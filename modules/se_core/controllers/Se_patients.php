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
        // Scoped selectors: the form offers only records this staff member may
        // link, instead of a bare numeric input that invites a foreign id.
        $data['brands']  = se_all_brands(true, true);
        $data['leads']   = se_patient_selectable_leads();
        $data['clients'] = se_patient_selectable_clients();
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
        $data['brands']  = se_all_brands(true, true);
        $data['leads']   = se_patient_selectable_leads((int) $patient->brand_id);
        $data['clients'] = se_patient_selectable_clients((int) $patient->brand_id);
        $this->load->view('se_core/se_patients_form', $data);
    }

    public function save($id = '')
    {
        // Mutations are POST-only. AdminController enforces CSRF on POST; a GET
        // that reaches a writer bypasses that entirely.
        if ($this->input->method() !== 'post' || !$this->input->post()) {
            redirect(admin_url('se_core/se_patients'));
        }

        if (!$id) {
            if (staff_cant('create', 'se_patients')) {
                access_denied('se_patients');
            }

            $v = se_patient_validate($this->input->post());

            // se_patient_validate() already refuses a brand this staff member
            // cannot reach and any cross-brand lead/client link.
            if ($v['errors']) {
                set_alert('warning', _l('se_patient_invalid'));
                redirect(admin_url('se_core/se_patients/create'));
            }

            if (se_patient_link_conflict($v['clean']['brand_id'], $v['clean']['lead_id'], $v['clean']['client_id'])) {
                set_alert('warning', _l('se_patient_link_conflict'));
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

            // The record keeps its own brand. The posted brand_id is replaced
            // BEFORE validation so the link checks run against the authorized
            // brand rather than whatever the form claimed.
            $post = $this->input->post();
            $post['brand_id'] = (int) $existing->brand_id;

            $v = se_patient_validate($post);

            if ($v['errors']) {
                set_alert('warning', _l('se_patient_invalid'));
                redirect(admin_url('se_core/se_patients/edit/' . (int) $id));
            }

            if (se_patient_link_conflict($v['clean']['brand_id'], $v['clean']['lead_id'],
                                         $v['clean']['client_id'], (int) $id)) {
                set_alert('warning', _l('se_patient_link_conflict'));
                redirect(admin_url('se_core/se_patients/edit/' . (int) $id));
            }

            // Never overwrite a stored passport with a blank on an ordinary edit.
            if ($v['clean']['passport_no'] === null) {
                unset($v['clean']['passport_no']);
            }

            se_patient_update((int) $id, $v['clean']);
            set_alert('success', _l('se_patient_saved'));
        }

        redirect(admin_url('se_core/se_patients'));
    }

    /**
     * Archive a patient. POST-only + CSRF.
     *
     * This was a GET route, so any link, prefetch or crafted image could
     * archive a patient record with no token at all.
     */
    public function archive($id)
    {
        if ($this->input->method() !== 'post') {
            access_denied('se_patients');
        }

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

    /** Record a data-subject deletion request. POST-only + CSRF. */
    public function request_deletion($id)
    {
        if ($this->input->method() !== 'post') {
            access_denied('se_patients');
        }

        if (staff_cant('delete', 'se_patients')) {
            access_denied('se_patients');
        }

        $patient = se_patient_get($id);
        if (!$patient) {
            access_denied('se_patients');
        }

        se_patient_request_deletion((int) $id, (int) $patient->brand_id);
        set_alert('success', _l('se_patient_deletion_requested'));
        redirect(admin_url('se_core/se_patients/view/' . (int) $id));
    }
}
