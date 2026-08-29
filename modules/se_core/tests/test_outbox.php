<?php
/**
 * Conversion outbox: snapshot capture and consumption, consent gating at send
 * time, failure classification, backoff, lease expiry, fencing and concurrent
 * workers.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function se_test_seed_outbox()
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];

    $db->seed('tblse_brands', [
        ['id' => 1, 'name' => 'Brand A', 'active' => 1,
         'meta_dataset_id' => 'DS1', 'google_ads_customer_id' => '123-456-7890'],
    ]);
    $db->seed('tblse_staff_brands', [['staff_id' => 10, 'brand_id' => 1]]);
    $db->seed('tblleads', [
        ['id' => 101, 'brand_id' => 1, 'consent_ads' => 1,
         'email' => 'Ada@Example.Invalid', 'phonenumber' => '+90 555 111 22 33',
         'meta_lead_id' => 'm-101', 'gclid' => 'GCLID-FIRST', 'fbc' => 'fb.1.x',
         'ctwa_clid' => '', 'lost' => 0, 'junk' => 0,
         'consent_text_version' => 'v1'],
    ]);
    $db->seed('tblse_consent_ledger', []);
    $db->seed('tblse_conversion_outbox', []);
    $db->seed('tblse_gdm_requests', []);
    $GLOBALS['se_test']['options'] = [];
    $GLOBALS['SE_GDM_SENDER'] = null;
}

/**
 * Record a grant that predates the conversion, which is the real ordering: a
 * visitor consents on the form, the conversion happens afterwards. Seeding the
 * ledger directly lets a test control consent_at, which se_consent_grant()
 * always stamps as "now".
 */
function se_test_backdated_grant($brand_id, $lead_id, $consent_at, $version = 'v1')
{
    $db = se_test_db();
    $rows = $db->rows('tblse_consent_ledger');
    $rows[] = [
        'id' => count($rows) + 1, 'brand_id' => $brand_id, 'rel_type' => 'lead',
        'rel_id' => $lead_id, 'purpose' => 'ads', 'state' => 'granted',
        'consent_text_version' => $version, 'source' => 'web_to_lead',
        'consent_at' => $consent_at, 'recorded_by' => 0,
    ];
    $db->seed('tblse_consent_ledger', $rows);
}

se_test_seed_outbox();
se_test_act_as(1, [], true);

/* ======================================================================== */
se_group('Destinations require an explicit enable');

se_eq([], se_outbox_destinations_for_brand(1), 'nothing is queued while both integrations are off');

$GLOBALS['se_test']['options']['se_capi_enabled_1'] = 1;
se_eq(['meta_capi'], se_outbox_destinations_for_brand(1), 'CAPI queues only once explicitly enabled');

$GLOBALS['se_test']['options']['se_google_dm_enabled_1'] = 1;
se_eq(['meta_capi', 'google_dm'], se_outbox_destinations_for_brand(1), 'Google queues only once explicitly enabled');

se_eq(false, se_capi_enabled(2), 'CAPI defaults to DISABLED for an unconfigured brand');
se_eq(false, se_google_dm_enabled(2), 'Google DM defaults to DISABLED for an unconfigured brand');

/* ======================================================================== */
se_group('Queue-time snapshot capture');

se_test_seed_outbox();
se_test_backdated_grant(1, 101, '2026-05-01 09:00:00');

$id = se_outbox_queue(1, 101, 'meta_capi', 'Consultation Held', [], '2026-06-01 12:00:00');
se_ok($id > 0, 'row queued');

$row = se_test_db()->rows('tblse_conversion_outbox')[0];
se_eq(1, (int) $row['payload_version'], 'row carries the snapshot payload version');
se_ok(!empty($row['attribution_snapshot']), 'attribution snapshot is populated (was always [])');
se_ok(!empty($row['consent_snapshot']), 'consent snapshot is populated');

