<?php
/**
 * Mailgun + Twilio API clients and the wp_cron-driven broadcast processor.
 *
 * All sends use wp_remote_post — no SDK dependencies. Errors are stored
 * per-recipient in the broadcast_log table so they can be inspected from
 * the admin UI.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Build the [text, html] body pair for an email send, appending the
 * unsubscribe footer per the user's template. Required for CASL/CAN-SPAM
 * compliance — every commercial email must offer a working unsubscribe.
 *
 * @return array{0:string,1:string}
 */
function lmeg_build_email_with_footer($body, $unsub_url) {
    $s        = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $template = isset($s['unsub_footer_text']) && $s['unsub_footer_text'] !== ''
        ? $s['unsub_footer_text']
        : "Don't want these? Unsubscribe here: {unsub_url}";

    $footer_text = str_replace('{unsub_url}', $unsub_url, $template);
    // Escape the template as HTML first, then substitute a real anchor for the placeholder.
    $footer_html = str_replace(
        '{unsub_url}',
        '<a href="' . esc_url($unsub_url) . '">unsubscribe</a>',
        esc_html($template)
    );

    $text = $body . "\n\n-- \n" . $footer_text;
    $html = nl2br(esc_html($body))
          . '<hr style="border:0;border-top:1px solid #ddd;margin:24px 0 12px;" />'
          . '<p style="font-size:12px;color:#888;font-family:sans-serif;">' . $footer_html . '</p>';

    return [$text, $html];
}

/**
 * Wrap every http(s) link in the HTML with a click-tracker redirect and
 * append a 1x1 tracking pixel. Only touches HTML; text remains untouched.
 */
function lmeg_apply_tracking($html, $broadcast_id, $subscriber_id) {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];

    if (!empty($s['tracking_clicks'])) {
        $html = preg_replace_callback(
            '/href\s*=\s*(["\'])(https?:\/\/[^"\']+)\1/i',
            function ($m) use ($broadcast_id, $subscriber_id) {
                $url = $m[2];
                // Skip already-tokenised unsubscribe / tracker links.
                if (strpos($url, 'lmeg_unsubscribe=') !== false || strpos($url, 'lmeg_track=') !== false) {
                    return $m[0];
                }
                return 'href=' . $m[1] . esc_url(lmeg_track_click_url($broadcast_id, $subscriber_id, $url)) . $m[1];
            },
            $html
        );
    }

    if (!empty($s['tracking_opens'])) {
        $html .= '<img src="' . esc_url(lmeg_track_open_url($broadcast_id, $subscriber_id)) . '" width="1" height="1" alt="" style="border:0;width:1px;height:1px;" />';
    }

    return $html;
}

/* ---------------------------------------------------------------------------
 * Mailgun
 * ------------------------------------------------------------------------- */

/**
 * Send a single email via Mailgun's HTTP API.
 *
 * @return true|WP_Error
 */
function lmeg_mailgun_send($to, $subject, $body_text, $body_html = '') {
    $s = lmeg_get_settings();
    $api_key = $s['mailgun_api_key'] ?? '';
    $domain  = $s['mailgun_domain']  ?? '';
    $from_e  = $s['mailgun_from_email'] ?? '';
    $from_n  = $s['mailgun_from_name']  ?? '';

    if (!$api_key || !$domain || !$from_e) {
        return new WP_Error('lmeg_mailgun_unconfigured', 'Mailgun is not configured.');
    }

    $endpoint = 'https://api.mailgun.net/v3/' . rawurlencode($domain) . '/messages';
    // EU customers — flip to api.eu.mailgun.net if Mailgun region is EU.
    if (!empty($s['mailgun_region']) && $s['mailgun_region'] === 'eu') {
        $endpoint = 'https://api.eu.mailgun.net/v3/' . rawurlencode($domain) . '/messages';
    }

    $from = $from_n ? sprintf('%s <%s>', $from_n, $from_e) : $from_e;
    $body = [
        'from'    => $from,
        'to'      => $to,
        'subject' => $subject,
        'text'    => $body_text,
    ];
    if ($body_html) {
        $body['html'] = $body_html;
    }

    $resp = wp_remote_post($endpoint, [
        'timeout' => 20,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode('api:' . $api_key),
        ],
        'body' => $body,
    ]);

    if (is_wp_error($resp)) {
        return $resp;
    }
    $code = wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) {
        return new WP_Error(
            'lmeg_mailgun_http_' . $code,
            'Mailgun returned ' . $code . ': ' . wp_remote_retrieve_body($resp)
        );
    }
    return true;
}

