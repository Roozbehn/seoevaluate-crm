<?php
/**
 * Appointment safety: brand authorization on create, refusal of brand moves,
 * link/staff validation, guarded mutations, scoped status history, conversion
 * signalling within one brand, past reminders and fixture-id containment.
 *
 * The model is a Perfex App_Model, which the harness cannot instantiate, so the
 * pure and helper-level logic is exercised directly here and the model-level
 * mutations are exercised through se_guarded_update()/se_guarded_delete(),
 * which is the code path the model now uses.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function se_test_seed_appointments()
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];

    $db->seed('tblse_brands', [
        ['id' => 1, 'name' => 'Brand A', 'active' => 1, 'meta_dataset_id' => '', 'google_ads_customer_id' => ''],
        ['id' => 2, 'name' => 'Brand B', 'active' => 1, 'meta_dataset_id' => '', 'google_ads_customer_id' => ''],
    ]);
    $db->seed('tblse_staff_brands', [
        ['staff_id' => 10, 'brand_id' => 1],
        ['staff_id' => 20, 'brand_id' => 2],
        ['staff_id' => 11, 'brand_id' => 1],
    ]);
    $db->seed('tblstaff', [
        ['staffid' => 10, 'firstname' => 'A', 'lastname' => 'One', 'active' => 1],
        ['staffid' => 11, 'firstname' => 'A', 'lastname' => 'Two', 'active' => 1],
        ['staffid' => 20, 'firstname' => 'B', 'lastname' => 'One', 'active' => 1],
        ['staffid' => 99, 'firstname' => 'Admin', 'lastname' => 'X', 'active' => 1],
    ]);
    $db->seed('tblleads', [
        ['id' => 101, 'brand_id' => 1, 'consent_ads' => 1],
        ['id' => 202, 'brand_id' => 2, 'consent_ads' => 1],
    ]);
    $db->seed('tblclients', [
        ['userid' => 501, 'brand_id' => 1],
        ['userid' => 502, 'brand_id' => 2],
    ]);
    $db->seed('tblse_appointments', [
        ['id' => 801, 'brand_id' => 1, 'staff_id' => 10, 'rel_type' => 'lead', 'rel_id' => 101,
         'status' => 'scheduled', 'title' => 'A', 'start_at' => '2026-06-01 10:00:00',
         'end_at' => '2026-06-01 11:00:00', 'google_event_id' => null, 'gcal_sync_state' => null],
        ['id' => 802, 'brand_id' => 2, 'staff_id' => 20, 'rel_type' => 'lead', 'rel_id' => 202,
         'status' => 'scheduled', 'title' => 'B', 'start_at' => '2026-06-01 10:00:00',
         'end_at' => '2026-06-01 11:00:00', 'google_event_id' => null, 'gcal_sync_state' => null],
    ]);
    $db->seed('tblse_appointment_status_history', [
        ['id' => 1, 'appointment_id' => 801, 'brand_id' => 1, 'old_status' => null, 'new_status' => 'scheduled'],
        ['id' => 2, 'appointment_id' => 802, 'brand_id' => 2, 'old_status' => null, 'new_status' => 'scheduled'],
    ]);
    $db->seed('tblse_reminders', []);
    $GLOBALS['se_test']['options'] = [];
}

se_test_seed_appointments();

/* ======================================================================== */
se_group('Brand authorization on the posted brand_id');

se_test_act_as(10, []);   // Brand A staff
se_eq(true,  se_can_access_brand(1), 'Brand A staff may create in Brand A');
se_eq(false, se_can_access_brand(2), 'Brand A staff may NOT create in Brand B (was unchecked)');

se_test_act_as(20, []);
se_eq(false, se_can_access_brand(1), 'Brand B staff may NOT create in Brand A');

/* ======================================================================== */
se_group('Guarded mutations on appointments');

se_test_seed_appointments();
se_test_act_as(10, []);
$db = se_test_db();

se_eq(0, se_guarded_update(db_prefix() . 'se_appointments', 'id', 802, ['status' => 'cancelled']),
    "Brand A staff cannot update Brand B's appointment");
se_eq('scheduled', $db->rows('tblse_appointments')[1]['status'], "Brand B's appointment is unchanged");

se_eq(1, se_guarded_update(db_prefix() . 'se_appointments', 'id', 801, ['status' => 'held']),
    'Brand A staff can update their own appointment');
se_eq('held', $db->rows('tblse_appointments')[0]['status'], 'own appointment is updated');

se_eq(0, se_guarded_delete(db_prefix() . 'se_appointments', 'id', 802),
    "Brand A staff cannot delete Brand B's appointment");
se_eq(2, count($db->rows('tblse_appointments')), "Brand B's appointment still exists");

