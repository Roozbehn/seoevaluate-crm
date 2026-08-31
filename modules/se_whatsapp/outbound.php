<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * WhatsApp OUTBOUND layer.
 *
 * LIVE SENDING IS DISABLED. There is no live transport in this file: the only
 * transport is a registered callable, and with none registered every send is
 * GATED and queued without leaving the CRM. A live transport is a separate,
 * deliberate step the owner takes after Meta App Review.
 *
 * THE TWO RULES THAT MATTER
 * -------------------------
 * 1. Free-form text may only be sent INSIDE the 24-hour customer service
 *    window, which opens on each inbound message. Outside it, only an
 *    APPROVED template may be sent. Getting this wrong is not a bug that shows
 *    up in testing — Meta silently drops the message and the quality rating
 *    falls, so the rule is enforced here rather than trusted to the caller.
 * 2. Every outbound message carries a stable idempotency key. A retry after a
 *    timeout must not deliver the message twice; the platform cannot help us
 *    because our first attempt may have succeeded without us hearing.
 */

define('SE_WA_OUT_MAX_ATTEMPTS', 5);
define('SE_WA_OUT_LEASE_SECONDS', 900);
define('SE_WA_OUT_BACKOFF_BASE', 60);
define('SE_WA_OUT_BACKOFF_CAP', 7200);
define('SE_WA_OUT_MAX_TEXT', 4096);

/** Media types we accept a reference for, and their size ceilings (bytes). */
function se_wa_media_allowlist()
{
    return [
        'image'    => ['max' => 5 * 1024 * 1024,  'mime' => ['image/jpeg', 'image/png', 'image/webp']],
        'document' => ['max' => 100 * 1024 * 1024, 'mime' => ['application/pdf']],
        'audio'    => ['max' => 16 * 1024 * 1024, 'mime' => ['audio/ogg', 'audio/mpeg', 'audio/aac']],
        'video'    => ['max' => 16 * 1024 * 1024, 'mime' => ['video/mp4', 'video/3gpp']],
    ];
}

/* ---------------------------------------------------------------------------
 * Transport seam.
 * ------------------------------------------------------------------------- */

$GLOBALS['SE_WA_TRANSPORT'] = null;

/**
 * Register a transport: callable(array $message): array{ok,wamid,code,error}.
 * Tests register a fixture. Nothing registers a live one here.
 */
function se_wa_register_transport(callable $t)
{
    $GLOBALS['SE_WA_TRANSPORT'] = $t;
}

function se_wa_transport_available()
{
    return is_callable($GLOBALS['SE_WA_TRANSPORT'] ?? null);
}

/**
 * Why can this brand not send? Empty string when it can.
 *
 * The UI renders this verbatim, because "the composer is disabled" with no
 * reason is the single most confusing state an operator can be shown.
 */
function se_wa_send_blocked_reason($brand_id)
{
    if (!se_wa_can_send($brand_id)) {
        return 'no_number';
    }

    // The app secret may be INHERITED from meta_app (same Meta app) — check the
    // canonical accessor, not the raw wa_app file, or a working inherited
    // secret reads as 'no_credentials'.
    if (function_exists('se_wa_app_secret') ? se_wa_app_secret() === '' : !se_secret_configured('wa_app', 0)) {
        return 'no_credentials';
    }

    // Sending needs the Cloud API TOKEN (provider wa_token) — a different
    // credential from the app secret, which only validates webhook signatures.
    if (se_wa_cloud_token() === '') {
        return 'no_token';
    }

    if (!se_wa_transport_available()) {
        return 'no_transport';
    }

    return '';
}

/** The WhatsApp Cloud API access token (secret provider wa_token). Never logged. */
function se_wa_cloud_token()
{
    return function_exists('se_secret_read') ? se_secret_read('wa_token') : '';
}

/* ---------------------------------------------------------------------------
 * Composition rules.
 * ------------------------------------------------------------------------- */

