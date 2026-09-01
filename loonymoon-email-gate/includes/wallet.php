<?php
/**
 * Fanloop Wallet Pass — Apple PassKit (.pkpass) generation.
 *
 * A "Fanloop Pass" fans add to Apple Wallet (Google Wallet comes later): a
 * lock-screen fan card tied to a subscriber, updatable via push. This file is
 * the ENGINE — build + sign a .pkpass. Delivery endpoints, the PassKit web
 * service, APNs push and the admin UI arrive in later iterations.
 *
 * LIVE on real iPhones needs a Pass Type ID certificate + Apple WWDR cert in
 * Settings (Wallet). Until then we sign with a throwaway self-signed cert so the
 * whole pipeline runs and validates locally — the bundle is structurally correct,
 * just not trusted by a real device.
 *
 * @package Fanloop
 */

if (!defined('ABSPATH') && !defined('LMEG_WALLET_STANDALONE')) return;

if (!defined('LMEG_WALLET_DB_VERSION')) define('LMEG_WALLET_DB_VERSION', '2');

/* -------------------------------------------------------------------------
 * Data model — passes + device registrations (self-installs, version-gated).
 * ---------------------------------------------------------------------- */
add_action('init', 'lmeg_wallet_maybe_install', 1);
function lmeg_wallet_maybe_install() {
    if (get_option('lmeg_wallet_db_version') === LMEG_WALLET_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $passes  = $wpdb->prefix . 'lmeg_wallet_passes';
    $regs    = $wpdb->prefix . 'lmeg_wallet_registrations';

    dbDelta("CREATE TABLE $passes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        serial VARCHAR(64) NOT NULL,
        platform VARCHAR(10) NOT NULL DEFAULT 'apple',
        subscriber_id BIGINT UNSIGNED DEFAULT NULL,
        auth_token VARCHAR(64) NOT NULL,
        tier VARCHAR(120) DEFAULT NULL,
        headline VARCHAR(190) DEFAULT NULL,
        latest VARCHAR(190) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        last_push_at DATETIME DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY serial (serial),
        KEY subscriber_id (subscriber_id)
    ) $charset;");

    dbDelta("CREATE TABLE $regs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        serial VARCHAR(64) NOT NULL,
        device_lib_id VARCHAR(190) NOT NULL,
        push_token VARCHAR(190) NOT NULL,
        platform VARCHAR(10) NOT NULL DEFAULT 'apple',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY dev_serial (device_lib_id, serial),
        KEY serial (serial)
    ) $charset;");

    $chk = $wpdb->prefix . 'lmeg_wallet_checkins';
    dbDelta("CREATE TABLE $chk (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        serial VARCHAR(64) NOT NULL,
        event_day DATE NOT NULL,
        checked_at DATETIME NOT NULL,
        by_user BIGINT UNSIGNED DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY serial_day (serial, event_day),
        KEY serial (serial)
    ) $charset;");

    update_option('lmeg_wallet_db_version', LMEG_WALLET_DB_VERSION);
}
function lmeg_wallet_table($which = 'passes') {
    global $wpdb;
    $map = ['regs' => 'lmeg_wallet_registrations', 'checkins' => 'lmeg_wallet_checkins', 'passes' => 'lmeg_wallet_passes'];
    return $wpdb->prefix . ($map[$which] ?? $map['passes']);
}

/* -------------------------------------------------------------------------
 * Settings + readiness.
 * ---------------------------------------------------------------------- */
function lmeg_wallet_settings() {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $artist = $s['artist_name'] ?? (function_exists('get_bloginfo') ? get_bloginfo('name') : 'Fanloop');
    return [
        'pass_type_id' => trim((string) ($s['wallet_pass_type_id'] ?? '')),   // pass.com.artist.fan
        'team_id'      => trim((string) ($s['wallet_team_id'] ?? '')),        // Apple team identifier
        'cert_pem'     => trim((string) ($s['wallet_cert_pem'] ?? '')),       // signer cert + key (inline PEM or path)
        'cert_pass'    => (string) ($s['wallet_cert_pass'] ?? ''),
        'wwdr_pem'     => trim((string) ($s['wallet_wwdr_pem'] ?? '')),       // Apple WWDR intermediate (inline PEM or path)
        'org'          => trim((string) ($s['wallet_org'] ?? $artist)),
        'logo_text'    => trim((string) ($s['wallet_logo_text'] ?? $artist)),
        'bg'           => trim((string) ($s['wallet_bg'] ?? '#141019')),
        'fg'           => trim((string) ($s['wallet_fg'] ?? '#ffffff')),
        'label'        => trim((string) ($s['wallet_label'] ?? '#c7b9ff')),
    ];
}
/** True when a real Apple production cert is configured; otherwise we run dev/self-signed. */
function lmeg_wallet_apple_ready() {
    $c = lmeg_wallet_settings();
    return $c['pass_type_id'] !== '' && $c['team_id'] !== ''
        && lmeg_wallet_pem($c['cert_pem']) !== '' && lmeg_wallet_pem($c['wwdr_pem']) !== '';
}
/** A PEM setting may be inline PEM text or a filesystem path. Returns the PEM or ''. */
function lmeg_wallet_pem($v) {
    $v = trim((string) $v);
    if ($v === '') return '';
    if (strpos($v, '-----BEGIN') !== false) return $v;
    if (@is_file($v) && @is_readable($v)) return (string) file_get_contents($v);
    return '';
}

/* -------------------------------------------------------------------------
 * Small filesystem + colour helpers.
 * ---------------------------------------------------------------------- */
function lmeg_wallet_tmpdir() {
    $base = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '/tmp';
    $d = $base . '/lmeg-wallet';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}
function lmeg_wallet_rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        is_dir($p) ? lmeg_wallet_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
/** '#141019' -> 'rgb(20,16,25)' (PassKit wants rgb() strings). */
function lmeg_wallet_rgb($hex) {
    $hex = ltrim((string) $hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) $hex = '000000';
    $n = hexdec($hex);
    return sprintf('rgb(%d,%d,%d)', ($n >> 16) & 255, ($n >> 8) & 255, $n & 255);
}
function lmeg_wallet_rgb_arr($hex) {
    $hex = ltrim((string) $hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) $hex = '000000';
    $n = hexdec($hex);
    return [($n >> 16) & 255, ($n >> 8) & 255, $n & 255];
}

/* -------------------------------------------------------------------------
 * Images — Apple requires icon (+@2x); logo (+@2x) is shown top-left. We render
 * simple, on-brand PNGs with GD (a coloured tile + the artist initial / name)
 * so the bundle is always complete. A richer designer comes later.
 * ---------------------------------------------------------------------- */
function lmeg_wallet_images() {
    $c   = lmeg_wallet_settings();
    $bg  = lmeg_wallet_rgb_arr($c['bg']);
    $fg  = lmeg_wallet_rgb_arr($c['fg']);
    $acc = lmeg_wallet_rgb_arr($c['label']);
    $initial = strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $c['logo_text']) ?: 'F', 0, 1));

    $png = function ($w, $h, $draw) {
        $im = imagecreatetruecolor($w, $h);
        imagesavealpha($im, true);
        $draw($im, $w, $h);
        ob_start(); imagepng($im); $bytes = ob_get_clean();
        imagedestroy($im);
        return $bytes;
    };
    $icon = function ($im, $w, $h) use ($bg, $acc, $fg, $initial) {
        $b = imagecolorallocate($im, $bg[0], $bg[1], $bg[2]);
        imagefilledrectangle($im, 0, 0, $w, $h, $b);
        $a = imagecolorallocate($im, $acc[0], $acc[1], $acc[2]);
        imagefilledellipse($im, (int) ($w / 2), (int) ($h / 2), (int) ($w * 0.72), (int) ($h * 0.72), $a);
        $t = imagecolorallocate($im, $bg[0], $bg[1], $bg[2]);
        $fnt = 5; $cw = imagefontwidth($fnt); $ch = imagefontheight($fnt);
        imagestring($im, $fnt, (int) (($w - $cw) / 2), (int) (($h - $ch) / 2), $initial, $t);
    };
    $logo = function ($im, $w, $h) use ($bg, $fg, $c) {
        $b = imagecolorallocate($im, $bg[0], $bg[1], $bg[2]);
        imagefilledrectangle($im, 0, 0, $w, $h, $b);
        $t = imagecolorallocate($im, $fg[0], $fg[1], $fg[2]);
        $txt = mb_substr($c['logo_text'] ?: 'Fanloop', 0, 22);
        $fnt = 5; $ch = imagefontheight($fnt);
        imagestring($im, $fnt, 6, (int) (($h - $ch) / 2), $txt, $t);
    };

    return [
        'icon.png'     => $png(29, 29, $icon),
        'icon@2x.png'  => $png(58, 58, $icon),
        'logo.png'     => $png(160, 50, $logo),
        'logo@2x.png'  => $png(320, 100, $logo),
    ];
}

