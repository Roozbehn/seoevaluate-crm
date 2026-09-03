<?php
/**
 * Google Data Manager: library-backed credential provider (google/auth),
 * async status lifecycle, age policy and landing-token binding.
 *
 * Entirely OFFLINE: the token exchange goes through an injected PSR-7 handler,
 * the key material is a synthetic RSA keypair generated at test time
 * (openssl_pkey_new), and the JWT assertion is VERIFIED with firebase/php-jwt
 * against the synthetic public key — nothing cryptographic is hand-rolled and
 * no socket is ever opened.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* ---------------------------------------------------------------------------
 * Reach the composer vendor autoload without touching bootstrap.php.
 * Order: explicit test constant, the web app's APPPATH, then the repo layout
 * (modules/ and application/ are siblings at the repo root).
 * ------------------------------------------------------------------------- */
if (!class_exists('Google\\Auth\\Credentials\\ServiceAccountCredentials')) {
    $seGoogleAutoloadCandidates = [];
    if (defined('SE_VENDOR_AUTOLOAD')) { $seGoogleAutoloadCandidates[] = SE_VENDOR_AUTOLOAD; }
    if (defined('APPPATH'))            { $seGoogleAutoloadCandidates[] = APPPATH . 'vendor/autoload.php'; }
    $seGoogleAutoloadCandidates[] = dirname(__DIR__, 3) . '/application/vendor/autoload.php';

    foreach ($seGoogleAutoloadCandidates as $seGoogleAutoloadCandidate) {
        if (is_string($seGoogleAutoloadCandidate) && $seGoogleAutoloadCandidate !== ''
            && is_file($seGoogleAutoloadCandidate)) {
            require_once $seGoogleAutoloadCandidate;
            break;
        }
    }
}

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
    se_gdm_register_http_handler(null);
    se_gdm_token_cache_reset();
    se_test_remove_secret('google_sa_1');
}

/** Synthetic RSA keypair, minted fresh for this run. Lives only in memory. */
function se_test_google_keypair()
{
    static $pair = null;

    if ($pair !== null) {
        return $pair;
    }

    $res = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    $privatePem = '';
    openssl_pkey_export($res, $privatePem);
    $details = openssl_pkey_get_details($res);

    return $pair = ['private' => $privatePem, 'public' => $details['key']];
}

/** A structurally complete, obviously synthetic service-account document. */
function se_test_google_sa_json($privatePem)
{
    return json_encode([
        'type'           => 'service_account',
        'project_id'     => 'zztest-project',
        'private_key_id' => 'zztestkeyid0000000000000000000000',
        'private_key'    => $privatePem,
        'client_email'   => 'zztest@zztest-project.iam.gserviceaccount.invalid',
        'client_id'      => '900000000000000000001',
    ]);
}

se_test_seed_google();
se_test_act_as(1, [], true);

/* ======================================================================== */
se_group('The official google/auth library is the DEFAULT signer');

se_ok(class_exists('Google\\Auth\\Credentials\\ServiceAccountCredentials'),
    'google/auth is installed and autoloadable (SE_VENDOR_AUTOLOAD / APPPATH / repo layout)');
se_ok(class_exists('Firebase\\JWT\\JWT'), 'firebase/php-jwt rides along for JWT verification');
se_eq(true, se_gdm_library_available(), 'the provider sees the library');
se_eq(true, se_gdm_token_provider_available(), 'so a signer is available WITHOUT registering one');

se_eq('', se_gdm_access_token(1), 'but with no credential installed, no token is minted');
se_eq(null, se_gdm_credential(1), 'and no credential is reported');
se_eq('configuration', se_gdm_last_token_failure(1)['category'], 'a missing key document is a CONFIGURATION gap');

$st = se_gdm_credential_status(1);
se_eq(false, $st['ready'], 'the provider reports NOT ready');
se_eq(true, $st['signer_available'], 'while the signer itself is present');
se_eq(false, $st['file_readable'], 'the key file is not readable (absent)');
se_eq(false, $st['key_document_valid'], 'and no key document is valid');
se_eq(false, $st['token_cached'], 'no token is cached');
se_eq(null, $st['client_email'], 'no service account is reported');
se_eq(false, isset($st['token']), 'status NEVER carries a token');
se_eq(false, isset($st['private_key']), 'status NEVER carries a key');