/* ---------------------------------------------------------------------------
 * Brevo (formerly Sendinblue)
 * ------------------------------------------------------------------------- */

/**
 * Send a single email via Brevo's Transactional API.
 *
 * @return true|WP_Error
 */
function lmeg_brevo_send($to, $subject, $body_text, $body_html = '') {
    $s = lmeg_get_settings();
    $api_key = $s['brevo_api_key']   ?? '';
    $from_e  = $s['brevo_from_email'] ?? '';
    $from_n  = $s['brevo_from_name']  ?? '';

    if (!$api_key || !$from_e) {
        return new WP_Error('lmeg_brevo_unconfigured', 'Brevo is not configured.');
    }

    $payload = [
        'sender'      => array_filter(['name' => $from_n, 'email' => $from_e]),
        'to'          => [['email' => $to]],
        'subject'     => $subject,
        'textContent' => $body_text,
    ];
    if ($body_html) {
        $payload['htmlContent'] = $body_html;
    }

    $resp = wp_remote_post('https://api.brevo.com/v3/smtp/email', [
        'timeout' => 20,
        'headers' => [
            'api-key'      => $api_key,
            'Content-Type' => 'application/json',
            'accept'       => 'application/json',
        ],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($resp)) {
        return $resp;
    }
    $code = wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) {
        return new WP_Error(
            'lmeg_brevo_http_' . $code,
            'Brevo returned ' . $code . ': ' . wp_remote_retrieve_body($resp)
        );
    }
    return true;
}

/**
 * Verify Brevo credentials by hitting GET /v3/account.
 */
function lmeg_brevo_verify() {
    $s = lmeg_get_settings();
    if (!$s['brevo_api_key']) {
        return new WP_Error('lmeg_brevo_unconfigured', 'Paste your Brevo API key first.');
    }
    $resp = wp_remote_get('https://api.brevo.com/v3/account', [
        'timeout' => 15,
        'headers' => [
            'api-key' => $s['brevo_api_key'],
            'accept'  => 'application/json',
        ],
    ]);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code === 200) {
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        $email = $data['email'] ?? '(unknown)';
        $plan  = isset($data['plan'][0]['type']) ? $data['plan'][0]['type'] : 'unknown';
        return 'Connected as ' . $email . ' (' . $plan . ' plan).';
    }
    if ($code === 401) {
        return new WP_Error('lmeg_brevo_auth', 'Authentication failed (HTTP 401). Check the API key.');
    }
    return new WP_Error('lmeg_brevo_http_' . $code, 'Brevo returned HTTP ' . $code . ': ' . wp_remote_retrieve_body($resp));
}

/* ---------------------------------------------------------------------------
 * Provider-agnostic wrapper — pick sender based on settings
 * ------------------------------------------------------------------------- */

/**
 * Send an email through whichever provider is configured. All broadcast /
 * welcome / magic-link / sequence code should call this instead of the
 * provider-specific functions.
 */
function lmeg_email_send($to, $subject, $body_text, $body_html = '') {
    $s = lmeg_get_settings();
    $provider = $s['email_provider'] ?? 'brevo';
    if ($provider === 'mailgun') {
        return lmeg_mailgun_send($to, $subject, $body_text, $body_html);
    }
    return lmeg_brevo_send($to, $subject, $body_text, $body_html);
}

/* ---------------------------------------------------------------------------
 * Twilio
 * ------------------------------------------------------------------------- */

/**
 * Send a single SMS via Twilio's REST API.
 *
 * @return true|WP_Error
 */
function lmeg_twilio_send($to_e164, $body) {
    $s     = lmeg_get_settings();
    $sid   = $s['twilio_account_sid'] ?? '';
    $token = $s['twilio_auth_token']  ?? '';
    $from  = $s['twilio_from_number'] ?? '';

    if (!$sid || !$token || !$from) {
        return new WP_Error('lmeg_twilio_unconfigured', 'Twilio is not configured.');
    }

    $endpoint = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
    $resp = wp_remote_post($endpoint, [
        'timeout' => 20,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($sid . ':' . $token),
        ],
        'body' => [
            'From' => $from,
            'To'   => $to_e164,
            'Body' => $body,
        ],
    ]);

    if (is_wp_error($resp)) {
        return $resp;
    }
    $code = wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) {
        return new WP_Error(
            'lmeg_twilio_http_' . $code,
            'Twilio returned ' . $code . ': ' . wp_remote_retrieve_body($resp)
        );
    }
    return true;
}

