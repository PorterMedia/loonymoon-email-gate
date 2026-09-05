<?php
/**
 * TikTok — Login Kit + Display API (v2).
 *
 * The artist connects their own TikTok account (same idea as the Instagram
 * connection) and we read what the open API allows: their profile stats
 * (followers, total likes, video count) and their videos (cover, views, likes,
 * comments, shares, caption). That powers a TikTok section on Social Listening —
 * a follower card and a "Top TikTok posts" grid — reusing the shared post-card
 * renderer.
 *
 * HONEST LIMITS (unlike Instagram): the open Display API does NOT expose
 * follower demographics (age/gender/country), individual comment text, or
 * sound-usage counts. Those need TikTok's gated Research/Business tiers or a
 * paid data partnership, so they're out of scope here.
 *
 * Setup: create an app at developers.tiktok.com, add Login Kit, request the
 * user.info.* + video.list scopes, and paste the Client Key + Secret. The
 * redirect URI below must be listed in the app's Login Kit settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('LMEG_TIKTOK_AUTH'))  define('LMEG_TIKTOK_AUTH',  'https://www.tiktok.com/v2/auth/authorize/');
if (!defined('LMEG_TIKTOK_API'))   define('LMEG_TIKTOK_API',   'https://open.tiktokapis.com/v2');
if (!defined('LMEG_TIKTOK_SCOPE')) define('LMEG_TIKTOK_SCOPE',  'user.info.basic,user.info.profile,user.info.stats,video.list');

/* ---------------------------------------------------------------------------
 * Config + connection state
 * ------------------------------------------------------------------------- */

/** App credentials present (Client Key + Secret). */
function lmeg_tiktok_has_app() {
    $s = lmeg_get_settings();
    return !empty($s['tiktok_client_key']) && !empty($s['tiktok_client_secret']);
}

/** The stored connection (tokens + open_id), kept out of the settings blob. */
function lmeg_tiktok_conn() {
    $c = get_option('lmeg_tiktok_conn', []);
    return is_array($c) ? $c : [];
}

/** Fully connected: app creds + a usable refresh token + account id. */
function lmeg_tiktok_configured() {
    $c = lmeg_tiktok_conn();
    return lmeg_tiktok_has_app() && !empty($c['refresh_token']) && !empty($c['open_id']);
}

/** The single redirect URI used for both authorize + token-exchange legs. */
function lmeg_tiktok_redirect_uri() {
    return add_query_arg('lmeg_tt_oauth', 'callback', home_url('/'));
}

/* ---------------------------------------------------------------------------
 * OAuth (Login Kit v2)
 * ------------------------------------------------------------------------- */

add_action('admin_post_lmeg_tiktok_oauth_start', 'lmeg_tiktok_oauth_start');
function lmeg_tiktok_oauth_start() {
    if (!current_user_can('manage_options')) wp_die('Not allowed.');
    check_admin_referer('lmeg_tt_oauth');
    $back = admin_url('admin.php?page=lmeg-social');
    if (!lmeg_tiktok_has_app()) {
        wp_safe_redirect(add_query_arg('tt_err', 'noapp', $back));
        exit;
    }
    $s     = lmeg_get_settings();
    $state = wp_generate_password(24, false);
    set_transient('lmeg_tt_oauth_state', $state, 15 * MINUTE_IN_SECONDS);
    $url = LMEG_TIKTOK_AUTH . '?' . http_build_query([
        'client_key'    => $s['tiktok_client_key'],
        'scope'         => LMEG_TIKTOK_SCOPE,
        'response_type' => 'code',
        'redirect_uri'  => lmeg_tiktok_redirect_uri(),
        'state'         => $state,
    ]);
    wp_redirect($url);
    exit;
}

