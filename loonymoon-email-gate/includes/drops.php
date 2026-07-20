<?php
/**
 * Release Drops — countdown pages for upcoming releases with a "Notify me"
 * capture, then streaming links once live. When the release moment passes, a
 * broadcast is auto-queued to everyone who asked to be notified (or all subs).
 *
 * This is the owned-audience replacement for Spotify pre-saves (shelved — the
 * 250k-MAU wall): same fan behavior, but the contact is captured here and the
 * artist controls the release-day message.
 *
 * Public: [loony_drop]                 — newest scheduled/released drop
 *         [loony_drop slug="new-single"]
 *         [loony_drop id="3"]
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------------------------
 * Data helpers
 * ------------------------------------------------------------------------- */

function lmeg_drops_table() {
    global $wpdb;
    return $wpdb->prefix . 'lmeg_drops';
}

function lmeg_drops_all($limit = 200) {
    global $wpdb;
    $t = lmeg_drops_table();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $t ORDER BY COALESCE(release_at, created_at) DESC LIMIT %d", $limit
    ));
}

function lmeg_drop_get($id_or_slug) {
    global $wpdb;
    $t = lmeg_drops_table();
    if (ctype_digit((string) $id_or_slug)) {
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", (int) $id_or_slug));
    }
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE slug = %s", sanitize_title($id_or_slug)));
}

/** Newest drop that is meant to be shown (scheduled or released). */
function lmeg_drop_current() {
    global $wpdb;
    $t = lmeg_drops_table();
    return $wpdb->get_row(
        "SELECT * FROM $t WHERE status IN ('scheduled','released')
         ORDER BY COALESCE(release_at, created_at) DESC LIMIT 1"
    );
}

/** Parse the "Label | URL" textarea into [['label','url'], ...]. */
function lmeg_drop_parse_links($raw) {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strpos($line, '|') !== false) {
            list($label, $url) = array_map('trim', explode('|', $line, 2));
        } else {
            $label = $line; $url = $line;
        }
        $url = esc_url_raw($url);
        if ($url) $out[] = ['label' => $label ?: $url, 'url' => $url];
    }
    return $out;
}

function lmeg_drop_links($drop) {
    $links = json_decode((string) $drop->links, true);
    return is_array($links) ? $links : [];
}

function lmeg_drop_url($drop) {
    $page = lmeg_get_settings()['drops_page_url'] ?? '';
    if ($page) return add_query_arg('drop', $drop->slug, $page);
    return home_url('/?drop=' . rawurlencode($drop->slug));
}

/* ---------------------------------------------------------------------------
 * Admin — list + edit
 * ------------------------------------------------------------------------- */

function lmeg_admin_drops() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;

    $editing = isset($_GET['edit']) ? lmeg_drop_get((int) $_GET['edit']) : null;
    $creating = isset($_GET['new']);

    echo '<div class="wrap lmeg-wrap"><h1>Release Drops</h1>';
    echo '<p class="description" style="max-width:60em;">Build a countdown page for an upcoming release. Fans tap <strong>Notify me</strong> to opt in; the moment the release time passes, everyone who signed up gets an automatic email/SMS with your streaming links. Embed a drop anywhere with <code>[loony_drop]</code> (newest) or <code>[loony_drop slug="&hellip;"]</code>.</p>';

    if ($editing || $creating) {
        lmeg_admin_drop_form($editing);
        echo '</div>';
        return;
    }

    echo '<p><a href="' . esc_url(admin_url('admin.php?page=lmeg-drops&new=1')) . '" class="button button-primary">+ New drop</a></p>';

    $drops = lmeg_drops_all();
    if (!$drops) {
        echo '<p>No drops yet. Create one to start a countdown.</p></div>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>'
       . '<th>Title</th><th>Status</th><th>Release</th><th>Notify sign-ups</th><th>Broadcast</th><th>Shortcode</th><th></th>'
       . '</tr></thead><tbody>';
    foreach ($drops as $d) {
        $when = $d->release_at ? esc_html(get_date_from_gmt(get_gmt_from_date($d->release_at), 'M j, Y g:i a')) : '—';
        $status_label = ucfirst($d->status);
        if ($d->status === 'scheduled' && $d->release_at && strtotime($d->release_at) <= current_time('timestamp')) {
            $status_label = 'Releasing…';
        }
        $bcast = $d->broadcast_sent
            ? '<span style="color:#10b981;">Sent'. ($d->broadcast_id ? ' (#'.(int)$d->broadcast_id.')' : '') .'</span>'
            : ($d->status === 'released' ? '—' : 'Pending');
        echo '<tr>'
           . '<td><strong>' . esc_html($d->title) . '</strong><br><span class="description">' . esc_html($d->slug) . '</span></td>'
           . '<td>' . esc_html($status_label) . '</td>'
           . '<td>' . $when . '</td>'
           . '<td>' . number_format_i18n((int) $d->notify_count) . '</td>'
           . '<td>' . $bcast . '</td>'
           . '<td><code>[loony_drop slug="' . esc_attr($d->slug) . '"]</code></td>'
           . '<td><a href="' . esc_url(admin_url('admin.php?page=lmeg-drops&edit=' . (int) $d->id)) . '">Edit</a>'
           . ' &middot; <a href="' . esc_url(lmeg_drop_url($d)) . '" target="_blank">View</a></td>'
           . '</tr>';
    }
    echo '</tbody></table></div>';
}

