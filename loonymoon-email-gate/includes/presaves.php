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
/** The Deezer "connect" URL a fan is sent to for a given campaign. $back is the
 * page to return the fan to (so the widget can show its success state). */
function lmeg_presave_deezer_auth_url($campaign_id, $back = '') {
    $s = lmeg_presave_settings();
    if (!lmeg_presave_deezer_ready()) return '';
    // state carries the campaign id + return page (Deezer echoes it back verbatim).
    $state = lmeg_presave_b64url(wp_json_encode(['c' => (int) $campaign_id, 'b' => (string) $back]));
    return 'https://connect.deezer.com/oauth/auth.php?' . http_build_query([
        'app_id'       => $s['deezer_app_id'],
        'redirect_uri' => lmeg_presave_deezer_redirect_uri(),
        // basic_access + email → the callback can read the fan's email for the CRM;
        // manage_library → add the album at release; offline_access → token persists.
        'perms'        => 'basic_access,email,manage_library,offline_access',
        'state'        => $state,
    ]);
}
/** Decode a Deezer state back into ['c' => campaign_id, 'b' => return_url]. */
function lmeg_presave_deezer_state($state) {
    $state = (string) $state;
    $j = json_decode((string) base64_decode(strtr($state, '-_', '+/')), true);
    if (is_array($j) && isset($j['c'])) return ['c' => (int) $j['c'], 'b' => (string) ($j['b'] ?? '')];
    return ['c' => (int) $state, 'b' => ''];   // back-compat: a plain campaign id
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

/* =========================================================================
 * ITERATION 2 — admin UI. A "Pre-Saves" submenu: campaigns + credentials.
 * Self-contained form/nonce/save; credentials merge into the shared LMEG_OPTION
 * blob (never a file). Admin-only; internal, so "Fanloop" wording is fine here.
 * ====================================================================== */
add_action('admin_menu', 'lmeg_presave_admin_menu', 31);
function lmeg_presave_admin_menu() {
    add_submenu_page('lmeg', 'Pre-Saves', 'Pre-Saves', 'manage_options', 'lmeg-presaves', 'lmeg_presave_admin_page');
}

function lmeg_presave_admin_page() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $notice = '';
    $ct = lmeg_presave_table('campaigns');

    if (isset($_POST['lmeg_presave_action']) && check_admin_referer('lmeg_presaves', 'lmeg_presaves_nonce')) {
        $act = sanitize_key($_POST['lmeg_presave_action']);
        if ($act === 'save_creds') {
            $o = get_option(LMEG_OPTION, []);
            foreach (['presave_apple_music_key_id', 'presave_team_id', 'presave_deezer_app_id'] as $k)
                $o[$k] = sanitize_text_field(wp_unslash($_POST[$k] ?? ''));
            foreach (['presave_apple_music_key', 'presave_deezer_secret'] as $k)   // credentials, stored raw
                $o[$k] = trim((string) wp_unslash($_POST[$k] ?? ''));
            update_option(LMEG_OPTION, $o);
            $notice = '<div class="notice notice-success"><p>Pre-save credentials saved.</p></div>';
        } elseif ($act === 'create_campaign') {
            $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
            if ($title === '') {
                $notice = '<div class="notice notice-error"><p>Give the release a title.</p></div>';
            } else {
                $rd = sanitize_text_field(wp_unslash($_POST['release_date'] ?? ''));
                $rd_sql = null;
                if ($rd !== '') { $ts = strtotime(str_replace('T', ' ', $rd)); if ($ts) $rd_sql = date('Y-m-d H:i:s', $ts); }
                $wpdb->insert($ct, [
                    'title'           => $title,
                    'artist'          => sanitize_text_field(wp_unslash($_POST['artist'] ?? '')),
                    'release_date'    => $rd_sql,
                    'apple_album_id'  => sanitize_text_field(wp_unslash($_POST['apple_album_id'] ?? '')),
                    'deezer_album_id' => sanitize_text_field(wp_unslash($_POST['deezer_album_id'] ?? '')),
                    'amazon_url'      => esc_url_raw(wp_unslash($_POST['amazon_url'] ?? '')),
                    'artwork_url'     => esc_url_raw(wp_unslash($_POST['artwork_url'] ?? '')),
                    'status'          => 'active',
                    'created_at'      => current_time('mysql', true),
                ]);
                if ($wpdb->insert_id && $rd_sql) lmeg_presave_schedule((int) $wpdb->insert_id, $rd_sql);
                $notice = '<div class="notice notice-success"><p>Campaign created. Add it to a page with the shortcode shown below.</p></div>';
            }
        } elseif ($act === 'delete_campaign') {
            $cid = (int) ($_POST['campaign_id'] ?? 0);
            if ($cid) {
                $wpdb->delete($ct, ['id' => $cid]);
                $wpdb->delete(lmeg_presave_table('saves'), ['campaign_id' => $cid]);
                $notice = '<div class="notice notice-success"><p>Campaign deleted.</p></div>';
            }
        } elseif ($act === 'release_now') {
            $cid = (int) ($_POST['campaign_id'] ?? 0);
            if ($cid) {
                $r = lmeg_presave_release($cid);
                $notice = '<div class="notice notice-success"><p>Released — <strong>' . (int) $r['added'] . '</strong> added to libraries'
                    . ($r['skipped'] ? ', ' . (int) $r['skipped'] . ' skipped' : '')
                    . ($r['failed'] ? ', <strong>' . (int) $r['failed'] . '</strong> failed (see the row status)' : '') . '.</p></div>';
            }
        }
    }

    $s          = get_option(LMEG_OPTION, []);
    $campaigns  = $wpdb->get_results("SELECT * FROM $ct ORDER BY (status='active') DESC, release_date ASC, id DESC");
    $val        = function ($k) use ($s) { return esc_attr((string) ($s[$k] ?? '')); };
    $ta         = function ($k) use ($s) { return esc_textarea((string) ($s[$k] ?? '')); };
    $dot        = function ($state, $label) {
        $col = $state === 'ok' ? '#1a7f37' : ($state === 'warn' ? '#bf8700' : '#8c8f94');
        return '<div style="display:flex;align-items:center;gap:9px;margin:6px 0;"><span style="width:10px;height:10px;border-radius:50%;background:' . $col . ';flex:0 0 auto;"></span><span>' . $label . '</span></div>';
    };
    ?>
    <div class="wrap">
        <h1>Fanloop — Pre-Saves</h1>
        <?php echo $notice; ?>
        <p class="description" style="max-width:760px;">Let fans commit an upcoming release to their music library before it drops — it's added automatically on release day. Add credentials below to switch each platform on; until then a platform just isn't offered.</p>

        <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start;margin-top:10px;">
          <div style="flex:1;min-width:320px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 18px;">
            <h2 style="margin-top:4px;">Platforms</h2>
            <?php
            echo $dot(lmeg_presave_apple_ready() ? 'ok' : 'warn',
                lmeg_presave_apple_ready() ? '<strong>Apple Music: ready</strong>' : '<strong>Apple Music: off</strong> — add a MusicKit key + Key ID');
            echo $dot(lmeg_presave_deezer_ready() ? 'ok' : 'warn',
                lmeg_presave_deezer_ready() ? '<strong>Deezer: ready</strong>' : '<strong>Deezer: off</strong> — add a Deezer App ID + secret');
            echo $dot('ok', '<strong>Amazon Music: reminder mode</strong> — release-day email + link (no silent auto-add exists)');
            ?>
            <p class="description" style="margin-top:12px;">Spotify isn't offered — its API blocks pre-saves for real fanbases. Use a Release Drop for Spotify.</p>
          </div>
        </div>

        <h2 style="margin-top:26px;">Campaigns</h2>
        <?php if (!$campaigns): ?>
            <p class="description">No campaigns yet — create one below.</p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:1000px;">
                <thead><tr><th>Release</th><th>Date</th><th>Platforms</th><th>Pre-saves</th><th>Shortcode</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($campaigns as $c):
                    $plats = lmeg_presave_platforms_for($c);
                    $counts = lmeg_presave_counts($c->id); ?>
                    <tr>
                        <td><strong><?php echo esc_html($c->title); ?></strong><?php echo $c->artist ? '<br><span class="description">' . esc_html($c->artist) . '</span>' : ''; ?></td>
                        <td><?php echo $c->release_date ? esc_html(date_i18n('M j, Y', strtotime($c->release_date))) : '<span class="description">—</span>'; ?></td>
                        <td><?php echo $plats ? esc_html(implode(', ', array_map('ucfirst', $plats))) : '<span class="description">none configured</span>'; ?></td>
                        <td><strong><?php echo (int) $counts['total']; ?></strong> <span class="description">(A <?php echo (int) $counts['apple']; ?> · D <?php echo (int) $counts['deezer']; ?> · Az <?php echo (int) $counts['amazon']; ?>)</span></td>
                        <td><code>[fanloop_presave campaign="<?php echo (int) $c->id; ?>"]</code></td>
                        <td style="white-space:nowrap;">
                            <?php if ($c->status === 'active' && $counts['total'] > 0): ?>
                            <form method="post" onsubmit="return confirm('Release now — add this to <?php echo (int) $counts['total']; ?> pre-saver\'s libraries? Do this only once the album is actually live on the stores.');" style="display:inline;margin:0;">
                                <?php wp_nonce_field('lmeg_presaves', 'lmeg_presaves_nonce'); ?>
                                <input type="hidden" name="lmeg_presave_action" value="release_now" />
                                <input type="hidden" name="campaign_id" value="<?php echo (int) $c->id; ?>" />
                                <button class="button button-small">Release now</button>
                            </form>
                            <?php elseif ($c->status === 'released'): ?><span class="description">Released</span> <?php endif; ?>
                            <form method="post" onsubmit="return confirm('Delete this campaign and its pre-saves?');" style="display:inline;margin:0 0 0 6px;">
                                <?php wp_nonce_field('lmeg_presaves', 'lmeg_presaves_nonce'); ?>
                                <input type="hidden" name="lmeg_presave_action" value="delete_campaign" />
                                <input type="hidden" name="campaign_id" value="<?php echo (int) $c->id; ?>" />
                                <button class="button-link-delete button-link">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start;margin-top:24px;">
          <form method="post" style="flex:1;min-width:360px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px 18px;">
            <?php wp_nonce_field('lmeg_presaves', 'lmeg_presaves_nonce'); ?>
            <input type="hidden" name="lmeg_presave_action" value="create_campaign" />
            <h2 style="margin-top:2px;">New campaign</h2>
            <table class="form-table" role="presentation">
                <tr><th><label for="ps_title">Release title</label></th><td><input name="title" id="ps_title" class="regular-text" required /></td></tr>
                <tr><th><label>Artist</label></th><td><input name="artist" class="regular-text" placeholder="<?php echo esc_attr(lmeg_presave_settings()['org'] ?: ''); ?>" /></td></tr>
                <tr><th><label>Release date</label></th><td><input type="datetime-local" name="release_date" /></td></tr>
                <tr><th><label>Apple album ID</label></th><td><input name="apple_album_id" class="regular-text" placeholder="e.g. 1440913150" />
                    <p class="description">The numeric id from the Apple Music album URL (…/album/…/<strong>1440913150</strong>).</p></td></tr>
                <tr><th><label>Deezer album ID</label></th><td><input name="deezer_album_id" class="regular-text" placeholder="e.g. 302127" />
                    <p class="description">The numeric id from the Deezer album URL (deezer.com/album/<strong>302127</strong>).</p></td></tr>
                <tr><th><label>Amazon Music URL</label></th><td><input type="url" name="amazon_url" class="regular-text" placeholder="https://music.amazon.com/albums/…" /></td></tr>
                <tr><th><label>Artwork URL</label></th><td><input type="url" name="artwork_url" class="regular-text" placeholder="https://…/cover.jpg (optional)" /></td></tr>
            </table>
            <p><button class="button button-primary">Create campaign</button></p>
          </form>

          <form method="post" style="flex:1;min-width:360px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px 18px;">
            <?php wp_nonce_field('lmeg_presaves', 'lmeg_presaves_nonce'); ?>
            <input type="hidden" name="lmeg_presave_action" value="save_creds" />
            <h2 style="margin-top:2px;">Platform credentials</h2>
            <h3>Apple Music (MusicKit)</h3>
            <table class="form-table" role="presentation">
                <tr><th><label>MusicKit key (.p8)</label></th><td><textarea name="presave_apple_music_key" rows="5" class="large-text code" placeholder="-----BEGIN PRIVATE KEY----- … (or a server path)"><?php echo $ta('presave_apple_music_key'); ?></textarea></td></tr>
                <tr><th><label>Key ID</label></th><td><input name="presave_apple_music_key_id" class="regular-text" value="<?php echo $val('presave_apple_music_key_id'); ?>" placeholder="10-char MusicKit Key ID" /></td></tr>
                <tr><th><label>Team ID</label></th><td><input name="presave_team_id" class="regular-text" value="<?php echo $val('presave_team_id'); ?>" placeholder="<?php echo esc_attr(trim((string) ($s['wallet_team_id'] ?? '')) ?: '10-char Apple Team ID'); ?>" />
                    <p class="description">Leave blank to reuse your Wallet Team ID.</p></td></tr>
            </table>
            <h3>Deezer</h3>
            <table class="form-table" role="presentation">
                <tr><th><label>App ID</label></th><td><input name="presave_deezer_app_id" class="regular-text" value="<?php echo $val('presave_deezer_app_id'); ?>" /></td></tr>
                <tr><th><label>Secret</label></th><td><input name="presave_deezer_secret" class="regular-text" value="<?php echo $val('presave_deezer_secret'); ?>" /></td></tr>
            </table>
            <p class="description">Deezer redirect URI (add this to your Deezer app): <code style="word-break:break-all;"><?php echo esc_html(lmeg_presave_deezer_redirect_uri()); ?></code></p>
            <p><button class="button button-primary">Save credentials</button></p>
          </form>
        </div>
    </div>
    <?php
}

