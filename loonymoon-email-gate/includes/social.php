<?php
/**
 * Social Listening — a read on the artist's social presence from their OWN
 * connected accounts:
 *   • Audience snapshot   — IG + Spotify followers + owned fan-list, at a glance
 *   • Growth trends       — follower sparklines + per-day rate + 30-day change
 *   • Content performance — top posts by engagement, engagement rate, cadence,
 *                           story-mention (UGC) count
 *   • Fan sentiment       — AI over recent Instagram comments
 *   • Listening digest    — an AI brief that turns it all into "do next" actions
 *
 * "Sound usage" (how the artist's song is used in OTHER people's Reels/TikToks)
 * needs a third-party sound-recognition provider and can't be sourced from the
 * artist's own accounts — surfaced as unavailable.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------------------------
 * Instagram account stats (followers / following / media count)
 * ------------------------------------------------------------------------- */

function lmeg_ig_account_stats($force = false) {
    if (!function_exists('lmeg_ig_configured') || !lmeg_ig_configured()) return null;
    $cache = 'lmeg_ig_acct_stats';
    if (!$force) { $c = get_transient($cache); if (is_array($c)) return $c; }
    $s = lmeg_get_settings();
    $resp = wp_remote_get(
        LMEG_IG_GRAPH . '/' . rawurlencode($s['ig_account_id'])
            . '?fields=username,followers_count,media_count,follows_count&access_token=' . rawurlencode($s['ig_page_token']),
        ['timeout' => 12]
    );
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) return null;
    $d = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($d) || !isset($d['followers_count'])) return null;
    $out = [
        'username'    => (string) ($d['username'] ?? ''),
        'followers'   => (int) $d['followers_count'],
        'media_count' => (int) ($d['media_count'] ?? 0),
        'follows'     => (int) ($d['follows_count'] ?? 0),
    ];
    set_transient($cache, $out, HOUR_IN_SECONDS);
    return $out;
}

/* ---------------------------------------------------------------------------
 * Daily follower snapshot (Instagram) — piggybacks the minute tick, guarded to
 * run about once a day. Spotify already snapshots itself on overview.
 * ------------------------------------------------------------------------- */

add_action('lmeg_broadcast_tick', 'lmeg_social_snapshot_tick', 55);
function lmeg_social_snapshot_tick() {
    if (get_transient('lmeg_social_snap_done')) return;
    set_transient('lmeg_social_snap_done', 1, DAY_IN_SECONDS);
    $st = lmeg_ig_account_stats(true);
    if (!$st) return;
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->prefix}lmeg_social_snapshots (platform, snap_date, followers, media_count, created_at)
         VALUES ('instagram', %s, %d, %d, %s)
         ON DUPLICATE KEY UPDATE followers = VALUES(followers), media_count = VALUES(media_count)",
        current_time('Y-m-d'), $st['followers'], $st['media_count'], current_time('mysql')
    ));
}

function lmeg_social_snapshots($platform, $days = 60) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT snap_date, followers, media_count FROM {$wpdb->prefix}lmeg_social_snapshots
         WHERE platform = %s AND snap_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY) ORDER BY snap_date ASC",
        $platform, (int) $days
    ));
}

/** current value, total change over the window, and average change/day. */
function lmeg_social_series_stats($rows, $field = 'followers') {
    $vals = [];
    foreach ((array) $rows as $r) { $vals[] = (int) $r->$field; }
    $n = count($vals);
    if ($n === 0) return ['current' => null, 'delta' => 0, 'days' => 0, 'per_day' => 0, 'vals' => []];
    $delta = $vals[$n - 1] - $vals[0];
    $days  = max(1, $n - 1);
    return [
        'current' => $vals[$n - 1],
        'delta'   => $delta,
        'days'    => $days,
        'per_day' => round($delta / $days, 1),
        'vals'    => $vals,
    ];
}

/* ---------------------------------------------------------------------------
 * Content performance — recent posts + engagement
 * ------------------------------------------------------------------------- */

