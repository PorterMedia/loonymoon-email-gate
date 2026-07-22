<?php
/**
 * Shortcodes — embeddable signup form.
 *
 * Usage:
 *   [lmeg_signup]
 *   [lmeg_signup heading="Get the newsletter" message="Weekly essays. Free."]
 *   [lmeg_signup style="inline" button="Join"]
 *   [lmeg_signup style="minimal" phone="yes"]
 *
 * All signups POST to the same admin-post handler as the main gate, so
 * subscribers created here go through auto-tagging, welcome email, member
 * cookie, and everything else. On success the user lands back on the
 * embedding page with `?lmeg_signup=ok` — which the shortcode swaps for
 * a confirmation panel in-place.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('lmeg_signup',  'lmeg_shortcode_signup');
add_shortcode('lmeg_premium', 'lmeg_shortcode_premium');
function lmeg_shortcode_signup($atts = []) {
    $atts = shortcode_atts([
        'heading'  => '',
        'message'  => '',
        'button'   => 'Subscribe',
        'style'    => 'card',       // card | inline | minimal
        'phone'    => 'no',         // yes | no
        'consent'  => 'auto',       // auto (from settings) | none | custom text
        'redirect' => '',           // where to send them after signup
        'success'  => '',           // blank = use the Settings message; set to override per-embed
        'tiers'    => '',           // ''=free only; 'all'=every active tier; '1,2'=specific IDs
        'divider'  => 'or',         // text shown between free button and tier cards
        'contest'  => '',           // contest ID to auto-enter after signup
    ], $atts, 'lmeg_signup');

    // Prefill from the URL (?lmeg_email= / ?lmeg_phone=) so a link can arrive
    // with the address already filled in.
    $prefill_email = isset($_GET['lmeg_email']) ? sanitize_email(wp_unslash($_GET['lmeg_email'])) : '';
    $prefill_phone = isset($_GET['lmeg_phone']) ? preg_replace('/[^\d+]/', '', (string) wp_unslash($_GET['lmeg_phone'])) : '';
    $contest_join  = (int) $atts['contest'];

    // Success copy: per-embed attr wins; otherwise the site-wide setting.
    if ($atts['success'] === '') {
        $s_all = lmeg_get_settings();
        $atts['success'] = $s_all['signup_success_message'] ?: 'Thank you for joining the loonybin';
    }

    // Resolve tier list. Empty = no tiers (current behavior); "all" = every
    // active tier; comma list = filter to those IDs.
    $tier_list = [];
    if (!empty($atts['tiers']) && function_exists('lmeg_all_tiers')) {
        $all = lmeg_all_tiers(true);
        if (strtolower($atts['tiers']) === 'all') {
            $tier_list = $all;
        } else {
            $wanted = array_filter(array_map('intval', explode(',', $atts['tiers'])));
            if ($wanted) {
                $tier_list = array_values(array_filter($all, function ($t) use ($wanted) {
                    return in_array((int) $t->id, $wanted, true);
                }));
            }
        }
    }
    $show_tiers = !empty($tier_list);
    // Always resolve the current member so the form can show WHO it recognizes
    // (e.g. after a one-tap {contest_link} sign-in) — not only on tier forms.
    $member     = function_exists('lmeg_current_member') ? lmeg_current_member() : null;

    // Per-render instance id so multiple embeds on one page each get a
    // scroll anchor and unique field ids.
    static $inst = 0;
    $inst++;
    $id = 'lmeg-embed-' . $inst;

    $style = in_array($atts['style'], ['card', 'inline', 'minimal'], true) ? $atts['style'] : 'card';
    $show_phone = strtolower($atts['phone']) === 'yes';

    // Consent copy: 'auto' pulls the site-wide consent line from settings;
    // 'none' hides it entirely; anything else is used verbatim.
    $consent = '';
    if ($atts['consent'] === 'auto') {
        $s = lmeg_get_settings();
        $consent = $s['consent_text'] ?? '';
    } elseif ($atts['consent'] !== 'none') {
        $consent = $atts['consent'];
    }

    // Success state — same page, ?lmeg_signup=ok query param, anchor to the
    // first embed instance. If several embeds are on the page they'll all
    // show the success message; that's acceptable and cheaper than tracking.
    if (!empty($_GET['lmeg_signup']) && $_GET['lmeg_signup'] === 'ok') {
        return '<div id="' . esc_attr($id) . '" class="lmeg-embed lmeg-embed--' . esc_attr($style) . ' lmeg-embed--success" role="status">'
             . '<div class="lmeg-embed__success-icon" aria-hidden="true">✓</div>'
             . '<p>' . esc_html($atts['success']) . '</p>'
             . '</div>';
    }

    // Compute the return URL: the current page + ?lmeg_signup=ok + #anchor.
    $current   = home_url(add_query_arg(null, null));
    $redirect  = $atts['redirect'] ?: $current;
    $redirect  = add_query_arg('lmeg_signup', 'ok', $redirect) . '#' . $id;

    $nonce     = wp_create_nonce('lmeg_submit');
    $action    = esc_url(admin_url('admin-post.php'));
    $countries = function_exists('lmeg_countries') ? lmeg_countries() : [];

    ob_start();
    ?>
    <div id="<?php echo esc_attr($id); ?>" class="lmeg-embed lmeg-embed--<?php echo esc_attr($style); ?>">
        <?php if ($atts['heading']) : ?>
            <div class="lmeg-embed__heading"><?php echo esc_html($atts['heading']); ?></div>
        <?php endif; ?>
        <?php if ($atts['message']) : ?>
            <p class="lmeg-embed__message"><?php echo esc_html($atts['message']); ?></p>
        <?php endif; ?>

        <form class="lmeg-form lmeg-embed__form" method="post" action="<?php echo $action; ?>" novalidate>
            <input type="hidden" name="action"            value="lmeg_submit" />
            <input type="hidden" name="_wpnonce"          value="<?php echo esc_attr($nonce); ?>" />
            <input type="hidden" name="redirect"          value="<?php echo esc_url($redirect); ?>" />
            <input type="hidden" name="contact_type"      value="email" />
            <input type="hidden" name="phone_country_iso" value="US" />

            <div class="lmeg-hp-wrap" aria-hidden="true">
                <label>Leave this empty<input type="text" name="lmeg_hp" value="" tabindex="-1" autocomplete="off" /></label>
            </div>

            <?php if ($member) : ?>
                <p class="lmeg-embed__member">
                    ✓ You&rsquo;re on the list as <strong><?php echo esc_html($member->email ?: $member->phone); ?></strong>.
                    <a href="?lmeg_member=logout" style="opacity:.7;">Not you?</a>
                </p>
                <?php if ($member->email) : ?><input type="hidden" name="email" value="<?php echo esc_attr($member->email); ?>" /><?php endif; ?>
            <?php else : ?>
                <?php if ($show_phone) : ?>
                    <div class="lmeg-tabs" role="tablist" aria-label="Contact method">
                        <button type="button" class="lmeg-tab is-active" role="tab" aria-selected="true"  data-channel="email">Email</button>
                        <button type="button" class="lmeg-tab"           role="tab" aria-selected="false" data-channel="phone">Phone</button>
                    </div>
                <?php endif; ?>

                <?php if ($contest_join) : ?><input type="hidden" name="lmeg_contest_join" value="<?php echo (int) $contest_join; ?>" /><?php endif; ?>
                <div class="lmeg-embed__row">
                    <div class="lmeg-field lmeg-field-email">
                        <label class="lmeg-embed__label" for="<?php echo esc_attr($id); ?>-email">Email</label>
                        <input type="email" id="<?php echo esc_attr($id); ?>-email" name="email" required autocomplete="email"
                               placeholder="you@example.com" class="lmeg-input" value="<?php echo esc_attr($prefill_email); ?>" />
                    </div>

                    <?php if ($show_phone) : ?>
                        <div class="lmeg-field lmeg-field-phone" hidden>
                            <label class="lmeg-embed__label" for="<?php echo esc_attr($id); ?>-phone">Phone</label>
                            <div class="lmeg-phone-row">
                                <select name="phone_country" class="lmeg-select" aria-label="Country">
                                    <?php foreach ($countries as $c) :
                                        $sel = ($c[0] === 'US') ? ' selected' : '';
                                    ?>
                                        <option value="<?php echo esc_attr($c[0]); ?>" data-dial="<?php echo esc_attr($c[2]); ?>"<?php echo $sel; ?>>
                                            <?php echo esc_html(lmeg_flag_emoji($c[0]) . ' ' . $c[1] . ' (+' . $c[2] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="lmeg-dial" aria-hidden="true">+1</span>
                                <input type="tel" id="<?php echo esc_attr($id); ?>-phone" name="phone" inputmode="tel"
                                       placeholder="555 123 4567" class="lmeg-input" autocomplete="tel-national" value="<?php echo esc_attr($prefill_phone); ?>" />
                            </div>
                        </div>
                    <?php endif; ?>

                    <button type="submit" name="lmeg_after" value="free" class="lmeg-button lmeg-embed__button"><?php echo esc_html($atts['button']); ?></button>
                </div>
            <?php endif; ?>

            <?php if ($show_tiers) : ?>
                <?php if (!$member) : ?>
                    <div class="lmeg-embed__divider"><span><?php echo esc_html($atts['divider']); ?></span></div>
                <?php endif; ?>
                <div class="lmeg-tiers lmeg-tiers--grid">
                    <?php foreach ($tier_list as $t) : ?>
                        <div class="lmeg-tier">
                            <div class="lmeg-tier__name"><?php echo esc_html($t->name); ?></div>
                            <?php if ($t->description) : ?>
                                <p class="lmeg-tier__desc"><?php echo esc_html($t->description); ?></p>
                            <?php endif; ?>
                            <div class="lmeg-tier__prices">
                                <?php if ($t->price_monthly) : ?>
                                    <button type="submit" name="lmeg_after" value="checkout:<?php echo (int) $t->id; ?>:monthly" class="lmeg-button lmeg-tier__cta">
                                        <span class="lmeg-tier__price"><?php echo esc_html(lmeg_format_price($t->price_monthly, $t->currency)); ?></span>
                                        <span class="lmeg-tier__period">/ month</span>
                                    </button>
                                <?php endif; ?>
                                <?php if ($t->price_annual) : ?>
                                    <button type="submit" name="lmeg_after" value="checkout:<?php echo (int) $t->id; ?>:annual" class="lmeg-button lmeg-button--outline lmeg-tier__cta">
                                        <span class="lmeg-tier__price"><?php echo esc_html(lmeg_format_price($t->price_annual, $t->currency)); ?></span>
                                        <span class="lmeg-tier__period">/ year</span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($consent && !$member) : ?>
                <p class="lmeg-embed__consent"><?php echo esc_html($consent); ?></p>
            <?php endif; ?>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * [lmeg_premium] — embeddable tier-selection form for premium access.
 *
 * Renders tier cards (from Email Gate → Tiers (Paid)) and lets visitors
 * subscribe in one form submission — email captured + Stripe Checkout
 * created in a single request. Existing members skip the email input
 * and go straight to checkout on tier click.
 *
 * Params:
 *   heading   — bold heading above the tiers
 *   message   — subheading / blurb text
 *   style     — card (default, boxed) | minimal (no card chrome)
 *   tiers     — comma-separated tier IDs to show (default: all active)
 *   button    — override the CTA on tier buttons (rarely needed)
 *
 * Examples:
 *   [lmeg_premium]
 *   [lmeg_premium heading="Support the work" message="Join the club."]
 *   [lmeg_premium style="minimal" tiers="1,2"]
 */
