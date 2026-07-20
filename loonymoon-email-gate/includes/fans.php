<?php
/**
 * Fan CRM layer — unique codes, referral links, fan-type scoring, and the
 * per-fan activity timeline. The OpenStage-inspired feature set that runs
 * entirely on data the plugin already collects.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------------------------
 * IP → country geolocation
 *
 * Fills the country gap for email signups (phone + address signups already
 * carry a country). Order of precedence stays: phone country > address
 * country > IP geo. Uses the Cloudflare country header when present (free,
 * instant), else api.country.is (free, HTTPS, no key). Per-IP result cached
 * a day; lookups fail silently.
 * ------------------------------------------------------------------------- */

function lmeg_geo_country_from_ip($ip) {
    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return '';
    }
    $key    = 'lmeg_geo_' . md5($ip);
    $cached = get_transient($key);
    if ($cached !== false) {
        return $cached === '-' ? '' : $cached;
    }

    $cc   = '';
    $resp = wp_remote_get('https://api.country.is/' . rawurlencode($ip), ['timeout' => 2]);
    if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
        $d = json_decode(wp_remote_retrieve_body($resp), true);
        if (!empty($d['country']) && preg_match('/^[A-Z]{2}$/', strtoupper($d['country']))) {
            $cc = strtoupper($d['country']);
        }
    }
    set_transient($key, $cc ?: '-', DAY_IN_SECONDS);
    return $cc;
}

/**
 * Country for the CURRENT request — Cloudflare header first (free), then
 * API lookup on the resolved client IP.
 */
function lmeg_geo_country_current_request() {
    $cf = strtoupper((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
    if (preg_match('/^[A-Z]{2}$/', $cf) && $cf !== 'XX' && $cf !== 'T1') {
        return $cf;
    }
    $ip = function_exists('lmeg_client_ip') ? lmeg_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
    return lmeg_geo_country_from_ip($ip);
}

/**
 * Backfill: existing subscribers with a stored IP but no country get
 * geolocated in small batches (15 every 5 minutes) until the gap is closed.
 * Re-applies auto-tags so country:* chips appear as rows fill in.
 */
add_action('lmeg_broadcast_tick', 'lmeg_geo_backfill', 60);
function lmeg_geo_backfill() {
    if (get_site_transient('lmeg_geo_bf_lock')) return;
    set_site_transient('lmeg_geo_bf_lock', 1, 5 * MINUTE_IN_SECONDS);

    global $wpdb;
    $subs = $wpdb->prefix . LMEG_TABLE;
    $rows = $wpdb->get_results(
        "SELECT id, ip FROM $subs
         WHERE (country IS NULL OR country = '') AND ip IS NOT NULL AND ip <> ''
         ORDER BY id ASC LIMIT 15"
    );
    foreach ((array) $rows as $r) {
        $cc = lmeg_geo_country_from_ip($r->ip);
        if ($cc) {
            $wpdb->update($subs, ['country' => $cc], ['id' => (int) $r->id]);
            $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM $subs WHERE id = %d", (int) $r->id));
            if ($fresh) lmeg_apply_auto_tags($fresh);
        }
    }
}

/* ---------------------------------------------------------------------------
 * Unique codes + referral links
 * ------------------------------------------------------------------------- */

/**
 * Get (lazily generating) the fan's unique code. Serves double duty:
 * a presale/discount identifier ({unique_code}) and the referral handle
 * ({referral_link} → /?ref=CODE).
 */
function lmeg_get_fan_code($subscriber_id) {
    global $wpdb;
    $tbl  = $wpdb->prefix . LMEG_TABLE;
    $code = $wpdb->get_var($wpdb->prepare("SELECT referral_code FROM $tbl WHERE id = %d", (int) $subscriber_id));
    if ($code) return $code;

    // 8-char uppercase, unambiguous alphabet. Retry on the freak collision.
    for ($i = 0; $i < 5; $i++) {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($c = 0; $c < 8; $c++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $ok = $wpdb->query($wpdb->prepare(
            "UPDATE $tbl SET referral_code = %s WHERE id = %d AND referral_code IS NULL",
            $code, (int) $subscriber_id
        ));
        if ($ok !== false) {
            $fresh = $wpdb->get_var($wpdb->prepare("SELECT referral_code FROM $tbl WHERE id = %d", (int) $subscriber_id));
            if ($fresh) return $fresh;
        }
    }
    return '';
}

function lmeg_referral_url($subscriber_id) {
    $code = lmeg_get_fan_code($subscriber_id);
    return $code ? add_query_arg('ref', $code, home_url('/')) : home_url('/');
}

/**
 * Capture ?ref=CODE into a 30-day cookie so the signup that follows can be
 * credited to the referrer.
 */
add_action('init', 'lmeg_maybe_capture_ref', 5);
function lmeg_maybe_capture_ref() {
    if (empty($_GET['ref'])) return;
    $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $_GET['ref']));
    if (strlen($code) < 6 || strlen($code) > 12) return;
    setcookie('lmeg_ref', $code, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
    $_COOKIE['lmeg_ref'] = $code;
}

/**
 * Resolve the referral cookie to a subscriber id (the referrer), or null.
 */
function lmeg_resolve_ref_cookie() {
    global $wpdb;
    $code = isset($_COOKIE['lmeg_ref']) ? strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $_COOKIE['lmeg_ref'])) : '';
    if (!$code) return null;
    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE referral_code = %s", $code
    ));
    return $id ? (int) $id : null;
}

