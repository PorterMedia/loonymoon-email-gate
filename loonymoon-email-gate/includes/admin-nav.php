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
            ['lmeg', 'Subscribers'], ['lmeg-audience', 'Audience'], ['lmeg-tags', 'Tags'],
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
            ['lmeg-products', 'Store'], ['lmeg-orders', 'Orders'], ['lmeg-shop', 'Revenue'],
        ]],
        'engage'    => ['label' => 'Engage',    'pages' => [
            ['lmeg-contests', 'Contests'], ['lmeg-surveys', 'Surveys'], ['lmeg-tour', 'Tour'],
            ['lmeg-bio', 'Smart Bio'], ['lmeg-shortcodes', 'Shortcodes'], ['lmeg-social', 'Social'],
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

add_action('in_admin_header', 'lmeg_admin_hub_bar');
function lmeg_admin_hub_bar() {
    $current = lmeg_admin_current_slug();
    if ($current === '') return;

    $resolved = lmeg_admin_hubs_resolve($current, lmeg_admin_registered_slugs());
    $hubs     = $resolved['hubs'];
    $active   = $resolved['active'];
    if (empty($hubs)) return;

    $url = function ($slug) { return admin_url('admin.php?page=' . $slug); };
    ?>
    <div class="lmeg-hubbar" role="navigation" aria-label="Fanloop sections">
        <div class="lmeg-hubbar__row lmeg-hubbar__row--hubs">
            <span class="lmeg-hubbar__brand">Fanloop</span>
            <?php foreach ($hubs as $key => $hub):
                $first = $hub['pages'][0][0]; ?>
                <a class="lmeg-hub<?php echo $key === $active ? ' is-active' : ''; ?>"
                   href="<?php echo esc_url($url($first)); ?>"><?php echo esc_html($hub['label']); ?></a>
            <?php endforeach; ?>
        </div>
        <?php $sub = isset($hubs[$active]) ? $hubs[$active]['pages'] : []; if (count($sub) > 1): ?>
        <div class="lmeg-hubbar__row lmeg-hubbar__row--tabs">
            <?php foreach ($sub as $p): ?>
                <a class="lmeg-subtab<?php echo $p[0] === $current ? ' is-active' : ''; ?>"
                   href="<?php echo esc_url($url($p[0])); ?>"><?php echo esc_html($p[1]); ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
