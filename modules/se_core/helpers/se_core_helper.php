<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Per-request memo for the capability lookups below.
 *
 * A generation counter rather than function statics, so a test (or a job that
 * legitimately switches acting staff) can invalidate every cached answer at
 * once with se_authz_reset_cache(). Static locals cannot be cleared, which
 * would make the tenancy rules untestable.
 */
function &se_authz_cache()
{
    static $cache = [];

    return $cache;
}

/** Drop every memoized capability answer. */
function se_authz_reset_cache()
{
    $cache = &se_authz_cache();
    $cache = [];
}

function se_authz_memo($key, callable $resolver)
{
    $cache = &se_authz_cache();

    $staff = (int) get_staff_user_id();
    $slot  = $staff . ':' . $key;

    if (!array_key_exists($slot, $cache)) {
        $cache[$slot] = $resolver();
    }

    return $cache[$slot];
}

/**
 * Does the current staff member see EVERY brand?
 *
 * Only two things grant that: being a Perfex admin, or holding the explicit
 * `se_tenancy.all_brands` capability. It is deliberately NOT implied by
 * `se_brands.view` (brand configuration) or `se_reports.view` (reporting) —
 * that conflation is exactly the defect this replaces, because gating the
 * reports controller on `se_brands.view` promoted every reporting user to a
 * global tenant user.
 */
function se_staff_sees_all_brands()
{
    return se_authz_memo('all_brands', function () {
        return is_admin() || staff_can(SE_CAP_ALL_BRANDS, SE_FEATURE_TENANCY);
    });
}

/**
 * May the current staff member work the unassigned (brand 0) triage queue?
 *
 * Brand 0 is where a lead lands before its brand is known. It used to be
 * appended to EVERY staff member's brand set, which quietly made all
 * unassigned records — leads, patients, appointments, WhatsApp threads —
 * globally visible. It is now its own capability.
 */
function se_staff_can_triage()
{
    return se_authz_memo('triage', function () {
        return is_admin() || staff_can(SE_CAP_TRIAGE, SE_FEATURE_TENANCY);
    });
}

/** May the current staff member open the reporting screens? */
function se_staff_can_report()
{
    return is_admin()
        || staff_can('view', SE_FEATURE_REPORTS)
        || staff_can(SE_CAP_ALL_BRANDS, SE_FEATURE_TENANCY);
}

/** May the current staff member read/write brand CONFIGURATION? */
function se_staff_can_configure_brands()
{
    return is_admin() || staff_can('view', SE_FEATURE_BRANDS);
}

/**
 * The REAL brands mapped to the current staff member — never including the
 * brand-0 triage bucket.
 *
 * Kept separate from se_staff_brand_ids() because "which brands do you work
 * on" and "which rows may you see" are different questions. New-lead brand
 * stamping needs the former: with brand 0 folded in, a staff member mapped to
 * exactly one real brand looked like a two-brand user and their leads were
 * never stamped.
 */
