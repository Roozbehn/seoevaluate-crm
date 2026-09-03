<?php
/**
 * Mesajlar (CRM-M034 inbox query, CRM-M036 contextual actions): bounded,
 * brand-scoped rows with names, exact last-message previews, journey state
 * and attention; chips and search; thread paging with a "load older" cursor;
 * state → 2–4 buttons filtered by capability.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$now = strtotime('2026-09-04 12:00:00');
$ago = function ($s) use ($now) { return date('Y-m-d H:i:s', $now - $s); };

function se_inbox_seed($ago, $now, $extra = 0)
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];
    $db->seed('tblse_brands', [['id' => 1, 'name' => 'A', 'active' => 1], ['id' => 2, 'name' => 'B', 'active' => 1]]);
    $db->seed('tblse_staff_brands', [['staff_id' => 10, 'brand_id' => 1]]);
    $db->seed('tblstaff', [['staffid' => 10, 'firstname' => 'Azin', 'lastname' => 'Asgari']]);
    $db->seed('tblleads', [['id' => 101, 'name' => 'Ayşe Yılmaz', 'phonenumber' => '+90 555 111 22 33'], ['id' => 102, 'name' => 'Berna Kaya', 'phonenumber' => '0555-111-2244']]);
    $convs = [
        ['id' => 1, 'brand_id' => 1, 'wa_user_id' => '905551112233', 'lead_id' => 101, 'assigned_staff' => 10, 'unread_count' => 2, 'last_inbound_at' => $ago(600), 'window_expires_at' => date('Y-m-d H:i:s', $now + 3600)],
        ['id' => 2, 'brand_id' => 1, 'wa_user_id' => '905551112244', 'lead_id' => 102, 'assigned_staff' => 0, 'unread_count' => 0, 'last_inbound_at' => $ago(7200), 'window_expires_at' => date('Y-m-d H:i:s', $now - 3600)],
        ['id' => 3, 'brand_id' => 1, 'wa_user_id' => '905559990000', 'lead_id' => 0, 'assigned_staff' => 0, 'unread_count' => 1, 'last_inbound_at' => $ago(60), 'window_expires_at' => date('Y-m-d H:i:s', $now + 3600)],
        ['id' => 4, 'brand_id' => 2, 'wa_user_id' => '905558880000', 'lead_id' => 0, 'assigned_staff' => 0, 'unread_count' => 5, 'last_inbound_at' => $ago(10), 'window_expires_at' => null],
    ];
    for ($i = 0; $i < $extra; $i++) {
        $convs[] = ['id' => 100 + $i, 'brand_id' => 1, 'wa_user_id' => '90544' . str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'lead_id' => 0, 'assigned_staff' => 0, 'unread_count' => 0, 'last_inbound_at' => $ago(86400 + $i * 60), 'window_expires_at' => null];
    }
    $db->seed('tblse_wa_conversations', $convs);
    $db->seed('tblse_wa_messages', [
        ['id' => 1, 'brand_id' => 1, 'conversation_id' => 1, 'direction' => 'in', 'type' => 'text', 'body' => 'Merhaba', 'date_created' => $ago(900), 'received_at' => $ago(900), 'sent_at' => null, 'origin' => ''],
        ['id' => 2, 'brand_id' => 1, 'conversation_id' => 1, 'direction' => 'out', 'type' => 'text', 'body' => 'Hoş geldiniz, ' . str_repeat('x', 200), 'date_created' => $ago(800), 'received_at' => null, 'sent_at' => $ago(800), 'origin' => 'journey:welcome'],
        ['id' => 3, 'brand_id' => 1, 'conversation_id' => 1, 'direction' => 'in', 'type' => 'image', 'body' => '', 'date_created' => $ago(600), 'received_at' => $ago(600), 'sent_at' => null, 'origin' => ''],
        ['id' => 4, 'brand_id' => 1, 'conversation_id' => 2, 'direction' => 'out', 'type' => 'text', 'body' => 'Teklifiniz hazır', 'date_created' => $ago(7000), 'received_at' => null, 'sent_at' => $ago(7000), 'origin' => 'staff'],
        ['id' => 5, 'brand_id' => 1, 'conversation_id' => 3, 'direction' => 'in', 'type' => 'text', 'body' => 'Fiyat bilgisi alabilir miyim', 'date_created' => $ago(60), 'received_at' => $ago(60), 'sent_at' => null, 'origin' => ''],
    ]);
    $j = function ($id, $conv, $lead, $wa, $state, $age, $extra = []) use ($ago) {
        return array_merge(['id' => $id, 'brand_id' => 1, 'lead_id' => $lead, 'wa_user_id' => $wa, 'wa_conversation_id' => $conv, 'state' => $state,
            'state_changed_at' => $ago($age), 'last_updated' => $ago($age), 'date_created' => $ago($age + 3600), 'automation_state' => 'active', 'automation_changed_at' => null,
            'urgent' => 0, 'reminder_count' => 0, 'assigned_staff' => 0, 'source' => 'organic_whatsapp', 'consultation_appointment_id' => 0, 'procedure_appointment_id' => 0, 'display_name' => ''], $extra);
    };
    $db->seed('tblse_journeys', [
        $j(1, 1, 101, '905551112233', 'ready_for_review', 600),
        $j(2, 2, 102, '905551112244', 'quote_sent', 7200, ['urgent' => 1]),
    ]);
    $db->seed('tblse_journey_quotes', [['id' => 1, 'journey_id' => 2, 'brand_id' => 1, 'version' => 1, 'status' => 'sent', 'sent_at' => $ago(7000), 'valid_until' => date('Y-m-d', $now + 10 * 86400)]]);
    $db->seed('tblse_appointments', []);
    $db->seed('tblse_wa_outbound', []);
    $GLOBALS['se_test']['options'] = [];
    se_authz_reset_cache();

    return $db;
}
$ids = function ($r) { return array_map(function ($x) { return $x['id']; }, $r['rows']); };

/* ======================================================================== */
se_group('Inbox rows: scope, order, names, previews, states');
se_inbox_seed($ago, $now);
se_test_act_as(10, ['se_journey.view', 'se_journey.view_health']);
$r = se_wa_inbox_rows(se_wa_inbox_filters([]), $now);
se_eq([3, 1, 2], $ids($r), 'own brand only, newest inbound first');
$by = []; foreach ($r['rows'] as $row) { $by[$row['id']] = $row; }
se_eq('Ayşe Y.', $by[1]['name'], 'lead name shortened');
se_eq('AY', $by[1]['initials'], 'initials');
se_ok(strpos($by[3]['name'], '•') !== false, 'no lead → masked phone as the name');
se_eq('📷 se_wa_preview_photo', $by[1]['preview'], 'the LAST message (image) is the preview, not an earlier text');
se_eq('se_wa_preview_you: Teklifiniz hazır', $by[2]['preview'], 'outbound preview is prefixed');
se_eq('Fiyat bilgisi alabilir miyim', $by[3]['preview'], 'inbound text preview');
se_eq('ready_for_review', $by[1]['state_label'], 'journey state label (harness returns the raw key)');
se_eq('se_na_new_thread', $by[3]['state_label'], 'no journey → new thread');
se_eq(1, $by[2]['urgent'], 'urgent flag');
se_eq(true, $by[1]['window_open'], 'window open');
se_eq(false, $by[2]['window_open'], 'window closed');
se_eq(2, $by[1]['attention'], 'ready_for_review is a staff item (p2)');
se_eq(0, $by[3]['attention'], 'no journey → no attention priority (unread still counts)');
se_eq(2, $r['counts']['unread'], 'unread thread count');
se_eq(false, $r['has_more'], 'no more pages');

