<?php
if (!defined('ABSPATH')) exit;

/* ============================================================================
 * Fanloop Store — native discount / promo codes  (BETA)
 * ----------------------------------------------------------------------------
 * Codes the artist creates (percent-off or fixed-amount) that fans enter on the
 * cart checkout page. Works in demo AND live checkout: the discount is applied
 * to the item subtotal (not shipping), distributed proportionally across the
 * order's lines so per-line revenue stays exact, and the code's use count ticks
 * up once per completed order. Table: lmeg_discounts.
 * ========================================================================== */

function lmeg_discount_norm($code) {
    return strtoupper(preg_replace('/\s+/', '', (string) $code));
}

function lmeg_discount_get($code) {
    global $wpdb;
    $c = lmeg_discount_norm($code);
    return $c ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lmeg_discounts WHERE code = %s", $c)) : null;
}

/**
 * Validate a code against an order subtotal (cents) + currency.
 * Returns ['ok'=>true,'discount'=>row,'amount_off'=>cents,'code'=>CODE] or
 * ['ok'=>false,'error'=>message].
 */
function lmeg_discount_validate($code, $subtotal_cents, $currency) {
    $money = function ($c) use ($currency) { return function_exists('lmeg_cart_money') ? lmeg_cart_money($c, $currency) : ('$' . number_format($c / 100, 2)); };
    $d = lmeg_discount_get($code);
    if (!$d)                        return ['ok' => false, 'error' => 'That code isn’t valid.'];
    if ($d->status !== 'active')    return ['ok' => false, 'error' => 'That code is no longer active.'];
    if ($d->expires_at && strtotime($d->expires_at) < current_time('timestamp')) return ['ok' => false, 'error' => 'That code has expired.'];
    if ((int) $d->max_uses > 0 && (int) $d->used >= (int) $d->max_uses)           return ['ok' => false, 'error' => 'That code has reached its limit.'];
    if ((int) $d->min_subtotal_cents > 0 && $subtotal_cents < (int) $d->min_subtotal_cents) {
        return ['ok' => false, 'error' => 'This code needs a minimum of ' . $money((int) $d->min_subtotal_cents) . '.'];
    }
    if ($d->kind === 'amount' && strtoupper($d->currency) !== strtoupper($currency)) {
        return ['ok' => false, 'error' => 'That code can’t be used in this currency.'];
    }
    $off = ($d->kind === 'percent')
        ? (int) round($subtotal_cents * min(100, max(1, (int) $d->value)) / 100)
        : min((int) $d->value, $subtotal_cents);
    $off = max(0, min($off, $subtotal_cents));
    return ['ok' => true, 'discount' => $d, 'amount_off' => $off, 'code' => $d->code, 'kind' => $d->kind, 'value' => (int) $d->value];
}

/**
 * Distribute an order-level discount across lines by item subtotal.
 * Each line's share is capped at that line's own value so the discount can never
 * exceed a line — the checkout deducts it as max(0, line - share), so an over-cap
 * share would be clamped away and silently overcharge the customer. Any rounding
 * remainder is redistributed to lines that still have room; the shares always sum
 * to $off (given $off <= $items_total) and no share is negative or over its value.
 */
function lmeg_discount_split($lines, $off, $items_total) {
    $n   = count($lines);
    $out = array_fill(0, $n, 0);
    if ($off <= 0 || $items_total <= 0 || $n === 0) return $out;

    // First pass: proportional floor, capped at each line's own value and at what's left.
    $rem = (int) $off;
    $val = [];
    foreach ($lines as $i => $ln) {
        $si = (int) $ln['unit'] * (int) $ln['qty'];
        if ($si < 0) $si = 0;
        $val[$i] = $si;
        $share = (int) floor($off * $si / $items_total);
        if ($share > $si)  $share = $si;
        if ($share > $rem) $share = $rem;
        $out[$i] = $share;
        $rem    -= $share;
    }

    // Redistribute the leftover cents to lines that still have capacity (round-robin).
    $guard = 0;
    while ($rem > 0 && $guard < $n * ($rem + 2)) {
        $moved = false;
        foreach ($val as $i => $si) {
            if ($rem <= 0) break;
            if ($out[$i] < $si) { $out[$i]++; $rem--; $moved = true; }
        }
        if (!$moved) break;   // no capacity anywhere (off exceeded total) — leave the rest unassigned
        $guard++;
    }
    return $out;
}

function lmeg_discount_increment($code) {
    global $wpdb;
    $c = lmeg_discount_norm($code);
    if ($c) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}lmeg_discounts SET used = used + 1 WHERE code = %s", $c));
}

/** Short human label like "20% off" / "$5 off". */
function lmeg_discount_desc($d, $currency = null) {
    if ($d->kind === 'percent') return ((int) $d->value) . '% off';
    $cur = $currency ?: ($d->currency ?: 'USD');
    return (function_exists('lmeg_cart_money') ? lmeg_cart_money((int) $d->value, $cur) : ('$' . number_format($d->value / 100, 2))) . ' off';
}

