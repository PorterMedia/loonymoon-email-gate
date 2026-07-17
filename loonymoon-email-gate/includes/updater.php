<?php
/**
 * GitHub-driven self-updater.
 *
 * Checks the plugin's GitHub Releases API for a newer tagged release,
 * and hooks into WordPress's plugin update system so a fresh version
 * shows up on the Plugins page with a normal "Update now" link. When
 * clicked, WP downloads the zip attached to the release, extracts it,
 * and swaps the plugin in place — no manual zip uploads.
 *
 * Configuration lives in constants at the top of the main plugin file:
 *   LMEG_GITHUB_OWNER  — GitHub username / org
 *   LMEG_GITHUB_REPO   — repo name
 *
 * The release must have a zip asset named "<repo>.zip" containing a
 * folder of the same name. That's what `gh release create --dir` and
 * the manual zip flow both produce, so this "just works" in practice.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('pre_set_site_transient_update_plugins', 'lmeg_updater_check', 20);
add_filter('plugins_api',                            'lmeg_updater_info',  20, 3);
add_filter('upgrader_source_selection',              'lmeg_updater_rename_folder', 10, 3);

const LMEG_UPDATER_CACHE_KEY = 'lmeg_github_release';
const LMEG_UPDATER_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

/**
 * Custom 15-minute cron schedule + tick that clears WP's own
 * "update_plugins" transient. This forces WP to re-run its update
 * scan (which fires our `pre_set_site_transient_update_plugins`
 * filter) on the next request, so new releases surface within ~15
 * minutes instead of waiting for WP's default twice-a-day check.
 */
add_filter('cron_schedules', 'lmeg_updater_cron_schedules');
function lmeg_updater_cron_schedules($schedules) {
    if (!isset($schedules['lmeg_quarter_hour'])) {
        $schedules['lmeg_quarter_hour'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => 'Every 15 minutes (Loonymoon Email Gate)',
        ];
    }
    return $schedules;
}

add_action('init', 'lmeg_updater_ensure_cron');
function lmeg_updater_ensure_cron() {
    if (!wp_next_scheduled('lmeg_updater_tick')) {
        wp_schedule_event(time() + 60, 'lmeg_quarter_hour', 'lmeg_updater_tick');
    }
}

add_action('lmeg_updater_tick', 'lmeg_updater_tick_handler');
function lmeg_updater_tick_handler() {
    delete_site_transient(LMEG_UPDATER_CACHE_KEY);
    delete_site_transient('update_plugins');
}

/**
 * Fetch and cache the latest release payload from GitHub. Returns the
 * decoded array on success, or null on error / missing configuration.
 */
function lmeg_updater_fetch_release($force = false) {
    if (!defined('LMEG_GITHUB_OWNER') || !defined('LMEG_GITHUB_REPO')) return null;
    if (!LMEG_GITHUB_OWNER || !LMEG_GITHUB_REPO) return null;

    if (!$force) {
        $cached = get_site_transient(LMEG_UPDATER_CACHE_KEY);
        if ($cached !== false) return $cached ?: null;
    }

    $url = sprintf(
        'https://api.github.com/repos/%s/%s/releases/latest',
        rawurlencode(LMEG_GITHUB_OWNER),
        rawurlencode(LMEG_GITHUB_REPO)
    );
    $resp = wp_remote_get($url, [
        'timeout' => 8,
        'headers' => [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'LMEG-Updater/' . LMEG_VERSION,
        ],
    ]);
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        // Cache a short empty result so we don't hammer the API on every load.
        set_site_transient(LMEG_UPDATER_CACHE_KEY, [], MINUTE_IN_SECONDS * 15);
        return null;
    }
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($data) || empty($data['tag_name'])) {
        set_site_transient(LMEG_UPDATER_CACHE_KEY, [], MINUTE_IN_SECONDS * 15);
        return null;
    }

    set_site_transient(LMEG_UPDATER_CACHE_KEY, $data, LMEG_UPDATER_CACHE_TTL);
    return $data;
}

/**
 * Strip a leading "v" from a tag (v2.13.0 → 2.13.0).
 */
function lmeg_updater_normalize_version($tag) {
    return ltrim((string) $tag, 'vV');
}

/**
 * Find the first zip asset attached to the release. Falls back to the
 * repo-generated source zip (which needs the folder rename filter below).
 */
