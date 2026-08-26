<?php
if (!defined('ABSPATH')) exit;

/* ============================================================================
 * Fanloop Drops — native digital products / direct sales  (BETA)
 * ----------------------------------------------------------------------------
 * One-off Stripe purchases sold straight to the fan list. Reuses the members
 * Stripe helper + webhook. On a paid checkout it: captures the buyer as a fan,
 * tags them, records the sale into lmeg_shop_orders (so it shows up in every
 * revenue / fan / attribution surface for free), and delivers the goods via a
 * tokenized access link (+ emailed receipt). Physical merch/fulfilment stays on
 * Shopify by design — this is digital + direct only.
 * ========================================================================== */

function lmeg_product_get($id)      { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lmeg_products WHERE id = %d", (int) $id)); }
function lmeg_product_by_slug($slug){ global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lmeg_products WHERE slug = %s", (string) $slug)); }
function lmeg_product_is_pwyw($p)   { return (int) $p->min_price_cents > 0; }
function lmeg_product_url($p)       { return add_query_arg(['lmeg_product' => $p->slug], home_url('/')); }

/**
 * Parse variants (+ optional per-variant stock JSON) into a list of
 * ['name','stock'(int|null),'available'(bool)]. stock null = untracked/unlimited.
 */
function lmeg_product_variants($p) {
    $names = array_filter(array_map('trim', explode(',', (string) $p->variants)));
    $stock = [];
    if (!empty($p->variant_stock)) { $d = json_decode($p->variant_stock, true); if (is_array($d)) $stock = $d; }
    $out = [];
    foreach ($names as $n) {
        $has = array_key_exists($n, $stock);
        $q   = $has ? (int) $stock[$n] : null;
        $out[] = ['name' => $n, 'stock' => $q, 'available' => (!$has || $q > 0)];
    }
    return $out;
}

/** Decrement a tracked variant's stock by one on a sale (no-op if untracked). */
function lmeg_product_decrement_variant($product_id, $variant) {
    if ($variant === '') return;
    global $wpdb;
    $p = lmeg_product_get($product_id);
    if (!$p || empty($p->variant_stock)) return;
    $stock = json_decode($p->variant_stock, true);
    if (!is_array($stock) || !array_key_exists($variant, $stock)) return;
    $stock[$variant] = max(0, (int) $stock[$variant] - 1);
    $wpdb->update($wpdb->prefix . 'lmeg_products', ['variant_stock' => wp_json_encode($stock)], ['id' => (int) $product_id]);
}

/** Build the admin variants field value: "S:8, M:3, L" (remaining qty for tracked). */
function lmeg_product_variants_field($p) {
    $parts = [];
    foreach (lmeg_product_variants($p) as $v) {
        $parts[] = $v['name'] . ($v['stock'] === null ? '' : ':' . $v['stock']);
    }
    return implode(', ', $parts);
}

/* ---------------------------------------------------------------------------
 * First-run: seed a few DRAFT sample products so a fresh Store isn't empty and
 * the artist has real, fully-filled examples to edit. Runs once per site, and
 * only when the store is genuinely empty (never disturbs real products). Drafts
 * are hidden from the front end until the artist sets them Active.
 * ------------------------------------------------------------------------- */
add_action('admin_init', 'lmeg_products_seed_samples');
function lmeg_products_seed_samples() {
    if (get_option('lmeg_store_samples_seeded')) return;
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_products';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tbl)) !== $tbl) return; // table not ready yet
    if ((int) $wpdb->get_var("SELECT COUNT(*) FROM $tbl") > 0) { update_option('lmeg_store_samples_seeded', 1, false); return; }

    $now = current_time('mysql');
    $samples = [
        [
            'title' => 'Sample — Single (digital download)', 'slug' => 'sample-single',
            'description' => 'This is a sample. Edit it or delete it. A digital track fans buy and download — upload the audio file (or paste an unlock link) on this product.',
            'price_cents' => 199, 'min_price_cents' => 0, 'currency' => 'USD',
            'type' => 'digital', 'processor' => 'stripe', 'shipping_cents' => 0, 'variants' => '', 'stock' => -1,
        ],
        [
            'title' => 'Sample — Pay what you want', 'slug' => 'sample-pwyw',
            'description' => 'A "name your price" release — great for tips and supporting a record. Edit the suggested price and minimum, then upload the file.',
            'price_cents' => 500, 'min_price_cents' => 200, 'currency' => 'USD',
            'type' => 'digital', 'processor' => 'stripe', 'shipping_cents' => 0, 'variants' => '', 'stock' => -1,
        ],
        [
            'title' => 'Sample — T-shirt (physical)', 'slug' => 'sample-tshirt',
            'description' => 'A physical item that ships. Edit the price, sizes, and flat shipping fee. Uses Stripe by default — switch to Square on this product if you prefer.',
            'price_cents' => 3000, 'min_price_cents' => 0, 'currency' => 'USD',
            'type' => 'physical', 'processor' => 'stripe', 'shipping_cents' => 600, 'variants' => 'S, M, L, XL', 'stock' => -1,
        ],
        [
            'title' => 'Sample — Bundle (limited)', 'slug' => 'sample-bundle',
            'description' => 'Bundle a few things together as a limited drop. This one has a stock limit set — edit or delete it.',
            'price_cents' => 1200, 'min_price_cents' => 0, 'currency' => 'USD',
            'type' => 'digital', 'processor' => 'stripe', 'shipping_cents' => 0, 'variants' => '', 'stock' => 100,
        ],
    ];
    foreach ($samples as $s) {
        $wpdb->insert($tbl, array_merge($s, ['status' => 'draft', 'sold' => 0, 'created_at' => $now]));
    }
    update_option('lmeg_store_samples_seeded', 1, false);
}

/* ---------------------------------------------------------------------------
 * Front-end router: start checkout / return from Stripe / serve access link.
 * ------------------------------------------------------------------------- */
add_action('init', 'lmeg_products_router');
function lmeg_products_router() {
    if (isset($_GET['lmeg_buy']))          { lmeg_product_start_checkout(); }
    elseif (isset($_GET['lmeg_buy_done'])) { lmeg_product_checkout_return(); }
    elseif (isset($_GET['lmeg_access']))   { lmeg_product_serve_access(); }
    elseif (isset($_GET['lmeg_product']) && $_GET['lmeg_product'] !== '') { lmeg_product_page(); }
}

function lmeg_product_start_checkout() {
    global $wpdb;
    $p = lmeg_product_get((int) $_GET['lmeg_buy']);
    if (!$p || $p->status !== 'active')          { wp_die('This item is not available.'); }
    if ($p->stock >= 0 && $p->sold >= $p->stock) { wp_die('Sorry — this has sold out.'); }

    $physical = ($p->type === 'physical');

    // Variant (e.g. a size): required if the product defines any, and must be
    // in stock when quantities are tracked.
    $variant = '';
    $vlist   = lmeg_product_variants($p);
    if ($vlist) {
        $sel = isset($_GET['variant']) ? sanitize_text_field(wp_unslash($_GET['variant'])) : '';
        $match = null;
        foreach ($vlist as $vo) { if ($vo['name'] === $sel) { $match = $vo; break; } }
        if (!$match)               { wp_die('Please choose an option first.'); }
        if (!$match['available'])  { wp_die('Sorry — that option is sold out.'); }
        $variant = $match['name'];
    }

    // Demo mode — complete the order with no payment processor so the whole
    // flow can be walked. Route this single item through the cart checkout page.
    if (function_exists('lmeg_store_demo_on') && lmeg_store_demo_on()) {
        lmeg_cart_checkout_page(null, [[
            'id' => (int) $p->id, 'variant' => $variant, 'qty' => 1,
            'amount' => isset($_GET['amount']) ? (float) $_GET['amount'] : null,
        ]]);
    }

    // Item (fixed or pay-what-you-want) + flat shipping for physical.
    $item = (int) $p->price_cents;
    if (lmeg_product_is_pwyw($p)) {
        $chosen = isset($_GET['amount']) ? (int) round(((float) $_GET['amount']) * 100) : (int) $p->price_cents;
        $item = max((int) $p->min_price_cents, $chosen);
    }
    $ship  = $physical ? (int) $p->shipping_cents : 0;
    $total = $item + $ship;
    if ($total < 50) { wp_die('That amount is too low for card payment.'); }

    $ret = ['lmeg_buy_done' => $p->id];
    if ($variant !== '') $ret['v'] = $variant;

    /* ---------- Square ---------- */
    if ($p->processor === 'square') {
        if (!function_exists('lmeg_square_ready') || !lmeg_square_ready()) { wp_die('Square is not set up yet.'); }
        $link = lmeg_square_create_link($p, $total, $variant, $physical, add_query_arg($ret, home_url('/')));
        if (is_wp_error($link) || empty($link['url']) || empty($link['order_id'])) {
            wp_die('Could not start Square checkout: ' . (is_wp_error($link) ? esc_html($link->get_error_message()) : 'unknown error'));
        }
        // Pre-create a pending row keyed by the Square order id so fulfilment
        // (on return or webhook) knows the product + variant.
        $wpdb->insert($wpdb->prefix . 'lmeg_product_purchases', [
            'product_id' => $p->id, 'processor' => 'square', 'provider_ref' => $link['order_id'],
            'variant' => $variant ?: null, 'amount_cents' => $total, 'currency' => strtoupper($p->currency ?: 'USD'),
            'status' => 'pending', 'fulfillment' => 'none', 'access_count' => 0, 'access_limit' => 15,
            'created_at' => current_time('mysql'),
        ]);
        wp_redirect(esc_url_raw($link['url']));
        exit;
    }

    /* ---------- Stripe ---------- */
    $keys = lmeg_stripe_keys();
    if (empty($keys['sk'])) { wp_die('Stripe is not set up yet.'); }
    $cur     = strtolower($p->currency ?: 'usd');
    $success = add_query_arg(array_merge($ret, ['session_id' => '{CHECKOUT_SESSION_ID}']), home_url('/'));
    $cancel  = add_query_arg(['lmeg_product' => $p->slug], home_url('/'));
    $params  = [
        'mode'        => 'payment',
        'success_url' => $success,
        'cancel_url'  => $cancel,
        'line_items[0][price_data][currency]'           => $cur,
        'line_items[0][price_data][unit_amount]'        => $item,
        'line_items[0][price_data][product_data][name]' => $p->title . ($variant ? ' — ' . $variant : ''),
        'line_items[0][quantity]'                       => 1,
        'metadata[product_id]'                          => $p->id,
        'metadata[variant]'                             => $variant,
        'allow_promotion_codes'                         => 'true',
    ];
    if ($physical) {
        foreach (['US', 'CA', 'GB', 'AU', 'DE', 'FR'] as $i => $cc) {
            $params["shipping_address_collection[allowed_countries][$i]"] = $cc;
        }
        $params['phone_number_collection[enabled]'] = 'true';
        if ($ship > 0) {
            $params['shipping_options[0][shipping_rate_data][type]']                  = 'fixed_amount';
            $params['shipping_options[0][shipping_rate_data][fixed_amount][amount]']  = $ship;
            $params['shipping_options[0][shipping_rate_data][fixed_amount][currency]'] = $cur;
            $params['shipping_options[0][shipping_rate_data][display_name]']          = 'Shipping';
        }
    }
    if (!empty($p->cover_url) && filter_var($p->cover_url, FILTER_VALIDATE_URL)) {
        $params['line_items[0][price_data][product_data][images][0]'] = $p->cover_url;
    }
    $session = lmeg_stripe_request('POST', '/checkout/sessions', $params);
    if (is_wp_error($session) || empty($session['url'])) {
        wp_die('Could not start checkout: ' . (is_wp_error($session) ? esc_html($session->get_error_message()) : 'unknown error'));
    }
    wp_redirect(esc_url_raw($session['url']));
    exit;
}

/**
 * Fulfil a PAID product checkout session. Idempotent (keyed on the session id).
 * Called by the Stripe webhook and, as a fallback for webhook lag, by the
 * return page. Returns the buyer's subscriber id (0 if none).
 */
function lmeg_product_fmt_addr($a) {
    if (!is_array($a) || !$a) return '';
    return trim(implode("\n", array_filter([
        trim(($a['line1'] ?? '') . ' ' . ($a['line2'] ?? '')),
        trim(($a['city'] ?? '') . ', ' . ($a['state'] ?? '') . ' ' . ($a['postal_code'] ?? '')),
        $a['country'] ?? '',
    ])));
}

/**
 * Shared: capture the buyer as a fan (silently) + tag them for a product.
 */
function lmeg_product_capture_fan($email, $p) {
    if (!$email) return 0;
    remove_action('lmeg_subscriber_created', 'lmeg_maybe_send_welcome', 10);
    $sub_id = (int) lmeg_store_subscriber([
        'contact_type' => 'email', 'email' => $email, 'phone' => null,
        'country' => null, 'street' => null, 'city' => null, 'region' => null,
        'postal_code' => null, 'post_id' => null,
    ]);
    if ($sub_id && function_exists('lmeg_get_or_create_tag')) {
        $t = lmeg_get_or_create_tag('product:' . $p->slug, 'Bought: ' . $p->title, false, '#E15FA8');
        if ($t) lmeg_attach_tag($sub_id, $t->id);
    }
    return $sub_id;
}

/**
 * Shared: record the sale into lmeg_shop_orders (so it shows in every revenue /
 * fan / attribution surface) using a synthetic id below Shopify's range.
 */
function lmeg_product_record_revenue($p, $pur_id, $email, $amount, $cur) {
    if (!function_exists('lmeg_shop_record_order') || !$email) return;
    lmeg_shop_record_order([
        'id'           => 800000000000 + (int) $pur_id,
        'email'        => $email,
        'total_price'  => $amount / 100,
        'currency'     => $cur,
        'created_at'   => gmdate('c'),
        'order_number' => 'DROP-' . $p->id . '-' . $pur_id,
        'name'         => 'DROP-' . $p->id,
    ]);
}

/**
 * Fulfil a PAID Stripe checkout session. Idempotent (keyed on the session id).
 * Called by the webhook and, as a fallback for webhook lag, the return page.
 */
function lmeg_product_fulfill_checkout($session) {
    global $wpdb;
    $ptbl    = $wpdb->prefix . 'lmeg_product_purchases';
    $prod_id = (int) ($session['metadata']['product_id'] ?? 0);
    $sess_id = (string) ($session['id'] ?? '');
    if (!$prod_id || !$sess_id) return 0;
    if (($session['payment_status'] ?? '') !== 'paid') return 0;

    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ptbl WHERE stripe_session_id = %s", $sess_id));
    if ($existing && $existing->status === 'paid') return (int) $existing->subscriber_id;

    $p = lmeg_product_get($prod_id);
    if (!$p) return 0;
    $physical = ($p->type === 'physical');

    $email   = sanitize_email($session['customer_details']['email'] ?? ($session['customer_email'] ?? ''));
    $amount  = (int) ($session['amount_total'] ?? $p->price_cents);
    $cur     = strtoupper($session['currency'] ?? ($p->currency ?: 'USD'));
    $variant = sanitize_text_field($session['metadata']['variant'] ?? '');
    $token   = wp_generate_password(40, false, false);

    $ship_name = ''; $ship_addr = '';
    if ($physical) {
        $sd = $session['shipping_details'] ?? ($session['shipping'] ?? []);
        $ship_name = sanitize_text_field($sd['name'] ?? ($session['customer_details']['name'] ?? ''));
        $ship_addr = lmeg_product_fmt_addr($sd['address'] ?? ($session['customer_details']['address'] ?? []));
    }

    $sub_id = lmeg_product_capture_fan($email, $p);
    $fulfil = $physical ? 'unshipped' : 'none';
    $fields = [
        'subscriber_id' => $sub_id ?: null, 'email' => $email ?: null,
        'amount_cents' => $amount, 'currency' => $cur, 'processor' => 'stripe', 'provider_ref' => $sess_id,
        'variant' => $variant ?: null, 'ship_name' => $ship_name ?: null, 'ship_address' => $ship_addr ?: null,
        'fulfillment' => $fulfil, 'status' => 'paid', 'access_token' => $token, 'paid_at' => current_time('mysql'),
    ];
    if ($existing) {
        $wpdb->update($ptbl, $fields, ['id' => (int) $existing->id]);
        $pur_id = (int) $existing->id;
    } else {
        $wpdb->insert($ptbl, array_merge($fields, [
            'product_id' => $prod_id, 'stripe_session_id' => $sess_id,
            'access_count' => 0, 'access_limit' => 15, 'created_at' => current_time('mysql'),
        ]));
        $pur_id = (int) $wpdb->insert_id;
    }
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}lmeg_products SET sold = sold + 1 WHERE id = %d", $prod_id));
    lmeg_product_decrement_variant($prod_id, $variant);
    lmeg_product_record_revenue($p, $pur_id, $email, $amount, $cur);
    if ($email) lmeg_product_send_receipt($p, $email, $token, $amount, $cur, $physical, $ship_name);
    lmeg_product_notify_admin($p, $email, $amount, $cur, $physical, $variant, $ship_name, $ship_addr);
    return $sub_id;
}

/**
 * Fulfil a PAID Square order (from the return page or the Square webhook).
 * The pending purchase row was pre-created at checkout, keyed by the order id.
 */
function lmeg_product_fulfill_square($order_id) {
    global $wpdb;
    $ptbl = $wpdb->prefix . 'lmeg_product_purchases';
    $pur  = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ptbl WHERE provider_ref = %s AND processor = 'square'", (string) $order_id));
    if (!$pur) return 0;
    if ($pur->status === 'paid') return (int) $pur->subscriber_id;

    $info = function_exists('lmeg_square_order_info') ? lmeg_square_order_info($order_id) : null;
    if (!$info || empty($info['paid'])) return 0;

    $p = lmeg_product_get($pur->product_id);
    if (!$p) return 0;
    $physical = ($p->type === 'physical');
    $email  = $info['email'];
    $amount = $info['amount'] ?: (int) $pur->amount_cents;
    $cur    = $info['cur'] ?: $pur->currency;
    $token  = wp_generate_password(40, false, false);

    $sub_id = lmeg_product_capture_fan($email, $p);
    $wpdb->update($ptbl, [
        'subscriber_id' => $sub_id ?: null, 'email' => $email ?: null,
        'amount_cents' => $amount, 'currency' => $cur, 'status' => 'paid', 'access_token' => $token,
        'ship_name' => $info['ship_name'] ?: null, 'ship_address' => $info['ship_addr'] ?: null,
        'fulfillment' => $physical ? 'unshipped' : 'none', 'paid_at' => current_time('mysql'),
    ], ['id' => (int) $pur->id]);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}lmeg_products SET sold = sold + 1 WHERE id = %d", $pur->product_id));
    lmeg_product_decrement_variant($pur->product_id, (string) $pur->variant);
    lmeg_product_record_revenue($p, (int) $pur->id, $email, $amount, $cur);
    if ($email) lmeg_product_send_receipt($p, $email, $token, $amount, $cur, $physical, $info['ship_name']);
    lmeg_product_notify_admin($p, $email, $amount, $cur, $physical, (string) $pur->variant, $info['ship_name'], $info['ship_addr']);
    return $sub_id;
}

