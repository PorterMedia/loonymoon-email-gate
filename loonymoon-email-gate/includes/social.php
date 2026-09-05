<?php
/**
 * Social Listening — a read on the artist's social presence from their OWN
 * connected accounts:
 *   • Audience snapshot   — IG + Spotify followers + owned fan-list, at a glance
 *   • Follower demographics — gender / age / country / city (IG insights;
 *                           needs the instagram_manage_insights scope)
 *   • Growth trends       — follower sparklines + per-day rate + 30-day change
 *   • Content performance — top posts by engagement, engagement rate, cadence,
 *                           format breakdown, hashtag performance (lift vs avg),
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
    global $wpdb;
    $today = current_time('Y-m-d');
    $now   = current_time('mysql');

    // Instagram followers + post count.
    $st = lmeg_ig_account_stats(true);
    if ($st) {
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}lmeg_social_snapshots (platform, snap_date, followers, media_count, created_at)
             VALUES ('instagram', %s, %d, %d, %s)
             ON DUPLICATE KEY UPDATE followers = VALUES(followers), media_count = VALUES(media_count)",
            $today, $st['followers'], $st['media_count'], $now
        ));
    }

    // Facebook Page followers (same connection — no reconnect needed).
    $fb = function_exists('lmeg_fb_page_stats') ? lmeg_fb_page_stats(true) : null;
    if ($fb) {
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}lmeg_social_snapshots (platform, snap_date, followers, media_count, created_at)
             VALUES ('facebook', %s, %d, %d, %s)
             ON DUPLICATE KEY UPDATE followers = VALUES(followers)",
            $today, $fb['followers'], 0, $now
        ));
    }
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
    $vals = []; $labels = [];
    foreach ((array) $rows as $r) {
        $vals[]   = (int) $r->$field;
        $labels[] = isset($r->snap_date) ? date_i18n('M j', strtotime($r->snap_date)) : '';
    }
    $n = count($vals);
    if ($n === 0) return ['current' => null, 'delta' => 0, 'days' => 0, 'per_day' => 0, 'vals' => [], 'labels' => []];
    $delta = $vals[$n - 1] - $vals[0];
    $days  = max(1, $n - 1);
    return [
        'current' => $vals[$n - 1],
        'delta'   => $delta,
        'days'    => $days,
        'per_day' => round($delta / $days, 1),
        'vals'    => $vals,
        'labels'  => $labels,
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
            . '/media?fields=id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count&limit=' . (int) $limit
            . '&access_token=' . rawurlencode($s['ig_page_token']),
        ['timeout' => 15]
    );
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) return [];
    $d = json_decode(wp_remote_retrieve_body($resp), true);
    $out = [];
    foreach ((array) ($d['data'] ?? []) as $m) {
        // thumbnail_url is set for VIDEO; media_url for IMAGE (and often CAROUSEL cover).
        $thumb = (string) ($m['thumbnail_url'] ?? ($m['media_url'] ?? ''));
        $out[] = [
            'id'        => (string) ($m['id'] ?? ''),
            'caption'   => (string) ($m['caption'] ?? ''),
            'type'      => (string) ($m['media_type'] ?? ''),
            'thumb'     => $thumb,
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
        $ts = $m['timestamp'] ? strtotime($m['timestamp']) : false;
        if ($ts) $times[] = $ts;
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

/**
 * Best day to post — which weekday the artist's own posts have historically
 * landed the most engagement on. A rough owned-data heuristic (true
 * audience-online timing needs the insights permission), so it's labelled
 * modestly. Returns null until there's enough post history.
 */
function lmeg_social_ig_best_time() {
    $media = lmeg_social_ig_media(25);
    if (count($media) < 5) return null;
    $by_dow = [];
    foreach ($media as $m) {
        $ts = $m['timestamp'] ? strtotime($m['timestamp']) : false;
        if (!$ts) continue;
        $by_dow[(int) wp_date('w', $ts)][] = $m['likes'] + $m['comments'];
    }
    if (!$by_dow) return null;
    $best = null; $best_avg = -1; $n = 0;
    foreach ($by_dow as $d => $arr) {
        $avg = array_sum($arr) / count($arr);
        $n  += count($arr);
        if ($avg > $best_avg) { $best_avg = $avg; $best = $d; }
    }
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    return ['day' => $days[$best], 'samples' => $n];
}

/**
 * Which content format earns the most engagement — Reels/Video vs Photo vs
 * Carousel — from the artist's own recent posts. Sorted best-first.
 */
function lmeg_social_ig_type_breakdown() {
    $media = lmeg_social_ig_media(25);
    if (count($media) < 3) return null;
    $by = [];
    foreach ($media as $m) {
        $t = $m['type'] !== '' ? $m['type'] : 'UNKNOWN';
        if (!isset($by[$t])) $by[$t] = ['count' => 0, 'eng' => 0];
        $by[$t]['count']++;
        $by[$t]['eng'] += $m['likes'] + $m['comments'];
    }
    $labels = ['VIDEO' => 'Reels / Video', 'IMAGE' => 'Photos', 'CAROUSEL_ALBUM' => 'Carousels', 'UNKNOWN' => 'Other'];
    $out = [];
    foreach ($by as $t => $d) {
        $out[] = [
            'label' => $labels[$t] ?? ucwords(strtolower(str_replace('_', ' ', $t))),
            'count' => $d['count'],
            'avg'   => (int) round($d['eng'] / max(1, $d['count'])),
        ];
    }
    usort($out, function ($a, $b) { return $b['avg'] <=> $a['avg']; });
    return $out;
}

/**
 * Hashtag performance — which hashtags in the artist's OWN recent captions
 * correlate with the strongest posts. Derived entirely from the media we
 * already fetch (no extra API calls), so it lights up the moment Instagram is
 * connected. "lift" = how a hashtag's average engagement compares with the
 * account's average post — the listening insight ("#loonybin posts do +34%").
 *
 * @return array|null ['avg_all'=>int, 'tags'=>[ ['tag','posts','avg','lift'], … ]]
 */
function lmeg_social_ig_hashtags($limit = 10, $min_posts = 2) {
    $media = lmeg_social_ig_media(25);
    if (count($media) < 4) return null;
    $tags = []; $eng_all = 0; $n = 0;
    foreach ($media as $m) {
        $eng = (int) $m['likes'] + (int) $m['comments'];
        $eng_all += $eng; $n++;
        if (!preg_match_all('/#([\p{L}0-9_]{2,60})/u', (string) $m['caption'], $mm)) continue;
        $seen = [];
        foreach ($mm[1] as $raw) {
            $tag = function_exists('mb_strtolower') ? mb_strtolower($raw) : strtolower($raw);
            if (isset($seen[$tag])) continue; // count a tag once per post
            $seen[$tag] = 1;
            if (!isset($tags[$tag])) $tags[$tag] = ['posts' => 0, 'eng' => 0];
            $tags[$tag]['posts']++;
            $tags[$tag]['eng']  += $eng;
        }
    }
    $avg_all = $n ? $eng_all / $n : 0;
    $out = [];
    foreach ($tags as $tag => $d) {
        if ($d['posts'] < $min_posts) continue;
        $avg = $d['eng'] / max(1, $d['posts']);
        $out[] = [
            'tag'   => $tag,
            'posts' => (int) $d['posts'],
            'avg'   => (int) round($avg),
            'lift'  => $avg_all > 0 ? (int) round(100 * ($avg - $avg_all) / $avg_all) : 0,
        ];
    }
    if (!$out) return null;
    usort($out, function ($a, $b) { return $b['avg'] <=> $a['avg']; });
    return ['avg_all' => (int) round($avg_all), 'tags' => array_slice($out, 0, (int) $limit)];
}

/**
 * Follower demographics — gender, age, country, city — the marquee "who your
 * audience is" read (matches Cobrand's Social Listening Overview). Uses the
 * Instagram Graph `follower_demographics` insight (period=lifetime,
 * metric_type=total_value, with breakdowns). Requires the
 * `instagram_manage_insights` scope on the connection (added to the connect
 * flow) and a Business/Creator account with ≥100 followers — until the artist
 * reconnects to grant it, every call fails gracefully and returns null so the
 * page shows a "reconnect to unlock" nudge rather than an error.
 *
 * @return array|null ['gender'=>[k=>n], 'age'=>[k=>n], 'country'=>[cc=>n], 'city'=>[name=>n]]
 */
