<?php
/**
 * Brevo + Twilio API clients and the wp_cron-driven broadcast processor.
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

    // Plain-text alternative: strip markup so HTML broadcasts read cleanly
    // in text-only clients and spam filters see matching content.
    $text = trim(wp_strip_all_tags($body)) . "\n\n-- \n" . $footer_text;

    if (!empty($s['email_template_enabled'])) {
        $html = lmeg_branded_email_html($body, $footer_html);
    } else {
        // Legacy plain rendering.
        $html = nl2br(esc_html($body))
              . '<hr style="border:0;border-top:1px solid #ddd;margin:24px 0 12px;" />'
              . '<p style="font-size:12px;color:#888;font-family:sans-serif;">' . $footer_html . '</p>';
    }

    return [$text, $html];
}

/**
 * Wrap email content in the branded loonybin template.
 *
 * Design pulled from the /subscribe/ page: warm cream backdrop, white
 * rounded card, the site's primary (pink) accent for links and the top
 * rule, logo centered in the header. Email-safe: tables, inline styles,
 * system font stack, no external assets besides the logo image.
 */
function lmeg_branded_email_html($body, $footer_html) {
    $s      = lmeg_get_settings();
    $accent = sanitize_hex_color($s['color_primary'] ?? '') ?: '#d05fa2';
    $logo   = trim((string) ($s['logo_url'] ?? ''));
    $logo_w = max(60, min(300, (int) ($s['logo_max_width'] ?? 180)));
    $site   = get_bloginfo('name');
    $note   = trim((string) ($s['email_footer_note'] ?? ''));

    // Body was sanitized with wp_kses_post at save time. Autoparagraph it
    // and make bare URLs clickable (magic links arrive as plain URLs).
    $content = wpautop(make_clickable($body));
    // Give paragraphs, links, images, headings + lists email-safe inline styles.
    $content = str_replace('<p>', '<p style="margin:0 0 1.15em;font-size:16px;line-height:1.65;color:#2f2a2c;">', $content);
    $content = preg_replace('/<a(?![^>]*style=)/', '<a style="color:' . $accent . ';text-decoration:underline;"', $content);
    $content = preg_replace('/<img(?![^>]*style=)/', '<img style="max-width:100%;height:auto;border:0;border-radius:10px;display:block;margin:0 auto 1.15em;"', $content);
    $content = preg_replace('/<h([1-3])(?![^>]*style=)/', '<h$1 style="margin:1.2em 0 .5em;font-size:22px;line-height:1.25;color:#2f2a2c;"', $content);
    $content = preg_replace('/<(ul|ol)(?![^>]*style=)/', '<$1 style="margin:0 0 1.15em;padding-left:1.4em;color:#2f2a2c;font-size:16px;line-height:1.65;"', $content);
    $content = preg_replace('/<blockquote(?![^>]*style=)/', '<blockquote style="margin:0 0 1.15em;padding:.6em 1em;border-left:3px solid ' . $accent . ';color:#5a5257;font-style:italic;"', $content);
    // Footer links (unsubscribe) get a muted tone instead of default blue.
    $footer_html = preg_replace('/<a(?![^>]*style=)/', '<a style="color:#9a8f94;text-decoration:underline;"', $footer_html);

    $font = "-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    ob_start();
    ?>
<div style="margin:0;padding:0;background-color:#faf6f1;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#faf6f1;">
  <tr>
    <td align="center" style="padding:32px 16px;">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

        <!-- accent rule -->
        <tr>
          <td style="height:4px;background-color:<?php echo esc_attr($accent); ?>;border-radius:4px 4px 0 0;font-size:0;line-height:0;">&nbsp;</td>
        </tr>

        <!-- card -->
        <tr>
          <td style="background-color:#ffffff;border:1px solid #efe6dd;border-top:0;border-radius:0 0 14px 14px;padding:0;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">

              <?php if ($logo) : ?>
              <tr>
                <td align="center" style="padding:34px 40px 6px;">
                  <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($site); ?>" width="<?php echo (int) $logo_w; ?>" style="display:block;max-width:<?php echo (int) $logo_w; ?>px;width:100%;height:auto;border:0;" />
                </td>
              </tr>
              <?php else : ?>
              <tr>
                <td align="center" style="padding:34px 40px 6px;font-family:<?php echo $font; ?>;font-size:22px;font-weight:700;color:#2f2a2c;letter-spacing:-0.01em;">
                  <?php echo esc_html($site); ?>
                </td>
              </tr>
              <?php endif; ?>

              <!-- content -->
              <tr>
                <td style="padding:26px 40px 8px;font-family:<?php echo $font; ?>;">
                  <?php echo $content; ?>
                </td>
              </tr>

              <!-- footer -->
              <tr>
                <td style="padding:8px 40px 30px;">
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr><td style="border-top:1px solid #f0e8e0;font-size:0;line-height:0;">&nbsp;</td></tr>
                    <tr>
                      <td style="padding-top:14px;font-family:<?php echo $font; ?>;font-size:12px;line-height:1.6;color:#9a8f94;">
                        <?php if ($note) : ?><?php echo esc_html($note); ?><br /><?php endif; ?>
                        <?php echo $footer_html; ?>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>

            </table>
          </td>
        </tr>

        <!-- sub-card sign-off -->
        <tr>
          <td align="center" style="padding:18px 8px 0;font-family:<?php echo $font; ?>;font-size:11px;color:#b9aeb3;">
            © <?php echo esc_html(date('Y') . ' ' . $site); ?>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</div>
    <?php
    return ob_get_clean();
}

