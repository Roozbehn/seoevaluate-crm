<?php
/**
 * HTTP test tier — real, signed requests against the DEPLOYED application.
 *
 *   php modules/se_core/tests/run_http.php             # full run
 *   php modules/se_core/tests/run_http.php --cleanup   # purge fixtures only
 *
 * Three sub-suites, reported separately:
 *   1. "Meta webhook"       — /se_core/leadgen
 *   2. "WhatsApp webhook"   — /se_whatsapp/webhook
 *   3. "Route/method/CSRF"  — the rest of the surface
 *
 * Every webhook response must carry the controller's marker header
 * (X-SE-Webhook) — a Perfex CSRF 403 has none, and any POST answered WITHOUT
 * the marker FAILS the suite. Fixtures are synthetic (ids >= 900000 /
 * ZZTEST…), signed with random per-run secret FILES that exist only for the
 * run, and removed by a guaranteed cleanup that then re-counts EVERY table
 * against the pre-run snapshot.
 *
 * The only deliberate table-count exception is the CI database-session table
 * (`sessions`): it churns with every live web request (the 15-minute cron
 * included), so this run deletes exactly its OWN session rows (by cookie-jar
 * id) and reports — rather than asserts — the background delta of that one
 * table. Every other table must match exactly.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/http/fixtures.php';

/* ======================================================================== */
/* --cleanup mode: restore renames, purge fixture rows, remove secrets.     */
/* ======================================================================== */

if (in_array('--cleanup', $argv ?? [], true)) {
    echo "== cleanup mode ==\n";
    se_http_restore_renames();

    $deleted = se_http_delete_fixture_rows();
    foreach ($deleted as $t => $n) { echo "   deleted {$n} row(s) from {$t}\n"; }
    if (!$deleted) { echo "   no fixture rows found\n"; }

    foreach (glob('/home/hyundaic/_w3/A/http_cookies_*.txt') ?: [] as $jar) {
        $ids = [];
        foreach (file($jar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos($line, '#HttpOnly_') === 0) { $line = substr($line, 10); }
            if ($line === '' || $line[0] === '#') { continue; }
            $parts = preg_split('/\t/', $line);
            $value = end($parts);
            if (is_string($value) && preg_match('/^[A-Za-z0-9\-,]{16,128}$/', $value)) { $ids[] = $value; }
        }
        if ($ids) {
            $in = implode(',', array_map('se_esc', array_unique($ids)));
            foreach (se_session_tables() as $table) {
                try { se_sql('DELETE FROM `' . $table . '` WHERE `id` IN (' . $in . ')'); } catch (Throwable $e) {}
            }
        }
        @unlink($jar);
    }

    se_http_remove_secrets();
    $residue = se_http_secret_residue();
    echo '   secret files remaining: ' . ($residue ? implode(', ', $residue) : 'none') . "\n";
    $GLOBALS['SE_HTTP_CLEANED'] = true;   // suppress the shutdown pass
    echo "cleanup done\n";
    exit($residue ? 1 : 0);
}

/* ======================================================================== */
/* Pre-run snapshot (EVERY table), then fixtures + synthetic secrets.       */
/* ======================================================================== */

$before          = se_all_table_counts();
$beforeAttempts  = [
    'outbox_attempts' => (int) se_scalar('SELECT COALESCE(SUM(attempts),0) FROM `' . db_prefix() . 'se_conversion_outbox`'),
    'wa_out_attempts' => (int) se_scalar('SELECT COALESCE(SUM(attempts),0) FROM `' . db_prefix() . 'se_wa_outbound`'),
    'gdm_requests'    => (int) se_scalar('SELECT COUNT(*) FROM `' . db_prefix() . 'se_gdm_requests`'),
];
$GLOBALS['SE_HTTP_OPTION_SNAPS']['se_meta_last_webhook_at'] = se_http_snapshot_option('se_meta_last_webhook_at');

echo 'pre-run snapshot: ' . count($before) . " tables counted\n";

$tag       = se_run_tag();
$p         = db_prefix();
$exitCode  = 1;

