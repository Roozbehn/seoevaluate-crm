<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * WhatsApp message templates — the CRM's mirror of the WABA template library.
 *
 * The composer and the outbound queue only ever offer/accept a template whose
 * row in tblse_wa_templates says APPROVED. Before this file nothing wrote that
 * table: the readiness page and the conversation composer said "No approved
 * templates for this brand" while WhatsApp Manager listed approved ones.
 *
 * Three sources keep the mirror current, in order of authority:
 *   1. se_wa_sync_templates($brand_id) — full pull of the WABA template list
 *      from Graph (manual button on the readiness page; throttled cron).
 *   2. message_template_status_update webhooks — Meta pushes approval /
 *      rejection / pause changes; applied in place, no network.
 *   3. Nothing else. Rows are never hand-edited: a template that Meta no
 *      longer returns is marked `deleted`, never removed, so history stays.
 *
 * Network access goes through ONE seam (se_wa_register_template_fetcher) so
 * tests exercise parsing/upsert logic end to end without a socket.
 */

define('SE_WA_TEMPLATE_SYNC_INTERVAL', 6 * 3600);   // cron re-pull cadence per brand
define('SE_WA_TEMPLATE_PAGE_LIMIT', 100);
define('SE_WA_TEMPLATE_MAX_PAGES', 20);

$GLOBALS['SE_WA_TEMPLATE_FETCHER'] = $GLOBALS['SE_WA_TEMPLATE_FETCHER'] ?? null;

/** Register a fetcher: callable(waba_id):array{ok:bool,templates:array,error:string}. Tests/live. */
function se_wa_register_template_fetcher(callable $f)
{
    $GLOBALS['SE_WA_TEMPLATE_FETCHER'] = $f;
}

/* ---------------------------------------------------------------------------
 * Pure parsing.
 * ------------------------------------------------------------------------- */

/**
 * Normalise one Graph template object into a tblse_wa_templates row.
 *
 * Meta's status vocabulary (APPROVED, PENDING, REJECTED, PAUSED, DISABLED,
 * IN_APPEAL, PENDING_DELETION, DELETED, LIMIT_EXCEEDED) is stored lower-case
 * in `approval_state`; the composer's `approved` filter matches exactly one of
 * them. Anything unknown is kept verbatim (lower-cased) so a new Meta state
 * never silently reads as approved.
 *
 * @return array|null  null when the object lacks a usable name/language.
 */
function se_wa_parse_template($brand_id, array $tpl)
{
    $name = trim((string) ($tpl['name'] ?? ''));
    $lang = trim((string) ($tpl['language'] ?? ''));

    if ($name === '' || $lang === '') {
        return null;
    }

    $body = '';
    $vars = [];

    foreach ((array) ($tpl['components'] ?? []) as $component) {
        if (strtoupper((string) ($component['type'] ?? '')) !== 'BODY') {
            continue;
        }
        $body = (string) ($component['text'] ?? '');
        // {{1}}, {{2}} … positional placeholders, or {{name}} named ones.
        if (preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $body, $m)) {
            $vars = array_values(array_unique($m[1]));
        }
        break;
    }

    $quality = $tpl['quality_score']['score'] ?? null;

    return [
        'brand_id'       => (int) $brand_id,
        'name'           => mb_substr($name, 0, 128),
        'language'       => mb_substr($lang, 0, 8),
        'category'       => mb_substr(strtoupper((string) ($tpl['category'] ?? '')), 0, 24) ?: null,
        'approval_state' => mb_substr(strtolower((string) ($tpl['status'] ?? '')), 0, 16) ?: null,
        'body'           => $body !== '' ? mb_substr($body, 0, 4096) : null,
        'variables'      => $vars ? mb_substr(implode(',', $vars), 0, 255) : null,
        'quality_state'  => $quality !== null ? mb_substr(strtolower((string) $quality), 0, 16) : null,
    ];
}

/**
 * Placeholder keys a mirror row expects, in body order ('1','2',… or names).
 * Reads the stored `variables` column; falls back to parsing `body` for rows
 * inserted by a status webhook before a full sync filled the column in.
 */
function se_wa_template_variables(array $row)
{
    $stored = trim((string) ($row['variables'] ?? ''));
    if ($stored !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $stored)), 'strlen'));
    }
    if (preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', (string) ($row['body'] ?? ''), $m)) {
        return array_values(array_unique($m[1]));
    }

    return [];
}

/* ---------------------------------------------------------------------------
 * Persistence.
 * ------------------------------------------------------------------------- */

