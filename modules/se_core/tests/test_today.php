<?php
/**
 * Bugün (CRM-M023 / UX-D01 / AZCRM-UX-001 / OBS-002): the attention queue is
 * brand-scoped, one row per journey that needs a staff member, sorted by
 * priority then age, capped, and unread threads without a journey surface too.
 * The right column (appointments, unread, Sistem) only shows what needs a hand.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$now = strtotime('2026-09-04 12:00:00');
$ago = function ($s) use ($now) { return date('Y-m-d H:i:s', $now - $s); };

function se_today_seed($ago)
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];
    $db->seed('tblse_brands', [['id' => 1, 'name' => 'A', 'active' => 1], ['id' => 2, 'name' => 'B', 'active' => 1]]);
    $db->seed('tblse_staff_brands', [['staff_id' => 10, 'brand_id' => 1]]);
    $db->seed('tblleads', [
        ['id' => 101, 'name' => 'Ayşe Yılmaz', 'phonenumber' => '+905551112233'],
        ['id' => 102, 'name' => 'Berna Kaya', 'phonenumber' => '+905551112244'],
        ['id' => 103, 'name' => 'Ceren Demir', 'phonenumber' => '+905551112255'],
        ['id' => 104, 'name' => 'Deniz Ak', 'phonenumber' => '+905551112266'],
        ['id' => 105, 'name' => 'Ekin Öz', 'phonenumber' => '+905551112277'],
        ['id' => 106, 'name' => 'Filiz Can', 'phonenumber' => '+905551112288'],
    ]);
    $j = function ($id, $lead, $state, $age, $extra = []) use ($ago) {
        return array_merge(['id' => $id, 'brand_id' => 1, 'lead_id' => $lead, 'wa_user_id' => '90555111' . $id, 'wa_conversation_id' => $id,
            'state' => $state, 'state_changed_at' => $ago($age), 'last_updated' => $ago($age), 'date_created' => $ago($age + 86400),
            'automation_state' => 'active', 'automation_changed_at' => null, 'urgent' => 0, 'reminder_count' => 0,
            'consultation_appointment_id' => 0, 'procedure_appointment_id' => 0, 'display_name' => ''], $extra);
    };
    $db->seed('tblse_journeys', [
        $j(1, 101, 'ready_for_review', 40 * 60),                 // p2, young
        $j(2, 102, 'ready_for_review', 5 * 86400),               // p1 (overdue)
        $j(3, 103, 'photos_requested', 3600),                    // patient-owned, no unread → hidden
        $j(4, 104, 'photos_requested', 3600),                    // patient-owned but unread > 30 min → reply row p3
        $j(5, 105, 'under_review', 2 * 86400),                   // p2, older than #1
        $j(6, 106, 'completed', 600),                            // terminal → never
        $j(7, 0, 'ready_for_review', 3600, ['brand_id' => 2]),   // other brand → invisible to staff 10
    ]);
    $db->seed('tblse_journey_quotes', []);
    $db->seed('tblse_appointments', []);
    $db->seed('tblse_wa_outbound', []);
    $db->seed('tblse_wa_conversations', [
        ['id' => 4, 'brand_id' => 1, 'wa_user_id' => '905551114', 'lead_id' => 104, 'unread_count' => 2, 'last_inbound_at' => $ago(45 * 60)],
        ['id' => 3, 'brand_id' => 1, 'wa_user_id' => '905551113', 'lead_id' => 103, 'unread_count' => 0, 'last_inbound_at' => $ago(600)],
        ['id' => 90, 'brand_id' => 1, 'wa_user_id' => '905559990000', 'lead_id' => 0, 'unread_count' => 1, 'last_inbound_at' => $ago(20 * 60)],   // no journey
        ['id' => 91, 'brand_id' => 2, 'wa_user_id' => '905559990001', 'lead_id' => 0, 'unread_count' => 3, 'last_inbound_at' => $ago(20 * 60)],   // other brand
    ]);
    $GLOBALS['se_test']['options'] = [];
    se_authz_reset_cache();

    return $db;
}

/* ======================================================================== */
se_group('Bugün queue: scope, filtering, ordering');

