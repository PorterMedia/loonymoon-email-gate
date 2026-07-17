<?php
/**
 * Paid membership layer — tiers, member cookie, magic-link sign-in,
 * Stripe Checkout + Customer Portal + webhook receiver, and the gate
 * decision logic that knows about paid access levels.
 *
 * The plugin owns members. WordPress users are not touched — signups
 * live entirely in lmeg_subscribers. Signed cookies + magic links
 * carry identity between requests.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LMEG_MEMBER_COOKIE', 'lmeg_member');

/* ---------------------------------------------------------------------------
 * Signed member cookie: sub_id.expires.tier_id.hmac
 * ------------------------------------------------------------------------- */

function lmeg_member_cookie_value($sub_id, $tier_id, $expires_ts) {
    $sub_id  = (int) $sub_id;
    $tier_id = (int) $tier_id;
    $expires = (int) $expires_ts;
    $payload = $sub_id . '.' . $expires . '.' . $tier_id;
    $sig     = substr(hash_hmac('sha256', $payload, lmeg_get_secret()), 0, 32);
    return $payload . '.' . $sig;
}

function lmeg_parse_member_cookie($raw) {
    if (!$raw || !is_string($raw)) return null;
    $parts = explode('.', $raw);
    if (count($parts) !== 4) return null;
    list($sub_id, $expires, $tier_id, $sig) = $parts;
    if (!ctype_digit($sub_id) || !ctype_digit($expires) || !ctype_digit($tier_id)) return null;
    $expected = substr(hash_hmac('sha256', "$sub_id.$expires.$tier_id", lmeg_get_secret()), 0, 32);
    if (!hash_equals($expected, $sig)) return null;
    if ((int) $expires < time()) return null;
    return [
        'sub_id'  => (int) $sub_id,
        'tier_id' => (int) $tier_id,
        'expires' => (int) $expires,
    ];
}

function lmeg_set_member_cookie($sub_id, $tier_id) {
    $s    = lmeg_get_settings();
    $days = max(1, (int) $s['member_cookie_days']);
    $exp  = time() + $days * DAY_IN_SECONDS;
    $val  = lmeg_member_cookie_value($sub_id, $tier_id, $exp);
    setcookie(LMEG_MEMBER_COOKIE, $val, $exp, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
    $_COOKIE[LMEG_MEMBER_COOKIE] = $val; // available same-request
}

function lmeg_clear_member_cookie() {
    setcookie(LMEG_MEMBER_COOKIE, '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
    unset($_COOKIE[LMEG_MEMBER_COOKIE]);
}

/**
 * Return the current member row, or null. Verifies the signed cookie AND
 * looks up the fresh subscriber row so tier changes made server-side are
 * reflected on the next request. Never trusts stale tier_id from the cookie
 * for access decisions — always uses the DB row.
 */
function lmeg_current_member() {
    static $cache = false;
    if ($cache !== false) return $cache;
    $parsed = lmeg_parse_member_cookie($_COOKIE[LMEG_MEMBER_COOKIE] ?? '');
    if (!$parsed) { $cache = null; return null; }

    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d",
        $parsed['sub_id']
    ));
    if (!$row || $row->unsubscribed_at) { $cache = null; return null; }
    $cache = $row;
    return $row;
}

/* ---------------------------------------------------------------------------
 * Tiers
 * ------------------------------------------------------------------------- */

function lmeg_all_tiers($active_only = false) {
    global $wpdb;
    $where = $active_only ? 'WHERE is_active = 1' : '';
    return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lmeg_tiers $where ORDER BY sort_order ASC, name ASC");
}

function lmeg_tier($id) {
    global $wpdb;
    if (!$id) return null;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lmeg_tiers WHERE id = %d", (int) $id));
}

function lmeg_tier_by_stripe_price($price_id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lmeg_tiers WHERE stripe_price_monthly = %s OR stripe_price_annual = %s LIMIT 1",
        $price_id, $price_id
    ));
}

function lmeg_format_price($cents, $currency = 'USD') {
    if (!$cents) return '';
    $amt = number_format($cents / 100, 2);
    return $currency === 'USD' ? "\$$amt" : "$amt $currency";
}

/* ---------------------------------------------------------------------------
 * Post access level (public / free / paid / tier-specific)
 * ------------------------------------------------------------------------- */

/**
 * Returns one of: 'public', 'free', 'paid', 'soft-paid', or ('tier', $tier_id).
 */
function lmeg_post_access_level($post_id) {
    $meta = get_post_meta((int) $post_id, '_lmeg_access', true);
    if (!$meta) {
        $s = lmeg_get_settings();
        $meta = $s['default_post_access'] ?: 'free';
    }
    if (strpos($meta, 'tier:') === 0) {
        return ['tier', (int) substr($meta, 5)];
    }
    if (!in_array($meta, ['public', 'free', 'paid', 'soft-paid'], true)) {
        $meta = 'free';
    }
    return $meta;
}

/**
 * Has this specific free member accepted the "keep reading free" offer
 * on this specific post? Grants are per-(subscriber, post).
 */
function lmeg_has_soft_grant($subscriber_id, $post_id) {
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$wpdb->prefix}lmeg_soft_grants WHERE subscriber_id = %d AND post_id = %d",
        (int) $subscriber_id, (int) $post_id
    ));
}

