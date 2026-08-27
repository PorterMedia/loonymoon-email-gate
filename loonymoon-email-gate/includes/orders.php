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

/**
 * Mark a whole order refunded (or restore to paid). Reuses the status column —
 * refunded orders drop out of every status='paid' surface (KPIs, analytics,
 * downloads). Also syncs lmeg_shop_orders (removes/re-adds the revenue rows) so
 * refunds leave the wider dashboard totals too. Does NOT move money — the artist
 * refunds in Stripe/Square; this just records it.
 */
add_action('admin_post_lmeg_refund_order', 'lmeg_handle_refund_order');
function lmeg_handle_refund_order() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_refund_order', 'lmeg_refund_nonce');
    global $wpdb;
    $ptbl  = $wpdb->prefix . 'lmeg_product_purchases';
    $sotbl = $wpdb->prefix . 'lmeg_shop_orders';
    $okey  = sanitize_text_field(wp_unslash($_POST['okey'] ?? ''));
    $to    = (($_POST['to'] ?? 'refunded') === 'paid') ? 'paid' : 'refunded';
    if ($okey === '') { wp_safe_redirect(admin_url('admin.php?page=lmeg-orders')); exit; }
    $expr = lmeg_orders_okey_expr('');

    $rows = $wpdb->get_results($wpdb->prepare("SELECT id, product_id, email, amount_cents, currency FROM $ptbl WHERE $expr = %s AND status IN ('paid','refunded')", $okey));
    foreach ((array) $rows as $r) {
        $soid = 800000000000 + (int) $r->id;
        if ($to === 'refunded') {
            $wpdb->query($wpdb->prepare("DELETE FROM $sotbl WHERE shopify_order_id = %d", $soid));
        } else {
            $p = function_exists('lmeg_product_get') ? lmeg_product_get($r->product_id) : null;
            if ($p && $r->email && function_exists('lmeg_product_record_revenue')) {
                lmeg_product_record_revenue($p, (int) $r->id, $r->email, (int) $r->amount_cents, $r->currency);
            }
        }
    }
    $wpdb->query($wpdb->prepare("UPDATE $ptbl SET status = %s WHERE $expr = %s AND status IN ('paid','refunded')", $to, $okey));
    wp_safe_redirect(admin_url('admin.php?page=lmeg-orders&' . ($to === 'refunded' ? 'refunded' : 'unrefunded') . '=1' . lmeg_orders_keep_args())); exit;
}

/* ---------------------------------------------------------------------------
 * The Orders page
 * ------------------------------------------------------------------------- */
/* ---------------------------------------------------------------------------
 * Fulfilment paperwork: printable packing slips + a carrier-ready CSV.
 * ------------------------------------------------------------------------- */
