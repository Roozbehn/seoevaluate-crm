<?php
/**
 * Secret-store diagnostic (CLI only).
 *
 *   php modules/se_core/tests/secret_diag.php
 *
 * Verifies the integration secret store WITHOUT ever printing a value, a hash,
 * a length, a prefix or a suffix. Prints booleans, a mode string, an owner, and
 * timestamps only. Safe to run on the live host and safe to paste into a ticket.
 *
 * Exit code 0 = store healthy (dir + required server tokens present, modes ok).
 *           1 = one or more checks failed.
 *           2 = environment/bootstrap error.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

ini_set('log_errors', '0');
ini_set('display_errors', 'stderr');

$root = realpath(__DIR__ . '/../../../') . '/';

if (!is_file($root . 'application/config/app-config.php')) {
    fwrite(STDERR, "app-config.php not found; run this on the deployed host.\n");
    exit(2);
}

define('BASEPATH', $root . 'system/');
require $root . 'application/config/app-config.php';

$dir = defined('SE_SECRET_DIR') ? SE_SECRET_DIR : '/home/hyundaic/_secrets';

/* We must not require the whole CI framework here, so mirror the provider's
 * filename rules locally. Keep this list in sync with se_secret_providers(). */
$providers = [
    // key            per_brand
    'meta_capi'     => true,
    'meta_page'     => true,
    'meta_app'      => false,
    'meta_verify'   => false,
    'wa_app'        => false,
    'wa_verify'     => false,
    'google_sa'     => true,
    'landing_token' => false,
    'website_lead'  => true,
];

/* Server-generated tokens that this system installs itself and therefore must
 * always be present on a healthy store. Owner-provided Meta/Google credentials
 * are reported but do not fail the check (they are installed later). */
$required = ['meta_verify', 'wa_verify', 'landing_token'];

$fail = 0;
echo "== SE secret store diagnostic ==\n";
echo "configured_path : " . (defined('SE_SECRET_DIR') ? 'yes' : 'no (using default)') . "\n";
echo "dir             : " . $dir . "\n";

$exists = is_dir($dir);
echo "dir_exists      : " . ($exists ? 'yes' : 'no') . "\n";
if (!$exists) { echo "RESULT          : FAIL (no directory)\n"; exit(1); }

$mode  = substr(sprintf('%o', fileperms($dir)), -3);
$modeOk = $mode === '700';
echo "dir_mode        : {$mode}" . ($modeOk ? ' (ok)' : ' (EXPECT 700)') . "\n";
if (!$modeOk) { $fail = 1; }

$owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($dir))['name'] ?? fileowner($dir)) : fileowner($dir);
echo "dir_owner       : {$owner}\n";

$real = realpath($dir) ?: $dir;
$docroot = rtrim($root, '/');
$outside = strpos($real, $docroot) !== 0;
echo "outside_docroot : " . ($outside ? 'yes' : 'NO — INSIDE DOCROOT') . "\n";
if (!$outside) { $fail = 1; }

echo "-- providers (existence/readability/mode only; never a value) --\n";
foreach ($providers as $key => $perBrand) {
    // Check the global file and any per-brand suffixes present.
    $candidates = [$key];
    if ($perBrand) {
        foreach (glob($dir . '/' . $key . '_*') ?: [] as $p) {
            $candidates[] = basename($p);
        }
    }
    foreach (array_unique($candidates) as $name) {
        $path = $dir . '/' . $name;
        $ex   = is_file($path);
        $rd   = $ex && is_readable($path);
        $fm   = $ex ? substr(sprintf('%o', fileperms($path)), -3) : '---';
        $fmOk = $fm === '600';
        $isRequired = in_array($key, $required, true);
        $line = sprintf("  %-16s exists=%s readable=%s mode=%s%s",
            $name, $ex ? 'y' : 'n', $rd ? 'y' : 'n', $fm, $fmOk || !$ex ? '' : ' (EXPECT 600)');
        if ($isRequired && (!$ex || !$rd || !$fmOk)) { $line .= '  <-- REQUIRED, FAILING'; $fail = 1; }
        echo $line . "\n";
    }
}

echo "RESULT          : " . ($fail ? 'FAIL' : 'OK') . "\n";
exit($fail);
