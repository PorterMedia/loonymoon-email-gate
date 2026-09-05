<?php
/**
 * Collect Content — a dedicated fan-UGC capture campaign (Cobrand's "Content
 * Submissions"), deliberately SEPARATE from release drops so file uploads never
 * touch the shared signup handler. A campaign has its own page via
 * [fanloop_collect slug="..."]; a fan submits their email + a photo/video (+ an
 * optional caption), which (a) creates/dedupes them as a fan (tagged
 * content-submission + collect:<slug>) and (b) stores the upload for the artist
 * to view and download in the admin gallery.
 *
 * Upload safety: its own nonce + honeypot + per-IP rate limit; server-side MIME
 * allow-list (images + short-form video only) enforced through wp_handle_upload's
 * `mimes` map (which runs wp_check_filetype_and_ext), plus a hard size cap.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('LMEG_COLLECT_DB_VERSION')) define('LMEG_COLLECT_DB_VERSION', '1');
if (!defined('LMEG_COLLECT_MAX_BYTES'))  define('LMEG_COLLECT_MAX_BYTES', 64 * 1024 * 1024); // 64 MB

function lmeg_collect_campaigns_table() { global $wpdb; return $wpdb->prefix . 'lmeg_content_campaigns'; }
function lmeg_collect_subs_table()      { global $wpdb; return $wpdb->prefix . 'lmeg_content_subs'; }

add_action('init', 'lmeg_collect_maybe_install', 1);
function lmeg_collect_maybe_install() {
    if (get_option('lmeg_collect_db_version') === LMEG_COLLECT_DB_VERSION) return;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $c = lmeg_collect_campaigns_table();
    $s = lmeg_collect_subs_table();
    dbDelta("CREATE TABLE $c (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(190) NOT NULL DEFAULT '',
        slug VARCHAR(190) NOT NULL DEFAULT '',
        prompt TEXT NULL,
        accept VARCHAR(10) NOT NULL DEFAULT 'both',
        status VARCHAR(10) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY slug (slug)
    ) $charset;");
    dbDelta("CREATE TABLE $s (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        campaign_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        subscriber_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        email VARCHAR(190) NOT NULL DEFAULT '',
        name VARCHAR(120) NOT NULL DEFAULT '',
        caption VARCHAR(500) NOT NULL DEFAULT '',
        file VARCHAR(300) NOT NULL DEFAULT '',
        url VARCHAR(600) NOT NULL DEFAULT '',
        mime VARCHAR(80) NOT NULL DEFAULT '',
        bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY campaign_id (campaign_id)
    ) $charset;");
    update_option('lmeg_collect_db_version', LMEG_COLLECT_DB_VERSION);
}

/* ---------------------------------------------------------------------------
 * Data
 * ------------------------------------------------------------------------- */

function lmeg_collect_campaigns() {
    global $wpdb;
    return $wpdb->get_results("SELECT * FROM " . lmeg_collect_campaigns_table() . " ORDER BY id DESC");
}

function lmeg_collect_get($slug_or_id) {
    global $wpdb;
    $t = lmeg_collect_campaigns_table();
    if (ctype_digit((string) $slug_or_id)) {
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", (int) $slug_or_id));
    }
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE slug = %s", sanitize_title($slug_or_id)));
}

function lmeg_collect_sub_count($campaign_id) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . lmeg_collect_subs_table() . " WHERE campaign_id = %d", (int) $campaign_id
    ));
}

