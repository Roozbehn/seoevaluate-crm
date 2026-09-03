<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * se_journey — consultation and procedure scheduling.
 *
 * Booking goes THROUGH the existing se_appointments model: it owns the
 * per-staff advisory lock, the overlap check, working hours, status history,
 * reminders and calendar sync. This file only maps journey steps onto
 * appointment rows and keeps the journey state in step with them.
 *
 * Deposits are a STATE and a reference, never card data.
 */

function se_journey_appointments_model()
{
    $CI = &get_instance();
    if (isset($CI->se_appointments_model) && is_object($CI->se_appointments_model)) {
        return $CI->se_appointments_model;
    }
    if (!class_exists('Se_appointments_model')) {
        $path = dirname(__DIR__) . '/se_appointments/models/Se_appointments_model.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
    if (!class_exists('Se_appointments_model')) {
        return null;
    }
    $CI->se_appointments_model = new Se_appointments_model();

    return $CI->se_appointments_model;
}

/**
 * Attach an appointment that already exists (booked here or in the calendar
 * form with ?journey=) to the journey: link id, move the state, log the event
 * and queue the patient confirmation. Idempotent per appointment+start (the
 * dedup salt carries the start time, so a reschedule sends a fresh one —
 * CRM-M044 / AZCRM-AP-003).
 */
function se_journey_link_appointment($j, $id, $staff_id, array $data = [])
{
    $CI = &get_instance();
    $CI->db->where('id', (int) $id)->where('brand_id', (int) $j->brand_id);
    $appt = $CI->db->get(db_prefix() . 'se_appointments')->row();
    if (!$appt || (string) $appt->rel_type !== 'lead' || (int) $appt->rel_id !== (int) $j->lead_id) {
        return false;
    }
    $type   = (string) ($appt->appointment_type ?? '') === 'procedure' ? 'procedure' : 'consultation';
    $start  = (string) $appt->start_at;
    $format = (string) ($appt->consultation_format ?? 'in_person') === 'online' ? 'online' : 'in_person';
    $actor  = (int) $staff_id > 0 ? 'staff' : 'patient';
    $salt   = 'a' . (int) $id . ':' . (int) strtotime($start);
    $now = date('Y-m-d H:i:s');
    if ($type === 'consultation') {
        $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', ['consultation_appointment_id' => (int) $id, 'last_updated' => $now]);
        $j->consultation_appointment_id = (int) $id;
        if (in_array((string) $j->state, ['consultation_recommended', 'quote_sent', 'quote_accepted', 'quote_revision_requested', 'consultation_booked'], true)) {
            se_journey_transition($j, 'consultation_booked', 'consultation_booked', $actor, $staff_id ?: null, 'appointment:' . (int) $id);
        }
        se_journey_event($j, 'consultation_booked', $format . ' ' . $start . ($actor === 'patient' ? ' (patient self-booked)' : ''), [], $actor, $staff_id ?: null, 'appointment', (string) $id);
        if (function_exists('se_journey_send_copy')) {
            // The confirmation carries an "add to calendar" link (.ics). Outside the
            // window the 4-variable template goes when Meta has approved it; else
            // the original 3-variable one (the booking page still offers the file).
            $when = date('d.m.Y H:i', strtotime($start));
            $fmt  = $format === 'online' ? 'online' : 'klinikte';
            $cal  = se_journey_calendar_link($j, (int) $staff_id);
            $tpl  = se_journey_template_ready((int) $j->brand_id, 'eyebrow_consultation_calendar_tr');
            $spec = ['purpose' => 'consultation_confirmation', 'bypass_pause' => true, 'dedup_salt' => $salt];
            if ($tpl['ready'] && $cal !== '') {
                $spec['template'] = 'eyebrow_consultation_calendar_tr';
                $spec['template_vars'] = [se_journey_template_name($j), $when, $fmt, $cal];
            } else {
                $spec['template'] = 'eyebrow_consultation_confirmation_tr';
                $spec['template_vars'] = [se_journey_template_name($j), $when, $fmt];
            }
            se_journey_send_copy($j, 'consultation_confirmation', ['when' => $when, 'format' => $fmt, 'link' => $cal], $spec);
        }
    } else {
        $upd = ['procedure_appointment_id' => (int) $id, 'last_updated' => $now];
        if (!empty($data['deposit_state']) && in_array((string) $data['deposit_state'], ['none', 'requested', 'received', 'refunded'], true)) {
            $upd['deposit_state'] = (string) $data['deposit_state'];
        }
        if (isset($data['payment_ref'])) {
            $upd['payment_ref'] = mb_substr(preg_replace('/\d{8,}/', '[ref]', (string) $data['payment_ref']), 0, 64);   // never a card number
        }
        $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', $upd);
        $j->procedure_appointment_id = (int) $id;
        if (in_array((string) $j->state, ['consultation_completed', 'quote_sent', 'quote_accepted', 'procedure_booked'], true)) {
            se_journey_transition($j, 'procedure_booked', 'procedure_booked', 'staff', $staff_id, 'appointment:' . (int) $id);
        }
        se_journey_event($j, 'procedure_booked', $start, [], 'staff', $staff_id, 'appointment', (string) $id);
        if (function_exists('se_journey_send_copy')) {
            se_journey_send_copy($j, 'procedure_confirmation', ['when' => date('d.m.Y H:i', strtotime($start))],
                ['purpose' => 'procedure_confirmation', 'bypass_pause' => true, 'template' => 'eyebrow_procedure_confirmation_tr',
                 'template_vars' => [se_journey_template_name($j), date('d.m.Y H:i', strtotime($start))], 'dedup_salt' => $salt]);
        }
    }


    return true;
}

/**
 * Book a consultation or a procedure slot.
 *
 * @param array $data start_at, end_at, staff_id, consultation_format (online|in_person), location, notes
 * @return array{ok:bool,reason:string,appointment_id:int}
 */
function se_journey_book_appointment($j, array $data, $staff_id, $type = 'consultation')
{
    if (!in_array($type, ['consultation', 'procedure'], true)) {
        return ['ok' => false, 'reason' => 'bad_type', 'appointment_id' => 0];
    }
    if ((int) $j->lead_id <= 0) {
        return ['ok' => false, 'reason' => 'no_lead', 'appointment_id' => 0];
    }
    $model = se_journey_appointments_model();
    if (!$model) {
        return ['ok' => false, 'reason' => 'appointments_unavailable', 'appointment_id' => 0];
    }
    $start = trim((string) ($data['start_at'] ?? ''));
    $end   = trim((string) ($data['end_at'] ?? ''));
    if ($start === '' || strtotime($start) === false) {
        return ['ok' => false, 'reason' => 'start_required', 'appointment_id' => 0];
    }
    if ($end === '' || strtotime($end) === false) {
        $end = date('Y-m-d H:i:s', strtotime($start) + ($type === 'procedure' ? 4 * 3600 : 30 * 60));
    }
    $format = (string) ($data['consultation_format'] ?? 'in_person') === 'online' ? 'online' : 'in_person';
    $title  = $type === 'procedure' ? 'Kaş ekimi işlemi' : ($format === 'online' ? 'Online ön görüşme' : 'Klinikte ön görüşme');
    // No staff id = the patient booked it (secure booking page) or the system:
    // no staff session to scope by; the model still checks the calendar.
    $system = (int) $staff_id <= 0 || !empty($data['system']);
    $actor  = (int) $staff_id > 0 ? 'staff' : 'patient';

    $id = $model->add([
        'brand_id'            => (int) $j->brand_id,
        'title'               => $title,
        'rel_type'            => 'lead',
        'rel_id'              => (int) $j->lead_id,
        'staff_id'            => (int) ($data['staff_id'] ?? 0),
        'start_at'            => $start,
        'end_at'              => $end,
        'status'              => 'scheduled',
        'appointment_type'    => $type,
        'consultation_format' => $format,
        'location'            => (string) ($data['location'] ?? ''),
        'notes'               => (string) ($data['notes'] ?? ''),
        'staff_timezone'      => (string) ($data['staff_timezone'] ?? ''),
    ], ['system' => $system]);
    if (!$id) {
        se_journey_audit((int) $j->brand_id, (int) $j->id, 'booking_refused', 'appointment', null, $type . ' slot conflict or invalid');

        return ['ok' => false, 'reason' => 'slot_unavailable', 'appointment_id' => 0];
    }

    se_journey_link_appointment($j, (int) $id, (int) $staff_id, $data);

    return ['ok' => true, 'reason' => '', 'appointment_id' => (int) $id];
}

/** Reschedule / cancel / mark held — through the model, then reflect on the journey. */
function se_journey_appointment_update($j, $appointment_id, array $data, $staff_id)
{
    $model = se_journey_appointments_model();
    if (!$model) {
        return ['ok' => false, 'reason' => 'appointments_unavailable'];
    }
    $appt = $model->get((int) $appointment_id);
    if (!$appt || (int) $appt->brand_id !== (int) $j->brand_id || (int) $appt->rel_id !== (int) $j->lead_id) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $allowed = [];
    foreach (['start_at', 'end_at', 'staff_id', 'status', 'notes', 'location', 'cancellation_reason', 'consultation_format'] as $k) {
        if (isset($data[$k])) { $allowed[$k] = $data[$k]; }
    }
    if (!$allowed) {
        return ['ok' => false, 'reason' => 'nothing_to_update'];
    }
    if (!$model->update((int) $appointment_id, $allowed)) {
        return ['ok' => false, 'reason' => 'slot_unavailable', 'message' => (string) ($model->last_message ?? '')];
    }
    se_journey_event($j, 'appointment_updated', implode(',', array_keys($allowed)), [], 'staff', $staff_id, 'appointment', (string) $appointment_id);
    // A moved consultation gets a fresh confirmation (CRM-M044): the salt carries the new start.
    $after = $model->get((int) $appointment_id);
    if ($after && isset($allowed['start_at']) && strtotime((string) $after->start_at) !== strtotime((string) $appt->start_at)
        && (string) ($after->appointment_type ?? '') !== 'procedure' && !in_array((string) $after->status, ['cancelled', 'no_show', 'completed', 'held'], true)
        && function_exists('se_journey_send_copy')) {
        $when = date('d.m.Y H:i', strtotime((string) $after->start_at));
        $fmt  = (string) ($after->consultation_format ?? '') === 'online' ? 'online' : 'klinikte';
        se_journey_send_copy($j, 'consultation_confirmation', ['when' => $when, 'format' => $fmt, 'link' => ''],
            ['purpose' => 'consultation_confirmation', 'bypass_pause' => true, 'template' => 'eyebrow_consultation_confirmation_tr',
             'template_vars' => [se_journey_template_name($j), $when, $fmt], 'dedup_salt' => 'a' . (int) $appointment_id . ':' . (int) strtotime((string) $after->start_at)]);
        se_journey_event($j, 'consultation_rescheduled', $when, [], 'staff', $staff_id, 'appointment', (string) $appointment_id);
    }
    se_journey_reflect_appointment($j, (int) $appointment_id, $staff_id, isset($data['outcome_note']) ? (string) $data['outcome_note'] : '');
    if (function_exists('se_journey_sync_lead')) { se_journey_sync_lead($j, 'appointment'); }   // a reschedule/confirm changes no state

    return ['ok' => true, 'reason' => ''];
}

/** Map an appointment's status onto the journey state (idempotent). */
function se_journey_reflect_appointment($j, $appointment_id, $staff_id = 0, $note = '')
{
    $CI = &get_instance();
    $CI->db->where('id', (int) $appointment_id)->where('brand_id', (int) $j->brand_id);
    $a = $CI->db->get(db_prefix() . 'se_appointments')->row();
    if (!$a) {
        return false;
    }
    $isProcedure = (string) ($a->appointment_type ?? '') === 'procedure' || (int) $j->procedure_appointment_id === (int) $appointment_id;
    $actor = $staff_id ? 'staff' : 'system';

    if (!$isProcedure) {
        if (in_array((string) $a->status, ['held', 'completed'], true) && (string) $j->state === 'consultation_booked') {
            se_journey_transition($j, 'consultation_completed', 'consultation_' . $a->status, $actor, $staff_id ?: null, 'appointment:' . (int) $appointment_id, $note !== '' ? mb_substr($note, 0, 500) : null);
            se_journey_task($j, 'consultation_outcome', 'Consultation held — record outcome and next action', 'normal', null, (string) $appointment_id);
        } elseif (in_array((string) $a->status, ['cancelled', 'no_show'], true) && (string) $j->state === 'consultation_booked') {
            se_journey_transition($j, 'consultation_recommended', 'consultation_' . $a->status, $actor, $staff_id ?: null, 'appointment:' . (int) $appointment_id);
            se_journey_task($j, 'rebook_consultation', 'Consultation ' . $a->status . ' — rebook or close', 'normal', null, (string) $appointment_id);
        }
    } else {
        if (in_array((string) $a->status, ['cancelled', 'no_show'], true) && in_array((string) $j->state, ['procedure_booked', 'preop_pending'], true)) {
            se_journey_transition($j, 'consultation_completed', 'procedure_' . $a->status, $actor, $staff_id ?: null, 'appointment:' . (int) $appointment_id, null, true);
            se_journey_task($j, 'rebook_procedure', 'Procedure ' . $a->status . ' — rebook or close', 'normal', null, (string) $appointment_id);
        }
    }

    return true;
}

/** Cron: keep journeys in step with appointment status changes made elsewhere. */
function se_journey_sync_appointments($limit = 200)
{
    $CI = &get_instance();
    $n = 0;
    $CI->db->where_in('state', ['consultation_booked', 'procedure_booked', 'preop_pending'])->order_by('id', 'ASC')->limit(max(1, (int) $limit));
    foreach ($CI->db->get(db_prefix() . 'se_journeys')->result_array() as $row) {
        $j = se_journey_get_raw((int) $row['id']);
        $id = (string) $row['state'] === 'consultation_booked' ? (int) $row['consultation_appointment_id'] : (int) $row['procedure_appointment_id'];
        if ($id > 0 && se_journey_reflect_appointment($j, $id)) {
            $n++;
        }
    }

    return $n;
}

/* ===========================================================================
 * Patient self-booking: a face-to-face consultation slot picked from the
 * clinic calendar (token page, no CRM session).
 *
 * Availability = the consultation calendar's working hours (se_working_hours
 * rows for that staff member when defined, else the brand's default hours
 * and days from Journey Settings) minus existing appointments on the same
 * calendar. The final write goes through the appointments model, which
 * re-checks overlap and working hours under the per-calendar lock — the page
 * list is a convenience, never the authority.
 * ======================================================================== */

define('SE_JOURNEY_BOOKING_DEFAULT_HOURS', '10:00-18:00');
define('SE_JOURNEY_BOOKING_DEFAULT_DAYS', '1,2,3,4,5,6');   // Mon–Sat (0 = Sunday)

/** Per-brand booking configuration with safe bounds. */
function se_journey_booking_settings($brand_id)
{
    $b = (int) $brand_id;
    $int = function ($key, $default, $min, $max) use ($b) {
        $v = get_option('se_journey_booking_' . $key . '_' . $b);
        $v = ($v === '' || $v === null) ? $default : (int) $v;

        return max($min, min($max, $v));
    };
    $hours = (string) get_option('se_journey_booking_hours_' . $b);
    if (!preg_match('/^\d{1,2}:\d{2}-\d{1,2}:\d{2}$/', $hours)) {
        $hours = SE_JOURNEY_BOOKING_DEFAULT_HOURS;
    }
    $daysRaw = (string) get_option('se_journey_booking_days_' . $b);
    $days = [];
    foreach (preg_split('/[^\d]+/', $daysRaw !== '' ? $daysRaw : SE_JOURNEY_BOOKING_DEFAULT_DAYS) as $d) {
        if ($d !== '' && (int) $d >= 0 && (int) $d <= 6) { $days[] = (int) $d; }
    }
    $days = array_values(array_unique($days));
    sort($days);

    return [
        'staff_id'     => $int('staff', 0, 0, PHP_INT_MAX),
        'slot_minutes' => $int('slot', 30, 15, 180),
        'days_ahead'   => $int('horizon', 14, 1, 60),
        'notice_hours' => $int('notice', 24, 0, 168),
        'hours'        => $hours,
        'days'         => $days,
        'location'     => mb_substr(trim((string) get_option('se_journey_booking_location_' . $b)), 0, 191),
    ];
}

/** The calendar (staff member) patient bookings land on: the setting, else the brand's first active staff. */
function se_journey_booking_staff($brand_id, ?array $cfg = null)
{
    $cfg = $cfg ?: se_journey_booking_settings($brand_id);
    if ((int) $cfg['staff_id'] > 0) {
        return (int) $cfg['staff_id'];
    }
    if (function_exists('se_appt_selectable_staff')) {
        foreach (se_appt_selectable_staff((int) $brand_id) as $s) {
            return (int) $s['staffid'];
        }
    }

    return 0;
}

/** Working windows [startClock, endClock] for one weekday (0=Sun..6=Sat). */
function se_journey_booking_windows($brand_id, $staff_id, $weekday, array $cfg)
{
    $CI = &get_instance();
    $CI->db->where('brand_id', (int) $brand_id)->where('staff_id', (int) $staff_id);
    $rows = $CI->db->get(db_prefix() . 'se_working_hours')->result_array();
    if ($rows) {
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r['weekday'] === (int) $weekday) {
                $out[] = [substr((string) $r['start_time'], 0, 5), substr((string) $r['end_time'], 0, 5)];
            }
        }

        return $out;
    }
    if (!in_array((int) $weekday, $cfg['days'], true)) {
        return [];
    }
    [$from, $to] = explode('-', $cfg['hours']);

    return [[$from, $to]];
}

