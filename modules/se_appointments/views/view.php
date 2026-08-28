<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-8 col-md-offset-2">
        <div class="panel_s">
          <div class="panel-body">
            <div class="_buttons">
              <a href="<?php echo admin_url('se_appointments/manage'); ?>" class="btn btn-default pull-right"><?php echo _l('se_appt_list'); ?></a>
              <h4 class="no-margin"><?php echo html_escape($appointment->title); ?></h4>
            </div>
            <hr class="hr-panel-heading" />
            <table class="table">
              <tr><td><?php echo _l('se_appt_start'); ?></td><td><?php echo _dt($appointment->start_at); ?></td></tr>
              <tr><td><?php echo _l('se_appt_end'); ?></td><td><?php echo $appointment->end_at ? _dt($appointment->end_at) : '&mdash;'; ?></td></tr>
              <tr><td><?php echo _l('se_appt_status'); ?></td><td><span class="label label-default"><?php echo _l('se_appt_status_' . $appointment->status); ?></span></td></tr>
              <tr><td><?php echo _l('se_appt_staff'); ?></td><td><?php echo html_escape($appointment->staff_name); ?></td></tr>
              <tr><td><?php echo _l('se_brand'); ?></td><td><?php echo function_exists('se_brand_name') ? html_escape(se_brand_name($appointment->brand_id)) : (int) $appointment->brand_id; ?></td></tr>
              <tr><td><?php echo _l('se_appt_lead'); ?></td>
                <td><?php echo $appointment->rel_type === 'lead' && $appointment->rel_id
                    ? '<a href="' . admin_url('leads/index/' . (int) $appointment->rel_id) . '">#' . (int) $appointment->rel_id . '</a>'
                    : '&mdash;'; ?></td></tr>
              <tr><td><?php echo _l('se_appt_location'); ?></td><td><?php echo html_escape($appointment->location); ?></td></tr>
              <tr><td><?php echo _l('se_appt_notes'); ?></td><td><?php echo nl2br(html_escape($appointment->notes)); ?></td></tr>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
