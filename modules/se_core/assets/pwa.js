/*
 * Registers the service worker and manages this browser's push subscription.
 *
 * Loaded on every admin page for a logged-in staff member.
 *
 * PERMISSION IS ASKED FOR ON A CLICK, NEVER ON LOAD. A permission prompt fired
 * from page load is the pattern browsers now auto-deny and users resent long
 * before that; it also burns the one chance to ask. The bell button in the
 * header is the gesture.
 */
(function () {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) { return; }

  var BASE = (window.SE_PWA && window.SE_PWA.base) || '';

  function urlB64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var raw = window.atob(base64);
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; ++i) { out[i] = raw.charCodeAt(i); }
    return out;
  }

  function paint(state) {
    var btn = document.getElementById('se-push-toggle');
    if (!btn) { return; }
    btn.setAttribute('data-state', state);
    btn.title = state === 'on' ? 'Bildirimler açık' : 'Bildirimleri aç';
  }

  /*
   * The bell lives in the admin top bar. It is injected rather than added to a
   * theme template so a Perfex upgrade cannot silently drop it — and because
   * the permission prompt MUST come from a click, this button is the only
   * thing that triggers it.
   */
  function mountButton() {
    if (document.getElementById('se-push-toggle')) { return; }
    var host = document.querySelector('#top-search-bar, .navbar-right, #header .navbar-nav');
    if (!host) { return; }

    var li = document.createElement('li');
    var btn = document.createElement('button');
    btn.id = 'se-push-toggle';
    btn.type = 'button';
    btn.setAttribute('data-state', 'off');
    btn.setAttribute('aria-label', 'Bildirimler');
    btn.innerHTML = '<i class="fa fa-bell" aria-hidden="true"></i>';
    btn.addEventListener('click', function () {
      if (btn.getAttribute('data-state') === 'denied') { return; }
      if (btn.getAttribute('data-state') === 'on') { window.sePushDisable(); }
      else { window.sePushEnable(); }
    });
    li.appendChild(btn);
    host.insertBefore(li, host.firstChild);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountButton);
  } else {
    mountButton();
  }

  var swReady = navigator.serviceWorker.register(BASE + '/se_core/se_pwa/sw', { scope: '/' })
    .then(function () { return navigator.serviceWorker.ready; });

  swReady.then(function (reg) {
    return reg.pushManager.getSubscription().then(function (sub) {
      paint(sub ? 'on' : 'off');
    });
  }).catch(function () { paint('off'); });


  /*
   * Perfex verifies CSRF from $_POST, so a JSON body (which leaves $_POST
   * empty) was refused with 403 whenever CSRF was on. Post as a form with the
   * page's csrfData token and carry the JSON in a `payload` field.
   */
  function postForm(path, fields) {
    var fd = new FormData();
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    if (window.csrfData && csrfData.token_name) { fd.append(csrfData.token_name, csrfData.hash); }
    return fetch(BASE + path, { method: 'POST', credentials: 'include', body: fd });
  }

  window.sePushEnable = function () {
    return Notification.requestPermission().then(function (permission) {
      // 'denied' is terminal until the user changes it in browser settings —
      // asking again does nothing, so say so rather than retrying.
      if (permission !== 'granted') { paint('denied'); return; }

      return fetch(BASE + '/se_core/se_pwa/key', { credentials: 'include' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok || !d.key) { paint('off'); return; }
          return swReady.then(function (reg) {
            return reg.pushManager.subscribe({
              // Required by every browser: a push must always be shown to the
              // user. Silent background pushes are not available and asking
              // for them fails the subscribe outright.
              userVisibleOnly: true,
              applicationServerKey: urlB64ToUint8Array(d.key)
            });
          }).then(function (sub) {
            return postForm('/se_core/se_pwa/subscribe', { payload: JSON.stringify(sub) });
          }).then(function () { paint('on'); });
        });
    });
  };

  window.sePushDisable = function () {
    return swReady.then(function (reg) { return reg.pushManager.getSubscription(); })
      .then(function (sub) {
        if (!sub) { paint('off'); return; }
        var endpoint = sub.endpoint;
        return sub.unsubscribe().then(function () {
          return postForm('/se_core/se_pwa/unsubscribe', { payload: JSON.stringify({ endpoint: endpoint }) });
        }).then(function () { paint('off'); });
      });
  };
})();
