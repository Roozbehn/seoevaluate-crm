<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * WhatsApp calling — the CRM records calls, it does not carry them.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 * Meta's Calling API hands the business an SDP offer and expects it to answer
 * and then carry DTLS-SRTP media itself. That needs a WebRTC or SIP stack; the
 * cPanel/LiteSpeed host cannot run one, and pretending otherwise would mean
 * accepting calls we then drop — worse for a patient than a phone that simply
 * rings in the WhatsApp Business app, which is where staff answer today.
 *
 * So this handler NEVER calls pre_accept or accept. It writes the record: who
 * called, when, how long, and whether anyone picked up. That is the half that
 * produces the clinic value, because the expensive failure is a missed call
 * nobody follows up, and today that is invisible — the call happens entirely
 * inside someone's phone and leaves no trace in the CRM at all.
 *
 * Answering inside the CRM stays possible later without touching this file:
 * it is a media plane behind the same two webhooks.
 *
 * IDEMPOTENCE
 * One call produces two webhooks (connect, then terminate) and Meta redelivers
 * both. `call_id` is the unique key, connect inserts, terminate updates. A
 * redelivered connect must not create a second row and must not re-notify.
 */

/** Meta's `changes[].field` for calling webhooks. */
define('SE_WA_CALLS_FIELD', 'calls');

/**
 * Handles one entry of `value.calls[]`.
 *
 * `$call` is Meta's object: id, to, from, event, timestamp, direction, and on
 * terminate also status, duration, start_time, end_time.
 */
function se_wa_handle_call($brand_id, $phone_number_id, array $call)
{
    $CI = &get_instance();

    $call_id = isset($call['id']) ? mb_substr((string) $call['id'], 0, 191) : '';
    $event   = isset($call['event']) ? (string) $call['event'] : '';
    if ($call_id === '' || ($event !== 'connect' && $event !== 'terminate')) {
        return false;
    }

    $table = db_prefix() . 'se_wa_calls';
    $CI->db->where('call_id', $call_id);
    $existing = $CI->db->get($table)->row();

    if ($event === 'connect') {
        // A redelivered connect must not create a second row, and must not
        // ring the staff phone again.
        if ($existing) {
            return false;
        }

        $from = isset($call['from']) ? mb_substr((string) $call['from'], 0, 32) : '';
        if ($from === '') {
            return false;
        }

        /* Attach to the existing thread when there is one. A call from a
         * number nobody has messaged is still worth recording — it just has no
         * conversation to hang off yet, and conversation_id 0 says so honestly
         * rather than inventing a thread. */
        $conv_id = 0;
        $assigned = 0;
        $CI->db->where('brand_id', (int) $brand_id)->where('wa_user_id', $from);
        $conv = $CI->db->get(db_prefix() . 'se_wa_conversations')->row();
        if ($conv) {
            $conv_id  = (int) $conv->id;
            $assigned = (int) $conv->assigned_staff;
        }

        $CI->db->insert($table, [
            'brand_id'        => (int) $brand_id,
            'conversation_id' => $conv_id,
            'call_id'         => $call_id,
            'wa_user_id'      => $from,
            'direction'       => isset($call['direction']) && $call['direction'] === 'BUSINESS_INITIATED'
                ? 'BUSINESS_INITIATED' : 'USER_INITIATED',
            'state'           => 'ringing',
            'started_at'      => se_wa_call_time($call, 'timestamp'),
            'date_created'    => date('Y-m-d H:i:s'),
        ]);

        if (function_exists('se_push_notify_call')) {
            se_push_notify_call((int) $brand_id, $conv_id, $assigned);
        }

        return true;
    }

    /* terminate. A terminate with no preceding connect is possible — Meta can
     * drop the first webhook — so this upserts rather than requiring a row. */
    $status   = isset($call['status']) ? mb_substr((string) $call['status'], 0, 16) : '';
    $duration = isset($call['duration']) ? (int) $call['duration'] : 0;

    // "answered" is not a field Meta sends. It is derivable and it is the one
    // thing anyone actually wants to know: a COMPLETED call with a duration
    // was picked up, anything else was not.
    $answered = ($status === 'COMPLETED' && $duration > 0);

    $row = [
        'state'    => 'ended',
        'status'   => $status !== '' ? $status : null,
        'duration' => $duration,
        'ended_at' => se_wa_call_time($call, 'end_time') ?: date('Y-m-d H:i:s'),
    ];

    if ($existing) {
        $CI->db->where('id', (int) $existing->id)->update($table, $row);
        $conv_id  = (int) $existing->conversation_id;
        $wa_user  = (string) $existing->wa_user_id;
    } else {
        $wa_user = isset($call['from']) ? mb_substr((string) $call['from'], 0, 32) : '';
        $conv_id = 0;
        $CI->db->where('brand_id', (int) $brand_id)->where('wa_user_id', $wa_user);
        $conv = $CI->db->get(db_prefix() . 'se_wa_conversations')->row();
        if ($conv) { $conv_id = (int) $conv->id; }

        $CI->db->insert($table, array_merge($row, [
            'brand_id'        => (int) $brand_id,
            'conversation_id' => $conv_id,
            'call_id'         => $call_id,
            'wa_user_id'      => $wa_user,
            'direction'       => 'USER_INITIATED',
            'started_at'      => se_wa_call_time($call, 'start_time'),
            'date_created'    => date('Y-m-d H:i:s'),
        ]));
    }

    /* A MISSED call is the whole point of this feature. A patient who rings
     * and gets nobody has told you they are ready to talk, and today that fact
     * exists only inside someone's phone — it reaches no screen and no report.
     * The row above is the durable record; this is the nudge. */
    if (!$answered && function_exists('se_push_notify_missed_call')) {
        $assigned = 0;
        if ($conv_id > 0) {
            $CI->db->where('id', $conv_id);
            $c = $CI->db->get(db_prefix() . 'se_wa_conversations')->row();
            if ($c) { $assigned = (int) $c->assigned_staff; }
        }
        se_push_notify_missed_call((int) $brand_id, $conv_id, $assigned);
    }

    return true;
}

/** Meta sends UNIX timestamps as strings. Returns a DB datetime, or null. */
function se_wa_call_time(array $call, $key)
{
    if (empty($call[$key])) {
        return null;
    }

    $raw = $call[$key];
    // Numeric = UNIX seconds. Anything else is already a formatted time and
    // is passed to strtotime rather than guessed at.
    $ts = is_numeric($raw) ? (int) $raw : strtotime((string) $raw);

    return $ts > 0 ? date('Y-m-d H:i:s', $ts) : null;
}
