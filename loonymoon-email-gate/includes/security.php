<?php
/**
 * Abuse protection — rate limiting, email-domain sanity checks, and the
 * shared client-IP helper. All limits are tunable via filters; defaults
 * are generous for humans and hostile to scripts.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Best-effort client IP. Trusts the leftmost X-Forwarded-For entry only
 * when behind a proxy that always sets it (LiteSpeed/Cloudflare do).
 */
function lmeg_client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * Sliding-window counter on transients. Returns true if the action is
 * allowed (and consumes one slot), false when the bucket is exhausted.
 */
function lmeg_rate_limit($bucket, $max, $window_seconds) {
    $key  = 'lmeg_rl_' . md5($bucket);
    $now  = time();
    $data = get_transient($key);

    // Fixed window. The old version reset the TTL on every hit, so a busy
    // (or shared/CGNAT) IP that ever reached the cap stayed blocked forever
    // as long as traffic kept coming — silently dropping real signups.
    if (!is_array($data) || ($data['exp'] ?? 0) <= $now) {
        set_transient($key, ['n' => 1, 'exp' => $now + $window_seconds], $window_seconds);
        return true;
    }
    if ((int) $data['n'] >= $max) {
        return false;
    }
    $data['n'] = (int) $data['n'] + 1;
    // Keep the SAME expiry — do not extend the window on each hit.
    set_transient($key, $data, max(1, $data['exp'] - $now));
    return true;
}

/**
 * Cheap DNS sanity check on an email's domain — kills the obvious garbage
 * (typo'd or made-up domains) before we spend a Brevo send on it.
 * Results cached for a day. DNS failure (timeout) fails OPEN so a flaky
 * resolver can't block real signups.
 */
function lmeg_email_domain_ok($email) {
    $at = strrpos((string) $email, '@');
    if ($at === false) return false;
    $domain = strtolower(substr($email, $at + 1));
    if (!$domain || strpos($domain, '.') === false) return false;

    // The big mailbox providers are never DNS-gated — a flaky host resolver
    // (checkdnsrr can misfire on managed hosting) must never block gmail etc.
    static $known = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.ca', 'ymail.com',
        'outlook.com', 'hotmail.com', 'hotmail.ca', 'live.com', 'live.ca', 'msn.com',
        'icloud.com', 'me.com', 'mac.com', 'aol.com',
        'proton.me', 'protonmail.com', 'gmx.com', 'gmx.net', 'mail.com', 'zoho.com',
    ];
    if (in_array($domain, $known, true)) return true;

    // Can't check → allow (fail OPEN, as intended). Never let DNS gate signups.
    if (!function_exists('checkdnsrr')) return true;

    $cache_key = 'lmeg_mx_' . md5($domain);
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached === 'ok';

    $ok = checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
    // Cache a positive for a day; cache a negative only briefly so a transient
    // resolver hiccup can't lock a real domain out for 24h.
    set_transient($cache_key, $ok ? 'ok' : 'bad', $ok ? DAY_IN_SECONDS : 20 * MINUTE_IN_SECONDS);
    return $ok;
}

/**
 * Record a rejected signup so the admin can see WHY captures fail (honeypot,
 * rate limit, bad nonce, invalid email). Keeps the last 100 in an option.
 */
function lmeg_log_signup_reject($reason, $email = '') {
    $log = get_option('lmeg_signup_rejects', []);
    if (!is_array($log)) $log = [];
    array_unshift($log, [
        't'      => current_time('mysql'),
        'reason' => (string) $reason,
        'email'  => substr((string) $email, 0, 190),
        'ip'     => substr(function_exists('lmeg_client_ip') ? lmeg_client_ip() : '', 0, 45),
    ]);
    update_option('lmeg_signup_rejects', array_slice($log, 0, 100), false);
}

function lmeg_recent_signup_rejects($limit = 30) {
    $log = get_option('lmeg_signup_rejects', []);
    return is_array($log) ? array_slice($log, 0, (int) $limit) : [];
}

