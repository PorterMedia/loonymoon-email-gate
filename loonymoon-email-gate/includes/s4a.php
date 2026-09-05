<?php
/**
 * Spotify for Artists (S4A) metrics — the streaming data the public Spotify Web
 * API can't see (streams, monthly + active listeners, saves, playlist adds,
 * super listeners, followers, top songs/playlists, listener geography).
 *
 * S4A has no API, so Fanloop can't fetch this itself — it has to be FED, two ways
 * that share one table (lmeg_s4a_snapshots):
 *   1. PASTE  — paste the S4A JSON export into Spotify for Artists → Import.
 *   2. PUSH   — POST the same JSON to ?lmeg_s4a=ingest&token=<secret> (for the
 *               Spotify-tracker pipeline / Porter Brain to push on a schedule).
 * Each import is a dated snapshot, so repeated imports build the trend.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('LMEG_S4A_DB_VERSION')) define('LMEG_S4A_DB_VERSION', '1');

function lmeg_s4a_table() { global $wpdb; return $wpdb->prefix . 'lmeg_s4a_snapshots'; }

add_action('init', 'lmeg_s4a_maybe_install', 1);
function lmeg_s4a_maybe_install() {
    if (get_option('lmeg_s4a_db_version') === LMEG_S4A_DB_VERSION) return;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    global $wpdb;
    $t = lmeg_s4a_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        artist VARCHAR(120) NOT NULL DEFAULT '',
        captured_date DATE NOT NULL,
        window VARCHAR(8) NOT NULL DEFAULT '28d',
        monthly_listeners BIGINT NULL,
        streams BIGINT NULL,
        mal BIGINT NULL,
        new_active BIGINT NULL,
        super_listeners BIGINT NULL,
        saves BIGINT NULL,
        playlist_adds BIGINT NULL,
        followers BIGINT NULL,
        streams_per_listener DECIMAL(6,2) NULL,
        changes TEXT NULL,
        top_songs TEXT NULL,
        top_playlists TEXT NULL,
        top_countries TEXT NULL,
        meta TEXT NULL,
        source VARCHAR(16) NOT NULL DEFAULT 'paste',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY artist_date_win (artist, captured_date, window),
        KEY artist (artist)
    ) $charset;");
    update_option('lmeg_s4a_db_version', LMEG_S4A_DB_VERSION);
}

/** Auth token for the push endpoint — generated once, stored in its own option. */
function lmeg_s4a_token() {
    $t = get_option('lmeg_s4a_token', '');
    if (!$t) { $t = wp_generate_password(32, false); update_option('lmeg_s4a_token', $t, false); }
    return $t;
}

/* ---------------------------------------------------------------------------
 * Parse + store
 * ------------------------------------------------------------------------- */

/**
 * Normalize an S4A payload (single-artist object, OR the {artists:[…]} wrapper
 * my export produces) into one or more snapshot rows. Tolerant of missing keys.
 *
 * @return array list of normalized rows
 */
