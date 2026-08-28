<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: SE Core
Description: Multi-brand foundation for the SEO Evaluate CRM - brand registry, staff-to-brand scoping, attribution fields and the ad-platform conversion outbox.
Version: 1.0.0
Requires at least: 3.4.1
*/

define('SE_CORE_MODULE_NAME', 'se_core');

$CI = &get_instance();
$CI->load->helper(SE_CORE_MODULE_NAME . '/se_core');

register_language_files(SE_CORE_MODULE_NAME, [SE_CORE_MODULE_NAME]);
register_activation_hook(SE_CORE_MODULE_NAME, 'se_core_activation_hook');

hooks()->add_action('admin_init', 'se_core_permissions');
hooks()->add_action('admin_init', 'se_core_menu_items');
hooks()->add_action('admin_init', 'se_core_brand_guard');

// Brand scoping. See docs/SCOPING.md in the fork for why each seam is used.
hooks()->add_filter('leads_table_sql_join', 'se_core_scope_leads_table');
hooks()->add_filter('customers_table_sql_join', 'se_core_scope_customers_table');
hooks()->add_action('kanban_query_initiated', 'se_core_scope_kanban');

// Stamp the brand on new records.
hooks()->add_action('lead_created', 'se_core_stamp_lead_brand');
hooks()->add_action('lead_converted_to_customer', 'se_core_carry_brand_to_customer');

function se_core_activation_hook()
{
    require_once __DIR__ . '/install.php';
}

function se_core_permissions()
{
    $capabilities = [];

    $capabilities['capabilities'] = [
        'view'   => _l('permission_view') . '(' . _l('permission_global') . ')',
        'create' => _l('permission_create'),
        'edit'   => _l('permission_edit'),
        'delete' => _l('permission_delete'),
    ];

    register_staff_capabilities('se_brands', $capabilities, _l('se_brands'));
}

function se_core_menu_items()
{
    if (staff_can('view', 'se_brands')) {
        $CI = &get_instance();
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

    if (se_staff_sees_all_brands()) {
        return;
    }

    $ids = se_staff_brand_ids();

    $CI = &get_instance();
    $CI->db->where('(' . db_prefix() . 'leads.brand_id IN (' . implode(',', $ids) . '))');
}

function se_core_stamp_lead_brand($lead_id)
{
    $CI = &get_instance();

    $CI->db->select('brand_id')->where('id', $lead_id);
    $lead = $CI->db->get(db_prefix() . 'leads')->row();

    if (!$lead || (int) $lead->brand_id !== 0) {
        return;
    }

    // A lead created by a staff member who works on exactly one brand belongs
    // to that brand. Anything else stays unassigned and shows in the triage list.
    $ids = se_staff_brand_ids();

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

/**
 * Blocks direct record access across a brand boundary.
 *
 * Scoping the list queries is not enough - /admin/leads/lead/123 loads by id.
 * This runs on every admin request and refuses the ones that reach outside
 * the staff member's brands.
 */
function se_core_brand_guard()
{
    $CI = &get_instance();

    if (!is_staff_logged_in() || se_staff_sees_all_brands()) {
        return;
    }

    $checks = [
        ['leads', 'lead', db_prefix() . 'leads', 'id'],
        ['clients', 'client', db_prefix() . 'clients', 'userid'],
    ];

    $segments = $CI->uri->segment_array();

    foreach ($checks as [$controller, $method, $table, $pk]) {
        if ($CI->uri->segment(2) !== $controller) {
            continue;
        }

        $id = 0;

        if ($CI->uri->segment(3) === $method) {
            $id = (int) $CI->uri->segment(4);
        } elseif ($controller === 'clients' && is_numeric($CI->uri->segment(3))) {
            $id = (int) $CI->uri->segment(3);
        }

        if ($id <= 0) {
            continue;
        }

        $CI->db->select('brand_id')->where($pk, $id);
        $row = $CI->db->get($table)->row();

        if ($row && !se_can_access_brand($row->brand_id)) {
            se_core_deny();
        }
    }
}

function se_core_deny()
{
    if (is_ajax_request()) {
        header('HTTP/1.0 403 Forbidden');
        echo json_encode(['success' => false, 'message' => _l('se_brand_access_denied')]);
        die;
    }

    access_denied(_l('se_brands'));
}
