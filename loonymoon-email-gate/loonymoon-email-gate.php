<?php
/**
 * Plugin Name: Loonymoon Email Gate
 * Plugin URI:  https://loonymoonchild.com/
 * Description: Gate post content behind an email or phone opt-in. Captures address fields, broadcasts to subscribers via Brevo (email) and Twilio (SMS).
 * Version:     2.55.12
 * Author:      Porter Media
 * License:     GPL-2.0+
 * Text Domain: loonymoon-email-gate
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LMEG_VERSION',     '2.55.12');
define('LMEG_DB_VERSION',  '2.55.12');
define('LMEG_TABLE',       'lmeg_subscribers');
define('LMEG_OPTION',      'lmeg_settings');
define('LMEG_COOKIE',      'lmeg_unlocked');
define('LMEG_PLUGIN_FILE', __FILE__);
define('LMEG_PLUGIN_DIR',  plugin_dir_path(__FILE__));
define('LMEG_PLUGIN_URL',  plugin_dir_url(__FILE__));

// GitHub repo for self-updater. Override in wp-config.php with:
//   define('LMEG_GITHUB_OWNER', 'yourname');
//   define('LMEG_GITHUB_REPO', 'loonymoon-email-gate');
if (!defined('LMEG_GITHUB_OWNER')) define('LMEG_GITHUB_OWNER', 'PorterMedia');
if (!defined('LMEG_GITHUB_REPO'))  define('LMEG_GITHUB_REPO',  'loonymoon-email-gate');

// How often WP re-checks GitHub for a new release, in minutes.
// Override in wp-config.php with: define('LMEG_UPDATE_INTERVAL_MINUTES', 15);
if (!defined('LMEG_UPDATE_INTERVAL_MINUTES')) define('LMEG_UPDATE_INTERVAL_MINUTES', 2);

/**
 * Optionally load shared credentials from a .env file so they don't have to
 * be re-entered in each artist site's admin. Looked up in order:
 *   1. path in the LMEG_ENV_FILE constant (wp-config.php)
 *   2. wp-content/.env
 *   3. one level above the WordPress root (a common, web-inaccessible spot)
 * Only LMEG_-prefixed KEY=VALUE lines are turned into constants — safe, and
 * it won't clobber anything already defined in wp-config.php.
 */
function lmeg_load_env_file() {
    $candidates = [];
    if (defined('LMEG_ENV_FILE')) $candidates[] = LMEG_ENV_FILE;
    if (defined('WP_CONTENT_DIR')) $candidates[] = WP_CONTENT_DIR . '/.env';
    if (defined('ABSPATH')) $candidates[] = dirname(ABSPATH) . '/.env';

    foreach ($candidates as $file) {
        if (!$file || !is_readable($file)) continue;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            // strip surrounding quotes
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                $v = substr($v, 1, -1);
            }
            if (preg_match('/^LMEG_[A-Z0-9_]+$/', $k) && !defined($k)) {
                define($k, $v);
            }
        }
        break; // first readable file wins
    }
}
lmeg_load_env_file();

require_once LMEG_PLUGIN_DIR . 'includes/security.php';
require_once LMEG_PLUGIN_DIR . 'includes/tags.php';
require_once LMEG_PLUGIN_DIR . 'includes/sending.php';
require_once LMEG_PLUGIN_DIR . 'includes/sequences.php';
require_once LMEG_PLUGIN_DIR . 'includes/members.php';
require_once LMEG_PLUGIN_DIR . 'includes/shortcodes.php';
require_once LMEG_PLUGIN_DIR . 'includes/shop.php';
require_once LMEG_PLUGIN_DIR . 'includes/fans.php';
require_once LMEG_PLUGIN_DIR . 'includes/smartlinks.php';
require_once LMEG_PLUGIN_DIR . 'includes/biopage.php';
require_once LMEG_PLUGIN_DIR . 'includes/drops.php';
require_once LMEG_PLUGIN_DIR . 'includes/engage.php';
require_once LMEG_PLUGIN_DIR . 'includes/instagram.php';
require_once LMEG_PLUGIN_DIR . 'includes/spotify.php';
require_once LMEG_PLUGIN_DIR . 'includes/ai.php';
require_once LMEG_PLUGIN_DIR . 'includes/updater.php';
require_once LMEG_PLUGIN_DIR . 'includes/admin.php';

/* ---------------------------------------------------------------------------
 * Activation / migration
 * ------------------------------------------------------------------------- */

register_activation_hook(__FILE__, 'lmeg_activate');
function lmeg_activate() {
    lmeg_create_tables();
    if (!get_option(LMEG_OPTION)) {
        add_option(LMEG_OPTION, lmeg_default_settings());
    }
    update_option('lmeg_db_version', LMEG_DB_VERSION);
    if (!wp_next_scheduled('lmeg_broadcast_tick')) {
        wp_schedule_event(time() + 60, 'lmeg_minute', 'lmeg_broadcast_tick');
    }
}

register_deactivation_hook(__FILE__, 'lmeg_deactivate');
function lmeg_deactivate() {
    $ts = wp_next_scheduled('lmeg_broadcast_tick');
    if ($ts) wp_unschedule_event($ts, 'lmeg_broadcast_tick');
    $ts = wp_next_scheduled('lmeg_updater_tick');
    if ($ts) wp_unschedule_event($ts, 'lmeg_updater_tick');
}

add_action('plugins_loaded', 'lmeg_maybe_migrate');
function lmeg_maybe_migrate() {
    $current = get_option('lmeg_db_version', '0');
    if (!version_compare($current, LMEG_DB_VERSION, '<')) {
        return;
    }

    global $wpdb;

    // v1 → v2: subscribers table relaxed (nullable email, dropped old UNIQUE).
    if (version_compare($current, '2.0.0', '<')) {
        $wpdb->suppress_errors(true);
        $wpdb->query("ALTER TABLE {$wpdb->prefix}" . LMEG_TABLE . " DROP INDEX email");
        $wpdb->query("ALTER TABLE {$wpdb->prefix}" . LMEG_TABLE . " MODIFY email VARCHAR(190) DEFAULT NULL");
        $wpdb->suppress_errors(false);
    }

    // Always (re)run dbDelta to pick up any new columns.
    lmeg_create_tables();

    // v1 → v2: backfill contact_type.
    if (version_compare($current, '2.0.0', '<')) {
        $wpdb->query("UPDATE {$wpdb->prefix}" . LMEG_TABLE . " SET contact_type = 'email' WHERE contact_type IS NULL OR contact_type = ''");
    }

    // v2.0 → v2.1: stamp existing log rows with their broadcast's channel
    // (was always 'email' or 'sms' before — auto routing didn't exist yet).
    if (version_compare($current, '2.1.0', '<')) {
        $bcast_tbl = $wpdb->prefix . 'lmeg_broadcasts';
        $log_tbl   = $wpdb->prefix . 'lmeg_broadcast_log';
        $wpdb->query("UPDATE $log_tbl l JOIN $bcast_tbl b ON b.id = l.broadcast_id SET l.channel = b.channel WHERE l.channel = 'email' AND b.channel IN ('email','sms')");
    }

    // v2.28: Mailgun removed entirely — Brevo is the only email provider.
    // Scrub the dead settings keys so nothing can route to Mailgun again.
    if (version_compare($current, '2.46.0', '<')) {
        $opts = get_option(LMEG_OPTION, []);
        if (is_array($opts)) {
            unset($opts['email_provider'], $opts['mailgun_api_key'], $opts['mailgun_domain'],
                  $opts['mailgun_region'], $opts['mailgun_from_email'], $opts['mailgun_from_name']);
            update_option(LMEG_OPTION, $opts);
        }
    }

    // v2.3 → v2.4: backfill auto-tags for everyone already in the table.
    // Cheap on small lists; on huge lists the activation may pause briefly.
    if (version_compare($current, '2.4.0', '<')) {
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE);
        if ($rows) {
            foreach ($rows as $r) {
                lmeg_apply_auto_tags($r);
            }
        }
    }

    update_option('lmeg_db_version', LMEG_DB_VERSION);
}

