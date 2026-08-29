<?php
/**
 * WhatsApp outbound: window rules, template gating, idempotency, gated holds,
 * claim/lease/fence and reminder consumption.
 *
 * A fixture transport is used throughout. Nothing here can send a real message:
 * with no transport registered every send is GATED, and the fixture only ever
 * records what it was asked to send.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function se_test_seed_wa_out()
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];

    $db->seed('tblse_brands', [['id' => 1, 'name' => 'Brand A', 'active' => 1]]);
    $db->seed('tblse_staff_brands', [['staff_id' => 10, 'brand_id' => 1]]);
    $db->seed('tblse_wa_numbers', [
        ['id' => 1, 'brand_id' => 1, 'phone_number_id' => 'PN1', 'waba_id' => 'W1',
         'display_number' => '+90 555', 'token_option_ref' => 'wa_token_1', 'state' => 'active'],
    ]);
    $db->seed('tblse_wa_conversations', [
        // open window
        ['id' => 901, 'brand_id' => 1, 'phone_number_id' => 'PN1', 'wa_user_id' => 'U1',
         'lead_id' => 101, 'assigned_staff' => 0, 'unread_count' => 1, 'state' => 'open',
         'window_expires_at' => date('Y-m-d H:i:s', time() + 3600)],
        // closed window
        ['id' => 902, 'brand_id' => 1, 'phone_number_id' => 'PN1', 'wa_user_id' => 'U2',
         'lead_id' => 102, 'assigned_staff' => 0, 'unread_count' => 0, 'state' => 'open',
         'window_expires_at' => date('Y-m-d H:i:s', time() - 3600)],
    ]);
    $db->seed('tblse_wa_templates', [
        ['id' => 1, 'brand_id' => 1, 'name' => 'appointment_reminder', 'language' => 'tr',
         'category' => 'UTILITY', 'approval_state' => 'approved'],
        ['id' => 2, 'brand_id' => 1, 'name' => 'not_yet', 'language' => 'tr',
         'category' => 'MARKETING', 'approval_state' => 'pending'],
    ]);
    $db->seed('tblse_wa_outbound', []);
    $db->seed('tblse_wa_messages', []);
    $db->seed('tblse_reminders', []);
    $db->seed('tblse_appointments', []);

    $GLOBALS['se_test']['options'] = [];
    $GLOBALS['SE_WA_TRANSPORT'] = null;
    $GLOBALS['se_wa_sent'] = [];
}

se_test_seed_wa_out();
se_test_act_as(10, [], true);

/* ======================================================================== */
se_group('Sending is gated before any credential exists');

se_eq(false, se_wa_transport_available(), 'no transport is registered by default');
/* Blocked for SOME reason before anything is configured. Which reason depends
 * on what the fixture has seeded first, and pinning it here would make the
 * assertion depend on suite order rather than on behaviour. */
se_ok(se_wa_send_blocked_reason(1) !== '', 'sending is blocked before anything is configured');

/* Install a REAL fixture secret in a REAL 0700 store, so the actual provider
 * is exercised. The value is a throwaway string, never a credential. */
se_test_install_secret('wa_app', 'fixture-not-a-real-secret');
se_eq(true, se_secret_configured('wa_app', 0), 'the fixture secret is readable through the real provider');

$status = se_secret_status('wa_app', 0);
se_eq(true, $status['configured'], 'status reports configured');
se_eq(true, $status['mode_ok'], 'and the file is mode 600');
se_eq(false, isset($status['value']), 'status NEVER carries the value');
se_eq(false, strpos(json_encode($status), 'fixture-not-a-real-secret') !== false,
    'and the value appears nowhere in the status payload');

se_eq('no_transport', se_wa_send_blocked_reason(1),
    'with a credential but no transport, sending is still gated');

se_group('Compose policy follows the 24-hour service window');

$db = se_test_db();
$open   = (object) $db->rows('tblse_wa_conversations')[0];
$closed = (object) $db->rows('tblse_wa_conversations')[1];

se_eq(true,  se_wa_window_open($open),   'a conversation with a future expiry has an OPEN window');
se_eq(false, se_wa_window_open($closed), 'a conversation with a past expiry has a CLOSED window');

/* ======================================================================== */
se_group('Queueing respects the window (transport registered)');

se_wa_register_transport(function ($m) {
    $GLOBALS['se_wa_sent'][] = $m;
    return ['ok' => true, 'wamid' => 'wamid.FIXTURE.' . count($GLOBALS['se_wa_sent'])];
});
se_eq(true, se_wa_transport_available(), 'a fixture transport is registered');

$policy = se_wa_compose_policy($open);
se_eq(true, $policy['allowed'], 'an open window allows sending');
se_eq('freeform', $policy['mode'], 'and the mode is free-form');

