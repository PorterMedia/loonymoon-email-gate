<?php
/**
 * Fan journey analytics — the "source → action → purchase" funnel, native to
 * Fanloop. An artist's site is a router: this measures the handoff moments
 * (stream on a DSP, grab a ticket, buy merch) and — unlike an anonymous pixel —
 * ties them to the KNOWN fan whenever we can (logged-in member cookie), so a
 * fan's profile shows what they actually did, not just an aggregate.
 *
 * Concepts (funnel + the music-aware link classifier) are carried over from
 * 794 Analytics so both products report the same buckets.
 *
 * Stage 1: capture pipeline (classifier + events table + beacon endpoint + JS).
 * The admin report and the per-fan journey come next; this ships dormant until
 * "journey_enabled" is turned on.
 */

if (!defined('ABSPATH')) exit;

/* ---------------------------------------------------------------------------
 * Music-aware outbound-link classification (ported from 794 Analytics v4.4.0).
 * By URL (+ light text hints). First match wins.
 * ------------------------------------------------------------------------- */

/** host-substring => DSP category. */
function lmeg_journey_dsp_map() {
    return [
        'open.spotify.com' => 'Spotify', 'spotify.com' => 'Spotify',
        'music.apple.com' => 'Apple Music', 'itunes.apple.com' => 'Apple Music', 'apple.co' => 'Apple Music',
        'music.youtube.com' => 'YouTube', 'youtube.com' => 'YouTube', 'youtu.be' => 'YouTube',
        'music.amazon' => 'Amazon Music', 'amazon.' => 'Amazon Music',
        'tidal.com' => 'TIDAL', 'deezer.com' => 'Deezer', 'pandora.com' => 'Pandora',
        'soundcloud.com' => 'SoundCloud', 'bandcamp.com' => 'Bandcamp',
        'audiomack.com' => 'Audiomack', 'iheart.com' => 'iHeartRadio',
    ];
}

/** Smart-link / pre-save aggregators → "DSP Button". */
function lmeg_journey_smartlink_hosts() {
    return ['feature.fm', 'ffm.to', 'distrokid.com', 'found.ee', 'lnk.to', 'ditto.fm',
            'orcd.co', 'push.fm', 'fanlink.to', 'li.sten.to', 'smarturl.it',
            'hyperfollow.com', 'show.co', 'linktr.ee'];
}

/** Ticketing / live → "Tickets". */
function lmeg_journey_ticket_hosts() {
    return ['bandsintown.com', 'songkick.com', 'ticketmaster.', 'seetickets.',
            'dice.fm', 'eventbrite.', 'axs.com', 'livenation.', 'ticketweb.'];
}

/** The DSP category names, for "streaming destination" rollups. */
function lmeg_journey_dsp_categories() {
    return array_values(array_unique(array_values(lmeg_journey_dsp_map())));
}

/**
 * Classify an outbound link the same way 794 does.
 * @param string $href     the clicked href
 * @param string $siteHost the artist's own host (internal vs external)
 * @param ?string $text    link text, if any
 */
function lmeg_link_category($href, $siteHost = '', $text = null) {
    $href = trim((string) $href);
    if ($href === '' || strpos($href, '#') === 0
        || strpos($href, 'mailto:') === 0 || strpos($href, 'tel:') === 0) {
        return 'Internal Link';
    }
    $host = strtolower((string) parse_url($href, PHP_URL_HOST));
    if ($host === '' || ($siteHost !== '' && strpos($host, $siteHost) !== false)) {
        return 'Internal Link';
    }
    $hay = $host . strtolower((string) parse_url($href, PHP_URL_PATH));

    foreach (lmeg_journey_dsp_map() as $needle => $category) {
        if (strpos($host, $needle) !== false) return $category;
    }
    foreach (lmeg_journey_smartlink_hosts() as $needle) {
        if (strpos($hay, $needle) !== false) return 'DSP Button';
    }
    foreach (lmeg_journey_ticket_hosts() as $needle) {
        if (strpos($hay, $needle) !== false) return 'Tickets';
    }
    $t = strtolower((string) $text);
    if ($t !== '') {
        if (strpos($t, 'listen') !== false || strpos($t, 'stream') !== false || strpos($t, 'pre-save') !== false) return 'DSP Button';
        if (strpos($t, 'ticket') !== false || strpos($t, 'rsvp') !== false) return 'Tickets';
        if (strpos($t, 'watch') !== false || strpos($t, 'trailer') !== false || strpos($t, 'video') !== false) return 'Trailer / Video';
    }
    return 'External Link';
}

/* ---------------------------------------------------------------------------
 * State
 * ------------------------------------------------------------------------- */

/** Is journey tracking switched on for this site? */
function lmeg_journey_enabled() {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    return !empty($s['journey_enabled']);
}

/** The artist site's own host (for internal/external classification). */
function lmeg_journey_site_host() {
    return strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
}

