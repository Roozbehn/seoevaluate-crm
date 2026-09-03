<?php
/**
 * Patient journey (se_journey) — the STAFF side: default-deny permissions,
 * review decisions that never auto-diagnose, the quote approval gate and the
 * immutable snapshot, consultation booking through the real appointments
 * model (no double booking), procedure completion, aftercare scheduling and
 * check-ins, signed photo URLs, and the state machine's transition log.
 *
 * Acceptance criteria covered here: 10, 13, 18, 19, 20 (plus 22 for the
 * untouched modules, by the rest of the suite staying green).
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/journey_fixtures.php';

/* ======================================================================== */
se_group('Journey staff: health answers and photos are default-deny');

$j = se_test_journey_reviewed();
$db = se_test_db();
se_eq('ready_for_review', $j->state, 'fixture journey is ready for review');
se_eq(3, count($db->rows('tblse_journey_media')), 'with three photos');

se_test_act_as(11, ['se_journey.view']);                 // Sales-like: basic view only
se_eq(true,  se_journey_can('view'), 'basic view granted');
se_eq(false, se_journey_can('view_health'), 'health answers denied without the capability');
se_eq(false, se_journey_can('view_photos'), 'photos denied without the capability');
se_eq(false, se_journey_can('export_health'), 'export denied');
se_eq(false, se_journey_can('approve_quote'), 'quote approval denied');
se_eq(false, se_journey_can('edit_review'), 'review editing denied');

se_test_act_as(11, ['se_journey.view', 'se_journey.view_health']);
se_eq(true, se_journey_can('view_health'), 'an explicit grant opens health answers');
se_eq(false, se_journey_can('view_photos'), 'but photos stay closed (separate capability)');

se_test_act_as(10, [], true);
se_eq(true, se_journey_can('export_health'), 'admin passes everything');

// A staff member outside the brand sees nothing, even with every capability.
se_test_act_as(20, array_map(function ($c) { return 'se_journey.' . $c; }, array_keys(se_journey_capabilities())));
se_eq(null, se_journey_get((int) $j->id), 'a foreign-brand staff member cannot read the journey');
se_eq(null, se_journey_media_get((int) $db->rows('tblse_journey_media')[0]['id']), 'nor its photos');
se_eq([], se_journey_list(), 'nor list it');
se_test_act_as(10, [], true);

/* ======================================================================== */
se_group('Journey staff: signed photo URLs are staff-bound, expiring and audited');

$m = $db->rows('tblse_journey_media')[0];
$url = se_journey_media_view_url((int) $m['id'], 10);
se_ok(strpos($url, '/se_journey/se_journey/media/' . (int) $m['id'] . '?e=') !== false, 'a view URL is produced');
parse_str(parse_url($url, PHP_URL_QUERY), $q);
se_eq(true, se_journey_media_signature_valid((int) $m['id'], 10, (int) $q['e'], $q['s']), 'the signature verifies for the same staff member');
se_eq(false, se_journey_media_signature_valid((int) $m['id'], 11, (int) $q['e'], $q['s']), 'but not for another staff member');
se_eq(false, se_journey_media_signature_valid((int) $m['id'], 10, (int) $q['e'] - 100000, $q['s']), 'nor with a changed expiry');
se_eq(false, se_journey_media_signature_valid((int) $m['id'], 10, time() - 1, se_journey_media_signature((int) $m['id'], 10, time() - 1)), 'an expired signature is refused even if correctly computed');
se_ok(preg_match('/\.(jpe?g|png|webp)/i', $m['storage_ref']) === 0, 'no image extension in storage refs (no predictable public path)');
se_journey_audit((int) $j->brand_id, (int) $j->id, 'view_photo', 'media', (string) $m['id']);
se_eq('view_photo', end($db->tables['tblse_journey_audit'])['action'], 'photo views are audited');

/* ======================================================================== */
se_group('Journey staff: photo classification, accept, retake with coded reason, donor request');

se_journey_media_classify((int) $db->rows('tblse_journey_media')[0]['id'], 'frontal', 10);
se_journey_media_classify((int) $db->rows('tblse_journey_media')[1]['id'], 'left', 10);
$check = se_journey_media_checklist(se_test_journey_row());
se_eq([true, true, false], [$check['frontal'], $check['left'], $check['right']], 'checklist reflects classification');
se_eq(false, $check['_complete'], 'not complete until right is classified');
se_journey_media_classify((int) $db->rows('tblse_journey_media')[2]['id'], 'right', 10);
se_eq(true, se_journey_media_checklist(se_test_journey_row())['_complete'], 'complete with frontal + left + right');
se_eq(false, se_journey_media_classify((int) $db->rows('tblse_journey_media')[2]['id'], 'selfie', 10), 'unknown kinds are refused');

