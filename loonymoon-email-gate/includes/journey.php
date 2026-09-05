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

    // Beacon body: JSON (sendBeacon Blob) or form-encoded, best-effort.
    $raw = file_get_contents('php://input');
    $b   = $raw ? json_decode($raw, true) : null;
    if (!is_array($b)) $b = $_POST;
    $type = sanitize_key($b['t'] ?? '');

    // Cheap bot filter — skip obvious crawlers.
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|monitor|curl|wget|headless/i', $ua)) { status_header(204); exit; }

    // Time-on-page is member-only, identity-linked — recorded even when
    // anonymous journey tracking is off (it complements the always-on member
    // page-view logger rather than the anonymous capture below).
    if ($type === 'dwell') {
        lmeg_journey_record_dwell(
            isset($b['page']) ? esc_url_raw((string) $b['page']) : '',
            (int) ($b['ms'] ?? 0)
        );
        status_header(204); exit;
    }

    // Anonymous / full journey capture requires the toggle.
    if (!lmeg_journey_enabled()) { status_header(204); exit; }
    if (!in_array($type, ['pageview', 'outbound'], true)) { status_header(204); exit; }

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

/**
 * Record time-on-page for an identified fan by attaching the active-dwell (ms)
 * to their most recent on-site page-view for that URL. The member page-view
 * logger (lmeg_track_member_pageview) already created that row on load; this
 * just fills in how long they stayed. Anonymous visitors are skipped. Beacons
 * re-send a growing total, so we keep the largest value seen (GREATEST).
 */
function lmeg_journey_record_dwell($page, $ms) {
    global $wpdb;
    $ms  = (int) $ms;
    if ($ms <= 0) return;
    $ms  = min($ms, 30 * 60 * 1000);                 // clamp to the 30-min client cap
    $sid = (int) (lmeg_journey_subscriber_id() ?: 0);
    if (!$sid) return;                               // members only
    $url = strtok((string) $page, '?');              // same query-stripped URL the logger stores
    if ($url === '' ) return;
    if (strlen($url) > 500) $url = substr($url, 0, 500);
    $tbl = $wpdb->prefix . 'lmeg_broadcast_events';
    $wpdb->query($wpdb->prepare(
        "UPDATE $tbl SET dwell_ms = GREATEST(COALESCE(dwell_ms, 0), %d)
         WHERE subscriber_id = %d AND event_type = 'pageview' AND source = 'site'
           AND url = %s AND created_at >= (NOW() - INTERVAL 2 HOUR)
         ORDER BY id DESC LIMIT 1",
        $ms, $sid, $url
    ));
}

/* ---------------------------------------------------------------------------
 * Front-end beacon script (enqueued only when tracking is on)
 * ------------------------------------------------------------------------- */

add_action('wp_enqueue_scripts', 'lmeg_journey_enqueue');
function lmeg_journey_enqueue() {
    if (is_admin()) return;
    // Load for full anonymous journey tracking, or for a signed-in fan so their
    // time-on-page is measured even when anonymous tracking is off.
    $member = function_exists('lmeg_current_member') ? lmeg_current_member() : null;
    if (!lmeg_journey_enabled() && !$member) return;
    wp_enqueue_script('lmeg-journey', LMEG_PLUGIN_URL . 'assets/journey.js', [], LMEG_VERSION, true);
    wp_localize_script('lmeg-journey', 'LMEG_J', [
        'url'  => add_query_arg('lmeg_journey', 'collect', home_url('/')),
        'host' => lmeg_journey_site_host(),
    ]);
}

/* ---------------------------------------------------------------------------
 * Stage 2 — the Journey report (admin) + enable toggle
 * ------------------------------------------------------------------------- */

// NOTE: the Journey submenu is registered in admin.php's core lmeg_admin_menu()
// alongside every other Fanloop page. A standalone add_action('admin_menu', …)
// here failed to land the page in the live menu (WP then 403'd it as
// unregistered), so registration was consolidated into the core menu builder.
// The page callback lmeg_admin_journey() lives below and is unchanged.

