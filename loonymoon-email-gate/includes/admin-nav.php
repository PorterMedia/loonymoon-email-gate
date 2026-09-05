<?php
/**
 * Fanloop admin hub navigation.
 *
 * The plugin registers ~34 flat submenu items — a wall nobody can scan. This
 * lays a two-row hub bar across the top of every Fanloop admin page:
 *   row 1 — the ~9 top-level hubs (Overview, Audience, Messaging, …)
 *   row 2 — the sub-tabs of the hub you're currently in
 *
 * It changes no page logic: hubs are built from the pages ACTUALLY registered
 * under the Fanloop menu (so conditional pages like Orders and capability-gated
 * items appear only when present, and nothing 404s). Anything registered but not
 * mapped falls into a "More" hub so no page is ever unreachable.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Hub key => [label, pages[[slug,short label], …]] in display order. */
function lmeg_admin_hub_map() {
    return [
        'overview'  => ['label' => 'Overview',  'pages' => [
            ['lmeg-overview', 'Overview'],
        ]],
        'audience'  => ['label' => 'Audience',  'pages' => [
            ['lmeg', 'Subscribers'], ['lmeg-audience', 'Audience'], ['lmeg-fanbase', 'Fanbase'], ['lmeg-tags', 'Tags'],
            ['lmeg-segments', 'Segments'], ['lmeg-top-fans', 'Top Fans'], ['lmeg-members', 'Members'],
            ['lmeg-journey', 'Journey'], ['lmeg-referrals', 'Referrals'],
        ]],
        'messaging' => ['label' => 'Messaging', 'pages' => [
            ['lmeg-compose', 'Compose'], ['lmeg-broadcasts', 'History'], ['lmeg-templates', 'Templates'],
            ['lmeg-sequences', 'Sequences'], ['lmeg-deliverability', 'Deliverability'],
        ]],
        'releases'  => ['label' => 'Releases',  'pages' => [
            ['lmeg-releases', 'Releases'], ['lmeg-drops', 'Drops'], ['lmeg-presaves', 'Pre-Saves'],
            ['lmeg-smartlinks', 'Smartlinks'],
        ]],
        'store'     => ['label' => 'Store',     'pages' => [
            ['lmeg-products', 'Products'], ['lmeg-orders', 'Orders'], ['lmeg-store-promos', 'Promotions'],
            ['lmeg-store-shows', 'Shows'], ['lmeg-store-stock', 'Stock'], ['lmeg-shop', 'Revenue'],
            ['lmeg-store-tools', 'Tools'],
        ]],
        'engage'    => ['label' => 'Engage',    'pages' => [
            ['lmeg-contests', 'Contests'], ['lmeg-surveys', 'Surveys'], ['lmeg-tour', 'Tour'],
            ['lmeg-bio', 'Smart Bio'], ['lmeg-shortcodes', 'Shortcodes'],
        ]],
        'social'    => ['label' => 'Social',    'pages' => [
            ['lmeg-social', 'Social Listening'],
        ]],
        'connect'   => ['label' => 'Connect',   'pages' => [
            ['lmeg-instagram', 'Instagram'], ['lmeg-spotify', 'Spotify'], ['lmeg-wallet', 'Wallet'],
            ['lmeg-tiers', 'Tiers'],
        ]],
        'ai'        => ['label' => 'Ask AI',    'pages' => [
            ['lmeg-ai', 'Ask AI'],
        ]],
        'settings'  => ['label' => 'Settings',  'pages' => [
            ['lmeg-settings', 'Settings'], ['lmeg-setup', 'Get Started'],
        ]],
    ];
}

/** slug => menu title for everything actually registered under 'lmeg'. */
function lmeg_admin_registered_slugs() {
    global $submenu;
    $slugs = [];
    if (!empty($submenu['lmeg']) && is_array($submenu['lmeg'])) {
        foreach ($submenu['lmeg'] as $item) {
            if (!empty($item[2])) $slugs[$item[2]] = isset($item[0]) ? wp_strip_all_tags($item[0]) : $item[2];
        }
    }
    return $slugs;
}

