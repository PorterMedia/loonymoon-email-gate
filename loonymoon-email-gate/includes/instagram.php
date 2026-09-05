<?php
/**
 * Instagram DM automation (Meta Messaging API) + comment-to-DM + CRM capture.
 *
 * The mechanics:
 *   • DM keyword   — a fan DMs a keyword ("LOONY") → Meta fires our webhook →
 *                    first matching rule auto-replies (signup link, presale…)
 *                    inside Meta's 24-hour window.
 *   • Comment→DM   — a fan comments a keyword on a post/reel → we send them a
 *                    private DM (Meta's "private reply", allowed once per
 *                    comment) and optionally post a public reply.
 *   • Capture      — a rule can ask for the fan's email; when they send it we
 *                    create a real subscriber (welcome email + sequences fire),
 *                    tag them, and link the whole conversation to their profile.
 *
 * Meta does NOT allow unsolicited outbound DMs — replies only. Platform rule.
 *
 * Endpoints (all on ?lmeg_ig=webhook):
 *   GET  — Meta's subscription handshake (hub.challenge echo)
 *   POST — message + comment events, verified via X-Hub-Signature-256
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
        $mode      = $_GET['hub_mode']         ?? '';
        $token     = $_GET['hub_verify_token'] ?? '';
        $challenge = $_GET['hub_challenge']    ?? '';
        // PHP renames hub.mode → hub_mode automatically.
        if ($mode === 'subscribe' && hash_equals(lmeg_ig_verify_token(), (string) $token)) {
            // Meta's hub.challenge is an integer; cast + plaintext so nothing but
            // digits can ever be reflected into the response body.
            header('Content-Type: text/plain; charset=utf-8');
            echo (int) $challenge;
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
    if (empty($s['ig_app_secret'])
        || strpos($sig, 'sha256=') !== 0
        || !hash_equals('sha256=' . hash_hmac('sha256', $payload, $s['ig_app_secret']), $sig)) {
        status_header(403);
        exit('Bad signature.');
    }

    $data = json_decode($payload, true);
    if (($data['object'] ?? '') === 'instagram') {
        foreach ((array) ($data['entry'] ?? []) as $entry) {
            // Direct messages.
            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                lmeg_ig_handle_message_event($event);
            }
            // Comments on posts/reels (field-change events).
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? '') === 'comments') {
                    lmeg_ig_handle_comment_change($change['value'] ?? []);
                }
            }
        }
    }

    status_header(200);
    exit('ok');
}

/* ---------------------------------------------------------------------------
 * Merge tags + email capture helpers
 * ------------------------------------------------------------------------- */

/** The public opt-in URL used in {subscribe_url}. */
function lmeg_ig_subscribe_url() {
    return apply_filters('lmeg_ig_subscribe_url', home_url('/subscribe/'));
}

/**
 * Render an auto-reply: swap {subscribe_url}, {community}, {artist}, {home_url}
 * and — when we already know the fan — the usual per-fan merge tags too.
 */
function lmeg_ig_render($text, $sub = null) {
    $text = strtr((string) $text, [
        '{subscribe_url}' => lmeg_ig_subscribe_url(),
        '{signup_url}'    => lmeg_ig_subscribe_url(),
        '{community}'     => lmeg_community(),
        '{artist}'        => lmeg_artist(),
        '{home_url}'      => home_url('/'),
    ]);
    if ($sub && function_exists('lmeg_render_merge_tags')) {
        $text = lmeg_render_merge_tags($text, $sub);
    }
    return $text;
}

/** First valid email address found anywhere in a message, or ''. */
function lmeg_ig_extract_email($text) {
    if (!preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', (string) $text, $m)) return '';
    $email = sanitize_email($m[0]);
    return is_email($email) ? $email : '';
}

/** Subscriber id already linked to this IG user, or 0. */
function lmeg_ig_linked_subscriber($ig_user_id) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT subscriber_id FROM {$wpdb->prefix}lmeg_ig_messages
         WHERE ig_user_id = %s AND subscriber_id IS NOT NULL ORDER BY id DESC LIMIT 1",
        (string) $ig_user_id
    ));
}

/** Log one IG message row (inbound or outbound). */
function lmeg_ig_log($ig_user_id, $username, $direction, $text, $args = []) {
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'lmeg_ig_messages', [
        'ig_user_id'    => (string) $ig_user_id,
        'username'      => $username ?: null,
        'direction'     => $direction,
        'source'        => $args['source'] ?? 'dm',
        'text'          => mb_substr((string) $text, 0, 2000),
        'rule_id'       => isset($args['rule_id']) ? (int) $args['rule_id'] : null,
        'subscriber_id' => isset($args['subscriber_id']) && $args['subscriber_id'] ? (int) $args['subscriber_id'] : null,
        'created_at'    => current_time('mysql'),
    ]);
}

