<?php
/**
 * Schema oracle for the fake DB (audit K1 / AZCRM-QA-001 / CRM-M069).
 *
 * The fake DB is schema-less: a production write to a column that does not
 * exist (the `waba_id` phantom, audit J12) passes every test and fails only
 * on the host. This oracle builds the real column list per table from the
 * SAME statement sources the deploy uses — se_core_migration_statements()
 * (which folds in the Instagram, media and journey schemas), the
 * appointments migration list and the WhatsApp/core install scripts — and
 * lets the fake DB check every insert()/update() from PRODUCTION code
 * against it. Seeds are exempt (fixtures may carry extra keys).
 *
 * Statement parsing is deliberately simple: CREATE TABLE column lines and
 * ALTER TABLE … ADD COLUMN. Tables it has never seen (Perfex core tables such
 * as tblleads) are not checked.
 */

function se_schema_oracle_statements()
{
    $stmts = [];
    if (function_exists('se_core_migration_statements')) {
        $stmts = array_merge($stmts, se_core_migration_statements());
    }
    if (function_exists('se_appt_migration_statements')) {
        $stmts = array_merge($stmts, se_appt_migration_statements());
    }
    // Install scripts run their statements in file scope; read them without executing.
    foreach (glob(dirname(__DIR__, 2) . '/se_*/install.php') as $f) {
        $stmts = array_merge($stmts, se_schema_oracle_extract_sql_strings((string) file_get_contents($f)));
    }

    return $stmts;
}

/** Pull "CREATE TABLE …" / "ALTER TABLE …" double-quoted strings out of PHP source, with {$p} resolved. */
function se_schema_oracle_extract_sql_strings($php)
{
    $out = [];
    // "CREATE TABLE IF NOT EXISTS `{$p}x` (…)"  and  'CREATE TABLE `' . db_prefix() . "x` (…)"
    $php = preg_replace('/\'CREATE TABLE `\'\s*\.\s*db_prefix\(\)\s*\.\s*"/', '"CREATE TABLE `{$p}', $php);
    if (preg_match_all('/"((?:CREATE TABLE|ALTER TABLE)[^"]*)"/s', $php, $m)) {
        foreach ($m[1] as $sql) {
            $out[] = str_replace(['{$p}', '{$cs}'], [db_prefix(), 'utf8mb4'], $sql);
        }
    }

    return $out;
}

/** @return array<string, array<string,true>>  table => [column => true] */
function se_schema_oracle_build(?array $stmts = null)
{
    static $cache = null;
    if ($stmts === null && $cache !== null) {
        return $cache;
    }
    $stmts = $stmts ?? se_schema_oracle_statements();
    $schema = []; $created = [];
    foreach ($stmts as $sql) {
        if (preg_match('/CREATE TABLE(?: IF NOT EXISTS)?\s*`([^`]+)`\s*\((.*)\)\s*(?:ENGINE|DEFAULT CHARSET|CHARACTER SET|COLLATE|$)/is', $sql, $m)) {
            $table = $m[1];
            $created[$table] = true;
            foreach (preg_split('/,\s*\n/', preg_replace('#/\*.*?\*/#s', '', $m[2])) as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FULLTEXT)/i', $line)) { continue; }
                if (preg_match('/^`([^`]+)`/', $line, $c)) { $schema[$table][$c[1]] = true; }
            }
        } elseif (preg_match('/ALTER TABLE\s*`([^`]+)`\s*(.*)$/is', $sql, $m)) {
            $table = $m[1];
            if (preg_match_all('/ADD COLUMN(?: IF NOT EXISTS)?\s*`([^`]+)`/i', $m[2], $cols)) {
                foreach ($cols[1] as $c) { $schema[$table][$c] = true; }
            }
        }
    }
    // A table known only through ALTER … ADD COLUMN (Perfex core tables such as
    // tblleads) has no complete column list here → not checkable, drop it.
    foreach (array_keys($schema) as $t) { if (empty($created[$t])) { unset($schema[$t]); } }
    if (func_num_args() === 0) { $cache = $schema; }

    return $schema;
}

/** Columns in $data that the real schema does not have (empty = fine / table unknown). */
function se_schema_oracle_unknown_columns($table, array $data)
{
    $schema = se_schema_oracle_build();
    if (!isset($schema[$table])) {
        return [];
    }
    $bad = [];
    foreach (array_keys($data) as $col) {
        if (!isset($schema[$table][$col])) { $bad[] = $col; }
    }

    return $bad;
}