function lmeg_shortcode_premium($atts = []) {
    $atts = shortcode_atts([
        'heading' => '',
        'message' => '',
        'style'   => 'card',
        'tiers'   => '',
    ], $atts, 'lmeg_premium');

    if (!function_exists('lmeg_all_tiers')) {
        return '';
    }

    $all_tiers = lmeg_all_tiers(true);
    if (!empty($atts['tiers'])) {
        $wanted = array_filter(array_map('intval', explode(',', $atts['tiers'])));
        if ($wanted) {
            $all_tiers = array_values(array_filter($all_tiers, function ($t) use ($wanted) {
                return in_array((int) $t->id, $wanted, true);
            }));
        }
    }

    if (empty($all_tiers)) {
        return '<div class="lmeg-embed lmeg-embed--card lmeg-embed--premium"><em>No paid tiers configured yet. Add one at Email Gate → Tiers (Paid).</em></div>';
    }

    $member = function_exists('lmeg_current_member') ? lmeg_current_member() : null;
    $style  = in_array($atts['style'], ['card', 'minimal'], true) ? $atts['style'] : 'card';

    static $inst = 0;
    $inst++;
    $id = 'lmeg-premium-' . $inst;

    // Redirect back to the current page after signup — the paywall handler
    // will divert to Stripe Checkout when lmeg_after=checkout:... is set, so
    // this fallback URL is only used if something goes wrong upstream.
    $current  = home_url(add_query_arg(null, null));
    $redirect = $current;

    $nonce  = wp_create_nonce('lmeg_submit');
    $action = esc_url(admin_url('admin-post.php'));

    ob_start();
    ?>
    <div id="<?php echo esc_attr($id); ?>" class="lmeg-embed lmeg-embed--<?php echo esc_attr($style); ?> lmeg-embed--premium">
        <?php if ($atts['heading']) : ?>
            <div class="lmeg-embed__heading"><?php echo esc_html($atts['heading']); ?></div>
        <?php endif; ?>
        <?php if ($atts['message']) : ?>
            <p class="lmeg-embed__message"><?php echo esc_html($atts['message']); ?></p>
        <?php endif; ?>

        <form class="lmeg-form lmeg-embed__form" method="post" action="<?php echo $action; ?>" novalidate>
            <input type="hidden" name="action"            value="lmeg_submit" />
            <input type="hidden" name="_wpnonce"          value="<?php echo esc_attr($nonce); ?>" />
            <input type="hidden" name="redirect"          value="<?php echo esc_url($redirect); ?>" />
            <input type="hidden" name="contact_type"      value="email" />
            <input type="hidden" name="phone_country_iso" value="US" />

            <div class="lmeg-hp-wrap" aria-hidden="true">
                <label>Leave this empty<input type="text" name="lmeg_hp" value="" tabindex="-1" autocomplete="off" /></label>
            </div>

            <?php if (!$member) : ?>
                <div class="lmeg-field lmeg-embed__field">
                    <label class="lmeg-embed__label" for="<?php echo esc_attr($id); ?>-email">Your email</label>
                    <input type="email"
                           id="<?php echo esc_attr($id); ?>-email"
                           name="email"
                           required
                           autocomplete="email"
                           placeholder="you@example.com"
                           class="lmeg-input" />
                </div>
            <?php else : ?>
                <p class="lmeg-embed__member">
                    Signed in as <strong><?php echo esc_html($member->email ?: $member->phone); ?></strong>.
                    <a href="?lmeg_member=logout" style="opacity:.7;">Not you?</a>
                </p>
            <?php endif; ?>

            <div class="lmeg-tiers lmeg-tiers--grid">
                <?php foreach ($all_tiers as $t) : ?>
                    <div class="lmeg-tier">
                        <div class="lmeg-tier__name"><?php echo esc_html($t->name); ?></div>
                        <?php if ($t->description) : ?>
                            <p class="lmeg-tier__desc"><?php echo esc_html($t->description); ?></p>
                        <?php endif; ?>
                        <div class="lmeg-tier__prices">
                            <?php if ($t->price_monthly) : ?>
                                <button type="submit"
                                        name="lmeg_after"
                                        value="checkout:<?php echo (int) $t->id; ?>:monthly"
                                        class="lmeg-button lmeg-tier__cta">
                                    <span class="lmeg-tier__price"><?php echo esc_html(lmeg_format_price($t->price_monthly, $t->currency)); ?></span>
                                    <span class="lmeg-tier__period">/ month</span>
                                </button>
                            <?php endif; ?>
                            <?php if ($t->price_annual) : ?>
                                <button type="submit"
                                        name="lmeg_after"
                                        value="checkout:<?php echo (int) $t->id; ?>:annual"
                                        class="lmeg-button lmeg-button--outline lmeg-tier__cta">
                                    <span class="lmeg-tier__price"><?php echo esc_html(lmeg_format_price($t->price_annual, $t->currency)); ?></span>
                                    <span class="lmeg-tier__period">/ year</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!$member) : ?>
                <p class="lmeg-embed__signin">
                    Already subscribed? <a href="?lmeg_member=signin">Sign in →</a>
                </p>
            <?php endif; ?>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
