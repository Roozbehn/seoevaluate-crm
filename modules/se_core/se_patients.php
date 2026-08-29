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
 * Consent ledger lives in se_consent.php.
 *
 * It used to be defined here with a permissive decision rule and no record of
 * WHICH question produced the answer. se_consent.php owns it now: strict
 * affirmative allowlist, question/answer provenance, server-controlled
 * consent-text version, point-in-time lookup, and withdrawal handling.
 * ------------------------------------------------------------------------ */

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

/* --------------------------------------------------------------------------
 * Patient CRUD (brand-scoped) — procedural, reusing the existing tables.
 * The controller calls these; kept as pure-ish functions so they are unit
 * testable with a stubbed CI. No duplicate patient model or table is created.
 * ------------------------------------------------------------------------ */

/** Brand ids the current staff may see, or null for "all brands" (admin). */
function se_patient_scope_ids()
{
    if (function_exists('se_staff_sees_all_brands') && se_staff_sees_all_brands()) {
        return null;
    }
    return function_exists('se_staff_brand_ids') ? array_map('intval', se_staff_brand_ids()) : [];
}

/** Validate + clean patient input. Returns ['clean'=>[], 'errors'=>[]]. Pure. */
function se_patient_validate(array $data)
{
    $errors = [];
    $clean  = [];

    $clean['brand_id']  = (int) ($data['brand_id'] ?? 0);
    $clean['client_id'] = (int) ($data['client_id'] ?? 0);
    $clean['lead_id']   = (int) ($data['lead_id'] ?? 0);

    if ($clean['brand_id'] <= 0) {
        $errors[] = 'brand_required';
    }
    if ($clean['client_id'] <= 0 && $clean['lead_id'] <= 0) {
        $errors[] = 'link_required';   // a patient must link to a lead or a client
    }

    // Minimal personal data; Unicode/Turkish safe; sensible lengths.
    $clean['preferred_language'] = isset($data['preferred_language']) && $data['preferred_language'] !== ''
        ? mb_substr(trim((string) $data['preferred_language']), 0, 8) : null;
    $clean['nationality'] = isset($data['nationality']) && $data['nationality'] !== ''
        ? mb_substr(trim((string) $data['nationality']), 0, 64) : null;
    $clean['passport_no'] = isset($data['passport_no']) && $data['passport_no'] !== ''
        ? mb_substr(trim((string) $data['passport_no']), 0, 64) : null;

    return ['clean' => $clean, 'errors' => $errors];
}

/** Apply the brand scope to the current query builder (unless admin/all). */
function se_patient_apply_scope($CI)
{
    $ids = se_patient_scope_ids();
    if ($ids !== null) {
        $CI->db->where_in('brand_id', $ids ?: [0]);
    }
}

/** Brand-scoped patient list with optional search + pagination. */
function se_patient_list(array $filters = [])
{
    $CI = &get_instance();
    $t = db_prefix() . 'se_patients';

    se_patient_apply_scope($CI);
    if (empty($filters['include_archived'])) {
        $CI->db->where('retention_state !=', 'archived');
    }
    if (!empty($filters['search'])) {
        $s = (string) $filters['search'];
        $CI->db->group_start()
               ->like('nationality', $s)
               ->or_like('preferred_language', $s)
               ->group_end();
    }
    $CI->db->order_by('id', 'DESC');
    $limit  = max(1, min(100, (int) ($filters['limit'] ?? 25)));
    $offset = max(0, (int) ($filters['offset'] ?? 0));
    $CI->db->limit($limit, $offset);

    return $CI->db->get($t)->result_array();
}

/** Count for pagination (same scope/filters, no limit). */
function se_patient_count(array $filters = [])
{
    $CI = &get_instance();
    $t = db_prefix() . 'se_patients';
    se_patient_apply_scope($CI);
    if (empty($filters['include_archived'])) {
        $CI->db->where('retention_state !=', 'archived');
    }
    if (!empty($filters['search'])) {
        $s = (string) $filters['search'];
        $CI->db->group_start()->like('nationality', $s)->or_like('preferred_language', $s)->group_end();
    }
    return $CI->db->count_all_results($t);
}

