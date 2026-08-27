<?php
if (!defined('ABSPATH')) exit;

/* ============================================================================
 * Fanloop Store — cart + multi-item checkout  (BETA)
 * ----------------------------------------------------------------------------
 * A client-side cart (localStorage) with a server-validated checkout that can
 * run in two modes:
 *
 *   • DEMO   — no payment processor. The order completes instantly so the whole
 *              flow (fan capture, download links, receipts, admin notice,
 *              revenue, orders-to-ship) can be walked end-to-end. Sales are
 *              tagged processor='demo' and can be cleared with one click.
 *   • LIVE   — one Stripe Checkout session for the whole cart (digital +
 *              physical), fulfilled per line on return + webhook.
 *
 * Reuses the shared fulfilment helpers in products.php (capture_fan,
 * record_revenue, send_receipt, notify_admin, decrement_variant) so a cart line
 * behaves exactly like a single Buy-now purchase in every downstream surface.
 * ========================================================================== */

/** Demo (no-payment) checkout enabled? */
function lmeg_store_demo_on() {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    return !empty($s['store_demo']);
}

/** Flat shipping-by-zone (Canada / USA / International) enabled? */
function lmeg_store_ship_enabled() {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    return !empty($s['store_ship_zones']);
}
/** Map a country (name or 2-letter code) to a shipping zone. */
function lmeg_store_ship_zone($country) {
    $c = strtoupper(trim((string) $country));
    if ($c === 'CA' || $c === 'CANADA') return 'ca';
    if ($c === 'US' || $c === 'USA' || $c === 'UNITED STATES' || $c === 'UNITED STATES OF AMERICA') return 'us';
    return 'intl';
}
/** The three zone fees (cents), for display/JS. */
function lmeg_store_ship_fees() {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    return ['ca' => (int) ($s['store_ship_ca'] ?? 0), 'us' => (int) ($s['store_ship_us'] ?? 0), 'intl' => (int) ($s['store_ship_intl'] ?? 0)];
}
/** The flat shipping fee (cents) for a destination country. */
function lmeg_store_ship_fee($country) {
    $fees = lmeg_store_ship_fees();
    return (int) $fees[lmeg_store_ship_zone($country)];
}
/**
 * Apply the zone flat fee to a validated cart: put the whole fee on the first
 * physical line (zero the rest), and set the order shipping/total. No-op unless
 * zone shipping is on and the cart has a physical item.
 */
/** Free-shipping threshold (cents) on the order subtotal; 0 = off. */
function lmeg_store_ship_free_over() {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    return (int) ($s['store_ship_free_over'] ?? 0);
}

function lmeg_cart_apply_zone_shipping(&$v, $country) {
    if (empty($v['zone_shipping']) || empty($v['has_physical'])) return 0;
    $fee = lmeg_store_ship_fee($country);
    $fo  = lmeg_store_ship_free_over();
    if ($fo > 0 && (int) $v['subtotal'] >= $fo) $fee = 0;   // free over threshold
    $seen = false;
    foreach ($v['lines'] as $i => $ln) {
        if (!$seen && !empty($ln['physical'])) { $v['lines'][$i]['lship'] = $fee; $seen = true; }
        else $v['lines'][$i]['lship'] = 0;
    }
    $v['shipping'] = $fee;
    $v['total']    = (int) $v['subtotal'] + $fee;
    return $fee;
}

/**
 * Validate a raw cart (array of ['id','variant','qty','amount']) against the DB.
 * Never trusts client prices — only the pay-what-you-want amount is honoured,
 * and that is clamped to the product minimum. Invalid / unavailable lines are
 * dropped. Returns ['lines','subtotal','shipping','total','currency',
 * 'has_physical','mixed','dropped'].
 */
function lmeg_cart_validate($raw) {
    $lines = []; $sub = 0; $ship = 0; $cur = ''; $phys = false; $mixed = false; $dropped = 0;
    $zone = lmeg_store_ship_enabled();   // flat shipping by destination zone
    if (!is_array($raw)) $raw = [];
    foreach ($raw as $it) {
        if (!is_array($it)) { $dropped++; continue; }
        $id = (int) ($it['id'] ?? 0);
        $p  = $id ? lmeg_product_get($id) : null;
        if (!$p || $p->status !== 'active') { $dropped++; continue; }
        if ($p->stock >= 0 && $p->sold >= $p->stock) { $dropped++; continue; }

        $qty = max(1, min(20, (int) ($it['qty'] ?? 1)));

        // Variant (size): required if the product defines any, must be available.
        $variant = '';
        $vlist   = lmeg_product_variants($p);
        if ($vlist) {
            $sel = isset($it['variant']) ? sanitize_text_field((string) $it['variant']) : '';
            $ok  = null;
            foreach ($vlist as $vo) { if ($vo['name'] === $sel) { $ok = $vo; break; } }
            if (!$ok || !$ok['available']) { $dropped++; continue; }
            $variant = $ok['name'];
        }

        // Unit price (fixed, or clamped pay-what-you-want).
        $unit = (int) $p->price_cents;
        if (lmeg_product_is_pwyw($p)) {
            $chosen = isset($it['amount']) ? (int) round(((float) $it['amount']) * 100) : (int) $p->price_cents;
            $unit   = max((int) $p->min_price_cents, $chosen);
        }

        $physical = ($p->type === 'physical');
        // Per-product flat shipping, unless zone shipping is on (a single
        // destination-based fee is applied to the order instead).
        $lship    = ($physical && !$zone) ? (int) $p->shipping_cents : 0;
        $lcur     = strtoupper($p->currency ?: 'USD');
        if ($cur === '') $cur = $lcur;
        elseif ($lcur !== $cur) $mixed = true;

        $lines[] = ['p' => $p, 'variant' => $variant, 'qty' => $qty, 'unit' => $unit,
                    'physical' => $physical, 'lship' => $lship, 'cur' => $lcur];
        $sub  += $unit * $qty;
        $ship += $lship;
        if ($physical) $phys = true;
    }
    if ($cur === '') $cur = 'USD';
    return ['lines' => $lines, 'subtotal' => $sub, 'shipping' => $ship, 'total' => $sub + $ship,
            'currency' => $cur, 'has_physical' => $phys, 'mixed' => $mixed, 'dropped' => $dropped,
            'zone_shipping' => ($zone && $phys)];
}

/** Money formatter shared by the store pages. */
function lmeg_cart_money($cents, $cur) {
    return function_exists('lmeg_format_price') ? lmeg_format_price((int) $cents, $cur) : ('$' . number_format($cents / 100, 2));
}

/**
 * Fulfil ONE cart line. Idempotent, keyed on $ref (unique per line, e.g.
 * "demo_ab12…#0" or "<stripe_session>#0"). Records the sale but does NOT email —
 * the whole order gets one combined receipt from lmeg_cart_fulfill_all. Returns
 * a struct describing the line (incl. 'new' = whether it was fulfilled just now).
 */
