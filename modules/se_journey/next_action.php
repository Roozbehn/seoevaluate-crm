<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Next-action engine (CRM-M017 / UX-F03 / UX-COPY §4).
 *
 * One function answers "what should happen next for this patient?" for every
 * screen: Bugün, Hastalar, the patient page, the WhatsApp context column and
 * strip, and the staff timers. It is table-driven and pure: it reads the
 * journey row plus a small context array and returns text + one action.
 *
 *   se_journey_next_action($journey, $ctx = [], $now = null)
 *   → ['key','owner','sentence','reason','age','priority','tone','action_label','url','ghost']
 *
 * owner   : 'staff' (a person must act) | 'patient' (waiting on the patient;
 *           reminders are automatic) | 'none' (terminal / nothing to do)
 * priority: 1 danger (urgent, failed) · 2 action (staff now) · 3 info (soon /
 *           informational). Bugün sorts by priority then age.
 *
 * Thresholds (seconds) are the documented ones; they are constants so the
 * timers (CRM-M045) and this file never disagree.
 */

if (!defined('SE_NA_REVIEW_ESCALATE')) {
    define('SE_NA_REVIEW_ESCALATE',   4 * 86400);   // ready_for_review older than this → escalate to owner
    define('SE_NA_QUOTE_FOLLOWUP',    3 * 86400);   // quote_sent unanswered → follow up
    define('SE_NA_QUOTE_EXPIRY_WARN', 3 * 86400);   // valid_until within this → warn
    define('SE_NA_PAUSED_STALE',      1 * 86400);   // paused_staff longer than this → nudge
    define('SE_NA_HELD_UNRECORDED',   2 * 3600);    // consultation end passed, no outcome
    define('SE_NA_NO_AFTERCARE',      1 * 86400);   // procedure_completed without a plan
    define('SE_NA_WELCOME_STALE',     1 * 86400);   // welcome sent, no reply
    define('SE_NA_UNANSWERED_THREAD', 30 * 60);     // inbound not answered
}

/**
 * @param object $j    se_journeys row (object)
 * @param array  $ctx  optional: 'quote' (row|null), 'appointment' (row|null: the
 *                     relevant consultation/procedure appointment), 'wa_failed'
 *                     (bool), 'unread_since' (ts|null), 'aftercare_plan' (bool),
 *                     'last_staff_reply_at', 'urgent' (bool)
 * @param int    $now  injectable clock
 */
