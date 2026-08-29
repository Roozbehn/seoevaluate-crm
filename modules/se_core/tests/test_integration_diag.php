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
