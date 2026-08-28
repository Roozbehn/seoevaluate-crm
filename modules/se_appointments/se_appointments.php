<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: SE Appointments
Description: Brand-scoped appointment booking and calendar for the SEO Evaluate CRM. Consultation Booked / Held feed the conversion outbox as first-class signals.
Version: 1.0.0
Requires at least: 3.4.1
*/

define('SE_APPOINTMENTS_MODULE_NAME', 'se_appointments');

hooks()->add_action('admin_init', 'se_appt_permissions');
hooks()->add_action('admin_init', 'se_appt_menu');

// Surface appointments as a tab on the lead and customer profiles.
hooks()->add_action('after_lead_tabs_content', 'se_appt_lead_tab_content');

register_activation_hook(SE_APPOINTMENTS_MODULE_NAME, 'se_appt_activation');

function se_appt_activation()
{
    require_once __DIR__ . '/install.php';
}

function se_appt_permissions()
{
    register_staff_capabilities('se_appointments', [
        'capabilities' => [
            'view'   => _l('permission_view') . '(' . _l('permission_global') . ')',
            'create' => _l('permission_create'),
            'edit'   => _l('permission_edit'),
            'delete' => _l('permission_delete'),
        ],
    ], _l('se_appointments'));
}

function se_appt_menu()
{
    $CI = &get_instance();

    if (staff_can('view', 'se_appointments')) {
        $CI->app_menu->add_sidebar_menu_item('se-appointments', [
            'name'     => _l('se_appointments'),
            'href'     => admin_url('se_appointments/index'),
            'icon'     => 'fa fa-calendar-check-o',
            'position' => 26,
        ]);
    }
}

/**
 * Renders a lightweight list of a lead's appointments inside the lead profile.
 * Kept read-only here; full editing lives in the module's own screens.
 */
function se_appt_lead_tab_content($lead)
{
    $lead_id = 0;
    if (is_array($lead) && !empty($lead['id'])) {
        $lead_id = (int) $lead['id'];
    } elseif (is_object($lead) && !empty($lead->id)) {
        $lead_id = (int) $lead->id;
    }
    if (!$lead_id) {
        return;
    }

    $CI = &get_instance();
    $CI->db->where('rel_type', 'lead')->where('rel_id', $lead_id)->order_by('start_at', 'DESC');
    $appointments = $CI->db->get(db_prefix() . 'se_appointments')->result_array();

    if (empty($appointments)) {
        return;
    }

    echo '<div class="panel_s"><div class="panel-body"><h5>' . _l('se_appointments') . '</h5><ul class="list-unstyled">';
    foreach ($appointments as $a) {
        echo '<li>' . _dt($a['start_at']) . ' &mdash; ' . html_escape($a['title'])
           . ' <span class="label label-default">' . html_escape($a['status']) . '</span></li>';
    }
    echo '</ul></div></div>';
}