function se_journey_next_action($j, array $ctx = [], $now = null)
{
    $now   = $now ?? time();
    $state = (string) ($j->state ?? '');
    $since = strtotime((string) (($j->state_changed_at ?? '') ?: ($j->last_updated ?? '') ?: ($j->date_created ?? ''))) ?: $now;
    $age   = max(0, $now - $since);
    $base  = 'se_journey/se_journey/view/' . (int) ($j->id ?? 0);
    $url   = function ($tab = '') use ($base) { return admin_url($base . ($tab !== '' ? '?tab=' . $tab : '')); };
    $L     = function ($k, $a = null, $b = null) { $t = _l($k); return $a === null ? $t : sprintf($t, $a, $b); };
    $ageT  = function_exists('se_ui_age') ? se_ui_age($since, $now) : (string) $age;

    $out = function ($key, $owner, $priority, $tone, $sentence, $reason, $label, $u, $ghost = false) use ($age) {
        return ['key' => $key, 'owner' => $owner, 'priority' => $priority, 'tone' => $tone, 'sentence' => $sentence,
                'reason' => $reason, 'age' => $age, 'action_label' => $label, 'url' => $u, 'ghost' => $ghost];
    };

    /* ---- overrides that beat the state ---- */
    if (!empty($ctx['urgent']) || (int) ($j->urgent ?? 0) === 1) {
        return $out('urgent', 'staff', 1, 'danger', $L('se_na_urgent'), $L('se_na_urgent_reason', $ageT), $L('se_na_btn_reply'), $url('chat'));
    }
    if (!empty($ctx['wa_failed'])) {
        return $out('wa_failed', 'staff', 1, 'danger', $L('se_na_wa_failed'), $L('se_na_wa_failed_reason'), $L('se_na_btn_call'), $url('chat'));
    }
    if ((string) ($j->automation_state ?? '') === 'paused_staff' && !in_array($state, ['completed', 'closed_lost', 'opted_out', 'not_suitable'], true)) {
        $pausedAt = strtotime((string) ($j->automation_changed_at ?? '')) ?: $since;
        if ($now - $pausedAt >= SE_NA_PAUSED_STALE) {
            return $out('paused', 'staff', 3, 'inactive', $L('se_na_paused'), $L('se_na_paused_reason', se_ui_age($pausedAt, $now)), $L('se_na_btn_resume'), $url('timeline'));
        }
    }

    $quote = $ctx['quote'] ?? null;
    $appt  = $ctx['appointment'] ?? null;

    switch ($state) {
        case 'new_whatsapp_enquiry':
            return $out('new', 'staff', 2, 'action', $L('se_na_new'), $L('se_na_new_reason', $ageT), $L('se_na_btn_start'), $url('timeline'));
        case 'welcome_sent':
            if ($age >= SE_NA_WELCOME_STALE) {
                return $out('welcome_stale', 'staff', 3, 'warning', $L('se_na_welcome_stale'), $L('se_na_welcome_stale_reason', $ageT), $L('se_na_btn_write'), $url('chat'));
            }
            return $out('welcome', 'patient', 3, 'info', $L('se_na_wait_patient'), $L('se_na_wait_welcome_reason'), '', '', true);
        case 'privacy_notice_sent': case 'consent_pending': case 'intake_link_sent': case 'intake_started': case 'intake_incomplete':
            return $out('intake_wait', 'patient', 3, 'info', $L('se_na_wait_patient'), $L('se_na_wait_intake_reason', (int) ($j->reminder_count ?? 0)), $L('se_na_btn_remind_now'), $url('intake'), true);
        case 'consent_declined':
            return $out('consent_declined', 'staff', 3, 'inactive', $L('se_na_consent_declined'), $L('se_na_consent_declined_reason'), $L('se_na_btn_write'), $url('chat'));
        case 'intake_submitted': case 'photos_requested': case 'photos_incomplete': case 'photo_retake_requested':
            return $out('photos_wait', 'patient', 3, 'info', $L('se_na_wait_patient'), $L('se_na_wait_photos_reason'), $L('se_na_btn_remind_now'), $url('photos'), true);
        case 'ready_for_review':
            $p = $age >= SE_NA_REVIEW_ESCALATE ? 1 : 2;
            return $out('review', 'staff', $p, $p === 1 ? 'danger' : 'action', $L('se_na_review'), $L('se_na_review_reason', $ageT), $L('se_na_btn_review_photos'), $url('photos'));
        case 'under_review':
            return $out('decision', 'staff', 2, 'action', $L('se_na_decision'), $L('se_na_decision_reason', $ageT), $L('se_na_btn_record_decision'), $url('review'));
        case 'more_information_required':
            return $out('more_info', 'patient', 3, 'warning', $L('se_na_more_info'), $L('se_na_more_info_reason', $ageT), $L('se_na_btn_write'), $url('chat'), true);
        case 'consultation_recommended':
            return $out('book_consult', 'staff', 2, 'action', $L('se_na_book_consult'), $L('se_na_book_consult_reason', $ageT), $L('se_na_btn_book'), $url('care'));
        case 'quote_pending_staff_approval':
            $v = $quote ? (int) $quote->version : 1;
            return $out('quote_approve', 'staff', 2, 'action', $L('se_na_quote_approve', $v), $L('se_na_quote_approve_reason', $ageT), $L('se_na_btn_approve_quote'), $url('review'));
        case 'quote_sent':
            $sentAt = $quote && !empty($quote->sent_at) ? strtotime($quote->sent_at) : $since;
            $validUntil = $quote && !empty($quote->valid_until) ? strtotime($quote->valid_until . ' 23:59:59') : 0;
            if ($validUntil && $validUntil < $now) {
                return $out('quote_expired', 'staff', 2, 'warning', $L('se_na_quote_expired'), $L('se_na_quote_expired_reason'), $L('se_na_btn_new_version'), $url('review'));
            }
            if ($now - $sentAt >= SE_NA_QUOTE_FOLLOWUP) {
                $left = $validUntil ? (int) floor(($validUntil - $now) / 86400) : null;
                return $out('quote_followup', 'staff', 3, 'warning', $L('se_na_quote_followup'), $L('se_na_quote_followup_reason', se_ui_age($sentAt, $now), $left === null ? '' : sprintf(_l('se_na_days_valid'), $left)), $L('se_na_btn_remind'), $url('chat'));
            }
            return $out('quote_wait', 'patient', 3, 'info', $L('se_na_wait_patient'), $L('se_na_wait_quote_reason', se_ui_age($sentAt, $now)), '', '', true);
        case 'quote_expired':
            return $out('quote_expired', 'staff', 2, 'warning', $L('se_na_quote_expired'), $L('se_na_quote_expired_reason'), $L('se_na_btn_new_version'), $url('review'));
        case 'quote_revision_requested':
            return $out('quote_revise', 'staff', 2, 'action', $L('se_na_quote_revise'), $L('se_na_quote_revise_reason', $ageT), $L('se_na_btn_new_version'), $url('review'));
        case 'quote_accepted':
            return $out('accepted_book', 'staff', 3, 'info', $L('se_na_accepted'), $L('se_na_accepted_reason', $ageT), $L('se_na_btn_book'), $url('care'));
        case 'consultation_booked':
            $start = $appt && !empty($appt->start_at) ? strtotime($appt->start_at) : 0;
            $end   = $appt && !empty($appt->end_at) ? strtotime($appt->end_at) : $start;
            if ($start && $end && $end + SE_NA_HELD_UNRECORDED <= $now) {
                return $out('held_unrecorded', 'staff', 2, 'action', $L('se_na_record_outcome'), $L('se_na_record_outcome_reason', se_ui_when($start)), $L('se_na_btn_record_outcome'), $url('care'));
            }
            return $out('consult_wait', 'none', 3, 'info', $L('se_na_consult_booked'), $start ? $L('se_na_consult_booked_reason', se_ui_when($start)) : '', '', '', true);
        case 'consultation_completed':
            return $out('after_consult', 'staff', 2, 'action', $L('se_na_after_consult'), $L('se_na_after_consult_reason', $ageT), $L('se_na_btn_plan_today'), $url('care'));
        case 'procedure_booked': case 'preop_pending':
            $start = $appt && !empty($appt->start_at) ? strtotime($appt->start_at) : 0;
            return $out('procedure_wait', 'none', 3, 'info', $L('se_na_procedure_booked'), $start ? $L('se_na_procedure_booked_reason', se_ui_when($start)) : '', $L('se_na_btn_preop'), $url('care'), true);
        case 'procedure_completed':
            $p = $age >= SE_NA_NO_AFTERCARE ? 2 : 3;
            return $out('start_aftercare', 'staff', $p, 'action', $L('se_na_start_aftercare'), $L('se_na_start_aftercare_reason', $ageT), $L('se_na_btn_start_plan'), $url('care'));
        case 'aftercare_active':
            return $out('aftercare', 'none', 3, 'positive', $L('se_na_aftercare'), '', '', '', true);
        case 'followup_due':
            return $out('followup', 'staff', 2, 'warning', $L('se_na_followup'), $L('se_na_followup_reason', $ageT), $L('se_na_btn_write'), $url('chat'));
        case 'completed': case 'not_suitable': case 'closed_lost': case 'opted_out':
            return $out('terminal', 'none', 3, 'inactive', '', '', '', '');
    }

    return $out('unknown', 'none', 3, 'info', '', '', '', '');
}