/**
 * Wrap every http(s) link in the HTML with a click-tracker redirect and
 * append a 1x1 tracking pixel. Only touches HTML; text remains untouched.
 */
function lmeg_apply_tracking($html, $broadcast_id, $subscriber_id, $source = 'broadcast', $ref = 0) {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];

    // UTM campaign label reflects the source (broadcast-N, welcome, sequence-N).
    $campaign = $source === 'broadcast' ? 'broadcast-' . (int) $broadcast_id
              : ($source === 'sequence' ? 'sequence-' . (int) $ref : 'welcome');

    if (!empty($s['tracking_clicks'])) {
        $utm_source = sanitize_title($s['utm_source'] ?? '') ?: 'loonybin';
        $html = preg_replace_callback(
            '/href\s*=\s*(["\'])(https?:\/\/[^"\']+)\1/i',
            function ($m) use ($broadcast_id, $subscriber_id, $utm_source, $source, $ref, $campaign) {
                $url = $m[2];
                // Never wrap/alter functional, signed links — unsubscribe, our
                // own tracker, or a one-tap contest entry (its HMAC token must
                // arrive intact; extra redirects/params invite breakage).
                if (strpos($url, 'lmeg_unsubscribe=') !== false
                    || strpos($url, 'lmeg_track=') !== false
                    || strpos($url, 'lmeg_enter=') !== false
                    || strpos($url, 'lmeg_ce=') !== false) {
                    return $m[0];
                }
                $url = add_query_arg([
                    'utm_source'   => $utm_source,
                    'utm_medium'   => 'email',
                    'utm_campaign' => $campaign,
                ], html_entity_decode($url));
                return 'href=' . $m[1] . esc_url(lmeg_track_click_url($broadcast_id, $subscriber_id, $url, $source, $ref)) . $m[1];
            },
            $html
        );
    }

    if (!empty($s['tracking_opens'])) {
        $html .= '<img src="' . esc_url(lmeg_track_open_url($broadcast_id, $subscriber_id, $source, $ref)) . '" width="1" height="1" alt="" style="border:0;width:1px;height:1px;" />';
    }

    return $html;
}

/**
 * Distinct opens + clicks for a non-broadcast email source (welcome, sequence).
 * @return array ['opens'=>int, 'clicks'=>int]
 */
