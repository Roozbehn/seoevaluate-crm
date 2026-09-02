<?php
/**
 * R2 storage driver: writes go to the gateway Worker with the bearer key,
 * rows record storage='r2', staff views redirect to a signed URL, Instagram
 * gets a signed public URL, WhatsApp gets a temp local copy, and migration
 * moves local files up (verify-then-delete). All HTTP through the seam.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$db = se_test_db();
$db->tables = []; $db->autoinc = [];
$db->seed('tblse_brands', [['id' => 1, 'name' => 'Brand A', 'active' => 1]]);
$db->seed('tblse_media', []);
$db->seed('tblse_wa_numbers', [['id' => 1, 'brand_id' => 1, 'phone_number_id' => 'PN1', 'waba_id' => 'W1', 'state' => 'active', 'token_option_ref' => 'wa_token']]);
$db->seed('tblse_wa_conversations', [['id' => 901, 'brand_id' => 1, 'phone_number_id' => 'PN1', 'wa_user_id' => 'U1', 'lead_id' => 0,
    'assigned_staff' => 0, 'unread_count' => 0, 'state' => 'open', 'window_expires_at' => date('Y-m-d H:i:s', time() + 3600)]]);
$db->seed('tblse_wa_messages', []); $db->seed('tblse_wa_outbound', []); $db->seed('tblse_wa_templates', []); $db->seed('tblse_wa_metering', []);
$GLOBALS['se_test']['options'] = [];
$GLOBALS['SE_MEDIA_FETCHER'] = null; $GLOBALS['SE_MEDIA_HTTP'] = null; $GLOBALS['SE_WA_TRANSPORT'] = null;
se_test_act_as(10, ['se_whatsapp.create'], true);

$dir = defined('SE_MEDIA_DIR') ? SE_MEDIA_DIR : sys_get_temp_dir() . '/se_media_r2_test_' . getmypid();
if (!defined('SE_MEDIA_DIR')) { define('SE_MEDIA_DIR', $dir); }

/* --- not configured => local ---------------------------------------------- */
se_eq('local', se_media_storage_driver(), 'without a gateway URL/key the driver is local');
update_option('se_media_storage', 'r2');
se_eq('local', se_media_storage_driver(), 'asking for r2 without the key still falls back to local (never silently drops files)');

/* --- configure the gateway + a fake Worker behind the seam --------------- */
update_option('se_media_r2_url', 'https://crm-media.example.workers.dev/');
se_test_install_secret('r2_media_key', 'fixture-not-a-real-key');
se_eq(true, se_media_r2_ready(), 'gateway ready');
se_eq('r2', se_media_storage_driver(), 'driver is r2');

$bucket = []; $calls = [];
se_media_register_http(function ($method, $url, $headers, $body) use (&$bucket, &$calls) {
    $calls[] = [$method, $url, $headers];
    $path = parse_url($url, PHP_URL_PATH);
    $key  = rawurldecode(substr($path, 3));               // strip "/o/"
    $auth = '';
    foreach ($headers as $h) { if (stripos($h, 'Authorization:') === 0) { $auth = trim(substr($h, 14)); } }
    if ($auth !== 'Bearer fixture-not-a-real-key') { return ['code' => 401, 'body' => '', 'headers' => []]; }
    if ($method === 'PUT') { $bucket[$key] = $body; return ['code' => 200, 'body' => '{"ok":true}', 'headers' => []]; }
    if ($method === 'GET') { return isset($bucket[$key]) ? ['code' => 200, 'body' => $bucket[$key], 'headers' => []] : ['code' => 404, 'body' => '', 'headers' => []]; }
    return ['code' => 405, 'body' => '', 'headers' => []];
});

/* --- inbound fetch stores to R2 ------------------------------------------ */
$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 64);
$id = se_media_enqueue('wa', 501, 1, 'image', 'MEDIA-R2');
se_media_register_fetcher(function ($row) use ($jpeg) { return ['ok' => true, 'bytes' => $jpeg, 'mime' => 'image/jpeg', 'error' => '']; });
se_media_fetch_pending();
$row = se_media_get($id);
se_eq('stored', $row['state'], 'inbound image stored');
se_eq('r2', $row['storage'], 'in R2');
se_eq('wa/1/' . $id . '.jpg', $row['path'], 'with the same relative layout');
se_ok(isset($bucket['crm/wa/1/' . $id . '.jpg']), 'the object landed under the crm/ prefix');
se_eq($jpeg, $bucket['crm/wa/1/' . $id . '.jpg'], 'byte-for-byte');
se_ok(!is_file($dir . '/wa/1/' . $id . '.jpg'), 'and nothing was written to the CRM disk');
$put = $calls[0];
se_eq('PUT', $put[0], 'one PUT');
se_ok(in_array('Content-Type: image/jpeg', $put[2], true), 'with the content type for R2 metadata');
se_ok(strpos($put[1], 'https://crm-media.example.workers.dev/o/crm/wa/1/') === 0, 'to the gateway, path-encoded with slashes kept');

/* --- staff view: redirect to a signed URL -------------------------------- */
$now = 1_800_000_000;
$url = se_media_view_redirect($row, $now);
se_ok(strpos($url, 'https://crm-media.example.workers.dev/o/crm/wa/1/' . $id . '.jpg?exp=' . ($now + 600) . '&sig=') === 0, 'staff view redirects to a 10-minute signed gateway URL');
parse_str(parse_url($url, PHP_URL_QUERY), $q);
se_eq(hash_hmac('sha256', 'crm/wa/1/' . $id . '.jpg|' . ($now + 600), 'fixture-not-a-real-key'), $q['sig'], 'signature = HMAC-SHA256(key, objectKey|exp) — the Worker checks exactly this');
se_eq('', se_media_view_redirect(['storage' => 'local', 'state' => 'stored', 'path' => 'x'], $now), 'local rows are streamed by the CRM, no redirect');
se_eq(true, se_media_available($row), 'available');
se_eq('', se_media_abs_path($row), 'no local path for an R2 row');

