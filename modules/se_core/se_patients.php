<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Patient + consent layer (brand-scoped).
 *
 * Sits alongside Perfex's native customer/contact model — it does not replace
 * it. Clinical data (procedure history) lives here and NEVER enters a Meta or
 * Google payload. Every row carries brand_id; reads/writes for ordinary staff
 * are constrained to their assigned brands.
 */

/* --------------------------------------------------------------------------
 * Consent ledger — append-only. The current state of a purpose is the latest
 * row for that (brand, rel, purpose). Withdrawals are new rows, not deletes.
 * ------------------------------------------------------------------------ */

/** Allowed consent purposes; anything else is rejected at the boundary. */
function se_consent_purposes()
{
    return ['ads', 'marketing', 'whatsapp'];
}

/**
 * Append a consent event. Returns the new row id, or 0 when rejected.
 * $state is 'granted' or 'withdrawn'.
 */
function se_consent_record($brand_id, $rel_type, $rel_id, $purpose, $state, $version = null, $source = null, $recorded_by = 0)
{
    if (!in_array($purpose, se_consent_purposes(), true)) {
        return 0;
    }
    if (!in_array($state, ['granted', 'withdrawn'], true)) {
        return 0;
    }
    $rel_id = (int) $rel_id;
    if ($rel_id <= 0) {
        return 0;
    }

    $CI = &get_instance();
    $CI->db->insert(db_prefix() . 'se_consent_ledger', [
        'brand_id'             => (int) $brand_id,
        'rel_type'             => in_array($rel_type, ['lead', 'client'], true) ? $rel_type : 'lead',
        'rel_id'               => $rel_id,
        'purpose'              => $purpose,
        'state'                => $state,
        'consent_text_version' => $version !== null ? mb_substr((string) $version, 0, 32) : null,
        'source'               => $source !== null ? mb_substr((string) $source, 0, 64) : null,
        'consent_at'           => date('Y-m-d H:i:s'),
        'recorded_by'          => (int) $recorded_by,
    ]);

    return (int) $CI->db->insert_id();
}

/** Latest consent state for a purpose, or null if never recorded. */
function se_consent_current($brand_id, $rel_type, $rel_id, $purpose)
{
    $CI = &get_instance();
    $CI->db->where('brand_id', (int) $brand_id)
           ->where('rel_type', $rel_type)
           ->where('rel_id', (int) $rel_id)
           ->where('purpose', $purpose)
           ->order_by('id', 'DESC')
           ->limit(1);
    $row = $CI->db->get(db_prefix() . 'se_consent_ledger')->row();

    return $row ? $row->state : null;
}

/** True only if the latest state for the purpose is 'granted'. */
function se_consent_granted($brand_id, $rel_type, $rel_id, $purpose)
{
    return se_consent_current($brand_id, $rel_type, $rel_id, $purpose) === 'granted';
}

/* --------------------------------------------------------------------------
 * Patient records — brand-scoped, with an access log for sensitive reads.
 * ------------------------------------------------------------------------ */

/** Create or update a patient row for a lead/customer within a brand. */
function se_patient_upsert(array $data)
{
    $CI = &get_instance();
    $table = db_prefix() . 'se_patients';

    $clean = [
        'brand_id'           => (int) ($data['brand_id'] ?? 0),
        'client_id'          => (int) ($data['client_id'] ?? 0),
        'lead_id'            => (int) ($data['lead_id'] ?? 0),
        'preferred_language' => isset($data['preferred_language']) ? mb_substr((string) $data['preferred_language'], 0, 8) : null,
        'nationality'        => isset($data['nationality']) ? mb_substr((string) $data['nationality'], 0, 64) : null,
        'passport_no'        => isset($data['passport_no']) ? mb_substr((string) $data['passport_no'], 0, 64) : null,
    ];

    // Match an existing patient by brand + client or brand + lead.
    $CI->db->where('brand_id', $clean['brand_id']);
    if ($clean['client_id'] > 0) {
        $CI->db->where('client_id', $clean['client_id']);
    } else {
        $CI->db->where('lead_id', $clean['lead_id']);
    }
    $existing = $CI->db->get($table)->row();

    if ($existing) {
        $clean['last_updated'] = date('Y-m-d H:i:s');
        $CI->db->where('id', $existing->id)->update($table, $clean);
        return (int) $existing->id;
    }

    $clean['date_created'] = date('Y-m-d H:i:s');
    $CI->db->insert($table, $clean);
    return (int) $CI->db->insert_id();
}

/** Fetch a patient by id, brand-scoped for the current staff member. */
function se_patient_get($id)
{
    $CI = &get_instance();
    $CI->db->where('id', (int) $id);

    if (function_exists('se_staff_sees_all_brands') && !se_staff_sees_all_brands()) {
        $ids = se_staff_brand_ids();
        $CI->db->where('brand_id IN (' . implode(',', array_map('intval', $ids)) . ')');
    }

    $patient = $CI->db->get(db_prefix() . 'se_patients')->row();

    if ($patient) {
        se_patient_log_access((int) $patient->brand_id, (int) $patient->id, 'view');
    }

    return $patient;
}

/** Record a sensitive-record access. Never logs record contents. */
function se_patient_log_access($brand_id, $patient_id, $action)
{
    $CI = &get_instance();
    $staff_id = function_exists('get_staff_user_id') ? (int) get_staff_user_id() : 0;

    $CI->db->insert(db_prefix() . 'se_record_access_log', [
        'brand_id'    => (int) $brand_id,
        'patient_id'  => (int) $patient_id,
        'staff_id'    => $staff_id,
        'action'      => mb_substr((string) $action, 0, 32),
        'accessed_at' => date('Y-m-d H:i:s'),
    ]);
}
