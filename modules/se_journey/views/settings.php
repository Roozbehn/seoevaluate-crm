<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $v = $values; ?>
<div id="wrapper"><div class="content">
<?php se_ui_header(_l('se_journey_settings'), [['href' => admin_url('se_journey/se_journey/index'), 'label' => _l('se_back'), 'icon' => 'fa-arrow-left']], _l('se_journey_settings_subtitle')); ?>

<div class="row">
  <div class="col-md-5">
    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_journey_readiness')); ?>
        <?php if ($readiness) { echo $readiness['go_live_ready'] ? se_ui_badge('ok', _l('se_journey_go_live_ready')) : se_ui_badge('warning', sprintf(_l('se_journey_readiness_summary'), (int) $readiness['blocking'])); } ?></h5>
      <?php if ($readiness) { ?>
      <div class="table-responsive"><table class="table table-condensed"><tbody>
      <?php foreach ($readiness['items'] as $it) { ?>
        <tr><td style="width:36px"><?php echo se_ui_badge($it['ok'] ? 'ok' : ($it['blocking'] ? 'failed' : 'warning'), $it['ok'] ? '✓' : ($it['blocking'] ? '✗' : '!')); ?></td>
            <td><strong><?php echo html_escape(_l('se_journey_ready_' . $it['key'])); ?></strong><?php if (!$it['ok'] || $it['key'] === 'sandbox') { ?><br /><small class="text-muted"><?php echo html_escape($it['action']); ?></small><?php } ?></td></tr>
      <?php } ?>
      </tbody></table></div>
      <?php } ?>
      <?php if ($health) { ?>
      <h5 class="mtop15"><?php echo html_escape(_l('se_journey_health')); ?></h5>
      <?php se_ui_kv([
          _l('se_journey_health_templates') => html_escape($health['templates']['approved'] . '/' . $health['templates']['total']),
          _l('se_journey_health_blocked') => (int) $health['template_blocked_tasks'],
          _l('se_journey_health_media_failed') => (int) $health['media_fetch_failed'] . ' / ' . (int) $health['media_parked'] . ' parked',
          _l('se_journey_health_errors') => (int) $health['automation_errors'],
          _l('se_journey_health_urgent') => (int) $health['urgent_open'],
          _l('se_journey_health_queue') => html_escape(json_encode($health['wa_queue'])),
          _l('se_journey_health_cron') => html_escape($health['cron_age_seconds'] === null ? '—' : $health['cron_age_seconds'] . 's'),
          _l('se_journey_health_listener') => html_escape($health['listener_last_error'] ?: '—'),
      ], true); ?>
      <?php } ?>
    </div></div>
  </div>

  <div class="col-md-7">
    <?php if ($integration_admin) { ?>
    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_journey_flags')); ?></h5>
      <?php echo form_open(admin_url('se_journey/se_journey/save_settings')); ?>
        <input type="hidden" name="section" value="flags" /><input type="hidden" name="brand" value="<?php echo (int) $brand; ?>" />
        <div class="checkbox"><label><input type="checkbox" name="enabled" value="1" <?php echo $v['enabled'] ? 'checked' : ''; ?> /> <?php echo html_escape(_l('se_journey_flag_enabled')); ?></label></div>
        <div class="checkbox"><label><input type="checkbox" name="sandbox" value="1" <?php echo $v['sandbox'] ? 'checked' : ''; ?> /> <strong><?php echo html_escape(_l('se_journey_flag_sandbox')); ?></strong></label></div>
        <div class="form-group"><label><?php echo html_escape(_l('se_journey_test_recipients')); ?></label><input class="form-control" name="test_recipients" value="<?php echo html_escape($v['test_recipients']); ?>" placeholder="9053…, 9054…" /></div>
        <div class="checkbox"><label><input type="checkbox" name="interactive" value="1" <?php echo $v['interactive'] ? 'checked' : ''; ?> /> <?php echo html_escape(_l('se_journey_flag_interactive')); ?></label></div>
        <div class="checkbox"><label><input type="checkbox" name="auto_organic" value="1" <?php echo $v['auto_organic'] ? 'checked' : ''; ?> /> <?php echo html_escape(_l('se_journey_flag_auto_organic')); ?></label></div>
        <div class="row">
          <div class="col-sm-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_intake_ttl')); ?></label><input type="number" class="form-control" name="intake_ttl_hours" value="<?php echo (int) $v['intake_ttl_hours']; ?>" min="1" max="336" /></div></div>
          <div class="col-sm-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_reminder_hours')); ?></label><input class="form-control" name="reminder_hours" value="<?php echo html_escape($v['reminder_hours']); ?>" /></div></div>
          <div class="col-sm-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_quiet_hours')); ?></label><input class="form-control" name="quiet_hours" value="<?php echo html_escape($v['quiet_hours']); ?>" placeholder="21:00-09:00" /></div></div>
        </div>
        <div class="row">
          <div class="col-sm-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_daily_cap')); ?></label><input type="number" class="form-control" name="daily_cap" value="<?php echo (int) $v['daily_cap']; ?>" min="1" max="20" /></div></div>
          <div class="col-sm-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_urgent_staff')); ?></label><input class="form-control" name="urgent_staff_ids" value="<?php echo html_escape($v['urgent_staff_ids']); ?>" placeholder="1, 900021" /></div></div>
          <div class="col-sm-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_amount_policy')); ?></label><select name="quote_amount_policy" class="form-control"><?php foreach (['hidden', 'range', 'exact'] as $p) { ?><option value="<?php echo $p; ?>"<?php echo $v['quote_amount_policy'] === $p ? ' selected' : ''; ?>><?php echo html_escape(_l('se_journey_amount_policy_' . $p)); ?></option><?php } ?></select></div></div>
        </div>
        <div class="form-group"><label><?php echo html_escape(_l('se_journey_public_base_url')); ?></label><input class="form-control" name="public_base_url" value="<?php echo html_escape($v['public_base_url']); ?>" placeholder="https://crm.roozbeh.com.tr" /></div>
        <div class="checkbox"><label><input type="checkbox" name="technical_fields" value="1" <?php echo $v['technical_fields'] ? 'checked' : ''; ?> /> <?php echo html_escape(_l('se_journey_flag_technical')); ?></label></div>
        <button class="btn btn-primary" type="submit"><?php echo html_escape(_l('submit')); ?></button>
      <?php echo form_close(); ?>
    </div></div>
    <?php } ?>

    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_journey_clinical_settings')); ?></h5>
      <p class="text-muted"><small><?php echo html_escape(_l('se_journey_clinical_note')); ?></small></p>
      <?php echo form_open(admin_url('se_journey/se_journey/save_settings')); ?>
        <input type="hidden" name="section" value="clinical" /><input type="hidden" name="brand" value="<?php echo (int) $brand; ?>" />
        <div class="checkbox"><label><input type="checkbox" name="preop_text_approved" value="1" <?php echo $v['preop_text_approved'] ? 'checked' : ''; ?> /> <?php echo html_escape(_l('se_journey_preop_approved')); ?></label></div>
        <div class="form-group"><label><?php echo html_escape(_l('se_journey_preop_url')); ?></label><input class="form-control" name="preop_info_url" value="<?php echo html_escape($v['preop_info_url']); ?>" placeholder="https://…" /></div>
        <div class="checkbox"><label><input type="checkbox" name="ask_infectious" value="1" <?php echo $v['ask_infectious'] ? 'checked' : ''; ?> /> <?php echo html_escape(_l('se_journey_ask_infectious')); ?></label></div>
        <div class="form-group"><label><?php echo html_escape(_l('se_journey_protocols')); ?></label>
          <textarea class="form-control" name="protocols_json" rows="10" style="font-family:monospace;font-size:11px"><?php echo html_escape($v['protocols_json']); ?></textarea>
          <small class="text-muted"><?php echo html_escape(_l('se_journey_protocols_hint')); ?></small></div>
        <button class="btn btn-primary" type="submit"><?php echo html_escape(_l('submit')); ?></button>
      <?php echo form_close(); ?>
    </div></div>

    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_journey_copy')); ?></h5>
      <p class="text-muted"><small><?php echo html_escape(_l('se_journey_copy_hint')); ?></small></p>
      <?php echo form_open(admin_url('se_journey/se_journey/save_settings')); ?>
        <input type="hidden" name="section" value="copy" /><input type="hidden" name="brand" value="<?php echo (int) $brand; ?>" />
        <div class="form-group"><textarea class="form-control" name="copy_json" rows="8" style="font-family:monospace;font-size:11px" placeholder='{"version":"tr-2026-09-v2","tr":{"welcome":"…"}}'><?php echo html_escape($v['copy_json']); ?></textarea></div>
        <details><summary><small><?php echo html_escape(_l('se_journey_copy_defaults')); ?></small></summary><pre style="white-space:pre-wrap;font-size:11px;max-height:300px;overflow:auto"><?php echo html_escape(json_encode($copy_defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
        <button class="btn btn-default mtop10" type="submit"><?php echo html_escape(_l('submit')); ?></button>
      <?php echo form_close(); ?>
    </div></div>

    <?php if ($is_admin) { ?>
    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_journey_bypass')); ?> <?php echo $v['consent_bypass'] ? se_ui_badge('failed', 'ON') : se_ui_badge('ok', 'OFF'); ?></h5>
      <p class="text-muted"><small><?php echo html_escape(_l('se_journey_bypass_note')); ?></small></p>
      <?php echo form_open(admin_url('se_journey/se_journey/save_settings')); ?>
        <input type="hidden" name="section" value="bypass" /><input type="hidden" name="brand" value="<?php echo (int) $brand; ?>" />
        <div class="checkbox"><label><input type="checkbox" name="consent_bypass" value="1" <?php echo $v['consent_bypass'] ? 'checked' : ''; ?> /> <?php echo html_escape(_l('se_journey_bypass_enable')); ?></label></div>
        <div class="form-group"><input class="form-control" name="consent_bypass_reason" value="<?php echo html_escape($v['consent_bypass_reason']); ?>" placeholder="<?php echo html_escape(_l('se_journey_bypass_reason')); ?>" /></div>
        <button class="btn btn-warning" type="submit"><?php echo html_escape(_l('submit')); ?></button>
      <?php echo form_close(); ?>
    </div></div>
    <?php } ?>
  </div>
</div>
</div></div>
<?php init_tail(); ?></body></html>