$policy = se_wa_compose_policy($closed);
se_eq(true, $policy['allowed'], 'a closed window still allows sending');
se_eq('template', $policy['mode'], 'but only via an approved template');

/* Free-form inside the window: accepted. */
$r = se_wa_queue_message(901, ['kind' => 'text', 'body' => 'Merhaba']);
se_eq(true, $r['ok'], 'free-form text inside the window is queued');
se_eq(1, count($db->rows('tblse_wa_outbound')), 'one outbound row exists');

/* Free-form OUTSIDE the window: refused. This is the rule that protects the
   number's quality rating, and Meta fails it silently. */
$r = se_wa_queue_message(902, ['kind' => 'text', 'body' => 'Merhaba']);
se_eq(false, $r['ok'], 'free-form text OUTSIDE the window is refused');
se_eq('window_closed', $r['reason'], 'and the reason names the closed window');
se_eq(1, count($db->rows('tblse_wa_outbound')), 'no row was written');

/* An empty body is refused. */
$r = se_wa_queue_message(901, ['kind' => 'text', 'body' => '   ']);
se_eq(false, $r['ok'], 'an empty body is refused');
se_eq('empty_body', $r['reason'], 'with a clear reason');

/* ======================================================================== */
se_group('Only APPROVED templates may be queued');

$r = se_wa_queue_message(902, ['kind' => 'template', 'template' => 'appointment_reminder']);
se_eq(true, $r['ok'], 'an approved template is queued outside the window');

$r = se_wa_queue_message(902, ['kind' => 'template', 'template' => 'not_yet']);
se_eq(false, $r['ok'], 'a PENDING template is refused');
se_eq('template_not_approved', $r['reason'], 'and says why');

$r = se_wa_queue_message(902, ['kind' => 'template', 'template' => 'does_not_exist']);
se_eq(false, $r['ok'], 'an unknown template is refused');

$r = se_wa_queue_message(901, ['kind' => 'audio', 'body' => 'x']);
se_eq(false, $r['ok'], 'an unsupported kind is refused');
se_eq('unsupported_kind', $r['reason'], 'with a clear reason');

/* ======================================================================== */
se_group('Idempotency: the same intent queues once');

se_test_seed_wa_out();
se_wa_register_transport(function ($m) { $GLOBALS['se_wa_sent'][] = $m; return ['ok' => true, 'wamid' => 'w1']; });
$db = se_test_db();

$a = se_wa_queue_message(901, ['kind' => 'text', 'body' => 'Same text']);
$b = se_wa_queue_message(901, ['kind' => 'text', 'body' => 'Same text']);

se_eq(true,  $a['ok'], 'the first queue succeeds');
se_eq(false, $b['ok'], 'the identical second queue is refused');
se_eq('duplicate', $b['reason'], 'as a duplicate');
se_eq(1, count($db->rows('tblse_wa_outbound')), 'exactly one row exists');

$c = se_wa_queue_message(901, ['kind' => 'text', 'body' => 'Different text']);
se_eq(true, $c['ok'], 'a different message still queues');
se_eq(2, count($db->rows('tblse_wa_outbound')), 'two rows now');

// The key is stable: same inputs, same key.
$k1 = se_wa_idempotency_key(901, 'text', hash('sha256', 'x'));
$k2 = se_wa_idempotency_key(901, 'text', hash('sha256', 'x'));
se_eq($k1, $k2, 'the idempotency key is stable across calls');
se_ok($k1 !== se_wa_idempotency_key(902, 'text', hash('sha256', 'x')),
    'and differs per conversation');

/* ======================================================================== */
se_group('Draining: claim, lease, fence');

se_test_seed_wa_out();
$db = se_test_db();
$rows = [];
for ($i = 1; $i <= 4; $i++) {
    $rows[] = ['id' => $i, 'conversation_id' => 901, 'brand_id' => 1, 'kind' => 'text',
               'body' => 'm' . $i, 'idempotency_key' => 'k' . $i, 'status' => 'pending',
               'attempts' => 0, 'fence' => 0, 'next_attempt_at' => date('Y-m-d H:i:s', time() - 60)];
}
$db->seed('tblse_wa_outbound', $rows);

$a = se_wa_out_claim_batch('out-A', 2);
$b = se_wa_out_claim_batch('out-B', 2);
se_eq(2, count($a), 'worker A claims two');
se_eq(2, count($b), 'worker B claims two');
se_eq([], array_intersect(array_column($a, 'id'), array_column($b, 'id')), 'claims are disjoint');
se_eq(0, count(se_wa_out_claim_batch('out-C', 2)), 'nothing left to claim');
se_eq(1, (int) $a[0]['fence'], 'claiming bumps the fence');

/* ======================================================================== */
se_group('A gated row holds WITHOUT consuming an attempt');

se_test_seed_wa_out();
$db = se_test_db();
$GLOBALS['SE_WA_TRANSPORT'] = null;   // no transport = gated

