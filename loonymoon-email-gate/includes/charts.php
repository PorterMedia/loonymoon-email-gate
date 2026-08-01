<?php
/**
 * Fanloop — shared data-viz + icon helpers.
 *
 * One premium line-chart renderer used by every admin page (Social, Spotify,
 * Overview, dashboard widget) so all charts look identical: a smooth curve,
 * a soft gradient area fill, a crisp non-scaling stroke, and a glowing dot
 * marking the current value. Plus a small Lucide-style icon set (with real
 * brand glyphs) so the UI uses proper vector icons instead of emoji.
 *
 * Everything is a SELF-CONTAINED inline SVG (no external CSS). This is
 * deliberate — loonymoonchild.com runs Autoptimize, which serves a stale,
 * un-versioned admin.css, so anything that depends on a new CSS rule may not
 * load. Inline SVG attributes always render.
 */

if (!defined('ABSPATH')) exit;

/* ===========================================================================
 * Line / area chart
 * ========================================================================= */

/**
 * Build a smooth SVG path (Catmull-Rom → cubic Bézier) through the points.
 *
 * @param array $pts List of [x, y] pairs.
 * @return string An SVG path "d" value ('' if <2 points).
 */
function lmeg_chart_smooth_path($pts) {
    $n = count($pts);
    if ($n < 2) return '';
    if ($n === 2) return 'M' . $pts[0][0] . ',' . $pts[0][1] . ' L' . $pts[1][0] . ',' . $pts[1][1];
    $d = 'M' . $pts[0][0] . ',' . $pts[0][1];
    for ($i = 0; $i < $n - 1; $i++) {
        $p0 = $pts[$i - 1] ?? $pts[$i];
        $p1 = $pts[$i];
        $p2 = $pts[$i + 1];
        $p3 = $pts[$i + 2] ?? $p2;
        $c1x = $p1[0] + ($p2[0] - $p0[0]) / 6;
        $c1y = $p1[1] + ($p2[1] - $p0[1]) / 6;
        $c2x = $p2[0] - ($p3[0] - $p1[0]) / 6;
        $c2y = $p2[1] - ($p3[1] - $p1[1]) / 6;
        $d .= ' C' . round($c1x, 2) . ',' . round($c1y, 2)
            . ' ' . round($c2x, 2) . ',' . round($c2y, 2)
            . ' ' . round($p2[0], 2) . ',' . round($p2[1], 2);
    }
    return $d;
}

/**
 * Render a smooth filled line/area chart from a series of numbers.
 *
 * @param array $vals Ordered numeric series (oldest → newest). Needs ≥2 points.
 * @param array $args color, w, h, pad, uid, fill, dot, baseline, area_opacity
 * @return string SVG markup, or '' when there isn't enough data.
 */
