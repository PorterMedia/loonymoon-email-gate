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

/**
 * The 90-day budget in cents: the real number when they gave one, otherwise
 * the midpoint of the band they picked, otherwise null. Pure.
 */
function lmeg_plan_budget_cents($brief) {
    $amt = $brief['budget_amount'] ?? '';
    if ($amt !== '' && (int) $amt > 0) return (int) $amt * 100;
    if ($amt !== '' && (int) $amt === 0) return 0;
    $map = ['none' => 0, 'low' => 15000, 'mid' => 62500, 'high' => 200000];
    $b = (string) ($brief['budget'] ?? '');
    return array_key_exists($b, $map) ? $map[$b] : null;
}

/** Grant money expected, in cents. Pure. */
function lmeg_plan_funding_cents($brief) {
    $f = $brief['funding_expected'] ?? '';
    return ($f === '') ? 0 : max(0, (int) $f) * 100;
}

/** Budget, funding and what actually leaves the account. Pure. */
function lmeg_plan_budget_split($brief) {
    $total = lmeg_plan_budget_cents($brief);
    if ($total === null) return null;
    $fund = lmeg_plan_funding_cents($brief);
    $fund = min($fund, $total);
    return ['total' => $total, 'funding' => $fund, 'out_of_pocket' => max(0, $total - $fund),
            'funded_pct' => $total > 0 ? (int) round($fund / $total * 100) : 0];
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

/* ---------------------------------------------------------------------------
 * How a budget is shaped.
 *
 * A marketing budget that works is not a single number, it is an allocation
 * across the same handful of categories every time — what the record looks
 * like, what there is to watch, who is told, who is paid to tell people, and
 * what fans can buy — with a slice held back for the thing you didn't foresee.
 *
 * Two rules of thumb hold across scales, and both are in the weights below:
 * the smaller the budget, the more of it belongs in assets and content the
 * artist keeps and reuses; paid reach and publicity only earn a bigger share
 * once there is something proven to point them at. What shifts the mix from
 * there is the artist's own stage, their stated goal and whether a release is
 * actually in flight.
 *
 * Nothing here is any one company's figures — it is the shape, applied to
 * whatever number the artist says they have, and every weight is filterable.
 * ------------------------------------------------------------------------- */

function lmeg_plan_budget_categories() {
    return [
        'assets'  => ['Assets',      'photos, artwork, a bio, the things every other line needs'],
        'content' => ['Content',     'video, visualizers, clips — what there is to watch'],
        'paid'    => ['Paid reach',  'ads and platform placements, pointed at what already works'],
        'pr'      => ['Publicity',   'someone whose job is getting you covered'],
        'live'    => ['Live',        'the room, the gear, getting there'],
        'promo'   => ['Merch',       'stock to sell and things to give away'],
    ];
}

/**
 * Recommended allocation of a total budget. Pure.
 * Returns ['total','contingency','rows'[key,label,desc,pct,cents,why],'notes'[]].
 */
function lmeg_plan_budget_shape($total_cents, $ctx = []) {
    $total = max(0, (int) $total_cents);
    $cats  = lmeg_plan_budget_categories();
    $stage = (int) ($ctx['stage']['stage'] ?? 0);
    $goal  = (string) ($ctx['brief']['goal'] ?? '');
    $days  = $ctx['release']['days_out'] ?? null;
    $in_flight = ($days !== null && (int) $days <= 45);
    $why = [];

    if ($stage <= 2) {
        $w = ['assets' => 30, 'content' => 30, 'paid' => 20, 'pr' => 10, 'live' => 5, 'promo' => 5];
        $why['assets'] = 'early on, the photos and artwork get reused by every other line';
    } elseif ($stage <= 4) {
        $w = ['assets' => 22, 'content' => 28, 'paid' => 25, 'pr' => 15, 'live' => 5, 'promo' => 5];
        $why['paid'] = 'you have enough proof now to pay to show it to more people';
    } else {
        $w = ['assets' => 18, 'content' => 25, 'paid' => 22, 'pr' => 20, 'live' => 8, 'promo' => 7];
        $why['pr'] = 'at your stage coverage compounds — it is worth paying someone to chase it';
    }

    $shift = function (&$w, $key, $n) { $w[$key] = max(0, (int) ($w[$key] ?? 0) + (int) $n); };
    switch ($goal) {
        case 'shows':   $shift($w, 'live', 12); $shift($w, 'paid', -6); $shift($w, 'pr', -6); $why['live'] = 'you said the next 90 days are about playing'; break;
        case 'sales':   $shift($w, 'promo', 10); $shift($w, 'pr', -6); $shift($w, 'content', -4); $why['promo'] = 'you said the next 90 days are about selling'; break;
        case 'sync':    $shift($w, 'pr', 8); $shift($w, 'assets', 4); $shift($w, 'paid', -12); $why['pr'] = 'placements come from relationships and clean assets, not from ads'; break;
        case 'list':    $shift($w, 'paid', 8); $shift($w, 'content', 4); $shift($w, 'pr', -8); $shift($w, 'live', -4); $why['paid'] = 'paid reach is the fastest way to put a sign-up in front of strangers'; break;
        case 'streams': $shift($w, 'paid', 6); $shift($w, 'content', 5); $shift($w, 'pr', -7); $shift($w, 'live', -4); $why['content'] = 'streams follow something to watch'; break;
        case 'members': $shift($w, 'content', 6); $shift($w, 'promo', 4); $shift($w, 'paid', -6); $shift($w, 'pr', -4); $why['content'] = 'people pay monthly for access to more, so there has to be more'; break;
    }
    if ($in_flight) {
        $shift($w, 'assets', 5); $shift($w, 'content', 5); $shift($w, 'paid', 4);
        $shift($w, 'live', -7); $shift($w, 'promo', -7);
        $why['content'] = 'a release inside six weeks needs something to watch more than anything else';
    }

    // A category pushed to nothing is dropped, but never silently.
    $parked = [];
    foreach ($w as $k => $pct) if ($pct <= 0) $parked[] = $cats[$k][0];

    // 10% held back — every real budget that survives contact keeps a remainder.
    $hold = (int) round($total * 0.10);
    $spendable = max(0, $total - $hold);
    $sum = array_sum($w) ?: 1;
    $rows = [];
    foreach ($w as $k => $pct) {
        if ($pct <= 0) continue;
        $rows[] = [
            'key' => $k, 'label' => $cats[$k][0], 'desc' => $cats[$k][1],
            'pct' => (int) round($pct / $sum * 100),
            'cents' => (int) round($spendable * $pct / $sum),
            'why' => (string) ($why[$k] ?? ''),
        ];
    }
    usort($rows, function ($a, $b) { return $b['cents'] <=> $a['cents']; });

    // What the allocation can't fix, said plainly.
    $notes = [];
    if ($parked && $total > 0) {
        $notes[] = ($in_flight ? 'While the release is in flight, ' : 'For this stretch, ')
                 . strtolower(implode(' and ', $parked)) . ' ' . (count($parked) === 1 ? 'gets' : 'get')
                 . ' nothing. Park ' . (count($parked) === 1 ? 'it' : 'them') . ' until the record is out, then reallocate.';
    }
    if ($total > 0 && $total < 30000) {
        $notes[] = 'Under $300 there is no point splitting six ways. Put it into one thing you keep — photos, or one video — and spend the rest of the plan on your own list.';
    }
    if (empty($ctx['store']['products']) && $total > 0) {
        $notes[] = 'There is nothing in the store yet, so the merch slice has nowhere to land. Move it to content until there is something to sell.';
    }
    if (($ctx['list']['total'] ?? 0) < 100 && $total > 0) {
        $notes[] = 'With a list this small, paid reach should buy sign-ups rather than streams — the list is the thing that keeps paying after the money stops.';
    }
    return ['total' => $total, 'contingency' => $hold, 'rows' => $rows, 'notes' => $notes, 'in_flight' => $in_flight];
}

/**
 * Three 90-day scenarios, built from the artist's own last 28 days, and
 * whether the planned spend comes back. Pure given $ctx + $econ.
 *
 * Conservative is "nothing changes" — that's the baseline the spend has to
 * beat, so what matters is the INCREMENTAL return, not the gross.
 */
function lmeg_plan_scenarios($ctx, $spend_cents, $econ) {
    $rate = (float) ($econ['stream_rate_cents'] ?? 0);
    $aov  = (int) ($econ['aov_cents'] ?? 0);
    $s28  = (int) ($ctx['streams']['streams'] ?? 0);
    $ord90 = (int) ($econ['orders'] ?? 0);
    if ($s28 < 1 && $ord90 < 1) return null;

    $streams90 = (int) round($s28 / 28 * 90);
    $defs = [
        ['key' => 'flat',     'label' => 'If nothing changes', 'sm' => 1.00, 'om' => 1.00],
        ['key' => 'expected', 'label' => 'If the plan lands',  'sm' => 1.25, 'om' => 1.50],
        ['key' => 'strong',   'label' => 'If it really works', 'sm' => 1.60, 'om' => 2.00],
    ];
    $rows = []; $base = null;
    foreach ($defs as $d) {
        $st = (int) round($streams90 * $d['sm']);
        $or = (int) round($ord90 * $d['om']);
        $rev = (int) round($st * $rate) + $or * $aov;
        if ($base === null) $base = $rev;
        $rows[] = [
            'key' => $d['key'], 'label' => $d['label'], 'streams' => $st, 'orders' => $or,
            'revenue' => $rev, 'incremental' => $rev - $base,
            'recoups' => ($spend_cents > 0) ? (($rev - $base) >= (int) $spend_cents) : null,
        ];
    }
    $needed = [];
    if ($spend_cents > 0) {
        if ($rate > 0) $needed['streams'] = (int) round($spend_cents / $rate / 1000) * 1000;
        if ($aov > 0)  $needed['orders']  = (int) ceil($spend_cents / $aov);
    }
    return ['rows' => $rows, 'spend' => (int) $spend_cents, 'needed' => $needed,
            'window' => '90 days', 'from' => $s28 > 0 ? 'your last 28 days of streams' : 'your order history'];
}