/**
 * Free in-person slots on the booking calendar from now+notice to the horizon.
 *
 * @return array{ok:bool,reason:string,staff_id:int,slot_minutes:int,slots:array,days:array}
 *         slots: [['start' => 'Y-m-d H:i:s', 'end' => ...], ...]; days: date => [slots]
 */
function se_journey_booking_slots($brand_id, $now = null)
{
    $cfg   = se_journey_booking_settings($brand_id);
    $staff = se_journey_booking_staff($brand_id, $cfg);
    $empty = ['ok' => false, 'reason' => '', 'staff_id' => $staff, 'slot_minutes' => $cfg['slot_minutes'], 'slots' => [], 'days' => [], 'cfg' => $cfg];
    if ($staff <= 0) {
        $empty['reason'] = 'no_calendar';

        return $empty;
    }
    $now      = $now ? (int) $now : time();
    $earliest = $now + $cfg['notice_hours'] * 3600;
    $step     = $cfg['slot_minutes'] * 60;
    $lastDay  = strtotime('+' . (int) $cfg['days_ahead'] . ' days', strtotime(date('Y-m-d', $now)));
    $horizon  = $lastDay + 86400;

    // Existing bookings on this calendar in range, once (cancelled/no-show never block).
    $CI = &get_instance();
    $CI->db->where('brand_id', (int) $brand_id)->where('staff_id', (int) $staff)
           ->where('status NOT IN ("cancelled","no_show")')
           ->where('start_at <', date('Y-m-d H:i:s', $horizon));
    $busy = [];
    foreach ($CI->db->get(db_prefix() . 'se_appointments')->result_array() as $a) {
        $s = strtotime((string) $a['start_at']);
        $e = !empty($a['end_at']) ? strtotime((string) $a['end_at']) : null;
        if ($s !== false) { $busy[] = [$s, $e]; }
    }
    $isBusy = function ($s, $e) use ($busy) {
        foreach ($busy as $b) {
            if ($b[0] < $e && ($b[1] === null || $b[1] === false || $b[1] > $s)) { return true; }
        }

        return false;
    };

    $slots = $days = [];
    for ($d = strtotime(date('Y-m-d', $now)); $d <= $lastDay; $d += 86400) {
        $date = date('Y-m-d', $d);
        foreach (se_journey_booking_windows($brand_id, $staff, (int) date('w', $d), $cfg) as $w) {
            $t   = strtotime($date . ' ' . $w[0] . ':00');
            $end = strtotime($date . ' ' . $w[1] . ':00');
            if ($t === false || $end === false) { continue; }
            for (; $t + $step <= $end; $t += $step) {
                if ($t < $earliest || $isBusy($t, $t + $step)) { continue; }
                $slot = ['start' => date('Y-m-d H:i:s', $t), 'end' => date('Y-m-d H:i:s', $t + $step)];
                $slots[] = $slot;
                $days[$date][] = $slot;
            }
        }
    }

    return ['ok' => true, 'reason' => $slots ? '' : 'no_slots', 'staff_id' => $staff, 'slot_minutes' => $cfg['slot_minutes'],
            'slots' => $slots, 'days' => $days, 'cfg' => $cfg];
}

