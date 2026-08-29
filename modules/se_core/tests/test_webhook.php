<?php
/**
 * Webhook controller-order logic + the workstream-A authorization fixes.
 *
 * - se_leadgen_receive_outcome / se_wa_receive_outcome: the EXACT pipeline
 *   order (413 size before HMAC, HMAC over raw bytes before decode, 400
 *   malformed-JSON gate before store, honest 200/500 after the store).
 * - Secret-source unification: enforcement reads the FILE secret provider
 *   only, fails closed, never reads the legacy options.
 * - se_meta_ui_requeue brand guard, se_meta_ui_counters/events brand=0 scope,
 *   se_default_brand_id unmapped->empty, se-health nav gate, appointments
 *   lead-tab brand scope.
 *
 * Every secret here is an obviously synthetic throwaway string.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function se_test_seed_webhook()
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];

    $db->seed('tblse_brands', [
        ['id' => 1, 'name' => 'Brand A', 'active' => 1],
        ['id' => 2, 'name' => 'Brand B', 'active' => 1],
    ]);
    $db->seed('tblse_staff_brands', [
        ['staff_id' => 10, 'brand_id' => 1],
        ['staff_id' => 20, 'brand_id' => 2],
    ]);
    $db->seed('tblse_meta_forms', [
        ['id' => 1, 'brand_id' => 1, 'page_id' => 'PAGE-A', 'form_id' => 'FORM-A',
         'form_name' => 'Form A', 'active' => 1, 'field_map_json' => ''],
        ['id' => 2, 'brand_id' => 2, 'page_id' => 'PAGE-B', 'form_id' => 'FORM-B',
         'form_name' => 'Form B', 'active' => 1, 'field_map_json' => ''],
    ]);
    $db->seed('tblse_meta_leadgen_events', []);
    $db->seed('tblse_wa_webhook_events', []);
    $db->seed('tblleads', []);
    $db->seed('tblse_appointments', []);
    $GLOBALS['se_test']['options'] = [];
}

function se_test_webhook_purge_secrets()
{
    foreach (['meta_app', 'meta_verify', 'meta_page', 'meta_page_1', 'meta_page_2',
              'wa_app', 'wa_verify'] as $f) {
        se_test_remove_secret($f);
    }
}

function se_test_leadgen_payload($leadgen_id, $page_id = 'PAGE-A', $form_id = 'FORM-A')
{
    return json_encode(['entry' => [['id' => $page_id, 'time' => 1700000000, 'changes' => [[
        'field' => 'leadgen',
        'value' => [
            'leadgen_id'   => $leadgen_id,
            'page_id'      => $page_id,
            'form_id'      => $form_id,
            'created_time' => 1700000000,
        ],
    ]]]]]);
}

function se_test_sign($raw, $secret)
{
    return 'sha256=' . hash_hmac('sha256', $raw, $secret);
}

se_test_seed_webhook();
se_test_webhook_purge_secrets();
se_test_act_as(1, [], true);

$SYNTH_META_APP = 'ZZTEST-synthetic-meta-app-' . bin2hex(random_bytes(8));
$SYNTH_WA_APP   = 'ZZTEST-synthetic-wa-app-' . bin2hex(random_bytes(8));

/* ======================================================================== */
se_group('Secret source unification: file provider only, fail closed');

se_eq(true, isset(se_secret_providers()['meta_verify']), 'the meta_verify provider is registered');
se_eq(false, (bool) se_secret_providers()['meta_verify']['per_brand'], 'meta_verify is a global (non-per-brand) secret');

// Legacy option values must have NO influence any more (they are preserved,
// never read). Set them all and prove enforcement still fails closed.
$GLOBALS['se_test']['options']['se_meta_app_secret']           = 'ZZTEST-legacy-option-meta';
$GLOBALS['se_test']['options']['se_meta_webhook_verify_token'] = 'ZZTEST-legacy-verify-meta';
$GLOBALS['se_test']['options']['se_wa_app_secret']             = 'ZZTEST-legacy-option-wa';
$GLOBALS['se_test']['options']['se_wa_verify_token']           = 'ZZTEST-legacy-verify-wa';
$GLOBALS['se_test']['options']['se_meta_page_token_1']         = 'ZZTEST-legacy-page-token';

