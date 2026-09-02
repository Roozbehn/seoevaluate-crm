<?php
/**
 * Patient journey (se_journey) — sealed photo storage on Cloudflare R2.
 *
 * The journey never holds an R2 credential: sealed bytes go through the same
 * crm-media gateway the inbox store uses (services/crm-media), under
 * crm/journey/<brand>/<journey>/<random>.enc. Covered here:
 *   - driver selection (auto → R2 once the gateway is ready, never before)
 *   - a WhatsApp photo sealed straight to R2: ciphertext in the bucket, JPEG
 *     back through se_journey_media_read, no local file
 *   - migration of earlier local objects (upload → read back → compare → unlink)
 *   - gateway down at write time → local fallback + visible event, migrated later
 *   - optional purge of the inbox's plain copy after sealing (local unlink,
 *     R2 DELETE, and an old Worker without DELETE → kept + task)
 *   - erasure of a journey object through the gateway
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/journey_fixtures.php';

/* ======================================================================== */
se_group('Journey R2: driver stays local until the gateway is ready');

$j  = se_test_journey_reviewed();      // three photos sealed while R2 is NOT configured
$db = se_test_db();
$GLOBALS['SE_MEDIA_HTTP'] = null;

se_eq('local', se_journey_media_storage_driver(), 'no gateway URL/key → local');
update_option('se_journey_media_storage', 'r2');
se_eq('local', se_journey_media_storage_driver(), 'asking for r2 without a ready gateway still writes locally (never drops a photo)');
update_option('se_journey_media_storage', 'auto');
$localRows = array_values(array_filter($db->rows('tblse_journey_media'), function ($m) { return $m['state'] === 'received'; }));
se_eq(3, count($localRows), 'three sealed photos exist');
foreach ($localRows as $m) {
    se_eq('local', $m['storage'], 'photo ' . $m['id'] . ' recorded as local');
    se_ok(is_file(se_journey_media_dir() . '/' . $m['storage_ref']), 'and is on disk');
}

/* --- configure the gateway + a fake Worker behind the HTTP seam ----------- */
update_option('se_media_r2_url', 'https://crm-media.example.workers.dev/');
se_test_install_secret('r2_media_key', 'fixture-not-a-real-key');
se_eq('r2', se_journey_media_storage_driver(), 'gateway ready → auto driver is r2');

$bucket = []; $calls = []; $workerHasDelete = true; $gatewayDown = false;
se_media_register_http(function ($method, $url, $headers, $body) use (&$bucket, &$calls, &$workerHasDelete, &$gatewayDown) {
    $calls[] = [$method, $url];
    if ($gatewayDown) { return ['code' => 503, 'body' => '', 'headers' => []]; }
    $key  = rawurldecode(substr((string) parse_url($url, PHP_URL_PATH), 3));   // strip "/o/"
    $auth = '';
    foreach ($headers as $h) { if (stripos($h, 'Authorization:') === 0) { $auth = trim(substr($h, 14)); } }
    if ($auth !== 'Bearer fixture-not-a-real-key') { return ['code' => 401, 'body' => '', 'headers' => []]; }
    if (strpos($key, 'crm/') !== 0) { return ['code' => 404, 'body' => '{"ok":false,"reason":"bad_key"}', 'headers' => []]; }
    if ($method === 'PUT') { $bucket[$key] = $body; return ['code' => 200, 'body' => '{"ok":true}', 'headers' => []]; }
    if ($method === 'GET') { return isset($bucket[$key]) ? ['code' => 200, 'body' => $bucket[$key], 'headers' => []] : ['code' => 404, 'body' => '', 'headers' => []]; }
    if ($method === 'DELETE') {
        if (!$workerHasDelete) { return ['code' => 405, 'body' => '', 'headers' => []]; }
        unset($bucket[$key]);
        return ['code' => 204, 'body' => '', 'headers' => []];
    }
    return ['code' => 405, 'body' => '', 'headers' => []];
});

/* ======================================================================== */
se_group('Journey R2: a new WhatsApp photo is sealed straight into R2');