function lmeg_email_engagement($source, $ref = 0) {
    global $wpdb;
    $ev = $wpdb->prefix . 'lmeg_broadcast_events';
    $opens  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT subscriber_id) FROM $ev WHERE source=%s AND source_ref=%d AND event_type='open'",  $source, (int) $ref));
    $clicks = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT subscriber_id) FROM $ev WHERE source=%s AND source_ref=%d AND event_type='click'", $source, (int) $ref));
    return ['opens' => $opens, 'clicks' => $clicks];
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
        'radius_filter'  => null,   // ['km' => float, 'city' => string] — only fans within km of city
        'recipient_ids'  => null,   // explicit subscriber ids (resend flows) — skips the tag/radius filters
        'smart_timing'   => false,  // per-fan send_after at their most-active hour
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
    if ($has_email) $clauses[] = "(contact_type = 'email' AND email IS NOT NULL AND email <> '' AND email_status = 'ok' AND confirmed_at IS NOT NULL)";
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

    if (!empty($args['recipient_ids'])) {
        // Explicit audience (e.g. "resend to non-openers") — same channel +
        // unsubscribed + suppression guards, no tag/radius narrowing.
        $ids = array_filter(array_map('intval', (array) $args['recipient_ids']));
        $rows = $ids ? $wpdb->get_results(
            "SELECT id, contact_type, email, phone, city, region, country FROM $subs_tbl
              WHERE id IN (" . implode(',', $ids) . ") AND " . implode(' AND ', $where_parts)
        ) : [];
    } else {
        $sql  = "SELECT id, contact_type, email, phone, city, region, country FROM $subs_tbl WHERE " . implode(' AND ', $where_parts);
        $rows = $params
            ? $wpdb->get_results($wpdb->prepare($sql, $params))
            : $wpdb->get_results($sql);
    }

    if (!$rows) {
        return new WP_Error('lmeg_no_recipients', 'No matching subscribers (after excluding unsubscribed).');
    }

    // Radius filter — "only fans within X km of <city>". Applied in PHP after
    // the SQL narrowing: each fan's city (not the center) is geocoded once and
    // cached permanently, so repeat sends cost no lookups. Fans with no city
    // on file can't be placed and are excluded when a radius is set.
    if (!empty($args['radius_filter']['km']) && !empty($args['radius_filter']['city'])
        && function_exists('lmeg_geo_city_coords')) {
        $r_city = trim((string) $args['radius_filter']['city']);
        $r_km   = (float) $args['radius_filter']['km'];
        $center = lmeg_geo_city_coords($r_city, '', (string) ($args['radius_filter']['country'] ?? ''));
        if (!$center) {
            return new WP_Error('lmeg_geo_center', 'Could not locate "' . $r_city . '" on the map — check the spelling (add the country in Compose if it\'s ambiguous).');
        }
        $rows = array_values(array_filter($rows, function ($r) use ($center, $r_km) {
            if (empty($r->city)) return false;
            $c = lmeg_geo_city_coords($r->city, (string) ($r->region ?? ''), (string) ($r->country ?? ''));
            if (!$c) return false;
            return lmeg_geo_distance_km($center['lat'], $center['lng'], $c['lat'], $c['lng']) <= $r_km;
        }));
        if (!$rows) {
            return new WP_Error('lmeg_no_recipients', 'No subscribers with a city on file within ' . $r_km . ' km of ' . $r_city . '.');
        }
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

    // Smart send times: each fan's modal open HOUR (all-time) becomes their
    // send window — the send spreads over up to 24h. Fans with no open
    // history go out immediately.
    $send_after_by_sub = [];
    if (!empty($args['smart_timing'])) {
        $rid = array_map(function ($r) { return (int) $r->id; }, $rows);
        $ev  = $wpdb->prefix . 'lmeg_broadcast_events';
        $hrs = $rid ? $wpdb->get_results(
            "SELECT subscriber_id, HOUR(created_at) AS h, COUNT(*) AS n FROM $ev
              WHERE subscriber_id IN (" . implode(',', $rid) . ") AND event_type = 'open'
              GROUP BY subscriber_id, HOUR(created_at)"
        ) : [];
        $best = [];
        foreach ($hrs as $hr) {
            $sid = (int) $hr->subscriber_id;
            if (!isset($best[$sid]) || (int) $hr->n > $best[$sid][1]) $best[$sid] = [(int) $hr->h, (int) $hr->n];
        }
        $base_ts = $args['scheduled_for'] ? strtotime($args['scheduled_for']) : current_time('timestamp');
        foreach ($best as $sid => $bh) {
            $t = strtotime(date('Y-m-d', $base_ts) . ' ' . sprintf('%02d:00:00', $bh[0]));
            if ($t < $base_ts) $t += DAY_IN_SECONDS;
            $send_after_by_sub[$sid] = date('Y-m-d H:i:s', $t);
        }
    }

    foreach ($rows as $r) {
        $channel   = $r->contact_type === 'phone' ? 'sms' : 'email';
        $recipient = $channel === 'sms' ? $r->phone : $r->email;
        $wpdb->insert($log_tbl, [
            'broadcast_id'  => $bcast_id,
            'subscriber_id' => $r->id,
            'channel'       => $channel,
            'recipient'     => $recipient,
            'status'        => 'pending',
            'send_after'    => $send_after_by_sub[(int) $r->id] ?? null,
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
    global $wpdb;

    // Render merge tags so a TEST reflects the real send (previously it sent
    // the raw {contest_link}/{name}/… text). If the test recipient is a known
    // subscriber, use their record so {contest_link} etc. produce real, working
    // values; otherwise use a stand-in (personal tags fall back gracefully).
    $sub = null;
    if ($channel === 'email' && is_email($to)) {
        $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE email = %s", $to));
    } elseif ($channel === 'sms') {
        $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE phone = %s", $to));
    }
    if (!$sub) {
        $sub = (object) [
            'id' => 0,
            'email' => $channel === 'email' ? $to : '',
            'phone' => $channel === 'sms' ? $to : '',
            'first_name' => '',
        ];
    }
    if (function_exists('lmeg_render_merge_tags')) {
        $body    = lmeg_render_merge_tags((string) $body, $sub);
        $subject = lmeg_render_merge_tags((string) $subject, $sub);
    }

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
 * Per-tick batch size. Twilio Trial accounts cap throughput; Brevo handles
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

    // A batch of slow provider calls (20s timeout each) can exceed PHP's
    // default max_execution_time and kill the tick mid-loop, leaving rows
    // stuck in 'pending' forever. Ask for enough runway to finish the batch.
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    @ignore_user_abort(true);

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
        "SELECT * FROM $log_tbl WHERE broadcast_id = %d AND status = 'pending'
           AND (send_after IS NULL OR send_after <= %s)
         ORDER BY id ASC LIMIT %d",
        $bcast->id, current_time('mysql'), lmeg_batch_size()
    ));

    if (!$batch) {
        // Rows may still be waiting on their smart-timing window — not done yet.
        $waiting = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $log_tbl WHERE broadcast_id = %d AND status = 'pending'", $bcast->id
        ));
        if ($waiting > 0) return;
    }

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

