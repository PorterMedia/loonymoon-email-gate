<?php
/**
 * Admin polish — small, global UX refinements that apply across every Fanloop
 * page without touching individual page callbacks.
 *
 * SUCCESS TOASTS: the plugin shows save feedback the WordPress way — a POST
 * redirects to a GET that renders a `.notice.notice-success` banner. There are
 * ~87 of these across the admin, all standard markup. Rather than edit each one,
 * this promotes any success notice into a floating, auto-dismissing toast in the
 * corner. Errors, warnings and info notices are deliberately left as persistent
 * banners (you don't want an error to vanish on a timer). CSS + JS are emitted
 * inline in the footer so an optimization plugin (Autoptimize) serving a cached
 * admin.css can't strip them — the same defensive choice as the nav chrome.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_footer', 'lmeg_admin_polish_footer');
function lmeg_admin_polish_footer() {
    if (!function_exists('lmeg_admin_current_slug') || lmeg_admin_current_slug() === '') return;
    ?>
<style id="lmeg-polish-css">
.lmeg-toasts{position:fixed;right:22px;bottom:22px;z-index:100000;display:flex;flex-direction:column;gap:10px;max-width:360px;}
.lmeg-toast{display:flex;align-items:flex-start;gap:10px;background:linear-gradient(160deg,#161826,#1C1F2E);
  border:1px solid rgba(255,255,255,.10);border-left:3px solid #34D399;border-radius:12px;
  padding:12px 14px;box-shadow:0 12px 34px rgba(0,0,0,.45);color:#F4F5F7;
  font-family:'DM Sans',-apple-system,'Segoe UI',Roboto,sans-serif;font-size:13.5px;line-height:1.4;
  opacity:0;transform:translateY(10px) scale(.98);transition:opacity .22s ease,transform .22s ease;}
.lmeg-toast.is-in{opacity:1;transform:translateY(0) scale(1);}
.lmeg-toast.is-out{opacity:0;transform:translateY(10px) scale(.98);}
.lmeg-toast__ico{flex:0 0 auto;width:20px;height:20px;border-radius:50%;background:#34D399;color:#0E0F16;
  display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;line-height:1;margin-top:1px;}
.lmeg-toast__msg{flex:1;min-width:0;}
.lmeg-toast__msg a{color:#E58BBD;}
.lmeg-toast__x{flex:0 0 auto;background:none;border:0;color:#8B90A0;font-size:18px;line-height:1;cursor:pointer;padding:0 2px;}
.lmeg-toast__x:hover{color:#F4F5F7;}
@media (prefers-reduced-motion:reduce){.lmeg-toast{transition:opacity .001s;transform:none;}.lmeg-toast.is-in{transform:none;}}
</style>
<script id="lmeg-polish-js">
(function(){
  function ready(fn){ if(document.readyState!=='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded',fn); } }
  ready(function(){
    if(!document.body.classList.contains('lmeg-admin')) return;
    var sel = '.wrap .notice-success, .wrap div.updated, #wpbody-content > .notice-success, #wpbody-content > div.updated';
    var found = document.querySelectorAll(sel);
    if(!found.length) found = document.querySelectorAll('.notice-success, div.updated');
    if(!found.length) return;
    var host = document.createElement('div');
    host.className = 'lmeg-toasts';
    host.setAttribute('role','status');
    host.setAttribute('aria-live','polite');
    document.body.appendChild(host);
    Array.prototype.forEach.call(found, function(n){
      var p = n.querySelector('p');
      var msg = p ? p.innerHTML : n.innerHTML;
      if(!msg || !msg.trim()) return;
      n.style.display = 'none';
      var t = document.createElement('div');
      t.className = 'lmeg-toast';
      t.innerHTML = '<span class="lmeg-toast__ico" aria-hidden="true">✓</span>'
        + '<span class="lmeg-toast__msg">' + msg + '</span>'
        + '<button type="button" class="lmeg-toast__x" aria-label="Dismiss">×</button>';
      host.appendChild(t);
      requestAnimationFrame(function(){ t.classList.add('is-in'); });
      var timer = setTimeout(close, 4200);
      function close(){ t.classList.remove('is-in'); t.classList.add('is-out'); setTimeout(function(){ if(t.parentNode) t.parentNode.removeChild(t); }, 280); }
      t.querySelector('.lmeg-toast__x').addEventListener('click', function(){ clearTimeout(timer); close(); });
      t.addEventListener('mouseenter', function(){ clearTimeout(timer); });
      t.addEventListener('mouseleave', function(){ clearTimeout(timer); timer = setTimeout(close, 2000); });
    });
  });
})();
</script>
    <?php
}
