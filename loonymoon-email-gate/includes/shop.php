<?php
/**
 * Shopify shop connection — order sync + email-campaign revenue attribution.
 *
 * Model: orders are pulled from the Shopify Admin API on a cron cadence.
 * Each order's customer email is matched against lmeg_subscribers. If the
 * subscriber clicked a tracked broadcast link within the attribution window
 * before the purchase, the order's revenue is attributed to that broadcast
 * (last-click). Falls back to the most recent open, then to a plain
 * "subscriber" attribution (they're on the list but no campaign touch).
 *
 * This works with Buy Button embeds where UTMs don't survive into Shopify's
 * own order attribution — because the matching happens on OUR side from the
 * click/open events we already record.
 */

if (!defined('ABSPATH')) {
    exit;
}

const LMEG_SHOP_API_VERSION = '2024-07';
const LMEG_SHOP_SYNC_LOCK   = 'lmeg_shop_sync_lock';
const LMEG_SHOP_LAST_SYNC   = 'lmeg_shop_last_sync';

/* ---------------------------------------------------------------------------
 * Shopify Admin API client
 * ------------------------------------------------------------------------- */

function lmeg_shop_configured() {
    $s = lmeg_get_settings();
    if (empty($s['shopify_domain'])) return false;
    // Either a pasted static token (legacy admin-created custom apps) OR a
    // Client ID + Secret pair (dev-dashboard apps → client credentials grant).
    return !empty($s['shopify_admin_token'])
        || (!empty($s['shopify_client_id']) && !empty($s['shopify_client_secret']));
}

/**
 * Resolve an Admin API access token.
 *
 * Precedence:
 *   1. A manually pasted static token (shopify_admin_token) — legacy custom
 *      apps created in the store admin still hand these out.
 *   2. Client credentials grant — the dev-dashboard model. We exchange the
 *      app's Client ID + Secret at /admin/oauth/access_token for a token that
 *      is valid ~24h, and cache it (minus a safety buffer) so we only refresh
 *      about once a day. The app must be installed on the store you own.
 *
 * @return string|WP_Error token, or an error explaining what to fix.
 */
function lmeg_shop_access_token() {
    $s      = lmeg_get_settings();
    $domain = preg_replace('#^https?://#', '', trim($s['shopify_domain'] ?? ''));

    $manual = trim($s['shopify_admin_token'] ?? '');
    if ($manual !== '') return $manual;

    $client_id     = trim($s['shopify_client_id'] ?? '');
    $client_secret = trim($s['shopify_client_secret'] ?? '');
    if (!$domain || !$client_id || !$client_secret) {
        return new WP_Error('lmeg_shop_unconfigured', 'Shopify is not configured. Add a store domain plus either an Admin API access token or a Client ID + Secret.');
    }

    $cache_key = 'lmeg_shop_cc_token_' . md5($domain . '|' . $client_id);
    $cached = get_transient($cache_key);
    if ($cached) return $cached;

    $resp = wp_remote_post('https://' . $domain . '/admin/oauth/access_token', [
        'timeout' => 20,
        'body'    => [
            'grant_type'    => 'client_credentials',
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
        ],
    ]);
    if (is_wp_error($resp)) return $resp;

    $code = wp_remote_retrieve_response_code($resp);
    $raw  = wp_remote_retrieve_body($resp);
    $body = json_decode($raw, true);
    if ($code < 200 || $code >= 300 || empty($body['access_token'])) {
        // Surface Shopify's actual reason — the OAuth error shape varies.
        $detail = '';
        if (is_array($body)) {
            if (!empty($body['error_description']))  $detail = $body['error_description'];
            elseif (!empty($body['error']))          $detail = $body['error'];
            elseif (!empty($body['errors']))         $detail = is_string($body['errors']) ? $body['errors'] : wp_json_encode($body['errors']);
        }
        if ($detail === '') {
            $detail = 'HTTP ' . $code . ($raw ? ' — ' . substr(wp_strip_all_tags($raw), 0, 300) : '');
        }
        $hint = '';
        if (stripos($detail, 'shop_not_permitted') !== false || stripos($detail, 'cannot be performed') !== false) {
            $hint = ' NOTE: the client-credentials grant only works on Shopify development stores, not live/paid stores. A live store needs the OAuth connect flow instead.';
        }
        return new WP_Error('lmeg_shop_token', 'Could not get a Shopify token from the Client ID/Secret: ' . $detail . '.' . $hint);
    }

    $token = (string) $body['access_token'];
    $ttl   = (int) ($body['expires_in'] ?? 3600);
    set_transient($cache_key, $token, max(60, $ttl - 300));
    return $token;
}