se_test_media_fetcher(function ($id) { return ['ok' => true, 'bytes' => se_test_jpeg(), 'mime' => 'image/jpeg']; });
$db->tables['tblse_wa_conversations'][0]['window_expires_at'] = date('Y-m-d H:i:s', time() + 30 * 86400);
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['image' => ['id' => 'R2-P4', 'mime_type' => 'image/jpeg']]));
$new = se_test_last_row('tblse_journey_media');
se_eq('received', $new['state'], 'the fourth photo is sealed');
se_eq('r2', $new['storage'], 'and recorded as R2');
$key = 'crm/journey/' . (int) $j->brand_id . '/' . (int) $j->id . '/' . basename((string) $new['storage_ref']);
se_ok(isset($bucket[$key]), 'the object sits under crm/journey/<brand>/<journey>/ in the bucket');
se_eq(1, preg_match('#^crm/journey/\d+/\d+/[0-9a-f]{32}\.enc$#', $key), 'with an unguessable name and .enc suffix');
se_ok(strpos($bucket[$key], "\xff\xd8") !== 0 && strpos($bucket[$key], 'v1:') === 0, 'what the bucket holds is ciphertext, not a JPEG');
se_ok(!is_file(se_journey_media_dir() . '/' . $new['storage_ref']), 'nothing was written locally');
$plain = se_journey_media_read((object) $new);
se_ok($plain !== null && strpos($plain, "\xff\xd8") === 0, 'reading the row through the gateway decrypts back to the JPEG');
se_eq(1, count(array_filter($calls, function ($c) { return $c[0] === 'PUT' && strpos($c[1], '/o/crm/journey/') !== false; })), 'exactly one PUT for the photo');
foreach ($calls as $c) { se_ok(strpos($c[1], '?sig=') === false && strpos($c[1], '&sig=') === false, 'no signed public URL is ever minted for a journey object (' . $c[0] . ')'); }

/* ======================================================================== */
se_group('Journey R2: earlier local objects migrate — upload, verify, unlink');

$before = count($bucket);
$r = se_journey_media_migrate_to_r2();
se_eq(3, $r['moved'], 'the three local photos moved');
se_eq(0, $r['failed'], 'none failed');
se_eq($before + 3, count($bucket), 'three new objects in the bucket');
foreach ($localRows as $m) {
    $row = null; foreach ($db->rows('tblse_journey_media') as $x) { if ((int) $x['id'] === (int) $m['id']) { $row = $x; } }
    se_eq('r2', $row['storage'], 'row ' . $m['id'] . ' now says r2');
    se_ok(!is_file(se_journey_media_dir() . '/' . $m['storage_ref']), 'local file ' . $m['id'] . ' removed after verification');
    $p = se_journey_media_read((object) $row);
    se_ok($p !== null && strpos($p, "\xff\xd8") === 0, 'row ' . $m['id'] . ' still decrypts');
}
se_eq(0, se_journey_media_migrate_to_r2()['moved'], 'a second run finds nothing to move');

/* ======================================================================== */
se_group('Journey R2: gateway down at write time → local fallback, visible, migrated later');

$gatewayDown = true;
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['image' => ['id' => 'R2-P5', 'mime_type' => 'image/jpeg']]));
$fb = se_test_last_row('tblse_journey_media');
se_eq('received', $fb['state'], 'the photo is still accepted');
se_eq('local', $fb['storage'], 'sealed locally instead');
se_ok(is_file(se_journey_media_dir() . '/' . $fb['storage_ref']), 'file on disk');
se_eq(1, count(array_filter($db->rows('tblse_journey_events'), function ($e) { return $e['kind'] === 'media_store_fallback'; })), 'a visible fallback event was recorded');
$gatewayDown = false;
$r = se_journey_media_migrate_to_r2();
se_eq(1, $r['moved'], 'the cron moves it up once the gateway answers');
$fbRow = null; foreach ($db->rows('tblse_journey_media') as $x) { if ((int) $x['id'] === (int) $fb['id']) { $fbRow = $x; } }
se_eq('r2', $fbRow['storage'], 'row now r2');

