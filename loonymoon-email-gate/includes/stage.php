<?php
/**
 * Fanloop stage — where this artist sits on a seven-step ladder, computed from
 * the five rings (listeners → followers → list → customers → members) plus
 * release count, recent sends and the 28-day streams direction. Each stage is a
 * set of gates with a real value, a target and the Fanloop tool that moves it.
 * The first failing gate of the next stage is the bottleneck: that's the one
 * thing the artist should work on this week.
 *
 * Stages are sequential — you're at the highest stage whose gates ALL pass and
 * every stage below it passes too. Unknown data (a ring that isn't connected)
 * fails its gate and shows "—", never a fake pass.
 *
 * Pure evaluator (lmeg_si_stage) + gatherer for the extra inputs
 * (lmeg_si_stage_extra) + two renderers: the Insights card and the brief block.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The ladder: number → name + one-line meaning. */
function lmeg_si_stage_ladder() {
    return [
        1 => ['name' => 'Releasing',         'blurb' => 'Your music is on Spotify and people can find it.'],
        2 => ['name' => 'Finding listeners', 'blurb' => 'Strangers are listening and some are following.'],
        3 => ['name' => 'Building a list',   'blurb' => 'Listeners are becoming fans you can actually reach.'],
        4 => ['name' => 'Keeping in touch',  'blurb' => 'A real share of listeners join, and you talk to them regularly.'],
        5 => ['name' => 'Selling',           'blurb' => 'Fans on your list buy from you.'],
        6 => ['name' => 'Recurring support', 'blurb' => 'Some fans pay every month.'],
        7 => ['name' => 'Compounding',       'blurb' => 'Members, list and streams all grow together.'],
    ];
}

/**
 * Evaluate the ladder. $n = the raw ring counts (listeners, sp_followers,
 * ig_followers, list, superfans, customers, members — null = unknown);
 * $x = ['releases' => int|null, 'sends_30d' => int|null, 'streams_pct' => float|null].
 * Returns stage, name, score (0–100), stages[1..7] each with gates, next stage,
 * bottleneck gate, failing gates, ratios. Pure.
 */
