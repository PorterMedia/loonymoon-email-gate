<?php
/**
 * What the plan costs, and what it has to return.
 *
 * Two models, both editable, neither guessing at the artist's own numbers:
 *
 *   COST — a typical low/mid/high price for each kind of move, in cents.
 *   These are Fanloop's shipped defaults for an independent artist working in
 *   Canada or the US; they are not read from anyone's books, and a site can
 *   replace any of them through the `lmeg_plan_costs` filter.
 *
 *   RETURN — what a fan and a thousand streams are actually worth to THIS
 *   artist, taken from their own store orders where they have them, and from
 *   an editable streaming rate where they don't. That's what turns a price
 *   into a decision: this costs $150 and needs 11 orders or 43,000 streams to
 *   wash its face.
 *
 * Every figure the plan prints can be traced to one of those two places, and
 * a move with no money attached says nothing about money at all.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Typical spend per move, in cents: [low, typical, high, unit].
 * Keys are move keys first, then a channel fallback. Filterable.
 */
function lmeg_plan_cost_table() {
    $t = [
        // Paid reach
        'paid-boost'     => [10000, 25000, 100000, 'per push'],
        'roll-presave'   => [0, 0, 0, ''],
        // Things that usually cost something to make
        'roll-focus'     => [0, 15000, 75000, 'if you hire the edit'],
        'city-play'      => [0, 25000, 150000, 'room, gear, travel'],
        'merch-tease'    => [0, 0, 0, ''],
        'sync-pitch'     => [0, 0, 15000, 'if you buy a library submission'],
        'collect-ugc'    => [0, 0, 5000, 'if you give something away'],
        'store-first'    => [5000, 30000, 120000, 'first run of stock'],
        // Free by default: email, admin, evergreen posting
        'channel:email'   => [0, 0, 0, ''],
        'channel:content' => [0, 0, 0, ''],
        'channel:social'  => [0, 0, 0, ''],
        'channel:release' => [0, 0, 0, ''],
        'channel:store'   => [0, 0, 0, ''],
        'channel:admin'   => [0, 0, 0, ''],
    ];
    return (array) apply_filters('lmeg_plan_costs', $t);
}

/** [low, typical, high, unit] for one move. Pure given the table. */
function lmeg_plan_move_cost($key, $channel, $table = null) {
    $t = $table ?: lmeg_plan_cost_table();
    $key = (string) $key;
    // Anchored moves carry their date in the key: roll-announce-2026-10-18.
    $base = preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $key);
    $base = preg_replace('/-w\d+$/', '', $base);
    foreach ([$key, $base] as $k) if (isset($t[$k])) return array_values((array) $t[$k]);
    $ch = 'channel:' . (string) $channel;
    if (isset($t[$ch])) return array_values((array) $t[$ch]);
    return [0, 0, 0, ''];
}

/**
 * What a fan and a thousand streams are worth to this artist.
 *
 * aov / rev_per_fan come from their own paid orders (native store first, then
 * Shopify). The streaming rate is a filterable default because no site has
 * per-stream payouts in it — `lmeg_plan_stream_rate_cents` is the one number a
 * label or manager would want to set per artist.
 */
function lmeg_plan_economics($days = 90) {
    global $wpdb;
    $out = ['aov_cents' => null, 'orders' => 0, 'rev_cents' => 0, 'fans' => null, 'rev_per_fan_cents' => null,
            'stream_rate_cents' => (float) apply_filters('lmeg_plan_stream_rate_cents', 0.35), 'source' => 'none'];

    $pp = $wpdb->prefix . 'lmeg_product_purchases';
    if (function_exists('lmeg_plan_has_table') && lmeg_plan_has_table($pp)) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) n, COALESCE(SUM(total_cents),0) rev FROM $pp WHERE status='paid' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", (int) $days), ARRAY_A);
        if ($row && (int) $row['n'] > 0) {
            $out['orders'] = (int) $row['n']; $out['rev_cents'] = (int) $row['rev'];
            $out['aov_cents'] = (int) round($out['rev_cents'] / max(1, $out['orders']));
            $out['source'] = 'store';
        }
    }
    if ($out['aov_cents'] === null) {
        $so = $wpdb->prefix . 'lmeg_shop_orders';
        if (function_exists('lmeg_plan_has_table') && lmeg_plan_has_table($so)) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) n, COALESCE(SUM(total_cents),0) rev FROM $so WHERE ordered_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", (int) $days), ARRAY_A);
            if ($row && (int) $row['n'] > 0) {
                $out['orders'] = (int) $row['n']; $out['rev_cents'] = (int) $row['rev'];
                $out['aov_cents'] = (int) round($out['rev_cents'] / max(1, $out['orders']));
                $out['source'] = 'shopify';
            }
        }
    }
    if (function_exists('lmeg_fanbase_counts')) {
        $c = (array) lmeg_fanbase_counts();
        $fans = (int) ($c['total'] ?? 0);
        if ($fans > 0) {
            $out['fans'] = $fans;
            if ($out['rev_cents'] > 0) $out['rev_per_fan_cents'] = round($out['rev_cents'] / $fans, 2);
        }
    }
    return $out;
}

