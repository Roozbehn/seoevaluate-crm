<?php
/**
 * WhatsApp call log.
 *
 * The CRM records calls and never answers them — answering needs a WebRTC or
 * SIP media stack the host cannot run, and accepting a call we would then drop
 * is worse for a patient than a phone ringing in the WhatsApp Business app.
 * These tests pin the recording, the two-webhook lifecycle, and the one thing
 * the feature exists for: a missed call that leaves a trace.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function se_test_seed_calls()
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];
    $db->seed('tblse_wa_calls', []);
    $db->seed('tblse_wa_conversations', [
        ['id' => 3, 'brand_id' => 22, 'wa_user_id' => '905551112233', 'assigned_staff' => 11],
        ['id' => 4, 'brand_id' => 22, 'wa_user_id' => '905559998877', 'assigned_staff' => 0],
    ]);
    $db->seed('tblse_staff_brands', [['staff_id' => 10, 'brand_id' => 22], ['staff_id' => 11, 'brand_id' => 22]]);
    $db->seed('tblse_push_subscriptions', []);
    $GLOBALS['se_test']['options'] = [];
    se_push_register_http(null);
}

se_test_seed_calls();

/* ======================================================================== */
se_group('A ringing call is recorded and attached to its thread');

se_ok(se_wa_handle_call(22, 'PNID', [
    'id' => 'wacid.1', 'from' => '905551112233', 'to' => 'PNID',
    'event' => 'connect', 'direction' => 'USER_INITIATED', 'timestamp' => '1756900000',
]), 'a connect webhook is handled');

$rows = se_test_db()->rows('tblse_wa_calls');
se_eq(1, count($rows), 'one call row');
se_eq(3, (int) $rows[0]['conversation_id'], 'attached to the caller\'s existing thread');
se_eq('ringing', $rows[0]['state'], 'state is ringing until it terminates');
se_eq('USER_INITIATED', $rows[0]['direction'], 'direction recorded');
// Computed, not hardcoded: the expectation must not encode the machine's timezone.
se_eq(date('Y-m-d H:i:s', 1756900000), $rows[0]['started_at'], 'the UNIX timestamp is stored as a datetime');

// One call is two webhooks and Meta redelivers both. A repeated connect must
// not create a second row — and must not ring the phone again.
se_eq(false, se_wa_handle_call(22, 'PNID', [
    'id' => 'wacid.1', 'from' => '905551112233', 'event' => 'connect', 'timestamp' => '1756900000',
]), 'a redelivered connect is ignored');
se_eq(1, count(se_test_db()->rows('tblse_wa_calls')), 'and creates no second row');

/* A call from a number nobody has messaged is still worth recording. */
se_wa_handle_call(22, 'PNID', [
    'id' => 'wacid.2', 'from' => '905550000000', 'event' => 'connect', 'timestamp' => '1756900100',
]);
$stranger = se_test_db()->rows('tblse_wa_calls')[1];
se_eq(0, (int) $stranger['conversation_id'], 'no thread yet is recorded honestly as 0, not invented');
se_eq('905550000000', $stranger['wa_user_id'], 'and the number is kept so it can be matched later');

/* ======================================================================== */
se_group('Terminate closes the record, and says whether anyone picked up');

se_wa_handle_call(22, 'PNID', [
    'id' => 'wacid.1', 'event' => 'terminate', 'status' => 'COMPLETED',
    'duration' => 120, 'start_time' => '1756900000', 'end_time' => '1756900120',
]);
$row = se_test_db()->rows('tblse_wa_calls')[0];
se_eq('ended', $row['state'], 'state moves to ended');
se_eq('COMPLETED', $row['status'], "Meta's own status is kept verbatim");
se_eq(120, (int) $row['duration'], 'duration in seconds');
se_eq(date('Y-m-d H:i:s', 1756900120), $row['ended_at'], 'end time recorded');
se_eq(2, count(se_test_db()->rows('tblse_wa_calls')), 'terminate updates, never inserts a duplicate');

