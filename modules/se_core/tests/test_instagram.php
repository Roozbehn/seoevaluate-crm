<?php
/**
 * Instagram Direct: signature verification, event classification (inbound /
 * echo / read / referral), ad-referral first-touch attribution, mid dedup,
 * brand-mismatch refusal, window policy and outbound gates/idempotency.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../../se_instagram/helpers.php';
require_once __DIR__ . '/../../se_instagram/outbound.php';
require_once __DIR__ . '/../se_webhook_state.php';   // live_test evidence recording

function se_test_seed_ig()
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];

    $db->seed('tblse_brands', [['id' => 1, 'name' => 'Brand A', 'active' => 1], ['id' => 2, 'name' => 'Brand B', 'active' => 1]]);
    $db->seed('tblse_staff_brands', [['staff_id' => 10, 'brand_id' => 1]]);
    $db->seed('tblse_ig_accounts', [
        ['id' => 1, 'brand_id' => 1, 'ig_account_id' => 'IG1', 'page_id' => 'PG1', 'state' => 'active'],
        ['id' => 2, 'brand_id' => 2, 'ig_account_id' => 'IG2', 'page_id' => 'PG2', 'state' => 'active'],
    ]);
    $db->seed('tblse_ig_conversations', []);
    $db->seed('tblse_ig_messages', []);
    $db->seed('tblse_ig_webhook_events', []);
    $db->seed('tblse_ig_outbound', []);
    $GLOBALS['se_test']['options'] = [];
    $GLOBALS['SE_IG_TRANSPORT'] = null;
}

se_test_seed_ig();
se_test_act_as(1, [], true);

/* ======================================================================== */
se_group('Instagram signature verification');

$secret = 'test-app-secret';
$body   = '{"object":"instagram","entry":[{"id":"IG1"}]}';
$good   = 'sha256=' . hash_hmac('sha256', $body, $secret);

se_eq(true,  se_ig_verify_signature($body, $good, $secret), 'a correct signature verifies');
se_eq(false, se_ig_verify_signature($body . 'x', $good, $secret), 'a tampered body fails');
se_eq(false, se_ig_verify_signature($body, 'sha256=deadbeef', $secret), 'a wrong signature fails');
se_eq(false, se_ig_verify_signature($body, '', $secret), 'a missing header fails');

se_test_remove_secret('ig_app');
se_test_install_secret('meta_app', 'canonical-app-secret');
se_ok(se_ig_app_secret() === 'canonical-app-secret', 'the Instagram signature secret inherits the shared Meta App Secret');
se_ok(se_ig_app_secret_inherited(), 'and reports it as inherited');

/* ======================================================================== */
se_group('Verify token');

se_test_remove_secret('ig_verify');
se_eq(false, se_ig_verify_outcome('subscribe', 'anything'), 'no verify token => fails closed');
se_test_install_secret('ig_verify', 'fixture-ig-verify');
se_eq(true,  se_ig_verify_outcome('subscribe', 'fixture-ig-verify'), 'correct token + subscribe verifies');
se_eq(false, se_ig_verify_outcome('subscribe', 'wrong'), 'wrong token fails');
se_eq(false, se_ig_verify_outcome('unsubscribe', 'fixture-ig-verify'), 'wrong mode fails');

/* ======================================================================== */
se_group('Event classification (pure)');

$inbound = ['sender' => ['id' => 'U1'], 'recipient' => ['id' => 'IG1'], 'timestamp' => 1700000000000,
            'message' => ['mid' => 'm1', 'text' => 'hello']];
$e = se_ig_classify_event($inbound);
se_eq('inbound', $e['kind'], 'a customer message classifies as inbound');
se_eq(1700000000, $e['ts'], 'millisecond timestamps are converted to seconds');
se_eq('m1', $e['mid'], 'mid extracted');

$echo = ['sender' => ['id' => 'IG1'], 'recipient' => ['id' => 'U1'], 'timestamp' => 1700000001000,
         'message' => ['mid' => 'm2', 'text' => 'hi there', 'is_echo' => true]];
se_eq('echo', se_ig_classify_event($echo)['kind'], 'is_echo classifies as a business echo');

$read = ['sender' => ['id' => 'U1'], 'recipient' => ['id' => 'IG1'], 'timestamp' => 1700000002000, 'read' => ['mid' => 'm2']];
se_eq('read', se_ig_classify_event($read)['kind'], 'a read receipt classifies as read');

