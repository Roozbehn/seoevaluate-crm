<?php
/**
 * Conversions API for business messaging.
 *
 * The live Instagram MESSAGES campaign never touches the website, so until
 * this path exists Meta optimises it against no conversion signal at all.
 * These tests pin the three things that make such an event either land or be
 * silently discarded: the dataset it goes to, the event name, and the
 * per-channel identifier pair.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function se_test_seed_mm()
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];
    $db->seed('tblse_brands', [
        ['id' => 22, 'name' => 'Azin', 'active' => 1, 'meta_dataset_id' => '4515580372030489'],
    ]);
    $db->seed('tblleads', [
        ['id' => 700, 'brand_id' => 22, 'consent_ads' => 1, 'lost' => 0, 'junk' => 0,
         'email' => '', 'phonenumber' => '', 'consent_text_version' => 'v1'],
    ]);
    $db->seed('tblse_consent_ledger', []);
    $db->seed('tblse_conversion_outbox', []);
    $GLOBALS['se_test']['options'] = [];
    se_capi_messaging_register_http(null);
}

function se_test_mm_row($ctx, $overrides = [])
{
    return array_merge([
        'id'         => 5,
        'brand_id'   => 22,
        'lead_id'    => 700,
        'event_name' => 'LeadSubmitted',
        'event_time' => '2026-09-02 12:00:00',
        'payload'    => json_encode($ctx),
    ], $overrides);
}

se_test_seed_mm();

/* ======================================================================== */
se_group('The dataset is the MM one, and never the website one');

$mm  = se_capi_messaging_dataset(22);
se_eq('2081936999059007', $mm['id'], 'resolves the registry MM dataset');
se_ok($mm['id'] !== se_asset_dataset('web_capi', 22), 'MM dataset is not the web dataset');

// Brand 22 was once pointed at another business's MM dataset for web CAPI.
// The mirror mistake — messaging events into the web dataset — must be
// impossible by construction, not by convention.
se_eq('4515580372030489', se_asset_dataset('web_capi', 22), 'web dataset unchanged by this feature');

$none = se_capi_messaging_dataset(999);
se_eq(null, $none['id'], 'a brand with no registry entry gets no dataset');
se_eq('no_mm_dataset', $none['code'], 'and the reason names the gap');

/* ======================================================================== */
se_group('Event names are Meta\'s closed set — never our internal taxonomy');

se_eq('LeadSubmitted', se_capi_messaging_event_name('conversation_started'),
      'a thread opened from a messages ad is a LeadSubmitted');
se_eq('QualifiedLead', se_capi_messaging_event_name('qualified'), 'staff qualification maps');
se_eq(null, se_capi_messaging_event_name('whatsapp_click'),
      'the WEBSITE taxonomy name does not leak into messaging');
se_eq(null, se_capi_messaging_event_name('Contact'),
      "'Contact' is not accepted on a messaging event and is never sent");
se_eq(null, se_capi_messaging_event_name(''), 'an empty signal maps to nothing');

// NOTE: not $name / $file / $files — test files run inside the runner's own
// scope, and clobbering its variables renames the suite in the report.
foreach (se_capi_messaging_name_map() as $mm_signal => $mm_name) {
    se_ok(in_array($mm_name, se_capi_messaging_event_names(), true),
          "mapped name {$mm_name} is in Meta's accepted set");
}

/* ======================================================================== */
se_group('Per-channel identifiers: the right pair, unhashed, or nothing');

$wa = se_capi_messaging_build_event(se_test_mm_row([
    'messaging_channel' => 'whatsapp', 'signal' => 'conversation_started',
    'ctwa_clid' => 'CTWA123', 'whatsapp_business_account_id' => '1398503638806590',
]));
se_eq('business_messaging', $wa['event']['action_source'], 'action_source is business_messaging');
se_eq('whatsapp', $wa['event']['messaging_channel'], 'channel travels with the event');
se_eq('CTWA123', $wa['event']['user_data']['ctwa_clid'], 'ctwa_clid is sent UNHASHED');
se_eq('1398503638806590', $wa['event']['user_data']['whatsapp_business_account_id'], 'WABA id sent');
se_eq(false, isset($wa['event']['user_data']['em']), 'no email hash on a messaging event');
se_eq(false, isset($wa['event']['user_data']['ph']), 'no phone hash on a messaging event');

