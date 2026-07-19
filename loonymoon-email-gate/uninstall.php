<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_subscribers");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_broadcasts");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_broadcast_log");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_subscriber_tags");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_tags");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_broadcast_events");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_segments");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_templates");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_sequence_enrollments");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_sequence_steps");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_sequences");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_tiers");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_magic_links");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_stripe_events");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_soft_grants");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_shop_orders");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lmeg_smartlinks");
delete_option('lmeg_shop_last_sync');
delete_option('lmeg_fan_types_last_run');
delete_option('lmeg_smartlinks_flushed');

// Clean per-post meta.
delete_post_meta_by_key('_lmeg_access');

delete_option('lmeg_settings');
delete_option('lmeg_db_version');
delete_option('lmeg_secret_key');

$ts = wp_next_scheduled('lmeg_broadcast_tick');
if ($ts) wp_unschedule_event($ts, 'lmeg_broadcast_tick');