$ref = ['sender' => ['id' => 'U9'], 'recipient' => ['id' => 'IG1'], 'timestamp' => 1700000003000,
        'referral' => ['source' => 'ADS', 'type' => 'OPEN_THREAD', 'ad_id' => 'AD123']];
se_eq('referral', se_ig_classify_event($ref)['kind'], 'a standalone ad referral classifies as referral');

$pb = ['sender' => ['id' => 'U1'], 'recipient' => ['id' => 'IG1'], 'timestamp' => 1700000004000,
       'postback' => ['mid' => 'm3', 'title' => 'Fiyat bilgisi', 'payload' => 'PRICE']];
$e = se_ig_classify_event($pb);
se_eq('inbound', $e['kind'], 'an icebreaker postback is an inbound message');
se_eq('Fiyat bilgisi', $e['text'], 'whose text is the tapped title');

$att = ['sender' => ['id' => 'U1'], 'recipient' => ['id' => 'IG1'], 'timestamp' => 1700000005000,
        'message' => ['mid' => 'm4', 'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://cdn.example/x.jpg']]]]];
$e = se_ig_classify_event($att);
se_eq('image', $e['type'], 'attachment type is carried');
se_ok(strpos($e['media'], 'url:') === 0, 'media is referenced, never inlined');

/* ======================================================================== */
se_group('Inbound processing: conversation, attribution, dedup');

$db = se_test_db();
$payload = json_encode(['object' => 'instagram', 'entry' => [['id' => 'IG1', 'time' => 1, 'messaging' => [
    ['sender' => ['id' => 'U1'], 'recipient' => ['id' => 'IG1'], 'timestamp' => 1700000000000,
     'message' => ['mid' => 'm1', 'text' => 'hello'],
     'referral' => ['source' => 'ADS', 'type' => 'OPEN_THREAD', 'ad_id' => 'AD123', 'ads_context_data' => ['ad_title' => 'x']]],
]]]]);
se_ig_process_event(['payload' => $payload]);

$convs = $db->rows('tblse_ig_conversations');
se_eq(1, count($convs), 'one conversation created');
se_eq(1, (int) $convs[0]['brand_id'], 'routed to the account\'s brand');
se_eq('AD123', $convs[0]['referral_ad_id'], 'ad referral captured on first touch');
se_eq('ADS', $convs[0]['referral_source'], 'referral source captured');
se_eq(1, (int) $convs[0]['unread_count'], 'unread incremented');
se_ok(!empty($convs[0]['window_expires_at']), 'a 24h reply window opened');
se_eq(1, count($db->rows('tblse_ig_messages')), 'one message stored');
se_ok(!empty($GLOBALS['se_test']['options']['se_ig_live_test_at']), 'first real inbound records live_test evidence');

// Duplicate delivery of the same mid is a no-op.
se_ig_process_event(['payload' => $payload]);
se_eq(1, count($db->rows('tblse_ig_messages')), 'duplicate mid does not create a second message');

// Second inbound with a DIFFERENT referral must not overwrite first-touch attribution.
$payload2 = json_encode(['object' => 'instagram', 'entry' => [['id' => 'IG1', 'messaging' => [
    ['sender' => ['id' => 'U1'], 'recipient' => ['id' => 'IG1'], 'timestamp' => 1700000100000,
     'message' => ['mid' => 'm5', 'text' => 'again'], 'referral' => ['source' => 'ADS', 'ad_id' => 'AD999']],
]]]]);
se_ig_process_event(['payload' => $payload2]);
$convs = $db->rows('tblse_ig_conversations');
se_eq('AD123', $convs[0]['referral_ad_id'], 'first-touch ad attribution is never overwritten');
se_eq(2, (int) $convs[0]['unread_count'], 'unread increments per inbound');

// Business echo (sent from the Instagram app) is mirrored as outbound/handset.
$payload3 = json_encode(['object' => 'instagram', 'entry' => [['id' => 'IG1', 'messaging' => [
    ['sender' => ['id' => 'IG1'], 'recipient' => ['id' => 'U1'], 'timestamp' => 1700000200000,
     'message' => ['mid' => 'm6', 'text' => 'reply from app', 'is_echo' => true]],
]]]]);
se_ig_process_event(['payload' => $payload3]);
$msgs = $db->rows('tblse_ig_messages');
$last = end($msgs);
se_eq('out', $last['direction'], 'an echo is an outbound message');
se_eq('handset', $last['source'], 'sourced from the Instagram app, not the CRM');
se_eq(2, (int) $db->rows('tblse_ig_conversations')[0]['unread_count'], 'an echo does not touch unread');