/**
 * Stamp referred_by on freshly created subscribers.
 */
add_action('lmeg_subscriber_created', 'lmeg_apply_referral', 5, 1);
function lmeg_apply_referral($subscriber_id) {
    $referrer = lmeg_resolve_ref_cookie();
    if (!$referrer || $referrer === (int) $subscriber_id) return;
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . LMEG_TABLE,
        ['referred_by' => $referrer],
        ['id' => (int) $subscriber_id]
    );
}

/* ---------------------------------------------------------------------------
 * Fan-type scoring — superfan / engaged / casual / dormant
 * ------------------------------------------------------------------------- */

/**
 * Classify every subscriber and refresh their fan-type auto-tag.
 * Criteria (rolling 90 days):
 *   superfan — any shop order OR active paid tier
 *   engaged  — 2+ clicks or 5+ opens
 *   casual   — at least 1 open or click
 *   dormant  — nothing
 */
function lmeg_recalculate_fan_types() {
    global $wpdb;
    $subs   = $wpdb->prefix . LMEG_TABLE;
    $events = $wpdb->prefix . 'lmeg_broadcast_events';
    $orders = $wpdb->prefix . 'lmeg_shop_orders';
    $since  = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - 90 * DAY_IN_SECONDS);

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT s.id,
                s.member_status, s.member_tier_id,
                COALESCE(e.clicks, 0) AS clicks,
                COALESCE(e.opens, 0)  AS opens,
                COALESCE(o.orders, 0) AS orders
         FROM $subs s
         LEFT JOIN (
             SELECT subscriber_id,
                    SUM(event_type = 'click') AS clicks,
                    SUM(event_type = 'open')  AS opens
             FROM $events WHERE created_at >= %s GROUP BY subscriber_id
         ) e ON e.subscriber_id = s.id
         LEFT JOIN (
             SELECT subscriber_id, COUNT(*) AS orders
             FROM $orders WHERE ordered_at >= %s GROUP BY subscriber_id
         ) o ON o.subscriber_id = s.id
         WHERE s.unsubscribed_at IS NULL",
        $since, $since
    ));

    $counts = ['superfan' => 0, 'engaged' => 0, 'casual' => 0, 'dormant' => 0];
    foreach ((array) $rows as $r) {
        $is_paying = ($r->member_status === 'active' && $r->member_tier_id);
        if ($r->orders > 0 || $is_paying) {
            $type = 'superfan';
        } elseif ($r->clicks >= 2 || $r->opens >= 5) {
            $type = 'engaged';
        } elseif ($r->clicks >= 1 || $r->opens >= 1) {
            $type = 'casual';
        } else {
            $type = 'dormant';
        }
        $counts[$type]++;

        lmeg_detach_auto_tags($r->id, 'fan-type:');
        $tag = lmeg_get_or_create_tag('fan-type:' . $type, 'Fan type: ' . ucfirst($type), true, lmeg_fan_type_color($type));
        if ($tag) lmeg_attach_tag($r->id, $tag->id);
    }

    update_option('lmeg_fan_types_last_run', current_time('mysql'), false);
    return $counts;
}

function lmeg_fan_type_color($type) {
    return [
        'superfan' => '#d05fa2',
        'engaged'  => '#8b5cf6',
        'casual'   => '#3b82f6',
        'dormant'  => '#9ca3af',
    ][$type] ?? '#6b7280';
}

/**
 * Cron: refresh fan types daily. Piggybacks the minute tick with a 24h lock.
 */
add_action('lmeg_broadcast_tick', 'lmeg_fan_types_cron', 50);
function lmeg_fan_types_cron() {
    if (get_site_transient('lmeg_fan_types_lock')) return;
    set_site_transient('lmeg_fan_types_lock', 1, DAY_IN_SECONDS);
    lmeg_recalculate_fan_types();
}

/* ---------------------------------------------------------------------------
 * Fan timeline — one merged, ordered activity stream per subscriber
 * ------------------------------------------------------------------------- */

/**
 * @return array of ['at' => datetime, 'icon', 'label'] newest first
 */