add_action('init', 'lmeg_tiktok_maybe_oauth_callback');
function lmeg_tiktok_maybe_oauth_callback() {
    if (($_GET['lmeg_tt_oauth'] ?? '') !== 'callback') return;
    $back  = admin_url('admin.php?page=lmeg-social');
    $state = get_transient('lmeg_tt_oauth_state');
    delete_transient('lmeg_tt_oauth_state');

    if (!current_user_can('manage_options')
        || empty($_GET['state']) || !$state
        || !hash_equals((string) $state, (string) wp_unslash($_GET['state']))) {
        wp_safe_redirect(add_query_arg('tt_err', 'state', $back));
        exit;
    }
    if (!empty($_GET['error'])) {
        set_transient('lmeg_tt_oauth_msg', sanitize_text_field(wp_unslash($_GET['error_description'] ?? $_GET['error'])), 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg('tt_err', 'denied', $back));
        exit;
    }
    if (empty($_GET['code'])) {
        wp_safe_redirect(add_query_arg('tt_err', 'nocode', $back));
        exit;
    }
    $r = lmeg_tiktok_token_exchange(sanitize_text_field(wp_unslash($_GET['code'])));
    if (is_wp_error($r)) {
        set_transient('lmeg_tt_oauth_msg', $r->get_error_message(), 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg('tt_err', 'exchange', $back));
        exit;
    }
    wp_safe_redirect(add_query_arg('tt_connected', '1', $back));
    exit;
}

/** Exchange the auth code for access + refresh tokens. */
function lmeg_tiktok_token_exchange($code) {
    $s    = lmeg_get_settings();
    $resp = wp_remote_post(LMEG_TIKTOK_API . '/oauth/token/', [
        'timeout' => 15,
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        'body'    => [
            'client_key'    => $s['tiktok_client_key'],
            'client_secret' => $s['tiktok_client_secret'],
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => lmeg_tiktok_redirect_uri(),
        ],
    ]);
    if (is_wp_error($resp)) return $resp;
    $d = json_decode(wp_remote_retrieve_body($resp), true);
    if (empty($d['access_token'])) {
        return new WP_Error('lmeg_tt', $d['error_description'] ?? ($d['error'] ?? 'TikTok token exchange failed.'));
    }
    lmeg_tiktok_store_tokens($d);
    return true;
}

/** Persist the token bundle (merging so a refresh keeps the refresh token). */
function lmeg_tiktok_store_tokens($d) {
    $c = lmeg_tiktok_conn();
    $c['access_token'] = (string) $d['access_token'];
    if (!empty($d['refresh_token'])) $c['refresh_token'] = (string) $d['refresh_token'];
    $c['open_id']    = (string) ($d['open_id'] ?? ($c['open_id'] ?? ''));
    $c['scope']      = (string) ($d['scope'] ?? ($c['scope'] ?? ''));
    $c['expires_at'] = time() + (int) ($d['expires_in'] ?? 86400) - 120;
    if (!empty($d['refresh_expires_in'])) $c['refresh_expires_at'] = time() + (int) $d['refresh_expires_in'];
    update_option('lmeg_tiktok_conn', $c, false);
}

/** Refresh the access token using the stored refresh token. */
function lmeg_tiktok_refresh() {
    $s = lmeg_get_settings();
    $c = lmeg_tiktok_conn();
    if (empty($c['refresh_token'])) return new WP_Error('lmeg_tt', 'No TikTok refresh token — reconnect.');
    $resp = wp_remote_post(LMEG_TIKTOK_API . '/oauth/token/', [
        'timeout' => 15,
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        'body'    => [
            'client_key'    => $s['tiktok_client_key'],
            'client_secret' => $s['tiktok_client_secret'],
            'grant_type'    => 'refresh_token',
            'refresh_token' => $c['refresh_token'],
        ],
    ]);
    if (is_wp_error($resp)) return $resp;
    $d = json_decode(wp_remote_retrieve_body($resp), true);
    if (empty($d['access_token'])) return new WP_Error('lmeg_tt', $d['error_description'] ?? 'TikTok token refresh failed.');
    lmeg_tiktok_store_tokens($d);
    return true;
}

/** A currently-valid access token (refreshing on the fly), or '' if none. */
function lmeg_tiktok_access_token() {
    $c = lmeg_tiktok_conn();
    if (empty($c['access_token'])) return '';
    if (!empty($c['expires_at']) && time() >= (int) $c['expires_at']) {
        $r = lmeg_tiktok_refresh();
        if (is_wp_error($r)) return '';
        $c = lmeg_tiktok_conn();
    }
    return (string) ($c['access_token'] ?? '');
}

function lmeg_tiktok_disconnect() {
    delete_option('lmeg_tiktok_conn');
    delete_transient('lmeg_tiktok_user');
    delete_transient('lmeg_tiktok_videos');
}

add_action('admin_post_lmeg_tiktok_disconnect', function () {
    if (!current_user_can('manage_options')) wp_die('Not allowed.');
    check_admin_referer('lmeg_tt_disc');
    lmeg_tiktok_disconnect();
    wp_safe_redirect(add_query_arg('tt_disc', '1', admin_url('admin.php?page=lmeg-social')));
    exit;
});

