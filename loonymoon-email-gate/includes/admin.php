<?php
/**
 * Admin pages: Subscribers, Compose, Broadcast History, Settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------------------------
 * Menu
 * ------------------------------------------------------------------------- */

add_action('admin_menu', 'lmeg_admin_menu');
function lmeg_admin_menu() {
    $cap = 'manage_options';
    add_menu_page('Email Gate', 'Email Gate', $cap, 'lmeg',           'lmeg_admin_subscribers', 'dashicons-email-alt', 30);
    add_submenu_page('lmeg', 'Overview',          'Overview',          $cap, 'lmeg-overview',        'lmeg_admin_overview');
    add_submenu_page('lmeg', 'Subscribers',       'Subscribers',       $cap, 'lmeg',                 'lmeg_admin_subscribers');
    add_submenu_page('lmeg', 'Audience',          'Audience',          $cap, 'lmeg-audience',        'lmeg_admin_audience');
    add_submenu_page('lmeg', 'Smartlinks',        'Smartlinks',        $cap, 'lmeg-smartlinks',      'lmeg_admin_smartlinks');
    add_submenu_page('lmeg', 'Members (Paid)',    'Members (Paid)',    $cap, 'lmeg-members',         'lmeg_admin_members');
    add_submenu_page('lmeg', 'Tags',              'Tags',              $cap, 'lmeg-tags',            'lmeg_admin_tags');
    add_submenu_page('lmeg', 'Segments',          'Segments',          $cap, 'lmeg-segments',        'lmeg_admin_segments');
    add_submenu_page('lmeg', 'Templates',         'Templates',         $cap, 'lmeg-templates',       'lmeg_admin_templates');
    add_submenu_page('lmeg', 'Sequences',         'Sequences',         $cap, 'lmeg-sequences',       'lmeg_admin_sequences');
    add_submenu_page('lmeg', 'Tiers (Paid)',      'Tiers (Paid)',      $cap, 'lmeg-tiers',           'lmeg_admin_tiers');
    add_submenu_page('lmeg', 'Compose Broadcast', 'Compose Broadcast', $cap, 'lmeg-compose',         'lmeg_admin_compose');
    add_submenu_page('lmeg', 'Broadcast History', 'Broadcast History', $cap, 'lmeg-broadcasts',      'lmeg_admin_broadcasts');
    add_submenu_page('lmeg', 'Shop Revenue',      'Shop Revenue',      $cap, 'lmeg-shop',            'lmeg_admin_shop');
    add_submenu_page('lmeg', 'Settings',          'Settings',          $cap, 'lmeg-settings',        'lmeg_admin_settings');
}

add_action('admin_enqueue_scripts', 'lmeg_admin_assets');
function lmeg_admin_assets($hook) {
    if (strpos((string) $hook, 'lmeg') === false) return;
    wp_enqueue_style('lmeg-admin-font', 'https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap', [], null);
    wp_enqueue_style('lmeg-admin', LMEG_PLUGIN_URL . 'assets/admin.css', ['lmeg-admin-font'], LMEG_VERSION);

    // Drag-and-drop email builder — only on the Compose page.
    if (strpos((string) $hook, 'lmeg-compose') !== false) {
        wp_enqueue_style('lmeg-builder', LMEG_PLUGIN_URL . 'assets/builder.css', ['lmeg-admin'], LMEG_VERSION);
        wp_enqueue_script('lmeg-builder', LMEG_PLUGIN_URL . 'assets/builder.js', [], LMEG_VERSION, true);
    }

    // Belt-and-suspenders: some plugins clobber the admin_body_class filter
    // (returning their own string instead of appending), which would strip
    // our scope class and leave the dark theme dormant. Re-add it in JS the
    // moment the DOM exists — this cannot be filtered away.
    wp_add_inline_script('jquery-core', "document.addEventListener('DOMContentLoaded',function(){document.body.classList.add('lmeg-admin');});");
    // Media picker for the logo field.
    wp_enqueue_media();
    wp_add_inline_script('jquery-core', "
        jQuery(function($){
            var frame;
            $(document).on('click', '#lmeg-pick-logo', function(e){
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({ title: 'Choose a logo', button: { text: 'Use this' }, multiple: false });
                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    $('#logo_url').val(att.url);
                });
                frame.open();
            });
        });
    ");
}

/**
 * Scope the premium dark theme to our pages only via a body class.
 */
add_filter('admin_body_class', 'lmeg_admin_body_class', PHP_INT_MAX);
function lmeg_admin_body_class($classes) {
    if (!empty($_GET['page']) && strpos((string) $_GET['page'], 'lmeg') === 0) {
        $classes .= ' lmeg-admin';
    }
    return $classes;
}

/**
 * App-style header bar rendered above every plugin page — brand mark +
 * primary nav pills. Injected once via in_admin_header, no per-page edits.
 */
add_action('in_admin_header', 'lmeg_admin_app_bar');
function lmeg_admin_app_bar() {
    if (empty($_GET['page']) || strpos((string) $_GET['page'], 'lmeg') !== 0) return;
    $current = sanitize_text_field($_GET['page']);
    $items = [
        'lmeg-overview'   => 'Overview',
        'lmeg'            => 'Fans',
        'lmeg-audience'   => 'Audience',
        'lmeg-compose'    => 'Compose',
        'lmeg-broadcasts' => 'Broadcasts',
        'lmeg-shop'       => 'Revenue',
        'lmeg-members'    => 'Members',
        'lmeg-spotify'    => 'Spotify',
        'lmeg-ai'         => 'Ask AI',
        'lmeg-settings'   => 'Settings',
    ];
    ?>
    <div class="lmeg-appbar">
        <a class="lmeg-appbar__brand" href="<?php echo esc_url(admin_url('admin.php?page=lmeg-overview')); ?>">
            <span class="lmeg-appbar__dot" aria-hidden="true"></span>
            loonybin
        </a>
        <nav class="lmeg-appbar__nav" aria-label="Loonybin sections">
            <?php foreach ($items as $slug => $label) : ?>
                <a class="lmeg-appbar__link<?php echo $current === $slug ? ' is-active' : ''; ?>"
                   href="<?php echo esc_url(admin_url('admin.php?page=' . $slug)); ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </nav>
        <a class="lmeg-appbar__site" href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener">View site ↗</a>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Tag chip — render helper used across admin views
 * ------------------------------------------------------------------------- */

/**
 * Small inline SVG icon (Lucide-style, 14px, currentColor) per tag family.
 * Returns '' when no icon applies (manual tags fall back to a colored dot).
 */
function lmeg_tag_icon_svg($slug) {
    $slug = (string) $slug;
    $svg = function ($inner) {
        return '<svg class="lmeg-chip__ico" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
    };
    if (strpos($slug, 'channel:email') === 0) return $svg('<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>');
    if (strpos($slug, 'channel:phone') === 0) return $svg('<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>');
    if (strpos($slug, 'channel:paid')  === 0) return $svg('<path d="M12 2l2.4 7.4H22l-6 4.6 2.3 7.4-6.3-4.6L5.7 21 8 14 2 9.4h7.6z"/>'); // star
    if (strpos($slug, 'tier:')         === 0) return $svg('<path d="M12 2l2.4 7.4H22l-6 4.6 2.3 7.4-6.3-4.6L5.7 21 8 14 2 9.4h7.6z"/>');
    if (strpos($slug, 'fan-type:superfan') === 0) return $svg('<path d="M12 2l2.4 7.4H22l-6 4.6 2.3 7.4-6.3-4.6L5.7 21 8 14 2 9.4h7.6z"/>');
    if (strpos($slug, 'fan-type:engaged')  === 0) return $svg('<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/>'); // heart
    if (strpos($slug, 'fan-type:casual')   === 0) return $svg('<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>'); // user
    if (strpos($slug, 'fan-type:dormant')  === 0) return $svg('<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9z"/>'); // moon
    if (strpos($slug, 'has-address')   === 0) return $svg('<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>'); // home
    if (strpos($slug, 'customer')      === 0) return $svg('<circle cx="9" cy="21" r="1"/><circle cx="18" cy="21" r="1"/><path d="M1 1h4l2.6 13H19l2-8H6"/>'); // cart
    return '';
}

function lmeg_render_tag_chip($tag, $opts = []) {
    $color = esc_attr($tag->color ?? '#6b7280');
    $slug  = (string) ($tag->slug ?? '');
    $raw   = (string) ($tag->name ?? $slug);

    // Short label: country → ISO code + flag; else strip the "Family: " prefix.
    $is_country = strpos($slug, 'country:') === 0;
    if ($is_country) {
        $iso   = strtoupper(substr($slug, 8));
        $label = trim(lmeg_flag_emoji($iso) . ' ' . $iso);
        $icon  = ''; // the flag emoji is the glyph
    } else {
        $label = (strpos($raw, ': ') !== false) ? substr($raw, strpos($raw, ': ') + 2) : $raw;
        $icon  = lmeg_tag_icon_svg($slug);
    }

    // Hide the default dot whenever there's a glyph (svg icon OR the flag).
    $has_glyph = ($icon || $is_country) ? ' lmeg-chip--icon' : '';
    $auto_cls  = !empty($tag->is_auto) ? ' lmeg-chip--auto' : '';
    $title     = esc_attr($raw); // full label on hover
    $count     = isset($opts['count']) ? ' <span class="lmeg-chip__count">' . (int) $opts['count'] . '</span>' : '';

    return '<span class="lmeg-chip' . $auto_cls . $has_glyph . '" style="--lmeg-chip-color:' . $color . ';" title="' . $title . '">'
         . $icon . esc_html($label) . $count . '</span>';
}

/* ---------------------------------------------------------------------------
 * Tags admin page
 * ------------------------------------------------------------------------- */

function lmeg_admin_tags() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $tags_tbl = $wpdb->prefix . 'lmeg_tags';
    $notice = '';

    if (isset($_POST['lmeg_tags_nonce']) && wp_verify_nonce($_POST['lmeg_tags_nonce'], 'lmeg_tags')) {
        $act = sanitize_text_field($_POST['lmeg_action'] ?? '');
        if ($act === 'create') {
            $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $color = sanitize_hex_color(wp_unslash($_POST['color'] ?? '')) ?: '#6b7280';
            if ($name) {
                lmeg_get_or_create_tag($name, $name, false, $color);
                $notice = '<div class="notice notice-success"><p>Tag created.</p></div>';
            }
        } elseif ($act === 'update') {
            $id    = (int) ($_POST['tag_id'] ?? 0);
            $name  = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $color = sanitize_hex_color(wp_unslash($_POST['color'] ?? '')) ?: '#6b7280';
            if ($id && $name) {
                $wpdb->update($tags_tbl, ['name' => $name, 'color' => $color], ['id' => $id]);
                $notice = '<div class="notice notice-success"><p>Tag updated.</p></div>';
            }
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['tag_id'] ?? 0);
            if ($id) {
                $wpdb->delete($tags_tbl, ['id' => $id]);
                $wpdb->delete($wpdb->prefix . 'lmeg_subscriber_tags', ['tag_id' => $id]);
                $notice = '<div class="notice notice-success"><p>Tag deleted.</p></div>';
            }
        }
    }

    $rows = lmeg_all_tags();
    ?>
    <div class="wrap">
        <h1>Email Gate — Tags</h1>
        <?php echo $notice; ?>
        <p>Tags group subscribers into segments. <strong>Auto</strong> tags (channel, country, has-address) are managed by the plugin — you can rename or recolor them, but their membership updates automatically when subscribers change.</p>

        <h2>Create a tag</h2>
        <form method="post" style="margin-bottom:24px;">
            <?php wp_nonce_field('lmeg_tags', 'lmeg_tags_nonce'); ?>
            <input type="hidden" name="lmeg_action" value="create" />
            <input type="text"  name="name"  placeholder="Tag name" class="regular-text" required />
            <input type="color" name="color" value="#6b7280" />
            <button type="submit" class="button button-primary">Add tag</button>
        </form>

        <h2>All tags</h2>
        <table class="widefat striped lmeg-tags-table">
            <thead><tr><th>Tag</th><th>Slug</th><th>Members</th><th>Type</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="5">No tags yet. Auto-tags appear here as soon as your first subscriber arrives.</td></tr>
            <?php else : foreach ($rows as $t) : ?>
                <tr>
                    <td>
                        <form method="post" style="display:flex;gap:8px;align-items:center;">
                            <?php wp_nonce_field('lmeg_tags', 'lmeg_tags_nonce'); ?>
                            <input type="hidden" name="lmeg_action" value="update" />
                            <input type="hidden" name="tag_id" value="<?php echo (int) $t->id; ?>" />
                            <?php echo lmeg_render_tag_chip($t); ?>
                            <input type="text"  name="name"  value="<?php echo esc_attr($t->name); ?>" />
                            <input type="color" name="color" value="<?php echo esc_attr($t->color); ?>" />
                            <button type="submit" class="button button-small">Save</button>
                        </form>
                    </td>
                    <td><code><?php echo esc_html($t->slug); ?></code></td>
                    <td><?php echo (int) $t->member_count; ?></td>
                    <td><?php echo $t->is_auto ? '<em>auto</em>' : 'manual'; ?></td>
                    <td>
                        <?php if (!$t->is_auto) : ?>
                            <form method="post" onsubmit="return confirm('Delete this tag? Members will lose this tag but stay subscribed.');" style="display:inline;">
                                <?php wp_nonce_field('lmeg_tags', 'lmeg_tags_nonce'); ?>
                                <input type="hidden" name="lmeg_action" value="delete" />
                                <input type="hidden" name="tag_id" value="<?php echo (int) $t->id; ?>" />
                                <button type="submit" class="button button-link-delete">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Subscribers
 * ------------------------------------------------------------------------- */