/* ---------------------------------------------------------------------------
 * OAuth connect (authorization code grant) — for LIVE/paid stores.
 *
 * The client-credentials grant only works on Shopify dev stores. A live store
 * you own connects with the standard authorization-code flow, which yields a
 * permanent OFFLINE access token (no 24h refresh). The store owner clicks
 * "Connect", approves in Shopify, and we exchange the returned code for a
 * token stored in shopify_admin_token (which lmeg_shop_access_token() prefers).
 *
 * Prereqs in the dev-dashboard app: custom distribution to this store, the
 * redirect URL below whitelisted, and the read_orders scope.
 * ------------------------------------------------------------------------- */

function lmeg_shop_oauth_redirect_uri() {
    if (get_option('permalink_structure')) {
        return home_url('/lmeg-shopify-oauth/');
    }
    return home_url('/?lmeg_shop_oauth=1');
}

add_filter('query_vars', function ($v) { $v[] = 'lmeg_shop_oauth'; return $v; });
add_action('init', function () {
    add_rewrite_rule('^lmeg-shopify-oauth/?$', 'index.php?lmeg_shop_oauth=1', 'top');
    if (get_option('lmeg_shop_oauth_flushed') !== '1') {
        flush_rewrite_rules(false);
        update_option('lmeg_shop_oauth_flushed', '1', false);
    }
});

add_action('admin_post_lmeg_shop_oauth_start', 'lmeg_shop_oauth_start');
function lmeg_shop_oauth_start() {
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
    check_admin_referer('lmeg_shop_oauth');

    $s      = lmeg_get_settings();
    $domain = preg_replace('#^https?://#', '', trim($s['shopify_domain'] ?? ''));
    $cid    = trim($s['shopify_client_id'] ?? '');
    if (!$domain || !$cid) {
        wp_die('Add your store domain and Client ID in Settings first, then click Connect.', 'Shopify', ['back_link' => true]);
    }

    $state = wp_generate_password(24, false);
    set_transient('lmeg_shop_oauth_state_' . get_current_user_id(), $state, 900);

    $url = 'https://' . $domain . '/admin/oauth/authorize?' . http_build_query([
        'client_id'    => $cid,
        'scope'        => 'read_orders,read_customers',
        'redirect_uri' => lmeg_shop_oauth_redirect_uri(),
        'state'        => $state,
    ]);
    wp_redirect($url); // external URL — wp_redirect, not wp_safe_redirect
    exit;
}

add_action('template_redirect', 'lmeg_shop_oauth_callback', 1);
function lmeg_shop_oauth_callback() {
    if (!get_query_var('lmeg_shop_oauth') && empty($_GET['lmeg_shop_oauth'])) return;

    $settings_url = admin_url('admin.php?page=lmeg-settings');
    if (!current_user_can('manage_options')) {
        wp_die('Log in as an administrator to finish connecting Shopify.', 'Shopify', ['response' => 403]);
    }

    $s      = lmeg_get_settings();
    $domain = preg_replace('#^https?://#', '', trim($s['shopify_domain'] ?? ''));
    $cid    = trim($s['shopify_client_id'] ?? '');
    $secret = trim($s['shopify_client_secret'] ?? '');

    $shop  = isset($_GET['shop'])  ? preg_replace('#^https?://#', '', sanitize_text_field(wp_unslash($_GET['shop'])))  : '';
    $code  = isset($_GET['code'])  ? sanitize_text_field(wp_unslash($_GET['code']))  : '';
    $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';

    $key      = 'lmeg_shop_oauth_state_' . get_current_user_id();
    $expected = get_transient($key);
    delete_transient($key);

    $fail = function ($msg) use ($settings_url) {
        wp_die('Shopify connect failed: ' . esc_html($msg) . ' <a href="' . esc_url($settings_url) . '">Back to Settings</a>',
            'Shopify', ['response' => 400]);
    };

    if (!$code) $fail('no authorization code in the callback — try the install link again.');
    if (!$secret || !$cid) $fail('missing Client ID/Secret — add them in Settings first.');
    if ($shop && $domain && strtolower($shop) !== strtolower($domain)) $fail('shop mismatch (' . $shop . ' vs ' . $domain . ').');
    // Shopify signs every OAuth callback with the app secret — that HMAC is the
    // REAL authentication, so it's required. The CSRF "state" we set during the
    // "Connect" button flow is an extra guard for that path, but a custom-
    // distribution app's own signed install link legitimately arrives WITHOUT
    // it. So: always verify the HMAC; verify state only when one is present.
    if (!lmeg_shop_oauth_verify_hmac(wp_unslash($_GET), $secret)) $fail('signature verification failed — the callback was not properly signed by Shopify.');
    if ($state && $expected && !hash_equals((string) $expected, $state)) $fail('security state mismatch — start Connect again from Settings.');

    $exchange_shop = $shop ?: $domain;
    $resp = wp_remote_post('https://' . $exchange_shop . '/admin/oauth/access_token', [
        'timeout' => 20,
        'body'    => ['client_id' => $cid, 'client_secret' => $secret, 'code' => $code],
    ]);
    if (is_wp_error($resp)) $fail($resp->get_error_message());

    $body  = json_decode(wp_remote_retrieve_body($resp), true);
    $token = is_array($body) && !empty($body['access_token']) ? $body['access_token'] : '';
    if (!$token) $fail('no access token returned (HTTP ' . wp_remote_retrieve_response_code($resp) . ').');

    $opts = get_option(LMEG_OPTION, []);
    if (!is_array($opts)) $opts = [];
    $opts['shopify_admin_token'] = $token;
    if (empty($opts['shopify_domain']) && $shop) $opts['shopify_domain'] = $shop;
    update_option(LMEG_OPTION, $opts);
    if (function_exists('lmeg_shop_flush_token')) lmeg_shop_flush_token();

    wp_safe_redirect(add_query_arg('shop_connected', '1', $settings_url));
    exit;
}

