<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Patient journey — staff screens.
 *
 * Every action is POST-only + CSRF (Perfex) + capability + brand-scoped
 * record lookup. Health answers, photos, exports and quote approval each
 * check their own capability again inside the model layer, so a route alone
 * never grants anything.
 */
class Se_journey extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        if (staff_cant('view', SE_JOURNEY_FEATURE)) {
            access_denied('se_journey');
        }
    }

    /** Dashboard counters + attention items + journey list. */
    public function index()
    {
        $data['title']    = _l('se_journeys');
        $data['counters'] = se_journey_dashboard_counters();
        $data['tasks']    = se_journey_open_tasks(30);
        $data['filter']   = ['state' => (string) $this->input->get('state'), 'urgent' => (int) $this->input->get('urgent')];
        $data['journeys'] = se_journey_list(['state' => $data['filter']['state'], 'urgent' => $data['filter']['urgent'], 'limit' => 100]);
        $data['brand']    = function_exists('se_clinic_sole_brand_id') ? (int) se_clinic_sole_brand_id() : 0;
        $data['readiness'] = $data['brand'] > 0 && se_journey_is_integration_admin() ? se_journey_readiness($data['brand']) : null;
        $this->load->view('se_journey/index', $data);
    }

    /** The journey workspace: header, timeline and role-gated tabs. */
    public function view($id)
    {
        $j = $this->journey($id);
        $tab = (string) ($this->input->get('tab') ?: 'timeline');
        $data = ['title' => _l('se_journeys') . ' #' . (int) $j->id, 'j' => $j, 'tab' => $tab];
        $data['tasks'] = se_journey_open_tasks(20, (int) $j->id);
        $data['staff'] = function_exists('se_appt_selectable_staff') ? se_appt_selectable_staff((int) $j->brand_id) : [];
        $data['consent'] = se_journey_consent_state($j);
        $data['timeline'] = $this->timeline($j);
        $data['can'] = [];
        foreach (array_keys(se_journey_capabilities()) as $cap) {
            $data['can'][$cap] = se_journey_can($cap);
        }
        $data['checklist'] = se_journey_media_checklist($j);
        $data['intake'] = se_journey_intake_get($j);
        $data['answers'] = [];
        $data['fields'] = [];
        if ($tab === 'intake' && $data['can']['view_health'] && $data['intake']) {
            se_journey_audit((int) $j->brand_id, (int) $j->id, 'view_intake', 'intake', (string) $data['intake']->id);
            $data['answers'] = se_journey_intake_answers($data['intake']);
            $data['fields']  = se_journey_fields((int) $j->brand_id);
            $data['sections'] = se_journey_questionnaire()['sections'];
        }
        $data['media'] = [];
        if ($tab === 'photos' && $data['can']['view_photos']) {
            foreach (se_journey_media_list($j) as $m) {
                $m['view_url'] = in_array($m['state'], ['pending_fetch', 'fetch_failed'], true) || empty($m['storage_ref'])
                    ? '' : se_journey_media_view_url((int) $m['id'], (int) get_staff_user_id());
                $data['media'][] = $m;
            }
            $data['retake_reasons'] = se_journey_retake_reasons();
        }
        if ($tab === 'review') {
            $data['review'] = se_journey_review_get($j);
            $data['quote']  = se_journey_quote_latest($j);
            $data['quotes'] = $this->quotes($j);
            $data['decisions'] = se_journey_review_decisions();
            $data['flags'] = $data['intake'] ? (json_decode((string) $data['intake']->flags_json, true) ?: []) : [];
            $data['amount_policy'] = se_journey_quote_amount_policy((int) $j->brand_id);
        }
        if ($tab === 'care') {
            $data['appointments'] = $this->appointments($j);
            $data['aftercare'] = se_journey_aftercare_events($j);
            $data['protocols'] = se_journey_aftercare_protocols((int) $j->brand_id);
            $data['preop'] = se_journey_preop_checklist((int) $j->brand_id);
        }
        $this->load->view('se_journey/view', $data);
    }

    /* ------------------------------------------------------------------ */
    /* Actions (POST + CSRF)                                               */
    /* ------------------------------------------------------------------ */

    /** Start (or resume the welcome of) a journey from a WhatsApp thread. */
    public function start_conversation($conv_id)
    {
        $this->post_only();
        $this->need('edit_review');
        $this->load->model('se_whatsapp/se_whatsapp_model');
        $conv = $this->se_whatsapp_model->get_conversation((int) $conv_id);   // brand-scoped
        if (!$conv) {
            access_denied('se_journey');
        }
        $r = se_journey_start_from_conversation($conv, (int) get_staff_user_id());
        if ($r['ok'] && $r['mode'] === 'sandbox') {
            set_alert('warning', _l('se_journey_sandbox_not_sent'));
        } elseif ($r['ok']) {
            set_alert('success', _l('se_journey_welcome_queued') . ($r['mode'] === 'template' ? ' (' . _l('se_journey_via_template') . ')' : ''));
        } elseif ($r['reason'] === 'already_started' && $r['journey']) {
            set_alert('warning', _l('se_journey_already_started') . ': ' . _l('se_journey_state_' . $r['journey']->state));
        } else {
            set_alert('warning', _l('se_journey_blocked') . ': ' . $r['reason']);
        }
        redirect(admin_url('se_whatsapp/se_whatsapp/conversation/' . (int) $conv_id));
    }

    /** Start a journey for a lead that has a phone but no WhatsApp thread (website applicant). */
    public function start_lead($lead_id)
    {
        $this->post_only();
        $this->need('edit_review');
        $r = se_journey_start_from_lead((int) $lead_id, (int) get_staff_user_id());
        if ($r['ok'] && $r['mode'] === 'sandbox') {
            set_alert('warning', _l('se_journey_sandbox_not_sent'));
        } elseif ($r['ok']) {
            set_alert('success', _l('se_journey_welcome_queued') . ($r['mode'] === 'template' ? ' (' . _l('se_journey_via_template') . ')' : ''));
        } elseif ($r['reason'] === 'already_started' && $r['journey']) {
            set_alert('warning', _l('se_journey_already_started') . ': ' . _l('se_journey_state_' . $r['journey']->state));
        } elseif ($r['reason'] === 'relinked' && $r['journey']) {
            set_alert('success', _l('se_journey_lead_relinked') . ': ' . _l('se_journey_state_' . $r['journey']->state));
        } else {
            $key = 'se_journey_start_fail_' . $r['reason'];
            $txt = _l($key);
            set_alert('warning', _l('se_journey_blocked') . ': ' . ($txt !== $key ? $txt : $r['reason']));
        }
        if ($r['journey']) {
            redirect(admin_url('se_journey/se_journey/view/' . (int) $r['journey']->id));
        }
        redirect(admin_url('leads/index/' . (int) $lead_id));
    }

    public function action($id, $what)
    {
        $this->post_only();
        $j = $this->journey($id);
        $staff = (int) get_staff_user_id();
        $back = admin_url('se_journey/se_journey/view/' . (int) $j->id . '?tab=' . urlencode((string) ($this->input->post('tab') ?: 'timeline')));
        $msg = ['success', _l('se_journey_done')];

        switch ($what) {
            case 'assign':
                $this->need('edit_review');
                $sid = (int) $this->input->post('staff_id');
                $this->db->where('id', (int) $j->id)->where('brand_id', (int) $j->brand_id)->update(db_prefix() . 'se_journeys', ['assigned_staff' => $sid, 'last_updated' => date('Y-m-d H:i:s')]);
                se_journey_audit((int) $j->brand_id, (int) $j->id, 'assign', 'staff', (string) $sid);
                break;
            case 'start':            // organic enquiry: staff decide to start the bot
                $this->need('edit_review');
                if ((string) $j->state === 'new_whatsapp_enquiry') {
                    $r = se_journey_send_welcome($j, 'staff:' . $staff);
                    $msg = !$r['ok'] ? ['warning', _l('se_journey_blocked') . ': ' . $r['reason']]
                         : ($r['mode'] === 'sandbox' ? ['warning', _l('se_journey_sandbox_not_sent')] : ['success', _l('se_journey_welcome_queued')]);
                }
                break;
            case 'resend_link':
                $this->need('edit_review');
                $r = se_journey_send_privacy_and_link($j, 'staff:' . $staff, 'staff', $staff);
                $msg = $r['ok'] ? ['success', _l('se_journey_link_queued')] : ['warning', _l('se_journey_blocked') . ': ' . $r['reason']];
                break;
            case 'pause':
                $this->need('edit_review');
                se_journey_set_automation($j, 'paused_staff', mb_substr((string) $this->input->post('reason') ?: 'staff_pause', 0, 191), 'staff', $staff);
                se_journey_audit((int) $j->brand_id, (int) $j->id, 'automation_pause', null, null, (string) $this->input->post('reason'));
                break;
            case 'resume':
                $this->need('edit_review');
                se_journey_resume($j, $staff, mb_substr((string) $this->input->post('reason') ?: 'staff_resume', 0, 191));
                break;
            case 'reactivate':       // after opt-out, with evidence
                $this->need('edit_review');
                $r = se_journey_reactivate($j, (string) $this->input->post('evidence'), $staff, (string) $this->input->post('note'));
                $msg = $r['ok'] ? ['success', _l('se_journey_done')] : ['warning', _l('se_journey_evidence_required')];
                break;
            case 'close':
                $this->need('edit_review');
                se_journey_transition($j, 'closed_lost', 'staff_close', 'staff', $staff, null, mb_substr((string) $this->input->post('note'), 0, 500));
                break;
            case 'task_done':
                se_journey_task_done((int) $this->input->post('task_id'), $staff);
                break;
            case 'photo_classify':
                $this->need('view_photos');
                se_journey_media_classify((int) $this->input->post('media_id'), (string) $this->input->post('kind'), $staff);
                break;
            case 'photos_accept':
                $this->need('view_photos');
                se_journey_media_accept($j, $staff);
                break;
            case 'photo_retake':
                $this->need('view_photos');
                $r = se_journey_media_request_retake($j, (string) $this->input->post('kind'), (string) $this->input->post('reason'), $staff);
                $msg = $r['ok'] ? ['success', _l('se_journey_retake_queued')] : ['warning', _l('se_journey_blocked') . ': ' . $r['reason']];
                break;
            case 'photo_donor':
                $this->need('view_photos');
                se_journey_media_request_donor($j, $staff);
                break;
            case 'photos_ready':
                $this->need('view_photos');
                se_journey_media_ready_for_review($j, $staff);
                break;
            case 'review_save':
                $this->need('edit_review');
                $r = se_journey_review_save($j, [
                    'internal_notes' => (string) $this->input->post('internal_notes'),
                    'decision'       => (string) $this->input->post('decision'),
                    'decision_note'  => (string) $this->input->post('decision_note'),
                    'checklist'      => (array) $this->input->post('checklist'),
                    'due_at'         => (string) $this->input->post('due_at'),
                    'assigned_staff' => (int) $this->input->post('assigned_staff'),
                    'next_action'    => (string) $this->input->post('next_action'),
                    'next_action_due_at' => (string) $this->input->post('next_action_due_at'),
                    'notify_patient' => (int) $this->input->post('notify_patient'),
                ], $staff);
                if (!$r['ok']) { $msg = ['warning', _l('se_journey_blocked') . ': ' . $r['reason']]; }
                break;
            case 'quote_draft':
                $this->need('edit_review');
                $r = se_journey_quote_draft($j, $this->input->post(), $staff);
                if (!$r['ok']) { $msg = ['warning', _l('se_journey_blocked') . ': ' . $r['reason']]; }
                break;
            case 'quote_request':
                $this->need('edit_review');
                se_journey_quote_request_approval((int) $this->input->post('quote_id'), $staff);
                break;
            case 'quote_approve':
                $this->need('approve_quote');
                $r = se_journey_quote_approve((int) $this->input->post('quote_id'), $staff);
                if (!$r['ok']) { $msg = ['warning', _l('se_journey_blocked') . ': ' . $r['reason']]; }
                break;
            case 'quote_send':
                $this->need('edit_review');
                $r = se_journey_quote_send((int) $this->input->post('quote_id'), $staff);
                $msg = $r['ok'] ? ['success', _l('se_journey_quote_sent')] : ['warning', _l('se_journey_blocked') . ': ' . $r['reason']];
                break;
            case 'book':
                $this->need('manage_consultation');
                $type = (string) $this->input->post('type') === 'procedure' ? 'procedure' : 'consultation';
                $r = se_journey_book_appointment($j, $this->input->post(), $staff, $type);
                $msg = $r['ok'] ? ['success', _l('se_journey_booked')] : ['warning', _l('se_journey_blocked') . ': ' . _l('se_journey_reason_' . $r['reason'])];
                break;
            case 'lead_sync':
                $this->need('view');
                $r = se_journey_sync_lead($j, 'staff');
                $msg = $r['ok'] ? ['success', _l('se_journey_lead_synced')] : ['warning', _l('se_journey_blocked') . ': ' . $r['reason']];
                break;
            case 'book_link':
                // The calendar link (face-to-face consultation slot picker), by hand.
                $this->need('manage_consultation');
                $r = se_journey_send_booking_link($j, $staff, '', 'booking_link_repeat');
                $msg = $r['ok'] ? ['success', _l('se_journey_book_link_sent') . ($r['mode'] === 'sandbox' ? ' — ' . _l('se_journey_sandbox_not_sent') : '')]
                                : ['warning', _l('se_journey_blocked') . ': ' . $r['reason']];
                break;
            case 'appointment':
                $this->need('manage_consultation');
                $r = se_journey_appointment_update($j, (int) $this->input->post('appointment_id'), $this->input->post(), $staff);
                if (!$r['ok']) { $msg = ['warning', _l('se_journey_blocked') . ': ' . $r['reason']]; }
                break;
            case 'preop_start':
                $this->need('manage_consultation');
                se_journey_preop_start($j, $staff);
                break;
            case 'procedure_complete':
                $this->need('manage_consultation');
                $r = se_journey_procedure_complete($j, $staff, (string) $this->input->post('notes'), (array) $this->input->post('technical'), (string) $this->input->post('procedure_at'));
                if (!$r['ok']) { $msg = ['warning', _l('se_journey_blocked') . ': ' . $r['reason']]; }
                break;
            case 'aftercare_start':
                $this->need('manage_aftercare');
                $r = se_journey_aftercare_start($j, (string) $this->input->post('protocol'), $staff, (string) $this->input->post('anchor_at'));
                if (!$r['ok']) { $msg = ['warning', _l('se_journey_blocked') . ': ' . $r['reason']]; }
                break;
            case 'complete':
                $this->need('manage_aftercare');
                se_journey_complete($j, $staff, (string) $this->input->post('note'));
                break;
            default:
                $msg = ['warning', _l('se_journey_unknown_action')];
        }

        set_alert($msg[0], $msg[1]);
        redirect($back);
    }

    /** Signed, expiring, capability-gated photo view. Streams decrypted bytes; audited. */
    public function media($id)
    {
        $this->need('view_photos');
        $m = se_journey_media_get((int) $id);
        if (!$m) {
            access_denied('se_journey');
        }
        $staff = (int) get_staff_user_id();
        if (!se_journey_media_signature_valid((int) $m->id, $staff, (int) $this->input->get('e'), (string) $this->input->get('s'))) {
            set_status_header(403);
            header('Content-Type: text/plain');
            echo 'link expired';
            return;
        }
        $bytes = se_journey_media_read($m);
        if ($bytes === null) {
            set_status_header(404);
            header('Content-Type: text/plain');
            echo 'unavailable';
            return;
        }
        se_journey_audit((int) $m->brand_id, (int) $m->journey_id, 'view_photo', 'media', (string) $m->id);
        header('Content-Type: ' . ($m->mime ?: 'image/jpeg'));
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: inline; filename="photo-' . (int) $m->id . '"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        echo $bytes;
    }

    /** Export the intake answers (JSON). Separate capability, audited. */
    public function export($id)
    {
        $this->post_only();
        $this->need('export_health');
        $j = $this->journey($id);
        $intake = se_journey_intake_get($j);
        if (!$intake) {
            access_denied('se_journey');
        }
        se_journey_audit((int) $j->brand_id, (int) $j->id, 'export_intake', 'intake', (string) $intake->id);
        $payload = ['journey' => (int) $j->id, 'version' => $intake->questionnaire_version, 'submitted_at' => $intake->submitted_at,
                    'flags' => json_decode((string) $intake->flags_json, true), 'answers' => se_journey_intake_answers($intake)];
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="intake-' . (int) $j->id . '.json"');
        header('Cache-Control: no-store, private');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /* ------------------------------------------------------------------ */
    /* Template registry + settings (integration admin)                    */
    /* ------------------------------------------------------------------ */

    /* ------------------------------------------------------------------ */
    /* WhatsApp Flows: the intake form and the calendar inside WhatsApp     */

    public function flows()
    {
        if (!se_journey_can('manage_templates') && !se_journey_is_integration_admin()) {
            access_denied('se_journey');
        }
        $brand = $this->brand();
        $data['title'] = _l('se_journey_flows');
        $data['brand'] = $brand;
        $data['readiness'] = $brand > 0 ? se_journey_flow_readiness($brand) : null;
        $data['key_status'] = null;
        if ($brand > 0 && (string) $this->input->get('check_key') === '1') {
            $data['key_status'] = se_journey_flow_public_key_status($brand);
        }
        $data['json'] = [];
        foreach (se_journey_flow_kinds() as $kind => $def) {
            $data['json'][$kind] = json_encode(se_journey_flow_json($brand, $kind), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        $this->load->view('se_journey/flows', $data);
    }

    public function flow_action($what)
    {
        $this->post_only();
        if (!se_journey_can('manage_templates') && !se_journey_is_integration_admin()) {
            access_denied('se_journey');
        }
        $brand = $this->brand();
        $staff = (int) get_staff_user_id();
        $kind  = (string) $this->input->post('kind');
        $back  = admin_url('se_journey/se_journey/flows?brand=' . $brand);
        if ($what === 'settings') {
            if (!se_journey_is_integration_admin()) { access_denied('se_journey'); }
            update_option('se_journey_flows_' . $brand, (int) $this->input->post('flows_enabled') === 1 ? 1 : 0);
            update_option('se_journey_flow_app_id', preg_replace('/\D/', '', (string) $this->input->post('flow_app_id')));
            se_journey_audit($brand, 0, 'settings_saved', null, null, 'flows');
            set_alert('success', _l('se_journey_saved'));
            redirect($back);
        }
        if ($what === 'register_key') {
            $r = se_journey_flow_register_public_key($brand);
            set_alert($r['ok'] ? 'success' : 'warning', $r['ok'] ? _l('se_journey_flow_key_registered') : _l('se_journey_blocked') . ': ' . $r['reason']);
            redirect($back . '&check_key=1');
        }
        if (!isset(se_journey_flow_kinds()[$kind])) {
            set_alert('warning', _l('se_journey_blocked') . ': unknown flow');
            redirect($back);
        }
        switch ($what) {
            case 'create':
                $r = se_journey_flow_create($brand, $kind, $staff);
                if ($r['ok']) { $r = se_journey_flow_upload_json($brand, $kind, $staff); }
                break;
            case 'upload':
                $r = se_journey_flow_upload_json($brand, $kind, $staff);
                break;
            case 'publish':
                $r = se_journey_flow_publish($brand, $kind, $staff);
                break;
            case 'sync':
                $r = se_journey_flow_sync($brand, $kind);
                break;
            default:
                $r = ['ok' => false, 'reason' => 'unknown'];
        }
        $errs = !empty($r['validation_errors']) ? ' — ' . count($r['validation_errors']) . ' ' . _l('se_journey_flow_validation_errors') : '';
        set_alert($r['ok'] ? ($errs ? 'warning' : 'success') : 'warning', ($r['ok'] ? _l('se_journey_flow_done_' . $what) : _l('se_journey_blocked') . ': ' . $r['reason']) . $errs);
        redirect($back);
    }

    public function templates()
    {
        if (!se_journey_can('manage_templates') && !se_journey_is_integration_admin()) {
            access_denied('se_journey');
        }
        $brand = $this->brand();
        $data['title'] = _l('se_journey_templates');
        $data['brand'] = $brand;
        if ($brand > 0) {
            se_journey_seed_templates($brand);   // registers definitions added since the last release (idempotent)
            se_journey_seed_templates($brand);
            se_journey_sync_template_status($brand);
        }
        $this->db->where('brand_id', $brand)->order_by('logical_name', 'ASC');
        $data['rows'] = $this->db->get(db_prefix() . 'se_journey_templates')->result_array();
        $data['mirror'] = function_exists('se_wa_approved_templates') ? array_column(se_wa_approved_templates($brand), 'name') : [];
        $data['can_submit'] = function_exists('se_wa_cloud_token') && se_wa_cloud_token() !== '' && function_exists('se_wa_waba_for_brand') && se_wa_waba_for_brand($brand) !== '';
        $data['test_recipients'] = se_journey_test_recipients($brand);
        $this->load->view('se_journey/templates', $data);
    }

    public function template_action($what)
    {
        $this->post_only();
        if (!se_journey_can('manage_templates') && !se_journey_is_integration_admin()) {
            access_denied('se_journey');
        }
        $brand = $this->brand();
        $logical = (string) $this->input->post('logical');
        if ($what === 'submit') {
            $r = se_journey_submit_template($brand, $logical, (int) get_staff_user_id());
            set_alert($r['ok'] ? 'success' : 'warning', $r['ok'] ? _l('se_journey_template_submitted') . ' (' . $r['status'] . ')' : _l('se_journey_blocked') . ': ' . $r['reason']);
        } elseif ($what === 'sync') {
            if (function_exists('se_wa_sync_templates')) { se_wa_sync_templates($brand); }
            $n = se_journey_sync_template_status($brand);
            set_alert('success', _l('se_journey_template_synced') . ' (' . $n . ')');
        } elseif ($what === 'test_send') {
            // Only to an allow-listed test recipient, only an approved template.
            $to = se_journey_normalize_wa_id((string) $this->input->post('to'));
            if (!in_array($to, se_journey_test_recipients($brand), true)) {
                set_alert('warning', _l('se_journey_test_recipient_not_allowlisted'));
            } else {
                $j = se_journey_find_by_wa($brand, $to);
                $ready = se_journey_template_ready($brand, $logical);
                if (!$j || !$ready['ready']) {
                    set_alert('warning', _l('se_journey_blocked') . ': ' . ($j ? 'template_' . $ready['reason'] : 'no_journey_for_recipient'));
                } else {
                    $vars = json_decode((string) $this->input->post('vars'), true);
                    $conv = se_journey_conversation($j);
                    $r = $conv ? se_wa_queue_message((int) $conv->id, ['kind' => 'template', 'template' => $ready['meta_name'], 'variables' => is_array($vars) ? $vars : [], 'origin' => 'journey:test_send'], (int) get_staff_user_id()) : ['ok' => false, 'reason' => 'no_conversation'];
                    se_journey_audit($brand, (int) $j->id, 'template_test_send', 'template', $logical, $r['ok'] ? 'queued' : $r['reason']);
                    set_alert($r['ok'] ? 'success' : 'warning', $r['ok'] ? _l('se_journey_test_send_queued') : _l('se_journey_blocked') . ': ' . $r['reason']);
                }
            }
        }
        redirect(admin_url('se_journey/se_journey/templates'));
    }

    public function settings()
    {
        if (!se_journey_is_integration_admin() && !se_journey_can('manage_consent')) {
            access_denied('se_journey');
        }
        $brand = $this->brand();
        $data['title'] = _l('se_journey_settings');
        $data['brand'] = $brand;
        $data['is_admin'] = is_admin();
        $data['integration_admin'] = se_journey_is_integration_admin();
        $data['readiness'] = $brand > 0 ? se_journey_readiness($brand) : null;
        $data['health'] = $brand > 0 ? se_journey_health($brand) : null;
        $data['values'] = [
            'enabled'      => se_journey_enabled($brand) ? 1 : 0,
            'sandbox'      => se_journey_sandbox($brand) ? 1 : 0,
            'interactive'  => se_journey_interactive_enabled($brand) ? 1 : 0,
            'auto_organic' => se_journey_auto_start_organic($brand) ? 1 : 0,
            'auto_website' => se_journey_auto_start_website($brand) ? 1 : 0,
            'test_recipients' => implode(', ', se_journey_test_recipients($brand)),
            'intake_ttl_hours' => se_journey_intake_ttl_hours(),
            'reminder_hours' => implode(',', se_journey_reminder_hours()),
            'quiet_hours'  => (string) se_journey_config('quiet_hours', SE_JOURNEY_DEFAULT_QUIET),
            'daily_cap'    => se_journey_daily_cap(),
            'urgent_staff_ids' => (string) se_journey_config('urgent_staff_ids', ''),
            'public_base_url' => (string) se_journey_config('public_base_url', ''),
            'quote_amount_policy' => se_journey_quote_amount_policy($brand),
            'preop_text_approved' => (int) get_option('se_journey_preop_text_approved_' . $brand),
            'preop_info_url' => (string) get_option('se_journey_preop_info_url_' . $brand),
            'consultation_info_approved' => (int) get_option('se_journey_consultation_info_approved_' . $brand),
            'technical_fields' => (int) get_option('se_journey_technical_fields_' . $brand),
            'media_storage' => (string) get_option('se_journey_media_storage') ?: 'auto',
            'media_storage_status' => se_journey_media_storage_status(),
            'purge_inbox_copy' => (int) get_option('se_journey_purge_inbox_copy_' . $brand),
            'lead_sync' => se_journey_lead_sync_enabled($brand) ? 1 : 0,
            'lead_sync_status' => se_journey_lead_sync_status_enabled($brand) ? 1 : 0,
            'booking' => se_journey_booking_settings($brand),
            'booking_staff_options' => $brand > 0 && function_exists('se_appt_selectable_staff') ? se_appt_selectable_staff($brand) : [],
            'ask_infectious' => (int) get_option('se_journey_ask_infectious_' . $brand),
            'consent_bypass' => se_journey_consent_bypass_active($brand) ? 1 : 0,
            'consent_bypass_reason' => (string) get_option('se_journey_consent_bypass_reason_' . $brand),
            'protocols_json' => json_encode(array_values(se_journey_aftercare_protocols($brand)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'copy_json' => (string) get_option('se_journey_copy_' . $brand),
        ];
        $data['copy_defaults'] = se_journey_copy_defaults()['tr'];
        $this->load->view('se_journey/settings', $data);
    }

    public function save_settings()
    {
        $this->post_only();
        $brand = $this->brand();
        $staff = (int) get_staff_user_id();
        $section = (string) $this->input->post('section');

        if ($section === 'flags') {
            if (!se_journey_is_integration_admin()) { access_denied('se_journey'); }
            update_option('se_journey_enabled_' . $brand, (int) $this->input->post('enabled') === 1 ? 1 : 0);
            update_option('se_journey_sandbox_' . $brand, (int) $this->input->post('sandbox') === 1 ? 1 : 0);
            update_option('se_journey_interactive_' . $brand, (int) $this->input->post('interactive') === 1 ? 1 : 0);
            update_option('se_journey_auto_start_organic_' . $brand, (int) $this->input->post('auto_organic') === 1 ? 1 : 0);
            update_option('se_journey_auto_start_website_' . $brand, (int) $this->input->post('auto_website') === 1 ? 1 : 0);
            update_option('se_journey_test_recipients_' . $brand, mb_substr(preg_replace('/[^\d,\s;]/', '', (string) $this->input->post('test_recipients')), 0, 500));
            update_option('se_journey_intake_ttl_hours', max(1, min(336, (int) $this->input->post('intake_ttl_hours'))));
            update_option('se_journey_reminder_hours', preg_replace('/[^\d,]/', '', (string) $this->input->post('reminder_hours')));
            update_option('se_journey_quiet_hours', preg_match('/^\d{1,2}:\d{2}-\d{1,2}:\d{2}$/', (string) $this->input->post('quiet_hours')) ? (string) $this->input->post('quiet_hours') : SE_JOURNEY_DEFAULT_QUIET);
            update_option('se_journey_daily_cap', max(1, min(20, (int) $this->input->post('daily_cap'))));
            update_option('se_journey_urgent_staff_ids', preg_replace('/[^\d,\s]/', '', (string) $this->input->post('urgent_staff_ids')));
            $base = trim((string) $this->input->post('public_base_url'));
            update_option('se_journey_public_base_url', preg_match('#^https://[a-z0-9.-]+(?::\d+)?(/.*)?$#i', $base) ? $base : '');
            update_option('se_journey_quote_amount_policy_' . $brand, in_array((string) $this->input->post('quote_amount_policy'), ['hidden', 'range', 'exact'], true) ? (string) $this->input->post('quote_amount_policy') : 'range');
            update_option('se_journey_technical_fields_' . $brand, (int) $this->input->post('technical_fields') === 1 ? 1 : 0);
            update_option('se_journey_media_storage', in_array((string) $this->input->post('media_storage'), ['auto', 'r2', 'local'], true) ? (string) $this->input->post('media_storage') : 'auto');
            update_option('se_journey_purge_inbox_copy_' . $brand, (int) $this->input->post('purge_inbox_copy') === 1 ? 1 : 0);
            update_option('se_journey_lead_sync_' . $brand, (int) $this->input->post('lead_sync') === 1 ? 1 : 0);
            update_option('se_journey_lead_sync_status_' . $brand, (int) $this->input->post('lead_sync_status') === 1 ? 1 : 0);
            // Patient self-booking calendar (face-to-face consultation after an accepted quote).
            update_option('se_journey_booking_staff_' . $brand, max(0, (int) $this->input->post('booking_staff')));
            update_option('se_journey_booking_slot_' . $brand, max(15, min(180, (int) $this->input->post('booking_slot'))));
            update_option('se_journey_booking_horizon_' . $brand, max(1, min(60, (int) $this->input->post('booking_horizon'))));
            update_option('se_journey_booking_notice_' . $brand, max(0, min(168, (int) $this->input->post('booking_notice'))));
            $hours = trim((string) $this->input->post('booking_hours'));
            update_option('se_journey_booking_hours_' . $brand, preg_match('/^\d{1,2}:\d{2}-\d{1,2}:\d{2}$/', $hours) ? $hours : SE_JOURNEY_BOOKING_DEFAULT_HOURS);
            $days = array_values(array_unique(array_filter(array_map('intval', (array) $this->input->post('booking_days')), function ($d) { return $d >= 0 && $d <= 6; })));
            update_option('se_journey_booking_days_' . $brand, $days ? implode(',', $days) : SE_JOURNEY_BOOKING_DEFAULT_DAYS);
            update_option('se_journey_booking_location_' . $brand, mb_substr(trim((string) $this->input->post('booking_location')), 0, 191));
            se_journey_audit($brand, 0, 'settings_saved', null, null, 'flags');
        } elseif ($section === 'clinical') {
            if (!se_journey_can('manage_consent')) { access_denied('se_journey'); }
            update_option('se_journey_preop_text_approved_' . $brand, (int) $this->input->post('preop_text_approved') === 1 ? 1 : 0);
            $url = trim((string) $this->input->post('preop_info_url'));
            update_option('se_journey_preop_info_url_' . $brand, preg_match('#^https://#i', $url) ? mb_substr($url, 0, 500) : '');
            update_option('se_journey_consultation_info_approved_' . $brand, (int) $this->input->post('consultation_info_approved') === 1 ? 1 : 0);
            update_option('se_journey_ask_infectious_' . $brand, (int) $this->input->post('ask_infectious') === 1 ? 1 : 0);
            $protocols = json_decode((string) $this->input->post('protocols_json'), true);
            if (is_array($protocols)) {
                $r = se_journey_aftercare_save_protocols($brand, $protocols, $staff);
                if (!$r['ok']) { set_alert('warning', _l('se_journey_protocol_invalid') . ': ' . $r['reason']); redirect(admin_url('se_journey/se_journey/settings')); }
            }
            se_journey_audit($brand, 0, 'settings_saved', null, null, 'clinical');
        } elseif ($section === 'copy') {
            if (!se_journey_can('manage_consent')) { access_denied('se_journey'); }
            $copy = json_decode((string) $this->input->post('copy_json'), true);
            if ((string) $this->input->post('copy_json') !== '' && !is_array($copy)) {
                set_alert('warning', _l('se_journey_copy_invalid')); redirect(admin_url('se_journey/se_journey/settings'));
            }
            if (is_array($copy)) {
                $copy['version'] = mb_substr((string) ($copy['version'] ?? ('custom-' . date('Ymd-Hi'))), 0, 32);
                update_option('se_journey_copy_' . $brand, json_encode($copy, JSON_UNESCAPED_UNICODE));
            } else {
                update_option('se_journey_copy_' . $brand, '');
            }
            se_journey_audit($brand, 0, 'copy_saved', null, null, is_array($copy) ? $copy['version'] : 'reset');
        } elseif ($section === 'bypass') {
            if (!is_admin()) { access_denied('se_journey'); }
            $ok = se_journey_set_consent_bypass($brand, (int) $this->input->post('consent_bypass') === 1, (string) $this->input->post('consent_bypass_reason'), $staff);
            if (!$ok) { set_alert('warning', _l('se_journey_bypass_reason_required')); redirect(admin_url('se_journey/se_journey/settings')); }
        }
        set_alert('success', _l('se_journey_saved'));
        redirect(admin_url('se_journey/se_journey/settings'));
    }

    /* ------------------------------------------------------------------ */

    private function journey($id)
    {
        $j = se_journey_get((int) $id);
        if (!$j) {
            access_denied('se_journey');
        }

        return $j;
    }

    private function brand()
    {
        $b = (int) $this->input->post_get('brand');
        if ($b <= 0 && function_exists('se_clinic_sole_brand_id')) {
            $b = (int) se_clinic_sole_brand_id();
        }
        if ($b > 0 && !se_can_access_brand($b)) {
            access_denied('se_journey');
        }

        return $b;
    }

    private function need($cap)
    {
        if (!se_journey_can($cap)) {
            access_denied('se_journey');
        }
    }

    private function post_only()
    {
        if (strtolower((string) $this->input->method()) !== 'post') {
            access_denied('se_journey');
        }
    }

    /** Merge messages, transitions, events, appointment history into one ordered timeline (non-sensitive). */
    private function timeline($j)
    {
        $items = [];
        $this->db->where('conversation_id', (int) $j->wa_conversation_id)->where('brand_id', (int) $j->brand_id)->order_by('id', 'DESC')->limit(100);
        foreach ($this->db->get(db_prefix() . 'se_wa_messages')->result_array() as $m) {
            $items[] = ['at' => $m['received_at'] ?: ($m['sent_at'] ?: $m['date_created']), 'kind' => 'wa_' . $m['direction'],
                        'label' => ($m['direction'] === 'in' ? _l('se_wa_direction_in') : _l('se_wa_direction_out')) . ' · ' . $m['type'] . ($m['delivery_state'] ? ' · ' . $m['delivery_state'] : ''),
                        'text' => $m['type'] === 'image' ? '[photo]' : mb_substr((string) $m['body'], 0, 300), 'actor' => $m['source'] ?: ''];
        }
        $this->db->where('journey_id', (int) $j->id)->order_by('id', 'DESC')->limit(200);
        foreach ($this->db->get(db_prefix() . 'se_journey_transitions')->result_array() as $t) {
            $items[] = ['at' => $t['created_at'], 'kind' => 'transition', 'label' => ($t['from_state'] ?: '—') . ' → ' . $t['to_state'],
                        'text' => $t['trigger_key'] . ($t['note'] ? ' — ' . $t['note'] : ''), 'actor' => $t['actor_type'] . ($t['actor_id'] ? ' #' . $t['actor_id'] : '')];
        }
        $this->db->where('journey_id', (int) $j->id)->order_by('id', 'DESC')->limit(200);
        foreach ($this->db->get(db_prefix() . 'se_journey_events')->result_array() as $e) {
            $items[] = ['at' => $e['created_at'], 'kind' => 'event', 'label' => $e['kind'], 'text' => (string) $e['summary'], 'actor' => $e['actor_type']];
        }
        foreach ([$j->consultation_appointment_id, $j->procedure_appointment_id] as $aid) {
            if ((int) $aid <= 0) { continue; }
            $this->db->where('appointment_id', (int) $aid)->where('brand_id', (int) $j->brand_id)->order_by('id', 'ASC');
            foreach ($this->db->get(db_prefix() . 'se_appointment_status_history')->result_array() as $h) {
                $items[] = ['at' => $h['changed_at'], 'kind' => 'appointment', 'label' => 'appointment #' . $aid . ': ' . ($h['old_status'] ?: '—') . ' → ' . $h['new_status'], 'text' => '', 'actor' => 'staff #' . $h['changed_by']];
            }
        }
        usort($items, function ($a, $b) { return strcmp((string) $b['at'], (string) $a['at']); });

        return array_slice($items, 0, 250);
    }

    private function quotes($j)
    {
        $this->db->where('journey_id', (int) $j->id)->where('brand_id', (int) $j->brand_id)->order_by('id', 'DESC');

        return $this->db->get(db_prefix() . 'se_journey_quotes')->result_array();
    }

    private function appointments($j)
    {
        if ((int) $j->lead_id <= 0) { return []; }
        $this->db->where('rel_type', 'lead')->where('rel_id', (int) $j->lead_id)->where('brand_id', (int) $j->brand_id)->order_by('start_at', 'DESC');

        return $this->db->get(db_prefix() . 'se_appointments')->result_array();
    }
}
