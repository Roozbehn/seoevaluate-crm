<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">

<?php se_ui_header(_l('se_outbox'), [], _l('se_outbox_subtitle')); ?>

<?php if (empty($has_brand)) { se_ui_no_brand_screen(); } else { ?>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">

  <?php /* submitted vs confirmed is the distinction operators get wrong. */ ?>
  <div class="alert alert-info">
    <i class="fa fa-info-circle"></i> <?php echo html_escape(_l('se_outbox_submitted_explainer')); ?>
  </div>

  <?php se_ui_counters($counters, admin_url('se_core/se_outbox')); ?>

  <?php
  $brandOpts = ['' => _l('se_appt_filter_all')];
  foreach ($brands as $b) { $brandOpts[(int) $b['id']] = $b['name']; }

  se_ui_filters(admin_url('se_core/se_outbox'), [
      'brand'       => ['label' => _l('se_brand'), 'type' => 'select', 'options' => $brandOpts],
      'destination' => ['label' => _l('se_outbox_destination'), 'type' => 'select',
                        'options' => ['' => _l('se_appt_filter_all'), 'meta_capi' => 'Meta CAPI', 'google_dm' => 'Google DM']],
      'status'      => ['label' => _l('se_status'), 'type' => 'select',
                        'options' => ['' => _l('se_appt_filter_all'), 'pending' => 'pending', 'processing' => 'processing',
                                      'submitted' => 'submitted', 'confirmed' => 'confirmed', 'sent' => 'sent',
                                      'failed' => 'failed', 'skipped' => 'skipped']],
      'event'       => ['label' => _l('se_outbox_event'), 'type' => 'text', 'placeholder' => 'Lead'],
      'from'        => ['label' => _l('se_outbox_from'), 'type' => 'date'],
      'to'          => ['label' => _l('se_outbox_to'), 'type' => 'date'],
  ], $filters); ?>

  <?php if (empty($rows)) { se_ui_empty(_l('se_outbox_none')); } else { ?>
  <div class="table-responsive">
    <table class="table table-striped">
      <thead><tr>
        <th><?php echo html_escape(_l('se_outbox_event_id')); ?></th>
        <th><?php echo html_escape(_l('se_brand')); ?></th>
        <th><?php echo html_escape(_l('se_outbox_destination')); ?></th>
        <th><?php echo html_escape(_l('se_outbox_event')); ?></th>
        <th><?php echo html_escape(_l('se_outbox_event_time')); ?></th>
        <th><?php echo html_escape(_l('se_status')); ?></th>
        <th><?php echo html_escape(_l('se_outbox_attempts')); ?></th>
        <th><?php echo html_escape(_l('se_outbox_next_retry')); ?></th>
        <th><?php echo html_escape(_l('se_outbox_error')); ?></th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r) {
          $safe = se_outbox_safe_detail($r);
          $consentBlocked = in_array($r['error_code'] ?? '', ['consent_withdrawn', 'consent_blocked'], true)
              || ($r['failure_class'] ?? '') === 'consent_withdrawn';
          $eligible = in_array($r['status'], se_outbox_requeueable_statuses(), true) && !$consentBlocked; ?>
        <tr>
          <td><code><?php echo html_escape($safe['event_id']); ?></code></td>
          <td><?php echo se_ui_brand_badge((int) $r['brand_id']); ?></td>
          <td><?php echo html_escape($r['destination']); ?></td>
          <td><?php echo html_escape($r['event_name']); ?></td>
          <td><small><?php echo html_escape($r['event_time']); ?></small></td>
          <td><?php echo se_ui_badge($r['status']); ?></td>
          <td><?php echo (int) $r['attempts']; ?></td>
          <td><small class="text-muted"><?php echo html_escape($r['next_attempt_at'] ?: '—'); ?></small></td>
          <td>
            <?php if (!empty($r['failure_class'])) { echo se_ui_badge($r['failure_class']); } ?>
            <?php if (!empty($r['error_code'])) { ?><br /><small class="text-muted"><?php echo html_escape($r['error_code']); ?></small><?php } ?>
          </td>
          <td class="text-right">
            <a href="<?php echo admin_url('se_core/se_outbox/detail/' . (int) $r['id']); ?>" class="btn btn-default btn-sm"><?php echo html_escape(_l('view')); ?></a>
            <?php if ($eligible && se_staff_can_configure_brands()) { ?>
              <?php echo form_open(admin_url('se_core/se_outbox/requeue/' . (int) $r['id']), ['style' => 'display:inline']); ?>
                <button type="submit" class="btn btn-warning btn-sm"
                        onclick="return confirm('<?php echo html_escape(_l('se_outbox_requeue_confirm')); ?>');">
                  <?php echo html_escape(_l('se_outbox_requeue')); ?>
                </button>
              <?php echo form_close(); ?>
            <?php } elseif ($consentBlocked) { ?>
              <span class="label label-danger" title="<?php echo html_escape(_l('se_outbox_requeue_consent_blocked')); ?>">
                <?php echo html_escape(_l('se_outbox_consent_locked')); ?>
              </span>
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
