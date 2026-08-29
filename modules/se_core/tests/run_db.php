<?php
/**
 * REAL MariaDB test runner.
 *
 *   php modules/se_core/tests/run_db.php            # every db suite
 *   php modules/se_core/tests/run_db.php appointments
 *
 * Runs the REAL model and helper code against the deployed database inside a
 * transaction that is ALWAYS rolled back. Reads the connection from the
 * untracked config file and never prints it.
 *
 * A network-kill fixture is installed: any attempt to open an outbound socket
 * during a test aborts the run, so a test can never quietly contact Meta,
 * WhatsApp or Google.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

ini_set('log_errors', '0');
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);
date_default_timezone_set('UTC');

$root = realpath(__DIR__ . '/../../../') . '/';

if (!is_file($root . 'application/config/app-config.php')) {
    fwrite(STDERR, "app-config.php not found; run this on the deployed host.\n");
    exit(2);
}

define('BASEPATH', $root . 'system/');
define('APPPATH', $root . 'application/');
define('FCPATH', $root);
define('ENVIRONMENT', 'production');
define('SE_TESTING', true);
define('SE_TESTING_REAL_DB', true);

/* Reserved fixture id range — far above anything production will reach. */
define('SE_TEST_ID_BASE', 900000);
define('SE_TEST_BRAND_A', 900001);
define('SE_TEST_BRAND_B', 900002);

require $root . 'application/config/app-config.php';

function db_prefix() { return defined('APP_DB_PREFIX') ? APP_DB_PREFIX : 'tbl'; }

$dbcfg = []; $active_group = 'default'; $query_builder = true;
require $root . 'application/config/database.php';

$c    = $dbcfg['default'] ?? null;
$conn = null;

if ($c === null) {
    // CI's config file assigns to $db; re-read under that name.
    $db = []; require $root . 'application/config/database.php';
    $c = $db['default'];
}

$conn = @new mysqli($c['hostname'], $c['username'], $c['password'], $c['database']);

