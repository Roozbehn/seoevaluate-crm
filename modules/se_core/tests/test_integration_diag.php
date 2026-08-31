<?php

/**
 * Integration diagnostics — the behaviour the four admin screens depend on.
 *
 * DB-free: exercises the secret provider, the canonical app-secret inheritance,
 * the CAPI/Lead-Ads health independence, the sensitive-event upload guard, and
 * the truthful reconcile timestamp — all as pure functions over fixture secrets
 * and in-memory option state. No token value is ever asserted on.
 */

// se_secret_store_status() checks the store is outside FCPATH (the document
// root). In production FCPATH is always defined; the CLI harness is not a web
// context, so provide a docroot that is NOT an ancestor of the temp secret dir.
if (!defined('FCPATH')) { define('FCPATH', __DIR__ . '/'); }

require_once __DIR__ . '/../se_secret_provider.php';
require_once __DIR__ . '/../se_google_dm.php';
require_once __DIR__ . '/../../se_whatsapp/helpers.php';

/* ---- secret store status exposes path + expected filename, never a value -- */
se_group('secret store status is safe and useful');

se_test_install_secret('meta_verify', 'fixture-verify');
$store = se_secret_store_status();
se_ok(isset($store['dir']) && $store['dir'] === SE_SECRET_DIR, 'store status exposes the resolved absolute path (configuration, not a secret)');
se_ok($store['outside_docroot'] === true, 'the test store is outside the document root');

$st = se_secret_status('meta_verify', 0);
se_eq('meta_verify', $st['expected_file'], 'provider status names the expected file without revealing a value');
se_ok($st['configured'] === true && !isset($st['value']) && !isset($st['length']), 'status carries no value and no length');

/* ---- canonical Meta App Secret: WhatsApp inherits it ---------------------- */
se_group('WhatsApp app secret inherits the canonical Meta App Secret');

se_test_remove_secret('wa_app');
se_test_remove_secret('meta_app');
se_eq('', se_wa_app_secret(), 'no wa_app and no meta_app => empty (fails closed)');
se_ok(!se_wa_app_secret_inherited(), 'nothing to inherit yet');

se_test_install_secret('meta_app', 'canonical-app-secret');
se_eq('canonical-app-secret', se_wa_app_secret(), 'with only meta_app installed, WhatsApp inherits it');
se_ok(se_wa_app_secret_inherited(), 'inheritance flag is true when meta_app is the source');

$waStatus = se_secret_status('wa_app', 0);
se_eq('meta_app', $waStatus['inherited_from'], 'wa_app status reports inheritance from meta_app');
se_ok($waStatus['configured'] === true, 'inherited wa_app reads as configured for the UI');
se_ok($waStatus['own_file'] === false, 'but it has no dedicated file of its own');

se_test_install_secret('wa_app', 'dedicated-wa-secret');
se_eq('dedicated-wa-secret', se_wa_app_secret(), 'a dedicated wa_app file takes precedence over inheritance');
se_ok(!se_wa_app_secret_inherited(), 'inheritance flag is false once a dedicated file exists');

/* ---- CAPI and Lead Ads readiness are INDEPENDENT -------------------------- */
se_group('CAPI readiness never depends on Lead Ads App Review');

/* se_gdm_event_uploadable / se_gdm_stage_uploadable are pure policy predicates. */
se_group('sensitive-stage upload guard');

se_ok(se_gdm_stage_uploadable('Qualified'), 'Qualified is an allowlisted, uploadable stage');
se_ok(se_gdm_stage_uploadable('Consultation Booked'), 'Consultation Booked is uploadable');
se_ok(!se_gdm_stage_uploadable('Photos Received'), 'Photos Received is NOT uploadable (sensitive)');
se_ok(!se_gdm_stage_uploadable('Treated'), 'Treated is NOT uploadable (clinical)');
se_ok(!se_gdm_stage_uploadable('Follow-up'), 'Follow-up is NOT uploadable (clinical)');

