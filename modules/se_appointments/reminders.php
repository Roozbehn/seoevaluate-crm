<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Appointment reminder queue — an internal interface, not a sender.
 *
 * The appointment module never talks to Meta. It enqueues a reminder here; the
 * WhatsApp module (Phase 3) drains the queue once it is operational. Until then
 * rows sit 'pending' and nothing is sent. Enqueue is idempotent on a dedup key
 * so re-saving an appointment never double-queues the same reminder.
 */

/** Deterministic dedup key for one reminder of an appointment at a time. */
function se_reminder_dedup_key($appointment_id, $type, $scheduled_at)
{
    return 'appt:' . (int) $appointment_id . ':' . $type . ':' . substr((string) $scheduled_at, 0, 16);
}

/**
 * Queue a reminder. Returns the row id, or 0 if it already existed / was invalid.
 * No message is sent here — that is the WhatsApp module's job later.
 */
function se_reminder_enqueue($brand_id, $appointment_id, $scheduled_at, $type = 'appointment', $language = null, $template_ref = null)
{
    $appointment_id = (int) $appointment_id;
    if ($appointment_id <= 0 || empty($scheduled_at) || !strtotime($scheduled_at)) {
        return 0;
    }

    $CI = &get_instance();
    $table = db_prefix() . 'se_reminders';
    $key = se_reminder_dedup_key($appointment_id, $type, $scheduled_at);

    // Idempotent: the unique dedup_key makes a duplicate insert a no-op.
    $CI->db->where('dedup_key', $key);
    if ($CI->db->count_all_results($table) > 0) {
        return 0;
    }

    $CI->db->insert($table, [
        'brand_id'       => (int) $brand_id,
        'appointment_id' => $appointment_id,
        'type'           => mb_substr((string) $type, 0, 32),
        'channel'        => 'whatsapp',
        'language'       => $language ? mb_substr((string) $language, 0, 8) : null,
        'template_ref'   => $template_ref ? mb_substr((string) $template_ref, 0, 128) : null,
        'scheduled_at'   => $scheduled_at,
        'state'          => 'pending',
        'dedup_key'      => $key,
        'date_created'   => date('Y-m-d H:i:s'),
    ]);

    return (int) $CI->db->insert_id();
}

/** Cancel all still-pending reminders for an appointment (e.g. on cancel/reschedule). */
function se_reminder_cancel_for_appointment($appointment_id)
{
    $CI = &get_instance();
    $CI->db->where('appointment_id', (int) $appointment_id)
           ->where('state', 'pending')
           ->update(db_prefix() . 'se_reminders', ['state' => 'cancelled']);

    return $CI->db->affected_rows();
}

/**
 * The reminder offset before an appointment start, in hours (config-driven).
 * Default 24h. Returns the scheduled_at datetime string, or null if in the past.
 */
function se_reminder_schedule_for($start_at, $hours_before = null)
{
    $hours = $hours_before !== null ? (int) $hours_before : (int) (get_option('se_reminder_hours_before') ?: 24);
    $ts = strtotime($start_at);
    if (!$ts) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts - $hours * 3600);
}
