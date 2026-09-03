<?php
/**
 * Consent decision, ledger provenance, withdrawal, and Meta Lead Ads mapping.
 *
 * The regression that matters most: the old rule ended in `|| $val !== ''`, so
 * "no" and "hayır" granted consent. Every negative case below would have
 * FAILED before this change.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function se_test_seed_consent()
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];

    $db->seed('tblse_brands', [['id' => 1, 'name' => 'Brand A', 'active' => 1,
        'meta_dataset_id' => 'DS1', 'google_ads_customer_id' => '']]);
    $db->seed('tblse_staff_brands', [['staff_id' => 10, 'brand_id' => 1]]);
    $db->seed('tblleads', [
        ['id' => 101, 'brand_id' => 1, 'meta_lead_id' => 'm-101', 'consent_ads' => 0,
         'email' => 'a@example.invalid', 'lost' => 0, 'junk' => 0],
    ]);
    $db->seed('tblse_consent_ledger', []);
    $db->seed('tblse_conversion_outbox', []);
    $db->seed('tblse_meta_forms', [
        ['id' => 1, 'brand_id' => 1, 'page_id' => 'P1', 'form_id' => 'F1', 'active' => 1,
         'field_map_json' => json_encode(['full_name' => 'name', 'email' => 'email'])],
        ['id' => 2, 'brand_id' => 2, 'page_id' => 'P2', 'form_id' => 'F1', 'active' => 1,
         'field_map_json' => ''],
    ]);
    $GLOBALS['se_test']['options'] = [];
}

se_test_seed_consent();
se_test_act_as(1, [], true);

/* ======================================================================== */
se_group('Consent decision — affirmative allowlist only');

foreach (['yes', 'YES', ' Yes ', 'true', '1', 'evet', 'EVET', 'onay', 'Onay',
          'onaylıyorum', 'onayliyorum', 'kabul', 'agree', 'accept', 'checked'] as $v) {
    se_eq(SE_CONSENT_GRANTED, se_consent_decide($v), "'{$v}' GRANTS consent");
}

/* ======================================================================== */
se_group('Consent decision — negatives must NOT grant (the original defect)');

foreach (['no', 'NO', ' No ', 'hayır', 'HAYIR', 'hayir', 'false', '0', 'off',
          'reddediyorum', 'istemiyorum', 'decline', 'reject', 'opt_out'] as $v) {
    se_eq(SE_CONSENT_WITHDRAWN, se_consent_decide($v), "'{$v}' is an explicit REFUSAL");
    se_eq(false, se_consent_is_granted($v), "'{$v}' does NOT grant consent");
}

/* ======================================================================== */
se_group('Consent decision — blank and unknown are never consent');

foreach (['', '   ', "\t", "\n"] as $v) {
    se_eq(SE_CONSENT_UNKNOWN, se_consent_decide($v), 'blank answer is UNKNOWN');
    se_eq(false, se_consent_is_granted($v), 'blank answer does NOT grant consent');
}

foreach (['maybe', 'lorem ipsum', 'call me', '???', 'yes please but not for ads', 'sí'] as $v) {
    se_eq(SE_CONSENT_UNKNOWN, se_consent_decide($v), "unrecognised text '{$v}' is UNKNOWN");
    se_eq(false, se_consent_is_granted($v), "unrecognised text '{$v}' does NOT grant consent");
}

/* ======================================================================== */
se_group('Configurable allowlist');

$GLOBALS['se_test']['options']['se_consent_affirmative_values'] = 'tamam, olur';
se_eq(SE_CONSENT_GRANTED, se_consent_decide('tamam'), 'configured affirmative value grants');
se_eq(SE_CONSENT_GRANTED, se_consent_decide('OLUR'), 'configured affirmative value is case-insensitive');
se_eq(SE_CONSENT_UNKNOWN, se_consent_decide('yes'), 'configuration REPLACES the default list');
unset($GLOBALS['se_test']['options']['se_consent_affirmative_values']);
se_eq(SE_CONSENT_GRANTED, se_consent_decide('yes'), 'defaults restored when unconfigured');

/* ======================================================================== */
se_group('Consent-text version is server-controlled');

se_eq('v1', se_consent_text_version(1), 'falls back to the built-in default version');
$GLOBALS['se_test']['options']['se_consent_text_version'] = 'kvkk-2026-01';
se_eq('kvkk-2026-01', se_consent_text_version(1), 'global configured version is used');
$GLOBALS['se_test']['options']['se_consent_text_version_1'] = 'brand1-v3';
se_eq('brand1-v3', se_consent_text_version(1), 'per-brand version overrides the global one');