function lmeg_product_access_url($token) {
    return add_query_arg(['lmeg_access' => $token], home_url('/'));
}

function lmeg_product_send_receipt($p, $email, $token, $amount, $cur, $physical = false, $ship_name = '') {
    if (!$email || !function_exists('lmeg_email_deliver')) return;
    $artist = lmeg_email_artist();
    $price  = function_exists('lmeg_format_price') ? lmeg_format_price($amount, $cur) : ('$' . number_format($amount / 100, 2));
    $table  = lmeg_email_order_table([['name' => $p->title, 'meta' => '', 'amount' => $price]], $price);

    if ($physical) {
        $subject = 'Order confirmed: ' . $p->title;
        $inner   = lmeg_email_h('Order confirmed 🎉')
                 . lmeg_email_p('Thanks for your order — we\'re on it. Here\'s what\'s coming your way:')
                 . $table
                 . ($ship_name ? lmeg_email_ship_block($ship_name, '') : '')
                 . lmeg_email_note('We\'ll get it on its way and email you if we need anything.');
        $pre = 'Your order of ' . $p->title . ' is confirmed.';
    } else {
        $access  = lmeg_product_access_url($token);
        $subject = 'Your download: ' . $p->title;
        $inner   = lmeg_email_h('Thanks for your purchase 💜')
                 . lmeg_email_p('You just picked up <strong>' . esc_html($p->title) . '</strong> — your download\'s ready below.')
                 . $table
                 . lmeg_email_download_block([['name' => $p->title, 'url' => $access]])
                 . lmeg_email_note('Trouble with the link? Just reply to this email and ' . esc_html($artist) . ' can help.');
        $pre = 'Your download of ' . $p->title . ' is ready.';
    }
    lmeg_email_deliver($email, $subject, $inner, $pre);
}