se_eq('', se_meta_app_secret(), 'meta app secret is EMPTY with no file, even with the legacy option set');
se_eq('', se_wa_app_secret(), 'wa app secret is EMPTY with no file, even with the legacy option set');
se_eq('', se_meta_verify_token(), 'meta verify token is EMPTY with no file');
se_eq('', se_wa_verify_token(), 'wa verify token is EMPTY with no file');
se_eq('', se_meta_page_token(1), 'meta page token ignores the legacy option entirely');

$raw = se_test_leadgen_payload('L-OPT');
se_eq(false, se_leadgen_verify_signature($raw, se_test_sign($raw, 'ZZTEST-legacy-option-meta')),
    'a body signed with the legacy OPTION secret is rejected (option not read; no secret => fail closed)');
se_eq(false, se_wa_verify_signature($raw, se_test_sign($raw, 'ZZTEST-legacy-option-wa')),
    'same for WhatsApp: the option-signed body is rejected');
se_eq(false, se_leadgen_verify_outcome('subscribe', 'ZZTEST-legacy-verify-meta'),
    'meta GET verification fails CLOSED with no verify-token file');
se_eq(false, se_wa_verify_outcome('subscribe', 'ZZTEST-legacy-verify-wa'),
    'wa GET verification fails CLOSED with no verify-token file');

// Install FILE secrets: enforcement must now agree with the provider.
se_test_install_secret('meta_app', $SYNTH_META_APP);
se_test_install_secret('wa_app', $SYNTH_WA_APP);
se_test_install_secret('meta_verify', 'ZZTEST-meta-verify-file');
se_test_install_secret('wa_verify', 'ZZTEST-wa-verify-file');

se_eq($SYNTH_META_APP, se_meta_app_secret(), 'meta app secret now comes from the file provider');
se_eq(true, se_leadgen_verify_signature($raw, se_test_sign($raw, $SYNTH_META_APP)),
    'a body signed with the FILE secret verifies');
se_eq(false, se_leadgen_verify_signature($raw, se_test_sign($raw, 'ZZTEST-legacy-option-meta')),
    'the legacy option secret still verifies NOTHING');
se_eq(true, se_wa_verify_signature($raw, se_test_sign($raw, $SYNTH_WA_APP)), 'wa file secret verifies');
se_eq(true, se_leadgen_verify_outcome('subscribe', 'ZZTEST-meta-verify-file'), 'meta verification accepts the file token');
se_eq(false, se_leadgen_verify_outcome('subscribe', 'ZZTEST-wa-verify-file'), 'meta verification rejects the WA token (separate providers)');
se_eq(false, se_leadgen_verify_outcome('unsubscribe', 'ZZTEST-meta-verify-file'), 'a non-subscribe mode is refused');
se_eq(true, se_wa_verify_outcome('subscribe', 'ZZTEST-wa-verify-file'), 'wa verification accepts its file token');
se_eq(false, se_wa_verify_outcome('subscribe', 'wrong'), 'wa verification rejects a wrong token');

se_test_install_secret('meta_page', 'ZZTEST-page-global');
se_test_install_secret('meta_page_1', 'ZZTEST-page-brand1');
se_eq('ZZTEST-page-brand1', se_meta_page_token(1), 'the per-brand page-token file wins for its brand');
se_eq('ZZTEST-page-global', se_meta_page_token(2), 'other brands fall back to the shared file');

$health = se_meta_health(1);
se_eq(true, $health['token_configured'], 'se_meta_health reads the SAME file provider (agrees with the credentials UI)');
se_eq(false, $health['externally_gated'], 'a file page token lifts the gate flag');