function lmeg_chart_line($vals, $args = []) {
    $vals = array_values(array_map('floatval', (array) $vals));
    $a = wp_parse_args($args, [
        'color'        => '#D05FA2',
        'w'            => 480,   // viewBox width (aspect reference)
        'h'            => 76,    // rendered height in px
        'pad'          => 9,     // vertical breathing room so curve/dot don't clip
        'uid'          => '',
        'fill'         => true,
        'dot'          => true,
        'baseline'     => false, // minimal by default — the fill grounds the chart
        'area_opacity' => 0.30,
    ]);
    if (count($vals) < 2) return '';

    $w = (float) $a['w']; $h = (float) $a['h']; $pad = (float) $a['pad'];
    $min = min($vals); $max = max($vals); $range = max(0.0001, $max - $min);
    $n = count($vals); $step = $w / ($n - 1);

    $pts = []; $firstx = 0.0; $lastx = 0.0; $lasty = 0.0;
    foreach ($vals as $i => $v) {
        $x = round($i * $step, 2);
        $y = round($h - $pad - (($v - $min) / $range) * ($h - 2 * $pad), 2);
        $pts[] = [$x, $y];
        if ($i === 0) $firstx = $x;
        $lastx = $x; $lasty = $y;
    }
    $path = lmeg_chart_smooth_path($pts);
    $col  = $a['color'];
    $uid  = $a['uid'] !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $a['uid'])
                             : 'c' . substr(md5($path . $col), 0, 8);
    $gid  = 'lmegc-g-' . $uid;

    // preserveAspectRatio="none" stretches the plot to the card's full width;
    // vector-effect keeps the stroke a constant width regardless of that stretch.
    $svg  = '<svg class="lmegc-line" viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="' . $h
          . '" preserveAspectRatio="none" style="display:block;overflow:visible;margin-top:12px;" aria-hidden="true">';
    $svg .= '<defs><linearGradient id="' . esc_attr($gid) . '" x1="0" y1="0" x2="0" y2="1">'
          . '<stop offset="0" stop-color="' . esc_attr($col) . '" stop-opacity="' . esc_attr($a['area_opacity']) . '"/>'
          . '<stop offset="0.55" stop-color="' . esc_attr($col) . '" stop-opacity="' . esc_attr(round($a['area_opacity'] * 0.35, 3)) . '"/>'
          . '<stop offset="1" stop-color="' . esc_attr($col) . '" stop-opacity="0"/></linearGradient></defs>';

    if ($a['fill']) {
        $area = $path . ' L' . $lastx . ',' . $h . ' L' . $firstx . ',' . $h . ' Z';
        $svg .= '<path d="' . esc_attr($area) . '" fill="url(#' . esc_attr($gid) . ')" stroke="none"/>';
    }
    if ($a['baseline']) {
        $svg .= '<line x1="0" y1="' . ($h - 0.75) . '" x2="' . $w . '" y2="' . ($h - 0.75)
              . '" stroke="rgba(255,255,255,0.08)" stroke-width="1" vector-effect="non-scaling-stroke"/>';
    }
    $svg .= '<path d="' . esc_attr($path) . '" fill="none" stroke="' . esc_attr($col) . '" stroke-width="2.25" '
          . 'stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>';
    if ($a['dot']) {
        $svg .= '<circle cx="' . $lastx . '" cy="' . $lasty . '" r="7" fill="' . esc_attr($col)
              . '" opacity="0.18" vector-effect="non-scaling-stroke"/>';
        $svg .= '<circle cx="' . $lastx . '" cy="' . $lasty . '" r="3.2" fill="' . esc_attr($col)
              . '" stroke="#12141F" stroke-width="1.6" vector-effect="non-scaling-stroke"/>';
    }
    $svg .= '</svg>';
    return $svg;
}

/**
 * A small coloured delta chip ("▲ 128 / 30d"). Dark-theme safe.
 */
function lmeg_chart_delta_chip($delta, $per_day = null, $days = null) {
    $delta = (int) $delta;
    if ($delta === 0 && !$per_day) return '';
    $up  = $delta >= 0;
    $col = $up ? '#34D399' : '#F87171';
    $bg  = $up ? 'rgba(52,211,153,0.12)' : 'rgba(248,113,113,0.12)';
    $ico = $up ? '<path d="M6 9l4-4 4 4"/><path d="M10 5v10" transform="translate(0 -0.5)"/>'
               : '<path d="M6 11l4 4 4-4"/><path d="M10 5v10"/>';
    $chip = '<span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600;'
          . 'margin-top:8px;padding:2px 9px 2px 6px;border-radius:999px;color:' . $col . ';background:' . $bg . ';">'
          . '<svg viewBox="0 0 20 20" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" '
          . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:block;">' . $ico . '</svg>'
          . number_format_i18n(abs($delta));
    if ($days) $chip .= ' <span style="opacity:.75;font-weight:500;">/ ' . (int) $days . 'd</span>';
    $chip .= '</span>';
    return $chip;
}

