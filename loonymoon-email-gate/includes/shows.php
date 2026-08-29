<?php
/**
 * Tour dates + "pick up at the show".
 *
 * Artists add their upcoming shows (manually, or later synced from Bandsintown);
 * at checkout a fan buying physical merch can choose to collect it at a show
 * instead of paying shipping. This file owns the shows data model and the admin
 * manager. The checkout wiring and the per-show pickup list live alongside the
 * cart/orders code.
 */

if (!defined('ABSPATH')) exit;

/* ---------------------------------------------------------------------------
 * Data
 * ------------------------------------------------------------------------- */

/** Every show, soonest first (undated shows last). */
function lmeg_shows_all() {
    global $wpdb;
    return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lmeg_shows ORDER BY (show_date IS NULL), show_date ASC, id DESC");
}

/** One show by id, or null. */
function lmeg_shows_get($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lmeg_shows WHERE id = %d", (int) $id));
}

/**
 * Active shows a fan can still pick up at — undated, or dated today or later.
 * This is the list offered at checkout, soonest first.
 */
function lmeg_shows_upcoming() {
    global $wpdb;
    $today = current_time('Y-m-d') . ' 00:00:00';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lmeg_shows
         WHERE active = 1 AND (show_date IS NULL OR show_date >= %s)
         ORDER BY (show_date IS NULL), show_date ASC, id DESC",
        $today
    ));
}

/** "Venue, City" (+ region/country when useful) — the human name for a show. */
function lmeg_show_place($s) {
    $bits = [];
    if (!empty($s->venue)) $bits[] = $s->venue;
    $loc = trim(($s->city ?? '') . (!empty($s->region) ? ', ' . $s->region : ''));
    if ($loc !== '') $bits[] = $loc;
    $out = implode(' · ', $bits);
    return $out !== '' ? $out : 'Show';
}

/** "Venue, City — Fri, Oct 28" — full label with the date, for menus and receipts. */
function lmeg_show_label($s) {
    $label = lmeg_show_place($s);
    if (!empty($s->show_date)) {
        $ts = strtotime($s->show_date);
        if ($ts) $label .= ' — ' . date_i18n('D, M j', $ts);
    }
    return $label;
}

/** Is "pick up at a show" available? On by setting AND at least one upcoming show. */
function lmeg_pickup_enabled() {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    if (empty($s['store_pickup_enabled'])) return false;
    return count(lmeg_shows_upcoming()) > 0;
}

/* ---------------------------------------------------------------------------
 * Admin write handlers (secured: current_user_can + nonce)
 * ------------------------------------------------------------------------- */

add_action('admin_post_lmeg_save_show', 'lmeg_handle_save_show');
function lmeg_handle_save_show() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_save_show', 'lmeg_show_nonce');
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_shows';

    $venue = trim(sanitize_text_field(wp_unslash($_POST['venue'] ?? '')));
    $city  = trim(sanitize_text_field(wp_unslash($_POST['city'] ?? '')));
    if ($venue === '' && $city === '') {
        wp_safe_redirect(admin_url('admin.php?page=lmeg-products&showerr=1#shows')); exit;
    }
    // Normalise the datetime-local value ("2026-10-28T20:00") to MySQL, or blank.
    $raw_date = sanitize_text_field(wp_unslash($_POST['show_date'] ?? ''));
    $show_date = null;
    if ($raw_date !== '') {
        $ts = strtotime(str_replace('T', ' ', $raw_date));
        if ($ts) $show_date = date('Y-m-d H:i:s', $ts);
    }
    $data = [
        'venue'       => mb_substr($venue, 0, 190),
        'city'        => mb_substr($city, 0, 120),
        'region'      => mb_substr(trim(sanitize_text_field(wp_unslash($_POST['region'] ?? ''))), 0, 120),
        'country'     => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($_POST['country'] ?? '')), 0, 2)),
        'show_date'   => $show_date,
        'pickup_note' => mb_substr(trim(sanitize_text_field(wp_unslash($_POST['pickup_note'] ?? ''))), 0, 255),
        'active'      => empty($_POST['active']) ? 0 : 1,
    ];
    $id = (int) ($_POST['show_id'] ?? 0);
    if ($id && lmeg_shows_get($id)) {
        $wpdb->update($tbl, $data, ['id' => $id]);
    } else {
        $data['source']     = 'manual';
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($tbl, $data);
    }
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products&showsaved=1#shows')); exit;
}

