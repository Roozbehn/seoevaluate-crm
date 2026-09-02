<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Media storage backends for tblse_media rows.
 *
 *   local — files under se_media_dir() on the CRM host (the original store).
 *   r2    — Cloudflare R2 bucket `azin-media`, prefix `crm/`, reached through
 *           the gateway Worker `crm-media` (services/crm-media) with a shared
 *           HMAC key (secret provider `r2_media_key`). The Worker holds the
 *           bucket binding; the CRM never sees R2 credentials.
 *
 * Every row records where its bytes live (`storage` column), so the two can
 * coexist while old files are migrated. New writes go to the configured
 * driver (option `se_media_storage`, default 'local'; 'r2' once the gateway
 * URL and key are set). HTTP goes through one seam for tests.
 *
 * Serving from R2 is by SIGNED, SHORT-LIVED URL: the CRM's authenticated route
 * still does the staff/brand check, then redirects the browser to
 *   <gateway>/o/<key>?exp=<unix>&sig=hex(HMAC-SHA256(key|exp))
 * so no file bytes pass through PHP. Meta's Instagram fetcher receives the
 * same kind of URL.
 */

define('SE_MEDIA_R2_PREFIX', 'crm/');
define('SE_MEDIA_R2_VIEW_TTL', 600);      // staff view redirect
define('SE_MEDIA_R2_PUB_TTL', 3600);      // Instagram Send API fetch

$GLOBALS['SE_MEDIA_HTTP'] = $GLOBALS['SE_MEDIA_HTTP'] ?? null;

/** callable(method, url, headers[], body): ['code'=>int,'body'=>string,'headers'=>array] */
function se_media_register_http(callable $f)
{
    $GLOBALS['SE_MEDIA_HTTP'] = $f;
}

/* ------------------------------------------------------------------ config */

function se_media_r2_url()
{
    return rtrim((string) get_option('se_media_r2_url'), '/');
}

function se_media_r2_key()
{
    return function_exists('se_secret_read') ? se_secret_read('r2_media_key') : '';
}

/** Is the R2 gateway usable (URL + key present)? */
function se_media_r2_ready()
{
    return se_media_r2_url() !== '' && se_media_r2_key() !== '';
}

/** Driver for NEW writes: 'r2' only when configured AND ready, else 'local'. */
function se_media_storage_driver()
{
    return get_option('se_media_storage') === 'r2' && se_media_r2_ready() ? 'r2' : 'local';
}

/** R2 object key for a row-relative path (the same <ch>/<brand>/<id>.<ext> layout). */
function se_media_r2_object_key($rel)
{
    return SE_MEDIA_R2_PREFIX . ltrim((string) $rel, '/');
}

/* ---------------------------------------------------------------- signing */

function se_media_r2_sig($key, $exp)
{
    return hash_hmac('sha256', $key . '|' . (int) $exp, se_media_r2_key());
}

/** Signed GET URL for an object key, valid for $ttl seconds. */
function se_media_r2_signed_url($rel, $ttl, $now = null)
{
    $key = se_media_r2_object_key($rel);
    $exp = ($now ?? time()) + (int) $ttl;
    return se_media_r2_url() . '/o/' . str_replace('%2F', '/', rawurlencode($key))
         . '?exp=' . $exp . '&sig=' . se_media_r2_sig($key, $exp);
}

/* ------------------------------------------------------------------- HTTP */