function lmeg_admin_drop_form($drop) {
    $is_new = !$drop;
    $links_text = '';
    if (!$is_new) {
        foreach (lmeg_drop_links($drop) as $l) {
            $links_text .= $l['label'] . ' | ' . $l['url'] . "\n";
        }
    }
    $release_local = '';
    if (!$is_new && $drop->release_at) {
        // datetime-local wants "Y-m-dTH:i" in the site's wall-clock time.
        $release_local = date('Y-m-d\TH:i', strtotime($drop->release_at));
    }
    $default_email = "The wait is over — it's out now.\n\nListen everywhere:\n{links}\n\nThank you for being here.\nLOONY";
    $default_sms   = "It's out now 💫 Listen: {links}";

    $val = function ($field, $fallback = '') use ($drop, $is_new) {
        if ($is_new) return $fallback;
        return $drop->$field !== null ? $drop->$field : $fallback;
    };

    echo '<h2>' . ($is_new ? 'New drop' : 'Edit: ' . esc_html($drop->title)) . '</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('lmeg_save_drop');
    echo '<input type="hidden" name="action" value="lmeg_save_drop" />';
    if (!$is_new) echo '<input type="hidden" name="drop_id" value="' . (int) $drop->id . '" />';

    echo '<table class="form-table" role="presentation">';

    echo '<tr><th><label for="d_title">Title</label></th><td>'
       . '<input name="title" id="d_title" class="regular-text" required value="' . esc_attr($val('title')) . '" placeholder="New single — &ldquo;Moonchild&rdquo;" /></td></tr>';

    echo '<tr><th><label for="d_cover">Cover image URL</label></th><td>'
       . '<input name="cover_url" id="d_cover" class="regular-text" value="' . esc_attr($val('cover_url')) . '" placeholder="https://…/cover.jpg" />'
       . '<p class="description">Square art looks best. Paste a Media Library URL.</p></td></tr>';

    echo '<tr><th><label for="d_desc">Description</label></th><td>'
       . '<textarea name="description" id="d_desc" rows="3" class="large-text" placeholder="A one-line tease for the release.">' . esc_textarea($val('description')) . '</textarea></td></tr>';

    echo '<tr><th><label for="d_release">Release date &amp; time</label></th><td>'
       . '<input type="datetime-local" name="release_at" id="d_release" value="' . esc_attr($release_local) . '" />'
       . '<p class="description">Site timezone. The countdown runs to this moment, then the auto-broadcast fires within ~a minute.</p></td></tr>';

    echo '<tr><th><label for="d_links">Streaming links</label></th><td>'
       . '<textarea name="links" id="d_links" rows="5" class="large-text code" placeholder="Spotify | https://open.spotify.com/…&#10;Apple Music | https://music.apple.com/…&#10;YouTube | https://youtu.be/…">' . esc_textarea($links_text) . '</textarea>'
       . '<p class="description">One per line, <code>Label | URL</code>. Shown as buttons after release, and dropped into the broadcast wherever you put <code>{links}</code>.</p></td></tr>';

    $audience = $val('audience', 'notify');
    echo '<tr><th>Release broadcast goes to</th><td>'
       . '<label style="margin-right:1.5em;"><input type="radio" name="audience" value="notify" ' . checked($audience, 'notify', false) . ' /> Only fans who tapped &ldquo;Notify me&rdquo;</label>'
       . '<label><input type="radio" name="audience" value="all" ' . checked($audience, 'all', false) . ' /> All subscribers</label></td></tr>';

    echo '<tr><th><label for="d_subject">Release email subject</label></th><td>'
       . '<input name="email_subject" id="d_subject" class="regular-text" value="' . esc_attr($val('email_subject', 'It\'s out now')) . '" /></td></tr>';

    echo '<tr><th><label for="d_ebody">Release email body</label></th><td>'
       . '<textarea name="email_body" id="d_ebody" rows="7" class="large-text">' . esc_textarea($val('email_body', $default_email)) . '</textarea>'
       . '<p class="description">Use <code>{links}</code> for the streaming buttons and <code>{name}</code> for the fan&rsquo;s name. Wrapped in your branded template on send.</p></td></tr>';

    echo '<tr><th><label for="d_sbody">Release SMS body</label></th><td>'
       . '<textarea name="sms_body" id="d_sbody" rows="3" class="large-text">' . esc_textarea($val('sms_body', $default_sms)) . '</textarea>'
       . '<p class="description">Leave blank to skip SMS. <code>{links}</code> becomes plain <code>Label: url</code> lines.</p></td></tr>';

    echo '</table>';

    echo '<p>';
    echo '<button type="submit" name="save_mode" value="scheduled" class="button button-primary">' . ($is_new ? 'Create &amp; schedule' : 'Save &amp; schedule') . '</button> ';
    echo '<button type="submit" name="save_mode" value="draft" class="button">Save as draft</button> ';
    echo '<a href="' . esc_url(admin_url('admin.php?page=lmeg-drops')) . '" class="button-link" style="margin-left:1em;">Cancel</a>';
    echo '</p>';
    echo '<p class="description">Scheduled drops with a release time in the past will auto-send on the next minute tick. Draft drops never send and show a plain preview.</p>';

    if (!$is_new) {
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Delete this drop? This cannot be undone.\');" style="margin-top:1em;">';
        wp_nonce_field('lmeg_delete_drop');
        echo '<input type="hidden" name="action" value="lmeg_delete_drop" />';
        echo '<input type="hidden" name="drop_id" value="' . (int) $drop->id . '" />';
        echo '<button type="submit" class="button-link-delete" style="color:#b32d2e;">Delete drop</button>';
    }
    echo '</form>';
}

