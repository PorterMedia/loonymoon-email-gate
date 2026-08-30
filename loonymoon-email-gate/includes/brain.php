<?php
/**
 * Brain export — a read-only endpoint that lets Porter Brain (the central
 * intelligence hub) pull this site's metrics, people and docs. Implements the
 * brain-export connector contract.
 *
 *   GET ?lmeg_brain=export&token=<secret>
 *
 * Disabled until a token is set (Settings → Central brain). Read-only: no
 * cookies, no writes. Mirrors the existing public routers (lmeg_cart /
 * lmeg_purchases) — a query-var handler on `init`.
 */

if (!defined('ABSPATH')) exit;

add_action('init', 'lmeg_brain_router');
function lmeg_brain_router() {
    if (!isset($_GET['lmeg_brain'])) return;
    $action = sanitize_key($_GET['lmeg_brain']);
    if (!in_array($action, ['export', 'person'], true)) return;

    $s      = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $secret = (string) ($s['brain_token'] ?? '');
    if ($secret === '') { status_header(404); exit; }   // feature off until a token is set
    $given  = isset($_GET['token']) ? (string) wp_unslash($_GET['token']) : '';
    if (!hash_equals($secret, $given)) { status_header(401); exit; }

    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    if ($action === 'person') {
        echo wp_json_encode(lmeg_brain_person_payload((int) ($_GET['sub'] ?? 0)));
    } else {
        echo wp_json_encode(lmeg_brain_export_payload());
    }
    exit;
}

