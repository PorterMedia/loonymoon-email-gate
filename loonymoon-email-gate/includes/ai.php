<?php
/**
 * AI assistant — ask questions about your fanbase in plain English.
 *
 * A compact snapshot of the plugin's OWN first-party data (subscriber
 * counts, fan types, countries, revenue, recent broadcast performance,
 * Spotify stats) is assembled server-side and sent to Anthropic's Messages
 * API alongside the question. The model answers grounded in that snapshot —
 * it never sees raw PII beyond aggregate stats, and it can't act, only advise.
 */

if (!defined('ABSPATH')) {
    exit;
}

const LMEG_AI_ENDPOINT = 'https://api.anthropic.com/v1/messages';

function lmeg_ai_configured() {
    $s = lmeg_get_settings();
    return !empty($s['ai_api_key']);
}

/**
 * Build the grounding snapshot. Aggregate numbers only — no email lists.
 */
function lmeg_ai_context() {
    global $wpdb;
    $subs = $wpdb->prefix . LMEG_TABLE;
    $ctx  = [];

    $total  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE unsubscribed_at IS NULL");
    $unsub  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE unsubscribed_at IS NOT NULL");
    $d30    = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $email_n= (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE contact_type='email' AND unsubscribed_at IS NULL");
    $sms_n  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE contact_type='phone' AND unsubscribed_at IS NULL");
    $ctx[] = "Subscribers: $total active ($email_n email, $sms_n SMS), $unsub unsubscribed, +$d30 in last 30 days.";

    // Fan types.
    $types = $wpdb->get_results(
        "SELECT t.name, COUNT(st.subscriber_id) n FROM {$wpdb->prefix}lmeg_tags t
         JOIN {$wpdb->prefix}lmeg_subscriber_tags st ON st.tag_id=t.id
         WHERE t.slug LIKE 'fan-type:%' GROUP BY t.id ORDER BY n DESC"
    );
    if ($types) {
        $parts = [];
        foreach ($types as $t) $parts[] = str_replace('Fan type: ', '', $t->name) . ': ' . (int) $t->n;
        $ctx[] = 'Fan types — ' . implode(', ', $parts) . '.';
    }

    // Top countries.
    $countries = $wpdb->get_results(
        "SELECT country, COUNT(*) n FROM $subs WHERE country<>'' AND country IS NOT NULL AND unsubscribed_at IS NULL
         GROUP BY country ORDER BY n DESC LIMIT 6"
    );
    if ($countries) {
        $parts = [];
        foreach ($countries as $c) $parts[] = $c->country . ': ' . (int) $c->n;
        $ctx[] = 'Top countries — ' . implode(', ', $parts) . '.';
    }

    // Paid / revenue.
    if (function_exists('lmeg_shop_totals')) {
        $t30 = lmeg_shop_totals(30);
        if ($t30) {
            $ctx[] = 'Shop (30d): total ' . lmeg_format_price((int) $t30->all_cents)
                   . ', campaign-attributed ' . lmeg_format_price((int) $t30->campaign_cents)
                   . ' from ' . (int) $t30->campaign_orders . ' orders.';
        }
    }
    $paying = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE member_status='active' AND member_tier_id IS NOT NULL");
    if ($paying) $ctx[] = "Paying members: $paying.";

    // Recent broadcasts w/ engagement.
    $bcasts = $wpdb->get_results(
        "SELECT b.id, b.subject, b.sent, b.created_at,
                (SELECT COUNT(DISTINCT subscriber_id) FROM {$wpdb->prefix}lmeg_broadcast_events e WHERE e.broadcast_id=b.id AND e.event_type='open') opens,
                (SELECT COUNT(DISTINCT subscriber_id) FROM {$wpdb->prefix}lmeg_broadcast_events e WHERE e.broadcast_id=b.id AND e.event_type='click') clicks
         FROM {$wpdb->prefix}lmeg_broadcasts b WHERE b.status='completed' ORDER BY b.id DESC LIMIT 5"
    );
    if ($bcasts) {
        $lines = [];
        foreach ($bcasts as $b) {
            $sent = max(1, (int) $b->sent);
            $lines[] = '"' . ($b->subject ?: 'broadcast') . '" — ' . (int) $b->sent . ' sent, '
                     . round(100 * $b->opens / $sent) . '% open, ' . round(100 * $b->clicks / $sent) . '% click';
        }
        $ctx[] = "Recent broadcasts:\n- " . implode("\n- ", $lines);
    }

    // Spotify.
    if (function_exists('lmeg_spotify_configured') && lmeg_spotify_configured()) {
        $ov = lmeg_spotify_overview();
        if (!is_wp_error($ov)) {
            $tt = array_map(function ($t) { return $t['name']; }, array_slice($ov['top_tracks'], 0, 3));
            $ctx[] = 'Spotify: ' . number_format($ov['followers']) . ' followers, popularity ' . $ov['popularity'] . '/100'
                   . ($tt ? '. Top tracks: ' . implode(', ', $tt) . '.' : '.');
        }
        // Initiative → Spotify impact correlation.
        if (function_exists('lmeg_impact_ai_summary')) {
            $imp = lmeg_impact_ai_summary();
            if ($imp) $ctx[] = $imp;
        }
    }

    return implode("\n", $ctx);
}

/**
 * Ask the model a question. Returns the answer text or WP_Error.
 */
function lmeg_ai_ask($question) {
    $s = lmeg_get_settings();
    if (empty($s['ai_api_key'])) return new WP_Error('lmeg_ai_unconfigured', 'Add your Anthropic API key in Settings → AI assistant.');
    $question = trim(wp_strip_all_tags((string) $question));
    if ($question === '') return new WP_Error('lmeg_ai_empty', 'Ask a question first.');

    $context = lmeg_ai_context();
    $model   = $s['ai_model'] ?: 'claude-haiku-4-5-20251001';

    $system = "You are the analytics assistant inside a musician's fan-CRM WordPress plugin (\"loonybin\", for the artist LOONY). "
            . "Answer the user's question using ONLY the DATA SNAPSHOT below. Be concrete, cite the numbers, and when useful give one actionable next step (e.g. which segment to email, when to send). "
            . "If the snapshot doesn't contain the answer, say so plainly and suggest what to enable or track. Keep it tight — a few sentences or a short list. Never invent numbers.\n\n"
            . "DATA SNAPSHOT:\n" . $context;

    $resp = wp_remote_post(LMEG_AI_ENDPOINT, [
        'timeout' => 45,
        'headers' => [
            'x-api-key'         => $s['ai_api_key'],
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ],
        'body' => wp_json_encode([
            'model'      => $model,
            'max_tokens' => 700,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $question]],
        ]),
    ]);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    $d    = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code !== 200) {
        return new WP_Error('lmeg_ai_http_' . $code, $d['error']['message'] ?? ('HTTP ' . $code));
    }
    $text = '';
    foreach ((array) ($d['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    return $text !== '' ? $text : new WP_Error('lmeg_ai_empty_resp', 'The model returned no text.');
}

function lmeg_ai_verify() {
    $r = lmeg_ai_ask('Reply with just the word: connected.');
    if (is_wp_error($r)) return $r;
    return 'AI connected — model responded.';
}

/* ---------------------------------------------------------------------------
 * AJAX + admin page
 * ------------------------------------------------------------------------- */

add_action('wp_ajax_lmeg_ai_ask', 'lmeg_ajax_ai_ask');
function lmeg_ajax_ai_ask() {
    if (!current_user_can('manage_options')) wp_send_json_error(['msg' => 'forbidden'], 403);
    check_ajax_referer('lmeg_ai', 'nonce');
    $r = lmeg_ai_ask($_POST['q'] ?? '');
    if (is_wp_error($r)) wp_send_json_error(['msg' => $r->get_error_message()]);
    wp_send_json_success(['answer' => $r]);
}

add_action('admin_menu', function () {
    add_submenu_page('lmeg', 'Ask AI', 'Ask AI', 'manage_options', 'lmeg-ai', 'lmeg_admin_ai');
}, 20);

function lmeg_admin_ai() {
    if (!current_user_can('manage_options')) return;
    $ready = lmeg_ai_configured();
    $nonce = wp_create_nonce('lmeg_ai');
    $suggestions = [
        'How is my list growing and where are most of my fans?',
        'Which broadcast performed best and why?',
        'How do my drops and broadcasts impact Spotify?',
        'Who should I target for my next presale?',
        'What should I do to re-engage dormant fans?',
        'Summarize my fanbase in 3 bullet points.',
    ];
    ?>
    <div class="wrap">
        <h1>Email Gate — Ask AI</h1>

        <?php if (!$ready) : ?>
            <div class="notice notice-info"><p>Add your Anthropic API key under <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings')); ?>">Settings → AI assistant</a> to enable this. Get one at <a href="https://console.anthropic.com" target="_blank" rel="noopener">console.anthropic.com</a>.</p></div>
        <?php else : ?>
            <p style="max-width:720px;opacity:.85;">Ask anything about your fanbase — the assistant answers from your live plugin data (subscribers, fan types, countries, revenue, broadcast performance, Spotify).</p>

            <div style="display:flex;flex-wrap:wrap;gap:6px;margin:12px 0;">
                <?php foreach ($suggestions as $q) : ?>
                    <button type="button" class="button lmeg-ai-suggest"><?php echo esc_html($q); ?></button>
                <?php endforeach; ?>
            </div>

            <div id="lmeg-ai-thread" style="max-width:820px;margin:14px 0;display:flex;flex-direction:column;gap:12px;"></div>

            <form id="lmeg-ai-form" style="max-width:820px;display:flex;gap:8px;">
                <input type="text" id="lmeg-ai-q" class="regular-text" style="flex:1;" placeholder="Ask about your fans…" autocomplete="off" />
                <button type="submit" class="button button-primary" id="lmeg-ai-send">Ask</button>
            </form>

            <script>
            (function(){
                var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                var nonce = <?php echo wp_json_encode($nonce); ?>;
                var thread = document.getElementById('lmeg-ai-thread');
                var form = document.getElementById('lmeg-ai-form');
                var input = document.getElementById('lmeg-ai-q');
                var send = document.getElementById('lmeg-ai-send');

                function bubble(role, text){
                    var d = document.createElement('div');
                    d.style.cssText = 'padding:12px 15px;border-radius:12px;white-space:pre-wrap;line-height:1.55;font-size:14px;'
                        + (role==='you'
                            ? 'align-self:flex-end;max-width:80%;background:linear-gradient(135deg,#d05fa2,#a855f7);color:#fff;'
                            : 'align-self:flex-start;max-width:88%;background:#1C1F2E;color:#F4F5F7;border:1px solid rgba(255,255,255,.08);');
                    d.textContent = text;
                    thread.appendChild(d);
                    d.scrollIntoView({behavior:'smooth',block:'nearest'});
                    return d;
                }

                function ask(q){
                    if(!q.trim()) return;
                    bubble('you', q);
                    input.value=''; send.disabled=true; send.textContent='Thinking…';
                    var loading = bubble('ai','…');
                    var fd = new FormData();
                    fd.append('action','lmeg_ai_ask'); fd.append('nonce',nonce); fd.append('q',q);
                    fetch(ajax,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
                        loading.textContent = (d && d.success) ? d.data.answer : ('⚠ ' + ((d&&d.data&&d.data.msg)||'error'));
                    }).catch(function(){ loading.textContent='⚠ network error'; })
                    .finally(function(){ send.disabled=false; send.textContent='Ask'; });
                }

                form.addEventListener('submit', function(e){ e.preventDefault(); ask(input.value); });
                document.querySelectorAll('.lmeg-ai-suggest').forEach(function(b){
                    b.addEventListener('click', function(){ ask(b.textContent); });
                });
            })();
            </script>
        <?php endif; ?>
    </div>
    <?php
}
