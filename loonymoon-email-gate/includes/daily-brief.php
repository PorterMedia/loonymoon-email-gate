<?php
/**
 * Daily brief — one email every morning for any site with Spotify for Artists
 * attached (LOONY, Geoffroy today). Built from the same data the Spotify
 * Insights page shows and laid out to match it: yesterday's streams up top
 * with a 14-day bar strip, the five fan rings, the songs that moved, Fanloop's
 * top findings with their one-click actions, playlist pickups, the last send's
 * lift, a fresh launch and where they listen. Dark Fanloop branding, email-safe
 * (tables + inline styles, hex fallbacks under every gradient).
 *
 * Sends once per site-day, not before 9am and only after that day's capture
 * has landed (the 9am Spotify for Artists pull) — never a stale brief. Settings → "Daily brief"
 * toggles it and sets the address; blank falls back to the digest address,
 * then the site admin email. Insights page: "Email me today's brief" +
 * "Preview in browser" (?lmeg_brief_preview=1, &demo=1 for sample data).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Everything the brief renders from, as one plain array — so the renderer is
 * pure and a stubbed-WP harness can build the email from demo rows. null when
 * this site has no Spotify for Artists snapshot.
 */
function lmeg_si_brief_data($demo = false) {
    if (!function_exists('lmeg_si_quick_context')) return null;
    $q = lmeg_si_quick_context($demo);
    if (!$q) return null;
    $sel = $q['sel']; $snap = $q['snap']; $prev = $q['prev']; $meta = $q['meta']; $map = $q['map']; $sw = $q['sw'];
    $daily = $q['daily']; $launch = $q['launch']; $ctx = $q['ctx']; $sp = $q['sp']; $mlp = $q['mlp'];
    $now = current_time('timestamp');

    $d = ['artist' => (string) $sel, 'site' => get_bloginfo('name'), 'demo' => (bool) $demo, 'captured' => (string) $snap->captured_date];
    $d['fresh_days'] = max(0, (int) floor(($now - strtotime($snap->captured_date . ' 12:00:00')) / 86400));
    $d['today_label'] = date_i18n('D, M j', $now);

    // Yesterday — the last complete day of the 28-day daily series (the capture
    // day itself was trimmed by the parser). vs the day before, vs the 7-day avg.
    $ds = array_values(array_map('intval', (array) ($daily['streams'] ?? [])));
    $dd = array_values((array) ($daily['dates'] ?? []));
    $n  = count($ds);
    $y  = null;
    if ($n >= 2 && count($dd) === $n) {
        $last = $ds[$n - 1]; $before = $ds[$n - 2];
        $avg7 = $n >= 8 ? array_sum(array_slice($ds, -8, 7)) / 7 : null;
        $win  = array_slice($ds, -14);
        $y = [
            'date'      => (string) $dd[$n - 1],
            'label'     => date_i18n('l, M j', strtotime($dd[$n - 1])),
            'streams'   => $last,
            'vs_prev'   => $before > 0 ? round(($last - $before) / $before * 100, 1) : null,
            'vs_avg7'   => $avg7 > 0 ? round(($last - $avg7) / $avg7 * 100, 1) : null,
            'avg7'      => $avg7 !== null ? (int) round($avg7) : null,
            'listeners' => isset($daily['listeners'][$n - 1]) ? (int) $daily['listeners'][$n - 1] : null,
            'saves'     => isset($daily['saves'][$n - 1]) ? (int) $daily['saves'][$n - 1] : null,
            'best14'    => $last >= max($win),
        ];
    }
    $d['yesterday'] = $y;
    $d['series'] = ['dates' => array_slice($dd, -14), 'streams' => array_slice($ds, -14)];

    // 28-day picture + followers level.
    $fs = array_values(array_filter(array_map('intval', (array) ($daily['followers'] ?? []))));
    $d['k28'] = [
        'streams' => (int) $snap->streams, 'streams_pct' => $sp,
        'listeners' => (int) $snap->monthly_listeners, 'listeners_pct' => $mlp,
        'followers' => $fs ? (int) end($fs) : null, 'followers_delta' => count($fs) >= 7 ? (int) end($fs) - (int) $fs[0] : null,
        'saves' => $snap->saves !== null ? (int) $snap->saves : null,
        'prev_date' => $prev ? (string) $prev->captured_date : null,
    ];

    // Five rings — same numbers as the Insights strip (demo uses the page's sample).
    $ov = $demo ? (function_exists('lmeg_si_demo_overview') ? lmeg_si_demo_overview($sel) : null)
                : (function_exists('lmeg_spotify_overview') ? lmeg_spotify_overview() : null);
    if (function_exists('is_wp_error') && is_wp_error($ov)) $ov = null;
    $rings_raw = null;
    $demo_raw  = ['listeners' => (int) $snap->monthly_listeners, 'listeners_pct' => $mlp, 'sp_followers' => 8240, 'sp_followers_delta' => 162, 'ig_followers' => 12480, 'ig_followers_delta' => 310, 'list' => 2140, 'superfans' => 96, 'list_new' => 184, 'customers' => 312, 'customers_new' => 27, 'members' => 41];
    $d['rings'] = $demo ? lmeg_si_fan_rings_shape($demo_raw) : lmeg_si_fan_rings_data($snap, $ov, is_array($ov), ['monthly_listeners' => $mlp], $rings_raw);
    // Stage ladder — same inputs as the Insights card.
    $d['stage'] = function_exists('lmeg_si_stage')
        ? lmeg_si_stage($demo ? $demo_raw : (is_array($rings_raw) ? $rings_raw : []), lmeg_si_stage_extra($snap, $ov, ['streams' => $sp], $demo))
        : null;
    if ($d['stage'] && !empty($d['stage']['bottleneck'])) {
        foreach ($d['stage']['bottleneck']['actions'] as &$a) $a['href'] = lmeg_si_stage_action_href($a);
        unset($a);
    }
    // The ladder's nudges (quiet list, almost there) — same items as the Overview.
    $d['stage_nudges'] = ($d['stage'] && function_exists('lmeg_si_stage_attention')) ? lmeg_si_stage_attention($d['stage']) : [];
    // Since yesterday: today's ladder reading vs the one before (the history
    // tick runs ahead of the brief tick). Sample history in demo.
    $d['stage_delta'] = null;
    if ($d['stage'] && function_exists('lmeg_si_stage_log_delta')) {
        $log = $demo ? (function_exists('lmeg_si_stage_demo_log') ? lmeg_si_stage_demo_log() : []) : (function_exists('lmeg_si_stage_log_get') ? lmeg_si_stage_log_get() : []);
        $d['stage_delta'] = $log ? lmeg_si_stage_log_delta($log) : null;
    }

    // Songs — top 5 by last-7-day streams from the real day-by-day data, else
    // the 28-day list. Each carries its own 14-day mini series.
    $songs = [];
    if ($map) {
        foreach ($map as $e) {
            $v = array_values(array_map('intval', (array) $e['s']));
            $songs[] = ['title' => (string) $e['t'], 'streams' => array_sum(array_slice($v, -7)), 'wow' => lmeg_si_week_over_week($v), 'spark' => array_slice($v, -14)];
        }
        usort($songs, function ($a, $b) { return $b['streams'] <=> $a['streams']; });
        $d['songs_basis'] = 'last 7 days';
    } else {
        foreach (array_values(array_filter((array) json_decode((string) $snap->top_songs, true), 'is_array')) as $s) {
            $songs[] = ['title' => (string) ($s['title'] ?? ''), 'streams' => (int) ($s['streams'] ?? 0), 'wow' => null, 'spark' => []];
        }
        $d['songs_basis'] = 'last 28 days';
    }
    $songs = array_slice($songs, 0, 5);
    $mx = 0; foreach ($songs as $s) $mx = max($mx, (int) $s['streams']);
    foreach ($songs as &$s) $s['share'] = $mx > 0 ? round($s['streams'] / $mx * 100) : 0;
    unset($s);
    $d['songs'] = $songs;
    $d['mover']  = ($sw && !empty($sw['up']))   ? $sw['up'][0]   : null;
    $d['cooled'] = ($sw && !empty($sw['down'])) ? $sw['down'][0] : null;

    // Findings — the top 3 from Fanloop's engine, each with up to two actions
    // resolved to absolute links (the email has no admin context).
    $F = array_slice(lmeg_si_analyze($ctx), 0, 3);
    foreach ($F as &$f) {
        $acts = [];
        foreach (array_slice(lmeg_si_finding_actions($f), 0, 2) as $a) {
            $acts[] = ['label' => (string) $a['label'], 'href' => !empty($a['href']) ? $a['href'] : admin_url('admin.php?' . http_build_query(array_merge(['page' => $a['page']], (array) ($a['args'] ?? []))))];
        }
        $f['actions'] = $acts;
    }
    unset($f);
    $d['findings'] = $F;

    // Also today: playlist pickups / drops, the last send's lift, a fresh launch.
    $pd = $ctx['playlist_diff'] ?? null;
    $d['pickups'] = (is_array($pd) && !empty($pd['new']))  ? array_slice($pd['new'], 0, 3)  : [];
    $d['dropped'] = (is_array($pd) && !empty($pd['gone'])) ? array_slice($pd['gone'], 0, 2) : [];
    $marks = $demo ? (function_exists('lmeg_si_demo_marks') ? lmeg_si_demo_marks() : []) : (function_exists('lmeg_si_campaign_marks') ? lmeg_si_campaign_marks(14) : []);
    $lift  = ($dd && $marks) ? lmeg_si_campaign_lift($dd, $ds, $marks) : [];
    $d['lift']   = $lift ? end($lift) : null;
    $d['launch'] = ($launch && (int) $launch[0]['days'] <= 35) ? $launch[0] : null;

    // Share = of THIS artist's monthly listeners (S4A's own pct field is a
    // fraction of a different base and reads as "0.5%" for a 53% market).
    $cty = lmeg_si_country_rows((array) ($meta['countries'] ?? []), 5);
    $ml  = (int) $snap->monthly_listeners;
    foreach ($cty as &$c) $c['pct'] = $ml > 0 ? round($c['num'] / $ml * 100, 1) : null;
    unset($c);
    $d['countries'] = $cty;

    $d['url_insights'] = admin_url('admin.php?page=lmeg-spotify-insights');
    $d['url_stage']    = admin_url('admin.php?page=lmeg-ladder');
    $d['url_settings'] = admin_url('admin.php?page=lmeg-settings');
    return $d;
}

