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
        // Perfex ships FullCalendar v5 under assets/plugins/fullcalendar; the
        // view used to assume the jQuery v3 plugin and silently rendered nothing.
        add_calendar_assets();
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

        $data['title']     = _l('se_appointments');
        $data['has_brand'] = se_staff_has_any_brand();

        $filters = [];

        foreach (['brand_id' => 'brand', 'staff_id' => 'staff', 'status' => 'status'] as $col => $param) {
            $v = $this->input->get($param);

            if ($v !== null && $v !== '') {
                // A brand filter must still be one the caller may reach.
                if ($col === 'brand_id' && !se_can_access_brand((int) $v)) {
                    continue;
                }
                $filters[$col] = $col === 'status' ? (string) $v : (int) $v;
            }
        }

        $data['appointments'] = $data['has_brand'] ? $this->se_appointments_model->get('', $filters) : [];
        $data['names']        = $data['appointments'] ? $this->se_appointments_model->patient_names($data['appointments']) : [];
        $data['brands']       = se_all_brands(true, true);
        $data['staff']        = se_appt_selectable_staff();
        $data['statuses']     = Se_appointments_model::STATUSES;
        $data['filters']      = [
            'brand'  => $this->input->get('brand'),
            'staff'  => $this->input->get('staff'),
            'status' => $this->input->get('status'),
        ];

        $this->load->view('se_appointments/manage', $data);
    }

    /** Create form. */
    public function create()
    {
        if (staff_cant('create', 'se_appointments')) {
            access_denied('se_appointments');
        }

        $data['title']       = _l('se_appt_new');
        $data['appointment'] = null;
        $data['prefill']     = $this->prefill((array) $this->input->get());
        $this->form_data($data, (int) ($data['prefill']['brand_id'] ?? 0));

        $this->load->view('se_appointments/form', $data);
    }

    /**
     * Prefill for the create form (CRM-M039/M041): ?lead=, ?journey=, ?type=,
     * ?start=, and ?from=<appointment id> for the same-day procedure shortcut
     * (copies patient, staff, brand, location; starts when the source ends).
     */
    private function prefill(array $g)
    {
        $src = !empty($g['from']) ? $this->se_appointments_model->get((int) $g['from']) : null;   // brand-scoped lookup

        return se_appt_prefill_from($g, $src ?: null);
    }

    /** end_at from date + time + duration when the form did not post an explicit end. */
    private function normalise_post(array $data)
    {
        if (!empty($data['date']) && !empty($data['time'])) {
            $data['start_at'] = trim($data['date']) . ' ' . trim($data['time']) . (strlen(trim($data['time'])) === 5 ? ':00' : '');
        }
        if (empty($data['end_at']) && !empty($data['start_at']) && !empty($data['duration']) && strtotime($data['start_at'])) {
            $data['end_at'] = date('Y-m-d H:i:s', strtotime($data['start_at']) + max(5, (int) $data['duration']) * 60);
        }
        if (empty($data['title'])) {
            $data['title'] = se_appt_type_label($data['appointment_type'] ?? '');
        }
        unset($data['date'], $data['time'], $data['duration']);

        return $data;
    }

    /** Edit form. Brand-guarded: a foreign id resolves to null and is denied. */
    public function edit($id)
    {
        if (staff_cant('edit', 'se_appointments')) {
            access_denied('se_appointments');
        }

        $appointment = $this->se_appointments_model->get($id);

        if (!$appointment) {
            access_denied('se_appointments');
        }

        $data['title']       = _l('edit');
        $data['appointment'] = $appointment;
        $data['prefill']     = [];
        $this->form_data($data, (int) $appointment->brand_id);

        $this->load->view('se_appointments/form', $data);
    }

    /** Selector data shared by create and edit, scoped to what the caller may link. */
    private function form_data(&$data, $brand_id = 0)
    {
        $data['brands']   = se_all_brands(true, true);
        $data['staff']    = se_appt_selectable_staff($brand_id);
        $data['statuses'] = Se_appointments_model::STATUSES;
        $data['formats']  = ['in_person', 'online'];
        $data['leads']    = se_patient_selectable_leads($brand_id);
        $data['clients']  = se_patient_selectable_clients($brand_id);
        $data['timezones'] = timezone_identifiers_list();
    }

    /**
     * Change status directly from the list or detail view.
     * POST-only + CSRF; the model applies the brand predicate.
     */
    public function status($id)
    {
        if ($this->input->method() !== 'post') {
            access_denied('se_appointments');
        }

        if (staff_cant('edit', 'se_appointments')) {
            access_denied('se_appointments');
        }

        $status = (string) $this->input->post('status');

        if (!in_array($status, Se_appointments_model::STATUSES, true)) {
            access_denied('se_appointments');
        }

        if (!$this->se_appointments_model->get($id)) {
            access_denied('se_appointments');
        }

        if ($this->se_appointments_model->update($id, ['status' => $status])) {
            set_alert('success', _l('se_appt_updated'));
        } else {
            set_alert('warning', _l('se_appt_invalid_window'));
        }

        redirect(admin_url('se_appointments/se_appointments/view/' . (int) $id));
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
        $data['history']     = $this->se_appointments_model->status_history((int) $id);
        $names = $this->se_appointments_model->patient_names([(array) $appointment]);
        $data['patient']     = $names[$appointment->rel_type . ':' . (int) $appointment->rel_id] ?? '';
        $data['statuses']    = Se_appointments_model::STATUSES;
        $this->load->view('se_appointments/view', $data);
    }

    public function save($id = '')
    {
        // POST-only: AdminController enforces CSRF on POST, and a GET that
        // reaches a writer bypasses that entirely.
        if ($this->input->method() !== 'post' || !$this->input->post()) {
            redirect(admin_url('se_appointments/manage'));
        }

        $data = $this->normalise_post($this->input->post());
        $journey = (int) $this->input->post('journey_id');
        $back = (string) $this->input->post('back');

        if (!$id) {
            if (staff_cant('create', 'se_appointments')) {
                access_denied('se_appointments');
            }
            $new = $this->se_appointments_model->add($data);
            if ($new) {
                set_alert('success', _l('se_appt_added'));
                if ($journey > 0 && function_exists('se_journey_get') && function_exists('se_journey_link_appointment')) {
                    $j = se_journey_get($journey);
                    if ($j) { se_journey_link_appointment($j, (int) $new, (int) get_staff_user_id()); }
                }
                redirect($this->after_save($back, (int) $new));
            }
            $this->form_error($data, null);
            return;
        }

        if (staff_cant('edit', 'se_appointments')) {
            access_denied('se_appointments');
        }
        // Re-verify the id is in the staff member's scope before writing.
        $before = $this->se_appointments_model->get($id);
        if (!$before) {
            access_denied('se_appointments');
        }
        if ($this->se_appointments_model->update($id, $data)) {
            set_alert('success', _l('se_appt_updated'));
            redirect($this->after_save($back, (int) $id));
        }
        $this->form_error($data, $before);
    }

    /** Same-site return target after a save (patient page, calendar…), else the appointment. */
    private function after_save($back, $id)
    {
        if ($back !== '' && strpos($back, admin_url()) === 0 && strpos($back, "\n") === false) {
            return $back;
        }

        return admin_url('se_appointments/se_appointments/view/' . (int) $id);
    }

    /** Re-render the form with the exact refusal (UX-COPY §5) and the posted values kept. */
    private function form_error(array $posted, $appointment)
    {
        $msg = $this->se_appointments_model->last_message !== '' ? $this->se_appointments_model->last_message : _l('se_appt_invalid_window');
        $data['title']       = $appointment ? _l('edit') : _l('se_appt_new');
        $data['appointment'] = $appointment;
        $data['prefill']     = $posted;
        $data['error']       = $msg;
        $data['error_kind']  = $this->se_appointments_model->last_reason;
        $this->form_data($data, (int) ($appointment ? $appointment->brand_id : ($posted['brand_id'] ?? 0)));
        $this->load->view('se_appointments/form', $data);
    }

    /** Delete an appointment. POST-only + CSRF (this was a GET route). */
    public function delete($id)
    {
        if ($this->input->method() !== 'post') {
            access_denied('se_appointments');
        }

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
