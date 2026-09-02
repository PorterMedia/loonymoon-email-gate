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

/** Persist the release record from $_POST, then cascade to the drop, page and
 *  product (creating or updating the linked rows). Returns the release id. */
function lmeg_release_save_from_post() {
    global $wpdb;
    $t   = lmeg_releases_table();
    $now = current_time('mysql');
    $id  = (int) ($_POST['release_id'] ?? 0);
    $prev = $id ? lmeg_release_get($id) : null;   // keep existing linked ids on edit

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

    // Build the working release object (new field values + existing linked ids).
    $rel = (object) array_merge((array) ($prev ?: []), $data, ['id' => $id]);

    // Cascade — create/sync the drop, the WP page and the shop product.
    $drop_id    = lmeg_release_sync_drop($rel);
    $rel->drop_id = $drop_id;
    $drop_slug  = $drop_id ? $wpdb->get_var($wpdb->prepare('SELECT slug FROM ' . lmeg_drops_table() . ' WHERE id = %d', $drop_id)) : '';
    $page_id    = lmeg_release_sync_page($rel, (string) $drop_slug);
    $product_id = lmeg_release_sync_product($rel);

    $wpdb->update($t,
        ['drop_id' => $drop_id ?: null, 'page_id' => $page_id ?: null, 'product_id' => $product_id ?: null],
        ['id' => $id], ['%d', '%d', '%d'], ['%d']);

    return $id;
}

/** A unique slug within $table, keeping $keep_id's own slug free. */
function lmeg_release_unique_slug($table, $base, $keep_id = 0) {
    global $wpdb;
    $base = sanitize_title($base) ?: 'release';
    $slug = $base; $i = 2;
    while ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE slug = %s AND id <> %d", $slug, (int) $keep_id))) {
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

/** Create or update the release's Drop (countdown + notify + release-day broadcast). */
function lmeg_release_sync_drop($rel) {
    global $wpdb;
    $t       = lmeg_drops_table();
    $drop_id = (int) ($rel->drop_id ?? 0);
    if ($drop_id && !$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE id = %d", $drop_id))) $drop_id = 0;

    $slug = $drop_id
        ? ($wpdb->get_var($wpdb->prepare("SELECT slug FROM $t WHERE id = %d", $drop_id)) ?: lmeg_release_unique_slug($t, $rel->title, $drop_id))
        : lmeg_release_unique_slug($t, $rel->title);

    $links  = function_exists('lmeg_drop_parse_links') ? lmeg_drop_parse_links($rel->links) : [];
    $status = ($rel->status === 'draft') ? 'draft' : 'scheduled';

    $notify_tag_id = null;
    if (function_exists('lmeg_get_or_create_tag')) {
        $tag = lmeg_get_or_create_tag('drop:' . $slug, 'Drop: ' . $rel->title, true, '#d05fa2');
        if ($tag) $notify_tag_id = (int) $tag->id;
    }

    $data = [
        'title'         => (string) $rel->title,
        'slug'          => $slug,
        'cover_url'     => ($rel->artwork_url ?: null),
        'description'   => ($rel->description ?: null),
        'release_at'    => ($rel->release_at ?: null),
        'links'         => wp_json_encode($links),
        'notify_tag_id' => $notify_tag_id,
        'audience'      => 'notify',
        'status'        => $status,
    ];
    if ($drop_id) {
        $wpdb->update($t, $data, ['id' => $drop_id]);
    } else {
        $data['notify_count'] = 0;
        $data['created_at']   = current_time('mysql');
        $wpdb->insert($t, $data);
        $drop_id = (int) $wpdb->insert_id;
    }
    return $drop_id;
}

/** Create or update the real WP release page that embeds the drop block. */
function lmeg_release_sync_page($rel, $drop_slug) {
    $content = $drop_slug !== '' ? '[fanloop_drop slug="' . $drop_slug . '"]' : '[fanloop_drop]';
    $status  = ($rel->status === 'draft') ? 'draft' : 'publish';
    $page_id = (int) ($rel->page_id ?? 0);

    $postarr = [
        'post_title'   => ($rel->title ?: 'Release'),
        'post_content' => $content,
        'post_type'    => 'page',
        'post_status'  => $status,
    ];
    if ($page_id && function_exists('get_post') && get_post($page_id)) {
        $postarr['ID'] = $page_id;
        wp_update_post($postarr);
    } else {
        $page_id = (int) wp_insert_post($postarr);
    }
    return $page_id;
}

/** Create or update the single shop product whose variants are the formats. */
function lmeg_release_sync_product($rel) {
    global $wpdb;
    $t = $wpdb->prefix . 'lmeg_products';
    $product_id = (int) ($rel->product_id ?? 0);
    if ($product_id && !$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE id = %d", $product_id))) $product_id = 0;

    // Formats (one per line) → the product's comma-separated variants field.
    $formats  = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($rel->formats ?? ''))));
    $variants = implode(', ', $formats);

    $slug = $product_id
        ? ($wpdb->get_var($wpdb->prepare("SELECT slug FROM $t WHERE id = %d", $product_id)) ?: lmeg_release_unique_slug($t, $rel->title, $product_id))
        : lmeg_release_unique_slug($t, $rel->title);

    // Sellable only once released; a future release date becomes a pre-order.
    $status    = ($rel->status === 'released') ? 'active' : 'draft';
    $preorder  = (!empty($rel->release_at) && strtotime($rel->release_at) > time()) ? $rel->release_at : null;

    $data = [
        'title'       => (string) $rel->title,
        'slug'        => $slug,
        'description' => ($rel->description ?: null),
        'cover_url'   => ($rel->artwork_url ?: null),
        'variants'    => ($variants ?: null),
        'preorder_at' => $preorder,
        'status'      => $status,
    ];
    if ($product_id) {
        $wpdb->update($t, $data, ['id' => $product_id]);
    } else {
        $data['currency']   = 'USD';
        $data['type']       = 'digital';
        $data['processor']  = 'stripe';
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($t, $data);
        $product_id = (int) $wpdb->insert_id;
    }
    return $product_id;
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
