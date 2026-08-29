<?php
/**
 * Network-free test bootstrap for the SE modules.
 *
 * Stubs exactly the CodeIgniter / Perfex surface the modules touch, so the
 * REAL module files are loaded and exercised — no reimplementation, no mocking
 * of the code under test. Nothing here opens a socket, reads a credential or
 * touches the live database.
 *
 * Run:  php modules/se_core/tests/run.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

error_reporting(E_ALL);
ini_set('display_errors', '1');
// Never let a test run drop an error_log inside the document root.
ini_set('log_errors', '0');
date_default_timezone_set('UTC');

define('BASEPATH', __DIR__);                       // satisfies the module guards
define('SE_TESTING', true);

require_once __DIR__ . '/fake_db.php';

/* ---------------------------------------------------------------------------
 * Test state. Mutated by tests via se_test_* helpers.
 * ------------------------------------------------------------------------- */

$GLOBALS['se_test'] = [
    'staff_id'    => 0,
    'is_admin'    => false,
    'permissions' => [],   // ["feature.capability" => true]
    'options'     => [],
    'denied'      => null, // set when se_core_deny()/access_denied() fires
    'activity'    => [],
    'uri'         => [],
    'post'        => [],
    'get'         => [],
    'method'      => 'get',
    'is_ajax'     => false,
];

function se_test_reset()
{
    $GLOBALS['se_test']['denied']   = null;
    $GLOBALS['se_test']['activity'] = [];
    $GLOBALS['se_test']['post']     = [];
    $GLOBALS['se_test']['get']      = [];
    $GLOBALS['se_test']['uri']      = [];
    $GLOBALS['se_test']['method']   = 'get';
    $GLOBALS['se_test']['is_ajax']  = false;
    se_authz_reset_cache();
}

/** Become a staff member with an explicit capability set. */
function se_test_act_as($staff_id, array $capabilities = [], $admin = false)
{
    $GLOBALS['se_test']['staff_id']    = (int) $staff_id;
    $GLOBALS['se_test']['is_admin']    = (bool) $admin;
    $GLOBALS['se_test']['permissions'] = array_fill_keys($capabilities, true);
    se_authz_reset_cache();
}

function se_test_set_uri(array $segments)   { $GLOBALS['se_test']['uri'] = $segments; }
function se_test_set_post(array $p)         { $GLOBALS['se_test']['post'] = $p; $GLOBALS['se_test']['method'] = 'post'; }
function se_test_set_get(array $g)          { $GLOBALS['se_test']['get'] = $g; }
function se_test_denied()                   { return $GLOBALS['se_test']['denied']; }

/* ---------------------------------------------------------------------------
 * CodeIgniter / Perfex stubs.
 * ------------------------------------------------------------------------- */

class SeTestUri
{
    public function segment($n, $default = null)
    {
        $s = $GLOBALS['se_test']['uri'];
        return $s[$n - 1] ?? $default;
    }

    public function segment_array() { return $GLOBALS['se_test']['uri']; }
}

class SeTestInput
{
    public function post($key = null)
    {
        $p = $GLOBALS['se_test']['post'];
        return $key === null ? $p : ($p[$key] ?? null);
    }

    public function get($key = null)
    {
        $g = $GLOBALS['se_test']['get'];
        return $key === null ? $g : ($g[$key] ?? null);
    }

    public function post_get($key)
    {
        return $GLOBALS['se_test']['post'][$key] ?? $GLOBALS['se_test']['get'][$key] ?? null;
    }

    public function method() { return $GLOBALS['se_test']['method']; }
}

class SeTestLoader
{
    public function helper($x) {}
    public function model($x) {}
    public function library($x) {}
    public function view($x, $y = null) {}
}

class SeTestCI
{
    public $db;
    public $uri;
    public $input;
    public $load;

    public function __construct()
    {
        $this->db    = new SeFakeDb();
        $this->uri   = new SeTestUri();
        $this->input = new SeTestInput();
        $this->load  = new SeTestLoader();
    }
}

$GLOBALS['se_test_ci'] = new SeTestCI();

function &get_instance()
{
    return $GLOBALS['se_test_ci'];
}

function se_test_db() { return $GLOBALS['se_test_ci']->db; }

function db_prefix() { return 'tbl'; }

function get_staff_user_id() { return $GLOBALS['se_test']['staff_id']; }
function is_staff_logged_in() { return $GLOBALS['se_test']['staff_id'] > 0; }

function is_admin($staff_id = '') { return $GLOBALS['se_test']['is_admin']; }

function staff_can($capability, $feature = null, $staff_id = '')
{
    if ($GLOBALS['se_test']['is_admin']) { return true; }
    return !empty($GLOBALS['se_test']['permissions'][$feature . '.' . $capability]);
}

