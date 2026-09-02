<?php
if (!defined('ABSPATH')) exit;

/* ============================================================================
 * Fanloop — Releases hub
 * ----------------------------------------------------------------------------
 * One place to plan a release. A single form (built out across slices) creates
 * and keeps in sync: the Drop (countdown + notify + release-day broadcast), a
 * site release page, and one shop product with format variants. This file is
 * the scaffold — data model + admin list + create/edit form for the release
 * record itself. The cascade (drop / page / product) layers on next.
 * ========================================================================== */

if (!defined('LMEG_RELEASE_DB_VERSION')) define('LMEG_RELEASE_DB_VERSION', '1');

function lmeg_releases_table() {
    global $wpdb;
    return $wpdb->prefix . 'lmeg_releases';
}

/* -------------------------------------------------------------------------
 * Data model — version-gated self-install (same pattern as presaves/wallet).
 * ---------------------------------------------------------------------- */
add_action('init', 'lmeg_releases_maybe_install', 1);
function lmeg_releases_maybe_install() {
    if (get_option('lmeg_release_db_version') === LMEG_RELEASE_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $t = lmeg_releases_table();
    dbDelta("CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(200) NOT NULL DEFAULT '',
        artwork_url VARCHAR(600) NOT NULL DEFAULT '',
        release_at DATETIME DEFAULT NULL,
        description TEXT DEFAULT NULL,
        links TEXT DEFAULT NULL,
        formats TEXT DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        drop_id BIGINT UNSIGNED DEFAULT NULL,
        product_id BIGINT UNSIGNED DEFAULT NULL,
        page_id BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME DEFAULT NULL,
        updated_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        KEY status (status),
        KEY release_at (release_at)
    ) $charset;");
    update_option('lmeg_release_db_version', LMEG_RELEASE_DB_VERSION);
}

/* -------------------------------------------------------------------------
 * Data helpers.
 * ---------------------------------------------------------------------- */
function lmeg_releases_all($limit = 200) {
    global $wpdb;
    $t = lmeg_releases_table();
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM $t ORDER BY COALESCE(release_at, created_at) DESC, id DESC LIMIT %d", (int) $limit));
}

function lmeg_release_get($id) {
    global $wpdb;
    $t = lmeg_releases_table();
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", (int) $id));
}

function lmeg_release_status_label($s) {
    $m = ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'released' => 'Released'];
    return $m[$s] ?? ucfirst((string) $s);
}

/** Small coloured status pill for the admin list. */
function lmeg_release_status_pill($s) {
    $c = ['draft' => '#6b7280', 'scheduled' => '#2563eb', 'released' => '#15803d'];
    $bg = ['draft' => '#f3f4f6', 'scheduled' => '#eff6ff', 'released' => '#f0fdf4'];
    $col = $c[$s] ?? '#374151'; $back = $bg[$s] ?? '#f3f4f6';
    return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700;color:' . $col . ';background:' . $back . '">' . esc_html(lmeg_release_status_label($s)) . '</span>';
}

/* -------------------------------------------------------------------------
 * Admin — submenu + list/form page.
 * ---------------------------------------------------------------------- */
add_action('admin_menu', 'lmeg_releases_admin_menu', 28);
function lmeg_releases_admin_menu() {
    add_submenu_page('lmeg', 'Releases', 'Releases', 'manage_options', 'lmeg-releases', 'lmeg_releases_admin_page');
}

function lmeg_releases_admin_page() {
    if (!current_user_can('manage_options')) return;

    // Save (create / edit the release record).
    $notice = '';
    if (isset($_POST['lmeg_release_action']) && check_admin_referer('lmeg_release_save', 'lmeg_release_nonce')) {
        if ($_POST['lmeg_release_action'] === 'save') {
            $id = lmeg_release_save_from_post();
            $notice = '<div class="notice notice-success"><p>Release saved.</p></div>';
            // fall through to the list so the saved row is visible
            $_GET = [];
        }
    }

    $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
    $edit   = isset($_GET['edit']) ? lmeg_release_get((int) $_GET['edit']) : null;

    echo '<div class="wrap">';
    echo '<h1 style="display:flex;align-items:center;gap:12px;">Releases';
    if ($action !== 'new' && !$edit) {
        echo ' <a href="' . esc_url(admin_url('admin.php?page=lmeg-releases&action=new')) . '" class="button button-primary">New release</a>';
    }
    echo '</h1>';
    echo $notice;

    if ($action === 'new' || $edit) {
        lmeg_releases_render_form($edit);
    } else {
        lmeg_releases_render_list();
    }
    echo '</div>';
}

