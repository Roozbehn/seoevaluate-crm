<?php
/**
 * Patient journey (se_journey) — the patient's answer to a quote and the
 * calendar booking that follows an acceptance.
 *
 *   - a sent quote carries three reply buttons in-window (accept / price
 *     revision / human); outside the window the quick-reply template goes,
 *     with payloads that come back as the same button ids
 *   - accept → quote_accepted + a secure calendar link; the page lists free
 *     face-to-face slots from the consultation calendar (working hours minus
 *     existing appointments), and a pick books THROUGH the appointments
 *     model with no staff session
 *   - price revision → quote_revision_requested + staff task; a new version
 *     can be approved and sent; the patient can still accept
 *   - the quote page offers the same three actions (token, no window needed)
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/journey_fixtures.php';

/** Review → approved quote → sent, by staff 10 (admin). Returns the quote row. */
function se_test_quote_sent()
{
    $db = se_test_db();
    se_test_act_as(10, [], true);
    $j = se_test_journey_row();
    se_journey_review_open($j, 10);
    se_journey_review_save(se_test_journey_row(), ['decision' => 'provisionally_suitable'], 10);
    $j = se_test_journey_row();
    se_journey_quote_draft($j, ['currency' => 'EUR', 'amount_min' => '1500', 'amount_max' => '2200', 'show_amount' => 1, 'valid_until' => '+30 days',
        'included' => "Ön görüşme\nİşlem", 'recommendation' => 'procedure_after_consultation'], 10);
    $rows = $db->rows('tblse_journey_quotes');
    $q = end($rows);
    se_journey_quote_approve((int) $q['id'], 10);
    $r = se_journey_quote_send((int) $q['id'], 10);
    se_wa_out_drain();
    $rows = $db->rows('tblse_journey_quotes');

    return ['send' => $r, 'quote' => end($rows)];
}

/* ======================================================================== */
se_group('Journey quote: the sent quote carries the three reply buttons in-window');

se_test_journey_reviewed();
$db = se_test_db();
$sent = se_test_quote_sent();
se_eq(true, $sent['send']['ok'], 'the approved quote is sent');
se_eq('quote_sent', se_test_journey_row()->state, 'state: quote_sent');
$last = end($GLOBALS['se_wa_sent']);
se_eq('interactive', $last['kind'], 'in-window: one interactive message');
se_eq(['jr_quote_accept', 'jr_quote_revise', 'jr_handoff'], array_column($last['payload']['buttons'], 'id'), 'accept / price revision / human, in that order');
foreach ($last['payload']['buttons'] as $b) { se_ok(mb_strlen($b['title']) <= 20, 'button "' . $b['title'] . '" fits Meta\'s 20-char cap'); }
se_eq(['Teklifi Kabul Et', 'Fiyat Revizyonu', 'Danışmana Bağlan'], array_column($last['payload']['buttons'], 'title'), 'Turkish titles');
se_ok(strpos($last['body'], '/quote') !== false && strpos($last['body'], 'garantisi değildir') !== false, 'the body still carries the secure link and the disclaimer');
se_ok(strpos($last['body'], 'yüz yüze') !== false, 'and says a face-to-face slot follows an acceptance');
se_ok(mb_strlen($last['body']) <= 1024, 'interactive body within the limit');
se_eq(null, $sent['quote']['patient_response'] ?? null, 'no answer yet');
// Consultation information (procedure/prep/recovery links) is gated separately and defaults to
// unapproved: no extra message goes out, and staff see a task instead of a silent no-op.
se_eq($last, end($GLOBALS['se_wa_sent']), 'unapproved: no consultation-information message follows the quote');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'consultation_info_unapproved'; })), 'staff task created instead');

// A typed question after the quote: staff task + the options repeated once, not on every message.
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Depozito ne kadar?', se_test_wamid()));
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'question_after_quote'; })), 'staff task for the question');
se_eq('interactive', end($GLOBALS['se_wa_sent'])['kind'], 'the options are repeated as buttons');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], 'kararınızı') !== false, 'with the short options text');
$optionsCount = function () use ($db) { return count(array_filter($db->rows('tblse_wa_outbound'), function ($o) { return $o['origin'] === 'journey:quote_options'; })); };
se_eq(1, $optionsCount(), 'one options message');
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Bir de seyahat?', se_test_wamid()));
se_eq(1, $optionsCount(), 'a second question does not repeat the options again');
se_eq('quote_sent', se_test_journey_row()->state, 'still quote_sent');

