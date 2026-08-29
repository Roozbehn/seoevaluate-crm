<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Brand-scoped patient records (route: /admin/se_core/se_patients).
 * Reuses tblse_patients / se_consent_ledger / se_record_access_log. Every read
 * and write is brand-scoped; a foreign-brand id resolves to null and is denied,
 * even if a valid id is placed in the URL. CSRF is enforced by AdminController.
 * Archive (soft-delete) is preferred so consent + history remain available.
 */
class Se_patients extends AdminController
{
    public function index()
    {
        if (staff_cant('view', 'se_patients')) {
            access_denied('se_patients');
        }
        $search = $this->input->get('search') ? trim((string) $this->input->get('search')) : '';
        $page   = max(1, (int) $this->input->get('page'));
        $per    = 25;
        $filters = ['search' => $search, 'limit' => $per, 'offset' => ($page - 1) * $per,
                    'include_archived' => (bool) $this->input->get('archived')];

        $data['title']    = _l('se_patients');
        $data['patients'] = se_patient_list($filters);
        $data['total']    = se_patient_count($filters);
        $data['page']     = $page;
        $data['per']      = $per;
        $data['search']   = $search;
        $data['archived'] = (bool) $this->input->get('archived');
        $this->load->view('se_core/se_patients_list', $data);
    }

    public function view($id)
    {
        if (staff_cant('view', 'se_patients')) {
            access_denied('se_patients');
        }
        $patient = se_patient_get($id); // brand-scoped + logs access; null for foreign brand
        if (!$patient) {
            access_denied('se_patients');
        }
        $data['title']    = _l('se_patients');
        $data['patient']  = $patient;
        $data['links']    = se_patient_links($patient);
        $data['consent']  = se_patient_consent_history($patient);
        $data['audit']    = se_patient_audit_history($patient);
        $this->load->view('se_core/se_patients_view', $data);
    }

    public function create()
    {
        if (staff_cant('create', 'se_patients')) {
            access_denied('se_patients');
        }
        $data['title']   = _l('se_patients');
        $data['patient'] = null;
        $this->load->view('se_core/se_patients_form', $data);
    }

    public function edit($id)
    {
        if (staff_cant('edit', 'se_patients')) {
            access_denied('se_patients');
        }
        $patient = se_patient_get($id);
        if (!$patient) {
            access_denied('se_patients');
        }
        $data['title']   = _l('se_patients');
        $data['patient'] = $patient;
        $this->load->view('se_core/se_patients_form', $data);
    }

    public function save($id = '')
    {
        if (!$this->input->post()) {
            redirect(admin_url('se_core/se_patients'));
        }
        $v = se_patient_validate($this->input->post());

        if (!$id) {
            if (staff_cant('create', 'se_patients')) {
                access_denied('se_patients');
            }
            // Creating: the acting staff must be allowed on the target brand.
            if (function_exists('se_can_access_brand') && !se_can_access_brand($v['clean']['brand_id'])) {
                access_denied('se_patients');
            }
            if ($v['errors']) {
                set_alert('warning', _l('se_patient_invalid'));
                redirect(admin_url('se_core/se_patients/create'));
            }
            se_patient_create($v['clean']);
            set_alert('success', _l('se_patient_saved'));
        } else {
            if (staff_cant('edit', 'se_patients')) {
                access_denied('se_patients');
            }
            $existing = se_patient_get($id); // brand-scoped: foreign id -> null -> deny
            if (!$existing) {
                access_denied('se_patients');
            }
            // Keep the record on its own brand; ignore any brand change from the form.
            $v['clean']['brand_id'] = (int) $existing->brand_id;
            if ($v['errors']) {
                set_alert('warning', _l('se_patient_invalid'));
                redirect(admin_url('se_core/se_patients/edit/' . (int) $id));
            }
            se_patient_update((int) $id, $v['clean']);
            set_alert('success', _l('se_patient_saved'));
        }
        redirect(admin_url('se_core/se_patients'));
    }

    public function archive($id)
    {
        if (staff_cant('delete', 'se_patients')) {
            access_denied('se_patients');
        }
        $patient = se_patient_get($id);
        if (!$patient) {
            access_denied('se_patients');
        }
        se_patient_archive((int) $id, (int) $patient->brand_id);
        set_alert('success', _l('se_patient_archived'));
        redirect(admin_url('se_core/se_patients'));
    }
}
