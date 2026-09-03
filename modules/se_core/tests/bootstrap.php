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
    'staff_member' => true,
];

function se_test_reset()
{
    $GLOBALS['se_test']['denied']    = null;
    $GLOBALS['se_test']['activity']  = [];
    $GLOBALS['se_test']['post']      = [];
    $GLOBALS['se_test']['get']       = [];
    $GLOBALS['se_test']['uri']       = [];
    $GLOBALS['se_test']['method']    = 'get';
    $GLOBALS['se_test']['is_ajax']   = false;
    $GLOBALS['se_test']['staff_member'] = true;
    $GLOBALS['se_test']['admin_ids'] = [];
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

    /** CI_Input::is_ajax_request() — the ONLY ajax check that exists in production. */
    public function is_ajax_request() { return (bool) $GLOBALS['se_test']['is_ajax']; }
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
function get_staff_full_name($id = '') {
    foreach (se_test_db()->rows('tblstaff') as $s) { if ((int) $s['staffid'] === (int) $id) { return trim($s['firstname'] . ' ' . $s['lastname']); } }
    return '';
}
function is_staff_logged_in() { return $GLOBALS['se_test']['staff_id'] > 0; }

/**
 * Perfex semantics: no argument (or the acting staff id) answers for the
 * CURRENT actor; an explicit OTHER staff id answers for that staff member,
 * looked up in the test's admin registry (se_test_set_admin_ids).
 */
function is_admin($staff_id = '')
{
    if ($staff_id !== '' && $staff_id !== null
        && (int) $staff_id !== (int) $GLOBALS['se_test']['staff_id']) {
        return in_array((int) $staff_id, $GLOBALS['se_test']['admin_ids'] ?? [], true);
    }
    // Perfex's is_admin() with no staff session runs a SELECT on the shared
    // query builder (no $GLOBALS['current_user'] to answer from) — mid-build
    // that pollutes the caller's statement. Count such calls so a test can
    // assert that no-session code paths never reach it.
    if ((int) $GLOBALS['se_test']['staff_id'] <= 0) {
        $GLOBALS['se_test']['is_admin_calls_without_session'] = ($GLOBALS['se_test']['is_admin_calls_without_session'] ?? 0) + 1;
    }

    return $GLOBALS['se_test']['is_admin'];
}

/** Declare which OTHER staff ids count as Perfex admins for is_admin($id). */
function se_test_set_admin_ids(array $ids)
{
    $GLOBALS['se_test']['admin_ids'] = array_map('intval', $ids);
}

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

/* No global is_ajax_request() stub: Perfex has none (only $CI->input->is_ajax_request()),
 * and a stub here once hid a fatal in production code. */
function is_staff_member($staff_id = '') { return (bool) $GLOBALS['se_test']['staff_member']; }

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
function site_url($p = '') { return '/' . $p; }
function base_url($p = '') { return '/' . $p; }
function _dt($d) { return (string) $d; }
function html_escape($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/** Perfex App_Model stand-in so the real model classes can load. */
class App_Model
{
    public $db;

    public function __construct()
    {
        $this->db = $GLOBALS['se_test_ci']->db;
    }
}

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
require_once $SE_MODULES . '/se_core/se_consent_settings.php';
require_once $SE_MODULES . '/se_core/se_patients.php';
require_once $SE_MODULES . '/se_core/libraries/Se_hash.php';
require_once $SE_MODULES . '/se_core/se_outbox_snapshot.php';
require_once $SE_MODULES . '/se_core/se_outbox.php';
require_once $SE_MODULES . '/se_core/se_asset_registry.php';
require_once $SE_MODULES . '/se_core/se_capi.php';
require_once $SE_MODULES . '/se_core/se_capi_messaging.php';
require_once $SE_MODULES . '/se_core/se_push.php';
require_once $SE_MODULES . '/se_core/se_push_events.php';
require_once $SE_MODULES . '/se_core/se_google_auth.php';
require_once $SE_MODULES . '/se_core/se_google_dm.php';
require_once $SE_MODULES . '/se_core/se_meta_leadgen.php';

/* Appointment helpers that are plain functions (the model needs App_Model). */
require_once $SE_MODULES . '/se_appointments/availability.php';
require_once $SE_MODULES . '/se_appointments/reminders.php';
require_once $SE_MODULES . '/se_appointments/gcal.php';
require_once $SE_MODULES . '/se_core/se_secret_provider.php';
require_once $SE_MODULES . '/se_core/se_website_lead.php';
require_once $SE_MODULES . '/se_whatsapp/helpers.php';
require_once $SE_MODULES . '/se_whatsapp/calls.php';
require_once $SE_MODULES . '/se_whatsapp/outbound.php';
require_once $SE_MODULES . '/se_whatsapp/inbox.php';
require_once $SE_MODULES . '/se_whatsapp/templates.php';
/* Patient journey (se_journey). Its sealed media store AND the inbox media
 * store it reads from are throw-away temp directories for the run, like the
 * secret store below (the media suites define SE_MEDIA_DIR only when unset). */
if (!defined('SE_MEDIA_DIR')) {
    $seMediaDir = sys_get_temp_dir() . '/se_test_media_' . getmypid();
    @mkdir($seMediaDir, 0700, true);
    define('SE_MEDIA_DIR', $seMediaDir);
}
if (!defined('SE_JOURNEY_MEDIA_DIR')) {
    $seJourneyMediaDir = sys_get_temp_dir() . '/se_test_journey_media_' . getmypid();
    @mkdir($seJourneyMediaDir, 0700, true);
    define('SE_JOURNEY_MEDIA_DIR', $seJourneyMediaDir);
}
require_once $SE_MODULES . '/se_journey/helpers.php';
require_once $SE_MODULES . '/se_journey/messaging.php';
require_once $SE_MODULES . '/se_journey/intake.php';
require_once $SE_MODULES . '/se_journey/media.php';
require_once $SE_MODULES . '/se_journey/review.php';
require_once $SE_MODULES . '/se_journey/consultation.php';
require_once $SE_MODULES . '/se_journey/leadsync.php';
require_once $SE_MODULES . '/se_journey/flows.php';
require_once $SE_MODULES . '/se_journey/aftercare.php';
require_once $SE_MODULES . '/se_journey/health.php';
require_once $SE_MODULES . '/se_journey/next_action.php';
require_once $SE_MODULES . '/se_journey/timers.php';
require_once $SE_MODULES . '/se_journey/ui.php';
require_once $SE_MODULES . '/se_instagram/helpers.php';
require_once $SE_MODULES . '/se_instagram/outbound.php';
require_once $SE_MODULES . '/se_core/se_integration_ui.php';
require_once $SE_MODULES . '/se_core/se_reporting.php';
require_once $SE_MODULES . '/se_core/se_outbound_tracker.php';
require_once $SE_MODULES . '/se_core/se_outbox_ui.php';
require_once $SE_MODULES . '/se_core/se_hastalar.php';
require_once $SE_MODULES . '/se_core/se_dispatch.php';
require_once $SE_MODULES . '/se_core/se_media.php';
require_once $SE_MODULES . '/se_core/se_media_storage.php';
require_once $SE_MODULES . '/se_core/se_chat_ui.php';
require_once $SE_MODULES . '/se_core/helpers/se_ui_helper.php';
require_once $SE_MODULES . '/se_core/se_clinic.php';
require_once $SE_MODULES . '/se_core/se_navigation.php';
require_once $SE_MODULES . '/se_appointments/se_appointments.php';
require_once $SE_MODULES . '/se_whatsapp/models/Se_whatsapp_model.php';

/* ---------------------------------------------------------------------------
 * ONE shared temporary secret store for the whole run.
 *
 * SE_SECRET_DIR is a constant, so the first suite to define it wins and every
 * later suite silently writes fixtures somewhere the provider is not looking.
 * Defining it once here, before any suite runs, removes that ordering trap.
 * The directory is 0700 and every file 0600, exercising the real provider.
 * ------------------------------------------------------------------------- */
if (!defined('SE_SECRET_DIR')) {
    $seSecretDir = sys_get_temp_dir() . '/se_test_secrets_' . getmypid();
    @mkdir($seSecretDir, 0700, true);
    @chmod($seSecretDir, 0700);
    define('SE_SECRET_DIR', $seSecretDir);
}

/** Install a fixture secret. The value is a throwaway string, never a credential. */
function se_test_install_secret($name, $value)
{
    $path = rtrim(SE_SECRET_DIR, '/') . '/' . $name;
    file_put_contents($path, $value);
    @chmod($path, 0600);

    return $path;
}

/** Remove a fixture secret so a later suite starts from a known state. */
function se_test_remove_secret($name)
{
    @unlink(rtrim(SE_SECRET_DIR, '/') . '/' . $name);
}

/** Remove every fixture secret and the store itself. */
function se_test_purge_secrets()
{
    foreach (glob(rtrim(SE_SECRET_DIR, '/') . '/*') as $f) { @unlink($f); }
    @rmdir(SE_SECRET_DIR);
}

/** se_core_deny() lives in se_core.php, which we cannot load (it self-executes). */
if (!function_exists('se_core_deny')) {
    function se_core_deny()
    {
        access_denied('se_brands');
    }
}