function lmeg_si_stage($n, $x = []) {
    $L = lmeg_si_stage_ladder();
    $v = function ($k) use ($n) { return (isset($n[$k]) && $n[$k] !== null && $n[$k] !== '') ? max(0, (int) $n[$k]) : null; };
    $listeners = $v('listeners'); $sp = $v('sp_followers'); $list = $v('list'); $customers = $v('customers'); $members = $v('members');
    $releases = (isset($x['releases']) && $x['releases'] !== null) ? (int) $x['releases'] : null;
    $sends30  = (isset($x['sends_30d']) && $x['sends_30d'] !== null) ? (int) $x['sends_30d'] : null;
    $spct     = (isset($x['streams_pct']) && $x['streams_pct'] !== null && $x['streams_pct'] !== '') ? (float) $x['streams_pct'] : null;
    $pct = function ($a, $b) { return ($a !== null && $b !== null && $b > 0) ? $a / $b * 100 : null; };
    $list_pct = $pct($list, $listeners); $cust_pct = $pct($customers, $list); $fol_pct = $pct($sp, $listeners);
    $nf = function ($k) { return $k === null ? '—' : (function_exists('number_format_i18n') ? number_format_i18n((int) $k) : number_format((int) $k)); };
    $pf = function ($k) { return $k === null ? '—' : rtrim(rtrim(number_format((float) $k, $k < 1 ? 2 : 1), '0'), '.') . '%'; };
    $sf = function ($k) { return $k === null ? '—' : (($k > 0 ? '+' : ($k < 0 ? '−' : '')) . rtrim(rtrim(number_format(abs((float) $k), 1), '0'), '.') . '%'); };
    $go = function ($page, $label) { return ['label' => $label, 'page' => $page, 'args' => []]; };
    $compose = function ($angle, $label, $group = null) { $a = ['prefill' => 'insight', 'angle' => $angle]; if ($group) $a['group'] = $group; return ['label' => $label, 'page' => 'lmeg-compose', 'args' => $a]; };
    $gate = function ($key, $label, $pass, $value, $target, $ring, $actions) {
        return ['key' => $key, 'label' => $label, 'pass' => (bool) $pass, 'value' => $value, 'target' => $target, 'ring' => $ring, 'actions' => $actions];
    };
    $S = [
        1 => [
            $gate('releases', 'Music on Spotify', $releases !== null && $releases >= 1, $nf($releases) . ' release' . ($releases === 1 ? '' : 's'), 'at least 1', 'listeners', [$go('lmeg-releases', 'Add a release')]),
        ],
        2 => [
            $gate('listeners', 'Monthly listeners', $listeners !== null && $listeners >= 1000, $nf($listeners), '1,000+', 'listeners', [$go('lmeg-presaves', 'Set up a pre-save'), $compose('listen', 'Send your list to Spotify')]),
            $gate('followers', 'Spotify followers', $sp !== null && $sp >= 100, $nf($sp), '100+', 'followers', [$compose('follow', 'Ask your list to follow')]),
        ],
        3 => [
            $gate('list', 'Fans on your list', $list !== null && $list >= 100, $nf($list), '100+', 'list', [$go('lmeg-drops', 'Run a drop'), $go('lmeg-contests', 'Run a contest'), $go('lmeg-releases', 'Put a signup on a release page')]),
        ],
        4 => [
            $gate('list_share', 'Listeners who join your list', $list_pct !== null && ($list_pct >= 1 || $list >= 1000), $pf($list_pct) . ' of monthly listeners', '1% (or 1,000 fans)', 'list', [$go('lmeg-presaves', 'Set up a pre-save'), $go('lmeg-drops', 'Run a drop'), $go('lmeg-contests', 'Run a contest')]),
            $gate('sends', 'Sent to your list in the last 30 days', $sends30 !== null && $sends30 >= 1, $nf($sends30) . ' send' . ($sends30 === 1 ? '' : 's'), '1 or more', 'list', [$go('lmeg-compose', 'Send to your list')]),
        ],
        5 => [
            $gate('customers', 'Fans who have bought', $customers !== null && $customers >= 10, $nf($customers), '10+', 'customers', [$go('lmeg-products', 'Add something to sell'), $compose('lift', 'Tell your superfans', 'superfans')]),
            $gate('cust_share', 'Your list who buy', $cust_pct !== null && $cust_pct >= 2, $pf($cust_pct) . ' of your list', '2%', 'customers', [$go('lmeg-store-promos', 'Run a promotion'), $compose('lift', 'Tell your superfans', 'superfans')]),
        ],
        6 => [
            $gate('members', 'Paying members', $members !== null && $members >= 10, $nf($members), '10+', 'members', [$go('lmeg-tiers', 'Set up a tier'), $compose('lift', 'Invite your superfans', 'superfans')]),
        ],
        7 => [
            $gate('members_100', 'Paying members', $members !== null && $members >= 100, $nf($members), '100+', 'members', [$go('lmeg-tiers', 'Grow your tiers'), $compose('lift', 'Invite your superfans', 'superfans')]),
            $gate('list_share_5', 'Listeners who join your list', $list_pct !== null && $list_pct >= 5, $pf($list_pct), '5%', 'list', [$go('lmeg-presaves', 'Set up a pre-save'), $go('lmeg-drops', 'Run a drop')]),
            $gate('streams_hold', '28-day streams holding or growing', $spct !== null && $spct >= 0, $sf($spct) . ' vs the 28 days before', '0% or better', 'listeners', [$go('lmeg-releases', 'Plan a release')]),
        ],
    ];
    $stages = [];
    foreach ($S as $i => $gates) {
        $ok = true; foreach ($gates as $g) { if (!$g['pass']) { $ok = false; break; } }
        $stages[$i] = ['n' => $i, 'name' => $L[$i]['name'], 'blurb' => $L[$i]['blurb'], 'gates' => $gates, 'passed' => $ok];
    }
    $stage = 0;
    for ($i = 1; $i <= 7; $i++) { if ($stages[$i]['passed']) $stage = $i; else break; }
    $next = $stage < 7 ? $stages[$stage + 1] : null;
    $failing = $next ? array_values(array_filter($next['gates'], function ($g) { return !$g['pass']; })) : [];
    $bottleneck = $failing ? $failing[0] : null;
    $frac = $next ? (count($next['gates']) - count($failing)) / max(1, count($next['gates'])) : 0;
    $score = (int) round(($stage + $frac) / 7 * 100);
    return [
        'stage' => $stage, 'name' => $stage ? $L[$stage]['name'] : 'Getting started', 'blurb' => $stage ? $L[$stage]['blurb'] : 'Connect Spotify and add a release to start the ladder.',
        'score' => max(0, min(100, $score)), 'stages' => $stages, 'next' => $next, 'bottleneck' => $bottleneck, 'failing' => $failing,
        'ratios' => ['list_pct' => $list_pct, 'cust_pct' => $cust_pct, 'fol_pct' => $fol_pct],
    ];
}

