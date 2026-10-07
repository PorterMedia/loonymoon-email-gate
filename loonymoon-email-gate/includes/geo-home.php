<?php
/**
 * Where a fan actually lives, as opposed to where we happened to meet them.
 *
 * The signup IP is one data point taken on one day. A fan who joins the list
 * while travelling is recorded in the city they were visiting, and before this
 * module nothing ever corrected it: the city column is fill-only, so that fan
 * stayed a Berlin fan forever, got told about Berlin shows, and was missed for
 * the one down the road from their house.
 *
 * Meanwhile every open and click already stored the visitor's IP and nobody
 * read it. So the signal was being collected and thrown away.
 *
 * What this does:
 *   - keeps the signup city, but treats it as "where we met them"
 *   - tallies the places a fan engages FROM, coarsely (city/country + counts),
 *     resolving each IP once through the cached geocoder
 *   - derives a home city from the MODE of those places, not the latest one,
 *     because travel is a minority of anyone's year and last-seen-wins would
 *     relocate a fan for the week they're on holiday
 *   - weights clicks above page views above opens, since an open's IP is often
 *     Apple Mail Privacy Protection or Gmail's image proxy rather than the fan
 *   - ranks the evidence (what they told us > where they ship > repeated
 *     clicks > signup IP) and records WHICH basis was used, so the admin can
 *     see a fact and a guess as different things
 *
 * No raw IP history is kept here: an IP is resolved to a city, counted, and
 * only the city/country/date/count survive in lmeg_fan_geo.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('LMEG_HOME_DB_VERSION')) define('LMEG_HOME_DB_VERSION', '1');
if (!defined('LMEG_HOME_WINDOW_DAYS')) define('LMEG_HOME_WINDOW_DAYS', 180);

function lmeg_fan_geo_table()  { global $wpdb; return $wpdb->prefix . 'lmeg_fan_geo'; }
function lmeg_fan_home_table() { global $wpdb; return $wpdb->prefix . 'lmeg_fan_home'; }

add_action('init', 'lmeg_home_maybe_install', 1);
function lmeg_home_maybe_install() {
    if (get_option('lmeg_home_db_version') === LMEG_HOME_DB_VERSION) return;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $geo = lmeg_fan_geo_table();
    $home = lmeg_fan_home_table();
    dbDelta("CREATE TABLE $geo (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        subscriber_id BIGINT UNSIGNED NOT NULL,
        city VARCHAR(120) NOT NULL DEFAULT '',
        region VARCHAR(120) NOT NULL DEFAULT '',
        country CHAR(2) NOT NULL DEFAULT '',
        signal VARCHAR(10) NOT NULL DEFAULT 'click',
        hits INT UNSIGNED NOT NULL DEFAULT 0,
        days INT UNSIGNED NOT NULL DEFAULT 0,
        first_seen DATE NULL,
        last_seen DATE NULL,
        PRIMARY KEY (id),
        UNIQUE KEY place (subscriber_id, city, region, country, signal),
        KEY subscriber_id (subscriber_id)
    ) $charset;");
    dbDelta("CREATE TABLE $home (
        subscriber_id BIGINT UNSIGNED NOT NULL,
        city VARCHAR(120) NOT NULL DEFAULT '',
        region VARCHAR(120) NOT NULL DEFAULT '',
        country CHAR(2) NOT NULL DEFAULT '',
        basis VARCHAR(12) NOT NULL DEFAULT '',
        confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
        signals INT UNSIGNED NOT NULL DEFAULT 0,
        days INT UNSIGNED NOT NULL DEFAULT 0,
        explicit_city VARCHAR(120) NOT NULL DEFAULT '',
        explicit_region VARCHAR(120) NOT NULL DEFAULT '',
        explicit_country CHAR(2) NOT NULL DEFAULT '',
        explicit_basis VARCHAR(12) NOT NULL DEFAULT '',
        updated_at DATETIME NULL,
        PRIMARY KEY (subscriber_id),
        KEY city (city)
    ) $charset;");
    update_option('lmeg_home_db_version', LMEG_HOME_DB_VERSION);
}

/* ---------------------------------------------------------------------------
 * Pure logic.
 * ------------------------------------------------------------------------- */