se_today_seed($ago);
se_test_act_as(10, []);
$q = se_journey_attention_queue(25, $now);
$keys = array_map(function ($r) { return $r['journey_id'] . ':' . $r['key']; }, $q['rows']);
se_eq(['2:review', '5:decision', '1:review', '4:unread', '0:unread_no_journey'], $keys, 'priority first, then oldest; patient-owned rows only with an unanswered inbound; terminal and other-brand rows never');
se_eq(5, $q['total'], 'total counts every row before the cap');
se_eq(['p1' => 1, 'p2' => 2, 'p3' => 2], $q['counts'], 'priority counts');
se_eq('Berna K.', $q['rows'][0]['who'], 'names are shortened (first name + initial)');
se_eq(true, $q['rows'][0]['hot'], 'a p1 row is hot');
se_eq(false, $q['rows'][3]['hot'], 'a reply row is not hot');
se_eq('se_na_btn_reply', $q['rows'][3]['action_label'], 'the reply row has the reply button');
se_ok(strpos($q['rows'][3]['url'], 'se_whatsapp/conversation/4') !== false, 'and it deep-links the thread');
se_eq(2, $q['rows'][3]['unread'], 'unread count travels with the row');
se_eq('se_na_new_thread', $q['rows'][4]['why'], 'a thread with no journey is labelled as new');
se_ok(strpos($q['rows'][4]['who'], '905559990000') === false && $q['rows'][4]['who'] !== '', 'phone is masked when there is no name');
foreach ($q['rows'] as $r) {
    se_ok($r['action_label'] !== '' && $r['url'] !== '', 'every row has exactly one action (' . $r['key'] . ')');
    se_ok(!empty($r['aria']), 'every row button has an accessible name');
}

se_group('Bugün queue: cap and empty cases');
$q2 = se_journey_attention_queue(2, $now);
se_eq(2, count($q2['rows']), 'the cap limits rows');
se_eq(5, $q2['total'], 'but not the total');
se_eq('2:review', $q2['rows'][0]['journey_id'] . ':' . $q2['rows'][0]['key'], 'and the first row is still the most urgent');

se_test_act_as(9999, []);   // unmapped staff: fail-closed scope
$q3 = se_journey_attention_queue(25, $now);
se_eq([], $q3['rows'], 'an unmapped staff member sees an empty queue');
se_eq(0, $q3['total'], 'with total 0');

se_group('Bugün: an unanswered inbound younger than the window stays patient-owned');
$db = se_today_seed($ago);
$db->where('id', 4)->update('tblse_wa_conversations', ['last_inbound_at' => $ago(5 * 60)]);
se_test_act_as(10, []);
$q4 = se_journey_attention_queue(25, $now);
$ids = array_map(function ($r) { return $r['journey_id']; }, $q4['rows']);
se_eq(false, in_array(4, $ids, true), 'journey #4 (photos requested, inbound 5 min ago) is not yet a staff item');

/* ======================================================================== */
se_group('Bugün: stage counts (active journeys only, brand-scoped)');
se_today_seed($ago);
se_test_act_as(10, []);
$stages = se_journey_stage_counts();
se_eq(array_values(se_ui_stages_list()), array_keys($stages), 'pills follow the stage order');
se_eq(3, $stages['review'] ?? -1, 'ready_for_review ×2 + under_review = 3 in İnceleme');
se_eq(2, $stages['evaluation'] ?? -1, 'photos_requested ×2 in Değerlendirme');
se_eq(5, array_sum($stages), 'completed and other-brand journeys are not counted');

/* ======================================================================== */
se_group('Bugün: right column');
$db = se_today_seed($ago);
$db->seed('tblse_appointments', [
    ['id' => 1, 'brand_id' => 1, 'rel_type' => 'lead', 'rel_id' => 101, 'start_at' => date('Y-m-d') . ' 14:30:00', 'end_at' => date('Y-m-d') . ' 15:00:00', 'status' => 'scheduled', 'appointment_type' => 'consultation', 'title' => 'x'],
    ['id' => 2, 'brand_id' => 1, 'rel_type' => 'lead', 'rel_id' => 102, 'start_at' => date('Y-m-d') . ' 09:00:00', 'end_at' => date('Y-m-d') . ' 09:30:00', 'status' => 'cancelled', 'appointment_type' => 'consultation', 'title' => 'x'],
    ['id' => 3, 'brand_id' => 1, 'rel_type' => 'lead', 'rel_id' => 103, 'start_at' => date('Y-m-d', strtotime('+1 day')) . ' 09:00:00', 'end_at' => date('Y-m-d', strtotime('+1 day')) . ' 09:30:00', 'status' => 'scheduled', 'appointment_type' => 'procedure', 'title' => 'x'],
    ['id' => 4, 'brand_id' => 1, 'rel_type' => 'lead', 'rel_id' => 104, 'start_at' => date('Y-m-d') . ' 10:00:00', 'end_at' => date('Y-m-d') . ' 11:00:00', 'status' => 'confirmed', 'appointment_type' => 'procedure', 'title' => 'x'],
    ['id' => 5, 'brand_id' => 2, 'rel_type' => 'lead', 'rel_id' => 105, 'start_at' => date('Y-m-d') . ' 10:00:00', 'end_at' => date('Y-m-d') . ' 11:00:00', 'status' => 'scheduled', 'appointment_type' => 'procedure', 'title' => 'x'],
]);
se_test_act_as(10, []);
$appts = se_dashboard_today_appointments();
se_eq([4, 1], array_map(function ($a) { return (int) $a['id']; }, $appts), 'today only, not cancelled, own brand, ordered by time');
se_eq('Deniz Ak', $appts[0]['patient'], 'patient name resolved');
se_eq('procedure', $appts[0]['type'], 'type key resolved');

