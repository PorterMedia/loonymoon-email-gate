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
    // Send rhythm (optional inputs): when was the last send, how many in 90
    // days, the average gap between send days. $x['today'] pins the clock for tests.
    $sends90   = (isset($x['sends_90d']) && $x['sends_90d'] !== null) ? (int) $x['sends_90d'] : null;
    $last_send = !empty($x['last_send']) ? (string) $x['last_send'] : null;
    $send_gap  = (isset($x['send_gap']) && $x['send_gap'] !== null) ? (int) $x['send_gap'] : null;
    $today_s   = !empty($x['today']) ? (string) $x['today'] : (function_exists('current_time') ? current_time('Y-m-d') : date('Y-m-d'));
    $since     = $last_send ? max(0, (int) floor((strtotime($today_s) - strtotime($last_send)) / 86400)) : null;
    // A cadence is only quoted while it is current (the last send is within
    // ~1.5 gaps); a July burst followed by silence reads as a count, not a rhythm.
    $current = ($send_gap && $since !== null && $since <= max(14, (int) round($send_gap * 1.5)));
    $rhythm = ['last_send' => $last_send, 'days_since' => $since, 'sends_90d' => $sends90, 'gap' => $send_gap, 'current' => $current,
               'label' => $sends90 === null ? '' : ($sends90 === 0 ? 'No sends in the last 90 days'
                        : ($current ? 'About one send every ' . $send_gap . ' days over the last 90 days'
                        : ($sends90 === 1 ? 'One send in the last 90 days' : number_format($sends90) . ' sends in the last 90 days')))];
    $superfans = (isset($n['superfans']) && $n['superfans'] !== null && $n['superfans'] !== '') ? max(0, (int) $n['superfans']) : null;
    $pct = function ($a, $b) { return ($a !== null && $b !== null && $b > 0) ? $a / $b * 100 : null; };
    $list_pct = $pct($list, $listeners); $cust_pct = $pct($customers, $list); $fol_pct = $pct($sp, $listeners);
    $nf = function ($k) { return $k === null ? '—' : (function_exists('number_format_i18n') ? number_format_i18n((int) $k) : number_format((int) $k)); };
    $pf = function ($k) { return $k === null ? '—' : rtrim(rtrim(number_format((float) $k, $k < 1 ? 2 : 1), '0'), '.') . '%'; };
    $sf = function ($k) { return $k === null ? '—' : (($k > 0 ? '+' : ($k < 0 ? '−' : '')) . rtrim(rtrim(number_format(abs((float) $k), 1), '0'), '.') . '%'); };
    $go = function ($page, $label) { return ['label' => $label, 'page' => $page, 'args' => []]; };
    $compose = function ($angle, $label, $group = null) { $a = ['prefill' => 'insight', 'angle' => $angle]; if ($group) $a['group'] = $group; return ['label' => $label, 'page' => 'lmeg-compose', 'args' => $a]; };
    // progress: how far along a numeric gate is (0–100), null when the gate is
    // pass/fail only (streams direction) or the input is unknown.
    $pr = function ($a, $goal) { return ($a === null || $goal <= 0) ? null : max(0, min(100, (float) $a / $goal * 100)); };
    $gate = function ($key, $label, $pass, $value, $target, $ring, $actions, $progress = null) {
        $pass = (bool) $pass;
        return ['key' => $key, 'label' => $label, 'pass' => $pass, 'value' => $value, 'target' => $target, 'ring' => $ring, 'actions' => $actions,
                'progress' => $pass ? 100 : ($progress === null ? null : (int) round($progress))];
    };
    $S = [
        1 => [
            $gate('releases', 'Music on Spotify', $releases !== null && $releases >= 1, $nf($releases) . ' release' . ($releases === 1 ? '' : 's'), 'at least 1', 'listeners', [$go('lmeg-releases', 'Add a release')], $pr($releases, 1)),
        ],
        2 => [
            $gate('listeners', 'Monthly listeners', $listeners !== null && $listeners >= 1000, $nf($listeners), '1,000+', 'listeners', [$go('lmeg-presaves', 'Set up a pre-save'), $compose('listen', 'Send your list to Spotify')], $pr($listeners, 1000)),
            $gate('followers', 'Spotify followers', $sp !== null && $sp >= 100, $nf($sp), '100+', 'followers', [$compose('follow', 'Ask your list to follow')], $pr($sp, 100)),
        ],
        3 => [
            $gate('list', 'Fans on your list', $list !== null && $list >= 100, $nf($list), '100+', 'list', [$go('lmeg-drops', 'Run a drop'), $go('lmeg-contests', 'Run a contest'), $go('lmeg-releases', 'Put a signup on a release page')], $pr($list, 100)),
        ],
        4 => [
            $gate('list_share', 'Listeners who join your list', $list_pct !== null && ($list_pct >= 1 || $list >= 1000), $pf($list_pct) . ' of monthly listeners', '1% (or 1,000 fans)', 'list', [$go('lmeg-presaves', 'Set up a pre-save'), $go('lmeg-drops', 'Run a drop'), $go('lmeg-contests', 'Run a contest')], max((float) $pr($list_pct, 1), (float) $pr($list, 1000))),
            $gate('sends', 'Sent to your list in the last 30 days', $sends30 !== null && $sends30 >= 1,
                  $nf($sends30) . ' send' . ($sends30 === 1 ? '' : 's') . (($sends30 === 0 && $since !== null) ? ' · last one ' . ($since === 0 ? 'today' : ($since === 1 ? 'yesterday' : $since . ' days ago')) : ''),
                  '1 or more', 'list', [$go('lmeg-compose', 'Send to your list')], $pr($sends30, 1)),
        ],
        5 => [
            $gate('customers', 'Fans who have bought', $customers !== null && $customers >= 10, $nf($customers), '10+', 'customers', [$go('lmeg-products', 'Add something to sell'), $compose('lift', 'Tell your superfans', 'superfans')], $pr($customers, 10)),
            $gate('cust_share', 'Your list who buy', $cust_pct !== null && $cust_pct >= 2, $pf($cust_pct) . ' of your list', '2%', 'customers', [$go('lmeg-store-promos', 'Run a promotion'), $compose('lift', 'Tell your superfans', 'superfans')], $pr($cust_pct, 2)),
        ],
        6 => [
            $gate('members', 'Paying members', $members !== null && $members >= 10, $nf($members), '10+', 'members', [$go('lmeg-tiers', 'Set up a tier'), $compose('lift', 'Invite your superfans', 'superfans')], $pr($members, 10)),
        ],
        7 => [
            $gate('members_100', 'Paying members', $members !== null && $members >= 100, $nf($members), '100+', 'members', [$go('lmeg-tiers', 'Grow your tiers'), $compose('lift', 'Invite your superfans', 'superfans')], $pr($members, 100)),
            $gate('list_share_5', 'Listeners who join your list', $list_pct !== null && $list_pct >= 5, $pf($list_pct), '5%', 'list', [$go('lmeg-presaves', 'Set up a pre-save'), $go('lmeg-drops', 'Run a drop')], $pr($list_pct, 5)),
            $gate('streams_hold', '28-day streams holding or growing', $spct !== null && $spct >= 0, $sf($spct) . ' vs the 28 days before', '0% or better', 'listeners', [$go('lmeg-releases', 'Plan a release')]),
        ],
    ];
    // Distance + pace: for every gate that isn't passing, how many are still
    // needed (using whichever alternative target is closer), what the last 28
    // days added, and the ETA at that pace — honest, never a promise.
    $sd = function ($k) use ($n) { return (isset($n[$k]) && $n[$k] !== null && $n[$k] !== '') ? (int) $n[$k] : null; }; // signed delta
    $list_new = $v('list_new'); $cust_new = $v('customers_new');
    // The list pace uses organic signups when the import split is known.
    $list_org = $v('list_new_organic'); $imported = $v('list_imported_28d');
    $list_rate = $list_org !== null ? $list_org : $list_new;
    $spd = $sd('sp_followers_delta'); $mem_d = $sd('members_delta'); $lis_d = $sd('listeners_delta');
    $approx = function ($k) use ($n) { return !empty($n[$k . '_src']) && $n[$k . '_src'] === 'log'; };   // rate derived from the history log
    $pool = ($superfans !== null && $superfans > 0) ? number_format($superfans) . ' superfan' . ($superfans === 1 ? '' : 's') . ' to ask first' : null;
    $dist = function ($key) use ($listeners, $sp, $list, $customers, $members, $releases, $sends30, $list_rate, $cust_new, $spd, $mem_d, $lis_d, $pool, $rhythm) {
        $c = function ($x) { return $x === null ? null : (int) ceil($x); };
        $list_new = $list_rate;
        switch ($key) {
            case 'releases':     return [$releases === null ? null : max(0, 1 - $releases), 'release', null, null];
            case 'listeners':    return [$listeners === null ? null : max(0, 1000 - $listeners), 'listener', $lis_d, null];
            case 'followers':    return [$sp === null ? null : max(0, 100 - $sp), 'follower', $spd, null];
            case 'list':         return [$list === null ? null : max(0, 100 - $list), 'fan', $list_new, null];
            case 'list_share':
                if ($list === null) return [null, 'fan', $list_new, null];
                $a = max(0, 1000 - $list); $b = $listeners === null ? null : max(0, $c($listeners * 0.01) - $list);
                $need = ($b === null || $a <= $b) ? $a : $b;
                $alt = ($b === null) ? null : (($a <= $b) ? '1,000 fans is closer than 1% of your listeners (' . number_format($c($listeners * 0.01)) . ')' : '1% of your listeners (' . number_format($c($listeners * 0.01)) . ') is closer than 1,000');
                return [$need, 'fan', $list_new, $alt];
            case 'sends':        return [$sends30 === null ? null : max(0, 1 - $sends30), 'send', null, $rhythm['label'] !== '' ? $rhythm['label'] : null];
            case 'customers':    return [$customers === null ? null : max(0, 10 - $customers), 'buyer', $cust_new, $pool];
            case 'cust_share':   return [($customers === null || $list === null) ? null : max(0, $c($list * 0.02) - $customers), 'buyer', $cust_new, $pool];
            case 'members':      return [$members === null ? null : max(0, 10 - $members), 'member', $mem_d, $pool];
            case 'members_100':  return [$members === null ? null : max(0, 100 - $members), 'member', $mem_d, $pool];
            case 'list_share_5': return [($list === null || $listeners === null) ? null : max(0, $c($listeners * 0.05) - $list), 'fan', $list_new, null];
        }
        return [null, '', null, null];
    };
    // Where signups came from (last 28 days): evidence line for the list gates,
    // and their action pills re-ranked so the tool that brought the most
    // organic signups comes first. Imports are shown but never rank a tool.
    $sources = (isset($n['signup_sources']) && is_array($n['signup_sources'])) ? $n['signup_sources'] : null;
    $evidence = ''; $rank = [];
    if ($sources) {
        $meta = lmeg_si_stage_source_meta(); $bits = [];
        $fams = array_filter($sources, function ($v, $k) { return $k !== 'total' && (int) $v > 0; }, ARRAY_FILTER_USE_BOTH);
        arsort($fams);
        foreach ($fams as $k => $v) { if (isset($meta[$k])) { $bits[] = $meta[$k][0] . ' ' . number_format((int) $v); if ($meta[$k][1] && $k !== 'imports') $rank[$meta[$k][1]] = (int) $v; } }
        $evidence = $bits ? implode(' · ', $bits) : '';
    }
    foreach ($S as $i => $gates) {
        foreach ($gates as $j => $g) {
            $S[$i][$j] += ['need_n' => null, 'need_label' => '', 'rate_n' => null, 'rate_label' => '', 'eta_days' => null, 'eta_label' => '', 'alt' => null, 'evidence' => ''];
            if (in_array($g['key'], ['list', 'list_share', 'list_share_5'], true) && $evidence !== '') {
                $S[$i][$j]['evidence'] = $evidence;
                if ($rank) { $acts = $S[$i][$j]['actions']; usort($acts, function ($a, $b) use ($rank) { return ($rank[$b['page'] ?? ''] ?? 0) <=> ($rank[$a['page'] ?? ''] ?? 0); }); $S[$i][$j]['actions'] = $acts; }
            }
            if ($g['pass']) continue;
            list($need, $unit, $rate, $alt) = $dist($g['key']);
            if ($need === null || $need <= 0) continue;
            $plural = function ($k, $u) { return number_format($k) . ' ' . $u . ($k === 1 ? '' : 's'); };
            $S[$i][$j]['need_n'] = $need; $S[$i][$j]['need_label'] = '+' . $plural($need, $unit) . ' to go'; $S[$i][$j]['alt'] = $alt;
            if ($rate !== null) {
                $src = ['listeners' => 'listeners_delta', 'followers' => 'sp_followers_delta', 'list' => 'list_new', 'list_share' => 'list_new', 'list_share_5' => 'list_new',
                        'customers' => 'customers_new', 'cust_share' => 'customers_new', 'members' => 'members_delta', 'members_100' => 'members_delta'][$g['key']] ?? '';
                $S[$i][$j]['rate_n'] = $rate;
                $S[$i][$j]['rate_label'] = ($rate > 0 ? '+' : ($rate < 0 ? '−' : '')) . number_format(abs($rate)) . (($src && $approx($src)) ? ' per 28 days at the recent pace' : ' in the last 28 days')
                                         . (($src === 'list_new' && $list_org !== null && $imported > 0) ? ' (' . number_format($imported) . ' imported, not counted)' : '');
                if ($rate > 0) { $days = (int) ceil($need / ($rate / 28)); $S[$i][$j]['eta_days'] = $days; $S[$i][$j]['eta_label'] = lmeg_si_stage_eta_label($days) . ' at that pace'; }
                else $S[$i][$j]['eta_label'] = 'no growth at the current pace';
            }
        }
    }
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
        'rhythm' => $rhythm, 'signup_sources' => $sources, 'signup_evidence' => $evidence,
        // the raw inputs, for the Stage page's "how it's measured" table
        'inputs' => ['listeners' => $listeners, 'sp_followers' => $sp, 'list' => $list, 'superfans' => $superfans, 'customers' => $customers, 'members' => $members,
                     'releases' => $releases, 'sends_30d' => $sends30, 'streams_pct' => $spct,
                     'sp_followers_delta' => $spd, 'list_new' => $list_new, 'customers_new' => $cust_new,
                     'list_new_organic' => $list_org, 'list_imported_28d' => $imported],
    ];
}