function lmeg_social_ig_media($limit = 25, $force = false) {
    if (!lmeg_ig_configured()) return [];
    $cache = 'lmeg_social_ig_media';
    if (!$force) { $c = get_transient($cache); if (is_array($c)) return $c; }
    $s = lmeg_get_settings();
    $resp = wp_remote_get(
        LMEG_IG_GRAPH . '/' . rawurlencode($s['ig_account_id'])
            . '/media?fields=id,caption,media_type,permalink,timestamp,like_count,comments_count&limit=' . (int) $limit
            . '&access_token=' . rawurlencode($s['ig_page_token']),
        ['timeout' => 15]
    );
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) return [];
    $d = json_decode(wp_remote_retrieve_body($resp), true);
    $out = [];
    foreach ((array) ($d['data'] ?? []) as $m) {
        $out[] = [
            'id'        => (string) ($m['id'] ?? ''),
            'caption'   => (string) ($m['caption'] ?? ''),
            'type'      => (string) ($m['media_type'] ?? ''),
            'permalink' => (string) ($m['permalink'] ?? ''),
            'timestamp' => (string) ($m['timestamp'] ?? ''),
            'likes'     => (int) ($m['like_count'] ?? 0),
            'comments'  => (int) ($m['comments_count'] ?? 0),
        ];
    }
    set_transient($cache, $out, HOUR_IN_SECONDS);
    return $out;
}

function lmeg_social_ig_content_stats() {
    $media = lmeg_social_ig_media(25);
    if (!$media) return null;
    $ig        = lmeg_ig_account_stats();
    $followers = $ig ? max(1, $ig['followers']) : 0;
    $total_eng = 0;
    $times     = [];
    foreach ($media as $m) {
        $total_eng += $m['likes'] + $m['comments'];
        if ($m['timestamp']) $times[] = strtotime($m['timestamp']);
    }
    $count   = count($media);
    $avg_eng = $count ? $total_eng / $count : 0;
    sort($times);
    $cadence = (count($times) >= 2)
        ? round((($times[count($times) - 1] - $times[0]) / (count($times) - 1)) / DAY_IN_SECONDS, 1)
        : 0;
    usort($media, function ($a, $b) { return ($b['likes'] + $b['comments']) <=> ($a['likes'] + $a['comments']); });
    return [
        'count'    => $count,
        'avg_eng'  => (int) round($avg_eng),
        'eng_rate' => $followers ? round(100 * $avg_eng / $followers, 2) : 0,
        'cadence'  => $cadence,
        'top'      => array_slice($media, 0, 8),
    ];
}

/** Story mentions received in the last N days (UGC signal). */
function lmeg_social_story_mentions($days = 30) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}lmeg_ig_messages
         WHERE source = 'story' AND direction = 'in' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
        (int) $days
    ));
}

/* ---------------------------------------------------------------------------
 * Comment sentiment — AI over recent Instagram post comments
 * ------------------------------------------------------------------------- */

function lmeg_social_ig_comments($max = 80) {
    if (!lmeg_ig_configured()) return [];
    $s    = lmeg_get_settings();
    $base = LMEG_IG_GRAPH . '/' . rawurlencode($s['ig_account_id']);
    $tok  = rawurlencode($s['ig_page_token']);
    $media = wp_remote_get($base . '/media?fields=id,comments_count&limit=12&access_token=' . $tok, ['timeout' => 12]);
    if (is_wp_error($media) || wp_remote_retrieve_response_code($media) !== 200) return [];
    $md = json_decode(wp_remote_retrieve_body($media), true);
    $comments = [];
    foreach ((array) ($md['data'] ?? []) as $m) {
        if (count($comments) >= $max) break;
        if (empty($m['comments_count'])) continue;
        $cr = wp_remote_get(LMEG_IG_GRAPH . '/' . rawurlencode($m['id']) . '/comments?fields=text&limit=25&access_token=' . $tok, ['timeout' => 12]);
        if (is_wp_error($cr) || wp_remote_retrieve_response_code($cr) !== 200) continue;
        $cd = json_decode(wp_remote_retrieve_body($cr), true);
        foreach ((array) ($cd['data'] ?? []) as $c) {
            $t = trim((string) ($c['text'] ?? ''));
            if ($t !== '') $comments[] = mb_substr($t, 0, 300);
            if (count($comments) >= $max) break;
        }
    }
    return $comments;
}

