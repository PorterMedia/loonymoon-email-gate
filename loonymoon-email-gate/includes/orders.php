<?php
if (!defined('ABSPATH')) exit;

/* ============================================================================
 * Fanloop Store — Orders admin  (BETA)
 * ----------------------------------------------------------------------------
 * A unified order view. Purchase rows are stored per line item; this page
 * GROUPS them into real orders (a cart checkout = one order, many lines) keyed
 * by the checkout reference, with search, filters, pagination, and per-order
 * actions: mark the whole order shipped (with tracking) and resend the receipt
 * / download links. Read-only aggregation over lmeg_product_purchases — no
 * schema of its own.
 * ========================================================================== */

/** SQL expression that collapses a line's ref into its order key (join alias 'pp'). */
function lmeg_orders_okey_expr($alias = 'pp.') {
    return "COALESCE(NULLIF(SUBSTRING_INDEX({$alias}provider_ref,'#',1),''), {$alias}stripe_session_id, CAST({$alias}id AS CHAR))";
}

function lmeg_orders_money($c, $cur) {
    return function_exists('lmeg_format_price') ? lmeg_format_price((int) $c, $cur ?: 'USD') : ('$' . number_format($c / 100, 2));
}

/* ---------------------------------------------------------------------------
 * Actions: ship a whole order / resend its receipt
 * ------------------------------------------------------------------------- */
add_action('admin_post_lmeg_ship_group', 'lmeg_handle_ship_group');
function lmeg_handle_ship_group() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_ship_group', 'lmeg_shipg_nonce');
    global $wpdb;
    $ptbl = $wpdb->prefix . 'lmeg_product_purchases';
    $okey = sanitize_text_field(wp_unslash($_POST['okey'] ?? ''));
    if ($okey === '') { wp_safe_redirect(admin_url('admin.php?page=lmeg-orders')); exit; }
    $carrier  = sanitize_text_field(wp_unslash($_POST['carrier'] ?? ''));
    $tracking = sanitize_text_field(wp_unslash($_POST['tracking'] ?? ''));
    $expr     = lmeg_orders_okey_expr('');   // single-table UPDATE — no alias

    $rep = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ptbl WHERE status='paid' AND fulfillment='unshipped' AND $expr = %s LIMIT 1", $okey));
    $wpdb->query($wpdb->prepare("UPDATE $ptbl SET fulfillment='shipped', tracking=%s, carrier=%s WHERE status='paid' AND fulfillment='unshipped' AND $expr = %s", $tracking ?: null, $carrier ?: null, $okey));
    if ($rep && $rep->email && function_exists('lmeg_product_send_shipped')) {
        $rep->tracking = $tracking ?: null; $rep->carrier = $carrier ?: null;
        lmeg_product_send_shipped($rep);
    }
    wp_safe_redirect(admin_url('admin.php?page=lmeg-orders&shipped=1' . lmeg_orders_keep_args())); exit;
}

add_action('admin_post_lmeg_resend_receipt', 'lmeg_handle_resend_receipt');
function lmeg_handle_resend_receipt() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_resend_receipt', 'lmeg_resend_nonce');
    global $wpdb;
    $ptbl = $wpdb->prefix . 'lmeg_product_purchases';
    $tbl  = $wpdb->prefix . 'lmeg_products';
    $okey = sanitize_text_field(wp_unslash($_POST['okey'] ?? ''));
    if ($okey === '') { wp_safe_redirect(admin_url('admin.php?page=lmeg-orders')); exit; }
    $expr = lmeg_orders_okey_expr('pp.');
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT pp.*, pr.title, pr.type FROM $ptbl pp LEFT JOIN $tbl pr ON pr.id = pp.product_id WHERE pp.status='paid' AND $expr = %s ORDER BY pp.id ASC", $okey));

    if ($rows && $rows[0]->email && function_exists('lmeg_cart_send_receipt')) {
        $email = $rows[0]->email; $ship_name = ''; $ship_addr = ''; $code = null; $off = 0; $lines = [];
        foreach ($rows as $r) {
            $qty  = max(1, (int) ($r->qty ?: 1));
            $disc = (int) $r->discount_cents;
            $full = (int) $r->amount_cents + $disc;
            $lines[] = [
                'title' => $r->title, 'variant' => (string) $r->variant, 'type' => $r->type,
                'amount' => (int) $r->amount_cents, 'line_discount' => $disc, 'unit' => (int) round($full / $qty),
                'qty' => $qty, 'cur' => strtoupper($r->currency ?: 'USD'), 'token' => (string) $r->access_token,
                'physical' => ($r->type === 'physical'),
            ];
            if ($r->ship_name)     $ship_name = $r->ship_name;
            if ($r->ship_address)  $ship_addr = $r->ship_address;
            if ($r->discount_code) $code = $r->discount_code;
            $off += $disc;
        }
        lmeg_cart_send_receipt($lines, $email, $ship_name, $ship_addr, $code ? ['code' => $code, 'amount_off' => $off] : null);
    }
    wp_safe_redirect(admin_url('admin.php?page=lmeg-orders&resent=1' . lmeg_orders_keep_args())); exit;
}

