<?php
/**
 * Release / drop link tracking.
 *
 * Streaming-link buttons on a drop or release page point at a short local
 * redirector — yoursite.com/lc/<drop_id>/<index>/ — which records the click
 * (which link, when, visitor IP, user-agent, referrer, and the fan's identity
 * if we know them) and then 302s to the real destination. Nothing about the
 * destination URL is exposed in the link, so there's no open-redirect risk:
 * the target is looked up from the drop's own stored links by index.
 *
 * Known fans also get the click added to their timeline (lmeg_broadcast_events)
 * so streaming interest shows up in the CRM alongside email opens/clicks.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('LMEG_LINKTRACK_DB_VERSION')) define('LMEG_LINKTRACK_DB_VERSION', '2');

function lmeg_link_clicks_table() {
    global $wpdb;
    return $wpdb->prefix . 'lmeg_link_clicks';
}

/** Self-installing, version-gated table (same pattern as releases/wallet). */
add_action('init', 'lmeg_link_tracking_maybe_install', 1);
function lmeg_link_tracking_maybe_install() {
    if (get_option('lmeg_link_track_db_version') === LMEG_LINKTRACK_DB_VERSION) return;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    global $wpdb;
    $t = lmeg_link_clicks_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        drop_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        release_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        label VARCHAR(120) NOT NULL DEFAULT '',
        target_url VARCHAR(600) NOT NULL DEFAULT '',
        subscriber_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        country CHAR(2) NOT NULL DEFAULT '',
        user_agent VARCHAR(255) NOT NULL DEFAULT '',
        referrer VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY drop_id (drop_id),
        KEY release_id (release_id),
        KEY country (country),
        KEY created_at (created_at)
    ) $charset;");
    update_option('lmeg_link_track_db_version', LMEG_LINKTRACK_DB_VERSION);
}