/**
 * The hops between rings — each one's conversion right now, the ladder gate
 * that sits on it (if any) and what the destination ring added in the last 28
 * days. Pure; built from lmeg_si_stage()'s inputs. Rows: from, to, pct,
 * pct_label, gate (target %, stage, pass) or null, delta_label, bar (0–100).
 */
function lmeg_si_stage_hops($st) {
    $in = $st['inputs'] ?? [];
    $g = function ($k) use ($in) { return (isset($in[$k]) && $in[$k] !== null) ? (int) $in[$k] : null; };
    $pct = function ($a, $b) { return ($a !== null && $b !== null && $b > 0) ? $a / $b * 100 : null; };
    $pf  = function ($k) { return $k === null ? '—' : rtrim(rtrim(number_format((float) $k, $k < 1 ? 2 : 1), '0'), '.') . '%'; };
    $dl  = function ($d, $unit) { return $d === null ? '' : (($d > 0 ? '+' : ($d < 0 ? '−' : '')) . number_format(abs((int) $d)) . ' ' . $unit . (abs((int) $d) === 1 ? '' : 's') . ' in 28 days'); };
    $listeners = $g('listeners'); $sp = $g('sp_followers'); $list = $g('list'); $cust = $g('customers'); $mem = $g('members');
    $stage = (int) ($st['stage'] ?? 0);
    $rows = [];
    // 1. listeners → Spotify followers (no gate — a health read)
    $p = $pct($sp, $listeners);
    $rows[] = ['key' => 'followers', 'from' => 'Monthly listeners', 'to' => 'Spotify followers', 'pct' => $p, 'pct_label' => $pf($p), 'gate' => null,
               'delta_label' => $dl($g('sp_followers_delta'), 'follower'), 'bar' => $p === null ? null : (int) round(min(100, $p / 40 * 100)), 'note' => 'No gate on this hop; one in three is a strong catalogue.'];
    // 2. listeners → your list (stage 4 wants 1%, stage 7 wants 5%)
    $p = $pct($list, $listeners); $target = $stage >= 4 ? 5 : 1; $need_stage = $stage >= 4 ? 7 : 4;
    $org = $g('list_new_organic'); $imp = $g('list_imported_28d');
    $rows[] = ['key' => 'list', 'from' => 'Monthly listeners', 'to' => 'Your list', 'pct' => $p, 'pct_label' => $pf($p),
               'gate' => ['target' => $target, 'stage' => $need_stage, 'pass' => $p !== null && $p >= $target, 'label' => $target . '% for stage ' . $need_stage],
               'delta_label' => $dl($org !== null ? $org : $g('list_new'), 'fan'), 'bar' => $p === null ? null : (int) round(min(100, $p / $target * 100)),
               'note' => ($org !== null && $imp > 0) ? number_format($imp) . ' imported, not counted' : ''];
    // 3. your list → buyers (stage 5 wants 2%)
    $p = $pct($cust, $list);
    $rows[] = ['key' => 'buyers', 'from' => 'Your list', 'to' => 'Fans who have bought', 'pct' => $p, 'pct_label' => $pf($p),
               'gate' => ['target' => 2, 'stage' => 5, 'pass' => $p !== null && $p >= 2, 'label' => '2% for stage 5'],
               'delta_label' => $dl($g('customers_new'), 'buyer'), 'bar' => $p === null ? null : (int) round(min(100, $p / 2 * 100)), 'note' => ''];
    // 4. buyers → members (stage 6 wants 10 members; the hop itself has no % gate)
    $p = $pct($mem, $cust);
    $rows[] = ['key' => 'members', 'from' => 'Fans who have bought', 'to' => 'Paying members', 'pct' => $p, 'pct_label' => $pf($p), 'gate' => null,
               'delta_label' => '', 'bar' => $p === null ? null : (int) round(min(100, $p / 10 * 100)), 'note' => 'No % gate; stage 6 wants 10 members, stage 7 wants 100. One in ten buyers is a healthy read.'];
    return $rows;
}

/** The "Between the rings" card: one row per hop with ratio, gate line and 28-day movement. */
function lmeg_si_render_stage_hops($st, $card, $lbl) {
    $rows = lmeg_si_stage_hops($st);
    ob_start(); ?>
        <div style="<?php echo $card; ?>max-width:1040px;margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;flex-wrap:wrap;margin-bottom:6px;">
                <div style="<?php echo $lbl; ?>">Between the rings · conversion</div>
                <div style="font-size:11px;color:#8B90A0;">How many people make each hop, the gate that sits on it, and what the next ring gained in the last 28 days.</div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
                <?php foreach ($rows as $r) : $gt = $r['gate']; $ok = $gt ? $gt['pass'] : null;
                    $fill = $ok === true ? '#34D399' : ($ok === false ? 'linear-gradient(90deg,#D05FA2,#7C6CF6)' : '#7C6CF6'); ?>
                <div style="background:#0E0F16;border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:12px 14px;">
                    <div style="font-size:11px;color:#8B90A0;line-height:1.4;"><?php echo esc_html($r['from']); ?> <span style="color:#C9CCD6;">→</span> <span style="color:#F4F5F7;font-weight:600;"><?php echo esc_html($r['to']); ?></span></div>
                    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;margin-top:6px;">
                        <div style="font:800 22px/1 var(--lmegA-font,inherit);color:<?php echo $r['pct'] === null ? '#8B90A0' : '#F4F5F7'; ?>;"><?php echo esc_html($r['pct_label']); ?></div>
                        <?php if ($gt) : ?><div style="font-size:11px;font-weight:700;color:<?php echo $ok ? '#34D399' : '#E58BBD'; ?>;white-space:nowrap;"><?php echo $ok ? '✓ ' : '○ '; ?><?php echo esc_html($gt['label']); ?></div><?php endif; ?>
                    </div>
                    <?php if ($r['bar'] !== null) : ?>
                    <div style="height:5px;border-radius:3px;background:rgba(255,255,255,.08);margin-top:8px;overflow:hidden;"><div style="height:100%;width:<?php echo max(2, (int) $r['bar']); ?>%;border-radius:3px;background:<?php echo $fill; ?>;"></div></div>
                    <?php endif; ?>
                    <div style="font-size:11px;color:#C9CCD6;margin-top:7px;line-height:1.45;"><?php echo $r['delta_label'] !== '' ? esc_html($r['delta_label']) : ($r['pct'] === null ? 'Not connected yet' : 'No 28-day movement data'); ?><?php if ($r['note']) : ?><span style="color:#8B90A0;"> · <?php echo esc_html($r['note']); ?></span><?php endif; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php return ob_get_clean();
}

/** "about 3 weeks" / "about 5 months" / "about 17 years" — a rounded, human ETA. Pure. */
function lmeg_si_stage_eta_label($days) {
    $days = (int) $days;
    if ($days <= 1)   return 'about a day';
    if ($days < 14)   return 'about ' . $days . ' days';
    if ($days < 60)   return 'about ' . max(2, (int) round($days / 7)) . ' weeks';
    if ($days < 730)  return 'about ' . max(2, (int) round($days / 30.4)) . ' months';
    return 'about ' . max(2, (int) round($days / 365)) . ' years';
}

/** Latest reading vs the one before it: score/list-share/stage deltas + the two dates. null with <2 readings. */
function lmeg_si_stage_log_delta($log) {
    if (!is_array($log) || count($log) < 2) return null;
    $dates = array_keys($log); $n = count($dates);
    $a = $log[$dates[$n - 2]]; $b = $log[$dates[$n - 1]];
    $lp = (isset($a['list_pct'], $b['list_pct']) && $a['list_pct'] !== null && $b['list_pct'] !== null) ? round((float) $b['list_pct'] - (float) $a['list_pct'], 2) : null;
    return ['from' => $dates[$n - 2], 'to' => $dates[$n - 1], 'score' => (int) $b['score'] - (int) $a['score'], 'stage' => (int) $b['stage'] - (int) $a['stage'],
            'list_pct' => $lp, 'list_pct_from' => $a['list_pct'] ?? null, 'list_pct_to' => $b['list_pct'] ?? null,
            'gap_days' => max(1, (int) round((strtotime($dates[$n - 1]) - strtotime($dates[$n - 2])) / 86400))];
}

