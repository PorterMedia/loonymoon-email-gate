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

if (!defined('LMEG_RELEASE_DB_VERSION')) define('LMEG_RELEASE_DB_VERSION', '5');

/** The page template release pages use — Elementor Full Width where Elementor
 *  is active, so the drop block renders full-bleed (no theme title band). */
function lmeg_release_page_template() {
    $tpl = defined('ELEMENTOR_VERSION') ? 'elementor_header_footer' : '';
    return apply_filters('lmeg_release_page_template', $tpl);
}

/** A clean, flat slug for a release page: the title slug, or title-lp when that
 *  bare slug is already used by another page (e.g. a self-titled album vs the
 *  artist's own page) — so we never get WordPress's "-3" fallbacks. */
function lmeg_release_page_slug($title, $exclude_id = 0) {
    global $wpdb;
    $base = sanitize_title($title) ?: 'release';
    $taken = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_name=%s AND post_status NOT IN ('trash','auto-draft') AND ID <> %d LIMIT 1",
        $base, (int) $exclude_id
    ));
    return $taken ? $base . '-lp' : $base;
}

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
        preview_url VARCHAR(600) NOT NULL DEFAULT '',
        apple_id BIGINT UNSIGNED DEFAULT NULL,
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

    // Backfill existing release pages: Elementor Full Width template + clean flat
    // slugs (fixing WordPress "-3" style fallbacks like loony-3 → loony-lp).
    $tpl  = lmeg_release_page_template();
    $rows = $wpdb->get_results("SELECT page_id, title FROM $t WHERE page_id IS NOT NULL AND page_id > 0");
    foreach ((array) $rows as $r) {
        $pid = (int) $r->page_id;
        if (!$pid || !get_post($pid)) continue;
        if ($tpl !== '') update_post_meta($pid, '_wp_page_template', $tpl);
        $want = lmeg_release_page_slug((string) $r->title, $pid);
        $cur  = get_post_field('post_name', $pid);
        if ($cur && $want && $cur !== $want) wp_update_post(['ID' => $pid, 'post_name' => $want]);
    }

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

/** Existing release for an Apple collection id — used so catalog imports don't duplicate. */
function lmeg_release_by_apple_id($apple_id) {
    global $wpdb;
    $apple_id = (int) $apple_id;
    if (!$apple_id) return null;
    $t = lmeg_releases_table();
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE apple_id = %d LIMIT 1", $apple_id));
}

/* -------------------------------------------------------------------------
 * Multi-service streaming links — from the artist + title, resolve Spotify
 * and Deezer to sit alongside the Apple Music link, so every release page
 * shows a button per service. Cached per (artist|title).
 * ---------------------------------------------------------------------- */

/** Deezer URL (keyless public API) for artist+title, or ''. Tries an album match
 *  first (albums/EPs), then falls back to a track search (singles) → its album. */