/* ---------------------------------------------------------------------------
 * Admin — save / delete
 * ------------------------------------------------------------------------- */

add_action('admin_post_lmeg_save_drop', 'lmeg_handle_save_drop');
function lmeg_handle_save_drop() {
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
    check_admin_referer('lmeg_save_drop');
    global $wpdb;
    $t = lmeg_drops_table();

    $drop_id = isset($_POST['drop_id']) ? (int) $_POST['drop_id'] : 0;
    $title   = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    if ($title === '') {
        wp_die('A title is required.', 'Missing title', ['back_link' => true]);
    }

    // Stable, unique slug.
    $base = sanitize_title($title);
    if ($base === '') $base = 'drop';
    $slug = $base;
    $existing_slug = $drop_id ? $wpdb->get_var($wpdb->prepare("SELECT slug FROM $t WHERE id = %d", $drop_id)) : '';
    if ($slug !== $existing_slug) {
        $i = 2;
        while ($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE slug = %s AND id <> %d", $slug, $drop_id))) {
            $slug = $base . '-' . $i++;
        }
    }

    $links = lmeg_drop_parse_links(wp_unslash($_POST['links'] ?? ''));

    // datetime-local arrives as wall-clock in the site tz; store as-is (MySQL).
    $release_at = null;
    if (!empty($_POST['release_at'])) {
        $ts = strtotime(sanitize_text_field(wp_unslash($_POST['release_at'])));
        if ($ts) $release_at = date('Y-m-d H:i:s', $ts);
    }

    $mode   = ($_POST['save_mode'] ?? 'scheduled') === 'draft' ? 'draft' : 'scheduled';

    // Per-drop notify tag so "Notify me" opt-ins are targetable.
    $notify_tag_id = null;
    if (function_exists('lmeg_get_or_create_tag')) {
        $tag = lmeg_get_or_create_tag('drop:' . $slug, 'Drop: ' . $title, true, '#d05fa2');
        if ($tag) $notify_tag_id = (int) $tag->id;
    }

    $data = [
        'title'         => $title,
        'slug'          => $slug,
        'cover_url'     => esc_url_raw(wp_unslash($_POST['cover_url'] ?? '')) ?: null,
        'description'   => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')) ?: null,
        'release_at'    => $release_at,
        'links'         => wp_json_encode($links),
        'notify_tag_id' => $notify_tag_id,
        'audience'      => ($_POST['audience'] ?? 'notify') === 'all' ? 'all' : 'notify',
        'email_subject' => sanitize_text_field(wp_unslash($_POST['email_subject'] ?? '')) ?: null,
        'email_body'    => wp_kses_post(wp_unslash($_POST['email_body'] ?? '')) ?: null,
        'sms_body'      => sanitize_textarea_field(wp_unslash($_POST['sms_body'] ?? '')) ?: null,
        'status'        => $mode,
    ];

    if ($drop_id) {
        // Never resurrect an already-sent drop back to "scheduled" in a way
        // that would re-broadcast — broadcast_sent stays whatever it was.
        $wpdb->update($t, $data, ['id' => $drop_id]);
    } else {
        $data['broadcast_sent'] = 0;
        $data['notify_count']   = 0;
        $data['created_at']     = current_time('mysql');
        $wpdb->insert($t, $data);
        $drop_id = (int) $wpdb->insert_id;
    }

    wp_safe_redirect(admin_url('admin.php?page=lmeg-drops&edit=' . $drop_id . '&saved=1'));
    exit;
}