/**
 * Does the current member satisfy the access level for this post?
 * Returns 'ok', 'needs_signup', or 'needs_upgrade'. Soft-paid follows
 * the same shape but the render layer softens the CTA.
 */
function lmeg_check_post_access($post_id) {
    $access = lmeg_post_access_level($post_id);
    if ($access === 'public') return 'ok';

    $member = lmeg_current_member();
    if (!$member) return 'needs_signup';

    if ($access === 'free') return 'ok';

    $is_paying = $member->member_status === 'active' && $member->member_tier_id;

    if ($access === 'paid') {
        return $is_paying ? 'ok' : 'needs_upgrade';
    }
    if ($access === 'soft-paid') {
        if ($is_paying) return 'ok';
        if (lmeg_has_soft_grant($member->id, $post_id)) return 'ok';
        return 'needs_upgrade';
    }
    if (is_array($access) && $access[0] === 'tier') {
        $required_tier_id = (int) $access[1];
        return ($is_paying && (int) $member->member_tier_id === $required_tier_id) ? 'ok' : 'needs_upgrade';
    }
    return 'needs_signup';
}

/* ---------------------------------------------------------------------------
 * Magic-link sign-in
 * ------------------------------------------------------------------------- */

function lmeg_send_magic_link($email) {
    global $wpdb;
    $email = sanitize_email($email);
    if (!is_email($email)) {
        return new WP_Error('lmeg_bad_email', 'Please enter a valid email.');
    }
    $sub = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE email = %s",
        $email
    ));
    if (!$sub) {
        // Don't leak account existence — always respond as if we sent.
        return true;
    }

    $s     = lmeg_get_settings();
    $ttl_h = max(1, (int) $s['magic_link_ttl_hours']);
    $token = wp_generate_password(48, false, false);
    $hash  = hash('sha256', $token);
    $exp   = date('Y-m-d H:i:s', time() + $ttl_h * HOUR_IN_SECONDS);

    $wpdb->insert($wpdb->prefix . 'lmeg_magic_links', [
        'subscriber_id' => $sub->id,
        'token_hash'    => $hash,
        'expires_at'    => $exp,
        'created_at'    => current_time('mysql'),
    ]);

    $link = add_query_arg([
        'lmeg_member' => 'verify',
        't' => $token,
    ], home_url('/'));

    $subject = lmeg_render_merge_tags($s['magic_link_subject'] ?: 'Your sign-in link', $sub);
    $body    = lmeg_render_merge_tags($s['magic_link_body']    ?: 'Click to sign in: {magic_link}', $sub);
    $body    = str_replace('{magic_link}', $link, $body);

    if (function_exists('lmeg_email_send')) {
        list($text, $html) = lmeg_build_email_with_footer($body, lmeg_unsub_url($sub->id, $sub->email));
        lmeg_email_send($sub->email, $subject, $text, $html);
    } else {
        wp_mail($sub->email, $subject, $body);
    }
    return true;
}

function lmeg_verify_magic_link($token) {
    global $wpdb;
    if (!$token) return null;
    $hash = hash('sha256', $token);
    $row  = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lmeg_magic_links WHERE token_hash = %s LIMIT 1",
        $hash
    ));
    if (!$row) return null;
    if ($row->used_at) return null;
    if (strtotime($row->expires_at) < time()) return null;

    // Mark used, one-shot.
    $wpdb->update(
        $wpdb->prefix . 'lmeg_magic_links',
        ['used_at' => current_time('mysql')],
        ['id' => $row->id]
    );
    return (int) $row->subscriber_id;
}

/* ---------------------------------------------------------------------------
 * Stripe API — checkout, portal, webhook
 * ------------------------------------------------------------------------- */

function lmeg_stripe_keys() {
    $s = lmeg_get_settings();
    if (($s['stripe_mode'] ?? 'test') === 'live') {
        return [
            'pk'   => $s['stripe_live_pk'],
            'sk'   => $s['stripe_live_sk'],
            'whs'  => $s['stripe_live_webhook_sec'],
            'mode' => 'live',
        ];
    }
    return [
        'pk'   => $s['stripe_test_pk'],
        'sk'   => $s['stripe_test_sk'],
        'whs'  => $s['stripe_test_webhook_sec'],
        'mode' => 'test',
    ];
}

function lmeg_stripe_request($method, $path, $params = []) {
    $keys = lmeg_stripe_keys();
    if (!$keys['sk']) return new WP_Error('lmeg_stripe_unconfigured', 'Stripe secret key not set.');
    $url  = 'https://api.stripe.com/v1' . $path;
    $args = [
        'method'  => $method,
        'timeout' => 20,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($keys['sk'] . ':'),
            'Stripe-Version' => '2024-06-20',
        ],
    ];
    if ($method === 'GET' && $params) {
        $url .= '?' . http_build_query($params);
    } elseif ($params) {
        $args['body'] = http_build_query($params);
    }
    $resp = wp_remote_request($url, $args);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true) ?: [];
    if ($code < 200 || $code >= 300) {
        return new WP_Error('lmeg_stripe_http_' . $code, $body['error']['message'] ?? 'Stripe error');
    }
    return $body;
}

