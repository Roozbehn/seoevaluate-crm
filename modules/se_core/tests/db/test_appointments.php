<?php
/**
 * REAL MariaDB, REAL model: Se_appointments_model executed directly.
 *
 * These call the actual add()/update()/delete()/status_history() methods — no
 * formula is reimplemented here, so a change to the model that breaks a rule
 * breaks this suite.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$p  = db_prefix();
$db = se_db();

$BA = SE_TEST_BRAND_A;
$BB = SE_TEST_BRAND_B;
$S1 = SE_TEST_ID_BASE + 10;    // staff mapped to Brand A
$S2 = SE_TEST_ID_BASE + 20;    // staff mapped to Brand B
$L1 = SE_TEST_ID_BASE + 101;   // lead in Brand A
$L2 = SE_TEST_ID_BASE + 202;   // lead in Brand B

// Brands + mappings (test_scope may already have inserted brand A/B).
$db->query("INSERT IGNORE INTO {$p}se_brands (id,name,slug,active,date_created) VALUES
    ({$BA},'ZZTEST Brand A','zztest-a',1,NOW()),({$BB},'ZZTEST Brand B','zztest-b',1,NOW())");
$db->query("INSERT IGNORE INTO {$p}se_staff_brands (staff_id,brand_id) VALUES ({$S1},{$BA}),({$S2},{$BB})");
$db->query("INSERT IGNORE INTO {$p}staff (staffid,firstname,lastname,email,active,admin,datecreated)
    VALUES ({$S1},'ZZA','Test','zza@example.invalid',1,0,NOW()),
           ({$S2},'ZZB','Test','zzb@example.invalid',1,0,NOW())");
$db->query("INSERT INTO {$p}leads (id,brand_id,name,email,status,source,dateadded,lastcontact,assigned,addedfrom)
    VALUES ({$L1},{$BA},'ZZ Lead A','zzla@example.invalid',0,0,NOW(),NULL,0,0),
           ({$L2},{$BB},'ZZ Lead B','zzlb@example.invalid',0,0,NOW(),NULL,0,0)");

$model = new Se_appointments_model();

se_group('Required fields on create (real model)');

se_test_act_as($S1, []);

se_eq(false, $model->add(['brand_id' => $BA]), 'create without title/start/end is refused');
se_eq(false, $model->add(['title' => 'x', 'brand_id' => $BA]), 'create without times is refused');
se_eq(false, $model->add(['title' => 'x', 'start_at' => '2026-09-01 10:00:00',
    'end_at' => '2026-09-01 11:00:00']), 'create without a brand is refused');
/* prepare() NORMALISES an unrecognised status to 'scheduled' rather than
 * refusing. That is the deliberate contract — a client that sends garbage gets
 * a safe default instead of a silent no-op — so assert the normalisation, and
 * assert that the garbage never reaches the table. */
$normId = $model->add(['title' => 'ZZ Norm', 'start_at' => '2026-09-05 10:00:00',
    'end_at' => '2026-09-05 11:00:00', 'brand_id' => $BA, 'status' => 'bogus_status']);
se_ok($normId > 0, 'an unrecognised status is normalised rather than refused');
$normRow = $db->query("SELECT status FROM {$p}se_appointments WHERE id=" . (int) $normId)->row();
se_eq('scheduled', $normRow->status, 'and it is stored as the safe default, never as the garbage value');

se_group('Brand authorization on create (real model)');

se_eq(false, $model->add(['title' => 'cross', 'start_at' => '2026-09-01 10:00:00',
    'end_at' => '2026-09-01 11:00:00', 'brand_id' => $BB, 'status' => 'scheduled']),
    'Brand A staff cannot create inside Brand B');

$id = $model->add(['title' => 'ZZ Appt A', 'start_at' => '2026-09-01 10:00:00',
    'end_at' => '2026-09-01 11:00:00', 'brand_id' => $BA, 'status' => 'scheduled',
    'staff_id' => $S1, 'rel_type' => 'lead', 'rel_id' => $L1]);
