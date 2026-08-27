<?php
if (!defined('ABSPATH')) exit;

/* ============================================================================
 * Fanloop Store — "Buy the set" bundles  (BETA)
 * ----------------------------------------------------------------------------
 * A bundle names a set of products and a % off. When a fan has EVERY product
 * of an active bundle in their cart, the discount applies automatically at
 * checkout — computed server-side from the validated cart, so it can't be
 * spoofed. Only one order discount applies: a manually-entered code wins; the
 * bundle fills in when no code is used. Table: lmeg_bundles.
 * ========================================================================== */

/** All active bundles, newest first. */
function lmeg_bundles_active() {
    global $wpdb;
    return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lmeg_bundles WHERE active = 1 ORDER BY id DESC");
}

/** A bundle row's product ids → clean int list. */
function lmeg_bundle_ids($b) {
    $raw = is_object($b) ? ($b->product_ids ?? '') : (string) $b;
    $out = [];
    foreach (explode(',', (string) $raw) as $x) {
        $id = (int) trim($x);
        if ($id > 0 && !in_array($id, $out, true)) $out[] = $id;
    }
    return $out;
}

/**
 * Best qualifying bundle discount for a validated cart, or null.
 * A bundle qualifies when every one of its products is present in the cart.
 * The discount is `pct`% of the matched products' line totals (unit×qty, before
 * shipping), clamped to the subtotal. Returns
 * ['id','title','pct','amount_off','product_ids'].
 */
function lmeg_cart_bundle_match($v) {
    if (empty($v['lines']) || !empty($v['mixed'])) return null;   // mixed currency → skip

    // Map product id → this cart's line total for it (sum across lines, e.g. variants).
    $line_total = [];
    foreach ($v['lines'] as $ln) {
        $pid = (int) $ln['p']->id;
        $line_total[$pid] = ($line_total[$pid] ?? 0) + (int) $ln['unit'] * (int) $ln['qty'];
    }
    $in_cart = array_keys($line_total);

    $best = null;
    foreach ((array) lmeg_bundles_active() as $b) {
        $ids = lmeg_bundle_ids($b);
        if (count($ids) < 2) continue;                       // a bundle needs ≥2 products
        if (array_diff($ids, $in_cart)) continue;            // not every bundle product is in the cart
        $pct  = max(1, min(90, (int) $b->pct));
        $base = 0; foreach ($ids as $pid) $base += (int) ($line_total[$pid] ?? 0);
        $off  = (int) floor($base * $pct / 100);
        $off  = max(0, min($off, (int) $v['subtotal']));
        if ($off <= 0) continue;
        if (!$best || $off > $best['amount_off']) {
            $best = ['id' => (int) $b->id, 'title' => (string) $b->title, 'pct' => $pct,
                     'amount_off' => $off, 'product_ids' => $ids];
        }
    }
    return $best;
}