function lmeg_stripe_create_checkout($sub, $tier, $interval) {
    $price_id = $interval === 'annual' ? $tier->stripe_price_annual : $tier->stripe_price_monthly;
    if (!$price_id) return new WP_Error('lmeg_no_price', 'This tier has no Stripe price for that interval.');

    $success = add_query_arg([
        'lmeg_member' => 'return',
        'session_id'  => '{CHECKOUT_SESSION_ID}',
    ], home_url('/'));
    $cancel  = add_query_arg(['lmeg_member' => 'upgrade'], home_url('/'));

    $params = [
        'mode'                 => 'subscription',
        'customer_email'       => $sub->email,
        'line_items[0][price]' => $price_id,
        'line_items[0][quantity]' => 1,
        'success_url'          => $success,
        'cancel_url'           => $cancel,
        'metadata[sub_id]'     => $sub->id,
        'metadata[tier_id]'    => $tier->id,
        'metadata[interval]'   => $interval,
        'allow_promotion_codes' => 'true',
    ];
    if ($sub->stripe_customer_id) {
        $params['customer'] = $sub->stripe_customer_id;
        unset($params['customer_email']);
    }
    return lmeg_stripe_request('POST', '/checkout/sessions', $params);
}

function lmeg_stripe_customer_portal($sub) {
    if (!$sub->stripe_customer_id) return new WP_Error('lmeg_no_customer', 'No Stripe customer on file.');
    $return = add_query_arg(['lmeg_member' => 'account'], home_url('/'));
    return lmeg_stripe_request('POST', '/billing_portal/sessions', [
        'customer'   => $sub->stripe_customer_id,
        'return_url' => $return,
    ]);
}

/**
 * Constant-time verify of a Stripe webhook signature.
 * Format: t=timestamp,v1=hex_hmac  (see Stripe docs).
 */
function lmeg_stripe_verify_webhook($payload, $signature_header, $secret, $tolerance = 300) {
    if (!$signature_header || !$secret) return false;
    $ts = null; $sig = null;
    foreach (explode(',', $signature_header) as $part) {
        list($k, $v) = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't')  $ts = (int) $v;
        if ($k === 'v1') $sig = $v;
    }
    if (!$ts || !$sig) return false;
    if (abs(time() - $ts) > $tolerance) return false;
    $expected = hash_hmac('sha256', $ts . '.' . $payload, $secret);
    return hash_equals($expected, $sig);
}

function lmeg_stripe_handle_event($event) {
    global $wpdb;
    $event_id = $event['id'] ?? null;
    $type     = $event['type'] ?? '';
    if (!$event_id) return;

    // Idempotency — bail if already processed.
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT event_id FROM {$wpdb->prefix}lmeg_stripe_events WHERE event_id = %s",
        $event_id
    ));
    if ($exists) return;

    $obj = $event['data']['object'] ?? [];
    $sub_id = null;

    if ($type === 'checkout.session.completed') {
        $sub_id       = (int) ($obj['metadata']['sub_id']  ?? 0);
        $tier_id      = (int) ($obj['metadata']['tier_id'] ?? 0);
        $interval     = $obj['metadata']['interval'] ?? 'monthly';
        $customer_id  = $obj['customer']     ?? null;
        $subscription = $obj['subscription'] ?? null;
        if ($sub_id) {
            $wpdb->update(
                $wpdb->prefix . LMEG_TABLE,
                [
                    'member_tier_id'         => $tier_id,
                    'member_status'          => 'active',
                    'stripe_customer_id'     => $customer_id,
                    'stripe_subscription_id' => $subscription,
                    'billing_interval'       => $interval === 'annual' ? 'year' : 'month',
                    'member_expires_at'      => null,
                ],
                ['id' => $sub_id]
            );
            // Refresh channel:paid + tier:<slug> auto-tags.
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", $sub_id));
            if ($row) lmeg_apply_auto_tags($row);
        }
    } elseif ($type === 'customer.subscription.updated' || $type === 'customer.subscription.deleted') {
        $subscription_id = $obj['id'] ?? '';
        $status          = $obj['status'] ?? 'cancelled';
        $current_period_end = isset($obj['current_period_end']) ? (int) $obj['current_period_end'] : 0;
        $sub = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE stripe_subscription_id = %s",
            $subscription_id
        ));
        if ($sub) {
            $sub_id = (int) $sub->id;
            $new = ['member_status' => in_array($status, ['active', 'trialing'], true) ? 'active' : $status];
            if ($status === 'canceled' || $type === 'customer.subscription.deleted') {
                $new['member_status']     = 'cancelled';
                $new['member_expires_at'] = $current_period_end ? date('Y-m-d H:i:s', $current_period_end) : null;
                // Keep tier_id until expires_at passes — cron reverts them.
            }
            $wpdb->update($wpdb->prefix . LMEG_TABLE, $new, ['id' => $sub->id]);
            $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", $sub->id));
            if ($fresh) lmeg_apply_auto_tags($fresh);
        }
    }

    $wpdb->insert($wpdb->prefix . 'lmeg_stripe_events', [
        'event_id'      => $event_id,
        'event_type'    => $type,
        'subscriber_id' => $sub_id ?: null,
        'processed_at'  => current_time('mysql'),
    ]);
}

