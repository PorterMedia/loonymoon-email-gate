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
/** On sale = a compare-at price above the (real) price, and not pay-what-you-want. */
function lmeg_product_on_sale($p) {
    return !lmeg_product_is_pwyw($p) && (int) ($p->compare_at_cents ?? 0) > (int) $p->price_cents && (int) $p->price_cents >= 0 && (int) ($p->compare_at_cents ?? 0) > 0;
}
/** Percent off vs the compare-at price (0 when not on sale). */
function lmeg_product_sale_pct($p) {
    if (!lmeg_product_on_sale($p)) return 0;
    $was = (int) $p->compare_at_cents; $now = (int) $p->price_cents;
    return (int) round(($was - $now) / $was * 100);
}

/** A product is a pre-order while its release date is still in the future. */
function lmeg_product_is_preorder($p) {
    return !empty($p->preorder_at) && strtotime($p->preorder_at) > current_time('timestamp');
}
/** Formatted release date, e.g. "Sep 12, 2026". */
function lmeg_product_preorder_date($p) {
    return !empty($p->preorder_at) ? date_i18n(get_option('date_format'), strtotime($p->preorder_at)) : '';
}

/**
 * Store-wide announcement bar (dismissible). Returns '' when no text is set or
 * it's already been rendered once on this page. Dismiss is remembered per banner
 * text via localStorage, so a new message reappears.
 */
function lmeg_store_banner_html() {
    if (!empty($GLOBALS['lmeg_banner_shown'])) return '';
    $s    = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $text = trim((string) ($s['store_banner_text'] ?? ''));
    if ($text === '') return '';
    $GLOBALS['lmeg_banner_shown'] = true;
    $link = trim((string) ($s['store_banner_link'] ?? ''));
    $key  = substr(md5($text . '|' . $link), 0, 12);
    $inner = esc_html($text) . ($link ? ' <span style="opacity:.85">→</span>' : '');
    $content = $link
        ? '<a href="' . esc_url($link) . '" style="color:inherit;text-decoration:none;font-weight:700">' . $inner . '</a>'
        : '<span style="font-weight:700">' . $inner . '</span>';
    return '<div class="flp-banner" data-key="' . esc_attr($key) . '" style="position:relative;margin:0 0 18px;padding:12px 44px 12px 18px;border-radius:12px;background:linear-gradient(118deg,#E15FA8,#8A6CF6);color:#0B0C12;text-align:center;font-size:14px;line-height:1.45;font-family:inherit">'
        . $content
        . '<button type="button" class="flp-banner-x" aria-label="Dismiss" style="position:absolute;right:9px;top:50%;transform:translateY(-50%);background:rgba(0,0,0,.18);border:0;color:#0B0C12;width:24px;height:24px;border-radius:50%;cursor:pointer;font-size:15px;line-height:1">×</button></div>'
        . '<script>(function(){var b=document.currentScript.previousElementSibling;if(!b||!b.classList||!b.classList.contains("flp-banner"))return;var k=b.getAttribute("data-key");try{if(localStorage.getItem("flp_banner_dismissed")===k){b.style.display="none";return;}}catch(e){}var x=b.querySelector(".flp-banner-x");if(x)x.addEventListener("click",function(){b.style.display="none";try{localStorage.setItem("flp_banner_dismissed",k);}catch(e){}});})();</script>';
}

/** Up to $limit other active products (best-sellers first) for a "more from the shop" strip. */
function lmeg_product_related($p, $limit = 4) {
    global $wpdb;
    $limit = max(1, min(8, (int) $limit));
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lmeg_products WHERE status = 'active' AND id <> %d ORDER BY sold DESC, id DESC LIMIT %d",
        (int) $p->id, $limit
    ));
}

/**
 * Best-sellers strip for the post-purchase "You might also like" upsell.
 * Excludes the products just bought. Dark-surface mini-cards (this renders on
 * the dark thank-you chrome — every colour is explicit, none inherited).
 * Returns '' when nothing else is for sale.
 */
function lmeg_store_upsell_html($exclude_ids = [], $limit = 3, $heading = 'You might also like') {
    global $wpdb;
    $limit   = max(1, min(6, (int) $limit));
    $exclude = array_values(array_unique(array_filter(array_map('intval', (array) $exclude_ids))));
    $notin   = $exclude ? ' AND id NOT IN (' . implode(',', $exclude) . ')' : '';   // ints only — safe to inline
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lmeg_products WHERE status = 'active'$notin ORDER BY sold DESC, id DESC LIMIT %d",
        $limit
    ));
    if (!$rows) return '';

    $cards = '';
    foreach ($rows as $r) {
        $rurl  = esc_url(lmeg_product_url($r));
        $price = lmeg_product_is_pwyw($r) ? 'Name your price'
               : (function_exists('lmeg_format_price') ? lmeg_format_price((int) $r->price_cents, $r->currency ?: 'USD') : '$' . number_format($r->price_cents / 100, 2));
        $img = !empty($r->cover_url)
            ? '<img src="' . esc_url($r->cover_url) . '" alt="" style="width:100%;aspect-ratio:1/1;object-fit:cover;display:block">'
            : '<div style="width:100%;aspect-ratio:1/1;background:#20222E"></div>';
        $cards .= '<a href="' . $rurl . '" style="text-decoration:none;display:block;background:#12141f;border:1px solid rgba(255,255,255,.08);border-radius:12px;overflow:hidden">'
            . $img
            . '<div style="padding:9px 11px">'
            . '<div style="color:#F4F2F7;font-weight:650;font-size:13px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . esc_html($r->title) . '</div>'
            . '<div style="color:#E7A6CF;font-size:12px;font-weight:700;margin-top:2px">' . esc_html($price) . '</div>'
            . '</div></a>';
    }
    return '<div style="width:100%;max-width:720px;margin:34px auto 0">'
        . '<div style="color:#8B90A0;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:12px;text-align:center">' . esc_html($heading) . '</div>'
        . '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px">' . $cards . '</div></div>';
}

/** A product's tags → clean list (comma-separated in the `tags` column). */
function lmeg_product_tags($p) {
    $raw = is_object($p) ? ($p->tags ?? '') : (string) $p;
    $out = [];
    foreach (explode(',', (string) $raw) as $t) {
        $t = trim(preg_replace('/\s+/', ' ', (string) $t));
        if ($t === '') continue;
        $key = strtolower($t);
        if (!isset($out[$key])) $out[$key] = $t;   // dedupe case-insensitively, keep first casing
        if (count($out) >= 12) break;
    }
    return array_values($out);
}

/**
 * Filter/curate a set of product rows for a storefront collection.
 * - ids "12,7,3" → exactly those products, in that order (missing/inactive skipped).
 * - else tag "vinyl" → products carrying that tag (case-insensitive).
 * - else → unchanged. Pure (operates on already-fetched rows), so it's testable.
 */
function lmeg_store_filter_rows($rows, $ids_csv, $tag) {
    $rows = (array) $rows;
    $ids_csv = trim((string) $ids_csv);
    if ($ids_csv !== '') {
        $ids = [];
        foreach (explode(',', $ids_csv) as $x) { $id = (int) trim($x); if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id; }
        $by = [];
        foreach ($rows as $r) $by[(int) $r->id] = $r;
        $out = [];
        foreach ($ids as $id) if (isset($by[$id])) $out[] = $by[$id];
        return $out;
    }
    $tag = strtolower(trim((string) $tag));
    if ($tag !== '') {
        $out = [];
        foreach ($rows as $r) {
            $tags = function_exists('lmeg_product_tags') ? array_map('strtolower', lmeg_product_tags($r)) : [];
            if (in_array($tag, $tags, true)) $out[] = $r;
        }
        return $out;
    }
    return $rows;
}

/** Normalise a raw tags string for storage (trim, dedupe, cap length). */
function lmeg_product_tags_normalize($raw) {
    $list = lmeg_product_tags($raw);
    $str  = implode(', ', $list);
    return mb_substr($str, 0, 255);
}

/**
 * Is this product buyable right now? Mirrors the card's sold-out logic:
 * must be active, not stock-exhausted, and (if it has variants) at least one
 * variant available. Used for back-in-stock auto-notify on restock.
 */
function lmeg_product_is_available($p) {
    if (!$p || ($p->status ?? '') !== 'active') return false;
    if (($p->stock ?? -1) >= 0 && (int) ($p->sold ?? 0) >= (int) $p->stock) return false;
    $vlist = function_exists('lmeg_product_variants') ? lmeg_product_variants($p) : [];
    if ($vlist) {
        foreach ($vlist as $v) if (!empty($v['available'])) return true;
        return false;
    }
    return true;
}

/**
 * Was this product added recently? (created_at within the last $days days.)
 * Drives the "Just added" badge on storefront cards.
 */
function lmeg_product_is_new($p, $days = 14) {
    if (empty($p->created_at)) return false;
    $t = strtotime($p->created_at);
    if (!$t) return false;
    $now = function_exists('current_time') ? current_time('timestamp') : time();
    return ($now - $t) <= ($days * DAY_IN_SECONDS) && $t <= $now + DAY_IN_SECONDS;
}

/**
 * Low-stock urgency block for a product card (WHITE surface): a "🔥 Only N left"
 * pill, a claimed-progress bar, and an "X of Y claimed" line. Returns '' when
 * there's no real stock limit. All colours explicit (card never inherits).
 */
function lmeg_product_lowstock_html($sold, $stock) {
    $sold = max(0, (int) $sold); $stock = (int) $stock;
    if ($stock <= 0) return '';
    $remaining = max(0, $stock - $sold);
    $claimed   = min($stock, $sold);
    $pct       = (int) round(min(100, max(0, $claimed / $stock * 100)));
    return '<div style="margin-bottom:9px">'
        . '<div style="display:inline-block;font-size:12px;font-weight:700;color:#B45309;background:#FEF3C7;padding:2px 10px;border-radius:999px;margin-bottom:6px">' . lmeg_store_icon('flame', 13, ['style' => 'margin-right:4px']) . 'Only ' . $remaining . ' left</div>'
        . '<div style="height:6px;border-radius:999px;background:#EFE6D2;overflow:hidden"><div style="height:100%;width:' . $pct . '%;background:linear-gradient(90deg,#F59E0B,#EF4444);border-radius:999px"></div></div>'
        . '<div style="font-size:11px;color:#8a6d00;margin-top:4px;font-weight:600">' . $claimed . ' of ' . $stock . ' claimed</div>'
        . '</div>';
}

