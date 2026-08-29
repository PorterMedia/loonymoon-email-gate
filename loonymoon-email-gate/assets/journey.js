/**
 * Fanloop journey beacon — records a pageview on load and classified outbound
 * clicks as they happen. Fire-and-forget via sendBeacon so it never blocks
 * navigation. The server (?lmeg_journey=collect) ties each event to the known
 * fan when possible, else the first-party visitor cookie. Config: window.LMEG_J
 * = { url, host }.
 */
(function () {
  'use strict';
  var J = window.LMEG_J;
  if (!J || !J.url) return;

  function send(payload) {
    try {
      var body = new Blob([JSON.stringify(payload)], { type: 'text/plain' });
      if (navigator.sendBeacon && navigator.sendBeacon(J.url, body)) return;
    } catch (e) {}
    // Fallback for browsers without sendBeacon (keepalive fetch).
    try {
      fetch(J.url, { method: 'POST', body: JSON.stringify(payload), keepalive: true, credentials: 'same-origin' });
    } catch (e) {}
  }

  // UTM off the current URL, if present.
  function utm() {
    var out = {};
    try {
      var q = new URLSearchParams(location.search);
      if (q.get('utm_source'))   out.us = q.get('utm_source');
      if (q.get('utm_medium'))   out.um = q.get('utm_medium');
      if (q.get('utm_campaign')) out.uc = q.get('utm_campaign');
    } catch (e) {}
    return out;
  }

  // Pageview.
  var pv = { t: 'pageview', page: location.href, ref: document.referrer || '' };
  var u = utm();
  for (var k in u) pv[k] = u[k];
  send(pv);

  // Outbound clicks (capture phase so it still fires if the handler navigates).
  var host = (J.host || location.hostname).toLowerCase();
  document.addEventListener('click', function (ev) {
    var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#' || /^(mailto:|tel:|javascript:)/i.test(href)) return;

    var h = '';
    try { h = new URL(href, location.href).hostname.toLowerCase(); } catch (e) { return; }
    if (!h || h.indexOf(host) !== -1) return;   // internal / same-site → skip

    send({
      t: 'outbound',
      url: a.href,                                  // resolved absolute URL
      text: (a.textContent || '').trim().slice(0, 190),
      page: location.href
    });
  }, true);
})();