/* ======================================================================== */
se_group('Journey quote: "Teklifi Kabul Et" → quote_accepted + calendar link');

se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'jr_quote_accept', 'title' => 'Teklifi Kabul Et']]]));
$j = se_test_journey_row();
se_eq('quote_accepted', $j->state, 'state: quote_accepted');
$rows = $db->rows('tblse_journey_quotes'); $q = end($rows);
se_eq(['accepted', 'whatsapp'], [$q['patient_response'], $q['patient_response_via']], 'the quote row records the answer and the channel');
se_ok(!empty($q['patient_response_at']), 'with a timestamp');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'quote_accepted'; })), 'staff task: quote accepted');
se_eq(1, count(array_filter($db->rows('tblse_journey_transitions'), function ($t) { return $t['to_state'] === 'quote_accepted' && $t['actor_type'] === 'patient'; })), 'the transition is attributed to the patient');
$ack = end($GLOBALS['se_wa_sent'])['body'];
se_ok(strpos($ack, 'kabul ettiğinizi kaydettik') !== false && strpos($ack, 'yüz yüze') !== false, 'acknowledgement names the face-to-face consultation');
preg_match('#/se_journey/intake/([A-Za-z0-9_-]+)/book#', $ack, $mm);
se_ok(!empty($mm[1]), 'with the secure calendar link');
$bookToken = $mm[1] ?? '';
$v = se_journey_verify_token($bookToken, 'book', '203.0.113.9', 'UA');
se_eq(true, $v['ok'], 'the link is a booking token');
se_eq(false, se_journey_verify_token($bookToken, 'quote', '203.0.113.9', 'UA')['ok'], 'and only a booking token');
se_ok(count(array_filter($GLOBALS['se_wa_sent'], function ($m) { return strpos((string) ($m['body'] ?? ''), 'kabul ettiğinizi') !== false; })) === 1, 'one acknowledgement');

// A repeated tap re-sends the link and nothing else.
$tasksBefore = count($db->rows('tblse_journey_tasks'));
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'jr_quote_accept', 'title' => 'Teklifi Kabul Et']]]));
se_eq('quote_accepted', se_test_journey_row()->state, 'state unchanged');
se_eq($tasksBefore, count($db->rows('tblse_journey_tasks')), 'no new task');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], '/book') !== false && strpos(end($GLOBALS['se_wa_sent'])['body'], 'kabul ettiğinizi') === false, 'the link again, as a repeat');
se_eq(1, count(array_filter($db->rows('tblse_journey_transitions'), function ($t) { return $t['to_state'] === 'quote_accepted'; })), 'one accept transition');
// "randevu" typed later → the link again.
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Randevu linki', se_test_wamid()));
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], '/book') !== false, '"randevu linki" re-sends the calendar link');

/* ======================================================================== */
se_group('Journey quote: the calendar — working hours minus existing appointments, bounded by notice and horizon');

$GLOBALS['se_test']['options']['se_journey_booking_hours_1']   = '10:00-13:00';
$GLOBALS['se_test']['options']['se_journey_booking_days_1']    = '1,2,3,4,5';
$GLOBALS['se_test']['options']['se_journey_booking_slot_1']    = 30;
$GLOBALS['se_test']['options']['se_journey_booking_horizon_1'] = 7;
$GLOBALS['se_test']['options']['se_journey_booking_notice_1']  = 24;
$GLOBALS['se_test']['options']['se_journey_booking_location_1'] = 'Klinik, İstanbul';
$cfg = se_journey_booking_settings(1);
se_eq(['10:00-13:00', [1, 2, 3, 4, 5], 30, 7, 24, 'Klinik, İstanbul'], [$cfg['hours'], $cfg['days'], $cfg['slot_minutes'], $cfg['days_ahead'], $cfg['notice_hours'], $cfg['location']], 'settings read with bounds');
se_eq(10, se_journey_booking_staff(1), 'no calendar chosen → the brand\'s first active staff member');

