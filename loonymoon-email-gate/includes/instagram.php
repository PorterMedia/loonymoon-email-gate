<?php
/**
 * Instagram DM automation (Meta Messaging API).
 *
 * The mechanic: a fan DMs a keyword ("LOONY") to the artist's IG account →
 * Meta fires our webhook → we match a keyword rule → auto-reply with the
 * configured text (signup link, presale link, …) within Meta's allowed
 * 24-hour response window. Every conversation is logged.
 *
 * Meta does NOT allow unsolicited outbound DMs — replies only. That's a
 * platform rule, not a plugin limitation.
 *
 * Endpoints (both on ?lmeg_ig=webhook):
 *   GET  — Meta's subscription handshake (hub.challenge echo)
 *   POST — message events, verified via X-Hub-Signature-256 (app secret)
 */

if (!defined('ABSPATH')) {
    exit;
}

const LMEG_IG_GRAPH = 'https://graph.facebook.com/v21.0';

function lmeg_ig_configured() {
    $s = lmeg_get_settings();
    return !empty($s['ig_page_token']) && !empty($s['ig_account_id']);
}

function lmeg_ig_verify_token() {
    $s = lmeg_get_settings();
    if (!empty($s['ig_verify_token'])) return $s['ig_verify_token'];
    // Deterministic default derived from the site secret, so the field can
    // be pre-filled before the user ever saves settings.
    return substr(hash_hmac('sha256', 'ig-verify', lmeg_get_secret()), 0, 20);
}

/* ---------------------------------------------------------------------------
 * Webhook
 * ------------------------------------------------------------------------- */

add_action('init', 'lmeg_ig_maybe_handle_webhook');
function lmeg_ig_maybe_handle_webhook() {
    if (!isset($_GET['lmeg_ig']) || $_GET['lmeg_ig'] !== 'webhook') return;

    // Meta subscription handshake.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $mode      = $_GET['hub_mode']         ?? ($_GET['hub_mode'] ?? '');
        $token     = $_GET['hub_verify_token'] ?? '';
        $challenge = $_GET['hub_challenge']    ?? '';
        // PHP renames hub.mode → hub_mode automatically.
        if ($mode === 'subscribe' && hash_equals(lmeg_ig_verify_token(), (string) $token)) {
            echo $challenge;
            exit;
        }
        status_header(403);
        exit('Verification failed.');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        status_header(405);
        exit;
    }

    $payload = file_get_contents('php://input');
    $s       = lmeg_get_settings();

    // Signature check — reject anything not signed with our app secret.
    $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if (!$s['ig_app_secret']
        || strpos($sig, 'sha256=') !== 0
        || !hash_equals('sha256=' . hash_hmac('sha256', $payload, $s['ig_app_secret']), $sig)) {
        status_header(403);
        exit('Bad signature.');
    }

    $data = json_decode($payload, true);
    if (($data['object'] ?? '') === 'instagram') {
        foreach ((array) ($data['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                lmeg_ig_handle_message_event($event);
            }
        }
    }

    status_header(200);
    exit('ok');
}

function lmeg_ig_handle_message_event($event) {
    global $wpdb;

    $sender = (string) ($event['sender']['id'] ?? '');
    $text   = (string) ($event['message']['text'] ?? '');
    $echo   = !empty($event['message']['is_echo']);
    if (!$sender || $text === '' || $echo) return;

    // Flood guard: at most 30 inbound rows per user per hour.
    if (!lmeg_rate_limit('ig_in_' . $sender, 30, HOUR_IN_SECONDS)) return;

    $username = lmeg_ig_lookup_username($sender);

    $wpdb->insert($wpdb->prefix . 'lmeg_ig_messages', [
        'ig_user_id' => $sender,
        'username'   => $username ?: null,
        'direction'  => 'in',
        'text'       => substr($text, 0, 2000),
        'created_at' => current_time('mysql'),
    ]);

    // Keyword match — word-boundary, case-insensitive, first active rule wins.
    $rules = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lmeg_ig_rules WHERE is_active = 1 ORDER BY id ASC");
    foreach ((array) $rules as $rule) {
        if (!preg_match('/\b' . preg_quote($rule->keyword, '/') . '\b/iu', $text)) continue;

        // Anti-loop: one auto-reply per (user, rule) per 10 minutes.
        if (!lmeg_rate_limit('ig_reply_' . $sender . '_' . $rule->id, 1, 10 * MINUTE_IN_SECONDS)) return;

        $sent = lmeg_ig_send_reply($sender, $rule->reply_text);
        if (!is_wp_error($sent)) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}lmeg_ig_rules SET hits = hits + 1 WHERE id = %d", $rule->id
            ));
            $wpdb->insert($wpdb->prefix . 'lmeg_ig_messages', [
                'ig_user_id' => $sender,
                'username'   => $username ?: null,
                'direction'  => 'out',
                'text'       => substr($rule->reply_text, 0, 2000),
                'rule_id'    => (int) $rule->id,
                'created_at' => current_time('mysql'),
            ]);
        }
        return; // first match only
    }
}

