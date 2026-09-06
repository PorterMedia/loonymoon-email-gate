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

    // Rising track — promote while hot.
    if (!empty($c['mover_up']) && ($c['mover_up']['pace'] ?? 0) >= 10) {
        $m = $c['mover_up'];
        $F[] = ['type' => 'opportunity', 'title' => '“' . $m['title'] . '” is gaining',
            'detail' => 'It ran ' . $p($m['pace']) . '% above its 28-day pace (' . $n($m['s7']) . ' streams in the last 7 days). Put promo behind it while it’s moving.'];
    }
    // Falling flagship — watch.
    if (!empty($c['mover_down']) && ($c['mover_down']['pace'] ?? 0) <= -20) {
        $m = $c['mover_down'];
        $F[] = ['type' => 'watch', 'title' => '“' . $m['title'] . '” is cooling',
            'detail' => 'Down ' . $p(abs($m['pace'])) . '% vs its 28-day pace. If it was a recent focus, the momentum is fading.'];
    }
    // Streams velocity — catalog-wide week-over-week momentum from the daily
    // series (distinct from per-song movers and month-over-month deltas).
    if (!empty($c['velocity']) && ($c['velocity']['prior7'] ?? 0) > 0) {
        $v = $c['velocity']; $wow = (float) $v['wow'];
        if ($wow <= -12) {
            $streak = ($v['weeks_down'] ?? 0) >= 3 ? ' — and each of the last ' . (int) $v['weeks_down'] . ' weeks came in below the one before' : '';
            $F[] = ['type' => 'watch', 'title' => 'Streams are cooling',
                'detail' => 'The last 7 days ran ' . $p(abs($wow)) . '% below the previous 7 (' . $n($v['last7']) . ' vs ' . $n($v['prior7']) . ' streams)' . $streak . '. A re-push, a fresh drop, or a playlist pitch could reverse the slide.'];
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
        $F[] = ['type' => 'insight', 'title' => 'Fans keep “' . $s['title'] . '” the most', 'detail' => $detail];
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
    $t = lmeg_si_tokens();
    $card = $t['card']; $lbl = $t['lbl'];

    // ---- gather from both sources (each optional) ----------------------------
    // Per-site isolation: this Fanloop site shows ONLY its own artist — no
    // cross-artist switcher, even if the DB holds other artists' snapshots.
    $sel = lmeg_artist();
    $artists = [$sel];
    $snap    = function_exists('lmeg_s4a_latest') ? lmeg_s4a_latest($sel) : null;
    $changes = ($snap && $snap->changes) ? (array) json_decode($snap->changes, true) : [];

    // Period-over-period: when the S4A export didn't carry its own change_pct
    // (e.g. the URL-pull pipeline doesn't), compute each KPI's change vs the
    // previous snapshot so the chips + "since last capture" note come alive.
    $prev = ($snap && function_exists('lmeg_s4a_prev')) ? lmeg_s4a_prev($sel, $snap->window, $snap->captured_date) : null;
    if ($prev) {
        foreach (['monthly_listeners', 'streams', 'mal', 'saves', 'playlist_adds', 'followers', 'super_listeners', 'new_active'] as $mk) {
            if (($changes[$mk] ?? null) === null || $changes[$mk] === '') {
                $pc = lmeg_si_pct_change($snap->$mk ?? null, $prev->$mk ?? null);
                if ($pc !== null) $changes[$mk] = round($pc, 1);
            }
        }
    }

    $ov = function_exists('lmeg_spotify_overview') ? lmeg_spotify_overview() : null;
    if (is_wp_error($ov)) $ov = null;

    // Social side — for the cross-platform profile. Each is null when unconfigured.
    $ig_stats = function_exists('lmeg_ig_account_stats') ? lmeg_ig_account_stats() : null;
    $fb_stats = function_exists('lmeg_fb_page_stats') ? lmeg_fb_page_stats() : null;
    $ig_demo  = function_exists('lmeg_social_ig_demographics') ? lmeg_social_ig_demographics() : null;

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
    $findings = lmeg_si_analyze([
        'geo'               => $az_geo,
        'saver'             => lmeg_si_top_saver($az_songs),
        'velocity'          => lmeg_si_stream_velocity((array) ($az_meta['daily']['streams'] ?? [])),
        'mover_up'          => $az_mv['biggest'] ?? null,
        'mover_down'        => $az_down,
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
    ?>
    <div class="wrap lmeg-admin">
        <h1>Fanloop — Insights</h1>

        <?php if (!$has_s4a && !$has_api) : ?>
            <div style="<?php echo $card; ?>max-width:820px;margin-top:12px;">
                <p style="margin:0 0 8px;">No Spotify data yet. Two quick connects light this page up:</p>
                <p style="margin:0;">
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=lmeg-spotify')); ?>">Connect Spotify (followers, popularity)</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lmeg-s4a')); ?>" style="margin-left:6px;">Import Spotify for Artists (streams, songs)</a>
                </p>
            </div>
        </div>
        <?php return; endif; ?>

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
            </div>
            <?php if ($has_api && !empty($ov['url'])) : ?>
                <a class="button" href="<?php echo esc_url($ov['url']); ?>" target="_blank" rel="noopener" style="flex:0 0 auto;">Open on Spotify ↗</a>
            <?php endif; ?>
        </div>

        <!-- ANALYSIS (Fanloop's own findings) -------------------------------->
        <?php if ($findings) :
            $ftok = ['opportunity' => ['#7C6CF6', 'Opportunity'], 'watch' => ['#F59E0B', 'Watch'], 'insight' => ['#E58BBD', 'Insight'], 'strength' => ['#34D399', 'Strength']];
        ?>
        <div style="<?php echo $card; ?>max-width:1040px;margin:12px 0 14px;">
            <div style="<?php echo $lbl; ?>margin-bottom:4px;">What the data says <span style="color:#8B90A0;font-weight:400;">· Fanloop analysis</span></div>
            <p style="color:#8B90A0;font-size:12px;margin:0 0 14px;">Auto-generated from your streaming + social data — the most actionable items first.</p>
            <div style="display:flex;flex-direction:column;gap:13px;">
                <?php foreach ($findings as $f) : $tk = $ftok[$f['type']] ?? ['#8B90A0', '']; ?>
                <div style="display:flex;gap:12px;align-items:flex-start;">
                    <span style="flex:0 0 auto;margin-top:1px;font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:<?php echo $tk[0]; ?>;background:<?php echo $tk[0]; ?>1f;border:1px solid <?php echo $tk[0]; ?>55;border-radius:20px;padding:3px 9px;min-width:82px;text-align:center;"><?php echo esc_html($tk[1]); ?></span>
                    <div style="flex:1 1 auto;min-width:0;">
                        <div style="color:#F4F5F7;font-size:14px;font-weight:600;margin-bottom:2px;"><?php echo esc_html($f['title']); ?></div>
                        <div style="color:#C9CCD6;font-size:13px;line-height:1.5;"><?php echo esc_html($f['detail']); ?></div>
                    </div>
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
            echo $kpi('Followers',         $followers,                              $changes['followers'] ?? null);
            if ($popularity !== null) echo $kpi('Popularity', $popularity, null, '/100');
            ?>
        </div>

        <?php if ($prev) : $days = max(1, (int) round((strtotime($snap->captured_date) - strtotime($prev->captured_date)) / 86400)); ?>
        <p style="color:#8B90A0;font-size:12px;margin:-4px 0 14px;max-width:1040px;">Change vs your previous capture — <?php echo esc_html($prev->captured_date); ?>, <?php echo (int) $days; ?> day<?php echo $days === 1 ? '' : 's'; ?> earlier.</p>
        <?php endif; ?>

        <!-- TRENDS ------------------------------------------------------------>
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
        </div>
        <?php endif; ?>

        <!-- THE SONGS (the point of the page) --------------------------------->
        <?php
        $songs = $has_s4a ? (array) json_decode((string) $snap->top_songs, true) : [];
        $songs = array_values(array_filter($songs, 'is_array'));
        if ($songs) :
            $max = 1;
            foreach ($songs as $s) { $max = max($max, (int) ($s['streams'] ?? 0)); }
            $meta_songs = ($has_s4a && $snap->meta) ? (array) json_decode((string) $snap->meta, true) : [];
            $movers = lmeg_si_song_movers($songs, (array) ($meta_songs['songs_7d'] ?? []));
            $reconcile = lmeg_si_reconcile_tracks($songs, ($has_api ? ($ov['top_tracks'] ?? []) : []));
        ?>
        <div style="<?php echo $card; ?>max-width:1040px;margin-bottom:14px;">
            <div style="<?php echo $lbl; ?>margin-bottom:<?php echo $reconcile['both'] ? '4' : '12'; ?>px;">Your songs · by streams <span style="color:#8B90A0;font-weight:400;">(<?php echo count($songs); ?>)</span></div>
            <?php if ($reconcile['both']) : ?><p style="color:#8B90A0;font-size:11px;margin:0 0 12px;">Streams from Spotify&nbsp;for&nbsp;Artists · <span style="color:#E58BBD;">◍</span> = Spotify public popularity (0–100), matched on <?php echo (int) $reconcile['both']; ?> track<?php echo $reconcile['both'] === 1 ? '' : 's'; ?>.</p><?php endif; ?>
            <?php if (!empty($movers['biggest'])) : $bm = $movers['biggest']; $bmpace = rtrim(rtrim(number_format($bm['pace'], 1), '0'), '.'); ?>
            <div style="background:rgba(52,211,153,.10);border:1px solid rgba(52,211,153,.35);border-radius:12px;padding:11px 14px;margin-bottom:14px;display:flex;gap:10px;align-items:center;">
                <span style="font-size:18px;flex:0 0 auto;" aria-hidden="true">🔥</span>
                <div style="font-size:13px;color:#F4F5F7;line-height:1.5;"><strong><?php echo esc_html($bm['title']); ?></strong> is heating up — <strong style="color:#34D399;"><?php echo number_format_i18n($bm['s7']); ?></strong> streams in the last 7 days, running <strong style="color:#34D399;"><?php echo esc_html($bmpace); ?>% above</strong> its 28-day pace.</div>
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
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:20px;text-align:right;color:#8B90A0;font-size:12px;font-variant-numeric:tabular-nums;flex:0 0 auto;"><?php echo $i + 1; ?></div>
                    <div style="flex:1 1 auto;min-width:0;">
                        <?php
                        $rp = $movers['by_title'][strtolower(trim($title))]['pace'] ?? null;
                        $rchip = '';
                        if ($rp !== null && abs($rp) >= 8) {
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
                </div>
                <?php endforeach; ?>
            </div>
        </div>
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
        <?php
        $rel_meta = ($has_s4a && $snap->meta) ? (array) json_decode((string) $snap->meta, true) : [];
        $releases = lmeg_si_releases_sorted($rel_meta['releases'] ?? []);
        // Cross-link to Fanloop release pages — exact match by Spotify album id
        // (from the release's stored links), else by normalized title.
        $fl_by_id = []; $fl_by_title = [];
        if ($releases && function_exists('lmeg_releases_all') && function_exists('lmeg_release_public_url')) {
            foreach ((array) lmeg_releases_all() as $fr) {
                $u = lmeg_release_public_url($fr);
                if (!$u) continue;
                $aid = lmeg_si_spotify_album_id((string) ($fr->links ?? ''));
                if ($aid !== '') $fl_by_id[$aid] = $u;
                $nt = strtolower(trim((string) ($fr->title ?? '')));
                if ($nt !== '' && !isset($fl_by_title[$nt])) $fl_by_title[$nt] = $u;
            }
        }
        if ($releases) : $maxR = 1; foreach ($releases as $r) { $maxR = max($maxR, $r['streams']); } ?>
        <div style="<?php echo $card; ?>max-width:1040px;margin-bottom:14px;">
            <div style="<?php echo $lbl; ?>margin-bottom:12px;">Releases · by streams <span style="color:#8B90A0;font-weight:400;">(<?php echo count($releases); ?>)</span></div>
            <div style="display:flex;flex-direction:column;gap:9px;max-height:420px;overflow:auto;">
                <?php foreach (array_slice($releases, 0, 20) as $i => $r) :
                    $st = (int) $r['streams']; $w = max(2, round($st / $maxR * 100));
                    $yr = ($r['date'] && strlen($r['date']) >= 4) ? substr($r['date'], 0, 4) : '';
                    $type = $r['type'] ? ucwords(strtolower(str_replace('_', ' ', $r['type']))) : '';
                    $sub = trim(implode(' · ', array_filter([$type, $yr])));
                    $flu = '';
                    $aid = lmeg_si_spotify_album_id($r['uri']);
                    if ($aid !== '' && isset($fl_by_id[$aid])) $flu = $fl_by_id[$aid];
                    elseif (isset($fl_by_title[strtolower(trim($r['name']))])) $flu = $fl_by_title[strtolower(trim($r['name']))]; ?>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:20px;text-align:right;color:#8B90A0;font-size:12px;font-variant-numeric:tabular-nums;flex:0 0 auto;"><?php echo $i + 1; ?></div>
                        <div style="flex:1 1 auto;min-width:0;">
                            <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:4px;">
                                <span style="color:#F4F5F7;font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($r['name']); ?><?php if ($sub) : ?> <span style="color:#8B90A0;font-size:11px;font-weight:400;"><?php echo esc_html($sub); ?></span><?php endif; ?></span>
                                <span style="display:flex;align-items:center;gap:8px;flex:0 0 auto;"><?php if ($flu) : ?><a href="<?php echo esc_url($flu); ?>" target="_blank" rel="noopener" title="Open this release's Fanloop page" style="font-size:11px;text-decoration:none;">↗</a><?php endif; ?><span style="color:#F4F5F7;font-size:13px;font-variant-numeric:tabular-nums;"><?php echo number_format_i18n($st); ?></span></span>
                            </div>
                            <div style="height:6px;border-radius:6px;background:rgba(255,255,255,.06);overflow:hidden;"><div style="height:100%;width:<?php echo $w; ?>%;background:linear-gradient(90deg,#D05FA2,#E58BBD);border-radius:6px;"></div></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- WHO'S LISTENING (gender · age · cities) -------------------------->
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
                <div style="<?php echo $lbl; ?>margin-bottom:10px;">Where they listen <span style="color:#8B90A0;font-weight:400;">· monthly listeners</span></div>
                <?php foreach ($country_rows as $i => $c) : ?>
                    <div style="padding:6px 0;border-bottom:1px solid rgba(255,255,255,.06);">
                        <div style="display:flex;align-items:center;gap:9px;font-size:13px;">
                            <span style="color:#8B90A0;width:14px;flex:0 0 auto;font-variant-numeric:tabular-nums;"><?php echo $i + 1; ?></span>
                            <span style="flex:0 0 auto;font-size:15px;line-height:1;"><?php echo esc_html($c['flag']); ?></span>
                            <span style="color:#F4F5F7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($c['name']); ?></span>
                            <span style="color:#F4F5F7;margin-left:auto;font-variant-numeric:tabular-nums;flex:0 0 auto;"><?php echo esc_html(number_format_i18n($c['num'])); ?></span>
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
                foreach (array_slice($playlists, 0, 10) as $p) :
                    if (!is_array($p)) continue;
                    $name = (string) ($p['title'] ?? $p['name'] ?? '');
                    if ($name === '') continue;
                    $val = isset($p['streams']) ? number_format_i18n((int) $p['streams']) : '';
                    $tl  = $pl_type[(string) ($p['type'] ?? '')] ?? '';
                    $fol = (isset($p['followers']) && $p['followers'] !== null && $p['followers'] !== '') ? (int) $p['followers'] : null;
                    $sub = trim(implode(' · ', array_filter([(string) ($p['author'] ?? ''), $tl, $fol !== null ? number_format_i18n($fol) . ' followers' : ''])));
                ?>
                    <div style="padding:6px 0;border-bottom:1px solid rgba(255,255,255,.06);">
                        <div style="display:flex;justify-content:space-between;gap:10px;font-size:13px;">
                            <span style="color:#F4F5F7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($name); ?></span>
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
        <?php
        $impact = function_exists('lmeg_impact_rows') ? lmeg_impact_rows(7) : [];
        if ($impact) : ?>
        <div style="<?php echo $card; ?>max-width:1040px;margin-bottom:14px;">
            <div style="<?php echo $lbl; ?>margin-bottom:4px;">Momentum · what moved the needle</div>
            <p style="<?php echo $t['muted']; ?>font-size:12px;margin:0 0 10px;">Follower &amp; popularity change in the 7 days after each release and broadcast. Directional, not causal.</p>
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
        <?php if (function_exists('lmeg_admin_social')) : ?>
        <div style="height:1px;background:rgba(255,255,255,.12);max-width:1040px;margin:26px 0 16px;"></div>
        <h2 style="font:800 20px/1 var(--lmegA-font,inherit);margin:0 0 4px;">Social</h2>
        <p style="color:#8B90A0;font-size:12px;margin:0 0 14px;max-width:1040px;">Your social presence — audience, growth, content, and how fans feel — from your connected accounts.</p>
        <?php lmeg_admin_social(true); ?>
        <?php endif; ?>

        <!-- STALE-SNAPSHOT HINT (enriched sections need a fresh import) -------->
        <?php
        $has_enriched = !empty($meta['gender']) || !empty($meta['top_cities']) || !empty($releases) || !empty($playlists);
        if ($has_s4a && !$has_enriched) : ?>
        <div style="background:rgba(124,108,246,.10);border:1px solid rgba(124,108,246,.35);border-radius:12px;padding:12px 14px;margin-bottom:14px;max-width:1040px;color:#F4F5F7;font-size:13px;line-height:1.5;">
            Your latest snapshot predates <strong>demographics, playlists &amp; release breakdowns</strong>. <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-s4a')); ?>">Import a fresh Spotify&nbsp;for&nbsp;Artists export</a> to unlock those sections.
        </div>
        <?php endif; ?>

        <!-- FRESHNESS + LINKS ------------------------------------------------->
        <p style="<?php echo $t['muted']; ?>font-size:12px;max-width:1040px;">
            <?php if ($has_s4a) : ?>Streaming data captured <?php echo esc_html($snap->captured_date); ?> · <?php endif; ?>
            <?php if (!$has_s4a) : ?><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-s4a')); ?>">Import Spotify for Artists</a> for streams &amp; per-song data · <?php endif; ?>
            <?php if (!$has_api) : ?><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-spotify')); ?>">Connect Spotify</a> for followers &amp; popularity · <?php endif; ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-s4a')); ?>">Manage import</a> ·
            <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-spotify')); ?>">Spotify settings</a>
        </p>
    </div>
    <?php
}