/**
 * How much a signal is worth as evidence of where someone lives.
 * A click is a first-party redirect the fan actually chose. A page view is
 * nearly as good. An open can be a mail-privacy relay in another country, so
 * it counts, barely, and can never carry a home city on its own.
 */
function lmeg_home_signal_weight($signal) {
    switch ((string) $signal) {
        case 'click':    return 3;
        case 'pageview': return 2;
        case 'open':     return 1;
        default:         return 0;
    }
}

/** A public, routable IP we can meaningfully geolocate. Pure. */
function lmeg_home_ip_usable($ip) {
    $ip = trim((string) $ip);
    if ($ip === '') return false;
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/**
 * The home city implied by a fan's places. Pure.
 *
 * $places: rows of ['city','region','country','signal','hits','days','last_seen'].
 * Returns null unless one place wins on weighted volume AND was seen on at
 * least two separate days AND has at least one click or page view behind it.
 * Confidence is that winner's share of all weighted signal in the window.
 */
function lmeg_home_derive($places, $today = null, $window_days = LMEG_HOME_WINDOW_DAYS) {
    $today = $today ?: (function_exists('current_time') ? current_time('Y-m-d') : date('Y-m-d'));
    $cut   = date('Y-m-d', strtotime($today . ' -' . (int) $window_days . ' days'));
    $agg   = [];
    $total = 0;

    foreach ((array) $places as $p) {
        $city = trim((string) ($p['city'] ?? ''));
        if ($city === '') continue;
        $hits = max(0, (int) ($p['hits'] ?? 0));
        if (!$hits) continue;
        $last = substr((string) ($p['last_seen'] ?? ''), 0, 10);
        if ($last !== '' && $last < $cut) continue; // aged out of the window
        $w = lmeg_home_signal_weight($p['signal'] ?? 'click');
        if ($w < 1) continue;

        $region  = trim((string) ($p['region'] ?? ''));
        $country = strtoupper(substr((string) ($p['country'] ?? ''), 0, 2));
        $k = strtolower($city) . '|' . strtolower($region) . '|' . $country;
        if (!isset($agg[$k])) {
            $agg[$k] = ['city' => $city, 'region' => $region, 'country' => $country,
                        'score' => 0, 'hits' => 0, 'days' => 0, 'last_seen' => '', 'strong' => false];
        }
        $agg[$k]['score'] += $hits * $w;
        $agg[$k]['hits']  += $hits;
        $agg[$k]['days']   = max((int) $agg[$k]['days'], max(0, (int) ($p['days'] ?? 0)));
        if ($last > $agg[$k]['last_seen']) $agg[$k]['last_seen'] = $last;
        if ($w >= 2) $agg[$k]['strong'] = true;
        $total += $hits * $w;
    }
    if (!$agg || $total < 1) return null;

    $rows = array_values($agg);
    usort($rows, function ($a, $b) {
        if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
        if ($a['days']  !== $b['days'])  return $b['days']  <=> $a['days'];
        return strcmp((string) $b['last_seen'], (string) $a['last_seen']);
    });
    $win = $rows[0];
    // One visit doesn't make a home, and opens alone can't either.
    if ((int) $win['days'] < 2 || empty($win['strong'])) return null;

    return [
        'city' => $win['city'], 'region' => $win['region'], 'country' => $win['country'],
        'confidence' => (int) max(1, min(100, round($win['score'] / $total * 100))),
        'signals' => (int) $win['hits'], 'days' => (int) $win['days'], 'last_seen' => $win['last_seen'],
    ];
}

/**
 * Pick the best available place and say which basis it came from. Pure.
 * $c = ['explicit'=>place|null,'order'=>place|null,'derived'=>place|null,'signup'=>place|null]
 */
function lmeg_home_rank($c) {
    foreach (['explicit', 'order', 'derived', 'signup'] as $basis) {
        $p = $c[$basis] ?? null;
        if (!is_array($p) || trim((string) ($p['city'] ?? '')) === '') continue;
        $conf = ($basis === 'derived') ? (int) ($p['confidence'] ?? 0) : (($basis === 'signup') ? 25 : 100);
        return [
            'city' => (string) $p['city'], 'region' => (string) ($p['region'] ?? ''),
            'country' => strtoupper(substr((string) ($p['country'] ?? ''), 0, 2)),
            'basis' => $basis, 'confidence' => $conf,
            'signals' => (int) ($p['signals'] ?? 0), 'days' => (int) ($p['days'] ?? 0),
        ];
    }
    return null;
}

/** Plain-English provenance for a resolved place. Pure. */
function lmeg_home_basis_label($place) {
    $b = (string) ($place['basis'] ?? '');
    $n = (int) ($place['signals'] ?? 0);
    $d = (int) ($place['days'] ?? 0);
    switch ($b) {
        case 'explicit': return 'they told us';
        case 'order':    return 'from where they had an order shipped';
        case 'derived':  return $n . ' ' . ($n === 1 ? 'visit' : 'visits') . ' on ' . $d . ' separate ' . ($d === 1 ? 'day' : 'days');
        case 'signup':   return 'where they signed up — may just be where they were that day';
    }
    return '';
}

/** True when a place is solid enough to aim a local send at. Pure. */
function lmeg_home_is_confident($place, $min = 50) {
    if (!is_array($place) || empty($place['city'])) return false;
    return (int) ($place['confidence'] ?? 0) >= (int) $min;
}

/* ---------------------------------------------------------------------------
 * Storage.
 * ------------------------------------------------------------------------- */

/** Count one sighting of a fan in a place. Days only tick on a new date. */
function lmeg_home_note_place($sid, $place, $signal, $date) {
    global $wpdb;
    $sid = (int) $sid;
    $city = trim((string) ($place['city'] ?? ''));
    if (!$sid || $city === '') return false;
    $t = lmeg_fan_geo_table();
    $region  = trim((string) ($place['region'] ?? ''));
    $country = strtoupper(substr((string) ($place['country'] ?? ''), 0, 2));
    $date    = substr((string) $date, 0, 10) ?: current_time('Y-m-d');
    $signal  = in_array($signal, ['click', 'pageview', 'open'], true) ? $signal : 'click';

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, hits, days, first_seen, last_seen FROM $t
          WHERE subscriber_id = %d AND city = %s AND region = %s AND country = %s AND signal = %s",
        $sid, $city, $region, $country, $signal));
    if ($row) {
        $new_day = (substr((string) $row->last_seen, 0, 10) !== $date);
        $wpdb->update($t, [
            'hits'      => (int) $row->hits + 1,
            'days'      => (int) $row->days + ($new_day ? 1 : 0),
            'last_seen' => max((string) $row->last_seen, $date),
        ], ['id' => (int) $row->id]);
    } else {
        $wpdb->insert($t, ['subscriber_id' => $sid, 'city' => $city, 'region' => $region,
                           'country' => $country, 'signal' => $signal, 'hits' => 1, 'days' => 1,
                           'first_seen' => $date, 'last_seen' => $date]);
    }
    return true;
}

