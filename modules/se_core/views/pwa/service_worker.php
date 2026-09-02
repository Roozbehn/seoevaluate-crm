/*
 * CRM service worker.
 *
 * Deliberately does NOT cache anything. A CRM shows live patient
 * conversations, and a stale cached thread is worse than a spinner — the whole
 * value of the screen is that it is current. The worker exists for one reason:
 * to be alive when a push arrives.
 */

self.addEventListener('install', function (e) {
  // Take over immediately. Without this the first install sits waiting for
  // every existing tab to close, and the user who just enabled notifications
  // gets none until tomorrow.
  self.skipWaiting();
});

self.addEventListener('activate', function (e) {
  e.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (e) {
  var d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = {}; }

  var title = d.title || 'CRM';
  var options = {
    body: d.body || '',
    // Same tag for the same thread, so ten messages in one conversation
    // replace each other instead of stacking ten notifications on a phone.
    tag: d.tag || 'crm',
    renotify: true,
    icon: d.icon || '/modules/se_core/assets/icon-192.png',
    badge: '/modules/se_core/assets/icon-192.png',
    data: { url: d.url || '/admin' },
    requireInteraction: false
  };

  e.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var target = (e.notification.data && e.notification.data.url) || '/admin';

  // Focus an existing window if the CRM is already open, rather than opening a
  // second one — staff end up with a dozen tabs otherwise.
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      if (list[i].url.indexOf(target) !== -1 && 'focus' in list[i]) { return list[i].focus(); }
    }
    if (self.clients.openWindow) { return self.clients.openWindow(target); }
  }));
});

/*
 * A subscription can be rotated by the browser at any time. Without this
 * handler the old endpoint quietly 410s and the staff member simply stops
 * receiving notifications, with nothing to see anywhere.
 */
self.addEventListener('pushsubscriptionchange', function (e) {
  e.waitUntil(
    self.registration.pushManager.subscribe({ userVisibleOnly: true,
      applicationServerKey: e.oldSubscription ? e.oldSubscription.options.applicationServerKey : null })
      .then(function (sub) {
        return fetch('/se_core/se_pwa/subscribe', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          credentials: 'include', body: JSON.stringify(sub)
        });
      })
  );
});