se_ok($id > 0, 'a valid create succeeds and returns an id');

$row = $db->query("SELECT * FROM {$p}se_appointments WHERE id=" . (int) $id)->row();
se_ok($row !== null, 'the row exists in the database');
se_eq($BA, (int) $row->brand_id, 'stored with the authorized brand');

se_group('Cross-brand link is refused (real model)');

se_eq(false, $model->add(['title' => 'bad link', 'start_at' => '2026-09-02 10:00:00',
    'end_at' => '2026-09-02 11:00:00', 'brand_id' => $BA, 'status' => 'scheduled',
    'rel_type' => 'lead', 'rel_id' => $L2]),
    'cannot link a Brand A appointment to a Brand B lead');

se_eq(false, $model->add(['title' => 'bad staff', 'start_at' => '2026-09-02 10:00:00',
    'end_at' => '2026-09-02 11:00:00', 'brand_id' => $BA, 'status' => 'scheduled',
    'staff_id' => $S2]),
    'cannot assign Brand B staff to a Brand A appointment');

se_group('PARTIAL-UPDATE ATTACKS (the bypass this phase closed)');

// A: only rel_id, pointing at a foreign lead. rel_type was absent from $data,
// so the old link check was skipped entirely.
se_eq(false, $model->update($id, ['rel_id' => $L2]),
    'partial update with ONLY a foreign rel_id is refused');
$row = $db->query("SELECT rel_id FROM {$p}se_appointments WHERE id=" . (int) $id)->row();
se_eq($L1, (int) $row->rel_id, 'the relation was not re-pointed at the foreign lead');

// B: only end_at, earlier than the stored start. start_at was absent, so the
// old window check returned "valid".
se_eq(false, $model->update($id, ['end_at' => '2026-09-01 09:00:00']),
    'partial update with ONLY an end before the stored start is refused');
$row = $db->query("SELECT end_at FROM {$p}se_appointments WHERE id=" . (int) $id)->row();
se_eq('2026-09-01 11:00:00', $row->end_at, 'the end time was not corrupted');

// C: only staff_id, pointing at foreign staff.
se_eq(false, $model->update($id, ['staff_id' => $S2]),
    'partial update with ONLY a foreign staff_id is refused');

// D: only rel_type, flipping lead->client so the stored rel_id is reinterpreted.
se_eq(false, $model->update($id, ['rel_type' => 'client']),
    'partial update with ONLY rel_type is refused (stored rel_id is not a client)');

// A legitimate partial update still works.
se_eq(true, $model->update($id, ['location' => 'Room 2']), 'a legitimate partial update succeeds');
$row = $db->query("SELECT location FROM {$p}se_appointments WHERE id=" . (int) $id)->row();
se_eq('Room 2', $row->location, 'and is written');

se_group('Brand move is refused (real model)');

$model->update($id, ['brand_id' => $BB, 'location' => 'Room 3']);
$row = $db->query("SELECT brand_id,location FROM {$p}se_appointments WHERE id=" . (int) $id)->row();
se_eq($BA, (int) $row->brand_id, 'a posted brand_id cannot move the appointment');
se_eq('Room 3', $row->location, 'but the rest of the update still applies');

se_group('Cross-brand mutation is refused at the SQL boundary');

se_test_act_as($S2, []);   // Brand B staff
se_eq(false, $model->update($id, ['title' => 'HACKED']), "Brand B staff cannot update Brand A's appointment");
$row = $db->query("SELECT title FROM {$p}se_appointments WHERE id=" . (int) $id)->row();
se_eq('ZZ Appt A', $row->title, 'the title is unchanged in the database');

se_eq(false, $model->delete($id), "Brand B staff cannot delete Brand A's appointment");
se_eq(1, (int) $db->query("SELECT COUNT(*) c FROM {$p}se_appointments WHERE id=" . (int) $id)->row()->c,
    'the row still exists');