/**
 * Notify the artist/admin of a new sale (branded HTML). Off if the Store
 * notification toggle is disabled; sends to the configured address, or the site
 * admin email by default. Fires for both processors, digital and physical.
 */
function lmeg_product_notify_admin($p, $email, $amount, $cur, $physical, $variant = '', $ship_name = '', $ship_addr = '') {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    if (isset($s['store_notify']) && !$s['store_notify']) return;         // explicitly disabled
    $to = trim((string) ($s['store_notify_email'] ?? '')) ?: get_option('admin_email');
    if (!$to || !is_email($to) || !function_exists('lmeg_email_deliver')) return;

    $price   = function_exists('lmeg_format_price') ? lmeg_format_price($amount, $cur) : ('$' . number_format($amount / 100, 2));
    $subject = ($physical ? '🛍️ New order: ' : '💿 New sale: ') . $p->title . ' — ' . $price;
    $name    = $p->title . ($variant ? ' · ' . $variant : '');
    $inner   = lmeg_email_h($physical ? 'New order 🛍️' : 'New sale 💿')
             . lmeg_email_p('A new ' . ($physical ? 'order' : 'sale') . ' just landed in your store.')
             . lmeg_email_order_table([['name' => $name, 'meta' => 'Buyer: ' . ($email ?: 'unknown'), 'amount' => $price]], $price);
    if ($physical) {
        $inner .= lmeg_email_ship_block($ship_name ?: '(no name given)', $ship_addr)
                . lmeg_email_button('Orders to ship →', admin_url('admin.php?page=lmeg-products#orders'));
    } else {
        $inner .= lmeg_email_p('The buyer got their download automatically.')
                . lmeg_email_button('Open your Store →', admin_url('admin.php?page=lmeg-products'));
    }
    lmeg_email_deliver($to, $subject, $inner, ($physical ? 'New order' : 'New sale') . ': ' . $p->title . ' — ' . $price);
}

function lmeg_product_serve_access() {
    global $wpdb;
    $token = sanitize_text_field($_GET['lmeg_access'] ?? '');
    $ptbl  = $wpdb->prefix . 'lmeg_product_purchases';
    $pur   = $token ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $ptbl WHERE access_token = %s", $token)) : null;
    if (!$pur || $pur->status !== 'paid')              { wp_die('This link is not valid.'); }
    if ((int) $pur->access_count >= (int) $pur->access_limit) { wp_die('This download link has reached its access limit. Reply to your receipt email and the artist can help.'); }
    $p = lmeg_product_get($pur->product_id);
    if (!$p) { wp_die('This item is unavailable. Please contact the artist.'); }

    // Uploaded file → stream it privately through PHP (the file is never a
    // public URL). Otherwise fall back to the pasted unlock link.
    if (!empty($p->file_path)) {
        $up  = wp_upload_dir();
        $abs = trailingslashit($up['basedir']) . ltrim($p->file_path, '/');
        if (!is_file($abs)) { wp_die('The file is missing. Please contact the artist.'); }
        $wpdb->query($wpdb->prepare("UPDATE $ptbl SET access_count = access_count + 1 WHERE id = %d", (int) $pur->id));
        lmeg_product_stream_file($abs, $p->file_name ?: basename($abs));
        exit;
    }
    if (empty($p->deliver_url)) { wp_die('This item has no download set yet. Please contact the artist.'); }
    $wpdb->query($wpdb->prepare("UPDATE $ptbl SET access_count = access_count + 1 WHERE id = %d", (int) $pur->id));
    wp_redirect(esc_url_raw($p->deliver_url));
    exit;
}

/* ---------------------------------------------------------------------------
 * Private file storage + streaming (for uploaded digital goods)
 * ------------------------------------------------------------------------- */