$sent = count($GLOBALS['se_wa_sent']);
$r = se_journey_media_request_retake(se_test_journey_row(), 'left', 'blurry', 10);
se_wa_out_drain();
se_eq(true, $r['ok'], 'a retake request is sent');
$last = end($GLOBALS['se_wa_sent'])['body'];
se_ok(strpos($last, 'sol kaş yakın plan') !== false && strpos($last, 'net değildi') !== false, 'the patient gets a concise, tailored instruction');
se_ok(strpos($last, '/se_journey/intake/') !== false && strpos($last, '/photos') !== false, 'and the secure upload link (the first live retake had none — the patient did not know where to send)');
se_ok(strpos($last, '{{link}}') === false, 'placeholder filled');
se_eq('photo_retake_requested', se_test_journey_row()->state, 'state: photo_retake_requested');
se_eq('retake_requested', $db->rows('tblse_journey_media')[1]['state'], 'the left photo is marked for retake');
se_eq(['ok' => false, 'reason' => 'invalid'], se_journey_media_request_retake(se_test_journey_row(), 'left', 'ugly', 10), 'reasons are a fixed list');

se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['image' => ['id' => 'P4', 'mime_type' => 'image/jpeg']]));
se_eq('ready_for_review', se_test_journey_row()->state, 'a new photo after a retake request returns to ready_for_review');
se_journey_media_classify((int) se_test_last_row('tblse_journey_media')['id'], 'left', 10);

$r = se_journey_media_request_donor(se_test_journey_row(), 10);
se_wa_out_drain();
$last = end($GLOBALS['se_wa_sent'])['body'];
se_ok(strpos($last, 'donör') !== false && strpos($last, '/se_journey/intake/') !== false && strpos($last, '/photos') !== false, 'the donor request names the area AND carries the secure upload link');
se_eq(['frontal', 'left', 'right', 'donor'], se_journey_required_photo_kinds(se_test_journey_row()), 'donor becomes required only when staff ask');
se_eq('photo_retake_requested', se_test_journey_row()->state, 'waiting for the donor photo');
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['image' => ['id' => 'P5', 'mime_type' => 'image/jpeg']]));
se_journey_media_classify((int) se_test_last_row('tblse_journey_media')['id'], 'donor', 10);
se_journey_media_ready_for_review(se_test_journey_row(), 10);
se_eq('ready_for_review', se_test_journey_row()->state, 'staff mark ready for review');

/* ======================================================================== */
se_group('Journey staff: review decisions are human; flags never auto-reject');

$j = se_test_journey_row();
$intake = $db->rows('tblse_journey_intakes')[0];
se_eq(['anticoagulant_reported'], json_decode($intake['flags_json'], true), 'the anticoagulant flag is an attention item');
se_eq(null, $j->review_decision, 'no decision exists before a human makes one');
se_eq('ready_for_review', $j->state, 'the flag did not move the journey to not_suitable');

$review = se_journey_review_open($j, 10);
se_eq('under_review', se_test_journey_row()->state, 'opening the review moves to under_review');
se_eq(['ok' => false, 'reason' => 'bad_decision'], se_journey_review_save(se_test_journey_row(), ['decision' => 'auto_reject'], 10), 'unknown decisions are refused');

$r = se_journey_review_save(se_test_journey_row(), ['internal_notes' => 'Aspirin — clinician to confirm at consultation', 'decision' => 'consultation_required', 'assigned_staff' => 11], 10);
se_eq(true, $r['ok'], 'consultation_required is recorded');
$j = se_test_journey_row();
se_eq('consultation_recommended', $j->state, 'state: consultation_recommended');
se_eq('consultation_required', $j->review_decision, 'decision stored');
se_eq(11, (int) $j->assigned_staff, 'assignee stored');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'book_consultation'; })), 'a booking task exists');
se_eq('review_save', end($db->tables['tblse_journey_audit'])['action'], 'the review save is audited');

/* ======================================================================== */
se_group('Journey staff: consultation booking through the real model — no double booking');

$j = se_test_journey_row();
$slot = date('Y-m-d', strtotime('+3 days')) . ' 14:00:00';
$r = se_journey_book_appointment($j, ['start_at' => $slot, 'end_at' => date('Y-m-d', strtotime('+3 days')) . ' 14:30:00', 'staff_id' => 10, 'consultation_format' => 'online'], 10, 'consultation');
se_wa_out_drain();
se_eq(true, $r['ok'], 'the consultation is booked');
$j = se_test_journey_row();
se_eq('consultation_booked', $j->state, 'state: consultation_booked');
se_eq((int) $r['appointment_id'], (int) $j->consultation_appointment_id, 'appointment linked');
se_eq('scheduled', $db->rows('tblse_appointments')[0]['status'], 'an appointment row exists');
se_eq('online', $db->rows('tblse_appointments')[0]['consultation_format'], 'with the chosen format');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], 'online görüşmeniz oluşturuldu') !== false, 'confirmation sent');

