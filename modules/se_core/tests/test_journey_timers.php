<?php
/**
 * Staff timers (CRM-M045 / AZCRM-WF-002), quote expiry (CRM-M048 /
 * AZCRM-WF-005), aftercare auto-start behind the approved flag (CRM-M046 /
 * AZCRM-WF-003). Every nudge fires once per journey + state period; the
 * thresholds are the next-action engine's; the option switches it all off.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$now = strtotime('2026-09-04 12:00:00');
$ago = function ($s) use ($now) { return date('Y-m-d H:i:s', $now - $s); };

function se_timers_seed($ago, $now)
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];
    $db->seed('tblse_brands', [['id' => 1, 'name' => 'A', 'active' => 1]]);
    $db->seed('tblse_staff_brands', [['staff_id' => 10, 'brand_id' => 1]]);
    $db->seed('tblstaff', [['staffid' => 10, 'firstname' => 'Azin', 'lastname' => 'Asgari']]);
    $db->seed('tblleads', [['id' => 101, 'name' => 'Ayşe Yılmaz', 'phonenumber' => '+905551112233']]);
    $j = function ($id, $state, $age, $extra = []) use ($ago) {
        return array_merge(['id' => $id, 'brand_id' => 1, 'lead_id' => 101, 'wa_user_id' => '90555111' . $id, 'wa_conversation_id' => $id, 'state' => $state,
            'state_changed_at' => $ago($age), 'last_updated' => $ago($age), 'date_created' => $ago($age + 86400), 'automation_state' => 'active', 'automation_changed_at' => null,
            'urgent' => 0, 'reminder_count' => 0, 'assigned_staff' => 10, 'source' => 'organic_whatsapp', 'consultation_appointment_id' => 0, 'procedure_appointment_id' => 0,
            'display_name' => '', 'procedure_at' => null], $extra);
    };
    $db->seed('tblse_journeys', [
        $j(1, 'ready_for_review', 40 * 60),                                   // young: no nudge
        $j(2, 'ready_for_review', 5 * 86400),                                 // escalated (p1) → nudge
        $j(3, 'quote_sent', 4 * 86400),                                       // follow-up → nudge
        $j(4, 'quote_sent', 40 * 86400),                                      // expired → state + nudge
        $j(5, 'photos_requested', 3 * 86400, ['automation_state' => 'paused_staff', 'automation_changed_at' => $ago(2 * 86400)]),   // paused stale → nudge
        $j(6, 'consultation_booked', 3 * 86400, ['consultation_appointment_id' => 60]),   // ended 5h ago, unrecorded → nudge
        $j(7, 'procedure_completed', 2 * 86400, ['procedure_at' => $ago(2 * 86400)]),     // no plan → nudge (or auto-start when approved)
        $j(8, 'welcome_sent', 2 * 86400),                                     // welcome stale → nudge
        $j(9, 'completed', 600),                                              // terminal → never
    ]);
    $db->seed('tblse_journey_quotes', [
        ['id' => 1, 'journey_id' => 3, 'brand_id' => 1, 'version' => 1, 'status' => 'sent', 'sent_at' => $ago(4 * 86400), 'valid_until' => date('Y-m-d', $now + 20 * 86400), 'patient_response' => ''],
        ['id' => 2, 'journey_id' => 4, 'brand_id' => 1, 'version' => 1, 'status' => 'sent', 'sent_at' => $ago(40 * 86400), 'valid_until' => date('Y-m-d', $now - 86400), 'patient_response' => ''],
    ]);
    $db->seed('tblse_appointments', [
        ['id' => 60, 'brand_id' => 1, 'rel_type' => 'lead', 'rel_id' => 101, 'staff_id' => 10, 'start_at' => $ago(6 * 3600), 'end_at' => $ago(5 * 3600), 'status' => 'scheduled', 'appointment_type' => 'consultation'],
    ]);
    $db->seed('tblse_wa_outbound', []);
    $db->seed('tblse_wa_conversations', []);
    $db->seed('tblse_journey_tasks', []);
    $db->seed('tblse_journey_events', []);
    $db->seed('tblse_journey_transitions', []);
    $db->seed('tblse_journey_audit', []);
    $db->seed('tblse_journey_aftercare_plans', []);
    $db->seed('tblse_journey_aftercare_events', []);
    $GLOBALS['se_test']['options'] = [];
    se_authz_reset_cache();

    return $db;
}
$kinds = function ($db) { $k = []; foreach ($db->rows('tblse_journey_tasks') as $t) { $k[] = (int) $t['journey_id'] . ':' . $t['kind']; } sort($k); return $k; };

/* ======================================================================== */
se_group('Timers: one nudge per threshold, none for young or terminal journeys');
$db = se_timers_seed($ago, $now);
se_test_act_as(0, []);   // cron: no staff session
$r = se_journey_run_timers($now);
se_eq(8, $r['scanned'], 'active journeys scanned (terminal excluded)');
se_eq(1, $r['expired'], 'one quote expired');
se_eq('quote_expired', $db->rows('tblse_journeys')[3]['state'], 'journey 4: quote_sent → quote_expired');
se_eq(1, count(array_filter($db->rows('tblse_journey_transitions'), function ($t) { return $t['to_state'] === 'quote_expired' && $t['actor_type'] === 'system'; })), 'the expiry transition is recorded by the system');
se_eq(['2:timer_review', '3:timer_quote_followup', '4:timer_quote_expired', '5:timer_paused', '6:timer_held', '7:timer_aftercare', '8:timer_welcome'], $kinds($db), 'one task per documented threshold; journey 1 (40 min) gets none');
se_eq(7, $r['tasks'], 'task count');
$t2 = null; foreach ($db->rows('tblse_journey_tasks') as $t) { if ((int) $t['journey_id'] === 2) { $t2 = $t; } }
se_eq('urgent', $t2['priority'], 'an escalated review is urgent');
se_eq(10, (int) $t2['assigned_staff'], 'assigned to the journey owner');
se_ok(strpos($t2['dedup_key'], 'j2:timer_review:') === 0, 'dedup key carries the state period');
se_eq(7, count(array_filter($db->rows('tblse_journey_events'), function ($e) { return $e['kind'] === 'timer'; })), 'each nudge logged as an event');