/** The journey's consultation appointment row (brand-scoped direct read; the model's get() needs a staff session). */
function se_journey_consultation_appointment($j)
{
    if ((int) $j->consultation_appointment_id <= 0) {
        return null;
    }
    $CI = &get_instance();
    $CI->db->where('id', (int) $j->consultation_appointment_id)->where('brand_id', (int) $j->brand_id);

    return $CI->db->get(db_prefix() . 'se_appointments')->row();
}

/** A live (not cancelled/no-show/past) consultation booking, if any. */
function se_journey_consultation_upcoming($j)
{
    $a = se_journey_consultation_appointment($j);
    if (!$a || in_array((string) $a->status, ['cancelled', 'no_show', 'completed', 'held'], true)) {
        return null;
    }
    if (strtotime((string) $a->start_at) < time() - 3600) {
        return null;
    }

    return $a;
}

/**
 * The patient picked a slot on the booking page. The slot must be one the
 * page could have offered right now (recomputed, never trusted from the
 * form), then the appointment is created through the model.
 *
 * @return array{ok:bool,reason:string,appointment_id:int}
 */
function se_journey_booking_pick($j, $slot_start, $via = 'page')
{
    if (se_journey_consultation_upcoming($j)) {
        return ['ok' => false, 'reason' => 'already_booked', 'appointment_id' => (int) $j->consultation_appointment_id];
    }
    if (!in_array((string) $j->state, ['quote_accepted', 'quote_sent', 'consultation_recommended', 'quote_revision_requested'], true)) {
        return ['ok' => false, 'reason' => 'state', 'appointment_id' => 0];
    }
    $want = strtotime(trim((string) $slot_start));
    if ($want === false) {
        return ['ok' => false, 'reason' => 'bad_slot', 'appointment_id' => 0];
    }
    $want = date('Y-m-d H:i:s', $want);
    $avail = se_journey_booking_slots((int) $j->brand_id);
    if (!$avail['ok']) {
        return ['ok' => false, 'reason' => $avail['reason'], 'appointment_id' => 0];
    }
    $chosen = null;
    foreach ($avail['slots'] as $s) {
        if ($s['start'] === $want) { $chosen = $s; break; }
    }
    if (!$chosen) {
        se_journey_audit((int) $j->brand_id, (int) $j->id, 'booking_slot_rejected', 'appointment', null, 'not offered: ' . $want);

        return ['ok' => false, 'reason' => 'slot_unavailable', 'appointment_id' => 0];
    }
    $r = se_journey_book_appointment($j, [
        'start_at' => $chosen['start'], 'end_at' => $chosen['end'], 'staff_id' => (int) $avail['staff_id'],
        'consultation_format' => 'in_person', 'location' => (string) $avail['cfg']['location'],
        'notes' => 'Hasta güvenli bağlantıdan seçti (' . $via . ')', 'system' => true,
    ], 0, 'consultation');
    if ($r['ok']) {
        se_journey_audit((int) $j->brand_id, (int) $j->id, 'booking_self_service', 'appointment', (string) $r['appointment_id'], $chosen['start'] . ' via ' . $via);
        se_journey_task($j, 'consultation_self_booked', 'Patient booked a face-to-face consultation from the calendar — confirm the slot', 'normal', null, (string) $r['appointment_id']);
    }

    return $r;
}