function lmeg_s4a_parse($data) {
    if (is_string($data)) $data = json_decode($data, true);
    if (!is_array($data)) return [];
    $default_date = !empty($data['captured_at']) ? substr((string) $data['captured_at'], 0, 10) : current_time('Y-m-d');
    $default_win  = 'season';
    if (!empty($data['period']) && stripos((string) $data['period'], '7 day') !== false) $default_win = '7d';
    elseif (!empty($data['period']) && stripos((string) $data['period'], '28 day') !== false) $default_win = '28d';
    elseif (!empty($data['period']) && stripos((string) $data['period'], '12 month') !== false) $default_win = '12mo';
    else $default_win = '28d';

    $artists = isset($data['artists']) && is_array($data['artists']) ? $data['artists'] : [$data];
    $rows = [];
    foreach ($artists as $a) {
        if (!is_array($a)) continue;
        $ss = (array) ($a['streaming_stats_28d'] ?? $a['streaming_stats'] ?? []);
        $ad = (array) ($a['audience_development_28d'] ?? $a['audience_development'] ?? []);
        $num = function ($v) { return ($v === null || $v === '') ? null : (int) round((float) $v); };
        $rows[] = [
            'artist'               => sanitize_text_field((string) ($a['name'] ?? lmeg_artist())),
            'captured_date'        => $default_date,
            'window'               => $default_win,
            'monthly_listeners'    => $num($ss['monthly_listeners'] ?? null),
            'streams'              => $num($ss['streams'] ?? null),
            'mal'                  => $num($ad['monthly_active_listeners'] ?? $ss['monthly_active_listeners'] ?? null),
            'new_active'           => $num($ad['new_active_listeners'] ?? null),
            'super_listeners'      => $num($ad['super_listeners'] ?? null),
            'saves'                => $num($ss['saves'] ?? null),
            'playlist_adds'        => $num($ss['playlist_adds'] ?? null),
            'followers'            => $num($ss['followers'] ?? null),
            'streams_per_listener' => isset($ss['streams_per_listener']) ? (float) $ss['streams_per_listener'] : null,
            'changes'              => wp_json_encode([
                'monthly_listeners' => $ss['monthly_listeners_change_pct'] ?? null,
                'streams'           => $ss['streams_change_pct'] ?? null,
                'mal'               => $ad['monthly_active_listeners_change_pct'] ?? null,
                'new_active'        => $ad['new_active_listeners_change_pct'] ?? null,
                'super_listeners'   => $ad['super_listeners_change_pct'] ?? null,
                'saves'             => $ss['saves_change_pct'] ?? null,
                'playlist_adds'     => $ss['playlist_adds_change_pct'] ?? null,
                'followers'         => $ss['followers_change_pct'] ?? null,
            ]),
            'top_songs'            => wp_json_encode(array_values((array) ($a['songs'] ?? $a['top_songs_last_7d'] ?? $a['top_songs'] ?? []))),
            'top_playlists'        => wp_json_encode(array_values((array) ($a['top_playlists_last_7d'] ?? $a['top_playlists'] ?? []))),
            'top_countries'        => wp_json_encode(array_values((array) ($ad['top_active_listener_countries'] ?? $a['top_countries'] ?? []))),
            'meta'                 => wp_json_encode([
                'recent_release'      => $a['recent_release'] ?? null,
                'listening_now'       => $a['listening_now'] ?? null,
                'pct_streams_from_mal'=> $ad['pct_streams_from_mal'] ?? null,
                'spotify_artist_id'   => $a['spotify_artist_id'] ?? null,
                // Listener demographics + city geography the pull fetches — kept in
                // meta so the Insights page can show WHO and WHERE (no schema change).
                'gender'              => $a['gender'] ?? null,
                'gender_by_age'       => $a['gender_by_age'] ?? null,
                'top_cities'          => array_values((array) ($a['top_cities'] ?? [])),
                // 7-day per-song streams — lets the Insights page compute momentum
                // (recent 7d pace vs the 28d run-rate) from a single snapshot.
                'songs_7d'            => array_values((array) ($a['top_songs_last_7d'] ?? [])),
                // Per-release 28d streams — a COMPACT summary only (the full
                // raw.releases with recordingStats is ~50KB and would blow the
                // TEXT meta column; name/streams/type/date/uri is ~2KB).
                'releases'            => array_map(function ($r) {
                    return [
                        'name'    => (string) ($r['albumName'] ?? ''),
                        'streams' => (int) ($r['numStreams'] ?? 0),
                        'type'    => (string) ($r['releaseType'] ?? ''),
                        'date'    => (string) ($r['releaseDate'] ?? ''),
                        'uri'     => (string) ($r['albumUri'] ?? ''),
                    ];
                }, array_values((array) ($a['releases'] ?? ($a['raw']['releases']['releases'] ?? [])))),
            ]),
        ];
    }
    return $rows;
}