/** Decode a product's extra gallery images (JSON array of URLs) → valid URLs. */
function lmeg_product_gallery($p) {
    $g = [];
    if (!empty($p->gallery)) { $d = json_decode($p->gallery, true); if (is_array($d)) $g = $d; }
    $out = [];
    foreach ($g as $u) { $u = trim((string) $u); if ($u !== '' && filter_var($u, FILTER_VALIDATE_URL)) $out[] = $u; }
    return $out;
}

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

    // Route this single item through the cart checkout page when we need to run
    // the full flow there: demo mode (no payment), or a physical item with zone
    // shipping on (so the destination country / fee is collected first).
    $route_cart = (function_exists('lmeg_store_demo_on') && lmeg_store_demo_on())
        || ($physical && function_exists('lmeg_store_ship_enabled') && lmeg_store_ship_enabled());
    if ($route_cart) {
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

    $preorder = lmeg_product_is_preorder($p);
    $predate  = lmeg_product_preorder_date($p);

    if ($physical) {
        $subject = ($preorder ? 'Pre-order confirmed: ' : 'Order confirmed: ') . $p->title;
        $inner   = lmeg_email_h($preorder ? 'Pre-order confirmed 🎟️' : 'Order confirmed 🎉')
                 . lmeg_email_p($preorder
                     ? 'Thanks for pre-ordering — it ships around <strong>' . esc_html($predate) . '</strong>.'
                     : 'Thanks for your order — we\'re on it. Here\'s what\'s coming your way:')
                 . $table
                 . ($ship_name ? lmeg_email_ship_block($ship_name, '') : '')
                 . lmeg_email_note($preorder ? 'We\'ll email you when it\'s on the way.' : 'We\'ll get it on its way and email you if we need anything.');
        $pre = ($preorder ? 'Your pre-order of ' : 'Your order of ') . $p->title . ' is confirmed.';
    } elseif ($preorder) {
        $subject = 'Pre-order confirmed: ' . $p->title;
        $inner   = lmeg_email_h('Pre-order confirmed 🎟️')
                 . lmeg_email_p('Thanks for pre-ordering <strong>' . esc_html($p->title) . '</strong>. Your download will be ready on <strong>' . esc_html($predate) . '</strong> — we\'ll email it to you.')
                 . $table
                 . lmeg_email_note('It\'ll also appear on your purchases page once it\'s released.');
        $pre = 'Your pre-order of ' . $p->title . ' is confirmed — available ' . $predate . '.';
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

/** "Your download is ready" email — used on a pre-order release. */
function lmeg_product_send_ready($p, $email, $token) {
    if (!$email || !function_exists('lmeg_email_deliver')) return false;
    $inner = lmeg_email_h('Your download is ready 🎉')
        . lmeg_email_p('<strong>' . esc_html($p->title) . '</strong> is out — here\'s the download you pre-ordered.')
        . lmeg_email_download_block([['name' => $p->title, 'url' => lmeg_product_access_url($token)]])
        . lmeg_email_note('It\'s also on your purchases page anytime. Enjoy!');
    return lmeg_email_deliver($email, 'Your download is ready: ' . $p->title, $inner, $p->title . ' is out — grab your download.');
}

add_action('admin_post_lmeg_release_downloads', 'lmeg_handle_release_downloads');
function lmeg_handle_release_downloads() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_release_downloads', 'lmeg_release_nonce');
    global $wpdb;
    $ptbl = $wpdb->prefix . 'lmeg_product_purchases';
    $pid  = (int) ($_POST['product_id'] ?? 0);
    $p    = $pid ? lmeg_product_get($pid) : null;
    if (!$p) { wp_safe_redirect(admin_url('admin.php?page=lmeg-products')); exit; }
    $buyers = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT email, access_token FROM $ptbl WHERE product_id = %d AND status='paid' AND email IS NOT NULL AND email <> '' AND access_token IS NOT NULL LIMIT 5000", $pid));
    $n = 0;
    foreach ((array) $buyers as $b) if (lmeg_product_send_ready($p, $b->email, $b->access_token)) $n++;
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products&edit=' . $pid . '&released=' . $n)); exit;
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

/** Active products whose tracked stock (overall or a size) is low (≤ $threshold). */
function lmeg_lowstock_products($threshold = 5) {
    global $wpdb;
    $out  = [];
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lmeg_products WHERE status = 'active'");
    foreach ((array) $rows as $p) {
        $low = [];
        if ($p->stock >= 0) {
            $rem = max(0, (int) $p->stock - (int) $p->sold);
            if ($rem <= $threshold) $low[] = ($rem === 0 ? 'sold out' : $rem . ' left');
        }
        foreach (lmeg_product_variants($p) as $v) {
            if ($v['stock'] !== null && (int) $v['stock'] <= $threshold) $low[] = $v['name'] . ': ' . ((int) $v['stock'] === 0 ? 'sold out' : (int) $v['stock'] . ' left');
        }
        if ($low) $out[] = ['title' => $p->title, 'detail' => implode(', ', $low)];
    }
    return $out;
}

/**
 * Weekly low-stock digest to the artist (opt-in). Runs on the per-minute tick
 * but self-throttles to at most one email a week, and only when something's low.
 */
add_action('lmeg_broadcast_tick', 'lmeg_lowstock_digest_tick', 46);
function lmeg_lowstock_digest_tick() {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    if (empty($s['store_lowstock_digest']) || !function_exists('lmeg_email_deliver')) return;
    $last = (int) get_option('lmeg_lowstock_digest_last', 0);
    if ((current_time('timestamp') - $last) < 7 * DAY_IN_SECONDS) return;

    update_option('lmeg_lowstock_digest_last', current_time('timestamp'));   // reset weekly clock regardless
    $to = trim((string) ($s['store_notify_email'] ?? '')) ?: get_option('admin_email');
    if (!$to || !is_email($to)) return;

    $low = lmeg_lowstock_products(5);
    if (!$low) return;   // nothing to report this week

    $rows = '';
    foreach ($low as $l) {
        $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #f0eef4;font-weight:650;color:#1a1622">' . esc_html($l['title'])
              . '</td><td align="right" style="padding:8px 0;border-bottom:1px solid #f0eef4;color:#B45309;font-weight:700;white-space:nowrap">' . esc_html($l['detail']) . '</td></tr>';
    }
    $n = count($low);
    $inner = lmeg_email_h('Running low on stock 📦')
        . lmeg_email_p($n . ' product' . ($n === 1 ? '' : 's') . ' in your shop ' . ($n === 1 ? 'is' : 'are') . ' running low — restock before ' . ($n === 1 ? 'it sells' : 'they sell') . ' out.')
        . '<div style="margin:6px 0 18px;background:#faf9fc;border:1px solid #efedf3;border-radius:12px;padding:6px 18px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table></div>'
        . lmeg_email_button('Open your Store →', admin_url('admin.php?page=lmeg-products'));
    lmeg_email_deliver($to, 'Low stock: ' . $n . ' product' . ($n === 1 ? '' : 's') . ' need a restock', $inner, $n . ' products are running low in your shop.');
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

    // Pre-order that hasn't been released yet (no file/link).
    if (lmeg_product_is_preorder($p) && empty($p->file_path) && empty($p->deliver_url)) {
        wp_die('This is a pre-order — your download unlocks on ' . esc_html(lmeg_product_preorder_date($p)) . '. We\'ll email you the moment it\'s ready.', 'Pre-order', ['response' => 200]);
    }

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
    </div>
    <?php if ($paid && function_exists('lmeg_store_upsell_html')) echo lmeg_store_upsell_html($p ? [(int) $p->id] : [], 3); ?>
    </body></html><?php
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
    return '<div class="flp-prod-wrap" style="max-width:420px">' . lmeg_product_card_html($p, true, true) . '</div>';
}

/* Storefront — [fanloop_store] / [loony_store] lists all active products. */
add_shortcode('fanloop_store', 'lmeg_shortcode_store');
add_shortcode('loony_store', 'lmeg_shortcode_store');
function lmeg_shortcode_store($atts) {
    static $store_n = 0; $store_n++;
    $atts = shortcode_atts(['type' => 'all', 'min' => 240, 'controls' => 'auto', 'ids' => '', 'tag' => '', 'per' => 0, 'hero' => ''], $atts, 'fanloop_store');
    global $wpdb;
    $where = "status = 'active'";
    if (in_array($atts['type'], ['digital', 'physical'], true)) $where .= $wpdb->prepare(' AND type = %s', $atts['type']);
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lmeg_products WHERE $where ORDER BY featured DESC, id DESC");
    // Curated collection: exact ids (in order) or a single tag section.
    $curated = (trim((string) $atts['ids']) !== '');
    if ($curated || trim((string) $atts['tag']) !== '') {
        $rows = lmeg_store_filter_rows($rows, $atts['ids'], $atts['tag']);
    }
    // Spotlight hero: pull one product out to feature large above the grid. Uses
    // the first featured product (falls back to the newest). Only when hero is
    // requested, not a curated section, and there are ≥2 products (so the grid
    // still has something below it).
    $hero_p = null;
    if (in_array(strtolower(trim((string) $atts['hero'])), ['1', 'on', 'yes', 'true', 'featured'], true) && !$curated && count($rows) >= 2) {
        foreach ($rows as $p) { if (!empty($p->featured)) { $hero_p = $p; break; } }
        if (!$hero_p) $hero_p = $rows[0];
        $rows = array_values(array_filter($rows, function ($p) use ($hero_p) { return (int) $p->id !== (int) $hero_p->id; }));
    }
    if (!$rows && !$hero_p) {
        return '<div style="max-width:420px;margin:20px auto;text-align:center;background:#fff;color:#17141f;border:1px solid rgba(0,0,0,.12);border-radius:16px;padding:34px 26px;box-shadow:0 12px 40px rgba(0,0,0,.08);font-family:inherit">'
            . '<div style="line-height:1;margin-bottom:10px;color:#c3c3cf">' . lmeg_store_icon('bag', 40) . '</div>'
            . '<div style="font-weight:750;font-size:18px;margin-bottom:5px">The shop is warming up</div>'
            . '<div style="font-size:14px;color:#6b6b78;line-height:1.5">New drops are on the way — check back soon.</div>'
            . '</div>';
    }
    $min = max(160, min(360, (int) $atts['min']));
    $uid = 'flpstore' . $store_n;

    // Search + sort controls — shown once the shop has enough items to warrant it
    // (or forced with controls="on" / hidden with controls="off").
    $show_ctrls = ($atts['controls'] === 'on') || ($atts['controls'] !== 'off' && !$curated && count($rows) >= 5);
    $ctrl_css = 'padding:10px 13px;border:1px solid rgba(0,0,0,.18);border-radius:10px;background:#fff;color:#17141f;font-size:14px';
    $controls = '';
    if ($show_ctrls) {
        $n_total = count($rows);
        $controls = '<div class="flp-store-ctrls" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px">'
            . '<input type="search" class="flp-q" placeholder="Search the shop…" aria-label="Search the shop" style="flex:1;min-width:180px;' . $ctrl_css . '">'
            . '<select class="flp-sort" aria-label="Sort products" style="' . $ctrl_css . ';cursor:pointer">'
            . '<option value="featured">Featured</option><option value="new">Newest</option>'
            . '<option value="price-asc">Price: low to high</option><option value="price-desc">Price: high to low</option>'
            . '<option value="sold">Best selling</option><option value="name">Name A–Z</option></select>'
            . '<span class="flp-count" aria-live="polite" style="font-size:13px;color:#6b6b78;white-space:nowrap;flex:0 0 auto">' . (int) $n_total . ' item' . ($n_total === 1 ? '' : 's') . '</span></div>';
    }

    // Tag / category filter chips — union of tags across the shown products,
    // ordered by frequency then name. Shown only when ≥2 distinct tags exist.
    $tag_counts = [];
    foreach ($rows as $p) {
        foreach (lmeg_product_tags($p) as $t) {
            $k = strtolower($t);
            if (!isset($tag_counts[$k])) $tag_counts[$k] = ['label' => $t, 'n' => 0];
            $tag_counts[$k]['n']++;
        }
    }
    uasort($tag_counts, function ($a, $b) {
        if ($a['n'] !== $b['n']) return $b['n'] - $a['n'];
        return strcasecmp($a['label'], $b['label']);
    });
    $has_tags = count($tag_counts) >= 2;

    // "In stock only" toggle — offered only when at least one active product is
    // actually sold out (stock exhausted / all sizes gone), so it's meaningful.
    $has_soldout = false;
    if (function_exists('lmeg_product_is_available')) {
        foreach ($rows as $p) { if (!lmeg_product_is_available($p)) { $has_soldout = true; break; } }
    }
    // "On sale" toggle — offered when any product is on sale.
    $has_sale = false;
    if (function_exists('lmeg_product_on_sale')) {
        foreach ($rows as $p) { if (lmeg_product_on_sale($p)) { $has_sale = true; break; } }
    }
    $has_chiprow = $has_tags || $has_soldout || $has_sale;

    $chips = '';
    if ($has_chiprow) {
        $chips = '<div class="flp-tags" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px">';
        if ($has_tags) {
            $chips .= '<button type="button" class="flp-tag is-active" data-tag="">All</button>';
            foreach ($tag_counts as $k => $info) {
                $chips .= '<button type="button" class="flp-tag" data-tag="' . esc_attr($k) . '">' . esc_html($info['label']) . '</button>';
            }
        }
        $push = $has_tags ? ' style="margin-left:auto"' : '';
        if ($has_sale) {
            $chips .= '<button type="button" class="flp-onsale" aria-pressed="false"' . $push . '>' . lmeg_store_icon('tag', 12, ['style' => 'margin-right:4px;vertical-align:-2px']) . 'On sale</button>';
            $push = '';   // only the first right-group chip gets the push
        }
        if ($has_soldout) {
            $chips .= '<button type="button" class="flp-instock" aria-pressed="false"' . $push . '><span class="box">✓</span> In stock only</button>';
        }
        $chips .= '</div>';
    }

    $chip_css = $has_chiprow ? '<style>' : '';
    if ($has_tags) {
        $chip_css .= '#' . $uid . ' .flp-tag{background:#fff;color:#17141f;border:1px solid rgba(0,0,0,.18);border-radius:999px;padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;line-height:1.1;transition:background .12s,color .12s}'
          . '#' . $uid . ' .flp-tag:hover{border-color:#E15FA8;color:#E15FA8}'
          . '#' . $uid . ' .flp-tag.is-active{background:#E15FA8;color:#fff;border-color:#E15FA8}';
    }
    if ($has_soldout) {
        $chip_css .= '#' . $uid . ' .flp-instock{background:#fff;color:#17141f;border:1px solid rgba(0,0,0,.18);border-radius:999px;padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;line-height:1.1;display:inline-flex;align-items:center;gap:6px;transition:background .12s,color .12s}'
          . '#' . $uid . ' .flp-instock .box{width:14px;height:14px;border-radius:4px;border:1.5px solid rgba(0,0,0,.3);display:inline-grid;place-items:center;font-size:10px;line-height:1;color:transparent}'
          . '#' . $uid . ' .flp-instock.is-active{background:#ECFDF5;border-color:#047857;color:#047857}'
          . '#' . $uid . ' .flp-instock.is-active .box{background:#047857;border-color:#047857;color:#fff}';
    }
    if ($has_sale) {
        $chip_css .= '#' . $uid . ' .flp-onsale{background:#fff;color:#17141f;border:1px solid rgba(0,0,0,.18);border-radius:999px;padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;line-height:1.1;transition:background .12s,color .12s}'
          . '#' . $uid . ' .flp-onsale:hover{border-color:#DC2626;color:#DC2626}'
          . '#' . $uid . ' .flp-onsale.is-active{background:#FEE2E2;border-color:#DC2626;color:#DC2626}';
    }
    $chip_css .= $has_chiprow ? '</style>' : '';

    // "Load more" pagination for big catalogues (per="12"); 0 = show all.
    $per      = max(0, (int) $atts['per']);
    $paginate = ($per > 0 && count($rows) > $per);
    $more_btn = $paginate
        ? '<div style="text-align:center;margin-top:22px"><button type="button" class="flp-more" style="background:#fff;color:#17141f;border:1px solid rgba(0,0,0,.2);border-radius:999px;padding:11px 24px;font-size:14px;font-weight:700;cursor:pointer">Load more</button></div>'
        : '';

    $run_js = $show_ctrls || $has_tags || $has_soldout || $has_sale || $paginate;
    // Subtle card hover lift — hover-capable pointers only, disabled for reduced motion.
    $hover_css = '<style>@media(hover:hover){#' . $uid . ' .flp-prod{transition:transform .16s ease,box-shadow .16s ease}'
        . '#' . $uid . ' .flp-prod:hover{transform:translateY(-4px);box-shadow:0 22px 50px rgba(0,0,0,.16)}}'
        . '@media(prefers-reduced-motion:reduce){#' . $uid . ' .flp-prod{transition:none}#' . $uid . ' .flp-prod:hover{transform:none}}</style>';

    // Spotlight hero — the featured product shown large above the grid. Reuses the
    // real product card (so add-to-cart / variant / qty all work identically) and
    // restyles it to a horizontal, taller layout via scoped CSS.
    $hero_block = '';
    if ($hero_p) {
        // NB: .flp-prod carries INLINE flex-direction:column / width / height, so the
        // hero overrides that fight those need !important to win over the inline style.
        $h = '#' . $uid . ' .flp-hero';
        $hero_css = '<style>'
            . $h . '{margin:2px 0 26px}'
            . $h . '-eyebrow{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#8A6CF6;margin-bottom:10px}'
            . $h . ' .flp-prod{flex-direction:row!important;min-height:340px}'
            . $h . ' .flp-prod>div:first-child{flex:0 0 clamp(240px,44%,430px)!important;max-width:clamp(240px,44%,430px);align-self:stretch;position:relative}'
            . $h . ' .flp-prod>div:first-child a,' . $h . ' .flp-prod>div:first-child>div{height:100%}'
            . $h . ' .flp-prod img{width:100%!important;height:100%!important;aspect-ratio:auto!important;object-fit:cover}'
            . $h . ' .flp-prod>div:last-child{padding:30px 34px!important;justify-content:center}'
            . $h . ' .flp-prod .flp-quick{display:none!important}'
            . '@media(max-width:640px){' . $h . ' .flp-prod{flex-direction:column!important;min-height:0}' . $h . ' .flp-prod>div:first-child{flex:auto!important;max-width:none}' . $h . ' .flp-prod img{aspect-ratio:1/1!important}' . $h . ' .flp-prod>div:last-child{padding:22px 22px!important}}'
            . '</style>';
        $hero_block = $hero_css . '<div class="flp-hero"><div class="flp-hero-eyebrow">' . lmeg_store_icon('sparkles', 13) . 'Spotlight</div>' . lmeg_product_card_html($hero_p) . '</div>';
    }

    $out = $hover_css . $chip_css . lmeg_store_banner_html()
        . '<div class="flp-store-wrap" id="' . $uid . '">' . $hero_block . $controls . $chips
        . '<p class="flp-store-none" style="display:none;color:#6b6b78;padding:6px 2px">No products match your search.</p>'
        . '<div class="flp-store" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(' . $min . 'px,1fr));gap:20px;align-items:stretch">';
    foreach ($rows as $p) $out .= lmeg_product_card_html($p);
    $out .= '</div>' . $more_btn . '</div>';

    if ($run_js) {
        $out .= '<script>(function(){var root=document.getElementById(' . wp_json_encode($uid) . ');if(!root)return;'
            . 'var grid=root.querySelector(".flp-store"),none=root.querySelector(".flp-store-none"),q=root.querySelector(".flp-q"),sort=root.querySelector(".flp-sort");'
            . 'var cards=[].slice.call(grid.querySelectorAll(".flp-prod")),orig=cards.slice();'
            . 'var tagBtns=[].slice.call(root.querySelectorAll(".flp-tag")),stockBtn=root.querySelector(".flp-instock"),saleBtn=root.querySelector(".flp-onsale");'
            . 'var countEl=root.querySelector(".flp-count"),total=cards.length;'
            . 'var moreBtn=root.querySelector(".flp-more"),PER=' . (int) $per . ',shown=PER;'
            . 'function num(c,a){return parseInt(c.getAttribute(a),10)||0;}'
            . 'function activeTag(){var a=root.querySelector(".flp-tag.is-active");return a?(a.getAttribute("data-tag")||""):"";}'
            . 'function inStock(){return !!(stockBtn&&stockBtn.classList.contains("is-active"));}'
            . 'function onSale(){return !!(saleBtn&&saleBtn.classList.contains("is-active"));}'
            . 'function apply(){var t=((q&&q.value)||"").trim().toLowerCase(),tg=activeTag(),so=inStock(),sl=onSale();'
            . 'var vis=cards.filter(function(c){'
            . 'if(so&&c.getAttribute("data-avail")!=="1")return false;'
            . 'if(sl&&c.getAttribute("data-sale")!=="1")return false;'
            . 'if(tg){var ct=(c.getAttribute("data-tags")||"").split("|");if(ct.indexOf(tg)<0)return false;}'
            . 'if(!t)return true;return((c.getAttribute("data-title")||"")+" "+(c.getAttribute("data-desc")||"")).toLowerCase().indexOf(t)>=0;});'
            . 'var s=sort?sort.value:"featured",arr;'
            . 'if(s==="new")arr=vis.slice().sort(function(a,b){return num(b,"data-id")-num(a,"data-id");});'
            . 'else if(s==="price-asc")arr=vis.slice().sort(function(a,b){return num(a,"data-price")-num(b,"data-price");});'
            . 'else if(s==="price-desc")arr=vis.slice().sort(function(a,b){return num(b,"data-price")-num(a,"data-price");});'
            . 'else if(s==="sold")arr=vis.slice().sort(function(a,b){return num(b,"data-sold")-num(a,"data-sold");});'
            . 'else if(s==="name")arr=vis.slice().sort(function(a,b){return(a.getAttribute("data-title")||"").localeCompare(b.getAttribute("data-title")||"");});'
            . 'else arr=orig.filter(function(c){return vis.indexOf(c)>=0;});'
            . 'var revealed=(PER>0)?arr.slice(0,shown):arr;'
            . 'cards.forEach(function(c){c.style.display="none";});revealed.forEach(function(c){c.style.display="";grid.appendChild(c);});'
            . 'if(none)none.style.display=arr.length?"none":"";'
            . 'if(moreBtn)moreBtn.style.display=(PER>0&&arr.length>revealed.length)?"":"none";'
            . 'if(countEl)countEl.textContent=(arr.length===total)?(total+" item"+(total===1?"":"s")):(arr.length+" of "+total);save();}'
            . 'function refilter(){shown=PER;apply();}'
            . 'var SKEY="fanloop_store_filters";'
            . 'function save(){try{localStorage.setItem(SKEY,JSON.stringify({q:q?q.value:"",sort:sort?sort.value:"",tag:activeTag(),stock:inStock(),sale:onSale()}));}catch(e){}}'
            . 'function restore(){var s;try{s=JSON.parse(localStorage.getItem(SKEY)||"null");}catch(e){s=null;}if(!s)return false;var did=false;'
            . 'if(q&&typeof s.q==="string"&&s.q){q.value=s.q;did=true;}'
            . 'if(sort&&s.sort){var ok=false;[].forEach.call(sort.options,function(o){if(o.value===s.sort)ok=true;});if(ok){sort.value=s.sort;did=true;}}'
            . 'if(typeof s.tag==="string"&&s.tag&&tagBtns.length){var f=null;tagBtns.forEach(function(b){if((b.getAttribute("data-tag")||"")===s.tag)f=b;});if(f){tagBtns.forEach(function(b){b.classList.remove("is-active");});f.classList.add("is-active");did=true;}}'
            . 'if(s.stock&&stockBtn){stockBtn.classList.add("is-active");stockBtn.setAttribute("aria-pressed","true");did=true;}'
            . 'if(s.sale&&saleBtn){saleBtn.classList.add("is-active");saleBtn.setAttribute("aria-pressed","true");did=true;}'
            . 'return did;}'
            . 'tagBtns.forEach(function(b){b.addEventListener("click",function(){tagBtns.forEach(function(x){x.classList.remove("is-active");});b.classList.add("is-active");refilter();});});'
            . 'if(stockBtn)stockBtn.addEventListener("click",function(){stockBtn.classList.toggle("is-active");stockBtn.setAttribute("aria-pressed",stockBtn.classList.contains("is-active")?"true":"false");refilter();});'
            . 'if(saleBtn)saleBtn.addEventListener("click",function(){saleBtn.classList.toggle("is-active");saleBtn.setAttribute("aria-pressed",saleBtn.classList.contains("is-active")?"true":"false");refilter();});'
            . 'if(q)q.addEventListener("input",refilter);if(sort)sort.addEventListener("change",refilter);'
            . 'if(moreBtn)moreBtn.addEventListener("click",function(){shown+=PER;apply();});'
            . 'if(restore()||PER>0)apply();})();</script>';
    }
    return $out;
}

/**
 * Render a single product card (fills its container's width/height, so it works
 * standalone or inside the storefront grid). $solo = a single-product context
 * (product page / [fanloop_product]) where the full gallery viewer is shown.
 */
function lmeg_product_card_html($p, $link = true, $solo = false) {
    $cur      = $p->currency ?: 'USD';
    $fmt      = function ($c) use ($cur) { return function_exists('lmeg_format_price') ? lmeg_format_price((int) $c, $cur) : ('$' . number_format($c / 100, 2)); };
    $price    = $fmt($p->price_cents);
    $pwyw     = lmeg_product_is_pwyw($p);
    $on_sale  = lmeg_product_on_sale($p);
    $sale_pct = $on_sale ? lmeg_product_sale_pct($p) : 0;
    $was      = $on_sale ? $fmt($p->compare_at_cents) : '';
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
    $preorder  = lmeg_product_is_preorder($p);
    $predate   = $preorder ? lmeg_product_preorder_date($p) : '';
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
    <div class="flp-prod" data-title="<?php echo esc_attr($p->title); ?>" data-desc="<?php echo esc_attr($p->description); ?>" data-price="<?php echo (int) $p->price_cents; ?>" data-sold="<?php echo (int) $p->sold; ?>" data-id="<?php echo (int) $p->id; ?>" data-tags="<?php echo esc_attr(implode('|', array_map('strtolower', lmeg_product_tags($p)))); ?>" data-avail="<?php echo $sold_out ? 0 : 1; ?>" data-sale="<?php echo $on_sale ? 1 : 0; ?>" style="display:flex;flex-direction:column;width:100%;height:100%;border:1px solid rgba(0,0,0,.12);border-radius:16px;overflow:hidden;font-family:inherit;background:#fff;color:#17141f;box-shadow:0 12px 40px rgba(0,0,0,.08)">
      <?php
      $gallery = lmeg_product_gallery($p);
      $imgs = [];
      foreach (array_merge([$p->cover_url], $gallery) as $u) { $u = trim((string) $u); if ($u !== '' && !in_array($u, $imgs, true)) $imgs[] = $u; }
      if ($solo && count($imgs) > 1) :
        $mid = 'flpmain' . (int) $p->id; $gid = 'flpgal' . (int) $p->id; ?>
        <div class="flp-gal" id="<?php echo esc_attr($gid); ?>" data-imgs="<?php echo esc_attr(wp_json_encode(array_values($imgs))); ?>" data-alt="<?php echo esc_attr($p->title); ?>">
          <div style="position:relative">
            <img id="<?php echo esc_attr($mid); ?>" class="flp-gal-main" src="<?php echo esc_url($imgs[0]); ?>" alt="<?php echo esc_attr($p->title); ?>" data-idx="0" style="width:100%;display:block;aspect-ratio:1/1;object-fit:cover;cursor:zoom-in">
            <span class="flp-gal-zoom" aria-hidden="true" style="position:absolute;right:10px;top:10px;background:rgba(11,12,18,.62);color:#fff;font-size:12px;font-weight:600;padding:5px 10px;border-radius:999px;pointer-events:none;display:inline-flex;align-items:center;gap:5px"><?php echo lmeg_store_icon('maximize', 12); ?>Zoom</span>
          </div>
          <div style="display:flex;gap:7px;padding:9px 10px;overflow-x:auto;background:#faf9fc">
            <?php foreach ($imgs as $i => $u) : ?>
              <img src="<?php echo esc_url($u); ?>" alt="" data-main="<?php echo esc_attr($mid); ?>" data-idx="<?php echo (int) $i; ?>" class="flp-thumb" style="width:54px;height:54px;object-fit:cover;border-radius:8px;cursor:pointer;flex:0 0 auto;border:2px solid <?php echo $i === 0 ? '#E15FA8' : 'rgba(0,0,0,.12)'; ?>">
            <?php endforeach; ?>
          </div>
        </div>
        <div class="flp-lb" id="<?php echo esc_attr($gid); ?>lb" hidden style="position:fixed;inset:0;z-index:100002;background:rgba(6,7,12,.93);display:flex;align-items:center;justify-content:center">
          <button type="button" class="flp-lb-x" aria-label="Close" style="position:absolute;top:14px;right:16px;width:40px;height:40px;border-radius:50%;border:0;background:rgba(255,255,255,.12);color:#fff;cursor:pointer;display:grid;place-items:center"><?php echo lmeg_store_icon('x', 20); ?></button>
          <button type="button" class="flp-lb-prev" aria-label="Previous image" style="position:absolute;left:10px;width:46px;height:46px;border-radius:50%;border:0;background:rgba(255,255,255,.12);color:#fff;cursor:pointer;display:grid;place-items:center"><?php echo lmeg_store_icon('chevron-left', 26, ['stroke' => 2.2]); ?></button>
          <img class="flp-lb-img" src="" alt="" style="max-width:92vw;max-height:86vh;object-fit:contain;border-radius:8px;box-shadow:0 20px 70px rgba(0,0,0,.6)">
          <button type="button" class="flp-lb-next" aria-label="Next image" style="position:absolute;right:10px;width:46px;height:46px;border-radius:50%;border:0;background:rgba(255,255,255,.12);color:#fff;cursor:pointer;display:grid;place-items:center"><?php echo lmeg_store_icon('chevron-right', 26, ['stroke' => 2.2]); ?></button>
          <div class="flp-lb-count" style="position:absolute;bottom:16px;left:0;right:0;text-align:center;color:#CFD2DC;font-size:13px;font-weight:600"></div>
        </div>
        <script>
        (function(){
          var g=document.getElementById(<?php echo wp_json_encode($gid); ?>); if(!g) return;
          var imgs; try{imgs=JSON.parse(g.getAttribute('data-imgs')||'[]');}catch(e){imgs=[];}
          var alt=g.getAttribute('data-alt')||'';
          var main=g.querySelector('.flp-gal-main');
          var thumbs=[].slice.call(g.querySelectorAll('.flp-thumb'));
          var lb=document.getElementById(g.id+'lb');
          var limg=lb.querySelector('.flp-lb-img'), cnt=lb.querySelector('.flp-lb-count');
          var idx=0;
          function setMain(i){ idx=(i+imgs.length)%imgs.length; if(main){main.src=imgs[idx];main.setAttribute('data-idx',idx);} thumbs.forEach(function(t){t.style.borderColor=(+t.getAttribute('data-idx')===idx)?'#E15FA8':'rgba(0,0,0,.12)';}); }
          function show(i){ if(!imgs.length)return; idx=(i+imgs.length)%imgs.length; limg.src=imgs[idx]; limg.alt=alt; if(cnt)cnt.textContent=(idx+1)+' / '+imgs.length; }
          function openLb(){ show(idx); lb.hidden=false; }
          function closeLb(){ lb.hidden=true; setMain(idx); }
          thumbs.forEach(function(t){ t.addEventListener('click',function(){ setMain(+t.getAttribute('data-idx')); }); });
          if(main) main.addEventListener('click',openLb);
          lb.querySelector('.flp-lb-x').addEventListener('click',closeLb);
          lb.querySelector('.flp-lb-prev').addEventListener('click',function(e){e.stopPropagation();show(idx-1);});
          lb.querySelector('.flp-lb-next').addEventListener('click',function(e){e.stopPropagation();show(idx+1);});
          lb.addEventListener('click',function(e){ if(e.target===lb) closeLb(); });
          document.addEventListener('keydown',function(e){ if(lb.hidden)return; if(e.key==='Escape')closeLb(); else if(e.key==='ArrowLeft')show(idx-1); else if(e.key==='ArrowRight')show(idx+1); });
          setMain(0);
        })();
        </script>
      <?php elseif (!empty($imgs)) : $img = '<img src="' . esc_url($imgs[0]) . '" alt="' . esc_attr($p->title) . '" style="width:100%;display:block;aspect-ratio:1/1;object-fit:cover">';
        $badge = (count($imgs) > 1) ? '<span style="position:absolute;right:8px;bottom:8px;background:rgba(11,12,18,.72);color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px">▦ ' . count($imgs) . '</span>' : '';
        $wrapped = '<div style="position:relative;display:block">' . $img . $badge . '</div>';
        $linked  = $link ? '<a href="' . $url . '" style="display:block">' . $wrapped . '</a>' : $wrapped;
        $quick   = !$solo ? '<button type="button" class="flp-quick" aria-label="Quick look" style="position:absolute;left:8px;bottom:8px;background:rgba(255,255,255,.93);color:#17141f;border:0;border-radius:999px;padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer;box-shadow:0 2px 10px rgba(0,0,0,.22);display:inline-flex;align-items:center;gap:5px">' . lmeg_store_icon('search', 13) . 'Quick look</button>' : '';
        echo '<div style="position:relative">' . $linked . $quick . '</div>'; ?>
      <?php endif; ?>
      <div style="padding:18px 20px;display:flex;flex-direction:column;flex:1">
        <?php if (!empty($p->featured)) : ?><div style="align-self:flex-start;font-size:11px;font-weight:800;color:#8a6d00;background:#FEF9C3;padding:2px 9px;border-radius:999px;margin-bottom:8px;display:inline-flex;align-items:center;gap:4px"><?php echo lmeg_store_icon('star', 12, ['fill' => true]); ?>Featured</div><?php endif; ?>
        <?php if (empty($p->featured) && function_exists('lmeg_product_is_new') && lmeg_product_is_new($p)) : ?><div style="align-self:flex-start;font-size:11px;font-weight:800;color:#0f766e;background:#CCFBF1;padding:2px 9px;border-radius:999px;margin-bottom:8px;display:inline-flex;align-items:center;gap:4px"><?php echo lmeg_store_icon('sparkles', 12); ?>Just added</div><?php endif; ?>
        <?php
        if (!$solo && function_exists('lmeg_bundles_for_product')) {
            $pbundles = lmeg_bundles_for_product($p->id);
            if ($pbundles) {
                $bpct = 0; foreach ($pbundles as $bb) $bpct = max($bpct, (int) $bb->pct);
                echo '<div style="align-self:flex-start;font-size:11px;font-weight:800;color:#9d2b76;background:#FBE7F2;padding:2px 9px;border-radius:999px;margin-bottom:8px">🎁 Buy the set · save ' . (int) $bpct . '%</div>';
            }
        }
        ?>
        <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:4px">
          <div style="font-weight:750;font-size:19px;color:#17141f;flex:1;min-width:0"><?php echo $link ? '<a href="' . $url . '" style="color:#17141f;text-decoration:none">' . esc_html($p->title) . '</a>' : esc_html($p->title); ?><?php if ($physical) : ?> <span style="font-size:11px;color:#6b6b78;font-weight:600;vertical-align:middle">· ships</span><?php endif; ?></div>
          <?php if (!$solo) : ?><button type="button" class="flp-share" data-url="<?php echo esc_attr($url); ?>" title="Copy link to this product" aria-label="Copy link to this product" style="flex:0 0 auto;background:#fff;border:1px solid rgba(0,0,0,.14);color:#6b6b78;width:32px;height:32px;border-radius:9px;cursor:pointer;font-size:14px;line-height:1;display:inline-flex;align-items:center;justify-content:center"><?php echo lmeg_store_icon('link', 15); ?></button><?php endif; ?>
        </div>
        <?php if (!empty($p->description)) : ?><div style="font-size:14px;color:#454552;line-height:1.5;margin-bottom:14px"><?php echo esc_html($p->description); ?></div><?php endif; ?>
        <div style="margin-top:auto">
        <?php if ($on_sale && !$sold_out) : ?><div style="display:flex;align-items:baseline;gap:8px;margin-bottom:9px"><span style="font-size:19px;font-weight:800;color:#17141f"><?php echo esc_html($price); ?></span><span style="font-size:14px;color:#9A9DB0;text-decoration:line-through"><?php echo esc_html($was); ?></span><span style="font-size:12px;font-weight:800;color:#DC2626;background:#FEE2E2;padding:2px 8px;border-radius:999px">−<?php echo (int) $sale_pct; ?>%</span></div><?php endif; ?>
        <?php if (!$sold_out && $preorder) : ?><div style="font-size:12px;font-weight:700;color:#3730A3;background:#EEF2FF;display:inline-flex;align-items:center;gap:5px;padding:2px 10px;border-radius:999px;margin-bottom:9px"><?php echo lmeg_store_icon('calendar', 12); ?>Pre-order · <?php echo esc_html(($physical ? 'ships ' : 'available ') . $predate); ?></div>
        <?php elseif (!$sold_out && $low_stock) : ?><?php echo lmeg_product_lowstock_html($p->sold, $p->stock); ?>
        <?php elseif (!$sold_out && (int) $p->sold >= 5) : ?><div style="font-size:12px;font-weight:700;color:#047857;background:#ECFDF5;display:inline-flex;align-items:center;gap:5px;padding:2px 10px;border-radius:999px;margin-bottom:9px"><?php echo lmeg_store_icon('star', 12, ['fill' => true]); ?><?php echo esc_html(number_format((int) $p->sold)); ?> sold</div><?php endif; ?>
        <?php if (!$sold_out && $physical && !$pwyw) : $qmax = ((int) $p->stock >= 0) ? max(1, (int) $remaining) : 0; ?>
        <div class="flp-qtywrap" style="display:inline-flex;align-items:center;border:1px solid #d9d9e0;border-radius:10px;overflow:hidden;background:#fff;margin-bottom:11px">
          <button type="button" class="flp-qtir" data-d="-1" aria-label="Decrease quantity" style="width:36px;height:38px;border:0;background:#f4f4f6;color:#17141f;font-size:19px;line-height:1;cursor:pointer;padding:0">−</button>
          <input type="text" inputmode="numeric" pattern="[0-9]*" class="flp-qty" value="1" min="1" <?php echo $qmax ? 'max="' . (int) $qmax . '" ' : ''; ?>aria-label="Quantity" style="width:48px;height:38px;text-align:center;border:0;border-left:1px solid #ededf1;border-right:1px solid #ededf1;font-size:15px;font-weight:700;color:#17141f;background:#fff">
          <button type="button" class="flp-qtir" data-d="1" aria-label="Increase quantity" style="width:36px;height:38px;border:0;background:#f4f4f6;color:#17141f;font-size:19px;line-height:1;cursor:pointer;padding:0">+</button>
        </div>
        <?php endif; ?>
        <?php if ($sold_out) : ?>
          <div style="font-weight:700;color:#6b6b78">Sold out</div>
          <?php
          if (function_exists('lmeg_waitlist_form_html') && $p->status === 'active') {
              echo lmeg_waitlist_form_html($p);
              $waiting = function_exists('lmeg_waitlist_count') ? lmeg_waitlist_count($p->id) : 0;
              if ($waiting >= 3) echo '<div style="font-size:12px;color:#8a6d00;margin-top:6px;display:flex;align-items:center;gap:5px">' . lmeg_store_icon('bell', 12) . (int) $waiting . ' fans waiting for this to come back</div>';
          }
          ?>
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
/**
 * Sticky bottom buy-bar for the product page on mobile (≤640px). Simple
 * products get a direct .flp-add (adds to cart + opens the drawer); products
 * that need a size/price choice, or sold-out ones, get a button that scrolls
 * the buy card into view. Dark chrome; hidden on desktop via CSS.
 */
function lmeg_product_sticky_bar_html($p) {
    $cur      = $p->currency ?: 'USD';
    $fmt      = function ($c) use ($cur) { return function_exists('lmeg_format_price') ? lmeg_format_price((int) $c, $cur) : ('$' . number_format($c / 100, 2)); };
    $pwyw     = function_exists('lmeg_product_is_pwyw') && lmeg_product_is_pwyw($p);
    $physical = ($p->type === 'physical');
    $vlist    = function_exists('lmeg_product_variants') ? lmeg_product_variants($p) : [];
    $sold_out = function_exists('lmeg_product_is_available') ? !lmeg_product_is_available($p) : false;
    $simple   = !$sold_out && !$pwyw && empty($vlist);
    $price    = $pwyw ? 'Name your price' : $fmt($p->price_cents);

    $data = 'data-id="' . (int) $p->id . '" data-slug="' . esc_attr($p->slug)
          . '" data-title="' . esc_attr($p->title) . '" data-cover="' . esc_attr($p->cover_url)
          . '" data-price="' . (int) $p->price_cents . '" data-cur="' . esc_attr($cur)
          . '" data-type="' . esc_attr($p->type) . '" data-ship="' . ($physical ? (int) $p->shipping_cents : 0)
          . '" data-pwyw="' . ($pwyw ? 1 : 0) . '" data-min="' . (int) $p->min_price_cents
          . '" data-hasvar="' . (!empty($vlist) ? 1 : 0) . '"';

    $bs = 'border:0;border-radius:10px;padding:12px 20px;font-weight:800;font-size:15px;cursor:pointer;white-space:nowrap';
    if ($sold_out) {
        $btn = '<button type="button" disabled style="' . $bs . ';background:#33313c;color:#8B90A0;cursor:not-allowed">Sold out</button>';
    } elseif ($simple) {
        $btn = '<button type="button" class="flp-add" ' . $data . ' style="' . $bs . ';background:linear-gradient(118deg,#E15FA8,#8A6CF6);color:#0B0C12">Add to cart</button>';
    } else {
        $btn = '<button type="button" class="flp-goto-buy" style="' . $bs . ';background:linear-gradient(118deg,#E15FA8,#8A6CF6);color:#0B0C12">' . (($physical || $vlist) ? 'Choose options' : 'Buy now') . '</button>';
    }
    $thumb = !empty($p->cover_url) ? '<img src="' . esc_url($p->cover_url) . '" alt="" style="width:40px;height:40px;border-radius:8px;object-fit:cover;flex:0 0 auto">' : '';

    ob_start(); ?>
<style>
  .flp-sticky-buy{display:none}
  @media(max-width:640px){
    body{padding-bottom:84px}
    .flp-sticky-buy{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:99997;align-items:center;gap:12px;
      padding:12px 16px calc(12px + env(safe-area-inset-bottom,0px));background:rgba(14,16,23,.95);backdrop-filter:blur(8px);
      border-top:1px solid rgba(255,255,255,.1);box-shadow:0 -10px 30px rgba(0,0,0,.45)}
  }
  .flp-sticky-buy .t{flex:1;min-width:0;text-align:left}
  .flp-sticky-buy .t b{display:block;font-size:14px;color:#F4F2F7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:650}
  .flp-sticky-buy .t span{font-size:13px;color:#E7A6CF;font-weight:700}
</style>
<div class="flp-sticky-buy"><?php echo $thumb; ?><div class="t"><b><?php echo esc_html($p->title); ?></b><span><?php echo esc_html($price); ?></span></div><?php echo $btn; ?></div>
<script>(function(){var g=document.querySelector('.flp-goto-buy');if(!g)return;g.addEventListener('click',function(){var c=document.querySelector('.flp-prod');if(c)c.scrollIntoView({behavior:'smooth',block:'center'});});})();</script>
<?php
    return ob_get_clean();
}

/**
 * Open Graph / Twitter / product meta tags for a product page, so shared links
 * unfurl with a title, image, price and availability. Returns a block of
 * <meta> tags. All values escaped.
 */
function lmeg_product_meta_tags($p) {
    $site  = get_bloginfo('name');
    $url   = function_exists('lmeg_product_url') ? lmeg_product_url($p) : home_url('/');
    $cur   = strtoupper($p->currency ?: 'USD');
    $pwyw  = function_exists('lmeg_product_is_pwyw') && lmeg_product_is_pwyw($p);
    $avail = (function_exists('lmeg_product_is_available') && !lmeg_product_is_available($p)) ? 'oos' : 'instock';
    $desc  = trim((string) $p->description);
    if ($desc === '') $desc = $p->title . ($pwyw ? '' : ' — ' . (function_exists('lmeg_format_price') ? lmeg_format_price((int) $p->price_cents, $cur) : '$' . number_format($p->price_cents / 100, 2)));

    $m  = '<meta property="og:type" content="product">' . "\n";
    $m .= '<meta property="og:site_name" content="' . esc_attr($site) . '">' . "\n";
    $m .= '<meta property="og:title" content="' . esc_attr($p->title) . '">' . "\n";
    $m .= '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
    $m .= '<meta name="description" content="' . esc_attr($desc) . '">' . "\n";
    $m .= '<meta property="og:description" content="' . esc_attr($desc) . '">' . "\n";
    if (!$pwyw) {
        $amount = number_format($p->price_cents / 100, 2, '.', '');
        $m .= '<meta property="product:price:amount" content="' . esc_attr($amount) . '">' . "\n";
        $m .= '<meta property="product:price:currency" content="' . esc_attr($cur) . '">' . "\n";
        $m .= '<meta property="og:price:amount" content="' . esc_attr($amount) . '">' . "\n";
        $m .= '<meta property="og:price:currency" content="' . esc_attr($cur) . '">' . "\n";
    }
    $m .= '<meta property="product:availability" content="' . ($avail === 'oos' ? 'out of stock' : 'in stock') . '">' . "\n";
    $has_img = !empty($p->cover_url) && filter_var($p->cover_url, FILTER_VALIDATE_URL);
    if ($has_img) {
        $m .= '<meta property="og:image" content="' . esc_url($p->cover_url) . '">' . "\n";
        $m .= '<meta name="twitter:image" content="' . esc_url($p->cover_url) . '">' . "\n";
    }
    $m .= '<meta name="twitter:card" content="' . ($has_img ? 'summary_large_image' : 'summary') . '">' . "\n";
    $m .= '<meta name="twitter:title" content="' . esc_attr($p->title) . '">' . "\n";
    $m .= '<meta name="twitter:description" content="' . esc_attr($desc) . '">' . "\n";
    return $m;
}

/* Turn a plain-text size chart into an HTML table. One row per non-empty line,
 * cells split on "|", the first row is the header. Returns '' when empty. Cells
 * are escaped. $dark picks the on-brand dark-page palette (product page) vs a
 * light palette (admin preview). */
function lmeg_size_chart_html($text, $dark = true) {
    $text = trim((string) $text);
    if ($text === '') return '';
    $rows = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $rows[] = array_map('trim', explode('|', $line));
    }
    if (!$rows) return '';
    $ncol = 0; foreach ($rows as $r) $ncol = max($ncol, count($r));
    $bd   = $dark ? 'rgba(255,255,255,.14)' : '#e2e2e8';
    $htxt = $dark ? '#F4F2F7' : '#17141f';
    $hbg  = $dark ? 'rgba(255,255,255,.06)' : '#f5f4f8';
    $btxt = $dark ? '#C9CBD6' : '#3a3a44';
    $head = array_shift($rows);
    $cell = 'padding:7px 12px;border:1px solid ' . $bd . ';text-align:left;font-size:13px';
    $out  = '<table class="flp-sizes" style="border-collapse:collapse;width:100%;margin:2px 0 4px">';
    $out .= '<thead><tr>';
    for ($i = 0; $i < $ncol; $i++) $out .= '<th style="' . $cell . ';font-weight:700;color:' . $htxt . ';background:' . $hbg . '">' . esc_html($head[$i] ?? '') . '</th>';
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $out .= '<tr>';
        for ($i = 0; $i < $ncol; $i++) $out .= '<td style="' . $cell . ';color:' . $btxt . '">' . esc_html($r[$i] ?? '') . '</td>';
        $out .= '</tr>';
    }
    $out .= '</tbody></table>';
    return $out;
}

/* Turn a plain-text FAQ into a collapsible accordion. One "Question | Answer"
 * per non-empty line (split on the FIRST "|"; a line with no "|" is a heading-
 * only question with no body). Cells escaped; empty input → ''. $dark picks the
 * product-page palette. The chevron flips when its <details> is open. */
function lmeg_faq_html($text, $dark = true) {
    $text = trim((string) $text);
    if ($text === '') return '';
    $items = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = explode('|', $line, 2);
        $q = trim($parts[0]);
        $a = isset($parts[1]) ? trim($parts[1]) : '';
        if ($q === '') continue;
        $items[] = [$q, $a];
    }
    if (!$items) return '';
    $bd   = $dark ? 'rgba(255,255,255,.1)' : '#e6e6ec';
    $qtxt = $dark ? '#F4F2F7' : '#17141f';
    $atxt = $dark ? '#C9CBD6' : '#3a3a44';
    $mut  = $dark ? '#8B90A0' : '#9A9DB0';
    $out  = '<style>.flp-faq details>summary::-webkit-details-marker{display:none}.flp-faq details[open] .flp-faq-chev{transform:rotate(180deg)}.flp-faq-chev{transition:transform .18s ease}</style>';
    $out .= '<div class="flp-faq">';
    foreach ($items as $it) {
        $out .= '<details style="border-bottom:1px solid ' . $bd . '">'
            . '<summary style="cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 2px;font-weight:650;font-size:14px;color:' . $qtxt . '">'
            . '<span>' . esc_html($it[0]) . '</span>'
            . '<span class="flp-faq-chev" style="flex:0 0 auto;color:' . $mut . ';display:inline-flex">' . lmeg_store_icon('chevron-down', 16) . '</span>'
            . '</summary>';
        if ($it[1] !== '') $out .= '<div style="padding:0 2px 14px;font-size:14px;line-height:1.55;color:' . $atxt . ';white-space:pre-line">' . esc_html($it[1]) . '</div>';
        $out .= '</details>';
    }
    $out .= '</div>';
    return $out;
}

function lmeg_product_page() {
    $p = lmeg_product_by_slug(sanitize_title(wp_unslash($_GET['lmeg_product'])));
    if (!$p || $p->status !== 'active') { status_header(404); nocache_headers(); wp_die('This product is not available.', 'Not found', ['response' => 404]); }
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    $site = get_bloginfo('name');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo esc_html($p->title . ' · ' . $site); ?></title>
    <?php echo lmeg_product_meta_tags($p); ?>
    <style>
      *{box-sizing:border-box;margin:0}body{background:#0B0C12;color:#F4F2F7;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px 20px}
      .wrap{width:100%;max-width:440px}
      .back{display:block;text-align:center;margin-top:22px;color:#8B90A0;font-size:13px;text-decoration:none}
      .sig{margin-top:10px;text-align:center;color:#6C6F82;font-size:12px}
      .topback{position:fixed;top:16px;left:16px;z-index:5;display:inline-flex;align-items:center;gap:6px;color:#B9BCC9;font-size:13px;font-weight:600;text-decoration:none;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);padding:8px 14px;border-radius:999px;transition:background .15s,border-color .15s;-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px)}
      .topback:hover{background:rgba(255,255,255,.11);border-color:rgba(255,255,255,.28);color:#fff}
      @media(max-width:520px){.topback{top:12px;left:12px;font-size:12px;padding:7px 12px}}
    </style></head><body>
    <a class="topback" href="<?php echo esc_url(home_url('/')); ?>"><?php echo lmeg_store_icon('arrow-left', 14); ?><?php echo esc_html($site); ?></a>
    <div class="wrap">
      <?php echo lmeg_store_banner_html(); ?>
      <?php echo lmeg_product_card_html($p, false, true); ?>
      <?php if (!empty($p->size_chart) && ($sizeg = lmeg_size_chart_html($p->size_chart, true))) : ?>
      <details class="flp-sizeguide" style="margin-top:14px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:12px;overflow:hidden">
        <summary style="cursor:pointer;list-style:none;padding:13px 16px;font-weight:700;font-size:14px;color:#F4F2F7;display:flex;align-items:center;justify-content:space-between"><span style="display:inline-flex;align-items:center;gap:7px"><?php echo lmeg_store_icon('ruler', 15); ?>Size guide</span><span style="color:#8B90A0;font-size:12px;font-weight:600">tap to open</span></summary>
        <div style="padding:0 16px 14px;overflow-x:auto"><?php echo $sizeg; ?></div>
      </details>
      <?php endif; ?>
      <?php if (!empty($p->faq) && ($faqhtml = lmeg_faq_html($p->faq, true))) : ?>
      <div style="margin-top:16px">
        <div style="display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#8B90A0;margin-bottom:4px"><?php echo lmeg_store_icon('help', 14); ?>FAQ</div>
        <?php echo $faqhtml; ?>
      </div>
      <?php endif; ?>
      <?php if (function_exists('lmeg_bundle_widget_html')) echo lmeg_bundle_widget_html($p); ?>
      <?php
      $share_url = lmeg_product_url($p);
      $share_txt = $p->title . ' — ' . $site;
      $x_url  = 'https://twitter.com/intent/tweet?text=' . rawurlencode($share_txt) . '&url=' . rawurlencode($share_url);
      $fb_url = 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($share_url);
      $wa_url = 'https://wa.me/?text=' . rawurlencode($share_txt . ' ' . $share_url);
      $pill   = 'background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.16);color:#F4F2F7;font-size:13px;font-weight:650;padding:9px 14px;border-radius:999px;text-decoration:none;cursor:pointer;font-family:inherit';
      ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:16px">
        <button type="button" id="flp-copy" data-url="<?php echo esc_attr($share_url); ?>" style="<?php echo $pill; ?>;display:inline-flex;align-items:center;gap:7px"><?php echo lmeg_store_icon('link', 15); ?><span class="flp-copy-t">Copy link</span></button>
        <a href="<?php echo esc_url($x_url); ?>" target="_blank" rel="noopener" style="<?php echo $pill; ?>">Share on X</a>
        <a href="<?php echo esc_url($fb_url); ?>" target="_blank" rel="noopener" style="<?php echo $pill; ?>">Facebook</a>
        <a href="<?php echo esc_url($wa_url); ?>" target="_blank" rel="noopener" style="<?php echo $pill; ?>">WhatsApp</a>
      </div>
      <script>
      (function(){var b=document.getElementById('flp-copy');if(!b)return;var lbl=b.querySelector('.flp-copy-t')||b;b.addEventListener('click',function(){var u=b.getAttribute('data-url');function done(){var t=lbl.textContent;lbl.textContent='Copied';setTimeout(function(){lbl.textContent=t;},1600);}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(u).then(done,function(){window.prompt('Copy this link:',u);});}else{window.prompt('Copy this link:',u);}});})();
      </script>
      <a class="back" href="<?php echo esc_url(home_url('/')); ?>" style="display:inline-flex;align-items:center;gap:6px"><?php echo lmeg_store_icon('arrow-left', 14); ?><?php echo esc_html($site); ?></a>
    </div>
    <?php $rel = lmeg_product_related($p, 4); if ($rel) : ?>
    <div style="width:100%;max-width:720px;margin-top:36px">
      <div style="color:#8B90A0;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:12px;text-align:center">More from the shop</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px">
        <?php foreach ($rel as $r) : $rurl = esc_url(lmeg_product_url($r)); ?>
        <a href="<?php echo $rurl; ?>" style="text-decoration:none;color:inherit;display:block;background:#12141f;border:1px solid rgba(255,255,255,.08);border-radius:12px;overflow:hidden">
          <?php if (!empty($r->cover_url)) : ?><img src="<?php echo esc_url($r->cover_url); ?>" alt="" style="width:100%;aspect-ratio:1/1;object-fit:cover;display:block"><?php endif; ?>
          <div style="padding:9px 11px">
            <div style="color:#F4F2F7;font-weight:650;font-size:13px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo esc_html($r->title); ?></div>
            <div style="color:#E7A6CF;font-size:12px;font-weight:700;margin-top:2px"><?php echo esc_html(lmeg_product_is_pwyw($r) ? 'Name your price' : (function_exists('lmeg_format_price') ? lmeg_format_price((int) $r->price_cents, $r->currency ?: 'USD') : '$' . number_format($r->price_cents / 100, 2))); ?></div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php
    // Recently viewed — filled client-side from localStorage (excludes this product), then records this one.
    $rv_cur = wp_json_encode([
        'id'    => (int) $p->id,
        'title' => (string) $p->title,
        'cover' => (string) ($p->cover_url ?: ''),
        'url'   => (string) lmeg_product_url($p),
        'price' => lmeg_product_is_pwyw($p) ? 'Name your price'
                 : (function_exists('lmeg_format_price') ? lmeg_format_price((int) $p->price_cents, $p->currency ?: 'USD') : '$' . number_format($p->price_cents / 100, 2)),
    ]);
    ?>
    <div id="flp-recent" style="display:none;width:100%;max-width:720px;margin:34px auto 0"></div>
    <script>(function(){var KEY="fanloop_recent",CUR=<?php echo $rv_cur; ?>,CAP=8;
    function esc(s){return String(s==null?"":s).replace(/[&<>"]/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"}[c];});}
    var list=[];try{list=JSON.parse(localStorage.getItem(KEY))||[];}catch(e){}if(!Array.isArray(list))list=[];
    var others=list.filter(function(i){return i&&i.id!=CUR.id;}).slice(0,CAP);
    var wrap=document.getElementById("flp-recent");
    if(wrap&&others.length){var h='<div style="color:#8B90A0;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:12px;text-align:center">Recently viewed</div><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px">';
    others.forEach(function(i){h+='<a href="'+esc(i.url)+'" style="text-decoration:none;display:block;background:#12141f;border:1px solid rgba(255,255,255,.08);border-radius:12px;overflow:hidden">'+(i.cover?'<img src="'+esc(i.cover)+'" alt="" style="width:100%;aspect-ratio:1/1;object-fit:cover;display:block">':'<div style="width:100%;aspect-ratio:1/1;background:#20222E"></div>')+'<div style="padding:9px 11px"><div style="color:#F4F2F7;font-weight:650;font-size:13px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+esc(i.title)+'</div><div style="color:#E7A6CF;font-size:12px;font-weight:700;margin-top:2px">'+esc(i.price)+'</div></div></a>';});
    h+="</div>";wrap.innerHTML=h;wrap.style.display="";}
    list=list.filter(function(i){return i&&i.id!=CUR.id;});list.unshift(CUR);list=list.slice(0,CAP);
    try{localStorage.setItem(KEY,JSON.stringify(list));}catch(e){}})();</script>
    <?php echo lmeg_product_sticky_bar_html($p); ?>
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

    // Duplicate: clone as a fresh Draft. Keeps cover/gallery/price/variants;
    // does NOT copy the private uploaded file (avoids two products serving the
    // same file) — the unlock link, if any, is kept.
    if (($_POST['do'] ?? '') === 'duplicate' && $id) {
        $src = lmeg_product_get($id);
        if ($src) {
            $data = (array) $src;
            unset($data['id']);
            $data['title']     = $src->title . ' (copy)';
            $data['status']    = 'draft';
            $data['sold']      = 0;
            $data['file_path'] = null; $data['file_name'] = null; $data['file_size'] = 0;
            $data['created_at'] = current_time('mysql');
            $base = sanitize_title($src->slug ?: $src->title) . '-copy';
            $slug = $base;
            while ((int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $tbl WHERE slug = %s", $slug))) $slug = $base . '-' . wp_generate_password(4, false, false);
            $data['slug'] = $slug;
            $wpdb->insert($tbl, $data);
            wp_safe_redirect(admin_url('admin.php?page=lmeg-products&edit=' . (int) $wpdb->insert_id . '&saved=' . (int) $wpdb->insert_id)); exit;
        }
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

    // Gallery images (JSON array of URLs from the media picker).
    $gal = [];
    if (!empty($_POST['gallery'])) {
        $dec = json_decode(wp_unslash($_POST['gallery']), true);
        if (is_array($dec)) foreach ($dec as $u) { $u = esc_url_raw((string) $u); if ($u && !in_array($u, $gal, true)) $gal[] = $u; }
    }

    $data = [
        'title'           => $title,
        'slug'            => $slug,
        'description'     => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
        'cover_url'       => esc_url_raw($_POST['cover_url'] ?? ''),
        'gallery'         => $gal ? wp_json_encode(array_slice($gal, 0, 12)) : null,
        'size_chart'      => sanitize_textarea_field(wp_unslash($_POST['size_chart'] ?? '')),
        'faq'             => sanitize_textarea_field(wp_unslash($_POST['faq'] ?? '')),
        'price_cents'     => max(0, $to_cents($_POST['price'] ?? 0)),
        'compare_at_cents' => max(0, $to_cents($_POST['compare_at'] ?? 0)),
        'min_price_cents' => !empty($_POST['pwyw']) ? max(0, $to_cents($_POST['min_price'] ?? 0)) : 0,
        'currency'        => strtoupper(substr(sanitize_text_field($_POST['currency'] ?? 'USD'), 0, 3)) ?: 'USD',
        'type'            => in_array($_POST['type'] ?? 'digital', ['digital', 'physical'], true) ? $_POST['type'] : 'digital',
        'processor'       => in_array($_POST['processor'] ?? 'stripe', ['stripe', 'square'], true) ? $_POST['processor'] : 'stripe',
        'shipping_cents'  => max(0, $to_cents($_POST['shipping'] ?? 0)),
        'weight_g'        => max(0, (int) ($_POST['weight_g'] ?? 0)),
        'variants'        => implode(', ', $v_names),
        'variant_stock'   => $v_stock ? wp_json_encode($v_stock) : null,
        'deliver_url'     => esc_url_raw($_POST['deliver_url'] ?? ''),
        'deliver_note'    => sanitize_textarea_field(wp_unslash($_POST['deliver_note'] ?? '')),
        'stock'           => ($_POST['stock'] ?? '') === '' ? -1 : max(0, (int) $_POST['stock']),
        'status'          => in_array($_POST['status'] ?? 'active', ['active', 'draft'], true) ? $_POST['status'] : 'active',
        'preorder_at'     => !empty($_POST['preorder_at']) ? (sanitize_text_field($_POST['preorder_at']) . ' 00:00:00') : null,
        'featured'        => !empty($_POST['featured']) ? 1 : 0,
        'tags'            => lmeg_product_tags_normalize(sanitize_text_field(wp_unslash($_POST['tags'] ?? ''))) ?: null,
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

    $auto_notified = 0;
    if ($id) {
        $wpdb->update($tbl, $data, ['id' => $id]);
        // Back-in-stock: if this edit brought a sold-out product back, email the waitlist.
        if (function_exists('lmeg_waitlist_maybe_autonotify')) {
            $after = lmeg_product_get($id);
            $auto_notified = (int) lmeg_waitlist_maybe_autonotify($old, $after);
        }
    } else {
        $data['sold'] = 0; $data['created_at'] = current_time('mysql'); $wpdb->insert($tbl, $data); $id = (int) $wpdb->insert_id;
    }
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products&saved=' . $id . ($auto_notified ? '&notified_wl=' . $auto_notified : ''))); exit;
}

add_action('admin_post_lmeg_ship_order', 'lmeg_handle_ship_order');
function lmeg_handle_ship_order() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_ship_order', 'lmeg_ship_nonce');
    global $wpdb;
    $ptbl = $wpdb->prefix . 'lmeg_product_purchases';
    $pid  = (int) ($_POST['purchase_id'] ?? 0);
    $to   = (($_POST['to'] ?? 'shipped') === 'unshipped') ? 'unshipped' : 'shipped';
    if (!$pid) { wp_safe_redirect(admin_url('admin.php?page=lmeg-products#orders')); exit; }

    $data = ['fulfillment' => $to];
    if ($to === 'shipped') {
        $data['carrier']  = sanitize_text_field(wp_unslash($_POST['carrier'] ?? '')) ?: null;
        $data['tracking'] = sanitize_text_field(wp_unslash($_POST['tracking'] ?? '')) ?: null;
    }
    $wpdb->update($ptbl, $data, ['id' => $pid]);

    // Email the buyer that it's on the way (with tracking, if provided).
    if ($to === 'shipped') {
        $pur = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ptbl WHERE id = %d", $pid));
        if ($pur && $pur->email) lmeg_product_send_shipped($pur);
    }
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products&shipped=1#orders')); exit;
}

/** Build a tracking URL from a carrier + number (or pass through a pasted URL). */
function lmeg_tracking_url($carrier, $number) {
    $number = trim((string) $number);
    if ($number === '') return '';
    if (preg_match('~^https?://~i', $number)) return esc_url_raw($number);   // buyer pasted a full link
    $n = rawurlencode($number);
    switch (strtolower(trim((string) $carrier))) {
        case 'usps':        return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . $n;
        case 'ups':         return 'https://www.ups.com/track?tracknum=' . $n;
        case 'fedex':       return 'https://www.fedex.com/fedextrack/?trknbr=' . $n;
        case 'canada post': return 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=' . $n;
        case 'dhl':         return 'https://www.dhl.com/en/express/tracking.html?AWB=' . $n;
        default:            return '';   // unknown carrier & not a URL → show the number as plain text
    }
}

/** Email the buyer a branded "your order shipped" notice (with tracking). */
function lmeg_product_send_shipped($pur) {
    if (!function_exists('lmeg_email_deliver')) return;
    $p      = lmeg_product_get((int) $pur->product_id);
    $title  = $p ? $p->title : 'your order';
    $artist = lmeg_email_artist();
    $url    = lmeg_tracking_url($pur->carrier ?? '', $pur->tracking ?? '');

    $inner = lmeg_email_h('Your order shipped 📦')
        . lmeg_email_p('Good news — <strong>' . esc_html($title) . '</strong>' . (!empty($pur->variant) ? ' (' . esc_html($pur->variant) . ')' : '')
            . ' is on its way' . (!empty($pur->ship_name) ? ' to ' . esc_html($pur->ship_name) : '') . '.');
    if (!empty($pur->tracking) || !empty($pur->carrier)) {
        $inner .= lmeg_email_label('Tracking')
            . lmeg_email_p(trim(esc_html($pur->carrier ?: '') . (!empty($pur->carrier) && !empty($pur->tracking) ? ' · ' : '') . esc_html($pur->tracking ?: '')));
    }
    if ($url) $inner .= lmeg_email_button('Track your package →', $url);
    $inner .= lmeg_email_note('Questions about your order? Just reply to this email.');
    lmeg_email_deliver($pur->email, 'Your order shipped: ' . $title, $inner, $title . ' is on its way.');
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

/**
 * Build CSV rows (header + one per order line) from paid purchase rows.
 * Text cells starting with =,+,-,@ are prefixed with ' to defuse spreadsheet
 * formula injection. Separated out so it's unit-testable.
 */
function lmeg_orders_csv_rows($rows) {
    $safe = function ($v) {
        $v = (string) $v;
        return ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) ? "'" . $v : $v;
    };
    $cents = function ($c) { return number_format(((int) $c) / 100, 2, '.', ''); };
    $out = [['Date', 'Product', 'Variant', 'Qty', 'Buyer email', 'Amount', 'Currency', 'Discount code', 'Discount', 'Payment', 'Fulfillment', 'Tracking', 'Carrier', 'Ship name', 'Ship address']];
    foreach ((array) $rows as $r) {
        $out[] = [
            $r->paid_at,
            $safe($r->title),
            $safe($r->variant),
            (int) (isset($r->qty) ? $r->qty : 1) ?: 1,
            $safe($r->email),
            $cents($r->amount_cents),
            $r->currency,
            $safe($r->discount_code ?? ''),
            !empty($r->discount_cents) ? $cents($r->discount_cents) : '',
            $r->processor,
            $r->fulfillment,
            $safe($r->tracking ?? ''),
            $safe($r->carrier ?? ''),
            $safe($r->ship_name ?? ''),
            $safe($r->ship_address ? str_replace("\n", ', ', $r->ship_address) : ''),
        ];
    }
    return $out;
}

add_action('admin_post_lmeg_export_orders', 'lmeg_handle_export_orders');
function lmeg_handle_export_orders() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_export_orders');
    global $wpdb;
    $ptbl = $wpdb->prefix . 'lmeg_product_purchases';
    $tbl  = $wpdb->prefix . 'lmeg_products';
    $rows = $wpdb->get_results("SELECT pp.*, pr.title FROM $ptbl pp LEFT JOIN $tbl pr ON pr.id = pp.product_id WHERE pp.status = 'paid' ORDER BY pp.id DESC");

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fanloop-orders-' . gmdate('Y-m-d') . '.csv"');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel reads accents correctly
    foreach (lmeg_orders_csv_rows($rows) as $line) fputcsv($fh, $line);
    fclose($fh);
    exit;
}

/* ---------------------------------------------------------------------------
 * Products CSV — bulk export + import (manage the whole catalogue at once)
 * ------------------------------------------------------------------------- */

/** Canonical CSV column order. */
function lmeg_products_csv_headers() {
    return ['Title', 'Slug', 'Description', 'Type', 'Price', 'Compare at', 'Min price', 'Currency', 'Shipping', 'Weight (g)', 'Stock', 'Variants', 'Tags', 'Featured', 'Status', 'Preorder date', 'Cover URL', 'Deliver URL', 'Deliver note'];
}

/** Rebuild a "S:10, M:5, L" variants string from the stored variants + variant_stock JSON. */
function lmeg_products_variants_to_str($variants, $variant_stock) {
    $names = array_filter(array_map('trim', explode(',', (string) $variants)));
    if (!$names) return '';
    $stock = [];
    if ($variant_stock) { $d = json_decode($variant_stock, true); if (is_array($d)) $stock = $d; }
    $parts = [];
    foreach ($names as $n) $parts[] = isset($stock[$n]) ? ($n . ':' . (int) $stock[$n]) : $n;
    return implode(', ', $parts);
}

/** Build export rows (header + one per product); text cells starting =,+,-,@ are defused. Testable. */
function lmeg_products_csv_rows($rows) {
    $safe  = function ($v) { $v = (string) $v; return ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) ? "'" . $v : $v; };
    $money = function ($c) { return ((int) $c) > 0 ? number_format(((int) $c) / 100, 2, '.', '') : ''; };
    $out = [lmeg_products_csv_headers()];
    foreach ((array) $rows as $p) {
        $type = (($p->type ?? '') === 'physical') ? 'physical' : 'digital';
        $out[] = [
            $safe($p->title),
            $p->slug,
            $safe($p->description ?? ''),
            $type,
            $money($p->price_cents ?? 0),
            $money($p->compare_at_cents ?? 0),
            $money($p->min_price_cents ?? 0),
            strtoupper($p->currency ?: 'USD'),
            ($type === 'physical') ? $money($p->shipping_cents ?? 0) : '',
            ((int) ($p->weight_g ?? 0)) ?: '',
            (((int) ($p->stock ?? -1)) < 0) ? '' : (int) $p->stock,
            $safe(lmeg_products_variants_to_str($p->variants ?? '', $p->variant_stock ?? '')),
            $safe($p->tags ?? ''),
            (!empty($p->featured) ? '1' : '0'),
            (($p->status ?? '') === 'draft') ? 'draft' : 'active',
            (!empty($p->preorder_at) && strtotime($p->preorder_at)) ? date('Y-m-d', strtotime($p->preorder_at)) : '',
            $p->cover_url ?? '',
            $p->deliver_url ?? '',
            $safe($p->deliver_note ?? ''),
        ];
    }
    return $out;
}

/** Parse a CSV string → ['headers'=>[lowercased], 'rows'=>[assoc(lowerheader=>value)]]. Testable. */
function lmeg_products_csv_parse($str) {
    $str = preg_replace('/^\xEF\xBB\xBF/', '', (string) $str);   // strip UTF-8 BOM
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $str);
    rewind($fh);
    $headers = null; $rows = [];
    while (($cells = fgetcsv($fh)) !== false) {
        if ($cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) continue;   // blank line
        if ($headers === null) {
            $headers = array_map(function ($h) { return strtolower(trim((string) $h)); }, $cells);
            continue;
        }
        $row = [];
        foreach ($headers as $i => $h) { if ($h === '') continue; $row[$h] = isset($cells[$i]) ? (string) $cells[$i] : ''; }
        $rows[] = $row;
    }
    fclose($fh);
    return ['headers' => $headers ?: [], 'rows' => $rows];
}

/**
 * Validate + normalize one import row into product DB fields (no DB access, so
 * unit-testable). Returns ['ok'=>false,'error'=>..] or
 * ['ok'=>true,'title'=>..,'slug_in'=>..,'data'=>[..fields..]].
 */
function lmeg_products_import_prepare($row) {
    $g = function ($k) use ($row) { return isset($row[$k]) ? trim((string) $row[$k]) : ''; };
    $to_cents = function ($v) { $v = preg_replace('/[^0-9.]/', '', (string) $v); return max(0, (int) round(((float) $v) * 100)); };
    $truthy = function ($v) { return in_array(strtolower(trim((string) $v)), ['1', 'yes', 'y', 'true', 'on'], true); };

    $title = $g('title');
    if ($title === '') return ['ok' => false, 'error' => 'missing title'];

    $type = (strtolower($g('type')) === 'physical') ? 'physical' : 'digital';

    // variants "S:10, M:5, L"
    $v_names = []; $v_stock = [];
    foreach (explode(',', $g('variants')) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (strpos($part, ':') !== false) {
            list($vn, $vq) = array_map('trim', explode(':', $part, 2));
            if ($vn === '') continue;
            $v_names[] = $vn;
            if ($vq !== '' && is_numeric($vq)) $v_stock[$vn] = max(0, (int) $vq);
        } else { $v_names[] = $part; }
    }

    $stock_raw = $g('stock');
    $st = strtolower($g('status'));
    $status = ($st === 'active') ? 'active' : 'draft';   // default draft on import — safer than auto-publishing
    $pre_ts = ($g('preorder date') !== '') ? strtotime($g('preorder date')) : false;

    $data = [
        'title'           => $title,
        'description'     => $g('description'),
        'cover_url'       => function_exists('esc_url_raw') ? esc_url_raw($g('cover url')) : $g('cover url'),
        'price_cents'     => $to_cents($g('price')),
        'compare_at_cents' => $to_cents($g('compare at')),
        'min_price_cents' => $to_cents($g('min price')),
        'currency'        => (strtoupper(substr($g('currency'), 0, 3)) ?: 'USD'),
        'type'            => $type,
        'processor'       => 'stripe',
        'shipping_cents'  => ($type === 'physical') ? $to_cents($g('shipping')) : 0,
        'weight_g'        => max(0, (int) $g('weight (g)')),
        'variants'        => implode(', ', $v_names),
        'variant_stock'   => $v_stock ? wp_json_encode($v_stock) : null,
        'deliver_url'     => function_exists('esc_url_raw') ? esc_url_raw($g('deliver url')) : $g('deliver url'),
        'deliver_note'    => $g('deliver note'),
        'stock'           => ($stock_raw === '') ? -1 : max(0, (int) $stock_raw),
        'status'          => $status,
        'preorder_at'     => $pre_ts ? (date('Y-m-d', $pre_ts) . ' 00:00:00') : null,
        'featured'        => $truthy($g('featured')) ? 1 : 0,
        'tags'            => function_exists('lmeg_product_tags_normalize') ? (lmeg_product_tags_normalize($g('tags')) ?: null) : ($g('tags') ?: null),
    ];
    return ['ok' => true, 'title' => $title, 'slug_in' => function_exists('sanitize_title') ? sanitize_title($g('slug')) : $g('slug'), 'data' => $data];
}

add_action('admin_post_lmeg_export_products', 'lmeg_handle_export_products');
function lmeg_handle_export_products() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_export_products');
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lmeg_products ORDER BY id DESC");
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fanloop-products-' . gmdate('Y-m-d') . '.csv"');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    foreach (lmeg_products_csv_rows($rows) as $line) fputcsv($fh, $line);
    fclose($fh);
    exit;
}

add_action('admin_post_lmeg_import_products', 'lmeg_handle_import_products');
function lmeg_handle_import_products() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_import_products', 'lmeg_import_nonce');
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_products';
    $back = admin_url('admin.php?page=lmeg-products&imported=1');

    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        set_transient('lmeg_import_result', ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['No file was uploaded.']], 120);
        wp_safe_redirect($back); exit;
    }
    $str    = (string) file_get_contents($_FILES['csv']['tmp_name']);
    $parsed = lmeg_products_csv_parse($str);
    $created = 0; $updated = 0; $skipped = 0; $errors = [];
    $rownum = 1;   // header is row 1
    foreach ($parsed['rows'] as $row) {
        $rownum++;
        $prep = lmeg_products_import_prepare($row);
        if (empty($prep['ok'])) { $skipped++; if (count($errors) < 25) $errors[] = "Row $rownum: " . $prep['error']; continue; }
        $data = $prep['data'];
        $slug = $prep['slug_in'] ?: sanitize_title($prep['title']);
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM $tbl WHERE slug = %s", $slug));
        if ($existing) {
            $wpdb->update($tbl, $data, ['id' => (int) $existing->id]);   // keeps slug + sold + created_at
            $updated++;
        } else {
            $base = $slug; $k = 1;
            while ((int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $tbl WHERE slug = %s", $slug))) $slug = $base . '-' . (++$k);
            $wpdb->insert($tbl, array_merge($data, ['slug' => $slug, 'sold' => 0, 'created_at' => current_time('mysql')]));
            $created++;
        }
    }
    set_transient('lmeg_import_result', ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors], 120);
    wp_safe_redirect($back); exit;
}

/**
 * Getting-started checklist for the Store admin. Returns '' once every step is
 * done (so it disappears when the shop is set up). Steps are derived from state:
 * payment connected, a live product, the storefront placed on a page, a sale made.
 */
function lmeg_store_checklist_html($units) {
    global $wpdb;
    $keys   = function_exists('lmeg_stripe_keys') ? lmeg_stripe_keys() : [];
    $pay    = !empty($keys['sk']) || (function_exists('lmeg_square_ready') && lmeg_square_ready());
    $prod   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lmeg_products WHERE status='active'") > 0;
    $placed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND (post_content LIKE '%[fanloop_store%' OR post_content LIKE '%[fanloop_product%' OR post_content LIKE '%[loony_store%' OR post_content LIKE '%[loony_product%')") > 0;
    $sale   = ((int) $units) > 0;

    $steps = [
        ['ok' => $pay,    'label' => 'Connect a payment processor', 'hint' => 'Stripe or Square — that\'s where the money lands.',       'link' => admin_url('admin.php?page=lmeg-settings#payments'), 'cta' => 'Connect'],
        ['ok' => $prod,   'label' => 'Add your first product',       'hint' => 'A single, a bundle, some merch — or edit a sample.',      'link' => admin_url('admin.php?page=lmeg-products&new=1'),     'cta' => 'New product'],
        ['ok' => $placed, 'label' => 'Put your shop on a page',      'hint' => 'Paste <code>[fanloop_store]</code> into any page or post.', 'link' => '',                                                'cta' => ''],
        ['ok' => $sale,   'label' => 'Make a test sale',            'hint' => 'Flip on Demo checkout to walk the whole flow for free.', 'link' => admin_url('admin.php?page=lmeg-settings#payments'), 'cta' => 'Enable demo'],
    ];
    $done = 0; foreach ($steps as $s) if ($s['ok']) $done++;
    if ($done >= count($steps)) return '';

    $rows = '';
    foreach ($steps as $s) {
        $mark = $s['ok']
            ? '<span style="color:#34D399;font-weight:800">✓</span>'
            : '<span style="display:inline-block;width:14px;height:14px;border:2px solid #6C6F82;border-radius:50%"></span>';
        $cta = (!$s['ok'] && $s['link'] && $s['cta']) ? ' <a href="' . esc_url($s['link']) . '" style="color:#E7A6CF;text-decoration:none;font-weight:600;white-space:nowrap">' . esc_html($s['cta']) . ' →</a>' : '';
        $rows .= '<div style="display:flex;gap:11px;align-items:flex-start;padding:9px 0;border-top:1px solid rgba(255,255,255,.06)">'
            . '<div style="flex:0 0 16px;margin-top:2px;text-align:center">' . $mark . '</div>'
            . '<div style="flex:1"><div style="font-weight:650;color:' . ($s['ok'] ? '#8B90A0' : '#F4F5F7') . '">' . esc_html($s['label']) . $cta . '</div>'
            . '<div style="font-size:12px;color:#8B90A0;margin-top:1px">' . $s['hint'] . '</div></div></div>';
    }
    $pct = (int) round($done / count($steps) * 100);
    return '<div style="max-width:640px;margin:14px 0 20px;background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:18px 20px;color:#F4F5F7">'
        . '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px"><strong style="font-size:15px">Get your shop live</strong><span style="color:#8B90A0;font-size:13px">' . $done . ' of ' . count($steps) . '</span></div>'
        . '<div style="height:6px;background:rgba(255,255,255,.1);border-radius:99px;overflow:hidden;margin-bottom:6px"><div style="height:100%;width:' . $pct . '%;background:linear-gradient(90deg,#E15FA8,#8A6CF6)"></div></div>'
        . $rows . '</div>';
}

/**
 * "Top products by revenue" mini bar-list for the Store dashboard (admin light
 * surface). $rows: objects with pid, rev (cents), n (sales), title — ordered
 * revenue DESC. $fmt: cents→money formatter. Returns '' when there are no sales.
 */
function lmeg_admin_top_products_html($rows, $fmt) {
    $rows = array_values(array_filter((array) $rows, function ($r) { return (int) $r->rev > 0; }));
    if (!$rows) return '';
    $maxrev = max(1, (int) $rows[0]->rev);
    $medals = ['🥇', '🥈', '🥉'];
    $items = '';
    foreach ($rows as $i => $r) {
        $pct  = (int) round(min(100, max(2, (int) $r->rev / $maxrev * 100)));
        $name = $r->title ?: ('Product #' . (int) $r->pid);
        $items .= '<div style="display:flex;align-items:center;gap:10px;padding:7px 0">'
            . '<span style="font-size:15px;width:20px;text-align:center;flex:0 0 auto">' . ($medals[$i] ?? '#' . ($i + 1)) . '</span>'
            . '<div style="flex:1;min-width:0">'
            . '<div style="display:flex;justify-content:space-between;gap:10px;font-size:13px;color:#17141f;margin-bottom:3px">'
            . '<span style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . esc_html($name) . '</span>'
            . '<span style="white-space:nowrap;font-weight:700">' . esc_html($fmt((int) $r->rev)) . ' <span style="color:#8a8a94;font-weight:400">· ' . (int) $r->n . ' sale' . ((int) $r->n === 1 ? '' : 's') . '</span></span></div>'
            . '<div style="height:6px;border-radius:999px;background:#F0EEF4;overflow:hidden"><div style="height:100%;width:' . $pct . '%;background:linear-gradient(90deg,#E15FA8,#8A6CF6);border-radius:999px"></div></div>'
            . '</div></div>';
    }
    return '<div style="max-width:980px;margin:0 0 20px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:13px 18px">'
        . '<div style="font-weight:700;color:#17141f;margin-bottom:6px;display:flex;align-items:center;gap:6px">' . lmeg_store_icon('trophy', 15) . 'Top products</div>' . $items . '</div>';
}

/**
 * Small up/down percentage chip comparing $cur to $prev (admin light surface).
 * Green ▲ for a rise, red ▼ for a drop, a green "▲ new" when there's no prior
 * period to compare, and '' when both periods are empty.
 */
function lmeg_admin_pct_chip($cur, $prev, $suffix = '') {
    $cur = (int) $cur; $prev = (int) $prev;
    $tail = $suffix ? ' <span style="opacity:.7;font-weight:500">' . esc_html($suffix) . '</span>' : '';
    $wrap = function ($col, $bg, $txt) use ($tail) {
        return '<span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;margin-top:6px;padding:2px 9px;border-radius:999px;color:' . $col . ';background:' . $bg . '">' . $txt . $tail . '</span>';
    };
    if ($prev <= 0) {
        return $cur > 0 ? $wrap('#059669', '#ECFDF5', '▲ new') : '';
    }
    $pct = (int) round(($cur - $prev) / $prev * 100);
    return ($pct >= 0)
        ? $wrap('#059669', '#ECFDF5', '▲ ' . $pct . '%')
        : $wrap('#DC2626', '#FEE2E2', '▼ ' . abs($pct) . '%');
}

/* Per-product stock analysis (pure). Handles product-level stock (stock>=0,
 * remaining = stock - sold) and per-variant remaining (variant_stock JSON via
 * lmeg_product_variants). 'remaining' is the min across whatever is tracked
 * (drives urgency); 'on_hand' is units in stock for inventory totals (variant
 * sum when variant-tracked, else product remaining). Untracked → tracked=false. */
function lmeg_product_remaining($p) {
    $product_tracked = ((int) $p->stock >= 0);
    $prem = $product_tracked ? ((int) $p->stock - (int) $p->sold) : null;
    $vars = []; $vtracked = false;
    if (function_exists('lmeg_product_variants')) {
        foreach (lmeg_product_variants($p) as $v) {
            if ($v['stock'] !== null) { $vtracked = true; $vars[] = ['name' => $v['name'], 'remaining' => (int) $v['stock']]; }
        }
    }
    $cands = [];
    if ($product_tracked) $cands[] = (int) $prem;
    foreach ($vars as $v) $cands[] = $v['remaining'];
    $min = $cands ? min($cands) : null;
    if ($vtracked)            { $on_hand = 0; foreach ($vars as $v) $on_hand += max(0, $v['remaining']); }
    elseif ($product_tracked) { $on_hand = max(0, (int) $prem); }
    else                      { $on_hand = 0; }
    return [
        'tracked'           => ($product_tracked || $vtracked),
        'product_tracked'   => $product_tracked,
        'variant_tracked'   => $vtracked,
        'remaining'         => $min,
        'product_remaining' => $prem,
        'variants'          => $vars,
        'on_hand'           => $on_hand,
    ];
}

/* Aggregate stock report (pure). Classifies tracked products into out-of-stock
 * (remaining<=0) and running-low (0<remaining<=threshold), most-urgent first,
 * and totals units on hand + inventory value per currency. Untracked products
 * are skipped. */
function lmeg_products_lowstock($rows, $threshold = 5) {
    $threshold = max(0, (int) $threshold);
    $out = []; $low = []; $tracked = 0; $units = 0; $value = [];
    foreach ((array) $rows as $p) {
        $st = lmeg_product_remaining($p);
        if (!$st['tracked']) continue;
        $tracked++;
        $units += (int) $st['on_hand'];
        $cur = strtoupper((!empty($p->currency)) ? $p->currency : 'USD');
        if (!isset($value[$cur])) $value[$cur] = 0;
        $value[$cur] += (int) $st['on_hand'] * (int) $p->price_cents;
        $rem  = (int) $st['remaining'];
        $item = ['p' => $p, 'remaining' => $rem, 'on_hand' => (int) $st['on_hand'], 'variants' => $st['variants'], 'product_remaining' => $st['product_remaining'], 'variant_tracked' => $st['variant_tracked']];
        if ($rem <= 0)              $out[] = $item;
        elseif ($rem <= $threshold) $low[] = $item;
    }
    $sorter = function ($a, $b) {
        if ($a['remaining'] === $b['remaining']) return strcasecmp((string) $a['p']->title, (string) $b['p']->title);
        return $a['remaining'] - $b['remaining'];
    };
    usort($out, $sorter); usort($low, $sorter);
    return ['out' => $out, 'low' => $low, 'tracked' => $tracked, 'units' => $units, 'value' => $value, 'threshold' => $threshold];
}

/* Render the inventory / low-stock card for the Store admin (light surface). */
function lmeg_admin_lowstock_html($rows, $threshold = 5) {
    $rep = lmeg_products_lowstock($rows, $threshold);
    if ($rep['tracked'] === 0) return '';   // no tracked inventory at all — nothing useful to show
    $fmt = function ($cents, $cur) { return function_exists('lmeg_format_price') ? lmeg_format_price((int) $cents, $cur) : ('$' . number_format($cents / 100, 2)); };

    $vchips = function ($item) use ($threshold) {
        if (empty($item['variant_tracked']) || !$item['variants']) return '';
        $out = '';
        foreach ($item['variants'] as $v) {
            $r = (int) $v['remaining'];
            if ($r <= 0)          { $c = '#B91C1C'; $bg = '#FEE2E2'; }
            elseif ($r <= $threshold) { $c = '#92400E'; $bg = '#FEF3C7'; }
            else                  { $c = '#3f6212'; $bg = '#ECFCCB'; }
            $out .= '<span style="display:inline-block;font-size:11px;font-weight:700;color:' . $c . ';background:' . $bg . ';padding:1px 7px;border-radius:999px;margin:2px 4px 0 0">' . esc_html($v['name']) . ': ' . $r . '</span>';
        }
        return '<div style="margin-top:3px">' . $out . '</div>';
    };
    $row = function ($item, $accent) use ($fmt, $vchips) {
        $p = $item['p'];
        $cur = !empty($p->currency) ? $p->currency : 'USD';
        $url = admin_url('admin.php?page=lmeg-products&edit=' . (int) $p->id);
        $draft = (isset($p->status) && $p->status === 'draft') ? ' <span style="font-size:11px;color:#6b6b78;background:#eef0f4;padding:1px 7px;border-radius:999px">draft</span>' : '';
        $rem = (int) $item['remaining'];
        $label = $rem <= 0 ? 'Out' : ($rem . ' left');
        return '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:9px 0;border-top:1px solid #f0f0f2">'
            . '<div style="min-width:0"><a href="' . esc_url($url) . '" style="font-weight:600;color:#17141f;text-decoration:none">' . esc_html($p->title) . '</a>' . $draft
            . '<span style="color:#8a8a94;font-size:12px"> · ' . esc_html($fmt((int) $p->price_cents, $cur)) . ($item['on_hand'] ? ' · ' . (int) $item['on_hand'] . ' on hand' : '') . '</span>'
            . $vchips($item) . '</div>'
            . '<span style="flex:0 0 auto;font-weight:800;font-size:13px;color:' . $accent . ';white-space:nowrap">' . esc_html($label) . '</span></div>';
    };

    // Summary line.
    $vals = [];
    foreach ($rep['value'] as $cur => $cents) if ($cents > 0) $vals[] = $fmt($cents, $cur);
    $summary = number_format_i18n($rep['tracked']) . ' tracked product' . ($rep['tracked'] === 1 ? '' : 's')
        . ' · ' . number_format_i18n($rep['units']) . ' unit' . ($rep['units'] === 1 ? '' : 's') . ' on hand'
        . ($vals ? ' · value ≈ ' . implode(' + ', array_map('esc_html', $vals)) : '');

    $body = '';
    if (!$rep['out'] && !$rep['low']) {
        $body = '<div style="padding:10px 0 2px;color:#3f6212;font-weight:600;display:flex;align-items:center;gap:6px">' . lmeg_store_icon('check-circle', 14) . 'Every tracked product is above the low-stock threshold (' . (int) $rep['threshold'] . ' or fewer).</div>';
    } else {
        if ($rep['out']) {
            $body .= '<div style="margin-top:8px;font-weight:800;color:#B91C1C;font-size:13px;display:flex;align-items:center;gap:6px">' . lmeg_store_icon('ban', 14) . 'Out of stock (' . count($rep['out']) . ')</div>';
            foreach ($rep['out'] as $it) $body .= $row($it, '#B91C1C');
        }
        if ($rep['low']) {
            $body .= '<div style="margin-top:12px;font-weight:800;color:#92400E;font-size:13px;display:flex;align-items:center;gap:6px">' . lmeg_store_icon('alert', 14) . 'Running low — ' . (int) $rep['threshold'] . ' or fewer (' . count($rep['low']) . ')</div>';
            foreach ($rep['low'] as $it) $body .= $row($it, '#92400E');
        }
    }

    return '<div style="max-width:980px;margin:0 0 20px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 18px">'
        . '<div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap">'
        . '<div style="font-weight:700;color:#17141f;display:flex;align-items:center;gap:7px">' . lmeg_store_icon('box', 16) . 'Inventory</div>'
        . '<div style="color:#50575e;font-size:12px">' . $summary . '</div></div>'
        . $body . '</div>';
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
        echo '<div class="notice notice-warning" style="border-left-color:#E15FA8"><p>' . lmeg_store_icon('flask', 14, ['style' => 'margin-right:5px;vertical-align:-2px']) . '<strong>Demo checkout is ON.</strong> Orders complete <strong>without payment</strong> so you can walk the whole flow (cart → receipt → download → fan captured). These show up as normal sales, tagged <em>demo</em>. <strong>Turn it off before you go live</strong> in <a href="' . esc_url(admin_url('admin.php?page=lmeg-settings#payments')) . '">Settings → Payments</a> — while it\'s on, no money is collected.</p></div>';
    }

    /* ----- create / edit form ----- */
    if ($new || $edit) {
        wp_enqueue_media(); // WordPress media library picker for the cover image
        $p = $edit ?: (object) ['id'=>0,'title'=>'','slug'=>'','description'=>'','cover_url'=>'','gallery'=>'','preorder_at'=>null,'featured'=>0,'tags'=>'','weight_g'=>0,'price_cents'=>0,'compare_at_cents'=>0,'min_price_cents'=>0,'currency'=>'USD','type'=>'digital','processor'=>'stripe','shipping_cents'=>0,'variants'=>'','variant_stock'=>'','deliver_url'=>'','deliver_note'=>'','file_path'=>'','file_name'=>'','file_size'=>0,'stock'=>-1,'status'=>'active'];
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
                <tr><th><label>Gallery images</label></th><td>
                    <div id="lmeg-gallery-prev" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:9px"></div>
                    <input type="hidden" id="lmeg-gallery" name="gallery" value="<?php echo esc_attr(wp_json_encode(lmeg_product_gallery($p))); ?>">
                    <button type="button" class="button" id="lmeg-gallery-add">Add images</button>
                    <p class="description">Extra photos shown on the product page (front &amp; back, details, lifestyle). The cover above stays the main image. Up to 12.</p>
                    <script>
                    jQuery(function($){
                        var frame, urls = [];
                        try { urls = JSON.parse($('#lmeg-gallery').val() || '[]') || []; } catch(e) { urls = []; }
                        function sync(){ $('#lmeg-gallery').val(JSON.stringify(urls)); render(); }
                        function render(){
                            var h = '';
                            urls.forEach(function(u, i){
                                h += '<span style="position:relative;display:inline-block"><img src="'+u+'" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid #ddd">'
                                   + '<button type="button" data-i="'+i+'" class="lmeg-gal-rm" style="position:absolute;top:-7px;right:-7px;width:20px;height:20px;border-radius:50%;border:0;background:#b32d2e;color:#fff;cursor:pointer;line-height:1;font-size:12px">×</button></span>';
                            });
                            $('#lmeg-gallery-prev').html(h);
                        }
                        $('#lmeg-gallery-add').on('click', function(e){ e.preventDefault();
                            frame = wp.media({ title:'Add gallery images', button:{text:'Add to gallery'}, multiple:true, library:{type:'image'} });
                            frame.on('select', function(){
                                frame.state().get('selection').toJSON().forEach(function(a){ if(a.url && urls.indexOf(a.url)<0 && urls.length<12) urls.push(a.url); });
                                sync();
                            });
                            frame.open();
                        });
                        $('#lmeg-gallery-prev').on('click', '.lmeg-gal-rm', function(e){ e.preventDefault(); urls.splice(parseInt($(this).data('i'),10),1); sync(); });
                        render();
                    });
                    </script>
                </td></tr>
                <tr><th><label>Price</label></th><td><input type="number" name="price" step="0.01" min="0" style="width:120px" value="<?php echo esc_attr($money($p->price_cents)); ?>"> <input type="text" name="currency" style="width:64px" maxlength="3" value="<?php echo esc_attr($p->currency ?: 'USD'); ?>"></td></tr>
                <tr><th><label>Compare-at price</label></th><td><input type="number" name="compare_at" step="0.01" min="0" style="width:120px" value="<?php echo esc_attr($money($p->compare_at_cents ?? 0)); ?>"><p class="description">Optional “was” price. Set it <strong>higher than the price</strong> to put the product on sale — the card shows the original struck through, the sale price, and a <strong>−X%</strong> badge. Fans are charged the Price above. Leave 0 for no sale.</p></td></tr>
                <tr><th><label>Pay what you want</label></th><td><label><input type="checkbox" name="pwyw" value="1" <?php checked((int) $p->min_price_cents > 0); ?>> Let fans choose the price</label> &nbsp; minimum <input type="number" name="min_price" step="0.01" min="0" style="width:110px" value="<?php echo esc_attr($money($p->min_price_cents)); ?>"><p class="description">When on, the price above is the suggested amount and fans can pay the minimum or more.</p></td></tr>
                <tr><th><label>Type</label></th><td>
                    <label><input type="radio" name="type" value="digital" <?php checked($p->type ?? 'digital', 'digital'); ?>> Digital (download / unlock link)</label> &nbsp;&nbsp;
                    <label><input type="radio" name="type" value="physical" <?php checked($p->type ?? 'digital', 'physical'); ?>> Physical (ship it)</label>
                    <p class="description">Physical collects a shipping address at checkout and creates an order for you to ship. Digital delivers the unlock link below.</p></td></tr>
                <tr><th><label>Payment</label></th><td>
                    <label><input type="radio" name="processor" value="stripe" <?php checked($p->processor ?? 'stripe', 'stripe'); ?>> Stripe</label> &nbsp;&nbsp;
                    <label><input type="radio" name="processor" value="square" <?php checked($p->processor ?? 'stripe', 'square'); ?>> Square</label>
                    <p class="description">Which processor collects the payment — the money lands in that account. Set keys under Settings → Payments.</p></td></tr>
                <tr><th><label>Shipping fee <span style="color:#888;font-weight:400">(physical)</span></label></th><td><input type="number" name="shipping" step="0.01" min="0" style="width:120px" value="<?php echo esc_attr($money($p->shipping_cents ?? 0)); ?>"><p class="description">Flat shipping added at checkout for physical items. 0 = free shipping. <em>Ignored when flat rate by zone is on (Settings → Payments).</em></p></td></tr>
                <tr><th><label>Weight <span style="color:#888;font-weight:400">(physical)</span></label></th><td><input type="number" name="weight_g" min="0" step="1" style="width:120px" value="<?php echo (int) ($p->weight_g ?? 0); ?>"> grams<p class="description">Shipping weight (grams). Shows on packing slips and helps when buying labels. Optional.</p></td></tr>
                <tr><th><label>Variants / sizes</label></th><td><input type="text" name="variants" class="regular-text" value="<?php echo esc_attr(lmeg_product_variants_field($p)); ?>" placeholder="S, M, L, XL"><p class="description">Comma-separated options the buyer picks (e.g. <code>S, M, L</code>). To <strong>track stock per option</strong>, add a quantity: <code>S:10, M:5, L:20</code> — that count is the remaining stock (it counts down on each sale, and sold-out options are hidden from buyers). Mix freely; leave a number off an option for unlimited. Blank = no variants.</p></td></tr>
                <tr><th><label>Upload file <span style="color:#888;font-weight:400">(digital)</span></label></th><td>
                    <?php if (!empty($p->file_path)) : ?><p style="margin:0 0 7px">📎 <strong><?php echo esc_html($p->file_name); ?></strong> <span style="color:#888">(<?php echo esc_html(size_format((int) $p->file_size)); ?>)</span> &nbsp; <label style="color:#a00"><input type="checkbox" name="remove_file" value="1"> remove</label></p><?php endif; ?>
                    <input type="file" name="product_file">
                    <p class="description">Upload the actual file (audio, zip, pdf, video, image, epub…). It's stored privately and served only through each buyer's personal download link — never a public URL. <?php echo !empty($p->file_path) ? 'Uploading a new file replaces the current one. ' : ''; ?>A file takes priority over the link below. (Large files may need your host's upload limit raised.)</p></td></tr>
                <tr><th><label>…or unlock link <span style="color:#888;font-weight:400">(digital)</span></label></th><td><input type="url" name="deliver_url" class="regular-text" value="<?php echo esc_attr($p->deliver_url); ?>" placeholder="https://… private stream / Drive / Discord invite"><p class="description">Used only when no file is uploaded above: after paying, the fan is sent to this link through a private, per-buyer access URL.</p></td></tr>
                <tr><th><label>Limit (stock)</label></th><td><input type="number" name="stock" min="0" style="width:120px" value="<?php echo $p->stock < 0 ? '' : (int) $p->stock; ?>" placeholder="unlimited"><p class="description">Leave blank for unlimited; set a number for a limited drop.</p></td></tr>
                <tr><th><label>Pre-order until</label></th><td><input type="date" name="preorder_at" value="<?php echo esc_attr(!empty($p->preorder_at) ? date('Y-m-d', strtotime($p->preorder_at)) : ''); ?>"><p class="description">Optional. Set a future release date to sell it as a <strong>pre-order</strong> — fans buy now and it shows “Available &lt;date&gt;”. Digital pre-orders don’t send a download until you release it (upload the file, then use “Send downloads to buyers”); physical pre-orders ship when you’re ready. Leave blank for a normal product.</p></td></tr>
                <tr><th><label>Featured</label></th><td><label><input type="checkbox" name="featured" value="1" <?php checked(!empty($p->featured)); ?>> Pin to the top of your shop</label><p class="description">Featured products show first in <code>[fanloop_store]</code> and get a ⭐ Featured badge.</p></td></tr>
                <tr><th><label>Tags <span style="color:#888;font-weight:400">/ category</span></label></th><td><input type="text" name="tags" value="<?php echo esc_attr(implode(', ', lmeg_product_tags($p))); ?>" placeholder="Vinyl, Apparel, Limited" style="width:100%;max-width:420px"><p class="description">Comma-separated. Fans can filter your shop by these — filter chips appear in <code>[fanloop_store]</code> when any product has tags. e.g. <em>Vinyl, Apparel, Digital, Merch, Limited</em>. Up to 12 tags.</p></td></tr>
                <tr><th><label>Size guide <span style="color:#888;font-weight:400">/ measurements</span></label></th><td><textarea name="size_chart" class="large-text" rows="4" placeholder="Size | Chest | Length&#10;S | 18 in | 27 in&#10;M | 20 in | 28 in&#10;L | 22 in | 29 in"><?php echo esc_textarea($p->size_chart ?? ''); ?></textarea><p class="description">Optional. One row per line, columns separated by <code>|</code>. The <strong>first line is the header</strong>. Shows as a collapsible “📏 Size guide” table on the product page. Leave blank to hide. Great for apparel.</p></td></tr>
                <tr><th><label>FAQ <span style="color:#888;font-weight:400">/ questions</span></label></th><td><textarea name="faq" class="large-text" rows="4" placeholder="When will it ship? | Orders go out within 3–5 business days.&#10;What's your return policy? | Unopened items can be returned within 30 days.&#10;Is this a pre-order?"><?php echo esc_textarea($p->faq ?? ''); ?></textarea><p class="description">Optional. One question per line as <code>Question | Answer</code> (split on the first <code>|</code>). Shows as a collapsible FAQ accordion on the product page. Leave blank to hide.</p></td></tr>
                <tr><th><label>Status</label></th><td><select name="status"><option value="active" <?php selected($p->status, 'active'); ?>>Active (buyable)</option><option value="draft" <?php selected($p->status, 'draft'); ?>>Draft (hidden)</option></select></td></tr>
            </table>
            <p><button type="submit" class="button button-primary">Save product</button>
            <?php if ($p->id) : ?> &nbsp; <button type="submit" name="do" value="duplicate" class="button" onclick="return confirm('Make a draft copy of this product?');">Duplicate</button> &nbsp; <button type="submit" name="do" value="delete" class="button" onclick="return confirm('Delete this product? Past sales stay recorded.');">Delete</button><?php endif; ?></p>
            <?php if ($p->id) : ?>
            <p class="description">Its own page: <code><?php echo esc_html(lmeg_product_url($p)); ?></code> <a href="<?php echo esc_url(lmeg_product_url($p)); ?>" target="_blank" rel="noopener">open ↗</a></p>
            <p class="description">Embed on any page: <code>[fanloop_product id=<?php echo (int) $p->id; ?>]</code> &nbsp;·&nbsp; whole shop: <code>[fanloop_store]</code></p>
            <?php endif; ?>
        </form>
        <?php
        // Sold-out waitlist for this product.
        if ($p->id && function_exists('lmeg_waitlist_count')) {
            $wl = lmeg_waitlist_count($p->id);
            if (isset($_GET['notified_wl'])) echo '<div class="notice notice-success is-dismissible"><p>Notified ' . (int) $_GET['notified_wl'] . ' waiting fan' . ((int) $_GET['notified_wl'] === 1 ? '' : 's') . '.</p></div>';
            if ($wl > 0) : ?>
            <div style="max-width:720px;margin-top:18px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #E15FA8;border-radius:8px;padding:14px 18px">
                <strong style="display:inline-flex;align-items:center;gap:5px"><?php echo lmeg_store_icon('bell', 13); ?><?php echo (int) $wl; ?> <?php echo $wl === 1 ? 'fan is' : 'fans are'; ?> waiting</strong> for this to come back in stock.
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:8px" onsubmit="return confirm('Email all <?php echo (int) $wl; ?> waiting fans that this is back in stock?');">
                    <?php wp_nonce_field('lmeg_notify_waitlist', 'lmeg_waitlist_nonce'); ?>
                    <input type="hidden" name="action" value="lmeg_notify_waitlist">
                    <input type="hidden" name="product_id" value="<?php echo (int) $p->id; ?>">
                    <button type="submit" class="button button-primary">Notify them it's back</button>
                </form>
                <p class="description" style="margin:8px 0 0">Send once you've set the stock (or a size) back to available. Each fan is emailed a link to the product and won't be notified again.</p>
            </div>
            <?php endif;
        }
        // Release day: email all buyers their download (for a digital pre-order).
        if ($p->id && ($p->type ?? 'digital') !== 'physical') {
            $bn = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT email) FROM $ptbl WHERE product_id = %d AND status='paid' AND email IS NOT NULL AND email <> ''", $p->id));
            if (isset($_GET['released'])) echo '<div class="notice notice-success is-dismissible"><p>Sent the download to ' . (int) $_GET['released'] . ' buyer' . ((int) $_GET['released'] === 1 ? '' : 's') . '.</p></div>';
            if ($bn > 0) : ?>
            <div style="max-width:720px;margin-top:14px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #8A6CF6;border-radius:8px;padding:14px 18px">
                <strong>🎟️ <?php echo (int) $bn; ?> buyer<?php echo $bn === 1 ? '' : 's'; ?></strong> — email everyone their download link (use on a pre-order's release day, after you've uploaded the file).
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:8px" onsubmit="return confirm('Email all <?php echo (int) $bn; ?> buyers their download link now?');">
                    <?php wp_nonce_field('lmeg_release_downloads', 'lmeg_release_nonce'); ?>
                    <input type="hidden" name="action" value="lmeg_release_downloads">
                    <input type="hidden" name="product_id" value="<?php echo (int) $p->id; ?>">
                    <button type="submit" class="button">Send downloads to buyers</button>
                </form>
            </div>
            <?php endif;
        }
        ?>
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

    // KPI summary: orders (distinct checkouts), average order value, unique
    // buyers, top seller, and a 30-day revenue sparkline.
    $orders = (int) $wpdb->get_var("SELECT COUNT(DISTINCT COALESCE(NULLIF(SUBSTRING_INDEX(provider_ref,'#',1),''), stripe_session_id, CAST(id AS CHAR))) FROM $ptbl WHERE status = 'paid'");
    $aov    = $orders ? (int) round($rev / $orders) : 0;
    $buyers = (int) $wpdb->get_var("SELECT COUNT(DISTINCT email) FROM $ptbl WHERE status = 'paid' AND email IS NOT NULL AND email <> ''");
    $topn   = $wpdb->get_results("SELECT pp.product_id pid, SUM(pp.amount_cents) rev, COUNT(*) n, pr.title FROM $ptbl pp LEFT JOIN $tbl pr ON pr.id = pp.product_id WHERE pp.status = 'paid' GROUP BY pp.product_id, pr.title ORDER BY rev DESC LIMIT 3");
    // Revenue this 30 days vs the previous 30 days (for a trend chip).
    $prev_start = date('Y-m-d H:i:s', $today_ts - 60 * DAY_IN_SECONDS);
    $rev30      = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount_cents),0) FROM $ptbl WHERE status = 'paid' AND paid_at >= %s", $since));
    $revprev30  = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount_cents),0) FROM $ptbl WHERE status = 'paid' AND paid_at >= %s AND paid_at < %s", $prev_start, $since));
    // Orders this 7 days vs the previous 7 days (week-over-week).
    $w1  = date('Y-m-d H:i:s', $today_ts - 7 * DAY_IN_SECONDS);
    $w2  = date('Y-m-d H:i:s', $today_ts - 14 * DAY_IN_SECONDS);
    $okd = "COUNT(DISTINCT COALESCE(NULLIF(SUBSTRING_INDEX(provider_ref,'#',1),''), stripe_session_id, CAST(id AS CHAR)))";
    $orders7     = (int) $wpdb->get_var($wpdb->prepare("SELECT $okd FROM $ptbl WHERE status = 'paid' AND paid_at >= %s", $w1));
    $ordersprev7 = (int) $wpdb->get_var($wpdb->prepare("SELECT $okd FROM $ptbl WHERE status = 'paid' AND paid_at >= %s AND paid_at < %s", $w2, $w1));
    $revdaily = array_fill(0, 30, 0);
    foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT DATE(paid_at) d, COALESCE(SUM(amount_cents),0) c FROM $ptbl WHERE status = 'paid' AND paid_at >= %s GROUP BY DATE(paid_at)", $since)) as $dr) {
        $idx = 29 - (int) floor(($today_ts - strtotime($dr->d . ' 00:00:00')) / DAY_IN_SECONDS);
        if ($idx >= 0 && $idx < 30) $revdaily[$idx] = round(((int) $dr->c) / 100, 2);
    }
    $fmtc = function ($c) { return function_exists('lmeg_format_price') ? lmeg_format_price((int) $c, 'USD') : ('$' . number_format($c / 100, 2)); };
    ?>
    <p style="margin:10px 0 4px"><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-products&new=1')); ?>" class="button button-primary">+ New product</a>
    <?php if ($demo_n > 0) : ?>
        &nbsp; <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" onsubmit="return confirm('Clear <?php echo (int) $demo_n; ?> demo/test order<?php echo $demo_n === 1 ? '' : 's'; ?>? Real sales are untouched. Sold counts and variant stock consumed by the tests are restored.');">
            <?php wp_nonce_field('lmeg_clear_demo', 'lmeg_demo_nonce'); ?>
            <input type="hidden" name="action" value="lmeg_clear_demo">
            <button type="submit" class="button" style="display:inline-flex;align-items:center;gap:6px"><?php echo lmeg_store_icon('flask', 14); ?>Clear test orders (<?php echo (int) $demo_n; ?>)</button>
        </form>
    <?php endif; ?>
    <?php if ($units > 0) : ?>
        &nbsp; <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lmeg_export_orders'), 'lmeg_export_orders')); ?>" class="button" style="display:inline-flex;align-items:center;gap:6px"><?php echo lmeg_store_icon('download', 14); ?>Export orders (CSV)</a>
    <?php endif; ?></p>
    <details style="max-width:840px;margin:0 0 14px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px 16px">
        <summary style="cursor:pointer;font-weight:600">Shortcodes &amp; links — how to show your shop</summary>
        <table class="widefat" style="margin:10px 0 4px"><tbody>
            <tr><td style="width:230px"><code>[fanloop_store]</code></td><td>Your whole shop as a grid.</td></tr>
            <tr><td><code>[fanloop_store controls="on"]</code></td><td>Force the <strong>search + sort</strong> bar (auto-shows at 5+ products).</td></tr>
            <tr><td><code>[fanloop_store type="digital"]</code></td><td>Only digital (or <code>type="physical"</code>).</td></tr>
            <tr><td><code>[fanloop_store min="200"]</code></td><td>Smaller cards / more per row (grid min px).</td></tr>
            <tr><td><code>[fanloop_store ids="12,7,3"]</code></td><td>A <strong>curated collection</strong> — just those products, in that order (great for a landing or feature section).</td></tr>
            <tr><td><code>[fanloop_store tag="vinyl"]</code></td><td>Only products with that <strong>tag</strong> — a ready-made section page.</td></tr>
            <tr><td><code>[fanloop_store per="12"]</code></td><td>Show 12 at a time with a <strong>Load more</strong> button (great for big catalogues).</td></tr>
            <tr><td><code>[fanloop_product id="<?php echo (int) ($rows[0]->id ?? 1); ?>"]</code></td><td>One product (or <code>slug="…"</code>).</td></tr>
            <tr><td><code>[fanloop_purchases]</code></td><td>A "find my downloads" box for fans.</td></tr>
            <tr><td><code><?php echo esc_html(home_url('/?lmeg_product=your-slug')); ?></code></td><td>A product's own shareable page (URL shown on each product's edit screen).</td></tr>
            <tr><td><code><?php echo esc_html(add_query_arg(['lmeg_purchases' => 'find'], home_url('/'))); ?></code></td><td>Direct find-my-purchases link.</td></tr>
        </tbody></table>
        <p class="description" style="margin:6px 0 0">Sort options in the bar: Featured · Newest · Price · Best selling · Name. Pin a product to the top with the ⭐ <strong>Featured</strong> checkbox on its edit screen. Every <code>fanloop_</code> code also works as <code>loony_</code>.</p>
    </details>
    <details style="max-width:840px;margin:0 0 14px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px 16px">
        <summary style="cursor:pointer;font-weight:600">Import / export products (CSV)</summary>
        <p class="description" style="margin:10px 0">Bulk-manage your catalogue in a spreadsheet. <strong>Export</strong> to download every product as a CSV; edit it (or build one from scratch) and <strong>import</strong> to create and update products in one go. Rows are matched by <strong>Slug</strong> — an existing slug updates that product, a new or blank slug creates one. Imported products come in as <strong>Drafts</strong> unless the Status column says <code>active</code>. Columns: <?php echo esc_html(implode(', ', lmeg_products_csv_headers())); ?>. Price/Min price/Shipping in dollars; Stock blank = unlimited; Variants like <code>S:10, M:5, L</code>; Featured <code>1</code>/<code>0</code>.</p>
        <p style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0">
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lmeg_export_products'), 'lmeg_export_products')); ?>" class="button" style="display:inline-flex;align-items:center;gap:6px"><?php echo lmeg_store_icon('download', 14); ?>Export products (CSV)</a>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="display:inline-flex;gap:8px;align-items:center;margin:0">
                <?php wp_nonce_field('lmeg_import_products', 'lmeg_import_nonce'); ?>
                <input type="hidden" name="action" value="lmeg_import_products">
                <input type="file" name="csv" accept=".csv,text/csv" required>
                <button type="submit" class="button button-primary" onclick="return confirm('Import products from this CSV? Existing products with a matching slug will be updated.');">⬆ Import</button>
            </form>
        </p>
    </details>
    <?php
    // Import result notice.
    if (isset($_GET['imported']) && ($ir = get_transient('lmeg_import_result'))) {
        delete_transient('lmeg_import_result');
        $bits = [];
        if ($ir['created']) $bits[] = '<strong>' . (int) $ir['created'] . '</strong> created';
        if ($ir['updated']) $bits[] = '<strong>' . (int) $ir['updated'] . '</strong> updated';
        if ($ir['skipped']) $bits[] = '<strong>' . (int) $ir['skipped'] . '</strong> skipped';
        $cls = !empty($ir['errors']) ? 'notice-warning' : 'notice-success';
        echo '<div class="notice ' . $cls . ' is-dismissible" style="max-width:840px"><p>CSV import: ' . ($bits ? implode(' · ', $bits) : 'nothing to import') . '.';
        if (!empty($ir['errors'])) echo '<br><span style="color:#8a6d00">' . esc_html(implode(' · ', array_slice($ir['errors'], 0, 25))) . '</span>';
        echo '</p></div>';
    }
    $has_samples = false;
    foreach ($rows as $rp) { if (strpos($rp->slug, 'sample-') === 0 && $rp->status === 'draft') { $has_samples = true; break; } }
    if ($has_samples) echo '<div class="notice notice-info inline" style="margin:0 0 18px;max-width:840px"><p>👋 We added a few <strong>sample products</strong> to get you started — they are <strong>Drafts</strong>, so fans can\'t see them yet. Edit one to make it yours (and set it <em>Active</em> to sell it), or delete them.</p></div>';
    echo lmeg_store_checklist_html($units);
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;max-width:980px;margin-bottom:14px">
        <div class="lmeg-stat"><div class="lmeg-stat__label">Revenue · 30d trend</div><div class="lmeg-stat__value"><?php echo esc_html($fmtc($rev)); ?></div><?php echo function_exists('lmeg_chart_line') ? lmeg_chart_line($revdaily, ['color' => '#E15FA8', 'uid' => 'store-rev', 'h' => 44, 'suffix' => ' USD']) : ''; ?><div><?php echo lmeg_admin_pct_chip($rev30, $revprev30, 'vs prev 30d'); ?></div></div>
        <div class="lmeg-stat"><div class="lmeg-stat__label">Orders</div><div class="lmeg-stat__value"><?php echo number_format_i18n($orders); ?></div><div class="lmeg-stat__hint"><?php echo number_format_i18n($units); ?> item<?php echo $units === 1 ? '' : 's'; ?> total</div></div>
        <div class="lmeg-stat"><div class="lmeg-stat__label">Avg order</div><div class="lmeg-stat__value"><?php echo esc_html($fmtc($aov)); ?></div><div class="lmeg-stat__hint">revenue ÷ orders</div></div>
        <div class="lmeg-stat"><div class="lmeg-stat__label">Buyers</div><div class="lmeg-stat__value"><?php echo number_format_i18n($buyers); ?></div><div class="lmeg-stat__hint">unique fans who bought</div></div>
        <div class="lmeg-stat"><div class="lmeg-stat__label">Downloads</div><div class="lmeg-stat__value"><?php echo number_format_i18n($dls); ?></div><div class="lmeg-stat__hint">file/link accesses</div></div>
        <div class="lmeg-stat"><div class="lmeg-stat__label">Orders · 7d</div><div class="lmeg-stat__value"><?php echo number_format_i18n($orders7); ?></div><div><?php echo lmeg_admin_pct_chip($orders7, $ordersprev7, 'vs prev 7d'); ?></div><?php if (!$orders7) : ?><div class="lmeg-stat__hint">this week</div><?php endif; ?></div>
    </div>
    <?php echo lmeg_admin_top_products_html($topn, $fmtc); ?>
    <?php echo lmeg_admin_lowstock_html($rows); ?>
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
                <td><?php echo !empty($p->featured) ? lmeg_store_icon('star', 13, ['fill' => true, 'style' => 'color:#E0A800;margin-right:5px;vertical-align:-2px']) : ''; ?><strong><?php echo esc_html($p->title); ?></strong></td>
                <td><?php echo ($p->type === 'physical') ? '📦 Physical' : '⬇ Digital'; ?></td>
                <td><?php echo esc_html($price); ?><?php echo ($p->type === 'physical' && (int)$p->shipping_cents > 0) ? ' <span style="color:#888">+ ship</span>' : ''; ?></td>
                <td><?php echo ($p->processor === 'square') ? 'Square' : 'Stripe'; ?></td>
                <td><?php echo (int) $p->sold; ?><?php echo $p->stock >= 0 ? ' / ' . (int) $p->stock : ''; ?><?php if (function_exists('lmeg_waitlist_count')) { $wl = lmeg_waitlist_count($p->id); if ($wl > 0) echo ' <span title="waiting for restock" style="color:#b03083;font-size:12px">· 🔔 ' . (int) $wl . '</span>'; } ?></td>
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
                    <td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center"><?php wp_nonce_field('lmeg_ship_order', 'lmeg_ship_nonce'); ?><input type="hidden" name="action" value="lmeg_ship_order"><input type="hidden" name="purchase_id" value="<?php echo (int) $o->id; ?>">
                        <select name="carrier" style="max-width:120px"><option value="">Carrier…</option><?php foreach (['USPS','UPS','FedEx','Canada Post','DHL','Other'] as $cc) : ?><option value="<?php echo esc_attr($cc); ?>"><?php echo esc_html($cc); ?></option><?php endforeach; ?></select>
                        <input type="text" name="tracking" placeholder="Tracking # or link" style="width:150px">
                        <button class="button button-small button-primary" type="submit">Mark shipped</button>
                        <span class="description" style="flex-basis:100%;margin:0">Adds a "your order shipped" email with a tracking link (optional).</span>
                    </form></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table>
    <?php endif;

    $recent = $wpdb->get_results("SELECT pp.*, pr.title FROM $ptbl pp LEFT JOIN $tbl pr ON pr.id = pp.product_id WHERE pp.status='paid' ORDER BY pp.id DESC LIMIT 15");
    if ($recent) : ?>
        <h2 style="margin-top:26px">Recent sales <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-orders')); ?>" style="font-size:13px;font-weight:400;text-decoration:none">· all orders →</a></h2>
        <table class="widefat striped" style="max-width:820px">
            <thead><tr><th>When</th><th>Product</th><th>Buyer</th><th>Amount</th></tr></thead>
            <tbody><?php foreach ($recent as $r) : ?>
                <tr><td><?php echo esc_html($r->paid_at); ?></td><td><?php echo esc_html($r->title); ?><?php echo ($r->processor === 'demo') ? ' <span style="font-size:11px;background:rgba(225,95,168,.14);color:#b03083;padding:1px 7px;border-radius:999px;vertical-align:middle">demo</span>' : ''; ?></td><td><?php echo esc_html($r->email ?: '—'); ?></td><td><?php echo esc_html(function_exists('lmeg_format_price') ? lmeg_format_price((int)$r->amount_cents, $r->currency) : '$'.number_format($r->amount_cents/100,2)); ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>
    <?php endif;

    if (function_exists('lmeg_discounts_admin_section')) lmeg_discounts_admin_section();
    if (function_exists('lmeg_bundles_admin_section'))   lmeg_bundles_admin_section();
    if (function_exists('lmeg_waitlist_admin_section'))  lmeg_waitlist_admin_section();
    if (function_exists('lmeg_abandoned_admin_section')) lmeg_abandoned_admin_section();
    echo '</div>';
}
