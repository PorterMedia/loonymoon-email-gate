<?php
/**
 * Plan — the marketing team, run off the artist's own numbers.
 *
 * Every other tool in Fanloop answers "what happened". This one answers "what
 * do I do next week", and it answers it from evidence rather than a template:
 * a rolling four-week plan of dated moves, each carrying the number that
 * triggered it and a button that opens the tool which performs it (usually a
 * pre-filled Compose draft aimed at the right Fanbase group).
 *
 * Shape of the thing:
 *   lmeg_plan_context()      gathers first-party data into one flat array
 *   lmeg_plan_rules()        pure — context in, candidate moves out (scored)
 *   lmeg_plan_schedule()     pure — spreads moves over four weeks, caps sends
 *   lmeg_plan_generate()     persists the schedule, keeping done/skipped rows
 *   lmeg_admin_plan()        the page: this week, the next three, the reasons
 *
 * The rules are deliberate and inspectable: no model decides what to do. An
 * LLM is used for one optional paragraph of framing and nothing else, so a
 * site with no AI key gets the identical plan.
 *
 * Release rollouts are anchored to real dates from Drops/Releases, so a record
 * dated six weeks out lays its own runway (pre-save, announce, teaser,
 * countdown, release day, save ask, focus-track push) instead of the artist
 * remembering the shape of a rollout.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('LMEG_PLAN_DB_VERSION')) define('LMEG_PLAN_DB_VERSION', '1');

function lmeg_plan_table() {
    global $wpdb;
    return $wpdb->prefix . 'lmeg_plan_moves';
}

/** Self-installing, version-gated table (same pattern as link-tracking/releases). */
add_action('init', 'lmeg_plan_maybe_install', 1);
function lmeg_plan_maybe_install() {
    if (get_option('lmeg_plan_db_version') === LMEG_PLAN_DB_VERSION) return;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    global $wpdb;
    $t = lmeg_plan_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        mkey VARCHAR(80) NOT NULL DEFAULT '',
        due_date DATE NOT NULL,
        week_start DATE NOT NULL,
        title VARCHAR(190) NOT NULL DEFAULT '',
        why TEXT NULL,
        channel VARCHAR(20) NOT NULL DEFAULT '',
        effort VARCHAR(10) NOT NULL DEFAULT '',
        metric VARCHAR(160) NOT NULL DEFAULT '',
        score INT NOT NULL DEFAULT 0,
        action_label VARCHAR(90) NOT NULL DEFAULT '',
        action_page VARCHAR(60) NOT NULL DEFAULT '',
        action_args TEXT NULL,
        action_href VARCHAR(600) NOT NULL DEFAULT '',
        status VARCHAR(10) NOT NULL DEFAULT 'todo',
        plan_run DATE NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY move_slot (mkey, due_date),
        KEY due_date (due_date),
        KEY status (status)
    ) $charset;");
    update_option('lmeg_plan_db_version', LMEG_PLAN_DB_VERSION);
}

/* ---------------------------------------------------------------------------
 * Context — every number the rules are allowed to see, gathered once.
 * Guarded with function_exists throughout so a site missing a module (no
 * store, no S4A, no social) still gets a plan built from what it does have.
 * ------------------------------------------------------------------------- */

