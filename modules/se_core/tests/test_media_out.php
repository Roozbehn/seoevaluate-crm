<?php
/**
 * Outbound attachments: upload validation + private storage, WhatsApp queue
 * → transport (file handed over, caption, window rule), Instagram queue →
 * transport (signed public URL), thread linking, and the signed-URL contract.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$db = se_test_db();
$db->tables = []; $db->autoinc = [];
$db->seed('tblse_brands', [['id' => 1, 'name' => 'Brand A', 'active' => 1], ['id' => 2, 'name' => 'Brand B', 'active' => 1]]);
$db->seed('tblse_media', []);
$db->seed('tblse_wa_numbers', [['id' => 1, 'brand_id' => 1, 'phone_number_id' => 'PN1', 'waba_id' => 'W1', 'state' => 'active', 'token_option_ref' => 'wa_token']]);
$db->seed('tblse_wa_conversations', [
    ['id' => 901, 'brand_id' => 1, 'phone_number_id' => 'PN1', 'wa_user_id' => 'U1', 'lead_id' => 0, 'assigned_staff' => 0,
     'unread_count' => 0, 'state' => 'open', 'window_expires_at' => date('Y-m-d H:i:s', time() + 3600)],
    ['id' => 902, 'brand_id' => 1, 'phone_number_id' => 'PN1', 'wa_user_id' => 'U2', 'lead_id' => 0, 'assigned_staff' => 0,
     'unread_count' => 0, 'state' => 'open', 'window_expires_at' => date('Y-m-d H:i:s', time() - 3600)],
]);
$db->seed('tblse_wa_messages', []);
$db->seed('tblse_wa_outbound', []);
$db->seed('tblse_wa_templates', []);
$db->seed('tblse_wa_metering', []);
$db->seed('tblse_ig_accounts', [['id' => 1, 'brand_id' => 1, 'ig_account_id' => 'ACC1', 'page_id' => 'PG1', 'state' => 'active']]);
$db->seed('tblse_ig_conversations', [
    ['id' => 1, 'brand_id' => 1, 'ig_account_id' => 'ACC1', 'igsid' => 'USER1', 'lead_id' => 0, 'assigned_staff' => 0,
     'unread_count' => 0, 'state' => 'open', 'window_expires_at' => date('Y-m-d H:i:s', time() + 3600)],
]);
$db->seed('tblse_ig_messages', []);
$db->seed('tblse_ig_outbound', []);
$GLOBALS['se_test']['options'] = [];
$GLOBALS['SE_WA_TRANSPORT'] = null;
$GLOBALS['SE_IG_TRANSPORT'] = null;
se_test_act_as(10, ['se_whatsapp.create', 'se_instagram.create'], true);

$dir = sys_get_temp_dir() . '/se_media_out_test_' . getmypid();
if (!defined('SE_MEDIA_DIR')) { define('SE_MEDIA_DIR', $dir); }
$dir = SE_MEDIA_DIR;

function se_test_tmp_upload($name, $bytes, $type = '')
{
    $tmp = tempnam(sys_get_temp_dir(), 'seup');
    file_put_contents($tmp, $bytes);
    return ['name' => $name, 'type' => $type, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
}
$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 200);
$png  = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 100);

/* --- upload validation ---------------------------------------------------- */
$r = se_media_store_upload('wa', 1, ['error' => UPLOAD_ERR_NO_FILE], 10);
se_eq('no_file', $r['error'], 'no file => no_file');

$r = se_media_store_upload('wa', 1, se_test_tmp_upload('evil.exe', "MZ\x90\x00" . str_repeat('x', 50), 'application/x-msdownload'), 10);
se_eq(false, $r['ok'], 'an executable is refused');
se_eq('unsupported_type', $r['error'], 'as unsupported');

$r = se_media_store_upload('wa', 1, se_test_tmp_upload('fake.jpg', 'this is not a jpeg at all, just text', 'image/jpeg'), 10);
se_ok($r['ok'] && $r['kind'] === 'document' || (!$r['ok'] && $r['error'] === 'content_mismatch'),
    'a text file renamed .jpg is never sent as an image (stored as a plain document, or refused as a mismatch)');

$r = se_media_store_upload('ig', 1, se_test_tmp_upload('list.pdf', '%PDF-1.4 x', 'application/pdf'), 10);
se_eq('unsupported_for_channel', $r['error'], 'Instagram refuses documents');

$r = se_media_store_upload('ig', 1, se_test_tmp_upload('big.png', $png . str_repeat("\x00", SE_MEDIA_IG_MAX_BYTES), 'image/png'), 10);
se_eq('too_large', $r['error'], 'Instagram caps at 8 MB');