/**
 * Verify Shopify's HMAC on the OAuth callback. Message = query params minus
 * hmac (and our own routing param), sorted by key, joined key=value with &.
 */
function lmeg_shop_oauth_verify_hmac($params, $secret) {
    if (empty($params['hmac']) || $secret === '') return false;
    $provided = (string) $params['hmac'];
    unset($params['hmac'], $params['lmeg_shop_oauth']);
    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        if (is_array($v)) continue;
        $pairs[] = $k . '=' . $v;
    }
    $computed = hash_hmac('sha256', implode('&', $pairs), $secret);
    return hash_equals($computed, $provided);
}

/** Drop any cached client-credentials token so the next call re-fetches. */
function lmeg_shop_flush_token() {
    $s      = lmeg_get_settings();
    $domain = preg_replace('#^https?://#', '', trim($s['shopify_domain'] ?? ''));
    $cid    = trim($s['shopify_client_id'] ?? '');
    if ($domain && $cid) {
        delete_transient('lmeg_shop_cc_token_' . md5($domain . '|' . $cid));
    }
}

function lmeg_shop_request($path, $query = []) {
    $s      = lmeg_get_settings();
    $domain = preg_replace('#^https?://#', '', trim($s['shopify_domain'] ?? ''));
    if (!$domain) {
        return new WP_Error('lmeg_shop_unconfigured', 'Shopify store domain is not set.');
    }
    $token = lmeg_shop_access_token();
    if (is_wp_error($token)) return $token;

    $url = 'https://' . $domain . '/admin/api/' . LMEG_SHOP_API_VERSION . $path;
    if ($query) {
        $url = add_query_arg($query, $url);
    }
    $resp = wp_remote_get($url, [
        'timeout' => 20,
        'headers' => [
            'X-Shopify-Access-Token' => $token,
            'Accept'                 => 'application/json',
        ],
    ]);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($body) && isset($body['errors']) ? wp_json_encode($body['errors']) : 'HTTP ' . $code;
        return new WP_Error('lmeg_shop_http_' . $code, 'Shopify returned ' . $code . ': ' . $msg);
    }
    return is_array($body) ? $body : [];
}

/**
 * What scopes the current token actually holds. This is the definitive check:
 * a 403 "requires merchant approval for read_orders" means the token simply
 * doesn't carry read_orders, regardless of what the app appears to request.
 *
 * @return array|WP_Error list of granted scope handles.
 */
function lmeg_shop_granted_scopes() {
    $s      = lmeg_get_settings();
    $domain = preg_replace('#^https?://#', '', trim($s['shopify_domain'] ?? ''));
    if (!$domain) return new WP_Error('lmeg_shop_unconfigured', 'No store domain.');
    $token = lmeg_shop_access_token();
    if (is_wp_error($token)) return $token;

    // Note: the access-scopes endpoint lives under /admin/oauth/, not the
    // versioned Admin API path, so we call it directly.
    $resp = wp_remote_get('https://' . $domain . '/admin/oauth/access_scopes.json', [
        'timeout' => 20,
        'headers' => ['X-Shopify-Access-Token' => $token, 'Accept' => 'application/json'],
    ]);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('lmeg_scopes_http_' . $code, 'HTTP ' . $code);
    }
    $handles = [];
    foreach ((array) ($body['access_scopes'] ?? []) as $sc) {
        if (!empty($sc['handle'])) $handles[] = $sc['handle'];
    }
    return $handles;
}

/**
 * Verify credentials — GET /shop.json, returns friendly string or WP_Error.
 * Also reports the token's granted scopes so a missing read_orders (the usual
 * cause of the 403 order-sync error) is obvious.
 */
