<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">
            <div class="_buttons">
              <a href="<?php echo admin_url('se_appointments/manage'); ?>" class="btn btn-default pull-right">
                <?php echo _l('se_appt_list'); ?>
              </a>
              <h4 class="no-margin"><?php echo _l('se_appointments'); ?></h4>
            </div>
            <hr class="hr-panel-heading" />
            <div id="se-appointments-calendar"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
<script>
$(function () {
  if (typeof $.fn.fullCalendar !== 'function') { return; }
  $('#se-appointments-calendar').fullCalendar({
    header: { left: 'prev,next today', center: 'title', right: 'month,agendaWeek,agendaDay' },
    defaultView: 'month',
    editable: false,
    events: function (start, end, tz, cb) {
      $.getJSON(admin_url + 'se_appointments/feed', {
        start: start.format('YYYY-MM-DD HH:mm:ss'),
        end: end.format('YYYY-MM-DD HH:mm:ss')
      }, cb);
    },
    eventClick: function (ev) {
      if (ev.url) { window.location.href = ev.url; return false; }
    }
  });
});
</script>
</body>
</html>
