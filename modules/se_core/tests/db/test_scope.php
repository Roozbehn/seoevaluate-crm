<?php
/**
 * REAL MariaDB: brand-scope SQL must be VALID and must fail closed.
 *
 * The fake DB can only report what our own matcher does. Only the server can
 * tell us whether `IN ()` is genuinely a syntax error — which is what made the
 * empty-scope bug a 500 rather than a quiet mistake.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$p  = db_prefix();
$db = se_db();

// Fixtures in the reserved id range.
$db->query("INSERT IGNORE INTO {$p}se_brands (id,name,slug,active,date_created) VALUES
    (" . SE_TEST_BRAND_A . ",'ZZTEST Brand A','zztest-a',1,NOW()),
    (" . SE_TEST_BRAND_B . ",'ZZTEST Brand B','zztest-b',1,NOW())");

$db->query("INSERT IGNORE INTO {$p}se_staff_brands (staff_id,brand_id) VALUES
    (" . (SE_TEST_ID_BASE + 10) . "," . SE_TEST_BRAND_A . ")");

$db->query("INSERT IGNORE INTO {$p}se_patients (id,brand_id,client_id,lead_id,retention_state,date_created) VALUES
    (" . (SE_TEST_ID_BASE + 1) . "," . SE_TEST_BRAND_A . ",0,0,'active',NOW()),
    (" . (SE_TEST_ID_BASE + 2) . "," . SE_TEST_BRAND_B . ",0,0,'active',NOW()),
    (" . (SE_TEST_ID_BASE + 3) . ",0,0,0,'active',NOW())");

se_group('Empty scope produces VALID SQL that MariaDB accepts and that denies');

se_test_act_as(SE_TEST_ID_BASE + 999, []);   // mapped to nothing

$pred = se_scope_in_sql('brand_id');
se_eq('1=0', $pred, 'unmapped staff get 1=0');

// Execute it. The old `IN ()` raised a real syntax error here.
$threw = false;
try {
    $n = (int) $db->query("SELECT COUNT(*) c FROM {$p}se_patients WHERE {$pred}")->row()->c;
} catch (SeSqlError $e) { $threw = true; $n = -1; }

se_eq(false, $threw, 'the empty-scope predicate is accepted by MariaDB (old IN () was a syntax error)');
se_eq(0, $n, 'and it matches zero rows');

// Prove the old form really is a syntax error, so the regression is unmistakable.
$threwOld = false;
try { $db->query("SELECT COUNT(*) c FROM {$p}se_patients WHERE brand_id IN ()"); }
catch (SeSqlError $e) { $threwOld = true; }
se_eq(true, $threwOld, 'MariaDB genuinely rejects `IN ()` — confirming the old code 500ed');

// The join form must also execute.
$join = se_scope_join_sql($p . 'se_patients');
$threw = false;
try {
    $n = (int) $db->query("SELECT COUNT(*) c FROM {$p}se_patients {$join}")->row()->c;
} catch (SeSqlError $e) { $threw = true; $n = -1; }
se_eq(false, $threw, 'the empty-scope JOIN is accepted by MariaDB');
se_eq(0, $n, 'and it matches zero rows');

se_group('Mapped staff see only their brand, through real SQL');

se_test_act_as(SE_TEST_ID_BASE + 10, []);
$pred = se_scope_in_sql('brand_id');
se_eq('brand_id IN (' . SE_TEST_BRAND_A . ')', $pred, 'predicate names only the mapped brand');

$rows = $db->query("SELECT id FROM {$p}se_patients WHERE {$pred} AND id >= " . SE_TEST_ID_BASE)->result_array();
se_eq(1, count($rows), 'exactly one fixture patient is visible');
se_eq(SE_TEST_ID_BASE + 1, (int) $rows[0]['id'], 'and it is the Brand A one');

// Brand 0 must NOT leak without the triage capability.
$hasZero = false;
foreach ($rows as $r) { if ((int) $r['id'] === SE_TEST_ID_BASE + 3) { $hasZero = true; } }
se_eq(false, $hasZero, 'the brand-0 patient is not visible without triage');

se_group('Triage and all-brands, through real SQL');

se_test_act_as(SE_TEST_ID_BASE + 10, ['se_tenancy.triage_unassigned']);
$rows = $db->query("SELECT id FROM {$p}se_patients WHERE " . se_scope_in_sql('brand_id')
    . ' AND id >= ' . SE_TEST_ID_BASE)->result_array();
se_eq(2, count($rows), 'triage adds the unassigned patient');

se_test_act_as(SE_TEST_ID_BASE + 10, ['se_tenancy.all_brands']);
se_eq('', se_scope_in_sql('brand_id'), 'all-brands adds no restriction');

se_group('Guarded mutations against real rows');

se_test_act_as(SE_TEST_ID_BASE + 10, []);   // Brand A only

$n = se_guarded_update($p . 'se_patients', 'id', SE_TEST_ID_BASE + 2, ['nationality' => 'XX']);
se_eq(0, $n, "cannot update Brand B's patient");

$check = $db->query("SELECT nationality FROM {$p}se_patients WHERE id=" . (SE_TEST_ID_BASE + 2))->row();
se_eq(null, $check->nationality, "Brand B's row is untouched in the database");

$n = se_guarded_update($p . 'se_patients', 'id', SE_TEST_ID_BASE + 1, ['nationality' => 'TR']);
se_eq(1, $n, 'can update own patient');
$check = $db->query("SELECT nationality FROM {$p}se_patients WHERE id=" . (SE_TEST_ID_BASE + 1))->row();
se_eq('TR', $check->nationality, 'own row is updated in the database');

$n = se_guarded_delete($p . 'se_patients', 'id', SE_TEST_ID_BASE + 2);
se_eq(0, $n, "cannot delete Brand B's patient");
se_eq(1, (int) $db->query("SELECT COUNT(*) c FROM {$p}se_patients WHERE id=" . (SE_TEST_ID_BASE + 2))->row()->c,
    "Brand B's row still exists");

// An unmapped staff member may mutate nothing at all.
se_test_act_as(SE_TEST_ID_BASE + 999, []);
$n = se_guarded_update($p . 'se_patients', 'id', SE_TEST_ID_BASE + 1, ['nationality' => 'ZZ']);
se_eq(0, $n, 'unmapped staff can update nothing');