function lmeg_social_ig_demographics($force = false) {
    if (!function_exists('lmeg_ig_configured') || !lmeg_ig_configured()) return null;
    $cache = 'lmeg_social_ig_demo';
    if (!$force) { $c = get_transient($cache); if (is_array($c)) return ($c === ['__none__' => 1]) ? null : $c; }

    $s    = lmeg_get_settings();
    $base = LMEG_IG_GRAPH . '/' . rawurlencode($s['ig_account_id']) . '/insights';
    $tok  = rawurlencode($s['ig_page_token']);

    // One insight call per breakdown; a breakdown returns
    // total_value.breakdowns[0].results[] = { dimension_values:[…], value:N }.
    $pull = function ($breakdown) use ($base, $tok) {
        $resp = wp_remote_get(
            $base . '?metric=follower_demographics&period=lifetime&metric_type=total_value'
                  . '&breakdown=' . rawurlencode($breakdown) . '&access_token=' . $tok,
            ['timeout' => 12]
        );
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) return [];
        $d = json_decode(wp_remote_retrieve_body($resp), true);
        $res = $d['data'][0]['total_value']['breakdowns'][0]['results'] ?? null;
        if (!is_array($res)) return [];
        return $res;
    };

    // age,gender in one call → split into two distributions.
    $gender = []; $age = [];
    foreach ($pull('age,gender') as $r) {
        $dv = $r['dimension_values'] ?? []; $v = (int) ($r['value'] ?? 0);
        if (count($dv) < 2) continue;
        $a = (string) $dv[0]; $g = strtoupper((string) $dv[1]);
        $age[$a]    = ($age[$a] ?? 0) + $v;
        $gender[$g] = ($gender[$g] ?? 0) + $v;
    }
    $country = []; foreach ($pull('country') as $r) { $cc = strtoupper((string) ($r['dimension_values'][0] ?? '')); if ($cc !== '') $country[$cc] = (int) ($r['value'] ?? 0); }
    $city    = []; foreach ($pull('city')    as $r) { $nm = (string) ($r['dimension_values'][0] ?? '');            if ($nm !== '') $city[$nm]    = (int) ($r['value'] ?? 0); }

    if (!$gender && !$age && !$country && !$city) {
        set_transient($cache, ['__none__' => 1], 3 * HOUR_IN_SECONDS); // remember "unavailable" briefly
        return null;
    }
    // Sort each distribution most-first (age keeps its natural bucket order).
    arsort($gender); arsort($country); arsort($city);
    $age_order = ['13-17', '18-24', '25-34', '35-44', '45-54', '55-64', '65+'];
    $age_sorted = [];
    foreach ($age_order as $k) if (isset($age[$k])) $age_sorted[$k] = $age[$k];
    foreach ($age as $k => $v) if (!isset($age_sorted[$k])) $age_sorted[$k] = $v; // any unexpected bucket last
    $out = ['gender' => $gender, 'age' => $age_sorted, 'country' => $country, 'city' => $city];
    set_transient($cache, $out, 12 * HOUR_IN_SECONDS);
    return $out;
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
 * Facebook Page — comes free with the Instagram connection. The OAuth grants
 * a Page token with `pages_read_engagement`, and the Page id is stored at
 * connect time (`lmeg_ig_page_id`), so the artist's FB Page needs no separate
 * connect: same token, same Graph host.
 * ------------------------------------------------------------------------- */

function lmeg_fb_configured() {
    $s = lmeg_get_settings();
    return !empty($s['ig_page_token']) && (bool) get_option('lmeg_ig_page_id');
}

/** Page name + follower count (falls back to fan/like count). 1h cache. */
function lmeg_fb_page_stats($force = false) {
    if (!lmeg_fb_configured()) return null;
    $cache = 'lmeg_fb_page_stats';
    if (!$force) { $c = get_transient($cache); if (is_array($c)) return $c; }
    $s   = lmeg_get_settings();
    $pid = get_option('lmeg_ig_page_id');
    $resp = wp_remote_get(
        LMEG_IG_GRAPH . '/' . rawurlencode($pid)
            . '?fields=name,followers_count,fan_count&access_token=' . rawurlencode($s['ig_page_token']),
        ['timeout' => 12]
    );
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) return null;
    $d = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($d) || isset($d['error'])) return null;
    $out = [
        'name'      => (string) ($d['name'] ?? ''),
        'followers' => isset($d['followers_count']) ? (int) $d['followers_count'] : (int) ($d['fan_count'] ?? 0),
        'fans'      => (int) ($d['fan_count'] ?? 0),
    ];
    set_transient($cache, $out, HOUR_IN_SECONDS);
    return $out;
}

/** Recent Page posts with engagement (reactions + comments + shares). 1h cache. */
function lmeg_fb_posts($limit = 25, $force = false) {
    if (!lmeg_fb_configured()) return [];
    $cache = 'lmeg_fb_posts';
    if (!$force) { $c = get_transient($cache); if (is_array($c)) return $c; }
    $s   = lmeg_get_settings();
    $pid = get_option('lmeg_ig_page_id');
    $resp = wp_remote_get(
        LMEG_IG_GRAPH . '/' . rawurlencode($pid)
            . '/posts?fields=id,message,story,full_picture,created_time,permalink_url,shares,'
            . 'comments.summary(true).limit(0),reactions.summary(true).limit(0)&limit=' . (int) $limit
            . '&access_token=' . rawurlencode($s['ig_page_token']),
        ['timeout' => 15]
    );
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) return [];
    $d = json_decode(wp_remote_retrieve_body($resp), true);
    $out = [];
    foreach ((array) ($d['data'] ?? []) as $p) {
        $out[] = [
            'id'        => (string) ($p['id'] ?? ''),
            'caption'   => (string) ($p['message'] ?? ($p['story'] ?? '')),
            'thumb'     => (string) ($p['full_picture'] ?? ''),
            'permalink' => (string) ($p['permalink_url'] ?? ''),
            'timestamp' => (string) ($p['created_time'] ?? ''),
            'likes'     => (int) ($p['reactions']['summary']['total_count'] ?? 0),
            'comments'  => (int) ($p['comments']['summary']['total_count'] ?? 0),
            'shares'    => (int) ($p['shares']['count'] ?? 0),
        ];
    }
    set_transient($cache, $out, HOUR_IN_SECONDS);
    return $out;
}

/** Aggregate FB content performance — mirrors the IG one, engagement adds shares. */
function lmeg_fb_content_stats() {
    $posts = lmeg_fb_posts(25);
    if (!$posts) return null;
    $fb        = lmeg_fb_page_stats();
    $followers = $fb ? max(1, $fb['followers']) : 0;
    $total_eng = 0; $times = [];
    foreach ($posts as $p) {
        $total_eng += $p['likes'] + $p['comments'] + $p['shares'];
        $ts = $p['timestamp'] ? strtotime($p['timestamp']) : false;
        if ($ts) $times[] = $ts;
    }
    $count   = count($posts);
    $avg_eng = $count ? $total_eng / $count : 0;
    sort($times);
    $cadence = (count($times) >= 2)
        ? round((($times[count($times) - 1] - $times[0]) / (count($times) - 1)) / DAY_IN_SECONDS, 1)
        : 0;
    usort($posts, function ($a, $b) {
        return (($b['likes'] + $b['comments'] + $b['shares']) <=> ($a['likes'] + $a['comments'] + $a['shares']));
    });
    return [
        'count'    => $count,
        'avg_eng'  => (int) round($avg_eng),
        'eng_rate' => $followers ? round(100 * $avg_eng / $followers, 2) : 0,
        'cadence'  => $cadence,
        'top'      => array_slice($posts, 0, 8),
    ];
}

/** Recent FB comment text, for sentiment. */
function lmeg_fb_comments($max = 60) {
    if (!lmeg_fb_configured()) return [];
    $s   = lmeg_get_settings();
    $pid = get_option('lmeg_ig_page_id');
    $tok = rawurlencode($s['ig_page_token']);
    $posts = wp_remote_get(
        LMEG_IG_GRAPH . '/' . rawurlencode($pid) . '/posts?fields=id,comments.summary(true).limit(0)&limit=12&access_token=' . $tok,
        ['timeout' => 12]
    );
    if (is_wp_error($posts) || wp_remote_retrieve_response_code($posts) !== 200) return [];
    $pd = json_decode(wp_remote_retrieve_body($posts), true);
    $comments = [];
    foreach ((array) ($pd['data'] ?? []) as $p) {
        if (count($comments) >= $max) break;
        if (empty($p['comments']['summary']['total_count'])) continue;
        $cr = wp_remote_get(LMEG_IG_GRAPH . '/' . rawurlencode($p['id']) . '/comments?fields=message&limit=25&access_token=' . $tok, ['timeout' => 12]);
        if (is_wp_error($cr) || wp_remote_retrieve_response_code($cr) !== 200) continue;
        $cd = json_decode(wp_remote_retrieve_body($cr), true);
        foreach ((array) ($cd['data'] ?? []) as $c) {
            $t = trim((string) ($c['message'] ?? ''));
            if ($t !== '') $comments[] = mb_substr($t, 0, 300);
            if (count($comments) >= $max) break;
        }
    }
    return $comments;
}

