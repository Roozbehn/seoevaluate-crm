<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * se_journey — the journey writes what it learns onto the CRM lead.
 *
 * WHAT IS COPIED (non-health only)
 *   native lead columns : name (already, at intake), country, city, language,
 *                         lastcontact, pipeline status (forward only)
 *   lead custom fields  : journey stage, age, preferred language, contact
 *                         preference, the three consents, form date, photo
 *                         count, review decision, quote, consultation,
 *                         procedure — a "Hasta yolculuğu" block on the lead
 *   lead activity log   : one line per journey stage change
 *
 * WHAT IS NEVER COPIED
 *   The health questionnaire (complaint, history, medication, pregnancy,
 *   allergies …). It is special-category data, sealed at rest and shown only
 *   on the journey's Intake tab to staff with the view-health capability.
 *   The lead record is plain, exportable and visible to every staff member
 *   with lead access, so the answers stay where the consent covers them.
 *
 * WHEN
 *   Every state transition, the form's consent step, the identity section of
 *   the form (name/age/country/city/language/contact preference — captured at
 *   submit time, the sealed answers are never re-read), each photo received,
 *   appointment changes, and a staff "Sync to lead" button. All writes are by
 *   id + brand: the callers run without a staff session (dispatcher, token
 *   pages), where staff-scoped helpers see nothing.
 */

define('SE_JOURNEY_LEAD_FIELDS_VERSION', 1);
define('SE_JOURNEY_LEAD_ACTOR', 'Hasta yolculuğu');

/** Lead custom fields owned by the journey (slug → definition). Order = display order. */
function se_journey_lead_field_definitions()
{
    return [
        'leads_journey_stage'          => ['name' => 'Yolculuk aşaması',        'type' => 'input'],
        'leads_journey_age'            => ['name' => 'Yaş',                      'type' => 'number'],
        'leads_journey_language'       => ['name' => 'Tercih edilen dil',        'type' => 'input'],
        'leads_journey_contact_pref'   => ['name' => 'Görüşme tercihi',          'type' => 'input'],
        'leads_journey_consent_health' => ['name' => 'Sağlık verisi rızası',     'type' => 'input'],
        'leads_journey_consent_marketing' => ['name' => 'Pazarlama rızası',      'type' => 'input'],
        'leads_journey_consent_photo'  => ['name' => 'Fotoğraf yayın izni',      'type' => 'input'],
        'leads_journey_intake_at'      => ['name' => 'Form gönderim tarihi',     'type' => 'input'],
        'leads_journey_photos'         => ['name' => 'Alınan fotoğraf sayısı',   'type' => 'number'],
        'leads_journey_review'         => ['name' => 'Değerlendirme kararı',     'type' => 'input'],
        'leads_journey_quote'          => ['name' => 'Teklif',                   'type' => 'input'],
        'leads_journey_consultation'   => ['name' => 'Ön görüşme',               'type' => 'input'],
        'leads_journey_procedure'      => ['name' => 'İşlem',                    'type' => 'input'],
        'leads_journey_synced_at'      => ['name' => 'Yolculuk son eşitleme',    'type' => 'input'],
    ];
}

/** Per-brand switches (both default ON). */
function se_journey_lead_sync_enabled($brand_id)
{
    $v = get_option('se_journey_lead_sync_' . (int) $brand_id);

    return $v === '' || $v === null ? true : (int) $v === 1;
}

function se_journey_lead_sync_status_enabled($brand_id)
{
    $v = get_option('se_journey_lead_sync_status_' . (int) $brand_id);

    return $v === '' || $v === null ? true : (int) $v === 1;
}

/**
 * Make sure the custom fields exist (idempotent, matched by slug; never
 * renames or deletes a field a staff member may have edited). Returns the
 * slug → field id map.
 */
