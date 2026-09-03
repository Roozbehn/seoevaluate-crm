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
        <div class="checkbox checkbox-primary"><input type="checkbox" id="cb_enabled" name="enabled" value="1" <?php echo $v['enabled'] ? 'checked' : ''; ?> /><label for="cb_enabled"><?php echo html_escape(_l('se_journey_flag_enabled')); ?></label></div>
        <div class="checkbox checkbox-primary"><input type="checkbox" id="cb_sandbox" name="sandbox" value="1" <?php echo $v['sandbox'] ? 'checked' : ''; ?> /><label for="cb_sandbox"><strong><?php echo html_escape(_l('se_journey_flag_sandbox')); ?></strong></label></div>
        <div class="form-group"><label><?php echo html_escape(_l('se_journey_test_recipients')); ?></label><input class="form-control" name="test_recipients" value="<?php echo html_escape($v['test_recipients']); ?>" placeholder="9053…, 9054…" /></div>
        <div class="checkbox checkbox-primary"><input type="checkbox" id="cb_interactive" name="interactive" value="1" <?php echo $v['interactive'] ? 'checked' : ''; ?> /><label for="cb_interactive"><?php echo html_escape(_l('se_journey_flag_interactive')); ?></label></div>
        <div class="checkbox checkbox-primary"><input type="checkbox" id="cb_auto_organic" name="auto_organic" value="1" <?php echo $v['auto_organic'] ? 'checked' : ''; ?> /><label for="cb_auto_organic"><?php echo html_escape(_l('se_journey_flag_auto_organic')); ?></label></div>
        <div class="checkbox checkbox-primary"><input type="checkbox" id="cb_auto_website" name="auto_website" value="1" <?php echo !empty($v['auto_website']) ? 'checked' : ''; ?> /><label for="cb_auto_website"><?php echo html_escape(_l('se_journey_flag_auto_website')); ?></label><br /><small class="text-muted"><?php echo html_escape(_l('se_journey_flag_auto_website_hint')); ?></small></div>
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
        <div class="checkbox checkbox-primary"><input type="checkbox" id="cb_technical_fields" name="technical_fields" value="1" <?php echo $v['technical_fields'] ? 'checked' : ''; ?> /><label for="cb_technical_fields"><?php echo html_escape(_l('se_journey_flag_technical')); ?></label></div>
        <div class="row">
          <div class="col-sm-6"><div class="form-group"><label><?php echo html_escape(_l('se_journey_media_storage')); ?></label>
            <select name="media_storage" class="form-control"><?php foreach (['auto', 'r2', 'local'] as $p) { ?><option value="<?php echo $p; ?>"<?php echo $v['media_storage'] === $p ? ' selected' : ''; ?>><?php echo html_escape(_l('se_journey_media_storage_' . $p)); ?></option><?php } ?></select>
            <small class="text-muted"><?php echo html_escape(_l('se_journey_media_storage_now')); ?>: <strong><?php echo html_escape($v['media_storage_status']['driver']); ?></strong><?php if (!$v['media_storage_status']['r2_ready']) { echo ' · ' . html_escape(_l('se_journey_media_r2_not_ready')); } ?></small></div></div>
          <div class="col-sm-6"><div class="checkbox checkbox-primary" style="margin-top:28px"><input type="checkbox" id="cb_purge_inbox_copy" name="purge_inbox_copy" value="1" <?php echo $v['purge_inbox_copy'] ? 'checked' : ''; ?> /><label for="cb_purge_inbox_copy"><?php echo html_escape(_l('se_journey_flag_purge_inbox')); ?></label></div></div>
        </div>
        <hr />
        <h5><?php echo html_escape(_l('se_journey_lead_sync_settings')); ?></h5>
        <p class="text-muted"><small><?php echo html_escape(_l('se_journey_lead_sync_note')); ?></small></p>
        <div class="checkbox checkbox-primary"><input type="checkbox" id="cb_lead_sync" name="lead_sync" value="1" <?php echo $v['lead_sync'] ? 'checked' : ''; ?> /><label for="cb_lead_sync"><?php echo html_escape(_l('se_journey_flag_lead_sync')); ?></label></div>
        <div class="checkbox checkbox-primary"><input type="checkbox" id="cb_lead_sync_status" name="lead_sync_status" value="1" <?php echo $v['lead_sync_status'] ? 'checked' : ''; ?> /><label for="cb_lead_sync_status"><?php echo html_escape(_l('se_journey_flag_lead_sync_status')); ?></label></div>
        <hr />
        <h5><?php echo html_escape(_l('se_journey_booking_settings')); ?></h5>
        <p class="text-muted"><small><?php echo html_escape(_l('se_journey_booking_note')); ?></small></p>
        <?php $bk = $v['booking']; ?>
        <div class="row">
          <div class="col-sm-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_booking_staff')); ?></label>
            <select name="booking_staff" class="form-control"><option value="0"><?php echo html_escape(_l('se_journey_booking_staff_auto')); ?></option>
              <?php foreach ($v['booking_staff_options'] as $s) { ?><option value="<?php echo (int) $s['staffid']; ?>"<?php echo (int) $bk['staff_id'] === (int) $s['staffid'] ? ' selected' : ''; ?>><?php echo html_escape(trim($s['firstname'] . ' ' . $s['lastname'])); ?></option><?php } ?>
            </select></div></div>
          <div class="col-sm-2"><div class="form-group"><label><?php echo html_escape(_l('se_journey_booking_slot')); ?></label><input type="number" class="form-control" name="booking_slot" value="<?php echo (int) $bk['slot_minutes']; ?>" min="15" max="180" step="5" /></div></div>
          <div class="col-sm-3"><div class="form-group"><label><?php echo html_escape(_l('se_journey_booking_horizon')); ?></label><input type="number" class="form-control" name="booking_horizon" value="<?php echo (int) $bk['days_ahead']; ?>" min="1" max="60" /></div></div>
          <div class="col-sm-3"><div class="form-group"><label><?php echo html_escape(_l('se_journey_booking_notice')); ?></label><input type="number" class="form-control" name="booking_notice" value="<?php echo (int) $bk['notice_hours']; ?>" min="0" max="168" /></div></div>
        </div>
        <div class="row">
          <div class="col-sm-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_booking_hours')); ?></label><input class="form-control" name="booking_hours" value="<?php echo html_escape($bk['hours']); ?>" placeholder="10:00-18:00" /></div></div>
          <div class="col-sm-8"><div class="form-group"><label><?php echo html_escape(_l('se_journey_booking_days')); ?></label><div>
            <?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'] as $dn => $dl) { ?>
              <div class="checkbox checkbox-primary checkbox-inline"><input type="checkbox" id="cb_bday_<?php echo $dn; ?>" name="booking_days[]" value="<?php echo $dn; ?>" <?php echo in_array($dn, $bk['days'], true) ? 'checked' : ''; ?> /><label for="cb_bday_<?php echo $dn; ?>"><?php echo html_escape(_l('se_journey_day_' . $dn)); ?></label></div>
            <?php } ?>
          </div></div></div>
        </div>
        <div class="form-group"><label><?php echo html_escape(_l('se_journey_booking_location')); ?></label><input class="form-control" name="booking_location" value="<?php echo html_escape($bk['location']); ?>" maxlength="191" /></div>
        <button class="btn btn-primary" type="submit"><?php echo html_escape(_l('submit')); ?></button>
      <?php echo form_close(); ?>
    </div></div>
    <?php } ?>

    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_journey_clinical_settings')); ?></h5>
      <p class="text-muted"><small><?php echo html_escape(_l('se_journey_clinical_note')); ?></small></p>
      <?php echo form_open(admin_url('se_journey/se_journey/save_settings')); ?>
        <input type="hidden" name="section" value="clinical" /><input type="hidden" name="brand" value="<?php echo (int) $brand; ?>" />
        <div class="checkbox checkbox-primary"><input type="checkbox" id="cb_preop_text_approved" name="preop_text_approved" value="1" <?php echo $v['preop_text_approved'] ? 'checked' : ''; ?> /><label for="cb_preop_text_approved"><?php echo html_escape(_l('se_journey_preop_approved')); ?></label></div>
        <div class="form-group"><label><?php echo html_escape(_l('se_journey_preop_url')); ?></label><input class="form-control" name="preop_info_url" value="<?php echo html_escape($v['preop_info_url']); ?>" placeholder="https://…" /></div>
        <div class="checkbox checkbox-primary"><input type="checkbox" id="cb_consultation_info_approved" name="consultation_info_approved" value="1" <?php echo $v['consultation_info_approved'] ? 'checked' : ''; ?> /><label for="cb_consultation_info_approved"><?php echo html_escape(_l('se_journey_consultation_info_approved')); ?></label></div>
        <p class="text-muted"><small><?php echo html_escape(_l('se_journey_consultation_info_note')); ?></small></p>
        <div class="checkbox checkbox-primary"><input type="checkbox" id="cb_ask_infectious" name="ask_infectious" value="1" <?php echo $v['ask_infectious'] ? 'checked' : ''; ?> /><label for="cb_ask_infectious"><?php echo html_escape(_l('se_journey_ask_infectious')); ?></label></div>
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
        <div class="checkbox checkbox-primary"><input type="checkbox" id="cb_consent_bypass" name="consent_bypass" value="1" <?php echo $v['consent_bypass'] ? 'checked' : ''; ?> /><label for="cb_consent_bypass"><?php echo html_escape(_l('se_journey_bypass_enable')); ?></label></div>
        <div class="form-group"><input class="form-control" name="consent_bypass_reason" value="<?php echo html_escape($v['consent_bypass_reason']); ?>" placeholder="<?php echo html_escape(_l('se_journey_bypass_reason')); ?>" /></div>
        <button class="btn btn-warning" type="submit"><?php echo html_escape(_l('submit')); ?></button>
      <?php echo form_close(); ?>
    </div></div>
    <?php } ?>
  </div>
</div>
</div></div>
<?php init_tail(); ?></body></html>