$now = strtotime('next monday 09:00');   // a fixed anchor: the week ahead is deterministic
$avail = se_journey_booking_slots(1, $now);
se_eq(true, $avail['ok'], 'slots computed');
se_eq(10, $avail['staff_id'], 'on staff 10\'s calendar');
se_ok(count($avail['slots']) > 0, 'there are free slots');
$bad = 0;
foreach ($avail['slots'] as $s) {
    $t = strtotime($s['start']);
    $clock = date('H:i', $t);
    if ($t < $now + 24 * 3600) { $bad++; }                                  // notice
    if ($clock < '10:00' || $clock > '12:30') { $bad++; }                   // inside 10:00–13:00 with 30-minute slots
    if (!in_array((int) date('w', $t), [1, 2, 3, 4, 5], true)) { $bad++; }  // weekdays only
    if (strtotime($s['end']) - $t !== 1800) { $bad++; }                     // 30 minutes
    if ($t > $now + 8 * 86400) { $bad++; }                                  // horizon
}
se_eq(0, $bad, 'every slot honours notice, hours, days, length and horizon');
$tuesday = date('Y-m-d', strtotime('+1 day', $now));
se_eq(['10:00', '10:30', '11:00', '11:30', '12:00', '12:30'], array_map(function ($s) { return date('H:i', strtotime($s['start'])); }, $avail['days'][$tuesday]), 'Tuesday: six half-hour slots');
se_ok(!isset($avail['days'][date('Y-m-d', $now)]), 'Monday itself is inside the 24h notice → not offered');
se_ok(!isset($avail['days'][date('Y-m-d', strtotime('+5 days', $now))]), 'Saturday is not a booking day');

// An existing appointment on the same calendar removes its slot; a cancelled one does not.
$db->seed('tblse_appointments', [
    ['id' => 501, 'brand_id' => 1, 'staff_id' => 10, 'rel_type' => 'lead', 'rel_id' => 999, 'start_at' => $tuesday . ' 10:45:00', 'end_at' => $tuesday . ' 11:15:00', 'status' => 'scheduled', 'title' => 'x', 'appointment_type' => 'consultation'],
    ['id' => 502, 'brand_id' => 1, 'staff_id' => 10, 'rel_type' => 'lead', 'rel_id' => 998, 'start_at' => $tuesday . ' 12:00:00', 'end_at' => $tuesday . ' 12:30:00', 'status' => 'cancelled', 'title' => 'x', 'appointment_type' => 'consultation'],
    ['id' => 503, 'brand_id' => 1, 'staff_id' => 11, 'rel_type' => 'lead', 'rel_id' => 997, 'start_at' => $tuesday . ' 12:30:00', 'end_at' => $tuesday . ' 13:00:00', 'status' => 'scheduled', 'title' => 'x', 'appointment_type' => 'consultation'],
]);
$avail = se_journey_booking_slots(1, $now);
se_eq(['10:00', '11:30', '12:00', '12:30'], array_map(function ($s) { return date('H:i', strtotime($s['start'])); }, $avail['days'][$tuesday]),
      '10:30 and 11:00 overlap the 10:45–11:15 booking; the cancelled 12:00 and another staff member\'s 12:30 do not block');

// Working-hours rows for the calendar override the default hours/days.
$db->seed('tblse_working_hours', [['id' => 1, 'brand_id' => 1, 'staff_id' => 10, 'weekday' => 3, 'start_time' => '14:00:00', 'end_time' => '15:00:00']]);   // Wednesdays only
$avail = se_journey_booking_slots(1, $now);
$wednesday = date('Y-m-d', strtotime('+2 days', $now));
se_eq([$wednesday], array_keys($avail['days']), 'with working hours defined, only the defined weekday is bookable');
se_eq(['14:00', '14:30'], array_map(function ($s) { return date('H:i', strtotime($s['start'])); }, $avail['days'][$wednesday]), 'inside the defined window');
$db->seed('tblse_working_hours', []);

/* ======================================================================== */
se_group('Journey quote: the patient books a slot — through the model, with no staff session');

se_test_act_as(0, [], false);   // a token page: nobody is logged in
se_authz_reset_cache();
$GLOBALS['se_test']['is_admin_calls_without_session'] = 0;
$j = se_test_journey_row();
$avail = se_journey_booking_slots(1);   // real clock, as the page uses it
se_ok(count($avail['slots']) > 0, 'live slots exist');
$pick = $avail['slots'][1]['start'];

$r = se_journey_booking_pick($j, date('Y-m-d', strtotime($pick)) . ' 03:00:00', 'page');
se_eq(['ok' => false, 'reason' => 'slot_unavailable', 'appointment_id' => 0], $r, 'a time the page never offered is refused');
se_eq(1, count(array_filter($db->rows('tblse_journey_audit'), function ($a) { return $a['action'] === 'booking_slot_rejected'; })), 'and audited');