/**
 * Signup throttle — call from the submit handler. Dies with 429 when the
 * network is hammering the endpoint.
 */
function lmeg_guard_signup($email = '') {
    $ip = lmeg_client_ip();
    // Higher than before: the old 5/10min + 30/day per IP silently blocked
    // legit fans sharing an IP (mobile carrier CGNAT, offices, venue wifi) and
    // any burst of real signups after a post/drop. Still enough to stop floods.
    $burst = (int) apply_filters('lmeg_signup_burst_limit', 15);    // per 10 min
    $daily = (int) apply_filters('lmeg_signup_daily_limit', 300);   // per day

    if (!lmeg_rate_limit('signup10_' . $ip, $burst, 10 * MINUTE_IN_SECONDS)
        || !lmeg_rate_limit('signupday_' . $ip, $daily, DAY_IN_SECONDS)) {
        lmeg_log_signup_reject('rate_limit', $email);
        wp_die(
            'Too many signups from your network — please try again a little later.',
            'Slow down',
            ['response' => 429]
        );
    }
}

/**
 * Cap tracking-event inserts per (broadcast, subscriber, type) per day so a
 * leaked pixel/click URL can't be replayed into table bloat.
 */
function lmeg_track_event_allowed($broadcast_id, $subscriber_id, $type) {
    $cap = (int) apply_filters('lmeg_track_event_daily_cap', 50);
    return lmeg_rate_limit(
        'trk_' . $broadcast_id . '_' . $subscriber_id . '_' . $type,
        $cap,
        DAY_IN_SECONDS
    );
}

/* ---------------------------------------------------------------------------
 * wp-login brute-force protection
 *
 * Counts failed logins per IP and per username; once over the limit, the
 * authenticate filter refuses further attempts for the lockout window —
 * BEFORE password checking, so credential-stuffing runs burn out fast.
 * Successful login clears the counters for that IP.
 *
 * Tunables:
 *   lmeg_login_ip_limit    (default 10 fails / 15 min per IP)
 *   lmeg_login_user_limit  (default 5 fails / 15 min per username)
 * ------------------------------------------------------------------------- */

const LMEG_LOGIN_WINDOW = 15 * MINUTE_IN_SECONDS;

function lmeg_login_fail_key($kind, $val) {
    return 'lmeg_lf_' . $kind . '_' . md5(strtolower((string) $val));
}

add_action('wp_login_failed', 'lmeg_note_login_failure');
function lmeg_note_login_failure($username) {
    foreach ([
        lmeg_login_fail_key('ip', lmeg_client_ip()),
        lmeg_login_fail_key('user', $username),
    ] as $key) {
        $n = (int) get_transient($key);
        set_transient($key, $n + 1, LMEG_LOGIN_WINDOW);
    }
}

add_filter('authenticate', 'lmeg_block_locked_logins', 5, 3);
function lmeg_block_locked_logins($user, $username, $password) {
    if (empty($username)) return $user;

    $ip_limit   = (int) apply_filters('lmeg_login_ip_limit', 10);
    $user_limit = (int) apply_filters('lmeg_login_user_limit', 5);

    $ip_fails   = (int) get_transient(lmeg_login_fail_key('ip', lmeg_client_ip()));
    $user_fails = (int) get_transient(lmeg_login_fail_key('user', $username));

    if ($ip_fails >= $ip_limit || $user_fails >= $user_limit) {
        return new WP_Error(
            'lmeg_locked_out',
            'Too many failed login attempts. Please wait 15 minutes and try again.'
        );
    }
    return $user;
}

add_action('wp_login', 'lmeg_clear_login_failures', 10, 1);
function lmeg_clear_login_failures($username) {
    delete_transient(lmeg_login_fail_key('ip', lmeg_client_ip()));
    delete_transient(lmeg_login_fail_key('user', $username));
}
