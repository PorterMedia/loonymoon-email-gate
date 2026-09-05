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

/** All active bundles, newest first. Cached per-request (cards call it a lot). */
function lmeg_bundles_active() {
    static $cache = null;
    if ($cache !== null) return $cache;
    global $wpdb;
    $cache = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lmeg_bundles WHERE active = 1 ORDER BY id DESC");
    return $cache;
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

/**
 * If a product can be added to the cart in one tap (no size to pick, not
 * pay-what-you-want, active and in stock), return its client cart-item payload;
 * otherwise null. Used to decide whether "Add the set" can one-tap the whole
 * bundle.
 */
function lmeg_bundle_quick_item($q) {
    if (!$q || ($q->status ?? '') !== 'active') return null;
    if (function_exists('lmeg_product_is_pwyw') && lmeg_product_is_pwyw($q)) return null;
    $vlist = function_exists('lmeg_product_variants') ? lmeg_product_variants($q) : [];
    if ($vlist) return null;
    if (function_exists('lmeg_product_is_available') && !lmeg_product_is_available($q)) return null;
    $physical = (($q->type ?? '') === 'physical');
    return [
        'id'    => (int) $q->id,
        'slug'  => (string) ($q->slug ?? ''),
        'title' => (string) ($q->title ?? ''),
        'cover' => (string) ($q->cover_url ?? ''),
        'unit'  => (int) $q->price_cents,
        'cur'   => strtoupper($q->currency ?: 'USD'),
        'variant' => '',
        'qty'   => 1,
        'type'  => (string) ($q->type ?? 'digital'),
        'ship'  => $physical ? (int) $q->shipping_cents : 0,
        'pwyw'  => false,
    ];
}

/**
 * "Buy the set & save X%" widget for a product page (dark chrome). Shows the
 * best bundle this product belongs to, its products as tiles, and — when every
 * product is one-tap addable — an "Add the set to cart" button that drops the
 * whole set in the cart so the bundle discount kicks in at checkout. Returns ''
 * when the product isn't in a usable bundle.
 */
function lmeg_bundle_widget_html($p) {
    if (!$p) return '';
    $bundles = lmeg_bundles_for_product($p->id);
    if (!$bundles) return '';
    usort($bundles, function ($a, $b) { return (int) $b->pct - (int) $a->pct; });   // biggest saving first
    $b   = $bundles[0];
    $pct = max(1, min(90, (int) $b->pct));

    // Load the set's products (active only).
    $prods = [];
    foreach (lmeg_bundle_ids($b) as $id) {
        $q = function_exists('lmeg_product_get') ? lmeg_product_get($id) : null;
        if ($q && $q->status === 'active') $prods[] = $q;
    }
    if (count($prods) < 2) return '';

    $fmt = function ($c, $cur) { return function_exists('lmeg_format_price') ? lmeg_format_price((int) $c, $cur ?: 'USD') : '$' . number_format($c / 100, 2); };

    // One-tap payloads + a fixed-price total (for a concrete "save $X" line).
    $items = []; $all_quick = true; $base = 0; $all_fixed = true; $cur = 'USD';
    foreach ($prods as $q) {
        $cur = strtoupper($q->currency ?: 'USD');
        $it = lmeg_bundle_quick_item($q);
        if ($it === null) $all_quick = false; else $items[] = $it;
        if (function_exists('lmeg_product_is_pwyw') && lmeg_product_is_pwyw($q)) $all_fixed = false;
        else $base += (int) $q->price_cents;
    }
    $can_addall = $all_quick && count($items) === count($prods);
    $save_cents = $all_fixed ? (int) floor($base * $pct / 100) : 0;

    // Product tiles.
    $tiles = '';
    foreach ($prods as $q) {
        $is_this = ((int) $q->id === (int) $p->id);
        $price   = lmeg_product_is_pwyw($q) ? 'Name your price' : $fmt($q->price_cents, $q->currency);
        $img = !empty($q->cover_url)
            ? '<img src="' . esc_url($q->cover_url) . '" alt="" style="width:100%;aspect-ratio:1/1;object-fit:cover;display:block">'
            : '<div style="width:100%;aspect-ratio:1/1;background:#20222E"></div>';
        $inner = $img
            . '<div style="padding:8px 10px">'
            . '<div style="color:#F4F2F7;font-weight:650;font-size:13px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . esc_html($q->title) . '</div>'
            . '<div style="color:#E7A6CF;font-size:12px;font-weight:700;margin-top:2px">' . esc_html($price) . '</div></div>';
        $ring = $is_this ? 'outline:2px solid #E15FA8;outline-offset:-2px;' : '';
        $tiles .= $is_this
            ? '<div style="' . $ring . 'background:#12141f;border:1px solid rgba(255,255,255,.08);border-radius:12px;overflow:hidden">' . $inner . '</div>'
            : '<a href="' . esc_url(lmeg_product_url($q)) . '" style="text-decoration:none;display:block;background:#12141f;border:1px solid rgba(255,255,255,.08);border-radius:12px;overflow:hidden">' . $inner . '</a>';
    }

    $head_save = $save_cents > 0
        ? 'Buy the set &amp; save ' . esc_html($fmt($save_cents, $cur)) . ' <span style="color:#8B90A0;font-weight:600">(' . $pct . '% off)</span>'
        : 'Buy the set &amp; save ' . $pct . '%';

    $cta = '';
    if ($can_addall) {
        $cta = '<button type="button" class="flp-add-set" data-items="' . esc_attr(wp_json_encode($items)) . '" '
             . 'style="margin-top:14px;width:100%;background:linear-gradient(118deg,#E15FA8,#8A6CF6);color:#0B0C12;font-weight:800;border:0;padding:13px;border-radius:11px;font-size:15px;cursor:pointer">'
             . '🛒 Add the set to cart</button>'
             . '<div style="margin-top:7px;font-size:12px;color:#8B90A0;text-align:center">The ' . $pct . '% comes off automatically at checkout.</div>';
    } else {
        $cta = '<div style="margin-top:12px;font-size:13px;color:#B9BCC9;text-align:center">Add each item to your cart and the ' . $pct . '% comes off automatically at checkout.</div>';
    }

    return '<div style="width:100%;max-width:720px;margin:26px auto 0;background:linear-gradient(160deg,#1a1526,#141019);border:1px solid rgba(225,95,168,.28);border-radius:16px;padding:20px 22px">'
        . '<div style="text-align:center;font-weight:800;font-size:17px;color:#fff;margin-bottom:3px">🎁 ' . $head_save . '</div>'
        . '<div style="text-align:center;color:#9AA0B4;font-size:13px;margin-bottom:16px">' . esc_html($b->title) . '</div>'
        . '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px">' . $tiles . '</div>'
        . $cta . '</div>';
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
    $back = admin_url('admin.php?page=lmeg-store-promos#bundles');

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
    if ($title === '' || count($pids) < 2) { wp_safe_redirect(admin_url('admin.php?page=lmeg-store-promos&bundle_err=1#bundles')); exit; }

    $wpdb->insert($tbl, [
        'title'       => $title,
        'product_ids' => implode(',', array_slice($pids, 0, 30)),
        'pct'         => $pct,
        'active'      => 1,
        'created_at'  => current_time('mysql'),
    ]);
    wp_safe_redirect(admin_url('admin.php?page=lmeg-store-promos&bundle_saved=1#bundles')); exit;
}

add_action('admin_post_lmeg_delete_bundle', 'lmeg_handle_delete_bundle');
function lmeg_handle_delete_bundle() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_delete_bundle', 'lmeg_bundle_del_nonce');
    global $wpdb;
    $id = (int) ($_POST['bundle_id'] ?? 0);
    if ($id) $wpdb->delete($wpdb->prefix . 'lmeg_bundles', ['id' => $id]);
    wp_safe_redirect(admin_url('admin.php?page=lmeg-store-promos#bundles')); exit;
}
