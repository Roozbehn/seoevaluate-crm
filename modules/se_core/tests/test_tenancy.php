<?php
/**
 * Tenant (brand) isolation matrix.
 *
 * Actors:
 *   admin        - Perfex admin, sees everything
 *   staffA       - mapped to brand 1 only
 *   staffB       - mapped to brand 2 only
 *   reporter     - mapped to brand 1, holds se_reports.view ONLY
 *   configurator - mapped to brand 1, holds se_brands.* (configuration) ONLY
 *   triager      - mapped to brand 1, plus se_tenancy.triage_unassigned
 *   global       - holds se_tenancy.all_brands
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function se_test_seed_tenancy()
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];

    $db->seed('tblse_brands', [
        ['id' => 1, 'name' => 'Brand A', 'active' => 1, 'meta_dataset_id' => '', 'google_ads_customer_id' => ''],
        ['id' => 2, 'name' => 'Brand B', 'active' => 1, 'meta_dataset_id' => '', 'google_ads_customer_id' => ''],
    ]);

    // staff 10 -> brand 1 ; staff 20 -> brand 2 ; 30/40/50 -> brand 1
    $db->seed('tblse_staff_brands', [
        ['staff_id' => 10, 'brand_id' => 1],
        ['staff_id' => 20, 'brand_id' => 2],
        ['staff_id' => 30, 'brand_id' => 1],
        ['staff_id' => 40, 'brand_id' => 1],
        ['staff_id' => 50, 'brand_id' => 1],
        ['staff_id' => 60, 'brand_id' => 1],
        ['staff_id' => 61, 'brand_id' => 1],
        ['staff_id' => 61, 'brand_id' => 2],
    ]);

    $db->seed('tblleads', [
        ['id' => 101, 'brand_id' => 1, 'email' => 'a@example.invalid', 'consent_ads' => 1, 'lost' => 0, 'junk' => 0],
        ['id' => 202, 'brand_id' => 2, 'email' => 'b@example.invalid', 'consent_ads' => 1, 'lost' => 0, 'junk' => 0],
        ['id' => 303, 'brand_id' => 0, 'email' => 'u@example.invalid', 'consent_ads' => 0, 'lost' => 0, 'junk' => 0],
    ]);

    $db->seed('tblclients', [
        ['userid' => 501, 'brand_id' => 1],
        ['userid' => 502, 'brand_id' => 2],
    ]);

    $db->seed('tblse_patients', [
        ['id' => 701, 'brand_id' => 1, 'lead_id' => 101, 'client_id' => 0, 'retention_state' => 'active'],
        ['id' => 702, 'brand_id' => 2, 'lead_id' => 202, 'client_id' => 0, 'retention_state' => 'active'],
    ]);

    $db->seed('tblse_appointments', [
        ['id' => 801, 'brand_id' => 1, 'status' => 'scheduled'],
        ['id' => 802, 'brand_id' => 2, 'status' => 'scheduled'],
    ]);

    $db->seed('tblse_wa_conversations', [
        ['id' => 901, 'brand_id' => 1],
        ['id' => 902, 'brand_id' => 2],
    ]);

    $db->seed('tblse_wa_messages', [
        ['id' => 911, 'brand_id' => 1, 'wamid' => 'wamid.A'],
        ['id' => 912, 'brand_id' => 2, 'wamid' => 'wamid.B'],
    ]);

    $db->seed('tblse_conversion_outbox', []);
    $db->seed('tblstaff_permissions', []);
    $db->seed('tblroles', []);
}

/* Actor helpers ---------------------------------------------------------- */
function act_admin()        { se_test_act_as(1, [], true); }
function act_staffA()       { se_test_act_as(10, []); }
function act_staffB()       { se_test_act_as(20, []); }
function act_reporter()     { se_test_act_as(30, ['se_reports.view']); }
function act_configurator() { se_test_act_as(40, ['se_brands.view', 'se_brands.edit', 'se_brands.create', 'se_brands.delete']); }
function act_triager()      { se_test_act_as(50, ['se_tenancy.triage_unassigned']); }
function act_global()       { se_test_act_as(60, ['se_tenancy.all_brands']); }
function act_twobrand()     { se_test_act_as(61, []); }

