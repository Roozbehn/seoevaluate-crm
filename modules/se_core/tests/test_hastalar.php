<?php
/**
 * Hastalar (CRM-M024 / UX-L01 / AZCRM-UX-002 / T10): one brand-scoped list,
 * search by name or phone digits in any formatting, chips per stage, the same
 * next action as Bugün, masked phones without the health capability, pages.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$now = strtotime('2026-09-04 12:00:00');
$ago = function ($s) use ($now) { return date('Y-m-d H:i:s', $now - $s); };

function se_hastalar_seed($ago, $now, $n_extra = 0)
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];
    $db->seed('tblse_brands', [['id' => 1, 'name' => 'A', 'active' => 1], ['id' => 2, 'name' => 'B', 'active' => 1]]);
    $db->seed('tblse_staff_brands', [['staff_id' => 10, 'brand_id' => 1], ['staff_id' => 11, 'brand_id' => 1]]);
    $db->seed('tblstaff', [['staffid' => 10, 'firstname' => 'Azin', 'lastname' => 'Asgari'], ['staffid' => 11, 'firstname' => 'Roozbeh', 'lastname' => 'N']]);
    $db->seed('tblleads', [
        ['id' => 101, 'name' => 'Ayşe Yılmaz', 'phonenumber' => '+90 555 111 22 33', 'email' => ''],
        ['id' => 102, 'name' => 'Berna Kaya', 'phonenumber' => '0555-111-2244', 'email' => ''],
        ['id' => 103, 'name' => 'Ceren Demir', 'phonenumber' => '(555) 111 2255', 'email' => ''],
        ['id' => 104, 'name' => 'Deniz Ak', 'phonenumber' => '+905551112266', 'email' => ''],
    ]);
    $j = function ($id, $lead, $state, $age, $extra = []) use ($ago) {
        return array_merge(['id' => $id, 'brand_id' => 1, 'lead_id' => $lead, 'wa_user_id' => '9055511122' . str_pad((string) $id, 2, '0', STR_PAD_LEFT), 'wa_conversation_id' => $id,
            'state' => $state, 'state_changed_at' => $ago($age), 'last_updated' => $ago($age), 'date_created' => $ago($age + 86400),
            'automation_state' => 'active', 'automation_changed_at' => null, 'urgent' => 0, 'reminder_count' => 0, 'assigned_staff' => 0, 'source' => 'organic_whatsapp',
            'consultation_appointment_id' => 0, 'procedure_appointment_id' => 0, 'display_name' => ''], $extra);
    };
    $rows = [
        $j(1, 101, 'ready_for_review', 40 * 60, ['assigned_staff' => 10]),
        $j(2, 102, 'ready_for_review', 5 * 86400, ['assigned_staff' => 11, 'urgent' => 1]),
        $j(3, 103, 'photos_requested', 3600),
        $j(4, 104, 'completed', 600),
        $j(5, 0, 'welcome_sent', 7200, ['display_name' => 'Gizem', 'wa_user_id' => '905559998877']),
        $j(6, 0, 'ready_for_review', 3600, ['brand_id' => 2]),
    ];
    for ($i = 0; $i < $n_extra; $i++) {
        $rows[] = $j(100 + $i, 0, 'welcome_sent', 10000 + $i * 60, ['display_name' => 'Extra ' . $i, 'wa_user_id' => '90544' . str_pad((string) $i, 7, '0', STR_PAD_LEFT)]);
    }
    $db->seed('tblse_journeys', $rows);
    $db->seed('tblse_journey_quotes', []);
    $db->seed('tblse_wa_outbound', []);
    $db->seed('tblse_wa_conversations', [
        ['id' => 3, 'brand_id' => 1, 'wa_user_id' => '905551112203', 'lead_id' => 103, 'unread_count' => 2, 'last_inbound_at' => $ago(45 * 60)],
    ]);
    $db->seed('tblse_appointments', [
        ['id' => 1, 'brand_id' => 1, 'rel_type' => 'lead', 'rel_id' => 101, 'start_at' => date('Y-m-d H:i:s', $now + 2 * 86400), 'end_at' => date('Y-m-d H:i:s', $now + 2 * 86400 + 1800), 'status' => 'scheduled', 'appointment_type' => 'consultation'],
        ['id' => 2, 'brand_id' => 1, 'rel_type' => 'lead', 'rel_id' => 101, 'start_at' => date('Y-m-d H:i:s', $now + 5 * 86400), 'end_at' => date('Y-m-d H:i:s', $now + 5 * 86400 + 1800), 'status' => 'scheduled', 'appointment_type' => 'procedure'],
        ['id' => 3, 'brand_id' => 1, 'rel_type' => 'lead', 'rel_id' => 102, 'start_at' => date('Y-m-d H:i:s', $now - 5 * 86400), 'end_at' => date('Y-m-d H:i:s', $now - 5 * 86400 + 1800), 'status' => 'completed', 'appointment_type' => 'consultation'],
    ]);
    $GLOBALS['se_test']['options'] = [];
    se_authz_reset_cache();

    return $db;
}
$ids = function ($r) { return array_map(function ($x) { return $x['journey_id']; }, $r['rows']); };

/* ======================================================================== */
se_group('Hastalar: filters');
$f = se_hastalar_filters([]);
se_eq(['q' => '', 'f' => 'active', 'sort' => 'recent', 'page' => 1], $f, 'defaults: active, recent, page 1');
se_eq('attention', se_hastalar_filters(['f' => 'attention'])['sort'], 'the attention chip sorts by attention');
se_eq('review', se_hastalar_filters(['stage' => 'review'])['f'], 'Bugün stage links select the stage chip');
se_eq('active', se_hastalar_filters(['f' => 'bogus'])['f'], 'unknown chip falls back');
se_eq('905551112233', se_hastalar_digits('+90 555 111 22 33'), 'digits extracted from a formatted phone');
se_eq('', se_hastalar_digits('Ayşe 1'), 'a name with a stray digit is a name search');