function lmeg_cart_fulfill_line($line, $email, $ship_name, $ship_addr, $processor, $ref) {
    global $wpdb;
    $ptbl = $wpdb->prefix . 'lmeg_product_purchases';
    $p    = $line['p'];

    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ptbl WHERE provider_ref = %s", $ref));
    if ($existing && $existing->status === 'paid') {
        return [
            'new' => false, 'id' => (int) $existing->id, 'physical' => ($p->type === 'physical'),
            'title' => $p->title, 'variant' => (string) $existing->variant, 'type' => $p->type,
            'amount' => (int) $existing->amount_cents, 'unit' => (int) $line['unit'], 'line_discount' => 0,
            'qty' => (int) $existing->qty ?: 1, 'cur' => strtoupper($existing->currency ?: 'USD'),
            'token' => (string) $existing->access_token, 'note' => (string) ($existing->note ?? ''),
            'preorder' => function_exists('lmeg_product_is_preorder') && lmeg_product_is_preorder($p),
            'predate' => function_exists('lmeg_product_preorder_date') ? lmeg_product_preorder_date($p) : '',
        ];
    }

    $physical = !empty($line['physical']);
    $variant  = (string) $line['variant'];
    $qty      = max(1, (int) $line['qty']);
    $cur      = strtoupper($p->currency ?: 'USD');
    $disc     = max(0, (int) ($line['discount'] ?? 0));       // this line's share of an order discount
    $amount   = max(0, (int) $line['unit'] * $qty - $disc) + (int) $line['lship'];
    $token    = wp_generate_password(40, false, false);

    $sub_id = lmeg_product_capture_fan($email, $p);
    $fields = [
        'product_id' => (int) $p->id, 'subscriber_id' => $sub_id ?: null, 'email' => $email ?: null,
        'amount_cents' => $amount, 'qty' => $qty, 'currency' => $cur,
        'discount_code' => !empty($line['discount_code']) ? $line['discount_code'] : null, 'discount_cents' => $disc,
        'note' => !empty($line['note']) ? mb_substr((string) $line['note'], 0, 500) : null,
        'processor' => $processor, 'provider_ref' => $ref,
        'variant' => $variant ?: null, 'ship_name' => $ship_name ?: null, 'ship_address' => $ship_addr ?: null,
        'fulfillment' => $physical ? 'unshipped' : 'none', 'status' => 'paid', 'access_token' => $token,
        'paid_at' => current_time('mysql'),
    ];
    if ($existing) {
        $wpdb->update($ptbl, $fields, ['id' => (int) $existing->id]);
        $pur_id = (int) $existing->id;
    } else {
        $wpdb->insert($ptbl, array_merge($fields, [
            'access_count' => 0, 'access_limit' => 15, 'created_at' => current_time('mysql'),
        ]));
        $pur_id = (int) $wpdb->insert_id;
    }
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}lmeg_products SET sold = sold + %d WHERE id = %d", $qty, (int) $p->id));
    for ($k = 0; $k < $qty; $k++) lmeg_product_decrement_variant((int) $p->id, $variant);
    lmeg_product_record_revenue($p, $pur_id, $email, $amount, $cur);
    return [
        'new' => true, 'id' => $pur_id, 'physical' => $physical, 'title' => $p->title,
        'variant' => $variant, 'type' => $p->type, 'amount' => $amount, 'unit' => (int) $line['unit'],
        'line_discount' => $disc, 'qty' => $qty, 'cur' => $cur, 'token' => $token,
        'note' => (string) ($line['note'] ?? ''),
        'preorder' => function_exists('lmeg_product_is_preorder') && lmeg_product_is_preorder($p),
        'predate' => function_exists('lmeg_product_preorder_date') ? lmeg_product_preorder_date($p) : '',
    ];
}

/**
 * Fulfil an entire validated cart against one reference stem ($stem). Each line
 * i is fulfilled with "$stem#i". When any line is newly fulfilled, sends ONE
 * combined receipt to the buyer and ONE notification to the artist (guarded so
 * a webhook + return race can't double-send). Returns the ref stem.
 */
function lmeg_cart_fulfill_all($v, $email, $ship_name, $ship_addr, $processor, $stem, $discount = null, $note = '') {
    // Distribute an order discount across the item lines (shipping isn't discounted).
    $off    = $discount ? max(0, min((int) $discount['amount_off'], (int) $v['subtotal'])) : 0;
    $splits = ($off > 0 && function_exists('lmeg_discount_split'))
        ? lmeg_discount_split($v['lines'], $off, (int) $v['subtotal'])
        : array_fill(0, count($v['lines']), 0);

    $dcode = ($off > 0 && !empty($discount['code'])) ? $discount['code'] : null;
    $results = [];
    foreach ($v['lines'] as $i => $line) {
        $line['discount']      = $splits[$i] ?? 0;
        $line['discount_code'] = ($line['discount'] > 0) ? $dcode : null;
        $line['note']          = $note;
        $results[] = lmeg_cart_fulfill_line($line, $email, $ship_name, $ship_addr, $processor, $stem . '#' . $i);
    }
    $new_any = false;
    foreach ($results as $r) if (!empty($r['new'])) { $new_any = true; break; }

    $guard = 'lmeg_cart_mailed_' . md5($stem);
    if ($new_any && !get_transient($guard)) {
        set_transient($guard, 1, DAY_IN_SECONDS);
        if ($off > 0 && !empty($discount['code']) && empty($discount['bundle']) && function_exists('lmeg_discount_increment')) {
            lmeg_discount_increment($discount['code']);
        }
        $dctx = ($off > 0 && !empty($discount['code'])) ? ['code' => $discount['code'], 'amount_off' => $off, 'bundle' => !empty($discount['bundle'])] : null;
        if ($email) lmeg_cart_send_receipt($results, $email, $ship_name, $ship_addr, $dctx);
        lmeg_cart_notify_admin($results, $email, $ship_name, $ship_addr, $dctx);
    }
    return $stem;
}

/** One branded receipt for a whole cart order. */
function lmeg_cart_send_receipt($lines, $email, $ship_name, $ship_addr, $discount = null) {
    if (!function_exists('lmeg_email_deliver')) return;
    $artist = lmeg_email_artist();
    $items = []; $dls = []; $total = 0; $off = 0; $cur = 'USD'; $has_phys = false; $pre = [];
    foreach ($lines as $ln) {
        $cur = $ln['cur']; $total += (int) $ln['amount']; $off += (int) ($ln['line_discount'] ?? 0);
        $full = (int) $ln['amount'] + (int) ($ln['line_discount'] ?? 0);   // list price before discount
        $meta = ((int) $ln['qty'] > 1 ? (int) $ln['qty'] . ' × ' . lmeg_cart_money($ln['unit'], $ln['cur']) : '')
              . ((int) $ln['qty'] > 1 && $ln['variant'] ? ' · ' : '') . ($ln['variant'] ? $ln['variant'] : '');
        $items[] = ['name' => $ln['title'] . (!empty($ln['preorder']) ? ' (pre-order)' : ''), 'meta' => trim($meta, ' ·'), 'amount' => lmeg_cart_money($full, $ln['cur'])];
        if (!empty($ln['preorder'])) {
            $pre[] = esc_html($ln['title']) . ' — ' . ($ln['type'] === 'physical' ? 'ships ' : 'available ') . esc_html($ln['predate'] ?? '');
        } elseif ($ln['type'] !== 'physical' && $ln['token']) {
            $dls[] = ['name' => $ln['title'], 'url' => lmeg_product_access_url($ln['token'])];
        } else { $has_phys = true; }
    }
    $dlabel = (!empty($discount['bundle']) ? '🎁 Bundle' : 'Discount') . (!empty($discount['code']) ? ' (' . $discount['code'] . ')' : '');
    $extra = ($off > 0) ? [['label' => $dlabel, 'amount' => '−' . lmeg_cart_money($off, $cur), 'accent' => true]] : [];
    $inner = lmeg_email_h('Thanks for your order 💜')
        . lmeg_email_p('Here\'s everything from your order with <strong>' . esc_html($artist) . '</strong>.')
        . lmeg_email_order_table($items, lmeg_cart_money($total, $cur), $extra)
        . lmeg_email_download_block($dls)
        . ($pre ? lmeg_email_note('Pre-order' . (count($pre) > 1 ? 's' : '') . ' — we\'ll email you when ready:<br>' . implode('<br>', $pre)) : '')
        . ($has_phys ? lmeg_email_ship_block($ship_name, $ship_addr) . lmeg_email_note('We\'ll get your item' . (count($lines) > 1 ? 's' : '') . ' on the way and email you if we need anything.') : '')
        . ($dls ? lmeg_email_note('Trouble with a link? Just reply and ' . esc_html($artist) . ' can help.') : '');
    lmeg_email_deliver($email, 'Your order from ' . $artist, $inner, 'Your order from ' . $artist . ' — receipt & downloads.');
}

