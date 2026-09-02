<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head();
$jid = (int) $j->id;
$act = function ($what) use ($jid) { return admin_url('se_journey/se_journey/action/' . $jid . '/' . $what); };
$tabUrl = function ($t) use ($jid) { return admin_url('se_journey/se_journey/view/' . $jid . '?tab=' . $t); };
$staffName = function ($id) use ($staff) {
    foreach ($staff as $s) { if ((int) $s['staffid'] === (int) $id) { return trim($s['firstname'] . ' ' . $s['lastname']); } }
    return $id ? '#' . (int) $id : '—';
};
$phase = se_journey_ui_phase($j->state);
?>
<div id="wrapper"><div class="content">

<?php se_ui_header(_l('se_journeys') . ' #' . $jid . ' — ' . ($j->display_name ?: ('••••' . substr((string) $j->wa_user_id, -4))), [
    ['href' => admin_url('se_journey/se_journey/index'), 'label' => _l('se_back'), 'icon' => 'fa-arrow-left'],
    ['href' => admin_url('se_whatsapp/se_whatsapp/conversation/' . (int) $j->wa_conversation_id), 'label' => _l('se_whatsapp'), 'icon' => 'fa-whatsapp'],
] + ((int) $j->lead_id > 0 ? [['href' => admin_url('leads/index/' . (int) $j->lead_id), 'label' => _l('se_appt_lead') . ' #' . (int) $j->lead_id, 'icon' => 'fa-user']] : [])); ?>