$snap = se_outbox_snapshot_decode($row['attribution_snapshot']);
se_eq('GCLID-FIRST', $snap['first_touch']['gclid'], 'first-touch gclid captured');
se_eq('fb.1.x', $snap['first_touch']['fbc'], 'fbc captured raw (platform requires raw)');
se_eq('m-101', $snap['destination']['meta_lead_id'], 'destination key captured');
se_eq(64, strlen($snap['identifiers']['em']), 'email captured as a 64-char SHA-256');
se_eq(64, strlen($snap['identifiers']['ph']), 'phone captured as a 64-char SHA-256');
se_eq(false, isset($snap['identifiers']['email']), 'raw email is NOT stored in the snapshot');
se_eq(false, strpos(json_encode($snap), 'Ada@Example') !== false, 'no raw contact detail anywhere in the snapshot');

$cs = se_outbox_snapshot_decode($row['consent_snapshot']);
se_eq('granted', $cs['state'], 'consent state captured at queue time');
se_ok((int) $cs['ledger_id'] > 0, 'the exact consent ledger row is referenced');

/* ======================================================================== */
se_group('Senders consume the snapshot, not the current lead row');

$db = se_test_db();
$row = $db->rows('tblse_conversion_outbox')[0];

// Mutate the lead AFTER queueing: the event must not change.
$db->tables['tblleads'][0]['email']        = 'changed@example.invalid';
$db->tables['tblleads'][0]['gclid']        = 'GCLID-CHANGED';
$db->tables['tblleads'][0]['meta_lead_id'] = 'm-CHANGED';

$event = se_capi_build_event($row, null);
se_eq('m-101', $event['user_data']['lead_id'], 'CAPI uses the SNAPSHOT lead id, not the edited one');
se_eq($snap['identifiers']['em'], $event['user_data']['em'][0], 'CAPI uses the snapshot email hash');
se_eq('system_generated', $event['action_source'], 'action_source unchanged');
se_eq('se-101-' . $row['id'], $event['event_id'], 'event id is stable and derived from immutable keys');

$g = se_gdm_build_event($row, null, true);
se_eq('GCLID-FIRST', $g['adIdentifiers']['gclid'], 'Google uses the SNAPSHOT gclid, not the edited one');
se_eq('CONSENT_GRANTED', $g['consent']['adUserData'], 'Google consent comes from the snapshot');
se_eq('se-gdm-101-' . $row['id'], $g['transactionId'], 'transactionId is stable');

/* Retry stability: the same row rebuilds byte-identically. */
se_eq(json_encode($event), json_encode(se_capi_build_event($row, null)), 'CAPI event is identical across retries');
se_eq(json_encode($g), json_encode(se_gdm_build_event($row, null, true)), 'Google event is identical across retries');

/* ======================================================================== */
se_group('Pre-snapshot rows are UNSENDABLE (no live-lead fallback)');

se_test_seed_outbox();
$db = se_test_db();
$db->seed('tblse_conversion_outbox', [
    ['id' => 9, 'brand_id' => 1, 'lead_id' => 101, 'destination' => 'meta_capi',
     'event_name' => 'Lead', 'event_time' => '2026-06-01 12:00:00', 'status' => 'pending',
     'attempts' => 0, 'fence' => 0, 'payload_version' => 0,
     'attribution_snapshot' => null, 'consent_snapshot' => null],
]);
$legacy = $db->rows('tblse_conversion_outbox')[0];
se_eq(false, se_outbox_row_has_snapshot($legacy), 'a v0 row is recognised as pre-snapshot');

/* The BUILDER is pure and still accepts a lead, so a caller can inspect what
 * an old row would have produced. The SENDER refuses: rebuilding a historical
 * conversion from the lead's current row is exactly the defect the snapshot
 * removes, and keeping a fallback "just for old rows" kept it alive for every
 * row queued before the migration. */
$lead = (object) ['meta_lead_id' => 'm-101', 'email' => '', 'phonenumber' => '',
                  'ctwa_clid' => '', 'fbc' => '', 'fbp' => '', 'consent_ads' => 1];
$ev = se_capi_build_event($legacy, $lead);
se_eq('m-101', $ev['user_data']['lead_id'], 'the pure builder can still be driven with a lead');