function se_journey_lead_fields_ensure()
{
    $CI  = &get_instance();
    $t   = db_prefix() . 'customfields';
    $map = [];
    $CI->db->where('fieldto', 'leads')->where_in('slug', array_keys(se_journey_lead_field_definitions()));
    foreach ($CI->db->get($t)->result_array() as $r) {
        $map[(string) $r['slug']] = (int) $r['id'];
    }
    $order = 900;   // after any fields the clinic already defined
    $created = 0;
    foreach (se_journey_lead_field_definitions() as $slug => $d) {
        $order += 1;
        if (isset($map[$slug])) {
            continue;
        }
        $CI->db->insert($t, [
            'fieldto' => 'leads', 'name' => $d['name'], 'slug' => $slug, 'required' => 0, 'type' => $d['type'], 'options' => '',
            'display_inline' => 0, 'field_order' => $order, 'active' => 1, 'show_on_pdf' => 0, 'show_on_ticket_form' => 0,
            'only_admin' => 0, 'show_on_table' => 0, 'show_on_client_portal' => 0, 'disalow_client_to_edit' => 0,
            'bs_column' => 6, 'default_value' => '',
        ]);
        $id = (int) $CI->db->insert_id();
        if ($id > 0) {
            $map[$slug] = $id;
            $created++;
        }
    }
    if ($created > 0 && function_exists('se_journey_audit')) {
        se_journey_audit(0, 0, 'lead_fields_created', 'customfields', null, $created . ' field(s)');
    }

    return $map;   // one indexed SELECT per sync; no static memo (a reset store must be re-seeded)
}

/** Write one custom-field value (insert or update; empty string clears). */
function se_journey_lead_field_set($lead_id, $field_id, $value)
{
    $CI = &get_instance();
    $t  = db_prefix() . 'customfieldsvalues';
    $value = mb_substr(trim((string) $value), 0, 1000);
    $CI->db->where('relid', (int) $lead_id)->where('fieldid', (int) $field_id)->where('fieldto', 'leads');
    $row = $CI->db->get($t)->row();
    if ($row) {
        if ((string) $row->value !== $value) {
            $CI->db->where('id', (int) $row->id)->update($t, ['value' => $value]);
        }

        return;
    }
    if ($value === '') {
        return;
    }
    $CI->db->insert($t, ['relid' => (int) $lead_id, 'fieldid' => (int) $field_id, 'fieldto' => 'leads', 'value' => $value]);
}

/** Pipeline stage (se_core) a journey state maps to; null = leave the lead where it is. */
function se_journey_lead_stage_for_state($state)
{
    $map = [
        'welcome_sent' => 'WhatsApp Engaged', 'privacy_notice_sent' => 'WhatsApp Engaged', 'consent_pending' => 'WhatsApp Engaged',
        'consent_declined' => 'WhatsApp Engaged', 'intake_link_sent' => 'WhatsApp Engaged', 'intake_started' => 'WhatsApp Engaged',
        'intake_incomplete' => 'WhatsApp Engaged',
        'intake_submitted' => 'Qualified', 'photos_requested' => 'Qualified', 'photos_incomplete' => 'Qualified', 'photo_retake_requested' => 'Qualified',
        'ready_for_review' => 'Photos Received', 'under_review' => 'Photos Received', 'more_information_required' => 'Photos Received',
        'consultation_recommended' => 'Photos Received', 'quote_pending_staff_approval' => 'Photos Received',
        'quote_sent' => 'Quote Sent', 'quote_accepted' => 'Quote Sent', 'quote_revision_requested' => 'Quote Sent', 'quote_expired' => 'Quote Sent',
        'consultation_booked' => 'Consultation Booked',
        'consultation_completed' => 'Consultation Held', 'procedure_booked' => 'Consultation Held', 'preop_pending' => 'Consultation Held',
        'procedure_completed' => 'Treated', 'aftercare_active' => 'Treated', 'completed' => 'Treated',
        'followup_due' => 'Follow-up',
        // not_suitable / closed_lost / opted_out / new_whatsapp_enquiry: a human decides the pipeline outcome.
    ];

    return $map[(string) $state] ?? null;
}