/** A durable first-party visitor id (cookie), so anonymous journeys stitch and
 *  later link to a fan once they identify. Returns the id (sets the cookie if new). */
function lmeg_journey_anon_id() {
    $c = isset($_COOKIE['lmeg_vid']) ? preg_replace('/[^a-f0-9]/', '', (string) $_COOKIE['lmeg_vid']) : '';
    if (strlen($c) === 32) return $c;
    $c = md5(wp_generate_password(24, false, false) . microtime(true));
    if (!headers_sent()) {
        setcookie('lmeg_vid', $c, time() + 365 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
    }
    $_COOKIE['lmeg_vid'] = $c;
    return $c;
}

/** The known fan (subscriber id) for this request, or 0 if anonymous. */
function lmeg_journey_subscriber_id() {
    if (function_exists('lmeg_current_member')) {
        $m = lmeg_current_member();
        if ($m && !empty($m->id)) return (int) $m->id;
    }
    return 0;
}

/* ---------------------------------------------------------------------------
 * Capture — the public beacon endpoint (?lmeg_journey=collect)
 * ------------------------------------------------------------------------- */

add_action('init', 'lmeg_journey_router');
function lmeg_journey_router() {
    if (!isset($_GET['lmeg_journey'])) return;
    if (sanitize_key($_GET['lmeg_journey']) !== 'collect') return;
    if (!lmeg_journey_enabled()) { status_header(204); exit; }

    // Beacon body: JSON (sendBeacon Blob) or form-encoded, best-effort.
    $raw = file_get_contents('php://input');
    $b   = $raw ? json_decode($raw, true) : null;
    if (!is_array($b)) $b = $_POST;

    $type = sanitize_key($b['t'] ?? '');
    if (!in_array($type, ['pageview', 'outbound'], true)) { status_header(204); exit; }

    // Cheap bot filter — skip obvious crawlers.
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|monitor|curl|wget|headless/i', $ua)) { status_header(204); exit; }

    $siteHost = lmeg_journey_site_host();
    $href     = isset($b['url'])  ? esc_url_raw((string) $b['url'])  : '';
    $text     = isset($b['text']) ? sanitize_text_field((string) $b['text']) : '';
    $category = $type === 'outbound' ? lmeg_link_category($href, $siteHost, $text) : null;

    lmeg_journey_record([
        'event_type'   => $type,
        'category'     => $category,
        'url'          => $type === 'outbound' ? $href : '',
        'link_text'    => mb_substr($text, 0, 190),
        'page_url'     => isset($b['page']) ? esc_url_raw((string) $b['page']) : '',
        'referrer'     => isset($b['ref']) ? esc_url_raw((string) $b['ref']) : '',
        'utm_source'   => isset($b['us']) ? sanitize_text_field(mb_substr((string) $b['us'], 0, 120)) : '',
        'utm_medium'   => isset($b['um']) ? sanitize_text_field(mb_substr((string) $b['um'], 0, 120)) : '',
        'utm_campaign' => isset($b['uc']) ? sanitize_text_field(mb_substr((string) $b['uc'], 0, 190)) : '',
        'user_agent'   => $ua,
    ]);

    status_header(204);
    exit;
}

/** Insert one journey event, tying it to the known fan + anon id + IP. */
function lmeg_journey_record($data) {
    global $wpdb;
    // Outbound clicks to the artist's OWN site aren't handoffs — don't store.
    if (($data['event_type'] ?? '') === 'outbound' && ($data['category'] ?? '') === 'Internal Link') return;

    $row = wp_parse_args($data, [
        'event_type' => 'pageview', 'category' => null, 'url' => '', 'link_text' => '',
        'page_url' => '', 'referrer' => '', 'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '',
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
    $row['subscriber_id'] = lmeg_journey_subscriber_id() ?: null;
    $row['anon_id']       = lmeg_journey_anon_id();
    $row['ip']            = function_exists('lmeg_client_ip') ? lmeg_client_ip() : substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $row['country']       = '';
    $row['created_at']    = current_time('mysql');
    // Trim long URLs to the column width.
    foreach (['url', 'page_url', 'referrer'] as $u) { if (strlen($row[$u]) > 500) $row[$u] = substr($row[$u], 0, 500); }

    $wpdb->insert($wpdb->prefix . 'lmeg_journey_events', $row);
}

/* ---------------------------------------------------------------------------
 * Front-end beacon script (enqueued only when tracking is on)
 * ------------------------------------------------------------------------- */

add_action('wp_enqueue_scripts', 'lmeg_journey_enqueue');
function lmeg_journey_enqueue() {
    if (!lmeg_journey_enabled() || is_admin()) return;
    wp_enqueue_script('lmeg-journey', LMEG_PLUGIN_URL . 'assets/journey.js', [], LMEG_VERSION, true);
    wp_localize_script('lmeg-journey', 'LMEG_J', [
        'url'  => add_query_arg('lmeg_journey', 'collect', home_url('/')),
        'host' => lmeg_journey_site_host(),
    ]);
}