$before = count($db->rows('tblse_appointments'));
$r = se_journey_booking_pick($j, substr($pick, 0, 16), 'page');   // the form posts Y-m-d H:i
se_wa_out_drain();
se_eq(true, $r['ok'], 'an offered slot books');
$j = se_test_journey_row();
se_eq('consultation_booked', $j->state, 'state: consultation_booked');
se_eq((int) $r['appointment_id'], (int) $j->consultation_appointment_id, 'appointment linked to the journey');
se_eq($before + 1, count($db->rows('tblse_appointments')), 'one new appointment row');
$a = null; foreach ($db->rows('tblse_appointments') as $row) { if ((int) $row['id'] === (int) $r['appointment_id']) { $a = $row; } }
se_eq(['in_person', 10, 'scheduled', 'lead', (int) $j->lead_id, 'Klinik, İstanbul', $pick], [$a['consultation_format'], (int) $a['staff_id'], $a['status'], $a['rel_type'], (int) $a['rel_id'], $a['location'], $a['start_at']], 'face-to-face, on the calendar staff, for the journey\'s lead, at the picked time');
se_eq(1, count(array_filter($db->rows('tblse_journey_transitions'), function ($t) { return $t['to_state'] === 'consultation_booked' && $t['actor_type'] === 'patient'; })), 'transition attributed to the patient');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], 'klinikte görüşmeniz oluşturuldu') !== false, 'confirmation sent');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'consultation_self_booked'; })), 'staff task to confirm');
se_eq(1, count(array_filter($db->rows('tblse_journey_audit'), function ($a) { return $a['action'] === 'booking_self_service'; })), 'audited');
se_ok(se_journey_consultation_upcoming($j) !== null, 'the upcoming consultation is visible to the pages');
// Production 2026-09-03: the page returned a blank 500 AFTER the row was inserted — the model's
// post-write hooks re-read the row through the staff-scoped get(), and with no session Perfex's
// is_admin() ran a query on the half-built statement. The hooks must see the row without a
// session (reminder queued, milestone considered) and the authz helpers must never call is_admin().
se_eq(0, (int) $GLOBALS['se_test']['is_admin_calls_without_session'], 'no is_admin() query on a session-less request (the authz helpers short-circuit)');
$apptId = (int) $r['appointment_id'];
se_eq(1, count(array_filter($db->rows('tblse_reminders'), function ($x) use ($apptId) { return (int) $x['appointment_id'] === $apptId; })), 'the reminder for the self-booked consultation is queued (the hook saw the row)');
se_eq(1, (int) $a['reminder_queued'], 'and the row is marked reminder_queued');
se_eq(1, count(array_filter($db->rows('tblse_appointment_status_history'), function ($x) use ($apptId) { return (int) $x['appointment_id'] === $apptId && $x['new_status'] === 'scheduled'; })), 'status history written');

// "Add to calendar": the confirmation carries a link to an .ics of the booking; the file is right.
$conf = end($GLOBALS['se_wa_sent'])['body'];
preg_match('#/se_journey/intake/([A-Za-z0-9_-]+)/calendar#', $conf, $cm);
se_ok(!empty($cm[1]), 'the confirmation links to the calendar file');
$cv = se_journey_verify_token($cm[1], 'calendar', '203.0.113.9', 'UA');
se_eq(true, $cv['ok'], 'a calendar token (45 days)');
$ics = se_journey_calendar_ics($j, se_journey_consultation_appointment($j));
se_ok(strpos($ics, "BEGIN:VCALENDAR\r\n") === 0 && substr($ics, -15) === "END:VCALENDAR\r\n", 'iCalendar envelope with CRLF line ends');
se_ok(strpos($ics, 'SUMMARY:Klinikte ön görüşme – ') !== false, 'face-to-face summary');
se_ok(strpos($ics, 'LOCATION:Klinik\, İstanbul') !== false, 'clinic address, RFC-escaped comma');
$startUtc = (new DateTime($pick, new DateTimeZone(get_option('default_timezone') ?: 'Europe/Istanbul')))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
se_ok(strpos($ics, 'DTSTART:' . $startUtc) !== false, 'start converted to UTC');
se_ok(strpos($ics, 'DTEND:') !== false && strpos($ics, 'UID:journey-' . (int) $j->id . '-appointment-' . (int) $r['appointment_id'] . '@') !== false, 'end and a stable UID');
se_ok(strpos($ics, 'TRIGGER:-P1D') !== false, 'a reminder one day before');
se_ok(strpos($ics, SE_TEST_PATIENT) === false && stripos($ics, 'aspirin') === false, 'no patient number, no health data in the file');
se_eq(0, count(array_filter(explode("\r\n", $ics), function ($l) { return strlen($l) > 75; })), 'lines folded at 75 octets');
se_ok(strpos(se_journey_calendar_google_url($j, se_journey_consultation_appointment($j)), 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=') === 0, 'Google Calendar link');
se_eq(1, count(array_filter($db->rows('tblse_journey_tokens'), function ($t) { return $t['purpose'] === 'calendar'; })), 'one calendar token for the confirmation');

$r2 = se_journey_booking_pick($j, $pick, 'page');
se_eq('already_booked', $r2['reason'], 'a second pick is refused');
$avail2 = se_journey_booking_slots(1);
se_ok(!in_array($pick, array_column($avail2['slots'], 'start'), true), 'the booked slot is gone from the calendar');

/* ======================================================================== */
se_group('Journey quote: "Fiyat Revizyonu" → staff task; a new version can be sent; the patient can still accept');

se_test_journey_reviewed();
$sent = se_test_quote_sent();
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Fiyat revizyonu istiyorum', se_test_wamid()));
$j = se_test_journey_row();
se_eq('quote_revision_requested', $j->state, 'typed request → quote_revision_requested');
$rows = $db->rows('tblse_journey_quotes'); $q = end($rows);
se_eq('revision_requested', $q['patient_response'], 'recorded on the quote');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'quote_revision'; })), 'staff task: price revision');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], 'Talebinizi aldık') !== false, 'acknowledged');

