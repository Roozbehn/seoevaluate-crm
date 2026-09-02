<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">
<?php se_ui_header(_l('se_journey_templates'), [
    ['href' => admin_url('se_journey/se_journey/index'), 'label' => _l('se_back'), 'icon' => 'fa-arrow-left'],
    ['href' => admin_url('se_whatsapp/se_whatsapp/readiness?brand=' . (int) $brand), 'label' => _l('se_wa_readiness'), 'icon' => 'fa-whatsapp'],
], _l('se_journey_templates_subtitle')); ?>

<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <?php echo form_open(admin_url('se_journey/se_journey/template_action/sync'), ['class' => 'pull-right']); ?>
    <input type="hidden" name="brand" value="<?php echo (int) $brand; ?>" />
    <button class="btn btn-default btn-sm" type="submit"><i class="fa fa-refresh"></i> <?php echo html_escape(_l('se_journey_sync_meta')); ?></button>
  <?php echo form_close(); ?>
  <p class="text-muted"><?php echo html_escape(_l('se_journey_templates_note')); ?></p>
  <?php if (!$can_submit) { ?><div class="alert alert-warning"><i class="fa fa-lock"></i> <?php echo html_escape(_l('se_journey_templates_gated')); ?></div><?php } ?>
  <div class="table-responsive"><table class="table table-hover">
    <thead><tr>
      <th><?php echo html_escape(_l('se_journey_logical_name')); ?></th><th><?php echo html_escape(_l('se_journey_meta_status')); ?></th>
      <th class="hidden-xs"><?php echo html_escape(_l('se_journey_category')); ?></th><th class="hidden-xs"><?php echo html_escape(_l('se_journey_meta_id')); ?></th>
      <th class="hidden-xs"><?php echo html_escape(_l('se_journey_last_sync')); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r) {
        $inMirror = in_array($r['meta_name'], $mirror, true);
        $tone = $r['approval_status'] === 'approved' ? ($inMirror ? 'ok' : 'warning') : (in_array($r['approval_status'], ['rejected', 'disabled', 'submit_failed'], true) ? 'failed' : 'pending'); ?>
      <tr>
        <td><code><?php echo html_escape($r['logical_name']); ?></code><br /><small class="text-muted"><?php echo html_escape($r['language']); ?> · v<?php echo (int) $r['content_version']; ?> · <?php echo html_escape($r['meta_name']); ?></small>
            <details><summary><small><?php echo html_escape(_l('se_journey_preview')); ?></small></summary><pre style="white-space:pre-wrap;font-size:11px"><?php echo html_escape($r['body']); ?></pre><small class="text-muted"><?php echo html_escape(_l('se_journey_samples')); ?>: <?php echo html_escape(implode(' | ', json_decode((string) $r['placeholders_json'], true) ?: [])); ?></small></details>
            <div class="mtop5">          <?php if ($can_submit && in_array($r['approval_status'], ['not_submitted', 'submit_failed', 'rejected'], true)) { ?>
            <?php echo form_open(admin_url('se_journey/se_journey/template_action/submit'), ['style' => 'display:inline-block']); ?>
              <input type="hidden" name="brand" value="<?php echo (int) $brand; ?>" /><input type="hidden" name="logical" value="<?php echo html_escape($r['logical_name']); ?>" />
              <button class="btn btn-primary btn-xs" type="submit"><i class="fa fa-upload"></i> <?php echo html_escape(_l('se_journey_submit_meta')); ?></button>
            <?php echo form_close(); ?>
          <?php } ?>
          <?php if ($r['approval_status'] === 'approved' && $inMirror && $test_recipients) { ?>
            <?php echo form_open(admin_url('se_journey/se_journey/template_action/test_send'), ['class' => 'form-inline mtop5']); ?>
              <input type="hidden" name="brand" value="<?php echo (int) $brand; ?>" /><input type="hidden" name="logical" value="<?php echo html_escape($r['logical_name']); ?>" />
              <select name="to" class="form-control input-sm"><?php foreach ($test_recipients as $t) { ?><option value="<?php echo html_escape($t); ?>">••••<?php echo html_escape(substr($t, -4)); ?></option><?php } ?></select>
              <input type="text" name="vars" class="form-control input-sm" placeholder='["Ad","https://…"]' style="width:150px" />
              <button class="btn btn-default btn-xs" type="submit"><?php echo html_escape(_l('se_journey_test_send')); ?></button>
            <?php echo form_close(); ?>
          <?php } ?>
            </div></td>
        <td><?php echo se_ui_badge($tone, _l('se_journey_tpl_' . $r['approval_status'])); ?>
            <?php if ($r['approval_status'] === 'approved' && !$inMirror) { ?><br /><small class="text-warning"><?php echo html_escape(_l('se_journey_not_in_mirror')); ?></small><?php } ?>
            <?php if ($r['rejection_reason']) { ?><br /><small class="text-danger"><?php echo html_escape($r['rejection_reason']); ?></small><?php } ?></td>
        <td class="hidden-xs"><?php echo html_escape($r['category_requested']); ?><?php echo $r['category_meta'] && $r['category_meta'] !== $r['category_requested'] ? ' → <strong>' . html_escape($r['category_meta']) . '</strong>' : ''; ?></td>
        <td class="hidden-xs"><small><?php echo html_escape($r['meta_template_id'] ?: '—'); ?></small></td>
        <td class="hidden-xs"><small><?php echo html_escape($r['last_sync_at'] ?: '—'); ?></small></td>
      </tr>
    <?php } ?>
    </tbody>
  </table></div>
  <p class="text-muted"><small><?php echo html_escape(_l('se_journey_flow_note')); ?></small></p>
</div></div></div></div>
</div></div>
<?php init_tail(); ?></body></html>