/* ---------------------------------------------------------------------------
 * Deliverability — Brevo webhook (bounces / spam / blocks), suppression,
 * and double opt-in confirmation.
 * ------------------------------------------------------------------------- */

/** Secret-derived token that authenticates Brevo's webhook calls. */
function lmeg_brevo_wh_token() {
    return substr(hash_hmac('sha256', 'brevo-webhook', lmeg_get_secret()), 0, 20);
}

/** The URL to paste into Brevo → Transactional → Settings → Webhook. */
function lmeg_brevo_wh_url() {
    return add_query_arg('lmeg_brevo_wh', lmeg_brevo_wh_token(), home_url('/'));
}

add_action('init', 'lmeg_maybe_handle_brevo_webhook');
function lmeg_maybe_handle_brevo_webhook() {
    if (empty($_GET['lmeg_brevo_wh'])) return;
    if (!hash_equals(lmeg_brevo_wh_token(), (string) $_GET['lmeg_brevo_wh'])) { status_header(403); exit; }

    $d      = json_decode((string) file_get_contents('php://input'), true);
    $events = isset($d['event']) ? [$d] : (is_array($d) ? $d : []);
    global $wpdb;
    $subs = $wpdb->prefix . LMEG_TABLE;

    foreach ($events as $ev) {
        if (!is_array($ev)) continue;
        $type  = (string) ($ev['event'] ?? '');
        $email = sanitize_email((string) ($ev['email'] ?? ''));
        if (!$email || !$type) continue;
        $sub = $wpdb->get_row($wpdb->prepare("SELECT id FROM $subs WHERE email = %s", $email));
        if (!$sub) continue;

        $log = function ($kind, $detail) use ($wpdb, $sub) {
            $wpdb->insert($wpdb->prefix . 'lmeg_broadcast_events', [
                'broadcast_id' => 0, 'subscriber_id' => (int) $sub->id,
                'event_type'   => $kind, 'source' => 'brevo', 'source_ref' => 0,
                'url'          => substr($detail, 0, 500), 'ip' => null,
                'user_agent'   => 'brevo-webhook', 'created_at' => current_time('mysql'),
            ]);
        };

        if (in_array($type, ['hard_bounce', 'blocked', 'invalid_email', 'error'], true)) {
            // Dead address — suppress so it never burns sender reputation again.
            $wpdb->update($subs, ['email_status' => 'bounced', 'email_status_at' => current_time('mysql')], ['id' => (int) $sub->id]);
            $log('bounce', $type);
        } elseif (in_array($type, ['soft_bounce', 'deferred'], true)) {
            $log('bounce', 'soft:' . $type); // counted, not suppressed
        } elseif (in_array($type, ['spam', 'complaint'], true)) {
            // A complaint is a hard "never again": suppress AND unsubscribe.
            $wpdb->update($subs, [
                'email_status'    => 'spam',
                'email_status_at' => current_time('mysql'),
                'unsubscribed_at' => current_time('mysql'),
            ], ['id' => (int) $sub->id]);
            $log('spam', $type);
        } elseif ($type === 'unsubscribed') {
            $wpdb->update($subs, ['unsubscribed_at' => current_time('mysql')], ['id' => (int) $sub->id]);
        }
    }
    status_header(200);
    echo 'ok';
    exit;
}

