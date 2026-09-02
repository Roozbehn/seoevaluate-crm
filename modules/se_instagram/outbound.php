<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Instagram OUTBOUND layer — queue, gates, drain. Same rules as WhatsApp:
 *   1. Free-form text only INSIDE the 24-hour window that opens on each
 *      inbound message (Instagram has no approved-template fallback; outside
 *      the window the composer is closed rather than silently dropped).
 *   2. Every outbound message carries a stable idempotency key.
 * Nothing sends inline; the drain does, through a registered transport.
 */

define('SE_IG_OUT_MAX_ATTEMPTS', 5);
define('SE_IG_OUT_LEASE_SECONDS', 900);
define('SE_IG_OUT_BACKOFF_BASE', 60);
define('SE_IG_OUT_BACKOFF_CAP', 7200);
define('SE_IG_OUT_MAX_TEXT', 1000);   // Instagram text message cap

$GLOBALS['SE_IG_TRANSPORT'] = null;

function se_ig_register_transport(callable $t) { $GLOBALS['SE_IG_TRANSPORT'] = $t; }
function se_ig_transport_available()          { return is_callable($GLOBALS['SE_IG_TRANSPORT'] ?? null); }

/**
 * AUTHORITATIVE send-capability check — composer, drain, Health and
 * diagnostics all read this, so they can never disagree. Empty when sendable.
 */
function se_ig_send_blocked_reason($brand_id)
{
    if (function_exists('se_ig_maybe_register_live_transport')) {
        se_ig_maybe_register_live_transport((int) $brand_id);   // brand-scoped token counts
    }

    if (!se_ig_can_send($brand_id)) {
        return 'no_account';
    }
    if (se_ig_app_secret() === '') {
        return 'no_credentials';
    }
    if (se_ig_token((int) $brand_id) === '') {
        return 'no_token';
    }
    // Sending needs Instagram messaging scopes on the token; a diagnostic
    // records the verified state so the composer never lies about it.
    if ((int) get_option('se_ig_scopes_verified') !== 1) {
        return 'scopes_unverified';
    }
    if (!se_ig_transport_available()) {
        return 'no_transport';
    }

    return '';
}

function se_ig_inbox_blocked_reason(array $conversations)
{
    $brands = [];
    foreach ($conversations as $c) {
        $b = (int) (is_array($c) ? ($c['brand_id'] ?? 0) : ($c->brand_id ?? 0));
        if ($b > 0) { $brands[$b] = true; }
    }

    return count($brands) === 1 ? se_ig_send_blocked_reason((int) array_key_first($brands)) : '';
}

/** @return array{allowed:bool,mode:string,reason:string,window_open:bool,expires_at:?string} */
function se_ig_compose_policy($conversation)
{
    $open    = se_ig_window_open($conversation);
    $blocked = se_ig_send_blocked_reason((int) $conversation->brand_id);

    if ($blocked !== '') {
        return ['allowed' => false, 'mode' => 'none', 'reason' => $blocked,
                'window_open' => $open, 'expires_at' => $conversation->window_expires_at ?? null];
    }
    if ($open) {
        return ['allowed' => true, 'mode' => 'freeform', 'reason' => '',
                'window_open' => true, 'expires_at' => $conversation->window_expires_at];
    }

    return ['allowed' => false, 'mode' => 'none', 'reason' => 'window_closed',
            'window_open' => false, 'expires_at' => $conversation->window_expires_at ?? null];
}

function se_ig_idempotency_key($conversation_id, $kind, $payload_signature)
{
    return substr(hash('sha256', (int) $conversation_id . '|' . $kind . '|' . $payload_signature), 0, 64);
}

