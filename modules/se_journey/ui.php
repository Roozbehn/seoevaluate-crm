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

/**
 * Sidebar panel on the WhatsApp conversation page: the journey behind this
 * thread (state, automation, link) or a Start button when there is none.
 * Rendered only for staff who may view journeys; Start needs edit_review.
 */
function se_journey_conversation_panel($conv)
{
    if (!function_exists('se_journey_can') || !se_journey_can('view')) {
        return;
    }
    $brand = (int) ($conv->brand_id ?? 0);
    $j = $brand > 0 ? se_journey_find_by_wa($brand, (string) ($conv->wa_user_id ?? '')) : null;
    $enabled = $brand > 0 && se_journey_enabled($brand);
    $canStart = se_journey_can('edit_review');

    echo '<div class="panel_s"><div class="panel-body"><h5>' . html_escape(_l('se_journey_panel_title')) . '</h5>';
    if ($j) {
        echo '<p>' . se_ui_badge(se_journey_ui_state_tone($j->state), _l('se_journey_state_' . $j->state)) . ' '
           . se_ui_badge(se_journey_ui_automation_tone($j->automation_state), _l('se_journey_auto_' . $j->automation_state)) . '</p>';
        echo '<p><a class="btn btn-default btn-sm" href="' . admin_url('se_journey/se_journey/view/' . (int) $j->id) . '"><i class="fa fa-route"></i> '
           . html_escape(_l('se_journey_open')) . ' #' . (int) $j->id . '</a></p>';
        if ((string) $j->state === 'new_whatsapp_enquiry' && $canStart && $enabled) {
            echo form_open(admin_url('se_journey/se_journey/action/' . (int) $j->id . '/start'));
            echo '<button type="submit" class="btn btn-success btn-sm"><i class="fa fa-play"></i> ' . html_escape(_l('se_journey_start_evaluation')) . '</button>';
            echo form_close();
        }
    } elseif (!$enabled) {
        echo '<p class="text-muted"><small>' . html_escape(_l('se_journey_panel_disabled')) . '</small></p>';
    } elseif ($canStart) {
        echo '<p class="text-muted"><small>' . html_escape(_l('se_journey_panel_none')) . '</small></p>';
        echo form_open(admin_url('se_journey/se_journey/start_conversation/' . (int) ($conv->id ?? 0)));
        echo '<button type="submit" class="btn btn-success btn-sm"><i class="fa fa-play"></i> ' . html_escape(_l('se_journey_start_evaluation')) . '</button>';
        echo form_close();
    } else {
        echo '<p class="text-muted"><small>' . html_escape(_l('se_journey_panel_none')) . '</small></p>';
    }
    echo '</div></div>';
}

