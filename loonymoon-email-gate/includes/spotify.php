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
 * Store one snapshot row (used by daily cron, "capture now", and CSV import).
 */
function lmeg_spotify_store_snapshot($date, $followers, $popularity) {
    global $wpdb;
    $d = date('Y-m-d', strtotime($date));
    return $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->prefix}lmeg_spotify_snapshots (snap_date, followers, popularity, created_at)
         VALUES (%s, %d, %d, %s)
         ON DUPLICATE KEY UPDATE followers = VALUES(followers), popularity = VALUES(popularity)",
        $d, (int) $followers, (int) $popularity, current_time('mysql')
    ));
}

/**
 * Capture today's numbers immediately (button on the History section).
 * @return true|WP_Error
 */
function lmeg_spotify_capture_now() {
    if (!lmeg_spotify_configured()) return new WP_Error('lmeg_sp_unconfigured', 'Spotify is not configured.');
    $ov = lmeg_spotify_overview(true);
    if (is_wp_error($ov)) return $ov;
    lmeg_spotify_store_snapshot(current_time('Y-m-d'), $ov['followers'], $ov['popularity']);
    return true;
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
    lmeg_spotify_store_snapshot(current_time('Y-m-d'), $ov['followers'], $ov['popularity']);
}

/**
 * All history rows, newest first, with day-over-day deltas computed.
 */
function lmeg_spotify_history() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT snap_date, followers, popularity FROM {$wpdb->prefix}lmeg_spotify_snapshots ORDER BY snap_date ASC"
    );
    $out = [];
    $prev = null;
    foreach ((array) $rows as $r) {
        $out[] = [
            'date'       => $r->snap_date,
            'followers'  => (int) $r->followers,
            'popularity' => (int) $r->popularity,
            'fdelta'     => $prev ? (int) $r->followers - $prev['followers'] : null,
            'pdelta'     => $prev ? (int) $r->popularity - $prev['popularity'] : null,
        ];
        $prev = ['followers' => (int) $r->followers, 'popularity' => (int) $r->popularity];
    }
    return array_reverse($out); // newest first
}

/* CSV export of the full history. */
add_action('admin_post_lmeg_spotify_export', 'lmeg_spotify_export_csv');
function lmeg_spotify_export_csv() {
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
    check_admin_referer('lmeg_spotify_export');
    global $wpdb;
    $rows = $wpdb->get_results("SELECT snap_date, followers, popularity FROM {$wpdb->prefix}lmeg_spotify_snapshots ORDER BY snap_date ASC", ARRAY_A);
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="loony-spotify-history-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['date', 'followers', 'popularity']);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
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
 * Impact analysis — correlate plugin initiatives with Spotify momentum
 *
 * Public API gives followers + popularity (0-100). Popularity is Spotify's
 * own recent-stream-velocity score, so it's the best public proxy for "did
 * streams move." For each initiative (broadcast, release) we measure the
 * follower + popularity change in the window AFTER it and compare to the
 * baseline daily rate — surfacing which drops actually moved the needle,
 * next to the clicks + revenue that broadcast drove.
 * ------------------------------------------------------------------------- */

/**
 * Nearest snapshot on-or-before a date (falls back to nearest-after).
 * @return array|null ['followers','popularity','snap_date']
 */
function lmeg_spotify_nearest($date) {
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_spotify_snapshots';
    $d   = date('Y-m-d', strtotime($date));
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT snap_date, followers, popularity FROM $tbl WHERE snap_date <= %s ORDER BY snap_date DESC LIMIT 1", $d
    ));
    if (!$row) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT snap_date, followers, popularity FROM $tbl WHERE snap_date >= %s ORDER BY snap_date ASC LIMIT 1", $d
        ));
    }
    return $row ? ['followers' => (int) $row->followers, 'popularity' => (int) $row->popularity, 'snap_date' => $row->snap_date] : null;
}

/**
 * Baseline daily follower change across the whole snapshot history.
 */
