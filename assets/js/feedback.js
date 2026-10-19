/* =============================================================
 * feedback.js — "Send feedback about this screen" dialog.
 *
 * Opened from the bottom-right tab (#dy-feedback-tab).
 * Pre-filled by PHP (window.doubleyoupiFeedback): the screen, the product
 * it belongs to (correctable), the environment. Sends through this site's
 * REST API (Feedback::handle), which relays to Double Youpi's queue.
 *
 * Nothing is sent before the consent box is ticked; the JSON preview shows
 * the exact payload (the server only adds the timestamp).
 * =============================================================*/
// ── Review link (19/10/2026) ────────────────────────────────────
// The "Leave a review" button beside the tab opens a small dialog with one
// sentence and a link to the WordPress.org review form. Nothing is counted
// or sent. Without <dialog> support, the button goes straight to the form.
(function () {
  'use strict';
  var dialog = document.getElementById('dy-review');
  var trigger = document.getElementById('dy-review-tab');
  if (!dialog || !trigger) { return; }
  var go = dialog.querySelector('[data-dy-review-go]');
  if (typeof dialog.showModal !== 'function') {
    trigger.addEventListener('click', function () { if (go) { window.open(go.href, '_blank', 'noopener'); } });
    return;
  }
  trigger.addEventListener('click', function (e) { e.preventDefault(); if (!dialog.open) { dialog.showModal(); } });
  dialog.querySelectorAll('[data-dy-review-close]').forEach(function (b) {
    b.addEventListener('click', function () { dialog.close(); });
  });
  dialog.addEventListener('click', function (e) { if (e.target === dialog) { dialog.close(); } });   // backdrop
  if (go) { go.addEventListener('click', function () { dialog.close(); }); }
})();