function lmeg_updater_zip_url($release) {
    if (!empty($release['assets']) && is_array($release['assets'])) {
        foreach ($release['assets'] as $asset) {
            if (!empty($asset['browser_download_url']) &&
                strtolower(substr($asset['name'] ?? '', -4)) === '.zip') {
                return $asset['browser_download_url'];
            }
        }
    }
    return $release['zipball_url'] ?? '';
}

/**
 * Advertise an update to WP if the GitHub tag is newer than LMEG_VERSION.
 */
function lmeg_updater_check($transient) {
    if (empty($transient) || !is_object($transient)) return $transient;

    $release = lmeg_updater_fetch_release();
    if (!$release) return $transient;

    $new_version = lmeg_updater_normalize_version($release['tag_name']);
    if (version_compare($new_version, LMEG_VERSION, '<=')) return $transient;

    $zip = lmeg_updater_zip_url($release);
    if (!$zip) return $transient;

    $plugin_file = plugin_basename(LMEG_PLUGIN_FILE);
    $transient->response[$plugin_file] = (object) [
        'id'          => $plugin_file,
        'slug'        => dirname($plugin_file),
        'plugin'      => $plugin_file,
        'new_version' => $new_version,
        'url'         => $release['html_url'] ?? sprintf('https://github.com/%s/%s', LMEG_GITHUB_OWNER, LMEG_GITHUB_REPO),
        'package'     => $zip,
        'tested'      => '6.6',
        'requires'    => '5.8',
        'requires_php'=> '7.4',
    ];
    return $transient;
}

/**
 * Populate the "View details" modal that appears when a user clicks the
 * update notice. Body of the release becomes the changelog.
 */
function lmeg_updater_info($result, $action, $args) {
    if ($action !== 'plugin_information') return $result;
    if (empty($args->slug) || $args->slug !== dirname(plugin_basename(LMEG_PLUGIN_FILE))) return $result;

    $release = lmeg_updater_fetch_release();
    if (!$release) return $result;

    $new_version = lmeg_updater_normalize_version($release['tag_name']);
    $body        = $release['body'] ?? '';
    $zip         = lmeg_updater_zip_url($release);

    return (object) [
        'name'          => 'Loonymoon Email Gate',
        'slug'          => dirname(plugin_basename(LMEG_PLUGIN_FILE)),
        'version'       => $new_version,
        'author'        => '<a href="https://github.com/' . esc_attr(LMEG_GITHUB_OWNER) . '">' . esc_html(LMEG_GITHUB_OWNER) . '</a>',
        'homepage'      => sprintf('https://github.com/%s/%s', LMEG_GITHUB_OWNER, LMEG_GITHUB_REPO),
        'requires'      => '5.8',
        'tested'        => '6.6',
        'requires_php'  => '7.4',
        'last_updated'  => $release['published_at'] ?? '',
        'download_link' => $zip,
        'trunk'         => $zip,
        'sections'      => [
            'description' => 'Gate posts, capture emails/phones, run tag-based broadcasts and paid tiers.',
            'changelog'   => nl2br(esc_html($body)) ?: 'See <a href="' . esc_url($release['html_url'] ?? '') . '">GitHub release notes</a>.',
        ],
    ];
}

/**
 * WordPress extracts the update zip into a folder named after the zip.
 * GitHub source zips extract as "owner-repo-abcd1234/" — WP needs the
 * folder to be named exactly "loonymoon-email-gate/" or it can't swap
 * the plugin in place. Rename post-extraction if needed. Only touches
 * upgrades of THIS plugin.
 */
function lmeg_updater_rename_folder($source, $remote_source, $upgrader) {
    if (!isset($upgrader->skin->plugin_info['Name'])) return $source;
    // Only intervene when the upgrader is upgrading THIS plugin.
    $plugin_file = plugin_basename(LMEG_PLUGIN_FILE);
    $upgrading   = isset($upgrader->skin->plugin) ? $upgrader->skin->plugin : '';
    if ($upgrading && $upgrading !== $plugin_file) return $source;

    $desired = trailingslashit($remote_source) . dirname($plugin_file);
    if ($source === $desired) return $source;

    global $wp_filesystem;
    if ($wp_filesystem && $wp_filesystem->move($source, $desired)) {
        return $desired;
    }
    return $source;
}

/**
 * Force-refresh the release check when someone hits "Check again" on the
 * Plugins page. Cheap; only fires when WP is explicitly re-scanning.
 */
add_action('upgrader_process_complete', function ($upgrader, $hook_extra) {
    if (($hook_extra['type'] ?? '') === 'plugin') {
        delete_site_transient(LMEG_UPDATER_CACHE_KEY);
    }
}, 10, 2);