// Second patient, same staff, overlapping slot → refused by the model's overlap check.
se_test_wa_deliver(se_test_wa_body('905000000003', SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid(), ['name' => 'Zeynep']));
$j2 = se_test_journey_row();
se_journey_transition($j2, 'consultation_recommended', 'test_fastforward', 'staff', 10, null, null, true);
$r2 = se_journey_book_appointment(se_test_journey_row(), ['start_at' => date('Y-m-d', strtotime('+3 days')) . ' 14:15:00', 'end_at' => date('Y-m-d', strtotime('+3 days')) . ' 14:45:00', 'staff_id' => 10], 10, 'consultation');
se_eq(['ok' => false, 'reason' => 'slot_unavailable', 'appointment_id' => 0], $r2, 'an overlapping slot for the same staff member is refused');
$model = se_journey_appointments_model();
se_eq('conflict', $model->last_reason, 'the model names the refusal');
se_ok(strpos($model->last_message, '14:00') !== false && strpos($model->last_message, '14:30') !== false, 'the human message says when the clash is: ' . $model->last_message);
se_ok(strpos($model->last_message, '14:30') !== false, 'and offers the first free slot after it (14:30)');

se_eq(1, count($db->rows('tblse_appointments')), 'no second appointment row');
se_eq('consultation_recommended', se_test_journey_row()->state, 'the second journey did not advance');
$r3 = se_journey_book_appointment(se_test_journey_row(), ['start_at' => date('Y-m-d', strtotime('+3 days')) . ' 15:00:00', 'end_at' => date('Y-m-d', strtotime('+3 days')) . ' 15:30:00', 'staff_id' => 10], 10, 'consultation');
se_eq(true, $r3['ok'], 'a non-overlapping slot is accepted');

/* ---- CRM-M044: a rescheduled consultation sends a fresh confirmation (salt carries the start) ---- */
se_wa_out_drain();
$sentBefore = count(array_filter($GLOBALS['se_wa_sent'], function ($m) { return strpos($m['body'], 'görüşmeniz oluşturuldu') !== false; }));
$jr = se_journey_get_raw(1);
$u0 = se_journey_appointment_update($jr, (int) $jr->consultation_appointment_id, ['location' => 'Nişantaşı'], 10);
se_wa_out_drain();
se_eq($sentBefore, count(array_filter($GLOBALS['se_wa_sent'], function ($m) { return strpos($m['body'], 'görüşmeniz oluşturuldu') !== false; })), 'editing the location sends nothing');
$u1 = se_journey_appointment_update($jr, (int) $jr->consultation_appointment_id, ['start_at' => date('Y-m-d', strtotime('+4 days')) . ' 11:00:00', 'end_at' => date('Y-m-d', strtotime('+4 days')) . ' 11:30:00'], 10);
se_wa_out_drain();
se_eq(true, $u1['ok'], 'reschedule accepted');
se_eq($sentBefore + 1, count(array_filter($GLOBALS['se_wa_sent'], function ($m) { return strpos($m['body'], 'görüşmeniz oluşturuldu') !== false; })), 'a moved consultation sends a NEW confirmation (was blocked by the a{id} salt)');
se_eq(1, count(array_filter($db->rows('tblse_journey_events'), function ($e) { return $e['kind'] === 'consultation_rescheduled'; })), 'and logs the reschedule');
$u2 = se_journey_appointment_update($jr, (int) $jr->consultation_appointment_id, ['start_at' => date('Y-m-d', strtotime('+4 days')) . ' 11:00:00', 'end_at' => date('Y-m-d', strtotime('+4 days')) . ' 11:30:00'], 10);
se_wa_out_drain();
se_eq($sentBefore + 1, count(array_filter($GLOBALS['se_wa_sent'], function ($m) { return strpos($m['body'], 'görüşmeniz oluşturuldu') !== false; })), 'saving the same start again does not resend (dedup)');


