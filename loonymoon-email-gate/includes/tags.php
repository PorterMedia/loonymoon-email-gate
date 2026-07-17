<?php
/**
 * Tags + audience resolution. The native equivalent of Mailchimp's
 * tags + tag-based segments. Every subscriber row can have any number
 * of tag rows joined via the lmeg_subscriber_tags table.
 *
 * "Auto" tags are managed by the plugin (channel:email, country:CA,
 * has-address) — they're recomputed on every signup and on backfill.
 * Non-auto tags are user-managed and persist until explicitly removed.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------------------------
 * Tag CRUD
 * ------------------------------------------------------------------------- */

function lmeg_normalize_slug($slug) {
    $slug = strtolower(trim((string) $slug));
    $slug = preg_replace('/[^a-z0-9:_\-]+/', '-', $slug);
    return substr(trim($slug, '-'), 0, 64);
}

/**
 * Get a tag by slug, creating it if missing. Returns the tag row, or null on failure.
 */
function lmeg_get_or_create_tag($slug, $name = null, $is_auto = false, $color = null) {
    global $wpdb;
    $table = $wpdb->prefix . 'lmeg_tags';
    $slug  = lmeg_normalize_slug($slug);
    if (!$slug) return null;

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE slug = %s", $slug));
    if ($row) return $row;

    $wpdb->insert($table, [
        'slug'       => $slug,
        'name'       => $name ?: $slug,
        'color'      => $color ?: lmeg_default_tag_color($slug),
        'is_auto'    => $is_auto ? 1 : 0,
        'created_at' => current_time('mysql'),
    ]);
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $wpdb->insert_id));
}

/**
 * Pleasant default color per tag family. Channel/country/auto tags get
 * predictable hues so the UI feels coherent.
 */
function lmeg_default_tag_color($slug) {
    if (strpos($slug, 'channel:email') === 0) return '#3b82f6';
    if (strpos($slug, 'channel:phone') === 0) return '#10b981';
    if (strpos($slug, 'country:')       === 0) return '#8b5cf6';
    if ($slug === 'has-address')              return '#f59e0b';
    return '#6b7280';
}

function lmeg_all_tags() {
    global $wpdb;
    $tags    = $wpdb->prefix . 'lmeg_tags';
    $subtags = $wpdb->prefix . 'lmeg_subscriber_tags';
    return $wpdb->get_results("
        SELECT t.*, COALESCE(c.cnt, 0) AS member_count
        FROM $tags t
        LEFT JOIN (SELECT tag_id, COUNT(*) AS cnt FROM $subtags GROUP BY tag_id) c ON c.tag_id = t.id
        ORDER BY t.is_auto DESC, t.name ASC
    ");
}

function lmeg_tags_for_subscriber($subscriber_id) {
    global $wpdb;
    $tags    = $wpdb->prefix . 'lmeg_tags';
    $subtags = $wpdb->prefix . 'lmeg_subscriber_tags';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT t.* FROM $tags t JOIN $subtags st ON st.tag_id = t.id WHERE st.subscriber_id = %d ORDER BY t.is_auto DESC, t.name ASC",
        $subscriber_id
    ));
}

/* ---------------------------------------------------------------------------
 * Attach / detach
 * ------------------------------------------------------------------------- */

function lmeg_attach_tag($subscriber_id, $tag_id) {
    global $wpdb;
    $subtags = $wpdb->prefix . 'lmeg_subscriber_tags';
    // INSERT IGNORE so re-tagging is idempotent. rows_affected reflects
    // whether this call actually introduced the pairing — sequence triggers
    // only fire on the first attach.
    $before = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $subtags WHERE subscriber_id = %d AND tag_id = %d",
        $subscriber_id, $tag_id
    ));
    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO $subtags (subscriber_id, tag_id, created_at) VALUES (%d, %d, %s)",
        $subscriber_id, $tag_id, current_time('mysql')
    ));
    if ($before === 0) {
        do_action('lmeg_tag_attached', (int) $subscriber_id, (int) $tag_id);
    }
}

function lmeg_detach_tag($subscriber_id, $tag_id) {
    global $wpdb;
    $subtags = $wpdb->prefix . 'lmeg_subscriber_tags';
    $wpdb->delete($subtags, ['subscriber_id' => $subscriber_id, 'tag_id' => $tag_id]);
}

function lmeg_detach_auto_tags($subscriber_id, $slug_prefix) {
    global $wpdb;
    $tags    = $wpdb->prefix . 'lmeg_tags';
    $subtags = $wpdb->prefix . 'lmeg_subscriber_tags';
    $wpdb->query($wpdb->prepare(
        "DELETE st FROM $subtags st JOIN $tags t ON t.id = st.tag_id
         WHERE st.subscriber_id = %d AND t.is_auto = 1 AND t.slug LIKE %s",
        $subscriber_id, $wpdb->esc_like($slug_prefix) . '%'
    ));
}

/* ---------------------------------------------------------------------------
 * Auto-tag derivation from a subscriber row
 * ------------------------------------------------------------------------- */

/**
 * Recompute and apply the canonical auto-tags for a subscriber row.
 * Old conflicting auto-tags in the same family are removed first so a
 * country change doesn't leave a stale country tag attached.
 */
