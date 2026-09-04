<?php
if (!defined('ABSPATH')) exit;

/* ============================================================================
 * Fanloop — Song previews (Apple Music / iTunes)
 * ----------------------------------------------------------------------------
 * Adds 30-second audio previews to release pages and shop products, and lets
 * the artist *find* them automatically from the public iTunes Search API
 * instead of hand-pasting a clip URL for every song.
 *
 *  - lmeg_itunes_search()        server-side catalogue lookup (cached 12h)
 *  - [admin] "Find on Apple Music" picker  →  fills a preview_url field
 *  - lmeg_audio_preview_html()   the shared front-end player pill
 *
 * The player reuses the same .flp-preview component the shop product player
 * already uses (includes/products.php), so both interoperate and only one clip
 * ever plays at a time across a page.
 * ========================================================================== */

/* -------------------------------------------------------------------------
 * iTunes Search API — find songs (with a playable preview) for a term.
 * Public endpoint, no key. Cached per term so pages/admin never hammer it.
 * ---------------------------------------------------------------------- */
function lmeg_itunes_search($term, $limit = 8) {
    $term = trim((string) $term);
    if ($term === '') return [];
    $limit = max(1, min(25, (int) $limit));

    $key    = 'lmeg_itunes_' . md5(strtolower($term) . '|' . $limit);
    $cached = get_transient($key);
    if (is_array($cached)) return $cached;

    // Build the query directly (add_query_arg would double-encode the term).
    $url = 'https://itunes.apple.com/search?term=' . rawurlencode($term)
         . '&media=music&entity=song&limit=' . $limit;

    $res = wp_remote_get($url, ['timeout' => 8, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) return [];

    $body = json_decode(wp_remote_retrieve_body($res), true);
    $out  = [];
    if (!empty($body['results']) && is_array($body['results'])) {
        foreach ($body['results'] as $r) {
            if (empty($r['previewUrl'])) continue;
            $art   = (string) ($r['artworkUrl100'] ?? ($r['artworkUrl60'] ?? ''));
            $art_hi = $art !== '' ? str_replace(['100x100bb', '60x60bb'], '600x600bb', $art) : '';
            $out[] = [
                'title'       => (string) ($r['trackName'] ?? ''),
                'artist'      => (string) ($r['artistName'] ?? ''),
                'album'       => (string) ($r['collectionName'] ?? ''),
                'preview_url' => (string) $r['previewUrl'],
                'artwork'     => $art,
                'artwork_hi'  => $art_hi,
                'track_id'    => (int) ($r['trackId'] ?? 0),
                'track_url'   => (string) ($r['trackViewUrl'] ?? ''),
            ];
        }
    }
    set_transient($key, $out, 12 * HOUR_IN_SECONDS);
    return $out;
}

/* -------------------------------------------------------------------------
 * Admin AJAX — return matches for the picker.
 * ---------------------------------------------------------------------- */
add_action('wp_ajax_lmeg_preview_search', 'lmeg_preview_search_ajax');
function lmeg_preview_search_ajax() {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('lmeg_preview_search', 'nonce');
    $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
    wp_send_json_success(lmeg_itunes_search($term, 8));
}

/* -------------------------------------------------------------------------
 * Front-end player — the shared .flp-preview pill.
 * ---------------------------------------------------------------------- */

/** The one-at-a-time player JS, emitted at most once per page (shared with the
 *  shop product player via the same $GLOBALS guard + the same DOM classes). */
function lmeg_preview_shared_js() {
    if (!empty($GLOBALS['lmeg_preview_js'])) return '';
    $GLOBALS['lmeg_preview_js'] = true;
    $play  = wp_json_encode(function_exists('lmeg_store_icon') ? lmeg_store_icon('play', 15, ['fill' => true, 'style' => 'margin-left:1px']) : '&#9654;');
    $pause = wp_json_encode(function_exists('lmeg_store_icon') ? lmeg_store_icon('pause', 15, ['fill' => true]) : '&#10073;&#10073;');
    return '<script>(function(){var PLAY=' . $play . ',PAUSE=' . $pause . ';'
        . 'function setp(b,on){b.innerHTML=on?PAUSE:PLAY;b.setAttribute("aria-pressed",on?"true":"false");b.setAttribute("aria-label",on?"Pause preview":"Play preview");if(on)b.classList.add("is-playing");else b.classList.remove("is-playing");}'
        . 'document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest(".flp-preview-btn");if(!b)return;e.preventDefault();var w=b.closest(".flp-preview"),au=w&&w.querySelector(".flp-preview-audio");if(!au)return;'
        . 'document.querySelectorAll(".flp-preview-audio").forEach(function(a){if(a!==au){a.pause();var ww=a.closest(".flp-preview"),bb=ww&&ww.querySelector(".flp-preview-btn");if(bb)setp(bb,false);}});'
        . 'if(au.paused){var pr=au.play();if(pr&&pr.catch)pr.catch(function(){});setp(b,true);}else{au.pause();setp(b,false);}});'
        . 'document.addEventListener("timeupdate",function(e){var au=e.target;if(!au.classList||!au.classList.contains("flp-preview-audio"))return;var w=au.closest(".flp-preview"),f=w&&w.querySelector(".flp-preview-fill");if(f&&au.duration)f.style.width=(au.currentTime/au.duration*100)+"%";},true);'
        . 'document.addEventListener("ended",function(e){var au=e.target;if(!au.classList||!au.classList.contains("flp-preview-audio"))return;var w=au.closest(".flp-preview"),b=w&&w.querySelector(".flp-preview-btn"),f=w&&w.querySelector(".flp-preview-fill");if(b)setp(b,false);if(f)f.style.width="0%";},true);'
        . '})();</script>';
}

/** A standalone audio-preview pill for any front-end surface (release page,
 *  etc.). $accent lets a surface tint it to its brand colour. */
function lmeg_audio_preview_html($url, $label = 'Preview', $accent = '#E15FA8') {
    $url = trim((string) $url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return '';
    $accent  = sanitize_hex_color($accent) ?: '#E15FA8';
    $ic_head = function_exists('lmeg_store_icon') ? lmeg_store_icon('headphones', 12) : '';
    $ic_play = function_exists('lmeg_store_icon') ? lmeg_store_icon('play', 15, ['fill' => true, 'style' => 'margin-left:1px']) : '&#9654;';
    $js = lmeg_preview_shared_js();

    return '<div class="flp-preview" style="display:flex;align-items:center;gap:10px;margin:0;padding:7px 12px 7px 7px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);border-radius:999px;max-width:340px">'
        . '<button type="button" class="flp-preview-btn" aria-label="Play preview" aria-pressed="false" style="flex:0 0 auto;width:34px;height:34px;border-radius:50%;border:0;background:' . esc_attr($accent) . ';color:#fff;cursor:pointer;display:grid;place-items:center;padding:0">' . $ic_play . '</button>'
        . '<div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:4px">'
        . '<span style="font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;opacity:.75;display:inline-flex;align-items:center;gap:5px">' . $ic_head . esc_html($label) . '</span>'
        . '<div class="flp-preview-bar" style="height:4px;background:rgba(255,255,255,.16);border-radius:999px;overflow:hidden"><div class="flp-preview-fill" style="height:100%;width:0%;background:' . esc_attr($accent) . ';border-radius:999px;transition:width .15s linear"></div></div>'
        . '</div>'
        . '<audio class="flp-preview-audio" preload="none" src="' . esc_url($url) . '"></audio>'
        . '</div>' . $js;
}

/* -------------------------------------------------------------------------
 * Release page — show the preview under the drop, when the release has one.
 * ---------------------------------------------------------------------- */
add_action('lmeg_drop_after_body', 'lmeg_release_drop_preview', 10, 2);
function lmeg_release_drop_preview($drop, $released) {
    if (!function_exists('lmeg_release_for_drop')) return;
    $rel = lmeg_release_for_drop((int) $drop->id);
    $url = ($rel && !empty($rel->preview_url)) ? $rel->preview_url : '';
    if ($url === '') return;
    $accent = '#E15FA8';
    $s = function_exists('lmeg_get_settings') ? lmeg_get_settings() : [];
    if (!empty($s['color_primary'])) $accent = sanitize_hex_color($s['color_primary']) ?: $accent;
    echo '<div class="lmeg-drop__preview" style="margin-top:14px">' . lmeg_audio_preview_html($url, 'Hear it', $accent) . '</div>';
}

/* -------------------------------------------------------------------------
 * Admin — "Find on Apple Music" picker. Reusable in any admin form: it fills
 * the form's [name="$target"] input (default: preview_url) with the chosen
 * clip. Renders the shared config + JS + styles once.
 * ---------------------------------------------------------------------- */
function lmeg_preview_finder_html($seed = '', $target = 'preview_url') {
    $once = '';
    if (empty($GLOBALS['lmeg_finder_boot'])) {
        $GLOBALS['lmeg_finder_boot'] = true;
        $once = lmeg_preview_finder_boot();
    }
    $seed = esc_attr($seed);
    $target = esc_attr($target);
    return $once
        . '<div class="lmeg-finder" data-target="' . $target . '" style="margin:8px 0 4px;max-width:520px">'
        . '<div style="display:flex;gap:8px;align-items:center">'
        . '<input type="text" class="lmeg-finder-q regular-text" value="' . $seed . '" placeholder="Song or artist — search Apple Music" style="flex:1">'
        . '<button type="button" class="button lmeg-finder-go">Find preview</button>'
        . '</div>'
        . '<div class="lmeg-finder-results" hidden></div>'
        . '</div>';
}

/** One-time config + JS + CSS for the admin finder. */
function lmeg_preview_finder_boot() {
    $nonce = wp_create_nonce('lmeg_preview_search');
    ob_start(); ?>
<style>
.lmeg-finder-results{margin-top:8px;border:1px solid #dcdcde;border-radius:10px;overflow:hidden;background:#fff;max-height:300px;overflow-y:auto}
.lmeg-finder-results:empty{display:none}
.lmeg-finder-loading,.lmeg-finder-empty{padding:12px 14px;color:#6b7280;font-size:13px}
.lmeg-finder-pick{display:flex;gap:11px;align-items:center;width:100%;text-align:left;background:none;border:0;border-bottom:1px solid #f0f0f2;padding:9px 12px;cursor:pointer}
.lmeg-finder-pick:last-child{border-bottom:0}
.lmeg-finder-pick:hover{background:#faf7fb}
.lmeg-finder-pick img{width:40px;height:40px;border-radius:6px;object-fit:cover;flex:0 0 auto;background:#f3f4f6}
.lmeg-finder-pick .m{min-width:0;flex:1}
.lmeg-finder-pick .t{display:block;font-weight:700;color:#1e1e1e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lmeg-finder-pick .a{display:block;font-size:12px;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lmeg-finder-pick .go{flex:0 0 auto;font-size:12px;font-weight:700;color:#a4508b}
.lmeg-finder-chosen{padding:10px 14px;color:#15803d;font-size:13px;background:#f0fdf4}
</style>
<script>
window.lmegPreviewCfg = window.lmegPreviewCfg || {ajax:(window.ajaxurl||'/wp-admin/admin-ajax.php'),nonce:<?php echo wp_json_encode($nonce); ?>};
(function(){
  if(window.__lmegFinder) return; window.__lmegFinder=true;
  function esc(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML;}
  document.addEventListener('click',function(e){
    var go=e.target.closest&&e.target.closest('.lmeg-finder-go');
    if(go){e.preventDefault();run(go.closest('.lmeg-finder'));return;}
    var pk=e.target.closest&&e.target.closest('.lmeg-finder-pick');
    if(pk){e.preventDefault();pick(pk);return;}
  });
  document.addEventListener('keydown',function(e){
    if(e.key==='Enter'&&e.target&&e.target.classList&&e.target.classList.contains('lmeg-finder-q')){e.preventDefault();run(e.target.closest('.lmeg-finder'));}
  });
  function run(box){
    if(!box)return;
    var q=box.querySelector('.lmeg-finder-q').value.trim();
    var out=box.querySelector('.lmeg-finder-results');
    if(!q){out.hidden=true;out.innerHTML='';return;}
    out.hidden=false;out.innerHTML='<div class="lmeg-finder-loading">Searching Apple Music…</div>';
    var u=lmegPreviewCfg.ajax+'?action=lmeg_preview_search&nonce='+encodeURIComponent(lmegPreviewCfg.nonce)+'&term='+encodeURIComponent(q);
    fetch(u,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
      if(!j||!j.success||!j.data||!j.data.length){out.innerHTML='<div class="lmeg-finder-empty">No songs with a preview found. Try the exact track name (and artist).</div>';return;}
      box._rows=j.data;
      out.innerHTML=j.data.map(function(t,i){
        return '<button type="button" class="lmeg-finder-pick" data-i="'+i+'">'
          +'<img src="'+esc(t.artwork)+'" alt="">'
          +'<span class="m"><span class="t">'+esc(t.title)+'</span><span class="a">'+esc(t.artist)+(t.album?' · '+esc(t.album):'')+'</span></span>'
          +'<span class="go">Use ▶</span></button>';
      }).join('');
    }).catch(function(){out.innerHTML='<div class="lmeg-finder-empty">Search failed — check the connection and try again.</div>';});
  }
  function pick(pk){
    var box=pk.closest('.lmeg-finder'),form=box.closest('form');
    var t=box._rows&&box._rows[+pk.getAttribute('data-i')];if(!t||!form)return;
    var name=box.getAttribute('data-target')||'preview_url';
    var inp=form.querySelector('[name="'+name+'"]');
    if(inp){inp.value=t.preview_url;inp.dispatchEvent(new Event('change',{bubbles:true}));}
    box.querySelector('.lmeg-finder-results').innerHTML='<div class="lmeg-finder-chosen">✓ Preview set: <strong>'+esc(t.title)+'</strong> — '+esc(t.artist)+'. Save to apply.</div>';
  }
})();
</script>
<?php
    return ob_get_clean();
}
