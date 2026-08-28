<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Se_appointments extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('se_appointments/se_appointments_model');
    }

    public function index()
    {
        if (staff_cant('view', 'se_appointments')) {
            access_denied('se_appointments');
        }

        $data['title'] = _l('se_appointments');
        $this->load->view('se_appointments/calendar', $data);
    }

    /** JSON feed for the calendar. */
    public function feed()
    {
        if (staff_cant('view', 'se_appointments')) {
            ajax_access_denied();
        }

        $start = $this->input->get('start') ?: date('Y-m-01 00:00:00');
        $end   = $this->input->get('end') ?: date('Y-m-t 23:59:59');

        echo json_encode($this->se_appointments_model->for_calendar($start, $end));
    }

    public function manage()
    {
        if (staff_cant('view', 'se_appointments')) {
            access_denied('se_appointments');
        }

        $data['title']        = _l('se_appointments');
        $data['appointments'] = $this->se_appointments_model->get();
        $this->load->view('se_appointments/manage', $data);
    }

    public function view($id)
    {
        if (staff_cant('view', 'se_appointments')) {
            access_denied('se_appointments');
        }

        $appointment = $this->se_appointments_model->get($id);

        // Brand guard: a scoped model returns null for a foreign-brand id.
        if (!$appointment) {
            access_denied('se_appointments');
        }

        $data['title']       = $appointment->title;
        $data['appointment'] = $appointment;
        $this->load->view('se_appointments/view', $data);
    }

    public function save($id = '')
    {
        if (!$this->input->post()) {
            redirect(admin_url('se_appointments/manage'));
        }

        $data = $this->input->post();

        if (!$id) {
            if (staff_cant('create', 'se_appointments')) {
                access_denied('se_appointments');
            }
            $new = $this->se_appointments_model->add($data);
            if ($new) {
                set_alert('success', _l('se_appt_added'));
            }
        } else {
            if (staff_cant('edit', 'se_appointments')) {
                access_denied('se_appointments');
            }
            // Re-verify the id is in the staff member's scope before writing.
            if (!$this->se_appointments_model->get($id)) {
                access_denied('se_appointments');
            }
            if ($this->se_appointments_model->update($id, $data)) {
                set_alert('success', _l('se_appt_updated'));
            }
        }

        redirect(admin_url('se_appointments/manage'));
    }

    public function delete($id)
    {
        if (staff_cant('delete', 'se_appointments')) {
            access_denied('se_appointments');
        }
        if (!$this->se_appointments_model->get($id)) {
            access_denied('se_appointments');
        }
        if ($this->se_appointments_model->delete($id)) {
            set_alert('success', _l('deleted', _l('se_appointment')));
        }
        redirect(admin_url('se_appointments/manage'));
    }
}
