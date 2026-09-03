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

        // Brand scoping, fail closed: an unmapped staff member gets 1=0 rather
        // than the `IN ()` syntax error the old inline implode produced.
        se_apply_scope_in(db_prefix() . 'se_appointments.brand_id');

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

    /**
     * One row the model itself just wrote, read by id WITHOUT staff scope.
     *
     * The post-write hooks (status history signal, reminder queue, calendar
     * sync) used get(), which applies the staff brand scope. On a request with
     * no staff session — the patient's booking page, the dispatcher — that
     * scope resolves through Perfex's is_admin(), which runs its own query on
     * the SHARED query builder while get() has select()/join() half built:
     * the polluted statement threw, the request died with a 500 AFTER the row
     * was inserted, and the caller never learned the id. Even without the
     * exception the scope would have hidden the row (1=0) and silently skipped
     * the milestone, the reminder and the calendar event. The hooks act on a
     * row this model owns; they need no scope.
     */
    protected function row($id)
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
        $this->db->where(db_prefix() . 'se_appointments.id', (int) $id);

        return $this->db->get(db_prefix() . 'se_appointments')->row();
    }

    /**
     * @param array $opts 'system' => true when the caller is NOT a staff request
     *                    (the patient journey booking a slot from a token page,
     *                    the cron): the brand was resolved by the caller from
     *                    its own record, and there is no staff session to scope
     *                    by. Availability, working hours and the slot lock still
     *                    apply in full.
     */
    public function add($data, array $opts = [])
    {
        $data = $this->prepare($data);

        // The posted brand_id was previously trusted verbatim, so a crafted POST
        // could create an appointment inside another tenant.
        if (empty($opts['system']) && !se_can_access_brand((int) ($data['brand_id'] ?? 0))) {
            return false;
        }
        if (!empty($opts['system']) && (int) ($data['brand_id'] ?? 0) <= 0) {
            return false;   // a system booking is always for a known brand
        }

        if ($this->missing_required($data)) {
            return false;
        }

        if ($this->invalid_window($data)) {
            return false;
        }

        if ($this->invalid_links($data)) {
            return false;
        }

        // Serialise the check-then-insert. Two concurrent requests both passed
        // the availability check and both inserted, producing a double booking
        // that neither request could see coming.
        $lock = $this->acquire_slot_lock($data);

        if ($lock === false) {
            return false;   // could not take the lock: refuse, do not guess
        }

        try {
            if ($this->has_availability_conflict($data)) {
                return false;
            }

            $data['date_created'] = date('Y-m-d H:i:s');

            $this->db->insert(db_prefix() . 'se_appointments', $data);
            $id = $this->db->insert_id();
        } finally {
            $this->release_slot_lock($lock);
        }

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

        if (!$before) {
            return false;   // out of scope, or gone
        }

        $data = $this->prepare($data);

        // An appointment does not change tenant. Moving one is not a supported
        // operation, so a posted brand_id is dropped rather than honoured; the
        // record keeps the brand it already has.
        unset($data['brand_id']);

        /* ---- MERGE STORED STATE BEFORE VALIDATING ANYTHING ----------------
         * Every rule below must see the record as it WILL BE, not the handful
         * of fields this request happened to send. Validating $data alone gave
         * two clean bypasses:
         *
         *   POST {rel_id: <foreign lead>}   - no rel_type in $data, so
         *     invalid_links()'s `!empty($clean['rel_type'])` guard was false
         *     and the entire link check was skipped.
         *   POST {end_at: <before stored start>} - no start_at in $data, so
         *     invalid_window() hit `empty($clean['start_at'])` and returned
         *     "valid", writing an appointment that ends before it begins.
         */
        $merged = array_merge([
            'brand_id' => (int) $before->brand_id,
            'rel_type' => $before->rel_type,
            'rel_id'   => (int) $before->rel_id,
            'staff_id' => (int) $before->staff_id,
            'start_at' => $before->start_at,
            'end_at'   => $before->end_at,
            'title'    => $before->title,
            'status'   => $before->status,
        ], $data);

        if ($this->invalid_window($merged)) {
            return false;
        }

        if ($this->invalid_links($merged)) {
            return false;
        }

        // Availability is re-checked whenever ANYTHING that determines the slot
        // changes - start, end, or assigned staff. Checking only start_at let a
        // staff reassignment or an extended end time create an overlap.
        $slotChanged = isset($data['start_at']) || isset($data['end_at']) || isset($data['staff_id']);

        if ($slotChanged) {
            $lock = $this->acquire_slot_lock($merged);

            // A lock we could not take is not a lock. Refuse rather than run
            // the check-then-write unprotected and risk a double booking.
            if ($lock === false) {
                return false;
            }

            try {
                if ($this->has_availability_conflict($merged, (int) $id)) {
                    return false;
                }
            } finally {
                $this->release_slot_lock($lock);
            }
        }

        $data['last_updated'] = date('Y-m-d H:i:s');

        // Brand is in the SQL predicate and the affected count is checked, so a
        // caller that skipped its own authorization still writes nothing.
        $affected = se_guarded_update(db_prefix() . 'se_appointments', 'id', (int) $id, $data);

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
        // Authorize BEFORE the side effects: cancelling a calendar event and
        // dropping reminders for a record the caller may not touch is itself a
        // cross-tenant write.
        if (!$this->get($id)) {
            return false;
        }

        $this->sync_calendar((int) $id, 'cancel');

        if (function_exists('se_reminder_cancel_for_appointment')) {
            se_reminder_cancel_for_appointment((int) $id);
        }

        return se_guarded_delete(db_prefix() . 'se_appointments', 'id', (int) $id) > 0;
    }

    /** Full status timeline for an appointment. */
    public function status_history($appointment_id)
    {
        // Scoped: the history table carries brand_id, and reading another
        // tenant's status timeline leaks who was seen and when.
        $predicate = se_brand_predicate();

        $this->db->where('appointment_id', (int) $appointment_id);

        if ($predicate !== '') {
            $this->db->where($predicate, null, false);
        }

        $this->db->order_by('id', 'ASC');

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
        $appt = $this->row($appointment_id);
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
        $appt = $this->row($appointment_id);
        if (!$appt) {
            return;
        }
        $result = se_gcal_sync((array) $appt, $operation);

        if (empty($result['ok']) || !array_key_exists('event_id', $result)) {
            return;
        }

        // A fixture id must never land in a real appointment row: once written,
        // `gcal-fixture-*` is indistinguishable from a real Google event id, and
        // every later sync then believes an event exists that Google has never
        // heard of. Fixture results are recorded as a separate sync state.
        // By id and brand of the row itself, not the staff scope: the sync
        // state belongs to the row we just wrote, whoever triggered the write.
        if (function_exists('se_gcal_result_is_fixture') && se_gcal_result_is_fixture($result)) {
            $this->db->where('id', (int) $appointment_id)->where('brand_id', (int) $appt->brand_id)
                     ->update(db_prefix() . 'se_appointments', ['gcal_sync_state' => 'fixture']);

            return;
        }

        $this->db->where('id', (int) $appointment_id)->where('brand_id', (int) $appt->brand_id)
                 ->update(db_prefix() . 'se_appointments', [
                     'google_event_id' => $result['event_id'],
                     'gcal_sync_state' => $result['event_id'] === null ? 'cancelled' : 'synced',
                 ]);
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

        $appt = $this->row($appointment_id);
        if (!$appt || $appt->rel_type !== 'lead' || (int) $appt->rel_id === 0) {
            return;
        }

        // The lead MUST belong to the same brand as the appointment. Without
        // this the appointment's brand_id was used to queue a conversion built
        // from a lead in a different tenant.
        $this->db->select('consent_ads')
                 ->where('id', (int) $appt->rel_id)
                 ->where('brand_id', (int) $appt->brand_id);
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

    /** Maximum sensible appointment length; anything longer is a data-entry error. */
    const MAX_DURATION_SECONDS = 86400;

    /**
     * Fields a NEW appointment cannot be created without.
     *
     * add() previously accepted a payload with no title, no times and no
     * brand: prepare() simply omitted them and the INSERT created a blank row
     * that no screen could render meaningfully.
     */
    protected function missing_required($clean)
    {
        foreach (['title', 'start_at', 'end_at'] as $field) {
            if (!isset($clean[$field]) || trim((string) $clean[$field]) === '') {
                return true;
            }
        }

        if ((int) ($clean['brand_id'] ?? 0) <= 0) {
            return true;
        }

        if (!isset($clean['status']) || !in_array($clean['status'], self::STATUSES, true)) {
            return true;
        }

        return false;
    }

    protected function invalid_window($clean)
    {
        if (isset($clean['start_at']) && $clean['start_at'] !== '' && strtotime($clean['start_at']) === false) {
            return true;
        }

        // A required field that is present but blank is invalid; a field that is
        // absent is simply not being changed by this update.
        if (array_key_exists('title', $clean) && trim((string) $clean['title']) === '') {
            return true;
        }

        if (empty($clean['start_at']) || empty($clean['end_at'])) {
            return false;
        }

        $start = strtotime($clean['start_at']);
        $end   = strtotime($clean['end_at']);

        if ($end <= $start) {
            return true;
        }

        return ($end - $start) > self::MAX_DURATION_SECONDS;
    }

    /**
     * The linked lead/client and the assigned staff member must belong to the
     * appointment's brand. None of this was checked, so an appointment in
     * Brand A could point at a Brand B lead and be assigned to Brand B staff.
     */
    protected function invalid_links($clean)
    {
        $brand = (int) ($clean['brand_id'] ?? 0);

        if (!empty($clean['rel_id']) && !empty($clean['rel_type'])) {
            $type = $clean['rel_type'] === 'client' ? 'client' : 'lead';
            $recordBrand = function_exists('se_record_brand') ? se_record_brand($type, (int) $clean['rel_id']) : null;

            if ($recordBrand === null || (int) $recordBrand !== $brand) {
                return true;
            }
        }

        if (!empty($clean['staff_id']) && !$this->staff_belongs_to_brand((int) $clean['staff_id'], $brand)) {
            return true;
        }

        return false;
    }

    /** Is this staff member mapped to the brand (or an unrestricted admin)? */
    protected function staff_belongs_to_brand($staff_id, $brand_id)
    {
        $rows = $this->db->query(
            'SELECT brand_id FROM ' . db_prefix() . 'se_staff_brands WHERE staff_id = ' . (int) $staff_id
        )->result_array();

        if (!$rows) {
            /* An unmapped staff member is NOT implicitly unrestricted.
             *
             * This used to return true for anyone with no brand mapping, which
             * is precisely an ordinary staff member who has not been assigned
             * anywhere — the same "no mapping means no limits" inversion that
             * the tenancy split removed elsewhere. Only a real admin, or the
             * explicit all-brands capability, may be assigned to any brand. */
            return is_admin($staff_id)
                || (function_exists('staff_can') && staff_can(SE_CAP_ALL_BRANDS, SE_FEATURE_TENANCY, $staff_id));
        }

        foreach ($rows as $row) {
            if ((int) $row['brand_id'] === (int) $brand_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Advisory lock around the check-then-write for one staff member's calendar.
     *
     * MariaDB GET_LOCK is connection-scoped and released explicitly (and
     * automatically if the request dies), which is what we want: a crashed
     * request must not hold a booking slot hostage.
     */
    /**
     * Take the per-(brand, staff) advisory lock.
     *
     * GET_LOCK returns 1 on success, 0 on timeout and NULL on error. The
     * previous version ignored the result entirely and returned the lock name
     * regardless, so a timed-out lock looked identical to a held one: the
     * check-then-write then ran with no mutual exclusion at all and the
     * double-booking guard silently disappeared under exactly the concurrency
     * it exists to handle.
     *
     * @return string|null|false lock name when held, null when not needed,
     *                           false when it could NOT be acquired
     */
    protected function acquire_slot_lock($data)
    {
        $staff = (int) ($data['staff_id'] ?? 0);

        if ($staff <= 0) {
            return null;   // no staff, no calendar to protect
        }

        $name = 'se_appt_slot_' . (int) ($data['brand_id'] ?? 0) . '_' . $staff;

        $row = $this->db->query('SELECT GET_LOCK(' . $this->db->escape($name) . ', 5) AS l')->row();

        if (!$row || (int) $row->l !== 1) {
            return false;
        }

        return $name;
    }

    /** Release ONLY a lock this connection actually acquired. */
    protected function release_slot_lock($name)
    {
        if (is_string($name) && $name !== '') {
            $this->db->query('SELECT RELEASE_LOCK(' . $this->db->escape($name) . ')');
        }
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

        // Relation type is an enumeration, not free text.
        if (isset($clean['rel_type']) && !in_array($clean['rel_type'], ['lead', 'client'], true)) {
            $clean['rel_type'] = 'lead';
        }

        // Timezone must be one PHP actually knows, or the reminder clock silently
        // drifts. An unknown value falls back to the configured clinic default.
        if (isset($clean['staff_timezone'])) {
            $tz = trim((string) $clean['staff_timezone']);
            $clean['staff_timezone'] = ($tz !== '' && in_array($tz, timezone_identifiers_list(), true))
                ? $tz
                : (get_option('default_timezone') ?: 'Europe/Istanbul');
        }

        // Bounded free text.
        foreach (['title' => 191, 'location' => 191, 'cancellation_reason' => 191, 'notes' => 5000] as $col => $max) {
            if (isset($clean[$col])) {
                $clean[$col] = mb_substr(trim((string) $clean[$col]), 0, $max);
            }
        }

        return $clean;
    }

    /** Calendar feed for a date range, as FullCalendar-shaped events. */
    public function for_calendar($start, $end)
    {
        $rows  = $this->get('', []);
        $names = $this->patient_names($rows);

        $events = [];
        foreach ($rows as $r) {
            if ($r['start_at'] < $start || $r['start_at'] > $end) {
                continue;
            }
            $type    = function_exists('se_appt_type_key') ? se_appt_type_key($r['appointment_type'] ?? '') : 'consultation';
            $patient = $names[$r['rel_type'] . ':' . (int) $r['rel_id']] ?? '';
            $label   = function_exists('se_appt_type_label') ? se_appt_type_label($type) : $type;
            $events[] = [
                'id'        => $r['id'],
                'title'     => trim($label . ($patient !== '' ? ' · ' . $patient : '')),
                'start'     => $r['start_at'],
                'end'       => $r['end_at'],
                'className' => [function_exists('se_appt_type_class') ? se_appt_type_class($type) : '', 'st-' . $r['status']],
                'url'       => admin_url('se_appointments/view/' . $r['id']),
                'extendedProps' => [
                    'type'    => $type,
                    'status'  => $r['status'],
                    'patient' => $patient,
                    'staff'   => (string) ($r['staff_name'] ?? ''),
                    'place'   => (string) ($r['location'] ?? ''),
                ],
            ];
        }

        return $events;
    }

    /**
     * Display names for the related records of a set of appointments, in one
     * query per relation type (leads, clients) — never per row.
     *
     * @return array 'lead:123' => 'Ayşe Y.'
     */
    public function patient_names(array $rows)
    {
        $ids = ['lead' => [], 'client' => []];
        foreach ($rows as $r) {
            $t = (string) ($r['rel_type'] ?? '');
            if (isset($ids[$t]) && (int) $r['rel_id'] > 0) {
                $ids[$t][] = (int) $r['rel_id'];
            }
        }
        $out = [];
        if ($ids['lead']) {
            $this->db->select('id, name')->where_in('id', array_unique($ids['lead']));
            foreach ($this->db->get(db_prefix() . 'leads')->result_array() as $l) {
                $out['lead:' . (int) $l['id']] = (string) $l['name'];
            }
        }
        if ($ids['client']) {
            $this->db->select('userid, company')->where_in('userid', array_unique($ids['client']));
            foreach ($this->db->get(db_prefix() . 'clients')->result_array() as $c) {
                $out['client:' . (int) $c['userid']] = (string) $c['company'];
            }
        }

        return $out;
    }
}