$gate = se_outbox_consent_allows_send($legacy);
se_eq(false, $gate['ok'], 'a v0 row FAILS the send gate');
se_eq('no event snapshot; cannot verify consent at event time', $gate['reason'],
    'and the reason names the missing snapshot');

$consent = se_outbox_row_consent($legacy);
se_eq('unknown', $consent['state'], 'a v0 row reports UNKNOWN consent, never the live flag');
se_eq('no_snapshot', $consent['source'], 'and says the snapshot is missing');

/* ======================================================================== */
se_group('Consent gate at send time');

se_test_seed_outbox();
se_test_backdated_grant(1, 101, '2026-05-01 09:00:00');
se_outbox_queue(1, 101, 'meta_capi', 'Lead', [], '2026-06-01 12:00:00');
$row = se_test_db()->rows('tblse_conversion_outbox')[0];

$gate = se_outbox_consent_allows_send($row);
se_eq(true, $gate['ok'], 'a granted snapshot passes the send gate');

// Withdraw AFTER queueing: the row must not transmit.
se_consent_record(1, 'lead', 101, 'ads', SE_CONSENT_WITHDRAWN, null, 'dsr', 0, 'consent_ads', 'no');
$gate = se_outbox_consent_allows_send($row);
se_eq(false, $gate['ok'], 'a later withdrawal blocks transmission of a queued row');
se_eq('consent withdrawn before transmission', $gate['reason'], 'the reason names the withdrawal');

// A row whose snapshot never granted must never send.
se_test_seed_outbox();
se_outbox_queue(1, 101, 'meta_capi', 'Lead', [], '2026-06-01 12:00:00');
$row = se_test_db()->rows('tblse_conversion_outbox')[0];
$gate = se_outbox_consent_allows_send($row);
se_eq(false, $gate['ok'], 'a row with no recorded consent never sends');
se_eq('no ad consent at event time', $gate['reason'], 'reason names the missing consent');

/* ======================================================================== */
se_group('Gated failures do not consume retry attempts');

se_test_seed_outbox();
se_test_backdated_grant(1, 101, '2026-05-01 09:00:00');
$GLOBALS['se_test']['options']['se_capi_enabled_1'] = 1;
se_outbox_queue(1, 101, 'meta_capi', 'Lead', [], '2026-06-01 12:00:00');

$db = se_test_db();
$worker = 'w-gated';
$claimed = se_outbox_claim_batch($worker, 10);
se_eq(1, count($claimed), 'the due row is claimed');
se_eq(1, (int) $claimed[0]['fence'], 'claiming bumps the fence');

// No Meta token configured -> gated.
$result = se_outbox_process_row($claimed[0], $worker);
se_eq('gated', $result, 'a missing credential is classified as gated');

$row = $db->rows('tblse_conversion_outbox')[0];
se_eq('pending', $row['status'], 'a gated row goes back to pending');
se_eq(0, (int) $row['attempts'], 'a gated row does NOT consume an attempt');
se_eq('gated', $row['failure_class'], 'failure class recorded');
se_eq('no_token', $row['error_code'], 'error code recorded');
se_ok($row['next_attempt_at'] > date('Y-m-d H:i:s'), 'a gated row is rescheduled into the future');

/* A gated row is not re-claimed before its recheck time. */
$again = se_outbox_claim_batch('w2', 10);
se_eq(0, count($again), 'a rescheduled row is not claimed again immediately');

/* ======================================================================== */
se_group('Retryable failures back off; permanent failures park');

se_test_seed_outbox();
se_test_backdated_grant(1, 101, '2026-05-01 09:00:00');
$db = se_test_db();
$db->seed('tblse_conversion_outbox', [
    ['id' => 1, 'brand_id' => 1, 'lead_id' => 101, 'destination' => 'nonsense',
     'event_name' => 'Lead', 'event_time' => '2026-06-01 12:00:00', 'status' => 'processing',
     'attempts' => 0, 'fence' => 1, 'locked_by' => 'w1', 'payload_version' => 0],
]);
$row = $db->rows('tblse_conversion_outbox')[0];
$row['consent_snapshot'] = json_encode(['state' => 'granted', 'ledger_id' => 0]);
$row['payload_version'] = 1;
$row['attribution_snapshot'] = json_encode(['first_touch' => [], 'identifiers' => [], 'destination' => []]);