/* ---------------------------------------------------------------------------
 * Comment sentiment — AI over recent Instagram + Facebook post comments
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
    if (!lmeg_ig_configured() && !lmeg_fb_configured()) {
        return new WP_Error('lmeg_ig_unconfigured', 'Connect Instagram or Facebook first (Settings → Instagram).');
    }
    $comments = array_merge(lmeg_social_ig_comments(60), lmeg_fb_comments(40));
    if (!$comments) return new WP_Error('lmeg_social_nocomments', 'No recent Instagram or Facebook comments found to analyze.');

    $s     = lmeg_get_settings();
    $model = $s['ai_model'] ?: 'claude-haiku-4-5-20251001';
    $list  = "- " . implode("\n- ", $comments);
    $system = "You analyze fan comment sentiment for the artist " . lmeg_artist() . ". "
        . "Given recent Instagram and Facebook comments, return ONLY a raw JSON object (no prose) with keys: "
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
        $ht = lmeg_social_ig_hashtags();
        if ($ht && !empty($ht['tags'])) {
            $tops = array_slice($ht['tags'], 0, 3);
            $bits = [];
            foreach ($tops as $h) $bits[] = '#' . $h['tag'] . ' (' . ($h['lift'] >= 0 ? '+' : '') . $h['lift'] . '% vs avg, ' . $h['posts'] . ' posts)';
            $lines[] = "Top-performing hashtags: " . implode(', ', $bits) . ".";
        }
        $demo = lmeg_social_ig_demographics();
        if ($demo) {
            $fmtTop = function ($arr, $n, $flag = false) {
                $arr = (array) $arr; $tot = max(1, array_sum($arr)); $i = 0; $out = [];
                foreach ($arr as $k => $v) { if ($i++ >= $n) break; $lbl = ($flag && function_exists('lmeg_country_by_iso') && ($r = lmeg_country_by_iso($k))) ? $r[1] : $k; $out[] = $lbl . ' ' . round(100 * $v / $tot) . '%'; }
                return implode(', ', $out);
            };
            $parts = [];
            if (!empty($demo['country'])) $parts[] = "top countries " . $fmtTop($demo['country'], 3, true);
            if (!empty($demo['city']))    $parts[] = "top cities " . $fmtTop($demo['city'], 3);
            if (!empty($demo['age']))     $parts[] = "biggest age group " . $fmtTop($demo['age'], 1);
            if ($parts) $lines[] = "Audience: " . implode('; ', $parts) . ".";
        }
    }
    if (lmeg_fb_configured()) {
        $fb = lmeg_fb_page_stats();
        if ($fb) $lines[] = "Facebook Page: " . number_format($fb['followers']) . " followers.";
        $fbs = lmeg_social_series_stats(lmeg_social_snapshots('facebook', 30));
        if ($fbs['days'] >= 1) $lines[] = "FB follower change: " . ($fbs['delta'] >= 0 ? '+' : '') . $fbs['delta'] . " over " . $fbs['days'] . " days.";
        $fcs = lmeg_fb_content_stats();
        if ($fcs) $lines[] = "Recent " . $fcs['count'] . " FB posts: avg " . $fcs['avg_eng'] . " engagements each, " . $fcs['eng_rate'] . "% engagement rate.";
    }
    if (function_exists('lmeg_spotify_configured') && lmeg_spotify_configured()) {
        $ov = lmeg_spotify_overview();
        if (!is_wp_error($ov)) $lines[] = "Spotify: " . number_format($ov['followers']) . " followers, popularity " . $ov['popularity'] . "/100.";
    }
    $sent = get_transient('lmeg_social_sentiment');
    if (is_array($sent)) {
        $lines[] = "Comment sentiment: {$sent['positive']}% positive / {$sent['neutral']}% neutral / {$sent['negative']}% negative. Themes: " . implode(', ', array_slice((array) ($sent['themes'] ?? []), 0, 5)) . ".";
    }
    if (!$lines) return new WP_Error('lmeg_social_nodata', 'Connect Instagram, Facebook, or Spotify first — no social data to summarize yet.');

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
 * Demo / preview data — lets the artist see the full dashboard before their
 * accounts are connected. Deterministic sample values for an artist ~LOONY.
 * ------------------------------------------------------------------------- */

