<?php
/**
 * WhatsApp template mirror: Graph → tblse_wa_templates sync, status webhooks
 * routed by WABA id, the composer's approved-only view, and the send-time
 * language lookup. Network-free: the fetcher seam returns fixtures.
 *
 * Regression: production showed "No approved templates for this brand" while
 * WhatsApp Manager listed two APPROVED templates — nothing ever wrote the
 * mirror table, and message_template_status_update webhooks were parked as
 * "unknown phone_number_id".
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function se_test_seed_wa_templates()
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];

    $db->seed('tblse_brands', [
        ['id' => 1, 'name' => 'Brand A', 'active' => 1],
        ['id' => 2, 'name' => 'Brand B', 'active' => 1],
    ]);
    $db->seed('tblse_wa_numbers', [
        ['id' => 1, 'brand_id' => 1, 'waba_id' => 'WABA1', 'phone_number_id' => 'PN1', 'state' => 'active', 'token_option_ref' => 'wa_token'],
        ['id' => 2, 'brand_id' => 2, 'waba_id' => null,    'phone_number_id' => 'PN2', 'state' => 'active', 'token_option_ref' => 'wa_token'],
    ]);
    $db->seed('tblse_wa_templates', []);
    $db->seed('tblse_wa_webhook_events', []);
    $db->seed('tblse_wa_conversations', [
        ['id' => 901, 'brand_id' => 1, 'phone_number_id' => 'PN1', 'wa_user_id' => 'U1',
         'lead_id' => 0, 'assigned_staff' => 0, 'unread_count' => 0, 'state' => 'open',
         'window_expires_at' => date('Y-m-d H:i:s', time() - 3600)],   // closed window
    ]);
    $db->seed('tblse_wa_outbound', []);
    $db->seed('tblse_wa_messages', []);

    $GLOBALS['se_test']['options'] = [];
    $GLOBALS['SE_WA_TRANSPORT'] = null;
    $GLOBALS['SE_WA_TEMPLATE_FETCHER'] = null;
}

se_test_seed_wa_templates();
se_test_act_as(10, ['se_whatsapp.view', 'se_whatsapp.create'], true);

/* The exact shape WhatsApp Manager returned for the production WABA. */
$fixture = [
    ['name' => 'azin_reengagement_tr', 'language' => 'tr', 'status' => 'APPROVED', 'category' => 'MARKETING',
     'components' => [
         ['type' => 'BODY', 'text' => 'Merhaba {{1}}, randevunuz {{2}} için hazır.'],
         ['type' => 'FOOTER', 'text' => 'Azin Asgari'],
     ], 'quality_score' => ['score' => 'UNKNOWN']],
    ['name' => 'website_submit_form_wp_number_verify', 'language' => 'en', 'status' => 'APPROVED',
     'category' => 'AUTHENTICATION',
     'components' => [['type' => 'BODY', 'text' => '{{1}} is your verification code.'], ['type' => 'BUTTONS']]],
    ['name' => 'not_yet', 'language' => 'tr', 'status' => 'PENDING', 'category' => 'MARKETING',
     'components' => [['type' => 'BODY', 'text' => 'Bekliyor']]],
    ['language' => 'tr', 'status' => 'APPROVED'],   // no name: skipped, never inserted
];

/* --- parsing ------------------------------------------------------------- */
$row = se_wa_parse_template(1, $fixture[0]);
se_eq('azin_reengagement_tr', $row['name'], 'name carried');
se_eq('tr', $row['language'], 'language carried');
se_eq('approved', $row['approval_state'], 'Meta status is stored lower-case so the composer filter matches');
se_eq('MARKETING', $row['category'], 'category upper-case');
se_eq('1,2', $row['variables'], 'positional body placeholders extracted');
se_eq('unknown', $row['quality_state'], 'quality score mirrored');
se_ok(strpos((string) $row['body'], 'Merhaba') === 0, 'BODY text mirrored');
se_eq(null, se_wa_parse_template(1, $fixture[3]), 'a template without a name is rejected');