/** Upsert one parsed row on (brand_id, name, language). Returns 'inserted'|'updated'|'unchanged'. */
function se_wa_upsert_template(array $row)
{
    $CI = &get_instance();
    $table = db_prefix() . 'se_wa_templates';

    $CI->db->where('brand_id', (int) $row['brand_id'])
           ->where('name', $row['name'])
           ->where('language', $row['language']);
    $existing = $CI->db->get($table)->row_array();

    if ($existing) {
        $changed = false;
        foreach (['category', 'approval_state', 'body', 'variables', 'quality_state'] as $col) {
            if ((string) ($existing[$col] ?? '') !== (string) ($row[$col] ?? '')) {
                $changed = true;
                break;
            }
        }
        if (!$changed) {
            return 'unchanged';
        }
        $CI->db->where('id', (int) $existing['id'])->update($table, [
            'category'       => $row['category'],
            'approval_state' => $row['approval_state'],
            'body'           => $row['body'],
            'variables'      => $row['variables'],
            'quality_state'  => $row['quality_state'],
            'last_updated'   => se_db_now(),
        ]);

        return 'updated';
    }

    $row['date_created'] = se_db_now();
    $row['last_updated'] = se_db_now();
    $CI->db->insert($table, $row);

    return 'inserted';
}

/** The WABA id a brand's active number belongs to (first mapped number wins). */
function se_wa_waba_for_brand($brand_id)
{
    $CI = &get_instance();
    $CI->db->where('brand_id', (int) $brand_id)->order_by('id', 'ASC')->limit(1);
    $row = $CI->db->get(db_prefix() . 'se_wa_numbers')->row_array();

    return $row ? (string) ($row['waba_id'] ?? '') : '';
}

/** Brand owning a WABA id (template webhooks carry the WABA, not a phone number). */
function se_wa_route_waba_to_brand($waba_id)
{
    if (!$waba_id) {
        return null;
    }
    $CI = &get_instance();
    $CI->db->where('waba_id', (string) $waba_id)->order_by('id', 'ASC')->limit(1);
    $row = $CI->db->get(db_prefix() . 'se_wa_numbers')->row_array();

    return $row ? (int) $row['brand_id'] : null;
}

/**
 * Full sync for one brand: pull every template on its WABA and mirror it.
 *
 * @return array{ok:bool,reason:string,inserted:int,updated:int,unchanged:int,removed:int,approved:int}
 */
function se_wa_sync_templates($brand_id)
{
    $out = ['ok' => false, 'reason' => '', 'inserted' => 0, 'updated' => 0,
            'unchanged' => 0, 'removed' => 0, 'approved' => 0];

    $brand_id = (int) $brand_id;
    if ($brand_id <= 0) {
        $out['reason'] = 'no_brand';
        return $out;
    }

    $waba = se_wa_waba_for_brand($brand_id);
    if ($waba === '') {
        $out['reason'] = 'no_waba';
        return $out;
    }

    $fetched = se_wa_fetch_templates($waba);
    if (empty($fetched['ok'])) {
        $out['reason'] = (string) ($fetched['error'] ?? 'fetch_failed');
        update_option('se_wa_templates_last_error_' . $brand_id, $out['reason']);
        return $out;
    }

    $seen = [];
    foreach ((array) $fetched['templates'] as $tpl) {
        $row = se_wa_parse_template($brand_id, (array) $tpl);
        if ($row === null) {
            continue;
        }
        $seen[$row['name'] . '|' . $row['language']] = true;
        $out[se_wa_upsert_template($row)]++;
        if ($row['approval_state'] === 'approved') {
            $out['approved']++;
        }
    }

    // Anything the WABA no longer lists is gone on Meta's side: mark, don't delete.
    $CI = &get_instance();
    $table = db_prefix() . 'se_wa_templates';
    $CI->db->where('brand_id', $brand_id);
    foreach ($CI->db->get($table)->result_array() as $existing) {
        $key = $existing['name'] . '|' . $existing['language'];
        if (isset($seen[$key]) || ($existing['approval_state'] ?? '') === 'deleted') {
            continue;
        }
        $CI->db->where('id', (int) $existing['id'])->update($table, [
            'approval_state' => 'deleted', 'last_updated' => se_db_now(),
        ]);
        $out['removed']++;
    }

    $out['ok'] = true;
    update_option('se_wa_templates_synced_at_' . $brand_id, se_db_now());
    update_option('se_wa_templates_last_error_' . $brand_id, '');

    return $out;
}

/**
 * Apply one message_template_status_update webhook value in place.
 *
 * Value shape (Meta): {event, message_template_id, message_template_name,
 * message_template_language, reason?}. A template the mirror has never seen is
 * inserted with the pushed state so an approval is usable immediately; the
 * next full sync fills in body/category.
 */