/** The places a fan has engaged from, newest first. For the admin and derive(). */
function lmeg_home_history($sid) {
    global $wpdb;
    $t = lmeg_fan_geo_table();
    if (!lmeg_home_has_table($t)) return [];
    return (array) $wpdb->get_results($wpdb->prepare(
        "SELECT city, region, country, signal, hits, days, first_seen, last_seen
           FROM $t WHERE subscriber_id = %d ORDER BY last_seen DESC, hits DESC", (int) $sid), ARRAY_A);
}

function lmeg_home_has_table($t) {
    global $wpdb;
    static $seen = [];
    if (isset($seen[$t])) return $seen[$t];
    return $seen[$t] = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t));
}

/**
 * Record a place the fan actually stated: a city typed into a signup form
 * ('explicit') or the city an order shipped to ('order'). These outrank
 * anything inferred and are never overwritten by inference.
 */
function lmeg_home_set_stated($sid, $city, $region = '', $country = '', $basis = 'explicit') {
    global $wpdb;
    $sid  = (int) $sid;
    $city = trim((string) $city);
    if (!$sid || $city === '') return false;
    if (!in_array($basis, ['explicit', 'order'], true)) $basis = 'explicit';
    lmeg_home_maybe_install();
    $t = lmeg_fan_home_table();
    $existing = $wpdb->get_row($wpdb->prepare("SELECT subscriber_id, explicit_basis FROM $t WHERE subscriber_id = %d", $sid));
    // What they typed beats what an order implies; don't downgrade.
    if ($existing && (string) $existing->explicit_basis === 'explicit' && $basis === 'order') return false;
    $vals = [
        'explicit_city'    => substr($city, 0, 120),
        'explicit_region'  => substr(trim((string) $region), 0, 120),
        'explicit_country' => strtoupper(substr((string) $country, 0, 2)),
        'explicit_basis'   => $basis,
        'updated_at'       => current_time('mysql'),
    ];
    if ($existing) $wpdb->update($t, $vals, ['subscriber_id' => $sid]);
    else           $wpdb->insert($t, array_merge($vals, ['subscriber_id' => $sid]));
    lmeg_home_recompute($sid);
    return true;
}

