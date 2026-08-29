<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $pages = (int) ceil($total / $per); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
<div class="panel_s"><div class="panel-body">
  <div class="row"><div class="col-md-6"><h4 class="no-margin"><?php echo _l('se_patients'); ?></h4></div>
    <div class="col-md-6 text-right">
      <?php if (staff_can('create','se_patients')) { ?><a href="<?php echo admin_url('se_core/se_patients/create'); ?>" class="btn btn-primary btn-sm"><?php echo _l('se_patient_new'); ?></a><?php } ?>
    </div></div>
  <hr />
  <form method="get" action="<?php echo admin_url('se_core/se_patients'); ?>" class="form-inline" style="margin-bottom:12px">
    <input type="text" name="search" value="<?php echo html_escape($search); ?>" class="form-control input-sm" placeholder="<?php echo _l('se_patient_search'); ?>" />
    <label><input type="checkbox" name="archived" value="1" <?php echo $archived ? 'checked' : ''; ?> /> <?php echo _l('se_patient_show_archived'); ?></label>
    <button class="btn btn-default btn-sm"><?php echo _l('se_patient_search'); ?></button>
  </form>
  <div class="table-responsive"><table class="table table-striped">
    <thead><tr><th>#</th><th><?php echo _l('se_patient_brand'); ?></th><th><?php echo _l('se_patient_language'); ?></th><th><?php echo _l('se_patient_nationality'); ?></th><th><?php echo _l('se_patient_state'); ?></th><th></th></tr></thead>
    <tbody>
    <?php if (empty($patients)) { ?><tr><td colspan="6" class="text-muted"><?php echo _l('se_patient_none'); ?></td></tr><?php } ?>
    <?php foreach ($patients as $p) { ?>
      <tr>
        <td><?php echo (int) $p['id']; ?></td>
        <td><?php echo (int) $p['brand_id']; ?></td>
        <td><?php echo html_escape($p['preferred_language']); ?></td>
        <td><?php echo html_escape($p['nationality']); ?></td>
        <td><span class="label label-<?php echo $p['retention_state']==='archived'?'default':'success'; ?>"><?php echo html_escape($p['retention_state']); ?></span></td>
        <td><a href="<?php echo admin_url('se_core/se_patients/view/' . (int) $p['id']); ?>"><?php echo _l('se_patient_view'); ?></a></td>
      </tr>
    <?php } ?>
    </tbody>
  </table></div>
  <p class="text-muted"><?php echo _l('se_patient_total'); ?>: <?php echo (int) $total; ?></p>
  <?php if ($pages > 1) { for ($i=1;$i<=$pages;$i++){ ?>
     <a class="btn btn-<?php echo $i===$page?'primary':'default'; ?> btn-sm" href="<?php echo admin_url('se_core/se_patients?page='.$i.'&search='.urlencode($search).($archived?'&archived=1':'')); ?>"><?php echo $i; ?></a>
  <?php } } ?>
</div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