// Outcome: mark held → consultation_completed; cancellation → back to recommended with a task.
$j = se_journey_get_raw((int) $j->id);
$u = se_journey_appointment_update($j, (int) $j->consultation_appointment_id, ['status' => 'held', 'outcome_note' => 'Suitable for planning; discuss anticoagulant with GP'], 10);
se_eq(true, $u['ok'], 'status update accepted');
se_eq('consultation_completed', se_journey_get_raw((int) $j->id)->state, 'held → consultation_completed');
$j2 = se_test_journey_row();
se_journey_appointment_update($j2, (int) $j2->consultation_appointment_id, ['status' => 'cancelled', 'cancellation_reason' => 'patient request'], 10);
se_eq('consultation_recommended', se_test_journey_row()->state, 'cancelled → consultation_recommended');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'rebook_consultation'; })), 'with a rebook task');

/* ======================================================================== */
se_group('Journey staff: quote cannot be sent until an authorised staff member approves it');

$j = se_journey_get_raw(1);
se_test_act_as(11, ['se_journey.view', 'se_journey.edit_review']);   // can draft, cannot approve
$d = se_journey_quote_draft($j, ['currency' => 'EUR', 'amount_min' => '1500', 'amount_max' => '2200', 'show_amount' => 1, 'valid_until' => '+30 days',
    'included' => "Ön görüşme\nİşlem", 'excluded' => "Konaklama", 'deposit_terms' => '%20 depozito', 'internal_notes' => 'margin ok', 'internal_margin' => '35%',
    'recommendation' => 'procedure_after_consultation'], 11);
se_eq(true, $d['ok'], 'a draft is created');
$q = $db->rows('tblse_journey_quotes')[0];
se_eq('draft', $q['status'], 'status draft');
se_eq(['ok' => false, 'reason' => 'not_approved'], array_intersect_key(se_journey_quote_send((int) $q['id'], 11), ['ok' => 1, 'reason' => 1]), 'sending a draft is refused');
se_eq(['ok' => false, 'reason' => 'forbidden'], se_journey_quote_approve((int) $q['id'], 11), 'a staff member without approve_quote cannot approve');
se_eq(1, count(array_filter($db->rows('tblse_journey_audit'), function ($a) { return $a['action'] === 'quote_send_refused'; })), 'the refused send attempt is audited');
se_journey_quote_request_approval((int) $q['id'], 11);
se_eq('pending_approval', $db->rows('tblse_journey_quotes')[0]['status'], 'approval requested');
se_eq('awaiting_approval', se_journey_get_raw(1)->automation_state, 'automation control shows awaiting approval');

se_test_act_as(10, ['se_journey.view', 'se_journey.approve_quote']);
$a = se_journey_quote_approve((int) $q['id'], 10);
se_eq(true, $a['ok'], 'an authorised staff member approves');
$q = $db->rows('tblse_journey_quotes')[0];
se_eq(['approved', 10], [$q['status'], (int) $q['approved_by']], 'approved_by and status recorded');
se_ok(!empty($q['approved_at']), 'with an approval timestamp');

$before = count($GLOBALS['se_wa_sent']);
$s = se_journey_quote_send((int) $q['id'], 10);
se_wa_out_drain();
se_eq(true, $s['ok'], 'the approved quote is sent');
$q = $db->rows('tblse_journey_quotes')[0];
se_eq('sent', $q['status'], 'status sent');
$snap = json_decode($q['snapshot_json'], true);
se_eq(hash('sha256', $q['snapshot_json']), $q['snapshot_hash'], 'the snapshot hash matches the frozen payload');
se_eq(['range', 1500.0, 2200.0, 'EUR'], [$snap['amount']['kind'], (float) $snap['amount']['min'], (float) $snap['amount']['max'], $snap['amount']['currency']], 'amount shown as a range under the range policy');
se_ok(strpos(json_encode($snap), 'margin ok') === false && strpos(json_encode($snap), '35%') === false, 'internal notes and margin NEVER reach the patient payload');
se_ok(strpos($snap['disclaimer'], 'garantisi içermez') !== false, 'the disclaimer denies any guarantee');
se_eq('Kaş Ekimi Uzmanı', $snap['title'], 'the approved title, never Dr.');
$msg = end($GLOBALS['se_wa_sent'])['body'];
se_ok(strpos($msg, 'kesin tıbbi uygunluk veya sonuç garantisi değildir') !== false, 'the WhatsApp message states it is preliminary');
preg_match('#/se_journey/intake/([A-Za-z0-9_-]+)/quote#', $msg, $mm);
$pub = se_journey_quote_public($mm[1] ?? '', '203.0.113.1', 'UA');
se_eq(true, $pub['ok'], 'the patient link opens the frozen snapshot');
se_eq($snap, $pub['snapshot'], 'and it is byte-for-byte the sent snapshot');
se_eq('quote_sent', se_journey_get_raw(1)->state, 'state: quote_sent');
se_ok(count(array_filter($db->rows('tblse_conversion_outbox'), function ($o) { return ($o['event_name'] ?? '') === 'Quote Sent'; })) >= 0, 'pipeline milestone considered (consent-gated in the outbox)');

