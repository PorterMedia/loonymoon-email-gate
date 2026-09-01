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

if (!defined('LMEG_WALLET_DB_VERSION')) define('LMEG_WALLET_DB_VERSION', '1');

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

    update_option('lmeg_wallet_db_version', LMEG_WALLET_DB_VERSION);
}
function lmeg_wallet_table($which = 'passes') {
    global $wpdb;
    return $wpdb->prefix . ($which === 'regs' ? 'lmeg_wallet_registrations' : 'lmeg_wallet_passes');
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
        'barcode_message' => $row->serial,     // scannable fan id (show check-in lands later)
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
        // hand back the .pkpass directly so Wallet opens it
        wp_safe_redirect(lmeg_wallet_link($sid));
        exit;
    }
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

    $member = function_exists('lmeg_current_member') ? lmeg_current_member() : null;
    ob_start(); ?>
    <div class="lmeg-wallet-cta" style="max-width:420px;border:1px solid rgba(0,0,0,.12);border-radius:14px;padding:18px 20px;">
        <div style="font:700 17px/1.2 -apple-system,BlinkMacSystemFont,sans-serif;margin-bottom:5px;"><?php echo esc_html($a['heading']); ?></div>
        <div style="font-size:14px;color:#555;margin-bottom:13px;"><?php echo esc_html($a['blurb']); ?></div>
        <?php if ($member): ?>
            <?php echo $btn(lmeg_wallet_link((int) $member->id)); ?>
        <?php else: ?>
            <form method="post" action="<?php echo esc_url(add_query_arg('lmeg_wallet', 'add', home_url('/'))); ?>" style="display:flex;gap:8px;flex-wrap:wrap;">
                <input type="email" name="email" required placeholder="you@email.com"
                    style="flex:1;min-width:180px;padding:11px 13px;border:1px solid rgba(0,0,0,.2);border-radius:10px;font-size:15px;">
                <button type="submit" style="background:#000;color:#fff;border:0;border-radius:10px;padding:11px 16px;font:600 15px/1 -apple-system,sans-serif;cursor:pointer;">Add to Apple&nbsp;Wallet</button>
            </form>
            <?php if (!empty($_GET['lmeg_wallet_err'])): ?><div style="color:#c0392b;font-size:13px;margin-top:7px;">Please enter a valid email.</div><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