se_test_act_as(10, [], true);
se_journey_quote_draft(se_test_journey_row(), ['currency' => 'EUR', 'amount_min' => '1400', 'amount_max' => '1900', 'show_amount' => 1, 'recommendation' => 'procedure_after_consultation'], 10);
$rows = $db->rows('tblse_journey_quotes'); $q2 = end($rows);
se_eq(2, (int) $q2['version'], 'version 2 drafted');
se_journey_quote_approve((int) $q2['id'], 10);
$r = se_journey_quote_send((int) $q2['id'], 10);
se_wa_out_drain();
se_eq(true, $r['ok'], 'v2 sent');
se_eq('quote_sent', se_test_journey_row()->state, 'state: quote_sent again');
se_eq('interactive', end($GLOBALS['se_wa_sent'])['kind'], 'with the buttons');
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Kabul ediyorum', se_test_wamid()));
se_eq('quote_accepted', se_test_journey_row()->state, 'typed acceptance → quote_accepted');
$rows = $db->rows('tblse_journey_quotes');
se_eq(['revision_requested', 'accepted'], [$rows[0]['patient_response'], $rows[1]['patient_response']], 'each version keeps its own answer');

/* ======================================================================== */
se_group('Journey quote: outside the window the quick-reply template goes, and a tap comes back as the button id');

se_test_journey_reviewed();
se_test_act_as(10, [], true);
se_journey_seed_templates(1);
foreach ($db->tables['tblse_journey_templates'] as &$row) { if (in_array($row['logical_name'], ['eyebrow_quote_ready_tr', 'eyebrow_booking_link_tr'], true)) { $row['approval_status'] = 'approved'; } }
unset($row);
$db->seed('tblse_wa_templates', [
    ['id' => 1, 'brand_id' => 1, 'name' => 'eyebrow_quote_ready_tr', 'language' => 'tr', 'category' => 'UTILITY', 'approval_state' => 'approved', 'variables' => '1,2'],
    ['id' => 2, 'brand_id' => 1, 'name' => 'eyebrow_booking_link_tr', 'language' => 'tr', 'category' => 'UTILITY', 'approval_state' => 'approved', 'variables' => '1,2'],
]);
foreach ($db->tables['tblse_wa_conversations'] as &$c) { $c['window_expires_at'] = date('Y-m-d H:i:s', time() - 86400); }
unset($c);
$sent = se_test_quote_sent();
se_eq(true, $sent['send']['ok'], 'sent');
$last = end($GLOBALS['se_wa_sent']);
se_eq(['template', 'eyebrow_quote_ready_tr'], [$last['kind'], $last['template']], 'the quick-reply quote template');
se_eq('Ayşe', $last['variables'][0], 'first name');
se_ok(strpos($last['variables'][1], '/quote') !== false, 'secure link');
se_eq(['jr_quote_accept', 'jr_quote_revise', 'jr_handoff'], $last['payload']['quick_replies'], 'the button payloads travel with the queued row');
$payload = se_wa_template_send_payload($last);
se_eq('body', $payload['template']['components'][0]['type'], 'Cloud API: body parameters first');
se_eq([['button', 'quick_reply', '0', 'jr_quote_accept'], ['button', 'quick_reply', '1', 'jr_quote_revise'], ['button', 'quick_reply', '2', 'jr_handoff']],
      array_map(function ($c) { return [$c['type'], $c['sub_type'], $c['index'], $c['parameters'][0]['payload']]; }, array_slice($payload['template']['components'], 1)),
      'then one index-bound button component per payload');