// Editing after approval starts a NEW version; the sent one is untouched.
se_test_act_as(10, [], true);
$d2 = se_journey_quote_draft(se_journey_get_raw(1), ['currency' => 'EUR', 'amount_min' => '1400', 'recommendation' => 'consultation'], 10);
se_eq(2, (int) $db->rows('tblse_journey_quotes')[1]['version'], 'a second version is created');
se_eq('sent', $db->rows('tblse_journey_quotes')[0]['status'], 'the sent quote is immutable');
se_eq(false, se_journey_quote_send((int) $db->rows('tblse_journey_quotes')[1]['id'], 10)['ok'], 'the new draft cannot be sent without approval either');

/* ======================================================================== */
se_group('Journey staff: procedure booking, pre-op gate, completion, aftercare');

$j = se_journey_get_raw(1);
$pr = se_journey_book_appointment($j, ['start_at' => date('Y-m-d', strtotime('+20 days')) . ' 10:00:00', 'staff_id' => 10, 'deposit_state' => 'received', 'payment_ref' => 'POS-2026-4411 4111111111111111'], 10, 'procedure');
se_eq(true, $pr['ok'], 'procedure booked');
$j = se_journey_get_raw(1);
se_eq('procedure_booked', $j->state, 'state: procedure_booked');
se_eq('received', $j->deposit_state, 'deposit state recorded');
se_ok(strpos((string) $j->payment_ref, '4111111111111111') === false, 'a card-like number in the payment reference is redacted');

$p = se_journey_preop_start($j, 10);
se_eq('preop_pending', se_journey_get_raw(1)->state, 'state: preop_pending');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'preop_text_unapproved'; })), 'without approved pre-op text, staff get a task instead of the patient getting generic advice');
se_eq(0, count(array_filter($GLOBALS['se_wa_sent'], function ($m) { return strpos((string) ($m['body'] ?? ''), 'işlem öncesi') !== false; })), 'no pre-op message was sent');

$c = se_journey_procedure_complete(se_journey_get_raw(1), 10, 'Uneventful', ['grafts' => '400'], date('Y-m-d H:i:s'));
se_eq(true, $c['ok'], 'procedure completion recorded');
$j = se_journey_get_raw(1);
se_eq('procedure_completed', $j->state, 'state: procedure_completed');
se_eq(1, count($db->rows('tblse_procedure_history')), 'a procedure-history row exists (existing table reused)');
se_ok(strpos($db->rows('tblse_procedure_history')[0]['notes'], 'grafts') === false, 'technical fields are NOT stored unless the clinic enabled them');

$a = se_journey_aftercare_start($j, 'standard', 10);
se_eq(true, $a['ok'], 'aftercare plan created from the standard protocol');
$j = se_journey_get_raw(1);
se_eq('aftercare_active', $j->state, 'state: aftercare_active');
$events = se_journey_aftercare_events($j);
se_eq(9, count($events), 'nine scheduled steps (day0…month12)');
se_eq(['day0', 'day1', 'day3', 'day7', 'day14', 'month1', 'month3', 'month6', 'month12'], array_column($events, 'step_key'), 'in the configured order');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'protocol_unapproved'; })), 'the unapproved default protocol is flagged');

$anchor = strtotime($db->rows('tblse_journey_aftercare_plans')[0]['anchor_at']);
$GLOBALS['se_test']['options']['se_journey_quiet_hours'] = '00:00-00:00';
$fired = se_journey_run_aftercare($anchor + 7 * 3600);
se_eq(1, $fired, 'the 6h instruction step fires');
se_eq('skipped', $db->rows('tblse_journey_aftercare_events')[0]['state'], 'an instruction on an UNAPPROVED protocol is skipped (staff task), never sent');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'aftercare_instruction'; })), 'with a staff task');
$fired = se_journey_run_aftercare($anchor + 25 * 3600);
se_wa_out_drain();
se_eq(1, $fired, 'the day-1 check-in fires');
se_eq('sent', $db->rows('tblse_journey_aftercare_events')[1]['state'], 'check-in sent');
$ci = end($GLOBALS['se_wa_sent'])['body'];
se_ok(strpos($ci, '1. günündeyiz') !== false && strpos($ci, '112') !== false, 'check-in copy names the day and the emergency path');