/** Queue a text reply. Window enforced at queue time AND send time. */
function se_ig_queue_message($conversation_id, array $message, $staff_id = 0)
{
    $CI = &get_instance();

    $CI->db->where('id', (int) $conversation_id);
    se_apply_scope_in('brand_id');
    $conv = $CI->db->get(db_prefix() . 'se_ig_conversations')->row();

    if (!$conv) {
        return ['ok' => false, 'id' => 0, 'reason' => 'not_found'];
    }

    $policy = se_ig_compose_policy($conv);
    if (!$policy['allowed']) {
        return ['ok' => false, 'id' => 0, 'reason' => $policy['reason']];
    }

    $kind     = ($message['kind'] ?? 'text') === 'media' ? 'media' : 'text';
    $media_id = null;
    $body     = trim((string) ($message['body'] ?? ''));

    if ($kind === 'media') {
        // Instagram attachments carry no caption; the composer queues any text
        // as a separate message. Image / audio / video only, ≤ 8 MB (Meta).
        $media_id = (int) ($message['media_id'] ?? 0);
        $media = function_exists('se_media_sendable') ? se_media_sendable($media_id, 'ig', (int) $conv->brand_id) : null;
        if ($media === null) {
            return ['ok' => false, 'id' => 0, 'reason' => 'media_invalid'];
        }
        $body = null;
        $key  = se_ig_idempotency_key($conversation_id, 'media', hash('sha256', 'media|' . $media_id));
    } else {
        if ($body === '') {
            return ['ok' => false, 'id' => 0, 'reason' => 'empty_body'];
        }
        $body = mb_substr($body, 0, SE_IG_OUT_MAX_TEXT);
        $key  = se_ig_idempotency_key($conversation_id, 'text', hash('sha256', $body));
    }

    $CI->db->where('idempotency_key', $key);
    if ($CI->db->count_all_results(db_prefix() . 'se_ig_outbound') > 0) {
        return ['ok' => false, 'id' => 0, 'reason' => 'duplicate'];
    }

    try {
        $CI->db->insert(db_prefix() . 'se_ig_outbound', [
            'conversation_id' => (int) $conv->id,
            'brand_id'        => (int) $conv->brand_id,
            'kind'            => $kind,
            'body'            => $body,
            'media_id'        => $media_id,
            'idempotency_key' => $key,
            'status'          => 'pending',
            'attempts'        => 0,
            'fence'           => 0,
            'created_by'      => (int) $staff_id,
            'date_created'    => se_db_now(),
            'next_attempt_at' => se_db_now(),
        ]);
    } catch (Exception $e) {
        return ['ok' => false, 'id' => 0, 'reason' => 'duplicate'];
    }

    return ['ok' => true, 'id' => (int) $CI->db->insert_id(), 'reason' => ''];
}

function se_ig_out_backoff_seconds($attempts)
{
    $exp = SE_IG_OUT_BACKOFF_BASE * (2 ** max(0, (int) $attempts - 1));

    return random_int((int) ($exp / 2), (int) min($exp, SE_IG_OUT_BACKOFF_CAP));
}

function se_ig_out_recover_stale()
{
    $CI = &get_instance();
    $CI->db->where('status', 'processing')->where('locked_at <', se_db_now(-SE_IG_OUT_LEASE_SECONDS))
           ->update(db_prefix() . 'se_ig_outbound', ['status' => 'pending', 'locked_at' => null, 'locked_by' => null]);

    return (int) $CI->db->affected_rows();
}

function se_ig_out_claim_batch($worker, $limit = 50)
{
    $CI    = &get_instance();
    $table = db_prefix() . 'se_ig_outbound';
    $now   = se_db_now();

    $CI->db->query(
        'UPDATE `' . $table . "` SET status='processing', locked_at=NOW()"
        . ', locked_by=' . $CI->db->escape($worker) . ', fence = fence + 1'
        . " WHERE status='pending' AND attempts < " . (int) SE_IG_OUT_MAX_ATTEMPTS
        . ' AND (next_attempt_at IS NULL OR next_attempt_at <= ' . $CI->db->escape($now) . ')'
        . ' ORDER BY id ASC LIMIT ' . max(1, (int) $limit)
    );

    $CI->db->where('status', 'processing')->where('locked_by', $worker)->order_by('id', 'ASC');

    return $CI->db->get($table)->result_array();
}

function se_ig_out_drain($limit = 50)
{
    $limit = (int) $limit; if ($limit < 1) { $limit = 50; }

    $CI    = &get_instance();
    $table = db_prefix() . 'se_ig_outbound';

    se_ig_out_recover_stale();

    $worker = substr(md5(uniqid((string) getmypid(), true)), 0, 24);
    $rows   = se_ig_out_claim_batch($worker, $limit);

    foreach ($rows as $row) {
        $update = se_ig_out_process($row);
        $update['locked_at'] = null;
        $update['locked_by'] = null;

        $CI->db->where('id', $row['id'])->where('locked_by', $worker)->where('fence', (int) $row['fence'])
               ->update($table, $update);
    }

    return count($rows);
}

