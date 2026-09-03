<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: SE WhatsApp
Description: WhatsApp Cloud API inbox for the Azin Asgari – Kaş Ekimi, İstanbul clinic CRM. Signed webhook receiver, tenant routing, conversations/messages, templates, metering. Live Meta connection is externally gated.
Version: 1.0.0
Requires at least: 3.4.1
*/

define('SE_WHATSAPP_MODULE_NAME', 'se_whatsapp');

/*
 * Register the module's language files. Missing for the same reason as
 * se_appointments: the inbox rendered se_wa_all / se_wa_no_conversations
 * instead of its English strings.
 */
register_language_files(SE_WHATSAPP_MODULE_NAME, [SE_WHATSAPP_MODULE_NAME]);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/outbound.php';
require_once __DIR__ . '/inbox.php';       // Mesajlar list/thread queries (bounded)
require_once __DIR__ . '/templates.php';   // WABA template mirror (sync + status webhooks)
require_once __DIR__ . '/calls.php';       // call log — records calls, never answers them
require_once __DIR__ . '/transport.php';   // registers the live Cloud API sender when wa_token exists

register_activation_hook(SE_WHATSAPP_MODULE_NAME, 'se_whatsapp_activation');

hooks()->add_action('admin_init', 'se_whatsapp_permissions');


// Async: drain webhook events + consume due reminders after core cron tasks.
if (function_exists('se_cron_listener')) { se_cron_listener('se_wa_process_pending'); } else { hooks()->add_action('after_cron_run', 'se_wa_process_pending'); }
if (function_exists('se_cron_listener')) { se_cron_listener('se_wa_consume_due_reminders'); } else { hooks()->add_action('after_cron_run', 'se_wa_consume_due_reminders'); }
if (function_exists('se_cron_listener')) { se_cron_listener('se_wa_out_drain'); } else { hooks()->add_action('after_cron_run', 'se_wa_out_drain'); }
if (function_exists('se_cron_listener')) { se_cron_listener('se_wa_sync_templates_cron'); } else { hooks()->add_action('after_cron_run', 'se_wa_sync_templates_cron'); }   // throttled WABA template re-pull

// Conversation tab on the lead profile.
hooks()->add_action('after_lead_tabs_content', 'se_whatsapp_lead_tab');

function se_whatsapp_activation()
{
    require_once __DIR__ . '/install.php';
}

function se_whatsapp_permissions()
{
    $caps = [
        'view'     => _l('permission_view') . '(' . _l('permission_global') . ')',
        'create'   => _l('permission_create'),
        'edit'     => _l('permission_edit'),
        'delete'   => _l('permission_delete'),
    ];
    register_staff_capabilities('se_whatsapp', ['capabilities' => $caps], _l('se_whatsapp'));
    // Configuration (numbers/tokens/templates) is a separate, stricter capability.
    register_staff_capabilities('se_whatsapp_config', ['capabilities' => ['manage' => _l('permission_edit')]], _l('se_whatsapp_config'));
}

function se_whatsapp_menu()
{
    $CI = &get_instance();
    // Registered by se_core/se_navigation.php as part of the grouped
    // "SEO Evaluate CRM" section. Kept as a no-op for compatibility.
}

/** Read-only conversation summary on the lead profile. */
function se_whatsapp_lead_tab($lead)
{
    $lead_id = is_array($lead) ? (int) ($lead['id'] ?? 0) : (int) ($lead->id ?? 0);
    if (!$lead_id) {
        return;
    }
    $CI = &get_instance();
    // Brand-scoped: the lead profile must not surface another tenant's thread
    // just because a lead_id happens to match.
    $CI->db->where('lead_id', $lead_id);

    if (function_exists('se_brand_predicate') && ($pred = se_brand_predicate()) !== '') {
        $CI->db->where($pred, null, false);
    }

    $CI->db->order_by('last_inbound_at', 'DESC');
    $convos = $CI->db->get(db_prefix() . 'se_wa_conversations')->result_array();
    if (empty($convos)) {
        return;
    }
    echo '<div class="panel_s"><div class="panel-body"><h5>' . _l('se_whatsapp') . '</h5><ul class="list-unstyled">';
    foreach ($convos as $c) {
        echo '<li>' . html_escape($c['wa_user_id']) . ' &mdash; '
           . html_escape($c['state']) . ' (' . (int) $c['unread_count'] . ' unread)</li>';
    }
    echo '</ul></div></div>';
}