/** One fan's full behavioural footprint — every data point we have on them. */
function lmeg_brain_person_payload($sid) {
    global $wpdb;
    $sid = (int) $sid;
    $subs = $wpdb->prefix . LMEG_TABLE;
    $ev   = $wpdb->prefix . 'lmeg_broadcast_events';
    $ord  = $wpdb->prefix . 'lmeg_shop_orders';

    $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM $subs WHERE id = %d", $sid));
    if (!$sub) return ['error' => 'not found'];

    // On-site behaviour (identity-linked pageviews live in broadcast_events).
    $pv        = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ev WHERE subscriber_id = %d AND event_type = 'pageview'", $sid));
    $visitDays = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT DATE(created_at)) FROM $ev WHERE subscriber_id = %d AND event_type = 'pageview'", $sid));
    $firstV    = $wpdb->get_var($wpdb->prepare("SELECT MIN(created_at) FROM $ev WHERE subscriber_id = %d AND event_type = 'pageview'", $sid));
    $lastV     = $wpdb->get_var($wpdb->prepare("SELECT MAX(created_at) FROM $ev WHERE subscriber_id = %d AND event_type = 'pageview'", $sid));
    $opens     = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ev WHERE subscriber_id = %d AND event_type = 'open'", $sid));
    $clicks    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ev WHERE subscriber_id = %d AND event_type = 'click'", $sid));
    // Orders attribute by subscriber_id, or by email when the order carries no
    // subscriber_id (guest / pre-subscription checkout) — matching the export.
    $email     = (string) ($sub->email ?? '');
    $ordWhere  = "subscriber_id = %d OR (subscriber_id IS NULL AND email <> '' AND LOWER(email) = LOWER(%s))";
    $orders    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ord WHERE $ordWhere", $sid, $email));
    $spend     = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(total_cents),0) FROM $ord WHERE $ordWhere", $sid, $email));

    // Time on site — active-dwell (ms) captured client-side, summed over their
    // page views; and how often they visit (sessions split on a 30-min gap).
    $dwellMs    = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(dwell_ms),0) FROM $ev WHERE subscriber_id = %d AND event_type = 'pageview'", $sid));
    $timeOnSite = (int) round($dwellMs / 1000);   // seconds
    $times = (array) $wpdb->get_col($wpdb->prepare("SELECT created_at FROM $ev WHERE subscriber_id = %d AND event_type = 'pageview' ORDER BY created_at ASC", $sid));
    $sessions = 0; $prevTs = null;
    foreach ($times as $t) {
        $ts = strtotime((string) $t);
        if ($ts === false) continue;
        if ($prevTs === null || ($ts - $prevTs) > 1800) $sessions++;
        $prevTs = $ts;
    }
    $avgVisit = $sessions ? (int) round($timeOnSite / $sessions) : 0;

    // Pages they land on.
    $pages = [];
    foreach ((array) $wpdb->get_results($wpdb->prepare(
        "SELECT url, COUNT(*) n FROM $ev WHERE subscriber_id = %d AND event_type = 'pageview' AND url <> ''
         GROUP BY url ORDER BY n DESC LIMIT 12", $sid)) as $r) {
        $path = wp_parse_url($r->url, PHP_URL_PATH) ?: $r->url;
        $pages[] = ['path' => $path, 'views' => (int) $r->n];
    }

    // Visit frequency — pageviews per day, last 60 days.
    $byDay = [];
    foreach ((array) $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(created_at) d, COUNT(*) n FROM $ev WHERE subscriber_id = %d AND event_type = 'pageview'
         GROUP BY DATE(created_at) ORDER BY d DESC LIMIT 60", $sid)) as $r) {
        $byDay[$r->d] = (int) $r->n;
    }

    // Outbound handoffs (journey), if that module is present.
    $journey = function_exists('lmeg_fan_journey_summary') ? lmeg_fan_journey_summary($sid) : null;

    // Full merged activity timeline (signup, emails, opens, clicks, pageviews,
    // orders, contests, surveys, DMs…) — reuse the fan-profile timeline builder.
    $timeline = [];
    if (function_exists('lmeg_fan_timeline')) {
        foreach (array_slice((array) lmeg_fan_timeline($sid, 60), 0, 60) as $it) {
            $timeline[] = ['at' => $it['at'] ?? '', 'icon' => $it['icon'] ?? '•', 'label' => $it['label'] ?? ''];
        }
    }

    return [
        'source_id'   => (string) $sid,
        'email'       => $sub->email ?: null,
        'name'        => $sub->first_name ?: null,
        'phone'       => $sub->phone ?: null,
        'ip'          => $sub->ip ?: null,
        'joined'      => $sub->created_at ? gmdate('c', strtotime($sub->created_at)) : null,
        'first_visit' => $firstV ? gmdate('c', strtotime($firstV)) : null,
        'last_visit'  => $lastV ? gmdate('c', strtotime($lastV)) : null,
        'metrics'     => [
            ['key' => 'pageviews',    'label' => 'Page views',    'value' => $pv,         'unit' => 'count'],
            ['key' => 'visits',       'label' => 'Visits',        'value' => $sessions,   'unit' => 'count'],
            ['key' => 'visit_days',   'label' => 'Days visited',  'value' => $visitDays,  'unit' => 'count'],
            ['key' => 'time_on_site', 'label' => 'Time on site',  'value' => $timeOnSite, 'unit' => 'seconds'],
            ['key' => 'avg_visit',    'label' => 'Avg visit',     'value' => $avgVisit,   'unit' => 'seconds'],
            ['key' => 'email_opens',  'label' => 'Email opens',   'value' => $opens,      'unit' => 'count'],
            ['key' => 'email_clicks', 'label' => 'Email clicks',  'value' => $clicks,     'unit' => 'count'],
            ['key' => 'orders',       'label' => 'Orders',        'value' => $orders,     'unit' => 'count'],
            ['key' => 'spend',        'label' => 'Spend',         'value' => $spend,      'unit' => 'cents'],
        ],
        'top_pages'     => $pages,
        'visits_by_day' => $byDay,
        'journey'       => $journey,
        'timeline'      => $timeline,
    ];
}

/** Build the brain-export payload (metrics + people + docs). Read-only. */
function lmeg_brain_export_payload() {
    global $wpdb;
    $subs = $wpdb->prefix . LMEG_TABLE;
    $ord  = $wpdb->prefix . 'lmeg_shop_orders';
    $bc   = $wpdb->prefix . 'lmeg_broadcasts';
    $log  = $wpdb->prefix . 'lmeg_broadcast_log';
    $ev   = $wpdb->prefix . 'lmeg_broadcast_events';
    $since = gmdate('Y-m-d H:i:s', current_time('timestamp') - 30 * DAY_IN_SECONDS);

    $host   = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
    $slug   = 'fanloop-' . trim(preg_replace('/[^a-z0-9]+/', '-', $host), '-');
    $artist = function_exists('lmeg_artist') ? lmeg_artist() : get_bloginfo('name');

    // Money unit follows the store's dominant currency.
    $cur  = (string) $wpdb->get_var("SELECT currency FROM $ord WHERE currency IS NOT NULL AND currency <> '' GROUP BY currency ORDER BY COUNT(*) DESC LIMIT 1");
    $unit = in_array(strtoupper($cur), ['USD', 'CAD'], true) ? strtoupper($cur) . '_cents' : 'cents';

    // ---- metrics (current values; the hub stores them as a time series) ----
    $fans   = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE unsubscribed_at IS NULL");
    $new30  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $subs WHERE created_at >= %s", $since));
    $paying = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE member_status = 'active' AND member_tier_id IS NOT NULL");
    $revAll = (int) $wpdb->get_var("SELECT COALESCE(SUM(total_cents),0) FROM $ord");
    $rev30  = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(total_cents),0) FROM $ord WHERE ordered_at >= %s", $since));
    $ord30  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ord WHERE ordered_at >= %s", $since));
    $sent30 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $log WHERE status = 'sent' AND sent_at >= %s", $since));
    $open30 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT subscriber_id) FROM $ev WHERE event_type = 'open' AND created_at >= %s", $since));
    $openRate = $sent30 ? round($open30 / $sent30 * 100, 1) : 0;
    // On-site engagement (identified fans): page views in the last 30d and the
    // average active time-on-page across all page views that have a measurement.
    $pv30   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ev WHERE event_type = 'pageview' AND created_at >= %s", $since));
    $avgTos = (int) $wpdb->get_var("SELECT COALESCE(ROUND(AVG(dwell_ms) / 1000), 0) FROM $ev WHERE event_type = 'pageview' AND dwell_ms > 0");

    $metrics = [
        ['key' => 'fans_total',       'label' => 'Fans',                'value' => $fans,     'unit' => 'count'],
        ['key' => 'new_30d',          'label' => 'New fans (30d)',      'value' => $new30,    'unit' => 'count'],
        ['key' => 'members_paying',   'label' => 'Paying members',      'value' => $paying,   'unit' => 'count'],
        ['key' => 'revenue_all',      'label' => 'Revenue (all-time)',  'value' => $revAll,   'unit' => $unit],
        ['key' => 'revenue_30d',      'label' => 'Revenue (30d)',       'value' => $rev30,    'unit' => $unit],
        ['key' => 'orders_30d',       'label' => 'Orders (30d)',        'value' => $ord30,    'unit' => 'count'],
        ['key' => 'open_rate_30d',    'label' => 'Open rate (30d)',     'value' => $openRate, 'unit' => 'percent'],
        ['key' => 'pageviews_30d',    'label' => 'Page views (30d)',    'value' => $pv30,     'unit' => 'count'],
        ['key' => 'avg_time_on_site', 'label' => 'Avg time on site',    'value' => $avgTos,   'unit' => 'seconds'],
    ];

    // ---- people (top window by value; lifetime value = membership + shop) ----
    // Shop revenue attributes to a fan by their order's subscriber_id when it's
    // set, otherwise by matching the order email to the fan's email (guest and
    // pre-subscription checkouts often carry no subscriber_id). Each order is
    // resolved to exactly one fan so nothing is double-counted. The window is
    // ordered by total value so buyers are never dropped when a site has more
    // than 500 fans (e.g. a big recent import pushing older buyers past the cap).
    $rows = $wpdb->get_results(
        "SELECT s.id, s.email, s.phone, s.first_name, s.ip, s.member_revenue_cents, s.created_at,
                COALESCE(orev.cents, 0) AS shop_cents,
                (COALESCE(s.member_revenue_cents, 0) + COALESCE(orev.cents, 0)) AS total_value,
                (SELECT GROUP_CONCAT(t.slug) FROM {$wpdb->prefix}lmeg_subscriber_tags st
                   JOIN {$wpdb->prefix}lmeg_tags t ON t.id = st.tag_id WHERE st.subscriber_id = s.id) AS tags
         FROM $subs s
         LEFT JOIN (
             SELECT rid, SUM(total_cents) AS cents FROM (
                 SELECT o.total_cents,
                        CASE WHEN o.subscriber_id IS NOT NULL AND o.subscriber_id > 0 THEN o.subscriber_id
                             ELSE (SELECT s2.id FROM $subs s2
                                   WHERE s2.email <> '' AND LOWER(s2.email) = LOWER(o.email)
                                   ORDER BY s2.id LIMIT 1)
                        END AS rid
                 FROM $ord o
             ) resolved WHERE rid IS NOT NULL GROUP BY rid
         ) orev ON orev.rid = s.id
         WHERE s.unsubscribed_at IS NULL
         ORDER BY total_value DESC, s.id DESC LIMIT 500"
    );
    $people = [];
    foreach ((array) $rows as $r) {
        $people[] = [
            'source_id'     => (string) $r->id,
            'email'         => $r->email ?: null,
            'phone'         => $r->phone ?: null,
            'name'          => $r->first_name ?: null,
            'ip'            => $r->ip ?: null,
            'revenue_cents' => (int) $r->member_revenue_cents + (int) $r->shop_cents,
            'last_seen'     => $r->created_at ? gmdate('c', strtotime($r->created_at)) : null,
            'tags'          => $r->tags ? explode(',', $r->tags) : [],
        ];
    }

    // ---- docs (recent broadcasts, for the AI to retrieve later) ----
    $docs = [];
    $brs = $wpdb->get_results("SELECT id, subject, completed_at FROM $bc WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 20");
    foreach ((array) $brs as $b) {
        $docs[] = [
            'source_id'   => 'bcast-' . (int) $b->id,
            'type'        => 'broadcast',
            'title'       => $b->subject ?: ('Broadcast #' . (int) $b->id),
            'body'        => '',
            'url'         => '',
            'occurred_at' => $b->completed_at ? gmdate('c', strtotime($b->completed_at)) : null,
        ];
    }

    return [
        'project'      => $slug,
        'name'         => 'Fanloop — ' . $artist,
        'generated_at' => gmdate('c'),
        'metrics'      => $metrics,
        'people'       => $people,
        'docs'         => $docs,
    ];
}