function lmeg_social_sentiment($force = false) {
    $cache = 'lmeg_social_sentiment';
    if (!$force) { $c = get_transient($cache); if (is_array($c)) return $c; }
    if (!function_exists('lmeg_ai_configured') || !lmeg_ai_configured()) {
        return new WP_Error('lmeg_ai_unconfigured', 'Add your Anthropic API key in Settings → AI assistant to analyze sentiment.');
    }
    if (!lmeg_ig_configured()) {
        return new WP_Error('lmeg_ig_unconfigured', 'Connect Instagram first (Settings → Instagram).');
    }
    $comments = lmeg_social_ig_comments(80);
    if (!$comments) return new WP_Error('lmeg_social_nocomments', 'No recent Instagram comments found to analyze.');

    $s     = lmeg_get_settings();
    $model = $s['ai_model'] ?: 'claude-haiku-4-5-20251001';
    $list  = "- " . implode("\n- ", $comments);
    $system = "You analyze fan comment sentiment for the artist " . lmeg_artist() . ". "
        . "Given recent Instagram comments, return ONLY a raw JSON object (no prose) with keys: "
        . "\"positive\" (int percent), \"neutral\" (int percent), \"negative\" (int percent) — these three sum to ~100; "
        . "\"themes\" (array of up to 5 short strings — what fans are talking about); "
        . "\"highlights\" (array of up to 3 short verbatim standout positive comments); "
        . "\"questions\" (array of up to 3 short verbatim questions fans are asking that deserve a reply); "
        . "\"watch\" (array of up to 2 short notes on negative/complaint themes, or empty).";
    $resp = wp_remote_post(LMEG_AI_ENDPOINT, [
        'timeout' => 45,
        'headers' => ['x-api-key' => $s['ai_api_key'], 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'],
        'body'    => wp_json_encode([
            'model'      => $model,
            'max_tokens' => 900,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => "Comments:\n" . $list]],
        ]),
    ]);
    if (is_wp_error($resp)) return $resp;
    if (wp_remote_retrieve_response_code($resp) !== 200) {
        $e = json_decode(wp_remote_retrieve_body($resp), true);
        return new WP_Error('lmeg_ai_http', $e['error']['message'] ?? 'AI request failed.');
    }
    $d = json_decode(wp_remote_retrieve_body($resp), true);
    $text = '';
    foreach ((array) ($d['content'] ?? []) as $b) {
        if (($b['type'] ?? '') === 'text') $text .= $b['text'];
    }
    if (preg_match('/\{.*\}/s', $text, $m)) $text = $m[0];
    $parsed = json_decode($text, true);
    if (!is_array($parsed)) return new WP_Error('lmeg_ai_parse', 'Unexpected AI response — try again.');
    $parsed['_count'] = count($comments);
    set_transient($cache, $parsed, 6 * HOUR_IN_SECONDS);
    return $parsed;
}

add_action('wp_ajax_lmeg_social_sentiment', 'lmeg_ajax_social_sentiment');
function lmeg_ajax_social_sentiment() {
    if (!current_user_can('manage_options')) wp_send_json_error(['msg' => 'forbidden'], 403);
    check_ajax_referer('lmeg_social', 'nonce');
    $r = lmeg_social_sentiment(true);
    if (is_wp_error($r)) wp_send_json_error(['msg' => $r->get_error_message()]);
    wp_send_json_success($r);
}

/* ---------------------------------------------------------------------------
 * Listening digest — one AI brief over everything, with "do next" actions
 * ------------------------------------------------------------------------- */

