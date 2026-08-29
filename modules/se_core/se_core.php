<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: SE Core
Description: Foundation of the Azin Asgari – Kaş Ekimi, İstanbul clinic CRM - brand registry, staff-to-brand scoping, clinic mode (lean navigation, roles, provisioning), attribution fields and the ad-platform conversion outbox.
Version: 1.0.0
Requires at least: 3.4.1
*/

define('SE_CORE_MODULE_NAME', 'se_core');

$CI = &get_instance();
$CI->load->helper(SE_CORE_MODULE_NAME . '/se_core');
$CI->load->helper(SE_CORE_MODULE_NAME . '/se_ui');
require_once __DIR__ . '/se_authz.php';
require_once __DIR__ . '/se_secret_provider.php';
require_once __DIR__ . '/se_consent_settings.php';
require_once __DIR__ . '/se_navigation.php';
require_once __DIR__ . '/se_clinic.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/se_consent.php';
require_once __DIR__ . '/se_patients.php';
require_once __DIR__ . '/se_attribution.php';

register_language_files(SE_CORE_MODULE_NAME, [SE_CORE_MODULE_NAME]);
register_activation_hook(SE_CORE_MODULE_NAME, 'se_core_activation_hook');

hooks()->add_action('admin_init', 'se_core_migrate', 1);
hooks()->add_action('admin_init', 'se_clinic_provision', 2);
hooks()->add_action('admin_init', 'se_clinic_dashboard_redirect', 5);
hooks()->add_action('admin_init', 'se_patient_permissions');

hooks()->add_action('admin_init', 'se_core_permissions');
hooks()->add_action('admin_init', 'se_core_menu_items');
hooks()->add_action('admin_init', 'se_nav_register');
hooks()->add_action('admin_init', 'se_authz_request_guard');

// Clinic mode: lean sidebar, reduced quick-create menu, admin-only Setup menu.
// See se_clinic.php. Priority 1000 runs after the Menu Builder module (999).
hooks()->add_filter('sidebar_menu_items', 'se_clinic_filter_sidebar', 1000);
hooks()->add_filter('quick_actions_links', 'se_clinic_filter_quick_actions', 1000);
hooks()->add_filter('show_setup_menu', 'se_clinic_show_setup_menu');
hooks()->add_filter('admin_header_logo_url', 'se_clinic_admin_header_logo_url');

// Brand scoping. See docs/SCOPING.md in the fork for why each seam is used.
hooks()->add_filter('leads_table_sql_join', 'se_core_scope_leads_table');
hooks()->add_filter('customers_table_sql_join', 'se_core_scope_customers_table');
hooks()->add_action('kanban_query_initiated', 'se_core_scope_kanban');

// Stamp the brand on new records. In single-brand (clinic) mode the second
// hook stamps whatever the first one left at brand 0 — including admin-created
// leads and webhook leads, which the multi-brand rule leaves for triage.
hooks()->add_action('lead_created', 'se_core_stamp_lead_brand');
hooks()->add_action('lead_created', 'se_clinic_stamp_lead', 20);
hooks()->add_action('lead_converted_to_customer', 'se_core_carry_brand_to_customer');

// Single-brand mode: a new staff member belongs to the clinic.
hooks()->add_action('staff_member_created', 'se_clinic_map_staff');

function se_core_activation_hook()
{
    require_once __DIR__ . '/install.php';
    se_core_migrate();
}

/**
 * Three separate features, deliberately.
 *
 * Previously a single `se_brands.view` capability meant "read brand config",
 * "open reports" AND "reach every tenant's data" at once, so any reporting
 * user became a global tenant user. Splitting them is the fix; nothing here
 * grants cross-brand reach except se_tenancy.all_brands.
 */
function se_core_permissions()
{
    // Brand CONFIGURATION only. Grants no access to another brand's records.
    register_staff_capabilities('se_brands', [
        'capabilities' => [
            'view'   => _l('se_perm_brand_config_view'),
            'create' => _l('permission_create'),
            'edit'   => _l('permission_edit'),
            'delete' => _l('permission_delete'),
        ],
    ], _l('se_perm_feature_brands'));

    // Reporting screens. Reports stay restricted to the staff member's brands.
    register_staff_capabilities('se_reports', [
        'capabilities' => [
            'view' => _l('se_perm_reports_view'),
        ],
    ], _l('se_perm_feature_reports'));

    // The only capabilities that widen the tenant boundary. Grant deliberately.
    register_staff_capabilities('se_tenancy', [
        'capabilities' => [
            SE_CAP_ALL_BRANDS => _l('se_perm_all_brands'),
            SE_CAP_TRIAGE     => _l('se_perm_triage'),
        ],
    ], _l('se_perm_feature_tenancy'));

    // Consent wording, on its own: the clinic owner maintains it without
    // holding se_brands.view, which would also unlock credentials and the
    // Meta/Google configuration screens.
    register_staff_capabilities(SE_FEATURE_CONSENT, [
        'capabilities' => [
            SE_CAP_CONSENT_MANAGE => _l('se_perm_consent_manage'),
        ],
    ], _l('se_perm_feature_consent'));
}