if ($conn->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$conn->set_charset('utf8mb4');

require_once __DIR__ . '/real_db.php';
require_once __DIR__ . '/net_kill.php';

/* ---------------------------------------------------------------------------
 * Minimal CI/Perfex surface, backed by the REAL connection.
 * ------------------------------------------------------------------------- */

$GLOBALS['se_test'] = [
    'staff_id' => 0, 'is_admin' => false, 'permissions' => [],
    'options' => [], 'activity' => [], 'denied' => null,
];

class SeRealLoader { public function helper($x) {} public function model($x) {} public function library($x) {} public function view($x, $y = null) {} }

class SeRealCI
{
    public $db; public $load;
    public function __construct($db) { $this->db = $db; $this->load = new SeRealLoader(); }
}

$GLOBALS['se_real_db'] = new SeRealDb($conn);
$GLOBALS['se_test_ci'] = new SeRealCI($GLOBALS['se_real_db']);

function &get_instance() { return $GLOBALS['se_test_ci']; }
function se_db() { return $GLOBALS['se_real_db']; }

function get_staff_user_id() { return $GLOBALS['se_test']['staff_id']; }
function is_staff_logged_in() { return $GLOBALS['se_test']['staff_id'] > 0; }
function is_admin($staff_id = '') { return $GLOBALS['se_test']['is_admin']; }

function staff_can($cap, $feature = null, $staff_id = '')
{
    if ($GLOBALS['se_test']['is_admin']) { return true; }
    return !empty($GLOBALS['se_test']['permissions'][$feature . '.' . $cap]);
}
function staff_cant($cap, $feature = null, $staff_id = '') { return !staff_can($cap, $feature, $staff_id); }

function get_option($name) { return $GLOBALS['se_test']['options'][$name] ?? ''; }
function update_option($name, $value) { $GLOBALS['se_test']['options'][$name] = $value; return true; }
function add_option($n, $v) { return update_option($n, $v); }

function _l($k, $x = '') { return $k; }
function log_activity($m) { $GLOBALS['se_test']['activity'][] = $m; }
function is_ajax_request() { return false; }
function slug_it($s) { return strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s)); }
function to_sql_date($d, $t = false) { return $d; }
function _dt($d) { return $d; }
function html_escape($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

class SeAccessDenied extends RuntimeException {}
function access_denied($w = '') { throw new SeAccessDenied($w ?: 'denied'); }
function ajax_access_denied($w = '') { throw new SeAccessDenied($w ?: 'denied'); }
function se_core_deny() { access_denied('se_brands'); }

class SeRealHooks
{
    public function add_action($t, $f, $p = 10, $a = 1) {}
    public function add_filter($t, $f, $p = 10, $a = 1) {}
    public function do_action($t, $a = null) {}
    public function apply_filters($t, $v) { return $v; }
}
function hooks() { static $h = null; if ($h === null) { $h = new SeRealHooks(); } return $h; }
function register_staff_capabilities($f, $c, $n = null) {}
function register_language_files($m, $f) {}
function register_activation_hook($m, $f) {}
function admin_url($p = '') { return '/admin/' . $p; }
function site_url($p = '') { return '/' . $p; }

/** Perfex App_Model stand-in: the real model classes extend this. */
class App_Model { public $db; public function __construct() { $this->db = $GLOBALS['se_real_db']; } }

/* ---------------------------------------------------------------------------
 * Load the REAL code under test.
 * ------------------------------------------------------------------------- */

$MOD = $root . 'modules/';

require_once $MOD . 'se_core/helpers/se_core_helper.php';
require_once $MOD . 'se_core/se_authz.php';
require_once $MOD . 'se_core/migrations.php';
require_once $MOD . 'se_core/pipeline.php';
require_once $MOD . 'se_core/se_consent.php';
require_once $MOD . 'se_core/se_consent_settings.php';
require_once $MOD . 'se_core/se_patients.php';
require_once $MOD . 'se_core/libraries/Se_hash.php';
require_once $MOD . 'se_core/se_outbox_snapshot.php';
require_once $MOD . 'se_core/se_outbox.php';
require_once $MOD . 'se_core/se_capi.php';
require_once $MOD . 'se_core/se_google_dm.php';
require_once $MOD . 'se_core/se_meta_leadgen.php';
require_once $MOD . 'se_appointments/availability.php';
require_once $MOD . 'se_appointments/reminders.php';
require_once $MOD . 'se_appointments/gcal.php';
require_once $MOD . 'se_appointments/models/Se_appointments_model.php';
require_once $MOD . 'se_whatsapp/helpers.php';
require_once $MOD . 'se_whatsapp/models/Se_whatsapp_model.php';

/* ---------------------------------------------------------------------------
 * Assertions + actor helpers.
 * ------------------------------------------------------------------------- */

$GLOBALS['se_assert'] = ['pass' => 0, 'fail' => 0, 'failures' => [], 'group' => ''];

function se_group($n) { $GLOBALS['se_assert']['group'] = $n; echo "\n-- {$n}\n"; }

function se_ok($cond, $label)
{
    if ($cond) { $GLOBALS['se_assert']['pass']++; echo "   PASS  {$label}\n"; }
    else {
        $GLOBALS['se_assert']['fail']++;
        $GLOBALS['se_assert']['failures'][] = $GLOBALS['se_assert']['group'] . ' :: ' . $label;
        echo "   FAIL  {$label}\n";
    }
}

function se_eq($e, $a, $label)
{
    $ok = $e === $a;
    if (!$ok) { $label .= ' (expected ' . var_export($e, true) . ', got ' . var_export($a, true) . ')'; }
    se_ok($ok, $label);
}

function se_test_act_as($id, array $caps = [], $admin = false)
{
    $GLOBALS['se_test']['staff_id'] = (int) $id;
    $GLOBALS['se_test']['is_admin'] = (bool) $admin;
    $GLOBALS['se_test']['permissions'] = array_fill_keys($caps, true);
    se_authz_reset_cache();
}

/** Open a SECOND connection, for genuine cross-connection concurrency tests. */
function se_test_second_connection()
{
    global $c;
    $conn2 = @new mysqli($c['hostname'], $c['username'], $c['password'], $c['database']);
    if ($conn2->connect_errno) { return null; }
    $conn2->set_charset('utf8mb4');

    return new SeRealDb($conn2);
}

/* ---------------------------------------------------------------------------
 * Run inside a transaction that is ALWAYS rolled back.
 * ------------------------------------------------------------------------- */

$only  = $argv[1] ?? null;
$files = glob(__DIR__ . '/db/test_*.php');
sort($files);

$before = [];
foreach (['leads', 'clients', 'se_patients', 'se_appointments', 'se_conversion_outbox',
          'se_wa_webhook_events', 'se_meta_leadgen_events', 'se_brands', 'staff'] as $t) {
    $before[$t] = (int) $conn->query('SELECT COUNT(*) c FROM ' . db_prefix() . $t)->fetch_assoc()['c'];
}

$conn->begin_transaction();
$ran = [];

try {
    foreach ($files as $file) {
        $name = preg_replace('/^test_|\.php$/', '', basename($file));
        if ($only !== null && $name !== $only) { continue; }

        echo "\n================ real-db suite: {$name} ================\n";
        require $file;
        $ran[] = $name;
    }
} catch (Throwable $e) {
    echo "\n!! ABORTED: " . $e->getMessage() . "\n";
    $GLOBALS['se_assert']['fail']++;
    $GLOBALS['se_assert']['failures'][] = 'ABORT :: ' . $e->getMessage();
}

$conn->rollback();

/* SAFETY NET.
 *
 * A rollback is not a guarantee: any DDL a suite runs implicitly COMMITS in
 * MySQL/MariaDB and silently ends the transaction, making everything inserted
 * before it permanent. That happened once during development and leaked
 * fixtures into the live database. Suites are now forbidden from running DDL,
 * but the cleanup runs unconditionally rather than trusting that rule.
 *
 * Only the reserved id range and ZZ-prefixed fixtures are touched.
 */
$purge = [
    'se_appointment_status_history' => 'brand_id >= ' . SE_TEST_ID_BASE . ' OR appointment_id >= ' . SE_TEST_ID_BASE,
    'se_reminders'         => 'brand_id >= ' . SE_TEST_ID_BASE . ' OR appointment_id >= ' . SE_TEST_ID_BASE,
    'se_appointments'      => 'id >= ' . SE_TEST_ID_BASE . ' OR brand_id >= ' . SE_TEST_ID_BASE,
    'se_conversion_outbox' => 'id >= ' . SE_TEST_ID_BASE . ' OR brand_id >= ' . SE_TEST_ID_BASE . " OR dedup_key LIKE 'zz-%'",
    'se_wa_webhook_events' => "phone_number_id = 'ZZPN' OR waba_id = 'ZZWABA'",
    'se_consent_ledger'    => 'brand_id >= ' . SE_TEST_ID_BASE . ' OR rel_id >= ' . SE_TEST_ID_BASE,
    'se_patients'          => 'id >= ' . SE_TEST_ID_BASE . ' OR brand_id >= ' . SE_TEST_ID_BASE,
    'leads'                => 'id >= ' . SE_TEST_ID_BASE . ' OR brand_id >= ' . SE_TEST_ID_BASE,
    'se_staff_brands'      => 'staff_id >= ' . SE_TEST_ID_BASE . ' OR brand_id >= ' . SE_TEST_ID_BASE,
    'staff'                => 'staffid >= ' . SE_TEST_ID_BASE,
    'se_brands'            => 'id >= ' . SE_TEST_ID_BASE . " OR name LIKE 'ZZTEST%'",
];

$purged = 0;

foreach ($purge as $table => $where) {
    try {
        $conn->query('DELETE FROM ' . db_prefix() . $table . ' WHERE ' . $where);
        $purged += $conn->affected_rows;
    } catch (Throwable $e) { /* table may not exist in an older schema */ }
}

/* Prove the rollback actually restored every table. */
$residue = [];
foreach ($before as $t => $n) {
    $now = (int) $conn->query('SELECT COUNT(*) c FROM ' . db_prefix() . $t)->fetch_assoc()['c'];
    if ($now !== $n) { $residue[] = "{$t}: {$n} -> {$now}"; }
}

$a = $GLOBALS['se_assert'];

echo "\n============================================\n";
echo "real-db suites : " . implode(', ', $ran) . "\n";
echo "PASS           : {$a['pass']}\n";
echo "FAIL           : {$a['fail']}\n";
echo "rollback clean : " . ($residue ? 'NO — ' . implode('; ', $residue) : 'YES (all row counts restored)') . "\n";
echo "fixtures purged: {$purged} (safety net; 0 expected when the rollback held)\n";
echo "outbound calls : " . se_net_kill_count() . " (must be 0)\n";

if ($a['fail'] > 0) {
    echo "\nFailures:\n";
    foreach ($a['failures'] as $f) { echo "  - {$f}\n"; }
}

exit(($a['fail'] > 0 || $residue || se_net_kill_count() > 0) ? 1 : 0);
