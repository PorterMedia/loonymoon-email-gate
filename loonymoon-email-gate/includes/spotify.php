<?php
/**
 * Spotify analytics — Client Credentials flow (app-level, no user login,
 * no extended-access quota). Pulls artist profile, top tracks, and recent
 * releases, plus a daily follower/popularity snapshot for trend lines.
 *
 * NOTE: this is the read-only public data path. It has nothing to do with
 * pre-saves (which need per-user Authorization Code tokens + extended
 * access, gated behind Spotify's 250k-MAU wall — shelved).
 */

if (!defined('ABSPATH')) {
    exit;
}

const LMEG_SPOTIFY_API = 'https://api.spotify.com/v1';

function lmeg_spotify_configured() {
    $s = lmeg_get_settings();
    return !empty($s['spotify_client_id']) && !empty($s['spotify_client_secret']) && !empty($s['spotify_artist_id']);
}

/**
 * App access token via client credentials, cached until ~1 min before expiry.
 */
function lmeg_spotify_token() {
    $s = lmeg_get_settings();
    if (empty($s['spotify_client_id']) || empty($s['spotify_client_secret'])) {
        return new WP_Error('lmeg_sp_unconfigured', 'Spotify client ID/secret missing.');
    }
    $cached = get_transient('lmeg_spotify_token');
    if ($cached) return $cached;

    $resp = wp_remote_post('https://accounts.spotify.com/api/token', [
        'timeout' => 12,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($s['spotify_client_id'] . ':' . $s['spotify_client_secret']),
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ],
        'body' => ['grant_type' => 'client_credentials'],
    ]);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    $d    = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code !== 200 || empty($d['access_token'])) {
        return new WP_Error('lmeg_sp_auth', $d['error_description'] ?? ('HTTP ' . $code));
    }
    $ttl = max(60, (int) ($d['expires_in'] ?? 3600) - 60);
    set_transient('lmeg_spotify_token', $d['access_token'], $ttl);
    return $d['access_token'];
}

function lmeg_spotify_get($path, $query = []) {
    $token = lmeg_spotify_token();
    if (is_wp_error($token)) return $token;
    $url = LMEG_SPOTIFY_API . $path;
    if ($query) $url = add_query_arg($query, $url);
    $resp = wp_remote_get($url, [
        'timeout' => 12,
        'headers' => ['Authorization' => 'Bearer ' . $token],
    ]);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    $d    = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('lmeg_sp_http_' . $code, $d['error']['message'] ?? ('HTTP ' . $code));
    }
    return $d;
}

/**
 * Full artist snapshot for the admin page (15-min cache).
 */
function lmeg_spotify_overview($force = false) {
    if (!lmeg_spotify_configured()) return new WP_Error('lmeg_sp_unconfigured', 'Spotify is not configured.');
    if (!$force) {
        $cached = get_transient('lmeg_spotify_overview');
        if ($cached !== false) return $cached;
    }
    $s   = lmeg_get_settings();
    $aid = $s['spotify_artist_id'];

    $artist = lmeg_spotify_get('/artists/' . rawurlencode($aid));
    if (is_wp_error($artist)) return $artist;

    $top    = lmeg_spotify_get('/artists/' . rawurlencode($aid) . '/top-tracks', ['market' => 'US']);
    $albums = lmeg_spotify_get('/artists/' . rawurlencode($aid) . '/albums', ['include_groups' => 'album,single', 'limit' => 8, 'market' => 'US']);

    $out = [
        'name'       => $artist['name'] ?? '',
        'followers'  => (int) ($artist['followers']['total'] ?? 0),
        'popularity' => (int) ($artist['popularity'] ?? 0),
        'genres'     => $artist['genres'] ?? [],
        'image'      => $artist['images'][0]['url'] ?? '',
        'url'        => $artist['external_urls']['spotify'] ?? '',
        'top_tracks' => [],
        'releases'   => [],
    ];
    foreach ((array) ($top['tracks'] ?? []) as $t) {
        $out['top_tracks'][] = [
            'name'       => $t['name'] ?? '',
            'popularity' => (int) ($t['popularity'] ?? 0),
            'album'      => $t['album']['name'] ?? '',
            'url'        => $t['external_urls']['spotify'] ?? '',
        ];
    }
    foreach ((array) ($albums['items'] ?? []) as $a) {
        $out['releases'][] = [
            'name' => $a['name'] ?? '',
            'type' => $a['album_type'] ?? '',
            'date' => $a['release_date'] ?? '',
            'url'  => $a['external_urls']['spotify'] ?? '',
            'img'  => $a['images'][2]['url'] ?? ($a['images'][0]['url'] ?? ''),
        ];
    }

    set_transient('lmeg_spotify_overview', $out, 15 * MINUTE_IN_SECONDS);
    return $out;
}

