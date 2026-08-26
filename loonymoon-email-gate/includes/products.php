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

/* ---------------------------------------------------------------------------
 * Front-end router: start checkout / return from Stripe / serve access link.
 * ------------------------------------------------------------------------- */
add_action('init', 'lmeg_products_router');
function lmeg_products_router() {
    if (isset($_GET['lmeg_buy']))         { lmeg_product_start_checkout(); }
    elseif (isset($_GET['lmeg_buy_done'])){ lmeg_product_checkout_return(); }
    elseif (isset($_GET['lmeg_access']))  { lmeg_product_serve_access(); }
}

function lmeg_product_start_checkout() {
    $p = lmeg_product_get((int) $_GET['lmeg_buy']);
    if (!$p || $p->status !== 'active')          { wp_die('This item is not available.'); }
    if ($p->stock >= 0 && $p->sold >= $p->stock) { wp_die('Sorry — this drop has sold out.'); }
    $keys = lmeg_stripe_keys();
    if (empty($keys['sk']))                      { wp_die('Payments are not set up yet.'); }

    // Fixed price, or pay-what-you-want clamped to the minimum.
    $amount = (int) $p->price_cents;
    if (lmeg_product_is_pwyw($p)) {
        $chosen = isset($_GET['amount']) ? (int) round(((float) $_GET['amount']) * 100) : (int) $p->price_cents;
        $amount = max((int) $p->min_price_cents, $chosen);
    }
    if ($amount < 50) { wp_die('That amount is too low for card payment.'); }

    $cur     = strtolower($p->currency ?: 'usd');
    $success = add_query_arg(['lmeg_buy_done' => $p->id, 'session_id' => '{CHECKOUT_SESSION_ID}'], home_url('/'));
    $cancel  = add_query_arg(['lmeg_product' => $p->slug], home_url('/'));

    $params = [
        'mode'        => 'payment',
        'success_url' => $success,
        'cancel_url'  => $cancel,
        'line_items[0][price_data][currency]'           => $cur,
        'line_items[0][price_data][unit_amount]'        => $amount,
        'line_items[0][price_data][product_data][name]' => $p->title,
        'line_items[0][quantity]'                       => 1,
        'metadata[product_id]'                          => $p->id,
        'allow_promotion_codes'                         => 'true',
    ];
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

    $email  = sanitize_email($session['customer_details']['email'] ?? ($session['customer_email'] ?? ''));
    $amount = (int) ($session['amount_total'] ?? $p->price_cents);
    $cur    = strtoupper($session['currency'] ?? ($p->currency ?: 'USD'));
    $token  = wp_generate_password(40, false, false);

    // Capture the buyer as a fan — SILENTLY (they get a purchase receipt, not
    // the generic signup welcome).
    $sub_id = 0;
    if ($email) {
        remove_action('lmeg_subscriber_created', 'lmeg_maybe_send_welcome', 10);
        $sub_id = (int) lmeg_store_subscriber([
            'contact_type' => 'email', 'email' => $email, 'phone' => null,
            'country' => null, 'street' => null, 'city' => null, 'region' => null,
            'postal_code' => null, 'post_id' => null,
        ]);
    }
    if ($sub_id && function_exists('lmeg_get_or_create_tag')) {
        $t = lmeg_get_or_create_tag('product:' . $p->slug, 'Bought: ' . $p->title, false, '#E15FA8');
        if ($t) lmeg_attach_tag($sub_id, $t->id);
    }

    // Upsert the purchase row (drives delivery + the sales list).
    if ($existing) {
        $wpdb->update($ptbl, [
            'subscriber_id' => $sub_id ?: null, 'email' => $email ?: null,
            'amount_cents' => $amount, 'currency' => $cur, 'status' => 'paid',
            'access_token' => $token, 'paid_at' => current_time('mysql'),
        ], ['id' => (int) $existing->id]);
        $pur_id = (int) $existing->id;
    } else {
        $wpdb->insert($ptbl, [
            'product_id' => $prod_id, 'subscriber_id' => $sub_id ?: null, 'email' => $email ?: null,
            'amount_cents' => $amount, 'currency' => $cur, 'stripe_session_id' => $sess_id,
            'status' => 'paid', 'access_token' => $token, 'access_count' => 0, 'access_limit' => 15,
            'created_at' => current_time('mysql'), 'paid_at' => current_time('mysql'),
        ]);
        $pur_id = (int) $wpdb->insert_id;
    }
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}lmeg_products SET sold = sold + 1 WHERE id = %d", $prod_id));

    // Record revenue into lmeg_shop_orders so it appears everywhere Shopify
    // revenue does (fan timeline, totals, broadcast attribution, LTV). The
    // synthetic id sits far below real Shopify order ids so it can't collide.
    if (function_exists('lmeg_shop_record_order') && $email) {
        lmeg_shop_record_order([
            'id'           => 800000000000 + $pur_id,
            'email'        => $email,
            'total_price'  => $amount / 100,
            'currency'     => $cur,
            'created_at'   => gmdate('c'),
            'order_number' => 'DROP-' . $p->id . '-' . $pur_id,
            'name'         => 'DROP-' . $p->id,
        ]);
    }

    if ($email) lmeg_product_send_receipt($p, $email, $token, $amount, $cur);
    return $sub_id;
}

