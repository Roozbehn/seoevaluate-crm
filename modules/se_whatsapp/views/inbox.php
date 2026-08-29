<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">

<?php
$actions = [];
if (se_staff_can_configure_brands()) {
    $actions[] = ['href' => admin_url('se_whatsapp/se_whatsapp/readiness'),
                  'label' => _l('se_wa_readiness'), 'icon' => 'fa-heartbeat'];
}
se_ui_header(_l('se_whatsapp'), $actions, _l('se_wa_inbox_subtitle'));
?>

<?php if (empty($has_brand)) { se_ui_no_brand_screen(); } else { ?>

<?php if ($blocked !== '') { ?>
  <div class="row"><div class="col-md-12">
    <div class="alert alert-info">
      <i class="fa fa-info-circle"></i>
      <?php echo html_escape(_l('se_wa_sending_gated')); ?> &mdash;
      <?php echo html_escape(_l('se_wa_blocked_' . $blocked)); ?>
    </div>
  </div></div>
<?php } ?>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">

  <div class="clearfix mbot15">
    <div class="btn-group" role="group">
      <?php $cur = $this->input->get('assigned');
      foreach (['' => 'se_wa_all', 'me' => 'se_wa_assigned_me', 'none' => 'se_wa_unassigned'] as $v => $key) { ?>
        <a href="<?php echo admin_url('se_whatsapp/se_whatsapp/inbox' . ($v ? '?assigned=' . $v : '')); ?>"
           class="btn btn-<?php echo ((string) $cur === (string) $v) ? 'primary' : 'default'; ?>">
          <?php echo html_escape(_l($key)); ?>
        </a>
      <?php } ?>
    </div>
    <div class="pull-right">
      <?php foreach ($out_health as $k => $n) {
          if ($n > 0) { echo se_ui_badge($k, _l('se_wa_queue_' . $k) . ': ' . $n) . ' '; }
      } ?>
    </div>
  </div>

  <?php if (empty($conversations)) {
      se_ui_empty(_l('se_wa_no_conversations'));
  } else { ?>
  <div class="table-responsive">
    <table class="table table-striped">
      <thead><tr>
        <th><?php echo html_escape(_l('se_wa_contact')); ?></th>
        <th><?php echo html_escape(_l('se_wa_brand')); ?></th>
        <th><?php echo html_escape(_l('se_appt_lead')); ?></th>
        <th><?php echo html_escape(_l('se_wa_assigned_staff')); ?></th>
        <th><?php echo html_escape(_l('se_wa_unread')); ?></th>
        <th><?php echo html_escape(_l('se_wa_window')); ?></th>
        <th><?php echo html_escape(_l('se_wa_last_inbound')); ?></th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($conversations as $c) {
          $open = !empty($c['window_expires_at']) && strtotime($c['window_expires_at']) > time(); ?>
        <tr>
          <td><a href="<?php echo admin_url('se_whatsapp/se_whatsapp/conversation/' . (int) $c['id']); ?>">
              <?php echo html_escape($c['wa_user_id']); ?></a></td>
          <td><?php echo se_ui_brand_badge((int) $c['brand_id']); ?></td>
          <td><?php echo !empty($c['lead_id'])
                ? '<a href="' . admin_url('leads/index/' . (int) $c['lead_id']) . '">#' . (int) $c['lead_id'] . '</a>'
                : '<span class="text-muted">&mdash;</span>'; ?></td>
          <td><?php echo !empty($c['assigned_staff'])
                ? html_escape('staff #' . (int) $c['assigned_staff'])
                : '<span class="text-muted">' . html_escape(_l('se_wa_unassigned')) . '</span>'; ?></td>
          <td><?php echo (int) $c['unread_count'] > 0
                ? '<span class="label label-danger">' . (int) $c['unread_count'] . '</span>'
                : '<span class="text-muted">0</span>'; ?></td>
          <td><?php echo $open
                ? se_ui_badge('open', _l('se_wa_window_open'))
                : se_ui_badge('closed', _l('se_wa_window_closed')); ?></td>
          <td><small><?php echo html_escape((string) ($c['last_inbound_at'] ?? '')); ?></small></td>
          <td class="text-right">
            <a href="<?php echo admin_url('se_whatsapp/se_whatsapp/conversation/' . (int) $c['id']); ?>"
               class="btn btn-default btn-sm"><?php echo html_escape(_l('view')); ?></a>
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