/**
 * Decide what MAY be sent on a conversation right now.
 *
 * @return array{allowed:bool,mode:string,reason:string,window_open:bool,expires_at:?string}
 */
function se_wa_compose_policy($conversation)
{
    $open = se_wa_window_open($conversation);

    $blocked = se_wa_send_blocked_reason((int) $conversation->brand_id);

    if ($blocked !== '') {
        return ['allowed' => false, 'mode' => 'none', 'reason' => $blocked,
                'window_open' => $open, 'expires_at' => $conversation->window_expires_at ?? null];
    }

    if ($open) {
        return ['allowed' => true, 'mode' => 'freeform', 'reason' => '',
                'window_open' => true, 'expires_at' => $conversation->window_expires_at];
    }

    return ['allowed' => true, 'mode' => 'template', 'reason' => 'window_closed',
            'window_open' => false, 'expires_at' => $conversation->window_expires_at ?? null];
}

/** Approved templates a brand may send. Only APPROVED ones are offered. */
function se_wa_approved_templates($brand_id)
{
    $CI = &get_instance();

    $CI->db->where('brand_id', (int) $brand_id)
           ->where('approval_state', 'approved')
           ->order_by('name', 'ASC');

    return $CI->db->get(db_prefix() . 'se_wa_templates')->result_array();
}

/**
 * Stable idempotency key for one outbound message.
 *
 * Derived from immutable inputs, never from a timestamp: a retry must produce
 * the SAME key so the unique index rejects a second row for the same intent.
 */
function se_wa_idempotency_key($conversation_id, $kind, $payload_signature)
{
    return substr(hash('sha256', (int) $conversation_id . '|' . $kind . '|' . $payload_signature), 0, 64);
}

/* ---------------------------------------------------------------------------
 * Queueing.
 * ------------------------------------------------------------------------- */

/**
 * Queue an outbound message. Never sends inline.
 *
 * Enforces the window rule at QUEUE time and again at send time: the window can
 * close while a message sits in the queue, and sending free-form text after it
 * closes is exactly the mistake that costs a number its quality rating.
 *
 * @return array{ok:bool,id:int,reason:string}
 */
function se_wa_queue_message($conversation_id, array $message, $staff_id = 0)
{
    $CI = &get_instance();

    $CI->db->where('id', (int) $conversation_id);
    se_apply_scope_in('brand_id');
    $conv = $CI->db->get(db_prefix() . 'se_wa_conversations')->row();

    if (!$conv) {
        return ['ok' => false, 'id' => 0, 'reason' => 'not_found'];
    }

    $policy = se_wa_compose_policy($conv);

    if (!$policy['allowed']) {
        return ['ok' => false, 'id' => 0, 'reason' => $policy['reason']];
    }

    $kind = $message['kind'] ?? 'text';

    if ($kind === 'text') {
        if ($policy['mode'] !== 'freeform') {
            return ['ok' => false, 'id' => 0, 'reason' => 'window_closed'];
        }

        $body = trim((string) ($message['body'] ?? ''));

        if ($body === '') {
            return ['ok' => false, 'id' => 0, 'reason' => 'empty_body'];
        }

        $body      = mb_substr($body, 0, SE_WA_OUT_MAX_TEXT);
        $signature = hash('sha256', $body);
        $template  = null;
    } elseif ($kind === 'template') {
        $name = (string) ($message['template'] ?? '');

        // Only an APPROVED template for THIS brand may be queued.
        $approved = false;

        foreach (se_wa_approved_templates((int) $conv->brand_id) as $t) {
            if ($t['name'] === $name) { $approved = true; break; }
        }

        if (!$approved) {
            return ['ok' => false, 'id' => 0, 'reason' => 'template_not_approved'];
        }

        $body      = null;
        $template  = $name;
        $signature = hash('sha256', $name . '|' . json_encode($message['variables'] ?? []));
    } else {
        return ['ok' => false, 'id' => 0, 'reason' => 'unsupported_kind'];
    }

    $key = se_wa_idempotency_key($conversation_id, $kind, $signature);

    // Idempotent: the same intent queued twice is one row.
    $CI->db->where('idempotency_key', $key);

    if ($CI->db->count_all_results(db_prefix() . 'se_wa_outbound') > 0) {
        return ['ok' => false, 'id' => 0, 'reason' => 'duplicate'];
    }

    try {
        $CI->db->insert(db_prefix() . 'se_wa_outbound', [
            'conversation_id' => (int) $conv->id,
            'brand_id'        => (int) $conv->brand_id,
            'kind'            => $kind,
            'body'            => $body,
            'template_name'   => $template,
            'variables_json'  => isset($message['variables']) ? json_encode($message['variables']) : null,
            'idempotency_key' => $key,
            'status'          => 'pending',
            'attempts'        => 0,
            'fence'           => 0,
            'created_by'      => (int) $staff_id,
            'date_created'    => se_db_now(),
            'next_attempt_at' => se_db_now(),
        ]);
    } catch (Exception $e) {
        // The unique index is the real guard; the pre-check only narrows it.
        return ['ok' => false, 'id' => 0, 'reason' => 'duplicate'];
    }

    return ['ok' => true, 'id' => (int) $CI->db->insert_id(), 'reason' => ''];
}

