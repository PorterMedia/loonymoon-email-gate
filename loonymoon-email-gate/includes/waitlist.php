<?php
if (!defined('ABSPATH')) exit;

/* ============================================================================
 * Fanloop Store — sold-out waitlist  (BETA)
 * ----------------------------------------------------------------------------
 * When a product is sold out, fans can leave their email to be notified when
 * it's back. The artist sees the count and, when they restock, sends a branded
 * "it's back" email to everyone waiting with one click. Table: lmeg_waitlist.
 * ========================================================================== */

function lmeg_waitlist_count($product_id) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}lmeg_waitlist WHERE product_id = %d AND notified = 0", (int) $product_id));
}

/* Front-end: join the waitlist for a product. */
add_action('init', 'lmeg_waitlist_router');
function lmeg_waitlist_router() {
    if (isset($_GET['lmeg_waitlist']) && sanitize_key($_GET['lmeg_waitlist']) === 'join') lmeg_waitlist_join();
}

function lmeg_waitlist_join() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { wp_safe_redirect(home_url('/')); exit; }
    global $wpdb;
    $pid     = (int) ($_POST['product_id'] ?? 0);
    $variant = sanitize_text_field(wp_unslash($_POST['variant'] ?? ''));
    $email   = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $p       = $pid ? lmeg_product_get($pid) : null;

    if ($p && $email && is_email($email)) {
        $tbl = $wpdb->prefix . 'lmeg_waitlist';
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $tbl WHERE product_id = %d AND variant = %s AND email = %s AND notified = 0", $pid, $variant, $email));
        if (!$exists) {
            $wpdb->insert($tbl, ['product_id' => $pid, 'variant' => $variant, 'email' => $email, 'notified' => 0, 'created_at' => current_time('mysql')]);
        }
    }
    $title = $p ? $p->title : 'this';
    lmeg_store_page('You\'re on the list',
        '<div class="dot">🔔</div><h1>You\'re on the list</h1>'
        . '<p>We\'ll email <strong>' . esc_html($email ?: 'you') . '</strong> the moment <strong>' . esc_html($title) . '</strong> is back in stock.</p>'
        . '<a class="home" href="' . esc_url(home_url('/')) . '">← Back to site</a>', 'Waitlist');
}

/** The sold-out "notify me" form for a product card (light surface). */
function lmeg_waitlist_form_html($p) {
    $action = esc_url(add_query_arg(['lmeg_waitlist' => 'join'], home_url('/')));
    return '<form method="post" action="' . $action . '" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">'
        . '<input type="hidden" name="product_id" value="' . (int) $p->id . '">'
        . '<input type="email" name="email" required placeholder="Email me when it\'s back" style="flex:1;min-width:150px;padding:9px 11px;border:1px solid #ccc;border-radius:9px;color:#17141f;background:#fff;font-size:13px">'
        . '<button type="submit" style="background:#E15FA8;color:#fff;border:0;font-weight:700;padding:9px 15px;border-radius:9px;cursor:pointer;font-size:13px">Notify me</button>'
        . '</form>';
}

/* ---------------------------------------------------------------------------
 * Admin: notify everyone waiting on a product (manual "it's back").
 * ------------------------------------------------------------------------- */
add_action('admin_post_lmeg_notify_waitlist', 'lmeg_handle_notify_waitlist');
function lmeg_handle_notify_waitlist() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_notify_waitlist', 'lmeg_waitlist_nonce');
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_waitlist';
    $pid = (int) ($_POST['product_id'] ?? 0);
    $p   = $pid ? lmeg_product_get($pid) : null;
    if (!$p) { wp_safe_redirect(admin_url('admin.php?page=lmeg-products')); exit; }

    $waiters = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tbl WHERE product_id = %d AND notified = 0 ORDER BY id ASC LIMIT 2000", $pid));
    $sent = 0;
    foreach ((array) $waiters as $w) {
        if ($w->email && lmeg_waitlist_send_back($p, $w->email)) $sent++;
        $wpdb->update($tbl, ['notified' => 1, 'notified_at' => current_time('mysql')], ['id' => (int) $w->id]);
    }
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products&edit=' . $pid . '&notified_wl=' . $sent)); exit;
}

/** Branded "it's back in stock" email. */
function lmeg_waitlist_send_back($p, $email) {
    if (!function_exists('lmeg_email_deliver')) return false;
    $artist = lmeg_email_artist();
    $url    = function_exists('lmeg_product_url') ? lmeg_product_url($p) : home_url('/');
    $price  = function_exists('lmeg_format_price') ? lmeg_format_price((int) $p->price_cents, $p->currency ?: 'USD') : ('$' . number_format($p->price_cents / 100, 2));
    $inner  = lmeg_email_h('It\'s back in stock 🎉')
        . lmeg_email_p('<strong>' . esc_html($p->title) . '</strong> is available again' . (lmeg_product_is_pwyw($p) ? '' : ' (' . esc_html($price) . ')') . ' — grab it before it\'s gone.')
        . lmeg_email_button('Get it now →', $url)
        . lmeg_email_note('You\'re getting this because you asked to be notified. We won\'t email you about it again.');
    return lmeg_email_deliver($email, esc_html($p->title) . ' is back in stock', $inner, $p->title . ' is available again.');
}