/**
 * Turn an IG conversation into a real fan: create/dedupe the subscriber (which
 * fires the welcome email + sequences), tag them, and back-link every past
 * message from this IG user to the new profile.
 */
function lmeg_ig_capture_fan($ig_user_id, $username, $email, $rule = null) {
    global $wpdb;

    $sid = lmeg_store_subscriber([
        'contact_type' => 'email',
        'email'        => $email,
        'phone'        => null,
        'country'      => null,
        'street'       => null,
        'city'         => null,
        'region'       => null,
        'postal_code'  => null,
        'post_id'      => 0,
    ]);
    if (!$sid) return 0;

    // An "Instagram" auto-tag so IG-grown fans are segmentable (the subscribers
    // table has no source column — the tag is how we mark the channel).
    if (function_exists('lmeg_get_or_create_tag')) {
        $t = lmeg_get_or_create_tag('instagram', 'Instagram', true, '#E1306C');
        if ($t) lmeg_attach_tag($sid, $t->id);
        // Optional per-rule tag (a campaign/keyword tag the artist chose).
        if ($rule && !empty($rule->add_tag)) {
            $slug = sanitize_title($rule->add_tag);
            $ct   = lmeg_get_or_create_tag($slug, $rule->add_tag, false);
            if ($ct) lmeg_attach_tag($sid, $ct->id);
        }
    }

    // Back-link the whole thread to the profile.
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}lmeg_ig_messages SET subscriber_id = %d WHERE ig_user_id = %s",
        $sid, (string) $ig_user_id
    ));
    // Remember the IG handle on the profile for quick reference.
    if ($username) update_option('lmeg_ig_handle_' . $sid, $username, false);

    return $sid;
}

/* ---------------------------------------------------------------------------
 * Story mentions — a fan tags the artist in their Instagram story. We log it,
 * tag a known fan as a story-mentioner (a segmentable UGC list), and — if a
 * thank-you/permission reply is set — auto-DM them (within Meta's 24h window)
 * to say thanks and ask to repost. (Laylo/Cobrand "story mentions → UGC".)
 * ------------------------------------------------------------------------- */

function lmeg_ig_handle_story_mention($sender) {
    global $wpdb;
    // Once per user per hour (a fan may tag across several story frames).
    if (!lmeg_rate_limit('ig_story_' . $sender, 1, HOUR_IN_SECONDS)) return;

    $username = lmeg_ig_lookup_username($sender);
    $linked   = lmeg_ig_linked_subscriber($sender);

    lmeg_ig_log($sender, $username, 'in', 'mentioned you in their story 📸', ['source' => 'story', 'subscriber_id' => $linked]);

    // Tag a known fan so you can find + reward the people repping you.
    if ($linked && function_exists('lmeg_get_or_create_tag')) {
        $t = lmeg_get_or_create_tag('story-mention', 'Story mention', true, '#E1306C');
        if ($t) lmeg_attach_tag((int) $linked, $t->id);
    }

    // Auto thank-you + repost-permission ask, if configured.
    $s     = lmeg_get_settings();
    $reply = trim((string) ($s['ig_story_reply'] ?? ''));
    if ($reply !== '') {
        $sub      = $linked ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", $linked)) : null;
        $rendered = lmeg_ig_render($reply, $sub);
        if (!is_wp_error(lmeg_ig_dm($sender, $rendered))) {
            lmeg_ig_log($sender, $username, 'out', $rendered, ['source' => 'story', 'subscriber_id' => $linked]);
        }
    }
}

/* ---------------------------------------------------------------------------
 * Inbound DM handling
 * ------------------------------------------------------------------------- */

