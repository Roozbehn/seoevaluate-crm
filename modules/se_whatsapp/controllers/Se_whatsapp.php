<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Se_whatsapp extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('se_whatsapp/se_whatsapp_model');
    }

    /** Mesajlar (CRM-M035): list + thread + context on one page; ?c=<id> selects a thread. */
    public function inbox()
    {
        if (staff_cant('view', 'se_whatsapp')) {
            access_denied('se_whatsapp');
        }
        $this->render_inbox((int) $this->input->get('c'));
    }

    /** Deep link to one thread (Bugün, Hastalar, notifications). Same page, thread selected. */
    public function conversation($id)
    {
        if (staff_cant('view', 'se_whatsapp')) {
            access_denied('se_whatsapp');
        }
        $this->render_inbox((int) $id, true);
    }

    private function render_inbox($selected, $deep_link = false)
    {
        $t0 = microtime(true);
        $data['title']     = _l('se_nav_messages');
        $data['has_brand'] = se_staff_has_any_brand();
        $data['f']         = se_wa_inbox_filters((array) $this->input->get());
        $data['list']      = $data['has_brand'] ? se_wa_inbox_rows($data['f']) : ['rows' => [], 'has_more' => false, 'next_before' => '', 'counts' => ['unread' => 0]];
        $data['out_health'] = se_wa_out_health();
        $data['blocked']   = $data['has_brand'] ? se_wa_inbox_blocked_reason(array_map(function ($r) { return ['brand_id' => $r['brand_id']]; }, $data['list']['rows'])) : '';
        $data['staff']     = function_exists('se_appt_selectable_staff') ? se_appt_selectable_staff() : [];
        $data['selected']  = $selected;
        $data['conversation'] = null;
        $data['evidence_redacted'] = $this->input->get('evidence') === 'redacted';

        if ($selected > 0) {
            // Brand guard: a scoped lookup returns null for a foreign-brand conversation.
            $conversation = $this->se_whatsapp_model->get_conversation($selected);
            if (!$conversation) {
                access_denied('se_whatsapp');
            }
            // Viewing the thread marks it read INSIDE the CRM. Local unread state
            // only — sending a WhatsApp read receipt to the customer is a separate,
            // policy-controlled action and is NOT triggered by opening the page.
            if ((int) $conversation->unread_count > 0) {
                $this->db->where('id', (int) $conversation->id)
                         ->where('brand_id', (int) $conversation->brand_id)
                         ->update(db_prefix() . 'se_wa_conversations', ['unread_count' => 0]);
                $conversation->unread_count = 0;
                foreach ($data['list']['rows'] as &$r) { if ($r['id'] === (int) $conversation->id) { $r['unread'] = 0; } }
                unset($r);
            }
            $data['conversation'] = $conversation;
            $page = se_wa_thread_page((int) $conversation->id, (int) $this->input->get('before'));
            $data['messages']     = $page['messages'];
            $data['older_before'] = $page['older_before'];
            $data['media']        = function_exists('se_media_for_messages')
                ? se_media_for_messages('wa', array_column($data['messages'], 'id')) : [];
            $data['policy']       = se_wa_compose_policy($conversation);
            $data['templates']    = se_wa_approved_templates((int) $conversation->brand_id);
            $data['queued']       = se_wa_out_health((int) $conversation->brand_id);
            $data['tracker']      = function_exists('se_outbound_rows')
                ? se_outbound_rows('se_wa_outbound', (int) $conversation->id, 'wamid') : [];
            $data['dispatch_eta'] = function_exists('se_outbound_dispatch_eta') ? se_outbound_dispatch_eta() : null;
            $data['ctx_html']     = function_exists('se_journey_conversation_context') ? se_journey_conversation_context($conversation) : '';
            $data['journey']      = function_exists('se_journey_find_by_wa') ? se_journey_find_by_wa((int) $conversation->brand_id, (string) $conversation->wa_user_id) : null;
            $data['row']          = null;
            foreach ($data['list']['rows'] as $r) { if ($r['id'] === (int) $conversation->id) { $data['row'] = $r; break; } }
            $data['back_url']     = admin_url('se_whatsapp/se_whatsapp/inbox' . ($data['f']['f'] !== 'all' ? '?f=' . $data['f']['f'] : ''));
        }
        $data['build_ms'] = (int) round((microtime(true) - $t0) * 1000);
        $this->load->view('se_whatsapp/inbox', $data);
    }

    /**
     * Assign a conversation to a staff member. POST-only + CSRF.
     *
     * The model additionally refuses an assignee outside the conversation's
     * brand, so a crafted staff_id cannot hand the thread to another tenant.
     */
    public function assign($id)
    {
        if ($this->input->method() !== 'post') {
            access_denied('se_whatsapp');
        }

        if (staff_cant('edit', 'se_whatsapp')) {
            access_denied('se_whatsapp');
        }

        $conversation = $this->se_whatsapp_model->get_conversation($id);

        if (!$conversation) {
            access_denied('se_whatsapp');
        }

        if (!$this->se_whatsapp_model->assign((int) $id, (int) $this->input->post('staff_id'))) {
            set_alert('warning', _l('se_wa_assign_denied'));
        }

        redirect(admin_url('se_whatsapp/conversation/' . (int) $id));
    }

    /**
     * Queue a reply. POST-only + CSRF + capability + brand guarded.
     *
     * Nothing is SENT here: the message is queued and the drainer holds it
     * while sending is gated. The window rule is enforced by the model, not
     * trusted to the form.
     */
    /** Where a reply returns: the thread page, or the same-site admin page that embedded the composer (patient Sohbet tab). */
    private function reply_back($id)
    {
        $back = (string) $this->input->post('back');
        $base = admin_url();
        if ($back !== '' && strpos($back, $base) === 0 && strpos($back, "\n") === false && strpos($back, "\r") === false) {
            return $back;
        }

        return admin_url('se_whatsapp/se_whatsapp/conversation/' . (int) $id);
    }

    public function reply($id)
    {
        if ($this->input->method() !== 'post') {
            access_denied('se_whatsapp');
        }

        if (staff_cant('create', 'se_whatsapp')) {
            access_denied('se_whatsapp');
        }

        $conversation = $this->se_whatsapp_model->get_conversation($id);

        if (!$conversation) {
            access_denied('se_whatsapp');
        }

        $kind = $this->input->post('kind') === 'template' ? 'template' : 'text';
        $template = (string) $this->input->post('template');

        // Placeholder values are posted per template (variables[<name>][<n>]);
        // only the chosen template's set is used, in placeholder order.
        $posted    = $this->input->post('variables');
        $variables = [];
        if ($kind === 'template' && is_array($posted) && isset($posted[$template]) && is_array($posted[$template])) {
            foreach ($posted[$template] as $k => $v) {
                $variables[(string) $k] = trim((string) $v);
            }
        }

        // An attachment turns a text reply into a media reply (text = caption).
        // The file is validated + stored BEFORE anything is queued; a rejected
        // file queues nothing and says why.
        if ($kind === 'text' && !empty($_FILES['attachment']) && (int) $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $up = se_media_store_upload('wa', (int) $conversation->brand_id, $_FILES['attachment'], (int) get_staff_user_id());
            if (!$up['ok']) {
                set_alert('warning', _l('se_media_upload_' . $up['error']) ?: _l('se_media_upload_failed'));
                redirect($this->reply_back((int) $id));
                return;
            }
            $kind = 'media';
            $media_id = (int) $up['id'];

            // WhatsApp audio cannot carry a caption: send the voice message
            // first and any typed text as its own message right after.
            $text = trim((string) $this->input->post('body'));
            if ($up['kind'] === 'audio' && $text !== '') {
                $first = se_wa_queue_message((int) $id, ['kind' => 'media', 'media_id' => $media_id], (int) get_staff_user_id());
                if (!$first['ok']) {
                    set_alert('warning', _l('se_wa_reply_blocked_' . $first['reason']) ?: _l('se_wa_reply_blocked'));
                    redirect($this->reply_back((int) $id));
                    return;
                }
                $kind = 'text';
                $media_id = 0;
            }
        }

        // A journey template that opens a WhatsApp Flow needs a per-patient
        // flow token (and the patient's name) that only the journey issues:
        // it goes through the journey step that owns it, never as a plain
        // template — Meta refuses one without its parameters (#132000).
        if ($kind === 'template' && function_exists('se_journey_compose_template')) {
            $via = se_journey_compose_template($conversation, $template, (int) get_staff_user_id());
            if ($via !== null) {
                if (!empty($via['ok'])) {
                    set_alert($via['mode'] === 'sandbox' ? 'warning' : 'success',
                        _l($via['mode'] === 'sandbox' ? 'se_wa_reply_sandbox_not_sent' : 'se_wa_reply_queued_via_journey'));
                } else {
                    $key = 'se_wa_reply_blocked_' . $via['reason'];
                    $txt = _l($key);
                    set_alert('warning', $txt !== $key ? $txt : _l('se_wa_reply_blocked') . ': ' . $via['reason']);
                }
                redirect($this->reply_back((int) $id));
                return;
            }
        }

        $result = se_wa_queue_message((int) $id, [
            'kind'      => $kind,
            'body'      => (string) $this->input->post('body'),
            'template'  => $template,
            'variables' => $variables,
            'media_id'  => $media_id ?? 0,
            'pause_automation' => (int) $this->input->post('pause_automation') === 1,
        ], (int) get_staff_user_id());

        if ($result['ok']) {
            set_alert('success', _l('se_wa_reply_queued'));
        } else {
            set_alert('warning', _l('se_wa_reply_blocked_' . $result['reason']) ?: _l('se_wa_reply_blocked'));
        }

        redirect($this->reply_back((int) $id));
    }

    /**
     * Pull the WABA template library into the mirror. POST-only + CSRF +
     * configuration capability + brand guarded. Reads Meta; writes only the
     * templates table. Never sends a message.
     */
    public function sync_templates()
    {
        if ($this->input->method() !== 'post') {
            access_denied('se_whatsapp');
        }

        if (!se_staff_can_configure_brands()) {
            access_denied('se_whatsapp');
        }

        $brand = (int) $this->input->post('brand');

        if ($brand <= 0 || !se_can_access_brand($brand)) {
            access_denied('se_whatsapp');
        }

        $r = se_wa_sync_templates($brand);

        if ($r['ok']) {
            set_alert('success', sprintf(_l('se_wa_templates_synced'),
                (int) $r['approved'], (int) $r['inserted'], (int) $r['updated'], (int) $r['removed']));
        } else {
            set_alert('warning', _l('se_wa_templates_sync_failed') . ': ' . $r['reason']);
        }

        $back = (string) $this->input->post('back');
        redirect($back !== '' && strpos($back, admin_url()) === 0
            ? $back
            : admin_url('se_whatsapp/se_whatsapp/readiness?brand=' . $brand));
    }

    /** Per-brand readiness: numbers, templates, webhook and queue health. */
    public function readiness()
    {
        if (!se_staff_can_configure_brands()) {
            access_denied('se_whatsapp');
        }

        $brand = (int) $this->input->get('brand');

        if ($brand > 0 && !se_can_access_brand($brand)) {
            access_denied('se_whatsapp');
        }

        $data['title']     = _l('se_wa_readiness');
        $data['brand']     = $brand;
        $data['brands']    = se_all_brands(false, true);
        $data['numbers']   = se_wa_numbers_for($brand);
        $data['templates'] = $brand > 0 ? se_wa_approved_templates($brand) : [];
        $data['templates_synced_at'] = $brand > 0 ? (string) get_option('se_wa_templates_synced_at_' . $brand) : '';
        $data['templates_last_error'] = $brand > 0 ? (string) get_option('se_wa_templates_last_error_' . $brand) : '';
        $data['can_sync_templates'] = $brand > 0 && se_wa_waba_for_brand($brand) !== '';
        $data['blocked']   = se_wa_send_blocked_reason($brand);
        $data['out_health'] = se_wa_out_health($brand);
        $data['webhook_state'] = function_exists('se_webhook_state')
            ? se_webhook_state('wa')
            : null;
        $data['webhook']   = [
            'url'          => site_url('se_whatsapp/webhook'),
            // WhatsApp and Lead Ads use the same Meta app in production.  The
            // canonical accessor intentionally inherits meta_app when there is
            // no dedicated wa_app file; checking the raw provider here made a
            // verified, working webhook appear unconfigured.
            'app_secret'   => function_exists('se_wa_app_secret') && se_wa_app_secret() !== '',
            'app_secret_inherited' => function_exists('se_wa_app_secret_inherited')
                ? se_wa_app_secret_inherited()
                : false,
            'verify_token' => se_secret_configured('wa_verify', 0),
            'last_event'   => se_wa_last_event_at(),
        ];

        $this->load->view('se_whatsapp/readiness', $data);
    }
}
