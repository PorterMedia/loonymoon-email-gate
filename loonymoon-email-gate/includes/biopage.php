<?php
/**
 * Smart Bio — a link-in-bio page (Linktree replacement) with a built-in
 * email/SMS capture at the top, so every bio visit is a chance to own the
 * fan relationship instead of leaking the tap to a third party.
 *
 * Each outbound link is routed through the existing /go/<slug> smartlink
 * tracker, so clicks are counted and attributed to known fans for free.
 *
 * Config lives in the `lmeg_bio` option (no new table). Render anywhere with
 * the [loony_bio] shortcode — typically on a dedicated page like /links.
 */

if (!defined('ABSPATH')) {
    exit;
}

function lmeg_bio_defaults() {
    return [
        'name'             => get_bloginfo('name'),
        'tagline'          => '',
        'avatar_url'       => '',
        'accent'           => '',
        'show_capture'     => 1,
        'capture_headline' => 'Join the loonybin',
        'capture_sub'      => 'New music, shows, and drops — straight to you.',
        'links'            => [], // [['label','url','slug'], ...]
    ];
}

function lmeg_bio_config() {
    $cfg = get_option('lmeg_bio', []);
    if (!is_array($cfg)) $cfg = [];
    return wp_parse_args($cfg, lmeg_bio_defaults());
}

/**
 * Ensure a smartlink row exists for a bio target so its clicks are tracked.
 * Slug is derived from the URL so it stays stable across saves.
 */
function lmeg_bio_sync_smartlink($url) {
    global $wpdb;
    $tbl  = $wpdb->prefix . 'lmeg_smartlinks';
    $slug = 'bio-' . substr(md5($url), 0, 8);
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $tbl WHERE slug = %s", $slug));
    if ($exists) {
        $wpdb->update($tbl, ['target_url' => $url], ['id' => $exists]);
    } else {
        $wpdb->insert($tbl, [
            'slug'       => $slug,
            'target_url' => $url,
            'clicks'     => 0,
            'created_at' => current_time('mysql'),
        ]);
    }
    return $slug;
}

/* ---------------------------------------------------------------------------
 * Admin
 * ------------------------------------------------------------------------- */