function lmeg_social_ai_digest($force = false) {
    $cache = 'lmeg_social_digest';
    if (!$force) { $c = get_transient($cache); if (is_string($c) && $c !== '') return $c; }
    if (!function_exists('lmeg_ai_configured') || !lmeg_ai_configured()) {
        return new WP_Error('lmeg_ai_unconfigured', 'Add your Anthropic API key in Settings → AI assistant.');
    }
    $lines = [];
    if (lmeg_ig_configured()) {
        $ig = lmeg_ig_account_stats();
        if ($ig) $lines[] = "Instagram: " . number_format($ig['followers']) . " followers, " . number_format($ig['media_count']) . " posts.";
        $igs = lmeg_social_series_stats(lmeg_social_snapshots('instagram', 30));
        if ($igs['days'] >= 1) $lines[] = "IG follower change: " . ($igs['delta'] >= 0 ? '+' : '') . $igs['delta'] . " over " . $igs['days'] . " days (" . $igs['per_day'] . "/day).";
        $cs = lmeg_social_ig_content_stats();
        if ($cs) $lines[] = "Recent " . $cs['count'] . " posts: avg " . $cs['avg_eng'] . " engagements each, " . $cs['eng_rate'] . "% engagement rate, ~" . $cs['cadence'] . " days between posts.";
        $sm = lmeg_social_story_mentions(30);
        $lines[] = "Story mentions (30d): " . $sm . ".";
    }
    if (function_exists('lmeg_spotify_configured') && lmeg_spotify_configured()) {
        $ov = lmeg_spotify_overview();
        if (!is_wp_error($ov)) $lines[] = "Spotify: " . number_format($ov['followers']) . " followers, popularity " . $ov['popularity'] . "/100.";
    }
    $sent = get_transient('lmeg_social_sentiment');
    if (is_array($sent)) {
        $lines[] = "Comment sentiment: {$sent['positive']}% positive / {$sent['neutral']}% neutral / {$sent['negative']}% negative. Themes: " . implode(', ', array_slice((array) ($sent['themes'] ?? []), 0, 5)) . ".";
    }
    if (!$lines) return new WP_Error('lmeg_social_nodata', 'Connect Instagram or Spotify first — no social data to summarize yet.');

    $s      = lmeg_get_settings();
    $model  = $s['ai_model'] ?: 'claude-haiku-4-5-20251001';
    $system = "You are the social-listening analyst for the artist " . lmeg_artist() . " (fan community \"" . lmeg_community() . "\"). "
        . "From the DATA, write a tight brief (~110 words max): 2-3 sentences on how their social presence is doing (cite the numbers), then a line starting with **Do next:** and 2-3 concrete, specific actions. No fluff, no invented numbers. Plain text + light markdown.";
    $resp = wp_remote_post(LMEG_AI_ENDPOINT, [
        'timeout' => 45,
        'headers' => ['x-api-key' => $s['ai_api_key'], 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'],
        'body'    => wp_json_encode([
            'model'      => $model,
            'max_tokens' => 500,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => "DATA:\n" . implode("\n", $lines)]],
        ]),
    ]);
    if (is_wp_error($resp)) return $resp;
    if (wp_remote_retrieve_response_code($resp) !== 200) {
        $e = json_decode(wp_remote_retrieve_body($resp), true);
        return new WP_Error('lmeg_ai_http', $e['error']['message'] ?? 'AI request failed.');
    }
    $d = json_decode(wp_remote_retrieve_body($resp), true);
    $text = '';
    foreach ((array) ($d['content'] ?? []) as $b) {
        if (($b['type'] ?? '') === 'text') $text .= $b['text'];
    }
    $text = trim($text);
    if ($text === '') return new WP_Error('lmeg_ai_empty', 'The model returned no text.');
    set_transient($cache, $text, 6 * HOUR_IN_SECONDS);
    return $text;
}

add_action('wp_ajax_lmeg_social_digest', 'lmeg_ajax_social_digest');
function lmeg_ajax_social_digest() {
    if (!current_user_can('manage_options')) wp_send_json_error(['msg' => 'forbidden'], 403);
    check_ajax_referer('lmeg_social', 'nonce');
    $r = lmeg_social_ai_digest(true);
    if (is_wp_error($r)) wp_send_json_error(['msg' => $r->get_error_message()]);
    wp_send_json_success(['digest' => $r]);
}

/* ---------------------------------------------------------------------------
 * Sparkline helper
 * ------------------------------------------------------------------------- */