function lmeg_shop_verify() {
    $r = lmeg_shop_request('/shop.json');
    if (is_wp_error($r)) return $r;
    $name = $r['shop']['name']   ?? '(unknown)';
    $dom  = $r['shop']['domain'] ?? '';
    $msg  = 'Connected to "' . $name . '"' . ($dom ? ' (' . $dom . ')' : '') . '.';

    $scopes = lmeg_shop_granted_scopes();
    if (!is_wp_error($scopes)) {
        $msg .= ' Token scopes: ' . (empty($scopes) ? '(none)' : implode(', ', $scopes)) . '.';
        if (!in_array('read_orders', (array) $scopes, true)) {
            $msg .= ' ⚠ read_orders is NOT in this token — that is exactly why order sync returns 403. '
                  . 'The scope must be declared on the Shopify app AND a fresh token minted (reconnect) after that.';
        }
    }
    return $msg;
}

/* ---------------------------------------------------------------------------
 * Order sync
 * ------------------------------------------------------------------------- */

/**
 * Pull recent orders and (re)attribute them. Runs from cron (throttled to
 * every 15 minutes) and from the manual "Sync now" button.
 *
 * @return array|WP_Error ['fetched' => n, 'attributed' => n]
 */
function lmeg_shop_sync($force = false) {
    global $wpdb;
    if (!lmeg_shop_configured()) {
        return new WP_Error('lmeg_shop_unconfigured', 'Shopify is not configured.');
    }

    if (!$force && get_site_transient(LMEG_SHOP_SYNC_LOCK)) {
        return ['fetched' => 0, 'attributed' => 0, 'skipped' => true];
    }
    set_site_transient(LMEG_SHOP_SYNC_LOCK, 1, 15 * MINUTE_IN_SECONDS);

    // Look back far enough to catch stragglers; first run pulls 30 days.
    $last = get_option(LMEG_SHOP_LAST_SYNC, '');
    $since_ts = $last ? (strtotime($last) - 2 * DAY_IN_SECONDS) : (time() - 30 * DAY_IN_SECONDS);

    $r = lmeg_shop_request('/orders.json', [
        'status'         => 'any',
        'limit'          => 250,
        'created_at_min' => gmdate('c', $since_ts),
        'fields'         => 'id,order_number,email,total_price,currency,created_at,cancelled_at,financial_status',
    ]);
    if (is_wp_error($r)) {
        delete_site_transient(LMEG_SHOP_SYNC_LOCK);
        return $r;
    }

    $orders = $r['orders'] ?? [];
    $tbl    = $wpdb->prefix . 'lmeg_shop_orders';
    $subs   = $wpdb->prefix . LMEG_TABLE;
    $now    = current_time('mysql');
    $attributed = 0;

    foreach ($orders as $o) {
        if (lmeg_shop_record_order($o)) $attributed++;
    }

    update_option(LMEG_SHOP_LAST_SYNC, $now, false);

    // Abandoned-cart recovery — best-effort; a failure here (e.g. protected
    // customer data not yet approved on the app) must not break order sync.
    $aband     = lmeg_shop_sync_abandoned();
    $triggered = is_wp_error($aband) ? 0 : (int) ($aband['triggered'] ?? 0);

    return ['fetched' => count($orders), 'attributed' => $attributed, 'cart_triggers' => $triggered];
}

/**
 * Match a Shopify buyer to a fan: email first, then phone (last 10 digits, so
 * formatting and country code don't matter). Returns a subscriber id or 0.
 */
function lmeg_shop_match_subscriber($email, $phone_raw) {
    global $wpdb;
    $subs  = $wpdb->prefix . LMEG_TABLE;
    $email = sanitize_email((string) $email);
    if ($email) {
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $subs WHERE email = %s", $email));
        if ($id) return $id;
    }
    $digits = preg_replace('/[^\d]/', '', (string) $phone_raw);
    if (strlen($digits) >= 10) {
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $subs WHERE phone IS NOT NULL AND phone <> '' AND RIGHT(REPLACE(phone, '+', ''), 10) = %s LIMIT 1",
            substr($digits, -10)
        ));
        if ($id) return $id;
    }
    return 0;
}

/**
 * Record ONE Shopify order (from the API sync OR the order webhook) — matches
 * it to a fan by email, attributes it to the broadcast they last clicked,
 * attaches the "customer" tag, and upserts it into lmeg_shop_orders.
 * Returns true if it got attributed to a broadcast.
 */
