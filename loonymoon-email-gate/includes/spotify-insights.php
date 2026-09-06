<?php
/**
 * Spotify Insights — the one cohesive page that answers "how is my music doing?"
 *
 * Fanloop holds Spotify data in two places that, on their own, each tell half the
 * story:
 *   - spotify.php  : the public Web API — followers, popularity, genres, artist
 *                    photo, follower/popularity history, initiative "impact".
 *   - s4a.php      : Spotify for Artists — streams, monthly + active listeners,
 *                    saves, per-song streams, playlists, listener geography.
 * This page stitches both into a single scannable view built around the music:
 * a headline read, the numbers that matter, the trends, and the songs doing the
 * work. It degrades gracefully — whichever source is present still renders, with
 * a clear nudge to connect/import the other.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_submenu_page('lmeg', 'Spotify Insights', 'Spotify Insights', 'manage_options', 'lmeg-spotify-insights', 'lmeg_admin_spotify_insights');
}, 20);

/** Shared dark-theme tokens for this page. */
function lmeg_si_tokens() {
    return [
        'card' => 'background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px 18px;color:#F4F5F7;',
        'lbl'  => 'font:600 11px/1 var(--lmegA-font,inherit);letter-spacing:.06em;text-transform:uppercase;color:#8B90A0;',
        'muted'=> 'color:#8B90A0;',
    ];
}

/** Green/red/grey percent chip. */
function lmeg_si_chip($pct) {
    if ($pct === null || $pct === '') return '';
    $pct = (float) $pct;
    $c = $pct > 0 ? '#34D399' : ($pct < 0 ? '#F87171' : '#8B90A0');
    return '<span style="font-size:12px;font-weight:600;color:' . $c . ';margin-left:6px;">' . ($pct > 0 ? '+' : '') . rtrim(rtrim(number_format($pct, 1), '0'), '.') . '%</span>';
}

/**
 * Compose the headline read from whatever data exists — a plain-language sentence
 * plus a momentum clause. Returns '' when there's nothing to say yet.
 */
function lmeg_si_headline($snap, $changes, $ov) {
    $fmt = function ($n) { return number_format_i18n((int) $n); };
    $bits = [];
    if ($snap && $snap->streams !== null) {
        $s = $fmt($snap->streams);
        $pct = $changes['streams'] ?? null;
        $mom = ($pct !== null && $pct !== '') ? (((float) $pct > 0 ? 'up ' : (((float) $pct < 0) ? 'down ' : '')) . rtrim(rtrim(number_format(abs((float) $pct), 1), '0'), '.') . '% on the prior period') : '';
        $bits[] = 'Your music pulled <strong>' . $s . ' streams</strong> in the last 28 days' . ($mom ? ' — ' . $mom : '') . '.';
    } elseif ($snap && $snap->monthly_listeners !== null) {
        $bits[] = '<strong>' . $fmt($snap->monthly_listeners) . '</strong> people listened this month.';
    } elseif (is_array($ov) && !empty($ov['followers'])) {
        $bits[] = '<strong>' . $fmt($ov['followers']) . '</strong> followers on Spotify.';
    }
    return $bits ? implode(' ', $bits) : '';
}

/**
 * Normalize the S4A gender object ({female,male,nonbinary,unknown} string counts)
 * into display rows with share-of-total. Returns [] when empty.
 */
function lmeg_si_gender_split($gender) {
    if (!is_array($gender)) return [];
    $map = [
        'female'    => ['label' => 'Women',      'color' => '#D05FA2'],
        'male'      => ['label' => 'Men',        'color' => '#7C6CF6'],
        'nonbinary' => ['label' => 'Non-binary', 'color' => '#34D399'],
        'unknown'   => ['label' => 'Unknown',    'color' => '#8B90A0'],
    ];
    $total = 0;
    foreach ($map as $k => $_) { $total += (int) ($gender[$k] ?? 0); }
    if ($total <= 0) return [];
    $rows = [];
    foreach ($map as $k => $m) {
        $c = (int) ($gender[$k] ?? 0);
        if ($c <= 0) continue;
        $rows[] = ['label' => $m['label'], 'color' => $m['color'], 'count' => $c, 'pct' => $c / $total * 100];
    }
    return $rows;
}

/**
 * Age-bucket totals from the S4A gender_by_age object. Each bucket total is the
 * sum of its per-gender split (falls back to the scalar age_X_Y if present).
 * Returns ordered [ ['label'=>'18–24','total'=>N], … ], [] when empty.
 */
function lmeg_si_age_rows($gba) {
    if (!is_array($gba)) return [];
    $buckets = [
        'age_0_17' => '0–17', 'age_18_24' => '18–24', 'age_25_34' => '25–34',
        'age_35_44' => '35–44', 'age_45_54' => '45–54', 'age_55_64' => '55–64', 'age_65' => '65+',
    ];
    $rows = [];
    foreach ($buckets as $key => $label) {
        $total = 0;
        $split = $gba[$key . '_gender'] ?? null;
        if (is_array($split)) {
            foreach (['female', 'male', 'nonbinary', 'unknown'] as $g) { $total += (int) ($split[$g] ?? 0); }
        } elseif (isset($gba[$key]) && is_scalar($gba[$key])) {
            $total = (int) $gba[$key];
        }
        if ($total > 0) $rows[] = ['label' => $label, 'total' => $total];
    }
    return $rows;
}

/**
 * Per-song momentum from a single snapshot: compare each song's last-7-days
 * streams against its 28-day run-rate (streams/4). A positive pace means the song
 * is running hotter than its 28-day average — i.e. rising.
 *
 * @param array $songs28 [{title,streams}, …] — the 28-day song list.
 * @param array $songs7d [{title,streams}, …] — the last-7-days song list (top N).
 * @return array ['by_title'=>[normTitle=>['pace'=>float|null,'s7'=>int|null,'s28'=>int]],
 *                'biggest'=>['title','pace','s7','s28']|null]
 */
function lmeg_si_song_movers($songs28, $songs7d) {
    $norm = function ($s) { return strtolower(trim((string) $s)); };
    $map7 = [];
    $max7 = 0;
    foreach ((array) $songs7d as $s) {
        if (!is_array($s)) continue;
        $t = $norm($s['title'] ?? $s['trackName'] ?? '');
        if ($t === '') continue;
        $v = (int) ($s['streams'] ?? 0);
        $map7[$t] = $v;
        $max7 = max($max7, $v);
    }
    $by = [];
    $biggest = null;
    $floor = max(100, (int) round($max7 * 0.05)); // ignore low-volume noise for the callout
    foreach ((array) $songs28 as $s) {
        if (!is_array($s)) continue;
        $title = (string) ($s['title'] ?? $s['trackName'] ?? '');
        $t = $norm($title);
        if ($t === '') continue;
        $s28 = (int) ($s['streams'] ?? 0);
        $s7  = array_key_exists($t, $map7) ? (int) $map7[$t] : null;
        $pace = null;
        if ($s7 !== null && $s28 > 0) {
            $expected = $s28 / 4.0;
            if ($expected > 0) $pace = ($s7 - $expected) / $expected * 100.0;
        }
        $by[$t] = ['pace' => $pace, 's7' => $s7, 's28' => $s28];
        if ($pace !== null && $pace > 0 && $s7 !== null && $s7 >= $floor) {
            if ($biggest === null || $pace > $biggest['pace']) {
                $biggest = ['title' => $title, 'pace' => $pace, 's7' => $s7, 's28' => $s28];
            }
        }
    }
    return ['by_title' => $by, 'biggest' => $biggest];
}

/**
 * Playlist stream-source mix: share of playlist streams by type — Editorial
 * (Spotify's team), Algorithmic (Radio/Mixes/DJ), Listener (organic user
 * playlists). A strategic signal of how algo-dependent vs editorially-backed the
 * artist is. Returns rows sorted by streams, or [] when no playlist streams.
 */
function lmeg_si_playlist_mix($playlists) {
    $map = [
        'curated'      => ['label' => 'Editorial',   'color' => '#D05FA2'],
        'personalized' => ['label' => 'Algorithmic', 'color' => '#7C6CF6'],
        'listener'     => ['label' => 'Listener',    'color' => '#34D399'],
    ];
    $tot = 0; $by = [];
    foreach ((array) $playlists as $p) {
        if (!is_array($p)) continue;
        $ty = (string) ($p['type'] ?? ''); if (!isset($map[$ty])) continue;
        $s = (int) ($p['streams'] ?? 0); if ($s <= 0) continue;
        $by[$ty] = ($by[$ty] ?? 0) + $s; $tot += $s;
    }
    if ($tot <= 0) return [];
    $rows = [];
    foreach ($map as $ty => $m) {
        if (empty($by[$ty])) continue;
        $rows[] = ['type' => $ty, 'label' => $m['label'], 'color' => $m['color'], 'streams' => $by[$ty], 'pct' => $by[$ty] / $tot * 100];
    }
    usort($rows, function ($a, $b) { return $b['streams'] <=> $a['streams']; });
    return $rows;
}

/**
 * The track fans keep most: highest saves/listeners rate among songs with
 * enough listeners to be meaningful, compared to the catalog's listener-weighted
 * save rate. A high per-song save rate is fan intent independent of raw stream
 * volume — the fan-favorite is often NOT the biggest streamer. Pure.
 * Returns ['title','rate','cat','ratio','listeners','saves'] or null.
 */
function lmeg_si_top_saver($songs, $min_listeners = 500, $k = 500) {
    $tot_s = 0; $tot_l = 0; $cand = [];
    foreach ((array) $songs as $s) {
        if (!is_array($s)) continue;
        $li = (int) ($s['listeners'] ?? 0); $sv = (int) ($s['saves'] ?? 0);
        if ($li <= 0 || $sv < 0) continue;
        $tot_s += $sv; $tot_l += $li;
        if ($li >= $min_listeners) $cand[] = ['title' => (string) ($s['title'] ?? $s['trackName'] ?? ''), 'li' => $li, 'sv' => $sv];
    }
    if (!$cand || $tot_l <= 0) return null;
    $cat = $tot_s / $tot_l * 100;
    // Rank by a shrunk rate — (saves + k·catRate)/(listeners + k) — so a high rate
    // on a thin listener base is pulled toward the catalog mean and a well-sampled
    // track wins. Report the RAW observed rate; rank on the shrunk one.
    $best = null; $bestShrunk = -1;
    foreach ($cand as $c) {
        $shrunk = ($c['sv'] + $k * ($cat / 100)) / ($c['li'] + $k) * 100;
        if ($shrunk > $bestShrunk) {
            $bestShrunk = $shrunk;
            $rate = $c['sv'] / $c['li'] * 100;
            $best = ['title' => $c['title'], 'rate' => $rate, 'listeners' => $c['li'], 'saves' => $c['sv'],
                     'cat' => $cat, 'ratio' => $cat > 0 ? $rate / $cat : null];
        }
    }
    return $best;
}

/**
 * Streams velocity from the daily 28-day series: last 7 days vs the previous 7
 * (week-over-week), plus how many consecutive trailing weeks moved the same way.
 * A within-period momentum read that works from ONE snapshot — catches a trend
 * turning before it shows in the month-over-month totals. Pure.
 * Returns ['wow','last7','prior7','weeks_down','weeks_up'] or null (<14 days).
 */
function lmeg_si_stream_velocity($daily_streams) {
    $v = array_map('intval', array_values(array_filter((array) $daily_streams, 'is_numeric')));
    $n = count($v);
    if ($n < 14) return null;
    $last7  = array_sum(array_slice($v, -7));
    $prior7 = array_sum(array_slice($v, -14, 7));
    if ($prior7 <= 0) return null;
    // Trailing 7-day blocks, newest first, to measure a consecutive streak.
    $blocks = [];
    for ($k = 0; $k < 6; $k++) { $start = $n - 7 * ($k + 1); if ($start < 0) break; $blocks[] = array_sum(array_slice($v, $start, 7)); }
    $down = 0; $up = 0; $m = count($blocks);
    if ($m >= 2) {
        if ($blocks[0] < $blocks[1]) { $down = 1; for ($i = 1; $i + 1 < $m; $i++) { if ($blocks[$i] < $blocks[$i + 1]) $down++; else break; } }
        elseif ($blocks[0] > $blocks[1]) { $up = 1; for ($i = 1; $i + 1 < $m; $i++) { if ($blocks[$i] > $blocks[$i + 1]) $up++; else break; } }
    }
    return ['wow' => ($last7 - $prior7) / $prior7 * 100, 'last7' => $last7, 'prior7' => $prior7, 'weeks_down' => $down, 'weeks_up' => $up];
}

/**
 * "Fan rings" — the five concentric audiences Fanloop can see at once, and the
 * conversion between each: Listeners (Spotify, anonymous) → Followers (Spotify
 * + Instagram, semi-anonymous) → On your list (named, reachable) → Customers
 * (paid once) → Members (pay monthly). Pure shaping: takes the raw counts,
 * returns ordered rings with 'pct' = share of the previous ring (null when
 * either side is unknown/0). Followers uses the LARGER platform (overlap is
 * unknown, so a sum would inflate) and lists both underneath.
 */
function lmeg_si_fan_rings_shape($n) {
    $v = function ($k) use ($n) { return (isset($n[$k]) && $n[$k] !== null && $n[$k] !== '') ? max(0, (int) $n[$k]) : null; };
    $sp = $v('sp_followers'); $ig = $v('ig_followers');
    $fol = ($sp === null && $ig === null) ? null : max((int) $sp, (int) $ig);
    $folSub = [];
    if ($sp !== null) $folSub[] = 'Spotify ' . number_format_i18n($sp);
    if ($ig !== null) $folSub[] = 'Instagram ' . number_format_i18n($ig);
    $rings = [
        ['key' => 'listeners', 'label' => 'Monthly listeners', 'value' => $v('listeners'), 'sub' => 'Spotify · anonymous', 'tone' => '#7C6CF6'],
        ['key' => 'followers', 'label' => 'Followers',         'value' => $fol,            'sub' => $folSub ? implode(' · ', $folSub) : 'not connected', 'tone' => '#7C6CF6'],
        ['key' => 'list',      'label' => 'On your list',      'value' => $v('list'),      'sub' => ($v('superfans') !== null ? number_format_i18n($v('superfans')) . ' superfans · ' : '') . 'email, SMS, DM', 'tone' => '#D05FA2', 'href' => 'admin.php?page=lmeg-fanbase'],
        ['key' => 'customers', 'label' => 'Customers',         'value' => $v('customers'), 'sub' => 'bought at least once', 'tone' => '#D05FA2'],
        ['key' => 'members',   'label' => 'Members',           'value' => $v('members'),   'sub' => 'paying monthly', 'tone' => '#34D399'],
    ];
    // Recent movement per ring (optional inputs): listeners_pct (S4A period-
    // over-period %), sp_followers_delta / ig_followers_delta (abs, 28d),
    // list_new (fans added, 30d), customers_new (first-time buyers, 28d).
    $sgn = function ($v, $suffix = '') { return ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . number_format_i18n(abs((int) $v)) . $suffix; };
    $lp = isset($n['listeners_pct']) && $n['listeners_pct'] !== null && $n['listeners_pct'] !== '' ? (float) $n['listeners_pct'] : null;
    $rings[0]['change'] = $lp !== null ? [($lp > 0 ? '+' : ($lp < 0 ? '−' : '')) . rtrim(rtrim(number_format(abs($lp), 1), '0'), '.') . '% vs the 28 days before', $lp <=> 0] : null;
    $fd = [];
    if (isset($n['sp_followers_delta']) && $n['sp_followers_delta'] !== null) $fd[] = 'Spotify ' . $sgn($n['sp_followers_delta']);
    if (isset($n['ig_followers_delta']) && $n['ig_followers_delta'] !== null) $fd[] = 'Instagram ' . $sgn($n['ig_followers_delta']);
    $fsum = (int) ($n['sp_followers_delta'] ?? 0) + (int) ($n['ig_followers_delta'] ?? 0);
    $rings[1]['change'] = $fd ? [implode(' · ', $fd) . ' in 28 days', $fsum <=> 0] : null;
    $rings[2]['change'] = isset($n['list_new']) && $n['list_new'] !== null ? [$sgn($n['list_new']) . ' new in 30 days', ((int) $n['list_new']) <=> 0] : null;
    $rings[3]['change'] = isset($n['customers_new']) && $n['customers_new'] !== null ? [$sgn($n['customers_new']) . ' first-time buyers in 28 days', ((int) $n['customers_new']) <=> 0] : null;
    $rings[4]['change'] = null;
    $prev = null; $prevLabel = null;
    foreach ($rings as &$r) {
        $r['pct'] = ($prev !== null && $prev > 0 && $r['value'] !== null) ? round($r['value'] / $prev * 100, $r['value'] / $prev * 100 < 1 ? 2 : 1) : null;
        $r['pct_of'] = $r['pct'] !== null ? $prevLabel : null;
        if ($r['value'] !== null) { $prev = $r['value']; $prevLabel = strtolower($r['label']); }
    }
    unset($r);
    return $rings;
}

/**
 * Gather the raw counts for lmeg_si_fan_rings_shape from what this site holds:
 * S4A snapshot (listeners, Spotify followers), the latest Instagram snapshot,
 * the Fanbase groups (list total, superfans, members) and distinct buyers
 * across Shopify-attributed orders + the native store. Every source is
 * optional — a missing one yields null (rendered as "—"), never a fatal.
 */