function lmeg_create_tables() {
    global $wpdb;
    $charset    = $wpdb->get_charset_collate();
    $subs       = $wpdb->prefix . LMEG_TABLE;
    $broadcasts = $wpdb->prefix . 'lmeg_broadcasts';
    $log        = $wpdb->prefix . 'lmeg_broadcast_log';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta("CREATE TABLE $subs (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        contact_type VARCHAR(10) NOT NULL DEFAULT 'email',
        email VARCHAR(190) DEFAULT NULL,
        phone VARCHAR(32) DEFAULT NULL,
        country VARCHAR(2) DEFAULT NULL,
        street VARCHAR(190) DEFAULT NULL,
        city VARCHAR(120) DEFAULT NULL,
        region VARCHAR(120) DEFAULT NULL,
        postal_code VARCHAR(20) DEFAULT NULL,
        post_id BIGINT(20) UNSIGNED DEFAULT NULL,
        ip VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        referrer VARCHAR(255) DEFAULT NULL,
        unsubscribed_at DATETIME DEFAULT NULL,
        welcome_sent_at DATETIME DEFAULT NULL,
        member_tier_id BIGINT(20) UNSIGNED DEFAULT NULL,
        member_status VARCHAR(20) NOT NULL DEFAULT 'free',
        stripe_customer_id VARCHAR(64) DEFAULT NULL,
        stripe_subscription_id VARCHAR(64) DEFAULT NULL,
        member_expires_at DATETIME DEFAULT NULL,
        billing_interval VARCHAR(10) DEFAULT NULL,
        referral_code VARCHAR(12) DEFAULT NULL,
        referred_by BIGINT(20) UNSIGNED DEFAULT NULL,
        first_name VARCHAR(80) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        member_revenue_cents BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_email (email),
        UNIQUE KEY uniq_phone (phone),
        UNIQUE KEY uniq_refcode (referral_code),
        KEY idx_unsub (unsubscribed_at),
        KEY idx_member (member_tier_id, member_status),
        KEY idx_stripe_sub (stripe_subscription_id),
        KEY idx_stripe_cust (stripe_customer_id),
        KEY idx_referred_by (referred_by)
    ) $charset;");

    dbDelta("CREATE TABLE $broadcasts (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        channel VARCHAR(10) NOT NULL DEFAULT 'auto',
        subject VARCHAR(255) DEFAULT NULL,
        body LONGTEXT DEFAULT NULL,
        body_sms LONGTEXT DEFAULT NULL,
        country_filter VARCHAR(2) DEFAULT NULL,
        tag_filter TEXT DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'queued',
        total INT UNSIGNED NOT NULL DEFAULT 0,
        sent INT UNSIGNED NOT NULL DEFAULT 0,
        failed INT UNSIGNED NOT NULL DEFAULT 0,
        scheduled_for DATETIME DEFAULT NULL,
        created_by BIGINT(20) UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL,
        completed_at DATETIME DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY idx_scheduled (scheduled_for, status)
    ) $charset;");

    dbDelta("CREATE TABLE $log (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        broadcast_id BIGINT(20) UNSIGNED NOT NULL,
        subscriber_id BIGINT(20) UNSIGNED NOT NULL,
        channel VARCHAR(10) NOT NULL DEFAULT 'email',
        recipient VARCHAR(190) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        error TEXT DEFAULT NULL,
        sent_at DATETIME DEFAULT NULL,
        opened_at DATETIME DEFAULT NULL,
        first_clicked_at DATETIME DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY idx_broadcast (broadcast_id),
        KEY idx_status (status)
    ) $charset;");

    $events   = $wpdb->prefix . 'lmeg_broadcast_events';
    dbDelta("CREATE TABLE $events (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        broadcast_id BIGINT(20) UNSIGNED NOT NULL,
        subscriber_id BIGINT(20) UNSIGNED NOT NULL,
        event_type VARCHAR(10) NOT NULL,
        source VARCHAR(16) NOT NULL DEFAULT 'broadcast',
        source_ref BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        url VARCHAR(500) DEFAULT NULL,
        ip VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_bcast_type (broadcast_id, event_type),
        KEY idx_source (source, source_ref, event_type),
        KEY idx_sub (subscriber_id)
    ) $charset;");

    $tags     = $wpdb->prefix . 'lmeg_tags';
    $subtags  = $wpdb->prefix . 'lmeg_subscriber_tags';

    dbDelta("CREATE TABLE $tags (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(64) NOT NULL,
        name VARCHAR(120) NOT NULL,
        color VARCHAR(7) NOT NULL DEFAULT '#6b7280',
        is_auto TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_slug (slug)
    ) $charset;");

    dbDelta("CREATE TABLE $subtags (
        subscriber_id BIGINT(20) UNSIGNED NOT NULL,
        tag_id BIGINT(20) UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (subscriber_id, tag_id),
        KEY idx_tag (tag_id)
    ) $charset;");

    $segments  = $wpdb->prefix . 'lmeg_segments';
    $templates = $wpdb->prefix . 'lmeg_templates';
    $seqs      = $wpdb->prefix . 'lmeg_sequences';
    $seqsteps  = $wpdb->prefix . 'lmeg_sequence_steps';
    $seqenr    = $wpdb->prefix . 'lmeg_sequence_enrollments';

    dbDelta("CREATE TABLE $segments (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(160) NOT NULL,
        tag_ids TEXT NOT NULL,
        match_mode VARCHAR(4) NOT NULL DEFAULT 'any',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id)
    ) $charset;");

    dbDelta("CREATE TABLE $templates (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(160) NOT NULL,
        subject VARCHAR(255) DEFAULT NULL,
        body_email LONGTEXT DEFAULT NULL,
        body_sms LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id)
    ) $charset;");

    dbDelta("CREATE TABLE $seqs (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(160) NOT NULL,
        trigger_tag_id BIGINT(20) UNSIGNED DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_trigger (trigger_tag_id)
    ) $charset;");

    dbDelta("CREATE TABLE $seqsteps (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        sequence_id BIGINT(20) UNSIGNED NOT NULL,
        position INT UNSIGNED NOT NULL DEFAULT 1,
        delay_days INT UNSIGNED NOT NULL DEFAULT 0,
        subject VARCHAR(255) DEFAULT NULL,
        body_email LONGTEXT DEFAULT NULL,
        body_sms LONGTEXT DEFAULT NULL,
        sends BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY idx_seq_pos (sequence_id, position)
    ) $charset;");

    dbDelta("CREATE TABLE $seqenr (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        subscriber_id BIGINT(20) UNSIGNED NOT NULL,
        sequence_id BIGINT(20) UNSIGNED NOT NULL,
        current_position INT UNSIGNED NOT NULL DEFAULT 1,
        next_send_at DATETIME DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        enrolled_at DATETIME NOT NULL,
        completed_at DATETIME DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_sub_seq (subscriber_id, sequence_id),
        KEY idx_next (status, next_send_at)
    ) $charset;");

    $tiers    = $wpdb->prefix . 'lmeg_tiers';
    $magic    = $wpdb->prefix . 'lmeg_magic_links';
    $stevents = $wpdb->prefix . 'lmeg_stripe_events';

    dbDelta("CREATE TABLE $tiers (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(64) NOT NULL,
        name VARCHAR(120) NOT NULL,
        description TEXT DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        currency VARCHAR(3) NOT NULL DEFAULT 'USD',
        price_monthly INT UNSIGNED DEFAULT NULL,
        price_annual INT UNSIGNED DEFAULT NULL,
        stripe_price_monthly VARCHAR(64) DEFAULT NULL,
        stripe_price_annual VARCHAR(64) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_slug (slug)
    ) $charset;");

    dbDelta("CREATE TABLE $magic (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        subscriber_id BIGINT(20) UNSIGNED NOT NULL,
        token_hash VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_token (token_hash),
        KEY idx_sub (subscriber_id)
    ) $charset;");

    dbDelta("CREATE TABLE $stevents (
        event_id VARCHAR(64) NOT NULL,
        event_type VARCHAR(64) NOT NULL,
        subscriber_id BIGINT(20) UNSIGNED DEFAULT NULL,
        processed_at DATETIME NOT NULL,
        PRIMARY KEY  (event_id)
    ) $charset;");

    $sgrants = $wpdb->prefix . 'lmeg_soft_grants';
    dbDelta("CREATE TABLE $sgrants (
        subscriber_id BIGINT(20) UNSIGNED NOT NULL,
        post_id BIGINT(20) UNSIGNED NOT NULL,
        granted_at DATETIME NOT NULL,
        PRIMARY KEY  (subscriber_id, post_id),
        KEY idx_post (post_id)
    ) $charset;");

    $smart = $wpdb->prefix . 'lmeg_smartlinks';
    dbDelta("CREATE TABLE $smart (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(64) NOT NULL,
        target_url TEXT NOT NULL,
        clicks BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        last_clicked_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_slug (slug)
    ) $charset;");

    $drops = $wpdb->prefix . 'lmeg_drops';
    dbDelta("CREATE TABLE $drops (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(200) NOT NULL,
        slug VARCHAR(80) NOT NULL,
        cover_url TEXT,
        description TEXT,
        release_at DATETIME DEFAULT NULL,
        links LONGTEXT,
        notify_tag_id BIGINT(20) UNSIGNED DEFAULT NULL,
        audience VARCHAR(10) NOT NULL DEFAULT 'notify',
        email_subject VARCHAR(200) DEFAULT NULL,
        email_body LONGTEXT,
        sms_body TEXT,
        status VARCHAR(12) NOT NULL DEFAULT 'draft',
        broadcast_id BIGINT(20) UNSIGNED DEFAULT NULL,
        broadcast_sent TINYINT(1) NOT NULL DEFAULT 0,
        notify_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_slug (slug),
        KEY idx_status (status),
        KEY idx_release (release_at)
    ) $charset;");

    $sps = $wpdb->prefix . 'lmeg_spotify_snapshots';
    dbDelta("CREATE TABLE $sps (
        snap_date DATE NOT NULL,
        followers BIGINT(20) UNSIGNED DEFAULT NULL,
        popularity INT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (snap_date)
    ) $charset;");

    $igr = $wpdb->prefix . 'lmeg_ig_rules';
    $igm = $wpdb->prefix . 'lmeg_ig_messages';
    dbDelta("CREATE TABLE $igr (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        keyword VARCHAR(80) NOT NULL,
        reply_text TEXT NOT NULL,
        hits BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id)
    ) $charset;");
    dbDelta("CREATE TABLE $igm (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        ig_user_id VARCHAR(64) NOT NULL,
        username VARCHAR(120) DEFAULT NULL,
        direction VARCHAR(4) NOT NULL DEFAULT 'in',
        text TEXT,
        rule_id BIGINT(20) UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_user (ig_user_id),
        KEY idx_created (created_at)
    ) $charset;");

    $tour = $wpdb->prefix . 'lmeg_tour_dates';
    dbDelta("CREATE TABLE $tour (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        show_date DATE NOT NULL,
        venue VARCHAR(190) NOT NULL,
        city VARCHAR(120) NOT NULL,
        region VARCHAR(120) DEFAULT NULL,
        ticket_url TEXT,
        presale_url TEXT,
        presale_members_only TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(16) NOT NULL DEFAULT 'onsale',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_date (show_date)
    ) $charset;");

    $surveys = $wpdb->prefix . 'lmeg_surveys';
    $votes   = $wpdb->prefix . 'lmeg_survey_votes';
    dbDelta("CREATE TABLE $surveys (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        question VARCHAR(255) NOT NULL,
        options_json TEXT NOT NULL,
        is_open TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id)
    ) $charset;");
    dbDelta("CREATE TABLE $votes (
        survey_id BIGINT(20) UNSIGNED NOT NULL,
        subscriber_id BIGINT(20) UNSIGNED NOT NULL,
        option_idx INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (survey_id, subscriber_id),
        KEY idx_survey (survey_id)
    ) $charset;");

    $contests = $wpdb->prefix . 'lmeg_contests';
    $entries  = $wpdb->prefix . 'lmeg_contest_entries';
    dbDelta("CREATE TABLE $contests (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(190) NOT NULL,
        description TEXT,
        ends_at DATETIME DEFAULT NULL,
        winner_subscriber_id BIGINT(20) UNSIGNED DEFAULT NULL,
        is_open TINYINT(1) NOT NULL DEFAULT 1,
        referral_bonus TINYINT(1) NOT NULL DEFAULT 1,
        page_url TEXT,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id)
    ) $charset;");
    dbDelta("CREATE TABLE $entries (
        contest_id BIGINT(20) UNSIGNED NOT NULL,
        subscriber_id BIGINT(20) UNSIGNED NOT NULL,
        entries INT UNSIGNED NOT NULL DEFAULT 1,
        entered_at DATETIME NOT NULL,
        PRIMARY KEY  (contest_id, subscriber_id),
        KEY idx_contest (contest_id)
    ) $charset;");

    $orders = $wpdb->prefix . 'lmeg_shop_orders';
    dbDelta("CREATE TABLE $orders (
        shopify_order_id BIGINT(20) UNSIGNED NOT NULL,
        order_number VARCHAR(32) DEFAULT NULL,
        email VARCHAR(190) DEFAULT NULL,
        subscriber_id BIGINT(20) UNSIGNED DEFAULT NULL,
        broadcast_id BIGINT(20) UNSIGNED DEFAULT NULL,
        attribution VARCHAR(16) NOT NULL DEFAULT 'none',
        total_cents INT UNSIGNED NOT NULL DEFAULT 0,
        currency VARCHAR(3) NOT NULL DEFAULT 'USD',
        ordered_at DATETIME DEFAULT NULL,
        synced_at DATETIME NOT NULL,
        PRIMARY KEY  (shopify_order_id),
        KEY idx_broadcast (broadcast_id),
        KEY idx_subscriber (subscriber_id),
        KEY idx_ordered (ordered_at)
    ) $charset;");

    $aband = $wpdb->prefix . 'lmeg_shop_abandoned';
    dbDelta("CREATE TABLE $aband (
        checkout_id BIGINT(20) UNSIGNED NOT NULL,
        token VARCHAR(64) DEFAULT NULL,
        email VARCHAR(190) DEFAULT NULL,
        subscriber_id BIGINT(20) UNSIGNED DEFAULT NULL,
        recovery_url TEXT,
        total_cents INT UNSIGNED NOT NULL DEFAULT 0,
        currency VARCHAR(3) NOT NULL DEFAULT 'USD',
        checkout_at DATETIME DEFAULT NULL,
        recovered TINYINT(1) NOT NULL DEFAULT 0,
        tagged TINYINT(1) NOT NULL DEFAULT 0,
        synced_at DATETIME NOT NULL,
        PRIMARY KEY  (checkout_id),
        KEY idx_subscriber (subscriber_id),
        KEY idx_email (email),
        KEY idx_recovered (recovered)
    ) $charset;");
}