/* ======================================================================== */
se_group('Tokens are minted through the injected handler; the assertion verifies');

$pair = se_test_google_keypair();
se_test_install_secret('google_sa_1', se_test_google_sa_json($pair['private']));

$cred = se_gdm_credential(1);
se_ok($cred !== null, 'the synthetic credential file parses');
se_eq('zztest@zztest-project.iam.gserviceaccount.invalid', $cred['client_email'], 'client_email is read');
se_eq(true, se_gdm_key_document_valid($cred), 'the synthetic RSA key parses as usable');

$handlerCalls = 0;
$seen = ['method' => '', 'uri' => '', 'content_type' => '', 'grant_type' => '', 'claims' => null, 'sig_ok' => false];

se_gdm_register_http_handler(function ($request) use (&$handlerCalls, &$seen, $pair) {
    $handlerCalls++;

    $seen['method']       = $request->getMethod();
    $seen['uri']          = (string) $request->getUri();
    $seen['content_type'] = $request->getHeaderLine('Content-Type');

    $params = [];
    parse_str((string) $request->getBody(), $params);
    $seen['grant_type'] = $params['grant_type'] ?? '';

    // Verify the RS256 assertion against the SYNTHETIC public key with
    // firebase/php-jwt — a forged or mis-signed assertion throws here.
    try {
        $seen['claims'] = \Firebase\JWT\JWT::decode(
            (string) ($params['assertion'] ?? ''),
            new \Firebase\JWT\Key($pair['public'], 'RS256')
        );
        $seen['sig_ok'] = true;
    } catch (Throwable $e) {
        $seen['sig_ok'] = false;
    }

    return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
        'access_token' => 'zz-access-token-' . $handlerCalls,
        'expires_in'   => 3600,
        'token_type'   => 'Bearer',
    ]));
});

$t1 = se_gdm_access_token(1);
se_eq('zz-access-token-1', $t1, 'a token is minted through the library + injected handler');
se_eq(1, $handlerCalls, 'exactly one exchange request was made');
se_eq('POST', $seen['method'], 'the exchange is a POST');
se_eq('https://oauth2.googleapis.com/token', $seen['uri'], 'to the Google token endpoint');
se_eq('application/x-www-form-urlencoded', $seen['content_type'], 'as a form post');
se_eq('urn:ietf:params:oauth:grant-type:jwt-bearer', $seen['grant_type'], 'using the JWT-bearer grant');
se_eq(true, $seen['sig_ok'], 'the JWT assertion VERIFIES against the synthetic public key');
se_eq('zztest@zztest-project.iam.gserviceaccount.invalid', $seen['claims']->iss ?? null, 'assertion iss is the service account');
se_eq(SE_GDM_SCOPE, $seen['claims']->scope ?? null, 'assertion scope is the Data Manager ingestion scope');
se_eq('https://oauth2.googleapis.com/token', $seen['claims']->aud ?? null, 'assertion aud is the token endpoint');
se_ok(($seen['claims']->exp ?? 0) > time(), 'assertion carries a future expiry');

/* ======================================================================== */
se_group('Caching: no renewal before expiry, renewal inside the skew window');

$t2 = se_gdm_access_token(1);
se_eq('zz-access-token-1', $t2, 'the cached token is reused before expiry');
se_eq(1, $handlerCalls, 'no second exchange happened');

$st = se_gdm_credential_status(1);
se_eq(true, $st['ready'], 'the provider reports ready');
se_eq(true, $st['token_cached'], 'a token is cached');
se_eq(true, $st['token_valid_now'], 'and currently valid');
se_eq(false, strpos(json_encode($st), 'zz-access-token') !== false,
    'the token value appears NOWHERE in the status payload');
se_eq(false, strpos(json_encode($st), 'BEGIN PRIVATE KEY') !== false,
    'the private key appears NOWHERE in the status payload');