/** Upsert a snapshot row (unique on artist+date+window). Returns rows written. */
function lmeg_s4a_store($rows, $source = 'paste') {
    global $wpdb;
    $t = lmeg_s4a_table();
    $n = 0;
    foreach ((array) $rows as $r) {
        if (empty($r['captured_date']) || empty($r['artist'])) continue;
        $r['source']     = in_array($source, ['paste', 'push'], true) ? $source : 'paste';
        $r['created_at'] = current_time('mysql');
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t WHERE artist = %s AND captured_date = %s AND window = %s",
            $r['artist'], $r['captured_date'], $r['window']
        ));
        if ($exists) { $wpdb->update($t, $r, ['id' => (int) $exists]); }
        else { $wpdb->insert($t, $r); }
        $n++;
    }
    return $n;
}

/** The most recent snapshot for an artist (defaults to this site's artist). */
function lmeg_s4a_latest($artist = null, $window = '28d') {
    global $wpdb;
    $artist = $artist ?: lmeg_artist();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . lmeg_s4a_table() . " WHERE artist = %s AND window = %s ORDER BY captured_date DESC LIMIT 1",
        $artist, $window
    ));
}

/** A metric's value series over time (oldest→newest) for the trend chart. */
function lmeg_s4a_series($metric, $artist = null, $window = '28d', $limit = 60) {
    global $wpdb;
    $allowed = ['monthly_listeners', 'streams', 'mal', 'saves', 'playlist_adds', 'followers', 'super_listeners', 'new_active'];
    if (!in_array($metric, $allowed, true)) return [];
    $artist = $artist ?: lmeg_artist();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT captured_date, `$metric` v FROM " . lmeg_s4a_table() . "
         WHERE artist = %s AND window = %s AND `$metric` IS NOT NULL
         ORDER BY captured_date ASC LIMIT %d", $artist, $window, (int) $limit
    ));
    return $rows;
}

/** Distinct artists we have snapshots for (for the switcher). */
function lmeg_s4a_artists() {
    global $wpdb;
    return $wpdb->get_col("SELECT DISTINCT artist FROM " . lmeg_s4a_table() . " ORDER BY artist ASC");
}

/* ---------------------------------------------------------------------------
 * Push endpoint — POST JSON to ?lmeg_s4a=ingest&token=<secret>
 * ------------------------------------------------------------------------- */
add_action('init', 'lmeg_s4a_router');
function lmeg_s4a_router() {
    if (($_GET['lmeg_s4a'] ?? '') !== 'ingest') return;
    $given = isset($_GET['token']) ? (string) wp_unslash($_GET['token']) : '';
    if (!hash_equals(lmeg_s4a_token(), $given)) { status_header(401); exit; }
    $raw  = file_get_contents('php://input');
    $rows = lmeg_s4a_parse($raw);
    if (!$rows) { status_header(400); header('Content-Type: application/json'); echo '{"ok":false,"error":"no parseable data"}'; exit; }
    $n = lmeg_s4a_store($rows, 'push');
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode(['ok' => true, 'stored' => $n]);
    exit;
}

/* ---------------------------------------------------------------------------
 * Admin page
 * ------------------------------------------------------------------------- */
add_action('admin_menu', function () {
    add_submenu_page('lmeg', 'Spotify for Artists', 'Spotify for Artists', 'manage_options', 'lmeg-s4a', 'lmeg_admin_s4a');
}, 21);