function se_media_http($method, $url, array $headers = [], $body = null)
{
    if (is_callable($GLOBALS['SE_MEDIA_HTTP'] ?? null)) {
        return call_user_func($GLOBALS['SE_MEDIA_HTTP'], $method, $url, $headers, $body);
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    if ($method === 'HEAD') {
        $opts[CURLOPT_NOBODY] = true;
    }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['code' => $raw === false ? 0 : $code, 'body' => $raw === false ? '' : (string) $raw, 'headers' => ['content-type' => $type]];
}

/* --------------------------------------------------------------- R2 ops */

/** Upload bytes. Returns '' or an error string. */
function se_media_r2_put($rel, $bytes, $mime)
{
    $r = se_media_http('PUT', se_media_r2_url() . '/o/' . str_replace('%2F', '/', rawurlencode(se_media_r2_object_key($rel))),
        ['Authorization: Bearer ' . se_media_r2_key(), 'Content-Type: ' . ($mime ?: 'application/octet-stream'),
         'Content-Length: ' . strlen($bytes)], $bytes);
    if ($r['code'] < 200 || $r['code'] >= 300) {
        return 'r2 put failed (HTTP ' . $r['code'] . ')';
    }
    return '';
}

/** Download bytes (server-to-server, bearer). '' on failure. */
function se_media_r2_get($rel)
{
    $r = se_media_http('GET', se_media_r2_url() . '/o/' . str_replace('%2F', '/', rawurlencode(se_media_r2_object_key($rel))),
        ['Authorization: Bearer ' . se_media_r2_key()]);
    return $r['code'] >= 200 && $r['code'] < 300 ? (string) $r['body'] : '';
}

/* ------------------------------------------------- driver-neutral surface */

/**
 * Store bytes for a row-relative path with the configured driver.
 * @return array{ok:bool,storage:string,error:string}
 */
function se_media_storage_put($rel, $bytes, $mime)
{
    if (se_media_storage_driver() === 'r2') {
        $err = se_media_r2_put($rel, $bytes, $mime);
        return ['ok' => $err === '', 'storage' => 'r2', 'error' => $err];
    }
    $dir = dirname((string) $rel);
    if (($err = se_media_ensure_dir($dir)) !== '') {
        return ['ok' => false, 'storage' => 'local', 'error' => $err];
    }
    $path = se_media_dir() . '/' . $rel;
    if (@file_put_contents($path, $bytes, LOCK_EX) === false) {
        return ['ok' => false, 'storage' => 'local', 'error' => 'write failed'];
    }
    @chmod($path, 0600);
    return ['ok' => true, 'storage' => 'local', 'error' => ''];
}

/**
 * A LOCAL file path holding the row's bytes — for anything that needs a real
 * file (the WhatsApp multipart upload). Local rows return their store path;
 * R2 rows are downloaded to a temp file the caller deletes. '' if unavailable.
 */
function se_media_local_copy(array $row)
{
    if (($row['storage'] ?? 'local') !== 'r2') {
        return se_media_abs_path($row);
    }
    if (($row['state'] ?? '') !== 'stored' || empty($row['path']) || strpos($row['path'], '..') !== false) {
        return '';
    }
    $bytes = se_media_r2_get($row['path']);
    if ($bytes === '') {
        return '';
    }
    $tmp = tempnam(sys_get_temp_dir(), 'semedia');
    if ($tmp === false || @file_put_contents($tmp, $bytes) === false) {
        return '';
    }
    return $tmp;
}

/** Is the row's file present (local: on disk; r2: trusted by state)? */
function se_media_available(array $row)
{
    return ($row['storage'] ?? 'local') === 'r2'
        ? (($row['state'] ?? '') === 'stored' && !empty($row['path']))
        : se_media_abs_path($row) !== '';
}

/**
 * Where a browser should be sent for the row after the CRM has authorised the
 * request: a signed gateway URL for R2 rows, '' for local rows (stream it).
 */
function se_media_view_redirect(array $row, $now = null)
{
    if (($row['storage'] ?? 'local') !== 'r2' || !se_media_available($row)) {
        return '';
    }
    return se_media_r2_signed_url($row['path'], SE_MEDIA_R2_VIEW_TTL, $now);
}

/** Public URL for Meta's fetcher: signed gateway URL (r2) or the CRM's own signed route (local). */
function se_media_public_url(array $row, $now = null)
{
    if (($row['storage'] ?? 'local') === 'r2') {
        return se_media_r2_signed_url($row['path'], SE_MEDIA_R2_PUB_TTL, $now);
    }
    return se_media_pub_url($row, SE_MEDIA_PUB_TTL, $now);
}

/* ------------------------------------------------------------- migration */

/**
 * Move up to $limit stored LOCAL rows into R2 (verifies the upload with a
 * bearer GET before deleting the local file). Idempotent; safe to re-run.
 * @return array{moved:int,failed:int,errors:array}
 */
function se_media_migrate_local_to_r2($limit = 25)
{
    $out = ['moved' => 0, 'failed' => 0, 'errors' => []];
    if (!se_media_r2_ready()) {
        $out['errors'][] = 'r2 not configured';
        return $out;
    }
    $CI = &get_instance();
    $table = db_prefix() . 'se_media';
    $CI->db->where('state', 'stored')->where('storage', 'local')->order_by('id', 'ASC')->limit((int) $limit);
    foreach ($CI->db->get($table)->result_array() as $row) {
        $abs = se_media_abs_path($row);
        if ($abs === '') {
            $out['failed']++; $out['errors'][] = $row['id'] . ': local file missing';
            continue;
        }
        $bytes = (string) file_get_contents($abs);
        $err = se_media_r2_put($row['path'], $bytes, (string) $row['mime']);
        if ($err !== '') {
            $out['failed']++; $out['errors'][] = $row['id'] . ': ' . $err;
            continue;
        }
        $back = se_media_r2_get($row['path']);
        if ($back === '' || hash('sha256', $back) !== hash('sha256', $bytes)) {
            $out['failed']++; $out['errors'][] = $row['id'] . ': verify failed';
            continue;
        }
        $CI->db->where('id', (int) $row['id'])->update($table, ['storage' => 'r2']);
        @unlink($abs);
        $out['moved']++;
    }
    return $out;
}
