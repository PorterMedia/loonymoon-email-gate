<?php
/**
 * Plan brief — the handful of things the data can't tell us.
 *
 * Fanloop already knows what happened: streams per song, who opens, who buys,
 * which city, which playlist. What it cannot know is what the artist has
 * coming, what they're aiming at, and what they'll actually do in a week. So
 * this asks exactly that and nothing else — no archetype quiz, no influences,
 * no questions whose answers are already in the database.
 *
 * Every field changes the plan, and the page says how next to each one. If a
 * question wouldn't change a single move, it doesn't belong here.
 */

if (!defined('ABSPATH')) {
    exit;
}

function lmeg_plan_brief_defaults() {
    return [
        'goal'            => '',     // list | sales | streams | shows | sync | members
        'dates'           => [],     // [['type'=>'release','name'=>'','date'=>'YYYY-MM-DD'], …]
        'posts_per_week'  => '',     // 0–7
        'sends_per_month' => '',     // 0–8
        'video_ok'        => '',     // '' unknown | '1' | '0'
        'wont_do'         => '',
        'budget'          => '',     // none | low | mid | high
        'budget_amount'   => '',     // dollars over 90 days, if they know the number
        'funding_expected'=> '',     // grant money applied for or confirmed, in dollars
        'known_for'       => '',
        'cities'          => '',
        'sync'            => '',     // '' | '1' | '0'
        'updated_at'      => '',
    ];
}

function lmeg_plan_brief() {
    $b = get_option('lmeg_plan_brief');
    $b = is_array($b) ? $b : [];
    $out = array_merge(lmeg_plan_brief_defaults(), $b);
    $out['dates'] = array_values(array_filter((array) $out['dates'], function ($d) { return !empty($d['date']); }));
    return $out;
}

function lmeg_plan_brief_goals() {
    return [
        'list'    => ['Grow the list',        'more people you can reach directly'],
        'streams' => ['Grow streams',         'more listening, more often'],
        'sales'   => ['Sell more',            'merch, music, anything in the store'],
        'shows'   => ['Play more shows',      'rooms full enough to book again'],
        'members' => ['Build paying support', 'recurring members'],
        'sync'    => ['Get placements',       'film, TV, games, ads'],
    ];
}

function lmeg_plan_brief_budgets() {
    return ['none' => 'Nothing', 'low' => 'Under $250', 'mid' => '$250 – $1,000', 'high' => 'Over $1,000'];
}

function lmeg_plan_brief_date_types() {
    return ['release' => 'Release', 'tour' => 'Show or tour', 'video' => 'Video', 'merch' => 'Merch drop', 'other' => 'Something else'];
}

/** How many answers are in, and what each missing one would change. */
function lmeg_plan_brief_missing($b = null) {
    $b = $b ?: lmeg_plan_brief();
    $rows = [
        ['key' => 'dates',           'label' => 'What\'s coming, and when',   'changes' => 'anchors a full rollout to each date — pre-save, announce, first listen, countdown, release day, save ask'],
        ['key' => 'goal',            'label' => 'The one outcome for 90 days','changes' => 'reorders every week so the moves that serve it come first'],
        ['key' => 'posts_per_week',  'label' => 'Posts you can manage a week','changes' => 'sets how many content slots the calendar fills'],
        ['key' => 'sends_per_month', 'label' => 'Emails a month you\'re good with', 'changes' => 'caps how many sends a single week can ask of you'],
        ['key' => 'wont_do',         'label' => 'What you won\'t do',          'changes' => 'drops those ideas out of the calendar for good'],
        ['key' => 'budget',          'label' => 'Budget for 90 days',          'changes' => 'adds paid moves only when there is money behind them'],
        ['key' => 'cities',          'label' => 'Cities you can get to',       'changes' => 'aims the live and local moves somewhere you can actually go'],
        ['key' => 'known_for',       'label' => 'What fans come to you for',   'changes' => 'gives the content pillars their voice'],
    ];
    $missing = [];
    foreach ($rows as $r) {
        $v = $b[$r['key']] ?? '';
        $empty = is_array($v) ? !$v : (trim((string) $v) === '');
        if ($empty) $missing[] = $r;
    }
    return ['missing' => $missing, 'answered' => count($rows) - count($missing), 'total' => count($rows)];
}

/** Upcoming brief dates, soonest first, past ones dropped. */
function lmeg_plan_brief_dates($b = null, $today = null) {
    $b = $b ?: lmeg_plan_brief();
    $today = $today ?: current_time('Y-m-d');
    $out = [];
    foreach ((array) $b['dates'] as $d) {
        $date = substr((string) ($d['date'] ?? ''), 0, 10);
        if (!$date || $date < $today) continue;
        $out[] = ['type' => (string) ($d['type'] ?? 'other'), 'name' => trim((string) ($d['name'] ?? '')), 'date' => $date];
    }
    usort($out, function ($a, $b2) { return strcmp($a['date'], $b2['date']); });
    return $out;
}