/** One notification to the artist for a whole cart order. */
function lmeg_cart_notify_admin($lines, $email, $ship_name, $ship_addr, $discount = null) {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    if (isset($s['store_notify']) && !$s['store_notify']) return;
    $to = trim((string) ($s['store_notify_email'] ?? '')) ?: get_option('admin_email');
    if (!$to || !is_email($to) || !function_exists('lmeg_email_deliver')) return;

    $items = []; $total = 0; $off = 0; $cur = 'USD'; $has_phys = false; $note = '';
    foreach ($lines as $ln) {
        $cur = $ln['cur']; $total += (int) $ln['amount']; $off += (int) ($ln['line_discount'] ?? 0);
        $full = (int) $ln['amount'] + (int) ($ln['line_discount'] ?? 0);
        $items[] = ['name' => $ln['title'] . ($ln['variant'] ? ' · ' . $ln['variant'] : ''),
                    'meta' => (int) $ln['qty'] > 1 ? 'Qty ' . (int) $ln['qty'] : '',
                    'amount' => lmeg_cart_money($full, $ln['cur'])];
        if ($ln['physical']) $has_phys = true;
        if ($note === '' && !empty($ln['note'])) $note = (string) $ln['note'];
    }
    $price = lmeg_cart_money($total, $cur);
    $dlabel = (!empty($discount['bundle']) ? '🎁 Bundle' : 'Discount') . (!empty($discount['code']) ? ' (' . $discount['code'] . ')' : '');
    $extra = ($off > 0) ? [['label' => $dlabel, 'amount' => '−' . lmeg_cart_money($off, $cur), 'accent' => true]] : [];
    $note_block = ($note !== '') ? lmeg_email_note('📝 <strong>Note from buyer:</strong><br>' . nl2br(esc_html($note))) : '';
    $inner = lmeg_email_h($has_phys ? 'New order 🛍️' : 'New sale 💿')
        . lmeg_email_p('A new order just landed — <strong>' . count($lines) . '</strong> item' . (count($lines) === 1 ? '' : 's') . ' from <strong>' . esc_html($email ?: 'unknown') . '</strong>.')
        . lmeg_email_order_table($items, $price, $extra)
        . $note_block
        . ($has_phys ? lmeg_email_ship_block($ship_name ?: '(no name given)', $ship_addr) . lmeg_email_button('Orders to ship →', admin_url('admin.php?page=lmeg-products#orders'))
                     : lmeg_email_button('Open your Store →', admin_url('admin.php?page=lmeg-products')));
    lmeg_email_deliver($to, ($has_phys ? '🛍️ New order' : '💿 New sale') . ' — ' . $price, $inner, 'New order in your store — ' . $price);
}

/**
 * Fulfil a paid multi-item Stripe session (from the return page or webhook).
 * The validated cart is stashed in a transient keyed by metadata[cart_token].
 * Idempotent per line via "<session_id>#<i>".
 */
function lmeg_cart_fulfill_from_session($session) {
    $token = sanitize_text_field($session['metadata']['cart_token'] ?? '');
    if (!$token) return;
    if (($session['payment_status'] ?? '') !== 'paid') return;
    $sess_id = (string) ($session['id'] ?? '');
    if (!$sess_id) return;

    $stash = get_transient('lmeg_cart_' . $token);
    if (!$stash || empty($stash['raw'])) return;               // already fulfilled + cleared, or expired
    $v = lmeg_cart_validate($stash['raw']);
    if (!$v['lines']) return;

    $email = sanitize_email($session['customer_details']['email'] ?? ($stash['email'] ?? ''));
    $sd    = $session['shipping_details'] ?? ($session['shipping'] ?? []);
    $ship_name = sanitize_text_field($sd['name'] ?? ($stash['ship_name'] ?? ''));
    $ship_addr = lmeg_product_fmt_addr($sd['address'] ?? []) ?: (string) ($stash['ship_addr'] ?? '');
    $discount  = !empty($stash['discount']) ? $stash['discount'] : null;
    $note      = (string) ($stash['note'] ?? '');
    if (!empty($v['zone_shipping'])) lmeg_cart_apply_zone_shipping($v, $stash['ship_country'] ?? '');

    lmeg_cart_fulfill_all($v, $email, $ship_name, $ship_addr, 'stripe', $sess_id, $discount, $note);
    if (function_exists('lmeg_abandoned_mark_recovered')) lmeg_abandoned_mark_recovered($email);
    delete_transient('lmeg_cart_' . $token);
}

/* ---------------------------------------------------------------------------
 * Front-end router — ?lmeg_cart=checkout|place|done
 * ------------------------------------------------------------------------- */
add_action('init', 'lmeg_cart_router');
function lmeg_cart_router() {
    if (!isset($_GET['lmeg_cart'])) return;
    $action = sanitize_key($_GET['lmeg_cart']);
    if ($action === 'checkout')     lmeg_cart_checkout_page();
    elseif ($action === 'place')    lmeg_cart_place();
    elseif ($action === 'done')     lmeg_cart_done();
    elseif ($action === 'resume' && function_exists('lmeg_abandoned_resume')) lmeg_abandoned_resume();
}

/** Read the posted cart JSON into a PHP array. */
function lmeg_cart_posted() {
    $raw = isset($_POST['cart']) ? json_decode(wp_unslash($_POST['cart']), true) : [];
    return is_array($raw) ? $raw : [];
}

/* ---------------------------------------------------------------------------
 * Step 1 — review page (order summary + email / shipping form)
 * ------------------------------------------------------------------------- */