/* ---------------------------------------------------------------------------
 * Settings
 * ------------------------------------------------------------------------- */

function lmeg_default_settings() {
    return [
        // Form copy
        'cookie_days'         => 30,
        'form_heading'        => 'Read the rest',
        'form_message'        => 'Enter your email or phone to unlock this post and future ones.',
        'button_text'         => 'Unlock',
        'consent_text'        => 'I agree to receive occasional emails or texts. Unsubscribe anytime.',
        'success_message'     => 'Thanks! Loading the post…',
        // Scope
        'gate_single_posts'   => 1,
        'gate_blog_index'     => 0,
        // Address fields
        'enable_address'      => 0,
        'address_required'    => 0,
        'address_message'     => 'Optional: where should we send mail?',
        // Brevo — the email provider
        'brevo_api_key'       => '',
        'brevo_from_email'    => '',
        'brevo_from_name'     => '',
        // Twilio
        'twilio_account_sid'  => '',
        'twilio_auth_token'   => '',
        'twilio_from_number'  => '',
        // Unsubscribe + feed teaser copy
        'unsub_footer_text'   => "Don't want these? Unsubscribe here: {unsub_url}",
        'feed_teaser_text'    => 'This post is for subscribers. Visit the site to read.',
        // Welcome email
        'welcome_enabled'     => 0,
        'welcome_subject'     => 'Welcome to {site_name}',
        'welcome_body'        => "Hi {name},\n\nThanks for subscribing to {site_name}. You'll hear from us soon.\n\n— The team",
        // Tracking
        'tracking_opens'      => 1,
        'tracking_clicks'     => 1,
        // Stripe / paid membership
        'stripe_mode'              => 'test',
        'stripe_test_pk'           => '',
        'stripe_test_sk'           => '',
        'stripe_test_webhook_sec'  => '',
        'stripe_live_pk'           => '',
        'stripe_live_sk'           => '',
        'stripe_live_webhook_sec'  => '',
        'member_cookie_days'       => 30,
        'magic_link_ttl_hours'     => 24,
        'default_post_access'      => 'free', // 'public' | 'free' | 'paid'
        'upgrade_heading'          => 'Get full access',
        'upgrade_message'          => 'This post is for paid members. Pick a plan to keep reading.',
        'soft_paywall_heading'     => 'Support what you love',
        'soft_paywall_message'     => 'This post is free to read — or if you value the work, becoming a paid member keeps it going.',
        'paywall_heading'          => 'Unlock the Loonybin',
        'paywall_premium_label'    => 'Get premium access',
        'paywall_unlock_label'     => 'Unlock',
        'logo_url'                 => '',   // URL to logo image shown above the card
        'logo_max_width'           => 200,  // max width in px
        'signup_success_message'   => 'Thank you for joining the loonybin',
        'default_test_email'       => 'ian@portermedia.ca',
        // Branded email template
        'email_template_enabled'   => 1,
        'email_footer_note'        => "You're receiving this because you joined the loonybin.",
        // Spotify analytics (Client Credentials — app-level, no user login)
        'spotify_client_id'        => '',
        'spotify_client_secret'    => '',
        'spotify_artist_id'        => '',
        // AI assistant (Anthropic)
        'ai_api_key'               => '',
        'ai_model'                 => 'claude-haiku-4-5-20251001',
        // Instagram DM automation
        'ig_app_secret'            => '',
        'ig_page_token'            => '',
        'ig_account_id'            => '',
        'ig_verify_token'          => '',
        // Shopify shop connection (revenue attribution)
        'shopify_domain'           => '',   // e.g. loonymoonchildstore.myshopify.com
        'shopify_admin_token'      => '',   // Legacy static token (shpat_...) — optional
        'shopify_client_id'        => '',   // Dev-dashboard app Client ID (client credentials)
        'shopify_client_secret'    => '',   // Dev-dashboard app Client secret
        'attribution_window_days'  => 7,
        'utm_source'               => 'loonybin',
        // Front-end theming
        'color_primary'            => '#111111',
        'color_primary_text'       => '#ffffff',
        'color_accent'             => '#3b82f6',
        'color_border'             => '',   // blank = default translucent black
        'color_card_bg'            => '',   // blank = default white
        'color_card_text'          => '',   // blank = default #1a1a1a
        'color_page_bg'            => '',   // blank = theme's background shows through
        'signin_heading'           => 'Sign in',
        'signin_message'           => "Enter your email and we'll send you a sign-in link.",
        'magic_link_subject'       => 'Your sign-in link for {site_name}',
        'magic_link_body'          => "Hi,\n\nClick to sign in:\n\n{magic_link}\n\nExpires in 24 hours. If you didn't request this, ignore this email.",
    ];
}

