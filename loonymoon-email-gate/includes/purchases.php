<?php
if (!defined('ABSPATH')) exit;

/* ============================================================================
 * Fanloop Store — buyer self-service "my purchases"  (BETA)
 * ----------------------------------------------------------------------------
 * No login. A fan enters their email and we send a magic link (good for 30
 * minutes) that lists everything they've bought — fresh download links for
 * digital items, and order status for physical ones. The email is never
 * revealed to whoever types it: we always show the same neutral message and
 * only email an address that actually has purchases.
 *
 * Reuses the store's per-purchase access tokens (lmeg_product_access_url) and
 * the on-brand page chrome (lmeg_store_page) from cart.php.
 * ========================================================================== */

add_action('init', 'lmeg_purchases_router');
function lmeg_purchases_router() {
    if (!isset($_GET['lmeg_purchases'])) return;
    $a = sanitize_key($_GET['lmeg_purchases']);
    if ($a === 'request')   lmeg_purchases_request();
    elseif ($a === 'view')  lmeg_purchases_view();
    elseif ($a === 'find')  lmeg_purchases_find_page();
}

/** Standalone email-entry page (shareable URL, even without the shortcode). */
function lmeg_purchases_find_page() {
    if (function_exists('lmeg_store_page')) {
        lmeg_store_page('Find my purchases',
            '<div class="dot">' . lmeg_store_icon('key', 24, ['style' => 'color:#0B0C12']) . '</div><h1>Find your purchases</h1>'
            . '<p>Enter the email you bought with and we\'ll send you a link to view and re-download everything.</p>'
            . lmeg_purchases_form_html(true)
            . '<a class="home" href="' . esc_url(home_url('/')) . '">' . lmeg_store_icon('arrow-left', 13, ['style' => 'margin-right:4px;vertical-align:-2px']) . 'Back to site</a>', 'Purchases');
    }
}

/** The email-entry form. $dark = styled for the on-brand dark store pages. */
function lmeg_purchases_form_html($dark = false) {
    $action = esc_url(add_query_arg(['lmeg_purchases' => 'request'], home_url('/')));
    if ($dark) {
        return '<form method="post" action="' . $action . '" style="margin:6px 0 2px;text-align:left">'
            . '<input type="email" name="email" required placeholder="you@email.com" style="width:100%;padding:12px 13px;border-radius:10px;border:1px solid rgba(255,255,255,.16);background:#0E1017;color:#fff;font-size:15px">'
            . '<button type="submit" style="margin-top:12px;width:100%;background:linear-gradient(118deg,#E15FA8,#8A6CF6);color:#0B0C12;font-weight:800;border:0;padding:14px;border-radius:12px;font-size:15px;cursor:pointer">Email me my downloads</button>'
            . '</form>';
    }
    // Light card for embedding on the artist's own (themed) page.
    return '<form method="post" action="' . $action . '" class="flp-purchases" style="max-width:420px;border:1px solid rgba(0,0,0,.12);border-radius:16px;padding:22px;background:#fff;box-shadow:0 12px 40px rgba(0,0,0,.08);font-family:inherit">'
        . '<div style="font-weight:750;font-size:18px;margin-bottom:4px;color:#111">Find your purchases</div>'
        . '<div style="font-size:14px;color:#444;line-height:1.5;margin-bottom:14px">Enter the email you bought with — we\'ll send a link to view and re-download everything.</div>'
        . '<input type="email" name="email" required placeholder="you@email.com" style="width:100%;padding:11px 12px;border-radius:10px;border:1px solid #ccc;font-size:15px;color:#111">'
        . '<button type="submit" style="margin-top:12px;width:100%;background:#E15FA8;color:#fff;font-weight:700;border:0;padding:12px;border-radius:10px;font-size:15px;cursor:pointer">Email me my downloads</button>'
        . '</form>';
}