function lmeg_deezer_album_url($artist, $title) {
    $artist = trim((string) $artist); $title = trim((string) $title);
    if ($artist === '' || $title === '') return '';
    $match = function ($name) use ($artist) {
        $name = (string) $name;
        return $name !== '' && (stripos($name, $artist) !== false || stripos($artist, $name) !== false);
    };
    $get = function ($url) {
        $res = wp_remote_get($url, ['timeout' => 8, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) return [];
        $d = json_decode(wp_remote_retrieve_body($res), true);
        return $d['data'] ?? [];
    };
    // 1) Album match (albums / EPs).
    foreach ($get('https://api.deezer.com/search/album?limit=5&q=' . rawurlencode('artist:"' . $artist . '" album:"' . $title . '"')) as $al) {
        if (!empty($al['link']) && $match($al['artist']['name'] ?? '')) return (string) $al['link'];
    }
    // 2) Track fallback (singles) → the track's album, else the track link.
    foreach ($get('https://api.deezer.com/search?limit=8&q=' . rawurlencode($artist . ' ' . $title)) as $tr) {
        if (!$match($tr['artist']['name'] ?? '')) continue;
        if (!empty($tr['album']['link'])) return (string) $tr['album']['link'];
        if (!empty($tr['link']))          return (string) $tr['link'];
    }
    return '';
}

/** Spotify album URL via the site's Spotify app (client credentials), or ''. */
function lmeg_spotify_album_url($artist, $title) {
    $artist = trim((string) $artist); $title = trim((string) $title);
    if ($artist === '' || $title === '') return '';
    if (!function_exists('lmeg_spotify_configured') || !lmeg_spotify_configured()) return '';
    $r = lmeg_spotify_get('/search', ['q' => $artist . ' ' . $title, 'type' => 'album', 'limit' => 5]);
    if (is_wp_error($r) || !is_array($r)) return '';
    foreach (($r['albums']['items'] ?? []) as $al) {
        $an = $al['artists'][0]['name'] ?? '';
        if (!empty($al['external_urls']['spotify']) && stripos($an, $artist) !== false) return (string) $al['external_urls']['spotify'];
    }
    return '';
}

/** Streaming-links text (Spotify / Apple Music / Deezer) for a release — keeps
 *  any existing links, only fetching what's missing. Order: Spotify, Apple, Deezer. */
function lmeg_release_streaming_links($title, $apple_url = '', $existing = '') {
    $artist = function_exists('lmeg_artist') ? lmeg_artist() : '';
    $have = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) $existing) as $line) {
        if (strpos($line, '|') !== false) {
            list($l, $u) = array_map('trim', explode('|', $line, 2));
            if ($l !== '' && $u !== '') $have[$l] = $u;
        }
    }
    if ($apple_url !== '' && empty($have['Apple Music'])) $have['Apple Music'] = $apple_url;
    if ($artist !== '') {
        if (empty($have['Spotify'])) { $s = lmeg_spotify_album_url($artist, $title); if ($s) $have['Spotify'] = $s; }
        if (empty($have['Deezer']))  { $z = lmeg_deezer_album_url($artist, $title);   if ($z) $have['Deezer']  = $z; }
    }
    $out = [];
    foreach (['Spotify', 'Apple Music', 'Deezer'] as $l) if (!empty($have[$l])) { $out[] = $l . ' | ' . $have[$l]; unset($have[$l]); }
    foreach ($have as $l => $u) $out[] = $l . ' | ' . $u;
    return implode("\n", $out);
}

/** Backfill: add Spotify + Deezer links to every release (idempotent), and
 *  re-sync each drop so the release page buttons update. Returns count updated. */
function lmeg_release_refresh_links() {
    global $wpdb;
    $t = lmeg_releases_table();
    $updated = 0;
    foreach (lmeg_releases_all(500) as $r) {
        $apple = '';
        foreach (preg_split('/\r\n|\r|\n/', (string) $r->links) as $ln) {
            if (stripos($ln, 'apple') !== false && strpos($ln, '|') !== false) $apple = trim(explode('|', $ln, 2)[1]);
        }
        $new = lmeg_release_streaming_links($r->title, $apple, (string) $r->links);
        if ($new !== '' && $new !== (string) $r->links) {
            $wpdb->update($t, ['links' => $new, 'updated_at' => current_time('mysql')], ['id' => $r->id]);
            $rel = lmeg_release_get($r->id);
            if ($rel && !empty($rel->drop_id)) lmeg_release_sync_drop($rel);
            $updated++;
        }
    }
    return $updated;
}

function lmeg_release_status_label($s) {
    $m = ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'released' => 'Released'];
    return $m[$s] ?? ucfirst((string) $s);
}

/** The release (if any) that owns a given drop — used by the drop page's buy CTA. */
function lmeg_release_for_drop($drop_id) {
    global $wpdb;
    $drop_id = (int) $drop_id;
    if (!$drop_id) return null;
    $t = lmeg_releases_table();
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE drop_id = %d ORDER BY id DESC LIMIT 1", $drop_id));
}