/**
 * Send (or re-send) the secure booking link. Called after the patient accepts
 * the quote, by staff from the journey page, and on a "randevu/link" reply.
 *
 * @return array{ok:bool,reason:string,link:string,mode:string}
 */
function se_journey_send_booking_link($j, $issued_by = 0, $correlation = '', $copy_key = 'quote_accepted_ack')
{
    // Inside WhatsApp (the booking Flow) when published; else the calendar page link.
    if (function_exists('se_journey_flow_ready') && se_journey_flow_ready((int) $j->brand_id, 'booking')['ready']) {
        $r = se_journey_send_flow($j, 'booking', se_journey_copy((int) $j->brand_id, 'booking_flow', ['name' => se_journey_first_name($j)], (string) $j->language), $correlation,
            ['purpose' => 'booking_flow', 'issued_by' => (int) $issued_by, 'bypass_pause' => true]);
        if ($r['ok']) {
            return ['ok' => true, 'reason' => '', 'link' => '', 'mode' => 'flow'];
        }
    }
    $tok = se_journey_issue_token($j, 'book', (int) $issued_by);
    if (!$tok['ok']) {
        return ['ok' => false, 'reason' => 'token_failed', 'link' => '', 'mode' => ''];
    }
    $link = se_journey_public_url('se_journey/intake/' . $tok['token'] . '/book');
    $r = se_journey_send_copy($j, $copy_key, ['link' => $link], [
        'purpose' => $copy_key, 'bypass_pause' => true, 'correlation' => $correlation,
        'template' => 'eyebrow_booking_link_tr', 'template_vars' => [se_journey_template_name($j), $link],
        'dedup_salt' => 't' . (int) $tok['id'],
    ]);

    return ['ok' => (bool) $r['ok'], 'reason' => (string) $r['reason'], 'link' => $link, 'mode' => (string) $r['mode']];
}

