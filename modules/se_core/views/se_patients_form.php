<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $p = $patient; ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-8 col-md-offset-2">
<div class="panel_s"><div class="panel-body">
  <h4><?php echo $p ? _l('edit') : _l('se_patient_new'); ?></h4><hr />
  <?php echo form_open(admin_url('se_core/se_patients/save/' . ($p ? (int) $p->id : ''))); ?>
    <?php if (!$p) { ?>
      <?php /* Scoped selectors, not raw numeric inputs: the form can only offer
               brands and records this staff member may actually link. */ ?>
      <div class="form-group">
        <label><?php echo _l('se_patient_brand'); ?></label>
        <select name="brand_id" class="form-control selectpicker" required>
          <option value=""></option>
          <?php /* One reachable brand (clinic mode): preselect it so the form
                   does not ask a question with a single answer. */ ?>
          <?php foreach ($brands as $b) { ?>
            <option value="<?php echo (int) $b['id']; ?>"<?php echo count($brands) === 1 ? ' selected' : ''; ?>><?php echo html_escape($b['name']); ?></option>
          <?php } ?>
        </select>
      </div>
      <div class="form-group">
        <label><?php echo _l('se_appt_lead'); ?></label>
        <select name="lead_id" class="form-control selectpicker" data-live-search="true">
          <option value="0">&mdash;</option>
          <?php foreach ($leads as $l) { ?>
            <option value="<?php echo (int) $l['id']; ?>"><?php echo html_escape($l['name']); ?> (#<?php echo (int) $l['id']; ?>)</option>
          <?php } ?>
        </select>
      </div>
      <div class="form-group">
        <label><?php echo _l('client'); ?></label>
        <select name="client_id" class="form-control selectpicker" data-live-search="true">
          <option value="0">&mdash;</option>
          <?php foreach ($clients as $c) { ?>
            <option value="<?php echo (int) $c['userid']; ?>"><?php echo html_escape($c['company']); ?> (#<?php echo (int) $c['userid']; ?>)</option>
          <?php } ?>
        </select>
      </div>
    <?php } else { ?>
      <?php /* On edit the brand and links are fixed; the controller replaces any
               posted brand_id with the record's own before validating. */ ?>
      <input type="hidden" name="lead_id" value="<?php echo (int) $p->lead_id; ?>" />
      <input type="hidden" name="client_id" value="<?php echo (int) $p->client_id; ?>" />
    <?php } ?>
    <div class="form-group"><label><?php echo _l('se_patient_language'); ?></label><input type="text" name="preferred_language" maxlength="8" class="form-control" value="<?php echo $p ? html_escape($p->preferred_language) : ''; ?>" /></div>
    <div class="form-group"><label><?php echo _l('se_patient_nationality'); ?></label><input type="text" name="nationality" maxlength="64" class="form-control" value="<?php echo $p ? html_escape($p->nationality) : ''; ?>" /></div>

    <?php if (se_patient_passport_collection_enabled() && se_patient_crypto_available()) { ?>
      <?php /* Passport is write-only: the stored value is never rendered back
               into an input, so it cannot be read off the edit page. */ ?>
      <div class="form-group">
        <label><?php echo _l('se_patient_passport'); ?> <small class="text-muted">(<?php echo _l('se_patient_optional'); ?>)</small></label>
        <input type="text" name="passport_no" maxlength="64" class="form-control" value="" autocomplete="off" />
        <?php if ($p && $p->passport_no !== null && $p->passport_no !== '') { ?>
          <p class="text-muted"><small><?php echo html_escape(se_patient_mask_passport('stored')); ?> &mdash; leave blank to keep the stored value.</small></p>
        <?php } ?>
      </div>
    <?php } ?>

    <button class="btn btn-primary"><?php echo _l('submit'); ?></button>
  <?php echo form_close(); ?>

  <?php /* Archive is a POST with a CSRF token, in its own form. It used to be a
           GET link, so any prefetch or crafted image could archive a record. */ ?>
  <?php if ($p && staff_can('delete', 'se_patients') && $p->retention_state !== 'archived') { ?>
    <?php echo form_open(admin_url('se_core/se_patients/archive/' . (int) $p->id), ['class' => 'pull-right']); ?>
      <button type="submit" class="btn btn-danger"
              onclick="return confirm('<?php echo _l('se_patient_archive_confirm'); ?>');">
        <?php echo _l('se_patient_archive'); ?>
      </button>
    <?php echo form_close(); ?>
  <?php } ?>
</div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