/* ======================================================================== */
se_group('Meta receive pipeline: exact order and honest statuses');

se_test_seed_webhook();
$db = se_test_db();
$eventsTable = 'tblse_meta_leadgen_events';

$valid = se_test_leadgen_payload('L-1');
$sig   = se_test_sign($valid, $SYNTH_META_APP);

// 1. Size limit FIRST — an oversized body 413s even when validly signed.
$huge = str_repeat('x', SE_LEADGEN_MAX_BODY_BYTES + 1);
$out  = se_leadgen_receive_outcome(strlen($huge), $huge, se_test_sign($huge, $SYNTH_META_APP));
se_eq(413, $out['status'], 'oversized actual bytes -> 413 even with a VALID signature (size before HMAC)');
se_eq('payload_too_large', $out['reason'], '413 reason is machine readable');

$out = se_leadgen_receive_outcome(SE_LEADGEN_MAX_BODY_BYTES + 1, $valid, $sig);
se_eq(413, $out['status'], 'an oversized DECLARED Content-Length -> 413 before anything else');
se_eq(0, count($db->rows($eventsTable)), 'no row was stored by any 413');

// 2. Signature over the exact raw bytes.
$out = se_leadgen_receive_outcome(strlen($valid), $valid, '');
se_eq(401, $out['status'], 'missing signature -> 401');
se_eq('bad_signature', $out['reason'], '401 reason is bad_signature');

$out = se_leadgen_receive_outcome(strlen($valid), $valid, se_test_sign($valid, 'ZZTEST-wrong-secret'));
se_eq(401, $out['status'], 'invalid signature -> 401');

$tampered = $valid;
$tampered[13] = $tampered[13] === 'a' ? 'b' : 'a';   // flip one raw byte after signing
$out = se_leadgen_receive_outcome(strlen($tampered), $tampered, $sig);
se_eq(401, $out['status'], 'one modified raw byte after signing -> 401');
se_eq(0, count($db->rows($eventsTable)), 'no row was stored by any 401');

// 3. Signature BEFORE decode: malformed JSON with a BAD signature is 401.
$malformed = '{"entry":[{"broken":';
$out = se_leadgen_receive_outcome(strlen($malformed), $malformed, 'sha256=' . str_repeat('0', 64));
se_eq(401, $out['status'], 'malformed JSON with a bad signature -> 401 (signature is checked first)');

// 4. Malformed-JSON gate (validly signed) -> 400, no store.
$out = se_leadgen_receive_outcome(strlen($malformed), $malformed, se_test_sign($malformed, $SYNTH_META_APP));
se_eq(400, $out['status'], 'malformed JSON with a VALID signature -> 400');
se_eq('malformed_json', $out['reason'], '400 reason is malformed_json');

$scalar = '42';
$out = se_leadgen_receive_outcome(strlen($scalar), $scalar, se_test_sign($scalar, $SYNTH_META_APP));
se_eq(400, $out['status'], 'a well-formed scalar (not array/object) -> 400');
se_eq(0, count($db->rows($eventsTable)), 'no row was stored by any 400');

// 5. Durable store: 200 only after the row exists; duplicate stays harmless.
$out = se_leadgen_receive_outcome(strlen($valid), $valid, $sig);
se_eq(200, $out['status'], 'a valid signed leadgen payload -> 200');
se_eq('accepted', $out['reason'], 'first delivery reason is accepted');
se_eq(1, count($db->rows($eventsTable)), 'exactly ONE durable event row exists');
se_eq('L-1', $db->rows($eventsTable)[0]['leadgen_id'], 'the row carries the leadgen id');
se_ok(!empty($GLOBALS['se_test']['options']['se_meta_last_webhook_at']), 'last-webhook heartbeat recorded on accept');