function lmeg_fan_timeline($subscriber_id, $limit = 100) {
    global $wpdb;
    $sid    = (int) $subscriber_id;
    $subs   = $wpdb->prefix . LMEG_TABLE;
    $events = $wpdb->prefix . 'lmeg_broadcast_events';
    $log    = $wpdb->prefix . 'lmeg_broadcast_log';
    $orders = $wpdb->prefix . 'lmeg_shop_orders';
    $bcast  = $wpdb->prefix . 'lmeg_broadcasts';
    $grants = $wpdb->prefix . 'lmeg_soft_grants';

    $items = [];

    $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM $subs WHERE id = %d", $sid));
    if (!$sub) return [];

    $items[] = ['at' => $sub->created_at, 'icon' => '🌱', 'label' => 'Joined the list'];
    if ($sub->unsubscribed_at) {
        $items[] = ['at' => $sub->unsubscribed_at, 'icon' => '👋', 'label' => 'Unsubscribed'];
    }
    if ($sub->welcome_sent_at) {
        $items[] = ['at' => $sub->welcome_sent_at, 'icon' => '💌', 'label' => 'Welcome email sent'];
    }

    // Broadcast sends to this fan.
    $sends = $wpdb->get_results($wpdb->prepare(
        "SELECT l.sent_at, l.channel, b.subject FROM $log l
         LEFT JOIN $bcast b ON b.id = l.broadcast_id
         WHERE l.subscriber_id = %d AND l.status = 'sent' ORDER BY l.sent_at DESC LIMIT 40", $sid
    ));
    foreach ((array) $sends as $r) {
        $items[] = [
            'at'    => $r->sent_at,
            'icon'  => $r->channel === 'sms' ? '📱' : '📤',
            'label' => 'Received ' . ($r->channel === 'sms' ? 'SMS' : 'email') . ': ' . ($r->subject ?: 'broadcast'),
        ];
    }

    // Opens + clicks.
    $evs = $wpdb->get_results($wpdb->prepare(
        "SELECT e.created_at, e.event_type, e.url, b.subject FROM $events e
         LEFT JOIN $bcast b ON b.id = e.broadcast_id
         WHERE e.subscriber_id = %d ORDER BY e.created_at DESC LIMIT 60", $sid
    ));
    foreach ((array) $evs as $r) {
        $items[] = [
            'at'    => $r->created_at,
            'icon'  => $r->event_type === 'click' ? '🖱' : '👀',
            'label' => ($r->event_type === 'click' ? 'Clicked' : 'Opened') . ' "' . ($r->subject ?: 'broadcast') . '"'
                     . ($r->event_type === 'click' && $r->url ? ' → ' . esc_url(rawurldecode($r->url)) : ''),
        ];
    }

    // Shop orders.
    $ords = $wpdb->get_results($wpdb->prepare(
        "SELECT ordered_at, order_number, total_cents, currency FROM $orders
         WHERE subscriber_id = %d ORDER BY ordered_at DESC LIMIT 30", $sid
    ));
    foreach ((array) $ords as $r) {
        $items[] = [
            'at'    => $r->ordered_at,
            'icon'  => '🛒',
            'label' => 'Placed order #' . $r->order_number . ' — ' . lmeg_format_price((int) $r->total_cents, $r->currency),
        ];
    }

    // Soft-paywall grants.
    $sgs = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, granted_at FROM $grants WHERE subscriber_id = %d ORDER BY granted_at DESC LIMIT 20", $sid
    ));
    foreach ((array) $sgs as $r) {
        $items[] = [
            'at'    => $r->granted_at,
            'icon'  => '🔓',
            'label' => 'Read "' . get_the_title($r->post_id) . '" free (soft paywall)',
        ];
    }

    usort($items, function ($a, $b) { return strcmp($b['at'] ?? '', $a['at'] ?? ''); });
    return array_slice($items, 0, $limit);
}

/**
 * Lifetime revenue for one fan.
 */
function lmeg_fan_revenue($subscriber_id) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(total_cents), 0) FROM {$wpdb->prefix}lmeg_shop_orders WHERE subscriber_id = %d",
        (int) $subscriber_id
    ));
}

/** Subscription revenue accumulated from Stripe invoice.payment_succeeded. */
function lmeg_fan_membership_revenue($subscriber_id) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(member_revenue_cents, 0) FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d",
        (int) $subscriber_id
    ));
}

/**
 * True lifetime value: attributed shop orders + subscription payments.
 * Returns cents. Use lmeg_fan_ltv_breakdown() for the split.
 */
function lmeg_fan_ltv($subscriber_id) {
    $b = lmeg_fan_ltv_breakdown($subscriber_id);
    return $b['total'];
}

function lmeg_fan_ltv_breakdown($subscriber_id) {
    $shop = lmeg_fan_revenue($subscriber_id);
    $memb = lmeg_fan_membership_revenue($subscriber_id);
    return ['shop' => $shop, 'membership' => $memb, 'total' => $shop + $memb];
}

/** Opens / clicks counts for a fan (all-time), for the profile engagement card. */
function lmeg_fan_engagement($subscriber_id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT SUM(event_type = 'open') AS opens, SUM(event_type = 'click') AS clicks
         FROM {$wpdb->prefix}lmeg_broadcast_events WHERE subscriber_id = %d",
        (int) $subscriber_id
    ));
    return ['opens' => (int) ($row->opens ?? 0), 'clicks' => (int) ($row->clicks ?? 0)];
}
