<?php
/**
 * Fan CRM layer — unique codes, referral links, fan-type scoring, and the
 * per-fan activity timeline. The OpenStage-inspired feature set that runs
 * entirely on data the plugin already collects.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------------------------
 * IP → country geolocation
 *
 * Fills the country gap for email signups (phone + address signups already
 * carry a country). Order of precedence stays: phone country > address
 * country > IP geo. Uses the Cloudflare country header when present (free,
 * instant), else api.country.is (free, HTTPS, no key). Per-IP result cached
 * a day; lookups fail silently.
 * ------------------------------------------------------------------------- */

function lmeg_geo_country_from_ip($ip) {
    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return '';
    }
    $key    = 'lmeg_geo_' . md5($ip);
    $cached = get_transient($key);
    if ($cached !== false) {
        return $cached === '-' ? '' : $cached;
    }

    $cc   = '';
    $resp = wp_remote_get('https://api.country.is/' . rawurlencode($ip), ['timeout' => 2]);
    if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
        $d = json_decode(wp_remote_retrieve_body($resp), true);
        if (!empty($d['country']) && preg_match('/^[A-Z]{2}$/', strtoupper($d['country']))) {
            $cc = strtoupper($d['country']);
        }
    }
    set_transient($key, $cc ?: '-', DAY_IN_SECONDS);
    return $cc;
}

/**
 * Country for the CURRENT request — Cloudflare header first (free), then
 * API lookup on the resolved client IP.
 */
function lmeg_geo_country_current_request() {
    $cf = strtoupper((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
    if (preg_match('/^[A-Z]{2}$/', $cf) && $cf !== 'XX' && $cf !== 'T1') {
        return $cf;
    }
    $ip = function_exists('lmeg_client_ip') ? lmeg_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
    return lmeg_geo_country_from_ip($ip);
}

/**
 * Backfill: existing subscribers with a stored IP but no country get
 * geolocated in small batches (15 every 5 minutes) until the gap is closed.
 * Re-applies auto-tags so country:* chips appear as rows fill in.
 */
add_action('lmeg_broadcast_tick', 'lmeg_geo_backfill', 60);
function lmeg_geo_backfill() {
    if (get_site_transient('lmeg_geo_bf_lock')) return;
    set_site_transient('lmeg_geo_bf_lock', 1, MINUTE_IN_SECONDS);

    global $wpdb;
    $subs = $wpdb->prefix . LMEG_TABLE;
    $rows = $wpdb->get_results(
        "SELECT id, ip FROM $subs
         WHERE (country IS NULL OR country = '') AND ip IS NOT NULL AND ip <> ''
         ORDER BY id ASC LIMIT 40"
    );
    foreach ((array) $rows as $r) {
        $cc = lmeg_geo_country_from_ip($r->ip);
        if ($cc) {
            $wpdb->update($subs, ['country' => $cc], ['id' => (int) $r->id]);
            $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM $subs WHERE id = %d", (int) $r->id));
            if ($fresh) lmeg_apply_auto_tags($fresh);
        }
    }
}

/**
 * IP → approximate city/region/country in one call (ipwho.is — free, HTTPS,
 * keyless). Approximate by nature (often the ISP hub), so it only ever FILLS
 * EMPTY city fields — a real city from a form or Shopify order always wins.
 *
 * @return array|null|false  ['city','region','country'] on a hit; null when
 *                           the IP genuinely has no city data (cached 30 days);
 *                           false on a transport error (NOT cached — retryable).
 */
function lmeg_geo_city_from_ip($ip) {
    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return null;
    }
    $key    = 'lmeg_geocity_' . md5($ip);
    $cached = get_transient($key);
    if ($cached !== false) {
        return $cached === '-' ? null : $cached;
    }

    $resp = wp_remote_get('https://ipwho.is/' . rawurlencode($ip), ['timeout' => 4]);
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        return false; // API down / network blip — try again later, don't cache
    }
    $d = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($d) || empty($d['success']) || empty($d['city'])) {
        set_transient($key, '-', 30 * DAY_IN_SECONDS);
        return null;
    }
    $hit = [
        'city'    => substr(sanitize_text_field((string) $d['city']), 0, 100),
        'region'  => substr(sanitize_text_field((string) ($d['region'] ?? '')), 0, 100),
        'country' => preg_match('/^[A-Z]{2}$/', strtoupper((string) ($d['country_code'] ?? ''))) ? strtoupper($d['country_code']) : '',
    ];
    set_transient($key, $hit, 30 * DAY_IN_SECONDS);
    return $hit;
}

/**
 * Backfill: subscribers with a stored IP but NO city get an approximate city
 * (+ region, + country if missing) from IP geolocation, then fresh auto-tags
 * so city:* chips appear. Cursor-based so IPs with no city data aren't
 * retried forever; when a pass completes the cursor resets, and cached
 * misses make re-walks nearly free (no HTTP). New signups have higher ids,
 * so they're picked up within a pass automatically.
 */
add_action('lmeg_broadcast_tick', 'lmeg_geo_city_backfill', 62);
function lmeg_geo_city_backfill() {
    if (get_site_transient('lmeg_geocity_bf_lock')) return;
    set_site_transient('lmeg_geocity_bf_lock', 1, MINUTE_IN_SECONDS);

    global $wpdb;
    $subs   = $wpdb->prefix . LMEG_TABLE;
    $cursor = (int) get_option('lmeg_geo_city_cursor', 0);
    $rows   = $wpdb->get_results($wpdb->prepare(
        "SELECT id, ip FROM $subs
          WHERE id > %d AND ip IS NOT NULL AND ip <> ''
            AND (city IS NULL OR city = '')
          ORDER BY id ASC LIMIT 40",
        $cursor
    ));
    if (!$rows) {
        // Pass complete — rewind so brand-new signups (and, after the 30-day
        // miss cache expires, previously unmapped IPs) get another look.
        if ($cursor > 0) update_option('lmeg_geo_city_cursor', 0, false);
        return;
    }
    foreach ($rows as $r) {
        $g = lmeg_geo_city_from_ip($r->ip);
        if ($g === false) return; // API down — resume from the same cursor next tick
        if (is_array($g)) {
            $upd = ['city' => $g['city']];
            if (!empty($g['region']))  $upd['region'] = $g['region'];
            // Never overwrite an existing country — only fill a gap.
            $has_cc = $wpdb->get_var($wpdb->prepare("SELECT country FROM $subs WHERE id = %d", (int) $r->id));
            if (!$has_cc && !empty($g['country'])) $upd['country'] = $g['country'];
            $wpdb->update($subs, $upd, ['id' => (int) $r->id]);
            $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM $subs WHERE id = %d", (int) $r->id));
            if ($fresh && function_exists('lmeg_apply_auto_tags')) lmeg_apply_auto_tags($fresh);
        }
        update_option('lmeg_geo_city_cursor', (int) $r->id, false);
    }
}

/**
 * On-demand burst geocoder for big imports. Walks the same cursor as the
 * background backfill but processes as many un-citied IPs as it can inside a
 * time budget, so the fan map fills in minutes instead of over days. Safe to
 * call repeatedly; stops early if the geo API starts erroring (rate limit /
 * outage). Returns ['located','processed','remaining'].
 */
function lmeg_geo_city_burst($max = 400, $seconds = 18) {
    global $wpdb;
    $subs      = $wpdb->prefix . LMEG_TABLE;
    $started   = time();
    $located   = 0;
    $processed = 0;
    $stop      = false;
    $cursor    = (int) get_option('lmeg_geo_city_cursor', 0);

    while (!$stop && $processed < $max && (time() - $started) < $seconds) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, ip FROM $subs
              WHERE id > %d AND ip IS NOT NULL AND ip <> '' AND (city IS NULL OR city = '')
              ORDER BY id ASC LIMIT 25",
            $cursor
        ));
        if (!$rows) { $cursor = 0; break; } // reached the end of the list — rewind
        foreach ($rows as $r) {
            if ($processed >= $max || (time() - $started) >= $seconds) { $stop = true; break; }
            $g = lmeg_geo_city_from_ip($r->ip);
            if ($g === false) { $stop = true; break; } // API blip/limit — stop, keep cursor so we resume here
            if (is_array($g)) {
                $upd = ['city' => $g['city']];
                if (!empty($g['region'])) $upd['region'] = $g['region'];
                $has_cc = $wpdb->get_var($wpdb->prepare("SELECT country FROM $subs WHERE id = %d", (int) $r->id));
                if (!$has_cc && !empty($g['country'])) $upd['country'] = $g['country'];
                $wpdb->update($subs, $upd, ['id' => (int) $r->id]);
                $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM $subs WHERE id = %d", (int) $r->id));
                if ($fresh && function_exists('lmeg_apply_auto_tags')) lmeg_apply_auto_tags($fresh);
                $located++;
            }
            $processed++;
            $cursor = (int) $r->id;
        }
    }
    update_option('lmeg_geo_city_cursor', $cursor, false);
    $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE ip IS NOT NULL AND ip <> '' AND (city IS NULL OR city = '')");
    return ['located' => $located, 'processed' => $processed, 'remaining' => $remaining];
}