/** Bundles that include a given product id (for storefront "buy the set" hints). */
function lmeg_bundles_for_product($product_id) {
    $product_id = (int) $product_id;
    $out = [];
    foreach ((array) lmeg_bundles_active() as $b) {
        if (in_array($product_id, lmeg_bundle_ids($b), true)) $out[] = $b;
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * Admin — a "Bundles" section on the Store page (create / toggle / delete).
 * ------------------------------------------------------------------------- */
function lmeg_bundles_admin_section() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $btbl = $wpdb->prefix . 'lmeg_bundles';
    $ptbl = $wpdb->prefix . 'lmeg_products';
    $bundles  = $wpdb->get_results("SELECT * FROM $btbl ORDER BY id DESC");
    $products = $wpdb->get_results("SELECT id, title, price_cents, currency, status FROM $ptbl ORDER BY status ASC, id DESC LIMIT 200");
    $pmap = [];
    foreach ((array) $products as $p) $pmap[(int) $p->id] = $p;
    $save = admin_url('admin-post.php');
    $fmt  = function ($c, $cur) { return function_exists('lmeg_format_price') ? lmeg_format_price((int) $c, $cur ?: 'USD') : '$' . number_format($c / 100, 2); };
    ?>
    <h2 id="bundles" style="margin-top:30px">Bundles <span style="font-size:12px;color:#8B90A0;font-weight:400">· buy the set &amp; save</span></h2>
    <p class="description" style="margin:0 0 12px;max-width:820px">Group products into a set with a discount. When a fan has <strong>every</strong> product in the set in their cart, the % comes off automatically at checkout — no code to type. A discount code they enter themselves takes priority over a bundle.</p>

    <?php if ($bundles) : ?>
    <table class="widefat striped" style="max-width:820px;margin-bottom:14px">
        <thead><tr><th>Bundle</th><th>Products</th><th>Discount</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($bundles as $b) :
            $ids = lmeg_bundle_ids($b);
            $names = [];
            foreach ($ids as $pid) $names[] = isset($pmap[$pid]) ? $pmap[$pid]->title : ('#' . $pid);
        ?>
            <tr>
                <td><strong><?php echo esc_html($b->title); ?></strong></td>
                <td style="font-size:13px;color:#555"><?php echo esc_html(implode(' + ', $names)); ?></td>
                <td><?php echo (int) $b->pct; ?>% off</td>
                <td><?php echo $b->active ? '<span style="color:#047857;font-weight:600">Active</span>' : '<span style="color:#9A9DB0">Off</span>'; ?></td>
                <td>
                    <form method="post" action="<?php echo esc_url($save); ?>" style="display:inline">
                        <?php wp_nonce_field('lmeg_save_bundle', 'lmeg_bundle_nonce'); ?>
                        <input type="hidden" name="action" value="lmeg_save_bundle">
                        <input type="hidden" name="do" value="toggle">
                        <input type="hidden" name="bundle_id" value="<?php echo (int) $b->id; ?>">
                        <button class="button button-small"><?php echo $b->active ? 'Turn off' : 'Turn on'; ?></button>
                    </form>
                    <form method="post" action="<?php echo esc_url($save); ?>" style="display:inline" onsubmit="return confirm('Delete this bundle? Products are not affected.');">
                        <?php wp_nonce_field('lmeg_delete_bundle', 'lmeg_bundle_del_nonce'); ?>
                        <input type="hidden" name="action" value="lmeg_delete_bundle">
                        <input type="hidden" name="bundle_id" value="<?php echo (int) $b->id; ?>">
                        <button class="button button-small link-delete">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (count($products) < 2) : ?>
        <p class="description">Add at least two products first, then you can group them into a bundle.</p>
    <?php else : ?>
    <form method="post" action="<?php echo esc_url($save); ?>" style="max-width:820px;background:#fff;border:1px solid #e2e4ea;border-radius:10px;padding:16px 18px">
        <?php wp_nonce_field('lmeg_save_bundle', 'lmeg_bundle_nonce'); ?>
        <input type="hidden" name="action" value="lmeg_save_bundle">
        <input type="hidden" name="do" value="create">
        <strong style="display:block;margin-bottom:10px">New bundle</strong>
        <p style="margin:0 0 10px">
            <label>Name <input type="text" name="title" required maxlength="120" placeholder="e.g. Vinyl + Tee set" style="width:260px"></label>
            &nbsp; <label>Discount <input type="number" name="pct" min="1" max="90" value="10" style="width:70px"> %</label>
        </p>
        <p style="margin:0 0 6px;font-weight:600">Products in this set <span style="font-weight:400;color:#888">(pick 2 or more)</span></p>
        <div style="max-height:210px;overflow:auto;border:1px solid #eee;border-radius:8px;padding:8px 10px;columns:2;column-gap:22px">
            <?php foreach ($products as $p) : ?>
                <label style="display:block;break-inside:avoid;padding:3px 0;font-size:13px">
                    <input type="checkbox" name="pids[]" value="<?php echo (int) $p->id; ?>">
                    <?php echo esc_html($p->title); ?>
                    <span style="color:#999"><?php echo esc_html($fmt($p->price_cents, $p->currency)); ?><?php echo $p->status !== 'active' ? ' · ' . esc_html($p->status) : ''; ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <p style="margin:12px 0 0"><button class="button button-primary">Create bundle</button></p>
    </form>
    <?php endif;
}

add_action('admin_post_lmeg_save_bundle', 'lmeg_handle_save_bundle');
function lmeg_handle_save_bundle() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_save_bundle', 'lmeg_bundle_nonce');
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_bundles';
    $do  = sanitize_key($_POST['do'] ?? 'create');
    $back = admin_url('admin.php?page=lmeg-products#bundles');

    if ($do === 'toggle') {
        $id = (int) ($_POST['bundle_id'] ?? 0);
        if ($id) $wpdb->query($wpdb->prepare("UPDATE $tbl SET active = 1 - active WHERE id = %d", $id));
        wp_safe_redirect($back); exit;
    }

    // create
    $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $pct   = max(1, min(90, (int) ($_POST['pct'] ?? 10)));
    $pids  = [];
    foreach ((array) ($_POST['pids'] ?? []) as $x) { $id = (int) $x; if ($id > 0 && !in_array($id, $pids, true)) $pids[] = $id; }
    if ($title === '' || count($pids) < 2) { wp_safe_redirect(admin_url('admin.php?page=lmeg-products&bundle_err=1#bundles')); exit; }

    $wpdb->insert($tbl, [
        'title'       => $title,
        'product_ids' => implode(',', array_slice($pids, 0, 30)),
        'pct'         => $pct,
        'active'      => 1,
        'created_at'  => current_time('mysql'),
    ]);
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products&bundle_saved=1#bundles')); exit;
}

add_action('admin_post_lmeg_delete_bundle', 'lmeg_handle_delete_bundle');
function lmeg_handle_delete_bundle() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_delete_bundle', 'lmeg_bundle_del_nonce');
    global $wpdb;
    $id = (int) ($_POST['bundle_id'] ?? 0);
    if ($id) $wpdb->delete($wpdb->prefix . 'lmeg_bundles', ['id' => $id]);
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products#bundles')); exit;
}
