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

/*
 * se_patient_upsert() was REMOVED.
 *
 * It was unused, and it wrote brand_id/lead_id/client_id straight from its
 * caller with no authorization check, no link validation and no brand
 * predicate on the UPDATE. An unused function that can re-tenant a patient row
 * is a loaded gun in the drawer; the guarded create/update path below is the
 * only way in now.
 */

/* ---------------------------------------------------------------------------
 * Passport numbers.
 *
 * Policy: DO NOT COLLECT until operationally required, and never store or
 * redisplay in plaintext.
 *
 * Collection is off by default. Turning it on additionally requires an
 * encryption provider (framework-reviewed authenticated encryption, key held
 * outside Git and outside the database). No such provider is implemented here
 * and no custom cryptography is invented, so with collection enabled but no
 * provider registered the field is REFUSED rather than written in the clear.
 * Failing closed is the point: the alternative is a plaintext passport number
 * in every database dump.
 * ------------------------------------------------------------------------ */

/** Is passport collection switched on by the owner? Default: no. */
function se_patient_passport_collection_enabled()
{
    return (int) get_option('se_patient_collect_passport') === 1;
}

/**
 * Is an authenticated-encryption provider available for passport storage?
 *
 * A provider registers itself as a callable in $GLOBALS['SE_PATIENT_CRYPTO']
 * exposing encrypt()/decrypt(). None ships with this module.
 */
function se_patient_crypto_available()
{
    $p = $GLOBALS['SE_PATIENT_CRYPTO'] ?? null;

    return is_array($p) && is_callable($p['encrypt'] ?? null) && is_callable($p['decrypt'] ?? null);
}

/** Mask a passport number for display. Never renders the full value. */
function se_patient_mask_passport($value)
{
    $value = (string) $value;

    if ($value === '') {
        return '';
    }

    $tail = mb_substr($value, -2);

    return str_repeat('•', max(4, mb_strlen($value) - 2)) . $tail;
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

    // The acting staff member must be allowed on the target brand at all.
    if ($clean['brand_id'] > 0 && function_exists('se_can_access_brand')
        && !se_can_access_brand($clean['brand_id'])) {
        $errors[] = 'brand_denied';
    }

    // Linked records must EXIST and must belong to the SAME brand. A cross-brand
    // link is rejected outright and never silently rewritten to something valid:
    // quietly repointing a patient at a different record is a data-integrity
    // failure dressed up as a convenience.
    if ($clean['lead_id'] > 0) {
        $leadBrand = function_exists('se_record_brand') ? se_record_brand('lead', $clean['lead_id']) : null;

        if ($leadBrand === null) {
            $errors[] = 'lead_not_found';
        } elseif ((int) $leadBrand !== $clean['brand_id']) {
            $errors[] = 'lead_brand_mismatch';
        }
    }

    if ($clean['client_id'] > 0) {
        $clientBrand = function_exists('se_record_brand') ? se_record_brand('client', $clean['client_id']) : null;

        if ($clientBrand === null) {
            $errors[] = 'client_not_found';
        } elseif ((int) $clientBrand !== $clean['brand_id']) {
            $errors[] = 'client_brand_mismatch';
        }
    }

    // Minimal personal data; Unicode/Turkish safe; sensible lengths.
    $clean['preferred_language'] = isset($data['preferred_language']) && $data['preferred_language'] !== ''
        ? mb_substr(trim((string) $data['preferred_language']), 0, 8) : null;
    $clean['nationality'] = isset($data['nationality']) && $data['nationality'] !== ''
        ? mb_substr(trim((string) $data['nationality']), 0, 64) : null;
    // Passport: not collected unless the owner enabled it AND an authenticated
    // encryption provider exists. Otherwise the field is dropped (collection
    // off) or the save is refused (enabled but unprotected) - never stored in
    // plaintext.
    $passport = isset($data['passport_no']) ? trim((string) $data['passport_no']) : '';

    if ($passport === '') {
        $clean['passport_no'] = null;
    } elseif (!se_patient_passport_collection_enabled()) {
        $clean['passport_no'] = null;          // silently not collected, by policy
    } elseif (!se_patient_crypto_available()) {
        $errors[] = 'passport_storage_unavailable';
        $clean['passport_no'] = null;
    } else {
        $clean['passport_no'] = call_user_func(
            $GLOBALS['SE_PATIENT_CRYPTO']['encrypt'],
            mb_substr($passport, 0, 64)
        );
    }

    return ['clean' => $clean, 'errors' => $errors];
}

/** Apply the brand scope to the current query builder (unless admin/all). */
/**
 * Apply the brand scope to the current query builder.
 *
 * `$ids ?: [0]` used to substitute the brand-0 triage bucket when a staff
 * member had no mapped brands — widening access for precisely the user who
 * should see nothing. It now fails closed.
 */