/** Human label for a journey state / decision in the CRM language, falling back to the key. */
function se_journey_lead_label($key)
{
    static $loaded = false;
    if (!function_exists('_l')) {
        return (string) $key;
    }
    $l = _l($key, '', false);
    if ((!is_string($l) || $l === '' || $l === $key) && !$loaded) {
        // Module language files are loaded by the admin/client language hooks;
        // a token page or the dispatcher may reach here before either ran.
        $loaded = true;
        $CI = &get_instance();
        if (isset($CI->lang) && is_object($CI->lang) && method_exists($CI->lang, 'load')) {
            try {
                $CI->lang->load('se_journey/se_journey', (string) (get_option('active_language') ?: 'english'));
            } catch (Throwable $e) {
                // stay with the key
            }
            $l = _l($key, '', false);
        }
    }

    return is_string($l) && $l !== '' ? $l : (string) $key;
}

/** ISO-3166 code → Perfex country id (0 when unknown). Accepts a code or a known country name. */
function se_journey_lead_country_id($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return 0;
    }
    $code = strlen($raw) === 2 ? strtoupper($raw) : (function_exists('se_website_lead_country_code') ? (string) se_website_lead_country_code($raw) : '');
    $CI = &get_instance();
    if ($code !== '') {
        $CI->db->select('country_id')->where('iso2', $code);
        $row = $CI->db->get(db_prefix() . 'countries')->row();
        if ($row) {
            return (int) $row->country_id;
        }
    }
    // A name exactly as Perfex spells it ("Turkey", "Germany").
    $CI->db->select('country_id')->where('short_name', $raw);
    $row = $CI->db->get(db_prefix() . 'countries')->row();

    return $row ? (int) $row->country_id : 0;
}

/** Journey language code → Perfex language folder ('' when the CRM has no such language). */
function se_journey_lead_language_folder($code)
{
    $map = ['tr' => 'turkish', 'en' => 'english', 'fa' => 'persian', 'ar' => 'arabic', 'de' => 'german', 'ru' => 'russian'];
    $folder = $map[strtolower((string) $code)] ?? '';
    if ($folder !== '' && defined('APPPATH') && !is_dir(APPPATH . 'language/' . $folder)) {
        return '';
    }

    return $folder;
}

/**
 * Identity fields from the form's first section, given at SUBMIT time by
 * se_journey_apply_identity() — the only moment the plain answers pass by.
 * Nothing here is health data.
 */
function se_journey_lead_apply_identity($j, array $clean)
{
    if ((int) $j->lead_id <= 0 || !se_journey_lead_sync_enabled((int) $j->brand_id)) {
        return;
    }
    $CI   = &get_instance();
    $lead = se_journey_lead_row($j);
    if (!$lead) {
        return;
    }
    $upd = [];
    if (!empty($clean['country']) && (int) $lead->country <= 0) {
        $cid = se_journey_lead_country_id((string) $clean['country']);
        if ($cid > 0) { $upd['country'] = $cid; }
    }
    if (!empty($clean['city']) && trim((string) ($lead->city ?? '')) === '') {
        $upd['city'] = mb_substr(trim((string) $clean['city']), 0, 100);
    }
    if (!empty($clean['preferred_language']) && trim((string) $lead->default_language) === '') {
        $folder = se_journey_lead_language_folder((string) $clean['preferred_language']);
        if ($folder !== '') { $upd['default_language'] = $folder; }
    }
    if ($upd) {
        $CI->db->where('id', (int) $lead->id)->where('brand_id', (int) $j->brand_id)->update(db_prefix() . 'leads', $upd);
    }

    $fields = se_journey_lead_fields_ensure();
    $langs  = ['tr' => 'Türkçe', 'en' => 'English', 'fa' => 'فارسی', 'ar' => 'العربية'];
    $times  = ['morning' => 'Sabah', 'afternoon' => 'Öğleden sonra', 'evening' => 'Akşam', 'any' => 'Fark etmez'];
    $chans  = ['whatsapp' => 'WhatsApp', 'phone' => 'Telefon', 'video' => 'Görüntülü görüşme', 'in_person' => 'Klinikte'];
    if (isset($fields['leads_journey_age']) && isset($clean['age']) && (int) $clean['age'] > 0) {
        se_journey_lead_field_set((int) $lead->id, $fields['leads_journey_age'], (string) (int) $clean['age']);
    }
    if (isset($fields['leads_journey_language']) && !empty($clean['preferred_language'])) {
        $code = (string) $clean['preferred_language'];
        se_journey_lead_field_set((int) $lead->id, $fields['leads_journey_language'], $langs[$code] ?? $code);
    }
    if (isset($fields['leads_journey_contact_pref'])) {
        $parts = [];
        if (!empty($clean['contact_time']))    { $parts[] = $times[(string) $clean['contact_time']] ?? (string) $clean['contact_time']; }
        if (!empty($clean['contact_channel'])) { $parts[] = $chans[(string) $clean['contact_channel']] ?? (string) $clean['contact_channel']; }
        if ($parts) {
            se_journey_lead_field_set((int) $lead->id, $fields['leads_journey_contact_pref'], implode(' · ', $parts));
        }
    }
}