/* =========================================================================
 * ITERATION 3 — fan-facing widget: [fanloop_presave campaign="X"].
 * WHITE-LABEL — fans see the artist, never "Fanloop". Renders artwork + the
 * platform buttons that are actually available for the campaign. Apple uses
 * MusicKit JS (fan authorizes → we store a Music-User-Token); Deezer is an OAuth
 * connect; Amazon is an email capture + release-day reminder. The connect
 * endpoints (?lmeg_presave=…) are wired in iteration 4.
 * ====================================================================== */
add_shortcode('fanloop_presave', 'lmeg_presave_shortcode');
function lmeg_presave_shortcode($atts = []) {
    $a = shortcode_atts(['campaign' => 0], $atts, 'fanloop_presave');
    $c = lmeg_presave_get_campaign((int) $a['campaign']);
    if (!$c) return '';
    $plats = lmeg_presave_platforms_for($c);
    if (!$plats) return '';   // nothing configured yet → render nothing

    static $inst = 0; $inst++;
    $uid    = 'flps' . $inst;
    $set    = lmeg_presave_settings();
    $artist = $c->artist !== '' ? $c->artist : ($set['org'] ?: '');
    $accent = (function_exists('lmeg_wallet_settings') && preg_match('/^#[0-9a-fA-F]{6}$/', (string) lmeg_wallet_settings()['label'])) ? lmeg_wallet_settings()['label'] : '#E15FA8';
    $when   = $c->release_date ? date_i18n('F j', strtotime($c->release_date)) : '';
    $devtoken  = in_array('apple', $plats, true)  ? lmeg_presave_apple_dev_token() : '';
    $page_url  = home_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/');
    $deezerUrl = in_array('deezer', $plats, true) ? lmeg_presave_deezer_auth_url($c->id, $page_url) : '';
    $appleEp   = esc_url(add_query_arg('lmeg_presave', 'apple_store', home_url('/')));
    $amazonEp  = esc_url(add_query_arg('lmeg_presave', 'amazon', home_url('/')));

    // Platform button (shared look). Apple/Amazon are JS actions; Deezer is a link.
    $btn = function ($label, $svg, $extra = '') use ($accent) {
        return '<button type="button" ' . $extra . ' style="display:flex;align-items:center;justify-content:center;gap:9px;width:100%;'
            . 'padding:12px 16px;border:0;border-radius:12px;background:#fff;color:#17141f;font:700 15px/1 -apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;'
            . 'cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.14);margin-top:9px">' . $svg . esc_html($label) . '</button>';
    };
    $ic = [
        'apple'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="#FA243C" aria-hidden="true"><path d="M23.99 6.12c0-.5-.05-1-.14-1.48a4.9 4.9 0 0 0-.6-1.62A4.68 4.68 0 0 0 20.98.44 6.6 6.6 0 0 0 18.86.06C18.36.01 18.2 0 16.9 0H7.1C5.8 0 5.64.01 5.14.06c-.74.05-1.44.2-2.12.38A4.68 4.68 0 0 0 .75 3.02a4.9 4.9 0 0 0-.6 1.62C.05 5.12 0 5.62 0 6.12v11.76c0 .5.05 1 .15 1.48.13.6.34 1.14.6 1.62.53.94 1.32 1.73 2.27 2.26.68.18 1.38.33 2.12.38.5.05.66.06 1.96.06h9.8c1.3 0 1.46-.01 1.96-.06a6.6 6.6 0 0 0 2.12-.38 4.68 4.68 0 0 0 2.27-2.26c.26-.48.47-1.02.6-1.62.1-.48.15-.98.15-1.48V6.12zM17.3 8.9v6.28c0 .55-.04.9-.36 1.3-.6.76-1.94.9-2.62.28-.66-.6-.5-1.66.32-2.12.4-.22.86-.26 1.32-.32.28-.04.42-.16.42-.46V9.42l-5.2 1.06v5.9c0 .55-.04.9-.36 1.3-.6.76-1.94.9-2.62.28-.66-.6-.5-1.66.32-2.12.4-.22.86-.26 1.32-.32.28-.04.42-.16.42-.46V8.2c0-.44.2-.7.62-.8l6.06-1.22c.5-.1.76.12.76.62l.02 2.1z"/></svg>',
        'deezer' => '<svg width="18" height="15" viewBox="0 0 24 18" aria-hidden="true"><rect x="19" y="0" width="5" height="3" fill="#40AB5D"/><rect x="19" y="4.6" width="5" height="3" fill="#F90"/><rect x="12.6" y="4.6" width="5" height="3" fill="#FF5723"/><rect x="19" y="9.2" width="5" height="3" fill="#EF3E3F"/><rect x="12.6" y="9.2" width="5" height="3" fill="#5F358B"/><rect x="6.3" y="9.2" width="5" height="3" fill="#2A9DE1"/><rect x="19" y="13.8" width="5" height="3" fill="#333"/><rect x="12.6" y="13.8" width="5" height="3" fill="#357DED"/><rect x="6.3" y="13.8" width="5" height="3" fill="#41B85C"/><rect x="0" y="13.8" width="5" height="3" fill="#FDCA00"/></svg>',
        'amazon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="#25D1DA" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm-1 5.5 5 3.5-5 3.5v-7z"/></svg>',
    ];

    ob_start(); ?>
    <div id="<?php echo esc_attr($uid); ?>" class="flp-presave" data-c="<?php echo (int) $c->id; ?>"
         style="max-width:420px;border-radius:18px;overflow:hidden;background:#141019;color:#fff;box-shadow:0 14px 40px rgba(0,0,0,.28);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif">
      <?php if ($c->artwork_url): ?><img src="<?php echo esc_url($c->artwork_url); ?>" alt="" style="display:block;width:100%;aspect-ratio:1;object-fit:cover"><?php endif; ?>
      <div style="padding:20px">
        <div style="font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:<?php echo esc_attr($accent); ?>"><?php echo $when ? 'Out ' . esc_html($when) : 'Pre-save'; ?></div>
        <div style="font-size:22px;font-weight:800;margin-top:4px;line-height:1.15"><?php echo esc_html($c->title); ?></div>
        <?php if ($artist): ?><div style="font-size:14px;color:#c9c6d4;margin-top:2px"><?php echo esc_html($artist); ?></div><?php endif; ?>
        <p style="font-size:13.5px;color:#b7b3c4;margin:12px 0 4px">Pre-save it now — it lands in your library the moment it drops.</p>

        <?php if (in_array('apple', $plats, true) || in_array('amazon', $plats, true)): ?>
          <input type="email" class="flp-ps-email" placeholder="you@email.com" autocomplete="email"
            style="width:100%;box-sizing:border-box;margin-top:10px;padding:11px 13px;border:1px solid rgba(255,255,255,.18);border-radius:10px;background:rgba(255,255,255,.06);color:#fff;font-size:14px">
        <?php endif; ?>

        <div class="flp-ps-btns">
          <?php if (in_array('apple', $plats, true)) echo $btn('Pre-save on Apple Music', $ic['apple'], 'data-plat="apple"'); ?>
          <?php if (in_array('deezer', $plats, true)) echo '<a href="' . esc_url($deezerUrl) . '" style="display:flex;align-items:center;justify-content:center;gap:9px;width:100%;box-sizing:border-box;padding:12px 16px;border-radius:12px;background:#fff;color:#17141f;font:700 15px/1 -apple-system,sans-serif;text-decoration:none;box-shadow:0 1px 3px rgba(0,0,0,.14);margin-top:9px">' . $ic['deezer'] . 'Pre-save on Deezer</a>'; ?>
          <?php if (in_array('amazon', $plats, true)) echo $btn('Pre-save on Amazon Music', $ic['amazon'], 'data-plat="amazon"'); ?>
        </div>
        <div class="flp-ps-done" style="display:none;text-align:center;padding:16px 0 4px">
          <div style="font-size:34px;color:<?php echo esc_attr($accent); ?>">✓</div>
          <div style="font-weight:700;margin-top:4px">You're pre-saved</div>
          <div style="font-size:13px;color:#b7b3c4;margin-top:2px">We'll add it for you on release day.</div>
        </div>
        <div class="flp-ps-err" style="display:none;color:#ff9b9b;font-size:13px;margin-top:8px"></div>
      </div>
    </div>
    <?php if ($devtoken): ?>
    <script>window.LMEG_MK=window.LMEG_MK||<?php echo wp_json_encode(['token' => $devtoken, 'name' => $artist ?: 'Pre-save']); ?>;
    (function(){function cfg(){try{MusicKit.configure({developerToken:LMEG_MK.token,app:{name:LMEG_MK.name,build:'1'}});}catch(e){}}
    if(window.MusicKit&&MusicKit.configure)cfg();else document.addEventListener('musickitloaded',cfg);})();</script>
    <script src="https://js-cdn.music.apple.com/musickit/v3/musickit.js" data-web-components async></script>
    <?php endif; ?>
    <script>(function(){var root=document.getElementById(<?php echo wp_json_encode($uid); ?>);if(!root||root.dataset.wired)return;root.dataset.wired='1';
      var C=root.getAttribute('data-c'),emailEl=root.querySelector('.flp-ps-email'),done=root.querySelector('.flp-ps-done'),btns=root.querySelector('.flp-ps-btns'),err=root.querySelector('.flp-ps-err');
      function email(){return emailEl?emailEl.value.trim():'';}
      function fail(m){err.textContent=m;err.style.display='block';}
      function win(){btns.style.display='none';if(emailEl)emailEl.style.display='none';err.style.display='none';done.style.display='block';}
      function post(url,body){return fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(function(r){return r.json();});}
      root.addEventListener('click',function(e){var b=e.target.closest('[data-plat]');if(!b)return;var plat=b.getAttribute('data-plat');err.style.display='none';
        if((plat==='apple'||plat==='amazon')&&!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email())){fail('Enter your email so we can add it for you.');if(emailEl)emailEl.focus();return;}
        if(plat==='amazon'){post(<?php echo wp_json_encode($amazonEp); ?>,{c:C,email:email()}).then(function(d){d&&d.ok?win():fail((d&&d.msg)||'Something went wrong.');}).catch(function(){fail('Network error.');});return;}
        if(plat==='apple'){b.disabled=true;var mk;try{mk=MusicKit.getInstance();}catch(x){b.disabled=false;fail('Apple Music is still loading — try again in a moment.');return;}
          mk.authorize().then(function(tok){return post(<?php echo wp_json_encode($appleEp); ?>,{c:C,token:tok||mk.musicUserToken,email:email()});}).then(function(d){d&&d.ok?win():fail((d&&d.msg)||'Could not save — try again.');}).catch(function(){b.disabled=false;fail('Apple Music authorization was cancelled.');});}
      });
      // Returning from a Deezer connect (?lmeg_presave_ok=deezer) → show success.
      try{if(/[?&]lmeg_presave_ok=/.test(location.search))win();}catch(e){}
    })();</script>
    <?php
    return ob_get_clean();
}

