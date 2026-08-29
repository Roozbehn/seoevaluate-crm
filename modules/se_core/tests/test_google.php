<?php
/**
 * Google Data Manager: credential provider, async status lifecycle, age policy
 * and landing-token binding. All fixture-driven; no Google request is possible.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function se_test_seed_google()
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];
    $db->seed('tblse_brands', [['id' => 1, 'name' => 'Brand A', 'active' => 1,
        'google_ads_customer_id' => '123-456-7890', 'meta_dataset_id' => '']]);
    $db->seed('tblse_staff_brands', [['staff_id' => 10, 'brand_id' => 1]]);
    $db->seed('tblleads', [['id' => 101, 'brand_id' => 1, 'consent_ads' => 1,
        'gclid' => '', 'gbraid' => '', 'wbraid' => '']]);
    $db->seed('tblse_conversion_outbox', []);
    $db->seed('tblse_gdm_requests', []);
    $GLOBALS['se_test']['options'] = [];
    $GLOBALS['SE_GDM_TOKEN_PROVIDER'] = null;
    $GLOBALS['SE_GDM_STATUS_POLLER']  = null;
}

se_test_seed_google();
se_test_act_as(1, [], true);

/* ======================================================================== */
se_group('Credential provider is gated without a signer');

se_eq(false, se_gdm_token_provider_available(), 'no signer is registered by default');
se_eq('', se_gdm_access_token(1), 'so no access token can be minted');
se_eq(null, se_gdm_credential(1), 'and no credential is present');

$st = se_gdm_credential_status(1);
se_eq(false, $st['ready'], 'the provider reports NOT ready');
se_eq(false, $st['signer_available'], 'and says the signer is missing');
se_eq(false, $st['token_cached'], 'no token is cached');
se_eq(null, $st['client_email'], 'no service account is reported');
se_eq(false, isset($st['token']), 'status NEVER carries a token');
se_eq(false, isset($st['private_key']), 'status NEVER carries a key');

/* ======================================================================== */
se_group('With a fixture credential and signer, tokens are minted and cached');

// A structurally valid but entirely fake service-account document.
$fakeCred = json_encode([
    'type' => 'service_account', 'project_id' => 'zz-project',
    'client_email' => 'zz@zz-project.iam.gserviceaccount.invalid',
    'private_key' => "-----BEGIN PRIVATE KEY-----\nFIXTURE\n-----END PRIVATE KEY-----\n",
]);

se_test_install_secret('google_sa_1', $fakeCred);

$cred = se_gdm_credential(1);
se_ok($cred !== null, 'the credential file parses');
se_eq('zz@zz-project.iam.gserviceaccount.invalid', $cred['client_email'], 'client_email is read');

// Still gated: a credential without a signer mints nothing.
se_eq('', se_gdm_access_token(1), 'a credential alone still mints no token');

$minted = 0;
se_gdm_register_token_provider(function ($credential, $scope) use (&$minted) {
    $minted++;
    se_ok($scope === SE_GDM_SCOPE, 'the signer receives the Data Manager scope');
    return ['access_token' => 'fixture-token-' . $minted, 'expires_in' => 3600];
});

$t1 = se_gdm_access_token(1);
se_eq('fixture-token-1', $t1, 'a token is minted through the registered signer');
se_eq(1, $minted, 'the signer was called once');

$t2 = se_gdm_access_token(1);
se_eq('fixture-token-1', $t2, 'the cached token is reused');
se_eq(1, $minted, 'the signer was NOT called again');

$st = se_gdm_credential_status(1);
se_eq(true, $st['ready'], 'the provider now reports ready');
se_eq(true, $st['token_valid_now'], 'the cached token is valid');
se_eq(false, strpos(json_encode($st), 'fixture-token-1') !== false,
    'the token value appears NOWHERE in the status payload');
se_eq(false, strpos(json_encode($st), 'BEGIN PRIVATE KEY') !== false,
    'the private key appears NOWHERE in the status payload');