/* ---------------------------------------------------------------------------
 * Credential verification (used by Settings page "Test connection" buttons)
 * ------------------------------------------------------------------------- */

/**
 * Hit Mailgun's domain info endpoint with the saved credentials.
 * Returns a friendly status string on success, or WP_Error on failure.
 */
function lmeg_mailgun_verify() {
    $s = lmeg_get_settings();
    if (!$s['mailgun_api_key'] || !$s['mailgun_domain']) {
        return new WP_Error('lmeg_mailgun_unconfigured', 'Fill in the API key and sending domain first.');
    }
    $host = ($s['mailgun_region'] === 'eu') ? 'api.eu.mailgun.net' : 'api.mailgun.net';
    $resp = wp_remote_get('https://' . $host . '/v3/domains/' . rawurlencode($s['mailgun_domain']), [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode('api:' . $s['mailgun_api_key']),
        ],
    ]);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code === 200) {
        $data   = json_decode(wp_remote_retrieve_body($resp), true);
        $state  = $data['domain']['state'] ?? 'unknown';
        return 'Connected. Domain "' . $s['mailgun_domain'] . '" is ' . $state . '.';
    }
    if ($code === 401) {
        return new WP_Error('lmeg_mailgun_auth', 'Authentication failed (HTTP 401). Check the API key.');
    }
    if ($code === 404) {
        return new WP_Error('lmeg_mailgun_domain', 'Domain not found (HTTP 404). Check the sending domain spelling and region.');
    }
    return new WP_Error('lmeg_mailgun_http_' . $code, 'Mailgun returned HTTP ' . $code . ': ' . wp_remote_retrieve_body($resp));
}

/**
 * Hit Twilio's Account info endpoint with the saved credentials.
 */
function lmeg_twilio_verify() {
    $s = lmeg_get_settings();
    if (!$s['twilio_account_sid'] || !$s['twilio_auth_token']) {
        return new WP_Error('lmeg_twilio_unconfigured', 'Fill in the Account SID and Auth Token first.');
    }
    $resp = wp_remote_get('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($s['twilio_account_sid']) . '.json', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($s['twilio_account_sid'] . ':' . $s['twilio_auth_token']),
        ],
    ]);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code === 200) {
        $data        = json_decode(wp_remote_retrieve_body($resp), true);
        $friendly    = $data['friendly_name'] ?? '(unnamed)';
        $status      = $data['status']        ?? 'unknown';
        return 'Connected as "' . $friendly . '" (' . $status . ').';
    }
    if ($code === 401) {
        return new WP_Error('lmeg_twilio_auth', 'Authentication failed (HTTP 401). Check the SID and Auth Token.');
    }
    return new WP_Error('lmeg_twilio_http_' . $code, 'Twilio returned HTTP ' . $code . ': ' . wp_remote_retrieve_body($resp));
}

/* ---------------------------------------------------------------------------
 * Broadcast queue + cron
 * ------------------------------------------------------------------------- */

/**
 * Queue a broadcast that auto-routes each subscriber to their preferred
 * channel. body_email goes to people with contact_type='email' (using their
 * email address); body_sms goes to people with contact_type='phone' (using
 * their phone number). Empty body for a channel = skip that channel.
 *
 * @param array $args ['subject', 'body_email', 'body_sms', 'country_filter']
 * @return int|WP_Error broadcast id, or error
 */