/** Allowed uploads for a campaign's accept mode: [ext => mime] for wp_handle_upload. */
function lmeg_collect_allowed_mimes($accept) {
    $images = ['jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'heic' => 'image/heic'];
    $videos = ['mp4|m4v' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm'];
    if ($accept === 'image') return $images;
    if ($accept === 'video') return $videos;
    return array_merge($images, $videos);
}

function lmeg_collect_accept_attr($accept) {
    if ($accept === 'image') return 'image/*';
    if ($accept === 'video') return 'video/*';
    return 'image/*,video/*';
}

/* ---------------------------------------------------------------------------
 * Public form — [fanloop_collect slug="..."]
 * ------------------------------------------------------------------------- */

add_shortcode('fanloop_collect', 'lmeg_collect_shortcode');
function lmeg_collect_shortcode($atts) {
    $atts = shortcode_atts(['slug' => '', 'id' => ''], $atts, 'fanloop_collect');
    $camp = $atts['id'] ? lmeg_collect_get($atts['id']) : ($atts['slug'] ? lmeg_collect_get($atts['slug']) : null);
    if (!$camp) return '<p>Content campaign not found.</p>';
    if ($camp->status !== 'active') return '<p>This submission window is closed.</p>';

    $done = (($_GET['lmeg_collect'] ?? '') === 'ok');
    $err  = sanitize_text_field(wp_unslash($_GET['lmeg_collect_err'] ?? ''));
    $nonce  = wp_create_nonce('lmeg_collect');
    $action = esc_url(admin_url('admin-post.php'));
    $accept = lmeg_collect_accept_attr($camp->accept);
    $kind   = $camp->accept === 'image' ? 'a photo' : ($camp->accept === 'video' ? 'a video' : 'a photo or video');
    ob_start();
    ?>
    <div class="lmeg-collect" style="max-width:520px;margin:0 auto;font-family:inherit;">
        <?php if ($camp->prompt) : ?><p class="lmeg-collect__prompt" style="margin:0 0 12px;"><?php echo esc_html($camp->prompt); ?></p><?php endif; ?>
        <?php if ($done) : ?>
            <div class="lmeg-collect__ok" style="padding:16px 18px;border-radius:12px;background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.4);">🎉 Thanks — your submission came through! We’ve got it.</div>
        <?php else : ?>
            <?php if ($err) : ?><div class="lmeg-collect__err" style="padding:12px 14px;border-radius:10px;background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.4);margin-bottom:12px;"><?php echo esc_html($err); ?></div><?php endif; ?>
            <form class="lmeg-collect__form" method="post" action="<?php echo $action; ?>" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="action"   value="lmeg_collect_submit" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />
                <input type="hidden" name="campaign" value="<?php echo (int) $camp->id; ?>" />
                <input type="hidden" name="redirect" value="<?php echo esc_url(home_url(add_query_arg(null, null))); ?>" />
                <div class="lmeg-hp-wrap" aria-hidden="true" style="position:absolute;left:-9999px;"><label>Leave empty<input type="text" name="lmeg_hp" value="" tabindex="-1" autocomplete="off" /></label></div>
                <p style="margin:0 0 10px;"><input type="text" name="name" placeholder="Your name (optional)" style="width:100%;padding:10px 12px;box-sizing:border-box;" /></p>
                <p style="margin:0 0 10px;"><input type="email" name="email" required placeholder="you@email.com" style="width:100%;padding:10px 12px;box-sizing:border-box;" /></p>
                <p style="margin:0 0 10px;"><input type="file" name="file" accept="<?php echo esc_attr($accept); ?>" required style="width:100%;" /><br><small style="opacity:.7;">Upload <?php echo esc_html($kind); ?> (max <?php echo (int) round(LMEG_COLLECT_MAX_BYTES / 1048576); ?> MB).</small></p>
                <p style="margin:0 0 12px;"><textarea name="caption" rows="2" placeholder="Add a caption (optional)" style="width:100%;padding:10px 12px;box-sizing:border-box;"></textarea></p>
                <button type="submit" style="padding:11px 20px;cursor:pointer;">Submit</button>
            </form>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/* ---------------------------------------------------------------------------
 * Submit handler — its own action, isolated from lmeg_submit.
 * ------------------------------------------------------------------------- */

add_action('admin_post_nopriv_lmeg_collect_submit', 'lmeg_collect_submit');
add_action('admin_post_lmeg_collect_submit',        'lmeg_collect_submit');
function lmeg_collect_submit() {
    $redirect = wp_validate_redirect(wp_unslash($_POST['redirect'] ?? ''), home_url('/'));
    $bounce = function ($err = '') use ($redirect) {
        $url = $err
            ? add_query_arg('lmeg_collect_err', rawurlencode($err), remove_query_arg(['lmeg_collect'], $redirect))
            : add_query_arg('lmeg_collect', 'ok', remove_query_arg(['lmeg_collect_err'], $redirect));
        wp_safe_redirect($url);
        exit;
    };

    // Honeypot — a filled hidden field means a bot; pretend success, store nothing.
    if (!empty($_POST['lmeg_hp'])) $bounce();
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'lmeg_collect')) $bounce('Your form expired — please try again.');

    $ip = function_exists('lmeg_client_ip') ? lmeg_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
    if (function_exists('lmeg_rate_limit') && !lmeg_rate_limit('collect_' . md5((string) $ip), 6, HOUR_IN_SECONDS)) {
        $bounce('Too many submissions from here — try again later.');
    }

    $camp = lmeg_collect_get((int) ($_POST['campaign'] ?? 0));
    if (!$camp || $camp->status !== 'active') $bounce('This submission window is closed.');

    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    if (!is_email($email)) $bounce('Please enter a valid email.');
    $name    = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    $caption = sanitize_textarea_field(wp_unslash($_POST['caption'] ?? ''));

    if (empty($_FILES['file']) || !empty($_FILES['file']['error'])) $bounce('Please choose a file to upload.');
    if ((int) $_FILES['file']['size'] > LMEG_COLLECT_MAX_BYTES) {
        $bounce('That file is too big (max ' . (int) round(LMEG_COLLECT_MAX_BYTES / 1048576) . ' MB).');
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    add_filter('upload_dir', 'lmeg_collect_upload_dir');
    $moved = wp_handle_upload($_FILES['file'], [
        'test_form' => false,
        'mimes'     => lmeg_collect_allowed_mimes($camp->accept), // server-side type allow-list
    ]);
    remove_filter('upload_dir', 'lmeg_collect_upload_dir');

    if (!is_array($moved) || !empty($moved['error']) || empty($moved['url'])) {
        $bounce('That file type isn’t allowed — please upload a photo or video.');
    }

    // Create/dedupe the fan (fires welcome + sequences) and tag them.
    $sid = function_exists('lmeg_store_subscriber') ? (int) lmeg_store_subscriber([
        'contact_type' => 'email', 'email' => $email, 'phone' => null,
        'country' => null, 'street' => null, 'city' => null, 'region' => null,
        'postal_code' => null, 'post_id' => null,
    ]) : 0;
    if ($sid && function_exists('lmeg_get_or_create_tag') && function_exists('lmeg_attach_tag')) {
        if ($name && empty($GLOBALS['__lmeg_skip_name'])) {
            global $wpdb; $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}" . LMEG_TABLE . " SET first_name = COALESCE(NULLIF(first_name,''), %s) WHERE id = %d", $name, $sid));
        }
        $t1 = lmeg_get_or_create_tag('content-submission', 'Content submission', false, '#7C6CF6');
        if ($t1) lmeg_attach_tag($sid, $t1->id);
        $t2 = lmeg_get_or_create_tag('collect:' . $camp->slug, 'Collect: ' . $camp->title, true, '#D05FA2');
        if ($t2) lmeg_attach_tag($sid, $t2->id);
    }

    global $wpdb;
    $uploads = wp_get_upload_dir();
    $rel = ltrim(str_replace($uploads['basedir'], '', $moved['file']), '/');
    $wpdb->insert(lmeg_collect_subs_table(), [
        'campaign_id'   => (int) $camp->id,
        'subscriber_id' => $sid,
        'email'         => $email,
        'name'          => substr($name, 0, 120),
        'caption'       => substr($caption, 0, 500),
        'file'          => substr($rel, 0, 300),
        'url'           => substr((string) $moved['url'], 0, 600),
        'mime'          => substr((string) ($moved['type'] ?? ''), 0, 80),
        'bytes'         => (int) ($_FILES['file']['size'] ?? 0),
        'ip'            => substr((string) $ip, 0, 45),
        'created_at'    => current_time('mysql'),
    ]);
    $bounce();
}

/** Route uploads into a dedicated subfolder so submissions are easy to find/manage. */
function lmeg_collect_upload_dir($dirs) {
    $dirs['subdir'] = '/fanloop-submissions' . $dirs['subdir'];
    $dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
    $dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
    return $dirs;
}

/* ---------------------------------------------------------------------------
 * Admin — manage campaigns + view the submission gallery
 * ------------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_submenu_page('lmeg', 'Collect Content', 'Collect Content', 'manage_options', 'lmeg-collect', 'lmeg_admin_collect');
}, 21);

function lmeg_admin_collect() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $notice = '';

    // Create a campaign.
    if (isset($_POST['lmeg_collect_new_nonce']) && wp_verify_nonce($_POST['lmeg_collect_new_nonce'], 'lmeg_collect_new')) {
        $title  = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $slug   = sanitize_title($_POST['slug'] ?? $title);
        $accept = in_array($_POST['accept'] ?? 'both', ['image', 'video', 'both'], true) ? $_POST['accept'] : 'both';
        $prompt = sanitize_textarea_field(wp_unslash($_POST['prompt'] ?? ''));
        if ($title && $slug) {
            if ($wpdb->get_var($wpdb->prepare("SELECT id FROM " . lmeg_collect_campaigns_table() . " WHERE slug = %s", $slug))) {
                $slug .= '-' . wp_generate_password(4, false);
            }
            $wpdb->insert(lmeg_collect_campaigns_table(), [
                'title' => $title, 'slug' => $slug, 'prompt' => $prompt,
                'accept' => $accept, 'status' => 'active', 'created_at' => current_time('mysql'),
            ]);
            $notice = '<div class="notice notice-success is-dismissible"><p>Campaign <strong>' . esc_html($title) . '</strong> created. Embed it with <code>[fanloop_collect slug="' . esc_html($slug) . '"]</code>.</p></div>';
        }
    }
    // Toggle status / delete.
    if (isset($_POST['lmeg_collect_act_nonce']) && wp_verify_nonce($_POST['lmeg_collect_act_nonce'], 'lmeg_collect_act')) {
        $cid = (int) ($_POST['cid'] ?? 0); $act = sanitize_key($_POST['act'] ?? '');
        if ($cid && $act === 'toggle') {
            $cur = $wpdb->get_var($wpdb->prepare("SELECT status FROM " . lmeg_collect_campaigns_table() . " WHERE id = %d", $cid));
            $wpdb->update(lmeg_collect_campaigns_table(), ['status' => $cur === 'active' ? 'off' : 'active'], ['id' => $cid]);
        } elseif ($cid && $act === 'delete') {
            $wpdb->delete(lmeg_collect_campaigns_table(), ['id' => $cid]);
            // Submission rows kept intentionally are removed too (files remain on disk).
            $wpdb->delete(lmeg_collect_subs_table(), ['campaign_id' => $cid]);
        }
    }

    $card = 'background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px 18px;color:#F4F5F7;';

    // Single-campaign gallery view.
    $view = isset($_GET['campaign']) ? lmeg_collect_get((int) $_GET['campaign']) : null;
    if ($view) {
        $subs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . lmeg_collect_subs_table() . " WHERE campaign_id = %d ORDER BY id DESC", (int) $view->id
        ));
        ?>
        <div class="wrap lmeg-admin">
            <h1>Submissions — <?php echo esc_html($view->title); ?></h1>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-collect')); ?>">&larr; All campaigns</a> · <?php echo count($subs); ?> submission<?php echo count($subs) === 1 ? '' : 's'; ?> · shortcode <code>[fanloop_collect slug="<?php echo esc_attr($view->slug); ?>"]</code></p>
            <?php if (!$subs) : ?>
                <div style="<?php echo $card; ?>max-width:820px;">No submissions yet. Share the page with <code>[fanloop_collect slug="<?php echo esc_attr($view->slug); ?>"]</code> and they’ll appear here.</div>
            <?php else : ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;max-width:1100px;">
                <?php foreach ($subs as $sb) : $is_img = strpos((string) $sb->mime, 'image/') === 0; ?>
                    <div style="<?php echo $card; ?>overflow:hidden;padding:0;">
                        <div style="aspect-ratio:1/1;background:#0E0F16;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                            <?php if ($is_img) : ?>
                                <a href="<?php echo esc_url($sb->url); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url($sb->url); ?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block;"></a>
                            <?php else : ?>
                                <video src="<?php echo esc_url($sb->url); ?>#t=0.1" controls preload="metadata" style="width:100%;height:100%;object-fit:cover;background:#000;"></video>
                            <?php endif; ?>
                        </div>
                        <div style="padding:10px 12px;">
                            <?php if ($sb->caption) : ?><div style="font-size:12.5px;line-height:1.35;margin-bottom:6px;">“<?php echo esc_html($sb->caption); ?>”</div><?php endif; ?>
                            <div style="font-size:12px;color:#8B90A0;"><?php echo $sb->subscriber_id ? '<a href="' . esc_url(add_query_arg(['page' => 'lmeg', 'fan' => (int) $sb->subscriber_id], admin_url('admin.php'))) . '">' . esc_html($sb->name ?: $sb->email) . '</a>' : esc_html($sb->name ?: $sb->email); ?></div>
                            <div style="font-size:11px;color:#8B90A0;margin-top:2px;"><?php echo esc_html(date_i18n('M j, Y', strtotime($sb->created_at))); ?> · <a href="<?php echo esc_url($sb->url); ?>" download>Download</a></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return;
    }

    // Campaign list + creator.
    $camps = lmeg_collect_campaigns();
    ?>
    <div class="wrap lmeg-admin">
        <h1>Fanloop — Collect Content</h1>
        <?php echo $notice; ?>
        <p style="max-width:820px;">Run a fan-content campaign — a page where fans upload a <strong>photo or video</strong> (and join your list at the same time). Great for cover contests, "show us your setup", UGC for a release. Every submitter becomes a fan tagged <code>content-submission</code>.</p>

        <div style="<?php echo $card; ?>max-width:640px;margin:12px 0 18px;">
            <form method="post">
                <?php wp_nonce_field('lmeg_collect_new', 'lmeg_collect_new_nonce'); ?>
                <p style="margin:0 0 10px;"><label>Title<br><input type="text" name="title" class="regular-text" required placeholder="Show us your LOONY tattoo" style="width:100%;" /></label></p>
                <p style="margin:0 0 10px;"><label>Prompt shown to fans<br><textarea name="prompt" rows="2" class="large-text" placeholder="Upload your photo for a chance to be featured 💜"></textarea></label></p>
                <p style="margin:0 0 10px;"><label>Accept
                    <select name="accept"><option value="both">Photos &amp; videos</option><option value="image">Photos only</option><option value="video">Videos only</option></select>
                </label></p>
                <button type="submit" class="button button-primary">Create campaign</button>
            </form>
        </div>

        <?php if (!$camps) : ?>
            <p class="description">No campaigns yet — create one above.</p>
        <?php else : ?>
        <table class="widefat striped" style="max-width:900px;">
            <thead><tr><th>Campaign</th><th>Accepts</th><th>Submissions</th><th>Shortcode</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($camps as $cp) : $n = lmeg_collect_sub_count($cp->id); ?>
                <tr>
                    <td><strong><a href="<?php echo esc_url(add_query_arg('campaign', (int) $cp->id, admin_url('admin.php?page=lmeg-collect'))); ?>"><?php echo esc_html($cp->title); ?></a></strong></td>
                    <td style="color:#8B90A0;"><?php echo esc_html(['both' => 'Photos & videos', 'image' => 'Photos', 'video' => 'Videos'][$cp->accept] ?? $cp->accept); ?></td>
                    <td><a href="<?php echo esc_url(add_query_arg('campaign', (int) $cp->id, admin_url('admin.php?page=lmeg-collect'))); ?>"><?php echo (int) $n; ?></a></td>
                    <td><a href="<?php echo esc_url(lmeg_collect_url($cp)); ?>" target="_blank" rel="noopener">Share link ↗</a><br><code style="font-size:11px;">[fanloop_collect slug="<?php echo esc_attr($cp->slug); ?>"]</code></td>
                    <td><?php echo $cp->status === 'active' ? '<span style="color:#34D399;">● Active</span>' : '<span style="color:#8B90A0;">○ Off</span>'; ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('lmeg_collect_act', 'lmeg_collect_act_nonce'); ?>
                            <input type="hidden" name="cid" value="<?php echo (int) $cp->id; ?>" />
                            <button type="submit" name="act" value="toggle" class="button button-small"><?php echo $cp->status === 'active' ? 'Turn off' : 'Turn on'; ?></button>
                            <button type="submit" name="act" value="delete" class="button button-small" onclick="return confirm('Delete this campaign? Submission records are removed (uploaded files stay in your Media uploads).');">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Standalone shareable page — /?collect=<slug>. So a campaign works with just
 * a link (no need to place the shortcode in a theme/Elementor widget), the
 * same way drops share via /?drop=<slug>. Scoped to the front page so it never
 * overrides a page that intentionally embeds [fanloop_collect].
 * ------------------------------------------------------------------------- */

function lmeg_collect_url($camp) {
    $slug = is_object($camp) ? $camp->slug : (string) $camp;
    return home_url('/?collect=' . rawurlencode($slug));
}

add_action('template_redirect', 'lmeg_collect_standalone_page');
function lmeg_collect_standalone_page() {
    if (empty($_GET['collect']) || is_admin()) return;
    if (!(is_front_page() || is_home())) return;
    $camp = lmeg_collect_get(sanitize_title(wp_unslash($_GET['collect'])));
    if (!$camp) return;

    status_header(200);
    nocache_headers();
    get_header();
    echo '<div class="lmeg-collect-standalone" style="max-width:600px;margin:48px auto;padding:0 18px;">';
    if ($camp->title) echo '<h1 style="text-align:center;margin:0 0 18px;">' . esc_html($camp->title) . '</h1>';
    echo do_shortcode('[fanloop_collect slug="' . esc_attr($camp->slug) . '"]');
    echo '</div>';
    get_footer();
    exit;
}