/* -------------------------------------------------------------------------
 * pass.json builder.
 * ---------------------------------------------------------------------- */
/**
 * Build the pass.json array for a storeCard fan pass.
 * $args: serial, auth_token, tier, member_since, latest, barcode_message,
 *        web_service_url (omit for a static/un-updatable pass).
 */
function lmeg_wallet_pass_json(array $args) {
    $c = lmeg_wallet_settings();
    $tier   = $args['tier']   ?? 'Fan';
    $latest = $args['latest'] ?? 'Welcome to the inner circle.';
    $since  = $args['member_since'] ?? gmdate('Y');

    $pass = [
        'formatVersion'      => 1,
        'passTypeIdentifier' => $c['pass_type_id'] !== '' ? $c['pass_type_id'] : 'pass.ca.portermedia.fanloop.dev',
        'teamIdentifier'     => $c['team_id'] !== '' ? $c['team_id'] : 'DEVTEAM0000',
        'organizationName'   => $c['org'] !== '' ? $c['org'] : 'Fanloop',
        'description'        => ($c['org'] ?: 'Fanloop') . ' — fan pass',
        'serialNumber'       => (string) ($args['serial'] ?? bin2hex(random_bytes(10))),
        'logoText'           => $c['logo_text'] ?: ($c['org'] ?: 'Fanloop'),
        'foregroundColor'    => lmeg_wallet_rgb($c['fg']),
        'backgroundColor'    => lmeg_wallet_rgb($c['bg']),
        'labelColor'         => lmeg_wallet_rgb($c['label']),
        'sharingProhibited'  => true,
        'storeCard'          => [
            'primaryFields'   => [
                ['key' => 'tier', 'label' => 'MEMBER', 'value' => $tier],
            ],
            'secondaryFields' => [
                ['key' => 'since', 'label' => 'SINCE', 'value' => (string) $since],
            ],
            'auxiliaryFields' => [
                ['key' => 'latest', 'label' => 'LATEST', 'value' => $latest, 'changeMessage' => '%@'],
            ],
            'backFields'      => [
                ['key' => 'about', 'label' => 'About', 'value' => 'Your pass to ' . ($c['org'] ?: 'the artist') . ' — drops, presales and shows land right here on your lock screen.'],
            ],
        ],
    ];

    if (!empty($args['barcode_message'])) {
        $pass['barcodes'] = [[
            'format'          => 'PKBarcodeFormatQR',
            'message'         => (string) $args['barcode_message'],
            'messageEncoding' => 'iso-8859-1',
        ]];
    }
    if (!empty($args['web_service_url']) && !empty($args['auth_token'])) {
        $pass['webServiceURL']       = (string) $args['web_service_url'];
        $pass['authenticationToken'] = (string) $args['auth_token'];
    }
    return $pass;
}

/* -------------------------------------------------------------------------
 * Signing — PKCS#7 detached signature over manifest.json.
 * ---------------------------------------------------------------------- */
/** Extract the DER PKCS#7 out of openssl's S/MIME output (detached → pure base64 body). */
function lmeg_wallet_smime_to_der($smime) {
    $pos = strpos($smime, "\n\n");
    if ($pos === false) $pos = strpos($smime, "\r\n\r\n");
    $body = $pos !== false ? substr($smime, $pos) : $smime;
    $b64 = '';
    foreach (preg_split('/\r?\n/', $body) as $ln) {
        $t = trim($ln);
        if ($t === '' || strpos($t, '--') === 0) continue;
        if (preg_match('#^[A-Za-z0-9+/=]+$#', $t)) $b64 .= $t;   // pure base64 lines only
    }
    return $b64 === '' ? '' : (string) base64_decode($b64);
}
/** A cached throwaway self-signed signer for dev mode (no Apple cert configured). */
function lmeg_wallet_dev_signer() {
    $dir   = lmeg_wallet_tmpdir();
    $certf = $dir . '/dev_signer.pem';
    $keyf  = $dir . '/dev_key.pem';
    if (is_file($certf) && is_file($keyf)) {
        return ['cert' => (string) file_get_contents($certf), 'key' => (string) file_get_contents($keyf)];
    }
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'Fanloop Dev Pass Signer', 'organizationName' => 'Fanloop Dev'], $key);
    $x   = openssl_csr_sign($csr, null, $key, 3650);
    openssl_x509_export($x, $certPem);
    openssl_pkey_export($key, $keyPem);
    @file_put_contents($certf, $certPem);
    @file_put_contents($keyf, $keyPem);
    return ['cert' => $certPem, 'key' => $keyPem];
}
/** Sign manifest.json → raw DER PKCS#7 detached signature ('' on failure). */
function lmeg_wallet_sign($manifestPath) {
    $dir = lmeg_wallet_tmpdir();
    $wwdrf = null; $keyf = null;
    if (lmeg_wallet_apple_ready()) {
        $c = lmeg_wallet_settings();
        $certf = tempnam($dir, 'crt'); file_put_contents($certf, lmeg_wallet_pem($c['cert_pem'])); // cert (+key) PEM
        $wwdrf = tempnam($dir, 'wdr'); file_put_contents($wwdrf, lmeg_wallet_pem($c['wwdr_pem']));
        $signCert = 'file://' . $certf;
        $privKey  = ['file://' . $certf, $c['cert_pass']];
        $extra    = 'file://' . $wwdrf;
    } else {
        $dev   = lmeg_wallet_dev_signer();
        $certf = tempnam($dir, 'crt'); file_put_contents($certf, $dev['cert']);
        $keyf  = tempnam($dir, 'key'); file_put_contents($keyf, $dev['key']);
        $signCert = 'file://' . $certf;
        $privKey  = ['file://' . $keyf, ''];
        $extra    = null;
    }
    $sigf = tempnam($dir, 'sig');
    $ok = @openssl_pkcs7_sign($manifestPath, $sigf, $signCert, $privKey, [], PKCS7_BINARY | PKCS7_DETACHED, $extra);
    $der = ($ok && is_file($sigf)) ? lmeg_wallet_smime_to_der((string) file_get_contents($sigf)) : '';
    @unlink($sigf); @unlink($certf);
    if ($keyf)  @unlink($keyf);
    if ($wwdrf) @unlink($wwdrf);
    return $der;
}

/* -------------------------------------------------------------------------
 * Assemble the .pkpass (a signed zip).
 * ---------------------------------------------------------------------- */
