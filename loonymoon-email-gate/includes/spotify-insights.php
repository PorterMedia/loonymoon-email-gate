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

function lmeg_admin_spotify_insights() {
    if (!current_user_can('manage_options')) return;
    $t = lmeg_si_tokens();
    $card = $t['card']; $lbl = $t['lbl'];

    // ---- gather from both sources (each optional) ----------------------------
    $artists = function_exists('lmeg_s4a_artists') ? lmeg_s4a_artists() : [];
    $sel = isset($_GET['artist']) ? sanitize_text_field(wp_unslash($_GET['artist']))
        : (in_array(lmeg_artist(), $artists, true) ? lmeg_artist() : ($artists[0] ?? lmeg_artist()));
    $snap    = function_exists('lmeg_s4a_latest') ? lmeg_s4a_latest($sel) : null;
    $changes = ($snap && $snap->changes) ? (array) json_decode($snap->changes, true) : [];

    $ov = function_exists('lmeg_spotify_overview') ? lmeg_spotify_overview() : null;
    if (is_wp_error($ov)) $ov = null;

    $has_s4a = (bool) $snap;
    $has_api = is_array($ov);

    $followers  = $has_api && !empty($ov['followers']) ? (int) $ov['followers']
                : ($snap && $snap->followers !== null ? (int) $snap->followers : null);
    $popularity = $has_api ? (int) ($ov['popularity'] ?? 0) : null;
    ?>
    <div class="wrap lmeg-admin">
        <h1>Fanloop — Spotify Insights</h1>

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

        <!-- TRENDS ------------------------------------------------------------>
        <?php
        $streams_ser = ($has_s4a && function_exists('lmeg_s4a_series')) ? lmeg_s4a_series('streams', $sel, $snap->window) : [];
        $hist = function_exists('lmeg_spotify_history') ? array_reverse(lmeg_spotify_history()) : []; // oldest→newest
        $has_stream_chart = count($streams_ser) >= 2 && function_exists('lmeg_chart_line');
        $has_foll_chart   = count($hist) >= 2 && function_exists('lmeg_chart_line');
        if ($has_stream_chart || $has_foll_chart) : ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;max-width:1040px;margin-bottom:14px;">
            <?php if ($has_stream_chart) : ?>
                <div style="<?php echo $card; ?>">
                    <div style="<?php echo $lbl; ?>margin-bottom:8px;">Streams over time</div>
                    <?php echo lmeg_chart_line(array_map(function ($r) { return (int) $r->v; }, $streams_ser), [
                        'color' => '#1DB954', 'uid' => 'si-streams', 'h' => 70, 'suffix' => ' streams',
                        'labels' => array_map(function ($r) { return date_i18n('M j', strtotime($r->captured_date)); }, $streams_ser),
                    ]); ?>
                </div>
            <?php endif; ?>
            <?php if ($has_foll_chart) : ?>
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
        ?>
        <div style="<?php echo $card; ?>max-width:1040px;margin-bottom:14px;">
            <div style="<?php echo $lbl; ?>margin-bottom:12px;">Your songs · by streams <span style="color:#8B90A0;font-weight:400;">(<?php echo count($songs); ?>)</span></div>
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
                        <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:4px;">
                            <span style="color:#F4F5F7;font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($title); ?></span>
                            <span style="color:#F4F5F7;font-size:13px;font-variant-numeric:tabular-nums;flex:0 0 auto;"><?php echo number_format_i18n($st); ?></span>
                        </div>
                        <div style="height:6px;border-radius:6px;background:rgba(255,255,255,.06);overflow:hidden;">
                            <div style="height:100%;width:<?php echo $w; ?>%;background:linear-gradient(90deg,#7C6CF6,#D05FA2);border-radius:6px;"></div>
                        </div>
                        <?php if ($li !== null || $sv !== null) : ?>
                        <div style="margin-top:3px;font-size:11px;color:#8B90A0;">
                            <?php if ($li !== null) echo esc_html(number_format_i18n($li)) . ' listeners'; ?><?php if ($li !== null && $sv !== null) echo ' · '; ?><?php if ($sv !== null) echo esc_html(number_format_i18n($sv)) . ' saves'; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- SECONDARY GRID: markets, playlists, top tracks (popularity) -------->
        <?php
        $markets   = $has_s4a ? (array) json_decode((string) $snap->top_countries, true) : [];
        $playlists = $has_s4a ? (array) json_decode((string) $snap->top_playlists, true) : [];
        $toptracks = $has_api ? (array) ($ov['top_tracks'] ?? []) : [];
        if ($markets || $playlists || $toptracks) : ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;max-width:1040px;margin-bottom:14px;">
            <?php if ($markets) : ?>
            <div style="<?php echo $card; ?>">
                <div style="<?php echo $lbl; ?>margin-bottom:10px;">Where they listen</div>
                <?php foreach (array_slice($markets, 0, 8) as $i => $m) :
                    $name = is_array($m) ? (string) ($m['name'] ?? $m['x'] ?? '') : (string) $m; ?>
                    <div style="display:flex;gap:10px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:13px;"><span style="color:#8B90A0;width:16px;flex:0 0 auto;"><?php echo $i + 1; ?></span><span style="color:#F4F5F7;"><?php echo esc_html($name); ?></span></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($playlists) : ?>
            <div style="<?php echo $card; ?>">
                <div style="<?php echo $lbl; ?>margin-bottom:10px;">Top playlists</div>
                <?php foreach (array_slice($playlists, 0, 8) as $p) :
                    if (!is_array($p)) continue;
                    $name = (string) ($p['name'] ?? ''); $val = isset($p['streams']) ? number_format_i18n((int) $p['streams']) : ''; ?>
                    <div style="display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:13px;"><span style="color:#F4F5F7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($name); ?></span><span style="color:#8B90A0;font-variant-numeric:tabular-nums;"><?php echo esc_html($val); ?></span></div>
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