function lmeg_ig_handle_message_event($event) {
    global $wpdb;

    $sender = (string) ($event['sender']['id'] ?? '');
    $echo   = !empty($event['message']['is_echo']);
    if (!$sender || $echo) return;

    // Story mention — a fan tagged the artist in their IG story. It arrives here
    // as a message with a story_mention attachment (usually no text). Handle + stop.
    foreach ((array) ($event['message']['attachments'] ?? []) as $att) {
        if (($att['type'] ?? '') === 'story_mention') {
            lmeg_ig_handle_story_mention($sender);
            return;
        }
    }

    $text = (string) ($event['message']['text'] ?? '');
    if ($text === '') return;

    // Flood guard: at most 30 inbound rows per user per hour.
    if (!lmeg_rate_limit('ig_in_' . $sender, 30, HOUR_IN_SECONDS)) return;

    $username = lmeg_ig_lookup_username($sender);
    $linked   = lmeg_ig_linked_subscriber($sender);

    lmeg_ig_log($sender, $username, 'in', $text, ['source' => 'dm', 'subscriber_id' => $linked]);

    // --- Email capture -----------------------------------------------------
    // If we're waiting on this fan's email (a collect-email rule just replied),
    // or they volunteered an email and aren't a fan yet, capture them.
    $await_key = 'lmeg_ig_await_' . md5($sender);
    $await     = get_transient($await_key);
    $email     = lmeg_ig_extract_email($text);
    if ($email && !$linked) {
        $rule = $await ? $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lmeg_ig_rules WHERE id = %d", (int) $await
        )) : null;
        $sid = lmeg_ig_capture_fan($sender, $username, $email, $rule);
        if ($sid) {
            delete_transient($await_key);
            $confirm = lmeg_ig_render(
                "🎉 you're in! welcome to " . lmeg_community() . ". check your inbox — I just sent you a note.",
                $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", $sid))
            );
            if (!is_wp_error(lmeg_ig_dm($sender, $confirm))) {
                lmeg_ig_log($sender, $username, 'out', $confirm, ['source' => 'dm', 'subscriber_id' => $sid]);
            }
            return; // captured — don't also run a keyword reply this turn
        }
    }

    // --- Keyword rules -----------------------------------------------------
    $sub   = $linked ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", $linked)) : null;
    $rules = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lmeg_ig_rules WHERE is_active = 1 ORDER BY id ASC");
    foreach ((array) $rules as $rule) {
        if (!preg_match('/\b' . preg_quote($rule->keyword, '/') . '\b/iu', $text)) continue;

        // Anti-loop: one auto-reply per (user, rule) per 10 minutes.
        if (!lmeg_rate_limit('ig_reply_' . $sender . '_' . $rule->id, 1, 10 * MINUTE_IN_SECONDS)) return;

        $reply = lmeg_ig_render($rule->reply_text, $sub);
        $quick = lmeg_ig_buttons_payload($rule->buttons);
        $sent  = lmeg_ig_dm($sender, $reply, $quick);
        if (!is_wp_error($sent)) {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}lmeg_ig_rules SET hits = hits + 1 WHERE id = %d", $rule->id));
            lmeg_ig_log($sender, $username, 'out', $reply, ['source' => 'dm', 'rule_id' => (int) $rule->id, 'subscriber_id' => $linked]);
            // Arm email capture: their next message is treated as their email.
            if (!empty($rule->collect_email) && !$linked) {
                set_transient($await_key, (int) $rule->id, 30 * MINUTE_IN_SECONDS);
            }
        }
        return; // first match only
    }
}

/* ---------------------------------------------------------------------------
 * Comment-to-DM
 * ------------------------------------------------------------------------- */

function lmeg_ig_handle_comment_change($value) {
    global $wpdb;

    $comment_id = (string) ($value['id'] ?? '');
    $text       = (string) ($value['text'] ?? '');
    $from_id    = (string) ($value['from']['id'] ?? '');
    $from_user  = (string) ($value['from']['username'] ?? '');
    if (!$comment_id || $text === '' || !$from_id) return;

    // Ignore the artist's own comments/replies.
    $s = lmeg_get_settings();
    if ($from_id === (string) ($s['ig_account_id'] ?? '')) return;

    // One auto-action per comment, ever.
    if (!lmeg_rate_limit('ig_cmt_' . $comment_id, 1, DAY_IN_SECONDS)) return;

    $rules = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lmeg_ig_rules WHERE is_active = 1 AND on_comment = 1 ORDER BY id ASC");
    foreach ((array) $rules as $rule) {
        if (!preg_match('/\b' . preg_quote($rule->keyword, '/') . '\b/iu', $text)) continue;

        $linked = lmeg_ig_linked_subscriber($from_id);
        $sub    = $linked ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", $linked)) : null;

        // Log the comment itself.
        lmeg_ig_log($from_id, $from_user, 'in', $text, ['source' => 'comment', 'rule_id' => (int) $rule->id, 'subscriber_id' => $linked]);

        // Private reply — a one-time DM to whoever commented. (No quick-reply
        // buttons here: Meta doesn't reliably accept them on private replies.)
        $reply = lmeg_ig_render($rule->reply_text, $sub);
        $sent  = lmeg_ig_send(['comment_id' => $comment_id], $reply);
        if (!is_wp_error($sent)) {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}lmeg_ig_rules SET hits = hits + 1 WHERE id = %d", $rule->id));
            lmeg_ig_log($from_id, $from_user, 'out', $reply, ['source' => 'comment', 'rule_id' => (int) $rule->id, 'subscriber_id' => $linked]);
            if (!empty($rule->collect_email) && !$linked) {
                set_transient('lmeg_ig_await_' . md5($from_id), (int) $rule->id, 30 * MINUTE_IN_SECONDS);
            }
        }

        // Optional public reply on the comment thread.
        if (!empty($rule->public_reply)) {
            lmeg_ig_public_reply($comment_id, lmeg_ig_render($rule->public_reply, $sub));
        }
        return; // first match only
    }
}

