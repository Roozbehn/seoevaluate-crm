<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Inbound media store — WhatsApp + Instagram attachments, one pipeline.
 *
 * Threads used to show "Media attachment" for every image, voice note, video
 * or document because the file stayed on Meta's CDN. This module gives each
 * attachment a row in tblse_media, fetches the bytes asynchronously (from the
 * dispatcher / cron, never inside a webhook request), stores them OUTSIDE the
 * document root, and serves them back only through an authenticated,
 * brand-scoped controller (se_core/se_media/view/<id>).
 *
 * Sources differ per channel and are normalised here:
 *   - WhatsApp: the webhook carries a media ID. Two authenticated Graph calls:
 *     GET /{media-id} → {url, mime_type, sha256, file_size}, then GET url with
 *     the same Bearer token (the URL is short-lived, ~5 min).
 *   - Instagram: the webhook carries a CDN URL (lookaside.fbsbx.com) that is
 *     fetched directly; it also expires, so it is stored in full here — the
 *     191-char media_ref column on the message row truncated it.
 *
 * Network goes through ONE seam (se_media_register_fetcher) so tests run the
 * whole enqueue → fetch → store → serve path with no socket.
 *
 * Hard limits: mime allow-list, 25 MB cap, 5 attempts with backoff, filenames
 * never taken from the provider (id-based on disk), bytes sniffed for images.
 */

define('SE_MEDIA_MAX_BYTES', 25 * 1024 * 1024);
define('SE_MEDIA_MAX_ATTEMPTS', 5);
define('SE_MEDIA_BATCH', 10);

$GLOBALS['SE_MEDIA_FETCHER'] = $GLOBALS['SE_MEDIA_FETCHER'] ?? null;

// Safety net: the 15-minute Perfex cron also drains attachments, so media
// still arrives if the per-minute dispatcher is ever down. Guarded so the
// headless migration runner (tests/migrate_cli.php), which requires this
// file for its schema statements outside the CI context, can load it.
if (function_exists('hooks')) {
    if (function_exists('se_cron_listener')) { se_cron_listener('se_media_fetch_pending'); } else { hooks()->add_action('after_cron_run', 'se_media_fetch_pending'); }
}

/** callable(array $row): array{ok:bool,bytes:string,mime:string,error:string,filename?:string} */
function se_media_register_fetcher(callable $f)
{
    $GLOBALS['SE_MEDIA_FETCHER'] = $f;
}

/* ---------------------------------------------------------------------------
 * Schema (idempotent; registered with se_core migrations).
 * ------------------------------------------------------------------------- */