se_eq(1, count(se_wa_template_send_payload(['to' => '1', 'template' => 't', 'variables' => ['a'], 'payload' => []])['template']['components']), 'a template without quick replies has no button components');
se_eq('quote_sent', se_test_journey_row()->state, 'state: quote_sent');

// The tap on the template button: type "button" with the payload → accepted, link in-window (the tap reopened the window).
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['button' => ['payload' => 'jr_quote_accept', 'text' => 'Teklifi Kabul Et']]));
se_eq('quote_accepted', se_test_journey_row()->state, 'template quick reply → quote_accepted');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], '/book') !== false, 'calendar link sent');

// Then a booking whose confirmation must go as a template: the 4-variable calendar template when approved.
foreach ($db->tables['tblse_journey_templates'] as &$row) { if ($row['logical_name'] === 'eyebrow_consultation_calendar_tr') { $row['approval_status'] = 'approved'; } }
unset($row);
$db->tables['tblse_wa_templates'][] = ['id' => 3, 'brand_id' => 1, 'name' => 'eyebrow_consultation_calendar_tr', 'language' => 'tr', 'category' => 'UTILITY', 'approval_state' => 'approved', 'variables' => '1,2,3,4'];
foreach ($db->tables['tblse_wa_conversations'] as &$c) { $c['window_expires_at'] = date('Y-m-d H:i:s', time() - 86400); }   // the tap's window has passed
unset($c);
se_test_act_as(10, [], true);
$slotT = date('Y-m-d', strtotime('+6 days')) . ' 11:00:00';
$rb = se_journey_book_appointment(se_test_journey_row(), ['start_at' => $slotT, 'staff_id' => 10, 'consultation_format' => 'in_person'], 10, 'consultation');
se_wa_out_drain();
se_eq(true, $rb['ok'], 'staff booking while the window is closed');
$lastT = end($GLOBALS['se_wa_sent']);
se_eq(['template', 'eyebrow_consultation_calendar_tr'], [$lastT['kind'], $lastT['template']], 'confirmation as the calendar template');
se_eq(4, count($lastT['variables']), 'name, date, format, link');
se_ok(strpos($lastT['variables'][3], '/calendar') !== false && $lastT['variables'][2] === 'klinikte', 'the fourth variable is the .ics link');

// Without an approved quick-reply template the plain evaluation template is used (no payloads).
se_test_journey_reviewed();
se_test_act_as(10, [], true);
se_journey_seed_templates(1);
foreach ($db->tables['tblse_journey_templates'] as &$row) { if ($row['logical_name'] === 'eyebrow_evaluation_ready_tr') { $row['approval_status'] = 'approved'; } }
unset($row);
$db->seed('tblse_wa_templates', [['id' => 1, 'brand_id' => 1, 'name' => 'eyebrow_evaluation_ready_tr', 'language' => 'tr', 'category' => 'UTILITY', 'approval_state' => 'approved', 'variables' => '1,2']]);
foreach ($db->tables['tblse_wa_conversations'] as &$c) { $c['window_expires_at'] = date('Y-m-d H:i:s', time() - 86400); }
unset($c);
$sent = se_test_quote_sent();
$last = end($GLOBALS['se_wa_sent']);
se_eq(['template', 'eyebrow_evaluation_ready_tr'], [$last['kind'], $last['template']], 'falls back to the approved plain template');
se_eq([], $last['payload'], 'no quick replies on it');
// A template quick reply Meta echoes as TEXT (no payload configured) still matches by label.
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['button' => ['payload' => 'Fiyat Revizyonu', 'text' => 'Fiyat Revizyonu']]));
se_eq('quote_revision_requested', se_test_journey_row()->state, 'a label-only quick reply is understood too');

/* ======================================================================== */
se_group('Journey quote: the quote page answers (token, no window) and the model\'s system guard');