/**
 * Backfill: existing subscribers who already have a city but no city:* tag
 * (rows created before city auto-tags existed) get their auto-tags refreshed
 * in small batches. Self-terminating — once every city'd row is tagged the
 * query comes back empty.
 */
add_action('lmeg_broadcast_tick', 'lmeg_city_tag_backfill', 61);
function lmeg_city_tag_backfill() {
    if (get_site_transient('lmeg_citytag_bf_lock')) return;
    set_site_transient('lmeg_citytag_bf_lock', 1, 5 * MINUTE_IN_SECONDS);

    global $wpdb;
    $subs = $wpdb->prefix . LMEG_TABLE;
    $rows = $wpdb->get_results(
        "SELECT s.* FROM $subs s
          WHERE s.city IS NOT NULL AND s.city <> ''
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->prefix}lmeg_subscriber_tags st
                  JOIN {$wpdb->prefix}lmeg_tags t ON t.id = st.tag_id
                 WHERE st.subscriber_id = s.id AND t.slug LIKE 'city:%'
            )
          ORDER BY s.id ASC LIMIT 25"
    );
    foreach ((array) $rows as $r) {
        if (function_exists('lmeg_apply_auto_tags')) lmeg_apply_auto_tags($r);
    }
}

/* ---------------------------------------------------------------------------
 * City geocoding + distance — powers "within X km of <city>" radius sends.
 * Open-Meteo's geocoder is free, keyless, HTTPS. Successful lookups are
 * cached permanently in one option (cities don't move); failures retry after
 * a day via a transient so an API blip can't poison the cache.
 * ------------------------------------------------------------------------- */

function lmeg_geo_city_coords($city, $region = '', $country = '') {
    $city = trim((string) $city);
    if ($city === '') return null;

    $ckey  = md5(strtolower($city) . '|' . strtolower(trim((string) $region)) . '|' . strtoupper(trim((string) $country)));
    $cache = get_option('lmeg_city_geo', []);
    if (!is_array($cache)) $cache = [];
    if (isset($cache[$ckey])) return $cache[$ckey];
    if (get_transient('lmeg_city_geo_miss_' . $ckey)) return null;

    $coords = null;
    $resp   = wp_remote_get(
        'https://geocoding-api.open-meteo.com/v1/search?name=' . rawurlencode($city) . '&count=5&language=en&format=json',
        ['timeout' => 4]
    );
    if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
        $d       = json_decode(wp_remote_retrieve_body($resp), true);
        $results = is_array($d) && !empty($d['results']) ? $d['results'] : [];
        // Prefer a result in the fan's country (there is a Toronto, Ohio…),
        // then one whose admin1 matches their region, else the top hit
        // (Open-Meteo ranks by population, which is usually right).
        $pick = null;
        foreach ($results as $r) {
            if ($country && strtoupper((string) ($r['country_code'] ?? '')) === strtoupper(trim($country))) { $pick = $r; break; }
        }
        if (!$pick && $region !== '') {
            foreach ($results as $r) {
                if (stripos((string) ($r['admin1'] ?? ''), trim($region)) !== false) { $pick = $r; break; }
            }
        }
        if (!$pick && $results) $pick = $results[0];
        if ($pick && isset($pick['latitude'], $pick['longitude'])) {
            $coords = ['lat' => (float) $pick['latitude'], 'lng' => (float) $pick['longitude']];
        }
    }

    if ($coords) {
        $cache[$ckey] = $coords;
        update_option('lmeg_city_geo', $cache, false);
    } else {
        set_transient('lmeg_city_geo_miss_' . $ckey, 1, DAY_IN_SECONDS);
    }
    return $coords;
}

/** Great-circle distance in km (haversine). */
function lmeg_geo_distance_km($lat1, $lng1, $lat2, $lng2) {
    $rad = M_PI / 180;
    $a   = sin((($lat2 - $lat1) * $rad) / 2) ** 2
         + cos($lat1 * $rad) * cos($lat2 * $rad) * sin((($lng2 - $lng1) * $rad) / 2) ** 2;
    return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * One-shot backfill: fans who already have a language on file but no lang:*
 * tag (signed up before language auto-tags existed) get re-tagged.
 */
add_action('lmeg_broadcast_tick', 'lmeg_lang_tag_backfill', 64);
function lmeg_lang_tag_backfill() {
    if (get_option('lmeg_lang_tag_fix_done')) return;
    global $wpdb;
    $subs = $wpdb->prefix . LMEG_TABLE;
    $rows = $wpdb->get_results(
        "SELECT s.* FROM $subs s
          WHERE s.lang IS NOT NULL AND s.lang <> ''
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->prefix}lmeg_subscriber_tags st
                  JOIN {$wpdb->prefix}lmeg_tags t ON t.id = st.tag_id
                 WHERE st.subscriber_id = s.id AND t.slug LIKE 'lang:%'
            )
          LIMIT 50"
    );
    if (!$rows) { update_option('lmeg_lang_tag_fix_done', 1, false); return; }
    foreach ($rows as $r) {
        if (function_exists('lmeg_apply_auto_tags')) lmeg_apply_auto_tags($r);
    }
}

/**
 * One-shot cleanup: fans who were tagged has-address off a city/region alone
 * (possible between v2.55.25's IP-city backfill and the stricter street-or-
 * postal rule) get their auto-tags recomputed, which detaches the tag.
 * Batches of 50 per tick until none remain, then flags done.
 */
add_action('lmeg_broadcast_tick', 'lmeg_addr_tag_cleanup', 63);
function lmeg_addr_tag_cleanup() {
    if (get_option('lmeg_addr_tag_fix_done')) return;
    global $wpdb;
    $subs = $wpdb->prefix . LMEG_TABLE;
    $rows = $wpdb->get_results(
        "SELECT s.* FROM $subs s
          JOIN {$wpdb->prefix}lmeg_subscriber_tags st ON st.subscriber_id = s.id
          JOIN {$wpdb->prefix}lmeg_tags t ON t.id = st.tag_id AND t.slug = 'has-address'
         WHERE (s.street IS NULL OR s.street = '') AND (s.postal_code IS NULL OR s.postal_code = '')
         LIMIT 50"
    );
    if (!$rows) { update_option('lmeg_addr_tag_fix_done', 1, false); return; }
    foreach ($rows as $r) {
        if (function_exists('lmeg_apply_auto_tags')) lmeg_apply_auto_tags($r);
    }
}

/* ---------------------------------------------------------------------------
 * On-site page views for IDENTIFIED fans — identity-linked analytics no
 * generic analytics plugin can give you. Any visitor carrying the signed
 * member cookie (set on signup, magic-link sign-in, or a one-tap link) gets
 * their front-end page views logged to lmeg_broadcast_events as
 * event_type='pageview' / source='site', feeding the fan timeline +
 * interaction counts. Anonymous visitors are never tracked here — that's
 * what a regular analytics plugin is for.
 * ------------------------------------------------------------------------- */

add_action('wp', 'lmeg_track_member_pageview');
function lmeg_track_member_pageview() {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_404() || is_preview()) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    if (!function_exists('lmeg_current_member')) return;
    // Don't log the band/staff browsing their own site.
    if (is_user_logged_in() && current_user_can('edit_posts')) return;

    $member = lmeg_current_member();
    if (!$member) return;

    $post_id = is_singular() ? (int) get_the_ID() : 0;
    $url     = $post_id ? get_permalink($post_id) : home_url(($_SERVER['REQUEST_URI'] ?? '/'));
    // Strip query noise (utm, lmeg params) so the same page dedupes cleanly.
    $url     = strtok((string) $url, '?');

    // One view per fan+page per 30 minutes — a refresh spree isn't 10 visits.
    $tkey = 'lmeg_pv_' . (int) $member->id . '_' . substr(md5($url), 0, 12);
    if (get_transient($tkey)) return;
    set_transient($tkey, 1, 30 * MINUTE_IN_SECONDS);

    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'lmeg_broadcast_events', [
        'broadcast_id'  => 0,
        'subscriber_id' => (int) $member->id,
        'event_type'    => 'pageview',
        'source'        => 'site',
        'source_ref'    => $post_id,
        'url'           => substr($url, 0, 500),
        'ip'            => substr(function_exists('lmeg_client_ip') ? lmeg_client_ip() : '', 0, 45),
        'user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        'created_at'    => current_time('mysql'),
    ]);
}

/* ---------------------------------------------------------------------------
 * Unique codes + referral links
 * ------------------------------------------------------------------------- */