/** Re-rank a fan's location from everything known about them. */
function lmeg_home_recompute($sid) {
    global $wpdb;
    $sid = (int) $sid;
    if (!$sid) return null;
    lmeg_home_maybe_install();
    $subs = $wpdb->prefix . LMEG_TABLE;
    $sub  = $wpdb->get_row($wpdb->prepare("SELECT city, region, country FROM $subs WHERE id = %d", $sid));
    $t    = lmeg_fan_home_table();
    $row  = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE subscriber_id = %d", $sid));

    $stated = null;
    if ($row && trim((string) $row->explicit_city) !== '') {
        $stated = ['city' => $row->explicit_city, 'region' => $row->explicit_region, 'country' => $row->explicit_country];
    }
    $derived = lmeg_home_derive(lmeg_home_history($sid));
    $signup  = ($sub && trim((string) $sub->city) !== '')
        ? ['city' => $sub->city, 'region' => (string) $sub->region, 'country' => (string) $sub->country] : null;

    $best = lmeg_home_rank([
        'explicit' => ($row && (string) $row->explicit_basis === 'explicit') ? $stated : null,
        'order'    => ($row && (string) $row->explicit_basis === 'order')    ? $stated : null,
        'derived'  => $derived,
        'signup'   => $signup,
    ]);
    if (!$best) return null;

    $vals = [
        'city' => substr((string) $best['city'], 0, 120), 'region' => substr((string) $best['region'], 0, 120),
        'country' => (string) $best['country'], 'basis' => (string) $best['basis'],
        'confidence' => (int) $best['confidence'], 'signals' => (int) $best['signals'],
        'days' => (int) $best['days'], 'updated_at' => current_time('mysql'),
    ];
    if ($row) $wpdb->update($t, $vals, ['subscriber_id' => $sid]);
    else      $wpdb->insert($t, array_merge($vals, ['subscriber_id' => $sid]));
    return $best;
}

/**
 * The best place for one fan: ['city','region','country','basis','confidence',…]
 * or null. Falls back to the signup city when nothing is derived yet, so this
 * is always safe to call in place of reading the city column directly.
 */
function lmeg_home_place($sid) {
    global $wpdb;
    $sid = (int) $sid;
    if (!$sid) return null;
    $t = lmeg_fan_home_table();
    if (lmeg_home_has_table($t)) {
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE subscriber_id = %d", $sid), ARRAY_A);
        if ($r && trim((string) $r['city']) !== '') {
            return ['city' => $r['city'], 'region' => $r['region'], 'country' => $r['country'],
                    'basis' => $r['basis'], 'confidence' => (int) $r['confidence'],
                    'signals' => (int) $r['signals'], 'days' => (int) $r['days']];
        }
    }
    $subs = $wpdb->prefix . LMEG_TABLE;
    $sub = $wpdb->get_row($wpdb->prepare("SELECT city, region, country FROM $subs WHERE id = %d", $sid));
    if (!$sub || trim((string) $sub->city) === '') return null;
    return ['city' => $sub->city, 'region' => (string) $sub->region, 'country' => (string) $sub->country,
            'basis' => 'signup', 'confidence' => 25, 'signals' => 0, 'days' => 0];
}