function lmeg_spotify_baseline_daily() {
    global $wpdb;
    $tbl  = $wpdb->prefix . 'lmeg_spotify_snapshots';
    $rows = $wpdb->get_results("SELECT snap_date, followers FROM $tbl ORDER BY snap_date ASC");
    if (count((array) $rows) < 2) return null;
    $first = $rows[0]; $last = end($rows);
    $days  = max(1, (strtotime($last->snap_date) - strtotime($first->snap_date)) / DAY_IN_SECONDS);
    return (($last->followers - $first->followers) / $days);
}

/**
 * Build impact rows for broadcasts + releases. Each row measures the change
 * over `window` days after the event. Returns [] when there isn't enough
 * snapshot history to measure anything yet.
 */
function lmeg_impact_rows($window = 7) {
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_spotify_snapshots';
    $span = $wpdb->get_row("SELECT MIN(snap_date) lo, MAX(snap_date) hi FROM $tbl");
    if (!$span || !$span->lo) return [];

    $baseline = lmeg_spotify_baseline_daily();
    $rev_map  = function_exists('lmeg_shop_revenue_by_broadcast') ? lmeg_shop_revenue_by_broadcast() : [];
    $events   = [];

    // Broadcasts.
    $bcasts = $wpdb->get_results(
        "SELECT id, subject, created_at,
                (SELECT COUNT(DISTINCT subscriber_id) FROM {$wpdb->prefix}lmeg_broadcast_events e WHERE e.broadcast_id=b.id AND e.event_type='click') clicks
         FROM {$wpdb->prefix}lmeg_broadcasts b WHERE status='completed' ORDER BY created_at DESC LIMIT 40"
    );
    foreach ((array) $bcasts as $b) {
        $events[] = [
            'type'  => 'Broadcast',
            'label' => $b->subject ?: 'broadcast',
            'date'  => substr($b->created_at, 0, 10),
            'clicks'=> (int) $b->clicks,
            'rev'   => isset($rev_map[(int) $b->id]) ? (int) $rev_map[(int) $b->id]['cents'] : 0,
        ];
    }

    // Releases (from cached overview).
    $ov = lmeg_spotify_overview();
    if (!is_wp_error($ov)) {
        foreach ($ov['releases'] as $r) {
            if (!empty($r['date']) && strlen($r['date']) >= 10) {
                $events[] = ['type' => ucfirst($r['type'] ?: 'Release'), 'label' => $r['name'], 'date' => substr($r['date'], 0, 10), 'clicks' => null, 'rev' => 0];
            }
        }
    }

    $rows = [];
    foreach ($events as $e) {
        // Need snapshots covering both the event date and +window days.
        if ($e['date'] < $span->lo || date('Y-m-d', strtotime($e['date'] . " +$window days")) > $span->hi) {
            $rows[] = $e + ['fdelta' => null, 'pdelta' => null, 'lift' => null, 'measurable' => false];
            continue;
        }
        $at    = lmeg_spotify_nearest($e['date']);
        $after = lmeg_spotify_nearest(date('Y-m-d', strtotime($e['date'] . " +$window days")));
        if (!$at || !$after) { $rows[] = $e + ['fdelta' => null, 'pdelta' => null, 'lift' => null, 'measurable' => false]; continue; }

        $fdelta = $after['followers'] - $at['followers'];
        $pdelta = $after['popularity'] - $at['popularity'];
        $expected = $baseline !== null ? $baseline * $window : null;
        $lift = ($expected !== null) ? $fdelta - $expected : null;
        $rows[] = $e + ['fdelta' => $fdelta, 'pdelta' => $pdelta, 'lift' => $lift, 'measurable' => true];
    }

    // Sort newest first by date.
    usort($rows, function ($a, $b) { return strcmp($b['date'], $a['date']); });
    return $rows;
}

/**
 * Compact impact summary string for the AI context.
 */
function lmeg_impact_ai_summary() {
    $rows = array_filter(lmeg_impact_rows(7), function ($r) { return $r['measurable']; });
    if (!$rows) return '';
    $lines = [];
    foreach (array_slice($rows, 0, 6) as $r) {
        $lines[] = $r['type'] . ' "' . $r['label'] . '" (' . $r['date'] . '): '
                 . ($r['fdelta'] >= 0 ? '+' : '') . $r['fdelta'] . ' followers, '
                 . ($r['pdelta'] >= 0 ? '+' : '') . $r['pdelta'] . ' popularity in the 7 days after'
                 . ($r['lift'] !== null ? ' (' . ($r['lift'] >= 0 ? '+' : '') . round($r['lift']) . ' vs baseline)' : '');
    }
    return "Spotify impact of recent initiatives (7-day follower/popularity change after each):\n- " . implode("\n- ", $lines);
}

