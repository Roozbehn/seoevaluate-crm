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
        'quote_accepted' => 'ok', 'quote_revision_requested' => 'warning',
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


/* ===========================================================================
 * Human timeline (CRM-M026 / UX-P04 / UX-COPY §6)
 * ======================================================================== */

/**
 * One ordered list (newest first) of what happened to a patient, in plain
 * Turkish: messages, state changes, events, appointment status changes.
 * Raw kinds never reach the view; events the label map marks as noise
 * (token issued, flow steps, lead sync) are dropped. Non-sensitive: message
 * bodies are truncated previews, photos are "[fotoğraf]", nothing from the
 * intake answers.
 *
 * @return array<int, array{at:string, label:string, text:string, actor:string, tone:string, kind:string}>
 */
function se_journey_timeline_human($j, $limit = 150)
{
    $CI = &get_instance();
    $p  = db_prefix();
    $items = [];
    $actorOf = function ($type, $id = 0) {
        $type = (string) $type;
        if ($type === 'staff' || $type === 'agent') {
            return $id > 0 && function_exists('get_staff_full_name') ? se_ui_short_name((string) get_staff_full_name($id)) : _l('se_tl_actor_staff');
        }
        if (in_array($type, ['patient', 'customer', 'contact', 'user'], true)) { return _l('se_tl_actor_patient'); }
        return _l('se_tl_actor_auto');
    };

    if ((int) $j->wa_conversation_id > 0 && $CI->db->table_exists($p . 'se_wa_messages')) {
        $CI->db->where('conversation_id', (int) $j->wa_conversation_id)->where('brand_id', (int) $j->brand_id)->order_by('id', 'DESC')->limit(100);
        foreach ($CI->db->get($p . 'se_wa_messages')->result_array() as $m) {
            $in = ($m['direction'] ?? 'in') === 'in';
            $origin = (string) ($m['origin'] ?? '');
            $detail = $in ? (string) $m['type'] : (strpos($origin, 'journey:') === 0 ? substr($origin, 8) : (string) $m['type']);
            $label = se_journey_event_label($in ? 'wa_inbound' : 'wa_outbound', ['detail' => $detail]);
            if ($label === '') { continue; }
            $text = $m['type'] === 'image' ? '' : mb_substr(trim((string) ($m['body'] ?? '')), 0, 160);
            $failed = in_array((string) ($m['delivery_state'] ?? ''), ['failed', 'undeliverable'], true);
            $items[] = ['at' => $m['received_at'] ?: ($m['sent_at'] ?: $m['date_created']), 'label' => $label, 'text' => $text,
                        'actor' => $in ? _l('se_tl_actor_patient') : ($origin === 'staff' || $origin === '' && !empty($m['staff_id']) ? $actorOf('staff', (int) ($m['staff_id'] ?? 0)) : _l('se_tl_actor_auto')),
                        'tone' => $failed ? 'danger' : ($in ? 'info' : 'inactive'), 'kind' => $in ? 'in' : 'out'];
        }
    }
    if ($CI->db->table_exists($p . 'se_journey_transitions')) {
        $CI->db->where('journey_id', (int) $j->id)->order_by('id', 'DESC')->limit(150);
        foreach ($CI->db->get($p . 'se_journey_transitions')->result_array() as $t) {
            $label = se_journey_event_label('transition', ['to' => (string) $t['to_state'], 'from' => (string) $t['from_state']]);
            if ($label === '') { continue; }
            $items[] = ['at' => $t['created_at'], 'label' => $label, 'text' => (string) ($t['note'] ?? ''),
                        'actor' => $actorOf($t['actor_type'] ?? '', (int) ($t['actor_id'] ?? 0)), 'tone' => se_ui_state_tone($t['to_state']), 'kind' => 'state'];
        }
    }
    if ($CI->db->table_exists($p . 'se_journey_events')) {
        $CI->db->where('journey_id', (int) $j->id)->order_by('id', 'DESC')->limit(150);
        foreach ($CI->db->get($p . 'se_journey_events')->result_array() as $e) {
            $label = se_journey_event_label((string) $e['kind'], ['detail' => (string) ($e['detail'] ?? $e['summary'] ?? '')]);
            if ($label === '') { continue; }
            $items[] = ['at' => $e['created_at'], 'label' => $label, 'text' => (string) ($e['summary'] ?? ''),
                        'actor' => $actorOf($e['actor_type'] ?? '', (int) ($e['actor_id'] ?? 0)), 'tone' => in_array((string) $e['kind'], ['urgent', 'wa_delivery_failed'], true) ? 'danger' : 'info', 'kind' => (string) $e['kind']];
        }
    }
    if ($CI->db->table_exists($p . 'se_appointment_status_history')) {
        foreach ([$j->consultation_appointment_id ?? 0, $j->procedure_appointment_id ?? 0] as $aid) {
            if ((int) $aid <= 0) { continue; }
            $CI->db->where('appointment_id', (int) $aid)->where('brand_id', (int) $j->brand_id)->order_by('id', 'ASC');
            foreach ($CI->db->get($p . 'se_appointment_status_history')->result_array() as $h) {
                $known = in_array((string) $h['new_status'], ['scheduled', 'confirmed', 'held', 'completed', 'no_show', 'cancelled'], true);
                $items[] = ['at' => $h['changed_at'], 'label' => _l($known ? 'se_tl_appt_' . $h['new_status'] : 'se_tl_appt_changed'), 'text' => '',
                            'actor' => $actorOf('staff', (int) ($h['changed_by'] ?? 0)), 'tone' => 'info', 'kind' => 'appointment'];
            }
        }
    }
    usort($items, function ($a, $b) { return strcmp((string) $b['at'], (string) $a['at']); });

    return array_slice($items, 0, max(1, (int) $limit));
}
