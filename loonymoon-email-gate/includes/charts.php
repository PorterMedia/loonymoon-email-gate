<?php
/**
 * Fanloop — shared data-viz helpers.
 *
 * One premium line-chart renderer used by every admin page (Social, Spotify,
 * Overview, dashboard widget) so all charts look identical: a soft gradient
 * area fill, a crisp non-scaling stroke, a faint baseline, and a glowing
 * end-point marking the current value.
 *
 * Everything is a SELF-CONTAINED inline SVG (no external CSS). This is
 * deliberate — loonymoonchild.com runs Autoptimize, which serves a stale,
 * un-versioned admin.css, so anything that depends on a new CSS rule may not
 * load. Inline SVG attributes always render.
 */

if (!defined('ABSPATH')) exit;

/**
 * Render a filled line/area chart from a series of numbers.
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
        'h'            => 72,    // rendered height in px
        'pad'          => 7,     // vertical breathing room so line/dot don't clip
        'uid'          => '',
        'fill'         => true,
        'dot'          => true,
        'baseline'     => true,
        'area_opacity' => 0.26,
    ]);
    if (count($vals) < 2) return '';

    $w = (float) $a['w']; $h = (float) $a['h']; $pad = (float) $a['pad'];
    $min = min($vals); $max = max($vals); $range = max(0.0001, $max - $min);
    $n = count($vals); $step = $w / ($n - 1);

    $pts = []; $firstx = 0.0; $lastx = 0.0; $lasty = 0.0;
    foreach ($vals as $i => $v) {
        $x = round($i * $step, 2);
        $y = round($h - $pad - (($v - $min) / $range) * ($h - 2 * $pad), 2);
        $pts[] = $x . ',' . $y;
        if ($i === 0) $firstx = $x;
        $lastx = $x; $lasty = $y;
    }
    $line = implode(' ', $pts);
    $col  = $a['color'];
    $uid  = $a['uid'] !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $a['uid'])
                             : 'c' . substr(md5($line . $col), 0, 8);
    $gid  = 'lmegc-g-' . $uid;

    // preserveAspectRatio="none" stretches the plot to the card's full width;
    // vector-effect keeps the stroke a constant 2px regardless of that stretch.
    $svg  = '<svg class="lmegc-line" viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="' . $h
          . '" preserveAspectRatio="none" style="display:block;overflow:visible;margin-top:10px;" aria-hidden="true">';
    $svg .= '<defs><linearGradient id="' . esc_attr($gid) . '" x1="0" y1="0" x2="0" y2="1">'
          . '<stop offset="0" stop-color="' . esc_attr($col) . '" stop-opacity="' . esc_attr($a['area_opacity']) . '"/>'
          . '<stop offset="1" stop-color="' . esc_attr($col) . '" stop-opacity="0"/></linearGradient></defs>';

    if ($a['fill']) {
        $area = 'M' . $firstx . ',' . $h . ' L' . str_replace(' ', ' L', $line) . ' L' . $lastx . ',' . $h . ' Z';
        $svg .= '<path d="' . esc_attr($area) . '" fill="url(#' . esc_attr($gid) . ')" stroke="none"/>';
    }
    if ($a['baseline']) {
        $svg .= '<line x1="0" y1="' . ($h - 0.75) . '" x2="' . $w . '" y2="' . ($h - 0.75)
              . '" stroke="rgba(255,255,255,0.10)" stroke-width="1" vector-effect="non-scaling-stroke"/>';
    }
    $svg .= '<polyline fill="none" stroke="' . esc_attr($col) . '" stroke-width="2" stroke-linecap="round" '
          . 'stroke-linejoin="round" vector-effect="non-scaling-stroke" points="' . esc_attr($line) . '"/>';
    if ($a['dot']) {
        $svg .= '<circle cx="' . $lastx . '" cy="' . $lasty . '" r="6" fill="' . esc_attr($col)
              . '" opacity="0.20" vector-effect="non-scaling-stroke"/>';
        $svg .= '<circle cx="' . $lastx . '" cy="' . $lasty . '" r="3" fill="' . esc_attr($col)
              . '" stroke="#12141F" stroke-width="1.5" vector-effect="non-scaling-stroke"/>';
    }
    $svg .= '</svg>';
    return $svg;
}

/**
 * A small coloured delta chip ("▲ +128 / 30d"). Dark-theme safe.
 *
 * @param int      $delta   Change in the value.
 * @param int|null $per_day Optional per-day rate to append.
 * @param int|null $days    Optional window length in days.
 * @return string HTML, or '' when there's nothing to show.
 */
function lmeg_chart_delta_chip($delta, $per_day = null, $days = null) {
    $delta = (int) $delta;
    if ($delta === 0 && !$per_day) return '';
    $up  = $delta >= 0;
    $col = $up ? '#34D399' : '#F87171';
    $bg  = $up ? 'rgba(52,211,153,0.12)' : 'rgba(248,113,113,0.12)';
    $txt = ($up ? '▲ +' : '▼ ') . number_format_i18n(abs($delta));
    if ($days) $txt .= ' / ' . (int) $days . 'd';
    return '<span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600;'
         . 'margin-top:8px;padding:2px 9px;border-radius:999px;color:' . $col . ';background:' . $bg . ';">'
         . $txt . '</span>';
}