// A hidden client field must never become the authoritative version.
se_test_seed_consent();
$GLOBALS['se_test']['options']['se_consent_text_version'] = 'server-v9';
se_consent_grant(1, 101, 'ads', 'web_to_lead', 'consent_ads', 'yes');
$row = se_consent_current_row(1, 'lead', 101, 'ads');
se_eq('server-v9', $row->consent_text_version, 'ledger records the SERVER version, not a client-supplied one');

/* ======================================================================== */
se_group('Ledger provenance: question, raw answer, normalized answer');

se_test_seed_consent();
se_consent_grant(1, 101, 'ads', 'meta_lead_ads', 'kvkk_consent_question', ' EVET ');
$row = se_consent_current_row(1, 'lead', 101, 'ads');
se_eq('kvkk_consent_question', $row->question_key, 'ledger records WHICH question was answered');
se_eq(' EVET ', $row->answer_raw, 'ledger records the raw answer verbatim');
se_eq('evet', $row->answer_normalized, 'ledger records the normalized answer used for the decision');
se_eq('meta_lead_ads', $row->source, 'ledger records the source');
se_eq(SE_CONSENT_GRANTED, $row->state, 'ledger records the decision');

/* ======================================================================== */
se_group('Ledger is authoritative; consent_ads is derived');

se_test_seed_consent();
$db = se_test_db();

se_eq(0, (int) $db->rows('tblleads')[0]['consent_ads'], 'lead starts without ad consent');
se_consent_grant(1, 101, 'ads', 'web_to_lead', 'consent_ads', 'yes');
se_eq(1, (int) $db->rows('tblleads')[0]['consent_ads'], 'grant syncs the derived flag on');
se_eq(true, se_consent_granted(1, 'lead', 101, 'ads'), 'ledger reports granted');

se_consent_withdraw(1, 101, 'ads', 'data_subject_request', 'consent_ads', 'no');
se_eq(0, (int) $db->rows('tblleads')[0]['consent_ads'], 'withdrawal syncs the derived flag off');
se_eq(false, se_consent_granted(1, 'lead', 101, 'ads'), 'ledger reports withdrawn');
se_eq(2, count($db->rows('tblse_consent_ledger')), 'ledger is append-only: withdrawal ADDS a row');

se_consent_grant(1, 101, 'ads', 'web_to_lead', 'consent_ads', 'evet');
se_eq(1, (int) $db->rows('tblleads')[0]['consent_ads'], 're-grant syncs the flag back on');
se_eq(3, count($db->rows('tblse_consent_ledger')), 'ledger now holds three rows');

/* ======================================================================== */
se_group('Point-in-time consent (historical reproducibility)');

se_test_seed_consent();
$db = se_test_db();
$db->seed('tblse_consent_ledger', [
    ['id' => 1, 'brand_id' => 1, 'rel_type' => 'lead', 'rel_id' => 101, 'purpose' => 'ads',
     'state' => 'granted',   'consent_text_version' => 'v1', 'source' => 'web',
     'consent_at' => '2026-01-01 10:00:00', 'recorded_by' => 0],
    ['id' => 2, 'brand_id' => 1, 'rel_type' => 'lead', 'rel_id' => 101, 'purpose' => 'ads',
     'state' => 'withdrawn', 'consent_text_version' => 'v2', 'source' => 'dsr',
     'consent_at' => '2026-03-01 10:00:00', 'recorded_by' => 0],
]);

$at = se_consent_state_at(1, 'lead', 101, 'ads', '2026-02-01 00:00:00');
se_eq(SE_CONSENT_GRANTED, $at['state'], 'state as at February reflects the January grant');
se_eq('v1', $at['version'], 'point-in-time lookup returns the version in force then');
se_eq(1, $at['ledger_id'], 'point-in-time lookup identifies the exact ledger row');

$at = se_consent_state_at(1, 'lead', 101, 'ads', '2026-04-01 00:00:00');
se_eq(SE_CONSENT_WITHDRAWN, $at['state'], 'state as at April reflects the March withdrawal');
se_eq('v2', $at['version'], 'later version is returned for the later point in time');

$at = se_consent_state_at(1, 'lead', 101, 'ads', '2025-12-01 00:00:00');
se_eq(SE_CONSENT_UNKNOWN, $at['state'], 'before any record, state is UNKNOWN (never granted)');
se_eq(0, $at['ledger_id'], 'no ledger row before the first record');

