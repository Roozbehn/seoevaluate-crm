<?php
/**
 * Clinical-data prohibition.
 *
 * No procedure, diagnosis, body area, photo, passport, clinical note or health
 * attribute may reach Meta or Google. Asserted against the ACTUAL built
 * payloads, with every prohibited field deliberately populated on the source
 * rows first, so the test fails if a future change starts copying them.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Every term that must never appear in an outbound advertising payload. */
function se_test_prohibited_terms()
{
    return [
        'rhinoplasty', 'liposuction', 'implant', 'transplant', 'veneer',
        'diagnosis', 'diabetes', 'oncology', 'hiv',
        'nose', 'jaw', 'abdomen', 'breast',
        'photo.jpg', 'before_after', 'xray',
        'U1234567', 'passport',
        'clinical note', 'allergy', 'medication', 'blood_type',
    ];
}

function se_test_assert_clean($payload, $label)
{
    $json = strtolower(json_encode($payload));
    $hits = [];

    foreach (se_test_prohibited_terms() as $term) {
        if (strpos($json, strtolower($term)) !== false) {
            $hits[] = $term;
        }
    }

    se_eq([], $hits, $label . ($hits ? ' [LEAKED: ' . implode(', ', $hits) . ']' : ''));
}

function se_test_seed_prohibition()
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];

    $db->seed('tblse_brands', [
        ['id' => 1, 'name' => 'Brand A', 'active' => 1,
         'meta_dataset_id' => 'DS1', 'google_ads_customer_id' => '123'],
    ]);
    $db->seed('tblse_staff_brands', [['staff_id' => 10, 'brand_id' => 1]]);

    // A lead carrying EVERY prohibited attribute, plus legitimate ones.
    $db->seed('tblleads', [[
        'id' => 101, 'brand_id' => 1, 'consent_ads' => 1,
        'email' => 'ada@example.invalid', 'phonenumber' => '+905551112233',
        'meta_lead_id' => 'm-101', 'gclid' => 'GCLID1', 'fbc' => 'fb.1.x', 'fbp' => 'fb.2.y',
        'ctwa_clid' => 'CTWA1',
        // prohibited, deliberately present on the source row:
        'procedure_interest' => 'Rhinoplasty',
        'diagnosis'          => 'Diagnosis: deviated septum',
        'body_area'          => 'nose',
        'photo_url'          => 'https://x.invalid/photo.jpg',
        'clinical_note'      => 'Clinical note: allergy to medication',
        'blood_type'         => 'blood_type A+',
        'description'        => 'liposuction and implant consult; xray attached',
        'lost' => 0, 'junk' => 0,
    ]]);

    // A patient row holding the clinical layer.
    $db->seed('tblse_patients', [[
        'id' => 701, 'brand_id' => 1, 'lead_id' => 101, 'client_id' => 0,
        'nationality' => 'TR', 'passport_no' => 'U1234567',
        'retention_state' => 'active',
    ]]);

    $db->seed('tblse_consent_ledger', [[
        'id' => 1, 'brand_id' => 1, 'rel_type' => 'lead', 'rel_id' => 101, 'purpose' => 'ads',
        'state' => 'granted', 'consent_text_version' => 'v1', 'source' => 'web',
        'consent_at' => '2026-05-01 09:00:00', 'recorded_by' => 0,
    ]]);
    $db->seed('tblse_conversion_outbox', []);
    $GLOBALS['se_test']['options'] = [];
}

se_test_seed_prohibition();
se_test_act_as(1, [], true);

/* ======================================================================== */
se_group('The queue-time snapshot carries no clinical data');

se_outbox_queue(1, 101, 'meta_capi', 'Consultation Held', [], '2026-06-01 12:00:00');
$row = se_test_db()->rows('tblse_conversion_outbox')[0];

se_test_assert_clean(se_outbox_snapshot_decode($row['attribution_snapshot']),
    'attribution snapshot contains no prohibited field');
se_test_assert_clean(se_outbox_snapshot_decode($row['consent_snapshot']),
    'consent snapshot contains no prohibited field');

/* ======================================================================== */
se_group('Meta CAPI payload carries no clinical data');

$event = se_capi_build_event($row, null);
se_test_assert_clean($event, 'CAPI event contains no prohibited field');

se_eq(['event_name', 'event_time', 'action_source', 'user_data', 'custom_data', 'event_id'],
    array_keys($event), 'CAPI event has exactly the expected top-level keys');
se_eq(['event_source' => 'crm', 'lead_event_source' => SE_CLINIC_NAME],
    $event['custom_data'], 'custom_data carries only the two documented values');