/** Returns ['bytes'=>rawZip, 'mode'=>'production'|'dev'] or ['error'=>msg]. */
function lmeg_wallet_build_pkpass(array $passJson) {
    $work = lmeg_wallet_tmpdir() . '/pk_' . bin2hex(random_bytes(6));
    @mkdir($work, 0700, true);
    try {
        file_put_contents($work . '/pass.json', wp_json_encode($passJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $imgs = lmeg_wallet_images();
        foreach ($imgs as $name => $bytes) file_put_contents($work . '/' . $name, $bytes);

        $manifest = ['pass.json' => sha1_file($work . '/pass.json')];
        foreach ($imgs as $name => $b) $manifest[$name] = sha1_file($work . '/' . $name);
        file_put_contents($work . '/manifest.json', wp_json_encode($manifest, JSON_UNESCAPED_SLASHES));

        $der = lmeg_wallet_sign($work . '/manifest.json');
        if ($der === '') return ['error' => 'signing failed (check OpenSSL / cert)'];
        file_put_contents($work . '/signature', $der);

        $zipPath = $work . '.pkpass';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return ['error' => 'zip open failed'];
        foreach (['pass.json', 'manifest.json', 'signature'] as $f) $zip->addFile($work . '/' . $f, $f);
        foreach (array_keys($imgs) as $name) $zip->addFile($work . '/' . $name, $name);
        $zip->close();

        $bytes = (string) file_get_contents($zipPath);
        @unlink($zipPath);
        return ['bytes' => $bytes, 'mode' => lmeg_wallet_apple_ready() ? 'production' : 'dev'];
    } finally {
        lmeg_wallet_rrmdir($work);
    }
}

/* =========================================================================
 * ITERATION 2 — delivery: per-fan passes, Add-to-Wallet, capture into CRM.
 * ====================================================================== */

function lmeg_wallet_ip() { return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

/** Membership tier label for a subscriber (fuller Stripe-grant logic lands later). */
function lmeg_wallet_tier_for($sid) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT member_status, member_tier_id FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", (int) $sid));
    if ($row && $row->member_status && $row->member_status !== 'free' && $row->member_tier_id && function_exists('lmeg_tier')) {
        $t = lmeg_tier((int) $row->member_tier_id);
        if ($t && !empty($t->name)) return $t->name;
    }
    return 'Fan';
}

/** Get-or-create the Apple pass row for a subscriber. Returns the row object. */
function lmeg_wallet_issue_for_subscriber($sid, $args = []) {
    global $wpdb;
    $sid = (int) $sid;
    $tbl = lmeg_wallet_table('passes');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE subscriber_id = %d AND platform = 'apple'", $sid));
    if ($row) {
        // refresh tier if it changed
        $tier = lmeg_wallet_tier_for($sid);
        if ($tier !== $row->tier) {
            $wpdb->update($tbl, ['tier' => $tier, 'updated_at' => current_time('mysql', true)], ['id' => $row->id]);
            $row->tier = $tier;
        }
        return $row;
    }
    $serial = 'flp' . dechex($sid) . bin2hex(random_bytes(4));
    $now    = current_time('mysql', true);
    $wpdb->insert($tbl, [
        'serial'        => $serial,
        'platform'      => 'apple',
        'subscriber_id' => $sid,
        'auth_token'    => bin2hex(random_bytes(16)),
        'tier'          => lmeg_wallet_tier_for($sid),
        'headline'      => (string) ($args['headline'] ?? ''),
        'latest'        => (string) ($args['latest'] ?? 'Welcome to the inner circle.'),
        'created_at'    => $now,
        'updated_at'    => $now,
    ], ['%s','%s','%d','%s','%s','%s','%s','%s','%s']);
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id = %d", $wpdb->insert_id));
}

/** The one-tap "Add to Apple Wallet" URL for a subscriber (issues the pass). */
function lmeg_wallet_link($sid) {
    $row = lmeg_wallet_issue_for_subscriber($sid);
    if (!$row) return '';
    return add_query_arg(['lmeg_wallet' => 'pkpass', 's' => $row->serial, 't' => $row->auth_token], home_url('/'));
}

/** Find (or create) a subscriber by email — used when a new fan adds a pass. */
function lmeg_wallet_get_or_create_subscriber($email, $first_name = '') {
    global $wpdb;
    $email = sanitize_email((string) $email);
    if (!$email || !is_email($email)) return 0;
    $id = function_exists('lmeg_shop_match_subscriber') ? (int) lmeg_shop_match_subscriber($email, '') : 0;
    if ($id) return $id;
    $now = current_time('mysql', true);
    $wpdb->insert($wpdb->prefix . LMEG_TABLE, [
        'contact_type' => 'email',
        'email'        => $email,
        'first_name'   => sanitize_text_field($first_name),
        'created_at'   => $now,
        'confirmed_at' => $now,          // adding a Wallet pass is an explicit opt-in
        'email_status' => 'ok',
        'member_status'=> 'free',
        'lang'         => function_exists('lmeg_current_lang') ? lmeg_current_lang() : null,
        'ip'           => lmeg_wallet_ip(),
    ], ['%s','%s','%s','%s','%s','%s','%s','%s','%s']);
    $id = (int) $wpdb->insert_id;
    if ($id && function_exists('do_action')) do_action('lmeg_subscriber_created', $id, 'wallet');
    return $id;
}

/** Build the per-fan pass.json from a pass row + subscriber row. */
function lmeg_wallet_pass_for_row($row, $sub = null) {
    $since = gmdate('Y');
    if ($sub && !empty($sub->created_at)) $since = gmdate('Y', strtotime($sub->created_at));
    $args = [
        'serial'          => $row->serial,
        'tier'            => $row->tier ?: 'Fan',
        'member_since'    => $since,
        'latest'          => $row->latest ?: 'Welcome to the inner circle.',
        'barcode_message' => home_url('/?lmeg_wallet=checkin&serial=' . rawurlencode($row->serial)),  // QR → admin check-in card
    ];
    // web service + auth token wired in iteration 3 (register for push updates)
    if (function_exists('lmeg_wallet_ws_url') && !empty($row->auth_token)) {
        $args['web_service_url'] = lmeg_wallet_ws_url();
        $args['auth_token']      = $row->auth_token;
    }
    return lmeg_wallet_pass_json($args);
}

/* ---- routes: ?lmeg_wallet=pkpass (download) and =add (capture) ---- */
add_action('init', 'lmeg_wallet_router');
function lmeg_wallet_router() {
    if (!isset($_GET['lmeg_wallet'])) return;
    $action = sanitize_key($_GET['lmeg_wallet']);
    global $wpdb;

    if ($action === 'pkpass') {
        $serial = sanitize_text_field($_GET['s'] ?? '');
        $token  = sanitize_text_field($_GET['t'] ?? '');
        if ($serial === '' || $token === '') { status_header(400); exit('missing serial/token'); }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . lmeg_wallet_table('passes') . " WHERE serial = %s", $serial));
        if (!$row || !hash_equals((string) $row->auth_token, $token)) { status_header(401); exit('unauthorized'); }
        $sub = $row->subscriber_id
            ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", (int) $row->subscriber_id))
            : null;
        $res = lmeg_wallet_build_pkpass(lmeg_wallet_pass_for_row($row, $sub));
        if (isset($res['error'])) { status_header(500); exit('pass build failed: ' . $res['error']); }
        nocache_headers();
        header('Content-Type: application/vnd.apple.pkpass');
        header('Content-Disposition: attachment; filename="' . $row->serial . '.pkpass"');
        header('Content-Length: ' . strlen($res['bytes']));
        echo $res['bytes'];
        exit;
    }

    if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = sanitize_email($_POST['email'] ?? '');
        $first = sanitize_text_field($_POST['first_name'] ?? '');
        if (!$email || !is_email($email)) { wp_safe_redirect(add_query_arg('lmeg_wallet_err', '1', wp_get_referer() ?: home_url('/'))); exit; }
        $sid = lmeg_wallet_get_or_create_subscriber($email, $first);
        if (!$sid) { wp_safe_redirect(add_query_arg('lmeg_wallet_err', '1', wp_get_referer() ?: home_url('/'))); exit; }
        $platform = sanitize_key($_POST['platform'] ?? 'apple');
        if ($platform === 'google' && lmeg_wallet_google_ready()) {
            $gu = lmeg_wallet_google_save_url($sid);
            if ($gu) { wp_safe_redirect($gu); exit; }
        }
        // default: hand back the .pkpass directly so Apple Wallet opens it
        wp_safe_redirect(lmeg_wallet_link($sid));
        exit;
    }

    if ($action === 'checkin') {   // door scanner opens the pass QR → this admin card
        if (!current_user_can('manage_options')) { status_header(403); exit('Sign in as an admin to scan check-ins.'); }
        $serial = sanitize_text_field($_GET['serial'] ?? $_GET['s'] ?? '');
        $pass = $serial ? $wpdb->get_row($wpdb->prepare("SELECT * FROM " . lmeg_wallet_table('passes') . " WHERE serial = %s", $serial)) : null;
        header('Content-Type: text/html; charset=utf-8');
        nocache_headers();
        if (!$pass) { echo lmeg_wallet_checkin_html(null, null, null); exit; }
        $sub = $pass->subscriber_id
            ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", (int) $pass->subscriber_id))
            : null;
        $chk = lmeg_wallet_table('checkins');
        $day = current_time('Y-m-d');
        $already = $wpdb->get_var($wpdb->prepare("SELECT checked_at FROM $chk WHERE serial = %s AND event_day = %s", $pass->serial, $day));
        if (!$already) {
            $wpdb->insert($chk, [
                'serial' => $pass->serial, 'event_day' => $day,
                'checked_at' => current_time('mysql', true), 'by_user' => get_current_user_id(),
            ], ['%s','%s','%s','%d']);
        }
        echo lmeg_wallet_checkin_html($pass, $sub, $already);
        exit;
    }
}

