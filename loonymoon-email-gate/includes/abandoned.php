<?php
if (!defined('ABSPATH')) exit;

/* ============================================================================
 * Fanloop Store — abandoned carts + recovery  (BETA)
 * ----------------------------------------------------------------------------
 * When a shopper reaches checkout and enters their email but doesn't complete
 * the order, the cart is saved. The artist sees open carts in the Store admin
 * and can send a one-tap branded "you left something" email with a resume link
 * that reopens the exact cart. Any completed purchase by that email auto-marks
 * their open carts recovered. Table: lmeg_abandoned.
 *
 * Note: in demo checkout the order completes instantly, so nothing abandons —
 * this recovers real (live) checkouts that stall before payment.
 * ========================================================================== */

/** Save a cart at checkout so it can be recovered. Returns the resume token. */
function lmeg_abandoned_capture($email, $raw, $v) {
    if (!$email || !is_email($email)) return '';
    global $wpdb;
    $token = wp_generate_password(32, false, false);
    $wpdb->insert($wpdb->prefix . 'lmeg_abandoned', [
        'email'       => $email,
        'token'       => $token,
        'cart'        => wp_json_encode($raw),
        'total_cents' => (int) $v['total'],
        'currency'    => $v['currency'],
        'recovered'   => 0,
        'nudged'      => 0,
        'created_at'  => current_time('mysql'),
    ]);
    return $token;
}

/** Mark every open cart for an email recovered (called when they buy). */
function lmeg_abandoned_mark_recovered($email) {
    if (!$email) return;
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}lmeg_abandoned SET recovered = 1, recovered_at = %s WHERE email = %s AND recovered = 0",
        current_time('mysql'), $email
    ));
}

/** Resume link for a saved cart (optionally carrying a comeback code to auto-apply). */
function lmeg_abandoned_resume_url($token, $code = '') {
    $args = ['lmeg_cart' => 'resume', 'token' => $token];
    if ($code !== '') $args['code'] = $code;
    return add_query_arg($args, home_url('/'));
}

/**
 * Get (creating once) a one-time percent "comeback" discount for an abandoned
 * cart — a per-cart code, single use, expiring in 14 days. Returns '' when the
 * comeback-discount setting is off. Deterministic per cart token, so re-sending
 * the reminder reuses the same code (no duplicates).
 */
function lmeg_abandoned_comeback_code($row) {
    $s   = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $pct = max(0, min(90, (int) ($s['store_cart_nudge_pct'] ?? 0)));
    if ($pct <= 0 || !$row || empty($row->token)) return '';
    global $wpdb;
    $tbl  = $wpdb->prefix . 'lmeg_discounts';
    $stub = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $row->token), 0, 6));
    $code = function_exists('lmeg_discount_norm') ? lmeg_discount_norm('BACK' . $stub) : ('BACK' . $stub);
    if ($code === '') return '';
    $existing = function_exists('lmeg_discount_get') ? lmeg_discount_get($code)
        : $wpdb->get_row($wpdb->prepare("SELECT id FROM $tbl WHERE code = %s", $code));
    if (!$existing) {
        $wpdb->insert($tbl, [
            'code' => $code, 'kind' => 'percent', 'value' => $pct,
            'currency' => strtoupper($row->currency ?: 'USD'),
            'min_subtotal_cents' => 0, 'max_uses' => 1, 'used' => 0,
            'expires_at' => date('Y-m-d H:i:s', current_time('timestamp') + 14 * DAY_IN_SECONDS),
            'status' => 'active', 'created_at' => current_time('mysql'),
        ]);
    }
    return $code;
}

/** ?lmeg_cart=resume — reopen a saved cart on the checkout page. */
function lmeg_abandoned_resume() {
    global $wpdb;
    $token = sanitize_text_field($_GET['token'] ?? '');
    $row   = $token ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lmeg_abandoned WHERE token = %s", $token)) : null;
    if (!$row) { wp_safe_redirect(home_url('/')); exit; }
    $raw = json_decode($row->cart, true);
    if (!is_array($raw)) $raw = [];
    $v = lmeg_cart_validate($raw);
    lmeg_cart_checkout_page($v, $raw, '', $row->email);   // prefill their email
}

