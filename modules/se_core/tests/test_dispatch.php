<?php
/**
 * Fast messaging dispatcher (se_core/dispatch): runs only the WA/IG legs
 * under a lock, records its heartbeat, and the conversation tracker switches
 * its ETA to the per-minute cadence while the heartbeat is alive — and falls
 * back to the 15-minute cron when it is not.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$db = se_test_db();
$db->tables = []; $db->autoinc = [];
$db->seed('tblse_wa_webhook_events', []);
$db->seed('tblse_wa_outbound', []);
$db->seed('tblse_wa_numbers', []);
$db->seed('tblse_wa_conversations', []);
$db->seed('tblse_ig_webhook_events', []);
$db->seed('tblse_ig_outbound', []);
$db->seed('tblse_ig_accounts', []);
$GLOBALS['se_test']['options'] = [];

$db->seed('tblse_media', []);
se_eq(['wa_events', 'wa_queue', 'ig_events', 'ig_queue', 'leadgen', 'media', 'journey_media'], array_keys(se_dispatch_steps()),
    'the dispatcher runs exactly the messaging legs (+ Lead Ads pull, attachment fetch) — no invoices, reminders or IMAP');

$r = se_dispatch_run();
se_eq(true, $r['ok'], 'an empty system dispatches cleanly');
se_eq(false, $r['locked'], 'and was not blocked by a lock');
se_eq([], $r['errors'], 'no errors');
se_eq(7, count($r['ran']), 'all seven legs ran (4 messaging + leadgen + media + journey media seal)');
se_ok((int) get_option('se_dispatch_last_run') > time() - 5, 'the heartbeat is recorded');
se_ok(se_dispatch_active(), 'and the dispatcher counts as active');

/* --- tracker ETA follows the dispatcher while it is alive ---------------- */
$now = time();
update_option('last_cron_run', $now - 600);                       // Perfex cron 10 min ago
$eta = se_outbound_dispatch_eta($now);
se_eq('dispatcher', $eta['source'], 'ETA source is the fast dispatcher');
se_eq(60, $eta['interval'], 'with a one-minute interval');
se_ok($eta['seconds'] <= 60, 'next run within a minute');
se_eq('every minute', se_outbound_cadence_text($eta), 'cadence text says every minute');

$row = ['id' => 1, 'kind' => 'text', 'template_name' => '', 'body' => 'x', 'status' => 'pending', 'attempts' => 0,
        'failure_class' => '', 'last_error' => '', 'queued_at' => date('Y-m-d H:i:s', $now),
        'next_attempt_at' => date('Y-m-d H:i:s', $now), 'sent_at' => '', 'provider_id' => ''];
$ex = se_outbound_explain($row, $eta, $now);
se_eq('pending', $ex['state'], 'pending');
se_ok(strpos($ex['text'], 'within the next minute') !== false, 'a queued row promises the next minute');

/* --- dispatcher dead => honest fallback to the 15-minute cron ----------- */
update_option('se_dispatch_last_run', $now - 600);                // no heartbeat for 10 min
se_eq(false, se_dispatch_active($now), 'three missed minutes = not active');
$eta = se_outbound_dispatch_eta($now);
se_eq('cron', $eta['source'], 'ETA falls back to the Perfex cron');
se_eq(900, $eta['interval'], 'with its 15-minute interval');
se_eq('every 15 minutes', se_outbound_cadence_text($eta), 'and says so');
$ex = se_outbound_explain($row, $eta, $now);
se_ok(strpos($ex['text'], 'next dispatcher run at') !== false, 'the row names the cron run time again');

$GLOBALS['se_test']['options'] = [];