function se_staff_real_brand_ids()
{
    return se_authz_memo('real_brand_ids', function () {
    $CI = &get_instance();

    // Standalone query: a caller may invoke this mid-build (e.g. a brand-scoped
    // model that has already set select()/join() on the shared query builder).
    // Using the query builder here would inherit that partial state and corrupt
    // both queries, so run raw SQL that leaves the shared builder untouched.
    $rows = $CI->db->query('SELECT brand_id FROM ' . db_prefix() . 'se_staff_brands WHERE staff_id = ' . (int) get_staff_user_id())->result_array();

    $ids = [];

    foreach ($rows as $row) {
        $id = (int) $row['brand_id'];
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
    });
}

/**
 * Brand ids whose ROWS the current staff member may see.
 *
 * The mapped real brands, plus the brand-0 triage bucket only when the
 * triage capability is held.
 */
function se_staff_brand_ids()
{
    return se_authz_memo('brand_ids', function () {
        $ids = se_staff_real_brand_ids();

        if (se_staff_can_triage()) {
            $ids[] = 0;
        }

        return array_values(array_unique($ids));
    });
}

function se_can_access_brand($brand_id)
{
    if (se_staff_sees_all_brands()) {
        return true;
    }

    return in_array((int) $brand_id, se_staff_brand_ids(), true);
}

/**
 * Canonical brand-scope SQL for a column. THE fail-closed primitive.
 *
 * Returns '' for a caller who legitimately sees everything, and `1=0` for a
 * caller with no reachable brands.
 *
 * Why this exists: five separate call sites each built their own
 * `IN (implode(...))`. For a staff member mapped to no brand at all,
 * se_staff_brand_ids() returns an EMPTY array, and every one of them then
 * produced `IN ()` — a MariaDB syntax error, i.e. a 500 on the leads list, the
 * kanban, appointments and the WhatsApp inbox. One of them was worse: the
 * patient scope used `$ids ?: [0]`, which silently substituted the brand-0
 * triage bucket and WIDENED access for exactly the user who should see nothing.
 *
 * An empty scope must deny, never error and never widen.
 */
function se_scope_in_sql($column)
{
    if (se_staff_sees_all_brands()) {
        return '';
    }

    $ids = array_map('intval', se_staff_brand_ids());

    if (!$ids) {
        return '1=0';   // fail closed
    }

    return $column . ' IN (' . implode(',', $ids) . ')';
}

/**
 * Apply se_scope_in_sql() to the shared query builder.
 * Returns false when the caller may see nothing at all.
 */
function se_apply_scope_in($column)
{
    $sql = se_scope_in_sql($column);

    if ($sql === '') {
        return true;
    }

    $CI = &get_instance();
    $CI->db->where($sql, null, false);

    return $sql !== '1=0';
}

/**
 * INNER JOIN that restricts a table to the staff member's brands.
 *
 * Returns an empty string for staff who see everything, so no join is added.
 * For a staff member with NO reachable brands it returns a join that cannot
 * match, rather than the `INNER JOIN ()` syntax error the old version emitted.
 */
function se_scope_join_sql($table)
{
    if (se_staff_sees_all_brands()) {
        return '';
    }

    $ids   = array_map('intval', se_staff_brand_ids());
    $alias = 'se_scope_' . substr(md5($table), 0, 8);

    if (!$ids) {
        // Deliberately unsatisfiable: a derived table with no rows.
        return 'INNER JOIN (SELECT NULL AS brand_id FROM DUAL WHERE 1=0) ' . $alias
            . ' ON ' . $alias . '.brand_id = ' . $table . '.brand_id';
    }

    $values = [];

    foreach ($ids as $id) {
        $values[] = 'SELECT ' . (int) $id . ' AS brand_id';
    }

    return 'INNER JOIN (' . implode(' UNION ', $values) . ') ' . $alias
        . ' ON ' . $alias . '.brand_id = ' . $table . '.brand_id';
}

/** Has the current staff member any reachable brand at all? */
function se_staff_has_any_brand()
{
    return se_staff_sees_all_brands() || count(se_staff_brand_ids()) > 0;
}

/**
 * Brands for pickers and settings screens.
 *
 * $accessible_only defaults to TRUE: an ordinary picker must never offer a
 * brand the staff member cannot reach, because offering it invites a POST that
 * the mutation guard then has to reject. Pass false only from a genuine
 * configuration screen that the caller has already authorized.
 */
function se_all_brands($active_only = true, $accessible_only = true)
{
    $CI = &get_instance();

    if ($active_only) {
        $CI->db->where('active', 1);
    }

    if ($accessible_only && !se_staff_sees_all_brands()) {
        $ids = array_values(array_filter(se_staff_brand_ids(), function ($id) {
            return (int) $id > 0;
        }));

        if (!$ids) {
            return [];
        }

        $CI->db->where_in('id', $ids);
    }

    $CI->db->order_by('name', 'ASC');

    return $CI->db->get(db_prefix() . 'se_brands')->result_array();
}

/**
 * The brand a screen should default to: the first brand this staff member can
 * actually reach, never the first globally-existing brand.
 */
function se_default_brand_id()
{
    $brands = se_all_brands(true, true);

    if ($brands) {
        return (int) $brands[0]['id'];
    }

    // No reachable brand. 0 is the triage bucket; a staff member without the
    // triage capability simply has nothing to show, and every downstream query
    // is brand-filtered anyway.
    return 0;
}

function se_brand_name($brand_id)
{
    if ((int) $brand_id === 0) {
        return _l('se_brand_unassigned');
    }

    $CI = &get_instance();
    $CI->db->select('name')->where('id', (int) $brand_id);
    $row = $CI->db->get(db_prefix() . 'se_brands')->row();

    return $row ? $row->name : _l('se_brand_unknown');
}

/**
 * Queues a conversion signal for a destination, WITH an immutable snapshot of
 * the attribution and consent state that applied at event time.
 *
 * Nothing is sent inline with a web request - cron drains the outbox. The dedup
 * key keeps a repeated stage change on the same day from producing duplicates.
 *
 * @param string $destination meta_capi|google_dm
 * @param string $event_name  pipeline stage name, treatment-agnostic
 */
function se_outbox_queue($brand_id, $lead_id, $destination, $event_name, array $payload = [], $event_time = null)
{
    $CI = &get_instance();

    $event_time = $event_time ?: date('Y-m-d H:i:s');

    $dedup = implode(':', [
        (int) $brand_id,
        (int) $lead_id,
        $destination,
        $event_name,
        date('Y-m-d', strtotime($event_time)),
    ]);

    $CI->db->where('dedup_key', $dedup);

    if ($CI->db->count_all_results(db_prefix() . 'se_conversion_outbox') > 0) {
        return false;
    }

    // Snapshot FIRST: it reads the lead row and the consent ledger, and doing
    // that mid-INSERT would pollute the shared query builder.
    $snapshot = function_exists('se_outbox_build_snapshot')
        ? se_outbox_build_snapshot($brand_id, $lead_id, $event_name, $event_time)
        : null;

    $row = [
        'brand_id'    => (int) $brand_id,
        'lead_id'     => (int) $lead_id,
        'destination' => $destination,
        'event_name'  => $event_name,
        'event_time'  => $event_time,
        'payload'     => json_encode($payload),
        'status'      => 'pending',
        'attempts'    => 0,
        'dedup_key'   => $dedup,
        'date_created' => date('Y-m-d H:i:s'),
        'next_attempt_at' => $event_time,
    ];

    if ($snapshot) {
        $row['attribution_snapshot'] = json_encode($snapshot['attribution']);
        $row['consent_snapshot']     = json_encode($snapshot['consent']);
        $row['payload_version']      = SE_OUTBOX_PAYLOAD_VERSION;
    }

    $CI->db->insert(db_prefix() . 'se_conversion_outbox', $row);

    return $CI->db->insert_id();
}
