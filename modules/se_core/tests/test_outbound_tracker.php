<?php
/**
 * Outbound tracker: a queued reply is explained per row (what, why pending,
 * when it goes) instead of vanishing into a "pending 1" counter.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$db = se_test_db();
$db->tables = []; $db->autoinc = [];
$GLOBALS['se_test']['options'] = [];

$now = strtotime('2026-09-02 17:30:00');

/* --- dispatcher ETA ------------------------------------------------------ */
$eta = se_outbound_dispatch_eta($now);
se_eq(null, $eta['next_run_at'], 'no cron run recorded => no ETA claimed');

update_option('last_cron_run', $now - 300);                      // ran 5 min ago
$eta = se_outbound_dispatch_eta($now);
se_eq(date('Y-m-d H:i:s', $now + 600), $eta['next_run_at'], 'next run = last run + 15 min');
se_eq(600, $eta['seconds'], '10 minutes away');
se_eq(false, $eta['overdue'], 'not overdue');

update_option('last_cron_run', $now - 2000);                     // 33 min ago: a run was missed
$late = se_outbound_dispatch_eta($now);
se_eq(true, $late['overdue'], 'a missed run is reported as overdue');
se_eq(0, $late['seconds'], 'and never as a negative/past ETA');

update_option('last_cron_run', $now - 300);
$eta = se_outbound_dispatch_eta($now);

/* --- rows ---------------------------------------------------------------- */
$db->seed('tblse_wa_outbound', [
    ['id' => 1, 'conversation_id' => 950002, 'brand_id' => 22, 'kind' => 'template', 'template_name' => 'azin_reengagement_tr',
     'body' => null, 'status' => 'pending', 'attempts' => 0, 'failure_class' => null, 'last_error' => null,
     'date_created' => date('Y-m-d H:i:s', $now - 120), 'next_attempt_at' => date('Y-m-d H:i:s', $now - 120),
     'sent_at' => null, 'wamid' => null],
    ['id' => 2, 'conversation_id' => 950002, 'brand_id' => 22, 'kind' => 'text', 'template_name' => null,
     'body' => 'hello there, this is a long body that will be truncated in the tracker label for sure',
     'status' => 'sent', 'attempts' => 1, 'failure_class' => null, 'last_error' => null,
     'date_created' => date('Y-m-d H:i:s', $now - 4000), 'next_attempt_at' => date('Y-m-d H:i:s', $now - 4000),
     'sent_at' => date('Y-m-d H:i:s', $now - 3500), 'wamid' => 'wamid.HBgMOTA1MzE0MzI4NTQ5FQIAERgU'],
    ['id' => 3, 'conversation_id' => 950002, 'brand_id' => 22, 'kind' => 'text', 'template_name' => null, 'body' => 'retry me',
     'status' => 'pending', 'attempts' => 1, 'failure_class' => 'retryable', 'last_error' => 'transport error',
     'date_created' => date('Y-m-d H:i:s', $now - 900), 'next_attempt_at' => date('Y-m-d H:i:s', $now + 1500),
     'sent_at' => null, 'wamid' => null],
    ['id' => 4, 'conversation_id' => 950002, 'brand_id' => 22, 'kind' => 'text', 'template_name' => null, 'body' => 'gated',
     'status' => 'pending', 'attempts' => 0, 'failure_class' => 'gated', 'last_error' => 'sending gated: no_token',
     'date_created' => date('Y-m-d H:i:s', $now - 60), 'next_attempt_at' => date('Y-m-d H:i:s', $now + 3600),
     'sent_at' => null, 'wamid' => null],
    ['id' => 5, 'conversation_id' => 950002, 'brand_id' => 22, 'kind' => 'text', 'template_name' => null, 'body' => 'late',
     'status' => 'skipped', 'attempts' => 0, 'failure_class' => 'permanent', 'last_error' => 'service window closed before send',
     'date_created' => date('Y-m-d H:i:s', $now - 60), 'next_attempt_at' => date('Y-m-d H:i:s', $now - 60),
     'sent_at' => null, 'wamid' => null],
    ['id' => 6, 'conversation_id' => 950002, 'brand_id' => 22, 'kind' => 'text', 'template_name' => null, 'body' => 'dead',
     'status' => 'failed', 'attempts' => 5, 'failure_class' => 'retryable', 'last_error' => 'graph HTTP 500',
     'date_created' => date('Y-m-d H:i:s', $now - 60), 'next_attempt_at' => date('Y-m-d H:i:s', $now - 60),
     'sent_at' => null, 'wamid' => null],
    ['id' => 7, 'conversation_id' => 999, 'brand_id' => 22, 'kind' => 'text', 'template_name' => null, 'body' => 'other thread',
     'status' => 'pending', 'attempts' => 0, 'failure_class' => null, 'last_error' => null,
     'date_created' => date('Y-m-d H:i:s', $now), 'next_attempt_at' => date('Y-m-d H:i:s', $now), 'sent_at' => null, 'wamid' => null],
]);

