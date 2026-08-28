<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Se_appointments_model extends App_Model
{
    /** Statuses that count as a genuine consultation for conversion signalling. */
    const HELD_STATUSES = ['held', 'completed'];

    /** Valid appointment statuses. */
    const STATUSES = ['scheduled', 'confirmed', 'held', 'completed', 'no_show', 'cancelled'];

    public function get($id = '', $filters = [])
    {
        $this->db->select(
            db_prefix() . 'se_appointments.*, '
            . 'CONCAT(' . db_prefix() . 'staff.firstname, " ", ' . db_prefix() . 'staff.lastname) as staff_name'
        );
        $this->db->join(
            db_prefix() . 'staff',
            db_prefix() . 'staff.staffid = ' . db_prefix() . 'se_appointments.staff_id',
            'left'
        );

        // Brand scoping: staff see only their brands, plus the unassigned bucket.
        if (function_exists('se_staff_sees_all_brands') && !se_staff_sees_all_brands()) {
            $ids = se_staff_brand_ids();
            $this->db->where('(' . db_prefix() . 'se_appointments.brand_id IN (' . implode(',', array_map('intval', $ids)) . '))');
        }

        foreach ($filters as $col => $val) {
            $this->db->where(db_prefix() . 'se_appointments.' . $col, $val);
        }

        if (is_numeric($id)) {
            $this->db->where(db_prefix() . 'se_appointments.id', $id);

            return $this->db->get(db_prefix() . 'se_appointments')->row();
        }

        $this->db->order_by(db_prefix() . 'se_appointments.start_at', 'DESC');

        return $this->db->get(db_prefix() . 'se_appointments')->result_array();
    }

    public function add($data)
    {
        $data = $this->prepare($data);

        if ($this->invalid_window($data)) {
            return false;
        }
        if ($this->has_availability_conflict($data)) {
            return false;
        }

        $data['date_created'] = date('Y-m-d H:i:s');

        $this->db->insert(db_prefix() . 'se_appointments', $data);
        $id = $this->db->insert_id();

        if ($id) {
            $this->record_status_history($id, (int) $data['brand_id'], null, $data['status']);
            $this->signal_status($id, null, $data['status']);
            $this->on_schedule_changed($id);
            $this->sync_calendar($id, 'create');
            log_activity('SE Appointment Created [' . $id . ']');
        }

        return $id;
    }

    public function update($id, $data)
    {
        $before = $this->get($id);
        $data   = $this->prepare($data);

        if ($this->invalid_window($data)) {
            return false;
        }
        // Availability is only re-checked when the time or staff is being changed.
        if (isset($data['start_at']) && $this->has_availability_conflict($data, (int) $id)) {
            return false;
        }

        $data['last_updated'] = date('Y-m-d H:i:s');

        $this->db->where('id', $id)->update(db_prefix() . 'se_appointments', $data);
        $affected = $this->db->affected_rows();

        if ($before && isset($data['status'])) {
            if ($before->status !== $data['status']) {
                $this->record_status_history((int) $id, (int) $before->brand_id, $before->status, $data['status']);
            }
            $this->signal_status($id, $before->status, $data['status']);

            // Cancelled / no-show appointments should not fire reminders.
            if (in_array($data['status'], ['cancelled', 'no_show'], true) && function_exists('se_reminder_cancel_for_appointment')) {
                se_reminder_cancel_for_appointment((int) $id);
            }
        }

        // Reschedule -> refresh the reminder and the calendar event.
        if (isset($data['start_at']) && $before && $before->start_at !== $data['start_at']) {
            if (function_exists('se_reminder_cancel_for_appointment')) {
                se_reminder_cancel_for_appointment((int) $id);
            }
            $this->on_schedule_changed((int) $id);
        }

        if (isset($data['start_at']) || (isset($data['status']) && $before && $before->status !== $data['status'])) {
            $this->sync_calendar((int) $id, 'update');
        }

        if ($affected > 0) {
            log_activity('SE Appointment Updated [' . $id . ']');
        }

        return true;
    }

    /** Cancel with a reason. Returns true on success. */
    public function cancel($id, $reason = '')
    {
        return $this->update($id, ['status' => 'cancelled', 'cancellation_reason' => $reason]);
    }

    public function delete($id)
    {
        $this->sync_calendar((int) $id, 'cancel');
        if (function_exists('se_reminder_cancel_for_appointment')) {
            se_reminder_cancel_for_appointment((int) $id);
        }
        $this->db->where('id', $id)->delete(db_prefix() . 'se_appointments');

        return $this->db->affected_rows() > 0;
    }

    /** Full status timeline for an appointment. */
    public function status_history($appointment_id)
    {
        $this->db->where('appointment_id', (int) $appointment_id)->order_by('id', 'ASC');

        return $this->db->get(db_prefix() . 'se_appointment_status_history')->result_array();
    }

    protected function record_status_history($appointment_id, $brand_id, $old, $new)
    {
        $this->db->insert(db_prefix() . 'se_appointment_status_history', [
            'appointment_id' => (int) $appointment_id,
            'brand_id'       => (int) $brand_id,
            'old_status'     => $old,
            'new_status'     => $new,
            'changed_by'     => function_exists('get_staff_user_id') ? (int) get_staff_user_id() : 0,
            'changed_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    /** Queue the pre-appointment reminder for the current start time. */
    protected function on_schedule_changed($appointment_id)
    {
        if (!function_exists('se_reminder_enqueue')) {
            return;
        }
        $appt = $this->get($appointment_id);
        if (!$appt || in_array($appt->status, ['cancelled', 'no_show'], true)) {
            return;
        }
        $when = se_reminder_schedule_for($appt->start_at);
        if ($when) {
            se_reminder_enqueue((int) $appt->brand_id, (int) $appt->id, $when, 'appointment');
            $this->db->where('id', (int) $appt->id)->update(db_prefix() . 'se_appointments', ['reminder_queued' => 1]);
        }
    }

    /** Push the appointment to the configured calendar adapter (fixture by default). */
    protected function sync_calendar($appointment_id, $operation)
    {
        if (!function_exists('se_gcal_sync')) {
            return;
        }
        $appt = $this->get($appointment_id);
        if (!$appt) {
            return;
        }
        $result = se_gcal_sync((array) $appt, $operation);
        if (!empty($result['ok']) && array_key_exists('event_id', $result)) {
            $this->db->where('id', (int) $appointment_id)
                     ->update(db_prefix() . 'se_appointments', ['google_event_id' => $result['event_id']]);
        }
    }

    protected function has_availability_conflict($data, $ignore_id = 0)
    {
        if (empty($data['start_at']) || empty($data['staff_id'])) {
            return false;
        }
        $end = !empty($data['end_at']) ? $data['end_at'] : $data['start_at'];

        if (function_exists('se_appt_has_overlap')
            && se_appt_has_overlap((int) ($data['brand_id'] ?? 0), (int) $data['staff_id'], $data['start_at'], $end, $ignore_id)) {
            return true;
        }
        if (function_exists('se_appt_within_working_hours')
            && !se_appt_within_working_hours((int) ($data['brand_id'] ?? 0), (int) $data['staff_id'], $data['start_at'], $end)) {
            return true;
        }

        return false;
    }

    /**
     * Emits conversion signals for appointment milestones (Booked / Held). The
     * lead must have ad consent; the outbox helper enforces dedup + destinations.
     */
    protected function signal_status($appointment_id, $old_status, $new_status)
    {
        if (!function_exists('se_outbox_queue')) {
            return;
        }

        $appt = $this->get($appointment_id);
        if (!$appt || $appt->rel_type !== 'lead' || (int) $appt->rel_id === 0) {
            return;
        }

        $this->db->select('consent_ads')->where('id', (int) $appt->rel_id);
        $lead = $this->db->get(db_prefix() . 'leads')->row();
        if (!$lead || (int) $lead->consent_ads !== 1) {
            return;
        }

        $event = null;
        if ($old_status === null && $new_status === 'scheduled') {
            $event = 'Consultation Booked';
        } elseif (in_array($new_status, self::HELD_STATUSES, true)
                  && !in_array((string) $old_status, self::HELD_STATUSES, true)) {
            $event = 'Consultation Held';
        }

        if ($event === null) {
            return;
        }

        foreach (se_outbox_destinations_for_brand((int) $appt->brand_id) as $dest) {
            se_outbox_queue((int) $appt->brand_id, (int) $appt->rel_id, $dest, $event);
        }
    }

    protected function invalid_window($clean)
    {
        if (isset($clean['start_at']) && $clean['start_at'] !== '' && strtotime($clean['start_at']) === false) {
            return true;
        }

        if (empty($clean['start_at']) || empty($clean['end_at'])) {
            return false;
        }

        return strtotime($clean['end_at']) <= strtotime($clean['start_at']);
    }

    protected function prepare($data)
    {
        $allowed = [
            'brand_id', 'title', 'rel_type', 'rel_id', 'staff_id',
            'procedure_interest', 'start_at', 'end_at', 'status', 'location', 'notes',
            'appointment_type', 'consultation_format', 'cancellation_reason', 'staff_timezone',
        ];

        $clean = [];
        foreach ($allowed as $k) {
            if (isset($data[$k])) {
                $clean[$k] = $data[$k];
            }
        }

        foreach (['brand_id', 'rel_id', 'staff_id', 'procedure_interest'] as $intcol) {
            if (isset($clean[$intcol])) {
                $clean[$intcol] = (int) $clean[$intcol];
            }
        }

        if (!empty($clean['start_at'])) {
            $clean['start_at'] = to_sql_date($clean['start_at'], true);
        }
        if (!empty($clean['end_at'])) {
            $clean['end_at'] = to_sql_date($clean['end_at'], true);
        }

        if (isset($clean['status']) && !in_array($clean['status'], self::STATUSES, true)) {
            $clean['status'] = 'scheduled';
        }
        if (isset($clean['consultation_format']) && !in_array($clean['consultation_format'], ['online', 'in_person'], true)) {
            $clean['consultation_format'] = 'in_person';
        }

        return $clean;
    }

    /** Calendar feed for a date range, as FullCalendar-shaped events. */
    public function for_calendar($start, $end)
    {
        $rows = $this->get('', []);

        $colors = [
            'scheduled' => '#4c84ff', 'confirmed' => '#03a9f4', 'held' => '#84c529',
            'completed' => '#37bc9b', 'no_show' => '#fc2d42', 'cancelled' => '#b0bec5',
        ];

        $events = [];
        foreach ($rows as $r) {
            if ($r['start_at'] < $start || $r['start_at'] > $end) {
                continue;
            }
            $events[] = [
                'id'    => $r['id'],
                'title' => $r['title'],
                'start' => $r['start_at'],
                'end'   => $r['end_at'],
                'color' => $colors[$r['status']] ?? '#4c84ff',
                'url'   => admin_url('se_appointments/view/' . $r['id']),
            ];
        }

        return $events;
    }
}