function lmeg_product_access_url($token) {
    return add_query_arg(['lmeg_access' => $token], home_url('/'));
}

function lmeg_product_send_receipt($p, $email, $token, $amount, $cur) {
    $s        = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $artist   = $s['community_name'] ?? ($s['artist_name'] ?? get_bloginfo('name'));
    $access   = lmeg_product_access_url($token);
    $price    = function_exists('lmeg_format_price') ? lmeg_format_price($amount, $cur) : ('$' . number_format($amount / 100, 2));
    $subject  = 'Your download: ' . $p->title;
    $body     = '<div style="font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px;color:#111">'
        . '<h2 style="margin:0 0 6px">Thanks for supporting ' . esc_html($artist) . ' 💜</h2>'
        . '<p style="color:#444;margin:0 0 18px">You bought <strong>' . esc_html($p->title) . '</strong> for ' . esc_html($price) . '.</p>'
        . '<p style="margin:0 0 22px"><a href="' . esc_url($access) . '" style="display:inline-block;background:#E15FA8;color:#fff;text-decoration:none;font-weight:700;padding:13px 26px;border-radius:10px">Get your download →</a></p>'
        . '<p style="color:#888;font-size:13px">Or paste this link into your browser:<br>' . esc_html($access) . '</p>'
        . '</div>';
    add_filter('wp_mail_content_type', function () { return 'text/html'; });
    wp_mail($email, $subject, $body);
    remove_all_filters('wp_mail_content_type');
}

function lmeg_product_serve_access() {
    global $wpdb;
    $token = sanitize_text_field($_GET['lmeg_access'] ?? '');
    $ptbl  = $wpdb->prefix . 'lmeg_product_purchases';
    $pur   = $token ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $ptbl WHERE access_token = %s", $token)) : null;
    if (!$pur || $pur->status !== 'paid')              { wp_die('This link is not valid.'); }
    if ((int) $pur->access_count >= (int) $pur->access_limit) { wp_die('This download link has reached its access limit. Reply to your receipt email and the artist can help.'); }
    $p = lmeg_product_get($pur->product_id);
    if (!$p || empty($p->deliver_url))                 { wp_die('This item has no download set yet. Please contact the artist.'); }
    $wpdb->query($wpdb->prepare("UPDATE $ptbl SET access_count = access_count + 1 WHERE id = %d", (int) $pur->id));
    wp_redirect(esc_url_raw($p->deliver_url));
    exit;
}

/**
 * Return page after Stripe. Fulfils inline if the webhook hasn't landed yet,
 * then shows a clean, self-contained confirmation with the download button.
 */