/* ===========================================================================
 * "Add to calendar": an iCalendar file for the booked consultation, served
 * from a token page (WhatsApp cannot carry text/calendar as a document —
 * Meta's document types are PDF/Office/plain text — so the message links
 * to the file; a tap opens the phone's calendar with the event).
 * ======================================================================== */

/** A fresh calendar link for the journey's consultation ('' when nothing is booked). */
function se_journey_calendar_link($j, $issued_by = 0)
{
    if (!se_journey_consultation_appointment($j)) {
        return '';
    }
    $tok = se_journey_issue_token($j, 'calendar', (int) $issued_by, false);   // earlier links stay valid

    return $tok['ok'] ? se_journey_public_url('se_journey/intake/' . $tok['token'] . '/calendar') : '';
}

/** RFC 5545 text escaping. */
function se_journey_ics_text($v)
{
    return str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\;", "\\,", "\\n", "\\n"], (string) $v);
}

/** RFC 5545 line folding (75 octets, continuation lines start with a space). */
function se_journey_ics_fold($line)
{
    $out = '';
    while (strlen($line) > 75) {
        $cut = 75;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) { $cut--; }   // never split a UTF-8 sequence
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }

    return $out . $line;
}

/** A stored (app-timezone) datetime as an iCalendar UTC stamp. */
function se_journey_ics_utc($datetime, $tz)
{
    try {
        $d = new DateTime((string) $datetime, new DateTimeZone($tz));
        $d->setTimezone(new DateTimeZone('UTC'));

        return $d->format('Ymd\THis\Z');
    } catch (Exception $e) {
        return gmdate('Ymd\THis\Z', strtotime((string) $datetime));
    }
}