function lmeg_admin_subscribers() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $table = $wpdb->prefix . LMEG_TABLE;
    $notice = '';

    // Fan profile detail view.
    if (!empty($_GET['fan'])) {
        lmeg_admin_fan_profile((int) $_GET['fan']);
        return;
    }

    // Handle bulk actions — tag ops and manual tier grants.
    if (isset($_POST['lmeg_subs_nonce']) && wp_verify_nonce($_POST['lmeg_subs_nonce'], 'lmeg_subs')) {
        $action = sanitize_text_field($_POST['bulk_action'] ?? '');
        $ids    = array_filter(array_map('intval', (array) ($_POST['sub_ids'] ?? [])));
        $tag_id = (int) ($_POST['bulk_tag_id'] ?? 0);
        $tier_id = (int) ($_POST['bulk_tier_id'] ?? 0);

        if ($ids && in_array($action, ['add_tag', 'remove_tag'], true) && $tag_id) {
            foreach ($ids as $sid) {
                if ($action === 'add_tag')    lmeg_attach_tag($sid, $tag_id);
                if ($action === 'remove_tag') lmeg_detach_tag($sid, $tag_id);
            }
            $notice = '<div class="notice notice-success"><p>'
                    . esc_html(count($ids)) . ' subscriber'
                    . (count($ids) === 1 ? '' : 's')
                    . ' tagged.</p></div>';
        } elseif ($ids && $action === 'grant_tier' && $tier_id) {
            // Manual comp — no Stripe involved. member_status='active' with
            // NULL stripe_subscription_id means the tier is admin-granted.
            foreach ($ids as $sid) {
                $wpdb->update($table, [
                    'member_tier_id'    => $tier_id,
                    'member_status'     => 'active',
                    'member_expires_at' => null,
                ], ['id' => $sid]);
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $sid));
                if ($row) lmeg_apply_auto_tags($row);
            }
            $notice = '<div class="notice notice-success"><p>Granted tier to ' . count($ids) . ' subscriber' . (count($ids) === 1 ? '' : 's') . '. Note: Stripe subscriptions are unaffected — this is a manual comp.</p></div>';
        } elseif ($ids && $action === 'revoke_tier') {
            foreach ($ids as $sid) {
                $wpdb->update($table, [
                    'member_tier_id'    => null,
                    'member_status'     => 'free',
                    'member_expires_at' => null,
                ], ['id' => $sid]);
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $sid));
                if ($row) lmeg_apply_auto_tags($row);
            }
            $notice = '<div class="notice notice-warning"><p>Revoked tier from ' . count($ids) . ' subscriber' . (count($ids) === 1 ? '' : 's') . '. Stripe subscriptions are NOT cancelled — do that in the Stripe dashboard if needed.</p></div>';
        }
    }

    // Optional filter: ?tag=<slug>
    $filter_tag_slug = isset($_GET['tag']) ? sanitize_text_field($_GET['tag']) : '';
    $filter_tag      = null;
    if ($filter_tag_slug) {
        $filter_tag = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lmeg_tags WHERE slug = %s", $filter_tag_slug));
    }
    // Optional filter: ?status=free|paid|unsubscribed
    $filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    if (!in_array($filter_status, ['free', 'paid', 'unsubscribed'], true)) {
        $filter_status = '';
    }

    $where = '1=1';
    if ($filter_tag) {
        $where .= $wpdb->prepare(' AND id IN (SELECT subscriber_id FROM ' . $wpdb->prefix . 'lmeg_subscriber_tags WHERE tag_id = %d)', $filter_tag->id);
    }
    if ($filter_status === 'paid') {
        $where .= " AND member_status = 'active' AND member_tier_id IS NOT NULL AND unsubscribed_at IS NULL";
    } elseif ($filter_status === 'free') {
        $where .= " AND (member_tier_id IS NULL OR member_status <> 'active') AND unsubscribed_at IS NULL";
    } elseif ($filter_status === 'unsubscribed') {
        $where .= " AND unsubscribed_at IS NOT NULL";
    }

    $rows       = $wpdb->get_results("SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT 500");
    $total      = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $active_em  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE email IS NOT NULL AND email <> '' AND unsubscribed_at IS NULL");
    $active_ph  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE phone IS NOT NULL AND phone <> '' AND unsubscribed_at IS NULL");
    $unsub_n    = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE unsubscribed_at IS NOT NULL");
    $export_url = wp_nonce_url(admin_url('admin-post.php?action=lmeg_export'), 'lmeg_export');
    $all_tags   = lmeg_all_tags();

    // Pre-compute tags per visible subscriber to avoid N+1 queries.
    $row_ids = array_map(function ($r) { return (int) $r->id; }, $rows);
    $tags_by_sub = [];
    if ($row_ids) {
        $placeholders = implode(',', array_fill(0, count($row_ids), '%d'));
        $sql = "SELECT st.subscriber_id, t.* FROM {$wpdb->prefix}lmeg_subscriber_tags st
                JOIN {$wpdb->prefix}lmeg_tags t ON t.id = st.tag_id
                WHERE st.subscriber_id IN ($placeholders)";
        foreach ($wpdb->get_results($wpdb->prepare($sql, $row_ids)) as $tr) {
            $tags_by_sub[(int) $tr->subscriber_id][] = $tr;
        }
    }
    echo $notice;
    ?>
    <div class="wrap">
        <h1>Email Gate — Subscribers</h1>
        <p>
            <strong><?php echo number_format_i18n($total); ?></strong> total —
            <?php echo number_format_i18n($active_em); ?> active email,
            <?php echo number_format_i18n($active_ph); ?> active SMS,
            <?php echo number_format_i18n($unsub_n); ?> unsubscribed.
            <a href="<?php echo esc_url($export_url); ?>" class="button button-primary">Export all as CSV</a>
        </p>

        <?php
        $all_tiers_admin = function_exists('lmeg_all_tiers') ? lmeg_all_tiers() : [];
        $base_url = admin_url('admin.php?page=lmeg');
        ?>
        <ul class="subsubsub">
            <li><a href="<?php echo esc_url($base_url); ?>" class="<?php echo $filter_status === '' && !$filter_tag ? 'current' : ''; ?>">All</a> |</li>
            <li><a href="<?php echo esc_url(add_query_arg('status','free',$base_url)); ?>" class="<?php echo $filter_status === 'free' ? 'current' : ''; ?>">Free</a> |</li>
            <li><a href="<?php echo esc_url(add_query_arg('status','paid',$base_url)); ?>" class="<?php echo $filter_status === 'paid' ? 'current' : ''; ?>">Paid</a> |</li>
            <li><a href="<?php echo esc_url(add_query_arg('status','unsubscribed',$base_url)); ?>" class="<?php echo $filter_status === 'unsubscribed' ? 'current' : ''; ?>">Unsubscribed</a></li>
        </ul>
        <div style="clear:both;"></div>

        <?php if ($filter_tag) : ?>
            <p>Filtered by tag: <?php echo lmeg_render_tag_chip($filter_tag); ?>
               <a href="<?php echo esc_url($base_url); ?>">Clear filter</a></p>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('lmeg_subs', 'lmeg_subs_nonce'); ?>
            <div class="tablenav top">
                <div class="alignleft actions bulkactions" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                    <select name="bulk_action">
                        <option value="">Bulk action…</option>
                        <optgroup label="Tags">
                            <option value="add_tag">Add tag…</option>
                            <option value="remove_tag">Remove tag…</option>
                        </optgroup>
                        <?php if ($all_tiers_admin) : ?>
                            <optgroup label="Paid membership">
                                <option value="grant_tier">Grant tier (comp)…</option>
                                <option value="revoke_tier">Revoke tier / membership</option>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                    <select name="bulk_tag_id">
                        <option value="">— pick a tag —</option>
                        <?php foreach ($all_tags as $t) : ?>
                            <option value="<?php echo (int) $t->id; ?>"><?php echo esc_html($t->name); ?> (<?php echo (int) $t->member_count; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($all_tiers_admin) : ?>
                        <select name="bulk_tier_id">
                            <option value="">— pick a tier —</option>
                            <?php foreach ($all_tiers_admin as $t) : ?>
                                <option value="<?php echo (int) $t->id; ?>"><?php echo esc_html($t->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <button type="submit" class="button">Apply</button>
                </div>
            </div>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" id="lmeg-check-all" /></th>
                        <th>Status</th>
                        <th>Type</th>
                        <th>Contact</th>
                        <th>Tags</th>
                        <th>Post</th>
                        <th>Captured</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="7">No submissions yet.</td></tr>
                <?php else : foreach ($rows as $r) :
                    $title   = $r->post_id ? get_the_title($r->post_id) : '—';
                    $link    = $r->post_id ? get_permalink($r->post_id) : '';
                    $contact = $r->contact_type === 'phone' ? $r->phone : $r->email;
                    $status_dot = $r->unsubscribed_at
                        ? '<span title="Unsubscribed ' . esc_attr($r->unsubscribed_at) . '" style="color:#a00;">● Unsubscribed</span>'
                        : '<span style="color:#1a6f1a;">● Active</span>';
                ?>
                    <tr<?php echo $r->unsubscribed_at ? ' style="opacity:.55;"' : ''; ?>>
                        <td class="check-column"><input type="checkbox" name="sub_ids[]" value="<?php echo (int) $r->id; ?>" /></td>
                        <td><?php echo $status_dot; ?></td>
                        <td><?php
                            $is_sms = $r->contact_type === 'phone';
                            $ico = $is_sms
                                ? '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg>'
                                : '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>';
                            echo '<span class="lmeg-type">' . $ico . '<span>' . ($is_sms ? 'SMS' : 'Email') . '</span></span>';
                        ?></td>
                        <td><a href="<?php echo esc_url(add_query_arg(['page' => 'lmeg', 'fan' => (int) $r->id], admin_url('admin.php'))); ?>"><strong><?php echo esc_html($contact ?: '—'); ?></strong></a></td>
                        <td>
                            <?php $tags = $tags_by_sub[(int) $r->id] ?? []; ?>
                            <?php if ($tags) : ?>
                                <div class="lmeg-chips">
                                    <?php foreach ($tags as $t) : ?>
                                        <a href="<?php echo esc_url(add_query_arg('tag', $t->slug, admin_url('admin.php?page=lmeg'))); ?>" style="text-decoration:none;">
                                            <?php echo lmeg_render_tag_chip($t); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else : ?>
                                <span style="color:#999;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($link) : ?>
                                <a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener"><?php echo esc_html($title); ?></a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><span title="<?php echo esc_attr($r->created_at); ?>"><?php echo esc_html(date_i18n('M j, Y', strtotime($r->created_at))); ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </form>
        <script>
        (function(){
            var all = document.getElementById('lmeg-check-all');
            if (!all) return;
            all.addEventListener('change', function(){
                document.querySelectorAll('input[name="sub_ids[]"]').forEach(function(c){ c.checked = all.checked; });
            });
        })();
        </script>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Compose & send
 * ------------------------------------------------------------------------- */

function lmeg_admin_compose() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $subs_tbl = $wpdb->prefix . LMEG_TABLE;

    $notice = '';
    $vals = [
        'subject'          => '',
        'body_email'       => '',
        'body_email_blocks'=> '',
        'body_sms'         => '',
        'tag_ids'          => [],
        'tag_match'        => 'any',
    ];

    if (isset($_POST['lmeg_action']) && check_admin_referer('lmeg_compose', 'lmeg_compose_nonce')) {
        $vals['subject']    = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $vals['body_email'] = wp_kses_post(wp_unslash($_POST['body_email'] ?? ''));
        // Builder block JSON is round-tripped verbatim (client-generated, re-encoded server-side for safety).
        $blocks_raw = wp_unslash($_POST['body_email_blocks'] ?? '');
        $decoded    = json_decode($blocks_raw, true);
        $vals['body_email_blocks'] = is_array($decoded) ? wp_json_encode($decoded) : '';
        $vals['body_sms']   = sanitize_textarea_field(wp_unslash($_POST['body_sms'] ?? ''));
        $vals['tag_ids']    = array_filter(array_map('intval', (array) ($_POST['tag_ids'] ?? [])));
        $vals['tag_match']  = ($_POST['tag_match'] ?? 'any') === 'all' ? 'all' : 'any';

        switch ($_POST['lmeg_action']) {
            case 'send_test_email':
                $to = sanitize_email(wp_unslash($_POST['test_to_email'] ?? ''));
                $r  = lmeg_send_test('email', $to, $vals['subject'], $vals['body_email']);
                $notice = is_wp_error($r)
                    ? '<div class="notice notice-error"><p>Email test failed: ' . esc_html($r->get_error_message()) . '</p></div>'
                    : '<div class="notice notice-success"><p>Email test sent to ' . esc_html($to) . '.</p></div>';
                break;

            case 'send_test_sms':
                $to = sanitize_text_field(wp_unslash($_POST['test_to_sms'] ?? ''));
                $r  = lmeg_send_test('sms', $to, '', $vals['body_sms']);
                $notice = is_wp_error($r)
                    ? '<div class="notice notice-error"><p>SMS test failed: ' . esc_html($r->get_error_message()) . '</p></div>'
                    : '<div class="notice notice-success"><p>SMS test sent to ' . esc_html($to) . '.</p></div>';
                break;

            case 'send_broadcast':
                // Parse the optional schedule input. Browser <input type="datetime-local">
                // gives us "2026-06-01T15:30" — we normalize to MySQL DATETIME in site time.
                $sched_raw = sanitize_text_field(wp_unslash($_POST['scheduled_for'] ?? ''));
                $scheduled = null;
                if ($sched_raw !== '') {
                    $ts = strtotime(str_replace('T', ' ', $sched_raw));
                    if ($ts && $ts > current_time('timestamp')) {
                        $scheduled = date('Y-m-d H:i:s', $ts);
                    } elseif ($ts) {
                        $notice = '<div class="notice notice-error"><p>Scheduled time is in the past — pick a future moment.</p></div>';
                        break;
                    }
                }

                $bid = lmeg_queue_broadcast([
                    'subject'        => $vals['subject'],
                    'body_email'     => $vals['body_email'],
                    'body_sms'       => $vals['body_sms'],
                    'tag_filter'     => [
                        'tag_ids' => $vals['tag_ids'],
                        'match'   => $vals['tag_match'],
                    ],
                    'scheduled_for'  => $scheduled,
                ]);
                if (is_wp_error($bid)) {
                    $notice = '<div class="notice notice-error"><p>' . esc_html($bid->get_error_message()) . '</p></div>';
                } else {
                    $url = admin_url('admin.php?page=lmeg-broadcasts');
                    $when = $scheduled ? 'scheduled for ' . esc_html($scheduled) : 'queued for immediate send';
                    $notice = '<div class="notice notice-success"><p>Broadcast ' . $when . '. <a href="' . esc_url($url) . '">View progress →</a></p></div>';
                }
                break;
        }
    }

    // Counts for the audience hint — active subscribers segmented by signup channel.
    $count_email = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs_tbl WHERE contact_type = 'email' AND email IS NOT NULL AND email <> '' AND unsubscribed_at IS NULL");
    $count_sms   = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs_tbl WHERE contact_type = 'phone' AND phone IS NOT NULL AND phone <> '' AND unsubscribed_at IS NULL");

    $all_tags    = lmeg_all_tags();
    $ajax_nonce  = wp_create_nonce('lmeg_audience');
    ?>
    <div class="wrap">
        <h1>Email Gate — Compose Broadcast</h1>
        <?php echo $notice; ?>
        <p>
            Auto-routes per subscriber.
            <strong><?php echo $count_email; ?></strong> active email subscribers ·
            <strong><?php echo $count_sms; ?></strong> active SMS subscribers.
            Leave a body blank to skip that channel entirely.
        </p>
        <form method="post">
            <?php wp_nonce_field('lmeg_compose', 'lmeg_compose_nonce'); ?>

            <?php
            $seg_rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lmeg_segments ORDER BY name ASC");
            $tpl_rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lmeg_templates ORDER BY name ASC");
            ?>
            <?php if ($seg_rows || $tpl_rows) : ?>
                <h2>Quick load</h2>
                <table class="form-table" role="presentation">
                    <?php if ($seg_rows) : ?>
                        <tr>
                            <th>Segment</th>
                            <td>
                                <select id="lmeg-load-segment">
                                    <option value="">— pick a saved segment —</option>
                                    <?php foreach ($seg_rows as $sg) : ?>
                                        <option
                                            value="<?php echo (int) $sg->id; ?>"
                                            data-tag-ids="<?php echo esc_attr($sg->tag_ids); ?>"
                                            data-match="<?php echo esc_attr($sg->match_mode); ?>"><?php echo esc_html($sg->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="button" id="lmeg-load-segment-btn">Load</button>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($tpl_rows) : ?>
                        <tr>
                            <th>Template</th>
                            <td>
                                <select id="lmeg-load-template">
                                    <option value="">— pick a template —</option>
                                    <?php foreach ($tpl_rows as $tp) : ?>
                                        <option
                                            value="<?php echo (int) $tp->id; ?>"
                                            data-subject="<?php echo esc_attr($tp->subject); ?>"
                                            data-body-email="<?php echo esc_attr($tp->body_email); ?>"
                                            data-body-sms="<?php echo esc_attr($tp->body_sms); ?>"><?php echo esc_html($tp->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="button" id="lmeg-load-template-btn">Load</button>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>
            <?php endif; ?>

            <h2>Audience</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label>Filter by tags</label></th>
                    <td>
                        <div class="lmeg-audience">
                            <div class="lmeg-audience-tags">
                                <?php if (empty($all_tags)) : ?>
                                    <em>No tags yet. <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-tags')); ?>">Create one →</a></em>
                                <?php else : foreach ($all_tags as $t) :
                                    $checked = in_array((int) $t->id, $vals['tag_ids'], true) ? ' checked' : '';
                                ?>
                                    <label class="lmeg-chip-label">
                                        <input type="checkbox" name="tag_ids[]" value="<?php echo (int) $t->id; ?>"<?php echo $checked; ?> />
                                        <?php echo lmeg_render_tag_chip($t, ['count' => $t->member_count]); ?>
                                    </label>
                                <?php endforeach; endif; ?>
                            </div>
                            <div class="lmeg-audience-controls">
                                <label><input type="radio" name="tag_match" value="any" <?php checked($vals['tag_match'], 'any'); ?> /> Match <strong>any</strong> selected tag</label>
                                <label><input type="radio" name="tag_match" value="all" <?php checked($vals['tag_match'], 'all'); ?> /> Match <strong>all</strong> selected tags</label>
                            </div>
                            <div class="lmeg-audience-count" id="lmeg-audience-count">
                                Sending to <strong>—</strong> subscribers
                            </div>
                            <p class="description">Pick zero tags to send to everyone (still excluding unsubscribed). The count updates as you check tags.</p>
                        </div>
                    </td>
                </tr>
            </table>

            <h2>Email (via Brevo) — sent to <?php echo $count_email; ?> subscriber<?php echo $count_email === 1 ? '' : 's'; ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="subject">Subject</label></th>
                    <td><input type="text" name="subject" id="subject" class="regular-text" value="<?php echo esc_attr($vals['subject']); ?>" /></td>
                </tr>
                <tr>
                    <th><label>Email body</label></th>
                    <td>
                        <div class="lmeg-bd-modes">
                            <button type="button" class="lmeg-bd-mode is-active" data-mode="builder">🧱 Drag &amp; drop builder</button>
                            <button type="button" class="lmeg-bd-mode" data-mode="rich">Rich text / HTML</button>
                        </div>

                        <div id="lmeg-builder-root" data-accent="<?php echo esc_attr(lmeg_get_settings()['color_primary'] ?? '#d05fa2'); ?>"></div>
                        <input type="hidden" id="body_email_blocks" name="body_email_blocks" value="<?php echo esc_attr($vals['body_email_blocks'] ?? ''); ?>" />

                        <div id="lmeg-rich-wrap" style="display:none;">
                        <?php
                        wp_editor($vals['body_email'], 'body_email', [
                            'textarea_name' => 'body_email',
                            'textarea_rows' => 12,
                            'media_buttons' => true,
                            'teeny'         => false,
                            'quicktags'     => true,
                        ]);
                        ?>
                        </div>

                        <div id="lmeg-email-meta" style="margin-top:6px;font-size:12px;display:flex;gap:.5em;align-items:center;flex-wrap:wrap;">
                            <span id="lmeg-email-words">0 words</span>
                            <span style="opacity:.4;">·</span>
                            <span id="lmeg-email-chars">0 chars</span>
                            <span style="opacity:.4;">·</span>
                            <span id="lmeg-email-read" style="padding:1px 8px;border-radius:999px;background:#eef2ff;color:#3a3a8a;">&lt; 1 min read</span>
                        </div>
                        <p class="description">Build with drag &amp; drop blocks, or switch to Rich text / HTML. Merge tags work in text blocks: <code>{name}</code>, <code>{unique_code}</code>, <code>{referral_link}</code>. Everything renders inside your branded template on send. Leave empty to skip the email channel.</p>

                        <p style="margin-top:10px;">
                            <button type="button" class="button" id="lmeg-preview-btn">👁 Preview email in new tab</button>
                            <span class="description" style="margin-left:8px;">Opens the branded email with sample merge values.</span>
                        </p>
                        <form id="lmeg-preview-form" method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" target="lmeg_email_preview" style="display:none;">
                            <input type="hidden" name="action" value="lmeg_preview_email" />
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('lmeg_preview')); ?>" />
                            <input type="hidden" name="body" id="lmeg-preview-body" value="" />
                        </form>

                        <script>
                        // builder.js is enqueued in the footer, so defer init until the
                        // DOM (and that script) are ready — otherwise LMEGBuilder is undefined.
                        (function(){
                            function start(){
                                var root = document.getElementById('lmeg-builder-root');
                                var richWrap = document.getElementById('lmeg-rich-wrap');
                                if (!root) return;
                                if (!window.LMEGBuilder) {
                                    // Script still not loaded — try again shortly (up to ~3s).
                                    if ((start._tries = (start._tries || 0) + 1) < 30) { setTimeout(start, 100); }
                                    return;
                                }
                                var b = window.LMEGBuilder.init(root, 'body_email');
                                document.querySelectorAll('.lmeg-bd-mode').forEach(function(btn){
                                    btn.addEventListener('click', function(){
                                        document.querySelectorAll('.lmeg-bd-mode').forEach(function(x){x.classList.remove('is-active');});
                                        btn.classList.add('is-active');
                                        var builder = btn.dataset.mode === 'builder';
                                        root.style.display = builder ? '' : 'none';
                                        richWrap.style.display = builder ? 'none' : '';
                                        if (b) b.sync();
                                    });
                                });

                                // Preview in a new tab — pull the body from whichever
                                // editor is active, then submit the hidden form.
                                var pvBtn = document.getElementById('lmeg-preview-btn');
                                if (pvBtn) pvBtn.addEventListener('click', function(){
                                    var builderActive = root.style.display !== 'none';
                                    if (builderActive && b) {
                                        b.sync(); // builder → #body_email (mirrors tinymce)
                                    } else if (window.tinymce && tinymce.get('body_email')) {
                                        try { tinymce.triggerSave(); } catch(e){} // rich → #body_email
                                    }
                                    var ta = document.getElementById('body_email');
                                    document.getElementById('lmeg-preview-body').value = ta ? ta.value : '';
                                    // Name + open the target window in the click handler so
                                    // the browser doesn't treat it as a blocked popup.
                                    window.open('', 'lmeg_email_preview');
                                    document.getElementById('lmeg-preview-form').submit();
                                });
                            }
                            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
                            else start();
                        })();
                        </script>
                    </td>
                </tr>
                <tr>
                    <th><label for="test_to_email">Test email to</label></th>
                    <td>
                        <?php $default_test = lmeg_get_settings()['default_test_email'] ?? ''; ?>
                        <input type="email" name="test_to_email" id="test_to_email" class="regular-text" placeholder="you@example.com" value="<?php echo esc_attr($default_test); ?>" />
                        <button type="submit" name="lmeg_action" value="send_test_email" class="button">Send email test</button>
                        <p class="description">Pre-filled from Settings → "Default test recipient". Change it here for a one-off.</p>
                    </td>
                </tr>
            </table>

            <h2>SMS (Twilio) — sent to <?php echo $count_sms; ?> subscriber<?php echo $count_sms === 1 ? '' : 's'; ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="body_sms">SMS body</label></th>
                    <td>
                        <textarea name="body_sms" id="body_sms" rows="4" class="large-text" maxlength="1600"><?php echo esc_textarea($vals['body_sms']); ?></textarea>
                        <div id="lmeg-sms-meta" style="margin-top:6px;font-size:12px;display:flex;gap:.5em;align-items:center;flex-wrap:wrap;">
                            <span id="lmeg-sms-chars">0 chars</span>
                            <span style="opacity:.4;">·</span>
                            <span id="lmeg-sms-segs">0 segments</span>
                            <span style="opacity:.4;">·</span>
                            <span id="lmeg-sms-enc" style="padding:1px 8px;border-radius:999px;background:#e7f5e7;color:#1a6f1a;">GSM-7</span>
                            <span id="lmeg-sms-warn" style="display:none;color:#a05a00;">⚠ multi-segment — each segment billed separately</span>
                        </div>
                        <p class="description">Plain text. Up to 160 chars per segment in standard encoding, 70 per segment if you include emoji or accents. Leave blank to skip the SMS channel.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="test_to_sms">Test SMS to (E.164)</label></th>
                    <td>
                        <input type="text" name="test_to_sms" id="test_to_sms" class="regular-text" placeholder="+14155551234" />
                        <button type="submit" name="lmeg_action" value="send_test_sms" class="button">Send SMS test</button>
                    </td>
                </tr>
            </table>

            <h2>Schedule</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="scheduled_for">Send at</label></th>
                    <td>
                        <input type="datetime-local" name="scheduled_for" id="scheduled_for" />
                        <p class="description">Leave blank to send immediately. Otherwise the broadcast sits in queue and starts at the chosen moment (site timezone). Recipients are locked in at queue time, not at send time.</p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" name="lmeg_action" value="send_broadcast" class="button button-primary"
                    onclick="return confirm('Queue this broadcast — emails to your email subscribers and texts to your SMS subscribers?');">
                    Queue broadcast
                </button>
            </p>
        </form>
        <hr />
        <p><em>Broadcasts process via wp_cron in batches of <?php echo (int) lmeg_batch_size(); ?> per minute. Close this tab and watch <strong>Broadcast History</strong> for progress.</em></p>
    </div>
    <script>
    (function () {
        var ta = document.getElementById('body_sms');
        if (!ta) return;
        var elChars = document.getElementById('lmeg-sms-chars');
        var elSegs  = document.getElementById('lmeg-sms-segs');
        var elEnc   = document.getElementById('lmeg-sms-enc');
        var elWarn  = document.getElementById('lmeg-sms-warn');

        // GSM-7 default + extension table. Any char outside this set forces UCS-2 (Unicode).
        var GSM = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
        var GSM_EXT = '^{}\\[~]|€'; // each of these counts as 2 chars in a GSM-7 message
        var GSM_SET = new Set((GSM + GSM_EXT).split(''));

        function analyse(text) {
            var len = 0, isGsm = true;
            for (var i = 0; i < text.length; i++) {
                var ch = text[i];
                if (!GSM_SET.has(ch)) { isGsm = false; break; }
                len += GSM_EXT.indexOf(ch) === -1 ? 1 : 2;
            }
            if (!isGsm) len = Array.from(text).length; // count code points for Unicode
            var segCap   = isGsm ? 160 : 70;
            var multiCap = isGsm ? 153 : 67;
            var segs = len === 0 ? 0 : (len <= segCap ? 1 : Math.ceil(len / multiCap));
            return { len: len, segs: segs, isGsm: isGsm };
        }

        function update() {
            var r = analyse(ta.value);
            elChars.textContent = r.len + ' char' + (r.len === 1 ? '' : 's');
            elSegs.textContent  = r.segs + ' segment' + (r.segs === 1 ? '' : 's');
            if (r.isGsm) {
                elEnc.textContent = 'GSM-7';
                elEnc.style.background = '#e7f5e7';
                elEnc.style.color = '#1a6f1a';
            } else {
                elEnc.textContent = 'Unicode';
                elEnc.style.background = '#fff3cd';
                elEnc.style.color = '#7a5a00';
            }
            elWarn.style.display = r.segs > 1 ? '' : 'none';
        }

        ta.addEventListener('input', update);
        update();
    })();

    // Audience count — live updates as tags/match/bodies change.
    (function () {
        var countEl = document.getElementById('lmeg-audience-count');
        if (!countEl) return;
        var ajax_url = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce    = <?php echo wp_json_encode($ajax_nonce); ?>;
        var taEmail  = document.getElementById('body_email');
        var taSms    = document.getElementById('body_sms');
        var matchRadios = document.querySelectorAll('input[name="tag_match"]');
        var tagBoxes    = document.querySelectorAll('input[name="tag_ids[]"]');

        var inflight = null;
        function refresh() {
            var fd = new FormData();
            fd.append('action', 'lmeg_audience_count');
            fd.append('nonce',  nonce);
            tagBoxes.forEach(function (b) { if (b.checked) fd.append('tag_ids[]', b.value); });
            var match = 'any';
            matchRadios.forEach(function (r) { if (r.checked) match = r.value; });
            fd.append('match', match);
            fd.append('has_email', taEmail && taEmail.value.trim() ? '1' : '0');
            fd.append('has_sms',   taSms   && taSms.value.trim()   ? '1' : '0');

            if (inflight) inflight.abort();
            inflight = new AbortController();
            countEl.innerHTML = 'Counting…';
            fetch(ajax_url, { method: 'POST', body: fd, signal: inflight.signal })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.success) {
                        countEl.innerHTML = 'Sending to <strong>' + d.data.count + '</strong> subscriber' + (d.data.count === 1 ? '' : 's');
                    } else {
                        countEl.innerHTML = '<em>count failed</em>';
                    }
                })
                .catch(function () {});
        }

        tagBoxes.forEach(function (b) { b.addEventListener('change', refresh); });
        matchRadios.forEach(function (r) { r.addEventListener('change', refresh); });
        if (taEmail) taEmail.addEventListener('input', debounce(refresh, 350));
        if (taSms)   taSms.addEventListener('input',   debounce(refresh, 350));
        refresh();

        function debounce(fn, ms) {
            var t = null;
            return function () {
                clearTimeout(t);
                t = setTimeout(fn, ms);
            };
        }
    })();

    // Email body meta — words / chars / read time. ~225 wpm reading speed.
    (function () {
        var ta = document.getElementById('body_email');
        if (!ta) return;
        var elWords = document.getElementById('lmeg-email-words');
        var elChars = document.getElementById('lmeg-email-chars');
        var elRead  = document.getElementById('lmeg-email-read');
        var WPM = 225;

        function update() {
            var raw  = ta.value;
            // Strip HTML tags + collapse entities/whitespace for word counting.
            var text = raw.replace(/<[^>]*>/g, ' ')
                          .replace(/&nbsp;|&amp;|&lt;|&gt;|&quot;|&#\d+;/g, ' ')
                          .trim();
            var words = text.length ? text.split(/\s+/).length : 0;
            var chars = raw.length;

            elWords.textContent = words + ' word' + (words === 1 ? '' : 's');
            elChars.textContent = chars + ' char' + (chars === 1 ? '' : 's');

            if (words === 0) {
                elRead.textContent = '— read';
            } else {
                var mins = words / WPM;
                if (mins < 1) {
                    elRead.textContent = '< 1 min read';
                } else {
                    var rounded = Math.round(mins);
                    elRead.textContent = rounded + ' min read';
                }
            }
        }

        ta.addEventListener('input', update);
        // With the visual editor active the textarea is hidden and silent —
        // mirror TinyMCE content back into it so the counter stays live.
        if (window.tinymce) {
            tinymce.on('AddEditor', function (e) {
                if (e.editor.id !== 'body_email') return;
                e.editor.on('keyup change SetContent', function () {
                    ta.value = e.editor.getContent();
                    update();
                });
            });
        }
        update();
    })();

    // Load segment / template into the form.
    (function () {
        var segBtn = document.getElementById('lmeg-load-segment-btn');
        var tplBtn = document.getElementById('lmeg-load-template-btn');
        var segSel = document.getElementById('lmeg-load-segment');
        var tplSel = document.getElementById('lmeg-load-template');

        if (segBtn && segSel) {
            segBtn.addEventListener('click', function () {
                var opt = segSel.options[segSel.selectedIndex];
                if (!opt || !opt.value) return;
                var tagIds = [];
                try { tagIds = JSON.parse(opt.dataset.tagIds || '[]'); } catch (e) {}
                var match  = opt.dataset.match || 'any';
                document.querySelectorAll('input[name="tag_ids[]"]').forEach(function (b) {
                    b.checked = tagIds.indexOf(parseInt(b.value, 10)) !== -1;
                    b.dispatchEvent(new Event('change'));
                });
                document.querySelectorAll('input[name="tag_match"]').forEach(function (r) {
                    r.checked = (r.value === match);
                    r.dispatchEvent(new Event('change'));
                });
            });
        }

        if (tplBtn && tplSel) {
            tplBtn.addEventListener('click', function () {
                var opt = tplSel.options[tplSel.selectedIndex];
                if (!opt || !opt.value) return;
                var subjEl  = document.getElementById('subject');
                var emailTa = document.getElementById('body_email');
                var smsTa   = document.getElementById('body_sms');
                if (subjEl)  subjEl.value  = opt.dataset.subject   || '';
                if (emailTa) { emailTa.value = opt.dataset.bodyEmail || ''; emailTa.dispatchEvent(new Event('input')); }
                // TinyMCE visual editor holds its own copy — sync it too.
                if (window.tinymce && tinymce.get('body_email')) {
                    tinymce.get('body_email').setContent(opt.dataset.bodyEmail || '');
                }
                if (smsTa)   { smsTa.value   = opt.dataset.bodySms   || ''; smsTa.dispatchEvent(new Event('input')); }
            });
        }
    })();
    </script>
    <?php
}

