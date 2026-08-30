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

  // --- Time on page (active dwell) -----------------------------------------
  // Accumulate wall-clock time only while the tab is actually visible, capped
  // so a tab left open forever doesn't inflate it. Report the running total on
  // tab-hide / page-hide (the reliable "leaving" signals) and via a heartbeat,
  // so long single-page reads are captured even without a clean exit. The
  // server keeps the largest value it sees for this page, so re-sends are safe.
  var CAP = 30 * 60 * 1000;                 // 30 min ceiling
  var activeMs = 0, sentMs = 0;
  var span = (document.visibilityState === 'visible') ? Date.now() : 0;

  function accrue() {
    if (span) { activeMs += Date.now() - span; span = 0; }
    if (activeMs > CAP) activeMs = CAP;
  }
  function resume() {
    if (!span && document.visibilityState === 'visible') span = Date.now();
  }
  function flushDwell(force) {
    accrue();
    var ms = Math.round(activeMs);
    if (ms > 0 && (force || ms - sentMs >= 5000)) {   // resend only on real growth
      send({ t: 'dwell', page: location.href, ms: ms });
      sentMs = ms;
    }
    resume();                                          // keep counting if still visible
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') flushDwell(true);
    else resume();
  });
  window.addEventListener('pagehide', function () { flushDwell(true); });
  setInterval(function () { flushDwell(false); }, 30000);

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
