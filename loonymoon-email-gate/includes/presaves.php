<?php
/**
 * Fanloop Pre-Saves — real platform pre-saves for an upcoming release.
 *
 * A "pre-save" campaign lets fans commit an upcoming album/single to their music
 * library BEFORE release; on release day it's automatically added for them.
 *
 *   - Apple Music  : MusicKit — fan authorizes, we store a Music-User-Token and
 *                    POST the album to their library at release. Needs a MusicKit
 *                    key (.p8, ES256), Key ID, and Team ID.
 *   - Deezer       : OAuth (manage_library) — fan authorizes, we store an access
 *                    token and add the album to their favourites at release. Needs
 *                    a Deezer app (App ID + secret).
 *   - Amazon Music : no public auto-add API exists for third parties, so this is
 *                    an honest "reminder" pre-save: capture the fan + email them a
 *                    one-tap link on release day (never a silent auto-add).
 *
 * Spotify is deliberately absent — its API's Extended-Quota threshold blocks
 * pre-saves for a real fanbase (see the drops "owned audience" approach instead).
 *
 * This file is the ENGINE (data model + settings + tokens). The admin UI, the
 * fan-facing [fanloop_presave] widget, the connect endpoints and the release
 * runner arrive in following iterations. Everything is dev-guarded: no platform
 * credentials → that platform simply isn't offered.
 *
 * @package Fanloop
 */

if (!defined('ABSPATH') && !defined('LMEG_PRESAVE_STANDALONE')) return;

if (!defined('LMEG_PRESAVE_DB_VERSION')) define('LMEG_PRESAVE_DB_VERSION', '1');

/* -------------------------------------------------------------------------
 * Data model — campaigns + per-fan pre-save records (version-gated install).
 * ---------------------------------------------------------------------- */