add_action('admin_post_lmeg_delete_drop', 'lmeg_handle_delete_drop');
function lmeg_handle_delete_drop() {
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
    check_admin_referer('lmeg_delete_drop');
    global $wpdb;
    $drop_id = (int) ($_POST['drop_id'] ?? 0);
    if ($drop_id) $wpdb->delete(lmeg_drops_table(), ['id' => $drop_id]);
    wp_safe_redirect(admin_url('admin.php?page=lmeg-drops&deleted=1'));
    exit;
}

/* ---------------------------------------------------------------------------
 * Auto-broadcast on release — piggybacks the minute broadcast tick.
 * ------------------------------------------------------------------------- */

add_action('lmeg_broadcast_tick', 'lmeg_process_drops_tick', 30);
function lmeg_process_drops_tick() {
    global $wpdb;
    $t = lmeg_drops_table();
    $now = current_time('mysql');

    $due = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $t
          WHERE status = 'scheduled' AND broadcast_sent = 0
            AND release_at IS NOT NULL AND release_at <= %s
          LIMIT 5",
        $now
    ));
    if (!$due) return;

    foreach ($due as $drop) {
        $links = lmeg_drop_links($drop);

        // Build channel bodies with {links} expanded.
        $email_body = (string) $drop->email_body;
        $sms_body   = (string) $drop->sms_body;

        $email_links_html = '';
        foreach ($links as $l) {
            $email_links_html .= '<a href="' . esc_url($l['url']) . '">' . esc_html($l['label']) . '</a><br>';
        }
        $sms_links = implode(' ', array_map(function ($l) { return $l['url']; }, $links));

        $email_body = lmeg_drop_expand_links($email_body, $email_links_html);
        $sms_body   = lmeg_drop_expand_links($sms_body, $sms_links);

        $args = [
            'subject'    => $drop->email_subject ?: ('Out now: ' . $drop->title),
            'body_email' => trim($email_body) !== '' ? $email_body : '',
            'body_sms'   => trim($sms_body)   !== '' ? $sms_body   : '',
        ];
        if ($drop->audience === 'notify' && $drop->notify_tag_id) {
            $args['tag_filter'] = ['tag_ids' => [(int) $drop->notify_tag_id], 'match' => 'any'];
        }

        $bcast_id = null;
        if ($args['body_email'] !== '' || $args['body_sms'] !== '') {
            $res = lmeg_queue_broadcast($args);
            if (!is_wp_error($res)) $bcast_id = (int) $res;
            // If there were simply no recipients yet, we still mark released so
            // the page flips to streaming links; the broadcast just no-ops.
        }

        $wpdb->update($t, [
            'status'         => 'released',
            'broadcast_sent' => 1,
            'broadcast_id'   => $bcast_id,
        ], ['id' => $drop->id]);
    }
}

function lmeg_drop_expand_links($body, $replacement) {
    if (strpos($body, '{links}') !== false) {
        return str_replace('{links}', $replacement, $body);
    }
    $body = rtrim($body);
    return $body === '' ? $replacement : $body . "\n\n" . $replacement;
}

/* ---------------------------------------------------------------------------
 * Public shortcode — [loony_drop]
 * ------------------------------------------------------------------------- */