$out = se_leadgen_receive_outcome(strlen($valid), $valid, $sig);
se_eq(200, $out['status'], 'an identical redelivery -> 2xx (harmless)');
se_eq('duplicate', $out['reason'], 'and is reported as duplicate');
se_eq(1, count($db->rows($eventsTable)), 'still exactly one row after the duplicate');

// 6. Honest refusal of a payload the store cannot key (no leadgen_id).
$noid = json_encode(['entry' => [['id' => 'PAGE-A', 'changes' => [['field' => 'leadgen', 'value' => (object) []]]]]]);
$out = se_leadgen_receive_outcome(strlen($noid), $noid, se_test_sign($noid, $SYNTH_META_APP));
se_eq(500, $out['status'], 'a well-formed body the store cannot key -> 500, never a false 200');
se_eq('not_stored', $out['reason'], 'and the reason says not_stored');

/* ======================================================================== */
se_group('WhatsApp receive pipeline: exact order and honest statuses');

se_test_seed_webhook();
$db = se_test_db();
$waTable = 'tblse_wa_webhook_events';

$waValid = '{"entry":[{"id":"ZZWABA","changes":[{"value":{"metadata":{"phone_number_id":"ZZPN"}}}]}]}';
$waSig   = se_test_sign($waValid, $SYNTH_WA_APP);

$waHuge = str_repeat('y', SE_WA_MAX_BODY_BYTES + 1);
$out = se_wa_receive_outcome(strlen($waHuge), $waHuge, se_test_sign($waHuge, $SYNTH_WA_APP));
se_eq(413, $out['status'], 'oversized actual bytes -> 413 even validly signed');

$out = se_wa_receive_outcome(SE_WA_MAX_BODY_BYTES + 1, $waValid, $waSig);
se_eq(413, $out['status'], 'oversized declared Content-Length -> 413');

$out = se_wa_receive_outcome(strlen($waValid), $waValid, '');
se_eq(401, $out['status'], 'missing signature -> 401');

$out = se_wa_receive_outcome(strlen($waValid), $waValid, se_test_sign($waValid, 'ZZTEST-wrong'));
se_eq(401, $out['status'], 'invalid signature -> 401');

$waMal = '{"entry":[';
$out = se_wa_receive_outcome(strlen($waMal), $waMal, 'sha256=' . str_repeat('0', 64));
se_eq(401, $out['status'], 'malformed + bad signature -> 401 (signature first)');

$out = se_wa_receive_outcome(strlen($waMal), $waMal, se_test_sign($waMal, $SYNTH_WA_APP));
se_eq(400, $out['status'], 'malformed + valid signature -> 400');
se_eq('malformed_json', $out['reason'], 'reason is malformed_json');
se_eq(0, count($db->rows($waTable)), 'nothing was stored by any refusal');

$out = se_wa_receive_outcome(strlen($waValid), $waValid, $waSig);
se_eq(200, $out['status'], 'valid signed body -> 200');
se_eq('accepted', $out['reason'], 'reason accepted');
se_eq(1, count($db->rows($waTable)), 'exactly one durable event row');

$out = se_wa_receive_outcome(strlen($waValid), $waValid, $waSig);
se_eq(200, $out['status'], 'identical redelivery -> 2xx');
se_eq('duplicate', $out['reason'], 'reported as duplicate');
se_eq(1, count($db->rows($waTable)), 'still exactly one row');

/* ======================================================================== */
se_group('F1: Meta requeue is brand-guarded');

se_test_seed_webhook();
$db = se_test_db();
$db->seed('tblse_meta_leadgen_events', [
    ['id' => 1, 'leadgen_id' => 'L-A', 'page_id' => 'PAGE-A', 'form_id' => 'FORM-A',
     'state' => 'held', 'attempts' => 3, 'signature_valid' => 1, 'payload' => '{}', 'last_error' => 'x'],
    ['id' => 2, 'leadgen_id' => 'L-B', 'page_id' => 'PAGE-B', 'form_id' => 'FORM-B',
     'state' => 'failed', 'attempts' => 5, 'signature_valid' => 1, 'payload' => '{}', 'last_error' => 'x'],
    ['id' => 3, 'leadgen_id' => 'L-X', 'page_id' => 'PAGE-X', 'form_id' => 'FORM-X',
     'state' => 'held', 'attempts' => 1, 'signature_valid' => 1, 'payload' => '{}', 'last_error' => 'x'],
]);

