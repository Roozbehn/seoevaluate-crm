<?php
/**
 * Empty-scope safety.
 *
 * A staff member mapped to NO brand (and without triage or all-brands) must be
 * denied — never receive a widened scope, and never produce invalid SQL.
 *
 * Every assertion here fails against the previous implementation: five call
 * sites built `IN (implode(...))` inline, which becomes `IN ()` for an empty
 * array, and the patient scope substituted `[0]` — the triage bucket — which
 * WIDENED access for exactly the user who should see nothing.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function se_test_seed_scope()
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];
    $db->seed('tblse_brands', [
        ['id' => 1, 'name' => 'Brand A', 'active' => 1],
        ['id' => 2, 'name' => 'Brand B', 'active' => 1],
    ]);
    $db->seed('tblse_staff_brands', [
        ['staff_id' => 10, 'brand_id' => 1],
        ['staff_id' => 61, 'brand_id' => 1],
        ['staff_id' => 61, 'brand_id' => 2],
    ]);
    $db->seed('tblse_patients', [
        ['id' => 701, 'brand_id' => 1, 'lead_id' => 0, 'client_id' => 0, 'retention_state' => 'active'],
        ['id' => 702, 'brand_id' => 2, 'lead_id' => 0, 'client_id' => 0, 'retention_state' => 'active'],
        ['id' => 703, 'brand_id' => 0, 'lead_id' => 0, 'client_id' => 0, 'retention_state' => 'active'],
    ]);
}

se_test_seed_scope();

/* ======================================================================== */
se_group('Unmapped ordinary staff: deny, do not widen, do not error');

se_test_act_as(9999, []);   // mapped to nothing, no capabilities

se_eq([], se_staff_brand_ids(), 'an unmapped staff member reaches no brand');
se_eq(false, se_staff_has_any_brand(), 'and has no brand at all');

$sql = se_scope_in_sql('brand_id');
se_eq('1=0', $sql, 'the scope predicate FAILS CLOSED (was "IN ()" — a SQL syntax error)');
se_eq(false, strpos($sql, 'IN ()') !== false, 'no empty IN () is ever generated');
se_eq(false, $sql === '', 'the predicate is NOT omitted (omitting it would expose everything)');

$join = se_scope_join_sql(db_prefix() . 'leads');
se_eq(false, strpos($join, 'INNER JOIN ()') !== false, 'no empty INNER JOIN () is generated');
se_ok(strpos($join, '1=0') !== false, 'the join is deliberately unsatisfiable');

se_eq(false, se_can_access_brand(0), 'brand 0 is NOT reachable without the triage capability');
se_eq(false, se_can_access_brand(1), 'no real brand is reachable');
se_eq(false, se_apply_scope_in('brand_id'), 'applying the scope reports "nothing visible"');

/* The patient list must return nothing, not the triage bucket. */
se_test_seed_scope();
se_test_act_as(9999, []);
$db = se_test_db();
se_patient_apply_scope($db);
$rows = $db->get('tblse_patients')->result_array();
se_eq(0, count($rows), 'an unmapped staff member sees ZERO patients (was: brand-0 patients)');

/* ======================================================================== */
se_group('Mapped staff still work');

se_test_seed_scope();
se_test_act_as(10, []);   // Brand A only
se_eq('brand_id IN (1)', se_scope_in_sql('brand_id'), 'a single-brand predicate is correct');
se_eq(true, se_staff_has_any_brand(), 'a mapped staff member has a brand');
se_eq(true, se_apply_scope_in('brand_id'), 'applying the scope reports "something visible"');

$db = se_test_db();
se_patient_apply_scope($db);
$rows = $db->get('tblse_patients')->result_array();
se_eq(1, count($rows), 'Brand A staff sees exactly their own patient');
se_eq(701, (int) $rows[0]['id'], 'and it is the right one');
se_eq(false, in_array(703, array_column($rows, 'id')), 'brand-0 patients are NOT included without triage');

se_test_seed_scope();
se_test_act_as(61, []);   // two brands
se_eq('brand_id IN (1,2)', se_scope_in_sql('brand_id'), 'a two-brand predicate lists both');

/* ======================================================================== */
se_group('Triage and global staff');

se_test_seed_scope();
se_test_act_as(50, ['se_tenancy.triage_unassigned']);
se_eq('brand_id IN (0)', se_scope_in_sql('brand_id'), 'a triage-only staff member reaches brand 0');
$db = se_test_db();
se_patient_apply_scope($db);
se_eq(1, count($db->get('tblse_patients')->result_array()), 'triage staff see only the unassigned patient');

se_test_seed_scope();
se_test_act_as(60, ['se_tenancy.all_brands']);
se_eq('', se_scope_in_sql('brand_id'), 'an all-brands user gets no restriction');
se_eq('', se_scope_join_sql(db_prefix() . 'leads'), 'and no join');
$db = se_test_db();
se_patient_apply_scope($db);
se_eq(3, count($db->get('tblse_patients')->result_array()), 'an all-brands user sees every patient');

se_test_seed_scope();
se_test_act_as(1, [], true);
se_eq('', se_scope_in_sql('brand_id'), 'an admin gets no restriction');

/* ======================================================================== */
se_group('Every generated predicate is syntactically valid');

foreach ([[9999, [], false], [10, [], false], [61, [], false],
          [50, ['se_tenancy.triage_unassigned'], false], [60, ['se_tenancy.all_brands'], false],
          [1, [], true]] as [$id, $caps, $admin]) {
    se_test_seed_scope();
    se_test_act_as($id, $caps, $admin);

    foreach (['brand_id', db_prefix() . 'leads.brand_id', 'x.brand_id'] as $col) {
        $p = se_scope_in_sql($col);
        $ok = $p === '' || $p === '1=0' || preg_match('/^[A-Za-z0-9_.]+ IN \(\d+(,\d+)*\)$/', $p);
        se_ok($ok, "staff {$id}: predicate for {$col} is valid SQL");
    }

    $j = se_scope_join_sql(db_prefix() . 'leads');
    $okj = $j === '' || (strpos($j, '()') === false && strpos($j, 'UNION )') === false);
    se_ok($okj, "staff {$id}: join SQL is valid");
}