/**
 * Setting key → candidate config names. Shared, cross-site credentials can
 * be defined once in wp-config.php (as constants) or a .env file (see
 * lmeg_load_env_file) instead of being re-entered in each site's admin.
 * Config values always WIN over the stored DB option. Per-artist values
 * (spotify_artist_id, shopify_domain, from-addresses…) are deliberately
 * NOT here — those stay in the admin.
 */
function lmeg_env_map() {
    return [
        'spotify_client_id'       => ['LMEG_SPOTIFY_CLIENT_ID', 'SPOTIFY_CLIENT_ID'],
        'spotify_client_secret'   => ['LMEG_SPOTIFY_CLIENT_SECRET', 'SPOTIFY_CLIENT_SECRET'],
        'ai_api_key'              => ['LMEG_AI_API_KEY', 'ANTHROPIC_API_KEY'],
        'ai_model'                => ['LMEG_AI_MODEL'],
        'brevo_api_key'           => ['LMEG_BREVO_API_KEY', 'BREVO_API_KEY'],
        'twilio_account_sid'      => ['LMEG_TWILIO_ACCOUNT_SID', 'TWILIO_ACCOUNT_SID'],
        'twilio_auth_token'       => ['LMEG_TWILIO_AUTH_TOKEN', 'TWILIO_AUTH_TOKEN'],
        'twilio_from_number'      => ['LMEG_TWILIO_FROM_NUMBER'],
        'stripe_test_sk'          => ['LMEG_STRIPE_TEST_SK'],
        'stripe_test_pk'          => ['LMEG_STRIPE_TEST_PK'],
        'stripe_test_webhook_sec' => ['LMEG_STRIPE_TEST_WEBHOOK_SEC'],
        'stripe_live_sk'          => ['LMEG_STRIPE_LIVE_SK'],
        'stripe_live_pk'          => ['LMEG_STRIPE_LIVE_PK'],
        'stripe_live_webhook_sec' => ['LMEG_STRIPE_LIVE_WEBHOOK_SEC'],
        'ig_app_secret'           => ['LMEG_IG_APP_SECRET'],
        'ig_page_token'           => ['LMEG_IG_PAGE_TOKEN'],
        'shopify_admin_token'     => ['LMEG_SHOPIFY_ADMIN_TOKEN'],
        'shopify_client_id'       => ['LMEG_SHOPIFY_CLIENT_ID'],
        'shopify_client_secret'   => ['LMEG_SHOPIFY_CLIENT_SECRET'],
    ];
}

/**
 * Resolve one config-backed value from constants or environment.
 * Returns null when nothing is set in config (so the DB value stands).
 */
function lmeg_env_value($setting_key) {
    $names = lmeg_env_map()[$setting_key] ?? [];
    foreach ($names as $name) {
        if (defined($name) && constant($name) !== '') return constant($name);
        $env = getenv($name);
        if ($env !== false && $env !== '') return $env;
    }
    return null;
}

/**
 * Which setting keys are currently being supplied by config (for the admin
 * UI to lock those fields).
 */
function lmeg_env_managed_keys() {
    $keys = [];
    foreach (array_keys(lmeg_env_map()) as $k) {
        if (lmeg_env_value($k) !== null) $keys[] = $k;
    }
    return $keys;
}

function lmeg_get_settings() {
    $opts = wp_parse_args(get_option(LMEG_OPTION, []), lmeg_default_settings());
    // Overlay config-backed credentials — constants/.env win over the DB.
    foreach (array_keys(lmeg_env_map()) as $key) {
        $v = lmeg_env_value($key);
        if ($v !== null) $opts[$key] = $v;
    }
    return $opts;
}

/* ---------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */

/**
 * Render an ISO 3166-1 alpha-2 country code as its flag emoji.
 */
function lmeg_flag_emoji($iso) {
    $iso = strtoupper(trim((string) $iso));
    if (!preg_match('/^[A-Z]{2}$/', $iso)) {
        return '';
    }
    $base = 0x1F1E6 - ord('A');
    if (!function_exists('mb_chr')) {
        return $iso; // PHP < 7.2 fallback
    }
    return mb_chr($base + ord($iso[0])) . mb_chr($base + ord($iso[1]));
}

function lmeg_countries() {
    static $list = null;
    if ($list === null) {
        $list = include LMEG_PLUGIN_DIR . 'includes/countries.php';
    }
    return $list;
}

function lmeg_country_by_iso($iso) {
    $iso = strtoupper((string) $iso);
    foreach (lmeg_countries() as $c) {
        if ($c[0] === $iso) return $c;
    }
    return null;
}

/* ---------------------------------------------------------------------------
 * Unsubscribe — HMAC-signed per-subscriber tokens
 * ------------------------------------------------------------------------- */

function lmeg_get_secret() {
    $key = get_option('lmeg_secret_key', '');
    if (!$key) {
        $key = wp_generate_password(64, true, true);
        update_option('lmeg_secret_key', $key, false);
    }
    return $key;
}

function lmeg_unsub_token($subscriber_id, $contact) {
    return substr(
        hash_hmac('sha256', $subscriber_id . '|' . strtolower((string) $contact), lmeg_get_secret()),
        0,
        32
    );
}

function lmeg_verify_unsub_token($subscriber_id, $contact, $token) {
    return hash_equals(lmeg_unsub_token($subscriber_id, $contact), (string) $token);
}

function lmeg_unsub_url($subscriber_id, $contact) {
    return add_query_arg([
        'lmeg_unsubscribe' => 1,
        'u' => $subscriber_id,
        't' => lmeg_unsub_token($subscriber_id, $contact),
    ], home_url('/'));
}

add_action('init', 'lmeg_maybe_handle_unsubscribe');
function lmeg_maybe_handle_unsubscribe() {
    if (empty($_GET['lmeg_unsubscribe'])) return;

    $id    = absint($_GET['u'] ?? 0);
    $token = sanitize_text_field($_GET['t'] ?? '');

    if (!$id || !$token) {
        wp_die('Invalid unsubscribe link.', 'Unsubscribe', ['response' => 400]);
    }

    global $wpdb;
    $table = $wpdb->prefix . LMEG_TABLE;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, email, phone FROM $table WHERE id = %d",
        $id
    ));
    if (!$row) {
        wp_die('Subscription not found.', 'Unsubscribe', ['response' => 404]);
    }

    $contact = $row->email ?: $row->phone ?: '';
    if (!lmeg_verify_unsub_token($row->id, $contact, $token)) {
        wp_die('Invalid unsubscribe link.', 'Unsubscribe', ['response' => 403]);
    }

    $wpdb->update($table, ['unsubscribed_at' => current_time('mysql')], ['id' => $row->id]);

    $masked = $contact;
    if ($row->email) {
        $parts = explode('@', $contact, 2);
        $masked = substr($parts[0], 0, 2) . '…@' . ($parts[1] ?? '');
    } elseif ($row->phone) {
        $masked = '…' . substr($contact, -4);
    }

    wp_die(
        '<h1 style="font-family:sans-serif">You\'re unsubscribed</h1>' .
        '<p style="font-family:sans-serif">We won\'t send anything else to <strong>' . esc_html($masked) . '</strong>.</p>' .
        '<p style="font-family:sans-serif"><a href="' . esc_url(home_url('/')) . '">← Back to ' . esc_html(get_bloginfo('name')) . '</a></p>',
        'Unsubscribed',
        ['response' => 200]
    );
}

