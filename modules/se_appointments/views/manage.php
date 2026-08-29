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
        <th><?php echo html_escape(_l('se_appt_title')); ?></th>
        <th><?php echo html_escape(_l('se_appt_brand')); ?></th>
        <th><?php echo html_escape(_l('se_appt_start')); ?></th>
        <th><?php echo html_escape(_l('se_appt_staff')); ?></th>
        <th><?php echo html_escape(_l('se_appt_relation')); ?></th>
        <th><?php echo html_escape(_l('se_appt_status')); ?></th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($appointments as $a) { ?>
        <tr>
          <td><a href="<?php echo admin_url('se_appointments/se_appointments/view/' . (int) $a['id']); ?>"><?php echo html_escape($a['title']); ?></a></td>
          <td><?php echo se_ui_brand_badge((int) $a['brand_id']); ?></td>
          <td><?php echo html_escape($a['start_at']); ?></td>
          <td><?php echo html_escape((string) ($a['staff_name'] ?? '')); ?></td>
          <td>
            <?php if (!empty($a['rel_id']) && $a['rel_type'] === 'lead') { ?>
              <a href="<?php echo admin_url('leads/index/' . (int) $a['rel_id']); ?>"><?php echo html_escape(_l('se_appt_lead')); ?> #<?php echo (int) $a['rel_id']; ?></a>
            <?php } elseif (!empty($a['rel_id'])) { ?>
              <a href="<?php echo admin_url('clients/client/' . (int) $a['rel_id']); ?>"><?php echo html_escape(_l('se_appt_customer')); ?> #<?php echo (int) $a['rel_id']; ?></a>
            <?php } else { echo '<span class="text-muted">&mdash;</span>'; } ?>
          </td>
          <td><?php echo se_ui_badge($a['status'], _l('se_appt_status_' . $a['status'])); ?></td>
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