/**
 * Email-safe horizontal bar: a two-cell table whose first cell is $pct% wide
 * with a bgcolor + height ATTRIBUTE (divs with widths/heights get flattened by
 * Gmail and Outlook; table cells don't). Pure.
 */
function lmeg_si_brief_bar($pct, $fill, $track, $h = 6, $mt = 0) {
    $pct = max(2, min(100, (int) $pct)); $rest = 100 - $pct;
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="' . ($mt ? 'margin-top:' . (int) $mt . 'px;' : '') . 'border-collapse:collapse;"><tr>'
         . '<td width="' . $pct . '%" height="' . (int) $h . '" bgcolor="' . $fill . '" style="width:' . $pct . '%;height:' . (int) $h . 'px;background-color:' . $fill . ';font-size:0;line-height:0;mso-line-height-rule:exactly;border-radius:4px;">&nbsp;</td>'
         . ($rest > 0 ? '<td width="' . $rest . '%" height="' . (int) $h . '" bgcolor="' . $track . '" style="width:' . $rest . '%;height:' . (int) $h . 'px;background-color:' . $track . ';font-size:0;line-height:0;mso-line-height-rule:exactly;">&nbsp;</td>' : '')
         . '</tr></table>';
}

/** Signed percent string: "+3.1%" / "−2%" / "0%". */
function lmeg_si_brief_pct($v, $dp = 1) {
    if ($v === null || $v === '') return '';
    $v = (float) $v;
    $s = rtrim(rtrim(number_format(abs($v), $dp), '0'), '.');
    return ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . $s . '%';
}

/**
 * The email body. Pure: takes the array from lmeg_si_brief_data. Tables +
 * inline styles only; every gradient sits on a hex background-color so
 * clients that drop background-image still read.
 */