add_action('admin_post_lmeg_delete_show', 'lmeg_handle_delete_show');
function lmeg_handle_delete_show() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_delete_show', 'lmeg_show_del_nonce');
    global $wpdb;
    $id = (int) ($_POST['show_id'] ?? 0);
    if ($id) $wpdb->delete($wpdb->prefix . 'lmeg_shows', ['id' => $id]);
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products&showdeleted=1#shows')); exit;
}

add_action('admin_post_lmeg_save_pickup', 'lmeg_handle_save_pickup');
function lmeg_handle_save_pickup() {
    if (!current_user_can('manage_options')) wp_die('nope');
    check_admin_referer('lmeg_save_pickup', 'lmeg_pickup_nonce');
    $opts = (array) get_option(LMEG_OPTION, []);
    $opts['store_pickup_enabled'] = empty($_POST['store_pickup_enabled']) ? 0 : 1;
    update_option(LMEG_OPTION, $opts);
    wp_safe_redirect(admin_url('admin.php?page=lmeg-products&pickupsaved=1#shows')); exit;
}

/* ---------------------------------------------------------------------------
 * Admin — the "Shows & pickup" section, embedded on the Store page.
 * ------------------------------------------------------------------------- */

function lmeg_shows_admin_section() {
    if (!current_user_can('manage_options')) return;
    $rows     = lmeg_shows_all();
    $settings = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $enabled  = !empty($settings['store_pickup_enabled']);
    $save     = admin_url('admin-post.php');
    ?>
    <h2 id="shows" style="margin-top:34px">Shows &amp; pick-up</h2>
    <?php
    if (isset($_GET['showsaved']))   echo '<div class="notice notice-success is-dismissible"><p>Show saved.</p></div>';
    if (isset($_GET['showdeleted'])) echo '<div class="notice notice-success is-dismissible"><p>Show removed.</p></div>';
    if (isset($_GET['pickupsaved'])) echo '<div class="notice notice-success is-dismissible"><p>Pick-up setting saved.</p></div>';
    if (isset($_GET['showerr']))     echo '<div class="notice notice-error"><p>Add at least a venue or a city.</p></div>';
    ?>
    <p class="description" style="margin:0 0 12px;max-width:820px">Add your upcoming shows, then let fans choose <strong>&ldquo;pick up at a show&rdquo;</strong> at checkout instead of paying shipping — you bring their order to the merch table. Great for tours: no postage, and it pulls the sale online instead of hoping they stop by.</p>

    <form method="post" action="<?php echo esc_url($save); ?>" style="max-width:900px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 18px;margin-bottom:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <?php wp_nonce_field('lmeg_save_pickup', 'lmeg_pickup_nonce'); ?>
        <input type="hidden" name="action" value="lmeg_save_pickup">
        <label style="font-weight:600;display:inline-flex;align-items:center;gap:8px">
            <input type="checkbox" name="store_pickup_enabled" value="1" <?php checked($enabled); ?>>
            Offer &ldquo;pick up at a show&rdquo; at checkout
        </label>
        <span class="description">Only appears when you have an upcoming show below.</span>
        <button type="submit" class="button">Save</button>
    </form>

    <table class="widefat striped" style="max-width:900px;margin-bottom:14px">
        <thead><tr><th>Venue</th><th>City</th><th>Date</th><th>Note for fans</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows) : ?><tr><td colspan="6">No shows yet — add your first date below.</td></tr>
        <?php else : foreach ($rows as $s) :
            $past = (!empty($s->show_date) && strtotime($s->show_date) < current_time('timestamp'));
        ?>
            <tr>
                <td><strong><?php echo esc_html($s->venue ?: '—'); ?></strong><?php echo $s->source === 'bandsintown' ? ' <span class="description" style="font-weight:400">· Bandsintown</span>' : ''; ?></td>
                <td><?php echo esc_html(trim(($s->city ?: '') . ($s->region ? ', ' . $s->region : '')) ?: '—'); ?><?php echo $s->country ? ' <span style="color:#9A9DB0">' . esc_html($s->country) . '</span>' : ''; ?></td>
                <td><?php echo !empty($s->show_date) ? esc_html(date_i18n('M j, Y · g:ia', strtotime($s->show_date))) . ($past ? ' <span style="color:#b32d2e">(past)</span>' : '') : '<span style="color:#9A9DB0">—</span>'; ?></td>
                <td style="max-width:220px;color:#50505c"><?php echo esc_html($s->pickup_note ?: '—'); ?></td>
                <td><?php echo (int) $s->active ? '<span style="color:#1a8a4a">● On</span>' : '<span style="color:#9A9DB0">Off</span>'; ?></td>
                <td style="white-space:nowrap">
                    <button type="button" class="button button-small lmeg-show-edit"
                        data-id="<?php echo (int) $s->id; ?>"
                        data-venue="<?php echo esc_attr($s->venue); ?>"
                        data-city="<?php echo esc_attr($s->city); ?>"
                        data-region="<?php echo esc_attr($s->region); ?>"
                        data-country="<?php echo esc_attr($s->country); ?>"
                        data-date="<?php echo esc_attr(!empty($s->show_date) ? date('Y-m-d\TH:i', strtotime($s->show_date)) : ''); ?>"
                        data-note="<?php echo esc_attr($s->pickup_note); ?>"
                        data-active="<?php echo (int) $s->active; ?>">Edit</button>
                    <form method="post" action="<?php echo esc_url($save); ?>" style="display:inline" onsubmit="return confirm('Remove this show?');">
                        <?php wp_nonce_field('lmeg_delete_show', 'lmeg_show_del_nonce'); ?>
                        <input type="hidden" name="action" value="lmeg_delete_show">
                        <input type="hidden" name="show_id" value="<?php echo (int) $s->id; ?>">
                        <button class="button button-small link-delete" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <form method="post" action="<?php echo esc_url($save); ?>" id="lmeg-show-form" style="max-width:900px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 18px">
        <?php wp_nonce_field('lmeg_save_show', 'lmeg_show_nonce'); ?>
        <input type="hidden" name="action" value="lmeg_save_show">
        <input type="hidden" name="show_id" id="lmeg-show-id" value="">
        <strong id="lmeg-show-formtitle">Add a show</strong>
        <table class="form-table" role="presentation">
            <tr><th><label>Venue</label></th><td><input type="text" name="venue" id="lmeg-f-venue" class="regular-text" placeholder="The Fillmore"></td></tr>
            <tr><th><label>City</label></th><td>
                <input type="text" name="city" id="lmeg-f-city" class="regular-text" placeholder="San Francisco">
                &nbsp; <input type="text" name="region" id="lmeg-f-region" style="width:120px" placeholder="CA">
                &nbsp; <input type="text" name="country" id="lmeg-f-country" maxlength="2" style="width:64px;text-transform:uppercase" placeholder="US">
                <span class="description">region &amp; country optional</span>
            </td></tr>
            <tr><th><label>Date &amp; time</label></th><td><input type="datetime-local" name="show_date" id="lmeg-f-date"> <span class="description">optional — leave blank for &ldquo;whenever&rdquo;</span></td></tr>
            <tr><th><label>Note for fans</label></th><td><input type="text" name="pickup_note" id="lmeg-f-note" class="regular-text" placeholder="Find us at the merch table after the set"> <span class="description">shown on the receipt</span></td></tr>
            <tr><th><label>Status</label></th><td><label><input type="checkbox" name="active" id="lmeg-f-active" value="1" checked> Available for pick-up</label></td></tr>
        </table>
        <p><button type="submit" class="button button-primary">Save show</button> <button type="button" class="button" id="lmeg-show-reset" style="display:none">Cancel edit</button></p>
    </form>
    <script>
    (function(){
        var f=document.getElementById('lmeg-show-form'); if(!f) return;
        function set(id,v){ var el=document.getElementById(id); if(el){ if(el.type==='checkbox'){el.checked=!!(+v);} else {el.value=v||'';} } }
        document.querySelectorAll('.lmeg-show-edit').forEach(function(b){
            b.addEventListener('click',function(){
                document.getElementById('lmeg-show-id').value=b.getAttribute('data-id');
                set('lmeg-f-venue',b.getAttribute('data-venue'));
                set('lmeg-f-city',b.getAttribute('data-city'));
                set('lmeg-f-region',b.getAttribute('data-region'));
                set('lmeg-f-country',b.getAttribute('data-country'));
                set('lmeg-f-date',b.getAttribute('data-date'));
                set('lmeg-f-note',b.getAttribute('data-note'));
                set('lmeg-f-active',b.getAttribute('data-active'));
                document.getElementById('lmeg-show-formtitle').textContent='Edit show';
                document.getElementById('lmeg-show-reset').style.display='';
                f.scrollIntoView({behavior:'smooth',block:'center'});
            });
        });
        var r=document.getElementById('lmeg-show-reset');
        if(r) r.addEventListener('click',function(){ f.reset(); document.getElementById('lmeg-show-id').value=''; document.getElementById('lmeg-show-formtitle').textContent='Add a show'; r.style.display='none'; });
    })();
    </script>
    <?php
}