/* ---------------------------------------------------------------------------
 * Broadcast history
 * ------------------------------------------------------------------------- */

function lmeg_admin_broadcasts() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $bcast_tbl = $wpdb->prefix . 'lmeg_broadcasts';
    $log_tbl   = $wpdb->prefix . 'lmeg_broadcast_log';

    // Detail view?
    $view = isset($_GET['view']) ? (int) $_GET['view'] : 0;
    if ($view) {
        $b = $wpdb->get_row($wpdb->prepare("SELECT * FROM $bcast_tbl WHERE id = %d", $view));
        if (!$b) { echo '<div class="wrap"><h1>Broadcast not found.</h1></div>'; return; }
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $log_tbl WHERE broadcast_id = %d ORDER BY id ASC LIMIT 1000", $view
        ));
        ?>
        <div class="wrap">
            <h1>Broadcast #<?php echo (int) $b->id; ?></h1>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-broadcasts')); ?>">← Back to history</a></p>
            <?php
            $ev_tbl  = $wpdb->prefix . 'lmeg_broadcast_events';
            $opens   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT subscriber_id) FROM $ev_tbl WHERE broadcast_id = %d AND event_type = 'open'",  $b->id));
            $clicks  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT subscriber_id) FROM $ev_tbl WHERE broadcast_id = %d AND event_type = 'click'", $b->id));
            $sent_n  = max(1, (int) $b->sent);
            $orate   = round(($opens  / $sent_n) * 100, 1);
            $crate   = round(($clicks / $sent_n) * 100, 1);
            ?>
            <p>
                <strong>Channel:</strong> <?php echo esc_html(strtoupper($b->channel)); ?> &nbsp;|&nbsp;
                <strong>Status:</strong> <?php echo esc_html($b->status); ?> &nbsp;|&nbsp;
                <strong>Sent:</strong> <?php echo (int) $b->sent; ?> / <?php echo (int) $b->total; ?>
                &nbsp;|&nbsp; <strong>Failed:</strong> <?php echo (int) $b->failed; ?>
                &nbsp;|&nbsp; <strong>Opens:</strong> <?php echo $opens; ?> (<?php echo $orate; ?>%)
                &nbsp;|&nbsp; <strong>Clicks:</strong> <?php echo $clicks; ?> (<?php echo $crate; ?>%)
                <?php
                $rev_map = function_exists('lmeg_shop_revenue_by_broadcast') ? lmeg_shop_revenue_by_broadcast() : [];
                if (isset($rev_map[(int) $b->id])) :
                    $rv = $rev_map[(int) $b->id];
                ?>
                    &nbsp;|&nbsp; <strong>Revenue:</strong> <?php echo esc_html(lmeg_format_price($rv['cents'])); ?> (<?php echo (int) $rv['orders']; ?> order<?php echo $rv['orders'] === 1 ? '' : 's'; ?>)
                <?php endif; ?>
            </p>
            <?php if ($b->subject) : ?><p><strong>Subject:</strong> <?php echo esc_html($b->subject); ?></p><?php endif; ?>
            <?php if (!empty($b->body)) : ?>
                <h2>Email body</h2>
                <pre style="background:#f6f7f7;padding:1em;white-space:pre-wrap;border-radius:6px;"><?php echo esc_html($b->body); ?></pre>
            <?php endif; ?>
            <?php if (!empty($b->body_sms)) : ?>
                <h2>SMS body</h2>
                <pre style="background:#f6f7f7;padding:1em;white-space:pre-wrap;border-radius:6px;"><?php echo esc_html($b->body_sms); ?></pre>
            <?php endif; ?>
            <h2>Recipient log (latest 1000)</h2>
            <table class="widefat striped">
                <thead><tr><th>Channel</th><th>Recipient</th><th>Status</th><th>Sent at</th><th>Opened</th><th>Clicked</th><th>Error</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $l) : ?>
                    <tr>
                        <td><?php echo $l->channel === 'sms' ? '📱 SMS' : '✉️ Email'; ?></td>
                        <td><?php echo esc_html($l->recipient); ?></td>
                        <td><?php echo esc_html($l->status); ?></td>
                        <td><?php echo esc_html($l->sent_at ?: '—'); ?></td>
                        <td><?php echo $l->opened_at ? '<span style="color:#1a6f1a;">✓</span> ' . esc_html($l->opened_at) : '—'; ?></td>
                        <td><?php echo $l->first_clicked_at ? '<span style="color:#1a6f1a;">✓</span> ' . esc_html($l->first_clicked_at) : '—'; ?></td>
                        <td><?php echo esc_html($l->error ?: ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return;
    }

    $rows    = $wpdb->get_results("SELECT * FROM $bcast_tbl ORDER BY id DESC LIMIT 100");
    $now     = current_time('mysql');
    $rev_map = function_exists('lmeg_shop_revenue_by_broadcast') ? lmeg_shop_revenue_by_broadcast() : [];
    ?>
    <div class="wrap">
        <h1>Email Gate — Broadcast History</h1>
        <table class="widefat striped">
            <thead><tr><th>#</th><th>Channel</th><th>Subject / Body</th><th>Status</th><th>Sent / Total</th><th>Failed</th><th>Revenue</th><th>Created</th><th>Send time</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="10">No broadcasts yet.</td></tr>
            <?php else : foreach ($rows as $b) :
                $preview = $b->subject ?: mb_substr((string) ($b->body ?? $b->body_sms ?? ''), 0, 60);
                // If scheduled in the future, show "Scheduled" instead of "queued".
                $display_status = $b->status;
                if ($b->status === 'queued' && !empty($b->scheduled_for) && $b->scheduled_for > $now) {
                    $display_status = 'scheduled';
                }
                if ($b->status === 'completed') {
                    $send_time = $b->completed_at ?: '—';
                } elseif (!empty($b->scheduled_for) && $b->scheduled_for > $now) {
                    $send_time = '⏰ ' . $b->scheduled_for;
                } else {
                    $send_time = '—';
                }
            ?>
                <tr>
                    <td><?php echo (int) $b->id; ?></td>
                    <td><?php echo esc_html(strtoupper($b->channel)); ?></td>
                    <td><?php echo esc_html($preview); ?></td>
                    <td><?php echo esc_html($display_status); ?></td>
                    <td><?php echo (int) $b->sent; ?> / <?php echo (int) $b->total; ?></td>
                    <td><?php echo (int) $b->failed; ?></td>
                    <td><?php
                        if (isset($rev_map[(int) $b->id])) {
                            echo '<strong>' . esc_html(lmeg_format_price($rev_map[(int) $b->id]['cents'])) . '</strong> · ' . (int) $rev_map[(int) $b->id]['orders'];
                        } else {
                            echo '—';
                        }
                    ?></td>
                    <td><?php echo esc_html($b->created_at); ?></td>
                    <td><?php echo esc_html($send_time); ?></td>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-broadcasts&view=' . $b->id)); ?>">View</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Settings
 * ------------------------------------------------------------------------- */

