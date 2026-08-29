<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">

<?php se_ui_header(_l('se_meta_leadgen'), [], _l('se_meta_subtitle')); ?>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <form method="get" action="<?php echo admin_url('se_core/se_meta'); ?>" class="form-inline mbot15">
    <label for="brandsel"><?php echo html_escape(_l('se_brand')); ?></label>
    <select id="brandsel" name="brand" class="form-control mleft5" onchange="this.form.submit()">
      <option value="0"<?php echo $brand === 0 ? ' selected' : ''; ?>><?php echo html_escape(_l('se_appt_filter_all')); ?></option>
      <?php foreach ($brands as $b) { ?>
        <option value="<?php echo (int) $b['id']; ?>"<?php echo $brand === (int) $b['id'] ? ' selected' : ''; ?>><?php echo html_escape($b['name']); ?></option>
      <?php } ?>
    </select>
  </form>
  <?php if (se_is_all_brands($brand)) { echo se_all_brands_readonly_notice(); } ?>

  <?php se_ui_kv([
      _l('se_status')            => $status['enabled'] ? se_ui_badge('enabled', _l('se_enabled')) : se_ui_badge('disabled', _l('se_disabled')),
      _l('se_meta_app_owner')    => $status['app_owner'] ? html_escape($status['app_owner']) : '<span class="text-muted">—</span>',
      _l('se_meta_webhook_url')  => '<code>' . html_escape($status['webhook_url']) . '</code>',
      _l('se_meta_webhook_ready') => $status['webhook_ready'] ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('warning', _l('se_no')),
      _l('se_meta_page_token')   => $status['page_token'] ? se_ui_badge('ok', _l('se_credentials_installed')) : se_ui_badge('warning', _l('se_credentials_missing')),
      _l('se_meta_app_secret')   => $status['app_secret'] ? se_ui_badge('ok', _l('se_credentials_installed')) : se_ui_badge('warning', _l('se_credentials_missing')),
      _l('se_meta_verify_token') => $status['verify_token'] ? se_ui_badge('ok', _l('se_credentials_installed')) : se_ui_badge('warning', _l('se_credentials_missing')),
      _l('se_meta_page_form_map') => !empty($status['page_form_mapped']) ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('warning', _l('se_no')),
      _l('se_meta_last_webhook') => html_escape((string) ($status['last_webhook_at'] ?: '—')),
      // "Last successful fetch" now reads the AUTHENTICATED-fetch timestamp, not
      // the reconcile heartbeat — so it is truthfully "—" until a token exists.
      _l('se_meta_last_fetch')   => html_escape((string) ($status['last_fetch_ok_at'] ?: '—')),
      _l('se_meta_last_reconcile') => html_escape((string) ($status['last_reconcile_at'] ?: '—')),
      // Honest reconciliation state — names blockers, never a bare green "Yes".
      _l('se_meta_reconcile')    => '<span class="text-warning">'
          . html_escape((string) ($status['reconcile_status_text'] ?? '')) . '</span>',
      _l('se_meta_last_reconcile_result') => !empty($status['last_reconcile_result'])
          ? se_ui_badge($status['last_reconcile_result'] === 'Reconciled' ? 'ok' : 'warning',
                        html_escape((string) $status['last_reconcile_result']))
            . (!empty($status['last_reconcile_reason'])
                ? ' <small class="text-muted">' . html_escape((string) $status['last_reconcile_reason']) . '</small>'
                : '')
          : '<span class="text-muted">—</span>',
      _l('se_meta_last_error')   => html_escape((string) ($status['last_error'] ?: '—')),
  ], true); ?>

  <p class="text-muted"><small><i class="fa fa-shield"></i> <?php echo html_escape(_l('se_credentials_no_values_note')); ?></small></p>
</div></div></div></div>