/* --- outbound upload goes to R2; WhatsApp gets a temp local copy --------- */
$tmp = tempnam(sys_get_temp_dir(), 'seup'); file_put_contents($tmp, $jpeg);
$up = se_media_store_upload('wa', 1, ['name' => 'foto.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($jpeg)], 10);
se_eq(true, $up['ok'], 'composer upload accepted');
$out = se_media_get($up['id']);
se_eq('r2', $out['storage'], 'stored in R2');
se_ok(isset($bucket['crm/wa/1/' . $up['id'] . '.jpg']), 'object present');
se_ok(!file_exists($tmp), 'the PHP upload temp file is removed');

$copy = se_media_local_copy($out);
se_ok($copy !== '' && is_file($copy) && file_get_contents($copy) === $jpeg, 'a temp local copy is produced for the multipart upload');
@unlink($copy);

se_test_install_secret('wa_app', 'fixture-not-a-real-secret'); se_test_install_secret('wa_token', 'fixture-not-a-real-token');
$sent = [];
se_wa_register_transport(function ($m) use (&$sent) {
    $sent[] = ['path' => $m['media']['abs_path'], 'exists_at_send' => is_file($m['media']['abs_path']), 'storage' => $m['media']['storage']];
    return ['ok' => true, 'wamid' => 'wamid.R2', 'code' => 200, 'error' => ''];
});
$q = se_wa_queue_message(901, ['kind' => 'media', 'media_id' => $up['id'], 'body' => 'r2 caption'], 10);
se_eq(true, $q['ok'], 'queued');
se_wa_out_drain();
se_eq(1, count($sent), 'sent through the transport');
se_eq(true, $sent[0]['exists_at_send'], 'the transport saw a real file');
se_eq('r2', $sent[0]['storage'], 'from an R2 row');
se_ok(!is_file($sent[0]['path']), 'and the temp copy was removed after the attempt');

/* --- Instagram public URL is the signed gateway URL ---------------------- */
$pub = se_media_public_url($out, $now);
se_ok(strpos($pub, 'https://crm-media.example.workers.dev/o/crm/wa/1/' . $up['id'] . '.jpg?exp=' . ($now + 3600) . '&sig=') === 0, 'Instagram fetch URL = 1-hour signed gateway URL');
se_ok(strpos(se_media_public_url(['storage' => 'local', 'id' => 7, 'state' => 'stored', 'direction' => 'out', 'path' => 'ig/1/7.png'], $now), '/se_core/se_media_pub/index/7/') === 0,
    'local rows keep the CRM-served signed route');

/* --- migration: local → r2, verify then delete --------------------------- */
$GLOBALS['se_test']['options']['se_media_storage'] = 'local';
se_eq('local', se_media_storage_driver(), 'switch new writes back to local for the fixture');
$png = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 20);
$lid = se_media_enqueue('ig', 777, 1, 'image', 'https://cdn.example/x');
se_media_register_fetcher(function ($row) use ($png) { return ['ok' => true, 'bytes' => $png, 'mime' => 'image/png', 'error' => '']; });
se_media_fetch_pending();
$local = se_media_get($lid);
se_eq('local', $local['storage'], 'fixture row is local');
se_ok(is_file($dir . '/' . $local['path']), 'on disk');

$m = se_media_migrate_local_to_r2();
se_eq(1, $m['moved'], 'one row migrated');
se_eq(0, $m['failed'], 'no failures');
se_eq('r2', se_media_get($lid)['storage'], 'row now says r2');
se_eq($png, $bucket['crm/' . $local['path']], 'bytes are in the bucket');
se_ok(!is_file($dir . '/' . $local['path']), 'local file deleted after verification');
$m = se_media_migrate_local_to_r2();
se_eq(0, $m['moved'], 'a second run finds nothing to do');

// A gateway that rejects the write leaves the local file alone.
$GLOBALS['se_test']['options']['se_media_storage'] = 'local';
$lid2 = se_media_enqueue('wa', 778, 1, 'image', 'MEDIA-KEEP');
se_media_register_fetcher(function ($row) use ($jpeg) { return ['ok' => true, 'bytes' => $jpeg, 'mime' => 'image/jpeg', 'error' => '']; });
se_media_fetch_pending();
$keep = se_media_get($lid2);
se_media_register_http(function ($method) { return ['code' => 503, 'body' => '', 'headers' => []]; });
$m = se_media_migrate_local_to_r2();
se_eq(1, $m['failed'], 'a failed upload is reported');
se_eq('local', se_media_get($lid2)['storage'], 'the row stays local');
se_ok(is_file($dir . '/' . $keep['path']), 'and the file is kept');

// cleanup
foreach (glob($dir . '/*/*/*') as $f) { @unlink($f); }
foreach (glob($dir . '/*/*') as $d) { @rmdir($d); }
foreach (glob($dir . '/*') as $d) { @rmdir($d); }
@rmdir($dir);
se_test_remove_secret('r2_media_key'); se_test_remove_secret('wa_app'); se_test_remove_secret('wa_token');
$GLOBALS['SE_MEDIA_FETCHER'] = null; $GLOBALS['SE_MEDIA_HTTP'] = null; $GLOBALS['SE_WA_TRANSPORT'] = null;
$GLOBALS['se_test']['options'] = [];