se_group('Inbox rows: chips');
se_eq([3, 1], $ids(se_wa_inbox_rows(se_wa_inbox_filters(['f' => 'unread']), $now)), 'unread chip');
se_eq([1], $ids(se_wa_inbox_rows(se_wa_inbox_filters(['f' => 'me']), $now)), 'me chip');
se_eq([3, 2], $ids(se_wa_inbox_rows(se_wa_inbox_filters(['f' => 'unassigned']), $now)), 'unassigned chip');
se_eq([3, 1, 2], $ids(se_wa_inbox_rows(se_wa_inbox_filters(['f' => 'attention']), $now)), 'attention chip: staff items or unread (journey 2 is urgent → staff item even while the quote waits)');
$db = se_test_db(); $db->where('id', 2)->update('tblse_journeys', ['urgent' => 0]);
se_eq([3, 1], $ids(se_wa_inbox_rows(se_wa_inbox_filters(['f' => 'attention']), $now)), 'without the urgent flag a quote sent 2h ago (patient-owned, no unread) drops out');
se_eq('me', se_wa_inbox_filters(['assigned' => 'me'])['f'], 'legacy ?assigned=me maps to the chip');
se_eq('unassigned', se_wa_inbox_filters(['assigned' => 'none'])['f'], 'legacy ?assigned=none maps');