function se_core_menu_items()
{
    $CI = &get_instance();

    // Brand configuration lives under Setup and needs the config capability.
    // Everything else is registered as flat sidebar items plus one
    // Integrations group by se_nav_register() in se_navigation.php.
    if (se_staff_can_configure_brands()) {
        $CI->app_menu->add_setup_menu_item('se-brands', [
            'name'     => _l('se_brands'),
            'href'     => admin_url('se_core/brands'),
            'position' => 31,
        ]);
    }
}

/**
 * Injects brand scoping into the leads datatable.
 *
 * Perfex exposes no where-filter on this table, so the restriction is
 * expressed as an INNER JOIN against the brands the staff member may see.
 * A lead whose brand is not in that set produces no joined row and drops out.
 */
function se_core_scope_leads_table($join)
{
    if ($sql = se_scope_join_sql(db_prefix() . 'leads')) {
        $join[] = $sql;
    }

    return $join;
}

function se_core_scope_customers_table($join)
{
    if ($sql = se_scope_join_sql(db_prefix() . 'clients')) {
        $join[] = $sql;
    }

    return $join;
}

/**
 * Scopes the leads kanban.
 *
 * The kanban classes carry no hooks of their own, so patches/0002 adds the
 * kanban_query_initiated action to AbstractKanban. The query builder is the
 * shared CI singleton, so a where() added here lands on the in-flight query.
 */
function se_core_scope_kanban($kanban)
{
    if (!($kanban instanceof \app\services\leads\LeadsKanban)) {
        return;
    }

    // Fail closed: an unmapped staff member gets 1=0, not `IN ()`.
    se_apply_scope_in(db_prefix() . 'leads.brand_id');
}

function se_core_stamp_lead_brand($lead_id)
{
    // Forms.php fires lead_created with ['lead_id' => id, 'web_to_lead_form' => true];
    // the model and the cron importer pass the bare id.
    $lead_id = se_clinic_lead_id_from_hook($lead_id);

    if ($lead_id <= 0) {
        return;
    }

    $CI = &get_instance();

    $CI->db->select('brand_id')->where('id', $lead_id);
    $lead = $CI->db->get(db_prefix() . 'leads')->row();

    if (!$lead || (int) $lead->brand_id !== 0) {
        return;
    }

    // A lead created by a staff member who works on exactly one brand belongs
    // to that brand. Anything else stays unassigned and shows in the triage list.
    //
    // This reads the REAL brand set. The row-visibility set folds in the brand-0
    // triage bucket, so a single-brand staff member used to look like a
    // two-brand user here and their leads were never stamped at all.
    $ids = se_staff_real_brand_ids();

    if (count($ids) === 1 && !se_staff_sees_all_brands()) {
        $CI->db->where('id', $lead_id)->update(db_prefix() . 'leads', ['brand_id' => (int) $ids[0]]);
    }
}

function se_core_carry_brand_to_customer($data)
{
    $lead_id     = is_array($data) && isset($data['lead_id']) ? (int) $data['lead_id'] : 0;
    $customer_id = is_array($data) && isset($data['client_id']) ? (int) $data['client_id'] : 0;

    if (!$lead_id || !$customer_id) {
        return;
    }

    $CI = &get_instance();

    $CI->db->select('brand_id')->where('id', $lead_id);
    $lead = $CI->db->get(db_prefix() . 'leads')->row();

    if ($lead) {
        $CI->db->where('userid', $customer_id)
               ->update(db_prefix() . 'clients', ['brand_id' => (int) $lead->brand_id]);
    }
}

function se_core_deny()
{
    $CI = &get_instance();

    if (isset($CI->input) && $CI->input->is_ajax_request()) {
        header('HTTP/1.0 403 Forbidden');
        echo json_encode(['success' => false, 'message' => _l('se_brand_access_denied')]);
        die;
    }

    access_denied(_l('se_brands'));
}

/*
 * SE modules - batch 2 (conversion pipeline).
 * Credential-gated; no live public routes are enabled here.
 * se_meta_leadgen.php is wired but its live Graph fetch/send stays gated on a
 * Meta Page token + App Review; se_whatsapp is now its own module.
 */
foreach (['se_asset_registry.php', 'se_outbox_snapshot.php', 'se_outbox.php', 'se_capi.php', 'se_google_dm.php',
          'se_meta_leadgen.php', 'se_webhook_state.php', 'se_reporting.php', 'se_google_auth.php',
          'se_outbox_ui.php', 'se_integration_ui.php'] as $__se_b2) {
    $__se_b2_path = __DIR__ . '/' . $__se_b2;
    if (is_file($__se_b2_path)) {
        require_once $__se_b2_path;
    }
}