/* ---------------------------------------------------------------------------
 * Open + click tracking
 * ------------------------------------------------------------------------- */

function lmeg_track_token($broadcast_id, $subscriber_id, $type, $url = '', $source = 'broadcast', $ref = 0) {
    // Click tokens bind the destination URL into the HMAC so the redirect
    // endpoint can't be repurposed as an open redirect (swapping ?u= for a
    // phishing URL invalidates the signature). Source + ref let welcome and
    // sequence-step emails be tracked distinctly from broadcasts.
    return substr(
        hash_hmac('sha256', $broadcast_id . '|' . $subscriber_id . '|' . $type . '|' . $url . '|' . $source . '|' . $ref, lmeg_get_secret()),
        0,
        16
    );
}

function lmeg_verify_track_token($broadcast_id, $subscriber_id, $type, $token, $url = '', $source = 'broadcast', $ref = 0) {
    return hash_equals(lmeg_track_token($broadcast_id, $subscriber_id, $type, $url, $source, $ref), (string) $token);
}

function lmeg_track_open_url($broadcast_id, $subscriber_id, $source = 'broadcast', $ref = 0) {
    return add_query_arg([
        'lmeg_track' => 'open',
        'b' => $broadcast_id,
        's' => $subscriber_id,
        'src' => $source,
        'ref' => (int) $ref,
        't' => lmeg_track_token($broadcast_id, $subscriber_id, 'open', '', $source, $ref),
    ], home_url('/'));
}

function lmeg_track_click_url($broadcast_id, $subscriber_id, $target_url, $source = 'broadcast', $ref = 0) {
    return add_query_arg([
        'lmeg_track' => 'click',
        'b' => $broadcast_id,
        's' => $subscriber_id,
        'src' => $source,
        'ref' => (int) $ref,
        't' => lmeg_track_token($broadcast_id, $subscriber_id, 'click', $target_url, $source, $ref),
        'u' => rawurlencode($target_url),
    ], home_url('/'));
}

add_action('init', 'lmeg_maybe_handle_track');
function lmeg_maybe_handle_track() {
    if (empty($_GET['lmeg_track'])) return;
    $type          = $_GET['lmeg_track'] === 'click' ? 'click' : 'open';
    $broadcast_id  = absint($_GET['b'] ?? 0);
    $subscriber_id = absint($_GET['s'] ?? 0);
    $token         = sanitize_text_field($_GET['t'] ?? '');
    $source        = in_array($_GET['src'] ?? 'broadcast', ['broadcast', 'welcome', 'sequence'], true) ? $_GET['src'] : 'broadcast';
    $ref           = absint($_GET['ref'] ?? 0);

    // Silently reject invalid, but always finish the request nicely so a
    // failed pixel doesn't leave a broken image in the email client.
    $click_url = $type === 'click' && isset($_GET['u']) ? rawurldecode((string) $_GET['u']) : '';
    // Non-broadcast sources have subscriber but broadcast_id 0.
    $valid = $subscriber_id
        && lmeg_verify_track_token($broadcast_id, $subscriber_id, $type, $token, $type === 'click' ? $click_url : '', $source, $ref);

    // Replay cap — a leaked pixel/click URL can't be hammered into event bloat.
    if ($valid && !lmeg_track_event_allowed($broadcast_id . '-' . $source . '-' . $ref, $subscriber_id, $type)) {
        $valid = false;
    }

    if ($valid) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'lmeg_broadcast_events', [
            'broadcast_id'  => $broadcast_id,
            'subscriber_id' => $subscriber_id,
            'event_type'    => $type,
            'source'        => $source,
            'source_ref'    => $ref,
            'url'           => $type === 'click' ? substr((string) ($_GET['u'] ?? ''), 0, 500) : null,
            'ip'            => substr($_SERVER['REMOTE_ADDR']    ?? '', 0, 45),
            'user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'created_at'    => current_time('mysql'),
        ]);

        // Only broadcasts have a per-recipient log row to stamp.
        if ($source === 'broadcast' && $broadcast_id) {
            $log_tbl = $wpdb->prefix . 'lmeg_broadcast_log';
            $col     = $type === 'click' ? 'first_clicked_at' : 'opened_at';
            $wpdb->query($wpdb->prepare(
                "UPDATE $log_tbl SET $col = COALESCE($col, %s) WHERE broadcast_id = %d AND subscriber_id = %d",
                current_time('mysql'), $broadcast_id, $subscriber_id
            ));
        }
    }

    if ($type === 'click') {
        // Only follow the destination when the token (which binds the URL)
        // verified — otherwise this endpoint would be an open redirect.
        // wp_redirect (not _safe_) is correct here: destinations are external
        // (Spotify, Shopify…) and the HMAC already guarantees integrity.
        if ($valid && $click_url) {
            wp_redirect(esc_url_raw($click_url), 302);
        } else {
            wp_safe_redirect(home_url('/'));
        }
        exit;
    }

    // Serve a transparent 1x1 GIF for opens.
    nocache_headers();
    header('Content-Type: image/gif');
    header('Content-Length: 43');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

/* ---------------------------------------------------------------------------
 * Merge tags — substitute {email}, {phone}, {city}, {country}, etc.
 * ------------------------------------------------------------------------- */

function lmeg_merge_tag_map($sub) {
    if (!is_object($sub)) $sub = (object) $sub;
    return [
        '{email}'       => (string) ($sub->email ?? ''),
        '{phone}'       => (string) ($sub->phone ?? ''),
        '{street}'      => (string) ($sub->street ?? ''),
        '{city}'        => (string) ($sub->city ?? ''),
        '{region}'      => (string) ($sub->region ?? ''),
        '{postal_code}' => (string) ($sub->postal_code ?? ''),
        '{country}'     => (string) ($sub->country ?? ''),
        '{site_name}'   => (string) get_bloginfo('name'),
        '{site_url}'    => (string) home_url('/'),
        // Prefer the stored first name; fall back to the email local part.
        '{name}'        => !empty($sub->first_name)
            ? (string) $sub->first_name
            : (!empty($sub->email)
                ? (string) explode('@', $sub->email)[0]
                : (string) ($sub->phone ?? 'friend')),
        '{unique_code}'   => (!empty($sub->id) && function_exists('lmeg_get_fan_code'))
            ? (string) lmeg_get_fan_code($sub->id) : '',
        '{referral_link}' => (!empty($sub->id) && function_exists('lmeg_referral_url'))
            ? (string) lmeg_referral_url($sub->id) : (string) home_url('/'),
        // Abandoned-cart recovery link — the fan's most recent un-recovered
        // Shopify checkout. Empty (falls back to store) if none pending.
        '{cart_url}'      => (!empty($sub->id) && function_exists('lmeg_fan_cart_url'))
            ? (string) lmeg_fan_cart_url($sub->id) : '',
        // Personalized one-tap contest entry link (newest open contest). Signs
        // the fan in and enters them with a single click.
        '{contest_link}'  => (!empty($sub->id) && function_exists('lmeg_contest_link_for'))
            ? (string) lmeg_contest_link_for($sub->id) : (string) home_url('/'),
    ];
}

function lmeg_render_merge_tags($text, $sub) {
    if ($text === null || $text === '') return $text;
    // Parameterized {contest_link:ID} — target a SPECIFIC contest (bare
    // {contest_link} still resolves to the newest open one).
    if (strpos($text, '{contest_link:') !== false && function_exists('lmeg_contest_enter_url') && !empty($sub->id)) {
        $text = preg_replace_callback('/\{contest_link:(\d+)\}/', function ($m) use ($sub) {
            return lmeg_contest_enter_url((int) $m[1], (int) $sub->id);
        }, (string) $text);
    }
    $map = lmeg_merge_tag_map($sub);
    return str_replace(array_keys($map), array_values($map), (string) $text);
}

/* ---------------------------------------------------------------------------
 * Welcome email — auto-send after a fresh signup with an email
 * ------------------------------------------------------------------------- */

