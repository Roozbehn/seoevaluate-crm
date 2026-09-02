<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">

<?php
$actions = [];
if (se_journey_is_integration_admin() || se_journey_can('manage_templates')) {
    $actions[] = ['href' => admin_url('se_journey/se_journey/templates'), 'label' => _l('se_journey_templates'), 'icon' => 'fa-file-text-o'];
    $actions[] = ['href' => admin_url('se_journey/se_journey/flows'), 'label' => _l('se_journey_flows'), 'icon' => 'fa-mobile'];
}
if (se_journey_is_integration_admin() || se_journey_can('manage_consent')) {
    $actions[] = ['href' => admin_url('se_journey/se_journey/settings'), 'label' => _l('se_journey_settings'), 'icon' => 'fa-cog'];
}
se_ui_header(_l('se_journeys'), $actions, _l('se_journey_subtitle'));
?>

<?php if (!empty($readiness) && (!empty($readiness['blocking']) || !$readiness['go_live_ready'])) { ?>
<div class="row"><div class="col-md-12">
  <div class="alert alert-<?php echo $readiness['blocking'] ? 'warning' : 'info'; ?>">
    <i class="fa fa-info-circle"></i>
    <?php echo html_escape(sprintf(_l('se_journey_readiness_summary'), (int) $readiness['blocking'])); ?>
    <a href="<?php echo admin_url('se_journey/se_journey/settings'); ?>"><?php echo html_escape(_l('se_journey_settings')); ?> &raquo;</a>
  </div>
</div></div>
<?php } ?>

<?php /* Counters — counts only, never content. Each links to a filtered list. */ ?>
<div class="row">
<?php
$cards = [
    'new_enquiries' => ['fa-comment', ''], 'incomplete_intake' => ['fa-file-text-o', ''], 'waiting_photos' => ['fa-camera', ''],
    'ready_for_review' => ['fa-search', 'info'], 'quote_pending' => ['fa-check-square-o', 'warning'], 'consultation_due' => ['fa-calendar', ''],
    'procedure_booked' => ['fa-medkit', ''], 'followup_due' => ['fa-heartbeat', ''], 'urgent' => ['fa-exclamation-triangle', 'danger'], 'failed_message' => ['fa-times-circle', 'danger'],
];
$states = ['new_enquiries' => 'welcome_sent', 'incomplete_intake' => 'intake_started', 'waiting_photos' => 'photos_requested', 'ready_for_review' => 'ready_for_review',
           'quote_pending' => 'quote_pending_staff_approval', 'consultation_due' => 'consultation_recommended', 'procedure_booked' => 'procedure_booked', 'followup_due' => 'followup_due'];
foreach ($cards as $key => [$icon, $tone]) {
    $href = isset($states[$key]) ? admin_url('se_journey/se_journey/index?state=' . $states[$key]) : ($key === 'urgent' ? admin_url('se_journey/se_journey/index?urgent=1') : null);
    echo '<div class="col-md-3 col-sm-4 col-xs-6">';
    echo se_ui_stat_card(_l('se_journey_counter_' . $key), (int) ($counters[$key] ?? 0), $href, $icon, $tone);
    echo '</div>';
}
?>
</div>

<div class="row">
  <div class="col-md-5">
    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_journey_attention')); ?> <small class="text-muted"><?php echo count($tasks); ?></small></h5>
      <?php if (!$tasks) { se_ui_empty(_l('se_journey_no_tasks')); } else { ?>
      <?php foreach ($tasks as $t) { ?>
        <div class="clearfix" style="padding:6px 0;border-bottom:1px solid rgba(128,128,128,.2)">
          <?php echo form_open(admin_url('se_journey/se_journey/action/' . (int) $t['journey_id'] . '/task_done'), ['class' => 'pull-right mleft5']); ?>
            <input type="hidden" name="task_id" value="<?php echo (int) $t['id']; ?>" />
            <button class="btn btn-default btn-xs" type="submit" title="<?php echo html_escape(_l('se_journey_task_done')); ?>"><i class="fa fa-check"></i></button>
          <?php echo form_close(); ?>
          <?php echo $t['priority'] === 'urgent' ? se_ui_badge('failed', _l('se_journey_urgent')) . ' ' : ''; ?>
          <a href="<?php echo admin_url('se_journey/se_journey/view/' . (int) $t['journey_id']); ?>">#<?php echo (int) $t['journey_id']; ?></a>
          <?php echo html_escape($t['title']); ?>
          <br /><small class="text-muted"><?php echo html_escape($t['created_at']); ?></small>
        </div>
      <?php } ?>
      <?php } ?>
    </div></div>
  </div>

  <div class="col-md-7">
    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_journeys')); ?>
        <?php if ($filter['state'] !== '' || $filter['urgent']) { ?>
          <small><a href="<?php echo admin_url('se_journey/se_journey/index'); ?>"><?php echo html_escape(_l('se_journey_clear_filter')); ?></a></small>
        <?php } ?>
      </h5>
      <?php if (!$journeys) { se_ui_empty(_l('se_journey_none')); } else { ?>
      <div class="table-responsive"><table class="table table-hover">
        <thead><tr>
          <th>#</th><th><?php echo html_escape(_l('se_journey_patient')); ?></th><th><?php echo html_escape(_l('se_journey_state')); ?></th>
          <th class="hidden-xs"><?php echo html_escape(_l('se_journey_source')); ?></th><th class="hidden-xs"><?php echo html_escape(_l('se_journey_automation')); ?></th>
          <th class="hidden-xs"><?php echo html_escape(_l('se_journey_updated')); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($journeys as $r) { ?>
          <tr>
            <td><a href="<?php echo admin_url('se_journey/se_journey/view/' . (int) $r['id']); ?>"><?php echo (int) $r['id']; ?></a>
                <?php echo (int) $r['urgent'] === 1 ? ' ' . se_ui_badge('failed', _l('se_journey_urgent')) : ''; ?></td>
            <td><?php echo html_escape($r['display_name'] ?: ('••••' . substr((string) $r['wa_user_id'], -4))); ?></td>
            <td><?php echo se_ui_badge(se_journey_ui_state_tone($r['state']), _l('se_journey_state_' . $r['state'])); ?></td>
            <td class="hidden-xs"><small><?php echo html_escape(_l('se_journey_source_' . $r['source'])); ?></small></td>
            <td class="hidden-xs"><?php echo se_ui_badge(se_journey_ui_automation_tone($r['automation_state']), _l('se_journey_auto_' . $r['automation_state'])); ?></td>
            <td class="hidden-xs"><small><?php echo html_escape((string) $r['last_updated']); ?></small></td>
          </tr>
        <?php } ?>
        </tbody>
      </table></div>
      <?php } ?>
    </div></div>
  </div>
</div>

</div></div>
<?php init_tail(); ?></body></html>