/**
 * The .ics for one appointment of the journey (consultation or procedure).
 * Times are converted from the clinic timezone to UTC; the phone shows them
 * in its own zone. No health data, no phone number of the patient.
 */
function se_journey_calendar_ics($j, $a)
{
    $tz     = function_exists('se_appt_clinic_tz') ? se_appt_clinic_tz((int) $j->brand_id) : (get_option('default_timezone') ?: 'Europe/Istanbul');
    $cfg    = function_exists('se_journey_booking_settings') ? se_journey_booking_settings((int) $j->brand_id) : ['location' => ''];
    $isProc = (string) ($a->appointment_type ?? '') === 'procedure';
    $online = (string) ($a->consultation_format ?? '') === 'online';
    $clinic = defined('SE_CLINIC_NAME') ? SE_CLINIC_NAME : 'Azin Asgari – Kaş Ekimi Uzmanı';
    $summary = $isProc ? 'Kaş ekimi işlemi – ' . $clinic : ($online ? 'Online ön görüşme – ' : 'Klinikte ön görüşme – ') . $clinic;
    $desc = ($isProc ? 'Kaş ekimi işleminiz.' : ($online ? 'Online ön görüşmeniz.' : 'Klinikte yüz yüze ön görüşmeniz.'))
          . ' Değişiklik veya iptal için WhatsApp üzerinden yazabilirsiniz: +90 547 120 70 70';
    $location = !empty($a->location) ? (string) $a->location : (string) ($cfg['location'] ?? '');
    $end = !empty($a->end_at) ? (string) $a->end_at : date('Y-m-d H:i:s', strtotime((string) $a->start_at) + 30 * 60);
    $host = parse_url(se_journey_public_url(''), PHP_URL_HOST) ?: 'crm';

    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//' . se_journey_ics_text($clinic) . '//Patient journey//TR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:journey-' . (int) $j->id . '-appointment-' . (int) $a->id . '@' . $host,
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . se_journey_ics_utc($a->start_at, $tz),
        'DTEND:' . se_journey_ics_utc($end, $tz),
        'SUMMARY:' . se_journey_ics_text($summary),
        'DESCRIPTION:' . se_journey_ics_text($desc),
    ];
    if ($location !== '' && !$online) {
        $lines[] = 'LOCATION:' . se_journey_ics_text($location);
    }
    $lines[] = 'STATUS:' . (in_array((string) $a->status, ['cancelled', 'no_show'], true) ? 'CANCELLED' : 'CONFIRMED');
    $lines[] = 'BEGIN:VALARM';
    $lines[] = 'TRIGGER:-P1D';
    $lines[] = 'ACTION:DISPLAY';
    $lines[] = 'DESCRIPTION:' . se_journey_ics_text('Yarın: ' . $summary);
    $lines[] = 'END:VALARM';
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    return implode("\r\n", array_map('se_journey_ics_fold', $lines)) . "\r\n";
}

