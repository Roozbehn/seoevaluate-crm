<?php
/**
 * Clinic mode (se_clinic.php + the flat navigation).
 *
 * Actors:
 *   admin   - Perfex admin
 *   owner   - the "Clinic Owner" role's capabilities, mapped to the sole brand
 *   sales   - the "Sales" role's capabilities, mapped to the sole brand
 *   nobody  - authenticated, no capability at all
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function se_test_clinic_caps($role_name)
{
    foreach (se_clinic_role_definitions() as $role) {
        if ($role['name'] === $role_name) {
            $caps = [];
            foreach ($role['permissions'] as $feature => $capabilities) {
                foreach ($capabilities as $c) { $caps[] = $feature . '.' . $c; }
            }
            return $caps;
        }
    }
    throw new RuntimeException('unknown role ' . $role_name);
}

function se_test_seed_clinic($brands = 1)
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];

    $rows = [];
    for ($i = 1; $i <= $brands; $i++) {
        $rows[] = ['id' => $i, 'name' => 'TurquAI CRM ' . $i, 'slug' => 'turquai-' . $i, 'active' => 1,
                   'meta_dataset_id' => '', 'google_ads_customer_id' => ''];
    }
    $db->seed('tblse_brands', $rows);

    $db->seed('tblse_staff_brands', [['staff_id' => 30, 'brand_id' => 1]]);

    $db->seed('tblstaff', [
        ['staffid' => 1,  'email' => 'admin@example.invalid', 'admin' => 1, 'active' => 1, 'default_language' => ''],
        ['staffid' => 30, 'email' => 'owner@example.invalid', 'admin' => 0, 'active' => 1, 'default_language' => ''],
        ['staffid' => 40, 'email' => 'sales@example.invalid', 'admin' => 0, 'active' => 1, 'default_language' => 'persian'],
        ['staffid' => 90, 'email' => 'gone@example.invalid',  'admin' => 0, 'active' => 0, 'default_language' => ''],
    ]);

    $db->seed('tblleads', [
        ['id' => 101, 'brand_id' => 1, 'email' => 'a@example.invalid'],
        ['id' => 303, 'brand_id' => 0, 'email' => 'u@example.invalid'],
        ['id' => 404, 'brand_id' => 3, 'email' => 'x@example.invalid'],
    ]);
    $db->seed('tblclients', [['userid' => 501, 'brand_id' => 0]]);
    $db->seed('tblse_patients', [['id' => 701, 'brand_id' => 0, 'retention_state' => 'active']]);
    $db->seed('tblse_appointments', [['id' => 801, 'brand_id' => 1, 'status' => 'scheduled']]);
    $db->seed('tblse_wa_conversations', [['id' => 901, 'brand_id' => 0]]);
    $db->seed('tblroles', []);

    $GLOBALS['se_test']['options'] = [
        'se_core_schema_version' => SE_CORE_SCHEMA_VERSION,
        'active_language'        => 'english',
        'companyname'            => 'SEO Evaluate CRM',
    ];

    se_test_reset();
}

/** The core sidebar as menu_helper.php + se_nav_register() would build it. */
function se_test_clinic_sidebar_fixture()
{
    $items = [];
    foreach (['dashboard' => 1, 'customers' => 5, 'sales' => 10, 'subscriptions' => 15, 'expenses' => 20,
              'contracts' => 25, 'projects' => 30, 'tasks' => 35, 'support' => 40, 'leads' => 45,
              'estimate_request' => 46, 'knowledge-base' => 50, 'utilities' => 55, 'reports' => 60,
              'se-patients' => 3, 'se-appointments' => 4, 'se-whatsapp' => 5, 'se-instagram' => 6,
              'se-reports' => 8, 'se-integrations' => 9] as $slug => $pos) {
        $items[$slug] = ['slug' => $slug, 'name' => $slug, 'href' => '/admin/' . $slug, 'position' => $pos, 'children' => []];
    }
    $items['dashboard']['href'] = '/admin/';
    return $items;
}