/* ---------------------------------------------------------------------------
 * Admin — save / delete handlers
 * ------------------------------------------------------------------------- */
add_action('admin_post_lmeg_save_discount', 'lmeg_handle_save_discount');
function lmeg_handle_save_discount() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_save_discount', 'lmeg_discount_nonce');
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_discounts';

    $code = lmeg_discount_norm($_POST['code'] ?? '');
    if ($code === '') { wp_safe_redirect(admin_url('admin.php?page=lmeg-products&derr=code#discounts')); exit; }
    $kind = ($_POST['kind'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
    $to_cents = function ($v) { return (int) round(((float) preg_replace('/[^0-9.]/', '', (string) $v)) * 100); };
    $value = ($kind === 'percent') ? max(1, min(100, (int) $_POST['value'])) : max(0, $to_cents($_POST['value'] ?? 0));
    $data = [
        'code'               => $code,
        'kind'               => $kind,
        'value'              => $value,
        'currency'           => strtoupper(substr(sanitize_text_field($_POST['currency'] ?? 'USD'), 0, 3)) ?: 'USD',
        'min_subtotal_cents' => max(0, $to_cents($_POST['min_subtotal'] ?? 0)),
        'max_uses'           => max(0, (int) ($_POST['max_uses'] ?? 0)),
        'expires_at'         => !empty($_POST['expires_at']) ? (sanitize_text_field($_POST['expires_at']) . ' 23:59:59') : null,
        'status'             => (($_POST['status'] ?? 'active') === 'disabled') ? 'disabled' : 'active',
    ];
    $existing = lmeg_discount_get($code);
    if ($existing) {
        $wpdb->update($tbl, $data, ['id' => (int) $existing->id]);
    } else {
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($tbl, $data);
    }
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products&dsaved=1#discounts')); exit;
}

add_action('admin_post_lmeg_delete_discount', 'lmeg_handle_delete_discount');
function lmeg_handle_delete_discount() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_delete_discount', 'lmeg_discount_del_nonce');
    global $wpdb;
    $id = (int) ($_POST['discount_id'] ?? 0);
    if ($id) $wpdb->delete($wpdb->prefix . 'lmeg_discounts', ['id' => $id]);
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products&ddeleted=1#discounts')); exit;
}

/* ---------------------------------------------------------------------------
 * Admin — the "Discount codes" section, embedded on the Store page.
 * ------------------------------------------------------------------------- */
/**
 * "N / M used" cell with a usage progress bar (admin light surface). Bar goes
 * green → amber (≥70%) → red (maxed). With no limit, just shows the count.
 */
function lmeg_discount_usage_html($used, $max) {
    $used = max(0, (int) $used); $max = (int) $max;
    if ($max <= 0) {
        return '<span style="font-size:13px;color:#17141f">' . $used . '</span> <span style="color:#9A9DB0;font-size:11px">used</span>';
    }
    $pct = (int) round(min(100, $used / $max * 100));
    $maxed = ($used >= $max);
    $col = $maxed ? '#DC2626' : ($pct >= 70 ? '#D97706' : '#059669');
    $bg  = $maxed ? '#FEE2E2' : ($pct >= 70 ? '#FEF3C7' : '#ECFDF5');
    return '<div style="min-width:92px">'
        . '<div style="font-size:13px;color:#17141f;margin-bottom:4px">' . $used . ' <span style="color:#6b6b78">/ ' . $max . '</span>'
        . ($maxed ? ' <span style="color:#DC2626;font-weight:700;font-size:11px">maxed</span>' : '') . '</div>'
        . '<div style="height:5px;border-radius:999px;background:' . $bg . ';overflow:hidden"><div style="height:100%;width:' . $pct . '%;background:' . $col . ';border-radius:999px"></div></div>'
        . '</div>';
}