/** Visitor IP via the shared helper (security.php), with a safe fallback. */
function lmeg_linktrack_ip() {
    if (function_exists('lmeg_client_ip')) return lmeg_client_ip();
    $ip = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * Country for the CURRENT click, resolved WITHOUT a blocking network call so
 * the redirect stays instant: the Cloudflare header when present, else a
 * cache-only IP lookup (a transient already warmed by the fan-CDP geo helpers).
 * Anything not immediately known is left '' and filled later by the admin
 * panel's lazy backfill (lmeg_link_clicks_backfill_country).
 */
function lmeg_linktrack_country_fast() {
    $cf = strtoupper((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
    if (preg_match('/^[A-Z]{2}$/', $cf) && $cf !== 'XX' && $cf !== 'T1') return $cf;
    $ip = lmeg_linktrack_ip();
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return '';
    $cached = get_transient('lmeg_geo_' . md5($ip)); // set by lmeg_geo_country_from_ip
    if ($cached !== false && $cached !== '-') return (string) $cached;
    return '';
}

/** Tracked redirect URL for link #$index of a given drop. */
function lmeg_link_click_url($drop_id, $index) {
    $drop_id = (int) $drop_id; $index = (int) $index;
    if (get_option('permalink_structure')) {
        return home_url('/lc/' . $drop_id . '/' . $index . '/');
    }
    return add_query_arg(['lmeg_lc_d' => $drop_id, 'lmeg_lc_i' => $index], home_url('/'));
}

add_filter('query_vars', function ($vars) {
    $vars[] = 'lmeg_lc_d';
    $vars[] = 'lmeg_lc_i';
    return $vars;
});

add_action('init', function () {
    add_rewrite_rule('^lc/([0-9]+)/([0-9]+)/?$', 'index.php?lmeg_lc_d=$matches[1]&lmeg_lc_i=$matches[2]', 'top');
});

add_action('template_redirect', 'lmeg_link_click_redirect');
function lmeg_link_click_redirect() {
    $drop_id = (int) get_query_var('lmeg_lc_d');
    if (!$drop_id && isset($_GET['lmeg_lc_d'])) $drop_id = (int) $_GET['lmeg_lc_d'];
    if (!$drop_id) return;
    $has_index = ($GLOBALS['wp_query']->get('lmeg_lc_i', null) !== null && $GLOBALS['wp_query']->get('lmeg_lc_i') !== '')
                 || isset($_GET['lmeg_lc_i']);
    if (!$has_index) return;
    $index = (int) (get_query_var('lmeg_lc_i') !== '' ? get_query_var('lmeg_lc_i') : ($_GET['lmeg_lc_i'] ?? 0));

    $drop  = function_exists('lmeg_drop_get') ? lmeg_drop_get($drop_id) : null;
    $links = ($drop && function_exists('lmeg_drop_links')) ? lmeg_drop_links($drop) : [];
    if (empty($links[$index]) || empty($links[$index]['url'])) {
        wp_safe_redirect(home_url('/'));
        exit;
    }
    $link   = $links[$index];
    $target = $link['url'];

    global $wpdb;
    $subscriber_id = 0;
    if (function_exists('lmeg_current_member')) {
        $m = lmeg_current_member();
        if ($m && !empty($m->id)) $subscriber_id = (int) $m->id;
    }
    $release_id = 0;
    if (function_exists('lmeg_release_for_drop')) {
        $rel = lmeg_release_for_drop($drop_id);
        if ($rel && !empty($rel->id)) $release_id = (int) $rel->id;
    }

    $wpdb->insert(lmeg_link_clicks_table(), [
        'drop_id'       => $drop_id,
        'release_id'    => $release_id,
        'label'         => substr((string) ($link['label'] ?? ''), 0, 120),
        'target_url'    => substr((string) $target, 0, 600),
        'subscriber_id' => $subscriber_id,
        'ip'            => substr(lmeg_linktrack_ip(), 0, 45),
        'country'       => lmeg_linktrack_country_fast(),
        'user_agent'    => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'referrer'      => substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 255),
        'created_at'    => current_time('mysql'),
    ]);

    // Mirror onto the fan's timeline when we know who this is (matches smartlinks).
    if ($subscriber_id) {
        $wpdb->insert($wpdb->prefix . 'lmeg_broadcast_events', [
            'broadcast_id'  => 0,
            'subscriber_id' => $subscriber_id,
            'event_type'    => 'click',
            'url'           => 'release-link:' . substr((string) ($link['label'] ?? ''), 0, 60) . ' → ' . substr((string) $target, 0, 320),
            'ip'            => substr(lmeg_linktrack_ip(), 0, 45),
            'user_agent'    => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'created_at'    => current_time('mysql'),
        ]);
    }

    // Feed the click into the Fan Journey as an outbound handoff — classified
    // (Spotify / Apple Music / Tickets / …) and tied to the fan when known — so
    // release/drop clicks show in the journey funnel, not just the click log.
    // Gated on the journey toggle so nothing is recorded until it's turned on.
    if (function_exists('lmeg_journey_enabled') && lmeg_journey_enabled() && function_exists('lmeg_journey_record')) {
        $ref = substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500);
        lmeg_journey_record([
            'event_type' => 'outbound',
            'category'   => function_exists('lmeg_link_category')
                ? lmeg_link_category($target, function_exists('lmeg_journey_site_host') ? lmeg_journey_site_host() : '', (string) ($link['label'] ?? ''))
                : 'DSP Button',
            'url'        => $target,
            'link_text'  => substr((string) ($link['label'] ?? ''), 0, 120),
            'page_url'   => $ref,
            'referrer'   => $ref,
        ]);
    }

    wp_redirect(esc_url_raw($target), 302);
    exit;
}

/** Flush rewrites once so /lc/… resolves the first time this feature ships. */
add_action('init', function () {
    if (get_option('lmeg_link_track_flushed') !== '1') {
        flush_rewrite_rules(false);
        update_option('lmeg_link_track_flushed', '1', false);
    }
}, 99);

/* ---------- stats helpers (admin) ---------- */

function lmeg_link_clicks_total($drop_id) {
    global $wpdb;
    $drop_id = (int) $drop_id;
    if (!$drop_id) return 0;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . lmeg_link_clicks_table() . " WHERE drop_id = %d", $drop_id
    ));
}

/** [ ['label'=>..,'clicks'=>N,'last'=>datetime], ... ] newest-active first. */
function lmeg_link_clicks_by_label($drop_id) {
    global $wpdb;
    $drop_id = (int) $drop_id;
    if (!$drop_id) return [];
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT label, COUNT(*) clicks, MAX(created_at) last
         FROM " . lmeg_link_clicks_table() . "
         WHERE drop_id = %d GROUP BY label ORDER BY clicks DESC", $drop_id
    ));
    $out = [];
    foreach ($rows as $r) $out[] = ['label' => $r->label, 'clicks' => (int) $r->clicks, 'last' => $r->last];
    return $out;
}

/** Recent individual clicks (with IP) for the admin, newest first. */
function lmeg_link_clicks_recent($drop_id, $limit = 15) {
    global $wpdb;
    $drop_id = (int) $drop_id;
    if (!$drop_id) return [];
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . lmeg_link_clicks_table() . "
         WHERE drop_id = %d ORDER BY id DESC LIMIT %d", $drop_id, (int) $limit
    ));
}