/* ---------------------------------------------------------------------------
 * Graph API
 * ------------------------------------------------------------------------- */

function lmeg_ig_send_reply($igsid, $text) {
    $s = lmeg_get_settings();
    if (!lmeg_ig_configured()) return new WP_Error('lmeg_ig_unconfigured', 'Instagram is not configured.');

    $resp = wp_remote_post(
        LMEG_IG_GRAPH . '/' . rawurlencode($s['ig_account_id']) . '/messages?access_token=' . rawurlencode($s['ig_page_token']),
        [
            'timeout' => 12,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'recipient' => ['id' => $igsid],
                'message'   => ['text' => $text],
            ]),
        ]
    );
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('lmeg_ig_http_' . $code, wp_remote_retrieve_body($resp));
    }
    return true;
}

function lmeg_ig_lookup_username($igsid) {
    $s = lmeg_get_settings();
    if (!lmeg_ig_configured()) return '';
    $cache_key = 'lmeg_ig_un_' . md5($igsid);
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $resp = wp_remote_get(
        LMEG_IG_GRAPH . '/' . rawurlencode($igsid) . '?fields=username&access_token=' . rawurlencode($s['ig_page_token']),
        ['timeout' => 8]
    );
    $username = '';
    if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
        $d = json_decode(wp_remote_retrieve_body($resp), true);
        $username = (string) ($d['username'] ?? '');
    }
    set_transient($cache_key, $username, WEEK_IN_SECONDS);
    return $username;
}

function lmeg_ig_verify() {
    $s = lmeg_get_settings();
    if (!lmeg_ig_configured()) {
        return new WP_Error('lmeg_ig_unconfigured', 'Fill in the IG account ID and Page access token first.');
    }
    $resp = wp_remote_get(
        LMEG_IG_GRAPH . '/' . rawurlencode($s['ig_account_id']) . '?fields=username,name&access_token=' . rawurlencode($s['ig_page_token']),
        ['timeout' => 12]
    );
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    $d    = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code === 200 && !empty($d['username'])) {
        return 'Connected to @' . $d['username'] . ($d['name'] ? ' (' . $d['name'] . ')' : '') . '.';
    }
    $err = $d['error']['message'] ?? ('HTTP ' . $code);
    return new WP_Error('lmeg_ig_verify', 'Instagram: ' . $err);
}

