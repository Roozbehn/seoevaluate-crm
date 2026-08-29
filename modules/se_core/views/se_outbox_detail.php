<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">

<?php se_ui_header(_l('se_outbox') . ' #' . (int) $row['id'], [
    ['href' => admin_url('se_core/se_outbox'), 'label' => _l('se_back'), 'icon' => 'fa-arrow-left'],
]); ?>

<div class="row"><div class="col-md-8"><div class="panel_s"><div class="panel-body">
  <h5><?php echo html_escape(_l('se_outbox_event')); ?></h5>
  <?php se_ui_kv([
      _l('se_outbox_event_id')   => '<code>' . html_escape($safe['event_id']) . '</code>',
      _l('se_brand')             => se_ui_brand_badge((int) $row['brand_id']),
      _l('se_outbox_destination') => html_escape($safe['destination']),
      _l('se_outbox_event')      => html_escape($safe['event_name']),
      _l('se_outbox_event_time') => html_escape($safe['event_time']),
      _l('se_outbox_captured')   => html_escape((string) $safe['captured_at']),
      _l('se_status')            => se_ui_badge($row['status']),
      _l('se_outbox_attempts')   => (int) $safe['attempts'],
      _l('se_outbox_next_retry') => html_escape((string) ($safe['next_attempt_at'] ?: '—')),
      _l('se_outbox_request_id') => html_escape((string) ($safe['request_id'] ?: '—')),
      _l('se_outbox_submitted_at') => html_escape((string) ($safe['submitted_at'] ?: '—')),
  ], true); ?>
</div></div></div>

<div class="col-md-4">
  <div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_outbox_consent')); ?></h5>
    <?php se_ui_kv([
        _l('se_status')                  => se_ui_badge($safe['consent_state']),
        _l('se_consent_version')         => html_escape((string) ($safe['consent_version'] ?: '—')),
        _l('se_consent_recorded_at')     => html_escape((string) ($safe['consent_at'] ?: '—')),
        _l('se_outbox_snapshot_version') => (int) $safe['payload_version'],
    ], true); ?>
  </div></div>

  <div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_outbox_identifiers')); ?></h5>
    <?php /* Presence only. A hash is still personal data; a click id identifies
             an individual's ad journey. The operator needs to know WHETHER an
             identifier was captured, never what it was. */ ?>
    <p class="text-muted"><small><?php echo html_escape(_l('se_outbox_identifiers_note')); ?></small></p>
    <?php se_ui_kv([
        _l('se_outbox_has_email') => $safe['has_email_hash'] ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('unknown', _l('se_no')),
        _l('se_outbox_has_phone') => $safe['has_phone_hash'] ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('unknown', _l('se_no')),
        _l('se_outbox_has_click') => $safe['has_click_id']   ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('unknown', _l('se_no')),
        _l('se_outbox_has_meta')  => $safe['has_meta_lead']  ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('unknown', _l('se_no')),
    ], true); ?>
  </div></div>

  <?php if (!empty($safe['failure_class']) || !empty($safe['last_error'])) { ?>
  <div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_outbox_error')); ?></h5>
    <?php se_ui_kv([
        _l('se_outbox_failure_class') => se_ui_badge((string) $safe['failure_class']),
        _l('se_outbox_error_code')    => html_escape((string) ($safe['error_code'] ?: '—')),
        _l('se_outbox_error_message') => html_escape((string) ($safe['last_error'] ?: '—')),
    ], true); ?>
  </div></div>
  <?php } ?>
</div></div>

</div></div>
<?php init_tail(); ?></body></html>