/** Double opt-in confirmation email + signed one-tap confirm link. */
function lmeg_confirm_token($sub_id) {
    return substr(hash_hmac('sha256', 'confirm|' . (int) $sub_id, lmeg_get_secret()), 0, 24);
}

function lmeg_send_confirm_email($sub) {
    if (empty($sub->email)) return;
    $url  = add_query_arg('lmeg_confirm', (int) $sub->id . '-' . lmeg_confirm_token($sub->id), home_url('/'));
    $body = '<p style="font-size:16px;">One tap and you\'re in:</p>'
          . '<p style="margin:22px 0;"><a href="' . esc_url($url) . '" style="display:inline-block;padding:13px 30px;border-radius:8px;font-weight:600;text-decoration:none;color:#ffffff;background:#d05fa2;">Confirm my signup</a></p>'
          . '<p style="opacity:.7;">If you didn\'t sign up, just ignore this email.</p>';
    list($text, $html) = lmeg_build_email_with_footer($body, lmeg_unsub_url((int) $sub->id, $sub->email));
    lmeg_email_send($sub->email, 'Confirm your signup', $text, $html);
}

add_action('init', 'lmeg_maybe_handle_confirm');
function lmeg_maybe_handle_confirm() {
    if (empty($_GET['lmeg_confirm'])) return;
    $parts = explode('-', sanitize_text_field(wp_unslash($_GET['lmeg_confirm'])));
    if (count($parts) !== 2) return;
    $sid = (int) $parts[0];
    if (!$sid || !hash_equals(lmeg_confirm_token($sid), (string) $parts[1])) return;

    global $wpdb;
    $subs = $wpdb->prefix . LMEG_TABLE;
    $sub  = $wpdb->get_row($wpdb->prepare("SELECT * FROM $subs WHERE id = %d", $sid));
    if ($sub && empty($sub->confirmed_at)) {
        $wpdb->update($subs, ['confirmed_at' => current_time('mysql')], ['id' => $sid]);
        if (function_exists('lmeg_maybe_send_welcome')) lmeg_maybe_send_welcome($sid);
    }
    if (function_exists('lmeg_set_cookie')) lmeg_set_cookie();
    // 200 + client-side forward (never a 302 — mail scanners flag those).
    if (function_exists('lmeg_contest_forward_page')) lmeg_contest_forward_page(home_url('/'), null, false);
    wp_safe_redirect(home_url('/'));
    exit;
}

/* ---------------------------------------------------------------------------
 * Monday digest — the owner's weekly one-email overview. No dashboard visit
 * needed: growth, revenue, best send, top cities, plus an AI read when the
 * Anthropic key is configured.
 * ------------------------------------------------------------------------- */

add_action('lmeg_broadcast_tick', 'lmeg_weekly_digest_tick', 70);
function lmeg_weekly_digest_tick() {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    if (isset($s['digest_enabled']) && empty($s['digest_enabled'])) return;
    $now = current_time('timestamp');
    if ((int) date('N', $now) !== 1 || (int) date('G', $now) < 8) return; // Mondays from 8am site time
    $wk = date('o-W', $now);
    if (get_option('lmeg_digest_last') === $wk) return;
    update_option('lmeg_digest_last', $wk, false);
    lmeg_send_owner_digest();
}