/* --- before sync: exactly the production symptom ------------------------- */
se_eq([], se_wa_approved_templates(1), 'empty mirror => "no approved templates"');
se_eq('WABA1', se_wa_waba_for_brand(1), 'brand 1 resolves its WABA from the numbers table');
se_eq('', se_wa_waba_for_brand(2), 'a number without a WABA id cannot sync');

$r = se_wa_sync_templates(2);
se_eq(false, $r['ok'], 'sync refuses a brand without a WABA');
se_eq('no_waba', $r['reason'], 'and names the reason');

/* --- fetch failure is reported, not swallowed ---------------------------- */
se_wa_register_template_fetcher(function ($waba) { return ['ok' => false, 'templates' => [], 'error' => 'graph HTTP 401']; });
$r = se_wa_sync_templates(1);
se_eq(false, $r['ok'], 'a failed fetch fails the sync');
se_eq('graph HTTP 401', $r['reason'], 'with the sanitised provider reason');
se_eq('graph HTTP 401', get_option('se_wa_templates_last_error_1'), 'and records it for the readiness page');
se_eq([], se_wa_approved_templates(1), 'a failed fetch does not touch the mirror');

/* --- successful sync ----------------------------------------------------- */
$calls = [];
se_wa_register_template_fetcher(function ($waba) use (&$calls, $fixture) {
    $calls[] = $waba;
    return ['ok' => true, 'templates' => $fixture, 'error' => ''];
});
$r = se_wa_sync_templates(1);
se_eq(true, $r['ok'], 'sync succeeds');
se_eq(['WABA1'], $calls, 'the fetcher is asked for the brand\'s WABA, nothing else');
se_eq(3, $r['inserted'], 'three usable templates inserted');
se_eq(2, $r['approved'], 'two of them approved');
se_eq(0, $r['removed'], 'nothing removed on a first pull');
se_eq('', get_option('se_wa_templates_last_error_1'), 'the previous error is cleared');
se_ok(get_option('se_wa_templates_synced_at_1') !== '', 'sync time recorded');

$approved = se_wa_approved_templates(1);
se_eq(2, count($approved), 'the composer now offers exactly the APPROVED templates');
se_eq('azin_reengagement_tr', $approved[0]['name'], 'ordered by name');
se_eq('website_submit_form_wp_number_verify', $approved[1]['name'], 'pending one is not offered');
se_eq([], se_wa_approved_templates(2), 'brand 2 sees none of brand 1\'s templates');

/* Re-sync is idempotent: same rows, no duplicates. */
$r = se_wa_sync_templates(1);
se_eq(0, $r['inserted'], 'second pull inserts nothing');
se_eq(3, $r['unchanged'], 'all three unchanged');
se_eq(3, count(se_test_db()->rows('tblse_wa_templates')), 'unique (brand,name,language) respected');

/* A template Meta no longer lists is marked deleted, never dropped. */
se_wa_register_template_fetcher(function ($waba) use ($fixture) {
    return ['ok' => true, 'templates' => [$fixture[0], $fixture[2]], 'error' => ''];
});
$r = se_wa_sync_templates(1);
se_eq(1, $r['removed'], 'the vanished template is flagged');
se_eq(1, count(se_wa_approved_templates(1)), 'and no longer offered');
se_eq(3, count(se_test_db()->rows('tblse_wa_templates')), 'but its row is kept for history');