/** Flip journey tracking on/off from the report page (own nonce + cap). */
add_action('admin_post_lmeg_journey_toggle', 'lmeg_journey_handle_toggle');
function lmeg_journey_handle_toggle() {
    if (!current_user_can('manage_options')) wp_die('Not allowed');
    check_admin_referer('lmeg_journey_toggle');
    $opts = lmeg_get_settings();
    $opts['journey_enabled'] = empty($opts['journey_enabled']) ? 1 : 0;
    update_option(LMEG_OPTION, $opts);
    wp_safe_redirect(admin_url('admin.php?page=lmeg-journey&toggled=' . $opts['journey_enabled']));
    exit;
}

/**
 * All the numbers the report needs, in one cached bundle. Read-only stats only.
 * The fan funnel is the differentiator — it joins journey activity to the
 * subscriber-attributed shop_orders, so "source → action → purchase" is measured
 * on IDENTIFIED fans, not anonymous sessions.
 */
function lmeg_journey_report_data($days) {
    $build = function () use ($days) {
        global $wpdb;
        $je    = $wpdb->prefix . 'lmeg_journey_events';
        $so    = $wpdb->prefix . 'lmeg_shop_orders';
        $since = gmdate('Y-m-d H:i:s', current_time('timestamp') - $days * DAY_IN_SECONDS);

        // Headline volume.
        $pageviews = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $je WHERE event_type='pageview' AND created_at >= %s", $since));
        $clicks = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $je WHERE event_type='outbound' AND created_at >= %s", $since));
        $visitors = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT anon_id) FROM $je WHERE anon_id <> '' AND created_at >= %s", $since));
        $fans_seen = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT subscriber_id) FROM $je WHERE subscriber_id IS NOT NULL AND created_at >= %s", $since));

        // Outbound handoffs by category (Spotify / Tickets / DSP Button / …).
        $by_cat = $wpdb->get_results($wpdb->prepare(
            "SELECT category, COUNT(*) c FROM $je
             WHERE event_type='outbound' AND category IS NOT NULL AND created_at >= %s
             GROUP BY category ORDER BY c DESC", $since), ARRAY_A) ?: [];

        // Top outbound destinations.
        $top_dest = $wpdb->get_results($wpdb->prepare(
            "SELECT url, category, COUNT(*) c FROM $je
             WHERE event_type='outbound' AND url <> '' AND created_at >= %s
             GROUP BY url, category ORDER BY c DESC LIMIT 12", $since), ARRAY_A) ?: [];

        // Traffic sources — UTM first, else referrer host, else Direct (reduced in PHP).
        $utm_src = $wpdb->get_results($wpdb->prepare(
            "SELECT utm_source s, COUNT(*) c FROM $je
             WHERE event_type='pageview' AND utm_source <> '' AND created_at >= %s
             GROUP BY utm_source ORDER BY c DESC LIMIT 10", $since), ARRAY_A) ?: [];
        $ref_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT referrer, COUNT(*) c FROM $je
             WHERE event_type='pageview' AND created_at >= %s
             GROUP BY referrer ORDER BY c DESC LIMIT 60", $since), ARRAY_A) ?: [];

        // Fan funnel: known fans active → of those, clicked out → of those, purchased.
        $fan_clicked = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT subscriber_id) FROM $je
             WHERE event_type='outbound' AND subscriber_id IS NOT NULL AND created_at >= %s", $since));
        $fan_bought = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT o.subscriber_id) FROM $so o
             JOIN (SELECT DISTINCT subscriber_id FROM $je
                   WHERE subscriber_id IS NOT NULL AND created_at >= %s) j
               ON j.subscriber_id = o.subscriber_id
             WHERE o.subscriber_id IS NOT NULL AND o.ordered_at >= %s", $since, $since));
        $fan_revenue = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(o.total_cents),0) FROM $so o
             JOIN (SELECT DISTINCT subscriber_id FROM $je
                   WHERE subscriber_id IS NOT NULL AND created_at >= %s) j
               ON j.subscriber_id = o.subscriber_id
             WHERE o.subscriber_id IS NOT NULL AND o.ordered_at >= %s", $since, $since));

        return compact('pageviews', 'clicks', 'visitors', 'fans_seen', 'by_cat',
                       'top_dest', 'utm_src', 'ref_rows', 'fan_clicked', 'fan_bought', 'fan_revenue');
    };
    return function_exists('lmeg_stat_cache')
        ? lmeg_stat_cache('journey_' . (int) $days, 3 * MINUTE_IN_SECONDS, $build)
        : $build();
}

/** Reduce raw referrer URLs to a host→count map, with a Direct bucket. */
function lmeg_journey_sources($ref_rows) {
    $host = strtolower((string) lmeg_journey_site_host());
    $out  = [];
    foreach ($ref_rows as $r) {
        $ref = (string) $r['referrer'];
        $c   = (int) $r['c'];
        if ($ref === '') { $out['Direct / none'] = ($out['Direct / none'] ?? 0) + $c; continue; }
        $h = strtolower((string) parse_url($ref, PHP_URL_HOST));
        if ($h === '' || strpos($h, $host) !== false) continue; // internal referrers aren't a "source"
        $h = preg_replace('/^www\./', '', $h);
        $out[$h] = ($out[$h] ?? 0) + $c;
    }
    arsort($out);
    return array_slice($out, 0, 8, true);
}

function lmeg_admin_journey() {
    if (!current_user_can('manage_options')) return;
    $enabled = lmeg_journey_enabled();
    $toggle_url = wp_nonce_url(admin_url('admin-post.php?action=lmeg_journey_toggle'), 'lmeg_journey_toggle');

    echo '<div class="wrap"><h1 style="display:flex;align-items:center;gap:12px;">Journey'
       . '<span style="font:600 11px/1 system-ui;letter-spacing:.08em;text-transform:uppercase;color:#C4B5FD;background:rgba(124,108,246,.18);border:1px solid rgba(124,108,246,.35);padding:4px 8px;border-radius:999px;">Fan analytics</span></h1>';
    echo '<p style="max-width:680px;color:#C7CBD1;font-size:14px;">Where fans go when they leave your site — every stream, ticket link and merch click — and, because Fanloop knows who they are, which of those journeys end in a purchase.</p>';

    if (!$enabled) {
        echo '<div style="max-width:680px;background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:28px;margin-top:14px;">'
           . '<h2 style="margin:0 0 8px;color:#F4F5F7;">Tracking is off</h2>'
           . '<p style="color:#C7CBD1;margin:0 0 18px;">Turn it on to start recording pageviews and outbound clicks (classified into Spotify, Apple Music, Tickets, and so on). Nothing is collected until you do. It has no effect on your store, checkout, or emails.</p>'
           . '<a href="' . esc_url($toggle_url) . '" class="button button-primary button-hero">Enable journey tracking</a>'
           . '</div></div>';
        return;
    }

    // Date window.
    $days  = (int) ($_GET['days'] ?? 30);
    if (!in_array($days, [7, 30, 90], true)) $days = 30;
    $d = lmeg_journey_report_data($days);

    $ctr   = $d['pageviews'] > 0 ? round($d['clicks'] / $d['pageviews'] * 100, 1) : 0;
    $money = function ($c) { return '$' . number_format(((int) $c) / 100, 2); };
    $nf    = function ($n) { return number_format_i18n((int) $n); };
    $accent  = '#8A6CF6';
    $s = lmeg_get_settings();
    if (!empty($s['store_accent2'])) $accent = $s['store_accent2'];

    // ---- controls -------------------------------------------------------
    echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin:16px 0 12px;flex-wrap:wrap;">';
    echo '<div style="display:flex;gap:6px;">';
    foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days'] as $k => $label) {
        $on = $k === $days;
        echo '<a href="' . esc_url(admin_url('admin.php?page=lmeg-journey&days=' . $k)) . '" style="'
           . 'font:600 13px/1 system-ui;padding:7px 13px;border-radius:8px;text-decoration:none;border:1px solid '
           . ($on ? esc_attr($accent) : 'rgba(255,255,255,.18)') . ';color:' . ($on ? '#fff' : '#F4F5F7') . ';background:' . ($on ? esc_attr($accent) : '#fff') . ';">'
           . esc_html($label) . '</a>';
    }
    echo '</div>';
    echo '<a href="' . esc_url($toggle_url) . '" style="font:500 13px/1 system-ui;color:#C7CBD1;text-decoration:none;border:1px solid rgba(255,255,255,.14);background:linear-gradient(160deg,#161826,#1C1F2E);padding:7px 13px;border-radius:8px;">Tracking on · turn off</a>';
    echo '</div>';

    // ---- KPI strip (plain type + hairlines, no cards) -------------------
    $kpis = [
        ['Pageviews',        $nf($d['pageviews'])],
        ['Outbound clicks',  $nf($d['clicks'])],
        ['Click rate',       $ctr . '%'],
        ['Visitors',         $nf($d['visitors'])],
        ['Known fans seen',  $nf($d['fans_seen'])],
    ];
    echo '<div style="display:flex;flex-wrap:wrap;background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-radius:12px;overflow:hidden;">';
    $i = 0;
    foreach ($kpis as $k) {
        $bl = $i++ > 0 ? 'border-left:1px solid rgba(0,0,0,.08);' : '';
        echo '<div style="flex:1 1 150px;padding:18px 20px;' . $bl . '">'
           . '<div style="font:700 30px/1.05 system-ui;color:#F4F5F7;letter-spacing:-.01em;font-variant-numeric:tabular-nums;">' . esc_html($k[1]) . '</div>'
           . '<div style="font:600 11px/1 system-ui;letter-spacing:.07em;text-transform:uppercase;color:#8B90A0;margin-top:7px;">' . esc_html($k[0]) . '</div>'
           . '</div>';
    }
    echo '</div>';

    // ---- Fan funnel (the differentiator) --------------------------------
    $stages = [
        ['Known fans active',   (int) $d['fans_seen'],    'saw them on the site'],
        ['Clicked out',         (int) $d['fan_clicked'],  'streamed / ticket / merch link'],
        ['Purchased',           (int) $d['fan_bought'],   $money($d['fan_revenue']) . ' from these fans'],
    ];
    $fmax = max(1, $d['fans_seen']);
    echo '<div style="background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:20px 22px;margin-top:16px;">';
    echo '<h2 style="margin:0 0 4px;color:#F4F5F7;font-size:16px;">Fan funnel · source → action → purchase</h2>';
    echo '<p style="margin:0 0 16px;color:#8B90A0;font-size:13px;">Identified fans only — the path an anonymous pixel can’t follow.</p>';
    foreach ($stages as $n => $st) {
        $pct  = round($st[1] / $fmax * 100);
        $conv = $n > 0 && $stages[0][1] > 0 ? round($st[1] / $stages[0][1] * 100) : ($n === 0 ? 100 : 0);
        echo '<div style="margin-bottom:12px;">';
        echo '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:5px;">'
           . '<span style="font:600 14px/1 system-ui;color:#F4F5F7;">' . esc_html($st[0]) . '</span>'
           . '<span style="font:600 14px/1 system-ui;color:#F4F5F7;font-variant-numeric:tabular-nums;">' . $nf($st[1])
           . ' <span style="color:#8B90A0;font-weight:500;">· ' . esc_html($st[2]) . '</span></span></div>';
        echo '<div style="height:12px;background:rgba(255,255,255,.10);border-radius:6px;overflow:hidden;">'
           . '<div style="height:100%;width:' . max(2, $pct) . '%;background:' . esc_attr($accent) . ';border-radius:6px;"></div></div>';
        if ($n > 0) echo '<div style="font:500 12px/1 system-ui;color:#8B90A0;margin-top:4px;">' . $conv . '% of active fans</div>';
        echo '</div>';
    }
    echo '</div>';

    // ---- Two columns: categories + destinations -------------------------
    echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:16px;">';

    // Handoffs by category.
    $catmax = 1; foreach ($d['by_cat'] as $r) $catmax = max($catmax, (int) $r['c']);
    $dsp = function_exists('lmeg_journey_dsp_categories') ? lmeg_journey_dsp_categories() : [];
    echo '<div style="flex:1 1 320px;background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:20px 22px;">';
    echo '<h2 style="margin:0 0 14px;color:#F4F5F7;font-size:16px;">Handoffs by destination type</h2>';
    if (!$d['by_cat']) {
        echo '<p style="color:#8B90A0;font-size:13px;margin:0;">No outbound clicks recorded yet in this window.</p>';
    } else {
        foreach ($d['by_cat'] as $r) {
            $isdsp = in_array($r['category'], $dsp, true);
            $pct = round((int) $r['c'] / $catmax * 100);
            echo '<div style="margin-bottom:11px;">';
            echo '<div style="display:flex;justify-content:space-between;font:600 13px/1.2 system-ui;color:#F4F5F7;margin-bottom:4px;">'
               . '<span>' . esc_html($r['category']) . ($isdsp ? ' <span style="color:#1DB954;">♪</span>' : '') . '</span>'
               . '<span style="font-variant-numeric:tabular-nums;">' . $nf($r['c']) . '</span></div>';
            echo '<div style="height:9px;background:rgba(255,255,255,.10);border-radius:5px;overflow:hidden;">'
               . '<div style="height:100%;width:' . max(3, $pct) . '%;background:' . ($isdsp ? '#1DB954' : esc_attr($accent)) . ';border-radius:5px;"></div></div>';
            echo '</div>';
        }
    }
    echo '</div>';

    // Top destinations.
    echo '<div style="flex:1 1 320px;background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:20px 22px;">';
    echo '<h2 style="margin:0 0 14px;color:#F4F5F7;font-size:16px;">Top outbound links</h2>';
    if (!$d['top_dest']) {
        echo '<p style="color:#8B90A0;font-size:13px;margin:0;">Nothing yet.</p>';
    } else {
        echo '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
        foreach ($d['top_dest'] as $r) {
            $u = (string) $r['url'];
            $short = preg_replace('#^https?://(www\.)?#', '', $u);
            if (strlen($short) > 46) $short = substr($short, 0, 45) . '…';
            echo '<tr style="border-top:1px solid rgba(0,0,0,.07);">'
               . '<td style="padding:8px 0;color:#F4F5F7;"><a href="' . esc_url($u) . '" target="_blank" rel="noopener" style="color:#F4F5F7;text-decoration:none;">' . esc_html($short) . '</a>'
               . '<div style="color:#8B90A0;font-size:11px;">' . esc_html((string) $r['category']) . '</div></td>'
               . '<td style="padding:8px 0;text-align:right;color:#F4F5F7;font-weight:600;font-variant-numeric:tabular-nums;white-space:nowrap;">' . $nf($r['c']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';
    echo '</div>'; // end columns

    // ---- Traffic sources ------------------------------------------------
    $sources = lmeg_journey_sources($d['ref_rows']);
    foreach ($d['utm_src'] as $u) { // fold UTM campaigns in as named sources
        $key = 'utm: ' . $u['s'];
        $sources[$key] = ($sources[$key] ?? 0) + (int) $u['c'];
    }
    arsort($sources);
    $sources = array_slice($sources, 0, 10, true);
    $smax = 1; foreach ($sources as $c) $smax = max($smax, $c);
    echo '<div style="background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:20px 22px;margin-top:16px;max-width:640px;">';
    echo '<h2 style="margin:0 0 14px;color:#F4F5F7;font-size:16px;">Where visitors came from</h2>';
    if (!$sources) {
        echo '<p style="color:#8B90A0;font-size:13px;margin:0;">No pageviews recorded yet in this window.</p>';
    } else {
        foreach ($sources as $name => $c) {
            $pct = round($c / $smax * 100);
            echo '<div style="margin-bottom:10px;">';
            echo '<div style="display:flex;justify-content:space-between;font:600 13px/1.2 system-ui;color:#F4F5F7;margin-bottom:4px;">'
               . '<span>' . esc_html($name) . '</span><span style="font-variant-numeric:tabular-nums;">' . $nf($c) . '</span></div>';
            echo '<div style="height:8px;background:#eef1f6;border-radius:5px;overflow:hidden;">'
               . '<div style="height:100%;width:' . max(3, $pct) . '%;background:#C7CBD1;border-radius:5px;"></div></div>';
            echo '</div>';
        }
    }
    echo '</div>';

    echo '<p style="color:#8B90A0;font-size:12px;margin-top:14px;">Numbers cached ~3 min · add <code>?lmeg_fresh=1</code> to force-refresh.</p>';
    echo '</div>'; // .wrap
}

/* ---------------------------------------------------------------------------
 * Stage 3 — per-fan journey + anonymous→fan stitch
 * ------------------------------------------------------------------------- */

/** Emoji for a handoff category (shared by the timeline + the profile card). */
function lmeg_journey_category_icon($cat) {
    $dsp = lmeg_journey_dsp_categories();
    if (in_array($cat, $dsp, true) || $cat === 'DSP Button') return '🎧';
    if ($cat === 'Tickets')         return '🎟';
    if ($cat === 'Trailer / Video') return '▶️';
    return '🔗';
}

/** One fan's on-site journey at a glance (cheap, indexed by subscriber_id). */
function lmeg_fan_journey_summary($subscriber_id) {
    global $wpdb;
    $sid = (int) $subscriber_id;
    $tbl = $wpdb->prefix . 'lmeg_journey_events';
    $clicks = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $tbl WHERE subscriber_id = %d AND event_type='outbound'", $sid));
    $pageviews = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $tbl WHERE subscriber_id = %d AND event_type='pageview'", $sid));
    $last_seen = $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(created_at) FROM $tbl WHERE subscriber_id = %d", $sid));
    $by_cat = $wpdb->get_results($wpdb->prepare(
        "SELECT category, COUNT(*) c FROM $tbl
         WHERE subscriber_id = %d AND event_type='outbound' AND category IS NOT NULL
         GROUP BY category ORDER BY c DESC", $sid), ARRAY_A) ?: [];
    return compact('clicks', 'pageviews', 'last_seen', 'by_cat');
}

/**
 * Attach a fan's PRE-signup anonymous journey to them. Fires the moment we learn
 * who a visitor is (email capture or member login): the events they generated
 * while anonymous — keyed by the first-party lmeg_vid cookie — get stamped with
 * their subscriber_id, so their journey doesn't start blank at signup.
 */
function lmeg_journey_claim($subscriber_id) {
    global $wpdb;
    $sid = (int) $subscriber_id;
    if ($sid <= 0) return 0;
    $vid = isset($_COOKIE['lmeg_vid']) ? preg_replace('/[^a-f0-9]/', '', (string) $_COOKIE['lmeg_vid']) : '';
    if (strlen($vid) !== 32) return 0;
    $tbl = $wpdb->prefix . 'lmeg_journey_events';
    return (int) $wpdb->query($wpdb->prepare(
        "UPDATE $tbl SET subscriber_id = %d WHERE subscriber_id IS NULL AND anon_id = %s",
        $sid, $vid));
}
add_action('lmeg_subscriber_created', 'lmeg_journey_claim');
