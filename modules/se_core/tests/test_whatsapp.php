<?php
/**
 * WhatsApp inbound: signature verification, body-size limits, dedup races,
 * brand-bound status callbacks, conversation brand-mismatch refusal, claim /
 * lease / backoff, retention purge and assignment authorization.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function se_test_seed_wa()
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];

    $db->seed('tblse_brands', [
        ['id' => 1, 'name' => 'Brand A', 'active' => 1],
        ['id' => 2, 'name' => 'Brand B', 'active' => 1],
    ]);
    $db->seed('tblse_staff_brands', [
        ['staff_id' => 10, 'brand_id' => 1],
        ['staff_id' => 20, 'brand_id' => 2],
    ]);
    $db->seed('tblse_wa_numbers', [
        ['id' => 1, 'brand_id' => 1, 'phone_number_id' => 'PN1'],
        ['id' => 2, 'brand_id' => 2, 'phone_number_id' => 'PN2'],
    ]);
    $db->seed('tblse_wa_conversations', [
        ['id' => 901, 'brand_id' => 1, 'phone_number_id' => 'PN1', 'wa_user_id' => 'U1',
         'unread_count' => 0, 'assigned_staff' => 0, 'lead_id' => 101, 'state' => 'open'],
        ['id' => 902, 'brand_id' => 2, 'phone_number_id' => 'PN2', 'wa_user_id' => 'U2',
         'unread_count' => 0, 'assigned_staff' => 0, 'lead_id' => 202, 'state' => 'open'],
    ]);
    $db->seed('tblse_wa_messages', [
        ['id' => 911, 'brand_id' => 1, 'conversation_id' => 901, 'wamid' => 'wamid.SHARED',
         'delivery_state' => 'sent'],
        ['id' => 912, 'brand_id' => 2, 'conversation_id' => 902, 'wamid' => 'wamid.SHARED',
         'delivery_state' => 'sent'],
    ]);
    $db->seed('tblse_wa_webhook_events', []);
    $db->seed('tblse_wa_metering', []);
    $GLOBALS['se_test']['options'] = [];
}

se_test_seed_wa();
se_test_act_as(1, [], true);

/* ======================================================================== */
se_group('Signature verification');

$secret = 'test-app-secret';
$body   = '{"entry":[{"id":"WABA1"}]}';
$good   = 'sha256=' . hash_hmac('sha256', $body, $secret);

se_eq(true,  se_wa_verify_signature($body, $good, $secret), 'a correct signature verifies');
se_eq(false, se_wa_verify_signature($body . 'x', $good, $secret), 'a tampered body fails');
se_eq(false, se_wa_verify_signature($body, 'sha256=deadbeef', $secret), 'a wrong signature fails');
se_eq(false, se_wa_verify_signature($body, $good, 'other-secret'), 'a wrong secret fails');
se_eq(false, se_wa_verify_signature($body, '', $secret), 'a missing header fails');
se_eq(false, se_wa_verify_signature($body, hash_hmac('sha256', $body, $secret), $secret),
    'a header without the sha256= prefix fails');
se_eq(false, se_wa_verify_signature($body, $good, ''), 'an unconfigured secret fails closed');
se_eq('••••8549', se_wa_redacted_contact('90 531 432 8549'),
    'evidence labels retain only the final four contact digits');
se_eq('[redacted]', se_wa_redacted_contact(''),
    'an empty contact has a safe evidence label');

/* ======================================================================== */
se_group('Body-size limit');

se_test_seed_wa();
$db = se_test_db();

$huge = str_repeat('a', SE_WA_MAX_BODY_BYTES + 1);
$res  = se_wa_store_event($huge, true);
se_eq(true, !empty($res['oversize']), 'an oversized body is refused');
se_eq(false, $res['stored'], 'nothing is stored for an oversized body');
se_eq(0, count($db->rows('tblse_wa_webhook_events')), 'the events table stays empty');

