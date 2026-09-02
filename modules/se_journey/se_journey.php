<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: SE Journey
Description: Staff-supervised eyebrow-transplant patient journey for the Azin Asgari – Kaş Ekimi, İstanbul clinic CRM: WhatsApp enquiry → consent → secure intake form → photographs → human review → approved quote → consultation → procedure → aftercare. Extends se_whatsapp / se_core / se_appointments; never a second inbox, patient table or outbox.
Version: 1.0.0
Requires at least: 3.4.1
*/

define('SE_JOURNEY_MODULE', 'se_journey');

register_language_files(SE_JOURNEY_MODULE, [SE_JOURNEY_MODULE]);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/messaging.php';
require_once __DIR__ . '/intake.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/review.php';
require_once __DIR__ . '/consultation.php';
require_once __DIR__ . '/leadsync.php';
require_once __DIR__ . '/flows.php';
require_once __DIR__ . '/aftercare.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/ui.php';

register_activation_hook(SE_JOURNEY_MODULE, 'se_journey_activation');

hooks()->add_action('admin_init', 'se_journey_permissions');
hooks()->add_action('admin_init', 'se_journey_seed_for_clinic', 3);
hooks()->add_action('admin_init', 'se_journey_grant_clinic_roles', 4);

// Scheduled work rides the existing Perfex cron (15 min) — reminders,
// aftercare, parked media, appointment sync. Inbound reactions are immediate
// (they run inside the WhatsApp event drain, which the per-minute
// dispatcher already calls).
hooks()->add_action('after_cron_run', 'se_journey_cron');

// Journey summary on the lead profile.
hooks()->add_action('after_lead_tabs_content', 'se_journey_lead_tab');

function se_journey_activation()
{
    require_once __DIR__ . '/install.php';
}

function se_journey_permissions()
{
    $caps = [];
    foreach (se_journey_capabilities() as $cap => $labelKey) {
        $caps[$cap] = _l($labelKey);
    }
    register_staff_capabilities(SE_JOURNEY_FEATURE, ['capabilities' => $caps], _l('se_journeys'));
}

/**
 * Seed the template registry for the sole clinic brand (idempotent, cheap).
 * Keyed on the registry's signature (names + content versions), so a
 * definition added or bumped in a later release is registered on the next
 * admin page load — the first version used a one-shot flag and left new
 * definitions unregistered.
 */
function se_journey_seed_for_clinic()
{
    if (!function_exists('se_clinic_sole_brand_id')) {
        return;
    }
    $brand = (int) se_clinic_sole_brand_id();
    if ($brand > 0 && (string) get_option('se_journey_templates_seeded_' . $brand) !== se_journey_template_registry_signature()) {
        se_journey_seed_templates($brand);
        update_option('se_journey_templates_seeded_' . $brand, se_journey_template_registry_signature());
    }
}

/**
 * One-shot (versioned) grant of the journey capabilities to the seeded clinic
 * roles. Clinic provisioning ran before this module existed, so the existing
 * "Clinic Owner" / "Sales" rows would otherwise never receive the new feature.
 * Only ADDS the se_journey feature keys; never removes or rewrites anything
 * else in the role, never touches per-staff permissions.
 */
function se_journey_grant_clinic_roles()
{
    if ((int) get_option('se_journey_roles_version') >= 1 || !function_exists('se_clinic_role_definitions')) {
        return 0;
    }
    $CI = &get_instance();
    $changed = 0;
    foreach (se_clinic_role_definitions() as $def) {
        if (empty($def['permissions'][SE_JOURNEY_FEATURE])) {
            continue;
        }
        $CI->db->where('name', $def['name']);
        $role = $CI->db->get(db_prefix() . 'roles')->row();
        if (!$role) {
            continue;
        }
        $perms = @unserialize((string) $role->permissions);
        if (!is_array($perms)) {
            $perms = [];
        }
        $have = isset($perms[SE_JOURNEY_FEATURE]) && is_array($perms[SE_JOURNEY_FEATURE]) ? $perms[SE_JOURNEY_FEATURE] : [];
        $merged = array_values(array_unique(array_merge($have, $def['permissions'][SE_JOURNEY_FEATURE])));
        if ($merged === $have) {
            continue;
        }
        $perms[SE_JOURNEY_FEATURE] = $merged;
        $CI->db->where('roleid', (int) $role->roleid)->update(db_prefix() . 'roles', ['permissions' => serialize($perms)]);
        $changed++;
    }
    update_option('se_journey_roles_version', 1);
    if ($changed > 0) {
        log_activity('SE journey capabilities granted to ' . $changed . ' clinic role(s)');
    }

    return $changed;
}