function staff_cant($capability, $feature = null, $staff_id = '')
{
    return !staff_can($capability, $feature, $staff_id);
}

function get_option($name)
{
    return $GLOBALS['se_test']['options'][$name] ?? '';
}

function update_option($name, $value)
{
    $GLOBALS['se_test']['options'][$name] = $value;
    return true;
}

function add_option($name, $value) { return update_option($name, $value); }

function _l($key, $x = '') { return $key; }

function log_activity($msg) { $GLOBALS['se_test']['activity'][] = $msg; }

function is_ajax_request() { return $GLOBALS['se_test']['is_ajax']; }

function access_denied($what = '')
{
    $GLOBALS['se_test']['denied'] = $what ?: 'denied';
    throw new SeAccessDenied($GLOBALS['se_test']['denied']);
}

function ajax_access_denied($what = '')
{
    $GLOBALS['se_test']['denied'] = $what ?: 'ajax_denied';
    throw new SeAccessDenied($GLOBALS['se_test']['denied']);
}

class SeAccessDenied extends RuntimeException {}

function slug_it($s) { return strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s)); }

/* Perfex hooks: record registrations, never dispatch. */
class SeTestHooks
{
    public $actions = [];
    public $filters = [];

    public function add_action($tag, $fn, $prio = 10, $args = 1) { $this->actions[$tag][] = $fn; }
    public function add_filter($tag, $fn, $prio = 10, $args = 1) { $this->filters[$tag][] = $fn; }
    public function do_action($tag, $arg = null) {}
    public function apply_filters($tag, $value) { return $value; }
}

function hooks()
{
    static $h = null;
    if ($h === null) { $h = new SeTestHooks(); }
    return $h;
}

function register_staff_capabilities($feature, $config, $name = null)
{
    $GLOBALS['se_test']['capabilities'][$feature] = $config;
}

function register_language_files($m, $f) {}
function register_activation_hook($m, $f) {}
function admin_url($p = '') { return '/admin/' . $p; }

/* ---------------------------------------------------------------------------
 * Assertions.
 * ------------------------------------------------------------------------- */

$GLOBALS['se_assert'] = ['pass' => 0, 'fail' => 0, 'failures' => [], 'group' => ''];

function se_group($name)
{
    $GLOBALS['se_assert']['group'] = $name;
    echo "\n-- {$name}\n";
}

function se_ok($condition, $label)
{
    if ($condition) {
        $GLOBALS['se_assert']['pass']++;
        echo "   PASS  {$label}\n";
    } else {
        $GLOBALS['se_assert']['fail']++;
        $GLOBALS['se_assert']['failures'][] = $GLOBALS['se_assert']['group'] . ' :: ' . $label;
        echo "   FAIL  {$label}\n";
    }
}

function se_eq($expected, $actual, $label)
{
    $ok = $expected === $actual;
    if (!$ok) {
        $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
    }
    se_ok($ok, $label);
}

/** Assert that $fn triggers an access denial. */
function se_denies(callable $fn, $label)
{
    $GLOBALS['se_test']['denied'] = null;
    try {
        $fn();
        se_ok(false, $label . ' [NO DENIAL RAISED]');
    } catch (SeAccessDenied $e) {
        se_ok(true, $label);
    }
}

/** Assert that $fn completes without an access denial. */
function se_allows(callable $fn, $label)
{
    $GLOBALS['se_test']['denied'] = null;
    try {
        $fn();
        se_ok(true, $label);
    } catch (SeAccessDenied $e) {
        se_ok(false, $label . ' [UNEXPECTED DENIAL]');
    }
}

/* ---------------------------------------------------------------------------
 * Load the real module code under test.
 * ------------------------------------------------------------------------- */

$SE_MODULES = dirname(dirname(__DIR__));   // .../modules

require_once $SE_MODULES . '/se_core/helpers/se_core_helper.php';
require_once $SE_MODULES . '/se_core/se_authz.php';
require_once $SE_MODULES . '/se_core/migrations.php';
require_once $SE_MODULES . '/se_core/pipeline.php';
require_once $SE_MODULES . '/se_core/se_consent.php';
require_once $SE_MODULES . '/se_core/se_patients.php';
require_once $SE_MODULES . '/se_core/libraries/Se_hash.php';
require_once $SE_MODULES . '/se_core/se_outbox_snapshot.php';
require_once $SE_MODULES . '/se_core/se_outbox.php';
require_once $SE_MODULES . '/se_core/se_capi.php';
require_once $SE_MODULES . '/se_core/se_google_dm.php';
require_once $SE_MODULES . '/se_core/se_meta_leadgen.php';

/** se_core_deny() lives in se_core.php, which we cannot load (it self-executes). */
if (!function_exists('se_core_deny')) {
    function se_core_deny()
    {
        access_denied('se_brands');
    }
}