function se_test_clinic_slugs(array $items)
{
    return array_map(function ($i) { return $i['slug']; }, $items);
}

/* ---------------------------------------------------------------------------
 * 1. Single-brand detection
 * ------------------------------------------------------------------------- */
se_group('Clinic: single-brand detection follows the data');

se_test_seed_clinic(0);
se_eq(0, se_clinic_sole_brand_id(), 'no brand at all -> 0');
se_ok(!se_clinic_is_single_brand(), 'no brand is not single-brand mode');

se_test_seed_clinic(1);
se_eq(1, se_clinic_sole_brand_id(), 'exactly one active brand -> its id');
se_ok(se_clinic_is_single_brand(), 'one brand is single-brand mode');

se_test_db()->seed('tblse_brands', [
    ['id' => 1, 'name' => 'A', 'slug' => 'a', 'active' => 1],
    ['id' => 2, 'name' => 'B', 'slug' => 'b', 'active' => 0],
]);
se_clinic_reset_cache();
se_eq(1, se_clinic_sole_brand_id(), 'an inactive second brand does not count');

se_test_seed_clinic(2);
se_eq(0, se_clinic_sole_brand_id(), 'two active brands -> 0 (multi-brand install)');

se_test_seed_clinic(1);
se_clinic_sole_brand_id();
se_test_db()->seed('tblse_brands', [['id' => 1, 'name' => 'A', 'slug' => 'a', 'active' => 1], ['id' => 2, 'name' => 'B', 'slug' => 'b', 'active' => 1]]);
se_eq(1, se_clinic_sole_brand_id(), 'the answer is memoized within a request');
se_authz_reset_cache();
se_eq(0, se_clinic_sole_brand_id(), 'se_authz_reset_cache() also drops the clinic memo');

/* ---------------------------------------------------------------------------
 * 2. Lean sidebar
 * ------------------------------------------------------------------------- */
se_group('Clinic: lean sidebar filter');

se_test_seed_clinic(1);
se_test_act_as(40, se_test_clinic_caps('Sales'));

$out = se_clinic_filter_sidebar(se_test_clinic_sidebar_fixture());

foreach (se_clinic_hidden_sidebar_slugs() as $slug) {
    se_ok(!isset($out[$slug]), "core '{$slug}' is removed");
}
foreach (['dashboard', 'customers', 'leads', 'se-patients', 'se-appointments', 'se-whatsapp', 'se-instagram', 'se-reports', 'se-integrations'] as $slug) {
    se_ok(isset($out[$slug]), "'{$slug}' survives");
}
se_eq(2, $out['leads']['position'], 'Leads moves up to position 2');
se_eq(7, $out['customers']['position'], 'Customers moves after the clinic screens (incl. Instagram)');
se_eq('/admin/se_core/se_dashboard', $out['dashboard']['href'], 'Dashboard points at the clinic dashboard for a clinic role');

se_test_act_as(99, []);
$out = se_clinic_filter_sidebar(se_test_clinic_sidebar_fixture());
se_eq('/admin/', $out['dashboard']['href'], 'Dashboard is left alone for a staff member who may not open the clinic dashboard');
se_eq('nope', se_clinic_filter_sidebar('nope'), 'a non-array passes through untouched');

se_group('Clinic: quick-create and Setup menu');

$quick = [
    ['name' => 'invoice', 'permission' => 'invoices'],
    ['name' => 'client', 'permission' => 'customers'],
    ['name' => 'task', 'permission' => 'tasks'],
    ['name' => 'lead', 'permission' => 'is_staff_member'],
    ['name' => 'ticket'],
    ['name' => 'staff', 'permission' => 'staff'],
    ['name' => 'calendar', 'permission' => ''],
];
$filtered = se_clinic_filter_quick_actions($quick);
$kept = array_map(function ($i) { return $i['name']; }, $filtered);
se_eq(['client', 'lead', 'staff'], $kept, 'quick-create keeps only Lead, Customer and Staff member');
se_ok(!isset($filtered[1]['permission']), 'the Lead link loses its is_staff_member permission key for a staff member (Perfex would otherwise hide it from every non-admin)');
se_eq('customers', $filtered[0]['permission'], 'other links keep their permission gate');

