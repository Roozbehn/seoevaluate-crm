<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-10 col-md-offset-1">
<div class="panel_s"><div class="panel-body">
  <a href="<?php echo admin_url('se_core/se_patients'); ?>">&laquo; <?php echo _l('se_patients'); ?></a>
  <h4><?php echo _l('se_patient'); ?> #<?php echo (int) $patient->id; ?>
    <span class="label label-<?php echo $patient->retention_state==='archived'?'default':'success'; ?>"><?php echo html_escape($patient->retention_state); ?></span>
    <?php if (staff_can('edit','se_patients') && $patient->retention_state!=='archived') { ?><a class="btn btn-default btn-sm pull-right" href="<?php echo admin_url('se_core/se_patients/edit/'.(int)$patient->id); ?>"><?php echo _l('edit'); ?></a><?php } ?>
  </h4>
  <hr />
  <p><strong><?php echo _l('se_patient_brand'); ?>:</strong> <?php echo (int) $patient->brand_id; ?>
   &middot; <strong><?php echo _l('se_patient_language'); ?>:</strong> <?php echo html_escape($patient->preferred_language); ?>
   &middot; <strong><?php echo _l('se_patient_nationality'); ?>:</strong> <?php echo html_escape($patient->nationality); ?>
   <?php /* Passport is never rendered in plaintext. When collection is off the
            column is empty by policy; when on it is ciphertext, so only a mask
            is shown and reading the real value is a deliberate, logged action. */ ?>
   &middot; <strong><?php echo _l('se_patient_passport'); ?>:</strong>
   <?php echo ($patient->passport_no === null || $patient->passport_no === '')
        ? html_escape(_l('se_patient_passport_hidden'))
        : html_escape(se_patient_mask_passport('stored')); ?></p>
  <h5><?php echo _l('se_patient_links'); ?></h5>
  <?php if (!empty($links['lead'])) { ?><p><?php echo _l('se_appt_lead'); ?>: <a href="<?php echo admin_url('leads/index/'.(int)$links['lead']->id); ?>"><?php echo html_escape($links['lead']->name); ?></a></p><?php } ?>
  <?php if (!empty($links['client'])) { ?><p>Customer: <a href="<?php echo admin_url('clients/client/'.(int)$links['client']->userid); ?>"><?php echo html_escape($links['client']->company); ?></a></p><?php } ?>
  <?php if (!empty($links['appointments'])) { ?><ul><?php foreach ($links['appointments'] as $a) { ?><li><?php echo html_escape($a['start_at']); ?> — <?php echo html_escape($a['title']); ?> (<?php echo html_escape($a['status']); ?>)</li><?php } ?></ul><?php } ?>
  <h5><?php echo _l('se_patient_consent_history'); ?></h5>
  <table class="table"><tbody><?php foreach ($consent as $c) { ?><tr><td><?php echo html_escape($c['purpose']); ?></td><td><?php echo html_escape($c['state']); ?></td><td><?php echo html_escape($c['consent_text_version']); ?></td><td><?php echo html_escape($c['source']); ?></td><td><?php echo html_escape($c['consent_at']); ?></td></tr><?php } ?><?php if(empty($consent)){ echo '<tr><td class="text-muted">'._l('se_patient_none').'</td></tr>'; } ?></tbody></table>
  <h5><?php echo _l('se_patient_audit_history'); ?></h5>
  <table class="table"><tbody><?php foreach ($audit as $a) { ?><tr><td><?php echo html_escape($a['action']); ?></td><td>staff <?php echo (int) $a['staff_id']; ?></td><td><?php echo html_escape($a['accessed_at']); ?></td></tr><?php } ?></tbody></table>
</div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