function lmeg_queue_broadcast($args) {
    global $wpdb;
    $defaults = [
        'subject'        => '',
        'body_email'     => '',
        'body_sms'       => '',
        'tag_filter'     => null,   // ['tag_ids' => int[], 'match' => 'any'|'all']
        'scheduled_for'  => null,   // MySQL datetime in site timezone, or null = send immediately
    ];
    $args = wp_parse_args($args, $defaults);

    $has_email = trim((string) $args['body_email']) !== '';
    $has_sms   = trim((string) $args['body_sms'])   !== '';
    if (!$has_email && !$has_sms) {
        return new WP_Error('lmeg_empty_body', 'At least one of email body or SMS body is required.');
    }

    $subs_tbl  = $wpdb->prefix . LMEG_TABLE;
    $bcast_tbl = $wpdb->prefix . 'lmeg_broadcasts';
    $log_tbl   = $wpdb->prefix . 'lmeg_broadcast_log';

    // Pull every subscriber the channels are willing to reach. Skip the
    // unsubscribed. We only include rows whose contact_type matches a body
    // the sender provided AND whose corresponding contact column is populated.
    $clauses = [];
    if ($has_email) $clauses[] = "(contact_type = 'email' AND email IS NOT NULL AND email <> '')";
    if ($has_sms)   $clauses[] = "(contact_type = 'phone' AND phone IS NOT NULL AND phone <> '')";
    $where_parts = ['(' . implode(' OR ', $clauses) . ')', 'unsubscribed_at IS NULL'];
    $params = [];

    // Apply tag-based audience filter, if any.
    if (!empty($args['tag_filter']) && !empty($args['tag_filter']['tag_ids'])) {
        list($tag_sql, $tag_params) = lmeg_audience_where($args['tag_filter']);
        if ($tag_sql) {
            $where_parts[] = $tag_sql;
            $params        = array_merge($params, $tag_params);
        }
    }

    $sql  = "SELECT id, contact_type, email, phone FROM $subs_tbl WHERE " . implode(' AND ', $where_parts);
    $rows = $params
        ? $wpdb->get_results($wpdb->prepare($sql, $params))
        : $wpdb->get_results($sql);

    if (!$rows) {
        return new WP_Error('lmeg_no_recipients', 'No matching subscribers (after excluding unsubscribed).');
    }

    $stored_filter = !empty($args['tag_filter']['tag_ids'])
        ? wp_json_encode($args['tag_filter'])
        : null;

    $wpdb->insert($bcast_tbl, [
        'channel'        => 'auto',
        'subject'        => $has_email ? $args['subject'] : null,
        'body'           => $has_email ? $args['body_email'] : null,
        'body_sms'       => $has_sms   ? $args['body_sms']   : null,
        'country_filter' => null,
        'tag_filter'     => $stored_filter,
        'status'         => 'queued',
        'total'          => count($rows),
        'sent'           => 0,
        'failed'         => 0,
        'scheduled_for'  => $args['scheduled_for'] ?: null,
        'created_by'     => get_current_user_id(),
        'created_at'     => current_time('mysql'),
    ]);
    $bcast_id = (int) $wpdb->insert_id;

    foreach ($rows as $r) {
        $channel   = $r->contact_type === 'phone' ? 'sms' : 'email';
        $recipient = $channel === 'sms' ? $r->phone : $r->email;
        $wpdb->insert($log_tbl, [
            'broadcast_id'  => $bcast_id,
            'subscriber_id' => $r->id,
            'channel'       => $channel,
            'recipient'     => $recipient,
            'status'        => 'pending',
        ]);
    }

    if (!wp_next_scheduled('lmeg_broadcast_tick')) {
        wp_schedule_event(time() + 30, 'lmeg_minute', 'lmeg_broadcast_tick');
    }
    spawn_cron();

    return $bcast_id;
}

/**
 * Send a single test message right now (no logging).
 *
 * @return true|WP_Error
 */
function lmeg_send_test($channel, $to, $subject, $body) {
    if ($channel === 'email') {
        if (!is_email($to)) {
            return new WP_Error('lmeg_bad_email', 'Invalid test email address.');
        }
        // Use a placeholder unsubscribe URL so the admin sees the footer
        // formatting; the link is non-functional (no matching subscriber row).
        $placeholder_url = add_query_arg(['lmeg_unsubscribe' => 1, 'u' => 0, 't' => 'test'], home_url('/'));
        list($text, $html) = lmeg_build_email_with_footer($body, $placeholder_url);
        return lmeg_email_send($to, $subject ?: '(test)', $text, $html);
    }
    if ($channel === 'sms') {
        if (!preg_match('/^\+\d{6,16}$/', $to)) {
            return new WP_Error('lmeg_bad_phone', 'Test phone must be E.164 (e.g. +14155551234).');
        }
        return lmeg_twilio_send($to, $body);
    }
    return new WP_Error('lmeg_bad_channel', 'Invalid channel.');
}

/**
 * Custom cron schedule — every minute.
 */
add_filter('cron_schedules', 'lmeg_cron_schedules');
function lmeg_cron_schedules($s) {
    if (!isset($s['lmeg_minute'])) {
        $s['lmeg_minute'] = ['interval' => 60, 'display' => 'Every Minute (Loonymoon Email Gate)'];
    }
    return $s;
}

/**
 * Per-tick batch size. Twilio Trial accounts cap throughput; Mailgun handles
 * far more — but we keep both modest so a single PHP cron tick stays fast.
 */
