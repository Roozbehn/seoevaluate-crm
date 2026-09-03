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
        'quote_accepted' => 'ok', 'quote_revision_requested' => 'warning', 'quote_expired' => 'warning',
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
        'review'       => ['ready_for_review', 'under_review', 'more_information_required', 'quote_pending_staff_approval', 'quote_sent', 'quote_expired', 'consultation_recommended'],
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

/* ===========================================================================
 * Contextual actions by state (CRM-M036 / UX-W06 / AZCRM-WA-004)
 * ======================================================================== */

/**
 * The 2–4 buttons a staff member needs next to a thread, derived from the
 * state map + next action, filtered by capability. Each: ['label', 'kind'
 * ('link'|'post'), 'url', 'variant', 'fields' => [...]]. The first is the
 * next action itself (primary) when it is staff-owned.
 */
function se_journey_contextual_actions($j, array $can, array $na = [], $tab = 'chat')
{
    $jid  = (int) $j->id;
    $act  = function ($what) use ($jid) { return admin_url('se_journey/se_journey/action/' . $jid . '/' . $what); };
    $view = function ($t) use ($jid) { return admin_url('se_journey/se_journey/view/' . $jid . '?tab=' . $t); };
    $out  = [];
    $seen = [];
    $add  = function ($label, $kind, $url, $variant = 'secondary', array $fields = []) use (&$out, &$seen, $tab) {
        $key = $kind . '|' . $url;
        if (isset($seen[$key]) || count($out) >= 4) { return; }
        $seen[$key] = true;
        if ($kind === 'post') { $fields['tab'] = $tab; }
        $out[] = ['label' => $label, 'kind' => $kind, 'url' => $url, 'variant' => $variant, 'fields' => $fields];
    };
    $state = (string) $j->state;
    $edit  = !empty($can['edit_review']);
    $cons  = !empty($can['manage_consultation']);

    if (!empty($na['action_label']) && !empty($na['url']) && ($na['owner'] ?? '') === 'staff' && $na['key'] !== 'unread') {
        $add($na['action_label'], 'link', $na['url'], 'primary');
    }
    switch ($state) {
        case 'new_whatsapp_enquiry':
            if ($edit) { $add(_l('se_journey_start_welcome'), 'post', $act('start'), 'primary'); }
            break;
        case 'welcome_sent': case 'privacy_notice_sent': case 'consent_pending': case 'intake_link_sent': case 'intake_started': case 'intake_incomplete': case 'consent_declined':
            if ($edit) { $add(_l('se_journey_resend_link'), 'post', $act('resend_link')); }
            break;
        case 'photos_requested': case 'photos_incomplete': case 'photo_retake_requested':
            if (!empty($can['view_photos'])) { $add(_l('se_journey_tab_photos'), 'link', $view('photos')); }
            if ($edit) { $add(_l('se_journey_resend_link'), 'post', $act('resend_link')); }
            break;
        case 'ready_for_review': case 'under_review': case 'more_information_required':
            if (!empty($can['view_photos'])) { $add(_l('se_na_btn_review_photos'), 'link', $view('photos'), 'primary'); }
            if ($edit) { $add(_l('se_journey_request_retake'), 'link', $view('photos') . '#se-retake-kind'); $add(_l('se_journey_decision'), 'link', $view('review')); }
            break;
        case 'quote_pending_staff_approval':
            if (!empty($can['approve_quote'])) { $add(_l('se_journey_approve_quote'), 'link', $view('review'), 'primary'); }
            elseif ($edit) { $add(_l('se_journey_quote'), 'link', $view('review')); }
            break;
        case 'quote_sent': case 'quote_accepted': case 'quote_revision_requested': case 'quote_expired': case 'consultation_recommended':
            if ($cons) { $add(_l('se_journey_send_book_link'), 'post', $act('book_link'), $state === 'quote_accepted' ? 'primary' : 'secondary'); }
            if ($cons && (int) $j->lead_id > 0 && function_exists('staff_can') && staff_can('create', 'se_appointments')) { $add(_l('se_na_btn_book'), 'link', admin_url('se_appointments/create?lead=' . (int) $j->lead_id . '&journey=' . $jid)); }
            if ($edit) { $add(_l('se_journey_send_consultation_info'), 'post', $act('consultation_info_send'), 'ghost'); }
            if ($edit && $state !== 'consultation_recommended') { $add(_l('se_journey_new_version'), 'link', $view('review'), 'ghost'); }
            break;
        case 'consultation_booked': case 'consultation_completed':
            if ($cons) { $add(_l('se_na_btn_record_outcome'), 'link', $view('care'), $state === 'consultation_completed' ? 'secondary' : 'primary'); }
            if ($edit) { $add(_l('se_journey_send_consultation_info'), 'post', $act('consultation_info_send'), 'ghost'); }
            break;
        case 'procedure_booked': case 'preop_pending':
            if ($cons) { $add(_l('se_journey_start_preop'), 'link', $view('care')); $add(_l('se_journey_procedure_complete'), 'link', $view('care')); }
            break;
        case 'procedure_completed': case 'aftercare_active': case 'followup_due':
            if (!empty($can['manage_aftercare'])) { $add(_l('se_journey_aftercare'), 'link', $view('care')); }
            break;
        case 'closed_lost': case 'not_suitable':
            if ($edit) { $add(_l('se_pw_reopen'), 'link', $view('timeline')); }
            break;
    }
    if ($edit && (string) $j->automation_state !== 'active' && $state !== 'opted_out' && !in_array($state, ['closed_lost', 'not_suitable', 'completed'], true)) {
        $add(_l('se_journey_resume'), 'post', $act('resume'), 'secondary', ['reason' => 'staff_resume']);
    }

    return $out;
}

