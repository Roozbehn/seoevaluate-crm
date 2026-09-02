<?php
/**
 * Inbound media store: WhatsApp media ids and Instagram CDN URLs are
 * registered on ingest, fetched asynchronously through the fetcher seam,
 * validated (allow-list, size, byte sniff), stored outside the docroot and
 * rendered inline; failures are bounded and explained.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$db = se_test_db();
$db->tables = []; $db->autoinc = [];
$db->seed('tblse_media', []);
$db->seed('tblse_wa_numbers', [['id' => 1, 'brand_id' => 1, 'phone_number_id' => 'PN1', 'waba_id' => 'W1']]);
$db->seed('tblse_wa_conversations', []);
$db->seed('tblse_wa_messages', []);
$db->seed('tblse_ig_accounts', [['id' => 1, 'brand_id' => 1, 'ig_account_id' => 'ACC1', 'username' => 'azin', 'state' => 'active']]);
$db->seed('tblse_ig_conversations', []);
$db->seed('tblse_ig_messages', []);
$db->seed('tblse_brands', [['id' => 1, 'name' => 'Brand A', 'active' => 1]]);
$db->seed('tblse_wa_metering', []);
$GLOBALS['se_test']['options'] = [];
$GLOBALS['SE_MEDIA_FETCHER'] = null;

// Private store for the run.
$dir = defined('SE_MEDIA_DIR') ? SE_MEDIA_DIR : sys_get_temp_dir() . '/se_media_test_' . getmypid();
if (!defined('SE_MEDIA_DIR')) { define('SE_MEDIA_DIR', $dir); }
se_eq($dir, se_media_dir(), 'the store path is the configured private directory');

/* --- WhatsApp ingest registers the attachment ---------------------------- */
se_wa_handle_inbound(1, 'PN1', [
    'id' => 'wamid.IMG1', 'from' => '905000000001', 'timestamp' => time(), 'type' => 'image',
    'image' => ['id' => 'MEDIA-123', 'mime_type' => 'image/jpeg', 'sha256' => 'x', 'caption' => 'ön görünüm'],
], []);
$msgs = se_test_db()->rows('tblse_wa_messages');
se_eq(1, count($msgs), 'one message row');
se_eq('media:MEDIA-123', $msgs[0]['media_ref'], 'the message keeps the provider reference');
se_eq('ön görünüm', $msgs[0]['body'], 'the caption becomes the message text');
$media = se_test_db()->rows('tblse_media');
se_eq(1, count($media), 'one media row registered');
se_eq('wa', $media[0]['channel'], 'channel wa');
se_eq('MEDIA-123', $media[0]['provider_ref'], 'the media id is what will be fetched');
se_eq('image', $media[0]['kind'], 'kind image');
se_eq('pending', $media[0]['state'], 'pending until fetched');
se_eq('image/jpeg', $media[0]['mime'], 'declared mime carried');

// Voice note + document + sticker.
se_wa_handle_inbound(1, 'PN1', ['id' => 'wamid.AUD1', 'from' => '905000000001', 'timestamp' => time(), 'type' => 'audio',
    'audio' => ['id' => 'MEDIA-AUD', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]], []);
se_wa_handle_inbound(1, 'PN1', ['id' => 'wamid.DOC1', 'from' => '905000000001', 'timestamp' => time(), 'type' => 'document',
    'document' => ['id' => 'MEDIA-DOC', 'mime_type' => 'application/pdf', 'filename' => '../../fiyat listesi.pdf']], []);
se_wa_handle_inbound(1, 'PN1', ['id' => 'wamid.STK1', 'from' => '905000000001', 'timestamp' => time(), 'type' => 'sticker',
    'sticker' => ['id' => 'MEDIA-STK', 'mime_type' => 'image/webp']], []);
$media = se_test_db()->rows('tblse_media');
se_eq(4, count($media), 'four media rows');
se_eq('audio/ogg', $media[1]['mime'], 'codec suffix stripped from the mime');
se_eq('fiyat listesi.pdf', $media[2]['filename'], 'provider filename is basename-only');
se_eq('image', $media[3]['kind'], 'a sticker is stored as an image');

// Duplicate webhook delivery does not double-register.
se_wa_handle_inbound(1, 'PN1', ['id' => 'wamid.IMG1', 'from' => '905000000001', 'timestamp' => time(), 'type' => 'image',
    'image' => ['id' => 'MEDIA-123', 'mime_type' => 'image/jpeg']], []);
se_eq(4, count(se_test_db()->rows('tblse_media')), 'a redelivered message registers nothing new');

/* --- Instagram ingest keeps the FULL CDN url ----------------------------- */
$longUrl = 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1234567890&signature=' . str_repeat('a', 300);
se_ig_handle_inbound(1, 'ACC1', [
    'kind' => 'inbound', 'sender' => 'USER1', 'recipient' => 'ACC1', 'ts' => date('Y-m-d H:i:s'),
    'mid' => 'mid.IG1', 'type' => 'image', 'text' => '', 'media' => 'url:' . substr($longUrl, 0, 180),
    'media_url' => $longUrl, 'deleted' => false, 'referral' => null,
]);
$media = se_test_db()->rows('tblse_media');
se_eq(5, count($media), 'the Instagram attachment is registered');
se_eq('ig', $media[4]['channel'], 'channel ig');
se_eq($longUrl, $media[4]['provider_ref'], 'the full CDN url survives (media_ref truncates at 191)');