function lmeg_si_fan_rings_data($snap, $ov, $has_api, $changes = []) {
    global $wpdb;
    $n = ['listeners' => null, 'sp_followers' => null, 'ig_followers' => null, 'list' => null, 'superfans' => null, 'customers' => null, 'members' => null];
    if ($snap) {
        $n['listeners'] = $snap->monthly_listeners !== null ? (int) $snap->monthly_listeners : null;
        $n['listeners_pct'] = (isset($changes['monthly_listeners']) && $changes['monthly_listeners'] !== null && $changes['monthly_listeners'] !== '') ? (float) $changes['monthly_listeners'] : null;
        // Spotify followers is a LEVEL: take the last day of the daily series
        // (snapshots ingested before v3.198.1 stored a 28-day SUM in the
        // followers column — never read that here), else the public API.
        $dm = $snap->meta ? (array) json_decode((string) $snap->meta, true) : [];
        $fs = array_values(array_filter(array_map('intval', (array) ($dm['daily']['followers'] ?? []))));
        if ($fs) { $n['sp_followers'] = (int) end($fs); if (count($fs) >= 7) $n['sp_followers_delta'] = (int) end($fs) - (int) $fs[0]; }
    }
    if ($n['sp_followers'] === null && $has_api && !empty($ov['followers'])) $n['sp_followers'] = (int) $ov['followers'];
    if (function_exists('lmeg_social_snapshots')) {
        $rows = (array) lmeg_social_snapshots('instagram', 28);
        if ($rows) { $last = end($rows); $first = reset($rows); $n['ig_followers'] = (int) $last->followers; if (count($rows) >= 7) $n['ig_followers_delta'] = (int) $last->followers - (int) $first->followers; }
    }
    if (function_exists('lmeg_fanbase_counts')) {
        $c = lmeg_fanbase_counts();
        $n['list'] = (int) ($c['total'] ?? 0); $n['superfans'] = (int) ($c['superfans'] ?? 0); $n['members'] = (int) ($c['members'] ?? 0);
        $n['list_new'] = isset($c['new']) ? (int) $c['new'] : null;
    }
    if (defined('LMEG_TABLE')) {
        $subs = $wpdb->prefix . LMEG_TABLE; $orders = $wpdb->prefix . 'lmeg_shop_orders'; $store = $wpdb->prefix . 'lmeg_product_purchases';
        // Distinct people, not orders: union Shopify buyers (by subscriber) with
        // native-store buyers (by email) — the store table may lack an email
        // column on older installs, so fall back to Shopify-only on error.
        $buyers = $wpdb->get_var("SELECT COUNT(DISTINCT e) FROM (
            SELECT LOWER(s.email) e FROM $orders o JOIN $subs s ON s.id = o.subscriber_id WHERE o.subscriber_id > 0
            UNION SELECT LOWER(email) FROM $store WHERE email IS NOT NULL AND email <> '' AND status NOT IN ('pending','failed','cancelled','canceled','refunded')
        ) x");
        if ($buyers === null || $wpdb->last_error) {
            $wpdb->last_error = '';
            $buyers = $wpdb->get_var("SELECT COUNT(DISTINCT subscriber_id) FROM $orders WHERE subscriber_id > 0");
        }
        $n['customers'] = $buyers !== null ? (int) $buyers : null;
        // First-time buyers in the last 28 days (Shopify-attributed): people
        // whose EARLIEST order falls inside the window.
        $nb = $wpdb->get_var("SELECT COUNT(*) FROM (SELECT subscriber_id, MIN(ordered_at) first_order FROM $orders WHERE subscriber_id > 0 GROUP BY subscriber_id) f
                               WHERE f.first_order >= DATE_SUB(NOW(), INTERVAL 28 DAY)");
        if ($wpdb->last_error) { $wpdb->last_error = ''; $nb = null; }
        $n['customers_new'] = $nb !== null ? (int) $nb : null;
    }
    return lmeg_si_fan_rings_shape($n);
}

/**
 * "What to do with it" — maps a finding to one-click actions inside Fanloop:
 * a prefilled Compose draft (angles: mover / repush / save / lift / release /
 * listen / follow, handled by lmeg_admin_compose's prefill=insight) or the
 * tool the finding points at (drops, contests, releases, pre-saves, shows,
 * fanbase, instagram). Pure: returns [['label','page','args'=>[]] | ['label',
 * 'href'], …]; the renderer builds admin URLs. Matches on the finding's title
 * (stable strings from lmeg_si_analyze) + its optional 'song'/'uri' keys.
 */
function lmeg_si_finding_actions($f) {
    $t = (string) ($f['title'] ?? ''); $song = (string) ($f['song'] ?? ''); $uri = (string) ($f['uri'] ?? '');
    $compose = function ($angle, $label) use ($song, $uri) {
        $a = ['prefill' => 'insight', 'angle' => $angle];
        if ($song !== '') $a['song'] = $song;
        if ($uri !== '') $a['uri'] = $uri;
        return ['label' => $label, 'page' => 'lmeg-compose', 'args' => $a];
    };
    $go = function ($page, $label) { return ['label' => $label, 'page' => $page, 'args' => []]; };
    $spotify = preg_match('/^spotify:track:([A-Za-z0-9]{22})$/', $uri, $mm) ? [['label' => 'Open on Spotify ↗', 'href' => 'https://open.spotify.com/track/' . $mm[1]]] : [];
    $ends = function ($s) use ($t) { return substr($t, -strlen($s)) === $s; };
    if ($ends('is breaking out this week') || $ends('is gaining'))  return array_merge([$compose('mover', 'Send it to your list')], $spotify);
    if ($song !== '' && $ends('is cooling'))                          return array_merge([$compose('repush', 'Re-push it to your list')], $spotify);
    if (strpos($t, 'Fans keep') === 0)                                return array_merge([$compose('save', 'Ask fans to save it')], $spotify);
    if (in_array($t, ['Lift across the catalogue', 'Streams are accelerating', 'Growing on both fronts', 'Your latest release is landing'], true)) return [$compose('lift', 'Tell your fans')];
    if (in_array($t, ['Soft week across the catalogue', 'Streams are cooling'], true)) return [$go('lmeg-drops', 'Plan a drop'), $go('lmeg-contests', 'Run a contest'), $compose('listen', 'Nudge your list')];
    if (in_array($t, ['Time for new music', 'One track carries a lot'], true))          return [$go('lmeg-releases', 'Plan a release'), $go('lmeg-presaves', 'Set up a pre-save')];
    if ($t === 'Latest release is under its potential')               return [$compose('release', 'Push the release')];
    if ($t === 'Turn listeners into followers')                       return [$compose('follow', 'Ask your list to follow'), $go('lmeg-presaves', 'Set up a pre-save')];
    if ($t === 'Low save rate')                                       return [$compose('save', 'Ask fans to save')];
    if (strpos($t, 'Your audience centers on') === 0)                 return [$go('lmeg-store-shows', 'Announce a show'), $go('lmeg-fanbase', 'See your fanbase')];
    if ($t === 'Streams up, social flat')                             return [$go('lmeg-instagram', 'Post about it')];
    if ($t === 'Social up, streams flat')                             return [$compose('listen', 'Send your list to Spotify')];
    if ($t === 'Loyal core')                                          return [$go('lmeg-fanbase', 'See your superfans')];
    if (strpos($t, 'New editorial playlist') === 0)                   return [$compose('lift', 'Tell your fans'), $go('lmeg-instagram', 'Post about it')];
    if ($ends('is your fastest-growing market') || $ends('is slipping')) return [$go('lmeg-store-shows', 'Announce a show'), $go('lmeg-segments', 'Target fans there')];
    if (strpos($t, 'opened bigger than') !== false)                   return array_merge([$compose('mover', 'Send it to your list')], $spotify);
    if (strpos($t, 'opened smaller than') !== false)                  return [$go('lmeg-presaves', 'Set up a pre-save'), $compose('repush', 'Re-push it to your list')];
    if (strpos($t, 'Dropped from') === 0)                             return [$go('lmeg-releases', 'Plan a release'), $go('lmeg-presaves', 'Set up a pre-save')];
    if ($t === 'Heavily algorithm-driven')                            return [$go('lmeg-presaves', 'Set up a pre-save')];
    return [];
}

/**
 * Campaign marks — completed broadcasts (email/SMS sends) in the last $days,
 * folded to one mark per send day: {d, n sends, subject (first), sent (total
 * recipients), clicks (distinct clickers)}. Two plain queries (no correlated
 * GROUP BY, so ONLY_FULL_GROUP_BY can't bite). Read-only; [] on any error.
 */
function lmeg_si_campaign_marks($days = 365) {
    global $wpdb;
    if (empty($wpdb)) return [];
    $b = $wpdb->prefix . 'lmeg_broadcasts'; $e = $wpdb->prefix . 'lmeg_broadcast_events';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, subject, sent, DATE(created_at) d FROM $b
          WHERE status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY) ORDER BY created_at ASC", (int) $days), ARRAY_A);
    if ($wpdb->last_error || !$rows) { $wpdb->last_error = ''; return []; }
    $ids = array_map('intval', array_column($rows, 'id'));
    $clicks = [];
    $cr = $wpdb->get_results("SELECT broadcast_id, COUNT(DISTINCT subscriber_id) c FROM $e
                               WHERE event_type = 'click' AND broadcast_id IN (" . implode(',', $ids) . ") GROUP BY broadcast_id", ARRAY_A);
    if ($wpdb->last_error) { $wpdb->last_error = ''; $cr = []; }
    foreach ((array) $cr as $c) $clicks[(int) $c['broadcast_id']] = (int) $c['c'];
    $out = [];
    foreach ($rows as $r) {
        $d = (string) $r['d'];
        if (!isset($out[$d])) $out[$d] = ['d' => $d, 'n' => 0, 'subject' => trim((string) $r['subject']) !== '' ? (string) $r['subject'] : 'untitled send', 'sent' => 0, 'clicks' => 0];
        $out[$d]['n']++; $out[$d]['sent'] += (int) $r['sent']; $out[$d]['clicks'] += $clicks[(int) $r['id']] ?? 0;
    }
    return array_values($out);
}

/**
 * Streams lift around each campaign mark that falls inside a daily series:
 * average of the $win days from the send day forward vs the $win days before.
 * Needs full windows on both sides and a non-zero "before". Pure. Returns
 * [{d, subject, n, sent, clicks, before, after, pct}] in series order.
 */
function lmeg_si_campaign_lift($dates, $vals, $marks, $win = 3) {
    $dates = array_values((array) $dates); $vals = array_values(array_map('intval', (array) $vals));
    $idx = array_flip($dates); $out = [];
    foreach ((array) $marks as $m) {
        $d = (string) ($m['d'] ?? ''); if (!isset($idx[$d])) continue;
        $i = (int) $idx[$d];
        if ($i - $win < 0 || $i + $win - 1 >= count($vals)) continue;
        $before = array_sum(array_slice($vals, $i - $win, $win)) / $win;
        $after  = array_sum(array_slice($vals, $i, $win)) / $win;
        if ($before <= 0) continue;
        $out[] = ['d' => $d, 'subject' => (string) ($m['subject'] ?? ''), 'n' => (int) ($m['n'] ?? 1), 'sent' => (int) ($m['sent'] ?? 0), 'clicks' => (int) ($m['clicks'] ?? 0),
                  'before' => (int) round($before), 'after' => (int) round($after), 'pct' => round(($after - $before) / $before * 100, 1)];
    }
    return $out;
}

/**
 * Demo companions for ?demo=1 (see lmeg_s4a_demo_payload): the public-API
 * overview the hero/releases logic reads (release DATES come from here since
 * S4A's are empty), and campaign marks so the lift readouts have sends to
 * point at. Pure; dates relative to today.
 */
function lmeg_si_demo_overview($artist) {
    $ago = function ($days) { return date('Y-m-d', strtotime(current_time('Y-m-d')) - $days * 86400); };
    return [
        'name' => $artist, 'followers' => 8240, 'popularity' => 46, 'genres' => ['indie pop', 'bedroom pop', 'alt'], 'image' => '', 'url' => '',
        'releases' => [
            ['name' => 'Blue Hour',        'date' => $ago(19),  'type' => 'single'],
            ['name' => 'Neon Rain',        'date' => $ago(64),  'type' => 'single'],
            ['name' => 'Glasshouse EP',    'date' => $ago(140), 'type' => 'ep'],
            ['name' => 'Midnight Traffic', 'date' => $ago(300), 'type' => 'single'],
            ['name' => 'Paper Planets',    'date' => $ago(420), 'type' => 'single'],
        ],
        'top_tracks' => [],
    ];
}
function lmeg_si_demo_marks() {
    $ago = function ($days) { return date('Y-m-d', strtotime(current_time('Y-m-d')) - $days * 86400); };
    return [
        ['d' => $ago(130), 'n' => 1, 'subject' => 'Summer tour dates 🌙',          'sent' => 1980, 'clicks' => 240],
        ['d' => $ago(61),  'n' => 1, 'subject' => 'Glasshouse EP turns one',      'sent' => 2040, 'clicks' => 96],
        ['d' => $ago(23),  'n' => 1, 'subject' => 'Toronto show — presale is live', 'sent' => 2090, 'clicks' => 188],
        ['d' => $ago(9),   'n' => 1, 'subject' => 'Blue Hour is out now',          'sent' => 2140, 'clicks' => 412],
    ];
}

/**
 * "Spotify this week" — the block the Monday owner digest (sending.php,
 * lmeg_send_owner_digest) appends: 28d streams + listeners with period-over-
 * period change, followers level + 28d delta, the real week-over-week mover
 * (or the song that cooled most), the last send's streams lift, and the top 3
 * findings with their action links. '' when the site has no S4A snapshot.
 * Light email HTML (inline styles, light background — it lands in the inbox).
 */
function lmeg_si_digest_html() {
    if (!function_exists('lmeg_s4a_latest')) return '';
    $sel  = lmeg_artist();
    $snap = lmeg_s4a_latest($sel);
    if (!$snap) return '';
    $meta = $snap->meta ? (array) json_decode((string) $snap->meta, true) : [];
    $prev = function_exists('lmeg_s4a_prev') ? lmeg_s4a_prev($sel, $snap->window, $snap->captured_date) : null;
    $pct  = function ($a, $b) { return ($b !== null && (int) $b > 0 && $a !== null) ? round(((int) $a - (int) $b) / (int) $b * 100, 1) : null; };
    $chg  = function ($v) { return $v === null ? '' : ' <span style="color:' . ($v >= 0 ? '#1f9d63' : '#d9534f') . ';font-weight:600;">' . ($v >= 0 ? '+' : '') . $v . '%</span>'; };
    $sp   = $prev ? $pct($snap->streams, $prev->streams) : null;
    $mlp  = $prev ? $pct($snap->monthly_listeners, $prev->monthly_listeners) : null;
    $rows = [];
    $rows[] = ['🎧', 'Streams (28 days)', number_format_i18n((int) $snap->streams) . $chg($sp)];
    $rows[] = ['👂', 'Monthly listeners', number_format_i18n((int) $snap->monthly_listeners) . $chg($mlp)];
    $fs = array_values(array_filter(array_map('intval', (array) ($meta['daily']['followers'] ?? []))));
    if ($fs) {
        $fd = count($fs) >= 7 ? (int) end($fs) - (int) $fs[0] : null;
        $rows[] = ['➕', 'Spotify followers', number_format_i18n((int) end($fs)) . ($fd !== null ? ' <span style="opacity:.6;">(' . ($fd >= 0 ? '+' : '−') . number_format_i18n(abs($fd)) . ' in 28 days)</span>' : '')];
    }
    $map = lmeg_si_song_daily_map((array) ($meta['song_daily'] ?? []));
    $sw  = $map ? lmeg_si_song_wow_summary($map) : null;
    if ($sw && !empty($sw['up'])) {
        $g = $sw['up'][0];
        $rows[] = ['🔥', 'Mover this week', esc_html($g['title']) . ' — +' . $g['wow'] . '% (' . number_format_i18n($g['last7']) . ' streams in 7 days)' . ' <span style="opacity:.6;">· ' . count($sw['up']) . ' up / ' . count($sw['down']) . ' down</span>'];
    } elseif ($sw && !empty($sw['down'])) {
        $g = $sw['down'][0];
        $rows[] = ['🧊', 'Cooled most', esc_html($g['title']) . ' — ' . $g['wow'] . '% <span style="opacity:.6;">· ' . count($sw['down']) . ' songs down, ' . count($sw['up']) . ' up</span>'];
    }
    $daily = (array) ($meta['daily'] ?? []);
    if (!empty($daily['dates']) && function_exists('lmeg_si_campaign_marks')) {
        $lift = lmeg_si_campaign_lift($daily['dates'], (array) ($daily['streams'] ?? []), lmeg_si_campaign_marks(14));
        if ($lift) { $L = end($lift); $rows[] = ['✉️', 'Last send → streams', esc_html($L['subject']) . ' — ' . ($L['pct'] >= 0 ? '+' : '') . $L['pct'] . '% over the 3 days after']; }
    }
    // A recent launch (≤35 days) gets its own line: first 7 / first 28 days.
    $sd_entries = (array) ($meta['song_daily'] ?? []);
    $ovd = function_exists('lmeg_spotify_overview') ? lmeg_spotify_overview() : null; if (is_wp_error($ovd)) $ovd = null;
    $launch = lmeg_si_launch_compare((is_array($ovd) && !empty($ovd['releases'])) ? $ovd['releases'] : [], $map);
    if ($launch && (int) $launch[0]['days'] <= 35) {
        $L0 = $launch[0];
        $rows[] = ['🚀', 'Launch · ' . esc_html($L0['name']), number_format_i18n($L0['first7']) . ' streams in its first 7 days' . ($L0['first28'] !== null ? ' · ' . number_format_i18n($L0['first28']) . ' in its first 28' : ' · ' . (int) $L0['days'] . ' days in')];
    }
    // Same engine inputs as the page, so Monday's "What to do" matches it.
    $cm = lmeg_si_catalogue_monthly($sd_entries);
    $moves = null; $mdays = 0;
    if (!empty($meta['countries']) && function_exists('lmeg_s4a_at')) {
        $base = lmeg_s4a_at($sel, $snap->window, date('Y-m-d', strtotime($snap->captured_date . ' -7 days')));
        if ((!$base || $base->captured_date >= $snap->captured_date) && $prev) { $pm = (array) json_decode((string) $prev->meta, true); $base = (object) ['captured_date' => $prev->captured_date, 'countries' => wp_json_encode($pm['countries'] ?? [])]; }
        if ($base && $base->captured_date < $snap->captured_date) { $moves = lmeg_si_country_movers($meta['countries'], json_decode((string) $base->countries, true)); $mdays = max(1, (int) round((strtotime($snap->captured_date) - strtotime($base->captured_date)) / 86400)); }
    }
    $extra_ctx = [
        'weekday'       => lmeg_si_weekday_profile($sd_entries),
        'quarter'       => $cm ? $cm['q90'] : null,
        'launch'        => $launch,
        'playlist_diff' => $prev ? lmeg_si_playlist_diff(json_decode((string) $snap->top_playlists, true), json_decode((string) $prev->top_playlists, true)) : null,
        'prev_date'     => $prev ? (string) $prev->captured_date : null,
        'country_moves' => $moves,
        'moves_days'    => $mdays,
    ];
    $html = '<h3 style="margin:22px 0 10px;">Spotify this week</h3><table style="border-collapse:collapse;">';
    foreach ($rows as $r) {
        $html .= '<tr><td style="padding:6px 10px 6px 0;font-size:18px;">' . $r[0] . '</td>'
               . '<td style="padding:6px 18px 6px 0;color:#777;white-space:nowrap;">' . $r[1] . '</td>'
               . '<td style="padding:6px 0;font-weight:600;">' . $r[2] . '</td></tr>';
    }
    $html .= '</table>';
    // Top 3 findings, each with up to two of its action links.
    $ctx = [
        'song_wow'          => $sw,
        'velocity'          => lmeg_si_stream_velocity((array) ($daily['streams'] ?? [])),
        'streams_pop'       => $sp,
        'monthly_listeners' => (int) $snap->monthly_listeners,
        'followers'         => $fs ? (int) end($fs) : null,
        'save_rate'         => ($snap->monthly_listeners > 0 && $snap->saves !== null) ? (int) $snap->saves / (int) $snap->monthly_listeners * 100 : null,
    ] + $extra_ctx;
    $F = array_slice(lmeg_si_analyze($ctx), 0, 3);
    if ($F) {
        $html .= '<p style="margin:14px 0 6px;font-weight:600;">What to do</p><ul style="margin:0;padding-left:18px;">';
        foreach ($F as $f) {
            $links = [];
            foreach (array_slice(lmeg_si_finding_actions($f), 0, 2) as $a) {
                $href = !empty($a['href']) ? $a['href'] : admin_url('admin.php?' . http_build_query(array_merge(['page' => $a['page']], (array) ($a['args'] ?? []))));
                $links[] = '<a href="' . esc_url($href) . '">' . esc_html($a['label']) . '</a>';
            }
            $html .= '<li style="margin:0 0 8px;"><strong>' . esc_html($f['title']) . '</strong> — ' . esc_html($f['detail']) . ($links ? ' <span style="white-space:nowrap;">' . implode(' · ', $links) . '</span>' : '') . '</li>';
        }
        $html .= '</ul>';
    }
    $html .= '<p style="margin:10px 0 0;"><a href="' . esc_url(admin_url('admin.php?page=lmeg-spotify-insights')) . '">Open Spotify Insights →</a></p>';
    return $html;
}

/** ", streams +4.1%" for the digest subject line, or '' when unknown. */
function lmeg_si_digest_subject_bit() {
    if (!function_exists('lmeg_s4a_latest') || !function_exists('lmeg_s4a_prev')) return '';
    $sel = lmeg_artist(); $snap = lmeg_s4a_latest($sel); if (!$snap) return '';
    $prev = lmeg_s4a_prev($sel, $snap->window, $snap->captured_date);
    if (!$prev || (int) $prev->streams <= 0) return '';
    $p = round(((int) $snap->streams - (int) $prev->streams) / (int) $prev->streams * 100, 1);
    return ', streams ' . ($p >= 0 ? '+' : '') . $p . '%';
}

/**
 * Link clicks per Fanloop release page, in ONE query: [drop_id => ['total',
 * 'known' (distinct signed-in fans)]] for the given drop ids. Lets the
 * Releases card put "what your release page did" (clicks) next to "what
 * Spotify did" (streams). [] when nothing to look up or on error.
 */
function lmeg_si_release_clicks_map($drop_ids) {
    global $wpdb;
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $drop_ids))));
    if (!$ids || empty($wpdb) || !function_exists('lmeg_link_clicks_table')) return [];
    $rows = $wpdb->get_results("SELECT drop_id, COUNT(*) total, COUNT(DISTINCT CASE WHEN subscriber_id > 0 THEN subscriber_id END) known
                                  FROM " . lmeg_link_clicks_table() . " WHERE drop_id IN (" . implode(',', $ids) . ") GROUP BY drop_id", ARRAY_A);
    if ($wpdb->last_error) { $wpdb->last_error = ''; return []; }
    $out = [];
    foreach ((array) $rows as $r) $out[(int) $r['drop_id']] = ['total' => (int) $r['total'], 'known' => (int) $r['known']];
    return $out;
}

/**
 * Day-of-week profile from the per-song daily series (top songs, last $weeks
 * weeks, summed across songs): average streams per weekday, the peak and the
 * trough as % vs the all-days mean. 1 = Monday … 7 = Sunday. null when the
 * data covers fewer than 4 full weeks. Pure.
 */
function lmeg_si_weekday_profile($entries, $weeks = 12) {
    $sum = array_fill(1, 7, 0); $days = 0;
    foreach ((array) $entries as $e) {
        if (!is_array($e) || empty($e['s']) || empty($e['d0'])) continue;
        $s = array_values(array_map('intval', (array) $e['s'])); $n = count($s);
        $take = min($n - ($n % 7), $weeks * 7);
        if ($take < 28) continue;
        $t0 = strtotime($e['d0'] . ' +' . ($n - $take) . ' days');
        for ($i = 0; $i < $take; $i++) $sum[(int) date('N', $t0 + $i * 86400)] += $s[$n - $take + $i];
        $days = max($days, $take);
    }
    if ($days < 28 || array_sum($sum) <= 0) return null;
    $wk = intdiv($days, 7);
    $avg = []; foreach ($sum as $d => $v) $avg[$d] = $v / $wk;
    $mean = array_sum($avg) / 7;
    $peak = 1; $trough = 1;
    foreach ($avg as $d => $v) { if ($v > $avg[$peak]) $peak = $d; if ($v < $avg[$trough]) $trough = $d; }
    return ['avg' => $avg, 'mean' => $mean, 'weeks' => $wk, 'peak' => $peak, 'trough' => $trough,
            'peak_pct' => $mean > 0 ? round(($avg[$peak] - $mean) / $mean * 100, 1) : 0,
            'trough_pct' => $mean > 0 ? round(($avg[$trough] - $mean) / $mean * 100, 1) : 0];
}

/**
 * Long-range catalogue trend from the per-song daily series (top songs, up to
 * 365 days): streams summed across songs per calendar month (the current
 * month flagged partial with its day count), plus the last 90 days vs the 90
 * before. Honest label: it's the TOP songs' streams, not the whole catalogue.
 * null when fewer than ~2 months of data. Pure.
 */
function lmeg_si_catalogue_monthly($entries, $months = 12) {
    $day = [];
    foreach ((array) $entries as $e) {
        if (!is_array($e) || empty($e['s']) || empty($e['d0'])) continue;
        $t0 = strtotime($e['d0']); if (!$t0) continue;
        foreach (array_values((array) $e['s']) as $i => $v) { $k = date('Y-m-d', $t0 + $i * 86400); $day[$k] = ($day[$k] ?? 0) + (int) $v; }
    }
    if (count($day) < 45) return null;
    ksort($day);
    $by = [];
    foreach ($day as $d => $v) { $ym = substr($d, 0, 7); if (!isset($by[$ym])) $by[$ym] = ['ym' => $ym, 'streams' => 0, 'days' => 0]; $by[$ym]['streams'] += $v; $by[$ym]['days']++; }
    $by = array_values($by);
    $by = array_slice($by, -$months);
    $last = end($day); $lastDate = array_key_last($day);
    foreach ($by as &$m) {
        $dim = (int) date('t', strtotime($m['ym'] . '-01'));
        $m['partial'] = $m['days'] < $dim;
        $m['label'] = date_i18n('M', strtotime($m['ym'] . '-01'));
        $m['year']  = (int) substr($m['ym'], 0, 4);
    }
    unset($m);
    // Best FULL month, and last 90 days vs the 90 before.
    $best = null; foreach ($by as $m) { if (!$m['partial'] && ($best === null || $m['streams'] > $best['streams'])) $best = $m; }
    $vals = array_values($day); $n = count($vals); $q = null;
    if ($n >= 180) { $l90 = array_sum(array_slice($vals, -90)); $p90 = array_sum(array_slice($vals, -180, 90)); if ($p90 > 0) $q = ['last' => $l90, 'prior' => $p90, 'pct' => round(($l90 - $p90) / $p90 * 100, 1)]; }
    return ['months' => $by, 'best' => $best, 'q90' => $q, 'days' => $n, 'through' => $lastDate];
}

/**
 * Playlist movement between two captures: playlists in today's top list that
 * weren't in the previous one ('new') and editorial ones that fell out
 * ('gone', conservative — only curated with a meaningful share, since a small
 * playlist can drop below the list cutoff without anything happening). Keyed
 * by uri, title as fallback. Each entry: title, author, type, followers,
 * streams. Pure.
 */
function lmeg_si_playlist_diff($cur, $prev) {
    $key = function ($p) { $u = (string) ($p['uri'] ?? ''); return $u !== '' ? $u : strtolower(trim((string) ($p['title'] ?? $p['name'] ?? ''))); };
    $norm = function ($p) { return ['title' => (string) ($p['title'] ?? $p['name'] ?? ''), 'author' => (string) ($p['author'] ?? ''), 'type' => (string) ($p['type'] ?? ''),
                                     'followers' => (isset($p['followers']) && $p['followers'] !== null && $p['followers'] !== '') ? (int) $p['followers'] : null, 'streams' => (int) ($p['streams'] ?? 0)]; };
    $cur  = array_values(array_filter((array) $cur, 'is_array')); $prev = array_values(array_filter((array) $prev, 'is_array'));
    if (!$cur || !$prev) return ['new' => [], 'gone' => []];
    $pk = []; foreach ($prev as $p) $pk[$key($p)] = $p;
    $ck = []; foreach ($cur as $p) $ck[$key($p)] = $p;
    $new = []; foreach ($cur as $p) { $k = $key($p); if ($k !== '' && !isset($pk[$k]) && $norm($p)['title'] !== '') $new[] = $norm($p); }
    $ptotal = 0; foreach ($prev as $p) $ptotal += (int) ($p['streams'] ?? 0);
    $gone = []; foreach ($prev as $p) { $k = $key($p); $n = $norm($p); if ($k !== '' && !isset($ck[$k]) && $n['type'] === 'curated' && $ptotal > 0 && $n['streams'] / $ptotal >= 0.05) $gone[] = $n; }
    usort($new, function ($a, $b) { return ($b['followers'] ?? 0) <=> ($a['followers'] ?? 0); });
    return ['new' => $new, 'gone' => $gone];
}

/**
 * Launch comparison — for releases whose title matches a song with day-by-day
 * data (singles, mostly): streams in the first 7 and first 28 days after the
 * release date, so launches can be compared like-for-like. Needs the daily
 * series to cover the release date + 7 days. Returns [{name,date,first7,
 * first28 (null when <28 days have passed),days}] newest first. Pure.
 */
function lmeg_si_launch_compare($api_releases, $sd_map) {
    $out = [];
    foreach ((array) $api_releases as $r) {
        if (!is_array($r) || empty($r['name']) || empty($r['date']) || strlen((string) $r['date']) < 10) continue;
        // API release names can arrive HTML-escaped ("Ain&#039;t No Sunshine") — decode before matching/display.
        $r['name'] = html_entity_decode((string) $r['name'], ENT_QUOTES, 'UTF-8');
        $e = $sd_map[lmeg_si_song_key((string) $r['name'])] ?? null;
        if (!$e || empty($e['s']) || empty($e['d0'])) continue;
        $s = array_values(array_map('intval', (array) $e['s']));
        $i = (int) round((strtotime((string) $r['date']) - strtotime((string) $e['d0'])) / 86400);
        if ($i < 0 || $i + 6 >= count($s)) continue;   // series must cover release day + 7
        $avail = count($s) - $i;
        $out[] = ['name' => (string) $r['name'], 'date' => substr((string) $r['date'], 0, 10), 'type' => (string) ($r['type'] ?? ''),
                  'first7' => array_sum(array_slice($s, $i, 7)), 'first28' => $avail >= 28 ? array_sum(array_slice($s, $i, 28)) : null, 'days' => $avail];
    }
    usort($out, function ($a, $b) { return strcmp($b['date'], $a['date']); });
    return $out;
}

/** Monday…Sunday names for the profile (1..7). */
function lmeg_si_weekday_name($n, $plural = false) {
    $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    $s = $names[(int) $n] ?? '';
    return $plural && $s ? $s . 's' : $s;
}

/** Normalized title key shared by the song-daily map and the row lookup. */
function lmeg_si_song_key($s) {
    $s = trim((string) $s);
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    return preg_replace('/\s+/', ' ', $s);
}

/**
 * meta.song_daily ([{t,u,d0,s,li,sv}] from lmeg_s4a_song_daily) → [normTitle =>
 * entry] for O(1) row lookup; entries without a usable streams series are
 * dropped and `s` is coerced to ints. Pure.
 */
function lmeg_si_song_daily_map($list) {
    $out = [];
    foreach ((array) $list as $e) {
        if (!is_array($e) || empty($e['s']) || !is_array($e['s']) || trim((string) ($e['t'] ?? '')) === '') continue;
        $e['s'] = array_map('intval', array_values($e['s']));
        $out[lmeg_si_song_key($e['t'])] = $e;
    }
    return $out;
}

/**
 * TRUE week-over-week from a daily series: sum of the last 7 values vs the 7
 * before, as a % (1dp). null when fewer than 14 days or the prior week is 0. Pure.
 */
function lmeg_si_week_over_week($vals) {
    $vals = array_values(array_map('intval', (array) $vals));
    if (count($vals) < 14) return null;
    $last = array_sum(array_slice($vals, -7));
    $prev = array_sum(array_slice($vals, -14, 7));
    if ($prev <= 0) return null;
    return round(($last - $prev) / $prev * 100, 1);
}

/**
 * Catalog momentum from REAL per-song day-by-day data (top 20). Input: the map
 * from lmeg_si_song_daily_map. Returns null when nothing qualifies, else
 * ['up'=>[…],'down'=>[…],'lead'=>entry|null,'n'=>int] where each entry is
 * ['title','wow','last7','prior7'] — up/down sorted by |wow| desc, both gated
 * (bigger week ≥ $min_week streams AND smaller week ≥ a third of it) so tiny
 * catalogue tails can't post a "+700%" from 35 → 280; lead is the song with the
 * most streams in the last 7 days (ungated). Pure.
 */
function lmeg_si_song_wow_summary($map, $min_week = 150) {
    $rows = [];
    foreach ((array) $map as $e) {
        if (!is_array($e) || empty($e['s'])) continue;
        $vals = array_values(array_map('intval', (array) $e['s']));
        if (count($vals) < 14) continue;
        $last7 = array_sum(array_slice($vals, -7)); $prior7 = array_sum(array_slice($vals, -14, 7));
        $wow = ($prior7 > 0) ? round(($last7 - $prior7) / $prior7 * 100, 1) : null;
        $rows[] = ['title' => (string) ($e['t'] ?? ''), 'uri' => (string) ($e['u'] ?? ''), 'wow' => $wow, 'last7' => $last7, 'prior7' => $prior7];
    }
    if (!$rows) return null;
    $lead = null; foreach ($rows as $r) { if ($lead === null || $r['last7'] > $lead['last7']) $lead = $r; }
    $q = array_values(array_filter($rows, function ($r) use ($min_week) {
        return $r['wow'] !== null && max($r['last7'], $r['prior7']) >= $min_week && min($r['last7'], $r['prior7']) >= $min_week / 3;
    }));
    $up = array_values(array_filter($q, function ($r) { return $r['wow'] >= 10; }));
    $down = array_values(array_filter($q, function ($r) { return $r['wow'] <= -10; }));
    usort($up, function ($a, $b) { return $b['wow'] <=> $a['wow']; });
    usort($down, function ($a, $b) { return $a['wow'] <=> $b['wow']; });
    return ['up' => $up, 'down' => $down, 'lead' => $lead, 'n' => count($rows)];
}

/**
 * Tiny inline SVG sparkline (soft area + line + endpoint dot) for a series of
 * ints, zero baseline, fixed box. '' with fewer than 2 points. Coordinates are
 * formatted with number_format (sprintf %f is locale-sensitive). Pure.
 */