function lmeg_product_private_dir() {
    $up  = wp_upload_dir();
    $dir = trailingslashit($up['basedir']) . 'fanloop-private';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

/**
 * Move an uploaded product file into private storage. Returns
 * ['path','name','size'] (path relative to the uploads basedir), ['error'=>..],
 * or null when no file was sent.
 */
function lmeg_product_handle_upload() {
    if (empty($_FILES['product_file']['tmp_name']) || !is_uploaded_file($_FILES['product_file']['tmp_name'])) return null;
    if (($_FILES['product_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => 'The file did not upload (it may be larger than the server allows).'];
    }
    $orig  = sanitize_file_name($_FILES['product_file']['name']);
    $ext   = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allow = ['mp3', 'wav', 'flac', 'aac', 'm4a', 'ogg', 'zip', 'pdf', 'mp4', 'mov', 'm4v', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt', 'epub'];
    if (!in_array($ext, $allow, true)) {
        return ['error' => 'That file type is not allowed. Use audio, zip, pdf, video, image, txt or epub.'];
    }
    $dir  = lmeg_product_private_dir();
    $rand = wp_generate_password(32, false, false) . '.' . $ext;
    $dest = trailingslashit($dir) . $rand;
    if (!@move_uploaded_file($_FILES['product_file']['tmp_name'], $dest)) {
        return ['error' => 'Could not save the file — check the uploads folder is writable.'];
    }
    return ['path' => 'fanloop-private/' . $rand, 'name' => $orig, 'size' => (int) @filesize($dest)];
}

function lmeg_product_delete_file($rel) {
    if (!$rel) return;
    $up  = wp_upload_dir();
    $abs = trailingslashit($up['basedir']) . ltrim($rel, '/');
    if (is_file($abs) && strpos((string) realpath($abs), (string) realpath($up['basedir'])) === 0) @unlink($abs);
}

function lmeg_product_stream_file($abs, $filename) {
    if (function_exists('set_time_limit')) @set_time_limit(0);
    while (ob_get_level() > 0) @ob_end_clean();
    nocache_headers();
    $ft = wp_check_filetype($filename);
    header('Content-Type: ' . ($ft['type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Content-Length: ' . (int) @filesize($abs));
    header('X-Content-Type-Options: nosniff');
    $fh = @fopen($abs, 'rb');
    if ($fh) { while (!feof($fh)) { echo fread($fh, 8192); @flush(); } fclose($fh); }
}

/**
 * Return page after Stripe or Square. Fulfils inline if the webhook hasn't
 * landed yet, then shows a self-contained confirmation.
 */
function lmeg_product_checkout_return() {
    global $wpdb;
    $p        = lmeg_product_get((int) $_GET['lmeg_buy_done']);
    $ptbl     = $wpdb->prefix . 'lmeg_product_purchases';
    $sess_id  = sanitize_text_field($_GET['session_id'] ?? '');
    $sq_order = sanitize_text_field($_GET['orderId'] ?? ($_GET['order_id'] ?? ''));
    $pur      = null;

    if ($sess_id) {
        $pur = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ptbl WHERE stripe_session_id = %s", $sess_id));
        if (!$pur || $pur->status !== 'paid') {
            $s = lmeg_stripe_request('GET', '/checkout/sessions/' . rawurlencode($sess_id));
            if (!is_wp_error($s) && ($s['payment_status'] ?? '') === 'paid') {
                lmeg_product_fulfill_checkout($s);
                $pur = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ptbl WHERE stripe_session_id = %s", $sess_id));
            }
        }
    } elseif ($sq_order && function_exists('lmeg_product_fulfill_square')) {
        lmeg_product_fulfill_square($sq_order);
        $pur = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ptbl WHERE provider_ref = %s AND processor = 'square'", $sq_order));
    }

    $paid     = ($pur && $pur->status === 'paid');
    $physical = ($p && $p->type === 'physical');
    $access   = ($paid && !$physical && $pur->access_token) ? lmeg_product_access_url($pur->access_token) : '';
    $title    = $p ? $p->title : 'your order';

    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo $paid ? 'Thank you' : 'Processing'; ?> · <?php echo esc_html(get_bloginfo('name')); ?></title>
    <style>
      :root{color-scheme:dark}
      *{box-sizing:border-box;margin:0}body{background:#0B0C12;color:#F4F2F7;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px}
      .card{max-width:520px;text-align:center;background:linear-gradient(160deg,#161826,#12141f);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:44px 34px;box-shadow:0 30px 90px rgba(0,0,0,.5)}
      .dot{width:54px;height:54px;border-radius:50%;margin:0 auto 20px;background:linear-gradient(118deg,#E15FA8,#8A6CF6);display:grid;place-items:center;font-size:28px}
      h1{font-size:27px;font-weight:820;letter-spacing:-.02em;margin-bottom:10px}
      p{color:#B9BCC9;line-height:1.55;margin-bottom:22px}
      .btn{display:inline-block;background:linear-gradient(118deg,#E15FA8,#8A6CF6);color:#0B0C12;font-weight:750;text-decoration:none;padding:15px 30px;border-radius:12px}
      .home{display:block;margin-top:22px;color:#8B90A0;font-size:13px;text-decoration:none}
      .lnk{color:#8B90A0;font-size:12px;word-break:break-all;margin-top:16px}
    </style></head><body>
    <div class="card">
      <?php if ($paid && $physical) : ?>
        <div class="dot">✓</div>
        <h1>Order confirmed. Thank you!</h1>
        <p>Your order of <strong><?php echo esc_html($title); ?></strong> is in — we'll get it shipped to you. A receipt is on its way to your inbox.</p>
      <?php elseif ($paid) : ?>
        <div class="dot">✓</div>
        <h1>You're in. Thank you!</h1>
        <p>Your purchase of <strong><?php echo esc_html($title); ?></strong> is complete, and it's on its way to your inbox too.</p>
        <?php if ($access) : ?><a class="btn" href="<?php echo esc_url($access); ?>">Get your download →</a><div class="lnk"><?php echo esc_html($access); ?></div><?php endif; ?>
      <?php else : ?>
        <div class="dot">…</div>
        <h1>Payment processing</h1>
        <p>Hang tight — your payment is being confirmed. Check your email in a moment for your receipt. You can safely close this page.</p>
      <?php endif; ?>
      <a class="home" href="<?php echo esc_url(home_url('/')); ?>">← Back to site</a>
    </div></body></html><?php
    exit;
}

/* ---------------------------------------------------------------------------
 * Shortcode — [fanloop_product id=".." ] / [loony_product slug=".."]
 * ------------------------------------------------------------------------- */
add_shortcode('fanloop_product', 'lmeg_shortcode_product');
add_shortcode('loony_product', 'lmeg_shortcode_product');
function lmeg_shortcode_product($atts) {
    $atts = shortcode_atts(['id' => 0, 'slug' => ''], $atts, 'fanloop_product');
    $p = $atts['id'] ? lmeg_product_get((int) $atts['id']) : ($atts['slug'] ? lmeg_product_by_slug($atts['slug']) : null);
    if (!$p) return '';
    return '<div class="flp-prod-wrap" style="max-width:420px">' . lmeg_product_card_html($p) . '</div>';
}

/* Storefront — [fanloop_store] / [loony_store] lists all active products. */
add_shortcode('fanloop_store', 'lmeg_shortcode_store');
add_shortcode('loony_store', 'lmeg_shortcode_store');
function lmeg_shortcode_store($atts) {
    static $store_n = 0; $store_n++;
    $atts = shortcode_atts(['type' => 'all', 'min' => 240, 'controls' => 'auto'], $atts, 'fanloop_store');
    global $wpdb;
    $where = "status = 'active'";
    if (in_array($atts['type'], ['digital', 'physical'], true)) $where .= $wpdb->prepare(' AND type = %s', $atts['type']);
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lmeg_products WHERE $where ORDER BY id DESC");
    if (!$rows) return '<p style="opacity:.7">Nothing in the shop yet — check back soon.</p>';
    $min = max(160, min(360, (int) $atts['min']));
    $uid = 'flpstore' . $store_n;

    // Search + sort controls — shown once the shop has enough items to warrant it
    // (or forced with controls="on" / hidden with controls="off").
    $show_ctrls = ($atts['controls'] === 'on') || ($atts['controls'] !== 'off' && count($rows) >= 5);
    $ctrl_css = 'padding:10px 13px;border:1px solid rgba(0,0,0,.18);border-radius:10px;background:#fff;color:#17141f;font-size:14px';
    $controls = '';
    if ($show_ctrls) {
        $controls = '<div class="flp-store-ctrls" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px">'
            . '<input type="search" class="flp-q" placeholder="Search the shop…" aria-label="Search the shop" style="flex:1;min-width:180px;' . $ctrl_css . '">'
            . '<select class="flp-sort" aria-label="Sort products" style="' . $ctrl_css . ';cursor:pointer">'
            . '<option value="featured">Featured</option><option value="new">Newest</option>'
            . '<option value="price-asc">Price: low to high</option><option value="price-desc">Price: high to low</option>'
            . '<option value="sold">Best selling</option><option value="name">Name A–Z</option></select></div>';
    }

    $out = '<div class="flp-store-wrap" id="' . $uid . '">' . $controls
        . '<p class="flp-store-none" style="display:none;color:#6b6b78;padding:6px 2px">No products match your search.</p>'
        . '<div class="flp-store" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(' . $min . 'px,1fr));gap:20px;align-items:stretch">';
    foreach ($rows as $p) $out .= lmeg_product_card_html($p);
    $out .= '</div></div>';

    if ($show_ctrls) {
        $out .= '<script>(function(){var root=document.getElementById(' . wp_json_encode($uid) . ');if(!root)return;'
            . 'var grid=root.querySelector(".flp-store"),none=root.querySelector(".flp-store-none"),q=root.querySelector(".flp-q"),sort=root.querySelector(".flp-sort");'
            . 'var cards=[].slice.call(grid.querySelectorAll(".flp-prod")),orig=cards.slice();'
            . 'function num(c,a){return parseInt(c.getAttribute(a),10)||0;}'
            . 'function apply(){var t=((q&&q.value)||"").trim().toLowerCase();'
            . 'var vis=cards.filter(function(c){if(!t)return true;return((c.getAttribute("data-title")||"")+" "+(c.getAttribute("data-desc")||"")).toLowerCase().indexOf(t)>=0;});'
            . 'var s=sort?sort.value:"featured",arr;'
            . 'if(s==="new")arr=vis.slice().sort(function(a,b){return num(b,"data-id")-num(a,"data-id");});'
            . 'else if(s==="price-asc")arr=vis.slice().sort(function(a,b){return num(a,"data-price")-num(b,"data-price");});'
            . 'else if(s==="price-desc")arr=vis.slice().sort(function(a,b){return num(b,"data-price")-num(a,"data-price");});'
            . 'else if(s==="sold")arr=vis.slice().sort(function(a,b){return num(b,"data-sold")-num(a,"data-sold");});'
            . 'else if(s==="name")arr=vis.slice().sort(function(a,b){return(a.getAttribute("data-title")||"").localeCompare(b.getAttribute("data-title")||"");});'
            . 'else arr=orig.filter(function(c){return vis.indexOf(c)>=0;});'
            . 'cards.forEach(function(c){c.style.display="none";});arr.forEach(function(c){c.style.display="";grid.appendChild(c);});'
            . 'if(none)none.style.display=arr.length?"none":"";}'
            . 'if(q)q.addEventListener("input",apply);if(sort)sort.addEventListener("change",apply);})();</script>';
    }
    return $out;
}

/**
 * Render a single product card (fills its container's width/height, so it works
 * standalone or inside the storefront grid).
 */
function lmeg_product_card_html($p, $link = true) {
    $cur      = $p->currency ?: 'USD';
    $fmt      = function ($c) use ($cur) { return function_exists('lmeg_format_price') ? lmeg_format_price((int) $c, $cur) : ('$' . number_format($c / 100, 2)); };
    $price    = $fmt($p->price_cents);
    $pwyw     = lmeg_product_is_pwyw($p);
    $physical = ($p->type === 'physical');
    $vlist    = lmeg_product_variants($p);
    $ship     = ($physical && (int) $p->shipping_cents > 0) ? $fmt($p->shipping_cents) : '';
    $any_var  = true;
    if ($vlist) { $any_var = false; foreach ($vlist as $v) { if ($v['available']) { $any_var = true; break; } } }
    $sold_out = ($p->status !== 'active') || ($p->stock >= 0 && $p->sold >= $p->stock) || !$any_var;
    $needs_form = $pwyw || !empty($vlist);
    // Low-stock urgency: only when a limit is actually set and running low.
    $remaining = ($p->stock >= 0) ? max(0, (int) $p->stock - (int) $p->sold) : null;
    $low_stock = ($remaining !== null && $remaining > 0 && $remaining <= 10);
    $url = esc_url(lmeg_product_url($p));
    $GLOBALS['lmeg_store_seen'] = true;   // tell wp_footer to print the cart UI

    // Data the "Add to cart" button carries into the client-side cart.
    $data = 'class="flp-add" data-id="' . (int) $p->id . '" data-slug="' . esc_attr($p->slug)
          . '" data-title="' . esc_attr($p->title) . '" data-cover="' . esc_attr($p->cover_url)
          . '" data-price="' . (int) $p->price_cents . '" data-cur="' . esc_attr($cur)
          . '" data-type="' . esc_attr($p->type) . '" data-ship="' . ($physical ? (int) $p->shipping_cents : 0)
          . '" data-pwyw="' . ($pwyw ? 1 : 0) . '" data-min="' . (int) $p->min_price_cents
          . '" data-hasvar="' . (!empty($vlist) ? 1 : 0) . '"';
    $add_pri = 'style="background:#E15FA8;color:#fff;border:0;font-weight:700;padding:11px 18px;border-radius:10px;cursor:pointer;flex:1;font-size:14px"';
    $buy_sec = 'style="background:#fff;color:#E15FA8;border:1px solid #E15FA8;font-weight:700;padding:10px 16px;border-radius:10px;cursor:pointer;text-decoration:none;font-size:14px;white-space:nowrap"';
    ob_start(); ?>
    <div class="flp-prod" data-title="<?php echo esc_attr($p->title); ?>" data-desc="<?php echo esc_attr($p->description); ?>" data-price="<?php echo (int) $p->price_cents; ?>" data-sold="<?php echo (int) $p->sold; ?>" data-id="<?php echo (int) $p->id; ?>" style="display:flex;flex-direction:column;width:100%;height:100%;border:1px solid rgba(0,0,0,.12);border-radius:16px;overflow:hidden;font-family:inherit;background:#fff;color:#17141f;box-shadow:0 12px 40px rgba(0,0,0,.08)">
      <?php if (!empty($p->cover_url)) : $img = '<img src="' . esc_url($p->cover_url) . '" alt="' . esc_attr($p->title) . '" style="width:100%;display:block;aspect-ratio:1/1;object-fit:cover">';
        echo $link ? '<a href="' . $url . '" style="display:block">' . $img . '</a>' : $img; ?>
      <?php endif; ?>
      <div style="padding:18px 20px;display:flex;flex-direction:column;flex:1">
        <div style="font-weight:750;font-size:19px;margin-bottom:4px;color:#17141f"><?php echo $link ? '<a href="' . $url . '" style="color:#17141f;text-decoration:none">' . esc_html($p->title) . '</a>' : esc_html($p->title); ?><?php if ($physical) : ?> <span style="font-size:11px;color:#6b6b78;font-weight:600;vertical-align:middle">· ships</span><?php endif; ?></div>
        <?php if (!empty($p->description)) : ?><div style="font-size:14px;color:#454552;line-height:1.5;margin-bottom:14px"><?php echo esc_html($p->description); ?></div><?php endif; ?>
        <div style="margin-top:auto">
        <?php if (!$sold_out && $low_stock) : ?><div style="font-size:12px;font-weight:700;color:#B45309;background:#FEF3C7;display:inline-block;padding:2px 10px;border-radius:999px;margin-bottom:9px">🔥 Only <?php echo (int) $remaining; ?> left</div>
        <?php elseif (!$sold_out && (int) $p->sold >= 5) : ?><div style="font-size:12px;font-weight:700;color:#047857;background:#ECFDF5;display:inline-block;padding:2px 10px;border-radius:999px;margin-bottom:9px">★ <?php echo esc_html(number_format((int) $p->sold)); ?> sold</div><?php endif; ?>
        <?php if ($sold_out) : ?>
          <div style="font-weight:700;color:#6b6b78">Sold out</div>
        <?php elseif ($needs_form) : ?>
          <form method="get" action="<?php echo esc_url(home_url('/')); ?>" style="display:flex;flex-direction:column;gap:9px">
            <input type="hidden" name="lmeg_buy" value="<?php echo (int) $p->id; ?>">
            <?php if (!empty($vlist) || $pwyw) : ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
              <?php if (!empty($vlist)) : ?>
                <select name="variant" required style="padding:9px;border:1px solid #ccc;border-radius:8px;color:#17141f;background:#fff"><option value="" disabled selected>Choose…</option><?php foreach ($vlist as $v) : ?><option value="<?php echo esc_attr($v['name']); ?>" <?php echo $v['available'] ? '' : 'disabled'; ?>><?php echo esc_html($v['name']) . ($v['available'] ? ($v['stock'] !== null && $v['stock'] <= 5 ? ' — ' . (int) $v['stock'] . ' left' : '') : ' — sold out'); ?></option><?php endforeach; ?></select>
              <?php endif; ?>
              <?php if ($pwyw) : ?>
                <input type="number" name="amount" min="<?php echo esc_attr(number_format($p->min_price_cents / 100, 2, '.', '')); ?>" step="0.01" value="<?php echo esc_attr(number_format(max($p->price_cents, $p->min_price_cents) / 100, 2, '.', '')); ?>" style="width:96px;padding:9px;border:1px solid #ccc;border-radius:8px;color:#17141f;background:#fff" aria-label="Name your price">
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <div style="display:flex;gap:8px;align-items:center">
              <button type="button" <?php echo $data; ?> <?php echo $add_pri; ?>><?php echo $pwyw ? 'Add to cart' : esc_html('Add · ' . $price); ?></button>
              <button type="submit" <?php echo $buy_sec; ?>>Buy now</button>
            </div>
          </form>
          <div style="font-size:12px;color:#6b6b78;margin-top:6px"><?php echo $pwyw ? 'Name your price · min ' . esc_html($fmt($p->min_price_cents)) : ''; ?><?php echo $ship ? ($pwyw ? ' · ' : '') . '+ ' . esc_html($ship) . ' shipping' : ''; ?></div>
        <?php else : ?>
          <div style="display:flex;gap:8px;align-items:center">
            <button type="button" <?php echo $data; ?> <?php echo $add_pri; ?>><?php echo esc_html('Add · ' . $price); ?></button>
            <a href="<?php echo esc_url(add_query_arg(['lmeg_buy' => $p->id], home_url('/'))); ?>" <?php echo $buy_sec; ?>>Buy now</a>
          </div>
          <?php if ($ship) : ?><div style="font-size:12px;color:#6b6b78;margin-top:6px">+ <?php echo esc_html($ship); ?> shipping</div><?php endif; ?>
        <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Hosted, shareable product page at ?lmeg_product=<slug>. Standalone on-brand
 * page centering the product card so each product has its own URL.
 */
function lmeg_product_page() {
    $p = lmeg_product_by_slug(sanitize_title(wp_unslash($_GET['lmeg_product'])));
    if (!$p || $p->status !== 'active') { status_header(404); nocache_headers(); wp_die('This product is not available.', 'Not found', ['response' => 404]); }
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    $site = get_bloginfo('name');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo esc_html($p->title . ' · ' . $site); ?></title>
    <meta property="og:title" content="<?php echo esc_attr($p->title); ?>">
    <?php if (!empty($p->description)) : ?><meta name="description" content="<?php echo esc_attr($p->description); ?>"><meta property="og:description" content="<?php echo esc_attr($p->description); ?>"><?php endif; ?>
    <?php if (!empty($p->cover_url)) : ?><meta property="og:image" content="<?php echo esc_url($p->cover_url); ?>"><?php endif; ?>
    <style>
      *{box-sizing:border-box;margin:0}body{background:#0B0C12;color:#F4F2F7;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px 20px}
      .wrap{width:100%;max-width:440px}
      .back{display:block;text-align:center;margin-top:22px;color:#8B90A0;font-size:13px;text-decoration:none}
      .sig{margin-top:10px;text-align:center;color:#6C6F82;font-size:12px}
    </style></head><body>
    <div class="wrap">
      <?php echo lmeg_product_card_html($p, false); ?>
      <a class="back" href="<?php echo esc_url(home_url('/')); ?>">← <?php echo esc_html($site); ?></a>
    </div>
    <?php if (function_exists('lmeg_cart_assets_html')) echo lmeg_cart_assets_html(); ?>
    </body></html><?php
    exit;
}

/* ---------------------------------------------------------------------------
 * Admin — Store page (list + create/edit + sales)
 * ------------------------------------------------------------------------- */
add_action('admin_post_lmeg_save_product', 'lmeg_handle_save_product');
function lmeg_handle_save_product() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_save_product', 'lmeg_product_nonce');
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_products';
    $id  = (int) ($_POST['product_id'] ?? 0);

    if (($_POST['do'] ?? '') === 'delete' && $id) {
        $old = lmeg_product_get($id);
        if ($old && !empty($old->file_path)) lmeg_product_delete_file($old->file_path);
        $wpdb->delete($tbl, ['id' => $id]);
        wp_safe_redirect(admin_url('admin.php?page=lmeg-products&deleted=1')); exit;
    }

    // Uploaded digital file (optional). Fail fast on a bad upload.
    $file = lmeg_product_handle_upload();
    if (is_array($file) && !empty($file['error'])) {
        set_transient('lmeg_product_file_err', $file['error'], 60);
        wp_safe_redirect(admin_url('admin.php?page=lmeg-products' . ($id ? '&edit=' . $id : '&new=1') . '&err=file')); exit;
    }

    $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    if ($title === '') { wp_safe_redirect(admin_url('admin.php?page=lmeg-products&err=title')); exit; }
    $slug  = sanitize_title($_POST['slug'] ?? $title) ?: sanitize_title($title);
    // keep slug unique
    $clash = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $tbl WHERE slug = %s AND id <> %d", $slug, $id));
    if ($clash) $slug .= '-' . wp_generate_password(4, false, false);

    $to_cents = function ($v) { return (int) round(((float) preg_replace('/[^0-9.]/', '', (string) $v)) * 100); };

    // Variants: "S, M, L" (names) or "S:10, M:5, L" to track quantities per option.
    $v_names = []; $v_stock = [];
    foreach (explode(',', (string) wp_unslash($_POST['variants'] ?? '')) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (strpos($part, ':') !== false) {
            list($vn, $vq) = array_map('trim', explode(':', $part, 2));
            $vn = sanitize_text_field($vn);
            if ($vn === '') continue;
            $v_names[] = $vn;
            if ($vq !== '' && is_numeric($vq)) $v_stock[$vn] = max(0, (int) $vq);
        } else {
            $v_names[] = sanitize_text_field($part);
        }
    }

    $data = [
        'title'           => $title,
        'slug'            => $slug,
        'description'     => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
        'cover_url'       => esc_url_raw($_POST['cover_url'] ?? ''),
        'price_cents'     => max(0, $to_cents($_POST['price'] ?? 0)),
        'min_price_cents' => !empty($_POST['pwyw']) ? max(0, $to_cents($_POST['min_price'] ?? 0)) : 0,
        'currency'        => strtoupper(substr(sanitize_text_field($_POST['currency'] ?? 'USD'), 0, 3)) ?: 'USD',
        'type'            => in_array($_POST['type'] ?? 'digital', ['digital', 'physical'], true) ? $_POST['type'] : 'digital',
        'processor'       => in_array($_POST['processor'] ?? 'stripe', ['stripe', 'square'], true) ? $_POST['processor'] : 'stripe',
        'shipping_cents'  => max(0, $to_cents($_POST['shipping'] ?? 0)),
        'variants'        => implode(', ', $v_names),
        'variant_stock'   => $v_stock ? wp_json_encode($v_stock) : null,
        'deliver_url'     => esc_url_raw($_POST['deliver_url'] ?? ''),
        'deliver_note'    => sanitize_textarea_field(wp_unslash($_POST['deliver_note'] ?? '')),
        'stock'           => ($_POST['stock'] ?? '') === '' ? -1 : max(0, (int) $_POST['stock']),
        'status'          => in_array($_POST['status'] ?? 'active', ['active', 'draft'], true) ? $_POST['status'] : 'active',
    ];

    // Attach a newly uploaded file, or remove the current one on request.
    $old = $id ? lmeg_product_get($id) : null;
    if (is_array($file) && !empty($file['path'])) {
        $data['file_path'] = $file['path'];
        $data['file_name'] = $file['name'];
        $data['file_size'] = (int) $file['size'];
        if ($old && !empty($old->file_path) && $old->file_path !== $file['path']) lmeg_product_delete_file($old->file_path);
    } elseif (!empty($_POST['remove_file']) && $old && !empty($old->file_path)) {
        lmeg_product_delete_file($old->file_path);
        $data['file_path'] = null; $data['file_name'] = null; $data['file_size'] = 0;
    }

    if ($id) { $wpdb->update($tbl, $data, ['id' => $id]); }
    else     { $data['sold'] = 0; $data['created_at'] = current_time('mysql'); $wpdb->insert($tbl, $data); $id = (int) $wpdb->insert_id; }
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products&saved=' . $id)); exit;
}

add_action('admin_post_lmeg_ship_order', 'lmeg_handle_ship_order');
function lmeg_handle_ship_order() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_ship_order', 'lmeg_ship_nonce');
    global $wpdb;
    $pid = (int) ($_POST['purchase_id'] ?? 0);
    $to  = (($_POST['to'] ?? 'shipped') === 'unshipped') ? 'unshipped' : 'shipped';
    if ($pid) $wpdb->update($wpdb->prefix . 'lmeg_product_purchases', ['fulfillment' => $to], ['id' => $pid]);
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products&shipped=1#orders')); exit;
}

/**
 * Clear all DEMO (no-payment) test orders. Removes the demo purchase rows and
 * their synthetic revenue rows, restores each product's sold count and any
 * per-variant stock the test sales consumed — leaving real sales untouched.
 */
add_action('admin_post_lmeg_clear_demo', 'lmeg_handle_clear_demo');
function lmeg_handle_clear_demo() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_clear_demo', 'lmeg_demo_nonce');
    global $wpdb;
    $ptbl = $wpdb->prefix . 'lmeg_product_purchases';
    $tbl  = $wpdb->prefix . 'lmeg_products';

    $rows = $wpdb->get_results("SELECT id, product_id, qty, variant FROM $ptbl WHERE processor = 'demo'");
    $dec = []; $restock = []; $oids = [];
    foreach ($rows as $r) {
        $pid = (int) $r->product_id; $q = max(1, (int) $r->qty);
        $dec[$pid] = ($dec[$pid] ?? 0) + $q;
        if ($r->variant) $restock[$pid][$r->variant] = ($restock[$pid][$r->variant] ?? 0) + $q;
        $oids[] = 800000000000 + (int) $r->id;   // synthetic lmeg_shop_orders id
    }
    foreach ($dec as $pid => $q) {
        $wpdb->query($wpdb->prepare("UPDATE $tbl SET sold = GREATEST(0, CAST(sold AS SIGNED) - %d) WHERE id = %d", $q, $pid));
    }
    foreach ($restock as $pid => $vmap) {
        $p = lmeg_product_get($pid);
        if (!$p || empty($p->variant_stock)) continue;
        $stock = json_decode($p->variant_stock, true);
        if (!is_array($stock)) continue;
        foreach ($vmap as $vn => $q) { if (array_key_exists($vn, $stock)) $stock[$vn] = (int) $stock[$vn] + (int) $q; }
        $wpdb->update($tbl, ['variant_stock' => wp_json_encode($stock)], ['id' => $pid]);
    }
    if ($oids) {
        $in = implode(',', array_map('intval', $oids));
        $wpdb->query("DELETE FROM {$wpdb->prefix}lmeg_shop_orders WHERE shopify_order_id IN ($in)");
    }
    $n = (int) $wpdb->query("DELETE FROM $ptbl WHERE processor = 'demo'");
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products&cleared=' . $n)); exit;
}

function lmeg_admin_products() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $tbl  = $wpdb->prefix . 'lmeg_products';
    $ptbl = $wpdb->prefix . 'lmeg_product_purchases';
    $edit = isset($_GET['edit']) ? lmeg_product_get((int) $_GET['edit']) : null;
    $new  = isset($_GET['new']);
    $keys = lmeg_stripe_keys();
    $save = admin_url('admin-post.php');

    $sq_ready = function_exists('lmeg_square_ready') && lmeg_square_ready();
    echo '<div class="wrap"><h1>Fanloop — Store <span style="font-size:12px;vertical-align:middle;background:rgba(225,95,168,.16);color:#E15FA8;padding:3px 10px;border-radius:999px;">BETA · digital + physical</span></h1>';
    if (empty($keys['sk']) && !$sq_ready) {
        echo '<div class="notice notice-warning"><p>Connect a payment processor first (Settings → Payments) — that\'s where the money lands. You can create products now, but buyers can\'t check out until <strong>Stripe</strong> or <strong>Square</strong> keys are saved.</p></div>';
    }
    if (isset($_GET['saved']))   echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
    if (isset($_GET['deleted'])) echo '<div class="notice notice-success is-dismissible"><p>Deleted.</p></div>';
    if (isset($_GET['shipped'])) echo '<div class="notice notice-success is-dismissible"><p>Order updated.</p></div>';
    if (isset($_GET['cleared'])) echo '<div class="notice notice-success is-dismissible"><p>Cleared ' . (int) $_GET['cleared'] . ' test order' . ((int) $_GET['cleared'] === 1 ? '' : 's') . '.</p></div>';
    if (function_exists('lmeg_store_demo_on') && lmeg_store_demo_on()) {
        echo '<div class="notice notice-warning" style="border-left-color:#E15FA8"><p>🧪 <strong>Demo checkout is ON.</strong> Orders complete <strong>without payment</strong> so you can walk the whole flow (cart → receipt → download → fan captured). These show up as normal sales, tagged <em>demo</em>. <strong>Turn it off before you go live</strong> in <a href="' . esc_url(admin_url('admin.php?page=lmeg-settings#payments')) . '">Settings → Payments</a> — while it\'s on, no money is collected.</p></div>';
    }

    /* ----- create / edit form ----- */
    if ($new || $edit) {
        wp_enqueue_media(); // WordPress media library picker for the cover image
        $p = $edit ?: (object) ['id'=>0,'title'=>'','slug'=>'','description'=>'','cover_url'=>'','price_cents'=>0,'min_price_cents'=>0,'currency'=>'USD','type'=>'digital','processor'=>'stripe','shipping_cents'=>0,'variants'=>'','variant_stock'=>'','deliver_url'=>'','deliver_note'=>'','file_path'=>'','file_name'=>'','file_size'=>0,'stock'=>-1,'status'=>'active'];
        $money = function ($c) { return number_format(((int) $c) / 100, 2, '.', ''); };
        if (isset($_GET['err']) && $_GET['err'] === 'file') { echo '<div class="notice notice-error"><p>' . esc_html(get_transient('lmeg_product_file_err') ?: 'That file could not be uploaded.') . '</p></div>'; delete_transient('lmeg_product_file_err'); }
        ?>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-products')); ?>">← All products</a></p>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url($save); ?>" style="max-width:720px;">
            <?php wp_nonce_field('lmeg_save_product', 'lmeg_product_nonce'); ?>
            <input type="hidden" name="action" value="lmeg_save_product">
            <input type="hidden" name="product_id" value="<?php echo (int) $p->id; ?>">
            <table class="form-table">
                <tr><th><label>Title</label></th><td><input type="text" name="title" class="regular-text" required value="<?php echo esc_attr($p->title); ?>" placeholder="e.g. Neon Hours (single)"></td></tr>
                <tr><th><label>Description</label></th><td><textarea name="description" class="large-text" rows="3"><?php echo esc_textarea($p->description); ?></textarea></td></tr>
                <tr><th><label>Cover image</label></th><td>
                    <div id="lmeg-cover-prev" style="margin-bottom:9px;<?php echo empty($p->cover_url) ? 'display:none' : ''; ?>"><img src="<?php echo esc_url($p->cover_url); ?>" style="max-width:170px;height:auto;border-radius:10px;border:1px solid #ddd"></div>
                    <input type="hidden" id="lmeg-cover-url" name="cover_url" value="<?php echo esc_attr($p->cover_url); ?>">
                    <button type="button" class="button" id="lmeg-cover-pick">Choose image</button>
                    <button type="button" class="button" id="lmeg-cover-clear" style="<?php echo empty($p->cover_url) ? 'display:none' : ''; ?>">Remove</button>
                    <p class="description">Pick from your WordPress Media Library.</p>
                    <script>
                    jQuery(function($){
                        var frame;
                        $('#lmeg-cover-pick').on('click', function(e){ e.preventDefault();
                            if (frame) { frame.open(); return; }
                            frame = wp.media({ title:'Choose a cover image', button:{text:'Use this image'}, multiple:false, library:{type:'image'} });
                            frame.on('select', function(){
                                var a = frame.state().get('selection').first().toJSON();
                                $('#lmeg-cover-url').val(a.url);
                                $('#lmeg-cover-prev').html('<img src="'+a.url+'" style="max-width:170px;height:auto;border-radius:10px;border:1px solid #ddd">').show();
                                $('#lmeg-cover-clear').show();
                            });
                            frame.open();
                        });
                        $('#lmeg-cover-clear').on('click', function(e){ e.preventDefault(); $('#lmeg-cover-url').val(''); $('#lmeg-cover-prev').hide().empty(); $(this).hide(); });
                    });
                    </script>
                </td></tr>
                <tr><th><label>Price</label></th><td><input type="number" name="price" step="0.01" min="0" style="width:120px" value="<?php echo esc_attr($money($p->price_cents)); ?>"> <input type="text" name="currency" style="width:64px" maxlength="3" value="<?php echo esc_attr($p->currency ?: 'USD'); ?>"></td></tr>
                <tr><th><label>Pay what you want</label></th><td><label><input type="checkbox" name="pwyw" value="1" <?php checked((int) $p->min_price_cents > 0); ?>> Let fans choose the price</label> &nbsp; minimum <input type="number" name="min_price" step="0.01" min="0" style="width:110px" value="<?php echo esc_attr($money($p->min_price_cents)); ?>"><p class="description">When on, the price above is the suggested amount and fans can pay the minimum or more.</p></td></tr>
                <tr><th><label>Type</label></th><td>
                    <label><input type="radio" name="type" value="digital" <?php checked($p->type ?? 'digital', 'digital'); ?>> Digital (download / unlock link)</label> &nbsp;&nbsp;
                    <label><input type="radio" name="type" value="physical" <?php checked($p->type ?? 'digital', 'physical'); ?>> Physical (ship it)</label>
                    <p class="description">Physical collects a shipping address at checkout and creates an order for you to ship. Digital delivers the unlock link below.</p></td></tr>
                <tr><th><label>Payment</label></th><td>
                    <label><input type="radio" name="processor" value="stripe" <?php checked($p->processor ?? 'stripe', 'stripe'); ?>> Stripe</label> &nbsp;&nbsp;
                    <label><input type="radio" name="processor" value="square" <?php checked($p->processor ?? 'stripe', 'square'); ?>> Square</label>
                    <p class="description">Which processor collects the payment — the money lands in that account. Set keys under Settings → Payments.</p></td></tr>
                <tr><th><label>Shipping fee <span style="color:#888;font-weight:400">(physical)</span></label></th><td><input type="number" name="shipping" step="0.01" min="0" style="width:120px" value="<?php echo esc_attr($money($p->shipping_cents ?? 0)); ?>"><p class="description">Flat shipping added at checkout for physical items. 0 = free shipping.</p></td></tr>
                <tr><th><label>Variants / sizes</label></th><td><input type="text" name="variants" class="regular-text" value="<?php echo esc_attr(lmeg_product_variants_field($p)); ?>" placeholder="S, M, L, XL"><p class="description">Comma-separated options the buyer picks (e.g. <code>S, M, L</code>). To <strong>track stock per option</strong>, add a quantity: <code>S:10, M:5, L:20</code> — that count is the remaining stock (it counts down on each sale, and sold-out options are hidden from buyers). Mix freely; leave a number off an option for unlimited. Blank = no variants.</p></td></tr>
                <tr><th><label>Upload file <span style="color:#888;font-weight:400">(digital)</span></label></th><td>
                    <?php if (!empty($p->file_path)) : ?><p style="margin:0 0 7px">📎 <strong><?php echo esc_html($p->file_name); ?></strong> <span style="color:#888">(<?php echo esc_html(size_format((int) $p->file_size)); ?>)</span> &nbsp; <label style="color:#a00"><input type="checkbox" name="remove_file" value="1"> remove</label></p><?php endif; ?>
                    <input type="file" name="product_file">
                    <p class="description">Upload the actual file (audio, zip, pdf, video, image, epub…). It's stored privately and served only through each buyer's personal download link — never a public URL. <?php echo !empty($p->file_path) ? 'Uploading a new file replaces the current one. ' : ''; ?>A file takes priority over the link below. (Large files may need your host's upload limit raised.)</p></td></tr>
                <tr><th><label>…or unlock link <span style="color:#888;font-weight:400">(digital)</span></label></th><td><input type="url" name="deliver_url" class="regular-text" value="<?php echo esc_attr($p->deliver_url); ?>" placeholder="https://… private stream / Drive / Discord invite"><p class="description">Used only when no file is uploaded above: after paying, the fan is sent to this link through a private, per-buyer access URL.</p></td></tr>
                <tr><th><label>Limit (stock)</label></th><td><input type="number" name="stock" min="0" style="width:120px" value="<?php echo $p->stock < 0 ? '' : (int) $p->stock; ?>" placeholder="unlimited"><p class="description">Leave blank for unlimited; set a number for a limited drop.</p></td></tr>
                <tr><th><label>Status</label></th><td><select name="status"><option value="active" <?php selected($p->status, 'active'); ?>>Active (buyable)</option><option value="draft" <?php selected($p->status, 'draft'); ?>>Draft (hidden)</option></select></td></tr>
            </table>
            <p><button type="submit" class="button button-primary">Save product</button>
            <?php if ($p->id) : ?> &nbsp; <button type="submit" name="do" value="delete" class="button" onclick="return confirm('Delete this product? Past sales stay recorded.');">Delete</button><?php endif; ?></p>
            <?php if ($p->id) : ?>
            <p class="description">Its own page: <code><?php echo esc_html(lmeg_product_url($p)); ?></code> <a href="<?php echo esc_url(lmeg_product_url($p)); ?>" target="_blank" rel="noopener">open ↗</a></p>
            <p class="description">Embed on any page: <code>[fanloop_product id=<?php echo (int) $p->id; ?>]</code> &nbsp;·&nbsp; whole shop: <code>[fanloop_store]</code></p>
            <?php endif; ?>
        </form>
        </div><?php
        return;
    }

    /* ----- list + sales + download analytics ----- */
    $rows  = $wpdb->get_results("SELECT * FROM $tbl ORDER BY id DESC");
    $units = (int) $wpdb->get_var("SELECT COUNT(*) FROM $ptbl WHERE status = 'paid'");
    $rev   = (int) $wpdb->get_var("SELECT COALESCE(SUM(amount_cents),0) FROM $ptbl WHERE status = 'paid'");
    $dls   = (int) $wpdb->get_var("SELECT COALESCE(SUM(access_count),0) FROM $ptbl WHERE status = 'paid'");
    $demo_n = (int) $wpdb->get_var("SELECT COUNT(*) FROM $ptbl WHERE processor = 'demo'");

    // Per-product: buyers, downloads, how many buyers actually downloaded.
    $pstats = [];
    foreach ((array) $wpdb->get_results("SELECT product_id, COUNT(*) buyers, COALESCE(SUM(access_count),0) dls, SUM(CASE WHEN access_count > 0 THEN 1 ELSE 0 END) downloaders FROM $ptbl WHERE status = 'paid' GROUP BY product_id") as $r) {
        $pstats[(int) $r->product_id] = $r;
    }

    // 30-day units/day sparkline.
    $since    = date('Y-m-d H:i:s', current_time('timestamp') - 30 * DAY_IN_SECONDS);
    $daily    = array_fill(0, 30, 0);
    $today_ts = current_time('timestamp');
    foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT DATE(paid_at) d, COUNT(*) n FROM $ptbl WHERE status = 'paid' AND paid_at >= %s GROUP BY DATE(paid_at)", $since)) as $dr) {
        $idx = 29 - (int) floor(($today_ts - strtotime($dr->d . ' 00:00:00')) / DAY_IN_SECONDS);
        if ($idx >= 0 && $idx < 30) $daily[$idx] = (int) $dr->n;
    }
    ?>
    <p style="margin:10px 0 4px"><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-products&new=1')); ?>" class="button button-primary">+ New product</a>
    <?php if ($demo_n > 0) : ?>
        &nbsp; <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" onsubmit="return confirm('Clear <?php echo (int) $demo_n; ?> demo/test order<?php echo $demo_n === 1 ? '' : 's'; ?>? Real sales are untouched. Sold counts and variant stock consumed by the tests are restored.');">
            <?php wp_nonce_field('lmeg_clear_demo', 'lmeg_demo_nonce'); ?>
            <input type="hidden" name="action" value="lmeg_clear_demo">
            <button type="submit" class="button">🧪 Clear test orders (<?php echo (int) $demo_n; ?>)</button>
        </form>
    <?php endif; ?></p>
    <p class="description" style="margin:0 0 12px">Show your whole shop on any page with <code>[fanloop_store]</code> (or one item with <code>[fanloop_product id=…]</code>). Let buyers re-download what they bought with <code>[fanloop_purchases]</code> (or link to <code><?php echo esc_html(add_query_arg(['lmeg_purchases' => 'find'], home_url('/'))); ?></code>).</p>
    <?php
    $has_samples = false;
    foreach ($rows as $rp) { if (strpos($rp->slug, 'sample-') === 0 && $rp->status === 'draft') { $has_samples = true; break; } }
    if ($has_samples) echo '<div class="notice notice-info inline" style="margin:0 0 18px;max-width:840px"><p>👋 We added a few <strong>sample products</strong> to get you started — they are <strong>Drafts</strong>, so fans can\'t see them yet. Edit one to make it yours (and set it <em>Active</em> to sell it), or delete them.</p></div>';
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;max-width:840px;margin-bottom:20px">
        <div class="lmeg-stat"><div class="lmeg-stat__label">Units sold · 30d trend</div><div class="lmeg-stat__value"><?php echo number_format_i18n($units); ?></div><?php echo function_exists('lmeg_chart_line') ? lmeg_chart_line($daily, ['color' => '#E15FA8', 'uid' => 'store-units', 'h' => 44, 'suffix' => 'sales']) : ''; ?></div>
        <div class="lmeg-stat"><div class="lmeg-stat__label">Revenue</div><div class="lmeg-stat__value"><?php echo esc_html(function_exists('lmeg_format_price') ? lmeg_format_price($rev, 'USD') : '$' . number_format($rev/100,2)); ?></div><div class="lmeg-stat__hint">before processor fees · lands in your Stripe / Square</div></div>
        <div class="lmeg-stat"><div class="lmeg-stat__label">Downloads</div><div class="lmeg-stat__value"><?php echo number_format_i18n($dls); ?></div><div class="lmeg-stat__hint">total file/link accesses by buyers</div></div>
    </div>
    <table class="widefat striped">
        <thead><tr><th>Product</th><th>Type</th><th>Price</th><th>Payment</th><th>Sold</th><th>Downloads</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows) : ?><tr><td colspan="8">No products yet. Create your first drop.</td></tr>
        <?php else : foreach ($rows as $p) :
            $cur = $p->currency ?: 'USD';
            $price = lmeg_product_is_pwyw($p)
                ? 'PWYW · min ' . (function_exists('lmeg_format_price') ? lmeg_format_price((int)$p->min_price_cents,$cur) : '')
                : (function_exists('lmeg_format_price') ? lmeg_format_price((int)$p->price_cents,$cur) : '$'.number_format($p->price_cents/100,2));
        ?>
            <tr>
                <td><strong><?php echo esc_html($p->title); ?></strong></td>
                <td><?php echo ($p->type === 'physical') ? '📦 Physical' : '⬇ Digital'; ?></td>
                <td><?php echo esc_html($price); ?><?php echo ($p->type === 'physical' && (int)$p->shipping_cents > 0) ? ' <span style="color:#888">+ ship</span>' : ''; ?></td>
                <td><?php echo ($p->processor === 'square') ? 'Square' : 'Stripe'; ?></td>
                <td><?php echo (int) $p->sold; ?><?php echo $p->stock >= 0 ? ' / ' . (int) $p->stock : ''; ?></td>
                <td><?php
                    $st = $pstats[(int) $p->id] ?? null;
                    if ($p->type === 'physical') { echo '<span style="color:#9A9DB0">—</span>'; }
                    elseif ($st && (int) $st->buyers > 0) {
                        $pct = round(((int) $st->downloaders / (int) $st->buyers) * 100);
                        echo number_format_i18n((int) $st->dls) . ' <span style="color:#888;font-size:12px">· ' . (int) $st->downloaders . '/' . (int) $st->buyers . ' (' . $pct . '%)</span>';
                    } else { echo '<span style="color:#9A9DB0">0</span>'; }
                ?></td>
                <td><?php echo $p->status === 'active' ? '<span style="color:#34D399">● Active</span>' : '<span style="color:#9A9DB0">Draft</span>'; ?></td>
                <td><a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=lmeg-products&edit=' . $p->id)); ?>">Edit</a></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php
    // Physical orders awaiting shipment.
    $orders = $wpdb->get_results("SELECT pp.*, pr.title FROM $ptbl pp LEFT JOIN $tbl pr ON pr.id = pp.product_id WHERE pp.status='paid' AND pp.fulfillment='unshipped' ORDER BY pp.id ASC LIMIT 100");
    if ($orders) : ?>
        <h2 id="orders" style="margin-top:26px">Orders to ship (<?php echo count($orders); ?>)</h2>
        <table class="widefat striped" style="max-width:980px">
            <thead><tr><th>When</th><th>Item</th><th>Ship to</th><th>Amount</th><th></th></tr></thead>
            <tbody><?php foreach ($orders as $o) : ?>
                <tr>
                    <td><?php echo esc_html($o->paid_at); ?></td>
                    <td><?php echo esc_html($o->title); ?><?php echo $o->variant ? ' · <strong>' . esc_html($o->variant) . '</strong>' : ''; ?></td>
                    <td><?php echo esc_html($o->ship_name ?: '—'); ?><?php echo $o->email ? ' · ' . esc_html($o->email) : ''; ?><?php echo $o->ship_address ? '<br><span style="white-space:pre-line;color:#666;font-size:12px">' . esc_html($o->ship_address) . '</span>' : ''; ?></td>
                    <td><?php echo esc_html(function_exists('lmeg_format_price') ? lmeg_format_price((int)$o->amount_cents, $o->currency) : '$'.number_format($o->amount_cents/100,2)); ?></td>
                    <td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('lmeg_ship_order', 'lmeg_ship_nonce'); ?><input type="hidden" name="action" value="lmeg_ship_order"><input type="hidden" name="purchase_id" value="<?php echo (int) $o->id; ?>"><button class="button button-small" type="submit">Mark shipped</button></form></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table>
    <?php endif;

    $recent = $wpdb->get_results("SELECT pp.*, pr.title FROM $ptbl pp LEFT JOIN $tbl pr ON pr.id = pp.product_id WHERE pp.status='paid' ORDER BY pp.id DESC LIMIT 15");
    if ($recent) : ?>
        <h2 style="margin-top:26px">Recent sales</h2>
        <table class="widefat striped" style="max-width:820px">
            <thead><tr><th>When</th><th>Product</th><th>Buyer</th><th>Amount</th></tr></thead>
            <tbody><?php foreach ($recent as $r) : ?>
                <tr><td><?php echo esc_html($r->paid_at); ?></td><td><?php echo esc_html($r->title); ?><?php echo ($r->processor === 'demo') ? ' <span style="font-size:11px;background:rgba(225,95,168,.14);color:#b03083;padding:1px 7px;border-radius:999px;vertical-align:middle">demo</span>' : ''; ?></td><td><?php echo esc_html($r->email ?: '—'); ?></td><td><?php echo esc_html(function_exists('lmeg_format_price') ? lmeg_format_price((int)$r->amount_cents, $r->currency) : '$'.number_format($r->amount_cents/100,2)); ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>
    <?php endif;

    if (function_exists('lmeg_discounts_admin_section')) lmeg_discounts_admin_section();
    echo '</div>';
}
