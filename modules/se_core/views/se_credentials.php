<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">

<?php se_ui_header(_l('se_credentials'), [], _l('se_credentials_subtitle')); ?>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <div class="alert alert-info">
    <i class="fa fa-shield"></i> <?php echo html_escape(_l('se_credentials_no_values_note')); ?>
  </div>

  <?php if (!empty($brands)) { ?>
  <form method="get" action="<?php echo admin_url('se_core/se_credentials'); ?>" class="form-inline mbot15">
    <label for="brandsel"><?php echo html_escape(_l('se_brand')); ?></label>
    <select id="brandsel" name="brand" class="form-control mleft5" onchange="this.form.submit()">
      <option value="0"<?php echo ((int) $brand === 0) ? ' selected' : ''; ?>><?php echo html_escape(_l('se_appt_filter_all')); ?></option>
      <?php foreach ($brands as $b) { ?>
        <option value="<?php echo (int) $b['id']; ?>"<?php echo (int) $brand === (int) $b['id'] ? ' selected' : ''; ?>><?php echo html_escape($b['name']); ?></option>
      <?php } ?>
    </select>
  </form>
  <?php } ?>

  <h5><?php echo html_escape(_l('se_credentials_store')); ?></h5>
  <?php se_ui_kv([
      _l('se_credentials_store_exists')  => $store['exists'] ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('warning', _l('se_no')),
      _l('se_credentials_store_mode')    => $store['mode'] === null
          ? se_ui_badge('unknown', '—')
          : ($store['mode_ok'] ? se_ui_badge('ok', $store['mode']) : se_ui_badge('error', $store['mode'])),
      _l('se_credentials_outside_docroot') => $store['outside_docroot'] ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('error', _l('se_no')),
      _l('se_credentials_path_configured') => $store['configured_path'] ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('warning', _l('se_no')),
      // The RESOLVED absolute path — configuration, never a secret — so the
      // owner acts on the real path, not a hard-coded guess.
      _l('se_credentials_store_path')    => '<code>' . html_escape((string) ($store['dir'] ?? '—')) . '</code>',
  ], true); ?>
  <p class="text-muted"><small><i class="fa fa-terminal"></i>
    <?php echo html_escape(_l('se_credentials_diag_hint')); ?>
    <code>php modules/se_core/tests/secret_diag.php</code></small></p>
</div></div></div></div>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <h5><?php echo html_escape(_l('se_credentials_providers')); ?></h5>
  <div class="table-responsive">
    <table class="table table-striped">
      <thead><tr>
        <th><?php echo html_escape(_l('se_credentials_provider')); ?></th>
        <th><?php echo html_escape(_l('se_brand')); ?></th>
        <th><?php echo html_escape(_l('se_credentials_expected_file')); ?></th>
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
          <td><code><?php echo html_escape((string) ($p['expected_file'] ?? $p['provider'])); ?></code></td>
          <td><?php
                if (!empty($p['inherited_from'])) {
                    echo se_ui_badge('ok', _l('se_credentials_inherited'));
                } elseif ($p['configured']) {
                    echo se_ui_badge('ok', _l('se_yes'));
                } else {
                    echo se_ui_badge('unknown', _l('se_no'));
                }
              ?></td>
          <td><?php echo (!empty($p['inherited_from']) || $p['readable']) ? se_ui_badge('ok', _l('se_yes')) : se_ui_badge('unknown', _l('se_no')); ?></td>
          <td><?php echo $p['mode'] === null
                ? (!empty($p['inherited_from']) ? '<span class="text-muted">' . html_escape(_l('se_credentials_inherited')) . '</span>' : '<span class="text-muted">—</span>')
                : ($p['mode_ok'] ? se_ui_badge('ok', $p['mode']) : se_ui_badge('error', $p['mode'])); ?></td>
          <td><small><?php echo html_escape((string) ($p['last_auth_at'] ?: '—')); ?></small></td>
          <td><small class="text-muted"><?php echo html_escape((string) ($p['last_error'] ?: '—')); ?></small></td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
</div></div></div></div>

<?php
// Reusable per-provider progress table renderer.
$renderProgress = function ($rows) {
    echo '<div class="table-responsive"><table class="table table-striped"><thead><tr>'
       . '<th>' . html_escape(_l('se_credentials_provider')) . '</th>'
       . '<th>' . html_escape(_l('se_status')) . '</th>'
       . '<th>' . html_escape(_l('se_credentials_detail')) . '</th>'
       . '<th>' . html_escape(_l('se_credentials_enabled')) . '</th></tr></thead><tbody>';
    foreach ($rows as $pr) {
        $badge = $pr['state'] === 'complete' ? se_ui_badge('ok', _l('se_credentials_complete'))
               : ($pr['state'] === 'partial' ? se_ui_badge('warning', _l('se_credentials_partial'))
               : se_ui_badge('error', _l('se_credentials_missing_state')));
        $enabled = $pr['enabled'] === null ? '<span class="text-muted">—</span>'
            : (($pr['enabled'] ? se_ui_badge('ok', _l('se_enabled')) : se_ui_badge('disabled', _l('se_disabled')))
               . ' <small class="text-muted">' . html_escape((string) $pr['enabled_label']) . '</small>');
        echo '<tr><td><strong>' . html_escape($pr['label']) . '</strong></td>'
           . '<td>' . $badge . '</td>'
           . '<td><small>' . html_escape($pr['detail']) . '</small></td>'
           . '<td>' . $enabled . '</td></tr>';
    }
    echo '</tbody></table></div>';
};
?>
<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <h5><?php echo html_escape(_l('se_credentials_progress')); ?></h5>
  <p class="text-muted"><small><?php echo html_escape(_l('se_credentials_progress_hint')); ?></small></p>
  <?php if (!empty($progress_all)) {
      echo se_all_brands_readonly_notice();
      foreach ($progress_all as $blockRow) {
          echo '<h5 class="mtop15">' . se_ui_brand_badge((int) $blockRow['brand_id']) . ' '
             . html_escape((string) $blockRow['brand_name']) . '</h5>';
          $renderProgress($blockRow['rows']);
      }
  } else {
      $renderProgress($progress);
  } ?>
  <p class="text-muted"><small><i class="fa fa-info-circle"></i> <?php echo html_escape(_l('se_credentials_progress_note')); ?></small></p>
</div></div></div></div>

</div></div>
<?php init_tail(); ?></body></html>