function lmeg_apply_auto_tags($sub) {
    if (!is_object($sub) && !is_array($sub)) return;
    $sub = (object) $sub;
    if (empty($sub->id)) return;

    // channel:email or channel:phone — exactly one wins.
    lmeg_detach_auto_tags($sub->id, 'channel:');
    $channel_slug = $sub->contact_type === 'phone' ? 'channel:phone' : 'channel:email';
    $channel_name = $sub->contact_type === 'phone' ? 'Channel: SMS' : 'Channel: Email';
    $t = lmeg_get_or_create_tag($channel_slug, $channel_name, true);
    if ($t) lmeg_attach_tag($sub->id, $t->id);

    // country:XX — only if we know it.
    lmeg_detach_auto_tags($sub->id, 'country:');
    if (!empty($sub->country)) {
        $iso  = strtoupper($sub->country);
        $name = 'Country: ' . $iso;
        if (function_exists('lmeg_country_by_iso')) {
            $c = lmeg_country_by_iso($iso);
            if ($c) $name = 'Country: ' . $c[1];
        }
        $t = lmeg_get_or_create_tag('country:' . strtolower($iso), $name, true);
        if ($t) lmeg_attach_tag($sub->id, $t->id);
    }

    // has-address — set if any address field is filled.
    $has_addr = !empty($sub->street) || !empty($sub->city) || !empty($sub->region) || !empty($sub->postal_code);
    lmeg_detach_auto_tags($sub->id, 'has-address');
    if ($has_addr) {
        $t = lmeg_get_or_create_tag('has-address', 'Has mailing address', true);
        if ($t) lmeg_attach_tag($sub->id, $t->id);
    }

    // Membership tags — channel:paid + tier:<slug> — reflect Stripe state.
    lmeg_detach_auto_tags($sub->id, 'tier:');
    // Also strip channel:paid so downgrades remove it; channel:email/phone was
    // handled above by the 'channel:' family clear.
    $is_paying = !empty($sub->member_tier_id) && ($sub->member_status ?? '') === 'active';
    if ($is_paying && function_exists('lmeg_tier')) {
        $paid = lmeg_get_or_create_tag('channel:paid', 'Channel: Paid', true);
        if ($paid) lmeg_attach_tag($sub->id, $paid->id);
        $tier = lmeg_tier($sub->member_tier_id);
        if ($tier) {
            $tt = lmeg_get_or_create_tag('tier:' . $tier->slug, 'Tier: ' . $tier->name, true);
            if ($tt) lmeg_attach_tag($sub->id, $tt->id);
        }
    }
}

/* ---------------------------------------------------------------------------
 * Audience resolution (the heart of "send by tag")
 * ------------------------------------------------------------------------- */

/**
 * Build a SQL fragment + params that constrains lmeg_subscribers.id to the
 * audience matching the given tag filter. Returns [where_sql, params_array].
 *
 * @param array $filter ['tag_ids' => int[], 'match' => 'any'|'all']
 * @return array [string, array]  -- empty WHERE if no tags specified
 */
function lmeg_audience_where($filter) {
    global $wpdb;
    $subtags = $wpdb->prefix . 'lmeg_subscriber_tags';

    $tag_ids = array_filter(array_map('intval', (array) ($filter['tag_ids'] ?? [])));
    if (!$tag_ids) {
        return ['', []];
    }
    $placeholders = implode(',', array_fill(0, count($tag_ids), '%d'));
    $match        = ($filter['match'] ?? 'any') === 'all' ? 'all' : 'any';

    if ($match === 'any') {
        $sql    = "id IN (SELECT DISTINCT subscriber_id FROM $subtags WHERE tag_id IN ($placeholders))";
        $params = $tag_ids;
    } else {
        $sql    = "id IN (
            SELECT subscriber_id FROM $subtags
            WHERE tag_id IN ($placeholders)
            GROUP BY subscriber_id
            HAVING COUNT(DISTINCT tag_id) = %d
        )";
        $params = array_merge($tag_ids, [count($tag_ids)]);
    }

    return [$sql, $params];
}

/**
 * Count subscribers matching a tag filter. Honors the same active-only and
 * channel/contact filtering as queue_broadcast so the admin preview is honest.
 */
function lmeg_audience_count($filter, $require = []) {
    global $wpdb;
    $subs = $wpdb->prefix . LMEG_TABLE;

    list($audience_sql, $audience_params) = lmeg_audience_where($filter);

    $where  = ['unsubscribed_at IS NULL'];
    $params = [];

    if ($audience_sql) {
        $where[]  = $audience_sql;
        $params   = array_merge($params, $audience_params);
    }

    // Optional channel constraints (any of these must be satisfied).
    $channel_clauses = [];
    if (!empty($require['email'])) $channel_clauses[] = "(contact_type = 'email' AND email IS NOT NULL AND email <> '')";
    if (!empty($require['sms']))   $channel_clauses[] = "(contact_type = 'phone' AND phone IS NOT NULL AND phone <> '')";
    if ($channel_clauses) {
        $where[] = '(' . implode(' OR ', $channel_clauses) . ')';
    }

    $sql = "SELECT COUNT(*) FROM $subs WHERE " . implode(' AND ', $where);
    return (int) ($params
        ? $wpdb->get_var($wpdb->prepare($sql, $params))
        : $wpdb->get_var($sql));
}
