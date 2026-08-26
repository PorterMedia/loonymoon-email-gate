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
            '<div class="dot">🔑</div><h1>Find your purchases</h1>'
            . '<p>Enter the email you bought with and we\'ll send you a link to view and re-download everything.</p>'
            . lmeg_purchases_form_html(true)
            . '<a class="home" href="' . esc_url(home_url('/')) . '">← Back to site</a>', 'Purchases');
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
        '<div class="dot">✉️</div><h1>Check your email</h1>'
        . '<p>If there are any purchases under <strong>' . $shown . '</strong>, we just sent a link to view and re-download them. It\'s good for 30 minutes — check your spam folder if it doesn\'t arrive.</p>'
        . '<a class="home" href="' . esc_url(home_url('/')) . '">← Back to site</a>', 'Purchases');
}

/** Email the magic link. */
function lmeg_purchases_send_link($email, $token) {
    $s      = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $artist = $s['community_name'] ?? ($s['artist_name'] ?? get_bloginfo('name'));
    $url    = add_query_arg(['lmeg_purchases' => 'view', 'token' => $token], home_url('/'));
    $body   = '<div style="font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px;color:#111">'
        . '<h2 style="margin:0 0 10px">Your purchases</h2>'
        . '<p style="color:#444;margin:0 0 18px">Here\'s your link to view and re-download everything you\'ve bought from ' . esc_html($artist) . '. It works for 30 minutes.</p>'
        . '<p style="margin:0 0 22px"><a href="' . esc_url($url) . '" style="display:inline-block;background:#E15FA8;color:#fff;text-decoration:none;font-weight:700;padding:13px 26px;border-radius:10px">View my purchases →</a></p>'
        . '<p style="color:#888;font-size:13px">Or paste this into your browser:<br>' . esc_html($url) . '</p></div>';
    add_filter('wp_mail_content_type', function () { return 'text/html'; });
    wp_mail($email, 'Your purchases from ' . $artist, $body);
    remove_all_filters('wp_mail_content_type');
}

/** Render the verified purchases list behind the magic link. */
function lmeg_purchases_view() {
    $token = sanitize_text_field($_GET['token'] ?? '');
    $email = $token ? get_transient('lmeg_purch_' . $token) : '';
    if (!$email) {
        lmeg_store_page('Link expired',
            '<div class="dot">⌛</div><h1>This link has expired</h1>'
            . '<p>For your security, purchase links are good for 30 minutes. Request a fresh one and we\'ll email it right over.</p>'
            . '<a class="home" href="' . esc_url(add_query_arg(['lmeg_purchases' => 'find'], home_url('/'))) . '">Get a new link →</a>'
            . '<a class="home" href="' . esc_url(home_url('/')) . '">← Back to site</a>', 'Purchases');
    }

    global $wpdb;
    $ptbl = $wpdb->prefix . 'lmeg_product_purchases';
    $tbl  = $wpdb->prefix . 'lmeg_products';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT pp.*, pr.title, pr.type FROM $ptbl pp LEFT JOIN $tbl pr ON pr.id = pp.product_id
         WHERE pp.email = %s AND pp.status = 'paid' ORDER BY pp.id DESC", $email));

    $items = '';
    foreach ((array) $rows as $r) {
        $digital = ($r->type !== 'physical');
        $meta    = esc_html(function_exists('lmeg_cart_money') ? lmeg_cart_money($r->amount_cents, $r->currency) : ('$' . number_format($r->amount_cents / 100, 2)))
                 . ($r->paid_at ? ' · ' . esc_html(date_i18n(get_option('date_format'), strtotime($r->paid_at))) : '');
        $right   = '';
        if ($digital && $r->access_token) {
            $used = ((int) $r->access_count >= (int) $r->access_limit);
            $right = $used
                ? '<span style="color:#F0A0A0;font-size:12px">download limit reached</span>'
                : '<a href="' . esc_url(lmeg_product_access_url($r->access_token)) . '" style="background:linear-gradient(118deg,#E15FA8,#8A6CF6);color:#0B0C12;font-weight:750;text-decoration:none;padding:9px 15px;border-radius:9px;white-space:nowrap;font-size:14px">Download →</a>';
        } elseif (!$digital) {
            $right = ($r->fulfillment === 'shipped')
                ? '<span style="color:#7DD3A8;font-size:13px;font-weight:600;white-space:nowrap">Shipped</span>'
                : '<span style="color:#E7C97D;font-size:13px;font-weight:600;white-space:nowrap">Preparing</span>';
        }
        $items .= '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.08)">'
            . '<div style="text-align:left;min-width:0"><div style="font-weight:650">' . esc_html($r->title ?: 'Item') . ($r->variant ? ' · <span style="color:#9AA">' . esc_html($r->variant) . '</span>' : '') . '</div>'
            . '<div style="color:#8B90A0;font-size:12px">' . $meta . '</div></div>' . $right . '</div>';
    }

    if ($items === '') {
        $items = '<p style="color:#8B90A0">No purchases found under this email.</p>';
    }

    lmeg_store_page('Your purchases',
        '<div class="dot">🎧</div><h1>Your purchases</h1>'
        . '<p>Everything under <strong>' . esc_html($email) . '</strong>. Digital downloads are ready anytime.</p>'
        . '<div style="text-align:left;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:4px 16px 12px">' . $items . '</div>'
        . '<a class="home" href="' . esc_url(home_url('/')) . '">← Back to site</a>', 'Purchases');
}

/* Shortcode — [fanloop_purchases] / [loony_purchases] : embed the finder form. */
add_shortcode('fanloop_purchases', 'lmeg_purchases_shortcode');
add_shortcode('loony_purchases', 'lmeg_purchases_shortcode');
function lmeg_purchases_shortcode($atts) {
    return lmeg_purchases_form_html(false);
}