/** Preserve the current search/filter/page across a redirect. */
function lmeg_orders_keep_args() {
    $keep = '';
    foreach (['q', 'f', 'paged'] as $k) if (isset($_POST[$k]) && $_POST[$k] !== '') $keep .= '&' . $k . '=' . rawurlencode(sanitize_text_field(wp_unslash($_POST[$k])));
    return $keep;
}

/* ---------------------------------------------------------------------------
 * The Orders page
 * ------------------------------------------------------------------------- */
function lmeg_admin_orders() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $ptbl = $wpdb->prefix . 'lmeg_product_purchases';
    $tbl  = $wpdb->prefix . 'lmeg_products';
    $OK   = lmeg_orders_okey_expr('pp.');

    $q     = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
    $f     = isset($_GET['f']) ? sanitize_key($_GET['f']) : 'all';
    $paged = max(1, (int) ($_GET['paged'] ?? 1));
    $per   = 25; $offset = ($paged - 1) * $per;

    // Line-level WHERE + order-level HAVING.
    $where = ["pp.status='paid'"]; $having = []; $hargs = [];
    if ($f === 'demo') $where[] = "pp.processor='demo'";
    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $having[] = "(MAX(pp.email) LIKE %s OR MAX(CASE WHEN pr.title LIKE %s THEN 1 ELSE 0 END)=1)";
        $hargs[] = $like; $hargs[] = $like;
    }
    if ($f === 'toship')  $having[] = "SUM(CASE WHEN pp.fulfillment='unshipped' THEN 1 ELSE 0 END) > 0";
    if ($f === 'digital') $having[] = "SUM(CASE WHEN pr.type='physical' THEN 1 ELSE 0 END) = 0";
    $whereSql  = implode(' AND ', $where);
    $havingSql = $having ? ('HAVING ' . implode(' AND ', $having)) : '';

    $countSql = "SELECT COUNT(*) FROM (SELECT $OK okey FROM $ptbl pp LEFT JOIN $tbl pr ON pr.id=pp.product_id WHERE $whereSql GROUP BY okey $havingSql) t";
    $total    = (int) ($hargs ? $wpdb->get_var($wpdb->prepare($countSql, $hargs)) : $wpdb->get_var($countSql));

    $aggSql = "SELECT $OK okey, MAX(pp.id) maxid, MAX(pp.paid_at) when_, MAX(pp.email) email,
                SUM(pp.amount_cents) total, MAX(pp.currency) cur, MAX(pp.processor) processor,
                MAX(pp.discount_code) code, SUM(pp.discount_cents) disc,
                MAX(pp.ship_name) ship_name, MAX(pp.ship_address) ship_addr,
                MAX(pp.tracking) tracking, MAX(pp.carrier) carrier,
                SUM(CASE WHEN pp.fulfillment='unshipped' THEN 1 ELSE 0 END) unshipped,
                SUM(CASE WHEN pr.type='physical' THEN 1 ELSE 0 END) physicals,
                SUM(pp.qty) items, COUNT(*) nlines
               FROM $ptbl pp LEFT JOIN $tbl pr ON pr.id=pp.product_id
               WHERE $whereSql GROUP BY okey $havingSql ORDER BY maxid DESC LIMIT %d OFFSET %d";
    $orders = $wpdb->get_results($wpdb->prepare($aggSql, array_merge($hargs, [$per, $offset])));

    // Line items for the visible orders.
    $lines_by = [];
    $keys = array_map(function ($o) { return $o->okey; }, (array) $orders);
    if ($keys) {
        $ph = implode(',', array_fill(0, count($keys), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pp.*, pr.title, pr.type, $OK okey FROM $ptbl pp LEFT JOIN $tbl pr ON pr.id=pp.product_id WHERE pp.status='paid' AND $OK IN ($ph) ORDER BY pp.id ASC", $keys));
        foreach ((array) $rows as $ln) $lines_by[$ln->okey][] = $ln;
    }

    // Headline stats (all-time).
    $rev_all    = (int) $wpdb->get_var("SELECT COALESCE(SUM(amount_cents),0) FROM $ptbl WHERE status='paid'");
    $orders_all = (int) $wpdb->get_var("SELECT COUNT(DISTINCT " . lmeg_orders_okey_expr('') . ") FROM $ptbl WHERE status='paid'");
    $toship_all = (int) $wpdb->get_var("SELECT COUNT(DISTINCT " . lmeg_orders_okey_expr('') . ") FROM $ptbl WHERE status='paid' AND fulfillment='unshipped'");
    $save = admin_url('admin-post.php');

    echo '<div class="wrap"><h1>Fanloop — Orders</h1>';
    if (isset($_GET['shipped'])) echo '<div class="notice notice-success is-dismissible"><p>Order marked shipped and the buyer notified.</p></div>';
    if (isset($_GET['resent']))  echo '<div class="notice notice-success is-dismissible"><p>Receipt re-sent.</p></div>';
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;max-width:720px;margin:12px 0 18px">
        <div class="lmeg-stat"><div class="lmeg-stat__label">Orders</div><div class="lmeg-stat__value"><?php echo number_format_i18n($orders_all); ?></div></div>
        <div class="lmeg-stat"><div class="lmeg-stat__label">Revenue</div><div class="lmeg-stat__value"><?php echo esc_html(lmeg_orders_money($rev_all, 'USD')); ?></div></div>
        <div class="lmeg-stat"><div class="lmeg-stat__label">To ship</div><div class="lmeg-stat__value"><?php echo number_format_i18n($toship_all); ?></div></div>
    </div>

    <form method="get" style="margin:0 0 14px">
        <input type="hidden" name="page" value="lmeg-orders">
        <?php $pill = function ($key, $label) use ($f, $q) {
            $on = ($f === $key);
            $url = add_query_arg(array_filter(['page' => 'lmeg-orders', 'f' => $key === 'all' ? false : $key, 'q' => $q ?: false]), admin_url('admin.php'));
            echo '<a href="' . esc_url($url) . '" class="button' . ($on ? ' button-primary' : '') . '" style="margin-right:6px">' . esc_html($label) . '</a>';
        }; ?>
        <?php $pill('all', 'All'); $pill('toship', 'To ship'); $pill('digital', 'Digital'); $pill('demo', 'Demo'); ?>
        &nbsp; <input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Search email or product…" style="min-width:220px">
        <?php if ($f !== 'all') : ?><input type="hidden" name="f" value="<?php echo esc_attr($f); ?>"><?php endif; ?>
        <button class="button">Search</button>
        <?php if ($q !== '') : ?><a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'lmeg-orders', 'f' => $f], admin_url('admin.php'))); ?>">Clear</a><?php endif; ?>
    </form>

    <table class="widefat striped">
        <thead><tr><th>When</th><th>Buyer</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$orders) : ?><tr><td colspan="7">No orders<?php echo $q !== '' || $f !== 'all' ? ' match that filter.' : ' yet.'; ?></td></tr>
        <?php else : foreach ($orders as $o) :
            $mylines = $lines_by[$o->okey] ?? [];
            $item_str = [];
            foreach ($mylines as $ln) $item_str[] = $ln->title . ($ln->variant ? ' · ' . $ln->variant : '') . ((int) ($ln->qty ?: 1) > 1 ? ' ×' . (int) $ln->qty : '');
            $cur = $o->cur ?: 'USD';
            $digital_only = ((int) $o->physicals === 0);
            if ((int) $o->unshipped > 0)      { $status = '<span style="color:#E7C97D;font-weight:600">To ship</span>'; }
            elseif (!$digital_only)           { $status = '<span style="color:#7DD3A8;font-weight:600">Shipped</span>' . ($o->tracking && function_exists('lmeg_tracking_url') && lmeg_tracking_url($o->carrier, $o->tracking) ? ' · <a href="' . esc_url(lmeg_tracking_url($o->carrier, $o->tracking)) . '" target="_blank" rel="noopener">track</a>' : ''); }
            else                              { $status = '<span style="color:#7DD3A8;font-weight:600">Delivered</span>'; }
            $paychip = ['stripe' => 'Stripe', 'square' => 'Square', 'demo' => 'Demo'][$o->processor] ?? esc_html($o->processor);
        ?>
            <tr>
                <td style="white-space:nowrap"><?php echo esc_html($o->when_ ? date_i18n('M j, Y', strtotime($o->when_)) : '—'); ?></td>
                <td><?php echo esc_html($o->email ?: '—'); ?><?php echo $o->ship_name ? '<br><span style="color:#777;font-size:12px">' . esc_html($o->ship_name) . '</span>' : ''; ?></td>
                <td style="max-width:280px"><?php echo esc_html(implode(', ', $item_str)); ?><?php echo $o->ship_addr ? '<br><span style="color:#888;font-size:12px;white-space:pre-line">' . esc_html($o->ship_addr) . '</span>' : ''; ?></td>
                <td style="white-space:nowrap"><?php echo esc_html(lmeg_orders_money($o->total, $cur)); ?><?php echo (int) $o->disc > 0 ? '<br><span style="color:#1a8a4a;font-size:12px">' . esc_html($o->code ? $o->code . ' ' : '') . '−' . esc_html(lmeg_orders_money($o->disc, $cur)) . '</span>' : ''; ?></td>
                <td><?php echo esc_html($paychip); ?></td>
                <td><?php echo $status; ?></td>
                <td>
                    <?php if ((int) $o->unshipped > 0) : ?>
                        <form method="post" action="<?php echo esc_url($save); ?>" style="display:flex;gap:5px;flex-wrap:wrap;align-items:center;margin-bottom:5px">
                            <?php wp_nonce_field('lmeg_ship_group', 'lmeg_shipg_nonce'); ?>
                            <input type="hidden" name="action" value="lmeg_ship_group">
                            <input type="hidden" name="okey" value="<?php echo esc_attr($o->okey); ?>">
                            <input type="hidden" name="q" value="<?php echo esc_attr($q); ?>"><input type="hidden" name="f" value="<?php echo esc_attr($f); ?>"><input type="hidden" name="paged" value="<?php echo (int) $paged; ?>">
                            <select name="carrier" style="max-width:110px"><option value="">Carrier…</option><?php foreach (['USPS','UPS','FedEx','Canada Post','DHL','Other'] as $cc) echo '<option>' . esc_html($cc) . '</option>'; ?></select>
                            <input type="text" name="tracking" placeholder="Tracking #" style="width:120px">
                            <button class="button button-small button-primary">Ship</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url($save); ?>" style="display:inline">
                        <?php wp_nonce_field('lmeg_resend_receipt', 'lmeg_resend_nonce'); ?>
                        <input type="hidden" name="action" value="lmeg_resend_receipt">
                        <input type="hidden" name="okey" value="<?php echo esc_attr($o->okey); ?>">
                        <input type="hidden" name="q" value="<?php echo esc_attr($q); ?>"><input type="hidden" name="f" value="<?php echo esc_attr($f); ?>"><input type="hidden" name="paged" value="<?php echo (int) $paged; ?>">
                        <button class="button button-small" title="Email the buyer their receipt &amp; download links again">Resend receipt</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php
    $pages = (int) ceil($total / $per);
    if ($pages > 1) {
        $base = add_query_arg(array_filter(['page' => 'lmeg-orders', 'f' => $f === 'all' ? false : $f, 'q' => $q ?: false]), admin_url('admin.php'));
        echo '<p style="margin-top:14px">';
        if ($paged > 1)      echo '<a class="button" href="' . esc_url(add_query_arg('paged', $paged - 1, $base)) . '">← Prev</a> ';
        echo '<span style="margin:0 8px">Page ' . (int) $paged . ' of ' . $pages . ' · ' . number_format_i18n($total) . ' orders</span>';
        if ($paged < $pages) echo ' <a class="button" href="' . esc_url(add_query_arg('paged', $paged + 1, $base)) . '">Next →</a>';
        echo '</p>';
    }
    echo '</div>';
}
