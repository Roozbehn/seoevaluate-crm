<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Brands extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('se_core/se_brands_model');
    }

    public function index()
    {
        // Brand configuration capability. Distinct from cross-brand data reach.
        if (!se_staff_can_configure_brands()) {
            access_denied('se_brands');
        }

        $data['brands'] = $this->se_brands_model->get();
        $data['staff']  = $this->staff_model->get('', ['active' => 1]);
        $data['title']  = _l('se_brands');

        $this->load->view('se_core/manage', $data);
    }

    public function save($id = '')
    {
        // Configuration mutations are POST-only and CSRF-protected by Perfex's
        // global CSRF layer; a GET must never reach the model.
        if ($this->input->method() !== 'post') {
            access_denied('se_brands');
        }

        if ($this->input->post()) {
            $data = $this->input->post();

            if (!$id) {
                if (staff_cant('create', 'se_brands')) {
                    access_denied('se_brands');
                }

                $new_id = $this->se_brands_model->add($data);

                if ($new_id) {
                    set_alert('success', _l('se_brand_added'));
                }
            } else {
                if (staff_cant('edit', 'se_brands')) {
                    access_denied('se_brands');
                }

                if ($this->se_brands_model->update($id, $data)) {
                    set_alert('success', _l('se_brand_updated'));
                }
            }
        }

        redirect(admin_url('se_core/brands'));
    }
}
