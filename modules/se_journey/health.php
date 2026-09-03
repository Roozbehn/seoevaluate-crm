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
    $add('media_storage_r2', $storage['driver'] === 'r2', $storage['driver'] === 'r2'
        ? 'Sealed photos go to Cloudflare R2 (bucket azin-media, crm/journey/…) through the crm-media gateway.'
        : ($storage['r2_requested']
            ? 'R2 gateway not ready — set option se_media_r2_url (crm-media Worker URL) and install secret r2_media_key; until then sealed photos stay in the local directory and migrate automatically once the gateway is up.'
            : 'Storage forced to local by option se_journey_media_storage=local.'), false);
    $add('media_dir', $storage['exists'] && $storage['writable'],
        ($storage['driver'] === 'r2' ? 'Fallback only (used when the gateway is unreachable): ' : '')
        . 'create the private journey media directory outside the docroot (default: a sibling of the inbox media store named _se_journey_media, mode 0700, owner = PHP user) or set option se_journey_media_dir.',
        $storage['driver'] !== 'r2');
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
    $rows = $CI->db->get(db_prefix() . 'se_journey_tasks')->result_array();
    // Turkish titles by kind (UX-COPY §8); the stored English title is the fallback.
    foreach ($rows as &$r) {
        $r['title_raw'] = (string) $r['title'];
        $r['title'] = function_exists('se_tr') ? se_tr('se_task_' . (string) $r['kind'], (string) $r['title']) : (string) $r['title'];
    }
    unset($r);

    return $rows;
}

/* ===========================================================================
 * Bugün — attention queue (CRM-M023 / UX-D01/D02 / AZCRM-UX-001 / OBS-002)
 * ======================================================================== */

/** Journey states that never need attention again. */
function se_journey_terminal_states()
{
    return ['completed', 'not_suitable', 'closed_lost', 'opted_out'];
}

/**
 * Batch context for a set of journeys: latest quote, relevant appointment,
 * failed-send flag, lead row, unread thread and the computed next action —
 * each table read ONCE, se_journey_next_action() run in PHP. Shared by the
 * Bugün queue and the Hastalar list so they cannot disagree.
 *
 * @return array{items: array<int, array>, unread: array<int, array>, leads: array<int, array>}
 *   items: journey_id => ['j' => object, 'na' => array, 'ctx' => array, 'lead' => array|null,
 *          'unread' => array|null, 'name' => string, 'appointment' => object|null, 'next_appointment' => array|null]
 *   unread: conversation_id => unread thread rows (all, incl. threads without a journey)
 */