$GLOBALS['se_test']['staff_member'] = false;
$filtered = se_clinic_filter_quick_actions($quick);
se_eq('is_staff_member', $filtered[1]['permission'], 'a non-staff user keeps the gate (and Perfex hides the link)');
$GLOBALS['se_test']['staff_member'] = true;

se_test_act_as(1, [], true);
se_ok(se_clinic_show_setup_menu(true), 'admin keeps the Setup menu');
se_test_act_as(30, se_test_clinic_caps('Clinic Owner'));
se_ok(!se_clinic_show_setup_menu(true), 'the owner does not get the Setup menu');
se_ok(!se_clinic_show_setup_menu(false), 'a Setup menu Perfex already hid stays hidden');

/* ---------------------------------------------------------------------------
 * 3. Dashboard redirect predicate
 * ------------------------------------------------------------------------- */
se_group('Clinic: core dashboard request predicate');

se_ok(se_clinic_is_core_dashboard_request('dashboard', 'index', false), 'GET /admin (dashboard/index) is the core dashboard');
se_ok(se_clinic_is_core_dashboard_request('Dashboard', 'INDEX', false), 'case-insensitive');
se_ok(!se_clinic_is_core_dashboard_request('dashboard', 'index', true), 'AJAX requests are never redirected');
se_ok(!se_clinic_is_core_dashboard_request('dashboard', 'weekly_payments_statistics', false), 'dashboard widget endpoints are not redirected');
se_ok(!se_clinic_is_core_dashboard_request('se_dashboard', 'index', false), 'the clinic dashboard itself is not redirected (no loop)');
se_ok(!se_clinic_is_core_dashboard_request('leads', 'index', false), 'other controllers are not redirected');

/* ---------------------------------------------------------------------------
 * 4. Lead stamping
 * ------------------------------------------------------------------------- */
se_group('Clinic: single-brand lead stamping');

se_test_seed_clinic(1);
se_test_act_as(1, [], true);
se_ok(se_clinic_stamp_lead(303), 'a brand-0 lead created by the admin is stamped with the clinic');
$lead = null;
foreach (se_test_db()->rows('tblleads') as $r) { if ($r['id'] === 303) { $lead = $r; } }
se_eq(1, (int) $lead['brand_id'], 'stamped with the sole brand id');
se_ok(!se_clinic_stamp_lead(404), 'a lead that already has a brand is left alone');
se_ok(!se_clinic_stamp_lead(303), 'stamping twice changes nothing');

se_test_seed_clinic(1);
se_ok(se_clinic_stamp_lead(['lead_id' => 303, 'web_to_lead_form' => true]), 'the web-to-lead payload (Forms.php passes an array) stamps the RIGHT lead');
$leads = [];
foreach (se_test_db()->rows('tblleads') as $r) { $leads[(int) $r['id']] = (int) $r['brand_id']; }
se_eq(1, $leads[303], '...lead 303 is stamped');
se_ok(!se_clinic_stamp_lead(['web_to_lead_form' => true]), 'a payload without lead_id is refused');
se_ok(!se_clinic_stamp_lead(0), 'lead id 0 is refused');
se_eq(303, se_clinic_lead_id_from_hook(['lead_id' => '303']), 'hook payload normalisation: array');
se_eq(303, se_clinic_lead_id_from_hook('303'), 'hook payload normalisation: scalar');
se_eq(0, se_clinic_lead_id_from_hook(['x' => 1]), 'hook payload normalisation: missing id -> 0');