function lmeg_admin_settings() {
    if (!current_user_can('manage_options')) return;

    $verify_notice = '';

    if (isset($_POST['lmeg_settings_nonce']) && wp_verify_nonce($_POST['lmeg_settings_nonce'], 'lmeg_settings')) {
        $new = [
            // Form copy
            'cookie_days'         => max(1, (int) ($_POST['cookie_days'] ?? 30)),
            'form_heading'        => sanitize_text_field(wp_unslash($_POST['form_heading'] ?? '')),
            'form_message'        => sanitize_textarea_field(wp_unslash($_POST['form_message'] ?? '')),
            'button_text'         => sanitize_text_field(wp_unslash($_POST['button_text'] ?? 'Unlock')),
            'consent_text'        => sanitize_textarea_field(wp_unslash($_POST['consent_text'] ?? '')),
            'success_message'     => sanitize_text_field(wp_unslash($_POST['success_message'] ?? '')),
            // Scope
            'gate_single_posts'   => !empty($_POST['gate_single_posts']) ? 1 : 0,
            'gate_blog_index'     => !empty($_POST['gate_blog_index']) ? 1 : 0,
            // Address fields
            'enable_address'      => !empty($_POST['enable_address']) ? 1 : 0,
            'address_required'    => !empty($_POST['address_required']) ? 1 : 0,
            'address_message'     => sanitize_textarea_field(wp_unslash($_POST['address_message'] ?? '')),
            // Brevo (the email provider)
            'brevo_api_key'       => sanitize_text_field(wp_unslash($_POST['brevo_api_key'] ?? '')),
            'brevo_from_email'    => sanitize_email(wp_unslash($_POST['brevo_from_email'] ?? '')),
            'brevo_from_name'     => sanitize_text_field(wp_unslash($_POST['brevo_from_name'] ?? '')),
            // Twilio
            'twilio_account_sid'  => sanitize_text_field(wp_unslash($_POST['twilio_account_sid'] ?? '')),
            'twilio_auth_token'   => sanitize_text_field(wp_unslash($_POST['twilio_auth_token'] ?? '')),
            'twilio_from_number'  => sanitize_text_field(wp_unslash($_POST['twilio_from_number'] ?? '')),
            // Compliance + feed copy
            'unsub_footer_text'   => sanitize_textarea_field(wp_unslash($_POST['unsub_footer_text'] ?? '')),
            'feed_teaser_text'    => sanitize_text_field(wp_unslash($_POST['feed_teaser_text'] ?? '')),
            // Welcome email
            'welcome_enabled'     => !empty($_POST['welcome_enabled']) ? 1 : 0,
            'welcome_subject'     => sanitize_text_field(wp_unslash($_POST['welcome_subject'] ?? '')),
            'welcome_body'        => wp_kses_post(wp_unslash($_POST['welcome_body'] ?? '')),
            // Tracking
            'tracking_opens'      => !empty($_POST['tracking_opens']) ? 1 : 0,
            'tracking_clicks'     => !empty($_POST['tracking_clicks']) ? 1 : 0,
            // Stripe / paid membership
            'stripe_mode'             => ($_POST['stripe_mode'] ?? 'test') === 'live' ? 'live' : 'test',
            'stripe_test_pk'          => sanitize_text_field(wp_unslash($_POST['stripe_test_pk'] ?? '')),
            'stripe_test_sk'          => sanitize_text_field(wp_unslash($_POST['stripe_test_sk'] ?? '')),
            'stripe_test_webhook_sec' => sanitize_text_field(wp_unslash($_POST['stripe_test_webhook_sec'] ?? '')),
            'stripe_live_pk'          => sanitize_text_field(wp_unslash($_POST['stripe_live_pk'] ?? '')),
            'stripe_live_sk'          => sanitize_text_field(wp_unslash($_POST['stripe_live_sk'] ?? '')),
            'stripe_live_webhook_sec' => sanitize_text_field(wp_unslash($_POST['stripe_live_webhook_sec'] ?? '')),
            'member_cookie_days'      => max(1, (int) ($_POST['member_cookie_days'] ?? 30)),
            'magic_link_ttl_hours'    => max(1, (int) ($_POST['magic_link_ttl_hours'] ?? 24)),
            'default_post_access'     => in_array($_POST['default_post_access'] ?? 'free', ['public', 'free', 'paid'], true) ? $_POST['default_post_access'] : 'free',
            'upgrade_heading'         => sanitize_text_field(wp_unslash($_POST['upgrade_heading'] ?? '')),
            'upgrade_message'         => sanitize_textarea_field(wp_unslash($_POST['upgrade_message'] ?? '')),
            'soft_paywall_heading'    => sanitize_text_field(wp_unslash($_POST['soft_paywall_heading'] ?? '')),
            'soft_paywall_message'    => sanitize_textarea_field(wp_unslash($_POST['soft_paywall_message'] ?? '')),
            'paywall_heading'         => sanitize_text_field(wp_unslash($_POST['paywall_heading'] ?? '')),
            'paywall_unlock_label'    => sanitize_text_field(wp_unslash($_POST['paywall_unlock_label'] ?? '')),
            'paywall_premium_label'   => sanitize_text_field(wp_unslash($_POST['paywall_premium_label'] ?? '')),
            'logo_url'                => esc_url_raw(wp_unslash($_POST['logo_url'] ?? '')),
            'logo_max_width'          => max(20, min(800, (int) ($_POST['logo_max_width'] ?? 200))),
            'signup_success_message'  => sanitize_text_field(wp_unslash($_POST['signup_success_message'] ?? '')) ?: 'Thank you for joining the loonybin',
            'default_test_email'      => sanitize_email(wp_unslash($_POST['default_test_email'] ?? '')),
            // Branded email template
            'email_template_enabled'  => !empty($_POST['email_template_enabled']) ? 1 : 0,
            'email_footer_note'       => sanitize_text_field(wp_unslash($_POST['email_footer_note'] ?? '')),
            // Spotify analytics
            'spotify_client_id'       => sanitize_text_field(wp_unslash($_POST['spotify_client_id'] ?? '')),
            'spotify_client_secret'   => sanitize_text_field(wp_unslash($_POST['spotify_client_secret'] ?? '')),
            'spotify_artist_id'       => sanitize_text_field(wp_unslash($_POST['spotify_artist_id'] ?? '')),
            // AI assistant
            'ai_api_key'              => sanitize_text_field(wp_unslash($_POST['ai_api_key'] ?? '')),
            'ai_model'                => sanitize_text_field(wp_unslash($_POST['ai_model'] ?? '')) ?: 'claude-haiku-4-5-20251001',
            // Instagram DM automation
            'ig_app_secret'           => sanitize_text_field(wp_unslash($_POST['ig_app_secret'] ?? '')),
            'ig_page_token'           => sanitize_text_field(wp_unslash($_POST['ig_page_token'] ?? '')),
            'ig_account_id'           => sanitize_text_field(wp_unslash($_POST['ig_account_id'] ?? '')),
            'ig_verify_token'         => sanitize_text_field(wp_unslash($_POST['ig_verify_token'] ?? '')),
            // Shopify shop connection
            'shopify_domain'          => sanitize_text_field(wp_unslash($_POST['shopify_domain'] ?? '')),
            'shopify_admin_token'     => sanitize_text_field(wp_unslash($_POST['shopify_admin_token'] ?? '')),
            'attribution_window_days' => max(1, min(90, (int) ($_POST['attribution_window_days'] ?? 7))),
            'utm_source'              => sanitize_title(wp_unslash($_POST['utm_source'] ?? '')) ?: 'loonybin',
            'color_primary'           => sanitize_hex_color(wp_unslash($_POST['color_primary']      ?? '')) ?: '#111111',
            'color_primary_text'      => sanitize_hex_color(wp_unslash($_POST['color_primary_text'] ?? '')) ?: '#ffffff',
            'color_accent'            => sanitize_hex_color(wp_unslash($_POST['color_accent']      ?? '')) ?: '#3b82f6',
            'color_border'            => !empty($_POST['color_border_reset']) ? '' : (sanitize_hex_color(wp_unslash($_POST['color_border'] ?? '')) ?: ''),
            'color_card_bg'           => !empty($_POST['color_card_bg_reset'])   ? '' : (sanitize_hex_color(wp_unslash($_POST['color_card_bg']   ?? '')) ?: ''),
            'color_card_text'         => !empty($_POST['color_card_text_reset']) ? '' : (sanitize_hex_color(wp_unslash($_POST['color_card_text'] ?? '')) ?: ''),
            'color_page_bg'           => !empty($_POST['color_page_bg_reset'])   ? '' : (sanitize_hex_color(wp_unslash($_POST['color_page_bg']   ?? '')) ?: ''),
            'signin_heading'          => sanitize_text_field(wp_unslash($_POST['signin_heading'] ?? '')),
            'signin_message'          => sanitize_textarea_field(wp_unslash($_POST['signin_message'] ?? '')),
            'magic_link_subject'      => sanitize_text_field(wp_unslash($_POST['magic_link_subject'] ?? '')),
            'magic_link_body'         => sanitize_textarea_field(wp_unslash($_POST['magic_link_body'] ?? '')),
        ];
        // Don't persist config-managed credentials (the locked fields submit a
        // masked placeholder) — keep whatever the DB had; the config overlay
        // supplies the real value on read regardless.
        if (function_exists('lmeg_env_managed_keys')) {
            $existing = get_option(LMEG_OPTION, []);
            foreach (lmeg_env_managed_keys() as $k) {
                if (is_array($existing) && array_key_exists($k, $existing)) {
                    $new[$k] = $existing[$k];
                } else {
                    unset($new[$k]);
                }
            }
        }
        update_option(LMEG_OPTION, $new);
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';

        // Verify-connection buttons piggyback on the same form so the user
        // doesn't have to save settings before testing.
        if (isset($_POST['lmeg_test'])) {
            if ($_POST['lmeg_test'] === 'twilio') {
                $r = lmeg_twilio_verify();
                $verify_notice = is_wp_error($r)
                    ? '<div class="notice notice-error"><p>Twilio: ' . esc_html($r->get_error_message()) . '</p></div>'
                    : '<div class="notice notice-success"><p>Twilio: ' . esc_html($r) . '</p></div>';
            } elseif ($_POST['lmeg_test'] === 'brevo') {
                $r = lmeg_brevo_verify();
                $verify_notice = is_wp_error($r)
                    ? '<div class="notice notice-error"><p>Brevo: ' . esc_html($r->get_error_message()) . '</p></div>'
                    : '<div class="notice notice-success"><p>Brevo: ' . esc_html($r) . '</p></div>';
            } elseif ($_POST['lmeg_test'] === 'shopify') {
                $r = lmeg_shop_verify();
                $verify_notice = is_wp_error($r)
                    ? '<div class="notice notice-error"><p>Shopify: ' . esc_html($r->get_error_message()) . '</p></div>'
                    : '<div class="notice notice-success"><p>Shopify: ' . esc_html($r) . '</p></div>';
            } elseif ($_POST['lmeg_test'] === 'instagram') {
                $r = lmeg_ig_verify();
                $verify_notice = is_wp_error($r)
                    ? '<div class="notice notice-error"><p>' . esc_html($r->get_error_message()) . '</p></div>'
                    : '<div class="notice notice-success"><p>' . esc_html($r) . '</p></div>';
            } elseif ($_POST['lmeg_test'] === 'spotify') {
                $r = lmeg_spotify_verify();
                $verify_notice = is_wp_error($r)
                    ? '<div class="notice notice-error"><p>Spotify: ' . esc_html($r->get_error_message()) . '</p></div>'
                    : '<div class="notice notice-success"><p>Spotify: ' . esc_html($r) . '</p></div>';
            } elseif ($_POST['lmeg_test'] === 'ai') {
                $r = lmeg_ai_verify();
                $verify_notice = is_wp_error($r)
                    ? '<div class="notice notice-error"><p>AI: ' . esc_html($r->get_error_message()) . '</p></div>'
                    : '<div class="notice notice-success"><p>' . esc_html($r) . '</p></div>';
            }
        }
    }

    echo $verify_notice;

    $s = lmeg_get_settings();
    ?>
    <div class="wrap">
        <h1>Email Gate — Settings</h1>

        <?php
        $env_keys = function_exists('lmeg_env_managed_keys') ? lmeg_env_managed_keys() : [];
        if ($env_keys) : ?>
            <div class="notice notice-info" style="max-width:820px;">
                <p><strong><?php echo count($env_keys); ?> credential<?php echo count($env_keys) === 1 ? '' : 's'; ?> loaded from wp-config/.env</strong> — those fields are locked here and managed in config, so you never re-enter them per site. Per-artist values (Spotify artist ID, Shopify store, from-addresses) stay editable below.</p>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('lmeg_settings', 'lmeg_settings_nonce'); ?>

            <h2>Form copy</h2>
            <table class="form-table" role="presentation">
                <tr><th><label for="cookie_days">Remember unlock for (days)</label></th>
                    <td><input type="number" min="1" name="cookie_days" id="cookie_days" value="<?php echo esc_attr($s['cookie_days']); ?>" class="small-text" /></td></tr>
                <tr><th><label for="form_heading">Heading</label></th>
                    <td><input type="text" name="form_heading" id="form_heading" value="<?php echo esc_attr($s['form_heading']); ?>" class="regular-text" /></td></tr>
                <tr><th><label for="form_message">Message</label></th>
                    <td><textarea name="form_message" id="form_message" rows="3" class="large-text"><?php echo esc_textarea($s['form_message']); ?></textarea></td></tr>
                <tr><th><label for="button_text">Button text</label></th>
                    <td><input type="text" name="button_text" id="button_text" value="<?php echo esc_attr($s['button_text']); ?>" class="regular-text" /></td></tr>
                <tr><th><label for="consent_text">Consent line</label></th>
                    <td><textarea name="consent_text" id="consent_text" rows="2" class="large-text"><?php echo esc_textarea($s['consent_text']); ?></textarea></td></tr>
                <tr><th><label for="signup_success_message">Signup success message</label></th>
                    <td><input type="text" name="signup_success_message" id="signup_success_message" class="regular-text" value="<?php echo esc_attr($s['signup_success_message'] ?? 'Thank you for joining the loonybin'); ?>" />
                        <p class="description">Shown in place of the <code>[lmeg_signup]</code> form after someone joins. A per-embed <code>success="…"</code> attribute overrides this.</p></td></tr>
                <tr><th><label for="default_test_email">Default test recipient</label></th>
                    <td><input type="email" name="default_test_email" id="default_test_email" class="regular-text" value="<?php echo esc_attr($s['default_test_email'] ?? ''); ?>" placeholder="ian@portermedia.ca" />
                        <p class="description">Pre-fills the "Test email to" field on Compose Broadcast so you don't have to retype it.</p></td></tr>
            </table>

            <h2>Colors</h2>
            <table class="form-table" role="presentation">
                <tr><th><label for="color_primary">Primary button</label></th>
                    <td>
                        <input type="color" name="color_primary" id="color_primary" value="<?php echo esc_attr($s['color_primary']); ?>" />
                        <code style="margin-left:8px;opacity:.65;"><?php echo esc_html($s['color_primary']); ?></code>
                        <p class="description">Background of Unlock / Subscribe / tier CTAs.</p>
                    </td></tr>
                <tr><th><label for="color_primary_text">Primary button text</label></th>
                    <td>
                        <input type="color" name="color_primary_text" id="color_primary_text" value="<?php echo esc_attr($s['color_primary_text']); ?>" />
                        <code style="margin-left:8px;opacity:.65;"><?php echo esc_html($s['color_primary_text']); ?></code>
                    </td></tr>
                <tr><th><label for="color_accent">Accent</label></th>
                    <td>
                        <input type="color" name="color_accent" id="color_accent" value="<?php echo esc_attr($s['color_accent']); ?>" />
                        <code style="margin-left:8px;opacity:.65;"><?php echo esc_html($s['color_accent']); ?></code>
                        <p class="description">Soft paywall tint, input focus rings.</p>
                    </td></tr>
                <tr><th><label for="color_border">Card border</label></th>
                    <td>
                        <input type="color" name="color_border" id="color_border" value="<?php echo esc_attr($s['color_border'] ?: '#e5e5e5'); ?>" />
                        <code style="margin-left:8px;opacity:.65;"><?php echo $s['color_border'] ? esc_html($s['color_border']) : '(default translucent black)'; ?></code>
                        <label style="margin-left:10px;"><input type="checkbox" name="color_border_reset" value="1" /> Reset to default</label>
                    </td></tr>
                <tr><th><label for="color_card_bg">Card background</label></th>
                    <td>
                        <input type="color" name="color_card_bg" id="color_card_bg" value="<?php echo esc_attr($s['color_card_bg'] ?: '#ffffff'); ?>" />
                        <code style="margin-left:8px;opacity:.65;"><?php echo $s['color_card_bg'] ? esc_html($s['color_card_bg']) : '(default white)'; ?></code>
                        <label style="margin-left:10px;"><input type="checkbox" name="color_card_bg_reset" value="1" /> Reset to default</label>
                        <p class="description">Paywall + gate card background. Set to a dark color to match a dark theme.</p>
                    </td></tr>
                <tr><th><label for="color_card_text">Card text</label></th>
                    <td>
                        <input type="color" name="color_card_text" id="color_card_text" value="<?php echo esc_attr($s['color_card_text'] ?: '#1a1a1a'); ?>" />
                        <code style="margin-left:8px;opacity:.65;"><?php echo $s['color_card_text'] ? esc_html($s['color_card_text']) : '(default near-black)'; ?></code>
                        <label style="margin-left:10px;"><input type="checkbox" name="color_card_text_reset" value="1" /> Reset to default</label>
                        <p class="description">Text color on the paywall + gate card. Flip to white if you set a dark background.</p>
                    </td></tr>
                <tr><th><label for="color_page_bg">Page background</label></th>
                    <td>
                        <input type="color" name="color_page_bg" id="color_page_bg" value="<?php echo esc_attr($s['color_page_bg'] ?: '#000000'); ?>" />
                        <code style="margin-left:8px;opacity:.65;"><?php echo $s['color_page_bg'] ? esc_html($s['color_page_bg']) : '(default — theme shows through)'; ?></code>
                        <label style="margin-left:10px;"><input type="checkbox" name="color_page_bg_reset" value="1" /> Reset to default</label>
                        <p class="description">Whole gated-page background. Overrides theme CSS with <code>!important</code>. Applies only when a post is gated — non-gated pages stay whatever the theme wants.</p>
                    </td></tr>
            </table>

            <p class="description" style="margin-top:8px;">
                <strong>Note:</strong> Card and page background colors are output with <code>!important</code>, so they beat any theme CSS. If you “unset” a value with Reset to default, the plugin drops back to letting the theme decide.
            </p>

            <h2>Where to gate</h2>
            <table class="form-table" role="presentation">
                <tr><th>Pages to gate</th><td>
                    <label><input type="checkbox" name="gate_single_posts" value="1" <?php checked($s['gate_single_posts']); ?> /> Single posts</label><br />
                    <label><input type="checkbox" name="gate_blog_index" value="1" <?php checked($s['gate_blog_index']); ?> /> Blog index page (the page that lists all posts)</label>
                </td></tr>
            </table>

            <h2>Address fields</h2>
            <table class="form-table" role="presentation">
                <tr><th>Show address fields</th><td>
                    <label><input type="checkbox" name="enable_address" value="1" <?php checked($s['enable_address']); ?> /> Add an expandable address block to the form</label><br />
                    <label><input type="checkbox" name="address_required" value="1" <?php checked($s['address_required']); ?> /> Require address fields (otherwise optional)</label>
                </td></tr>
                <tr><th><label for="address_message">Address block message</label></th>
                    <td><textarea name="address_message" id="address_message" rows="2" class="large-text"><?php echo esc_textarea($s['address_message']); ?></textarea>
                        <p class="description">Shown above the address fields when expanded.</p></td></tr>
            </table>

            <h2>Brevo (email)</h2>
            <table class="form-table" role="presentation">
                <tr><th><label for="brevo_api_key">API key</label></th>
                    <td><input type="text" name="brevo_api_key" id="brevo_api_key" value="<?php echo esc_attr($s['brevo_api_key']); ?>" class="regular-text" autocomplete="off" placeholder="xkeysib-..." />
                        <p class="description">Brevo → SMTP &amp; API → API Keys → your v3 key. Starts with <code>xkeysib-</code>.</p></td></tr>
                <tr><th><label for="brevo_from_email">From email</label></th>
                    <td><input type="email" name="brevo_from_email" id="brevo_from_email" value="<?php echo esc_attr($s['brevo_from_email']); ?>" class="regular-text" placeholder="hello@loonymoonchild.com" />
                        <p class="description">Must be a sender you've verified in Brevo → Senders &amp; IP.</p></td></tr>
                <tr><th><label for="brevo_from_name">From name</label></th>
                    <td><input type="text" name="brevo_from_name" id="brevo_from_name" value="<?php echo esc_attr($s['brevo_from_name']); ?>" class="regular-text" /></td></tr>
                <tr><th>Test connection</th>
                    <td><button type="submit" name="lmeg_test" value="brevo" class="button">Save &amp; test Brevo</button>
                        <p class="description">Hits Brevo's account endpoint with your saved key and reports the result above.</p></td></tr>
            </table>

            <h2>Email template</h2>
            <table class="form-table" role="presentation">
                <tr><th>Branded template</th>
                    <td><label><input type="checkbox" name="email_template_enabled" value="1" <?php checked($s['email_template_enabled'] ?? 1); ?> /> Wrap all outgoing emails in the branded loonybin template</label>
                        <p class="description">Cream backdrop, white card with your logo up top, links and the accent rule in your primary color (Settings → Colors → Primary button). Applies to broadcasts, welcome emails, magic links, and sequences. Unchecked = plain text-style emails.</p></td></tr>
                <tr><th><label for="email_footer_note">Footer note</label></th>
                    <td><input type="text" name="email_footer_note" id="email_footer_note" class="regular-text" value="<?php echo esc_attr($s['email_footer_note'] ?? ''); ?>" />
                        <p class="description">Small line above the unsubscribe link, e.g. "You're receiving this because you joined the loonybin."</p></td></tr>
            </table>

            <h2>Twilio (SMS)</h2>
            <table class="form-table" role="presentation">
                <tr><th><label for="twilio_account_sid">Account SID</label></th>
                    <td><input type="text" name="twilio_account_sid" id="twilio_account_sid" value="<?php echo esc_attr($s['twilio_account_sid']); ?>" class="regular-text" autocomplete="off" /></td></tr>
                <tr><th><label for="twilio_auth_token">Auth token</label></th>
                    <td><input type="password" name="twilio_auth_token" id="twilio_auth_token" value="<?php echo esc_attr($s['twilio_auth_token']); ?>" class="regular-text" autocomplete="off" /></td></tr>
                <tr><th><label for="twilio_from_number">From phone number (E.164)</label></th>
                    <td><input type="text" name="twilio_from_number" id="twilio_from_number" value="<?php echo esc_attr($s['twilio_from_number']); ?>" class="regular-text" placeholder="+15551234567" /></td></tr>
                <tr><th>Test connection</th>
                    <td><button type="submit" name="lmeg_test" value="twilio" class="button">Save &amp; test Twilio</button>
                        <p class="description">Hits Twilio's account endpoint with your saved credentials and reports the result above.</p></td></tr>
            </table>

            <h2>Spotify (analytics)</h2>
            <table class="form-table" role="presentation">
                <tr><th><label for="spotify_client_id">Client ID</label></th>
                    <td><input type="text" name="spotify_client_id" id="spotify_client_id" class="regular-text" value="<?php echo esc_attr($s['spotify_client_id'] ?? ''); ?>" autocomplete="off" />
                        <p class="description">From a free app at <a href="https://developer.spotify.com/dashboard" target="_blank" rel="noopener">developer.spotify.com/dashboard</a>. Artist stats need no special access.</p></td></tr>
                <tr><th><label for="spotify_client_secret">Client secret</label></th>
                    <td><input type="password" name="spotify_client_secret" id="spotify_client_secret" class="regular-text" value="<?php echo esc_attr($s['spotify_client_secret'] ?? ''); ?>" autocomplete="off" /></td></tr>
                <tr><th><label for="spotify_artist_id">Artist ID</label></th>
                    <td><input type="text" name="spotify_artist_id" id="spotify_artist_id" class="regular-text" value="<?php echo esc_attr($s['spotify_artist_id'] ?? ''); ?>" placeholder="e.g. 6M2wZ9GZgrQXHCFfjv46we" />
                        <p class="description">The ID in your artist page URL: open.spotify.com/artist/<strong>&lt;this&gt;</strong>.</p></td></tr>
                <tr><th>Test connection</th>
                    <td><button type="submit" name="lmeg_test" value="spotify" class="button">Save &amp; test Spotify</button></td></tr>
            </table>

            <h2>AI assistant</h2>
            <table class="form-table" role="presentation">
                <tr><th><label for="ai_api_key">Anthropic API key</label></th>
                    <td><input type="password" name="ai_api_key" id="ai_api_key" class="regular-text" value="<?php echo esc_attr($s['ai_api_key'] ?? ''); ?>" autocomplete="off" placeholder="sk-ant-..." />
                        <p class="description">From <a href="https://console.anthropic.com" target="_blank" rel="noopener">console.anthropic.com</a>. Powers the "Ask AI" page — answers questions from your own plugin data.</p></td></tr>
                <tr><th><label for="ai_model">Model</label></th>
                    <td><input type="text" name="ai_model" id="ai_model" class="regular-text" value="<?php echo esc_attr($s['ai_model'] ?? 'claude-haiku-4-5-20251001'); ?>" />
                        <p class="description">Default <code>claude-haiku-4-5-20251001</code> (fast + cheap). Swap for a larger model if you want deeper analysis.</p></td></tr>
                <tr><th>Test connection</th>
                    <td><button type="submit" name="lmeg_test" value="ai" class="button">Save &amp; test AI</button></td></tr>
            </table>

            <h2>Instagram (DM automation)</h2>
            <table class="form-table" role="presentation">
                <tr><th><label for="ig_account_id">IG account ID</label></th>
                    <td><input type="text" name="ig_account_id" id="ig_account_id" class="regular-text" value="<?php echo esc_attr($s['ig_account_id'] ?? ''); ?>" placeholder="1784XXXXXXXXXXXXX" />
                        <p class="description">The Instagram Business account ID from your Meta app's Instagram settings (not your @handle).</p></td></tr>
                <tr><th><label for="ig_page_token">Page access token</label></th>
                    <td><input type="password" name="ig_page_token" id="ig_page_token" class="regular-text" value="<?php echo esc_attr($s['ig_page_token'] ?? ''); ?>" autocomplete="off" /></td></tr>
                <tr><th><label for="ig_app_secret">App secret</label></th>
                    <td><input type="password" name="ig_app_secret" id="ig_app_secret" class="regular-text" value="<?php echo esc_attr($s['ig_app_secret'] ?? ''); ?>" autocomplete="off" />
                        <p class="description">Meta app → Settings → Basic → App Secret. Used to verify webhook signatures.</p></td></tr>
                <tr><th><label for="ig_verify_token">Webhook verify token</label></th>
                    <td><input type="text" name="ig_verify_token" id="ig_verify_token" class="regular-text" value="<?php echo esc_attr(($s['ig_verify_token'] ?? '') ?: (function_exists('lmeg_ig_verify_token') ? lmeg_ig_verify_token() : '')); ?>" />
                        <p class="description">Webhook callback URL: <code><?php echo esc_html(add_query_arg('lmeg_ig', 'webhook', home_url('/'))); ?></code> — full setup steps on the <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-instagram')); ?>">Instagram page</a>.</p></td></tr>
                <tr><th>Test connection</th>
                    <td><button type="submit" name="lmeg_test" value="instagram" class="button">Save &amp; test Instagram</button></td></tr>
            </table>

            <h2>Shop (Shopify)</h2>
            <table class="form-table" role="presentation">
                <tr><th><label for="shopify_domain">Store domain</label></th>
                    <td><input type="text" name="shopify_domain" id="shopify_domain" class="regular-text" value="<?php echo esc_attr($s['shopify_domain'] ?? ''); ?>" placeholder="loonymoonchildstore.myshopify.com" />
                        <p class="description">The <code>.myshopify.com</code> domain, not the storefront URL.</p></td></tr>
                <tr><th><label for="shopify_admin_token">Admin API access token</label></th>
                    <td><input type="password" name="shopify_admin_token" id="shopify_admin_token" class="regular-text" value="<?php echo esc_attr($s['shopify_admin_token'] ?? ''); ?>" autocomplete="off" placeholder="shpat_..." />
                        <p class="description">Shopify admin → Settings → Apps and sales channels → Develop apps → create an app with the <code>read_orders</code> Admin scope → install → copy the token.</p></td></tr>
                <tr><th><label for="attribution_window_days">Attribution window (days)</label></th>
                    <td><input type="number" min="1" max="90" name="attribution_window_days" id="attribution_window_days" class="small-text" value="<?php echo (int) ($s['attribution_window_days'] ?? 7); ?>" />
                        <p class="description">An order counts toward a broadcast if the buyer clicked (or opened) that broadcast within this many days before purchasing. Last click wins.</p></td></tr>
                <tr><th><label for="utm_source">UTM source</label></th>
                    <td><input type="text" name="utm_source" id="utm_source" class="regular-text" value="<?php echo esc_attr($s['utm_source'] ?? 'loonybin'); ?>" />
                        <p class="description">Broadcast links get <code>utm_source=&lt;this&gt;&amp;utm_medium=email&amp;utm_campaign=broadcast-N</code> appended so Shopify's own analytics see the traffic too.</p></td></tr>
                <tr><th>Test connection</th>
                    <td><button type="submit" name="lmeg_test" value="shopify" class="button">Save &amp; test Shopify</button>
                        <p class="description">Hits the store's shop endpoint with your saved token and reports the result above.</p></td></tr>
            </table>

            <h2>Welcome email</h2>
            <table class="form-table" role="presentation">
                <tr><th>Send welcome</th>
                    <td><label><input type="checkbox" name="welcome_enabled" value="1" <?php checked($s['welcome_enabled']); ?> /> Auto-send a welcome email on new signups (email channel only)</label></td></tr>
                <tr><th><label for="welcome_subject">Subject</label></th>
                    <td><input type="text" name="welcome_subject" id="welcome_subject" value="<?php echo esc_attr($s['welcome_subject']); ?>" class="regular-text" /></td></tr>
                <tr><th><label for="welcome_body">Body</label></th>
                    <td><textarea name="welcome_body" id="welcome_body" rows="6" class="large-text"><?php echo esc_textarea($s['welcome_body']); ?></textarea>
                        <p class="description">Merge tags: <code>{name}</code>, <code>{email}</code>, <code>{site_name}</code>, <code>{site_url}</code>.</p></td></tr>
            </table>

            <h2>Tracking</h2>
            <table class="form-table" role="presentation">
                <tr><th>Open tracking</th>
                    <td><label><input type="checkbox" name="tracking_opens" value="1" <?php checked($s['tracking_opens']); ?> /> Embed a 1×1 pixel in broadcast emails to detect opens</label></td></tr>
                <tr><th>Click tracking</th>
                    <td><label><input type="checkbox" name="tracking_clicks" value="1" <?php checked($s['tracking_clicks']); ?> /> Rewrite links in broadcast emails to record clicks (then redirect)</label></td></tr>
            </table>

            <h2>Stripe (paid membership)</h2>
            <table class="form-table" role="presentation">
                <tr><th>Mode</th>
                    <td>
                        <label><input type="radio" name="stripe_mode" value="test" <?php checked($s['stripe_mode'], 'test'); ?> /> Test</label>
                        &nbsp;&nbsp;
                        <label><input type="radio" name="stripe_mode" value="live" <?php checked($s['stripe_mode'], 'live'); ?> /> Live</label>
                    </td>
                </tr>
                <tr><th>Test publishable key</th><td><input type="text" name="stripe_test_pk" class="regular-text" value="<?php echo esc_attr($s['stripe_test_pk']); ?>" placeholder="pk_test_..." autocomplete="off" /></td></tr>
                <tr><th>Test secret key</th><td><input type="password" name="stripe_test_sk" class="regular-text" value="<?php echo esc_attr($s['stripe_test_sk']); ?>" placeholder="sk_test_..." autocomplete="off" /></td></tr>
                <tr><th>Test webhook secret</th><td><input type="password" name="stripe_test_webhook_sec" class="regular-text" value="<?php echo esc_attr($s['stripe_test_webhook_sec']); ?>" placeholder="whsec_..." autocomplete="off" /></td></tr>
                <tr><th>Live publishable key</th><td><input type="text" name="stripe_live_pk" class="regular-text" value="<?php echo esc_attr($s['stripe_live_pk']); ?>" placeholder="pk_live_..." autocomplete="off" /></td></tr>
                <tr><th>Live secret key</th><td><input type="password" name="stripe_live_sk" class="regular-text" value="<?php echo esc_attr($s['stripe_live_sk']); ?>" placeholder="sk_live_..." autocomplete="off" /></td></tr>
                <tr><th>Live webhook secret</th><td><input type="password" name="stripe_live_webhook_sec" class="regular-text" value="<?php echo esc_attr($s['stripe_live_webhook_sec']); ?>" placeholder="whsec_..." autocomplete="off" /></td></tr>
                <tr><th>Webhook endpoint URL</th><td>
                    <input type="text" readonly class="regular-text" value="<?php echo esc_attr(add_query_arg('lmeg_member','webhook', home_url('/'))); ?>" onclick="this.select();" />
                    <p class="description">Paste this into Stripe → Developers → Webhooks. Listen for <code>checkout.session.completed</code>, <code>customer.subscription.updated</code>, <code>customer.subscription.deleted</code>.</p>
                </td></tr>
            </table>

            <h2>Membership behavior</h2>
            <table class="form-table" role="presentation">
                <tr><th><label for="default_post_access">Default access for new posts</label></th>
                    <td>
                        <select name="default_post_access" id="default_post_access">
                            <option value="public" <?php selected($s['default_post_access'], 'public'); ?>>Public — anyone can read</option>
                            <option value="free"   <?php selected($s['default_post_access'], 'free'); ?>>Free members (email opt-in)</option>
                            <option value="paid"   <?php selected($s['default_post_access'], 'paid'); ?>>Any paid member</option>
                        </select>
                        <p class="description">Individual posts can override this via the Access meta box in the editor.</p>
                    </td></tr>
                <tr><th><label for="member_cookie_days">Member session (days)</label></th>
                    <td><input type="number" min="1" name="member_cookie_days" id="member_cookie_days" class="small-text" value="<?php echo esc_attr($s['member_cookie_days']); ?>" /></td></tr>
                <tr><th><label for="magic_link_ttl_hours">Magic link TTL (hours)</label></th>
                    <td><input type="number" min="1" name="magic_link_ttl_hours" id="magic_link_ttl_hours" class="small-text" value="<?php echo esc_attr($s['magic_link_ttl_hours']); ?>" /></td></tr>
                <tr><th><label for="paywall_heading">Paywall heading</label></th>
                    <td><input type="text" name="paywall_heading" id="paywall_heading" class="regular-text" value="<?php echo esc_attr($s['paywall_heading']); ?>" placeholder="Unlock <?php echo esc_attr(get_bloginfo('name')); ?>" />
                        <p class="description">The big heading at the top of the paywall, right under the 🔓 icon. Falls back to <em>"Unlock &lt;site name&gt;"</em>.</p></td></tr>
                <tr><th><label for="paywall_unlock_label">Unlock button label</label></th>
                    <td><input type="text" name="paywall_unlock_label" id="paywall_unlock_label" class="regular-text" value="<?php echo esc_attr($s['paywall_unlock_label']); ?>" placeholder="Unlock" /></td></tr>
                <tr><th><label for="paywall_premium_label">Premium button label</label></th>
                    <td><input type="text" name="paywall_premium_label" id="paywall_premium_label" class="regular-text" value="<?php echo esc_attr($s['paywall_premium_label']); ?>" placeholder="Get premium access" />
                        <p class="description">The button below the "or" divider that reveals tier options on click.</p></td></tr>
                <tr><th><label for="logo_url">Logo (above card)</label></th>
                    <td>
                        <input type="url" name="logo_url" id="logo_url" class="regular-text" value="<?php echo esc_attr($s['logo_url'] ?? ''); ?>" placeholder="https://…/logo.png" />
                        <button type="button" class="button" id="lmeg-pick-logo">Choose from media library</button>
                        <p class="description">Optional image shown above the paywall card. Upload via WP Media Library (or paste any URL).</p>
                        <?php if (!empty($s['logo_url'])) : ?>
                            <p style="margin-top:8px;"><img src="<?php echo esc_url($s['logo_url']); ?>" alt="" style="max-width:200px;max-height:120px;object-fit:contain;border:1px solid #ddd;padding:6px;background:#fafafa;" /></p>
                        <?php endif; ?>
                    </td></tr>
                <tr><th><label for="logo_max_width">Logo max width (px)</label></th>
                    <td><input type="number" name="logo_max_width" id="logo_max_width" class="small-text" min="20" max="800" value="<?php echo (int) ($s['logo_max_width'] ?? 200); ?>" /> px
                        <p class="description">Constrain the logo width so it doesn't overflow the paywall card.</p></td></tr>
                <tr><th><label for="upgrade_heading">Upgrade heading (hard paywall)</label></th>
                    <td><input type="text" name="upgrade_heading" id="upgrade_heading" class="regular-text" value="<?php echo esc_attr($s['upgrade_heading']); ?>" /></td></tr>
                <tr><th><label for="upgrade_message">Upgrade message (hard paywall)</label></th>
                    <td><textarea name="upgrade_message" id="upgrade_message" rows="2" class="large-text"><?php echo esc_textarea($s['upgrade_message']); ?></textarea></td></tr>
                <tr><th><label for="soft_paywall_heading">Soft paywall heading</label></th>
                    <td><input type="text" name="soft_paywall_heading" id="soft_paywall_heading" class="regular-text" value="<?php echo esc_attr($s['soft_paywall_heading']); ?>" />
                        <p class="description">Shown on posts marked "Paid, or continue free". Softer ask, "Not right now" link is added below the tier buttons.</p></td></tr>
                <tr><th><label for="soft_paywall_message">Soft paywall message</label></th>
                    <td><textarea name="soft_paywall_message" id="soft_paywall_message" rows="2" class="large-text"><?php echo esc_textarea($s['soft_paywall_message']); ?></textarea></td></tr>
                <tr><th><label for="signin_heading">Sign-in heading</label></th>
                    <td><input type="text" name="signin_heading" id="signin_heading" class="regular-text" value="<?php echo esc_attr($s['signin_heading']); ?>" /></td></tr>
                <tr><th><label for="signin_message">Sign-in message</label></th>
                    <td><textarea name="signin_message" id="signin_message" rows="2" class="large-text"><?php echo esc_textarea($s['signin_message']); ?></textarea></td></tr>
                <tr><th><label for="magic_link_subject">Magic link email subject</label></th>
                    <td><input type="text" name="magic_link_subject" id="magic_link_subject" class="regular-text" value="<?php echo esc_attr($s['magic_link_subject']); ?>" /></td></tr>
                <tr><th><label for="magic_link_body">Magic link email body</label></th>
                    <td><textarea name="magic_link_body" id="magic_link_body" rows="5" class="large-text"><?php echo esc_textarea($s['magic_link_body']); ?></textarea>
                        <p class="description">Use <code>{magic_link}</code> for the link, plus the usual merge tags.</p></td></tr>
            </table>

            <h2>Compliance &amp; feed copy</h2>
            <table class="form-table" role="presentation">
                <tr><th><label for="unsub_footer_text">Email unsubscribe footer</label></th>
                    <td><textarea name="unsub_footer_text" id="unsub_footer_text" rows="2" class="large-text"><?php echo esc_textarea($s['unsub_footer_text']); ?></textarea>
                        <p class="description">Appended to every broadcast email. Use <code>{unsub_url}</code> as the placeholder for the unsubscribe link. Required by CASL (Canada) and CAN-SPAM (US).</p></td></tr>
                <tr><th><label for="feed_teaser_text">RSS / REST teaser</label></th>
                    <td><input type="text" name="feed_teaser_text" id="feed_teaser_text" value="<?php echo esc_attr($s['feed_teaser_text']); ?>" class="regular-text" />
                        <p class="description">Replaces the post body in the RSS feed and REST API when single-post gating is on. A link to the post is appended automatically.</p></td></tr>
            </table>

            <?php submit_button(); ?>
        </form>
        <hr />
        <p><em>Logged-in editors and admins always see the full post content (handy for previewing without unlocking).</em></p>

        <?php
        // Map setting keys → the input name(s) in this form so JS can lock them.
        $env_field_names = [
            'spotify_client_id' => 'spotify_client_id', 'spotify_client_secret' => 'spotify_client_secret',
            'ai_api_key' => 'ai_api_key', 'ai_model' => 'ai_model',
            'brevo_api_key' => 'brevo_api_key',
            'twilio_account_sid' => 'twilio_account_sid', 'twilio_auth_token' => 'twilio_auth_token', 'twilio_from_number' => 'twilio_from_number',
            'stripe_test_sk' => 'stripe_test_sk', 'stripe_test_pk' => 'stripe_test_pk', 'stripe_test_webhook_sec' => 'stripe_test_webhook_sec',
            'stripe_live_sk' => 'stripe_live_sk', 'stripe_live_pk' => 'stripe_live_pk', 'stripe_live_webhook_sec' => 'stripe_live_webhook_sec',
            'ig_app_secret' => 'ig_app_secret', 'ig_page_token' => 'ig_page_token',
            'shopify_admin_token' => 'shopify_admin_token',
        ];
        $locked = [];
        foreach ($env_keys as $k) {
            if (isset($env_field_names[$k])) $locked[] = $env_field_names[$k];
        }
        if ($locked) : ?>
            <script>
            (function(){
                var locked = <?php echo wp_json_encode($locked); ?>;
                locked.forEach(function(name){
                    var el = document.querySelector('[name="'+name+'"]');
                    if(!el) return;
                    el.readOnly = true;
                    el.style.opacity = '.6';
                    el.value = el.value || '••••••••';
                    var badge = document.createElement('span');
                    badge.textContent = ' 🔒 from wp-config/.env';
                    badge.style.cssText = 'margin-left:8px;font-size:12px;color:#8B90A0;';
                    if(el.parentNode) el.parentNode.insertBefore(badge, el.nextSibling);
                });
            })();
            </script>
        <?php endif; ?>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * AJAX: live audience count for the Compose page
 * ------------------------------------------------------------------------- */

add_action('wp_ajax_lmeg_audience_count', 'lmeg_ajax_audience_count');
function lmeg_ajax_audience_count() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['msg' => 'forbidden'], 403);
    }
    check_ajax_referer('lmeg_audience', 'nonce');

    $tag_ids   = array_filter(array_map('intval', (array) ($_POST['tag_ids'] ?? [])));
    $match     = ($_POST['match'] ?? 'any') === 'all' ? 'all' : 'any';
    $has_email = !empty($_POST['has_email']);
    $has_sms   = !empty($_POST['has_sms']);

    // If neither body channel claims to want recipients, count nothing — the
    // queue_broadcast guard rejects empty broadcasts anyway.
    if (!$has_email && !$has_sms) {
        wp_send_json_success(['count' => 0]);
    }

    $count = lmeg_audience_count(
        ['tag_ids' => $tag_ids, 'match' => $match],
        ['email'   => $has_email, 'sms' => $has_sms]
    );
    wp_send_json_success(['count' => $count]);
}