<?php /* ---- Journey header: state, assignee, next action, due, source, WA, automation ---- */ ?>
<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
  <?php if ((int) $j->urgent === 1) { ?>
    <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> <strong><?php echo html_escape(_l('se_journey_urgent_banner')); ?></strong></div>
  <?php } ?>
  <div class="row">
    <div class="col-sm-3 col-xs-6"><small class="text-muted"><?php echo html_escape(_l('se_journey_state')); ?></small><br />
      <?php echo se_ui_badge(se_journey_ui_state_tone($j->state), _l('se_journey_state_' . $j->state)); ?>
      <br /><small class="text-muted"><?php echo html_escape((string) $j->state_changed_at); ?></small></div>
    <div class="col-sm-3 col-xs-6"><small class="text-muted"><?php echo html_escape(_l('se_journey_assignee')); ?></small><br />
      <?php echo html_escape($staffName($j->assigned_staff)); ?></div>
    <div class="col-sm-3 col-xs-6"><small class="text-muted"><?php echo html_escape(_l('se_journey_next_action')); ?></small><br />
      <?php echo html_escape($j->next_action ?: ($tasks ? $tasks[0]['title'] : '—')); ?>
      <?php if ($j->next_action_due_at) { ?><br /><small class="text-muted"><?php echo html_escape($j->next_action_due_at); ?></small><?php } ?></div>
    <div class="col-sm-3 col-xs-6"><small class="text-muted"><?php echo html_escape(_l('se_journey_source')); ?></small><br />
      <?php echo html_escape(_l('se_journey_source_' . $j->source)); ?> <small class="text-muted">(<?php echo html_escape((string) $j->source_confidence); ?>)</small></div>
  </div>
  <div class="row mtop15">
    <div class="col-sm-3 col-xs-6"><small class="text-muted"><?php echo html_escape(_l('se_journey_automation')); ?></small><br />
      <?php echo se_ui_badge(se_journey_ui_automation_tone($j->automation_state), _l('se_journey_auto_' . $j->automation_state)); ?>
      <?php if ($j->automation_reason) { ?><br /><small class="text-muted"><?php echo html_escape($j->automation_reason); ?></small><?php } ?></div>
    <div class="col-sm-3 col-xs-6"><small class="text-muted"><?php echo html_escape(_l('se_journey_last_wa')); ?></small><br />
      <?php echo html_escape((string) ($j->last_outbound_at ?: '—')); ?>
      <?php if ($j->last_send_block) { ?><br /><span class="label label-danger"><?php echo html_escape($j->last_send_block); ?></span><?php } ?></div>
    <div class="col-sm-3 col-xs-6"><small class="text-muted"><?php echo html_escape(_l('se_journey_consent')); ?></small><br />
      <?php echo se_ui_badge($consent['health_data'] ? 'granted' : 'unknown', 'health'); ?>
      <?php echo se_ui_badge($consent['marketing'] ? 'granted' : 'unknown', 'marketing'); ?>
      <?php echo se_ui_badge($consent['photo_publication'] ? 'granted' : 'unknown', 'publication'); ?>
      <?php if ($consent['version']) { ?><br /><small class="text-muted"><?php echo html_escape($consent['version']); ?></small><?php } ?></div>
    <div class="col-sm-3 col-xs-6"><small class="text-muted"><?php echo html_escape(_l('se_journey_touch')); ?></small><br />
      <small><?php echo html_escape((string) $j->first_touch_at); ?> → <?php echo html_escape((string) $j->latest_touch_at); ?></small></div>
  </div>

  <?php /* Stepper */ ?>
  <div class="mtop15">
    <?php foreach (se_journey_ui_phases() as $i => $p) {
        $on = $p === $phase; ?>
      <span class="label <?php echo $on ? 'label-primary' : 'label-default'; ?>" style="display:inline-block;margin:2px 2px 2px 0;padding:6px 8px"><?php echo ($i + 1) . '. ' . html_escape(_l('se_journey_phase_' . $p)); ?></span>
    <?php } ?>
  </div>

  <?php /* Automation control + quick actions (role gated) */ ?>
  <?php if ($can['edit_review']) { ?>
  <div class="mtop15">
    <?php if ($j->state === 'new_whatsapp_enquiry') { ?>
      <?php echo form_open($act('start'), ['style' => 'display:inline-block']); ?><button class="btn btn-primary btn-sm" type="submit"><i class="fa fa-play"></i> <?php echo html_escape(_l('se_journey_start_welcome')); ?></button><?php echo form_close(); ?>
    <?php } ?>
    <?php if (in_array($j->state, ['welcome_sent', 'privacy_notice_sent', 'consent_pending', 'intake_link_sent', 'intake_started', 'intake_incomplete', 'consent_declined'], true)) { ?>
      <?php echo form_open($act('resend_link'), ['style' => 'display:inline-block']); ?><button class="btn btn-default btn-sm" type="submit"><i class="fa fa-link"></i> <?php echo html_escape(_l('se_journey_resend_link')); ?></button><?php echo form_close(); ?>
    <?php } ?>
    <?php if ($j->automation_state === 'active') { ?>
      <?php echo form_open($act('pause'), ['style' => 'display:inline-block']); ?><input type="hidden" name="reason" value="staff_pause" /><button class="btn btn-default btn-sm" type="submit"><i class="fa fa-pause"></i> <?php echo html_escape(_l('se_journey_pause')); ?></button><?php echo form_close(); ?>
    <?php } elseif ($j->state !== 'opted_out') { ?>
      <?php echo form_open($act('resume'), ['style' => 'display:inline-block']); ?><input type="hidden" name="reason" value="staff_resume" /><button class="btn btn-success btn-sm" type="submit"><i class="fa fa-play"></i> <?php echo html_escape($j->automation_state === 'error' ? _l('se_journey_retry_resume') : _l('se_journey_resume')); ?></button><?php echo form_close(); ?>
    <?php } ?>
    <?php if ($j->state === 'opted_out') { ?>
      <?php echo form_open($act('reactivate'), ['class' => 'form-inline', 'style' => 'display:inline-block']); ?>
        <input type="text" class="form-control input-sm" name="evidence" placeholder="<?php echo html_escape(_l('se_journey_evidence_placeholder')); ?>" required />
        <button class="btn btn-warning btn-sm" type="submit"><?php echo html_escape(_l('se_journey_reactivate')); ?></button>
      <?php echo form_close(); ?>
    <?php } ?>
    <?php echo form_open($act('assign'), ['class' => 'form-inline', 'style' => 'display:inline-block']); ?>
      <select name="staff_id" class="form-control input-sm">
        <option value="0"><?php echo html_escape(_l('se_journey_unassigned')); ?></option>
        <?php foreach ($staff as $s) { ?><option value="<?php echo (int) $s['staffid']; ?>"<?php echo (int) $j->assigned_staff === (int) $s['staffid'] ? ' selected' : ''; ?>><?php echo html_escape(trim($s['firstname'] . ' ' . $s['lastname'])); ?></option><?php } ?>
      </select>
      <button class="btn btn-default btn-sm" type="submit"><?php echo html_escape(_l('se_journey_assign')); ?></button>
    <?php echo form_close(); ?>
    <?php if (!in_array($j->state, ['closed_lost', 'completed', 'opted_out'], true)) { ?>
      <?php echo form_open($act('close'), ['style' => 'display:inline-block', 'onsubmit' => 'return confirm(this.getAttribute(\'data-confirm\'))', 'data-confirm' => _l('se_journey_close_confirm')]); ?><button class="btn btn-default btn-sm" type="submit"><i class="fa fa-times"></i> <?php echo html_escape(_l('se_journey_close')); ?></button><?php echo form_close(); ?>
    <?php } ?>
  </div>
  <?php } ?>
</div></div></div></div>

<?php /* ---- Tabs ---- */ ?>
<div class="row"><div class="col-md-12">
  <ul class="nav nav-tabs">
    <?php foreach (['timeline' => true, 'intake' => $can['view_health'], 'photos' => $can['view_photos'], 'review' => $can['edit_review'] || $can['approve_quote'], 'care' => $can['manage_consultation'] || $can['manage_aftercare']] as $t => $allowed) {
        if (!$allowed) { continue; } ?>
      <li class="<?php echo $tab === $t ? 'active' : ''; ?>"><a href="<?php echo $tabUrl($t); ?>"><?php echo html_escape(_l('se_journey_tab_' . $t)); ?></a></li>
    <?php } ?>
  </ul>