/** The lead row, by id and brand (no staff scope: callers have no session). */
function se_journey_lead_row($j)
{
    if ((int) $j->lead_id <= 0) {
        return null;
    }
    $CI = &get_instance();
    $CI->db->where('id', (int) $j->lead_id)->where('brand_id', (int) $j->brand_id);

    return $CI->db->get(db_prefix() . 'leads')->row();
}

/**
 * Bring the lead up to date with the journey. Safe to call often: it writes
 * only what changed, never moves the pipeline backwards, never touches a
 * converted, lost or junk lead's status, and never reads sealed answers.
 *
 * @param string $reason  what triggered the sync (transition:<state>, consent, photo, appointment, staff)
 * @return array{ok:bool,reason:string,changed:array}
 */
function se_journey_sync_lead($j, $reason = '')
{
    if (!is_object($j) || (int) $j->lead_id <= 0) {
        return ['ok' => false, 'reason' => 'no_lead', 'changed' => []];
    }
    if (!se_journey_lead_sync_enabled((int) $j->brand_id)) {
        return ['ok' => false, 'reason' => 'disabled', 'changed' => []];
    }
    $j = se_journey_get_raw((int) $j->id) ?: $j;   // the freshest row (state, appointment ids, decision)
    $lead = se_journey_lead_row($j);
    if (!$lead) {
        return ['ok' => false, 'reason' => 'lead_missing', 'changed' => []];
    }
    $CI      = &get_instance();
    $now     = date('Y-m-d H:i:s');
    $changed = [];
    $upd     = [];

    /* ---- native columns ------------------------------------------------ */
    if (!empty($j->latest_touch_at) && (empty($lead->lastcontact) || strtotime((string) $lead->lastcontact) < strtotime((string) $j->latest_touch_at))) {
        $upd['lastcontact'] = (string) $j->latest_touch_at;
    }
    if (trim((string) ($lead->default_language ?? '')) === '' && !empty($j->language)) {
        $folder = se_journey_lead_language_folder((string) $j->language);
        if ($folder !== '') { $upd['default_language'] = $folder; }
    }

    /* ---- pipeline status (forward only, never on converted/lost/junk) --- */
    $stage = se_journey_lead_stage_for_state((string) $j->state);
    $convertedOrClosed = (int) ($lead->lost ?? 0) === 1 || (int) ($lead->junk ?? 0) === 1 || !empty($lead->date_converted);
    if ($stage !== null && !$convertedOrClosed && se_journey_lead_sync_status_enabled((int) $j->brand_id)) {
        $CI->db->select('id, name, statusorder')->where('name', $stage);
        $target = $CI->db->get(db_prefix() . 'leads_status')->row();
        $CI->db->select('id, name, statusorder')->where('id', (int) $lead->status);
        $current = $CI->db->get(db_prefix() . 'leads_status')->row();
        $currentOrder = $current ? (int) $current->statusorder : 0;
        if ($target && (int) $target->id !== (int) $lead->status && $currentOrder < 1000 && (int) $target->statusorder > $currentOrder) {
            $upd['status'] = (int) $target->id;
            $upd['last_status_change'] = $now;
            $upd['last_lead_status'] = (int) $lead->status;
            $changed[] = 'status:' . $target->name;
            se_journey_lead_activity((int) $lead->id, 'not_lead_activity_status_updated',
                serialize([SE_JOURNEY_LEAD_ACTOR, $current ? (string) $current->name : '', (string) $target->name]));
            if (function_exists('hooks')) {
                hooks()->do_action('lead_status_changed', ['lead_id' => (int) $lead->id, 'old_status' => (int) $lead->status, 'new_status' => (int) $target->id]);
            }
        }
    }
    if ($upd) {
        $CI->db->where('id', (int) $lead->id)->where('brand_id', (int) $j->brand_id)->update(db_prefix() . 'leads', $upd);
        foreach (array_keys($upd) as $k) { if ($k !== 'status' && $k !== 'last_status_change' && $k !== 'last_lead_status') { $changed[] = $k; } }
    }

    /* ---- custom fields ------------------------------------------------- */
    $fields = se_journey_lead_fields_ensure();
    $set = function ($slug, $value) use ($fields, $lead) {
        if (isset($fields[$slug])) {
            se_journey_lead_field_set((int) $lead->id, $fields[$slug], $value);
        }
    };
    $set('leads_journey_stage', se_journey_lead_label('se_journey_state_' . (string) $j->state));

    $consent = function_exists('se_journey_consent_state') ? se_journey_consent_state($j) : null;
    if ($consent) {
        $yes = 'Verildi'; $no = 'Verilmedi';
        $set('leads_journey_consent_health', ($consent['health_data'] ? $yes : $no) . (!empty($consent['version']) ? ' (' . $consent['version'] . ')' : ''));
        $set('leads_journey_consent_marketing', $consent['marketing'] ? $yes : $no);
        $set('leads_journey_consent_photo', $consent['photo_publication'] ? $yes : $no);
    }
    if (function_exists('se_journey_intake_get')) {
        $intake = se_journey_intake_get($j);
        if ($intake && (string) $intake->status === 'submitted' && !empty($intake->submitted_at)) {
            $set('leads_journey_intake_at', date('d.m.Y H:i', strtotime((string) $intake->submitted_at)));
        }
    }
    if (function_exists('se_journey_media_count')) {
        $n = (int) se_journey_media_count($j);
        if ($n > 0) { $set('leads_journey_photos', (string) $n); }
    }
    if (!empty($j->review_decision)) {
        $set('leads_journey_review', se_journey_lead_label('se_journey_decision_' . (string) $j->review_decision));
    }
    if (function_exists('se_journey_quote_sent_row')) {
        $q = se_journey_quote_sent_row($j) ?: (function_exists('se_journey_quote_latest') ? se_journey_quote_latest($j) : null);
        if ($q && in_array((string) $q->status, ['sent', 'approved', 'pending_approval'], true)) {
            $set('leads_journey_quote', se_journey_lead_quote_summary($q));
        }
    }
    if (function_exists('se_journey_consultation_appointment')) {
        $a = se_journey_consultation_appointment($j);
        if ($a) { $set('leads_journey_consultation', se_journey_lead_appointment_summary($a)); }
    }
    if ((int) $j->procedure_appointment_id > 0) {
        $CI->db->where('id', (int) $j->procedure_appointment_id)->where('brand_id', (int) $j->brand_id);
        $pa = $CI->db->get(db_prefix() . 'se_appointments')->row();
        if ($pa) { $set('leads_journey_procedure', se_journey_lead_appointment_summary($pa)); }
    }
    $set('leads_journey_synced_at', date('d.m.Y H:i') . ($reason !== '' ? ' · ' . mb_substr($reason, 0, 40) : ''));

    return ['ok' => true, 'reason' => '', 'changed' => $changed];
}