function lmeg_si_sparkline($vals, $w = 96, $h = 26, $color = '#34D399') {
    $vals = array_values(array_map('intval', (array) $vals));
    $n = count($vals);
    if ($n < 2) return '';
    $mx = max(1, max($vals));
    $p = 3; $iw = $w - 2 * $p; $ih = $h - 2 * $p;
    $f = function ($v) { return number_format((float) $v, 1, '.', ''); };
    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = $f($p + $i * $iw / ($n - 1)) . ',' . $f($p + (1 - max(0, $v) / $mx) * $ih);
    }
    $line  = implode(' ', $pts);
    $area  = $line . ' ' . $f($p + $iw) . ',' . $f($h - $p) . ' ' . $f($p) . ',' . $f($h - $p);
    $color = preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) ? $color : '#34D399';
    list($lx, $ly) = explode(',', end($pts));
    return '<svg viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" width="' . (int) $w . '" height="' . (int) $h . '" style="display:block;overflow:visible;" aria-hidden="true">'
        . '<polygon points="' . $area . '" fill="' . $color . '" fill-opacity=".12"/>'
        . '<polyline points="' . $line . '" fill="none" stroke="' . $color . '" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/>'
        . '<circle cx="' . $lx . '" cy="' . $ly . '" r="2.2" fill="' . $color . '"/>'
        . '</svg>';
}

/**
 * Per-song history across daily snapshots — one point per capture. Input: rows
 * from lmeg_s4a_history() (captured_date, top_songs JSON [{title,streams,
 * listeners,saves}], meta JSON {songs_7d:[{title,streams}]}); rows may be objects
 * (wpdb) or arrays (harness). Returns [normTitle => ['title'=>display,'pts'=>[
 * ['d'=>date,'s28'=>int,'s7'=>int|null,'li'=>int|null,'sv'=>int|null], …]]],
 * points ascending by date. Keyed by a normalized title so capitalization drift
 * between captures still lands on ONE series. Pure.
 */
function lmeg_si_song_history($rows) {
    $norm = function ($s) {
        $s = trim((string) $s);
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        return preg_replace('/\s+/', ' ', $s);
    };
    $get = function ($r, $k) { return is_object($r) ? ($r->$k ?? null) : ($r[$k] ?? null); };
    $out = [];
    foreach ((array) $rows as $r) {
        $date = (string) $get($r, 'captured_date');
        if ($date === '') continue;
        $songs = json_decode((string) ($get($r, 'top_songs') ?? '[]'), true);
        // Slim rows carry just meta.songs_7d (JSON_EXTRACT); full rows carry meta.
        $s7raw = $get($r, 'songs_7d');
        if ($s7raw !== null && $s7raw !== '') { $s7list = is_string($s7raw) ? json_decode($s7raw, true) : $s7raw; }
        else { $meta = json_decode((string) ($get($r, 'meta') ?? '{}'), true); $s7list = $meta['songs_7d'] ?? []; }
        $s7 = [];
        foreach ((array) $s7list as $s) { if (is_array($s)) $s7[$norm($s['title'] ?? '')] = (int) ($s['streams'] ?? 0); }
        foreach ((array) $songs as $s) {
            if (!is_array($s)) continue;
            $t = (string) ($s['title'] ?? $s['trackName'] ?? '');
            if ($t === '') continue;
            $k = $norm($t);
            if (!isset($out[$k])) $out[$k] = ['title' => $t, 'pts' => []];
            $out[$k]['pts'][] = [
                'd'   => $date,
                's28' => (int) ($s['streams'] ?? 0),
                's7'  => isset($s7[$k]) ? $s7[$k] : null,
                'li'  => isset($s['listeners']) ? (int) $s['listeners'] : null,
                'sv'  => isset($s['saves']) ? (int) $s['saves'] : null,
            ];
        }
    }
    foreach ($out as &$e) { usort($e['pts'], function ($a, $b) { return strcmp($a['d'], $b['d']); }); } unset($e);
    return $out;
}

/**
 * Reconcile the two track sources: S4A songs (carry STREAMS) vs the public
 * Spotify API top-tracks (carry POPULARITY, 0–100). Matches by normalized title.
 * Returns:
 *  - pop_by_title: [normTitle => popularity] to enrich the S4A song rows,
 *  - api_only: API tracks not in the S4A catalog (usually features/collabs on
 *    other artists' releases), each {name, popularity, album},
 *  - both / s4a_only: counts for the reconciliation summary.
 */
function lmeg_si_reconcile_tracks($s4a_songs, $api_tracks) {
    $norm = function ($s) { return preg_replace('/\s+/', ' ', strtolower(trim((string) $s))); };
    $api = [];
    foreach ((array) $api_tracks as $t) {
        if (!is_array($t)) continue;
        $nm = $norm($t['name'] ?? '');
        if ($nm === '') continue;
        $pop = (int) ($t['popularity'] ?? 0);
        if (!isset($api[$nm]) || $pop > $api[$nm]['popularity']) {
            $api[$nm] = ['name' => (string) ($t['name'] ?? ''), 'popularity' => $pop, 'album' => (string) ($t['album'] ?? '')];
        }
    }
    $pop_by_title = []; $matched = []; $s4a_only = 0;
    foreach ((array) $s4a_songs as $s) {
        if (!is_array($s)) continue;
        $nm = $norm($s['title'] ?? $s['trackName'] ?? '');
        if ($nm === '') continue;
        if (isset($api[$nm])) { $pop_by_title[$nm] = $api[$nm]['popularity']; $matched[$nm] = true; }
        else { $s4a_only++; }
    }
    $api_only = [];
    foreach ($api as $nm => $t) if (empty($matched[$nm])) $api_only[] = $t;
    usort($api_only, function ($a, $b) { return $b['popularity'] <=> $a['popularity']; });
    return ['pop_by_title' => $pop_by_title, 'api_only' => $api_only, 'both' => count($matched), 's4a_only' => $s4a_only];
}

/** Extract a Spotify album id (22 chars) from a spotify:album:ID uri OR an
 *  open.spotify.com/album/ID url. Returns '' when none. */
function lmeg_si_spotify_album_id($s) {
    $s = (string) $s;
    if (preg_match('~spotify:album:([A-Za-z0-9]{22})~', $s, $m)) return $m[1];
    if (preg_match('~open\.spotify\.com/album/([A-Za-z0-9]{22})~', $s, $m)) return $m[1];
    return '';
}

/**
 * Filter + sort the compact release summary (from snapshot meta) by 28-day
 * streams, descending. Drops entries with no name. Returns [] when empty.
 */
function lmeg_si_releases_sorted($releases) {
    $out = [];
    foreach ((array) $releases as $r) {
        if (!is_array($r)) continue;
        $name = trim((string) ($r['name'] ?? ''));
        if ($name === '') continue;
        $out[] = [
            'name'    => $name,
            'streams' => (int) ($r['streams'] ?? 0),
            'type'    => (string) ($r['type'] ?? ''),
            'date'    => (string) ($r['date'] ?? ''),
            'uri'     => (string) ($r['uri'] ?? ''),
        ];
    }
    usort($out, function ($a, $b) { return $b['streams'] <=> $a['streams']; });
    return $out;
}

/**
 * Derive plain-language "so what" insight callouts from the snapshot + meta.
 * Each is ['label','value','detail']; only callouts with real data are returned.
 */
function lmeg_si_callouts($snap, $meta) {
    $out = [];
    $meta = (array) $meta;
    $ml    = ($snap && $snap->monthly_listeners !== null) ? (int) $snap->monthly_listeners : 0;
    $saves = ($snap && $snap->saves !== null) ? (int) $snap->saves : 0;

    if ($ml > 0 && $saves > 0) {
        $rate = $saves / $ml * 100;
        $out[] = ['label' => 'Save rate', 'value' => rtrim(rtrim(number_format($rate, 1), '0'), '.') . '%',
                  'detail' => number_format_i18n($saves) . ' saves · ' . number_format_i18n($ml) . ' listeners'];
    }
    if (isset($meta['pct_streams_from_mal']) && $meta['pct_streams_from_mal'] !== null && $meta['pct_streams_from_mal'] !== '') {
        $p = (float) $meta['pct_streams_from_mal']; if ($p > 0 && $p <= 1) $p *= 100;
        if ($p > 0) $out[] = ['label' => 'From active fans', 'value' => round($p) . '%',
                  'detail' => 'of streams come from your most active listeners'];
    }
    $ages = lmeg_si_age_rows($meta['gender_by_age'] ?? null);
    if ($ages) {
        $tot = 0; $top = $ages[0];
        foreach ($ages as $r) { $tot += $r['total']; if ($r['total'] > $top['total']) $top = $r; }
        if ($tot > 0) $out[] = ['label' => 'Core audience', 'value' => $top['label'],
                  'detail' => round($top['total'] / $tot * 100) . '% of listeners are this age'];
    }
    $cities = array_values(array_filter((array) ($meta['top_cities'] ?? []), 'is_array'));
    if ($cities) {
        $c = $cities[0]; $loc = trim(implode(', ', array_filter([(string) ($c['region'] ?? ''), (string) ($c['country'] ?? '')])));
        $detail = $loc; if (isset($c['num'])) $detail = trim($loc . ' · ' . number_format_i18n((int) $c['num']) . ' listeners', ' ·');
        $out[] = ['label' => 'Biggest city', 'value' => (string) ($c['name'] ?? ''), 'detail' => $detail];
    }
    $songs = array_values(array_filter((array) json_decode((string) ($snap->top_songs ?? '[]'), true), 'is_array'));
    if (count($songs) >= 2) {
        $sum = 0; $max = 0; $maxt = '';
        foreach ($songs as $s) { $v = (int) ($s['streams'] ?? 0); $sum += $v; if ($v > $max) { $max = $v; $maxt = (string) ($s['title'] ?? ''); } }
        if ($sum > 0 && $maxt !== '') $out[] = ['label' => 'Top-track share', 'value' => round($max / $sum * 100) . '%',
                  'detail' => 'of listed streams: "' . $maxt . '"'];
    }
    return $out;
}

/** Normalize a gender distribution — Spotify {female,male,nonbinary,unknown} OR
 *  Instagram {F,M,U} — into comparable Women/Men/Non-binary/Unknown percentages.
 *  Returns ['total'=>N,'rows'=>[{label,color,pct},…]] or null when empty. */
function lmeg_si_norm_gender($g) {
    if (!is_array($g)) return null;
    $women = (int) ($g['female'] ?? $g['F'] ?? $g['FEMALE'] ?? 0);
    $men   = (int) ($g['male'] ?? $g['M'] ?? $g['MALE'] ?? 0);
    $nb    = (int) ($g['nonbinary'] ?? $g['nonBinary'] ?? $g['NONBINARY'] ?? 0);
    $unk   = (int) ($g['unknown'] ?? $g['U'] ?? $g['UNKNOWN'] ?? 0);
    $tot = $women + $men + $nb + $unk;
    if ($tot <= 0) return null;
    $rows = [];
    foreach ([['Women', '#D05FA2', $women], ['Men', '#7C6CF6', $men], ['Non-binary', '#34D399', $nb], ['Unknown', '#8B90A0', $unk]] as $r) {
        if ($r[2] > 0) $rows[] = ['label' => $r[0], 'color' => $r[1], 'pct' => $r[2] / $tot * 100];
    }
    return ['total' => $tot, 'rows' => $rows];
}

/** Normalize an age distribution to canonical buckets as percentages. Accepts
 *  the Spotify shape ([{label,total}], from lmeg_si_age_rows) or the Instagram
 *  shape ({bucket=>count}). Returns {canonBucket=>pct} or null when empty. */
function lmeg_si_norm_age($src) {
    $canon = ['<18', '18-24', '25-34', '35-44', '45-54', '55-64', '65+'];
    $map = ['0–17' => '<18', '0-17' => '<18', '13-17' => '<18', '13–17' => '<18'];
    $counts = array_fill_keys($canon, 0); $tot = 0;
    $add = function ($label, $v) use (&$counts, &$tot, $map, $canon) {
        $l = str_replace('–', '-', trim((string) $label));
        $l = $map[$l] ?? $l;
        if (!in_array($l, $canon, true)) return;
        $counts[$l] += (int) $v; $tot += (int) $v;
    };
    if (isset($src[0]) && is_array($src[0])) { foreach ((array) $src as $r) $add($r['label'] ?? '', $r['total'] ?? 0); }
    else { foreach ((array) $src as $k => $v) $add($k, $v); }
    if ($tot <= 0) return null;
    $out = [];
    foreach ($canon as $b) $out[$b] = $counts[$b] / $tot * 100;
    return $out;
}

/** Normalize a city source to a ranked [{name,count,sub}] list. Accepts the
 *  Spotify shape ([{name,num,region,country}]) or the Instagram shape
 *  ({cityName=>count}). Sorted by count desc, trimmed to $limit. */
function lmeg_si_city_list($src, $limit = 8) {
    $out = [];
    if (isset($src[0]) && is_array($src[0])) { // Spotify
        foreach ((array) $src as $c) {
            if (!is_array($c)) continue;
            $out[] = ['name' => (string) ($c['name'] ?? ''), 'count' => (int) ($c['num'] ?? 0),
                      'sub' => trim(implode(', ', array_filter([(string) ($c['region'] ?? ''), (string) ($c['country'] ?? '')])))];
        }
    } else { // Instagram {name=>count}
        foreach ((array) $src as $k => $v) $out[] = ['name' => (string) $k, 'count' => (int) $v, 'sub' => ''];
    }
    $out = array_values(array_filter($out, function ($c) { return $c['name'] !== '' && $c['count'] > 0; }));
    usort($out, function ($a, $b) { return $b['count'] <=> $a['count']; });
    return array_slice($out, 0, $limit);
}

/** ISO 3166-1 alpha-2 → English country name (for the S4A locations endpoint,
 *  which returns bare 2-letter codes). Unknown codes fall back to the code. */
function lmeg_si_country_name($cc) {
    $cc = strtoupper(trim((string) $cc));
    static $m = [
        'AD'=>'Andorra','AE'=>'United Arab Emirates','AF'=>'Afghanistan','AG'=>'Antigua & Barbuda','AI'=>'Anguilla','AL'=>'Albania','AM'=>'Armenia','AO'=>'Angola','AQ'=>'Antarctica','AR'=>'Argentina','AS'=>'American Samoa','AT'=>'Austria','AU'=>'Australia','AW'=>'Aruba','AX'=>'Åland Islands','AZ'=>'Azerbaijan',
        'BA'=>'Bosnia & Herzegovina','BB'=>'Barbados','BD'=>'Bangladesh','BE'=>'Belgium','BF'=>'Burkina Faso','BG'=>'Bulgaria','BH'=>'Bahrain','BI'=>'Burundi','BJ'=>'Benin','BL'=>'St. Barthélemy','BM'=>'Bermuda','BN'=>'Brunei','BO'=>'Bolivia','BQ'=>'Caribbean Netherlands','BR'=>'Brazil','BS'=>'Bahamas','BT'=>'Bhutan','BW'=>'Botswana','BY'=>'Belarus','BZ'=>'Belize',
        'CA'=>'Canada','CC'=>'Cocos Islands','CD'=>'DR Congo','CF'=>'Central African Republic','CG'=>'Congo','CH'=>'Switzerland','CI'=>'Côte d’Ivoire','CK'=>'Cook Islands','CL'=>'Chile','CM'=>'Cameroon','CN'=>'China','CO'=>'Colombia','CR'=>'Costa Rica','CU'=>'Cuba','CV'=>'Cape Verde','CW'=>'Curaçao','CX'=>'Christmas Island','CY'=>'Cyprus','CZ'=>'Czechia',
        'DE'=>'Germany','DJ'=>'Djibouti','DK'=>'Denmark','DM'=>'Dominica','DO'=>'Dominican Republic','DZ'=>'Algeria',
        'EC'=>'Ecuador','EE'=>'Estonia','EG'=>'Egypt','EH'=>'Western Sahara','ER'=>'Eritrea','ES'=>'Spain','ET'=>'Ethiopia',
        'FI'=>'Finland','FJ'=>'Fiji','FK'=>'Falkland Islands','FM'=>'Micronesia','FO'=>'Faroe Islands','FR'=>'France',
        'GA'=>'Gabon','GB'=>'United Kingdom','GD'=>'Grenada','GE'=>'Georgia','GF'=>'French Guiana','GG'=>'Guernsey','GH'=>'Ghana','GI'=>'Gibraltar','GL'=>'Greenland','GM'=>'Gambia','GN'=>'Guinea','GP'=>'Guadeloupe','GQ'=>'Equatorial Guinea','GR'=>'Greece','GT'=>'Guatemala','GU'=>'Guam','GW'=>'Guinea-Bissau','GY'=>'Guyana',
        'HK'=>'Hong Kong','HN'=>'Honduras','HR'=>'Croatia','HT'=>'Haiti','HU'=>'Hungary',
        'ID'=>'Indonesia','IE'=>'Ireland','IL'=>'Israel','IM'=>'Isle of Man','IN'=>'India','IO'=>'British Indian Ocean Territory','IQ'=>'Iraq','IR'=>'Iran','IS'=>'Iceland','IT'=>'Italy',
        'JE'=>'Jersey','JM'=>'Jamaica','JO'=>'Jordan','JP'=>'Japan',
        'KE'=>'Kenya','KG'=>'Kyrgyzstan','KH'=>'Cambodia','KI'=>'Kiribati','KM'=>'Comoros','KN'=>'St. Kitts & Nevis','KP'=>'North Korea','KR'=>'South Korea','KW'=>'Kuwait','KY'=>'Cayman Islands','KZ'=>'Kazakhstan',
        'LA'=>'Laos','LB'=>'Lebanon','LC'=>'St. Lucia','LI'=>'Liechtenstein','LK'=>'Sri Lanka','LR'=>'Liberia','LS'=>'Lesotho','LT'=>'Lithuania','LU'=>'Luxembourg','LV'=>'Latvia','LY'=>'Libya',
        'MA'=>'Morocco','MC'=>'Monaco','MD'=>'Moldova','ME'=>'Montenegro','MF'=>'St. Martin','MG'=>'Madagascar','MH'=>'Marshall Islands','MK'=>'North Macedonia','ML'=>'Mali','MM'=>'Myanmar','MN'=>'Mongolia','MO'=>'Macau','MP'=>'Northern Mariana Islands','MQ'=>'Martinique','MR'=>'Mauritania','MS'=>'Montserrat','MT'=>'Malta','MU'=>'Mauritius','MV'=>'Maldives','MW'=>'Malawi','MX'=>'Mexico','MY'=>'Malaysia','MZ'=>'Mozambique',
        'NA'=>'Namibia','NC'=>'New Caledonia','NE'=>'Niger','NF'=>'Norfolk Island','NG'=>'Nigeria','NI'=>'Nicaragua','NL'=>'Netherlands','NO'=>'Norway','NP'=>'Nepal','NR'=>'Nauru','NU'=>'Niue','NZ'=>'New Zealand',
        'OM'=>'Oman',
        'PA'=>'Panama','PE'=>'Peru','PF'=>'French Polynesia','PG'=>'Papua New Guinea','PH'=>'Philippines','PK'=>'Pakistan','PL'=>'Poland','PM'=>'St. Pierre & Miquelon','PN'=>'Pitcairn Islands','PR'=>'Puerto Rico','PS'=>'Palestine','PT'=>'Portugal','PW'=>'Palau','PY'=>'Paraguay',
        'QA'=>'Qatar',
        'RE'=>'Réunion','RO'=>'Romania','RS'=>'Serbia','RU'=>'Russia','RW'=>'Rwanda',
        'SA'=>'Saudi Arabia','SB'=>'Solomon Islands','SC'=>'Seychelles','SD'=>'Sudan','SE'=>'Sweden','SG'=>'Singapore','SH'=>'St. Helena','SI'=>'Slovenia','SJ'=>'Svalbard & Jan Mayen','SK'=>'Slovakia','SL'=>'Sierra Leone','SM'=>'San Marino','SN'=>'Senegal','SO'=>'Somalia','SR'=>'Suriname','SS'=>'South Sudan','ST'=>'São Tomé & Príncipe','SV'=>'El Salvador','SX'=>'Sint Maarten','SY'=>'Syria','SZ'=>'Eswatini',
        'TC'=>'Turks & Caicos','TD'=>'Chad','TF'=>'French Southern Territories','TG'=>'Togo','TH'=>'Thailand','TJ'=>'Tajikistan','TK'=>'Tokelau','TL'=>'Timor-Leste','TM'=>'Turkmenistan','TN'=>'Tunisia','TO'=>'Tonga','TR'=>'Türkiye','TT'=>'Trinidad & Tobago','TV'=>'Tuvalu','TW'=>'Taiwan','TZ'=>'Tanzania',
        'UA'=>'Ukraine','UG'=>'Uganda','US'=>'United States','UY'=>'Uruguay','UZ'=>'Uzbekistan',
        'VA'=>'Vatican City','VC'=>'St. Vincent & Grenadines','VE'=>'Venezuela','VG'=>'British Virgin Islands','VI'=>'U.S. Virgin Islands','VN'=>'Vietnam','VU'=>'Vanuatu',
        'WF'=>'Wallis & Futuna','WS'=>'Samoa','XK'=>'Kosovo','YE'=>'Yemen','YT'=>'Mayotte','ZA'=>'South Africa','ZM'=>'Zambia','ZW'=>'Zimbabwe',
    ];
    return $m[$cc] ?? ($cc !== '' ? $cc : 'Unknown');
}

/** Flag emoji for a 2-letter country code (regional-indicator pair), or '' if
 *  the code isn't two letters. Built as raw UTF-8, no mb_* (PHP 8.2-safe). */
function lmeg_si_country_flag($cc) {
    $cc = strtoupper(trim((string) $cc));
    if (strlen($cc) !== 2 || !ctype_alpha($cc)) return '';
    $enc = function ($cp) { // 4-byte UTF-8 (regional indicators live above U+FFFF)
        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F))
             . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    };
    return $enc(127397 + ord($cc[0])) . $enc(127397 + ord($cc[1]));
}

/** Rank the rich per-country breakdown (from meta.countries: [{cc,num,act,pct}])
 *  into render rows with resolved name/flag and a bar share relative to the top
 *  country. Sorted by monthly listeners desc, trimmed to $limit. Pure. */
function lmeg_si_country_rows($countries, $limit = 8) {
    $rows = [];
    foreach ((array) $countries as $c) {
        if (!is_array($c)) continue;
        $cc  = strtoupper((string) ($c['cc'] ?? $c['name'] ?? ''));
        $num = (int) ($c['num'] ?? 0);
        if ($cc === '' || strlen($cc) !== 2 || $num <= 0) continue;
        $rows[] = ['cc' => $cc, 'name' => lmeg_si_country_name($cc), 'flag' => lmeg_si_country_flag($cc),
                   'num' => $num, 'pct' => isset($c['pct']) && $c['pct'] !== null ? (float) $c['pct'] : null];
    }
    usort($rows, function ($a, $b) { return $b['num'] <=> $a['num']; });
    $rows = array_slice($rows, 0, max(1, (int) $limit));
    $max  = 0; foreach ($rows as $r) $max = max($max, $r['num']);
    foreach ($rows as &$r) { $r['share'] = $max > 0 ? round($r['num'] / $max * 100, 1) : 0.0; } unset($r);
    return $rows;
}

/**
 * Market movement between two captures' per-country listener counts
 * (meta.countries: [{cc,num,…}]). Returns ['up'=>[…],'down'=>[…],'all'=>[cc=>pct]]
 * with entries ['cc','name','num','prev','pct'] — up/down only for markets with
 * ≥ $min_num listeners now and |change| ≥ $min_pct, sorted by |pct|. Pure.
 */
function lmeg_si_country_movers($cur, $prev, $min_num = 500, $min_pct = 10) {
    $ix = function ($list) { $o = []; foreach ((array) $list as $c) { if (!is_array($c)) continue; $cc = strtoupper((string) ($c['cc'] ?? '')); if (strlen($cc) === 2) $o[$cc] = (int) ($c['num'] ?? 0); } return $o; };
    $c = $ix($cur); $p = $ix($prev);
    $up = []; $down = []; $all = [];
    foreach ($c as $cc => $num) {
        if (!isset($p[$cc]) || $p[$cc] <= 0) continue;
        $pct = round(($num - $p[$cc]) / $p[$cc] * 100, 1);
        $all[$cc] = $pct;
        if ($num < $min_num) continue;
        $e = ['cc' => $cc, 'name' => lmeg_si_country_name($cc), 'num' => $num, 'prev' => $p[$cc], 'pct' => $pct];
        if ($pct >= $min_pct) $up[] = $e; elseif ($pct <= -$min_pct) $down[] = $e;
    }
    usort($up, function ($a, $b) { return $b['pct'] <=> $a['pct']; });
    usort($down, function ($a, $b) { return $a['pct'] <=> $b['pct']; });
    return ['up' => $up, 'down' => $down, 'all' => $all];
}

/** Percent change cur-vs-prev, or null when not computable (missing / prev 0). */
function lmeg_si_pct_change($cur, $prev) {
    if ($cur === null || $prev === null || $cur === '' || $prev === '') return null;
    $cur = (float) $cur; $prev = (float) $prev;
    if ($prev == 0.0) return null;
    return ($cur - $prev) / $prev * 100.0;
}

/**
 * Fanloop's own analysis layer — reads the assembled data context and emits
 * ranked, plain-language findings + recommendations. Pure (no WP calls beyond
 * number_format_i18n); every rule fires only when its data is present and clears
 * a threshold, so nothing is fabricated. Returns up to 7 findings, most
 * actionable first: ['type'=>opportunity|watch|insight|strength,'title','detail'].
 */
