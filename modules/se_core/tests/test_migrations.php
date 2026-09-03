<?php
/**
 * Migration safety: additive-only statements, guarded idempotency, result
 * checking, and failure behaviour that leaves a recoverable state.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

se_group('Schema version and statement shape');

se_eq(22, SE_CORE_SCHEMA_VERSION, 'se_core schema version is 22 (v19 = web push subscriptions, v20 = WhatsApp call log, v21 = hot-path indexes, v22 = reminder outbound back-link)');
se_eq(2, count(array_filter(se_core_migration_statements(), function ($q) { return stripos($q, 'se_reminders`') !== false && stripos($q, 'IF NOT EXISTS') !== false && stripos($q, 'outbound_id') !== false; })), 'v22: reminder outbound_id column + index, both guarded');
se_eq(15, count(array_filter(se_core_migration_statements(), function ($q) { return stripos($q, 'ADD INDEX IF NOT EXISTS') !== false && (stripos($q, 'se_journeys`') !== false || stripos($q, 'se_wa_') !== false || stripos($q, 'se_appointments`') !== false || stripos($q, 'se_journey_tasks`') !== false || stripos($q, 'se_journey_quotes`') !== false); })) >= 15 ? 15 : 0, 'v21 adds the 15 hot-path indexes, all guarded');

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
        || stripos($sql, 'WHERE NOT EXISTS') !== false
        // Relaxing a unique index to a plain one (v15) is the one permitted
        // DROP: an INDEX, never data, and guarded so a re-run is a no-op.
        || preg_match('/DROP INDEX IF EXISTS/i', $sql) === 1;
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

/* ======================================================================== */
se_group('Schema oracle (audit K1 / AZCRM-QA-001 / CRM-M069): the fake DB knows the real columns');
$schema = se_schema_oracle_build();
se_ok(count($schema) >= 40, 'the oracle covers the SE tables (' . count($schema) . ')');
foreach (['tblse_journeys', 'tblse_wa_conversations', 'tblse_wa_outbound', 'tblse_reminders', 'tblse_conversion_outbox', 'tblse_appointments', 'tblse_ig_conversations'] as $t) {
    se_ok(isset($schema[$t]['id']) && isset($schema[$t]['brand_id']), "{$t}: id + brand_id known");
}
se_eq(true, isset($schema['tblse_reminders']['outbound_id']), 'v22 column is known (ALTER … ADD COLUMN parsed)');
se_eq(true, isset($schema['tblse_reminders']['attempts']), 'columns after an inline SQL comment are parsed');
se_eq(false, isset($schema['tblse_wa_conversations']['waba_id']), 'the J12 phantom: se_wa_conversations has NO waba_id column — the oracle knows');
se_eq(false, isset($schema['tblleads']), 'Perfex core tables (known only through ALTERs) are not checked');
se_eq(['nope'], se_schema_oracle_unknown_columns('tblse_journeys', ['id' => 1, 'state' => 'x', 'nope' => 1]), 'an unknown column is named');
se_eq([], se_schema_oracle_unknown_columns('tblunknown_table', ['whatever' => 1]), 'unknown tables are not checked');
// The fake DB records a violation on a production-style write and, in strict mode, throws.
$before = SeFakeDb::$schemaViolations;
$db = se_test_db(); $db->seed('tblse_journey_tasks', []);
$db->insert('tblse_journey_tasks', ['journey_id' => 1, 'ghost_column' => 1]);
$hit = array_filter(array_keys(SeFakeDb::$schemaViolations), function ($k) { return strpos($k, 'ghost_column') !== false; });
se_eq(1, count($hit), 'a write with a phantom column is recorded');
SeFakeDb::$schemaViolations = $before;   // this one was deliberate
SeFakeDb::$schemaStrict = true;
$threw = false; try { $db->update('tblse_journey_tasks', ['ghost2' => 1]); } catch (\RuntimeException $e) { $threw = strpos($e->getMessage(), 'ghost2') !== false; }
SeFakeDb::$schemaStrict = false; SeFakeDb::$schemaViolations = $before;
se_eq(true, $threw, 'strict mode throws on the write');