/**
 * Cron sweep — revert cancelled members whose grace period has expired.
 */
add_action('lmeg_broadcast_tick', 'lmeg_membership_expiry_sweep', 30);
function lmeg_membership_expiry_sweep() {
    global $wpdb;
    $now = current_time('mysql');
    // Snapshot who's about to revert so we can refresh their tags.
    $expired = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}" . LMEG_TABLE . "
         WHERE member_status = 'cancelled' AND member_expires_at IS NOT NULL AND member_expires_at <= %s",
        $now
    ));
    if (!$expired) return;
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}" . LMEG_TABLE . "
         SET member_tier_id = NULL, member_status = 'free', member_expires_at = NULL
         WHERE member_status = 'cancelled' AND member_expires_at IS NOT NULL AND member_expires_at <= %s",
        $now
    ));
    foreach ($expired as $sid) {
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", (int) $sid));
        if ($row) lmeg_apply_auto_tags($row);
    }
}

/* ---------------------------------------------------------------------------
 * Public endpoints — all served via ?lmeg_member=<action>
 * ------------------------------------------------------------------------- */

add_action('init', 'lmeg_member_router');
function lmeg_member_router() {
    $action = isset($_GET['lmeg_member']) ? sanitize_text_field($_GET['lmeg_member']) : '';
    if (!$action) return;

    switch ($action) {
        case 'signin':       return lmeg_render_full_page(lmeg_signin_form_html());
        case 'send-magic':   return lmeg_handle_send_magic();
        case 'verify':       return lmeg_handle_verify();
        case 'logout':       lmeg_clear_member_cookie(); wp_safe_redirect(home_url('/')); exit;
        case 'upgrade':      return lmeg_render_full_page(lmeg_upgrade_html());
        case 'checkout':     return lmeg_handle_checkout();
        case 'grant_free':   return lmeg_handle_grant_free();
        case 'return':       return lmeg_handle_stripe_return();
        case 'account':      return lmeg_render_full_page(lmeg_account_html());
        case 'portal':       return lmeg_handle_portal();
        case 'webhook':      return lmeg_handle_webhook();
    }
}

function lmeg_render_full_page($body_html) {
    get_header();
    echo '<main class="lmeg-member-page" style="max-width:520px;margin:3em auto;padding:0 1em;">' . $body_html . '</main>';
    get_footer();
    exit;
}

function lmeg_handle_send_magic() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_safe_redirect(add_query_arg('lmeg_member','signin', home_url('/'))); exit; }
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lmeg_signin')) {
        wp_die('Security check failed.', 'Error', ['response' => 403]);
    }
    lmeg_send_magic_link($_POST['email'] ?? '');
    lmeg_render_full_page(
        '<h1>Check your inbox</h1><p>If we have your address, a sign-in link is on its way. It expires in 24 hours.</p>' .
        '<p><a href="' . esc_url(home_url('/')) . '">← Back home</a></p>'
    );
}

function lmeg_handle_verify() {
    $token  = isset($_GET['t']) ? sanitize_text_field($_GET['t']) : '';
    $sub_id = lmeg_verify_magic_link($token);
    if (!$sub_id) {
        lmeg_render_full_page('<h1>Link expired</h1><p>This sign-in link is no longer valid. <a href="?lmeg_member=signin">Request a new one →</a></p>');
    }
    global $wpdb;
    $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", $sub_id));
    if (!$sub) exit;
    lmeg_set_member_cookie($sub->id, (int) $sub->member_tier_id);
    wp_safe_redirect(home_url('/'));
    exit;
}

function lmeg_handle_checkout() {
    if (!isset($_GET['tier']))     { wp_safe_redirect(add_query_arg('lmeg_member','upgrade', home_url('/'))); exit; }
    $tier_id  = (int) $_GET['tier'];
    $interval = ($_GET['interval'] ?? 'monthly') === 'annual' ? 'annual' : 'monthly';
    $member   = lmeg_current_member();
    if (!$member) {
        // Non-members should first opt in via the gate; bounce them there.
        lmeg_render_full_page(
            '<h1>Please sign in first</h1>' .
            '<p>To subscribe, sign in with your email so we can link the purchase to your account.</p>' .
            '<p><a href="?lmeg_member=signin" class="button">Sign in →</a></p>'
        );
    }
    $tier = lmeg_tier($tier_id);
    if (!$tier) { wp_safe_redirect(add_query_arg('lmeg_member','upgrade', home_url('/'))); exit; }

    $session = lmeg_stripe_create_checkout($member, $tier, $interval);
    if (is_wp_error($session)) {
        lmeg_render_full_page('<h1>Checkout unavailable</h1><p>' . esc_html($session->get_error_message()) . '</p>');
    }
    wp_safe_redirect(esc_url_raw($session['url']));
    exit;
}