/**
 * "needs 11 orders, or about 43,000 streams, to pay for itself" — the sentence
 * that makes a price a decision. '' when the move is free or we have no rate
 * to divide by. Pure.
 */
function lmeg_plan_breakeven($cost_cents, $econ) {
    $cost = (int) $cost_cents;
    if ($cost < 1) return '';
    $parts = [];
    $aov = isset($econ['aov_cents']) ? (int) $econ['aov_cents'] : 0;
    if ($aov > 0) {
        $n = (int) ceil($cost / $aov);
        $parts[] = $n . ' ' . ($n === 1 ? 'order' : 'orders') . ' at your average ' . lmeg_plan_money($aov);
    }
    $rate = isset($econ['stream_rate_cents']) ? (float) $econ['stream_rate_cents'] : 0;
    if ($rate > 0) {
        $streams = (int) round($cost / $rate / 1000) * 1000;
        if ($streams >= 1000) $parts[] = 'about ' . number_format_i18n($streams) . ' streams';
    }
    if (!$parts) return '';
    return 'Pays for itself at ' . implode(', or ', $parts) . '.';
}

/** $1,250 / $12.50 from cents. Pure. */
function lmeg_plan_money($cents, $with_cents = null) {
    $c = (int) round((float) $cents);
    $dollars = $c / 100;
    if ($with_cents === null) $with_cents = ($c % 100) !== 0 && abs($dollars) < 100;
    return '$' . number_format_i18n($dollars, $with_cents ? 2 : 0);
}

/** What the brief's budget answer means in cents over 90 days. Pure. */
function lmeg_plan_budget_cents($brief) {
    $map = ['none' => 0, 'low' => 15000, 'mid' => 62500, 'high' => 200000];
    $b = (string) ($brief['budget'] ?? '');
    return array_key_exists($b, $map) ? $map[$b] : null;
}

/**
 * Cost of a set of moves: totals, the priced ones, and how it sits against the
 * budget the artist gave. Pure given rows with cost_cents.
 */
function lmeg_plan_cost_summary($rows, $brief = []) {
    $low = 0; $typ = 0; $high = 0; $priced = 0;
    $table = lmeg_plan_cost_table();
    foreach ((array) $rows as $r) {
        $key = is_object($r) ? (string) $r->mkey : (string) ($r['key'] ?? '');
        $ch  = is_object($r) ? (string) $r->channel : (string) ($r['channel'] ?? '');
        list($l, $t, $h) = lmeg_plan_move_cost($key, $ch, $table);
        if ((int) $t > 0 || (int) $h > 0) $priced++;
        $low += (int) $l; $typ += (int) $t; $high += (int) $h;
    }
    $budget = lmeg_plan_budget_cents($brief);
    // The 90-day budget covers roughly four of these four-week plans... no:
    // one plan IS four weeks, so a 90-day budget gives it about a third.
    $window = $budget === null ? null : (int) round($budget / 3);
    return [
        'low' => $low, 'typical' => $typ, 'high' => $high, 'priced' => $priced,
        'budget_90d' => $budget, 'budget_window' => $window,
        'over' => ($window !== null && $typ > $window),
        'free' => ($typ === 0 && $high === 0),
    ];
}