function lmeg_cart_checkout_page($v = null, $raw = null, $err = '', $prefill_email = '') {
    if ($raw === null) $raw = lmeg_cart_posted();
    if ($v === null)   $v   = lmeg_cart_validate($raw);
    $demo = lmeg_store_demo_on();
    if ($prefill_email === '' && isset($_POST['email'])) $prefill_email = sanitize_email(wp_unslash($_POST['email']));

    if (empty($v['lines'])) {
        lmeg_store_page(__('Your cart is empty', 'lmeg'), '<div class="dot">🛒</div><h1>Your cart is empty</h1>'
            . '<p>Nothing to check out — head back and add something to the cart.</p>'
            . '<a class="home" href="' . esc_url(home_url('/')) . '">← Back to site</a>', 'Cart');
    }

    $cur   = $v['currency'];
    $rjson = wp_json_encode($raw);

    // Discount code (entered on this page, carried through to "place").
    $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
    $disc = null; $off = 0; $code_err = '';
    if ($code !== '' && function_exists('lmeg_discount_validate')) {
        $res = lmeg_discount_validate($code, (int) $v['subtotal'], $cur);
        if (!empty($res['ok'])) { $disc = $res; $off = (int) $res['amount_off']; }
        else { $code_err = $res['error']; $code = ''; }
    }
    // Automatic "buy the set" bundle — only when no manual code was applied.
    $bundle = null;
    if (!$disc && function_exists('lmeg_cart_bundle_match')) {
        $bm = lmeg_cart_bundle_match($v);
        if ($bm && (int) $bm['amount_off'] > 0) { $bundle = $bm; $off = (int) $bm['amount_off']; }
    }
    $grand   = max(0, (int) $v['total'] - $off);
    $checkout_action = esc_url(add_query_arg(['lmeg_cart' => 'checkout'], home_url('/')));

    // Order summary rows.
    $rows = '';
    foreach ($v['lines'] as $line) {
        $p    = $line['p'];
        $name = esc_html($p->title) . ($line['variant'] ? ' <span style="color:#9AA">· ' . esc_html($line['variant']) . '</span>' : '');
        $lt   = lmeg_cart_money($line['unit'] * $line['qty'], $line['cur']);
        $thumb = !empty($p->cover_url)
            ? '<img src="' . esc_url($p->cover_url) . '" alt="" style="width:46px;height:46px;border-radius:8px;object-fit:cover;flex:0 0 auto">'
            : '<div style="width:46px;height:46px;border-radius:8px;background:#222536;flex:0 0 auto"></div>';
        $rows .= '<div style="display:flex;gap:12px;align-items:center;padding:11px 0;border-bottom:1px solid rgba(255,255,255,.07)">'
              . $thumb
              . '<div style="flex:1;text-align:left;min-width:0"><div style="font-weight:650">' . $name . '</div>'
              . '<div style="color:#8B90A0;font-size:13px">' . (int) $line['qty'] . ' × ' . esc_html(lmeg_cart_money($line['unit'], $line['cur']))
              . ($line['lship'] ? ' · +' . esc_html(lmeg_cart_money($line['lship'], $line['cur'])) . ' ship' : '') . '</div></div>'
              . '<div style="font-weight:700;white-space:nowrap">' . esc_html($lt) . '</div></div>';
    }

    $zone_ship = !empty($v['zone_shipping']);
    $free_over = $zone_ship ? lmeg_store_ship_free_over() : 0;
    $free_now  = ($free_over > 0 && (int) $v['subtotal'] >= $free_over);
    $ship_row  = '';
    if ($zone_ship) {
        $ship_now = $free_now ? '<span id="flp-ship" style="color:#7DD3A8">Free</span>' : '<span id="flp-ship">select country ↓</span>';
        $ship_row = '<div style="display:flex;justify-content:space-between;padding:3px 0"><span>Shipping</span>' . $ship_now . '</div>';
    } elseif ($v['shipping']) {
        $ship_row = '<div style="display:flex;justify-content:space-between;padding:3px 0"><span>Shipping</span><span>' . esc_html(lmeg_cart_money($v['shipping'], $cur)) . '</span></div>';
    }
    // Free-shipping upsell hint.
    $free_hint = '';
    if ($free_over > 0 && $zone_ship) {
        $free_hint = $free_now
            ? '<div style="margin-top:8px;font-size:13px;color:#7DD3A8;text-align:center">🎉 You\'ve unlocked free shipping!</div>'
            : '<div style="margin-top:8px;font-size:13px;color:#E7C97D;text-align:center">Add ' . esc_html(lmeg_cart_money($free_over - (int) $v['subtotal'], $cur)) . ' more for free shipping</div>';
    }
    $totals = '<div style="margin-top:14px;font-size:14px;color:#B9BCC9">'
        . '<div style="display:flex;justify-content:space-between;padding:3px 0"><span>Subtotal</span><span>' . esc_html(lmeg_cart_money($v['subtotal'], $cur)) . '</span></div>'
        . ($disc && $off > 0 ? '<div style="display:flex;justify-content:space-between;padding:3px 0;color:#7DD3A8"><span>Discount (' . esc_html($disc['code']) . ')</span><span>−' . esc_html(lmeg_cart_money($off, $cur)) . '</span></div>' : '')
        . ($bundle && $off > 0 ? '<div style="display:flex;justify-content:space-between;padding:3px 0;color:#7DD3A8"><span>🎁 ' . esc_html($bundle['title']) . ' (' . (int) $bundle['pct'] . '% off)</span><span>−' . esc_html(lmeg_cart_money($off, $cur)) . '</span></div>' : '')
        . $ship_row
        . '<div style="display:flex;justify-content:space-between;padding:9px 0 0;margin-top:6px;border-top:1px solid rgba(255,255,255,.12);font-size:18px;font-weight:800;color:#fff"><span>Total</span><span id="flp-grand">' . esc_html(lmeg_cart_money($grand, $cur)) . '</span></div></div>';

    // Discount-code apply / remove UI.
    if ($disc) {
        $code_ui = '<div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center;background:rgba(125,211,168,.12);border:1px solid rgba(125,211,168,.35);border-radius:10px;padding:10px 13px">'
            . '<span style="color:#8fe3b5;font-size:14px">Code <strong>' . esc_html($disc['code']) . '</strong> applied — ' . esc_html(lmeg_discount_desc($disc['discount'], $cur)) . '</span>'
            . '<form method="post" action="' . $checkout_action . '" style="margin:0"><input type="hidden" name="cart" value="' . esc_attr($rjson) . '"><button type="submit" style="background:0;border:0;color:#9aa0b4;cursor:pointer;font-size:13px;text-decoration:underline">remove</button></form></div>';
    } else {
        $code_ui = '<form method="post" action="' . $checkout_action . '" style="margin-top:14px;display:flex;gap:8px">'
            . '<input type="hidden" name="cart" value="' . esc_attr($rjson) . '">'
            . '<input type="text" name="code" placeholder="Discount code" style="flex:1;padding:11px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.16);background:#0E1017;color:#fff;font-size:14px;text-transform:uppercase">'
            . '<button type="submit" style="background:#20222E;color:#fff;border:1px solid rgba(255,255,255,.16);border-radius:10px;padding:0 18px;font-weight:700;cursor:pointer">Apply</button></form>'
            . ($code_err ? '<div style="color:#FCA5A5;font-size:13px;margin-top:6px;text-align:left">' . esc_html($code_err) . '</div>' : '');
    }

    // Shipping fields for physical carts.
    $shipfields = '';
    if ($v['has_physical']) {
        $country_field = $zone_ship ? lmeg_cart_country_select() : lmeg_cart_input('ship_country', 'Country', true);
        $shipfields = '<div style="margin-top:12px;display:grid;gap:9px">'
            . lmeg_cart_input('ship_name', 'Full name', true)
            . lmeg_cart_input('ship_line1', 'Address', true)
            . lmeg_cart_input('ship_line2', 'Apartment, suite (optional)', false)
            . '<div style="display:grid;grid-template-columns:1fr 1fr;gap:9px">'
            . lmeg_cart_input('ship_city', 'City', true) . lmeg_cart_input('ship_region', 'State / Province', false) . '</div>'
            . '<div style="display:grid;grid-template-columns:1fr 1fr;gap:9px">'
            . lmeg_cart_input('ship_postal', 'Postal code', true) . $country_field . '</div></div>';
    }

    $errhtml = $err ? '<div style="background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.4);color:#FCA5A5;border-radius:10px;padding:10px 12px;margin-bottom:14px;font-size:14px">' . esc_html($err) . '</div>' : '';
    if ($v['mixed'] && !$demo) {
        $errhtml .= '<div style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.4);color:#FCD34D;border-radius:10px;padding:10px 12px;margin-bottom:14px;font-size:13px">Your cart mixes currencies — please check items out separately.</div>';
    }

    // Optional order / gift note.
    $note_ph = $v['has_physical'] ? 'Add a gift message or note for this order (optional)' : 'Add a note with your order (optional)';
    $notefield = '<label style="display:block;font-size:13px;color:#B9BCC9;margin:14px 0 5px">'
        . ($v['has_physical'] ? '🎁 Gift message or note' : '📝 Note') . ' <span style="color:#8B90A0;font-weight:400">— optional</span></label>'
        . '<textarea name="order_note" maxlength="500" rows="2" placeholder="' . esc_attr($note_ph) . '" '
        . 'style="width:100%;padding:11px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.16);background:#0E1017;color:#fff;font-size:14px;resize:vertical;font-family:inherit">'
        . esc_textarea(isset($_POST['order_note']) ? sanitize_textarea_field(wp_unslash($_POST['order_note'])) : '') . '</textarea>';

    $cta   = $demo ? 'Place demo order — no payment' : 'Continue to payment →';
    $note  = $demo
        ? '<div style="margin-top:12px;font-size:12px;color:#F5A9D0;background:rgba(225,95,168,.12);border-radius:9px;padding:9px 11px">Demo mode — no card is charged. This runs the full order flow so you can see receipts, downloads and the fan being captured.</div>'
        : '<div style="margin-top:12px;font-size:12px;color:#8B90A0">Secure checkout on Stripe. Your card details never touch this site.</div>';

    $body = '<div class="dot">🛍️</div><h1 style="margin-bottom:4px">Checkout</h1>'
        . '<p style="margin-bottom:18px;color:#8B90A0">' . count($v['lines']) . ' item' . (count($v['lines']) === 1 ? '' : 's') . ' in your cart' . ($demo ? ' · demo' : '') . '</p>'
        . '<div style="text-align:left;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:6px 16px 16px">' . $rows . $totals . $free_hint . '</div>'
        . ($bundle ? '<div style="margin-top:14px;display:flex;align-items:center;background:rgba(125,211,168,.12);border:1px solid rgba(125,211,168,.35);border-radius:10px;padding:10px 13px"><span style="color:#8fe3b5;font-size:14px">🎁 <strong>' . esc_html($bundle['title']) . '</strong> applied — buy the set, save ' . (int) $bundle['pct'] . '%</span></div>' : '')
        . $code_ui
        . '<form method="post" action="' . esc_url(add_query_arg(['lmeg_cart' => 'place'], home_url('/'))) . '" style="margin-top:20px;text-align:left">'
        . $errhtml
        . '<input type="hidden" name="cart" value="' . esc_attr($rjson) . '">'
        . '<input type="hidden" name="code" value="' . esc_attr($disc ? $disc['code'] : '') . '">'
        . '<label style="display:block;font-size:13px;color:#B9BCC9;margin-bottom:5px">Email — where your ' . ($v['has_physical'] ? 'confirmation goes' : 'downloads go') . '</label>'
        . '<input type="email" name="email" required placeholder="you@email.com" value="' . esc_attr($prefill_email) . '" style="width:100%;padding:12px 13px;border-radius:10px;border:1px solid rgba(255,255,255,.16);background:#0E1017;color:#fff;font-size:15px">'
        . $shipfields
        . $notefield
        . '<button type="submit" style="margin-top:16px;width:100%;background:linear-gradient(118deg,#E15FA8,#8A6CF6);color:#0B0C12;font-weight:800;border:0;padding:15px;border-radius:12px;font-size:15px;cursor:pointer">' . esc_html($cta) . '</button>'
        . $note
        . '</form>'
        . '<a class="home" href="' . esc_url(home_url('/')) . '">← Keep shopping</a>';

    // Live shipping recompute for zone shipping: pick fee from the country.
    if ($zone_ship) {
        $fees = lmeg_store_ship_fees();
        $body .= '<script>(function(){'
            . 'var FEES=' . wp_json_encode($fees) . ',SUB=' . (int) $v['subtotal'] . ',OFF=' . (int) $off . ',CUR=' . wp_json_encode($cur) . ',FREE=' . (int) $free_over . ';'
            . 'function money(c){try{return new Intl.NumberFormat(undefined,{style:"currency",currency:CUR}).format(c/100);}catch(e){return "$"+(c/100).toFixed(2);}}'
            . 'function zone(v){v=(v||"").toUpperCase();if(v==="CA")return"ca";if(v==="US")return"us";return"intl";}'
            . 'var sel=document.getElementById("flp-country"),shipEl=document.getElementById("flp-ship"),grandEl=document.getElementById("flp-grand");'
            . 'function upd(){var f;if(FREE>0&&SUB>=FREE){f=0;}else if(!sel||!sel.value){if(shipEl)shipEl.textContent="select country ↓";if(grandEl)grandEl.textContent=money(Math.max(0,SUB-OFF));return;}else{f=FEES[zone(sel.value)]||0;}'
            . 'if(shipEl)shipEl.textContent=f?money(f):"Free";if(grandEl)grandEl.textContent=money(Math.max(0,SUB-OFF)+f);}'
            . 'if(sel){sel.addEventListener("change",upd);}upd();})();</script>';
    }

    lmeg_store_page('Checkout', $body, 'Checkout');
}

