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

    $sent = lmeg_waitlist_notify_all($p);
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products&edit=' . $pid . '&notified_wl=' . $sent)); exit;
}

/**
 * Email every un-notified fan on a product's waitlist that it's back, mark them
 * notified, and return how many were emailed. Shared by the manual "Notify"
 * button and the automatic on-restock path. Idempotent: marks notified as it
 * goes, so a second call sends to nobody.
 */
function lmeg_waitlist_notify_all($p) {
    global $wpdb;
    if (!$p) return 0;
    $tbl = $wpdb->prefix . 'lmeg_waitlist';
    $waiters = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tbl WHERE product_id = %d AND notified = 0 ORDER BY id ASC LIMIT 2000", (int) $p->id));
    $sent = 0;
    foreach ((array) $waiters as $w) {
        if ($w->email && lmeg_waitlist_send_back($p, $w->email)) $sent++;
        $wpdb->update($tbl, ['notified' => 1, 'notified_at' => current_time('mysql')], ['id' => (int) $w->id]);
    }
    return $sent;
}

/**
 * Called after a product is saved. If it just went from sold-out → available
 * and auto-notify is on, email everyone waiting. Guarded so it only fires on a
 * genuine restock (was unavailable before, is available now).
 */
function lmeg_waitlist_maybe_autonotify($before, $after) {
    if (!function_exists('lmeg_product_is_available')) return 0;
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    if (isset($s['store_waitlist_auto']) && !$s['store_waitlist_auto']) return 0;   // opted out
    if (!$before || !$after) return 0;
    if (lmeg_product_is_available($before)) return 0;   // wasn't sold out — nothing to restock
    if (!lmeg_product_is_available($after))  return 0;   // still not available
    if (lmeg_waitlist_count($after->id) < 1) return 0;   // nobody waiting
    return lmeg_waitlist_notify_all($after);
}

/* ---------------------------------------------------------------------------
 * Admin: a "Waitlist" section on the Store page + CSV export.
 * ------------------------------------------------------------------------- */
function lmeg_waitlist_admin_section() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $tbl  = $wpdb->prefix . 'lmeg_waitlist';
    $ptbl = $wpdb->prefix . 'lmeg_products';
    $rows = $wpdb->get_results("SELECT w.product_id pid, COUNT(*) n, MIN(w.created_at) oldest, p.title FROM $tbl w LEFT JOIN $ptbl p ON p.id = w.product_id WHERE w.notified = 0 GROUP BY w.product_id, p.title ORDER BY n DESC");
    if (!$rows) return;   // nobody waiting
    $save  = admin_url('admin-post.php');
    $total = 0; foreach ($rows as $r) $total += (int) $r->n;
    ?>
    <h2 id="waitlist" style="margin-top:30px">Waitlist <span style="font-size:12px;color:#8B90A0;font-weight:400">· <?php echo number_format_i18n($total); ?> waiting</span>
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lmeg_export_waitlist'), 'lmeg_export_waitlist')); ?>" class="button button-small" style="vertical-align:middle;margin-left:8px">⬇ Export CSV</a></h2>
    <p class="description" style="margin:0 0 12px;max-width:800px">Fans waiting for a sold-out product to come back. Restock it, then hit “Notify” to email everyone a link.</p>
    <table class="widefat striped" style="max-width:760px">
        <thead><tr><th>Product</th><th>Waiting</th><th>Since</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r) : ?>
            <tr>
                <td><strong><?php echo esc_html($r->title ?: 'Product #' . (int) $r->pid); ?></strong></td>
                <td>🔔 <?php echo (int) $r->n; ?></td>
                <td><?php echo esc_html($r->oldest ? human_time_diff(strtotime($r->oldest), current_time('timestamp')) . ' ago' : '—'); ?></td>
                <td>
                    <form method="post" action="<?php echo esc_url($save); ?>" style="display:inline" onsubmit="return confirm('Email all <?php echo (int) $r->n; ?> waiting fans that this is back in stock?');">
                        <?php wp_nonce_field('lmeg_notify_waitlist', 'lmeg_waitlist_nonce'); ?>
                        <input type="hidden" name="action" value="lmeg_notify_waitlist">
                        <input type="hidden" name="product_id" value="<?php echo (int) $r->pid; ?>">
                        <button type="submit" class="button button-small">Notify them it's back</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

add_action('admin_post_lmeg_export_waitlist', 'lmeg_handle_export_waitlist');
function lmeg_handle_export_waitlist() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_export_waitlist');
    global $wpdb;
    $tbl  = $wpdb->prefix . 'lmeg_waitlist';
    $ptbl = $wpdb->prefix . 'lmeg_products';
    $rows = $wpdb->get_results("SELECT w.email, w.variant, w.created_at, p.title FROM $tbl w LEFT JOIN $ptbl p ON p.id = w.product_id WHERE w.notified = 0 ORDER BY w.product_id, w.id");
    $safe = function ($v) { $v = (string) $v; return ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) ? "'" . $v : $v; };

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fanloop-waitlist-' . gmdate('Y-m-d') . '.csv"');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, ['Product', 'Variant', 'Email', 'Signed up']);
    foreach ((array) $rows as $r) fputcsv($fh, [$safe($r->title), $safe($r->variant), $safe($r->email), $r->created_at]);
    fclose($fh);
    exit;
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