function lmeg_si_brief_html($d) {
    $F = "-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $BG = '#0E0F16'; $CARD = '#161826'; $CARD2 = '#1C1F2E'; $BORDER = '#2A2E42'; $TEXT = '#F4F5F7'; $SOFT = '#C9CCD6'; $MUTED = '#8B90A0';
    $PINK = '#D05FA2'; $VIOLET = '#7C6CF6'; $GREEN = '#34D399'; $AMBER = '#F59E0B'; $RED = '#F87171';
    $h = function ($s) { return esc_html((string) $s); };
    $n = function ($v) { return number_format_i18n((int) $v); };
    $card  = 'background-color:' . $CARD . ';background-image:linear-gradient(160deg,' . $CARD . ',' . $CARD2 . ');border:1px solid ' . $BORDER . ';border-radius:14px;';
    $lbl   = 'font-family:' . $F . ';font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:' . $MUTED . ';';
    $chip  = function ($pct, $suffix) use ($GREEN, $RED, $MUTED, $F) {
        if ($pct === null || $pct === '') return '';
        $c = (float) $pct > 0 ? $GREEN : ((float) $pct < 0 ? $RED : $MUTED);
        return '<span style="display:inline-block;font-family:' . $F . ';font-size:12px;font-weight:700;color:' . $c . ';background-color:#0E0F16;border:1px solid ' . $c . ';border-radius:999px;padding:3px 9px;margin:0 6px 6px 0;white-space:nowrap;">' . lmeg_si_brief_pct($pct) . ' <span style="font-weight:500;color:#C9CCD6;">' . esc_html($suffix) . '</span></span>';
    };
    $pill = function ($label, $href) use ($F, $TEXT) {
        return '<a href="' . esc_url($href) . '" style="display:inline-block;font-family:' . $F . ';font-size:11px;font-weight:700;color:' . $TEXT . ';background-color:#262A3D;border:1px solid #3A3F57;border-radius:999px;padding:5px 11px;text-decoration:none;margin:6px 6px 0 0;">' . esc_html($label) . '</a>';
    };
    $sec = function ($title, $inner, $note = '') use ($card, $lbl, $F, $MUTED) {
        return '<tr><td style="padding:0 0 14px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="' . $card . '"><tr><td class="lmeg-card-pad" style="padding:16px 18px;">'
             . '<div style="' . $lbl . 'margin-bottom:' . ($note ? '2' : '10') . 'px;">' . $title . '</div>'
             . ($note ? '<div style="font-family:' . $F . ';font-size:12px;color:' . $MUTED . ';margin-bottom:10px;">' . $note . '</div>' : '')
             . $inner . '</td></tr></table></td></tr>';
    };

    $y = $d['yesterday']; $k = $d['k28'];
    $fresh = (int) $d['fresh_days'];
    $fc = $fresh <= 1 ? $GREEN : ($fresh <= 3 ? $AMBER : $RED);
    $ft = $d['demo'] ? 'Sample data' : ($fresh <= 0 ? 'Updated today' : ($fresh === 1 ? 'Updated yesterday' : 'Updated ' . $fresh . ' days ago'));
    if ($d['demo']) $fc = $VIOLET;

    ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">
<title><?php echo $h(lmeg_si_brief_subject($d)); ?></title>
<style>
  /* Mobile: rings + the 28-day strip stack into one column, chevrons hide,
     the outer padding tightens. Gmail/Apple Mail/iOS honour head styles. */
  @media only screen and (max-width:600px) {
    .lmeg-wrap { padding:16px 8px !important; }
    .lmeg-ring { display:block !important; width:100% !important; padding:0 0 8px !important; }
    .lmeg-ring .lmeg-ring-v { font-size:24px !important; }
    .lmeg-ring .lmeg-ring-l { font-size:11px !important; }
    .lmeg-ring .lmeg-ring-s { font-size:12px !important; }
    .lmeg-chev { display:none !important; }
    .lmeg-col { display:block !important; width:100% !important; border-left:0 !important; padding:10px 0 0 !important; }
    .lmeg-hero-n { font-size:36px !important; }
    .lmeg-card-pad { padding:14px 14px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background-color:<?php echo $BG; ?>;">
<div style="margin:0;padding:0;background-color:<?php echo $BG; ?>;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:<?php echo $BG; ?>;">
<tr><td align="center" class="lmeg-wrap" style="padding:28px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

  <!-- header -->
  <tr><td style="padding:0 4px 16px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
      <td style="font-family:<?php echo $F; ?>;font-size:18px;font-weight:800;color:<?php echo $TEXT; ?>;letter-spacing:-.01em;vertical-align:middle;">
        <span style="display:inline-block;width:11px;height:11px;border-radius:50%;background-color:<?php echo $PINK; ?>;background-image:linear-gradient(135deg,<?php echo $PINK; ?>,<?php echo $VIOLET; ?>);vertical-align:middle;margin:-2px 8px 0 0;"></span>Fanloop
        <span style="font-weight:600;color:<?php echo $MUTED; ?>;">· Daily brief</span>
      </td>
      <td align="right" style="font-family:<?php echo $F; ?>;font-size:12px;color:<?php echo $MUTED; ?>;vertical-align:middle;white-space:nowrap;padding-left:12px;">
        <?php echo $h($d['today_label']); ?> &nbsp;<span style="display:inline-block;color:<?php echo $fc; ?>;border:1px solid <?php echo $fc; ?>;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;"><?php echo $h($ft); ?></span>
      </td>
    </tr></table>
  </td></tr>

  <!-- hero: yesterday -->
  <tr><td style="padding:0 0 14px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#221F3A;background-image:linear-gradient(120deg,rgba(208,95,162,.22),rgba(124,108,246,.22)),linear-gradient(160deg,<?php echo $CARD; ?>,<?php echo $CARD2; ?>);border:1px solid #3A3556;border-radius:14px;">
    <tr><td style="padding:20px 20px 16px;">
      <div style="<?php echo $lbl; ?>color:#C9CCD6;"><?php echo $h($d['artist']); ?> · <?php echo $y ? 'Yesterday · ' . $h($y['label']) : 'Last 28 days'; ?></div>
      <?php if ($y) : ?>
      <div class="lmeg-hero-n" style="font-family:<?php echo $F; ?>;font-size:44px;line-height:1.05;font-weight:800;color:<?php echo $TEXT; ?>;margin:8px 0 2px;"><?php echo $n($y['streams']); ?></div>
      <div style="font-family:<?php echo $F; ?>;font-size:14px;color:#C9CCD6;margin-bottom:10px;">streams yesterday<?php if ($y['listeners']) echo ' · ' . $n($y['listeners']) . ' listeners'; ?><?php if ($y['saves']) echo ' · ' . $n($y['saves']) . ' saves'; ?><?php if ($y['best14']) echo ' · <span style="color:' . $PINK . ';font-weight:700;">best day in two weeks</span>'; ?></div>
      <div><?php echo $chip($y['vs_prev'], 'vs the day before') . $chip($y['vs_avg7'], 'vs your 7-day average' . ($y['avg7'] ? ' (' . $n($y['avg7']) . ')' : '')); ?></div>
      <?php else : ?>
      <div style="font-family:<?php echo $F; ?>;font-size:44px;line-height:1.05;font-weight:800;color:<?php echo $TEXT; ?>;margin:8px 0 2px;"><?php echo $n($k['streams']); ?></div>
      <div style="font-family:<?php echo $F; ?>;font-size:14px;color:#C9CCD6;margin-bottom:10px;">streams in the last 28 days</div>
      <div><?php echo $chip($k['streams_pct'], 'vs the 28 days before'); ?></div>
      <?php endif; ?>

      <?php $sv = $d['series']['streams']; $sd = $d['series']['dates']; $cnt = count($sv); if ($cnt >= 5) : $smx = max(1, max($sv)); $BH = 60; ?>
      <!-- 14-day bars: each bar is a spacer cell + a coloured cell with HTML height
           attributes (Gmail/Outlook drop div heights and paint one solid block). -->
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;">
        <tr>
        <?php foreach ($sv as $i => $v) : $hh = max(3, (int) round($v / $smx * ($BH - 4))); $sp = $BH - $hh; $isLast = $i === $cnt - 1; $col = $isLast ? $PINK : '#2FBF8A'; ?>
          <td valign="bottom" width="<?php echo (int) floor(100 / $cnt); ?>%" style="padding:0 2px;vertical-align:bottom;" title="<?php echo $h(($sd[$i] ?? '') . ' · ' . $n($v)); ?>">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-bar="<?php echo (int) $hh; ?>">
              <tr><td height="<?php echo (int) $sp; ?>" style="height:<?php echo (int) $sp; ?>px;font-size:0;line-height:0;mso-line-height-rule:exactly;">&nbsp;</td></tr>
              <tr><td height="<?php echo (int) $hh; ?>" bgcolor="<?php echo $col; ?>" style="height:<?php echo (int) $hh; ?>px;background-color:<?php echo $col; ?>;font-size:0;line-height:0;mso-line-height-rule:exactly;border-radius:4px 4px 0 0;">&nbsp;</td></tr>
            </table>
          </td>
        <?php endforeach; ?>
        </tr>
        <tr>
          <td colspan="<?php echo $cnt; ?>" style="padding-top:6px;border-top:1px solid #3A3556;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
              <td style="font-family:<?php echo $F; ?>;font-size:11px;color:<?php echo $MUTED; ?>;"><?php echo $h(date_i18n('M j', strtotime($sd[0] ?? 'now'))); ?></td>
              <td align="center" style="font-family:<?php echo $F; ?>;font-size:11px;color:<?php echo $MUTED; ?>;">streams · last <?php echo $cnt; ?> days</td>
              <td align="right" style="font-family:<?php echo $F; ?>;font-size:11px;color:<?php echo $PINK; ?>;font-weight:700;"><?php echo $h(date_i18n('M j', strtotime($sd[$cnt - 1] ?? 'now'))); ?></td>
            </tr></table>
          </td>
        </tr>
      </table>
      <?php endif; ?>

      <!-- 28-day strip -->
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;border-top:1px solid #3A3556;">
        <tr>
          <td width="33%" class="lmeg-col" style="padding:12px 8px 0 0;font-family:<?php echo $F; ?>;">
            <div style="font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:<?php echo $MUTED; ?>;">28-day streams</div>
            <div style="font-size:18px;font-weight:800;color:<?php echo $TEXT; ?>;margin-top:3px;"><?php echo $n($k['streams']); ?> <span style="font-size:12px;font-weight:700;color:<?php echo ($k['streams_pct'] ?? 0) > 0 ? $GREEN : (($k['streams_pct'] ?? 0) < 0 ? $RED : $MUTED); ?>;"><?php echo $h(lmeg_si_brief_pct($k['streams_pct'])); ?></span></div>
          </td>
          <td width="34%" class="lmeg-col" style="padding:12px 8px 0;font-family:<?php echo $F; ?>;border-left:1px solid #3A3556;">
            <div style="font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:<?php echo $MUTED; ?>;">Monthly listeners</div>
            <div style="font-size:18px;font-weight:800;color:<?php echo $TEXT; ?>;margin-top:3px;"><?php echo $n($k['listeners']); ?> <span style="font-size:12px;font-weight:700;color:<?php echo ($k['listeners_pct'] ?? 0) > 0 ? $GREEN : (($k['listeners_pct'] ?? 0) < 0 ? $RED : $MUTED); ?>;"><?php echo $h(lmeg_si_brief_pct($k['listeners_pct'])); ?></span></div>
          </td>
          <td width="33%" class="lmeg-col" style="padding:12px 0 0 8px;font-family:<?php echo $F; ?>;border-left:1px solid #3A3556;">
            <div style="font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:<?php echo $MUTED; ?>;">Spotify followers</div>
            <div style="font-size:18px;font-weight:800;color:<?php echo $TEXT; ?>;margin-top:3px;"><?php echo $k['followers'] !== null ? $n($k['followers']) : '—'; ?> <?php if ($k['followers_delta'] !== null) : ?><span style="font-size:12px;font-weight:700;color:<?php echo $k['followers_delta'] > 0 ? $GREEN : ($k['followers_delta'] < 0 ? $RED : $MUTED); ?>;"><?php echo $k['followers_delta'] > 0 ? '+' : ($k['followers_delta'] < 0 ? '−' : ''); ?><?php echo $n(abs($k['followers_delta'])); ?></span><?php endif; ?></div>
          </td>
        </tr>
      </table>
      <?php if (!empty($k['prev_date'])) : ?><div style="font-family:<?php echo $F; ?>;font-size:11px;color:<?php echo $MUTED; ?>;margin-top:8px;">28-day changes vs your previous capture (<?php echo $h(date_i18n('M j', strtotime($k['prev_date']))); ?>) · followers over the last 28 days.</div><?php endif; ?>
    </td></tr>
    </table>
  </td></tr>

  <!-- five rings -->
  <?php if (!empty($d['rings'])) :
    $rings = $d['rings']; $inner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;border-spacing:0;"><tr>';
    foreach ($rings as $i => $r) {
        $tone = (string) $r['tone']; $deep = lmeg_si_shade($tone, 0.62); $val = $r['value'];
        if ($i > 0) $inner .= '<td width="14" align="center" class="lmeg-chev" style="font-family:' . $F . ';font-size:16px;font-weight:800;color:' . $TEXT . ';vertical-align:middle;">›</td>';
        $inner .= '<td class="lmeg-ring" style="vertical-align:top;padding:0;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:' . $tone . ';background-image:linear-gradient(135deg,' . $tone . ' 0%,' . $deep . ' 100%);border:1px solid rgba(255,255,255,.22);border-radius:12px;"><tr><td style="padding:10px 7px 9px;font-family:' . $F . ';color:#ffffff;">'
            . '<div class="lmeg-ring-v" style="font-size:17px;line-height:1.1;font-weight:800;color:#ffffff;letter-spacing:-.01em;white-space:nowrap;">' . ($val === null ? '—' : $n($val)) . '</div>'
            . '<div class="lmeg-ring-l" style="font-size:9px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#ffffff;margin:6px 0 3px;line-height:1.25;">' . esc_html($r['label']) . '</div>'
            . '<div class="lmeg-ring-s" style="font-size:10px;line-height:1.35;color:rgba(255,255,255,.92);">' . esc_html($r['sub']) . '</div>'
            . (!empty($r['change']) ? '<div style="margin-top:6px;display:inline-block;font-size:10px;line-height:1.3;font-weight:700;color:#ffffff;background-color:rgba(0,0,0,.28);border-radius:8px;padding:2px 6px;">' . ((int) $r['change'][1] > 0 ? '▲ ' : ((int) $r['change'][1] < 0 ? '▼ ' : '→ ')) . esc_html($r['change'][0]) . '</div>' : '')
            . ($r['pct'] !== null ? '<div style="margin-top:6px;font-size:10px;font-weight:800;color:#ffffff;">' . esc_html(rtrim(rtrim(number_format($r['pct'], 2), '0'), '.')) . '% <span style="font-weight:500;color:rgba(255,255,255,.85);">of ' . esc_html($r['pct_of']) . '</span></div>' : '')
            . '</td></tr></table></td>';
    }
    $inner .= '</tr></table>';
    echo $sec('Your fan base · five rings', $inner, 'Anonymous listeners on the left, people you can actually reach on the right — the % is each ring\'s share of the one before.');
  endif; ?>

  <!-- stage ladder -->
  <?php if (!empty($d['stage'])) : $st = $d['stage']; $stage = (int) $st['stage']; $b = $st['bottleneck']; $nx = $st['next'];
    $inner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td class="lmeg-col" width="42%" style="vertical-align:top;padding:0 12px 0 0;">'
        .   '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#221F3A;background-image:linear-gradient(120deg,rgba(208,95,162,.22),rgba(124,108,246,.22)),linear-gradient(160deg,' . $CARD . ',' . $CARD2 . ');border:1px solid #3A3556;border-radius:12px;"><tr><td style="padding:12px 14px;font-family:' . $F . ';">'
        .     '<div style="font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#C9CCD6;">Stage ' . $stage . ' of 7</div>'
        .     '<div style="font-size:20px;line-height:1.15;font-weight:800;color:' . $TEXT . ';margin:5px 0 3px;">' . esc_html($st['name']) . '</div>'
        .     ((!empty($d['stage_delta']) && ($d['stage_delta']['stage'] ?? 0) > 0) ? '<div style="font-size:12px;font-weight:700;color:' . $GREEN . ';margin:0 0 4px;">⬆ You reached stage ' . $stage . ' today</div>'
             : ((!empty($d['stage_delta']) && ($d['stage_delta']['stage'] ?? 0) < 0) ? '<div style="font-size:12px;font-weight:700;color:' . $RED . ';margin:0 0 4px;">Slipped to stage ' . $stage . ' — a gate below stopped passing</div>' : ''))
        .     '<div style="font-size:12px;line-height:1.45;color:#C9CCD6;">' . esc_html($st['blurb']) . '</div>'
        .     '<div style="margin-top:10px;font-size:11px;color:#C9CCD6;">Ladder progress <span style="color:' . $TEXT . ';font-weight:700;">' . (int) $st['score'] . '/100</span></div>'
        .     lmeg_si_brief_bar((int) $st['score'], $PINK, '#0E0F16', 6, 5)
        .     ((!empty($d['stage_delta']) && is_array($d['stage_delta'])) ? (function ($dl) use ($GREEN, $RED, $TEXT) {
                  $c = function ($v) use ($GREEN, $RED, $TEXT) { return $v > 0 ? $GREEN : ($v < 0 ? $RED : $TEXT); };
                  $pf = function ($v) { return rtrim(rtrim(number_format((float) $v, 2), '0'), '.') . '%'; };
                  $s = '<div style="font-size:11px;color:#C9CCD6;margin-top:6px;">Since ' . esc_html(date_i18n('M j', strtotime($dl['from']))) . ': <span style="color:' . $c($dl['score']) . ';font-weight:700;">' . ($dl['score'] > 0 ? '+' : '') . (int) $dl['score'] . ' progress</span>';
                  if ($dl['list_pct'] !== null) $s .= ' · list share ' . $pf($dl['list_pct_from']) . ' → <span style="color:' . $c($dl['list_pct']) . ';font-weight:700;">' . $pf($dl['list_pct_to']) . '</span>';
                  if ($dl['stage'] !== 0) $s .= ' · <span style="color:' . $c($dl['stage']) . ';font-weight:700;">stage ' . ($dl['stage'] > 0 ? 'up' : 'down') . '</span>';
                  return $s . '</div>';
              })($d['stage_delta']) : '')
        .   '</td></tr></table>'
        . '</td>'
        . '<td class="lmeg-col" style="vertical-align:top;padding:0;">';
    // the seven steps, two rows of pills
    $inner .= '<div style="font-family:' . $F . ';line-height:2.1;">';
    foreach ($st['stages'] as $s) {
        $done = $s['n'] <= $stage; $cur = $s['n'] === $stage + 1;
        $ps = $done ? 'background-color:#173A31;border:1px solid ' . $GREEN . ';color:' . $TEXT . ';' : ($cur ? 'background-color:' . $PINK . ';background-image:linear-gradient(135deg,' . $PINK . ',' . $VIOLET . ');border:1px solid #ffffff44;color:#ffffff;' : 'background-color:#0E0F16;border:1px solid ' . $BORDER . ';color:' . $MUTED . ';');
        $inner .= '<span style="display:inline-block;' . $ps . 'border-radius:999px;padding:3px 9px;font-size:11px;font-weight:700;margin:0 4px 4px 0;white-space:nowrap;">' . ($done ? '✓ ' : ($cur ? '→ ' : '')) . (int) $s['n'] . ' · ' . esc_html($s['name']) . '</span>';
    }
    $inner .= '</div>';
    if ($nx && $b) {
        $inner .= '<div style="margin-top:8px;background-color:#0E0F16;border:1px solid ' . $BORDER . ';border-radius:10px;padding:10px 12px;font-family:' . $F . ';">'
            . '<div style="font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#E58BBD;margin-bottom:4px;">What’s holding you at stage ' . $stage . '</div>'
            . '<div style="font-size:13px;font-weight:700;color:' . $TEXT . ';">' . esc_html($b['label']) . ': <span style="color:' . $RED . ';">' . esc_html($b['value']) . '</span> <span style="color:' . $MUTED . ';font-weight:500;">· needs ' . esc_html($b['target']) . '</span></div>'
            . (!empty($b['need_label']) ? '<div style="font-size:12px;color:' . $TEXT . ';margin-top:3px;"><strong>' . esc_html($b['need_label']) . '</strong>' . (!empty($b['rate_label']) ? ' <span style="color:' . $MUTED . ';">· ' . esc_html($b['rate_label']) . (!empty($b['eta_label']) ? ' · ' . esc_html($b['eta_label']) : '') . '</span>' : '') . '</div>' : '');
        foreach ($nx['gates'] as $g) $inner .= '<div style="font-size:12px;color:' . ($g['pass'] ? $SOFT : $TEXT) . ';margin-top:3px;"><span style="color:' . ($g['pass'] ? $GREEN : $RED) . ';font-weight:800;">' . ($g['pass'] ? '✓' : '○') . '</span> ' . esc_html($g['label']) . ' <span style="color:' . $MUTED . ';">— ' . esc_html($g['value']) . ', needs ' . esc_html($g['target']) . '</span></div>';
        foreach (array_slice($b['actions'], 0, 3) as $a) $inner .= $pill($a['label'], $a['href']);
        $inner .= '</div>';
    }
    foreach ((array) ($d['stage_nudges'] ?? []) as $ng) {
        $warn = ($ng['tone'] ?? '') === 'warn';
        $inner .= '<div style="margin-top:8px;font-family:' . $F . ';font-size:12px;line-height:1.45;color:' . $TEXT . ';background-color:#0E0F16;border:1px solid ' . ($warn ? '#7A3A3A' : '#3A3556') . ';border-radius:10px;padding:8px 12px;">'
                . '<span style="color:' . ($warn ? $RED : $VIOLET) . ';font-weight:800;">' . ($warn ? '!' : '✦') . '</span> <strong>' . esc_html($ng['label']) . '</strong>'
                . (!empty($ng['detail']) ? ' <span style="color:' . $MUTED . ';">— ' . esc_html($ng['detail']) . '</span>' : '')
                . (!empty($ng['href']) ? ' <a href="' . esc_url($ng['href']) . '" style="color:#E58BBD;font-weight:700;text-decoration:none;white-space:nowrap;">Open →</a>' : '') . '</div>';
    }
    if (!empty($d['url_stage'])) $inner .= '<div style="margin-top:8px;font-family:' . $F . ';font-size:12px;"><a href="' . esc_url($d['url_stage']) . '" style="color:#E58BBD;font-weight:700;text-decoration:none;">See the full ladder →</a></div>';
    $inner .= '</td></tr></table>';
    echo $sec('Your stage · Fanloop ladder', $inner, 'Seven steps from first release to fans who pay every month — each gated on your real numbers.');
  endif; ?>

  <!-- songs -->
  <?php if (!empty($d['songs'])) :
    $inner = '';
    if ($d['mover']) { $m = $d['mover']; $inner .= '<div style="font-family:' . $F . ';font-size:13px;color:' . $TEXT . ';background-color:#0E0F16;border:1px solid ' . $BORDER . ';border-radius:10px;padding:10px 12px;margin-bottom:12px;">🔥 <strong>“' . esc_html($m['title']) . '”</strong> is your mover this week — ' . $n($m['last7']) . ' streams in the last 7 days, <span style="color:' . $GREEN . ';font-weight:700;">' . lmeg_si_brief_pct($m['wow']) . '</span> on the week before.</div>'; }
    elseif ($d['cooled']) { $m = $d['cooled']; $inner .= '<div style="font-family:' . $F . ';font-size:13px;color:' . $TEXT . ';background-color:#0E0F16;border:1px solid ' . $BORDER . ';border-radius:10px;padding:10px 12px;margin-bottom:12px;">🧊 <strong>“' . esc_html($m['title']) . '”</strong> cooled the most — ' . $n($m['last7']) . ' streams in the last 7 days, <span style="color:' . $RED . ';font-weight:700;">' . lmeg_si_brief_pct($m['wow']) . '</span> on the week before.</div>'; }
    $inner .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
    foreach ($d['songs'] as $i => $s) {
        $wc = $s['wow'] === null ? $MUTED : ($s['wow'] > 0 ? $GREEN : ($s['wow'] < 0 ? $RED : $MUTED));
        $inner .= '<tr>'
            . '<td width="22" style="padding:7px 0;font-family:' . $F . ';font-size:12px;font-weight:700;color:' . $MUTED . ';vertical-align:top;">' . ($i + 1) . '</td>'
            . '<td style="padding:7px 8px 7px 0;vertical-align:top;">'
            .   '<div style="font-family:' . $F . ';font-size:14px;font-weight:600;color:' . $TEXT . ';">' . esc_html($s['title']) . '</div>'
            .   lmeg_si_brief_bar((int) $s['share'], $GREEN, '#0E0F16', 6, 5)
            . '</td>'
            . '<td align="right" width="120" style="padding:7px 0;font-family:' . $F . ';vertical-align:top;white-space:nowrap;">'
            .   '<div style="font-size:14px;font-weight:800;color:' . $TEXT . ';">' . $n($s['streams']) . '</div>'
            .   ($s['wow'] !== null ? '<div style="font-size:11px;font-weight:700;color:' . $wc . ';margin-top:3px;">' . ($s['wow'] > 0 ? '▲ ' : ($s['wow'] < 0 ? '▼ ' : '')) . rtrim(rtrim(number_format(abs((float) $s['wow']), 1), '0'), '.') . '% wk</div>' : '')
            . '</td></tr>';
    }
    $inner .= '</table>';
    echo $sec('Your songs · ' . esc_html($d['songs_basis']), $inner, $d['songs_basis'] === 'last 7 days' ? 'Top 5 by streams over the last 7 days · ▲▼ = this week vs the week before.' : 'Top 5 by 28-day streams.');
  endif; ?>

  <!-- what to do -->
  <?php if (!empty($d['findings'])) :
    $ftok = ['opportunity' => [$VIOLET, 'Opportunity'], 'watch' => [$AMBER, 'Watch'], 'insight' => ['#E58BBD', 'Insight'], 'strength' => [$GREEN, 'Strength']];
    $inner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
    foreach ($d['findings'] as $f) {
        $tk = $ftok[$f['type']] ?? [$MUTED, ''];
        $inner .= '<tr><td style="padding:0 0 14px;">'
            . '<span style="display:inline-block;font-family:' . $F . ';font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:' . $tk[0] . ';background-color:#0E0F16;border:1px solid ' . $tk[0] . ';border-radius:20px;padding:3px 9px;margin-bottom:6px;">' . esc_html($tk[1]) . '</span>'
            . '<div style="font-family:' . $F . ';font-size:15px;font-weight:700;color:' . $TEXT . ';margin:2px 0 3px;">' . esc_html($f['title']) . '</div>'
            . '<div style="font-family:' . $F . ';font-size:13px;line-height:1.55;color:' . $SOFT . ';">' . esc_html($f['detail']) . '</div>';
        foreach ((array) ($f['actions'] ?? []) as $a) $inner .= $pill($a['label'], $a['href']);
        $inner .= '</td></tr>';
    }
    $inner .= '</table>';
    echo $sec('What to do <span style="color:' . $MUTED . ';font-weight:400;text-transform:none;letter-spacing:0;">· Fanloop analysis</span>', $inner, 'The most actionable reads from your streaming + social data, with one-click next steps.');
  endif; ?>

  <!-- also today -->
  <?php $also = [];
    foreach ($d['pickups'] as $p) $also[] = '<span style="display:inline-block;font-size:10px;font-weight:800;color:' . $GREEN . ';border:1px solid ' . $GREEN . ';border-radius:999px;padding:1px 7px;margin-right:6px;">NEW</span>Picked up by <strong>' . esc_html($p['title']) . '</strong>' . (!empty($p['author']) ? ' <span style="color:' . $MUTED . ';">· ' . esc_html($p['author']) . '</span>' : '') . ($p['followers'] ? ' <span style="color:' . $MUTED . ';">· ' . $n($p['followers']) . ' followers</span>' : '');
    foreach ($d['dropped'] as $p) $also[] = '<span style="display:inline-block;font-size:10px;font-weight:800;color:' . $RED . ';border:1px solid ' . $RED . ';border-radius:999px;padding:1px 7px;margin-right:6px;">GONE</span>Dropped from <strong>' . esc_html($p['title']) . '</strong>' . (!empty($p['author']) ? ' <span style="color:' . $MUTED . ';">· ' . esc_html($p['author']) . '</span>' : '');
    if ($d['lift']) { $L = $d['lift']; $lc = $L['pct'] >= 0 ? $GREEN : $RED; $also[] = '✉️ Your send <strong>“' . esc_html($L['subject'] !== '' ? $L['subject'] : 'untitled send') . '”</strong> (' . esc_html(date_i18n('M j', strtotime($L['d']))) . ') → <span style="color:' . $lc . ';font-weight:700;">' . lmeg_si_brief_pct($L['pct']) . '</span> streams over the 3 days after' . (!empty($L['clicks']) ? ' <span style="color:' . $MUTED . ';">· ' . $n($L['clicks']) . ' clicks</span>' : ''); }
    if ($d['launch']) { $L0 = $d['launch']; $also[] = '🚀 Launch · <strong>' . esc_html($L0['name']) . '</strong> — ' . $n($L0['first7']) . ' streams in its first 7 days' . ($L0['first28'] !== null ? ' · ' . $n($L0['first28']) . ' in its first 28' : ' · ' . (int) $L0['days'] . ' days in'); }
    if ($also) {
        $inner = '';
        foreach ($also as $line) $inner .= '<div style="font-family:' . $F . ';font-size:13px;line-height:1.5;color:' . $TEXT . ';padding:8px 0;border-top:1px solid ' . $BORDER . ';">' . $line . '</div>';
        echo $sec('Also today', '<div style="margin-top:-8px;">' . $inner . '</div>');
    }
  ?>

  <!-- where they listen -->
  <?php if (!empty($d['countries'])) :
    $inner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
    foreach ($d['countries'] as $c) {
        $inner .= '<tr>'
            . '<td width="26" style="padding:5px 0;font-size:15px;vertical-align:middle;">' . $c['flag'] . '</td>'
            . '<td width="150" style="padding:5px 8px 5px 0;font-family:' . $F . ';font-size:13px;font-weight:600;color:' . $TEXT . ';vertical-align:middle;white-space:nowrap;">' . esc_html($c['name']) . '</td>'
            . '<td style="padding:5px 8px 5px 0;vertical-align:middle;">' . lmeg_si_brief_bar((int) $c['share'], $GREEN, '#0E0F16', 6, 0) . '</td>'
            . '<td align="right" width="110" style="padding:5px 0;font-family:' . $F . ';font-size:13px;font-weight:700;color:' . $TEXT . ';vertical-align:middle;white-space:nowrap;">' . $n($c['num']) . ($c['pct'] !== null ? ' <span style="font-size:11px;font-weight:500;color:' . $MUTED . ';">' . esc_html(rtrim(rtrim(number_format($c['pct'], 1), '0'), '.')) . '%</span>' : '') . '</td>'
            . '</tr>';
    }
    $inner .= '</table>';
    echo $sec('Where they listen', $inner, 'Monthly listeners by country · top 5.');
  endif; ?>

  <!-- footer -->
  <tr><td style="padding:6px 4px 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
      <td align="center" style="padding:6px 0 18px;">
        <a href="<?php echo esc_url($d['url_insights']); ?>" style="display:inline-block;font-family:<?php echo $F; ?>;font-size:14px;font-weight:800;color:#ffffff;background-color:<?php echo $PINK; ?>;background-image:linear-gradient(135deg,<?php echo $PINK; ?>,<?php echo $VIOLET; ?>);border-radius:999px;padding:12px 22px;text-decoration:none;">Open Spotify Insights →</a>
      </td>
    </tr><tr>
      <td align="center" style="font-family:<?php echo $F; ?>;font-size:11px;line-height:1.7;color:<?php echo $MUTED; ?>;">
        Streaming data captured <?php echo $h(date_i18n('M j, Y', strtotime($d['captured']))); ?> · Spotify for Artists<?php if ($d['demo']) echo ' · sample data'; ?><br>
        You get this every morning once the Spotify for Artists pull lands for <?php echo $h($d['artist']); ?>. <a href="<?php echo esc_url($d['url_settings']); ?>" style="color:<?php echo $MUTED; ?>;text-decoration:underline;">Turn it off in Settings</a>.<br>
        <span style="color:#5C6172;">Fanloop · <?php echo $h($d['site']); ?></span>
      </td>
    </tr></table>
  </td></tr>

</table>
</td></tr>
</table>
</div>
</body>
</html>
    <?php return ob_get_clean();
}

/** Plain-text alternative built from the data (not a strip of the HTML). */
function lmeg_si_brief_text($d) {
    $n = function ($v) { return number_format_i18n((int) $v); };
    $L = [];
    $L[] = strtoupper($d['artist']) . ' — DAILY BRIEF · ' . $d['today_label'];
    $y = $d['yesterday']; $k = $d['k28'];
    if ($y) {
        $L[] = 'Yesterday (' . $y['label'] . '): ' . $n($y['streams']) . ' streams' . ($y['vs_prev'] !== null ? ' · ' . lmeg_si_brief_pct($y['vs_prev']) . ' vs the day before' : '') . ($y['vs_avg7'] !== null ? ' · ' . lmeg_si_brief_pct($y['vs_avg7']) . ' vs your 7-day average' : '');
    }
    $L[] = '28-day streams: ' . $n($k['streams']) . ($k['streams_pct'] !== null ? ' (' . lmeg_si_brief_pct($k['streams_pct']) . ')' : '') . ' · Monthly listeners: ' . $n($k['listeners']) . ($k['listeners_pct'] !== null ? ' (' . lmeg_si_brief_pct($k['listeners_pct']) . ')' : '') . ($k['followers'] !== null ? ' · Followers: ' . $n($k['followers']) : '');
    if (!empty($d['rings'])) {
        $r = []; foreach ($d['rings'] as $x) $r[] = $x['label'] . ' ' . ($x['value'] === null ? '—' : $n($x['value']));
        $L[] = ''; $L[] = 'FIVE RINGS: ' . implode(' › ', $r);
    }
    if (!empty($d['stage']) && function_exists('lmeg_si_stage_text')) {
        $L[] = ''; $L[] = lmeg_si_stage_text($d['stage']);
        foreach ((array) ($d['stage_nudges'] ?? []) as $ng) $L[] = (($ng['tone'] ?? '') === 'warn' ? '! ' : '* ') . $ng['label'] . (!empty($ng['detail']) ? ' — ' . $ng['detail'] : '');
    }
    if (!empty($d['songs'])) {
        $L[] = ''; $L[] = 'YOUR SONGS (' . $d['songs_basis'] . ')';
        foreach ($d['songs'] as $i => $s) $L[] = ($i + 1) . '. ' . $s['title'] . ' — ' . $n($s['streams']) . ($s['wow'] !== null ? ' (' . lmeg_si_brief_pct($s['wow']) . ' wk)' : '');
    }
    if (!empty($d['findings'])) {
        $L[] = ''; $L[] = 'WHAT TO DO';
        foreach ($d['findings'] as $f) { $L[] = '• ' . $f['title'] . ' — ' . $f['detail']; foreach ((array) ($f['actions'] ?? []) as $a) $L[] = '    ' . $a['label'] . ': ' . $a['href']; }
    }
    $L[] = ''; $L[] = 'Open Spotify Insights: ' . $d['url_insights'];
    $L[] = 'Streaming data captured ' . $d['captured'] . ' · Spotify for Artists. Turn the brief off in Settings: ' . $d['url_settings'];
    return implode("\n", $L);
}

/** Subject line: "LOONY daily · Mon, Sep 7: 14,120 streams yesterday (+3.1%)". */
function lmeg_si_brief_subject($d) {
    $n = function ($v) { return number_format_i18n((int) $v); };
    $y = $d['yesterday'];
    $s = $d['artist'] . ' daily · ' . $d['today_label'] . ': ';
    if ($y) $s .= $n($y['streams']) . ' streams yesterday' . ($y['vs_prev'] !== null ? ' (' . lmeg_si_brief_pct($y['vs_prev']) . ')' : '');
    else $s .= $n($d['k28']['streams']) . ' streams in 28 days';
    if (!empty($d['stage_delta']) && ($d['stage_delta']['stage'] ?? 0) > 0 && !empty($d['stage'])) $s .= ' · ⬆ Stage ' . (int) $d['stage']['stage'] . ' reached';
    if ($d['demo']) $s .= ' · sample';
    return $s;
}

/** Recipient: Settings brief address → digest address → site admin. */
function lmeg_si_brief_recipient() {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    if (!empty($s['brief_email']) && is_email($s['brief_email'])) return $s['brief_email'];
    if (!empty($s['digest_email']) && is_email($s['digest_email'])) return $s['digest_email'];
    return get_option('admin_email');
}

/**
 * Build + send today's brief. $to overrides the configured recipient (the
 * "Email me today's brief" button sends to the person clicking). Returns
 * true or WP_Error (unconfigured mailer, no snapshot).
 */
function lmeg_send_daily_brief($to = null, $demo = false) {
    $d = lmeg_si_brief_data($demo);
    if (!$d) return new WP_Error('lmeg_brief_nodata', 'No Spotify for Artists snapshot yet — nothing to brief.');
    $to = ($to && is_email($to)) ? $to : lmeg_si_brief_recipient();
    return lmeg_email_send($to, lmeg_si_brief_subject($d), lmeg_si_brief_text($d), lmeg_si_brief_html($d));
}

/**
 * Daily tick: once per site-day, never before 9am site time, and ONLY once
 * TODAY's capture has landed (the Spotify for Artists pull runs at 9am and
 * POSTs each artist's snapshot in; the brief follows within the minute). No
 * capture today = no brief — the Insights page's red freshness banner covers
 * a failed pull, and a stale brief would be worse than none.
 */
add_action('lmeg_broadcast_tick', 'lmeg_daily_brief_tick', 71);
function lmeg_daily_brief_tick() {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    if (isset($s['brief_enabled']) && empty($s['brief_enabled'])) return;
    if (!function_exists('lmeg_s4a_latest')) return;
    $now = current_time('timestamp'); $today = date('Y-m-d', $now); $hour = (int) date('G', $now);
    $earliest = (int) apply_filters('lmeg_brief_earliest_hour', 9);
    if ($hour < $earliest) return;
    if (get_option('lmeg_brief_last') === $today) return;
    $snap = lmeg_s4a_latest();
    if (!$snap || (string) $snap->captured_date !== $today) return; // wait for today's pull
    update_option('lmeg_brief_last', $today, false);
    lmeg_send_daily_brief();
}

/** Admin-only browser preview: …/wp-admin/admin.php?lmeg_brief_preview=1[&demo=1]. */
add_action('admin_init', 'lmeg_daily_brief_preview');
function lmeg_daily_brief_preview() {
    if (empty($_GET['lmeg_brief_preview']) || !current_user_can('manage_options')) return;
    $d = lmeg_si_brief_data(!empty($_GET['demo']));
    header('Content-Type: text/html; charset=utf-8');
    // The brief is a complete document (head styles carry the mobile rules).
    echo $d ? lmeg_si_brief_html($d)
            : '<!doctype html><html><head><meta charset="utf-8"><title>Daily brief</title></head><body style="margin:0;background:#0E0F16;"><p style="font-family:sans-serif;padding:24px;color:#F4F5F7;">No Spotify for Artists snapshot yet — nothing to brief. Add <code>&amp;demo=1</code> to preview with sample data.</p></body></html>';
    exit;
}