/**
 * The extra inputs the ladder needs beyond the ring counts: release count
 * (public API list, else "has songs" from the snapshot), sends in the last 30
 * days (completed broadcasts; demo → the sample marks), 28-day streams change.
 */
function lmeg_si_stage_extra($snap, $ov, $changes = [], $demo = false) {
    $releases = null;
    if (is_array($ov) && !empty($ov['releases'])) $releases = count((array) $ov['releases']);
    elseif ($snap && !empty($snap->top_songs)) { $songs = json_decode((string) $snap->top_songs, true); if (is_array($songs) && $songs) $releases = 1; }
    $sends = null;
    if ($demo) {
        $sends = 0; $cut = strtotime(current_time('Y-m-d')) - 30 * 86400;
        if (function_exists('lmeg_si_demo_marks')) foreach (lmeg_si_demo_marks() as $m) { if (strtotime($m['d']) >= $cut) $sends += (int) $m['n']; }
    } elseif (function_exists('lmeg_si_campaign_marks')) {
        $sends = 0; foreach ((array) lmeg_si_campaign_marks(30) as $m) $sends += (int) ($m['n'] ?? 0);
    }
    $sp = (isset($changes['streams']) && $changes['streams'] !== null && $changes['streams'] !== '') ? (float) $changes['streams'] : null;
    return ['releases' => $releases, 'sends_30d' => $sends, 'streams_pct' => $sp];
}

/** Resolve a gate action to an href (admin page or external). */
function lmeg_si_stage_action_href($a) {
    if (!empty($a['href'])) return $a['href'];
    return admin_url('admin.php?' . http_build_query(array_merge(['page' => $a['page']], (array) ($a['args'] ?? []))));
}