/* ======================================================================== */
se_group('Age policy: the unverified six-hour delay is OFF by default');

se_test_seed_google();
se_eq(0, se_gdm_min_age_seconds(1), 'the minimum event age defaults to zero');
se_eq('', se_gdm_age_check(date('Y-m-d H:i:s'), 1), 'a brand-new event is accepted');

$GLOBALS['se_test']['options']['se_google_min_age_seconds'] = 21600;
se_eq(21600, se_gdm_min_age_seconds(1), 'a configured minimum is honoured');
se_ok(se_gdm_age_check(date('Y-m-d H:i:s'), 1) !== '', 'and a fresh event is then held');
se_ok(strpos(se_gdm_age_check(date('Y-m-d H:i:s'), 1), 'younger') !== false,
    'the reason says "younger", which the sender classifies as GATED not failed');

unset($GLOBALS['se_test']['options']['se_google_min_age_seconds']);
se_ok(se_gdm_age_check(date('Y-m-d H:i:s', time() - 200 * 86400), 1) !== '',
    'an event older than the maximum is rejected');

/* ======================================================================== */
se_group('Status interpretation: submitted / confirmed / partial / failed');

$r = se_gdm_interpret_status(['requestStatus' => 'PROCESSING']);
se_eq('submitted', $r['state'], 'PROCESSING stays submitted');

$r = se_gdm_interpret_status(['requestStatus' => 'SUCCESS', 'successCount' => 5, 'failureCount' => 0]);
se_eq('confirmed', $r['state'], 'all-success is confirmed');
se_eq(5, $r['succeeded'], 'success count is carried');

$r = se_gdm_interpret_status(['requestStatus' => 'SUCCESS', 'successCount' => 3, 'failureCount' => 2]);
se_eq('partial', $r['state'], 'some succeeded and some failed is PARTIAL');
se_eq(2, $r['failed'], 'failure count is carried');

$r = se_gdm_interpret_status(['requestStatus' => 'FAILED', 'successCount' => 0, 'failureCount' => 4]);
se_eq('failed', $r['state'], 'all-failed is failed');

$r = se_gdm_interpret_status(['requestStatus' => 'FAILED', 'errorInfo' => [
    ['errorCode' => 'INVALID_ARGUMENT', 'errorMessage' => 'token ya29.SOMEVERYLONGTOKENVALUEHERE12345 rejected', 'count' => 2],
]]);
se_eq('INVALID_ARGUMENT', $r['diagnostics'][0]['code'], 'the diagnostic code is kept');
se_eq(false, strpos($r['diagnostics'][0]['reason'], 'ya29.SOMEVERYLONGTOKENVALUEHERE12345') !== false,
    'a token-shaped string is redacted from the diagnostic');

/* ======================================================================== */
se_group('Polling settles the outbox rows behind a request');

se_test_seed_google();
$db = se_test_db();

$db->seed('tblse_gdm_requests', [
    ['id' => 1, 'brand_id' => 1, 'request_id' => 'REQ-A', 'event_count' => 2, 'status' => 'submitted'],
    ['id' => 2, 'brand_id' => 1, 'request_id' => 'REQ-B', 'event_count' => 3, 'status' => 'submitted'],
]);
$db->seed('tblse_conversion_outbox', [
    ['id' => 1, 'brand_id' => 1, 'lead_id' => 101, 'destination' => 'google_dm', 'event_name' => 'Lead',
     'status' => 'submitted', 'request_id' => 'REQ-A', 'attempts' => 1, 'payload_version' => 1],
    ['id' => 2, 'brand_id' => 1, 'lead_id' => 101, 'destination' => 'google_dm', 'event_name' => 'Lead',
     'status' => 'submitted', 'request_id' => 'REQ-B', 'attempts' => 1, 'payload_version' => 1],
]);

// Gated: no poller means nothing is invented.
se_eq(0, se_gdm_poll_pending(), 'with no poller registered, nothing is settled');
se_eq('submitted', $db->rows('tblse_conversion_outbox')[0]['status'], 'the row stays submitted');