/** The little card the door person sees after scanning a pass QR. */
function lmeg_wallet_checkin_html($pass, $sub, $already) {
    $c = lmeg_wallet_settings();
    $ok = $pass !== null;
    $name = $sub ? trim(($sub->first_name ?? '') ?: ($sub->email ?? 'Fan')) : '';
    $email = $sub->email ?? '';
    $tier  = $pass ? ($pass->tier ?: 'Fan') : '';
    $since = ($sub && !empty($sub->created_at)) ? gmdate('M Y', strtotime($sub->created_at)) : '';
    $accent = esc_attr($c['label'] ?: '#c7b9ff');
    ob_start(); ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Check-in</title></head>
<body style="margin:0;background:#0b0b10;color:#f4f2f7;font:16px/1.4 -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;min-height:100vh;display:grid;place-items:center;">
  <div style="width:min(92vw,420px);background:#16161f;border:1px solid rgba(255,255,255,.1);border-radius:18px;padding:26px;text-align:center;">
  <?php if (!$ok): ?>
    <div style="font-size:44px;margin-bottom:6px;">⚠️</div>
    <div style="font-weight:700;font-size:20px;">Not a valid pass</div>
    <div style="color:#9b98ab;margin-top:6px;">That QR doesn't match a Fanloop pass.</div>
  <?php else: ?>
    <div style="font-size:44px;margin-bottom:6px;color:<?php echo $accent; ?>;"><?php echo $already ? '↺' : '✓'; ?></div>
    <div style="font-weight:800;font-size:22px;"><?php echo $already ? 'Already checked in' : 'Checked in'; ?></div>
    <?php if ($already): ?><div style="color:#9b98ab;font-size:13px;margin-top:3px;">at <?php echo esc_html(gmdate('g:i a', strtotime($already))); ?> UTC</div><?php endif; ?>
    <div style="margin:18px 0 4px;font-weight:700;font-size:19px;"><?php echo esc_html($name ?: 'Fan'); ?></div>
    <?php if ($email): ?><div style="color:#9b98ab;font-size:14px;"><?php echo esc_html($email); ?></div><?php endif; ?>
    <div style="display:inline-block;margin-top:14px;background:rgba(199,185,255,.14);color:<?php echo $accent; ?>;border-radius:99px;padding:5px 14px;font-weight:700;font-size:13px;letter-spacing:.02em;"><?php echo esc_html(strtoupper($tier)); ?></div>
    <?php if ($since): ?><div style="color:#6a6779;font-size:12px;margin-top:12px;">Fan since <?php echo esc_html($since); ?></div><?php endif; ?>
  <?php endif; ?>
  </div>
</body></html>
    <?php
    return ob_get_clean();
}

/* ---- membership tier → pass (live), driven by lmeg_membership_changed ---- */
add_action('lmeg_membership_changed', 'lmeg_wallet_sync_tier');
function lmeg_wallet_sync_tier($subscriber_id) {
    global $wpdb;
    $sid = (int) $subscriber_id;
    if (!$sid) return;
    $pass = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . lmeg_wallet_table('passes') . " WHERE subscriber_id = %d AND platform = 'apple'", $sid));
    if (!$pass) return;   // fan has no Wallet pass — nothing to update
    $tier = lmeg_wallet_tier_for($sid);
    if ($tier === $pass->tier) return;
    lmeg_wallet_update($pass->serial, ['tier' => $tier]);   // writes row + pushes their device(s)
}