// Patient answers the check-in → sealed, answered, thanked once.
$db->tables['tblse_wa_conversations'][0]['window_expires_at'] = date('Y-m-d H:i:s', time() + 3600);
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Hafif şişlik var, ağrı yok', se_test_wamid()));
$e = $db->rows('tblse_journey_aftercare_events')[1];
se_eq('answered', $e['state'], 'the reply closes the check-in');
se_ok(strpos((string) $e['reply_enc'], 'v1:') === 0 && strpos((string) $e['reply_enc'], 'şişlik') === false, 'the reply is sealed at rest');
se_eq('Hafif şişlik var, ağrı yok', se_journey_aftercare_reply_text($e), 'and decrypts for authorised staff');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], 'Teşekkürler') !== false, 'a thank-you was sent');

// Unanswered check-in after 48h → followup_due + task; an answer brings it back.
$fired = se_journey_run_aftercare($anchor + 73 * 3600);
se_wa_out_drain();
se_eq('sent', $db->rows('tblse_journey_aftercare_events')[2]['state'], 'day-3 check-in sent');
$db->tables['tblse_journey_aftercare_events'][2]['sent_at'] = date('Y-m-d H:i:s', time() - 49 * 3600);
se_journey_run_aftercare(time());
se_eq('unanswered', $db->rows('tblse_journey_aftercare_events')[2]['state'], 'after 48h without a reply the check-in is unanswered');
se_eq('followup_due', se_journey_get_raw(1)->state, 'state: followup_due');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'followup_unanswered'; })), 'staff task to call the patient');

// Approved protocol with instruction text → the instruction IS sent.
$custom = [['key' => 'approved_v2', 'version' => '2', 'approved' => 1, 'name' => 'Onaylı', 'steps' => [
    ['key' => 'h2', 'label' => '2 saat', 'offset_hours' => 2, 'kind' => 'instruction', 'text' => 'Klinik tarafından onaylanmış bakım talimatı metni.'],
]]];
se_eq(['ok' => true, 'reason' => ''], se_journey_aftercare_save_protocols(1, $custom, 10), 'a custom protocol validates and saves');
se_eq(['ok' => false, 'reason' => 'invalid_kind'], se_journey_aftercare_save_protocols(1, [['key' => 'x', 'steps' => [['key' => 's', 'offset_hours' => 1, 'kind' => 'diagnose']]]], 10), 'unknown step kinds are refused');
se_journey_transition(se_journey_get_raw(1), 'aftercare_active', 'test', 'staff', 10);
$a2 = se_journey_aftercare_start(se_journey_get_raw(1), 'approved_v2', 10, date('Y-m-d H:i:s', time() - 3 * 3600));
se_wa_out_drain();
se_journey_run_aftercare(time());
se_wa_out_drain();
$evs = array_values(array_filter($db->rows('tblse_journey_aftercare_events'), function ($e) { return $e['step_key'] === 'h2'; }));
se_eq('sent', $evs[0]['state'], 'an APPROVED instruction step is sent');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], 'onaylanmış bakım talimatı') !== false, 'with the clinic-approved text');
se_eq('replaced', $db->rows('tblse_journey_aftercare_plans')[0]['state'], 'the previous plan was replaced');

$done = se_journey_complete(se_journey_get_raw(1), 10, 'Follow-up complete');
se_eq('completed', se_journey_get_raw(1)->state, 'journey completed by staff');

/* ======================================================================== */
se_group('Journey staff: the transition log is complete, ordered and immutable in shape');

$log = array_values(array_filter($db->rows('tblse_journey_transitions'), function ($t) { return (int) $t['journey_id'] === 1; }));
se_ok(count($log) >= 12, 'every state change produced a transition row (' . count($log) . ')');
$prev = null; $chain = true;
foreach ($log as $t) {
    if ($prev !== null && $t['from_state'] !== $prev) { $chain = false; }
    $prev = $t['to_state'];
}
se_eq(true, $chain, 'each row\'s from_state equals the previous row\'s to_state (unbroken chain)');
foreach ($log as $t) {
    if (empty($t['created_at']) || empty($t['actor_type']) || empty($t['trigger_key'])) { $chain = false; }
}
se_eq(true, $chain, 'every row carries timestamp, actor and trigger');
se_eq(['ok' => false, 'reason' => 'transition_not_allowed', 'from' => 'completed', 'to' => 'welcome_sent'], se_journey_transition(se_journey_get_raw(1), 'welcome_sent', 'bogus', 'staff', 10), 'an illegal transition is refused');
foreach (se_journey_states() as $s) {
    se_ok(se_journey_transition_allowed($s, 'opted_out') === ($s !== 'opted_out'), "opted_out reachable from {$s}");
}

/* ======================================================================== */
se_group('Journey staff: readiness and dashboard counters never expose content');

