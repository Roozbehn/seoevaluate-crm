<?php
/**
 * SE module test runner — network-free, database-free, credential-free.
 *
 *   php modules/se_core/tests/run.php            # every suite
 *   php modules/se_core/tests/run.php tenancy    # one suite
 *
 * Exit code 0 = all pass, 1 = at least one failure.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/bootstrap.php';

$only  = $argv[1] ?? null;
$files = glob(__DIR__ . '/test_*.php');
sort($files);

$ran = [];

foreach ($files as $file) {
    $name = preg_replace('/^test_|\.php$/', '', basename($file));

    if ($only !== null && $name !== $only) {
        continue;
    }

    echo "\n================ suite: {$name} ================\n";
    se_test_reset();
    require $file;
    $ran[] = $name;
}

if (function_exists('se_test_purge_secrets')) { se_test_purge_secrets(); }

$a = $GLOBALS['se_assert'];

echo "\n============================================\n";
echo "suites : " . implode(', ', $ran) . "\n";
echo "PASS   : {$a['pass']}\n";
echo "FAIL   : {$a['fail']}\n";

/* Schema oracle (K1 / QA-001): production writes to columns the real schema does not have. */
if (!empty(SeFakeDb::$schemaViolations)) {
    echo "\nSCHEMA ORACLE: " . count(SeFakeDb::$schemaViolations) . " distinct violation(s)\n";
    foreach (SeFakeDb::$schemaViolations as $v => $n) { echo "  - {$v} (x{$n})\n"; }
    // Strict by default (K1 / QA-001): a phantom column fails the run. SE_SCHEMA_STRICT=0 to report only.
    if (getenv('SE_SCHEMA_STRICT') !== '0') { $a['fail'] += count(SeFakeDb::$schemaViolations); foreach (array_keys(SeFakeDb::$schemaViolations) as $v) { $a['failures'][] = 'schema oracle :: ' . $v; } }
}

if ($a['fail'] > 0) {
    echo "\nFailures:\n";
    foreach ($a['failures'] as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "ALL TESTS PASSED\n";
exit(0);
