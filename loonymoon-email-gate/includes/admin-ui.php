<?php
if (!defined('ABSPATH')) exit;

/* ============================================================================
 * Fanloop — shared admin UI helpers
 * ----------------------------------------------------------------------------
 * A reusable WordPress Media Library image picker so image fields stop being
 * "open the media manager, copy the URL, paste it back". Any admin form renders
 * one field per image with lmeg_image_field(); a single delegated script wires
 * every field on the page, so a form can have many (cover, artwork, gallery…).
 * ========================================================================== */

/** Call once per admin page that uses lmeg_image_field(): enqueues wp.media + JS. */
function lmeg_media_enqueue() {
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('wp_enqueue_media')) wp_enqueue_media();
    add_action('admin_print_footer_scripts', 'lmeg_media_picker_js', 99);
}

/**
 * Render a Media-Library image field: a preview thumb + a hidden input carrying
 * the chosen URL (name=$name) + Choose/Remove buttons. Returns HTML.
 * $args: id, button (label), title (media modal), thumb (px), hint.
 */
function lmeg_image_field($name, $value = '', $args = []) {
    static $seq = 0; $seq++;
    $a = array_merge([
        'id'     => '',
        'button' => 'Choose image',
        'title'  => 'Choose an image',
        'thumb'  => 150,
        'hint'   => 'Pick from your Media Library — no URL pasting.',
    ], $args);
    $id    = $a['id'] ?: ('lmeg-img-' . preg_replace('/[^a-z0-9_]/i', '', (string) $name) . '-' . $seq);
    $thumb = (int) $a['thumb'];
    $has   = trim((string) $value) !== '';
    $style = 'max-width:' . $thumb . 'px;height:auto;border-radius:10px;border:1px solid #dcdcde;display:block';

    $h  = '<div class="lmeg-image-field" data-thumb="' . $thumb . '">';
    $h .= '<div class="lmeg-img-prev" style="margin-bottom:9px;' . ($has ? '' : 'display:none') . '">'
        . ($has ? '<img src="' . esc_url($value) . '" style="' . $style . '">' : '') . '</div>';
    $h .= '<input type="hidden" class="lmeg-img-input" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
    $h .= '<button type="button" class="button lmeg-img-pick" data-target="' . esc_attr($id) . '" data-title="' . esc_attr($a['title']) . '">' . esc_html($a['button']) . '</button> ';
    $h .= '<button type="button" class="button lmeg-img-clear" data-target="' . esc_attr($id) . '"' . ($has ? '' : ' style="display:none"') . '>Remove</button>';
    if ($a['hint'] !== '') $h .= '<p class="description">' . esc_html($a['hint']) . '</p>';
    $h .= '</div>';
    return $h;
}

/** One delegated handler wires every lmeg_image_field() on the page. */
function lmeg_media_picker_js() {
    ?>
    <script>
    jQuery(function($){
        var frames = {};
        $(document).on('click', '.lmeg-img-pick', function(e){
            e.preventDefault();
            if (!window.wp || !wp.media) { window.alert('Media library unavailable.'); return; }
            var $btn = $(this), id = $btn.data('target'), $wrap = $btn.closest('.lmeg-image-field');
            var thumb = parseInt($wrap.data('thumb'), 10) || 150;
            if (frames[id]) { frames[id].open(); return; }
            var f = wp.media({ title: $btn.data('title') || 'Choose an image', button: { text: 'Use this image' }, multiple: false, library: { type: 'image' } });
            f.on('select', function(){
                var a = f.state().get('selection').first().toJSON();
                if (!a || !a.url) return;
                $('#' + id).val(a.url);
                $wrap.find('.lmeg-img-prev').html('<img src="' + a.url + '" style="max-width:' + thumb + 'px;height:auto;border-radius:10px;border:1px solid #dcdcde;display:block">').show();
                $wrap.find('.lmeg-img-clear').show();
            });
            frames[id] = f; f.open();
        });
        $(document).on('click', '.lmeg-img-clear', function(e){
            e.preventDefault();
            var $wrap = $(this).closest('.lmeg-image-field');
            $wrap.find('.lmeg-img-input').val('');
            $wrap.find('.lmeg-img-prev').hide().empty();
            $(this).hide();
        });
    });
    </script>
    <?php
}