function lmeg_handle_grant_free() {
    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
    if (!$post_id) { wp_safe_redirect(home_url('/')); exit; }
    check_admin_referer('lmeg_grant_' . $post_id);

    $member = lmeg_current_member();
    if (!$member) {
        wp_safe_redirect(add_query_arg('lmeg_member', 'signin', home_url('/')));
        exit;
    }
    // Only meaningful on soft-paid posts. Silently drop for anything else.
    if (lmeg_post_access_level($post_id) !== 'soft-paid') {
        wp_safe_redirect(get_permalink($post_id) ?: home_url('/'));
        exit;
    }

    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->prefix}lmeg_soft_grants (subscriber_id, post_id, granted_at)
         VALUES (%d, %d, %s)",
        (int) $member->id, (int) $post_id, current_time('mysql')
    ));

    wp_safe_redirect(get_permalink($post_id) ?: home_url('/'));
    exit;
}

function lmeg_handle_stripe_return() {
    // We trust the webhook for state — the return handler just refreshes cookie.
    $member = lmeg_current_member();
    if ($member) {
        // Refresh cookie in case tier just changed via webhook.
        global $wpdb;
        $fresh = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d",
            $member->id
        ));
        if ($fresh) lmeg_set_member_cookie($fresh->id, (int) $fresh->member_tier_id);
    }
    lmeg_render_full_page(
        '<h1>Thanks!</h1><p>You\'re all set. Your subscription is being activated — refresh any post if it still looks locked.</p>' .
        '<p><a href="' . esc_url(home_url('/')) . '">← Back home</a></p>'
    );
}

function lmeg_handle_portal() {
    $member = lmeg_current_member();
    if (!$member) { wp_safe_redirect(add_query_arg('lmeg_member','signin', home_url('/'))); exit; }
    $session = lmeg_stripe_customer_portal($member);
    if (is_wp_error($session)) {
        lmeg_render_full_page('<h1>Portal unavailable</h1><p>' . esc_html($session->get_error_message()) . '</p>');
    }
    wp_safe_redirect(esc_url_raw($session['url']));
    exit;
}

function lmeg_handle_webhook() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { status_header(405); exit; }
    $payload = file_get_contents('php://input');
    $sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $keys    = lmeg_stripe_keys();
    if (!lmeg_stripe_verify_webhook($payload, $sig, $keys['whs'])) {
        status_header(400);
        exit('Invalid signature.');
    }
    $event = json_decode($payload, true);
    if ($event) lmeg_stripe_handle_event($event);
    status_header(200);
    exit('ok');
}

/* ---------------------------------------------------------------------------
 * HTML fragments
 * ------------------------------------------------------------------------- */

function lmeg_signin_form_html() {
    $s = lmeg_get_settings();
    ob_start(); ?>
    <h1><?php echo esc_html($s['signin_heading']); ?></h1>
    <p><?php echo esc_html($s['signin_message']); ?></p>
    <form method="post" action="<?php echo esc_url(add_query_arg('lmeg_member', 'send-magic', home_url('/'))); ?>" class="lmeg-signin-form">
        <?php wp_nonce_field('lmeg_signin'); ?>
        <label for="lmeg-signin-email" style="display:block;font-weight:600;margin-bottom:.4em;">Email</label>
        <input type="email" name="email" id="lmeg-signin-email" required class="lmeg-input" placeholder="you@example.com" />
        <button type="submit" class="lmeg-button" style="margin-top:.6em;">Email me a sign-in link</button>
    </form>
    <?php return ob_get_clean();
}

/**
 * Unified paywall UI (v2.11): icon + heading + email/phone form + "Get
 * premium access" collapsible.
 *
 * All buttons submit the same form; the handler dispatches on `lmeg_after`:
 * `free` → become member (soft-grant applied if the post is soft-paid),
 * `checkout:<tier_id>:<interval>` → stripe checkout.
 */