/** Small labelled input for the shipping block. */
function lmeg_cart_input($name, $label, $required) {
    return '<input type="text" name="' . esc_attr($name) . '" placeholder="' . esc_attr($label) . '"' . ($required ? ' required' : '')
        . ' style="width:100%;padding:11px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.16);background:#0E1017;color:#fff;font-size:14px">';
}

/** Country <select> for zone shipping (values are used to pick the zone fee). */
function lmeg_cart_country_select() {
    $countries = ['CA' => 'Canada', 'US' => 'United States', 'GB' => 'United Kingdom', 'AU' => 'Australia',
        'NZ' => 'New Zealand', 'IE' => 'Ireland', 'DE' => 'Germany', 'FR' => 'France', 'NL' => 'Netherlands',
        'BE' => 'Belgium', 'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'ES' => 'Spain', 'IT' => 'Italy',
        'CH' => 'Switzerland', 'AT' => 'Austria', 'JP' => 'Japan', 'MX' => 'Mexico', 'BR' => 'Brazil', 'ZZ' => 'Other country'];
    $opts = '<option value="" disabled selected>Country…</option>';
    foreach ($countries as $code => $name) $opts .= '<option value="' . esc_attr($code) . '">' . esc_html($name) . '</option>';
    return '<select name="ship_country" id="flp-country" required style="width:100%;padding:11px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.16);background:#0E1017;color:#fff;font-size:14px">' . $opts . '</select>';
}

/* ---------------------------------------------------------------------------
 * Step 2 — place the order (demo fulfil, or start Stripe)
 * ------------------------------------------------------------------------- */