/** "v1 · 1.500–2.200 EUR · gönderildi 03.09.2026 · kabul edildi 03.09.2026" (amount only when the patient saw one). */
function se_journey_lead_quote_summary($q)
{
    $parts = ['v' . (int) $q->version];
    if ((int) $q->show_amount === 1 && ($q->amount_min !== null || $q->amount_max !== null)) {
        $fmt = function ($n) { return number_format((float) $n, 0, ',', '.'); };
        if ($q->amount_min !== null && $q->amount_max !== null && (float) $q->amount_min !== (float) $q->amount_max) {
            $parts[] = $fmt($q->amount_min) . '–' . $fmt($q->amount_max) . ' ' . (string) $q->currency;
        } else {
            $parts[] = $fmt($q->amount_min !== null ? $q->amount_min : $q->amount_max) . ' ' . (string) $q->currency;
        }
    }
    $status = ['draft' => 'taslak', 'pending_approval' => 'onay bekliyor', 'approved' => 'onaylandı', 'sent' => 'gönderildi', 'withdrawn' => 'geri çekildi', 'expired' => 'süresi doldu'];
    $parts[] = ($status[(string) $q->status] ?? (string) $q->status) . (!empty($q->sent_at) ? ' ' . date('d.m.Y', strtotime((string) $q->sent_at)) : '');
    $resp = (string) ($q->patient_response ?? '');
    if ($resp !== '') {
        $parts[] = ($resp === 'accepted' ? 'kabul edildi' : 'fiyat revizyonu istendi') . (!empty($q->patient_response_at) ? ' ' . date('d.m.Y', strtotime((string) $q->patient_response_at)) : '');
    }

    return implode(' · ', $parts);
}

