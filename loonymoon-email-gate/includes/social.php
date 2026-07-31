<?php
/**
 * Social Listening — a read on the artist's social presence from their OWN
 * connected accounts: follower-growth trends (Spotify now, Instagram once
 * connected) and AI sentiment on recent Instagram post comments.
 *
 * "Sound usage" (tracking how the artist's song is used across other people's
 * Reels/TikToks) needs a third-party sound-recognition data provider and can't
 * be sourced from the artist's own accounts — surfaced as unavailable.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------------------------
 * Instagram account stats (followers / media count)
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
 * Daily follower snapshot (Instagram) — piggybacks the minute tick, guarded
 * to run about once a day. Spotify already snapshots itself on overview.
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
        . "\"watch\" (array of up to 2 short notes on negative/complaint themes, or empty).";
    $resp = wp_remote_post(LMEG_AI_ENDPOINT, [
        'timeout' => 45,
        'headers' => ['x-api-key' => $s['ai_api_key'], 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'],
        'body'    => wp_json_encode([
            'model'      => $model,
            'max_tokens' => 800,
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
    $artist = function_exists('lmeg_artist') ? lmeg_artist() : get_bloginfo('name');
    $sp_ok  = function_exists('lmeg_spotify_configured') && lmeg_spotify_configured();
    $ig_ok  = function_exists('lmeg_ig_configured') && lmeg_ig_configured();
    $ai_ok  = function_exists('lmeg_ai_configured') && lmeg_ai_configured();
    $card   = 'background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;';
    ?>
    <div class="wrap">
        <h1>Fanloop — Social Listening</h1>
        <p style="max-width:820px;">A read on <?php echo esc_html($artist); ?>'s social presence, straight from your connected accounts — follower growth and how fans are reacting.</p>

        <h2>Growth trends</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;max-width:1000px;">
        <?php if ($sp_ok) :
            $ov    = lmeg_spotify_overview();
            $snaps = function_exists('lmeg_spotify_snapshots') ? lmeg_spotify_snapshots(60) : [];
            $vals  = array_map(function ($r) { return (int) $r->followers; }, (array) $snaps);
            $spark = lmeg_social_sparkline($vals);
            $delta = (count($vals) >= 2) ? ($vals[count($vals) - 1] - $vals[0]) : 0;
        ?>
            <div style="<?php echo $card; ?>">
                <div style="font-weight:600;">🎧 Spotify followers</div>
                <div style="font-size:26px;font-weight:700;margin:4px 0;color:#111;"><?php echo is_wp_error($ov) ? '—' : number_format_i18n($ov['followers']); ?></div>
                <?php if (count($vals) >= 2) : ?><div style="font-size:12px;color:<?php echo $delta >= 0 ? '#16a34a' : '#dc2626'; ?>;"><?php echo ($delta >= 0 ? '▲ +' : '▼ ') . number_format_i18n(abs($delta)); ?> over <?php echo count($vals); ?> days</div><?php endif; ?>
                <?php if ($spark) : ?><svg viewBox="0 0 320 60" width="100%" height="60" style="margin-top:8px;"><polyline fill="none" stroke="#1DB954" stroke-width="2" points="<?php echo esc_attr($spark); ?>"/></svg><?php else : ?><p class="description">History builds day by day.</p><?php endif; ?>
            </div>
        <?php else : ?>
            <div style="background:#fff;border:1px dashed #dcdcde;border-radius:10px;padding:16px;">
                <div style="font-weight:600;">🎧 Spotify</div>
                <p class="description"><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-spotify')); ?>">Connect Spotify</a> to track follower growth.</p>
            </div>
        <?php endif; ?>

        <?php if ($ig_ok) :
            $ig     = lmeg_ig_account_stats();
            $isnaps = lmeg_social_snapshots('instagram', 60);
            $ivals  = array_map(function ($r) { return (int) $r->followers; }, (array) $isnaps);
            $ispark = lmeg_social_sparkline($ivals);
            $idelta = (count($ivals) >= 2) ? ($ivals[count($ivals) - 1] - $ivals[0]) : 0;
        ?>
            <div style="<?php echo $card; ?>">
                <div style="font-weight:600;">📸 Instagram followers</div>
                <div style="font-size:26px;font-weight:700;margin:4px 0;color:#111;"><?php echo $ig ? number_format_i18n($ig['followers']) : '—'; ?></div>
                <?php if (count($ivals) >= 2) : ?><div style="font-size:12px;color:<?php echo $idelta >= 0 ? '#16a34a' : '#dc2626'; ?>;"><?php echo ($idelta >= 0 ? '▲ +' : '▼ ') . number_format_i18n(abs($idelta)); ?> over <?php echo count($ivals); ?> days</div><?php endif; ?>
                <?php if ($ispark) : ?><svg viewBox="0 0 320 60" width="100%" height="60" style="margin-top:8px;"><polyline fill="none" stroke="#E1306C" stroke-width="2" points="<?php echo esc_attr($ispark); ?>"/></svg><?php else : ?><p class="description">Follower history starts today — the trend fills in over the next few days.</p><?php endif; ?>
            </div>
        <?php else : ?>
            <div style="background:#fff;border:1px dashed #dcdcde;border-radius:10px;padding:16px;">
                <div style="font-weight:600;">📸 Instagram</div>
                <p class="description"><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings')); ?>">Connect Instagram</a> to track follower growth + comment sentiment.</p>
            </div>
        <?php endif; ?>
        </div>

        <h2 style="margin-top:28px;">Comment sentiment</h2>
        <?php if (!$ig_ok || !$ai_ok) : ?>
            <p class="description" style="max-width:820px;">Needs <?php echo !$ig_ok ? 'Instagram connected' : ''; echo (!$ig_ok && !$ai_ok) ? ' and ' : ''; echo !$ai_ok ? 'your Anthropic API key (Settings → AI assistant)' : ''; ?>. Then Fanloop reads the comments on your recent posts and summarizes how fans feel.</p>
        <?php else : ?>
            <p style="max-width:820px;">Reads the comments on your recent Instagram posts and summarizes the mood — powered by your AI key. Cached ~6h.</p>
            <p><button type="button" class="button button-primary" id="lmeg-sent-btn">Analyze recent comments</button> <span id="lmeg-sent-status" style="font-size:12px;margin-left:8px;"></span></p>
            <div id="lmeg-sent-out" style="max-width:820px;"></div>
            <script>
            (function(){
                var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, nonce = <?php echo wp_json_encode(wp_create_nonce('lmeg_social')); ?>;
                var btn = document.getElementById('lmeg-sent-btn'), out = document.getElementById('lmeg-sent-out'), st = document.getElementById('lmeg-sent-status');
                function bar(label, pct, color){ pct = parseInt(pct||0,10); return '<div style="margin:5px 0;"><span style="display:inline-block;width:74px;">'+label+'</span><span style="display:inline-block;height:12px;width:'+Math.max(2,pct*2)+'px;background:'+color+';border-radius:6px;vertical-align:middle;"></span> '+pct+'%</div>'; }
                function esc(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
                btn.addEventListener('click', function(){
                    btn.disabled = true; st.textContent = 'Reading comments…';
                    var fd = new FormData(); fd.append('action','lmeg_social_sentiment'); fd.append('nonce',nonce);
                    fetch(ajax,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
                        if (d && d.success) { var x = d.data;
                            var html = '<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;margin-top:10px;color:#111;">';
                            html += bar('Positive', x.positive, '#16a34a') + bar('Neutral', x.neutral, '#9ca3af') + bar('Negative', x.negative, '#dc2626');
                            if (x.themes && x.themes.length) html += '<p style="margin:12px 0 4px;font-weight:600;">What they’re talking about</p><div>'+x.themes.map(function(t){return '<span style="display:inline-block;background:#eef2ff;color:#3730a3;border-radius:999px;padding:3px 10px;margin:2px;font-size:12px;">'+esc(t)+'</span>';}).join('')+'</div>';
                            if (x.highlights && x.highlights.length) html += '<p style="margin:12px 0 4px;font-weight:600;">Highlights</p><ul style="margin:0 0 0 18px;">'+x.highlights.map(function(h){return '<li>“'+esc(h)+'”</li>';}).join('')+'</ul>';
                            if (x.watch && x.watch.length) html += '<p style="margin:12px 0 4px;font-weight:600;color:#b45309;">Worth watching</p><ul style="margin:0 0 0 18px;">'+x.watch.map(function(w){return '<li>'+esc(w)+'</li>';}).join('')+'</ul>';
                            html += '<p class="description" style="margin-top:10px;">Based on '+(parseInt(x._count||0,10))+' recent comments.</p></div>';
                            out.innerHTML = html; st.textContent = '';
                        } else { st.textContent = '⚠ ' + ((d && d.data && d.data.msg) || 'error'); }
                    }).catch(function(){ st.textContent = '⚠ network error'; }).finally(function(){ btn.disabled = false; });
                });
            })();
            </script>
        <?php endif; ?>

        <h2 style="margin-top:28px;">Sound usage</h2>
        <p class="description" style="max-width:820px;">Tracking where your song is used across other people's Reels/TikToks needs a third-party sound-recognition data provider (that's Cobrand's edge — a 100M-sound database). It can't be pulled from your own accounts, so it isn't part of Fanloop. If you subscribe to a provider with an API, tell me and I can wire it in.</p>
    </div>
    <?php
}