/* ---------------------------------------------------------------------------
 * Email preview — renders the current compose body through the real branded
 * template and returns a full HTML document, opened in a new browser tab.
 * Posted from the Compose page; sample merge values stand in for per-recipient
 * data so the preview reads naturally.
 * ------------------------------------------------------------------------- */

add_action('wp_ajax_lmeg_preview_email', 'lmeg_ajax_preview_email');
function lmeg_ajax_preview_email() {
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
    check_admin_referer('lmeg_preview');

    $body = isset($_POST['body']) ? wp_kses_post(wp_unslash($_POST['body'])) : '';
    if (trim($body) === '') {
        $body = '<p>(No email content yet — add some blocks or type something, then preview again.)</p>';
    }

    // Fill merge tags with sample values so the preview reads like a real send.
    $samples = [
        '{name}'          => 'Alex',
        '{email}'         => 'alex@example.com',
        '{unique_code}'   => 'LOONY-7Q2X',
        '{referral_link}' => home_url('/?ref=LOONY-7Q2X'),
        '{site_name}'     => get_bloginfo('name'),
    ];
    $body = str_replace(array_keys($samples), array_values($samples), $body);

    $s      = lmeg_get_settings();
    $footer = 'You&rsquo;re getting this because you subscribed at ' . esc_html(get_bloginfo('name'))
            . '. <a href="#">Unsubscribe</a>';

    if (!empty($s['email_template_enabled']) && function_exists('lmeg_branded_email_html')) {
        $inner = lmeg_branded_email_html($body, $footer);
    } else {
        $inner = '<div style="max-width:600px;margin:0 auto;padding:24px;font-family:-apple-system,Segoe UI,Roboto,sans-serif;">' . $body . '</div>';
    }

    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Email preview &mdash; ' . esc_html(get_bloginfo('name')) . '</title>'
       . '<style>body{margin:0;background:#faf6f1;}'
       . '.lmeg-pvbar{font:13px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;background:#12141f;color:#f4f5f7;padding:11px 16px;text-align:center;}'
       . '.lmeg-pvbar b{color:#d05fa2;}</style></head><body>'
       . '<div class="lmeg-pvbar">Email preview &mdash; sample merge values shown. <b>Preview only, nothing was sent.</b></div>'
       . $inner
       . '</body></html>';
    exit;
}

/* ---------------------------------------------------------------------------
 * CSV export
 * ------------------------------------------------------------------------- */

add_action('admin_post_lmeg_export', 'lmeg_export_csv');
function lmeg_export_csv() {
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
    check_admin_referer('lmeg_export');

    global $wpdb;
    $table = $wpdb->prefix . LMEG_TABLE;
    $rows  = $wpdb->get_results(
        "SELECT contact_type, email, phone, country, street, city, region, postal_code, post_id, ip, referrer, created_at, unsubscribed_at FROM $table ORDER BY created_at DESC",
        ARRAY_A
    );

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="loonymoon-subscribers-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['contact_type', 'email', 'phone', 'country', 'street', 'city', 'region', 'postal_code', 'post_id', 'ip', 'referrer', 'created_at', 'unsubscribed_at']);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

/* ---------------------------------------------------------------------------
 * Segments — saved tag filters
 * ------------------------------------------------------------------------- */

function lmeg_admin_segments() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_segments';
    $notice = '';

    if (isset($_POST['lmeg_seg_nonce']) && wp_verify_nonce($_POST['lmeg_seg_nonce'], 'lmeg_segs')) {
        $act = sanitize_text_field($_POST['lmeg_action'] ?? '');
        if ($act === 'save') {
            $id      = (int) ($_POST['seg_id'] ?? 0);
            $name    = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $tag_ids = array_filter(array_map('intval', (array) ($_POST['tag_ids'] ?? [])));
            $match   = ($_POST['match_mode'] ?? 'any') === 'all' ? 'all' : 'any';
            if ($name) {
                $data = [
                    'name'       => $name,
                    'tag_ids'    => wp_json_encode($tag_ids),
                    'match_mode' => $match,
                ];
                if ($id) {
                    $wpdb->update($tbl, $data, ['id' => $id]);
                } else {
                    $data['created_at'] = current_time('mysql');
                    $wpdb->insert($tbl, $data);
                }
                $notice = '<div class="notice notice-success"><p>Segment saved.</p></div>';
            }
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['seg_id'] ?? 0);
            if ($id) {
                $wpdb->delete($tbl, ['id' => $id]);
                $notice = '<div class="notice notice-success"><p>Segment deleted.</p></div>';
            }
        }
    }

    $segments = $wpdb->get_results("SELECT * FROM $tbl ORDER BY name ASC");
    $all_tags = lmeg_all_tags();
    $tag_by_id = [];
    foreach ($all_tags as $t) $tag_by_id[(int) $t->id] = $t;
    ?>
    <div class="wrap">
        <h1>Email Gate — Segments</h1>
        <?php echo $notice; ?>
        <p>Saved tag filters. Pick a segment from the Compose screen instead of re-checking the same tags every time.</p>

        <h2>Create a segment</h2>
        <form method="post" style="margin-bottom:24px;">
            <?php wp_nonce_field('lmeg_segs', 'lmeg_seg_nonce'); ?>
            <input type="hidden" name="lmeg_action" value="save" />
            <table class="form-table" role="presentation">
                <tr><th>Name</th><td><input type="text" name="name" class="regular-text" required placeholder="e.g. Canadian email subscribers" /></td></tr>
                <tr><th>Tags</th><td>
                    <div class="lmeg-audience-tags" style="max-width:720px;">
                        <?php foreach ($all_tags as $t) : ?>
                            <label class="lmeg-chip-label"><input type="checkbox" name="tag_ids[]" value="<?php echo (int) $t->id; ?>" /><?php echo lmeg_render_tag_chip($t, ['count' => $t->member_count]); ?></label>
                        <?php endforeach; ?>
                    </div>
                </td></tr>
                <tr><th>Match</th><td>
                    <label><input type="radio" name="match_mode" value="any" checked /> Any of the selected tags</label>
                    <label style="margin-left:16px;"><input type="radio" name="match_mode" value="all" /> All of the selected tags</label>
                </td></tr>
            </table>
            <p><button type="submit" class="button button-primary">Save segment</button></p>
        </form>

        <h2>All segments</h2>
        <table class="widefat striped">
            <thead><tr><th>Name</th><th>Tags</th><th>Match</th><th>Size</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($segments)) : ?>
                <tr><td colspan="5">No segments yet.</td></tr>
            <?php else : foreach ($segments as $seg) :
                $tag_ids = json_decode($seg->tag_ids, true) ?: [];
                $chips = '';
                foreach ($tag_ids as $tid) if (isset($tag_by_id[$tid])) $chips .= lmeg_render_tag_chip($tag_by_id[$tid]) . ' ';
                $count = lmeg_audience_count(['tag_ids' => $tag_ids, 'match' => $seg->match_mode], ['email' => 1, 'sms' => 1]);
            ?>
                <tr>
                    <td><strong><?php echo esc_html($seg->name); ?></strong></td>
                    <td><?php echo $chips ?: '—'; ?></td>
                    <td><?php echo esc_html($seg->match_mode); ?></td>
                    <td><?php echo (int) $count; ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Delete segment?');" style="display:inline;">
                            <?php wp_nonce_field('lmeg_segs', 'lmeg_seg_nonce'); ?>
                            <input type="hidden" name="lmeg_action" value="delete" />
                            <input type="hidden" name="seg_id" value="<?php echo (int) $seg->id; ?>" />
                            <button type="submit" class="button button-link-delete">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Templates — reusable subject + body pairs
 * ------------------------------------------------------------------------- */

function lmeg_admin_templates() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_templates';
    $notice = '';

    if (isset($_POST['lmeg_tpl_nonce']) && wp_verify_nonce($_POST['lmeg_tpl_nonce'], 'lmeg_tpls')) {
        $act = sanitize_text_field($_POST['lmeg_action'] ?? '');
        if ($act === 'save') {
            $id         = (int) ($_POST['tpl_id'] ?? 0);
            $name       = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $subject    = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
            $body_email = wp_kses_post(wp_unslash($_POST['body_email'] ?? ''));
            $body_sms   = sanitize_textarea_field(wp_unslash($_POST['body_sms'] ?? ''));
            if ($name) {
                $data = compact('name', 'subject', 'body_email', 'body_sms');
                if ($id) {
                    $wpdb->update($tbl, $data, ['id' => $id]);
                } else {
                    $data['created_at'] = current_time('mysql');
                    $wpdb->insert($tbl, $data);
                }
                $notice = '<div class="notice notice-success"><p>Template saved.</p></div>';
            }
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['tpl_id'] ?? 0);
            if ($id) {
                $wpdb->delete($tbl, ['id' => $id]);
                $notice = '<div class="notice notice-success"><p>Template deleted.</p></div>';
            }
        }
    }

    $edit_id = (int) ($_GET['edit'] ?? 0);
    $edit    = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id = %d", $edit_id)) : null;
    $rows    = $wpdb->get_results("SELECT * FROM $tbl ORDER BY name ASC");
    ?>
    <div class="wrap">
        <h1>Email Gate — Templates</h1>
        <?php echo $notice; ?>
        <p>Reusable subject + body pairs. Merge tags: <code>{name}</code>, <code>{email}</code>, <code>{phone}</code>, <code>{city}</code>, <code>{country}</code>, <code>{site_name}</code>, <code>{site_url}</code>, <code>{unique_code}</code>, <code>{referral_link}</code>.</p>

        <h2><?php echo $edit ? 'Edit' : 'Create'; ?> template</h2>
        <form method="post">
            <?php wp_nonce_field('lmeg_tpls', 'lmeg_tpl_nonce'); ?>
            <input type="hidden" name="lmeg_action" value="save" />
            <?php if ($edit) : ?><input type="hidden" name="tpl_id" value="<?php echo (int) $edit->id; ?>" /><?php endif; ?>
            <table class="form-table" role="presentation">
                <tr><th>Name</th><td><input type="text" name="name" class="regular-text" required value="<?php echo esc_attr($edit->name ?? ''); ?>" /></td></tr>
                <tr><th>Subject</th><td><input type="text" name="subject" class="regular-text" value="<?php echo esc_attr($edit->subject ?? ''); ?>" /></td></tr>
                <tr><th>Email body</th><td><?php
                    wp_editor($edit->body_email ?? '', 'tpl_body_email', [
                        'textarea_name' => 'body_email',
                        'textarea_rows' => 10,
                        'media_buttons' => true,
                        'quicktags'     => true,
                    ]);
                ?></td></tr>
                <tr><th>SMS body</th><td><textarea name="body_sms" rows="3" class="large-text" maxlength="1600"><?php echo esc_textarea($edit->body_sms ?? ''); ?></textarea></td></tr>
            </table>
            <p><button type="submit" class="button button-primary"><?php echo $edit ? 'Update' : 'Save'; ?> template</button>
               <?php if ($edit) : ?><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-templates')); ?>" class="button">Cancel edit</a><?php endif; ?></p>
        </form>

        <h2>All templates</h2>
        <table class="widefat striped">
            <thead><tr><th>Name</th><th>Subject</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="3">No templates yet.</td></tr>
            <?php else : foreach ($rows as $t) : ?>
                <tr>
                    <td><strong><?php echo esc_html($t->name); ?></strong></td>
                    <td><?php echo esc_html($t->subject ?: '—'); ?></td>
                    <td>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-templates&edit=' . $t->id)); ?>" class="button">Edit</a>
                        <form method="post" onsubmit="return confirm('Delete template?');" style="display:inline;">
                            <?php wp_nonce_field('lmeg_tpls', 'lmeg_tpl_nonce'); ?>
                            <input type="hidden" name="lmeg_action" value="delete" />
                            <input type="hidden" name="tpl_id" value="<?php echo (int) $t->id; ?>" />
                            <button type="submit" class="button button-link-delete">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Sequences — drip campaigns
 * ------------------------------------------------------------------------- */