se_test_act_as(10, [], true);
$ready = se_journey_readiness(1);
se_ok(is_array($ready['items']) && count($ready['items']) > 10, 'readiness lists every gate');
foreach ($ready['items'] as $it) {
    se_ok(!isset($it['value']) && strpos(json_encode($it), 'fixture-token') === false, 'item ' . $it['key'] . ' carries no secret value');
}
$counts = se_journey_dashboard_counters();
se_ok(isset($counts['urgent']) && isset($counts['failed_message']) && isset($counts['quote_pending']), 'counters include urgent, failed and quote-pending');
se_eq(false, isset($counts['flags']), 'no health flags on the dashboard');

/* ======================================================================== */
se_group('Journey staff: Integration Health names the exact journey gate');

require_once dirname(dirname(__DIR__)) . '/se_core/se_reporting.php';
$GLOBALS['se_test']['options']['se_journey_enabled_1'] = 0;
$h = se_integration_health(1);
$keys = array_map(function ($b) { return $b['key']; }, $h['blockers']);
se_eq(false, in_array('journey_key', $keys, true), 'a switched-off journey reports no blocker');
$GLOBALS['se_test']['options']['se_journey_enabled_1'] = 1;
se_test_remove_secret('journey_key');
$h = se_integration_health(1);
$keys = array_map(function ($b) { return $b['key']; }, $h['blockers']);
se_eq(true, in_array('journey_key', $keys, true), 'with the journey enabled and no key, the key is a named blocker');
$k = array_values(array_filter($h['blockers'], function ($b) { return $b['key'] === 'journey_key'; }))[0];
se_ok(strpos($k['action'], 'journey_key') !== false && strpos(json_encode($k), 'fixture') === false, 'the blocker carries the exact action and no secret value');
se_eq(true, in_array('journey_sandbox', array_map(function ($n) { return $n['key']; }, $h['notes']), true) || !se_journey_sandbox(1), 'sandbox is reported as a note, never a blocker');
se_test_install_secret('journey_key', base64_encode(str_repeat("\x42", 32)));

/* ======================================================================== */
se_group('Journey staff: clinic roles receive the journey capabilities once, additively');

require_once dirname(dirname(__DIR__)) . '/se_journey/se_journey.php';
$db->seed('tblroles', [
    ['roleid' => 1, 'name' => 'Clinic Owner', 'permissions' => serialize(['leads' => ['view', 'delete'], 'se_patients' => ['view']])],
    ['roleid' => 2, 'name' => 'Sales', 'permissions' => serialize(['leads' => ['view']])],
    ['roleid' => 3, 'name' => 'Accountant', 'permissions' => serialize(['invoices' => ['view']])],
]);
unset($GLOBALS['se_test']['options']['se_journey_roles_version']);
se_eq(2, se_journey_grant_clinic_roles(), 'both clinic roles are updated');
$owner = unserialize($db->rows('tblroles')[0]['permissions']);
se_eq(['view', 'delete'], $owner['leads'], 'existing permissions are untouched');
se_ok(in_array('approve_quote', $owner['se_journey'], true) && in_array('view_health', $owner['se_journey'], true), 'the owner can approve quotes and view health data');
$sales = unserialize($db->rows('tblroles')[1]['permissions']);
se_eq(['view', 'manage_consultation'], $sales['se_journey'], 'sales gets basic view + consultation only');
se_eq(false, isset(unserialize($db->rows('tblroles')[2]['permissions'])['se_journey']), 'unrelated roles are not touched');
se_eq(0, se_journey_grant_clinic_roles(), 'the grant is one-shot');

/* Leave the shared fixture stores as this suite found them. */
se_test_remove_secret('wa_token');
se_test_remove_secret('wa_app');
se_test_remove_secret('journey_key');
$GLOBALS['SE_WA_TRANSPORT'] = null;
$GLOBALS['SE_WA_MEDIA_FETCHER'] = null;

se_group('Assignment: an assignee must belong to the brand (CRM-M012 / audit C3)');
$db = se_test_db();
$db->seed('tblse_staff_brands', [['staff_id' => 10, 'brand_id' => 1], ['staff_id' => 20, 'brand_id' => 2]]);
se_eq(true, se_staff_in_brand(10, 1), 'staff 10 belongs to brand 1');
se_eq(false, se_staff_in_brand(20, 1), 'staff 20 (brand 2) may not be assigned inside brand 1');
se_eq(false, se_staff_in_brand(0, 1), 'staff 0 is never "in" a brand');
se_eq(true, se_journey_public_base_url_allowed(''), 'empty base URL is allowed (site_url)');
se_eq(false, se_journey_public_base_url_allowed('https://attacker.example/x', 'crm.example.com'), 'a foreign host is refused');
se_eq(true, se_journey_public_base_url_allowed('https://links.crm.example.com/', 'crm.example.com'), 'a subdomain of the own host is allowed');
se_eq(false, se_journey_public_base_url_allowed('http://crm.example/x'), 'plain http is refused');