function lmeg_spotify_verify() {
    $ov = lmeg_spotify_overview(true);
    if (is_wp_error($ov)) return $ov;
    return 'Connected — ' . ($ov['name'] ?: 'artist') . ', ' . number_format($ov['followers']) . ' followers.';
}

/**
 * Daily follower/popularity snapshot for the trend line.
 */
add_action('lmeg_broadcast_tick', 'lmeg_spotify_daily_snapshot', 70);
function lmeg_spotify_daily_snapshot() {
    if (!lmeg_spotify_configured()) return;
    if (get_site_transient('lmeg_spotify_snap_lock')) return;
    set_site_transient('lmeg_spotify_snap_lock', 1, DAY_IN_SECONDS);

    $ov = lmeg_spotify_overview();
    if (is_wp_error($ov)) return;

    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->prefix}lmeg_spotify_snapshots (snap_date, followers, popularity, created_at)
         VALUES (%s, %d, %d, %s)
         ON DUPLICATE KEY UPDATE followers = VALUES(followers), popularity = VALUES(popularity)",
        current_time('Y-m-d'), (int) $ov['followers'], (int) $ov['popularity'], current_time('mysql')
    ));
}

function lmeg_spotify_snapshots($days = 30) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT snap_date, followers, popularity FROM {$wpdb->prefix}lmeg_spotify_snapshots
         WHERE snap_date >= DATE_SUB(%s, INTERVAL %d DAY) ORDER BY snap_date ASC",
        current_time('Y-m-d'), max(1, (int) $days)
    ));
}

