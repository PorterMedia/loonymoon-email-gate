<?php
/**
 * Consumer-facing multi-language layer.
 *
 * Two kinds of strings get translated:
 *   1. Fanloop's built-in UI words ("Email", "Phone", "Subscribe" …) — from the
 *      dictionary in lmeg_i18n_strings(), via lmeg_t().
 *   2. The artist's own custom copy (headings, success/consent messages …) —
 *      the base value is the English setting; French lives in
 *      $settings['i18n']['fr'][<key>], resolved via lmeg_scopy().
 *
 * Language is resolved by FOLLOWING the site's language plugin when one is
 * active (WPML / Polylang), so Fanloop matches the site's own switcher. On
 * sites with no language plugin, Fanloop uses its own toggle / browser detect.
 * Emails render in each fan's saved language via lmeg_lang_override().
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Languages Fanloop knows how to label, code => native name. */
function lmeg_known_langs() {
    return [
        'en' => 'English',
        'fr' => 'Français',
        'es' => 'Español',
        'de' => 'Deutsch',
        'pt' => 'Português',
        'it' => 'Italiano',
        'nl' => 'Nederlands',
    ];
}

/** Enabled languages for this site (always includes English + the default). */
function lmeg_enabled_langs() {
    $s   = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $set = isset($s['languages']) && is_array($s['languages']) ? $s['languages'] : ['en'];
    $known = array_keys(lmeg_known_langs());
    $out = [];
    foreach ($set as $l) { $l = strtolower(substr((string) $l, 0, 2)); if (in_array($l, $known, true)) $out[] = $l; }
    if (!in_array('en', $out, true)) $out[] = 'en';
    $def = lmeg_default_lang();
    if (!in_array($def, $out, true)) $out[] = $def;
    return array_values(array_unique($out));
}

function lmeg_default_lang() {
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $d = strtolower(substr((string) ($s['default_lang'] ?? 'en'), 0, 2));
    return array_key_exists($d, lmeg_known_langs()) ? $d : 'en';
}

function lmeg_multilang_on() {
    return count(lmeg_enabled_langs()) > 1;
}

/** Which site language plugin is driving language, if any. */
function lmeg_site_lang_plugin() {
    if (defined('ICL_LANGUAGE_CODE') || has_filter('wpml_current_language')) return 'wpml';
    if (function_exists('pll_current_language')) return 'polylang';
    return '';
}

/**
 * Force the render language (used when composing an email for a specific fan).
 * Pass a code to set, '' to clear, or nothing to read.
 */
function lmeg_lang_override($set = '__read__') {
    static $ov = '';
    if ($set !== '__read__') $ov = $set ? strtolower(substr((string) $set, 0, 2)) : '';
    return $ov;
}