// Register a signer so tokens exist, and a fixture poller.
se_gdm_register_token_provider(function ($c, $s) { return ['access_token' => 'tok', 'expires_in' => 3600]; });
se_test_install_secret('google_sa_1', json_encode([
    'type' => 'service_account', 'project_id' => 'p',
    'client_email' => 'z@z.invalid', 'private_key' => 'k']));

se_gdm_register_status_poller(function ($requestId, $token) {
    if ($requestId === 'REQ-A') {
        return ['requestStatus' => 'SUCCESS', 'successCount' => 2, 'failureCount' => 0];
    }
    return ['requestStatus' => 'SUCCESS', 'successCount' => 1, 'failureCount' => 2,
            'errorInfo' => [['errorCode' => 'PARTIAL', 'errorMessage' => 'two rejected', 'count' => 2]]];
});

$settled = se_gdm_poll_pending();
se_eq(2, $settled, 'both in-flight requests are settled');

$rows = $db->rows('tblse_conversion_outbox');
se_eq('confirmed', $rows[0]['status'], 'the fully-successful request CONFIRMS its outbox row');
se_eq('partial',   $rows[1]['status'], 'the partially-failed request marks its row PARTIAL');
se_ok(!empty($rows[1]['last_error']), 'and attaches a sanitized reason');

$reqs = $db->rows('tblse_gdm_requests');
se_eq('confirmed', $reqs[0]['status'], 'the request row records confirmed');
se_eq('partial',   $reqs[1]['status'], 'and partial');
se_eq(2, (int) $reqs[1]['failed'], 'with the failure count');

se_test_remove_secret('google_sa_1');

/* ======================================================================== */
se_group('Landing tokens are brand-, purpose- and time-bound');

se_test_seed_google();
$GLOBALS['se_test']['options']['se_landing_token_secret'] = 'zz-landing-fixture-secret';

$tok = se_landing_token_create(['gclid' => 'GC1'], 30, null, 1);
se_ok($tok !== '', 'a token is created');

$ok = se_landing_token_verify($tok, null, 1);
se_ok($ok !== null, 'it verifies for its own brand');
se_eq('GC1', $ok['gclid'], 'and carries the click id');

se_eq(null, se_landing_token_verify($tok, null, 2),
    'it does NOT verify for a different brand (was applicable to any lead)');

se_eq(null, se_landing_token_verify($tok . 'x', null, 1), 'a tampered token fails');
se_eq(null, se_landing_token_verify('garbage', null, 1), 'a non-token fails');
se_eq(null, se_landing_token_verify(str_repeat('a', 3000), null, 1), 'an oversized token is refused before decoding');

$expired = se_landing_token_create(['gclid' => 'GC1'], -1, null, 1);
se_eq(null, se_landing_token_verify($expired, null, 1), 'an expired token fails');

/* ======================================================================== */
se_group('Applying a landing token cannot overwrite first-touch or cross brands');

se_test_seed_google();
$GLOBALS['se_test']['options']['se_landing_token_secret'] = 'zz-landing-fixture-secret';
$db = se_test_db();

$tokA = se_landing_token_create(['gclid' => 'FIRST'], 30, null, 1);
se_eq(true, se_landing_apply_to_lead(101, $tokA), 'the first token is applied');
se_eq('FIRST', $db->rows('tblleads')[0]['gclid'], 'and stamps the click id');

$tokB = se_landing_token_create(['gclid' => 'SECOND'], 30, null, 1);
se_eq(false, se_landing_apply_to_lead(101, $tokB), 'a second token does NOT overwrite');
se_eq('FIRST', $db->rows('tblleads')[0]['gclid'], 'first-touch attribution survives');

$tokOther = se_landing_token_create(['gclid' => 'OTHER'], 30, null, 2);
se_eq(false, se_landing_apply_to_lead(101, $tokOther), "a token for another brand cannot touch this lead");
se_eq('FIRST', $db->rows('tblleads')[0]['gclid'], 'and the lead is unchanged');