$rows = se_outbound_rows('se_wa_outbound', 950002, 'wamid');
se_eq(6, count($rows), 'only this conversation\'s rows');
se_eq(6, $rows[0]['id'], 'newest first');
$byId = []; foreach ($rows as $r) { $byId[$r['id']] = $r; }
se_eq('wamid.HBgMOTA1MzE0MzI4NTQ5FQIAERgU', $byId[2]['provider_id'], 'provider id read from the channel column');

$ex = se_outbound_explain($byId[1], $eta, $now);
se_eq('pending', $ex['state'], 'a due pending row is plain pending');
se_ok(strpos($ex['text'], 'next dispatcher run at ' . date('H:i', $now + 600)) !== false, 'and names the next run time');
se_ok(strpos($ex['text'], '10 min') !== false, 'with the wait in minutes');

$ex = se_outbound_explain($byId[2], $eta, $now);
se_eq('sent', $ex['state'], 'sent');
se_ok(strpos($ex['text'], 'provider id wamid.HBgMOTA1MzE0') !== false, 'sent rows show a truncated provider id');

$ex = se_outbound_explain($byId[3], $eta, $now);
se_eq('retryable', $ex['state'], 'a backed-off row is retryable');
se_ok(strpos($ex['text'], 'Retry scheduled') === 0 && strpos($ex['text'], 'transport error') !== false, 'names the retry time and the last error');
se_ok(strpos($ex['text'], 'attempt 2') !== false, 'and the attempt number');

$ex = se_outbound_explain($byId[4], $eta, $now);
se_eq('gated', $ex['state'], 'a gated row is held');
se_ok(strpos($ex['text'], 'no_token') !== false, 'and names the gate');

$ex = se_outbound_explain($byId[5], $eta, $now);
se_eq('skipped', $ex['state'], 'skipped');
se_ok(strpos($ex['text'], 'will not be retried') !== false, 'says it is final');

$ex = se_outbound_explain($byId[6], $eta, $now);
se_eq('failed', $ex['state'], 'failed');
se_ok(strpos($ex['text'], '5 attempts') !== false && strpos($ex['text'], 'graph HTTP 500') !== false, 'attempts + error');

$ex = se_outbound_explain($byId[1], $late, $now);
se_eq('warning', $ex['state'], 'a due row with an overdue dispatcher warns about cron, not the message');

$ex = se_outbound_explain($byId[1], se_outbound_dispatch_eta_never(), $now);
se_eq('pending', $ex['state'], 'no cron ever => still just pending');

/* --- render (smoke) ------------------------------------------------------ */
ob_start(); se_ui_outbound_tracker($rows, $eta, $now); $html = ob_get_clean();
se_ok(strpos($html, 'Template azin_reengagement_tr') !== false, 'template rows are labelled by template');
se_ok(strpos($html, 'hello there, this is a long body that will be truncated in t…') !== false
   && strpos($html, 'for sure') === false, 'long bodies are truncated to 60 chars');
se_ok(strpos($html, 'every 15 minutes') !== false, 'the cadence is stated');
se_ok(strpos($html, '<script') === false && strpos($html, 'graph HTTP 500') !== false, 'errors are shown, escaped');

ob_start(); se_ui_outbound_tracker([], $eta, $now); $html = ob_get_clean();
se_ok(strpos($html, 'No messages queued') !== false, 'empty state');

$GLOBALS['se_test']['options'] = [];

function se_outbound_dispatch_eta_never()
{
    return ['last_run_at' => null, 'next_run_at' => null, 'seconds' => null, 'interval' => 900, 'overdue' => false];
}
