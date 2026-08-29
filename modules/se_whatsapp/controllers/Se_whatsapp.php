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
        $data['title']         = _l('se_whatsapp');
        $data['conversations'] = $this->se_whatsapp_model->conversations([
            'assigned' => $this->input->get('assigned'),
        ]);
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
        $data['messages']     = $this->se_whatsapp_model->messages((int) $conversation->id);
        $data['window_open']  = se_wa_window_open($conversation);
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
}