function lmeg_batch_size() {
    return (int) apply_filters('lmeg_batch_size', 25);
}

/**
 * The cron tick — picks up to one queued broadcast and sends N recipients.
 */
add_action('lmeg_broadcast_tick', 'lmeg_process_broadcast_tick');
function lmeg_process_broadcast_tick() {
    global $wpdb;
    $bcast_tbl = $wpdb->prefix . 'lmeg_broadcasts';
    $log_tbl   = $wpdb->prefix . 'lmeg_broadcast_log';

    // Pick the oldest broadcast that's eligible to send right now. A
    // scheduled broadcast is invisible to the picker until its scheduled_for
    // moment passes — it sits patiently in 'queued' state until then.
    // Compare against WP's current_time so site-timezone scheduled_for
    // values line up regardless of the MySQL server's timezone.
    $now = current_time('mysql');
    $bcast = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $bcast_tbl
         WHERE status IN ('queued','sending')
           AND (scheduled_for IS NULL OR scheduled_for <= %s)
         ORDER BY id ASC LIMIT 1",
        $now
    ));
    if (!$bcast) {
        return;
    }

    if ($bcast->status === 'queued') {
        $wpdb->update($bcast_tbl, ['status' => 'sending'], ['id' => $bcast->id]);
    }

    $batch = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $log_tbl WHERE broadcast_id = %d AND status = 'pending' ORDER BY id ASC LIMIT %d",
        $bcast->id, lmeg_batch_size()
    ));

    if (!$batch) {
        // No pending — broadcast is done.
        $wpdb->update($bcast_tbl, [
            'status'       => 'completed',
            'completed_at' => current_time('mysql'),
        ], ['id' => $bcast->id]);
        return;
    }

    // Preload subscriber rows for the batch so merge-tag substitution is one query, not N.
    $sub_ids = array_map(function ($r) { return (int) $r->subscriber_id; }, $batch);
    $subs_by_id = [];
    if ($sub_ids) {
        $placeholders = implode(',', array_fill(0, count($sub_ids), '%d'));
        $sub_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id IN ($placeholders)",
            $sub_ids
        ));
        foreach ($sub_rows as $sr) $subs_by_id[(int) $sr->id] = $sr;
    }

    foreach ($batch as $row) {
        $sub = $subs_by_id[(int) $row->subscriber_id] ?? null;

        // Route by the log row's own channel — auto broadcasts mix both.
        if ($row->channel === 'email') {
            $body      = lmeg_render_merge_tags((string) $bcast->body, $sub);
            $subject   = lmeg_render_merge_tags((string) $bcast->subject, $sub);
            $unsub_url = lmeg_unsub_url((int) $row->subscriber_id, $row->recipient);
            list($text, $html) = lmeg_build_email_with_footer($body, $unsub_url);
            $html = lmeg_apply_tracking($html, (int) $bcast->id, (int) $row->subscriber_id);
            $result = lmeg_email_send(
                $row->recipient,
                $subject ?: '(no subject)',
                $text,
                $html
            );
        } else {
            $body = $bcast->body_sms !== null && $bcast->body_sms !== ''
                ? $bcast->body_sms
                : $bcast->body;
            $body   = lmeg_render_merge_tags((string) $body, $sub);
            $result = lmeg_twilio_send($row->recipient, $body);
        }

        if (is_wp_error($result)) {
            $wpdb->update($log_tbl, [
                'status'  => 'failed',
                'error'   => substr($result->get_error_message(), 0, 500),
                'sent_at' => current_time('mysql'),
            ], ['id' => $row->id]);
            $wpdb->query($wpdb->prepare(
                "UPDATE $bcast_tbl SET failed = failed + 1 WHERE id = %d",
                $bcast->id
            ));
        } else {
            $wpdb->update($log_tbl, [
                'status'  => 'sent',
                'sent_at' => current_time('mysql'),
            ], ['id' => $row->id]);
            $wpdb->query($wpdb->prepare(
                "UPDATE $bcast_tbl SET sent = sent + 1 WHERE id = %d",
                $bcast->id
            ));
        }
    }

    // If anything still pending, leave status='sending'. Cron will pick it up next minute.
    $remaining = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $log_tbl WHERE broadcast_id = %d AND status = 'pending'",
        $bcast->id
    ));
    if ($remaining === 0) {
        $wpdb->update($bcast_tbl, [
            'status'       => 'completed',
            'completed_at' => current_time('mysql'),
        ], ['id' => $bcast->id]);
    }
}
