<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">

<?php se_ui_header(_l('se_consent_settings'), [], _l('se_consent_subtitle')); ?>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <form method="get" action="<?php echo admin_url('se_core/se_consent'); ?>" class="form-inline mbot15">
    <label for="brandsel"><?php echo html_escape(_l('se_brand')); ?></label>
    <select id="brandsel" name="brand" class="form-control mleft5" onchange="this.form.submit()">
      <option value="0"<?php echo $brand === 0 ? ' selected' : ''; ?>><?php echo html_escape(_l('se_consent_global_default')); ?></option>
      <?php foreach ($brands as $b) { ?>
        <option value="<?php echo (int) $b['id']; ?>"<?php echo $brand === (int) $b['id'] ? ' selected' : ''; ?>>
          <?php echo html_escape($b['name']); ?>
        </option>
      <?php } ?>
    </select>
  </form>

  <?php $anyConfigured = in_array(true, $configured, true); ?>
  <?php if (!$anyConfigured) { ?>
    <div class="alert alert-warning">
      <i class="fa fa-exclamation-triangle"></i>
      <strong><?php echo html_escape(_l('se_consent_none_title')); ?></strong><br />
      <?php echo html_escape(_l('se_consent_none_body')); ?>
    </div>
  <?php } ?>

  <div class="alert alert-info">
    <i class="fa fa-info-circle"></i> <?php echo html_escape(_l('se_consent_legal_note')); ?>
  </div>
</div></div></div></div>

<?php echo form_open(admin_url('se_core/se_consent/save')); ?>
<input type="hidden" name="brand_id" value="<?php echo (int) $brand; ?>" />

<div class="row"><div class="col-md-7">
  <div class="panel_s"><div class="panel-body">
    <div class="form-group">
      <label for="version" class="control-label"><?php echo html_escape(_l('se_consent_version')); ?> <span class="text-danger">*</span></label>
      <input type="text" class="form-control" id="version" name="version" maxlength="32"
             value="<?php echo html_escape($config['version']); ?>"
             placeholder="kvkk-2026-01" required pattern="[A-Za-z0-9._-]{1,32}" />
      <small class="text-muted"><?php echo html_escape(_l('se_consent_version_hint')); ?></small>
    </div>

    <?php foreach ($purposes as $purpose) {
        $p = $config['purposes'][$purpose] ?? ['enabled' => 0, 'text' => []]; ?>
      <hr />
      <h5>
        <?php echo html_escape(_l('se_consent_purpose_' . $purpose)); ?>
        <?php echo $configured[$purpose] ? se_ui_badge('ok', _l('se_consent_ready')) : se_ui_badge('warning', _l('se_consent_not_ready')); ?>
      </h5>

      <div class="checkbox">
        <label>
          <input type="checkbox" name="purposes[<?php echo $purpose; ?>][enabled]" value="1"
                 <?php echo !empty($p['enabled']) ? 'checked' : ''; ?> />
          <?php echo html_escape(_l('se_consent_enable_purpose')); ?>
        </label>
      </div>

      <?php foreach ($languages as $code => $label) { ?>
        <div class="form-group">
          <label for="t_<?php echo $purpose . '_' . $code; ?>" class="control-label">
            <?php echo html_escape(_l('se_consent_text_label') . ' — ' . $label); ?>
          </label>
          <textarea class="form-control" rows="3" maxlength="2000"
                    id="t_<?php echo $purpose . '_' . $code; ?>"
                    name="purposes[<?php echo $purpose; ?>][text][<?php echo $code; ?>]"
                    ><?php echo html_escape($p['text'][$code] ?? ''); ?></textarea>
        </div>
      <?php } ?>
    <?php } ?>

    <button type="submit" class="btn btn-primary"><?php echo html_escape(_l('submit')); ?></button>
  </div></div>
</div>

<div class="col-md-5">
  <div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_consent_preview')); ?></h5>
    <p class="text-muted"><small><?php echo html_escape(_l('se_consent_preview_note')); ?></small></p>

    <?php $shown = false; ?>
    <?php foreach ($purposes as $purpose) {
        if (empty($configured[$purpose])) { continue; }
        $shown = true; ?>
      <?php foreach ($languages as $code => $label) { ?>
        <div class="mbot15" style="padding:10px;border:1px solid rgba(128,128,128,.3);border-radius:4px">
          <small class="text-muted"><?php echo html_escape($label); ?></small><br />
          <label style="font-weight:normal">
            <?php /* Rendered EXACTLY as the visitor sees it: unchecked, and
                     with no mechanism anywhere to pre-check it. */ ?>
            <input type="checkbox" disabled />&nbsp;<?php echo html_escape(se_consent_text($brand, $purpose, $code)); ?>
          </label>
        </div>
      <?php } ?>
    <?php } ?>

    <?php if (!$shown) { se_ui_empty(_l('se_consent_preview_empty')); } ?>
  </div></div>

  <div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_consent_audit')); ?></h5>
    <?php se_ui_kv([
        _l('se_consent_version')       => $config['version'] !== '' ? $config['version'] : '—',
        _l('se_consent_updated_at')    => $config['updated_at'] ?: '—',
        _l('se_consent_updated_by')    => $config['updated_by'] ? ('staff #' . (int) $config['updated_by']) : '—',
        _l('se_consent_tracking_gate') => se_consent_tracking_allowed($brand)
            ? se_ui_badge('ok', _l('se_consent_tracking_on'))
            : se_ui_badge('warning', _l('se_consent_tracking_off')),
    ], true); ?>
  </div></div>
</div></div>
<?php echo form_close(); ?>

</div></div>
<?php init_tail(); ?></body></html>
