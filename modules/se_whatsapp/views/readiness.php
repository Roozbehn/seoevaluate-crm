<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">

<?php se_ui_header(_l('se_wa_readiness'), [
    ['href' => admin_url('se_whatsapp/se_whatsapp/inbox'), 'label' => _l('se_whatsapp'), 'icon' => 'fa-arrow-left'],
], _l('se_wa_readiness_subtitle')); ?>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <form method="get" action="<?php echo admin_url('se_whatsapp/se_whatsapp/readiness'); ?>" class="form-inline mbot15">
    <label for="brandsel"><?php echo html_escape(_l('se_wa_brand')); ?></label>
    <select id="brandsel" name="brand" class="form-control mleft5" onchange="this.form.submit()">
      <option value="0"><?php echo html_escape(_l('se_appt_filter_all')); ?></option>
      <?php foreach ($brands as $b) { ?>
        <option value="<?php echo (int) $b['id']; ?>"<?php echo $brand === (int) $b['id'] ? ' selected' : ''; ?>>
          <?php echo html_escape($b['name']); ?></option>
      <?php } ?>
    </select>
  </form>

  <?php if ($blocked !== '') { ?>
    <div class="alert alert-warning">
      <i class="fa fa-lock"></i> <strong><?php echo html_escape(_l('se_wa_sending_gated')); ?></strong>
      &mdash; <?php echo html_escape(_l('se_wa_blocked_' . $blocked)); ?>
    </div>
  <?php } else { ?>
    <div class="alert alert-success">
      <i class="fa fa-check-circle"></i> <strong><?php echo html_escape(_l('se_wa_sending_ready')); ?></strong>
      &mdash; <?php echo html_escape(_l('se_wa_blocked_none')); ?>
    </div>
  <?php } ?>

  <p class="text-muted"><small><i class="fa fa-shield"></i>
    <?php echo html_escape(_l('se_credentials_no_values_note')); ?></small></p>
</div></div></div></div>

<div class="row">
  <div class="col-md-6"><div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_wa_webhook_health')); ?></h5>
    <?php se_ui_kv([
        _l('se_meta_webhook_url')  => '<code>' . html_escape($webhook['url']) . '</code>',
        _l('se_meta_app_secret')   => $webhook['app_secret']
            ? se_ui_badge('ok', !empty($webhook['app_secret_inherited'])
                ? _l('se_wa_secret_inherited') : _l('se_credentials_installed'))
            : se_ui_badge('warning', _l('se_credentials_missing')),
        _l('se_wa_verify_token')   => $webhook['verify_token']
            ? se_ui_badge('ok', _l('se_credentials_installed')) : se_ui_badge('warning', _l('se_credentials_missing')),
        _l('se_wa_last_event')     => html_escape((string) ($webhook['last_event'] ?: '—')),
    ], true); ?>
  </div></div></div>

  <div class="col-md-6"><div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_wa_queue_health')); ?></h5>
    <?php se_ui_counters($out_health); ?>
    <p class="text-muted"><small><?php echo html_escape(_l(
        $blocked === '' ? 'se_wa_queue_note_ready' : 'se_wa_queue_note'
    )); ?></small></p>
  </div></div></div>