function lmeg_plan_context($demo = false) {
    $today = current_time('Y-m-d');
    $ctx = [
        'today'  => $today,
        'artist' => function_exists('lmeg_artist') ? (string) lmeg_artist() : '',
        'demo'   => (bool) $demo,
        'stage'  => null,
        'list'   => [],
        'streams'=> [],
        'songs'  => [],
        'sends'  => [],
        'release'=> [],
        'store'  => [],
        'geo'    => [],
        'social' => [],
        'has'    => [],
        'findings' => [],
    ];

    // Ladder: stage, bottleneck, ratios, and the raw ring inputs.
    if (function_exists('lmeg_si_stage_compute')) {
        $c = lmeg_si_stage_compute($demo);
        if ($c && !empty($c['stage'])) {
            $st = $c['stage'];
            $in = (array) ($st['inputs'] ?? []);
            $ctx['stage'] = [
                'stage'      => (int) ($st['stage'] ?? 0),
                'score'      => (int) ($st['score'] ?? 0),
                'name'       => (string) ($st['name'] ?? ''),
                'bottleneck' => $st['bottleneck'] ?? null,
                'ratios'     => (array) ($st['ratios'] ?? []),
                'inputs'     => $in,
            ];
            $ctx['list'] = [
                'total'     => lmeg_plan_int($in['list'] ?? null),
                'active'    => lmeg_plan_int($in['list_active'] ?? null),
                'atrisk'    => lmeg_plan_int($in['list_atrisk'] ?? null),
                'dormant'   => lmeg_plan_int($in['list_dormant'] ?? null),
                'new'       => lmeg_plan_int($in['list_new'] ?? null),
                'superfans' => lmeg_plan_int($in['superfans'] ?? null),
                'members'   => lmeg_plan_int($in['members'] ?? null),
                'customers' => lmeg_plan_int($in['customers'] ?? null),
                'bounced'   => lmeg_plan_int($in['list_bounced'] ?? null),
            ];
            $rh = (array) ($c['stage']['rhythm'] ?? []);
            $ex = (array) ($c['extra'] ?? []);
            $last = $ex['last_send'] ?? ($rh['last_send'] ?? null);
            $ctx['sends'] = [
                'count_30d' => lmeg_plan_int($ex['sends_30d'] ?? ($in['sends_30d'] ?? null)),
                'last'      => $last ? (string) $last : null,
                'gap_days'  => $last ? max(0, (int) floor((strtotime($today) - strtotime((string) $last)) / 86400)) : null,
                'avg_gap'   => lmeg_plan_int($ex['send_gap'] ?? null),
            ];
            $ctx['store']['tiers'] = lmeg_plan_int(($c['stage']['tiers']['active'] ?? null));
        }
    }

    // Streaming: the same context the Insights page and daily brief build from.
    if (function_exists('lmeg_si_quick_context')) {
        $q = lmeg_si_quick_context($demo);
        if ($q) {
            $snap = $q['snap']; $meta = (array) $q['meta']; $sw = $q['sw'];
            $fs = array_values(array_filter(array_map('intval', (array) ($meta['daily']['followers'] ?? []))));
            $ml = (int) $snap->monthly_listeners;
            $ctx['streams'] = [
                'listeners'  => $ml,
                'streams'    => (int) $snap->streams,
                'saves'      => $snap->saves === null ? null : (int) $snap->saves,
                'followers'  => $fs ? (int) end($fs) : null,
                'spl'        => $snap->streams_per_listener === null ? null : (float) $snap->streams_per_listener,
                'streams_pct'=> $q['sp'] === null ? null : (float) $q['sp'],
                'captured'   => (string) $snap->captured_date,
                'save_rate'  => ($ml > 0 && $snap->saves !== null) ? round((int) $snap->saves / $ml * 100, 2) : null,
            ];
            if (is_array($sw)) {
                $ctx['songs'] = ['up' => (array) ($sw['up'] ?? []), 'down' => (array) ($sw['down'] ?? []), 'lead' => $sw['lead'] ?? null, 'n' => (int) ($sw['n'] ?? 0)];
            }
            $ctx['map'] = (array) ($q['map'] ?? []);
            if (function_exists('lmeg_si_analyze')) $ctx['findings'] = (array) lmeg_si_analyze($q['ctx']);
            // Geography: biggest market, biggest city, fastest riser.
            $countries = (array) ($meta['countries'] ?? []);
            $tot = 0; foreach ($countries as $c2) $tot += (int) ($c2['streams'] ?? $c2['v'] ?? 0);
            if ($countries) {
                $first = reset($countries);
                $cc = (string) ($first['country'] ?? $first['c'] ?? array_key_first($countries));
                $v  = (int) ($first['streams'] ?? $first['v'] ?? 0);
                $ctx['geo']['top_country'] = ['cc' => strtoupper(substr($cc, 0, 2)), 'pct' => $tot > 0 ? round($v / $tot * 100, 1) : null];
            }
            $cities = (array) ($meta['cities'] ?? []);
            if ($cities) {
                $first = reset($cities);
                $ctx['geo']['top_city'] = ['name' => (string) ($first['city'] ?? $first['name'] ?? ''), 'streams' => (int) ($first['streams'] ?? $first['v'] ?? 0)];
            }
            $moves = (array) ($q['ctx']['country_moves'] ?? []);
            if (!empty($moves['up'][0])) {
                $u = $moves['up'][0];
                $ctx['geo']['riser'] = ['cc' => strtoupper((string) ($u['country'] ?? '')), 'pct' => isset($u['pct']) ? (float) $u['pct'] : null];
            }
            $age = (array) ($meta['age'] ?? []);
            if ($age) { $top = null; foreach ($age as $k => $v2) { $n = (int) (is_array($v2) ? ($v2['v'] ?? 0) : $v2); if ($top === null || $n > $top[1]) $top = [(string) (is_array($v2) ? ($v2['band'] ?? $k) : $k), $n]; } if ($top) $ctx['audience']['age'] = $top[0]; }
        }
    }

    if ($demo) return lmeg_plan_context_demo($ctx);

    // Send performance: the best-opened of the last eight completed sends.
    // Opens come from the per-recipient log AND the event spine, whichever
    // knows more — sends from before tracking was always on (v3.258.0) have
    // no events at all, and a send with no opens recorded is left out
    // entirely rather than reported as 0%.
    global $wpdb;
    $bt = $wpdb->prefix . 'lmeg_broadcasts';
    $bl = $wpdb->prefix . 'lmeg_broadcast_log';
    if (lmeg_plan_has_table($bt)) {
        $rows = $wpdb->get_results("SELECT id, subject, sent FROM $bt WHERE status='completed' AND sent > 0 ORDER BY id DESC LIMIT 8", ARRAY_A);
        $best = null;
        foreach ((array) $rows as $r) {
            $id = (int) $r['id'];
            $sent = max(1, (int) $r['sent']);
            $opens = 0; $clicks = 0;
            if (lmeg_plan_has_table($bl)) {
                $l = $wpdb->get_row($wpdb->prepare(
                    "SELECT SUM(opened_at IS NOT NULL) o, SUM(first_clicked_at IS NOT NULL) c FROM $bl WHERE broadcast_id = %d", $id), ARRAY_A);
                $opens = (int) ($l['o'] ?? 0); $clicks = (int) ($l['c'] ?? 0);
            }
            if (function_exists('lmeg_email_engagement')) {
                $e = (array) lmeg_email_engagement('broadcast', $id);
                $opens = max($opens, (int) ($e['opens'] ?? 0));
                $clicks = max($clicks, (int) ($e['clicks'] ?? 0));
            }
            if ($opens < 1) continue;
            $open = round($opens / $sent * 100, 1);
            if ($best === null || $open > $best['open_rate']) {
                $best = ['subject' => (string) $r['subject'], 'open_rate' => $open, 'click_rate' => round($clicks / $sent * 100, 1), 'sent' => $sent];
            }
        }
        $ctx['sends']['best'] = $best;
        $ctx['sends']['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $bt WHERE status='completed'");
    }

    // Releases + drops: the next dated one, and how long since the last.
    $next = null; $lastrel = null;
    if (function_exists('lmeg_drops_all')) {
        foreach ((array) lmeg_drops_all() as $d) {
            $when = !empty($d->release_at) ? substr((string) $d->release_at, 0, 10) : '';
            if (!$when) continue;
            if ($when >= $today && (string) $d->status !== 'released') {
                if ($next === null || $when < $next['date']) $next = ['date' => $when, 'title' => (string) $d->title, 'kind' => 'drop', 'page' => 'lmeg-drops', 'id' => (int) $d->id];
            }
            if ($when < $today && ($lastrel === null || $when > $lastrel)) $lastrel = $when;
        }
    }
    if (function_exists('lmeg_releases_all')) {
        foreach ((array) lmeg_releases_all() as $r) {
            $when = !empty($r->release_date) ? substr((string) $r->release_date, 0, 10) : '';
            if (!$when) continue;
            if ($when >= $today) {
                if ($next === null || $when < $next['date']) $next = ['date' => $when, 'title' => (string) ($r->title ?? ''), 'kind' => 'release', 'page' => 'lmeg-releases', 'id' => (int) $r->id];
            } elseif ($lastrel === null || $when > $lastrel) {
                $lastrel = $when;
            }
        }
    }
    $ctx['release'] = [
        'next'       => $next,
        'days_out'   => $next ? max(0, (int) floor((strtotime($next['date']) - strtotime($today)) / 86400)) : null,
        'last'       => $lastrel,
        'days_since' => $lastrel ? max(0, (int) floor((strtotime($today) - strtotime($lastrel)) / 86400)) : null,
        'presave'    => function_exists('lmeg_presave_active_campaigns') ? (count((array) lmeg_presave_active_campaigns()) > 0) : null,
    ];

    // Store: what is on sale, what sold, what is stuck.
    $pt = $wpdb->prefix . 'lmeg_products';
    if (lmeg_plan_has_table($pt)) {
        $ctx['store']['products'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $pt WHERE status='active'");
        $ctx['store']['lowstock'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $pt WHERE status='active' AND stock IS NOT NULL AND stock > 0 AND stock <= 3");
    }
    $pp = $wpdb->prefix . 'lmeg_product_purchases';
    if (lmeg_plan_has_table($pp)) {
        $ctx['store']['orders_30d'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $pp WHERE status='paid' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $ctx['store']['revenue_30d'] = (int) $wpdb->get_var("SELECT COALESCE(SUM(total_cents),0) FROM $pp WHERE status='paid' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    }
    $ab = $wpdb->prefix . 'lmeg_abandoned';
    if (lmeg_plan_has_table($ab)) {
        $ctx['store']['abandoned'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $ab WHERE recovered = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)");
    }

    // Social: Instagram level and 28-day movement.
    if (function_exists('lmeg_social_series_stats')) {
        $s = (array) lmeg_social_series_stats('instagram', 28);
        if ($s) $ctx['social'] = ['ig_followers' => lmeg_plan_int($s['last'] ?? null), 'ig_delta' => lmeg_plan_int($s['delta'] ?? null)];
    }

    // What automation already exists, so the plan never tells them to build it twice.
    $ctx['has']['welcome_sequence'] = false;
    if (function_exists('lmeg_all_sequences')) {
        foreach ((array) lmeg_all_sequences() as $s) if (!empty($s->is_active)) { $ctx['has']['welcome_sequence'] = true; break; }
    }
    $st2 = $wpdb->prefix . 'lmeg_contests';
    if (lmeg_plan_has_table($st2)) $ctx['has']['contest'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $st2 WHERE status='active'") > 0;
    $ct = $wpdb->prefix . 'lmeg_content_campaigns';
    if (lmeg_plan_has_table($ct)) $ctx['has']['collect'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $ct WHERE status='active'") > 0;

    return $ctx;
}

/** Sample numbers for ?demo=1, so the page can be read before any data lands. */
function lmeg_plan_context_demo($ctx) {
    $today = $ctx['today'];
    $ctx['sends'] = ['count_30d' => 1, 'last' => date('Y-m-d', strtotime($today . ' -26 days')), 'gap_days' => 26, 'avg_gap' => 24,
                     'best' => ['subject' => 'the one about the dam', 'open_rate' => 47.2, 'click_rate' => 9.1, 'sent' => 312], 'total' => 11];
    $ctx['release'] = ['next' => ['date' => date('Y-m-d', strtotime($today . ' +24 days')), 'title' => 'Sometimes', 'kind' => 'drop', 'page' => 'lmeg-drops', 'id' => 0],
                       'days_out' => 24, 'last' => date('Y-m-d', strtotime($today . ' -96 days')), 'days_since' => 96, 'presave' => false];
    $ctx['store'] = ['products' => 2, 'orders_30d' => 3, 'revenue_30d' => 10500, 'abandoned' => 2, 'lowstock' => 1, 'tiers' => 0];
    $ctx['social'] = ['ig_followers' => 12480, 'ig_delta' => 310];
    $ctx['has'] = ['welcome_sequence' => false, 'contest' => false, 'collect' => false];
    return $ctx;
}

function lmeg_plan_int($v) { return ($v === null || $v === '') ? null : (int) $v; }

function lmeg_plan_has_table($t) {
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t));
}

/* ---------------------------------------------------------------------------
 * Rules — pure. Context in, scored candidate moves out.
 *
 * A move never states a number the context didn't supply, and every rule that
 * can't be evidenced simply doesn't fire. Scores are coarse on purpose (a
 * release week beats catalogue upkeep beats evergreen), and lmeg_plan_schedule()
 * does the spreading.
 * ------------------------------------------------------------------------- */

function lmeg_plan_rules($ctx) {
    $today   = (string) ($ctx['today'] ?? date('Y-m-d'));
    $list    = (array) ($ctx['list'] ?? []);
    $streams = (array) ($ctx['streams'] ?? []);
    $songs   = (array) ($ctx['songs'] ?? []);
    $sends   = (array) ($ctx['sends'] ?? []);
    $rel     = (array) ($ctx['release'] ?? []);
    $store   = (array) ($ctx['store'] ?? []);
    $geo     = (array) ($ctx['geo'] ?? []);
    $social  = (array) ($ctx['social'] ?? []);
    $has     = (array) ($ctx['has'] ?? []);
    $stage   = (array) ($ctx['stage'] ?? []);
    $n       = function ($v) { return number_format_i18n((int) $v); };
    $out     = [];

    $add = function ($m) use (&$out) { $out[] = $m; };
    $compose = function ($angle, $label, $group = null, $extra = []) {
        $a = array_merge(['prefill' => 'insight', 'angle' => $angle], $extra);
        if ($group) $a['group'] = $group;
        return ['label' => $label, 'page' => 'lmeg-compose', 'args' => $a];
    };
    $go = function ($page, $label) { return ['label' => $label, 'page' => $page, 'args' => []]; };

    /* --- 1. The release rollout, anchored to the real date ---------------- */
    $days_out = $rel['days_out'] ?? null;
    if (!empty($rel['next']) && $days_out !== null && $days_out <= 45) {
        $rd    = (string) $rel['next']['date'];
        $title = trim((string) ($rel['next']['title'] ?? '')) ?: 'the release';
        $page  = (string) ($rel['next']['page'] ?? 'lmeg-drops');
        $steps = [
            ['key' => 'roll-presave',  'at' => 28, 'title' => 'Put the pre-save up for ' . $title,      'channel' => 'release', 'effort' => 'medium', 'metric' => 'pre-saves collected before release day', 'action' => $go('lmeg-presaves', 'Set up the pre-save'), 'score' => 96],
            ['key' => 'roll-announce', 'at' => 21, 'title' => 'Announce ' . $title . ' to your list',   'channel' => 'email',   'effort' => 'quick',  'metric' => 'opens and pre-saves from the list',      'action' => $compose('release', 'Draft the announce'), 'score' => 94],
            ['key' => 'roll-teaser',   'at' => 14, 'title' => 'Give superfans the first listen',         'channel' => 'email',   'effort' => 'quick',  'metric' => 'clicks from superfans',                   'action' => $compose('release', 'Draft the first-listen note', 'superfans'), 'score' => 92],
            ['key' => 'roll-count',    'at' => 7,  'title' => 'Open the countdown page',                 'channel' => 'release', 'effort' => 'quick',  'metric' => 'notify sign-ups on the drop page',        'action' => $go($page, 'Open the drop'), 'score' => 90],
            ['key' => 'roll-eve',      'at' => 1,  'title' => 'Send the night-before note',              'channel' => 'email',   'effort' => 'quick',  'metric' => 'release-day first-hour streams',          'action' => $compose('release', 'Draft the eve note'), 'score' => 95],
            ['key' => 'roll-day',      'at' => 0,  'title' => 'Release day: send it to everyone',        'channel' => 'email',   'effort' => 'quick',  'metric' => 'day-one streams and saves',               'action' => $compose('release', 'Draft release day'), 'score' => 99],
            ['key' => 'roll-save',     'at' => -3, 'title' => 'Ask the people who listened to save it',  'channel' => 'email',   'effort' => 'quick',  'metric' => 'save rate on the new song',               'action' => $compose('save', 'Draft the save ask', 'active'), 'score' => 88],
            ['key' => 'roll-focus',    'at' => -10,'title' => 'Push the track that took hold',           'channel' => 'social',  'effort' => 'medium','metric' => 'week-two streams instead of a week-one spike', 'action' => $go('lmeg-instagram', 'Post about it'), 'score' => 84],
        ];
        foreach ($steps as $s) {
            $due = date('Y-m-d', strtotime($rd . ' -' . (int) $s['at'] . ' days'));
            $late = false;
            if ($due < $today) {
                // The window has passed but the release hasn't: still worth doing,
                // so it moves to today and says so rather than disappearing.
                if ((int) $s['at'] <= 0) continue;
                $due = $today; $late = true;
            }
            $add([
                'key' => $s['key'], 'title' => $s['title'], 'channel' => $s['channel'], 'effort' => $s['effort'],
                'metric' => $s['metric'], 'score' => (int) $s['score'] + ($late ? 3 : 0), 'due' => $due, 'late' => $late,
                'why' => $title . ' lands ' . lmeg_plan_when($rd, $today) . ($late ? ' — this step was due ' . lmeg_plan_when(date('Y-m-d', strtotime($rd . ' -' . (int) $s['at'] . ' days')), $today) : ''),
                'action' => $s['action'], 'anchor' => true,
            ]);
        }
    }

    /* --- 2. No release in sight ------------------------------------------ */
    if (empty($rel['next']) && ($rel['days_since'] ?? null) !== null && (int) $rel['days_since'] >= 60) {
        $add(['key' => 'book-release', 'title' => 'Put a date on the next release', 'channel' => 'release', 'effort' => 'deep', 'offset' => 2, 'score' => 86,
              'why' => (int) $rel['days_since'] . ' days since your last release, and nothing dated yet. The rollout above only writes itself once a date exists.',
              'metric' => 'a dated release with a pre-save behind it', 'action' => $go('lmeg-releases', 'Plan the release')]);
    }

    /* --- 3. Send rhythm -------------------------------------------------- */
    $gap = $sends['gap_days'] ?? null;
    if ($gap !== null && $gap >= 21 && empty($rel['next'])) {
        $add(['key' => 'send-checkin', 'title' => 'Send a check-in to your list', 'channel' => 'email', 'effort' => 'quick', 'offset' => 1, 'score' => 82,
              'why' => $gap . ' days since your last send' . (($list['total'] ?? null) ? ', to ' . $n($list['total']) . ' people who asked to hear from you' : '') . '. A list cools quietly.',
              'metric' => 'open rate holding above your average', 'action' => $compose('checkin', 'Draft the check-in')]);
    } elseif ($gap !== null && $gap >= 45) {
        $add(['key' => 'send-checkin', 'title' => 'Send something before release week', 'channel' => 'email', 'effort' => 'quick', 'offset' => 1, 'score' => 80,
              'why' => $gap . ' days of silence means release day arrives cold. One warm send first lifts the one that matters.',
              'metric' => 'open rate on the release-day send', 'action' => $compose('checkin', 'Draft the check-in')]);
    }

    /* --- 4. The catalogue: what is moving on its own --------------------- */
    if (!empty($songs['up'][0])) {
        $s = $songs['up'][0];
        $add(['key' => 'song-mover', 'title' => 'Put weight behind "' . $s['title'] . '"', 'channel' => 'email', 'effort' => 'quick', 'offset' => 3, 'score' => 78,
              'why' => '"' . $s['title'] . '" is up ' . (float) $s['wow'] . '% week over week (' . $n($s['last7']) . ' streams in 7 days). Something is already working; this is the cheapest week to add to it.',
              'metric' => 'a second week of growth rather than a spike', 'action' => $compose('mover', 'Draft it', null, ['song' => $s['title'], 'uri' => (string) ($s['uri'] ?? '')])]);
    }
    if (!empty($songs['down'][0]) && empty($rel['next'])) {
        $s = $songs['down'][0];
        $add(['key' => 'song-cooling', 'title' => 'Give "' . $s['title'] . '" a second life', 'channel' => 'email', 'effort' => 'quick', 'offset' => 10, 'score' => 62,
              'why' => '"' . $s['title'] . '" cooled ' . abs((float) $s['wow']) . '% this week (' . $n($s['last7']) . ' streams, from ' . $n($s['prior7']) . '). A re-push to people who already clicked it beats starting cold.',
              'metric' => 'streams flattening out instead of sliding', 'action' => $compose('repush', 'Draft the re-push', 'active', ['song' => $s['title'], 'uri' => (string) ($s['uri'] ?? '')])]);
    }

    /* --- 5. The list: who is cooling, who never warmed up ---------------- */
    $total = $list['total'] ?? null;
    if ($total && !empty($list['atrisk']) && $list['atrisk'] >= max(5, (int) round($total * 0.05))) {
        $add(['key' => 'winback', 'title' => 'Win back the ' . $n($list['atrisk']) . ' going quiet', 'channel' => 'email', 'effort' => 'quick', 'offset' => 6, 'score' => 74,
              'why' => $n($list['atrisk']) . ' fans engaged before and have gone 60+ days without opening, clicking or visiting — ' . lmeg_plan_pct($list['atrisk'], $total) . ' of your list.',
              'metric' => 'how many of them open this one', 'action' => $compose('listen', 'Draft the win-back', 'atrisk')]);
    }
    if ($total && !empty($list['dormant']) && $list['dormant'] >= max(10, (int) round($total * 0.2))) {
        $add(['key' => 'dormant-first-value', 'title' => 'Give the never-engaged a reason', 'channel' => 'email', 'effort' => 'medium', 'offset' => 13, 'score' => 58,
              'why' => $n($list['dormant']) . ' people (' . lmeg_plan_pct($list['dormant'], $total) . ' of the list) have never opened, clicked or visited. Most joined for one thing and never got it.',
              'metric' => 'first opens from people who have never opened', 'action' => $compose('listen', 'Draft the reintroduction')]);
    }
    if (!empty($list['new']) && $list['new'] >= 10 && empty($has['welcome_sequence'])) {
        $add(['key' => 'welcome-seq', 'title' => 'Build the welcome sequence once', 'channel' => 'admin', 'effort' => 'deep', 'offset' => 4, 'score' => 76,
              'why' => $n($list['new']) . ' fans joined in the last 30 days and nothing greets them. This is the one job that keeps paying without you.',
              'metric' => 'every new fan hearing from you in week one', 'action' => $go('lmeg-sequences', 'Build the sequence')]);
    }
    if (!empty($list['bounced']) && $list['bounced'] >= 5) {
        $add(['key' => 'clean-bounces', 'title' => 'Clear ' . $n($list['bounced']) . ' bouncing addresses', 'channel' => 'admin', 'effort' => 'quick', 'offset' => 5, 'score' => 54,
              'why' => $n($list['bounced']) . ' addresses bounce every time you send, which is what drags the rest of your mail toward spam folders.',
              'metric' => 'delivery rate on the next send', 'action' => $go('lmeg-deliverability', 'Open deliverability')]);
    }

    /* --- 6. Listeners who aren't yours yet ------------------------------- */
    $lpct = $stage['ratios']['list_pct'] ?? null;
    $lis  = $streams['listeners'] ?? null;
    if ($lis && $lis >= 500 && $lpct !== null && $lpct < 1) {
        $add(['key' => 'list-drive', 'title' => 'Turn listeners into a list you own', 'channel' => 'social', 'effort' => 'medium', 'offset' => 8, 'score' => 80,
              'why' => $n($lis) . ' monthly listeners and ' . ($total ? $n($total) : '0') . ' on your list — ' . rtrim(rtrim(number_format((float) $lpct, 2), '0'), '.') . '%. Streaming platforms rent you that audience; the list is the part you keep.',
              'metric' => 'sign-ups per week from the link in your bio', 'action' => $go('lmeg-signups', 'Get your sign-up link')]);
    }
    $sr = $streams['save_rate'] ?? null;
    if ($sr !== null && $sr < 3 && $lis && $lis >= 500) {
        $add(['key' => 'save-ask', 'title' => 'Ask your list to save the music', 'channel' => 'email', 'effort' => 'quick', 'offset' => 9, 'score' => 66,
              'why' => 'Your save rate is ' . rtrim(rtrim(number_format((float) $sr, 2), '0'), '.') . '% of monthly listeners. Saves are what put you back in someone\'s rotation without a post.',
              'metric' => 'saves per 1,000 listeners', 'action' => $compose('save', 'Draft the save ask', 'active')]);
    }

    /* --- 7. Geography: where they already are ---------------------------- */
    if (!empty($geo['top_city']['name'])) {
        $city = (string) $geo['top_city']['name'];
        $add(['key' => 'city-play', 'title' => 'Do something real in ' . $city, 'channel' => 'social', 'effort' => 'deep', 'offset' => 16, 'score' => 56,
              'why' => $city . ' is your biggest city by streams' . (!empty($geo['top_city']['streams']) ? ' (' . $n($geo['top_city']['streams']) . ' in 28 days)' : '') . '. A show, an in-store, a local feature — density is what makes those work.',
              'metric' => 'sign-ups and sales from that city', 'action' => $go('lmeg-store-shows', 'Add a show')]);
    }
    if (!empty($geo['riser']['cc']) && !empty($geo['riser']['pct']) && (float) $geo['riser']['pct'] >= 15) {
        $cc = (string) $geo['riser']['cc'];
        $name = function_exists('lmeg_si_country_name') ? lmeg_si_country_name($cc) : $cc;
        $add(['key' => 'riser-market', 'title' => 'Follow the growth in ' . $name, 'channel' => 'email', 'effort' => 'quick', 'offset' => 12, 'score' => 60,
              'why' => $name . ' is up ' . round((float) $geo['riser']['pct']) . '% on streams. New markets are cheapest to serve while they are still moving on their own.',
              'metric' => 'streams and follows from that market', 'action' => $compose('listen', 'Email fans there', null, ['country' => $cc])]);
    }

    /* --- 8. The store ---------------------------------------------------- */
    $prods = $store['products'] ?? null;
    if ($prods !== null && $prods === 0 && $total && $total >= 50) {
        $add(['key' => 'store-first', 'title' => 'Put one thing in the store', 'channel' => 'store', 'effort' => 'medium', 'offset' => 11, 'score' => 64,
              'why' => $n($total) . ' people on your list and nothing to buy. One item — a shirt, a download, a signed thing — turns a list into a living.',
              'metric' => 'first orders in a week', 'action' => $go('lmeg-products', 'Add a product')]);
    } elseif ($prods && empty($store['orders_30d'])) {
        $add(['key' => 'store-tell', 'title' => 'Tell your list what is in the store', 'channel' => 'email', 'effort' => 'quick', 'offset' => 14, 'score' => 57,
              'why' => $prods . ' active ' . ($prods === 1 ? 'product' : 'products') . ' and no orders in 30 days. Most fans have never seen the store page.',
              'metric' => 'orders and revenue in the week after', 'action' => $compose('lift', 'Draft the store note', 'active')]);
    }
    if (!empty($store['abandoned'])) {
        $add(['key' => 'carts', 'title' => 'Chase ' . $n($store['abandoned']) . ' unfinished ' . ((int) $store['abandoned'] === 1 ? 'cart' : 'carts'), 'channel' => 'store', 'effort' => 'quick', 'offset' => 2, 'score' => 70,
              'why' => $n($store['abandoned']) . ' ' . ((int) $store['abandoned'] === 1 ? 'person' : 'people') . ' put something in the cart in the last two weeks and left. They are the warmest buyers you have.',
              'metric' => 'carts recovered', 'action' => $go('lmeg-orders', 'Open orders')]);
    }
    if (!empty($store['lowstock'])) {
        $add(['key' => 'lowstock', 'title' => 'Decide on the low-stock items', 'channel' => 'store', 'effort' => 'quick', 'offset' => 7, 'score' => 50,
              'why' => $store['lowstock'] . ' active ' . ((int) $store['lowstock'] === 1 ? 'item has' : 'items have') . ' 3 or fewer left. Restock it or say "last few" out loud — both beat selling out silently.',
              'metric' => 'no dead product pages', 'action' => $go('lmeg-store-stock', 'Check stock')]);
    }
    if (!empty($store['tiers']) && !empty($list['superfans']) && $list['superfans'] >= 20 && (int) ($list['members'] ?? 0) < 10) {
        $add(['key' => 'member-invite', 'title' => 'Invite your superfans in by name', 'channel' => 'email', 'effort' => 'quick', 'offset' => 15, 'score' => 68,
              'why' => $n($list['superfans']) . ' superfans and ' . (int) ($list['members'] ?? 0) . ' paying members. The people who already buy and click are the only ones worth asking first.',
              'metric' => 'members joining from that one send', 'action' => $compose('lift', 'Draft the invite', 'superfans')]);
    }

    /* --- 9. Social ------------------------------------------------------- */
    $sp = $streams['streams_pct'] ?? null;
    if ($sp !== null && $sp > 5 && isset($social['ig_delta']) && (int) $social['ig_delta'] <= 0) {
        $add(['key' => 'social-catchup', 'title' => 'Say out loud what the streams already show', 'channel' => 'social', 'effort' => 'quick', 'offset' => 4, 'score' => 63,
              'why' => 'Streams are up ' . round((float) $sp) . '% while Instagram followers went ' . ((int) $social['ig_delta'] === 0 ? 'flat' : 'down') . ' over 28 days. People are listening without knowing where to follow you.',
              'metric' => 'followers per week catching up to streams', 'action' => $go('lmeg-instagram', 'Open Instagram')]);
    }
    if (!empty($list['superfans']) && $list['superfans'] >= 10 && empty($has['collect'])) {
        $add(['key' => 'collect-ugc', 'title' => 'Ask fans for something you can post', 'channel' => 'social', 'effort' => 'medium', 'offset' => 18, 'score' => 46,
              'why' => $n($list['superfans']) . ' superfans will answer a direct ask. Their clips and photos are a month of posts you do not have to invent.',
              'metric' => 'submissions you would actually post', 'action' => $go('lmeg-collect', 'Open a collection')]);
    }

    /* --- 10. Evergreen, so a quiet week is still a planned week ---------- */
    $add(['key' => 'evergreen-post', 'title' => 'Post one thing from your world', 'channel' => 'social', 'effort' => 'quick', 'offset' => 5, 'score' => 20,
          'why' => 'Not everything needs a campaign behind it. One honest post a week is what keeps the room warm between releases.',
          'metric' => 'staying in feeds between releases', 'action' => $go('lmeg-instagram', 'Open Instagram'), 'cadence' => 'weekly']);
    $add(['key' => 'evergreen-reply', 'title' => 'Answer every DM and comment', 'channel' => 'social', 'effort' => 'quick', 'offset' => 6, 'score' => 18,
          'why' => 'Replies are the cheapest loyalty there is, and Fanloop captures the people you talk to as fans.',
          'metric' => 'DM replies turning into list sign-ups', 'action' => $go('lmeg-instagram', 'Open the inbox'), 'cadence' => 'weekly']);

    return $out;
}

/** "in 24 days" / "today" / "26 days ago" — for a why-line. Pure. */
function lmeg_plan_when($date, $today) {
    $d = (int) round((strtotime((string) $date) - strtotime((string) $today)) / 86400);
    if ($d === 0)  return 'today';
    if ($d === 1)  return 'tomorrow';
    if ($d === -1) return 'yesterday';
    return $d > 0 ? 'in ' . $d . ' days' : abs($d) . ' days ago';
}

/** "12%" / "<1%" of a total. Pure. */
function lmeg_plan_pct($part, $total) {
    if (!$total) return '';
    $p = (float) $part / (float) $total * 100;
    if ($p > 0 && $p < 1) return '<1%';
    return round($p) . '%';
}

/* ---------------------------------------------------------------------------
 * Scheduling — pure. Candidates in, a dated four-week plan out.
 *
 * Anchored release steps hold their date; everything else gets pushed to the
 * next week that has room. Caps exist so the plan can't quietly become "email
 * your list four times this week", which is how lists die.
 * ------------------------------------------------------------------------- */

function lmeg_plan_schedule($moves, $today, $weeks = 4, $per_week = 4, $emails_per_week = 2) {
    $t0 = strtotime((string) $today);
    if (!$t0) return [];
    $first_monday = lmeg_plan_week_start((string) $today);
    $last_day     = date('Y-m-d', strtotime($first_monday . ' +' . ((int) $weeks * 7 - 1) . ' days'));

    // Weekly-cadence moves become one copy per week; everything else is deduped
    // to its best-scoring instance.
    $expanded = []; $seen = [];
    foreach ((array) $moves as $m) {
        if (($m['cadence'] ?? '') === 'weekly') {
            for ($w = 0; $w < (int) $weeks; $w++) {
                $c = $m;
                $c['key'] = $m['key'] . '-w' . $w;
                $c['due'] = date('Y-m-d', strtotime($first_monday . ' +' . ($w * 7 + (int) ($m['offset'] ?? 3)) . ' days'));
                // Filler: it takes a spare slot in its own week and is dropped
                // if that week is already full, rather than pushing real work
                // into next week or piling up at the end of the month.
                $c['filler'] = true;
                unset($c['offset']);
                $expanded[] = $c;
            }
            continue;
        }
        $k = (string) $m['key'];
        if (isset($seen[$k])) {
            if ((int) $m['score'] > (int) $expanded[$seen[$k]]['score']) $expanded[$seen[$k]] = $m;
            continue;
        }
        $seen[$k] = count($expanded);
        $expanded[] = $m;
    }

    foreach ($expanded as &$m) {
        if (empty($m['due'])) $m['due'] = date('Y-m-d', strtotime((string) $today . ' +' . (int) ($m['offset'] ?? 0) . ' days'));
        if ($m['due'] < (string) $today) $m['due'] = (string) $today;
    }
    unset($m);

    usort($expanded, function ($a, $b) {
        if ((int) $a['score'] !== (int) $b['score']) return (int) $b['score'] <=> (int) $a['score'];
        return strcmp((string) $a['due'], (string) $b['due']);
    });

    // Anchored moves claim their slots first so a release date is never displaced.
    $placed = []; $count = []; $emails = [];
    $take = function ($m, $due) use (&$placed, &$count, &$emails) {
        $ws = lmeg_plan_week_start($due);
        $m['due'] = $due;
        $m['week_start'] = $ws;
        $placed[] = $m;
        $count[$ws] = (int) ($count[$ws] ?? 0) + 1;
        if (($m['channel'] ?? '') === 'email') $emails[$ws] = (int) ($emails[$ws] ?? 0) + 1;
    };

    foreach ($expanded as $m) if (!empty($m['anchor'])) { if ($m['due'] <= $last_day) $take($m, $m['due']); }

    foreach ($expanded as $m) {
        if (!empty($m['anchor'])) continue;
        $due = (string) $m['due'];
        $tries = !empty($m['filler']) ? 1 : (int) $weeks + 1;
        for ($i = 0; $i < $tries; $i++) {
            if ($due > $last_day) break;
            $ws = lmeg_plan_week_start($due);
            $room  = (int) ($count[$ws] ?? 0) < (int) $per_week;
            $email_room = ($m['channel'] ?? '') !== 'email' || (int) ($emails[$ws] ?? 0) < (int) $emails_per_week;
            if ($room && $email_room) { $take($m, $due); break; }
            $due = date('Y-m-d', strtotime($due . ' +7 days'));
        }
    }

    usort($placed, function ($a, $b) {
        if ((string) $a['due'] !== (string) $b['due']) return strcmp((string) $a['due'], (string) $b['due']);
        return (int) $b['score'] <=> (int) $a['score'];
    });
    return $placed;
}

/** Monday of the ISO week containing $date. Pure. */
function lmeg_plan_week_start($date) {
    $ts = strtotime((string) $date);
    if (!$ts) return (string) $date;
    $dow = (int) date('N', $ts); // 1 = Monday
    return date('Y-m-d', $ts - ($dow - 1) * 86400);
}

/* ---------------------------------------------------------------------------
 * Catalogue — every song gets a job this cycle, from its own daily streams.
 * ------------------------------------------------------------------------- */

function lmeg_plan_catalog_roles($songs, $limit = 8) {
    $rows = [];
    foreach ((array) $songs as $e) {
        if (!is_array($e) || empty($e['s'])) continue;
        $vals = array_values(array_map('intval', (array) $e['s']));
        if (!$vals) continue;
        $last7  = array_sum(array_slice($vals, -7));
        $prior7 = count($vals) >= 14 ? array_sum(array_slice($vals, -14, 7)) : null;
        $wow    = ($prior7 !== null && $prior7 > 0) ? round(($last7 - $prior7) / $prior7 * 100, 1) : null;
        $rows[] = ['title' => (string) ($e['t'] ?? ''), 'uri' => (string) ($e['u'] ?? ''), 'last7' => $last7, 'prior7' => $prior7, 'wow' => $wow, 'series' => $vals];
    }
    if (!$rows) return [];
    usort($rows, function ($a, $b) { return (int) $b['last7'] <=> (int) $a['last7']; });
    $lead = (int) $rows[0]['last7'];
    $out = [];
    foreach (array_slice($rows, 0, (int) $limit) as $i => $r) {
        $share = $lead > 0 ? $r['last7'] / $lead : 0;
        if ($i === 0) {
            $role = 'Focus track'; $job = 'Everything points here. Links, bio, pinned post.';
        } elseif ($r['wow'] !== null && $r['wow'] >= 15) {
            $role = 'Rising'; $job = 'Add fuel while it moves on its own — one send, one post.';
        } elseif ($r['wow'] !== null && $r['wow'] <= -15) {
            $role = 'Cooling'; $job = 'Re-push to fans who already clicked it, or rest it.';
        } elseif ($share < 0.05) {
            $role = 'Sleeper'; $job = 'Most of your audience has never heard it. Worth one reintroduction.';
        } else {
            $role = 'Steady'; $job = 'Leave it working. It earns without attention.';
        }
        $r['role'] = $role; $r['job'] = $job; $r['share'] = round($share * 100, 1);
        $out[] = $r;
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * Pillars — what to make more of, each one earned by a number.
 * ------------------------------------------------------------------------- */

function lmeg_plan_pillars($ctx) {
    $out = [];
    $songs = (array) ($ctx['songs'] ?? []);
    $geo   = (array) ($ctx['geo'] ?? []);
    $sends = (array) ($ctx['sends'] ?? []);
    $rel   = (array) ($ctx['release'] ?? []);
    $roles = lmeg_plan_catalog_roles((array) ($ctx['map'] ?? []));

    if (!empty($rel['next']['title']) && ($rel['days_out'] ?? 99) <= 45) {
        $out[] = ['label' => 'Making of ' . $rel['next']['title'], 'why' => 'It lands ' . lmeg_plan_when((string) $rel['next']['date'], (string) $ctx['today']) . '. Process posts are the only content that gets better the closer the date gets.',
                  'ideas' => ['the room it was written in', 'a verse that got cut', 'the moment it clicked']];
    }
    if (!empty($songs['up'][0])) {
        $s = $songs['up'][0];
        $out[] = ['label' => '"' . $s['title'] . '", while it moves', 'why' => 'Up ' . (float) $s['wow'] . '% week over week without a push behind it.',
                  'ideas' => ['play the part people quote', 'what the song is actually about', 'a fan\'s clip of it']];
    }
    if (!empty($roles)) {
        foreach ($roles as $r) {
            if ($r['role'] !== 'Sleeper') continue;
            $out[] = ['label' => 'The catalogue cut: "' . $r['title'] . '"', 'why' => 'Only ' . $r['share'] . '% of your focus track\'s weekly streams — most of your audience has never heard it.',
                      'ideas' => ['"if you found me through the new one, start here"', 'the story behind it', 'a live version']];
            break;
        }
    }
    if (!empty($geo['top_city']['name'])) {
        $out[] = ['label' => $geo['top_city']['name'] . ' and the map', 'why' => 'Your densest city by streams. Naming places makes the people in them feel found.',
                  'ideas' => ['shout the city by name', 'ask where to play next', 'a local landmark in a shot']];
    }
    if (!empty($sends['best']['subject']) && !empty($sends['best']['open_rate'])) {
        $b = $sends['best'];
        $out[] = ['label' => 'More like "' . $b['subject'] . '"', 'why' => 'Your best-opened send at ' . $b['open_rate'] . '% open' . ($b['click_rate'] ? ' and ' . $b['click_rate'] . '% click' : '') . '. Whatever that tone was, it is the one they answer.',
                  'ideas' => ['same voice, new week', 'reply-bait: ask them one question', 'the thing you almost didn\'t say']];
    }
    return array_slice($out, 0, 5);
}

/* ---------------------------------------------------------------------------
 * Persistence — generate keeps what the artist already decided.
 *
 * A regeneration refreshes wording and dates, drops future moves that no
 * longer have evidence, and never touches a row that was marked done or
 * skipped, or anything in the past. The plan is a record of what was
 * recommended, not just what is recommended now.
 * ------------------------------------------------------------------------- */

function lmeg_plan_generate($weeks = 4) {
    global $wpdb;
    lmeg_plan_maybe_install();
    $t = lmeg_plan_table();
    $ctx   = lmeg_plan_context(false);
    $moves = lmeg_plan_schedule(lmeg_plan_rules($ctx), (string) $ctx['today'], (int) $weeks);
    $now   = current_time('mysql');
    $today = (string) $ctx['today'];
    $keep  = [];

    foreach ($moves as $m) {
        $a = (array) ($m['action'] ?? []);
        $row = [
            'mkey'         => substr((string) $m['key'], 0, 80),
            'due_date'     => (string) $m['due'],
            'week_start'   => (string) $m['week_start'],
            'title'        => substr((string) $m['title'], 0, 190),
            'why'          => (string) ($m['why'] ?? ''),
            'channel'      => substr((string) ($m['channel'] ?? ''), 0, 20),
            'effort'       => substr((string) ($m['effort'] ?? ''), 0, 10),
            'metric'       => substr((string) ($m['metric'] ?? ''), 0, 160),
            'score'        => (int) ($m['score'] ?? 0),
            'action_label' => substr((string) ($a['label'] ?? ''), 0, 90),
            'action_page'  => substr((string) ($a['page'] ?? ''), 0, 60),
            'action_args'  => !empty($a['args']) ? (string) wp_json_encode($a['args']) : '',
            'action_href'  => substr((string) ($a['href'] ?? ''), 0, 600),
            'plan_run'     => $today,
            'updated_at'   => $now,
        ];
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM $t WHERE mkey = %s AND due_date = %s", $row['mkey'], $row['due_date']));
        if ($existing) {
            $wpdb->update($t, $row, ['id' => (int) $existing->id]);
            $keep[] = (int) $existing->id;
        } else {
            $row['status'] = 'todo';
            $row['created_at'] = $now;
            $wpdb->insert($t, $row);
            if ($wpdb->insert_id) $keep[] = (int) $wpdb->insert_id;
        }
    }

    // Retire future to-dos that this run no longer recommends.
    if ($keep) {
        $in = implode(',', array_map('intval', $keep));
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE status = 'todo' AND due_date >= %s AND id NOT IN ($in)", $today));
    } else {
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE status = 'todo' AND due_date >= %s", $today));
    }
    update_option('lmeg_plan_generated', ['at' => time(), 'run' => $today, 'moves' => count($moves), 'captured' => (string) ($ctx['streams']['captured'] ?? '')], false);
    return count($moves);
}

/** Rows between two dates (inclusive), oldest first. */
function lmeg_plan_moves($from, $to, $include_done = true) {
    global $wpdb;
    $t = lmeg_plan_table();
    if (!lmeg_plan_has_table($t)) return [];
    $sql = "SELECT * FROM $t WHERE due_date BETWEEN %s AND %s";
    if (!$include_done) $sql .= " AND status = 'todo'";
    $sql .= ' ORDER BY due_date ASC, score DESC';
    return (array) $wpdb->get_results($wpdb->prepare($sql, (string) $from, (string) $to));
}

/** The plan grouped by week: [week_start => ['start','end','moves'[]]]. */
function lmeg_plan_weeks($weeks = 4, $today = null) {
    $today = $today ?: current_time('Y-m-d');
    $start = lmeg_plan_week_start($today);
    $end   = date('Y-m-d', strtotime($start . ' +' . ((int) $weeks * 7 - 1) . ' days'));
    $rows  = lmeg_plan_moves($start, $end);
    $out = [];
    for ($w = 0; $w < (int) $weeks; $w++) {
        $ws = date('Y-m-d', strtotime($start . ' +' . ($w * 7) . ' days'));
        $out[$ws] = ['start' => $ws, 'end' => date('Y-m-d', strtotime($ws . ' +6 days')), 'moves' => []];
    }
    foreach ($rows as $r) {
        $ws = lmeg_plan_week_start((string) $r->due_date);
        if (isset($out[$ws])) $out[$ws]['moves'][] = $r;
    }
    return $out;
}

/** done / skipped / todo. Returns true when a row changed. */
function lmeg_plan_set_status($id, $status) {
    global $wpdb;
    $status = in_array($status, ['todo', 'done', 'skipped'], true) ? $status : 'todo';
    return (bool) $wpdb->update(lmeg_plan_table(), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => (int) $id]);
}

/** Moves done vs. recommended over a window — the plan's own scoreboard. */
function lmeg_plan_progress($days = 28) {
    global $wpdb;
    $t = lmeg_plan_table();
    if (!lmeg_plan_has_table($t)) return ['done' => 0, 'skipped' => 0, 'todo' => 0, 'past_due' => 0];
    $today = current_time('Y-m-d');
    $from  = date('Y-m-d', strtotime($today . ' -' . (int) $days . ' days'));
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT SUM(status='done') d, SUM(status='skipped') s, SUM(status='todo') t,
                SUM(status='todo' AND due_date < %s) p
         FROM $t WHERE due_date >= %s", $today, $from), ARRAY_A);
    return ['done' => (int) ($row['d'] ?? 0), 'skipped' => (int) ($row['s'] ?? 0), 'todo' => (int) ($row['t'] ?? 0), 'past_due' => (int) ($row['p'] ?? 0)];
}

/** Resolve a stored move's action to an href. */
function lmeg_plan_move_href($r) {
    if (!empty($r->action_href)) return (string) $r->action_href;
    if (empty($r->action_page)) return '';
    $args = $r->action_args ? (array) json_decode((string) $r->action_args, true) : [];
    return admin_url('admin.php?' . http_build_query(array_merge(['page' => (string) $r->action_page], $args)));
}

/* ---------------------------------------------------------------------------
 * One optional paragraph of framing. Everything above works without it.
 * ------------------------------------------------------------------------- */

function lmeg_plan_ai_note($ctx, $moves, $force = false) {
    if (!function_exists('lmeg_ai_configured') || !lmeg_ai_configured()) return '';
    $week = date('o-W', strtotime((string) $ctx['today']));
    $cached = get_option('lmeg_plan_note');
    if (!$force && is_array($cached) && ($cached['week'] ?? '') === $week && !empty($cached['text'])) return (string) $cached['text'];
    $lines = [];
    foreach (array_slice((array) $moves, 0, 3) as $m) $lines[] = '- ' . $m['title'] . ' (' . $m['why'] . ')';
    if (!$lines) return '';
    $q = "Here are this week's planned marketing moves for the artist, each with the data that triggered it:\n"
       . implode("\n", $lines)
       . "\n\nIn at most 60 words, tell the artist what this week is really about and which single move matters most. "
       . "Speak plainly to them in the second person. Use only the numbers above, invent nothing, no hype, no emoji, no headings.";
    $txt = lmeg_ai_ask($q);
    if (is_wp_error($txt) || !is_string($txt) || trim($txt) === '') return '';
    $txt = wp_strip_all_tags(trim($txt));
    update_option('lmeg_plan_note', ['week' => $week, 'text' => $txt, 'at' => time()], false);
    return $txt;
}

/* ---------------------------------------------------------------------------
 * Weekly generation. Hooks the minute tick like every other job here, so a
 * lost cron event can't strand it (see lmeg_ensure_broadcast_tick).
 * Priority 72: after the ladder reading (70) and the daily brief (71), so the
 * plan is built from today's numbers.
 * ------------------------------------------------------------------------- */
add_action('lmeg_broadcast_tick', 'lmeg_plan_tick', 72);
function lmeg_plan_tick() {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    if (isset($s['plan_enabled']) && $s['plan_enabled'] !== '' && empty($s['plan_enabled'])) return;
    $now = current_time('timestamp');
    if ((int) date('N', $now) !== 1 || (int) date('G', $now) < 9) return; // Monday morning
    $week = date('o-W', $now);
    if (get_option('lmeg_plan_last') === $week) return;
    update_option('lmeg_plan_last', $week, false);
    lmeg_plan_generate();
}

/* ---------------------------------------------------------------------------
 * The page.
 * ------------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_submenu_page('lmeg', 'Plan', 'Plan', 'manage_options', 'lmeg-plan', 'lmeg_admin_plan');
}, 20);

/** Channel → human label + tone. */
function lmeg_plan_channel($k) {
    $m = [
        'email'   => ['Email',   '#D05FA2'],
        'social'  => ['Social',  '#7C6CF6'],
        'release' => ['Release', '#34D399'],
        'store'   => ['Store',   '#FBBF24'],
        'admin'   => ['Setup',   '#8B90A0'],
    ];
    return $m[(string) $k] ?? ['Move', '#8B90A0'];
}

/** One move, as a row. $live = status buttons render. */
function lmeg_plan_render_move($r, $live = true, $compact = false) {
    $t = function_exists('lmeg_si_tokens') ? lmeg_si_tokens() : ['card' => '', 'lbl' => '', 'muted' => 'color:#8B90A0;'];
    list($cl, $tone) = lmeg_plan_channel($r->channel ?? '');
    $href = lmeg_plan_move_href($r);
    $done = (string) ($r->status ?? 'todo') === 'done';
    $skip = (string) ($r->status ?? 'todo') === 'skipped';
    $late = !$done && !$skip && (string) $r->due_date < current_time('Y-m-d');
    ob_start(); ?>
    <div style="display:flex;gap:14px;align-items:flex-start;padding:<?php echo $compact ? '11px 0' : '14px 0'; ?>;border-top:1px solid rgba(255,255,255,.07);<?php echo $done || $skip ? 'opacity:.55;' : ''; ?>">
        <div style="flex:0 0 52px;text-align:center;padding-top:2px;">
            <div style="font:700 15px/1 var(--lmegA-font,inherit);color:#F4F5F7;"><?php echo esc_html(date_i18n('j', strtotime((string) $r->due_date))); ?></div>
            <div style="font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#8B90A0;margin-top:3px;"><?php echo esc_html(date_i18n('M', strtotime((string) $r->due_date))); ?></div>
        </div>
        <div style="flex:1 1 auto;min-width:0;">
            <div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;">
                <span style="font:600 14px/1.35 var(--lmegA-font,inherit);color:#F4F5F7;<?php echo $done ? 'text-decoration:line-through;' : ''; ?>"><?php echo esc_html((string) $r->title); ?></span>
                <span style="font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:<?php echo esc_attr($tone); ?>;"><?php echo esc_html($cl); ?></span>
                <?php if ($late) : ?><span style="font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#FBBF24;">Past due</span><?php endif; ?>
                <?php if ($done) : ?><span style="font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#34D399;">Done</span><?php endif; ?>
                <?php if ($skip) : ?><span style="font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#8B90A0;">Skipped</span><?php endif; ?>
            </div>
            <?php if (!empty($r->why)) : ?>
            <div style="font-size:12.5px;line-height:1.5;color:#C9CCD6;margin-top:5px;"><?php echo esc_html((string) $r->why); ?></div>
            <?php endif; ?>
            <?php if (!empty($r->metric)) : ?>
            <div style="font-size:11.5px;color:#8B90A0;margin-top:5px;">Watch: <?php echo esc_html((string) $r->metric); ?></div>
            <?php endif; ?>
        </div>
        <div style="flex:0 0 auto;display:flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end;max-width:240px;">
            <?php if ($href && !$done && !$skip) : ?>
                <a class="button button-primary" href="<?php echo esc_url($href); ?>"><?php echo esc_html((string) ($r->action_label ?: 'Open')); ?></a>
            <?php endif; ?>
            <?php if ($live) : ?>
                <?php if (!$done) : ?>
                <form method="post" style="margin:0;"><?php wp_nonce_field('lmeg_plan', 'lmeg_plan_nonce'); ?>
                    <input type="hidden" name="lmeg_action" value="done"><input type="hidden" name="move_id" value="<?php echo (int) $r->id; ?>">
                    <button class="button" title="Mark this move done">Done</button>
                </form>
                <?php endif; ?>
                <?php if (!$done && !$skip) : ?>
                <form method="post" style="margin:0;"><?php wp_nonce_field('lmeg_plan', 'lmeg_plan_nonce'); ?>
                    <input type="hidden" name="lmeg_action" value="skipped"><input type="hidden" name="move_id" value="<?php echo (int) $r->id; ?>">
                    <button class="button" title="Not this cycle">Skip</button>
                </form>
                <?php endif; ?>
                <?php if ($done || $skip) : ?>
                <form method="post" style="margin:0;"><?php wp_nonce_field('lmeg_plan', 'lmeg_plan_nonce'); ?>
                    <input type="hidden" name="lmeg_action" value="todo"><input type="hidden" name="move_id" value="<?php echo (int) $r->id; ?>">
                    <button class="button" title="Put it back on the plan">Undo</button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php return ob_get_clean();
}

/** Turn generated (unsaved) moves into row-shaped objects, for ?demo=1. */
function lmeg_plan_fake_rows($moves) {
    $out = []; $i = 0;
    foreach ((array) $moves as $m) {
        $a = (array) ($m['action'] ?? []);
        $out[] = (object) [
            'id' => --$i, 'mkey' => $m['key'], 'due_date' => $m['due'], 'week_start' => $m['week_start'],
            'title' => $m['title'], 'why' => $m['why'] ?? '', 'channel' => $m['channel'] ?? '', 'effort' => $m['effort'] ?? '',
            'metric' => $m['metric'] ?? '', 'score' => $m['score'] ?? 0, 'status' => 'todo',
            'action_label' => $a['label'] ?? '', 'action_page' => $a['page'] ?? '', 'action_args' => !empty($a['args']) ? wp_json_encode($a['args']) : '', 'action_href' => $a['href'] ?? '',
        ];
    }
    return $out;
}

function lmeg_admin_plan() {
    if (!current_user_can('manage_options')) return;
    $demo   = !empty($_GET['demo']);
    $notice = '';

    if (!$demo && isset($_POST['lmeg_plan_nonce']) && wp_verify_nonce($_POST['lmeg_plan_nonce'], 'lmeg_plan')) {
        $act = isset($_POST['lmeg_action']) ? sanitize_text_field(wp_unslash($_POST['lmeg_action'])) : '';
        if ($act === 'regen') {
            delete_option('lmeg_plan_note');
            $n = lmeg_plan_generate();
            $notice = '<div class="notice notice-success"><p>Plan rebuilt from today\'s numbers — ' . (int) $n . ' moves over the next four weeks. Anything you marked done or skipped stayed that way.</p></div>';
        } elseif (in_array($act, ['done', 'skipped', 'todo'], true)) {
            lmeg_plan_set_status((int) ($_POST['move_id'] ?? 0), $act);
            $notice = '<div class="notice notice-success"><p>' . ($act === 'done' ? 'Marked done.' : ($act === 'skipped' ? 'Skipped for this cycle.' : 'Back on the plan.')) . '</p></div>';
        }
    }

    $ctx = lmeg_plan_context($demo);
    if ($demo) {
        $moves = lmeg_plan_schedule(lmeg_plan_rules($ctx), (string) $ctx['today']);
        $rows  = lmeg_plan_fake_rows($moves);
        $weeks = [];
        for ($w = 0; $w < 4; $w++) {
            $ws = date('Y-m-d', strtotime(lmeg_plan_week_start((string) $ctx['today']) . ' +' . ($w * 7) . ' days'));
            $weeks[$ws] = ['start' => $ws, 'end' => date('Y-m-d', strtotime($ws . ' +6 days')), 'moves' => []];
        }
        foreach ($rows as $r) { $ws = lmeg_plan_week_start((string) $r->due_date); if (isset($weeks[$ws])) $weeks[$ws]['moves'][] = $r; }
        $gen = ['run' => (string) $ctx['today'], 'captured' => (string) ($ctx['streams']['captured'] ?? '')];
        $prog = ['done' => 0, 'skipped' => 0, 'todo' => count($rows), 'past_due' => 0];
    } else {
        $weeks = lmeg_plan_weeks(4);
        $have = 0; foreach ($weeks as $w) $have += count($w['moves']);
        if (!$have) { lmeg_plan_generate(); $weeks = lmeg_plan_weeks(4); }
        $gen  = (array) get_option('lmeg_plan_generated', []);
        $prog = lmeg_plan_progress(28);
    }

    $t     = function_exists('lmeg_si_tokens') ? lmeg_si_tokens() : ['card' => 'background:#161826;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px 18px;color:#F4F5F7;', 'lbl' => '', 'muted' => 'color:#8B90A0;'];
    $card  = $t['card']; $lbl = $t['lbl'];
    $ws    = array_keys($weeks);
    $this_week = $ws ? $weeks[$ws[0]] : ['moves' => [], 'start' => $ctx['today'], 'end' => $ctx['today']];
    $note  = $demo ? '' : lmeg_plan_ai_note($ctx, array_map(function ($r) { return ['title' => $r->title, 'why' => $r->why]; }, (array) $this_week['moves']));
    $roles = lmeg_plan_catalog_roles((array) ($ctx['map'] ?? []));
    $pill  = lmeg_plan_pillars($ctx);
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;">Plan
            <span style="font:400 13px/1.4 var(--lmegA-font,inherit);color:#8B90A0;">Four weeks of moves, each one earned by a number you already have.</span>
        </h1>
        <?php echo $notice; ?>
        <?php if ($demo && function_exists('lmeg_demo_banner')) echo lmeg_demo_banner('lmeg-plan'); ?>

        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0 16px;">
            <?php if (!$demo) : ?>
            <form method="post" style="margin:0;"><?php wp_nonce_field('lmeg_plan', 'lmeg_plan_nonce'); ?>
                <input type="hidden" name="lmeg_action" value="regen">
                <button class="button button-primary" title="Rebuild from today's data. Done and skipped moves are kept.">Rebuild the plan</button>
            </form>
            <?php if (function_exists('lmeg_demo_preview_button')) echo str_replace(['<p>', '</p>'], '', lmeg_demo_preview_button('lmeg-plan')); ?>
            <?php endif; ?>
            <span style="font-size:12px;color:#8B90A0;">
                <?php if (!empty($gen['run'])) : ?>Built <?php echo esc_html(date_i18n('M j', strtotime((string) $gen['run']))); ?><?php endif; ?>
                <?php if (!empty($gen['captured'])) : ?> · Spotify data from <?php echo esc_html(date_i18n('M j', strtotime((string) $gen['captured']))); ?><?php endif; ?>
                <?php if (!$demo) : ?> · <?php echo (int) $prog['done']; ?> done, <?php echo (int) $prog['todo']; ?> open<?php if (!empty($prog['past_due'])) : ?>, <?php echo (int) $prog['past_due']; ?> past due<?php endif; ?> in 28 days<?php endif; ?>
            </span>
        </div>

        <?php if ($note !== '') : ?>
        <div style="<?php echo $card; ?>background:linear-gradient(135deg,rgba(208,95,162,.16),rgba(124,108,246,.16));max-width:900px;margin:0 0 18px;">
            <div style="<?php echo $lbl; ?>margin-bottom:6px;">This week, in a sentence</div>
            <div style="font-size:13.5px;line-height:1.55;color:#F4F5F7;"><?php echo esc_html($note); ?></div>
        </div>
        <?php endif; ?>

        <div style="<?php echo $card; ?>max-width:1040px;margin-bottom:18px;">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;">
                <div style="font:700 15px/1.2 var(--lmegA-font,inherit);">This week</div>
                <div style="font-size:11.5px;color:#8B90A0;"><?php echo esc_html(date_i18n('M j', strtotime((string) $this_week['start'])) . ' – ' . date_i18n('M j', strtotime((string) $this_week['end']))); ?></div>
            </div>
            <?php if (empty($this_week['moves'])) : ?>
                <div style="font-size:13px;color:#8B90A0;padding:14px 0 2px;">Nothing scheduled this week. Rebuild the plan, or add a release date and the rollout writes itself.</div>
            <?php else : foreach ($this_week['moves'] as $r) echo lmeg_plan_render_move($r, !$demo); endif; ?>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;max-width:1040px;margin-bottom:22px;">
            <?php foreach (array_slice($ws, 1) as $k) : $w = $weeks[$k]; ?>
            <div style="<?php echo $card; ?>">
                <div style="<?php echo $lbl; ?>"><?php echo esc_html(date_i18n('M j', strtotime((string) $w['start'])) . ' – ' . date_i18n('M j', strtotime((string) $w['end']))); ?></div>
                <?php if (empty($w['moves'])) : ?>
                    <div style="font-size:12.5px;color:#8B90A0;padding:12px 0 2px;">Open week.</div>
                <?php else : foreach ($w['moves'] as $r) echo lmeg_plan_render_move($r, !$demo, true); endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($pill) : ?>
        <h2 style="margin:6px 0 10px;font-size:16px;">What to make more of</h2>
        <p style="font-size:12.5px;color:#8B90A0;margin:0 0 12px;max-width:760px;">Content directions taken from what already performed, not from a survey about your influences.</p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;max-width:1040px;margin-bottom:22px;">
            <?php foreach ($pill as $p) : ?>
            <div style="<?php echo $card; ?>">
                <div style="font:600 14px/1.3 var(--lmegA-font,inherit);color:#F4F5F7;"><?php echo esc_html((string) $p['label']); ?></div>
                <div style="font-size:12.5px;line-height:1.5;color:#C9CCD6;margin-top:6px;"><?php echo esc_html((string) $p['why']); ?></div>
                <?php if (!empty($p['ideas'])) : ?>
                <ul style="margin:10px 0 0;padding:0 0 0 16px;font-size:12px;color:#8B90A0;line-height:1.6;">
                    <?php foreach ((array) $p['ideas'] as $i) : ?><li><?php echo esc_html((string) $i); ?></li><?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($roles) : ?>
        <h2 style="margin:6px 0 10px;font-size:16px;">Every song's job this cycle</h2>
        <div style="<?php echo $card; ?>max-width:1040px;overflow-x:auto;margin-bottom:22px;">
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead><tr style="text-align:left;color:#8B90A0;font-size:11px;letter-spacing:.06em;text-transform:uppercase;">
                    <th style="padding:0 10px 8px 0;font-weight:600;">Song</th>
                    <th style="padding:0 10px 8px 0;font-weight:600;">Role</th>
                    <th style="padding:0 10px 8px 0;font-weight:600;text-align:right;">7 days</th>
                    <th style="padding:0 10px 8px 0;font-weight:600;text-align:right;">Week on week</th>
                    <th style="padding:0 10px 8px 0;font-weight:600;">14 days</th>
                    <th style="padding:0 0 8px;font-weight:600;">What it's for</th>
                </tr></thead>
                <tbody>
                <?php foreach ($roles as $r) :
                    $rc = ['Focus track' => '#D05FA2', 'Rising' => '#34D399', 'Cooling' => '#F87171', 'Sleeper' => '#7C6CF6', 'Steady' => '#8B90A0'][$r['role']] ?? '#8B90A0'; ?>
                    <tr style="border-top:1px solid rgba(255,255,255,.07);">
                        <td style="padding:9px 10px 9px 0;font-weight:600;color:#F4F5F7;max-width:220px;"><?php echo esc_html($r['title']); ?></td>
                        <td style="padding:9px 10px 9px 0;"><span style="font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:<?php echo esc_attr($rc); ?>;"><?php echo esc_html($r['role']); ?></span></td>
                        <td style="padding:9px 10px 9px 0;text-align:right;font-variant-numeric:tabular-nums;"><?php echo esc_html(number_format_i18n((int) $r['last7'])); ?></td>
                        <td style="padding:9px 10px 9px 0;text-align:right;"><?php echo $r['wow'] === null ? '<span style="color:#8B90A0;">—</span>' : (function_exists('lmeg_si_chip') ? lmeg_si_chip($r['wow']) : esc_html($r['wow'] . '%')); ?></td>
                        <td style="padding:9px 10px 9px 0;width:110px;"><?php echo function_exists('lmeg_si_sparkline') ? lmeg_si_sparkline(array_slice($r['series'], -14), 96, 24, $rc) : ''; ?></td>
                        <td style="padding:9px 0;color:#C9CCD6;"><?php echo esc_html($r['job']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($ctx['stage']['bottleneck'])) : $b = $ctx['stage']['bottleneck']; ?>
        <div style="<?php echo $card; ?>max-width:1040px;margin-bottom:22px;">
            <div style="<?php echo $lbl; ?>">What the plan is aiming at</div>
            <div style="font-size:13.5px;line-height:1.55;color:#F4F5F7;margin-top:7px;">
                Stage <?php echo (int) $ctx['stage']['stage']; ?> of 7 — <?php echo esc_html((string) $ctx['stage']['name']); ?>.
                Next gate: <?php echo esc_html((string) ($b['label'] ?? '')); ?><?php if (!empty($b['value']) && !empty($b['target'])) : ?>
                    — <?php echo esc_html((string) $b['value']); ?> against <?php echo esc_html((string) $b['target']); ?><?php endif; ?>.
                <?php if (!empty($b['need_label'])) : ?><?php echo esc_html((string) $b['need_label']); ?>.<?php endif; ?>
            </div>
            <p style="margin:10px 0 0;"><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lmeg-ladder')); ?>">Open the ladder</a></p>
        </div>
        <?php endif; ?>

        <p style="font-size:12px;color:#8B90A0;max-width:760px;">Nothing here sends on its own. Every move opens a draft or a page and waits for you.
        The plan rebuilds itself every Monday morning, and you can rebuild it any time.</p>
    </div>
    <?php
}

/** Three moves for the Monday owner digest. '' when the plan is empty. */
function lmeg_plan_digest_html() {
    $weeks = lmeg_plan_weeks(1);
    $ws = array_keys($weeks);
    if (!$ws) return '';
    $moves = array_slice(array_filter((array) $weeks[$ws[0]]['moves'], function ($r) { return (string) $r->status === 'todo'; }), 0, 3);
    if (!$moves) return '';
    $html = '<h3 style="margin:22px 0 10px;">Your plan this week</h3><table style="border-collapse:collapse;">';
    foreach ($moves as $r) {
        $href = lmeg_plan_move_href($r);
        $html .= '<tr><td style="padding:7px 0;">'
               . '<strong>' . esc_html((string) $r->title) . '</strong>'
               . ' <span style="color:#777;">· ' . esc_html(date_i18n('D M j', strtotime((string) $r->due_date))) . '</span><br>'
               . '<span style="color:#777;font-size:13px;">' . esc_html((string) $r->why) . '</span>'
               . ($href ? ' <a href="' . esc_url($href) . '" style="font-size:13px;">' . esc_html((string) ($r->action_label ?: 'Open')) . '</a>' : '')
               . '</td></tr>';
    }
    return $html . '</table>';
}