/* ---------------------------------------------------------------------------
 * Admin — rules CRUD + conversation log
 * ------------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_submenu_page('lmeg', 'Instagram', 'Instagram', 'manage_options', 'lmeg-instagram', 'lmeg_admin_instagram');
}, 20);

function lmeg_admin_instagram() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $rules_tbl = $wpdb->prefix . 'lmeg_ig_rules';
    $notice = '';

    if (isset($_POST['lmeg_ig_nonce']) && wp_verify_nonce($_POST['lmeg_ig_nonce'], 'lmeg_ig')) {
        $act = sanitize_text_field($_POST['lmeg_action'] ?? '');
        if ($act === 'create') {
            $kw = sanitize_text_field(wp_unslash($_POST['keyword'] ?? ''));
            $rp = sanitize_textarea_field(wp_unslash($_POST['reply_text'] ?? ''));
            if ($kw && $rp) {
                $wpdb->insert($rules_tbl, [
                    'keyword'    => $kw,
                    'reply_text' => $rp,
                    'created_at' => current_time('mysql'),
                ]);
                $notice = '<div class="notice notice-success"><p>Rule added.</p></div>';
            }
        } elseif ($act === 'toggle') {
            $wpdb->query($wpdb->prepare("UPDATE $rules_tbl SET is_active = 1 - is_active WHERE id = %d", (int) ($_POST['rule_id'] ?? 0)));
        } elseif ($act === 'delete') {
            $wpdb->delete($rules_tbl, ['id' => (int) ($_POST['rule_id'] ?? 0)]);
            $notice = '<div class="notice notice-success"><p>Rule deleted.</p></div>';
        }
    }

    $configured  = lmeg_ig_configured();
    $webhook_url = add_query_arg('lmeg_ig', 'webhook', home_url('/'));
    $rules = $wpdb->get_results("SELECT * FROM $rules_tbl ORDER BY id ASC");
    $msgs  = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lmeg_ig_messages ORDER BY id DESC LIMIT 60");
    ?>
    <div class="wrap">
        <h1>Email Gate — Instagram DMs</h1>
        <?php echo $notice; ?>

        <?php if (!$configured) : ?>
            <div class="notice notice-info"><p>Instagram isn't connected yet — add credentials under
                <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings')); ?>">Settings → Instagram</a>. Setup guide is below.</p></div>
        <?php endif; ?>

        <p>Fans DM a keyword → they instantly get your reply (signup link, presale, whatever). Meta only permits <em>replies</em> — no cold outbound DMs, platform-wide rule.</p>

        <h2>Keyword rules</h2>
        <form method="post" style="margin-bottom:20px;display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;">
            <?php wp_nonce_field('lmeg_ig', 'lmeg_ig_nonce'); ?>
            <input type="hidden" name="lmeg_action" value="create" />
            <input type="text" name="keyword" placeholder="LOONY" style="max-width:160px;" required />
            <textarea name="reply_text" rows="2" style="flex:1;min-width:280px;" required placeholder="yo! join the loonybin here → https://loonymoonchild.com/subscribe/"></textarea>
            <button type="submit" class="button button-primary">Add rule</button>
        </form>

        <table class="widefat striped">
            <thead><tr><th>Keyword</th><th>Auto-reply</th><th>Hits</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($rules)) : ?>
                <tr><td colspan="5">No rules yet — add one above (e.g. keyword <code>LOONY</code> → your subscribe link).</td></tr>
            <?php else : foreach ($rules as $r) : ?>
                <tr>
                    <td><code><?php echo esc_html($r->keyword); ?></code></td>
                    <td style="max-width:420px;"><?php echo esc_html($r->reply_text); ?></td>
                    <td><strong><?php echo (int) $r->hits; ?></strong></td>
                    <td><?php echo $r->is_active ? '<span style="color:#34D399;">Active</span>' : '<span style="color:#F87171;">Paused</span>'; ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('lmeg_ig', 'lmeg_ig_nonce'); ?>
                            <input type="hidden" name="rule_id" value="<?php echo (int) $r->id; ?>" />
                            <button type="submit" name="lmeg_action" value="toggle" class="button"><?php echo $r->is_active ? 'Pause' : 'Activate'; ?></button>
                            <button type="submit" name="lmeg_action" value="delete" class="button button-link-delete" onclick="return confirm('Delete rule?');">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h2>Recent conversations</h2>
        <table class="widefat striped">
            <thead><tr><th></th><th>Fan</th><th>Message</th><th>When</th></tr></thead>
            <tbody>
            <?php if (empty($msgs)) : ?>
                <tr><td colspan="4">Nothing yet — once the webhook is live, incoming DMs and auto-replies appear here.</td></tr>
            <?php else : foreach ($msgs as $m) : ?>
                <tr>
                    <td><?php echo $m->direction === 'in' ? '📥' : '📤'; ?></td>
                    <td><?php echo esc_html($m->username ? '@' . $m->username : $m->ig_user_id); ?></td>
                    <td style="max-width:460px;"><?php echo esc_html($m->text); ?></td>
                    <td><?php echo esc_html($m->created_at); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h2>Meta setup (one-time)</h2>
        <ol style="max-width:820px;line-height:1.8;">
            <li>Instagram account must be a <strong>Business or Creator</strong> account, linked to a Facebook Page (IG app → Settings → Business tools).</li>
            <li>Create a Meta app at <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">developers.facebook.com/apps</a> → type <em>Business</em> → add the <strong>Messenger</strong> product → Instagram settings.</li>
            <li>Generate a <strong>Page access token</strong> for the linked Page with <code>instagram_basic</code>, <code>instagram_manage_messages</code>, <code>pages_manage_metadata</code>.</li>
            <li>Configure the webhook: callback URL <code><?php echo esc_html($webhook_url); ?></code>, verify token <code><?php echo esc_html(lmeg_ig_verify_token()); ?></code>, subscribe to <strong>messages</strong>.</li>
            <li>Paste App Secret, Page token, and your IG account ID into <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings')); ?>">Settings → Instagram</a> → Save &amp; test.</li>
            <li><strong>Dev mode:</strong> only DMs from accounts with a role on the app trigger the webhook — perfect for testing with your own IG. To go live for all fans, submit the app for review requesting Advanced Access on <code>instagram_manage_messages</code> (a form + a short screencast; usually approved within days).</li>
        </ol>
    </div>
    <?php
}