function lmeg_admin_s4a() {
    if (!current_user_can('manage_options')) return;
    $notice = '';

    // Paste import.
    if (isset($_POST['lmeg_s4a_nonce']) && wp_verify_nonce($_POST['lmeg_s4a_nonce'], 'lmeg_s4a')) {
        $raw  = (string) wp_unslash($_POST['s4a_json'] ?? '');
        $rows = lmeg_s4a_parse($raw);
        if ($rows) {
            $n = lmeg_s4a_store($rows, 'paste');
            $names = implode(', ', array_map(function ($r) { return $r['artist']; }, $rows));
            $notice = '<div class="notice notice-success is-dismissible"><p>Imported <strong>' . (int) $n . '</strong> snapshot(s): ' . esc_html($names) . '.</p></div>';
        } else {
            $notice = '<div class="notice notice-error"><p>Couldn\'t parse that — paste the S4A JSON export (an object with <code>streaming_stats_28d</code>, or an <code>artists</code> array).</p></div>';
        }
    }

    $artists = lmeg_s4a_artists();
    $sel     = isset($_GET['artist']) ? sanitize_text_field(wp_unslash($_GET['artist'])) : (in_array(lmeg_artist(), $artists, true) ? lmeg_artist() : ($artists[0] ?? lmeg_artist()));
    $snap    = lmeg_s4a_latest($sel);
    $changes = $snap && $snap->changes ? (array) json_decode($snap->changes, true) : [];
    $card    = 'background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:14px 16px;color:#F4F5F7;';
    $lbl     = 'font:600 11px/1 var(--lmegA-font,inherit);letter-spacing:.06em;text-transform:uppercase;color:#8B90A0;';
    $ingest_url = home_url('/?lmeg_s4a=ingest&token=' . lmeg_s4a_token());

    $chip = function ($pct) {
        if ($pct === null || $pct === '') return '';
        $pct = (float) $pct; $c = $pct > 0 ? '#34D399' : ($pct < 0 ? '#F87171' : '#8B90A0');
        return '<span style="font-size:12px;font-weight:600;color:' . $c . ';margin-left:6px;">' . ($pct > 0 ? '+' : '') . rtrim(rtrim(number_format($pct, 1), '0'), '.') . '%</span>';
    };
    $kpi = function ($label, $val, $pct) use ($card, $lbl, $chip) {
        $v = ($val === null) ? '—' : number_format_i18n((int) $val);
        return '<div style="' . $card . '"><div style="font:800 26px/1 var(--lmegA-font,inherit);font-variant-numeric:tabular-nums;">' . esc_html($v) . $chip($pct) . '</div><div style="' . $lbl . 'margin-top:6px;">' . esc_html($label) . '</div></div>';
    };
    ?>
    <div class="wrap lmeg-admin">
        <h1>Fanloop — Spotify for Artists</h1>
        <?php echo $notice; ?>
        <p style="max-width:820px;">The streaming numbers the public Spotify API can't see — <strong>streams, listeners, saves, playlist adds, super listeners, followers</strong> — pulled from your Spotify&nbsp;for&nbsp;Artists dashboard. Import a snapshot below; each import builds the trend.</p>

        <?php if (count($artists) > 1) : ?>
        <p style="margin:10px 0;">
            <?php foreach ($artists as $a) : ?>
                <a href="<?php echo esc_url(add_query_arg('artist', rawurlencode($a))); ?>" class="button<?php echo $a === $sel ? ' button-primary' : ''; ?>" style="margin-right:4px;"><?php echo esc_html($a); ?></a>
            <?php endforeach; ?>
        </p>
        <?php endif; ?>

        <?php if ($snap) : ?>
            <p style="color:#8B90A0;margin:6px 0 12px;"><strong style="color:#F4F5F7;"><?php echo esc_html($snap->artist); ?></strong> · last <?php echo esc_html($snap->window); ?> · captured <?php echo esc_html($snap->captured_date); ?></p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;max-width:1040px;margin-bottom:14px;">
                <?php
                echo $kpi('Monthly listeners', $snap->monthly_listeners, $changes['monthly_listeners'] ?? null);
                echo $kpi('Streams', $snap->streams, $changes['streams'] ?? null);
                echo $kpi('Active listeners', $snap->mal, $changes['mal'] ?? null);
                echo $kpi('New active', $snap->new_active, $changes['new_active'] ?? null);
                echo $kpi('Super listeners', $snap->super_listeners, $changes['super_listeners'] ?? null);
                echo $kpi('Saves', $snap->saves, $changes['saves'] ?? null);
                echo $kpi('Playlist adds', $snap->playlist_adds, $changes['playlist_adds'] ?? null);
                echo $kpi('Followers', $snap->followers, $changes['followers'] ?? null);
                ?>
            </div>

            <?php
            $ser = lmeg_s4a_series('streams', $sel, $snap->window);
            if (count($ser) >= 2 && function_exists('lmeg_chart_line')) : ?>
            <div style="<?php echo $card; ?>max-width:1040px;margin-bottom:14px;">
                <div style="<?php echo $lbl; ?>margin-bottom:6px;">Streams over time</div>
                <?php echo lmeg_chart_line(array_map(function ($r) { return (int) $r->v; }, $ser), [
                    'color' => '#1DB954', 'uid' => 's4a-streams', 'h' => 66,
                    'labels' => array_map(function ($r) { return date_i18n('M j', strtotime($r->captured_date)); }, $ser),
                    'suffix' => ' streams',
                ]); ?>
            </div>
            <?php elseif (function_exists('lmeg_s4a_series')) : ?>
                <p class="description" style="max-width:820px;">Import a couple more snapshots and a streams-over-time chart appears here.</p>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;max-width:1040px;">
                <?php
                $list = function ($title, $json, $key, $valkey, $limit = 6) use ($card, $lbl) {
                    $items = (array) json_decode((string) $json, true);
                    if (!$items) return;
                    $more = count($items) > $limit ? ' <span style="color:#8B90A0;font-weight:400;">(' . count($items) . ')</span>' : '';
                    echo '<div style="' . $card . ($limit > 8 ? 'max-height:360px;overflow:auto;' : '') . '"><div style="' . $lbl . 'margin-bottom:10px;">' . esc_html($title) . $more . '</div>';
                    foreach (array_slice($items, 0, $limit) as $it) {
                        if (is_array($it)) {
                            $name = (string) ($it[$key] ?? ''); $val = isset($it[$valkey]) ? number_format_i18n((int) $it[$valkey]) : '';
                        } else { $name = (string) $it; $val = ''; }
                        echo '<div style="display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:13px;"><span style="color:#F4F5F7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . esc_html($name) . '</span><span style="color:#8B90A0;font-variant-numeric:tabular-nums;">' . esc_html($val) . '</span></div>';
                    }
                    echo '</div>';
                };
                $list('Songs', $snap->top_songs, 'title', 'streams', 30);
                $list('Top playlists', $snap->top_playlists, 'name', 'streams');
                $list('Top active markets', $snap->top_countries, 'x', 'y');
                ?>
            </div>
        <?php else : ?>
            <div style="<?php echo $card; ?>max-width:820px;">No snapshots yet — paste your first S4A export below.</div>
        <?php endif; ?>

        <h2 style="margin-top:26px;">Import a snapshot</h2>
        <form method="post" style="max-width:820px;">
            <?php wp_nonce_field('lmeg_s4a', 'lmeg_s4a_nonce'); ?>
            <p class="description" style="margin:0 0 6px;">Paste the S4A JSON export (Claude produces this format, or your Spotify-tracker pipeline). One artist or an <code>artists</code> array.</p>
            <textarea name="s4a_json" rows="8" class="large-text code" placeholder='{"captured_at":"2026-09-05","period":"Last 28 days","artists":[{"name":"LOONY","streaming_stats_28d":{"monthly_listeners":183873,"streams":417940, …}}]}'></textarea>
            <p><button type="submit" class="button button-primary">Import</button></p>
        </form>

        <h2 style="margin-top:22px;">Automate it (push endpoint)</h2>
        <p class="description" style="max-width:820px;">Have your Spotify-tracker pipeline (or Porter Brain) <code>POST</code> the same JSON here on a schedule and it stays current, hands-off:</p>
        <p><code style="display:inline-block;background:#12141F;border:1px solid rgba(255,255,255,.12);border-radius:8px;padding:8px 12px;word-break:break-all;max-width:820px;"><?php echo esc_html($ingest_url); ?></code></p>
        <p class="description">Keep that token secret — anyone with it can write S4A snapshots. Regenerate by deleting the <code>lmeg_s4a_token</code> option.</p>
    </div>
    <?php
}
