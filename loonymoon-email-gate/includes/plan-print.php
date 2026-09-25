<?php
/**
 * The plan as a document — /wp-admin/admin.php?page=lmeg-plan&lmeg_plan_print=1
 *
 * A light, print-ruled version of the Plan page that the artist can work from
 * on paper or save as a PDF from the browser's print dialog (no PDF library
 * ships with the plugin, and a print stylesheet renders identically in every
 * browser while staying selectable and searchable).
 *
 * Page one is the cover and the week ahead; the calendar, the full four weeks
 * with their evidence, the catalogue, the pillars and the brief follow, each
 * starting on its own sheet.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', 'lmeg_plan_print_router');
function lmeg_plan_print_router() {
    if (empty($_GET['lmeg_plan_print']) || !current_user_can('manage_options')) return;
    $demo = !empty($_GET['demo']);
    header('Content-Type: text/html; charset=utf-8');
    echo lmeg_plan_print_html($demo);
    exit;
}

function lmeg_plan_print_html($demo = false) {
    $ctx   = lmeg_plan_context($demo);
    $brief = (array) ($ctx['brief'] ?? []);
    $artist = trim((string) ($ctx['artist'] ?? '')) ?: get_bloginfo('name');

    if ($demo) {
        $moves = lmeg_plan_build($ctx);
        $rows  = lmeg_plan_fake_rows($moves);
        $weeks = [];
        for ($w = 0; $w < 4; $w++) {
            $ws = date('Y-m-d', strtotime(lmeg_plan_week_start((string) $ctx['today']) . ' +' . ($w * 7) . ' days'));
            $weeks[$ws] = ['start' => $ws, 'end' => date('Y-m-d', strtotime($ws . ' +6 days')), 'moves' => []];
        }
        foreach ($rows as $r) { $k = lmeg_plan_week_start((string) $r->due_date); if (isset($weeks[$k])) $weeks[$k]['moves'][] = $r; }
    } else {
        $weeks = lmeg_plan_weeks(4);
        $have = 0; foreach ($weeks as $w) $have += count($w['moves']);
        if (!$have) { lmeg_plan_generate(); $weeks = lmeg_plan_weeks(4); }
    }

    $ks    = array_keys($weeks);
    $first = $ks ? $weeks[$ks[0]] : null;
    $last  = $ks ? $weeks[end($ks)] : null;
    $span  = ($first && $last) ? date_i18n('F j', strtotime((string) $first['start'])) . ' – ' . date_i18n('F j, Y', strtotime((string) $last['end'])) : '';
    $roles = lmeg_plan_catalog_roles((array) ($ctx['map'] ?? []));
    $pill  = lmeg_plan_pillars($ctx);
    $goals = function_exists('lmeg_plan_brief_goals') ? lmeg_plan_brief_goals() : [];
    $goal  = (string) ($brief['goal'] ?? '');
    $st    = (array) ($ctx['stage'] ?? []);
    $stream = (array) ($ctx['streams'] ?? []);
    $list  = (array) ($ctx['list'] ?? []);
    $nf    = function ($v) { return $v === null ? '—' : number_format_i18n((int) $v); };

    $facts = [];
    if (!empty($stream['listeners'])) $facts[] = ['Monthly listeners', $nf($stream['listeners'])];
    if (!empty($stream['streams']))   $facts[] = ['Streams, 28 days', $nf($stream['streams'])];
    if (!empty($list['total']))       $facts[] = ['On your list', $nf($list['total'])];
    if (isset($list['superfans']) && $list['superfans'] !== null) $facts[] = ['Superfans', $nf($list['superfans'])];
    if (!empty($st['stage']))         $facts[] = ['Ladder stage', (int) $st['stage'] . ' of 7'];
    if ($goal !== '' && isset($goals[$goal])) $facts[] = ['Aiming at', $goals[$goal][0]];

    ob_start(); ?>
<!doctype html>
<html><head><meta charset="utf-8">
<title><?php echo esc_html($artist); ?> — marketing plan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root { --ink:#14151c; --muted:#6b6f7d; --line:#e3e1e6; --pink:#b3237c; --violet:#5b4bd6; }
  * { box-sizing:border-box; }
  body { margin:0; background:#f4f2f5; color:var(--ink);
         font:14px/1.6 'DM Sans',-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; }
  .sheet { background:#fff; width:190mm; min-height:270mm; margin:16px auto; padding:18mm 16mm 16mm; box-shadow:0 2px 18px rgba(0,0,0,.10); }
  h1 { font-size:34px; line-height:1.1; margin:0 0 6px; letter-spacing:-.02em; }
  h2 { font-size:19px; margin:0 0 4px; letter-spacing:-.01em; }
  h3 { font-size:13px; margin:22px 0 8px; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); font-weight:700; }
  p  { margin:0 0 10px; }
  .lede { font-size:15px; color:#3a3b45; max-width:150mm; }
  .rule { height:3px; background:linear-gradient(90deg,var(--pink),var(--violet)); border-radius:3px; margin:0 0 20px; width:64px; }
  .meta { font-size:12px; color:var(--muted); }
  .facts { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin:18px 0 6px; }
  .fact { border:1px solid var(--line); border-radius:10px; padding:10px 12px; }
  .fact b { display:block; font-size:19px; letter-spacing:-.01em; }
  .fact span { font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); }
  .move { border-top:1px solid var(--line); padding:11px 0; display:flex; gap:12px; page-break-inside:avoid; }
  .move .d { flex:0 0 46px; text-align:center; }
  .move .d b { display:block; font-size:16px; line-height:1; }
  .move .d span { font-size:9.5px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); }
  .move .t { font-weight:600; }
  .move .w { font-size:12.5px; color:#44454f; margin-top:3px; }
  .move .m { font-size:11.5px; color:var(--muted); margin-top:3px; }
  .tag { font-size:9.5px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; margin-left:7px; }
  .box { border:1px solid var(--line); border-radius:12px; padding:14px 16px; margin:0 0 14px; page-break-inside:avoid; }
  .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  table.cal td, table.cal th { font-size:10.5px; }
  table.data { width:100%; border-collapse:collapse; font-size:12.5px; }
  table.data th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); padding:0 8px 7px 0; }
  table.data td { border-top:1px solid var(--line); padding:8px 8px 8px 0; }
  .num { text-align:right; font-variant-numeric:tabular-nums; }
  ul.ideas { margin:8px 0 0; padding-left:17px; font-size:12.5px; color:#44454f; }
  .foot { font-size:11px; color:var(--muted); border-top:1px solid var(--line); margin-top:22px; padding-top:9px; }
  .bar { position:sticky; top:0; background:#fff; border-bottom:1px solid var(--line); padding:9px 14px; display:flex; gap:10px; align-items:center; font-size:12.5px; }
  .bar button { font:inherit; padding:5px 12px; border-radius:7px; border:1px solid var(--line); background:var(--ink); color:#fff; cursor:pointer; }
  @page { size:A4; margin:14mm; }
  @media print {
    body { background:#fff; }
    .bar { display:none; }
    .sheet { width:auto; min-height:0; margin:0; padding:0; box-shadow:none; page-break-after:always; }
    .sheet:last-child { page-break-after:auto; }
  }
</style></head>
<body>
<div class="bar">
    <button onclick="window.print()">Save as PDF</button>
    <span class="meta">Print to PDF from the dialog. Four weeks, <?php echo esc_html($span); ?>.</span>
</div>

<!-- Sheet 1: cover + the week ahead -->
<div class="sheet">
    <div class="rule"></div>
    <h1><?php echo esc_html($artist); ?></h1>
    <h2 style="font-weight:400;color:var(--muted);">Marketing plan · <?php echo esc_html($span); ?></h2>
    <p class="lede" style="margin-top:16px;">Every move in here was triggered by something already true about your audience — a song that moved, a group that went quiet, a date you gave us. The number that triggered it sits underneath it, so you can disagree with any of them for the right reasons.</p>

    <?php if ($facts) : ?>
    <div class="facts">
        <?php foreach (array_slice($facts, 0, 6) as $f) : ?>
        <div class="fact"><b><?php echo esc_html($f[1]); ?></b><span><?php echo esc_html($f[0]); ?></span></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($st['bottleneck'])) : $b = $st['bottleneck']; ?>
    <p class="meta" style="margin-top:14px;">Next gate on the ladder: <?php echo esc_html((string) ($b['label'] ?? '')); ?><?php if (!empty($b['value']) && !empty($b['target'])) : ?> — <?php echo esc_html((string) $b['value']); ?> against <?php echo esc_html((string) $b['target']); ?><?php endif; ?><?php if (!empty($b['need_label'])) : ?>. <?php echo esc_html((string) $b['need_label']); ?><?php endif; ?>.</p>
    <?php endif; ?>

    <?php
    $all_rows = [];
    foreach ($weeks as $w2) foreach ((array) $w2['moves'] as $r2) $all_rows[] = $r2;
    $cs = function_exists('lmeg_plan_cost_summary') ? lmeg_plan_cost_summary($all_rows, $brief) : null;
    $ec = function_exists('lmeg_plan_economics') ? lmeg_plan_economics() : [];
    ?>
    <?php if ($cs && !$cs['free']) : ?>
    <div class="box" style="margin-top:16px;">
        <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:baseline;">
            <div><div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);">Four weeks, typical</div>
                 <div style="font-size:22px;font-weight:700;"><?php echo esc_html(lmeg_plan_money($cs['typical'])); ?></div></div>
            <div><div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);">Range</div>
                 <div style="font-size:14px;"><?php echo esc_html(lmeg_plan_money($cs['low'])); ?> – <?php echo esc_html(lmeg_plan_money($cs['high'])); ?></div></div>
            <?php if ($cs['budget_window'] !== null) : ?>
            <div><div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);">Your budget for this stretch</div>
                 <div style="font-size:14px;<?php echo $cs['over'] ? 'color:#a15c00;' : ''; ?>"><?php echo esc_html(lmeg_plan_money($cs['budget_window'])); ?><?php echo $cs['over'] ? ' — the plan asks for more' : ''; ?></div></div>
            <?php endif; ?>
            <?php if (!empty($ec['aov_cents'])) : ?>
            <div><div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);">Your average order</div>
                 <div style="font-size:14px;"><?php echo esc_html(lmeg_plan_money((int) $ec['aov_cents'])); ?></div></div>
            <?php endif; ?>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:9px;"><?php echo (int) $cs['priced']; ?> of <?php echo count($all_rows); ?> moves can cost money; the rest are your list, your catalogue and your time. Streams valued at <?php echo esc_html(lmeg_plan_money((float) ($ec['stream_rate_cents'] ?? 0) * 1000)); ?> per thousand.</div>
    </div>
    <?php endif; ?>

    <h3>This week · <?php echo esc_html(date_i18n('M j', strtotime((string) $first['start'])) . ' – ' . date_i18n('M j', strtotime((string) $first['end']))); ?></h3>
    <?php echo lmeg_plan_print_moves((array) $first['moves']); ?>
    <div class="foot">Nothing in this plan sends by itself. Fanloop · <?php echo esc_html(date_i18n('M j, Y')); ?></div>
</div>

<!-- Sheet 2: the calendar -->
<div class="sheet">
    <h2>The calendar</h2>
    <p class="meta">Posts and sends together, four weeks. <?php echo ($brief['posts_per_week'] ?? '') === '' ? 'Two content slots a week until you tell the brief otherwise.' : (int) $brief['posts_per_week'] . ' content slots a week, as you asked.'; ?></p>
    <div style="margin-top:14px;" class="cal"><?php echo lmeg_plan_calendar_html($weeks, true); ?></div>
    <?php echo lmeg_plan_legend_html(true); ?>
</div>

<!-- Sheet 2b: the money -->
<?php
$split = function_exists('lmeg_plan_budget_split') ? lmeg_plan_budget_split($brief) : null;
$shape = ($split && $split['total'] > 0 && function_exists('lmeg_plan_budget_shape')) ? lmeg_plan_budget_shape($split['total'], $ctx) : null;
$scen  = function_exists('lmeg_plan_scenarios') ? lmeg_plan_scenarios($ctx, $split ? $split['out_of_pocket'] : (($cs ?? null) ? $cs['typical'] : 0), $ec) : null;
?>
<?php if ($shape || $scen) : ?>
<div class="sheet">
    <h2>The money</h2>
    <?php if ($shape) : ?>
    <p class="meta">An allocation, not a total — weighted for stage <?php echo (int) ($st['stage'] ?? 0); ?><?php if ($goal !== '') echo ', the goal you named'; ?><?php if (!empty($shape['in_flight'])) echo ', and a release inside six weeks'; ?>.</p>
    <div class="facts" style="grid-template-columns:repeat(4,1fr);">
        <div class="fact"><b><?php echo esc_html(lmeg_plan_money($split['total'])); ?></b><span>Budget, 90 days</span></div>
        <?php if ($split['funding'] > 0) : ?>
        <div class="fact"><b>−<?php echo esc_html(lmeg_plan_money($split['funding'])); ?></b><span>Grant money (<?php echo (int) $split['funded_pct']; ?>%)</span></div>
        <div class="fact"><b><?php echo esc_html(lmeg_plan_money($split['out_of_pocket'])); ?></b><span>Out of your account</span></div>
        <?php endif; ?>
        <div class="fact"><b><?php echo esc_html(lmeg_plan_money($shape['contingency'])); ?></b><span>Held back</span></div>
    </div>
    <table class="data" style="margin-top:16px;">
        <thead><tr><th>Category</th><th class="num">Amount</th><th class="num">Share</th><th>What it's for</th></tr></thead>
        <tbody>
        <?php foreach ($shape['rows'] as $r3) : ?>
            <tr>
                <td style="font-weight:600;white-space:nowrap;"><?php echo esc_html($r3['label']); ?></td>
                <td class="num"><?php echo esc_html(lmeg_plan_money($r3['cents'])); ?></td>
                <td class="num"><?php echo (int) $r3['pct']; ?>%</td>
                <td><?php echo esc_html($r3['desc']); ?><?php if (!empty($r3['why'])) : ?> — <?php echo esc_html($r3['why']); ?><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php foreach ($shape['notes'] as $nt) : ?>
    <p style="font-size:12px;color:#a15c00;margin-top:10px;"><?php echo esc_html($nt); ?></p>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($scen) : ?>
    <h3 style="margin-top:<?php echo $shape ? '26px' : '6px'; ?>;">Does it come back?</h3>
    <p class="meta">Ninety days projected from <?php echo esc_html($scen['from']); ?>. The first row is the do-nothing baseline, so the column that matters is what each case adds on top of it.</p>
    <table class="data" style="margin-top:12px;">
        <thead><tr><th>Case</th><th class="num">Streams</th><th class="num">Orders</th><th class="num">Revenue</th><th class="num">On top</th><th>Against <?php echo esc_html(lmeg_plan_money($scen['spend'])); ?></th></tr></thead>
        <tbody>
        <?php foreach ($scen['rows'] as $r4) : ?>
            <tr>
                <td style="font-weight:600;"><?php echo esc_html($r4['label']); ?></td>
                <td class="num"><?php echo esc_html(number_format_i18n((int) $r4['streams'])); ?></td>
                <td class="num"><?php echo esc_html(number_format_i18n((int) $r4['orders'])); ?></td>
                <td class="num"><?php echo esc_html(lmeg_plan_money((int) $r4['revenue'])); ?></td>
                <td class="num"><?php echo $r4['incremental'] > 0 ? '+' . esc_html(lmeg_plan_money((int) $r4['incremental'])) : '—'; ?></td>
                <td><?php
                    if ($r4['recoups'] === null) echo 'nothing to recoup';
                    elseif ($r4['key'] === 'flat') echo 'the case where the money was wasted';
                    else echo $r4['recoups'] ? 'pays for itself' : 'still short';
                ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (!empty($scen['needed'])) : ?>
    <p class="meta" style="margin-top:10px;">Break-even needs <?php
        $bits = [];
        if (!empty($scen['needed']['streams'])) $bits[] = number_format_i18n((int) $scen['needed']['streams']) . ' extra streams';
        if (!empty($scen['needed']['orders'])) $bits[] = (int) $scen['needed']['orders'] . ' extra orders';
        echo esc_html(implode(', or ', $bits));
    ?>.</p>
    <?php endif; ?>
    <?php endif; ?>
    <div class="foot">Prices are typical independent rates, not quotes. Streams are valued at <?php echo esc_html(lmeg_plan_money((float) ($ec['stream_rate_cents'] ?? 0) * 1000)); ?> per thousand, which a manager can set per artist.</div>
</div>
<?php endif; ?>

<!-- Sheet 3: the four weeks in full -->
<div class="sheet">
    <h2>Week by week</h2>
    <p class="meta">With the evidence behind each move, and what to watch afterwards.</p>
    <?php foreach ($weeks as $w) : ?>
    <h3><?php echo esc_html(date_i18n('M j', strtotime((string) $w['start'])) . ' – ' . date_i18n('M j', strtotime((string) $w['end']))); ?></h3>
    <?php echo count($w['moves']) ? lmeg_plan_print_moves((array) $w['moves'], true) : '<p class="meta">Open week — keep it for the work the plan can\'t see.</p>'; ?>
    <?php endforeach; ?>
</div>

<!-- Sheet 4: catalogue + pillars -->
<div class="sheet">
    <?php if ($roles) : ?>
    <h2>Every song's job this cycle</h2>
    <p class="meta">From each song's own daily streams over the last fourteen days.</p>
    <table class="data" style="margin-top:12px;">
        <thead><tr><th>Song</th><th>Role</th><th class="num">7 days</th><th class="num">Week on week</th><th>What it's for</th></tr></thead>
        <tbody>
        <?php foreach ($roles as $r) : ?>
            <tr>
                <td style="font-weight:600;"><?php echo esc_html($r['title']); ?></td>
                <td style="white-space:nowrap;"><?php echo esc_html($r['role']); ?></td>
                <td class="num"><?php echo esc_html(number_format_i18n((int) $r['last7'])); ?></td>
                <td class="num"><?php echo $r['wow'] === null ? '—' : esc_html(($r['wow'] > 0 ? '+' : '') . $r['wow'] . '%'); ?></td>
                <td><?php echo esc_html($r['job']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($pill) : ?>
    <h3 style="margin-top:26px;">What to make more of</h3>
    <div class="grid2">
        <?php foreach ($pill as $p) : ?>
        <div class="box">
            <div style="font-weight:600;"><?php echo esc_html((string) $p['label']); ?></div>
            <div style="font-size:12.5px;color:#44454f;margin-top:5px;"><?php echo esc_html((string) $p['why']); ?></div>
            <?php if (!empty($p['ideas'])) : ?>
            <ul class="ideas"><?php foreach ((array) $p['ideas'] as $i) : ?><li><?php echo esc_html((string) $i); ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h3 style="margin-top:26px;">What this plan assumed</h3>
    <div class="box">
        <table class="data">
            <tbody>
            <tr><td style="width:44%;color:var(--muted);">The next 90 days are for</td><td><?php echo esc_html($goal !== '' && isset($goals[$goal]) ? $goals[$goal][0] : 'not stated yet'); ?></td></tr>
            <tr><td style="color:var(--muted);">Posts a week you can manage</td><td><?php echo ($brief['posts_per_week'] ?? '') === '' ? 'not stated (assumed 2)' : esc_html((string) $brief['posts_per_week']); ?></td></tr>
            <tr><td style="color:var(--muted);">Emails a month you're good with</td><td><?php echo ($brief['sends_per_month'] ?? '') === '' ? 'not stated (assumed 8)' : esc_html((string) $brief['sends_per_month']); ?></td></tr>
            <tr><td style="color:var(--muted);">Video</td><td><?php echo (string) ($brief['video_ok'] ?? '') === '1' ? 'yes' : ((string) ($brief['video_ok'] ?? '') === '0' ? 'no video' : 'not stated'); ?></td></tr>
            <tr><td style="color:var(--muted);">Won't do</td><td><?php echo trim((string) ($brief['wont_do'] ?? '')) === '' ? 'nothing ruled out' : esc_html((string) $brief['wont_do']); ?></td></tr>
            <tr><td style="color:var(--muted);">Budget for 90 days</td><td><?php $bl = function_exists('lmeg_plan_brief_budgets') ? lmeg_plan_brief_budgets() : []; echo esc_html($bl[(string) ($brief['budget'] ?? '')] ?? 'not stated'); ?></td></tr>
            <tr><td style="color:var(--muted);">Cities you can reach</td><td><?php echo trim((string) ($brief['cities'] ?? '')) === '' ? 'not stated' : esc_html((string) $brief['cities']); ?></td></tr>
            <tr><td style="color:var(--muted);">Chasing placements</td><td><?php echo (string) ($brief['sync'] ?? '') === '1' ? 'yes' : ((string) ($brief['sync'] ?? '') === '0' ? 'not now' : 'not stated'); ?></td></tr>
            <?php if (!empty($brief['known_for'])) : ?>
            <tr><td style="color:var(--muted);">What fans come for</td><td><?php echo esc_html((string) $brief['known_for']); ?></td></tr>
            <?php endif; ?>
            <?php foreach ((array) ($brief['dates'] ?? []) as $d) : if (empty($d['date'])) continue; $types = function_exists('lmeg_plan_brief_date_types') ? lmeg_plan_brief_date_types() : []; ?>
            <tr><td style="color:var(--muted);"><?php echo esc_html($types[(string) ($d['type'] ?? '')] ?? 'Date'); ?></td><td><?php echo esc_html(trim((string) ($d['name'] ?? '')) ?: '—'); ?> · <?php echo esc_html(date_i18n('M j, Y', strtotime((string) $d['date']))); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="foot">Change any assumption in Fanloop → Plan brief and the plan rebuilds around it.</div>
</div>
</body></html>
    <?php return ob_get_clean();
}

/** The move list, print-styled. */
function lmeg_plan_print_moves($moves, $compact = false) {
    if (!$moves) return '<p class="meta">Nothing scheduled.</p>';
    $out = '';
    foreach ($moves as $r) {
        list($cl, $tone) = lmeg_plan_channel($r->channel ?? '');
        $done = (string) ($r->status ?? '') === 'done';
        $skip = (string) ($r->status ?? '') === 'skipped';
        $out .= '<div class="move"' . ($done || $skip ? ' style="opacity:.5;"' : '') . '>'
              . '<div class="d"><b>' . esc_html(date_i18n('j', strtotime((string) $r->due_date))) . '</b><span>' . esc_html(date_i18n('M', strtotime((string) $r->due_date))) . '</span></div>'
              . '<div><div class="t"' . ($done ? ' style="text-decoration:line-through;"' : '') . '>' . esc_html((string) $r->title)
              . '<span class="tag" style="color:' . $tone . ';">' . esc_html($cl) . '</span>'
              . ($done ? '<span class="tag" style="color:#1c8f5f;">Done</span>' : '')
              . ($skip ? '<span class="tag" style="color:#8a8e9b;">Skipped</span>' : '')
              . '</div>';
        if (!empty($r->why))    $out .= '<div class="w">' . esc_html((string) $r->why) . '</div>';
        if (!$compact && !empty($r->metric)) $out .= '<div class="m">Watch: ' . esc_html((string) $r->metric) . '</div>';
        if (!empty($r->cost_cents) && function_exists('lmeg_plan_money')) {
            $out .= '<div class="m">Typically ' . esc_html(lmeg_plan_money((int) $r->cost_cents)) . (!empty($r->breakeven) ? ' · ' . esc_html((string) $r->breakeven) : '') . '</div>';
        }
        $out .= '</div></div>';
    }
    return $out;
}