se_eq(1, se_meta_event_brand($db->rows('tblse_meta_leadgen_events')[0]), 'a routed event resolves to its brand');
se_eq(null, se_meta_event_brand($db->rows('tblse_meta_leadgen_events')[2]), 'an unrouted event has no assertable brand');

// A single-brand configure staff member (the F1 attacker profile).
se_test_reset();
se_test_act_as(10, ['se_brands.view']);

$r = se_meta_ui_requeue(2);
se_eq(false, $r['ok'], "requeue of ANOTHER brand's event is refused");
se_eq('failed', $db->rows('tblse_meta_leadgen_events')[1]['state'], "and the foreign event's state is untouched");

$r = se_meta_ui_requeue(3);
se_eq(false, $r['ok'], 'requeue of an UNROUTED event is refused without cross-brand reach');
se_eq('held', $db->rows('tblse_meta_leadgen_events')[2]['state'], 'the unrouted event is untouched');

$r = se_meta_ui_requeue(1);
se_eq(true, $r['ok'], "requeue of the staff member's OWN brand event still works");
se_eq('pending', $db->rows('tblse_meta_leadgen_events')[0]['state'], 'own-brand event returned to pending');
se_eq(0, (int) $db->rows('tblse_meta_leadgen_events')[0]['attempts'], 'attempts reset');

$r = se_meta_ui_requeue(999);
se_eq(false, $r['ok'], 'a missing event is refused');

// Cross-brand reach may requeue anything eligible, including unrouted.
se_test_reset();
se_test_act_as(1, [], true);
$r = se_meta_ui_requeue(3);
se_eq(true, $r['ok'], 'an admin may requeue an unrouted event');

se_test_act_as(50, ['se_brands.view', 'se_tenancy.all_brands']);
$r = se_meta_ui_requeue(2);
se_eq(true, $r['ok'], 'se_tenancy.all_brands may requeue a foreign-brand event');

// Eligibility still enforced for an accessible event.
se_test_act_as(10, ['se_brands.view']);
$db->seed('tblse_meta_leadgen_events', [
    ['id' => 7, 'leadgen_id' => 'L-P', 'page_id' => 'PAGE-A', 'form_id' => 'FORM-A',
     'state' => 'processed', 'attempts' => 1, 'signature_valid' => 1, 'payload' => '{}'],
]);
$r = se_meta_ui_requeue(7);
se_eq(false, $r['ok'], 'a processed own-brand event is still not eligible');

/* ======================================================================== */
se_group('F2: counters/events at brand=0 are scoped to accessible brands');

se_test_seed_webhook();
$db = se_test_db();
$db->seed('tblse_meta_leadgen_events', [
    ['id' => 1, 'leadgen_id' => 'L-A1', 'page_id' => 'PAGE-A', 'form_id' => 'FORM-A',
     'state' => 'pending', 'attempts' => 0, 'signature_valid' => 1, 'payload' => '{"pii":"x"}'],
    ['id' => 2, 'leadgen_id' => 'L-A2', 'page_id' => 'PAGE-A', 'form_id' => 'FORM-A',
     'state' => 'failed', 'attempts' => 5, 'signature_valid' => 1, 'payload' => '{"pii":"x"}'],
    ['id' => 3, 'leadgen_id' => 'L-B1', 'page_id' => 'PAGE-B', 'form_id' => 'FORM-B',
     'state' => 'pending', 'attempts' => 0, 'signature_valid' => 1, 'payload' => '{"pii":"x"}'],
    ['id' => 4, 'leadgen_id' => 'L-U1', 'page_id' => 'PAGE-X', 'form_id' => 'FORM-X',
     'state' => 'held', 'attempts' => 0, 'signature_valid' => 1, 'payload' => '{"pii":"x"}'],
]);