add_action('init', 'lmeg_presave_maybe_install', 1);
function lmeg_presave_maybe_install() {
    if (get_option('lmeg_presave_db_version') === LMEG_PRESAVE_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset  = $wpdb->get_charset_collate();
    $camp     = $wpdb->prefix . 'lmeg_presave_campaigns';
    $saves    = $wpdb->prefix . 'lmeg_presaves';

    dbDelta("CREATE TABLE $camp (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(200) NOT NULL DEFAULT '',
        artist VARCHAR(200) NOT NULL DEFAULT '',
        release_date DATETIME DEFAULT NULL,
        apple_album_id VARCHAR(64) NOT NULL DEFAULT '',
        deezer_album_id VARCHAR(64) NOT NULL DEFAULT '',
        amazon_url VARCHAR(600) NOT NULL DEFAULT '',
        artwork_url VARCHAR(600) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        released_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        KEY status (status),
        KEY release_date (release_date)
    ) $charset;");

    dbDelta("CREATE TABLE $saves (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        campaign_id BIGINT UNSIGNED NOT NULL,
        subscriber_id BIGINT UNSIGNED DEFAULT NULL,
        platform VARCHAR(20) NOT NULL DEFAULT '',
        user_token TEXT DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        error VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME DEFAULT NULL,
        processed_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY campaign_sub_platform (campaign_id, subscriber_id, platform),
        KEY campaign_status (campaign_id, status),
        KEY platform (platform)
    ) $charset;");

    update_option('lmeg_presave_db_version', LMEG_PRESAVE_DB_VERSION);
}

/** Map a short name → full table name. */
function lmeg_presave_table($which) {
    global $wpdb;
    $map = ['campaigns' => 'lmeg_presave_campaigns', 'saves' => 'lmeg_presaves'];
    return $wpdb->prefix . ($map[$which] ?? 'lmeg_presaves');
}

/* -------------------------------------------------------------------------
 * Settings + readiness. Credentials live in the shared LMEG_OPTION blob.
 * ---------------------------------------------------------------------- */
function lmeg_presave_settings() {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    // Team ID is the same Apple account as Wallet — fall back to the Wallet value.
    $team = trim((string) ($s['presave_team_id'] ?? ''));
    if ($team === '') $team = trim((string) ($s['wallet_team_id'] ?? ''));
    return [
        // Apple Music (MusicKit)
        'apple_music_key'    => trim((string) ($s['presave_apple_music_key'] ?? '')),     // .p8 EC key (inline PEM or path)
        'apple_music_key_id' => trim((string) ($s['presave_apple_music_key_id'] ?? '')),  // 10-char MusicKit Key ID
        'team_id'            => $team,                                                     // 10-char Apple Team ID
        // Deezer
        'deezer_app_id'      => trim((string) ($s['presave_deezer_app_id'] ?? '')),
        'deezer_secret'      => trim((string) ($s['presave_deezer_secret'] ?? '')),
        // Branding / copy
        'org'                => function_exists('lmeg_wallet_settings') ? lmeg_wallet_settings()['org'] : '',
    ];
}

/** A PEM/.p8 setting may be inline text or a filesystem path. Returns it or ''. */
function lmeg_presave_pem($v) {
    $v = trim((string) $v);
    if ($v === '') return '';
    if (strpos($v, '-----BEGIN') !== false) return $v;
    if (@is_file($v) && @is_readable($v)) return (string) file_get_contents($v);
    return '';
}

function lmeg_presave_apple_ready() {
    $s = lmeg_presave_settings();
    return lmeg_presave_pem($s['apple_music_key']) !== '' && $s['apple_music_key_id'] !== '' && $s['team_id'] !== '';
}
function lmeg_presave_deezer_ready() {
    $s = lmeg_presave_settings();
    return $s['deezer_app_id'] !== '' && $s['deezer_secret'] !== '';
}
/** Amazon needs no credentials — it's a capture + release-day reminder link. */
function lmeg_presave_amazon_ready() { return true; }

/** Which platforms a given campaign can actually offer, given its IDs + creds. */
function lmeg_presave_platforms_for($campaign) {
    $out = [];
    if (!empty($campaign->apple_album_id)  && lmeg_presave_apple_ready())  $out[] = 'apple';
    if (!empty($campaign->deezer_album_id) && lmeg_presave_deezer_ready()) $out[] = 'deezer';
    if (!empty($campaign->amazon_url))                                     $out[] = 'amazon';
    return $out;
}

/* -------------------------------------------------------------------------
 * Small JWT helpers (ES256), namespaced so they never clash with wallet.php.
 * ---------------------------------------------------------------------- */
function lmeg_presave_b64url($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
/** DER ECDSA signature → raw R||S (64 bytes for P-256), as a JWS ES256 needs. */
function lmeg_presave_der2raw($der) {
    $o = 0; $L = strlen($der);
    if ($L < 8 || ord($der[$o++]) !== 0x30) return '';
    $len = ord($der[$o++]); if ($len & 0x80) { $n = $len & 0x7f; while ($n-- > 0) $o++; }
    if (ord($der[$o++]) !== 0x02) return '';
    $rl = ord($der[$o++]); $r = substr($der, $o, $rl); $o += $rl;
    if (ord($der[$o++]) !== 0x02) return '';
    $sl = ord($der[$o++]); $s = substr($der, $o, $sl);
    $r = ltrim($r, "\x00"); $s = ltrim($s, "\x00");
    if (strlen($r) > 32 || strlen($s) > 32) return '';
    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

/**
 * Apple Music developer token (ES256 JWT signed with the MusicKit key). This is
 * the token MusicKit JS is configured with and the Bearer for Apple Music API
 * calls. Cached until ~1 day before expiry. '' if not configured. Max life is
 * 180 days per Apple; we use that.
 */
function lmeg_presave_apple_dev_token() {
    static $cache = null;
    if ($cache && $cache['exp'] > time() + 86400) return $cache['jwt'];
    $s   = lmeg_presave_settings();
    $pem = lmeg_presave_pem($s['apple_music_key']);
    if ($pem === '' || $s['apple_music_key_id'] === '' || $s['team_id'] === '') return '';
    $pkey = openssl_pkey_get_private($pem);
    if (!$pkey) return '';
    $now  = time();
    $exp  = $now + 15552000;   // 180 days
    $head = lmeg_presave_b64url(wp_json_encode(['alg' => 'ES256', 'kid' => $s['apple_music_key_id']]));
    $body = lmeg_presave_b64url(wp_json_encode(['iss' => $s['team_id'], 'iat' => $now, 'exp' => $exp]));
    $sig  = '';
    if (!openssl_sign($head . '.' . $body, $sig, $pkey, OPENSSL_ALGO_SHA256)) return '';
    $raw = lmeg_presave_der2raw($sig);
    if ($raw === '') return '';
    $jwt = $head . '.' . $body . '.' . lmeg_presave_b64url($raw);
    $cache = ['jwt' => $jwt, 'exp' => $exp];
    return $jwt;
}

/* -------------------------------------------------------------------------
 * Deezer OAuth — connect URL + code→token exchange.
 * Docs: https://developers.deezer.com/api/oauth  (perms: manage_library)
 * ---------------------------------------------------------------------- */
/** Where Deezer sends the fan back after they authorize (our connect endpoint). */
function lmeg_presave_deezer_redirect_uri() {
    return home_url('/?lmeg_presave=deezer_cb');
}
/** The Deezer "connect" URL a fan is sent to for a given campaign. */
function lmeg_presave_deezer_auth_url($campaign_id) {
    $s = lmeg_presave_settings();
    if (!lmeg_presave_deezer_ready()) return '';
    return 'https://connect.deezer.com/oauth/auth.php?' . http_build_query([
        'app_id'       => $s['deezer_app_id'],
        'redirect_uri' => lmeg_presave_deezer_redirect_uri(),
        'perms'        => 'manage_library,offline_access',
        'state'        => (string) ((int) $campaign_id),
    ]);
}
/** Exchange a Deezer auth code for an access token. Returns token string or ''. */
function lmeg_presave_deezer_token($code) {
    $s = lmeg_presave_settings();
    if (!lmeg_presave_deezer_ready() || $code === '') return '';
    $resp = wp_remote_get('https://connect.deezer.com/oauth/access_token.php?' . http_build_query([
        'app_id' => $s['deezer_app_id'],
        'secret' => $s['deezer_secret'],
        'code'   => $code,
        'output' => 'json',
    ]), ['timeout' => 15]);
    if (is_wp_error($resp)) return '';
    $body = json_decode((string) wp_remote_retrieve_body($resp), true);
    return is_array($body) ? (string) ($body['access_token'] ?? '') : '';
}

/* -------------------------------------------------------------------------
 * Campaign helpers.
 * ---------------------------------------------------------------------- */
function lmeg_presave_get_campaign($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . lmeg_presave_table('campaigns') . " WHERE id = %d", (int) $id));
}
function lmeg_presave_active_campaigns() {
    global $wpdb;
    return $wpdb->get_results("SELECT * FROM " . lmeg_presave_table('campaigns') . " WHERE status = 'active' ORDER BY release_date ASC, id DESC");
}
/** Count of pre-saves for a campaign, keyed by platform. */
function lmeg_presave_counts($campaign_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT platform, COUNT(*) n FROM " . lmeg_presave_table('saves') . " WHERE campaign_id = %d GROUP BY platform", (int) $campaign_id), ARRAY_A);
    $out = ['apple' => 0, 'deezer' => 0, 'amazon' => 0, 'total' => 0];
    foreach ((array) $rows as $r) { $out[$r['platform']] = (int) $r['n']; $out['total'] += (int) $r['n']; }
    return $out;
}
