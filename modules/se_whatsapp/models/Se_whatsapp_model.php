<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Se_whatsapp_model extends App_Model
{
    /** Apply brand scoping unless the staff member sees all brands. */
    /** Apply brand scoping. Fails closed for a staff member with no brands. */
    private function scope($table_alias = '')
    {
        se_apply_scope_in(($table_alias ? $table_alias . '.' : '') . 'brand_id');
    }

    /** Conversations for the inbox, with the linked lead name where present. */
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

    /**
     * Assign a conversation to a staff member.
     *
     * The assignee must belong to the CONVERSATION's brand. Any staff id used
     * to be accepted, so a conversation could be handed to another tenant's
     * staff member, who would then see the thread on their inbox.
     *
     * @return bool true only when a row was actually assigned
     */
    public function assign($conversation_id, $staff_id)
    {
        $conversation = $this->get_conversation($conversation_id);

        if (!$conversation) {
            return false;   // out of scope
        }

        $staff_id = (int) $staff_id;

        if ($staff_id > 0 && !$this->staff_in_brand($staff_id, (int) $conversation->brand_id)) {
            return false;
        }

        $affected = se_guarded_update(db_prefix() . 'se_wa_conversations', 'id', (int) $conversation_id, [
            'assigned_staff' => $staff_id,
            'last_updated'   => date('Y-m-d H:i:s'),
        ]);

        return $affected > 0;
    }

    /**
     * Is this staff member mapped to the brand?
     *
     * A staff member with ZERO mapping rows used to pass unconditionally (the
     * empty result was read as "admin-like"), so a conversation could be
     * assigned to a completely unmapped staff member of another tenant. Only
     * an actual admin passes without a mapping now; everybody else needs a
     * real tblse_staff_brands row for THIS brand.
     */
    protected function staff_in_brand($staff_id, $brand_id)
    {
        if (is_admin((int) $staff_id)) {
            return true;   // admins reach every brand by definition
        }

        $rows = $this->db->query(
            'SELECT brand_id FROM ' . db_prefix() . 'se_staff_brands WHERE staff_id = ' . (int) $staff_id
        )->result_array();

        foreach ($rows as $row) {
            if ((int) $row['brand_id'] === (int) $brand_id) {
                return true;
            }
        }

        return false;
    }
}