function lmeg_admin_sequences() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $seq_tbl  = $wpdb->prefix . 'lmeg_sequences';
    $step_tbl = $wpdb->prefix . 'lmeg_sequence_steps';
    $enr_tbl  = $wpdb->prefix . 'lmeg_sequence_enrollments';
    $notice = '';

    if (isset($_POST['lmeg_seq_nonce']) && wp_verify_nonce($_POST['lmeg_seq_nonce'], 'lmeg_seqs')) {
        $act = sanitize_text_field($_POST['lmeg_action'] ?? '');
        if ($act === 'save_seq') {
            $id      = (int) ($_POST['seq_id'] ?? 0);
            $name    = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $trigger = (int) ($_POST['trigger_tag_id'] ?? 0);
            $active  = !empty($_POST['is_active']) ? 1 : 0;
            if ($name) {
                $data = ['name' => $name, 'trigger_tag_id' => $trigger ?: null, 'is_active' => $active];
                if ($id) {
                    $wpdb->update($seq_tbl, $data, ['id' => $id]);
                } else {
                    $data['created_at'] = current_time('mysql');
                    $wpdb->insert($seq_tbl, $data);
                }
                $notice = '<div class="notice notice-success"><p>Sequence saved.</p></div>';
            }
        } elseif ($act === 'delete_seq') {
            $id = (int) ($_POST['seq_id'] ?? 0);
            if ($id) {
                $wpdb->delete($seq_tbl, ['id' => $id]);
                $wpdb->delete($step_tbl, ['sequence_id' => $id]);
                $wpdb->delete($enr_tbl, ['sequence_id' => $id]);
                $notice = '<div class="notice notice-success"><p>Sequence deleted.</p></div>';
            }
        } elseif ($act === 'save_step') {
            $id          = (int) ($_POST['step_id'] ?? 0);
            $sequence_id = (int) ($_POST['sequence_id'] ?? 0);
            $position    = max(1, (int) ($_POST['position'] ?? 1));
            $delay       = max(0, (int) ($_POST['delay_days'] ?? 0));
            $subject     = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
            $body_email  = wp_kses_post(wp_unslash($_POST['body_email'] ?? ''));
            $body_sms    = sanitize_textarea_field(wp_unslash($_POST['body_sms'] ?? ''));
            if ($sequence_id) {
                $data = compact('sequence_id', 'position', 'delay_days', 'subject', 'body_email', 'body_sms');
                $data['delay_days'] = $delay;
                if ($id) {
                    $wpdb->update($step_tbl, $data, ['id' => $id]);
                } else {
                    $wpdb->insert($step_tbl, $data);
                }
                $notice = '<div class="notice notice-success"><p>Step saved.</p></div>';
            }
        } elseif ($act === 'delete_step') {
            $id = (int) ($_POST['step_id'] ?? 0);
            if ($id) {
                $wpdb->delete($step_tbl, ['id' => $id]);
                $notice = '<div class="notice notice-success"><p>Step deleted.</p></div>';
            }
        }
    }

    $edit_id = (int) ($_GET['edit'] ?? 0);
    $edit    = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $seq_tbl WHERE id = %d", $edit_id)) : null;
    $all_tags = lmeg_all_tags();

    if ($edit) {
        // Detail view for one sequence + its steps
        $steps  = lmeg_sequence_steps($edit->id);
        $active_enr = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $enr_tbl WHERE sequence_id = %d AND status = 'active'", $edit->id));
        $done_enr   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $enr_tbl WHERE sequence_id = %d AND status = 'completed'", $edit->id));
        ?>
        <div class="wrap">
            <h1>Sequence: <?php echo esc_html($edit->name); ?></h1>
            <?php echo $notice; ?>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-sequences')); ?>">← All sequences</a></p>

            <form method="post">
                <?php wp_nonce_field('lmeg_seqs', 'lmeg_seq_nonce'); ?>
                <input type="hidden" name="lmeg_action" value="save_seq" />
                <input type="hidden" name="seq_id" value="<?php echo (int) $edit->id; ?>" />
                <table class="form-table" role="presentation">
                    <tr><th>Name</th><td><input type="text" name="name" class="regular-text" value="<?php echo esc_attr($edit->name); ?>" required /></td></tr>
                    <tr><th>Trigger tag</th><td>
                        <select name="trigger_tag_id">
                            <option value="">(no trigger — enroll manually)</option>
                            <?php foreach ($all_tags as $t) : ?>
                                <option value="<?php echo (int) $t->id; ?>" <?php selected((int) $edit->trigger_tag_id, (int) $t->id); ?>><?php echo esc_html($t->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">When a subscriber gets this tag, they're enrolled from step 1.</p>
                    </td></tr>
                    <tr><th>Active</th><td><label><input type="checkbox" name="is_active" value="1" <?php checked($edit->is_active); ?> /> Send steps to enrolled subscribers</label></td></tr>
                </table>
                <p><button type="submit" class="button button-primary">Save sequence</button></p>
            </form>

            <p><strong><?php echo $active_enr; ?></strong> currently enrolled · <strong><?php echo $done_enr; ?></strong> completed</p>

            <h2>Steps</h2>
            <table class="widefat striped">
                <thead><tr><th>#</th><th>Delay</th><th>Subject</th><th>Sent</th><th>Opens</th><th>Clicks</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($steps)) : ?>
                    <tr><td colspan="7">No steps yet — add one below.</td></tr>
                <?php else : foreach ($steps as $step) :
                    $eng  = function_exists('lmeg_email_engagement') ? lmeg_email_engagement('sequence', (int) $step->id) : ['opens'=>0,'clicks'=>0];
                    $sent = (int) ($step->sends ?? 0);
                    $orate = $sent ? round(100 * $eng['opens'] / $sent) : 0;
                    $crate = $sent ? round(100 * $eng['clicks'] / $sent) : 0;
                ?>
                    <tr>
                        <td><?php echo (int) $step->position; ?></td>
                        <td><?php echo (int) $step->delay_days; ?>d</td>
                        <td><?php echo esc_html($step->subject ?: '—'); ?></td>
                        <td><strong><?php echo $sent; ?></strong></td>
                        <td><?php echo $eng['opens']; ?><?php echo $sent ? ' <span style="opacity:.5;">(' . $orate . '%)</span>' : ''; ?></td>
                        <td><?php echo $eng['clicks']; ?><?php echo $sent ? ' <span style="opacity:.5;">(' . $crate . '%)</span>' : ''; ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Delete step?');" style="display:inline;">
                                <?php wp_nonce_field('lmeg_seqs', 'lmeg_seq_nonce'); ?>
                                <input type="hidden" name="lmeg_action" value="delete_step" />
                                <input type="hidden" name="step_id" value="<?php echo (int) $step->id; ?>" />
                                <button type="submit" class="button button-link-delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <h2>Add a step</h2>
            <form method="post">
                <?php wp_nonce_field('lmeg_seqs', 'lmeg_seq_nonce'); ?>
                <input type="hidden" name="lmeg_action" value="save_step" />
                <input type="hidden" name="sequence_id" value="<?php echo (int) $edit->id; ?>" />
                <table class="form-table" role="presentation">
                    <tr><th>Position</th><td><input type="number" name="position" value="<?php echo count($steps) + 1; ?>" min="1" class="small-text" /></td></tr>
                    <tr><th>Delay (days after prior step / enrollment)</th><td><input type="number" name="delay_days" value="0" min="0" class="small-text" /></td></tr>
                    <tr><th>Subject</th><td><input type="text" name="subject" class="regular-text" placeholder="{name}, a follow-up" /></td></tr>
                    <tr><th>Email body</th><td><textarea name="body_email" rows="6" class="large-text"></textarea></td></tr>
                    <tr><th>SMS body</th><td><textarea name="body_sms" rows="3" class="large-text" maxlength="1600"></textarea></td></tr>
                </table>
                <p><button type="submit" class="button button-primary">Add step</button></p>
            </form>
        </div>
        <?php
        return;
    }

    $seqs = $wpdb->get_results("SELECT * FROM $seq_tbl ORDER BY name ASC");
    ?>
    <div class="wrap">
        <h1>Email Gate — Sequences</h1>
        <?php echo $notice; ?>
        <p>Automated multi-step journeys. Pick a trigger tag; every subscriber who receives that tag gets enrolled and works through the steps at the pace you set.</p>

        <?php
        // Welcome email analytics card.
        $wsent = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE welcome_sent_at IS NOT NULL");
        $weng  = function_exists('lmeg_email_engagement') ? lmeg_email_engagement('welcome', 0) : ['opens'=>0,'clicks'=>0];
        $s_all = lmeg_get_settings();
        $wo = $wsent ? round(100 * $weng['opens'] / $wsent) : 0;
        $wc = $wsent ? round(100 * $weng['clicks'] / $wsent) : 0;
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;max-width:640px;margin:14px 0 6px;">
            <div class="lmeg-stat"><div class="lmeg-stat__label">Welcome email</div>
                <div class="lmeg-stat__value" style="font-size:18px;"><?php echo empty($s_all['welcome_enabled']) ? 'Off' : 'On'; ?></div>
                <div class="lmeg-stat__hint"><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings')); ?>">Edit in Settings</a></div></div>
            <div class="lmeg-stat"><div class="lmeg-stat__label">Sent</div>
                <div class="lmeg-stat__value"><?php echo number_format_i18n($wsent); ?></div>
                <div class="lmeg-stat__hint">welcome emails</div></div>
            <div class="lmeg-stat"><div class="lmeg-stat__label">Opens</div>
                <div class="lmeg-stat__value"><?php echo (int) $weng['opens']; ?></div>
                <div class="lmeg-stat__hint"><?php echo $wo; ?>% open rate</div></div>
            <div class="lmeg-stat"><div class="lmeg-stat__label">Clicks</div>
                <div class="lmeg-stat__value"><?php echo (int) $weng['clicks']; ?></div>
                <div class="lmeg-stat__hint"><?php echo $wc; ?>% click rate</div></div>
        </div>
        <p class="description" style="max-width:760px;">
            <strong>Recipes — trigger tag → journey:</strong><br />
            · <code>channel:email</code> → welcome series for every new email signup<br />
            · <code>customer</code> → post-purchase thank-you / upsell (applied automatically on their first shop order)<br />
            · <code>channel:paid</code> or <code>tier:&lt;slug&gt;</code> → new paid-member onboarding<br />
            · <code>fan-type:dormant</code> → win-back campaign (fan types refresh daily)<br />
            · any manual tag → whatever you dream up — bulk-apply from the Subscribers page to enroll a batch
        </p>

        <h2>Create a sequence</h2>
        <form method="post" style="margin-bottom:24px;">
            <?php wp_nonce_field('lmeg_seqs', 'lmeg_seq_nonce'); ?>
            <input type="hidden" name="lmeg_action" value="save_seq" />
            <table class="form-table" role="presentation">
                <tr><th>Name</th><td><input type="text" name="name" class="regular-text" required placeholder="e.g. New subscriber welcome series" /></td></tr>
                <tr><th>Trigger tag</th><td>
                    <select name="trigger_tag_id">
                        <option value="">(no trigger — enroll manually)</option>
                        <?php foreach ($all_tags as $t) : ?>
                            <option value="<?php echo (int) $t->id; ?>"><?php echo esc_html($t->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td></tr>
                <tr><th>Active</th><td><label><input type="checkbox" name="is_active" value="1" /> Send steps to enrolled subscribers</label></td></tr>
            </table>
            <p><button type="submit" class="button button-primary">Create sequence</button></p>
        </form>

        <h2>All sequences</h2>
        <table class="widefat striped">
            <thead><tr><th>Name</th><th>Trigger</th><th>Steps</th><th>Active</th><th>Enrolled</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($seqs)) : ?>
                <tr><td colspan="6">No sequences yet.</td></tr>
            <?php else : foreach ($seqs as $s) :
                $trigger_tag = null;
                foreach ($all_tags as $t) if ((int) $t->id === (int) $s->trigger_tag_id) $trigger_tag = $t;
                $step_count  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $step_tbl WHERE sequence_id = %d", $s->id));
                $enrolled    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $enr_tbl WHERE sequence_id = %d AND status = 'active'", $s->id));
            ?>
                <tr>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-sequences&edit=' . $s->id)); ?>"><strong><?php echo esc_html($s->name); ?></strong></a></td>
                    <td><?php echo $trigger_tag ? lmeg_render_tag_chip($trigger_tag) : '—'; ?></td>
                    <td><?php echo $step_count; ?></td>
                    <td><?php echo $s->is_active ? '● Yes' : '○ No'; ?></td>
                    <td><?php echo $enrolled; ?></td>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-sequences&edit=' . $s->id)); ?>" class="button">Open</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Dashboard widget — 30-day growth + last broadcast
 * ------------------------------------------------------------------------- */

add_action('wp_dashboard_setup', 'lmeg_dashboard_widget_setup');
function lmeg_dashboard_widget_setup() {
    if (!current_user_can('manage_options')) return;
    wp_add_dashboard_widget('lmeg_dashboard', 'Email Gate', 'lmeg_dashboard_widget_render');
}

function lmeg_dashboard_widget_render() {
    global $wpdb;
    $subs = $wpdb->prefix . LMEG_TABLE;
    $bc   = $wpdb->prefix . 'lmeg_broadcasts';
    $ev   = $wpdb->prefix . 'lmeg_broadcast_events';

    $total  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE unsubscribed_at IS NULL");
    $unsub  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE unsubscribed_at IS NOT NULL");
    $day30  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");

    // 30 days of signup counts for a small sparkline SVG.
    $daily = $wpdb->get_results(
        "SELECT DATE(created_at) AS d, COUNT(*) AS n
         FROM $subs
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 29 DAY)
         GROUP BY DATE(created_at) ORDER BY d ASC"
    );
    $by_day = [];
    for ($i = 29; $i >= 0; $i--) {
        $by_day[date('Y-m-d', strtotime("-$i days"))] = 0;
    }
    foreach ($daily as $d) $by_day[$d->d] = (int) $d->n;
    $counts = array_values($by_day);
    $max    = max($counts) ?: 1;
    $w      = 240; $h = 48; $step = $w / max(1, count($counts) - 1);
    $points = [];
    foreach ($counts as $i => $n) {
        $x = round($i * $step, 1);
        $y = round($h - ($n / $max) * ($h - 4) - 2, 1);
        $points[] = "$x,$y";
    }
    $spark = implode(' ', $points);

    $last = $wpdb->get_row("SELECT * FROM $bc ORDER BY id DESC LIMIT 1");
    $opens = $clicks = 0;
    if ($last) {
        $opens  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT subscriber_id) FROM $ev WHERE broadcast_id = %d AND event_type = 'open'",  $last->id));
        $clicks = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT subscriber_id) FROM $ev WHERE broadcast_id = %d AND event_type = 'click'", $last->id));
    }
    ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div>
            <div style="font-size:26px;font-weight:600;line-height:1;"><?php echo number_format_i18n($total); ?></div>
            <div style="font-size:12px;opacity:.7;">Active subscribers</div>
            <div style="margin-top:12px;font-size:13px;">
                <span style="color:#1a6f1a;">+<?php echo $day30; ?></span> last 30 days ·
                <span style="opacity:.6;"><?php echo $unsub; ?> unsubscribed</span>
            </div>
            <svg viewBox="0 0 <?php echo $w; ?> <?php echo $h; ?>" width="100%" height="<?php echo $h; ?>" style="margin-top:8px;">
                <polyline fill="none" stroke="#3b82f6" stroke-width="1.5" points="<?php echo esc_attr($spark); ?>" />
            </svg>
            <?php
            // MRR / paying member line — only render if any tier exists.
            $mrr_cents = 0; $paying_n = 0; $currency = 'USD';
            if (function_exists('lmeg_all_tiers')) {
                $tiers = lmeg_all_tiers();
                $by_id = [];
                foreach ($tiers as $t) { $by_id[(int) $t->id] = $t; $currency = $t->currency; }
                $paying = $wpdb->get_results("SELECT member_tier_id, billing_interval FROM $subs WHERE member_status = 'active' AND member_tier_id IS NOT NULL AND stripe_subscription_id IS NOT NULL");
                foreach ($paying as $p) {
                    $paying_n++;
                    $t = $by_id[(int) $p->member_tier_id] ?? null;
                    if (!$t) continue;
                    if ($p->billing_interval === 'year' && $t->price_annual) $mrr_cents += (int) round($t->price_annual / 12);
                    elseif ($t->price_monthly)                                $mrr_cents += (int) $t->price_monthly;
                }
            }
            if ($tiers ?? false) : ?>
                <div style="margin-top:10px;padding-top:10px;border-top:1px solid rgba(0,0,0,.08);font-size:13px;">
                    <strong><?php echo esc_html(lmeg_format_price($mrr_cents, $currency)); ?></strong> MRR ·
                    <?php echo (int) $paying_n; ?> paying
                </div>
            <?php endif; ?>
        </div>
        <div>
            <div style="font-size:12px;opacity:.6;text-transform:uppercase;letter-spacing:.05em;">Last broadcast</div>
            <?php if ($last) : ?>
                <div style="font-size:14px;font-weight:600;margin-top:4px;"><?php echo esc_html($last->subject ?: mb_substr((string) ($last->body ?? $last->body_sms), 0, 40)); ?></div>
                <div style="font-size:12px;opacity:.7;margin-top:2px;"><?php echo esc_html($last->created_at); ?></div>
                <div style="display:flex;gap:12px;margin-top:12px;font-size:13px;">
                    <div><strong><?php echo (int) $last->sent; ?></strong>/<?php echo (int) $last->total; ?> sent</div>
                    <div><strong><?php echo $opens; ?></strong> opens</div>
                    <div><strong><?php echo $clicks; ?></strong> clicks</div>
                </div>
                <div style="margin-top:10px;"><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-broadcasts&view=' . $last->id)); ?>">View →</a></div>
            <?php else : ?>
                <div style="opacity:.6;margin-top:8px;">No broadcasts yet. <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-compose')); ?>">Compose one →</a></div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Tiers admin page — Stripe-backed paid subscription products
 * ------------------------------------------------------------------------- */

function lmeg_admin_tiers() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_tiers';
    $notice = '';

    if (isset($_POST['lmeg_tier_nonce']) && wp_verify_nonce($_POST['lmeg_tier_nonce'], 'lmeg_tiers')) {
        $act = sanitize_text_field($_POST['lmeg_action'] ?? '');
        if ($act === 'save') {
            $id                   = (int) ($_POST['tier_id'] ?? 0);
            $name                 = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $slug                 = lmeg_normalize_slug($_POST['slug'] ?? $name);
            $description          = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
            $is_active            = !empty($_POST['is_active']) ? 1 : 0;
            $sort_order           = (int) ($_POST['sort_order'] ?? 0);
            $currency             = strtoupper(substr(sanitize_text_field($_POST['currency'] ?? 'USD'), 0, 3));
            $price_monthly        = $_POST['price_monthly_dollars'] !== '' ? (int) round(((float) $_POST['price_monthly_dollars']) * 100) : null;
            $price_annual         = $_POST['price_annual_dollars']  !== '' ? (int) round(((float) $_POST['price_annual_dollars'])  * 100) : null;
            $stripe_price_monthly = sanitize_text_field(wp_unslash($_POST['stripe_price_monthly'] ?? '')) ?: null;
            $stripe_price_annual  = sanitize_text_field(wp_unslash($_POST['stripe_price_annual']  ?? '')) ?: null;

            if ($name && $slug) {
                $data = compact('slug','name','description','is_active','sort_order','currency','price_monthly','price_annual','stripe_price_monthly','stripe_price_annual');
                if ($id) {
                    $wpdb->update($tbl, $data, ['id' => $id]);
                } else {
                    $data['created_at'] = current_time('mysql');
                    $wpdb->insert($tbl, $data);
                }
                $notice = '<div class="notice notice-success"><p>Tier saved.</p></div>';
            }
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['tier_id'] ?? 0);
            if ($id) {
                $wpdb->delete($tbl, ['id' => $id]);
                $notice = '<div class="notice notice-success"><p>Tier deleted.</p></div>';
            }
        }
    }

    $edit_id = (int) ($_GET['edit'] ?? 0);
    $edit    = $edit_id ? lmeg_tier($edit_id) : null;
    $rows    = lmeg_all_tiers();
    ?>
    <div class="wrap">
        <h1>Email Gate — Paid Tiers</h1>
        <?php echo $notice; ?>
        <p>Configure paid subscription tiers. Create the product + prices in Stripe first, then paste the Stripe price IDs here. Stripe keys live in <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings')); ?>">Settings → Stripe</a>.</p>

        <h2><?php echo $edit ? 'Edit tier' : 'Create tier'; ?></h2>
        <form method="post">
            <?php wp_nonce_field('lmeg_tiers', 'lmeg_tier_nonce'); ?>
            <input type="hidden" name="lmeg_action" value="save" />
            <?php if ($edit) : ?><input type="hidden" name="tier_id" value="<?php echo (int) $edit->id; ?>" /><?php endif; ?>
            <table class="form-table" role="presentation">
                <tr><th>Name</th><td><input type="text" name="name" class="regular-text" required value="<?php echo esc_attr($edit->name ?? ''); ?>" placeholder="e.g. Basic" /></td></tr>
                <tr><th>Slug</th><td><input type="text" name="slug" class="regular-text" value="<?php echo esc_attr($edit->slug ?? ''); ?>" placeholder="basic (auto)" /></td></tr>
                <tr><th>Description</th><td><textarea name="description" rows="3" class="large-text"><?php echo esc_textarea($edit->description ?? ''); ?></textarea></td></tr>
                <tr><th>Active</th><td><label><input type="checkbox" name="is_active" value="1" <?php checked($edit->is_active ?? 1, 1); ?> /> Offer this tier on the upgrade page</label></td></tr>
                <tr><th>Sort order</th><td><input type="number" name="sort_order" class="small-text" value="<?php echo (int) ($edit->sort_order ?? 0); ?>" /></td></tr>
                <tr><th>Currency</th><td><input type="text" name="currency" class="small-text" maxlength="3" value="<?php echo esc_attr($edit->currency ?? 'USD'); ?>" /></td></tr>
                <tr><th>Monthly price (display)</th><td>
                    <input type="number" step="0.01" min="0" name="price_monthly_dollars" class="small-text" value="<?php echo $edit && $edit->price_monthly ? esc_attr(number_format($edit->price_monthly / 100, 2, '.', '')) : ''; ?>" />
                    <input type="text" name="stripe_price_monthly" class="regular-text" placeholder="price_XXX (Stripe price ID)" value="<?php echo esc_attr($edit->stripe_price_monthly ?? ''); ?>" />
                </td></tr>
                <tr><th>Annual price (display)</th><td>
                    <input type="number" step="0.01" min="0" name="price_annual_dollars" class="small-text" value="<?php echo $edit && $edit->price_annual ? esc_attr(number_format($edit->price_annual / 100, 2, '.', '')) : ''; ?>" />
                    <input type="text" name="stripe_price_annual" class="regular-text" placeholder="price_XXX (Stripe price ID)" value="<?php echo esc_attr($edit->stripe_price_annual ?? ''); ?>" />
                </td></tr>
            </table>
            <p><button type="submit" class="button button-primary"><?php echo $edit ? 'Update' : 'Save'; ?> tier</button>
               <?php if ($edit) : ?><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-tiers')); ?>" class="button">Cancel edit</a><?php endif; ?></p>
        </form>

        <h2>All tiers</h2>
        <table class="widefat striped">
            <thead><tr><th>Name</th><th>Prices</th><th>Members</th><th>Active</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="5">No tiers yet.</td></tr>
            <?php else : foreach ($rows as $t) :
                $members = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE member_tier_id = %d AND member_status = 'active'",
                    $t->id
                ));
            ?>
                <tr>
                    <td><strong><?php echo esc_html($t->name); ?></strong><br><code><?php echo esc_html($t->slug); ?></code></td>
                    <td>
                        <?php if ($t->price_monthly) echo esc_html(lmeg_format_price($t->price_monthly, $t->currency)) . ' / mo<br>'; ?>
                        <?php if ($t->price_annual)  echo esc_html(lmeg_format_price($t->price_annual,  $t->currency)) . ' / yr'; ?>
                    </td>
                    <td><?php echo $members; ?></td>
                    <td><?php echo $t->is_active ? '● Active' : '○ Inactive'; ?></td>
                    <td>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lmeg-tiers&edit=' . $t->id)); ?>">Edit</a>
                        <form method="post" onsubmit="return confirm('Delete tier? Members currently on it will revert to free.');" style="display:inline;">
                            <?php wp_nonce_field('lmeg_tiers', 'lmeg_tier_nonce'); ?>
                            <input type="hidden" name="lmeg_action" value="delete" />
                            <input type="hidden" name="tier_id" value="<?php echo (int) $t->id; ?>" />
                            <button type="submit" class="button button-link-delete">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h2>Webhook endpoint</h2>
        <p>Point Stripe's webhook at:</p>
        <p><code><?php echo esc_url(add_query_arg('lmeg_member','webhook', home_url('/'))); ?></code></p>
        <p>Listen for: <code>checkout.session.completed</code>, <code>customer.subscription.updated</code>, <code>customer.subscription.deleted</code>. Paste the signing secret into Settings.</p>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Post meta box — access level per post
 * ------------------------------------------------------------------------- */

add_action('add_meta_boxes', 'lmeg_register_access_meta_box');
function lmeg_register_access_meta_box() {
    add_meta_box('lmeg_access', 'Email Gate — Access', 'lmeg_access_meta_box', 'post', 'side', 'high');
}

