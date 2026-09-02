<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Centralized tenant (brand) authorization.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The previous design collapsed three unrelated ideas into one capability:
 * `staff_can('view', 'se_brands')` simultaneously meant "may read brand
 * configuration", "may open the reporting screens" and "may reach every
 * tenant's data". Because the reports controller gated on that same
 * capability, granting anybody reporting access silently promoted them to a
 * global tenant user. Those three concerns are now three separate features:
 *
 *   se_brands   view/create/edit/delete  - brand CONFIGURATION only.
 *   se_reports  view                     - reporting screens only.
 *   se_tenancy  all_brands               - explicit cross-brand data reach.
 *               triage_unassigned        - explicit reach into brand 0.
 *
 * None of view/create/edit/delete on any feature implies cross-brand access.
 * Only `se_tenancy.all_brands` (or being an admin) does, and it has to be
 * granted deliberately.
 *
 * ENFORCEMENT MODEL
 * -----------------
 * List scoping (JOINs, kanban filters) hides rows; it does not stop
 * `/admin/leads/lead/123`. Authorization therefore happens at three layers:
 *
 *   1. se_authz_request_guard()  - every admin request, BEFORE the controller.
 *      Default-CHECK, not default-allow: for the record-bearing controllers we
 *      validate every numeric id we can see (URI segments and known request
 *      parameters), and only an explicit list of routes that carry a
 *      non-record id (lead statuses, sources, forms, ...) is skipped. Adding a
 *      new Perfex route therefore inherits protection instead of bypassing it.
 *   2. se_guard_* helpers        - used by our own models so every UPDATE and
 *      DELETE carries the authorized brand in its SQL predicate and verifies
 *      the affected row count.
 *   3. se_require_record()       - explicit checks inside our controllers.
 */

/* ---------------------------------------------------------------------------
 * Capability vocabulary. Defined once so code and tests cannot drift apart.
 * ------------------------------------------------------------------------- */

define('SE_FEATURE_BRANDS', 'se_brands');    // brand configuration
define('SE_FEATURE_REPORTS', 'se_reports');  // reporting screens
define('SE_FEATURE_TENANCY', 'se_tenancy');  // cross-brand data reach

define('SE_CAP_ALL_BRANDS', 'all_brands');
define('SE_CAP_TRIAGE', 'triage_unassigned');

/**
 * Record types we can authorize by primary key.
 *
 * type => [table, primary key column, brand column]
 */
function se_authz_record_types()
{
    $p = db_prefix();

    return [
        'lead'            => [$p . 'leads', 'id', 'brand_id'],
        'client'          => [$p . 'clients', 'userid', 'brand_id'],
        'patient'         => [$p . 'se_patients', 'id', 'brand_id'],
        'appointment'     => [$p . 'se_appointments', 'id', 'brand_id'],
        'wa_conversation' => [$p . 'se_wa_conversations', 'id', 'brand_id'],
        'wa_message'      => [$p . 'se_wa_messages', 'id', 'brand_id'],
        'journey'         => [$p . 'se_journeys', 'id', 'brand_id'],
        'journey_media'   => [$p . 'se_journey_media', 'id', 'brand_id'],
        'journey_quote'   => [$p . 'se_journey_quotes', 'id', 'brand_id'],
        'outbox'          => [$p . 'se_conversion_outbox', 'id', 'brand_id'],
        'brand'           => [$p . 'se_brands', 'id', 'id'],
    ];
}

/**
 * The brand a record belongs to, or null when the record does not exist.
 *
 * Returning null for a missing row is deliberate: the request guard scans
 * numeric ids opportunistically, and an id that resolves to nothing must not
 * be treated as a cross-tenant hit.
 */
function se_record_brand($type, $id)
{
    $types = se_authz_record_types();

    if (!isset($types[$type]) || (int) $id <= 0) {
        return null;
    }

    [$table, $pk, $brandCol] = $types[$type];

    $CI = &get_instance();

    // Raw query: callers may be mid-build on the shared CI query builder.
    $sql = 'SELECT `' . $brandCol . '` AS brand_id FROM `' . $table . '`'
         . ' WHERE `' . $pk . '` = ' . (int) $id . ' LIMIT 1';

    $row = $CI->db->query($sql)->row();

    return $row ? (int) $row->brand_id : null;
}

/** True when the current staff member may reach this record. */
function se_can_access_record($type, $id)
{
    $brand = se_record_brand($type, $id);

    if ($brand === null) {
        return true; // no such record - not a tenancy decision
    }

    return se_can_access_brand($brand);
}

/** Deny the request unless the current staff member may reach this record. */
function se_require_record($type, $id)
{
    if (!se_can_access_record($type, $id)) {
        se_core_deny();
    }
}

/* ---------------------------------------------------------------------------
 * Mutation-boundary helpers.
 *
 * Every UPDATE/DELETE our own models issue must name the authorized brands in
 * its own WHERE clause, so a race, a stale read or a missed controller check
 * still cannot write across a tenant boundary.
 * ------------------------------------------------------------------------- */

/**
 * SQL fragment restricting a table to the brands the caller may write.
 * Returns '' for a caller that legitimately sees everything.
 */
function se_brand_predicate($table_alias = null, $column = 'brand_id')
{
    if (se_staff_sees_all_brands()) {
        return '';
    }

    $ids = se_staff_brand_ids();

    if (!$ids) {
        return '1=0'; // no brands mapped: authorize nothing
    }

    $col = ($table_alias ? '`' . $table_alias . '`.' : '') . '`' . $column . '`';

    return $col . ' IN (' . implode(',', array_map('intval', $ids)) . ')';
}