</div>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <h5><?php echo html_escape(_l('se_wa_numbers')); ?></h5>
  <?php if (empty($numbers)) { se_ui_empty(_l('se_wa_no_numbers')); } else { ?>
  <div class="table-responsive"><table class="table table-striped">
    <thead><tr>
      <th><?php echo html_escape(_l('se_wa_brand')); ?></th>
      <th><?php echo html_escape(_l('se_wa_display_number')); ?></th>
      <th><?php echo html_escape(_l('se_whatsapp_phone_number_id')); ?></th>
      <th><?php echo html_escape(_l('se_whatsapp_waba_id')); ?></th>
      <th><?php echo html_escape(_l('se_wa_quality')); ?></th>
      <th><?php echo html_escape(_l('se_status')); ?></th>
      <th><?php echo html_escape(_l('se_wa_token_ref')); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($numbers as $n) { ?>
      <tr>
        <td><?php echo se_ui_brand_badge((int) $n['brand_id']); ?></td>
        <td><?php echo html_escape((string) $n['display_number']); ?></td>
        <td><code><?php echo html_escape((string) $n['phone_number_id']); ?></code></td>
        <td><code><?php echo html_escape((string) $n['waba_id']); ?></code></td>
        <td><?php echo se_ui_badge(strtolower((string) $n['quality_rating']) ?: 'unknown'); ?></td>
        <td><?php echo se_ui_badge((string) $n['state']); ?></td>
        <?php /* Only whether a reference EXISTS — never the reference's value,
                 and never the credential it points at. */ ?>
        <td><?php echo !empty($n['token_option_ref'])
              ? se_ui_badge('ok', _l('se_credentials_configured'))
              : se_ui_badge('warning', _l('se_credentials_missing')); ?></td>
      </tr>
    <?php } ?>
    </tbody>
  </table></div>
  <?php } ?>
</div></div></div></div>

<?php if ($brand > 0) { ?>
<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <h5><?php echo html_escape(_l('se_wa_templates')); ?></h5>
  <?php if (empty($templates)) { se_ui_empty(_l('se_wa_no_templates')); } else { ?>
  <div class="table-responsive"><table class="table table-striped">
    <thead><tr>
      <th><?php echo html_escape(_l('se_wa_template')); ?></th>
      <th><?php echo html_escape(_l('se_wa_language')); ?></th>
      <th><?php echo html_escape(_l('se_wa_category')); ?></th>
      <th><?php echo html_escape(_l('se_status')); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($templates as $t) { ?>
      <tr>
        <td><?php echo html_escape($t['name']); ?></td>
        <td><?php echo html_escape((string) $t['language']); ?></td>
        <td><?php echo html_escape((string) $t['category']); ?></td>
        <td><?php echo se_ui_badge('ok', html_escape((string) $t['approval_state'])); ?></td>
      </tr>
    <?php } ?>
    </tbody>
  </table></div>
  <?php } ?>
</div></div></div></div>
<?php } ?>

<div class="row"><div class="col-md-12">
<?php
$signedPost = !empty($webhook_state['signed_post_received']);
$liveTest   = !empty($webhook_state['live_test_passed']);
$transport  = function_exists('se_wa_transport_available') && se_wa_transport_available();
$sentCount  = (int) ($out_health['sent'] ?? 0);
se_ui_gate_checklist(_l('se_wa_external_setup'), [
    ['label' => _l('se_wa_step_secret'),   'hint' => _l('se_wa_step_secret_hint'),   'done' => $webhook['app_secret']],
    ['label' => _l('se_wa_step_verify'),   'hint' => _l('se_wa_step_verify_hint'),   'done' => $webhook['verify_token']],
    // A provider-signed POST reaching the controller is stronger evidence of
    // the narrow route exclusion than a static configuration guess.
    ['label' => _l('se_wa_step_csrf'),     'hint' => _l('se_wa_step_csrf_hint'),     'done' => $signedPost],
    ['label' => _l('se_wa_step_number'),   'hint' => _l('se_wa_step_number_hint'),   'done' => count($numbers) > 0],
    ['label' => _l('se_wa_step_webhook'),  'hint' => _l('se_wa_step_webhook_hint'),  'done' => (bool) $webhook['last_event']],
    ['label' => _l('se_wa_step_templates'),'hint' => _l('se_wa_step_templates_hint'),'done' => count($templates) > 0],
    ['label' => _l('se_wa_step_review'),   'hint' => _l('se_wa_step_review_hint'),   'done' => $liveTest && $sentCount > 0],
    ['label' => _l('se_wa_step_transport'),'hint' => _l('se_wa_step_transport_hint'),'done' => $transport],
]); ?>
</div></div>

</div></div>
<?php init_tail(); ?></body></html>
