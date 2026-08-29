<?php
/**
 * Headless migration applier + verifier (CLI only).
 *
 *   php modules/se_core/tests/migrate_cli.php --dry-run
 *   php modules/se_core/tests/migrate_cli.php --apply
 *   php modules/se_core/tests/migrate_cli.php --verify
 *
 * Runs the SAME statement list the runtime path uses, so what is verified here
 * is what admin_init would apply. Reads the database connection from the
 * untracked config file and NEVER prints it, any row, or any option value.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// A CLI run whose working directory is the document root would otherwise drop
// a world-readable error_log there. Errors go to stderr only.
ini_set('log_errors', '0');
ini_set('display_errors', 'stderr');

$root = realpath(__DIR__ . '/../../../') . '/';

if (!is_file($root . 'application/config/app-config.php')) {
    fwrite(STDERR, "app-config.php not found; run this on the deployed host.\n");
    exit(2);
}

define('BASEPATH', $root . 'system/');
define('APPPATH', $root . 'application/');
define('FCPATH', $root);
define('ENVIRONMENT', 'production');

require $root . 'application/config/app-config.php';

function db_prefix() { return defined('APP_DB_PREFIX') ? APP_DB_PREFIX : 'tbl'; }

$db = []; $active_group = 'default'; $query_builder = true;
require $root . 'application/config/database.php';

$c  = $db['default'];
$my = @new mysqli($c['hostname'], $c['username'], $c['password'], $c['database']);

if ($my->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$my->set_charset('utf8mb4');

require_once __DIR__ . '/../migrations.php';

$mode  = $argv[1] ?? '--dry-run';
$stmts = se_core_migration_statements();

echo "schema target : v" . SE_CORE_SCHEMA_VERSION . "\n";
echo "statements    : " . count($stmts) . "\n";

$row     = $my->query("SELECT value v FROM `" . db_prefix() . "options` WHERE name='se_core_schema_version'")->fetch_assoc();
$current = (int) ($row['v'] ?? 0);
echo "current       : v{$current}\n";

if ($mode === '--dry-run') {
    foreach ($stmts as $i => $s) {
        printf("  %3d  %s\n", $i + 1, substr(preg_replace('/\s+/', ' ', $s), 0, 110));
    }
    exit(0);
}

if ($mode === '--apply') {
    $ok = 0; $failedAt = null; $err = '';

    foreach ($stmts as $i => $s) {
        if ($my->query($s) === false) {
            $failedAt = $i + 1;
            $err      = 'statement failed';
            break;
        }
        $ok++;
    }

    if ($failedAt !== null) {
        // Never echo the DB error text: it can carry schema/credential detail.
        echo "RESULT: FAILED at statement {$failedAt}/" . count($stmts) . " ({$err})\n";
        echo "schema version left at v{$current} so the next run retries from the top.\n";
        exit(1);
    }

    // tbloptions has no unique key on `name`, so ON DUPLICATE KEY cannot be
    // relied on: update first, insert only when nothing was updated.
    $v = SE_CORE_SCHEMA_VERSION;
    $o = db_prefix() . 'options';

    $my->query("UPDATE `{$o}` SET value='{$v}' WHERE name='se_core_schema_version'");

    if ($my->affected_rows === 0) {
        $exists = (int) $my->query("SELECT COUNT(*) c FROM `{$o}` WHERE name='se_core_schema_version'")->fetch_assoc()['c'];
        if ($exists === 0) {
            $my->query("INSERT INTO `{$o}` (name,value,autoload) VALUES ('se_core_schema_version','{$v}',1)");
        }
    }

    $my->query("UPDATE `{$o}` SET value='' WHERE name='se_core_schema_error'");

    echo "RESULT: applied {$ok}/" . count($stmts) . " statements; schema version -> v{$v}\n";
    exit(0);
}

if ($mode === '--verify') {
    $p       = db_prefix();
    $checks  = [
        ["{$p}se_conversion_outbox", ['attribution_snapshot', 'consent_snapshot', 'payload_version',
                                      'next_attempt_at', 'failure_class', 'error_code', 'fence',
                                      'request_id', 'submitted_at']],
        ["{$p}se_consent_ledger",    ['question_key', 'answer_raw', 'answer_normalized']],
        ["{$p}se_patients",          ['archived_at', 'archived_by']],
    ];

    $bad = 0;

    foreach ($checks as [$table, $cols]) {
        $have = [];
        $res  = $my->query("SHOW COLUMNS FROM `{$table}`");
        while ($x = $res->fetch_assoc()) { $have[] = $x['Field']; }

        foreach ($cols as $col) {
            $present = in_array($col, $have, true);
            printf("  %-28s %-22s %s\n", $table, $col, $present ? 'OK' : 'MISSING');
            if (!$present) { $bad++; }
        }
    }

    $row = $my->query("SELECT value v FROM `{$p}options` WHERE name='se_core_schema_version'")->fetch_assoc();
    echo "  schema version = v" . (int) ($row['v'] ?? 0) . "\n";

    exit($bad === 0 ? 0 : 1);
}

fwrite(STDERR, "unknown mode: {$mode}\n");
exit(2);