/* ---------------------------------------------------------------------------
 * Admin page
 * ------------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_submenu_page('lmeg', 'Spotify', 'Spotify', 'manage_options', 'lmeg-spotify', 'lmeg_admin_spotify');
}, 20);

function lmeg_admin_spotify() {
    if (!current_user_can('manage_options')) return;

    if (!lmeg_spotify_configured()) {
        echo '<div class="wrap"><h1>Email Gate — Spotify</h1><div class="notice notice-info"><p>Add your Spotify client ID, secret, and artist ID under <a href="' . esc_url(admin_url('admin.php?page=lmeg-settings')) . '">Settings → Spotify</a>. These come from a free app at <a href="https://developer.spotify.com/dashboard" target="_blank" rel="noopener">developer.spotify.com/dashboard</a> — no special access needed for artist stats.</p></div></div>';
        return;
    }

    $ov = lmeg_spotify_overview(!empty($_GET['refresh']));
    if (is_wp_error($ov)) {
        echo '<div class="wrap"><h1>Email Gate — Spotify</h1><div class="notice notice-error"><p>' . esc_html($ov->get_error_message()) . '</p></div></div>';
        return;
    }

    $snaps = lmeg_spotify_snapshots(30);
    // Build a sparkline for followers.
    $vals = array_map(function ($r) { return (int) $r->followers; }, (array) $snaps);
    $spark = '';
    if (count($vals) >= 2) {
        $min = min($vals); $max = max($vals); $range = max(1, $max - $min);
        $w = 320; $h = 60; $step = $w / (count($vals) - 1);
        $pts = [];
        foreach ($vals as $i => $v) {
            $x = round($i * $step, 1);
            $y = round($h - (($v - $min) / $range) * ($h - 6) - 3, 1);
            $pts[] = "$x,$y";
        }
        $spark = implode(' ', $pts);
    }
    ?>
    <div class="wrap">
        <h1>Email Gate — Spotify</h1>
        <p>
            <a class="button" href="<?php echo esc_url(add_query_arg('refresh', 1)); ?>">Refresh</a>
            <?php if ($ov['url']) : ?><a class="button" href="<?php echo esc_url($ov['url']); ?>" target="_blank" rel="noopener">Open on Spotify ↗</a><?php endif; ?>
        </p>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin:18px 0;max-width:1000px;">
            <div class="lmeg-stat">
                <div class="lmeg-stat__label">Followers</div>
                <div class="lmeg-stat__value"><?php echo number_format_i18n($ov['followers']); ?></div>
                <?php if (count($vals) >= 2) :
                    $delta = end($vals) - reset($vals); ?>
                    <div class="lmeg-stat__hint" style="color:<?php echo $delta >= 0 ? '#34D399' : '#F87171'; ?>;">
                        <?php echo ($delta >= 0 ? '+' : '') . number_format_i18n($delta); ?> in <?php echo count($vals); ?>d
                    </div>
                <?php else : ?>
                    <div class="lmeg-stat__hint">trend builds daily</div>
                <?php endif; ?>
            </div>
            <div class="lmeg-stat">
                <div class="lmeg-stat__label">Popularity</div>
                <div class="lmeg-stat__value"><?php echo (int) $ov['popularity']; ?><span style="font-size:14px;opacity:.5;">/100</span></div>
                <div class="lmeg-stat__hint">Spotify's 0–100 score</div>
            </div>
            <div class="lmeg-stat" style="grid-column:span 2;">
                <div class="lmeg-stat__label">Follower trend (30d)</div>
                <?php if ($spark) : ?>
                    <svg viewBox="0 0 320 60" width="100%" height="60" style="margin-top:6px;"><polyline fill="none" stroke="#1DB954" stroke-width="2" points="<?php echo esc_attr($spark); ?>" /></svg>
                <?php else : ?>
                    <div class="lmeg-stat__value" style="font-size:15px;font-weight:500;">Collecting…</div>
                    <div class="lmeg-stat__hint">A daily snapshot is captured automatically; the line fills in over the next few days.</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($ov['genres']) : ?>
            <p><strong>Genres:</strong> <?php echo esc_html(implode(' · ', $ov['genres'])); ?></p>
        <?php endif; ?>

        <h2>Top tracks</h2>
        <table class="widefat striped" style="max-width:720px;">
            <thead><tr><th>#</th><th>Track</th><th>Album</th><th>Popularity</th></tr></thead>
            <tbody>
            <?php foreach ($ov['top_tracks'] as $i => $t) : ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><a href="<?php echo esc_url($t['url']); ?>" target="_blank" rel="noopener"><strong><?php echo esc_html($t['name']); ?></strong></a></td>
                    <td><?php echo esc_html($t['album']); ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;max-width:140px;height:8px;background:rgba(255,255,255,.08);border-radius:4px;overflow:hidden;">
                                <div style="width:<?php echo (int) $t['popularity']; ?>%;height:100%;background:#1DB954;"></div>
                            </div>
                            <span style="font-variant-numeric:tabular-nums;"><?php echo (int) $t['popularity']; ?></span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Recent releases</h2>
        <table class="widefat striped" style="max-width:720px;">
            <thead><tr><th></th><th>Release</th><th>Type</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($ov['releases'] as $r) : ?>
                <tr>
                    <td><?php if ($r['img']) : ?><img src="<?php echo esc_url($r['img']); ?>" width="40" height="40" style="border-radius:6px;display:block;" alt="" /><?php endif; ?></td>
                    <td><a href="<?php echo esc_url($r['url']); ?>" target="_blank" rel="noopener"><strong><?php echo esc_html($r['name']); ?></strong></a></td>
                    <td><?php echo esc_html(ucfirst($r['type'])); ?></td>
                    <td><?php echo esc_html($r['date']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
