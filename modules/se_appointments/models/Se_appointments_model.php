<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Se_appointments_model extends App_Model
{
    /** Statuses that count as a genuine consultation for conversion signalling. */
    const HELD_STATUSES = ['held', 'completed'];

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

        $data['date_created'] = date('Y-m-d H:i:s');

        $this->db->insert(db_prefix() . 'se_appointments', $data);
        $id = $this->db->insert_id();

        if ($id) {
            $this->signal_status($id, null, $data['status']);
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

        $data['last_updated'] = date('Y-m-d H:i:s');

        $this->db->where('id', $id)->update(db_prefix() . 'se_appointments', $data);
        $affected = $this->db->affected_rows();

        if ($before && isset($data['status'])) {
            $this->signal_status($id, $before->status, $data['status']);
        }

        if ($affected > 0) {
            log_activity('SE Appointment Updated [' . $id . ']');
        }

        return true;
    }

    public function delete($id)
    {
        $this->db->where('id', $id)->delete(db_prefix() . 'se_appointments');

        return $this->db->affected_rows() > 0;
    }

    /**
     * Emits conversion signals for appointment milestones.
     *
     * "Consultation Booked" and "Consultation Held" are two of the highest-value
     * signals in a health-tourism funnel, so appointment status changes feed the
     * same outbox the pipeline uses. The lead must have ad consent; the outbox
     * helper enforces dedup and destination selection.
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

    /**
     * Rejects an impossible time window: an end at or before the start, or an
     * unparseable start. Only enforced when the fields are supplied, so a
     * start-only appointment stays valid. Returns true when the window is bad.
     */
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

        $valid = ['scheduled', 'confirmed', 'held', 'completed', 'no_show', 'cancelled'];
        if (isset($clean['status']) && !in_array($clean['status'], $valid, true)) {
            $clean['status'] = 'scheduled';
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
