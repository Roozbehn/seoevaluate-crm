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

$a = $GLOBALS['se_assert'];

echo "\n============================================\n";
echo "suites : " . implode(', ', $ran) . "\n";
echo "PASS   : {$a['pass']}\n";
echo "FAIL   : {$a['fail']}\n";

if ($a['fail'] > 0) {
    echo "\nFailures:\n";
    foreach ($a['failures'] as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "ALL TESTS PASSED\n";
exit(0);
