<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * SE CRM dashboard — the landing screen that makes the rest discoverable.
 *
 * Every figure is brand-scoped through the same fail-closed predicate the rest
 * of the system uses, so a single-brand user never sees a global total.
 */
class Se_dashboard extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        if (!se_staff_can_report() && !se_staff_can_configure_brands()) {
            access_denied('se_dashboard');
        }
    }

    public function index()
    {
        $data['title'] = _l('se_group');

        // An ordinary staff member with no brand gets an explanation, not an
        // empty dashboard they cannot distinguish from "no data yet".
        $data['has_brand'] = se_staff_has_any_brand();

        if ($data['has_brand']) {
            $data['stats']    = se_dashboard_stats();
            $data['warnings'] = se_dashboard_warnings();
            $data['brands']   = se_all_brands(true, true);
        }

        $this->load->view('se_core/se_dashboard', $data);
    }
}