/**
 * Get (lazily generating) the fan's unique code. Serves double duty:
 * a presale/discount identifier ({unique_code}) and the referral handle
 * ({referral_link} → /?ref=CODE).
 */
function lmeg_get_fan_code($subscriber_id) {
    global $wpdb;
    $tbl  = $wpdb->prefix . LMEG_TABLE;
    $code = $wpdb->get_var($wpdb->prepare("SELECT referral_code FROM $tbl WHERE id = %d", (int) $subscriber_id));
    if ($code) return $code;

    // 8-char uppercase, unambiguous alphabet. Retry on the freak collision.
    for ($i = 0; $i < 5; $i++) {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($c = 0; $c < 8; $c++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $ok = $wpdb->query($wpdb->prepare(
            "UPDATE $tbl SET referral_code = %s WHERE id = %d AND referral_code IS NULL",
            $code, (int) $subscriber_id
        ));
        if ($ok !== false) {
            $fresh = $wpdb->get_var($wpdb->prepare("SELECT referral_code FROM $tbl WHERE id = %d", (int) $subscriber_id));
            if ($fresh) return $fresh;
        }
    }
    return '';
}

function lmeg_referral_url($subscriber_id) {
    $code = lmeg_get_fan_code($subscriber_id);
    return $code ? add_query_arg('ref', $code, home_url('/')) : home_url('/');
}

/**
 * Capture ?ref=CODE into a 30-day cookie so the signup that follows can be
 * credited to the referrer.
 */
add_action('init', 'lmeg_maybe_capture_ref', 5);
function lmeg_maybe_capture_ref() {
    if (empty($_GET['ref'])) return;
    $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $_GET['ref']));
    if (strlen($code) < 6 || strlen($code) > 12) return;
    setcookie('lmeg_ref', $code, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
    $_COOKIE['lmeg_ref'] = $code;
}

/**
 * Resolve the referral cookie to a subscriber id (the referrer), or null.
 */
function lmeg_resolve_ref_cookie() {
    global $wpdb;
    $code = isset($_COOKIE['lmeg_ref']) ? strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $_COOKIE['lmeg_ref'])) : '';
    if (!$code) return null;
    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE referral_code = %s", $code
    ));
    return $id ? (int) $id : null;
}

/**
 * Stamp referred_by on freshly created subscribers.
 */
add_action('lmeg_subscriber_created', 'lmeg_apply_referral', 5, 1);
function lmeg_apply_referral($subscriber_id) {
    $referrer = lmeg_resolve_ref_cookie();
    if (!$referrer || $referrer === (int) $subscriber_id) return;
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . LMEG_TABLE,
        ['referred_by' => $referrer],
        ['id' => (int) $subscriber_id]
    );
}

/* ---------------------------------------------------------------------------
 * Fan-type scoring — superfan / engaged / casual / dormant
 * ------------------------------------------------------------------------- */

/**
 * Classify every subscriber and refresh their fan-type auto-tag.
 * Criteria (rolling 90 days). Contest entries and survey votes count as
 * "actions" alongside clicks — entering a contest is at least as deliberate
 * as clicking an email, so an entrant can never score dormant.
 *   superfan — any shop order OR active paid tier
 *   engaged  — 2+ actions (clicks + contest entries + survey votes), or
 *              5+ opens, or visited the site on 2+ separate days
 *   casual   — at least 1 action, open, or site visit
 *   dormant  — nothing
 * Site visits count DISTINCT DAYS (not raw pageviews) so one deep browsing
 * session doesn't score as ongoing engagement — coming BACK is the signal.
 */
function lmeg_recalculate_fan_types() {
    global $wpdb;
    $subs   = $wpdb->prefix . LMEG_TABLE;
    $events = $wpdb->prefix . 'lmeg_broadcast_events';
    $orders = $wpdb->prefix . 'lmeg_shop_orders';
    $since  = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - 90 * DAY_IN_SECONDS);

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT s.id,
                s.member_status, s.member_tier_id,
                COALESCE(e.clicks, 0) AS clicks,
                COALESCE(e.opens, 0)  AS opens,
                COALESCE(e.visit_days, 0) AS visit_days,
                COALESCE(o.orders, 0) AS orders,
                COALESCE(ce.entries, 0) AS centries,
                COALESCE(sv.votes, 0) AS votes
         FROM $subs s
         LEFT JOIN (
             SELECT subscriber_id,
                    SUM(event_type = 'click') AS clicks,
                    SUM(event_type = 'open')  AS opens,
                    COUNT(DISTINCT CASE WHEN event_type = 'pageview' THEN DATE(created_at) END) AS visit_days
             FROM $events WHERE created_at >= %s GROUP BY subscriber_id
         ) e ON e.subscriber_id = s.id
         LEFT JOIN (
             SELECT subscriber_id, COUNT(*) AS orders
             FROM $orders WHERE ordered_at >= %s GROUP BY subscriber_id
         ) o ON o.subscriber_id = s.id
         LEFT JOIN (
             SELECT subscriber_id, COUNT(*) AS entries
             FROM {$wpdb->prefix}lmeg_contest_entries WHERE entered_at >= %s GROUP BY subscriber_id
         ) ce ON ce.subscriber_id = s.id
         LEFT JOIN (
             SELECT subscriber_id, COUNT(*) AS votes
             FROM {$wpdb->prefix}lmeg_survey_votes WHERE created_at >= %s GROUP BY subscriber_id
         ) sv ON sv.subscriber_id = s.id
         WHERE s.unsubscribed_at IS NULL",
        $since, $since, $since, $since
    ));

    $counts = ['superfan' => 0, 'engaged' => 0, 'casual' => 0, 'dormant' => 0];
    foreach ((array) $rows as $r) {
        $is_paying = ($r->member_status === 'active' && $r->member_tier_id);
        $actions   = (int) $r->clicks + (int) $r->centries + (int) $r->votes;
        if ($r->orders > 0 || $is_paying) {
            $type = 'superfan';
        } elseif ($actions >= 2 || $r->opens >= 5 || $r->visit_days >= 2) {
            $type = 'engaged';
        } elseif ($actions >= 1 || $r->opens >= 1 || $r->visit_days >= 1) {
            $type = 'casual';
        } else {
            $type = 'dormant';
        }
        $counts[$type]++;

        lmeg_detach_auto_tags($r->id, 'fan-type:');
        $tag = lmeg_get_or_create_tag('fan-type:' . $type, 'Fan type: ' . ucfirst($type), true, lmeg_fan_type_color($type));
        if ($tag) lmeg_attach_tag($r->id, $tag->id);
    }

    update_option('lmeg_fan_types_last_run', current_time('mysql'), false);
    delete_transient('lmeg_stat_audience'); // fan-type tags changed → refresh the Audience page
    return $counts;
}

function lmeg_fan_type_color($type) {
    return [
        'superfan' => '#d05fa2',
        'engaged'  => '#8b5cf6',
        'casual'   => '#3b82f6',
        'dormant'  => '#9ca3af',
    ][$type] ?? '#6b7280';
}

/**
 * Cron: refresh fan types daily. Piggybacks the minute tick with a 24h lock.
 */
add_action('lmeg_broadcast_tick', 'lmeg_fan_types_cron', 50);
function lmeg_fan_types_cron() {
    if (get_site_transient('lmeg_fan_types_lock')) return;
    set_site_transient('lmeg_fan_types_lock', 1, DAY_IN_SECONDS);
    lmeg_recalculate_fan_types();
}

/* ---------------------------------------------------------------------------
 * Fan timeline — one merged, ordered activity stream per subscriber
 * ------------------------------------------------------------------------- */

/**
 * @return array of ['at' => datetime, 'icon', 'label'] newest first
 */
/**
 * Interaction counts for the fan-profile summary card: on-site page views,
 * presale/ticket clicks, contests entered, surveys voted.
 */
function lmeg_fan_interactions($subscriber_id) {
    global $wpdb;
    $sid    = (int) $subscriber_id;
    $events = $wpdb->prefix . 'lmeg_broadcast_events';
    return [
        'pageviews_30d' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $events WHERE subscriber_id = %d AND event_type = 'pageview'
              AND created_at >= %s", $sid, date('Y-m-d H:i:s', current_time('timestamp') - 30 * DAY_IN_SECONDS))),
        'pageviews'     => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $events WHERE subscriber_id = %d AND event_type = 'pageview'", $sid)),
        'presale'       => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $events WHERE subscriber_id = %d AND event_type = 'click'
              AND url LIKE 'smartlink:tour-%%'", $sid)),
        'contests'      => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}lmeg_contest_entries WHERE subscriber_id = %d", $sid)),
        'surveys'       => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}lmeg_survey_votes WHERE subscriber_id = %d", $sid)),
    ];
}

