<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * se_journey — small view helpers (badge tones, labels). Presentation only.
 */

/** Map a journey state onto the shared badge palette (se_ui_badge_class). */
function se_journey_ui_state_tone($state)
{
    $map = [
        'new_whatsapp_enquiry' => 'pending', 'welcome_sent' => 'processing', 'privacy_notice_sent' => 'processing',
        'consent_pending' => 'processing', 'consent_declined' => 'skipped', 'intake_link_sent' => 'processing',
        'intake_started' => 'processing', 'intake_incomplete' => 'skipped', 'intake_submitted' => 'submitted',
        'photos_requested' => 'processing', 'photos_incomplete' => 'skipped', 'photo_retake_requested' => 'skipped',
        'ready_for_review' => 'ok', 'under_review' => 'processing', 'more_information_required' => 'skipped',
        'consultation_recommended' => 'submitted', 'quote_pending_staff_approval' => 'warning', 'quote_sent' => 'sent',
        'consultation_booked' => 'scheduled', 'consultation_completed' => 'held', 'procedure_booked' => 'scheduled',
        'preop_pending' => 'processing', 'procedure_completed' => 'completed', 'aftercare_active' => 'active',
        'followup_due' => 'warning', 'completed' => 'completed', 'not_suitable' => 'closed', 'closed_lost' => 'closed',
        'opted_out' => 'failed',
    ];

    return $map[(string) $state] ?? 'pending';
}

function se_journey_ui_automation_tone($state)
{
    $map = ['active' => 'active', 'paused_patient' => 'skipped', 'paused_staff' => 'skipped', 'awaiting_approval' => 'warning',
            'error' => 'failed', 'stopped' => 'closed', 'blocked' => 'failed'];

    return $map[(string) $state] ?? 'pending';
}

/** Stepper: which of the seven macro phases the state belongs to. */
function se_journey_ui_phase($state)
{
    $phases = [
        'enquiry'      => ['new_whatsapp_enquiry', 'welcome_sent'],
        'consent'      => ['privacy_notice_sent', 'consent_pending', 'consent_declined', 'intake_link_sent'],
        'intake'       => ['intake_started', 'intake_incomplete', 'intake_submitted'],
        'photos'       => ['photos_requested', 'photos_incomplete', 'photo_retake_requested'],
        'review'       => ['ready_for_review', 'under_review', 'more_information_required', 'quote_pending_staff_approval', 'quote_sent', 'consultation_recommended'],
        'consultation' => ['consultation_booked', 'consultation_completed'],
        'procedure'    => ['procedure_booked', 'preop_pending', 'procedure_completed'],
        'aftercare'    => ['aftercare_active', 'followup_due', 'completed'],
    ];
    foreach ($phases as $k => $states) {
        if (in_array((string) $state, $states, true)) {
            return $k;
        }
    }

    return 'closed';
}

function se_journey_ui_phases()
{
    return ['enquiry', 'consent', 'intake', 'photos', 'review', 'consultation', 'procedure', 'aftercare'];
}