/** Google Calendar "add event" URL for the same appointment (a link, no file). */
function se_journey_calendar_google_url($j, $a)
{
    $tz  = function_exists('se_appt_clinic_tz') ? se_appt_clinic_tz((int) $j->brand_id) : 'Europe/Istanbul';
    $cfg = function_exists('se_journey_booking_settings') ? se_journey_booking_settings((int) $j->brand_id) : ['location' => ''];
    $end = !empty($a->end_at) ? (string) $a->end_at : date('Y-m-d H:i:s', strtotime((string) $a->start_at) + 30 * 60);
    $online = (string) ($a->consultation_format ?? '') === 'online';
    $q = [
        'action'   => 'TEMPLATE',
        'text'     => ((string) ($a->appointment_type ?? '') === 'procedure' ? 'Kaş ekimi işlemi' : ($online ? 'Online ön görüşme' : 'Klinikte ön görüşme')) . ' – Azin Asgari',
        'dates'    => se_journey_ics_utc($a->start_at, $tz) . '/' . se_journey_ics_utc($end, $tz),
        'details'  => 'Değişiklik veya iptal için WhatsApp: +90 547 120 70 70',
        'ctz'      => $tz,
    ];
    $loc = !empty($a->location) ? (string) $a->location : (string) ($cfg['location'] ?? '');
    if ($loc !== '' && !$online) {
        $q['location'] = $loc;
    }

    return 'https://calendar.google.com/calendar/render?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
}

/* ===========================================================================
 * Pre-op and procedure completion
 * ======================================================================== */

/** Pre-op checklist items (clinic-configurable; defaults are logistics only, never medical advice). */
function se_journey_preop_checklist($brand_id)
{
    $raw = json_decode((string) get_option('se_journey_preop_checklist_' . (int) $brand_id), true);
    if (is_array($raw) && $raw) {
        return array_values(array_map(function ($x) { return mb_substr((string) $x, 0, 120); }, $raw));
    }

    return ['Tarih/saat teyidi', 'Klinik adresi ve ulaşım bilgisi paylaşıldı', 'Gerekli belgeler ve onam formları hazır',
            'Tercüman ihtiyacı soruldu', 'Depozito durumu kaydedildi', 'Klinisyen hazırlık talimatları (kişiye özel) iletildi'];
}

/**
 * The three azinasgari.com pages that already carry the clinically reviewed,
 * published account of the procedure, pre-op preparation and recovery — the
 * CRM never restates this content in a WhatsApp message, it only links to it,
 * so a wording change is made once, on the site, and nothing here can drift
 * out of sync with what passed the content-safety and clinical-claims gates.
 */
function se_journey_consultation_info_urls()
{
    return [
        'procedure_link'   => 'https://azinasgari.com/tr/procedure',
        'preparation_link' => 'https://azinasgari.com/tr/preparation',
        'recovery_link'    => 'https://azinasgari.com/tr/recovery',
    ];
}