function lmeg_shop_record_order($o) {
    global $wpdb;
    $tbl  = $wpdb->prefix . 'lmeg_shop_orders';
    $subs = $wpdb->prefix . LMEG_TABLE;

    $oid = (int) ($o['id'] ?? 0);
    if (!$oid || !empty($o['cancelled_at'])) return false;

    // Webhook + API payloads spell the buyer email/phone a few different ways.
    $email = sanitize_email($o['email'] ?? ($o['contact_email'] ?? ($o['customer']['email'] ?? '')));
    $phone_raw = (string) ($o['phone']
        ?? ($o['customer']['phone']
        ?? ($o['billing_address']['phone']
        ?? ($o['shipping_address']['phone'] ?? ''))));
    $total = (int) round(((float) ($o['total_price'] ?? ($o['current_total_price'] ?? 0))) * 100);
    $ordered_local = !empty($o['created_at'])
        ? get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($o['created_at'])))
        : current_time('mysql');

    // Match the buyer to a fan (email, then phone — SMS-checkout orders carry
    // no email).
    $subscriber_id = lmeg_shop_match_subscriber($email, $phone_raw);
    $broadcast_id  = null;
    $attribution   = 'none';
    if ($subscriber_id) {
        $attribution = 'subscriber';
        list($broadcast_id, $attribution) = lmeg_shop_attribute_broadcast(
            (int) $subscriber_id, $ordered_local, $attribution
        );
        // "customer" auto-tag — first attach fires the post-purchase sequence.
        if (function_exists('lmeg_get_or_create_tag')) {
            $ct = lmeg_get_or_create_tag('customer', 'Customer', true, '#F59E0B');
            if ($ct) lmeg_attach_tag((int) $subscriber_id, $ct->id);
        }
    }

    $wpdb->query($wpdb->prepare(
        "INSERT INTO $tbl
            (shopify_order_id, order_number, email, phone, subscriber_id, broadcast_id, attribution, total_cents, currency, ordered_at, synced_at)
         VALUES (%d, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s)
         ON DUPLICATE KEY UPDATE
            email         = COALESCE(VALUES(email), email),
            phone         = COALESCE(VALUES(phone), phone),
            subscriber_id = VALUES(subscriber_id),
            broadcast_id  = VALUES(broadcast_id),
            attribution   = VALUES(attribution),
            total_cents   = VALUES(total_cents),
            synced_at     = VALUES(synced_at)",
        $oid,
        (string) ($o['order_number'] ?? ($o['name'] ?? '')),
        $email ?: null,
        $phone_raw !== '' ? $phone_raw : null,
        $subscriber_id ?: null,
        $broadcast_id ?: null,
        $attribution,
        $total,
        strtoupper(substr((string) ($o['currency'] ?? 'USD'), 0, 3)),
        $ordered_local,
        current_time('mysql')
    ));
    return $broadcast_id ? true : false;
}

/**
 * Backfill historical orders from a Shopify "Orders → Export" CSV. The webhook
 * is forward-only, so this fills in everything that happened before it. Each
 * order is matched to a fan by email/phone and deduped by order number against
 * what's already stored. No post-purchase sequences fire (this is bulk history,
 * not live orders) and revenue only counts money that was actually collected.
 * Returns a stats array.
 */