$ok = se_wa_store_event('{"entry":[{"id":"W","changes":[{"value":{"metadata":{"phone_number_id":"PN1"}}}]}]}', true);
se_eq(true, $ok['stored'], 'a normal body is stored');

/* ======================================================================== */
se_group('Duplicate delivery is a no-op, not an error');

$payload = '{"entry":[{"id":"W","changes":[{"value":{"metadata":{"phone_number_id":"PN1"}}}]}]}';
$again = se_wa_store_event($payload, true);
se_eq(false, $again['stored'], 'a repeated delivery is not stored again');
se_eq(true,  $again['duplicate'], 'and is reported as a duplicate');
se_eq(1, count($db->rows('tblse_wa_webhook_events')), 'only one row exists');

/* ======================================================================== */
se_group('Status callbacks are bound to the routed brand');

se_test_seed_wa();
$db = se_test_db();

// Both brands hold a message with the SAME wamid. A Brand A callback must only
// ever touch Brand A's row.
se_wa_handle_status(1, ['id' => 'wamid.SHARED', 'status' => 'delivered']);

$rows = $db->rows('tblse_wa_messages');
se_eq('delivered', $rows[0]['delivery_state'], "Brand A's message advanced");
se_eq('sent', $rows[1]['delivery_state'], "Brand B's identically-keyed message was NOT touched");

se_wa_handle_status(2, ['id' => 'wamid.SHARED', 'status' => 'read']);
$rows = $db->rows('tblse_wa_messages');
se_eq('delivered', $rows[0]['delivery_state'], "Brand A's message unaffected by Brand B's callback");
se_eq('read', $rows[1]['delivery_state'], "Brand B's message advanced by its own callback");

/* Out-of-order callbacks never move the state backwards. */
se_wa_handle_status(1, ['id' => 'wamid.SHARED', 'status' => 'sent']);
se_eq('delivered', $db->rows('tblse_wa_messages')[0]['delivery_state'],
    'a late "sent" callback does not regress a delivered message');

/* A failure is always applied. */
se_wa_handle_status(1, ['id' => 'wamid.SHARED', 'status' => 'failed']);
se_eq('failed', $db->rows('tblse_wa_messages')[0]['delivery_state'], 'a failure status is applied');

/* An unknown wamid for this brand is ignored. */
se_wa_handle_status(1, ['id' => 'wamid.UNKNOWN', 'status' => 'read']);
se_ok(true, 'an unknown wamid is ignored without error');

/* ======================================================================== */
se_group('Coexistence handset echoes are mirrored exactly once');

se_test_seed_wa();
$db = se_test_db();
$echoTs = time() - 120;
$echo = [
    'from' => 'BUSINESS1',
    'to' => 'U1',
    'to_user_id' => 'META-OPAQUE-USER',
    'id' => 'wamid.HANDSET1',
    'timestamp' => (string) $echoTs,
    'type' => 'text',
    'text' => ['body' => 'sent from the handset'],
];

se_wa_handle_echo(1, 'PN1', $echo, ['wa_id' => 'U1']);
$rows = array_values(array_filter($db->rows('tblse_wa_messages'), function ($r) {
    return ($r['wamid'] ?? '') === 'wamid.HANDSET1';
}));
se_eq(1, count($rows), 'one handset message is mirrored');
se_eq(901, (int) $rows[0]['conversation_id'], 'the real contacts[].wa_id selects the existing conversation');
se_eq('out', $rows[0]['direction'], 'the handset message is outbound');
se_eq('handset', $rows[0]['source'], 'its source is explicitly recorded as handset');
se_eq('sent from the handset', $rows[0]['body'], 'the text body is retained');
se_eq(date('Y-m-d H:i:s', $echoTs), $rows[0]['sent_at'], 'Meta timestamp is retained');
se_eq(0, (int) $db->rows('tblse_wa_conversations')[0]['unread_count'], 'an echo does not create local unread');