function se_journey_batch_context(array $journeys, $now = null, array $opts = [])
{
    $now = $now ?? time();
    $CI  = &get_instance();
    $p   = db_prefix();
    $out = ['items' => [], 'unread' => [], 'leads' => []];
    $ids = array_map(function ($j) { return (int) $j['id']; }, $journeys);
    $leadIds = array_values(array_unique(array_filter(array_map(function ($j) { return (int) $j['lead_id']; }, $journeys))));
    $convIds = array_values(array_unique(array_filter(array_map(function ($j) { return (int) $j['wa_conversation_id']; }, $journeys))));
    $apptIds = [];
    foreach ($journeys as $j) {
        foreach (['consultation_appointment_id', 'procedure_appointment_id'] as $k) {
            if ((int) ($j[$k] ?? 0) > 0) { $apptIds[] = (int) $j[$k]; }
        }
    }

    // latest quote per journey (ascending id → last write wins)
    $quotes = [];
    if ($ids && $CI->db->table_exists($p . 'se_journey_quotes')) {
        $CI->db->where_in('journey_id', $ids)->order_by('id', 'ASC');
        foreach ($CI->db->get($p . 'se_journey_quotes')->result_array() as $q) { $quotes[(int) $q['journey_id']] = (object) $q; }
    }
    // linked appointments
    $appts = [];
    if ($apptIds && $CI->db->table_exists($p . 'se_appointments')) {
        $CI->db->where_in('id', array_values(array_unique($apptIds)));
        foreach ($CI->db->get($p . 'se_appointments')->result_array() as $a) { $appts[(int) $a['id']] = (object) $a; }
    }
    // next upcoming appointment per lead (Hastalar column) — opt-in
    $nextAppt = [];
    if (!empty($opts['next_appointment']) && $leadIds && $CI->db->table_exists($p . 'se_appointments')) {
        $CI->db->where('rel_type', 'lead')->where_in('rel_id', $leadIds)->where('start_at >=', date('Y-m-d H:i:s', $now))
               ->where_not_in('status', ['cancelled', 'completed', 'no_show'])->order_by('start_at', 'ASC');
        foreach ($CI->db->get($p . 'se_appointments')->result_array() as $a) {
            if (!isset($nextAppt[(int) $a['rel_id']])) { $nextAppt[(int) $a['rel_id']] = $a; }
        }
    }
    // failed sends per conversation
    $failed = [];
    if ($convIds && $CI->db->table_exists($p . 'se_wa_outbound')) {
        $CI->db->select('conversation_id')->where_in('conversation_id', $convIds)->where('status', 'failed')->group_by('conversation_id');
        foreach ($CI->db->get($p . 'se_wa_outbound')->result_array() as $r) { $failed[(int) $r['conversation_id']] = true; }
    }
    // lead names + phones
    if ($leadIds) {
        $CI->db->select('id, name, phonenumber, email')->where_in('id', $leadIds);
        foreach ($CI->db->get($p . 'leads')->result_array() as $l) { $out['leads'][(int) $l['id']] = $l; }
    }
    // unread threads (brand-scoped; includes threads with no journey)
    if ($CI->db->table_exists($p . 'se_wa_conversations')) {
        if (function_exists('se_apply_scope_in')) { se_apply_scope_in('brand_id'); }
        $CI->db->select('id, wa_user_id, lead_id, unread_count, last_inbound_at')->where('unread_count >', 0);
        foreach ($CI->db->get($p . 'se_wa_conversations')->result_array() as $c) { $out['unread'][(int) $c['id']] = $c; }
    }

    foreach ($journeys as $jr) {
        $j = (object) $jr;
        $apptId = in_array((string) $j->state, ['procedure_booked', 'preop_pending', 'procedure_completed'], true)
            ? (int) ($j->procedure_appointment_id ?? 0) : (int) ($j->consultation_appointment_id ?? 0);
        $ctx = [
            'quote'       => $quotes[(int) $j->id] ?? null,
            'appointment' => $apptId ? ($appts[$apptId] ?? null) : null,
            'wa_failed'   => !empty($failed[(int) $j->wa_conversation_id]),
        ];
        $lead = $out['leads'][(int) $j->lead_id] ?? null;
        $name = $lead && trim((string) $lead['name']) !== '' ? se_ui_short_name($lead['name'])
              : (trim((string) ($j->display_name ?? '')) !== '' ? se_ui_short_name($j->display_name) : se_ui_phone($j->wa_user_id, true, false));
        $out['items'][(int) $j->id] = [
            'j' => $j, 'na' => se_journey_next_action($j, $ctx, $now), 'ctx' => $ctx, 'lead' => $lead, 'name' => $name,
            'unread' => $out['unread'][(int) $j->wa_conversation_id] ?? null,
            'appointment' => $ctx['appointment'], 'next_appointment' => $lead ? ($nextAppt[(int) $j->lead_id] ?? null) : null,
        ];
    }

    return $out;
}

/** Turn a batch item into an attention row (one button, accessible name). Null when nothing is owed by staff. */
function se_journey_attention_row_from(array $it, $now)
{
    $j = $it['j']; $na = $it['na']; $u = $it['unread'];
    if ($na['owner'] !== 'staff' && !$u) {
        return null;
    }
    // An unanswered inbound on a patient-owned step: the reply is the action.
    if ($na['owner'] !== 'staff' && $u) {
        $inAt = strtotime((string) $u['last_inbound_at']) ?: $now;
        if ($now - $inAt < SE_NA_UNANSWERED_THREAD) { return null; }
        $na = ['key' => 'unread', 'owner' => 'staff', 'priority' => 3, 'tone' => 'info', 'sentence' => _l('se_na_unread'),
               'reason' => _l('se_na_unread_reason', [(int) $u['unread_count'], se_ui_age($inAt, $now)]), 'age' => $now - $inAt,
               'action_label' => _l('se_na_btn_reply'), 'url' => admin_url('se_whatsapp/se_whatsapp/conversation/' . (int) $u['id']), 'ghost' => false];
    }
    $name = $it['name'];

    return [
        'journey_id' => (int) $j->id, 'lead_id' => (int) $j->lead_id, 'conversation_id' => (int) $j->wa_conversation_id,
        'who' => $name, 'state' => (string) $j->state, 'why' => se_ui_state_label($j->state), 'tone' => $na['tone'],
        'reason' => $na['reason'], 'age' => (int) $na['age'], 'hot' => $na['priority'] <= 2, 'priority' => (int) $na['priority'],
        'action_label' => $na['action_label'], 'url' => $na['url'], 'key' => $na['key'], 'unread' => $u ? (int) $u['unread_count'] : 0,
        'aria' => $name . ' — ' . $na['action_label'],
    ];
}

