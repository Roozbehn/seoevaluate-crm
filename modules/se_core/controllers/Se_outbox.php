<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Conversion outbox monitor.
 *
 * Read-mostly by design: an operator needs to see WHY a conversion has not
 * left the CRM, which is almost always a gate or a consent decision rather
 * than a bug. The only mutation is a guarded requeue.
 */
class Se_outbox extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        if (!se_staff_can_report() && !se_staff_can_configure_brands()) {
            access_denied('se_outbox');
        }
    }

    public function index()
    {
        $data['title']     = _l('se_outbox');
        $data['has_brand'] = se_staff_has_any_brand();

        if ($data['has_brand']) {
            $filters = [
                'brand'       => (int) $this->input->get('brand'),
                'destination' => (string) $this->input->get('destination'),
                'status'      => (string) $this->input->get('status'),
                'event'       => (string) $this->input->get('event'),
                'from'        => (string) $this->input->get('from'),
                'to'          => (string) $this->input->get('to'),
            ];

            $data['filters']  = $filters;
            $data['counters'] = se_outbox_status_counters($filters['brand']);
            $data['rows']     = se_outbox_browse($filters);
            $data['brands']   = se_all_brands(true, true);
        }

        $this->load->view('se_core/se_outbox', $data);
    }

    /** Safe detail for one row. Never renders raw PII, payload or a token. */
    public function detail($id)
    {
        if (!se_staff_can_report() && !se_staff_can_configure_brands()) {
            ajax_access_denied();
        }

        $row = se_outbox_row($id);

        if (!$row) {
            access_denied('se_outbox');
        }

        $data['title'] = _l('se_outbox');
        $data['row']   = $row;
        $data['safe']  = se_outbox_safe_detail($row);

        $this->load->view('se_core/se_outbox_detail', $data);
    }

    /**
     * Requeue an eligible row. POST-only + CSRF + brand-guarded.
     *
     * A consent-blocked row is NEVER requeueable: re-sending something the
     * data subject refused is the one mistake this screen must make impossible.
     */
    public function requeue($id)
    {
        if ($this->input->method() !== 'post') {
            access_denied('se_outbox');
        }

        if (!se_staff_can_configure_brands()) {
            access_denied('se_outbox');
        }

        $result = se_outbox_requeue((int) $id);

        set_alert($result['ok'] ? 'success' : 'warning', $result['message']);

        redirect(admin_url('se_core/se_outbox'));
    }
}