function lmeg_product_checkout_return() {
    global $wpdb;
    $p       = lmeg_product_get((int) $_GET['lmeg_buy_done']);
    $sess_id = sanitize_text_field($_GET['session_id'] ?? '');
    $ptbl    = $wpdb->prefix . 'lmeg_product_purchases';
    $pur     = $sess_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $ptbl WHERE stripe_session_id = %s", $sess_id)) : null;

    if ((!$pur || $pur->status !== 'paid') && $sess_id) {
        $s = lmeg_stripe_request('GET', '/checkout/sessions/' . rawurlencode($sess_id));
        if (!is_wp_error($s) && ($s['payment_status'] ?? '') === 'paid') {
            lmeg_product_fulfill_checkout($s);
            $pur = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ptbl WHERE stripe_session_id = %s", $sess_id));
        }
    }
    $paid   = ($pur && $pur->status === 'paid');
    $access = $paid ? lmeg_product_access_url($pur->access_token) : '';
    $title  = $p ? $p->title : 'your order';

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
      <?php if ($paid) : ?>
        <div class="dot">✓</div>
        <h1>You're in. Thank you!</h1>
        <p>Your purchase of <strong><?php echo esc_html($title); ?></strong> is complete, and it's on its way to your inbox too.</p>
        <?php if ($access) : ?><a class="btn" href="<?php echo esc_url($access); ?>">Get your download →</a><div class="lnk"><?php echo esc_html($access); ?></div><?php endif; ?>
      <?php else : ?>
        <div class="dot">…</div>
        <h1>Payment processing</h1>
        <p>Hang tight — your payment is being confirmed. Check your email in a moment for your download link. You can safely close this page.</p>
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
    $sold_out = ($p->status !== 'active') || ($p->stock >= 0 && $p->sold >= $p->stock);
    $cur      = $p->currency ?: 'USD';
    $price    = function_exists('lmeg_format_price') ? lmeg_format_price((int) $p->price_cents, $cur) : ('$' . number_format($p->price_cents / 100, 2));
    $pwyw     = lmeg_product_is_pwyw($p);
    $buy_base = esc_url(add_query_arg(['lmeg_buy' => $p->id], home_url('/')));
    ob_start(); ?>
    <div class="flp-prod" style="max-width:420px;border:1px solid rgba(0,0,0,.12);border-radius:16px;overflow:hidden;font-family:inherit;background:#fff;box-shadow:0 12px 40px rgba(0,0,0,.08)">
      <?php if (!empty($p->cover_url)) : ?><img src="<?php echo esc_url($p->cover_url); ?>" alt="<?php echo esc_attr($p->title); ?>" style="width:100%;display:block;aspect-ratio:1/1;object-fit:cover"><?php endif; ?>
      <div style="padding:18px 20px">
        <div style="font-weight:750;font-size:19px;margin-bottom:4px"><?php echo esc_html($p->title); ?></div>
        <?php if (!empty($p->description)) : ?><div style="font-size:14px;color:#555;line-height:1.5;margin-bottom:14px"><?php echo esc_html($p->description); ?></div><?php endif; ?>
        <?php if ($sold_out) : ?>
          <div style="font-weight:700;color:#999">Sold out</div>
        <?php elseif ($pwyw) : ?>
          <form method="get" action="<?php echo esc_url(home_url('/')); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="lmeg_buy" value="<?php echo (int) $p->id; ?>">
            <span style="color:#555">Name your price:</span>
            <input type="number" name="amount" min="<?php echo esc_attr(number_format($p->min_price_cents / 100, 2, '.', '')); ?>" step="0.01" value="<?php echo esc_attr(number_format(max($p->price_cents, $p->min_price_cents) / 100, 2, '.', '')); ?>" style="width:90px;padding:9px;border:1px solid #ccc;border-radius:8px">
            <button type="submit" style="background:#E15FA8;color:#fff;border:0;font-weight:700;padding:11px 20px;border-radius:10px;cursor:pointer">Buy</button>
          </form>
          <div style="font-size:12px;color:#999;margin-top:6px">Minimum <?php echo esc_html(function_exists('lmeg_format_price') ? lmeg_format_price((int) $p->min_price_cents, $cur) : ''); ?></div>
        <?php else : ?>
          <a href="<?php echo $buy_base; ?>" style="display:inline-block;background:#E15FA8;color:#fff;text-decoration:none;font-weight:700;padding:12px 24px;border-radius:10px"><?php echo esc_html($price); ?> · Buy now</a>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
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
        $wpdb->delete($tbl, ['id' => $id]);
        wp_safe_redirect(admin_url('admin.php?page=lmeg-products&deleted=1')); exit;
    }

    $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    if ($title === '') { wp_safe_redirect(admin_url('admin.php?page=lmeg-products&err=title')); exit; }
    $slug  = sanitize_title($_POST['slug'] ?? $title) ?: sanitize_title($title);
    // keep slug unique
    $clash = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $tbl WHERE slug = %s AND id <> %d", $slug, $id));
    if ($clash) $slug .= '-' . wp_generate_password(4, false, false);

    $to_cents = function ($v) { return (int) round(((float) preg_replace('/[^0-9.]/', '', (string) $v)) * 100); };
    $data = [
        'title'           => $title,
        'slug'            => $slug,
        'description'     => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
        'cover_url'       => esc_url_raw($_POST['cover_url'] ?? ''),
        'price_cents'     => max(0, $to_cents($_POST['price'] ?? 0)),
        'min_price_cents' => !empty($_POST['pwyw']) ? max(0, $to_cents($_POST['min_price'] ?? 0)) : 0,
        'currency'        => strtoupper(substr(sanitize_text_field($_POST['currency'] ?? 'USD'), 0, 3)) ?: 'USD',
        'deliver_url'     => esc_url_raw($_POST['deliver_url'] ?? ''),
        'deliver_note'    => sanitize_textarea_field(wp_unslash($_POST['deliver_note'] ?? '')),
        'stock'           => ($_POST['stock'] ?? '') === '' ? -1 : max(0, (int) $_POST['stock']),
        'status'          => in_array($_POST['status'] ?? 'active', ['active', 'draft'], true) ? $_POST['status'] : 'active',
    ];
    if ($id) { $wpdb->update($tbl, $data, ['id' => $id]); }
    else     { $data['sold'] = 0; $data['created_at'] = current_time('mysql'); $wpdb->insert($tbl, $data); $id = (int) $wpdb->insert_id; }
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products&saved=' . $id)); exit;
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

    echo '<div class="wrap"><h1>Fanloop — Store <span style="font-size:12px;vertical-align:middle;background:rgba(225,95,168,.16);color:#E15FA8;padding:3px 10px;border-radius:999px;">BETA · digital drops</span></h1>';
    if (empty($keys['sk'])) {
        echo '<div class="notice notice-warning"><p>Connect Stripe first (Settings → Payments) — that\'s where the money lands. You can create products now, but buyers can\'t check out until Stripe keys are saved.</p></div>';
    }
    if (isset($_GET['saved']))   echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
    if (isset($_GET['deleted'])) echo '<div class="notice notice-success is-dismissible"><p>Deleted.</p></div>';

    /* ----- create / edit form ----- */
    if ($new || $edit) {
        $p = $edit ?: (object) ['id'=>0,'title'=>'','slug'=>'','description'=>'','cover_url'=>'','price_cents'=>0,'min_price_cents'=>0,'currency'=>'USD','deliver_url'=>'','deliver_note'=>'','stock'=>-1,'status'=>'active'];
        $money = function ($c) { return number_format(((int) $c) / 100, 2, '.', ''); };
        ?>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-products')); ?>">← All products</a></p>
        <form method="post" action="<?php echo esc_url($save); ?>" style="max-width:720px;">
            <?php wp_nonce_field('lmeg_save_product', 'lmeg_product_nonce'); ?>
            <input type="hidden" name="action" value="lmeg_save_product">
            <input type="hidden" name="product_id" value="<?php echo (int) $p->id; ?>">
            <table class="form-table">
                <tr><th><label>Title</label></th><td><input type="text" name="title" class="regular-text" required value="<?php echo esc_attr($p->title); ?>" placeholder="e.g. Neon Hours (single)"></td></tr>
                <tr><th><label>Description</label></th><td><textarea name="description" class="large-text" rows="3"><?php echo esc_textarea($p->description); ?></textarea></td></tr>
                <tr><th><label>Cover image URL</label></th><td><input type="url" name="cover_url" class="regular-text" value="<?php echo esc_attr($p->cover_url); ?>" placeholder="https://…"><p class="description">Paste a Media Library image URL (optional).</p></td></tr>
                <tr><th><label>Price</label></th><td><input type="number" name="price" step="0.01" min="0" style="width:120px" value="<?php echo esc_attr($money($p->price_cents)); ?>"> <input type="text" name="currency" style="width:64px" maxlength="3" value="<?php echo esc_attr($p->currency ?: 'USD'); ?>"></td></tr>
                <tr><th><label>Pay what you want</label></th><td><label><input type="checkbox" name="pwyw" value="1" <?php checked((int) $p->min_price_cents > 0); ?>> Let fans choose the price</label> &nbsp; minimum <input type="number" name="min_price" step="0.01" min="0" style="width:110px" value="<?php echo esc_attr($money($p->min_price_cents)); ?>"><p class="description">When on, the price above is the suggested amount and fans can pay the minimum or more.</p></td></tr>
                <tr><th><label>Deliver (unlock link)</label></th><td><input type="url" name="deliver_url" class="regular-text" value="<?php echo esc_attr($p->deliver_url); ?>" placeholder="https://… private download / stream / Drive / Discord invite"><p class="description">After paying, the fan is sent to this link through a private, per-buyer access URL. <em>(Direct file upload &amp; hosting is coming in the next beta update — for now use an unlisted link.)</em></p></td></tr>
                <tr><th><label>Limit (stock)</label></th><td><input type="number" name="stock" min="0" style="width:120px" value="<?php echo $p->stock < 0 ? '' : (int) $p->stock; ?>" placeholder="unlimited"><p class="description">Leave blank for unlimited; set a number for a limited drop.</p></td></tr>
                <tr><th><label>Status</label></th><td><select name="status"><option value="active" <?php selected($p->status, 'active'); ?>>Active (buyable)</option><option value="draft" <?php selected($p->status, 'draft'); ?>>Draft (hidden)</option></select></td></tr>
            </table>
            <p><button type="submit" class="button button-primary">Save product</button>
            <?php if ($p->id) : ?> &nbsp; <button type="submit" name="do" value="delete" class="button" onclick="return confirm('Delete this product? Past sales stay recorded.');">Delete</button><?php endif; ?></p>
            <?php if ($p->id) : ?><p class="description">Embed on your site: <code>[fanloop_product id=<?php echo (int) $p->id; ?>]</code> &nbsp;·&nbsp; direct buy link: <code><?php echo esc_html(add_query_arg(['lmeg_buy' => $p->id], home_url('/'))); ?></code></p><?php endif; ?>
        </form>
        </div><?php
        return;
    }

    /* ----- list + sales ----- */
    $rows  = $wpdb->get_results("SELECT * FROM $tbl ORDER BY id DESC");
    $units = (int) $wpdb->get_var("SELECT COUNT(*) FROM $ptbl WHERE status = 'paid'");
    $rev   = (int) $wpdb->get_var("SELECT COALESCE(SUM(amount_cents),0) FROM $ptbl WHERE status = 'paid'");
    ?>
    <p style="margin:10px 0 18px"><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-products&new=1')); ?>" class="button button-primary">+ New product</a></p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;max-width:620px;margin-bottom:20px">
        <div class="lmeg-stat"><div class="lmeg-stat__label">Units sold</div><div class="lmeg-stat__value"><?php echo number_format_i18n($units); ?></div></div>
        <div class="lmeg-stat"><div class="lmeg-stat__label">Revenue</div><div class="lmeg-stat__value"><?php echo esc_html(function_exists('lmeg_format_price') ? lmeg_format_price($rev, 'USD') : '$' . number_format($rev/100,2)); ?></div><div class="lmeg-stat__hint">before Stripe fees · lands in your Stripe</div></div>
    </div>
    <table class="widefat striped">
        <thead><tr><th>Product</th><th>Price</th><th>Sold</th><th>Status</th><th>Embed</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows) : ?><tr><td colspan="6">No products yet. Create your first digital drop.</td></tr>
        <?php else : foreach ($rows as $p) :
            $cur = $p->currency ?: 'USD';
            $price = lmeg_product_is_pwyw($p)
                ? 'PWYW · min ' . (function_exists('lmeg_format_price') ? lmeg_format_price((int)$p->min_price_cents,$cur) : '')
                : (function_exists('lmeg_format_price') ? lmeg_format_price((int)$p->price_cents,$cur) : '$'.number_format($p->price_cents/100,2));
        ?>
            <tr>
                <td><strong><?php echo esc_html($p->title); ?></strong></td>
                <td><?php echo esc_html($price); ?></td>
                <td><?php echo (int) $p->sold; ?><?php echo $p->stock >= 0 ? ' / ' . (int) $p->stock : ''; ?></td>
                <td><?php echo $p->status === 'active' ? '<span style="color:#34D399">● Active</span>' : '<span style="color:#9A9DB0">Draft</span>'; ?></td>
                <td><code>[fanloop_product id=<?php echo (int) $p->id; ?>]</code></td>
                <td><a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=lmeg-products&edit=' . $p->id)); ?>">Edit</a></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php
    $recent = $wpdb->get_results("SELECT pp.*, pr.title FROM $ptbl pp LEFT JOIN $tbl pr ON pr.id = pp.product_id WHERE pp.status='paid' ORDER BY pp.id DESC LIMIT 15");
    if ($recent) : ?>
        <h2 style="margin-top:26px">Recent sales</h2>
        <table class="widefat striped" style="max-width:820px">
            <thead><tr><th>When</th><th>Product</th><th>Buyer</th><th>Amount</th></tr></thead>
            <tbody><?php foreach ($recent as $r) : ?>
                <tr><td><?php echo esc_html($r->paid_at); ?></td><td><?php echo esc_html($r->title); ?></td><td><?php echo esc_html($r->email ?: '—'); ?></td><td><?php echo esc_html(function_exists('lmeg_format_price') ? lmeg_format_price((int)$r->amount_cents, $r->currency) : '$'.number_format($r->amount_cents/100,2)); ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>
    <?php endif;
    echo '</div>';
}
