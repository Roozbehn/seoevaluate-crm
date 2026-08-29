<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Appointment availability + timezone helpers.
 *
 * Times are stored in the application timezone (Europe/Istanbul on staging) as
 * naive datetimes. Display conversions to a staff/clinic timezone happen here so
 * storage stays DST-safe and unambiguous.
 */

/** The clinic timezone for a brand (option override, else app default). */
function se_appt_clinic_tz($brand_id = 0)
{
    $tz = get_option('se_clinic_timezone_' . (int) $brand_id);
    if (!$tz) {
        $tz = get_option('default_timezone') ?: 'Europe/Istanbul';
    }
    return $tz;
}

/** Convert a stored datetime (app tz) to a target tz for display. */
function se_appt_display_in_tz($datetime, $target_tz, $app_tz = null)
{
    if (empty($datetime)) {
        return $datetime;
    }
    $app_tz = $app_tz ?: (get_option('default_timezone') ?: 'Europe/Istanbul');
    try {
        $dt = new DateTime($datetime, new DateTimeZone($app_tz));
        $dt->setTimezone(new DateTimeZone($target_tz));
        return $dt->format('Y-m-d H:i');
    } catch (Exception $e) {
        return $datetime;
    }
}

/**
 * Does [start,end) overlap an existing appointment for the same staff+brand?
 * Cancelled/no_show appointments never block. $ignore_id excludes the row being
 * edited. Returns true on overlap.
 */
function se_appt_has_overlap($brand_id, $staff_id, $start_at, $end_at, $ignore_id = 0)
{
    if (empty($start_at) || empty($end_at) || (int) $staff_id <= 0) {
        return false;
    }

    $CI = &get_instance();
    $table = db_prefix() . 'se_appointments';

    $CI->db->where('brand_id', (int) $brand_id)
           ->where('staff_id', (int) $staff_id)
           ->where('status NOT IN ("cancelled","no_show")')
           // classic overlap test: existing.start < new.end AND existing.end > new.start
           ->where('start_at <', $end_at)
           ->group_start()
               ->where('end_at >', $start_at)
               ->or_where('end_at IS NULL', null, false)
           ->group_end();

    if ((int) $ignore_id > 0) {
        $CI->db->where('id !=', (int) $ignore_id);
    }

    return $CI->db->count_all_results($table) > 0;
}

/**
 * Is [start,end) inside a defined working-hours window for the staff+brand on
 * that weekday? If no windows are defined for that staff/brand, availability is
 * unconstrained (returns true). Returns true when allowed.
 */
function se_appt_within_working_hours($brand_id, $staff_id, $start_at, $end_at)
{
    if (empty($start_at)) {
        return true;
    }

    $CI = &get_instance();
    $table = db_prefix() . 'se_working_hours';

    $weekday = (int) date('w', strtotime($start_at)); // 0=Sun..6=Sat

    $CI->db->where('brand_id', (int) $brand_id)->where('staff_id', (int) $staff_id);
    $total = $CI->db->count_all_results($table, false);
    if ($total === 0) {
        $CI->db->reset_query();
        return true; // no rules configured -> unconstrained
    }
    $CI->db->reset_query();

    $startClock = date('H:i:s', strtotime($start_at));
    $endClock   = $end_at ? date('H:i:s', strtotime($end_at)) : $startClock;

    $CI->db->where('brand_id', (int) $brand_id)
           ->where('staff_id', (int) $staff_id)
           ->where('weekday', $weekday)
           ->where('start_time <=', $startClock)
           ->where('end_time >=', $endClock);

    return $CI->db->count_all_results($table) > 0;
}

/**
 * Staff who may be assigned to an appointment, restricted to the brands the
 * current user can reach. A bare numeric staff_id input invited assigning
 * another tenant's staff member.
 */
function se_appt_selectable_staff($brand_id = 0)
{
    $CI = &get_instance();

    if ((int) $brand_id > 0) {
        $ids = [(int) $brand_id];
    } elseif (se_staff_sees_all_brands()) {
        $ids = [];
    } else {
        $ids = array_values(array_filter(se_staff_brand_ids(), function ($id) { return (int) $id > 0; }));
    }

    if ($ids) {
        $rows = $CI->db->query(
            'SELECT DISTINCT staff_id FROM ' . db_prefix() . 'se_staff_brands'
            . ' WHERE brand_id IN (' . implode(',', array_map('intval', $ids)) . ')'
        )->result_array();

        $staffIds = array_map(function ($r) { return (int) $r['staff_id']; }, $rows);

        if (!$staffIds) {
            return [];
        }

        $CI->db->where_in('staffid', $staffIds);
    }

    $CI->db->select('staffid, firstname, lastname')->where('active', 1)->order_by('firstname', 'ASC');

    return $CI->db->get(db_prefix() . 'staff')->result_array();
}