/* ---------------------------------------------------------------------------
 * Resolver — turns the IPs already sitting on engagement events into places.
 * Runs on the minute tick at priority 65, before the ladder reading (70) so a
 * fresh home city is in place when anything reads it.
 * ------------------------------------------------------------------------- */
add_action('lmeg_broadcast_tick', 'lmeg_home_resolve_tick', 65);
function lmeg_home_resolve_tick($budget = 25) {
    global $wpdb;
    if (!function_exists('lmeg_geo_city_from_ip')) return;
    if (get_site_transient('lmeg_home_resolve_lock')) return;
    set_site_transient('lmeg_home_resolve_lock', 1, MINUTE_IN_SECONDS);
    lmeg_home_maybe_install();

    $ev     = $wpdb->prefix . 'lmeg_broadcast_events';
    $cursor = (int) get_option('lmeg_home_event_cursor', 0);
    $rows   = $wpdb->get_results($wpdb->prepare(
        "SELECT id, subscriber_id, event_type, ip, created_at FROM $ev
          WHERE id > %d AND subscriber_id > 0 AND ip IS NOT NULL AND ip <> ''
            AND event_type IN ('click','open','pageview')
          ORDER BY id ASC LIMIT %d", $cursor, max(1, (int) $budget)));

    if (!$rows) {
        // Caught up. Stay where we are; new events have higher ids.
        return;
    }
    $touched = [];
    foreach ($rows as $r) {
        update_option('lmeg_home_event_cursor', (int) $r->id, false);
        if (!lmeg_home_ip_usable($r->ip)) continue;
        $g = lmeg_geo_city_from_ip($r->ip);
        if ($g === false) return;            // geo API down — resume from here next tick
        if (!is_array($g) || empty($g['city'])) continue;
        lmeg_home_note_place((int) $r->subscriber_id, $g, (string) $r->event_type, substr((string) $r->created_at, 0, 10));
        $touched[(int) $r->subscriber_id] = true;
    }
    foreach (array_keys($touched) as $sid) lmeg_home_recompute($sid);
}

/* ---------------------------------------------------------------------------
 * Capture the stated places at the moment they're structured.
 * ------------------------------------------------------------------------- */

/**
 * A city on the row at the moment of signup is the fan's own answer: the IP
 * geocoder only ever runs later, on the tick, and only fills blanks. So a city
 * present here was typed into the form.
 */
add_action('lmeg_subscriber_created', 'lmeg_home_capture_signup_city', 20);
function lmeg_home_capture_signup_city($sid) {
    global $wpdb;
    $subs = $wpdb->prefix . LMEG_TABLE;
    $row = $wpdb->get_row($wpdb->prepare("SELECT city, region, country FROM $subs WHERE id = %d", (int) $sid));
    if (!$row || trim((string) $row->city) === '') return;
    lmeg_home_set_stated((int) $sid, $row->city, (string) $row->region, (string) $row->country, 'explicit');
}

/** Where an order shipped is a real address, even if they never typed a city for us. */
function lmeg_home_capture_order_city($sid, $city, $region = '', $country = '') {
    if (trim((string) $city) === '') return;
    lmeg_home_set_stated((int) $sid, $city, $region, $country, 'order');
}

/**
 * Best place for many fans at once: [subscriber_id => place]. One query, so a
 * radius send doesn't do N lookups. Fans with no derived home are absent —
 * callers fall back to the signup city on the row they already have.
 */
function lmeg_home_places_for_ids($ids) {
    global $wpdb;
    $ids = array_values(array_unique(array_map('intval', (array) $ids)));
    $ids = array_filter($ids);
    if (!$ids) return [];
    $t = lmeg_fan_home_table();
    if (!lmeg_home_has_table($t)) return [];
    $out = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $in = implode(',', array_map('intval', $chunk));
        $rows = $wpdb->get_results(
            "SELECT subscriber_id, city, region, country, basis, confidence, signals, days
               FROM $t WHERE subscriber_id IN ($in) AND city <> ''", ARRAY_A);
        foreach ((array) $rows as $r) {
            $out[(int) $r['subscriber_id']] = [
                'city' => $r['city'], 'region' => $r['region'], 'country' => $r['country'],
                'basis' => $r['basis'], 'confidence' => (int) $r['confidence'],
                'signals' => (int) $r['signals'], 'days' => (int) $r['days'],
            ];
        }
    }
    return $out;
}

/**
 * The place to use when aiming a local send at one fan row: their derived or
 * stated home if we have one, otherwise the city they signed up in, labelled
 * as such. Pure given the map.
 */
function lmeg_home_place_for_row($row, $map = []) {
    $sid = (int) ($row->id ?? 0);
    if ($sid && isset($map[$sid])) return $map[$sid];
    $city = trim((string) ($row->city ?? ''));
    if ($city === '') return null;
    return ['city' => $city, 'region' => (string) ($row->region ?? ''), 'country' => (string) ($row->country ?? ''),
            'basis' => 'signup', 'confidence' => 25, 'signals' => 0, 'days' => 0];
}

/** Count an audience's places by basis, for "who am I actually reaching". Pure. */
function lmeg_home_basis_tally($places) {
    $t = ['explicit' => 0, 'order' => 0, 'derived' => 0, 'signup' => 0];
    foreach ((array) $places as $p) {
        $b = (string) ($p['basis'] ?? '');
        if (isset($t[$b])) $t[$b]++;
    }
    return $t;
}

/** "38 from repeat visits, 104 from where they signed up" — for the Compose note. Pure. */
function lmeg_home_basis_sentence($tally) {
    $bits = [];
    $label = ['explicit' => 'told us their city', 'order' => 'from a shipping address',
              'derived' => 'from repeat visits', 'signup' => 'from where they signed up'];
    foreach (['explicit', 'order', 'derived', 'signup'] as $b) {
        $n = (int) ($tally[$b] ?? 0);
        if ($n > 0) $bits[] = number_format_i18n($n) . ' ' . $label[$b];
    }
    return $bits ? implode(' · ', $bits) : '';
}

/**
 * How much the signup city is lying, for this site. Counts fans placed by
 * repeat activity, fans we only have a signup guess for, and the ones where
 * the two disagree — the travellers.
 */
function lmeg_home_mismatch_stats() {
    global $wpdb;
    $t = lmeg_fan_home_table();
    $subs = $wpdb->prefix . LMEG_TABLE;
    $out = ['placed' => 0, 'derived' => 0, 'stated' => 0, 'signup_only' => 0, 'disagree' => 0, 'pending' => 0];
    if (!lmeg_home_has_table($t)) return $out;

    $row = $wpdb->get_row(
        "SELECT
            SUM(h.city <> '') placed,
            SUM(h.basis = 'derived') derived,
            SUM(h.basis IN ('explicit','order')) stated,
            SUM(h.basis = 'signup') signup_only,
            SUM(h.basis <> 'signup' AND s.city <> '' AND LOWER(h.city) <> LOWER(s.city)) disagree
         FROM $t h INNER JOIN $subs s ON s.id = h.subscriber_id", ARRAY_A);
    foreach (['placed', 'derived', 'stated', 'signup_only', 'disagree'] as $k) {
        $out[$k] = (int) ($row[$k] ?? 0);
    }
    // Events still waiting for the resolver — tells you whether to trust the above yet.
    $ev = $wpdb->prefix . 'lmeg_broadcast_events';
    $cursor = (int) get_option('lmeg_home_event_cursor', 0);
    $out['pending'] = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $ev WHERE id > %d AND subscriber_id > 0 AND ip IS NOT NULL AND ip <> ''
           AND event_type IN ('click','open','pageview')", $cursor));
    return $out;
}