// Push the cached expiry inside the refresh skew: still nominally unexpired,
// but the provider must renew rather than hand out a nearly-dead token.
$cacheRef = &se_gdm_token_cache();
$cacheRef[1]['expires_at'] = time() + SE_GDM_TOKEN_SKEW - 10;

$t3 = se_gdm_access_token(1);
se_eq('zz-access-token-2', $t3, 'a fresh token is minted inside the skew window');
se_eq(2, $handlerCalls, 'via a second exchange');

$meta = se_gdm_token_cache_meta(1);
se_eq(true, $meta['valid'], 'and the new token is valid well past the skew');

/* ======================================================================== */
se_group('Handler failure gates with a sanitized AUTHENTICATION error');

se_gdm_token_cache_reset();

se_gdm_register_http_handler(function ($request) {
    throw new RuntimeException('boom ya29.ZZSYNTHETICSECRETTOKENVALUE0000000000 refused');
});

se_eq('', se_gdm_access_token(1), 'a failing exchange mints nothing');

$why = se_gdm_last_token_failure(1);
se_eq('authentication', $why['category'], 'classified as an authentication failure');
se_eq('token_exchange_failed', $why['code'], 'with the exchange-failed code');
se_eq(false, strpos($why['reason'], 'ZZSYNTHETICSECRETTOKENVALUE') !== false,
    'the exception text is NOT quoted in the classification');

$lastError = (string) get_option('se_secret_last_error_google_sa_1');
se_ok($lastError !== '', 'a sanitized last error is recorded for the screen');
se_eq(false, strpos($lastError, 'ZZSYNTHETICSECRETTOKENVALUE') !== false,
    'the recorded error never contains the token-shaped string');
se_eq(false, strpos($lastError, 'ya29') !== false, 'nor any ya29 fragment');

// An OAuth denial that is not about the key document is authentication too.
se_gdm_register_http_handler(function ($request) {
    return new \GuzzleHttp\Psr7\Response(401, ['Content-Type' => 'application/json'],
        json_encode(['error' => 'unauthorized_client', 'error_description' => 'nope']));
});

se_eq('', se_gdm_access_token(1), 'an unauthorized_client denial mints nothing');
se_eq('authentication', se_gdm_last_token_failure(1)['category'], 'and is classified authentication');

/* ======================================================================== */
se_group('Invalid or expired key documents gate as CONFIGURATION');

// (a) A key document whose private key is garbage: the RS256 signer refuses
// it locally, before any HTTP exchange could happen.
se_test_seed_google();
se_test_install_secret('google_sa_1', json_encode([
    'type' => 'service_account', 'project_id' => 'zztest-project',
    'client_email' => 'zztest@zztest-project.iam.gserviceaccount.invalid',
    'private_key'  => "-----BEGIN PRIVATE KEY-----\nZZGARBAGE\n-----END PRIVATE KEY-----\n",
]));

$handlerHits = 0;
se_gdm_register_http_handler(function ($request) use (&$handlerHits) {
    $handlerHits++;
    return new \GuzzleHttp\Psr7\Response(200, [], json_encode(['access_token' => 'never', 'expires_in' => 60]));
});

se_eq(false, se_gdm_key_document_valid(se_gdm_credential(1)), 'the garbage key does not parse');
se_eq('', se_gdm_access_token(1), 'so nothing is minted');
se_eq(0, $handlerHits, 'and NO exchange request was even attempted');

$why = se_gdm_last_token_failure(1);
se_eq('configuration', $why['category'], 'classified as a CONFIGURATION failure');
se_eq('bad_key_document', $why['code'], 'with the bad-key-document code');

$st = se_gdm_credential_status(1);
se_eq(true, $st['credential_valid'], 'the document still parses structurally');
se_eq(false, $st['key_document_valid'], 'but is reported unusable');
se_eq(false, $st['ready'], 'so the provider is NOT ready');