/* ---------------------------------------------------------------------------
 * Graph API
 * ------------------------------------------------------------------------- */

/** Build a Meta quick-replies array from a comma-separated button list. */
function lmeg_ig_buttons_payload($buttons) {
    $buttons = trim((string) $buttons);
    if ($buttons === '') return [];
    $out = [];
    foreach (array_slice(array_filter(array_map('trim', explode(',', $buttons))), 0, 13) as $label) {
        $title = mb_substr($label, 0, 20); // Meta caps titles at 20 chars.
        $out[] = ['content_type' => 'text', 'title' => $title, 'payload' => $title];
    }
    return $out;
}

/** Low-level send. $recipient is e.g. ['id'=>igsid] or ['comment_id'=>cid]. */
function lmeg_ig_send($recipient, $text, $quick = []) {
    $s = lmeg_get_settings();
    if (!lmeg_ig_configured()) return new WP_Error('lmeg_ig_unconfigured', 'Instagram is not configured.');

    $message = ['text' => $text];
    if (!empty($quick)) $message['quick_replies'] = $quick;

    $resp = wp_remote_post(
        LMEG_IG_GRAPH . '/' . rawurlencode($s['ig_account_id']) . '/messages?access_token=' . rawurlencode($s['ig_page_token']),
        [
            'timeout' => 12,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['recipient' => $recipient, 'message' => $message]),
        ]
    );
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('lmeg_ig_http_' . $code, wp_remote_retrieve_body($resp));
    }
    return true;
}

/** Send a normal DM to an IG-scoped user id. */
function lmeg_ig_dm($igsid, $text, $quick = []) {
    return lmeg_ig_send(['id' => $igsid], $text, $quick);
}

/** Back-compat: earlier code called lmeg_ig_send_reply($igsid, $text). */
function lmeg_ig_send_reply($igsid, $text) {
    return lmeg_ig_dm($igsid, $text);
}