function lmeg_admin_bio() {
    if (!current_user_can('manage_options')) return;
    $cfg = lmeg_bio_config();

    $links_text = '';
    foreach ($cfg['links'] as $l) {
        $links_text .= $l['label'] . ' | ' . $l['url'] . "\n";
    }

    echo '<div class="wrap lmeg-wrap"><h1>Smart Bio</h1>';
    if (!empty($_GET['saved'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Bio saved.</p></div>';
    }
    echo '<p class="description" style="max-width:60em;">Your link-in-bio page with a built-in signup. Drop <code>[loony_bio]</code> on a page (e.g. a page at <code>/links</code>) and use that URL in your Instagram/TikTok bio. Every link is click-tracked, and taps by known fans land on their timeline.</p>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('lmeg_save_bio');
    echo '<input type="hidden" name="action" value="lmeg_save_bio" />';
    echo '<table class="form-table" role="presentation">';

    echo '<tr><th><label for="b_name">Display name</label></th><td>'
       . '<input name="name" id="b_name" class="regular-text" value="' . esc_attr($cfg['name']) . '" /></td></tr>';

    echo '<tr><th><label for="b_tag">Tagline</label></th><td>'
       . '<input name="tagline" id="b_tag" class="regular-text" value="' . esc_attr($cfg['tagline']) . '" placeholder="alt r&amp;b · Toronto" /></td></tr>';

    echo '<tr><th><label for="b_av">Avatar image URL</label></th><td>'
       . '<input name="avatar_url" id="b_av" class="regular-text" value="' . esc_attr($cfg['avatar_url']) . '" placeholder="https://…/avatar.jpg" />'
       . '<p class="description">Square image; shown as a circle.</p></td></tr>';

    echo '<tr><th><label for="b_accent">Accent color (hex)</label></th><td>'
       . '<input name="accent" id="b_accent" class="regular-text" value="' . esc_attr($cfg['accent']) . '" placeholder="leave blank to use your brand color" /></td></tr>';

    echo '<tr><th>Signup capture</th><td>'
       . '<label><input type="checkbox" name="show_capture" value="1" ' . checked($cfg['show_capture'], 1, false) . ' /> Show the email signup at the top of the page</label></td></tr>';

    echo '<tr><th><label for="b_ch">Capture headline</label></th><td>'
       . '<input name="capture_headline" id="b_ch" class="regular-text" value="' . esc_attr($cfg['capture_headline']) . '" /></td></tr>';

    echo '<tr><th><label for="b_cs">Capture subtext</label></th><td>'
       . '<input name="capture_sub" id="b_cs" class="large-text" value="' . esc_attr($cfg['capture_sub']) . '" /></td></tr>';

    echo '<tr><th><label for="b_links">Links</label></th><td>'
       . '<textarea name="links" id="b_links" rows="8" class="large-text code" placeholder="Listen on Spotify | https://open.spotify.com/…&#10;Merch | https://shop.…&#10;Tour dates | https://…">' . esc_textarea($links_text) . '</textarea>'
       . '<p class="description">One per line, <code>Label | URL</code>, in the order they should appear. Each becomes a tracked button.</p></td></tr>';

    echo '</table>';
    echo '<p><button type="submit" class="button button-primary">Save bio</button>';

    // Offer a quick link to a page that already has the shortcode, if we can find one.
    $page = lmeg_bio_find_page();
    if ($page) {
        echo ' <a href="' . esc_url(get_permalink($page)) . '" target="_blank" class="button">View live bio</a>';
    }
    echo '</p></form></div>';
}

/** Best-effort: find a published page containing the [loony_bio] shortcode. */
function lmeg_bio_find_page() {
    $q = new WP_Query([
        'post_type'      => 'page',
        'post_status'    => 'publish',
        's'              => '[loony_bio',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);
    return $q->have_posts() ? $q->posts[0] : 0;
}

add_action('admin_post_lmeg_save_bio', 'lmeg_handle_save_bio');
function lmeg_handle_save_bio() {
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
    check_admin_referer('lmeg_save_bio');

    $links = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) wp_unslash($_POST['links'] ?? '')) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strpos($line, '|') !== false) {
            list($label, $url) = array_map('trim', explode('|', $line, 2));
        } else {
            $label = $line; $url = $line;
        }
        $url = esc_url_raw($url);
        if (!$url) continue;
        $links[] = [
            'label' => sanitize_text_field($label ?: $url),
            'url'   => $url,
            'slug'  => lmeg_bio_sync_smartlink($url),
        ];
    }

    $cfg = [
        'name'             => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
        'tagline'          => sanitize_text_field(wp_unslash($_POST['tagline'] ?? '')),
        'avatar_url'       => esc_url_raw(wp_unslash($_POST['avatar_url'] ?? '')),
        'accent'           => sanitize_hex_color(wp_unslash($_POST['accent'] ?? '')) ?: '',
        'show_capture'     => !empty($_POST['show_capture']) ? 1 : 0,
        'capture_headline' => sanitize_text_field(wp_unslash($_POST['capture_headline'] ?? '')),
        'capture_sub'      => sanitize_text_field(wp_unslash($_POST['capture_sub'] ?? '')),
        'links'            => $links,
    ];
    update_option('lmeg_bio', $cfg, false);

    wp_safe_redirect(admin_url('admin.php?page=lmeg-bio&saved=1'));
    exit;
}

/* ---------------------------------------------------------------------------
 * Public shortcode — [loony_bio]
 * ------------------------------------------------------------------------- */

add_shortcode('loony_bio', 'lmeg_shortcode_bio');
function lmeg_shortcode_bio($atts = []) {
    $cfg    = lmeg_bio_config();
    $s      = lmeg_get_settings();
    $accent = $cfg['accent'] ?: (sanitize_hex_color($s['color_primary'] ?? '') ?: '#d05fa2');
    $success = (!empty($_GET['lmeg_signup']) && $_GET['lmeg_signup'] === 'ok');

    ob_start();
    ?>
    <div id="lmeg-bio" class="lmeg-bio" style="--lmeg-accent:<?php echo esc_attr($accent); ?>;">
        <?php if ($cfg['avatar_url']) : ?>
            <div class="lmeg-bio__avatar"><img src="<?php echo esc_url($cfg['avatar_url']); ?>" alt="<?php echo esc_attr($cfg['name']); ?>" loading="lazy" /></div>
        <?php endif; ?>
        <h1 class="lmeg-bio__name"><?php echo esc_html($cfg['name']); ?></h1>
        <?php if ($cfg['tagline']) : ?><p class="lmeg-bio__tagline"><?php echo esc_html($cfg['tagline']); ?></p><?php endif; ?>

        <?php if ($cfg['show_capture']) : ?>
            <?php if ($success) : ?>
                <div class="lmeg-bio__ok" role="status">✓ You&rsquo;re in. Welcome to the loonybin.</div>
            <?php else : ?>
                <div class="lmeg-bio__capture">
                    <?php if ($cfg['capture_headline']) : ?><div class="lmeg-bio__caphead"><?php echo esc_html($cfg['capture_headline']); ?></div><?php endif; ?>
                    <?php if ($cfg['capture_sub']) : ?><div class="lmeg-bio__capsub"><?php echo esc_html($cfg['capture_sub']); ?></div><?php endif; ?>
                    <?php echo lmeg_bio_capture_form(); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($cfg['links'])) : ?>
            <div class="lmeg-bio__links">
                <?php foreach ($cfg['links'] as $l) :
                    $href = !empty($l['slug']) && function_exists('lmeg_smartlink_url')
                        ? lmeg_smartlink_url($l['slug'])
                        : $l['url']; ?>
                    <a class="lmeg-bio__link" href="<?php echo esc_url($href); ?>" rel="noopener"><?php echo esc_html($l['label']); ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    echo lmeg_bio_styles();
    return ob_get_clean();
}

