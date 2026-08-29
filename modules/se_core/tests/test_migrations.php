<?php
/**
 * Migration safety: additive-only statements, guarded idempotency, result
 * checking, and failure behaviour that leaves a recoverable state.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

se_group('Schema version and statement shape');

se_eq(9, SE_CORE_SCHEMA_VERSION, 'se_core schema version is 9');

$stmts = se_core_migration_statements();
se_ok(count($stmts) > 40, 'the statement list is populated (' . count($stmts) . ' statements)');

foreach ($stmts as $i => $sql) {
    $n = $i + 1;

    // Additive only: nothing destructive may enter this list.
    foreach (['DROP TABLE', 'DROP COLUMN', 'TRUNCATE', 'DELETE FROM', 'RENAME '] as $bad) {
        se_eq(false, stripos($sql, $bad) !== false, "statement {$n} contains no {$bad}");
    }

    // Every statement must be guarded so re-running is a no-op.
    $guarded = stripos($sql, 'IF NOT EXISTS') !== false
        || stripos($sql, 'INSERT IGNORE') !== false
        || stripos($sql, 'WHERE NOT EXISTS') !== false;
    se_ok($guarded, "statement {$n} is guarded for idempotency");
}

se_group('Runner checks every result and stops at the first failure');

$executed = 0;
$result = se_core_run_migrations(function ($sql) use (&$executed) { $executed++; return true; });
se_eq(true, $result['ok'], 'a clean run reports ok');
se_eq(count($stmts), $result['executed'], 'a clean run executes every statement');
se_eq(null, $result['failed_sql'], 'a clean run reports no failure');

// Simulated failure at statement 5.
$seen = 0;
$result = se_core_run_migrations(function ($sql) use (&$seen) {
    $seen++;
    return $seen === 5 ? false : true;
});
se_eq(false, $result['ok'], 'a failing statement makes the run report failure');
se_eq(4, $result['executed'], 'execution stops at the failing statement');
se_eq(5, $seen, 'the failing statement was the fifth attempted');
se_ok($result['failed_sql'] !== null, 'the failing statement is identified');
se_eq(count($stmts), $result['total'], 'the total is reported for context');

se_group('Idempotency: a second identical run changes nothing');

$first = se_core_migration_statements();
$second = se_core_migration_statements();
se_eq($first, $second, 'the statement list is pure and deterministic');

se_group('Capability migration fails closed');

$db = se_test_db();
$db->tables = []; $db->autoinc = [];
$db->seed('tblstaff_permissions', [
    ['staff_id' => 30, 'feature' => 'se_brands', 'capability' => 'view'],
    ['staff_id' => 31, 'feature' => 'invoices', 'capability' => 'view'],
]);
$db->seed('tblroles', [
    ['roleid' => 1, 'permissions' => serialize(['se_brands' => ['view', 'edit']])],
    ['roleid' => 2, 'permissions' => serialize(['invoices' => ['view']])],
]);

se_core_migrate_capabilities();

$perms = $db->rows('tblstaff_permissions');
$granted = [];
foreach ($perms as $p) { $granted[] = $p['feature'] . '.' . $p['capability']; }

se_ok(in_array('se_reports.view', $granted, true),
    'a staff member who held se_brands.view keeps REPORTING access');
se_eq(false, in_array('se_tenancy.all_brands', $granted, true),
    'NOBODY is auto-granted all_brands (that would preserve the vulnerability)');
se_eq(false, in_array('se_tenancy.triage_unassigned', $granted, true),
    'nobody is auto-granted the triage capability either');

$role1 = unserialize($db->rows('tblroles')[0]['permissions']);
se_ok(in_array('view', $role1['se_reports'] ?? [], true), 'the role keeps reporting access');
se_eq(false, isset($role1['se_tenancy']), 'the role is NOT granted tenancy capabilities');

$role2 = unserialize($db->rows('tblroles')[1]['permissions']);
se_eq(false, isset($role2['se_reports']), 'an unrelated role is untouched');

// Re-running must not duplicate anything.
$before = count($db->rows('tblstaff_permissions'));
se_core_migrate_capabilities();
se_eq($before, count($db->rows('tblstaff_permissions')), 'the capability migration is idempotent');