/* ======================================================================== */
se_group('Withdrawal holds unsent conversions');

se_test_seed_consent();
$db = se_test_db();
$db->seed('tblse_conversion_outbox', [
    ['id' => 1, 'brand_id' => 1, 'lead_id' => 101, 'destination' => 'meta_capi', 'status' => 'pending',   'event_name' => 'Lead'],
    ['id' => 2, 'brand_id' => 1, 'lead_id' => 101, 'destination' => 'meta_capi', 'status' => 'processing','event_name' => 'Qualified'],
    ['id' => 3, 'brand_id' => 1, 'lead_id' => 101, 'destination' => 'meta_capi', 'status' => 'sent',      'event_name' => 'New'],
    ['id' => 4, 'brand_id' => 1, 'lead_id' => 999, 'destination' => 'meta_capi', 'status' => 'pending',   'event_name' => 'Lead'],
]);

se_consent_grant(1, 101, 'ads', 'web_to_lead', 'consent_ads', 'yes');
$res = se_consent_withdraw(1, 101, 'ads', 'data_subject_request', 'consent_ads', 'hayır');

se_eq(2, $res['held'], 'withdrawal holds both unsent rows for this lead');
$rows = $db->rows('tblse_conversion_outbox');
se_eq('skipped', $rows[0]['status'], 'pending row is held');
se_eq('skipped', $rows[1]['status'], 'processing row is held');
se_eq('consent_withdrawn', $rows[0]['failure_class'], 'held row records why');
se_eq('sent', $rows[2]['status'], 'an already-transmitted row is NOT rewritten');
se_eq('pending', $rows[3]['status'], "another lead's row is untouched");

/* ======================================================================== */
se_group('Meta Lead Ads field mapping — consent');

$grant = se_leadgen_map_fields([
    ['name' => 'full_name', 'values' => ['Ada']],
    ['name' => 'email', 'values' => ['ada@example.invalid']],
    ['name' => 'marketing_consent', 'values' => ['evet']],
], ['full_name' => 'name', 'email' => 'email']);
se_eq(SE_CONSENT_GRANTED, $grant['consent_state'], 'affirmative Meta answer grants');
se_eq(true, $grant['consent_ads'], 'boolean back-compat is true on a grant');
se_eq('marketing_consent', $grant['consent_question'], 'consent question id is carried out');
se_eq('evet', $grant['consent_answer'], 'raw answer is carried out');
se_eq('Ada', $grant['lead']['name'], 'mapped columns still populate');

foreach (['no', 'hayır', 'false', '0'] as $answer) {
    $r = se_leadgen_map_fields([
        ['name' => 'marketing_consent', 'values' => [$answer]],
    ], []);
    se_eq(SE_CONSENT_WITHDRAWN, $r['consent_state'], "Meta answer '{$answer}' is a refusal");
    se_eq(false, $r['consent_ads'], "Meta answer '{$answer}' does NOT grant (was the bug)");
}

$r = se_leadgen_map_fields([['name' => 'marketing_consent', 'values' => ['']]], []);
se_eq(SE_CONSENT_UNKNOWN, $r['consent_state'], 'blank Meta answer is UNKNOWN');
se_eq(false, $r['consent_ads'], 'blank Meta answer does NOT grant');

$r = se_leadgen_map_fields([['name' => 'full_name', 'values' => ['Ada']]], ['full_name' => 'name']);
se_eq(SE_CONSENT_UNKNOWN, $r['consent_state'], 'missing consent question is UNKNOWN');
se_eq(false, $r['consent_ads'], 'missing consent question does NOT grant');

$r = se_leadgen_map_fields([['name' => 'marketing_consent', 'values' => ['whatever they typed']]], []);
se_eq(false, $r['consent_ads'], 'free-text Meta answer does NOT grant');

// A refusal anywhere in the form wins over an earlier grant.
$r = se_leadgen_map_fields([
    ['name' => 'consent_a', 'values' => ['yes']],
    ['name' => 'consent_b', 'values' => ['hayır']],
], []);
se_eq(SE_CONSENT_WITHDRAWN, $r['consent_state'], 'an explicit refusal overrides an earlier grant');

/* ======================================================================== */
se_group('Meta Lead Ads field-map allowlist');