add_shortcode('loony_drop', 'lmeg_shortcode_drop');
function lmeg_shortcode_drop($atts = []) {
    $atts = shortcode_atts(['id' => '', 'slug' => ''], $atts, 'loony_drop');

    if ($atts['id'])       $drop = lmeg_drop_get((int) $atts['id']);
    elseif ($atts['slug']) $drop = lmeg_drop_get($atts['slug']);
    elseif (!empty($_GET['drop'])) $drop = lmeg_drop_get(sanitize_title(wp_unslash($_GET['drop'])));
    else                   $drop = lmeg_drop_current();

    if (!$drop) return '<div class="lmeg-drop lmeg-drop--empty">No release to show yet.</div>';

    $s        = lmeg_get_settings();
    $accent   = sanitize_hex_color($s['color_primary'] ?? '') ?: '#d05fa2';
    $released = ($drop->status === 'released') || (!$drop->release_at) ||
                (strtotime($drop->release_at) <= current_time('timestamp'));
    // A scheduled-but-past drop whose broadcast hasn't run still shows as live.
    $links    = lmeg_drop_links($drop);
    $inst     = 'lmeg-drop-' . (int) $drop->id;

    $success  = (!empty($_GET['lmeg_signup']) && $_GET['lmeg_signup'] === 'ok');

    ob_start();
    ?>
    <div id="<?php echo esc_attr($inst); ?>" class="lmeg-drop" style="--lmeg-accent:<?php echo esc_attr($accent); ?>;">
        <?php if ($drop->cover_url) : ?>
            <div class="lmeg-drop__cover"><img src="<?php echo esc_url($drop->cover_url); ?>" alt="<?php echo esc_attr($drop->title); ?>" loading="lazy" /></div>
        <?php endif; ?>
        <div class="lmeg-drop__body">
            <div class="lmeg-drop__eyebrow"><?php echo $released ? 'Out now' : 'Coming soon'; ?></div>
            <h2 class="lmeg-drop__title"><?php echo esc_html($drop->title); ?></h2>
            <?php if ($drop->description) : ?>
                <p class="lmeg-drop__desc"><?php echo esc_html($drop->description); ?></p>
            <?php endif; ?>

            <?php if (!$released) :
                // Emit the release moment in UTC (…Z) so the countdown is
                // accurate for every viewer, whatever timezone they're in.
                $release_iso = get_gmt_from_date($drop->release_at, 'Y-m-d\TH:i:s') . 'Z'; ?>
                <div class="lmeg-drop__countdown" data-release="<?php echo esc_attr($release_iso); ?>" aria-live="polite">
                    <div class="lmeg-cd"><span class="lmeg-cd__n" data-u="d">0</span><span class="lmeg-cd__l">days</span></div>
                    <div class="lmeg-cd"><span class="lmeg-cd__n" data-u="h">0</span><span class="lmeg-cd__l">hrs</span></div>
                    <div class="lmeg-cd"><span class="lmeg-cd__n" data-u="m">0</span><span class="lmeg-cd__l">min</span></div>
                    <div class="lmeg-cd"><span class="lmeg-cd__n" data-u="s">0</span><span class="lmeg-cd__l">sec</span></div>
                </div>

                <?php if ($success) : ?>
                    <div class="lmeg-drop__ok" role="status">✓ You&rsquo;re on the list — we&rsquo;ll ping you the second it drops.</div>
                <?php else : ?>
                    <?php echo lmeg_drop_notify_form($drop); ?>
                <?php endif; ?>
            <?php else : ?>
                <?php if ($links) : ?>
                    <div class="lmeg-drop__links">
                        <?php foreach ($links as $l) : ?>
                            <a class="lmeg-drop__link" href="<?php echo esc_url($l['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($l['label']); ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <p class="lmeg-drop__desc">Streaming links coming momentarily.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
    echo lmeg_drop_styles();
    if (!$released) echo lmeg_drop_countdown_js();
    return ob_get_clean();
}

