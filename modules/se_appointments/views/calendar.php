<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content se-page">
    <div class="se-page-head">
      <h1><?php echo _l('se_appointments'); ?></h1>
      <span class="se-sub" id="se-cal-title"></span>
      <div class="se-actions">
        <div class="se-chipgroup hidden-xs" role="group" aria-label="<?php echo _l('se_appt_view'); ?>">
          <button type="button" class="se-chip on" data-view="dayGridMonth"><?php echo _l('se_appt_view_month'); ?></button>
          <button type="button" class="se-chip" data-view="timeGridWeek"><?php echo _l('se_appt_view_week'); ?></button>
          <button type="button" class="se-chip" data-view="timeGridDay"><?php echo _l('se_appt_view_day'); ?></button>
          <a class="se-chip" href="<?php echo admin_url('se_appointments/manage'); ?>"><?php echo _l('se_appt_list'); ?></a>
        </div>
        <div class="hidden-xs">
          <button type="button" class="se-btn se-btn-secondary" id="se-cal-prev" aria-label="<?php echo _l('se_appt_prev'); ?>">‹</button>
          <button type="button" class="se-btn se-btn-secondary" id="se-cal-today"><?php echo _l('se_appt_today'); ?></button>
          <button type="button" class="se-btn se-btn-secondary" id="se-cal-next" aria-label="<?php echo _l('se_appt_next'); ?>">›</button>
        </div>
        <?php if (staff_can('create', 'se_appointments')) { ?>
        <a href="<?php echo admin_url('se_appointments/create'); ?>" class="se-btn se-btn-primary hidden-xs">＋ <?php echo _l('se_appt_new'); ?></a>
        <?php } ?>
      </div>
    </div>

    <div class="se-legend hidden-xs">
      <?php foreach (se_appt_types() as $k => $t) { ?>
        <span class="<?php echo $t['class']; ?>"><?php echo html_escape($t['label']); ?></span>
      <?php } ?>
    </div>

    <div id="se-appointments-calendar" class="se-cal-host hidden-xs"></div>

    <!-- Phones: agenda, never a month grid (DS §2.15, UIUX §H) -->
    <div class="se-agenda-m visible-xs" id="se-agenda">
      <div class="se-toolbar se-daychips" id="se-agenda-days"></div>
      <div id="se-agenda-list"></div>
      <?php if (staff_can('create', 'se_appointments')) { ?>
      <a href="<?php echo admin_url('se_appointments/create'); ?>" class="se-fab" aria-label="<?php echo _l('se_appt_new'); ?>">＋</a>
      <?php } ?>
    </div>

    <div class="se-alert se-alert-danger" id="se-cal-missing" hidden>⚠️ <?php echo _l('se_appt_calendar_lib_missing'); ?></div>
  </div>