function lmeg_fan_timeline($subscriber_id, $limit = 100) {
    global $wpdb;
    $sid    = (int) $subscriber_id;
    $subs   = $wpdb->prefix . LMEG_TABLE;
    $events = $wpdb->prefix . 'lmeg_broadcast_events';
    $log    = $wpdb->prefix . 'lmeg_broadcast_log';
    $orders = $wpdb->prefix . 'lmeg_shop_orders';
    $bcast  = $wpdb->prefix . 'lmeg_broadcasts';
    $grants = $wpdb->prefix . 'lmeg_soft_grants';

    $items = [];

    $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM $subs WHERE id = %d", $sid));
    if (!$sub) return [];

    $items[] = ['at' => $sub->created_at, 'icon' => '🌱', 'label' => 'Joined the list'];
    if ($sub->unsubscribed_at) {
        $items[] = ['at' => $sub->unsubscribed_at, 'icon' => '👋', 'label' => 'Unsubscribed'];
    }
    if ($sub->welcome_sent_at) {
        $items[] = ['at' => $sub->welcome_sent_at, 'icon' => '💌', 'label' => 'Welcome email sent'];
    }

    // Broadcast sends to this fan.
    $sends = $wpdb->get_results($wpdb->prepare(
        "SELECT l.sent_at, l.channel, b.subject FROM $log l
         LEFT JOIN $bcast b ON b.id = l.broadcast_id
         WHERE l.subscriber_id = %d AND l.status = 'sent' ORDER BY l.sent_at DESC LIMIT 40", $sid
    ));
    foreach ((array) $sends as $r) {
        $items[] = [
            'at'    => $r->sent_at,
            'icon'  => $r->channel === 'sms' ? '📱' : '📤',
            'label' => 'Received ' . ($r->channel === 'sms' ? 'SMS' : 'email') . ': ' . ($r->subject ?: 'broadcast'),
        ];
    }

    // Opens + clicks + on-site interactions (pageviews, smartlink/presale clicks).
    $evs = $wpdb->get_results($wpdb->prepare(
        "SELECT e.created_at, e.event_type, e.url, b.subject FROM $events e
         LEFT JOIN $bcast b ON b.id = e.broadcast_id
         WHERE e.subscriber_id = %d ORDER BY e.created_at DESC LIMIT 80", $sid
    ));
    foreach ((array) $evs as $r) {
        $url = (string) $r->url;
        if ($r->event_type === 'pageview') {
            $path    = wp_parse_url($url, PHP_URL_PATH) ?: '/';
            $items[] = ['at' => $r->created_at, 'icon' => '📄', 'label' => 'Visited ' . $path];
        } elseif ($r->event_type === 'click' && strpos($url, 'smartlink:tour-presale-') === 0) {
            $items[] = ['at' => $r->created_at, 'icon' => '🎟', 'label' => 'Clicked a presale link — ' . esc_url(rawurldecode(preg_replace('/^smartlink:[^ ]+ → /', '', $url)))];
        } elseif ($r->event_type === 'click' && strpos($url, 'smartlink:tour-tickets-') === 0) {
            $items[] = ['at' => $r->created_at, 'icon' => '🎟', 'label' => 'Clicked a ticket link — ' . esc_url(rawurldecode(preg_replace('/^smartlink:[^ ]+ → /', '', $url)))];
        } elseif ($r->event_type === 'bounce') {
            $items[] = ['at' => $r->created_at, 'icon' => '📛', 'label' => 'Email bounced (' . ($url ?: 'hard') . ')'];
        } elseif ($r->event_type === 'spam') {
            $items[] = ['at' => $r->created_at, 'icon' => '🚫', 'label' => 'Marked the email as spam — suppressed + unsubscribed'];
        } elseif ($r->event_type === 'click' && strpos($url, 'smartlink:') === 0) {
            $items[] = ['at' => $r->created_at, 'icon' => '🔗', 'label' => 'Clicked link ' . esc_html(rawurldecode(substr($url, strlen('smartlink:'))))];
        } else {
            $items[] = [
                'at'    => $r->created_at,
                'icon'  => $r->event_type === 'click' ? '🖱' : '👀',
                'label' => ($r->event_type === 'click' ? 'Clicked' : 'Opened') . ' "' . ($r->subject ?: 'broadcast') . '"'
                         . ($r->event_type === 'click' && $url ? ' → ' . esc_url(rawurldecode($url)) : ''),
            ];
        }
    }

    // Contest entries.
    $cents = $wpdb->get_results($wpdb->prepare(
        "SELECT ce.entered_at, ce.entries, c.title FROM {$wpdb->prefix}lmeg_contest_entries ce
         LEFT JOIN {$wpdb->prefix}lmeg_contests c ON c.id = ce.contest_id
         WHERE ce.subscriber_id = %d ORDER BY ce.entered_at DESC LIMIT 20", $sid
    ));
    foreach ((array) $cents as $r) {
        $items[] = [
            'at'    => $r->entered_at,
            'icon'  => '🏆',
            'label' => 'Entered contest "' . ($r->title ?: 'contest') . '"' . ((int) $r->entries > 1 ? ' (' . (int) $r->entries . ' entries)' : ''),
        ];
    }

    // Survey votes (with the option they picked).
    $votes = $wpdb->get_results($wpdb->prepare(
        "SELECT v.created_at, v.option_idx, s.question, s.options_json FROM {$wpdb->prefix}lmeg_survey_votes v
         LEFT JOIN {$wpdb->prefix}lmeg_surveys s ON s.id = v.survey_id
         WHERE v.subscriber_id = %d ORDER BY v.created_at DESC LIMIT 20", $sid
    ));
    foreach ((array) $votes as $r) {
        $opts   = json_decode((string) $r->options_json, true) ?: [];
        $choice = isset($opts[(int) $r->option_idx]) ? (string) $opts[(int) $r->option_idx] : '';
        $items[] = [
            'at'    => $r->created_at,
            'icon'  => '🗳',
            'label' => 'Voted in "' . ($r->question ?: 'survey') . '"' . ($choice !== '' ? ' — chose "' . $choice . '"' : ''),
        ];
    }

    // Abandoned carts (and recoveries).
    $carts = $wpdb->get_results($wpdb->prepare(
        "SELECT checkout_at, synced_at, total_cents, currency, recovered FROM {$wpdb->prefix}lmeg_shop_abandoned
         WHERE subscriber_id = %d ORDER BY synced_at DESC LIMIT 10", $sid
    ));
    foreach ((array) $carts as $r) {
        $items[] = [
            'at'    => $r->checkout_at ?: $r->synced_at,
            'icon'  => $r->recovered ? '💰' : '🛒',
            'label' => ($r->recovered ? 'Recovered an abandoned cart — ' : 'Left a cart behind — ')
                     . lmeg_format_price((int) $r->total_cents, $r->currency),
        ];
    }

    // Shop orders.
    $ords = $wpdb->get_results($wpdb->prepare(
        "SELECT ordered_at, order_number, total_cents, currency FROM $orders
         WHERE subscriber_id = %d ORDER BY ordered_at DESC LIMIT 30", $sid
    ));
    foreach ((array) $ords as $r) {
        $items[] = [
            'at'    => $r->ordered_at,
            'icon'  => '🛒',
            'label' => 'Placed order #' . $r->order_number . ' — ' . lmeg_format_price((int) $r->total_cents, $r->currency),
        ];
    }

    // Soft-paywall grants.
    $sgs = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, granted_at FROM $grants WHERE subscriber_id = %d ORDER BY granted_at DESC LIMIT 20", $sid
    ));
    foreach ((array) $sgs as $r) {
        $items[] = [
            'at'    => $r->granted_at,
            'icon'  => '🔓',
            'label' => 'Read "' . get_the_title($r->post_id) . '" free (soft paywall)',
        ];
    }

    // Instagram DMs + comments (captured/linked conversations).
    $igs = $wpdb->get_results($wpdb->prepare(
        "SELECT direction, source, text, created_at FROM {$wpdb->prefix}lmeg_ig_messages
         WHERE subscriber_id = %d ORDER BY created_at DESC LIMIT 30", $sid
    ));
    foreach ((array) $igs as $r) {
        $verb    = $r->direction === 'in'
            ? ($r->source === 'comment' ? 'Commented on Instagram' : 'DM\'d on Instagram')
            : 'Auto-replied on Instagram';
        $snippet = mb_substr((string) $r->text, 0, 120);
        $items[] = [
            'at'    => $r->created_at,
            'icon'  => $r->direction === 'in' ? '📸' : '↩️',
            'label' => $verb . ($snippet !== '' ? ': "' . $snippet . '"' : ''),
        ];
    }

    // On-site journey — classified outbound handoffs (streams, tickets, merch).
    if (function_exists('lmeg_journey_category_icon')) {
        $jtbl = $wpdb->prefix . 'lmeg_journey_events';
        $dsp  = function_exists('lmeg_journey_dsp_categories') ? lmeg_journey_dsp_categories() : [];
        $jevs = $wpdb->get_results($wpdb->prepare(
            "SELECT created_at, category, url FROM $jtbl
             WHERE subscriber_id = %d AND event_type = 'outbound'
             ORDER BY created_at DESC LIMIT 40", $sid
        ));
        foreach ((array) $jevs as $r) {
            $host = strtolower((string) wp_parse_url((string) $r->url, PHP_URL_HOST));
            $host = preg_replace('/^www\./', '', $host);
            $cat  = (string) $r->category;
            if (in_array($cat, $dsp, true) || $cat === 'DSP Button') {
                $label = 'Streamed — ' . ($cat === 'DSP Button' ? ($host ?: 'a streaming link') : $cat);
            } elseif ($cat === 'Tickets') {
                $label = 'Clicked a ticket link' . ($host ? ' — ' . $host : '');
            } elseif ($cat === 'Trailer / Video') {
                $label = 'Watched a video' . ($host ? ' — ' . $host : '');
            } else {
                $label = 'Clicked out to ' . ($host ?: 'an external link');
            }
            $items[] = ['at' => $r->created_at, 'icon' => lmeg_journey_category_icon($cat), 'label' => $label];
        }
    }

    usort($items, function ($a, $b) { return strcmp($b['at'] ?? '', $a['at'] ?? ''); });
    return array_slice($items, 0, $limit);
}