/* =========================================================================
 * ITERATION 4 — connect endpoints (?lmeg_presave=…) + CRM capture.
 * The fan widget POSTs Apple/Amazon here; Deezer redirects back here after
 * OAuth. Each stores a pending lmeg_presaves row and captures the fan into the
 * CRM (reusing the Wallet subscriber matcher — matches by email, no duplicate).
 * ====================================================================== */

/** Insert-or-update the unique (campaign, subscriber, platform) pre-save row. */
function lmeg_presave_record($campaign_id, $subscriber_id, $platform, $token) {
    global $wpdb;
    $t   = lmeg_presave_table('saves');
    $now = current_time('mysql', true);
    $sid = $subscriber_id ? (int) $subscriber_id : null;
    if ($sid) {
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t WHERE campaign_id = %d AND subscriber_id = %d AND platform = %s",
            (int) $campaign_id, $sid, $platform));
        if ($existing) {
            $wpdb->update($t, ['user_token' => (string) $token, 'status' => 'pending', 'error' => ''], ['id' => $existing]);
            return $existing;
        }
    }
    $wpdb->insert($t, [
        'campaign_id'   => (int) $campaign_id,
        'subscriber_id' => $sid,
        'platform'      => $platform,
        'user_token'    => (string) $token,
        'status'        => 'pending',
        'created_at'    => $now,
    ]);
    return (int) $wpdb->insert_id;
}