/* ---------------------------------------------------------------------------
 * Draining.
 * ------------------------------------------------------------------------- */

function se_wa_out_backoff_seconds($attempts)
{
    $exp = SE_WA_OUT_BACKOFF_BASE * (2 ** max(0, (int) $attempts - 1));

    return random_int((int) ($exp / 2), (int) min($exp, SE_WA_OUT_BACKOFF_CAP));
}

function se_wa_out_recover_stale()
{
    $CI = &get_instance();

    $CI->db->where('status', 'processing')
           ->where('locked_at <', se_db_now(-SE_WA_OUT_LEASE_SECONDS))
           ->update(db_prefix() . 'se_wa_outbound', [
               'status' => 'pending', 'locked_at' => null, 'locked_by' => null,
           ]);

    return (int) $CI->db->affected_rows();
}

/** Atomically claim due outbound messages, bumping the fence. */
function se_wa_out_claim_batch($worker, $limit = 50)
{
    $CI    = &get_instance();
    $table = db_prefix() . 'se_wa_outbound';
    $now   = se_db_now();

    $CI->db->query(
        'UPDATE `' . $table . "` SET status='processing', locked_at=NOW()"
        . ', locked_by=' . $CI->db->escape($worker)
        . ', fence = fence + 1'
        . " WHERE status='pending' AND attempts < " . (int) SE_WA_OUT_MAX_ATTEMPTS
        . ' AND (next_attempt_at IS NULL OR next_attempt_at <= ' . $CI->db->escape($now) . ')'
        . ' ORDER BY id ASC LIMIT ' . max(1, (int) $limit)
    );

    $CI->db->where('status', 'processing')->where('locked_by', $worker)->order_by('id', 'ASC');

    return $CI->db->get($table)->result_array();
}

/**
 * Drain the outbound queue.
 *
 * With no transport registered every row is held as GATED without consuming an
 * attempt, so the queue survives however long App Review takes.
 */
function se_wa_out_drain($limit = 50)
{
    $limit = (int) $limit; if ($limit < 1) { $limit = 50; }

    $CI    = &get_instance();
    $table = db_prefix() . 'se_wa_outbound';

    se_wa_out_recover_stale();

    $worker = substr(md5(uniqid((string) getmypid(), true)), 0, 24);
    $rows   = se_wa_out_claim_batch($worker, $limit);

    foreach ($rows as $row) {
        $update = se_wa_out_process($row);

        $update['locked_at'] = null;
        $update['locked_by'] = null;

        // Fenced against a worker whose lease expired mid-flight.
        $CI->db->where('id', $row['id'])
               ->where('locked_by', $worker)
               ->where('fence', (int) $row['fence'])
               ->update($table, $update);
    }

    return count($rows);
}