</div>
<?php init_tail(); ?>
<script>
(function () {
  var feed = admin_url + 'se_appointments/feed';
  var types = <?php echo json_encode(array_map(function ($t) { return ['label' => $t['label'], 'color' => $t['color']]; }, se_appt_types())); ?>;
  var L = {
    empty: <?php echo json_encode(_l('se_appt_agenda_empty')); ?>,
    held: <?php echo json_encode(_l('se_appt_mark_held')); ?>,
    sameDay: <?php echo json_encode(_l('se_appt_same_day_procedure')); ?>,
    days: ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt']
  };
  var el = document.getElementById('se-appointments-calendar');

  /* ---------- desktop / tablet: FullCalendar v5 (Perfex ships lib/main.min.js) ---------- */
  if (window.FullCalendar && el && window.getComputedStyle(el).display !== 'none') {
    var cal = new FullCalendar.Calendar(el, {
      initialView: 'dayGridMonth',
      headerToolbar: false,
      firstDay: 1,
      locale: <?php echo json_encode(strtolower(substr($GLOBALS['locale'] ?? 'tr', 0, 2)) ?: 'tr'); ?>,
      height: 'auto',
      nowIndicator: true,
      eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
      events: function (info, ok, fail) {
        $.getJSON(feed, { start: info.startStr.slice(0, 19).replace('T', ' '), end: info.endStr.slice(0, 19).replace('T', ' ') }, ok).fail(fail);
      },
      eventClick: function (arg) { if (arg.event.url) { arg.jsEvent.preventDefault(); window.location.href = arg.event.url; } },
      datesSet: function (arg) { document.getElementById('se-cal-title').textContent = arg.view.title; }
    });
    cal.render();
    document.querySelectorAll('.se-chip[data-view]').forEach(function (b) {
      b.addEventListener('click', function () {
        document.querySelectorAll('.se-chip[data-view]').forEach(function (x) { x.classList.remove('on'); });
        b.classList.add('on'); cal.changeView(b.getAttribute('data-view'));
      });
    });
    document.getElementById('se-cal-prev').addEventListener('click', function () { cal.prev(); });
    document.getElementById('se-cal-next').addEventListener('click', function () { cal.next(); });
    document.getElementById('se-cal-today').addEventListener('click', function () { cal.today(); });
  } else if (el && window.getComputedStyle(el).display !== 'none') {
    document.getElementById('se-cal-missing').hidden = false;   // never a silent blank page again
  }

  /* ---------- phones: agenda ---------- */
  var agenda = document.getElementById('se-agenda');
  if (agenda && window.getComputedStyle(agenda).display !== 'none') {
    var today = new Date(); today.setHours(0, 0, 0, 0);
    var sel = new Date(today);
    function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    function renderDays() {
      var wrap = document.getElementById('se-agenda-days'); wrap.innerHTML = '';
      for (var i = -1; i <= 5; i++) {
        var d = new Date(sel); d.setDate(sel.getDate() + i);
        var b = document.createElement('button'); b.type = 'button'; b.className = 'se-chip' + (i === 0 ? ' on' : '');
        b.textContent = L.days[d.getDay()] + ' ' + d.getDate(); b.setAttribute('data-date', iso(d));
        b.addEventListener('click', function () { sel = new Date(this.getAttribute('data-date') + 'T00:00:00'); renderDays(); load(); });
        wrap.appendChild(b);
      }
    }
    function load() {
      var start = iso(sel) + ' 00:00:00', end = iso(sel) + ' 23:59:59';
      $.getJSON(feed, { start: start, end: end }, function (events) {
        var list = document.getElementById('se-agenda-list');
        var card = document.createElement('div'); card.className = 'se-card';
        var h = document.createElement('h2'); h.textContent = sel.toLocaleDateString('tr-TR', { weekday: 'long', day: 'numeric', month: 'long' });
        var c = document.createElement('span'); c.className = 'se-count'; c.textContent = events.length ? events.length + ' randevu' : '';
        h.appendChild(c); card.appendChild(h);
        if (!events.length) { var p = document.createElement('p'); p.className = 'se-help'; p.textContent = L.empty; card.appendChild(p); }
        else {
          var ul = document.createElement('ul'); ul.className = 'se-agenda';
          events.sort(function (a, b) { return a.start < b.start ? -1 : 1; }).forEach(function (e) {
            var li = document.createElement('li');
            var t = document.createElement('span'); t.className = 't'; t.textContent = e.start.slice(11, 16);
            var bar = document.createElement('span'); bar.className = 'bar'; bar.style.background = (types[e.extendedProps.type] || {}).color || '#93c5fd';
            var body = document.createElement('div');
            var n = document.createElement('a'); n.className = 'n'; n.href = e.url; n.textContent = e.extendedProps.patient || e.title;
            var m = document.createElement('div'); m.className = 'm';
            m.textContent = [(types[e.extendedProps.type] || {}).label, e.extendedProps.place, e.extendedProps.staff].filter(Boolean).join(' · ');
            body.appendChild(n); body.appendChild(m);
            if (e.extendedProps.type === 'consultation' && ['scheduled', 'confirmed'].indexOf(e.extendedProps.status) !== -1) {
              var row = document.createElement('div'); row.className = 'se-row-actions';
              var a1 = document.createElement('a'); a1.className = 'se-btn se-btn-primary se-btn-sm'; a1.href = e.url + '?held=1'; a1.textContent = L.held;
              var a2 = document.createElement('a'); a2.className = 'se-btn se-btn-secondary se-btn-sm'; a2.href = admin_url + 'se_appointments/create?type=procedure&from=' + e.id; a2.textContent = L.sameDay;
              row.appendChild(a1); row.appendChild(a2); body.appendChild(row);
            }
            li.appendChild(t); li.appendChild(bar); li.appendChild(body); ul.appendChild(li);
          });
          card.appendChild(ul);
        }
        list.innerHTML = ''; list.appendChild(card);
      });
    }
    renderDays(); load();
  }
})();
</script>
</body>
</html>
