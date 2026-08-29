<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Consent Settings.
 *
 * Configures the wording and version that the web-to-lead forms render and
 * that the ledger files. There are no secret fields here and no way to
 * pre-check a box.
 */
class Se_consent extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        if (!se_staff_can_configure_brands()) {
            access_denied('se_consent');
        }
    }

    public function index()
    {
        $brand = (int) $this->input->get('brand');

        if ($brand > 0 && !se_can_access_brand($brand)) {
            access_denied('se_consent');
        }

        $data['title']     = _l('se_consent_settings');
        $data['brand']     = $brand;
        $data['brands']    = se_all_brands(false, true);
        $data['config']    = se_consent_config($brand);
        $data['languages'] = se_consent_languages();
        $data['purposes']  = se_consent_configurable_purposes();
        $data['configured'] = [];

        foreach ($data['purposes'] as $p) {
            $data['configured'][$p] = se_consent_text_configured($brand, $p);
        }

        $this->load->view('se_core/se_consent', $data);
    }

    /** Save configuration. POST-only + CSRF. */
    public function save()
    {
        if ($this->input->method() !== 'post') {
            access_denied('se_consent');
        }

        if (!se_staff_can_configure_brands()) {
            access_denied('se_consent');
        }

        $brand = (int) $this->input->post('brand_id');

        if ($brand > 0 && !se_can_access_brand($brand)) {
            access_denied('se_consent');
        }

        $result = se_consent_save_config($brand, $this->input->post(), (int) get_staff_user_id());

        if ($result['ok']) {
            set_alert('success', _l('se_consent_saved'));
        } else {
            set_alert('warning', _l('se_consent_invalid') . ' (' . implode(', ', $result['errors']) . ')');
        }

        redirect(admin_url('se_core/se_consent?brand=' . $brand));
    }
}