<?php if ($brand > 0) {
    // Diagnostic controls. Safe actions run now; gated actions are DISABLED and
    // state their exact prerequisite instead of failing silently.
    $seHasPage = !empty($status['page_token']);
    $seHasCapi = se_secret_configured('meta_capi', (int) $brand) || se_secret_configured('meta_capi', 0);
    $seDiagBtn = function ($action, $label, $enabled, $prereq) use ($brand) {
        if ($enabled) {
            echo form_open(admin_url('se_core/se_meta/diag/' . $action), ['style' => 'display:inline-block;margin:2px']);
            echo '<input type="hidden" name="brand" value="' . (int) $brand . '" />';
            echo '<button type="submit" class="btn btn-default btn-sm">' . html_escape($label) . '</button>';
            echo form_close();
        } else {
            echo '<button type="button" class="btn btn-default btn-sm" disabled style="margin:2px" '
               . 'title="' . html_escape('Prerequisite: ' . $prereq) . '">' . html_escape($label)
               . ' <i class="fa fa-lock"></i></button>';
        }
    };
?>
<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <h5><?php echo html_escape(_l('se_diag_actions')); ?></h5>
  <p class="text-muted"><small><?php echo html_escape(_l('se_diag_actions_hint')); ?></small></p>
  <?php
    $seDiagBtn('recheck', _l('se_diag_recheck'), true, '');
    $seDiagBtn('credential', _l('se_diag_test_credential'), true, '');
    $seDiagBtn('verify_readiness', _l('se_diag_test_verification'), true, '');
    $seDiagBtn('reconcile', _l('se_diag_run_reconcile'), true, '');
    $seDiagBtn('refresh_forms', _l('se_diag_refresh_forms'), $seHasPage, 'Meta Page access token missing');
    $seDiagBtn('send_test_event', _l('se_diag_send_test_event'), $seHasCapi, 'Meta Conversions API token missing');
  ?>
</div></div></div></div>
<?php } ?>

<?php if ($brand > 0) { ?>
<div class="row"><div class="col-md-6"><div class="panel_s"><div class="panel-body">
  <h5><?php echo html_escape(_l('se_meta_defaults')); ?></h5>
  <p class="text-muted"><small><?php echo html_escape(_l('se_meta_defaults_hint')); ?></small></p>
  <?php echo form_open(admin_url('se_core/se_meta/save_defaults')); ?>
    <input type="hidden" name="brand_id" value="<?php echo (int) $brand; ?>" />
    <div class="form-group">
      <label for="lead_status" class="control-label"><?php echo html_escape(_l('se_meta_default_status')); ?></label>
      <select class="form-control" id="lead_status" name="lead_status">
        <?php $cur = (int) get_option('se_meta_default_status_' . (int) $brand);
        foreach ($statuses as $st) { ?>
          <option value="<?php echo (int) $st['id']; ?>"<?php echo $cur === (int) $st['id'] ? ' selected' : ''; ?>><?php echo html_escape($st['name']); ?></option>
        <?php } ?>
      </select>
    </div>
    <div class="form-group">
      <label for="lead_source" class="control-label"><?php echo html_escape(_l('se_meta_default_source')); ?></label>
      <select class="form-control" id="lead_source" name="lead_source">
        <?php $cur = (int) get_option('se_meta_default_source_' . (int) $brand);
        foreach ($sources as $sr) { ?>
          <option value="<?php echo (int) $sr['id']; ?>"<?php echo $cur === (int) $sr['id'] ? ' selected' : ''; ?>><?php echo html_escape($sr['name']); ?></option>
        <?php } ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary"><?php echo html_escape(_l('submit')); ?></button>
  <?php echo form_close(); ?>
</div></div></div>

<div class="col-md-6">
<?php se_ui_gate_checklist(_l('se_meta_external_setup'), [
    ['label' => _l('se_meta_step_app'),     'hint' => _l('se_meta_step_app_hint'),     'done' => (bool) $status['app_owner']],
    ['label' => _l('se_meta_step_secret'),  'hint' => _l('se_meta_step_secret_hint'),  'done' => $status['app_secret']],
    ['label' => _l('se_meta_step_token'),   'hint' => _l('se_meta_step_token_hint'),   'done' => $status['page_token']],
    ['label' => _l('se_meta_step_mapping'), 'hint' => _l('se_meta_step_mapping_hint'), 'done' => count($forms) > 0],
    ['label' => _l('se_meta_step_webhook'), 'hint' => _l('se_meta_step_webhook_hint'), 'done' => (bool) $status['last_webhook_at']],
    ['label' => _l('se_meta_step_review'),  'hint' => _l('se_meta_step_review_hint'),  'done' => false],
]); ?>
</div></div>
<?php } ?>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <h5><?php echo html_escape(_l('se_meta_form_mapping')); ?></h5>
  <?php if (empty($forms)) { se_ui_empty(_l('se_meta_no_forms')); } else { ?>
  <div class="table-responsive"><table class="table table-striped">
    <thead><tr>
      <th><?php echo html_escape(_l('se_brand')); ?></th>
      <th><?php echo html_escape(_l('se_meta_page_id')); ?></th>
      <th><?php echo html_escape(_l('se_meta_form_id')); ?></th>
      <th><?php echo html_escape(_l('se_meta_form_name')); ?></th>
      <th><?php echo html_escape(_l('se_status')); ?></th>
      <th><?php echo html_escape(_l('se_meta_field_map')); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($forms as $f) {
        $map = se_leadgen_sanitize_field_map(json_decode((string) $f['field_map_json'], true)); ?>
      <tr>
        <td><?php echo se_ui_brand_badge((int) $f['brand_id']); ?></td>
        <td><code><?php echo html_escape($f['page_id']); ?></code></td>
        <td><code><?php echo html_escape($f['form_id']); ?></code></td>
        <td><?php echo html_escape($f['form_name'] ?? ''); ?></td>
        <td><?php echo ((int) $f['active'] === 1) ? se_ui_badge('active', _l('se_enabled')) : se_ui_badge('disabled', _l('se_disabled')); ?></td>
        <td><small>
          <?php foreach ($map as $metaField => $col) {
              echo html_escape($metaField) . ' &rarr; <strong>' . html_escape($col) . '</strong><br />';
          } ?>
        </small></td>
      </tr>
    <?php } ?>
    </tbody>
  </table></div>
  <p class="text-muted"><small><?php echo html_escape(_l('se_meta_allowlist_note')); ?>:
    <?php echo html_escape(implode(', ', $allowed_columns)); ?></small></p>
  <?php } ?>
