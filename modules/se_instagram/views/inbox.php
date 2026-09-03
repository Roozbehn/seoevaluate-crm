<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content se-page se-messages">

<?php se_ui_header(_l('se_nav_messages') . ' · Instagram', [], _l('se_ig_inbox_subtitle')); ?>
<?php if (function_exists('se_messages_channel_switch')) { $sw = se_messages_channel_switch('instagram'); if ($sw !== '') { echo '<div class="row"><div class="col-md-12"><div class="se-toolbar" style="margin-bottom:12px">' . $sw . '</div></div></div>'; } } ?>

<?php if (empty($has_brand)) { se_ui_no_brand_screen(); } else { ?>

<?php if ($blocked !== '') { ?>
  <div class="row"><div class="col-md-12">
    <div class="alert alert-info"><i class="fa fa-info-circle"></i>
      <?php echo html_escape(_l('se_ig_sending_gated')); ?> &mdash;
      <?php echo html_escape(_l('se_ig_blocked_' . $blocked)); ?>
    </div>
  </div></div>
<?php } ?>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <div class="clearfix mbot15">
    <div class="btn-group" role="group">
      <?php $cur = $this->input->get('assigned');
      foreach (['' => 'se_ig_all', 'me' => 'se_ig_assigned_me', 'none' => 'se_ig_unassigned'] as $v => $key) { ?>
        <a href="<?php echo admin_url('se_instagram/se_instagram/inbox' . ($v ? '?assigned=' . $v : '')); ?>"
           class="btn btn-<?php echo ((string) $cur === (string) $v) ? 'primary' : 'default'; ?>">
          <?php echo html_escape(_l($key)); ?></a>
      <?php } ?>
    </div>
    <div class="pull-right">
      <?php foreach ($out_health as $k => $n) { if ($n > 0) { echo se_ui_badge($k, $k . ': ' . $n) . ' '; } } ?>
    </div>
  </div>

  <?php if (empty($conversations)) { se_ui_empty(_l('se_ig_no_conversations')); } else { ?>
  <div class="table-responsive"><table class="table table-striped">
    <thead><tr>
      <th><?php echo html_escape(_l('se_ig_contact')); ?></th>
      <th><?php echo html_escape(_l('se_brand')); ?></th>
      <th><?php echo html_escape(_l('se_ig_ad_referral')); ?></th>
      <th><?php echo html_escape(_l('se_ig_assigned_staff')); ?></th>
      <th><?php echo html_escape(_l('se_ig_unread')); ?></th>
      <th><?php echo html_escape(_l('se_ig_window')); ?></th>
      <th><?php echo html_escape(_l('se_ig_last_inbound')); ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($conversations as $c) {
        $open = !empty($c['window_expires_at']) && strtotime($c['window_expires_at']) > time(); ?>
      <tr>
        <td><a href="<?php echo admin_url('se_instagram/se_instagram/conversation/' . (int) $c['id']); ?>">
            <?php echo html_escape(se_ig_redacted_contact($c['igsid'])); ?></a></td>
        <td><?php echo se_ui_brand_badge((int) $c['brand_id']); ?></td>
        <td><?php echo !empty($c['referral_ad_id'])
              ? '<span class="label label-info">ad ' . html_escape($c['referral_ad_id']) . '</span>'
              : '<span class="text-muted">&mdash;</span>'; ?></td>
        <td><?php echo !empty($c['assigned_staff']) ? html_escape('staff #' . (int) $c['assigned_staff'])
              : '<span class="text-muted">' . html_escape(_l('se_ig_unassigned')) . '</span>'; ?></td>
        <td><?php echo (int) $c['unread_count'] > 0
              ? '<span class="label label-danger">' . (int) $c['unread_count'] . '</span>' : '<span class="text-muted">0</span>'; ?></td>
        <td><?php echo $open ? se_ui_badge('open', _l('se_ig_window_open')) : se_ui_badge('closed', _l('se_ig_window_closed')); ?></td>
        <td><small><?php echo html_escape((string) ($c['last_inbound_at'] ?? '')); ?></small></td>
        <td class="text-right"><a href="<?php echo admin_url('se_instagram/se_instagram/conversation/' . (int) $c['id']); ?>"
             class="btn btn-default btn-sm"><?php echo html_escape(_l('view')); ?></a></td>
      </tr>
    <?php } ?>
    </tbody>
  </table></div>
  <?php } ?>
</div></div></div></div>
<?php } ?>

</div></div>
<?php init_tail(); ?></body></html>
