<?php
if (!defined('ABSPATH')) exit;

/* ============================================================================
 * Fanloop Store — branded HTML email template
 * ----------------------------------------------------------------------------
 * A small, email-client-safe kit (table layout + inline styles, no flas/grid,
 * no <style> blocks) that every store email flows through: buyer receipts,
 * the artist's sale notifications, and the "find my purchases" magic link.
 * Honors the artist's logo (Settings → Branding) and falls back to their name.
 * ========================================================================== */

/** Fan-facing brand name. */
function lmeg_email_artist() {
    if (function_exists('lmeg_artist')) { $a = lmeg_artist(); if ($a) return $a; }
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    return $s['community_name'] ?? ($s['artist_name'] ?? get_bloginfo('name'));
}

/** Wrap inner HTML in the full branded shell and return the complete document. */
function lmeg_email_shell($inner, $preheader = '') {
    $artist = lmeg_email_artist();
    $s      = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $home   = home_url('/');
    $domain = preg_replace('~^https?://~', '', rtrim($home, '/'));

    // Logo (if set) or the artist name, centered at the top of the white card.
    $logo = trim((string) ($s['logo_url'] ?? ''));
    if ($logo && filter_var($logo, FILTER_VALIDATE_URL)) {
        $w   = max(60, min(220, (int) ($s['logo_max_width'] ?? 160)));
        $head = '<tr><td align="center" style="padding:28px 30px 2px"><img src="' . esc_url($logo) . '" alt="' . esc_attr($artist) . '" width="' . $w . '" style="max-width:' . $w . 'px;height:auto;display:block;border:0"></td></tr>';
    } else {
        $head = '<tr><td align="center" style="padding:28px 30px 2px"><span style="font-weight:800;font-size:20px;letter-spacing:-.01em;color:#1a1622">' . esc_html($artist) . '</span></td></tr>';
    }

    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light"></head>'
        . '<body style="margin:0;padding:0;background:#f4f2f7;-webkit-font-smoothing:antialiased">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f4f2f7">' . esc_html($preheader) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f2f7"><tr><td align="center" style="padding:26px 12px">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 12px 44px rgba(30,20,60,.10);font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif">'
        . '<tr><td style="height:6px;line-height:6px;font-size:6px;background:#E15FA8;background:linear-gradient(90deg,#E15FA8,#8A6CF6)">&nbsp;</td></tr>'
        . $head
        . '<tr><td style="padding:20px 34px 6px">' . $inner . '</td></tr>'
        . '<tr><td style="padding:18px 34px 30px"><div style="border-top:1px solid #efedf3;padding-top:16px;color:#9a94a8;font-size:12px;line-height:1.7">'
        . esc_html($artist) . ' · <a href="' . esc_url($home) . '" style="color:#b0559a;text-decoration:none">' . esc_html($domain) . '</a>'
        . '</div></td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Send an HTML email through the shell. */
function lmeg_email_send($to, $subject, $inner, $preheader = '') {
    if (!$to) return false;
    $html = lmeg_email_shell($inner, $preheader);
    $ct = function () { return 'text/html'; };
    add_filter('wp_mail_content_type', $ct);
    $ok = wp_mail($to, $subject, $html);
    remove_filter('wp_mail_content_type', $ct);
    return $ok;
}

/* ---- content blocks -------------------------------------------------------- */

function lmeg_email_h($text) {
    return '<h1 style="margin:12px 0 8px;font-size:22px;line-height:1.25;font-weight:800;letter-spacing:-.02em;color:#1a1622">' . esc_html($text) . '</h1>';
}

/** Paragraph — $html may contain simple inline markup (e.g. <strong>). */
function lmeg_email_p($html) {
    return '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#514b5e">' . $html . '</p>';
}

/** Bulletproof-ish gradient button. */
function lmeg_email_button($label, $url) {
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:2px 0 6px"><tr>'
        . '<td align="center" style="border-radius:11px;background:#E15FA8;background:linear-gradient(118deg,#E15FA8,#8A6CF6)">'
        . '<a href="' . esc_url($url) . '" style="display:inline-block;padding:13px 26px;color:#0B0C12;font-weight:800;font-size:15px;line-height:1;text-decoration:none;border-radius:11px">' . esc_html($label) . '</a>'
        . '</td></tr></table>';
}

/** A small uppercase section label. */
function lmeg_email_label($text) {
    return '<div style="font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#8A6CF6;margin:2px 0 8px">' . esc_html($text) . '</div>';
}

/**
 * Order summary card. $items = [['name'=>,'meta'=>,'amount'=>], ...];
 * pass $total to append a bold Total row.
 */
function lmeg_email_order_table($items, $total = null) {
    $rows = '';
    foreach ($items as $it) {
        $rows .= '<tr><td style="padding:10px 0;border-bottom:1px solid #f0eef4;vertical-align:top">'
            . '<div style="font-weight:650;font-size:15px;color:#1a1622">' . esc_html($it['name']) . '</div>'
            . (!empty($it['meta']) ? '<div style="font-size:13px;color:#9a94a8;margin-top:2px">' . esc_html($it['meta']) . '</div>' : '')
            . '</td><td align="right" style="padding:10px 0;border-bottom:1px solid #f0eef4;font-weight:700;color:#1a1622;white-space:nowrap;vertical-align:top">' . esc_html($it['amount']) . '</td></tr>';
    }
    $totalrow = ($total !== null)
        ? '<tr><td style="padding:12px 0 2px;font-weight:800;font-size:16px;color:#1a1622">Total</td><td align="right" style="padding:12px 0 2px;font-weight:800;font-size:16px;color:#1a1622">' . esc_html($total) . '</td></tr>'
        : '';
    return '<div style="margin:6px 0 20px;background:#faf9fc;border:1px solid #efedf3;border-radius:12px;padding:6px 18px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . $totalrow . '</table></div>';
}

/** Downloads section — $dls = [['name'=>,'url'=>], ...] renders a button each. */
function lmeg_email_download_block($dls) {
    if (!$dls) return '';
    $inner = '';
    foreach ($dls as $d) {
        $inner .= '<tr><td style="padding:6px 0"><div style="font-weight:650;font-size:14px;color:#1a1622;margin-bottom:7px">' . esc_html($d['name']) . '</div>' . lmeg_email_button('Download →', $d['url']) . '</td></tr>';
    }
    return '<div style="margin:2px 0 16px">' . lmeg_email_label('Your downloads')
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $inner . '</table></div>';
}

/** Shipping address block. */
function lmeg_email_ship_block($name, $addr) {
    $lines = $name ? '<div style="font-weight:650;color:#1a1622">' . esc_html($name) . '</div>' : '';
    if ($addr) foreach (explode("\n", $addr) as $l) { if (trim($l) !== '') $lines .= '<div style="color:#514b5e;font-size:14px;line-height:1.5">' . esc_html($l) . '</div>'; }
    return '<div style="margin:2px 0 16px">' . lmeg_email_label('Shipping to') . $lines . '</div>';
}

/** Subtle info note. */
function lmeg_email_note($html) {
    return '<div style="margin:2px 0 14px;background:#f6f3fb;border:1px solid #ece7f6;border-radius:10px;padding:12px 14px;font-size:13px;color:#6b6478;line-height:1.6">' . $html . '</div>';
}