add_action('lmeg_subscriber_created', 'lmeg_maybe_send_welcome', 10, 1);
function lmeg_maybe_send_welcome($subscriber_id) {
    $s = lmeg_get_settings();
    if (empty($s['welcome_enabled'])) return;

    global $wpdb;
    $sub = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d",
        $subscriber_id
    ));
    if (!$sub || !$sub->email || $sub->welcome_sent_at) return;

    $subject = lmeg_render_merge_tags($s['welcome_subject'] ?: 'Welcome', $sub);
    $body    = lmeg_render_merge_tags($s['welcome_body']    ?: '', $sub);

    if (function_exists('lmeg_email_send')) {
        list($text, $html) = lmeg_build_email_with_footer($body, lmeg_unsub_url($sub->id, $sub->email));
        // Track opens/clicks for the welcome email (source='welcome').
        if (function_exists('lmeg_apply_tracking')) {
            $html = lmeg_apply_tracking($html, 0, (int) $sub->id, 'welcome', 0);
        }
        $result = lmeg_email_send($sub->email, $subject, $text, $html);
        if (!is_wp_error($result)) {
            $wpdb->update($wpdb->prefix . LMEG_TABLE,
                ['welcome_sent_at' => current_time('mysql')],
                ['id' => $sub->id]
            );
        }
    }
}

/* ---------------------------------------------------------------------------
 * RSS feed + REST API gating
 * ------------------------------------------------------------------------- */

function lmeg_feed_teaser() {
    $s   = lmeg_get_settings();
    $msg = !empty($s['feed_teaser_text']) ? $s['feed_teaser_text'] : 'This post is for subscribers.';
    $url = get_permalink();
    return '<p>' . esc_html($msg) . ' <a href="' . esc_url($url) . '">Read on the site →</a></p>';
}

add_filter('the_content_feed', 'lmeg_filter_feed_content', 999);
add_filter('the_excerpt_rss',  'lmeg_filter_feed_excerpt', 999);
function lmeg_filter_feed_content($content) {
    if (!is_feed()) return $content;
    $s = lmeg_get_settings();
    if (empty($s['gate_single_posts'])) return $content;
    return lmeg_feed_teaser();
}
function lmeg_filter_feed_excerpt($excerpt) {
    if (!is_feed()) return $excerpt;
    $s = lmeg_get_settings();
    if (empty($s['gate_single_posts'])) return $excerpt;
    return wp_strip_all_tags(lmeg_feed_teaser());
}

add_filter('rest_prepare_post', 'lmeg_filter_rest_post', 10, 2);
function lmeg_filter_rest_post($response, $post) {
    if (current_user_can('edit_posts')) return $response;
    $s = lmeg_get_settings();
    if (empty($s['gate_single_posts'])) return $response;

    $data = $response->get_data();
    if (isset($data['content'])) {
        $teaser = lmeg_feed_teaser();
        $data['content']['rendered']  = $teaser;
        $data['content']['protected'] = true;
    }
    if (isset($data['excerpt'])) {
        $data['excerpt']['rendered'] = wp_strip_all_tags(lmeg_feed_teaser());
    }
    $response->set_data($data);
    return $response;
}

/* ---------------------------------------------------------------------------
 * Front-end gate
 * ------------------------------------------------------------------------- */

/**
 * Decide what to do on the current request:
 *   'ok'            → render post normally (public / valid member)
 *   'needs_signup'  → render the email opt-in gate form
 *   'needs_upgrade' → render the upgrade CTA (member exists, tier not paid)
 *   false           → not a gatable page
 */
function lmeg_gate_decision() {
    $s = lmeg_get_settings();
    if (current_user_can('edit_posts')) return 'ok';

    $on_single = is_singular('post');
    $on_index  = is_home() || is_post_type_archive('post');

    if ($on_single && !empty($s['gate_single_posts'])) {
        return lmeg_check_post_access(get_the_ID());
    }
    if ($on_index && !empty($s['gate_blog_index'])) {
        // Index gating is signup-only for now; member level not checked here.
        return lmeg_is_unlocked_or_member() ? 'ok' : 'needs_signup';
    }
    return false;
}

function lmeg_is_unlocked_or_member() {
    if (!empty($_COOKIE[LMEG_COOKIE])) return true;
    if (lmeg_current_member())          return true;
    return false;
}

// Legacy alias — some callers still use these.
function lmeg_should_gate() {
    $d = lmeg_gate_decision();
    return $d !== false && $d !== 'ok';
}
function lmeg_is_unlocked() {
    return lmeg_is_unlocked_or_member();
}

add_filter('template_include', 'lmeg_template_include', 100);
function lmeg_template_include($template) {
    $d = lmeg_gate_decision();
    if ($d === false || $d === 'ok') return $template;
    return LMEG_PLUGIN_DIR . 'templates/gate.php';
}

// Add a body class when the gate template is active, so `body.lmeg-page-bg`
// can pick up the page-background color setting.
add_filter('body_class', 'lmeg_body_class');
function lmeg_body_class($classes) {
    $d = lmeg_gate_decision();
    if ($d && $d !== 'ok') {
        $classes[] = 'lmeg-page-bg';
    }
    return $classes;
}

add_filter('the_content', 'lmeg_filter_content', 999);
function lmeg_filter_content($content) {
    $d = lmeg_gate_decision();
    if ($d === false || $d === 'ok') return $content;

    $post_id = get_the_ID();
    $access  = $post_id ? lmeg_post_access_level($post_id) : 'free';

    // Paid / soft-paid posts use the unified tier-first paywall regardless
    // of whether the reader is a non-member or a free member.
    if ($access === 'paid' || $access === 'soft-paid' || (is_array($access) && $access[0] === 'tier')) {
        return lmeg_paywall_html($post_id);
    }

    // Free posts fall through to the classic email opt-in form.
    if ($d === 'needs_upgrade') return lmeg_upgrade_html($post_id);
    return lmeg_render_form();
}

/**
 * Render the opt-in form. Used by templates/gate.php and the_content filter.
 */
