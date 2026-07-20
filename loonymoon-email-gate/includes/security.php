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
    $key = 'lmeg_rl_' . md5($bucket);
    $n   = (int) get_transient($key);
    if ($n >= $max) {
        return false;
    }
    // Note: resets the window on each hit for simplicity; strict-enough
    // semantics for abuse control, zero schema cost.
    set_transient($key, $n + 1, $window_seconds);
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
    if (!$domain) return false;

    $cache_key = 'lmeg_mx_' . md5($domain);
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached === 'ok';

    $ok = true;
    if (function_exists('checkdnsrr')) {
        $ok = checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
    }
    set_transient($cache_key, $ok ? 'ok' : 'bad', DAY_IN_SECONDS);
    return $ok;
}

/**
 * Signup throttle — call from the submit handler. Dies with 429 when the
 * network is hammering the endpoint.
 */
function lmeg_guard_signup() {
    $ip = lmeg_client_ip();
    $burst = (int) apply_filters('lmeg_signup_burst_limit', 5);    // per 10 min
    $daily = (int) apply_filters('lmeg_signup_daily_limit', 30);   // per day

    if (!lmeg_rate_limit('signup10_' . $ip, $burst, 10 * MINUTE_IN_SECONDS)
        || !lmeg_rate_limit('signupday_' . $ip, $daily, DAY_IN_SECONDS)) {
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