function lmeg_social_demo() {
    $mk = function ($vals) {
        $n = count($vals); $delta = $vals[$n - 1] - $vals[0]; $days = max(1, $n - 1);
        $labels = [];
        for ($i = 0; $i < $n; $i++) { $labels[] = date_i18n('M j', strtotime('-' . ($n - 1 - $i) . ' days')); }
        return ['current' => $vals[$n - 1], 'delta' => $delta, 'days' => $days, 'per_day' => round($delta / $days, 1), 'vals' => $vals, 'labels' => $labels];
    };
    // Organic-looking growth (small daily variance incl. a few flat/down days)
    // so the trend lines read like real analytics rather than a synthetic ramp.
    $ig_vals = []; $v = 26540;
    foreach ([44, 58, 50, 30, 66, 48, 18, -9, 55, 74, 40, 47, 63, 26, -14, 60, 70, 44, 52, 78, 36, 21, -7, 68, 84, 49, 57, 72, 41, 61] as $d) { $v += $d; $ig_vals[] = $v; }
    $sp_vals = []; $v = 49760;
    foreach ([58, 66, 50, 74, 42, 61, 31, 70, 55, -11, 78, 63, 47, 72, 52, 40, 76, 59, -15, 84, 67, 50, 73, 61, 44, 69, 54, 63, 48, 71] as $d) { $v += $d; $sp_vals[] = $v; }
    $fb_vals = []; $v = 18240;
    foreach ([12, 18, 9, 22, 6, -4, 15, 20, 11, 17, -7, 24, 13, 8, 19, 5, -3, 21, 14, 10, 16, 23, 7, -5, 18, 12, 20, 9, 15, 22] as $d) { $v += $d; $fb_vals[] = $v; }

    $fb_top = [
        ['caption' => 'SOFT THING is out now — link in comments 🖤', 'permalink' => '#', 'timestamp' => '2026-07-31T15:00:00+0000', 'likes' => 612, 'comments' => 74, 'shares' => 128],
        ['caption' => 'Toronto — presale is live. Who’s coming? 🎟️', 'permalink' => '#', 'timestamp' => '2026-07-28T18:00:00+0000', 'likes' => 540, 'comments' => 96, 'shares' => 88],
        ['caption' => 'Thank you for 18k here 🥹 the LOONYBIN keeps growing', 'permalink' => '#', 'timestamp' => '2026-07-22T17:00:00+0000', 'likes' => 470, 'comments' => 52, 'shares' => 41],
        ['caption' => 'New merch drops Friday 👀🔥', 'permalink' => '#', 'timestamp' => '2026-07-16T19:00:00+0000', 'likes' => 388, 'comments' => 44, 'shares' => 33],
        ['caption' => 'Behind the scenes of the video 🎬', 'permalink' => '#', 'timestamp' => '2026-07-19T16:00:00+0000', 'likes' => 356, 'comments' => 29, 'shares' => 22],
        ['caption' => 'Should I release the acoustic version?', 'permalink' => '#', 'timestamp' => '2026-07-13T21:00:00+0000', 'likes' => 302, 'comments' => 61, 'shares' => 18],
    ];

    $top = [
        ['caption' => 'SOFT THING is OUT NOW 🖤 go stream it pls', 'type' => 'VIDEO', 'permalink' => '#', 'timestamp' => '2026-07-31T15:00:00+0000', 'likes' => 4210, 'comments' => 318],
        ['caption' => 'toronto we’re coming for u 🎟️ presale in bio', 'type' => 'CAROUSEL_ALBUM', 'permalink' => '#', 'timestamp' => '2026-07-28T18:00:00+0000', 'likes' => 3120, 'comments' => 402],
        ['caption' => 'studio dump 🎹 which one should i finish', 'type' => 'VIDEO', 'permalink' => '#', 'timestamp' => '2026-07-25T20:00:00+0000', 'likes' => 2890, 'comments' => 560],
        ['caption' => 'thank u for 27k 🥹 the LOONYBIN is everything', 'type' => 'IMAGE', 'permalink' => '#', 'timestamp' => '2026-07-22T17:00:00+0000', 'likes' => 2540, 'comments' => 210],
        ['caption' => 'behind the scenes of the video 👀', 'type' => 'VIDEO', 'permalink' => '#', 'timestamp' => '2026-07-19T16:00:00+0000', 'likes' => 2210, 'comments' => 145],
        ['caption' => 'new merch dropping friday 👀🔥', 'type' => 'CAROUSEL_ALBUM', 'permalink' => '#', 'timestamp' => '2026-07-16T19:00:00+0000', 'likes' => 1980, 'comments' => 176],
        ['caption' => 'acoustic version… should i release it?', 'type' => 'VIDEO', 'permalink' => '#', 'timestamp' => '2026-07-13T21:00:00+0000', 'likes' => 1740, 'comments' => 230],
        ['caption' => 'ootd for the show tonight 💜', 'type' => 'IMAGE', 'permalink' => '#', 'timestamp' => '2026-07-10T22:00:00+0000', 'likes' => 1320, 'comments' => 88],
    ];
    return [
        'ig'         => ['username' => 'loonymoonchild', 'followers' => end($ig_vals), 'media_count' => 142, 'follows' => 612],
        'ig_stats'   => $mk($ig_vals),
        'fb'         => ['name' => 'LOONY', 'followers' => end($fb_vals), 'fans' => end($fb_vals)],
        'fb_stats'   => $mk($fb_vals),
        'fb_content' => ['count' => 18, 'avg_eng' => 540, 'eng_rate' => 3.0, 'cadence' => 3.4, 'top' => $fb_top],
        'ov'         => [
            'name' => 'LOONY', 'followers' => end($sp_vals), 'popularity' => 47, 'url' => '#',
            'genres' => ['art pop', 'indie pop', 'canadian indie'],
            'top_tracks' => [
                ['name' => 'SOFT THING', 'popularity' => 68, 'album' => 'SOFT THING', 'url' => '#'],
                ['name' => 'Overgrown', 'popularity' => 61, 'album' => 'the LOONYBIN', 'url' => '#'],
                ['name' => 'Moonchild', 'popularity' => 57, 'album' => 'the LOONYBIN', 'url' => '#'],
                ['name' => 'Static', 'popularity' => 52, 'album' => 'Static / Bloom', 'url' => '#'],
                ['name' => 'Bloom', 'popularity' => 49, 'album' => 'Static / Bloom', 'url' => '#'],
            ],
            'releases' => [
                ['name' => 'SOFT THING', 'type' => 'single', 'date' => '2026-07-31', 'url' => '#', 'img' => ''],
                ['name' => 'Static / Bloom', 'type' => 'single', 'date' => '2026-05-14', 'url' => '#', 'img' => ''],
                ['name' => 'the LOONYBIN', 'type' => 'album', 'date' => '2026-02-20', 'url' => '#', 'img' => ''],
            ],
        ],
        'sp_stats'   => $mk($sp_vals),
        'fan_ct'     => 32140,
        'stories'    => 23,
        'content'    => ['count' => 25, 'avg_eng' => 1360, 'eng_rate' => 4.9, 'cadence' => 2.7, 'top' => $top],
        'best_day'   => ['day' => 'Friday', 'samples' => 25],
        'types'      => [
            ['label' => 'Reels / Video', 'count' => 13, 'avg' => 2210],
            ['label' => 'Carousels', 'count' => 6, 'avg' => 1490],
            ['label' => 'Photos', 'count' => 6, 'avg' => 1090],
        ],
        'hashtags'   => ['avg_all' => 1360, 'tags' => [
            ['tag' => 'softthing',  'posts' => 5, 'avg' => 2480, 'lift' => 82],
            ['tag' => 'loonybin',   'posts' => 9, 'avg' => 1990, 'lift' => 46],
            ['tag' => 'newmusic',   'posts' => 6, 'avg' => 1720, 'lift' => 26],
            ['tag' => 'liveshow',   'posts' => 4, 'avg' => 1610, 'lift' => 18],
            ['tag' => 'artpop',     'posts' => 7, 'avg' => 1180, 'lift' => -13],
            ['tag' => 'studio',     'posts' => 3, 'avg' => 980,  'lift' => -28],
        ]],
        'demographics' => [
            'gender'  => ['F' => 15820, 'M' => 9740, 'U' => 980],
            'age'     => ['13-17' => 1210, '18-24' => 9860, '25-34' => 8730, '35-44' => 3820, '45-54' => 1560, '55-64' => 720, '65+' => 640],
            'country' => ['CA' => 13380, 'US' => 7620, 'MX' => 940, 'GB' => 860, 'AU' => 520, 'DE' => 410],
            'city'    => ['Toronto' => 4820, 'Los Angeles' => 1910, 'New York City' => 1680, 'Montreal' => 1240, 'Vancouver' => 980, 'Mexico City' => 610],
        ],
        'sentiment'  => [
            'positive' => 79, 'neutral' => 17, 'negative' => 4,
            'themes'     => ['the new single', 'tour dates', 'merch', 'your voice', 'the music video'],
            'highlights' => ['this song is on repeat, obsessed 😭🖤', 'saw u live and cried, best night ever', 'ur the best artist of our generation fr'],
            'questions'  => ['when are u coming to vancouver??', 'is the vinyl restocking?', 'what mic do u use for vocals'],
            'watch'      => ['a few fans frustrated the presale sold out fast'],
            '_count'     => 80,
        ],
        'digest'     => "You’re on a strong run: Instagram is up ~+" . (end($ig_vals) - $ig_vals[0]) . " in 30 days and “SOFT THING” is driving your best engagement in months — Reels are landing about 2× your photos. Fan sentiment is 79% positive; the community is hyped on the single and asking for more tour cities.\n\n**Do next:** 1) Cut the acoustic version as a Reel and post it Friday (your best day). 2) Answer the top question in a story — fans want a Vancouver date. 3) Restock the vinyl; people are actively asking.",
    ];
}

/* ---------------------------------------------------------------------------
 * Post cards — a visual grid (thumbnail + caption + metrics) shared by the
 * Instagram and Facebook "top posts" sections. Real IG/FB posts show their
 * cover art (media_url / thumbnail_url / full_picture); when there's no image
 * (or in demo mode) a deterministic branded gradient with the platform glyph
 * stands in, so the grid always reads as a set.
 * ------------------------------------------------------------------------- */