</div></div></div></div>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <h5><?php echo html_escape(_l('se_meta_queue')); ?></h5>
  <?php se_ui_counters($counters, admin_url('se_core/se_meta' . ($brand ? '?brand=' . (int) $brand : '')), 'state'); ?>

  <?php if (empty($events)) { se_ui_empty(_l('se_meta_no_events')); } else { ?>
  <div class="table-responsive"><table class="table table-striped">
    <thead><tr>
      <th>#</th>
      <th><?php echo html_escape(_l('se_meta_page_id')); ?></th>
      <th><?php echo html_escape(_l('se_meta_form_id')); ?></th>
      <th><?php echo html_escape(_l('se_status')); ?></th>
      <th><?php echo html_escape(_l('se_outbox_attempts')); ?></th>
      <th><?php echo html_escape(_l('se_outbox_error')); ?></th>
      <th><?php echo html_escape(_l('se_meta_received')); ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($events as $e) { ?>
      <tr>
        <td><?php echo (int) $e['id']; ?></td>
        <td><code><?php echo html_escape((string) ($e['page_id'] ?? '')); ?></code></td>
        <td><code><?php echo html_escape((string) ($e['form_id'] ?? '')); ?></code></td>
        <td><?php echo se_ui_badge($e['state']); ?></td>
        <td><?php echo (int) ($e['attempts'] ?? 0); ?></td>
        <td><small class="text-muted"><?php echo html_escape((string) ($e['last_error'] ?: '—')); ?></small></td>
        <td><small><?php echo html_escape((string) ($e['received_at'] ?? '')); ?></small></td>
        <td class="text-right">
          <?php if (in_array($e['state'], ['held', 'failed'], true)) {
              if (se_is_all_brands($brand)) { ?>
            <button type="button" class="btn btn-warning btn-sm" disabled
                    title="<?php echo html_escape(_l('se_all_brands_readonly')); ?>">
              <?php echo html_escape(_l('se_outbox_requeue')); ?>
            </button>
          <?php } else { ?>
            <?php echo form_open(admin_url('se_core/se_meta/requeue/' . (int) $e['id']), ['style' => 'display:inline']); ?>
              <button type="submit" class="btn btn-warning btn-sm"
                      onclick="return confirm('<?php echo html_escape(_l('se_meta_requeue_confirm')); ?>');">
                <?php echo html_escape(_l('se_outbox_requeue')); ?>
              </button>
            <?php echo form_close(); ?>
          <?php } } ?>
        </td>
      </tr>
    <?php } ?>
    </tbody>
  </table></div>
  <?php } ?>
</div></div></div></div>

</div></div>
<?php init_tail(); ?></body></html>