/** Every 15 minutes: bounded, idempotent, no network unless a fetcher/transport exists. */
function se_journey_cron()
{
    $out = [];
    foreach (['reminders' => 'se_journey_run_reminders', 'aftercare' => 'se_journey_run_aftercare',
              'parked_media' => 'se_journey_retry_parked_media', 'appointments' => 'se_journey_sync_appointments',
              'media_to_r2' => 'se_journey_media_migrate_to_r2'] as $k => $fn) {
        try {
            $out[$k] = call_user_func($fn);
        } catch (Throwable $e) {
            $out[$k] = 'error:' . get_class($e);
        }
    }
    if (function_exists('se_clinic_sole_brand_id') && ($b = (int) se_clinic_sole_brand_id()) > 0) {
        try { $out['templates'] = se_journey_sync_template_status($b); } catch (Throwable $e) { $out['templates'] = 'error'; }
    }
    update_option('se_journey_cron_last_run', time());
    update_option('se_journey_cron_last_summary', json_encode($out));

    return $out;
}

/** Read-only journey card on the lead profile (basic fields only). */
function se_journey_lead_tab($lead)
{
    $lead_id = is_array($lead) ? (int) ($lead['id'] ?? 0) : (int) ($lead->id ?? 0);
    if (!$lead_id || !se_journey_can('view')) {
        return;
    }
    $CI = &get_instance();
    $CI->db->where('lead_id', $lead_id);
    if (function_exists('se_apply_scope_in')) {
        se_apply_scope_in('brand_id');
    }
    $j = $CI->db->get(db_prefix() . 'se_journeys')->row();
    if (!$j) {
        // A website applicant who never wrote on WhatsApp: offer the start
        // template (needs the lead's contact consent + a phone; see helper).
        $brand = is_array($lead) ? (int) ($lead['brand_id'] ?? 0) : (int) ($lead->brand_id ?? 0);
        $phone = is_array($lead) ? (string) ($lead['phonenumber'] ?? '') : (string) ($lead->phonenumber ?? '');
        if (!se_journey_can('edit_review') || $brand <= 0 || !se_journey_enabled($brand) || trim($phone) === '') {
            return;
        }
        echo '<div class="panel_s"><div class="panel-body"><h5>' . _l('se_journeys') . '</h5>'
           . '<p class="text-muted"><small>' . html_escape(_l('se_journey_lead_start_note')) . '</small></p>'
           . form_open(admin_url('se_journey/se_journey/start_lead/' . $lead_id))
           . '<button type="submit" class="btn btn-success btn-sm"><i class="fa fa-whatsapp"></i> ' . html_escape(_l('se_journey_start_whatsapp_evaluation')) . '</button>'
           . form_close() . '</div></div>';

        return;
    }
    echo '<div class="panel_s"><div class="panel-body"><h5>' . _l('se_journeys') . '</h5>'
       . '<p>' . _l('se_journey_state') . ': <strong>' . html_escape(_l('se_journey_state_' . $j->state)) . '</strong>'
       . ' &middot; ' . _l('se_journey_automation') . ': ' . html_escape($j->automation_state)
       . ' &middot; <a href="' . admin_url('se_journey/se_journey/view/' . (int) $j->id) . '">' . _l('se_journey_open') . '</a></p></div></div>';
}
