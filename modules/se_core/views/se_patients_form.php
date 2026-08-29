<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $p = $patient; ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-8 col-md-offset-2">
<div class="panel_s"><div class="panel-body">
  <h4><?php echo $p ? _l('edit') : _l('se_patient_new'); ?></h4><hr />
  <?php echo form_open(admin_url('se_core/se_patients/save/' . ($p ? (int) $p->id : ''))); ?>
    <?php if (!$p) { ?>
      <div class="form-group"><label><?php echo _l('se_patient_brand'); ?> (brand_id)</label><input type="number" name="brand_id" class="form-control" value="" required /></div>
      <div class="form-group"><label>lead_id</label><input type="number" name="lead_id" class="form-control" value="0" /></div>
      <div class="form-group"><label>client_id</label><input type="number" name="client_id" class="form-control" value="0" /></div>
    <?php } else { ?>
      <input type="hidden" name="lead_id" value="<?php echo (int) $p->lead_id; ?>" />
      <input type="hidden" name="client_id" value="<?php echo (int) $p->client_id; ?>" />
    <?php } ?>
    <div class="form-group"><label><?php echo _l('se_patient_language'); ?></label><input type="text" name="preferred_language" maxlength="8" class="form-control" value="<?php echo $p ? html_escape($p->preferred_language) : ''; ?>" /></div>
    <div class="form-group"><label><?php echo _l('se_patient_nationality'); ?></label><input type="text" name="nationality" maxlength="64" class="form-control" value="<?php echo $p ? html_escape($p->nationality) : ''; ?>" /></div>
    <div class="form-group"><label><?php echo _l('se_patient_passport'); ?> <small class="text-muted">(<?php echo _l('se_patient_optional'); ?>)</small></label><input type="text" name="passport_no" maxlength="64" class="form-control" value="<?php echo $p ? html_escape($p->passport_no) : ''; ?>" /></div>
    <button class="btn btn-primary"><?php echo _l('submit'); ?></button>
    <?php if ($p && staff_can('delete','se_patients') && $p->retention_state!=='archived') { ?>
      <a class="btn btn-danger pull-right" href="<?php echo admin_url('se_core/se_patients/archive/'.(int)$p->id); ?>" onclick="return confirm('<?php echo _l('se_patient_archive_confirm'); ?>');"><?php echo _l('se_patient_archive'); ?></a>
    <?php } ?>
  <?php echo form_close(); ?>
</div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