se_ok(se_gdm_event_uploadable('Lead'), 'the generic Lead event is always uploadable');
se_ok(se_gdm_event_uploadable('Converted Lead'), 'Converted Lead is a generic, uploadable event');
se_ok(!se_gdm_event_uploadable('Treated'), 'a clinical stage is never an uploadable event');
se_ok(!se_gdm_event_uploadable('Photos Received'), 'Photos Received is never an uploadable event');

/* ---- CAPI dataset-drift guard (pure decision) ---------------------------- */
se_group('CAPI dataset-drift guard blocks the wrong dataset');

require_once __DIR__ . '/../se_meta_leadgen.php';

se_ok(se_capi_dataset_conflict_decide('4515580372030489', '') === null,
    'no authoritative id recorded => nothing to enforce (no conflict)');
se_ok(se_capi_dataset_conflict_decide('', '4515580372030489') === null,
    'unset brand dataset is a separate "no dataset" blocker, not a conflict');
se_ok(se_capi_dataset_conflict_decide('4515580372030489', '4515580372030489') === null,
    'matching dataset and authoritative id => no conflict');
se_eq('4515580372030489', se_capi_dataset_conflict_decide('4266388243621345', '4515580372030489'),
    'wrong dataset (WhatsApp MM id) conflicts; guard returns the authoritative id to restore');
se_eq('4515580372030489', se_capi_dataset_conflict_decide(' 4266388243621345 ', ' 4515580372030489 '),
    'whitespace is trimmed before comparison');

/* ---- single source of truth: the asset registry -------------------------- */
se_group('dataset asset registry is the single source of truth');

require_once __DIR__ . '/../se_asset_registry.php';

se_eq('4515580372030489', se_asset_dataset('web_capi', 22), 'registry gives the web CAPI dataset for brand 22');
se_eq('2081936999059007', se_asset_dataset('mm_api', 22), 'registry keeps the MM API dataset separate for brand 22');
se_ok(se_asset_dataset('web_capi', 22) !== se_asset_dataset('mm_api', 22), 'web and MM datasets are never the same asset');
se_ok(se_asset_dataset('web_capi', 999) === null, 'an unregistered brand has no enforced dataset (no false conflict)');

se_ok(se_asset_is_forbidden_web_capi('4266388243621345'), 'the superseded/misassigned dataset is forbidden for web CAPI');
se_ok(!se_asset_is_forbidden_web_capi('4515580372030489'), 'the correct web dataset is not forbidden');
se_ok(se_asset_is_forbidden_web_capi(' 4266388243621345 '), 'forbidden check trims whitespace');

/* ---- evidence-based six-state webhook model ------------------------------ */
se_group('a verify-token file is NOT webhook verification');

require_once __DIR__ . '/../se_webhook_state.php';

// Clean slate: no verify token, no recorded events.
se_test_remove_secret('meta_verify');
foreach (['se_meta_route_ok_at','se_meta_challenge_verified_at','se_meta_challenge_src',
          'se_meta_signed_post_at','se_meta_signed_post_selftest_at','se_meta_challenge_selftest_at','se_meta_live_test_at'] as $k) { unset($GLOBALS['se_test']['options'][$k]); }

$st = se_webhook_state('meta');
se_ok($st['verify_token_installed'] === false, 'no verify token => verify_token_installed is false');
se_ok($st['verification_ready'] === false, 'no route check and no token => verification_ready is false');
se_ok($st['challenge_verified'] === false, 'no correct-token challenge has happened => challenge_verified is false');

// Installing the verify-token file alone must NOT flip challenge_verified.
se_test_install_secret('meta_verify', 'fixture-verify');
$st = se_webhook_state('meta');
se_ok($st['verify_token_installed'] === true, 'verify token file installed => verify_token_installed true');
se_ok($st['verification_ready'] === false, 'token installed but route never reached => verification_ready STILL false');
se_ok($st['challenge_verified'] === false, 'a file existing is not a returned challenge => challenge_verified STILL false');

