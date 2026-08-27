<?php
if (!defined('ABSPATH')) exit;

/* ============================================================================
 * Fanloop Store — flat inline-SVG icon set
 * ----------------------------------------------------------------------------
 * One cohesive stroke-based icon system (24×24, currentColor, rounded caps) so
 * the store's UI reads flat and modern instead of relying on OS emoji. Use:
 *
 *   echo lmeg_store_icon('cart');                       // 16px, inherits text color
 *   echo lmeg_store_icon('search', 18);                 // 18px
 *   echo lmeg_store_icon('star', 14, ['fill' => true]); // solid variant
 *   echo lmeg_store_icon('gift', 16, ['stroke' => 2, 'class' => 'foo', 'style' => '…']);
 *
 * Meaningful glyphs (language flags, 🥇🥈🥉 medals) are deliberately left as-is.
 * ========================================================================== */

function lmeg_store_icon_paths() {
    // name => [inner SVG markup, is_fill_icon]
    return [
        'arrow-left'    => ['<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>', false],
        'arrow-right'   => ['<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>', false],
        'arrow-up'      => ['<line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/>', false],
        'arrow-down'    => ['<line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/>', false],
        'chevron-left'  => ['<polyline points="15 18 9 12 15 6"/>', false],
        'chevron-right' => ['<polyline points="9 18 15 12 9 6"/>', false],
        'chevron-down'  => ['<polyline points="6 9 12 15 18 9"/>', false],
        'help'          => ['<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>', false],
        'users'         => ['<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>', false],
        'x'             => ['<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>', false],
        'check'         => ['<polyline points="20 6 9 17 4 12"/>', false],
        'check-circle'  => ['<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>', false],
        'alert'         => ['<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>', false],
        'ban'           => ['<circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>', false],
        'search'        => ['<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>', false],
        'maximize'      => ['<polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/>', false],
        'ruler'         => ['<path d="M21.3 15.3a2.4 2.4 0 0 1 0 3.4l-2.6 2.6a2.4 2.4 0 0 1-3.4 0L2.7 8.7a2.41 2.41 0 0 1 0-3.4l2.6-2.6a2.4 2.4 0 0 1 3.4 0Z"/><path d="m14.5 12.5 2-2"/><path d="m11.5 9.5 2-2"/><path d="m8.5 6.5 2-2"/><path d="m17.5 15.5 2-2"/>', false],
        'box'           => ['<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>', false],
        'truck'         => ['<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>', false],
        'bell'          => ['<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>', false],
        'tag'           => ['<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>', false],
        'link'          => ['<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>', false],
        'flame'         => ['<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.07-2.14-.22-4.05 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.15.43-2.29 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>', false],
        'calendar'      => ['<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>', false],
        'bag'           => ['<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>', false],
        'cart'          => ['<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>', false],
        'gift'          => ['<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>', false],
        'sparkles'      => ['<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>', false],
        'star'          => ['<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>', false],
        'trophy'        => ['<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>', false],
        'edit'          => ['<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>', false],
        'lock'          => ['<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>', false],
        'printer'       => ['<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>', false],
        'download'      => ['<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>', false],
        'undo'          => ['<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>', false],
        'key'           => ['<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>', false],
        'mail'          => ['<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>', false],
        'headphones'    => ['<path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/>', false],
        'clock'         => ['<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>', false],
        'disc'          => ['<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/>', false],
        'heart'         => ['<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 1 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>', false],
        'flask'         => ['<path d="M9 3h6"/><path d="M10 3v6.5L4.9 18a2 2 0 0 0 1.7 3h10.8a2 2 0 0 0 1.7-3L14 9.5V3"/><path d="M7.5 15h9"/>', false],
        'clip'          => ['<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>', false],
        'plus'          => ['<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>', false],
    ];
}

/**
 * Return an inline SVG icon string. Unknown names return '' (never emoji).
 *
 * @param string $name  icon key from lmeg_store_icon_paths()
 * @param int    $size  px width/height (default 16)
 * @param array  $opts  fill(bool) stroke(float) class(string) style(string) title(string)
 */
function lmeg_store_icon($name, $size = 16, $opts = []) {
    static $lib = null;
    if ($lib === null) $lib = lmeg_store_icon_paths();
    if (!isset($lib[$name])) return '';
    $inner  = $lib[$name][0];
    $fill   = !empty($opts['fill']);
    $stroke = isset($opts['stroke']) ? (float) $opts['stroke'] : 1.75;
    $cls    = 'lmeg-ico' . (!empty($opts['class']) ? ' ' . $opts['class'] : '');
    $style  = 'vertical-align:-0.14em;flex:0 0 auto' . (!empty($opts['style']) ? ';' . $opts['style'] : '');
    $paint  = $fill ? 'fill="currentColor" stroke="none"' : 'fill="none" stroke="currentColor"';
    $sw     = $fill ? '' : ' stroke-width="' . $stroke . '" stroke-linecap="round" stroke-linejoin="round"';
    $title  = !empty($opts['title']) ? '<title>' . htmlspecialchars($opts['title'], ENT_QUOTES) . '</title>' : '';
    $sz     = (int) $size;
    return '<svg class="' . esc_attr($cls) . '" xmlns="http://www.w3.org/2000/svg" width="' . $sz . '" height="' . $sz
        . '" viewBox="0 0 24 24" ' . $paint . $sw . ' style="' . esc_attr($style) . '" aria-hidden="true" focusable="false" role="img">'
        . $title . $inner . '</svg>';
}