/** The language to render right now. */
function lmeg_current_lang() {
    $ov = lmeg_lang_override();
    if ($ov) {
        $enabled = lmeg_enabled_langs();
        return in_array($ov, $enabled, true) ? $ov : lmeg_default_lang();
    }
    static $lang = null;
    if ($lang !== null) return $lang;

    $enabled = lmeg_enabled_langs();
    $default = lmeg_default_lang();
    $pick = '';

    // 1. Follow the site's language plugin.
    $plugin = lmeg_site_lang_plugin();
    if ($plugin === 'wpml') {
        $pick = defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : (string) apply_filters('wpml_current_language', null);
    } elseif ($plugin === 'polylang' && function_exists('pll_current_language')) {
        $pick = (string) pll_current_language('slug');
    }

    // 2. No site plugin → our own toggle / cookie / browser.
    if (!$pick) {
        if (!empty($_GET['lmeg_lang']))         $pick = sanitize_key(wp_unslash($_GET['lmeg_lang']));
        elseif (!empty($_COOKIE['lmeg_lang']))  $pick = sanitize_key(wp_unslash($_COOKIE['lmeg_lang']));
        elseif (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) $pick = substr((string) $_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    }

    $pick = strtolower(substr((string) $pick, 0, 2));
    $lang = in_array($pick, $enabled, true) ? $pick : $default;
    return $lang;
}

/** Persist an explicit ?lmeg_lang= choice to a cookie (no site plugin case). */
add_action('init', 'lmeg_maybe_set_lang_cookie');
function lmeg_maybe_set_lang_cookie() {
    if (empty($_GET['lmeg_lang']) || headers_sent()) return;
    $l = sanitize_key(wp_unslash($_GET['lmeg_lang']));
    if (in_array($l, lmeg_enabled_langs(), true)) {
        setcookie('lmeg_lang', $l, time() + YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN);
        $_COOKIE['lmeg_lang'] = $l;
    }
}

/**
 * Translate a built-in UI string. Extra args are passed to vsprintf.
 * Falls back to English, then to the key itself.
 */
function lmeg_t($key) {
    $dict = lmeg_i18n_strings();
    $lang = lmeg_current_lang();
    $str  = $dict[$lang][$key] ?? ($dict['en'][$key] ?? $key);
    $args = array_slice(func_get_args(), 1);
    return $args ? vsprintf($str, $args) : $str;
}

/**
 * Resolve an artist-configured copy setting in the current (or given) language:
 * the French override if present, otherwise the base (default-language) value.
 */
function lmeg_scopy($key, $lang = null) {
    $s    = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    $lang = $lang ?: lmeg_current_lang();
    if ($lang !== lmeg_default_lang() && !empty($s['i18n'][$lang][$key]) && trim((string) $s['i18n'][$lang][$key]) !== '') {
        return $s['i18n'][$lang][$key];
    }
    return $s[$key] ?? '';
}

/** The artist-copy settings that can be translated (key => admin label). */
function lmeg_translatable_copy_keys() {
    return [
        'form_heading'           => 'Gate heading',
        'form_message'           => 'Gate message',
        'button_text'            => 'Gate button',
        'consent_text'           => 'Consent line',
        'address_message'        => 'Address prompt',
        'signup_success_message' => 'Signup success message',
        'paywall_heading'        => 'Paywall heading',
        'paywall_premium_label'  => 'Premium button label',
        'paywall_unlock_label'   => 'Unlock button label',
        'soft_paywall_message'   => 'Soft-paywall message',
        'welcome_subject'        => 'Welcome email subject',
        'welcome_body'           => 'Welcome email body',
        'email_footer_note'      => 'Email footer note',
        'unsub_footer_text'      => 'Unsubscribe footer',
    ];
}

/** The built-in UI dictionary. Add languages/keys here to extend. */
function lmeg_i18n_strings() {
    static $d = null;
    if ($d !== null) return $d;
    $d = [
        'en' => [
            'email'            => 'Email',
            'phone'            => 'Phone',
            'country'          => 'Country',
            'select'           => 'Select…',
            'subscribe'        => 'Subscribe',
            'email_ph'         => 'you@example.com',
            'phone_ph'         => '555 123 4567',
            'youre_on_list'    => "You're on the list",
            'enter_contest'    => 'Enter the contest',
            'join_to_vote'     => 'Join the list to vote —',
            'sign_in'          => 'sign in',
            'presale'          => 'Presale',
            'presale_members'  => 'Presale for members',
            'tickets'          => 'Tickets',
            'sold_out'         => 'Sold out',
            'notify_me'        => 'Notify me',
            'no_dates'         => "No upcoming dates — join the list and you'll hear first.",
            'add_address'      => 'Add mailing address',
            'confirm_signup'   => 'Confirm my signup',
            'confirm_intro'    => "One tap and you're in:",
            'confirm_ignore'   => "If you didn't sign up, just ignore this email.",
            'youre_entered'    => "You're entered!",
        ],
        'fr' => [
            'email'            => 'Courriel',
            'phone'            => 'Téléphone',
            'country'          => 'Pays',
            'select'           => 'Choisir…',
            'subscribe'        => "S'abonner",
            'email_ph'         => 'toi@exemple.com',
            'phone_ph'         => '514 123 4567',
            'youre_on_list'    => 'Tu es sur la liste',
            'enter_contest'    => 'Participer au concours',
            'join_to_vote'     => 'Abonne-toi pour voter —',
            'sign_in'          => 'se connecter',
            'presale'          => 'Prévente',
            'presale_members'  => 'Prévente pour les membres',
            'tickets'          => 'Billets',
            'sold_out'         => 'Complet',
            'notify_me'        => 'Avertis-moi',
            'no_dates'         => "Aucune date à venir — abonne-toi pour être averti en premier.",
            'add_address'      => 'Ajouter une adresse postale',
            'confirm_signup'   => 'Confirmer mon inscription',
            'confirm_intro'    => 'Un clic et le tour est joué :',
            'confirm_ignore'   => "Si tu ne t'es pas inscrit, ignore ce courriel.",
            'youre_entered'    => 'Tu participes!',
        ],
    ];
    return $d;
}

/**
 * Front-end language switcher (EN | FR pills). Only rendered when multi-language
 * is on AND no site plugin is already providing a switcher.
 */
function lmeg_lang_switcher() {
    if (!lmeg_multilang_on() || lmeg_site_lang_plugin()) return '';
    $cur   = lmeg_current_lang();
    $names = lmeg_known_langs();
    $base  = remove_query_arg('lmeg_lang');
    $out   = '<div class="lmeg-langswitch" role="group" aria-label="Language">';
    foreach (lmeg_enabled_langs() as $l) {
        $url = esc_url(add_query_arg('lmeg_lang', $l, $base));
        $cls = 'lmeg-langswitch__btn' . ($l === $cur ? ' is-active' : '');
        $out .= '<a class="' . $cls . '" href="' . $url . '">' . esc_html(strtoupper($l)) . '</a>';
    }
    $out .= '</div>';
    return $out;
}

/** Sanitize the enabled-languages POST array (en is always kept). */
function lmeg_sanitize_languages($raw) {
    $known = array_keys(lmeg_known_langs());
    $out = ['en'];
    foreach ((array) $raw as $l) {
        $l = strtolower(substr((string) $l, 0, 2));
        if (in_array($l, $known, true) && !in_array($l, $out, true)) $out[] = $l;
    }
    return array_values($out);
}

/** Sanitize the per-language translated-copy POST array. */
function lmeg_sanitize_i18n($raw) {
    if (!is_array($raw)) return [];
    $keys  = array_keys(lmeg_translatable_copy_keys());
    $known = array_keys(lmeg_known_langs());
    $rich  = ['welcome_body', 'form_message', 'soft_paywall_message'];
    $out = [];
    foreach ($raw as $lang => $fields) {
        $lang = strtolower(substr((string) $lang, 0, 2));
        if (!in_array($lang, $known, true) || !is_array($fields)) continue;
        foreach ($fields as $k => $v) {
            if (!in_array($k, $keys, true)) continue;
            $v = wp_unslash($v);
            $v = in_array($k, $rich, true) ? wp_kses_post($v) : sanitize_textarea_field($v);
            if (trim((string) $v) !== '') $out[$lang][$k] = $v;
        }
    }
    return $out;
}

/**
 * Raw detected language for STORAGE — the fan's real browsing language (site
 * plugin > toggle > cookie > browser), validated only to a known language
 * (NOT gated by the enabled list). So a fan on the French WPML page is recorded
 * as French even before the artist has switched on French UI/translations.
 */
function lmeg_detect_lang() {
    $pick   = '';
    $plugin = lmeg_site_lang_plugin();
    if ($plugin === 'wpml') {
        $pick = defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : (string) apply_filters('wpml_current_language', null);
    } elseif ($plugin === 'polylang' && function_exists('pll_current_language')) {
        $pick = (string) pll_current_language('slug');
    }
    if (!$pick) {
        if (!empty($_GET['lmeg_lang']))         $pick = sanitize_key(wp_unslash($_GET['lmeg_lang']));
        elseif (!empty($_COOKIE['lmeg_lang']))  $pick = sanitize_key(wp_unslash($_COOKIE['lmeg_lang']));
        elseif (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) $pick = substr((string) $_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    }
    $pick = strtolower(substr((string) $pick, 0, 2));
    return array_key_exists($pick, lmeg_known_langs()) ? $pick : '';
}

/** Language to STORE for a signing-up fan: the form's field wins, else detect. */
function lmeg_signup_lang() {
    if (!empty($_POST['lmeg_lang'])) {
        $p = strtolower(substr(sanitize_key(wp_unslash($_POST['lmeg_lang'])), 0, 2));
        if (array_key_exists($p, lmeg_known_langs())) return $p;
    }
    return lmeg_detect_lang();
}