function lmeg_si_analyze($c) {
    $F = [];
    $p = function ($v) { return rtrim(rtrim(number_format((float) $v, 1), '0'), '.'); };
    $n = function ($v) { return function_exists('number_format_i18n') ? number_format_i18n((int) $v) : number_format((int) $v); };

    // Per-song momentum. When REAL day-by-day data exists (song_wow, top 20
    // songs) it replaces the pace-vs-28d estimate below: true last-7 vs prior-7.
    $others = function ($list, $skip) use ($p) {
        $o = []; foreach ($list as $r) { if ($r['title'] !== $skip && count($o) < 2) $o[] = '“' . $r['title'] . '” ' . ($r['wow'] >= 0 ? '+' : '−') . $p(abs($r['wow'])) . '%'; }
        return $o;
    };
    if (!empty($c['song_wow']) && is_array($c['song_wow'])) {
        $sw = $c['song_wow'];
        if (!empty($sw['up']) && $sw['up'][0]['wow'] >= 25) {
            $m = $sw['up'][0]; $o = $others($sw['up'], $m['title']);
            $F[] = ['type' => 'opportunity', 'title' => '“' . $m['title'] . '” is breaking out this week', 'song' => $m['title'], 'uri' => (string) ($m['uri'] ?? ''),
                'detail' => 'Up ' . $p($m['wow']) . '% on the week before — ' . $n($m['last7']) . ' streams in the last 7 days vs ' . $n($m['prior7']) . '.' . ($o ? ' Also up: ' . implode(', ', $o) . '.' : '') . ' Put promo behind it while it’s moving.'];
        }
        if (!empty($sw['lead']) && $sw['lead']['wow'] !== null && $sw['lead']['wow'] <= -20) {
            $m = $sw['lead'];
            $F[] = ['type' => 'watch', 'title' => '“' . $m['title'] . '” is cooling', 'song' => $m['title'], 'uri' => (string) ($m['uri'] ?? ''),
                'detail' => 'Your biggest song this week is down ' . $p(abs($m['wow'])) . '% on the week before (' . $n($m['last7']) . ' vs ' . $n($m['prior7']) . ' streams). If it was a recent focus, the momentum is fading.'];
        } elseif (count($sw['up']) >= 3 && count($sw['up']) >= 3 * count($sw['down'])) {
            // Broad lift: ≥3 up and at least 3× as many up as down.
            $nd = count($sw['down']);
            $F[] = ['type' => 'strength', 'title' => 'Lift across the catalogue',
                'detail' => count($sw['up']) . ' of your top songs grew week-over-week' . ($nd ? ' and only ' . $nd . ' fell' : ' and none fell') . ' — ' . implode(', ', $others($sw['up'], null)) . '. Whatever is driving this is reaching more than one track.'];
        } elseif (count($sw['down']) >= 3 && count($sw['down']) >= 3 * count($sw['up'])) {
            $nu = count($sw['up']);
            $F[] = ['type' => 'watch', 'title' => 'Soft week across the catalogue',
                'detail' => count($sw['down']) . ' of your top songs fell week-over-week' . ($nu ? ' and only ' . $nu . ' grew' : ' and none grew') . ' — ' . implode(', ', $others($sw['down'], null)) . '. A fresh post, playlist pitch or drop would help more than pushing one track.'];
        }
    } else {
        // Rising track — promote while hot (pace-vs-28d estimate).
        if (!empty($c['mover_up']) && ($c['mover_up']['pace'] ?? 0) >= 10) {
            $m = $c['mover_up'];
            $F[] = ['type' => 'opportunity', 'title' => '“' . $m['title'] . '” is gaining', 'song' => $m['title'],
                'detail' => 'It ran ' . $p($m['pace']) . '% above its 28-day pace (' . $n($m['s7']) . ' streams in the last 7 days). Put promo behind it while it’s moving.'];
        }
        // Falling flagship — watch.
        if (!empty($c['mover_down']) && ($c['mover_down']['pace'] ?? 0) <= -20) {
            $m = $c['mover_down'];
            $F[] = ['type' => 'watch', 'title' => '“' . $m['title'] . '” is cooling', 'song' => $m['title'],
                'detail' => 'Down ' . $p(abs($m['pace'])) . '% vs its 28-day pace. If it was a recent focus, the momentum is fading.'];
        }
    }
    // Playlist pickups since the previous capture — an editorial add is the
    // most time-sensitive thing on the page; algorithmic/listener adds get a
    // lighter mention. A lost editorial playlist is a watch.
    if (!empty($c['playlist_diff']) && is_array($c['playlist_diff'])) {
        $pd = $c['playlist_diff']; $since = !empty($c['prev_date']) ? ' since ' . date_i18n('M j', strtotime($c['prev_date'])) : ' since your last capture';
        $ed = array_values(array_filter($pd['new'], function ($x) { return $x['type'] === 'curated'; }));
        $oth = array_values(array_filter($pd['new'], function ($x) { return $x['type'] !== 'curated' && (int) ($x['followers'] ?? 0) >= 1000; }));
        $fl = function ($x) use ($n) { return $x['followers'] ? ' (' . $n($x['followers']) . ' followers)' : ''; };
        if ($ed) {
            $x = $ed[0]; $more = count($ed) > 1 ? ' and ' . (count($ed) - 1) . ' more editorial playlist' . (count($ed) > 2 ? 's' : '') : '';
            $F[] = ['type' => 'opportunity', 'title' => 'New editorial playlist: “' . $x['title'] . '”', 'playlist' => $x['title'],
                'detail' => 'Spotify added you to “' . $x['title'] . '”' . $fl($x) . $more . $since . '. Editorial adds are earned, not bought — tell your fans while it’s fresh and it tends to stick.'];
        } elseif ($oth) {
            $x = $oth[0];
            $F[] = ['type' => 'insight', 'title' => count($oth) . ' new playlist' . (count($oth) > 1 ? 's' : '') . ' picked you up',
                'detail' => '“' . $x['title'] . '”' . ($x['author'] ? ' by ' . $x['author'] : '') . $fl($x) . (count($oth) > 1 ? ' and ' . (count($oth) - 1) . ' other' . (count($oth) > 2 ? 's' : '') : '') . ' started sending streams' . $since . '.'];
        }
        if (!empty($pd['gone'])) {
            $x = $pd['gone'][0];
            $F[] = ['type' => 'watch', 'title' => 'Dropped from “' . $x['title'] . '”',
                'detail' => 'The editorial playlist “' . $x['title'] . '”' . $fl($x) . ' no longer shows in your top playlists' . $since . ' — it was ' . $n($x['streams']) . ' streams in the window. Expect a dip; a fresh pitch or a new single is the way back in.'];
        }
    }
    // Launch comparison — did the newest single open bigger than the one before?
    if (!empty($c['launch']) && is_array($c['launch']) && count($c['launch']) >= 2) {
        $L0 = $c['launch'][0]; $L1 = $c['launch'][1];
        if ($L1['first7'] > 0) {
            $d7 = round(($L0['first7'] - $L1['first7']) / $L1['first7'] * 100, 1);
            if ($d7 >= 15) $F[] = ['type' => 'strength', 'title' => '“' . $L0['name'] . '” opened bigger than “' . $L1['name'] . '”', 'song' => $L0['name'],
                'detail' => $n($L0['first7']) . ' streams in its first 7 days vs ' . $n($L1['first7']) . ' for “' . $L1['name'] . '” — ' . $p($d7) . '% stronger on the same clock. Your launches are getting bigger; keep the pre-release build.'];
            elseif ($d7 <= -15) $F[] = ['type' => 'watch', 'title' => '“' . $L0['name'] . '” opened smaller than “' . $L1['name'] . '”', 'song' => $L0['name'],
                'detail' => $n($L0['first7']) . ' streams in its first 7 days vs ' . $n($L1['first7']) . ' for “' . $L1['name'] . '” (' . $p($d7) . '%). A softer first week usually means less pre-release runway — a pre-save and a list send before the next one changes this.'];
        }
    }
    // Market movement — the fastest-growing country (and a top market slipping).
    if (!empty($c['country_moves']) && is_array($c['country_moves'])) {
        $mv = $c['country_moves']; $md = max(1, (int) ($c['moves_days'] ?? 1)); $span = $md . ' day' . ($md === 1 ? '' : 's');
        if (!empty($mv['up'])) {
            $u = $mv['up'][0]; $more = count($mv['up']) > 1 ? ' ' . $mv['up'][1]['name'] . ' is up ' . $p($mv['up'][1]['pct']) . '% too.' : '';
            $F[] = ['type' => 'insight', 'title' => $u['name'] . ' is your fastest-growing market', 'country' => $u['cc'],
                'detail' => 'Monthly listeners there went from ' . $n($u['prev']) . ' to ' . $n($u['num']) . ' in ' . $span . ' (' . ($u['pct'] >= 0 ? '+' : '') . $p($u['pct']) . '%).' . $more . ' Worth a show, a local playlist pitch, or a post in their morning.'];
        }
        if (!empty($mv['down'])) {
            $d = $mv['down'][0];
            $F[] = ['type' => 'watch', 'title' => $d['name'] . ' is slipping', 'country' => $d['cc'],
                'detail' => 'Monthly listeners there fell from ' . $n($d['prev']) . ' to ' . $n($d['num']) . ' in ' . $span . ' (' . $p($d['pct']) . '%). One market cooling isn’t a crisis — but if it’s a core one, a targeted send there is cheap.'];
        }
    }
    // Long-range momentum — the last 90 days vs the 90 before (top songs).
    // Sits above the weekly noise: a quarter is long enough to be a trend.
    if (!empty($c['quarter']) && is_array($c['quarter']) && isset($c['quarter']['pct'])) {
        $q = $c['quarter']; $qp = (float) $q['pct'];
        if ($qp >= 15) $F[] = ['type' => 'strength', 'title' => 'The last quarter is your strongest',
            'detail' => 'Your top songs did ' . $n($q['last']) . ' streams in the last 90 days — ' . $p($qp) . '% more than the 90 days before. That’s a trend, not a spike: keep the cadence that got you here.'];
        elseif ($qp <= -15) $F[] = ['type' => 'watch', 'title' => 'The last quarter ran below the one before',
            'detail' => 'Your top songs did ' . $n($q['last']) . ' streams in the last 90 days, ' . $p(abs($qp)) . '% fewer than the 90 before. A longer slide than a soft week — new music or a proper campaign moves this, a single post won’t.'];
    }
    // Weekly rhythm — which day your listeners show up (top songs, 12 weeks).
    // Only worth saying when the peak is clearly above an average day.
    if (!empty($c['weekday']) && is_array($c['weekday']) && ($c['weekday']['peak_pct'] ?? 0) >= 12) {
        $w = $c['weekday'];
        $before = lmeg_si_weekday_name($w['peak'] - 1 ?: 7, true);
        $F[] = ['type' => 'insight', 'title' => lmeg_si_weekday_name($w['peak'], true) . ' are your biggest day',
            'detail' => 'Across your top songs, ' . lmeg_si_weekday_name($w['peak'], true) . ' run ' . $p($w['peak_pct']) . '% above an average day and ' . lmeg_si_weekday_name($w['trough'], true) . ' ' . $p(abs($w['trough_pct'])) . '% below (last ' . (int) $w['weeks'] . ' weeks). Post and send on ' . $before . ' so it lands when they’re already listening.'];
    }
    // Streams velocity — catalog-wide week-over-week momentum from the daily
    // series (distinct from per-song movers and month-over-month deltas).
    if (!empty($c['velocity']) && ($c['velocity']['prior7'] ?? 0) > 0) {
        $v = $c['velocity']; $wow = (float) $v['wow'];
        if ($wow <= -12) {
            $streak = ($v['weeks_down'] ?? 0) >= 3 ? ', and each of the last ' . (int) $v['weeks_down'] . ' weeks came in below the one before' : '';
            // Acknowledge the month-over-month picture so this doesn't read as a
            // contradiction next to a period-over-period "streams up" finding.
            $pop = (isset($c['streams_pop']) && $c['streams_pop'] !== null && (float) $c['streams_pop'] > 0)
                ? ' Your 28-day total is still up ' . $p((float) $c['streams_pop']) . '% overall, but the recent momentum is softening.' : '';
            $F[] = ['type' => 'watch', 'title' => 'Streams are cooling',
                'detail' => 'The last 7 days ran ' . $p(abs($wow)) . '% below the previous 7 (' . $n($v['last7']) . ' vs ' . $n($v['prior7']) . ' streams)' . $streak . '.' . $pop . ' A re-push, a fresh drop, or a playlist pitch could reverse the slide.'];
        } elseif ($wow >= 15) {
            $streak = ($v['weeks_up'] ?? 0) >= 3 ? ' — rising ' . (int) $v['weeks_up'] . ' weeks straight' : '';
            $F[] = ['type' => 'strength', 'title' => 'Streams are accelerating',
                'detail' => 'The last 7 days ran ' . $p($wow) . '% above the previous 7 (' . $n($v['last7']) . ' vs ' . $n($v['prior7']) . ' streams)' . $streak . '. Lean into whatever you’re doing right now — it’s working.'];
        }
    }
    // Release cadence / newest-release performance.
    if (!empty($c['last_release']['name'])) {
        $lr = $c['last_release']; $d = (int) ($lr['days_ago'] ?? 0);
        if ($d >= 60) $F[] = ['type' => 'opportunity', 'title' => 'Time for new music',
            'detail' => 'It’s been ' . $d . ' days since your last release (“' . $lr['name'] . '”). A fresh drop — or a re-push of a strong catalog cut — would refresh your momentum.'];
        elseif ($d <= 45 && isset($lr['ratio']) && $lr['ratio'] !== null) {
            if ($lr['ratio'] >= 1.6) $F[] = ['type' => 'strength', 'title' => 'Your latest release is landing',
                'detail' => '“' . $lr['name'] . '” is pulling ' . $p($lr['ratio']) . '× your typical release’s streams — lean promo into it while it’s fresh.'];
            elseif ($lr['ratio'] <= 0.6) $F[] = ['type' => 'watch', 'title' => 'Latest release is under its potential',
                'detail' => '“' . $lr['name'] . '” is tracking below your catalog average. A targeted push — playlist pitches, socials, a few ad dollars — could give it a second wind.'];
        }
    }
    // Playlist dependency vs editorial support.
    if (isset($c['pl_mix']['Algorithmic'])) {
        $alg = $c['pl_mix']['Algorithmic']; $ed = $c['pl_mix']['Editorial'] ?? 0;
        if ($alg >= 70) $F[] = ['type' => 'watch', 'title' => 'Heavily algorithm-driven',
            'detail' => $p($alg) . '% of your playlist streams come from Spotify’s own algorithm (Radio/Mixes/DJ) and only ' . $p($ed) . '% from editorial. Pitching editorial playlists would de-risk that reach.'];
        elseif ($ed >= 25) $F[] = ['type' => 'strength', 'title' => 'Editorial support is solid',
            'detail' => $p($ed) . '% of your playlist streams come from editorial placements.'];
    }
    // Audience geographic concentration + strongest secondary markets. Uses the
    // per-country monthly-listener breakdown (meta.countries), which no other
    // finding touches. Leads the insight tier: it's Spotify-only (no IG needed)
    // and directly actionable for ad targeting / tour routing, so it should
    // surface even on data-rich artists where cross-platform insights compete.
    if (!empty($c['geo']['top']) && ($c['geo']['pct'] ?? null) !== null) {
        $g = $c['geo'];
        if ($g['pct'] >= 40) {
            $sec = '';
            if (!empty($g['second'])) {
                $sec = ' Your strongest secondary market is ' . $g['second']
                     . (!empty($g['third']) ? ', then ' . $g['third'] : '')
                     . ' — the clearest places to target ads or route a tour.';
            }
            $F[] = ['type' => 'insight', 'title' => 'Your audience centers on ' . $g['top'],
                'detail' => $p($g['pct']) . '% of your monthly listeners are in ' . $g['top'] . '.' . $sec];
        } elseif ($g['pct'] < 25 && ($g['count'] ?? 0) >= 12) {
            $F[] = ['type' => 'strength', 'title' => 'Your reach is global',
                'detail' => 'No single country is more than ' . $p($g['pct']) . '% of your listeners — you’re spread across ' . (int) $g['count'] . ' markets, which is resilient, diversified reach.'];
        }
    }
    // Fan-intent standout — the track fans KEEP most (save rate), which usually
    // differs from the biggest streamer. Surfaces loyalty/quality, not volume,
    // from per-song saves+listeners no other finding uses.
    if (!empty($c['saver']['title']) && ($c['saver']['ratio'] ?? 0) >= 1.5 && ($c['saver']['rate'] ?? 0) >= 5) {
        $s = $c['saver'];
        $diverges = !empty($c['top_track']) && strcasecmp($s['title'], (string) $c['top_track']) !== 0;
        $detail = $p($s['rate']) . '% of its listeners saved “' . $s['title'] . '” — ' . $p($s['ratio']) . '× your catalog’s save rate.'
                . ($diverges
                    ? ' It’s not your most-streamed track, but it’s the one fans keep — a strong signal of the sound your core connects with.'
                    : ' That’s both your biggest song and your most-saved — rare, and worth doubling down on.');
        $F[] = ['type' => 'insight', 'title' => 'Fans keep “' . $s['title'] . '” the most', 'detail' => $detail, 'song' => $s['title']];
    }
    // Cross-platform gender gap.
    if (isset($c['sp_women'], $c['ig_women']) && $c['sp_women'] !== null && $c['ig_women'] !== null) {
        $gap = $c['sp_women'] - $c['ig_women'];
        if (abs($gap) >= 8) $F[] = ['type' => 'insight', 'title' => 'Who streams you ≠ who follows you',
            'detail' => 'Your Spotify audience is ' . $p(abs($gap)) . ' points ' . ($gap > 0 ? 'more female' : 'more male') . ' than your Instagram following. The people streaming you aren’t exactly your social crowd — worth tailoring content to each.'];
    }
    // Cross-platform age skew.
    if (!empty($c['sp_core_age']) && !empty($c['ig_core_age']) && $c['sp_core_age'] !== $c['ig_core_age']) {
        $F[] = ['type' => 'insight', 'title' => 'Streaming and social skew different ages',
            'detail' => 'Your Spotify core is ' . $c['sp_core_age'] . ' while your Instagram core is ' . $c['ig_core_age'] . '. Match each platform’s tone to who’s actually there.'];
    }
    // Geography gap.
    if (!empty($c['sp_top_city']) && !empty($c['ig_top_city']) && strcasecmp($c['sp_top_city'], $c['ig_top_city']) !== 0) {
        $F[] = ['type' => 'insight', 'title' => 'Reach and streams peak in different cities',
            'detail' => $c['sp_top_city'] . ' leads your Spotify streams while ' . $c['ig_top_city'] . ' leads your Instagram — a good split for local ads vs. touring routing.'];
    }
    // Listener→follower conversion.
    if (!empty($c['monthly_listeners']) && !empty($c['followers']) && $c['monthly_listeners'] >= 2 * $c['followers']) {
        $F[] = ['type' => 'opportunity', 'title' => 'Turn listeners into followers',
            'detail' => $n($c['monthly_listeners']) . ' people listened this month but only ' . $n($c['followers']) . ' follow you on Spotify. A follow CTA converts casual listens into a base that hears every release on day one.'];
    }
    // Social ↔ streaming growth direction.
    if (isset($c['streams_trend'], $c['social_trend']) && $c['streams_trend'] !== null && $c['social_trend'] !== null) {
        if ($c['streams_trend'] > 0 && $c['social_trend'] > 0) $F[] = ['type' => 'strength', 'title' => 'Growing on both fronts',
            'detail' => 'Streams and your Instagram following are trending up together — the momentum is broad-based, not just one channel.'];
        elseif ($c['social_trend'] > 0 && $c['streams_trend'] <= 0) $F[] = ['type' => 'watch', 'title' => 'Social up, streams flat',
            'detail' => 'Your following is growing but streams aren’t following yet — posts may not be pushing people to listen. Add a clear “listen now” CTA.'];
        elseif ($c['streams_trend'] > 0 && $c['social_trend'] <= 0) $F[] = ['type' => 'opportunity', 'title' => 'Streams up, social flat',
            'detail' => 'Streaming is rising without matching social growth — capture these new listeners with a follow/subscribe push before they drift.'];
    }
    // Save rate.
    if (isset($c['save_rate']) && $c['save_rate'] !== null) {
        if ($c['save_rate'] >= 5) $F[] = ['type' => 'strength', 'title' => 'Listeners are keeping your music',
            'detail' => 'A ' . $p($c['save_rate']) . '% save rate is strong — real fan intent, not just passive plays.'];
        elseif ($c['save_rate'] < 2) $F[] = ['type' => 'opportunity', 'title' => 'Low save rate',
            'detail' => 'Only ' . $p($c['save_rate']) . '% of listeners saved a track. Prompt saves in your posts and release copy — saves feed the algorithm.'];
    }
    // Catalog concentration.
    if (isset($c['top_track_share']) && $c['top_track_share'] !== null && !empty($c['top_track']) && $c['top_track_share'] >= 40) {
        $F[] = ['type' => 'watch', 'title' => 'One track carries a lot',
            'detail' => '“' . $c['top_track'] . '” is ' . $p($c['top_track_share']) . '% of your listed streams. Great song — but spread promotion so you’re not reliant on it.'];
    }
    // Loyal core.
    if (isset($c['active_share']) && $c['active_share'] !== null && $c['active_share'] >= 60) {
        $F[] = ['type' => 'strength', 'title' => 'Loyal core',
            'detail' => $p($c['active_share']) . '% of streams come from your most active listeners — a dependable base to launch releases to.'];
    }

    // Stable sort by tier (opportunity → watch → insight → strength) so the most
    // actionable lead, but keep insertion order within a tier.
    $order = ['opportunity' => 0, 'watch' => 1, 'insight' => 2, 'strength' => 3];
    $i = 0; foreach ($F as &$fr) { $fr['_i'] = $i++; } unset($fr);
    usort($F, function ($a, $b) use ($order) {
        $t = ($order[$a['type']] ?? 9) <=> ($order[$b['type']] ?? 9);
        return $t !== 0 ? $t : ($a['_i'] <=> $b['_i']);
    });
    // Cap at 8 and guarantee at least one strength surfaces (so the card can end
    // on what's working, not only what to fix) when strengths exist.
    $top = array_slice($F, 0, 8);
    $hasStrength = false; foreach ($top as $t) if ($t['type'] === 'strength') { $hasStrength = true; break; }
    if (!$hasStrength) {
        foreach ($F as $fr) { if ($fr['type'] === 'strength') { array_pop($top); $top[] = $fr; break; } }
    }
    foreach ($top as &$fr) unset($fr['_i']); unset($fr);
    return $top;
}

function lmeg_admin_spotify_insights() {
    if (!current_user_can('manage_options')) return;
    // A fatal mid-page used to end the output silently (display_errors is off
    // on production), so a broken section looked like a shorter page. Catch
    // anything thrown while rendering and say exactly where it broke.
    try {
        lmeg_admin_spotify_insights_render();
    } catch (\Throwable $e) {
        echo '<div class="notice notice-error" style="max-width:1040px;margin:14px 0;"><p><strong>Spotify Insights hit an error while rendering</strong> — the sections above are fine; the rest of the page couldn’t be drawn.<br>'
           . '<code>' . esc_html(get_class($e) . ': ' . $e->getMessage()) . '</code><br><span style="opacity:.75;">' . esc_html(basename($e->getFile()) . ':' . $e->getLine()) . '</span></p></div></div>';
    }
}