/* ---------------------------------------------------------------------------
 * Nudge email
 * ------------------------------------------------------------------------- */
function lmeg_abandoned_send_nudge($row) {
    if (!function_exists('lmeg_email_deliver') || !$row || !$row->email) return false;
    $artist = lmeg_email_artist();
    $raw    = json_decode($row->cart, true);
    $v      = lmeg_cart_validate(is_array($raw) ? $raw : []);

    $items = [];
    foreach ($v['lines'] as $line) {
        $items[] = [
            'name'   => $line['p']->title . ($line['variant'] ? ' · ' . $line['variant'] : ''),
            'meta'   => (int) $line['qty'] > 1 ? 'Qty ' . (int) $line['qty'] : '',
            'amount' => lmeg_cart_money($line['unit'] * $line['qty'], $line['cur']),
        ];
    }
    // Fall back to the saved snapshot if the products changed/vanished.
    $total = $v['lines'] ? $v['total'] : (int) $row->total_cents;
    $cur   = $v['lines'] ? $v['currency'] : $row->currency;
    if (!$items) $items[] = ['name' => 'Your cart', 'meta' => '', 'amount' => lmeg_cart_money($total, $cur)];

    // Optional "comeback" discount — a one-time code that auto-applies from the button.
    $ns     = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $pct    = max(0, min(90, (int) ($ns['store_cart_nudge_pct'] ?? 0)));
    $code   = lmeg_abandoned_comeback_code($row);
    if (!$code) $pct = 0;
    $offer  = $code ? lmeg_email_note('🎁 Here\'s <strong>' . $pct . '% off</strong> to finish up — code <strong>' . esc_html($code) . '</strong>, already applied when you tap below.') : '';
    $subject = $code ? ('Still thinking it over? Here\'s ' . $pct . '% off') : 'You left something in your cart';

    $inner = lmeg_email_h('You left something behind 🛒')
        . lmeg_email_p('Your cart with <strong>' . esc_html($artist) . '</strong> is still here — pick up right where you left off.')
        . lmeg_email_order_table($items, lmeg_cart_money($total, $cur))
        . $offer
        . lmeg_email_button($code ? 'Finish & save ' . $pct . '% →' : 'Complete your order →', lmeg_abandoned_resume_url($row->token, $code))
        . lmeg_email_note('Changed your mind? No worries — you can ignore this email.');
    return lmeg_email_deliver($row->email, $subject, $inner, 'Your cart with ' . $artist . ' is still waiting.');
}

/**
 * Cron: automatically send one reminder for open carts older than the configured
 * delay (opt-in). Runs on the plugin's per-minute tick; small batch per run.
 */
add_action('lmeg_broadcast_tick', 'lmeg_abandoned_nudge_tick', 45);
function lmeg_abandoned_nudge_tick() {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    if (empty($s['store_cart_nudge']) || !function_exists('lmeg_email_deliver')) return;
    $hours  = max(1, min(72, (int) ($s['store_cart_nudge_hours'] ?? 1)));
    $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - $hours * HOUR_IN_SECONDS);

    global $wpdb;
    $tbl  = $wpdb->prefix . 'lmeg_abandoned';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $tbl WHERE recovered = 0 AND nudged = 0 AND created_at <= %s ORDER BY id ASC LIMIT 15",
        $cutoff
    ));
    foreach ((array) $rows as $r) {
        lmeg_abandoned_send_nudge($r);
        $wpdb->update($tbl, ['nudged' => 1, 'nudged_at' => current_time('mysql')], ['id' => (int) $r->id]);
    }
}

add_action('admin_post_lmeg_nudge_cart', 'lmeg_handle_nudge_cart');
function lmeg_handle_nudge_cart() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_nudge_cart', 'lmeg_nudge_nonce');
    global $wpdb;
    $id  = (int) ($_POST['abandoned_id'] ?? 0);
    $row = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lmeg_abandoned WHERE id = %d", $id)) : null;
    if ($row && !$row->recovered) {
        lmeg_abandoned_send_nudge($row);
        $wpdb->update($wpdb->prefix . 'lmeg_abandoned', ['nudged' => 1, 'nudged_at' => current_time('mysql')], ['id' => $id]);
    }
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products&nudged=1#abandoned')); exit;
}

