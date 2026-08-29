<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Meta Lead Ads configuration and queue.
 *
 * There is NO token input field anywhere on this screen. Tokens are installed
 * on disk by the owner (see Integration Credentials); this screen reports
 * whether that worked and what the queue is doing.
 */
class Se_meta extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        if (!se_staff_can_configure_brands()) {
            access_denied('se_meta');
        }
    }

    public function index()
    {
        $brand = se_requested_brand_or_default($this->input->get('brand'));

        if ($brand > 0 && !se_can_access_brand($brand)) {
            access_denied('se_meta');
        }

        $data['title']      = _l('se_meta_leadgen');
        $data['brand']      = $brand;
        $data['brands']     = se_all_brands(false, true);
        $data['status']     = se_meta_ui_status($brand);
        $data['counters']   = se_meta_ui_counters($brand);
        $data['forms']      = se_meta_ui_forms($brand);
        $data['events']     = se_meta_ui_events($brand, (string) $this->input->get('state'));
        $data['state']      = (string) $this->input->get('state');
        $data['allowed_columns'] = se_leadgen_allowed_lead_columns();
        $data['statuses']   = se_meta_ui_lead_statuses();
        $data['sources']    = se_meta_ui_lead_sources();

        $this->load->view('se_core/se_meta', $data);
    }

    /** Requeue an eligible leadgen event. POST-only + CSRF + brand-guarded. */
    public function requeue($id)
    {
        if ($this->input->method() !== 'post') {
            access_denied('se_meta');
        }

        $result = se_meta_ui_requeue((int) $id);
        set_alert($result['ok'] ? 'success' : 'warning', $result['message']);

        redirect(admin_url('se_core/se_meta'));
    }

    /** Run a safe diagnostic action. POST-only + CSRF + configure-gated. */
    public function diag($action = '')
    {
        if ($this->input->method() !== 'post' || !se_staff_can_configure_brands()) {
            access_denied('se_meta');
        }

        $brand = se_requested_brand_or_default($this->input->post('brand'));
        $safe  = ['recheck', 'credential', 'verify_readiness', 'reconcile'];

        if (!in_array($action, $safe, true)) {
            access_denied('se_meta');
        }

        $result = se_meta_ui_diag($action, (int) $brand);
        set_alert($result['ok'] ? 'success' : 'warning', $result['message']);

        redirect(admin_url('se_core/se_meta' . ((int) $brand > 0 ? '?brand=' . (int) $brand : '')));
    }

    /** Save the per-brand defaults (lead status/source). POST-only + CSRF. */
    public function save_defaults()
    {
        if ($this->input->method() !== 'post') {
            access_denied('se_meta');
        }

        $brand = (int) $this->input->post('brand_id');

        if ($brand <= 0 || !se_can_access_brand($brand)) {
            access_denied('se_meta');
        }

        se_meta_ui_save_defaults($brand, (int) $this->input->post('lead_status'), (int) $this->input->post('lead_source'));
        set_alert('success', _l('se_meta_defaults_saved'));

        redirect(admin_url('se_core/se_meta?brand=' . $brand));
    }
}