/**
 * Guarded UPDATE: the authorized brands are part of the predicate and the
 * affected row count is returned so the caller can detect a refused write.
 *
 * ORDER MATTERS. se_brand_predicate() may have to run its own query to resolve
 * the staff-to-brand mapping, and running a query in the middle of building
 * one is exactly the query-builder pollution that produced the original
 * `Unknown table` 500s. So the predicate is resolved FIRST, into a plain
 * string, and only then is the builder touched.
 *
 * @return int rows actually updated (0 means "not yours" or "no change")
 */
function se_guarded_update($table, $pk, $id, array $data, $brandColumn = 'brand_id')
{
    $predicate = se_brand_predicate(null, $brandColumn);   // resolve first

    $CI = &get_instance();

    $CI->db->where($pk, (int) $id);

    if ($predicate !== '') {
        $CI->db->where($predicate, null, false);
    }

    $CI->db->update($table, $data);

    return (int) $CI->db->affected_rows();
}

/**
 * Guarded DELETE. Same contract, and the same resolve-then-build ordering.
 *
 * @return int rows actually deleted
 */
function se_guarded_delete($table, $pk, $id, $brandColumn = 'brand_id')
{
    $predicate = se_brand_predicate(null, $brandColumn);   // resolve first

    $CI = &get_instance();

    $CI->db->where($pk, (int) $id);

    if ($predicate !== '') {
        $CI->db->where($predicate, null, false);
    }

    $CI->db->delete($table);

    return (int) $CI->db->affected_rows();
}

/**
 * Add the brand predicate to an in-flight SELECT.
 *
 * Callers MUST have resolved any other sub-queries already; see the ordering
 * note on se_guarded_update().
 */
function se_apply_brand_predicate($table_alias = null, $column = 'brand_id')
{
    $predicate = se_brand_predicate($table_alias, $column);

    if ($predicate === '') {
        return true;
    }

    $CI = &get_instance();
    $CI->db->where($predicate, null, false);

    return true;
}

/* ---------------------------------------------------------------------------
 * Request guard.
 * ------------------------------------------------------------------------- */

/**
 * Controllers whose numeric ids identify a tenant-owned record.
 *
 * `segments`  - URI segment indexes that may carry the record id.
 * `params`    - GET/POST keys that may carry the record id.
 * `list_params` - GET/POST keys carrying an ARRAY of record ids (bulk actions).
 * `skip`      - methods whose ids are NOT records of this type (lead statuses,
 *               sources, form definitions, ...). This is the only allow-list,
 *               and it is small, explicit and about non-record routes only.
 */
function se_authz_guarded_controllers()
{
    return [
        'leads' => [
            'type'        => 'lead',
            'segments'    => [4, 5],
            'params'      => ['id', 'leadid', 'lead_id', 'rel_id', 'main_lead', 'merge_lead'],
            'list_params' => ['ids', 'leads'],
            'skip'        => [
                'statuses', 'status', 'delete_status', 'update_status_order',
                'change_status_color', 'sources', 'source', 'delete_source',
                'forms', 'form', 'delete_form', 'save_form_data',
                'email_integration', 'email_integration_folders',
                'test_email_integration', 'import', 'validate_unique_field',
                'switch_kanban', 'table', 'kanban', 'leads_kanban_load_more',
            ],
        ],
        'clients' => [
            'type'        => 'client',
            'segments'    => [3, 4],
            'params'      => ['id', 'clientid', 'client_id', 'customer_id', 'userid'],
            'list_params' => ['ids', 'customers'],
            'skip'        => [
                'groups', 'group', 'delete_group', 'import', 'table',
                'all_contacts', 'check_duplicate_customer_name',
                'update_all_proposal_emails_linked_to_customer', 'export',
            ],
        ],
    ];
}

/**
 * Collect every candidate record id visible in the current request for a
 * guarded controller: URI segments plus known parameters plus bulk arrays.
 */
function se_authz_candidate_ids(array $config)
{
    $CI  = &get_instance();
    $ids = [];

    foreach ($config['segments'] as $index) {
        $value = $CI->uri->segment($index);
        if (is_numeric($value) && (int) $value > 0) {
            $ids[] = (int) $value;
        }
    }

    foreach ($config['params'] as $key) {
        $value = $CI->input->post_get($key);
        if (is_numeric($value) && (int) $value > 0) {
            $ids[] = (int) $value;
        }
    }

    foreach ($config['list_params'] as $key) {
        $value = $CI->input->post_get($key);
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_numeric($item) && (int) $item > 0) {
                    $ids[] = (int) $item;
                }
            }
        }
    }

    return array_values(array_unique($ids));
}

/**
 * Runs on every admin request before the controller acts.
 *
 * Denies as soon as any identifiable record in the request belongs to a brand
 * the caller may not reach. Covers direct numeric ids, crafted POST bodies,
 * AJAX calls and bulk/status actions, because it never looks at the HTTP verb
 * or at a hand-maintained list of "dangerous" methods.
 */
function se_authz_request_guard()
{
    $CI = &get_instance();

    if (!is_staff_logged_in() || se_staff_sees_all_brands()) {
        return;
    }

    $controller = $CI->uri->segment(2);
    $method     = $CI->uri->segment(3) ?: 'index';

    $guarded = se_authz_guarded_controllers();

    if (!isset($guarded[$controller])) {
        return;
    }

    $config = $guarded[$controller];

    if (in_array($method, $config['skip'], true)) {
        return;
    }

    foreach (se_authz_candidate_ids($config) as $id) {
        if (!se_can_access_record($config['type'], $id)) {
            se_core_deny();
        }
    }
}