/* ===========================================================================
 * Icon set — Lucide-style strokes + real brand glyphs. currentColor driven.
 * ========================================================================= */

/**
 * Inline SVG icon. Uses currentColor so the wrapping element's color drives it.
 *
 * @param string $name  instagram|spotify|users|at-sign|heart|message|trophy|
 *                      eye|trending-up|music|calendar|sparkle|volume
 * @param array  $args  size (px), sw (stroke width)
 */
function lmeg_icon($name, $args = []) {
    $a  = wp_parse_args($args, ['size' => 18, 'sw' => 1.9]);
    $s  = (float) $a['size'];
    $sw = (float) $a['sw'];
    $stroke = function ($inner) use ($s, $sw) {
        return '<svg viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" '
             . 'stroke-width="' . $sw . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:block;">'
             . $inner . '</svg>';
    };
    switch ($name) {
        case 'spotify': // filled brand glyph
            return '<svg viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="currentColor" aria-hidden="true" style="display:block;">'
                . '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm4.59 14.44a.62.62 0 0 1-.86.21c-2.35-1.44-5.31-1.76-8.79-.96a.62.62 0 1 1-.28-1.21c3.81-.87 7.08-.5 9.72 1.11.29.18.38.57.21.85zm1.22-2.72a.78.78 0 0 1-1.07.26c-2.69-1.66-6.79-2.13-9.98-1.17a.78.78 0 1 1-.45-1.49c3.64-1.1 8.16-.57 11.25 1.32.36.23.48.71.25 1.08zm.11-2.84C14.8 8.86 9.4 8.68 6.3 9.62a.94.94 0 1 1-.54-1.8c3.56-1.08 9.53-.87 13.32 1.38a.94.94 0 1 1-.97 1.61z"/></svg>';
        case 'instagram':
            return $stroke('<rect x="2.5" y="2.5" width="19" height="19" rx="5.5"/><circle cx="12" cy="12" r="4.1"/><circle cx="17.6" cy="6.4" r="1.15" fill="currentColor" stroke="none"/>');
        case 'users':
            return $stroke('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>');
        case 'at-sign':
            return $stroke('<circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"/>');
        case 'heart':
            return $stroke('<path d="M19 14.13c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.63c0 2.29 1.51 4.04 3 5.5l7 6.87z"/>');
        case 'message':
            return $stroke('<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>');
        case 'trophy':
            return $stroke('<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>');
        case 'eye':
            return $stroke('<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>');
        case 'trending-up':
            return $stroke('<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>');
        case 'music':
            return $stroke('<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>');
        case 'sparkle':
            return $stroke('<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3z"/>');
        case 'volume':
            return $stroke('<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a9 9 0 0 1 0 14"/>');
        default:
            return $stroke('<circle cx="12" cy="12" r="9"/>');
    }
}

/**
 * Icon inside a soft tinted rounded tile — the modern stat-card badge.
 *
 * @param string $name  Icon name (see lmeg_icon).
 * @param string $hex   6-digit hex accent (icon colour; tile = same at ~13%).
 * @param int    $size  Tile size in px.
 */
function lmeg_icon_badge($name, $hex = '#D05FA2', $size = 30) {
    $tint = (strlen($hex) === 7) ? $hex . '22' : $hex;
    return '<span style="display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;'
        . 'width:' . (int) $size . 'px;height:' . (int) $size . 'px;border-radius:9px;'
        . 'background:' . esc_attr($tint) . ';color:' . esc_attr($hex) . ';">'
        . lmeg_icon($name, ['size' => round($size * 0.58)]) . '</span>';
}

/**
 * Card header row: tinted icon badge + label. Consistent across the dashboard.
 */
function lmeg_card_head($name, $hex, $label) {
    return '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">'
        . lmeg_icon_badge($name, $hex)
        . '<span style="font-weight:600;font-size:14px;">' . esc_html($label) . '</span></div>';
}
