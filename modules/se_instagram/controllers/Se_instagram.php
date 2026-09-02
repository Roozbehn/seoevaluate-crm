<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Se_instagram extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('se_instagram/se_instagram_model');
    }

    public function inbox()
    {
        if (staff_cant('view', 'se_instagram')) {
            access_denied('se_instagram');
        }
        $data['title']         = _l('se_instagram');
        $data['has_brand']     = se_staff_has_any_brand();
        $data['out_health']    = se_ig_out_health();
        $data['conversations'] = $this->se_instagram_model->conversations(['assigned' => $this->input->get('assigned')]);
        $data['blocked']       = se_ig_inbox_blocked_reason($data['conversations']);
        $this->load->view('se_instagram/inbox', $data);
    }

    public function conversation($id)
    {
        if (staff_cant('view', 'se_instagram')) {
            access_denied('se_instagram');
        }
        $conversation = $this->se_instagram_model->get_conversation($id);
        if (!$conversation) {
            access_denied('se_instagram');
        }

        // Viewing marks the thread read INSIDE the CRM only (no read receipt sent).
        if ((int) $conversation->unread_count > 0) {
            $this->db->where('id', (int) $conversation->id)->where('brand_id', (int) $conversation->brand_id)
                     ->update(db_prefix() . 'se_ig_conversations', ['unread_count' => 0]);
            $conversation->unread_count = 0;
        }

        $data['title']        = _l('se_instagram');
        $data['conversation'] = $conversation;
        $data['messages']     = $this->se_instagram_model->messages((int) $conversation->id);
        $data['policy']       = se_ig_compose_policy($conversation);
        $data['staff']        = function_exists('se_appt_selectable_staff') ? se_appt_selectable_staff((int) $conversation->brand_id) : [];
        $data['queued']       = se_ig_out_health((int) $conversation->brand_id);
        $this->load->view('se_instagram/conversation', $data);
    }

    public function assign($id)
    {
        if ($this->input->method() !== 'post' || staff_cant('edit', 'se_instagram')) {
            access_denied('se_instagram');
        }
        if (!$this->se_instagram_model->get_conversation($id)) {
            access_denied('se_instagram');
        }
        if (!$this->se_instagram_model->assign((int) $id, (int) $this->input->post('staff_id'))) {
            set_alert('warning', _l('se_ig_assign_denied'));
        }
        redirect(admin_url('se_instagram/se_instagram/conversation/' . (int) $id));
    }

    /** Queue a reply. Nothing is sent inline; the drain sends via the transport. */
    public function reply($id)
    {
        if ($this->input->method() !== 'post' || staff_cant('create', 'se_instagram')) {
            access_denied('se_instagram');
        }
        if (!$this->se_instagram_model->get_conversation($id)) {
            access_denied('se_instagram');
        }

        $result = se_ig_queue_message((int) $id, ['body' => (string) $this->input->post('body')], (int) get_staff_user_id());

        if ($result['ok']) {
            set_alert('success', _l('se_ig_reply_queued'));
        } else {
            set_alert('warning', _l('se_ig_blocked_' . $result['reason']) ?: _l('se_ig_reply_blocked'));
        }
        redirect(admin_url('se_instagram/se_instagram/conversation/' . (int) $id));
    }
}