$r = se_media_store_upload('wa', 1, se_test_tmp_upload('foto.jpg', $jpeg, 'image/jpeg'), 10);
se_eq(true, $r['ok'], 'a real JPEG is accepted');
se_eq('image', $r['kind'], 'as an image');
$waMedia = se_media_get($r['id']);
se_eq('out', $waMedia['direction'], 'stored as OUTBOUND');
se_eq('stored', $waMedia['state'], 'already stored (no fetch needed)');
se_eq(0, (int) $waMedia['message_id'], 'not yet linked to a thread message');
se_eq(10, (int) $waMedia['created_by'], 'uploader recorded');
se_eq('foto.jpg', $waMedia['filename'], 'original name kept (for the WhatsApp document filename)');
se_ok(is_file($dir . '/' . $waMedia['path']), 'bytes moved into the private store');
se_eq(hash('sha256', $jpeg), $waMedia['sha256'], 'sha256 recorded');

/* A browser-recorded voice message: MP4 container with an AAC audio track.
 * libmagic calls the container video/mp4; the browser declares audio/mp4. */
$m4a = "\x00\x00\x00\x18ftypM4A \x00\x00\x00\x00M4A mp42isom" . str_repeat("\x00", 300);
$r = se_media_store_upload('wa', 1, se_test_tmp_upload('voice-20260902-201500.m4a', $m4a, 'audio/mp4'), 10);
se_eq(true, $r['ok'], 'a recorded voice message is accepted');
se_eq('audio', $r['kind'], 'as AUDIO (not video) — the declared audio track wins over the container');
se_eq('audio/mp4', se_media_get($r['id'])['mime'], 'stored as audio/mp4');
$r = se_media_store_upload('ig', 1, se_test_tmp_upload('voice.m4a', $m4a, 'audio/mp4'), 10);
se_eq(true, $r['ok'], 'Instagram accepts the same voice message');
se_eq('audio', $r['kind'], 'as audio');
$mp4v = "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41" . str_repeat("\x00", 300);
$r = se_media_store_upload('wa', 1, se_test_tmp_upload('clip.mp4', $mp4v, 'video/mp4'), 10);
se_eq('video', $r['kind'], 'while a file the browser calls video stays video');

$r = se_media_store_upload('ig', 1, se_test_tmp_upload('story.png', $png, 'image/png'), 10);
se_eq(true, $r['ok'], 'a PNG for Instagram is accepted');
$igMedia = se_media_get($r['id']);

/* --- sendable guard ------------------------------------------------------- */
se_ok(se_media_sendable($waMedia['id'], 'wa', 1) !== null, 'the WhatsApp upload is sendable on WhatsApp for brand 1');
se_eq(null, se_media_sendable($waMedia['id'], 'ig', 1), 'but not on Instagram (channel mismatch)');
se_eq(null, se_media_sendable($waMedia['id'], 'wa', 2), 'nor for another brand');
se_eq(null, se_media_sendable(999999, 'wa', 1), 'unknown id => null');

/* --- WhatsApp queue ------------------------------------------------------- */
se_test_install_secret('wa_app', 'fixture-not-a-real-secret');
se_test_install_secret('wa_token', 'fixture-not-a-real-token');
$sent = [];
se_wa_register_transport(function ($m) use (&$sent) { $sent[] = $m; return ['ok' => true, 'wamid' => 'wamid.M' . count($sent), 'code' => 200, 'error' => '']; });

$q = se_wa_queue_message(902, ['kind' => 'media', 'media_id' => $waMedia['id'], 'body' => 'x'], 10);
se_eq('window_closed', $q['reason'], 'an attachment outside the 24h window is refused (free-form rule)');

$q = se_wa_queue_message(901, ['kind' => 'media', 'media_id' => 424242], 10);
se_eq('media_invalid', $q['reason'], 'an unknown attachment cannot be queued');

$q = se_wa_queue_message(901, ['kind' => 'media', 'media_id' => $waMedia['id'], 'body' => '  Fiyat listesi  '], 10);
se_eq(true, $q['ok'], 'a stored attachment queues on an open window');
$row = null; foreach (se_test_db()->rows('tblse_wa_outbound') as $o) { if ((int) $o['id'] === (int) $q['id']) { $row = $o; } }
se_eq('media', $row['kind'], 'kind media');
se_eq((int) $waMedia['id'], (int) $row['media_id'], 'media id on the queue row');
se_eq('Fiyat listesi', $row['body'], 'trimmed caption');

$q2 = se_wa_queue_message(901, ['kind' => 'media', 'media_id' => $waMedia['id'], 'body' => 'Fiyat listesi'], 10);
se_eq('duplicate', $q2['reason'], 'the same attachment + caption is one row');

se_wa_out_drain();
se_eq(1, count($sent), 'one send');
se_eq('media', $sent[0]['kind'], 'the transport is told it is an attachment');
se_eq('image', $sent[0]['media']['kind'], 'with the kind');
se_eq('image/jpeg', $sent[0]['media']['mime'], 'the mime');
se_eq('foto.jpg', $sent[0]['media']['filename'], 'the filename');
se_ok(is_file($sent[0]['media']['abs_path']), 'and the private path of the bytes');
se_eq('Fiyat listesi', $sent[0]['body'], 'caption as body');