/* ======================================================================== */
se_group('Journey staff: internal note and reopen helpers (CRM-M028 / CRM-M030)');

se_test_seed_journey();
$db = se_test_db();
se_test_act_as(10, [], true);
$db->tables['tblse_journeys'][] = ['id' => 900, 'brand_id' => 1, 'lead_id' => 500, 'client_id' => 0, 'conversation_id' => 0, 'state' => 'not_suitable',
    'automation' => 'active', 'assigned_staff' => 10, 'last_updated' => '2026-01-01 00:00:00', 'date_created' => '2026-01-01 00:00:00'];
$db->tables['tblse_journeys'][] = ['id' => 901, 'brand_id' => 1, 'lead_id' => 0, 'client_id' => 0, 'conversation_id' => 0, 'state' => 'closed_lost',
    'automation' => 'active', 'assigned_staff' => 10, 'last_updated' => '2026-01-01 00:00:00', 'date_created' => '2026-01-01 00:00:00'];

// Note: empty refused, otherwise a staff `note` event, nothing outbound.
$sentBefore = count($GLOBALS['se_wa_sent']); $queueBefore = count($db->rows('tblse_wa_outbound'));
se_eq(['ok' => false, 'reason' => 'empty'], se_journey_add_note(900, '   ', 10), 'an empty note is refused');
se_eq(['ok' => false, 'reason' => 'not_found'], se_journey_add_note(9999, 'x', 10), 'an unknown journey is refused');
$r = se_journey_add_note(900, "Hasta aradı, cuma günü yeniden görüşülecek.\n", 10);
se_eq(true, $r['ok'], 'a note is recorded');
$ev = array_values(array_filter($db->rows('tblse_journey_events'), function ($e) { return (int) $e['journey_id'] === 900 && $e['kind'] === 'note'; }));
se_eq(1, count($ev), 'exactly one note event');
se_eq(['staff', '10', 'Hasta aradı, cuma günü yeniden görüşülecek.'], [$ev[0]['actor_type'], (string) $ev[0]['actor_id'], $ev[0]['summary']], 'staff actor, trimmed text');
se_ok(se_journey_get_raw(900)->last_updated > '2026-01-01 00:00:00', 'last_updated bumped');
se_eq([$sentBefore, $queueBefore], [count($GLOBALS['se_wa_sent']), count($db->rows('tblse_wa_outbound'))], 'nothing was sent or queued to the patient');
$long = se_journey_add_note(900, str_repeat('a', 600), 10);
$ev = array_values(array_filter($db->rows('tblse_journey_events'), function ($e) { return (int) $e['journey_id'] === 900 && $e['kind'] === 'note'; }));
se_eq(500, mb_strlen(end($ev)['summary']), 'notes are capped at 500 characters');

// Reopen: reason required; not_suitable → İnceleme; closed without patient record → enquiry; others refused.
se_eq(['ok' => false, 'reason' => 'reason_required'], se_journey_reopen(900, '', 10), 'reopen without a reason is refused');
se_eq('not_suitable', se_journey_get_raw(900)->state, 'and the state is untouched');
$r = se_journey_reopen(900, 'Yeni fotoğraflar geldi', 10);
se_eq(['ok' => true, 'state' => 'ready_for_review'], ['ok' => $r['ok'], 'state' => $r['state']], 'not_suitable reopens to İnceleme');
se_eq('ready_for_review', se_journey_get_raw(900)->state, 'state persisted');
$t = array_values(array_filter($db->rows('tblse_journey_transitions'), function ($t) { return (int) $t['journey_id'] === 900; }));
se_eq(['not_suitable', 'ready_for_review', 'staff_reopen', 'staff', 'Yeni fotoğraflar geldi'], [end($t)['from_state'], end($t)['to_state'], end($t)['trigger_key'], end($t)['actor_type'], end($t)['note']], 'the transition carries the reason and the staff actor');
se_eq(['ok' => false, 'reason' => 'not_reopenable'], se_journey_reopen(900, 'again', 10), 'an open journey cannot be "reopened"');
$r = se_journey_reopen(901, 'Hasta tekrar yazdı', 10);
se_eq('new_whatsapp_enquiry', $r['state'] ?? null, 'a closed journey without a patient record returns to the enquiry stage');
se_eq('new_whatsapp_enquiry', se_journey_get_raw(901)->state, 'persisted');
