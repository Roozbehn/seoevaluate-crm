<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">

<?php se_ui_header(_l('se_credentials'), [], _l('se_credentials_subtitle')); ?>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <div class="alert alert-info">
    <i class="fa fa-shield"></i> <?php echo html_escape(_l('se_credentials_no_values_note')); ?>
  </div>

  <h5><?php echo html_escape(_l('se_credentials_store')); ?></h5>
  <?php se_ui_kv([
      _l('se_credentials_store_exists')  => $store['exists'] ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('warning', _l('se_no')),
      _l('se_credentials_store_mode')    => $store['mode'] === null
          ? se_ui_badge('unknown', '—')
          : ($store['mode_ok'] ? se_ui_badge('ok', $store['mode']) : se_ui_badge('error', $store['mode'])),
      _l('se_credentials_outside_docroot') => $store['outside_docroot'] ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('error', _l('se_no')),
      _l('se_credentials_path_configured') => $store['configured_path'] ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('warning', _l('se_no')),
  ], true); ?>
</div></div></div></div>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <h5><?php echo html_escape(_l('se_credentials_providers')); ?></h5>
  <div class="table-responsive">
    <table class="table table-striped">
      <thead><tr>
        <th><?php echo html_escape(_l('se_credentials_provider')); ?></th>
        <th><?php echo html_escape(_l('se_brand')); ?></th>
        <th><?php echo html_escape(_l('se_credentials_configured')); ?></th>
        <th><?php echo html_escape(_l('se_credentials_readable')); ?></th>
        <th><?php echo html_escape(_l('se_credentials_mode')); ?></th>
        <th><?php echo html_escape(_l('se_credentials_last_auth')); ?></th>
        <th><?php echo html_escape(_l('se_credentials_last_error')); ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($providers as $p) { ?>
        <tr>
          <td><?php echo html_escape($p['label']); ?></td>
          <td><?php echo $p['brand_name'] === null
                ? '<span class="text-muted">' . html_escape(_l('se_credentials_global')) . '</span>'
                : se_ui_brand_badge((int) $p['brand_id']); ?></td>
          <td><?php echo $p['configured'] ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('unknown', _l('se_no')); ?></td>
          <td><?php echo $p['readable'] ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('unknown', _l('se_no')); ?></td>
          <td><?php echo $p['mode'] === null
                ? '<span class="text-muted">—</span>'
                : ($p['mode_ok'] ? se_ui_badge('ok', $p['mode']) : se_ui_badge('error', $p['mode'])); ?></td>
          <td><small><?php echo html_escape((string) ($p['last_auth_at'] ?: '—')); ?></small></td>
          <td><small class="text-muted"><?php echo html_escape((string) ($p['last_error'] ?: '—')); ?></small></td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
</div></div></div></div>

<div class="row"><div class="col-md-12">
<?php se_ui_gate_checklist(_l('se_credentials_owner_actions'), [
    ['label' => _l('se_cred_step_dir'),   'hint' => _l('se_cred_step_dir_hint'),   'done' => $store['exists'] && $store['mode_ok']],
    ['label' => _l('se_cred_step_const'), 'hint' => _l('se_cred_step_const_hint'), 'done' => $store['configured_path']],
    ['label' => _l('se_cred_step_file'),  'hint' => _l('se_cred_step_file_hint'),  'done' => false],
    ['label' => _l('se_cred_step_enable'),'hint' => _l('se_cred_step_enable_hint'),'done' => false],
]); ?>
</div></div>

</div></div>
<?php init_tail(); ?></body></html>