$ig = se_capi_messaging_build_event(se_test_mm_row([
    'messaging_channel' => 'instagram', 'signal' => 'conversation_started',
    'ig_sid' => 'IGSID9', 'instagram_business_account_id' => 'IGACC1',
]));
se_eq('IGSID9', $ig['event']['user_data']['ig_sid'], 'instagram uses ig_sid');
se_eq('IGACC1', $ig['event']['user_data']['instagram_business_account_id'], 'and the IG account id');
se_eq(false, isset($ig['event']['user_data']['ctwa_clid']), 'no ctwa_clid on an instagram event');

// A WhatsApp thread has no IGSID and an Instagram thread has no ctwa_clid.
// Crossing them produces an event that matches nobody, so it is refused.
$crossed = se_capi_messaging_build_event(se_test_mm_row([
    'messaging_channel' => 'whatsapp', 'signal' => 'conversation_started',
    'ig_sid' => 'IGSID9', 'instagram_business_account_id' => 'IGACC1',
]));
se_eq('missing_whatsapp_identifiers', $crossed['error'], 'instagram identifiers do not satisfy whatsapp');

$half = se_capi_messaging_build_event(se_test_mm_row([
    'messaging_channel' => 'whatsapp', 'signal' => 'conversation_started',
    'whatsapp_business_account_id' => '1398503638806590',
]));
se_eq('missing_whatsapp_identifiers', $half['error'],
      'a WABA id with no click id is unattributable and is not sent');

$bad = se_capi_messaging_build_event(se_test_mm_row([
    'messaging_channel' => 'telegram', 'signal' => 'conversation_started',
]));
se_eq('unknown_channel', $bad['error'], 'an unknown channel is refused, not passed through');

$unmapped = se_capi_messaging_build_event(se_test_mm_row([
    'messaging_channel' => 'whatsapp', 'signal' => 'became_curious',
    'ctwa_clid' => 'C', 'whatsapp_business_account_id' => 'W',
]));
se_eq('unmapped_signal', $unmapped['error'], 'an unmapped signal is refused, never invented');

/* ======================================================================== */
se_group('Nothing clinical, and a stable id across retries');

$flat = json_encode($wa['event']);
foreach (['procedure', 'diagnosis', 'eyebrow', 'graft', 'photo', 'concern', 'health'] as $banned) {
    se_eq(false, stripos($flat, $banned) !== false, "no '{$banned}' anywhere in the payload");
}
se_eq('se-mm-700-5', $wa['event']['event_id'], 'event id is derived from immutable keys');
$again = se_capi_messaging_build_event(se_test_mm_row([
    'messaging_channel' => 'whatsapp', 'signal' => 'conversation_started',
    'ctwa_clid' => 'CTWA123', 'whatsapp_business_account_id' => '1398503638806590',
]));
se_eq($wa['event']['event_id'], $again['event']['event_id'],
      'a redelivery carries the SAME id — the outbox is at-least-once');

/* ======================================================================== */
se_group('Queueing from a conversation: ad threads only, and only when enabled');

se_test_seed_mm();
se_test_db()->seed('tblse_wa_conversations', [
    ['id' => 1, 'brand_id' => 22, 'ctwa_clid' => 'CTWA-AD', 'waba_id' => '1398503638806590'],
    ['id' => 2, 'brand_id' => 22, 'ctwa_clid' => '',        'waba_id' => '1398503638806590'],
    ['id' => 3, 'brand_id' => 22, 'ctwa_clid' => 'CTWA-AD', 'waba_id' => ''],
]);
se_test_db()->seed('tblse_ig_conversations', [
    ['id' => 1, 'brand_id' => 22, 'igsid' => 'IGSID9', 'ig_account_id' => 'IGACC1',
     'referral_json' => '{"source":"ADS","ad_id":"123"}'],
    ['id' => 2, 'brand_id' => 22, 'igsid' => 'IGSID8', 'ig_account_id' => 'IGACC1',
     'referral_json' => ''],
]);

// The owner switch is off: nothing is queued, not even for a real ad thread.
// Rows that would sit gated forever are refused at the producer.
se_eq(false, se_capi_messaging_queue_for_wa_conversation(1, 700),
      'disabled brand queues nothing at all');
se_eq(0, count(se_test_db()->rows('tblse_conversion_outbox')), 'outbox stays empty while disabled');

update_option('se_meta_mm_capi_enabled_22', '1');

se_ok(se_capi_messaging_queue_for_wa_conversation(1, 700) !== false,
      'an ad-opened WhatsApp thread queues a conversion');
