<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: SE Appointments
Description: Brand-scoped appointment booking and calendar for the SEO Evaluate CRM. Consultation Booked / Held feed the conversion outbox as first-class signals.
Version: 1.0.0
Requires at least: 3.4.1
*/

define('SE_APPOINTMENTS_MODULE_NAME', 'se_appointments');

/*
 * Register the module's language files.
 *
 * THIS WAS MISSING. Without it every _l() call in this module returned its own
 * key, so the sidebar entry read "se_appointments" and the whole manage screen
 * rendered raw keys: se_appt_title, se_appt_start, se_appt_status_scheduled...
 * The strings existed all along; nothing ever loaded them.
 */
register_language_files(SE_APPOINTMENTS_MODULE_NAME, [SE_APPOINTMENTS_MODULE_NAME]);

require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/reminders.php';
require_once __DIR__ . '/availability.php';
require_once __DIR__ . '/gcal.php';

hooks()->add_action('admin_init', 'se_appt_migrate', 1);
hooks()->add_action('admin_init', 'se_appt_permissions');


// Surface appointments as a tab on the lead and customer profiles.
hooks()->add_action('after_lead_tabs_content', 'se_appt_lead_tab_content');

register_activation_hook(SE_APPOINTMENTS_MODULE_NAME, 'se_appt_activation');

function se_appt_activation()
{
    require_once __DIR__ . '/install.php';
    se_appt_migrate();
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

    // Registered by se_core/se_navigation.php as part of the grouped
    // "SEO Evaluate CRM" section, so the SE features appear together rather
    // than scattered through the sidebar. Kept as a no-op for compatibility.
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

    // Brand-scoped, mirroring the WhatsApp lead-tab twin: the lead profile
    // must not surface another tenant's appointment rows just because a
    // rel_id happens to match. Resolve the predicate BEFORE building the
    // query (it may run its own staff-brand lookup).
    $pred = function_exists('se_brand_predicate') ? se_brand_predicate() : '';

    $CI = &get_instance();
    $CI->db->where('rel_type', 'lead')->where('rel_id', $lead_id);

    if ($pred !== '') {
        $CI->db->where($pred, null, false);
    }

    $CI->db->order_by('start_at', 'DESC');
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