/* ---------------------------------------------------------------------------
 * Admin — "Abandoned carts" section, embedded on the Store page.
 * ------------------------------------------------------------------------- */
function lmeg_abandoned_admin_section() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $tbl  = $wpdb->prefix . 'lmeg_abandoned';
    $open = (int) $wpdb->get_var("SELECT COUNT(*) FROM $tbl WHERE recovered = 0");
    $rec  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $tbl WHERE recovered = 1");
    if ($open === 0 && $rec === 0) return;   // nothing to show yet

    $rows  = $wpdb->get_results("SELECT * FROM $tbl WHERE recovered = 0 ORDER BY id DESC LIMIT 50");
    $save  = admin_url('admin-post.php');
    $money = function ($c, $cur) { return function_exists('lmeg_format_price') ? lmeg_format_price((int) $c, $cur) : ('$' . number_format($c / 100, 2)); };
    ?>
    <h2 id="abandoned" style="margin-top:30px">Abandoned carts <span style="font-size:12px;color:#8B90A0;font-weight:400">· <?php echo (int) $rec; ?> recovered</span></h2>
    <?php if (isset($_GET['nudged'])) echo '<div class="notice notice-success is-dismissible"><p>Reminder sent.</p></div>'; ?>
    <?php $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : []; ?>
    <p class="description" style="margin:0 0 12px;max-width:800px">Shoppers who reached checkout but didn’t finish. Send a one-tap reminder with a link that reopens their exact cart. Anyone who later buys is cleared automatically.
        <?php if (!empty($s['store_cart_nudge'])) : ?><br><span style="color:#1a8a4a">● Auto-reminders on</span> — one email is sent automatically after <?php echo (int) ($s['store_cart_nudge_hours'] ?? 1); ?> hour(s). <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings#payments')); ?>">Change</a>
        <?php else : ?><br>Want these sent automatically? Turn on <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings#payments')); ?>">Abandoned-cart reminder</a> in Settings → Payments.<?php endif; ?>
        <?php echo lmeg_store_demo_on() ? '<br><strong>Heads up:</strong> demo checkout completes instantly, so live carts are what show up here.' : ''; ?></p>
    <?php if (!$rows) : ?>
        <p style="opacity:.7">No open carts right now.</p>
    <?php else : ?>
    <table class="widefat striped" style="max-width:940px">
        <thead><tr><th>When</th><th>Email</th><th>Items</th><th>Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r) :
            $raw = json_decode($r->cart, true); $names = [];
            if (is_array($raw)) foreach ($raw as $it) { $p = lmeg_product_get((int) ($it['id'] ?? 0)); if ($p) $names[] = $p->title . (!empty($it['qty']) && (int) $it['qty'] > 1 ? ' ×' . (int) $it['qty'] : ''); }
        ?>
            <tr>
                <td><?php echo esc_html(human_time_diff(strtotime($r->created_at), current_time('timestamp'))); ?> ago</td>
                <td><?php echo esc_html($r->email); ?></td>
                <td style="max-width:320px"><?php echo $names ? esc_html(implode(', ', $names)) : '<span style="color:#9A9DB0">—</span>'; ?></td>
                <td><?php echo esc_html($money((int) $r->total_cents, $r->currency)); ?></td>
                <td>
                    <form method="post" action="<?php echo esc_url($save); ?>" style="display:inline">
                        <?php wp_nonce_field('lmeg_nudge_cart', 'lmeg_nudge_nonce'); ?>
                        <input type="hidden" name="action" value="lmeg_nudge_cart">
                        <input type="hidden" name="abandoned_id" value="<?php echo (int) $r->id; ?>">
                        <button class="button button-small" type="submit"><?php echo $r->nudged ? 'Send again' : 'Send reminder'; ?></button>
                    </form>
                    <?php if ($r->nudged) : ?><span class="description" style="margin-left:6px">sent <?php echo esc_html(human_time_diff(strtotime($r->nudged_at), current_time('timestamp'))); ?> ago</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif;
}