function lmeg_send_owner_digest() {
    global $wpdb;
    $s     = lmeg_get_settings();
    $subs  = $wpdb->prefix . LMEG_TABLE;
    $since = date('Y-m-d H:i:s', current_time('timestamp') - 7 * DAY_IN_SECONDS);

    $new    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $subs WHERE created_at >= %s", $since));
    $unsub  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $subs WHERE unsubscribed_at >= %s", $since));
    $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE unsubscribed_at IS NULL");
    $rev    = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(total_cents),0) FROM {$wpdb->prefix}lmeg_shop_orders WHERE ordered_at >= %s", $since));
    $reva   = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(total_cents),0) FROM {$wpdb->prefix}lmeg_shop_orders WHERE ordered_at >= %s AND broadcast_id IS NOT NULL", $since));

    $best = $wpdb->get_row($wpdb->prepare(
        "SELECT b.id, b.subject, b.sent,
                (SELECT COUNT(DISTINCT subscriber_id) FROM {$wpdb->prefix}lmeg_broadcast_events e WHERE e.broadcast_id = b.id AND e.event_type = 'open') AS opens
         FROM {$wpdb->prefix}lmeg_broadcasts b
         WHERE b.completed_at >= %s AND b.sent > 0 ORDER BY (SELECT COUNT(DISTINCT subscriber_id) FROM {$wpdb->prefix}lmeg_broadcast_events e2 WHERE e2.broadcast_id = b.id AND e2.event_type='open') / b.sent DESC LIMIT 1",
        $since
    ));
    $cities = $wpdb->get_results($wpdb->prepare(
        "SELECT city, COUNT(*) n FROM $subs WHERE created_at >= %s AND city IS NOT NULL AND city <> '' GROUP BY city ORDER BY n DESC LIMIT 3", $since
    ));

    $fmt = function_exists('lmeg_format_price') ? 'lmeg_format_price' : function ($c) { return '$' . number_format($c / 100, 2); };
    $rows = [
        ['🌱', 'New fans', number_format_i18n($new) . ($unsub ? ' <span style="opacity:.6;">(−' . $unsub . ' unsubscribed)</span>' : '')],
        ['👥', 'Active list', number_format_i18n($active)],
        ['💸', 'Shop revenue (7d)', $fmt($rev) . ($reva ? ' <span style="opacity:.6;">(' . $fmt($reva) . ' email-attributed)</span>' : '')],
    ];
    if ($best) $rows[] = ['🏅', 'Best send', esc_html($best->subject ?: ('#' . $best->id)) . ' — ' . round(($best->opens / max(1, $best->sent)) * 100) . '% opens'];
    if ($cities) $rows[] = ['📍', 'New fans from', esc_html(implode(', ', array_map(function ($c) { return $c->city . ' (' . $c->n . ')'; }, $cities)))];

    $body = '<h2 style="margin:0 0 14px;">Your week</h2><table style="border-collapse:collapse;">';
    foreach ($rows as $r) {
        $body .= '<tr><td style="padding:6px 10px 6px 0;font-size:18px;">' . $r[0] . '</td>'
               . '<td style="padding:6px 18px 6px 0;color:#777;white-space:nowrap;">' . $r[1] . '</td>'
               . '<td style="padding:6px 0;font-weight:600;">' . $r[2] . '</td></tr>';
    }
    $body .= '</table>';

    // AI read — best-effort, never blocks the digest.
    if (function_exists('lmeg_ai_ask')) {
        $ai = lmeg_ai_ask('Write a 2-3 sentence Monday morning read on how the last 7 days went and the single most useful action to take this week. Plain text, no greeting.');
        if (!is_wp_error($ai) && is_string($ai) && trim($ai) !== '') {
            $body .= '<p style="margin:18px 0 0;padding:12px 16px;background:#faf3f8;border-left:3px solid #d05fa2;font-style:italic;">' . esc_html(trim($ai)) . '</p>';
        }
    }
    $body .= '<p style="margin:16px 0 0;"><a href="' . esc_url(admin_url('admin.php?page=lmeg-overview')) . '">Open the dashboard →</a></p>';

    $to  = !empty($s['digest_email']) && is_email($s['digest_email']) ? $s['digest_email'] : get_option('admin_email');
    $ph  = add_query_arg(['lmeg_unsubscribe' => 1, 'u' => 0, 't' => 'digest'], home_url('/'));
    list($text, $html) = lmeg_build_email_with_footer($body, $ph);
    lmeg_email_send($to, sprintf('Your week: +%d fans, %s revenue', $new, $fmt($rev)), $text, $html);
}
