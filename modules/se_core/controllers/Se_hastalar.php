<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Hastalar — the single patient list (CRM-M024). Reads only. Rows are
 * journeys in the staff member's brands; Perfex Leads/Customers stay
 * reachable from Yönetim for admins.
 */
class Se_hastalar extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        if (staff_cant('view', 'se_journey') || !function_exists('se_hastalar_query')) {
            access_denied('se_hastalar');
        }
    }

    public function index()
    {
        $t0 = microtime(true);
        $data['title']     = _l('se_nav_hastalar');
        $data['has_brand'] = se_staff_has_any_brand();
        $data['f']         = se_hastalar_filters((array) $this->input->get());
        $data['result']    = $data['has_brand'] ? se_hastalar_query($data['f']) : ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'scanned' => 0, 'capped' => false];
        $data['stages']    = $data['has_brand'] ? se_journey_stage_counts() : [];
        $data['can_create_lead'] = staff_can('create', 'leads') || is_admin();
        $data['build_ms']  = (int) round((microtime(true) - $t0) * 1000);
        $this->load->view('se_core/se_hastalar', $data);
    }
}