function lmeg_cart_place() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { wp_safe_redirect(home_url('/')); exit; }
    $raw   = lmeg_cart_posted();
    $v     = lmeg_cart_validate($raw);
    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));

    if (empty($v['lines']))     { lmeg_cart_checkout_page($v, $raw, 'Your cart is empty or those items are no longer available.'); }
    if (!$email || !is_email($email)) { lmeg_cart_checkout_page($v, $raw, 'Please enter a valid email address.'); }

    // Shipping details for physical carts.
    $ship_name = ''; $ship_addr = '';
    if ($v['has_physical']) {
        $ship_name = sanitize_text_field(wp_unslash($_POST['ship_name'] ?? ''));
        $line1 = sanitize_text_field(wp_unslash($_POST['ship_line1'] ?? ''));
        $city  = sanitize_text_field(wp_unslash($_POST['ship_city'] ?? ''));
        $postal= sanitize_text_field(wp_unslash($_POST['ship_postal'] ?? ''));
        $ctry  = sanitize_text_field(wp_unslash($_POST['ship_country'] ?? ''));
        if (!$ship_name || !$line1 || !$city || !$postal || !$ctry) {
            lmeg_cart_checkout_page($v, $raw, 'Please complete the shipping address.');
        }
        $ship_addr = lmeg_product_fmt_addr([
            'line1' => $line1, 'line2' => sanitize_text_field(wp_unslash($_POST['ship_line2'] ?? '')),
            'city'  => $city,  'state' => sanitize_text_field(wp_unslash($_POST['ship_region'] ?? '')),
            'postal_code' => $postal, 'country' => $ctry,
        ]);
    }
    $ship_country = isset($ctry) ? $ctry : '';

    // Optional order / gift note.
    $onote = isset($_POST['order_note']) ? mb_substr(sanitize_textarea_field(wp_unslash($_POST['order_note'])), 0, 500) : '';

    // Zone flat shipping — set the order's shipping from the destination country.
    if (!empty($v['zone_shipping'])) lmeg_cart_apply_zone_shipping($v, $ship_country);

    // Discount code — re-validate at place time (it may have expired/hit its cap).
    $code = sanitize_text_field(wp_unslash($_POST['code'] ?? ''));
    $disc = null; $off = 0;
    if ($code !== '' && function_exists('lmeg_discount_validate')) {
        $res = lmeg_discount_validate($code, (int) $v['subtotal'], $v['currency']);
        if (!empty($res['ok'])) { $disc = ['code' => $res['code'], 'amount_off' => (int) $res['amount_off']]; $off = (int) $res['amount_off']; }
        else lmeg_cart_checkout_page($v, $raw, $res['error']);   // back to review with the reason
    }
    // Automatic "buy the set" bundle — server-authoritative, only when no code.
    if (!$disc && function_exists('lmeg_cart_bundle_match')) {
        $bm = lmeg_cart_bundle_match($v);
        if ($bm && (int) $bm['amount_off'] > 0) {
            $disc = ['code' => $bm['title'], 'amount_off' => (int) $bm['amount_off'], 'bundle' => true];
            $off  = (int) $bm['amount_off'];
        }
    }

    /* ---------- DEMO: fulfil immediately, no payment ---------- */
    if (lmeg_store_demo_on()) {
        $stem = 'demo_' . wp_generate_password(20, false, false);
        lmeg_cart_fulfill_all($v, $email, $ship_name, $ship_addr, 'demo', $stem, $disc, $onote);
        lmeg_cart_done($stem);   // render success
        exit;
    }

    /* ---------- LIVE: one Stripe session for the whole cart ---------- */
    if ($v['mixed']) { lmeg_cart_checkout_page($v, $raw, 'Your cart mixes currencies — please check items out separately.'); }
    $keys = function_exists('lmeg_stripe_keys') ? lmeg_stripe_keys() : [];
    if (empty($keys['sk'])) {
        lmeg_cart_checkout_page($v, $raw, 'Card checkout is not set up yet. (An admin can turn on demo checkout to test the flow.)');
    }

    // A native code can't take a card order below Stripe's minimum charge.
    if ($off > 0 && (max(0, (int) $v['subtotal'] - $off) + (int) $v['shipping']) < 50) {
        lmeg_cart_checkout_page($v, $raw, 'That code brings the total too low for card checkout. (An admin can turn on demo mode to test a free order.)');
    }
    $splits = ($off > 0 && function_exists('lmeg_discount_split'))
        ? lmeg_discount_split($v['lines'], min($off, (int) $v['subtotal']), (int) $v['subtotal'])
        : array_fill(0, count($v['lines']), 0);

    $token = wp_generate_password(24, false, false);
    set_transient('lmeg_cart_' . $token, [
        'raw' => $raw, 'email' => $email, 'ship_name' => $ship_name, 'ship_addr' => $ship_addr, 'discount' => $disc,
        'ship_country' => $ship_country, 'note' => $onote,
    ], 2 * HOUR_IN_SECONDS);

    // Save the cart so it can be recovered if they don't complete payment.
    if (function_exists('lmeg_abandoned_capture')) lmeg_abandoned_capture($email, $raw, $v);

    $cur     = strtolower($v['currency']);
    $success = add_query_arg(['lmeg_cart' => 'done', 'token' => $token, 'session_id' => '{CHECKOUT_SESSION_ID}'], home_url('/'));
    $params  = [
        'mode' => 'payment', 'success_url' => $success, 'cancel_url' => home_url('/'),
        'customer_email' => $email,
        'metadata[cart_token]' => $token,
    ];
    if (!$disc) $params['allow_promotion_codes'] = 'true';   // don't stack native + Stripe promo codes
    $i = 0;
    foreach ($v['lines'] as $line) {
        $p = $line['p'];
        $params["line_items[$i][price_data][currency]"] = $cur;
        if ($off > 0) {
            // Fold this line's share of the discount into a single unit price.
            $params["line_items[$i][price_data][unit_amount]"]        = max(0, (int) $line['unit'] * (int) $line['qty'] - (int) $splits[$i]);
            $params["line_items[$i][price_data][product_data][name]"] = $p->title . ($line['variant'] ? ' — ' . $line['variant'] : '') . ((int) $line['qty'] > 1 ? ' ×' . (int) $line['qty'] : '');
            $params["line_items[$i][quantity]"]                       = 1;
        } else {
            $params["line_items[$i][price_data][unit_amount]"]        = (int) $line['unit'];
            $params["line_items[$i][price_data][product_data][name]"] = $p->title . ($line['variant'] ? ' — ' . $line['variant'] : '');
            $params["line_items[$i][quantity]"]                       = (int) $line['qty'];
        }
        if (!empty($p->cover_url) && filter_var($p->cover_url, FILTER_VALIDATE_URL)) {
            $params["line_items[$i][price_data][product_data][images][0]"] = $p->cover_url;
        }
        $i++;
    }
    if ($v['shipping'] > 0) {
        $params['shipping_options[0][shipping_rate_data][type]']                   = 'fixed_amount';
        $params['shipping_options[0][shipping_rate_data][fixed_amount][amount]']   = (int) $v['shipping'];
        $params['shipping_options[0][shipping_rate_data][fixed_amount][currency]'] = $cur;
        $params['shipping_options[0][shipping_rate_data][display_name]']           = 'Shipping';
    }
    $session = lmeg_stripe_request('POST', '/checkout/sessions', $params);
    if (is_wp_error($session) || empty($session['url'])) {
        lmeg_cart_checkout_page($v, $raw, 'Could not start checkout: ' . (is_wp_error($session) ? $session->get_error_message() : 'unknown error'));
    }
    wp_redirect(esc_url_raw($session['url']));
    exit;
}

/* ---------------------------------------------------------------------------
 * Step 3 — success page (demo: called inline; Stripe: ?lmeg_cart=done)
 * ------------------------------------------------------------------------- */