/* ======================================================================== */
se_group('Journey R2: optional purge of the plain inbox copy after sealing');

update_option('se_journey_purge_inbox_copy_' . (int) $j->brand_id, 1);

// Inbox copy on local disk.
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['image' => ['id' => 'R2-P6', 'mime_type' => 'image/jpeg']]));
$sealed6 = se_test_last_row('tblse_journey_media');
$inbox6  = se_media_get((int) $sealed6['inbox_media_id']);
se_eq('purged', $inbox6['state'], 'the inbox row is marked purged');
se_eq('', (string) $inbox6['path'], 'its path is cleared');
se_eq('', se_media_abs_path(['state' => 'stored', 'path' => 'wa/1/' . (int) $inbox6['id'] . '.jpg']), 'and the plain file is gone from the inbox store');
se_ok(se_journey_media_read((object) $sealed6) !== null, 'while the sealed copy still opens');
se_eq(1, count(array_filter($db->rows('tblse_journey_events'), function ($e) { return $e['kind'] === 'inbox_copy_purged'; })), 'event recorded');

// Inbox copy in R2 (the inbox driver switched to r2): purge = gateway DELETE.
update_option('se_media_storage', 'r2');
se_eq('r2', se_media_storage_driver(), 'inbox driver is r2 too');
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['image' => ['id' => 'R2-P7', 'mime_type' => 'image/jpeg']]));
$sealed7 = se_test_last_row('tblse_journey_media');
$inbox7  = se_media_get((int) $sealed7['inbox_media_id']);
se_eq('purged', $inbox7['state'], 'R2 inbox copy purged through the gateway');
se_ok(!isset($bucket['crm/wa/1/' . (int) $inbox7['id'] . '.jpg']), 'the plain object is gone from the bucket');
se_ok(isset($bucket['crm/journey/' . (int) $j->brand_id . '/' . (int) $j->id . '/' . basename((string) $sealed7['storage_ref'])]), 'the sealed object remains');

// A Worker deployed before the DELETE route: keep the copy, open a task, never pretend.
$workerHasDelete = false;
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['image' => ['id' => 'R2-P8', 'mime_type' => 'image/jpeg']]));
$sealed8 = se_test_last_row('tblse_journey_media');
$inbox8  = se_media_get((int) $sealed8['inbox_media_id']);
se_eq('stored', $inbox8['state'], 'without DELETE on the Worker the inbox row stays stored');
se_ok(isset($bucket['crm/wa/1/' . (int) $inbox8['id'] . '.jpg']), 'and its object is untouched');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'inbox_purge_pending'; })), 'a task tells staff to redeploy crm-media');
$workerHasDelete = true;

/* ======================================================================== */
se_group('Journey R2: erasure removes the sealed object through the gateway');

$keyDel = 'crm/journey/' . (int) $j->brand_id . '/' . (int) $j->id . '/' . basename((string) $sealed7['storage_ref']);
se_eq('', se_journey_media_delete_object('r2', (string) $sealed7['storage_ref']), 'delete answers ok');
se_ok(!isset($bucket[$keyDel]), 'object gone');
se_eq('', se_journey_media_delete_object('r2', (string) $sealed7['storage_ref']), 'deleting again is idempotent (404 = already gone)');
$workerHasDelete = false;
se_eq('unsupported', se_journey_media_delete_object('r2', (string) $sealed8['storage_ref']), 'an old Worker reports unsupported instead of a false success');
$workerHasDelete = true;

/* Leave the shared fixture stores as this suite found them. */
update_option('se_media_storage', '');
update_option('se_media_r2_url', '');
update_option('se_journey_media_storage', '');
update_option('se_journey_purge_inbox_copy_' . (int) $j->brand_id, 0);
se_test_remove_secret('r2_media_key');
se_test_remove_secret('wa_token');
se_test_remove_secret('wa_app');
se_test_remove_secret('journey_key');
$GLOBALS['SE_WA_TRANSPORT'] = null;
$GLOBALS['SE_MEDIA_FETCHER'] = null;
$GLOBALS['SE_MEDIA_HTTP'] = null;