se_test_seed_clinic(2);
se_ok(!se_clinic_stamp_lead(303), 'with two brands nothing is stamped');
foreach (se_test_db()->rows('tblleads') as $r) { if ($r['id'] === 303) { $lead = $r; } }
se_eq(0, (int) $lead['brand_id'], 'the multi-brand lead stays in triage');

/* ---------------------------------------------------------------------------
 * 5. Staff mapping
 * ------------------------------------------------------------------------- */
se_group('Clinic: staff-to-brand mapping');

se_test_seed_clinic(1);
se_ok(se_clinic_map_staff(40), 'a new staff member is mapped to the clinic');
se_ok(!se_clinic_map_staff(40), 'mapping again is a no-op');
se_ok(!se_clinic_map_staff(30), 'an already-mapped staff member is not duplicated');
se_ok(!se_clinic_map_staff(0), 'staff id 0 is refused');
se_eq(2, count(se_test_db()->rows('tblse_staff_brands')), 'exactly two mapping rows');

se_test_seed_clinic(1);
se_eq(2, se_clinic_backfill_staff_mappings(), 'backfill maps the two unmapped ACTIVE staff (admin + sales)');
se_eq(0, se_clinic_backfill_staff_mappings(), 'backfill is idempotent');
$mapped = array_map(function ($r) { return (int) $r['staff_id']; }, se_test_db()->rows('tblse_staff_brands'));
sort($mapped);
se_eq([1, 30, 40], $mapped, 'inactive staff 90 is not mapped');

se_test_seed_clinic(2);
se_eq(0, se_clinic_backfill_staff_mappings(), 'no backfill in a multi-brand install');
se_ok(!se_clinic_map_staff(40), 'no automatic mapping in a multi-brand install');

/* ---------------------------------------------------------------------------
 * 6. Gates
 * ------------------------------------------------------------------------- */
se_group('Clinic: capability gates');

se_test_seed_clinic(1);

se_test_act_as(30, se_test_clinic_caps('Clinic Owner'));
se_ok(se_clinic_can_manage_consent(), 'owner (se_consent.manage) may edit consent wording');
se_ok(!se_staff_can_configure_brands(), '...without holding brand configuration');
se_ok(se_clinic_can_open_dashboard(), 'owner may open the dashboard');
se_ok(se_clinic_can_see_integration_cards(), 'owner (reports) sees the integration cards');

se_test_act_as(40, se_test_clinic_caps('Sales'));
se_ok(!se_clinic_can_manage_consent(), 'sales may not edit consent wording');
se_ok(se_clinic_can_open_dashboard(), 'sales may open the dashboard (feature capability only)');
se_ok(!se_clinic_can_see_integration_cards(), 'sales does not see the integration cards');
se_ok(!se_staff_can_report(), 'sales cannot report');

se_test_act_as(99, []);
se_ok(!se_clinic_can_open_dashboard(), 'a staff member with no capability may not open the dashboard');
se_ok(!se_clinic_can_manage_consent(), '...nor consent');

se_test_act_as(50, ['se_brands.view']);
se_ok(se_clinic_can_manage_consent(), 'brand configuration still implies consent (unchanged for admins/configurators)');

/* ---------------------------------------------------------------------------
 * 7. Navigation per role
 * ------------------------------------------------------------------------- */
se_group('Clinic: navigation visibility per role');

se_test_act_as(1, [], true);
se_eq(['se-patients', 'se-appointments', 'se-whatsapp', 'se-instagram', 'se-reports'], se_test_clinic_slugs(se_nav_visible_items()), 'admin: all five clinic items');
se_eq(['se-meta-leadgen', 'se-outbox', 'se-google', 'se-health', 'se-credentials', 'se-consent'], se_test_clinic_slugs(se_nav_visible_integration_items()), 'admin: all six integration items');