function lmeg_social_sparkline($vals, $w = 320, $h = 60) {
    $vals = array_values(array_map('intval', (array) $vals));
    if (count($vals) < 2) return '';
    $min = min($vals); $max = max($vals); $range = max(1, $max - $min); $step = $w / (count($vals) - 1); $pts = [];
    foreach ($vals as $i => $v) {
        $x = round($i * $step, 1);
        $y = round($h - (($v - $min) / $range) * ($h - 6) - 3, 1);
        $pts[] = "$x,$y";
    }
    return implode(' ', $pts);
}

/* ---------------------------------------------------------------------------
 * Admin page
 * ------------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_submenu_page('lmeg', 'Social Listening', 'Social Listening', 'manage_options', 'lmeg-social', 'lmeg_admin_social');
}, 21);

function lmeg_admin_social() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $artist = function_exists('lmeg_artist') ? lmeg_artist() : get_bloginfo('name');
    $sp_ok  = function_exists('lmeg_spotify_configured') && lmeg_spotify_configured();
    $ig_ok  = function_exists('lmeg_ig_configured') && lmeg_ig_configured();
    $ai_ok  = function_exists('lmeg_ai_configured') && lmeg_ai_configured();
    $card   = 'background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;';
    $dash   = 'background:#fff;border:1px dashed #dcdcde;border-radius:10px;padding:16px;';

    // Pull the data once.
    $ig       = $ig_ok ? lmeg_ig_account_stats() : null;
    $ig_stats = lmeg_social_series_stats(lmeg_social_snapshots('instagram', 30));
    $ov       = $sp_ok ? lmeg_spotify_overview() : null;
    $sp_snaps = ($sp_ok && function_exists('lmeg_spotify_snapshots')) ? lmeg_spotify_snapshots(60) : [];
    $sp_stats = lmeg_social_series_stats($sp_snaps);
    $fan_ct   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE unsubscribed_at IS NULL");
    $stories  = $ig_ok ? lmeg_social_story_mentions(30) : 0;
    $content  = $ig_ok ? lmeg_social_ig_content_stats() : null;

    $delta_html = function ($d, $per_day = null, $days = null) {
        if ($d === 0 && !$per_day) return '';
        $col = $d >= 0 ? '#16a34a' : '#dc2626';
        $txt = ($d >= 0 ? '▲ +' : '▼ ') . number_format_i18n(abs($d));
        if ($days) $txt .= ' / ' . (int) $days . 'd';
        return '<div style="font-size:12px;color:' . $col . ';margin-top:2px;">' . $txt . '</div>';
    };
    ?>
    <div class="wrap">
        <h1>Fanloop — Social Listening</h1>
        <p style="max-width:820px;">A read on <?php echo esc_html($artist); ?>'s social presence, straight from your connected accounts — audience, growth, content, and how fans feel.</p>

        <?php if (!$ig_ok) : ?>
            <div class="notice notice-info" style="max-width:900px;"><p><strong>Connect Instagram</strong> (<a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings')); ?>">Settings → Instagram</a>) to light up followers, top posts, story mentions, and comment sentiment. Spotify + your fan list show below now.</p></div>
        <?php endif; ?>

        <h2>Audience</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;max-width:1000px;margin-bottom:8px;">
            <div style="<?php echo $card; ?>">
                <div style="font-weight:600;">📸 Instagram</div>
                <div style="font-size:24px;font-weight:700;color:#111;"><?php echo $ig ? number_format_i18n($ig['followers']) : '—'; ?></div>
                <div style="font-size:12px;color:#3c434a;"><?php echo $ig ? 'followers · ' . number_format_i18n($ig['media_count']) . ' posts' : 'not connected'; ?></div>
                <?php echo $delta_html($ig_stats['delta'], $ig_stats['per_day'], $ig_stats['days']); ?>
            </div>
            <div style="<?php echo $card; ?>">
                <div style="font-weight:600;">🎧 Spotify</div>
                <div style="font-size:24px;font-weight:700;color:#111;"><?php echo (!$ov || is_wp_error($ov)) ? '—' : number_format_i18n($ov['followers']); ?></div>
                <div style="font-size:12px;color:#3c434a;"><?php echo (!$ov || is_wp_error($ov)) ? 'not connected' : 'followers · popularity ' . (int) $ov['popularity'] . '/100'; ?></div>
                <?php echo $delta_html($sp_stats['delta'], $sp_stats['per_day'], $sp_stats['days']); ?>
            </div>
            <div style="<?php echo $card; ?>">
                <div style="font-weight:600;">💜 Your fan list</div>
                <div style="font-size:24px;font-weight:700;color:#111;"><?php echo number_format_i18n($fan_ct); ?></div>
                <div style="font-size:12px;color:#3c434a;">owned contacts you can reach anytime</div>
            </div>
            <div style="<?php echo $card; ?>">
                <div style="font-weight:600;">🔁 Story mentions</div>
                <div style="font-size:24px;font-weight:700;color:#111;"><?php echo $ig_ok ? number_format_i18n($stories) : '—'; ?></div>
                <div style="font-size:12px;color:#3c434a;">tagged you in a story · last 30 days</div>
            </div>
        </div>

        <h2 style="margin-top:24px;">Growth trends</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;max-width:1000px;">
            <?php if ($ig_ok) : $ispark = lmeg_social_sparkline($ig_stats['vals']); ?>
            <div style="<?php echo $card; ?>">
                <div style="font-weight:600;">📸 Instagram followers</div>
                <div style="font-size:22px;font-weight:700;color:#111;margin:2px 0;"><?php echo $ig ? number_format_i18n($ig['followers']) : '—'; ?></div>
                <?php if ($ig_stats['days'] >= 1) : ?><div style="font-size:12px;color:#3c434a;"><?php echo ($ig_stats['delta'] >= 0 ? '+' : '') . number_format_i18n($ig_stats['delta']); ?> over <?php echo (int) $ig_stats['days']; ?> days · <?php echo $ig_stats['per_day']; ?>/day</div><?php endif; ?>
                <?php if ($ispark) : ?><svg viewBox="0 0 320 60" width="100%" height="60" style="margin-top:8px;"><polyline fill="none" stroke="#E1306C" stroke-width="2" points="<?php echo esc_attr($ispark); ?>"/></svg><?php else : ?><p class="description">Follower history starts on connect — the line fills in over the next few days.</p><?php endif; ?>
            </div>
            <?php else : ?>
            <div style="<?php echo $dash; ?>"><div style="font-weight:600;">📸 Instagram</div><p class="description"><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings')); ?>">Connect</a> to track follower growth.</p></div>
            <?php endif; ?>

            <?php if ($sp_ok) : $spark = lmeg_social_sparkline($sp_stats['vals']); ?>
            <div style="<?php echo $card; ?>">
                <div style="font-weight:600;">🎧 Spotify followers</div>
                <div style="font-size:22px;font-weight:700;color:#111;margin:2px 0;"><?php echo (!$ov || is_wp_error($ov)) ? '—' : number_format_i18n($ov['followers']); ?></div>
                <?php if ($sp_stats['days'] >= 1) : ?><div style="font-size:12px;color:#3c434a;"><?php echo ($sp_stats['delta'] >= 0 ? '+' : '') . number_format_i18n($sp_stats['delta']); ?> over <?php echo (int) $sp_stats['days']; ?> days · <?php echo $sp_stats['per_day']; ?>/day</div><?php endif; ?>
                <?php if ($spark) : ?><svg viewBox="0 0 320 60" width="100%" height="60" style="margin-top:8px;"><polyline fill="none" stroke="#1DB954" stroke-width="2" points="<?php echo esc_attr($spark); ?>"/></svg><?php else : ?><p class="description">History builds day by day.</p><?php endif; ?>
            </div>
            <?php else : ?>
            <div style="<?php echo $dash; ?>"><div style="font-weight:600;">🎧 Spotify</div><p class="description"><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-spotify')); ?>">Connect</a> to track follower growth.</p></div>
            <?php endif; ?>
        </div>

        <h2 style="margin-top:24px;">Content performance</h2>
        <?php if (!$ig_ok) : ?>
            <p class="description" style="max-width:820px;">Connect Instagram to see your top posts, engagement rate, and how often you post.</p>
        <?php elseif (!$content) : ?>
            <p class="description" style="max-width:820px;">No recent posts found yet.</p>
        <?php else : ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;max-width:760px;margin-bottom:10px;">
                <div style="<?php echo $card; ?>"><div style="font-weight:600;font-size:13px;">Engagement rate</div><div style="font-size:22px;font-weight:700;color:#111;"><?php echo $content['eng_rate']; ?>%</div><div style="font-size:12px;color:#3c434a;">likes+comments ÷ followers</div></div>
                <div style="<?php echo $card; ?>"><div style="font-weight:600;font-size:13px;">Avg per post</div><div style="font-size:22px;font-weight:700;color:#111;"><?php echo number_format_i18n($content['avg_eng']); ?></div><div style="font-size:12px;color:#3c434a;">engagements</div></div>
                <div style="<?php echo $card; ?>"><div style="font-weight:600;font-size:13px;">Posting cadence</div><div style="font-size:22px;font-weight:700;color:#111;"><?php echo $content['cadence']; ?></div><div style="font-size:12px;color:#3c434a;">days between posts</div></div>
            </div>
            <h3 style="margin:14px 0 6px;">Top posts</h3>
            <table class="widefat striped" style="max-width:900px;">
                <thead><tr><th>Post</th><th>Type</th><th>❤️ Likes</th><th>💬 Comments</th><th>When</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($content['top'] as $p) :
                    $cap = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($p['caption'])));
                    $cap = $cap !== '' ? mb_substr($cap, 0, 70) : '(no caption)';
                ?>
                    <tr>
                        <td style="max-width:360px;"><?php echo esc_html($cap); ?></td>
                        <td style="font-size:12px;color:#3c434a;"><?php echo esc_html(ucwords(strtolower(str_replace('_', ' ', $p['type'])))); ?></td>
                        <td><strong><?php echo number_format_i18n($p['likes']); ?></strong></td>
                        <td><?php echo number_format_i18n($p['comments']); ?></td>
                        <td style="font-size:12px;color:#3c434a;"><?php echo $p['timestamp'] ? esc_html(date_i18n('M j', strtotime($p['timestamp']))) : '—'; ?></td>
                        <td><?php if ($p['permalink']) : ?><a href="<?php echo esc_url($p['permalink']); ?>" target="_blank" rel="noopener">View ↗</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2 style="margin-top:24px;">Fan sentiment</h2>
        <?php if (!$ig_ok || !$ai_ok) : ?>
            <p class="description" style="max-width:820px;">Needs <?php echo !$ig_ok ? 'Instagram connected' : ''; echo (!$ig_ok && !$ai_ok) ? ' and ' : ''; echo !$ai_ok ? 'your Anthropic API key (Settings → AI assistant)' : ''; ?>. Then Fanloop reads your recent comments and summarizes the mood, themes, standout comments, and questions worth answering.</p>
        <?php else : ?>
            <p style="max-width:820px;">Reads the comments on your recent Instagram posts and summarizes the mood — powered by your AI key. Cached ~6h.</p>
            <p><button type="button" class="button button-primary" id="lmeg-sent-btn">Analyze recent comments</button> <span id="lmeg-sent-status" style="font-size:12px;margin-left:8px;"></span></p>
            <div id="lmeg-sent-out" style="max-width:820px;"></div>
        <?php endif; ?>

        <?php if ($ai_ok && ($ig_ok || $sp_ok)) : ?>
        <h2 style="margin-top:24px;">Listening digest</h2>
        <p style="max-width:820px;">One AI brief across everything above — how you're doing and what to do next.</p>
        <p><button type="button" class="button button-primary" id="lmeg-digest-btn">Generate digest</button> <span id="lmeg-digest-status" style="font-size:12px;margin-left:8px;"></span></p>
        <div id="lmeg-digest-out" style="max-width:820px;"></div>
        <?php endif; ?>

        <?php if (($ig_ok && $ai_ok) || ($ai_ok && ($ig_ok || $sp_ok))) : ?>
        <script>
        (function(){
            var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, nonce = <?php echo wp_json_encode(wp_create_nonce('lmeg_social')); ?>;
            function esc(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
            function md(s){ return esc(s).replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>').replace(/\n/g,'<br>'); }

            var sBtn = document.getElementById('lmeg-sent-btn');
            if (sBtn) {
                var sOut = document.getElementById('lmeg-sent-out'), sSt = document.getElementById('lmeg-sent-status');
                function bar(label, pct, color){ pct = parseInt(pct||0,10); return '<div style="margin:5px 0;"><span style="display:inline-block;width:74px;">'+label+'</span><span style="display:inline-block;height:12px;width:'+Math.max(2,pct*2)+'px;background:'+color+';border-radius:6px;vertical-align:middle;"></span> '+pct+'%</div>'; }
                sBtn.addEventListener('click', function(){
                    sBtn.disabled = true; sSt.textContent = 'Reading comments…';
                    var fd = new FormData(); fd.append('action','lmeg_social_sentiment'); fd.append('nonce',nonce);
                    fetch(ajax,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
                        if (d && d.success) { var x = d.data;
                            var html = '<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;margin-top:10px;color:#111;">';
                            html += bar('Positive', x.positive, '#16a34a') + bar('Neutral', x.neutral, '#9ca3af') + bar('Negative', x.negative, '#dc2626');
                            if (x.themes && x.themes.length) html += '<p style="margin:12px 0 4px;font-weight:600;">What they’re talking about</p><div>'+x.themes.map(function(t){return '<span style="display:inline-block;background:#eef2ff;color:#3730a3;border-radius:999px;padding:3px 10px;margin:2px;font-size:12px;">'+esc(t)+'</span>';}).join('')+'</div>';
                            if (x.highlights && x.highlights.length) html += '<p style="margin:12px 0 4px;font-weight:600;">Highlights</p><ul style="margin:0 0 0 18px;">'+x.highlights.map(function(h){return '<li>“'+esc(h)+'”</li>';}).join('')+'</ul>';
                            if (x.questions && x.questions.length) html += '<p style="margin:12px 0 4px;font-weight:600;">Questions to answer</p><ul style="margin:0 0 0 18px;">'+x.questions.map(function(q){return '<li>'+esc(q)+'</li>';}).join('')+'</ul>';
                            if (x.watch && x.watch.length) html += '<p style="margin:12px 0 4px;font-weight:600;color:#b45309;">Worth watching</p><ul style="margin:0 0 0 18px;">'+x.watch.map(function(w){return '<li>'+esc(w)+'</li>';}).join('')+'</ul>';
                            html += '<p class="description" style="margin-top:10px;">Based on '+(parseInt(x._count||0,10))+' recent comments.</p></div>';
                            sOut.innerHTML = html; sSt.textContent = '';
                        } else { sSt.textContent = '⚠ ' + ((d && d.data && d.data.msg) || 'error'); }
                    }).catch(function(){ sSt.textContent = '⚠ network error'; }).finally(function(){ sBtn.disabled = false; });
                });
            }

            var dBtn = document.getElementById('lmeg-digest-btn');
            if (dBtn) {
                var dOut = document.getElementById('lmeg-digest-out'), dSt = document.getElementById('lmeg-digest-status');
                dBtn.addEventListener('click', function(){
                    dBtn.disabled = true; dSt.textContent = 'Thinking…';
                    var fd = new FormData(); fd.append('action','lmeg_social_digest'); fd.append('nonce',nonce);
                    fetch(ajax,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
                        if (d && d.success) { dOut.innerHTML = '<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;margin-top:10px;color:#111;line-height:1.6;">'+md(d.data.digest)+'</div>'; dSt.textContent=''; }
                        else { dSt.textContent = '⚠ ' + ((d && d.data && d.data.msg) || 'error'); }
                    }).catch(function(){ dSt.textContent = '⚠ network error'; }).finally(function(){ dBtn.disabled = false; });
                });
            }
        })();
        </script>
        <?php endif; ?>

        <h2 style="margin-top:24px;">Sound usage</h2>
        <p class="description" style="max-width:820px;">Tracking where your song is used across other people's Reels/TikToks needs a third-party sound-recognition data provider (that's Cobrand's edge — a 100M-sound database). It can't be pulled from your own accounts, so it isn't part of Fanloop. If you subscribe to a provider with an API, tell me and I can wire it in.</p>
    </div>
    <?php
}
