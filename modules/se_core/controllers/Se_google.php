<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Google Data Manager status and conversion-action mapping.
 *
 * Read/configure only. No credential field, and nothing here can make a live
 * Google request.
 */
class Se_google extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        if (!se_staff_can_configure_brands()) {
            access_denied('se_google');
        }
    }

    public function index()
    {
        $brand = se_requested_brand_or_default($this->input->get('brand'));

        if ($brand > 0 && !se_can_access_brand($brand)) {
            access_denied('se_google');
        }

        $data['title']    = _l('se_google_dm');
        $data['brand']    = $brand;
        $data['brands']   = se_all_brands(false, true);
        $data['status']   = se_google_ui_status($brand);
        $data['counters'] = se_google_ui_counters($brand);
        $data['requests'] = se_google_ui_requests($brand);
        $data['mappings'] = se_google_ui_mappings($brand);
        $data['stages']   = se_pipeline_stages();

        $this->load->view('se_core/se_google', $data);
    }

    /** Save a conversion-action mapping. POST-only + CSRF + brand-guarded. */
    public function save_mapping()
    {
        if ($this->input->method() !== 'post') {
            access_denied('se_google');
        }

        $brand = (int) $this->input->post('brand_id');

        if ($brand <= 0 || !se_can_access_brand($brand)) {
            access_denied('se_google');
        }

        se_google_ui_save_mapping($brand, (string) $this->input->post('stage'), (string) $this->input->post('action_id'));
        set_alert('success', _l('se_google_mapping_saved'));

        redirect(admin_url('se_core/se_google?brand=' . $brand));
    }
}
