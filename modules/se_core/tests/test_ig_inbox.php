<?php
/**
 * Mesajlar · Instagram (UX-W09 / CRM-M038): the Instagram inbox uses the same
 * page model as WhatsApp — bounded, brand-scoped rows with names, previews,
 * journey state through the lead, chips, search, cursor paging and thread
 * paging; and the journey context accepts a lead-found journey.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$now = strtotime('2026-09-04 12:00:00');
$ago = function ($s) use ($now) { return date('Y-m-d H:i:s', $now - $s); };

function se_ig_inbox_seed($ago, $now, $extra = 0)
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];
    $db->seed('tblse_brands', [['id' => 1, 'name' => 'A', 'active' => 1], ['id' => 2, 'name' => 'B', 'active' => 1]]);
    $db->seed('tblse_staff_brands', [['staff_id' => 10, 'brand_id' => 1]]);
    $db->seed('tblstaff', [['staffid' => 10, 'firstname' => 'Azin', 'lastname' => 'Asgari']]);
    $db->seed('tblleads', [['id' => 101, 'name' => 'Ayşe Yılmaz', 'phonenumber' => '+90 555 111 22 33'], ['id' => 102, 'name' => 'Berna Kaya', 'phonenumber' => '0555-111-2244']]);
    $convs = [
        ['id' => 1, 'brand_id' => 1, 'ig_account_id' => 'IGA1', 'igsid' => '17841400000001111', 'lead_id' => 101, 'assigned_staff' => 10, 'unread_count' => 2, 'last_inbound_at' => $ago(600), 'window_expires_at' => date('Y-m-d H:i:s', $now + 3600), 'referral_ad_id' => 'AD77', 'referral_source' => 'ADS', 'state' => 'open'],
        ['id' => 2, 'brand_id' => 1, 'ig_account_id' => 'IGA1', 'igsid' => '17841400000002222', 'lead_id' => 102, 'assigned_staff' => 0, 'unread_count' => 0, 'last_inbound_at' => $ago(7200), 'window_expires_at' => date('Y-m-d H:i:s', $now - 3600), 'referral_ad_id' => null, 'referral_source' => null, 'state' => 'open'],
        ['id' => 3, 'brand_id' => 1, 'ig_account_id' => 'IGA1', 'igsid' => '17841400000003333', 'lead_id' => 0, 'assigned_staff' => 0, 'unread_count' => 1, 'last_inbound_at' => $ago(60), 'window_expires_at' => date('Y-m-d H:i:s', $now + 3600), 'referral_ad_id' => null, 'referral_source' => null, 'state' => 'open'],
        ['id' => 4, 'brand_id' => 2, 'ig_account_id' => 'IGA2', 'igsid' => '17841400000004444', 'lead_id' => 0, 'assigned_staff' => 0, 'unread_count' => 5, 'last_inbound_at' => $ago(10), 'window_expires_at' => null, 'referral_ad_id' => null, 'referral_source' => null, 'state' => 'open'],
    ];
    for ($i = 0; $i < $extra; $i++) {
        $convs[] = ['id' => 100 + $i, 'brand_id' => 1, 'ig_account_id' => 'IGA1', 'igsid' => '1784140009' . str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'lead_id' => 0, 'assigned_staff' => 0, 'unread_count' => 0, 'last_inbound_at' => $ago(86400 + $i * 60), 'window_expires_at' => null, 'referral_ad_id' => null, 'referral_source' => null, 'state' => 'open'];
    }
    $db->seed('tblse_ig_conversations', $convs);
    $db->seed('tblse_ig_messages', [
        ['id' => 1, 'brand_id' => 1, 'conversation_id' => 1, 'mid' => 'm1', 'direction' => 'in', 'type' => 'text', 'body' => 'Merhaba', 'date_created' => $ago(900), 'received_at' => $ago(900), 'sent_at' => null, 'source' => 'customer'],
        ['id' => 2, 'brand_id' => 1, 'conversation_id' => 1, 'mid' => 'm2', 'direction' => 'out', 'type' => 'text', 'body' => 'Hoş geldiniz, ' . str_repeat('x', 200), 'date_created' => $ago(800), 'received_at' => null, 'sent_at' => $ago(800), 'source' => 'crm_api'],
        ['id' => 3, 'brand_id' => 1, 'conversation_id' => 1, 'mid' => 'm3', 'direction' => 'in', 'type' => 'image', 'body' => '', 'date_created' => $ago(600), 'received_at' => $ago(600), 'sent_at' => null, 'source' => 'customer'],
        ['id' => 4, 'brand_id' => 1, 'conversation_id' => 2, 'mid' => 'm4', 'direction' => 'out', 'type' => 'text', 'body' => 'Teklifiniz hazır', 'date_created' => $ago(7000), 'received_at' => null, 'sent_at' => $ago(7000), 'source' => 'crm_api'],
        ['id' => 5, 'brand_id' => 1, 'conversation_id' => 3, 'mid' => 'm5', 'direction' => 'in', 'type' => 'text', 'body' => 'Fiyat bilgisi alabilir miyim', 'date_created' => $ago(60), 'received_at' => $ago(60), 'sent_at' => null, 'source' => 'customer'],
    ]);
    $j = function ($id, $lead, $state, $age, $extra = []) use ($ago) {
        return array_merge(['id' => $id, 'brand_id' => 1, 'lead_id' => $lead, 'wa_user_id' => '', 'wa_conversation_id' => 0, 'state' => $state,
            'state_changed_at' => $ago($age), 'last_updated' => $ago($age), 'date_created' => $ago($age + 3600), 'automation_state' => 'active', 'automation_changed_at' => null,
            'urgent' => 0, 'reminder_count' => 0, 'assigned_staff' => 0, 'source' => 'instagram', 'consultation_appointment_id' => 0, 'procedure_appointment_id' => 0, 'display_name' => ''], $extra);
    };
    $db->seed('tblse_journeys', [$j(1, 101, 'ready_for_review', 600), $j(2, 102, 'quote_sent', 7200, ['urgent' => 1])]);
    $db->seed('tblse_journey_quotes', [['id' => 1, 'journey_id' => 2, 'brand_id' => 1, 'version' => 1, 'status' => 'sent', 'sent_at' => $ago(7000), 'valid_until' => date('Y-m-d', $now + 10 * 86400)]]);
    $db->seed('tblse_appointments', []);
    $db->seed('tblse_wa_outbound', []);
    $db->seed('tblse_wa_conversations', []);
    $GLOBALS['se_test']['options'] = [];
    se_authz_reset_cache();

    return $db;
}
$ids = function ($r) { return array_map(function ($x) { return $x['id']; }, $r['rows']); };

/* ======================================================================== */
se_group('Instagram inbox rows: scope, order, names, previews, states (same model as WhatsApp)');
$db = se_ig_inbox_seed($ago, $now);
se_test_act_as(10, ['se_journey.view']);
$r = se_ig_inbox_rows(se_ig_inbox_filters([]), $now);
se_eq([3, 1, 2], $ids($r), 'own-brand threads newest first; the other brand is invisible');
$by = []; foreach ($r['rows'] as $x) { $by[$x['id']] = $x; }
se_eq('Ayşe Y.', $by[1]['name'], 'lead name, shortened');
se_eq('••••3333', $by[3]['name'], 'no lead → redacted Instagram id (never the raw igsid)');
se_eq('📷 se_ig_preview_photo', $by[1]['preview'], 'photo preview');
se_eq('se_ig_preview_you: Teklifiniz hazır', $by[2]['preview'], 'outbound preview prefixed');
se_eq('Fiyat bilgisi alabilir miyim', $by[3]['preview'], 'plain text preview');
se_eq(['ready_for_review', 'quote_sent', ''], [$by[1]['state'], $by[2]['state'], $by[3]['state']], 'journey state resolved THROUGH THE LEAD; none without a lead');
se_eq('se_na_new_thread', $by[3]['state_label'], 'a thread without a journey is labelled as new');
se_eq([1, 0], [$by[2]['urgent'], $by[1]['urgent']], 'urgent flag carried');
se_eq(['AD77', ''], [$by[1]['ad'], $by[2]['ad']], 'ad referral carried for the list marker');
se_eq([true, false], [$by[1]['window_open'], $by[2]['window_open']], 'window state per row');
se_eq(2, $r['counts']['unread'], 'unread thread count (own brand)');
se_eq('/admin/se_instagram/se_instagram/inbox?c=1', $by[1]['url'], 'rows link into the same page');
se_ok(strpos($by[3]['name'] . $by[3]['preview'] . $by[3]['state_label'], '17841400000003333') === false, 'the raw Instagram id never appears in the displayed name');

