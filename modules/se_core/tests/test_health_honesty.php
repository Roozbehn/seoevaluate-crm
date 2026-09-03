<?php
/**
 * Integration Health tells the truth (CRM-M008 / AZCRM-OBS-001 / K10):
 * skipped conversions are counted by reason, the dispatcher's age is exposed,
 * WhatsApp queue and appointment-reminder failures surface as blockers, and
 * the snapshot no longer reads a `dead` status that never exists.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$db = se_test_db();
$db->tables = []; $db->autoinc = [];
$db->seed('tblse_brands', [['id' => 1, 'name' => 'Brand A', 'active' => 1]]);
$db->seed('tblse_wa_numbers', []);
$db->seed('tblse_ig_accounts', []);
$db->seed('tblse_meta_forms', []);
$db->seed('tblse_outbox', []);
$db->seed('tblse_conversion_outbox', [
    ['id' => 1, 'brand_id' => 1, 'status' => 'skipped', 'error_code' => 'consent_blocked'],
    ['id' => 2, 'brand_id' => 1, 'status' => 'skipped', 'error_code' => 'consent_blocked'],
    ['id' => 3, 'brand_id' => 1, 'status' => 'skipped', 'error_code' => 'no_snapshot'],
    ['id' => 4, 'brand_id' => 1, 'status' => 'confirmed', 'error_code' => ''],
]);
$db->seed('tblse_wa_outbound', [
    ['id' => 1, 'brand_id' => 1, 'status' => 'failed'],
    ['id' => 2, 'brand_id' => 1, 'status' => 'sent'],
]);
$db->seed('tblse_reminders', [
    ['id' => 1, 'brand_id' => 1, 'state' => 'failed'],
]);
$GLOBALS['se_test']['options'] = [];
se_test_act_as(10, [], true);

se_group('Health: skipped conversions are visible, by reason');
$h = se_integration_health(1);
se_eq(3, $h['outbox']['skipped'], 'three skipped rows counted');
se_eq(['consent_blocked' => 2, 'no_snapshot' => 1], $h['outbox']['skipped_by_reason'], 'grouped by skip reason');
se_eq(1, $h['outbox']['sent'], 'confirmed rows count as sent');
se_ok(!array_key_exists('dead', $h['outbox']), 'the nonexistent dead status is gone');
$keys = array_map(function ($b) { return $b['key']; }, $h['blockers']);
se_ok(in_array('outbox_skipped', $keys, true), 'skipped conversions are a BLOCKER, not hidden behind a green card');
$blk = array_values(array_filter($h['blockers'], function ($b) { return $b['key'] === 'outbox_skipped'; }))[0];
se_ok(stripos($blk['action'], 'owner/legal decision') !== false, 'consent_blocked names the decision that unblocks it');

se_group('Health: dispatcher age and queues');
se_eq('unknown', $h['dispatcher']['state'], 'dispatcher that never ran is unknown, not healthy');
update_option('se_dispatch_last_run', time() - 30);
$h = se_integration_health(1);
se_eq('healthy', $h['dispatcher']['state'], 'a run 30 s ago is healthy');
update_option('se_dispatch_last_run', time() - 2000);
$h = se_integration_health(1);
se_eq('failed', $h['dispatcher']['state'], 'a run 2000 s ago is failed');
$keys = array_map(function ($b) { return $b['key']; }, $h['blockers']);
se_ok(in_array('dispatcher_stalled', $keys, true), 'a stalled dispatcher is a blocker');
se_ok(in_array('wa_outbound_failed', $keys, true), 'a failed WhatsApp send is a blocker');
se_ok(in_array('reminders_failed', $keys, true), 'a failed appointment reminder is a blocker');
se_eq(1, $h['wa_queue']['failed'], 'WhatsApp queue failed count');
se_eq(1, $h['reminders']['failed'], 'reminder failed count');