function lmeg_admin_spotify_insights_render() {
    // Section timings — printed as an HTML comment at the end when an admin
    // adds ?lmeg_prof=1 (the page was measured at 12s on a busy site).
    $prof = ['start' => microtime(true)]; $prof_on = !empty($_GET['lmeg_prof']);
    $mark = function ($k) use (&$prof) { $prof[$k] = microtime(true); };
    $t = lmeg_si_tokens();
    // Pull every token out up front: `$t` gets reused as a loop variable
    // further down (song titles, API tracks), and `$t['muted']` on a string is
    // a TypeError on PHP 8 that silently truncated the page.
    $card = $t['card']; $lbl = $t['lbl']; $muted = $t['muted'] ?? 'color:#8B90A0;';

    // "Email me this week's digest" — sends the Monday owner digest (with the
    // Spotify section) to the digest address right now.
    $si_notice = '';
    if (($_POST['lmeg_si_action'] ?? '') === 'digest' && check_admin_referer('lmeg_si_digest', 'lmeg_si_digest_nonce') && function_exists('lmeg_send_owner_digest')) {
        lmeg_send_owner_digest();
        $s_ = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
        $to_ = !empty($s_['digest_email']) && is_email($s_['digest_email']) ? $s_['digest_email'] : get_option('admin_email');
        $si_notice = '<div class="notice notice-success is-dismissible"><p>Digest sent to <strong>' . esc_html($to_) . '</strong>. The Monday version goes out automatically when "weekly summary" is on in Settings.</p></div>';
    }

    // ---- gather from both sources (each optional) ----------------------------
    // Per-site isolation: this Fanloop site shows ONLY its own artist — no
    // cross-artist switcher, even if the DB holds other artists' snapshots.
    $sel = lmeg_artist();
    $artists = [$sel];
    // ?demo=1 — preview the whole page with a synthetic Spotify-for-Artists
    // snapshot (same convention as the Fans/Audience demo previews): nothing
    // is written, every section renders, the banner links back to live.
    $demo      = !empty($_GET['demo']) && function_exists('lmeg_s4a_demo_rows');
    $demo_rows = $demo ? lmeg_s4a_demo_rows($sel) : [];
    if ($demo && !$demo_rows) $demo = false;
    $snap    = $demo ? (object) end($demo_rows) : (function_exists('lmeg_s4a_latest') ? lmeg_s4a_latest($sel) : null);
    $changes = ($snap && $snap->changes) ? (array) json_decode($snap->changes, true) : [];

    // Period-over-period: when the S4A export didn't carry its own change_pct
    // (e.g. the URL-pull pipeline doesn't), compute each KPI's change vs the
    // previous snapshot so the chips + "since last capture" note come alive.
    $prev = $demo ? (object) $demo_rows[0] : (($snap && function_exists('lmeg_s4a_prev')) ? lmeg_s4a_prev($sel, $snap->window, $snap->captured_date) : null);
    if ($prev) {
        foreach (['monthly_listeners', 'streams', 'mal', 'saves', 'playlist_adds', 'followers', 'super_listeners', 'new_active'] as $mk) {
            if (($changes[$mk] ?? null) === null || $changes[$mk] === '') {
                $pc = lmeg_si_pct_change($snap->$mk ?? null, $prev->$mk ?? null);
                if ($pc !== null) $changes[$mk] = round($pc, 1);
            }
        }
    }

    $mark('snapshot');
    $ov = $demo ? lmeg_si_demo_overview($sel) : (function_exists('lmeg_spotify_overview') ? lmeg_spotify_overview() : null);
    if (is_wp_error($ov)) $ov = null;
    $mark('overview_api');

    // Social side — for the cross-platform profile. Each is null when unconfigured.
    $ig_stats = function_exists('lmeg_ig_account_stats') ? lmeg_ig_account_stats() : null;
    $fb_stats = function_exists('lmeg_fb_page_stats') ? lmeg_fb_page_stats() : null;
    $ig_demo  = function_exists('lmeg_social_ig_demographics') ? lmeg_social_ig_demographics() : null;
    $mark('social_stats');

    $has_s4a = (bool) $snap;
    $has_api = is_array($ov);

    $followers  = $has_api && !empty($ov['followers']) ? (int) $ov['followers']
                : ($snap && $snap->followers !== null ? (int) $snap->followers : null);
    $popularity = $has_api ? (int) ($ov['popularity'] ?? 0) : null;

    // ---- assemble the analysis context (Fanloop's own findings) --------------
    $az_meta  = ($has_s4a && $snap->meta) ? (array) json_decode((string) $snap->meta, true) : [];
    $az_songs = $has_s4a ? array_values(array_filter((array) json_decode((string) $snap->top_songs, true), 'is_array')) : [];
    $az_mv    = lmeg_si_song_movers($az_songs, (array) ($az_meta['songs_7d'] ?? []));
    $az_down  = null;
    foreach ($az_songs as $s) { $t = strtolower(trim((string) ($s['title'] ?? ''))); $d = $az_mv['by_title'][$t] ?? null;
        if ($d && $d['pace'] !== null && $d['pace'] < 0 && ($az_down === null || $d['pace'] < $az_down['pace'])) $az_down = ['title' => (string) $s['title'], 'pace' => $d['pace']]; }
    $az_mix = []; foreach (lmeg_si_playlist_mix($has_s4a ? (array) json_decode((string) $snap->top_playlists, true) : []) as $r) $az_mix[$r['label']] = $r['pct'];
    $wpct = function ($ng) { if (!$ng) return null; foreach ($ng['rows'] as $r) if ($r['label'] === 'Women') return $r['pct']; return 0.0; };
    $coreAge = function ($m) { if (!$m) return null; $b = null; $mx = -1; foreach ($m as $k => $v) if ($v > $mx) { $mx = $v; $b = $k; } return $b; };
    $az_spCities = lmeg_si_city_list($az_meta['top_cities'] ?? [], 1);
    $az_igCities = lmeg_si_city_list(($ig_demo['city'] ?? []), 1);
    $az_saveRate = ($snap && $snap->monthly_listeners > 0 && $snap->saves !== null) ? (int) $snap->saves / (int) $snap->monthly_listeners * 100 : null;
    $az_sum = 0; $az_max = 0; $az_topTrack = '';
    foreach ($az_songs as $s) { $v = (int) ($s['streams'] ?? 0); $az_sum += $v; if ($v > $az_max) { $az_max = $v; $az_topTrack = (string) ($s['title'] ?? ''); } }
    // Newest release + performance. S4A's releases endpoint returns EMPTY dates,
    // so the release DATE comes from the public Spotify API (albums), while stream
    // counts come from S4A — matched by normalized name.
    $az_lastRel = null;
    $az_relRaw = array_values(array_filter((array) ($az_meta['releases'] ?? []), 'is_array'));
    $az_apiRel = ($has_api && !empty($ov['releases'])) ? $ov['releases'] : [];
    if ($az_apiRel) {
        usort($az_apiRel, function ($a, $b) { return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')); });
        $r0 = $az_apiRel[0];
        if (!empty($r0['date']) && ($ts = strtotime((string) $r0['date']))) {
            $byName = [];
            foreach ($az_relRaw as $r) $byName[strtolower(trim((string) ($r['name'] ?? '')))] = (int) ($r['streams'] ?? 0);
            $streams = $byName[strtolower(trim((string) ($r0['name'] ?? '')))] ?? null;
            $st = array_values($byName); sort($st); $med = $st ? $st[intdiv(count($st), 2)] : 0;
            $az_lastRel = ['name' => (string) ($r0['name'] ?? ''), 'days_ago' => (int) floor((current_time('timestamp') - $ts) / 86400),
                'streams' => $streams, 'ratio' => ($streams !== null && $med > 0 ? $streams / $med : null)];
        }
    }
    $trend = function ($vals) { $vals = array_values(array_filter((array) $vals, function ($v) { return $v !== null; })); $k = count($vals); if ($k < 2) return null; $d = (float) $vals[$k - 1] - (float) $vals[0]; return $d > 0 ? 1 : ($d < 0 ? -1 : 0); };
    // Streams direction — period-over-period (snapshot-to-snapshot) first, since
    // that matches the social growth it's paired with. Fall back to the daily
    // 28-day series (second half vs first half) so single-snapshot artists still
    // get a direction instead of null.
    $az_streamsTrend = ($has_s4a && function_exists('lmeg_s4a_series'))
        ? $trend(array_map(function ($r) { return (int) $r->v; }, lmeg_s4a_series('streams', $sel, $snap->window))) : null;
    if ($az_streamsTrend === null && $has_s4a) {
        $ds = array_values(array_filter((array) ($az_meta['daily']['streams'] ?? []), 'is_numeric'));
        if (count($ds) >= 8) {
            $h = intdiv(count($ds), 2);
            $first = array_sum(array_slice($ds, 0, $h)); $second = array_sum(array_slice($ds, $h));
            $az_streamsTrend = $second > $first ? 1 : ($second < $first ? -1 : 0);
        }
    }
    $az_socialTrend = null;
    if (function_exists('lmeg_social_snapshots') && function_exists('lmeg_social_series_stats')) {
        $igs = lmeg_social_series_stats(lmeg_social_snapshots('instagram', 30));
        if (!empty($igs['vals']) && count($igs['vals']) >= 2) $az_socialTrend = $igs['delta'] > 0 ? 1 : ($igs['delta'] < 0 ? -1 : 0);
    }
    // Audience geographic concentration (from the per-country breakdown).
    $az_ctys  = lmeg_si_country_rows((array) ($az_meta['countries'] ?? []), 3);
    $az_geo   = null;
    if ($az_ctys) {
        $ml = $snap ? (int) $snap->monthly_listeners : 0;
        $share = ($az_ctys[0]['pct'] !== null && $az_ctys[0]['pct'] > 0) ? (float) $az_ctys[0]['pct'] * 100
               : ($ml > 0 ? $az_ctys[0]['num'] / $ml * 100 : null);
        $gc = 0; foreach ((array) ($az_meta['countries'] ?? []) as $cc) if (is_array($cc) && (int) ($cc['num'] ?? 0) > 0) $gc++;
        $az_geo = ['top' => $az_ctys[0]['name'], 'pct' => $share !== null ? round($share, 1) : null,
                   'second' => $az_ctys[1]['name'] ?? null, 'third' => $az_ctys[2]['name'] ?? null, 'count' => $gc];
    }
    // Market movement: compare per-country listeners with the capture ~7 days
    // back (or the previous capture while history is short). Slim query.
    $az_moves = ['moves' => null, 'days' => 0];
    if ($has_s4a && !empty($az_meta['countries'])) {
        $base = null;
        if ($demo) { $base = (object) ['captured_date' => $demo_rows[0]['captured_date'], 'countries' => wp_json_encode(json_decode((string) $demo_rows[0]['meta'], true)['countries'] ?? [])]; }
        elseif (function_exists('lmeg_s4a_at')) {
            $base = lmeg_s4a_at($sel, $snap->window, date('Y-m-d', strtotime($snap->captured_date . ' -7 days')));
            if ((!$base || $base->captured_date >= $snap->captured_date) && $prev) $base = (object) ['captured_date' => $prev->captured_date, 'countries' => wp_json_encode(((array) json_decode((string) $prev->meta, true))['countries'] ?? [])];
        }
        if ($base && $base->captured_date < $snap->captured_date) {
            $az_moves['moves'] = lmeg_si_country_movers($az_meta['countries'], json_decode((string) $base->countries, true));
            $az_moves['days']  = max(1, (int) round((strtotime($snap->captured_date) - strtotime($base->captured_date)) / 86400));
        }
    }
    $findings = lmeg_si_analyze([
        'geo'               => $az_geo,
        'saver'             => lmeg_si_top_saver($az_songs),
        'velocity'          => lmeg_si_stream_velocity((array) ($az_meta['daily']['streams'] ?? [])),
        'streams_pop'       => (isset($changes['streams']) && $changes['streams'] !== '' && $changes['streams'] !== null) ? (float) $changes['streams'] : null,
        'mover_up'          => $az_mv['biggest'] ?? null,
        'mover_down'        => $az_down,
        // Real per-song week-over-week (top 20, day-by-day) — supersedes the
        // pace-based movers above inside the engine when present.
        'song_wow'          => lmeg_si_song_wow_summary(lmeg_si_song_daily_map((array) ($az_meta['song_daily'] ?? []))),
        // Day-of-week rhythm from the same per-song daily data (last 12 weeks).
        'weekday'           => lmeg_si_weekday_profile((array) ($az_meta['song_daily'] ?? [])),
        // Long-range: last 90 days vs the 90 before (top songs).
        'quarter'           => (($cm_ = lmeg_si_catalogue_monthly((array) ($az_meta['song_daily'] ?? []))) ? $cm_['q90'] : null),
        // Playlists that picked you up (or dropped you) since the previous capture.
        'playlist_diff'     => ($has_s4a && $prev) ? lmeg_si_playlist_diff(json_decode((string) $snap->top_playlists, true), json_decode((string) $prev->top_playlists, true)) : null,
        'prev_date'         => $prev ? (string) $prev->captured_date : null,
        // Launches compared on the same clock (first 7 days of each matched single).
        'launch'            => lmeg_si_launch_compare(($has_api && !empty($ov['releases'])) ? $ov['releases'] : [], lmeg_si_song_daily_map((array) ($az_meta['song_daily'] ?? []))),
        // Market movement vs the capture ~7 days back (slim row; falls back to the previous capture).
        'country_moves'     => $az_moves['moves'],
        'moves_days'        => $az_moves['days'],
        'pl_mix'            => $az_mix,
        'sp_women'          => $wpct(lmeg_si_norm_gender($az_meta['gender'] ?? null)),
        'ig_women'          => $wpct(lmeg_si_norm_gender(($ig_demo['gender'] ?? null))),
        'sp_core_age'       => $coreAge(lmeg_si_norm_age(lmeg_si_age_rows($az_meta['gender_by_age'] ?? null))),
        'ig_core_age'       => $coreAge(lmeg_si_norm_age(($ig_demo['age'] ?? null))),
        'sp_top_city'       => $az_spCities ? $az_spCities[0]['name'] : null,
        'ig_top_city'       => $az_igCities ? $az_igCities[0]['name'] : null,
        'monthly_listeners' => $snap ? (int) $snap->monthly_listeners : null,
        'followers'         => $followers,
        'streams_trend'     => $az_streamsTrend,
        'social_trend'      => $az_socialTrend,
        'save_rate'         => $az_saveRate,
        'top_track_share'   => $az_sum > 0 ? $az_max / $az_sum * 100 : null,
        'top_track'         => $az_topTrack,
        'active_share'      => isset($az_meta['pct_streams_from_mal']) && $az_meta['pct_streams_from_mal'] !== null ? (float) $az_meta['pct_streams_from_mal'] : null,
        'last_release'      => $az_lastRel,
    ]);
    $mark('analysis');
    ?>
    <div class="wrap lmeg-admin">
        <h1>Fanloop — Insights</h1>
        <?php if ($demo && function_exists('lmeg_demo_banner')) echo lmeg_demo_banner('lmeg-spotify-insights'); ?>

        <?php if (!$has_s4a && !$has_api) : ?>
            <div style="<?php echo $card; ?>max-width:820px;margin-top:12px;">
                <p style="margin:0 0 8px;">No Spotify data yet. Two quick connects light this page up:</p>
                <p style="margin:0;">
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=lmeg-spotify')); ?>">Connect Spotify (followers, popularity)</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lmeg-s4a')); ?>" style="margin-left:6px;">Import Spotify for Artists (streams, songs)</a>
                    <?php if (function_exists('lmeg_s4a_demo_rows')) : ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lmeg-spotify-insights&demo=1')); ?>" style="margin-left:6px;">Preview with demo data</a><?php endif; ?>
                </p>
            </div>
        </div>
        <?php return; endif; ?>
        <?php echo $si_notice; ?>
        <?php if (!$demo) : ?>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0 4px;">
            <?php if (function_exists('lmeg_demo_preview_button') && function_exists('lmeg_s4a_demo_rows')) echo str_replace(['<p>', '</p>'], '', lmeg_demo_preview_button('lmeg-spotify-insights')); ?>
            <?php if ($has_s4a && function_exists('lmeg_send_owner_digest')) : ?>
            <form method="post" style="margin:0;">
                <?php wp_nonce_field('lmeg_si_digest', 'lmeg_si_digest_nonce'); ?>
                <input type="hidden" name="lmeg_si_action" value="digest">
                <button class="button" title="Sends the weekly owner digest — with a Spotify section — to your digest address now">Email me this week’s digest</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (count($artists) > 1) : ?>
        <p style="margin:10px 0;">
            <?php foreach ($artists as $a) : ?>
                <a href="<?php echo esc_url(add_query_arg('artist', rawurlencode($a))); ?>" class="button<?php echo $a === $sel ? ' button-primary' : ''; ?>" style="margin-right:4px;"><?php echo esc_html($a); ?></a>
            <?php endforeach; ?>
        </p>
        <?php endif; ?>

        <!-- HERO -------------------------------------------------------------->
        <div style="<?php echo $card; ?>display:flex;gap:18px;align-items:center;max-width:1040px;margin:12px 0 14px;flex-wrap:wrap;">
            <?php if ($has_api && !empty($ov['image'])) : ?>
                <img src="<?php echo esc_url($ov['image']); ?>" alt="" width="96" height="96" style="width:96px;height:96px;border-radius:16px;object-fit:cover;flex:0 0 auto;box-shadow:0 6px 20px rgba(0,0,0,.4);">
            <?php endif; ?>
            <div style="flex:1 1 260px;min-width:220px;">
                <div style="font:800 26px/1.1 var(--lmegA-font,inherit);"><?php echo esc_html($has_api && !empty($ov['name']) ? $ov['name'] : $sel); ?></div>
                <?php if ($has_api && !empty($ov['genres'])) : ?>
                    <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
                        <?php foreach (array_slice($ov['genres'], 0, 4) as $g) : ?>
                            <span style="font-size:11px;color:#E58BBD;background:rgba(208,95,162,.14);border:1px solid rgba(208,95,162,.3);border-radius:20px;padding:3px 10px;"><?php echo esc_html($g); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php $headline = lmeg_si_headline($snap, $changes, $ov); if ($headline) : ?>
                    <p style="margin:10px 0 0;font-size:14px;color:#C9CCD6;max-width:560px;line-height:1.5;"><?php echo wp_kses_post($headline); ?></p>
                <?php endif; ?>
                <?php
                // Freshness chip — how old the streaming data is, at a glance
                // (green ≤1 day, amber 2–3, red older). Demo shows "Sample data".
                $fresh_days = ($has_s4a && !$demo && !empty($snap->captured_date)) ? max(0, (int) floor((current_time('timestamp') - strtotime($snap->captured_date . ' 12:00:00')) / 86400)) : null;
                if ($demo) { $fc = '#7C6CF6'; $ft = 'Sample data'; }
                elseif ($fresh_days === null) { $fc = null; $ft = ''; }
                elseif ($fresh_days <= 0) { $fc = '#34D399'; $ft = 'Updated today'; }
                elseif ($fresh_days === 1) { $fc = '#34D399'; $ft = 'Updated yesterday'; }
                elseif ($fresh_days <= 3) { $fc = '#F59E0B'; $ft = 'Updated ' . $fresh_days . ' days ago'; }
                else { $fc = '#F87171'; $ft = 'Updated ' . $fresh_days . ' days ago'; }
                if ($fc) : ?>
                    <div style="margin-top:10px;display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:600;color:#F4F5F7;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:3px 10px;" title="<?php echo $has_s4a && !$demo ? esc_attr('Spotify for Artists snapshot captured ' . $snap->captured_date) : ''; ?>">
                        <span style="width:7px;height:7px;border-radius:50%;background:<?php echo $fc; ?>;display:inline-block;" aria-hidden="true"></span><?php echo esc_html($ft); ?><?php if (!$demo) : ?> <span style="color:#8B90A0;font-weight:500;">· Spotify for Artists</span><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($has_api && !empty($ov['url'])) : ?>
                <a class="button" href="<?php echo esc_url($ov['url']); ?>" target="_blank" rel="noopener" style="flex:0 0 auto;">Open on Spotify ↗</a>
            <?php endif; ?>
        </div>

        <!-- STALE DATA WARNING — the daily pull hasn't landed for 3+ days ------>
        <?php if (isset($fresh_days) && $fresh_days !== null && $fresh_days >= 3) : ?>
        <div style="background:rgba(248,113,113,.10);border:1px solid rgba(248,113,113,.40);border-radius:12px;padding:11px 14px;margin:0 0 14px;max-width:1040px;color:#F4F5F7;font-size:13px;line-height:1.5;display:flex;gap:10px;align-items:center;">
            <span style="font-size:16px;flex:0 0 auto;" aria-hidden="true">⚠️</span>
            <div>Your Spotify data is <strong><?php echo (int) $fresh_days; ?> days old</strong> — nothing has landed since <?php echo esc_html($snap->captured_date); ?>, so the numbers below are stale. The daily pull may have stopped. <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-s4a')); ?>">Check the Spotify for Artists import →</a></div>
        </div>
        <?php endif; ?>

        <!-- FAN RINGS — listeners → followers → list → customers → members ---->
        <?php $rings = $demo ? lmeg_si_fan_rings_shape(['listeners' => (int) $snap->monthly_listeners, 'listeners_pct' => $changes['monthly_listeners'] ?? null, 'sp_followers' => 8240, 'sp_followers_delta' => 162, 'ig_followers' => 12480, 'ig_followers_delta' => 310, 'list' => 2140, 'superfans' => 96, 'list_new' => 184, 'customers' => 312, 'customers_new' => 27, 'members' => 41])
                     : lmeg_si_fan_rings_data($snap, $ov, $has_api, isset($changes) ? (array) $changes : []); if ($rings) : ?>
        <div style="<?php echo $card; ?>max-width:1040px;margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;flex-wrap:wrap;margin-bottom:10px;">
                <div style="<?php echo $lbl; ?>">Your fan base · five rings</div>
                <div style="font-size:11px;color:#8B90A0;">Anonymous listeners on the left, people you can actually reach on the right — the % is each ring's share of the one before.</div>
            </div>
            <div style="display:flex;align-items:stretch;gap:0;overflow-x:auto;">
                <?php foreach ($rings as $i => $r) : $val = $r['value']; ?>
                <?php if ($i > 0) : ?><div style="flex:0 0 auto;align-self:center;color:#8B90A0;font-size:16px;padding:0 6px;" aria-hidden="true">›</div><?php endif; ?>
                <div style="flex:1 1 0;min-width:150px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-top:3px solid <?php echo $r['tone']; ?>;border-radius:12px;padding:12px 12px 10px;">
                    <div style="font:800 24px/1.1 var(--lmegA-font,inherit);color:#F4F5F7;font-variant-numeric:tabular-nums;<?php echo $val === null ? 'color:#8B90A0;' : ''; ?>"><?php echo $val === null ? '—' : number_format_i18n($val); ?></div>
                    <div style="font:600 11px/1 var(--lmegA-font,inherit);letter-spacing:.06em;text-transform:uppercase;color:#8B90A0;margin:7px 0 4px;">
                        <?php if (!empty($r['href'])) : ?><a href="<?php echo esc_url(admin_url($r['href'])); ?>" style="color:#F4F5F7;text-decoration:none;border-bottom:1px dotted rgba(255,255,255,.35);"><?php echo esc_html($r['label']); ?></a><?php else : echo esc_html($r['label']); endif; ?>
                    </div>
                    <div style="font-size:11px;color:#C9CCD6;line-height:1.4;"><?php echo esc_html($r['sub']); ?></div>
                    <?php if (!empty($r['change'])) : $cdir = (int) $r['change'][1]; ?>
                    <div style="margin-top:4px;font-size:11px;font-weight:600;color:<?php echo $cdir > 0 ? '#34D399' : ($cdir < 0 ? '#F87171' : '#8B90A0'); ?>;font-variant-numeric:tabular-nums;"><?php echo $cdir > 0 ? '▲ ' : ($cdir < 0 ? '▼ ' : '· '); ?><?php echo esc_html($r['change'][0]); ?></div>
                    <?php endif; ?>
                    <?php if ($r['pct'] !== null) : ?>
                    <div style="margin-top:6px;font-size:11px;font-weight:700;color:<?php echo $r['tone']; ?>;"><?php echo esc_html(rtrim(rtrim(number_format($r['pct'], 2), '0'), '.')); ?>% <span style="color:#8B90A0;font-weight:500;">of <?php echo esc_html($r['pct_of']); ?></span></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- INSIGHT CALLOUTS ------------------------------------------------->
        <?php
        $callouts = lmeg_si_callouts($snap, ($has_s4a && $snap->meta) ? (array) json_decode((string) $snap->meta, true) : []);
        if ($callouts) : ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;max-width:1040px;margin-bottom:14px;">
            <?php foreach ($callouts as $co) : ?>
            <div style="background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-left:3px solid #7C6CF6;border-radius:12px;padding:12px 14px;color:#F4F5F7;">
                <div style="font:800 20px/1.15 var(--lmegA-font,inherit);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($co['value']); ?></div>
                <div style="font:600 11px/1 var(--lmegA-font,inherit);letter-spacing:.06em;text-transform:uppercase;color:#8B90A0;margin:6px 0 4px;"><?php echo esc_html($co['label']); ?></div>
                <div style="font-size:12px;color:#C9CCD6;line-height:1.4;"><?php echo esc_html($co['detail']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- KPI STRIP --------------------------------------------------------->
        <?php
        $kpi = function ($label, $val, $pct = null, $suffix = '') use ($card, $lbl) {
            $v = ($val === null) ? '—' : (is_float($val) ? rtrim(rtrim(number_format($val, 2), '0'), '.') : number_format_i18n((int) $val));
            return '<div style="' . $card . '"><div style="font:800 24px/1 var(--lmegA-font,inherit);font-variant-numeric:tabular-nums;">' . esc_html($v) . esc_html($suffix) . lmeg_si_chip($pct) . '</div><div style="' . $lbl . 'margin-top:6px;">' . esc_html($label) . '</div></div>';
        };
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;max-width:1040px;margin-bottom:14px;">
            <?php
            echo $kpi('Monthly listeners', $snap ? $snap->monthly_listeners : null, $changes['monthly_listeners'] ?? null);
            echo $kpi('Streams · 28d',     $snap ? $snap->streams : null,           $changes['streams'] ?? null);
            echo $kpi('Active listeners',  $snap ? $snap->mal : null,               $changes['mal'] ?? null);
            echo $kpi('Saves',             $snap ? $snap->saves : null,             $changes['saves'] ?? null);
            echo $kpi('Streams / listener', $snap && $snap->streams_per_listener !== null ? (float) $snap->streams_per_listener : null);
            // Followers intentionally omitted here — it already shows in the Social
            // audience tiles (Spotify) right below, so this strip stays non-redundant.
            if ($popularity !== null) echo $kpi('Popularity', $popularity, null, '/100');
            ?>
        </div>

        <?php if ($prev) : $days = max(1, (int) round((strtotime($snap->captured_date) - strtotime($prev->captured_date)) / 86400)); ?>
        <p style="color:#8B90A0;font-size:12px;margin:-4px 0 14px;max-width:1040px;">Change vs your previous capture — <?php echo esc_html($prev->captured_date); ?>, <?php echo (int) $days; ?> day<?php echo $days === 1 ? '' : 's'; ?> earlier.</p>
        <?php endif; ?>

        <!-- ANALYSIS (Fanloop's own findings) — compact + scrollable ---------->
        <?php if ($findings) :
            $ftok = ['opportunity' => ['#7C6CF6', 'Opportunity'], 'watch' => ['#F59E0B', 'Watch'], 'insight' => ['#E58BBD', 'Insight'], 'strength' => ['#34D399', 'Strength']];
        ?>
        <div style="<?php echo $card; ?>max-width:1040px;margin:0 0 14px;">
            <div style="<?php echo $lbl; ?>margin-bottom:4px;">What the data says <span style="color:#8B90A0;font-weight:400;">· Fanloop analysis</span></div>
            <p style="color:#8B90A0;font-size:12px;margin:0 0 12px;">Auto-generated from your streaming + social data — the most actionable items first. Scroll for more.</p>
            <div style="display:flex;flex-direction:column;gap:12px;max-height:320px;overflow-y:auto;padding-right:8px;">
                <?php foreach ($findings as $f) : $tk = $ftok[$f['type']] ?? ['#8B90A0', '']; ?>
                <div style="display:flex;gap:12px;align-items:flex-start;">
                    <span style="flex:0 0 auto;margin-top:1px;font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:<?php echo $tk[0]; ?>;background:<?php echo $tk[0]; ?>1f;border:1px solid <?php echo $tk[0]; ?>55;border-radius:20px;padding:3px 9px;min-width:82px;text-align:center;"><?php echo esc_html($tk[1]); ?></span>
                    <div style="flex:1 1 auto;min-width:0;">
                        <div style="color:#F4F5F7;font-size:14px;font-weight:600;margin-bottom:2px;"><?php echo esc_html($f['title']); ?></div>
                        <div style="color:#C9CCD6;font-size:13px;line-height:1.5;"><?php echo esc_html($f['detail']); ?></div>
                        <?php $acts = lmeg_si_finding_actions($f); if ($acts) : ?>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:7px;">
                            <?php foreach ($acts as $a) : $ext = !empty($a['href']); $href = $ext ? $a['href'] : admin_url('admin.php?' . http_build_query(array_merge(['page' => $a['page']], (array) $a['args']))); ?>
                            <a href="<?php echo esc_url($href); ?>"<?php echo $ext ? ' target="_blank" rel="noopener"' : ''; ?> style="font-size:11px;font-weight:700;color:#F4F5F7 !important;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.16);border-radius:999px;padding:4px 10px;text-decoration:none;line-height:1.2;"><?php echo esc_html($a['label']); ?></a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- SOCIAL — audience + growth (hoisted from Social Listening) --------->
        <?php $mark('top_cards'); ?>
        <?php if (function_exists('lmeg_admin_social')) : ?>
        <div style="height:1px;background:rgba(255,255,255,.12);max-width:1040px;margin:4px 0 16px;"></div>
        <h2 style="font:800 20px/1 var(--lmegA-font,inherit);margin:0 0 4px;">Social</h2>
        <p style="color:#8B90A0;font-size:12px;margin:0 0 14px;max-width:1040px;">Your audience and growth across platforms — from your connected accounts.</p>
        <?php lmeg_admin_social(true, 'audience_growth'); ?>
        <div style="height:14px;"></div>
        <?php endif; ?>

        <!-- TRENDS ------------------------------------------------------------>
        <?php $mark('social_audience'); ?>
        <?php
        // Prefer the DAILY 28-day series (from a single snapshot's stats endpoint)
        // over the sparse snapshot-to-snapshot charts when it's available.
        $daily       = (array) ($az_meta['daily'] ?? []);
        $daily_dates = array_values((array) ($daily['dates'] ?? []));
        $dstreams    = array_values(array_filter((array) ($daily['streams'] ?? []), 'is_numeric'));
        $dfoll       = array_values(array_filter((array) ($daily['followers'] ?? []), 'is_numeric'));
        $dlab = function ($vals) use ($daily_dates) {
            $out = []; $off = max(0, count($daily_dates) - count($vals));
            foreach (array_keys($vals) as $i) { $d = $daily_dates[$off + $i] ?? null; $out[] = $d ? date_i18n('M j', strtotime($d)) : ''; }
            return $out;
        };
        $streams_ser = ($has_s4a && function_exists('lmeg_s4a_series')) ? lmeg_s4a_series('streams', $sel, $snap->window) : [];
        $hist = function_exists('lmeg_spotify_history') ? array_reverse(lmeg_spotify_history()) : []; // oldest→newest
        $has_daily_streams = count($dstreams) >= 7 && function_exists('lmeg_chart_line');
        $has_daily_foll    = count($dfoll)    >= 7 && function_exists('lmeg_chart_line');
        $has_stream_chart  = !$has_daily_streams && count($streams_ser) >= 2 && function_exists('lmeg_chart_line');
        $has_foll_chart    = !$has_daily_foll && count($hist) >= 2 && function_exists('lmeg_chart_line');
        if ($has_daily_streams || $has_daily_foll || $has_stream_chart || $has_foll_chart) : ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;max-width:1040px;margin-bottom:14px;">
            <?php if ($has_daily_streams) : ?>
                <div style="<?php echo $card; ?>">
                    <div style="<?php echo $lbl; ?>margin-bottom:8px;">Streams — last 28 days <span style="color:#8B90A0;font-weight:400;">· daily</span></div>
                    <?php echo lmeg_chart_line($dstreams, [
                        'color' => '#1DB954', 'uid' => 'si-streams-d', 'h' => 70, 'suffix' => ' streams', 'labels' => $dlab($dstreams),
                    ]); ?>
                    <?php
                    // Weekly rhythm — 7 mini bars (Mon…Sun) from the per-song daily data.
                    $wkp = lmeg_si_weekday_profile((array) ($az_meta['song_daily'] ?? []));
                    if ($wkp) : $wmax = max(1, max($wkp['avg'])); ?>
                    <div style="margin-top:10px;border-top:1px solid rgba(255,255,255,.08);padding-top:8px;">
                        <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;margin-bottom:6px;flex-wrap:wrap;">
                            <div style="<?php echo $lbl; ?>">By weekday <span style="color:#8B90A0;font-weight:400;">· top songs, <?php echo (int) $wkp['weeks']; ?> weeks</span></div>
                            <div style="font-size:11px;color:#C9CCD6;"><strong style="color:#34D399;"><?php echo esc_html(lmeg_si_weekday_name($wkp['peak'], true)); ?></strong> <?php echo ($wkp['peak_pct'] >= 0 ? '+' : '') . esc_html(rtrim(rtrim(number_format($wkp['peak_pct'], 1), '0'), '.')); ?>% · <span style="color:#8B90A0;"><?php echo esc_html(lmeg_si_weekday_name($wkp['trough'], true)); ?> <?php echo esc_html(rtrim(rtrim(number_format($wkp['trough_pct'], 1), '0'), '.')); ?>%</span></div>
                        </div>
                        <div style="display:flex;gap:6px;align-items:flex-end;height:44px;">
                            <?php foreach ($wkp['avg'] as $d => $v) : $h = max(3, round($v / $wmax * 36)); $isPeak = $d === $wkp['peak']; ?>
                            <div style="flex:1 1 0;display:flex;flex-direction:column;align-items:center;gap:3px;" title="<?php echo esc_attr(lmeg_si_weekday_name($d) . ' · ' . number_format_i18n((int) round($v)) . ' streams/day avg'); ?>">
                                <div style="width:100%;height:<?php echo $h; ?>px;border-radius:4px 4px 2px 2px;background:<?php echo $isPeak ? '#34D399' : 'rgba(124,108,246,.55)'; ?>;"></div>
                                <div style="font-size:10px;color:<?php echo $isPeak ? '#F4F5F7' : '#8B90A0'; ?>;font-weight:<?php echo $isPeak ? '700' : '500'; ?>;"><?php echo esc_html(substr(lmeg_si_weekday_name($d), 0, 1)); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php
                    // Did a send move the needle? Campaign marks that fall inside
                    // this 28-day window, with streams in the 3 days after vs before.
                    $cm_marks = $demo ? lmeg_si_demo_marks() : lmeg_si_campaign_marks(60);
                    $cm_lift  = $cm_marks ? lmeg_si_campaign_lift(array_slice($daily_dates, max(0, count($daily_dates) - count($dstreams))), $dstreams, $cm_marks) : [];
                    if ($cm_lift) : ?>
                    <div style="margin-top:10px;border-top:1px solid rgba(255,255,255,.08);padding-top:8px;">
                        <div style="<?php echo $lbl; ?>margin-bottom:6px;">Campaign lift <span style="color:#8B90A0;font-weight:400;">· 3 days after a send vs 3 before</span></div>
                        <?php foreach (array_slice(array_reverse($cm_lift), 0, 4) as $L) : $up = $L['pct'] >= 0; ?>
                        <div style="display:flex;gap:8px;align-items:baseline;font-size:12px;color:#C9CCD6;line-height:1.5;">
                            <span style="flex:0 0 auto;color:#D05FA2;" aria-hidden="true">✉</span>
                            <span style="flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html(date_i18n('M j', strtotime($L['d']))); ?> · “<?php echo esc_html($L['subject']); ?>”<?php if ($L['clicks']) : ?> · <?php echo number_format_i18n($L['clicks']); ?> clicks<?php endif; ?></span>
                            <span style="flex:0 0 auto;font-weight:700;color:<?php echo $up ? '#34D399' : '#F87171'; ?>;font-variant-numeric:tabular-nums;" title="avg <?php echo number_format_i18n($L['after']); ?>/day after vs <?php echo number_format_i18n($L['before']); ?>/day before"><?php echo ($up ? '+' : '') . esc_html(rtrim(rtrim(number_format($L['pct'], 1), '0'), '.')); ?>%</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php elseif ($has_stream_chart) : ?>
                <div style="<?php echo $card; ?>">
                    <div style="<?php echo $lbl; ?>margin-bottom:8px;">Streams over time</div>
                    <?php echo lmeg_chart_line(array_map(function ($r) { return (int) $r->v; }, $streams_ser), [
                        'color' => '#1DB954', 'uid' => 'si-streams', 'h' => 70, 'suffix' => ' streams',
                        'labels' => array_map(function ($r) { return date_i18n('M j', strtotime($r->captured_date)); }, $streams_ser),
                    ]); ?>
                </div>
            <?php endif; ?>
            <?php if ($has_daily_foll) : ?>
                <div style="<?php echo $card; ?>">
                    <div style="<?php echo $lbl; ?>margin-bottom:8px;">Followers — last 28 days <span style="color:#8B90A0;font-weight:400;">· daily</span></div>
                    <?php echo lmeg_chart_line($dfoll, [
                        'color' => '#7C6CF6', 'uid' => 'si-foll-d', 'h' => 70, 'suffix' => ' followers', 'labels' => $dlab($dfoll),
                    ]); ?>
                </div>
            <?php elseif ($has_foll_chart) : ?>
                <div style="<?php echo $card; ?>">
                    <div style="<?php echo $lbl; ?>margin-bottom:8px;">Followers over time</div>
                    <?php echo lmeg_chart_line(array_map(function ($r) { return (int) $r['followers']; }, $hist), [
                        'color' => '#7C6CF6', 'uid' => 'si-followers', 'h' => 70, 'suffix' => ' followers',
                        'labels' => array_map(function ($r) { return date_i18n('M j', strtotime($r['date'])); }, $hist),
                    ]); ?>
                </div>
            <?php endif; ?>
            <?php
            // Long range — streams by month from the per-song daily data (top songs).
            $cmo = lmeg_si_catalogue_monthly((array) ($az_meta['song_daily'] ?? []));
            if ($cmo && count($cmo['months']) >= 2) : $mmax = 1; foreach ($cmo['months'] as $m) $mmax = max($mmax, $m['streams']); ?>
                <div style="<?php echo $card; ?>">
                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;flex-wrap:wrap;margin-bottom:8px;">
                        <div style="<?php echo $lbl; ?>">Streams by month <span style="color:#8B90A0;font-weight:400;">· top songs, <?php echo (int) round($cmo['days'] / 30); ?> months</span></div>
                        <?php if ($cmo['q90']) : $qp = (float) $cmo['q90']['pct']; ?><div style="font-size:11px;color:#C9CCD6;">Last 90 days <strong style="color:<?php echo $qp >= 0 ? '#34D399' : '#F87171'; ?>;"><?php echo ($qp >= 0 ? '+' : '') . esc_html(rtrim(rtrim(number_format($qp, 1), '0'), '.')); ?>%</strong> vs the 90 before</div><?php endif; ?>
                    </div>
                    <div style="display:flex;gap:5px;align-items:flex-end;height:78px;">
                        <?php foreach ($cmo['months'] as $m) : $h = max(3, round($m['streams'] / $mmax * 60)); $isBest = $cmo['best'] && $m['ym'] === $cmo['best']['ym']; ?>
                        <div style="flex:1 1 0;display:flex;flex-direction:column;align-items:center;gap:3px;min-width:0;" title="<?php echo esc_attr($m['label'] . ' ' . $m['year'] . ' · ' . number_format_i18n($m['streams']) . ' streams' . ($m['partial'] ? ' (' . (int) $m['days'] . ' days so far)' : '')); ?>">
                            <div style="width:100%;height:<?php echo $h; ?>px;border-radius:4px 4px 2px 2px;background:<?php echo $isBest ? '#34D399' : ($m['partial'] ? 'rgba(52,211,153,.35)' : 'rgba(29,185,84,.55)'); ?>;<?php echo $m['partial'] ? 'background-image:repeating-linear-gradient(135deg,rgba(255,255,255,.18) 0 3px,transparent 3px 6px);' : ''; ?>"></div>
                            <div style="font-size:10px;color:<?php echo $isBest ? '#F4F5F7' : '#8B90A0'; ?>;font-weight:<?php echo $isBest ? '700' : '500'; ?>;white-space:nowrap;"><?php echo esc_html($m['label']); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top:6px;font-size:11px;color:#8B90A0;"><?php if ($cmo['best']) : ?>Best month <strong style="color:#F4F5F7;"><?php echo esc_html($cmo['best']['label'] . ' ' . $cmo['best']['year']); ?></strong> · <?php echo number_format_i18n($cmo['best']['streams']); ?> streams · <?php endif; ?>striped = month in progress</div>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- THE SONGS (the point of the page) --------------------------------->
        <?php $mark('trends'); ?>
        <?php
        $songs = $has_s4a ? (array) json_decode((string) $snap->top_songs, true) : [];
        $songs = array_values(array_filter($songs, 'is_array'));
        if ($songs) :
            $max = 1;
            foreach ($songs as $s) { $max = max($max, (int) ($s['streams'] ?? 0)); }
            $meta_songs = ($has_s4a && $snap->meta) ? (array) json_decode((string) $snap->meta, true) : [];
            $movers = lmeg_si_song_movers($songs, (array) ($meta_songs['songs_7d'] ?? []));
            $reconcile = lmeg_si_reconcile_tracks($songs, ($has_api ? ($ov['top_tracks'] ?? []) : []));
            // Per-song day-by-day (top 20) → inline 28-day sparkline + a TRUE
            // week-over-week chip (last 7 days vs the 7 before) on each row.
            $sd_map = lmeg_si_song_daily_map((array) ($meta_songs['song_daily'] ?? []));
        ?>
        <div style="<?php echo $card; ?>max-width:1040px;margin-bottom:14px;">
            <div style="<?php echo $lbl; ?>margin-bottom:<?php echo $reconcile['both'] ? '4' : '12'; ?>px;">Your songs · by streams <span style="color:#8B90A0;font-weight:400;">(<?php echo count($songs); ?>)</span></div>
            <?php if ($reconcile['both']) : ?><p style="color:#8B90A0;font-size:11px;margin:0 0 12px;">Streams from Spotify&nbsp;for&nbsp;Artists · <span style="color:#E58BBD;">◍</span> = Spotify public popularity (0–100), matched on <?php echo (int) $reconcile['both']; ?> track<?php echo $reconcile['both'] === 1 ? '' : 's'; ?>.</p><?php endif; ?>
            <?php
            // Momentum banner. Prefer REAL week-over-week (top 20, day-by-day):
            // the biggest gainer, or in a week with no gainer the song that
            // cooled most — so this card never contradicts "What the data says".
            // The pace-vs-28d mover is only the fallback when no daily data exists.
            $fmtp = function ($v) { return rtrim(rtrim(number_format((float) $v, 1), '0'), '.'); };
            $sw_card = $sd_map ? lmeg_si_song_wow_summary($sd_map) : null;
            $banner = null;
            if ($sw_card && !empty($sw_card['up'])) {
                $g = $sw_card['up'][0];
                $banner = ['icon' => '🔥', 'bg' => 'rgba(52,211,153,.10)', 'bd' => 'rgba(52,211,153,.35)', 'c' => '#34D399', 'title' => $g['title'],
                    'html' => ' is your mover this week — <strong style="color:#34D399;">' . number_format_i18n($g['last7']) . '</strong> streams in the last 7 days, <strong style="color:#34D399;">' . esc_html($fmtp($g['wow'])) . '% up</strong> on the week before.'];
            } elseif ($sw_card && !empty($sw_card['down'])) {
                $g = $sw_card['down'][0];
                $banner = ['icon' => '🧊', 'bg' => 'rgba(248,113,113,.08)', 'bd' => 'rgba(248,113,113,.30)', 'c' => '#F87171', 'title' => $g['title'],
                    'html' => ' cooled the most this week — <strong style="color:#F87171;">' . number_format_i18n($g['last7']) . '</strong> streams in the last 7 days, <strong style="color:#F87171;">' . esc_html($fmtp(abs($g['wow']))) . '% down</strong> on the week before. No song grew week-over-week.'];
            } elseif (!$sw_card && !empty($movers['biggest'])) {
                $bm = $movers['biggest'];
                $banner = ['icon' => '🔥', 'bg' => 'rgba(52,211,153,.10)', 'bd' => 'rgba(52,211,153,.35)', 'c' => '#34D399', 'title' => $bm['title'],
                    'html' => ' is heating up — <strong style="color:#34D399;">' . number_format_i18n($bm['s7']) . '</strong> streams in the last 7 days, running <strong style="color:#34D399;">' . esc_html($fmtp($bm['pace'])) . '% above</strong> its 28-day pace.'];
            }
            if ($banner) : ?>
            <div style="background:<?php echo $banner['bg']; ?>;border:1px solid <?php echo $banner['bd']; ?>;border-radius:12px;padding:11px 14px;margin-bottom:14px;display:flex;gap:10px;align-items:center;">
                <span style="font-size:18px;flex:0 0 auto;" aria-hidden="true"><?php echo $banner['icon']; ?></span>
                <div style="font-size:13px;color:#F4F5F7;line-height:1.5;"><strong><?php echo esc_html($banner['title']); ?></strong><?php echo $banner['html']; ?></div>
            </div>
            <?php endif; ?>
            <div style="display:flex;flex-direction:column;gap:9px;max-height:520px;overflow:auto;">
                <?php foreach (array_slice($songs, 0, 40) as $i => $s) :
                    $title = (string) ($s['title'] ?? $s['trackName'] ?? '—');
                    $st = (int) ($s['streams'] ?? 0);
                    $li = isset($s['listeners']) ? (int) $s['listeners'] : null;
                    $sv = isset($s['saves']) ? (int) $s['saves'] : null;
                    $w = max(2, round($st / $max * 100));
                ?>
                <div class="lmeg-song-row" data-song="<?php echo esc_attr($title); ?>" role="button" tabindex="0" title="Click to see this song's history" style="display:flex;align-items:center;gap:12px;cursor:pointer;border-radius:8px;padding:2px 4px;margin:0 -4px;">
                    <div style="width:20px;text-align:right;color:#8B90A0;font-size:12px;font-variant-numeric:tabular-nums;flex:0 0 auto;"><?php echo $i + 1; ?></div>
                    <div style="flex:1 1 auto;min-width:0;">
                        <?php
                        $sd = $sd_map[lmeg_si_song_key($title)] ?? null;
                        $wow = $sd ? lmeg_si_week_over_week($sd['s']) : null;
                        $rp = $movers['by_title'][strtolower(trim($title))]['pace'] ?? null;
                        $rchip = '';
                        if ($wow !== null && abs($wow) >= 5) {
                            // Real day-by-day: last 7 days vs the 7 before.
                            $up = $wow > 0; $rc = $up ? '#34D399' : '#F87171';
                            $rchip = '<span title="Last 7 days vs the 7 days before" style="font-size:11px;font-weight:600;color:' . $rc . ';margin-left:6px;">' . ($up ? '▲' : '▼') . number_format(abs($wow), 0) . '% wk</span>';
                        } elseif ($wow === null && $rp !== null && abs($rp) >= 8) {
                            $up = $rp > 0; $rc = $up ? '#34D399' : '#F87171';
                            $rchip = '<span title="vs 28-day pace" style="font-size:11px;font-weight:600;color:' . $rc . ';margin-left:6px;">' . ($up ? '▲' : '▼') . number_format(abs($rp), 0) . '%</span>';
                        }
                        $pop = $reconcile['pop_by_title'][preg_replace('/\s+/', ' ', strtolower(trim($title)))] ?? null;
                        ?>
                        <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:4px;">
                            <span style="color:#F4F5F7;font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($title); ?></span>
                            <span style="color:#F4F5F7;font-size:13px;font-variant-numeric:tabular-nums;flex:0 0 auto;"><?php echo number_format_i18n($st); echo $rchip; ?></span>
                        </div>
                        <div style="height:6px;border-radius:6px;background:rgba(255,255,255,.06);overflow:hidden;">
                            <div style="height:100%;width:<?php echo $w; ?>%;background:linear-gradient(90deg,#7C6CF6,#D05FA2);border-radius:6px;"></div>
                        </div>
                        <?php if ($li !== null || $sv !== null || $pop !== null) :
                            $parts = [];
                            if ($li !== null) $parts[] = esc_html(number_format_i18n($li)) . ' listeners';
                            if ($sv !== null) $parts[] = esc_html(number_format_i18n($sv)) . ' saves';
                            if ($pop !== null) $parts[] = '<span style="color:#E58BBD;" title="Spotify public popularity 0–100">◍ ' . (int) $pop . '</span> popularity';
                        ?>
                        <div style="margin-top:3px;font-size:11px;color:#8B90A0;"><?php echo implode(' · ', $parts); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($sd) : ?>
                    <div style="flex:0 0 96px;" title="Last 28 days, day by day" aria-hidden="true"><?php echo lmeg_si_sparkline(array_slice($sd['s'], -28), 96, 26, ($wow !== null && $wow < -5) ? '#F87171' : '#34D399'); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- SONG HISTORY OVERLAY — click a song row for its day-by-day chart -->
        <?php
        // Two sources, embedded once so the overlay is instant and needs no AJAX:
        //  · meta.song_daily — TRUE day-by-day streams/listeners/saves for the top
        //    20 songs (up to 365 days, from S4A's song-stats endpoint) → real
        //    7 / 28 / 90 / custom ranges;
        //  · capture history — one point per daily capture (28d/7d window totals,
        //    listeners, saves) for EVERY song → the fallback for songs outside
        //    the top 20 or snapshots from before song_daily existed.
        $song_hist = $demo ? lmeg_si_song_history($demo_rows) : (function_exists('lmeg_s4a_history') ? lmeg_si_song_history(lmeg_s4a_history($sel, $snap->window)) : []);
        // Title → Spotify track uri (for the overlay's "Open on Spotify" + Compose prefill).
        $uri_map = [];
        foreach ($songs as $s_) { $u_ = (string) ($s_['uri'] ?? ''); if (preg_match('/^spotify:track:[A-Za-z0-9]{22}$/', $u_)) $uri_map[lmeg_si_song_key((string) ($s_['title'] ?? ''))] = $u_; }
        ?>
        <div id="lmeg-song-ov" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(8,9,14,.74);align-items:center;justify-content:center;padding:20px;">
            <div role="dialog" aria-modal="true" aria-labelledby="lmeg-song-ov-title" style="<?php echo $card; ?>width:min(760px,100%);max-height:92vh;overflow:auto;box-shadow:0 24px 70px rgba(0,0,0,.6);">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:10px;">
                    <div style="min-width:0;">
                        <div id="lmeg-song-ov-kicker" style="<?php echo $lbl; ?>margin-bottom:4px;">Song history</div>
                        <div id="lmeg-song-ov-title" style="font:800 22px/1.15 var(--lmegA-font,inherit);color:#F4F5F7;overflow:hidden;text-overflow:ellipsis;"></div>
                    </div>
                    <button type="button" id="lmeg-song-ov-close" aria-label="Close" style="flex:0 0 auto;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:#F4F5F7;border-radius:10px;width:34px;height:34px;font-size:18px;line-height:1;cursor:pointer;">&times;</button>
                </div>
                <div id="lmeg-song-ov-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:14px;"></div>
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px;">
                    <span style="<?php echo $lbl; ?>margin-right:4px;">Metric</span>
                    <div id="lmeg-song-ov-metric" style="display:flex;gap:6px;flex-wrap:wrap;"></div>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px;">
                    <span style="<?php echo $lbl; ?>margin-right:4px;">Range</span>
                    <div id="lmeg-song-ov-range" style="display:flex;gap:6px;flex-wrap:wrap;"></div>
                    <span id="lmeg-song-ov-custom" style="display:none;gap:6px;align-items:center;margin-left:4px;">
                        <input type="date" id="lmeg-song-ov-from" style="background:#0E0F16;color:#F4F5F7;border:1px solid rgba(255,255,255,.14);border-radius:8px;padding:4px 8px;font-size:12px;">
                        <span style="color:#8B90A0;font-size:12px;">to</span>
                        <input type="date" id="lmeg-song-ov-to" style="background:#0E0F16;color:#F4F5F7;border:1px solid rgba(255,255,255,.14);border-radius:8px;padding:4px 8px;font-size:12px;">
                    </span>
                    <select id="lmeg-song-ov-cmp" aria-label="Compare with another song" style="margin-left:auto;background:#0E0F16;color:#F4F5F7;border:1px solid rgba(255,255,255,.14);border-radius:8px;padding:4px 8px;font-size:12px;max-width:230px;"></select>
                </div>
                <div id="lmeg-song-ov-chart" style="min-height:190px;"></div>
                <p id="lmeg-song-ov-note" style="color:#8B90A0;font-size:12px;margin:10px 0 0;"></p>
                <div id="lmeg-song-ov-actions" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px;"></div>
            </div>
        </div>
        <script>
        (function(){
            var H  = <?php echo wp_json_encode($song_hist); ?>;
            var DL = <?php echo wp_json_encode(array_values(array_filter((array) ($meta_songs['song_daily'] ?? []), 'is_array'))); ?>;
            // Campaign marks (completed broadcasts by send day) — drawn as ✉ lines
            // on the day-by-day chart, with a 3-days-after vs 3-before lift note.
            var B = <?php echo wp_json_encode($demo ? lmeg_si_demo_marks() : (function_exists('lmeg_si_campaign_marks') ? lmeg_si_campaign_marks(365) : [])); ?>;
            // Per-song actions: Compose prefill (angle picked from the real week-over-week) + Open on Spotify.
            var COMPOSE = <?php echo wp_json_encode(admin_url('admin.php?page=lmeg-compose&prefill=insight')); ?>;
            var U = <?php echo wp_json_encode((object) $uri_map); ?>;
            // Metric sets per mode — daily (true day-by-day) vs history (one
            // point per capture, window totals).
            var DM = [['s','Streams / day'],['li','Listeners / day'],['sv','Saves / day']];
            var HM = [['s28','Streams · 28d window'],['s7','Streams · 7d window'],['li','Listeners'],['sv','Saves']];
            var RANGES = [['7','Last 7 days'],['28','Last 28 days'],['90','Last 90 days'],['all','All'],['custom','Custom']];
            var state = {key:null, daily:null, metric:'s', range:'28', cmp:null};
            var $ = function(id){ return document.getElementById(id); };
            var ov=$('lmeg-song-ov'), kick=$('lmeg-song-ov-kicker'), ttl=$('lmeg-song-ov-title'), stats=$('lmeg-song-ov-stats'), mWrap=$('lmeg-song-ov-metric'), rWrap=$('lmeg-song-ov-range'), cust=$('lmeg-song-ov-custom'), fromI=$('lmeg-song-ov-from'), toI=$('lmeg-song-ov-to'), chart=$('lmeg-song-ov-chart'), note=$('lmeg-song-ov-note'), cmpSel=$('lmeg-song-ov-cmp');
            if(!ov) return;
            var n = function(s){ return String(s||'').toLowerCase().replace(/\s+/g,' ').trim(); };
            var fmt = function(v){ return (v===null||v===undefined) ? '—' : Number(v).toLocaleString(); };
            var pct = function(a,b){ return (b>0) ? ((a-b)/b*100) : null; };
            var find = function(t){ var k=n(t); if(H[k]) return H[k]; for(var key in H){ if(n(H[key].title)===k) return H[key]; } return null; };
            var D = {}; DL.forEach(function(e){ if(e && e.t && e.s && e.s.length) D[n(e.t)] = e; });
            var btn = function(label, on, cb){ var b=document.createElement('button'); b.type='button'; b.textContent=label; b.style.cssText='border-radius:999px;padding:5px 11px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid '+(on?'#D05FA2':'rgba(255,255,255,.14)')+';background:'+(on?'#D05FA2':'rgba(255,255,255,.04)')+';color:#fff;'; b.addEventListener('click',cb); return b; };
            var dayMs = 86400000;
            var parse = function(d){ return new Date(d+'T00:00:00'); };
            var pad = function(x){ return (x<10?'0':'')+x; };
            var iso = function(x){ return x.getFullYear()+'-'+pad(x.getMonth()+1)+'-'+pad(x.getDate()); };
            var addDays = function(d,i){ var x=parse(d); x.setDate(x.getDate()+i); return x; };
            var lab = function(dd){ return parse(dd).toLocaleDateString(undefined,{month:'short',day:'numeric'}); };
            var sum = function(pts){ return pts.reduce(function(a,p){ return a+(p.v||0); },0); };
            // One series shape for both modes: [{d:'YYYY-MM-DD', v:number}].
            function series(){
                if(state.daily){ var arr=state.daily[state.metric]; if(!arr) return []; return arr.map(function(v,i){ return {d: iso(addDays(state.daily.d0,i)), v: v}; }); }
                var e = state.key ? H[state.key] : null; if(!e) return [];
                return e.pts.filter(function(p){ return p[state.metric]!==null && p[state.metric]!==undefined; }).map(function(p){ return {d:p.d, v:p[state.metric]}; });
            }
            function filtered(all){
                var pts = all.slice(); if(!pts.length) return pts;
                var last = parse(pts[pts.length-1].d);
                if(state.range==='7'||state.range==='28'||state.range==='90'){ var cut=new Date(last.getTime()-(parseInt(state.range,10)-1)*dayMs); pts=pts.filter(function(p){ return parse(p.d)>=cut; }); }
                else if(state.range==='custom'){ var f=fromI.value?parse(fromI.value):null, t=toI.value?parse(toI.value):null; pts=pts.filter(function(p){ var x=parse(p.d); return (!f||x>=f)&&(!t||x<=t); }); }
                return pts;
            }
            // Comparison song (daily mode only): its values aligned to the same dates.
            function cmpVals(pts){
                if(!state.daily || !state.cmp) return null;
                var arr = state.cmp[state.metric]; if(!arr) return null;
                var cm = {}; arr.forEach(function(v,i){ cm[iso(addDays(state.cmp.d0,i))] = v; });
                return pts.map(function(p){ return (p.d in cm) ? cm[p.d] : null; });
            }
            function svg(pts){
                var W=680,Hh=190,P={l:52,r:14,t:14,b:28};
                var vals=pts.map(function(p){return p.v;});
                var cv = cmpVals(pts);
                // Daily mode reads from a zero baseline (a per-day count); history
                // mode autoscales so a slow-moving window total still shows shape.
                var mn=state.daily?0:Math.min.apply(null,vals), mx=Math.max.apply(null,vals);
                if(cv){ cv.forEach(function(v){ if(v!==null && v>mx) mx=v; }); }
                if(mn===mx){ mn=state.daily?0:mn*0.95; mx=(mx*1.05)||1; }
                var iw=W-P.l-P.r, ih=Hh-P.t-P.b;
                var x=function(i){ return P.l+(pts.length===1?iw/2:i*iw/(pts.length-1)); };
                var y=function(v){ return P.t+(1-(v-mn)/(mx-mn))*ih; };
                var d=pts.map(function(p,i){ return (i?'L':'M')+x(i).toFixed(1)+' '+y(p.v).toFixed(1); }).join(' ');
                var area=d+' L'+x(pts.length-1).toFixed(1)+' '+(Hh-P.b)+' L'+x(0).toFixed(1)+' '+(Hh-P.b)+' Z';
                var s='<svg viewBox="0 0 '+W+' '+Hh+'" width="100%" height="'+Hh+'" role="img" aria-label="History chart" style="display:block;overflow:visible;">';
                for(var g=0; g<=3; g++){ var gy=P.t+g*ih/3; s+='<line x1="'+P.l+'" x2="'+(W-P.r)+'" y1="'+gy.toFixed(1)+'" y2="'+gy.toFixed(1)+'" stroke="rgba(255,255,255,.06)"/>'; }
                s+='<text x="'+(P.l-8)+'" y="'+(P.t+4)+'" text-anchor="end" font-size="11" fill="#8B90A0">'+fmt(Math.round(mx))+'</text>';
                s+='<text x="'+(P.l-8)+'" y="'+(Hh-P.b+4)+'" text-anchor="end" font-size="11" fill="#8B90A0">'+fmt(Math.round(mn))+'</text>';
                if(pts.length>1){ s+='<path d="'+area+'" fill="#34D399" fill-opacity=".10"/><path d="'+d+'" fill="none" stroke="#34D399" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round"/>'; }
                if(cv){ // second line, violet, broken where the other song has no data on that date
                    var d2='', pen=false; cv.forEach(function(v,i){ if(v===null){ pen=false; return; } d2+=(pen?'L':'M')+x(i).toFixed(1)+' '+y(v).toFixed(1)+' '; pen=true; });
                    if(d2) s+='<path d="'+d2.trim()+'" fill="none" stroke="#7C6CF6" stroke-width="2" stroke-dasharray="5 3" stroke-linejoin="round" stroke-linecap="round"/>';
                    s+='<text x="'+(W-P.r)+'" y="'+(P.t+2)+'" text-anchor="end" font-size="11" fill="#34D399">'+(state.daily?state.daily.t:'')+'</text><text x="'+(W-P.r)+'" y="'+(P.t+15)+'" text-anchor="end" font-size="11" fill="#7C6CF6">'+state.cmp.t+'</text>';
                }
                var dots = pts.length<=60;
                pts.forEach(function(p,i){ var last=i===pts.length-1; if(!dots&&!last) return; s+='<circle cx="'+x(i).toFixed(1)+'" cy="'+y(p.v).toFixed(1)+'" r="'+(last?4.5:2.5)+'" fill="'+(last?'#34D399':'#0E0F16')+'" stroke="#34D399" stroke-width="1.5"><title>'+lab(p.d)+': '+fmt(p.v)+'</title></circle>'; });
                if(!dots){ var pi=0; vals.forEach(function(v,i){ if(v>vals[pi]) pi=i; }); s+='<circle cx="'+x(pi).toFixed(1)+'" cy="'+y(vals[pi]).toFixed(1)+'" r="3.5" fill="#D05FA2" stroke="#0E0F16" stroke-width="1"><title>Best day — '+lab(pts[pi].d)+': '+fmt(vals[pi])+'</title></circle>'; }
                if(state.daily && B.length){ var di={}; pts.forEach(function(p,i){ di[p.d]=i; }); B.forEach(function(m){ if(!(m.d in di)) return; var mx_=x(di[m.d]).toFixed(1); s+='<line x1="'+mx_+'" x2="'+mx_+'" y1="'+P.t+'" y2="'+(Hh-P.b)+'" stroke="#D05FA2" stroke-width="1" stroke-dasharray="3 3" opacity=".75"><title>Sent '+lab(m.d)+': '+(m.subject||'broadcast')+(m.clicks?' · '+fmt(m.clicks)+' clicks':'')+'</title></line><text x="'+mx_+'" y="'+(P.t-2)+'" text-anchor="middle" font-size="10" fill="#D05FA2">✉<title>Sent '+lab(m.d)+': '+(m.subject||'broadcast')+'</title></text>'; }); }
                s+='<text x="'+P.l+'" y="'+(Hh-6)+'" font-size="11" fill="#8B90A0">'+lab(pts[0].d)+'</text>';
                if(pts.length>14){ var mi=Math.floor((pts.length-1)/2); s+='<text x="'+x(mi).toFixed(1)+'" y="'+(Hh-6)+'" text-anchor="middle" font-size="11" fill="#8B90A0">'+lab(pts[mi].d)+'</text>'; }
                if(pts.length>1) s+='<text x="'+(W-P.r)+'" y="'+(Hh-6)+'" text-anchor="end" font-size="11" fill="#8B90A0">'+lab(pts[pts.length-1].d)+'</text>';
                return s+'</svg>';
            }
            function chips(all, pts){
                var items;
                if(state.daily){
                    var name = ({s:'streams',li:'listeners',sv:'saves'})[state.metric]||'streams';
                    var peak=null; pts.forEach(function(p){ if(!peak||p.v>peak.v) peak=p; });
                    items=[['Last 7 days · '+name, sum(all.slice(-7))],['Last 28 days · '+name, sum(all.slice(-28))],['Avg / day in range', pts.length?Math.round(sum(pts)/pts.length):null],['Best day in range', peak?(fmt(peak.v)+' <span style="font-size:11px;color:#8B90A0;font-weight:600;">'+lab(peak.d)+'</span>'):null]];
                } else {
                    var e = state.key ? H[state.key] : null, lastPt = (e && e.pts.length) ? e.pts[e.pts.length-1] : {};
                    items=[['Streams · 28d', lastPt.s28],['Streams · 7d', lastPt.s7],['Listeners', lastPt.li],['Saves', lastPt.sv]];
                }
                stats.innerHTML = items.map(function(c){ var v=(typeof c[1]==='string')?c[1]:fmt(c[1]); return '<div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:10px 12px;"><div style="font:800 18px/1 var(--lmegA-font,inherit);color:#F4F5F7;font-variant-numeric:tabular-nums;">'+v+'</div><div style="font:600 10px/1 var(--lmegA-font,inherit);letter-spacing:.06em;text-transform:uppercase;color:#8B90A0;margin-top:6px;">'+c[0]+'</div></div>'; }).join('');
            }
            function render(){
                if(!state.daily && !state.key) return;
                var mlist = state.daily ? DM : HM;
                mWrap.innerHTML=''; mlist.forEach(function(m){ mWrap.appendChild(btn(m[1], state.metric===m[0], function(){ state.metric=m[0]; render(); })); });
                rWrap.innerHTML=''; RANGES.forEach(function(r){ if(r[0]==='90' && !state.daily) return; rWrap.appendChild(btn(r[1], state.range===r[0], function(){ state.range=r[0]; render(); })); });
                cust.style.display = (state.range==='custom') ? 'inline-flex' : 'none';
                if(cmpSel) cmpSel.style.display = state.daily ? '' : 'none';
                var all = series(), pts = filtered(all);
                chips(all, pts);
                if(!pts.length){ chart.innerHTML='<div style="color:#8B90A0;font-size:13px;padding:30px 0;text-align:center;">No data in this range for this metric.</div>'; }
                else if(pts.length===1){ chart.innerHTML='<div style="padding:26px 0;text-align:center;"><div style="font:800 34px/1 var(--lmegA-font,inherit);color:#F4F5F7;">'+fmt(pts[0].v)+'</div><div style="color:#8B90A0;font-size:12px;margin-top:6px;">'+(state.daily?lab(pts[0].d)+' — one day in this range':'captured '+parse(pts[0].d).toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'})+' — one point so far; the line draws itself as daily captures land')+'</div></div>'; }
                else { chart.innerHTML = svg(pts); }
                if(state.daily){
                    var total=sum(pts), len=pts.length, start=len?all.map(function(p){return p.d;}).indexOf(pts[0].d):-1;
                    var prev=(start>=len)?all.slice(start-len,start):[], ch=(prev.length===len&&len)?pct(total,sum(prev)):null;
                    // Campaign lift for sends inside this range: avg of the 3 days from the
                    // send day vs the 3 before (full windows only, computed on ALL days).
                    var lifts=[]; if(B.length){ var ai={}; all.forEach(function(p,i){ ai[p.d]=i; }); var inR={}; pts.forEach(function(p){ inR[p.d]=1; });
                        B.forEach(function(m){ if(!(m.d in ai)||!inR[m.d]) return; var i=ai[m.d]; if(i-3<0||i+2>=all.length) return; var bf=(all[i-3].v+all[i-2].v+all[i-1].v)/3, af=(all[i].v+all[i+1].v+all[i+2].v)/3; if(bf<=0) return; lifts.push({d:m.d,s:m.subject||'broadcast',pct:(af-bf)/bf*100}); }); }
                    var liftTxt = lifts.slice(-2).map(function(l){ return ' ✉ '+lab(l.d)+' “'+l.s+'”: '+(l.pct>=0?'+':'')+l.pct.toFixed(1)+'% over the 3 days after'; }).join(' ·');
                    var cmpTxt = '';
                    if(state.cmp){ var cv2 = cmpVals(pts) || [], ct = 0, cn = 0; cv2.forEach(function(v){ if(v!==null){ ct+=v; cn++; } }); if(cn){ var rel = ct>0 ? (total-ct)/ct*100 : null; cmpTxt = ' Compared with “'+state.cmp.t+'”: '+fmt(ct)+' over the same '+cn+' day'+(cn===1?'':'s')+(rel!==null?(' — this song '+(rel>=0?'+':'')+rel.toFixed(1)+'% vs it'):'')+'.'; } }
                    note.textContent = 'Day by day from Spotify for Artists · '+len+' day'+(len===1?'':'s')+' · '+fmt(total)+' total'+(ch!==null?(' · '+(ch>=0?'+':'')+ch.toFixed(1)+'% vs the previous '+len+' days'):'')+(all.length?(' · through '+lab(all[all.length-1].d)):'')+'.'+(liftTxt?(' Campaign lift —'+liftTxt+'.'):'')+cmpTxt;
                } else {
                    var delta=(pts.length>1)?(pts[pts.length-1].v-pts[0].v):null, caps=all.length;
                    note.textContent = 'History builds one point per daily capture — '+caps+' capture'+(caps===1?'':'s')+' so far'+(delta!==null?(' · '+(delta>=0?'+':'')+fmt(delta)+' over this range'):'')+'.'+(DL.length?' Day-by-day detail covers the top 20 songs by streams.':' Day-by-day detail for the top 20 songs arrives with the next pull.');
                }
            }
            function open(title){
                var e = find(title), dd = D[n(title)] || null;
                if(!e && !dd) return;
                state.daily = dd; state.key = null; state.cmp = null;
                if(cmpSel){ // other songs with day-by-day data
                    cmpSel.innerHTML = '<option value="">Compare with…</option>';
                    Object.keys(D).forEach(function(k){ if(dd && D[k]===dd) return; var o=document.createElement('option'); o.value=k; o.textContent=D[k].t; cmpSel.appendChild(o); });
                    cmpSel.value = '';
                }
                if(e){ for(var key in H){ if(H[key]===e){ state.key=key; break; } } }
                var mlist = dd ? DM : HM;
                if(!mlist.some(function(m){ return m[0]===state.metric; })) state.metric = mlist[0][0];
                if(state.range==='90' && !dd) state.range='28';
                ttl.textContent = dd ? dd.t : e.title;
                if(kick) kick.textContent = dd ? 'Song · day by day' : 'Song history';
                var all = series();
                if(all.length){ fromI.value=all[0].d; toI.value=all[all.length-1].d; }
                ov.style.display='flex'; document.body.style.overflow='hidden';
                render();
                actions(dd ? dd.t : e.title, dd);
                $('lmeg-song-ov-close').focus();
            }
            // "What to do with it" for THIS song: the Compose angle follows the real
            // week-over-week (up ≥10% → mover, down ≥10% → re-push, else feature),
            // plus "Ask fans to save it" and Open on Spotify when the uri is known.
            function actions(title, dd){
                var wrap = $('lmeg-song-ov-actions'); if(!wrap) return; wrap.innerHTML='';
                var wow = null;
                if(dd && dd.s && dd.s.length >= 14){ var l7=0,p7=0,n_=dd.s.length; for(var i=n_-7;i<n_;i++) l7+=dd.s[i]; for(var j=n_-14;j<n_-7;j++) p7+=dd.s[j]; if(p7>0) wow=(l7-p7)/p7*100; }
                var angle = (wow!==null && wow>=10) ? 'mover' : ((wow!==null && wow<=-10) ? 'repush' : 'feature');
                var uri = U[n(title)] || '';
                var mk = function(label, href, ext, primary){ var a=document.createElement('a'); a.href=href; a.textContent=label; if(ext){ a.target='_blank'; a.rel='noopener'; } a.style.cssText='font-size:12px;font-weight:700;color:#fff;text-decoration:none;border-radius:999px;padding:6px 12px;border:1px solid '+(primary?'#D05FA2':'rgba(255,255,255,.16)')+';background:'+(primary?'#D05FA2':'rgba(255,255,255,.06)')+';'; return a; };
                var q = function(a){ return COMPOSE+'&angle='+encodeURIComponent(a)+'&song='+encodeURIComponent(title)+(uri?'&uri='+encodeURIComponent(uri):''); };
                wrap.appendChild(mk(angle==='mover' ? 'Send it to your list' : (angle==='repush' ? 'Re-push it to your list' : 'Feature it to your list'), q(angle), false, true));
                wrap.appendChild(mk('Ask fans to save it', q('save'), false, false));
                if(/^spotify:track:[A-Za-z0-9]{22}$/.test(uri)) wrap.appendChild(mk('Open on Spotify ↗', 'https://open.spotify.com/track/'+uri.split(':')[2], true, false));
            }
            function close(){ ov.style.display='none'; document.body.style.overflow=''; }
            document.querySelectorAll('.lmeg-song-row').forEach(function(row){
                row.addEventListener('click', function(){ open(row.getAttribute('data-song')); });
                row.addEventListener('keydown', function(ev){ if(ev.key==='Enter'||ev.key===' '){ ev.preventDefault(); open(row.getAttribute('data-song')); } });
                row.addEventListener('mouseenter', function(){ row.style.background='rgba(255,255,255,.04)'; });
                row.addEventListener('mouseleave', function(){ row.style.background=''; });
            });
            $('lmeg-song-ov-close').addEventListener('click', close);
            ov.addEventListener('click', function(ev){ if(ev.target===ov) close(); });
            document.addEventListener('keydown', function(ev){ if(ev.key==='Escape' && ov.style.display!=='none') close(); });
            fromI.addEventListener('change', render); toI.addEventListener('change', render);
            if(cmpSel) cmpSel.addEventListener('change', function(){ state.cmp = D[cmpSel.value] || null; render(); });
        })();
        </script>
        <?php endif; ?>

        <!-- TRACKS ON SPOTIFY BUT NOT IN YOUR S4A CATALOG (features/collabs) --->
        <?php if ($has_s4a && !empty($reconcile['api_only'])) : ?>
        <div style="<?php echo $card; ?>max-width:1040px;margin-bottom:14px;">
            <div style="<?php echo $lbl; ?>margin-bottom:4px;">Also on Spotify · not in your catalog</div>
            <p style="color:#8B90A0;font-size:12px;margin:0 0 10px;">You appear on these in Spotify's public data, but they aren't in your Spotify&nbsp;for&nbsp;Artists catalog — usually features or collabs on other artists' releases.</p>
            <?php foreach (array_slice($reconcile['api_only'], 0, 8) as $t) : ?>
            <div style="display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:13px;">
                <span style="color:#F4F5F7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($t['name']); ?><?php if (!empty($t['album'])) : ?> <span style="color:#8B90A0;font-size:11px;"><?php echo esc_html($t['album']); ?></span><?php endif; ?></span>
                <span style="color:#E58BBD;font-variant-numeric:tabular-nums;flex:0 0 auto;" title="Spotify public popularity 0–100">◍ <?php echo (int) $t['popularity']; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- RELEASES (by streams) --------------------------------------------->
        <?php $mark('songs'); ?>
        <?php
        $rel_meta = ($has_s4a && $snap->meta) ? (array) json_decode((string) $snap->meta, true) : [];
        $releases = lmeg_si_releases_sorted($rel_meta['releases'] ?? []);
        // Cross-link to Fanloop release pages — exact match by Spotify album id
        // (from the release's stored links), else by normalized title.
        // Each match carries the page URL + the drop id, so link clicks from the
        // Fanloop release page can sit right next to the Spotify streams.
        $fl_by_id = []; $fl_by_title = []; $fl_drops = [];
        if ($releases && function_exists('lmeg_releases_all') && function_exists('lmeg_release_public_url')) {
            foreach ((array) lmeg_releases_all() as $fr) {
                $u = lmeg_release_public_url($fr);
                if (!$u) continue;
                $ent = ['url' => $u, 'drop_id' => (int) ($fr->drop_id ?? 0)];
                if ($ent['drop_id']) $fl_drops[] = $ent['drop_id'];
                $aid = lmeg_si_spotify_album_id((string) ($fr->links ?? ''));
                if ($aid !== '') $fl_by_id[$aid] = $ent;
                $nt = strtolower(trim((string) ($fr->title ?? '')));
                if ($nt !== '' && !isset($fl_by_title[$nt])) $fl_by_title[$nt] = $ent;
            }
        }
        $clicks_map = $fl_drops ? lmeg_si_release_clicks_map($fl_drops) : [];
        // Demo: the sample "Blue Hour" release page carries the sample analytics.
        if ($demo && function_exists('lmeg_release_demo_analytics')) {
            $dA = lmeg_release_demo_analytics();
            $fl_by_title['blue hour'] = ['url' => admin_url('admin.php?page=lmeg-releases&demo=1'), 'drop_id' => -1];
            $clicks_map[-1] = ['total' => (int) $dA['stats']['total'], 'known' => (int) $dA['stats']['known']];
        }
        $clicks_total = 0; $clicks_pages = 0;
        if ($releases) : $maxR = 1; foreach ($releases as $r) { $maxR = max($maxR, $r['streams']); } ?>
        <div style="<?php echo $card; ?>max-width:1040px;margin-bottom:14px;">
            <div style="<?php echo $lbl; ?>margin-bottom:12px;">Releases · by streams <span style="color:#8B90A0;font-weight:400;">(<?php echo count($releases); ?>)</span><?php if ($clicks_map) : ?> <span style="color:#8B90A0;font-weight:400;">· ↗ = has a Fanloop release page · clicks are from that page</span><?php endif; ?></div>
            <div style="display:flex;flex-direction:column;gap:9px;max-height:420px;overflow:auto;">
                <?php foreach (array_slice($releases, 0, 20) as $i => $r) :
                    $st = (int) $r['streams']; $w = max(2, round($st / $maxR * 100));
                    $yr = ($r['date'] && strlen($r['date']) >= 4) ? substr($r['date'], 0, 4) : '';
                    $type = $r['type'] ? ucwords(strtolower(str_replace('_', ' ', $r['type']))) : '';
                    $sub = trim(implode(' · ', array_filter([$type, $yr])));
                    $fl = null;
                    $aid = lmeg_si_spotify_album_id($r['uri']);
                    if ($aid !== '' && isset($fl_by_id[$aid])) $fl = $fl_by_id[$aid];
                    elseif (isset($fl_by_title[strtolower(trim($r['name']))])) $fl = $fl_by_title[strtolower(trim($r['name']))];
                    $flu = $fl['url'] ?? '';
                    $ck  = ($fl && isset($clicks_map[$fl['drop_id']])) ? $clicks_map[$fl['drop_id']] : null;
                    if ($ck) { $clicks_total += $ck['total']; $clicks_pages++; } ?>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:20px;text-align:right;color:#8B90A0;font-size:12px;font-variant-numeric:tabular-nums;flex:0 0 auto;"><?php echo $i + 1; ?></div>
                        <div style="flex:1 1 auto;min-width:0;">
                            <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:4px;">
                                <span style="color:#F4F5F7;font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($r['name']); ?><?php if ($sub) : ?> <span style="color:#8B90A0;font-size:11px;font-weight:400;"><?php echo esc_html($sub); ?></span><?php endif; ?></span>
                                <span style="display:flex;align-items:center;gap:8px;flex:0 0 auto;"><?php if ($flu) : ?><a href="<?php echo esc_url($flu); ?>" target="_blank" rel="noopener" title="Open this release's Fanloop page" style="font-size:11px;text-decoration:none;">↗</a><?php endif; ?><span style="color:#F4F5F7;font-size:13px;font-variant-numeric:tabular-nums;"><?php echo number_format_i18n($st); ?></span></span>
                            </div>
                            <div style="height:6px;border-radius:6px;background:rgba(255,255,255,.06);overflow:hidden;"><div style="height:100%;width:<?php echo $w; ?>%;background:linear-gradient(90deg,#D05FA2,#E58BBD);border-radius:6px;"></div></div>
                            <?php if ($ck) : ?>
                            <div style="margin-top:3px;font-size:11px;color:#8B90A0;"><span style="color:#E58BBD;">↗</span> <?php echo number_format_i18n($ck['total']); ?> link click<?php echo $ck['total'] === 1 ? '' : 's'; ?> from its Fanloop page<?php if ($ck['known']) : ?> · <?php echo number_format_i18n($ck['known']); ?> known fan<?php echo $ck['known'] === 1 ? '' : 's'; ?><?php endif; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($clicks_pages) : ?>
            <p style="margin:10px 0 0;font-size:12px;color:#C9CCD6;"><span style="color:#E58BBD;">↗</span> Your Fanloop release pages sent <strong style="color:#F4F5F7;"><?php echo number_format_i18n($clicks_total); ?></strong> clicks to streaming services across <?php echo (int) $clicks_pages; ?> release<?php echo $clicks_pages === 1 ? '' : 's'; ?> — listeners you sent there yourself.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- LAUNCH COMPARISON — first 7 / 28 days of each single, like-for-like -->
        <?php
        $launches = lmeg_si_launch_compare(($has_api && !empty($ov['releases'])) ? $ov['releases'] : [], isset($sd_map) ? $sd_map : lmeg_si_song_daily_map((array) ($az_meta['song_daily'] ?? [])));
        if (count($launches) >= 1) : $lmax = 1; foreach ($launches as $L) { $lmax = max($lmax, (int) ($L['first28'] ?? $L['first7'])); } ?>
        <div style="<?php echo $card; ?>max-width:1040px;margin-bottom:14px;">
            <div style="<?php echo $lbl; ?>margin-bottom:4px;"><?php echo count($launches) > 1 ? 'Launch comparison' : 'Launch'; ?> <span style="color:#8B90A0;font-weight:400;">· first 7 and 28 days<?php echo count($launches) > 1 ? ', like-for-like' : ''; ?></span></div>
            <p style="color:#8B90A0;font-size:12px;margin:0 0 12px;"><?php echo count($launches) > 1 ? 'Releases matched to a song with day-by-day data (singles, mostly). Same clock for every launch, so a bigger catalogue doesn’t flatter the older ones.' : 'Your one release inside the day-by-day window. The next single will line up beneath it on the same clock.'; ?></p>
            <div style="display:flex;flex-direction:column;gap:9px;">
                <?php foreach (array_slice($launches, 0, 8) as $L) : $v = (int) ($L['first28'] ?? $L['first7']); $w = max(2, round($v / $lmax * 100)); ?>
                <div>
                    <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:4px;font-size:13px;">
                        <span style="color:#F4F5F7;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($L['name']); ?> <span style="color:#8B90A0;font-size:11px;font-weight:400;"><?php echo esc_html(date_i18n('M j, Y', strtotime($L['date']))); ?></span></span>
                        <span style="color:#F4F5F7;font-variant-numeric:tabular-nums;flex:0 0 auto;"><span style="color:#8B90A0;font-size:11px;">first 7d</span> <?php echo number_format_i18n($L['first7']); ?> <span style="color:#8B90A0;font-size:11px;margin-left:8px;">first 28d</span> <?php echo $L['first28'] !== null ? number_format_i18n($L['first28']) : '<span style="color:#8B90A0;">' . (int) $L['days'] . ' days so far</span>'; ?></span>
                    </div>
                    <div style="height:6px;border-radius:6px;background:rgba(255,255,255,.06);overflow:hidden;"><div style="height:100%;width:<?php echo $w; ?>%;background:linear-gradient(90deg,#34D399,#7C6CF6);border-radius:6px;"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- WHO'S LISTENING (gender · age · cities) -------------------------->
        <?php $mark('releases'); ?>
        <?php
        $meta   = ($has_s4a && $snap->meta) ? (array) json_decode((string) $snap->meta, true) : [];
        $gender = lmeg_si_gender_split($meta['gender'] ?? null);
        $ages   = lmeg_si_age_rows($meta['gender_by_age'] ?? null);
        $cities = array_values(array_filter((array) ($meta['top_cities'] ?? []), 'is_array'));
        if ($gender || $ages || $cities) : ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;max-width:1040px;margin-bottom:14px;">
            <?php if ($gender) : ?>
            <div style="<?php echo $card; ?>">
                <div style="<?php echo $lbl; ?>margin-bottom:12px;">Who's listening · gender</div>
                <div style="display:flex;height:12px;border-radius:8px;overflow:hidden;background:rgba(255,255,255,.06);margin-bottom:12px;">
                    <?php foreach ($gender as $g) : ?>
                        <div title="<?php echo esc_attr($g['label']); ?>" style="width:<?php echo round($g['pct'], 2); ?>%;background:<?php echo $g['color']; ?>;"></div>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($gender as $g) : ?>
                    <div style="display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px;">
                        <span style="width:9px;height:9px;border-radius:3px;background:<?php echo $g['color']; ?>;flex:0 0 auto;"></span>
                        <span style="color:#F4F5F7;flex:1 1 auto;"><?php echo esc_html($g['label']); ?></span>
                        <span style="color:#F4F5F7;font-variant-numeric:tabular-nums;font-weight:600;"><?php echo rtrim(rtrim(number_format($g['pct'], 1), '0'), '.'); ?>%</span>
                        <span style="color:#8B90A0;font-variant-numeric:tabular-nums;min-width:64px;text-align:right;"><?php echo number_format_i18n($g['count']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($ages) : $maxAge = 1; foreach ($ages as $r) { $maxAge = max($maxAge, $r['total']); } ?>
            <div style="<?php echo $card; ?>">
                <div style="<?php echo $lbl; ?>margin-bottom:12px;">By age</div>
                <?php foreach ($ages as $r) : $w = max(2, round($r['total'] / $maxAge * 100)); ?>
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                        <span style="width:44px;color:#8B90A0;font-size:12px;flex:0 0 auto;font-variant-numeric:tabular-nums;"><?php echo esc_html($r['label']); ?></span>
                        <span style="flex:1 1 auto;height:8px;border-radius:6px;background:rgba(255,255,255,.06);overflow:hidden;"><span style="display:block;height:100%;width:<?php echo $w; ?>%;background:linear-gradient(90deg,#7C6CF6,#D05FA2);border-radius:6px;"></span></span>
                        <span style="color:#F4F5F7;font-size:12px;font-variant-numeric:tabular-nums;min-width:56px;text-align:right;flex:0 0 auto;"><?php echo number_format_i18n($r['total']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($cities) : $maxCity = 1; foreach ($cities as $c) { $maxCity = max($maxCity, (int) ($c['num'] ?? 0)); } ?>
            <div style="<?php echo $card; ?>max-height:360px;overflow:auto;">
                <div style="<?php echo $lbl; ?>margin-bottom:12px;">Top cities <span style="color:#8B90A0;font-weight:400;">(<?php echo count($cities); ?>)</span></div>
                <?php foreach (array_slice($cities, 0, 12) as $c) :
                    $name = (string) ($c['name'] ?? ''); $num = (int) ($c['num'] ?? 0);
                    $loc  = trim(implode(', ', array_filter([(string) ($c['region'] ?? ''), (string) ($c['country'] ?? '')])));
                    $w = max(2, round($num / $maxCity * 100)); ?>
                    <div style="margin-bottom:9px;">
                        <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:3px;font-size:13px;">
                            <span style="color:#F4F5F7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($name); ?><?php if ($loc) : ?> <span style="color:#8B90A0;font-size:11px;"><?php echo esc_html($loc); ?></span><?php endif; ?></span>
                            <span style="color:#F4F5F7;font-variant-numeric:tabular-nums;flex:0 0 auto;"><?php echo number_format_i18n($num); ?></span>
                        </div>
                        <div style="height:5px;border-radius:6px;background:rgba(255,255,255,.06);overflow:hidden;"><div style="height:100%;width:<?php echo $w; ?>%;background:linear-gradient(90deg,#1DB954,#34D399);border-radius:6px;"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ACROSS PLATFORMS (streaming ↔ social — one audience) ------------->
        <?php
        $sp_gender = lmeg_si_norm_gender($meta['gender'] ?? null);
        $ig_gender = lmeg_si_norm_gender(($ig_demo['gender'] ?? null));
        $sp_age    = lmeg_si_norm_age(lmeg_si_age_rows($meta['gender_by_age'] ?? null));
        $ig_age    = lmeg_si_norm_age(($ig_demo['age'] ?? null));
        $any_social = $ig_stats || $fb_stats || $ig_demo;
        if ($any_social) :
            $genderBar = function ($norm) {
                if (!$norm) return '<span style="color:#8B90A0;font-size:12px;">Not connected yet</span>';
                $h = '<div style="display:flex;height:10px;border-radius:6px;overflow:hidden;background:rgba(255,255,255,.06);margin-bottom:6px;">';
                foreach ($norm['rows'] as $r) $h .= '<div title="' . esc_attr($r['label']) . '" style="width:' . round($r['pct'], 2) . '%;background:' . $r['color'] . ';"></div>';
                $h .= '</div><div style="font-size:12px;color:#8B90A0;">';
                foreach ($norm['rows'] as $r) if (in_array($r['label'], ['Women', 'Men'], true)) $h .= '<span style="margin-right:12px;"><span style="color:#F4F5F7;font-weight:600;">' . round($r['pct']) . '%</span> ' . esc_html($r['label']) . '</span>';
                return $h . '</div>';
            };
        ?>
        <div style="<?php echo $card; ?>max-width:1040px;margin-bottom:14px;">
            <div style="<?php echo $lbl; ?>margin-bottom:4px;">Across platforms · one audience</div>
            <p style="color:#8B90A0;font-size:12px;margin:0 0 14px;">How your streaming audience (Spotify) lines up with your social audience (Instagram).</p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px;">
                <?php
                $reach = function ($plat, $val, $sub) use ($lbl) {
                    if ($val === null) return;
                    echo '<div style="background:#12141F;border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:10px 12px;">'
                        . '<div style="font:800 18px/1 var(--lmegA-font,inherit);font-variant-numeric:tabular-nums;color:#F4F5F7;">' . esc_html(number_format_i18n((int) $val)) . '</div>'
                        . '<div style="' . $lbl . 'margin-top:5px;">' . esc_html($plat) . '</div>'
                        . ($sub ? '<div style="font-size:11px;color:#8B90A0;margin-top:2px;">' . esc_html($sub) . '</div>' : '') . '</div>';
                };
                if ($snap && $snap->monthly_listeners !== null) $reach('Spotify · listeners', $snap->monthly_listeners, 'monthly');
                if ($followers !== null) $reach('Spotify · followers', $followers, '');
                if ($ig_stats) $reach('Instagram', $ig_stats['followers'] ?? null, 'followers' . (!empty($ig_stats['username']) ? ' · @' . $ig_stats['username'] : ''));
                if ($fb_stats) $reach('Facebook', $fb_stats['followers'] ?? null, 'followers');
                ?>
            </div>
            <?php if ($sp_gender || $ig_gender) : ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">
                <div><div style="<?php echo $lbl; ?>margin-bottom:8px;color:#1DB954;">Spotify · gender</div><?php echo $genderBar($sp_gender); ?></div>
                <div><div style="<?php echo $lbl; ?>margin-bottom:8px;color:#E58BBD;">Instagram · gender</div><?php echo $genderBar($ig_gender); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($sp_age || $ig_age) : $canon = ['<18', '18-24', '25-34', '35-44', '45-54', '55-64', '65+']; ?>
            <div style="margin-top:16px;">
                <div style="<?php echo $lbl; ?>margin-bottom:10px;">Age — <span style="color:#1DB954;">Spotify</span> vs <span style="color:#E58BBD;">Instagram</span></div>
                <?php foreach ($canon as $b) : $sv = $sp_age[$b] ?? null; $iv = $ig_age[$b] ?? null; if ($sv === null && $iv === null) continue; ?>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                    <span style="width:46px;color:#8B90A0;font-size:12px;flex:0 0 auto;font-variant-numeric:tabular-nums;"><?php echo esc_html($b); ?></span>
                    <div style="flex:1 1 auto;">
                        <div style="display:flex;align-items:center;gap:6px;margin-bottom:3px;"><span style="flex:1 1 auto;height:7px;border-radius:5px;background:rgba(255,255,255,.06);overflow:hidden;"><span style="display:block;height:100%;width:<?php echo $sv !== null ? round($sv) : 0; ?>%;background:#1DB954;border-radius:5px;"></span></span><span style="width:36px;text-align:right;font-size:11px;color:#8B90A0;font-variant-numeric:tabular-nums;"><?php echo $sv !== null ? round($sv) . '%' : '—'; ?></span></div>
                        <div style="display:flex;align-items:center;gap:6px;"><span style="flex:1 1 auto;height:7px;border-radius:5px;background:rgba(255,255,255,.06);overflow:hidden;"><span style="display:block;height:100%;width:<?php echo $iv !== null ? round($iv) : 0; ?>%;background:#E58BBD;border-radius:5px;"></span></span><span style="width:36px;text-align:right;font-size:11px;color:#8B90A0;font-variant-numeric:tabular-nums;"><?php echo $iv !== null ? round($iv) . '%' : '—'; ?></span></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php
            $sp_cities = lmeg_si_city_list($meta['top_cities'] ?? [], 8);
            $ig_cities = lmeg_si_city_list($ig_demo['city'] ?? [], 8);
            if ($sp_cities || $ig_cities) :
                $cityCol = function ($cities, $accent) {
                    if (!$cities) return '<span style="color:#8B90A0;font-size:12px;">No city data yet</span>';
                    $max = 1; foreach ($cities as $c) $max = max($max, $c['count']);
                    $h = '';
                    foreach ($cities as $c) {
                        $w = max(2, round($c['count'] / $max * 100));
                        $h .= '<div style="margin-bottom:7px;"><div style="display:flex;justify-content:space-between;gap:8px;font-size:12px;margin-bottom:2px;"><span style="color:#F4F5F7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . esc_html($c['name']) . ($c['sub'] ? ' <span style="color:#8B90A0;font-size:10px;">' . esc_html($c['sub']) . '</span>' : '') . '</span><span style="color:#8B90A0;font-variant-numeric:tabular-nums;flex:0 0 auto;">' . number_format_i18n($c['count']) . '</span></div><div style="height:5px;border-radius:5px;background:rgba(255,255,255,.06);overflow:hidden;"><div style="height:100%;width:' . $w . '%;background:' . $accent . ';border-radius:5px;"></div></div></div>';
                    }
                    return $h;
                };
            ?>
            <div style="margin-top:16px;">
                <div style="<?php echo $lbl; ?>margin-bottom:10px;">Top cities — <span style="color:#1DB954;">Spotify</span> vs <span style="color:#E58BBD;">Instagram</span></div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;">
                    <div><div style="font-size:11px;color:#1DB954;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Spotify listeners</div><?php echo $cityCol($sp_cities, '#1DB954'); ?></div>
                    <div><div style="font-size:11px;color:#E58BBD;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Instagram followers</div><?php echo $cityCol($ig_cities, '#E58BBD'); ?></div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!$ig_demo && ($ig_stats || $fb_stats)) : ?>
            <p style="color:#8B90A0;font-size:12px;margin:14px 0 0;">Connect Instagram insights to compare audience demographics — <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-social')); ?>">Social Listening →</a></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- SECONDARY GRID: markets, playlists, top tracks (popularity) -------->
        <?php
        $markets   = $has_s4a ? (array) json_decode((string) $snap->top_countries, true) : [];
        $playlists = $has_s4a ? (array) json_decode((string) $snap->top_playlists, true) : [];
        $toptracks = $has_api ? (array) ($ov['top_tracks'] ?? []) : [];
        if ($markets || $playlists || $toptracks) : ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;max-width:1040px;margin-bottom:14px;">
            <?php
            $country_rows = $has_s4a ? lmeg_si_country_rows((array) ($meta['countries'] ?? []), 8) : [];
            if ($country_rows) : ?>
            <div style="<?php echo $card; ?>">
                <div style="<?php echo $lbl; ?>margin-bottom:10px;">Where they listen <span style="color:#8B90A0;font-weight:400;">· monthly listeners<?php if (!empty($az_moves['moves'])) : ?> · vs <?php echo (int) $az_moves['days']; ?>d ago<?php endif; ?></span></div>
                <?php $mv_all = !empty($az_moves['moves']) ? $az_moves['moves']['all'] : []; foreach ($country_rows as $i => $c) : $mp = $mv_all[$c['cc']] ?? null; ?>
                    <div style="padding:6px 0;border-bottom:1px solid rgba(255,255,255,.06);">
                        <div style="display:flex;align-items:center;gap:9px;font-size:13px;">
                            <span style="color:#8B90A0;width:14px;flex:0 0 auto;font-variant-numeric:tabular-nums;"><?php echo $i + 1; ?></span>
                            <span style="flex:0 0 auto;font-size:15px;line-height:1;"><?php echo esc_html($c['flag']); ?></span>
                            <span style="color:#F4F5F7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($c['name']); ?></span>
                            <span style="color:#F4F5F7;margin-left:auto;font-variant-numeric:tabular-nums;flex:0 0 auto;"><?php echo esc_html(number_format_i18n($c['num'])); ?><?php if ($mp !== null) : ?> <span style="font-size:11px;font-weight:600;color:<?php echo $mp >= 1 ? '#34D399' : ($mp <= -1 ? '#F87171' : '#8B90A0'); ?>;" title="vs <?php echo (int) $az_moves['days']; ?> days ago"><?php echo $mp >= 1 ? '▲' : ($mp <= -1 ? '▼' : '·'); ?><?php echo esc_html(rtrim(rtrim(number_format(abs($mp), 1), '0'), '.')); ?>%</span><?php endif; ?></span>
                        </div>
                        <div style="height:5px;border-radius:3px;background:rgba(255,255,255,.06);margin-top:5px;overflow:hidden;"><div style="height:100%;width:<?php echo (float) $c['share']; ?>%;background:#1DB954;border-radius:3px;"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php elseif ($markets) : ?>
            <div style="<?php echo $card; ?>">
                <div style="<?php echo $lbl; ?>margin-bottom:10px;">Where they listen</div>
                <?php foreach (array_slice($markets, 0, 8) as $i => $m) :
                    $name = is_array($m) ? (string) ($m['name'] ?? $m['x'] ?? '') : (string) $m; ?>
                    <div style="display:flex;gap:10px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:13px;"><span style="color:#8B90A0;width:16px;flex:0 0 auto;"><?php echo $i + 1; ?></span><span style="color:#F4F5F7;"><?php echo esc_html($name); ?></span></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($playlists) : ?>
            <div style="<?php echo $card; ?>max-height:360px;overflow:auto;">
                <div style="<?php echo $lbl; ?>margin-bottom:10px;">Top playlists <span style="color:#8B90A0;font-weight:400;">(<?php echo count($playlists); ?>)</span></div>
                <?php $mix = lmeg_si_playlist_mix($playlists); if ($mix) : ?>
                <div style="display:flex;height:10px;border-radius:6px;overflow:hidden;background:rgba(255,255,255,.06);margin-bottom:8px;">
                    <?php foreach ($mix as $m) : ?><div title="<?php echo esc_attr($m['label']); ?>" style="width:<?php echo round($m['pct'], 2); ?>%;background:<?php echo $m['color']; ?>;"></div><?php endforeach; ?>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px;font-size:11px;">
                    <?php foreach ($mix as $m) : ?><span style="color:#8B90A0;"><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:<?php echo $m['color']; ?>;margin-right:5px;"></span><?php echo esc_html($m['label']); ?> <span style="color:#F4F5F7;font-weight:600;"><?php echo round($m['pct']); ?>%</span></span><?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php
                $pl_type = ['curated' => 'Editorial', 'listener' => 'Listener', 'personalized' => 'Algorithmic'];
                // NEW chip for playlists that weren't in the previous capture.
                $pl_new = [];
                if ($has_s4a && !empty($prev)) { foreach (lmeg_si_playlist_diff($playlists, json_decode((string) $prev->top_playlists, true))['new'] as $np) $pl_new[strtolower(trim($np['title']))] = true; }
                foreach (array_slice($playlists, 0, 10) as $p) :
                    if (!is_array($p)) continue;
                    $name = (string) ($p['title'] ?? $p['name'] ?? '');
                    if ($name === '') continue;
                    $isNew = isset($pl_new[strtolower(trim($name))]);
                    $val = isset($p['streams']) ? number_format_i18n((int) $p['streams']) : '';
                    $tl  = $pl_type[(string) ($p['type'] ?? '')] ?? '';
                    $fol = (isset($p['followers']) && $p['followers'] !== null && $p['followers'] !== '') ? (int) $p['followers'] : null;
                    $sub = trim(implode(' · ', array_filter([(string) ($p['author'] ?? ''), $tl, $fol !== null ? number_format_i18n($fol) . ' followers' : ''])));
                ?>
                    <div style="padding:6px 0;border-bottom:1px solid rgba(255,255,255,.06);">
                        <div style="display:flex;justify-content:space-between;gap:10px;font-size:13px;">
                            <span style="color:#F4F5F7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($name); ?><?php if ($isNew) : ?> <span style="font-size:9px;font-weight:800;letter-spacing:.06em;color:#0E0F16;background:#34D399;border-radius:20px;padding:2px 6px;vertical-align:middle;" title="Not in your previous capture">NEW</span><?php endif; ?></span>
                            <span style="color:#F4F5F7;font-variant-numeric:tabular-nums;flex:0 0 auto;"><?php echo esc_html($val); ?></span>
                        </div>
                        <?php if ($sub) : ?><div style="font-size:11px;color:#8B90A0;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($sub); ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($toptracks) : ?>
            <div style="<?php echo $card; ?>">
                <div style="<?php echo $lbl; ?>margin-bottom:10px;">Popular now <span style="color:#8B90A0;font-weight:400;">· Spotify</span></div>
                <?php foreach (array_slice($toptracks, 0, 8) as $tr) :
                    if (!is_array($tr)) continue; ?>
                    <div style="display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:13px;"><span style="color:#F4F5F7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html((string) ($tr['name'] ?? '')); ?></span><span style="color:#8B90A0;font-variant-numeric:tabular-nums;flex:0 0 auto;"><?php echo (int) ($tr['popularity'] ?? 0); ?></span></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- MOMENTUM / IMPACT ------------------------------------------------->
        <?php $mark('who_cross_secondary'); ?>
        <?php
        $impact = function_exists('lmeg_impact_rows') ? lmeg_impact_rows(7) : [];
        $mark('impact_rows');
        if ($impact) : ?>
        <div style="<?php echo $card; ?>max-width:1040px;margin-bottom:14px;">
            <div style="<?php echo $lbl; ?>margin-bottom:4px;">Momentum · what moved the needle</div>
            <p style="<?php echo $muted; ?>font-size:12px;margin:0 0 10px;">Follower &amp; popularity change in the 7 days after each release and broadcast. Directional, not causal.</p>
            <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead><tr style="<?php echo $lbl; ?>text-align:left;">
                    <th style="padding:6px 8px;">When</th><th style="padding:6px 8px;">Initiative</th>
                    <th style="padding:6px 8px;text-align:right;">Followers</th><th style="padding:6px 8px;text-align:right;">Popularity</th>
                </tr></thead>
                <tbody>
                <?php foreach (array_slice($impact, 0, 8) as $r) :
                    $fd = $r['fdelta'] ?? null; $pd = $r['pdelta'] ?? null;
                    $dc = function ($d) { if ($d === null) return '<span style="color:#8B90A0;">—</span>'; $c = $d > 0 ? '#34D399' : ($d < 0 ? '#F87171' : '#8B90A0'); return '<span style="color:' . $c . ';font-variant-numeric:tabular-nums;">' . ($d > 0 ? '+' : '') . number_format_i18n($d) . '</span>'; };
                ?>
                    <tr style="border-top:1px solid rgba(255,255,255,.06);">
                        <td style="padding:6px 8px;color:#8B90A0;white-space:nowrap;"><?php echo esc_html($r['date'] ?? ''); ?></td>
                        <td style="padding:6px 8px;color:#F4F5F7;"><span style="color:#8B90A0;">[<?php echo esc_html($r['type'] ?? ''); ?>]</span> <?php echo esc_html($r['label'] ?? ''); ?></td>
                        <td style="padding:6px 8px;text-align:right;"><?php echo $dc($fd); ?></td>
                        <td style="padding:6px 8px;text-align:right;"><?php echo $dc($pd); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- SOCIAL (merged from Social Listening — one page) ----------------->
        <?php $mark('momentum'); ?>
        <?php if (function_exists('lmeg_admin_social')) : ?>
        <div style="height:1px;background:rgba(255,255,255,.12);max-width:1040px;margin:26px 0 16px;"></div>
        <h2 style="font:800 20px/1 var(--lmegA-font,inherit);margin:0 0 4px;">Social · content &amp; sentiment</h2>
        <p style="color:#8B90A0;font-size:12px;margin:0 0 14px;max-width:1040px;">What you're posting, who's engaging, and how fans feel — from your connected accounts. Audience &amp; growth are up top.</p>
        <?php lmeg_admin_social(true, 'rest'); ?>
        <?php endif; ?>

        <!-- STALE-SNAPSHOT HINT (enriched sections need a fresh import) -------->
        <?php $mark('social_rest'); ?>
        <?php
        $has_enriched = !empty($meta['gender']) || !empty($meta['top_cities']) || !empty($releases) || !empty($playlists);
        if ($has_s4a && !$has_enriched) : ?>
        <div style="background:rgba(124,108,246,.10);border:1px solid rgba(124,108,246,.35);border-radius:12px;padding:12px 14px;margin-bottom:14px;max-width:1040px;color:#F4F5F7;font-size:13px;line-height:1.5;">
            Your latest snapshot predates <strong>demographics, playlists &amp; release breakdowns</strong>. <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-s4a')); ?>">Import a fresh Spotify&nbsp;for&nbsp;Artists export</a> to unlock those sections.
        </div>
        <?php endif; ?>

        <!-- FRESHNESS + LINKS ------------------------------------------------->
        <p style="<?php echo $muted; ?>font-size:12px;max-width:1040px;">
            <?php if ($has_s4a) : ?>Streaming data captured <?php echo esc_html($snap->captured_date); ?> · <?php endif; ?>
            <?php if (!$has_s4a) : ?><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-s4a')); ?>">Import Spotify for Artists</a> for streams &amp; per-song data · <?php endif; ?>
            <?php if (!$has_api) : ?><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-spotify')); ?>">Connect Spotify</a> for followers &amp; popularity · <?php endif; ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-s4a')); ?>">Manage import</a> ·
            <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-spotify')); ?>">Spotify settings</a>
        </p>
    </div>
    <?php
    if ($prof_on) {
        // Launch-matcher diagnostics: API releases seen, how many have dates, how many matched a daily song.
        $lr = ($has_api && !empty($ov['releases'])) ? (array) $ov['releases'] : [];
        $ld = count(array_filter($lr, function ($r) { return is_array($r) && !empty($r['date']) && strlen((string) $r['date']) >= 10; }));
        echo "\n<!-- lmeg_prof_launch api_releases=" . count($lr) . " dated=" . $ld . " matched=" . count($launches ?? []) . " daily_songs=" . count((array) ($az_meta['song_daily'] ?? [])) . " sample=" . esc_html(implode(' | ', array_slice(array_map(function ($r) { return (string) ($r['name'] ?? '') . '@' . substr((string) ($r['date'] ?? ''), 0, 10); }, $lr), 0, 6))) . " -->\n";
        $mark('end'); $out = []; $last = $prof['start'];
        foreach ($prof as $k => $ts) { if ($k === 'start') continue; $out[] = $k . '=' . number_format(($ts - $last) * 1000) . 'ms'; $last = $ts; }
        echo "\n<!-- lmeg_prof total=" . number_format(($prof['end'] - $prof['start']) * 1000) . "ms " . implode(' ', $out) . " -->\n";
    }
}