/** Handle the email request — send the magic link (throttled), show neutral page. */
function lmeg_purchases_request() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { wp_safe_redirect(home_url('/')); exit; }
    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));

    if ($email && is_email($email)) {
        global $wpdb;
        $ptbl = $wpdb->prefix . 'lmeg_product_purchases';
        $has  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ptbl WHERE email = %s AND status = 'paid'", $email));
        // Throttle: at most one link email per address every 2 minutes.
        $lock = 'lmeg_purch_lock_' . md5(strtolower($email));
        if ($has && !get_transient($lock)) {
            set_transient($lock, 1, 2 * MINUTE_IN_SECONDS);
            $token = wp_generate_password(40, false, false);
            set_transient('lmeg_purch_' . $token, $email, 30 * MINUTE_IN_SECONDS);
            lmeg_purchases_send_link($email, $token);
        }
    }

    $shown = $email ? esc_html($email) : 'that address';
    lmeg_store_page('Check your email',
        '<div class="dot">' . lmeg_store_icon('mail', 24, ['style' => 'color:#0B0C12']) . '</div><h1>Check your email</h1>'
        . '<p>If there are any purchases under <strong>' . $shown . '</strong>, we just sent a link to view and re-download them. It\'s good for 30 minutes — check your spam folder if it doesn\'t arrive.</p>'
        . '<a class="home" href="' . esc_url(home_url('/')) . '">' . lmeg_store_icon('arrow-left', 13, ['style' => 'margin-right:4px;vertical-align:-2px']) . 'Back to site</a>', 'Purchases');
}

/** Email the magic link. */
function lmeg_purchases_send_link($email, $token) {
    $artist = function_exists('lmeg_email_artist') ? lmeg_email_artist() : get_bloginfo('name');
    $url    = add_query_arg(['lmeg_purchases' => 'view', 'token' => $token], home_url('/'));
    if (!function_exists('lmeg_email_deliver')) return;
    $inner  = lmeg_email_h('Your purchases')
            . lmeg_email_p('Here\'s your link to view and re-download everything you\'ve bought from <strong>' . esc_html($artist) . '</strong>.')
            . lmeg_email_button('View my purchases →', $url)
            . lmeg_email_note('This link works for 30 minutes. If you didn\'t request it, you can safely ignore this email.');
    lmeg_email_deliver($email, 'Your purchases from ' . $artist, $inner, 'Your link to view and re-download your purchases.');
}

/** Render the verified purchases list behind the magic link. */
function lmeg_purchases_view() {
    $token = sanitize_text_field($_GET['token'] ?? '');
    $email = $token ? get_transient('lmeg_purch_' . $token) : '';
    if (!$email) {
        lmeg_store_page('Link expired',
            '<div class="dot">' . lmeg_store_icon('clock', 24, ['style' => 'color:#0B0C12']) . '</div><h1>This link has expired</h1>'
            . '<p>For your security, purchase links are good for 30 minutes. Request a fresh one and we\'ll email it right over.</p>'
            . '<a class="home" href="' . esc_url(add_query_arg(['lmeg_purchases' => 'find'], home_url('/'))) . '">Get a new link →</a>'
            . '<a class="home" href="' . esc_url(home_url('/')) . '">' . lmeg_store_icon('arrow-left', 13, ['style' => 'margin-right:4px;vertical-align:-2px']) . 'Back to site</a>', 'Purchases');
    }

    global $wpdb;
    $ptbl = $wpdb->prefix . 'lmeg_product_purchases';
    $tbl  = $wpdb->prefix . 'lmeg_products';
    $OK   = function_exists('lmeg_orders_okey_expr') ? lmeg_orders_okey_expr('pp.') : 'pp.id';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT pp.*, pr.title, pr.type, $OK okey FROM $ptbl pp LEFT JOIN $tbl pr ON pr.id = pp.product_id
         WHERE pp.email = %s AND pp.status = 'paid' ORDER BY pp.id DESC", $email));

    lmeg_store_page('Your purchases',
        '<div class="dot">' . lmeg_store_icon('headphones', 24, ['style' => 'color:#0B0C12']) . '</div><h1>Your orders</h1>'
        . '<p>Everything under <strong>' . esc_html($email) . '</strong>. Downloads are ready anytime; shipped items show tracking.</p>'
        . lmeg_purchases_orders_html($rows)
        . '<a class="home" href="' . esc_url(home_url('/')) . '">' . lmeg_store_icon('arrow-left', 13, ['style' => 'margin-right:4px;vertical-align:-2px']) . 'Back to site</a>', 'Purchases');
}