/** Get-or-create a CRM subscriber from an email (+ optional name). 0 if no email. */
function lmeg_presave_subscriber($email, $name = '') {
    $email = sanitize_email((string) $email);
    if (!$email || !is_email($email)) return 0;
    if (function_exists('lmeg_wallet_get_or_create_subscriber')) return (int) lmeg_wallet_get_or_create_subscriber($email, $name);
    if (function_exists('lmeg_shop_match_subscriber'))          return (int) lmeg_shop_match_subscriber($email, '');
    return 0;
}

add_action('init', 'lmeg_presave_router');
function lmeg_presave_router() {
    if (!isset($_GET['lmeg_presave'])) return;
    $action = sanitize_key($_GET['lmeg_presave']);

    // ---- Apple / Amazon : JSON POST from the widget ----
    if ($action === 'apple_store' || $action === 'amazon') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { status_header(405); exit; }
        $raw  = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($raw) ? $raw : $_POST;
        $cid  = (int) ($body['c'] ?? 0);
        $camp = lmeg_presave_get_campaign($cid);
        if (!$camp || $camp->status !== 'active') { wp_send_json(['ok' => false, 'msg' => 'This pre-save is closed.']); }
        $email = sanitize_email((string) ($body['email'] ?? ''));
        $sid   = lmeg_presave_subscriber($email);
        if ($action === 'apple_store') {
            $token = trim((string) ($body['token'] ?? ''));
            if ($token === '') { wp_send_json(['ok' => false, 'msg' => 'Apple Music authorization failed.']); }
            if (empty($camp->apple_album_id) || !lmeg_presave_apple_ready()) { wp_send_json(['ok' => false, 'msg' => 'Apple Music isn\'t available for this release.']); }
            lmeg_presave_record($cid, $sid, 'apple', $token);
        } else {
            if (empty($camp->amazon_url)) { wp_send_json(['ok' => false, 'msg' => 'Amazon Music isn\'t available for this release.']); }
            lmeg_presave_record($cid, $sid, 'amazon', '');
        }
        wp_send_json(['ok' => true]);
    }

    // ---- Deezer : OAuth redirect back from connect.deezer.com ----
    if ($action === 'deezer_cb') {
        $st   = lmeg_presave_deezer_state($_GET['state'] ?? '');
        $cid  = (int) $st['c'];
        $back = $st['b'] !== '' ? $st['b'] : home_url('/');
        $code = sanitize_text_field($_GET['code'] ?? '');
        if ($code === '' || !$cid) { wp_safe_redirect(add_query_arg('lmeg_presave_ok', 'error', $back)); exit; }
        $camp = lmeg_presave_get_campaign($cid);
        if (!$camp || $camp->status !== 'active') { wp_safe_redirect(add_query_arg('lmeg_presave_ok', 'error', $back)); exit; }
        $token = lmeg_presave_deezer_token($code);
        if ($token === '') { wp_safe_redirect(add_query_arg('lmeg_presave_ok', 'error', $back)); exit; }
        // Deezer gives us the fan's email + name (basic_access,email perms).
        $email = ''; $name = '';
        $resp = wp_remote_get('https://api.deezer.com/user/me?access_token=' . rawurlencode($token), ['timeout' => 15]);
        if (!is_wp_error($resp)) {
            $u = json_decode((string) wp_remote_retrieve_body($resp), true);
            if (is_array($u)) { $email = sanitize_email((string) ($u['email'] ?? '')); $name = sanitize_text_field((string) ($u['name'] ?? '')); }
        }
        $sid = lmeg_presave_subscriber($email, $name);
        lmeg_presave_record($cid, $sid, 'deezer', $token);
        wp_safe_redirect(add_query_arg('lmeg_presave_ok', 'deezer', $back));
        exit;
    }
}