/* --- fetch: network refused by default ----------------------------------- */
require_once __DIR__ . '/net_kill.php';
se_net_install_fixtures();
$before = se_net_kill_count();
se_media_fetch_pending();
se_ok(se_net_kill_count() > $before, 'without a fixture the seam is hit (and counted — so tests cannot leak to the network)');
$GLOBALS['se_net_attempts'] = [];   // this suite deliberately drove the seam
$media = se_test_db()->rows('tblse_media');
se_eq('pending', $media[0]['state'], 'a failed fetch stays pending');
se_eq(1, (int) $media[0]['attempts'], 'with one attempt counted');
se_eq('network disabled in tests', $media[0]['last_error'], 'and the reason recorded');
se_ok(strtotime($media[0]['next_attempt_at']) > time() + 60, 'and a backoff scheduled');

/* --- fetch: validation ---------------------------------------------------- */
$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 64);
$fixtures = [
    'MEDIA-123' => ['ok' => true, 'bytes' => $jpeg, 'mime' => 'image/jpeg', 'error' => ''],
    'MEDIA-AUD' => ['ok' => true, 'bytes' => 'OggS' . str_repeat('x', 40), 'mime' => 'audio/ogg; codecs=opus', 'error' => ''],
    'MEDIA-DOC' => ['ok' => true, 'bytes' => '%PDF-1.4 fake', 'mime' => 'application/pdf', 'error' => ''],
    'MEDIA-STK' => ['ok' => true, 'bytes' => 'not really webp', 'mime' => 'image/webp', 'error' => ''],   // sniff must refuse
    $longUrl    => ['ok' => true, 'bytes' => "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 20), 'mime' => 'image/png', 'error' => ''],
];
se_media_register_fetcher(function ($row) use ($fixtures) {
    return $fixtures[$row['provider_ref']] ?? ['ok' => false, 'bytes' => '', 'mime' => '', 'error' => 'unknown fixture'];
});
// Make every row due now.
foreach (se_test_db()->rows('tblse_media') as $r) {
    se_test_db()->where('id', $r['id'])->update('tblse_media', ['next_attempt_at' => date('Y-m-d H:i:s', time() - 1)]);
}
se_eq(5, se_media_fetch_pending(), 'all five attempted');
$media = []; foreach (se_test_db()->rows('tblse_media') as $r) { $media[$r['provider_ref']] = $r; }

se_eq('stored', $media['MEDIA-123']['state'], 'a valid JPEG is stored');
se_eq('wa/1/1.jpg', $media['MEDIA-123']['path'], 'under channel/brand with an id-based name');
se_ok(is_file($dir . '/wa/1/1.jpg'), 'the bytes are on disk');
se_eq(hash('sha256', $jpeg), $media['MEDIA-123']['sha256'], 'sha256 recorded');
se_eq(strlen($jpeg), (int) $media['MEDIA-123']['bytes'], 'size recorded');
se_eq('stored', $media['MEDIA-AUD']['state'], 'voice note stored');
se_eq('audio', $media['MEDIA-AUD']['kind'], 'as audio');
se_eq('audio/ogg', $media['MEDIA-AUD']['mime'], 'with a normalised mime');
se_eq('stored', $media['MEDIA-DOC']['state'], 'PDF stored');
se_eq('document', $media['MEDIA-DOC']['kind'], 'as a document');
se_eq('failed', $media['MEDIA-STK']['state'], 'bytes that do not match the declared image type are refused');
se_ok(strpos($media['MEDIA-STK']['last_error'], 'does not match') !== false, 'with the reason');
se_eq('stored', $media[$longUrl]['state'], 'the Instagram image is stored');
se_eq('ig/1/5.png', $media[$longUrl]['path'], 'in the ig tree');

// Size cap and unsupported type are permanent failures.
se_media_register_fetcher(function ($row) {
    return ['ok' => true, 'bytes' => str_repeat('x', SE_MEDIA_MAX_BYTES + 1), 'mime' => 'image/png', 'error' => ''];
});
$big = se_media_fetch_one(['id' => 99, 'channel' => 'wa', 'brand_id' => 1, 'provider_ref' => 'BIG', 'attempts' => 0, 'mime' => null, 'filename' => null]);
se_eq('failed', $big['state'], 'oversize is refused');
se_ok(strpos($big['last_error'], 'too large') === 0, 'and named');
se_media_register_fetcher(function ($row) { return ['ok' => true, 'bytes' => 'MZ...', 'mime' => 'application/x-msdownload', 'error' => '']; });
$exe = se_media_fetch_one(['id' => 98, 'channel' => 'wa', 'brand_id' => 1, 'provider_ref' => 'EXE', 'attempts' => 0, 'mime' => null, 'filename' => null]);
se_eq('failed', $exe['state'], 'an executable is refused permanently');
se_ok(strpos($exe['last_error'], 'unsupported type') === 0, 'with the type named');

/* --- serving + rendering -------------------------------------------------- */
$row = $media['MEDIA-123'];
se_eq($dir . '/wa/1/1.jpg', se_media_abs_path($row), 'absolute path resolves for a stored row');
se_eq('', se_media_abs_path($media['MEDIA-STK']), 'and is empty for a failed one');
se_eq('', se_media_abs_path(['state' => 'stored', 'path' => '../../etc/passwd']), 'a traversal-shaped path never resolves');
se_ok(strpos(se_media_disposition($row), 'inline') === 0, 'images are served inline');
se_ok(strpos(se_media_disposition($media['MEDIA-DOC']), 'inline; filename="fiyat listesi.pdf"') === 0, 'PDF inline with its filename');
$docx = $media['MEDIA-DOC']; $docx['mime'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'; $docx['kind'] = 'document';
se_ok(strpos(se_media_disposition($docx), 'attachment') === 0, 'office documents download');

$html = se_ui_media($row);
se_ok(strpos($html, '<img') !== false && strpos($html, 'se_core/se_media/view/1') !== false, 'an image renders as an <img> through the authed route');
$html = se_ui_media($media['MEDIA-AUD']);
se_ok(strpos($html, '<audio controls') !== false, 'a voice note renders as an audio player');
$html = se_ui_media($media['MEDIA-DOC']);
se_ok(strpos($html, 'fa-file-o') !== false && strpos($html, 'fiyat listesi.pdf') !== false, 'a document renders as a download link');
$html = se_ui_media($media['MEDIA-STK']);
se_ok(strpos($html, 'label-danger') !== false, 'a failed fetch is shown as unavailable');
$html = se_ui_media(['id' => 7, 'state' => 'pending', 'kind' => 'video', 'caption' => null, 'filename' => null, 'path' => null, 'bytes' => null, 'last_error' => null]);
se_ok(strpos($html, 'fa-refresh') !== false, 'a pending fetch says so');
se_ok(strpos(se_ui_media($row, true), 'redacted') !== false, 'evidence mode redacts media');
se_ok(strpos(se_ui_media(['id' => 1, 'state' => 'stored', 'kind' => 'image', 'caption' => '<b>x</b>', 'filename' => null, 'path' => 'a.jpg', 'bytes' => 1, 'last_error' => null]), '&lt;b&gt;') !== false,
    'captions are escaped');

/* --- thread renders media inline ----------------------------------------- */
$msgs = se_test_db()->rows('tblse_wa_messages');
$map  = se_media_for_messages('wa', array_column($msgs, 'id'));
se_eq(4, count($map), 'media map covers every attachment message');
ob_start(); se_ui_chat_thread($msgs, $map, ['channel' => 'wa']); $out = ob_get_clean();
se_ok(substr_count($out, '<img') === 1 && strpos($out, '<audio') !== false, 'the thread shows the image and the voice note inline');
se_ok(strpos($out, 'Media attachment') === false, 'no bare "Media attachment" placeholder remains');
se_ok(strpos($out, 'ön görünüm') !== false, 'the caption is shown');

/* --- backfill: attachments received before the store existed ------------- */
se_test_db()->seed('tblse_wa_messages', array_merge(se_test_db()->rows('tblse_wa_messages'), [
    ['id' => 900, 'conversation_id' => 1, 'brand_id' => 1, 'wamid' => 'wamid.OLD', 'direction' => 'in', 'source' => 'customer',
     'type' => 'audio', 'body' => null, 'media_ref' => 'media:OLD-AUD', 'received_at' => date('Y-m-d H:i:s'), 'date_created' => date('Y-m-d H:i:s')],
    ['id' => 901, 'conversation_id' => 1, 'brand_id' => 1, 'wamid' => 'wamid.OLDOUT', 'direction' => 'out', 'source' => 'cloud_api',
     'type' => 'text', 'body' => 'x', 'media_ref' => null, 'received_at' => null, 'date_created' => date('Y-m-d H:i:s')],
]));
se_media_register_fetcher(function ($row) { return ['ok' => false, 'bytes' => '', 'mime' => '', 'error' => 'not now']; });
se_eq(1, se_media_backfill_wa(), 'one legacy WhatsApp attachment is registered by the backfill');
se_eq(0, se_media_backfill_wa(), 'and not twice');
$legacy = se_media_for_messages('wa', [900]);
se_eq('OLD-AUD', $legacy[900]['provider_ref'], 'with its media id');
se_eq('audio', $legacy[900]['kind'], 'and kind');

// cleanup
foreach (glob($dir . '/*/*/*') as $f) { @unlink($f); }
foreach (glob($dir . '/*/*') as $d) { @rmdir($d); }
foreach (glob($dir . '/*') as $d) { @rmdir($d); }
@rmdir($dir);
$GLOBALS['SE_MEDIA_FETCHER'] = null;
$GLOBALS['se_net_attempts'] = [];