/** Words the artist has ruled out, lowercased. */
function lmeg_plan_brief_exclusions($b = null) {
    $b = $b ?: lmeg_plan_brief();
    $raw = strtolower(trim((string) $b['wont_do']));
    if ($raw === '') return [];
    $parts = preg_split('/[,;\n]+/', $raw);
    $out = [];
    foreach ((array) $parts as $p) { $p = trim($p); if (strlen($p) >= 3) $out[] = $p; }
    return $out;
}

/** True when a line of plan text collides with something they won't do. Pure. */
function lmeg_plan_brief_blocked($text, $exclusions) {
    $t = strtolower((string) $text);
    foreach ((array) $exclusions as $x) if ($x !== '' && strpos($t, $x) !== false) return true;
    return false;
}

/* ---------------------------------------------------------------------------
 * The page.
 * ------------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_submenu_page('lmeg', 'Plan brief', 'Plan brief', 'manage_options', 'lmeg-plan-brief', 'lmeg_admin_plan_brief');
}, 20);

function lmeg_admin_plan_brief() {
    if (!current_user_can('manage_options')) return;
    $notice = '';
    if (isset($_POST['lmeg_plan_brief_nonce']) && wp_verify_nonce($_POST['lmeg_plan_brief_nonce'], 'lmeg_plan_brief')) {
        $dates = [];
        $types = array_keys(lmeg_plan_brief_date_types());
        foreach ((array) ($_POST['d_date'] ?? []) as $i => $dt) {
            $dt = substr(sanitize_text_field(wp_unslash((string) $dt)), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) continue;
            $ty = sanitize_key(wp_unslash((string) ($_POST['d_type'][$i] ?? 'other')));
            $dates[] = ['type' => in_array($ty, $types, true) ? $ty : 'other',
                        'name' => sanitize_text_field(wp_unslash((string) ($_POST['d_name'][$i] ?? ''))),
                        'date' => $dt];
        }
        $goal = sanitize_key(wp_unslash((string) ($_POST['goal'] ?? '')));
        $bud  = sanitize_key(wp_unslash((string) ($_POST['budget'] ?? '')));
        $save = [
            'goal'            => array_key_exists($goal, lmeg_plan_brief_goals()) ? $goal : '',
            'dates'           => $dates,
            'posts_per_week'  => ($_POST['posts_per_week'] ?? '') === '' ? '' : max(0, min(7, (int) $_POST['posts_per_week'])),
            'sends_per_month' => ($_POST['sends_per_month'] ?? '') === '' ? '' : max(0, min(8, (int) $_POST['sends_per_month'])),
            'video_ok'        => in_array((string) ($_POST['video_ok'] ?? ''), ['0', '1'], true) ? (string) $_POST['video_ok'] : '',
            'wont_do'         => sanitize_textarea_field(wp_unslash((string) ($_POST['wont_do'] ?? ''))),
            'budget'          => array_key_exists($bud, lmeg_plan_brief_budgets()) ? $bud : '',
            'budget_amount'   => ($_POST['budget_amount'] ?? '') === '' ? '' : max(0, (int) $_POST['budget_amount']),
            'funding_expected'=> ($_POST['funding_expected'] ?? '') === '' ? '' : max(0, (int) $_POST['funding_expected']),
            'known_for'       => sanitize_textarea_field(wp_unslash((string) ($_POST['known_for'] ?? ''))),
            'cities'          => sanitize_text_field(wp_unslash((string) ($_POST['cities'] ?? ''))),
            'sync'            => in_array((string) ($_POST['sync'] ?? ''), ['0', '1'], true) ? (string) $_POST['sync'] : '',
            'updated_at'      => current_time('mysql'),
        ];
        update_option('lmeg_plan_brief', $save, false);
        if (function_exists('lmeg_plan_generate')) { delete_option('lmeg_plan_note'); lmeg_plan_generate(); }
        $notice = '<div class="notice notice-success"><p>Saved, and the plan was rebuilt around it. <a href="' . esc_url(admin_url('admin.php?page=lmeg-plan')) . '">See the plan →</a></p></div>';
    }

    $b = lmeg_plan_brief();
    $t = function_exists('lmeg_si_tokens') ? lmeg_si_tokens() : ['card' => 'background:#161826;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px 18px;color:#F4F5F7;', 'lbl' => '', 'muted' => 'color:#8B90A0;'];
    $card = $t['card']; $lbl = $t['lbl'];
    $st = lmeg_plan_brief_missing($b);
    $rows = max(4, count($b['dates']) + 2);
    $q = function ($n, $title, $sub) use ($lbl) {
        echo '<div style="margin:0 0 6px;"><span style="' . $lbl . '">Question ' . (int) $n . '</span>'
           . '<div style="font:600 15px/1.35 var(--lmegA-font,inherit);color:#F4F5F7;margin-top:4px;">' . esc_html($title) . '</div>'
           . '<div style="font-size:12.5px;color:#8B90A0;margin-top:3px;">' . esc_html($sub) . '</div></div>';
    };
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;">Plan brief
            <span style="font:400 13px/1.4 var(--lmegA-font,inherit);color:#8B90A0;">Eight questions. Only the things your data can't answer.</span>
        </h1>
        <?php echo $notice; ?>
        <p style="font-size:13px;color:#C9CCD6;max-width:780px;">
            <?php echo (int) $st['answered']; ?> of <?php echo (int) $st['total']; ?> answered.
            Nothing here is a personality test — each answer moves, adds or removes real moves on the plan, and it says so underneath.
        </p>

        <form method="post" style="max-width:820px;">
            <?php wp_nonce_field('lmeg_plan_brief', 'lmeg_plan_brief_nonce'); ?>

            <div style="<?php echo $card; ?>margin:0 0 14px;">
                <?php $q(1, 'What\'s coming, and when?', 'Releases, shows, videos, merch. A date is all the plan needs to build its runway.'); ?>
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <?php for ($i = 0; $i < $rows; $i++) : $d = $b['dates'][$i] ?? ['type' => 'release', 'name' => '', 'date' => '']; ?>
                    <tr>
                        <td style="padding:4px 8px 4px 0;width:150px;">
                            <select name="d_type[]" style="width:100%;">
                                <?php foreach (lmeg_plan_brief_date_types() as $k => $v) : ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected((string) $d['type'], $k); ?>><?php echo esc_html($v); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td style="padding:4px 8px 4px 0;"><input type="text" name="d_name[]" value="<?php echo esc_attr((string) $d['name']); ?>" placeholder="What is it called?" style="width:100%;"></td>
                        <td style="padding:4px 0;width:170px;"><input type="date" name="d_date[]" value="<?php echo esc_attr((string) $d['date']); ?>" style="width:100%;"></td>
                    </tr>
                    <?php endfor; ?>
                </table>
                <div style="font-size:11.5px;color:#8B90A0;margin-top:8px;">Each date lays out its own rollout: pre-save 4 weeks out, announce at 3, first listen for superfans at 2, countdown at 1, the night before, release day, then the save ask and the focus-track push.</div>
            </div>

            <div style="<?php echo $card; ?>margin:0 0 14px;">
                <?php $q(2, 'What is the next 90 days for?', 'One answer. The plan will favour it when two moves compete for the same week.'); ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px;margin-top:10px;">
                    <?php foreach (lmeg_plan_brief_goals() as $k => $g) : ?>
                    <label style="display:flex;gap:8px;align-items:flex-start;font-size:13px;cursor:pointer;">
                        <input type="radio" name="goal" value="<?php echo esc_attr($k); ?>" <?php checked((string) $b['goal'], $k); ?> style="margin-top:3px;">
                        <span><strong style="color:#F4F5F7;"><?php echo esc_html($g[0]); ?></strong><br><span style="color:#8B90A0;font-size:12px;"><?php echo esc_html($g[1]); ?></span></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="<?php echo $card; ?>margin:0 0 14px;">
                <?php $q(3, 'Honestly, how much can you do in a week?', 'Be conservative. A plan you finish beats a plan that looks impressive.'); ?>
                <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:flex-end;margin-top:8px;">
                    <label style="font-size:13px;">Posts a week<br>
                        <input type="number" name="posts_per_week" min="0" max="7" value="<?php echo esc_attr((string) $b['posts_per_week']); ?>" placeholder="2" style="width:90px;">
                    </label>
                    <label style="font-size:13px;">Emails a month<br>
                        <input type="number" name="sends_per_month" min="0" max="8" value="<?php echo esc_attr((string) $b['sends_per_month']); ?>" placeholder="4" style="width:90px;">
                    </label>
                    <label style="font-size:13px;">Video<br>
                        <select name="video_ok" style="width:170px;">
                            <option value="" <?php selected((string) $b['video_ok'], ''); ?>>Not sure yet</option>
                            <option value="1" <?php selected((string) $b['video_ok'], '1'); ?>>Yes, I'll shoot video</option>
                            <option value="0" <?php selected((string) $b['video_ok'], '0'); ?>>No video from me</option>
                        </select>
                    </label>
                </div>
                <div style="font-size:11.5px;color:#8B90A0;margin-top:10px;">Posts a week sets the number of content slots per week. Emails a month caps the sends any one week can ask for. Saying no to video keeps video ideas off the calendar.</div>
            </div>

            <div style="<?php echo $card; ?>margin:0 0 14px;">
                <?php $q(4, 'What won\'t you do?', 'Trends, dancing, face on camera, tour vlogs — whatever you\'d refuse. Commas or new lines.'); ?>
                <textarea name="wont_do" rows="2" style="width:100%;" placeholder="dancing, lip sync, face on camera"><?php echo esc_textarea((string) $b['wont_do']); ?></textarea>
                <div style="font-size:11.5px;color:#8B90A0;margin-top:8px;">Any idea or move whose wording matches one of these is dropped before the plan is written.</div>
            </div>

            <div style="<?php echo $card; ?>margin:0 0 14px;">
                <?php $q(5, 'What can you spend over the next 90 days?', 'Paid moves only appear when there is money behind them.'); ?>
                <select name="budget" style="min-width:220px;margin-top:6px;">
                    <option value="">Rather not say</option>
                    <?php foreach (lmeg_plan_brief_budgets() as $k => $v) : ?>
                    <option value="<?php echo esc_attr($k); ?>" <?php selected((string) $b['budget'], $k); ?>><?php echo esc_html($v); ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:flex-end;margin-top:12px;">
                    <label style="font-size:13px;">Or the actual number, if you know it ($)<br>
                        <input type="number" name="budget_amount" min="0" step="50" value="<?php echo esc_attr((string) $b['budget_amount']); ?>" placeholder="1500" style="width:140px;">
                    </label>
                    <label style="font-size:13px;">Grant money applied for or confirmed ($)<br>
                        <input type="number" name="funding_expected" min="0" step="50" value="<?php echo esc_attr((string) $b['funding_expected']); ?>" placeholder="0" style="width:140px;">
                    </label>
                </div>
                <div style="font-size:11.5px;color:#8B90A0;margin-top:10px;">A real number gets you an allocation across assets, content, paid reach, publicity, live and merch instead of a band. Grant money is subtracted to show what actually leaves your account.</div>
            </div>

            <div style="<?php echo $card; ?>margin:0 0 14px;">
                <?php $q(6, 'Cities you can actually get to?', 'Where a show, an in-store or a local push is realistic for you.'); ?>
                <input type="text" name="cities" value="<?php echo esc_attr((string) $b['cities']); ?>" placeholder="Toronto, Montreal, Hamilton" style="width:100%;">
                <div style="font-size:11.5px;color:#8B90A0;margin-top:8px;">Live and local moves aim at the busiest of these by streams, instead of a city you can't reach.</div>
            </div>

            <div style="<?php echo $card; ?>margin:0 0 14px;">
                <?php $q(7, 'What do fans come to you for?', 'In your words. One or two lines is plenty.'); ?>
                <textarea name="known_for" rows="3" style="width:100%;" placeholder="the quiet ones. people say they put it on at 2am."><?php echo esc_textarea((string) $b['known_for']); ?></textarea>
                <div style="font-size:11.5px;color:#8B90A0;margin-top:8px;">Gives the content pillars their voice, and goes into every draft Fanloop writes for you.</div>
            </div>

            <div style="<?php echo $card; ?>margin:0 0 18px;">
                <?php $q(8, 'Chasing sync placements?', 'Film, TV, games, ads.'); ?>
                <select name="sync" style="min-width:220px;margin-top:6px;">
                    <option value="" <?php selected((string) $b['sync'], ''); ?>>Not sure</option>
                    <option value="1" <?php selected((string) $b['sync'], '1'); ?>>Yes, I want placements</option>
                    <option value="0" <?php selected((string) $b['sync'], '0'); ?>>Not right now</option>
                </select>
                <div style="font-size:11.5px;color:#8B90A0;margin-top:8px;">Adds a quarterly pitching move with the assets and instrumentals to send.</div>
            </div>

            <p><button class="button button-primary button-hero">Save and rebuild the plan</button>
                <?php if (!empty($b['updated_at'])) : ?><span style="font-size:12px;color:#8B90A0;margin-left:10px;">Last answered <?php echo esc_html(date_i18n('M j, Y', strtotime((string) $b['updated_at']))); ?></span><?php endif; ?>
            </p>
        </form>
    </div>
    <?php
}