function lmeg_social_render_post_cards($posts, $platform = 'instagram', $metrics = null) {
    if (!$posts) return;
    $glyph = $platform === 'facebook' ? 'facebook' : 'instagram';
    if ($metrics === null) {
        $metrics = [['icon' => 'heart', 'key' => 'likes'], ['icon' => 'message', 'key' => 'comments']];
    }
    $card = 'background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-radius:14px;overflow:hidden;display:block;color:#F4F5F7;text-decoration:none;';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;max-width:900px;">';
    foreach ($posts as $i => $p) {
        $cap = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) ($p['caption'] ?? ''))));
        $cap = $cap !== '' ? mb_substr($cap, 0, 90) : '(no caption)';
        $type = strtoupper((string) ($p['type'] ?? ''));
        $badge = strpos($type, 'VIDEO') !== false ? 'Reel' : (strpos($type, 'CAROUSEL') !== false ? 'Carousel' : (strpos($type, 'IMAGE') !== false ? 'Photo' : ''));
        $thumb = (string) ($p['thumb'] ?? '');
        $href  = (string) ($p['permalink'] ?? '');
        $linkable = $href !== '' && $href !== '#';
        $seed = crc32((string) ($p['id'] ?? $cap)); $a = $seed % 360; $b = ($a + 45) % 360;
        $tag  = $linkable ? 'a' : 'div';
        $attr = $linkable ? ' href="' . esc_url($href) . '" target="_blank" rel="noopener"' : '';
        $rank = $i === 0 ? '<span style="position:absolute;top:8px;left:8px;display:inline-flex;align-items:center;gap:4px;background:rgba(14,15,22,.72);color:#FBBF24;font-size:11px;font-weight:600;padding:3px 7px;border-radius:20px;">' . lmeg_icon('trophy', ['size' => 12, 'sw' => 2]) . 'Top</span>' : '';
        $badge_html = $badge !== '' ? '<span style="position:absolute;top:8px;right:8px;background:rgba(14,15,22,.72);color:#F4F5F7;font-size:11px;font-weight:600;padding:3px 8px;border-radius:20px;">' . esc_html($badge) . '</span>' : '';
        if ($thumb !== '') {
            $media = '<img src="' . esc_url($thumb) . '" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block;">';
        } else {
            $media = '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,hsl(' . $a . ',42%,30%),hsl(' . $b . ',48%,20%));color:rgba(255,255,255,.6);">' . lmeg_icon($glyph, ['size' => 30, 'sw' => 1.6]) . '</div>';
        }
        echo '<' . $tag . $attr . ' style="' . $card . '">';
        echo '<div style="position:relative;aspect-ratio:1/1;background:#0E0F16;">' . $media . $rank . $badge_html . '</div>';
        echo '<div style="padding:10px 12px 12px;">';
        echo '<div style="font-size:12.5px;line-height:1.35;color:#F4F5F7;min-height:34px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">' . esc_html($cap) . '</div>';
        echo '<div style="display:flex;align-items:center;gap:12px;margin-top:8px;flex-wrap:wrap;">';
        foreach ($metrics as $m) {
            $v = (int) ($p[$m['key']] ?? 0);
            echo '<span style="display:inline-flex;align-items:center;gap:5px;font-size:12.5px;color:#F4F5F7;font-variant-numeric:tabular-nums;"><span style="color:#8B90A0;display:inline-flex;">' . lmeg_icon($m['icon'], ['size' => 13, 'sw' => 2]) . '</span>' . number_format_i18n($v) . '</span>';
        }
        echo '</div>';
        $when = !empty($p['timestamp']) ? date_i18n('M j', strtotime($p['timestamp'])) : '';
        if ($when !== '') echo '<div style="font-size:11px;color:#8B90A0;margin-top:7px;">' . esc_html($when) . ($linkable ? ' · View ↗' : '') . '</div>';
        echo '</div></' . $tag . '>';
    }
    echo '</div>';
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
    $fb_ok  = function_exists('lmeg_fb_configured') && lmeg_fb_configured();
    $ai_ok  = function_exists('lmeg_ai_configured') && lmeg_ai_configured();
    $card   = 'background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:18px 20px;color:#F4F5F7;';
    $dash   = 'background:rgba(255,255,255,.02);border:1px dashed rgba(255,255,255,.14);border-radius:14px;padding:18px 20px;color:#F4F5F7;';
    $muted  = '#8B90A0';

    // Demo/preview mode — sample data so the dashboard can be seen before connect.
    $demo        = !empty($_GET['demo']);
    $demo_sent   = null;
    $demo_digest = null;

    if ($demo) {
        $ig_ok = $sp_ok = $ai_ok = $fb_ok = true;
        $dd = lmeg_social_demo();
        $ig = $dd['ig']; $ig_stats = $dd['ig_stats']; $ov = $dd['ov']; $sp_stats = $dd['sp_stats'];
        $fb = $dd['fb']; $fb_stats = $dd['fb_stats']; $fb_content = $dd['fb_content'];
        $fan_ct = $dd['fan_ct']; $stories = $dd['stories']; $content = $dd['content'];
        $best_day = $dd['best_day']; $types = $dd['types'];
        $hashtags = $dd['hashtags'];
        $demographics = $dd['demographics'];
        $demo_sent = $dd['sentiment']; $demo_digest = $dd['digest'];
    } else {
        $ig       = $ig_ok ? lmeg_ig_account_stats() : null;
        $ig_stats = lmeg_social_series_stats(lmeg_social_snapshots('instagram', 30));
        $fb        = $fb_ok ? lmeg_fb_page_stats() : null;
        $fb_stats  = lmeg_social_series_stats(lmeg_social_snapshots('facebook', 30));
        $fb_content = $fb_ok ? lmeg_fb_content_stats() : null;
        $ov       = $sp_ok ? lmeg_spotify_overview() : null;
        $sp_snaps = ($sp_ok && function_exists('lmeg_spotify_snapshots')) ? lmeg_spotify_snapshots(60) : [];
        $sp_stats = lmeg_social_series_stats($sp_snaps);
        $fan_ct   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE unsubscribed_at IS NULL");
        $stories  = $ig_ok ? lmeg_social_story_mentions(30) : 0;
        $content  = $ig_ok ? lmeg_social_ig_content_stats() : null;
        $best_day = $ig_ok ? lmeg_social_ig_best_time() : null;
        $types    = $ig_ok ? lmeg_social_ig_type_breakdown() : null;
        $hashtags = $ig_ok ? lmeg_social_ig_hashtags() : null;
        $demographics = $ig_ok ? lmeg_social_ig_demographics() : null;
    }

    $delta_html = function ($d, $per_day = null, $days = null) {
        return lmeg_chart_delta_chip($d, $per_day, $days);
    };
    ?>
    <div class="wrap">
        <h1>Fanloop — Social Listening</h1>
        <p style="max-width:820px;">A read on <?php echo esc_html($artist); ?>'s social presence, straight from your connected accounts — audience, growth, content, and how fans feel.</p>

        <?php if ($demo) : ?>
            <div class="notice notice-warning" style="max-width:900px;"><p><span style="display:inline-flex;align-items:center;gap:6px;vertical-align:middle;"><?php echo lmeg_icon('eye', ['size' => 15, 'sw' => 2]); ?><strong>Demo data</strong></span> — this is a preview with sample numbers so you can see the layout and feel. <a href="<?php echo esc_url(remove_query_arg('demo')); ?>">Switch to live data →</a></p></div>
        <?php else : ?>
            <p><a class="button" href="<?php echo esc_url(add_query_arg('demo', 1)); ?>"><span style="display:inline-flex;align-items:center;gap:6px;vertical-align:middle;"><?php echo lmeg_icon('eye', ['size' => 15, 'sw' => 2]); ?>Preview with demo data</span></a></p>
        <?php endif; ?>

        <?php if (!$ig_ok) : ?>
            <div class="notice notice-info" style="max-width:900px;"><p><strong>Connect Instagram</strong> (<a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings')); ?>">Settings → Instagram</a>) to light up followers, top posts, story mentions, and comment sentiment — your <strong>Facebook Page</strong> comes with the same connection. Spotify + your fan list show below now.</p></div>
        <?php endif; ?>

        <h2>Audience</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;max-width:1000px;margin-bottom:8px;">
            <div style="<?php echo $card; ?>">
                <?php echo lmeg_card_head('instagram', '#E1306C', 'Instagram'); ?>
                <div style="font-size:24px;font-weight:700;color:#F4F5F7;"><?php echo $ig ? number_format_i18n($ig['followers']) : '—'; ?></div>
                <div style="font-size:12px;color:#8B90A0;"><?php echo $ig ? 'followers · ' . number_format_i18n($ig['media_count']) . ' posts' : 'not connected'; ?></div>
                <?php echo $delta_html($ig_stats['delta'], $ig_stats['per_day'], $ig_stats['days']); ?>
            </div>
            <div style="<?php echo $card; ?>">
                <?php echo lmeg_card_head('facebook', '#1877F2', 'Facebook'); ?>
                <div style="font-size:24px;font-weight:700;color:#F4F5F7;"><?php echo ($fb_ok && $fb) ? number_format_i18n($fb['followers']) : '—'; ?></div>
                <div style="font-size:12px;color:#8B90A0;"><?php echo ($fb_ok && $fb) ? 'Page followers' : 'not connected'; ?></div>
                <?php echo $delta_html($fb_stats['delta'], $fb_stats['per_day'], $fb_stats['days']); ?>
            </div>
            <div style="<?php echo $card; ?>">
                <?php echo lmeg_card_head('spotify', '#1DB954', 'Spotify'); ?>
                <div style="font-size:24px;font-weight:700;color:#F4F5F7;"><?php echo (!$ov || is_wp_error($ov)) ? '—' : number_format_i18n($ov['followers']); ?></div>
                <div style="font-size:12px;color:#8B90A0;"><?php echo (!$ov || is_wp_error($ov)) ? 'not connected' : 'followers · popularity ' . (int) $ov['popularity'] . '/100'; ?></div>
                <?php echo $delta_html($sp_stats['delta'], $sp_stats['per_day'], $sp_stats['days']); ?>
            </div>
            <div style="<?php echo $card; ?>">
                <?php echo lmeg_card_head('users', '#7C6CF6', 'Your fan list'); ?>
                <div style="font-size:24px;font-weight:700;color:#F4F5F7;"><?php echo number_format_i18n($fan_ct); ?></div>
                <div style="font-size:12px;color:#8B90A0;">owned contacts you can reach anytime</div>
            </div>
            <div style="<?php echo $card; ?>">
                <?php echo lmeg_card_head('at-sign', '#D05FA2', 'Story mentions'); ?>
                <div style="font-size:24px;font-weight:700;color:#F4F5F7;"><?php echo $ig_ok ? number_format_i18n($stories) : '—'; ?></div>
                <div style="font-size:12px;color:#8B90A0;">tagged you in a story · last 30 days</div>
            </div>
        </div>

        <h2 style="margin-top:24px;">Growth trends</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;max-width:1000px;">
            <?php if ($ig_ok) : $ispark = lmeg_social_sparkline($ig_stats['vals']); ?>
            <div style="<?php echo $card; ?>">
                <?php echo lmeg_card_head('instagram', '#E1306C', 'Instagram followers'); ?>
                <div style="font-size:22px;font-weight:700;color:#F4F5F7;margin:2px 0;"><?php echo $ig ? number_format_i18n($ig['followers']) : '—'; ?></div>
                <?php if ($ig_stats['days'] >= 1) : ?><div style="font-size:12px;color:#8B90A0;"><?php echo ($ig_stats['delta'] >= 0 ? '+' : '') . number_format_i18n($ig_stats['delta']); ?> over <?php echo (int) $ig_stats['days']; ?> days · <?php echo $ig_stats['per_day']; ?>/day</div><?php endif; ?>
                <?php if ($ispark) : echo lmeg_chart_line($ig_stats['vals'], ['color' => '#E1306C', 'uid' => 'ig-follows', 'labels' => $ig_stats['labels'] ?? [], 'suffix' => 'followers']); else : ?><p class="description">Follower history starts on connect — the line fills in over the next few days.</p><?php endif; ?>
            </div>
            <?php else : ?>
            <div style="<?php echo $dash; ?>"><?php echo lmeg_card_head('instagram', '#E1306C', 'Instagram'); ?><p class="description"><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings')); ?>">Connect</a> to track follower growth.</p></div>
            <?php endif; ?>

            <?php if ($fb_ok) : $fbspark = lmeg_social_sparkline($fb_stats['vals']); ?>
            <div style="<?php echo $card; ?>">
                <?php echo lmeg_card_head('facebook', '#1877F2', 'Facebook followers'); ?>
                <div style="font-size:22px;font-weight:700;color:#F4F5F7;margin:2px 0;"><?php echo $fb ? number_format_i18n($fb['followers']) : '—'; ?></div>
                <?php if ($fb_stats['days'] >= 1) : ?><div style="font-size:12px;color:#8B90A0;"><?php echo ($fb_stats['delta'] >= 0 ? '+' : '') . number_format_i18n($fb_stats['delta']); ?> over <?php echo (int) $fb_stats['days']; ?> days · <?php echo $fb_stats['per_day']; ?>/day</div><?php endif; ?>
                <?php if ($fbspark) : echo lmeg_chart_line($fb_stats['vals'], ['color' => '#1877F2', 'uid' => 'fb-follows', 'labels' => $fb_stats['labels'] ?? [], 'suffix' => 'followers']); else : ?><p class="description">Follower history starts on connect — the line fills in over the next few days.</p><?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($sp_ok) : $spark = lmeg_social_sparkline($sp_stats['vals']); ?>
            <div style="<?php echo $card; ?>">
                <?php echo lmeg_card_head('spotify', '#1DB954', 'Spotify followers'); ?>
                <div style="font-size:22px;font-weight:700;color:#F4F5F7;margin:2px 0;"><?php echo (!$ov || is_wp_error($ov)) ? '—' : number_format_i18n($ov['followers']); ?></div>
                <?php if ($sp_stats['days'] >= 1) : ?><div style="font-size:12px;color:#8B90A0;"><?php echo ($sp_stats['delta'] >= 0 ? '+' : '') . number_format_i18n($sp_stats['delta']); ?> over <?php echo (int) $sp_stats['days']; ?> days · <?php echo $sp_stats['per_day']; ?>/day</div><?php endif; ?>
                <?php if ($spark) : echo lmeg_chart_line($sp_stats['vals'], ['color' => '#1DB954', 'uid' => 'sp-follows', 'labels' => $sp_stats['labels'] ?? [], 'suffix' => 'followers']); else : ?><p class="description">History builds day by day.</p><?php endif; ?>
            </div>
            <?php else : ?>
            <div style="<?php echo $dash; ?>"><?php echo lmeg_card_head('spotify', '#1DB954', 'Spotify'); ?><p class="description"><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-spotify')); ?>">Connect</a> to track follower growth.</p></div>
            <?php endif; ?>
        </div>

        <?php if ($ig_ok) : ?>
        <h2 style="margin-top:24px;">Who your followers are</h2>
        <?php if (empty($demographics)) : ?>
            <div style="<?php echo $dash; ?>max-width:900px;">
                <p style="margin:0 0 4px;font-weight:600;color:#F4F5F7;">Follower demographics — gender, age, and where they are</p>
                <p class="description" style="margin:0;">Instagram shares this for Business/Creator accounts with 100+ followers, but it needs the <code>instagram_manage_insights</code> permission enabled on your Meta app (added via Meta App Review / Business Verification — <em>not</em> just a reconnect). Once it's approved on the app, this fills in automatically. Use <a href="<?php echo esc_url(add_query_arg('demo', 1)); ?>">demo data</a> to preview the layout.</p>
            </div>
        <?php else :
            // Inline distribution renderer: assoc [key=>count], optional label formatter.
            $dist = function ($data, $limit, $fmt = null) use ($card) {
                $data = (array) $data; if (!$data) { echo '<p class="description" style="margin:0;">No data yet.</p>'; return; }
                $total = array_sum($data); $total = $total > 0 ? $total : 1;
                $i = 0;
                foreach ($data as $k => $v) {
                    if ($i++ >= $limit) break;
                    $pct = round(100 * $v / $total, 1);
                    $label = $fmt ? $fmt($k) : $k;
                    echo '<div style="display:flex;align-items:center;gap:9px;margin:7px 0;">'
                       . '<span style="flex:0 0 120px;font-size:13px;color:#F4F5F7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' . $label . '</span>'
                       . '<span style="flex:1;height:8px;background:rgba(255,255,255,.07);border-radius:5px;overflow:hidden;"><span style="display:block;height:100%;width:' . max(3, round($pct)) . '%;background:linear-gradient(90deg,#7C6CF6,#D05FA2);border-radius:5px;"></span></span>'
                       . '<span style="flex:0 0 48px;text-align:right;font-size:12px;color:#8B90A0;font-variant-numeric:tabular-nums;">' . $pct . '%</span>'
                       . '</div>';
                }
            };
            $gender_label = ['F' => 'Female', 'M' => 'Male', 'U' => 'Unknown'];
            $flag = function ($cc) { $n = (function_exists('lmeg_country_by_iso') && ($r = lmeg_country_by_iso($cc))) ? $r[1] : $cc; return esc_html(trim((function_exists('lmeg_flag_emoji') ? lmeg_flag_emoji($cc) . ' ' : '') . $n)); };
            ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;max-width:900px;">
                <div style="<?php echo $card; ?>">
                    <div style="font-weight:600;font-size:13px;margin-bottom:10px;">Gender</div>
                    <?php $dist($demographics['gender'] ?? [], 4, function ($k) use ($gender_label) { return esc_html($gender_label[strtoupper($k)] ?? ucfirst(strtolower($k))); }); ?>
                </div>
                <div style="<?php echo $card; ?>">
                    <div style="font-weight:600;font-size:13px;margin-bottom:10px;">Age</div>
                    <?php $dist($demographics['age'] ?? [], 7, function ($k) { return esc_html($k); }); ?>
                </div>
                <div style="<?php echo $card; ?>">
                    <div style="font-weight:600;font-size:13px;margin-bottom:10px;">Top countries</div>
                    <?php $dist($demographics['country'] ?? [], 6, $flag); ?>
                </div>
                <div style="<?php echo $card; ?>">
                    <div style="font-weight:600;font-size:13px;margin-bottom:10px;">Top cities</div>
                    <?php $dist($demographics['city'] ?? [], 6, function ($k) { return esc_html($k); }); ?>
                </div>
            </div>
        <?php endif; ?>
        <?php endif; ?>

        <h2 style="margin-top:24px;">Content performance</h2>
        <?php if (!$ig_ok) : ?>
            <p class="description" style="max-width:820px;">Connect Instagram to see your top posts, engagement rate, and how often you post.</p>
        <?php elseif (!$content) : ?>
            <p class="description" style="max-width:820px;">No recent posts found yet.</p>
        <?php else : ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;max-width:760px;margin-bottom:10px;">
                <div style="<?php echo $card; ?>"><div style="font-weight:600;font-size:13px;">Engagement rate</div><div style="font-size:22px;font-weight:700;color:#F4F5F7;"><?php echo $content['eng_rate']; ?>%</div><div style="font-size:12px;color:#8B90A0;">likes+comments ÷ followers</div></div>
                <div style="<?php echo $card; ?>"><div style="font-weight:600;font-size:13px;">Avg per post</div><div style="font-size:22px;font-weight:700;color:#F4F5F7;"><?php echo number_format_i18n($content['avg_eng']); ?></div><div style="font-size:12px;color:#8B90A0;">engagements</div></div>
                <div style="<?php echo $card; ?>"><div style="font-weight:600;font-size:13px;">Posting cadence</div><div style="font-size:22px;font-weight:700;color:#F4F5F7;"><?php echo $content['cadence']; ?></div><div style="font-size:12px;color:#8B90A0;">days between posts</div></div>
                <?php if ($best_day) : ?>
                <div style="<?php echo $card; ?>"><div style="font-weight:600;font-size:13px;">Best day to post</div><div style="font-size:22px;font-weight:700;color:#F4F5F7;"><?php echo esc_html($best_day['day']); ?></div><div style="font-size:12px;color:#8B90A0;">your posts land best (last <?php echo (int) $best_day['samples']; ?>)</div></div>
                <?php endif; ?>
            </div>
            <h3 style="margin:14px 0 8px;">Top posts</h3>
            <?php lmeg_social_render_post_cards($content['top'], 'instagram'); ?>
            <?php if ($types && count($types) > 1) : ?>
            <h3 style="margin:16px 0 6px;">What format works best</h3>
            <table class="widefat striped" style="max-width:520px;">
                <thead><tr><th>Format</th><th>Posts</th><th>Avg engagement</th></tr></thead>
                <tbody>
                <?php foreach ($types as $ti => $tp) : ?>
                    <tr>
                        <td><?php if ($ti === 0) echo '<span style="display:inline-flex;vertical-align:middle;margin-right:7px;color:#FBBF24;">' . lmeg_icon('trophy', ['size' => 15, 'sw' => 2]) . '</span>'; ?><strong><?php echo esc_html($tp['label']); ?></strong></td>
                        <td><?php echo (int) $tp['count']; ?></td>
                        <td><strong><?php echo number_format_i18n($tp['avg']); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (!empty($hashtags) && !empty($hashtags['tags'])) :
                $ht_max = 0; foreach ($hashtags['tags'] as $h) $ht_max = max($ht_max, (int) $h['avg']); ?>
            <h3 style="margin:16px 0 4px;">Hashtag performance</h3>
            <p class="description" style="max-width:820px;margin:0 0 8px;">Which hashtags in your captions ride with your strongest posts — vs your average post (<?php echo number_format_i18n($hashtags['avg_all']); ?> engagements).</p>
            <div style="max-width:640px;">
                <?php foreach ($hashtags['tags'] as $h) :
                    $w    = $ht_max ? max(4, round(100 * $h['avg'] / $ht_max)) : 0;
                    $lift = (int) $h['lift'];
                    $lc   = $lift > 0 ? '#34D399' : ($lift < 0 ? '#8B90A0' : '#8B90A0');
                    $ls   = ($lift > 0 ? '+' : '') . $lift . '%'; ?>
                    <div style="display:flex;align-items:center;gap:10px;margin:7px 0;">
                        <span style="flex:0 0 150px;font-size:13px;color:#E58BBD;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">#<?php echo esc_html($h['tag']); ?></span>
                        <span style="flex:1;height:9px;background:rgba(255,255,255,.07);border-radius:5px;overflow:hidden;"><span style="display:block;height:100%;width:<?php echo $w; ?>%;background:linear-gradient(90deg,#7C6CF6,#D05FA2);border-radius:5px;"></span></span>
                        <span style="flex:0 0 84px;text-align:right;font-size:12.5px;color:#F4F5F7;font-variant-numeric:tabular-nums;"><?php echo number_format_i18n($h['avg']); ?></span>
                        <span style="flex:0 0 42px;text-align:right;font-size:11px;color:#8B90A0;"><?php echo (int) $h['posts']; ?>×</span>
                        <span style="flex:0 0 52px;text-align:right;font-size:12px;font-weight:600;color:<?php echo $lc; ?>;"><?php echo esc_html($ls); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <h2 style="margin-top:24px;">Facebook</h2>
        <?php if (!$fb_ok) : ?>
            <p class="description" style="max-width:820px;">Your Facebook Page connects together with Instagram — <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings')); ?>">connect in Settings → Instagram</a> and Page posts, engagement, and comment sentiment light up here automatically. No separate login.</p>
        <?php elseif (!$fb_content) : ?>
            <p class="description" style="max-width:820px;">Connected<?php echo ($fb && $fb['name']) ? ' — <strong style="color:#F4F5F7;">' . esc_html($fb['name']) . '</strong>' : ''; ?>. No recent Page posts found yet; once the Page publishes, top posts and engagement show here.</p>
        <?php else : ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;max-width:760px;margin-bottom:10px;">
                <div style="<?php echo $card; ?>"><div style="font-weight:600;font-size:13px;">Engagement rate</div><div style="font-size:22px;font-weight:700;color:#F4F5F7;"><?php echo $fb_content['eng_rate']; ?>%</div><div style="font-size:12px;color:#8B90A0;">reactions+comments+shares ÷ followers</div></div>
                <div style="<?php echo $card; ?>"><div style="font-weight:600;font-size:13px;">Avg per post</div><div style="font-size:22px;font-weight:700;color:#F4F5F7;"><?php echo number_format_i18n($fb_content['avg_eng']); ?></div><div style="font-size:12px;color:#8B90A0;">engagements</div></div>
                <div style="<?php echo $card; ?>"><div style="font-weight:600;font-size:13px;">Posting cadence</div><div style="font-size:22px;font-weight:700;color:#F4F5F7;"><?php echo $fb_content['cadence']; ?></div><div style="font-size:12px;color:#8B90A0;">days between posts</div></div>
            </div>
            <h3 style="margin:14px 0 8px;">Top Facebook posts</h3>
            <?php lmeg_social_render_post_cards($fb_content['top'], 'facebook', [
                ['icon' => 'heart', 'key' => 'likes'],
                ['icon' => 'message', 'key' => 'comments'],
                ['icon' => 'send', 'key' => 'shares'],
            ]); ?>
        <?php endif; ?>

        <?php if ($sp_ok && $ov && !is_wp_error($ov)) : ?>
        <h2 style="margin-top:24px;">Spotify</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;max-width:760px;margin-bottom:6px;">
            <div style="<?php echo $card; ?>">
                <?php echo lmeg_card_head('spotify', '#1DB954', 'Followers'); ?>
                <div style="font-size:22px;font-weight:700;color:#F4F5F7;"><?php echo number_format_i18n($ov['followers']); ?></div>
                <?php echo $delta_html($sp_stats['delta'], $sp_stats['per_day'], $sp_stats['days']); ?>
            </div>
            <div style="<?php echo $card; ?>">
                <?php echo lmeg_card_head('sparkle', '#7C6CF6', 'Popularity'); ?>
                <div style="font-size:22px;font-weight:700;color:#F4F5F7;"><?php echo (int) $ov['popularity']; ?><span style="font-size:13px;color:#8B90A0;"> /100</span></div>
                <div style="font-size:12px;color:#8B90A0;">Spotify's recent-stream score</div>
            </div>
        </div>
        <?php if (!empty($ov['genres'])) : ?><p style="max-width:760px;color:#8B90A0;margin:2px 0 10px;"><strong style="color:#F4F5F7;">Genres:</strong> <?php echo esc_html(implode(' · ', $ov['genres'])); ?></p><?php endif; ?>
        <?php if (!empty($ov['top_tracks'])) : ?>
        <h3 style="margin:14px 0 6px;">Top tracks</h3>
        <table class="widefat striped" style="max-width:720px;">
            <thead><tr><th>#</th><th>Track</th><th>Album</th><th>Popularity</th></tr></thead>
            <tbody>
            <?php foreach ($ov['top_tracks'] as $i => $t) : ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><?php if (!empty($t['url']) && $t['url'] !== '#') : ?><a href="<?php echo esc_url($t['url']); ?>" target="_blank" rel="noopener"><strong><?php echo esc_html($t['name']); ?></strong></a><?php else : ?><strong><?php echo esc_html($t['name']); ?></strong><?php endif; ?></td>
                    <td style="color:#8B90A0;"><?php echo esc_html($t['album']); ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;max-width:140px;height:8px;background:rgba(255,255,255,.08);border-radius:4px;overflow:hidden;"><div style="width:<?php echo (int) $t['popularity']; ?>%;height:100%;background:#1DB954;"></div></div>
                            <span style="font-variant-numeric:tabular-nums;"><?php echo (int) $t['popularity']; ?></span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php if (!empty($ov['releases'])) : ?>
        <h3 style="margin:16px 0 6px;">Recent releases</h3>
        <table class="widefat striped" style="max-width:720px;">
            <thead><tr><th></th><th>Release</th><th>Type</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($ov['releases'] as $r) : ?>
                <tr>
                    <td><?php if (!empty($r['img'])) : ?><img src="<?php echo esc_url($r['img']); ?>" width="40" height="40" style="border-radius:6px;display:block;" alt="" /><?php endif; ?></td>
                    <td><?php if (!empty($r['url']) && $r['url'] !== '#') : ?><a href="<?php echo esc_url($r['url']); ?>" target="_blank" rel="noopener"><strong><?php echo esc_html($r['name']); ?></strong></a><?php else : ?><strong><?php echo esc_html($r['name']); ?></strong><?php endif; ?></td>
                    <td><?php echo esc_html(ucfirst($r['type'])); ?></td>
                    <td style="color:#8B90A0;"><?php echo esc_html($r['date']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <p class="description" style="max-width:820px;margin-top:8px;">Impact analysis (how each broadcast &amp; release moved followers), daily history, and CSV backfill live on the <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-spotify')); ?>">full Spotify page</a>.</p>
        <?php endif; ?>

        <h2 style="margin-top:24px;">Fan sentiment</h2>
        <?php if ($demo && $demo_sent) : $x = $demo_sent; ?>
            <div style="<?php echo $card; ?>margin-top:6px;max-width:820px;">
                <?php foreach (['Positive' => ['positive', '#34D399'], 'Neutral' => ['neutral', '#8B90A0'], 'Negative' => ['negative', '#F87171']] as $lbl => $kc) : $pct = (int) $x[$kc[0]]; ?>
                    <div style="display:flex;align-items:center;gap:10px;margin:7px 0;"><span style="width:64px;font-size:13px;color:#8B90A0;"><?php echo $lbl; ?></span><span style="flex:1;max-width:340px;height:8px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden;display:inline-block;"><span style="display:block;width:<?php echo max(1, $pct); ?>%;height:100%;background:<?php echo $kc[1]; ?>;border-radius:999px;"></span></span><span style="width:40px;text-align:right;font-variant-numeric:tabular-nums;font-weight:600;"><?php echo $pct; ?>%</span></div>
                <?php endforeach; ?>
                <p style="margin:16px 0 6px;font-weight:600;">What they’re talking about</p><div><?php foreach ($x['themes'] as $t) : ?><span style="display:inline-block;background:rgba(124,108,246,.15);color:#C4BBFF;border:1px solid rgba(124,108,246,.30);border-radius:999px;padding:3px 11px;margin:3px 3px 0 0;font-size:12px;"><?php echo esc_html($t); ?></span><?php endforeach; ?></div>
                <p style="margin:16px 0 6px;font-weight:600;">Highlights</p><ul style="margin:0 0 0 18px;line-height:1.6;"><?php foreach ($x['highlights'] as $h) : ?><li>“<?php echo esc_html($h); ?>”</li><?php endforeach; ?></ul>
                <p style="margin:16px 0 6px;font-weight:600;">Questions to answer</p><ul style="margin:0 0 0 18px;line-height:1.6;"><?php foreach ($x['questions'] as $q) : ?><li><?php echo esc_html($q); ?></li><?php endforeach; ?></ul>
                <p style="margin:16px 0 6px;font-weight:600;color:#FBBF24;">Worth watching</p><ul style="margin:0 0 0 18px;line-height:1.6;"><?php foreach ($x['watch'] as $w) : ?><li><?php echo esc_html($w); ?></li><?php endforeach; ?></ul>
                <p class="description" style="margin-top:12px;">Based on <?php echo (int) $x['_count']; ?> recent comments.</p>
            </div>
        <?php elseif ((!$ig_ok && !$fb_ok) || !$ai_ok) : ?>
            <p class="description" style="max-width:820px;">Needs <?php echo (!$ig_ok && !$fb_ok) ? 'Instagram or Facebook connected' : ''; echo ((!$ig_ok && !$fb_ok) && !$ai_ok) ? ' and ' : ''; echo !$ai_ok ? 'your Anthropic API key (Settings → AI assistant)' : ''; ?>. Then Fanloop reads your recent comments and summarizes the mood, themes, standout comments, and questions worth answering.</p>
        <?php else : ?>
            <p style="max-width:820px;">Reads the comments on your recent Instagram and Facebook posts and summarizes the mood — powered by your AI key. Cached ~6h.</p>
            <p><button type="button" class="button button-primary" id="lmeg-sent-btn">Analyze recent comments</button> <span id="lmeg-sent-status" style="font-size:12px;margin-left:8px;"></span></p>
            <div id="lmeg-sent-out" style="max-width:820px;"></div>
        <?php endif; ?>

        <?php if ($ai_ok && ($ig_ok || $fb_ok || $sp_ok)) : ?>
        <h2 style="margin-top:24px;">Listening digest</h2>
        <p style="max-width:820px;">One AI brief across everything above — how you're doing and what to do next.</p>
        <?php if ($demo && $demo_digest) : ?>
        <div style="<?php echo $card; ?>border-left:3px solid #D05FA2;margin-top:6px;line-height:1.7;max-width:820px;"><?php echo wp_kses_post(nl2br(preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', esc_html($demo_digest)))); ?></div>
        <?php else : ?>
        <p><button type="button" class="button button-primary" id="lmeg-digest-btn">Generate digest</button> <span id="lmeg-digest-status" style="font-size:12px;margin-left:8px;"></span></p>
        <div id="lmeg-digest-out" style="max-width:820px;"></div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($ai_ok && ($ig_ok || $fb_ok || $sp_ok)) : ?>
        <script>
        (function(){
            var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, nonce = <?php echo wp_json_encode(wp_create_nonce('lmeg_social')); ?>;
            function esc(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
            function md(s){ return esc(s).replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>').replace(/\n/g,'<br>'); }

            var sBtn = document.getElementById('lmeg-sent-btn');
            if (sBtn) {
                var sOut = document.getElementById('lmeg-sent-out'), sSt = document.getElementById('lmeg-sent-status');
                function bar(label, pct, color){ pct = parseInt(pct||0,10); return '<div style="display:flex;align-items:center;gap:10px;margin:7px 0;"><span style="width:64px;font-size:13px;color:#8B90A0;">'+label+'</span><span style="flex:1;max-width:340px;height:8px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden;display:inline-block;"><span style="display:block;width:'+Math.max(1,pct)+'%;height:100%;background:'+color+';border-radius:999px;"></span></span><span style="width:40px;text-align:right;font-variant-numeric:tabular-nums;font-weight:600;">'+pct+'%</span></div>'; }
                sBtn.addEventListener('click', function(){
                    sBtn.disabled = true; sSt.textContent = 'Reading comments…';
                    var fd = new FormData(); fd.append('action','lmeg_social_sentiment'); fd.append('nonce',nonce);
                    fetch(ajax,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
                        if (d && d.success) { var x = d.data;
                            var html = '<div style="background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:18px 20px;margin-top:10px;color:#F4F5F7;">';
                            html += bar('Positive', x.positive, '#34D399') + bar('Neutral', x.neutral, '#8B90A0') + bar('Negative', x.negative, '#F87171');
                            if (x.themes && x.themes.length) html += '<p style="margin:16px 0 6px;font-weight:600;">What they’re talking about</p><div>'+x.themes.map(function(t){return '<span style="display:inline-block;background:rgba(124,108,246,.15);color:#C4BBFF;border:1px solid rgba(124,108,246,.30);border-radius:999px;padding:3px 11px;margin:3px 3px 0 0;font-size:12px;">'+esc(t)+'</span>';}).join('')+'</div>';
                            if (x.highlights && x.highlights.length) html += '<p style="margin:16px 0 6px;font-weight:600;">Highlights</p><ul style="margin:0 0 0 18px;line-height:1.6;">'+x.highlights.map(function(h){return '<li>“'+esc(h)+'”</li>';}).join('')+'</ul>';
                            if (x.questions && x.questions.length) html += '<p style="margin:16px 0 6px;font-weight:600;">Questions to answer</p><ul style="margin:0 0 0 18px;line-height:1.6;">'+x.questions.map(function(q){return '<li>'+esc(q)+'</li>';}).join('')+'</ul>';
                            if (x.watch && x.watch.length) html += '<p style="margin:16px 0 6px;font-weight:600;color:#FBBF24;">Worth watching</p><ul style="margin:0 0 0 18px;line-height:1.6;">'+x.watch.map(function(w){return '<li>'+esc(w)+'</li>';}).join('')+'</ul>';
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
                        if (d && d.success) { dOut.innerHTML = '<div style="background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-left:3px solid #D05FA2;border-radius:14px;padding:18px 20px;margin-top:10px;color:#F4F5F7;line-height:1.7;">'+md(d.data.digest)+'</div>'; dSt.textContent=''; }
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
