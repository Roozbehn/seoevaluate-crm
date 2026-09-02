<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Se_whatsapp extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('se_whatsapp/se_whatsapp_model');
    }

    public function inbox()
    {
        if (staff_cant('view', 'se_whatsapp')) {
            access_denied('se_whatsapp');
        }
        $data['title']     = _l('se_whatsapp');
        $data['has_brand'] = se_staff_has_any_brand();
        $data['out_health'] = se_wa_out_health();
        $data['conversations'] = $this->se_whatsapp_model->conversations([
            'assigned' => $this->input->get('assigned'),
        ]);
        $data['blocked']   = se_wa_inbox_blocked_reason($data['conversations']);
        // Only staff the current user could legitimately assign.
        $data['staff'] = function_exists('se_appt_selectable_staff') ? se_appt_selectable_staff() : [];
        $this->load->view('se_whatsapp/inbox', $data);
    }

    public function conversation($id)
    {
        if (staff_cant('view', 'se_whatsapp')) {
            access_denied('se_whatsapp');
        }
        // Brand guard: a scoped lookup returns null for a foreign-brand conversation.
        $conversation = $this->se_whatsapp_model->get_conversation($id);
        if (!$conversation) {
            access_denied('se_whatsapp');
        }
        $data['title']        = _l('se_whatsapp');
        $data['conversation'] = $conversation;

        // Viewing the thread marks it read INSIDE the CRM. Local unread state
        // only — sending a WhatsApp read receipt to the customer is a separate,
        // policy-controlled action and is NOT triggered by opening the page.
        if ((int) $conversation->unread_count > 0) {
            $this->db->where('id', (int) $conversation->id)
                     ->where('brand_id', (int) $conversation->brand_id)
                     ->update(db_prefix() . 'se_wa_conversations', ['unread_count' => 0]);
            $conversation->unread_count = 0;
        }

        $data['messages']     = $this->se_whatsapp_model->messages((int) $conversation->id);
        $data['policy']       = se_wa_compose_policy($conversation);
        $data['templates']    = se_wa_approved_templates((int) $conversation->brand_id);
        $data['staff']        = se_appt_selectable_staff((int) $conversation->brand_id);
        $data['queued']       = se_wa_out_health((int) $conversation->brand_id);
        $data['tracker']      = function_exists('se_outbound_rows')
            ? se_outbound_rows('se_wa_outbound', (int) $conversation->id, 'wamid') : [];
        $data['dispatch_eta'] = function_exists('se_outbound_dispatch_eta') ? se_outbound_dispatch_eta() : null;
        $this->load->view('se_whatsapp/conversation', $data);
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

        $result = se_wa_queue_message((int) $id, [
            'kind'     => $kind,
            'body'     => (string) $this->input->post('body'),
            'template' => (string) $this->input->post('template'),
        ], (int) get_staff_user_id());

        if ($result['ok']) {
            set_alert('success', _l('se_wa_reply_queued'));
        } else {
            set_alert('warning', _l('se_wa_reply_blocked_' . $result['reason']) ?: _l('se_wa_reply_blocked'));
        }

        redirect(admin_url('se_whatsapp/se_whatsapp/conversation/' . (int) $id));
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