/**
 * Lifetime revenue for one fan.
 */
function lmeg_fan_revenue($subscriber_id) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(total_cents), 0) FROM {$wpdb->prefix}lmeg_shop_orders WHERE subscriber_id = %d",
        (int) $subscriber_id
    ));
}

/** Subscription revenue accumulated from Stripe invoice.payment_succeeded. */
function lmeg_fan_membership_revenue($subscriber_id) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(member_revenue_cents, 0) FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d",
        (int) $subscriber_id
    ));
}

/**
 * True lifetime value: attributed shop orders + subscription payments.
 * Returns cents. Use lmeg_fan_ltv_breakdown() for the split.
 */
function lmeg_fan_ltv($subscriber_id) {
    $b = lmeg_fan_ltv_breakdown($subscriber_id);
    return $b['total'];
}

function lmeg_fan_ltv_breakdown($subscriber_id) {
    $shop = lmeg_fan_revenue($subscriber_id);
    $memb = lmeg_fan_membership_revenue($subscriber_id);
    return ['shop' => $shop, 'membership' => $memb, 'total' => $shop + $memb];
}

/** Opens / clicks counts for a fan (all-time), for the profile engagement card. */
function lmeg_fan_engagement($subscriber_id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT SUM(event_type = 'open') AS opens, SUM(event_type = 'click') AS clicks
         FROM {$wpdb->prefix}lmeg_broadcast_events WHERE subscriber_id = %d",
        (int) $subscriber_id
    ));
    return ['opens' => (int) ($row->opens ?? 0), 'clicks' => (int) ($row->clicks ?? 0)];
}

/* ---------------------------------------------------------------------------
 * Top Fans — a leaderboard of your biggest supporters, ranked by revenue +
 * engagement. The owned-data answer to Laylo's "Notable Fans": instead of
 * chasing social follower counts, surface who actually shows up for you.
 * One efficient ranked query (aggregated subqueries) so it scales.
 * ------------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_submenu_page('lmeg', 'Top Fans', 'Top Fans', 'manage_options', 'lmeg-top-fans', 'lmeg_admin_top_fans');
}, 21);

function lmeg_admin_top_fans() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $subs   = $wpdb->prefix . LMEG_TABLE;
    $orders = $wpdb->prefix . 'lmeg_shop_orders';
    $events = $wpdb->prefix . 'lmeg_broadcast_events';
    $notice = '';

    // Composite supporter score: revenue $ ×5 + clicks ×3 + opens + visits, and
    // a boost for paying members. No user input in the SQL — static + safe.
    $score = "(COALESCE(o.rev,0)/100*5 + COALESCE(e.clicks,0)*3 + COALESCE(e.opens,0) + COALESCE(e.visits,0) + IF(s.member_status='active',50,0))";
    $rows = $wpdb->get_results(
        "SELECT s.id, s.email, s.phone, s.first_name, s.member_status, s.created_at,
                COALESCE(o.rev,0) rev_cents, COALESCE(o.orders,0) orders,
                COALESCE(e.opens,0) opens, COALESCE(e.clicks,0) clicks, COALESCE(e.visits,0) visits,
                $score AS score
         FROM $subs s
         LEFT JOIN (SELECT subscriber_id, SUM(total_cents) rev, COUNT(*) orders FROM $orders GROUP BY subscriber_id) o ON o.subscriber_id = s.id
         LEFT JOIN (SELECT subscriber_id,
                       SUM(event_type='open') opens,
                       SUM(event_type='click') clicks,
                       SUM(event_type='pageview') visits
                    FROM $events GROUP BY subscriber_id) e ON e.subscriber_id = s.id
         WHERE s.unsubscribed_at IS NULL
         ORDER BY score DESC, rev_cents DESC
         LIMIT 100"
    );

    // One-click: tag the top N as VIP for targeting.
    if (isset($_POST['lmeg_topfans_nonce']) && wp_verify_nonce($_POST['lmeg_topfans_nonce'], 'lmeg_topfans')) {
        $n = max(1, min(100, (int) ($_POST['vip_n'] ?? 25)));
        $tag = function_exists('lmeg_get_or_create_tag') ? lmeg_get_or_create_tag('vip', 'VIP', false, '#f59e0b') : null;
        $tagged = 0;
        if ($tag) {
            foreach (array_slice($rows, 0, $n) as $r) { lmeg_attach_tag((int) $r->id, $tag->id); $tagged++; }
        }
        $notice = '<div class="notice notice-success"><p>Tagged your top <strong>' . (int) $tagged . '</strong> fans as <strong>VIP</strong> — now target them in Compose or a Sequence.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Fanloop — Top Fans</h1>
        <?php echo $notice; ?>
        <p style="max-width:760px;">Your biggest supporters, ranked by a blend of <strong>revenue</strong>, <strong>clicks &amp; opens</strong>, on-site visits, and membership. Give them early access, comp tickets, or a shout-out.</p>

        <form method="post" style="margin:12px 0;">
            <?php wp_nonce_field('lmeg_topfans', 'lmeg_topfans_nonce'); ?>
            Tag the top <input type="number" name="vip_n" value="25" min="1" max="100" class="small-text" /> as
            <button type="submit" class="button button-primary">🏷 VIP</button>
            <span class="description" style="margin-left:6px;">Adds a <code>vip</code> tag you can target.</span>
        </form>

        <table class="widefat striped" style="max-width:920px;">
            <thead><tr><th>#</th><th>Fan</th><th>Score</th><th>Revenue</th><th>Clicks</th><th>Opens</th><th>Member</th><th>Joined</th></tr></thead>
            <tbody>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="8">No fans yet — this fills in as opens, clicks, and orders come in.</td></tr>
            <?php else : $i = 0; foreach ($rows as $r) : $i++; ?>
                <tr>
                    <td><?php echo $i; ?></td>
                    <td><a href="<?php echo esc_url(add_query_arg(['page' => 'lmeg', 'fan' => (int) $r->id], admin_url('admin.php'))); ?>"><?php echo esc_html($r->first_name ?: $r->email ?: $r->phone ?: ('Fan #' . $r->id)); ?></a></td>
                    <td><strong><?php echo (int) round($r->score); ?></strong></td>
                    <td><?php echo (int) $r->rev_cents ? esc_html(lmeg_format_price((int) $r->rev_cents)) : '—'; ?></td>
                    <td><?php echo (int) $r->clicks; ?></td>
                    <td><?php echo (int) $r->opens; ?></td>
                    <td><?php echo $r->member_status === 'active' ? '⭐ paying' : '—'; ?></td>
                    <td><?php echo esc_html(mb_substr((string) $r->created_at, 0, 10)); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Fanbase — lifecycle groups. Cobrand's "Fanbase" shows named fan groups
 * (Superfans, at-risk/"faded", new, members) each with a size + share. Ours is
 * the owned-data version: every group is derived from the engagement + orders
 * the plugin already records (opens/clicks/visits + revenue + membership), so
 * it needs no new data. Any group can be snapshotted into a targetable tag in
 * one click (same pattern as Top Fans → VIP), so it drops straight into Compose.
 * ------------------------------------------------------------------------- */