function lmeg_paywall_html_v2($post_id) {
    $s       = lmeg_get_settings();
    $access  = lmeg_post_access_level($post_id);
    $is_soft = ($access === 'soft-paid');
    $tiers   = lmeg_all_tiers(true);
    $member  = lmeg_current_member();
    $countries = function_exists('lmeg_countries') ? lmeg_countries() : [];

    $heading      = $s['paywall_heading']       ?: ('Unlock ' . get_bloginfo('name'));
    $unlock_label = $s['paywall_unlock_label']  ?: 'Unlock';
    $premium_lbl  = $s['paywall_premium_label'] ?: 'Get premium access';

    $nonce   = wp_create_nonce('lmeg_submit');
    $action  = esc_url(admin_url('admin-post.php'));
    $field   = 'lmeg-pw-' . $post_id;
    $premium_open = ($member && !$is_soft && $access !== 'free') ? '' : ' hidden';

    ob_start(); ?>
    <div class="lmeg-paywall lmeg-paywall--v2<?php echo $is_soft ? ' lmeg-paywall--soft' : ''; ?>" role="region" aria-label="Unlock content">
        <div class="lmeg-paywall__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 9-3"></path>
            </svg>
        </div>
        <h2 class="lmeg-paywall__heading"><?php echo esc_html($heading); ?></h2>

        <form class="lmeg-form lmeg-paywall__form" method="post" action="<?php echo $action; ?>" novalidate>
            <input type="hidden" name="action"           value="lmeg_submit" />
            <input type="hidden" name="_wpnonce"         value="<?php echo esc_attr($nonce); ?>" />
            <input type="hidden" name="post_id"          value="<?php echo esc_attr($post_id); ?>" />
            <input type="hidden" name="redirect"         value="<?php echo esc_url(get_permalink($post_id) ?: home_url('/')); ?>" />
            <input type="hidden" name="contact_type"     value="email" />
            <input type="hidden" name="phone_country_iso" value="US" />

            <div class="lmeg-hp-wrap" aria-hidden="true">
                <label>Leave this empty<input type="text" name="lmeg_hp" value="" tabindex="-1" autocomplete="off" /></label>
            </div>

            <?php if (!$member) : ?>
                <div class="lmeg-tabs" role="tablist" aria-label="Contact method">
                    <button type="button" class="lmeg-tab is-active" role="tab" aria-selected="true"  data-channel="email">Email</button>
                    <button type="button" class="lmeg-tab"           role="tab" aria-selected="false" data-channel="phone">Phone</button>
                </div>

                <div class="lmeg-field lmeg-field-email">
                    <input type="email" id="<?php echo esc_attr($field); ?>-email" name="email" required autocomplete="email"
                           placeholder="you@example.com" class="lmeg-input" />
                </div>

                <div class="lmeg-field lmeg-field-phone" hidden>
                    <div class="lmeg-phone-row">
                        <select name="phone_country" class="lmeg-select" aria-label="Country">
                            <?php foreach ($countries as $c) :
                                $sel = ($c[0] === 'US') ? ' selected' : '';
                            ?>
                                <option value="<?php echo esc_attr($c[0]); ?>" data-dial="<?php echo esc_attr($c[2]); ?>"<?php echo $sel; ?>>
                                    <?php echo esc_html(lmeg_flag_emoji($c[0]) . ' ' . $c[1] . ' (+' . $c[2] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="lmeg-dial" aria-hidden="true">+1</span>
                        <input type="tel" id="<?php echo esc_attr($field); ?>-phone" name="phone" inputmode="tel"
                               placeholder="555 123 4567" class="lmeg-input" autocomplete="tel-national" />
                    </div>
                </div>

                <button type="submit" name="lmeg_after" value="free" class="lmeg-button lmeg-paywall__unlock">
                    <?php echo esc_html($unlock_label); ?>
                </button>
            <?php else : ?>
                <p class="lmeg-paywall__member">
                    Signed in as <strong><?php echo esc_html($member->email ?: $member->phone); ?></strong>.
                    <a href="?lmeg_member=logout" style="opacity:.7;">Not you?</a>
                </p>
                <?php if ($is_soft) :
                    $grant_url = wp_nonce_url(
                        add_query_arg(['lmeg_member' => 'grant_free', 'post' => (int) $post_id], home_url('/')),
                        'lmeg_grant_' . (int) $post_id
                    );
                ?>
                    <a href="<?php echo esc_url($grant_url); ?>" class="lmeg-button lmeg-paywall__unlock">
                        Keep reading free →
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($tiers) : ?>
                <div class="lmeg-paywall__divider"><span>or</span></div>

                <button type="button"
                        class="lmeg-button lmeg-button--outline lmeg-paywall__premium-toggle"
                        aria-expanded="<?php echo $premium_open ? 'false' : 'true'; ?>"
                        data-target="lmeg-premium-<?php echo (int) $post_id; ?>">
                    <?php echo esc_html($premium_lbl); ?>
                </button>

                <div id="lmeg-premium-<?php echo (int) $post_id; ?>" class="lmeg-paywall__premium-panel"<?php echo $premium_open; ?>>
                    <div class="lmeg-tiers lmeg-tiers--grid">
                        <?php foreach ($tiers as $t) : ?>
                            <div class="lmeg-tier">
                                <div class="lmeg-tier__name"><?php echo esc_html($t->name); ?></div>
                                <?php if ($t->description) : ?>
                                    <p class="lmeg-tier__desc"><?php echo esc_html($t->description); ?></p>
                                <?php endif; ?>
                                <div class="lmeg-tier__prices">
                                    <?php if ($t->price_monthly) : ?>
                                        <button type="submit" name="lmeg_after" value="checkout:<?php echo (int) $t->id; ?>:monthly" class="lmeg-button lmeg-tier__cta">
                                            <span class="lmeg-tier__price"><?php echo esc_html(lmeg_format_price($t->price_monthly, $t->currency)); ?></span>
                                            <span class="lmeg-tier__period">/ month</span>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($t->price_annual) : ?>
                                        <button type="submit" name="lmeg_after" value="checkout:<?php echo (int) $t->id; ?>:annual" class="lmeg-button lmeg-button--outline lmeg-tier__cta">
                                            <span class="lmeg-tier__price"><?php echo esc_html(lmeg_format_price($t->price_annual, $t->currency)); ?></span>
                                            <span class="lmeg-tier__period">/ year</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <p class="lmeg-paywall__signin">
                Already subscribed? <a href="?lmeg_member=signin">Sign in →</a>
            </p>
        </form>
    </div>
    <script>
    (function () {
        var t = document.querySelector('.lmeg-paywall__premium-toggle[data-target="lmeg-premium-<?php echo (int) $post_id; ?>"]');
        if (!t) return;
        var panel = document.getElementById(t.dataset.target);
        if (!panel) return;
        t.addEventListener('click', function () {
            var open = !panel.hasAttribute('hidden');
            if (open) {
                panel.setAttribute('hidden', '');
                t.setAttribute('aria-expanded', 'false');
            } else {
                panel.removeAttribute('hidden');
                t.setAttribute('aria-expanded', 'true');
                panel.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Route to whichever paywall version is active. Keeping v1 (tier-first) here
 * as a fallback in case someone wants to swap back via filter.
 */
function lmeg_paywall_html($post_id) {
    $mode = apply_filters('lmeg_paywall_mode', 'v2', $post_id);
    return $mode === 'v1' ? lmeg_paywall_html_v1($post_id) : lmeg_paywall_html_v2($post_id);
}

function lmeg_paywall_html_v1($post_id) {
    $access  = lmeg_post_access_level($post_id);
    $is_soft = ($access === 'soft-paid');
    $tiers   = lmeg_all_tiers(true);
    $member  = lmeg_current_member();
    $s       = lmeg_get_settings();

    $heading = $is_soft ? ($s['soft_paywall_heading'] ?? 'Support what you love') : $s['upgrade_heading'];
    $message = $is_soft ? ($s['soft_paywall_message'] ?? 'This post is free to read — or become a paid member to support the work.') : $s['upgrade_message'];

    $nonce  = wp_create_nonce('lmeg_submit');
    $action = esc_url(admin_url('admin-post.php'));

    ob_start(); ?>
    <div class="lmeg-paywall<?php echo $is_soft ? ' lmeg-paywall--soft' : ''; ?>" role="region" aria-label="Subscribe to keep reading">
        <div class="lmeg-paywall__head">
            <h2 class="lmeg-paywall__heading"><?php echo esc_html($heading); ?></h2>
            <p class="lmeg-paywall__message"><?php echo esc_html($message); ?></p>
        </div>

        <form class="lmeg-paywall__form" method="post" action="<?php echo $action; ?>" novalidate>
            <input type="hidden" name="action"       value="lmeg_submit" />
            <input type="hidden" name="_wpnonce"     value="<?php echo esc_attr($nonce); ?>" />
            <input type="hidden" name="post_id"      value="<?php echo esc_attr($post_id); ?>" />
            <input type="hidden" name="redirect"     value="<?php echo esc_url(get_permalink($post_id) ?: home_url('/')); ?>" />
            <input type="hidden" name="contact_type" value="email" />

            <div class="lmeg-hp-wrap" aria-hidden="true">
                <label>Leave this empty<input type="text" name="lmeg_hp" value="" tabindex="-1" autocomplete="off" /></label>
            </div>

            <?php if (!$member) : ?>
                <div class="lmeg-paywall__email">
                    <label class="lmeg-label" for="lmeg-pw-email-<?php echo esc_attr($post_id); ?>">Your email</label>
                    <input type="email"
                           id="lmeg-pw-email-<?php echo esc_attr($post_id); ?>"
                           name="email"
                           required
                           autocomplete="email"
                           placeholder="you@example.com"
                           class="lmeg-input" />
                </div>
            <?php else : ?>
                <p class="lmeg-paywall__member">
                    Signed in as <strong><?php echo esc_html($member->email ?: $member->phone); ?></strong>.
                    <a href="?lmeg_member=logout" style="opacity:.7;">Not you?</a>
                </p>
            <?php endif; ?>

            <?php if (empty($tiers)) : ?>
                <p class="lmeg-paywall__empty"><em>No paid tiers configured yet.</em></p>
            <?php else : ?>
                <div class="lmeg-tiers lmeg-tiers--grid">
                    <?php foreach ($tiers as $t) : ?>
                        <div class="lmeg-tier">
                            <div class="lmeg-tier__name"><?php echo esc_html($t->name); ?></div>
                            <?php if ($t->description) : ?>
                                <p class="lmeg-tier__desc"><?php echo esc_html($t->description); ?></p>
                            <?php endif; ?>
                            <div class="lmeg-tier__prices">
                                <?php if ($t->price_monthly) : ?>
                                    <button type="submit"
                                            name="lmeg_after"
                                            value="checkout:<?php echo (int) $t->id; ?>:monthly"
                                            class="lmeg-button lmeg-tier__cta">
                                        <span class="lmeg-tier__price"><?php echo esc_html(lmeg_format_price($t->price_monthly, $t->currency)); ?></span>
                                        <span class="lmeg-tier__period">/ month</span>
                                    </button>
                                <?php endif; ?>
                                <?php if ($t->price_annual) : ?>
                                    <button type="submit"
                                            name="lmeg_after"
                                            value="checkout:<?php echo (int) $t->id; ?>:annual"
                                            class="lmeg-button lmeg-button--outline lmeg-tier__cta">
                                        <span class="lmeg-tier__price"><?php echo esc_html(lmeg_format_price($t->price_annual, $t->currency)); ?></span>
                                        <span class="lmeg-tier__period">/ year</span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!$member) : ?>
                <div class="lmeg-paywall__divider"><span>or</span></div>
                <button type="submit" name="lmeg_after" value="free" class="lmeg-button lmeg-button--ghost lmeg-paywall__free">
                    <?php echo $is_soft
                        ? esc_html__('Start free — this post included', 'loonymoon-email-gate')
                        : esc_html__("Start free — get our newsletter (this post stays paid)", 'loonymoon-email-gate'); ?>
                </button>
            <?php elseif ($is_soft) : ?>
                <?php
                $grant_url = wp_nonce_url(
                    add_query_arg(['lmeg_member' => 'grant_free', 'post' => (int) $post_id], home_url('/')),
                    'lmeg_grant_' . (int) $post_id
                );
                ?>
                <div class="lmeg-paywall__divider"><span>or</span></div>
                <a href="<?php echo esc_url($grant_url); ?>" class="lmeg-button lmeg-button--ghost lmeg-paywall__free">
                    Not right now — keep reading this one free →
                </a>
            <?php endif; ?>

            <p class="lmeg-paywall__signin">
                Already subscribed? <a href="?lmeg_member=signin">Sign in →</a>
            </p>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

function lmeg_upgrade_html($post_id = 0) {
    $s      = lmeg_get_settings();
    $tiers  = lmeg_all_tiers(true);
    $access = $post_id ? lmeg_post_access_level($post_id) : 'paid';
    $is_soft = ($access === 'soft-paid');
    $heading = $is_soft ? ($s['soft_paywall_heading'] ?? 'Support what you love') : $s['upgrade_heading'];
    $message = $is_soft ? ($s['soft_paywall_message'] ?? 'This post is free to read — or if you value the work, becoming a paid member keeps it going.') : $s['upgrade_message'];
    ob_start(); ?>
    <div class="lmeg-upgrade<?php echo $is_soft ? ' lmeg-upgrade--soft' : ''; ?>">
        <h2 style="margin-top:0;"><?php echo esc_html($heading); ?></h2>
        <p><?php echo esc_html($message); ?></p>
        <?php if (empty($tiers)) : ?>
            <p><em>No tiers configured yet.</em></p>
        <?php else : ?>
            <div class="lmeg-tiers">
                <?php foreach ($tiers as $t) :
                    $checkout = function ($interval) use ($t) {
                        return add_query_arg([
                            'lmeg_member' => 'checkout',
                            'tier'        => $t->id,
                            'interval'    => $interval,
                        ], home_url('/'));
                    };
                ?>
                    <div class="lmeg-tier">
                        <div class="lmeg-tier__name"><?php echo esc_html($t->name); ?></div>
                        <?php if ($t->description) : ?>
                            <div class="lmeg-tier__desc"><?php echo esc_html($t->description); ?></div>
                        <?php endif; ?>
                        <div class="lmeg-tier__prices">
                            <?php if ($t->price_monthly) : ?>
                                <a class="lmeg-button" href="<?php echo esc_url($checkout('monthly')); ?>">
                                    <?php echo esc_html(lmeg_format_price($t->price_monthly, $t->currency)); ?> / month
                                </a>
                            <?php endif; ?>
                            <?php if ($t->price_annual) : ?>
                                <a class="lmeg-button lmeg-button--outline" href="<?php echo esc_url($checkout('annual')); ?>">
                                    <?php echo esc_html(lmeg_format_price($t->price_annual, $t->currency)); ?> / year
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($is_soft && $post_id) :
            $grant_url = wp_nonce_url(
                add_query_arg([
                    'lmeg_member' => 'grant_free',
                    'post'        => (int) $post_id,
                ], home_url('/')),
                'lmeg_grant_' . (int) $post_id
            );
        ?>
            <p class="lmeg-soft-decline" style="margin-top:1.4em;">
                <a href="<?php echo esc_url($grant_url); ?>">Not right now — keep reading this one free →</a>
            </p>
        <?php endif; ?>

        <p style="margin-top:1.5em;font-size:.9em;opacity:.7;">
            Already subscribed? <a href="?lmeg_member=signin">Sign in →</a>
        </p>
    </div>
    <?php return ob_get_clean();
}

function lmeg_account_html() {
    $member = lmeg_current_member();
    if (!$member) return lmeg_signin_form_html();
    $tier = $member->member_tier_id ? lmeg_tier($member->member_tier_id) : null;
    ob_start(); ?>
    <h1>Your account</h1>
    <p><strong>Email:</strong> <?php echo esc_html($member->email); ?></p>
    <p><strong>Status:</strong> <?php echo esc_html($member->member_status); ?></p>
    <p><strong>Plan:</strong> <?php echo $tier ? esc_html($tier->name) : 'Free'; ?></p>
    <?php if ($member->member_expires_at) : ?>
        <p><strong>Access ends:</strong> <?php echo esc_html($member->member_expires_at); ?></p>
    <?php endif; ?>
    <p style="margin-top:1.5em;">
        <?php if ($member->stripe_customer_id) : ?>
            <a class="lmeg-button" href="?lmeg_member=portal">Manage subscription</a>
        <?php else : ?>
            <a class="lmeg-button" href="?lmeg_member=upgrade">Upgrade</a>
        <?php endif; ?>
        &nbsp;
        <a href="?lmeg_member=logout">Sign out</a>
    </p>
    <?php return ob_get_clean();
}