/** Per-stage playbook: why the stage matters + the Fanloop moves that get you through it. */
function lmeg_si_stage_playbook() {
    $go = function ($page, $label) { return ['label' => $label, 'page' => $page, 'args' => []]; };
    $compose = function ($angle, $label, $group = null) { $a = ['prefill' => 'insight', 'angle' => $angle]; if ($group) $a['group'] = $group; return ['label' => $label, 'page' => 'lmeg-compose', 'args' => $a]; };
    return [
        1 => ['why'   => 'Nothing else on the ladder works until there is music to point at. Fanloop reads your catalogue from Spotify, so connecting it is the first move.',
              'moves' => [$go('lmeg-releases', 'Add your releases'), $go('lmeg-spotify', 'Connect Spotify'), $go('lmeg-s4a', 'Import Spotify for Artists')]],
        2 => ['why'   => 'A thousand monthly listeners and a hundred followers is where Spotify starts recommending you on its own. Every release is a reason to ask.',
              'moves' => [$go('lmeg-presaves', 'Set up a pre-save'), $go('lmeg-smartlinks', 'Share one smartlink everywhere'), $compose('follow', 'Ask your list to follow')]],
        3 => ['why'   => 'Listeners live on Spotify; a list is people you can reach directly. The first hundred names usually come from one signup placed where the music already is.',
              'moves' => [$go('lmeg-releases', 'Put a signup on every release page'), $go('lmeg-drops', 'Run a drop'), $go('lmeg-contests', 'Run a contest'), $go('lmeg-bio', 'Set up your Smart Bio')]],
        4 => ['why'   => 'One in a hundred listeners on your list, and a send every month, is where a message starts to move streams. Rhythm beats volume.',
              'moves' => [$go('lmeg-compose', 'Send to your list'), $go('lmeg-sequences', 'Set up a welcome sequence'), $go('lmeg-deliverability', 'Check deliverability')]],
        5 => ['why'   => 'Ten buyers proves people will pay; two in a hundred of your list buying is a solid rate for a fan store. Superfans go first.',
              'moves' => [$go('lmeg-products', 'Add something to sell'), $go('lmeg-store-promos', 'Run a promotion'), $compose('lift', 'Tell your superfans', 'superfans')]],
        6 => ['why'   => 'Monthly members are the steadiest income an independent artist has. Ten is the first cohort; invite the people who already buy.',
              'moves' => [$go('lmeg-tiers', 'Set up a tier'), $compose('lift', 'Invite your superfans', 'superfans'), $go('lmeg-collect', 'Collect content for members')]],
        7 => ['why'   => 'At the top, members, list and streams feed each other. The job is keeping the cycle turning: a release, a send and a drop every cycle.',
              'moves' => [$go('lmeg-releases', 'Plan a release'), $go('lmeg-drops', 'Run a drop'), $go('lmeg-tiers', 'Grow your tiers')]],
    ];
}

/** Sample ring counts used by every demo preview of the ladder (matches the Insights demo strip). */
function lmeg_si_stage_demo_raw($snap, $mlp = null) {
    return ['listeners' => $snap ? (int) $snap->monthly_listeners : 61400, 'listeners_pct' => $mlp, 'sp_followers' => 8240, 'sp_followers_delta' => 162, 'ig_followers' => 12480, 'ig_followers_delta' => 310,
            'list' => 2140, 'superfans' => 96, 'list_new' => 184, 'customers' => 312, 'customers_new' => 27, 'members' => 41,
            'signup_sources' => ['drops' => 62, 'contests' => 41, 'presaves' => 28, 'instagram' => 19, 'imports' => 0, 'store' => 9, 'site' => 25, 'total' => 184]];
}

/**
 * Imported fans in the last $days, detected by their signature: a CSV import
 * without a date column lands every row on one timestamp, so any minute with
 * ≥$min signups is treated as an import batch (organic signups never cluster
 * like that for a list this size). Returns the count, or null when the table
 * can't be read. Used to keep bulk imports out of the list pace.
 */
function lmeg_si_stage_list_bursts($days = 28, $min = 25) {
    global $wpdb;
    if (empty($wpdb) || !defined('LMEG_TABLE')) return null;
    $t = $wpdb->prefix . LMEG_TABLE;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H:%%i') m, COUNT(*) c FROM $t
          WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY) AND unsubscribed_at IS NULL
          GROUP BY m HAVING c >= %d", (int) $days, (int) $min), ARRAY_A);
    if ($wpdb->last_error) { $wpdb->last_error = ''; return null; }
    $n = 0; foreach ((array) $rows as $r) $n += (int) ($r['c'] ?? 0);
    return $n;
}

/**
 * Where the last $days of signups came from, by Fanloop tool: drops (drop:*
 * tags), contests (entries), pre-saves (saves), Instagram (instagram /
 * story-mention tags), imports (source:* tags, or the burst count when larger),
 * store (product:* tags) and "site" = new fans in none of those (the gate and
 * release pages). A fan can sit in several families; site is a true remainder.
 * null when the subscriber table can't be read; a missing tool table counts 0.
 */
function lmeg_si_stage_signup_sources($days = 28, $bursts = 0) {
    global $wpdb;
    if (empty($wpdb) || !defined('LMEG_TABLE')) return null;
    $p = $wpdb->prefix; $subs = $p . LMEG_TABLE; $tags = $p . 'lmeg_tags'; $st = $p . 'lmeg_subscriber_tags';
    $ce = $p . 'lmeg_contest_entries'; $ps = $p . 'lmeg_presaves'; $days = (int) $days;
    $q = function ($sql) use ($wpdb) { $v = $wpdb->get_var($sql); if ($wpdb->last_error) { $wpdb->last_error = ''; return null; } return $v === null ? null : (int) $v; };
    $win = "s.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY) AND s.unsubscribed_at IS NULL";
    $total = $q("SELECT COUNT(*) FROM $subs s WHERE $win");
    if ($total === null) return null;
    $fam = function ($where) use ($q, $subs, $tags, $st, $win) {
        return (int) $q("SELECT COUNT(DISTINCT s.id) FROM $subs s JOIN $st x ON x.subscriber_id = s.id JOIN $tags t ON t.id = x.tag_id WHERE $win AND ($where)");
    };
    $out = [
        'drops'     => $fam("t.slug LIKE 'drop:%'"),
        'contests'  => (int) $q("SELECT COUNT(DISTINCT s.id) FROM $subs s JOIN $ce e ON e.subscriber_id = s.id WHERE $win"),
        'presaves'  => (int) $q("SELECT COUNT(DISTINCT s.id) FROM $subs s JOIN $ps v ON v.subscriber_id = s.id WHERE $win"),
        'instagram' => $fam("t.slug IN ('instagram','story-mention')"),
        'imports'   => $fam("t.slug LIKE 'source:%'"),
        'store'     => $fam("t.slug LIKE 'product:%'"),
    ];
    $any = $q("SELECT COUNT(DISTINCT s.id) FROM $subs s WHERE $win AND (
        EXISTS (SELECT 1 FROM $st x JOIN $tags t ON t.id = x.tag_id WHERE x.subscriber_id = s.id AND (t.slug LIKE 'drop:%' OR t.slug LIKE 'source:%' OR t.slug LIKE 'product:%' OR t.slug IN ('instagram','story-mention')))
        OR EXISTS (SELECT 1 FROM $ce e WHERE e.subscriber_id = s.id) OR EXISTS (SELECT 1 FROM $ps v WHERE v.subscriber_id = s.id))");
    $site = $any === null ? max(0, $total - array_sum($out)) : max(0, $total - (int) $any);
    // Untagged imports (detected as a burst) are not "site" signups.
    $untagged_imports = max(0, (int) $bursts - $out['imports']);
    $out['imports'] = max($out['imports'], (int) $bursts);
    $out['site'] = max(0, $site - $untagged_imports);
    $out['total'] = $total;
    return $out;
}

/** Family key → label + the admin page whose action pill it ranks. */
function lmeg_si_stage_source_meta() {
    return ['drops' => ['Drops', 'lmeg-drops'], 'contests' => ['Contests', 'lmeg-contests'], 'presaves' => ['Pre-saves', 'lmeg-presaves'],
            'instagram' => ['Instagram', 'lmeg-instagram'], 'site' => ['Site & release pages', 'lmeg-releases'], 'store' => ['Store', 'lmeg-products'], 'imports' => ['Imports', '']];
}

/**
 * The ladder for this site right now — the light data path shared by the Stage
 * page and the daily log (no findings engine, no song maps): latest snapshot +
 * previous for the 28-day streams direction, the public overview for releases,
 * the five rings' raw counts. Works with no Spotify for Artists snapshot at all
 * (listeners show "—"; list, customers and members still evaluate). null only
 * when the S4A layer isn't loaded.
 */
function lmeg_si_stage_compute($demo = false) {
    if (!function_exists('lmeg_s4a_latest') || !function_exists('lmeg_si_fan_rings_data')) return null;
    $sel = lmeg_artist();
    if ($demo) {
        $dr = function_exists('lmeg_s4a_demo_rows') ? lmeg_s4a_demo_rows($sel) : [];
        if (!$dr) return null;
        $snap = (object) end($dr); $prev = (object) $dr[0];
        $ov = function_exists('lmeg_si_demo_overview') ? lmeg_si_demo_overview($sel) : null;
    } else {
        $snap = lmeg_s4a_latest($sel);
        $prev = ($snap && function_exists('lmeg_s4a_prev')) ? lmeg_s4a_prev($sel, $snap->window, $snap->captured_date) : null;
        $ov = function_exists('lmeg_spotify_overview') ? lmeg_spotify_overview() : null;
        if (function_exists('is_wp_error') && is_wp_error($ov)) $ov = null;
    }
    $pct = function ($a, $b) { return ($a !== null && $b !== null && (int) $b > 0) ? round(((int) $a - (int) $b) / (int) $b * 100, 1) : null; };
    $sp  = ($snap && $prev) ? $pct($snap->streams, $prev->streams) : null;
    $mlp = ($snap && $prev) ? $pct($snap->monthly_listeners, $prev->monthly_listeners) : null;
    $raw = null;
    if ($demo) { $raw = lmeg_si_stage_demo_raw($snap, $mlp); $rings = lmeg_si_fan_rings_shape($raw); }
    else {
        $rings = lmeg_si_fan_rings_data($snap, $ov, is_array($ov), ['monthly_listeners' => $mlp], $raw); if (!is_array($raw)) $raw = [];
        // Keep bulk imports out of the list pace: organic = new − import bursts.
        if (isset($raw['list_new']) && $raw['list_new'] !== null) {
            $imp = lmeg_si_stage_list_bursts(28);
            if ($imp !== null) { $raw['list_imported_28d'] = $imp; $raw['list_new_organic'] = max(0, (int) $raw['list_new'] - $imp); }
            $srcs = lmeg_si_stage_signup_sources(28, (int) $imp);
            if ($srcs !== null) $raw['signup_sources'] = $srcs;
        }
    }
    // Paces the rings can't give (members, listeners) — and any missing one —
    // come from the history log's own readings once they span a week.
    $hl = $demo ? (function_exists('lmeg_si_stage_demo_log') ? lmeg_si_stage_demo_log() : []) : lmeg_si_stage_log_get();
    foreach (['members_delta' => 'm', 'listeners_delta' => 'l', 'sp_followers_delta' => 'f', 'list_new' => 'ls', 'customers_new' => 'c'] as $rk => $lk) {
        if (!isset($raw[$rk]) || $raw[$rk] === null) { $r = lmeg_si_stage_log_rate($hl, $lk); if ($r !== null) { $raw[$rk] = $r; $raw[$rk . '_src'] = 'log'; } }
    }
    $extra = lmeg_si_stage_extra($snap, $ov, ['streams' => $sp], $demo);
    $stage = lmeg_si_stage($raw, $extra);
    return compact('sel', 'snap', 'prev', 'ov', 'sp', 'mlp', 'raw', 'rings', 'extra', 'stage', 'demo');
}