/**
 * Context for a journey, loaded once (quote, appointment, failed send), so the
 * engine stays pure. Used by the patient page and the WhatsApp context column;
 * Bugün/Hastalar batch-load the same pieces for many journeys.
 */
function se_journey_next_action_context($j)
{
    $ctx = [];
    if (function_exists('se_journey_quote_latest')) {
        $ctx['quote'] = se_journey_quote_latest($j);
    }
    $CI = &get_instance();
    $apptId = (int) ($j->procedure_appointment_id ?? 0) ?: (int) ($j->consultation_appointment_id ?? 0);
    if (in_array((string) $j->state, ['consultation_booked', 'consultation_completed'], true)) {
        $apptId = (int) ($j->consultation_appointment_id ?? 0);
    }
    if ($apptId > 0 && $CI->db->table_exists(db_prefix() . 'se_appointments')) {
        $CI->db->where('id', $apptId);
        $ctx['appointment'] = $CI->db->get(db_prefix() . 'se_appointments')->row();
    }
    if ((int) ($j->wa_conversation_id ?? 0) > 0 && $CI->db->table_exists(db_prefix() . 'se_wa_outbound')) {
        $CI->db->where('conversation_id', (int) $j->wa_conversation_id)->where('status', 'failed')->limit(1);
        $ctx['wa_failed'] = $CI->db->count_all_results(db_prefix() . 'se_wa_outbound') > 0;
    }

    return $ctx;
}