/** Create a patient (brand-scoped input already validated by caller). */
function se_patient_create(array $clean)
{
    $CI = &get_instance();
    $clean['retention_state'] = 'active';
    $clean['date_created'] = date('Y-m-d H:i:s');
    $CI->db->insert(db_prefix() . 'se_patients', $clean);
    $id = (int) $CI->db->insert_id();
    if ($id) {
        se_patient_log_access((int) $clean['brand_id'], $id, 'create');
    }
    return $id;
}

/** Update a patient by id (must already be brand-authorised by the caller). */
function se_patient_update($id, array $clean)
{
    $CI = &get_instance();
    $clean['last_updated'] = date('Y-m-d H:i:s');
    $CI->db->where('id', (int) $id)->update(db_prefix() . 'se_patients', $clean);
    se_patient_log_access((int) $clean['brand_id'], (int) $id, 'edit');
    return true;
}

/** Soft-delete: archive (keeps consent + operational history). */
function se_patient_archive($id, $brand_id)
{
    $CI = &get_instance();
    $CI->db->where('id', (int) $id)->update(db_prefix() . 'se_patients', [
        'retention_state'       => 'archived',
        'deletion_requested_at' => date('Y-m-d H:i:s'),
        'last_updated'          => date('Y-m-d H:i:s'),
    ]);
    se_patient_log_access((int) $brand_id, (int) $id, 'archive');
    return true;
}

/** Linked lead / client / appointments for a patient (brand implicit via patient). */
function se_patient_links($patient)
{
    $CI = &get_instance();
    $out = ['lead' => null, 'client' => null, 'appointments' => []];
    if ((int) $patient->lead_id > 0) {
        $CI->db->select('id, name')->where('id', (int) $patient->lead_id);
        $out['lead'] = $CI->db->get(db_prefix() . 'leads')->row();
        $CI->db->where('rel_type', 'lead')->where('rel_id', (int) $patient->lead_id)
               ->where('brand_id', (int) $patient->brand_id)->order_by('start_at', 'DESC');
        $out['appointments'] = $CI->db->get(db_prefix() . 'se_appointments')->result_array();
    }
    if ((int) $patient->client_id > 0) {
        $CI->db->select('userid, company')->where('userid', (int) $patient->client_id);
        $out['client'] = $CI->db->get(db_prefix() . 'clients')->row();
    }
    return $out;
}

/** Consent ledger history for the patient's linked lead/client. */
function se_patient_consent_history($patient)
{
    $CI = &get_instance();
    $rel_type = (int) $patient->lead_id > 0 ? 'lead' : 'client';
    $rel_id   = (int) $patient->lead_id > 0 ? (int) $patient->lead_id : (int) $patient->client_id;
    $CI->db->where('brand_id', (int) $patient->brand_id)
           ->where('rel_type', $rel_type)->where('rel_id', $rel_id)->order_by('id', 'DESC');
    return $CI->db->get(db_prefix() . 'se_consent_ledger')->result_array();
}

/** Sensitive-record access history for the patient. */
function se_patient_audit_history($patient)
{
    $CI = &get_instance();
    $CI->db->where('brand_id', (int) $patient->brand_id)->where('patient_id', (int) $patient->id)
           ->order_by('id', 'DESC')->limit(50);
    return $CI->db->get(db_prefix() . 'se_record_access_log')->result_array();
}

/** Perfex capabilities for patient records (view/create/edit/delete). */
function se_patient_permissions()
{
    register_staff_capabilities('se_patients', ['capabilities' => [
        'view'   => _l('permission_view'),
        'create' => _l('permission_create'),
        'edit'   => _l('permission_edit'),
        'delete' => _l('permission_delete'),
    ]], _l('se_patients'));
}

/** Sidebar menu entry, gated on the view capability. */
function se_patient_menu()
{
    $CI = &get_instance();
    if (staff_can('view', 'se_patients')) {
        $CI->app_menu->add_sidebar_menu_item('se-patients', [
            'name'     => _l('se_patients'),
            'href'     => admin_url('se_core/se_patients'),
            'icon'     => 'fa fa-user-md',
            'position' => 29,
        ]);
    }
}