$allowedUserData = ['lead_id', 'em', 'ph', 'ctwa_clid', 'fbc', 'fbp'];
foreach (array_keys($event['user_data']) as $k) {
    se_ok(in_array($k, $allowedUserData, true), "CAPI user_data key '{$k}' is on the allowlist");
}

/* Hashed, never raw. */
se_eq(false, strpos(json_encode($event), 'ada@example.invalid') !== false,
    'raw email never appears in the CAPI payload');
se_eq(false, strpos(json_encode($event), '905551112233') !== false,
    'raw phone never appears in the CAPI payload');
se_eq(64, strlen($event['user_data']['em'][0]), 'email is transmitted as a SHA-256 hash');
se_eq(64, strlen($event['user_data']['ph'][0]), 'phone is transmitted as a SHA-256 hash');

/* The event name is the pipeline stage, which is treatment-agnostic by policy. */
foreach (se_pipeline_stages() as $stage) {
    $json = strtolower($stage);
    $bad = false;
    foreach (se_test_prohibited_terms() as $term) {
        if (strpos($json, strtolower($term)) !== false) { $bad = true; }
    }
    se_eq(false, $bad, "pipeline stage '{$stage}' is treatment-agnostic");
}

/* ======================================================================== */
se_group('Google Data Manager payload carries no clinical data');

$g = se_gdm_build_event($row, null, true);
se_test_assert_clean($g, 'Google event contains no prohibited field');

se_eq(false, strpos(json_encode($g), 'ada@example.invalid') !== false,
    'raw email never appears in the Google payload');
se_eq(false, strpos(json_encode($g), 'U1234567') !== false,
    'the passport number never appears in the Google payload');

$allowedTop = ['destinationReferences', 'transactionId', 'eventTimestamp', 'eventSource',
               'consent', 'adIdentifiers', 'userData'];
foreach (array_keys($g) as $k) {
    se_ok(in_array($k, $allowedTop, true), "Google event key '{$k}' is on the allowlist");
}

foreach (array_keys($g['adIdentifiers'] ?? []) as $k) {
    se_ok(in_array($k, ['gclid', 'gbraid', 'wbraid'], true), "adIdentifiers key '{$k}' is a click id");
}

foreach (($g['userData']['userIdentifiers'] ?? []) as $ident) {
    foreach (array_keys($ident) as $k) {
        se_ok(in_array($k, ['emailAddress', 'phoneNumber'], true), "userIdentifiers key '{$k}' is allowed");
    }
    foreach ($ident as $v) {
        se_eq(64, strlen((string) $v), 'every Google identifier is a SHA-256 hash');
    }
}

/* ======================================================================== */
se_group('The builders are lead-only: patient data cannot reach them');

// The patient table holds the clinical layer. Neither builder takes a patient.
$reflectCapi = new ReflectionFunction('se_capi_build_event');
$reflectGdm  = new ReflectionFunction('se_gdm_build_event');

foreach ([$reflectCapi, $reflectGdm] as $fn) {
    $params = array_map(function ($p) { return $p->getName(); }, $fn->getParameters());
    se_eq(false, in_array('patient', $params, true),
        $fn->getName() . '() takes no patient argument');
}

// Scan the CODE, not the comments. Both files document the prohibition in
// prose ("no procedure, diagnosis, body area..."), and matching that text
// would fail the test for saying the right thing. php_strip_whitespace()
// removes comments, leaving only what actually executes.
$capiSrc = php_strip_whitespace(dirname(__DIR__) . '/se_capi.php');
$gdmSrc  = php_strip_whitespace(dirname(__DIR__) . '/se_google_dm.php');

foreach (['se_patients', 'passport_no', 'procedure_interest', 'diagnosis',
          'body_area', 'clinical_note', 'blood_type', 'photo_url'] as $needle) {
    se_eq(false, strpos($capiSrc, $needle) !== false, "se_capi.php never references '{$needle}'");
    se_eq(false, strpos($gdmSrc, $needle) !== false, "se_google_dm.php never references '{$needle}'");
}

/* ======================================================================== */
se_group('Meta Lead Ads cannot map a clinical column into a lead');

foreach (['procedure_interest', 'diagnosis', 'body_area', 'photo_url',
          'clinical_note', 'blood_type', 'passport_no'] as $col) {
    $map = se_leadgen_sanitize_field_map(['some_field' => $col]);
    se_eq(false, in_array($col, $map, true), "an ad form cannot map into '{$col}'");
}