/** Persist the release record from $_POST; returns the id. (Cascade added next slice.) */
function lmeg_release_save_from_post() {
    global $wpdb;
    $t   = lmeg_releases_table();
    $now = current_time('mysql');
    $id  = (int) ($_POST['release_id'] ?? 0);

    $release_at = sanitize_text_field(wp_unslash($_POST['release_at'] ?? ''));
    $release_at = $release_at !== '' ? date('Y-m-d H:i:s', strtotime($release_at)) : null;

    $data = [
        'title'       => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
        'artwork_url' => esc_url_raw(wp_unslash($_POST['artwork_url'] ?? '')),
        'release_at'  => $release_at,
        'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
        'links'       => sanitize_textarea_field(wp_unslash($_POST['links'] ?? '')),
        'formats'     => sanitize_textarea_field(wp_unslash($_POST['formats'] ?? '')),
        'status'      => in_array(($_POST['status'] ?? ''), ['draft', 'scheduled', 'released'], true) ? $_POST['status'] : 'draft',
        'updated_at'  => $now,
    ];
    $fmt = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

    if ($id) {
        $wpdb->update($t, $data, ['id' => $id], $fmt, ['%d']);
    } else {
        $data['created_at'] = $now; $fmt[] = '%s';
        $wpdb->insert($t, $data, $fmt);
        $id = (int) $wpdb->insert_id;
    }
    return $id;
}

/** The releases list (or an empty state that explains the one-form idea). */
function lmeg_releases_render_list() {
    $rows = lmeg_releases_all();
    if (empty($rows)) {
        echo '<div style="max-width:640px;margin-top:8px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px 24px;">'
           . '<h2 style="margin-top:2px;">Plan a release in one place</h2>'
           . '<p style="font-size:14px;color:#3c434a;line-height:1.6;">A release here becomes your <strong>drop</strong> (countdown + &ldquo;notify me&rdquo; + the release-day broadcast), a <strong>release page</strong> on your site, and a <strong>shop product</strong> with format options (Digital, CD, Vinyl&hellip;) &mdash; all from one form, kept in sync when you edit.</p>'
           . '<p style="margin-top:16px;"><a href="' . esc_url(admin_url('admin.php?page=lmeg-releases&action=new')) . '" class="button button-primary button-hero">Create your first release</a></p>'
           . '</div>';
        return;
    }
    echo '<table class="widefat striped" style="margin-top:14px;max-width:900px;">';
    echo '<thead><tr><th style="width:56px;"></th><th>Release</th><th>Status</th><th>Release date</th><th>Linked</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $art = $r->artwork_url
            ? '<img src="' . esc_url($r->artwork_url) . '" style="width:44px;height:44px;object-fit:cover;border-radius:8px;border:1px solid #dcdcde;display:block">'
            : '<div style="width:44px;height:44px;border-radius:8px;background:#f3f4f6;border:1px solid #e5e7eb"></div>';
        $when = $r->release_at ? esc_html(date_i18n('M j, Y · g:i a', strtotime($r->release_at))) : '<span style="color:#9ca3af">—</span>';
        $linked = [];
        if ($r->drop_id)    $linked[] = 'Drop';
        if ($r->page_id)    $linked[] = 'Page';
        if ($r->product_id) $linked[] = 'Shop';
        $linkedTxt = $linked ? esc_html(implode(' · ', $linked)) : '<span style="color:#9ca3af">not yet</span>';
        echo '<tr>'
           . '<td>' . $art . '</td>'
           . '<td><strong>' . esc_html($r->title ?: '(untitled)') . '</strong></td>'
           . '<td>' . lmeg_release_status_pill($r->status) . '</td>'
           . '<td>' . $when . '</td>'
           . '<td>' . $linkedTxt . '</td>'
           . '<td><a href="' . esc_url(admin_url('admin.php?page=lmeg-releases&edit=' . (int) $r->id)) . '" class="button button-small">Edit</a></td>'
           . '</tr>';
    }
    echo '</tbody></table>';
}