$rows = se_test_db()->rows('tblse_conversion_outbox');
se_eq(1, count($rows), 'exactly one row');
se_eq('meta_mm_capi', $rows[0]['destination'], 'routed to the messaging destination');
se_eq('LeadSubmitted', $rows[0]['event_name'], "stored under Meta's name, not ours");
$ctx = json_decode($rows[0]['payload'], true);
se_eq('CTWA-AD', $ctx['ctwa_clid'], 'the click id is carried on the row, not re-read later');
se_eq('whatsapp', $ctx['messaging_channel'], 'channel recorded at queue time');

// An organic enquiry is the common case and must never be reported as an ad
// conversion — that is a lie to the optimiser, and it is bid on.
se_eq(false, se_capi_messaging_queue_for_wa_conversation(2, 700),
      'a thread with no click id is not an ad conversion');
se_eq(false, se_capi_messaging_queue_for_wa_conversation(3, 700),
      'a click id with no WABA id is half-identified and is refused');
se_eq(false, se_capi_messaging_queue_for_wa_conversation(99, 700), 'a missing conversation is refused');
se_eq(false, se_capi_messaging_queue_for_wa_conversation(1, 0), 'a thread with no lead is refused');

se_ok(se_capi_messaging_queue_for_ig_conversation(1, 700) !== false,
      'an Instagram thread opened from an ad queues a conversion');
$ig_ctx = json_decode(se_test_db()->rows('tblse_conversion_outbox')[1]['payload'], true);
se_eq('IGSID9', $ig_ctx['ig_sid'], 'the IG thread identifier is carried');
se_eq(false, se_capi_messaging_queue_for_ig_conversation(2, 700),
      'an Instagram thread with no referral is organic and is not reported');

/* ======================================================================== */
se_group('The sender fails the way the outbox contract requires');

$row = se_test_mm_row([
    'messaging_channel' => 'whatsapp', 'signal' => 'conversation_started',
    'ctwa_clid' => 'C', 'whatsapp_business_account_id' => 'W',
]);

update_option('se_meta_mm_capi_enabled_22', '0');
$r = se_capi_messaging_send_event($row);
se_eq(SE_OUTBOX_FAIL_GATED, $r['class'], 'a disabled brand is GATED, never a delivery failure');
se_eq('disabled', $r['code'], 'and the code names the switch');

update_option('se_meta_mm_capi_enabled_22', '1');
$r = se_capi_messaging_send_event($row);
se_eq(SE_OUTBOX_FAIL_GATED, $r['class'], 'a missing token is GATED — it must not burn the retry budget');
se_eq('no_token', $r['code'], 'and names the missing credential');

se_test_install_secret('meta_mm_capi_22', 'TOKEN-MM');

$seen = [];
se_capi_messaging_register_http(function ($url, $token, $payload) use (&$seen) {
    $seen = ['url' => $url, 'token' => $token, 'payload' => $payload];
    return ['status' => 200, 'body' => '{"events_received":1}', 'transport_error' => false];
});
$r = se_capi_messaging_send_event($row);
se_eq(true, $r['ok'], 'a configured brand sends');
se_ok(strpos($seen['url'], '2081936999059007') !== false, 'posted to the MM dataset');
se_eq(false, strpos($seen['url'], '4515580372030489') !== false, 'never to the website dataset');
se_eq(false, strpos($seen['url'], 'TOKEN-MM') !== false, 'the token is NOT in the URL');
se_eq('TOKEN-MM', $seen['token'], 'the token travels in the Authorization header');

foreach ([500 => SE_OUTBOX_FAIL_RETRYABLE, 429 => SE_OUTBOX_FAIL_RETRYABLE,
          401 => SE_OUTBOX_FAIL_RETRYABLE, 400 => SE_OUTBOX_FAIL_PERMANENT] as $status => $expected) {
    se_capi_messaging_register_http(function ($u, $t, $p) use ($status) {
        return ['status' => $status, 'body' => '', 'transport_error' => false];
    });
    $out = se_capi_messaging_send_event($row);
    se_eq($expected, $out['class'], "HTTP {$status} is classified {$expected}");
}

se_capi_messaging_register_http(function ($u, $t, $p) {
    return ['status' => 0, 'body' => '', 'transport_error' => true];
});
se_eq(SE_OUTBOX_FAIL_RETRYABLE, se_capi_messaging_send_event($row)['class'], 'a transport error retries');

se_capi_messaging_register_http(null);