// Single-brand configure staff at the DEFAULT brand=0 screen.
se_test_reset();
se_test_act_as(10, ['se_brands.view']);

$c = se_meta_ui_counters(0);
se_eq(1, $c['pending'], 'brand=0 counters count only OWN-brand pending events');
se_eq(1, $c['failed'], 'own-brand failed counted');
se_eq(0, $c['held'], "the unrouted event is NOT counted for a single-brand staff member");

$rows = se_meta_ui_events(0);
se_eq(['L-A2', 'L-A1'], array_column($rows, 'leadgen_id'), 'brand=0 events list ONLY own-brand events (newest first)');
se_eq(false, isset($rows[0]['payload']), 'the raw payload is still stripped');

$rows = se_meta_ui_events(2);
se_eq([], $rows, "an explicit foreign brand id yields NOTHING at the data layer");
$c = se_meta_ui_counters(2);
se_eq(0, array_sum($c), 'foreign-brand counters are all zero');

// The same staff member's own explicit brand still works.
$c = se_meta_ui_counters(1);
se_eq(2, $c['pending'] + $c['failed'], 'explicit own brand still counts its two events');

// A staff member with NO brands sees nothing at all.
se_test_reset();
se_test_act_as(99, ['se_brands.view']);
se_eq([], se_meta_ui_events(0), 'a zero-brand staff member sees NO events');
se_eq(0, array_sum(se_meta_ui_counters(0)), 'and zero counters');

// Cross-brand reach sees everything, INCLUDING the unrouted event.
se_test_reset();
se_test_act_as(50, ['se_brands.view', 'se_tenancy.all_brands']);
$c = se_meta_ui_counters(0);
se_eq(2, $c['pending'], 'all-brands staff counts both brands');
se_eq(1, $c['held'], 'and the unrouted event');
se_eq(4, count(se_meta_ui_events(0)), 'all-brands staff lists all four events');

se_test_reset();
se_test_act_as(1, [], true);
se_eq(4, count(se_meta_ui_events(0)), 'admin lists all four events');

/* ======================================================================== */
se_group('F3: an unmapped staff member gets an EMPTY report default, never brand 0');

se_test_seed_webhook();
$db = se_test_db();
$db->seed('tblleads', [
    ['id' => 301, 'brand_id' => 0, 'lost' => 0, 'junk' => 0, 'dateadded' => '2026-08-01 10:00:00'],
    ['id' => 302, 'brand_id' => 1, 'lost' => 0, 'junk' => 0, 'dateadded' => '2026-08-01 10:00:00'],
]);

se_test_reset();
se_test_act_as(70, ['se_reports.view']);   // unmapped ordinary reporter (R3)
se_eq(SE_BRAND_NONE, se_default_brand_id(), 'unmapped ordinary staff resolves to SE_BRAND_NONE, not 0');
$totals = se_report_totals(se_default_brand_id());
se_eq(0, $totals['leads'], 'their report aggregates are EMPTY (the brand-0 triage lead is NOT leaked)');

se_test_reset();
se_test_act_as(71, ['se_reports.view', 'se_tenancy.triage_unassigned']);   // triager
se_eq(0, se_default_brand_id(), 'a triage-capable staff member keeps the brand-0 triage default');
se_eq(1, se_report_totals(0)['leads'], 'and still sees the triage aggregates');

se_test_reset();
se_test_act_as(10, ['se_reports.view']);   // mapped staff
se_eq(1, se_default_brand_id(), 'a mapped staff member defaults to their own brand');

se_test_reset();
se_test_act_as(1, [], true);
se_eq(1, se_default_brand_id(), 'admin defaults to the first brand by name');

