<?php
/** One failing cron step must not stop the next (CRM-M014 / AZCRM-OBS-004). */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$GLOBALS['se_test']['options'] = [];
$ran = [];
se_group('Cron steps are isolated');
$r1 = se_cron_run_isolated(function () use (&$ran) { $ran[] = 'a'; throw new RuntimeException('token=EAAGm0PX4ZCpsBA1ZBxYZBqZC7ZBLongTokenValueHere boom'); });
$r2 = se_cron_run_isolated(function () use (&$ran) { $ran[] = 'b'; return 42; });
se_eq(['a', 'b'], $ran, 'the second step ran although the first threw');
se_eq(null, $r1, 'a failed step yields null');
se_eq(42, $r2, 'a healthy step returns its value');
$errs = json_decode((string) get_option('se_cron_last_errors'), true);
se_ok(isset($errs['closure']['error']) && strpos($errs['closure']['error'], 'RuntimeException') === 0, 'the failure is recorded with its class');
se_ok(strpos($errs['closure']['error'], 'EAAGm0PX4ZCpsBA1ZBxYZBqZC7ZBLongTokenValueHere') === false, 'and the token-shaped secret is redacted');
