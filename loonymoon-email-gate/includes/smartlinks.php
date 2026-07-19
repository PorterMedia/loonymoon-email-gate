<?php
/**
 * Smartlinks — short, trackable redirect links: yoursite.com/go/<slug>.
 * Click counts live locally; if the visitor is a known member the click is
 * also logged as an event on their fan timeline. QR codes are rendered in
 * the admin via a public QR image API (display-only, no data stored there
 * beyond the URL itself).
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('query_vars', function ($vars) {
    $vars[] = 'lmeg_go';
    return $vars;
});

add_action('init', function () {
    add_rewrite_rule('^go/([A-Za-z0-9\-_]+)/?$', 'index.php?lmeg_go=$matches[1]', 'top');
});

add_action('template_redirect', 'lmeg_smartlink_redirect');
function lmeg_smartlink_redirect() {
    $slug = get_query_var('lmeg_go');
    if (!$slug && isset($_GET['lmeg_go'])) {
        $slug = sanitize_title($_GET['lmeg_go']);
    }
    if (!$slug) return;

    global $wpdb;
    $tbl  = $wpdb->prefix . 'lmeg_smartlinks';
    $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE slug = %s", $slug));
    if (!$link) {
        wp_safe_redirect(home_url('/'));
        exit;
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE $tbl SET clicks = clicks + 1, last_clicked_at = %s WHERE id = %d",
        current_time('mysql'), $link->id
    ));

    // If we know who this is, put the click on their timeline as an event.
    if (function_exists('lmeg_current_member')) {
        $member = lmeg_current_member();
        if ($member) {
            $wpdb->insert($wpdb->prefix . 'lmeg_broadcast_events', [
                'broadcast_id'  => 0,
                'subscriber_id' => (int) $member->id,
                'event_type'    => 'click',
                'url'           => 'smartlink:' . $link->slug . ' → ' . substr($link->target_url, 0, 400),
                'ip'            => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
                'user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                'created_at'    => current_time('mysql'),
            ]);
        }
    }

    wp_redirect(esc_url_raw($link->target_url), 302);
    exit;
}

function lmeg_smartlink_url($slug) {
    // Pretty URL if permalinks are on; query fallback otherwise.
    if (get_option('permalink_structure')) {
        return home_url('/go/' . rawurlencode($slug) . '/');
    }
    return add_query_arg('lmeg_go', rawurlencode($slug), home_url('/'));
}

/**
 * Flush rewrite rules once after this feature ships (rewrites registered on
 * init won't take effect until a flush).
 */
add_action('init', function () {
    if (get_option('lmeg_smartlinks_flushed') !== '1') {
        flush_rewrite_rules(false);
        update_option('lmeg_smartlinks_flushed', '1', false);
    }
}, 99);