/** The Insights card: stage + score, the seven-step ladder, the bottleneck with its gates and actions. */
function lmeg_si_render_stage($st, $card, $lbl) {
    if (!$st) return '';
    $stage = (int) $st['stage']; $next = $st['next']; $b = $st['bottleneck'];
    ob_start(); ?>
        <div style="<?php echo $card; ?>margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;flex-wrap:wrap;margin-bottom:12px;">
                <div style="<?php echo $lbl; ?>">Your stage · Fanloop ladder</div>
                <div style="font-size:11px;color:#8B90A0;">Seven steps from first release to a fan base that pays every month — each one gated on your real numbers.</div>
            </div>
            <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:stretch;">
                <div style="flex:0 0 250px;min-width:220px;background:linear-gradient(120deg,rgba(208,95,162,.18),rgba(124,108,246,.18)),linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.14);border-radius:12px;padding:14px 16px;">
                    <div style="font:700 11px/1 var(--lmegA-font,inherit);letter-spacing:.06em;text-transform:uppercase;color:#C9CCD6;">Stage <?php echo $stage; ?> of 7</div>
                    <div style="font:800 24px/1.1 var(--lmegA-font,inherit);color:#F4F5F7;margin:6px 0 4px;"><?php echo esc_html($st['name']); ?></div>
                    <div style="font-size:12px;color:#C9CCD6;line-height:1.45;"><?php echo esc_html($st['blurb']); ?></div>
                    <div style="margin-top:12px;display:flex;justify-content:space-between;font-size:11px;color:#C9CCD6;"><span>Ladder progress</span><span style="color:#F4F5F7;font-weight:700;"><?php echo (int) $st['score']; ?>/100</span></div>
                    <div style="height:6px;border-radius:4px;background:rgba(255,255,255,.08);margin-top:5px;overflow:hidden;"><div style="height:100%;width:<?php echo (int) $st['score']; ?>%;background:linear-gradient(90deg,#D05FA2,#7C6CF6);border-radius:4px;"></div></div>
                </div>
                <div style="flex:1 1 380px;min-width:300px;display:flex;flex-direction:column;gap:12px;">
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <?php foreach ($st['stages'] as $s) : $cur = $s['n'] === $stage + 1; $done = $s['n'] <= $stage;
                            $style = $done ? 'background:rgba(52,211,153,.16);border:1px solid rgba(52,211,153,.45);color:#F4F5F7;'
                                   : ($cur ? 'background:linear-gradient(135deg,#D05FA2,#7C6CF6);border:1px solid rgba(255,255,255,.28);color:#fff;box-shadow:0 6px 18px rgba(124,108,246,.35);'
                                   : 'background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12);color:#8B90A0;'); ?>
                        <div style="<?php echo $style; ?>border-radius:999px;padding:5px 11px;font-size:11px;font-weight:700;white-space:nowrap;" title="<?php echo esc_attr($s['blurb']); ?>"><?php echo $done ? '✓ ' : ($cur ? '→ ' : ''); ?><?php echo (int) $s['n']; ?> · <?php echo esc_html($s['name']); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($next && $b) : ?>
                    <div style="background:#0E0F16;border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:12px 14px;">
                        <div style="font:700 11px/1 var(--lmegA-font,inherit);letter-spacing:.06em;text-transform:uppercase;color:#E58BBD;margin-bottom:6px;">What’s holding you at stage <?php echo $stage; ?></div>
                        <div style="font-size:14px;font-weight:700;color:#F4F5F7;"><?php echo esc_html($b['label']); ?>: <span style="color:#F87171;"><?php echo esc_html($b['value']); ?></span> <span style="color:#8B90A0;font-weight:500;">· needs <?php echo esc_html($b['target']); ?></span></div>
                        <div style="font-size:12px;color:#C9CCD6;margin-top:4px;">To reach stage <?php echo (int) $next['n']; ?> · <?php echo esc_html($next['name']); ?>:</div>
                        <div style="display:flex;flex-direction:column;gap:4px;margin-top:6px;">
                            <?php foreach ($next['gates'] as $g) : ?>
                            <div style="font-size:12px;color:<?php echo $g['pass'] ? '#C9CCD6' : '#F4F5F7'; ?>;"><span style="display:inline-block;width:16px;color:<?php echo $g['pass'] ? '#34D399' : '#F87171'; ?>;font-weight:800;"><?php echo $g['pass'] ? '✓' : '○'; ?></span><?php echo esc_html($g['label']); ?> <span style="color:#8B90A0;">— <?php echo esc_html($g['value']); ?>, needs <?php echo esc_html($g['target']); ?></span></div>
                            <?php endforeach; ?>
                        </div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:9px;">
                            <?php foreach ($b['actions'] as $a) : ?>
                            <a href="<?php echo esc_url(lmeg_si_stage_action_href($a)); ?>" style="font-size:11px;font-weight:700;color:#F4F5F7 !important;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.16);border-radius:999px;padding:4px 10px;text-decoration:none;line-height:1.2;"><?php echo esc_html($a['label']); ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php elseif ($stage >= 7) : ?>
                    <div style="background:#0E0F16;border:1px solid rgba(52,211,153,.35);border-radius:10px;padding:12px 14px;font-size:13px;color:#F4F5F7;">Top of the ladder — members, list and streams are all growing together. Keep the rhythm: a release, a send and a drop every cycle.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php return ob_get_clean();
}

/** Plain-text line for the brief's text alternative. */
function lmeg_si_stage_text($st) {
    if (!$st) return '';
    $t = 'STAGE ' . (int) $st['stage'] . ' OF 7 · ' . $st['name'] . ' (' . (int) $st['score'] . '/100)';
    if ($st['bottleneck']) $t .= "\nHolding you back: " . $st['bottleneck']['label'] . ' — ' . $st['bottleneck']['value'] . ', needs ' . $st['bottleneck']['target'];
    return $t;
}
