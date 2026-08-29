<?php
/**
 * Patient safety: cross-brand link rejection, brand-scoped linked reads,
 * guarded mutations, archive vs deletion-request separation, link uniqueness
 * and passport non-collection.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function se_test_seed_patients()
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];

    $db->seed('tblse_brands', [
        ['id' => 1, 'name' => 'Brand A', 'active' => 1, 'meta_dataset_id' => '', 'google_ads_customer_id' => ''],
        ['id' => 2, 'name' => 'Brand B', 'active' => 1, 'meta_dataset_id' => '', 'google_ads_customer_id' => ''],
    ]);
    $db->seed('tblse_staff_brands', [
        ['staff_id' => 10, 'brand_id' => 1],
        ['staff_id' => 20, 'brand_id' => 2],
    ]);
    $db->seed('tblleads', [
        ['id' => 101, 'brand_id' => 1, 'name' => 'Lead A'],
        ['id' => 202, 'brand_id' => 2, 'name' => 'Lead B'],
    ]);
    $db->seed('tblclients', [
        ['userid' => 501, 'brand_id' => 1, 'company' => 'Client A'],
        ['userid' => 502, 'brand_id' => 2, 'company' => 'Client B'],
    ]);
    $db->seed('tblse_patients', [
        ['id' => 701, 'brand_id' => 1, 'lead_id' => 101, 'client_id' => 0,
         'retention_state' => 'active', 'passport_no' => null,
         'archived_at' => null, 'archived_by' => 0, 'deletion_requested_at' => null],
        ['id' => 702, 'brand_id' => 2, 'lead_id' => 202, 'client_id' => 0,
         'retention_state' => 'active', 'passport_no' => null,
         'archived_at' => null, 'archived_by' => 0, 'deletion_requested_at' => null],
    ]);
    $db->seed('tblse_appointments', [
        ['id' => 801, 'brand_id' => 1, 'rel_type' => 'lead', 'rel_id' => 101,
         'start_at' => '2026-06-01 10:00:00', 'title' => 'A', 'status' => 'scheduled'],
    ]);
    $db->seed('tblse_consent_ledger', []);
    $db->seed('tblse_record_access_log', []);
    $GLOBALS['se_test']['options'] = [];
    $GLOBALS['SE_PATIENT_CRYPTO'] = null;
}

se_test_seed_patients();

/* ======================================================================== */
se_group('Linked lead/client must exist and share the patient brand');

se_test_act_as(10, []);   // Brand A staff

$v = se_patient_validate(['brand_id' => 1, 'lead_id' => 101]);
se_eq([], $v['errors'], 'same-brand lead link is accepted');

$v = se_patient_validate(['brand_id' => 1, 'lead_id' => 202]);
se_ok(in_array('lead_brand_mismatch', $v['errors'], true), 'CROSS-BRAND lead link is rejected');
se_eq(1, $v['clean']['brand_id'], 'the brand is not silently rewritten to match the lead');

$v = se_patient_validate(['brand_id' => 1, 'client_id' => 502]);
se_ok(in_array('client_brand_mismatch', $v['errors'], true), 'CROSS-BRAND customer link is rejected');

$v = se_patient_validate(['brand_id' => 1, 'lead_id' => 999999]);
se_ok(in_array('lead_not_found', $v['errors'], true), 'a non-existent lead link is rejected');

$v = se_patient_validate(['brand_id' => 1, 'client_id' => 999999]);
se_ok(in_array('client_not_found', $v['errors'], true), 'a non-existent customer link is rejected');

$v = se_patient_validate(['brand_id' => 2, 'lead_id' => 202]);
se_ok(in_array('brand_denied', $v['errors'], true), 'creating in a brand the staff member cannot reach is rejected');

$v = se_patient_validate(['brand_id' => 1]);
se_ok(in_array('link_required', $v['errors'], true), 'a patient must link to a lead or a customer');

/* ======================================================================== */
se_group('Linked reads are brand-scoped');

se_test_seed_patients();
se_test_act_as(10, []);
$db = se_test_db();

// Point Brand A's patient at Brand B's lead, simulating stale or hostile data.
$db->tables['tblse_patients'][0]['lead_id']   = 202;
$db->tables['tblse_patients'][0]['client_id'] = 502;

$patient = (object) $db->rows('tblse_patients')[0];
$links   = se_patient_links($patient);

se_eq(null, $links['lead'], "a foreign-brand lead is NOT rendered on the patient page");
se_eq(null, $links['client'], "a foreign-brand customer is NOT rendered on the patient page");

// Same-brand links still resolve.
$db->tables['tblse_patients'][0]['lead_id']   = 101;
$db->tables['tblse_patients'][0]['client_id'] = 501;
$patient = (object) $db->rows('tblse_patients')[0];
$links   = se_patient_links($patient);
se_ok($links['lead'] !== null, 'a same-brand lead still resolves');
se_ok($links['client'] !== null, 'a same-brand customer still resolves');
se_eq(1, count($links['appointments']), 'same-brand appointments still resolve');

/* ======================================================================== */
se_group('Mutations carry the brand predicate');

se_test_seed_patients();
se_test_act_as(10, []);   // Brand A
$db = se_test_db();

se_eq(false, se_patient_update(702, ['brand_id' => 2, 'nationality' => 'XX']),
    "Brand A staff cannot update Brand B's patient");
se_eq(null, $db->rows('tblse_patients')[1]['nationality'] ?? null, "Brand B's patient row is unchanged");