/* ---- shortcode: [fanloop_wallet] — an Add-to-Apple-Wallet button ---- */
add_shortcode('fanloop_wallet', 'lmeg_wallet_shortcode');
function lmeg_wallet_shortcode($atts = []) {
    $a = shortcode_atts([
        'heading' => 'Add your fan pass',
        'blurb'   => 'One tap. Drops, presales and shows land on your lock screen.',
    ], $atts, 'fanloop_wallet');

    $btn = function ($href) {
        return '<a href="' . esc_url($href) . '" style="display:inline-flex;align-items:center;gap:9px;background:#000;color:#fff;'
            . 'text-decoration:none;border-radius:10px;padding:11px 16px;font:600 15px/1 -apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">'
            . '<svg width="17" height="17" viewBox="0 0 24 24" fill="#fff" aria-hidden="true"><path d="M17 1.6c.1 1-.3 2-1 2.8-.7.8-1.8 1.4-2.8 1.3-.1-1 .4-2 1-2.7.7-.8 1.9-1.4 2.8-1.4zM19.9 17c-.5 1.2-.8 1.7-1.4 2.7-1 1.4-2.3 3.2-4 3.2-1.5 0-1.9-1-3.9-1-2 0-2.4 1-3.9 1-1.7 0-2.9-1.6-3.9-3.1C-.1 16.6-.4 11 1.7 8c1-1.5 2.6-2.4 4.1-2.4 1.6 0 2.6 1 3.9 1 1.3 0 2-1 3.9-1 1.4 0 2.9.8 3.9 2.1-3.4 1.9-2.9 6.8.4 9.3z"/></svg>'
            . 'Add to Apple Wallet</a>';
    };
    $gbtn = function ($href) {
        return '<a href="' . esc_url($href) . '" style="display:inline-flex;align-items:center;gap:8px;background:#fff;color:#3c4043;'
            . 'text-decoration:none;border:1px solid #dadce0;border-radius:10px;padding:10px 15px;font:600 15px/1 -apple-system,\'Segoe UI\',Roboto,sans-serif;">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M22.5 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.9a5 5 0 0 1-2.2 3.3v2.7h3.6c2.1-2 3.2-4.9 3.2-7.8z"/><path fill="#34A853" d="M12 23c2.9 0 5.4-1 7.2-2.7l-3.6-2.7c-1 .7-2.3 1.1-3.6 1.1-2.8 0-5.1-1.9-6-4.4H2.3v2.8A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M6 14.3a6.6 6.6 0 0 1 0-4.2V7.3H2.3a11 11 0 0 0 0 9.8L6 14.3z"/><path fill="#EA4335" d="M12 5.4c1.6 0 3 .5 4.1 1.6l3.1-3.1A11 11 0 0 0 2.3 7.3L6 10.1c.9-2.6 3.2-4.7 6-4.7z"/></svg>'
            . 'Save to Google Wallet</a>';
    };
    $gready = function_exists('lmeg_wallet_google_ready') && lmeg_wallet_google_ready();

    $member = function_exists('lmeg_current_member') ? lmeg_current_member() : null;
    ob_start(); ?>
    <div class="lmeg-wallet-cta" style="max-width:420px;border:1px solid rgba(0,0,0,.12);border-radius:14px;padding:18px 20px;">
        <div style="font:700 17px/1.2 -apple-system,BlinkMacSystemFont,sans-serif;margin-bottom:5px;"><?php echo esc_html($a['heading']); ?></div>
        <div style="font-size:14px;color:#555;margin-bottom:13px;"><?php echo esc_html($a['blurb']); ?></div>
        <?php if ($member): ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php echo $btn(lmeg_wallet_link((int) $member->id)); ?>
                <?php if ($gready) { $gu = lmeg_wallet_google_save_url((int) $member->id); if ($gu) echo $gbtn($gu); } ?>
            </div>
        <?php else: ?>
            <form method="post" action="<?php echo esc_url(add_query_arg('lmeg_wallet', 'add', home_url('/'))); ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input type="email" name="email" required placeholder="you@email.com"
                    style="flex:1;min-width:180px;padding:11px 13px;border:1px solid rgba(0,0,0,.2);border-radius:10px;font-size:15px;">
                <button type="submit" name="platform" value="apple" style="background:#000;color:#fff;border:0;border-radius:10px;padding:11px 16px;font:600 15px/1 -apple-system,sans-serif;cursor:pointer;">Add to Apple&nbsp;Wallet</button>
                <?php if ($gready): ?><button type="submit" name="platform" value="google" style="background:#fff;color:#3c4043;border:1px solid #dadce0;border-radius:10px;padding:11px 16px;font:600 15px/1 -apple-system,sans-serif;cursor:pointer;">Save to Google&nbsp;Wallet</button><?php endif; ?>
            </form>
            <?php if (!empty($_GET['lmeg_wallet_err'])): ?><div style="color:#c0392b;font-size:13px;margin-top:7px;">Please enter a valid email.</div><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/* =========================================================================
 * ITERATION 3 — PassKit web service (device registration + pass updates).
 * Apple calls {webServiceURL}/v1/... ; we expose those as WP REST routes under
 * /wp-json/lmeg-wallet/v1/... . Defining lmeg_wallet_ws_url() also flips issued
 * passes to include webServiceURL + authenticationToken (so they're updatable).
 * ====================================================================== */

/** Base URL Apple appends "/v1/..." to. */
function lmeg_wallet_ws_url() {
    return untrailingslashit(rest_url('lmeg-wallet'));
}

/** Pull the pass auth token out of the "Authorization: ApplePass <token>" header. */
function lmeg_wallet_ws_token($request) {
    $h = (string) $request->get_header('authorization');
    return (stripos($h, 'ApplePass ') === 0) ? trim(substr($h, 10)) : '';
}
function lmeg_wallet_ws_pass($serial) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . lmeg_wallet_table('passes') . " WHERE serial = %s", $serial));
}

add_action('rest_api_init', 'lmeg_wallet_rest_routes');
function lmeg_wallet_rest_routes() {
    $ns = 'lmeg-wallet';
    $open = '__return_true';   // Apple's device isn't WP-authenticated; we check the ApplePass token inside.

    register_rest_route($ns, '/v1/devices/(?P<device>[^/]+)/registrations/(?P<pt>[^/]+)/(?P<serial>[^/]+)', [
        ['methods' => 'POST',   'callback' => 'lmeg_wallet_ws_register',   'permission_callback' => $open],
        ['methods' => 'DELETE', 'callback' => 'lmeg_wallet_ws_unregister', 'permission_callback' => $open],
    ]);
    register_rest_route($ns, '/v1/devices/(?P<device>[^/]+)/registrations/(?P<pt>[^/]+)', [
        ['methods' => 'GET', 'callback' => 'lmeg_wallet_ws_serials', 'permission_callback' => $open],
    ]);
    register_rest_route($ns, '/v1/passes/(?P<pt>[^/]+)/(?P<serial>[^/]+)', [
        ['methods' => 'GET', 'callback' => 'lmeg_wallet_ws_latest', 'permission_callback' => $open],
    ]);
    register_rest_route($ns, '/v1/log', [
        ['methods' => 'POST', 'callback' => 'lmeg_wallet_ws_log', 'permission_callback' => $open],
    ]);
}

/** POST register a device's push token for a pass. */
function lmeg_wallet_ws_register($request) {
    global $wpdb;
    $pass = lmeg_wallet_ws_pass($request['serial']);
    if (!$pass || !hash_equals((string) $pass->auth_token, lmeg_wallet_ws_token($request))) return new WP_REST_Response(null, 401);
    $body = json_decode((string) $request->get_body(), true);
    $push = sanitize_text_field($body['pushToken'] ?? '');
    if ($push === '') return new WP_REST_Response(null, 400);
    $regs = lmeg_wallet_table('regs');
    $id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $regs WHERE device_lib_id = %s AND serial = %s", $request['device'], $pass->serial));
    if ($id) { $wpdb->update($regs, ['push_token' => $push], ['id' => $id]); return new WP_REST_Response(null, 200); }
    $wpdb->insert($regs, [
        'serial'        => $pass->serial,
        'device_lib_id' => sanitize_text_field($request['device']),
        'push_token'    => $push,
        'platform'      => 'apple',
        'created_at'    => current_time('mysql', true),
    ], ['%s','%s','%s','%s','%s']);
    return new WP_REST_Response(null, 201);
}

/** DELETE unregister a device from a pass. */
function lmeg_wallet_ws_unregister($request) {
    global $wpdb;
    $pass = lmeg_wallet_ws_pass($request['serial']);
    if (!$pass || !hash_equals((string) $pass->auth_token, lmeg_wallet_ws_token($request))) return new WP_REST_Response(null, 401);
    $wpdb->delete(lmeg_wallet_table('regs'), ['device_lib_id' => $request['device'], 'serial' => $pass->serial], ['%s','%s']);
    return new WP_REST_Response(null, 200);
}

/** GET serial numbers of this device's passes changed since a tag. */
function lmeg_wallet_ws_serials($request) {
    global $wpdb;
    $regs = lmeg_wallet_table('regs');
    $pas  = lmeg_wallet_table('passes');
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.serial AS serial, p.updated_at AS updated_at
         FROM $regs r JOIN $pas p ON p.serial = r.serial
         WHERE r.device_lib_id = %s", $request['device']));
    $since = (int) $request->get_param('passesUpdatedSince');
    $serials = []; $max = 0;
    foreach ((array) $rows as $r) {
        $ts = (int) strtotime($r->updated_at . ' UTC');
        if ($ts > $max) $max = $ts;
        if (!$since || $ts > $since) $serials[] = $r->serial;
    }
    if (!$serials) return new WP_REST_Response(null, 204);
    return new WP_REST_Response(['lastUpdated' => (string) ($max ?: time()), 'serialNumbers' => $serials], 200);
}

/** GET the latest .pkpass for a serial (honors If-Modified-Since → 304). */
function lmeg_wallet_ws_latest($request) {
    global $wpdb;
    $pass = lmeg_wallet_ws_pass($request['serial']);
    if (!$pass || !hash_equals((string) $pass->auth_token, lmeg_wallet_ws_token($request))) return new WP_REST_Response(null, 401);
    $lastmod = (int) strtotime($pass->updated_at . ' UTC');
    $ims = $request->get_header('if_modified_since');
    if ($ims && strtotime($ims) >= $lastmod) return new WP_REST_Response(null, 304);
    $sub = $pass->subscriber_id
        ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", (int) $pass->subscriber_id))
        : null;
    $res = lmeg_wallet_build_pkpass(lmeg_wallet_pass_for_row($pass, $sub));
    if (isset($res['error'])) return new WP_REST_Response(null, 500);
    nocache_headers();
    header('Content-Type: application/vnd.apple.pkpass');
    header('Content-Disposition: attachment; filename="' . $pass->serial . '.pkpass"');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastmod ?: time()) . ' GMT');
    header('Content-Length: ' . strlen($res['bytes']));
    echo $res['bytes'];
    exit;
}