se_wa_handle_echo(1, 'PN1', $echo, ['wa_id' => 'U1']);
$rows = array_values(array_filter($db->rows('tblse_wa_messages'), function ($r) {
    return ($r['wamid'] ?? '') === 'wamid.HANDSET1';
}));
se_eq(1, count($rows), 'replaying the same echo is a no-op');

// Exercise the exact durable-event shape Meta sent in production: the field
// is smb_message_echoes and the array is value.message_echoes.
se_wa_process_event(['payload' => json_encode([
    'entry' => [['id' => 'WABA1', 'changes' => [[
        'field' => 'smb_message_echoes',
        'value' => [
            'metadata' => ['phone_number_id' => 'PN1'],
            'contacts' => [['wa_id' => 'U1']],
            'message_echoes' => [[
                'from' => 'BUSINESS1', 'to' => 'U1', 'to_user_id' => 'META-OPAQUE-USER',
                'id' => 'wamid.HANDSET2', 'timestamp' => (string) $echoTs,
                'type' => 'text', 'text' => ['body' => 'processor path'],
            ]],
        ],
    ]]]],
])]);
$rows = array_values(array_filter($db->rows('tblse_wa_messages'), function ($r) {
    return ($r['wamid'] ?? '') === 'wamid.HANDSET2';
}));
se_eq(1, count($rows), 'the async event processor consumes message_echoes');
se_eq('handset', $rows[0]['source'], 'the processor path preserves source');

// A handset can start a thread. Do not invent an inbound event or service
// window when creating the CRM conversation for it.
se_wa_handle_echo(1, 'PN1', [
    'to' => 'U-NEW', 'id' => 'wamid.HANDSET3', 'timestamp' => (string) $echoTs,
    'type' => 'text', 'text' => ['body' => 'new thread'],
], []);
$newConv = null;
foreach ($db->rows('tblse_wa_conversations') as $r) {
    if (($r['wa_user_id'] ?? '') === 'U-NEW') { $newConv = $r; }
}
se_ok($newConv !== null, 'a handset-first conversation is created');
se_eq(0, (int) $newConv['unread_count'], 'a handset-first conversation starts read');
se_eq(null, $newConv['last_inbound_at'] ?? null, 'no inbound timestamp is fabricated');
se_eq(null, $newConv['window_expires_at'] ?? null, 'no API service window is fabricated');

/* ======================================================================== */
se_group('Conversation brand mismatch is refused');

se_test_seed_wa();

$threw = false;
try {
    // PN1 routes to Brand 1, but we claim Brand 2 for an existing PN1 thread.
    se_wa_handle_inbound(2, 'PN1', ['id' => 'wamid.NEW', 'from' => 'U1', 'type' => 'text',
                                    'text' => ['body' => 'hi'], 'timestamp' => time()], []);
} catch (SeWaPermanentError $e) {
    $threw = true;
}
se_eq(true, $threw, 'appending to a thread whose brand no longer matches the routed number is refused');

/* ======================================================================== */
se_group('Field-length bounds');

se_test_seed_wa();
$db = se_test_db();

$longText = str_repeat('x', SE_WA_MAX_TEXT_LEN + 500);
se_wa_handle_inbound(1, 'PN1', [
    'id' => 'wamid.LONG', 'from' => 'U1', 'type' => 'text',
    'text' => ['body' => $longText], 'timestamp' => time(),
], []);

$msg = null;
foreach ($db->rows('tblse_wa_messages') as $r) {
    if (($r['wamid'] ?? '') === 'wamid.LONG') { $msg = $r; }
}
se_ok($msg !== null, 'the message was stored');
se_eq(SE_WA_MAX_TEXT_LEN, mb_strlen($msg['body']), 'the body is truncated to the documented bound');

/* ======================================================================== */
se_group('Duplicate envelope does not inflate unread or re-extend the window (CRM-M013 / T15)');

