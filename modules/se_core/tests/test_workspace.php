<?php
/**
 * Patient workspace data (CRM-M026 human timeline, CRM-M025 identity).
 * The timeline never shows raw kinds, hides noise, orders newest first and
 * keeps previews short; the view's tab list needs no data beyond this.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$db = se_test_db();
$db->tables = []; $db->autoinc = [];
$db->seed('tblse_brands', [['id' => 1, 'name' => 'A', 'active' => 1]]);
$db->seed('tblse_staff_brands', [['staff_id' => 10, 'brand_id' => 1]]);
$db->seed('tblstaff', [['staffid' => 10, 'firstname' => 'Azin', 'lastname' => 'Asgari']]);
$db->seed('tblse_wa_messages', [
    ['id' => 1, 'brand_id' => 1, 'conversation_id' => 7, 'direction' => 'in', 'type' => 'text', 'body' => 'Merhaba bilgi almak istiyorum', 'received_at' => '2026-09-04 09:00:00', 'sent_at' => null, 'date_created' => '2026-09-04 09:00:00', 'origin' => '', 'delivery_state' => ''],
    ['id' => 2, 'brand_id' => 1, 'conversation_id' => 7, 'direction' => 'out', 'type' => 'text', 'body' => 'Hoş geldiniz…', 'received_at' => null, 'sent_at' => '2026-09-04 09:01:00', 'date_created' => '2026-09-04 09:01:00', 'origin' => 'journey:welcome', 'delivery_state' => 'read'],
    ['id' => 3, 'brand_id' => 1, 'conversation_id' => 7, 'direction' => 'in', 'type' => 'image', 'body' => '', 'received_at' => '2026-09-04 10:00:00', 'sent_at' => null, 'date_created' => '2026-09-04 10:00:00', 'origin' => '', 'delivery_state' => ''],
    ['id' => 4, 'brand_id' => 1, 'conversation_id' => 7, 'direction' => 'out', 'type' => 'text', 'body' => str_repeat('x', 400), 'received_at' => null, 'sent_at' => '2026-09-04 10:30:00', 'date_created' => '2026-09-04 10:30:00', 'origin' => 'staff', 'staff_id' => 10, 'delivery_state' => 'failed'],
    ['id' => 5, 'brand_id' => 2, 'conversation_id' => 7, 'direction' => 'in', 'type' => 'text', 'body' => 'other brand', 'received_at' => '2026-09-04 11:00:00', 'sent_at' => null, 'date_created' => '2026-09-04 11:00:00', 'origin' => '', 'delivery_state' => ''],
]);
$db->seed('tblse_journey_transitions', [
    ['id' => 1, 'journey_id' => 5, 'from_state' => 'new_whatsapp_enquiry', 'to_state' => 'welcome_sent', 'trigger_key' => 'auto', 'actor_type' => 'system', 'actor_id' => null, 'note' => '', 'created_at' => '2026-09-04 09:01:00'],
    ['id' => 2, 'journey_id' => 5, 'from_state' => 'photos_requested', 'to_state' => 'ready_for_review', 'trigger_key' => 'photos', 'actor_type' => 'patient', 'actor_id' => null, 'note' => '', 'created_at' => '2026-09-04 10:05:00'],
]);
$db->seed('tblse_journey_events', [
    ['id' => 1, 'journey_id' => 5, 'kind' => 'token_issued', 'actor_type' => 'system', 'actor_id' => null, 'summary' => 'tok', 'created_at' => '2026-09-04 09:02:00'],
    ['id' => 2, 'journey_id' => 5, 'kind' => 'note', 'actor_type' => 'staff', 'actor_id' => '10', 'summary' => 'Aradım, yarın dönecek', 'created_at' => '2026-09-04 11:30:00'],
    ['id' => 3, 'journey_id' => 5, 'kind' => 'lead_sync', 'actor_type' => 'system', 'actor_id' => null, 'summary' => 'synced', 'created_at' => '2026-09-04 11:31:00'],
]);
$db->seed('tblse_appointment_status_history', [
    ['id' => 1, 'brand_id' => 1, 'appointment_id' => 40, 'old_status' => '', 'new_status' => 'scheduled', 'changed_by' => 10, 'changed_at' => '2026-09-04 12:00:00'],
]);
$j = (object) ['id' => 5, 'brand_id' => 1, 'wa_conversation_id' => 7, 'consultation_appointment_id' => 40, 'procedure_appointment_id' => 0];

se_group('Human timeline');
$tl = se_journey_timeline_human($j, 50);
$labels = array_map(function ($i) { return $i['label']; }, $tl);
se_eq(['se_tl_appt_scheduled', 'se_ev_note', 'se_ev_out_generic', 'se_ev_photos_ready', 'se_ev_in_image', 'se_ev_welcome', 'se_ev_welcome', 'se_ev_in_text'], $labels, 'newest first; transitions read as the resulting state; outbound journey sends resolve through origin; noise (token, lead sync, other brand) hidden');
foreach ($tl as $it) {
    se_ok(!preg_match('/^(wa_|transition|event|appointment #)/', $it['label']), 'no raw kind leaks: ' . $it['label']);
    se_ok(strpos($it['label'], ' → ') === false, 'no "a → b" arrows: ' . $it['label']);
}
se_eq('Aradım, yarın dönecek', $tl[1]['text'], 'note text is shown');
se_eq('Azin A.', $tl[1]['actor'], 'staff actor resolves to a short name');
se_eq('se_tl_actor_patient', $tl[7]['actor'], 'inbound → patient');
se_eq('se_tl_actor_auto', $tl[6]['actor'], 'journey send → automatic');
se_eq(160, mb_strlen($tl[2]['text']), 'outbound preview is truncated to 160 chars');
se_eq('danger', $tl[2]['tone'], 'a failed delivery is flagged');
se_eq('', $tl[4]['text'], 'an image has no text preview');
se_eq(3, count(se_journey_timeline_human($j, 3)), 'limit honoured');

se_group('Identity helpers used by the header');
se_eq('AY', se_ui_initials('Ayşe Yılmaz'), 'initials');
se_eq('Ayşe Y.', se_ui_short_name('Ayşe Yılmaz'), 'short name');
se_eq('+90 5•• ••• 22 33', se_ui_phone('+90 555 111 22 33', true, false), 'masked phone for staff without the health capability');
se_eq('+90 555 111 22 33', se_ui_phone('05551112233', false, false), 'normalised phone with the capability');