add_action('admin_post_lmeg_packing_slip', 'lmeg_handle_packing_slip');
function lmeg_handle_packing_slip() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_packing_slip');
    global $wpdb;
    $ptbl = $wpdb->prefix . 'lmeg_product_purchases';
    $tbl  = $wpdb->prefix . 'lmeg_products';
    $expr = lmeg_orders_okey_expr('pp.');
    $okey = sanitize_text_field(wp_unslash($_GET['okey'] ?? ''));

    if ($okey !== '') {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT pp.*, pr.title, pr.type, pr.weight_g, $expr okey FROM $ptbl pp LEFT JOIN $tbl pr ON pr.id = pp.product_id WHERE pp.status='paid' AND $expr = %s ORDER BY pp.id ASC", $okey));
    } else {   // all orders still awaiting shipment
        $rows = $wpdb->get_results("SELECT pp.*, pr.title, pr.type, pr.weight_g, $expr okey FROM $ptbl pp LEFT JOIN $tbl pr ON pr.id = pp.product_id WHERE pp.status='paid' AND pp.fulfillment='unshipped' ORDER BY $expr, pp.id ASC");
    }
    $orders = [];
    foreach ((array) $rows as $r) $orders[$r->okey][] = $r;

    $artist = function_exists('lmeg_email_artist') ? lmeg_email_artist() : get_bloginfo('name');
    $ship_from = function_exists('lmeg_get_settings') ? trim((string) (lmeg_get_settings()['store_ship_from'] ?? '')) : '';
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html><head><meta charset="utf-8"><title>Packing slips</title><style>
      *{box-sizing:border-box} body{font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#111;margin:0;background:#f4f4f6}
      .bar{position:sticky;top:0;background:#fff;border-bottom:1px solid #ddd;padding:10px 16px;display:flex;gap:10px}
      .bar button{font-size:14px;padding:8px 16px;border-radius:8px;border:0;background:#E15FA8;color:#fff;font-weight:700;cursor:pointer}
      .wrap{max-width:720px;margin:0 auto;padding:18px}
      .slip{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:26px 28px;margin-bottom:18px}
      .slip h1{font-size:15px;letter-spacing:.14em;text-transform:uppercase;color:#888;margin:0 0 2px}
      .brand{font-weight:800;font-size:20px;margin-bottom:14px}
      .row{display:flex;justify-content:space-between;gap:20px;margin-bottom:16px;font-size:14px}
      .lbl{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#999;margin-bottom:3px}
      .ship{font-size:15px;line-height:1.5;white-space:pre-line}
      table{width:100%;border-collapse:collapse;margin-top:6px}
      th,td{text-align:left;padding:9px 6px;border-bottom:1px solid #eee;font-size:14px}
      th{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#999}
      td.q{width:44px;font-weight:700}
      .thanks{margin-top:18px;color:#666;font-size:13px}
      @media print{.bar{display:none}body{background:#fff}.slip{border:0;border-radius:0;padding:0 0 20px;margin:0;page-break-after:always}.wrap{padding:0}}
    </style></head><body>
    <div class="bar"><button onclick="window.print()">🖨 Print</button><span style="align-self:center;color:#666;font-size:13px"><?php echo count($orders); ?> packing slip<?php echo count($orders) === 1 ? '' : 's'; ?></span></div>
    <div class="wrap">
    <?php if (!$orders) : ?><div class="slip"><p>No orders to print.</p></div><?php endif; ?>
    <?php foreach ($orders as $key => $lines) :
        $first = $lines[0]; ?>
        <div class="slip">
            <h1>Packing Slip</h1>
            <div class="brand"><?php echo esc_html($artist); ?></div>
            <?php if ($ship_from) : ?><div style="color:#777;font-size:12px;white-space:pre-line;margin-bottom:12px"><?php echo esc_html($ship_from); ?></div><?php endif; ?>
            <div class="row">
                <div><div class="lbl">Order</div><?php echo esc_html('#' . substr((string) $key, -8)); ?></div>
                <div><div class="lbl">Date</div><?php echo esc_html($first->paid_at ? date_i18n('M j, Y', strtotime($first->paid_at)) : '—'); ?></div>
            </div>
            <div class="row"><div><div class="lbl">Ship to</div><div class="ship"><?php echo esc_html(($first->ship_name ? $first->ship_name . "\n" : '') . ($first->ship_address ?: '')); ?></div><?php echo $first->email ? '<div style="color:#888;font-size:13px;margin-top:4px">' . esc_html($first->email) . '</div>' : ''; ?></div></div>
            <table><thead><tr><th class="q">Qty</th><th>Item</th></tr></thead><tbody>
            <?php foreach ($lines as $ln) : ?>
                <tr><td class="q"><?php echo (int) ($ln->qty ?: 1); ?></td><td><?php echo esc_html($ln->title . ($ln->variant ? ' · ' . $ln->variant : '')); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php $wt = 0; foreach ($lines as $ln) $wt += (int) ($ln->weight_g ?? 0) * (int) ($ln->qty ?: 1); if ($wt > 0) : ?><div style="margin-top:8px;color:#666;font-size:13px">Total weight: <?php echo esc_html(number_format($wt)); ?> g</div><?php endif; ?>
            <div class="thanks">Thanks for supporting <?php echo esc_html($artist); ?> 💜</div>
        </div>
    <?php endforeach; ?>
    </div></body></html><?php
    exit;
}

add_action('admin_post_lmeg_export_shipping', 'lmeg_handle_export_shipping');
function lmeg_handle_export_shipping() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_export_shipping');
    global $wpdb;
    $ptbl = $wpdb->prefix . 'lmeg_product_purchases';
    $tbl  = $wpdb->prefix . 'lmeg_products';
    $expr = lmeg_orders_okey_expr('pp.');
    $rows = $wpdb->get_results("SELECT pp.*, pr.title, pr.weight_g, $expr okey FROM $ptbl pp LEFT JOIN $tbl pr ON pr.id = pp.product_id WHERE pp.status='paid' AND pp.fulfillment='unshipped' ORDER BY $expr, pp.id ASC");

    $orders = [];   // okey => ['name','email','addr','items'[],'weight']
    foreach ((array) $rows as $r) {
        if (!isset($orders[$r->okey])) $orders[$r->okey] = ['name' => $r->ship_name, 'email' => $r->email, 'addr' => $r->ship_address, 'items' => [], 'weight' => 0];
        $orders[$r->okey]['items'][]  = ((int) ($r->qty ?: 1) > 1 ? (int) $r->qty . '× ' : '') . $r->title . ($r->variant ? ' (' . $r->variant . ')' : '');
        $orders[$r->okey]['weight']  += (int) ($r->weight_g ?? 0) * (int) ($r->qty ?: 1);
    }
    $safe = function ($v) { $v = (string) $v; return ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) ? "'" . $v : $v; };

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fanloop-to-ship-' . gmdate('Y-m-d') . '.csv"');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, ['Order', 'Name', 'Email', 'Address', 'Items', 'Weight (g)']);
    foreach ($orders as $key => $o) {
        fputcsv($fh, [
            '#' . substr((string) $key, -8),
            $safe($o['name']),
            $safe($o['email']),
            $safe(str_replace("\n", ', ', (string) $o['addr'])),
            $safe(implode('; ', $o['items'])),
            (int) $o['weight'] ?: '',
        ]);
    }
    fclose($fh);
    exit;
}

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

    // Line-level WHERE + order-level HAVING. Include refunded so they stay
    // visible (and reversible); refunds are excluded from revenue/KPIs below.
    $where = ["pp.status IN ('paid','refunded')"]; $having = []; $hargs = [];
    if ($f === 'demo')     $where[] = "pp.processor='demo'";
    if ($f === 'refunded') $having[] = "MAX(pp.status) = 'refunded'";
    if ($f !== 'refunded' && $f !== 'all') $having[] = "MAX(pp.status) = 'paid'";
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
                MAX(pp.status) ostatus,
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
            "SELECT pp.*, pr.title, pr.type, $OK okey FROM $ptbl pp LEFT JOIN $tbl pr ON pr.id=pp.product_id WHERE pp.status IN ('paid','refunded') AND $OK IN ($ph) ORDER BY pp.id ASC", $keys));
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
    if (isset($_GET['refunded'])) echo '<div class="notice notice-success is-dismissible"><p>Order marked refunded and removed from revenue. Remember to refund the money in your Stripe/Square dashboard.</p></div>';
    if (isset($_GET['unrefunded'])) echo '<div class="notice notice-success is-dismissible"><p>Order restored to paid.</p></div>';
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
        <?php $pill('all', 'All'); $pill('toship', 'To ship'); $pill('digital', 'Digital'); $pill('demo', 'Demo'); $pill('refunded', 'Refunded'); ?>
        &nbsp; <input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Search email or product…" style="min-width:220px">
        <?php if ($f !== 'all') : ?><input type="hidden" name="f" value="<?php echo esc_attr($f); ?>"><?php endif; ?>
        <button class="button">Search</button>
        <?php if ($q !== '') : ?><a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'lmeg-orders', 'f' => $f], admin_url('admin.php'))); ?>">Clear</a><?php endif; ?>
    </form>

    <?php if ($toship_all > 0) : ?>
    <p style="margin:0 0 12px">
        <a class="button" target="_blank" rel="noopener" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lmeg_packing_slip'), 'lmeg_packing_slip')); ?>">🖨 Print packing slips (<?php echo (int) $toship_all; ?>)</a>
        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lmeg_export_shipping'), 'lmeg_export_shipping')); ?>">⬇ Export to-ship (CSV)</a>
        <span class="description" style="margin-left:6px">Print slips for the box, or take the CSV to Pirate Ship / Shippo / Canada Post to buy labels — then paste tracking back in below.</span>
    </p>
    <?php endif; ?>

    <table class="widefat striped">
        <thead><tr><th>When</th><th>Buyer</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$orders) : ?><tr><td colspan="7">No orders<?php echo $q !== '' || $f !== 'all' ? ' match that filter.' : ' yet.'; ?></td></tr>
        <?php else : foreach ($orders as $o) :
            $mylines = $lines_by[$o->okey] ?? [];
            $item_str = [];
            foreach ($mylines as $ln) $item_str[] = $ln->title . ($ln->variant ? ' · ' . $ln->variant : '') . ((int) ($ln->qty ?: 1) > 1 ? ' ×' . (int) $ln->qty : '');
            $cur = $o->cur ?: 'USD';
            $refunded = (($o->ostatus ?? 'paid') === 'refunded');
            $digital_only = ((int) $o->physicals === 0);
            if ($refunded)                    { $status = '<span style="color:#9A9DB0;font-weight:600">↩ Refunded</span>'; }
            elseif ((int) $o->unshipped > 0)  { $status = '<span style="color:#E7C97D;font-weight:600">To ship</span>'; }
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
                    <?php $keep = '<input type="hidden" name="okey" value="' . esc_attr($o->okey) . '"><input type="hidden" name="q" value="' . esc_attr($q) . '"><input type="hidden" name="f" value="' . esc_attr($f) . '"><input type="hidden" name="paged" value="' . (int) $paged . '">'; ?>
                    <?php if (!$refunded) : ?>
                        <?php if ((int) $o->unshipped > 0) : ?>
                        <form method="post" action="<?php echo esc_url($save); ?>" style="display:flex;gap:5px;flex-wrap:wrap;align-items:center;margin-bottom:5px">
                            <?php wp_nonce_field('lmeg_ship_group', 'lmeg_shipg_nonce'); ?>
                            <input type="hidden" name="action" value="lmeg_ship_group"><?php echo $keep; ?>
                            <select name="carrier" style="max-width:110px"><option value="">Carrier…</option><?php foreach (['USPS','UPS','FedEx','Canada Post','DHL','Other'] as $cc) echo '<option>' . esc_html($cc) . '</option>'; ?></select>
                            <input type="text" name="tracking" placeholder="Tracking #" style="width:120px">
                            <button class="button button-small button-primary">Ship</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url($save); ?>" style="display:inline">
                            <?php wp_nonce_field('lmeg_resend_receipt', 'lmeg_resend_nonce'); ?>
                            <input type="hidden" name="action" value="lmeg_resend_receipt"><?php echo $keep; ?>
                            <button class="button button-small" title="Email the buyer their receipt &amp; download links again">Resend receipt</button>
                        </form>
                        <?php if ((int) $o->physicals > 0) : ?><a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'lmeg_packing_slip', 'okey' => $o->okey], admin_url('admin-post.php')), 'lmeg_packing_slip')); ?>" title="Printable packing slip">Slip</a><?php endif; ?>
                        <form method="post" action="<?php echo esc_url($save); ?>" style="display:inline" onsubmit="return confirm('Mark this order refunded? It leaves your revenue and reports. This does NOT move money — refund it in Stripe/Square yourself.');">
                            <?php wp_nonce_field('lmeg_refund_order', 'lmeg_refund_nonce'); ?>
                            <input type="hidden" name="action" value="lmeg_refund_order"><input type="hidden" name="to" value="refunded"><?php echo $keep; ?>
                            <button class="button button-small link-delete">Refund</button>
                        </form>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url($save); ?>" style="display:inline" onsubmit="return confirm('Restore this order to paid?');">
                            <?php wp_nonce_field('lmeg_refund_order', 'lmeg_refund_nonce'); ?>
                            <input type="hidden" name="action" value="lmeg_refund_order"><input type="hidden" name="to" value="paid"><?php echo $keep; ?>
                            <button class="button button-small">Un-refund</button>
                        </form>
                    <?php endif; ?>
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