</div></div>

<div class="row mtop15">
<?php if ($tab === 'timeline') { ?>
  <div class="col-md-8"><div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_journey_tab_timeline')); ?></h5>
    <?php if (!$timeline) { se_ui_empty(_l('se_journey_none')); } else { ?>
    <div style="max-height:600px;overflow-y:auto">
    <?php foreach ($timeline as $it) { ?>
      <div class="mbot10" style="border-left:3px solid rgba(128,128,128,.4);padding:4px 10px">
        <small class="text-muted"><?php echo html_escape($it['at']); ?> · <?php echo html_escape($it['kind']); ?><?php echo $it['actor'] !== '' ? ' · ' . html_escape($it['actor']) : ''; ?></small><br />
        <strong><?php echo html_escape($it['label']); ?></strong>
        <?php if ($it['text'] !== '') { ?><br /><?php echo nl2br(html_escape($it['text'])); ?><?php } ?>
      </div>
    <?php } ?>
    </div>
    <?php } ?>
  </div></div></div>
  <div class="col-md-4"><div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_journey_attention')); ?></h5>
    <?php if (!$tasks) { se_ui_empty(_l('se_journey_no_tasks')); } else { foreach ($tasks as $t) { ?>
      <div class="mbot10 clearfix">
        <?php echo $t['priority'] === 'urgent' ? se_ui_badge('failed', _l('se_journey_urgent')) . ' ' : ''; ?><?php echo html_escape($t['title']); ?>
        <?php echo form_open($act('task_done'), ['class' => 'pull-right']); ?><input type="hidden" name="task_id" value="<?php echo (int) $t['id']; ?>" /><button class="btn btn-default btn-xs" type="submit"><i class="fa fa-check"></i></button><?php echo form_close(); ?>
      </div>
    <?php } } ?>
  </div></div></div>

<?php } elseif ($tab === 'intake') { ?>
  <div class="col-md-8"><div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_journey_tab_intake')); ?>
      <?php if ($intake) { ?><small class="text-muted"><?php echo html_escape($intake->questionnaire_version . ' · ' . $intake->status . ' · ' . ($intake->submitted_at ?: $intake->last_saved_at)); ?></small><?php } ?></h5>
    <?php if (!$intake) { se_ui_empty(_l('se_journey_no_intake')); } else { ?>
      <?php $flags = json_decode((string) $intake->flags_json, true) ?: []; if ($flags) { ?>
        <div class="alert alert-warning"><strong><?php echo html_escape(_l('se_journey_review_flags')); ?>:</strong>
          <?php foreach ($flags as $f) { echo ' ' . se_ui_badge('warning', _l('se_journey_flag_' . preg_replace('/:.*/', '', $f)) . (strpos($f, ':') !== false ? ' ' . substr($f, strpos($f, ':') + 1) : '')); } ?>
          <br /><small><?php echo html_escape(_l('se_journey_flags_note')); ?></small></div>
      <?php } ?>
      <?php foreach ($sections as $sk => $section) { ?>
        <h5 class="mtop20"><?php echo html_escape($section['title']); ?></h5>
        <div class="table-responsive"><table class="table table-condensed"><tbody>
        <?php foreach ($section['fields'] as $fk => $f) { if (!isset($fields[$fk])) { continue; }
            $v = $answers[$fk] ?? null;
            if (is_array($v)) { $v = implode(', ', array_map(function ($x) use ($f) { return $f['options'][$x] ?? $x; }, $v)); }
            elseif ($v !== null && isset($f['options'][$v])) { $v = $f['options'][$v]; }
            $missing = ($v === null || $v === '') && !empty($f['required']); ?>
          <tr><td style="width:40%"><?php echo html_escape($f['label']); ?></td>
              <td><?php echo $missing ? '<span class="label label-warning">' . html_escape(_l('se_journey_missing')) . '</span>' : nl2br(html_escape((string) ($v ?? '—'))); ?></td></tr>
        <?php } ?>
        </tbody></table></div>
      <?php } ?>
      <?php if ($can['export_health']) { ?>
        <?php echo form_open(admin_url('se_journey/se_journey/export/' . $jid)); ?><button class="btn btn-default btn-sm" type="submit"><i class="fa fa-download"></i> <?php echo html_escape(_l('se_journey_export')); ?></button> <small class="text-muted"><?php echo html_escape(_l('se_journey_export_audited')); ?></small><?php echo form_close(); ?>
      <?php } ?>
    <?php } ?>
  </div></div></div>
  <div class="col-md-4"><div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_journey_consent')); ?></h5>
    <?php se_ui_kv(['health_data' => se_ui_badge($consent['health_data'] ? 'granted' : 'withdrawn'), 'photo_publication' => se_ui_badge($consent['photo_publication'] ? 'granted' : 'withdrawn'),
                    'marketing' => se_ui_badge($consent['marketing'] ? 'granted' : 'withdrawn'), 'whatsapp' => se_ui_badge($consent['whatsapp'] ? 'granted' : 'withdrawn'), 'version' => html_escape((string) $consent['version'])], true); ?>
  </div></div></div>

<?php } elseif ($tab === 'photos') { ?>
  <div class="col-md-8"><div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_journey_tab_photos')); ?></h5>
    <p><?php foreach (se_journey_required_photo_kinds($j) as $k) { echo se_ui_badge($checklist[$k] ? 'ok' : 'warning', _l('se_journey_photo_' . $k)) . ' '; } ?>
       <?php if ($checklist['_unclassified']) { echo se_ui_badge('pending', _l('se_journey_photo_unclassified')); } ?></p>
    <?php if (!$media) { se_ui_empty(_l('se_journey_no_photos')); } else { ?>
    <div class="row">
    <?php foreach ($media as $m) { ?>
      <div class="col-sm-6 col-xs-12 mbot15">
        <div style="border:1px solid rgba(128,128,128,.35);border-radius:4px;padding:8px">
          <?php if ($m['view_url'] !== '' && empty($m['deleted_at'])) { ?>
            <a href="<?php echo html_escape($m['view_url']); ?>" target="_blank" rel="noopener noreferrer"><img src="<?php echo html_escape($m['view_url']); ?>" alt="" style="max-width:100%;max-height:220px;display:block;margin:0 auto" /></a>
          <?php } else { ?><p class="text-muted"><?php echo html_escape($m['state']); ?><?php if (!empty($m['last_error'])) { echo ' — ' . html_escape($m['last_error']); } ?></p><?php } ?>
          <small class="text-muted">#<?php echo (int) $m['id']; ?> · <?php echo html_escape($m['source'] . ' · ' . $m['uploaded_at'] . ' · ' . $m['width'] . '×' . $m['height']); ?></small><br />
          <?php echo se_ui_badge($m['state'] === 'accepted' ? 'ok' : (in_array($m['state'], ['retake_requested', 'fetch_failed'], true) ? 'warning' : 'pending'), $m['state']); ?>
          <?php echo se_ui_badge($m['publication_permitted'] ? 'granted' : 'unknown', $m['publication_permitted'] ? _l('se_journey_publication_ok') : _l('se_journey_evaluation_only')); ?>
          <?php echo form_open($act('photo_classify'), ['class' => 'form-inline mtop5']); ?>
            <input type="hidden" name="tab" value="photos" /><input type="hidden" name="media_id" value="<?php echo (int) $m['id']; ?>" />
            <select name="kind" class="form-control input-sm">
              <?php foreach (['unclassified', 'frontal', 'left', 'right', 'donor', 'other', 'followup'] as $k) { ?><option value="<?php echo $k; ?>"<?php echo $m['kind'] === $k ? ' selected' : ''; ?>><?php echo html_escape(_l('se_journey_photo_' . $k)); ?></option><?php } ?>
            </select>
            <button class="btn btn-default btn-sm" type="submit"><?php echo html_escape(_l('se_journey_classify')); ?></button>
          <?php echo form_close(); ?>
        </div>
      </div>
    <?php } ?>
    </div>
    <?php } ?>
  </div></div></div>
  <div class="col-md-4"><div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_journey_photo_actions')); ?></h5>
    <?php echo form_open($act('photos_accept')); ?><input type="hidden" name="tab" value="photos" /><button class="btn btn-success btn-sm btn-block" type="submit"><i class="fa fa-check"></i> <?php echo html_escape(_l('se_journey_accept_photos')); ?></button><?php echo form_close(); ?>
    <?php echo form_open($act('photo_retake'), ['class' => 'mtop10']); ?><input type="hidden" name="tab" value="photos" />
      <div class="form-group"><label><?php echo html_escape(_l('se_journey_request_retake')); ?></label>
        <select name="kind" class="form-control input-sm"><?php foreach (['frontal', 'left', 'right', 'donor'] as $k) { ?><option value="<?php echo $k; ?>"><?php echo html_escape(_l('se_journey_photo_' . $k)); ?></option><?php } ?></select></div>
      <div class="form-group"><select name="reason" class="form-control input-sm" required><?php foreach ($retake_reasons as $r) { ?><option value="<?php echo $r; ?>"><?php echo html_escape(_l('se_journey_retake_' . $r)); ?></option><?php } ?></select></div>
      <button class="btn btn-warning btn-sm btn-block" type="submit"><?php echo html_escape(_l('se_journey_request_retake')); ?></button>
    <?php echo form_close(); ?>
    <?php echo form_open($act('photo_donor'), ['class' => 'mtop10']); ?><input type="hidden" name="tab" value="photos" /><button class="btn btn-default btn-sm btn-block" type="submit"><?php echo html_escape(_l('se_journey_request_donor')); ?></button><?php echo form_close(); ?>
    <?php echo form_open($act('photos_ready'), ['class' => 'mtop10']); ?><input type="hidden" name="tab" value="photos" /><button class="btn btn-primary btn-sm btn-block" type="submit"><?php echo html_escape(_l('se_journey_ready_for_review')); ?></button><?php echo form_close(); ?>
    <p class="text-muted mtop10"><small><?php echo html_escape(_l('se_journey_photo_privacy_note')); ?></small></p>
  </div></div></div>

<?php } elseif ($tab === 'review') { ?>
  <div class="col-md-6"><div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_journey_review')); ?></h5>
    <?php if ($flags) { ?><p><?php foreach ($flags as $f) { echo se_ui_badge('warning', _l('se_journey_flag_' . preg_replace('/:.*/', '', $f))) . ' '; } ?><br /><small class="text-muted"><?php echo html_escape(_l('se_journey_flags_note')); ?></small></p><?php } ?>
    <?php echo form_open($act('review_save')); ?><input type="hidden" name="tab" value="review" />
      <div class="form-group"><label><?php echo html_escape(_l('se_journey_internal_notes')); ?> <small class="text-muted"><?php echo html_escape(_l('se_journey_never_sent')); ?></small></label>
        <textarea name="internal_notes" class="form-control" rows="4" <?php echo $can['edit_review'] ? '' : 'disabled'; ?>><?php echo html_escape($review->internal_notes ?? ''); ?></textarea></div>
      <div class="row">
        <div class="col-sm-6"><div class="form-group"><label><?php echo html_escape(_l('se_journey_assignee')); ?></label>
          <select name="assigned_staff" class="form-control"><option value="0">—</option><?php foreach ($staff as $s) { ?><option value="<?php echo (int) $s['staffid']; ?>"<?php echo (int) $j->assigned_staff === (int) $s['staffid'] ? ' selected' : ''; ?>><?php echo html_escape(trim($s['firstname'] . ' ' . $s['lastname'])); ?></option><?php } ?></select></div></div>
        <div class="col-sm-6"><div class="form-group"><label><?php echo html_escape(_l('se_journey_due')); ?></label><input type="datetime-local" name="due_at" class="form-control" value="<?php echo html_escape($review && $review->due_at ? date('Y-m-d\TH:i', strtotime($review->due_at)) : ''); ?>" /></div></div>
      </div>
      <div class="form-group"><label><?php echo html_escape(_l('se_journey_decision')); ?></label>
        <select name="decision" class="form-control" <?php echo $can['edit_review'] ? '' : 'disabled'; ?>>
          <option value=""><?php echo html_escape(_l('se_journey_no_decision')); ?></option>
          <?php foreach ($decisions as $d) { ?><option value="<?php echo $d; ?>"<?php echo ($review->decision ?? '') === $d ? ' selected' : ''; ?>><?php echo html_escape(_l('se_journey_decision_' . $d)); ?></option><?php } ?>
        </select><small class="text-muted"><?php echo html_escape(_l('se_journey_decision_note')); ?></small></div>
      <div class="form-group"><input type="text" name="decision_note" class="form-control" maxlength="500" placeholder="<?php echo html_escape(_l('se_journey_decision_note_placeholder')); ?>" /></div>
      <div class="checkbox checkbox-primary"><input type="checkbox" id="cb_notify_patient" name="notify_patient" value="1"  /><label for="cb_notify_patient"><?php echo html_escape(_l('se_journey_notify_more_info')); ?></label></div>
      <?php if ($can['edit_review']) { ?><button class="btn btn-primary" type="submit"><?php echo html_escape(_l('se_journey_save_review')); ?></button><?php } ?>
    <?php echo form_close(); ?>
  </div></div></div>

  <div class="col-md-6"><div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_journey_quote')); ?>
      <?php if ($quote) { ?><?php echo se_ui_badge($quote->status === 'sent' ? 'sent' : ($quote->status === 'approved' ? 'ok' : ($quote->status === 'pending_approval' ? 'warning' : 'pending')), _l('se_journey_quote_status_' . $quote->status)); ?> <small class="text-muted">v<?php echo (int) $quote->version; ?></small><?php } ?></h5>
    <?php $editable = !$quote || in_array($quote->status, ['draft', 'pending_approval'], true) || $quote->status === 'sent'; ?>
    <?php echo form_open($act('quote_draft')); ?><input type="hidden" name="tab" value="review" />
      <div class="row">
        <div class="col-xs-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_currency')); ?></label><input name="currency" class="form-control" value="<?php echo html_escape($quote->currency ?? 'TRY'); ?>" maxlength="3" /></div></div>
        <div class="col-xs-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_amount_min')); ?></label><input name="amount_min" type="number" step="0.01" class="form-control" value="<?php echo html_escape($quote->amount_min ?? ''); ?>" <?php echo $amount_policy === 'hidden' ? 'disabled' : ''; ?> /></div></div>
        <div class="col-xs-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_amount_max')); ?></label><input name="amount_max" type="number" step="0.01" class="form-control" value="<?php echo html_escape($quote->amount_max ?? ''); ?>" <?php echo $amount_policy !== 'range' ? 'disabled' : ''; ?> /></div></div>
      </div>
      <div class="checkbox checkbox-primary"><input type="checkbox" id="cb_show_amount" name="show_amount" value="1" <?php echo !empty($quote->show_amount) ? 'checked' : ''; ?> <?php echo $amount_policy === 'hidden' ? 'disabled' : ''; ?> /><label for="cb_show_amount"><?php echo html_escape(_l('se_journey_show_amount')); ?> <small class="text-muted">(<?php echo html_escape(_l('se_journey_amount_policy_' . $amount_policy)); ?>)</small></label></div>
      <div class="form-group"><label><?php echo html_escape(_l('se_journey_recommendation')); ?></label>
        <select name="recommendation" class="form-control"><option value="consultation"<?php echo ($quote->recommendation ?? '') === 'consultation' ? ' selected' : ''; ?>><?php echo html_escape(_l('se_journey_rec_consultation')); ?></option><option value="procedure_after_consultation"<?php echo ($quote->recommendation ?? '') === 'procedure_after_consultation' ? ' selected' : ''; ?>><?php echo html_escape(_l('se_journey_rec_procedure')); ?></option></select></div>
      <div class="form-group"><label><?php echo html_escape(_l('se_journey_valid_until')); ?></label><input type="date" name="valid_until" class="form-control" value="<?php echo html_escape($quote->valid_until ?? ''); ?>" /></div>
      <div class="row">
        <div class="col-sm-6"><div class="form-group"><label><?php echo html_escape(_l('se_journey_included')); ?></label><textarea name="included" class="form-control" rows="3"><?php echo html_escape(implode("\n", json_decode((string) ($quote->included_json ?? '[]'), true) ?: [])); ?></textarea></div></div>
        <div class="col-sm-6"><div class="form-group"><label><?php echo html_escape(_l('se_journey_excluded')); ?></label><textarea name="excluded" class="form-control" rows="3"><?php echo html_escape(implode("\n", json_decode((string) ($quote->excluded_json ?? '[]'), true) ?: [])); ?></textarea></div></div>
      </div>
      <div class="form-group"><label><?php echo html_escape(_l('se_journey_deposit_terms')); ?></label><input name="deposit_terms" class="form-control" maxlength="500" value="<?php echo html_escape($quote->deposit_terms ?? ''); ?>" /></div>
      <div class="form-group"><label><?php echo html_escape(_l('se_journey_travel_notes')); ?></label><textarea name="travel_notes" class="form-control" rows="2"><?php echo html_escape($quote->travel_notes ?? ''); ?></textarea></div>
      <div class="row">
        <div class="col-sm-8"><div class="form-group"><label><?php echo html_escape(_l('se_journey_internal_notes')); ?> <small class="text-muted"><?php echo html_escape(_l('se_journey_never_sent')); ?></small></label><input name="internal_notes" class="form-control" value="<?php echo html_escape($quote->internal_notes ?? ''); ?>" /></div></div>
        <div class="col-sm-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_internal_margin')); ?></label><input name="internal_margin" class="form-control" value="<?php echo html_escape($quote->internal_margin ?? ''); ?>" /></div></div>
      </div>
      <?php if ($can['edit_review']) { ?><button class="btn btn-default" type="submit"><?php echo html_escape($quote && $quote->status === 'sent' ? _l('se_journey_new_version') : _l('se_journey_save_draft')); ?></button><?php } ?>
    <?php echo form_close(); ?>

    <?php if ($quote) { ?>
    <div class="mtop15">
      <?php if ($quote->status === 'draft' && $can['edit_review']) { ?>
        <?php echo form_open($act('quote_request'), ['style' => 'display:inline-block']); ?><input type="hidden" name="tab" value="review" /><input type="hidden" name="quote_id" value="<?php echo (int) $quote->id; ?>" /><button class="btn btn-default btn-sm" type="submit"><?php echo html_escape(_l('se_journey_request_approval')); ?></button><?php echo form_close(); ?>
      <?php } ?>
      <?php if (in_array($quote->status, ['draft', 'pending_approval'], true)) { ?>
        <?php if ($can['approve_quote']) { ?>
          <?php echo form_open($act('quote_approve'), ['style' => 'display:inline-block']); ?><input type="hidden" name="tab" value="review" /><input type="hidden" name="quote_id" value="<?php echo (int) $quote->id; ?>" /><button class="btn btn-success btn-sm" type="submit"><i class="fa fa-check"></i> <?php echo html_escape(_l('se_journey_approve_quote')); ?></button><?php echo form_close(); ?>
        <?php } else { ?><span class="label label-warning"><?php echo html_escape(_l('se_journey_needs_approver')); ?></span><?php } ?>
      <?php } ?>
      <?php if ($quote->status === 'approved') { ?>
        <span class="text-muted"><small><?php echo html_escape(_l('se_journey_approved_by')); ?> <?php echo html_escape($staffName($quote->approved_by)); ?> · <?php echo html_escape($quote->approved_at); ?></small></span>
        <?php if ($can['edit_review']) { ?><?php echo form_open($act('quote_send'), ['style' => 'display:inline-block']); ?><input type="hidden" name="tab" value="review" /><input type="hidden" name="quote_id" value="<?php echo (int) $quote->id; ?>" /><button class="btn btn-primary btn-sm" type="submit"><i class="fa fa-paper-plane"></i> <?php echo html_escape(_l('se_journey_send_quote')); ?></button><?php echo form_close(); ?><?php } ?>
      <?php } ?>
      <?php if ($quote->status === 'sent') { ?>
        <small class="text-muted"><?php echo html_escape(_l('se_journey_sent_at')); ?> <?php echo html_escape($quote->sent_at); ?> · <?php echo html_escape(_l('se_journey_snapshot_hash')); ?> <code><?php echo html_escape(substr((string) $quote->snapshot_hash, 0, 16)); ?></code></small>
        <details class="mtop10"><summary><?php echo html_escape(_l('se_journey_snapshot')); ?></summary><pre style="white-space:pre-wrap;font-size:11px"><?php echo html_escape(json_encode(json_decode((string) $quote->snapshot_json, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
      <?php } ?>
    </div>
    <?php } ?>
    <?php if (count($quotes) > 1) { ?><p class="mtop10 text-muted"><small><?php foreach ($quotes as $q) { echo 'v' . (int) $q['version'] . ' ' . html_escape($q['status']) . ' · '; } ?></small></p><?php } ?>
  </div></div></div>

<?php } elseif ($tab === 'care') { ?>
  <div class="col-md-6"><div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_journey_appointments')); ?></h5>
    <?php if (!$appointments) { se_ui_empty(_l('se_journey_none')); } else { ?>
      <div class="table-responsive"><table class="table table-condensed"><tbody>
      <?php foreach ($appointments as $a) { ?>
        <tr><td><?php echo html_escape($a['start_at']); ?><br /><small class="text-muted"><?php echo html_escape($a['title'] . ' · ' . ($a['appointment_type'] ?? '') . ' · ' . ($a['consultation_format'] ?? '')); ?></small></td>
            <td><?php echo se_ui_badge($a['status']); ?></td>
            <td>
              <?php if ($can['manage_consultation'] && !in_array($a['status'], ['cancelled', 'completed'], true)) { ?>
              <?php echo form_open($act('appointment'), ['class' => 'form-inline']); ?><input type="hidden" name="tab" value="care" /><input type="hidden" name="appointment_id" value="<?php echo (int) $a['id']; ?>" />
                <select name="status" class="form-control input-sm"><?php foreach (['scheduled', 'confirmed', 'held', 'completed', 'no_show', 'cancelled'] as $s) { ?><option value="<?php echo $s; ?>"<?php echo $a['status'] === $s ? ' selected' : ''; ?>><?php echo $s; ?></option><?php } ?></select>
                <input type="text" name="outcome_note" class="form-control input-sm" placeholder="<?php echo html_escape(_l('se_journey_outcome')); ?>" />
                <button class="btn btn-default btn-sm" type="submit"><?php echo html_escape(_l('se_journey_update')); ?></button>
              <?php echo form_close(); ?>
              <?php } ?>
            </td></tr>
      <?php } ?>
      </tbody></table></div>
    <?php } ?>
    <?php if ($can['manage_consultation']) { ?>
    <h5 class="mtop15"><?php echo html_escape(_l('se_journey_book')); ?></h5>
    <?php echo form_open($act('book')); ?><input type="hidden" name="tab" value="care" />
      <div class="row">
        <div class="col-sm-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_type')); ?></label><select name="type" class="form-control"><option value="consultation"><?php echo html_escape(_l('se_journey_consultation')); ?></option><option value="procedure"><?php echo html_escape(_l('se_journey_procedure')); ?></option></select></div></div>
        <div class="col-sm-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_start')); ?></label><input type="datetime-local" name="start_at" class="form-control" required /></div></div>
        <div class="col-sm-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_end')); ?></label><input type="datetime-local" name="end_at" class="form-control" /></div></div>
      </div>
      <div class="row">
        <div class="col-sm-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_staff')); ?></label><select name="staff_id" class="form-control"><?php foreach ($staff as $s) { ?><option value="<?php echo (int) $s['staffid']; ?>"><?php echo html_escape(trim($s['firstname'] . ' ' . $s['lastname'])); ?></option><?php } ?></select></div></div>
        <div class="col-sm-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_format')); ?></label><select name="consultation_format" class="form-control"><option value="in_person"><?php echo html_escape(_l('se_journey_in_person')); ?></option><option value="online"><?php echo html_escape(_l('se_journey_online')); ?></option></select></div></div>
        <div class="col-sm-4"><div class="form-group"><label><?php echo html_escape(_l('se_journey_deposit')); ?></label><select name="deposit_state" class="form-control"><option value="">—</option><?php foreach (['none', 'requested', 'received', 'refunded'] as $d) { ?><option value="<?php echo $d; ?>"><?php echo $d; ?></option><?php } ?></select></div></div>
      </div>
      <div class="form-group"><input type="text" name="location" class="form-control" placeholder="<?php echo html_escape(_l('se_journey_location')); ?>" /></div>
      <div class="form-group"><input type="text" name="payment_ref" class="form-control" placeholder="<?php echo html_escape(_l('se_journey_payment_ref')); ?>" /></div>
      <button class="btn btn-primary btn-sm" type="submit"><?php echo html_escape(_l('se_journey_book')); ?></button>
    <?php echo form_close(); ?>
    <?php } ?>
  </div></div></div>

  <div class="col-md-6"><div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_journey_procedure')); ?></h5>
    <?php if ($can['manage_consultation']) { ?>
      <?php if ($j->state === 'procedure_booked') { ?>
        <?php echo form_open($act('preop_start')); ?><input type="hidden" name="tab" value="care" /><button class="btn btn-default btn-sm" type="submit"><?php echo html_escape(_l('se_journey_start_preop')); ?></button><?php echo form_close(); ?>
      <?php } ?>
      <?php if (in_array($j->state, ['preop_pending', 'procedure_booked'], true)) { ?>
        <ul class="mtop10"><?php foreach ($preop as $item) { ?><li><?php echo html_escape($item); ?></li><?php } ?></ul>
        <?php echo form_open($act('procedure_complete')); ?><input type="hidden" name="tab" value="care" />
          <div class="form-group"><label><?php echo html_escape(_l('se_journey_procedure_at')); ?></label><input type="datetime-local" name="procedure_at" class="form-control" /></div>
          <div class="form-group"><textarea name="notes" class="form-control" rows="3" placeholder="<?php echo html_escape(_l('se_journey_procedure_notes')); ?>"></textarea></div>
          <button class="btn btn-primary btn-sm" type="submit"><?php echo html_escape(_l('se_journey_procedure_complete')); ?></button>
        <?php echo form_close(); ?>
      <?php } ?>
      <?php if ($j->procedure_at) { ?><p class="text-muted"><small><?php echo html_escape(_l('se_journey_procedure_at')); ?>: <?php echo html_escape($j->procedure_at); ?> · <?php echo html_escape(_l('se_journey_deposit')); ?>: <?php echo html_escape($j->deposit_state ?: '—'); ?></small></p><?php } ?>
    <?php } ?>

    <h5 class="mtop15"><?php echo html_escape(_l('se_journey_aftercare')); ?></h5>
    <?php if ($can['manage_aftercare'] && in_array($j->state, ['procedure_completed', 'aftercare_active', 'completed'], true)) { ?>
      <?php echo form_open($act('aftercare_start'), ['class' => 'form-inline']); ?><input type="hidden" name="tab" value="care" />
        <select name="protocol" class="form-control input-sm"><?php foreach ($protocols as $p) { ?><option value="<?php echo html_escape($p['key']); ?>"><?php echo html_escape($p['name'] . ' v' . $p['version'] . ($p['approved'] ? '' : ' — ' . _l('se_journey_unapproved'))); ?></option><?php } ?></select>
        <button class="btn btn-default btn-sm" type="submit"><?php echo html_escape(_l('se_journey_start_aftercare')); ?></button>
      <?php echo form_close(); ?>
    <?php } ?>
    <?php if (!$aftercare) { se_ui_empty(_l('se_journey_none')); } else { ?>
      <div class="table-responsive mtop10"><table class="table table-condensed"><tbody>
      <?php foreach ($aftercare as $e) { ?>
        <tr><td><?php echo html_escape($e['label']); ?><br /><small class="text-muted"><?php echo html_escape($e['kind'] . ' · ' . $e['due_at']); ?></small></td>
            <td><?php echo se_ui_badge($e['state'] === 'answered' ? 'ok' : ($e['state'] === 'unanswered' || $e['state'] === 'blocked' ? 'warning' : ($e['state'] === 'sent' ? 'sent' : 'pending')), $e['state']); ?></td>
            <td><?php if ($e['state'] === 'answered' && $can['view_health'] && !empty($e['reply_enc'])) { se_journey_audit((int) $j->brand_id, $jid, 'view_checkin', 'aftercare_event', (string) $e['id']); echo '<small>' . nl2br(html_escape(se_journey_aftercare_reply_text($e))) . '</small>'; } ?></td></tr>
      <?php } ?>
      </tbody></table></div>
    <?php } ?>
    <?php if ($can['manage_aftercare'] && in_array($j->state, ['aftercare_active', 'followup_due'], true)) { ?>
      <?php echo form_open($act('complete'), ['class' => 'mtop10']); ?><input type="hidden" name="tab" value="care" /><button class="btn btn-success btn-sm" type="submit"><i class="fa fa-flag-checkered"></i> <?php echo html_escape(_l('se_journey_mark_completed')); ?></button><?php echo form_close(); ?>
    <?php } ?>
  </div></div></div>
<?php } ?>
</div>

</div></div>
<?php init_tail(); ?></body></html>