se_test_seed_tenancy();

/* ======================================================================== */
se_group('Capability separation (the core defect)');

act_reporter();
se_eq(false, se_staff_sees_all_brands(), 'reporting capability does NOT grant all-brands access');
se_eq(true,  se_staff_can_report(),      'reporting capability grants report access');
se_eq(false, se_staff_can_configure_brands(), 'reporting capability does NOT grant brand configuration');
se_eq([1],   se_staff_brand_ids(),       'reporter is limited to their mapped brand');

act_configurator();
se_eq(false, se_staff_sees_all_brands(), 'brand-CONFIG capability does NOT grant all-brands data access');
se_eq(true,  se_staff_can_configure_brands(), 'brand-config capability grants configuration');
se_eq(false, se_staff_can_report(),      'brand-config capability does NOT grant reporting');
se_eq([1],   se_staff_brand_ids(),       'configurator is limited to their mapped brand');

act_staffA();
se_eq(false, se_staff_sees_all_brands(), 'plain staff never sees all brands');
foreach (['view', 'create', 'edit', 'delete'] as $cap) {
    se_test_act_as(10, ['se_brands.' . $cap, 'se_reports.' . $cap, 'se_patients.' . $cap]);
    se_eq(false, se_staff_sees_all_brands(), "ordinary '{$cap}' permission never implies cross-brand access");
}

act_global();
se_eq(true, se_staff_sees_all_brands(), 'explicit se_tenancy.all_brands DOES grant global access');

act_admin();
se_eq(true, se_staff_sees_all_brands(), 'admin sees all brands');

/* ======================================================================== */
se_group('Brand 0 (unassigned) is not globally visible');

act_staffA();
se_eq([1], se_staff_brand_ids(), 'staff without triage capability does NOT get brand 0');
se_eq(false, se_can_access_brand(0), 'staff without triage cannot reach unassigned records');
se_eq(false, se_can_access_record('lead', 303), 'unassigned lead is hidden from non-triage staff');

act_triager();
se_eq([1, 0], se_staff_brand_ids(), 'triage capability adds brand 0');
se_eq(true, se_can_access_brand(0), 'triager can reach the unassigned bucket');
se_eq(true, se_can_access_record('lead', 303), 'triager can reach the unassigned lead');

/* ======================================================================== */
se_group('Single-brand detection for new-lead stamping');

act_staffA();
se_eq([1], se_staff_real_brand_ids(), 'real brand set excludes the triage bucket');
se_eq(1, count(se_staff_real_brand_ids()), 'single-brand staff is detected as single-brand');

act_triager();
se_eq([1], se_staff_real_brand_ids(), 'triage capability does not inflate the REAL brand set');
se_eq(1, count(se_staff_real_brand_ids()), 'triager mapped to one brand is still single-brand for stamping');

act_twobrand();
se_eq(2, count(se_staff_real_brand_ids()), 'two-brand staff is not single-brand');

/* ======================================================================== */
se_group('Cross-brand record access — every record type');

$matrix = [
    ['lead', 101, 202],
    ['client', 501, 502],
    ['patient', 701, 702],
    ['appointment', 801, 802],
    ['wa_conversation', 901, 902],
    ['wa_message', 911, 912],
];

foreach ($matrix as [$type, $ownId, $foreignId]) {
    act_staffA();
    se_eq(true,  se_can_access_record($type, $ownId),     "staffA CAN reach own {$type}");
    se_eq(false, se_can_access_record($type, $foreignId), "staffA CANNOT reach Brand B {$type}");

    act_staffB();
    se_eq(true,  se_can_access_record($type, $foreignId), "staffB CAN reach own {$type}");
    se_eq(false, se_can_access_record($type, $ownId),     "staffB CANNOT reach Brand A {$type}");

    act_reporter();
    se_eq(false, se_can_access_record($type, $foreignId), "reporting-only staff CANNOT reach Brand B {$type}");

    act_admin();
    se_eq(true, se_can_access_record($type, $ownId) && se_can_access_record($type, $foreignId),
        "admin reaches both brands' {$type}");
}

