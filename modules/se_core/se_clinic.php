<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Clinic mode — the single-clinic simplification of the SE CRM.
 *
 * WHAT THIS IS
 * ------------
 * The CRM was built as a multi-brand agency tool. It now serves ONE clinic
 * ("Azin Asgari – Kaş Ekimi, İstanbul") with three staff accounts: an
 * administrator, the clinic owner and a sales person. Everything in this file
 * exists to make that shape the default rather than something an admin has to
 * assemble by hand on every install:
 *
 *   1. A LEAN SIDEBAR. The Perfex modules a clinic never touches (Sales,
 *      Subscriptions, Expenses, Contracts, Projects, Tasks, Support, Estimate
 *      Request, Knowledge Base, Utilities, core Reports) are removed through
 *      the `sidebar_menu_items` filter, and the remaining items are laid out
 *      flat: Dashboard, Leads, Patients, Appointments, WhatsApp, Customers,
 *      Reports, then one admin-only "Integrations" group. The quick-create
 *      menu is reduced to Lead / Customer / Staff and the Setup menu becomes
 *      admin-only.
 *
 *   2. ONE DASHBOARD. `/admin` (Perfex's invoice/ticket/project dashboard)
 *      redirects to the clinic dashboard, which every clinic role may open.
 *      Integration cards and system warnings stay with the roles that can act
 *      on them.
 *
 *   3. SINGLE-BRAND BEHAVIOUR. When exactly one active brand exists:
 *        - every new staff member is mapped to it automatically (the tenancy
 *          boundary is still enforced — it just has one side);
 *        - every new lead is stamped with it, whoever created it (the
 *          multi-brand stamping rule deliberately skips admins, which in a
 *          one-clinic CRM left admin-created leads invisible to staff);
 *        - the brand badge row disappears from the dashboard.
 *      With two or more brands none of this fires and the CRM behaves exactly
 *      as before, so clinic mode is a property of the data, not a switch that
 *      can be left on by mistake.
 *
 *   4. ONE-SHOT PROVISIONING (se_clinic_provision). Versioned like the schema
 *      migrations and locked the same way: names the brand, sets the company
 *      name and Turkish as the default UI language (existing accounts keep
 *      English), seeds the "Clinic Owner" and "Sales" roles, and folds any
 *      brand-0 records and unmapped staff into the sole brand. Re-running it
 *      does nothing; it never overwrites an edit the admin has made since.
 *
 *   5. A `se_consent.manage` capability so the clinic owner can maintain the
 *      consent wording without holding `se_brands.view`, which would also
 *      unlock credentials, Meta and Google configuration.
 *
 * Every function here is a plain function over the query builder so the
 * fake-DB suite (tests/test_clinic.php) exercises the real code.
 */

define('SE_CLINIC_NAME', 'Azin Asgari – Kaş Ekimi, İstanbul');
define('SE_CLINIC_SLUG', 'azin-asgari');
define('SE_CLINIC_LANGUAGE', 'turkish');
define('SE_CLINIC_PROVISION_VERSION', 1);

define('SE_FEATURE_CONSENT', 'se_consent');
define('SE_CAP_CONSENT_MANAGE', 'manage');

/* ---------------------------------------------------------------------------
 * Single-brand detection
 * ------------------------------------------------------------------------- */

function &se_clinic_cache()
{
    static $cache = [];

    return $cache;
}

/** Drop the memoized sole-brand answer (tests, and after provisioning). */
function se_clinic_reset_cache()
{
    $cache = &se_clinic_cache();
    $cache = [];
}

/**
 * The id of the ONLY active brand, or 0 when there are none or several.
 *
 * Standalone query (not the shared builder state) for the same reason as
 * se_staff_real_brand_ids(): callers may be mid-build on the shared builder.
 */
function se_clinic_sole_brand_id()
{
    $cache = &se_clinic_cache();

    if (!array_key_exists('sole_brand', $cache)) {
        $CI = &get_instance();

        // Raw SQL on purpose: the lead_created hook and the sidebar filter can
        // run while another caller has select()/where() pending on the shared
        // query builder, and the builder would fold that state into this query.
        $rows = $CI->db->query(
            'SELECT id FROM `' . db_prefix() . 'se_brands` WHERE active = 1 ORDER BY id ASC LIMIT 2'
        )->result_array();

        $cache['sole_brand'] = count($rows) === 1 ? (int) $rows[0]['id'] : 0;
    }

    return $cache['sole_brand'];
}

/**
 * The lead id a `lead_created` listener was handed.
 *
 * Leads_model and the cron importer pass the integer id; the public
 * web-to-lead controller (Forms.php) passes `['lead_id' => id,
 * 'web_to_lead_form' => true]`. Casting that array to int yields 1, which
 * would stamp lead #1 and leave the real one in triage.
 */
function se_clinic_lead_id_from_hook($arg)
{
    if (is_array($arg)) {
        return isset($arg['lead_id']) ? (int) $arg['lead_id'] : 0;
    }

    return (int) $arg;
}

function se_clinic_is_single_brand()
{
    return se_clinic_sole_brand_id() > 0;
}

/* ---------------------------------------------------------------------------
 * Capabilities
 * ------------------------------------------------------------------------- */

/** May the current staff member edit the consent wording? */
function se_clinic_can_manage_consent()
{
    return se_staff_can_configure_brands() || staff_can(SE_CAP_CONSENT_MANAGE, SE_FEATURE_CONSENT);
}

/**
 * May the current staff member open the clinic dashboard?
 *
 * Any clinic role may: the cards are brand-scoped counts of the screens the
 * staff member can already open. Integration cards are gated separately.
 */
function se_clinic_can_open_dashboard()
{
    return se_staff_can_report()
        || se_staff_can_configure_brands()
        || staff_can('view', 'se_patients')
        || staff_can('view', 'se_appointments')
        || staff_can('view', 'se_whatsapp')
        || staff_can('view', 'se_instagram');
}

/** May the current staff member see the integration cards and system warnings? */
function se_clinic_can_see_integration_cards()
{
    return se_staff_can_report() || se_staff_can_configure_brands();
}

/* ---------------------------------------------------------------------------
 * Sidebar, quick actions, setup menu
 * ------------------------------------------------------------------------- */

/** Core Perfex sidebar slugs a clinic never uses. */
function se_clinic_hidden_sidebar_slugs()
{
    return [
        'sales', 'subscriptions', 'expenses', 'contracts', 'projects', 'tasks',
        'support', 'estimate_request', 'knowledge-base', 'utilities', 'reports',
    ];
}

/**
 * Positions of the items that survive, so the sidebar reads in working order.
 * Core items keep their slugs (the theme's active-item logic keys on them).
 */
function se_clinic_sidebar_positions()
{
    return [
        'dashboard'       => 1,
        'leads'           => 2,
        'se-patients'     => 3,
        'se-appointments' => 4,
        'se-whatsapp'     => 5,
        'se-instagram'    => 6,
        'customers'       => 7,
        'se-reports'      => 8,
        'se-integrations' => 9,
        'se-consent'      => 10, // only when it stands alone (owner)
    ];
}

/**
 * `sidebar_menu_items` filter. Registered at priority 1000 so it runs after
 * the Menu Builder module (999): an `aside_menu_active` option that re-enables
 * or repositions a hidden core item is overridden here. (An item the Menu
 * Builder disabled is already gone by the time this runs; it is not revived.)
 */
function se_clinic_filter_sidebar($items)
{
    if (!is_array($items)) {
        return $items;
    }

    foreach (se_clinic_hidden_sidebar_slugs() as $slug) {
        unset($items[$slug]);
    }

    foreach (se_clinic_sidebar_positions() as $slug => $position) {
        if (isset($items[$slug])) {
            $items[$slug]['position'] = $position;
        }
    }

    // The top "Dashboard" item IS the clinic dashboard for anyone who may open it.
    if (isset($items['dashboard']) && se_clinic_can_open_dashboard()) {
        $items['dashboard']['href'] = admin_url('se_core/se_dashboard');
    }

    return $items;
}

/**
 * `quick_actions_links` filter: only Lead, Customer and Staff member remain.
 * Perfex identifies each link by the permission it checks, so filter on that.
 *
 * The Lead link carries `permission => 'is_staff_member'`, which the header
 * tests as staff_can('create', 'is_staff_member') — a plain permission lookup
 * that no non-admin passes, so stock Perfex hides "Lead" from every ordinary
 * staff member. The clinic roles live in the leads pipeline, so for a staff
 * member the key is dropped and the link shows.
 */
function se_clinic_filter_quick_actions($items)
{
    if (!is_array($items)) {
        return $items;
    }

    $keep = ['is_staff_member', 'customers', 'staff'];
    $out  = [];

    foreach ($items as $item) {
        if (!isset($item['permission']) || !in_array($item['permission'], $keep, true)) {
            continue;
        }

        if ($item['permission'] === 'is_staff_member' && is_staff_member()) {
            unset($item['permission']);
        }

        $out[] = $item;
    }

    return $out;
}

/** `show_setup_menu` filter: the Setup sidebar is for administrators only. */
function se_clinic_show_setup_menu($show)
{
    return $show && is_admin();
}

/* ---------------------------------------------------------------------------
 * Admin header logo
 * ------------------------------------------------------------------------- */

/**
 * The Perfex admin chrome (perfex_dark_theme) is dark, but the login screen
 * sits on a light background and shares the same `company_logo_dark` option.
 * One stored value cannot suit both. So `company_logo_dark` keeps the
 * dark-ink lockup that reads on the light login, and the DARK admin header is
 * overridden here with a light-ink lockup when one is named in the
 * `se_clinic_header_logo` option (a file under uploads/company/). Data-driven:
 * no filename is hard-coded, and clearing the option restores stock behaviour.
 */
function se_clinic_admin_header_logo_url($url)
{
    $file = (string) get_option('se_clinic_header_logo');

    if ($file !== '' && is_file(FCPATH . 'uploads/company/' . $file)) {
        return base_url('uploads/company/' . $file);
    }

    return $url;
}

/* ---------------------------------------------------------------------------
 * Dashboard redirect
 * ------------------------------------------------------------------------- */

/**
 * Is this request Perfex's own dashboard (GET /admin, or /admin/dashboard)?
 * Pure so the fake suite can assert it; the module controllers have other
 * class names, so `Se_dashboard` is never mistaken for it.
 */
function se_clinic_is_core_dashboard_request($router_class, $router_method, $is_ajax)
{
    if ($is_ajax) {
        return false;
    }

    return strtolower((string) $router_class) === 'dashboard'
        && strtolower((string) $router_method) === 'index';
}

/** admin_init: land every clinic role on the clinic dashboard. */
function se_clinic_dashboard_redirect()
{
    $CI = &get_instance();

    if (!isset($CI->router) || !is_staff_logged_in()) {
        return;
    }

    $is_ajax = isset($CI->input) && method_exists($CI->input, 'is_ajax_request') && $CI->input->is_ajax_request();

    if (!se_clinic_is_core_dashboard_request($CI->router->class, $CI->router->method, $is_ajax)) {
        return;
    }

    if (se_clinic_can_open_dashboard()) {
        redirect(admin_url('se_core/se_dashboard'));
    }
}

/* ---------------------------------------------------------------------------
 * Single-brand record and staff handling
 * ------------------------------------------------------------------------- */

/**
 * lead_created: in single-brand mode a brand-0 lead belongs to the clinic,
 * whoever (or whatever webhook) created it. Runs AFTER the multi-brand
 * stamping rule and only touches rows that rule left at 0.
 */
function se_clinic_stamp_lead($lead)
{
    $lead_id = se_clinic_lead_id_from_hook($lead);
    $brand   = se_clinic_sole_brand_id();

    if ($brand === 0 || $lead_id <= 0) {
        return false;
    }

    $CI = &get_instance();
    $CI->db->where('id', $lead_id)->where('brand_id', 0)
           ->update(db_prefix() . 'leads', ['brand_id' => $brand]);

    return $CI->db->affected_rows() > 0;
}

/** Map one staff member to the sole brand. Idempotent. */
function se_clinic_map_staff($staff_id)
{
    $brand    = se_clinic_sole_brand_id();
    $staff_id = (int) $staff_id;

    if ($brand === 0 || $staff_id <= 0) {
        return false;
    }

    $CI = &get_instance();

    $CI->db->where('staff_id', $staff_id)->where('brand_id', $brand);

    if ($CI->db->count_all_results(db_prefix() . 'se_staff_brands') > 0) {
        return false;
    }

    $CI->db->insert(db_prefix() . 'se_staff_brands', ['staff_id' => $staff_id, 'brand_id' => $brand]);

    return true;
}

/** Map every active staff member to the sole brand. Returns how many were added. */
function se_clinic_backfill_staff_mappings()
{
    if (!se_clinic_is_single_brand()) {
        return 0;
    }

    $CI = &get_instance();

    $CI->db->select('staffid')->where('active', 1);
    $rows  = $CI->db->get(db_prefix() . 'staff')->result_array();
    $added = 0;

    foreach ($rows as $row) {
        if (se_clinic_map_staff((int) $row['staffid'])) {
            $added++;
        }
    }

    return $added;
}

/** Tables whose brand-0 rows belong to the clinic once there is only one brand. */
function se_clinic_brand_scoped_tables()
{
    return ['leads', 'clients', 'se_patients', 'se_appointments', 'se_wa_conversations'];
}

/** Fold every brand-0 record into the sole brand. Returns rows changed per table. */
function se_clinic_backfill_brand_records()
{
    $brand = se_clinic_sole_brand_id();
    $out   = [];

    if ($brand === 0) {
        return $out;
    }

    $CI = &get_instance();

    foreach (se_clinic_brand_scoped_tables() as $table) {
        // se_appointments / se_whatsapp are separate modules: skip a table
        // whose module has not been activated rather than abort provisioning.
        if (!$CI->db->table_exists(db_prefix() . $table)) {
            $out[$table] = 0;
            continue;
        }

        $CI->db->where('brand_id', 0)->update(db_prefix() . $table, ['brand_id' => $brand]);
        $out[$table] = (int) $CI->db->affected_rows();
    }

    return $out;
}

/* ---------------------------------------------------------------------------
 * Provisioning
 * ------------------------------------------------------------------------- */

/**
 * The two non-admin roles, as Perfex stores them (feature => capabilities).
 *
 * Neither holds se_brands.* (integration configuration), se_tenancy.*
 * (cross-brand reach) or any Perfex finance/project capability. The owner
 * may delete and report; sales may not.
 */
function se_clinic_role_definitions()
{
    return [
        [
            'name'        => 'Clinic Owner',
            'permissions' => [
                'customers'       => ['view', 'create', 'edit', 'delete'],
                'leads'           => ['view', 'delete'],
                'se_patients'     => ['view', 'create', 'edit', 'delete'],
                'se_appointments' => ['view', 'create', 'edit', 'delete'],
                'se_whatsapp'     => ['view', 'create', 'edit', 'delete'],
                'se_instagram'    => ['view', 'create', 'edit', 'delete'],
                'se_reports'      => ['view'],
                SE_FEATURE_CONSENT => [SE_CAP_CONSENT_MANAGE],
            ],
        ],
        [
            'name'        => 'Sales',
            'permissions' => [
                'customers'       => ['view', 'create', 'edit'],
                'leads'           => ['view'],
                'se_patients'     => ['view', 'create', 'edit'],
                'se_appointments' => ['view', 'create', 'edit'],
                'se_whatsapp'     => ['view', 'create', 'edit'],
                'se_instagram'    => ['view', 'create', 'edit'],
            ],
        ],
    ];
}

/** Create a role by name unless one already exists. Returns true when created. */
function se_clinic_ensure_role($name, array $permissions)
{
    $CI = &get_instance();

    $CI->db->where('name', $name);

    if ($CI->db->count_all_results(db_prefix() . 'roles') > 0) {
        return false;
    }

    $CI->db->insert(db_prefix() . 'roles', [
        'name'        => $name,
        'permissions' => serialize($permissions),
    ]);

    return true;
}

/**
 * Name the brand. One brand: rename it. None: create it. Several: leave them —
 * that is a multi-brand install and not this clinic's.
 *
 * @return string one of created|renamed|left
 */
function se_clinic_ensure_brand()
{
    $CI = &get_instance();

    $CI->db->select('id, name, slug, active')->order_by('id', 'ASC');
    $rows = $CI->db->get(db_prefix() . 'se_brands')->result_array();

    // The same rule as se_clinic_sole_brand_id(): exactly one ACTIVE brand.
    $active = array_values(array_filter($rows, function ($r) { return (int) $r['active'] === 1; }));

    if (count($active) === 1) {
        $CI->db->where('id', (int) $active[0]['id'])->update(db_prefix() . 'se_brands', [
            'name' => SE_CLINIC_NAME,
            'slug' => SE_CLINIC_SLUG,
        ]);

        return 'renamed';
    }

    if (count($rows) === 0) {
        $CI->db->insert(db_prefix() . 'se_brands', [
            'name'         => SE_CLINIC_NAME,
            'slug'         => SE_CLINIC_SLUG,
            'active'       => 1,
            'date_created' => date('Y-m-d H:i:s'),
        ]);

        return 'created';
    }

    return 'left';   // several active brands, or only inactive ones: not this clinic's shape
}

/**
 * Pin every EXISTING account to the language it has been using, then make the
 * clinic language the default for accounts created from now on. Without the
 * first step the administrator's UI would flip language on the next request.
 *
 * @return int accounts pinned
 */
function se_clinic_set_default_language()
{
    $CI      = &get_instance();
    $current = (string) get_option('active_language');
    $pinned  = 0;

    if ($current !== '' && $current !== SE_CLINIC_LANGUAGE) {
        $CI->db->select('staffid, default_language');
        $rows = $CI->db->get(db_prefix() . 'staff')->result_array();

        foreach ($rows as $row) {
            if ((string) ($row['default_language'] ?? '') === '') {
                $CI->db->where('staffid', (int) $row['staffid'])
                       ->update(db_prefix() . 'staff', ['default_language' => $current]);
                $pinned++;
            }
        }
    }

    update_option('active_language', SE_CLINIC_LANGUAGE);

    return $pinned;
}

/** Has provisioning already run at this version? */
function se_clinic_provisioned()
{
    return (int) get_option('se_clinic_provision_version') >= SE_CLINIC_PROVISION_VERSION;
}

/**
 * The provisioning steps, in order. Separated from the runtime entry so the
 * fake suite can run them without a lock; every step is idempotent.
 *
 * @return array summary of what changed
 */
function se_clinic_provision_steps()
{
    $summary = ['brand' => se_clinic_ensure_brand(), 'roles' => [], 'staff_mapped' => 0, 'records' => []];

    se_clinic_reset_cache();

    foreach (se_clinic_role_definitions() as $role) {
        if (se_clinic_ensure_role($role['name'], $role['permissions'])) {
            $summary['roles'][] = $role['name'];
        }
    }

    update_option('companyname', SE_CLINIC_NAME);
    $summary['language_pinned'] = se_clinic_set_default_language();

    $summary['records']      = se_clinic_backfill_brand_records();
    $summary['staff_mapped'] = se_clinic_backfill_staff_mappings();

    update_option('se_clinic_provision_version', SE_CLINIC_PROVISION_VERSION);

    return $summary;
}

/**
 * Runtime entry: admin_init, after se_core_migrate(). Runs once per version,
 * only on a fully migrated schema, serialised with the same advisory-lock
 * pattern as the schema migration.
 */
function se_clinic_provision()
{
    if (se_clinic_provisioned()) {
        return true;
    }

    if ((int) get_option('se_core_schema_version') < SE_CORE_SCHEMA_VERSION) {
        return false; // schema first; the next request retries
    }

    $CI       = &get_instance();
    $lockName = 'se_clinic_provision_' . md5(db_prefix() . SE_CLINIC_PROVISION_VERSION);
    $lock     = $CI->db->query('SELECT GET_LOCK(' . $CI->db->escape($lockName) . ', 10) AS l')->row();

    if (!$lock || (int) $lock->l !== 1) {
        return false;
    }

    try {
        if (se_clinic_provisioned()) {
            return true;
        }

        $summary = se_clinic_provision_steps();

        log_activity('Clinic provisioning v' . SE_CLINIC_PROVISION_VERSION . ' applied: brand '
            . $summary['brand'] . ', roles created ' . count($summary['roles'])
            . ', staff mapped ' . $summary['staff_mapped']);

        return true;
    } finally {
        $CI->db->query('SELECT RELEASE_LOCK(' . $CI->db->escape($lockName) . ')');
    }
}