/** Decide the outcome for one claimed outbound row. */
function se_wa_out_process($row)
{
    $CI = &get_instance();

    $CI->db->where('id', (int) $row['conversation_id']);
    $conv = $CI->db->get(db_prefix() . 'se_wa_conversations')->row();

    if (!$conv) {
        return ['status' => 'failed', 'attempts' => (int) $row['attempts'] + 1,
                'failure_class' => 'permanent', 'last_error' => 'conversation gone'];
    }

    /* GATE FIRST, then the window.
     *
     * Order matters. Asking the composite policy whether we may send conflates
     * "the window closed" with "sending is not configured", and a gated row
     * would then be permanently SKIPPED as if the customer had gone quiet —
     * discarding a message that was only waiting for configuration. Check the
     * gate first and hold; only then judge the window. */
    $blockedReason = se_wa_send_blocked_reason((int) $conv->brand_id);
    if (!se_wa_transport_available() || $blockedReason !== '') {
        // Name the EXACT gate — a generic 'not configured' hides which
        // credential or component is actually missing.
        $why = $blockedReason !== '' ? $blockedReason : 'no_transport';
        return ['status' => 'pending', 'attempts' => (int) $row['attempts'],
                'failure_class' => 'gated', 'last_error' => 'sending gated: ' . $why,
                'next_attempt_at' => se_db_now(3600)];
    }

    /* Re-check the WINDOW at send time: it may have closed while queued, and
     * free-form text outside it is silently dropped by Meta. */
    if ($row['kind'] === 'text' && !se_wa_window_open($conv)) {
        return ['status' => 'skipped', 'attempts' => (int) $row['attempts'],
                'failure_class' => 'permanent', 'last_error' => 'service window closed before send'];
    }

    try {
        $result = call_user_func($GLOBALS['SE_WA_TRANSPORT'], [
            'phone_number_id' => $conv->phone_number_id,
            'to'              => $conv->wa_user_id,
            'kind'            => $row['kind'],
            'body'            => $row['body'],
            'template'        => $row['template_name'],
            'variables'       => json_decode((string) $row['variables_json'], true) ?: [],
            'idempotency_key' => $row['idempotency_key'],
        ]);
    } catch (Exception $e) {
        $attempts = (int) $row['attempts'] + 1;

        return ['status' => $attempts >= SE_WA_OUT_MAX_ATTEMPTS ? 'failed' : 'pending',
                'attempts' => $attempts, 'failure_class' => 'retryable',
                'last_error' => 'transport error',
                'next_attempt_at' => se_db_now(se_wa_out_backoff_seconds($attempts))];
    }

    if (!empty($result['ok'])) {
        // Record the sent message in the conversation thread.
        se_wa_record_outbound($row, $conv, (string) ($result['wamid'] ?? ''));

        return ['status' => 'sent', 'attempts' => (int) $row['attempts'] + 1,
                'wamid' => (string) ($result['wamid'] ?? ''), 'sent_at' => se_db_now(),
                'failure_class' => null, 'last_error' => null];
    }

    $code     = (int) ($result['code'] ?? 0);
    $attempts = (int) $row['attempts'] + 1;

    // 4xx other than throttling is our problem and will not fix itself.
    $permanent = $code >= 400 && $code < 500 && !in_array($code, [408, 429], true);

    return [
        'status'          => $permanent || $attempts >= SE_WA_OUT_MAX_ATTEMPTS ? 'failed' : 'pending',
        'attempts'        => $attempts,
        'failure_class'   => $permanent ? 'permanent' : 'retryable',
        'last_error'      => mb_substr(preg_replace('/[A-Za-z0-9_\-]{24,}/', '[redacted]',
                                (string) ($result['error'] ?? 'send failed')), 0, 255),
        'next_attempt_at' => se_db_now(se_wa_out_backoff_seconds($attempts)),
    ];
}

