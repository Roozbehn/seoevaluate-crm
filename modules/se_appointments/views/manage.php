<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">

<?php se_ui_header(_l('se_appointments'), [
    ['href' => admin_url('se_appointments/se_appointments/create'), 'label' => _l('se_appt_new'), 'icon' => 'fa-plus', 'class' => 'btn-primary'],
    ['href' => admin_url('se_appointments/se_appointments/index'),  'label' => _l('se_appt_calendar'), 'icon' => 'fa-calendar'],
]); ?>

<?php if (empty($has_brand)) { se_ui_no_brand_screen(); } else { ?>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">

  <?php
  $brandOpts = ['' => _l('se_appt_filter_all')];
  foreach ($brands as $b) { $brandOpts[(int) $b['id']] = $b['name']; }

  $staffOpts = ['' => _l('se_appt_filter_all')];
  foreach ($staff as $s) { $staffOpts[(int) $s['staffid']] = trim($s['firstname'] . ' ' . $s['lastname']); }

  $statusOpts = ['' => _l('se_appt_filter_all')];
  foreach ($statuses as $st) { $statusOpts[$st] = _l('se_appt_status_' . $st); }

  se_ui_filters(admin_url('se_appointments/se_appointments/manage'), [
      'brand'  => ['label' => _l('se_appt_brand'),  'type' => 'select', 'options' => $brandOpts],
      'staff'  => ['label' => _l('se_appt_staff'),  'type' => 'select', 'options' => $staffOpts],
      'status' => ['label' => _l('se_appt_status'), 'type' => 'select', 'options' => $statusOpts],
  ], $filters); ?>

  <?php if (empty($appointments)) {
      se_ui_empty(_l('se_appt_none'), [
          'href'  => admin_url('se_appointments/se_appointments/create'),
          'label' => _l('se_appt_new'),
      ]);
  } else { ?>
  <div class="table-responsive">
    <table class="table table-striped">
      <thead><tr>
        <th><?php echo html_escape(_l('se_appt_col_patient')); ?></th>
        <th><?php echo html_escape(_l('se_appt_col_when')); ?></th>
        <th><?php echo html_escape(_l('se_appt_col_type')); ?></th>
        <th class="hidden-xs"><?php echo html_escape(_l('se_appt_staff')); ?></th>
        <th><?php echo html_escape(_l('se_appt_status')); ?></th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($appointments as $a) { ?>
        <tr>
          <?php $pn = $names[$a['rel_type'] . ':' . (int) $a['rel_id']] ?? ''; ?>
          <td><a href="<?php echo admin_url('se_appointments/se_appointments/view/' . (int) $a['id']); ?>"><strong><?php echo html_escape($pn !== '' ? se_ui_short_name($pn) : $a['title']); ?></strong></a><?php if (count($brands) > 1) { echo ' ', se_ui_brand_badge((int) $a['brand_id']); } ?></td>
          <td><?php echo html_escape(se_ui_when($a['start_at'])); ?></td>
          <td><span class="<?php echo se_appt_type_class($a['appointment_type'] ?? ''); ?>" style="padding:2px 8px;border-radius:4px;border-inline-start:3px solid;font-size:12px;font-weight:600"><?php echo html_escape(se_appt_type_label($a['appointment_type'] ?? '')); ?></span></td>
          <td class="hidden-xs"><?php echo html_escape($a['staff_name'] !== '' ? se_ui_short_name((string) $a['staff_name']) : '—'); ?></td>
          <td><?php echo se_ui_ds_badge(in_array($a['status'], ['cancelled', 'no_show'], true) ? 'danger' : (in_array($a['status'], ['held', 'completed'], true) ? 'positive' : 'info'), _l('se_appt_status_' . $a['status'])); ?></td>
          <td class="text-right">
            <a href="<?php echo admin_url('se_appointments/se_appointments/view/' . (int) $a['id']); ?>" class="btn btn-default btn-sm"><?php echo html_escape(_l('view')); ?></a>
            <?php if (staff_can('edit', 'se_appointments')) { ?>
              <a href="<?php echo admin_url('se_appointments/se_appointments/edit/' . (int) $a['id']); ?>" class="btn btn-default btn-sm"><?php echo html_escape(_l('edit')); ?></a>
            <?php } ?>
          </td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
  <?php } ?>

</div></div></div></div>
<?php } ?>

</div></div>
<?php init_tail(); ?></body></html>