se_group('Inbox rows: search');
se_eq([2], $ids(se_wa_inbox_rows(se_wa_inbox_filters(['q' => 'berna']), $now)), 'name search');
se_eq([1], $ids(se_wa_inbox_rows(se_wa_inbox_filters(['q' => '111 22 33']), $now)), 'digits match a formatted lead phone');
se_eq([3], $ids(se_wa_inbox_rows(se_wa_inbox_filters(['q' => '9990000']), $now)), 'digits match a wa id with no lead');
se_eq([], $ids(se_wa_inbox_rows(se_wa_inbox_filters(['q' => 'nobody']), $now)), 'no match');

se_group('Inbox rows: scope fail-closed and paging');
se_test_act_as(9999, []);
se_eq([], $ids(se_wa_inbox_rows(se_wa_inbox_filters([]), $now)), 'unmapped staff sees nothing');
se_inbox_seed($ago, $now, 60);
se_test_act_as(10, ['se_journey.view']);
$r = se_wa_inbox_rows(se_wa_inbox_filters([]), $now);
se_eq(50, count($r['rows']), 'page is capped at 50');
se_eq(true, $r['has_more'], 'has_more');
$r2 = se_wa_inbox_rows(se_wa_inbox_filters(['before' => $r['next_before']]), $now);
se_eq(13, count($r2['rows']), 'cursor page has the rest');
se_eq(false, $r2['has_more'], 'and no more after that');
se_eq(0, count(array_intersect($ids($r), $ids($r2))), 'pages do not overlap');

se_group('Thread paging');
$db = se_inbox_seed($ago, $now);
$msgs = [];
for ($i = 1; $i <= 130; $i++) { $msgs[] = ['id' => $i, 'brand_id' => 1, 'conversation_id' => 1, 'direction' => $i % 2 ? 'in' : 'out', 'type' => 'text', 'body' => 'm' . $i, 'date_created' => $ago(20000 - $i * 60), 'received_at' => null, 'sent_at' => null, 'origin' => '']; }
$db->seed('tblse_wa_messages', $msgs);
se_test_act_as(10, []);
$pg = se_wa_thread_page(1);
se_eq(100, count($pg['messages']), 'the newest 100');
se_eq(31, (int) $pg['messages'][0]['id'], 'ascending from #31');
se_eq(130, (int) $pg['messages'][99]['id'], 'to #130');
se_eq(31, $pg['older_before'], 'older cursor = first shown id');
$pg2 = se_wa_thread_page(1, $pg['older_before']);
se_eq(30, count($pg2['messages']), 'previous page has the remaining 30');
se_eq(0, $pg2['older_before'], 'no further page');
se_eq(0, count(se_wa_thread_page(4)['messages']), 'a foreign-brand thread yields nothing');

