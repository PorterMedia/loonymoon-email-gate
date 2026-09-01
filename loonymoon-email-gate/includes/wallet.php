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