/* ---------------------------------------------------------------------------
 * Stage history — one reading a day (stage, score, bottleneck, list share),
 * written after the morning pull by the minute tick or by the first live view
 * of the Stage page that day. Option lmeg_stage_log: date => entry, ≤400 days.
 * ------------------------------------------------------------------------- */

function lmeg_si_stage_log_get() {
    $l = get_option('lmeg_stage_log', []);
    if (!is_array($l)) $l = [];
    ksort($l);
    return $l;
}

function lmeg_si_stage_log_entry($st, $captured = '') {
    $lp = $st['ratios']['list_pct'] ?? null; $in = $st['inputs'] ?? [];
    $iv = function ($k) use ($in) { return (isset($in[$k]) && $in[$k] !== null) ? (int) $in[$k] : null; };
    return ['stage' => (int) $st['stage'], 'score' => (int) $st['score'], 'gate' => (string) ($st['bottleneck']['key'] ?? ''),
            'list_pct' => $lp !== null ? round((float) $lp, 2) : null, 'captured' => (string) $captured,
            // the six gate inputs, so each one gets a trend line as the log grows
            'in' => ['l' => $iv('listeners'), 'f' => $iv('sp_followers'), 'ls' => $iv('list'), 'c' => $iv('customers'), 'm' => $iv('members'), 's' => $iv('sends_30d')]];
}

/** Write today's reading unless one exists (a pre-v3.237 reading without inputs is enriched in place). Returns true when written. */
function lmeg_si_stage_log_record($st, $date = null, $captured = '') {
    if (!$st) return false;
    $date = $date ?: current_time('Y-m-d');
    $log = lmeg_si_stage_log_get();
    if (isset($log[$date])) {
        if (isset($log[$date]['in'])) return false;
        $log[$date]['in'] = lmeg_si_stage_log_entry($st, $captured)['in'];
        update_option('lmeg_stage_log', $log, false);
        return true;
    }
    $log[$date] = lmeg_si_stage_log_entry($st, $captured);
    ksort($log);
    if (count($log) > 400) $log = array_slice($log, -400, null, true);
    update_option('lmeg_stage_log', $log, false);
    return true;
}

/** Is today's reading still to be written (and are we past the morning floor)? */
function lmeg_si_stage_log_due() {
    $now = current_time('timestamp');
    if ((int) date('G', $now) < (int) apply_filters('lmeg_brief_earliest_hour', 9)) return false;
    $log = get_option('lmeg_stage_log', []); $today = date('Y-m-d', $now);
    return !(is_array($log) && isset($log[$today]) && isset($log[$today]['in']));
}

/**
 * Per-input series from the log (oldest → newest, last $days readings that
 * carry inputs): key => [ints]. Only keys with ≥3 points. Keys: l listeners,
 * f Spotify followers, ls list, c customers, m members, s sends in 30 days.
 */
function lmeg_si_stage_trends($log, $days = 30) {
    $out = [];
    foreach (array_slice((array) $log, -$days, null, true) as $e) {
        if (empty($e['in']) || !is_array($e['in'])) continue;
        foreach ($e['in'] as $k => $v) { if ($v === null) continue; $out[$k][] = (int) $v; }
    }
    foreach ($out as $k => $vals) if (count($vals) < 3) unset($out[$k]);
    return $out;
}

/**
 * A 28-day rate for one input derived from the history log: (last − first) over
 * the span of readings that carry that input, scaled to 28 days. null until the
 * readings span at least $min_days. Fills the pace for gates with no native
 * 28-day delta (members, listeners) and backs up the others. Pure.
 */
function lmeg_si_stage_log_rate($log, $key, $min_days = 7) {
    $first = null; $last = null;
    foreach ((array) $log as $d => $e) {
        if (!isset($e['in'][$key]) || $e['in'][$key] === null) continue;
        $pt = [strtotime($d), (int) $e['in'][$key]];
        if ($first === null) $first = $pt;
        $last = $pt;
    }
    if (!$first || !$last) return null;
    $span = ($last[0] - $first[0]) / 86400;
    if ($span < $min_days) return null;
    return (int) round(($last[1] - $first[1]) / $span * 28);
}

/** Min–max scaled sparkline (a flat series sits mid-height); green up, red down, muted flat. '' with <3 points. Pure. */
function lmeg_si_stage_spark($vals, $w = 84, $h = 22) {
    $vals = array_values(array_map('intval', (array) $vals)); $n = count($vals);
    if ($n < 3) return '';
    $mn = min($vals); $mx = max($vals); $range = max(1, $mx - $mn);
    $p = 3; $iw = $w - 2 * $p; $ih = $h - 2 * $p;
    $f = function ($v) { return number_format((float) $v, 1, '.', ''); };
    $pts = [];
    foreach ($vals as $i => $v) $pts[] = $f($p + $i * $iw / ($n - 1)) . ',' . $f($mx === $mn ? $p + $ih / 2 : $p + (1 - ($v - $mn) / $range) * $ih);
    $d = end($vals) - $vals[0]; $color = $d > 0 ? '#34D399' : ($d < 0 ? '#F87171' : '#8B90A0');
    list($lx, $ly) = explode(',', end($pts));
    return '<svg class="lmeg-spark" viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" width="' . (int) $w . '" height="' . (int) $h . '" style="display:block;overflow:visible;" aria-hidden="true">'
         . '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $color . '" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/>'
         . '<circle cx="' . $lx . '" cy="' . $ly . '" r="2.2" fill="' . $color . '"/></svg>';
}

/**
 * Log today's reading if due. Waits for today's Spotify for Artists capture
 * until noon, then records with whatever is latest (sites without S4A log
 * after noon every day). $c = a computed context to reuse, else computed here.
 */
function lmeg_si_stage_log_maybe($c = null) {
    if (!lmeg_si_stage_log_due()) return false;
    if ($c === null) $c = lmeg_si_stage_compute(false);
    if (!$c || !empty($c['demo'])) return false;
    $now = current_time('timestamp'); $today = date('Y-m-d', $now);
    $cap = ($c['snap'] && !empty($c['snap']->captured_date)) ? (string) $c['snap']->captured_date : '';
    if ($cap !== $today && (int) date('G', $now) < 12) return false;
    return lmeg_si_stage_log_record($c['stage'], $today, $cap);
}

// Priority 70: ahead of the daily brief (71) so the brief's "since yesterday"
// line compares today's fresh reading with yesterday's.
add_action('lmeg_broadcast_tick', 'lmeg_si_stage_log_tick', 70);
function lmeg_si_stage_log_tick() { lmeg_si_stage_log_maybe(); }

/** The continuous run of days at $stage ending at the latest entry: since, days, whether it spans the whole log. */
function lmeg_si_stage_run($log, $stage) {
    if (!$log) return null;
    $dates = array_keys($log); $since = null;
    for ($i = count($dates) - 1; $i >= 0; $i--) { if ((int) $log[$dates[$i]]['stage'] !== (int) $stage) break; $since = $dates[$i]; }
    if ($since === null) return null;
    $last = end($dates);
    return ['since' => $since, 'last' => $last, 'days' => (int) floor((strtotime($last) - strtotime($since)) / 86400) + 1, 'first' => $dates[0], 'from_start' => $since === $dates[0]];
}

/** Stage changes in the log, oldest first: [date, from, to]. */
function lmeg_si_stage_changes($log) {
    $out = []; $prev = null;
    foreach ($log as $d => $e) { $s = (int) $e['stage']; if ($prev !== null && $s !== $prev) $out[] = ['date' => $d, 'from' => $prev, 'to' => $s]; $prev = $s; }
    return $out;
}

/** Sixty days of sample history for ?demo=1 — real step values the formula produces (stage 5 → 6, one more gate passing). */
function lmeg_si_stage_demo_log() {
    $log = []; $t = strtotime(current_time('Y-m-d'));
    for ($i = 59; $i >= 0; $i--) {
        $d = date('Y-m-d', $t - $i * 86400); $k = 59 - $i;
        if ($k < 22)      { $stage = 5; $score = 71; $gate = 'members'; }
        elseif ($k < 46)  { $stage = 6; $score = 86; $gate = 'members_100'; }
        else              { $stage = 6; $score = 90; $gate = 'members_100'; }
        $log[$d] = ['stage' => $stage, 'score' => $score, 'gate' => $gate, 'list_pct' => round(2.1 + $k * 0.024, 2), 'captured' => $d,
                    'in' => ['l' => 58000 + $k * 57 + (($k * 37) % 11) * 40, 'f' => 7900 + (int) round($k * 5.8), 'ls' => 1800 + (int) round($k * 5.8), 'c' => 260 + (int) round($k * 0.9), 'm' => 30 + (int) round($k * 0.19), 's' => $k < 22 ? 1 : 2]];
    }
    return $log;
}

