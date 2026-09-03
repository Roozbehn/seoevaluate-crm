/*
 * Azin CRM shell behaviour (CRM-M063 / CRM-M022):
 *  - accessible names for Perfex's icon-only header controls (menu, search,
 *    timers, notifications, theme switch, quick-add),
 *  - a skip link as the first element in <body>,
 *  - the mobile bottom tab bar (markup is emitted by se_clinic; this only
 *    marks the active item and keeps counts fresh),
 *  - a lightweight bottom sheet toggle for the WhatsApp context on phones.
 */
(function () {
  'use strict';
  var L = window.SE_CLINIC_L || {};

  function name(el, text) {
    if (!el || el.getAttribute('aria-label') || (el.textContent || '').trim()) { return; }
    el.setAttribute('aria-label', text);
  }

  function labelHeader() {
    name(document.querySelector('#header .hide-menu, #header [data-toggle="sidebar"], #header .menu-toggle'), L.menu || 'Menüyü aç/kapat');
    name(document.querySelector('#header .top-timers, #header a[href*="timesheets"]'), L.timers || 'Zamanlayıcılar');
    name(document.querySelector('#header .notifications-toggle, #header .dropdown-toggle.notifications-wrapper, #header [href*="notifications"]'), L.notifications || 'Bildirimler');
    name(document.querySelector('#header a[href*="/todo"]'), L.todo || 'Yapılacaklar');
    name(document.querySelector('#header .theme-switch, #header [title*="theme" i], #header [title*="tema" i]'), L.theme || 'Temayı değiştir');
    name(document.querySelector('#header .quick-actions, #header .dropdown-quick-actions a'), L.quick || 'Hızlı ekle');
    var search = document.querySelector('#header input[type="search"], #search_input, #header .search-input');
    if (search && !search.getAttribute('aria-label')) { search.setAttribute('aria-label', L.search || 'Ara'); }
    // Perfex 3.4 header: the search submit (icon only) and the mobile menu chevron carry no name.
    var searchIcon = document.querySelector('#header .fa-search');
    name(searchIcon ? searchIcon.closest('button') : null, L.search || 'Ara');
    name(document.querySelector('#header .navbar-toggle, #header .mobile-menu-toggle'), L.menu || 'Menüyü aç/kapat');
  }

  function skipLink() {
    if (document.querySelector('.se-skip')) { return; }
    var a = document.createElement('a');
    a.className = 'se-skip'; a.href = '#se-main'; a.textContent = L.skip || 'İçeriğe geç';
    document.body.insertBefore(a, document.body.firstChild);
    var content = document.querySelector('#wrapper .content');
    if (content && !content.id) { content.id = 'se-main'; content.setAttribute('tabindex', '-1'); }
  }

  function tabbar() {
    var bar = document.querySelector('.se-tabbar');
    if (!bar) { return; }
    var path = location.pathname;
    bar.querySelectorAll('a[data-match]').forEach(function (a) {
      var keys = a.getAttribute('data-match').split(',');
      if (keys.some(function (k) { return k && path.indexOf(k) !== -1; })) { a.classList.add('active'); a.setAttribute('aria-current', 'page'); }
    });
  }

  function sheets() {
    document.querySelectorAll('[data-se-sheet]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var sheet = document.querySelector(btn.getAttribute('data-se-sheet'));
        if (!sheet) { return; }
        var open = sheet.classList.toggle('open');
        sheet.setAttribute('aria-hidden', open ? 'false' : 'true');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { var f = sheet.querySelector('button, a, select, input'); if (f) { f.focus(); } }
      });
    });
    document.querySelectorAll('.se-sheet .se-sheet-close').forEach(function (c) {
      c.addEventListener('click', function () { var s = c.closest('.se-sheet'); s.classList.remove('open'); s.setAttribute('aria-hidden', 'true'); });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { document.querySelectorAll('.se-sheet.open').forEach(function (s) { s.classList.remove('open'); s.setAttribute('aria-hidden', 'true'); }); }
    });
  }

  function ready(fn) { if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
  ready(function () { labelHeader(); skipLink(); tabbar(); sheets(); });
})();