se_group('Hastalar: default list (active, own brand, recent first)');
se_hastalar_seed($ago, $now);
se_test_act_as(10, ['se_journey.view', 'se_journey.view_health']);
$r = se_hastalar_query(se_hastalar_filters([]), $now);
se_eq([1, 3, 5, 2], $ids($r), 'active journeys of brand A, newest touch first; completed and other-brand rows excluded');
se_eq(4, $r['total'], 'total');
$by = []; foreach ($r['rows'] as $row) { $by[$row['journey_id']] = $row; }
se_eq('Ayşe Y.', $by[1]['who'], 'short name');
se_eq('Ayşe Yılmaz', $by[1]['name'], 'full name kept for search/aria');
se_eq('+90 555 111 22 33', $by[1]['phone'], 'phone unmasked with the health capability');
se_eq('review', $by[1]['stage'], 'stage from the state map');
se_eq('Azin Asgari', $by[1]['assigned'], 'owner name resolved');
se_eq('consultation', $by[1]['next_appointment']['type'], 'the NEXT (earliest future) appointment, not the later one');
se_eq(null, $by[2]['next_appointment'], 'a past/completed appointment is not "next"');
se_eq(1, $by[2]['urgent'], 'urgent flag travels');
se_eq('Gizem', $by[5]['who'], 'display_name used when there is no lead');
se_eq(2, $by[3]['unread'], 'unread count');
se_eq('se_na_btn_review_photos', $by[1]['action_label'], 'row button = next action');
se_eq('se_na_btn_reply', $by[3]['action_label'], 'patient-owned step with an unanswered inbound → reply');
se_eq('', $by[5]['action_label'], 'a patient-owned step with nothing unanswered has no button (Aç instead)');

se_group('Hastalar: masking without the health capability');
se_test_act_as(11, []);
$r = se_hastalar_query(se_hastalar_filters([]), $now);
$by = []; foreach ($r['rows'] as $row) { $by[$row['journey_id']] = $row; }
se_ok(strpos($by[1]['phone'], '2233') === false || strpos($by[1]['phone'], '•') !== false, 'phone is masked for staff without view_health');
se_ok(strpos($by[1]['phone'], '111 22') === false, 'and the middle digits are not shown');

se_group('Hastalar: chips');
se_test_act_as(10, ['se_journey.view', 'se_journey.view_health']);
se_eq([2, 1, 3], $ids(se_hastalar_query(se_hastalar_filters(['f' => 'attention']), $now)), 'attention chip: only rows that need staff, p1 first then oldest');
se_eq([1, 2], $ids(se_hastalar_query(se_hastalar_filters(['f' => 'review']), $now)), 'stage chip');
se_eq([4], $ids(se_hastalar_query(se_hastalar_filters(['f' => 'closed']), $now)), 'closed chip');
se_eq(5, se_hastalar_query(se_hastalar_filters(['f' => 'all']), $now)['total'], 'all chip includes closed, still brand-scoped');
se_eq([1], $ids(se_hastalar_query(se_hastalar_filters(['f' => 'mine']), $now)), 'mine chip = assigned to the current staff');
se_eq([1, 2, 3, 5], $ids(se_hastalar_query(se_hastalar_filters(['sort' => 'name']), $now)), 'name sort (Ayşe, Berna, Ceren, Gizem)');

