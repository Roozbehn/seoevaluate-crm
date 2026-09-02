<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * se_journey — readiness, health and dashboard counters.
 *
 * Presence/absence only. No value of any secret, no patient content.
 */

/** Configuration readiness for one brand: each item is present|missing plus the exact next action. */
function se_journey_readiness($brand_id)
{
    $brand_id = (int) $brand_id;
    $items = [];
    $add = function ($key, $ok, $action, $blocking = true) use (&$items) {
        $items[] = ['key' => $key, 'ok' => (bool) $ok, 'action' => $action, 'blocking' => (bool) $blocking];
    };

    $add('feature_flag', se_journey_enabled($brand_id), 'Enable the journey for this brand (Journeys → Settings → Enabled).');
    $add('sandbox', true, se_journey_sandbox($brand_id)
        ? 'SANDBOX ON: only allow-listed test recipients receive real messages. Turn off for go-live.'
        : 'Sandbox off: real patients receive automated messages.', false);
    $add('encryption_key', se_journey_crypto_available(), 'Install the 32-byte key as secret provider `journey_key` (base64) with se-secret-install.sh; sodium extension must be loaded.');
    $storage = se_journey_media_storage_status();
    $add('media_dir', $storage['exists'] && $storage['writable'], 'Create the private journey media directory outside the docroot (default: a sibling of the inbox media store named _se_journey_media, mode 0700, owner = PHP user) or set option se_journey_media_dir.');
    $add('media_outside_docroot', $storage['outside_docroot'], 'Move the media directory outside the document root.');
    $add('health_consent_text', function_exists('se_consent_text_configured') && se_consent_text_configured($brand_id, 'health_data'),
        'Counsel-approved KVKK health-data (special category) consent text: Consent Settings → health_data (TR + EN, enabled, version).');
    $add('photo_publication_text', function_exists('se_consent_text_configured') && se_consent_text_configured($brand_id, 'photo_publication'),
        'Optional publication-consent text: Consent Settings → photo_publication.', false);
    $add('consent_bypass_off', !se_journey_consent_bypass_active($brand_id), 'Emergency consent bypass is ON (admin-only, audited) — turn it off once the approved text is configured.', false);
    $add('wa_number', function_exists('se_wa_can_send') && se_wa_can_send($brand_id), 'Configure the WhatsApp number row (state active, token ref) — WhatsApp → Readiness.');
    $blocked = function_exists('se_wa_send_blocked_reason') ? se_wa_send_blocked_reason($brand_id) : 'unknown';
    $add('wa_send_capability', $blocked === '', 'WhatsApp sending is gated: ' . ($blocked ?: 'ok') . ' (install wa_app/meta_app secret and wa_token; see WhatsApp → Readiness).');
    $add('wa_media_fetch', function_exists('se_journey_media_fetch_possible') && se_journey_media_fetch_possible(),
        'Inbound photo download (inbox media store, dispatcher step "media") needs the Cloud API token (wa_token).', false);
    $webhook = function_exists('se_webhook_state') ? se_webhook_state('wa') : null;
    $add('wa_webhook', is_array($webhook) && !empty($webhook['live_test_passed']), 'WhatsApp webhook must be verified and live-tested (Integration Health).');
    $add('lead_pipeline', se_journey_default_lead_status($brand_id) > 0 && se_journey_default_lead_source($brand_id) > 0, 'Configure a default lead status and source (Meta Lead Ads defaults or options se_journey_lead_status_/se_journey_lead_source_).');
    $tpl = se_journey_template_summary($brand_id);
    $add('templates_approved', $tpl['approved'] === $tpl['total'] && $tpl['total'] > 0,
        'Submit the logical templates to Meta and wait for APPROVED (' . $tpl['approved'] . '/' . $tpl['total'] . ' approved). Out-of-window messages are blocked until then.', false);
    $add('aftercare_protocol_approved', se_journey_any_protocol_approved($brand_id), 'Mark a clinic-approved aftercare protocol as approved (Journeys → Aftercare protocols).', false);
    $add('preop_text_approved', (int) get_option('se_journey_preop_text_approved_' . $brand_id) === 1, 'Pre-op information text/link needs counsel + medical director approval (option se_journey_preop_text_approved_<brand>).', false);
    $add('urgent_target', trim((string) se_journey_config('urgent_staff_ids', '')) !== '', 'Set the on-call staff ids for urgent alerts (option se_journey_urgent_staff_ids); admins are alerted meanwhile.', false);
    $cron = (int) get_option('se_journey_cron_last_run');
    $add('cron_recent', $cron > 0 && time() - $cron < 3600, 'The journey cron has not run in the last hour — check the Perfex cron.', false);

    $blocking = 0;
    foreach ($items as $i) { if (!$i['ok'] && $i['blocking']) { $blocking++; } }

    return ['items' => $items, 'blocking' => $blocking, 'go_live_ready' => $blocking === 0 && !se_journey_sandbox($brand_id)];
}