/* ======================================================================== */
se_group('Request guard — direct IDs, POST, AJAX, bulk');

// Direct id in the URI: /admin/leads/lead/202
act_staffA();
se_test_set_uri(['admin', 'leads', 'lead', '202']);
se_denies('se_authz_request_guard', 'direct URI id to a foreign lead is denied');

se_test_set_uri(['admin', 'leads', 'lead', '101']);
se_allows('se_authz_request_guard', 'direct URI id to an own lead is allowed');

// Mutation routes reached by URI segment
foreach (['delete', 'mark_as_lost', 'unmark_as_lost', 'mark_as_junk', 'unmark_as_junk',
          'export', 'get_convert_data', 'add_note', 'download_files', 'index'] as $method) {
    se_test_set_uri(['admin', 'leads', $method, '202']);
    se_denies('se_authz_request_guard', "leads/{$method} on a foreign lead is denied");
}

// Two-segment routes: delete_attachment/<att>/<lead>
se_test_set_uri(['admin', 'leads', 'delete_attachment', '7', '202']);
se_denies('se_authz_request_guard', 'leads/delete_attachment with foreign lead at segment 5 is denied');

se_test_set_uri(['admin', 'leads', 'delete_note', '9', '202']);
se_denies('se_authz_request_guard', 'leads/delete_note with foreign lead at segment 5 is denied');

// Crafted POST bodies
se_test_reset(); act_staffA();
se_test_set_uri(['admin', 'leads', 'update_lead_status']);
se_test_set_post(['leadid' => 202, 'status' => 3]);
se_denies('se_authz_request_guard', 'forged POST leadid to a foreign lead is denied');

se_test_reset(); act_staffA();
se_test_set_uri(['admin', 'leads', 'convert_to_customer']);
se_test_set_post(['leadid' => 202]);
se_denies('se_authz_request_guard', 'convert_to_customer on a foreign lead is denied');

se_test_reset(); act_staffA();
se_test_set_uri(['admin', 'leads', 'add_activity']);
se_test_set_post(['id' => 202]);
se_denies('se_authz_request_guard', 'add_activity on a foreign lead is denied');

// AJAX requests take the same path
se_test_reset(); act_staffA();
$GLOBALS['se_test']['is_ajax'] = true;
se_test_set_uri(['admin', 'leads', 'lead', '202']);
se_denies('se_authz_request_guard', 'AJAX request to a foreign lead is denied');
$GLOBALS['se_test']['is_ajax'] = false;

// Bulk actions: any foreign id in the batch denies the whole request
se_test_reset(); act_staffA();
se_test_set_uri(['admin', 'leads', 'bulk_action']);
se_test_set_post(['ids' => [101, 202]]);
se_denies('se_authz_request_guard', 'bulk_action containing a foreign lead is denied');

se_test_reset(); act_staffA();
se_test_set_uri(['admin', 'leads', 'bulk_action']);
se_test_set_post(['ids' => [101]]);
se_allows('se_authz_request_guard', 'bulk_action with only own leads is allowed');

// Customers
se_test_reset(); act_staffA();
se_test_set_uri(['admin', 'clients', 'client', '502']);
se_denies('se_authz_request_guard', 'direct URI id to a foreign customer is denied');

se_test_set_uri(['admin', 'clients', '502']);
se_denies('se_authz_request_guard', 'numeric customer id at segment 3 is denied');

foreach (['delete', 'mark_as_active', 'consents', 'assign_admins', 'contacts',
          'login_as_client', 'upload_attachment', 'zip_invoices', 'change_client_status'] as $method) {
    se_test_set_uri(['admin', 'clients', $method, '502']);
    se_denies('se_authz_request_guard', "clients/{$method} on a foreign customer is denied");
}

se_test_reset(); act_staffA();
se_test_set_uri(['admin', 'clients', 'bulk_action']);
se_test_set_post(['ids' => [502]]);
se_denies('se_authz_request_guard', 'customer bulk_action with a foreign id is denied');

