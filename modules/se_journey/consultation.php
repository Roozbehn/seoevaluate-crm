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
    ]);
    if (!$id) {
        se_journey_audit((int) $j->brand_id, (int) $j->id, 'booking_refused', 'appointment', null, $type . ' slot conflict or invalid');

        return ['ok' => false, 'reason' => 'slot_unavailable', 'appointment_id' => 0];
    }

    $CI  = &get_instance();
    $now = date('Y-m-d H:i:s');
    if ($type === 'consultation') {
        $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', ['consultation_appointment_id' => (int) $id, 'last_updated' => $now]);
        $j->consultation_appointment_id = (int) $id;
        if (in_array((string) $j->state, ['consultation_recommended', 'quote_sent', 'consultation_booked'], true)) {
            se_journey_transition($j, 'consultation_booked', 'consultation_booked', 'staff', $staff_id, 'appointment:' . (int) $id);
        }
        se_journey_event($j, 'consultation_booked', $format . ' ' . $start, [], 'staff', $staff_id, 'appointment', (string) $id);
        if (function_exists('se_journey_send_copy')) {
            se_journey_send_copy($j, 'consultation_confirmation', ['when' => date('d.m.Y H:i', strtotime($start)), 'format' => $format === 'online' ? 'online' : 'klinikte'],
                ['purpose' => 'consultation_confirmation', 'bypass_pause' => true, 'template' => 'eyebrow_consultation_confirmation_tr',
                 'template_vars' => [se_journey_template_name($j), date('d.m.Y H:i', strtotime($start)), $format === 'online' ? 'online' : 'klinikte'],
                 'dedup_salt' => 'a' . (int) $id]);
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
        if (in_array((string) $j->state, ['consultation_completed', 'quote_sent', 'procedure_booked'], true)) {
            se_journey_transition($j, 'procedure_booked', 'procedure_booked', 'staff', $staff_id, 'appointment:' . (int) $id);
        }
        se_journey_event($j, 'procedure_booked', $start, [], 'staff', $staff_id, 'appointment', (string) $id);
        if (function_exists('se_journey_send_copy')) {
            se_journey_send_copy($j, 'procedure_confirmation', ['when' => date('d.m.Y H:i', strtotime($start))],
                ['purpose' => 'procedure_confirmation', 'bypass_pause' => true, 'template' => 'eyebrow_procedure_confirmation_tr',
                 'template_vars' => [se_journey_template_name($j), date('d.m.Y H:i', strtotime($start))], 'dedup_salt' => 'a' . (int) $id]);
        }
    }

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
        return ['ok' => false, 'reason' => 'slot_unavailable'];
    }
    se_journey_event($j, 'appointment_updated', implode(',', array_keys($allowed)), [], 'staff', $staff_id, 'appointment', (string) $appointment_id);
    se_journey_reflect_appointment($j, (int) $appointment_id, $staff_id, isset($data['outcome_note']) ? (string) $data['outcome_note'] : '');

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