/* ======================================================================== */
se_group('F4: the se-health nav item is never looser than the Se_reports controller gate');

/* Clinic mode moved Integration Health into the Integrations group, gated on
 * report AND configure. The controller gate is still se_staff_can_report(),
 * so the invariant under test is: whoever sees the item can open it. */
function se_test_nav_item($slug)
{
    foreach (array_merge(se_nav_items(), se_nav_integration_items()) as $item) {
        if ($item['slug'] === $slug) { return $item; }
    }

    return null;
}

se_test_reset();
se_test_act_as(30, ['se_reports.view']);   // reporter (the clinic owner)
se_eq(false, call_user_func(se_test_nav_item('se-health')['can']), 'a report-only staff member is not offered the health item (clinic mode: Integrations are config-capable only)');
se_eq(true, se_staff_can_report(), '...although the controller would admit them, so nothing bounces');
se_eq(true, call_user_func(se_test_nav_item('se-reports')['can']), 'they see the reports item');

se_test_reset();
se_test_act_as(40, ['se_brands.view']);    // configure-only (the F4 victim)
se_eq(false, call_user_func(se_test_nav_item('se-health')['can']),
    'a configure-ONLY staff member still does not see the health item the controller would bounce');
se_eq(true, call_user_func(se_test_nav_item('se-meta-leadgen')['can']), 'their other items are unchanged');

se_test_reset();
se_test_act_as(50, ['se_tenancy.all_brands']);
se_eq(false, call_user_func(se_test_nav_item('se-health')['can']), 'all_brands alone (report, no configure) is not offered it either');

se_test_reset();
se_test_act_as(45, ['se_reports.view', 'se_brands.view']);
se_eq(true, call_user_func(se_test_nav_item('se-health')['can']), 'report + configure sees it, and the controller admits them');

se_test_reset();
se_test_act_as(1, [], true);
se_eq(true, call_user_func(se_test_nav_item('se-health')['can']), 'admin sees it');

/* ======================================================================== */
se_group('F5: the appointments lead-profile tab is brand-scoped');

se_test_seed_webhook();
$db = se_test_db();
$db->seed('tblse_appointments', [
    ['id' => 1, 'brand_id' => 1, 'rel_type' => 'lead', 'rel_id' => 101,
     'title' => 'ZZ-OWN-BRAND-APPT', 'status' => 'scheduled', 'start_at' => '2026-08-01 10:00:00'],
    ['id' => 2, 'brand_id' => 2, 'rel_type' => 'lead', 'rel_id' => 101,
     'title' => 'ZZ-FOREIGN-BRAND-APPT', 'status' => 'scheduled', 'start_at' => '2026-08-02 10:00:00'],
]);

se_test_reset();
se_test_act_as(10, []);   // brand-1 staff
ob_start(); se_appt_lead_tab_content(['id' => 101]); $html = ob_get_clean();
se_ok(strpos($html, 'ZZ-OWN-BRAND-APPT') !== false, "the staff member's own-brand appointment renders");
se_eq(false, strpos($html, 'ZZ-FOREIGN-BRAND-APPT') !== false, "the FOREIGN-brand appointment row does NOT render");

se_test_reset();
se_test_act_as(99, []);   // unmapped staff
ob_start(); se_appt_lead_tab_content(['id' => 101]); $html = ob_get_clean();
se_eq('', $html, 'an unmapped staff member gets no panel at all');

se_test_reset();
se_test_act_as(1, [], true);
ob_start(); se_appt_lead_tab_content(['id' => 101]); $html = ob_get_clean();
se_ok(strpos($html, 'ZZ-OWN-BRAND-APPT') !== false && strpos($html, 'ZZ-FOREIGN-BRAND-APPT') !== false,
    'admin still sees both rows');

/* ----------------------------------------------------------------------- */
se_test_webhook_purge_secrets();
se_test_reset();