se_test_journey_reviewed();
$sent = se_test_quote_sent();
preg_match('#/se_journey/intake/([A-Za-z0-9_-]+)/quote#', end($GLOBALS['se_wa_sent'])['body'], $mm);
$pub = se_journey_quote_public($mm[1], '203.0.113.1', 'UA');
se_eq(['', null, 'quote_sent'], [$pub['response'], $pub['booking'], (string) $pub['journey']->state], 'the page knows there is no answer yet');

se_test_act_as(0, [], false);
foreach ($db->tables['tblse_wa_conversations'] as &$c) { $c['window_expires_at'] = date('Y-m-d H:i:s', time() - 86400); }
unset($c);
$r = se_journey_quote_respond(se_test_journey_row(), 'accept', 'page');
se_eq(true, $r['ok'], 'accepted from the page');
se_ok(strpos((string) $r['book_link'], '/book') !== false, 'the page gets the calendar link to redirect to');
se_eq('quote_accepted', se_test_journey_row()->state, 'state: quote_accepted');
$rows = $db->rows('tblse_journey_quotes'); $q = end($rows);
se_eq('page', $q['patient_response_via'], 'channel recorded as the page');
se_ok(count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'window_closed' || $t['kind'] === 'template_blocked'; })) >= 1,
      'the WhatsApp copy of the link could not go (window closed, template not approved) — staff see a task; the page itself carried the patient on');
$pub = se_journey_quote_public($mm[1], '203.0.113.1', 'UA');
se_eq('accepted', $pub['response'], 'the page shows the acceptance');

$r = se_journey_quote_respond(se_test_journey_row(), 'revise', 'page');
se_eq('quote_revision_requested', se_test_journey_row()->state, 'a change of mind to a revision is allowed');
se_eq(['ok' => false, 'reason' => 'bad_action', 'book_link' => ''], se_journey_quote_respond(se_test_journey_row(), 'delete', 'page'), 'unknown actions are refused');

// The model: a system booking must name a brand; a staff request without access is still refused.
$model = se_journey_appointments_model();
$slot = date('Y-m-d', strtotime('+4 days')) . ' 10:00:00';
se_eq(false, $model->add(['brand_id' => 0, 'title' => 't', 'rel_type' => 'lead', 'rel_id' => 1, 'staff_id' => 10, 'start_at' => $slot, 'end_at' => date('Y-m-d', strtotime('+4 days')) . ' 10:30:00', 'status' => 'scheduled'], ['system' => true]), 'system + brand 0 → refused');
se_eq(false, $model->add(['brand_id' => 1, 'title' => 't', 'rel_type' => 'lead', 'rel_id' => 1, 'staff_id' => 10, 'start_at' => $slot, 'end_at' => date('Y-m-d', strtotime('+4 days')) . ' 10:30:00', 'status' => 'scheduled']), 'no staff session and no system flag → refused');
se_journey_transition(se_test_journey_row(), 'closed_lost', 'test', 'staff', 10);
se_eq('state', se_journey_booking_pick(se_test_journey_row(), $slot, 'page')['reason'], 'a still-valid calendar link cannot book once the journey is closed');

/* ======================================================================== */
se_group('Journey quote: sent straight from ready_for_review (no "open review") — the journey still enters the quote phase');

// Production 2026-09-03: staff drafted, approved and sent the quote from the
// review tab without pressing "open review". The journey stayed at
// ready_for_review, so the patient's "Fiyat Revizyonu" tap was filed as a
// message during the photo/review phase and the review tab kept saying
// "no answer yet".
se_test_journey_reviewed();
$db = se_test_db();
se_test_act_as(10, [], true);
se_eq('ready_for_review', se_test_journey_row()->state, 'photos in, review not opened');
$j = se_test_journey_row();
se_journey_quote_draft($j, ['currency' => 'EUR', 'amount_min' => '1500', 'amount_max' => '2200', 'show_amount' => 1, 'valid_until' => '+30 days',
    'included' => "Ön görüşme\nİşlem", 'recommendation' => 'procedure_after_consultation'], 10);
$rows = $db->rows('tblse_journey_quotes'); $q = end($rows);
se_journey_quote_approve((int) $q['id'], 10);
$r = se_journey_quote_send((int) $q['id'], 10);
se_wa_out_drain();
se_eq(true, $r['ok'], 'sent');
se_eq('quote_sent', se_test_journey_row()->state, 'state: quote_sent — the approved quote IS the review decision');
$trig = array_column(array_filter($db->rows('tblse_journey_transitions'), function ($t) { return in_array($t['to_state'], ['under_review', 'quote_pending_staff_approval', 'quote_sent'], true); }), 'trigger_key');
se_eq(['quote_prepared', 'quote_prepared', 'quote_sent'], $trig, 'walked ready_for_review → under_review → pending approval → quote_sent, each logged');
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'jr_quote_revise', 'title' => 'Fiyat Revizyonu']]]));
$rows = $db->rows('tblse_journey_quotes'); $q = end($rows);
se_eq('revision_requested', $q['patient_response'], 'the tap is the patient\'s answer');
se_eq('quote_revision_requested', se_test_journey_row()->state, 'state follows');