/** The linked pieces (drop / page / product) with their view + edit URLs. */
function lmeg_release_linked($rel) {
    $out = [];
    if (!empty($rel->drop_id)) {
        $drop = function_exists('lmeg_drop_get') ? lmeg_drop_get((int) $rel->drop_id) : null;
        $out['drop'] = [
            'short' => 'Drop', 'label' => 'Drop',
            'edit'  => admin_url('admin.php?page=lmeg-drops&edit=' . (int) $rel->drop_id),
            'view'  => ($drop && function_exists('lmeg_drop_url')) ? lmeg_drop_url($drop) : null,
        ];
    }
    if (!empty($rel->page_id)) {
        $out['page'] = [
            'short' => 'Page', 'label' => 'Release page',
            'edit'  => function_exists('get_edit_post_link') ? get_edit_post_link((int) $rel->page_id, '') : null,
            'view'  => function_exists('get_permalink') ? get_permalink((int) $rel->page_id) : null,
        ];
    }
    if (!empty($rel->product_id)) {
        $out['product'] = [
            'short' => 'Shop', 'label' => 'Shop product',
            'edit'  => admin_url('admin.php?page=lmeg-products&edit=' . (int) $rel->product_id),
            'view'  => null,
        ];
    }
    return $out;
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
    if (($_POST['lmeg_release_action'] ?? '') === 'save' && check_admin_referer('lmeg_release_save', 'lmeg_release_nonce')) {
        lmeg_release_save_from_post();
        $notice = '<div class="notice notice-success"><p>Release saved.</p></div>';
        $_GET = [];
    }
    // Backfill Spotify + Deezer links across every release.
    if (($_POST['lmeg_release_action'] ?? '') === 'refresh_links' && check_admin_referer('lmeg_refresh_links', 'lmeg_refresh_nonce')) {
        $n = lmeg_release_refresh_links();
        $notice = '<div class="notice notice-success"><p>Added streaming links to <strong>' . (int) $n . '</strong> release(s) — Spotify &amp; Deezer now sit alongside Apple Music.</p></div>';
        $_GET = [];
    }

    // Catalog import — "find" re-renders the import screen; "import" builds them.
    $import_stage = false;
    if (isset($_POST['lmeg_import_action']) && check_admin_referer('lmeg_import', 'lmeg_import_nonce')) {
        if ($_POST['lmeg_import_action'] === 'import') {
            list($c, $s) = lmeg_release_import_selected();
            $notice = '<div class="notice notice-success"><p>Built <strong>' . (int) $c . '</strong> release' . ($c === 1 ? '' : 's')
                    . ($s ? ' &middot; skipped ' . (int) $s . ' already in Fanloop' : '')
                    . '. Each got a drop, a release page and a shop product.</p></div>';
            $_GET = [];
        } else {
            $import_stage = true; // 'find' or the artist switcher
        }
    }

    $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
    if ($import_stage) $action = 'import';
    $edit   = isset($_GET['edit']) ? lmeg_release_get((int) $_GET['edit']) : null;

    echo '<div class="wrap">';
    echo '<h1 style="display:flex;align-items:center;gap:12px;">Releases';
    if ($action !== 'new' && $action !== 'import' && !$edit) {
        echo ' <a href="' . esc_url(admin_url('admin.php?page=lmeg-releases&action=new')) . '" class="button button-primary">New release</a>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=lmeg-releases&action=import')) . '" class="button">Import from Apple Music</a>';
    }
    echo '</h1>';
    echo $notice;

    if ($action !== 'new' && $action !== 'import' && !$edit) {
        echo '<form method="post" style="margin:-4px 0 14px;">';
        wp_nonce_field('lmeg_refresh_links', 'lmeg_refresh_nonce');
        echo '<input type="hidden" name="lmeg_release_action" value="refresh_links">';
        echo '<button class="button" onclick="return confirm(\'Fetch Spotify + Deezer links for every release? This can take a moment.\');">&#8635; Refresh streaming links</button>';
        echo '<span class="description" style="margin-left:8px;">Adds Spotify &amp; Deezer buttons alongside Apple Music on every release.</span>';
        echo '</form>';
    }

    if ($action === 'import') {
        lmeg_releases_render_import();
    } elseif ($action === 'new' || $edit) {
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
        'preview_url' => esc_url_raw(wp_unslash($_POST['preview_url'] ?? '')),
        'release_at'  => $release_at,
        'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
        'links'       => sanitize_textarea_field(wp_unslash($_POST['links'] ?? '')),
        'formats'     => sanitize_textarea_field(wp_unslash($_POST['formats'] ?? '')),
        'status'      => in_array(($_POST['status'] ?? ''), ['draft', 'scheduled', 'released'], true) ? $_POST['status'] : 'draft',
        'updated_at'  => $now,
    ];
    $fmt = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

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