try {
    se_http_install_fixtures();
    se_http_install_secrets();
    echo "fixtures + synthetic secret files installed (run tag {$tag})\n";

    /* =================================================================== */
    se_group('Application reachable');
    $r = se_http('/admin/authentication');
    se_eq(200, $r['code'], 'the login page responds');

    /* =================================================================== */
    /* SUB-SUITE 1: Meta webhook                                           */
    /* =================================================================== */
    $S = 'Meta webhook';
    $eventsT   = $p . 'se_meta_leadgen_events';
    $lgValid   = 'ZZTEST-LG-' . $tag . '-V';
    $lgStore   = 'ZZTEST-LG-' . $tag . '-S';
    $lgNoMap   = 'ZZTEST-LG-' . $tag . '-U';
    $metaBody  = function ($leadgenId, $pageId, $formId) {
        return json_encode(['entry' => [['id' => $pageId, 'time' => 1700000000, 'changes' => [[
            'field' => 'leadgen',
            'value' => ['leadgen_id' => $leadgenId, 'page_id' => $pageId,
                        'form_id' => $formId, 'created_time' => 1700000000],
        ]]]]]);
    };
    $lgRow = function ($leadgenId) use ($eventsT) {
        return se_fetch_row("SELECT * FROM `{$eventsT}` WHERE leadgen_id = " . se_esc($leadgenId));
    };

    se_group($S . ': subscription verification (GET)');

    $chal = 'ZZ-CHALLENGE-' . $tag;
    $r = se_http('/se_core/leadgen?hub_mode=subscribe&hub_verify_token=' . rawurlencode($GLOBALS['SE_HTTP_VERIFY_META']) . '&hub_challenge=' . $chal);
    se_eq(200, $r['code'], 'a valid verification GET is accepted');
    se_eq($chal, $r['body'], 'the EXACT challenge is echoed as the bare body');
    se_eq('leadgen', $r['marker'], 'the marker header identifies the webhook');
    se_matrix_add($S, 'verification GET, valid token', $r, 'body === challenge');

    $r = se_http('/se_core/leadgen?hub_mode=subscribe&hub_verify_token=ZZ-wrong&hub_challenge=' . $chal);
    se_eq(403, $r['code'], 'a wrong verify token is refused');
    se_eq('verify_failed', $r['reason'], 'with a machine-readable reason');
    se_eq(false, strpos($r['body'], $chal) !== false, 'and the challenge is not echoed');
    se_matrix_add($S, 'verification GET, invalid token', $r, 'challenge not echoed');

    se_group($S . ': POST signature ladder');

    $raw = $metaBody($lgValid, 'ZZTEST-PG-' . $tag, 'ZZTEST-FM-' . $tag);

    $r = se_http('/se_core/leadgen', ['method' => 'POST', 'body' => $raw,
        'headers' => ['Content-Type: application/json']]);
    se_eq(401, $r['code'], 'missing signature -> 401');
    se_eq('leadgen', $r['marker'], 'the 401 came from the CONTROLLER (marker present, not the CSRF page)');
    se_eq('bad_signature', $r['reason'], 'reason bad_signature');
    se_eq(null, $lgRow($lgValid), 'no row stored');
    se_matrix_add($S, 'POST, missing signature', $r, 'no row');

    $r = se_http('/se_core/leadgen', ['method' => 'POST', 'body' => $raw,
        'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=' . str_repeat('0', 64)]]);
    se_eq(401, $r['code'], 'invalid signature -> 401');
    se_eq('leadgen', $r['marker'], 'marker present');
    se_eq(null, $lgRow($lgValid), 'no row stored');
    se_matrix_add($S, 'POST, invalid signature', $r, 'no row');

    $tampered = $raw;
    $pos = strpos($tampered, $tag);
    $tampered[$pos] = $tampered[$pos] === 'A' ? 'B' : 'A';
    $r = se_http('/se_core/leadgen', ['method' => 'POST', 'body' => $tampered,
        'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_meta($raw)]]);
    se_eq(401, $r['code'], 'one raw byte modified after signing -> 401');
    se_matrix_add($S, 'POST, byte modified after signing', $r, 'no row');

    se_group($S . ': size and JSON gates');

    $huge = str_repeat('x', SE_LEADGEN_MAX_BODY_BYTES + 1);
    $r = se_http('/se_core/leadgen', ['method' => 'POST', 'body' => $huge,
        'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_meta($huge)]]);
    se_eq(413, $r['code'], 'oversized body (limit+1, validly signed) -> 413');
    se_eq('leadgen', $r['marker'], 'marker present');
    se_eq('payload_too_large', $r['reason'], 'reason payload_too_large');
    se_matrix_add($S, 'POST, oversized (limit+1, valid sig)', $r, 'no row');

    $malformed = '{"entry":[{"broken":' . $tag;
    $r = se_http('/se_core/leadgen', ['method' => 'POST', 'body' => $malformed,
        'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_meta($malformed)]]);
    se_eq(400, $r['code'], 'malformed JSON (validly signed) -> 400');
    se_eq('malformed_json', $r['reason'], 'reason malformed_json');
    se_eq(0, se_count_where('se_meta_leadgen_events', "payload LIKE '%broken%'"), 'no row stored');
    se_matrix_add($S, 'POST, malformed JSON (valid sig)', $r, 'no row');

    se_group($S . ': durable acceptance and dedup');

    $r = se_http('/se_core/leadgen', ['method' => 'POST', 'body' => $raw,
        'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_meta($raw)]]);
    se_eq(200, $r['code'], 'a valid signed fixture -> 200');
    se_eq('accepted', $r['reason'], 'reason accepted');
    $row = $lgRow($lgValid);
    se_ok($row !== null, 'exactly the durable event row exists (found by synthetic id)');
    se_eq(1, se_count_where('se_meta_leadgen_events', 'leadgen_id = ' . se_esc($lgValid)), 'exactly ONE row');
    se_eq('1', (string) $row['signature_valid'], 'stored as signature_valid');
    se_matrix_add($S, 'POST, valid signed', $r, '1 durable row (' . $lgValid . ')');

    $r = se_http('/se_core/leadgen', ['method' => 'POST', 'body' => $raw,
        'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_meta($raw)]]);
    se_ok($r['code'] >= 200 && $r['code'] < 300, 'an identical redelivery is a harmless 2xx');
    se_eq('duplicate', $r['reason'], 'reason duplicate');
    se_eq(1, se_count_where('se_meta_leadgen_events', 'leadgen_id = ' . se_esc($lgValid)), 'STILL exactly one row');
    se_matrix_add($S, 'POST, duplicate delivery', $r, 'still 1 row');

    se_group($S . ': unsupported methods');

    foreach (['PUT', 'DELETE'] as $m) {
        $r = se_http('/se_core/leadgen', ['method' => $m, 'body' => $raw,
            'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_meta($raw)]]);
        se_eq(405, $r['code'], $m . ' -> 405');
        se_eq('GET, POST', $r['allow'], 'Allow header names GET, POST');
        se_eq('method_not_allowed', $r['reason'], 'reason method_not_allowed');
        se_matrix_add($S, $m . ' request', $r, 'Allow: ' . $r['allow']);
    }

    se_group($S . ': unknown mapping is stored then PARKED, no lead created');

    $leadsBefore = (int) se_scalar("SELECT COUNT(*) FROM `{$p}leads`");
    $rawU = $metaBody($lgNoMap, 'ZZTEST-PG-NOMAP', 'ZZTEST-FM-NOMAP');
    $r = se_http('/se_core/leadgen', ['method' => 'POST', 'body' => $rawU,
        'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_meta($rawU)]]);
    se_eq(200, $r['code'], 'an unmapped page/form is still ACCEPTED durably (routing is async by design)');
    $rowU = $lgRow($lgNoMap);
    se_ok($rowU !== null, 'the event row exists');

    $outcome = se_leadgen_process_event($rowU);
    se_eq('unmapped', $outcome, 'in-process routing PARKS it as unmapped (would be state=failed by the drainer)');
    se_eq($leadsBefore, (int) se_scalar("SELECT COUNT(*) FROM `{$p}leads`"), 'NO operational lead row was created');
    se_sql("UPDATE `{$eventsT}` SET state='failed', last_error='no active page+form mapping' WHERE leadgen_id = " . se_esc($lgNoMap));
    se_matrix_add($S, 'POST, unknown page/form mapping', $r, 'stored; parked unmapped; 0 leads');

    se_group($S . ': cross-brand routing conflict is parked, never re-tenanted');

    // The stored VALID event routes to brand A, but a brand-B lead already
    // claims its meta_lead_id. Drive the processor with a registered
    // (network-free) fetcher: the upsert must refuse the brand move.
    se_leadgen_register_fetcher(function ($leadgen_id, $brand_id) {
        return [['name' => 'full_name', 'values' => ['ZZTEST Http Case']]];
    });
    $outcome = se_leadgen_process_event($lgRow($lgValid));
    se_eq('brand_mismatch', $outcome, 'the processor parks the event as brand_mismatch');
    $lead = se_fetch_row("SELECT id, brand_id FROM `{$p}leads` WHERE meta_lead_id = " . se_esc($lgValid));
    se_eq(SE_HTTP_BRAND_B, (int) $lead['brand_id'], "the existing lead's brand is UNCHANGED (no cross-brand write)");
    se_eq(1, se_count_where('leads', 'meta_lead_id = ' . se_esc($lgValid)), 'and no second lead appeared');
    se_net_install_fixtures();   // restore the counting fetcher seam
    se_matrix_add($S, 'cross-brand routing conflict (in-process)', ['code' => '-', 'marker' => '-', 'reason' => 'brand_mismatch'], 'lead brand unchanged');

    // Park the synthetic events so the live cron never claims them.
    se_sql("UPDATE `{$eventsT}` SET state='processed' WHERE leadgen_id = " . se_esc($lgValid));

    se_group($S . ': storage failure is an honest 5xx, never 2xx');

    $preCount = (int) se_scalar("SELECT COUNT(*) FROM `{$eventsT}`");
    $rawS = $metaBody($lgStore, 'ZZTEST-PG-' . $tag, 'ZZTEST-FM-' . $tag);
    se_http_rename_away('se_meta_leadgen_events');
    try {
        $r = se_http('/se_core/leadgen', ['method' => 'POST', 'body' => $rawS,
            'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_meta($rawS)]]);
    } finally {
        se_http_rename_back('se_meta_leadgen_events');
    }
    se_ok($r['code'] >= 500 && $r['code'] <= 503, 'events table unavailable -> ' . $r['code'] . ' (5xx, never 2xx)');
    se_ok($r['code'] < 200 || $r['code'] >= 300, 'a storage failure is NEVER acknowledged as accepted');
    se_eq(1, se_sql("SHOW TABLES LIKE '{$eventsT}'")->num_rows, 'the table is restored');
    se_eq($preCount, (int) se_scalar("SELECT COUNT(*) FROM `{$eventsT}`"), 'with the identical pre-failure row count');
    se_eq(null, $lgRow($lgStore), 'and no row for the refused delivery');
    se_matrix_add($S, 'POST, storage failure (table renamed)', $r, 'no row; table restored');

    /* =================================================================== */
    /* SUB-SUITE 2: WhatsApp webhook                                       */
    /* =================================================================== */
    $S = 'WhatsApp webhook';
    $waT     = $p . 'se_wa_webhook_events';
    $pnA     = 'ZZPNA' . $tag;
    $pnB     = 'ZZPNB' . $tag;
    $wamid   = 'wamid.ZZTEST' . $tag;
    $waBody  = function (array $value) use ($tag) {
        return json_encode(['object' => 'whatsapp_business_account',
            'entry' => [['id' => 'ZZWABA' . $tag, 'changes' => [['field' => 'messages', 'value' => $value]]]]]);
    };
    $waRowByHash = function ($raw) use ($waT) {
        return se_fetch_row("SELECT * FROM `{$waT}` WHERE event_hash = " . se_esc(hash('sha256', $raw)));
    };

    se_group($S . ': subscription verification (GET)');

    $chal = 'ZZ-WA-CHALLENGE-' . $tag;
    $r = se_http('/se_whatsapp/webhook?hub_mode=subscribe&hub_verify_token=' . rawurlencode($GLOBALS['SE_HTTP_VERIFY_WA']) . '&hub_challenge=' . $chal);
    se_eq(200, $r['code'], 'a valid verification GET is accepted');
    se_eq($chal, $r['body'], 'the EXACT challenge is echoed');
    se_eq('whatsapp', $r['marker'], 'marker header present');
    se_matrix_add($S, 'verification GET, valid token', $r, 'body === challenge');

    $r = se_http('/se_whatsapp/webhook?hub_mode=subscribe&hub_verify_token=ZZ-wrong&hub_challenge=' . $chal);
    se_eq(403, $r['code'], 'a wrong verify token is refused');
    se_eq('verify_failed', $r['reason'], 'machine-readable reason');
    se_matrix_add($S, 'verification GET, invalid token', $r, 'challenge not echoed');

    se_group($S . ': POST signature ladder');

    $rawMsg = $waBody(['metadata' => ['display_phone_number' => 'ZZ', 'phone_number_id' => $pnA],
        'contacts' => [['profile' => ['name' => 'ZZTEST'], 'wa_id' => 'ZZUSER2' . $tag]],
        'messages' => [['from' => 'ZZUSER2' . $tag, 'id' => 'wamid.ZZTESTIN' . $tag,
                        'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'zz hello']]]]);

    $r = se_http('/se_whatsapp/webhook', ['method' => 'POST', 'body' => $rawMsg,
        'headers' => ['Content-Type: application/json']]);
    se_eq(401, $r['code'], 'missing signature -> 401');
    se_eq('whatsapp', $r['marker'], 'the 401 came from the CONTROLLER (marker present, not the CSRF page)');
    se_eq(null, $waRowByHash($rawMsg), 'no row stored');
    se_matrix_add($S, 'POST, missing signature', $r, 'no row');

    $r = se_http('/se_whatsapp/webhook', ['method' => 'POST', 'body' => $rawMsg,
        'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=' . str_repeat('0', 64)]]);
    se_eq(401, $r['code'], 'invalid signature -> 401');
    se_eq(null, $waRowByHash($rawMsg), 'no row stored');
    se_matrix_add($S, 'POST, invalid signature', $r, 'no row');

    $tampered = $rawMsg;
    $pos = strpos($tampered, 'hello');
    $tampered[$pos] = 'H';
    $r = se_http('/se_whatsapp/webhook', ['method' => 'POST', 'body' => $tampered,
        'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_wa($rawMsg)]]);
    se_eq(401, $r['code'], 'one raw byte modified after signing -> 401');
    se_matrix_add($S, 'POST, byte modified after signing', $r, 'no row');

    se_group($S . ': size and JSON gates');

    $huge = str_repeat('y', SE_WA_MAX_BODY_BYTES + 1);
    $r = se_http('/se_whatsapp/webhook', ['method' => 'POST', 'body' => $huge,
        'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_wa($huge)]]);
    se_eq(413, $r['code'], 'oversized body (limit+1, validly signed) -> 413');
    se_eq('payload_too_large', $r['reason'], 'reason payload_too_large');
    se_matrix_add($S, 'POST, oversized (limit+1, valid sig)', $r, 'no row');

    $malformed = '{"entry":[{"zz":' . $tag;
    $r = se_http('/se_whatsapp/webhook', ['method' => 'POST', 'body' => $malformed,
        'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_wa($malformed)]]);
    se_eq(400, $r['code'], 'malformed JSON (validly signed) -> 400');
    se_eq('malformed_json', $r['reason'], 'reason malformed_json');
    se_eq(null, $waRowByHash($malformed), 'no row stored');
    se_matrix_add($S, 'POST, malformed JSON (valid sig)', $r, 'no row');

    se_group($S . ': durable acceptance, dedup, and NO inline processing');

    $r = se_http('/se_whatsapp/webhook', ['method' => 'POST', 'body' => $rawMsg,
        'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_wa($rawMsg)]]);
    se_eq(200, $r['code'], 'a valid signed fixture -> 200');
    se_eq('accepted', $r['reason'], 'reason accepted');
    $row = $waRowByHash($rawMsg);
    se_ok($row !== null, 'the durable event row exists (found by body hash)');
    se_eq($pnA, $row['phone_number_id'], 'routing metadata extracted');
    se_eq(0, se_count_where('se_wa_conversations', 'wa_user_id = ' . se_esc('ZZUSER2' . $tag)),
        'no conversation row inline — parsing is async by design');
    se_matrix_add($S, 'POST, valid signed message', $r, '1 durable row, async parse');

    $r = se_http('/se_whatsapp/webhook', ['method' => 'POST', 'body' => $rawMsg,
        'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_wa($rawMsg)]]);
    se_ok($r['code'] >= 200 && $r['code'] < 300, 'identical redelivery is a harmless 2xx');
    se_eq('duplicate', $r['reason'], 'reason duplicate');
    se_eq(1, se_count_where('se_wa_webhook_events', 'event_hash = ' . se_esc(hash('sha256', $rawMsg))), 'STILL exactly one row');
    se_matrix_add($S, 'POST, duplicate delivery', $r, 'still 1 row');

    // Park the message event before the live cron can claim it (its inbound
    // rows would be synthetic and cleaned up, but determinism is better).
    se_sql("UPDATE `{$waT}` SET state='processed' WHERE event_hash = " . se_esc(hash('sha256', $rawMsg)));

    se_group($S . ': unsupported methods');

    foreach (['PUT', 'DELETE'] as $m) {
        $r = se_http('/se_whatsapp/webhook', ['method' => $m, 'body' => $rawMsg,
            'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_wa($rawMsg)]]);
        se_eq(405, $r['code'], $m . ' -> 405');
        se_eq('GET, POST', $r['allow'], 'Allow header names GET, POST');
        se_matrix_add($S, $m . ' request', $r, 'Allow: ' . $r['allow']);
    }

    se_group($S . ': valid status callback transitions the brand-bound message');

    $rawStatus = $waBody(['metadata' => ['phone_number_id' => $pnA],
        'statuses' => [['id' => $wamid, 'status' => 'delivered', 'timestamp' => (string) time(),
                        'recipient_id' => 'ZZUSER' . $tag]]]);
    $r = se_http('/se_whatsapp/webhook', ['method' => 'POST', 'body' => $rawStatus,
        'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_wa($rawStatus)]]);
    se_eq(200, $r['code'], 'the signed status callback is accepted durably');
    $ev = $waRowByHash($rawStatus);
    se_ok($ev !== null, 'its event row exists');

    se_wa_process_event($ev);   // deterministic async step (what cron would do)

    $msg = se_fetch_row("SELECT brand_id, delivery_state FROM `{$p}se_wa_messages` WHERE wamid = " . se_esc($wamid));
    se_eq('delivered', $msg['delivery_state'], "the outbound message transitioned sent -> delivered");
    se_eq(SE_HTTP_BRAND_A, (int) $msg['brand_id'], 'with its brand UNCHANGED');
    $outb = se_fetch_row("SELECT brand_id, status, wamid FROM `{$p}se_wa_outbound` WHERE id = " . (SE_HTTP_ID_BASE + 601));
    se_eq(SE_HTTP_BRAND_A, (int) $outb['brand_id'], 'the outbound queue row is still brand-bound');
    se_sql("UPDATE `{$waT}` SET state='processed' WHERE event_hash = " . se_esc(hash('sha256', $rawStatus)));
    se_matrix_add($S, 'POST, valid status callback', $r, 'sent->delivered, brand unchanged');

    se_group($S . ': cross-brand status callback is refused (no transition)');

    // Brand B's number reports a status for brand A's message id.
    $rawCross = $waBody(['metadata' => ['phone_number_id' => $pnB],
        'statuses' => [['id' => $wamid, 'status' => 'read', 'timestamp' => (string) time()]]]);
    $r = se_http('/se_whatsapp/webhook', ['method' => 'POST', 'body' => $rawCross,
        'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_wa($rawCross)]]);
    se_eq(200, $r['code'], 'the callback is stored durably (decisions are async)');
    $ev = $waRowByHash($rawCross);

    se_wa_process_event($ev);

    $msg = se_fetch_row("SELECT brand_id, delivery_state FROM `{$p}se_wa_messages` WHERE wamid = " . se_esc($wamid));
    se_eq('delivered', $msg['delivery_state'], "brand A's message did NOT advance to read (brand-bound lookup found nothing)");
    se_eq(SE_HTTP_BRAND_A, (int) $msg['brand_id'], 'and its brand is unchanged');
    se_eq(0, se_count_where('se_wa_messages', 'brand_id = ' . SE_HTTP_BRAND_B), 'no brand-B message row appeared');
    se_eq(0, se_count_where('se_wa_conversations', 'brand_id = ' . SE_HTTP_BRAND_B), 'no brand-B conversation appeared');
    se_sql("UPDATE `{$waT}` SET state='processed' WHERE event_hash = " . se_esc(hash('sha256', $rawCross)));
    se_matrix_add($S, 'POST, cross-brand status callback', $r, 'stored; NO transition; no cross-brand write');

    se_group($S . ': unknown phone_number_id is stored then PARKED');

    $rawUnknown = $waBody(['metadata' => ['phone_number_id' => 'ZZPNX' . $tag],
        'messages' => [['from' => 'ZZUSER3' . $tag, 'id' => 'wamid.ZZTESTUX' . $tag,
                        'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'zz']]]]);
    $r = se_http('/se_whatsapp/webhook', ['method' => 'POST', 'body' => $rawUnknown,
        'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_wa($rawUnknown)]]);
    se_eq(200, $r['code'], 'an unknown number is still ACCEPTED durably');
    $ev = $waRowByHash($rawUnknown);
    se_ok($ev !== null, 'its event row exists');

    $threw = false;
    try { se_wa_process_event($ev); } catch (SeWaPermanentError $e) { $threw = true; }
    se_eq(true, $threw, 'in-process routing PARKS it (permanent routing failure — state=failed by the drainer)');
    se_eq(0, se_count_where('se_wa_conversations', 'phone_number_id = ' . se_esc('ZZPNX' . $tag)), 'NO conversation row created');
    se_eq(0, se_count_where('se_wa_messages', 'wamid = ' . se_esc('wamid.ZZTESTUX' . $tag)), 'NO message row created');
    se_sql("UPDATE `{$waT}` SET state='failed', last_error='routing failure' WHERE event_hash = " . se_esc(hash('sha256', $rawUnknown)));
    se_matrix_add($S, 'POST, unknown phone_number_id', $r, 'stored; parked; 0 operational rows');

    se_group($S . ': storage failure is an honest 5xx, never 2xx');

    $preCount = (int) se_scalar("SELECT COUNT(*) FROM `{$waT}`");
    $rawS = $waBody(['metadata' => ['phone_number_id' => $pnA],
        'statuses' => [['id' => 'wamid.ZZTESTSF' . $tag, 'status' => 'delivered']]]);
    se_http_rename_away('se_wa_webhook_events');
    try {
        $r = se_http('/se_whatsapp/webhook', ['method' => 'POST', 'body' => $rawS,
            'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_wa($rawS)]]);
    } finally {
        se_http_rename_back('se_wa_webhook_events');
    }
    se_ok($r['code'] >= 500 && $r['code'] <= 503, 'events table unavailable -> ' . $r['code'] . ' (5xx, never 2xx)');
    se_eq(1, se_sql("SHOW TABLES LIKE '{$waT}'")->num_rows, 'the table is restored');
    se_eq($preCount, (int) se_scalar("SELECT COUNT(*) FROM `{$waT}`"), 'with the identical pre-failure row count');
    se_eq(null, $waRowByHash($rawS), 'and no row for the refused delivery');
    se_matrix_add($S, 'POST, storage failure (table renamed)', $r, 'no row; table restored');

    /* =================================================================== */
    /* SUB-SUITE 3: Route/method/CSRF                                      */
    /* =================================================================== */
    $S = 'Route/method/CSRF';

    se_group($S . ': the CSRF exclusion is EXACT — nothing else was widened');

    $r = se_http('/admin/se_core/se_patients/save', ['method' => 'POST',
        'body' => 'name=ZZTEST', 'headers' => ['Content-Type: application/x-www-form-urlencoded']]);
    se_ok(in_array($r['code'], [403, 419], true), 'a normal admin POST without a CSRF token is still rejected (' . $r['code'] . ')');
    se_eq(null, $r['marker'], 'by the CSRF layer (no controller marker)');
    se_matrix_add($S, 'admin POST se_patients/save, no token', $r, 'CSRF page, no marker');

    $r = se_http('/admin/se_core/se_patients/save', ['method' => 'POST', 'body' => 'name=ZZTEST',
        'headers' => ['Content-Type: application/x-www-form-urlencoded', 'X-Requested-With: XMLHttpRequest']]);
    se_eq(419, $r['code'], 'the XHR variant gets the 419 Page Expired');
    se_matrix_add($S, 'admin POST (XHR), no token', $r, '419');

    // The /admin router ALIAS of each webhook must remain CSRF-protected:
    // the exclusion covers ONLY the canonical public URI.
    $aliasBody = '{"zz":"' . $tag . '"}';
    foreach (['/admin/se_core/leadgen', '/admin/se_whatsapp/webhook'] as $alias) {
        $r = se_http($alias, ['method' => 'POST', 'body' => $aliasBody,
            'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . se_sign_meta($aliasBody)]]);
        se_ok(in_array($r['code'], [403, 419], true), "POST {$alias} (router alias) is STILL CSRF-blocked ({$r['code']})");
        se_eq(null, $r['marker'], 'and never reached the controller');
        se_matrix_add($S, 'POST ' . $alias . ' (alias)', $r, 'CSRF-blocked, no marker');
    }

    // The /index route variants are NOT excluded either.
    foreach (['/se_core/leadgen/index', '/se_whatsapp/webhook/index'] as $variant) {
        $r = se_http($variant, ['method' => 'POST', 'body' => $aliasBody,
            'headers' => ['Content-Type: application/json']]);
        se_ok(in_array($r['code'], [403, 419], true), "POST {$variant} is not part of the exclusion ({$r['code']})");
        se_eq(null, $r['marker'], 'and never reached the controller');
    }

    se_group($S . ': unauthenticated admin GETs redirect to login');

    foreach (['/admin/se_core/se_dashboard', '/admin/se_core/se_outbox', '/admin/se_core/se_meta',
              '/admin/se_core/se_credentials', '/admin/se_core/se_reports/health',
              '/admin/se_whatsapp/se_whatsapp/inbox',
              '/admin/se_appointments/se_appointments/manage'] as $route) {
        $r = se_http($route);
        $redirectsToLogin = $r['code'] >= 300 && $r['code'] < 400
            && strpos((string) $r['location'], 'admin/authentication') !== false;
        se_ok($redirectsToLogin || $r['code'] === 403,
            "unauthenticated {$route} -> " . $r['code'] . ' ' . ($r['location'] ? '-> login' : ''));
    }

    se_group($S . ': mutation routes never execute on GET');

    foreach (['/admin/se_core/se_patients/archive/1',
              '/admin/se_appointments/se_appointments/delete/1',
              '/admin/se_core/se_outbox/requeue/1',
              '/admin/se_core/se_meta/requeue/1',
              '/admin/se_whatsapp/se_whatsapp/assign/1'] as $route) {
        $r = se_http($route);
        se_ok($r['code'] !== 200, "GET {$route} does not execute (got {$r['code']})");
    }

    se_group($S . ': webhook 405s (registered in the matrix above)');
    foreach (['/se_core/leadgen', '/se_whatsapp/webhook'] as $hook) {
        $r = se_http($hook, ['method' => 'DELETE']);
        se_eq(405, $r['code'], "DELETE {$hook} -> 405");
    }

    se_group($S . ': harness, secrets and logs are not web-reachable');

    foreach (['/modules/se_core/tests/run.php', '/modules/se_core/tests/run_db.php',
              '/modules/se_core/tests/run_http.php', '/modules/se_core/tests/http/fixtures.php',
              '/modules/se_core/tests/bootstrap.php', '/modules/se_core/tests/real_db.php'] as $route) {
        $r = se_http($route);
        se_eq(403, $r['code'], "{$route} is denied");
    }

    foreach (['/error_log', '/error_log.1', '/application/logs/log-2026-01-01.php',
              '/_evidence/logs/php_cli_error.log', '/_w3/A/http_cookies.txt',
              '/_secrets/meta_app', '/home/hyundaic/_secrets/meta_app'] as $route) {
        $r = se_http($route);
        se_ok(in_array($r['code'], [403, 404], true), "{$route} is not served (got {$r['code']})");
    }

    /* =================================================================== */
    se_group('Zero outbound: static source assertion + transport counters');

    $recvSources = [
        'Leadgen webhook controller'  => file_get_contents($SE_HTTP_ROOT . 'modules/se_core/controllers/Leadgen.php'),
        'WhatsApp webhook controller' => file_get_contents($SE_HTTP_ROOT . 'modules/se_whatsapp/controllers/Webhook.php'),
    ];
    foreach (['se_leadgen_store_event' => $SE_HTTP_ROOT . 'modules/se_core/se_meta_leadgen.php',
              'se_leadgen_receive_outcome' => $SE_HTTP_ROOT . 'modules/se_core/se_meta_leadgen.php',
              'se_wa_store_event' => $SE_HTTP_ROOT . 'modules/se_whatsapp/helpers.php',
              'se_wa_receive_outcome' => $SE_HTTP_ROOT . 'modules/se_whatsapp/helpers.php'] as $fn => $file) {
        $src = file_get_contents($file);
        if (preg_match('/function ' . $fn . '\(.*?\n}/s', $src, $m)) {
            $recvSources[$fn . '()'] = $m[0];
        } else {
            se_ok(false, "could not extract {$fn}() source for the static scan");
        }
    }
    foreach ($recvSources as $what => $src) {
        $hasNet = preg_match('/curl_init|curl_exec|fsockopen|stream_socket_client|file_get_contents\s*\(\s*[\'"]https?:/i', (string) $src);
        se_eq(0, $hasNet, "{$what} performs no outbound network call (static scan)");
    }

    se_eq(0, se_net_kill_count(), 'no in-process outbound transport seam fired');

    $exitCode = 0;
} catch (Throwable $e) {
    echo "\n!! ABORTED: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $GLOBALS['se_assert']['fail']++;
    $GLOBALS['se_assert']['failures'][] = 'ABORT :: ' . $e->getMessage();
} finally {
    se_http_cleanup();
}

/* ======================================================================== */
/* Post-cleanup verification: every table count restored; zero residue.     */
/* ======================================================================== */

se_group('Cleanup verification: every table count matches the pre-run snapshot');

$after      = se_all_table_counts();
$sessTables = se_session_tables();
$mismatch   = [];

foreach ($before as $t => $n) {
    $now = $after[$t] ?? -1;

    if (in_array($t, $sessTables, true)) {
        // Documented exception: DB sessions churn with ANY live request (the
        // 15-minute cron included). This run deleted its OWN session rows by
        // exact id; any remaining delta is background traffic, reported here.
        echo "   note: {$t} {$n} -> {$now} (live session churn; own rows deleted by id)\n";
        continue;
    }

    if ($now !== $n) { $mismatch[] = "{$t}: {$n} -> {$now}"; }
}

se_eq('[]', json_encode($mismatch), 'all ' . (count($before) - count($sessTables)) . ' non-session tables match the snapshot exactly');

se_eq($beforeAttempts['outbox_attempts'],
    (int) se_scalar('SELECT COALESCE(SUM(attempts),0) FROM `' . db_prefix() . 'se_conversion_outbox`'),
    'conversion-outbox attempt counters unchanged (no transmit attempt)');
se_eq($beforeAttempts['wa_out_attempts'],
    (int) se_scalar('SELECT COALESCE(SUM(attempts),0) FROM `' . db_prefix() . 'se_wa_outbound`'),
    'wa-outbound attempt counters unchanged (no transmit attempt)');
se_eq($beforeAttempts['gdm_requests'],
    (int) se_scalar('SELECT COUNT(*) FROM `' . db_prefix() . 'se_gdm_requests`'),
    'no Google Data Manager request row appeared');

$residue = se_http_secret_residue();
se_eq('[]', json_encode($residue), 'NO synthetic secret file remains for any provider');
se_ok(!is_dir('/home/hyundaic/_secrets') || !array_filter(scandir('/home/hyundaic/_secrets'), function ($f) {
    foreach (array_keys(se_secret_providers()) as $pv) {
        if ($f === $pv || strpos($f, $pv . '_') === 0) { return true; }
    }
    return false;
}), '/home/hyundaic/_secrets holds no provider files');

se_eq(0, count(se_http_delete_fixture_rows()), 'a second purge pass finds ZERO fixture rows (zero residue)');

/* ======================================================================== */

se_matrix_print();

$a = $GLOBALS['se_assert'];

echo "\n============================================\n";
echo "HTTP tier (sub-suites: Meta webhook / WhatsApp webhook / Route-method-CSRF)\n";
echo "PASS   : {$a['pass']}\n";
echo "FAIL   : {$a['fail']}\n";

if ($a['fail'] > 0) {
    echo "\nFailures:\n";
    foreach ($a['failures'] as $f) { echo "  - {$f}\n"; }
    exit(1);
}

echo "ALL HTTP TESTS PASSED\n";
exit($exitCode);
