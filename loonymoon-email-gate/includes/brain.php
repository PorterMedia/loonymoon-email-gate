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
    if (sanitize_key($_GET['lmeg_brain']) !== 'export') return;

    $s      = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $secret = (string) ($s['brain_token'] ?? '');
    if ($secret === '') { status_header(404); exit; }   // feature off until a token is set
    $given  = isset($_GET['token']) ? (string) wp_unslash($_GET['token']) : '';
    if (!hash_equals($secret, $given)) { status_header(401); exit; }

    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode(lmeg_brain_export_payload());
    exit;
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

    $metrics = [
        ['key' => 'fans_total',     'label' => 'Fans',                'value' => $fans,     'unit' => 'count'],
        ['key' => 'new_30d',        'label' => 'New fans (30d)',      'value' => $new30,    'unit' => 'count'],
        ['key' => 'members_paying', 'label' => 'Paying members',      'value' => $paying,   'unit' => 'count'],
        ['key' => 'revenue_all',    'label' => 'Revenue (all-time)',  'value' => $revAll,   'unit' => $unit],
        ['key' => 'revenue_30d',    'label' => 'Revenue (30d)',       'value' => $rev30,    'unit' => $unit],
        ['key' => 'orders_30d',     'label' => 'Orders (30d)',        'value' => $ord30,    'unit' => 'count'],
        ['key' => 'open_rate_30d',  'label' => 'Open rate (30d)',     'value' => $openRate, 'unit' => 'percent'],
    ];

    // ---- people (recent window; lifetime value = membership + shop) ----
    $rows = $wpdb->get_results(
        "SELECT s.id, s.email, s.phone, s.first_name, s.ip, s.member_revenue_cents, s.created_at,
                COALESCE(o.cents, 0) AS shop_cents,
                (SELECT GROUP_CONCAT(t.slug) FROM {$wpdb->prefix}lmeg_subscriber_tags st
                   JOIN {$wpdb->prefix}lmeg_tags t ON t.id = st.tag_id WHERE st.subscriber_id = s.id) AS tags
         FROM $subs s
         LEFT JOIN (SELECT subscriber_id, SUM(total_cents) cents FROM $ord GROUP BY subscriber_id) o
           ON o.subscriber_id = s.id
         WHERE s.unsubscribed_at IS NULL
         ORDER BY s.id DESC LIMIT 500"
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