/* ---------------------------------------------------------------------------
 * Admin page
 * ------------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_submenu_page('lmeg', 'Spotify', 'Spotify', 'manage_options', 'lmeg-spotify', 'lmeg_admin_spotify');
}, 20);

function lmeg_admin_spotify() {
    if (!current_user_can('manage_options')) return;
    $notice = '';

    // Capture-now + CSV import handlers.
    if (isset($_POST['lmeg_sp_nonce']) && wp_verify_nonce($_POST['lmeg_sp_nonce'], 'lmeg_sp_history')) {
        $act = sanitize_text_field($_POST['lmeg_action'] ?? '');
        if ($act === 'capture') {
            $r = lmeg_spotify_capture_now();
            $notice = is_wp_error($r)
                ? '<div class="notice notice-error"><p>' . esc_html($r->get_error_message()) . '</p></div>'
                : '<div class="notice notice-success"><p>Snapshot captured for today.</p></div>';
        } elseif ($act === 'import' && !empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
            $rows = 0;
            if (($fh = fopen($_FILES['csv']['tmp_name'], 'r')) !== false) {
                $header = fgetcsv($fh); // skip header
                while (($c = fgetcsv($fh)) !== false) {
                    if (count($c) < 2) continue;
                    $date = trim($c[0]);
                    if (!strtotime($date)) continue;
                    lmeg_spotify_store_snapshot($date, (int) $c[1], isset($c[2]) ? (int) $c[2] : 0);
                    $rows++;
                }
                fclose($fh);
            }
            $notice = '<div class="notice notice-success"><p>Imported ' . $rows . ' historical row' . ($rows === 1 ? '' : 's') . '.</p></div>';
        }
    }

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
        <?php echo $notice; ?>
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

        <h2>Impact of your initiatives</h2>
        <p class="description" style="max-width:820px;">Follower + popularity change in the 7 days after each broadcast and release, next to the clicks &amp; revenue it drove. Popularity (Spotify's 0–100 recent-stream score) is the closest public proxy for streams — the API doesn't expose raw stream counts. Directional, not causal; more useful as snapshot history accumulates.</p>
        <?php
        $impact = lmeg_impact_rows(7);
        $has_measurable = array_filter($impact, function ($r) { return $r['measurable']; });
        ?>
        <?php if (empty($impact)) : ?>
            <p>No broadcasts or releases to analyze yet.</p>
        <?php elseif (empty($has_measurable)) : ?>
            <div class="notice notice-info inline"><p>Initiatives found, but the follower trend needs ~1–2 weeks of daily snapshots before/after an event to measure lift. The daily snapshot runs automatically — check back soon.</p></div>
        <?php else : ?>
            <table class="widefat striped" style="max-width:900px;">
                <thead><tr><th>Initiative</th><th>Date</th><th>Type</th><th>Followers (7d)</th><th>Popularity (7d)</th><th>vs baseline</th><th>Clicks</th><th>Revenue</th></tr></thead>
                <tbody>
                <?php foreach ($impact as $r) : ?>
                    <tr<?php echo $r['measurable'] ? '' : ' style="opacity:.5;"'; ?>>
                        <td><strong><?php echo esc_html(mb_substr($r['label'], 0, 48)); ?></strong></td>
                        <td><?php echo esc_html($r['date']); ?></td>
                        <td><?php echo esc_html($r['type']); ?></td>
                        <td><?php
                            if (!$r['measurable']) { echo '—'; }
                            else { $c = $r['fdelta'] >= 0 ? '#1DB954' : '#F87171'; echo '<span style="color:' . $c . ';font-weight:600;">' . ($r['fdelta'] >= 0 ? '+' : '') . number_format_i18n($r['fdelta']) . '</span>'; }
                        ?></td>
                        <td><?php
                            if (!$r['measurable']) { echo '—'; }
                            else { $c = $r['pdelta'] >= 0 ? '#1DB954' : '#F87171'; echo '<span style="color:' . $c . ';">' . ($r['pdelta'] >= 0 ? '+' : '') . (int) $r['pdelta'] . '</span>'; }
                        ?></td>
                        <td><?php echo ($r['measurable'] && $r['lift'] !== null) ? (($r['lift'] >= 0 ? '+' : '') . number_format_i18n(round($r['lift']))) : '—'; ?></td>
                        <td><?php echo $r['clicks'] === null ? '—' : (int) $r['clicks']; ?></td>
                        <td><?php echo $r['rev'] ? esc_html(lmeg_format_price((int) $r['rev'])) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description"><strong>vs baseline</strong> = follower change beyond your normal daily growth rate over the same window. Positive = the initiative likely accelerated growth.</p>
        <?php endif; ?>

        <h2>Daily history</h2>
        <?php
        $history    = lmeg_spotify_history();
        $export_url = wp_nonce_url(admin_url('admin-post.php?action=lmeg_spotify_export'), 'lmeg_spotify_export');
        ?>
        <p>
            <form method="post" style="display:inline;">
                <?php wp_nonce_field('lmeg_sp_history', 'lmeg_sp_nonce'); ?>
                <input type="hidden" name="lmeg_action" value="capture" />
                <button type="submit" class="button button-primary">Capture today's numbers now</button>
            </form>
            <?php if ($history) : ?>
                <a class="button" href="<?php echo esc_url($export_url); ?>">Export CSV</a>
            <?php endif; ?>
            <span style="margin-left:10px;opacity:.65;">A snapshot is captured automatically each day. <?php echo count($history); ?> day<?php echo count($history) === 1 ? '' : 's'; ?> on record.</span>
        </p>

        <?php if ($history) : ?>
            <table class="widefat striped" style="max-width:640px;">
                <thead><tr><th>Date</th><th>Followers</th><th>Δ</th><th>Popularity</th><th>Δ</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($history, 0, 120) as $h) : ?>
                    <tr>
                        <td><?php echo esc_html($h['date']); ?></td>
                        <td style="font-variant-numeric:tabular-nums;"><?php echo number_format_i18n($h['followers']); ?></td>
                        <td><?php
                            if ($h['fdelta'] === null) { echo '<span style="opacity:.4;">—</span>'; }
                            else { $c = $h['fdelta'] > 0 ? '#1DB954' : ($h['fdelta'] < 0 ? '#F87171' : '#8B90A0'); echo '<span style="color:' . $c . ';">' . ($h['fdelta'] > 0 ? '+' : '') . number_format_i18n($h['fdelta']) . '</span>'; }
                        ?></td>
                        <td style="font-variant-numeric:tabular-nums;"><?php echo (int) $h['popularity']; ?></td>
                        <td><?php
                            if ($h['pdelta'] === null) { echo '<span style="opacity:.4;">—</span>'; }
                            else { $c = $h['pdelta'] > 0 ? '#1DB954' : ($h['pdelta'] < 0 ? '#F87171' : '#8B90A0'); echo '<span style="color:' . $c . ';">' . ($h['pdelta'] > 0 ? '+' : '') . (int) $h['pdelta'] . '</span>'; }
                        ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($history) > 120) : ?><p class="description">Showing latest 120 days — full history in the CSV export.</p><?php endif; ?>
        <?php else : ?>
            <p>No snapshots yet. Hit "Capture today's numbers now" to record the first data point — then it builds daily.</p>
        <?php endif; ?>

        <h3>Seed historical data</h3>
        <p class="description" style="max-width:820px;">Spotify's API can't tell us your past follower counts, so history starts the day you connect. If you have older numbers (a spreadsheet, S4A exports, screenshots), upload a CSV to backfill the charts. Format: <code>date,followers,popularity</code> — one row per day, e.g. <code>2026-06-01,58200,36</code>. Re-importing a date overwrites it.</p>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('lmeg_sp_history', 'lmeg_sp_nonce'); ?>
            <input type="hidden" name="lmeg_action" value="import" />
            <input type="file" name="csv" accept=".csv" required />
            <button type="submit" class="button">Import CSV</button>
        </form>
    </div>
    <?php
}