/** Post a public reply under a comment (needs instagram_manage_comments). */
function lmeg_ig_public_reply($comment_id, $text) {
    $s = lmeg_get_settings();
    if (!lmeg_ig_configured()) return new WP_Error('lmeg_ig_unconfigured', 'Instagram is not configured.');
    $resp = wp_remote_post(
        LMEG_IG_GRAPH . '/' . rawurlencode($comment_id) . '/replies',
        [
            'timeout' => 12,
            'body'    => ['message' => $text, 'access_token' => $s['ig_page_token']],
        ]
    );
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    return ($code >= 200 && $code < 300) ? true : new WP_Error('lmeg_ig_http_' . $code, wp_remote_retrieve_body($resp));
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

/**
 * Validate the App ID + App Secret directly (the exact check the Connect flow
 * does when exchanging the code), so a wrong/mismatched secret is caught on the
 * settings page instead of failing mid-OAuth.
 */
function lmeg_ig_verify_app() {
    $s = lmeg_get_settings();
    if (empty($s['ig_app_id']) || empty($s['ig_app_secret'])) {
        return new WP_Error('lmeg_ig_noapp', 'Add your App ID and App Secret first.');
    }
    $resp = wp_remote_get(LMEG_IG_GRAPH . '/oauth/access_token?' . http_build_query([
        'client_id'     => $s['ig_app_id'],
        'client_secret' => $s['ig_app_secret'],
        'grant_type'    => 'client_credentials',
    ]), ['timeout' => 15]);
    if (is_wp_error($resp)) return $resp;
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (!empty($body['access_token'])) {
        return '✓ App ID + App Secret are valid. Now click “Connect Instagram” above to finish.';
    }
    $msg = $body['error']['message'] ?? ('HTTP ' . wp_remote_retrieve_response_code($resp));
    return new WP_Error('lmeg_ig_appcheck', 'App credentials failed: ' . $msg
        . ' — re-copy the App Secret from THIS app’s Settings → Basic (App ID ' . $s['ig_app_id'] . '), clear the field, paste, Save.');
}

function lmeg_ig_verify() {
    $s = lmeg_get_settings();
    if (!lmeg_ig_configured()) {
        // Not connected yet — but if the App ID + Secret are in, validate THEM
        // so a bad secret surfaces here rather than mid-Connect.
        if (!empty($s['ig_app_id']) && !empty($s['ig_app_secret'])) {
            return lmeg_ig_verify_app();
        }
        return new WP_Error('lmeg_ig_unconfigured', 'Add your App ID + App Secret, then click “Connect Instagram”. (Or paste an IG account ID + Page token under “Connect manually instead”.)');
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
 * One-click connect — Facebook Login OAuth. The artist clicks "Connect
 * Instagram", approves on Meta's screen, and we exchange the code for a
 * long-lived Page token, discover their IG business account, store both, and
 * auto-subscribe the webhook. No token/ID copying.
 *
 * Needs an App ID + App Secret saved first (Meta app → Settings → Basic).
 * The redirect URI below must be listed in the app's Facebook Login →
 * "Valid OAuth Redirect URIs".
 * ------------------------------------------------------------------------- */

/** The single redirect URI used for both the authorize + token-exchange legs. */
function lmeg_ig_oauth_redirect_uri() {
    return add_query_arg('lmeg_ig_oauth', 'callback', home_url('/'));
}

/** Kick off the OAuth flow (admin-post, nonce-protected). */
add_action('admin_post_lmeg_ig_oauth_start', 'lmeg_ig_oauth_start');
function lmeg_ig_oauth_start() {
    if (!current_user_can('manage_options')) wp_die('Not allowed.');
    check_admin_referer('lmeg_ig_oauth');
    $s    = lmeg_get_settings();
    $back = admin_url('admin.php?page=lmeg-settings');
    if (empty($s['ig_app_id']) || empty($s['ig_app_secret'])) {
        wp_safe_redirect(add_query_arg('ig_oauth_err', 'noapp', $back));
        exit;
    }
    $state = wp_generate_password(24, false);
    set_transient('lmeg_ig_oauth_state', $state, 15 * MINUTE_IN_SECONDS);
    // Base scopes the app is known to have. instagram_manage_insights (follower
    // demographics + hashtag/insights) is OPT-IN per site — only appended when
    // the artist has ticked "Follower demographics" AND enabled that permission
    // on their Meta app. Requesting a permission the app hasn't been granted
    // makes Facebook reject the ENTIRE connect dialog for app admins, so it must
    // stay off by default. See Settings → Instagram.
    $scope = 'instagram_basic,instagram_manage_messages,instagram_manage_comments,pages_show_list,pages_read_engagement,business_management';
    if (!empty($s['ig_request_insights'])) $scope .= ',instagram_manage_insights';
    $url = 'https://www.facebook.com/v21.0/dialog/oauth?' . http_build_query([
        'client_id'     => $s['ig_app_id'],
        'redirect_uri'  => lmeg_ig_oauth_redirect_uri(),
        'state'         => $state,
        'response_type' => 'code',
        'scope'         => $scope,
    ]);
    wp_redirect($url);
    exit;
}

/** Handle Meta's redirect back to us. */
add_action('init', 'lmeg_ig_maybe_oauth_callback');
function lmeg_ig_maybe_oauth_callback() {
    if (($_GET['lmeg_ig_oauth'] ?? '') !== 'callback') return;
    $back  = admin_url('admin.php?page=lmeg-settings');
    $state = get_transient('lmeg_ig_oauth_state');
    delete_transient('lmeg_ig_oauth_state');

    // The user returning from Meta is our admin; the state binds the round-trip.
    if (!current_user_can('manage_options')
        || empty($_GET['state']) || !$state
        || !hash_equals((string) $state, (string) wp_unslash($_GET['state']))) {
        wp_safe_redirect(add_query_arg('ig_oauth_err', 'state', $back));
        exit;
    }
    if (!empty($_GET['error'])) {
        wp_safe_redirect(add_query_arg('ig_oauth_err', 'denied', $back));
        exit;
    }
    if (empty($_GET['code'])) {
        wp_safe_redirect(add_query_arg('ig_oauth_err', 'nocode', $back));
        exit;
    }
    $r = lmeg_ig_oauth_complete(sanitize_text_field(wp_unslash($_GET['code'])));
    if (is_wp_error($r)) {
        set_transient('lmeg_ig_oauth_msg', $r->get_error_message(), 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg('ig_oauth_err', 'exchange', $back));
        exit;
    }
    // Multiple IG accounts on this login → send them to the chooser.
    if (is_array($r) && !empty($r['choose'])) {
        wp_safe_redirect(add_query_arg('ig_choose', '1', $back));
        exit;
    }
    set_transient('lmeg_ig_oauth_msg', $r, 5 * MINUTE_IN_SECONDS);
    wp_safe_redirect(add_query_arg('ig_connected', '1', $back));
    exit;
}

/** Chooser submit — the admin picked which IG account to connect. */
add_action('admin_post_lmeg_ig_oauth_choose', 'lmeg_ig_oauth_choose');
function lmeg_ig_oauth_choose() {
    if (!current_user_can('manage_options')) wp_die('Not allowed.');
    check_admin_referer('lmeg_ig_choose');
    $back    = admin_url('admin.php?page=lmeg-settings');
    $choices = get_transient('lmeg_ig_oauth_choices_' . get_current_user_id());
    $idx     = (int) ($_POST['ig_choice'] ?? -1);
    if (!is_array($choices) || !isset($choices[$idx])) {
        wp_safe_redirect(add_query_arg('ig_oauth_err', 'state', $back));
        exit;
    }
    $msg = lmeg_ig_store_connection($choices[$idx]);
    delete_transient('lmeg_ig_oauth_choices_' . get_current_user_id());
    set_transient('lmeg_ig_oauth_msg', $msg, 5 * MINUTE_IN_SECONDS);
    wp_safe_redirect(add_query_arg('ig_connected', '1', $back));
    exit;
}

/** Exchange code → long-lived Page token + IG account id, then subscribe webhook. */
function lmeg_ig_oauth_complete($code) {
    $s = lmeg_get_settings();
    $redirect = lmeg_ig_oauth_redirect_uri();

    // 1) code → short-lived user token.
    $resp = wp_remote_get(LMEG_IG_GRAPH . '/oauth/access_token?' . http_build_query([
        'client_id'     => $s['ig_app_id'],
        'client_secret' => $s['ig_app_secret'],
        'redirect_uri'  => $redirect,
        'code'          => $code,
    ]), ['timeout' => 15]);
    $short = lmeg_ig_json($resp);
    if (is_wp_error($short)) return $short;
    if (empty($short['access_token'])) return new WP_Error('lmeg_ig_oauth', 'No user token returned.');

    // 2) short → long-lived user token (~60 days).
    $resp = wp_remote_get(LMEG_IG_GRAPH . '/oauth/access_token?' . http_build_query([
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => $s['ig_app_id'],
        'client_secret'     => $s['ig_app_secret'],
        'fb_exchange_token' => $short['access_token'],
    ]), ['timeout' => 15]);
    $long = lmeg_ig_json($resp);
    if (is_wp_error($long)) return $long;
    $user_token = $long['access_token'] ?? $short['access_token'];

    // 3) find the Page that has an IG business account (its token is long-lived
    //    because it's derived from the long-lived user token).
    $resp = wp_remote_get(LMEG_IG_GRAPH . '/me/accounts?' . http_build_query([
        'fields'       => 'id,name,access_token,instagram_business_account{id,username}',
        'access_token' => $user_token,
        'limit'        => 100,
    ]), ['timeout' => 15]);
    $pages = lmeg_ig_json($resp);
    if (is_wp_error($pages)) return $pages;

    // Collect EVERY Page that has a linked IG business account — a manager may
    // administer several (their own + the artists they manage). One → connect
    // it; several → let them pick which IG this particular site is for.
    $candidates = [];
    foreach ((array) ($pages['data'] ?? []) as $p) {
        if (empty($p['instagram_business_account']['id'])) continue;
        $candidates[] = [
            'ig_id'     => (string) $p['instagram_business_account']['id'],
            'ig_user'   => (string) ($p['instagram_business_account']['username'] ?? ''),
            'token'     => (string) ($p['access_token'] ?? ''),
            'page_id'   => (string) $p['id'],
            'page_name' => (string) ($p['name'] ?? ''),
        ];
    }
    if (!$candidates) {
        return new WP_Error('lmeg_ig_oauth', 'No Instagram Business account is linked to any Facebook Page on this login. In the Instagram app: Settings → Business tools → link your account to a Facebook Page, then reconnect.');
    }
    if (count($candidates) === 1) {
        return lmeg_ig_store_connection($candidates[0]);
    }
    // Several — stash them (15 min) and send the admin to a chooser.
    set_transient('lmeg_ig_oauth_choices_' . get_current_user_id(), $candidates, 15 * MINUTE_IN_SECONDS);
    return ['choose' => true];
}

/** Persist one chosen IG connection + wire up its webhooks. Returns a message. */
function lmeg_ig_store_connection($cand) {
    $s    = lmeg_get_settings();
    $opts = get_option(LMEG_OPTION, []);
    if (!is_array($opts)) $opts = [];
    $opts['ig_page_token'] = (string) $cand['token'];
    $opts['ig_account_id'] = (string) $cand['ig_id'];
    update_option(LMEG_OPTION, $opts);
    update_option('lmeg_ig_page_id', (string) $cand['page_id'], false);
    update_option('lmeg_ig_username', (string) $cand['ig_user'], false);

    // App-level webhook (object=instagram), via the app access token.
    wp_remote_post(LMEG_IG_GRAPH . '/' . rawurlencode($s['ig_app_id']) . '/subscriptions', [
        'timeout' => 15,
        'body'    => [
            'object'       => 'instagram',
            'callback_url' => add_query_arg('lmeg_ig', 'webhook', home_url('/')),
            'verify_token' => lmeg_ig_verify_token(),
            'fields'       => 'messages,comments',
            'access_token' => $s['ig_app_id'] . '|' . $s['ig_app_secret'],
        ],
    ]);
    // Subscribe THIS page so its events flow.
    wp_remote_post(LMEG_IG_GRAPH . '/' . rawurlencode($cand['page_id']) . '/subscribed_apps', [
        'timeout' => 15,
        'body'    => ['subscribed_fields' => 'messages,comments', 'access_token' => (string) $cand['token']],
    ]);
    return 'Connected to @' . ($cand['ig_user'] ?: $cand['ig_id']) . '.';
}

/** Decode a Graph API response, surfacing Meta's error message as a WP_Error. */
function lmeg_ig_json($resp) {
    if (is_wp_error($resp)) return $resp;
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (!empty($body['error']['message'])) {
        return new WP_Error('lmeg_ig_graph', $body['error']['message']);
    }
    return is_array($body) ? $body : [];
}

/** Disconnect — clear the stored token/account (admin-post, nonce-protected). */
add_action('admin_post_lmeg_ig_disconnect', 'lmeg_ig_disconnect');
function lmeg_ig_disconnect() {
    if (!current_user_can('manage_options')) wp_die('Not allowed.');
    check_admin_referer('lmeg_ig_disconnect');
    $opts = get_option(LMEG_OPTION, []);
    if (is_array($opts)) {
        $opts['ig_page_token'] = '';
        $opts['ig_account_id'] = '';
        update_option(LMEG_OPTION, $opts);
    }
    delete_option('lmeg_ig_page_id');
    delete_option('lmeg_ig_username');
    wp_safe_redirect(add_query_arg('ig_disconnected', '1', admin_url('admin.php?page=lmeg-settings')));
    exit;
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
                    'keyword'       => $kw,
                    'reply_text'    => $rp,
                    'collect_email' => !empty($_POST['collect_email']) ? 1 : 0,
                    'add_tag'       => ($tg = sanitize_text_field(wp_unslash($_POST['add_tag'] ?? ''))) !== '' ? $tg : null,
                    'on_comment'    => !empty($_POST['on_comment']) ? 1 : 0,
                    'public_reply'  => ($pr = sanitize_text_field(wp_unslash($_POST['public_reply'] ?? ''))) !== '' ? $pr : null,
                    'buttons'       => ($bt = sanitize_text_field(wp_unslash($_POST['buttons'] ?? ''))) !== '' ? $bt : null,
                    'created_at'    => current_time('mysql'),
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
        <h1>Fanloop — Instagram DMs</h1>
        <?php echo $notice; ?>

        <?php if (!$configured) : ?>
            <div class="notice notice-info"><p>Instagram isn't connected yet — add credentials under
                <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings')); ?>">Settings → Instagram</a>. Setup guide is below.</p></div>
        <?php endif; ?>

        <p>Fans DM (or comment) a keyword → they instantly get your reply — a signup link, presale, whatever. Ask for their email and they become a real fan on your list. Meta only permits <em>replies</em>, never cold outbound DMs (platform-wide rule).</p>

        <h2>Keyword rules</h2>
        <form method="post" style="margin-bottom:20px;max-width:900px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
            <?php wp_nonce_field('lmeg_ig', 'lmeg_ig_nonce'); ?>
            <input type="hidden" name="lmeg_action" value="create" />
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                <label style="flex:0 0 160px;"><strong>Keyword</strong><br>
                    <input type="text" name="keyword" placeholder="LOONY" style="width:100%;" required /></label>
                <label style="flex:1;min-width:280px;"><strong>Auto-reply (DM)</strong><br>
                    <textarea name="reply_text" rows="2" style="width:100%;" required placeholder="<?php echo esc_attr('yo! join ' . lmeg_community() . ' here → {subscribe_url}'); ?>"></textarea></label>
            </div>
            <p class="description" style="margin:8px 0 4px;">Merge tags: <code>{subscribe_url}</code> · <code>{community}</code> · <code>{artist}</code> · <code>{home_url}</code></p>
            <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start;margin-top:10px;">
                <label style="flex:1;min-width:220px;">Tap-buttons <span style="opacity:.6;">(optional, comma-separated)</span><br>
                    <input type="text" name="buttons" style="width:100%;" placeholder="Join the list, Tour dates, Shop" /></label>
                <label style="flex:1;min-width:220px;">Also tag captured fans <span style="opacity:.6;">(optional)</span><br>
                    <input type="text" name="add_tag" style="width:100%;" placeholder="presale-2026" /></label>
            </div>
            <div style="margin-top:12px;display:flex;flex-direction:column;gap:6px;">
                <label><input type="checkbox" name="collect_email" value="1" /> <strong>Ask for their email</strong> — their next message is captured as a new fan (welcome email + sequences fire). Make sure your reply asks for it.</label>
                <label><input type="checkbox" name="on_comment" value="1" /> <strong>Also trigger on comments</strong> — when someone comments this keyword on a post/reel, DM them automatically (comment-to-DM).</label>
            </div>
            <label style="display:block;margin-top:10px;">Public reply on the comment <span style="opacity:.6;">(optional — only used for comment triggers)</span><br>
                <input type="text" name="public_reply" style="width:100%;max-width:520px;" placeholder="sent you a DM! 💌" /></label>
            <p style="margin:14px 0 0;"><button type="submit" class="button button-primary">Add rule</button></p>
        </form>

        <table class="widefat striped">
            <thead><tr><th>Keyword</th><th>Auto-reply</th><th>Triggers</th><th>Hits</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($rules)) : ?>
                <tr><td colspan="6">No rules yet — add one above (e.g. keyword <code>LOONY</code> → your subscribe link).</td></tr>
            <?php else : foreach ($rules as $r) : ?>
                <tr>
                    <td><code><?php echo esc_html($r->keyword); ?></code></td>
                    <td style="max-width:380px;">
                        <?php echo esc_html($r->reply_text); ?>
                        <?php if (!empty($r->buttons)) : ?><br><span style="opacity:.7;font-size:12px;">🔘 <?php echo esc_html($r->buttons); ?></span><?php endif; ?>
                    </td>
                    <td style="font-size:12px;line-height:1.7;">
                        <span title="Direct messages">💬 DM</span>
                        <?php if (!empty($r->on_comment)) : ?><br><span title="Comments on posts">📝 Comments</span><?php endif; ?>
                        <?php if (!empty($r->collect_email)) : ?><br><span title="Captures email as a fan">✉️ Captures email</span><?php endif; ?>
                        <?php if (!empty($r->add_tag)) : ?><br><span title="Tags captured fans">🏷 <?php echo esc_html($r->add_tag); ?></span><?php endif; ?>
                    </td>
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
            <thead><tr><th></th><th>Fan</th><th>Message</th><th>Via</th><th>When</th></tr></thead>
            <tbody>
            <?php if (empty($msgs)) : ?>
                <tr><td colspan="5">Nothing yet — once the webhook is live, incoming DMs, comments, and auto-replies appear here.</td></tr>
            <?php else : foreach ($msgs as $m) :
                $who = $m->username ? '@' . $m->username : $m->ig_user_id;
            ?>
                <tr>
                    <td><?php echo $m->direction === 'in' ? '📥' : '📤'; ?></td>
                    <td><?php if (!empty($m->subscriber_id)) : ?>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'lmeg', 'fan' => (int) $m->subscriber_id], admin_url('admin.php'))); ?>"><?php echo esc_html($who); ?> ↗</a>
                    <?php else : echo esc_html($who); endif; ?></td>
                    <td style="max-width:420px;"><?php echo esc_html($m->text); ?></td>
                    <td style="font-size:12px;opacity:.75;"><?php echo $m->source === 'comment' ? '📝 comment' : ($m->source === 'story' ? '📸 story' : '💬 DM'); ?></td>
                    <td><?php echo esc_html($m->created_at); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h2>Meta setup (one-time)</h2>
        <p style="max-width:820px;">The connect button does the token + webhook wiring for you. You still create a Meta app once (Meta requires it), then it's a click.</p>
        <ol style="max-width:820px;line-height:1.8;">
            <li>Your Instagram must be a <strong>Business or Creator</strong> account, linked to a Facebook Page (IG app → Settings → Business tools).</li>
            <li>Create a Meta app at <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">developers.facebook.com/apps</a> → type <em>Business</em> → add the <strong>Facebook Login</strong> and <strong>Messenger</strong> products (Instagram settings).</li>
            <li>Meta app → <strong>Settings → Basic</strong>: copy the <strong>App ID</strong> and <strong>App Secret</strong> into <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings')); ?>">Settings → Instagram</a> and <strong>Save</strong>.</li>
            <li>Meta app → <strong>Facebook Login → Settings</strong> → add this to <em>Valid OAuth Redirect URIs</em>: <code><?php echo esc_html(function_exists('lmeg_ig_oauth_redirect_uri') ? lmeg_ig_oauth_redirect_uri() : ''); ?></code></li>
            <li>Back in Fanloop Settings → Instagram, click <strong>Connect Instagram</strong> → approve. Your token, account ID, and the <strong>messages</strong> + <strong>comments</strong> webhook are set up automatically.</li>
            <li><strong>Dev mode:</strong> until the app is reviewed, only DMs/comments from accounts with a role on the app trigger it — perfect for testing with your own IG. To go live for all fans, submit for Advanced Access on <code>instagram_manage_messages</code> (and <code>instagram_manage_comments</code> for comments) — a form + a short screencast, usually approved within days.</li>
        </ol>
        <p style="max-width:820px;opacity:.7;">Prefer to wire it by hand? The manual token/webhook fields are still under Settings → Instagram (“Connect manually instead”).</p>
    </div>
    <?php
}
