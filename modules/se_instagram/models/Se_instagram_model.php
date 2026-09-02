<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Se_instagram_model extends App_Model
{
    private function scope() { se_apply_scope_in('brand_id'); }

    public function conversations($filters = [])
    {
        $this->scope();
        if (!empty($filters['assigned']) && $filters['assigned'] === 'me') {
            $this->db->where('assigned_staff', get_staff_user_id());
        } elseif (isset($filters['assigned']) && $filters['assigned'] === 'none') {
            $this->db->where('assigned_staff', 0);
        }
        $this->db->order_by('last_inbound_at', 'DESC');

        return $this->db->get(db_prefix() . 'se_ig_conversations')->result_array();
    }

    public function get_conversation($id)
    {
        $this->db->where('id', (int) $id);
        $this->scope();

        return $this->db->get(db_prefix() . 'se_ig_conversations')->row();
    }

    public function messages($conversation_id)
    {
        $this->db->where('conversation_id', (int) $conversation_id);
        $this->scope();
        $this->db->order_by('id', 'ASC');

        return $this->db->get(db_prefix() . 'se_ig_messages')->result_array();
    }

    /** Assign within the conversation's brand only. */
    public function assign($conversation_id, $staff_id)
    {
        $conversation = $this->get_conversation($conversation_id);
        if (!$conversation) {
            return false;
        }
        $staff_id = (int) $staff_id;
        if ($staff_id > 0 && !$this->staff_in_brand($staff_id, (int) $conversation->brand_id)) {
            return false;
        }

        return se_guarded_update(db_prefix() . 'se_ig_conversations', 'id', (int) $conversation_id, [
            'assigned_staff' => $staff_id, 'last_updated' => date('Y-m-d H:i:s'),
        ]) > 0;
    }

    protected function staff_in_brand($staff_id, $brand_id)
    {
        if (is_admin((int) $staff_id)) {
            return true;
        }
        $rows = $this->db->query('SELECT brand_id FROM ' . db_prefix() . 'se_staff_brands WHERE staff_id = ' . (int) $staff_id)->result_array();
        foreach ($rows as $row) {
            if ((int) $row['brand_id'] === (int) $brand_id) { return true; }
        }

        return false;
    }
}