/**
 * Everything that needs a staff member right now, one row per patient,
 * ordered by priority (1 danger · 2 action · 3 info) then by age (oldest
 * first). Batch-loaded through se_journey_batch_context(). Capped at $limit.
 *
 * @return array{rows: array, total: int, counts: array}
 */
function se_journey_attention_queue($limit = 25, $now = null)
{
    $now = $now ?? time();
    $CI  = &get_instance();
    $p   = db_prefix();

    if (function_exists('se_apply_scope_in')) { se_apply_scope_in('brand_id'); }
    $CI->db->where_not_in('state', se_journey_terminal_states());
    $journeys = $CI->db->get($p . 'se_journeys')->result_array();
    if (!$journeys) {
        return ['rows' => [], 'total' => 0, 'counts' => ['p1' => 0, 'p2' => 0, 'p3' => 0]];
    }
    $batch = se_journey_batch_context($journeys, $now);

    $rows = [];
    $seenConv = [];
    foreach ($batch['items'] as $it) {
        $seenConv[(int) $it['j']->wa_conversation_id] = true;
        $row = se_journey_attention_row_from($it, $now);
        if ($row) { $rows[] = $row; }
    }
    // Unread threads with no journey at all (new number, journey not started)
    foreach ($batch['unread'] as $cid => $u) {
        if (!empty($seenConv[$cid])) { continue; }
        $inAt = strtotime((string) $u['last_inbound_at']) ?: $now;
        $lead = $batch['leads'][(int) $u['lead_id']] ?? null;
        $name = $lead && trim((string) $lead['name']) !== '' ? se_ui_short_name($lead['name']) : se_ui_phone($u['wa_user_id'], true, false);
        $rows[] = [
            'journey_id' => 0, 'lead_id' => (int) $u['lead_id'], 'conversation_id' => $cid, 'who' => $name, 'state' => '',
            'why' => _l('se_na_new_thread'), 'tone' => 'info', 'reason' => _l('se_na_unread_reason', [(int) $u['unread_count'], se_ui_age($inAt, $now)]),
            'age' => $now - $inAt, 'hot' => false, 'priority' => 3, 'action_label' => _l('se_na_btn_reply'),
            'url' => admin_url('se_whatsapp/se_whatsapp/conversation/' . $cid), 'key' => 'unread_no_journey', 'unread' => (int) $u['unread_count'],
            'aria' => $name . ' — ' . _l('se_na_btn_reply'),
        ];
    }

    usort($rows, function ($a, $b) {
        if ($a['priority'] !== $b['priority']) { return $a['priority'] <=> $b['priority']; }
        return $b['age'] <=> $a['age'];
    });
    $counts = ['p1' => 0, 'p2' => 0, 'p3' => 0];
    foreach ($rows as $r) { $counts['p' . $r['priority']]++; }

    return ['rows' => array_slice($rows, 0, max(1, (int) $limit)), 'total' => count($rows), 'counts' => $counts];
}

/** Stage counts for the "Hasta akışı" pills (active journeys, brand-scoped). */
function se_journey_stage_counts()
{
    $CI = &get_instance();
    if (function_exists('se_apply_scope_in')) { se_apply_scope_in('brand_id'); }
    $CI->db->select('state, COUNT(*) AS c')->where_not_in('state', se_journey_terminal_states())->group_by('state');
    $out = [];
    foreach (se_ui_stages_list() as $k) { $out[$k] = 0; }
    foreach ($CI->db->get(db_prefix() . 'se_journeys')->result_array() as $r) {
        $stage = se_ui_stage_of($r['state']);
        if (isset($out[$stage])) { $out[$stage] += (int) $r['c']; }
    }

    return $out;
}