function lmeg_access_meta_box($post) {
    $current = get_post_meta($post->ID, '_lmeg_access', true) ?: '';
    $tiers   = lmeg_all_tiers();
    wp_nonce_field('lmeg_access_meta', 'lmeg_access_nonce');
    ?>
    <p style="margin:0 0 6px;font-size:12px;opacity:.75;">Who can read this post?</p>
    <label style="display:block;margin:4px 0;"><input type="radio" name="lmeg_access" value=""       <?php checked($current, ''); ?> /> Site default</label>
    <label style="display:block;margin:4px 0;"><input type="radio" name="lmeg_access" value="public" <?php checked($current, 'public'); ?> /> Public — anyone</label>
    <label style="display:block;margin:4px 0;"><input type="radio" name="lmeg_access" value="free"   <?php checked($current, 'free'); ?> /> Free members (email opt-in)</label>
    <label style="display:block;margin:4px 0;"><input type="radio" name="lmeg_access" value="paid"      <?php checked($current, 'paid'); ?> /> Any paid member</label>
    <label style="display:block;margin:4px 0;"><input type="radio" name="lmeg_access" value="soft-paid" <?php checked($current, 'soft-paid'); ?> /> Paid, or continue free (soft paywall)</label>
    <?php if ($tiers) : ?>
        <p style="margin:12px 0 4px;font-size:12px;opacity:.75;">Or a specific tier:</p>
        <?php foreach ($tiers as $t) : $v = 'tier:' . $t->id; ?>
            <label style="display:block;margin:4px 0;"><input type="radio" name="lmeg_access" value="<?php echo esc_attr($v); ?>" <?php checked($current, $v); ?> /> Only <?php echo esc_html($t->name); ?></label>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
}

add_action('save_post_post', 'lmeg_save_access_meta');
function lmeg_save_access_meta($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['lmeg_access_nonce']) || !wp_verify_nonce($_POST['lmeg_access_nonce'], 'lmeg_access_meta')) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $val = isset($_POST['lmeg_access']) ? sanitize_text_field($_POST['lmeg_access']) : '';
    if (!$val) {
        delete_post_meta($post_id, '_lmeg_access');
        return;
    }
    if (!in_array($val, ['public', 'free', 'paid', 'soft-paid'], true) && strpos($val, 'tier:') !== 0) return;
    update_post_meta($post_id, '_lmeg_access', $val);
}

/* ---------------------------------------------------------------------------
 * Members admin page — paid membership snapshot + roster
 * ------------------------------------------------------------------------- */

function lmeg_admin_members() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $subs  = $wpdb->prefix . LMEG_TABLE;
    $tiers = function_exists('lmeg_all_tiers') ? lmeg_all_tiers() : [];

    // Build a tier -> row lookup for the roster + MRR calc.
    $tier_by_id = [];
    foreach ($tiers as $t) $tier_by_id[(int) $t->id] = $t;

    // Active paying counts (per tier + billing interval).
    $rows = $wpdb->get_results(
        "SELECT id, email, member_tier_id, member_status, billing_interval, member_expires_at,
                stripe_subscription_id, member_status, created_at
         FROM $subs
         WHERE member_tier_id IS NOT NULL AND member_status IN ('active','past_due','cancelled')
         ORDER BY created_at DESC LIMIT 500"
    );

    // MRR: sum monthly-equivalent price per active member.
    // Manual comps (no stripe_subscription_id) still count toward "active"
    // counts but contribute $0 to MRR — that's a deliberate distinction.
    $mrr_cents        = 0;
    $arr_cents        = 0;
    $active_paying    = 0;
    $active_comp      = 0;
    $active_by_tier   = [];
    foreach ($rows as $r) {
        if ($r->member_status !== 'active') continue;
        $tier = $tier_by_id[(int) $r->member_tier_id] ?? null;
        if (!$tier) continue;
        $active_by_tier[(int) $tier->id] = ($active_by_tier[(int) $tier->id] ?? 0) + 1;
        if (!$r->stripe_subscription_id) {
            $active_comp++;
            continue;
        }
        $active_paying++;
        if ($r->billing_interval === 'year' && $tier->price_annual) {
            $mrr_cents += (int) round($tier->price_annual / 12);
            $arr_cents += (int) $tier->price_annual;
        } elseif ($tier->price_monthly) {
            $mrr_cents += (int) $tier->price_monthly;
            $arr_cents += (int) ($tier->price_monthly * 12);
        }
    }

    // 30-day new & churn.
    $new_30d = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM $subs
         WHERE member_status = 'active' AND stripe_subscription_id IS NOT NULL
           AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $churn_30d = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM $subs
         WHERE member_status = 'cancelled'
           AND member_expires_at IS NOT NULL
           AND member_expires_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );

    $currency = 'USD';
    foreach ($tiers as $t) { $currency = $t->currency; break; }
    ?>
    <div class="wrap">
        <h1>Email Gate — Members (Paid)</h1>

        <?php if (empty($tiers)) : ?>
            <div class="notice notice-info"><p>No tiers configured yet. <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-tiers')); ?>">Create your first tier →</a></p></div>
        <?php endif; ?>

        <div class="lmeg-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin:20px 0;">
            <div class="lmeg-stat">
                <div class="lmeg-stat__label">MRR</div>
                <div class="lmeg-stat__value"><?php echo esc_html(lmeg_format_price($mrr_cents, $currency)); ?></div>
                <div class="lmeg-stat__hint">est. from paying subs</div>
            </div>
            <div class="lmeg-stat">
                <div class="lmeg-stat__label">ARR</div>
                <div class="lmeg-stat__value"><?php echo esc_html(lmeg_format_price($arr_cents, $currency)); ?></div>
                <div class="lmeg-stat__hint">MRR × 12</div>
            </div>
            <div class="lmeg-stat">
                <div class="lmeg-stat__label">Paying members</div>
                <div class="lmeg-stat__value"><?php echo (int) $active_paying; ?></div>
                <div class="lmeg-stat__hint">+ <?php echo (int) $active_comp; ?> comped</div>
            </div>
            <div class="lmeg-stat">
                <div class="lmeg-stat__label">New (30d)</div>
                <div class="lmeg-stat__value" style="color:#1a6f1a;">+<?php echo $new_30d; ?></div>
                <div class="lmeg-stat__hint">Stripe-created subs</div>
            </div>
            <div class="lmeg-stat">
                <div class="lmeg-stat__label">Churn (30d)</div>
                <div class="lmeg-stat__value" style="color:#a00;">-<?php echo $churn_30d; ?></div>
                <div class="lmeg-stat__hint">cancellations expiring</div>
            </div>
        </div>

        <?php if ($tiers) : ?>
            <h2>By tier</h2>
            <table class="widefat striped" style="max-width:640px;">
                <thead><tr><th>Tier</th><th>Members</th><th>Monthly</th><th>Annual</th></tr></thead>
                <tbody>
                    <?php foreach ($tiers as $t) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($t->name); ?></strong></td>
                            <td><?php echo (int) ($active_by_tier[(int) $t->id] ?? 0); ?></td>
                            <td><?php echo $t->price_monthly ? esc_html(lmeg_format_price($t->price_monthly, $t->currency)) : '—'; ?></td>
                            <td><?php echo $t->price_annual  ? esc_html(lmeg_format_price($t->price_annual,  $t->currency)) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2>Roster</h2>
        <p><a href="<?php echo esc_url(add_query_arg('status', 'paid', admin_url('admin.php?page=lmeg'))); ?>" class="button">Open in Subscribers →</a></p>
        <table class="widefat striped">
            <thead><tr><th>Email</th><th>Tier</th><th>Status</th><th>Billing</th><th>Source</th><th>Access ends</th></tr></thead>
            <tbody>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="6">No paying members yet. When someone completes Stripe Checkout they'll appear here.</td></tr>
            <?php else : foreach ($rows as $r) :
                $tier   = $tier_by_id[(int) $r->member_tier_id] ?? null;
                $source = $r->stripe_subscription_id ? '💳 Stripe' : '<em>Comped</em>';
                $status_style = $r->member_status === 'active' ? '#1a6f1a' : ($r->member_status === 'cancelled' ? '#a05a00' : '#a00');
            ?>
                <tr>
                    <td><?php echo esc_html($r->email); ?></td>
                    <td><?php echo $tier ? esc_html($tier->name) : '—'; ?></td>
                    <td><span style="color:<?php echo $status_style; ?>;">● <?php echo esc_html($r->member_status); ?></span></td>
                    <td><?php echo esc_html($r->billing_interval ?: '—'); ?></td>
                    <td><?php echo $source; ?></td>
                    <td><?php echo esc_html($r->member_expires_at ?: '—'); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Shop Revenue — email-attributed Shopify revenue
 * ------------------------------------------------------------------------- */

function lmeg_admin_shop() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $notice = '';

    if (isset($_POST['lmeg_shop_nonce']) && wp_verify_nonce($_POST['lmeg_shop_nonce'], 'lmeg_shop')
        && ($_POST['lmeg_action'] ?? '') === 'sync_now') {
        $r = lmeg_shop_sync(true);
        $notice = is_wp_error($r)
            ? '<div class="notice notice-error"><p>Sync failed: ' . esc_html($r->get_error_message()) . '</p></div>'
            : '<div class="notice notice-success"><p>Synced. Fetched ' . (int) ($r['fetched'] ?? 0) . ' recent orders; ' . (int) ($r['attributed'] ?? 0) . ' attributed to a broadcast this pass.</p></div>';
    }

    $configured = lmeg_shop_configured();
    $last_sync  = get_option(LMEG_SHOP_LAST_SYNC, '');
    ?>
    <div class="wrap">
        <h1>Email Gate — Shop Revenue</h1>
        <?php echo $notice; ?>

        <?php if (!$configured) : ?>
            <div class="notice notice-info"><p>Shopify isn't connected yet. Add your store domain + Admin API token under
                <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings')); ?>">Settings → Shop (Shopify)</a>.</p></div>
        </div>
        <?php return; endif; ?>

        <form method="post" style="margin:12px 0;">
            <?php wp_nonce_field('lmeg_shop', 'lmeg_shop_nonce'); ?>
            <input type="hidden" name="lmeg_action" value="sync_now" />
            <button type="submit" class="button button-primary">Sync orders now</button>
            <span style="margin-left:10px;opacity:.65;">Last sync: <?php echo $last_sync ? esc_html($last_sync) : 'never'; ?> · auto-syncs every ~15 min</span>
        </form>

        <?php
        $t30 = lmeg_shop_totals(30);
        $t365 = lmeg_shop_totals(365);
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin:20px 0;max-width:900px;">
            <div class="lmeg-stat">
                <div class="lmeg-stat__label">Campaign revenue (30d)</div>
                <div class="lmeg-stat__value"><?php echo esc_html(lmeg_format_price((int) ($t30->campaign_cents ?? 0))); ?></div>
                <div class="lmeg-stat__hint"><?php echo (int) ($t30->campaign_orders ?? 0); ?> orders from broadcast clicks</div>
            </div>
            <div class="lmeg-stat">
                <div class="lmeg-stat__label">Subscriber revenue (30d)</div>
                <div class="lmeg-stat__value"><?php echo esc_html(lmeg_format_price((int) ($t30->list_cents ?? 0))); ?></div>
                <div class="lmeg-stat__hint"><?php echo (int) ($t30->list_orders ?? 0); ?> orders from anyone on your list</div>
            </div>
            <div class="lmeg-stat">
                <div class="lmeg-stat__label">All shop revenue (30d)</div>
                <div class="lmeg-stat__value"><?php echo esc_html(lmeg_format_price((int) ($t30->all_cents ?? 0))); ?></div>
                <div class="lmeg-stat__hint"><?php echo (int) ($t30->all_orders ?? 0); ?> orders synced</div>
            </div>
            <div class="lmeg-stat">
                <div class="lmeg-stat__label">Campaign revenue (1y)</div>
                <div class="lmeg-stat__value"><?php echo esc_html(lmeg_format_price((int) ($t365->campaign_cents ?? 0))); ?></div>
                <div class="lmeg-stat__hint"><?php echo (int) ($t365->campaign_orders ?? 0); ?> orders</div>
            </div>
        </div>

        <h2>Revenue by broadcast</h2>
        <?php
        $bcast_tbl = $wpdb->prefix . 'lmeg_broadcasts';
        $per = $wpdb->get_results(
            "SELECT o.broadcast_id, SUM(o.total_cents) AS cents, COUNT(*) AS orders,
                    b.subject, b.created_at AS sent_at
             FROM {$wpdb->prefix}lmeg_shop_orders o
             LEFT JOIN $bcast_tbl b ON b.id = o.broadcast_id
             WHERE o.broadcast_id IS NOT NULL
             GROUP BY o.broadcast_id ORDER BY cents DESC LIMIT 50"
        );
        ?>
        <table class="widefat striped" style="max-width:900px;">
            <thead><tr><th>Broadcast</th><th>Sent</th><th>Orders</th><th>Revenue</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($per)) : ?>
                <tr><td colspan="5">No attributed orders yet. Revenue appears here after a subscriber clicks a broadcast link and buys within the attribution window.</td></tr>
            <?php else : foreach ($per as $p) : ?>
                <tr>
                    <td><strong><?php echo esc_html($p->subject ?: ('#' . (int) $p->broadcast_id)); ?></strong></td>
                    <td><?php echo esc_html($p->sent_at ?: '—'); ?></td>
                    <td><?php echo (int) $p->orders; ?></td>
                    <td><strong><?php echo esc_html(lmeg_format_price((int) $p->cents)); ?></strong></td>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-broadcasts&view=' . (int) $p->broadcast_id)); ?>">View broadcast</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h2>Recent attributed orders</h2>
        <?php
        $recent = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}lmeg_shop_orders
             WHERE subscriber_id IS NOT NULL
             ORDER BY ordered_at DESC LIMIT 50"
        );
        ?>
        <table class="widefat striped" style="max-width:900px;">
            <thead><tr><th>Order</th><th>Email</th><th>Total</th><th>Attribution</th><th>Broadcast</th><th>Ordered</th></tr></thead>
            <tbody>
            <?php if (empty($recent)) : ?>
                <tr><td colspan="6">No orders from subscribers yet.</td></tr>
            <?php else : foreach ($recent as $o) :
                $att_label = [
                    'click'      => '🖱 clicked broadcast',
                    'open'       => '✉️ opened broadcast',
                    'subscriber' => 'on the list',
                    'none'       => '—',
                ][$o->attribution] ?? $o->attribution;
            ?>
                <tr>
                    <td>#<?php echo esc_html($o->order_number); ?></td>
                    <td><?php echo esc_html($o->email); ?></td>
                    <td><?php echo esc_html(lmeg_format_price((int) $o->total_cents, $o->currency)); ?></td>
                    <td><?php echo esc_html($att_label); ?></td>
                    <td><?php echo $o->broadcast_id
                        ? '<a href="' . esc_url(admin_url('admin.php?page=lmeg-broadcasts&view=' . (int) $o->broadcast_id)) . '">#' . (int) $o->broadcast_id . '</a>'
                        : '—'; ?></td>
                    <td><?php echo esc_html($o->ordered_at ?: '—'); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <p style="margin-top:16px;opacity:.7;max-width:760px;"><em>How attribution works: an order is matched to a subscriber by email, then to the most recent broadcast that subscriber clicked (falling back to opened) within the attribution window before purchase — last click wins. Configure the window under Settings → Shop (Shopify).</em></p>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Fan profile — one fan, full context + activity timeline
 * ------------------------------------------------------------------------- */