/** Headline click stats for a release: total, unique visitors (IPs), known fans. */
function lmeg_link_clicks_stats($drop_id) {
    global $wpdb;
    $drop_id = (int) $drop_id;
    if (!$drop_id) return ['total' => 0, 'unique' => 0, 'known' => 0];
    $t = lmeg_link_clicks_table();
    return [
        'total'  => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE drop_id = %d", $drop_id)),
        'unique' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ip) FROM $t WHERE drop_id = %d", $drop_id)),
        'known'  => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT subscriber_id) FROM $t WHERE drop_id = %d AND subscriber_id > 0", $drop_id)),
    ];
}

/** Daily click counts for the last $days days (date => count, zero-filled, oldest→newest). */
function lmeg_link_clicks_daily($drop_id, $days = 30) {
    global $wpdb;
    $drop_id = (int) $drop_id;
    if (!$drop_id) return [];
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(created_at) d, COUNT(*) n FROM " . lmeg_link_clicks_table() . "
         WHERE drop_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY) GROUP BY DATE(created_at)",
        $drop_id, (int) $days
    ));
    $by = [];
    foreach ($rows as $r) $by[$r->d] = (int) $r->n;
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) { $day = date('Y-m-d', strtotime("-$i days")); $out[$day] = $by[$day] ?? 0; }
    return $out;
}

/** Top referrer hosts (where the click came from) for a release. host => count. */
function lmeg_link_clicks_by_source($drop_id, $limit = 6) {
    global $wpdb;
    $drop_id = (int) $drop_id;
    if (!$drop_id) return [];
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT referrer, COUNT(*) n FROM " . lmeg_link_clicks_table() . " WHERE drop_id = %d GROUP BY referrer",
        $drop_id
    ));
    $agg = [];
    foreach ($rows as $r) {
        $ref  = trim((string) $r->referrer);
        $host = $ref === '' ? 'Direct / unknown' : (parse_url($ref, PHP_URL_HOST) ?: 'Direct / unknown');
        $host = preg_replace('/^www\./', '', $host);
        $agg[$host] = ($agg[$host] ?? 0) + (int) $r->n;
    }
    arsort($agg);
    return array_slice($agg, 0, (int) $limit, true);
}

/**
 * Lazily fill the country for a drop's clicks that don't have one yet — a
 * small, bounded batch of DISTINCT un-geolocated IPs per admin view, resolved
 * through the day-cached lmeg_geo_country_from_ip(). Runs only in wp-admin so
 * a page render never pays for more than $limit lookups, and repeat views walk
 * the rest of the backlog. No-op when the geo helper isn't available.
 */
function lmeg_link_clicks_backfill_country($drop_id, $limit = 12) {
    if (!function_exists('lmeg_geo_country_from_ip')) return;
    global $wpdb;
    $drop_id = (int) $drop_id;
    if (!$drop_id) return;
    $t = lmeg_link_clicks_table();
    $ips = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT ip FROM $t
         WHERE drop_id = %d AND (country IS NULL OR country = '') AND ip <> '' AND ip <> '0.0.0.0'
         ORDER BY id DESC LIMIT %d", $drop_id, (int) $limit
    ));
    foreach ((array) $ips as $ip) {
        $cc = lmeg_geo_country_from_ip($ip);
        if ($cc) $wpdb->update($t, ['country' => $cc], ['drop_id' => $drop_id, 'ip' => $ip]);
    }
}

/**
 * Clicks grouped by country for a release. Backfills missing countries first
 * (bounded), then returns [ ['cc'=>'US','n'=>N], ... ] most-clicked first.
 * Rows still without a country after backfill are bucketed under '' (Unknown).
 */
function lmeg_link_clicks_by_country($drop_id, $limit = 8) {
    global $wpdb;
    $drop_id = (int) $drop_id;
    if (!$drop_id) return [];
    if (is_admin()) lmeg_link_clicks_backfill_country($drop_id);
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT country cc, COUNT(*) n FROM " . lmeg_link_clicks_table() . "
         WHERE drop_id = %d GROUP BY country ORDER BY n DESC", $drop_id
    ));
    $out = [];
    foreach ($rows as $r) $out[] = ['cc' => strtoupper((string) $r->cc), 'n' => (int) $r->n];
    // Keep top N known countries, fold everything else (incl. Unknown) into the tail count if truncated.
    return array_slice($out, 0, (int) $limit);
}
