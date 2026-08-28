<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Se_brands_model extends App_Model
{
    public function get($id = '')
    {
        if (is_numeric($id)) {
            $this->db->where('id', $id);

            return $this->db->get(db_prefix() . 'se_brands')->row();
        }

        $this->db->order_by('name', 'ASC');

        return $this->db->get(db_prefix() . 'se_brands')->result_array();
    }

    public function add($data)
    {
        $staff = isset($data['staff']) && is_array($data['staff']) ? $data['staff'] : [];
        unset($data['staff']);

        $data = $this->prepare($data);
        $data['date_created'] = date('Y-m-d H:i:s');

        $this->db->insert(db_prefix() . 'se_brands', $data);
        $id = $this->db->insert_id();

        if ($id) {
            $this->sync_staff($id, $staff);
            log_activity('SE Brand Created [' . $id . ', ' . $data['name'] . ']');
        }

        return $id;
    }

    public function update($id, $data)
    {
        $staff = isset($data['staff']) && is_array($data['staff']) ? $data['staff'] : [];
        unset($data['staff']);

        $data = $this->prepare($data);
        $data['last_updated'] = date('Y-m-d H:i:s');

        $this->db->where('id', $id);
        $this->db->update(db_prefix() . 'se_brands', $data);

        $affected = $this->db->affected_rows();

        $this->sync_staff($id, $staff);

        if ($affected > 0) {
            log_activity('SE Brand Updated [' . $id . ']');
        }

        return true;
    }

    /**
     * Replaces the staff mapping for a brand.
     *
     * Deliberately a full replace rather than a diff: the mapping is the
     * tenancy boundary, and a partial update that silently leaves a stale
     * row grants access nobody intended.
     */
    public function sync_staff($brand_id, array $staff_ids)
    {
        $this->db->where('brand_id', (int) $brand_id);
        $this->db->delete(db_prefix() . 'se_staff_brands');

        foreach ($staff_ids as $staff_id) {
            $this->db->insert(db_prefix() . 'se_staff_brands', [
                'brand_id' => (int) $brand_id,
                'staff_id' => (int) $staff_id,
            ]);
        }
    }

    public function staff_ids($brand_id)
    {
        $this->db->select('staff_id')->where('brand_id', (int) $brand_id);

        return array_map(function ($row) {
            return (int) $row['staff_id'];
        }, $this->db->get(db_prefix() . 'se_staff_brands')->result_array());
    }

    protected function prepare($data)
    {
        $allowed = [
            'name', 'slug', 'active',
            'meta_page_id', 'meta_ad_account_id', 'meta_dataset_id',
            'whatsapp_waba_id', 'whatsapp_phone_number_id',
            'google_ads_customer_id', 'ga4_property_id', 'gsc_site_url',
        ];

        $clean = [];

        foreach ($allowed as $key) {
            if (isset($data[$key])) {
                $clean[$key] = trim($data[$key]);
            }
        }

        $clean['active'] = isset($data['active']) ? 1 : 0;

        if (empty($clean['slug']) && !empty($clean['name'])) {
            $clean['slug'] = substr(slug_it($clean['name']), 0, 64);
        }

        return $clean;
    }
}