se_eq('failed', se_outbox_process_row($row, 'w1'), 'an unknown destination fails permanently');
se_eq('permanent', $db->rows('tblse_conversion_outbox')[0]['failure_class'], 'classified permanent');

/* Backoff grows and is jittered, never zero. */
$prev = 0;
for ($n = 1; $n <= 5; $n++) {
    $b = se_outbox_backoff_seconds($n);
    se_ok($b > 0, "backoff for attempt {$n} is positive");
    se_ok($b <= SE_OUTBOX_BACKOFF_CAP, "backoff for attempt {$n} respects the cap");
}
$samples = [];
for ($i = 0; $i < 20; $i++) { $samples[] = se_outbox_backoff_seconds(3); }
se_ok(count(array_unique($samples)) > 1, 'backoff is jittered, not a fixed value');

/* ======================================================================== */
se_group('Lease expiry and fencing');

se_test_seed_outbox();
$db = se_test_db();
$db->seed('tblse_conversion_outbox', [
    ['id' => 1, 'brand_id' => 1, 'lead_id' => 101, 'destination' => 'meta_capi',
     'event_name' => 'Lead', 'event_time' => '2026-06-01 12:00:00', 'status' => 'processing',
     'attempts' => 0, 'fence' => 1, 'locked_by' => 'stale-worker',
     'locked_at' => date('Y-m-d H:i:s', time() - (SE_OUTBOX_LEASE_SECONDS + 60)),
     'payload_version' => 1,
     'attribution_snapshot' => json_encode(['first_touch' => [], 'identifiers' => [], 'destination' => []]),
     'consent_snapshot' => json_encode(['state' => 'granted', 'ledger_id' => 0])],
]);

$staleRow = $db->rows('tblse_conversion_outbox')[0];   // fence 1, held by stale-worker

se_outbox_recover_stale();
se_eq('pending', $db->rows('tblse_conversion_outbox')[0]['status'], 'an expired lease returns the row to pending');

$fresh = se_outbox_claim_batch('fresh-worker', 10);
se_eq(1, count($fresh), 'a new worker claims the recovered row');
se_eq(2, (int) $fresh[0]['fence'], 'the re-claim bumped the fence');

// The stale worker now tries to write its result with the OLD fence.
$written = se_outbox_finalize($staleRow, 'stale-worker', ['status' => 'sent']);
se_eq(0, $written, 'the fenced-out stale worker writes NOTHING');
se_eq('processing', $db->rows('tblse_conversion_outbox')[0]['status'],
    "the fresh worker's claim survives the stale worker's write");

// The fresh worker CAN write.
$written = se_outbox_finalize($fresh[0], 'fresh-worker', ['status' => 'sent', 'locked_by' => null]);
se_eq(1, $written, 'the current lease holder writes successfully');
se_eq('sent', $db->rows('tblse_conversion_outbox')[0]['status'], 'result recorded');

/* ======================================================================== */
se_group('Concurrent workers claim disjoint rows');

se_test_seed_outbox();
$db = se_test_db();
$rows = [];
for ($i = 1; $i <= 6; $i++) {
    $rows[] = ['id' => $i, 'brand_id' => 1, 'lead_id' => 101, 'destination' => 'meta_capi',
               'event_name' => 'Lead', 'event_time' => '2026-06-01 12:00:00', 'status' => 'pending',
               'attempts' => 0, 'fence' => 0, 'next_attempt_at' => '2026-06-01 12:00:00',
               'payload_version' => 1];
}
$db->seed('tblse_conversion_outbox', $rows);

$a = se_outbox_claim_batch('worker-A', 3);
$b = se_outbox_claim_batch('worker-B', 3);

se_eq(3, count($a), 'worker A claims its batch');
se_eq(3, count($b), 'worker B claims its batch');