/** Group definitions: key => [label, desc, color, tag] (tag = slug to snapshot into). */
function lmeg_fanbase_defs() {
    return [
        'active'    => ['label' => 'Active',            'desc' => 'opened, clicked or visited in the last 30 days', 'color' => '#34D399', 'tag' => 'active-30d'],
        'new'       => ['label' => 'New fans',          'desc' => 'joined in the last 30 days',                     'color' => '#7C6CF6', 'tag' => 'new-30d'],
        'superfans' => ['label' => 'Superfans',         'desc' => 'top supporters — revenue, clicks & opens',       'color' => '#F59E0B', 'tag' => 'superfan'],
        'members'   => ['label' => 'Paying members',    'desc' => 'an active membership',                           'color' => '#E58BBD', 'tag' => 'member'],
        'atrisk'    => ['label' => 'Going quiet',       'desc' => 'were engaged, but nothing in 60+ days',          'color' => '#F87171', 'tag' => 'at-risk'],
        'dormant'   => ['label' => 'Never engaged',     'desc' => 'on your list but no opens/clicks/visits yet',    'color' => '#8B90A0', 'tag' => ''],
    ];
}

/** The per-fan classification sub-select (engagement + orders + score). Static SQL. */
function lmeg_fanbase_inner() {
    global $wpdb;
    $subs   = $wpdb->prefix . LMEG_TABLE;
    $orders = $wpdb->prefix . 'lmeg_shop_orders';
    $events = $wpdb->prefix . 'lmeg_broadcast_events';
    $score  = "(COALESCE(o.rev,0)/100*5 + COALESCE(e.clicks,0)*3 + COALESCE(e.opens,0) + COALESCE(e.visits,0) + IF(s.member_status='active',50,0))";
    $last   = "GREATEST(COALESCE(e.last_evt,'1970-01-01 00:00:00'), COALESCE(o.last_order,'1970-01-01 00:00:00'))";
    return "SELECT s.id, s.member_status, s.created_at,
                   COALESCE(e.evt_total,0) evt_total, $last AS last_touch, $score AS score
            FROM $subs s
            LEFT JOIN (SELECT subscriber_id,
                          SUM(event_type='open') opens, SUM(event_type='click') clicks,
                          SUM(event_type='pageview') visits, COUNT(*) evt_total, MAX(created_at) last_evt
                       FROM $events GROUP BY subscriber_id) e ON e.subscriber_id = s.id
            LEFT JOIN (SELECT subscriber_id, SUM(total_cents) rev, MAX(ordered_at) last_order
                       FROM $orders GROUP BY subscriber_id) o ON o.subscriber_id = s.id
            WHERE s.unsubscribed_at IS NULL";
}

/** Group key => WHERE predicate on the classified sub-select alias `t`. Static SQL. */
function lmeg_fanbase_preds() {
    return [
        'active'    => "t.last_touch >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        'new'       => "t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        'superfans' => "t.score >= 60 AND t.last_touch >= DATE_SUB(NOW(), INTERVAL 60 DAY)",
        'members'   => "t.member_status = 'active'",
        'atrisk'    => "t.evt_total >= 3 AND t.last_touch < DATE_SUB(NOW(), INTERVAL 60 DAY) AND t.created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)",
        'dormant'   => "t.evt_total = 0 AND t.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
    ];
}

/** Counts for every group + the total list, in one pass. */
function lmeg_fanbase_counts() {
    global $wpdb;
    $inner = lmeg_fanbase_inner();
    $sums  = [];
    foreach (lmeg_fanbase_preds() as $k => $p) $sums[] = "SUM(CASE WHEN $p THEN 1 ELSE 0 END) `$k`";
    $row = $wpdb->get_row("SELECT COUNT(*) total, " . implode(', ', $sums) . " FROM ($inner) t", ARRAY_A);
    return is_array($row) ? array_map('intval', $row) : ['total' => 0];
}

/** Subscriber ids in a group (for one-click tagging). */
function lmeg_fanbase_ids($key, $limit = 5000) {
    global $wpdb;
    $preds = lmeg_fanbase_preds();
    if (empty($preds[$key])) return [];
    $inner = lmeg_fanbase_inner();
    return array_map('intval', (array) $wpdb->get_col(
        "SELECT id FROM ($inner) t WHERE {$preds[$key]} ORDER BY score DESC LIMIT " . (int) $limit
    ));
}

add_action('admin_menu', function () {
    add_submenu_page('lmeg', 'Fanbase', 'Fanbase', 'manage_options', 'lmeg-fanbase', 'lmeg_admin_fanbase');
}, 20);

function lmeg_admin_fanbase() {
    if (!current_user_can('manage_options')) return;
    $defs   = lmeg_fanbase_defs();
    $notice = '';

    // One-click: snapshot a group into a targetable tag.
    if (isset($_POST['lmeg_fanbase_nonce']) && wp_verify_nonce($_POST['lmeg_fanbase_nonce'], 'lmeg_fanbase')) {
        $key = sanitize_key($_POST['group'] ?? '');
        if (isset($defs[$key]) && $defs[$key]['tag'] && function_exists('lmeg_get_or_create_tag')) {
            $ids = lmeg_fanbase_ids($key);
            $tag = lmeg_get_or_create_tag($defs[$key]['tag'], $defs[$key]['label'], false, $defs[$key]['color']);
            $n = 0;
            if ($tag) foreach ($ids as $id) { lmeg_attach_tag((int) $id, $tag->id); $n++; }
            $notice = '<div class="notice notice-success is-dismissible"><p>Tagged <strong>' . (int) $n . '</strong> fans as <code>' . esc_html($defs[$key]['tag']) . '</code> — target them now in <a href="' . esc_url(admin_url('admin.php?page=lmeg-compose')) . '">Compose</a>.</p></div>';
        }
    }

    $c     = lmeg_fanbase_counts();
    $total = max(1, (int) ($c['total'] ?? 0));
    $card  = 'background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px 18px;color:#F4F5F7;';
    ?>
    <div class="wrap lmeg-admin">
        <h1>Fanloop — Fanbase</h1>
        <?php echo $notice; ?>
        <p style="max-width:820px;">Your fans grouped by where they are in their lifecycle — all from your own opens, clicks, visits, orders and memberships. Snapshot any group into a tag to target it in <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-compose')); ?>">Compose</a>, or dig deeper in <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-top-fans')); ?>">Top Fans</a> and <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-segments')); ?>">Segments</a>.</p>

        <div style="<?php echo $card; ?>max-width:1040px;margin:12px 0 16px;display:flex;align-items:baseline;gap:12px;">
            <span style="font:800 30px/1 var(--lmegA-font,inherit);"><?php echo number_format_i18n((int) $c['total']); ?></span>
            <span style="color:#8B90A0;">fans on your list — <strong style="color:#F4F5F7;">yours to reach anytime</strong>, no algorithm in the way.</span>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;max-width:1040px;">
            <?php foreach ($defs as $key => $d) :
                $n   = (int) ($c[$key] ?? 0);
                $pct = round(100 * $n / $total);
            ?>
                <div style="<?php echo $card; ?>">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="width:9px;height:9px;border-radius:50%;background:<?php echo esc_attr($d['color']); ?>;flex:0 0 9px;"></span>
                        <strong style="font-size:14px;"><?php echo esc_html($d['label']); ?></strong>
                    </div>
                    <div style="font:800 28px/1.1 var(--lmegA-font,inherit);margin:8px 0 2px;font-variant-numeric:tabular-nums;"><?php echo number_format_i18n($n); ?></div>
                    <div style="font-size:12px;color:#8B90A0;margin-bottom:8px;"><?php echo esc_html($d['desc']); ?></div>
                    <div style="height:7px;background:rgba(255,255,255,.07);border-radius:5px;overflow:hidden;margin-bottom:10px;"><span style="display:block;height:100%;width:<?php echo max(2, (int) $pct); ?>%;background:<?php echo esc_attr($d['color']); ?>;border-radius:5px;"></span></div>
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <span style="font-size:11.5px;color:#8B90A0;"><?php echo (int) $pct; ?>% of your list</span>
                        <?php if ($d['tag'] && $n > 0) : ?>
                        <form method="post" style="margin:0;">
                            <?php wp_nonce_field('lmeg_fanbase', 'lmeg_fanbase_nonce'); ?>
                            <input type="hidden" name="group" value="<?php echo esc_attr($key); ?>" />
                            <button type="submit" class="button button-small">🏷 Tag <code style="background:none;"><?php echo esc_html($d['tag']); ?></code></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="description" style="max-width:820px;margin-top:14px;"><strong>Going quiet</strong> is your win-back list — fans who used to open and click but have drifted; a "we miss you" message or a members-only drop pulls them back before they're gone for good.</p>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Signups — the acquisition funnel. Cobrand's "Fan Sign Up Analytics": how
 * many joined, the email/SMS split, where they're from, who referred them,
 * and (when the Journey is on) a page-views → signups conversion rate + the
 * UTM campaigns driving the traffic. All from data the plugin already stores.
 * ------------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_submenu_page('lmeg', 'Signups', 'Signups', 'manage_options', 'lmeg-signups', 'lmeg_admin_signups');
}, 20);