/** POST device logs — Apple posts diagnostics here; keep them out of the way. */
function lmeg_wallet_ws_log($request) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $b = json_decode((string) $request->get_body(), true);
        foreach ((array) ($b['logs'] ?? []) as $line) error_log('[lmeg-wallet] ' . substr((string) $line, 0, 300));
    }
    return new WP_REST_Response(null, 200);
}

/* =========================================================================
 * ITERATION 4 — APNs push + pass updates + broadcast.
 * A Wallet "notification" = change a pass field (with a changeMessage) then
 * send an EMPTY push to every device registered for that pass. Free via APNs.
 * Dev-guarded: with no APNs key configured it no-ops and logs what it WOULD do.
 * ====================================================================== */

function lmeg_wallet_apns_settings() {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $c = lmeg_wallet_settings();
    return [
        'apns_key' => trim((string) ($s['wallet_apns_key'] ?? '')),      // APNs auth key (.p8 PEM inline or path)
        'key_id'   => trim((string) ($s['wallet_apns_key_id'] ?? '')),   // 10-char Key ID
        'team_id'  => $c['team_id'],
        'topic'    => $c['pass_type_id'],                                // APNs topic for pass pushes = passTypeIdentifier
        'sandbox'  => !empty($s['wallet_apns_sandbox']),
    ];
}
function lmeg_wallet_apns_ready() {
    $c = lmeg_wallet_apns_settings();
    return lmeg_wallet_pem($c['apns_key']) !== '' && $c['key_id'] !== '' && $c['team_id'] !== '' && $c['topic'] !== '';
}
function lmeg_wallet_b64url($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
/** DER ECDSA sig → raw R||S (64 bytes for P-256), as JWT ES256 requires. */
function lmeg_wallet_der2raw($der) {
    $o = 0; $L = strlen($der);
    if ($L < 8 || ord($der[$o++]) !== 0x30) return '';
    $len = ord($der[$o++]); if ($len & 0x80) { $n = $len & 0x7f; while ($n-- > 0) $o++; }
    if (ord($der[$o++]) !== 0x02) return '';
    $rl = ord($der[$o++]); $r = substr($der, $o, $rl); $o += $rl;
    if (ord($der[$o++]) !== 0x02) return '';
    $sl = ord($der[$o++]); $s = substr($der, $o, $sl);
    $r = ltrim($r, "\x00"); $s = ltrim($s, "\x00");
    if (strlen($r) > 32 || strlen($s) > 32) return '';
    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}
/** APNs provider JWT (ES256), cached ~50 min. '' if key missing/bad. */
function lmeg_wallet_apns_jwt() {
    static $cache = null;
    if ($cache && $cache['exp'] > time() + 120) return $cache['jwt'];
    $c   = lmeg_wallet_apns_settings();
    $pem = lmeg_wallet_pem($c['apns_key']);
    if ($pem === '' || $c['key_id'] === '' || $c['team_id'] === '') return '';
    $pkey = openssl_pkey_get_private($pem);
    if (!$pkey) return '';
    $now  = time();
    $head = lmeg_wallet_b64url(wp_json_encode(['alg' => 'ES256', 'kid' => $c['key_id']]));
    $body = lmeg_wallet_b64url(wp_json_encode(['iss' => $c['team_id'], 'iat' => $now]));
    $sig  = '';
    if (!openssl_sign($head . '.' . $body, $sig, $pkey, OPENSSL_ALGO_SHA256)) return '';
    $raw = lmeg_wallet_der2raw($sig);
    if ($raw === '') return '';
    $jwt = $head . '.' . $body . '.' . lmeg_wallet_b64url($raw);
    $cache = ['jwt' => $jwt, 'exp' => $now + 3000];
    return $jwt;
}
/** Send an empty APNs push to each pass push token. Dev-guarded. */
function lmeg_wallet_apns_push(array $tokens) {
    $tokens = array_values(array_unique(array_filter($tokens)));
    if (!lmeg_wallet_apns_ready()) {
        error_log('[lmeg-wallet] APNs not configured — would push ' . count($tokens) . ' device(s)');
        return ['sent' => 0, 'skipped' => count($tokens), 'dev' => true];
    }
    $jwt = lmeg_wallet_apns_jwt();
    if ($jwt === '') return ['error' => 'apns jwt build failed'];
    $c    = lmeg_wallet_apns_settings();
    $host = $c['sandbox'] ? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
    $ver  = defined('CURL_HTTP_VERSION_2_0') ? CURL_HTTP_VERSION_2_0 : 3;
    $sent = 0; $failed = 0; $gone = [];
    foreach ($tokens as $tok) {
        $ch = curl_init($host . '/3/device/' . rawurlencode($tok));
        curl_setopt_array($ch, [
            CURLOPT_HTTP_VERSION   => $ver,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '{}',
            CURLOPT_HTTPHEADER     => [
                'authorization: bearer ' . $jwt,
                'apns-topic: ' . $c['topic'],
                'apns-push-type: background',
                'apns-priority: 5',
                'content-type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) { $sent++; }
        else { $failed++; if ($code === 410) $gone[] = $tok; if (defined('WP_DEBUG') && WP_DEBUG) error_log('[lmeg-wallet] APNs ' . $code . ' ' . $resp); }
    }
    if ($gone) {  // 410 = token no longer valid; prune its registrations
        global $wpdb;
        foreach ($gone as $t) $wpdb->delete(lmeg_wallet_table('regs'), ['push_token' => $t], ['%s']);
    }
    return ['sent' => $sent, 'failed' => $failed];
}

/** Update one pass's fields (bumps updated_at) then push its devices. */
function lmeg_wallet_update($serial, array $fields = []) {
    global $wpdb;
    $tbl = lmeg_wallet_table('passes');
    $data = ['updated_at' => current_time('mysql', true), 'last_push_at' => current_time('mysql', true)];
    $fmt  = ['%s', '%s'];
    foreach (['latest', 'tier', 'headline'] as $k) if (isset($fields[$k])) { $data[$k] = (string) $fields[$k]; $fmt[] = '%s'; }
    $wpdb->update($tbl, $data, ['serial' => $serial], $fmt, ['%s']);
    $tokens = $wpdb->get_col($wpdb->prepare("SELECT push_token FROM " . lmeg_wallet_table('regs') . " WHERE serial = %s", $serial));
    $res = $tokens ? lmeg_wallet_apns_push($tokens) : ['sent' => 0];
    $res['devices'] = count((array) $tokens);
    return $res;
}

/** Push a one-line "LATEST" update to every pass (or a tag segment) + notify. */
function lmeg_wallet_broadcast($text, $segment = null) {
    global $wpdb;
    $pt = lmeg_wallet_table('passes');
    if ($segment === null || $segment === '') {
        $serials = $wpdb->get_col("SELECT serial FROM $pt WHERE platform = 'apple'");
    } else {
        $tags = $wpdb->prefix . 'lmeg_tags';
        $st   = $wpdb->prefix . 'lmeg_subscriber_tags';
        $serials = $wpdb->get_col($wpdb->prepare(
            "SELECT p.serial FROM $pt p
             JOIN $st s ON s.subscriber_id = p.subscriber_id
             JOIN $tags t ON t.id = s.tag_id
             WHERE t.slug = %s AND p.platform = 'apple'", $segment));
    }
    if (!$serials) return ['passes' => 0, 'devices' => 0, 'sent' => 0];
    $now = current_time('mysql', true);
    foreach ($serials as $s) $wpdb->update($pt, ['latest' => (string) $text, 'updated_at' => $now, 'last_push_at' => $now], ['serial' => $s], ['%s', '%s', '%s'], ['%s']);
    $in = implode(',', array_fill(0, count($serials), '%s'));
    $tokens = $wpdb->get_col($wpdb->prepare("SELECT push_token FROM " . lmeg_wallet_table('regs') . " WHERE serial IN ($in)", $serials));
    $push = $tokens ? lmeg_wallet_apns_push($tokens) : ['sent' => 0];
    return array_merge(['passes' => count($serials), 'devices' => count((array) $tokens)], $push);
}

/* =========================================================================
 * ITERATION 6 — Google Wallet (Save-to-Google-Wallet for Android fans).
 * A "skinny JWT" (RS256, signed with a Google Cloud service-account key) that
 * carries a Generic class + per-fan object; the Save URL is
 * https://pay.google.com/gp/v/save/<jwt>. Dev-guarded: no SA key → no button.
 * ====================================================================== */

function lmeg_wallet_google_settings() {
    $s  = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $sa = trim((string) ($s['wallet_google_sa_json'] ?? ''));
    if ($sa !== '' && strpos($sa, '{') === false && @is_file($sa) && @is_readable($sa)) $sa = (string) file_get_contents($sa);
    $j = json_decode($sa, true);
    return [
        'issuer_id'      => trim((string) ($s['wallet_google_issuer_id'] ?? '')),
        'client_email'   => is_array($j) ? (string) ($j['client_email'] ?? '') : '',
        'private_key'    => is_array($j) ? (string) ($j['private_key'] ?? '') : '',
        'private_key_id' => is_array($j) ? (string) ($j['private_key_id'] ?? '') : '',
    ];
}
function lmeg_wallet_google_ready() {
    $g = lmeg_wallet_google_settings();
    return $g['issuer_id'] !== '' && $g['client_email'] !== '' && $g['private_key'] !== '';
}
/** The Generic object for one fan's pass. */
function lmeg_wallet_google_object($pass, $sub) {
    $g = lmeg_wallet_google_settings();
    $c = lmeg_wallet_settings();
    $iss   = $g['issuer_id'];
    $objId = $iss . '.' . preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $pass->serial);
    $artist = $c['org'] ?: 'Fanloop';
    $fan    = $sub ? (trim((string) (($sub->first_name ?? '') ?: ($sub->email ?? 'Fan'))) ?: 'Fan') : 'Fan';
    $bg     = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c['bg']) ? $c['bg'] : '#141019';
    $checkin = home_url('/?lmeg_wallet=checkin&serial=' . rawurlencode((string) $pass->serial));
    return [
        'id'                 => $objId,
        'classId'            => $iss . '.fanloop_generic',
        'genericType'        => 'GENERIC_TYPE_UNSPECIFIED',
        'state'              => 'ACTIVE',
        'hexBackgroundColor' => $bg,
        'cardTitle' => ['defaultValue' => ['language' => 'en', 'value' => $artist]],
        'header'    => ['defaultValue' => ['language' => 'en', 'value' => $fan]],
        'subheader' => ['defaultValue' => ['language' => 'en', 'value' => 'Fan pass']],
        'textModulesData' => [['id' => 'tier', 'header' => 'Tier', 'body' => ($pass->tier ?: 'Fan')]],
        'barcode'   => ['type' => 'QR_CODE', 'value' => $checkin, 'alternateText' => (string) $pass->serial],
    ];
}
/** RS256 "save to wallet" JWT embedding the class + object. */
function lmeg_wallet_google_jwt($object) {
    $g = lmeg_wallet_google_settings();
    if (!$g['private_key']) return '';
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    if ($g['private_key_id']) $header['kid'] = $g['private_key_id'];
    $claims = [
        'iss'     => $g['client_email'],
        'aud'     => 'google',
        'typ'     => 'savetowallet',
        'iat'     => time(),
        'origins' => [home_url('/')],
        'payload' => [
            'genericClasses' => [['id' => $g['issuer_id'] . '.fanloop_generic']],
            'genericObjects' => [$object],
        ],
    ];
    $si  = lmeg_wallet_b64url(wp_json_encode($header)) . '.' . lmeg_wallet_b64url(wp_json_encode($claims));
    $pk  = openssl_pkey_get_private($g['private_key']);
    if (!$pk) return '';
    $sig = '';
    if (!openssl_sign($si, $sig, $pk, OPENSSL_ALGO_SHA256)) return '';   // RSA sig is already raw for JWT
    return $si . '.' . lmeg_wallet_b64url($sig);
}
/** The Save-to-Google-Wallet URL for a subscriber ('' if not configured). */
function lmeg_wallet_google_save_url($sid) {
    if (!lmeg_wallet_google_ready()) return '';
    global $wpdb;
    $pass = lmeg_wallet_issue_for_subscriber((int) $sid);
    if (!$pass) return '';
    $sub = $pass->subscriber_id
        ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", (int) $pass->subscriber_id))
        : null;
    $jwt = lmeg_wallet_google_jwt(lmeg_wallet_google_object($pass, $sub));
    return $jwt ? ('https://pay.google.com/gp/v/save/' . $jwt) : '';
}

/* =========================================================================
 * ITERATION 7 — Settings → Wallet admin page (config + readiness + test).
 * A self-contained submenu (own form/nonce/save) that merges wallet_* keys
 * into the lmeg_settings option — it never touches the main settings form.
 * ====================================================================== */

add_action('admin_menu', 'lmeg_wallet_admin_menu', 30);
function lmeg_wallet_admin_menu() {
    add_submenu_page('lmeg', 'Wallet Pass', 'Wallet Pass', 'manage_options', 'lmeg-wallet', 'lmeg_wallet_admin_page');
}
function lmeg_wallet_admin_page() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $notice = '';

    if (isset($_POST['lmeg_wallet_action']) && check_admin_referer('lmeg_wallet_settings', 'lmeg_wallet_settings_nonce')) {
        if ($_POST['lmeg_wallet_action'] === 'save') {
            $o = get_option(LMEG_OPTION, []);
            foreach (['wallet_org','wallet_logo_text','wallet_pass_type_id','wallet_team_id','wallet_cert_pass','wallet_apns_key_id','wallet_google_issuer_id'] as $k)
                $o[$k] = sanitize_text_field(wp_unslash($_POST[$k] ?? ''));
            foreach (['wallet_bg','wallet_fg','wallet_label'] as $k)
                $o[$k] = sanitize_hex_color(wp_unslash($_POST[$k] ?? '')) ?: ($o[$k] ?? '');
            foreach (['wallet_cert_pem','wallet_wwdr_pem','wallet_apns_key','wallet_google_sa_json'] as $k)
                $o[$k] = trim((string) wp_unslash($_POST[$k] ?? ''));   // credentials — stored raw (DB only)
            $o['wallet_apns_sandbox'] = !empty($_POST['wallet_apns_sandbox']) ? 1 : 0;
            $o['wallet_auto_drops']   = !empty($_POST['wallet_auto_drops']) ? '1' : '0';
            update_option(LMEG_OPTION, $o);
            $notice = '<div class="notice notice-success"><p>Wallet settings saved.</p></div>';
        } elseif ($_POST['lmeg_wallet_action'] === 'test') {
            $r = lmeg_wallet_broadcast('Test from Fanloop 🎫');
            $extra = !empty($r['dev']) ? ' (APNs not configured — no push actually sent)' : '';
            $notice = '<div class="notice notice-info"><p>Test: updated ' . (int) ($r['passes'] ?? 0) . ' pass(es), ' . (int) ($r['devices'] ?? 0) . ' device(s)' . esc_html($extra) . '.</p></div>';
        }
    }

    $c = lmeg_wallet_settings();
    $g = lmeg_wallet_google_settings();
    $s = get_option(LMEG_OPTION, []);
    $passes = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . lmeg_wallet_table('passes'));
    $regs   = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . lmeg_wallet_table('regs'));
    $dot = function ($state, $label) {   // $state: ok | warn | off
        $col = $state === 'ok' ? '#1a7f37' : ($state === 'warn' ? '#bf8700' : '#8c8f94');
        return '<div style="display:flex;align-items:center;gap:9px;margin:6px 0;"><span style="width:10px;height:10px;border-radius:50%;background:' . $col . ';flex:0 0 auto;"></span><span>' . $label . '</span></div>';
    };
    $val = function ($k) use ($s) { return esc_attr((string) ($s[$k] ?? '')); };
    $ta  = function ($k) use ($s) { return esc_textarea((string) ($s[$k] ?? '')); };
    ?>
    <div class="wrap">
        <h1>Fanloop — Wallet Pass</h1>
        <?php echo $notice; ?>
        <p class="description" style="max-width:760px;">A fan pass in Apple &amp; Google Wallet — a free lock-screen channel. Add the credentials below to go live; until then passes still build with a dev signature so you can test the flow.</p>

        <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start;">
          <div style="flex:1;min-width:340px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 18px;">
            <h2 style="margin-top:4px;">Status</h2>
            <?php
            echo $dot(lmeg_wallet_apple_ready() ? 'ok' : 'warn',
                lmeg_wallet_apple_ready() ? '<strong>Apple signing: production</strong> — passes trusted on real iPhones'
                : '<strong>Apple signing: dev self-signed</strong> — add your Pass Type ID cert + WWDR to go live');
            echo $dot(lmeg_wallet_apns_ready() ? 'ok' : 'warn',
                lmeg_wallet_apns_ready() ? '<strong>Push: ready</strong> — lock-screen updates will send'
                : '<strong>Push: off</strong> — add your APNs key to send updates');
            echo $dot(lmeg_wallet_google_ready() ? 'ok' : 'off',
                lmeg_wallet_google_ready() ? '<strong>Google Wallet: ready</strong>'
                : '<strong>Google Wallet: off</strong> — add a service account to offer Android');
            ?>
            <p style="margin-top:12px;"><strong><?php echo $passes; ?></strong> pass(es) issued · <strong><?php echo $regs; ?></strong> device registration(s)</p>
            <p class="description">Add the button anywhere with <code>[fanloop_wallet]</code>.</p>
            <p class="description">Web-service URL (auto): <code style="word-break:break-all;"><?php echo esc_html(function_exists('lmeg_wallet_ws_url') ? lmeg_wallet_ws_url() : ''); ?></code></p>
            <form method="post" style="margin-top:10px;">
                <?php wp_nonce_field('lmeg_wallet_settings', 'lmeg_wallet_settings_nonce'); ?>
                <input type="hidden" name="lmeg_wallet_action" value="test" />
                <button class="button" <?php echo $passes ? '' : 'disabled'; ?>>Send a test Wallet update</button>
            </form>
          </div>

          <form method="post" style="flex:2;min-width:420px;">
            <?php wp_nonce_field('lmeg_wallet_settings', 'lmeg_wallet_settings_nonce'); ?>
            <input type="hidden" name="lmeg_wallet_action" value="save" />

            <h2>Branding</h2>
            <table class="form-table" role="presentation">
                <tr><th>Pass name</th><td><input type="text" name="wallet_org" class="regular-text" value="<?php echo $val('wallet_org'); ?>" placeholder="Artist name" /></td></tr>
                <tr><th>Logo text</th><td><input type="text" name="wallet_logo_text" class="regular-text" value="<?php echo $val('wallet_logo_text'); ?>" placeholder="Artist name" /></td></tr>
                <tr><th>Colours</th><td>
                    Background <input type="text" name="wallet_bg" value="<?php echo $val('wallet_bg') ?: '#141019'; ?>" style="width:90px" />
                    Text <input type="text" name="wallet_fg" value="<?php echo $val('wallet_fg') ?: '#ffffff'; ?>" style="width:90px" />
                    Accent <input type="text" name="wallet_label" value="<?php echo $val('wallet_label') ?: '#c7b9ff'; ?>" style="width:90px" />
                    <p class="description">Hex, e.g. #141019.</p>
                </td></tr>
                <tr><th>New drops</th><td><label><input type="checkbox" name="wallet_auto_drops" value="1" <?php checked(($s['wallet_auto_drops'] ?? '1') !== '0'); ?> /> Auto-push new releases to Wallet pass-holders</label>
                    <p class="description">When a Release Drop goes live it also updates fans' passes + pushes a lock-screen alert (to the drop's audience).</p></td></tr>
            </table>

            <h2>Apple Wallet (PassKit)</h2>
            <table class="form-table" role="presentation">
                <tr><th>Pass Type ID</th><td><input type="text" name="wallet_pass_type_id" class="regular-text" value="<?php echo $val('wallet_pass_type_id'); ?>" placeholder="pass.ca.portermedia.loony" /></td></tr>
                <tr><th>Team ID</th><td><input type="text" name="wallet_team_id" class="regular-text" value="<?php echo $val('wallet_team_id'); ?>" placeholder="10-char Apple Team ID" /></td></tr>
                <tr><th>Signing cert (PEM)</th><td><textarea name="wallet_cert_pem" rows="4" class="large-text code" placeholder="-----BEGIN CERTIFICATE----- … (cert + private key, or a server path)"><?php echo $ta('wallet_cert_pem'); ?></textarea></td></tr>
                <tr><th>Cert password</th><td><input type="text" name="wallet_cert_pass" class="regular-text" value="<?php echo $val('wallet_cert_pass'); ?>" autocomplete="off" /></td></tr>
                <tr><th>WWDR cert (PEM)</th><td><textarea name="wallet_wwdr_pem" rows="3" class="large-text code" placeholder="Apple WWDR intermediate cert (PEM), or a server path"><?php echo $ta('wallet_wwdr_pem'); ?></textarea></td></tr>
            </table>

            <h2>Push (APNs)</h2>
            <table class="form-table" role="presentation">
                <tr><th>APNs key (.p8)</th><td><textarea name="wallet_apns_key" rows="4" class="large-text code" placeholder="-----BEGIN PRIVATE KEY----- … (AuthKey_XXXX.p8 contents, or a server path)"><?php echo $ta('wallet_apns_key'); ?></textarea></td></tr>
                <tr><th>Key ID</th><td><input type="text" name="wallet_apns_key_id" class="regular-text" value="<?php echo $val('wallet_apns_key_id'); ?>" placeholder="10-char Key ID" /></td></tr>
                <tr><th>Sandbox</th><td><label><input type="checkbox" name="wallet_apns_sandbox" value="1" <?php checked(!empty($s['wallet_apns_sandbox'])); ?> /> Use the APNs sandbox host (testing)</label></td></tr>
            </table>

            <h2>Google Wallet</h2>
            <table class="form-table" role="presentation">
                <tr><th>Issuer ID</th><td><input type="text" name="wallet_google_issuer_id" class="regular-text" value="<?php echo $val('wallet_google_issuer_id'); ?>" placeholder="3388000000022…" /></td></tr>
                <tr><th>Service account JSON</th><td><textarea name="wallet_google_sa_json" rows="4" class="large-text code" placeholder='{ "client_email": "...", "private_key": "-----BEGIN PRIVATE KEY----- …" }'><?php echo $ta('wallet_google_sa_json'); ?></textarea></td></tr>
            </table>

            <p><button class="button button-primary">Save Wallet settings</button></p>
            <p class="description">Credentials are stored in your WordPress database (the <code>lmeg_settings</code> option), never in any file.</p>
          </form>
        </div>
    </div>
    <?php
}