/* ======================================================================== */
se_group('Instagram inbox rows: chips and search');
se_eq([3, 1], $ids(se_ig_inbox_rows(se_ig_inbox_filters(['f' => 'unread']), $now)), 'unread chip');
se_eq([1], $ids(se_ig_inbox_rows(se_ig_inbox_filters(['f' => 'me']), $now)), 'assigned to me');
se_eq([3, 2], $ids(se_ig_inbox_rows(se_ig_inbox_filters(['f' => 'unassigned']), $now)), 'unassigned');
se_eq('me', se_ig_inbox_filters(['assigned' => 'me'])['f'], 'legacy ?assigned=me maps to the chip');
se_eq('all', se_ig_inbox_filters(['f' => 'bogus'])['f'], 'unknown chip → all');
se_eq([1], $ids(se_ig_inbox_rows(se_ig_inbox_filters(['q' => 'ayşe']), $now)), 'search by name');
se_eq([2], $ids(se_ig_inbox_rows(se_ig_inbox_filters(['q' => '555 111 2244']), $now)), 'search by phone digits in any format');
se_eq([3], $ids(se_ig_inbox_rows(se_ig_inbox_filters(['q' => '3333']), $now)), 'search by the Instagram id tail');
se_eq([], $ids(se_ig_inbox_rows(se_ig_inbox_filters(['q' => 'nobody']), $now)), 'no match → empty');