// Meta can drop the connect webhook. A terminate that arrives alone must still
// produce a record rather than being discarded.
se_wa_handle_call(22, 'PNID', [
    'id' => 'wacid.orphan', 'from' => '905551112233', 'event' => 'terminate',
    'status' => 'FAILED', 'duration' => 0, 'start_time' => '1756901000',
]);
$orphan = null;
foreach (se_test_db()->rows('tblse_wa_calls') as $r) {
    if ($r['call_id'] === 'wacid.orphan') { $orphan = $r; }
}
se_ok($orphan !== null, 'a terminate with no preceding connect still records the call');
se_eq(3, (int) $orphan['conversation_id'], 'and still finds the thread by number');

se_eq(false, se_wa_handle_call(22, 'PNID', ['id' => 'x', 'event' => 'ringing']), 'an unknown event is ignored');
se_eq(false, se_wa_handle_call(22, 'PNID', ['event' => 'connect']), 'a call with no id is ignored');

/* ======================================================================== */
se_group('The missed call is the one that must reach a human');

se_test_seed_calls();
se_test_install_secret('webpush_vapid', json_encode(se_push_vapid_generate()));
$b = se_test_fake_browser();
se_push_subscribe(11, 'https://fcm.googleapis.com/fcm/send/assignee', $b['p256dh'], $b['auth']);
se_push_subscribe(10, 'https://fcm.googleapis.com/fcm/send/other', $b['p256dh'], $b['auth']);

$pushes = [];
se_push_register_http(function ($e, $h, $body) use (&$pushes, $b) {
    $pushes[] = json_decode(se_test_push_decrypt($body, $b), true);
    return ['status' => 201, 'transport_error' => false];
});

se_wa_handle_call(22, 'PNID', [
    'id' => 'wacid.9', 'from' => '905551112233', 'event' => 'connect', 'timestamp' => '1756900000',
]);
se_eq(1, count($pushes), 'a ringing call notifies the assignee');
se_eq('WhatsApp araması', $pushes[0]['title'], 'and says only that a patient is calling');
foreach (['905551112233', '90555'] as $banned) {
    se_eq(false, strpos(json_encode($pushes[0]), $banned) !== false, 'no phone number in the payload');
}

$pushes = [];
se_wa_handle_call(22, 'PNID', [
    'id' => 'wacid.9', 'event' => 'terminate', 'status' => 'COMPLETED', 'duration' => 95,
]);
se_eq(0, count($pushes), 'an ANSWERED call raises nothing — it was handled');

$pushes = [];
se_wa_handle_call(22, 'PNID', ['id' => 'wacid.10', 'from' => '905559998877', 'event' => 'connect', 'timestamp' => '1756900200']);
$pushes = [];
se_wa_handle_call(22, 'PNID', ['id' => 'wacid.10', 'event' => 'terminate', 'status' => 'FAILED', 'duration' => 0]);
se_eq(2, count($pushes), 'an UNANSWERED call reaches the whole brand — the thread is unassigned');
se_eq('Cevapsız WhatsApp araması', $pushes[0]['title'], 'and says it was missed');
// The two notifications say different things and the second is the one that
// needs acting on, so neither may replace the other.
se_ok($pushes[0]['tag'] !== 'call-4', 'the missed-call tag differs from the ringing one');

// COMPLETED with no duration is not an answered call.
$pushes = [];
se_wa_handle_call(22, 'PNID', ['id' => 'wacid.11', 'from' => '905559998877', 'event' => 'connect', 'timestamp' => '1756900300']);
$pushes = [];
se_wa_handle_call(22, 'PNID', ['id' => 'wacid.11', 'event' => 'terminate', 'status' => 'COMPLETED', 'duration' => 0]);
se_eq(2, count($pushes), 'COMPLETED with zero duration counts as missed, not answered');

se_push_register_http(null);