function se_patient_apply_scope($CI)
{
    se_apply_scope_in('brand_id');
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

/**
 * Update a patient by id.
 *
 * The brand is part of the SQL predicate and the affected row count is checked,
 * so a caller that skipped its own authorization still writes nothing.
 *
 * @return bool true only when a row was actually updated
 */
function se_patient_update($id, array $clean)
{
    $clean['last_updated'] = date('Y-m-d H:i:s');

    $affected = se_guarded_update(db_prefix() . 'se_patients', 'id', (int) $id, $clean);

    if ($affected > 0) {
        se_patient_log_access((int) $clean['brand_id'], (int) $id, 'edit');
    }

    return $affected > 0;
}

/**
 * Archive a patient (soft delete; consent and operational history are kept).
 *
 * ARCHIVING IS NOT A DELETION REQUEST. The old code stamped
 * `deletion_requested_at` when a staff member merely filed a record away, which
 * destroyed the ability to tell "we tidied this up" from "the data subject
 * asked us to erase this" - and the second one carries a legal deadline.
 * Archive state now lives in archived_at/archived_by.
 *
 * @return bool true only when a row was actually archived
 */
function se_patient_archive($id, $brand_id)
{
    $affected = se_guarded_update(db_prefix() . 'se_patients', 'id', (int) $id, [
        'retention_state' => 'archived',
        'archived_at'     => date('Y-m-d H:i:s'),
        'archived_by'     => (int) get_staff_user_id(),
        'last_updated'    => date('Y-m-d H:i:s'),
    ]);

    if ($affected > 0) {
        se_patient_log_access((int) $brand_id, (int) $id, 'archive');
    }

    return $affected > 0;
}

/**
 * Record a data-subject deletion request. Separate from archiving, on purpose.
 */
function se_patient_request_deletion($id, $brand_id)
{
    $affected = se_guarded_update(db_prefix() . 'se_patients', 'id', (int) $id, [
        'deletion_requested_at' => date('Y-m-d H:i:s'),
        'last_updated'          => date('Y-m-d H:i:s'),
    ]);

    if ($affected > 0) {
        se_patient_log_access((int) $brand_id, (int) $id, 'deletion_requested');
    }

    return $affected > 0;
}

/**
 * Would this (brand, lead) or (brand, client) link duplicate an existing
 * patient? The database carries supporting indexes; the business rule that
 * 0 means "not linked" cannot be expressed as a MariaDB unique constraint, so
 * it is enforced here.
 *
 * @return bool true when a conflicting row exists
 */
function se_patient_link_conflict($brand_id, $lead_id, $client_id, $exclude_id = 0)
{
    $CI = &get_instance();

    foreach ([['lead_id', (int) $lead_id], ['client_id', (int) $client_id]] as [$column, $value]) {
        if ($value <= 0) {
            continue;
        }

        $CI->db->where('brand_id', (int) $brand_id)->where($column, $value);

        if ($exclude_id > 0) {
            $CI->db->where('id !=', (int) $exclude_id);
        }

        if ($CI->db->count_all_results(db_prefix() . 'se_patients') > 0) {
            return true;
        }
    }

    return false;
}

/** Linked lead / client / appointments for a patient (brand implicit via patient). */
function se_patient_links($patient)
{
    $CI = &get_instance();
    $out = ['lead' => null, 'client' => null, 'appointments' => []];
    // The PATIENT's brand is applied to the linked reads. Without it, a stale or
    // hostile lead_id/client_id on the patient row would render another
    // tenant's lead or customer straight onto this page.
    if ((int) $patient->lead_id > 0) {
        $CI->db->select('id, name')
               ->where('id', (int) $patient->lead_id)
               ->where('brand_id', (int) $patient->brand_id);
        $out['lead'] = $CI->db->get(db_prefix() . 'leads')->row();

        $CI->db->where('rel_type', 'lead')->where('rel_id', (int) $patient->lead_id)
               ->where('brand_id', (int) $patient->brand_id)->order_by('start_at', 'DESC');
        $out['appointments'] = $CI->db->get(db_prefix() . 'se_appointments')->result_array();
    }

    if ((int) $patient->client_id > 0) {
        $CI->db->select('userid, company')
               ->where('userid', (int) $patient->client_id)
               ->where('brand_id', (int) $patient->brand_id);
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
    // Registered by se_core/se_navigation.php as part of the grouped
    // "SEO Evaluate CRM" section. Kept as a no-op for compatibility.
}

/* ---------------------------------------------------------------------------
 * Scoped selectors for the patient form.
 *
 * Raw numeric brand/lead/client inputs invited a foreign id and then relied on
 * the validator to bounce it. Offering only linkable records removes the
 * invitation; the validator still enforces the rule.
 * ------------------------------------------------------------------------ */

/** Leads this staff member may link, optionally restricted to one brand. */
function se_patient_selectable_leads($brand_id = 0)
{
    $CI = &get_instance();

    $CI->db->select('id, name, brand_id');

    if ((int) $brand_id > 0) {
        $CI->db->where('brand_id', (int) $brand_id);
    } else {
        se_patient_apply_scope($CI);
    }

    $CI->db->order_by('id', 'DESC')->limit(500);

    return $CI->db->get(db_prefix() . 'leads')->result_array();
}

/** Customers this staff member may link, optionally restricted to one brand. */
function se_patient_selectable_clients($brand_id = 0)
{
    $CI = &get_instance();

    $CI->db->select('userid, company, brand_id');

    if ((int) $brand_id > 0) {
        $CI->db->where('brand_id', (int) $brand_id);
    } else {
        se_patient_apply_scope($CI);
    }

    $CI->db->order_by('userid', 'DESC')->limit(500);

    return $CI->db->get(db_prefix() . 'clients')->result_array();
}