/** Mirror a delivered outbound message into the conversation thread. */
function se_wa_record_outbound($row, $conv, $wamid)
{
    $CI = &get_instance();

    if ($wamid === '') {
        return;
    }

    $CI->db->where('wamid', $wamid)->where('brand_id', (int) $conv->brand_id);

    if ($CI->db->count_all_results(db_prefix() . 'se_wa_messages') > 0) {
        return;   // already mirrored
    }

    $CI->db->insert(db_prefix() . 'se_wa_messages', [
        'conversation_id' => (int) $conv->id,
        'brand_id'        => (int) $conv->brand_id,
        'wamid'           => $wamid,
        'direction'       => 'out',
        'type'            => $row['kind'] === 'template' ? 'template' : 'text',
        'body'            => $row['body'],
        'template_name'   => $row['template_name'],
        'delivery_state'  => 'sent',
        'sent_at'         => se_db_now(),
        'date_created'    => se_db_now(),
    ]);
}

/* ---------------------------------------------------------------------------
 * Reminder consumption.
 * ------------------------------------------------------------------------- */

/**
 * Turn due appointment reminders into queued template messages.
 *
 * Claimed and idempotent: a reminder is marked queued BEFORE the message is
 * created, and the outbound idempotency key makes a repeat a no-op, so a crash
 * between the two cannot produce two reminders for one appointment.
 */
function se_wa_consume_due_reminders($limit = 50)
{
    $limit = (int) $limit; if ($limit < 1) { $limit = 50; }

    $CI    = &get_instance();
    $table = db_prefix() . 'se_reminders';

    $CI->db->where('state', 'pending')
           ->where('channel', 'whatsapp')
           ->where('scheduled_at <=', se_db_now())
           ->order_by('id', 'ASC')->limit($limit);

    $due = $CI->db->get($table)->result_array();
    $queued = 0;

    foreach ($due as $rem) {
        $CI->db->where('id', (int) $rem['id']);
        $appt = $CI->db->get(db_prefix() . 'se_appointments')->row();

        // Find the conversation for this appointment's lead, in the same brand.
        $CI->db->where('brand_id', (int) $rem['brand_id'])
               ->where('lead_id', $appt ? (int) $appt->rel_id : 0)
               ->limit(1);
        $conv = $CI->db->get(db_prefix() . 'se_wa_conversations')->row();

        if (!$conv) {
            $CI->db->where('id', (int) $rem['id'])->update($table, [
                'state' => 'skipped', 'last_error' => 'no conversation for this lead',
            ]);
            continue;
        }

        // Mark first: a crash after this leaves a queued reminder, not a second one.
        $CI->db->where('id', (int) $rem['id'])->where('state', 'pending')
               ->update($table, ['state' => 'queued', 'attempts' => (int) $rem['attempts'] + 1]);

        if ($CI->db->affected_rows() < 1) {
            continue;   // another worker took it
        }

        $res = se_wa_queue_message((int) $conv->id, [
            'kind'     => 'template',
            'template' => (string) $rem['template_ref'],
        ]);

        if ($res['ok'] || $res['reason'] === 'duplicate') {
            $queued++;
        } else {
            $CI->db->where('id', (int) $rem['id'])->update($table, [
                'state' => 'failed', 'last_error' => mb_substr($res['reason'], 0, 255),
                'failed_at' => se_db_now(),
            ]);
        }
    }

    return $queued;
}

/** Outbound queue health for the readiness screen. */
function se_wa_out_health($brand_id = 0)
{
    $CI = &get_instance();

    $CI->db->select('status, COUNT(*) AS c')->group_by('status');
    se_apply_scope_in('brand_id');

    if ((int) $brand_id > 0 && se_can_access_brand($brand_id)) {
        $CI->db->where('brand_id', (int) $brand_id);
    }

    $rows = $CI->db->get(db_prefix() . 'se_wa_outbound')->result_array();

    $out = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

    foreach ($rows as $r) { $out[$r['status']] = (int) $r['c']; }

    return $out;
}