function lmeg_render_form() {
    $s         = lmeg_get_settings();
    $post_id   = get_the_ID() ?: 0;
    $nonce     = wp_create_nonce('lmeg_submit');
    $action    = esc_url(admin_url('admin-post.php'));
    $countries = lmeg_countries();
    $field     = 'lmeg-' . $post_id;

    ob_start();
    ?>
    <div class="lmeg-gate" role="region" aria-label="Unlock content">
        <div class="lmeg-gate-inner">
            <h3 class="lmeg-heading"><?php echo esc_html($s['form_heading']); ?></h3>
            <p class="lmeg-message"><?php echo esc_html($s['form_message']); ?></p>

            <form class="lmeg-form" method="post" action="<?php echo $action; ?>" novalidate>
                <input type="hidden" name="action"        value="lmeg_submit" />
                <input type="hidden" name="_wpnonce"      value="<?php echo esc_attr($nonce); ?>" />
                <input type="hidden" name="post_id"       value="<?php echo esc_attr($post_id); ?>" />
                <input type="hidden" name="redirect"      value="<?php echo esc_url(get_permalink($post_id) ?: home_url('/')); ?>" />
                <input type="hidden" name="contact_type"  value="email" />
                <input type="hidden" name="phone_country_iso" value="US" />

                <div class="lmeg-tabs" role="tablist" aria-label="Contact method">
                    <button type="button" class="lmeg-tab is-active" role="tab" aria-selected="true"  data-channel="email">Email</button>
                    <button type="button" class="lmeg-tab"           role="tab" aria-selected="false" data-channel="phone">Phone</button>
                </div>

                <div class="lmeg-hp-wrap" aria-hidden="true">
                    <label>Leave this empty<input type="text" name="lmeg_hp" value="" tabindex="-1" autocomplete="off" /></label>
                </div>

                <!-- Email -->
                <div class="lmeg-field lmeg-field-email">
                    <label class="lmeg-label" for="<?php echo esc_attr($field); ?>-email">Email</label>
                    <input type="email" id="<?php echo esc_attr($field); ?>-email" name="email" required autocomplete="email"
                           placeholder="you@example.com" class="lmeg-input" />
                </div>

                <!-- Phone -->
                <div class="lmeg-field lmeg-field-phone" hidden>
                    <label class="lmeg-label" for="<?php echo esc_attr($field); ?>-phone">Phone</label>
                    <div class="lmeg-phone-row">
                        <select name="phone_country" class="lmeg-select" aria-label="Country">
                            <?php foreach ($countries as $c) :
                                $selected = ($c[0] === 'US') ? ' selected' : '';
                            ?>
                                <option value="<?php echo esc_attr($c[0]); ?>" data-dial="<?php echo esc_attr($c[2]); ?>"<?php echo $selected; ?>>
                                    <?php echo esc_html(lmeg_flag_emoji($c[0]) . ' ' . $c[1] . ' (+' . $c[2] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="lmeg-dial" aria-hidden="true">+1</span>
                        <input type="tel" id="<?php echo esc_attr($field); ?>-phone" name="phone" inputmode="tel"
                               placeholder="555 123 4567" class="lmeg-input" autocomplete="tel-national" />
                    </div>
                </div>

                <?php if (!empty($s['enable_address'])) : ?>
                    <button type="button" class="lmeg-address-toggle" aria-expanded="false">Add address info</button>
                    <div class="lmeg-address-block" hidden>
                        <?php if (!empty($s['address_message'])) : ?>
                            <p class="lmeg-address-message"><?php echo esc_html($s['address_message']); ?></p>
                        <?php endif; ?>
                        <?php $req = !empty($s['address_required']) ? 'required' : ''; ?>
                        <div class="lmeg-field lmeg-field-street">
                            <label class="lmeg-label">Street</label>
                            <input type="text" name="street" class="lmeg-input" autocomplete="street-address" <?php echo $req; ?> />
                        </div>
                        <div class="lmeg-field">
                            <label class="lmeg-label">City</label>
                            <input type="text" name="city" class="lmeg-input" autocomplete="address-level2" <?php echo $req; ?> />
                        </div>
                        <div class="lmeg-field">
                            <label class="lmeg-label">Province / State</label>
                            <input type="text" name="region" class="lmeg-input" autocomplete="address-level1" <?php echo $req; ?> />
                        </div>
                        <div class="lmeg-field">
                            <label class="lmeg-label">Postal / ZIP</label>
                            <input type="text" name="postal_code" class="lmeg-input" autocomplete="postal-code" <?php echo $req; ?> />
                        </div>
                        <div class="lmeg-field lmeg-field-country-addr">
                            <label class="lmeg-label">Country</label>
                            <select name="address_country" class="lmeg-select" <?php echo $req; ?>>
                                <option value="">Select…</option>
                                <?php foreach ($countries as $c) : ?>
                                    <option value="<?php echo esc_attr($c[0]); ?>"><?php echo esc_html(lmeg_flag_emoji($c[0]) . ' ' . $c[1]); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>

                <button type="submit" class="lmeg-button"><?php echo esc_html($s['button_text']); ?></button>
                <p class="lmeg-consent"><?php echo esc_html($s['consent_text']); ?></p>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/* ---------------------------------------------------------------------------
 * Form handler
 * ------------------------------------------------------------------------- */

add_action('admin_post_nopriv_lmeg_submit', 'lmeg_handle_submit');
add_action('admin_post_lmeg_submit',         'lmeg_handle_submit');
function lmeg_handle_submit() {
    $posted_email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';

    // Nonce: normally required, BUT full-page caching (very common on WP hosts)
    // serves a single cached nonce to every visitor, and nonces expire in ~24h.
    // Once stale, every genuine signup would 403 here and vanish with no record
    // — a prime cause of "certain emails never got added". A public opt-in is
    // not a CSRF-sensitive action, so on a bad/missing nonce we log it and keep
    // going; the honeypot + per-IP rate limit remain the spam guards. Watch the
    // reject log (Subscribers page) for 'bad_nonce' to confirm caching is at play.
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lmeg_submit')) {
        if (function_exists('lmeg_log_signup_reject')) lmeg_log_signup_reject('bad_nonce', $posted_email);
    }

    $redirect = isset($_POST['redirect']) ? esc_url_raw(wp_unslash($_POST['redirect'])) : home_url('/');
    $after    = isset($_POST['lmeg_after']) ? sanitize_text_field(wp_unslash($_POST['lmeg_after'])) : '';

    // Honeypot — silently accept and redirect. (Logged so we can tell a real
    // bot from a browser/password-manager autofilling the hidden field.)
    if (!empty($_POST['lmeg_hp'])) {
        if (function_exists('lmeg_log_signup_reject')) lmeg_log_signup_reject('honeypot', $posted_email);
        lmeg_set_cookie();
        wp_safe_redirect($redirect);
        exit;
    }

    // Rate limit per network — stops scripted signup floods (and the Brevo
    // quota burn from welcome emails they'd trigger).
    lmeg_guard_signup($posted_email);

    // Fast path: existing member clicking a tier button. The paywall
    // doesn't render an email input for signed-in members, so there's
    // nothing to validate — go straight to Stripe.
    $existing_member = function_exists('lmeg_current_member') ? lmeg_current_member() : null;
    if ($existing_member && strpos($after, 'checkout:') === 0 && function_exists('lmeg_stripe_create_checkout')) {
        $parts    = explode(':', $after);
        $tier_id  = isset($parts[1]) ? (int) $parts[1] : 0;
        $interval = isset($parts[2]) && $parts[2] === 'annual' ? 'annual' : 'monthly';
        $tier     = $tier_id ? lmeg_tier($tier_id) : null;
        if (!$tier) {
            wp_die('Tier not found. Configure at Email Gate → Tiers (Paid).', 'Checkout error', ['response' => 400, 'back_link' => true]);
        }
        $session = lmeg_stripe_create_checkout($existing_member, $tier, $interval);
        if (is_wp_error($session)) {
            wp_die('Stripe checkout failed: ' . esc_html($session->get_error_message()) . '<br><br>Common causes: (1) Stripe test/live keys missing or wrong mode in Settings, (2) tier is missing its Stripe price ID (e.g. price_XXXXX) for the monthly or annual button clicked.',
                'Checkout error', ['response' => 500, 'back_link' => true]);
        }
        if (empty($session['url'])) {
            wp_die('Stripe returned no checkout URL. Check the raw Stripe response in your dashboard → Developers → Logs.', 'Checkout error', ['response' => 500, 'back_link' => true]);
        }
        wp_redirect(esc_url_raw($session['url']));
        exit;
    }

    // Fast path: existing member submitting the plain "free" button (or
    // anything else) — just bounce them back, they already have access.
    if ($existing_member && $after === 'free') {
        wp_safe_redirect($redirect);
        exit;
    }

    $type = in_array($_POST['contact_type'] ?? 'email', ['email', 'phone'], true)
        ? $_POST['contact_type'] : 'email';

    $email = '';
    $phone = '';
    $phone_country = '';

    if ($type === 'email') {
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (!is_email($email)) {
            if (function_exists('lmeg_log_signup_reject')) lmeg_log_signup_reject('invalid_email', $email);
            wp_die('Please enter a valid email address.', 'Invalid email', [
                'response' => 400, 'back_link' => true,
            ]);
        }
        if (!lmeg_email_domain_ok($email)) {
            if (function_exists('lmeg_log_signup_reject')) lmeg_log_signup_reject('domain_rejected', $email);
            wp_die('Please enter a valid email address.', 'Invalid email', [
                'response' => 400, 'back_link' => true,
            ]);
        }
    } else {
        $iso  = strtoupper(sanitize_text_field(wp_unslash($_POST['phone_country'] ?? '')));
        $c    = lmeg_country_by_iso($iso);
        $raw  = preg_replace('/[^\d]/', '', wp_unslash($_POST['phone'] ?? ''));
        if (!$c || strlen($raw) < 6) {
            wp_die('Please enter a valid phone number.', 'Invalid phone', [
                'response' => 400, 'back_link' => true,
            ]);
        }
        $phone = '+' . $c[2] . ltrim($raw, '0');
        $phone_country = $iso;
    }

    $address_iso = strtoupper(sanitize_text_field(wp_unslash($_POST['address_country'] ?? '')));
    $address_iso = preg_match('/^[A-Z]{2}$/', $address_iso) ? $address_iso : '';
    // Country precedence: phone-country > address-country > IP geolocation.
    $country = $phone_country ?: $address_iso;
    if (!$country && function_exists('lmeg_geo_country_current_request')) {
        $country = lmeg_geo_country_current_request();
    }

    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

    lmeg_store_subscriber([
        'contact_type' => $type,
        'email'        => $email ?: null,
        'phone'        => $phone ?: null,
        'country'      => $country ?: null,
        'street'       => sanitize_text_field(wp_unslash($_POST['street'] ?? '')) ?: null,
        'city'         => sanitize_text_field(wp_unslash($_POST['city'] ?? '')) ?: null,
        'region'       => sanitize_text_field(wp_unslash($_POST['region'] ?? '')) ?: null,
        'postal_code'  => sanitize_text_field(wp_unslash($_POST['postal_code'] ?? '')) ?: null,
        'post_id'      => $post_id ?: null,
    ]);

    lmeg_set_cookie();

    // Drop a signed member cookie so the fresh signup counts as a free
    // member on the next request. Also grab the row for post-signup routing.
    $found = null;
    if ($email || $phone) {
        global $wpdb;
        $col   = $email ? 'email' : 'phone';
        $val   = $email ?: $phone;
        $found = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE $col = %s",
            $val
        ));
        if ($found) lmeg_set_member_cookie($found->id, (int) $found->member_tier_id);
    }

    // Source tagging: any signup form (bio page, release-drop "Notify me",
    // embedded forms) can pass lmeg_tags — a comma-separated list of tag slugs
    // — to record where this opt-in came from and power targeted broadcasts.
    if ($found && !empty($_POST['lmeg_tags']) && function_exists('lmeg_get_or_create_tag')) {
        $slugs = array_slice(array_filter(array_map('sanitize_title',
            explode(',', (string) wp_unslash($_POST['lmeg_tags'])))), 0, 8);
        foreach ($slugs as $slug) {
            $tag = lmeg_get_or_create_tag($slug, null, true);
            if ($tag && function_exists('lmeg_attach_tag')) {
                lmeg_attach_tag((int) $found->id, (int) $tag->id);
            }
        }
    }

    // Honor lmeg_after intent: paywall form can request Stripe checkout or a
    // "start free" soft-grant right after the signup instead of the default redirect.
    $after = isset($_POST['lmeg_after']) ? sanitize_text_field(wp_unslash($_POST['lmeg_after'])) : '';
    if ($found && $after) {
        // Auto-grant a soft-paid post if that's what the visitor was on.
        if ($post_id && function_exists('lmeg_post_access_level') && lmeg_post_access_level($post_id) === 'soft-paid') {
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}lmeg_soft_grants (subscriber_id, post_id, granted_at)
                 VALUES (%d, %d, %s)",
                (int) $found->id, (int) $post_id, current_time('mysql')
            ));
        }

        // Checkout intent — "checkout:<tier_id>:<interval>".
        if (strpos($after, 'checkout:') === 0 && function_exists('lmeg_stripe_create_checkout')) {
            $parts    = explode(':', $after);
            $tier_id  = isset($parts[1]) ? (int) $parts[1] : 0;
            $interval = isset($parts[2]) && $parts[2] === 'annual' ? 'annual' : 'monthly';
            $tier     = $tier_id ? lmeg_tier($tier_id) : null;
            if (!$tier) {
                wp_die('Tier not found. Configure at Email Gate → Tiers (Paid).', 'Checkout error', ['response' => 400, 'back_link' => true]);
            }
            $session = lmeg_stripe_create_checkout($found, $tier, $interval);
            if (is_wp_error($session)) {
                wp_die('Stripe checkout failed: ' . esc_html($session->get_error_message()) . '<br><br>Common causes: (1) Stripe test/live keys missing or wrong mode in Settings, (2) tier is missing its Stripe price ID for the button clicked (monthly vs annual).',
                    'Checkout error', ['response' => 500, 'back_link' => true]);
            }
            if (!empty($session['url'])) {
                wp_redirect(esc_url_raw($session['url']));
                exit;
            }
            wp_die('Stripe returned no checkout URL. Check Stripe dashboard → Developers → Logs.', 'Checkout error', ['response' => 500, 'back_link' => true]);
        }
    }

    wp_safe_redirect($redirect);
    exit;
}

