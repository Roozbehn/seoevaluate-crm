<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">

<?php se_ui_header(_l('se_google_dm'), [], _l('se_google_subtitle')); ?>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <form method="get" action="<?php echo admin_url('se_core/se_google'); ?>" class="form-inline mbot15">
    <label for="brandsel"><?php echo html_escape(_l('se_brand')); ?></label>
    <select id="brandsel" name="brand" class="form-control mleft5" onchange="this.form.submit()">
      <option value="0"><?php echo html_escape(_l('se_appt_filter_all')); ?></option>
      <?php foreach ($brands as $b) { ?>
        <option value="<?php echo (int) $b['id']; ?>"<?php echo $brand === (int) $b['id'] ? ' selected' : ''; ?>><?php echo html_escape($b['name']); ?></option>
      <?php } ?>
    </select>
  </form>

  <div class="alert alert-info">
    <i class="fa fa-info-circle"></i> <?php echo html_escape(_l('se_google_lifecycle_note')); ?>
  </div>

  <?php se_ui_kv([
      _l('se_status')                => $status['enabled'] ? se_ui_badge('enabled', _l('se_enabled')) : se_ui_badge('disabled', _l('se_disabled')),
      _l('se_google_customer_id')    => html_escape((string) ($status['customer_id'] ?: '—')),
      _l('se_google_login_account')  => html_escape((string) ($status['login_account'] ?: '—')),
      _l('se_google_credential')     => $status['credential_ready'] ? se_ui_badge('ok', _l('se_credentials_installed')) : se_ui_badge('warning', _l('se_credentials_missing')),
      _l('se_google_credential_mode') => $status['credential_mode_ok'] === null
          ? '<span class="text-muted">—</span>'
          : ($status['credential_mode_ok'] ? se_ui_badge('ok', '600') : se_ui_badge('error', _l('se_credentials_mode_bad'))),
      _l('se_google_token_renewal')  => $status['token_renewal_implemented']
          ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('unknown', _l('se_not_implemented')),
      _l('se_google_status_polling') => $status['status_polling_implemented']
          ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('unknown', _l('se_not_implemented')),
      _l('se_credentials_last_auth') => html_escape((string) ($status['last_auth_at'] ?: '—')),
      _l('se_credentials_last_error') => html_escape((string) ($status['last_error'] ?: '—')),
  ], true); ?>

  <p class="text-muted"><small><i class="fa fa-shield"></i> <?php echo html_escape(_l('se_credentials_no_values_note')); ?></small></p>
</div></div></div></div>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <h5><?php echo html_escape(_l('se_google_delivery')); ?></h5>
  <?php se_ui_counters($counters); ?>
</div></div></div></div>

<?php if ($brand > 0) { ?>
<div class="row"><div class="col-md-6"><div class="panel_s"><div class="panel-body">
  <h5><?php echo html_escape(_l('se_google_mappings')); ?></h5>
  <?php echo form_open(admin_url('se_core/se_google/save_mapping')); ?>
    <input type="hidden" name="brand_id" value="<?php echo (int) $brand; ?>" />
    <div class="form-group">
      <label for="stage" class="control-label"><?php echo html_escape(_l('se_google_stage')); ?></label>
      <select class="form-control" id="stage" name="stage">
        <?php foreach ($stages as $st) { ?>
          <option value="<?php echo html_escape($st); ?>"><?php echo html_escape($st); ?></option>
        <?php } ?>
      </select>
    </div>
    <div class="form-group">
      <label for="action_id" class="control-label"><?php echo html_escape(_l('se_google_action_id')); ?></label>
      <input type="text" class="form-control" id="action_id" name="action_id" maxlength="64" />
    </div>
    <button type="submit" class="btn btn-primary"><?php echo html_escape(_l('submit')); ?></button>
  <?php echo form_close(); ?>

  <hr />
  <div class="table-responsive"><table class="table table-striped"><tbody>
  <?php foreach ($mappings as $stage => $action) { ?>
    <tr>
      <td><?php echo html_escape($stage); ?></td>
      <td><?php echo $action !== '' ? '<code>' . html_escape($action) . '</code>' : '<span class="text-muted">—</span>'; ?></td>
    </tr>
  <?php } ?>
  </tbody></table></div>
</div></div></div>

<div class="col-md-6">
<?php se_ui_gate_checklist(_l('se_google_external_setup'), [
    ['label' => _l('se_google_step_mcc'),    'hint' => _l('se_google_step_mcc_hint'),    'done' => (bool) $status['customer_id']],
    ['label' => _l('se_google_step_project'),'hint' => _l('se_google_step_project_hint'),'done' => false],
    ['label' => _l('se_google_step_sa'),     'hint' => _l('se_google_step_sa_hint'),     'done' => $status['credential_ready']],
    ['label' => _l('se_google_step_actions'),'hint' => _l('se_google_step_actions_hint'),'done' => count(array_filter($mappings)) > 0],
    ['label' => _l('se_google_step_enable'), 'hint' => _l('se_google_step_enable_hint'), 'done' => $status['enabled']],
]); ?>
</div></div>
<?php } ?>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <h5><?php echo html_escape(_l('se_google_requests')); ?></h5>
  <?php if (empty($requests)) { se_ui_empty(_l('se_google_no_requests')); } else { ?>
  <div class="table-responsive"><table class="table table-striped">
    <thead><tr>
      <th><?php echo html_escape(_l('se_brand')); ?></th>
      <th><?php echo html_escape(_l('se_outbox_request_id')); ?></th>
      <th><?php echo html_escape(_l('se_google_event_count')); ?></th>
      <th><?php echo html_escape(_l('se_status')); ?></th>
      <th><?php echo html_escape(_l('se_google_created')); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($requests as $r) { ?>
      <tr>
        <td><?php echo se_ui_brand_badge((int) $r['brand_id']); ?></td>
        <td><code><?php echo html_escape($r['request_id']); ?></code></td>
        <td><?php echo (int) $r['event_count']; ?></td>
        <td><?php echo se_ui_badge($r['status']); ?></td>
        <td><small><?php echo html_escape($r['created_at']); ?></small></td>
      </tr>
    <?php } ?>
    </tbody>
  </table></div>
  <?php } ?>
</div></div></div></div>

</div></div>
<?php init_tail(); ?></body></html>