(function () {
  'use strict';

  var CFG = window.doubleyoupiFeedback;
  if (!CFG || !CFG.rest) { return; }
  var I18N = CFG.i18n || {};
  var $ = function (id) { return document.getElementById(id); };

  var dialog = $('dy-feedback');
  var trigger = $('dy-feedback-tab');
  if (!dialog || !trigger || typeof dialog.showModal !== 'function') { return; }

  var form = $('dy-feedback-form');
  var body = $('dy-feedback-body');
  var foot = $('dy-feedback-foot');
  var done = $('dy-feedback-done');
  var message = $('dy-feedback-message');
  var count = $('dy-feedback-count');
  var file = $('dy-feedback-file');
  var shotBox = $('dy-feedback-shot');
  var shotImg = $('dy-feedback-shot-img');
  var shotRemove = $('dy-feedback-shot-remove');
  var contact = $('dy-feedback-contact');
  var email = $('dy-feedback-email');
  var consent = $('dy-feedback-consent');
  var preview = $('dy-feedback-json');
  var errorEl = $('dy-feedback-error');
  var sendBtn = $('dy-feedback-send');
  var refEl = $('dy-feedback-ref');

  var screenshot = '';   // data URL or ''
  var busy = false;

  // ── Static context ────────────────────────────────────────────
  $('dy-feedback-screen').textContent = (CFG.screen && CFG.screen.title) || document.title;
  $('dy-feedback-path').textContent = (CFG.screen && CFG.screen.url) || location.pathname;
  email.value = CFG.email || '';

  // ── Helpers ───────────────────────────────────────────────────
  // The product the screen belongs to is detected by PHP (ScreenOwner) and
  // travels with the report; it is shown in the preview, never asked.
  function detectedOwner() {
    var o = CFG.owner || {};
    return { kind: o.kind || 'core', slug: o.slug || 'wordpress', name: o.name || 'WordPress', version: o.version || '', detected: !!o.detected, via: o.via || '' };
  }
  function typeValue() {
    var r = form.querySelector('input[name="dy-feedback-type"]:checked');
    return r ? r.value : 'other';
  }
  function clientInfo() {
    var dark = document.body.classList.contains('dy-theme-light') ? 'light' : (document.body.classList.contains('dy-admin') ? 'dark' : '');
    return { viewport: { w: window.innerWidth, h: window.innerHeight }, theme: dark, ua: (navigator.userAgent || '').slice(0, 200) };
  }
  function payload() {
    var wantContact = contact.checked;
    return {
      type: typeValue(),
      message: message.value.trim(),
      owner: detectedOwner(),
      screen: CFG.screen || {},
      product: CFG.product || {},
      env: CFG.env || {},
      client: clientInfo(),
      site_id: I18N.anonymousSite || '(anonymous site identifier)',
      contact: wantContact ? { email: email.value.trim(), site: location.origin } : null,
      screenshot: screenshot ? '(image, ' + Math.round(screenshot.length * 0.75 / 1024) + ' KB)' : null
    };
  }
  function refreshPreview() {
    preview.textContent = JSON.stringify(payload(), null, 2);
  }
  function refreshState() {
    var ok = consent.checked && message.value.trim().length >= 5 && (!contact.checked || /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.value.trim()));
    sendBtn.disabled = busy || !ok;
    count.textContent = String(message.value.length);
    email.disabled = !contact.checked;
    refreshPreview();
  }
  function showError(text) {
    errorEl.textContent = text || '';
    errorEl.hidden = !text;
  }

  // ── Screenshot: file input or paste, downscaled to a JPEG that fits ─
  function acceptImage(blob) {
    if (!blob || !/^image\/(png|jpeg|webp)$/.test(blob.type)) { showError(I18N.shotBadType || 'Please use a PNG, JPEG or WebP image.'); return; }
    var url = URL.createObjectURL(blob);
    var img = new Image();
    img.onload = function () {
      URL.revokeObjectURL(url);
      var attempts = [[1600, 0.85], [1280, 0.75], [1024, 0.65]];
      var result = '';
      for (var i = 0; i < attempts.length; i++) {
        var maxW = attempts[i][0], q = attempts[i][1];
        var scale = Math.min(1, maxW / img.naturalWidth);
        var c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(img.naturalWidth * scale));
        c.height = Math.max(1, Math.round(img.naturalHeight * scale));
        var ctx = c.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(img, 0, 0, c.width, c.height);
        var data = c.toDataURL('image/jpeg', q);
        if (data.length <= (CFG.maxShot || 1500000)) { result = data; break; }
      }
      if (!result) { showError(I18N.shotTooLarge || 'This image is too large.'); return; }
      screenshot = result;
      shotImg.src = result;
      shotBox.hidden = false;
      showError('');
      refreshState();
    };
    img.onerror = function () { URL.revokeObjectURL(url); showError(I18N.shotBadType || 'Please use a PNG, JPEG or WebP image.'); };
    img.src = url;
  }
  file.addEventListener('change', function () { if (file.files && file.files[0]) { acceptImage(file.files[0]); } file.value = ''; });
  dialog.addEventListener('paste', function (e) {
    var items = (e.clipboardData && e.clipboardData.items) || [];
    for (var i = 0; i < items.length; i++) {
      if (items[i].kind === 'file' && /^image\//.test(items[i].type)) { e.preventDefault(); acceptImage(items[i].getAsFile()); return; }
    }
  });
  shotRemove.addEventListener('click', function () { screenshot = ''; shotImg.src = ''; shotBox.hidden = true; refreshState(); });

  // ── Open / close ──────────────────────────────────────────────
  function open() {
    if (dialog.open) { return; }
    reset(false);
    dialog.showModal();
    setTimeout(function () { message.focus(); }, 30);
  }
  function reset(clearMessage) {
    busy = false;
    body.hidden = false; foot.hidden = false; done.hidden = true;
    sendBtn.textContent = I18N.send || 'Send';
    showError('');
    if (clearMessage) { message.value = ''; screenshot = ''; shotImg.src = ''; shotBox.hidden = true; consent.checked = false; }
    refreshState();
  }
  trigger.addEventListener('click', function (e) { e.preventDefault(); open(); });
  $('dy-feedback-close').addEventListener('click', function () { dialog.close(); });
  $('dy-feedback-cancel').addEventListener('click', function () { dialog.close(); });
  $('dy-feedback-done-close').addEventListener('click', function () { dialog.close(); });
  dialog.addEventListener('click', function (e) { if (e.target === dialog) { dialog.close(); } });   // backdrop click
  dialog.addEventListener('cancel', function (e) { if (busy) { e.preventDefault(); } });

  [message, contact, email, consent].forEach(function (el) {
    el.addEventListener('input', refreshState);
    el.addEventListener('change', refreshState);
  });
  form.querySelectorAll('input[name="dy-feedback-type"]').forEach(function (r) { r.addEventListener('change', refreshPreview); });

  // ── Send ──────────────────────────────────────────────────────
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy || sendBtn.disabled) { return; }
    busy = true;
    sendBtn.disabled = true;
    sendBtn.textContent = I18N.sending || 'Sending…';
    showError('');
    var data = {
      type: typeValue(),
      message: message.value.trim(),
      consent: consent.checked,
      owner: detectedOwner().kind + ':' + detectedOwner().slug,
      owner_detected: detectedOwner().detected,
      owner_via: detectedOwner().via,
      contact: contact.checked,
      email: contact.checked ? email.value.trim() : '',
      screen: CFG.screen || {},
      client: clientInfo(),
      screenshot: screenshot || ''
    };
    fetch(CFG.rest, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify(data)
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, json: j || {} }; });
    }).then(function (res) {
      busy = false;
      if (res.status >= 200 && res.status < 300 && res.json.ok) {
        body.hidden = true; foot.hidden = true; done.hidden = false;
        refEl.textContent = res.json.id ? (I18N.reference || 'Reference %s.').replace('%s', res.json.id) : '';
        message.value = ''; screenshot = ''; shotImg.src = ''; shotBox.hidden = true; consent.checked = false;
        $('dy-feedback-done-close').focus();
        return;
      }
      sendBtn.textContent = I18N.retry || 'Try again';
      showError(res.json.message || I18N.networkError || 'Your report could not be sent.');
      refreshState();
    }).catch(function () {
      busy = false;
      sendBtn.textContent = I18N.retry || 'Try again';
      showError(I18N.networkError || 'Your report could not be sent.');
      refreshState();
    });
  });

  refreshState();
})();
