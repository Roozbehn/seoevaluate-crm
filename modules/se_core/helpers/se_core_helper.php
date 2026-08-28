<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Does the current staff member see every brand?
 *
 * Agency admins and anyone holding the global leads view permission do.
 * Everyone else is limited to the brands mapped to them.
 */
function se_staff_sees_all_brands()
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = is_admin() || staff_can('view', 'se_brands');

    return $cache;
}

/**
 * Brand ids the current staff member may see.
 *
 * Always contains 0 - the unassigned bucket - so a lead that arrives before
 * its brand is known stays visible rather than silently vanishing.
 */
function se_staff_brand_ids()
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $CI = &get_instance();

    // Standalone query: a caller may invoke this mid-build (e.g. a brand-scoped
    // model that has already set select()/join() on the shared query builder).
    // Using the query builder here would inherit that partial state and corrupt
    // both queries, so run raw SQL that leaves the shared builder untouched.
    $rows = $CI->db->query('SELECT brand_id FROM ' . db_prefix() . 'se_staff_brands WHERE staff_id = ' . (int) get_staff_user_id())->result_array();

    $ids = array_map(function ($row) {
        return (int) $row['brand_id'];
    }, $rows);

    $ids[] = 0;

    $cache = array_values(array_unique($ids));

    return $cache;
}

function se_can_access_brand($brand_id)
{
    if (se_staff_sees_all_brands()) {
        return true;
    }

    return in_array((int) $brand_id, se_staff_brand_ids(), true);
}

/**
 * INNER JOIN that restricts a table to the staff member's brands.
 *
 * Returns an empty string for staff who see everything, so no join is added.
 * The subquery includes brand 0 so unassigned records stay visible.
 */
function se_scope_join_sql($table)
{
    if (se_staff_sees_all_brands()) {
        return '';
    }

    $ids   = se_staff_brand_ids();
    $alias = 'se_scope_' . substr(md5($table), 0, 8);

    $values = [];

    foreach ($ids as $id) {
        $values[] = 'SELECT ' . (int) $id . ' AS brand_id';
    }

    return 'INNER JOIN (' . implode(' UNION ', $values) . ') ' . $alias
        . ' ON ' . $alias . '.brand_id = ' . $table . '.brand_id';
}

/**
 * All brands, for pickers and settings screens.
 */
function se_all_brands($active_only = true)
{
    $CI = &get_instance();

    if ($active_only) {
        $CI->db->where('active', 1);
    }

    $CI->db->order_by('name', 'ASC');

    return $CI->db->get(db_prefix() . 'se_brands')->result_array();
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
 * Queues a conversion signal for a destination.
 *
 * Nothing is sent inline with a web request - cron drains the outbox. The
 * dedup key keeps a repeated stage change from producing duplicate events.
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

    $CI->db->insert(db_prefix() . 'se_conversion_outbox', [
        'brand_id'    => (int) $brand_id,
        'lead_id'     => (int) $lead_id,
        'destination' => $destination,
        'event_name'  => $event_name,
        'event_time'  => $event_time,
        'payload'     => json_encode($payload),
        'status'      => 'pending',
        'attempts'    => 0,
        'dedup_key'   => $dedup,
        'date_created'=> date('Y-m-d H:i:s'),
    ]);

    return $CI->db->insert_id();
}