/** Convenience: next action with its context loaded. */
function se_journey_next_action_for($j, $now = null)
{
    return se_journey_next_action($j, se_journey_next_action_context($j), $now);
}

/**
 * Human label for a timeline event (CRM-M026 / UX-COPY §6). Returns '' when
 * the event should stay hidden by default (lead sync noise).
 *
 * @param string $kind     event kind / transition target
 * @param array  $ev       ['detail'=>..,'actor'=>..,'meta'=>[..],'from'=>..,'to'=>..]
 */
function se_journey_event_label($kind, array $ev = [])
{
    $kind   = (string) $kind;
    $detail = (string) ($ev['detail'] ?? '');
    $to     = (string) ($ev['to'] ?? '');
    $L = function ($k, $a = null) { $t = _l($k); return $a === null ? $t : sprintf($t, $a); };

    // Transitions read as the resulting state, never "a → b".
    if ($kind === 'transition' && $to !== '') {
        $map = [
            'ready_for_review' => 'se_ev_photos_ready', 'under_review' => 'se_ev_review_started', 'quote_sent' => 'se_ev_quote_sent',
            'quote_accepted' => 'se_ev_quote_accepted', 'quote_revision_requested' => 'se_ev_quote_revision', 'consultation_booked' => 'se_ev_consult_booked',
            'consultation_completed' => 'se_ev_consult_held', 'procedure_booked' => 'se_ev_procedure_booked', 'procedure_completed' => 'se_ev_procedure_done',
            'aftercare_active' => 'se_ev_aftercare_started', 'followup_due' => 'se_ev_followup_due', 'opted_out' => 'se_ev_optout', 'closed_lost' => 'se_ev_closed',
            'not_suitable' => 'se_ev_not_suitable', 'intake_submitted' => 'se_ev_intake_submitted', 'photos_requested' => 'se_ev_photos_requested',
            'intake_started' => 'se_ev_consent_granted', 'consent_declined' => 'se_ev_consent_declined', 'welcome_sent' => 'se_ev_welcome',
            'privacy_notice_sent' => 'se_ev_privacy', 'consent_pending' => 'se_ev_privacy', 'new_whatsapp_enquiry' => 'se_ev_new_enquiry',
            'more_information_required' => 'se_ev_more_info', 'consultation_recommended' => 'se_ev_consult_recommended',
            'quote_pending_staff_approval' => 'se_ev_quote_prepared', 'quote_expired' => 'se_ev_quote_expired', 'completed' => 'se_ev_completed',
            'photos_incomplete' => 'se_ev_photos_incomplete', 'intake_incomplete' => 'se_ev_intake_incomplete', 'photo_retake_requested' => 'se_ev_retake',
            'preop_pending' => 'se_ev_preop',
        ];
        return isset($map[$to]) ? $L($map[$to]) : se_ui_state_label($to);
    }

    $map = [
        'wa_inbound'         => ['text' => 'se_ev_in_text', 'image' => 'se_ev_in_image', 'interactive' => 'se_ev_in_button', 'audio' => 'se_ev_in_audio', 'document' => 'se_ev_in_document', 'unsupported' => 'se_ev_in_unsupported', '*' => 'se_ev_in_text'],
        'wa_outbound'        => ['welcome' => 'se_ev_welcome', 'privacy_and_flow' => 'se_ev_privacy', 'photos_request' => 'se_ev_photos_requested', 'photos_partial_ack' => 'se_ev_photos_partial',
                                 'photos_received_ack' => 'se_ev_photos_ack', 'evaluation_ready' => 'se_ev_quote_sent', 'quote_options' => 'se_ev_quote_options', 'booking_flow' => 'se_ev_booking_sent',
                                 'quote_accepted_ack' => 'se_ev_booking_sent', 'consultation_confirmation' => 'se_ev_consult_confirmation', 'consultation_reminder' => 'se_ev_consult_reminder',
                                 'consultation_information' => 'se_ev_consult_information', 'preop_information' => 'se_ev_preop_information', 'procedure_confirmation' => 'se_ev_procedure_confirmation',
                                 'handoff_ack' => 'se_ev_handoff', 'urgent_ack' => 'se_ev_urgent', 'optout_confirm' => 'se_ev_optout', 'options_repeat' => 'se_ev_options_repeat',
                                 'aftercare_checkin' => 'se_ev_aftercare_msg', 'followup_photo_request' => 'se_ev_followup_photo', 'aftercare_thanks' => 'se_ev_aftercare_thanks', '*' => 'se_ev_out_generic'],
        'consent_recorded'   => ['*' => 'se_ev_consent_granted'],
        'intake_saved'       => ['*' => 'se_ev_intake_saved'],
        'intake_submitted'   => ['*' => 'se_ev_intake_submitted'],
        'flow_step'          => ['*' => ''],           // noise: hidden
        'flow_completed'     => ['*' => 'se_ev_intake_submitted'],
        'media_received'     => ['*' => 'se_ev_media_received'],
        'photos_received'    => ['*' => 'se_ev_photos_ready'],
        'token_issued'       => ['*' => ''],
        'quote_prepared'     => ['*' => 'se_ev_quote_prepared'],
        'quote_approved'     => ['*' => 'se_ev_quote_approved'],
        'quote_sent'         => ['*' => 'se_ev_quote_sent'],
        'quote_viewed'       => ['*' => 'se_ev_quote_viewed'],
        'jr_quote_accept'    => ['*' => 'se_ev_quote_accepted'],
        'jr_quote_revise'    => ['*' => 'se_ev_quote_revision'],
        'review_decision'    => ['*' => 'se_ev_review_decision'],
        'staff_reply'        => ['*' => 'se_ev_staff_reply'],
        'automation_pause'   => ['*' => 'se_ev_paused'],
        'automation_resume'  => ['*' => 'se_ev_resumed'],
        'lead_sync'          => ['*' => ''],
        'note'               => ['*' => 'se_ev_note'],
        'urgent'             => ['*' => 'se_ev_urgent'],
        'handoff'            => ['*' => 'se_ev_handoff'],
        'wa_delivery_failed' => ['*' => 'se_ev_wa_failed'],
        'appointment'        => ['*' => 'se_ev_consult_booked'],
        'aftercare_step'     => ['*' => 'se_ev_aftercare_msg'],
        'auto_started'       => ['*' => 'se_ev_auto_started'],
        'staff_started'      => ['*' => 'se_ev_staff_started'],
    ];
    if (!isset($map[$kind])) {
        $t = _l('se_ev_' . $kind);
        return $t === 'se_ev_' . $kind ? '' : $t;
    }
    $first = trim((string) strtok($detail, ' '));
    $key = $map[$kind][$first] ?? ($map[$kind][$detail] ?? $map[$kind]['*']);
    if ($key === '') {
        return '';
    }
    $txt = $L($key);
    if ($kind === 'review_decision' && $detail !== '') {
        $dk = 'se_journey_decision_' . $detail;
        $dt = _l($dk);
        $txt .= ': ' . ($dt === $dk ? $detail : $dt);
    }
    if ($kind === 'wa_delivery_failed' && $detail !== '') {
        $txt .= ' (' . $detail . ')';
    }

    return $txt;
}