function lmeg_cart_done($stem = '') {
    global $wpdb;
    $ptbl = $wpdb->prefix . 'lmeg_product_purchases';

    if ($stem === '') {
        // Stripe return — fulfil as a fallback for webhook lag, then show.
        $token   = sanitize_text_field($_GET['token'] ?? '');
        $sess_id = sanitize_text_field($_GET['session_id'] ?? '');
        if ($sess_id && function_exists('lmeg_stripe_request')) {
            $s = lmeg_stripe_request('GET', '/checkout/sessions/' . rawurlencode($sess_id));
            if (!is_wp_error($s) && ($s['payment_status'] ?? '') === 'paid') {
                if (empty($s['metadata']['cart_token']) && $token) $s['metadata']['cart_token'] = $token;
                lmeg_cart_fulfill_from_session($s);
            }
        }
        $stem = $sess_id;
    }

    // Look the fulfilled lines back up by ref stem.
    $rows = $stem ? $wpdb->get_results($wpdb->prepare(
        "SELECT pp.*, pr.title, pr.type FROM $ptbl pp LEFT JOIN {$wpdb->prefix}lmeg_products pr ON pr.id = pp.product_id
         WHERE pp.provider_ref LIKE %s AND pp.status = 'paid' ORDER BY pp.id ASC",
        $wpdb->esc_like($stem) . '#%'
    )) : [];

    if (!$rows) {
        lmeg_store_page('Processing', '<div class="dot">…</div><h1>Payment processing</h1>'
            . '<p>Hang tight — your order is being confirmed and your receipt is on its way. You can safely close this page.</p>'
            . '<a class="home" href="' . esc_url(home_url('/')) . '">← Back to site</a>', 'Order');
    }

    $items = ''; $any_dl = false;
    foreach ($rows as $r) {
        $digital = ($r->type !== 'physical');
        $line = '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 0;border-bottom:1px solid rgba(255,255,255,.08)">'
              . '<div style="text-align:left"><div style="font-weight:650">' . esc_html($r->title) . ($r->variant ? ' · <span style="color:#9AA">' . esc_html($r->variant) . '</span>' : '') . '</div>'
              . '<div style="color:#8B90A0;font-size:12px">' . esc_html(lmeg_cart_money($r->amount_cents, $r->currency)) . '</div></div>';
        if ($digital && $r->access_token) {
            $any_dl = true;
            $line .= '<a href="' . esc_url(lmeg_product_access_url($r->access_token)) . '" style="background:linear-gradient(118deg,#E15FA8,#8A6CF6);color:#0B0C12;font-weight:750;text-decoration:none;padding:9px 15px;border-radius:9px;white-space:nowrap;font-size:14px">Download →</a>';
        } else {
            $line .= '<span style="color:#7DD3A8;font-size:13px;font-weight:600;white-space:nowrap">Ships to you</span>';
        }
        $items .= $line . '</div>';
    }

    $body = '<div class="dot">✓</div><h1>You\'re in. Thank you!</h1>'
        . '<p>' . ($any_dl ? 'Your downloads are ready below, and a' : 'A') . ' receipt is on its way to your inbox.</p>'
        . '<div style="text-align:left;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:4px 16px 12px;margin-bottom:8px">' . $items . '</div>'
        . ($any_dl ? '<p style="font-size:12px;color:#8B90A0;margin:2px 0 0">Lose this page? Get your downloads again anytime at <a href="' . esc_url(add_query_arg(['lmeg_purchases' => 'find'], home_url('/'))) . '" style="color:#E7A6CF">find my purchases</a>.</p>' : '')
        . '<a class="home" href="' . esc_url(home_url('/')) . '">← Back to site</a>'
        . '<script>try{localStorage.removeItem("fanloop_cart")}catch(e){}</script>';

    // Post-purchase upsell — best-sellers they didn't just buy.
    $bought = array_map(function ($r) { return (int) $r->product_id; }, $rows);
    if (function_exists('lmeg_store_upsell_html')) $body .= lmeg_store_upsell_html($bought, 3);

    lmeg_store_page('Thank you', $body, 'Order');
}

/* ---------------------------------------------------------------------------
 * Shared on-brand page chrome for the store's standalone pages.
 * ------------------------------------------------------------------------- */