/** Group paid purchase lines (each carrying an `okey`) into per-order cards. */
function lmeg_purchases_orders_html($rows) {
    $money = function ($c, $cur) { return function_exists('lmeg_cart_money') ? lmeg_cart_money($c, $cur) : ('$' . number_format($c / 100, 2)); };

    // Group lines into orders, preserving newest-first order.
    $orders = [];
    foreach ((array) $rows as $r) { $orders[$r->okey][] = $r; }
    if (!$orders) return '<div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:20px;color:#8B90A0">No purchases found under this email yet.</div>';

    $out = '';
    foreach ($orders as $okey => $lines) {
        $first = $lines[0];
        $cur = $first->currency ?: 'USD';
        $total = 0; $any_phys = false; $all_shipped = true; $track = ''; $carrier = '';
        foreach ($lines as $r) {
            $total += (int) $r->amount_cents;
            if (($r->type ?? '') === 'physical') {
                $any_phys = true;
                if (($r->fulfillment ?? '') !== 'shipped') $all_shipped = false;
                if (!empty($r->tracking)) { $track = (string) $r->tracking; $carrier = (string) ($r->carrier ?? ''); }
            }
        }
        $when = $first->paid_at ? date_i18n(get_option('date_format'), strtotime($first->paid_at)) : '';
        $ref  = strtoupper(substr((string) $okey, -6));

        // Per-item rows.
        $itemhtml = '';
        foreach ($lines as $r) {
            $digital = (($r->type ?? '') !== 'physical');
            $right = '';
            if ($digital && $r->access_token) {
                $used = ((int) $r->access_count >= (int) $r->access_limit);
                $right = $used
                    ? '<span style="color:#F0A0A0;font-size:12px">download limit reached — reply to your receipt</span>'
                    : '<a href="' . esc_url(lmeg_product_access_url($r->access_token)) . '" style="background:linear-gradient(118deg,#E15FA8,#8A6CF6);color:#0B0C12;font-weight:750;text-decoration:none;padding:8px 14px;border-radius:9px;white-space:nowrap;font-size:13px">Download →</a>';
            } elseif ($digital) {
                $right = '<span style="color:#8B90A0;font-size:12px">no file yet</span>';
            }
            $qty = (int) ($r->qty ?: 1);
            $itemhtml .= '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 0;border-top:1px solid rgba(255,255,255,.06)">'
                . '<div style="text-align:left;min-width:0"><div style="font-weight:600;font-size:14px">' . ($qty > 1 ? $qty . '× ' : '') . esc_html($r->title ?: 'Item') . ($r->variant ? ' <span style="color:#9AA">· ' . esc_html($r->variant) . '</span>' : '') . '</div>'
                . '<div style="color:#8B90A0;font-size:12px">' . esc_html($money($r->amount_cents, $cur)) . '</div></div>'
                . $right . '</div>';
        }

        // Order-level shipping status (physical orders only).
        $ship = '';
        if ($any_phys) {
            if ($all_shipped) {
                $turl = ($track && function_exists('lmeg_tracking_url')) ? lmeg_tracking_url($carrier, $track) : '';
                $line = lmeg_store_icon('check-circle', 14, ['style' => 'margin-right:5px;vertical-align:-2px']) . 'Shipped' . ($carrier ? ' · ' . esc_html($carrier) : '') . ($track ? ' · ' . esc_html($track) : '');
                $ship = '<div style="margin-top:10px;padding:9px 12px;background:rgba(125,211,168,.12);border:1px solid rgba(125,211,168,.3);border-radius:9px;font-size:13px;color:#8fe3b5">' . $line
                    . ($turl ? ' <a href="' . esc_url($turl) . '" target="_blank" rel="noopener" style="color:#8fe3b5;font-weight:700">Track →</a>' : '') . '</div>';
            } else {
                $ship = '<div style="margin-top:10px;padding:9px 12px;background:rgba(231,201,125,.1);border:1px solid rgba(231,201,125,.3);border-radius:9px;font-size:13px;color:#E7C97D;display:flex;align-items:center;gap:6px">' . lmeg_store_icon('truck', 15) . 'Preparing to ship — you\'ll get tracking by email.</div>';
            }
        }

        $out .= '<div style="text-align:left;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:14px 16px;margin-bottom:12px">'
            . '<div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px">'
            . '<div style="font-size:12px;color:#8B90A0;letter-spacing:.04em">ORDER #' . esc_html($ref) . ($when ? ' · ' . esc_html($when) : '') . '</div>'
            . '<div style="font-weight:800;color:#fff">' . esc_html($money($total, $cur)) . '</div></div>'
            . $itemhtml . $ship . '</div>';
    }
    return $out;
}

/* Shortcode — [fanloop_purchases] / [loony_purchases] : embed the finder form. */
add_shortcode('fanloop_purchases', 'lmeg_purchases_shortcode');
add_shortcode('loony_purchases', 'lmeg_purchases_shortcode');
function lmeg_purchases_shortcode($atts) {
    return lmeg_purchases_form_html(false);
}
