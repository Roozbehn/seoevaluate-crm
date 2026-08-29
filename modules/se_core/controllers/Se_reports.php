<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Brand-scoped reporting + integration-health screens.
 * The JSON endpoints compute internal metrics from CRM tables and read imported
 * external metrics from tblse_ext_metrics — NEVER an external HTTP call at render.
 */
class Se_reports extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        // Reporting is its own capability. It deliberately does NOT gate on
        // se_brands.view any more: that made every reporting user a global
        // tenant user, because se_brands.view also meant "see all brands".
        if (!se_staff_can_report()) {
            access_denied('se_reports');
        }
    }

    /**
     * Resolve + authorise the brand in scope.
     *
     * Defaults to the first brand THIS staff member can reach, not the first
     * brand that exists globally — the old default handed a restricted user a
     * foreign brand id and then relied on the access check to bounce them.
     *
     * An UNMAPPED ordinary staff member (no brand rows, not admin, no
     * se_tenancy capability) resolves to SE_BRAND_NONE via
     * se_default_brand_id(): a sentinel that matches no row, so data()/
     * health_data() return an honestly EMPTY no-brand state instead of the
     * brand-0 triage aggregates they used to leak. Staff who actually hold
     * se_tenancy.triage_unassigned / all_brands keep their brand-0 triage
     * default.
     */
    private function brand()
    {
        $b = (int) $this->input->get('brand');

        if ($b <= 0) {
            return se_default_brand_id();
        }

        if (!se_can_access_brand($b)) {
            ajax_access_denied();
        }

        return $b;
    }

    public function index()
    {
        $data['title'] = _l('se_reports');
        $data['brand'] = $this->brand();
        $this->load->view('se_core/se_reports_dashboard', $data);
    }

    public function health()
    {
        $data['title'] = _l('se_reports_health');
        $data['brand'] = $this->brand();
        $this->load->view('se_core/se_reports_health', $data);
    }

    /** JSON metrics for the dashboard (internal + stored external; no external calls). */
    public function data()
    {
        $b    = $this->brand();
        $from = $this->input->get('from') ?: null;
        $to   = $this->input->get('to') ?: null;

        header('Content-Type: application/json');
        echo json_encode([
            'brand_id'      => $b,
            'totals'        => se_report_totals($b, $from, $to),
            'by_stage'      => se_report_by_stage($b, $from, $to),
            'by_source'     => se_report_by_source($b, $from, $to),
            'appointments'  => se_report_appointments($b, $from, $to),
            'whatsapp'      => se_report_whatsapp($b),
            'spend_outcome' => se_report_spend_vs_outcome($b, $from, $to),
        ]);
    }

    /** JSON integration-health for the health page. */
    public function health_data()
    {
        header('Content-Type: application/json');
        echo json_encode(se_integration_health($this->brand()));
    }
}
