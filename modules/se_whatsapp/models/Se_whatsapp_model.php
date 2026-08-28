<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Se_whatsapp_model extends App_Model
{
    /** Apply brand scoping unless the staff member sees all brands. */
    private function scope($table_alias = '')
    {
        $col = ($table_alias ? $table_alias . '.' : '') . 'brand_id';
        if (function_exists('se_staff_sees_all_brands') && !se_staff_sees_all_brands()) {
            $ids = se_staff_brand_ids();
            $this->db->where('(' . $col . ' IN (' . implode(',', array_map('intval', $ids)) . '))');
        }
    }

    public function conversations($filters = [])
    {
        $this->scope();
        if (!empty($filters['assigned']) && $filters['assigned'] === 'me') {
            $this->db->where('assigned_staff', get_staff_user_id());
        } elseif (isset($filters['assigned']) && $filters['assigned'] === 'unassigned') {
            $this->db->where('assigned_staff', 0);
        }
        $this->db->order_by('last_inbound_at', 'DESC');
        return $this->db->get(db_prefix() . 'se_wa_conversations')->result_array();
    }

    public function get_conversation($id)
    {
        $this->db->where('id', (int) $id);
        $this->scope();
        return $this->db->get(db_prefix() . 'se_wa_conversations')->row();
    }

    public function messages($conversation_id)
    {
        $this->db->where('conversation_id', (int) $conversation_id);
        $this->scope();
        $this->db->order_by('id', 'ASC');
        return $this->db->get(db_prefix() . 'se_wa_messages')->result_array();
    }

    public function assign($conversation_id, $staff_id)
    {
        $this->db->where('id', (int) $conversation_id);
        $this->scope();
        $this->db->update(db_prefix() . 'se_wa_conversations', [
            'assigned_staff' => (int) $staff_id,
            'last_updated'   => date('Y-m-d H:i:s'),
        ]);
        return $this->db->affected_rows() >= 0;
    }
}