se_group('Timers: idempotent');
$r2 = se_journey_run_timers($now);
se_eq(0, $r2['tasks'], 'a second pass creates nothing');
se_eq(0, $r2['expired'], 'and expires nothing again');
$r3 = se_journey_run_timers($now + 3600);
se_eq(0, $r3['tasks'], 'an hour later still nothing (same state period)');
se_eq(7, count($db->rows('tblse_journey_tasks')), 'still 7 tasks');

se_group('Timers: a new state period earns a new nudge, a done task does not resurrect');
$db->where('id', 3)->update('tblse_journeys', ['state_changed_at' => $ago(4 * 3600), 'last_updated' => $ago(4 * 3600)]);   // re-sent 4h ago: below threshold
$db->where('id', 1)->update('tblse_journey_quotes', ['sent_at' => $ago(4 * 3600)]);
se_eq(0, se_journey_run_timers($now)['tasks'], 'below the threshold nothing fires');
$db->where('id', 3)->update('tblse_journeys', ['state_changed_at' => $ago(10 * 86400), 'last_updated' => $ago(10 * 86400)]);
$db->where('id', 1)->update('tblse_journey_quotes', ['sent_at' => $ago(10 * 86400)]);
se_eq(1, se_journey_run_timers($now)['tasks'], 'a different state period (re-sent quote) fires once more');

se_group('Timers: kill switch');
$db = se_timers_seed($ago, $now);
$GLOBALS['se_test']['options'] = ['se_journey_timers' => '0'];
$r = se_journey_run_timers($now);
se_eq('disabled', $r['skipped'], 'option 0 disables');
se_eq(0, count($db->rows('tblse_journey_tasks')), 'no tasks');
se_eq('quote_sent', $db->rows('tblse_journeys')[3]['state'], 'no expiry either');

se_group('Quote expiry: a late patient answer still counts');
$db = se_timers_seed($ago, $now);
$GLOBALS['se_test']['options'] = [];
se_journey_run_timers($now);
$j4 = se_journey_get_raw(4);
se_eq('quote_expired', $j4->state, 'expired');
$t = se_journey_transition($j4, 'quote_accepted', 'patient_accepted_quote', 'patient');
se_eq(true, !empty($t['ok']), 'quote_expired → quote_accepted is allowed');
$db = se_timers_seed($ago, $now);
se_journey_run_timers($now);
$t = se_journey_transition(se_journey_get_raw(4), 'quote_pending_staff_approval', 'staff_new_version', 'staff', 10);
se_eq(true, !empty($t['ok']), 'quote_expired → new version (pending approval) is allowed');
$t = se_journey_transition(se_journey_get_raw(2), 'quote_expired', 'x', 'system');
se_eq(false, !empty($t['ok']), 'ready_for_review → quote_expired is not (only quote_sent expires)');