se_test_act_as(30, se_test_clinic_caps('Clinic Owner'));
se_eq(['se-patients', 'se-appointments', 'se-whatsapp', 'se-instagram', 'se-reports'], se_test_clinic_slugs(se_nav_visible_items()), 'owner: patients, appointments, WhatsApp, Instagram, reports');
se_eq(['se-consent'], se_test_clinic_slugs(se_nav_visible_integration_items()), 'owner: only Consent Settings in Integrations');

se_test_act_as(40, se_test_clinic_caps('Sales'));
se_eq(['se-patients', 'se-appointments', 'se-whatsapp', 'se-instagram'], se_test_clinic_slugs(se_nav_visible_items()), 'sales: patients, appointments, WhatsApp, Instagram — no reports');
se_eq([], se_test_clinic_slugs(se_nav_visible_integration_items()), 'sales: no Integrations group at all');

se_test_act_as(60, ['se_reports.view']);
se_eq(['se-reports'], se_test_clinic_slugs(se_nav_visible_items()), 'report-only staff: Reports');
se_eq([], se_test_clinic_slugs(se_nav_visible_integration_items()), 'report-only staff: Outbox and Health are NOT offered (config-capable only)');

se_test_act_as(99, []);
se_eq([], se_test_clinic_slugs(se_nav_visible_items()), 'no capability: nothing');

$positions = [];
foreach (se_nav_items() as $item) { $positions[$item['slug']] = $item['position']; }
se_eq(['se-patients' => 3, 'se-appointments' => 4, 'se-whatsapp' => 5, 'se-instagram' => 6, 'se-reports' => 8], $positions, 'clinic items interleave with Dashboard (1), Leads (2) and Customers (7)');
foreach (se_nav_items() as $item) {
    se_eq($item['position'], se_clinic_sidebar_positions()[$item['slug']], "position of {$item['slug']} agrees with the sidebar filter");
}

/* ---------------------------------------------------------------------------
 * 8. Role definitions
 * ------------------------------------------------------------------------- */
se_group('Clinic: role definitions never widen the tenant boundary');

foreach (se_clinic_role_definitions() as $role) {
    $p = $role['permissions'];
    se_ok(!isset($p['se_brands']), $role['name'] . ' holds no brand configuration');
    se_ok(!isset($p['se_tenancy']), $role['name'] . ' holds no cross-brand capability');
    foreach (['invoices', 'estimates', 'payments', 'expenses', 'projects', 'tasks', 'settings', 'staff', 'roles'] as $f) {
        se_ok(!isset($p[$f]), $role['name'] . " holds no '{$f}' capability");
    }
}
$owner = se_clinic_role_definitions()[0]['permissions'];
$sales = se_clinic_role_definitions()[1]['permissions'];
se_ok(in_array('delete', $owner['se_patients'], true) && !in_array('delete', $sales['se_patients'], true), 'only the owner may delete patients');
se_ok(isset($owner['se_reports']) && !isset($sales['se_reports']), 'only the owner may report');
se_ok(isset($owner[SE_FEATURE_CONSENT]) && !isset($sales[SE_FEATURE_CONSENT]), 'only the owner manages consent');

/* ---------------------------------------------------------------------------
 * 9. Provisioning
 * ------------------------------------------------------------------------- */
se_group('Clinic: provisioning is one-shot and idempotent');

se_test_seed_clinic(1);
$GLOBALS['se_test']['options']['se_core_schema_version'] = SE_CORE_SCHEMA_VERSION - 1;
se_ok(!se_clinic_provision(), 'refuses to run on a schema that is not fully migrated');
se_eq('TurquAI CRM 1', se_test_db()->rows('tblse_brands')[0]['name'], '...and changed nothing');
se_eq('', (string) get_option('se_clinic_provision_version'), '...and recorded no version');

se_test_seed_clinic(1);
se_ok(se_clinic_provision(), 'runs on a migrated schema');

$brand = se_test_db()->rows('tblse_brands')[0];
se_eq(SE_CLINIC_NAME, $brand['name'], 'the sole brand is renamed to the clinic');
se_eq(SE_CLINIC_SLUG, $brand['slug'], 'the slug follows');
se_eq(1, (int) $brand['active'], 'the brand is active');