function se_wa_handle_template_status($brand_id, array $value)
{
    $name  = trim((string) ($value['message_template_name'] ?? ''));
    $lang  = trim((string) ($value['message_template_language'] ?? ''));
    $event = strtolower(trim((string) ($value['event'] ?? '')));

    if ($name === '' || $lang === '' || $event === '') {
        return false;
    }

    $CI = &get_instance();
    $table = db_prefix() . 'se_wa_templates';

    $CI->db->where('brand_id', (int) $brand_id)->where('name', $name)->where('language', $lang);
    $existing = $CI->db->get($table)->row_array();

    if ($existing) {
        $CI->db->where('id', (int) $existing['id'])->update($table, [
            'approval_state' => mb_substr($event, 0, 16),
            'last_updated'   => se_db_now(),
        ]);
    } else {
        $CI->db->insert($table, [
            'brand_id'       => (int) $brand_id,
            'name'           => mb_substr($name, 0, 128),
            'language'       => mb_substr($lang, 0, 8),
            'approval_state' => mb_substr($event, 0, 16),
            'date_created'   => se_db_now(),
            'last_updated'   => se_db_now(),
        ]);
    }

    update_option('se_wa_template_status_at_' . (int) $brand_id, se_db_now());

    return true;
}

/** Throttled cron sync: each brand with a WABA re-pulls at most every SE_WA_TEMPLATE_SYNC_INTERVAL. */
function se_wa_sync_templates_cron()
{
    if (!function_exists('se_secret_read') || se_wa_cloud_token() === '') {
        return 0;   // nothing to authenticate with; stay quiet, not "failed"
    }

    $CI = &get_instance();
    $brands = [];
    foreach ($CI->db->get(db_prefix() . 'se_wa_numbers')->result_array() as $n) {
        if ((string) ($n['waba_id'] ?? '') !== '' && (int) $n['brand_id'] > 0) {
            $brands[(int) $n['brand_id']] = true;
        }
    }

    $ran = 0;
    foreach (array_keys($brands) as $brand_id) {
        $last = (string) get_option('se_wa_templates_synced_at_' . $brand_id);
        if ($last !== '' && strtotime($last) !== false
            && strtotime($last) > strtotime(se_db_now()) - SE_WA_TEMPLATE_SYNC_INTERVAL) {
            continue;
        }
        se_wa_sync_templates($brand_id);
        $ran++;
    }

    return $ran;
}

/* ---------------------------------------------------------------------------
 * Network seam.
 * ------------------------------------------------------------------------- */

/** Fetch every template on a WABA. Registered fixture first; Graph otherwise. */
function se_wa_fetch_templates($waba_id)
{
    if (is_callable($GLOBALS['SE_WA_TEMPLATE_FETCHER'] ?? null)) {
        $r = call_user_func($GLOBALS['SE_WA_TEMPLATE_FETCHER'], (string) $waba_id);
        return is_array($r) ? $r : ['ok' => false, 'templates' => [], 'error' => 'fetch_failed'];
    }

    return se_wa_graph_fetch_templates((string) $waba_id);
}

/** Live Graph pull, paged. Token in the header only; error bodies sanitised. */
function se_wa_graph_fetch_templates($waba_id)
{
    $token = se_wa_cloud_token();
    if ($token === '') {
        return ['ok' => false, 'templates' => [], 'error' => 'no_token'];
    }

    $version = get_option('se_meta_graph_version') ?: 'v23.0';
    $url = 'https://graph.facebook.com/' . $version . '/' . rawurlencode($waba_id)
         . '/message_templates?fields=name,language,status,category,components,quality_score'
         . '&limit=' . SE_WA_TEMPLATE_PAGE_LIMIT;

    $all = [];
    for ($page = 0; $page < SE_WA_TEMPLATE_MAX_PAGES && $url !== ''; $page++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'templates' => [], 'error' => 'network_error'];
        }

        $body = json_decode((string) $raw, true) ?: [];

        if ($code < 200 || $code >= 300) {
            $msg = (string) ($body['error']['message'] ?? ('graph HTTP ' . $code));
            return ['ok' => false, 'templates' => [], 'error' => mb_substr($msg, 0, 180)];
        }

        foreach ((array) ($body['data'] ?? []) as $tpl) {
            $all[] = $tpl;
        }

        // Only follow a Graph-hosted next link; anything else is not ours to fetch.
        $next = (string) ($body['paging']['next'] ?? '');
        $url = (strpos($next, 'https://graph.facebook.com/') === 0) ? $next : '';
    }

    if (function_exists('se_secret_note_auth')) {
        se_secret_note_auth('wa_token', 0, true);
    }

    return ['ok' => true, 'templates' => $all, 'error' => ''];
}
