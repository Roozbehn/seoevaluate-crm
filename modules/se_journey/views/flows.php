<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $r = $readiness; ?>
<div id="wrapper"><div class="content">
<?php se_ui_header(_l('se_journey_flows'), [
    ['href' => admin_url('se_journey/se_journey/index'), 'label' => _l('se_back'), 'icon' => 'fa-arrow-left'],
    ['href' => admin_url('se_journey/se_journey/templates?brand=' . (int) $brand), 'label' => _l('se_journey_templates'), 'icon' => 'fa-file-text-o'],
], _l('se_journey_flows_subtitle')); ?>

<?php if (!$r) { ?>
<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body"><?php se_ui_empty(_l('se_journey_no_brand')); ?></div></div></div></div>
<?php } else { ?>
<div class="row">
  <div class="col-md-5">
    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_journey_flow_readiness')); ?></h5>
      <div class="table-responsive"><table class="table table-condensed"><tbody>
        <tr><td style="width:36px"><?php echo se_ui_badge($r['key_installed'] ? 'ok' : 'failed', $r['key_installed'] ? '✓' : '✗'); ?></td>
            <td><strong><?php echo html_escape(_l('se_journey_flow_ready_key')); ?></strong><br /><small class="text-muted"><?php echo html_escape(_l('se_journey_flow_ready_key_hint')); ?></small></td></tr>
        <tr><td><?php echo se_ui_badge($r['key_registered_at'] !== '' ? 'ok' : 'warning', $r['key_registered_at'] !== '' ? '✓' : '!'); ?></td>
            <td><strong><?php echo html_escape(_l('se_journey_flow_ready_registered')); ?></strong>
                <?php if ($r['key_registered_at'] !== '') { ?><br /><small class="text-muted"><?php echo html_escape($r['key_registered_at']); ?></small><?php } ?>
                <?php if ($key_status) { ?><br /><small class="<?php echo $key_status['ok'] && $key_status['matches'] && $key_status['status'] === 'VALID' ? 'text-success' : 'text-warning'; ?>">Meta: <?php echo html_escape($key_status['ok'] ? ($key_status['status'] ?: '—') . ($key_status['matches'] ? ' · ' . _l('se_journey_flow_key_matches') : ' · ' . _l('se_journey_flow_key_differs')) : $key_status['reason']); ?></small><?php } ?>
                <div class="mtop5">
                  <?php echo form_open(admin_url('se_journey/se_journey/flow_action/register_key'), ['style' => 'display:inline-block']); ?><input type="hidden" name="brand" value="<?php echo (int) $brand; ?>" /><button class="btn btn-default btn-xs" type="submit" <?php echo $r['key_installed'] ? '' : 'disabled'; ?>><i class="fa fa-key"></i> <?php echo html_escape(_l('se_journey_flow_register_key')); ?></button><?php echo form_close(); ?>
                  <a class="btn btn-default btn-xs" href="<?php echo admin_url('se_journey/se_journey/flows?brand=' . (int) $brand . '&check_key=1'); ?>"><i class="fa fa-search"></i> <?php echo html_escape(_l('se_journey_flow_check_key')); ?></a>
                </div></td></tr>
        <tr><td><?php echo se_ui_badge($r['app_id'] !== '' ? 'ok' : 'warning', $r['app_id'] !== '' ? '✓' : '!'); ?></td>
            <td><strong><?php echo html_escape(_l('se_journey_flow_ready_app')); ?></strong><br /><small class="text-muted"><?php echo html_escape(_l('se_journey_flow_ready_app_hint')); ?></small></td></tr>
        <tr><td><?php echo se_ui_badge('pending', 'i'); ?></td>
            <td><strong><?php echo html_escape(_l('se_journey_flow_endpoint')); ?></strong><br /><code><?php echo html_escape($r['endpoint']); ?></code></td></tr>
        <tr><td><?php echo se_ui_badge($r['enabled'] ? 'ok' : 'pending', $r['enabled'] ? '✓' : '–'); ?></td>
            <td><strong><?php echo html_escape(_l('se_journey_flow_ready_enabled')); ?></strong><br /><small class="text-muted"><?php echo html_escape(_l('se_journey_flow_ready_enabled_hint')); ?></small></td></tr>
      </tbody></table></div>

      <?php echo form_open(admin_url('se_journey/se_journey/flow_action/settings')); ?>
        <input type="hidden" name="brand" value="<?php echo (int) $brand; ?>" />
        <div class="checkbox checkbox-primary"><input type="checkbox" id="cb_flows_enabled" name="flows_enabled" value="1" <?php echo $r['enabled'] ? 'checked' : ''; ?> /><label for="cb_flows_enabled"><?php echo html_escape(_l('se_journey_flag_flows')); ?></label></div>
        <div class="form-group"><label><?php echo html_escape(_l('se_journey_flow_app_id')); ?></label><input class="form-control" name="flow_app_id" value="<?php echo html_escape($r['app_id']); ?>" placeholder="1375062474780237" /><small class="text-muted"><?php echo html_escape(_l('se_journey_flow_app_id_hint')); ?></small></div>
        <button class="btn btn-primary btn-sm" type="submit"><?php echo html_escape(_l('submit')); ?></button>
      <?php echo form_close(); ?>
    </div></div>
  </div>

  <div class="col-md-7">
    <?php foreach ($r['flows'] as $kind => $f) { ?>
    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_journey_flow_kind_' . $kind)); ?>
        <?php $tone = strtoupper($f['status']) === 'PUBLISHED' ? 'ok' : ($f['status'] !== '' ? 'warning' : 'pending'); echo se_ui_badge($tone, $f['status'] !== '' ? $f['status'] : _l('se_journey_flow_not_created')); ?>
        <?php if ($f['id'] !== '') { ?><small class="text-muted">· <code><?php echo html_escape($f['name']); ?></code> · id <?php echo html_escape($f['id']); ?></small><?php } ?>
      </h5>
      <?php if ($f['id'] !== '' && $f['json_hash'] !== '' && $f['json_hash'] !== $f['current_hash']) { ?><p class="text-warning"><small><i class="fa fa-exclamation-triangle"></i> <?php echo html_escape(_l('se_journey_flow_json_changed')); ?></small></p><?php } ?>
      <?php if ($f['errors']) { ?>
        <div class="alert alert-warning" style="font-size:12px"><strong><?php echo html_escape(_l('se_journey_flow_validation_errors')); ?>:</strong>
          <ul><?php foreach ($f['errors'] as $e) { ?><li><?php echo html_escape(is_array($e) ? (($e['error'] ?? '') . ' — ' . ($e['message'] ?? json_encode($e))) : (string) $e); ?></li><?php } ?></ul></div>
      <?php } ?>
      <?php if (!$f['ready']['ready']) { ?><p class="text-muted"><small><?php echo html_escape(_l('se_journey_flow_not_ready')); ?>: <?php echo html_escape(_l('se_journey_flow_reason_' . $f['ready']['reason'])); ?></small></p><?php } else { ?><p class="text-success"><small><i class="fa fa-check"></i> <?php echo html_escape(_l('se_journey_flow_in_use')); ?></small></p><?php } ?>
      <div>
        <?php foreach (['create' => ['fa-plus', $f['id'] === ''], 'upload' => ['fa-upload', $f['id'] !== ''], 'publish' => ['fa-paper-plane', $f['id'] !== '' && strtoupper($f['status']) !== 'PUBLISHED'], 'sync' => ['fa-refresh', $f['id'] !== '']] as $act => $cfg) { if (!$cfg[1]) { continue; } ?>
          <?php echo form_open(admin_url('se_journey/se_journey/flow_action/' . $act), ['style' => 'display:inline-block']); ?>
            <input type="hidden" name="brand" value="<?php echo (int) $brand; ?>" /><input type="hidden" name="kind" value="<?php echo html_escape($kind); ?>" />
            <button class="btn btn-<?php echo $act === 'publish' ? 'primary' : 'default'; ?> btn-xs" type="submit" <?php echo $r['key_installed'] ? '' : 'disabled'; ?>><i class="fa <?php echo $cfg[0]; ?>"></i> <?php echo html_escape(_l('se_journey_flow_act_' . $act)); ?></button>
          <?php echo form_close(); ?>
        <?php } ?>
      </div>
      <details class="mtop10"><summary><small><?php echo html_escape(_l('se_journey_flow_json')); ?></small></summary><pre style="white-space:pre-wrap;font-size:11px;max-height:320px;overflow:auto"><?php echo html_escape($json[$kind]); ?></pre></details>
    </div></div>
    <?php } ?>
  </div>
</div>
<?php } ?>
</div></div>
<?php init_tail(); ?></body></html>