/* Non-record routes must NOT be falsely denied ---------------------------- */
se_test_reset(); act_staffA();
foreach (['statuses', 'status', 'delete_status', 'sources', 'source', 'delete_source',
          'forms', 'form', 'delete_form', 'import', 'switch_kanban', 'table', 'kanban'] as $method) {
    se_test_set_uri(['admin', 'leads', $method, '202']);
    se_allows('se_authz_request_guard', "leads/{$method} (non-record id 202) is not falsely denied");
}

// Unknown controllers are out of scope for this guard
se_test_reset(); act_staffA();
se_test_set_uri(['admin', 'invoices', 'list_invoices', '202']);
se_allows('se_authz_request_guard', 'unrelated controller is untouched');

// A global user is never blocked
se_test_reset(); act_global();
se_test_set_uri(['admin', 'leads', 'lead', '202']);
se_allows('se_authz_request_guard', 'all_brands user reaches every lead');

se_test_reset(); act_admin();
se_test_set_uri(['admin', 'leads', 'lead', '202']);
se_allows('se_authz_request_guard', 'admin reaches every lead');

/* Missing records are not tenancy decisions ------------------------------- */
se_test_reset(); act_staffA();
se_test_set_uri(['admin', 'leads', 'lead', '999999']);
se_allows('se_authz_request_guard', 'a non-existent id is not treated as a cross-tenant hit');

/* ======================================================================== */
se_group('Mutation boundary — SQL predicate + affected rows');

se_test_reset(); act_staffA();
$db = se_test_db();

$n = se_guarded_update('tblse_appointments', 'id', 802, ['status' => 'cancelled']);
se_eq(0, $n, 'guarded UPDATE of a foreign appointment affects 0 rows');
se_eq('scheduled', $db->rows('tblse_appointments')[1]['status'], 'foreign appointment row is unchanged');

$n = se_guarded_update('tblse_appointments', 'id', 801, ['status' => 'held']);
se_eq(1, $n, 'guarded UPDATE of an own appointment affects 1 row');
se_eq('held', $db->rows('tblse_appointments')[0]['status'], 'own appointment row is updated');

$n = se_guarded_delete('tblse_patients', 'id', 702);
se_eq(0, $n, 'guarded DELETE of a foreign patient affects 0 rows');
se_eq(2, count($db->rows('tblse_patients')), 'foreign patient row still exists');

$n = se_guarded_delete('tblse_patients', 'id', 701);
se_eq(1, $n, 'guarded DELETE of an own patient affects 1 row');

// A staff member with no brands at all may mutate nothing.
se_test_reset(); se_test_act_as(9999, []);
se_eq('1=0', se_brand_predicate(), 'staff with no mapped brands gets an impossible predicate');
$n = se_guarded_update('tblse_appointments', 'id', 801, ['status' => 'no_show']);
se_eq(0, $n, 'unmapped staff cannot update anything');

// Admin predicate is empty (unrestricted) by design.
se_test_reset(); act_admin();
se_eq('', se_brand_predicate(), 'admin gets an unrestricted predicate');

/* ======================================================================== */
se_group('Accessible-only brand pickers and report defaults');

se_test_reset(); act_staffA();
$brands = se_all_brands(true, true);
se_eq(1, count($brands), 'picker offers only the accessible brand');
se_eq('1', (string) $brands[0]['id'], 'picker offers Brand A to staffA');
se_eq(1, se_default_brand_id(), 'report default is the first ACCESSIBLE brand, not the first global brand');

se_test_reset(); act_staffB();
$brands = se_all_brands(true, true);
se_eq(1, count($brands), 'staffB picker offers only Brand B');
se_eq('2', (string) $brands[0]['id'], 'staffB picker offers Brand B');
se_eq(2, se_default_brand_id(), 'staffB report default is Brand B, not Brand A');

se_test_reset(); act_admin();
se_eq(2, count(se_all_brands(true, true)), 'admin picker offers every brand');

se_test_reset(); se_test_act_as(9999, []);
se_eq([], se_all_brands(true, true), 'unmapped staff is offered no brand at all');