$roles = se_test_db()->rows('tblroles');
se_eq(2, count($roles), 'two roles created');
se_eq('Clinic Owner', $roles[0]['name'], 'Clinic Owner first');
se_eq('Sales', $roles[1]['name'], 'Sales second');
se_eq(se_clinic_role_definitions()[1]['permissions'], unserialize($roles[1]['permissions']), 'role permissions are stored serialized, Perfex-style');

se_eq(SE_CLINIC_NAME, get_option('companyname'), 'company name is the clinic');
se_eq('turkish', get_option('active_language'), 'Turkish is the default UI language');

$staff = [];
foreach (se_test_db()->rows('tblstaff') as $r) { $staff[(int) $r['staffid']] = $r; }
se_eq('english', $staff[1]['default_language'], 'the existing admin keeps English');
se_eq('english', $staff[30]['default_language'], 'the existing owner account keeps English');
se_eq('persian', $staff[40]['default_language'], 'an explicit per-account language is untouched');

$leads = [];
foreach (se_test_db()->rows('tblleads') as $r) { $leads[(int) $r['id']] = (int) $r['brand_id']; }
se_eq(1, $leads[303], 'brand-0 lead folded into the clinic');
se_eq(3, $leads[404], 'a lead of another brand id is untouched');
se_eq(1, (int) se_test_db()->rows('tblclients')[0]['brand_id'], 'brand-0 customer folded');
se_eq(1, (int) se_test_db()->rows('tblse_patients')[0]['brand_id'], 'brand-0 patient folded');
se_eq(1, (int) se_test_db()->rows('tblse_wa_conversations')[0]['brand_id'], 'brand-0 WhatsApp conversation folded');

$mapped = array_map(function ($r) { return (int) $r['staff_id']; }, se_test_db()->rows('tblse_staff_brands'));
sort($mapped);
se_eq([1, 30, 40], $mapped, 'every active staff member is mapped to the clinic');
se_eq(SE_CLINIC_PROVISION_VERSION, (int) get_option('se_clinic_provision_version'), 'version recorded');
se_eq(1, count($GLOBALS['se_test']['activity']), 'one activity-log line');

$before = count($GLOBALS['se_test']['activity']);
se_ok(se_clinic_provision(), 'second run returns true');
se_eq(2, count(se_test_db()->rows('tblroles')), '...creates no duplicate roles');
se_eq($before, count($GLOBALS['se_test']['activity']), '...and logs nothing');

se_test_seed_clinic(1);
se_test_db()->seed('tblroles', [['roleid' => 7, 'name' => 'Sales', 'permissions' => serialize(['leads' => ['view']])]]);
se_clinic_provision();
$roles = se_test_db()->rows('tblroles');
se_eq(2, count($roles), 'an existing Sales role is kept, only the missing one is added');
$existing = null;
foreach ($roles as $r) { if ($r['name'] === 'Sales') { $existing = $r; } }
se_eq(['leads' => ['view']], unserialize($existing['permissions']), 'the existing role\'s permissions are never overwritten');

se_test_seed_clinic(0);
se_clinic_provision();
se_eq(1, count(se_test_db()->rows('tblse_brands')), 'with no brand one is created');
se_eq(SE_CLINIC_NAME, se_test_db()->rows('tblse_brands')[0]['name'], '...with the clinic name');
$mapped = array_map(function ($r) { return (int) $r['staff_id']; }, se_test_db()->rows('tblse_staff_brands'));
sort($mapped);
se_eq([1, 30, 40], $mapped, '...and staff are mapped to it');