/** The current Fanloop admin page slug, or '' when we're not on one. */
function lmeg_admin_current_slug() {
    if (!is_admin()) return '';
    $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
    if ($page === '' || strpos($page, 'lmeg') !== 0) return '';
    return $page;
}

/**
 * Build the resolved hub structure for a given current slug.
 * Returns ['hubs' => [key => ['label','pages']], 'active' => key].
 * Pure (no WP output) so it can be unit-tested with a stubbed registry.
 */
function lmeg_admin_hubs_resolve($current, $registered) {
    $map     = lmeg_admin_hub_map();
    $hubs    = [];
    $covered = [];
    foreach ($map as $key => $hub) {
        $pages = [];
        foreach ($hub['pages'] as $p) {
            if (isset($registered[$p[0]])) { $pages[] = $p; $covered[$p[0]] = true; }
        }
        if ($pages) $hubs[$key] = ['label' => $hub['label'], 'pages' => $pages];
    }
    // Registered but unmapped → keep reachable under "More".
    $more = [];
    foreach ($registered as $slug => $title) {
        if (empty($covered[$slug])) $more[] = [$slug, $title ?: $slug];
    }
    if ($more) $hubs['more'] = ['label' => 'More', 'pages' => $more];

    // Which hub owns the current page?
    $active = '';
    foreach ($hubs as $key => $hub) {
        foreach ($hub['pages'] as $p) if ($p[0] === $current) { $active = $key; break 2; }
    }
    if ($active === '') {
        $keys   = array_keys($hubs);
        $active = $keys ? $keys[0] : '';
    }
    return ['hubs' => $hubs, 'active' => $active];
}

/**
 * The current hub's sub-tabs (for the second nav row). Returns [[slug,label], …]
 * or [] when the active hub has only one page (no sub-row needed).
 * Consumed by lmeg_admin_app_bar() in admin.php.
 */
function lmeg_admin_hub_subtabs($current) {
    $resolved = lmeg_admin_hubs_resolve($current, lmeg_admin_registered_slugs());
    $active   = $resolved['active'];
    $pages    = isset($resolved['hubs'][$active]) ? $resolved['hubs'][$active]['pages'] : [];
    return (count($pages) > 1) ? $pages : [];
}

/**
 * Sub-tab row styling, printed INLINE in the admin head. This codebase's own
 * comment (lmeg_admin_contrast_css) notes optimization plugins serve a stale
 * cacheable admin.css, so nav chrome that must always be correct is inlined.
 * The hub row (row 1) reuses the existing .lmeg-appbar__* classes.
 */
add_action('admin_head', 'lmeg_admin_nav_css', 98);
function lmeg_admin_nav_css() {
    if (lmeg_admin_current_slug() === '') return;
    echo "<style id='lmeg-nav-css'>\n"
       . ".lmeg-subbar{display:flex;flex-wrap:wrap;align-items:center;gap:4px;"
       . "margin:-4px 20px 0 2px;padding:8px 14px;background:var(--lmegA-bg2,#12141F);"
       . "border:1px solid var(--lmegA-border,rgba(255,255,255,.07));border-top:0;"
       . "border-radius:0 0 12px 12px;font-family:var(--lmegA-font,'DM Sans',sans-serif);}\n"
       . ".lmeg-subbar__link{display:inline-block;padding:5px 12px;border-radius:999px;"
       . "font-size:12.5px;font-weight:500;line-height:1;color:var(--lmegA-muted,#8B90A0)!important;"
       . "text-decoration:none!important;transition:color .15s ease,background-color .15s ease;}\n"
       . ".lmeg-subbar__link:hover{color:var(--lmegA-text,#F4F5F7)!important;background:rgba(255,255,255,.05);}\n"
       . ".lmeg-subbar__link.is-active{color:#fff!important;background:var(--lmegA-accent,#D05FA2);}\n"
       . ".lmeg-subbar__link:focus-visible{outline:2px solid var(--lmegA-accent,#D05FA2);outline-offset:1px;}\n"
       . "</style>\n";
}