se_test_seed_wa();
$db = se_test_db();
$t0 = time() - 7200;
se_wa_handle_inbound(1, 'PN1', ['id' => 'wamid.DUP', 'from' => 'U1', 'type' => 'text', 'text' => ['body' => 'merhaba'], 'timestamp' => $t0], []);
$c1 = null; foreach ($db->rows('tblse_wa_conversations') as $r) { if (($r['wa_user_id'] ?? '') === 'U1') { $c1 = $r; } }
se_eq(1, (int) $c1['unread_count'], 'first delivery: one unread');
// Meta retries the SAME envelope a little later (same wamid, later timestamp).
se_wa_handle_inbound(1, 'PN1', ['id' => 'wamid.DUP', 'from' => 'U1', 'type' => 'text', 'text' => ['body' => 'merhaba'], 'timestamp' => $t0 + 3000], []);
$c2 = null; foreach ($db->rows('tblse_wa_conversations') as $r) { if (($r['wa_user_id'] ?? '') === 'U1') { $c2 = $r; } }
se_eq(1, (int) $c2['unread_count'], 'the retry does not add a second unread');
se_eq($c1['window_expires_at'], $c2['window_expires_at'], 'the retry does not re-extend the 24 h window');
se_eq($c1['last_inbound_at'], $c2['last_inbound_at'], 'nor move last_inbound_at');
se_eq(1, count(array_filter($db->rows('tblse_wa_messages'), function ($r) { return ($r['wamid'] ?? '') === 'wamid.DUP'; })), 'one stored message');

/* ======================================================================== */
se_group('Backoff and retention');

foreach ([1, 2, 3, 4, 5] as $n) {
    $b = se_wa_backoff_seconds($n);
    se_ok($b > 0 && $b <= SE_WA_BACKOFF_CAP, "webhook backoff for attempt {$n} is bounded and positive");
}
$samples = [];
for ($i = 0; $i < 20; $i++) { $samples[] = se_wa_backoff_seconds(3); }
se_ok(count(array_unique($samples)) > 1, 'webhook backoff is jittered');

se_test_seed_wa();
$db = se_test_db();
$db->seed('tblse_wa_webhook_events', [
    ['id' => 1, 'event_hash' => 'h1', 'state' => 'processed', 'payload' => '{"secret":"body"}',
     'received_at' => date('Y-m-d H:i:s', strtotime('-40 days')), 'attempts' => 1, 'fence' => 0],
    ['id' => 2, 'event_hash' => 'h2', 'state' => 'processed', 'payload' => '{"recent":"body"}',
     'received_at' => date('Y-m-d H:i:s'), 'attempts' => 1, 'fence' => 0],
]);

$purged = se_wa_purge_old_payloads();
se_eq(1, $purged, 'exactly the out-of-retention payload is purged');
se_eq(null, $db->rows('tblse_wa_webhook_events')[0]['payload'], 'the old raw payload is dropped');
se_eq('h1', $db->rows('tblse_wa_webhook_events')[0]['event_hash'], 'the dedup hash is KEPT');
se_ok(!empty($db->rows('tblse_wa_webhook_events')[1]['payload']), 'a recent payload is retained');

/* ======================================================================== */
se_group('Webhook events are claimed, not just selected');

se_test_seed_wa();
$db = se_test_db();
$rows = [];
for ($i = 1; $i <= 4; $i++) {
    $rows[] = ['id' => $i, 'event_hash' => 'h' . $i, 'state' => 'pending', 'signature_valid' => 1,
               'payload' => '{}', 'attempts' => 0, 'fence' => 0,
               'next_attempt_at' => date('Y-m-d H:i:s', time() - 60)];
}
$db->seed('tblse_wa_webhook_events', $rows);

$a = se_wa_claim_batch('wa-A', 2);
$b = se_wa_claim_batch('wa-B', 2);
se_eq(2, count($a), 'worker A claims its batch');
se_eq(2, count($b), 'worker B claims its batch');
se_eq([], array_intersect(array_column($a, 'id'), array_column($b, 'id')),
    'two overlapping cron runs claim DISJOINT events (they used to both process all of them)');