/** The create / edit form (the record itself; cascade wiring lands next slice). */
function lmeg_releases_render_form($edit = null) {
    if (function_exists('lmeg_media_enqueue')) lmeg_media_enqueue();
    $r = $edit ?: (object) ['id' => 0, 'title' => '', 'artwork_url' => '', 'release_at' => null, 'description' => '', 'links' => '', 'formats' => "Digital\nCD\nVinyl", 'status' => 'draft'];
    $release_local = $r->release_at ? date('Y-m-d\TH:i', strtotime($r->release_at)) : '';
    ?>
    <p style="margin-top:6px;"><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-releases')); ?>">&larr; All releases</a></p>
    <form method="post" style="max-width:720px;">
        <?php wp_nonce_field('lmeg_release_save', 'lmeg_release_nonce'); ?>
        <input type="hidden" name="lmeg_release_action" value="save">
        <input type="hidden" name="release_id" value="<?php echo (int) $r->id; ?>">
        <table class="form-table" role="presentation">
            <tr><th><label for="r_title">Title</label></th><td>
                <input name="title" id="r_title" class="regular-text" required value="<?php echo esc_attr($r->title); ?>" placeholder="New single — &ldquo;Moonchild&rdquo;">
            </td></tr>
            <tr><th>Artwork</th><td>
                <?php echo lmeg_image_field('artwork_url', $r->artwork_url, ['title' => 'Choose release artwork', 'hint' => 'Square cover art. Pick from your Media Library.']); ?>
            </td></tr>
            <tr><th><label for="r_release">Release date &amp; time</label></th><td>
                <input type="datetime-local" name="release_at" id="r_release" value="<?php echo esc_attr($release_local); ?>">
                <p class="description">Site timezone. The drop countdown runs to this moment.</p>
            </td></tr>
            <tr><th><label for="r_desc">Description</label></th><td>
                <textarea name="description" id="r_desc" rows="3" class="large-text" placeholder="A one-line tease for the release."><?php echo esc_textarea($r->description); ?></textarea>
            </td></tr>
            <tr><th><label for="r_links">Streaming links</label></th><td>
                <textarea name="links" id="r_links" rows="5" class="large-text code" placeholder="Spotify | https://open.spotify.com/…&#10;Apple Music | https://music.apple.com/…"><?php echo esc_textarea($r->links); ?></textarea>
                <p class="description">One per line, <code>Label | URL</code>. Shown as buttons on the release page after release.</p>
            </td></tr>
            <tr><th><label for="r_formats">Formats</label></th><td>
                <textarea name="formats" id="r_formats" rows="3" class="large-text" placeholder="Digital&#10;CD&#10;Vinyl"><?php echo esc_textarea($r->formats); ?></textarea>
                <p class="description">One per line. These become the buyer&rsquo;s format options on the shop product.</p>
            </td></tr>
            <tr><th><label for="r_status">Status</label></th><td>
                <select name="status" id="r_status">
                    <?php foreach (['draft' => 'Draft', 'scheduled' => 'Scheduled', 'released' => 'Released'] as $k => $v): ?>
                        <option value="<?php echo esc_attr($k); ?>" <?php selected($r->status, $k); ?>><?php echo esc_html($v); ?></option>
                    <?php endforeach; ?>
                </select>
            </td></tr>
        </table>
        <p>
            <button class="button button-primary button-hero">Save release</button>
            <span class="description" style="margin-left:10px;">Next: saving will also create the drop, the release page, and the shop product.</span>
        </p>
    </form>
    <?php
}