function lmeg_shop_import_csv($file) {
    global $wpdb;
    $tbl   = $wpdb->prefix . 'lmeg_shop_orders';
    $stats = ['imported' => 0, 'matched' => 0, 'skipped_dup' => 0, 'skipped_unpaid' => 0, 'revenue_cents' => 0, 'error' => ''];

    $fh = @fopen($file, 'r');
    if (!$fh) { $stats['error'] = 'Could not read the uploaded file.'; return $stats; }

    $header = fgetcsv($fh, 0, ",", "\"", "\\");
    if (!$header) { fclose($fh); $stats['error'] = 'The file looks empty.'; return $stats; }
    $col = [];
    foreach ($header as $i => $h) { $col[strtolower(trim((string) $h))] = $i; }
    if (!isset($col['name'])) {
        fclose($fh);
        $stats['error'] = 'This doesn’t look like a Shopify orders export (no “Name” column). In Shopify: Orders → Export → plain CSV.';
        return $stats;
    }
    $pick = function ($row, $names) use ($col) {
        foreach ((array) $names as $n) {
            if (isset($col[$n], $row[$col[$n]]) && $row[$col[$n]] !== '') return $row[$col[$n]];
        }
        return '';
    };

    $seen = [];
    $now  = current_time('mysql');
    while (($row = fgetcsv($fh, 0, ",", "\"", "\\")) !== false) {
        if (!is_array($row)) continue;
        $name = trim((string) ($row[$col['name']] ?? ''));
        if ($name === '' || isset($seen[$name])) continue;
        // Shopify writes order-level fields (Total, Email, …) only on the FIRST
        // row of each order; later rows are line items with a blank Total.
        $total_raw = $pick($row, ['total']);
        if ($total_raw === '') continue;
        $seen[$name] = true;

        // Only money actually collected (skip pending/authorized/voided and
        // fully refunded net-zero orders). An empty status is treated as paid
        // because some exports leave it blank for completed orders.
        $status = strtolower(trim((string) $pick($row, ['financial status'])));
        if (!in_array($status, ['paid', 'partially_paid', 'partially_refunded', ''], true)) {
            $stats['skipped_unpaid']++;
            continue;
        }
        $cents = (int) round(((float) preg_replace('/[^0-9.\-]/', '', (string) $total_raw)) * 100);
        if ($cents <= 0) { $stats['skipped_unpaid']++; continue; }

        $order_number = ltrim($name, '#');
        if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM $tbl WHERE order_number = %s LIMIT 1", $order_number))) {
            $stats['skipped_dup']++;
            continue;
        }

        $email    = sanitize_email((string) $pick($row, ['email']));
        $phone    = (string) $pick($row, ['phone', 'billing phone', 'shipping phone']);
        $created  = (string) $pick($row, ['created at', 'paid at']);
        $ordered  = $created ? get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($created))) : $now;
        $currency = strtoupper(substr((string) ($pick($row, ['currency']) ?: 'USD'), 0, 3));
        $sid      = lmeg_shop_match_subscriber($email, $phone);
        // Synthetic PK from the order number (real webhook order ids are 13+
        // digits, so small order numbers never collide with them).
        $syn = ctype_digit($order_number) ? (int) $order_number : (int) sprintf('%u', crc32($name));
        if ($syn <= 0) continue;

        $ok = $wpdb->query($wpdb->prepare(
            "INSERT INTO $tbl
                (shopify_order_id, order_number, email, phone, subscriber_id, broadcast_id, attribution, total_cents, currency, ordered_at, synced_at)
             VALUES (%d, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE order_number = VALUES(order_number)",
            $syn, $order_number, $email ?: null, $phone !== '' ? $phone : null,
            $sid ?: null, null, $sid ? 'subscriber' : 'none', $cents, $currency, $ordered, $now
        ));
        if ($ok !== false) {
            $stats['imported']++;
            $stats['revenue_cents'] += $cents;
            if ($sid) $stats['matched']++;
        }
    }
    fclose($fh);
    return $stats;
}

/* ---------------------------------------------------------------------------
 * Order webhook — the no-app path. The merchant adds a "Order creation"
 * webhook in Settings → Notifications pointing at the tokenized URL below;
 * Shopify then POSTs each new order here. No OAuth, no app, no token to
 * manage — works on any store (incl. ones locked to the dev dashboard).
 * ------------------------------------------------------------------------- */

/** Secret token that authenticates the order webhook (in the URL). */
function lmeg_shop_wh_token() {
    return substr(hash_hmac('sha256', 'shopify-order-webhook', lmeg_get_secret()), 0, 22);
}

/** The URL the merchant pastes into Shopify → Settings → Notifications → Webhooks. */
function lmeg_shop_wh_url() {
    return add_query_arg('lmeg_shopify_wh', lmeg_shop_wh_token(), home_url('/'));
}

add_action('init', 'lmeg_maybe_handle_shopify_webhook');
function lmeg_maybe_handle_shopify_webhook() {
    if (empty($_GET['lmeg_shopify_wh'])) return;
    if (!hash_equals(lmeg_shop_wh_token(), (string) wp_unslash($_GET['lmeg_shopify_wh']))) {
        status_header(403); exit;
    }
    $raw = file_get_contents('php://input');
    $o   = json_decode((string) $raw, true);
    // Shopify's "Order creation" topic posts a single order object.
    if (is_array($o) && !empty($o['id'])) {
        if (function_exists('lmeg_shop_record_order')) lmeg_shop_record_order($o);
        // Remember we're receiving live orders (drives the Settings status).
        update_option('lmeg_shop_wh_last', current_time('mysql'), false);
        // Diagnostic: record the payload's STRUCTURE only (no customer data) so
        // we can tell "no customer on the order" from "Shopify redacted the PII".
        update_option('lmeg_shop_wh_debug', wp_json_encode([
            'at'                   => current_time('mysql'),
            'email_empty'          => empty($o['email']),
            'customer_email_empty' => empty($o['customer']['email'] ?? null),
            'phone_empty'          => empty($o['phone']) && empty($o['customer']['phone'] ?? null) && empty($o['billing_address']['phone'] ?? null),
            'has_customer_key'     => array_key_exists('customer', $o),
            'top_keys'             => implode(', ', array_slice(array_keys($o), 0, 40)),
        ]), false);
    }
    status_header(200);
    echo 'ok';
    exit;
}

/* ---------------------------------------------------------------------------
 * Abandoned-cart recovery — pull abandoned checkouts, match to subscribers,
 * fire the event:abandoned-cart trigger (drives a recovery sequence), and
 * stop nudging anyone who has since converted.
 * ------------------------------------------------------------------------- */