/** Score-over-time chart (SVG) with a guide line per stage boundary and a dot at each stage change. */
function lmeg_si_stage_history_svg($log, $days = 90) {
    $log = array_slice($log, -$days, null, true);
    if (!$log) return '';
    $W = 640; $H = 130; $pl = 8; $pr = 30; $pt = 14; $pb = 20;
    $dates = array_keys($log); $n = count($dates);
    $t0 = strtotime($dates[0]); $t1 = strtotime($dates[$n - 1]); $span = max(1, $t1 - $t0);
    $x = function ($d) use ($n, $W, $pl, $pr, $t0, $span) { return $n === 1 ? ($W - $pr + $pl) / 2 : $pl + (strtotime($d) - $t0) / $span * ($W - $pl - $pr); };
    $y = function ($s) use ($H, $pt, $pb) { return $pt + (100 - max(0, min(100, (float) $s))) / 100 * ($H - $pt - $pb); };
    $r = function ($v) { return round($v, 1); };
    $g = '';
    for ($i = 1; $i <= 6; $i++) {
        $yy = $r($y($i / 7 * 100));
        $g .= '<line x1="' . $pl . '" x2="' . ($W - $pr) . '" y1="' . $yy . '" y2="' . $yy . '" stroke="rgba(255,255,255,.08)" stroke-width="1"/>'
            . '<text x="' . ($W - $pr + 5) . '" y="' . $r($yy + 3) . '" font-size="9" fill="#8B90A0">S' . $i . '</text>';
    }
    $pts = []; foreach ($log as $d => $e) $pts[] = $r($x($d)) . ',' . $r($y((int) $e['score']));
    $path = 'M' . implode(' L', $pts);
    $area = $path . ' L' . $r($x($dates[$n - 1])) . ',' . $r($y(0)) . ' L' . $r($x($dates[0])) . ',' . $r($y(0)) . ' Z';
    $last = end($log); $lx = $r($x($dates[$n - 1])); $ly = $r($y((int) $last['score']));
    $dots = '';
    foreach (lmeg_si_stage_changes($log) as $c) {
        $dots .= '<circle cx="' . $r($x($c['date'])) . '" cy="' . $r($y((int) $log[$c['date']]['score'])) . '" r="4" fill="' . ($c['to'] > $c['from'] ? '#34D399' : '#F87171') . '" stroke="#0E0F16" stroke-width="1.5"/>';
    }
    $fmt = function ($ts) { return function_exists('date_i18n') ? date_i18n('M j', $ts) : date('M j', $ts); };
    return '<svg viewBox="0 0 ' . $W . ' ' . $H . '" style="width:100%;height:auto;display:block;" role="img" aria-label="Ladder progress over time">'
         . '<defs><linearGradient id="lmegStageArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#7C6CF6" stop-opacity=".42"/><stop offset="1" stop-color="#7C6CF6" stop-opacity="0"/></linearGradient></defs>'
         . $g
         . ($n > 1 ? '<path d="' . $area . '" fill="url(#lmegStageArea)"/><path d="' . $path . '" fill="none" stroke="#D05FA2" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>' : '')
         . $dots
         . '<circle cx="' . $lx . '" cy="' . $ly . '" r="4.5" fill="#F4F5F7"/>'
         . '<text x="' . $r($lx - 7) . '" y="' . $r($ly - 8) . '" font-size="11" font-weight="700" fill="#F4F5F7" text-anchor="end">' . (int) $last['score'] . '</text>'
         . '<text x="' . $pl . '" y="' . ($H - 6) . '" font-size="9" fill="#8B90A0">' . esc_html($fmt($t0)) . '</text>'
         . ($n > 1 ? '<text x="' . ($W - $pr) . '" y="' . ($H - 6) . '" font-size="9" fill="#8B90A0" text-anchor="end">' . esc_html($fmt($t1)) . '</text>' : '')
         . '</svg>';
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
    // Send rhythm from one year of completed broadcasts: counts in the last 30
    // and 90 days, the most recent send day, and the average gap between send
    // days over the last 90 (null with fewer than two send days).
    $sends = null; $sends90 = null; $last = null; $gap = null; $marks = null;
    if ($demo) { $marks = function_exists('lmeg_si_demo_marks') ? lmeg_si_demo_marks() : []; }
    elseif (function_exists('lmeg_si_campaign_marks')) { $marks = (array) lmeg_si_campaign_marks(365); }
    if ($marks !== null) {
        $today = strtotime(current_time('Y-m-d')); $sends = 0; $sends90 = 0; $days90 = [];
        foreach ($marks as $m) {
            $t = strtotime((string) $m['d']); if (!$t) continue;
            $age = (int) floor(($today - $t) / 86400); $k = max(1, (int) ($m['n'] ?? 1));
            if ($age <= 30) $sends += $k;
            if ($age <= 90) { $sends90 += $k; $days90[] = $t; }
            if ($last === null || $t > $last) $last = $t;
        }
        if (count($days90) >= 2) { sort($days90); $gap = (int) round((end($days90) - $days90[0]) / 86400 / (count($days90) - 1)); }
    }
    $sp = (isset($changes['streams']) && $changes['streams'] !== null && $changes['streams'] !== '') ? (float) $changes['streams'] : null;
    return ['releases' => $releases, 'sends_30d' => $sends, 'streams_pct' => $sp, 'sends_90d' => $sends90, 'last_send' => $last ? date('Y-m-d', $last) : null, 'send_gap' => $gap];
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
                <div style="font-size:11px;color:#8B90A0;display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;">Seven steps from first release to a fan base that pays every month — each one gated on your real numbers.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-ladder' . (!empty($_GET['demo']) ? '&demo=1' : ''))); ?>" style="font-size:11px;font-weight:700;color:#E58BBD !important;text-decoration:none;white-space:nowrap;">Open the full ladder →</a>
                </div>
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
                        <?php if (!empty($b['need_label'])) : ?>
                        <div style="font-size:12px;color:#C9CCD6;margin-top:3px;"><strong style="color:#F4F5F7;"><?php echo esc_html($b['need_label']); ?></strong><?php if (!empty($b['rate_label'])) echo ' · ' . esc_html($b['rate_label']); ?><?php if (!empty($b['eta_label'])) echo ' · ' . esc_html($b['eta_label']); ?></div>
                        <?php endif; ?>
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

/**
 * Ladder-driven "Needs attention" items for the Overview (≤2). Pure.
 *  - warn: the list has gone quiet — sends gate failing with the last send 30+
 *    days ago (or never), only once there is a list worth talking to (stage ≥ 3).
 *  - info: almost there — the bottleneck gate is ≥85% of the way, with the
 *    distance and pace, linking to its first action.
 * Item shape matches lmeg_overview_attention(): tone, label, detail, href.
 */
function lmeg_si_stage_attention($st) {
    if (!$st || empty($st['stages'])) return [];
    $items = [];
    $stage = (int) $st['stage']; $r = $st['rhythm'] ?? []; $b = $st['bottleneck'] ?? null; $next = $st['next'] ?? null;
    $sends = null;
    foreach ($st['stages'][4]['gates'] ?? [] as $g) if ($g['key'] === 'sends') $sends = $g;
    if ($stage >= 3 && $sends && !$sends['pass']) {
        $since = $r['days_since'] ?? null;
        if ($since === null || $since >= 30) {
            $items[] = ['tone' => 'warn',
                'label'  => $since === null ? 'Your list has never heard from you' : 'Your list hasn’t heard from you in ' . (int) $since . ' days',
                'detail' => 'One send clears a stage-4 gate · ' . number_format((int) ($st['inputs']['list'] ?? 0)) . ' fans waiting',
                'href'   => lmeg_si_stage_action_href($sends['actions'][0] ?? ['page' => 'lmeg-compose'])];
        }
    }
    if ($next && $b && $b['progress'] !== null && $b['progress'] >= 85 && !empty($b['need_label']) && $b['key'] !== 'sends') {
        $detail = 'Stage ' . (int) $next['n'] . ' · ' . $next['name'];
        if (!empty($b['eta_label']) && $b['eta_days'] !== null && $b['eta_days'] <= 90) $detail .= ' · ' . $b['eta_label'];
        $items[] = ['tone' => 'info', 'label' => 'Almost there: ' . $b['need_label'] . ' (' . $b['label'] . ')', 'detail' => $detail,
                    'href' => lmeg_si_stage_action_href($b['actions'][0] ?? ['page' => 'lmeg-ladder'])];
    }
    return $items;
}

/**
 * One-row strip for the Overview: stage pill + progress bar + this week's one
 * thing (with distance) + a link to the Ladder page. Pure apart from admin_url.
 */
function lmeg_si_render_stage_strip($st, $card, $lbl) {
    if (!$st) return '';
    $stage = (int) $st['stage']; $b = $st['bottleneck']; $next = $st['next'];
    ob_start(); ?>
        <div style="<?php echo $card; ?>margin-bottom:14px;display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
            <div style="flex:0 0 auto;display:flex;align-items:center;gap:10px;">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#D05FA2,#7C6CF6);color:#fff;font:800 15px/1 var(--lmegA-font,inherit);box-shadow:0 6px 18px rgba(124,108,246,.35);"><?php echo $stage; ?></span>
                <div>
                    <div style="<?php echo $lbl; ?>">Your stage · of 7</div>
                    <div style="font:800 16px/1.2 var(--lmegA-font,inherit);color:#F4F5F7;margin-top:3px;"><?php echo esc_html($st['name']); ?></div>
                </div>
            </div>
            <div style="flex:1 1 220px;min-width:180px;">
                <div style="display:flex;justify-content:space-between;font-size:11px;color:#C9CCD6;"><span>Ladder progress</span><span style="color:#F4F5F7;font-weight:700;"><?php echo (int) $st['score']; ?>/100</span></div>
                <div style="position:relative;height:6px;border-radius:4px;background:rgba(255,255,255,.08);margin-top:4px;overflow:hidden;">
                    <div style="height:100%;width:<?php echo (int) $st['score']; ?>%;background:linear-gradient(90deg,#D05FA2,#7C6CF6);border-radius:4px;"></div>
                    <?php for ($i = 1; $i <= 6; $i++) : ?><div style="position:absolute;top:0;bottom:0;left:<?php echo round($i / 7 * 100, 2); ?>%;width:2px;background:rgba(14,15,22,.85);"></div><?php endfor; ?>
                </div>
            </div>
            <div style="flex:2 1 300px;min-width:240px;font-size:13px;color:#C9CCD6;line-height:1.45;">
                <?php if ($next && $b) : ?>
                    <span style="font:700 11px/1 var(--lmegA-font,inherit);letter-spacing:.06em;text-transform:uppercase;color:#E58BBD;">This week</span>
                    <span style="color:#F4F5F7;font-weight:700;"><?php echo esc_html($b['label']); ?></span>
                    <?php if (!empty($b['need_label'])) : ?><span style="color:#F4F5F7;">· <?php echo esc_html($b['need_label']); ?></span><?php endif; ?>
                    <?php if (!empty($b['eta_label'])) : ?><span style="color:#8B90A0;">· <?php echo esc_html($b['eta_label']); ?></span><?php endif; ?>
                <?php else : ?>
                    <span style="color:#34D399;font-weight:700;">Top of the ladder</span> — keep the rhythm: a release, a send and a drop every cycle.
                <?php endif; ?>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-ladder')); ?>" style="flex:0 0 auto;font-size:12px;font-weight:700;color:#E58BBD !important;text-decoration:none;white-space:nowrap;">Open the Ladder →</a>
        </div>
    <?php return ob_get_clean();
}

/**
 * The Monday owner digest's ladder block (light inbox HTML, inline styles,
 * dark text): stage + score, this week's one thing with distance and pace,
 * the first action and a link to the Ladder page. Pure apart from admin_url.
 */
function lmeg_si_stage_digest_html($st) {
    if (!$st) return '';
    $stage = (int) $st['stage']; $b = $st['bottleneck']; $next = $st['next'];
    $h  = '<p style="margin:14px 0 6px;font-weight:600;">Your stage · Fanloop ladder</p>';
    $h .= '<p style="margin:0 0 6px;"><strong>Stage ' . $stage . ' of 7 · ' . esc_html($st['name']) . '</strong> — ' . (int) $st['score'] . '/100 on the ladder. ' . esc_html($st['blurb']) . '</p>';
    if ($next && $b) {
        $bits = [esc_html($b['value']) . ', needs ' . esc_html($b['target'])];
        if (!empty($b['need_label'])) $bits[] = '<strong>' . esc_html($b['need_label']) . '</strong>';
        if (!empty($b['rate_label'])) $bits[] = esc_html($b['rate_label']);
        if (!empty($b['eta_label']))  $bits[] = esc_html($b['eta_label']);
        $links = [];
        foreach (array_slice((array) $b['actions'], 0, 2) as $a) $links[] = '<a href="' . esc_url(lmeg_si_stage_action_href($a)) . '">' . esc_html($a['label']) . '</a>';
        $links[] = '<a href="' . esc_url(admin_url('admin.php?page=lmeg-ladder')) . '">See the full ladder →</a>';
        $h .= '<p style="margin:0;">This week, to reach stage ' . (int) $next['n'] . ': <strong>' . esc_html($b['label']) . '</strong> — ' . implode(' · ', $bits) . '. <span style="white-space:nowrap;">' . implode(' · ', $links) . '</span></p>';
    } else {
        $h .= '<p style="margin:0;">Top of the ladder — members, list and streams are all growing together. Keep the rhythm: a release, a send and a drop every cycle. <a href="' . esc_url(admin_url('admin.php?page=lmeg-ladder')) . '">See the full ladder →</a></p>';
    }
    return $h;
}

/**
 * The ladder as a compact paragraph for the Ask AI context: stage + score,
 * what has passed, every gate of the next stage with value/target/distance/
 * pace, the conversion between rings, send rhythm, and the one move. Pure.
 */
function lmeg_si_stage_ai_summary($st) {
    if (!$st || empty($st['stages'])) return '';
    $stage = (int) $st['stage']; $next = $st['next']; $b = $st['bottleneck'];
    $out = 'Fanloop ladder (7 stages, sequential): stage ' . $stage . ' of 7 "' . $st['name'] . '" (' . (int) $st['score'] . '/100).';
    $passed = [];
    foreach ($st['stages'] as $s) { if ($s['n'] > $stage) break; foreach ($s['gates'] as $g) $passed[] = strtolower($g['label']) . ' ' . $g['value']; }
    if ($passed) $out .= ' Passed: ' . implode('; ', $passed) . '.';
    if ($next) {
        $needs = [];
        foreach ($next['gates'] as $g) {
            $line = strtolower($g['label']) . ' ' . $g['value'] . ' (needs ' . $g['target'] . ($g['pass'] ? ', passed' : '') . ')';
            if (!$g['pass']) {
                $extra = array_filter([$g['need_label'] ?? '', $g['rate_label'] ?? '', $g['eta_label'] ?? '']);
                if ($extra) $line .= ' — ' . implode(', ', $extra);
            }
            $needs[] = $line;
        }
        $out .= ' Next: stage ' . (int) $next['n'] . ' "' . $next['name'] . '" needs ' . implode('; ', $needs) . '.';
    } else {
        $out .= ' Top of the ladder: every gate passes.';
    }
    $r = $st['ratios'] ?? [];
    $pf = function ($k) { return $k === null ? 'unknown' : rtrim(rtrim(number_format((float) $k, $k < 1 ? 2 : 1), '0'), '.') . '%'; };
    $out .= ' Conversion: ' . $pf($r['fol_pct'] ?? null) . ' of monthly listeners follow on Spotify, ' . $pf($r['list_pct'] ?? null) . ' are on the list, ' . $pf($r['cust_pct'] ?? null) . ' of the list have bought.';
    if (!empty($st['rhythm']['label'])) $out .= ' Send rhythm: ' . $st['rhythm']['label'] . (($st['rhythm']['days_since'] ?? null) !== null ? ', last send ' . (int) $st['rhythm']['days_since'] . ' days ago' : '') . '.';
    if ($next && $b) {
        $acts = array_map(function ($a) { return $a['label']; }, array_slice((array) $b['actions'], 0, 3));
        $out .= ' The single best move this week: ' . strtolower($b['label']) . (!empty($b['alt']) ? ' (' . $b['alt'] . ')' : '') . ($acts ? ' — Fanloop tools: ' . implode(', ', $acts) : '') . '.';
    }
    return $out;
}

/** Plain-text line for the brief's text alternative. */
function lmeg_si_stage_text($st) {
    if (!$st) return '';
    $t = 'STAGE ' . (int) $st['stage'] . ' OF 7 · ' . $st['name'] . ' (' . (int) $st['score'] . '/100)';
    $b = $st['bottleneck'];
    if ($b) {
        $t .= "\nHolding you back: " . $b['label'] . ' — ' . $b['value'] . ', needs ' . $b['target'];
        $bits = array_filter([$b['need_label'] ?? '', $b['rate_label'] ?? '', $b['eta_label'] ?? '']);
        if ($bits) $t .= "\n" . implode(' · ', $bits);
        if (!empty($b['alt'])) $t .= "\n" . $b['alt'];
    }
    if (!empty($st['rhythm']['label'])) $t .= "\nSends: " . $st['rhythm']['label'] . (($st['rhythm']['days_since'] ?? null) !== null ? ' · last send ' . (int) $st['rhythm']['days_since'] . ' days ago' : '');
    return $t;
}

/* ---------------------------------------------------------------------------
 * The Ladder page (Fanloop → Social → Ladder; slug lmeg-ladder, the original
 * lmeg-stage redirects): the whole ladder, one row per
 * stage with every gate's value, target and progress; the playbook for the
 * stage you're working on; how each number is measured; and the day-by-day
 * history. ?demo=1 previews with sample data. Registered in lmeg_admin_menu.
 * ------------------------------------------------------------------------- */

// The page shipped as lmeg-stage for one release; keep those links working.
// WP checks page access in wp-admin/includes/menu.php BEFORE admin_init, so an
// unregistered slug dies with "not allowed" first — this hook fires right
// before that die, with no output sent yet.
add_action('admin_page_access_denied', 'lmeg_ladder_legacy_redirect');
add_action('admin_menu', 'lmeg_ladder_legacy_redirect', 999);
function lmeg_ladder_legacy_redirect() {
    if (!is_admin() || (isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '') !== 'lmeg-stage') return;
    if (!current_user_can('manage_options')) return;
    wp_safe_redirect(admin_url('admin.php?page=lmeg-ladder' . (!empty($_GET['demo']) ? '&demo=1' : '')));
    exit;
}

function lmeg_admin_stage() {
    if (!current_user_can('manage_options')) return;
    try {
        $demo = !empty($_GET['demo']) && function_exists('lmeg_s4a_demo_rows');
        $c = lmeg_si_stage_compute($demo);
        if ($demo && !$c) { $demo = false; $c = lmeg_si_stage_compute(false); }
        $t = function_exists('lmeg_si_tokens') ? lmeg_si_tokens() : [];
        $card = $t['card'] ?? 'background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px 18px;color:#F4F5F7;';
        $lbl  = $t['lbl']  ?? 'font:600 11px/1 var(--lmegA-font,inherit);letter-spacing:.06em;text-transform:uppercase;color:#8B90A0;';
        if ($c && !$demo) lmeg_si_stage_log_maybe($c);       // first live view of the day writes today's reading
        $log = $demo ? lmeg_si_stage_demo_log() : lmeg_si_stage_log_get();
        echo lmeg_si_render_stage_page($c, $log, $card, $lbl, $demo);
    } catch (\Throwable $e) {
        echo '<div class="notice notice-error" style="max-width:1040px;margin:14px 0;"><p><strong>The Ladder page hit an error while rendering.</strong><br>'
           . '<code>' . esc_html(get_class($e) . ': ' . $e->getMessage()) . '</code><br><span style="opacity:.75;">' . esc_html(basename($e->getFile()) . ':' . $e->getLine()) . '</span></p></div>';
    }
}

/** A gate row: ✓/○, label, value vs target, progress bar (or pass/fail only). Pure. */
function lmeg_si_stage_gate_row($g, $compact = false) {
    $pass = !empty($g['pass']); $p = $g['progress'];
    $bar = '';
    if (!$compact && $p !== null) {
        $w = max(2, min(100, (int) $p));
        $bar = '<div style="height:5px;border-radius:3px;background:rgba(255,255,255,.08);margin-top:6px;overflow:hidden;"><div style="height:100%;width:' . $w . '%;border-radius:3px;background:' . ($pass ? '#34D399' : 'linear-gradient(90deg,#D05FA2,#7C6CF6)') . ';"></div></div>';
    }
    $right = $pass ? '<span style="font-size:11px;font-weight:700;color:#34D399;white-space:nowrap;">Passed</span>'
           : ($p !== null ? '<span style="font-size:11px;font-weight:700;color:#F4F5F7;white-space:nowrap;">' . (int) $p . '%</span>' : '<span style="font-size:11px;font-weight:700;color:#F87171;white-space:nowrap;">Not yet</span>');
    // distance + pace line (only for gates with a countable target)
    $pace = '';
    if (!$compact && !$pass && !empty($g['need_label'])) {
        $bits = ['<span style="color:#F4F5F7;font-weight:700;">' . esc_html($g['need_label']) . '</span>'];
        if (!empty($g['rate_label'])) $bits[] = esc_html($g['rate_label']);
        if (!empty($g['eta_label']))  $bits[] = '<span style="color:' . (($g['eta_days'] !== null && $g['eta_days'] <= 90) ? '#34D399' : '#E58BBD') . ';">' . esc_html($g['eta_label']) . '</span>';
        $pace = '<div style="font-size:11px;color:#8B90A0;margin-top:5px;">' . implode(' · ', $bits) . (!empty($g['alt']) ? ' <span title="' . esc_attr($g['alt']) . '" style="cursor:help;">ⓘ</span>' : '') . '</div>';
    }
    if (!$compact && !$pass && !empty($g['evidence'])) $pace .= '<div style="font-size:11px;color:#8B90A0;margin-top:3px;">Signups in the last 28 days: <span style="color:#C9CCD6;">' . esc_html($g['evidence']) . '</span></div>';
    return '<div style="display:grid;grid-template-columns:18px 1fr auto;gap:8px;align-items:center;' . ($compact ? '' : 'padding:8px 0;border-top:1px solid rgba(255,255,255,.06);') . '">'
         . '<span style="color:' . ($pass ? '#34D399' : '#F87171') . ';font-weight:800;font-size:13px;">' . ($pass ? '✓' : '○') . '</span>'
         . '<div><div style="font-size:' . ($compact ? '12' : '13') . 'px;color:' . ($pass && !$compact ? '#C9CCD6' : '#F4F5F7') . ';font-weight:' . ($compact ? '500' : '600') . ';">' . esc_html($g['label'])
         . ' <span style="color:#8B90A0;font-weight:500;">— ' . esc_html($g['value']) . ($pass ? '' : ', needs ' . esc_html($g['target'])) . '</span></div>' . $bar . $pace . '</div>'
         . $right . '</div>';
}

/** The full Stage page markup. $c from lmeg_si_stage_compute (or null), $log the history map. */
function lmeg_si_render_stage_page($c, $log, $card, $lbl, $demo = false) {
    $st = ($c && !empty($c['stage'])) ? $c['stage'] : lmeg_si_stage([], []);
    $stage = (int) $st['stage']; $next = $st['next']; $b = $st['bottleneck'];
    $PB = lmeg_si_stage_playbook();
    $run = lmeg_si_stage_run($log, $stage);
    $changes = array_slice(array_reverse(lmeg_si_stage_changes($log)), 0, 5);
    // The stage-up (or slip) moment: the most recent change landed on the latest reading.
    $moment = null;
    if ($changes && $log) { $last_date = array_key_last($log); if ($changes[0]['date'] === $last_date) $moment = $changes[0]; }
    $L = lmeg_si_stage_ladder();
    $captured = ($c && !empty($c['snap']) && !empty($c['snap']->captured_date)) ? (string) $c['snap']->captured_date : '';
    $in = $st['inputs'] ?? [];
    $nf = function ($k) { return $k === null ? '—' : (function_exists('number_format_i18n') ? number_format_i18n((int) $k) : number_format((int) $k)); };
    $sf = function ($k) { return $k === null ? '—' : (($k > 0 ? '+' : ($k < 0 ? '−' : '')) . rtrim(rtrim(number_format(abs((float) $k), 1), '0'), '.') . '%'); };
    $pill = function ($a, $strong = false) {
        return '<a href="' . esc_url(lmeg_si_stage_action_href($a)) . '" style="font-size:11px;font-weight:700;color:#F4F5F7 !important;background:' . ($strong ? 'linear-gradient(135deg,#D05FA2,#7C6CF6)' : 'rgba(255,255,255,.06)') . ';border:1px solid rgba(255,255,255,' . ($strong ? '.28' : '.16') . ');border-radius:999px;padding:5px 11px;text-decoration:none;line-height:1.2;white-space:nowrap;">' . esc_html($a['label']) . '</a>';
    };
    $ins_url = admin_url('admin.php?page=lmeg-spotify-insights' . ($demo ? '&demo=1' : ''));
    $done_gates = $next ? count($next['gates']) - count($st['failing']) : 0;
    ob_start(); ?>
    <div class="wrap lmeg-admin">
        <h1>Fanloop — Ladder</h1>
        <?php if ($demo && function_exists('lmeg_demo_banner')) echo lmeg_demo_banner('lmeg-ladder'); ?>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0 4px;">
            <a class="button" href="<?php echo esc_url($ins_url); ?>">← Spotify Insights</a>
            <?php if (!$demo && function_exists('lmeg_demo_preview_button') && function_exists('lmeg_s4a_demo_rows')) echo str_replace(['<p>', '</p>'], '', lmeg_demo_preview_button('lmeg-ladder')); ?>
        </div>

        <!-- HERO: where you are + the one thing to work on ------------------->
        <div style="<?php echo $card; ?>max-width:1040px;margin:12px 0 14px;">
            <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:stretch;">
                <div style="flex:1 1 300px;min-width:260px;background:linear-gradient(120deg,rgba(208,95,162,.18),rgba(124,108,246,.18)),linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.14);border-radius:12px;padding:16px 18px;">
                    <div style="font:700 11px/1 var(--lmegA-font,inherit);letter-spacing:.06em;text-transform:uppercase;color:#C9CCD6;">Stage <?php echo $stage; ?> of 7</div>
                    <div style="font:800 30px/1.1 var(--lmegA-font,inherit);color:#F4F5F7;margin:6px 0 4px;"><?php echo esc_html($st['name']); ?></div>
                    <?php if ($moment) : $up = $moment['to'] > $moment['from']; ?>
                    <div style="display:inline-block;font-size:12px;font-weight:700;color:<?php echo $up ? '#34D399' : '#F87171'; ?>;background:<?php echo $up ? 'rgba(52,211,153,.12)' : 'rgba(248,113,113,.12)'; ?>;border:1px solid <?php echo $up ? 'rgba(52,211,153,.4)' : 'rgba(248,113,113,.4)'; ?>;border-radius:999px;padding:3px 10px;margin:0 0 8px;"><?php echo $up ? '⬆ You reached stage ' . (int) $moment['to'] . ' ' . ($moment['date'] === (function_exists('current_time') ? current_time('Y-m-d') : date('Y-m-d')) ? 'today' : 'on ' . esc_html(date_i18n('M j', strtotime($moment['date'])))) : 'Slipped from stage ' . (int) $moment['from'] . ' — a gate below stopped passing'; ?></div>
                    <?php endif; ?>
                    <div style="font-size:13px;color:#C9CCD6;line-height:1.45;max-width:520px;"><?php echo esc_html($st['blurb']); ?></div>
                    <div style="margin-top:14px;display:flex;justify-content:space-between;font-size:11px;color:#C9CCD6;"><span>Ladder progress</span><span style="color:#F4F5F7;font-weight:700;"><?php echo (int) $st['score']; ?>/100</span></div>
                    <div style="position:relative;height:8px;border-radius:4px;background:rgba(255,255,255,.08);margin-top:5px;overflow:hidden;">
                        <div style="height:100%;width:<?php echo (int) $st['score']; ?>%;background:linear-gradient(90deg,#D05FA2,#7C6CF6);border-radius:4px;"></div>
                        <?php for ($i = 1; $i <= 6; $i++) : ?><div style="position:absolute;top:0;bottom:0;left:<?php echo round($i / 7 * 100, 2); ?>%;width:2px;background:rgba(14,15,22,.85);"></div><?php endfor; ?>
                    </div>
                    <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:8px;font-size:11px;color:#8B90A0;">
                        <span><?php if ($next) : ?><?php echo (int) $done_gates; ?> of <?php echo count($next['gates']); ?> gate<?php echo count($next['gates']) === 1 ? '' : 's'; ?> to stage <?php echo (int) $next['n']; ?> · <?php echo esc_html($next['name']); ?><?php else : ?>Every gate on the ladder passes<?php endif; ?></span>
                        <span><?php if ($run) : ?>At this stage <?php echo $run['days'] === 1 ? 'since today' : 'for ' . (int) $run['days'] . ' days'; ?><?php echo $run['from_start'] && $run['days'] > 1 ? ' (as far back as we’ve tracked)' : ''; ?><?php else : ?>History starts with today’s reading<?php endif; ?></span>
                    </div>
                </div>
                <div style="flex:1 1 320px;min-width:280px;display:flex;flex-direction:column;gap:10px;">
                    <?php if ($next && $b) : ?>
                    <div style="background:#0E0F16;border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:14px 16px;flex:1;">
                        <div style="font:700 11px/1 var(--lmegA-font,inherit);letter-spacing:.06em;text-transform:uppercase;color:#E58BBD;margin-bottom:8px;">This week’s one thing</div>
                        <div style="font-size:16px;font-weight:800;color:#F4F5F7;line-height:1.25;"><?php echo esc_html($b['label']); ?></div>
                        <div style="font-size:13px;color:#C9CCD6;margin-top:4px;"><span style="color:#F87171;font-weight:700;"><?php echo esc_html($b['value']); ?></span> · needs <?php echo esc_html($b['target']); ?></div>
                        <?php if ($b['progress'] !== null) : ?>
                        <div style="height:6px;border-radius:3px;background:rgba(255,255,255,.08);margin-top:10px;overflow:hidden;"><div style="height:100%;width:<?php echo max(2, (int) $b['progress']); ?>%;border-radius:3px;background:linear-gradient(90deg,#D05FA2,#7C6CF6);"></div></div>
                        <div style="font-size:11px;color:#8B90A0;margin-top:4px;"><?php echo (int) $b['progress']; ?>% of the way there</div>
                        <?php endif; ?>
                        <?php if (!empty($b['need_label'])) : ?>
                        <div style="margin-top:10px;padding:9px 11px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:9px;font-size:12px;color:#C9CCD6;line-height:1.5;">
                            <span style="color:#F4F5F7;font-weight:800;font-size:14px;"><?php echo esc_html($b['need_label']); ?></span>
                            <?php if (!empty($b['alt'])) : ?><span style="color:#8B90A0;"> · <?php echo esc_html($b['alt']); ?></span><?php endif; ?>
                            <?php if (!empty($b['rate_label'])) : ?><br><?php echo esc_html($b['rate_label']); ?><?php if (!empty($b['eta_label'])) : ?> · <span style="color:<?php echo ($b['eta_days'] !== null && $b['eta_days'] <= 90) ? '#34D399' : '#E58BBD'; ?>;font-weight:700;"><?php echo esc_html($b['eta_label']); ?></span><?php endif; ?><?php if ($b['eta_days'] === null || $b['eta_days'] > 90) : ?> <span style="color:#8B90A0;">— the moves below change the pace.</span><?php endif; ?><?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($b['evidence'])) : ?>
                        <div style="font-size:11px;color:#8B90A0;margin-top:10px;">Where your last 28 days of signups came from: <span style="color:#F4F5F7;font-weight:600;"><?php echo esc_html($b['evidence']); ?></span> — the tool that brought the most is first below.</div>
                        <?php endif; ?>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:<?php echo !empty($b['evidence']) ? '8' : '12'; ?>px;">
                            <?php $k = 0; foreach ($b['actions'] as $a) echo $pill($a, $k++ === 0); ?>
                        </div>
                        <?php if (count($st['failing']) > 1) : ?>
                        <div style="font-size:11px;color:#8B90A0;margin-top:10px;">Then: <?php echo esc_html(implode(' · ', array_map(function ($g) { return $g['label']; }, array_slice($st['failing'], 1)))); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php else : ?>
                    <div style="background:#0E0F16;border:1px solid rgba(52,211,153,.35);border-radius:12px;padding:14px 16px;flex:1;">
                        <div style="font:700 11px/1 var(--lmegA-font,inherit);letter-spacing:.06em;text-transform:uppercase;color:#34D399;margin-bottom:8px;">Top of the ladder</div>
                        <div style="font-size:14px;color:#F4F5F7;line-height:1.5;">Members, list and streams are all growing together. Keep the rhythm: a release, a send and a drop every cycle.</div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px;"><?php foreach ($PB[7]['moves'] as $a) echo $pill($a); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- THE LADDER: every stage, every gate ------------------------------->
        <div style="<?php echo $card; ?>max-width:1040px;margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;flex-wrap:wrap;margin-bottom:6px;">
                <div style="<?php echo $lbl; ?>">The ladder · all seven stages</div>
                <div style="font-size:11px;color:#8B90A0;">You sit at the highest stage whose gates all pass, with every stage below it passing too. Unknown numbers never count as a pass.</div>
            </div>
            <?php foreach ($st['stages'] as $s) : $n = (int) $s['n']; $passed = $n <= $stage; $cur = $n === $stage + 1; $future = $n > $stage + 1;
                $circle = $passed ? 'background:rgba(52,211,153,.18);border:1px solid rgba(52,211,153,.5);color:#34D399;'
                        : ($cur ? 'background:linear-gradient(135deg,#D05FA2,#7C6CF6);border:1px solid rgba(255,255,255,.28);color:#fff;box-shadow:0 6px 18px rgba(124,108,246,.35);'
                        : 'background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.14);color:#8B90A0;');
                $status = $passed ? '<span style="font-size:11px;font-weight:700;color:#34D399;">✓ Passed</span>'
                        : ($cur ? '<span style="font-size:11px;font-weight:700;color:#fff;background:linear-gradient(135deg,#D05FA2,#7C6CF6);border-radius:999px;padding:3px 9px;">→ Working on it · ' . (int) $done_gates . ' of ' . count($s['gates']) . ' gate' . (count($s['gates']) === 1 ? '' : 's') . '</span>'
                        : '<span style="font-size:11px;font-weight:700;color:#8B90A0;">Later</span>');
                $pb = $PB[$n] ?? null; ?>
            <div style="display:grid;grid-template-columns:44px 1fr;gap:0 12px;">
                <div style="display:flex;flex-direction:column;align-items:center;">
                    <div style="<?php echo $circle; ?>width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex:0 0 auto;"><?php echo $passed ? '✓' : $n; ?></div>
                    <?php if ($n < 7) : ?><div style="width:2px;flex:1;min-height:14px;background:<?php echo $n < $stage ? 'rgba(52,211,153,.4)' : 'rgba(255,255,255,.1)'; ?>;margin:4px 0;"></div><?php endif; ?>
                </div>
                <div style="padding:6px 0 <?php echo $n < 7 ? '16' : '0'; ?>px;">
                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;flex-wrap:wrap;">
                        <div style="font:800 <?php echo $cur ? '18' : '15'; ?>px/1.2 var(--lmegA-font,inherit);color:<?php echo $future ? '#C9CCD6' : '#F4F5F7'; ?>;">Stage <?php echo $n; ?> · <?php echo esc_html($s['name']); ?></div>
                        <?php echo $status; ?>
                    </div>
                    <div style="font-size:12px;color:#C9CCD6;margin-top:3px;line-height:1.45;"><?php echo esc_html($s['blurb']); ?></div>
                    <?php if ($passed) : ?>
                    <div style="display:flex;flex-direction:column;gap:3px;margin-top:8px;"><?php foreach ($s['gates'] as $g) echo lmeg_si_stage_gate_row($g, true); ?></div>
                    <?php else : ?>
                    <div style="background:#0E0F16;border:1px solid rgba(255,255,255,<?php echo $cur ? '.14' : '.08'; ?>);border-radius:12px;padding:6px 14px 10px;margin-top:10px;">
                        <?php foreach ($s['gates'] as $i => $g) echo str_replace('border-top:1px solid rgba(255,255,255,.06);', $i === 0 ? '' : 'border-top:1px solid rgba(255,255,255,.06);', lmeg_si_stage_gate_row($g, false)); ?>
                        <?php if ($pb) : ?>
                        <?php if ($cur) : ?>
                        <div style="border-top:1px solid rgba(255,255,255,.08);margin-top:6px;padding-top:10px;">
                            <div style="font:700 11px/1 var(--lmegA-font,inherit);letter-spacing:.06em;text-transform:uppercase;color:#E58BBD;margin-bottom:6px;">Playbook for stage <?php echo $n; ?></div>
                            <div style="font-size:13px;color:#F4F5F7;line-height:1.5;max-width:640px;"><?php echo esc_html($pb['why']); ?></div>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;"><?php $k = 0; foreach ($pb['moves'] as $a) echo $pill($a, $k++ === 0); ?></div>
                        </div>
                        <?php else : ?>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:10px;"><span style="font-size:11px;color:#8B90A0;font-weight:600;">Playbook:</span><?php foreach ($pb['moves'] as $a) echo $pill($a); ?></div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- BETWEEN THE RINGS: conversion per hop + 28-day movement --------->
        <?php echo lmeg_si_render_stage_hops($st, $card, $lbl); ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;max-width:1040px;margin-bottom:14px;">
            <!-- HISTORY ----------------------------------------------------->
            <div style="<?php echo $card; ?>">
                <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;flex-wrap:wrap;margin-bottom:8px;">
                    <div style="<?php echo $lbl; ?>">Your progress · day by day</div>
                    <div style="font-size:11px;color:#8B90A0;"><?php echo $demo ? 'Sample history' : 'One reading a day, after the morning Spotify pull'; ?></div>
                </div>
                <?php if ($log) : ?>
                <?php echo lmeg_si_stage_history_svg($log); ?>
                <div style="font-size:11px;color:#8B90A0;margin-top:4px;">Ladder progress out of 100 · guide lines mark each stage · dots mark a stage change</div>
                <div style="margin-top:10px;font-size:13px;color:#F4F5F7;">
                    <?php if ($run && $run['days'] > 1) : ?>At stage <?php echo $stage; ?> for <strong><?php echo (int) $run['days']; ?> days</strong>, since <?php echo esc_html(date_i18n('M j', strtotime($run['since']))); ?>.
                    <?php elseif ($run) : ?>Today is the first reading at stage <?php echo $stage; ?>.
                    <?php else : ?>Tracking started <?php echo esc_html(date_i18n('M j', strtotime(array_key_first($log)))); ?>.<?php endif; ?>
                </div>
                <?php $dl = lmeg_si_stage_log_delta($log); if ($dl) : ?>
                <div style="font-size:12px;color:#C9CCD6;margin-top:6px;">Since <?php echo esc_html(date_i18n('M j', strtotime($dl['from']))); ?>:
                    <span style="color:<?php echo $dl['score'] > 0 ? '#34D399' : ($dl['score'] < 0 ? '#F87171' : '#F4F5F7'); ?>;font-weight:700;"><?php echo $dl['score'] > 0 ? '+' : ''; ?><?php echo (int) $dl['score']; ?> progress</span>
                    <?php if ($dl['list_pct'] !== null) : ?> · list share <?php echo esc_html(rtrim(rtrim(number_format((float) $dl['list_pct_from'], 2), '0'), '.')); ?>% → <span style="color:<?php echo $dl['list_pct'] > 0 ? '#34D399' : ($dl['list_pct'] < 0 ? '#F87171' : '#F4F5F7'); ?>;font-weight:700;"><?php echo esc_html(rtrim(rtrim(number_format((float) $dl['list_pct_to'], 2), '0'), '.')); ?>%</span><?php endif; ?>
                    <?php if ($dl['stage'] !== 0) : ?> · <span style="color:<?php echo $dl['stage'] > 0 ? '#34D399' : '#F87171'; ?>;font-weight:700;">stage <?php echo $dl['stage'] > 0 ? 'up' : 'down'; ?></span><?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($changes) : ?>
                <div style="display:flex;flex-direction:column;gap:4px;margin-top:8px;">
                    <?php foreach ($changes as $ch) : $up = $ch['to'] > $ch['from']; ?>
                    <div style="font-size:12px;color:#C9CCD6;"><span style="color:<?php echo $up ? '#34D399' : '#F87171'; ?>;font-weight:800;"><?php echo $up ? '↑' : '↓'; ?></span> <?php echo $up ? 'Reached' : 'Slipped to'; ?> stage <?php echo (int) $ch['to']; ?> · <?php echo esc_html($L[$ch['to']]['name'] ?? ''); ?> <span style="color:#8B90A0;">— <?php echo esc_html(date_i18n('M j', strtotime($ch['date']))); ?></span></div>
                    <?php endforeach; ?>
                </div>
                <?php elseif (count($log) > 1) : ?>
                <div style="font-size:12px;color:#8B90A0;margin-top:6px;">No stage change yet in this window.</div>
                <?php endif; ?>
                <?php else : ?>
                <div style="font-size:13px;color:#F4F5F7;line-height:1.5;">No readings yet. The first one lands after this morning’s Spotify pull (or the next time this page is opened after 9am), and the chart builds from there, one point a day.</div>
                <?php endif; ?>
            </div>
            <!-- HOW IT'S MEASURED ------------------------------------------->
            <div style="<?php echo $card; ?>">
                <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;flex-wrap:wrap;margin-bottom:8px;">
                    <div style="<?php echo $lbl; ?>">The numbers behind the gates</div>
                    <div style="font-size:11px;color:#8B90A0;"><?php echo $captured ? ($demo ? 'Sample data' : 'Spotify captured ' . esc_html($captured)) : 'No Spotify for Artists snapshot yet'; ?></div>
                </div>
                <?php $tr = lmeg_si_stage_trends($log); $n_read = 0; foreach ((array) $log as $e_) if (!empty($e_['in'])) $n_read++;
                $rows = [
                    ['Monthly listeners', $nf($in['listeners'] ?? null), 'Spotify for Artists, last 28 days', 'l'],
                    ['Spotify followers', $nf($in['sp_followers'] ?? null), 'Spotify for Artists daily series, else the public Spotify API', 'f'],
                    ['Fans on your list', $nf($in['list'] ?? null), 'Fanloop subscribers (superfans: ' . $nf($in['superfans'] ?? null) . ')', 'ls'],
                    ['Fans who have bought', $nf($in['customers'] ?? null), 'Distinct buyers across Shopify and the Fanloop store', 'c'],
                    ['Paying members', $nf($in['members'] ?? null), 'Active paid tiers', 'm'],
                    ['Releases', $nf($in['releases'] ?? null), 'Your catalogue on Spotify', null],
                    ['Sends in the last 30 days', $nf($in['sends_30d'] ?? null), 'Completed broadcasts to your list' . (!empty($st['rhythm']['label']) ? ' · ' . $st['rhythm']['label'] : '') . (($st['rhythm']['days_since'] ?? null) !== null ? ' · last send ' . ($st['rhythm']['days_since'] === 0 ? 'today' : ($st['rhythm']['days_since'] === 1 ? 'yesterday' : (int) $st['rhythm']['days_since'] . ' days ago')) : ''), 's'],
                    ['28-day streams vs the 28 before', $sf($in['streams_pct'] ?? null), 'Spotify for Artists, this capture vs the previous one', null],
                ]; ?>
                <div style="display:flex;flex-direction:column;">
                    <?php foreach ($rows as $i => $r) : $sk = $r[3]; $spark = ($sk && !empty($tr[$sk])) ? lmeg_si_stage_spark($tr[$sk]) : ''; ?>
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;padding:7px 0;<?php echo $i ? 'border-top:1px solid rgba(255,255,255,.06);' : ''; ?>">
                        <div style="flex:1 1 auto;min-width:0;"><div style="font-size:13px;color:#F4F5F7;font-weight:600;"><?php echo esc_html($r[0]); ?></div><div style="font-size:11px;color:#8B90A0;"><?php echo esc_html($r[2]); ?></div></div>
                        <?php if ($spark) : ?><div style="flex:0 0 auto;" title="Last <?php echo count($tr[$sk]); ?> readings"><?php echo $spark; ?></div><?php endif; ?>
                        <div style="flex:0 0 auto;font:700 15px/1 var(--lmegA-font,inherit);color:<?php echo $r[1] === '—' ? '#8B90A0' : '#F4F5F7'; ?>;white-space:nowrap;"><?php echo esc_html($r[1]); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$tr) : ?><div style="font-size:11px;color:#8B90A0;margin-top:6px;">Trend lines appear after three daily readings<?php echo $n_read ? ' (' . $n_read . ' so far)' : ''; ?>.</div><?php endif; ?>
                <div style="font-size:11px;color:#8B90A0;margin-top:8px;line-height:1.5;">A ring that isn’t connected shows “—” and fails its gate rather than pretending to pass. Ladder progress = stages passed plus the share of the next stage’s gates you’ve cleared, out of 7.</div>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
}