function lmeg_discounts_admin_section() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lmeg_discounts ORDER BY id DESC");
    $save = admin_url('admin-post.php');

    // Per-code performance, derived from paid purchases tagged with the code.
    $ptbl  = $wpdb->prefix . 'lmeg_product_purchases';
    $perf  = [];
    foreach ((array) $wpdb->get_results("SELECT discount_code code, SUM(amount_cents) rev, SUM(discount_cents) given, MAX(currency) cur FROM $ptbl WHERE status = 'paid' AND discount_code IS NOT NULL AND discount_code <> '' GROUP BY discount_code") as $r) {
        $perf[$r->code] = $r;
    }
    ?>
    <h2 id="discounts" style="margin-top:30px">Discount codes</h2>
    <?php if (isset($_GET['dsaved'])) echo '<div class="notice notice-success is-dismissible"><p>Code saved.</p></div>';
    if (isset($_GET['ddeleted'])) echo '<div class="notice notice-success is-dismissible"><p>Code deleted.</p></div>';
    if (isset($_GET['derr'])) echo '<div class="notice notice-error"><p>Please enter a code.</p></div>'; ?>
    <p class="description" style="margin:0 0 12px;max-width:780px">Create a code fans type at checkout — percent-off or a fixed amount off the item subtotal (shipping isn’t discounted). Works in demo and live checkout. Great for launches (<code>LAUNCH20</code>), superfans, or a mailing-list perk.</p>

    <table class="widefat striped" style="max-width:900px;margin-bottom:14px">
        <thead><tr><th>Code</th><th>Discount</th><th>Min order</th><th>Used</th><th>Revenue</th><th>Given</th><th>Expires</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows) : ?><tr><td colspan="9">No codes yet.</td></tr>
        <?php else : foreach ($rows as $d) :
            $money = function ($c) use ($d) { return function_exists('lmeg_format_price') ? lmeg_format_price((int) $c, $d->currency ?: 'USD') : ('$' . number_format($c / 100, 2)); };
            $expired = ($d->expires_at && strtotime($d->expires_at) < current_time('timestamp'));
        ?>
            <tr>
                <td><strong style="font-family:ui-monospace,Menlo,monospace"><?php echo esc_html($d->code); ?></strong></td>
                <td><?php echo esc_html(lmeg_discount_desc($d)); ?></td>
                <td><?php echo (int) $d->min_subtotal_cents > 0 ? esc_html($money($d->min_subtotal_cents)) : '—'; ?></td>
                <td><?php echo lmeg_discount_usage_html($d->used, $d->max_uses); ?></td>
                <?php $pf = $perf[$d->code] ?? null; ?>
                <td><?php echo $pf ? esc_html($money((int) $pf->rev)) : '<span style="color:#9A9DB0">—</span>'; ?></td>
                <td><?php echo ($pf && (int) $pf->given > 0) ? '<span style="color:#1a8a4a">' . esc_html($money((int) $pf->given)) . '</span>' : '<span style="color:#9A9DB0">—</span>'; ?></td>
                <td><?php echo $d->expires_at ? esc_html(date_i18n(get_option('date_format'), strtotime($d->expires_at))) . ($expired ? ' <span style="color:#b32d2e">(expired)</span>' : '') : '—'; ?></td>
                <td><?php echo $d->status === 'active' && !$expired ? '<span style="color:#1a8a4a">● Active</span>' : '<span style="color:#9A9DB0">Off</span>'; ?></td>
                <td>
                    <form method="post" action="<?php echo esc_url($save); ?>" style="display:inline" onsubmit="return confirm('Delete code <?php echo esc_js($d->code); ?>?');">
                        <?php wp_nonce_field('lmeg_delete_discount', 'lmeg_discount_del_nonce'); ?>
                        <input type="hidden" name="action" value="lmeg_delete_discount">
                        <input type="hidden" name="discount_id" value="<?php echo (int) $d->id; ?>">
                        <button class="button button-small link-delete" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <form method="post" action="<?php echo esc_url($save); ?>" style="max-width:900px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 18px">
        <?php wp_nonce_field('lmeg_save_discount', 'lmeg_discount_nonce'); ?>
        <input type="hidden" name="action" value="lmeg_save_discount">
        <strong>New code</strong> <span class="description">(saving an existing code updates it)</span>
        <table class="form-table" role="presentation">
            <tr><th><label>Code</label></th><td><input type="text" name="code" class="regular-text" style="text-transform:uppercase;font-family:ui-monospace,Menlo,monospace" placeholder="LAUNCH20" required></td></tr>
            <tr><th><label>Type</label></th><td>
                <label><input type="radio" name="kind" value="percent" checked> Percent off</label> &nbsp;&nbsp;
                <label><input type="radio" name="kind" value="amount"> Fixed amount off</label>
                &nbsp;&nbsp; value <input type="number" name="value" step="0.01" min="0" style="width:100px" placeholder="20">
                <span class="description">percent = 1–100, amount = e.g. 5.00</span>
            </td></tr>
            <tr><th><label>Currency <span style="color:#888;font-weight:400">(amount)</span></label></th><td><input type="text" name="currency" maxlength="3" style="width:64px" value="USD"></td></tr>
            <tr><th><label>Minimum order</label></th><td><input type="number" name="min_subtotal" step="0.01" min="0" style="width:110px" placeholder="0"> <span class="description">optional — item subtotal required to use the code</span></td></tr>
            <tr><th><label>Usage limit</label></th><td><input type="number" name="max_uses" min="0" style="width:110px" placeholder="unlimited"> <span class="description">0 / blank = unlimited</span></td></tr>
            <tr><th><label>Expires</label></th><td><input type="date" name="expires_at"> <span class="description">optional — valid through this day</span></td></tr>
            <tr><th><label>Status</label></th><td><select name="status"><option value="active">Active</option><option value="disabled">Off</option></select></td></tr>
        </table>
        <p><button type="submit" class="button button-primary">Save code</button></p>
    </form>
    <?php
}