$idsA = array_column($a, 'id');
$idsB = array_column($b, 'id');
se_eq([], array_intersect($idsA, $idsB), 'the two claims are disjoint');
se_eq(6, count(array_unique(array_merge($idsA, $idsB))), 'every row is claimed exactly once');

$c = se_outbox_claim_batch('worker-C', 3);
se_eq(0, count($c), 'a third worker finds nothing left to claim');

/* ======================================================================== */
se_group('Provider errors are sanitized');

$s = se_outbox_sanitize_error(SE_OUTBOX_FAIL_RETRYABLE, 'http_500',
    'Server error for request with access_token=EAAGm0PX4ZCpsBA1ZBxYZBqZC7ZBLongTokenValueHere and payload {"em":"abc"}');
se_eq(false, strpos($s['last_error'], 'EAAGm0PX4ZCpsBA1ZBxYZBqZC7ZBLongTokenValueHere') !== false,
    'a token-shaped string is redacted from the stored error');
se_ok(strpos($s['last_error'], '[redacted]') !== false, 'redaction marker is present');
se_ok(strlen($s['last_error']) <= 300, 'stored error is bounded');
se_eq('http_500', $s['error_code'], 'error code preserved');
se_eq(SE_OUTBOX_FAIL_RETRYABLE, $s['failure_class'], 'failure class preserved');

/* ======================================================================== */
se_group('Google: an accepted ingest is SUBMITTED, not sent');

se_test_seed_outbox();
se_test_backdated_grant(1, 101, date('Y-m-d H:i:s', time() - 24 * 3600));
$GLOBALS['se_test']['options']['se_google_dm_enabled_1'] = 1;
$GLOBALS['se_test']['options']['se_google_conv_action_1'] = 'CA-1';

se_gdm_register_sender(function ($url, $payload) {
    return ['ok' => true, 'code' => 200, 'body' => json_encode(['requestId' => 'REQ-123'])];
});

se_outbox_queue(1, 101, 'google_dm', 'Lead', [], date('Y-m-d H:i:s', time() - 7 * 3600));
$db = se_test_db();
$claimed = se_outbox_claim_batch('gw', 5);
se_eq(1, count($claimed), 'google row claimed');

$res = se_outbox_process_row($claimed[0], 'gw');
se_eq('submitted', $res, 'an accepted ingest request is recorded as SUBMITTED');

$row = $db->rows('tblse_conversion_outbox')[0];
se_eq('submitted', $row['status'], 'status is submitted, not sent');
se_eq('REQ-123', $row['request_id'], 'the row is linked to its Data Manager request id');
se_ok(!empty($row['submitted_at']), 'submitted_at is stamped');
se_eq(1, count($db->rows('tblse_gdm_requests')), 'the request is tracked for later status retrieval');

$GLOBALS['SE_GDM_SENDER'] = null;

/* ======================================================================== */
se_group('The plaintext bearer-token option is never read');

/* The old design pasted a static token into `se_google_sa_token_<brand>`.
 * Tokens are now minted through the credential provider, and that option must
 * have no influence whatsoever — not as a value, not as a fallback. */
se_test_seed_outbox();
$GLOBALS['SE_GDM_TOKEN_PROVIDER'] = null;   // no signer registered
se_test_remove_secret('google_sa_1');
se_gdm_token_cache_reset();

se_eq('', se_gdm_access_token(1), 'with no credential and no signer, no token is produced');

$GLOBALS['se_test']['options']['se_google_sa_token_1'] = 'ya29.SHOULD-NEVER-BE-USED';
$GLOBALS['se_test']['options']['se_google_sa_token']   = 'ya29.ALSO-NEVER-USED';

se_eq('', se_gdm_access_token(1),
    'setting the old plaintext options changes nothing — they are not read at all');

$status = se_gdm_credential_status(1);
se_eq(false, $status['ready'], 'and the provider still reports not ready');
se_eq(false, strpos(json_encode($status), 'SHOULD-NEVER-BE-USED') !== false,
    'the old option value appears nowhere in the status payload');