$map = se_leadgen_sanitize_field_map([
    'full_name'  => 'name',
    'evil_a'     => 'brand_id',
    'evil_b'     => 'consent_ads',
    'evil_c'     => 'gclid',
    'evil_d'     => 'meta_lead_id',
]);
se_eq(true,  isset($map['full_name']),  'allowlisted contact column survives');
se_eq(false, in_array('brand_id', $map, true),     'brand_id cannot be written from an ad form');
se_eq(false, in_array('consent_ads', $map, true),  'consent_ads cannot be written from an ad form');
se_eq(false, in_array('gclid', $map, true),        'immutable first-touch column cannot be written');
se_eq(false, in_array('meta_lead_id', $map, true), 'meta_lead_id cannot be remapped');

$mapped = se_leadgen_map_fields([
    ['name' => 'evil_a', 'values' => ['2']],
    ['name' => 'full_name', 'values' => ['Ada']],
], ['evil_a' => 'brand_id', 'full_name' => 'name']);
se_eq(false, array_key_exists('brand_id', $mapped['lead']), 'a hostile mapping cannot smuggle brand_id through');

/* ======================================================================== */
se_group('Meta Lead Ads routing needs page_id AND form_id');

se_test_seed_consent();
se_eq(null, se_leadgen_route('', 'F1'), 'missing page_id refuses to route');
se_eq(null, se_leadgen_route('P1', ''), 'missing form_id refuses to route');
se_eq(null, se_leadgen_route('P9', 'F1'), 'unknown page for a known form does not route');

$route = se_leadgen_route('P1', 'F1');
se_eq(1, $route['brand_id'], 'page+form pair routes to the right brand');

$route2 = se_leadgen_route('P2', 'F1');
se_eq(2, $route2['brand_id'], 'the same form id under a different page routes to a DIFFERENT brand');

// Ambiguous mapping is refused rather than resolved arbitrarily.
se_test_db()->seed('tblse_meta_forms', [
    ['id' => 1, 'brand_id' => 1, 'page_id' => 'P1', 'form_id' => 'F1', 'active' => 1, 'field_map_json' => ''],
    ['id' => 2, 'brand_id' => 2, 'page_id' => 'P1', 'form_id' => 'F1', 'active' => 1, 'field_map_json' => ''],
]);
se_eq(null, se_leadgen_route('P1', 'F1'), 'an ambiguous page+form mapping is refused, not guessed');

/* ======================================================================== */
se_group('Meta Lead Ads brand-move is parked, not applied');

se_test_seed_consent();
$db = se_test_db();
$db->seed('tblleads', [
    ['id' => 101, 'brand_id' => 1, 'meta_lead_id' => 'm-101', 'consent_ads' => 0, 'lost' => 0, 'junk' => 0],
]);

$r = se_leadgen_upsert_lead(2, 'm-101', ['name' => 'Moved']);
se_eq('brand_mismatch', $r, 'a webhook for another brand cannot move an existing lead');
se_eq(1, (int) $db->rows('tblleads')[0]['brand_id'], 'the lead stays in its original brand');
se_eq(false, isset($db->rows('tblleads')[0]['name']), 'no field was written during the refused move');

$r = se_leadgen_upsert_lead(1, 'm-101', ['name' => 'Same brand update']);
se_eq(101, $r, 'a same-brand webhook still updates the lead');
se_eq('Same brand update', $db->rows('tblleads')[0]['name'], 'same-brand update applies');

/* ---- audit J15 / AZCRM-PJ-004: a NEW ad lead goes through the same downstream as a website lead ---- */
$db->seed('tblleads_status', [['id' => 5, 'statusorder' => 1]]);
$db->seed('tblleads_sources', [['id' => 7]]);
hooks()->fired = [];
$firedBefore = count(hooks()->fired);
$new = se_leadgen_upsert_lead(1, 'm-202', ['name' => 'Ad Person', 'phonenumber' => '+905550000202']);
se_ok((int) $new > 0 && $new !== 101, 'a new ad lead is inserted');
$fired = array_values(array_filter(hooks()->fired, function ($f) { return $f[0] === 'lead_created'; }));
se_eq(1, count($fired), 'lead_created fires exactly once for the new lead');
se_eq((int) $new, (int) $fired[0][1], 'with the new lead id');
$again = se_leadgen_upsert_lead(1, 'm-202', ['name' => 'Ad Person again']);
se_eq((int) $new, (int) $again, 'a redelivered notification updates the same lead');
se_eq(1, count(array_filter(hooks()->fired, function ($f) { return $f[0] === 'lead_created'; })), 'and does NOT fire lead_created again');
se_ok(array_key_exists('leadgen', se_dispatch_steps()) && se_dispatch_steps()['leadgen'] === 'se_leadgen_process_pending', 'the dispatcher drains Lead Ads notifications every minute');
