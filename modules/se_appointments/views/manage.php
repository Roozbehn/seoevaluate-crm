<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php
  $CI = &get_instance();
  $CI->load->model('staff_model');
  $staff  = $CI->staff_model->get('', ['active' => 1]);
  $brands = function_exists('se_all_brands') ? se_all_brands(true) : [];
  $statuses = ['scheduled', 'confirmed', 'held', 'completed', 'no_show', 'cancelled'];
?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">
            <div class="_buttons">
              <a href="#" class="btn btn-primary pull-right" onclick="se_appt_new(); return false;">
                <?php echo _l('se_appt_new'); ?>
              </a>
              <a href="<?php echo admin_url('se_appointments/index'); ?>" class="btn btn-default pull-right mright5">
                <?php echo _l('se_appt_calendar'); ?>
              </a>
              <h4 class="no-margin"><?php echo _l('se_appointments'); ?></h4>
            </div>
            <hr class="hr-panel-heading" />
            <table class="table dt-table">
              <thead>
                <tr>
                  <th><?php echo _l('se_appt_title'); ?></th>
                  <th><?php echo _l('se_appt_start'); ?></th>
                  <th><?php echo _l('se_appt_staff'); ?></th>
                  <th><?php echo _l('se_appt_status'); ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($appointments as $a) { ?>
                  <tr>
                    <td><?php echo html_escape($a['title']); ?></td>
                    <td><?php echo _dt($a['start_at']); ?></td>
                    <td><?php echo html_escape($a['staff_name']); ?></td>
                    <td><span class="label label-default"><?php echo _l('se_appt_status_' . $a['status']); ?></span></td>
                    <td class="text-right">
                      <a href="<?php echo admin_url('se_appointments/view/' . $a['id']); ?>" class="btn btn-default btn-xs"><?php echo _l('view'); ?></a>
                    </td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="panel_s">
      <div class="panel-body">
        <h4 id="se-appt-form-title"><?php echo _l('se_appt_new'); ?></h4>
        <hr class="hr-panel-heading" />
        <?php echo form_open(admin_url('se_appointments/save'), ['id' => 'se-appt-form']); ?>
          <input type="hidden" name="id" id="se-appt-id" value="" />
          <div class="row">
            <div class="col-md-6"><?php echo render_input('title', 'se_appt_title'); ?></div>
            <div class="col-md-3"><?php echo render_datetime_input('start_at', 'se_appt_start'); ?></div>
            <div class="col-md-3"><?php echo render_datetime_input('end_at', 'se_appt_end'); ?></div>
          </div>
          <div class="row">
            <div class="col-md-3">
              <label><?php echo _l('se_brand'); ?></label>
              <select name="brand_id" class="form-control selectpicker">
                <option value="0"><?php echo _l('se_brand_unassigned'); ?></option>
                <?php foreach ($brands as $b) { ?>
                  <option value="<?php echo $b['id']; ?>"><?php echo html_escape($b['name']); ?></option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-3">
              <label><?php echo _l('se_appt_staff'); ?></label>
              <select name="staff_id" class="form-control selectpicker">
                <?php foreach ($staff as $s) { ?>
                  <option value="<?php echo $s['staffid']; ?>"><?php echo html_escape($s['firstname'] . ' ' . $s['lastname']); ?></option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-3">
              <label><?php echo _l('se_appt_lead'); ?> (ID)</label>
              <input type="hidden" name="rel_type" value="lead" />
              <input type="number" name="rel_id" class="form-control" value="0" />
            </div>
            <div class="col-md-3">
              <label><?php echo _l('se_appt_status'); ?></label>
              <select name="status" class="form-control selectpicker">
                <?php foreach ($statuses as $st) { ?>
                  <option value="<?php echo $st; ?>"><?php echo _l('se_appt_status_' . $st); ?></option>
                <?php } ?>
              </select>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6"><?php echo render_input('location', 'se_appt_location'); ?></div>
          </div>
          <?php echo render_textarea('notes', 'se_appt_notes'); ?>
          <button type="submit" class="btn btn-primary"><?php echo _l('submit'); ?></button>
        <?php echo form_close(); ?>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
<script>
function se_appt_new() {
  $('#se-appt-id').val('');
  $('#se-appt-form')[0].reset();
  $('html,body').animate({ scrollTop: $('#se-appt-form').offset().top - 80 }, 300);
}
</script>
</body>
</html>
