<?php
/**
 * Loonymoon Email Gate — locked single-post template.
 *
 * Only the theme's header and footer render. Everything else on the page
 * is replaced by whichever fragment matches the gate decision.
 */

if (!defined('ABSPATH')) {
    exit;
}

$decision = function_exists('lmeg_gate_decision') ? lmeg_gate_decision() : 'needs_signup';
$post_id  = get_the_ID();
$access   = $post_id && function_exists('lmeg_post_access_level')
    ? lmeg_post_access_level($post_id)
    : 'free';

get_header();
?>
<main id="lmeg-locked" class="lmeg-locked-wrap" role="main">
    <?php
    if ($access === 'paid' || $access === 'soft-paid' || (is_array($access) && $access[0] === 'tier')) {
        echo lmeg_paywall_html($post_id);
    } elseif ($decision === 'needs_upgrade') {
        echo lmeg_upgrade_html($post_id);
    } else {
        echo lmeg_render_form();
    }
    ?>
</main>
<?php
get_footer();