function lmeg_shop_abandoned_tag_id() {
    if (!function_exists('lmeg_get_or_create_tag')) return 0;
    $t = lmeg_get_or_create_tag('event:abandoned-cart', 'Abandoned cart', true, '#ef4444');
    return $t ? (int) $t->id : 0;
}

/** The fan's most recent still-open abandoned-checkout recovery URL. */
function lmeg_fan_cart_url($subscriber_id) {
    global $wpdb;
    $url = $wpdb->get_var($wpdb->prepare(
        "SELECT recovery_url FROM {$wpdb->prefix}lmeg_shop_abandoned
         WHERE subscriber_id = %d AND recovered = 0 AND recovery_url IS NOT NULL AND recovery_url <> ''
         ORDER BY checkout_at DESC LIMIT 1",
        (int) $subscriber_id
    ));
    return $url ?: home_url('/');
}

function lmeg_shop_sync_abandoned() {
    global $wpdb;
    if (!lmeg_shop_configured()) return new WP_Error('lmeg_shop_unconfigured', 'Shopify is not configured.');

    $r = lmeg_shop_request('/checkouts.json', [
        'limit'          => 250,
        'created_at_min' => gmdate('c', time() - 14 * DAY_IN_SECONDS),
    ]);
    if (is_wp_error($r)) return $r;

    $checkouts = $r['checkouts'] ?? [];
    $tbl   = $wpdb->prefix . 'lmeg_shop_abandoned';
    $subs  = $wpdb->prefix . LMEG_TABLE;
    $now   = current_time('mysql');
    $triggered = 0;

    foreach ($checkouts as $c) {
        $cid = (int) ($c['id'] ?? 0);
        if (!$cid) continue;
        if (!empty($c['completed_at'])) { lmeg_shop_abandoned_mark_recovered($cid); continue; }

        $email = sanitize_email($c['email'] ?? '');
        $url   = esc_url_raw($c['abandoned_checkout_url'] ?? '');
        $total = (int) round(((float) ($c['total_price'] ?? 0)) * 100);
        $when  = !empty($c['created_at'])
            ? get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($c['created_at']))) : null;

        $sub_id = $email ? $wpdb->get_var($wpdb->prepare("SELECT id FROM $subs WHERE email = %s", $email)) : null;

        // Already converted? A completed order for this email at/after the checkout.
        $converted = ($email && $when) ? (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}lmeg_shop_orders WHERE email = %s AND ordered_at >= %s",
            $email, $when
        )) : 0;

        $existing  = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE checkout_id = %d", $cid));
        $recovered = $converted ? 1 : ($existing ? (int) $existing->recovered : 0);

        $wpdb->query($wpdb->prepare(
            "INSERT INTO $tbl (checkout_id, token, email, subscriber_id, recovery_url, total_cents, currency, checkout_at, recovered, tagged, synced_at)
             VALUES (%d,%s,%s,%s,%s,%d,%s,%s,%d,%d,%s)
             ON DUPLICATE KEY UPDATE
                email=VALUES(email), subscriber_id=VALUES(subscriber_id), recovery_url=VALUES(recovery_url),
                total_cents=VALUES(total_cents), recovered=VALUES(recovered), synced_at=VALUES(synced_at)",
            $cid, (string) ($c['token'] ?? ''), $email ?: null, $sub_id ?: null, $url ?: null,
            $total, strtoupper(substr((string) ($c['currency'] ?? 'USD'), 0, 3)), $when,
            $recovered, $existing ? (int) $existing->tagged : 0, $now
        ));

        if ($converted) { lmeg_shop_abandoned_mark_recovered($cid); continue; }

        // New, matched to a fan, not yet nudged → fire the recovery trigger.
        if ($sub_id && (!$existing || !$existing->tagged)) {
            $tag_id = lmeg_shop_abandoned_tag_id();
            if ($tag_id) {
                lmeg_attach_tag((int) $sub_id, $tag_id);
                $wpdb->update($tbl, ['tagged' => 1], ['checkout_id' => $cid]);
                $triggered++;
            }
        }
    }

    return ['checkouts' => count($checkouts), 'triggered' => $triggered];
}

/** Mark a cart recovered, and if the fan has no other open carts, stop the
 *  recovery flow and free the trigger tag so a future cart can re-fire it. */