// (b) A key Google has revoked/expired: the endpoint answers invalid_grant.
$pair = se_test_google_keypair();
se_test_install_secret('google_sa_1', se_test_google_sa_json($pair['private']));
se_gdm_token_cache_reset();

se_gdm_register_http_handler(function ($request) {
    return new \GuzzleHttp\Psr7\Response(400, ['Content-Type' => 'application/json'],
        json_encode(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.']));
});

se_eq('', se_gdm_access_token(1), 'a rejected key mints nothing');

$why = se_gdm_last_token_failure(1);
se_eq('configuration', $why['category'], 'an invalid/expired key document is CONFIGURATION');
se_eq('key_rejected', $why['code'], 'with the key-rejected code');
se_ok(strpos($why['reason'], 'invalid_grant') !== false, 'the short OAuth code is carried');
se_eq(false, strpos($why['reason'], 'Invalid JWT Signature') !== false, 'the provider prose is NOT carried');

/* ======================================================================== */
se_group('The sender surfaces the classification as gated codes');

// Full sender preconditions minus a usable token: brand + mapping + enabled.
$mkRow = function () {
    return ['id' => 1, 'brand_id' => 1, 'lead_id' => 101, 'event_name' => 'Lead',
            'event_time' => date('Y-m-d H:i:s', time() - 7200), 'attribution_snapshot' => ''];
};

$GLOBALS['se_test']['options']['se_google_dm_enabled_1'] = 1;
$GLOBALS['se_test']['options']['se_google_conv_action_1'] = 'CA-1';

// (a) invalid_grant key rejection → bad_credential, gated.
$res = se_google_dm_send_event($mkRow());
se_eq(SE_OUTBOX_FAIL_GATED, $res['class'], 'a rejected key HOLDS the row (gated, no attempt burned)');
se_eq('bad_credential', $res['code'], 'with the bad_credential code');
se_eq(false, strpos($res['error'], 'Invalid JWT Signature') !== false, 'and a sanitized error only');

// (b) exchange failure → auth_failed, gated.
se_gdm_token_cache_reset();
se_gdm_register_http_handler(function ($request) {
    throw new RuntimeException('connection refused');
});
$res = se_google_dm_send_event($mkRow());
se_eq(SE_OUTBOX_FAIL_GATED, $res['class'], 'an authentication failure HOLDS the row');
se_eq('auth_failed', $res['code'], 'with the auth_failed code');

// (c) nothing installed at all → the long-standing no_credentials gate.
se_test_seed_google();
$GLOBALS['se_test']['options']['se_google_dm_enabled_1'] = 1;
$GLOBALS['se_test']['options']['se_google_conv_action_1'] = 'CA-1';
$res = se_google_dm_send_event($mkRow());
se_eq(SE_OUTBOX_FAIL_GATED, $res['class'], 'no credential still HOLDS the row');
se_eq('no_credentials', $res['code'], 'with the unchanged no_credentials code');

/* ======================================================================== */
se_group('The registered-provider seam still overrides the library default');

se_test_seed_google();
$pair = se_test_google_keypair();
se_test_install_secret('google_sa_1', se_test_google_sa_json($pair['private']));

$minted = 0;
se_gdm_register_token_provider(function ($credential, $scope) use (&$minted) {
    $minted++;
    se_ok($scope === SE_GDM_SCOPE, 'the override receives the Data Manager scope');
    se_ok(is_array($credential) && !empty($credential['client_email']), 'and the parsed credential');
    return ['access_token' => 'zz-override-token-' . $minted, 'expires_in' => 3600];
});

se_eq('zz-override-token-1', se_gdm_access_token(1), 'a registered provider takes precedence');
se_eq('zz-override-token-1', se_gdm_access_token(1), 'and its token is cached');
se_eq(1, $minted, 'one mint only');

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

// Register a signer override so tokens exist, and a fixture poller.
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
se_test_install_secret('landing_token', 'zz-landing-fixture-secret');

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
se_test_install_secret('landing_token', 'zz-landing-fixture-secret');
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

/* ----------------------------- cleanup ---------------------------------- */
se_test_seed_google();   // removes the fixture secret, clears cache + seams