function lmeg_store_subscriber($data) {
    global $wpdb;
    $table = $wpdb->prefix . LMEG_TABLE;

    // Dedupe by whichever contact value is present. An existing row that
    // had previously unsubscribed gets reactivated — actively re-submitting
    // the form is treated as a fresh opt-in.
    $row_id = null;
    if (!empty($data['email'])) {
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE email = %s", $data['email']));
        if ($existing) {
            $update = array_filter([
                'phone'       => $data['phone'],
                'country'     => $data['country'],
                'street'      => $data['street'],
                'city'        => $data['city'],
                'region'      => $data['region'],
                'postal_code' => $data['postal_code'],
            ], function ($v) { return $v !== null && $v !== ''; });
            $update['unsubscribed_at'] = null;
            $wpdb->update($table, $update, ['id' => $existing]);
            $row_id = (int) $existing;
        }
    }
    if (!$row_id && !empty($data['phone'])) {
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE phone = %s", $data['phone']));
        if ($existing) {
            $update = array_filter([
                'email'       => $data['email'],
                'country'     => $data['country'],
                'street'      => $data['street'],
                'city'        => $data['city'],
                'region'      => $data['region'],
                'postal_code' => $data['postal_code'],
            ], function ($v) { return $v !== null && $v !== ''; });
            $update['unsubscribed_at'] = null;
            $wpdb->update($table, $update, ['id' => $existing]);
            $row_id = (int) $existing;
        }
    }

    $is_new = false;
    if (!$row_id) {
        $wpdb->insert($table, [
            'contact_type' => $data['contact_type'],
            'email'        => $data['email'],
            'phone'        => $data['phone'],
            'country'      => $data['country'],
            'street'       => $data['street'],
            'city'         => $data['city'],
            'region'       => $data['region'],
            'postal_code'  => $data['postal_code'],
            'post_id'      => $data['post_id'],
            'ip'           => substr(function_exists('lmeg_client_ip') ? lmeg_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'referrer'     => substr($_SERVER['HTTP_REFERER'] ?? '', 0, 255),
            'created_at'   => current_time('mysql'),
        ]);
        $row_id = (int) $wpdb->insert_id;
        $is_new = true;
    }

    // Refresh auto-tags so the row's channel/country/has-address tags
    // always reflect the current shape of the data.
    if ($row_id) {
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $row_id));
        if ($row) lmeg_apply_auto_tags($row);
        if ($is_new) {
            // Welcome email + sequence enrollments hook off this.
            do_action('lmeg_subscriber_created', $row_id);
        }
    }
}

function lmeg_set_cookie() {
    $s    = lmeg_get_settings();
    $days = max(1, (int) $s['cookie_days']);
    setcookie(
        LMEG_COOKIE,
        '1',
        time() + $days * DAY_IN_SECONDS,
        COOKIEPATH ?: '/',
        COOKIE_DOMAIN,
        is_ssl(),
        false
    );
}

/* ---------------------------------------------------------------------------
 * Assets
 * ------------------------------------------------------------------------- */

add_action('wp_enqueue_scripts', 'lmeg_enqueue');
function lmeg_enqueue() {
    // Load on any page that might render the gate. Cheap; the JS only acts
    // if a .lmeg-form element exists.
    wp_enqueue_style('lmeg-gate', LMEG_PLUGIN_URL . 'assets/gate.css', [], LMEG_VERSION);
    wp_enqueue_script('lmeg-gate', LMEG_PLUGIN_URL . 'assets/gate.js', [], LMEG_VERSION, true);

    // Emit the customisable palette. Two layers:
    //   1) CSS variables scoped to plugin containers (the polite default —
    //      lets an advanced user override via theme CSS if they want).
    //   2) Direct property overrides with !important on the specific
    //      selectors that need them — this is what actually beats a theme's
    //      body/main/main-content rules that would otherwise leak through.
    $s = lmeg_get_settings();
    $primary      = sanitize_hex_color($s['color_primary'])      ?: '#111111';
    $primary_text = sanitize_hex_color($s['color_primary_text']) ?: '#ffffff';
    $accent       = sanitize_hex_color($s['color_accent'])       ?: '#3b82f6';
    $border       = sanitize_hex_color($s['color_border']       ?? '');
    $card_bg      = sanitize_hex_color($s['color_card_bg']      ?? '');
    $card_text    = sanitize_hex_color($s['color_card_text']    ?? '');
    $page_bg      = sanitize_hex_color($s['color_page_bg']      ?? '');

    // Layer 1: CSS variables on every plugin container.
    $vars = ["--lmeg-primary: $primary;", "--lmeg-primary-text: $primary_text;", "--lmeg-accent: $accent;"];
    if ($border)    $vars[] = "--lmeg-border: $border;";
    if ($card_bg)   $vars[] = "--lmeg-card-bg: $card_bg;";
    if ($card_text) $vars[] = "--lmeg-card-text: $card_text;";

    $css  = '.lmeg-form,.lmeg-paywall,.lmeg-embed,.lmeg-upgrade,.lmeg-locked-wrap,.lmeg-gate{' . implode('', $vars) . '}';

    // Layer 2: Direct !important overrides — only when settings are set.
    if ($card_bg) {
        $css .= '.lmeg-paywall,.lmeg-gate,.lmeg-embed--card{background:' . $card_bg . ' !important;}';
    }
    if ($card_text) {
        $css .= '.lmeg-paywall,.lmeg-paywall *,.lmeg-gate,.lmeg-gate *,.lmeg-embed--card,.lmeg-embed--card *{color:' . $card_text . ' !important;}';
        // Preserve button text color — those buttons use --lmeg-primary-text.
        $css .= '.lmeg-paywall .lmeg-button,.lmeg-gate .lmeg-button,.lmeg-embed--card .lmeg-button{color:' . $primary_text . ' !important;}';
    }
    if ($page_bg) {
        $css .= '.lmeg-locked-wrap{background:' . $page_bg . ' !important;}';
        // Also cover the parent theme wrappers commonly used for the post
        // area, so the color extends edge-to-edge instead of leaving a
        // theme-colored gap around the gate.
        $css .= 'body.lmeg-page-bg{background:' . $page_bg . ' !important;}';
    }

    wp_add_inline_style('lmeg-gate', $css);
}