function lmeg_store_page($title, $inner, $tab = '') {
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    $site = get_bloginfo('name');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo esc_html(($tab ? $tab . ' · ' : '') . $site); ?></title>
    <style>
      :root{color-scheme:dark}
      *{box-sizing:border-box;margin:0}body{background:#0B0C12;color:#F4F2F7;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px}
      .card{width:100%;max-width:520px;text-align:center;background:linear-gradient(160deg,#161826,#12141f);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:38px 30px;box-shadow:0 30px 90px rgba(0,0,0,.5)}
      .dot{width:52px;height:52px;border-radius:50%;margin:0 auto 18px;background:linear-gradient(118deg,#E15FA8,#8A6CF6);display:grid;place-items:center;font-size:26px}
      h1{font-size:25px;font-weight:820;letter-spacing:-.02em;margin-bottom:9px}
      p{color:#B9BCC9;line-height:1.55;margin-bottom:18px}
      a{color:#E7A6CF}
      .home{display:block;margin-top:20px;color:#8B90A0;font-size:13px;text-decoration:none}
      input::placeholder{color:#5C6070}
    </style></head><body><div class="card"><?php echo $inner; ?></div></body></html><?php
    exit;
}

/* ---------------------------------------------------------------------------
 * Cart UI — floating button + slide-in drawer (client-side, localStorage).
 * Printed once on the front end wherever store content was rendered.
 * ------------------------------------------------------------------------- */
add_action('wp_footer', 'lmeg_cart_footer');
function lmeg_cart_footer() {
    if (is_admin()) return;
    if (empty($GLOBALS['lmeg_store_seen'])) return;   // only on pages that showed store content
    if (!empty($GLOBALS['lmeg_cart_printed'])) return;
    echo lmeg_cart_assets_html();
}

/** The drawer markup + script (also embedded inline on the standalone product page). */
function lmeg_cart_assets_html() {
    if (!empty($GLOBALS['lmeg_cart_printed'])) return '';
    $GLOBALS['lmeg_cart_printed'] = true;
    $checkout = esc_url(add_query_arg(['lmeg_cart' => 'checkout'], home_url('/')));
    ob_start(); ?>
<div id="flp-cart-root" data-checkout="<?php echo $checkout; ?>">
  <button id="flp-cart-btn" type="button" aria-label="Open cart" hidden>
    🛒 <span id="flp-cart-count">0</span>
  </button>
  <div id="flp-cart-back" hidden></div>
  <aside id="flp-cart-panel" hidden aria-label="Cart">
    <div class="flp-cart-head"><strong>Your cart</strong><button type="button" id="flp-cart-x" aria-label="Close">✕</button></div>
    <div id="flp-cart-items"></div>
    <div class="flp-cart-foot">
      <div class="flp-cart-tot"><span>Total</span><span id="flp-cart-total">—</span></div>
      <button type="button" id="flp-cart-go" class="flp-cart-go">Checkout</button>
      <div id="flp-cart-empty" class="flp-cart-empty">Your cart is empty.</div>
    </div>
  </aside>
</div>
<style>
  #flp-cart-btn{position:fixed;right:20px;bottom:20px;z-index:99998;background:linear-gradient(118deg,#E15FA8,#8A6CF6);color:#0B0C12;border:0;font-weight:800;font-size:15px;padding:13px 18px;border-radius:999px;cursor:pointer;box-shadow:0 12px 34px rgba(138,108,246,.4);display:flex;align-items:center;gap:8px}
  #flp-cart-btn #flp-cart-count{background:#0B0C12;color:#fff;min-width:22px;height:22px;border-radius:999px;display:inline-grid;place-items:center;font-size:12px;padding:0 6px}
  #flp-cart-back{position:fixed;inset:0;background:rgba(6,7,12,.62);z-index:99998;backdrop-filter:blur(2px)}
  #flp-cart-panel{position:fixed;top:0;right:0;height:100%;width:min(390px,92vw);z-index:99999;background:#12141F;color:#F4F2F7;box-shadow:-24px 0 70px rgba(0,0,0,.5);display:flex;flex-direction:column;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;transform:translateX(0)}
  #flp-cart-panel .flp-cart-head{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid rgba(255,255,255,.08);font-size:17px}
  #flp-cart-x{background:0;border:0;color:#9AA0B4;font-size:18px;cursor:pointer}
  #flp-cart-items{flex:1;overflow:auto;padding:6px 20px}
  .flp-ci{display:flex;gap:12px;align-items:center;padding:13px 0;border-bottom:1px solid rgba(255,255,255,.07)}
  .flp-ci img,.flp-ci .ph{width:52px;height:52px;border-radius:9px;object-fit:cover;flex:0 0 auto;background:#222536}
  .flp-ci .t{flex:1;min-width:0}
  .flp-ci .t b{font-weight:650;font-size:14px;display:block;line-height:1.3}
  .flp-ci .t span{color:#8B90A0;font-size:12px;display:block;margin:1px 0 2px}
  .flp-qty{display:inline-flex;align-items:center;gap:0;margin-top:6px;border:1px solid rgba(255,255,255,.16);border-radius:8px;overflow:hidden}
  .flp-qty button{background:#1B1E2C;color:#D8DAE6;border:0;width:26px;height:26px;cursor:pointer;font-size:15px}
  .flp-qty span{min-width:30px;text-align:center;font-size:13px}
  .flp-ci .rm{background:0;border:0;color:#8B90A0;cursor:pointer;font-size:12px;text-decoration:underline}
  .flp-ci .lt{font-weight:700;white-space:nowrap;font-size:14px}
  .flp-cart-foot{padding:16px 20px;border-top:1px solid rgba(255,255,255,.1)}
  .flp-cart-tot{display:flex;justify-content:space-between;font-size:17px;font-weight:800;margin-bottom:12px}
  .flp-cart-go{width:100%;background:linear-gradient(118deg,#E15FA8,#8A6CF6);color:#0B0C12;font-weight:800;border:0;padding:14px;border-radius:11px;font-size:15px;cursor:pointer}
  .flp-cart-empty{color:#8B90A0;text-align:center;font-size:14px;padding:8px 0}
  #flp-cart-toast{position:fixed;left:50%;bottom:82px;transform:translateX(-50%);z-index:99999;background:#1B1E2C;color:#fff;border:1px solid rgba(255,255,255,.14);padding:10px 16px;border-radius:999px;font-family:system-ui,sans-serif;font-size:14px;box-shadow:0 12px 30px rgba(0,0,0,.4);opacity:0;transition:opacity .2s,transform .2s;pointer-events:none}
  #flp-cart-toast.show{opacity:1;transform:translateX(-50%) translateY(-4px)}
</style>
<script>
(function(){
  var KEY='fanloop_cart', root=document.getElementById('flp-cart-root'); if(!root) return;
  var CHECKOUT=root.getAttribute('data-checkout');
  var btn=document.getElementById('flp-cart-btn'), cnt=document.getElementById('flp-cart-count'),
      back=document.getElementById('flp-cart-back'), panel=document.getElementById('flp-cart-panel'),
      itemsEl=document.getElementById('flp-cart-items'), totalEl=document.getElementById('flp-cart-total'),
      goBtn=document.getElementById('flp-cart-go'), emptyEl=document.getElementById('flp-cart-empty');

  function read(){ try{return JSON.parse(localStorage.getItem(KEY))||[]}catch(e){return[]} }
  function write(c){ try{localStorage.setItem(KEY,JSON.stringify(c))}catch(e){}; render(); }
  function count(c){ return c.reduce(function(n,i){return n+(+i.qty||1)},0); }
  function keyOf(i){ return i.id+'|'+(i.variant||''); }
  function money(cents,cur){ try{return new Intl.NumberFormat(undefined,{style:'currency',currency:cur||'USD'}).format((cents||0)/100);}catch(e){return '$'+((cents||0)/100).toFixed(2);} }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }

  function toast(msg){
    var t=document.getElementById('flp-cart-toast');
    if(!t){ t=document.createElement('div'); t.id='flp-cart-toast'; document.body.appendChild(t); }
    t.textContent=msg; t.classList.add('show'); clearTimeout(t._h); t._h=setTimeout(function(){t.classList.remove('show');},1600);
  }
  function open(){ back.hidden=false; panel.hidden=false; }
  function close(){ back.hidden=true; panel.hidden=true; }

  function render(){
    var c=read(), n=count(c);
    cnt.textContent=n;
    btn.hidden = (n===0 && !document.querySelector('.flp-add'));   // hide when empty & no products on page
    if(!c.length){ itemsEl.innerHTML=''; emptyEl.style.display='block'; goBtn.style.display='none'; totalEl.textContent='—'; return; }
    emptyEl.style.display='none'; goBtn.style.display='block';
    var cur=c[0].cur||'USD', sub=0, ship=0, html='';
    c.forEach(function(i,idx){
      var lt=(+i.unit)*(+i.qty); sub+=lt; ship+=(+i.ship||0);
      html+='<div class="flp-ci" data-k="'+esc(keyOf(i))+'">'
        + (i.cover?'<img src="'+esc(i.cover)+'" alt="">':'<div class="ph"></div>')
        + '<div class="t"><b>'+esc(i.title)+'</b>'+(i.variant?'<span>'+esc(i.variant)+'</span>':'')
        + '<div class="flp-qty"><button type="button" data-act="dec">−</button><span>'+(+i.qty)+'</span><button type="button" data-act="inc">+</button></div> '
        + '<button type="button" class="rm" data-act="rm">remove</button></div>'
        + '<div class="lt">'+money(lt,i.cur)+'</div></div>';
    });
    itemsEl.innerHTML=html;
    totalEl.textContent=money(sub+ship,cur);
  }

  // Add-to-cart from a product card.
  function handleAdd(b){
    var card=b.closest('.flp-prod')||document, hasvar=b.getAttribute('data-hasvar')==='1', pwyw=b.getAttribute('data-pwyw')==='1';
    var variant='';
    if(hasvar){ var sel=card.querySelector('select[name=variant]'); variant=sel?sel.value:''; if(!variant){ toast('Choose an option first'); if(sel) sel.focus(); return; } }
    var unit=+b.getAttribute('data-price');
    if(pwyw){ var amt=card.querySelector('input[name=amount]'); var val=amt?parseFloat(amt.value):0; var min=(+b.getAttribute('data-min'))/100; if(!(val>0)) val=min; if(val<min) val=min; unit=Math.round(val*100); }
    var item={ id:+b.getAttribute('data-id'), slug:b.getAttribute('data-slug'), title:b.getAttribute('data-title'),
      cover:b.getAttribute('data-cover'), unit:unit, cur:b.getAttribute('data-cur')||'USD', variant:variant, qty:1,
      type:b.getAttribute('data-type'), ship:+b.getAttribute('data-ship')||0, pwyw:pwyw };
    var c=read(), found=false;
    for(var k=0;k<c.length;k++){ if(keyOf(c[k])===keyOf(item)){ c[k].qty=(+c[k].qty||1)+1; if(pwyw) c[k].unit=unit; found=true; break; } }
    if(!found) c.push(item);
    write(c); toast('Added to cart'); open();
  }

  // Add a whole bundle at once (payloads in data-items).
  function handleAddSet(b){
    var items; try{items=JSON.parse(b.getAttribute('data-items')||'[]');}catch(_){items=[];}
    if(!items.length) return;
    var c=read();
    items.forEach(function(it){
      if(!it||!it.id) return;
      var found=false;
      for(var k=0;k<c.length;k++){ if(keyOf(c[k])===keyOf(it)){ c[k].qty=(+c[k].qty||1)+1; found=true; break; } }
      if(!found){ it.qty=(+it.qty||1); c.push(it); }
    });
    write(c); toast('Set added to cart'); open();
  }

  document.addEventListener('click', function(e){
    var st=e.target.closest('.flp-add-set'); if(st){ e.preventDefault(); handleAddSet(st); return; }
    var a=e.target.closest('.flp-add'); if(a){ e.preventDefault(); handleAdd(a); return; }
    if(e.target===btn||e.target.closest('#flp-cart-btn')){ render(); open(); return; }
    if(e.target===back||e.target===document.getElementById('flp-cart-x')){ close(); return; }
    var act=e.target.getAttribute&&e.target.getAttribute('data-act');
    if(act){
      var row=e.target.closest('.flp-ci'); if(!row) return; var k=row.getAttribute('data-k'); var c=read();
      for(var j=0;j<c.length;j++){ if(keyOf(c[j])===k){
        if(act==='inc') c[j].qty=(+c[j].qty||1)+1;
        else if(act==='dec') c[j].qty=Math.max(1,(+c[j].qty||1)-1);
        else if(act==='rm') c.splice(j,1);
        break;
      } }
      write(c); return;
    }
    if(e.target===goBtn){
      var c=read(); if(!c.length) return;
      var f=document.createElement('form'); f.method='POST'; f.action=CHECKOUT;
      var inp=document.createElement('input'); inp.type='hidden'; inp.name='cart';
      inp.value=JSON.stringify(c.map(function(i){return {id:i.id,variant:i.variant,qty:i.qty,amount:(i.unit/100)};}));
      f.appendChild(inp); document.body.appendChild(f); f.submit();
    }
  });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') close(); });

  render();
})();
</script>
<?php
    return ob_get_clean();
}