se_group('Aftercare auto-start only with an approved protocol');
$db = se_timers_seed($ago, $now);
se_journey_run_timers($now);
se_eq(0, count($db->rows('tblse_journey_aftercare_plans')), 'default protocol is unapproved → no auto-start');
se_ok(in_array('7:timer_aftercare', $kinds($db), true), 'staff get the "start the plan" task instead');
se_eq(0, count($db->rows('tblse_wa_outbound')), 'and NO patient message was queued automatically (approval flag off)');
se_eq('procedure_completed', se_journey_get_raw(7)->state, 'the journey is not moved either — visible task, not a silent skip');
$db = se_timers_seed($ago, $now);
$GLOBALS['se_test']['options'] = ['se_journey_aftercare_protocols_1' => json_encode([['key' => 'standard', 'version' => '2', 'approved' => 1, 'name' => 'Standart',
    'steps' => [['key' => 'day1', 'label' => '1. gün', 'offset_hours' => 24, 'kind' => 'checkin', 'template' => 'eyebrow_aftercare_checkin_tr']]]])];
$r = se_journey_run_timers($now);
se_eq(1, $r['aftercare'], 'an approved protocol auto-starts the plan');
se_eq(1, count($db->rows('tblse_journey_aftercare_plans')), 'one plan');
se_eq('aftercare_active', se_journey_get_raw(7)->state, 'journey 7 → aftercare_active');
se_ok(!in_array('7:timer_aftercare', $kinds($db), true), 'and no "start the plan" task is left behind');
se_eq(1, count(array_filter($db->rows('tblse_journey_events'), function ($e) { return $e['kind'] === 'auto_started'; })), 'logged as auto-started');
se_eq(0, se_journey_run_timers($now)['aftercare'], 'not started twice');
se_eq(0, count(array_filter($db->rows('tblse_wa_outbound'), function ($o) { return $o['status'] === 'sent'; })), 'starting the plan sends nothing immediately — the first step is scheduled for +24 h through the queue');

/* ======================================================================== */
se_group('Aftercare protocol v2 (DEC-005): stage templates exist, guide link per language, approval gate');
$std = se_journey_aftercare_default_protocol();
se_eq(['2', 0], [$std['version'], (int) $std['approved']], 'v2 ships UNAPPROVED');
$defs = se_journey_template_definitions();
foreach ($std['steps'] as $s) {
    if ($s['kind'] === 'instruction' || $s['kind'] === 'photo_request') { se_ok(isset($defs[$s['template']]), $s['key'] . ': template ' . $s['template'] . ' is a registered definition'); }
    if ($s['kind'] === 'instruction') { se_ok(strpos($s['text'], '{{link}}') !== false && strpos($s['text'], '{{name}}') !== false, $s['key'] . ': in-window text carries name and link'); }
    if ($s['kind'] === 'staff_task') { se_ok(trim($s['text']) !== '' && $s['template'] === '', $s['key'] . ': staff task has an instruction and no patient template'); }
}
se_eq(['day0', 'day1', 'day2', 'day3', 'day7', 'day10', 'day14', 'day21', 'month1', 'month3', 'month3p', 'month6t', 'month6', 'month12t', 'month12'], array_column($std['steps'], 'key'), '15 steps: 24-48 h, first wash, crusts, suture/control decision, 14-day photo, shedding, 1/3/6/12-month photos + control tasks');
foreach ($defs as $name => $d) { if (strpos($name, 'eyebrow_aftercare_') === 0) { se_ok(!preg_match('/\{\{\d\}\}\s*$/', $d['body']), $name . ': body does not end with a variable (Meta rule)'); } }
$GLOBALS['se_test']['options'] = [];
se_eq('https://azinasgari.com/tr/recovery', se_journey_aftercare_guide_url((object) ['brand_id' => 1, 'language' => 'tr']), 'Turkish guide link');
se_eq('https://azinasgari.com/fa/recovery', se_journey_aftercare_guide_url((object) ['brand_id' => 1, 'language' => 'fa']), 'Persian guide link');
se_eq('https://azinasgari.com/tr/recovery', se_journey_aftercare_guide_url((object) ['brand_id' => 1, 'language' => 'xx']), 'unknown language falls back to Turkish');
$GLOBALS['se_test']['options']['se_journey_aftercare_guide_url_1'] = 'https://example.com/bakim';
se_eq('https://example.com/bakim', se_journey_aftercare_guide_url((object) ['brand_id' => 1, 'language' => 'tr']), 'brand override wins');
$GLOBALS['se_test']['options']['se_journey_aftercare_guide_url_1'] = 'http://insecure.example';
se_eq('https://azinasgari.com/tr/recovery', se_journey_aftercare_guide_url((object) ['brand_id' => 1, 'language' => 'tr']), 'a non-https override is ignored');