/**
 * Right after a quote is sent: general information about the procedure, what
 * to do before it and what recovery looks like — three links, no restated
 * clinical content. Sent ONLY when a staff member has ticked "Consultation
 * information approved", the same gate as the pre-op message, because turning
 * this on changes what an automated message tells a real patient. Until then
 * the same moment creates a staff task so nothing silently goes unsent.
 */
function se_journey_send_consultation_information($j)
{
    $approved = (int) get_option('se_journey_consultation_info_approved_' . (int) $j->brand_id) === 1;
    if (!$approved || !function_exists('se_journey_send_copy')) {
        se_journey_task($j, 'consultation_info_unapproved', 'Consultation information (procedure/prep/recovery links) is not approved yet — share manually if needed', 'normal', null, '');

        return ['ok' => false, 'reason' => 'not_approved', 'mode' => 'skipped', 'outbound_id' => 0];
    }

    return se_journey_send_copy($j, 'consultation_information', se_journey_consultation_info_urls(),
        ['purpose' => 'consultation_information', 'bypass_pause' => true, 'dedup_salt' => 'ci' . (int) $j->id]);
}

/** Move to pre-op; send the information message ONLY when the approved text/link exists. */
function se_journey_preop_start($j, $staff_id)
{
    if ((string) $j->state !== 'procedure_booked') {
        return ['ok' => false, 'reason' => 'transition_not_allowed'];
    }
    se_journey_transition($j, 'preop_pending', 'preop_started', 'staff', $staff_id);
    se_journey_task($j, 'preop_checklist', 'Complete the pre-procedure checklist', 'normal', null, '');
    $approved = (int) get_option('se_journey_preop_text_approved_' . (int) $j->brand_id) === 1;
    $link = trim((string) get_option('se_journey_preop_info_url_' . (int) $j->brand_id));
    if ($approved && $link !== '' && function_exists('se_journey_send_copy')) {
        se_journey_send_copy($j, 'preop_information', ['link' => $link], ['purpose' => 'preop_information', 'bypass_pause' => true,
            'template' => 'eyebrow_preop_information_tr', 'template_vars' => [se_journey_template_name($j), $link]]);
    } else {
        se_journey_task($j, 'preop_text_unapproved', 'Pre-op information text/link is not approved by counsel/medical director — send instructions manually', 'normal', null, '');
    }

    return ['ok' => true, 'reason' => ''];
}

/**
 * Procedure done: record in the existing procedure-history table, stamp the
 * journey, then start aftercare. Technical fields (grafts etc.) are stored
 * only when the clinic enabled them.
 */
function se_journey_procedure_complete($j, $staff_id, $notes = '', array $technical = [], $procedure_at = null)
{
    if (!in_array((string) $j->state, ['preop_pending', 'procedure_booked'], true)) {
        return ['ok' => false, 'reason' => 'transition_not_allowed'];
    }
    $CI  = &get_instance();
    $now = date('Y-m-d H:i:s');
    $at  = $procedure_at && strtotime((string) $procedure_at) !== false ? date('Y-m-d H:i:s', strtotime((string) $procedure_at)) : $now;

    if ((string) $j->state === 'procedure_booked') {
        se_journey_transition($j, 'preop_pending', 'preop_skipped', 'staff', $staff_id);
    }
    if ((int) $j->patient_id > 0) {
        $note = mb_substr((string) $notes, 0, 5000);
        if ($technical && (int) get_option('se_journey_technical_fields_' . (int) $j->brand_id) === 1) {
            $tech = [];
            foreach (['grafts', 'technique', 'anesthesia', 'duration_min'] as $k) {
                if (isset($technical[$k]) && $technical[$k] !== '') { $tech[$k] = mb_substr((string) $technical[$k], 0, 64); }
            }
            if ($tech) { $note .= "\n[technical] " . json_encode($tech); }
        }
        $CI->db->insert(db_prefix() . 'se_procedure_history', [
            'brand_id' => (int) $j->brand_id, 'patient_id' => (int) $j->patient_id, 'procedure_name' => 'Kaş ekimi',
            'procedure_date' => date('Y-m-d', strtotime($at)), 'notes' => $note, 'date_created' => $now,
        ]);
    }
    $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', ['procedure_at' => $at, 'last_updated' => $now]);
    $j->procedure_at = $at;
    se_journey_transition($j, 'procedure_completed', 'procedure_completed', 'staff', $staff_id, null, null);
    se_journey_audit((int) $j->brand_id, (int) $j->id, 'procedure_complete', null, null, null);
    if (function_exists('se_outbox_queue') && function_exists('se_outbox_destinations_for_brand') && (int) $j->lead_id > 0) {
        foreach (se_outbox_destinations_for_brand((int) $j->brand_id) as $dest) {
            se_outbox_queue((int) $j->brand_id, (int) $j->lead_id, $dest, 'Treated');
        }
    }
    se_journey_task($j, 'aftercare_plan', 'Procedure recorded — select the aftercare protocol', 'normal', null, '');

    return ['ok' => true, 'reason' => ''];
}