/* --- status webhooks route by WABA, not phone_number_id ------------------ */
$evt = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
    'id' => 'WABA1',
    'changes' => [['field' => 'message_template_status_update', 'value' => [
        'event' => 'APPROVED', 'message_template_id' => 123,
        'message_template_name' => 'not_yet', 'message_template_language' => 'tr',
    ]]],
]]]);
$parked = null;
try { se_wa_process_event(['payload' => $evt]); } catch (SeWaPermanentError $e) { $parked = $e->getMessage(); }
se_eq(null, $parked, 'a template status webhook is no longer parked as unknown phone_number_id');
$names = array_map(function ($t) { return $t['name']; }, se_wa_approved_templates(1));
sort($names);
se_eq(['azin_reengagement_tr', 'not_yet'], $names, 'the pushed approval is applied in place');

$evt2 = json_encode(['entry' => [['id' => 'WABA1', 'changes' => [['field' => 'message_template_status_update',
    'value' => ['event' => 'REJECTED', 'message_template_name' => 'azin_reengagement_tr', 'message_template_language' => 'tr']]]]]]);
se_wa_process_event(['payload' => $evt2]);
se_eq(['not_yet'], array_map(function ($t) { return $t['name']; }, se_wa_approved_templates(1)),
    'a rejection removes the template from the composer immediately');

$evt3 = json_encode(['entry' => [['id' => 'WABA1', 'changes' => [['field' => 'message_template_status_update',
    'value' => ['event' => 'APPROVED', 'message_template_name' => 'brand_new', 'message_template_language' => 'en']]]]]]);
se_wa_process_event(['payload' => $evt3]);
se_eq(2, count(se_wa_approved_templates(1)), 'an approval for a never-synced template is usable at once');

$parked = null;
try {
    se_wa_process_event(['payload' => json_encode(['entry' => [['id' => 'WABA-UNKNOWN',
        'changes' => [['field' => 'message_template_status_update', 'value' => ['event' => 'APPROVED',
        'message_template_name' => 'x', 'message_template_language' => 'tr']]]]]])]);
} catch (SeWaPermanentError $e) { $parked = $e->getMessage(); }
se_eq('unknown waba_id', $parked, 'an unmapped WABA is parked permanently, not retried');
se_eq(2, count(se_wa_approved_templates(1)), 'and nothing leaks into a mapped brand');

/* --- send-time language comes from the mirror ---------------------------- */
se_test_install_secret('wa_app', 'fixture-not-a-real-secret');
se_test_install_secret('wa_token', 'fixture-not-a-real-token');
$sent = [];
se_wa_register_transport(function ($m) use (&$sent) { $sent[] = $m; return ['ok' => true, 'wamid' => 'wamid.T1', 'code' => 200, 'error' => '']; });

$q = se_wa_queue_message(901, ['kind' => 'template', 'template' => 'brand_new'], 10);
se_eq(true, $q['ok'], 'an approved template queues on a closed window');
se_wa_out_drain();
se_eq(1, count($sent), 'one send');
se_eq('en', $sent[0]['template_language'] ?? null, 'the transport receives the template\'s own language, not a hard-coded tr');

$q = se_wa_queue_message(901, ['kind' => 'template', 'template' => 'azin_reengagement_tr'], 10);
se_eq('template_not_approved', $q['reason'], 'a rejected template cannot be queued');

/* --- cron throttle ------------------------------------------------------- */
$calls = [];
se_wa_register_template_fetcher(function ($waba) use (&$calls, $fixture) {
    $calls[] = $waba; return ['ok' => true, 'templates' => $fixture, 'error' => ''];
});
se_eq(0, se_wa_sync_templates_cron(), 'cron skips a brand synced within the interval');
update_option('se_wa_templates_synced_at_1', date('Y-m-d H:i:s', time() - SE_WA_TEMPLATE_SYNC_INTERVAL - 60));
se_eq(1, se_wa_sync_templates_cron(), 'cron re-pulls once the interval has passed');
se_eq(['WABA1'], $calls, 'only brands with a WABA are pulled');

se_test_remove_secret('wa_app');
se_test_remove_secret('wa_token');
$GLOBALS['SE_WA_TRANSPORT'] = null;
$GLOBALS['SE_WA_TEMPLATE_FETCHER'] = null;