se_eq(0, count(se_wa_claim_batch('wa-C', 2)), 'nothing is left to claim');
se_eq(1, (int) $a[0]['fence'], 'claiming bumps the fence');

/* An unsigned event is never claimed. */
se_test_seed_wa();
$db->seed('tblse_wa_webhook_events', [
    ['id' => 1, 'event_hash' => 'h1', 'state' => 'pending', 'signature_valid' => 0,
     'payload' => '{}', 'attempts' => 0, 'fence' => 0, 'next_attempt_at' => date('Y-m-d H:i:s')],
]);
se_eq(0, count(se_wa_claim_batch('wa-D', 5)), 'an event with an invalid signature is never processed');

/* A row past max attempts is not claimed. */
$db->seed('tblse_wa_webhook_events', [
    ['id' => 1, 'event_hash' => 'h1', 'state' => 'pending', 'signature_valid' => 1,
     'payload' => '{}', 'attempts' => SE_WA_MAX_ATTEMPTS, 'fence' => 0,
     'next_attempt_at' => date('Y-m-d H:i:s')],
]);
se_eq(0, count(se_wa_claim_batch('wa-E', 5)), 'an exhausted event is not retried forever');

/* ======================================================================== */
se_group('Lease recovery');

se_test_seed_wa();
$db->seed('tblse_wa_webhook_events', [
    ['id' => 1, 'event_hash' => 'h1', 'state' => 'processing', 'signature_valid' => 1,
     'payload' => '{}', 'attempts' => 0, 'fence' => 1, 'locked_by' => 'dead',
     'locked_at' => date('Y-m-d H:i:s', time() - (SE_WA_LEASE_SECONDS + 60)),
     'next_attempt_at' => date('Y-m-d H:i:s', time() - 60)],
]);
se_eq(1, se_wa_recover_stale(), 'an expired lease is recovered');
se_eq('pending', $db->rows('tblse_wa_webhook_events')[0]['state'], 'the event returns to pending');
se_eq(1, count(se_wa_claim_batch('wa-F', 5)), 'and can be claimed by a live worker');

/* ======================================================================== */
se_group('Routing');

se_test_seed_wa();
se_eq(1, se_wa_route_to_brand('PN1'), 'a known number routes to its brand');
se_eq(2, se_wa_route_to_brand('PN2'), 'a second number routes to its own brand');
se_eq(null, se_wa_route_to_brand('PN-UNKNOWN'), 'an unknown number does not route');
se_eq(null, se_wa_route_to_brand(''), 'a blank number does not route');

/* ======================================================================== */
se_group('Assign: an unmapped staff member is never treated admin-like');

se_test_seed_wa();
se_test_reset();
se_test_act_as(10, []);   // brand-1 staff working conversation 901 (brand 1)

$model = new Se_whatsapp_model();

se_eq(true, $model->assign(901, 10), 'a staff member mapped to the conversation brand can be assigned');
se_eq(false, $model->assign(901, 20), "another tenant's staff member is refused");
se_eq(false, $model->assign(901, 999),
    'a staff member with ZERO brand rows is refused (they used to pass as admin-like)');

$conv = se_test_db()->rows('tblse_wa_conversations')[0];
se_eq(10, (int) $conv['assigned_staff'], 'the refused assignments changed nothing');

se_eq(true, $model->assign(901, 0), 'unassigning (0) still works');

// A real Perfex ADMIN needs no mapping row — admins reach every brand.
se_test_set_admin_ids([999]);
se_eq(true, $model->assign(901, 999), 'an actual admin assignee passes without a mapping');
se_test_set_admin_ids([]);

// Out-of-scope conversation: the acting staff member cannot assign at all.
se_test_reset();
se_test_act_as(10, []);
se_eq(false, $model->assign(902, 20), "a conversation outside the actor's scope cannot be assigned");