/** Decide the outcome for one claimed outbound row. */
function se_ig_out_process($row)
{
    $CI = &get_instance();

    $CI->db->where('id', (int) $row['conversation_id']);
    $conv = $CI->db->get(db_prefix() . 'se_ig_conversations')->row();

    if (!$conv) {
        return ['status' => 'failed', 'attempts' => (int) $row['attempts'] + 1,
                'failure_class' => 'permanent', 'last_error' => 'conversation gone'];
    }

    // GATE first (hold, never consume an attempt), then the window.
    $blocked = se_ig_send_blocked_reason((int) $conv->brand_id);
    if (!se_ig_transport_available() || $blocked !== '') {
        return ['status' => 'pending', 'attempts' => (int) $row['attempts'], 'failure_class' => 'gated',
                'last_error' => 'sending gated: ' . ($blocked !== '' ? $blocked : 'no_transport'),
                'next_attempt_at' => se_db_now(3600)];
    }

    if (!se_ig_window_open($conv)) {
        return ['status' => 'skipped', 'attempts' => (int) $row['attempts'],
                'failure_class' => 'permanent', 'last_error' => 'service window closed before send'];
    }

    // Attachment: the Send API fetches by URL, so mint a signed, short-lived
    // public URL for the stored file (outbound rows only; see se_media_pub).
    $media = null;
    if (($row['kind'] ?? 'text') === 'media') {
        $media = function_exists('se_media_sendable') ? se_media_sendable((int) ($row['media_id'] ?? 0), 'ig', (int) $conv->brand_id) : null;
        if ($media === null || !se_media_present($media)) {
            return ['status' => 'failed', 'attempts' => (int) $row['attempts'] + 1,
                    'failure_class' => 'permanent', 'last_error' => 'attachment missing'];
        }
        // Signed R2 gateway URL for R2 rows; the CRM's own signed route for local ones.
        $media['url'] = function_exists('se_media_public_url') ? se_media_public_url($media) : se_media_pub_url($media);
    }

    try {
        $result = call_user_func($GLOBALS['SE_IG_TRANSPORT'], [
            'ig_account_id'   => $conv->ig_account_id,
            'brand_id'        => (int) $conv->brand_id,
            'to'              => $conv->igsid,
            'kind'            => $row['kind'] ?? 'text',
            'body'            => $row['body'],
            'media'           => $media,   // kind, mime, url — or null
            'idempotency_key' => $row['idempotency_key'],
        ]);
    } catch (Exception $e) {
        $attempts = (int) $row['attempts'] + 1;

        return ['status' => $attempts >= SE_IG_OUT_MAX_ATTEMPTS ? 'failed' : 'pending',
                'attempts' => $attempts, 'failure_class' => 'retryable', 'last_error' => 'transport error',
                'next_attempt_at' => se_db_now(se_ig_out_backoff_seconds($attempts))];
    }

    if (!empty($result['ok'])) {
        if (function_exists('se_secret_note_auth')) {
            se_secret_note_auth('meta_page', (int) $conv->brand_id, true);
        }
        se_ig_record_outbound($row, $conv, (string) ($result['mid'] ?? ''));

        return ['status' => 'sent', 'attempts' => (int) $row['attempts'] + 1,
                'mid' => (string) ($result['mid'] ?? ''), 'sent_at' => date('Y-m-d H:i:s'),
                'failure_class' => null, 'last_error' => null];
    }

    $code      = (int) ($result['code'] ?? 0);
    $attempts  = (int) $row['attempts'] + 1;
    $permanent = $code >= 400 && $code < 500 && !in_array($code, [408, 429], true);

    return [
        'status'          => $permanent || $attempts >= SE_IG_OUT_MAX_ATTEMPTS ? 'failed' : 'pending',
        'attempts'        => $attempts,
        'failure_class'   => $permanent ? 'permanent' : 'retryable',
        'last_error'      => mb_substr(preg_replace('/[A-Za-z0-9_\-]{24,}/', '[redacted]',
                                (string) ($result['error'] ?? 'send failed')), 0, 255),
        'next_attempt_at' => se_db_now(se_ig_out_backoff_seconds($attempts)),
    ];
}

/** Mirror a sent message into the thread (display clock = app timezone). */
function se_ig_record_outbound($row, $conv, $mid)
{
    if ($mid === '') {
        return;
    }
    $CI = &get_instance();

    $CI->db->where('mid', $mid)->where('brand_id', (int) $conv->brand_id);
    if ($CI->db->count_all_results(db_prefix() . 'se_ig_messages') > 0) {
        return;
    }

    $media = ($row['kind'] ?? 'text') === 'media' && function_exists('se_media_get') ? se_media_get((int) ($row['media_id'] ?? 0)) : null;

    $CI->db->insert(db_prefix() . 'se_ig_messages', [
        'conversation_id' => (int) $conv->id,
        'brand_id'        => (int) $conv->brand_id,
        'mid'             => $mid,
        'direction'       => 'out',
        'source'          => 'crm_api',
        'type'            => $media ? $media['kind'] : 'text',
        'body'            => $row['body'],
        'media_ref'       => $media ? 'out:' . (int) $media['id'] : null,
        'delivery_state'  => 'sent',
        'sent_at'         => date('Y-m-d H:i:s'),
        'date_created'    => date('Y-m-d H:i:s'),
    ]);

    if ($media && function_exists('se_media_attach_message')) {
        se_media_attach_message((int) $media['id'], (int) $CI->db->insert_id(), (int) ($row['id'] ?? 0));
    }
}

function se_ig_out_health($brand_id = 0)
{
    $CI = &get_instance();
    $CI->db->select('status, COUNT(*) AS c')->group_by('status');
    se_apply_scope_in('brand_id');
    if ((int) $brand_id > 0 && se_can_access_brand($brand_id)) {
        $CI->db->where('brand_id', (int) $brand_id);
    }
    $rows = $CI->db->get(db_prefix() . 'se_ig_outbound')->result_array();

    $out = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    foreach ($rows as $r) { $out[$r['status']] = (int) $r['c']; }

    return $out;
}