se_eq(true, se_patient_update(701, ['brand_id' => 1, 'nationality' => 'TR']),
    'Brand A staff can update their own patient');
se_eq('TR', $db->rows('tblse_patients')[0]['nationality'], 'own patient row is updated');

se_eq(false, se_patient_archive(702, 2), "Brand A staff cannot archive Brand B's patient");
se_eq('active', $db->rows('tblse_patients')[1]['retention_state'], "Brand B's patient stays active");

se_eq(true, se_patient_archive(701, 1), 'Brand A staff can archive their own patient');
se_eq('archived', $db->rows('tblse_patients')[0]['retention_state'], 'own patient is archived');

/* ======================================================================== */
se_group('Archiving is NOT a deletion request');

se_test_seed_patients();
se_test_act_as(10, []);
$db = se_test_db();

se_patient_archive(701, 1);
$row = $db->rows('tblse_patients')[0];
se_eq('archived', $row['retention_state'], 'archive sets the retention state');
se_ok(!empty($row['archived_at']), 'archive stamps archived_at');
se_eq(10, (int) $row['archived_by'], 'archive records who did it');
se_eq(null, $row['deletion_requested_at'],
    'archiving does NOT record a deletion request (it used to, losing the legal signal)');

se_patient_request_deletion(701, 1);
$row = $db->rows('tblse_patients')[0];
se_ok(!empty($row['deletion_requested_at']), 'a deletion request is recorded separately');

/* ======================================================================== */
se_group('Link uniqueness per brand');

se_test_seed_patients();
se_test_act_as(10, []);

se_eq(true, se_patient_link_conflict(1, 101, 0), 'a second patient for the same (brand, lead) conflicts');
se_eq(false, se_patient_link_conflict(1, 101, 0, 701), 'editing the SAME patient is not a conflict');
se_eq(false, se_patient_link_conflict(1, 0, 501), 'an unused (brand, customer) pair is free');
se_eq(false, se_patient_link_conflict(2, 101, 0), 'the same lead id under another brand is not a conflict');
se_eq(false, se_patient_link_conflict(1, 0, 0), 'unlinked (0/0) never conflicts');

/* ======================================================================== */
se_group('Passport is not collected in plaintext');

se_test_seed_patients();
se_test_act_as(10, []);

se_eq(false, se_patient_passport_collection_enabled(), 'passport collection is OFF by default');

$v = se_patient_validate(['brand_id' => 1, 'lead_id' => 101, 'passport_no' => 'U1234567']);
se_eq([], $v['errors'], 'a submitted passport does not break the save');
se_eq(null, $v['clean']['passport_no'], 'a submitted passport is DISCARDED while collection is off');

// Enabled but with no encryption provider: refuse rather than store plaintext.
$GLOBALS['se_test']['options']['se_patient_collect_passport'] = 1;
se_eq(true, se_patient_passport_collection_enabled(), 'collection can be enabled by the owner');
se_eq(false, se_patient_crypto_available(), 'no encryption provider ships with this module');

$v = se_patient_validate(['brand_id' => 1, 'lead_id' => 101, 'passport_no' => 'U1234567']);
se_ok(in_array('passport_storage_unavailable', $v['errors'], true),
    'enabled WITHOUT encryption refuses the save');
se_eq(null, $v['clean']['passport_no'], 'nothing is stored in plaintext');

// With a provider registered, the value is encrypted before storage.
$GLOBALS['SE_PATIENT_CRYPTO'] = [
    'encrypt' => function ($v) { return 'enc:' . base64_encode($v); },
    'decrypt' => function ($v) { return base64_decode(substr($v, 4)); },
];
se_eq(true, se_patient_crypto_available(), 'a registered provider is detected');
$v = se_patient_validate(['brand_id' => 1, 'lead_id' => 101, 'passport_no' => 'U1234567']);
se_eq([], $v['errors'], 'with a provider the save is accepted');
se_eq(false, strpos((string) $v['clean']['passport_no'], 'U1234567') !== false,
    'the stored value is not the plaintext passport');
se_ok(strpos((string) $v['clean']['passport_no'], 'enc:') === 0, 'the stored value is ciphertext');

// Display is always masked.
se_eq(false, strpos(se_patient_mask_passport('U1234567'), 'U12345') !== false,
    'the mask does not reveal the passport');
se_ok(substr(se_patient_mask_passport('U1234567'), -2) === '67', 'the mask keeps only a short tail');

$GLOBALS['SE_PATIENT_CRYPTO'] = null;
unset($GLOBALS['se_test']['options']['se_patient_collect_passport']);

/* ======================================================================== */
se_group('Scoped selectors offer only linkable records');

se_test_seed_patients();

se_test_act_as(10, []);
$leads = se_patient_selectable_leads();
se_eq(1, count($leads), 'Brand A staff is offered one lead');
se_eq(101, (int) $leads[0]['id'], 'and it is their own');

$clients = se_patient_selectable_clients();
se_eq(1, count($clients), 'Brand A staff is offered one customer');
se_eq(501, (int) $clients[0]['userid'], 'and it is their own');

se_test_act_as(20, []);
$leads = se_patient_selectable_leads();
se_eq(202, (int) $leads[0]['id'], 'Brand B staff is offered only Brand B records');

se_test_act_as(1, [], true);
se_eq(2, count(se_patient_selectable_leads()), 'an admin is offered every lead');