se_group('Advisory lock: GET_LOCK result is honoured');

se_test_act_as($S1, []);

// Hold the lock on a SECOND connection, then prove the model refuses rather
// than proceeding unprotected.
$other = se_test_second_connection();

if ($other === null) {
    se_ok(false, 'second connection available for the lock test');
} else {
    $lockName = 'se_appt_slot_' . $BA . '_' . $S1;
    $held = $other->query('SELECT GET_LOCK(' . $other->escape($lockName) . ', 2) AS l')->row();
    se_eq(1, (int) $held->l, 'the second connection holds the slot lock');

    $t0 = microtime(true);
    $res = $model->update($id, ['start_at' => '2026-09-01 14:00:00', 'end_at' => '2026-09-01 15:00:00']);
    $elapsed = microtime(true) - $t0;

    se_eq(false, $res, 'the model REFUSES the write when it cannot take the lock');
    se_ok($elapsed >= 4.0, 'it waited for the lock timeout rather than skipping the lock (' . round($elapsed, 1) . 's)');

    $row = $db->query("SELECT start_at FROM {$p}se_appointments WHERE id=" . (int) $id)->row();
    se_eq('2026-09-01 10:00:00', $row->start_at, 'the start time was not changed while the lock was unavailable');

    $other->query('SELECT RELEASE_LOCK(' . $other->escape($lockName) . ')');

    // With the lock free again the same update succeeds.
    se_eq(true, $model->update($id, ['start_at' => '2026-09-01 14:00:00', 'end_at' => '2026-09-01 15:00:00']),
        'the same update succeeds once the lock is released');
    $other->conn->close();
}

se_group('Double booking is prevented (real overlap check)');

$id2 = $model->add(['title' => 'ZZ Overlap', 'start_at' => '2026-09-01 14:30:00',
    'end_at' => '2026-09-01 15:30:00', 'brand_id' => $BA, 'status' => 'scheduled',
    'staff_id' => $S1, 'rel_type' => 'lead', 'rel_id' => $L1]);
se_eq(false, $id2, 'an overlapping appointment for the same staff member is refused');

$id3 = $model->add(['title' => 'ZZ No Overlap', 'start_at' => '2026-09-01 16:00:00',
    'end_at' => '2026-09-01 17:00:00', 'brand_id' => $BA, 'status' => 'scheduled',
    'staff_id' => $S1, 'rel_type' => 'lead', 'rel_id' => $L1]);
se_ok($id3 > 0, 'a non-overlapping appointment is accepted');

se_group('Status transitions and history (real model)');

se_eq(true, $model->update($id, ['status' => 'held']), 'status change to held succeeds');
$hist = $model->status_history($id);
se_ok(count($hist) >= 1, 'a status-history row was written');

$last = end($hist);
se_eq('held', $last['new_status'], 'history records the new status');
se_eq($BA, (int) $last['brand_id'], 'history carries the brand');

// History is brand-scoped: Brand B staff must not read it.
se_test_act_as($S2, []);
se_eq(0, count($model->status_history($id)), "Brand B staff read no history for Brand A's appointment");

se_group('Delete is guarded and authorizes BEFORE side effects');

se_test_act_as($S1, []);
se_eq(true, $model->delete($id3), 'own appointment can be deleted');
se_eq(0, (int) $db->query("SELECT COUNT(*) c FROM {$p}se_appointments WHERE id=" . (int) $id3)->row()->c,
    'the row is gone');

se_test_act_as(SE_TEST_ID_BASE + 999, []);   // unmapped
se_eq(false, $model->delete($id), 'unmapped staff cannot delete');
se_eq(1, (int) $db->query("SELECT COUNT(*) c FROM {$p}se_appointments WHERE id=" . (int) $id)->row()->c,
    'the row survives');