/** "08.09.2026 14:00 · klinikte · planlandı". */
function se_journey_lead_appointment_summary($a)
{
    $status = ['scheduled' => 'planlandı', 'confirmed' => 'onaylandı', 'held' => 'gerçekleşti', 'completed' => 'tamamlandı', 'no_show' => 'gelmedi', 'cancelled' => 'iptal edildi'];
    $parts = [date('d.m.Y H:i', strtotime((string) $a->start_at))];
    if ((string) ($a->appointment_type ?? '') !== 'procedure') {
        $parts[] = (string) ($a->consultation_format ?? '') === 'online' ? 'online' : 'klinikte';
    }
    $parts[] = $status[(string) $a->status] ?? (string) $a->status;

    return implode(' · ', $parts);
}

/** One line on the lead's activity timeline, attributed to the journey (no staff session needed). */
function se_journey_lead_activity($lead_id, $description, $additional_data = '')
{
    $CI = &get_instance();
    $CI->db->insert(db_prefix() . 'lead_activity_log', [
        'date' => date('Y-m-d H:i:s'), 'description' => (string) $description, 'leadid' => (int) $lead_id,
        'staffid' => 0, 'additional_data' => (string) $additional_data, 'full_name' => SE_JOURNEY_LEAD_ACTOR,
    ]);
}

/** A stage change on the lead timeline: "Hasta yolculuğu: Teklif kabul edildi". */
function se_journey_lead_log_transition($j, $to)
{
    if ((int) $j->lead_id <= 0 || !se_journey_lead_sync_enabled((int) $j->brand_id)) {
        return;
    }
    se_journey_lead_activity((int) $j->lead_id, SE_JOURNEY_LEAD_ACTOR . ': ' . se_journey_lead_label('se_journey_state_' . (string) $to));
}
