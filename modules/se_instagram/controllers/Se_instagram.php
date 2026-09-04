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
        $this->render_inbox((int) $this->input->get('c'));
    }

    /** Deep link to one thread (Bugün, notifications). Same page, thread selected. */
    public function conversation($id)
    {
        if (staff_cant('view', 'se_instagram')) {
            access_denied('se_instagram');
        }
        $this->render_inbox((int) $id);
    }

    /** Mesajlar · Instagram — the same list | thread | context page as WhatsApp. */
    private function render_inbox($selected)
    {
        $t0 = microtime(true);
        $data['title']      = _l('se_nav_messages') . ' · Instagram';
        $data['has_brand']  = se_staff_has_any_brand();
        $data['f']          = se_ig_inbox_filters((array) $this->input->get());
        $data['list']       = $data['has_brand'] ? se_ig_inbox_rows($data['f']) : ['rows' => [], 'has_more' => false, 'next_before' => '', 'counts' => ['unread' => 0]];
        $data['out_health'] = se_ig_out_health();
        $data['blocked']    = $data['has_brand'] ? se_ig_inbox_blocked_reason(array_map(function ($r) { return ['brand_id' => $r['brand_id']]; }, $data['list']['rows'])) : '';
        $data['staff']      = function_exists('se_appt_selectable_staff') ? se_appt_selectable_staff() : [];
        $data['selected']   = $selected;
        $data['conversation'] = null;
        $data['evidence_redacted'] = $this->input->get('evidence') === 'redacted';

        if ($selected > 0) {
            // Brand guard: a scoped lookup returns null for a foreign-brand conversation.
            $conversation = $this->se_instagram_model->get_conversation($selected);
            if (!$conversation) {
                access_denied('se_instagram');
            }
            // Viewing marks the thread read INSIDE the CRM only (no read receipt sent).
            if ((int) $conversation->unread_count > 0) {
                $this->db->where('id', (int) $conversation->id)->where('brand_id', (int) $conversation->brand_id)
                         ->update(db_prefix() . 'se_ig_conversations', ['unread_count' => 0]);
                $conversation->unread_count = 0;
                foreach ($data['list']['rows'] as &$r) { if ($r['id'] === (int) $conversation->id) { $r['unread'] = 0; } }
                unset($r);
            }
            $data['conversation'] = $conversation;
            $page = se_ig_thread_page((int) $conversation->id, (int) $this->input->get('before'));
            $data['messages']     = $page['messages'];
            $data['older_before'] = $page['older_before'];
            $data['media']        = function_exists('se_media_for_messages')
                ? se_media_for_messages('ig', array_column($data['messages'], 'id')) : [];
            $data['policy']       = se_ig_compose_policy($conversation);
            $data['queued']       = se_ig_out_health((int) $conversation->brand_id);
            $data['tracker']      = function_exists('se_outbound_rows')
                ? se_outbound_rows('se_ig_outbound', (int) $conversation->id, 'mid') : [];
            $data['dispatch_eta'] = function_exists('se_outbound_dispatch_eta') ? se_outbound_dispatch_eta() : null;
            $data['journey']      = se_ig_journey_for($conversation);
            $data['ctx_html']     = function_exists('se_journey_conversation_context')
                ? se_journey_conversation_context($conversation, ['journey' => $data['journey'], 'channel' => 'ig']) : '';
            $data['row']          = null;
            foreach ($data['list']['rows'] as $r) { if ($r['id'] === (int) $conversation->id) { $data['row'] = $r; break; } }
            $data['back_url']     = admin_url('se_instagram/se_instagram/inbox' . ($data['f']['f'] !== 'all' ? '?f=' . $data['f']['f'] : ''));
        }
        $data['build_ms'] = (int) round((microtime(true) - $t0) * 1000);
        $this->load->view('se_instagram/inbox', $data);
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
        redirect(admin_url('se_instagram/se_instagram/inbox?c=' . (int) $id));
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

        $conversation = $this->se_instagram_model->get_conversation($id);
        $staff = (int) get_staff_user_id();
        $body  = trim((string) $this->input->post('body'));

        // Instagram attachments carry no caption: the file goes as its own
        // message and any text follows as a second one. File first, so a
        // rejected upload queues nothing.
        $result = null;
        if (!empty($_FILES['attachment']) && (int) $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $up = se_media_store_upload('ig', (int) $conversation->brand_id, $_FILES['attachment'], $staff);
            if (!$up['ok']) {
                set_alert('warning', _l('se_media_upload_' . $up['error']) ?: _l('se_media_upload_failed'));
                redirect(admin_url('se_instagram/se_instagram/inbox?c=' . (int) $id));
                return;
            }
            $result = se_ig_queue_message((int) $id, ['kind' => 'media', 'media_id' => (int) $up['id']], $staff);
        }
        if ($body !== '' && ($result === null || $result['ok'])) {
            $result = se_ig_queue_message((int) $id, ['body' => $body], $staff);
        }
        if ($result === null) {
            $result = ['ok' => false, 'reason' => 'empty_body'];
        }

        if ($result['ok']) {
            set_alert('success', _l('se_ig_reply_queued'));
        } else {
            set_alert('warning', _l('se_ig_blocked_' . $result['reason']) ?: _l('se_ig_reply_blocked'));
        }
        redirect(admin_url('se_instagram/se_instagram/inbox?c=' . (int) $id));
    }
}