/** Save the Client Key + Secret (its own tiny form, not the big settings blob). */
add_action('admin_post_lmeg_tiktok_save_app', function () {
    if (!current_user_can('manage_options')) wp_die('Not allowed.');
    check_admin_referer('lmeg_tt_app');
    $opts = get_option(LMEG_OPTION, []);
    if (!is_array($opts)) $opts = [];
    $opts['tiktok_client_key']    = sanitize_text_field(wp_unslash($_POST['tiktok_client_key'] ?? ''));
    $opts['tiktok_client_secret'] = sanitize_text_field(wp_unslash($_POST['tiktok_client_secret'] ?? ''));
    update_option(LMEG_OPTION, $opts);
    wp_safe_redirect(add_query_arg('tt_saved', '1', admin_url('admin.php?page=lmeg-social')));
    exit;
});

/* ---------------------------------------------------------------------------
 * Display API reads
 * ------------------------------------------------------------------------- */

/** Profile stats: display name, avatar, followers, total likes, video count. */
function lmeg_tiktok_user_info($force = false) {
    if (!lmeg_tiktok_configured()) return null;
    $cache = 'lmeg_tiktok_user';
    if (!$force) { $c = get_transient($cache); if (is_array($c)) return $c; }
    $tok = lmeg_tiktok_access_token();
    if (!$tok) return null;
    $resp = wp_remote_get(
        LMEG_TIKTOK_API . '/user/info/?fields=open_id,display_name,avatar_url,follower_count,likes_count,video_count',
        ['timeout' => 12, 'headers' => ['Authorization' => 'Bearer ' . $tok]]
    );
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) return null;
    $d = json_decode(wp_remote_retrieve_body($resp), true);
    $u = $d['data']['user'] ?? null;
    if (!is_array($u)) return null;
    $out = [
        'username'  => (string) ($u['display_name'] ?? ''),
        'avatar'    => (string) ($u['avatar_url'] ?? ''),
        'followers' => (int) ($u['follower_count'] ?? 0),
        'likes'     => (int) ($u['likes_count'] ?? 0),
        'videos'    => (int) ($u['video_count'] ?? 0),
    ];
    set_transient($cache, $out, HOUR_IN_SECONDS);
    return $out;
}

/**
 * The artist's videos, normalized to the same shape the post-card renderer and
 * Instagram/Facebook use (so lmeg_social_render_post_cards() just works).
 * Sorted most-viewed first.
 */
function lmeg_tiktok_videos($limit = 12, $force = false) {
    if (!lmeg_tiktok_configured()) return [];
    $cache = 'lmeg_tiktok_videos';
    if (!$force) { $c = get_transient($cache); if (is_array($c)) return $c; }
    $tok = lmeg_tiktok_access_token();
    if (!$tok) return [];
    $resp = wp_remote_post(
        LMEG_TIKTOK_API . '/video/list/?fields=id,title,video_description,cover_image_url,share_url,view_count,like_count,comment_count,share_count,create_time',
        [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['max_count' => min(20, max(1, (int) $limit))]),
        ]
    );
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) return [];
    $d   = json_decode(wp_remote_retrieve_body($resp), true);
    $out = [];
    foreach ((array) ($d['data']['videos'] ?? []) as $v) {
        $cap = (string) ($v['title'] ?? '');
        if ($cap === '') $cap = (string) ($v['video_description'] ?? '');
        $out[] = [
            'id'        => (string) ($v['id'] ?? ''),
            'caption'   => $cap,
            'thumb'     => (string) ($v['cover_image_url'] ?? ''),
            'permalink' => (string) ($v['share_url'] ?? ''),
            'timestamp' => !empty($v['create_time']) ? date('c', (int) $v['create_time']) : '',
            'likes'     => (int) ($v['like_count'] ?? 0),
            'comments'  => (int) ($v['comment_count'] ?? 0),
            'shares'    => (int) ($v['share_count'] ?? 0),
            'views'     => (int) ($v['view_count'] ?? 0),
            'type'      => 'VIDEO',
        ];
    }
    usort($out, function ($a, $b) { return $b['views'] <=> $a['views']; });
    $out = array_slice($out, 0, (int) $limit);
    set_transient($cache, $out, HOUR_IN_SECONDS);
    return $out;
}