/** Render a contextual action list (DS buttons; POST ones are CSRF forms). */
function se_journey_render_actions(array $actions, $block = true)
{
    $h = '';
    foreach ($actions as $a) {
        $cls = $block ? 'se-btn-block' : '';
        if ($a['kind'] === 'post') {
            $h .= se_ui_post_btn($a['label'], $a['url'], $a['variant'], $a['fields'], ['class' => $cls]);
        } else {
            $h .= se_ui_btn($a['label'], $a['url'], $a['variant'], ['class' => $cls]);
        }
    }

    return $h;
}

/**
 * The third column of Mesajlar / the phone sheet (CRM-M033 / UX-W02 / DS §2.18):
 * who, where in the process, what next, 2–4 actions, evaluation checklist,
 * facts, assignment. Returns HTML (inner content of .se-ctx).
 */
function se_journey_conversation_context($conv, array $opts = [])
{
    if (!function_exists('se_journey_can') || !se_journey_can('view')) {
        return '';
    }
    $brand = (int) ($conv->brand_id ?? 0);
    $j = $brand > 0 ? se_journey_find_by_wa($brand, (string) ($conv->wa_user_id ?? '')) : null;
    $can = [];
    foreach (array_keys(se_journey_capabilities()) as $cap) { $can[$cap] = se_journey_can($cap); }
    $h = '';
    if (!$j) {
        $enabled = $brand > 0 && se_journey_enabled($brand);
        $h .= '<div><h3>' . html_escape(_l('se_na_new_thread')) . '</h3><p class="se-help">' . html_escape($enabled ? _l('se_journey_panel_none') : _l('se_journey_panel_disabled')) . '</p></div>';
        if ($enabled && $can['edit_review']) {
            $h .= '<div class="se-ctx-actions">' . se_ui_post_btn(_l('se_journey_start_evaluation'), admin_url('se_journey/se_journey/start_conversation/' . (int) ($conv->id ?? 0)), 'primary', [], ['class' => 'se-btn-block']) . '</div>';
        }
        return $h;
    }
    $batch = se_journey_batch_context([(array) $j], null, ['next_appointment' => true]);
    $it = $batch['items'][(int) $j->id] ?? null;
    $na = $it ? $it['na'] : se_journey_next_action_for($j);
    $lead = $it ? $it['lead'] : null;
    $name = $it ? $it['name'] : se_ui_short_name((string) $j->display_name);
    $stage = se_ui_stage_of($j->state);
    $stages = se_ui_stages_list();
    $idx = array_search($stage, $stages, true);
    $srcKey = 'se_journey_source_' . (string) $j->source; $src = _l($srcKey); if ($src === $srcKey) { $src = (string) $j->source; }

    $h .= '<div><h3>' . html_escape($name) . '</h3><div class="se-help">' . html_escape(trim($src . ' · ' . _l('se_ctx_started', [se_ui_age($j->date_created)]), ' ·')) . '</div></div>';
    $h .= '<div>' . se_ui_state_badge($j->state) . ($idx !== false ? ' <span class="se-help" style="margin-inline-start:6px">' . html_escape(_l('se_ctx_step', [$idx + 1, count($stages), se_ui_stage_label($stage)])) . '</span>' : '')
        . ((int) $j->urgent === 1 ? ' ' . se_ui_ds_badge('danger', _l('se_journey_urgent'), true) : '') . '</div>';
    if (!empty($na['sentence'])) {
        $h .= '<div class="se-next" style="padding:12px"><div><div class="k">' . html_escape(_l('se_ui_next_action')) . '</div><div class="v" style="font-size:15px">' . html_escape($na['sentence']) . '</div>'
            . (!empty($na['reason']) ? '<div class="m">' . html_escape($na['reason']) . '</div>' : '') . '</div></div>';
    }
    $actions = se_journey_contextual_actions($j, $can, $na, 'chat');
    $h .= '<div class="se-ctx-actions">' . se_journey_render_actions($actions) . se_ui_btn(_l('se_ctx_all_actions'), admin_url('se_journey/se_journey/view/' . (int) $j->id), 'ghost', ['class' => 'se-btn-block']) . '</div>';

    // evaluation checklist
    $consent = se_journey_consent_state($j);
    $intake  = se_journey_intake_get($j);
    $check   = se_journey_media_checklist($j);
    $req     = se_journey_required_photo_kinds($j);
    $have    = 0; foreach ($req as $k) { if (!empty($check[$k])) { $have++; } }
    $li = function ($ok, $text) { return '<li><span class="' . ($ok ? 'ok' : 'todo') . '">' . ($ok ? '✓' : '○') . '</span> ' . html_escape($text) . '</li>'; };
    $h .= '<div><h3 style="margin-bottom:8px">' . html_escape(_l('se_journey_tab_intake')) . '</h3><ul class="se-checks">'
        . $li($consent['health_data'], _l('se_pw_consent_health') . ($consent['marketing'] ? ' · ' . _l('se_pw_consent_marketing') : ''))
        . $li($intake && $intake->status === 'submitted', _l('se_pw_check_form') . ($intake ? ' ' . $intake->questionnaire_version : ''))
        . $li($req && $have >= count($req), _l('se_pw_check_photos') . ' ' . $have . '/' . count($req))
        . $li(in_array($stage, ['quote', 'consultation', 'procedure', 'aftercare'], true) || (string) $j->state === 'consultation_recommended', _l('se_pw_check_review'))
        . $li(!empty($it['ctx']['quote']) && in_array($it['ctx']['quote']->status, ['sent', 'approved'], true), _l('se_pw_check_quote'))
        . $li(in_array($stage, ['procedure', 'aftercare'], true) || (string) $j->state === 'consultation_completed', _l('se_ui_stage_consultation'))
        . '</ul></div>';
    // facts
    $phone = se_ui_phone($lead && trim((string) $lead['phonenumber']) !== '' ? $lead['phonenumber'] : (string) $j->wa_user_id, !$can['view_health'], false);
    $h .= '<div><h3 style="margin-bottom:8px">' . html_escape(_l('se_ctx_facts')) . '</h3><dl class="kv">'
        . '<dt>' . html_escape(_l('se_wa_contact')) . '</dt><dd><bdi dir="ltr">' . html_escape($phone) . '</bdi></dd>'
        . '<dt>' . html_escape(_l('se_journey_automation')) . '</dt><dd>' . se_ui_automation_badge($j->automation_state) . '</dd>'
        . '<dt>' . html_escape(_l('se_pw_appointment')) . '</dt><dd>' . ($it && $it['next_appointment'] ? html_escape(se_ui_when($it['next_appointment']['start_at'])) : '—') . '</dd>'
        . '<dt>' . html_escape(_l('se_journey_assignee')) . '</dt><dd>' . html_escape((int) $j->assigned_staff > 0 && function_exists('get_staff_full_name') ? se_ui_short_name((string) get_staff_full_name((int) $j->assigned_staff)) : _l('se_journey_unassigned')) . '</dd>'
        . '</dl></div>';

    return $h;
}