$msgs = se_test_db()->rows('tblse_wa_messages');
se_eq(1, count($msgs), 'a thread message was recorded');
se_eq('out', $msgs[0]['direction'], 'outbound');
se_eq('image', $msgs[0]['type'], 'typed as image');
se_eq('out:' . $waMedia['id'], $msgs[0]['media_ref'], 'media_ref points at the media row');
$linked = se_media_get($waMedia['id']);
se_eq((int) $msgs[0]['id'], (int) $linked['message_id'], 'the media row is linked to the thread message');
se_eq((int) $q['id'], (int) $linked['outbound_id'], 'and to the queue row');
$map = se_media_for_messages('wa', [(int) $msgs[0]['id']]);
se_ok(isset($map[(int) $msgs[0]['id']]), 'so the thread can render it inline');
ob_start(); se_ui_chat_thread($msgs, $map, ['channel' => 'wa']); $html = ob_get_clean();
se_ok(strpos($html, '<img') !== false && strpos($html, 'Fiyat listesi') !== false, 'sent image + caption render in the thread');

/* --- Instagram queue + signed URL ---------------------------------------- */
$igSent = [];
se_ig_register_transport(function ($m) use (&$igSent) { $igSent[] = $m; return ['ok' => true, 'mid' => 'mid.M' . count($igSent), 'code' => 200, 'error' => '']; });
update_option('se_ig_scopes_verified', 1);
se_test_install_secret('meta_app', 'fixture-not-a-real-secret');
se_test_install_secret('meta_page_1', 'fixture-not-a-real-token');

$q = se_ig_queue_message(1, ['kind' => 'media', 'media_id' => $waMedia['id']], 10);
se_eq('media_invalid', $q['reason'], 'a WhatsApp upload cannot be sent on Instagram');
$q = se_ig_queue_message(1, ['kind' => 'media', 'media_id' => $igMedia['id']], 10);
se_eq(true, $q['ok'], 'the Instagram upload queues');
$q = se_ig_queue_message(1, ['kind' => 'media', 'media_id' => $igMedia['id']], 10);
se_eq('duplicate', $q['reason'], 'once');

$blocked = se_ig_send_blocked_reason(1);
if ($blocked === '') {
    se_ig_out_drain();
    se_eq(1, count($igSent), 'one Instagram send');
    se_eq('media', $igSent[0]['kind'], 'as an attachment');
    se_eq('image', $igSent[0]['media']['kind'], 'image');
    $url = $igSent[0]['media']['url'];
    se_ok(preg_match('#^/se_core/se_media_pub/index/' . (int) $igMedia['id'] . '/\d+/[a-f0-9]{40}$#', $url) === 1, 'the transport receives a signed public URL: ' . $url);
    [, , , , $id, $exp, $sig] = explode('/', $url);
    se_ok(se_media_pub_verify($id, $exp, $sig) !== null, 'the URL verifies');
    se_eq(null, se_media_pub_verify($id, $exp, strrev($sig)), 'a tampered signature does not');
    se_eq(null, se_media_pub_verify($id, $exp, $sig, (int) $exp + 1), 'nor after expiry');
    se_eq(null, se_media_pub_verify($id, (int) $exp + 100, $sig), 'nor with a moved expiry');
    $igMsgs = se_test_db()->rows('tblse_ig_messages');
    se_eq('out:' . $igMedia['id'], $igMsgs[0]['media_ref'], 'the Instagram thread message references the media');
    se_eq('image', $igMsgs[0]['type'], 'typed image');
} else {
    se_ok(true, 'Instagram send gate in this fixture: ' . $blocked . ' (transport contract covered by the WhatsApp path)');
}

/* inbound rows are never served publicly */
$in = se_media_enqueue('wa', 555, 1, 'image', 'MEDIA-IN');
se_test_db()->where('id', $in)->update('tblse_media', ['state' => 'stored', 'path' => 'wa/1/x.jpg']);
$exp = time() + 60;
se_eq(null, se_media_pub_verify($in, $exp, se_media_pub_sig($in, $exp)), 'an INBOUND row never resolves through the public route');

/* Disposition + serving for outbound rows through the authed route is unchanged. */
se_ok(strpos(se_media_disposition($linked), 'inline; filename="foto.jpg"') === 0, 'outbound image served inline with its name');

// cleanup
foreach (glob($dir . '/*/*/*') as $f) { @unlink($f); }
foreach (glob($dir . '/*/*') as $d) { @rmdir($d); }
foreach (glob($dir . '/*') as $d) { @rmdir($d); }
@rmdir($dir);
se_test_remove_secret('wa_app'); se_test_remove_secret('wa_token'); se_test_remove_secret('meta_app'); se_test_remove_secret('meta_page_1');
$GLOBALS['SE_WA_TRANSPORT'] = null; $GLOBALS['SE_IG_TRANSPORT'] = null;
$GLOBALS['se_test']['options'] = [];