/* ======================================================================== */
se_group('Instagram inbox rows: scope fail-closed, paging, bounded queries');
se_test_act_as(9999, []);
se_eq([], $ids(se_ig_inbox_rows(se_ig_inbox_filters([]), $now)), 'unmapped staff sees nothing');
se_ig_inbox_seed($ago, $now, 60);
se_test_act_as(10, ['se_journey.view']);
$db = se_test_db(); $db->selects = [];
$r = se_ig_inbox_rows(se_ig_inbox_filters([]), $now);
$n50 = array_sum($db->selects); $t50 = $db->selects;
se_eq(50, count($r['rows']), 'page is capped at 50');
se_eq(true, $r['has_more'], 'has_more');
$r2 = se_ig_inbox_rows(se_ig_inbox_filters(['before' => $r['next_before']]), $now);
se_eq(13, count($r2['rows']), 'cursor page has the rest');
se_eq(false, $r2['has_more'], 'and no more after that');
se_eq(0, count(array_intersect($ids($r), $ids($r2))), 'pages do not overlap');
se_ok($n50 <= 20 && max($t50) <= 2, 'bounded queries, no table more than twice for a 50-row page: ' . json_encode($t50));

/* ======================================================================== */
se_group('Instagram thread paging');
se_ig_inbox_seed($ago, $now);
$db = se_test_db();
$msgs = [];
for ($i = 1; $i <= 130; $i++) { $msgs[] = ['id' => $i, 'brand_id' => 1, 'conversation_id' => 1, 'mid' => 'x' . $i, 'direction' => $i % 2 ? 'in' : 'out', 'type' => 'text', 'body' => 'm' . $i, 'date_created' => $ago(20000 - $i * 10), 'received_at' => null, 'sent_at' => null, 'source' => null]; }
$db->seed('tblse_ig_messages', $msgs);
$p1 = se_ig_thread_page(1);
se_eq(100, count($p1['messages']), 'newest 100');
se_eq([31, 130], [(int) $p1['messages'][0]['id'], (int) $p1['messages'][99]['id']], 'ascending for display');
se_eq(31, $p1['older_before'], 'cursor to load older');
$p2 = se_ig_thread_page(1, $p1['older_before']);
se_eq([30, 1, 0], [count($p2['messages']), (int) $p2['messages'][0]['id'], $p2['older_before']], 'the older page ends the chain');

/* ======================================================================== */
se_group('Instagram thread context: journey through the lead, no WhatsApp start action');
se_ig_inbox_seed($ago, $now);
se_test_act_as(10, ['se_journey.view', 'se_journey.edit_review'], true);
$conv = (object) ['id' => 1, 'brand_id' => 1, 'igsid' => '17841400000001111', 'lead_id' => 101];
$j = se_ig_journey_for($conv);
se_eq(1, (int) ($j->id ?? 0), 'the journey is found through the lead');
$html = se_journey_conversation_context($conv, ['journey' => $j, 'channel' => 'ig']);
se_ok(strpos($html, 'Ayşe Y.') !== false && strpos($html, 'se_ui_next_action') !== false, 'context shows the patient and the next step');
se_eq(null, se_ig_journey_for((object) ['id' => 3, 'brand_id' => 1, 'igsid' => 'x', 'lead_id' => 0]), 'no lead → no journey');
$GLOBALS['se_test']['options']['se_journey_enabled_1'] = 1;
$none = se_journey_conversation_context((object) ['id' => 3, 'brand_id' => 1, 'igsid' => '17841400000003333', 'lead_id' => 0], ['journey' => null, 'channel' => 'ig']);
se_ok(strpos($none, 'se_na_new_thread') !== false, 'a thread without a journey is shown as new: ' . mb_substr($none, 0, 160));
se_ok(strpos($none, 'start_conversation') === false, 'the WhatsApp "start evaluation" action is NOT offered on an Instagram thread');