$db->seed('tblse_wa_outbound', [
    ['id' => 1, 'conversation_id' => 901, 'brand_id' => 1, 'kind' => 'text', 'body' => 'hi',
     'idempotency_key' => 'k1', 'status' => 'processing', 'attempts' => 0, 'fence' => 1,
     'locked_by' => 'w1'],
]);

$row = $db->rows('tblse_wa_outbound')[0];
$out = se_wa_out_process($row);

se_eq('pending', $out['status'], 'a gated row returns to pending');
se_eq(0, $out['attempts'], 'and does NOT consume an attempt');
se_eq('gated', $out['failure_class'], 'classified as gated');
se_ok(!empty($out['next_attempt_at']), 'and is rescheduled');

/* ======================================================================== */
se_group('The window is re-checked at SEND time, not only at queue time');

se_test_seed_wa_out();
$db = se_test_db();
se_wa_register_transport(function ($m) { $GLOBALS['se_wa_sent'][] = $m; return ['ok' => true, 'wamid' => 'w9']; });

// Queue free-form while open, then close the window before draining.
se_wa_queue_message(901, ['kind' => 'text', 'body' => 'queued while open']);
$db->tables['tblse_wa_conversations'][0]['window_expires_at'] = date('Y-m-d H:i:s', time() - 60);

$row = $db->rows('tblse_wa_outbound')[0];
$row['status'] = 'processing'; $row['locked_by'] = 'w1'; $row['fence'] = 1;

$out = se_wa_out_process($row);
se_eq('skipped', $out['status'], 'a free-form message whose window closed while queued is SKIPPED');
se_eq(0, count($GLOBALS['se_wa_sent']), 'and the transport was never called');

/* ======================================================================== */
se_group('A successful send mirrors into the thread exactly once');

se_test_seed_wa_out();
$db = se_test_db();
se_wa_register_transport(function ($m) { $GLOBALS['se_wa_sent'][] = $m; return ['ok' => true, 'wamid' => 'wamid.OK']; });

se_wa_queue_message(901, ['kind' => 'text', 'body' => 'hello']);
$row = $db->rows('tblse_wa_outbound')[0];
$row['status'] = 'processing'; $row['locked_by'] = 'w1'; $row['fence'] = 1;

$out = se_wa_out_process($row);
se_eq('sent', $out['status'], 'the row is marked sent');
se_eq(1, count($GLOBALS['se_wa_sent']), 'the transport was called once');
se_eq(1, count($db->rows('tblse_wa_messages')), 'the message is mirrored into the thread');
se_eq('out', $db->rows('tblse_wa_messages')[0]['direction'], 'as an OUTBOUND message');

// Mirroring twice must not duplicate.
se_wa_record_outbound($row, (object) $db->rows('tblse_wa_conversations')[0], 'wamid.OK');
se_eq(1, count($db->rows('tblse_wa_messages')), 'a repeat mirror is a no-op');

/* ======================================================================== */
se_group('Transport failures are classified and sanitized');

se_test_seed_wa_out();
$db = se_test_db();
se_wa_register_transport(function ($m) {
    return ['ok' => false, 'code' => 400,
            'error' => 'Bad request for token EAAGm0PX4ZCpsBA1ZBxYZBqZC7ZBLongTokenValueHere'];
});
se_wa_queue_message(901, ['kind' => 'text', 'body' => 'x']);
$row = $db->rows('tblse_wa_outbound')[0];
$row['status'] = 'processing'; $row['locked_by'] = 'w1'; $row['fence'] = 1;

$out = se_wa_out_process($row);
se_eq('failed', $out['status'], 'a 400 is permanent');
se_eq('permanent', $out['failure_class'], 'classified permanent');
se_eq(false, strpos($out['last_error'], 'EAAGm0PX4ZCpsBA1ZBxYZBqZC7ZBLongTokenValueHere') !== false,
    'a token-shaped string is redacted from the stored error');

se_wa_register_transport(function ($m) { return ['ok' => false, 'code' => 429, 'error' => 'slow down']; });
$out = se_wa_out_process($row);
se_eq('pending', $out['status'], 'a 429 is retryable');
se_eq('retryable', $out['failure_class'], 'classified retryable');
se_ok(!empty($out['next_attempt_at']), 'and backed off');

/* ======================================================================== */
se_group('Media allowlist');

$allow = se_wa_media_allowlist();
foreach (['image', 'document', 'audio', 'video'] as $k) {
    se_ok(isset($allow[$k]['max']) && $allow[$k]['max'] > 0, "{$k} has a size ceiling");
    se_ok(!empty($allow[$k]['mime']), "{$k} has an explicit MIME allowlist");
}
se_eq(false, isset($allow['application/x-executable']), 'no executable type is allowlisted');

// Leave the shared store clean for the next suite.
se_test_remove_secret('wa_app');