// Read receipt marks outbound rows read.
$payload4 = json_encode(['object' => 'instagram', 'entry' => [['id' => 'IG1', 'messaging' => [
    ['sender' => ['id' => 'U1'], 'recipient' => ['id' => 'IG1'], 'timestamp' => 1700000300000, 'read' => ['mid' => 'm6']],
]]]]);
se_ig_process_event(['payload' => $payload4]);
$msgs = $db->rows('tblse_ig_messages');
se_eq('read', end($msgs)['delivery_state'], 'a customer read receipt advances outbound delivery state to read');

// Unknown account is a permanent routing failure.
$threw = false;
try { se_ig_process_event(['payload' => json_encode(['entry' => [['id' => 'NOPE', 'messaging' => []]]])]); }
catch (SeIgPermanentError $x) { $threw = true; }
se_ok($threw, 'an unknown ig_account_id is parked as a permanent routing failure');

/* ======================================================================== */
se_group('Outbound gates and idempotency');

se_test_remove_secret('meta_page');
se_test_remove_secret('ig_token');
$conv = (object) $db->rows('tblse_ig_conversations')[0];

se_eq('no_token', se_ig_send_blocked_reason(1), 'without any token the gate names no_token');
se_test_install_secret('meta_page', 'fixture-not-a-real-token');
se_ok(se_ig_token_inherited(1), 'the Instagram token inherits meta_page');
se_eq('scopes_unverified', se_ig_send_blocked_reason(1), 'a token whose Instagram scopes are unverified is gated with the exact reason');
$GLOBALS['se_test']['options']['se_ig_scopes_verified'] = '1';
se_eq('no_transport', se_ig_send_blocked_reason(1), 'verified scopes but no transport => no_transport');

se_ig_register_transport(function ($m) { $GLOBALS['se_ig_sent'][] = $m; return ['ok' => true, 'mid' => 'mid.SENT1']; });
se_eq('', se_ig_send_blocked_reason(1), 'with everything present the gate clears');

$conv->window_expires_at = date('Y-m-d H:i:s', time() + 3600);
$db->update('tblse_ig_conversations', ['window_expires_at' => $conv->window_expires_at]);
$r1 = se_ig_queue_message((int) $conv->id, ['body' => 'Merhaba'], 10);
se_ok($r1['ok'], 'a reply inside the window queues');
$r2 = se_ig_queue_message((int) $conv->id, ['body' => 'Merhaba'], 10);
se_eq('duplicate', $r2['reason'], 'the same reply queued twice is one row (idempotent)');
se_eq('empty_body', se_ig_queue_message((int) $conv->id, ['body' => '   '])['reason'], 'an empty body is refused');

$row = $db->rows('tblse_ig_outbound')[0];
$row['fence'] = 1; $row['locked_by'] = 'w1';
$out = se_ig_out_process($row);
se_eq('sent', $out['status'], 'the drain sends through the transport');
se_eq('mid.SENT1', $out['mid'], 'and records the provider mid');
$msgs = $db->rows('tblse_ig_messages');
se_eq('crm_api', end($msgs)['source'], 'the sent message is mirrored as CRM-sent');

// Window closed => refused at queue time with the exact reason.
$db->update('tblse_ig_conversations', ['window_expires_at' => date('Y-m-d H:i:s', time() - 60)]);
se_eq('window_closed', se_ig_queue_message((int) $conv->id, ['body' => 'late'])['reason'], 'outside the window Instagram offers no template fallback');

/* REGRESSION: only a BRAND-SCOPED token exists (meta_page_1, no shared
 * meta_page). The lazy live-transport registration used to check brand 0 only,
 * so the authoritative gate reported no_transport although every credential
 * gate had passed — the drain then held every reply as gated. */
$GLOBALS['SE_IG_TRANSPORT'] = null;
se_test_remove_secret('meta_page');
se_test_install_secret('meta_page_1', 'fixture-brand-scoped-token');
require_once __DIR__ . '/../../se_instagram/transport.php';
se_eq('', se_ig_send_blocked_reason(1), 'a brand-scoped token alone lets the gate lazily register the live transport');
se_ok(se_ig_transport_available(), 'the live transport is registered from the brand-scoped token');
se_test_remove_secret('meta_page_1');

se_test_remove_secret('meta_page');
se_test_remove_secret('meta_app');
se_test_remove_secret('ig_verify');
$GLOBALS['SE_IG_TRANSPORT'] = null;