function lmeg_admin_fan_profile($fan_id) {
    global $wpdb;

    // Save bio fields (first name + private notes).
    if (isset($_POST['lmeg_fanbio_nonce']) && wp_verify_nonce($_POST['lmeg_fanbio_nonce'], 'lmeg_fanbio_' . $fan_id)) {
        $wpdb->update($wpdb->prefix . LMEG_TABLE, [
            'first_name' => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')) ?: null,
            'notes'      => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')) ?: null,
        ], ['id' => (int) $fan_id]);
        echo '<div class="notice notice-success is-dismissible"><p>Fan bio saved.</p></div>';
    }

    $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", $fan_id));
    if (!$sub) {
        echo '<div class="wrap"><h1>Fan not found.</h1></div>';
        return;
    }

    $tags     = lmeg_tags_for_subscriber($fan_id);
    $revenue  = function_exists('lmeg_fan_revenue') ? lmeg_fan_revenue($fan_id) : 0;
    $timeline = function_exists('lmeg_fan_timeline') ? lmeg_fan_timeline($fan_id) : [];
    $tier     = ($sub->member_tier_id && function_exists('lmeg_tier')) ? lmeg_tier($sub->member_tier_id) : null;
    $code     = function_exists('lmeg_get_fan_code') ? lmeg_get_fan_code($fan_id) : '';
    $referred = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE referred_by = %d", $fan_id
    ));
    $referrer = $sub->referred_by
        ? $wpdb->get_row($wpdb->prepare("SELECT id, email, phone FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", $sub->referred_by))
        : null;
    ?>
    <div class="wrap">
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg')); ?>">← All subscribers</a></p>
        <h1><?php echo esc_html($sub->email ?: $sub->phone ?: ('Fan #' . $fan_id)); ?></h1>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin:18px 0;max-width:920px;">
            <div class="lmeg-stat"><div class="lmeg-stat__label">Status</div>
                <div class="lmeg-stat__value" style="font-size:18px;"><?php echo $sub->unsubscribed_at ? '👋 Unsubscribed' : '● Active'; ?></div>
                <div class="lmeg-stat__hint">joined <?php echo esc_html($sub->created_at); ?></div></div>
            <div class="lmeg-stat"><div class="lmeg-stat__label">Plan</div>
                <div class="lmeg-stat__value" style="font-size:18px;"><?php echo $tier ? esc_html($tier->name) : 'Free'; ?></div>
                <div class="lmeg-stat__hint"><?php echo esc_html($sub->member_status); ?></div></div>
            <div class="lmeg-stat"><div class="lmeg-stat__label">Lifetime revenue</div>
                <div class="lmeg-stat__value" style="font-size:18px;"><?php echo esc_html(lmeg_format_price($revenue)); ?></div>
                <div class="lmeg-stat__hint">from synced shop orders</div></div>
            <div class="lmeg-stat"><div class="lmeg-stat__label">Referrals</div>
                <div class="lmeg-stat__value" style="font-size:18px;"><?php echo $referred; ?></div>
                <div class="lmeg-stat__hint">fans they brought in</div></div>
            <div class="lmeg-stat"><div class="lmeg-stat__label">Unique code</div>
                <div class="lmeg-stat__value" style="font-size:18px;"><code><?php echo esc_html($code ?: '—'); ?></code></div>
                <div class="lmeg-stat__hint">presale / referral code</div></div>
        </div>

        <p>
            <?php foreach ((array) $tags as $t) echo lmeg_render_tag_chip($t) . ' '; ?>
        </p>

        <table class="widefat" style="max-width:560px;margin:10px 0 22px;">
            <tbody>
                <?php if ($sub->email) : ?><tr><th style="width:160px;">Email</th><td><?php echo esc_html($sub->email); ?></td></tr><?php endif; ?>
                <?php if ($sub->phone) : ?><tr><th>Phone</th><td><?php echo esc_html($sub->phone); ?></td></tr><?php endif; ?>
                <?php if ($sub->country) : ?><tr><th>Country</th><td><?php echo esc_html(lmeg_flag_emoji($sub->country) . ' ' . $sub->country); ?></td></tr><?php endif; ?>
                <?php $addr = array_filter([$sub->street, $sub->city, $sub->region, $sub->postal_code]); ?>
                <?php if ($addr) : ?><tr><th>Address</th><td><?php echo esc_html(implode(', ', $addr)); ?></td></tr><?php endif; ?>
                <?php if ($referrer) : ?><tr><th>Referred by</th><td><a href="<?php echo esc_url(add_query_arg(['page' => 'lmeg', 'fan' => (int) $referrer->id], admin_url('admin.php'))); ?>"><?php echo esc_html($referrer->email ?: $referrer->phone); ?></a></td></tr><?php endif; ?>
                <?php if ($sub->post_id) : ?><tr><th>Signed up on</th><td><a href="<?php echo esc_url(get_permalink($sub->post_id)); ?>" target="_blank" rel="noopener"><?php echo esc_html(get_the_title($sub->post_id)); ?></a></td></tr><?php endif; ?>
            </tbody>
        </table>

        <h2>Bio</h2>
        <form method="post" style="max-width:560px;margin-bottom:22px;">
            <?php wp_nonce_field('lmeg_fanbio_' . $fan_id, 'lmeg_fanbio_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr><th><label for="first_name">First name</label></th>
                    <td><input type="text" name="first_name" id="first_name" class="regular-text" value="<?php echo esc_attr($sub->first_name ?? ''); ?>" placeholder="Used by the {name} merge tag" /></td></tr>
                <tr><th><label for="notes">Private notes</label></th>
                    <td><textarea name="notes" id="notes" rows="4" class="large-text" placeholder="Superfan from Toronto, front row at the June show…"><?php echo esc_textarea($sub->notes ?? ''); ?></textarea>
                        <p class="description">Only visible here in the admin.</p></td></tr>
            </table>
            <p><button type="submit" class="button button-primary">Save bio</button></p>
        </form>

        <h2>Timeline</h2>
        <?php if (empty($timeline)) : ?>
            <p>No activity yet.</p>
        <?php else : ?>
            <div style="max-width:760px;">
                <?php foreach ($timeline as $item) : ?>
                    <div style="display:flex;gap:12px;padding:9px 0;border-bottom:1px solid rgba(0,0,0,.06);align-items:baseline;">
                        <span style="flex:0 0 auto;"><?php echo $item['icon']; ?></span>
                        <span style="flex:1;"><?php echo esc_html($item['label']); ?></span>
                        <span style="flex:0 0 auto;font-size:12px;opacity:.55;white-space:nowrap;"><?php echo esc_html($item['at']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Audience — fan types, countries, top fans, referral leaderboard
 * ------------------------------------------------------------------------- */

function lmeg_admin_audience() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $subs = $wpdb->prefix . LMEG_TABLE;
    $notice = '';

    if (isset($_POST['lmeg_aud_nonce']) && wp_verify_nonce($_POST['lmeg_aud_nonce'], 'lmeg_aud')
        && ($_POST['lmeg_action'] ?? '') === 'recalc') {
        $counts = lmeg_recalculate_fan_types();
        $notice = '<div class="notice notice-success"><p>Fan types recalculated: '
            . (int) $counts['superfan'] . ' superfans, '
            . (int) $counts['engaged'] . ' engaged, '
            . (int) $counts['casual'] . ' casual, '
            . (int) $counts['dormant'] . ' dormant.</p></div>';
    }

    $last_run = get_option('lmeg_fan_types_last_run', '');

    // Fan type distribution from tags.
    $types = $wpdb->get_results(
        "SELECT t.slug, t.name, t.color, COUNT(st.subscriber_id) AS n
         FROM {$wpdb->prefix}lmeg_tags t
         JOIN {$wpdb->prefix}lmeg_subscriber_tags st ON st.tag_id = t.id
         WHERE t.slug LIKE 'fan-type:%'
         GROUP BY t.id ORDER BY n DESC"
    );
    $type_total = array_sum(array_map(function ($r) { return (int) $r->n; }, (array) $types)) ?: 1;

    // Country breakdown.
    $countries = $wpdb->get_results(
        "SELECT country, COUNT(*) AS n FROM $subs
         WHERE country IS NOT NULL AND country <> '' AND unsubscribed_at IS NULL
         GROUP BY country ORDER BY n DESC LIMIT 30"
    );
    $known   = array_sum(array_map(function ($r) { return (int) $r->n; }, (array) $countries));
    $unknown = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE (country IS NULL OR country = '') AND unsubscribed_at IS NULL");
    $cmax    = $countries ? max(array_map(function ($r) { return (int) $r->n; }, $countries)) : 1;

    // Top fans by lifetime revenue.
    $top = $wpdb->get_results(
        "SELECT s.id, s.email, s.phone, SUM(o.total_cents) AS cents, COUNT(o.shopify_order_id) AS orders
         FROM {$wpdb->prefix}lmeg_shop_orders o
         JOIN $subs s ON s.id = o.subscriber_id
         GROUP BY s.id ORDER BY cents DESC LIMIT 15"
    );

    // Referral leaderboard.
    $refs = $wpdb->get_results(
        "SELECT r.id, r.email, r.phone, COUNT(s.id) AS n
         FROM $subs s JOIN $subs r ON r.id = s.referred_by
         GROUP BY r.id ORDER BY n DESC LIMIT 15"
    );
    ?>
    <div class="wrap">
        <h1>Email Gate — Audience</h1>
        <?php echo $notice; ?>

        <form method="post" style="margin:12px 0;">
            <?php wp_nonce_field('lmeg_aud', 'lmeg_aud_nonce'); ?>
            <input type="hidden" name="lmeg_action" value="recalc" />
            <button type="submit" class="button button-primary">Recalculate fan types now</button>
            <span style="margin-left:10px;opacity:.65;">Last run: <?php echo $last_run ? esc_html($last_run) : 'never'; ?> · auto-runs daily</span>
        </form>

        <h2>Fan types</h2>
        <?php if (empty($types)) : ?>
            <p>No fan types yet — hit "Recalculate fan types now".</p>
        <?php else : ?>
            <div style="max-width:640px;">
                <?php foreach ($types as $t) : $pct = round(100 * (int) $t->n / $type_total); ?>
                    <div style="display:flex;align-items:center;gap:10px;margin:6px 0;">
                        <span style="flex:0 0 140px;"><?php echo esc_html(str_replace('Fan type: ', '', $t->name)); ?></span>
                        <div style="flex:1;background:rgba(0,0,0,.05);border-radius:6px;height:22px;overflow:hidden;">
                            <div style="width:<?php echo $pct; ?>%;min-width:2px;height:100%;background:<?php echo esc_attr($t->color); ?>;"></div>
                        </div>
                        <span style="flex:0 0 90px;text-align:right;"><strong><?php echo (int) $t->n; ?></strong> (<?php echo $pct; ?>%)</span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="description">Superfan = purchased or paying member (90d) · Engaged = 2+ clicks or 5+ opens · Casual = any open/click · Dormant = silent. Each fan gets a <code>fan-type:*</code> tag — use them in broadcasts and segments.</p>
        <?php endif; ?>

        <h2 style="margin-top:28px;">Where your fans are</h2>
        <?php if (empty($countries)) : ?>
            <p>No country data yet — it's captured from phone signups and address blocks.</p>
        <?php else : ?>
            <div style="max-width:640px;">
                <?php foreach ($countries as $c) : $pct = round(100 * (int) $c->n / max(1, $known)); ?>
                    <div style="display:flex;align-items:center;gap:10px;margin:5px 0;">
                        <span style="flex:0 0 140px;"><?php echo esc_html(lmeg_flag_emoji($c->country) . ' ' . $c->country); ?></span>
                        <div style="flex:1;background:rgba(0,0,0,.05);border-radius:6px;height:18px;overflow:hidden;">
                            <div style="width:<?php echo round(100 * (int) $c->n / $cmax); ?>%;min-width:2px;height:100%;background:#3b82f6;"></div>
                        </div>
                        <span style="flex:0 0 90px;text-align:right;"><strong><?php echo (int) $c->n; ?></strong> (<?php echo $pct; ?>%)</span>
                    </div>
                <?php endforeach; ?>
                <?php if ($unknown) : ?>
                    <p class="description"><?php echo (int) $unknown; ?> subscribers have no country on file (email-only signups don't capture location).</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <h2 style="margin-top:28px;">Top fans by revenue</h2>
        <table class="widefat striped" style="max-width:640px;">
            <thead><tr><th>Fan</th><th>Orders</th><th>Lifetime revenue</th></tr></thead>
            <tbody>
            <?php if (empty($top)) : ?>
                <tr><td colspan="3">No attributed shop orders yet.</td></tr>
            <?php else : foreach ($top as $f) : ?>
                <tr>
                    <td><a href="<?php echo esc_url(add_query_arg(['page' => 'lmeg', 'fan' => (int) $f->id], admin_url('admin.php'))); ?>"><?php echo esc_html($f->email ?: $f->phone); ?></a></td>
                    <td><?php echo (int) $f->orders; ?></td>
                    <td><strong><?php echo esc_html(lmeg_format_price((int) $f->cents)); ?></strong></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h2 style="margin-top:28px;">Referral leaderboard</h2>
        <table class="widefat striped" style="max-width:640px;">
            <thead><tr><th>Fan</th><th>Fans referred</th></tr></thead>
            <tbody>
            <?php if (empty($refs)) : ?>
                <tr><td colspan="2">No referrals yet. Every fan has a personal link — drop <code>{referral_link}</code> into a broadcast to start the loop ("share this with a friend").</td></tr>
            <?php else : foreach ($refs as $f) : ?>
                <tr>
                    <td><a href="<?php echo esc_url(add_query_arg(['page' => 'lmeg', 'fan' => (int) $f->id], admin_url('admin.php'))); ?>"><?php echo esc_html($f->email ?: $f->phone); ?></a></td>
                    <td><strong><?php echo (int) $f->n; ?></strong></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Smartlinks — trackable short links + QR codes
 * ------------------------------------------------------------------------- */

function lmeg_admin_smartlinks() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_smartlinks';
    $notice = '';

    if (isset($_POST['lmeg_sl_nonce']) && wp_verify_nonce($_POST['lmeg_sl_nonce'], 'lmeg_sl')) {
        $act = sanitize_text_field($_POST['lmeg_action'] ?? '');
        if ($act === 'create') {
            $slug   = sanitize_title(wp_unslash($_POST['slug'] ?? ''));
            $target = esc_url_raw(wp_unslash($_POST['target_url'] ?? ''));
            if ($slug && $target) {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $tbl WHERE slug = %s", $slug));
                if ($exists) {
                    $notice = '<div class="notice notice-error"><p>That slug is taken.</p></div>';
                } else {
                    $wpdb->insert($tbl, [
                        'slug'       => $slug,
                        'target_url' => $target,
                        'created_at' => current_time('mysql'),
                    ]);
                    $notice = '<div class="notice notice-success"><p>Smartlink created: <code>' . esc_html(lmeg_smartlink_url($slug)) . '</code></p></div>';
                }
            }
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['link_id'] ?? 0);
            if ($id) {
                $wpdb->delete($tbl, ['id' => $id]);
                $notice = '<div class="notice notice-success"><p>Smartlink deleted.</p></div>';
            }
        }
    }

    $rows = $wpdb->get_results("SELECT * FROM $tbl ORDER BY created_at DESC LIMIT 200");
    ?>
    <div class="wrap">
        <h1>Email Gate — Smartlinks</h1>
        <?php echo $notice; ?>
        <p>Short, trackable links: <code><?php echo esc_html(home_url('/go/')); ?>&lt;slug&gt;</code>. Use them in bios, posters, broadcasts — clicks are counted, and clicks by known fans land on their timeline.</p>

        <h2>Create a smartlink</h2>
        <form method="post" style="margin-bottom:24px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <?php wp_nonce_field('lmeg_sl', 'lmeg_sl_nonce'); ?>
            <input type="hidden" name="lmeg_action" value="create" />
            <code><?php echo esc_html(home_url('/go/')); ?></code>
            <input type="text" name="slug" placeholder="new-single" class="regular-text" style="max-width:180px;" required />
            <span>→</span>
            <input type="url" name="target_url" placeholder="https://open.spotify.com/track/…" class="regular-text" required />
            <button type="submit" class="button button-primary">Create</button>
        </form>

        <table class="widefat striped">
            <thead><tr><th>Short link</th><th>Target</th><th>Clicks</th><th>Last click</th><th>QR</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="6">No smartlinks yet.</td></tr>
            <?php else : foreach ($rows as $l) :
                $short = lmeg_smartlink_url($l->slug);
                $qr    = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . rawurlencode($short);
            ?>
                <tr>
                    <td><a href="<?php echo esc_url($short); ?>" target="_blank" rel="noopener"><code>/go/<?php echo esc_html($l->slug); ?></code></a></td>
                    <td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><a href="<?php echo esc_url($l->target_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($l->target_url); ?></a></td>
                    <td><strong><?php echo (int) $l->clicks; ?></strong></td>
                    <td><?php echo esc_html($l->last_clicked_at ?: '—'); ?></td>
                    <td><a href="<?php echo esc_url($qr); ?>" target="_blank" rel="noopener">View QR</a></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Delete this smartlink? The short URL will stop working.');" style="display:inline;">
                            <?php wp_nonce_field('lmeg_sl', 'lmeg_sl_nonce'); ?>
                            <input type="hidden" name="lmeg_action" value="delete" />
                            <input type="hidden" name="link_id" value="<?php echo (int) $l->id; ?>" />
                            <button type="submit" class="button button-link-delete">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <p class="description" style="margin-top:10px;">QR codes are rendered by api.qrserver.com from the short URL — download the PNG from the QR view for posters/merch.</p>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * Overview — the command-center landing page
 * ------------------------------------------------------------------------- */

function lmeg_admin_overview() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $subs = $wpdb->prefix . LMEG_TABLE;

    // --- headline numbers ---
    $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE unsubscribed_at IS NULL");
    $new30  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $unsub30= (int) $wpdb->get_var("SELECT COUNT(*) FROM $subs WHERE unsubscribed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");

    // MRR + paying
    $mrr = 0; $paying = 0; $cur = 'USD';
    if (function_exists('lmeg_all_tiers')) {
        $by = []; foreach (lmeg_all_tiers() as $t) { $by[(int)$t->id] = $t; $cur = $t->currency; }
        foreach ($wpdb->get_results("SELECT member_tier_id, billing_interval FROM $subs WHERE member_status='active' AND member_tier_id IS NOT NULL AND stripe_subscription_id IS NOT NULL") as $p) {
            $paying++; $t = $by[(int)$p->member_tier_id] ?? null; if (!$t) continue;
            if ($p->billing_interval === 'year' && $t->price_annual) $mrr += (int) round($t->price_annual/12);
            elseif ($t->price_monthly) $mrr += (int) $t->price_monthly;
        }
    }

    // Campaign revenue 30d
    $camp = 0; $camp_orders = 0;
    if (function_exists('lmeg_shop_totals')) {
        $t30 = lmeg_shop_totals(30);
        if ($t30) { $camp = (int) $t30->campaign_cents; $camp_orders = (int) $t30->campaign_orders; }
    }

    // Spotify
    $sp = null; $sp_delta = null;
    if (function_exists('lmeg_spotify_configured') && lmeg_spotify_configured()) {
        $ov = lmeg_spotify_overview();
        if (!is_wp_error($ov)) {
            $sp = $ov;
            $snaps = function_exists('lmeg_spotify_snapshots') ? lmeg_spotify_snapshots(30) : [];
            if (count($snaps) >= 2) $sp_delta = (int) end($snaps)->followers - (int) reset($snaps)->followers;
        }
    }

    // signup sparkline 30d
    $daily = $wpdb->get_results("SELECT DATE(created_at) d, COUNT(*) n FROM $subs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 29 DAY) GROUP BY DATE(created_at)");
    $byday = []; for ($i=29;$i>=0;$i--) $byday[date('Y-m-d', strtotime("-$i days"))] = 0;
    foreach ($daily as $d) $byday[$d->d] = (int) $d->n;
    $counts = array_values($byday); $max = max($counts) ?: 1;
    $w=280;$h=54;$step=$w/max(1,count($counts)-1);$pts=[];
    foreach ($counts as $i=>$n){ $pts[] = round($i*$step,1).','.round($h-($n/$max)*($h-4)-2,1); }
    $spark = implode(' ', $pts);

    // fan types
    $types = $wpdb->get_results("SELECT t.name,t.color,COUNT(st.subscriber_id) n FROM {$wpdb->prefix}lmeg_tags t JOIN {$wpdb->prefix}lmeg_subscriber_tags st ON st.tag_id=t.id WHERE t.slug LIKE 'fan-type:%' GROUP BY t.id ORDER BY n DESC");
    $tt_total = array_sum(array_map(function($r){return (int)$r->n;}, (array)$types)) ?: 1;

    // countries
    $countries = $wpdb->get_results("SELECT country,COUNT(*) n FROM $subs WHERE country<>'' AND country IS NOT NULL AND unsubscribed_at IS NULL GROUP BY country ORDER BY n DESC LIMIT 5");
    $c_max = $countries ? max(array_map(function($r){return (int)$r->n;}, $countries)) : 1;

    // last broadcast
    $last = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}lmeg_broadcasts WHERE status='completed' ORDER BY id DESC LIMIT 1");
    $l_open=$l_click=$l_rev=0;
    if ($last) {
        $ev = $wpdb->prefix.'lmeg_broadcast_events';
        $l_open = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT subscriber_id) FROM $ev WHERE broadcast_id=%d AND event_type='open'",$last->id));
        $l_click= (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT subscriber_id) FROM $ev WHERE broadcast_id=%d AND event_type='click'",$last->id));
        if (function_exists('lmeg_shop_revenue_by_broadcast')) { $rm=lmeg_shop_revenue_by_broadcast(); $l_rev = isset($rm[(int)$last->id])?(int)$rm[(int)$last->id]['cents']:0; }
    }
    $fmt = function($c) use ($cur){ return function_exists('lmeg_format_price') ? lmeg_format_price($c,$cur) : ('$'.number_format($c/100,2)); };
    ?>
    <div class="wrap">
        <h1>Overview</h1>

        <!-- headline KPIs -->
        <div class="lmeg-ov-kpis">
            <div class="lmeg-stat">
                <div class="lmeg-stat__label">Active subscribers</div>
                <div class="lmeg-stat__value"><?php echo number_format_i18n($active); ?></div>
                <div class="lmeg-stat__hint"><span style="color:#34D399;">+<?php echo $new30; ?></span> · <?php echo $unsub30; ?> lost (30d)</div>
                <svg viewBox="0 0 <?php echo $w; ?> <?php echo $h; ?>" width="100%" height="<?php echo $h; ?>" style="margin-top:8px;"><polyline fill="none" stroke="#D05FA2" stroke-width="1.6" points="<?php echo esc_attr($spark); ?>"/></svg>
            </div>
            <div class="lmeg-stat">
                <div class="lmeg-stat__label">MRR</div>
                <div class="lmeg-stat__value"><?php echo esc_html($fmt($mrr)); ?></div>
                <div class="lmeg-stat__hint"><?php echo $paying; ?> paying members</div>
            </div>
            <div class="lmeg-stat">
                <div class="lmeg-stat__label">Campaign revenue (30d)</div>
                <div class="lmeg-stat__value"><?php echo esc_html($fmt($camp)); ?></div>
                <div class="lmeg-stat__hint"><?php echo $camp_orders; ?> orders from broadcasts</div>
            </div>
            <div class="lmeg-stat">
                <div class="lmeg-stat__label">Spotify followers</div>
                <?php if ($sp) : ?>
                    <div class="lmeg-stat__value"><?php echo number_format_i18n($sp['followers']); ?></div>
                    <div class="lmeg-stat__hint"><?php echo $sp_delta!==null ? '<span style="color:'.($sp_delta>=0?'#34D399':'#F87171').';">'.($sp_delta>=0?'+':'').number_format_i18n($sp_delta).'</span> (30d) · ' : ''; ?>pop <?php echo (int)$sp['popularity']; ?></div>
                <?php else : ?>
                    <div class="lmeg-stat__value" style="font-size:15px;font-weight:500;opacity:.6;">Not connected</div>
                    <div class="lmeg-stat__hint"><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings')); ?>">Connect Spotify →</a></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- panels -->
        <div class="lmeg-ov-grid">
            <!-- fan types -->
            <div class="lmeg-ov-panel">
                <div class="lmeg-ov-panel__head">Fan types <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-audience')); ?>">Audience →</a></div>
                <?php if (empty($types)) : ?>
                    <p class="lmeg-ov-empty">Run "Recalculate" on the Audience page to score fans.</p>
                <?php else : foreach ($types as $t) : $pct = round(100*(int)$t->n/$tt_total); ?>
                    <div class="lmeg-ov-bar">
                        <span class="lmeg-ov-bar__label"><?php echo esc_html(str_replace('Fan type: ','',$t->name)); ?></span>
                        <div class="lmeg-ov-bar__track"><div style="width:<?php echo $pct; ?>%;background:<?php echo esc_attr($t->color); ?>;"></div></div>
                        <span class="lmeg-ov-bar__val"><?php echo (int)$t->n; ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- countries -->
            <div class="lmeg-ov-panel">
                <div class="lmeg-ov-panel__head">Where fans are <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-audience')); ?>">Map →</a></div>
                <?php if (empty($countries)) : ?>
                    <p class="lmeg-ov-empty">Country fills in from phone/address signups and IP geolocation.</p>
                <?php else : foreach ($countries as $c) : $pct=round(100*(int)$c->n/$c_max); ?>
                    <div class="lmeg-ov-bar">
                        <span class="lmeg-ov-bar__label"><?php echo esc_html(lmeg_flag_emoji($c->country).' '.$c->country); ?></span>
                        <div class="lmeg-ov-bar__track"><div style="width:<?php echo $pct; ?>%;background:#3b82f6;"></div></div>
                        <span class="lmeg-ov-bar__val"><?php echo (int)$c->n; ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- last broadcast -->
            <div class="lmeg-ov-panel">
                <div class="lmeg-ov-panel__head">Last broadcast <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-broadcasts')); ?>">History →</a></div>
                <?php if (!$last) : ?>
                    <p class="lmeg-ov-empty">No broadcasts yet. <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-compose')); ?>">Compose one →</a></p>
                <?php else :
                    $sent = max(1,(int)$last->sent); ?>
                    <div style="font-weight:600;margin-bottom:2px;"><?php echo esc_html($last->subject ?: mb_substr((string)($last->body ?? $last->body_sms),0,44)); ?></div>
                    <div style="font-size:12px;opacity:.6;margin-bottom:10px;"><?php echo esc_html($last->created_at); ?></div>
                    <div class="lmeg-ov-metrics">
                        <div><strong><?php echo (int)$last->sent; ?></strong><span>sent</span></div>
                        <div><strong><?php echo round(100*$l_open/$sent); ?>%</strong><span>opens</span></div>
                        <div><strong><?php echo round(100*$l_click/$sent); ?>%</strong><span>clicks</span></div>
                        <div><strong><?php echo esc_html($fmt($l_rev)); ?></strong><span>revenue</span></div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- spotify -->
            <div class="lmeg-ov-panel">
                <div class="lmeg-ov-panel__head">Spotify <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-spotify')); ?>">Details →</a></div>
                <?php if (!$sp) : ?>
                    <p class="lmeg-ov-empty">Not connected. <a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-settings')); ?>">Add keys →</a></p>
                <?php else : ?>
                    <div class="lmeg-ov-metrics">
                        <div><strong><?php echo number_format_i18n($sp['followers']); ?></strong><span>followers</span></div>
                        <div><strong><?php echo (int)$sp['popularity']; ?></strong><span>popularity</span></div>
                        <?php if ($sp_delta!==null) : ?><div><strong style="color:<?php echo $sp_delta>=0?'#34D399':'#F87171'; ?>;"><?php echo ($sp_delta>=0?'+':'').number_format_i18n($sp_delta); ?></strong><span>30d</span></div><?php endif; ?>
                    </div>
                    <?php if (!empty($sp['top_tracks'][0])) : ?><div style="margin-top:10px;font-size:12px;opacity:.7;">Top: <?php echo esc_html($sp['top_tracks'][0]['name']); ?></div><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- quick actions -->
        <div class="lmeg-ov-actions">
            <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=lmeg-compose')); ?>">Compose broadcast</a>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lmeg-ai')); ?>">Ask AI about your fans</a>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lmeg')); ?>">Subscribers</a>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lmeg-shop')); ?>">Revenue</a>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lmeg-spotify')); ?>">Spotify</a>
        </div>
    </div>

    <style>
    .lmeg-admin .lmeg-ov-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px;margin:18px 0;}
    .lmeg-admin .lmeg-ov-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin:8px 0 20px;}
    .lmeg-admin .lmeg-ov-panel{background:linear-gradient(160deg,#161826,#1C1F2E);border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:16px 18px;}
    .lmeg-admin .lmeg-ov-panel__head{display:flex;justify-content:space-between;align-items:baseline;font-weight:600;font-size:13px;letter-spacing:.04em;margin-bottom:12px;}
    .lmeg-admin .lmeg-ov-panel__head a{font-size:12px;font-weight:500;}
    .lmeg-admin .lmeg-ov-empty{font-size:13px;opacity:.6;}
    .lmeg-admin .lmeg-ov-bar{display:flex;align-items:center;gap:9px;margin:5px 0;}
    .lmeg-admin .lmeg-ov-bar__label{flex:0 0 110px;font-size:12.5px;}
    .lmeg-admin .lmeg-ov-bar__track{flex:1;height:9px;background:rgba(255,255,255,.07);border-radius:5px;overflow:hidden;}
    .lmeg-admin .lmeg-ov-bar__track div{height:100%;}
    .lmeg-admin .lmeg-ov-bar__val{flex:0 0 40px;text-align:right;font-size:12.5px;font-variant-numeric:tabular-nums;}
    .lmeg-admin .lmeg-ov-metrics{display:flex;gap:20px;flex-wrap:wrap;}
    .lmeg-admin .lmeg-ov-metrics div{display:flex;flex-direction:column;}
    .lmeg-admin .lmeg-ov-metrics strong{font-size:20px;font-weight:700;font-variant-numeric:tabular-nums;}
    .lmeg-admin .lmeg-ov-metrics span{font-size:11px;opacity:.55;text-transform:uppercase;letter-spacing:.05em;}
    .lmeg-admin .lmeg-ov-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;}
    </style>
    <?php
}
