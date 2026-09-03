<?php
/**
 * Next-action engine (CRM-M017 / UX-F03 / UX-QA03): every state × timing
 * yields the documented sentence key, owner and priority (UX-COPY §4).
 * _l() returns the key in the harness, so sentences are asserted by key.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$db = se_test_db();
$db->tables = []; $db->autoinc = [];
$db->seed('tblse_journey_quotes', []);
$db->seed('tblse_appointments', []);
$db->seed('tblse_wa_outbound', []);
$GLOBALS['se_test']['options'] = [];

$now = strtotime('2026-09-04 12:00:00');
$J = function ($state, $ageSeconds = 600, $extra = []) use ($now) {
    return (object) array_merge([
        'id' => 7, 'brand_id' => 1, 'lead_id' => 101, 'state' => $state, 'state_changed_at' => date('Y-m-d H:i:s', $now - $ageSeconds),
        'last_updated' => date('Y-m-d H:i:s', $now - $ageSeconds), 'date_created' => date('Y-m-d H:i:s', $now - 86400),
        'automation_state' => 'active', 'automation_changed_at' => null, 'urgent' => 0, 'reminder_count' => 0,
        'consultation_appointment_id' => 0, 'procedure_appointment_id' => 0, 'wa_conversation_id' => 0,
    ], $extra);
};

se_group('Next action: table rows from UX-COPY §4');
$rows = [
    // state, age, ctx, expected key, owner, priority, sentence key, button key
    ['ready_for_review', 42 * 60, [], 'review', 'staff', 2, 'se_na_review', 'se_na_btn_review_photos'],
    ['ready_for_review', 5 * 86400, [], 'review', 'staff', 1, 'se_na_review', 'se_na_btn_review_photos'],
    ['under_review', 3600, [], 'decision', 'staff', 2, 'se_na_decision', 'se_na_btn_record_decision'],
    ['quote_pending_staff_approval', 3 * 3600, ['quote' => (object) ['version' => 2]], 'quote_approve', 'staff', 2, 'se_na_quote_approve', 'se_na_btn_approve_quote'],
    ['quote_sent', 3600, ['quote' => (object) ['version' => 1, 'sent_at' => date('Y-m-d H:i:s', $now - 3600), 'valid_until' => date('Y-m-d', $now + 20 * 86400)]], 'quote_wait', 'patient', 3, 'se_na_wait_patient', ''],
    ['quote_sent', 4 * 86400, ['quote' => (object) ['version' => 1, 'sent_at' => date('Y-m-d H:i:s', $now - 4 * 86400), 'valid_until' => date('Y-m-d', $now + 20 * 86400)]], 'quote_followup', 'staff', 3, 'se_na_quote_followup', 'se_na_btn_remind'],
    ['quote_sent', 40 * 86400, ['quote' => (object) ['version' => 1, 'sent_at' => date('Y-m-d H:i:s', $now - 40 * 86400), 'valid_until' => date('Y-m-d', $now - 86400)]], 'quote_expired', 'staff', 2, 'se_na_quote_expired', 'se_na_btn_new_version'],
    ['quote_revision_requested', 86400, [], 'quote_revise', 'staff', 2, 'se_na_quote_revise', 'se_na_btn_new_version'],
    ['consultation_recommended', 2 * 86400, [], 'book_consult', 'staff', 2, 'se_na_book_consult', 'se_na_btn_book'],
    ['consultation_booked', 600, ['appointment' => (object) ['start_at' => date('Y-m-d H:i:s', $now + 86400), 'end_at' => date('Y-m-d H:i:s', $now + 86400 + 1800)]], 'consult_wait', 'none', 3, 'se_na_consult_booked', ''],
    ['consultation_booked', 3 * 86400, ['appointment' => (object) ['start_at' => date('Y-m-d H:i:s', $now - 5 * 3600), 'end_at' => date('Y-m-d H:i:s', $now - 4 * 3600)]], 'held_unrecorded', 'staff', 2, 'se_na_record_outcome', 'se_na_btn_record_outcome'],
    ['consultation_completed', 600, [], 'after_consult', 'staff', 2, 'se_na_after_consult', 'se_na_btn_plan_today'],
    ['procedure_completed', 2 * 86400, [], 'start_aftercare', 'staff', 2, 'se_na_start_aftercare', 'se_na_btn_start_plan'],
    ['followup_due', 3600, [], 'followup', 'staff', 2, 'se_na_followup', 'se_na_btn_write'],
    ['consent_pending', 3600, [], 'intake_wait', 'patient', 3, 'se_na_wait_patient', 'se_na_btn_remind_now'],
    ['photos_requested', 3600, [], 'photos_wait', 'patient', 3, 'se_na_wait_patient', 'se_na_btn_remind_now'],
    ['new_whatsapp_enquiry', 300, [], 'new', 'staff', 2, 'se_na_new', 'se_na_btn_start'],
    ['welcome_sent', 2 * 86400, [], 'welcome_stale', 'staff', 3, 'se_na_welcome_stale', 'se_na_btn_write'],
    ['aftercare_active', 600, [], 'aftercare', 'none', 3, 'se_na_aftercare', ''],
    ['completed', 600, [], 'terminal', 'none', 3, '', ''],
];
foreach ($rows as $r) {
    [$state, $age, $ctx, $key, $owner, $prio, $sentence, $btn] = $r;
    $na = se_journey_next_action($J($state, $age), $ctx, $now);
    se_eq($key, $na['key'], "$state @{$age}s → key $key");
    se_eq($owner, $na['owner'], "$state @{$age}s → owner $owner");
    se_eq($prio, $na['priority'], "$state @{$age}s → priority $prio");
    se_eq($sentence, $na['sentence'], "$state @{$age}s → sentence");
    se_eq($btn, $na['action_label'], "$state @{$age}s → button");
}

se_group('Next action: overrides');
$na = se_journey_next_action($J('quote_sent', 600, ['urgent' => 1]), [], $now);
se_eq(['urgent', 1], [$na['key'], $na['priority']], 'an urgent flag beats any state, priority 1');
$na = se_journey_next_action($J('photos_requested', 600), ['wa_failed' => true], $now);
se_eq(['wa_failed', 1], [$na['key'], $na['priority']], 'a failed send beats the state');
$na = se_journey_next_action($J('photos_requested', 600, ['automation_state' => 'paused_staff', 'automation_changed_at' => date('Y-m-d H:i:s', $now - 2 * 86400)]), [], $now);
se_eq('paused', $na['key'], 'a stale staff pause becomes the next action');
$na = se_journey_next_action($J('photos_requested', 600, ['automation_state' => 'paused_staff', 'automation_changed_at' => date('Y-m-d H:i:s', $now - 3600)]), [], $now);
se_eq('photos_wait', $na['key'], 'a fresh pause (1 h) does not yet nag');
se_ok(strlen($na['sentence']) > 0, 'every non-terminal state has a sentence');

se_group('Timeline labels never show raw codes');
se_eq('se_ev_quote_sent', se_journey_event_label('transition', ['from' => 'quote_pending_staff_approval', 'to' => 'quote_sent']), 'a transition reads as its result');
se_eq('se_ev_in_image', se_journey_event_label('wa_inbound', ['detail' => 'image']), 'inbound image');
se_eq('se_ev_welcome', se_journey_event_label('wa_outbound', ['detail' => 'welcome (inwindow)']), 'outbound copy key resolved from the first word');
se_eq('', se_journey_event_label('flow_step', ['detail' => 'intake HEALTH_A']), 'flow steps are hidden');
se_eq('', se_journey_event_label('lead_sync', []), 'lead sync rows are hidden');
se_eq('se_ev_wa_failed (131026)', se_journey_event_label('wa_delivery_failed', ['detail' => '131026']), 'delivery failure keeps the Meta code');

se_group('UI helper: state map covers every journey state');
foreach (se_journey_states() as $s) {
    se_ok(isset(se_ui_state_map()[$s]), "state map has $s");
}
se_eq('review', se_ui_stage_of('ready_for_review'), 'ready_for_review → review stage');
se_eq('action', se_ui_state_tone('quote_pending_staff_approval'), 'approval pending is an action tone');
se_ok(strpos(se_ui_stages('quote_sent'), 'aria-current="step"') !== false, 'stage bar marks the current stage');
se_eq(3, substr_count(se_ui_stages('quote_sent'), 'class="done"'), 'three stages done before quote');

se_group('UI helper: phone, age, names');
se_eq('+90 555 111 22 33', se_ui_phone('+905551112233', false, false), 'E.164 → grouped');
se_eq('+90 5•• ••• 22 33', se_ui_phone('905551112233', true, false), 'masked keeps the last four');
se_eq('+90 555 111 22 33', se_ui_phone('0555 111 22 33', false, false), 'national format normalised');
se_ok(strpos(se_ui_phone('905551112233'), '<bdi dir="ltr"') === 0, 'html form is bidi-isolated');
se_eq('18 se_ui_age_min', se_ui_age($now - 18 * 60, $now), 'minutes');
se_eq('3 se_ui_age_hour', se_ui_age($now - 3 * 3600, $now), 'hours');
se_eq('2 se_ui_age_day', se_ui_age($now - 2 * 86400, $now), 'days');
se_eq('Ayşe Y.', se_ui_short_name('Ayşe Yılmaz'), 'short name');
se_eq('AY', se_ui_initials('Ayşe Yılmaz'), 'initials');
se_eq('?', se_ui_initials(''), 'no name → ?');