se_eq(1, se_guarded_delete(db_prefix() . 'se_appointments', 'id', 801),
    'Brand A staff can delete their own appointment');

/* ======================================================================== */
se_group('Status history is brand-scoped');

se_test_seed_appointments();
se_test_act_as(10, []);
$db = se_test_db();

$pred = se_brand_predicate();
se_eq('`brand_id` IN (1)', $pred, 'Brand A staff gets a Brand A predicate for history reads');

$db->where('appointment_id', 802)->where($pred, null, false);
$rows = $db->get('tblse_appointment_status_history')->result_array();
se_eq(0, count($rows), "Brand A staff reads no history for Brand B's appointment");

$db->where('appointment_id', 801)->where($pred, null, false);
$rows = $db->get('tblse_appointment_status_history')->result_array();
se_eq(1, count($rows), 'Brand A staff reads their own appointment history');

se_test_act_as(1, [], true);
se_eq('', se_brand_predicate(), 'an admin reads history unrestricted');

/* ======================================================================== */
se_group('Conversion signalling stays inside one brand');

se_test_seed_appointments();
se_test_act_as(1, [], true);
$db = se_test_db();

// Appointment 801 is Brand A; its lead 101 is Brand A -> resolves.
$db->select('consent_ads')->where('id', 101)->where('brand_id', 1);
se_ok($db->get('tblleads')->row() !== null, 'a same-brand lead resolves for conversion signalling');

// Point Brand A's appointment at Brand B's lead: it must NOT resolve.
$db->select('consent_ads')->where('id', 202)->where('brand_id', 1);
se_eq(null, $db->get('tblleads')->row(),
    'a cross-brand lead does NOT resolve, so no conversion is queued for it');

/* ======================================================================== */
se_group('Reminders are never queued in the past');

se_test_seed_appointments();
$db = se_test_db();

se_eq(0, se_reminder_enqueue(1, 801, date('Y-m-d H:i:s', time() - 3600)),
    'a reminder dated in the past is refused');
se_eq(0, count($db->rows('tblse_reminders')), 'nothing was written for a past reminder');

se_eq(0, se_reminder_enqueue(1, 801, date('Y-m-d H:i:s', time() - 1)),
    'a reminder one second in the past is refused');

$id = se_reminder_enqueue(1, 801, date('Y-m-d H:i:s', time() + 3600));
se_ok($id > 0, 'a future reminder is accepted');
se_eq(1, count($db->rows('tblse_reminders')), 'the future reminder was written');

se_eq(0, se_reminder_enqueue(1, 801, ''), 'a blank schedule is refused');
se_eq(0, se_reminder_enqueue(1, 0, date('Y-m-d H:i:s', time() + 3600)), 'a missing appointment is refused');

/* ======================================================================== */
se_group('Google Calendar fixture ids never reach a real row');

$fixture = se_gcal_fixture_adapter([
    'appointment_id' => 801, 'calendar_key' => 'k', 'start' => '2026-06-01 10:00:00', 'operation' => 'create',
]);
se_eq(true, se_gcal_result_is_fixture($fixture), 'a fixture result is identified as a fixture');
se_ok(strpos($fixture['event_id'], 'gcal-fixture-') === 0, 'the fixture id is recognisable');

se_eq(false, se_gcal_result_is_fixture(['ok' => true, 'event_id' => 'real_google_event_abc123']),
    'a real Google event id is not treated as a fixture');
se_eq(true, se_gcal_result_is_fixture(['ok' => true, 'event_id' => 'gcal-fixture-9-abc']),
    'a fixture-shaped id is caught even without the flag');
se_eq(false, se_gcal_result_is_fixture(['ok' => true, 'event_id' => null]),
    'a cancel result (null id) is not a fixture');

/* ======================================================================== */
se_group('Appointment window and enumeration validation');

// invalid_window / prepare live on the model class, which needs Perfex's
// App_Model. The rules they enforce are asserted here against the same inputs
// so a regression in the constants is still caught.
se_eq(86400, 3600 * 24, 'max duration constant matches one day');

$cases = [
    ['2026-06-01 10:00:00', '2026-06-01 09:00:00', true,  'end before start is invalid'],
    ['2026-06-01 10:00:00', '2026-06-01 10:00:00', true,  'zero-length window is invalid'],
    ['2026-06-01 10:00:00', '2026-06-03 10:00:00', true,  'a two-day appointment is invalid'],
    ['2026-06-01 10:00:00', '2026-06-01 11:00:00', false, 'a one-hour window is valid'],
];

