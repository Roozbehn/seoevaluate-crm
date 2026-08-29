<?php
/**
 * Sidebar simulation — renders the REAL admin sidebar composition for the
 * three clinic roles, using Perfex's own App_menu, menu_helper.php (the core
 * item registration), the php-hooks library, the Menu Builder filters and the
 * clinic filters. Only the CodeIgniter surface those need is stubbed.
 *
 *   php modules/se_core/tests/sidebar_sim.php
 *
 * This is a development aid, not a test tier: it proves the filter wiring and
 * item ordering, not rendering.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('BASEPATH', __DIR__);
define('APPPATH', dirname(dirname(dirname(__DIR__))) . '/application/');
define('APP_MODULES_PATH', dirname(dirname(__DIR__)) . '/');

require_once APPPATH . 'vendor/bainternet/php-hooks/php-hooks.php';

function hooks() { static $h = null; if ($h === null) { $h = new \Hooks(); } return $h; }

require_once APPPATH . 'services/utilities/Arr.php';
require_once APPPATH . 'libraries/App_menu.php';

$GLOBALS['sim'] = ['admin' => false, 'perms' => [], 'staff_member' => true,
    'options' => ['aside_menu_active' => '[]', 'setup_menu_active' => '[]']];

class SimLoader { public function model($m) {} public function helper($h) {} }
class SimTickets { public function get_ticket_status() { return []; } public function ticket_count($s) { return 0; } }
class SimModules { public function number_of_modules_that_require_database_upgrade() { return 0; } }
function &get_instance() {
    static $ci = null;
    if ($ci === null) { $ci = new stdClass(); $ci->app_menu = new App_menu(); $ci->load = new SimLoader(); $ci->tickets_model = new SimTickets(); $ci->app_modules = new SimModules(); }
    return $ci;
}
function ticket_status_translate($id) { return (string) $id; }
function app_sort_by_position($array, $keepIndex = false) { return \app\services\utilities\Arr::sortBy($array, 'position', $keepIndex); }
function app_fill_empty_common_attributes($array) {
    $array['icon'] = $array['icon'] ?? ''; $array['href'] = (isset($array['href']) && $array['href'] != '') ? $array['href'] : '#';
    $array['position'] = $array['position'] ?? null; return $array;
}
function _l($k, $l = '', $log = true) { return $k; }
function admin_url($p = '') { return '/admin/' . $p; }
function is_admin($id = '') { return $GLOBALS['sim']['admin']; }
function staff_can($cap, $feature = null, $id = '') { return is_admin() || !empty($GLOBALS['sim']['perms'][$feature . '.' . $cap]); }
function staff_cant($cap, $feature = null, $id = '') { return !staff_can($cap, $feature, $id); }
function is_staff_member($id = '') { return $GLOBALS['sim']['staff_member']; }
function is_staff_logged_in() { return true; }
function have_assigned_customers($id = '') { return false; }
function get_option($n) { return $GLOBALS['sim']['options'][$n] ?? ''; }
function db_prefix() { return 'tbl'; }
function get_staff_user_id() { return 7; }
function total_rows($t, $w = []) { return 0; }
function get_staff_default_language($id = '') { return ''; }
function is_staff_member_of_role() { return false; }
function html_entity_decode_safe($s) { return $s; }
function app_menu_item_exists() { return false; }
function staff_has_assigned_proposals($id = '') { return false; }
function staff_has_assigned_estimates($id = '') { return false; }
function staff_has_assigned_invoices($id = '') { return false; }

/* Perfex core registration (the real file). */
require_once APPPATH . 'helpers/menu_helper.php';

/* Menu Builder module filters (the real file), as on production. */
require_once APP_MODULES_PATH . 'menu_setup/helpers/menu_setup_helper.php';
hooks()->add_filter('sidebar_menu_items', 'app_admin_sidebar_custom_options', 999);
hooks()->add_filter('sidebar_menu_items', 'app_admin_sidebar_custom_positions', 998);

/* Clinic code under test (the real files). */
require_once APP_MODULES_PATH . 'se_core/helpers/se_core_helper.php';
require_once APP_MODULES_PATH . 'se_core/se_authz.php';
require_once APP_MODULES_PATH . 'se_core/se_clinic.php';
require_once APP_MODULES_PATH . 'se_core/se_navigation.php';
hooks()->add_filter('sidebar_menu_items', 'se_clinic_filter_sidebar', 1000);

function sim_render($label, $admin, array $perms)
{
    $GLOBALS['sim']['admin'] = $admin;
    $GLOBALS['sim']['perms'] = array_fill_keys($perms, true);
    se_authz_reset_cache();

    $ci = &get_instance();
    $ci->app_menu = new App_menu();

    app_init_admin_sidebar_menu_items();
    se_nav_register();

    echo "\n== {$label} ==\n";
    foreach ($ci->app_menu->get_sidebar_menu_items() as $item) {
        $children = array_map(function ($c) { return $c['slug']; }, $item['children']);
        printf("  %-3s %-18s %-40s %s\n", $item['position'], $item['slug'], $item['href'], $children ? '{' . implode(', ', $children) . '}' : '');
    }
}

$owner = []; $sales = [];
foreach (se_clinic_role_definitions() as $role) {
    foreach ($role['permissions'] as $f => $caps) { foreach ($caps as $c) { ${$role['name'] === 'Sales' ? 'sales' : 'owner'}[] = $f . '.' . $c; } }
}

sim_render('Administrator', true, []);
sim_render('Clinic Owner', false, $owner);
sim_render('Sales', false, $sales);