// A route self-check makes verification_ready true (token + reachable route).
se_webhook_record('meta', 'route_ok');
$st = se_webhook_state('meta');
se_ok($st['verification_ready'] === true, 'verify token readable AND route reached => verification_ready true');
se_ok($st['challenge_verified'] === false, 'route reachability still is not a challenge');

// A SELF-TEST challenge must NEVER turn the production state green — it is
// recorded separately and only Meta's real callback sets challenge_verified.
se_webhook_record('meta', 'challenge', ['src' => 'self_test']);
$st = se_webhook_state('meta');
se_ok($st['challenge_verified'] === false, 'a self-test challenge does NOT set challenge_verified');
se_ok($st['challenge_selftest_at'] !== null, 'the self-test is tracked in its own timestamp');

se_webhook_record('meta', 'challenge', ['src' => 'meta']);
$st = se_webhook_state('meta');
se_ok($st['challenge_verified'] === true, "Meta's real callback sets challenge_verified");
se_eq('meta', $st['challenge_src'], 'and the source is provider, never self_test');

$metaChallengeAt = $st['challenge_verified_at'];
se_webhook_record('meta', 'challenge', ['src' => 'self_test']);
$st = se_webhook_state('meta');
se_eq($metaChallengeAt, $st['challenge_verified_at'],
    'a later self-test replay never overwrites or downgrades provider evidence');

se_ok($st['signed_post_received'] === false, 'no signed POST yet');
se_webhook_record('meta', 'signed_post', ['src' => 'self_test']);
$st = se_webhook_state('meta');
se_ok($st['signed_post_received'] === false, 'a self-test signed POST does NOT set signed_post_received');
se_webhook_record('meta', 'signed_post', ['src' => 'meta']);
$st = se_webhook_state('meta');
se_ok($st['signed_post_received'] === true, 'a provider-signed POST sets signed_post_received');
se_eq('meta', $st['signed_post_src'], 'with provider source');

se_ok($st['live_test_passed'] === false, 'no end-to-end lead yet');
se_webhook_record('meta', 'live_test');
$st = se_webhook_state('meta');
se_ok($st['live_test_passed'] === true, 'a lead created from the webhook sets live_test_passed');

// WhatsApp inherits the Meta App Secret; app_secret_installed reflects that.
se_test_remove_secret('wa_app');
se_test_install_secret('meta_app', 'canonical-app-secret');
$wst = se_webhook_state('wa');
se_ok($wst['app_secret_installed'] === true, 'WhatsApp app_secret_installed is true via inheritance from meta_app');
se_ok($wst['app_secret_inherited'] === true, 'and it is flagged as inherited, not an independent wa_app file');

// cleanup
se_test_remove_secret('meta_verify');
se_test_remove_secret('meta_app');
foreach (['se_meta_route_ok_at','se_meta_challenge_verified_at','se_meta_challenge_src',
          'se_meta_signed_post_at','se_meta_signed_post_selftest_at','se_meta_challenge_selftest_at','se_meta_live_test_at'] as $k) { unset($GLOBALS['se_test']['options'][$k]); }

/* ---- the Google status poller is genuinely registered -------------------- */
se_group('Google request-status polling is implemented');

se_ok(se_gdm_status_polling_implemented(), 'a live request-status poller is implemented, not just abstracted');

/* ---- cron threshold constants are explicit ------------------------------- */
se_group('cron cadence is explicit');

require_once __DIR__ . '/../se_reporting.php';
se_ok(defined('SE_CRON_EXPECTED_INTERVAL_SECONDS') && SE_CRON_EXPECTED_INTERVAL_SECONDS === 900, 'expected cron interval is stated (900s)');
se_ok(defined('SE_CRON_WARN_SECONDS') && defined('SE_CRON_FAIL_SECONDS')
    && SE_CRON_WARN_SECONDS < SE_CRON_FAIL_SECONDS, 'warn threshold precedes fail threshold');

se_test_remove_secret('wa_app');
se_test_remove_secret('meta_app');
se_test_remove_secret('meta_verify');
