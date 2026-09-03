<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head();
/*
 * Patient workspace (CRM-M025..M030 / UIUX §F / DS §2.4–2.7, 2.12, 2.13 / UX-COPY §3.5, §6).
 * Header → stage bar → next action → alerts → tabs. Every action is a POST to
 * se_journey/action/<id>/<what> (CSRF by AdminController); nothing here sends
 * a patient message except the Sohbet composer, whose policy the server sets.
 */
$jid = (int) $j->id;
$act = function ($what) use ($jid) { return admin_url('se_journey/se_journey/action/' . $jid . '/' . $what); };
$tabUrl = function ($t) use ($jid) { return admin_url('se_journey/se_journey/view/' . $jid . '?tab=' . $t); };
$staffName = function ($id) use ($staff) {
    foreach ($staff as $s) { if ((int) $s['staffid'] === (int) $id) { return trim($s['firstname'] . ' ' . $s['lastname']); } }
    return $id ? '#' . (int) $id : '—';
};
$hidden = function ($tab) { return '<input type="hidden" name="tab" value="' . html_escape($tab) . '" />'; };
$title  = $name !== '' ? $name : $phone;
$closed = in_array((string) $j->state, ['closed_lost', 'not_suitable', 'completed', 'opted_out'], true);
$srcKey = 'se_journey_source_' . (string) $j->source; $src = _l($srcKey); if ($src === $srcKey) { $src = (string) $j->source; }
$photoCount = 0; foreach ($checklist as $k => $v) { if ($k[0] !== '_' && $v) { $photoCount++; } }
$reqKinds = se_journey_required_photo_kinds($j);
$tabs = [
    'timeline' => [true, null],
    'chat'     => [(int) $j->wa_conversation_id > 0 && staff_can('view', 'se_whatsapp'), $unread > 0 ? $unread : null],
    'intake'   => [$can['view_health'], null],
    'photos'   => [$can['view_photos'], $photoCount > 0 ? $photoCount : null],
    'review'   => [$can['edit_review'] || $can['approve_quote'] || $can['view'], null],
    'care'     => [$can['manage_consultation'] || $can['manage_aftercare'] || $can['view'], null],
    'consent'  => [true, null],
];
?>
<div id="wrapper"><div class="content se-page se-patient">

  <p class="se-help" style="margin:0 0 8px"><a href="<?php echo admin_url('se_core/se_hastalar'); ?>">← <?php echo _l('se_nav_hastalar'); ?></a></p>

  <div class="se-card">
    <div class="se-ph">
      <div class="se-avatar" aria-hidden="true"><?php echo html_escape(se_ui_initials($title)); ?></div>
      <div>
        <h1><?php echo html_escape($name !== '' ? se_ui_short_name($name) : $phone); ?>
          <?php echo se_ui_state_badge($j->state); ?>
          <?php if ((int) $j->urgent === 1) { echo ' ', se_ui_ds_badge('danger', _l('se_journey_urgent'), true); } ?>
        </h1>
        <div class="se-idline">
          <?php if ($name !== '') { ?><span><bdi dir="ltr"><?php echo html_escape($phone); ?></bdi></span><?php } ?>
          <?php if ($src !== '') { ?><span><?php echo html_escape($src); ?></span><?php } ?>
          <span><?php echo _l('se_journey_assignee'); ?>: <?php echo html_escape((int) $j->assigned_staff > 0 ? $staffName($j->assigned_staff) : _l('se_journey_unassigned')); ?></span>
          <?php if ((int) $j->lead_id > 0 && is_admin()) { ?><span><a href="<?php echo admin_url('leads/index/' . (int) $j->lead_id); ?>"><?php echo _l('se_appt_lead'); ?> #<?php echo (int) $j->lead_id; ?></a></span><?php } ?>
        </div>
        <dl class="se-facts">
          <div><dt><?php echo _l('se_pw_last_touch'); ?></dt><dd><?php echo html_escape(se_ui_age($j->latest_touch_at ?: $j->last_updated)); ?><?php if ($unread > 0) { ?> · 💬 <?php echo (int) $unread; ?><?php } ?></dd></div>
          <div><dt><?php echo _l('se_pw_appointment'); ?></dt><dd><?php echo $next_appointment ? html_escape((function_exists('se_appt_type_label') ? se_appt_type_label($next_appointment['appointment_type'] ?? '') . ' · ' : '') . se_ui_when($next_appointment['start_at'])) : '—'; ?></dd></div>
          <div><dt><?php echo _l('se_journey_automation'); ?></dt><dd><?php echo se_ui_automation_badge($j->automation_state); ?></dd></div>
          <div><dt><?php echo _l('se_journey_consent'); ?></dt><dd><?php echo _l('se_pw_consent_health'); ?> <?php echo $consent['health_data'] ? '✓' : '✗'; ?> · <?php echo _l('se_pw_consent_marketing'); ?> <?php echo $consent['marketing'] ? '✓' : '✗'; ?> · <?php echo _l('se_pw_consent_publication'); ?> <?php echo $consent['photo_publication'] ? '✓' : '✗'; ?></dd></div>
          <?php if ($quote_latest) { ?><div><dt><?php echo _l('se_journey_quote'); ?></dt><dd>v<?php echo (int) $quote_latest->version; ?> · <?php echo html_escape(_l('se_journey_quote_status_' . $quote_latest->status)); ?></dd></div><?php } ?>
        </dl>
      </div>
      <div class="se-ph-actions" style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
        <?php if ($tabs['chat'][0]) { echo se_ui_btn(_l('se_pw_write'), $tabUrl('chat'), 'primary', ['icon' => '💬']); } ?>
        <?php if ($can['manage_consultation'] && (int) $j->lead_id > 0 && staff_can('create', 'se_appointments')) { echo se_ui_btn(_l('se_today_new_appt'), admin_url('se_appointments/create?lead=' . (int) $j->lead_id . '&journey=' . $jid), 'secondary', ['icon' => '＋']); } ?>
        <details class="se-more">
          <summary class="se-btn se-btn-secondary" aria-label="<?php echo _l('se_pw_more'); ?>">⋯</summary>
          <div class="se-more-panel se-card">
            <?php if ($can['edit_review']) { ?>
              <?php echo form_open($act('assign'), ['class' => 'se-field']); echo $hidden($tab); ?>
                <label for="se-assign"><?php echo _l('se_journey_assignee'); ?></label>
                <div style="display:flex;gap:8px"><select id="se-assign" name="staff_id" class="se-input">
                  <option value="0"><?php echo _l('se_journey_unassigned'); ?></option>
                  <?php foreach ($staff as $s) { ?><option value="<?php echo (int) $s['staffid']; ?>"<?php echo (int) $j->assigned_staff === (int) $s['staffid'] ? ' selected' : ''; ?>><?php echo html_escape(trim($s['firstname'] . ' ' . $s['lastname'])); ?></option><?php } ?>
                </select><button class="se-btn se-btn-secondary" type="submit"><?php echo _l('se_journey_assign'); ?></button></div>
              <?php echo form_close(); ?>
              <?php if ($j->state === 'new_whatsapp_enquiry') { echo form_open($act('start')), $hidden($tab), '<button class="se-btn se-btn-primary se-btn-block" type="submit">▶ ', html_escape(_l('se_journey_start_welcome')), '</button>', form_close(); } ?>
              <?php if (in_array($j->state, ['welcome_sent', 'privacy_notice_sent', 'consent_pending', 'intake_link_sent', 'intake_started', 'intake_incomplete', 'consent_declined'], true)) { echo form_open($act('resend_link')), $hidden($tab), '<button class="se-btn se-btn-secondary se-btn-block" type="submit">🔗 ', html_escape(_l('se_journey_resend_link')), '</button>', form_close(); } ?>
              <?php if ($j->automation_state === 'active') { echo form_open($act('pause')), $hidden($tab), '<input type="hidden" name="reason" value="staff_pause" /><button class="se-btn se-btn-secondary se-btn-block" type="submit">⏸ ', html_escape(_l('se_journey_pause')), '</button>', form_close(); }
                    elseif ($j->state !== 'opted_out') { echo form_open($act('resume')), $hidden($tab), '<input type="hidden" name="reason" value="staff_resume" /><button class="se-btn se-btn-secondary se-btn-block" type="submit">▶ ', html_escape($j->automation_state === 'error' ? _l('se_journey_retry_resume') : _l('se_journey_resume')), '</button>', form_close(); } ?>
              <?php if ((int) $j->lead_id > 0) { echo form_open($act('lead_sync')), $hidden($tab), '<button class="se-btn se-btn-ghost se-btn-block" type="submit" title="', html_escape(_l('se_journey_lead_sync_hint')), '">↻ ', html_escape(_l('se_journey_lead_sync')), '</button>', form_close(); } ?>
              <?php if ($tabs['chat'][0]) { echo se_ui_btn(_l('se_pw_open_inbox'), admin_url('se_whatsapp/se_whatsapp/conversation/' . (int) $j->wa_conversation_id), 'ghost', ['class' => 'se-btn-block']); } ?>
              <?php if (!$closed) { echo form_open($act('close'), ['onsubmit' => 'return confirm(this.getAttribute(\'data-confirm\'))', 'data-confirm' => _l('se_journey_close_confirm')]), $hidden($tab), '<button class="se-btn se-btn-danger se-btn-block" type="submit">✕ ', html_escape(_l('se_journey_close')), '</button>', form_close(); } ?>
            <?php } else { ?>
              <?php if ($tabs['chat'][0]) { echo se_ui_btn(_l('se_pw_open_inbox'), admin_url('se_whatsapp/se_whatsapp/conversation/' . (int) $j->wa_conversation_id), 'ghost', ['class' => 'se-btn-block']); } else { ?><p class="se-help"><?php echo _l('se_pw_no_actions'); ?></p><?php } ?>
            <?php } ?>
          </div>
        </details>
      </div>
    </div>

    <?php echo se_ui_stages($j->state); ?>

    <?php if (!empty($na['sentence'])) { echo se_ui_next_action($na); } ?>

    <?php /* Alerts: only what changes a decision (DS §2.7) */ ?>
    <div class="se-stack" style="margin-top:12px">
      <?php if ((int) $j->urgent === 1) { echo se_ui_alert('danger', _l('se_journey_urgent_banner')); } ?>
      <?php if ($j->automation_state === 'error' || $j->automation_state === 'blocked') { echo se_ui_alert('danger', _l('se_pw_automation_error') . ($j->automation_reason ? ' — ' . $j->automation_reason : ''), $can['edit_review'] ? ['label' => _l('se_journey_retry_resume'), 'href' => $tabUrl($tab) . '#se-more'] : null); } ?>
      <?php if (!empty($wa_failed) || $j->last_send_block) { echo se_ui_alert('warning', _l('se_pw_send_failed') . ($j->last_send_block ? ' — ' . $j->last_send_block : ''), $tabs['chat'][0] ? ['label' => _l('se_journey_tab_chat'), 'href' => $tabUrl('chat')] : null); } ?>
      <?php if (!$consent['health_data'] && !in_array($j->state, ['new_whatsapp_enquiry', 'welcome_sent', 'privacy_notice_sent', 'consent_pending', 'consent_declined', 'opted_out', 'closed_lost'], true)) { echo se_ui_alert('warning', _l('se_pw_no_health_consent'), ['label' => _l('se_journey_tab_consent'), 'href' => $tabUrl('consent')]); } ?>
      <?php if ($consent['health_data'] && !$consent['photo_publication'] && $photoCount > 0) { echo se_ui_alert('info', _l('se_pw_no_publication')); } ?>
      <?php if ($quote_latest && $quote_latest->status === 'sent' && !empty($quote_latest->valid_until) && strtotime($quote_latest->valid_until . ' 23:59:59') < time() && (string) $j->state === 'quote_sent') { echo se_ui_alert('warning', sprintf(_l('se_pw_quote_expired'), (int) $quote_latest->version, se_ui_when($quote_latest->valid_until . ' 00:00:00')), $can['edit_review'] ? ['label' => _l('se_journey_new_version'), 'href' => $tabUrl('review')] : null); } ?>
      <?php if ($closed && (string) $j->state !== 'completed' && $can['edit_review']) { ?>
        <?php echo form_open($act('reopen'), ['class' => 'se-alert se-alert-info']); echo $hidden($tab); ?>
          <span aria-hidden="true">↩</span>
          <span style="flex:1"><?php echo _l('se_pw_reopen_hint'); ?></span>
          <input class="se-input" style="max-width:260px" name="reason" required maxlength="500" placeholder="<?php echo _l('se_pw_reopen_reason'); ?>" aria-label="<?php echo _l('se_pw_reopen_reason'); ?>">
          <button class="se-btn se-btn-secondary se-btn-sm" type="submit"><?php echo _l('se_pw_reopen'); ?></button>
        <?php echo form_close(); ?>
      <?php } ?>
      <?php if ($j->state === 'opted_out' && $can['edit_review']) { ?>
        <?php echo form_open($act('reactivate'), ['class' => 'se-alert se-alert-warning']); echo $hidden($tab); ?>
          <span aria-hidden="true">⚠️</span><span style="flex:1"><?php echo _l('se_journey_state_opted_out'); ?></span>
          <input class="se-input" style="max-width:260px" name="evidence" required placeholder="<?php echo _l('se_journey_evidence_placeholder'); ?>" aria-label="<?php echo _l('se_journey_evidence_placeholder'); ?>">
          <button class="se-btn se-btn-secondary se-btn-sm" type="submit"><?php echo _l('se_journey_reactivate'); ?></button>
        <?php echo form_close(); ?>
      <?php } ?>
    </div>
  </div>

  <nav class="se-tabs" aria-label="<?php echo _l('se_pw_tabs'); ?>" style="margin-top:16px">
    <?php foreach ($tabs as $t => [$allowed, $n]) { if (!$allowed) { continue; } ?>
      <a href="<?php echo $tabUrl($t); ?>" class="<?php echo $tab === $t ? 'active' : ''; ?>"<?php echo $tab === $t ? ' aria-current="page"' : ''; ?>><?php echo _l('se_journey_tab_' . $t); ?><?php if ($n) { ?><span class="n"><?php echo (int) $n; ?></span><?php } ?></a>
    <?php } ?>
  </nav>

<?php /* ================================ GENEL ================================ */ ?>
<?php if ($tab === 'timeline') { ?>
  <div class="se-grid se-grid-8-4">
    <section class="se-stack">
      <div class="se-card">
        <h2><?php echo _l('se_pw_history'); ?></h2>
        <?php if (!$timeline) { ?><p class="se-help"><?php echo _l('se_journey_none'); ?></p><?php } else { ?>
        <ul class="se-tl">
          <?php $lastDay = ''; foreach ($timeline as $it) {
              $day = substr((string) $it['at'], 0, 10);
              if ($day !== $lastDay) { $lastDay = $day; ?><li class="se-tl-day"><span></span><div class="m"><?php echo html_escape(preg_replace('/ \d{2}:\d{2}$/', '', se_ui_when($day . ' 00:00:00'))); ?></div></li><?php }
              $dot = $it['tone'] === 'danger' || $it['tone'] === 'warning' ? 'warn' : ($it['kind'] === 'in' || $it['actor'] === _l('se_tl_actor_patient') ? 'pt' : ($it['actor'] === _l('se_tl_actor_auto') ? 'sys' : 'st')); ?>
            <li><span class="dot <?php echo $dot; ?>" aria-hidden="true"></span><div>
              <div class="h"><?php echo html_escape($it['label']); ?></div>
              <div class="m"><?php echo html_escape(substr((string) $it['at'], 11, 5) . ' · ' . $it['actor']); ?></div>
              <?php if ($it['text'] !== '') { ?><div class="b"><?php echo nl2br(html_escape($it['text'])); ?></div><?php } ?>
            </div></li>
          <?php } ?>
        </ul>
        <?php } ?>
      </div>
    </section>
    <aside class="se-stack">
      <div class="se-card">
        <h2><?php echo _l('se_pw_summary'); ?></h2>
        <ul class="se-checks">
          <li><span class="<?php echo $intake && $intake->status === 'submitted' ? 'ok' : 'todo'; ?>"><?php echo $intake && $intake->status === 'submitted' ? '✓' : '○'; ?></span> <?php echo _l('se_pw_check_form'); ?><?php if ($intake) { ?> <span class="se-help">(<?php echo html_escape($intake->questionnaire_version); ?> · <?php echo html_escape(se_ui_age($intake->submitted_at ?: $intake->last_saved_at)); ?>)</span><?php } ?></li>
          <li><span class="<?php echo $photoCount >= count($reqKinds) && $reqKinds ? 'ok' : 'todo'; ?>"><?php echo $photoCount >= count($reqKinds) && $reqKinds ? '✓' : '○'; ?></span> <?php echo _l('se_pw_check_photos'); ?> <?php echo (int) $photoCount; ?>/<?php echo count($reqKinds); ?></li>
          <li><span class="<?php echo in_array(se_ui_stage_of($j->state), ['quote', 'consultation', 'procedure', 'aftercare'], true) || $j->state === 'consultation_recommended' ? 'ok' : 'todo'; ?>"><?php echo in_array(se_ui_stage_of($j->state), ['quote', 'consultation', 'procedure', 'aftercare'], true) || $j->state === 'consultation_recommended' ? '✓' : '○'; ?></span> <?php echo _l('se_pw_check_review'); ?></li>
          <li><span class="<?php echo $quote_latest && in_array($quote_latest->status, ['sent', 'approved'], true) ? 'ok' : 'todo'; ?>"><?php echo $quote_latest && in_array($quote_latest->status, ['sent', 'approved'], true) ? '✓' : '○'; ?></span> <?php echo _l('se_pw_check_quote'); ?></li>
        </ul>
      </div>
      <div class="se-card">
        <h2><?php echo _l('se_journey_attention'); ?><?php if ($tasks) { ?> <span class="se-count"><?php echo count($tasks); ?></span><?php } ?></h2>
        <?php if (!$tasks) { ?><p class="se-help"><?php echo _l('se_journey_no_tasks'); ?></p><?php } else { ?>
        <ul class="se-attn">
          <?php foreach ($tasks as $t) { ?>
            <li><div><span class="se-who"><?php echo $t['priority'] === 'urgent' ? se_ui_ds_badge('danger', _l('se_journey_urgent'), true) . ' ' : ''; ?><?php echo html_escape($t['title']); ?></span></div>
              <?php echo form_open($act('task_done')); echo $hidden($tab); ?><input type="hidden" name="task_id" value="<?php echo (int) $t['id']; ?>" /><button class="se-btn se-btn-secondary se-btn-sm" type="submit" aria-label="<?php echo html_escape($t['title'] . ' — ' . _l('se_pw_task_done')); ?>">✓ <?php echo _l('se_pw_task_done'); ?></button><?php echo form_close(); ?></li>
          <?php } ?>
        </ul>
        <?php } ?>
      </div>
      <div class="se-card">
        <h2><?php echo _l('se_pw_notes'); ?></h2>
        <?php echo form_open($act('note')); echo $hidden($tab); ?>
          <label class="se-sr" for="se-note"><?php echo _l('se_pw_notes'); ?></label>
          <textarea id="se-note" class="se-input" name="note" rows="3" maxlength="500" style="height:auto;padding:10px" placeholder="<?php echo _l('se_pw_note_ph'); ?>" required></textarea>
          <button class="se-btn se-btn-secondary se-btn-sm" type="submit" style="margin-top:8px"><?php echo _l('se_pw_note_save'); ?></button>
        <?php echo form_close(); ?>
      </div>
    </aside>
  </div>

<?php /* ================================ SOHBET ================================ */ ?>
<?php } elseif ($tab === 'chat') { ?>
  <div class="se-grid se-grid-8-4">
    <section class="se-card se-threadcol">
      <?php if (empty($conversation)) { echo se_ui_empty_state(_l('se_wa_no_messages'), _l('se_pw_no_thread')); } else { ?>
        <?php se_ui_chat_thread($messages, $media ?? [], ['channel' => 'wa', 'empty' => _l('se_wa_no_messages')]); ?>
        <?php $back = $tabUrl('chat');
        if (!$policy['allowed']) {
            se_ui_chat_composer(['mode' => 'gated', 'title' => _l('se_wa_sending_gated'), 'reason' => _l('se_wa_blocked_' . $policy['reason'])]);
        } elseif ($policy['mode'] === 'freeform') {
            se_ui_chat_composer(['mode' => 'freeform', 'action' => admin_url('se_whatsapp/se_whatsapp/reply/' . (int) $conversation->id), 'back' => $back,
                'window_label' => _l('se_wa_window_open'), 'window_text' => _l('se_wa_window_until') . ' ' . (string) $policy['expires_at'], 'maxlength' => 4096, 'voice_ogg_ok' => true,
                'placeholder' => _l('se_chat_placeholder'), 'label_send' => _l('se_chat_send'), 'templates' => $templates, 'label_send_template' => _l('se_chat_send_template'),
                'journey_active' => (string) $j->automation_state === 'active']);
        } else {
            se_ui_chat_composer(['mode' => 'template', 'action' => admin_url('se_whatsapp/se_whatsapp/reply/' . (int) $conversation->id), 'back' => $back,
                'window_label' => _l('se_wa_window_closed'), 'window_text' => _l('se_wa_reply_template_required'), 'templates' => $templates, 'label_send' => _l('se_chat_send_template')]);
        } ?>
      <?php } ?>
    </section>
    <aside class="se-stack">
      <div class="se-card se-ctx">
        <h2><?php echo _l('se_pw_ctx'); ?></h2>
        <?php if (!empty($na['sentence'])) { echo se_ui_next_action($na, true); } ?>
        <dl class="se-facts">
          <div><dt><?php echo _l('se_journey_automation'); ?></dt><dd><?php echo se_ui_automation_badge($j->automation_state); ?></dd></div>
          <div><dt><?php echo _l('se_journey_state'); ?></dt><dd><?php echo html_escape(se_ui_state_label($j->state)); ?></dd></div>
          <?php if ($quote_latest) { ?><div><dt><?php echo _l('se_journey_quote'); ?></dt><dd>v<?php echo (int) $quote_latest->version; ?> · <?php echo html_escape(_l('se_journey_quote_status_' . $quote_latest->status)); ?></dd></div><?php } ?>
          <div><dt><?php echo _l('se_pw_appointment'); ?></dt><dd><?php echo $next_appointment ? html_escape(se_ui_when($next_appointment['start_at'])) : '—'; ?></dd></div>
        </dl>
        <div class="se-ctx-actions" style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
          <?php if ($can['manage_consultation'] && in_array($j->state, ['quote_sent', 'quote_accepted', 'quote_revision_requested', 'consultation_recommended'], true)) { echo form_open($act('book_link')), $hidden('chat'), '<button class="se-btn se-btn-secondary se-btn-block" type="submit">📅 ', html_escape(_l('se_journey_send_book_link')), '</button>', form_close(); } ?>
          <?php if ($can['edit_review'] && in_array($j->state, ['welcome_sent', 'privacy_notice_sent', 'consent_pending', 'intake_link_sent', 'intake_started', 'intake_incomplete'], true)) { echo form_open($act('resend_link')), $hidden('chat'), '<button class="se-btn se-btn-secondary se-btn-block" type="submit">🔗 ', html_escape(_l('se_journey_resend_link')), '</button>', form_close(); } ?>
          <?php if ($can['edit_review'] && in_array($j->state, ['quote_sent', 'quote_accepted', 'consultation_recommended', 'consultation_booked'], true)) { echo form_open($act('consultation_info_send')), $hidden('chat'), '<button class="se-btn se-btn-ghost se-btn-block" type="submit">ℹ️ ', html_escape(_l('se_journey_send_consultation_info')), '</button>', form_close(); } ?>
          <?php if ($can['edit_review'] && $j->automation_state !== 'active' && $j->state !== 'opted_out') { echo form_open($act('resume')), $hidden('chat'), '<input type="hidden" name="reason" value="staff_resume" /><button class="se-btn se-btn-secondary se-btn-block" type="submit">▶ ', html_escape(_l('se_journey_resume')), '</button>', form_close(); } ?>
          <?php if ($can['view_photos'] && $photoCount > 0) { echo se_ui_btn(_l('se_journey_tab_photos') . ' (' . $photoCount . ')', $tabUrl('photos'), 'ghost', ['class' => 'se-btn-block']); } ?>
        </div>
      </div>
    </aside>
  </div>

<?php /* ================================ DEĞERLENDİRME ================================ */ ?>
<?php } elseif ($tab === 'intake') { ?>
  <div class="se-grid se-grid-8-4">
    <section class="se-card">
      <h2><?php echo _l('se_journey_tab_intake'); ?><?php if ($intake) { ?> <span class="se-count"><?php echo html_escape($intake->questionnaire_version . ' · ' . _l('se_pw_intake_' . $intake->status) . ' · ' . se_ui_when($intake->submitted_at ?: $intake->last_saved_at)); ?></span><?php } ?></h2>
      <?php if (!$intake) { ?><p class="se-help"><?php echo _l('se_journey_no_intake'); ?></p><?php } else { ?>
        <?php $flags = json_decode((string) $intake->flags_json, true) ?: []; if ($flags) { ?>
          <?php echo se_ui_alert('warning', _l('se_journey_review_flags') . ': ' . implode(', ', array_map(function ($f) { return _l('se_journey_flag_' . preg_replace('/:.*/', '', $f)) . (strpos($f, ':') !== false ? ' ' . substr($f, strpos($f, ':') + 1) : ''); }, $flags)) . ' — ' . _l('se_journey_flags_note')); ?>
        <?php } ?>
        <?php foreach ($sections as $sk => $section) { ?>
          <h3 style="font-size:14px;margin:16px 0 6px"><?php echo html_escape($section['title']); ?></h3>
          <div class="se-tablewrap"><table class="se-table"><tbody>
          <?php foreach ($section['fields'] as $fk => $f) { if (!isset($fields[$fk])) { continue; }
              $v = $answers[$fk] ?? null;
              if (is_array($v)) { $v = implode(', ', array_map(function ($x) use ($f) { return $f['options'][$x] ?? $x; }, $v)); }
              elseif ($v !== null && isset($f['options'][$v])) { $v = $f['options'][$v]; }
              $missing = ($v === null || $v === '') && !empty($f['required']); ?>
            <tr><td style="width:40%"><?php echo html_escape($f['label']); ?></td>
                <td><?php echo $missing ? se_ui_ds_badge('warning', _l('se_journey_missing'), true) : nl2br(html_escape((string) ($v ?? '—'))); ?></td></tr>
          <?php } ?>
          </tbody></table></div>
        <?php } ?>
      <?php } ?>
    </section>
    <aside class="se-stack">
      <div class="se-card">
        <h2><?php echo _l('se_journey_consent'); ?></h2>
        <ul class="se-checks">
          <li><span class="<?php echo $consent['health_data'] ? 'ok' : 'todo'; ?>"><?php echo $consent['health_data'] ? '✓' : '✗'; ?></span> <?php echo _l('se_pw_consent_health'); ?></li>
          <li><span class="<?php echo $consent['photo_publication'] ? 'ok' : 'todo'; ?>"><?php echo $consent['photo_publication'] ? '✓' : '✗'; ?></span> <?php echo _l('se_pw_consent_publication'); ?></li>
          <li><span class="<?php echo $consent['marketing'] ? 'ok' : 'todo'; ?>"><?php echo $consent['marketing'] ? '✓' : '✗'; ?></span> <?php echo _l('se_pw_consent_marketing'); ?></li>
        </ul>
        <p class="se-help"><a href="<?php echo $tabUrl('consent'); ?>"><?php echo _l('se_journey_tab_consent'); ?> →</a></p>
      </div>
    </aside>
  </div>

<?php /* ================================ FOTOĞRAFLAR ================================ */ ?>
<?php } elseif ($tab === 'photos') { ?>
  <div class="se-grid se-grid-8-4">
    <section class="se-card">
      <h2><?php echo _l('se_journey_tab_photos'); ?> <span class="se-count"><?php echo (int) $photoCount; ?>/<?php echo count($reqKinds); ?></span></h2>
      <p><?php foreach ($reqKinds as $k) { echo se_ui_ds_badge($checklist[$k] ? 'positive' : 'warning', _l('se_journey_photo_' . $k), true) . ' '; } ?>
         <?php if ($checklist['_unclassified']) { echo se_ui_ds_badge('info', _l('se_journey_photo_unclassified'), true); } ?></p>
      <?php if (!$media) { ?><p class="se-help"><?php echo _l('se_journey_no_photos'); ?></p><?php } else { ?>
      <div class="se-grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr))">
      <?php foreach ($media as $m) { ?>
        <div class="se-card" style="padding:8px">
          <?php if ($m['view_url'] !== '' && empty($m['deleted_at'])) { ?>
            <a href="<?php echo html_escape($m['view_url']); ?>" target="_blank" rel="noopener noreferrer"><img src="<?php echo html_escape($m['view_url']); ?>" alt="<?php echo html_escape(_l('se_journey_photo_' . $m['kind'])); ?>" style="max-width:100%;max-height:220px;display:block;margin:0 auto;border-radius:6px" /></a>
          <?php } else { ?><p class="se-help"><?php echo html_escape(_l('se_pw_media_' . $m['state']) !== 'se_pw_media_' . $m['state'] ? _l('se_pw_media_' . $m['state']) : $m['state']); ?><?php if (!empty($m['last_error'])) { echo ' — ' . html_escape($m['last_error']); } ?></p><?php } ?>
          <div class="se-help" style="margin-top:6px"><?php echo html_escape(se_ui_when($m['uploaded_at']) . ' · ' . $m['width'] . '×' . $m['height']); ?></div>
          <div style="margin:6px 0"><?php echo se_ui_ds_badge($m['state'] === 'accepted' ? 'positive' : (in_array($m['state'], ['retake_requested', 'fetch_failed'], true) ? 'warning' : 'info'), _l('se_pw_media_' . $m['state']) !== 'se_pw_media_' . $m['state'] ? _l('se_pw_media_' . $m['state']) : $m['state'], true); ?>
            <?php echo se_ui_ds_badge($m['publication_permitted'] ? 'positive' : 'inactive', $m['publication_permitted'] ? _l('se_journey_publication_ok') : _l('se_journey_evaluation_only'), true); ?></div>
          <?php echo form_open($act('photo_classify'), ['style' => 'display:flex;gap:6px']); echo $hidden('photos'); ?><input type="hidden" name="media_id" value="<?php echo (int) $m['id']; ?>" />
            <select name="kind" class="se-input" aria-label="<?php echo _l('se_journey_classify'); ?>">
              <?php foreach (['unclassified', 'frontal', 'left', 'right', 'donor', 'other', 'followup'] as $k) { ?><option value="<?php echo $k; ?>"<?php echo $m['kind'] === $k ? ' selected' : ''; ?>><?php echo html_escape(_l('se_journey_photo_' . $k)); ?></option><?php } ?>
            </select>
            <button class="se-btn se-btn-secondary se-btn-sm" type="submit"><?php echo _l('se_journey_classify'); ?></button>
          <?php echo form_close(); ?>
        </div>
      <?php } ?>
      </div>
      <?php } ?>
    </section>
    <aside class="se-stack">
      <div class="se-card">
        <h2><?php echo _l('se_journey_photo_actions'); ?></h2>
        <div style="display:flex;flex-direction:column;gap:8px">
          <?php echo form_open($act('photos_accept')); echo $hidden('photos'); ?><button class="se-btn se-btn-primary se-btn-block" type="submit">✓ <?php echo _l('se_journey_accept_photos'); ?></button><?php echo form_close(); ?>
          <?php echo form_open($act('photos_ready')); echo $hidden('photos'); ?><button class="se-btn se-btn-secondary se-btn-block" type="submit"><?php echo _l('se_journey_ready_for_review'); ?></button><?php echo form_close(); ?>
          <?php echo form_open($act('photo_donor')); echo $hidden('photos'); ?><button class="se-btn se-btn-ghost se-btn-block" type="submit"><?php echo _l('se_journey_request_donor'); ?></button><?php echo form_close(); ?>
        </div>
        <h3 style="font-size:14px;margin:16px 0 6px"><?php echo _l('se_journey_request_retake'); ?></h3>
        <?php echo form_open($act('photo_retake'), ['class' => 'se-stack']); echo $hidden('photos'); ?>
          <div class="se-field"><label for="se-retake-kind"><?php echo _l('se_journey_photo_actions'); ?></label><select id="se-retake-kind" name="kind" class="se-input"><?php foreach (['frontal', 'left', 'right', 'donor'] as $k) { ?><option value="<?php echo $k; ?>"><?php echo html_escape(_l('se_journey_photo_' . $k)); ?></option><?php } ?></select></div>
          <div class="se-field"><label for="se-retake-reason"><?php echo _l('se_pw_reason'); ?></label><select id="se-retake-reason" name="reason" class="se-input" required><?php foreach ($retake_reasons as $r) { ?><option value="<?php echo $r; ?>"><?php echo html_escape(_l('se_journey_retake_' . $r)); ?></option><?php } ?></select></div>
          <button class="se-btn se-btn-secondary se-btn-block" type="submit"><?php echo _l('se_journey_request_retake'); ?></button>
        <?php echo form_close(); ?>
        <p class="se-help" style="margin-top:10px"><?php echo _l('se_journey_photo_privacy_note'); ?></p>
      </div>
    </aside>
  </div>

<?php /* ================================ İNCELEME / TEKLİF ================================ */ ?>
<?php } elseif ($tab === 'review') { ?>
  <div class="se-grid se-grid-8-4" style="grid-template-columns:1fr 1fr">
    <section class="se-card">
      <h2><?php echo _l('se_journey_review'); ?></h2>
      <?php if ($flags) { echo se_ui_alert('warning', implode(', ', array_map(function ($f) { return _l('se_journey_flag_' . preg_replace('/:.*/', '', $f)); }, $flags)) . ' — ' . _l('se_journey_flags_note')); } ?>
      <?php echo form_open($act('review_save'), ['class' => 'se-stack']); echo $hidden('review'); ?>
        <div class="se-field"><label for="se-rv-notes"><?php echo _l('se_journey_internal_notes'); ?> <span class="se-help">(<?php echo _l('se_journey_never_sent'); ?>)</span></label>
          <textarea id="se-rv-notes" name="internal_notes" class="se-input" rows="4" style="height:auto;padding:10px" <?php echo $can['edit_review'] ? '' : 'disabled'; ?>><?php echo html_escape($review->internal_notes ?? ''); ?></textarea></div>
        <div class="se-grid" style="grid-template-columns:1fr 1fr">
          <div class="se-field"><label for="se-rv-staff"><?php echo _l('se_journey_assignee'); ?></label>
            <select id="se-rv-staff" name="assigned_staff" class="se-input" <?php echo $can['edit_review'] ? '' : 'disabled'; ?>><option value="0">—</option><?php foreach ($staff as $s) { ?><option value="<?php echo (int) $s['staffid']; ?>"<?php echo (int) $j->assigned_staff === (int) $s['staffid'] ? ' selected' : ''; ?>><?php echo html_escape(trim($s['firstname'] . ' ' . $s['lastname'])); ?></option><?php } ?></select></div>
          <div class="se-field"><label for="se-rv-due"><?php echo _l('se_journey_due'); ?></label><input id="se-rv-due" type="datetime-local" name="due_at" class="se-input" value="<?php echo html_escape($review && $review->due_at ? date('Y-m-d\TH:i', strtotime($review->due_at)) : ''); ?>" <?php echo $can['edit_review'] ? '' : 'disabled'; ?> /></div>
        </div>
        <div class="se-field"><label for="se-rv-decision"><?php echo _l('se_journey_decision'); ?></label>
          <select id="se-rv-decision" name="decision" class="se-input" <?php echo $can['edit_review'] ? '' : 'disabled'; ?>>
            <option value=""><?php echo _l('se_journey_no_decision'); ?></option>
            <?php foreach ($decisions as $d) { ?><option value="<?php echo $d; ?>"<?php echo ($review->decision ?? '') === $d ? ' selected' : ''; ?>><?php echo html_escape(_l('se_journey_decision_' . $d)); ?></option><?php } ?>
          </select><span class="se-help"><?php echo _l('se_journey_decision_note'); ?></span></div>
        <div class="se-field"><label class="se-sr" for="se-rv-dn"><?php echo _l('se_journey_decision_note_placeholder'); ?></label><input id="se-rv-dn" type="text" name="decision_note" class="se-input" maxlength="500" placeholder="<?php echo _l('se_journey_decision_note_placeholder'); ?>" <?php echo $can['edit_review'] ? '' : 'disabled'; ?> /></div>
        <label style="display:flex;gap:8px;align-items:center;font-size:14px"><input type="checkbox" name="notify_patient" value="1" <?php echo $can['edit_review'] ? '' : 'disabled'; ?> /> <?php echo _l('se_journey_notify_more_info'); ?></label>
        <?php if ($can['edit_review']) { ?><div><button class="se-btn se-btn-primary" type="submit"><?php echo _l('se_journey_save_review'); ?></button></div><?php } else { ?><p class="se-help"><?php echo _l('se_pw_readonly'); ?></p><?php } ?>
      <?php echo form_close(); ?>
    </section>

    <section class="se-card">
      <h2><?php echo _l('se_journey_quote'); ?>
        <?php if ($quote) { ?><?php echo se_ui_ds_badge($quote->status === 'sent' ? 'info' : ($quote->status === 'approved' ? 'positive' : ($quote->status === 'pending_approval' ? 'action' : 'inactive')), _l('se_journey_quote_status_' . $quote->status) . ' · v' . (int) $quote->version, true); ?><?php } ?>
      </h2>
      <?php if (!$can['edit_review']) { ?>
        <?php /* Sales / read-only (CRM-M049 / UX-Q03): facts, no form */ ?>
        <?php if (!$quote) { ?><p class="se-help"><?php echo _l('se_journey_none'); ?></p><?php } else { ?>
          <dl class="se-facts">
            <div><dt><?php echo _l('se_journey_recommendation'); ?></dt><dd><?php echo html_escape($quote->recommendation === 'consultation' ? _l('se_journey_rec_consultation') : _l('se_journey_rec_procedure')); ?></dd></div>
            <div><dt><?php echo _l('se_journey_valid_until'); ?></dt><dd><?php echo $quote->valid_until ? html_escape(preg_replace('/ \d{2}:\d{2}$/', '', se_ui_when($quote->valid_until . ' 00:00:00'))) : '—'; ?></dd></div>
            <?php if (!empty($quote->show_amount) && $amount_policy !== 'hidden') { ?><div><dt><?php echo _l('se_journey_amount_min'); ?></dt><dd><?php echo html_escape(trim($quote->amount_min . ($amount_policy === 'range' && $quote->amount_max ? ' – ' . $quote->amount_max : '') . ' ' . $quote->currency)); ?></dd></div><?php } ?>
            <?php if ($quote->status === 'sent') { ?><div><dt><?php echo _l('se_journey_patient_response'); ?></dt><dd><?php $resp = (string) ($quote->patient_response ?? ''); echo html_escape($resp === 'accepted' ? _l('se_journey_response_accepted') : ($resp === 'revision_requested' ? _l('se_journey_response_revision') : _l('se_journey_response_none'))); ?></dd></div><?php } ?>
          </dl>
          <p class="se-help" style="margin-top:10px"><?php echo _l('se_pw_readonly'); ?></p>
        <?php } ?>
      <?php } else { ?>
      <?php echo form_open($act('quote_draft'), ['class' => 'se-stack']); echo $hidden('review'); ?>
        <div class="se-grid" style="grid-template-columns:1fr 1fr 1fr">
          <div class="se-field"><label for="se-q-cur"><?php echo _l('se_journey_currency'); ?></label><input id="se-q-cur" name="currency" class="se-input" value="<?php echo html_escape($quote->currency ?? 'TRY'); ?>" maxlength="3" /></div>
          <div class="se-field"><label for="se-q-min"><?php echo _l('se_journey_amount_min'); ?></label><input id="se-q-min" name="amount_min" type="number" step="0.01" class="se-input" value="<?php echo html_escape($quote->amount_min ?? ''); ?>" <?php echo $amount_policy === 'hidden' ? 'disabled' : ''; ?> /></div>
          <div class="se-field"><label for="se-q-max"><?php echo _l('se_journey_amount_max'); ?></label><input id="se-q-max" name="amount_max" type="number" step="0.01" class="se-input" value="<?php echo html_escape($quote->amount_max ?? ''); ?>" <?php echo $amount_policy !== 'range' ? 'disabled' : ''; ?> /></div>
        </div>
        <label style="display:flex;gap:8px;align-items:center;font-size:14px"><input type="checkbox" name="show_amount" value="1" <?php echo !empty($quote->show_amount) ? 'checked' : ''; ?> <?php echo $amount_policy === 'hidden' ? 'disabled' : ''; ?> /> <?php echo _l('se_journey_show_amount'); ?> <span class="se-help">(<?php echo _l('se_journey_amount_policy_' . $amount_policy); ?>)</span></label>
        <div class="se-grid" style="grid-template-columns:1fr 1fr">
          <div class="se-field"><label for="se-q-rec"><?php echo _l('se_journey_recommendation'); ?></label>
            <select id="se-q-rec" name="recommendation" class="se-input"><option value="consultation"<?php echo ($quote->recommendation ?? '') === 'consultation' ? ' selected' : ''; ?>><?php echo _l('se_journey_rec_consultation'); ?></option><option value="procedure_after_consultation"<?php echo ($quote->recommendation ?? '') === 'procedure_after_consultation' ? ' selected' : ''; ?>><?php echo _l('se_journey_rec_procedure'); ?></option></select></div>
          <div class="se-field"><label for="se-q-valid"><?php echo _l('se_journey_valid_until'); ?></label><input id="se-q-valid" type="date" name="valid_until" class="se-input" value="<?php echo html_escape($quote->valid_until ?? ''); ?>" /></div>
        </div>
        <div class="se-grid" style="grid-template-columns:1fr 1fr">
          <div class="se-field"><label for="se-q-inc"><?php echo _l('se_journey_included'); ?></label><textarea id="se-q-inc" name="included" class="se-input" rows="3" style="height:auto;padding:10px"><?php echo html_escape(implode("\n", json_decode((string) ($quote->included_json ?? '[]'), true) ?: [])); ?></textarea></div>
          <div class="se-field"><label for="se-q-exc"><?php echo _l('se_journey_excluded'); ?></label><textarea id="se-q-exc" name="excluded" class="se-input" rows="3" style="height:auto;padding:10px"><?php echo html_escape(implode("\n", json_decode((string) ($quote->excluded_json ?? '[]'), true) ?: [])); ?></textarea></div>
        </div>
        <div class="se-field"><label for="se-q-dep"><?php echo _l('se_journey_deposit_terms'); ?></label><input id="se-q-dep" name="deposit_terms" class="se-input" maxlength="500" value="<?php echo html_escape($quote->deposit_terms ?? ''); ?>" /></div>
        <div class="se-field"><label for="se-q-travel"><?php echo _l('se_journey_travel_notes'); ?></label><textarea id="se-q-travel" name="travel_notes" class="se-input" rows="2" style="height:auto;padding:10px"><?php echo html_escape($quote->travel_notes ?? ''); ?></textarea></div>
        <div class="se-grid" style="grid-template-columns:2fr 1fr">
          <div class="se-field"><label for="se-q-int"><?php echo _l('se_journey_internal_notes'); ?> <span class="se-help">(<?php echo _l('se_journey_never_sent'); ?>)</span></label><input id="se-q-int" name="internal_notes" class="se-input" value="<?php echo html_escape($quote->internal_notes ?? ''); ?>" /></div>
          <div class="se-field"><label for="se-q-margin"><?php echo _l('se_journey_internal_margin'); ?></label><input id="se-q-margin" name="internal_margin" class="se-input" value="<?php echo html_escape($quote->internal_margin ?? ''); ?>" /></div>
        </div>
        <div><button class="se-btn se-btn-secondary" type="submit"><?php echo $quote && $quote->status === 'sent' ? _l('se_journey_new_version') : _l('se_journey_save_draft'); ?></button></div>
      <?php echo form_close(); ?>

      <?php if ($quote) { ?>
      <div class="se-row-actions" style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <?php if ($quote->status === 'draft') { echo form_open($act('quote_request')), $hidden('review'), '<input type="hidden" name="quote_id" value="', (int) $quote->id, '" /><button class="se-btn se-btn-secondary se-btn-sm" type="submit">', html_escape(_l('se_journey_request_approval')), '</button>', form_close(); } ?>
        <?php if (in_array($quote->status, ['draft', 'pending_approval'], true)) {
            if ($can['approve_quote']) { echo form_open($act('quote_approve')), $hidden('review'), '<input type="hidden" name="quote_id" value="', (int) $quote->id, '" /><button class="se-btn se-btn-primary se-btn-sm" type="submit">✓ ', html_escape(_l('se_journey_approve_quote')), '</button>', form_close(); }
            else { echo se_ui_ds_badge('action', _l('se_journey_needs_approver'), true); } } ?>
        <?php if ($quote->status === 'approved') { ?>
          <span class="se-help"><?php echo _l('se_journey_approved_by'); ?> <?php echo html_escape($staffName($quote->approved_by)); ?> · <?php echo html_escape(se_ui_when($quote->approved_at)); ?></span>
          <?php echo form_open($act('quote_send')), $hidden('review'), '<input type="hidden" name="quote_id" value="', (int) $quote->id, '" /><button class="se-btn se-btn-primary se-btn-sm" type="submit">➤ ', html_escape(_l('se_journey_send_quote')), '</button>', form_close(); ?>
        <?php } ?>
        <?php if ($quote->status === 'sent') { ?>
          <span class="se-help"><?php echo _l('se_journey_sent_at'); ?> <?php echo html_escape(se_ui_when($quote->sent_at)); ?></span>
          <?php $resp = (string) ($quote->patient_response ?? ''); echo se_ui_ds_badge($resp === 'accepted' ? 'positive' : ($resp === 'revision_requested' ? 'warning' : 'info'), _l('se_journey_patient_response') . ': ' . ($resp === 'accepted' ? _l('se_journey_response_accepted') : ($resp === 'revision_requested' ? _l('se_journey_response_revision') : _l('se_journey_response_none'))), true); ?>
          <?php if ($can['manage_consultation'] && in_array($j->state, ['quote_sent', 'quote_accepted', 'quote_revision_requested', 'consultation_recommended'], true)) { echo form_open($act('book_link')), $hidden('review'), '<button class="se-btn se-btn-secondary se-btn-sm" type="submit">📅 ', html_escape(_l('se_journey_send_book_link')), '</button>', form_close(); } ?>
          <?php echo form_open($act('consultation_info_send')), $hidden('review'), '<button class="se-btn se-btn-ghost se-btn-sm" type="submit">ℹ️ ', html_escape(_l('se_journey_send_consultation_info')), '</button>', form_close(); ?>
        <?php } ?>
      </div>
      <?php if ($quote->status === 'sent') { ?><details style="margin-top:10px"><summary class="se-help"><?php echo _l('se_journey_snapshot'); ?> · <code><?php echo html_escape(substr((string) $quote->snapshot_hash, 0, 16)); ?></code></summary><pre style="white-space:pre-wrap;font-size:11px"><?php echo html_escape(json_encode(json_decode((string) $quote->snapshot_json, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details><?php } ?>
      <?php } ?>
      <?php if (count($quotes) > 1) { ?><p class="se-help" style="margin-top:10px"><?php foreach ($quotes as $q) { echo 'v' . (int) $q['version'] . ' ' . html_escape(_l('se_journey_quote_status_' . $q['status'])) . ' · '; } ?></p><?php } ?>
      <?php } ?>
    </section>
  </div>

<?php /* ================================ RANDEVU · İŞLEM · BAKIM ================================ */ ?>
<?php } elseif ($tab === 'care') { ?>
  <div class="se-grid se-grid-8-4" style="grid-template-columns:1fr 1fr">
    <section class="se-card">
      <h2><?php echo _l('se_journey_appointments'); ?></h2>
      <?php if (!$appointments) { ?><p class="se-help"><?php echo _l('se_journey_none'); ?></p><?php } else { ?>
        <ul class="se-mini">
        <?php foreach ($appointments as $a) { ?>
          <li style="flex-wrap:wrap">
            <span class="t"><?php echo html_escape(se_ui_when($a['start_at'])); ?></span>
            <a class="n" href="<?php echo admin_url('se_appointments/se_appointments/view/' . (int) $a['id']); ?>"><?php echo html_escape(function_exists('se_appt_type_label') ? se_appt_type_label($a['appointment_type'] ?? '') : ($a['appointment_type'] ?? '')); ?></a>
            <?php echo se_ui_ds_badge(in_array($a['status'], ['cancelled', 'no_show'], true) ? 'danger' : (in_array($a['status'], ['held', 'completed'], true) ? 'positive' : 'info'), _l('se_appt_status_' . $a['status']) !== 'se_appt_status_' . $a['status'] ? _l('se_appt_status_' . $a['status']) : $a['status'], true); ?>
            <span class="m"><?php echo html_escape(($a['consultation_format'] ?? '') === 'online' ? _l('se_journey_online') : ($a['location'] ?? '')); ?></span>
            <?php if ($can['manage_consultation'] && !in_array($a['status'], ['cancelled', 'completed'], true)) { ?>
              <?php echo form_open($act('appointment'), ['style' => 'display:flex;gap:6px;flex-basis:100%;margin-top:6px']); echo $hidden('care'); ?><input type="hidden" name="appointment_id" value="<?php echo (int) $a['id']; ?>" />
                <select name="status" class="se-input" aria-label="<?php echo _l('se_journey_update'); ?>"><?php foreach (['scheduled', 'confirmed', 'held', 'completed', 'no_show', 'cancelled'] as $s) { ?><option value="<?php echo $s; ?>"<?php echo $a['status'] === $s ? ' selected' : ''; ?>><?php echo html_escape(_l('se_appt_status_' . $s) !== 'se_appt_status_' . $s ? _l('se_appt_status_' . $s) : $s); ?></option><?php } ?></select>
                <input type="text" name="outcome_note" class="se-input" placeholder="<?php echo _l('se_journey_outcome'); ?>" aria-label="<?php echo _l('se_journey_outcome'); ?>" />
                <button class="se-btn se-btn-secondary se-btn-sm" type="submit"><?php echo _l('se_journey_update'); ?></button>
              <?php echo form_close(); ?>
            <?php } ?>
          </li>
        <?php } ?>
        </ul>
      <?php } ?>
      <?php if ($can['manage_consultation'] && in_array($j->state, ['quote_sent', 'quote_accepted', 'quote_revision_requested', 'consultation_recommended'], true)) { echo form_open($act('book_link'), ['style' => 'margin-top:10px']), $hidden('care'), '<button class="se-btn se-btn-secondary se-btn-sm" type="submit">📅 ', html_escape(_l('se_journey_send_book_link')), '</button>', form_close(); } ?>
      <?php if ($can['manage_consultation']) { ?>
        <h3 style="font-size:14px;margin:16px 0 6px"><?php echo _l('se_journey_book'); ?></h3>
        <?php if ((int) $j->lead_id > 0 && staff_can('create', 'se_appointments')) { echo se_ui_btn(_l('se_pw_book_in_calendar'), admin_url('se_appointments/create?lead=' . (int) $j->lead_id . '&journey=' . $jid), 'primary', ['icon' => '＋']); } ?>
        <details style="margin-top:8px"><summary class="se-help"><?php echo _l('se_pw_book_quick'); ?></summary>
        <?php echo form_open($act('book'), ['class' => 'se-stack', 'style' => 'margin-top:8px']); echo $hidden('care'); ?>
          <div class="se-grid" style="grid-template-columns:1fr 1fr 1fr">
            <div class="se-field"><label for="se-b-type"><?php echo _l('se_journey_type'); ?></label><select id="se-b-type" name="type" class="se-input"><option value="consultation"><?php echo _l('se_journey_consultation'); ?></option><option value="procedure"><?php echo _l('se_journey_procedure'); ?></option></select></div>
            <div class="se-field"><label for="se-b-start"><?php echo _l('se_journey_start'); ?></label><input id="se-b-start" type="datetime-local" name="start_at" class="se-input" required /></div>
            <div class="se-field"><label for="se-b-end"><?php echo _l('se_journey_end'); ?></label><input id="se-b-end" type="datetime-local" name="end_at" class="se-input" /></div>
          </div>
          <div class="se-grid" style="grid-template-columns:1fr 1fr 1fr">
            <div class="se-field"><label for="se-b-staff"><?php echo _l('se_journey_staff'); ?></label><select id="se-b-staff" name="staff_id" class="se-input"><?php foreach ($staff as $s) { ?><option value="<?php echo (int) $s['staffid']; ?>"><?php echo html_escape(trim($s['firstname'] . ' ' . $s['lastname'])); ?></option><?php } ?></select></div>
            <div class="se-field"><label for="se-b-fmt"><?php echo _l('se_journey_format'); ?></label><select id="se-b-fmt" name="consultation_format" class="se-input"><option value="in_person"><?php echo _l('se_journey_in_person'); ?></option><option value="online"><?php echo _l('se_journey_online'); ?></option></select></div>
            <div class="se-field"><label for="se-b-dep"><?php echo _l('se_journey_deposit'); ?></label><select id="se-b-dep" name="deposit_state" class="se-input"><option value="">—</option><?php foreach (['none', 'requested', 'received', 'refunded'] as $d) { ?><option value="<?php echo $d; ?>"><?php echo html_escape(_l('se_pw_deposit_' . $d)); ?></option><?php } ?></select></div>
          </div>
          <div class="se-field"><label class="se-sr" for="se-b-loc"><?php echo _l('se_journey_location'); ?></label><input id="se-b-loc" type="text" name="location" class="se-input" placeholder="<?php echo _l('se_journey_location'); ?>" /></div>
          <div class="se-field"><label class="se-sr" for="se-b-pay"><?php echo _l('se_journey_payment_ref'); ?></label><input id="se-b-pay" type="text" name="payment_ref" class="se-input" placeholder="<?php echo _l('se_journey_payment_ref'); ?>" /></div>
          <div><button class="se-btn se-btn-secondary" type="submit"><?php echo _l('se_journey_book'); ?></button></div>
        <?php echo form_close(); ?>
        </details>
      <?php } ?>
    </section>

    <section class="se-card">
      <h2><?php echo _l('se_journey_procedure'); ?></h2>
      <?php if ($can['manage_consultation']) { ?>
        <?php if ($j->state === 'procedure_booked') { echo form_open($act('preop_start')), $hidden('care'), '<button class="se-btn se-btn-secondary se-btn-sm" type="submit">', html_escape(_l('se_journey_start_preop')), '</button>', form_close(); } ?>
        <?php if (in_array($j->state, ['preop_pending', 'procedure_booked'], true)) { ?>
          <ul class="se-checks" style="margin-top:8px"><?php foreach ($preop as $item) { ?><li><span class="todo">○</span> <?php echo html_escape($item); ?></li><?php } ?></ul>
          <?php echo form_open($act('procedure_complete'), ['class' => 'se-stack', 'style' => 'margin-top:8px']); echo $hidden('care'); ?>
            <div class="se-field"><label for="se-p-at"><?php echo _l('se_journey_procedure_at'); ?></label><input id="se-p-at" type="datetime-local" name="procedure_at" class="se-input" /></div>
            <div class="se-field"><label class="se-sr" for="se-p-notes"><?php echo _l('se_journey_procedure_notes'); ?></label><textarea id="se-p-notes" name="notes" class="se-input" rows="3" style="height:auto;padding:10px" placeholder="<?php echo _l('se_journey_procedure_notes'); ?>"></textarea></div>
            <div><button class="se-btn se-btn-primary" type="submit"><?php echo _l('se_journey_procedure_complete'); ?></button></div>
          <?php echo form_close(); ?>
        <?php } ?>
        <?php if ($j->procedure_at) { ?><p class="se-help"><?php echo _l('se_journey_procedure_at'); ?>: <?php echo html_escape(se_ui_when($j->procedure_at)); ?> · <?php echo _l('se_journey_deposit'); ?>: <?php echo html_escape($j->deposit_state ? _l('se_pw_deposit_' . $j->deposit_state) : '—'); ?></p><?php } ?>
      <?php } ?>

      <h2 style="margin-top:16px"><?php echo _l('se_journey_aftercare'); ?></h2>
      <?php if ($can['manage_aftercare'] && in_array($j->state, ['procedure_completed', 'aftercare_active', 'completed'], true)) { ?>
        <?php echo form_open($act('aftercare_start'), ['style' => 'display:flex;gap:8px']); echo $hidden('care'); ?>
          <select name="protocol" class="se-input" aria-label="<?php echo _l('se_journey_aftercare'); ?>"><?php foreach ($protocols as $pr) { ?><option value="<?php echo html_escape($pr['key']); ?>"><?php echo html_escape($pr['name'] . ' v' . $pr['version'] . ($pr['approved'] ? '' : ' — ' . _l('se_journey_unapproved'))); ?></option><?php } ?></select>
          <button class="se-btn se-btn-secondary" type="submit"><?php echo _l('se_journey_start_aftercare'); ?></button>
        <?php echo form_close(); ?>
      <?php } ?>
      <?php if (!$aftercare) { ?><p class="se-help"><?php echo _l('se_journey_none'); ?></p><?php } else { ?>
        <ul class="se-mini" style="margin-top:8px">
        <?php foreach ($aftercare as $e) { ?>
          <li style="flex-wrap:wrap"><span class="t"><?php echo html_escape(se_ui_when($e['due_at'])); ?></span><span class="n"><?php echo html_escape($e['label']); ?></span>
              <?php echo se_ui_ds_badge($e['state'] === 'answered' ? 'positive' : (in_array($e['state'], ['unanswered', 'blocked'], true) ? 'warning' : 'info'), _l('se_pw_ac_' . $e['state']) !== 'se_pw_ac_' . $e['state'] ? _l('se_pw_ac_' . $e['state']) : $e['state'], true); ?>
              <?php if ($e['state'] === 'answered' && $can['view_health'] && !empty($e['reply_enc'])) { se_journey_audit((int) $j->brand_id, $jid, 'view_checkin', 'aftercare_event', (string) $e['id']); echo '<div class="se-help" style="flex-basis:100%">' . nl2br(html_escape(se_journey_aftercare_reply_text($e))) . '</div>'; } ?></li>
        <?php } ?>
        </ul>
      <?php } ?>
      <?php if ($can['manage_aftercare'] && in_array($j->state, ['aftercare_active', 'followup_due'], true)) { echo form_open($act('complete'), ['style' => 'margin-top:10px']), $hidden('care'), '<button class="se-btn se-btn-primary se-btn-sm" type="submit">🏁 ', html_escape(_l('se_journey_mark_completed')), '</button>', form_close(); } ?>
    </section>
  </div>

<?php /* ================================ KVKK ================================ */ ?>
<?php } elseif ($tab === 'consent') { ?>
  <div class="se-grid se-grid-8-4">
    <section class="se-card">
      <h2><?php echo _l('se_journey_tab_consent'); ?><?php if ($consent['version']) { ?> <span class="se-count"><?php echo html_escape($consent['version']); ?></span><?php } ?></h2>
      <ul class="se-checks">
        <li><span class="<?php echo $consent['health_data'] ? 'ok' : 'todo'; ?>"><?php echo $consent['health_data'] ? '✓' : '✗'; ?></span> <?php echo _l('se_pw_consent_health'); ?> — <span class="se-help"><?php echo _l('se_pw_consent_health_hint'); ?></span></li>
        <li><span class="<?php echo $consent['photo_publication'] ? 'ok' : 'todo'; ?>"><?php echo $consent['photo_publication'] ? '✓' : '✗'; ?></span> <?php echo _l('se_pw_consent_publication'); ?> — <span class="se-help"><?php echo _l('se_pw_consent_publication_hint'); ?></span></li>
        <li><span class="<?php echo $consent['marketing'] ? 'ok' : 'todo'; ?>"><?php echo $consent['marketing'] ? '✓' : '✗'; ?></span> <?php echo _l('se_pw_consent_marketing'); ?> — <span class="se-help"><?php echo _l('se_pw_consent_marketing_hint'); ?></span></li>
        <li><span class="<?php echo $consent['whatsapp'] ? 'ok' : 'todo'; ?>"><?php echo $consent['whatsapp'] ? '✓' : '✗'; ?></span> <?php echo _l('se_pw_consent_whatsapp'); ?></li>
      </ul>
      <p class="se-help" style="margin-top:10px"><?php echo _l('se_pw_consent_note'); ?></p>
    </section>
    <aside class="se-stack">
      <?php if ($can['export_health']) { ?>
      <div class="se-card">
        <h2><?php echo _l('se_journey_export'); ?></h2>
        <p class="se-help"><?php echo _l('se_journey_export_audited'); ?></p>
        <?php echo form_open(admin_url('se_journey/se_journey/export/' . $jid)); ?><button class="se-btn se-btn-secondary" type="submit">⬇ <?php echo _l('se_journey_export'); ?></button><?php echo form_close(); ?>
      </div>
      <?php } ?>
      <?php if ($can['manage_consent']) { ?>
      <div class="se-card"><h2><?php echo _l('se_pw_consent_texts'); ?></h2><p class="se-help"><?php echo _l('se_pw_consent_texts_hint'); ?></p><?php echo se_ui_btn(_l('se_pw_consent_settings'), admin_url('se_core/se_consent'), 'ghost'); ?></div>
      <?php } ?>
    </aside>
  </div>
<?php } ?>

</div></div>
<?php init_tail(); ?></body></html>
