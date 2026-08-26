<?php
if (!defined('ABSPATH')) exit;

/* ============================================================================
 * Square — alternate payment processor for the Fanloop Store (BETA)
 * Uses Square's hosted Payment Links (Quick Pay). Sandbox + production. Money
 * lands in the artist's own Square account. Physical orders collect a shipping
 * address; the paid order is verified server-side (on return + via webhook)
 * before fulfilment.
 * ========================================================================== */

function lmeg_square_keys() {
    $s    = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $mode = (($s['square_mode'] ?? 'sandbox') === 'production') ? 'production' : 'sandbox';
    if ($mode === 'production') {
        return ['token' => $s['square_prod_token'] ?? '', 'location' => $s['square_prod_location'] ?? '',
                'base' => 'https://connect.squareup.com', 'mode' => 'production', 'whk' => $s['square_webhook_key'] ?? ''];
    }
    return ['token' => $s['square_sandbox_token'] ?? '', 'location' => $s['square_sandbox_location'] ?? '',
            'base' => 'https://connect.squareupsandbox.com', 'mode' => 'sandbox', 'whk' => $s['square_webhook_key'] ?? ''];
}
function lmeg_square_ready() { $k = lmeg_square_keys(); return !empty($k['token']) && !empty($k['location']); }

function lmeg_square_request($method, $path, $body = null) {
    $k = lmeg_square_keys();
    if (empty($k['token'])) return new WP_Error('lmeg_sq_unconfigured', 'Square access token not set.');
    $args = ['method' => $method, 'timeout' => 20, 'headers' => [
        'Authorization'  => 'Bearer ' . $k['token'],
        'Square-Version' => '2024-07-17',
        'Content-Type'   => 'application/json',
    ]];
    if ($body !== null) $args['body'] = wp_json_encode($body);
    $resp = wp_remote_request($k['base'] . '/v2' . $path, $args);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    $data = json_decode(wp_remote_retrieve_body($resp), true) ?: [];
    if ($code < 200 || $code >= 300) {
        return new WP_Error('lmeg_sq_http_' . $code, $data['errors'][0]['detail'] ?? 'Square error');
    }
    return $data;
}

/**
 * Create a hosted Square Payment Link. Returns ['url','order_id'] or WP_Error.
 */
function lmeg_square_create_link($p, $amount, $variant, $physical, $return_url) {
    $k = lmeg_square_keys();
    if (empty($k['location'])) return new WP_Error('lmeg_sq_no_location', 'Square location id not set.');
    $name = $p->title . ($variant ? ' — ' . $variant : '');
    $r = lmeg_square_request('POST', '/online-checkout/payment-links', [
        'idempotency_key' => wp_generate_password(32, false, false),
        'quick_pay' => [
            'name'        => mb_substr($name, 0, 255),
            'price_money' => ['amount' => (int) $amount, 'currency' => strtoupper($p->currency ?: 'USD')],
            'location_id' => $k['location'],
        ],
        'checkout_options' => [
            'redirect_url'             => $return_url,
            'ask_for_shipping_address' => (bool) $physical,
        ],
    ]);
    if (is_wp_error($r)) return $r;
    return ['url' => $r['payment_link']['url'] ?? '', 'order_id' => $r['payment_link']['order_id'] ?? ''];
}

/**
 * Look up a Square order and return whether it's paid + the buyer/shipping
 * details we can capture. Returns null on error.
 */
function lmeg_square_order_info($order_id) {
    $r = lmeg_square_request('GET', '/orders/' . rawurlencode($order_id));
    if (is_wp_error($r) || empty($r['order'])) return null;
    $o = $r['order'];
    $paid = isset($o['net_amount_due_money']['amount'])
        ? ((int) $o['net_amount_due_money']['amount'] === 0)
        : (($o['state'] ?? '') === 'COMPLETED');
    $out = [
        'paid'      => $paid,
        'amount'    => (int) ($o['total_money']['amount'] ?? 0),
        'cur'       => strtoupper($o['total_money']['currency'] ?? 'USD'),
        'email'     => '',
        'ship_name' => '',
        'ship_addr' => '',
    ];
    if (!empty($o['fulfillments'][0]['shipment_details']['recipient'])) {
        $rec = $o['fulfillments'][0]['shipment_details']['recipient'];
        $out['email']     = sanitize_email($rec['email_address'] ?? '');
        $out['ship_name'] = sanitize_text_field($rec['display_name'] ?? '');
        $a = $rec['address'] ?? [];
        $out['ship_addr'] = trim(implode("\n", array_filter([
            trim(($a['address_line_1'] ?? '') . ' ' . ($a['address_line_2'] ?? '')),
            trim(($a['locality'] ?? '') . ', ' . ($a['administrative_district_level_1'] ?? '') . ' ' . ($a['postal_code'] ?? '')),
            $a['country'] ?? '',
        ])));
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * Webhook — Square notifies here when a payment/order updates. Backup to the
 * on-return verification so orders are captured even if the buyer closes the
 * tab. Register the URL (…/?lmeg_square=webhook) in Square → Webhooks and paste
 * the signature key into Settings → Payments.
 * ------------------------------------------------------------------------- */
add_action('init', 'lmeg_square_maybe_webhook');
function lmeg_square_maybe_webhook() {
    if (!isset($_GET['lmeg_square']) || $_GET['lmeg_square'] !== 'webhook') return;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { status_header(405); exit; }
    $body = file_get_contents('php://input');
    $k    = lmeg_square_keys();
    $sig  = $_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ?? '';
    $url  = home_url('/?lmeg_square=webhook');
    if (!empty($k['whk'])) {
        $expected = base64_encode(hash_hmac('sha256', $url . $body, $k['whk'], true));
        if (!hash_equals($expected, $sig)) { status_header(403); exit; }
    }
    $event = json_decode($body, true) ?: [];
    // Pull an order id out of whichever object this event carries.
    $obj = $event['data']['object'] ?? [];
    $order_id = $obj['payment']['order_id'] ?? ($obj['order_updated']['order_id'] ?? ($obj['order']['id'] ?? ''));
    if ($order_id && function_exists('lmeg_product_fulfill_square')) {
        lmeg_product_fulfill_square($order_id);
    }
    status_header(200);
    echo 'ok';
    exit;
}