function lmeg_admin_signups() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $subs    = $wpdb->prefix . LMEG_TABLE;
    $journey = $wpdb->prefix . 'lmeg_journey_events';

    $win  = isset($_GET['win']) ? (string) $_GET['win'] : '30';
    $days = $win === 'all' ? 0 : (in_array($win, ['7', '30', '90'], true) ? (int) $win : 30);
    $since_sql = $days ? "AND created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)" : '';

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE unsubscribed_at IS NULL $since_sql");
    $email = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE unsubscribed_at IS NULL AND contact_type='email' $since_sql");
    $sms   = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE unsubscribed_at IS NULL AND contact_type='phone' $since_sql");

    // Daily signups for the trend (default 30, capped for 'all').
    $tdays = $days ?: 90;
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(created_at) d, COUNT(*) n FROM $subs
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY) GROUP BY DATE(created_at)", $tdays));
    $by = []; foreach ($rows as $r) $by[$r->d] = (int) $r->n;
    $series = []; $labels = [];
    for ($i = $tdays - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $series[] = $by[$d] ?? 0; $labels[] = date_i18n('M j', strtotime($d)); }

    $countries = $wpdb->get_results("SELECT country cc, COUNT(*) n FROM $subs WHERE unsubscribed_at IS NULL AND country IS NOT NULL AND country<>'' $since_sql GROUP BY country ORDER BY n DESC LIMIT 8");
    $refs = $wpdb->get_results("SELECT referrer, COUNT(*) n FROM $subs WHERE unsubscribed_at IS NULL $since_sql GROUP BY referrer ORDER BY n DESC");

    // Journey-based: unique visitors + top UTM campaigns → conversion rate.
    $pv_since = $days ? "AND created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)" : '';
    $visitors = (int) $wpdb->get_var("SELECT COUNT(DISTINCT COALESCE(NULLIF(anon_id,''), CONCAT('s', subscriber_id))) FROM $journey WHERE event_type='pageview' $pv_since");
    $utm = $wpdb->get_results("SELECT utm_campaign, COUNT(*) n FROM $journey WHERE event_type='pageview' AND utm_campaign<>'' $pv_since GROUP BY utm_campaign ORDER BY n DESC LIMIT 6");
    $conv = $visitors > 0 ? round(100 * $total / $visitors, 1) : null;

    // Aggregate referrer hosts.
    $ref_hosts = [];
    foreach ($refs as $r) {
        $raw = trim((string) $r->referrer);
        $host = $raw === '' ? 'Direct / unknown' : (parse_url($raw, PHP_URL_HOST) ?: 'Direct / unknown');
        $host = preg_replace('/^www\./', '', $host);
        $ref_hosts[$host] = ($ref_hosts[$host] ?? 0) + (int) $r->n;
    }
    arsort($ref_hosts); $ref_hosts = array_slice($ref_hosts, 0, 8, true);

    $card = 'background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px 18px;color:#F4F5F7;';
    $lbl  = 'font:600 11px/1 var(--lmegA-font,inherit);letter-spacing:.06em;text-transform:uppercase;color:#8B90A0;';
    $bars = function ($items, $fmt) use ($card) {
        $max = 0; foreach ($items as $v) $max = max($max, (int) $v);
        $tot = array_sum($items); $tot = $tot ?: 1; $h = '';
        foreach ($items as $k => $v) {
            $w = $max ? max(3, round(100 * $v / $max)) : 0; $pct = round(100 * $v / $tot);
            $h .= '<div style="display:flex;align-items:center;gap:9px;margin:7px 0;">'
                . '<span style="flex:0 0 130px;font-size:13px;color:#F4F5F7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' . $fmt($k) . '</span>'
                . '<span style="flex:1;height:8px;background:rgba(255,255,255,.07);border-radius:5px;overflow:hidden;"><span style="display:block;height:100%;width:' . $w . '%;background:linear-gradient(90deg,#7C6CF6,#D05FA2);border-radius:5px;"></span></span>'
                . '<span style="flex:0 0 auto;font-size:12.5px;color:#F4F5F7;font-variant-numeric:tabular-nums;">' . number_format_i18n((int) $v) . '</span>'
                . '<span style="flex:0 0 40px;text-align:right;font-size:11.5px;color:#8B90A0;">' . $pct . '%</span></div>';
        }
        return $h;
    };
    $wins = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', 'all' => 'All time'];
    ?>
    <div class="wrap lmeg-admin">
        <h1>Fanloop — Signups</h1>
        <p style="max-width:820px;">Where your fans come from — volume, channel, geography, and (with the <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-journey')); ?>">Journey</a> on) how many visitors convert into signups.</p>
        <p style="margin:8px 0 14px;">
            <?php foreach ($wins as $k => $label) : $active = ($win === $k); ?>
                <a href="<?php echo esc_url(add_query_arg('win', $k)); ?>" class="button<?php echo $active ? ' button-primary' : ''; ?>" style="margin-right:4px;"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </p>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;max-width:1000px;margin-bottom:14px;">
            <div style="<?php echo $card; ?>"><div style="font:800 28px/1 var(--lmegA-font,inherit);font-variant-numeric:tabular-nums;"><?php echo number_format_i18n($total); ?></div><div style="<?php echo $lbl; ?>margin-top:6px;">Signups</div></div>
            <div style="<?php echo $card; ?>"><div style="font:800 28px/1 var(--lmegA-font,inherit);font-variant-numeric:tabular-nums;"><?php echo number_format_i18n($email); ?></div><div style="<?php echo $lbl; ?>margin-top:6px;">By email</div></div>
            <div style="<?php echo $card; ?>"><div style="font:800 28px/1 var(--lmegA-font,inherit);font-variant-numeric:tabular-nums;"><?php echo number_format_i18n($sms); ?></div><div style="<?php echo $lbl; ?>margin-top:6px;">By SMS</div></div>
            <div style="<?php echo $card; ?>"><div style="font:800 28px/1 var(--lmegA-font,inherit);color:<?php echo $conv !== null ? '#34D399' : '#8B90A0'; ?>;"><?php echo $conv !== null ? $conv . '%' : '—'; ?></div><div style="<?php echo $lbl; ?>margin-top:6px;">Conversion<?php echo $conv !== null ? ' · ' . number_format_i18n($visitors) . ' visitors' : ''; ?></div></div>
        </div>

        <div style="<?php echo $card; ?>max-width:1000px;margin-bottom:14px;">
            <div style="<?php echo $lbl; ?>margin-bottom:6px;">Signups · last <?php echo (int) $tdays; ?> days</div>
            <?php echo function_exists('lmeg_chart_line') ? lmeg_chart_line($series, ['color' => '#7C6CF6', 'uid' => 'signups-trend', 'h' => 66, 'labels' => $labels, 'suffix' => ' signups']) : ''; ?>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;max-width:1000px;">
            <div style="<?php echo $card; ?>">
                <div style="<?php echo $lbl; ?>margin-bottom:10px;">Where signups come from</div>
                <?php echo $ref_hosts ? $bars($ref_hosts, function ($h) { return esc_html($h); }) : '<p style="color:#8B90A0;font-size:13px;margin:0;">No referrer data yet.</p>'; ?>
            </div>
            <div style="<?php echo $card; ?>">
                <div style="<?php echo $lbl; ?>margin-bottom:10px;">Top countries</div>
                <?php
                $cc = []; foreach ($countries as $r) $cc[strtoupper((string) $r->cc)] = (int) $r->n;
                echo $cc ? $bars($cc, function ($k) { $n = (function_exists('lmeg_country_by_iso') && ($r = lmeg_country_by_iso($k))) ? $r[1] : $k; return esc_html(trim((function_exists('lmeg_flag_emoji') ? lmeg_flag_emoji($k) . ' ' : '') . $n)); }) : '<p style="color:#8B90A0;font-size:13px;margin:0;">No country data yet.</p>';
                ?>
            </div>
            <div style="<?php echo $card; ?>">
                <div style="<?php echo $lbl; ?>margin-bottom:10px;">Top campaigns (traffic)</div>
                <?php
                $uc = []; foreach ($utm as $r) $uc[(string) $r->utm_campaign] = (int) $r->n;
                echo $uc ? $bars($uc, function ($k) { return esc_html($k); }) : '<p style="color:#8B90A0;font-size:13px;margin:0;">No campaign traffic yet — add <code>?utm_campaign=</code> tags to your links (needs the Journey on).</p>';
                ?>
            </div>
        </div>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Referrals — attribute revenue to the fans who bring in other fans. The
 * owned-data take on Laylo's Affiliates: who's your street team, and how much
 * have the fans they referred actually spent?
 * ------------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_submenu_page('lmeg', 'Referrals', 'Referrals', 'manage_options', 'lmeg-referrals', 'lmeg_admin_referrals');
}, 22);