function lmeg_drop_notify_form($drop) {
    $current  = home_url(add_query_arg(null, null));
    $redirect = add_query_arg('lmeg_signup', 'ok', $current) . '#lmeg-drop-' . (int) $drop->id;
    $nonce    = wp_create_nonce('lmeg_submit');
    $action   = esc_url(admin_url('admin-post.php'));
    ob_start();
    ?>
    <form class="lmeg-drop__form" method="post" action="<?php echo $action; ?>" novalidate>
        <input type="hidden" name="action"       value="lmeg_submit" />
        <input type="hidden" name="_wpnonce"      value="<?php echo esc_attr($nonce); ?>" />
        <input type="hidden" name="redirect"      value="<?php echo esc_url($redirect); ?>" />
        <input type="hidden" name="contact_type"  value="email" />
        <input type="hidden" name="lmeg_tags"     value="drop:<?php echo esc_attr($drop->slug); ?>" />
        <div class="lmeg-hp-wrap" aria-hidden="true"><label>Leave empty<input type="text" name="lmeg_hp" value="" tabindex="-1" autocomplete="off" /></label></div>
        <div class="lmeg-drop__row">
            <input type="email" name="email" required placeholder="you@email.com" aria-label="Email" />
            <button type="submit">Notify me</button>
        </div>
    </form>
    <?php
    return ob_get_clean();
}

function lmeg_drop_styles() {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<style>
    .lmeg-drop{max-width:440px;margin:24px auto;background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 18px 50px rgba(0,0,0,.14);font-family:-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#2f2a2c;}
    .lmeg-drop--empty{padding:28px;text-align:center;color:#9a8f94;}
    .lmeg-drop__cover img{display:block;width:100%;height:auto;}
    .lmeg-drop__body{padding:24px 26px 28px;}
    .lmeg-drop__eyebrow{font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--lmeg-accent);font-weight:700;}
    .lmeg-drop__title{margin:6px 0 8px;font-size:26px;line-height:1.15;letter-spacing:-.01em;}
    .lmeg-drop__desc{margin:0 0 16px;color:#6a5f5a;font-size:15px;line-height:1.55;}
    .lmeg-drop__countdown{display:flex;gap:10px;margin:18px 0 20px;}
    .lmeg-cd{flex:1;background:#faf6f1;border:1px solid #efe6dd;border-radius:12px;padding:12px 4px;text-align:center;}
    .lmeg-cd__n{display:block;font-size:26px;font-weight:700;line-height:1;font-variant-numeric:tabular-nums;}
    .lmeg-cd__l{display:block;margin-top:5px;font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:#9a8f94;}
    .lmeg-drop__row{display:flex;gap:8px;}
    .lmeg-drop__form input[type=email]{flex:1;min-width:0;padding:13px 14px;border:1px solid #d8ccc0;border-radius:10px;font-size:15px;color:#2f2a2c;background:#fff;}
    .lmeg-drop__form input[type=email]:focus{outline:none;border-color:var(--lmeg-accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--lmeg-accent) 20%,transparent);}
    .lmeg-drop__form button{padding:13px 20px;border:0;border-radius:10px;background:var(--lmeg-accent);color:#fff;font-weight:600;font-size:15px;cursor:pointer;white-space:nowrap;}
    .lmeg-drop__form button:hover{filter:brightness(1.06);}
    .lmeg-drop__ok{margin-top:16px;padding:14px 16px;background:#ecfdf3;border:1px solid #bbf7d0;border-radius:12px;color:#166534;font-size:14px;}
    .lmeg-drop__links{display:flex;flex-direction:column;gap:10px;margin-top:6px;}
    .lmeg-drop__link{display:block;padding:14px 16px;border-radius:12px;background:#2f2a2c;color:#fff;text-decoration:none;text-align:center;font-weight:600;font-size:15px;transition:transform .12s ease;}
    .lmeg-drop__link:hover{transform:translateY(-1px);}
    .lmeg-hp-wrap{position:absolute;left:-9999px;height:0;overflow:hidden;}
    </style>';
}

function lmeg_drop_countdown_js() {
    return '<script>(function(){
    function tick(el){
        var t=new Date(el.getAttribute("data-release")).getTime();
        if(isNaN(t)){return;}
        function upd(){
            var d=Math.max(0,Math.floor((t-Date.now())/1000));
            var days=Math.floor(d/86400),h=Math.floor(d%86400/3600),m=Math.floor(d%3600/60),s=d%60;
            var set=function(u,v){var n=el.querySelector("[data-u="+u+"]");if(n)n.textContent=v;};
            set("d",days);set("h",("0"+h).slice(-2));set("m",("0"+m).slice(-2));set("s",("0"+s).slice(-2));
            if(d<=0){clearInterval(iv);var card=el.closest(".lmeg-drop");if(card){setTimeout(function(){location.reload();},1500);}}
        }
        upd();var iv=setInterval(upd,1000);
    }
    document.querySelectorAll(".lmeg-drop__countdown").forEach(tick);
    })();</script>';
}
