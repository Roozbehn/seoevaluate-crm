<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $a = $appointment; ?>
<div id="wrapper"><div class="content">

<?php
$actions = [['href' => admin_url('se_appointments/se_appointments/manage'), 'label' => _l('se_appt_list'), 'icon' => 'fa-arrow-left']];

if (staff_can('edit', 'se_appointments')) {
    $actions[] = ['href' => admin_url('se_appointments/se_appointments/edit/' . (int) $a->id),
                  'label' => _l('edit'), 'icon' => 'fa-pencil', 'class' => 'btn-primary'];
}

se_ui_header($a->title, $actions, _l('se_appt_detail'));
?>

<div class="row">
  <div class="col-md-7"><div class="panel_s"><div class="panel-body">
    <?php se_ui_kv([
        _l('se_appt_brand')    => se_ui_brand_badge((int) $a->brand_id),
        _l('se_appt_status')   => se_ui_badge($a->status, _l('se_appt_status_' . $a->status)),
        _l('se_appt_start')    => html_escape($a->start_at),
        _l('se_appt_end')      => html_escape((string) ($a->end_at ?: '—')),
        _l('se_appt_staff')    => html_escape((string) ($a->staff_name ?: '—')),
        _l('se_appt_relation') => (!empty($a->rel_id) && $a->rel_type === 'lead')
            ? '<a href="' . admin_url('leads/index/' . (int) $a->rel_id) . '">' . html_escape(_l('se_appt_lead')) . ' #' . (int) $a->rel_id . '</a>'
            : (!empty($a->rel_id)
                ? '<a href="' . admin_url('clients/client/' . (int) $a->rel_id) . '">' . html_escape(_l('se_appt_customer')) . ' #' . (int) $a->rel_id . '</a>'
                : '<span class="text-muted">&mdash;</span>'),
        _l('se_appt_format')   => html_escape((string) ($a->consultation_format ? _l('se_appt_format_' . $a->consultation_format) : '—')),
        _l('se_appt_timezone') => html_escape((string) ($a->staff_timezone ?: '—')),
        _l('se_appt_location') => html_escape((string) ($a->location ?: '—')),
        _l('se_appt_cancellation_reason') => html_escape((string) ($a->cancellation_reason ?: '—')),
        _l('se_appt_sync_state') => se_ui_badge((string) ($a->gcal_sync_state ?? 'unknown')),
    ], true); ?>

    <?php if (!empty($a->notes)) { ?>
      <h5><?php echo html_escape(_l('se_appt_notes')); ?></h5>
      <p><?php echo nl2br(html_escape($a->notes)); ?></p>
    <?php } ?>
  </div></div></div>

  <div class="col-md-5">
    <?php if (staff_can('edit', 'se_appointments')) { ?>
    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_appt_status')); ?></h5>
      <?php /* Each action is its own CSRF-protected POST. A status change is a
               mutation and must never be reachable by a GET link. */ ?>
      <?php foreach (['held', 'completed', 'no_show', 'cancelled'] as $target) {
          if ($a->status === $target) { continue; } ?>
        <?php echo form_open(admin_url('se_appointments/se_appointments/status/' . (int) $a->id), ['style' => 'display:inline']); ?>
          <input type="hidden" name="status" value="<?php echo html_escape($target); ?>" />
          <button type="submit" class="btn btn-default btn-sm mbot10"
                  onclick="return confirm('<?php echo html_escape(_l('se_appt_status_confirm')); ?>');">
            <?php echo html_escape(_l('se_appt_status_' . $target)); ?>
          </button>
        <?php echo form_close(); ?>
      <?php } ?>
    </div></div>
    <?php } ?>

    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_appt_history')); ?></h5>
      <?php if (empty($history)) { se_ui_empty(_l('se_none')); } else { ?>
        <ul class="list-unstyled">
        <?php foreach ($history as $h) { ?>
          <li class="mbot15">
            <?php echo $h['old_status']
                ? se_ui_badge($h['old_status'], _l('se_appt_status_' . $h['old_status'])) . ' &rarr; '
                : ''; ?>
            <?php echo se_ui_badge($h['new_status'], _l('se_appt_status_' . $h['new_status'])); ?>
            <br /><small class="text-muted">
              <?php echo html_escape((string) ($h['changed_at'] ?? '')); ?>
              <?php if (!empty($h['changed_by'])) { echo ' &middot; staff #' . (int) $h['changed_by']; } ?>
            </small>
          </li>
        <?php } ?>
        </ul>
      <?php } ?>
    </div></div>
  </div>
</div>

</div></div>
<?php init_tail(); ?></body></html>