function lmeg_admin_referrals() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $subs   = $wpdb->prefix . LMEG_TABLE;
    $orders = $wpdb->prefix . 'lmeg_shop_orders';

    $total_referred = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE referred_by IS NOT NULL");
    $total_refs     = (int) $wpdb->get_var("SELECT COUNT(DISTINCT referred_by) FROM $subs WHERE referred_by IS NOT NULL");
    $referred_rev   = (int) $wpdb->get_var(
        "SELECT COALESCE(SUM(o.total_cents),0) FROM $orders o JOIN $subs f ON f.id = o.subscriber_id WHERE f.referred_by IS NOT NULL"
    );

    $rows = $wpdb->get_results(
        "SELECT ref.id, ref.email, ref.first_name, ref.phone, ref.referral_code,
                COUNT(DISTINCT fan.id) AS referred_count,
                COUNT(DISTINCT o.subscriber_id) AS buyers,
                COALESCE(SUM(o.total_cents),0) AS rev_cents
         FROM $subs ref
         JOIN $subs fan ON fan.referred_by = ref.id
         LEFT JOIN $orders o ON o.subscriber_id = fan.id
         GROUP BY ref.id
         ORDER BY rev_cents DESC, referred_count DESC
         LIMIT 100"
    );
    ?>
    <div class="wrap">
        <h1>Fanloop — Referrals</h1>
        <p style="max-width:820px;">Who's bringing in new fans — and how much those referred fans have spent. Every fan has a referral link (the <code>{referral_link}</code> merge tag / their profile); this ranks your best advocates so you can reward them.</p>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;max-width:720px;margin:16px 0;">
            <div class="lmeg-stat"><div class="lmeg-stat__label">Referred fans</div><div class="lmeg-stat__value"><?php echo number_format_i18n($total_referred); ?></div></div>
            <div class="lmeg-stat"><div class="lmeg-stat__label">Referrers</div><div class="lmeg-stat__value"><?php echo number_format_i18n($total_refs); ?></div></div>
            <div class="lmeg-stat"><div class="lmeg-stat__label">Referred-fan revenue</div><div class="lmeg-stat__value"><?php echo esc_html(lmeg_format_price($referred_rev)); ?></div></div>
        </div>

        <h2>Top referrers</h2>
        <table class="widefat striped" style="max-width:900px;">
            <thead><tr><th>#</th><th>Fan</th><th>Code</th><th>Referred</th><th>Buyers</th><th>Attributed revenue</th></tr></thead>
            <tbody>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="6">No referrals yet. Share the <code>{referral_link}</code> merge tag in a broadcast (or the Step-5 "put me on" email) and this fills in as fans bring friends.</td></tr>
            <?php else : $i = 0; foreach ($rows as $r) : $i++; ?>
                <tr>
                    <td><?php echo $i; ?></td>
                    <td><a href="<?php echo esc_url(add_query_arg(['page' => 'lmeg', 'fan' => (int) $r->id], admin_url('admin.php'))); ?>"><?php echo esc_html($r->first_name ?: $r->email ?: $r->phone ?: ('Fan #' . $r->id)); ?></a></td>
                    <td><?php echo $r->referral_code ? '<code>' . esc_html($r->referral_code) . '</code>' : '—'; ?></td>
                    <td><strong><?php echo (int) $r->referred_count; ?></strong></td>
                    <td><?php echo (int) $r->buyers; ?></td>
                    <td><?php echo (int) $r->rev_cents ? '<strong>' . esc_html(lmeg_format_price((int) $r->rev_cents)) . '</strong>' : '—'; ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <p class="description" style="max-width:820px;margin-top:12px;">Payouts are manual for now — reward your top referrers with a discount code, free merch, or guest-list spots.</p>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Get Started — a setup checklist so a new (white-labeled) artist can see what
 * to connect next. Read-only; each item links to where to set it up, and it
 * surfaces the connect-Instagram/Spotify steps that unlock the newest features.
 * ------------------------------------------------------------------------- */

// NOTE: the Get Started submenu is registered in lmeg_admin_menu() (admin.php),
// alongside every other Fanloop page, so it's added AFTER the parent 'lmeg'
// menu exists. Registering it here on a separate priority-1 admin_menu hook
// ran before the parent and left the page unroutable on some WP setups (blank).
function lmeg_admin_setup() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $s    = lmeg_get_settings();
    $subs = $wpdb->prefix . LMEG_TABLE;
    $set  = admin_url('admin.php?page=lmeg-settings');

    $sub_count  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs");
    $bcast_sent = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lmeg_broadcasts WHERE status = 'completed'");
    $seq_active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lmeg_sequences WHERE is_active = 1");
    $drop_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lmeg_drops");

    // [label, done?, link, hint, optional?]
    $items = [
        ['Name your artist + community', (function_exists('lmeg_brand_raw') && lmeg_brand_raw('artist_name', '') !== ''), $set, 'So emails, forms, and the AI speak as you (e.g. “LOONY” / “the LOONYBIN”).', false],
        ['Connect email (Brevo)', (!empty($s['brevo_api_key']) && !empty($s['brevo_from_email'])), $set, 'Your API key + a verified from-address. Required to send email.', false],
        ['Place a signup form', ($sub_count > 0), admin_url('admin.php?page=lmeg-shortcodes'), 'Drop the [fanloop_signup] shortcode on a page. ' . ($sub_count > 0 ? number_format_i18n($sub_count) . ' fans so far.' : 'No fans yet.'), false],
        ['Send your first broadcast', ($bcast_sent > 0), admin_url('admin.php?page=lmeg-compose'), 'Email or text your list — try ✨ Write with AI.', false],
        ['Turn on a welcome sequence', ($seq_active > 0), admin_url('admin.php?page=lmeg-sequences'), 'Automate a welcome journey for every new fan.', false],
        ['Connect Instagram', (function_exists('lmeg_ig_configured') && lmeg_ig_configured()), $set, 'Unlocks DM automation, comment-to-DM, and social listening + growth.', true],
        ['Connect your shop (Shopify)', ((function_exists('lmeg_shop_configured') && lmeg_shop_configured()) || get_option('lmeg_shop_wh_last', '')), $set, 'Attribute revenue to your fans + campaigns.', true],
        ['Connect Spotify', (function_exists('lmeg_spotify_configured') && lmeg_spotify_configured()), admin_url('admin.php?page=lmeg-spotify'), 'Follower growth + analytics.', true],
        ['Add SMS (Twilio)', (!empty($s['twilio_account_sid'])), $set, 'Text your fans, not just email.', true],
        ['Add your AI key', (function_exists('lmeg_ai_configured') && lmeg_ai_configured()), $set, 'Powers Ask AI, the ✨ voice composer, and comment sentiment.', true],
        ['Create a drop', ($drop_count > 0), admin_url('admin.php?page=lmeg-drops'), 'A release/announcement page with a countdown timer.', true],
    ];

    $core      = array_filter($items, function ($i) { return !$i[4]; });
    $core_done = count(array_filter($core, function ($i) { return $i[1]; }));
    $all_done  = count(array_filter($items, function ($i) { return $i[1]; }));
    $pct       = round(100 * $all_done / max(1, count($items)));
    ?>
    <div class="wrap">
        <h1>Fanloop — Get Started</h1>
        <p style="max-width:760px;">Everything you connect makes Fanloop do more. Core steps first; the rest unlock extra powers.</p>

        <div style="max-width:640px;margin:14px 0;">
            <div style="height:12px;background:rgba(255,255,255,.08);border-radius:999px;overflow:hidden;"><div style="height:100%;width:<?php echo (int) $pct; ?>%;background:linear-gradient(90deg,#d05fa2,#a855f7);"></div></div>
            <div style="font-size:13px;margin-top:6px;color:#8B90A0;"><strong><?php echo $all_done; ?>/<?php echo count($items); ?></strong> set up · core <strong><?php echo $core_done; ?>/<?php echo count($core); ?></strong></div>
        </div>

        <table class="widefat" style="max-width:820px;">
            <tbody>
            <?php foreach ($items as $it) : list($label, $done, $link, $hint, $optional) = $it; ?>
                <tr>
                    <td style="width:30px;font-size:18px;"><?php echo $done ? '✅' : '⬜'; ?></td>
                    <td>
                        <strong style="<?php echo $done ? 'color:#34D399;' : 'color:#F4F5F7;'; ?>"><?php echo esc_html($label); ?></strong>
                        <?php if ($optional) : ?><span style="font-size:11px;background:rgba(124,108,246,.18);color:#C4BBFF;border-radius:999px;padding:1px 7px;margin-left:6px;">optional</span><?php endif; ?>
                        <div style="font-size:12px;color:#8B90A0;margin-top:2px;"><?php echo esc_html($hint); ?></div>
                    </td>
                    <td style="width:110px;text-align:right;"><?php if (!$done) : ?><a class="button button-small" href="<?php echo esc_url($link); ?>">Set up →</a><?php else : ?><a href="<?php echo esc_url($link); ?>" style="font-size:12px;">Manage</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