/* =========================================================================
 * ITERATION 5 — release runner. On release day (or "Release now"), add the
 * album to every pre-saver's library: Apple + Deezer via their APIs, Amazon via
 * a white-label reminder email. Rows are marked added/failed; the campaign flips
 * to 'released'. Triggered by an admin button, a per-campaign wp-cron scheduled
 * at the release date, and a daily fallback sweep.
 * ====================================================================== */

/** White-label release-day reminder email for Amazon (no auto-add API exists). */
function lmeg_presave_amazon_reminder($email, $camp) {
    if (!$email) return false;
    $artist  = $camp->artist !== '' ? $camp->artist : (lmeg_presave_settings()['org'] ?: '');
    $subject = ($artist ? $artist . ' — ' : '') . '“' . $camp->title . '” is out now';
    $body    = "It's here — “" . $camp->title . "”" . ($artist ? " by " . $artist : '') . " is out now.\n\n"
             . "Add it on Amazon Music:\n" . $camp->amazon_url . "\n\nThanks for pre-saving.";
    return (bool) wp_mail($email, $subject, $body);
}

/**
 * Release a campaign: process its pending pre-saves. $limit>0 processes a batch
 * (and does NOT flip the campaign to released). Returns {added,failed,skipped}.
 */
function lmeg_presave_release($campaign_id, $limit = 0) {
    global $wpdb;
    $out  = ['added' => 0, 'failed' => 0, 'skipped' => 0];
    $camp = lmeg_presave_get_campaign((int) $campaign_id);
    if (!$camp) return $out;
    $t   = lmeg_presave_table('saves');
    $now = current_time('mysql', true);
    $sql = $wpdb->prepare("SELECT * FROM $t WHERE campaign_id = %d AND status = 'pending' ORDER BY id ASC", (int) $campaign_id);
    if ($limit > 0) $sql .= ' LIMIT ' . (int) $limit;
    $rows = $wpdb->get_results($sql);
    $dev  = lmeg_presave_apple_ready() ? lmeg_presave_apple_dev_token() : '';

    foreach ((array) $rows as $r) {
        $ok = false; $err = '';
        if ($r->platform === 'apple') {
            if ($dev === '' || empty($camp->apple_album_id) || empty($r->user_token)) { $out['skipped']++; continue; }
            $resp = wp_remote_post('https://api.music.apple.com/v1/me/library?ids[albums]=' . rawurlencode($camp->apple_album_id), [
                'method' => 'POST', 'timeout' => 20,
                'headers' => ['Authorization' => 'Bearer ' . $dev, 'Music-User-Token' => $r->user_token],
            ]);
            if (is_wp_error($resp)) { $err = $resp->get_error_message(); }
            else { $code = (int) wp_remote_retrieve_response_code($resp); $ok = ($code === 202 || $code === 200); if (!$ok) $err = 'Apple HTTP ' . $code; }
        } elseif ($r->platform === 'deezer') {
            if (empty($camp->deezer_album_id) || empty($r->user_token)) { $out['skipped']++; continue; }
            $resp = wp_remote_post('https://api.deezer.com/user/me/albums', [
                'timeout' => 20, 'body' => ['album_id' => $camp->deezer_album_id, 'access_token' => $r->user_token],
            ]);
            if (is_wp_error($resp)) { $err = $resp->get_error_message(); }
            else { $b = trim((string) wp_remote_retrieve_body($resp)); $ok = ($b === 'true'); if (!$ok) $err = 'Deezer: ' . substr($b, 0, 120); }
        } elseif ($r->platform === 'amazon') {
            if (empty($camp->amazon_url)) { $out['skipped']++; continue; }
            $email = $r->subscriber_id ? $wpdb->get_var($wpdb->prepare("SELECT email FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", (int) $r->subscriber_id)) : '';
            if (!$email) { $out['skipped']++; continue; }
            $ok = lmeg_presave_amazon_reminder($email, $camp);
            if (!$ok) $err = 'reminder email failed';
        } else { $out['skipped']++; continue; }

        $wpdb->update($t, ['status' => $ok ? 'added' : 'failed', 'error' => substr($err, 0, 255), 'processed_at' => $now], ['id' => $r->id]);
        $ok ? $out['added']++ : $out['failed']++;
    }

    // A full run (no batch limit) closes the campaign.
    if ($limit === 0 && $camp->status !== 'released') {
        $wpdb->update(lmeg_presave_table('campaigns'), ['status' => 'released', 'released_at' => $now], ['id' => (int) $campaign_id]);
    }
    return $out;
}

/* ---- automatic triggers: per-campaign event + daily fallback sweep ---- */
add_action('lmeg_presave_run', 'lmeg_presave_release');
function lmeg_presave_schedule($campaign_id, $release_date) {
    if (!$release_date || !function_exists('wp_schedule_single_event')) return;
    $ts = strtotime($release_date);
    if ($ts && $ts > time()) wp_schedule_single_event($ts, 'lmeg_presave_run', [(int) $campaign_id]);
}
add_action('lmeg_presave_sweep', 'lmeg_presave_cron_sweep');
add_action('init', 'lmeg_presave_ensure_sweep', 20);
function lmeg_presave_ensure_sweep() {
    if (function_exists('wp_next_scheduled') && !wp_next_scheduled('lmeg_presave_sweep')) {
        wp_schedule_event(time() + 3600, 'daily', 'lmeg_presave_sweep');
    }
}
/** Fallback: release any active campaign whose release date has passed. */
function lmeg_presave_cron_sweep() {
    global $wpdb;
    $due = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM " . lmeg_presave_table('campaigns') . " WHERE status = 'active' AND release_date IS NOT NULL AND release_date <= %s",
        current_time('mysql')));
    foreach ((array) $due as $cid) lmeg_presave_release((int) $cid);
}