function se_media_schema_statements($p)
{
    return ["CREATE TABLE IF NOT EXISTS `{$p}se_media` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `channel` varchar(8) NOT NULL,
        `message_id` bigint(20) NOT NULL,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `direction` varchar(4) NOT NULL DEFAULT 'in',
        `storage` varchar(8) NOT NULL DEFAULT 'local',
        `outbound_id` bigint(20) DEFAULT NULL,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `kind` varchar(24) NOT NULL DEFAULT 'file',
        `provider_ref` text DEFAULT NULL,
        `caption` text DEFAULT NULL,
        `filename` varchar(191) DEFAULT NULL,
        `mime` varchar(96) DEFAULT NULL,
        `bytes` int(11) DEFAULT NULL,
        `sha256` char(64) DEFAULT NULL,
        `path` varchar(255) DEFAULT NULL,
        `state` varchar(16) NOT NULL DEFAULT 'pending',
        `attempts` int(11) NOT NULL DEFAULT 0,
        `last_error` varchar(255) DEFAULT NULL,
        `next_attempt_at` datetime DEFAULT NULL,
        `fetched_at` datetime DEFAULT NULL,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `channel_message` (`channel`,`message_id`),
        KEY `brand_id` (`brand_id`),
        KEY `claim` (`state`,`next_attempt_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"];
}

/* ---------------------------------------------------------------------------
 * Storage location — outside the document root, like the secret store.
 * ------------------------------------------------------------------------- */

function se_media_dir()
{
    if (defined('SE_MEDIA_DIR')) {
        return rtrim(SE_MEDIA_DIR, '/');
    }
    // App root == docroot on this host, so the parent is the account home.
    return rtrim(dirname(rtrim(FCPATH, '/')), '/') . '/_se_media';
}

/** Make sure the (private) directory exists. Returns '' or an error string. */
function se_media_ensure_dir($sub = '')
{
    $dir = se_media_dir() . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return 'media dir not writable';
    }
    return '';
}

/* ---------------------------------------------------------------------------
 * Allow-list.
 * ------------------------------------------------------------------------- */

/** mime → [kind, extension]. Anything else is refused and marked failed. */
function se_media_allowed()
{
    return [
        'image/jpeg' => ['image', 'jpg'], 'image/png' => ['image', 'png'], 'image/webp' => ['image', 'webp'],
        'image/gif' => ['image', 'gif'],
        'audio/ogg' => ['audio', 'ogg'], 'audio/mpeg' => ['audio', 'mp3'], 'audio/mp4' => ['audio', 'm4a'],
        'audio/aac' => ['audio', 'aac'], 'audio/amr' => ['audio', 'amr'], 'audio/wav' => ['audio', 'wav'],
        'audio/x-wav' => ['audio', 'wav'], 'audio/opus' => ['audio', 'opus'],
        'video/mp4' => ['video', 'mp4'], 'video/3gpp' => ['video', '3gp'], 'video/quicktime' => ['video', 'mov'],
        'application/pdf' => ['document', 'pdf'],
        'application/msword' => ['document', 'doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['document', 'docx'],
        'application/vnd.ms-excel' => ['document', 'xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['document', 'xlsx'],
        'text/plain' => ['document', 'txt'],
    ];
}

/** Normalise a provider mime ("audio/ogg; codecs=opus") to the allow-list key. */
function se_media_normalize_mime($mime)
{
    $m = strtolower(trim((string) explode(';', (string) $mime)[0]));
    return $m;
}

/** Cheap byte sniff for the image types; other kinds trust the declared mime. */
function se_media_sniff_ok($mime, $bytes)
{
    $head = substr($bytes, 0, 12);
    switch ($mime) {
        case 'image/jpeg': return strncmp($head, "\xFF\xD8\xFF", 3) === 0;
        case 'image/png':  return strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0;
        case 'image/gif':  return strncmp($head, 'GIF8', 4) === 0;
        case 'image/webp': return substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP';
        case 'application/pdf': return strncmp($head, '%PDF', 4) === 0;
    }
    return true;
}

/* ---------------------------------------------------------------------------
 * Enqueue (called by the inbound handlers) and lookup (called by the views).
 * ------------------------------------------------------------------------- */

/**
 * Register an attachment for later fetch. Idempotent on (channel, message_id).
 *
 * @param string $channel 'wa'|'ig'
 * @param string $ref     WA: the media id; IG: the full CDN URL
 */
function se_media_enqueue($channel, $message_id, $brand_id, $kind, $ref, $caption = null, $filename = null, $mime = null)
{
    $CI = &get_instance();
    $table = db_prefix() . 'se_media';

    $CI->db->where('channel', $channel)->where('message_id', (int) $message_id);
    if ($CI->db->count_all_results($table) > 0) {
        return false;
    }

    try {
        $CI->db->insert($table, [
            'channel'      => $channel,
            'message_id'   => (int) $message_id,
            'brand_id'     => (int) $brand_id,
            'kind'         => mb_substr((string) $kind, 0, 24) ?: 'file',
            'provider_ref' => (string) $ref,
            'caption'      => $caption !== null && $caption !== '' ? mb_substr((string) $caption, 0, 4096) : null,
            'filename'     => $filename !== null && $filename !== '' ? mb_substr(basename((string) $filename), 0, 191) : null,
            'mime'         => $mime !== null ? mb_substr(se_media_normalize_mime($mime), 0, 96) : null,
            'state'        => 'pending',
            'attempts'     => 0,
            'next_attempt_at' => se_db_now(),
            'date_created' => se_db_now(),
        ]);
    } catch (Exception $e) {
        return false;   // unique key: a concurrent enqueue already did it
    }

    return (int) $CI->db->insert_id();
}

/** message_id → media row, for one channel and a list of message ids. */
function se_media_for_messages($channel, array $message_ids)
{
    $message_ids = array_values(array_unique(array_map('intval', $message_ids)));
    if (!$message_ids) {
        return [];
    }
    $CI = &get_instance();
    $CI->db->where('channel', $channel)->where_in('message_id', $message_ids);
    $out = [];
    foreach ($CI->db->get(db_prefix() . 'se_media')->result_array() as $r) {
        $out[(int) $r['message_id']] = $r;
    }
    return $out;
}

function se_media_get($id)
{
    $CI = &get_instance();
    $CI->db->where('id', (int) $id);
    return $CI->db->get(db_prefix() . 'se_media')->row_array() ?: null;
}

/* ---------------------------------------------------------------------------
 * Fetch (dispatcher / cron step).
 * ------------------------------------------------------------------------- */

if (!defined('SE_MEDIA_LEASE_SECONDS')) { define('SE_MEDIA_LEASE_SECONDS', 15 * 60); }   // a fetch never legitimately takes longer

function se_media_backoff_seconds($attempts)
{
    return min(6 * 3600, 120 * (2 ** max(0, $attempts - 1)));
}

/**
 * Register attachments that arrived BEFORE the media store existed.
 *
 * WhatsApp rows carry `media:<id>` and the id stays fetchable on Meta's side
 * for about 30 days, so they can still be pulled. Instagram rows only kept a
 * truncated CDN url, which cannot be recovered — those stay as placeholders.
 * Cheap and idempotent: the newest 50 media messages, skip the ones already
 * registered.
 */
function se_media_backfill_wa($limit = 50)
{
    $CI = &get_instance();
    if (!$CI->db->table_exists(db_prefix() . 'se_wa_messages')) {
        return 0;
    }
    $CI->db->where('direction', 'in')->where('media_ref !=', '')->order_by('id', 'DESC')->limit((int) $limit);
    $rows = $CI->db->get(db_prefix() . 'se_wa_messages')->result_array();
    if (!$rows) {
        return 0;
    }
    $have = se_media_for_messages('wa', array_column($rows, 'id'));
    $n = 0;
    foreach ($rows as $m) {
        if (isset($have[(int) $m['id']]) || strpos((string) $m['media_ref'], 'media:') !== 0) {
            continue;
        }
        $kind = in_array($m['type'], ['image', 'audio', 'video', 'document'], true) ? $m['type'] : 'image';
        if (se_media_enqueue('wa', (int) $m['id'], (int) $m['brand_id'], $kind, substr((string) $m['media_ref'], 6), $m['body'] ?? null)) {
            $n++;
        }
    }
    return $n;
}

/** Fetch up to SE_MEDIA_BATCH pending attachments. Returns the number attempted. */
function se_media_fetch_pending($limit = SE_MEDIA_BATCH)
{
    $limit = (int) $limit; if ($limit < 1) { $limit = SE_MEDIA_BATCH; }

    $CI = &get_instance();
    $table = db_prefix() . 'se_media';

    se_media_backfill_wa();

    // When R2 is the configured store, drift older local files up to it a few
    // at a time (verify-then-delete), so the CRM disk empties out by itself.
    if (function_exists('se_media_storage_driver') && se_media_storage_driver() === 'r2') {
        se_media_migrate_local_to_r2(10);
    }

    // Lease recovery (audit J9 / CRM-M055): a worker that died mid-fetch left the
    // row in `fetching` forever — invisible, never retried. A fetching row whose
    // lease (next_attempt_at, set below) has passed goes back to pending with the
    // attempt counted, so the backoff and the 5-attempt cap still apply.
    $CI->db->where('state', 'fetching')->where('next_attempt_at <=', se_db_now())
           ->set('state', 'pending')->set('attempts', 'attempts + 1', false)
           ->set('last_error', 'fetch lease expired (worker died)')->update($table);

    $CI->db->where('state', 'pending')->where('next_attempt_at <=', se_db_now())
           ->order_by('id', 'ASC')->limit($limit);
    $rows = $CI->db->get($table)->result_array();

    foreach ($rows as $row) {
        // Claim with a lease: fetching + next_attempt_at = now + SE_MEDIA_LEASE_SECONDS.
        $CI->db->where('id', (int) $row['id'])->where('state', 'pending')
               ->update($table, ['state' => 'fetching', 'next_attempt_at' => se_db_now(SE_MEDIA_LEASE_SECONDS)]);
        if ($CI->db->affected_rows() !== 1) {
            continue;   // another worker took it
        }
        $outcome = se_media_fetch_one($row);
        $CI->db->where('id', (int) $row['id'])->update($table, $outcome);
    }

    return count($rows);
}

/** One attachment: fetch → validate → store. Returns the column updates. */
function se_media_fetch_one(array $row)
{
    $attempts = (int) $row['attempts'] + 1;
    $fail = function ($error, $permanent = false) use ($attempts) {
        $final = $permanent || $attempts >= SE_MEDIA_MAX_ATTEMPTS;
        return ['state' => $final ? 'failed' : 'pending', 'attempts' => $attempts,
                'last_error' => mb_substr((string) $error, 0, 255),
                'next_attempt_at' => se_db_now(se_media_backoff_seconds($attempts))];
    };

    $r = is_callable($GLOBALS['SE_MEDIA_FETCHER'] ?? null)
        ? call_user_func($GLOBALS['SE_MEDIA_FETCHER'], $row)
        : se_media_live_fetch($row);

    if (empty($r['ok'])) {
        return $fail((string) ($r['error'] ?? 'fetch failed'), !empty($r['permanent']));
    }

    $bytes = (string) ($r['bytes'] ?? '');
    $mime  = se_media_normalize_mime($r['mime'] ?? ($row['mime'] ?? ''));
    $allowed = se_media_allowed();

    if ($bytes === '') {
        return $fail('empty body');
    }
    if (strlen($bytes) > SE_MEDIA_MAX_BYTES) {
        return $fail('too large (' . strlen($bytes) . ' bytes)', true);
    }
    if (!isset($allowed[$mime])) {
        return $fail('unsupported type ' . ($mime ?: 'unknown'), true);
    }
    if (!se_media_sniff_ok($mime, $bytes)) {
        return $fail('content does not match declared type ' . $mime, true);
    }

    [$kind, $ext] = $allowed[$mime];
    $rel = $row['channel'] . '/' . (int) $row['brand_id'] . '/' . (int) $row['id'] . '.' . $ext;
    $put = se_media_storage_put($rel, $bytes, $mime);
    if (!$put['ok']) {
        return $fail($put['error']);
    }

    return ['state' => 'stored', 'attempts' => $attempts, 'last_error' => null, 'storage' => $put['storage'],
            'mime' => $mime, 'kind' => $kind, 'bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'path' => $rel,
            'filename' => $row['filename'] ?: (!empty($r['filename']) ? mb_substr(basename((string) $r['filename']), 0, 191) : null),
            'fetched_at' => se_db_now()];
}

/** Live fetch — WhatsApp via Graph with the Cloud API token, Instagram via CDN URL. */
function se_media_live_fetch(array $row)
{
    $ref = (string) $row['provider_ref'];

    if ($row['channel'] === 'wa') {
        $token = function_exists('se_wa_cloud_token') ? se_wa_cloud_token() : '';
        if ($token === '') {
            return ['ok' => false, 'error' => 'no wa_token'];
        }
        $version = get_option('se_meta_graph_version') ?: 'v23.0';
        $meta = se_media_http_get('https://graph.facebook.com/' . $version . '/' . rawurlencode($ref), $token);
        if (!$meta['ok']) {
            return ['ok' => false, 'error' => 'media lookup: ' . $meta['error'], 'permanent' => $meta['code'] === 400 || $meta['code'] === 404];
        }
        $info = json_decode($meta['body'], true) ?: [];
        $url  = (string) ($info['url'] ?? '');
        if ($url === '' || strpos($url, 'https://') !== 0) {
            return ['ok' => false, 'error' => 'media lookup: no url'];
        }
        $dl = se_media_http_get($url, $token);
        if (!$dl['ok']) {
            return ['ok' => false, 'error' => 'download: ' . $dl['error']];
        }
        return ['ok' => true, 'bytes' => $dl['body'],
                'mime' => $info['mime_type'] ?? $dl['mime'], 'error' => ''];
    }

    // Instagram: the CDN URL is self-authorising for a limited time.
    if (strpos($ref, 'https://') !== 0) {
        return ['ok' => false, 'error' => 'no downloadable url', 'permanent' => true];
    }
    $dl = se_media_http_get($ref, '');
    if (!$dl['ok']) {
        return ['ok' => false, 'error' => 'download: ' . $dl['error'],
                'permanent' => $dl['code'] === 403 || $dl['code'] === 404 || $dl['code'] === 410];
    }
    return ['ok' => true, 'bytes' => $dl['body'], 'mime' => $dl['mime'], 'error' => ''];
}

/** Bounded GET. Token in the header only; body capped; errors sanitised. */
function se_media_http_get($url, $token = '')
{
    // Only Meta's CDNs / Graph: the URL comes from a webhook or a Graph lookup
    // and this function attaches the bearer token to the request.
    if (function_exists('se_host_allowed') && !se_host_allowed($url, se_media_fetch_hosts())) {
        return ['ok' => false, 'code' => 0, 'body' => '', 'mime' => '', 'error' => 'host_not_allowed'];
    }
    $ch = curl_init($url);
    $headers = ['Accept: */*'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_MAXFILESIZE    => SE_MEDIA_MAX_BYTES + 1,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'code' => 0, 'body' => '', 'mime' => '', 'error' => 'network error: ' . mb_substr((string) $err, 0, 80)];
    }
    if ($code < 200 || $code >= 300) {
        $j = json_decode((string) $body, true);
        $msg = (string) ($j['error']['message'] ?? ('HTTP ' . $code));
        return ['ok' => false, 'code' => $code, 'body' => '', 'mime' => '', 'error' => mb_substr($msg, 0, 120)];
    }
    return ['ok' => true, 'code' => $code, 'body' => (string) $body, 'mime' => $mime, 'error' => ''];
}

/* ---------------------------------------------------------------------------
 * Serving — the controller calls this after authorisation.
 * ------------------------------------------------------------------------- */

/** Absolute path of a stored row, or '' if not stored / not on disk. */
function se_media_abs_path(array $row)
{
    if (($row['state'] ?? '') !== 'stored' || empty($row['path'])) {
        return '';
    }
    // The stored path is id-based and produced by this module; still, never
    // let a traversal-shaped value reach the filesystem.
    if (strpos($row['path'], '..') !== false) {
        return '';
    }
    $abs = se_media_dir() . '/' . $row['path'];
    return is_file($abs) ? $abs : '';
}

/** Content-Disposition: inline for things browsers render, attachment otherwise. */
function se_media_disposition(array $row)
{
    $inline = in_array($row['kind'] ?? '', ['image', 'audio', 'video'], true) || ($row['mime'] ?? '') === 'application/pdf';
    $name = $row['filename'] ?: ((int) $row['id'] . '.' . pathinfo((string) $row['path'], PATHINFO_EXTENSION));
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
    return ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"';
}

/* ---------------------------------------------------------------------------
 * Rendering helper for the thread views.
 * ------------------------------------------------------------------------- */

/** HTML for one message's attachment (or a status when not yet stored). */
function se_ui_media(array $media = null, $redacted = false)
{
    if ($media === null) {
        return '<span class="label label-default"><i class="fa fa-paperclip"></i> ' . html_escape(_l('se_media_placeholder')) . '</span>';
    }
    if ($redacted) {
        return '<span class="text-muted">[' . html_escape($media['kind']) . ' redacted for evidence]</span>';
    }

    $url = admin_url('se_core/se_media/view/' . (int) $media['id']);
    $cap = !empty($media['caption']) ? '<div class="mtop5">' . nl2br(html_escape($media['caption'])) . '</div>' : '';

    switch ($media['state']) {
        case 'stored':
            switch ($media['kind']) {
                case 'image':
                    return '<a href="' . html_escape($url) . '" target="_blank" rel="noopener">'
                         . '<img src="' . html_escape($url) . '" alt="" style="max-width:280px;max-height:280px;border-radius:6px;display:block" /></a>' . $cap;
                case 'audio':
                    return '<audio controls preload="none" src="' . html_escape($url) . '" style="max-width:280px;display:block"></audio>' . $cap;
                case 'video':
                    return '<video controls preload="metadata" src="' . html_escape($url) . '" style="max-width:320px;max-height:320px;border-radius:6px;display:block"></video>' . $cap;
                default:
                    $label = $media['filename'] ?: (_l('se_media_document') . ' (' . strtoupper(pathinfo((string) $media['path'], PATHINFO_EXTENSION)) . ')');
                    return '<a href="' . html_escape($url) . '" target="_blank" rel="noopener" class="btn btn-default btn-sm">'
                         . '<i class="fa fa-file-o"></i> ' . html_escape($label) . '</a>'
                         . (!empty($media['bytes']) ? ' <small class="text-muted">' . round($media['bytes'] / 1024) . ' KB</small>' : '') . $cap;
            }
        case 'failed':
            return '<span class="label label-danger"><i class="fa fa-exclamation-triangle"></i> '
                 . html_escape(_l('se_media_failed')) . '</span> <small class="text-muted">' . html_escape((string) $media['last_error']) . '</small>' . $cap;
        default:
            return '<span class="label label-info"><i class="fa fa-refresh"></i> '
                 . html_escape(_l('se_media_fetching')) . ' (' . html_escape($media['kind']) . ')</span>' . $cap;
    }
}

/* ===========================================================================
 * OUTBOUND attachments — files a staff member sends from the composer.
 *
 * The file is validated and stored at upload time (same allow-list, sniff and
 * cap as inbound; Instagram additionally caps at 8 MB per Meta), with
 * direction='out' and message_id=0 until the send succeeds, when the thread
 * row is created and linked. WhatsApp uploads the bytes to the Cloud API
 * (/{phone_number_id}/media) at send time; Instagram's Send API only accepts
 * a URL, so a short-lived, HMAC-signed public URL is minted for the file.
 * ======================================================================== */

define('SE_MEDIA_IG_MAX_BYTES', 8 * 1024 * 1024);
define('SE_MEDIA_PUB_TTL', 3600);

/** Schema additions for outbound (schema v15; idempotent). */
function se_media_schema_statements_v15($p)
{
    return [
        "ALTER TABLE `{$p}se_media` ADD COLUMN IF NOT EXISTS `direction` varchar(4) NOT NULL DEFAULT 'in'",
        "ALTER TABLE `{$p}se_media` ADD COLUMN IF NOT EXISTS `outbound_id` bigint(20) DEFAULT NULL",
        "ALTER TABLE `{$p}se_media` ADD COLUMN IF NOT EXISTS `created_by` int(11) NOT NULL DEFAULT 0",
        // Outbound rows share message_id=0 until sent, so the pair can no longer be unique.
        "ALTER TABLE `{$p}se_media` DROP INDEX IF EXISTS `channel_message`",
        "ALTER TABLE `{$p}se_media` ADD INDEX IF NOT EXISTS `channel_message` (`channel`,`message_id`)",
        "ALTER TABLE `{$p}se_wa_outbound` ADD COLUMN IF NOT EXISTS `media_id` bigint(20) DEFAULT NULL",
        "ALTER TABLE `{$p}se_ig_outbound` ADD COLUMN IF NOT EXISTS `media_id` bigint(20) DEFAULT NULL",
        // v16: where the bytes live — 'local' (CRM host) or 'r2' (Cloudflare, via crm-media Worker).
        "ALTER TABLE `{$p}se_media` ADD COLUMN IF NOT EXISTS `storage` varchar(8) NOT NULL DEFAULT 'local'",
        "ALTER TABLE `{$p}se_media` ADD INDEX IF NOT EXISTS `storage_state` (`storage`,`state`)",
    ];
}

/** What a channel may send: kind => true. */
function se_media_sendable_kinds($channel)
{
    return $channel === 'ig'
        ? ['image' => true, 'audio' => true, 'video' => true]
        : ['image' => true, 'audio' => true, 'video' => true, 'document' => true];
}

/**
 * Validate + store one uploaded file (a $_FILES entry) for sending.
 *
 * @return array{ok:bool,id:int,error:string,kind:string}
 */
function se_media_store_upload($channel, $brand_id, array $file, $staff_id = 0)
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'id' => 0, 'error' => 'no_file', 'kind' => ''];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'id' => 0, 'error' => 'upload_error_' . $err, 'kind' => ''];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        return ['ok' => false, 'id' => 0, 'error' => 'upload_missing', 'kind' => ''];
    }

    $size = (int) filesize($tmp);
    $cap  = $channel === 'ig' ? SE_MEDIA_IG_MAX_BYTES : SE_MEDIA_MAX_BYTES;
    if ($size <= 0) {
        return ['ok' => false, 'id' => 0, 'error' => 'empty_file', 'kind' => ''];
    }
    if ($size > $cap) {
        return ['ok' => false, 'id' => 0, 'error' => 'too_large', 'kind' => ''];
    }

    // The declared type is a hint; the bytes decide.
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_file($fi, $tmp);
        finfo_close($fi);
    }
    // Candidates in order of trust: what libmagic sees, then what the browser
    // declared (libmagic is unsure about some short/odd-but-valid files). A
    // candidate counts only if it is allow-listed AND the leading bytes agree.
    $allowed = se_media_allowed();
    $head    = (string) file_get_contents($tmp, false, null, 0, 16);
    $alias   = ['audio/x-m4a' => 'audio/mp4', 'audio/m4a' => 'audio/mp4', 'video/x-m4v' => 'video/mp4',
                'image/jpg' => 'image/jpeg', 'audio/x-wav' => 'audio/wav', 'audio/vnd.wave' => 'audio/wav'];
    $picked  = '';
    $sawMismatch = false;
    $declared = se_media_normalize_mime((string) ($file['type'] ?? ''));
    // An audio-only MP4 (a recorded voice message, an .m4a) is reported by
    // libmagic as video/mp4; when the browser says it is audio, believe the
    // browser about the *track* — the container is the same.
    if ($mime === 'video/mp4' && strpos($declared, 'audio/') === 0) {
        $mime = 'audio/mp4';
    }
    foreach ([$mime, $declared] as $cand) {
        $cand = se_media_normalize_mime($cand);
        $cand = $alias[$cand] ?? $cand;
        if ($cand === '' || !isset($allowed[$cand])) { continue; }
        if (!se_media_sniff_ok($cand, $head)) { $sawMismatch = true; continue; }
        $picked = $cand;
        break;
    }
    if ($picked === '') {
        return ['ok' => false, 'id' => 0, 'error' => $sawMismatch ? 'content_mismatch' : 'unsupported_type', 'kind' => ''];
    }
    $mime = $picked;
    [$kind, $ext] = $allowed[$mime];
    if (!isset(se_media_sendable_kinds($channel)[$kind])) {
        return ['ok' => false, 'id' => 0, 'error' => 'unsupported_for_channel', 'kind' => $kind];
    }

    $CI = &get_instance();
    $table = db_prefix() . 'se_media';
    $CI->db->insert($table, [
        'channel'      => $channel,
        'message_id'   => 0,
        'brand_id'     => (int) $brand_id,
        'direction'    => 'out',
        'kind'         => $kind,
        'provider_ref' => null,
        'filename'     => mb_substr(basename((string) ($file['name'] ?? '')), 0, 191) ?: null,
        'mime'         => $mime,
        'bytes'        => $size,
        'state'        => 'pending',
        'attempts'     => 0,
        'created_by'   => (int) $staff_id,
        'next_attempt_at' => se_db_now(),
        'date_created' => se_db_now(),
    ]);
    $id = (int) $CI->db->insert_id();

    $rel   = $channel . '/' . (int) $brand_id . '/' . $id . '.' . $ext;
    $bytes = (string) file_get_contents($tmp);
    $put   = se_media_storage_put($rel, $bytes, $mime);
    @unlink($tmp);
    if (!$put['ok']) {
        $CI->db->where('id', $id)->update($table, ['state' => 'failed', 'last_error' => $put['error']]);
        return ['ok' => false, 'id' => 0, 'error' => 'store_failed', 'kind' => $kind];
    }

    $CI->db->where('id', $id)->update($table, [
        'state' => 'stored', 'storage' => $put['storage'], 'path' => $rel,
        'sha256' => hash('sha256', $bytes), 'fetched_at' => se_db_now(),
    ]);

    return ['ok' => true, 'id' => $id, 'error' => '', 'kind' => $kind];
}

/** A stored OUTBOUND media row this brand may send, or null. */
function se_media_sendable($media_id, $channel, $brand_id)
{
    $row = se_media_get((int) $media_id);
    if (!$row || $row['channel'] !== $channel || (int) $row['brand_id'] !== (int) $brand_id
        || ($row['direction'] ?? 'in') !== 'out' || $row['state'] !== 'stored'
        || !isset(se_media_sendable_kinds($channel)[$row['kind']])) {
        return null;
    }
    return $row;
}

/** Link a media row to the thread message created after a successful send. */
function se_media_attach_message($media_id, $message_id, $outbound_id = null)
{
    $CI = &get_instance();
    $upd = ['message_id' => (int) $message_id];
    if ($outbound_id !== null) { $upd['outbound_id'] = (int) $outbound_id; }
    $CI->db->where('id', (int) $media_id)->update(db_prefix() . 'se_media', $upd);
}

/* ---- Signed public URL (Instagram Send API fetches attachments by URL) --- */

function se_media_pub_key()
{
    $k = (string) get_option('se_media_pub_key');
    if ($k === '') {
        $k = bin2hex(random_bytes(32));
        update_option('se_media_pub_key', $k);
    }
    return $k;
}

function se_media_pub_sig($id, $exp)
{
    return substr(hash_hmac('sha256', (int) $id . '|' . (int) $exp, se_media_pub_key()), 0, 40);
}

/** Time-limited public URL for one stored row. */
function se_media_pub_url(array $row, $ttl = SE_MEDIA_PUB_TTL, $now = null)
{
    $exp = ($now ?? time()) + (int) $ttl;
    return site_url('se_core/se_media_pub/index/' . (int) $row['id'] . '/' . $exp . '/' . se_media_pub_sig($row['id'], $exp));
}

/** Verify a public URL's id/exp/sig. Returns the row or null. */
function se_media_pub_verify($id, $exp, $sig, $now = null)
{
    $now = $now ?? time();
    if ((int) $exp < $now || !preg_match('/^[a-f0-9]{40}$/', (string) $sig)) {
        return null;
    }
    if (!hash_equals(se_media_pub_sig($id, $exp), (string) $sig)) {
        return null;
    }
    $row = se_media_get((int) $id);
    return $row && $row['state'] === 'stored' && ($row['direction'] ?? 'in') === 'out' ? $row : null;
}

/* ---------------------------------------------------------------------------
 * Storage-neutral helpers used by the transports and the sendable guard.
 * (The r2 driver lives in se_media_storage.php; these fall back to local.)
 * ------------------------------------------------------------------------- */

/** Does the row's file exist wherever it is stored? */
function se_media_present(array $row)
{
    return function_exists('se_media_available') ? se_media_available($row) : se_media_abs_path($row) !== '';
}