se_test_seed_clinic(2);
se_clinic_provision();
$names = array_map(function ($r) { return $r['name']; }, se_test_db()->rows('tblse_brands'));
se_eq(['TurquAI CRM 1', 'TurquAI CRM 2'], $names, 'with two brands neither is renamed');
$leads = [];
foreach (se_test_db()->rows('tblleads') as $r) { $leads[(int) $r['id']] = (int) $r['brand_id']; }
se_eq(0, $leads[303], '...and no record is folded');
se_eq(1, count(se_test_db()->rows('tblse_staff_brands')), '...and no staff is auto-mapped');
se_eq(2, count(se_test_db()->rows('tblroles')), 'the roles are still created (they are harmless)');
se_eq('turkish', get_option('active_language'), 'the language default still applies');

se_test_seed_clinic(1);
$GLOBALS['se_test']['options']['active_language'] = '';
se_clinic_provision();
$staff = [];
foreach (se_test_db()->rows('tblstaff') as $r) { $staff[(int) $r['staffid']] = $r; }
se_eq('', $staff[1]['default_language'], 'with no previous default language nothing is pinned');
se_eq('turkish', get_option('active_language'), '...but Turkish is still set');

se_group('Clinic: provisioning edge cases');

se_test_seed_clinic(1);
se_test_db()->seed('tblse_brands', [
    ['id' => 1, 'name' => 'Old', 'slug' => 'old', 'active' => 0],
    ['id' => 2, 'name' => 'TurquAI CRM', 'slug' => 'turquai', 'active' => 1],
]);
se_clinic_provision();
$names = [];
foreach (se_test_db()->rows('tblse_brands') as $r) { $names[(int) $r['id']] = $r['name']; }
se_eq('Old', $names[1], 'an inactive extra brand is left alone');
se_eq(SE_CLINIC_NAME, $names[2], '...and the single ACTIVE brand is the one renamed (same rule as sole-brand detection)');
se_eq(2, se_clinic_sole_brand_id(), 'single-brand mode keys on the active brand');

se_test_seed_clinic(1);
se_test_db()->seed('tblse_brands', [['id' => 1, 'name' => 'Old', 'slug' => 'old', 'active' => 0]]);
se_eq('left', se_clinic_ensure_brand(), 'only inactive brands: nothing is renamed or created');
se_eq(1, count(se_test_db()->rows('tblse_brands')), '...and no brand is added');

se_test_seed_clinic(1);
unset(se_test_db()->tables['tblse_wa_conversations']);
unset(se_test_db()->tables['tblse_appointments']);
$records = se_clinic_backfill_brand_records();
se_eq(0, $records['se_wa_conversations'], 'a table whose module is not activated is skipped, not queried');
se_eq(0, $records['se_appointments'], '...for every optional module table');
se_eq(1, $records['leads'], '...while present tables are still folded');

/* ---------------------------------------------------------------------------
 * 10. Admin header logo override (dark chrome vs light login)
 * ------------------------------------------------------------------------- */
se_group('Clinic: admin header logo override');

if (!defined('FCPATH')) {
    define('FCPATH', rtrim(sys_get_temp_dir(), '/') . '/se_clinic_test_' . getmypid() . '/');
}
@mkdir(FCPATH . 'uploads/company', 0777, true);
file_put_contents(FCPATH . 'uploads/company/alabaster.png', 'x');

$GLOBALS['se_test']['options']['se_clinic_header_logo'] = 'alabaster.png';
se_eq('/uploads/company/alabaster.png', se_clinic_admin_header_logo_url('/stock'),
    'a named, existing header logo overrides the stock admin header URL');

$GLOBALS['se_test']['options']['se_clinic_header_logo'] = 'missing.png';
se_eq('/stock', se_clinic_admin_header_logo_url('/stock'),
    'a named but ABSENT file falls back to the stock URL (never a broken image)');

$GLOBALS['se_test']['options']['se_clinic_header_logo'] = '';
se_eq('/stock', se_clinic_admin_header_logo_url('/stock'),
    'with no option set the stock admin header URL passes through unchanged');

@unlink(FCPATH . 'uploads/company/alabaster.png');