/* ======================================================================== */
se_group('Contextual actions by state');
$J = function ($state, $extra = []) use ($ago) {
    return (object) array_merge(['id' => 9, 'brand_id' => 1, 'lead_id' => 101, 'state' => $state, 'automation_state' => 'active', 'urgent' => 0, 'wa_conversation_id' => 1,
        'state_changed_at' => $ago(600), 'last_updated' => $ago(600), 'date_created' => $ago(3600), 'reminder_count' => 0, 'consultation_appointment_id' => 0, 'procedure_appointment_id' => 0], $extra);
};
$all = ['view' => true, 'view_health' => true, 'view_photos' => true, 'edit_review' => true, 'approve_quote' => true, 'manage_consultation' => true, 'manage_aftercare' => true, 'export_health' => true, 'manage_templates' => true, 'manage_consent' => true];
$labels = function ($a) { return array_map(function ($x) { return $x['label']; }, $a); };
se_test_act_as(10, ['se_appointments.create']);
$a = se_journey_contextual_actions($J('new_whatsapp_enquiry'), $all, se_journey_next_action($J('new_whatsapp_enquiry'), [], $now));
se_eq('se_na_btn_start', $a[0]['label'], 'new enquiry: next action first');
se_eq('primary', $a[0]['variant'], 'and primary');
$a = se_journey_contextual_actions($J('ready_for_review'), $all, se_journey_next_action($J('ready_for_review'), [], $now));
se_eq(['se_na_btn_review_photos', 'se_journey_request_retake', 'se_journey_decision'], $labels($a), 'review: photos, retake, decision (deduped against the next action)');
$a = se_journey_contextual_actions($J('quote_accepted'), $all, se_journey_next_action($J('quote_accepted'), [], $now));
se_ok(in_array('se_journey_send_book_link', $labels($a), true) && in_array('se_na_btn_book', $labels($a), true), 'quote accepted: booking link + book');
se_ok(count($a) <= 4, 'never more than 4 buttons');
$post = array_values(array_filter($a, function ($x) { return $x['kind'] === 'post'; }));
se_eq('chat', $post[0]['fields']['tab'], 'POST actions carry the tab to return to');
$sales = array_merge($all, ['edit_review' => false, 'approve_quote' => false, 'manage_consultation' => false, 'manage_aftercare' => false, 'view_photos' => false]);
$a = se_journey_contextual_actions($J('ready_for_review'), $sales, se_journey_next_action($J('ready_for_review'), [], $now));
se_eq([], array_values(array_filter($labels($a), function ($l) { return $l !== 'se_na_btn_review_photos'; })), 'a view-only role gets no mutating buttons');
$a = se_journey_contextual_actions($J('photos_requested', ['automation_state' => 'paused_staff']), $all, se_journey_next_action($J('photos_requested', ['automation_state' => 'paused_staff']), [], $now));
se_ok(in_array('se_journey_resume', $labels($a), true), 'paused automation offers Resume');
$a = se_journey_contextual_actions($J('closed_lost'), $all, se_journey_next_action($J('closed_lost'), [], $now));
se_eq(['se_pw_reopen'], $labels($a), 'closed: only reopen');
$html = se_journey_render_actions($a);
se_ok(strpos($html, 'se-btn') !== false && strpos($html, 'se_pw_reopen') !== false, 'renders DS buttons');

/* ======================================================================== */
se_group('Inbox rows: query count is bounded (no N+1 per conversation)');
se_inbox_seed($ago, $now, 60);
se_test_act_as(10, ['se_journey.view']);
$db = se_test_db(); $db->selects = [];
$r = se_wa_inbox_rows(se_wa_inbox_filters([]), $now);
$n50 = array_sum($db->selects);
$tables50 = $db->selects; $db->selects = [];
se_wa_inbox_rows(se_wa_inbox_filters(['before' => $r['next_before']]), $now);
$n13 = array_sum($db->selects);
se_ok($n50 <= 20, "a 50-row page runs a bounded number of SELECTs ({$n50} ≤ 20)");
se_ok(max($tables50) <= 2, 'no table is queried more than twice for one page (per-row lookups would show as 50): ' . json_encode($tables50));
se_ok($n13 <= $n50, "a smaller page never needs more queries (50 rows: {$n50}, 13 rows: {$n13})");