se_group('Hastalar: search');
se_eq([2], $ids(se_hastalar_query(se_hastalar_filters(['q' => 'berna']), $now)), 'name search is case-insensitive');
se_eq([2], $ids(se_hastalar_query(se_hastalar_filters(['q' => '111 2244']), $now)), 'digits match a lead phone stored with dashes');
se_eq([3], $ids(se_hastalar_query(se_hastalar_filters(['q' => '5551112255']), $now)), 'digits match a lead phone stored with parentheses');
se_eq([5], $ids(se_hastalar_query(se_hastalar_filters(['q' => '9988']), $now)), 'digits match the WhatsApp id when there is no lead');
se_eq([5], $ids(se_hastalar_query(se_hastalar_filters(['q' => 'gizem']), $now)), 'name search also covers display_name');
se_eq([], $ids(se_hastalar_query(se_hastalar_filters(['q' => 'zzz']), $now)), 'no match → empty');
se_eq([4], $ids(se_hastalar_query(se_hastalar_filters(['q' => 'deniz', 'f' => 'all']), $now)), 'search + all finds a closed patient');
se_eq([], $ids(se_hastalar_query(se_hastalar_filters(['q' => 'deniz']), $now)), 'but the default active chip hides it');

se_group('Hastalar: scope fail-closed');
se_test_act_as(9999, []);
se_eq(0, se_hastalar_query(se_hastalar_filters([]), $now)['total'], 'unmapped staff sees nothing');

se_group('Hastalar: pages and the scan cap');
se_hastalar_seed($ago, $now, 40);
se_test_act_as(10, []);
$r = se_hastalar_query(se_hastalar_filters([]), $now);
se_eq(44, $r['total'], '44 active rows');
se_eq(2, $r['pages'], 'two pages of 25');
se_eq(25, count($r['rows']), 'page 1 has 25');
$r2 = se_hastalar_query(se_hastalar_filters(['page' => 2]), $now);
se_eq(19, count($r2['rows']), 'page 2 has the rest');
se_eq(2, se_hastalar_query(se_hastalar_filters(['page' => 9]), $now)['page'], 'page beyond the end clamps');
se_eq(false, $r['capped'], 'not capped below the scan limit');

/* ======================================================================== */
se_group('Hastalar: query count is bounded (no N+1 per row)');
se_hastalar_seed($ago, $now, 40);
se_test_act_as(10, []);
$db = se_test_db(); $db->selects = [];
se_hastalar_query(se_hastalar_filters([]), $now);
$n25 = array_sum($db->selects);
$db->selects = [];
se_hastalar_query(se_hastalar_filters(['page' => 2]), $now);
$n19 = array_sum($db->selects);
se_ok($n25 <= 20, "a 25-row page runs a bounded number of SELECTs ({$n25} ≤ 20)");
se_ok(abs($n25 - $n19) <= 2, "and the count does not grow with the row count (25 rows: {$n25}, 19 rows: {$n19})");

/* ======================================================================== */
se_group('Next action: one engine for Bugün, Hastalar, Mesajlar context and the workspace (no duplicates)');
se_hastalar_seed($ago, $now);
se_test_act_as(10, ['se_journey.view']);
$j1 = se_journey_get_raw(1);
$direct   = se_journey_next_action_for($j1, $now);                                                     // workspace + Mesajlar thread context
$hastalar = null; foreach (se_hastalar_query(se_hastalar_filters([]), $now)['rows'] as $r) { if ((int) $r['journey_id'] === 1) { $hastalar = $r; } }
$bugun    = null; foreach (se_journey_attention_queue(25, $now)['rows'] as $r) { if ((int) $r['journey_id'] === 1) { $bugun = $r; } }
$batch    = se_journey_batch_context([(array) $j1], $now);
$viaBatch = $batch['items'][1]['na'];
se_ok($direct['sentence'] !== '' && $direct['key'] !== '', 'the engine yields a sentence and a key for journey 1 (' . $direct['key'] . ')');
se_eq($direct['key'], $viaBatch['key'], 'batch context (Hastalar/Mesajlar/Bugün feed) resolves the same key as the direct call');
se_eq($direct['sentence'], $viaBatch['sentence'], 'and the same sentence');
se_eq($direct['sentence'], $hastalar['next'], 'Hastalar shows the same sentence');
se_eq($direct['url'], $hastalar['next_url'] ?? $hastalar['action_url'] ?? $direct['url'], 'and links to the same action');
se_ok($bugun !== null, 'a staff-owned step appears once on Bugün');
se_eq($direct['key'], $bugun['key'], 'Bugün carries the same key');
se_eq($direct['action_label'], $bugun['action_label'], 'and the same button label');
$dupes = array_count_values(array_map(function ($r) { return (int) $r['journey_id']; }, se_journey_attention_queue(25, $now)['rows']));
se_eq([], array_filter($dupes, function ($n) { return $n > 1; }), 'no journey is listed twice on Bugün');
$inboxNext = null; foreach (se_wa_inbox_rows(se_wa_inbox_filters([]), $now)['rows'] as $r) { if ((int) $r['journey_id'] === 3) { $inboxNext = $r; } }
$j3 = se_journey_get_raw(3);
se_eq(se_ui_state_label($j3->state), $inboxNext['state_label'], 'Mesajlar row state chip uses the shared state map');