function lmeg_bio_capture_form() {
    $current  = home_url(add_query_arg(null, null));
    $redirect = add_query_arg('lmeg_signup', 'ok', $current) . '#lmeg-bio';
    $nonce    = wp_create_nonce('lmeg_submit');
    $action   = esc_url(admin_url('admin-post.php'));
    ob_start();
    ?>
    <form class="lmeg-bio__form" method="post" action="<?php echo $action; ?>" novalidate>
        <input type="hidden" name="action"      value="lmeg_submit" />
        <input type="hidden" name="_wpnonce"     value="<?php echo esc_attr($nonce); ?>" />
        <input type="hidden" name="redirect"     value="<?php echo esc_url($redirect); ?>" />
        <input type="hidden" name="contact_type" value="email" />
        <input type="hidden" name="lmeg_tags"    value="bio" />
        <div class="lmeg-hp-wrap" aria-hidden="true"><label>Leave empty<input type="text" name="lmeg_hp" value="" tabindex="-1" autocomplete="off" /></label></div>
        <div class="lmeg-bio__row">
            <input type="email" name="email" required placeholder="you@email.com" aria-label="Email" />
            <button type="submit">Join</button>
        </div>
    </form>
    <?php
    return ob_get_clean();
}

function lmeg_bio_styles() {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<style>
    .lmeg-bio{max-width:440px;margin:24px auto;padding:8px 18px 28px;text-align:center;font-family:-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#2f2a2c;}
    .lmeg-bio__avatar img{width:104px;height:104px;border-radius:50%;object-fit:cover;border:3px solid #fff;box-shadow:0 8px 24px rgba(0,0,0,.16);}
    .lmeg-bio__name{margin:14px 0 2px;font-size:24px;letter-spacing:-.01em;}
    .lmeg-bio__tagline{margin:0 0 18px;color:#6a5f5a;font-size:14.5px;}
    .lmeg-bio__capture{background:#faf6f1;border:1px solid #efe6dd;border-radius:16px;padding:18px 16px;margin:0 0 20px;}
    .lmeg-bio__caphead{font-weight:700;font-size:16px;margin-bottom:3px;}
    .lmeg-bio__capsub{color:#6a5f5a;font-size:13.5px;line-height:1.5;margin-bottom:12px;}
    .lmeg-bio__row{display:flex;gap:8px;}
    .lmeg-bio__form input[type=email]{flex:1;min-width:0;padding:12px 14px;border:1px solid #d8ccc0;border-radius:10px;font-size:15px;background:#fff;color:#2f2a2c;}
    .lmeg-bio__form input[type=email]:focus{outline:none;border-color:var(--lmeg-accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--lmeg-accent) 20%,transparent);}
    .lmeg-bio__form button{padding:12px 20px;border:0;border-radius:10px;background:var(--lmeg-accent);color:#fff;font-weight:600;font-size:15px;cursor:pointer;}
    .lmeg-bio__form button:hover{filter:brightness(1.06);}
    .lmeg-bio__ok{background:#ecfdf3;border:1px solid #bbf7d0;border-radius:14px;padding:14px 16px;color:#166534;font-size:14px;margin:0 0 20px;}
    .lmeg-bio__links{display:flex;flex-direction:column;gap:11px;}
    .lmeg-bio__link{display:block;padding:15px 18px;border-radius:12px;background:#fff;border:1px solid #e6ddd2;color:#2f2a2c;text-decoration:none;font-weight:600;font-size:15.5px;box-shadow:0 2px 8px rgba(0,0,0,.05);transition:transform .12s ease,border-color .12s ease;}
    .lmeg-bio__link:hover{transform:translateY(-2px);border-color:var(--lmeg-accent);}
    .lmeg-hp-wrap{position:absolute;left:-9999px;height:0;overflow:hidden;}
    </style>';
}