function se_journey_template_summary($brand_id)
{
    $CI = &get_instance();
    $CI->db->where('brand_id', (int) $brand_id);
    $rows = $CI->db->get(db_prefix() . 'se_journey_templates')->result_array();
    $s = ['total' => count($rows), 'approved' => 0, 'pending' => 0, 'rejected' => 0, 'not_submitted' => 0, 'other' => 0];
    foreach ($rows as $r) {
        $st = (string) $r['approval_status'];
        if (isset($s[$st])) { $s[$st]++; } else { $s['other']++; }
    }

    return $s;
}

function se_journey_any_protocol_approved($brand_id)
{
    foreach (se_journey_aftercare_protocols($brand_id) as $p) {
        if (!empty($p['approved'])) { return true; }
    }

    return false;
}

/** Operational health for Integration Health: queue lag, failures, media problems. */
function se_journey_health($brand_id)
{
    $CI = &get_instance();
    $brand_id = (int) $brand_id;

    $CI->db->where('brand_id', $brand_id)->where('state', 'open')->where('kind', 'template_blocked');
    $templateBlocked = $CI->db->count_all_results(db_prefix() . 'se_journey_tasks');
    $CI->db->where('brand_id', $brand_id)->where('state', 'open')->where('kind', 'media_fetch_failed');
    $mediaFailed = $CI->db->count_all_results(db_prefix() . 'se_journey_tasks');
    $CI->db->where('brand_id', $brand_id)->where('state', 'pending_fetch');
    $mediaParked = $CI->db->count_all_results(db_prefix() . 'se_journey_media');
    $CI->db->where('brand_id', $brand_id)->where('automation_state', 'error');
    $automationErrors = $CI->db->count_all_results(db_prefix() . 'se_journeys');
    $CI->db->where('brand_id', $brand_id)->where('urgent', 1);
    $urgent = $CI->db->count_all_results(db_prefix() . 'se_journeys');

    $queue = function_exists('se_wa_out_health') ? se_wa_out_health($brand_id) : [];
    $cron  = (int) get_option('se_journey_cron_last_run');

    return [
        'enabled'            => se_journey_enabled($brand_id),
        'sandbox'            => se_journey_sandbox($brand_id),
        'encryption'         => se_journey_crypto_available(),
        'media_storage'      => se_journey_media_storage_status(),
        'health_consent_text'=> function_exists('se_consent_text_configured') && se_consent_text_configured($brand_id, 'health_data'),
        'templates'          => se_journey_template_summary($brand_id),
        'template_blocked_tasks' => $templateBlocked,
        'media_fetch_failed' => $mediaFailed,
        'media_parked'       => $mediaParked,
        'automation_errors'  => $automationErrors,
        'urgent_open'        => $urgent,
        'wa_queue'           => $queue,
        'cron_age_seconds'   => $cron > 0 ? time() - $cron : null,
        'listener_last_error'=> (string) get_option('se_wa_listener_last_error'),
    ];
}

/** Dashboard counters (brand-scoped for the acting staff member; counts only, never content). */
function se_journey_dashboard_counters()
{
    $CI = &get_instance();
    $groups = [
        'new_enquiries'   => ['new_whatsapp_enquiry', 'welcome_sent'],
        'incomplete_intake' => ['privacy_notice_sent', 'consent_pending', 'intake_link_sent', 'intake_started', 'intake_incomplete', 'consent_declined'],
        'waiting_photos'  => ['photos_requested', 'photos_incomplete', 'photo_retake_requested'],
        'ready_for_review'=> ['ready_for_review', 'under_review', 'more_information_required'],
        'quote_pending'   => ['quote_pending_staff_approval'],
        'consultation_due'=> ['consultation_recommended', 'quote_sent', 'consultation_booked'],
        'procedure_booked'=> ['procedure_booked', 'preop_pending'],
        'followup_due'    => ['followup_due', 'procedure_completed'],
    ];
    $counts = [];
    foreach (array_keys($groups) as $k) { $counts[$k] = 0; }
    $counts['urgent'] = 0;
    $counts['failed_message'] = 0;
    $counts['paused'] = 0;

    if (function_exists('se_apply_scope_in')) {
        se_apply_scope_in('brand_id');
    }
    foreach ($CI->db->get(db_prefix() . 'se_journeys')->result_array() as $r) {
        foreach ($groups as $k => $states) {
            if (in_array((string) $r['state'], $states, true)) { $counts[$k]++; }
        }
        if ((int) $r['urgent'] === 1) { $counts['urgent']++; }
        if ((string) $r['automation_state'] === 'error') { $counts['failed_message']++; }
        if (in_array((string) $r['automation_state'], ['paused_staff', 'paused_patient'], true)) { $counts['paused']++; }
    }

    return $counts;
}

/** Open attention items, brand-scoped. */
function se_journey_open_tasks($limit = 50, $journey_id = 0)
{
    $CI = &get_instance();
    if (function_exists('se_apply_scope_in')) {
        se_apply_scope_in('brand_id');
    }
    $CI->db->where('state', 'open');
    if ((int) $journey_id > 0) {
        $CI->db->where('journey_id', (int) $journey_id);
    }
    $CI->db->order_by('id', 'DESC')->limit(max(1, (int) $limit));

    return $CI->db->get(db_prefix() . 'se_journey_tasks')->result_array();
}