function lmeg_shop_abandoned_mark_recovered($checkout_id) {
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_shop_abandoned';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE checkout_id = %d", (int) $checkout_id));
    if (!$row) return;
    if (!$row->recovered) $wpdb->update($tbl, ['recovered' => 1], ['checkout_id' => (int) $checkout_id]);
    if (!$row->subscriber_id) return;

    $open = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $tbl WHERE subscriber_id = %d AND recovered = 0", (int) $row->subscriber_id
    ));
    if ($open > 0) return;

    $tag_id = lmeg_shop_abandoned_tag_id();
    if ($tag_id && function_exists('lmeg_detach_tag')) lmeg_detach_tag((int) $row->subscriber_id, $tag_id);
    if ($tag_id) {
        $seq_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}lmeg_sequences WHERE trigger_tag_id = %d", $tag_id
        ));
        if ($seq_ids) {
            $in = implode(',', array_map('intval', $seq_ids));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}lmeg_sequence_enrollments SET status='cancelled', next_send_at=NULL
                 WHERE subscriber_id = %d AND status='active' AND sequence_id IN ($in)",
                (int) $row->subscriber_id
            ));
        }
    }
}

/** Abandoned-cart summary for the Shop Revenue page. */
function lmeg_shop_abandoned_stats() {
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_shop_abandoned';
    return $wpdb->get_row(
        "SELECT
            COALESCE(SUM(recovered = 0), 0) AS open_carts,
            COALESCE(SUM(recovered = 1), 0) AS recovered_carts,
            COALESCE(SUM(CASE WHEN recovered = 0 THEN total_cents ELSE 0 END), 0) AS open_value,
            COALESCE(SUM(CASE WHEN recovered = 1 THEN total_cents ELSE 0 END), 0) AS recovered_value
         FROM $tbl"
    );
}

/**
 * Last-click attribution: the most recent click event by this subscriber
 * within the attribution window before the order. Falls back to opens.
 *
 * @return array [broadcast_id|null, attribution string]
 */
function lmeg_shop_attribute_broadcast($subscriber_id, $ordered_at, $fallback) {
    global $wpdb;
    if (!$ordered_at) return [null, $fallback];

    $s      = lmeg_get_settings();
    $days   = max(1, (int) ($s['attribution_window_days'] ?? 7));
    $from   = date('Y-m-d H:i:s', strtotime($ordered_at) - $days * DAY_IN_SECONDS);
    $events = $wpdb->prefix . 'lmeg_broadcast_events';

    foreach (['click', 'open'] as $type) {
        $bid = $wpdb->get_var($wpdb->prepare(
            "SELECT broadcast_id FROM $events
             WHERE subscriber_id = %d AND event_type = %s
               AND created_at BETWEEN %s AND %s
             ORDER BY created_at DESC LIMIT 1",
            $subscriber_id, $type, $from, $ordered_at
        ));
        if ($bid) return [(int) $bid, $type];
    }
    return [null, $fallback];
}

/**
 * Cron: piggyback on the minute tick, self-throttled by the sync lock.
 */
add_action('lmeg_broadcast_tick', 'lmeg_shop_cron_sync', 40);
function lmeg_shop_cron_sync() {
    if (!lmeg_shop_configured()) return;
    lmeg_shop_sync(false);
}

/* ---------------------------------------------------------------------------
 * Revenue helpers for the admin UI
 * ------------------------------------------------------------------------- */

/**
 * Revenue attributed per broadcast — [broadcast_id => ['cents','orders']].
 */
function lmeg_shop_revenue_by_broadcast() {
    global $wpdb;
    $tbl  = $wpdb->prefix . 'lmeg_shop_orders';
    $rows = $wpdb->get_results(
        "SELECT broadcast_id, SUM(total_cents) AS cents, COUNT(*) AS orders
         FROM $tbl WHERE broadcast_id IS NOT NULL GROUP BY broadcast_id"
    );
    $out = [];
    foreach ((array) $rows as $r) {
        $out[(int) $r->broadcast_id] = ['cents' => (int) $r->cents, 'orders' => (int) $r->orders];
    }
    return $out;
}

function lmeg_shop_totals($days = 30) {
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_shop_orders';
    return $wpdb->get_row($wpdb->prepare(
        "SELECT
            COALESCE(SUM(total_cents), 0) AS all_cents,
            COALESCE(SUM(CASE WHEN subscriber_id IS NOT NULL THEN total_cents ELSE 0 END), 0) AS list_cents,
            COALESCE(SUM(CASE WHEN broadcast_id IS NOT NULL THEN total_cents ELSE 0 END), 0) AS campaign_cents,
            COUNT(*) AS all_orders,
            SUM(CASE WHEN subscriber_id IS NOT NULL THEN 1 ELSE 0 END) AS list_orders,
            SUM(CASE WHEN broadcast_id IS NOT NULL THEN 1 ELSE 0 END) AS campaign_orders
         FROM $tbl WHERE ordered_at >= DATE_SUB(%s, INTERVAL %d DAY)",
        current_time('mysql'), max(1, (int) $days)
    ));
}