/* ======================================================================== */
se_group('Journey quote: a quote sent before the fix (state left at ready_for_review) — the answer still lands');

se_test_journey_reviewed();
$db = se_test_db();
se_test_act_as(10, [], true);
$j = se_test_journey_row();
se_journey_quote_draft($j, ['currency' => 'EUR', 'amount_min' => '1500', 'amount_max' => '2200', 'show_amount' => 1, 'valid_until' => '+30 days',
    'included' => "Ön görüşme", 'recommendation' => 'procedure_after_consultation'], 10);
$rows = $db->rows('tblse_journey_quotes'); $q = end($rows);
se_journey_quote_approve((int) $q['id'], 10);
se_journey_quote_send((int) $q['id'], 10);
se_wa_out_drain();
// What production looked like: quote row "sent", journey state never moved.
foreach ($db->tables['tblse_journeys'] as &$jr) { $jr['state'] = 'ready_for_review'; }
unset($jr);
$rows = $db->rows('tblse_journey_quotes'); $q = end($rows);
se_eq(['sent', null], [$q['status'], $q['patient_response'] ?? null], 'sent quote, no answer, stale state');
$sentBefore = count($GLOBALS['se_wa_sent']);
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'jr_quote_revise', 'title' => 'Fiyat Revizyonu']]]));
se_wa_out_drain();
$rows = $db->rows('tblse_journey_quotes'); $q = end($rows);
se_eq('revision_requested', $q['patient_response'], 'the answer is recorded although the state was stale');
se_eq('quote_revision_requested', se_test_journey_row()->state, 'and the state is repaired into the quote phase');
se_ok(in_array('quote_phase_repair', array_column($db->rows('tblse_journey_transitions'), 'trigger_key'), true), 'the repair is an explicit, logged transition');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'quote_revision'; })), 'staff task for the revision');
se_ok(count($GLOBALS['se_wa_sent']) > $sentBefore, 'the patient got the acknowledgement');
// A plain question in a stale state is still an ordinary staff message (no repair without an answer).
foreach ($db->tables['tblse_journeys'] as &$jr) { $jr['state'] = 'ready_for_review'; }
unset($jr);
foreach ($db->tables['tblse_journey_quotes'] as &$qr) { $qr['patient_response'] = null; }
unset($qr);
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Merhaba, bir sorum var', se_test_wamid()));
se_eq('ready_for_review', se_test_journey_row()->state, 'a question does not move the state');

/* ======================================================================== */
se_group('Journey quote: once approved, consultation information (procedure/prep/recovery links) follows the quote');

se_eq(['https://azinasgari.com/tr/procedure', 'https://azinasgari.com/tr/preparation', 'https://azinasgari.com/tr/recovery'],
      array_values(se_journey_consultation_info_urls()), 'the three published, already-reviewed pages — nothing invented here');

se_test_journey_reviewed();
$GLOBALS['se_test']['options']['se_journey_consultation_info_approved_1'] = 1;
$tasksBefore = count($db->rows('tblse_journey_tasks'));
$sent = se_test_quote_sent();
se_eq(true, $sent['send']['ok'], 'quote still sends');
$msgs = array_values(array_filter($db->rows('tblse_wa_outbound'), function ($o) { return $o['origin'] === 'journey:consultation_information'; }));
se_eq(1, count($msgs), 'one consultation-information message queued, right behind the quote');
$body = $msgs[0]['body'];
foreach (se_journey_consultation_info_urls() as $url) { se_ok(strpos($body, $url) !== false, 'body carries ' . $url); }
se_ok(strpos($body, 'garanti') === false && strpos($body, 'Dr.') === false && strpos($body, 'Doktor') === false, 'no guarantee language, no clinical title');
se_eq(0, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) use ($tasksBefore) { return $t['kind'] === 'consultation_info_unapproved'; })), 'approved: no "unapproved" task this time');

$GLOBALS['se_test']['options']['se_journey_consultation_info_approved_1'] = 0;