/* -------------------------------------------------------------------------
 * Catalog import — build releases straight from an Apple Music discography.
 * Each imported release runs the same cascade (drop + page + product).
 * ---------------------------------------------------------------------- */

/** Insert one release from a plain data array and run the full cascade. */
function lmeg_release_create_from_import(array $f) {
    global $wpdb;
    $t   = lmeg_releases_table();
    $now = current_time('mysql');
    $release_at = !empty($f['release_at']) ? date('Y-m-d H:i:s', strtotime($f['release_at'])) : null;

    $data = [
        'title'       => sanitize_text_field($f['title'] ?? ''),
        'artwork_url' => esc_url_raw($f['artwork_url'] ?? ''),
        'preview_url' => esc_url_raw($f['preview_url'] ?? ''),
        'apple_id'    => !empty($f['apple_id']) ? (int) $f['apple_id'] : null,
        'release_at'  => $release_at,
        'description' => sanitize_textarea_field($f['description'] ?? ''),
        'links'       => sanitize_textarea_field($f['links'] ?? ''),
        'formats'     => sanitize_textarea_field($f['formats'] ?? 'Digital'),
        'status'      => in_array(($f['status'] ?? ''), ['draft', 'scheduled', 'released'], true) ? $f['status'] : 'released',
        'created_at'  => $now,
        'updated_at'  => $now,
    ];
    $wpdb->insert($t, $data);
    $id = (int) $wpdb->insert_id;
    if (!$id) return 0;

    $rel = (object) array_merge($data, ['id' => $id]);
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

/** Import the checked releases from the choose-stage form. Returns [created, skipped]. */
function lmeg_release_import_selected() {
    $artist = sanitize_text_field(wp_unslash($_POST['artist_name'] ?? ''));
    $rows   = json_decode(wp_unslash($_POST['releases_json'] ?? '[]'), true);
    $picked = array_map('intval', (array) ($_POST['pick'] ?? []));
    if (!is_array($rows) || !$picked) return [0, 0];

    $created = 0; $skipped = 0;
    foreach ($rows as $r) {
        $aid = (int) ($r['apple_id'] ?? 0);
        if (!in_array($aid, $picked, true)) continue;
        if ($aid && lmeg_release_by_apple_id($aid)) { $skipped++; continue; }

        // Streaming links (Spotify / Apple Music / Deezer) + best-effort preview.
        $links   = lmeg_release_streaming_links($r['clean_title'] ?? ($r['title'] ?? ''), $r['url'] ?? '', '');
        $preview = '';
        if (function_exists('lmeg_itunes_search')) {
            $hits = lmeg_itunes_search(trim($artist . ' ' . ($r['clean_title'] ?? $r['title'] ?? '')), 3);
            if (!empty($hits[0]['preview_url'])) $preview = $hits[0]['preview_url'];
        }
        lmeg_release_create_from_import([
            'title'       => $r['clean_title'] ?? ($r['title'] ?? ''),
            'artwork_url' => $r['artwork'] ?? '',
            'preview_url' => $preview,
            'apple_id'    => $aid,
            'release_at'  => $r['release_date'] ?? '',
            'links'       => $links,
            'formats'     => 'Digital',
            'status'      => 'released',
        ]);
        $created++;
    }
    return [$created, $skipped];
}

/** The import screen — search an artist, then pick releases to build. */
function lmeg_releases_render_import() {
    if (function_exists('lmeg_media_enqueue')) lmeg_media_enqueue();
    $term      = sanitize_text_field(wp_unslash($_POST['artist_term'] ?? ($_GET['term'] ?? '')));
    $artist_id = (int) ($_POST['artist_id'] ?? 0);
    $did_find  = (($_POST['lmeg_import_action'] ?? '') === 'find');
    ?>
    <p style="margin-top:6px;"><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-releases')); ?>">&larr; All releases</a></p>
    <form method="post" style="max-width:760px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px 18px;margin-bottom:18px;">
        <?php wp_nonce_field('lmeg_import', 'lmeg_import_nonce'); ?>
        <input type="hidden" name="lmeg_import_action" value="find">
        <label style="font-weight:600;display:block;margin-bottom:6px;">Import a catalog from Apple Music</label>
        <div style="display:flex;gap:8px;">
            <input type="text" name="artist_term" class="regular-text" value="<?php echo esc_attr($term); ?>" placeholder="Artist name, or paste an Apple Music artist link" style="flex:1;">
            <button class="button button-primary">Find releases</button>
        </div>
        <p class="description" style="margin-top:8px;">Pulls the artist&rsquo;s releases from Apple Music. Pick which to build &mdash; each becomes a <strong>release page</strong>, a <strong>drop</strong>, and a <strong>shop product</strong>, with artwork, date, a streaming link and a preview.</p>
    </form>
    <?php
    if (!$did_find || $term === '') return;

    // A pasted Apple Music link (or raw id) resolves the artist directly — no
    // name-guessing, no candidate list. Otherwise fall back to a name search.
    $link_id = (!$artist_id) ? lmeg_itunes_artist_id_from_input($term) : 0;
    if ($link_id) {
        $artist_id   = $link_id;
        $artists     = [];
        $artist_name = lmeg_itunes_artist_name($artist_id) ?: 'this artist';
    } else {
        $artists = function_exists('lmeg_itunes_artist_search') ? lmeg_itunes_artist_search($term, 6) : [];
        if (!$artists) { echo '<div class="notice notice-warning"><p>No artist found for &ldquo;' . esc_html($term) . '&rdquo;. Check the spelling, or paste their Apple Music artist link.</p></div>'; return; }
        if (!$artist_id) $artist_id = (int) $artists[0]['id'];
        $artist_name = $artists[0]['name'];
        foreach ($artists as $a) { if ((int) $a['id'] === $artist_id) $artist_name = $a['name']; }
    }

    if (count($artists) > 1) {
        echo '<form method="post" style="max-width:760px;margin-bottom:14px;">';
        wp_nonce_field('lmeg_import', 'lmeg_import_nonce');
        echo '<input type="hidden" name="lmeg_import_action" value="find"><input type="hidden" name="artist_term" value="' . esc_attr($term) . '">';
        echo '<div style="font-weight:600;margin-bottom:6px;">Which ' . esc_html($term) . '?</div><div style="display:flex;flex-wrap:wrap;gap:8px;">';
        foreach ($artists as $a) {
            $on = (int) $a['id'] === $artist_id;
            echo '<button name="artist_id" value="' . (int) $a['id'] . '" class="button' . ($on ? ' button-primary' : '') . '">' . esc_html($a['name']) . ' <span style="opacity:.6;">· ' . esc_html($a['genre']) . '</span></button>';
        }
        echo '</div></form>';
    }

    $releases = function_exists('lmeg_itunes_artist_releases') ? lmeg_itunes_artist_releases($artist_id) : [];
    if (!$releases) { echo '<div class="notice notice-warning"><p>No releases found for ' . esc_html($artist_name) . '.</p></div>'; return; }

    $new_count = 0;
    foreach ($releases as $r) { if (!lmeg_release_by_apple_id((int) $r['apple_id'])) $new_count++; }
    ?>
    <form method="post" style="max-width:920px;">
        <?php wp_nonce_field('lmeg_import', 'lmeg_import_nonce'); ?>
        <input type="hidden" name="lmeg_import_action" value="import">
        <input type="hidden" name="artist_name" value="<?php echo esc_attr($artist_name); ?>">
        <input type="hidden" name="releases_json" value="<?php echo esc_attr(wp_json_encode($releases)); ?>">
        <div style="display:flex;align-items:center;justify-content:space-between;margin:2px 0 10px;">
            <strong><?php echo count($releases); ?> releases for <?php echo esc_html($artist_name); ?> &middot; <?php echo (int) $new_count; ?> new</strong>
            <button class="button button-primary button-hero">Build selected in Fanloop</button>
        </div>
        <table class="widefat striped">
            <thead><tr><th style="width:32px;"></th><th style="width:52px;"></th><th>Release</th><th>Type</th><th>Released</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($releases as $r) :
                $exists = lmeg_release_by_apple_id((int) $r['apple_id']);
                $art = $r['artwork'] ? '<img src="' . esc_url($r['artwork']) . '" style="width:40px;height:40px;border-radius:6px;object-fit:cover;display:block;border:1px solid #e5e7eb">' : ''; ?>
                <tr>
                    <td><?php echo $exists ? '' : '<input type="checkbox" name="pick[]" value="' . (int) $r['apple_id'] . '" checked>'; ?></td>
                    <td><?php echo $art; ?></td>
                    <td><strong><?php echo esc_html($r['clean_title']); ?></strong></td>
                    <td><?php echo esc_html($r['kind']); ?></td>
                    <td><?php echo esc_html($r['release_date']); ?></td>
                    <td><?php echo $exists ? '<span style="color:#15803d;font-weight:600;">✓ already built</span>' : '<span style="color:#6b7280;">new</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:12px;"><button class="button button-primary button-hero">Build selected in Fanloop</button></p>
    </form>
    <?php
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
        'post_name'    => lmeg_release_page_slug(($rel->title ?: 'Release'), (int) $page_id),
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
    // Full-width Elementor template so the drop renders full-bleed.
    $tpl = lmeg_release_page_template();
    if ($page_id && $tpl !== '') update_post_meta($page_id, '_wp_page_template', $tpl);
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
        'preview_url' => (string) ($rel->preview_url ?? ''),
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
        $linked = lmeg_release_linked($r);
        if ($linked) {
            $parts = [];
            foreach ($linked as $p) {
                $parts[] = !empty($p['edit'])
                    ? '<a href="' . esc_url($p['edit']) . '">' . esc_html($p['short']) . '</a>'
                    : esc_html($p['short']);
            }
            $linkedTxt = implode(' · ', $parts);
        } else {
            $linkedTxt = '<span style="color:#9ca3af">not yet</span>';
        }
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
    $r = $edit ?: (object) ['id' => 0, 'title' => '', 'artwork_url' => '', 'preview_url' => '', 'release_at' => null, 'description' => '', 'links' => '', 'formats' => "Digital\nCD\nVinyl", 'status' => 'draft'];
    $release_local = $r->release_at ? date('Y-m-d\TH:i', strtotime($r->release_at)) : '';
    ?>
    <p style="margin-top:6px;"><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-releases')); ?>">&larr; All releases</a></p>
    <?php $linked = $edit ? lmeg_release_linked($edit) : []; if ($linked): ?>
        <div style="max-width:720px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:12px 16px;margin-bottom:16px;">
            <strong style="display:block;margin-bottom:8px;">Connected pieces</strong>
            <div style="display:flex;flex-wrap:wrap;gap:18px;">
                <?php foreach ($linked as $p): ?>
                    <div>
                        <span style="font-weight:600;"><?php echo esc_html($p['label']); ?></span>
                        <span style="margin-left:6px;">
                            <?php if (!empty($p['view'])): ?><a href="<?php echo esc_url($p['view']); ?>" target="_blank" rel="noopener">View</a><?php endif; ?>
                            <?php if (!empty($p['view']) && !empty($p['edit'])): ?><span style="color:#c7cbd1;"> | </span><?php endif; ?>
                            <?php if (!empty($p['edit'])): ?><a href="<?php echo esc_url($p['edit']); ?>">Edit</a><?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
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
            <tr><th><label for="r_preview_url">Audio preview</label></th><td>
                <input type="url" name="preview_url" id="r_preview_url" class="regular-text" value="<?php echo esc_attr($r->preview_url ?? ''); ?>" placeholder="https://… 30-second clip (.m4a / .mp3)">
                <?php echo lmeg_preview_finder_html($r->title, 'preview_url'); ?>
                <p class="description">A 30-second clip fans can play on the release page &mdash; and it auto-fills the same preview on the shop product. <strong>Find preview</strong> pulls the official Apple Music clip; or paste your own URL.</p>
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