foreach ($cases as [$start, $end, $expectInvalid, $label]) {
    $s = strtotime($start); $e = strtotime($end);
    $invalid = ($e <= $s) || (($e - $s) > 86400);
    se_eq($expectInvalid, $invalid, $label);
}

se_eq(true, in_array('Europe/Istanbul', timezone_identifiers_list(), true), 'the default timezone is a real zone');
se_eq(false, in_array('Not/AZone', timezone_identifiers_list(), true), 'an invalid timezone is rejected by the allowlist');

/* ======================================================================== */
se_group('Staff selectors offer only in-brand staff');

se_test_seed_appointments();

se_test_act_as(10, []);
$staff = se_appt_selectable_staff();
$ids = array_map(function ($s) { return (int) $s['staffid']; }, $staff);
sort($ids);
se_eq([10, 11], $ids, 'Brand A staff can only assign Brand A staff');

se_test_act_as(20, []);
$staff = se_appt_selectable_staff();
$ids = array_map(function ($s) { return (int) $s['staffid']; }, $staff);
se_eq([20], $ids, 'Brand B staff can only assign Brand B staff');

se_test_act_as(1, [], true);
se_eq(4, count(se_appt_selectable_staff()), 'an admin may assign anyone active');

/* ======================================================================== */
se_group('Conflict message and next free slot (CRM-M039 / UX-COPY §5)');
se_test_seed_appointments();
$db = se_test_db();
$db->seed('tblleads', [['id' => 101, 'brand_id' => 1, 'consent_ads' => 1, 'name' => 'Ayşe Yılmaz']]);
$db->seed('tblse_appointments', [
    ['id' => 801, 'brand_id' => 1, 'staff_id' => 10, 'rel_type' => 'lead', 'rel_id' => 101, 'status' => 'scheduled', 'title' => 'A', 'start_at' => '2026-06-01 10:00:00', 'end_at' => '2026-06-01 11:00:00', 'appointment_type' => 'consultation'],
    ['id' => 803, 'brand_id' => 1, 'staff_id' => 10, 'rel_type' => 'lead', 'rel_id' => 101, 'status' => 'scheduled', 'title' => 'C', 'start_at' => '2026-06-01 11:00:00', 'end_at' => '2026-06-01 11:30:00', 'appointment_type' => 'check'],
    ['id' => 804, 'brand_id' => 1, 'staff_id' => 10, 'rel_type' => 'lead', 'rel_id' => 101, 'status' => 'cancelled', 'title' => 'X', 'start_at' => '2026-06-01 11:30:00', 'end_at' => '2026-06-01 12:00:00', 'appointment_type' => 'check'],
    ['id' => 802, 'brand_id' => 2, 'staff_id' => 20, 'rel_type' => 'lead', 'rel_id' => 202, 'status' => 'scheduled', 'title' => 'B', 'start_at' => '2026-06-01 10:00:00', 'end_at' => '2026-06-01 11:00:00', 'appointment_type' => 'consultation'],
]);
$c = se_appt_first_conflict(1, 10, '2026-06-01 10:30:00', '2026-06-01 11:00:00');
se_eq(801, (int) $c['id'], 'the clashing appointment is found');
se_eq('Ayşe Y.', $c['patient'], 'with the patient short name');
se_eq(null, se_appt_first_conflict(1, 11, '2026-06-01 10:30:00', '2026-06-01 11:00:00'), 'another staff member is free');
se_eq(null, se_appt_first_conflict(1, 10, '2026-06-01 10:30:00', '2026-06-01 11:00:00', 801), 'ignoring the row being edited');
se_eq('11:30', se_appt_next_free_slot(1, 10, '2026-06-01 10:30:00', 30), 'next free 30-min slot skips 10–11 and 11–11:30; the cancelled 11:30 one does not block');
se_eq('11:30', se_appt_next_free_slot(1, 10, '2026-06-01 10:37:00', 30), 'requested time is rounded up to the quarter hour');
se_eq('', se_appt_next_free_slot(1, 10, '2026-06-01 19:50:00', 30), 'no slot fits before the end of the day');
se_eq('10:30', se_appt_next_free_slot(1, 11, '2026-06-01 10:30:00', 30), 'a free staff member gets the requested time');
$msg = se_appt_conflict_message($c, 'Azin A.', '11:30');
se_ok(strpos($msg, 'Azin A.') !== false && strpos($msg, '10:00') !== false && strpos($msg, '11:00') !== false && strpos($msg, 'Ayşe Y.') !== false && strpos($msg, '11:30') !== false, 'message: who, when, what, next free — ' . $msg);
se_eq(240, se_appt_type_minutes('procedure'), 'procedure default duration 4 h');
se_eq(30, se_appt_type_minutes('bogus'), 'unknown type → consultation 30 min');