$unread = se_dashboard_unread_threads(5);
se_eq([90, 4], array_map(function ($u) { return (int) $u['id']; }, $unread), 'unread threads newest first, own brand only');
se_eq('Deniz Ak', $unread[1]['patient'], 'with the lead name');
se_eq(1, count(se_dashboard_unread_threads(1)), 'limit honoured');

se_group('Bugün / Mesajlar: Instagram threads are counted and listed next to WhatsApp (UX-W09 / CRM-M038)');
$db->seed('tblse_ig_conversations', [
    ['id' => 501, 'brand_id' => 1, 'ig_account_id' => 'IGA1', 'igsid' => '17841400000000001', 'lead_id' => 0, 'assigned_staff' => 0, 'unread_count' => 2, 'last_inbound_at' => date('Y-m-d H:i:s'), 'window_expires_at' => null, 'state' => 'open'],
    ['id' => 502, 'brand_id' => 2, 'ig_account_id' => 'IGA2', 'igsid' => '17841400000000002', 'lead_id' => 0, 'assigned_staff' => 0, 'unread_count' => 1, 'last_inbound_at' => date('Y-m-d H:i:s'), 'window_expires_at' => null, 'state' => 'open'],
    ['id' => 503, 'brand_id' => 1, 'ig_account_id' => 'IGA1', 'igsid' => '17841400000000003', 'lead_id' => 0, 'assigned_staff' => 0, 'unread_count' => 0, 'last_inbound_at' => date('Y-m-d H:i:s'), 'window_expires_at' => null, 'state' => 'open'],
]);
se_test_act_as(10, ['se_whatsapp.view', 'se_instagram.view']);
$unread = se_dashboard_unread_threads(5);
$ig = array_values(array_filter($unread, function ($u) { return $u['channel'] === 'instagram'; }));
se_eq(1, count($ig), 'the own-brand unread Instagram thread is in the Bugün list (read ones and other brands are not)');
se_eq(['/admin/se_instagram/se_instagram/conversation/501', 2], [$ig[0]['url'], (int) $ig[0]['unread_count']], 'it links to the Instagram thread');
se_ok(strpos($ig[0]['contact'], '17841400000000001') === false, 'the Instagram id is redacted in the card');
se_eq(3, count($unread), 'WhatsApp rows keep their place (2 + 1)');
se_eq(3, se_clinic_tabbar_count('unread'), 'the tab-bar badge counts both channels');
$ch = se_messages_channels('whatsapp');
se_eq([['whatsapp', true, 2], ['instagram', false, 1]], array_map(function ($c) { return [$c['key'], $c['on'], $c['unread']]; }, $ch), 'channel switch: WhatsApp on, Instagram with its unread count');
se_ok(strpos(se_messages_channel_switch('instagram'), 'aria-current="true">Instagram') !== false, 'the Instagram inbox marks its own chip');
se_test_act_as(10, ['se_whatsapp.view']);
se_eq([], se_messages_channels('whatsapp'), 'no switch for a staff member who cannot see Instagram');
se_eq(2, se_clinic_tabbar_count('unread'), 'and the badge counts WhatsApp only');
se_eq(0, count(array_filter(se_dashboard_unread_threads(5), function ($u) { return $u['channel'] === 'instagram'; })), 'no Instagram rows either');
se_test_act_as(10, []);
$db->seed('tblse_ig_conversations', []);

se_group('Bugün: Sistem card lists only what needs a hand');
$GLOBALS['se_test']['options'] = ['last_cron_run' => (string) (time() - 120)];
$db->seed('tblse_outbox', []);
$db->seed('tblse_wa_templates', []);
$db->seed('tblse_consent_text', []);
$sys = se_dashboard_system_card();
se_ok(is_array($sys['alerts']), 'alerts is a list');
$texts = array_map(function ($a) { return $a['text']; }, $sys['alerts']);
se_eq(false, in_array('se_sys_cron_never', $texts, true), 'a fresh cron does not raise the cron alert');
se_ok(strpos($sys['summary'], 'Cron ✓') !== false, 'the summary line says cron is fine');

$GLOBALS['se_test']['options'] = ['last_cron_run' => (string) (time() - 2 * 3600)];
$sys = se_dashboard_system_card();
$texts = array_map(function ($a) { return $a['text']; }, $sys['alerts']);
se_ok((bool) preg_grep('/^se_sys_cron_stale/', $texts), 'a 2-hour-old cron raises the stale alert');
se_eq('danger', $sys['alerts'][0]['tone'], 'with danger tone');
se_ok(!empty($sys['alerts'][0]['href']), 'and a place to go');

$GLOBALS['se_test']['options'] = [];
$sys = se_dashboard_system_card();
$texts = array_map(function ($a) { return $a['text']; }, $sys['alerts']);
se_eq(true, in_array('se_sys_cron_never', $texts, true), 'no cron ever → the never alert');
