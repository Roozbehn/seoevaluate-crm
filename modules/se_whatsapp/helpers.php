<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * WhatsApp Cloud API — processing core (no UI, no direct network here).
 *
 * The webhook controller does the bare minimum in-request: verify the signature
 * over the RAW body, store the event durably (deduplicated), and 200 fast. All
 * parsing/routing/persistence happens later in se_wa_process_pending(), so a slow
 * consumer never delays the acknowledgement Meta requires (<250ms).
 *
 * No token or app secret is ever written to a table or a log. Secrets live in
 * options; a number row only references the option key.
 */

define('SE_WA_WINDOW_HOURS', 24);

/* Bounds. Meta's own payloads are small; anything far larger is either a bug or
 * an attempt to fill the table, and an unbounded LONGTEXT insert per webhook is
 * a cheap way to exhaust the account's disk quota. */
define('SE_WA_MAX_BODY_BYTES', 131072);      // 128 KB raw webhook body
define('SE_WA_MAX_TEXT_LEN', 4096);          // WhatsApp text messages cap at 4096
define('SE_WA_MAX_ID_LEN', 191);
define('SE_WA_MAX_REFERRAL_BYTES', 8192);
define('SE_WA_EVENT_RETENTION_DAYS', 30);    // raw payload retention
define('SE_WA_LEASE_SECONDS', 900);
define('SE_WA_MAX_ATTEMPTS', 5);
define('SE_WA_BACKOFF_BASE', 300);
define('SE_WA_BACKOFF_CAP', 21600);

/* --------------------------- signature + storage ------------------------- */

function se_wa_app_secret()
{
    return (string) get_option('se_wa_app_secret'); // never logged
}

/**
 * Verify Meta's X-Hub-Signature-256 over the exact raw body. Constant-time.
 */
function se_wa_verify_signature($raw_body, $header, $app_secret = null)
{
    $secret = $app_secret !== null ? $app_secret : se_wa_app_secret();
    if ($secret === '' || !is_string($header) || strpos($header, 'sha256=') !== 0) {
        return false;
    }
    $expected = 'sha256=' . hash_hmac('sha256', $raw_body, $secret);

    return hash_equals($expected, $header);
}

/** Pull the routing ids out of a decoded webhook payload (first entry/change). */
function se_wa_extract_routing($payload)
{
    $waba = $payload['entry'][0]['id'] ?? null;
    $value = $payload['entry'][0]['changes'][0]['value'] ?? [];
    $pnid = $value['metadata']['phone_number_id'] ?? null;

    return ['waba_id' => $waba, 'phone_number_id' => $pnid];
}

/**
 * Store a webhook event durably, deduplicated on a hash of the raw body.
 * Returns ['stored'=>bool,'duplicate'=>bool]. Never throws into the request.
 */
function se_wa_store_event($raw_body, $signature_valid)
{
    $CI = &get_instance();
    $table = db_prefix() . 'se_wa_webhook_events';

    // Bounded: refuse an oversized body rather than writing it to the table.
    if (strlen((string) $raw_body) > SE_WA_MAX_BODY_BYTES) {
        return ['stored' => false, 'duplicate' => false, 'oversize' => true];
    }

    $hash = hash('sha256', $raw_body);

    $CI->db->where('event_hash', $hash);
    if ($CI->db->count_all_results($table) > 0) {
        return ['stored' => false, 'duplicate' => true];
    }

    $decoded = json_decode($raw_body, true) ?: [];
    $routing = se_wa_extract_routing($decoded);

    // The check above narrows the race; the unique key on event_hash closes it.
    // A duplicate insert is an expected outcome of concurrent redelivery, not an
    // error, so it is caught and reported as a duplicate.
    try {
        $CI->db->insert($table, [
            'event_hash'      => $hash,
            'phone_number_id' => mb_substr((string) $routing['phone_number_id'], 0, SE_WA_MAX_ID_LEN),
            'waba_id'         => mb_substr((string) $routing['waba_id'], 0, SE_WA_MAX_ID_LEN),
            'payload'         => $raw_body,
            'signature_valid' => $signature_valid ? 1 : 0,
            'state'           => 'pending',
            'next_attempt_at' => se_db_now(),
            'received_at'     => se_db_now(),
        ]);
    } catch (Exception $e) {
        return ['stored' => false, 'duplicate' => true];
    }

    return ['stored' => true, 'duplicate' => false];
}

/** Exponential backoff with full jitter for webhook reprocessing. */
function se_wa_backoff_seconds($attempts)
{
    $exp = SE_WA_BACKOFF_BASE * (2 ** max(0, (int) $attempts - 1));
    $exp = min($exp, SE_WA_BACKOFF_CAP);

    return random_int((int) ($exp / 2), (int) $exp);
}

/**
 * Purge the raw payload of processed events past the retention window.
 *
 * The row (and its hash, which is what dedup needs) is kept; only the message
 * bodies, referral data and media references inside the stored payload are
 * dropped. Keeping every raw webhook body forever is an unnecessary copy of
 * message content with no retention story.
 */
function se_wa_purge_old_payloads()
{
    $CI = &get_instance();
    $cutoff = se_db_now(-SE_WA_EVENT_RETENTION_DAYS * 86400);

    $CI->db->where('state', 'processed')
           ->where('received_at <', $cutoff)
           ->where('payload IS NOT NULL', null, false)
           ->update(db_prefix() . 'se_wa_webhook_events', ['payload' => null]);

    return (int) $CI->db->affected_rows();
}

/** Return webhook events whose processing lease has expired. */
function se_wa_recover_stale()
{
    $CI = &get_instance();
    $cutoff = se_db_now(-SE_WA_LEASE_SECONDS);

    $CI->db->where('state', 'processing')
           ->where('locked_at <', $cutoff)
           ->update(db_prefix() . 'se_wa_webhook_events', [
               'state'     => 'pending',
               'locked_at' => null,
               'locked_by' => null,
           ]);

    return (int) $CI->db->affected_rows();
}

/**
 * Atomically claim webhook events that are DUE.
 *
 * The old drainer SELECTed pending rows and processed them with no claim at
 * all, so two overlapping cron runs both processed the same event.
 */
function se_wa_claim_batch($worker, $limit = 100)
{
    $CI = &get_instance();
    $table = db_prefix() . 'se_wa_webhook_events';
    $limit = max(1, (int) $limit);
    $now   = se_db_now();

    $CI->db->query(
        'UPDATE `' . $table . "` SET state='processing', locked_at=NOW()"
        . ', locked_by=' . $CI->db->escape($worker)
        . ', fence = fence + 1'
        . " WHERE state='pending' AND signature_valid=1 AND attempts < " . (int) SE_WA_MAX_ATTEMPTS
        . ' AND (next_attempt_at IS NULL OR next_attempt_at <= ' . $CI->db->escape($now) . ')'
        . ' ORDER BY id ASC LIMIT ' . $limit
    );

    $CI->db->where('state', 'processing')->where('locked_by', $worker)->order_by('id', 'ASC');

    return $CI->db->get($table)->result_array();
}

/* ------------------------------- processing ------------------------------ */

/** Map a phone_number_id to its brand via the numbers table. Null if unknown. */
function se_wa_route_to_brand($phone_number_id)
{
    if (!$phone_number_id) {
        return null;
    }
    $CI = &get_instance();
    $CI->db->where('phone_number_id', $phone_number_id);
    $row = $CI->db->get(db_prefix() . 'se_wa_numbers')->row();

    return $row ? (int) $row->brand_id : null;
}

/**
 * Drain webhook events. Bounded, claimed, retried with backoff.
 *
 * A permanent routing failure (unknown phone_number_id) is parked immediately
 * rather than retried five times: no amount of waiting maps an unknown number.
 */
function se_wa_process_pending($limit = 100)
{
    // after_cron_run passes a bool ($manually) as the first arg; ignore non-positive limits.
    $limit = (int) $limit; if ($limit < 1) { $limit = 100; }

    $CI = &get_instance();
    $table = db_prefix() . 'se_wa_webhook_events';

    se_wa_recover_stale();

    $worker = substr(md5(uniqid((string) getmypid(), true)), 0, 24);
    $events = se_wa_claim_batch($worker, $limit);

    foreach ($events as $ev) {
        $error     = '';
        $permanent = false;

        try {
            se_wa_process_event($ev);
        } catch (SeWaPermanentError $e) {
            $error     = 'routing failure';
            $permanent = true;
        } catch (Exception $e) {
            $error = 'processing error';
        }

        $attempts = (int) $ev['attempts'] + 1;

        if ($error === '') {
            $update = ['state' => 'processed', 'attempts' => $attempts, 'last_error' => null,
                       'processed_at' => date('Y-m-d H:i:s'), 'locked_at' => null, 'locked_by' => null];
        } elseif ($permanent || $attempts >= SE_WA_MAX_ATTEMPTS) {
            $update = ['state' => 'failed', 'attempts' => $attempts, 'last_error' => $error,
                       'processed_at' => date('Y-m-d H:i:s'), 'locked_at' => null, 'locked_by' => null];
        } else {
            $update = ['state' => 'pending', 'attempts' => $attempts, 'last_error' => $error,
                       'next_attempt_at' => se_db_now(se_wa_backoff_seconds($attempts)),
                       'locked_at' => null, 'locked_by' => null];
        }

        // Fenced: a worker whose lease expired cannot overwrite a newer result.
        $CI->db->where('id', $ev['id'])
               ->where('locked_by', $worker)
               ->where('fence', (int) $ev['fence'])
               ->update($table, $update);
    }

    se_wa_purge_old_payloads();

    return count($events);
}

/** A failure that retrying cannot fix. */
class SeWaPermanentError extends Exception {}

function se_wa_process_event($ev)
{
    $payload = json_decode($ev['payload'], true) ?: [];
    $routing = se_wa_extract_routing($payload);
    $brand_id = se_wa_route_to_brand($routing['phone_number_id']);
    if ($brand_id === null) {
        // No mapping will appear by waiting; park it for an operator.
        throw new SeWaPermanentError('unknown phone_number_id');
    }

    $value = $payload['entry'][0]['changes'][0]['value'] ?? [];

    foreach (($value['messages'] ?? []) as $msg) {
        se_wa_handle_inbound($brand_id, $routing['phone_number_id'], $msg, $value['contacts'][0] ?? []);
    }
    foreach (($value['statuses'] ?? []) as $st) {
        se_wa_handle_status($brand_id, $st);
    }
}

/** Upsert conversation + message for one inbound message. Deduplicated on wamid. */
function se_wa_handle_inbound($brand_id, $phone_number_id, $msg, $contact)
{
    $CI = &get_instance();
    $wamid = $msg['id'] ?? '';
    $from  = $msg['from'] ?? '';
    if ($wamid === '' || $from === '') {
        return;
    }

    $wamid = mb_substr($wamid, 0, SE_WA_MAX_ID_LEN);
    $from  = mb_substr($from, 0, SE_WA_MAX_ID_LEN);

    // Conversation (dedup by number+user).
    $convTable = db_prefix() . 'se_wa_conversations';
    $CI->db->where('phone_number_id', $phone_number_id)->where('wa_user_id', $from);
    $conv = $CI->db->get($convTable)->row();

    // An existing conversation's brand must still match the brand the routed
    // number maps to. If a number is re-pointed at a different brand, silently
    // continuing to append another tenant's messages to the old thread would
    // merge two tenants' conversation history.
    if ($conv && (int) $conv->brand_id !== (int) $brand_id) {
        throw new SeWaPermanentError('conversation brand mismatch');
    }

    $ts = isset($msg['timestamp']) ? date('Y-m-d H:i:s', (int) $msg['timestamp']) : date('Y-m-d H:i:s');
    $window = date('Y-m-d H:i:s', strtotime($ts) + SE_WA_WINDOW_HOURS * 3600);

    if (!$conv) {
        $CI->db->insert($convTable, [
            'brand_id'          => (int) $brand_id,
            'phone_number_id'   => $phone_number_id,
            'wa_user_id'        => $from,
            'last_inbound_at'   => $ts,
            'window_expires_at' => $window,
            'unread_count'      => 1,
            // ctwa_clid captured on the FIRST inbound only.
            'ctwa_clid'         => isset($msg['referral']['ctwa_clid'])
                ? mb_substr((string) $msg['referral']['ctwa_clid'], 0, SE_WA_MAX_ID_LEN) : null,
            'referral_json'     => isset($msg['referral'])
                ? mb_substr((string) json_encode($msg['referral']), 0, SE_WA_MAX_REFERRAL_BYTES) : null,
            'state'             => 'open',
            'date_created'      => date('Y-m-d H:i:s'),
        ]);
        $conv_id = (int) $CI->db->insert_id();
    } else {
        $conv_id = (int) $conv->id;
        $CI->db->where('id', $conv_id)->update($convTable, [
            'last_inbound_at'   => $ts,
            'window_expires_at' => $window,
            'unread_count'      => (int) $conv->unread_count + 1,
            'last_updated'      => date('Y-m-d H:i:s'),
        ]);
    }

    // Message (dedup by wamid via unique key + guard).
    $msgTable = db_prefix() . 'se_wa_messages';
    $CI->db->where('wamid', $wamid);
    if ($CI->db->count_all_results($msgTable) > 0) {
        return; // duplicate delivery
    }

    $type = mb_substr((string) ($msg['type'] ?? 'text'), 0, 32);
    $body = $type === 'text' ? mb_substr((string) ($msg['text']['body'] ?? ''), 0, SE_WA_MAX_TEXT_LEN) : null;
    $media_ref = null;
    if (in_array($type, ['image', 'document', 'audio', 'video'], true) && isset($msg[$type]['id'])) {
        $media_ref = 'media:' . $msg[$type]['id']; // controlled download happens later, post-validation
    }

    $CI->db->insert($msgTable, [
        'conversation_id' => $conv_id,
        'brand_id'        => (int) $brand_id,
        'wamid'           => $wamid,
        'direction'       => 'in',
        'type'            => $type,
        'body'            => $body,
        'media_ref'       => $media_ref,
        'received_at'     => $ts,
        'date_created'    => date('Y-m-d H:i:s'),
    ]);

    // Meter the inbound (service category) once per wamid.
    se_wa_meter((int) $brand_id, 'service', false, 'in:' . $wamid);
}

/** Apply a delivery status update, ignoring out-of-order regressions. */
function se_wa_handle_status($brand_id, $status)
{
    $CI = &get_instance();
    $wamid = $status['id'] ?? '';
    $state = $status['status'] ?? '';
    if ($wamid === '' || $state === '') {
        return;
    }

    $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3];
    $msgTable = db_prefix() . 'se_wa_messages';

    // The routed brand is part of BOTH the lookup and the update. A wamid is
    // supplied by the webhook body, and without the brand a status callback
    // routed to Brand A could read and overwrite Brand B's message row.
    $CI->db->where('wamid', $wamid)->where('brand_id', (int) $brand_id);
    $msg = $CI->db->get($msgTable)->row();
    if (!$msg) {
        return;
    }

    if ($state === 'failed') {
        $CI->db->where('id', $msg->id)->where('brand_id', (int) $brand_id)
               ->update($msgTable, ['delivery_state' => 'failed']);
        return;
    }

    // Out-of-order callbacks: only ever move the state forward.
    $current = $rank[$msg->delivery_state] ?? 0;
    $incoming = $rank[$state] ?? 0;
    if ($incoming > $current) {
        $update = ['delivery_state' => $state];
        // Meter conversation pricing on the first billable status if present.
        if (isset($status['pricing'])) {
            $update['pricing_category'] = $status['pricing']['category'] ?? null;
            $update['billable'] = !empty($status['pricing']['billable']) ? 1 : 0;
            se_wa_meter((int) $brand_id, $status['pricing']['category'] ?? 'unknown', !empty($status['pricing']['billable']), 'px:' . $wamid);
        }
        $CI->db->where('id', $msg->id)->where('brand_id', (int) $brand_id)->update($msgTable, $update);
    }
}

/* ------------------------------- metering -------------------------------- */

/** Configurable per-category rate (never hardcoded as permanent logic). */
function se_wa_rate($category)
{
    $rates = json_decode((string) get_option('se_wa_rates_json'), true) ?: [];
    return isset($rates[$category]) ? (float) $rates[$category] : 0.0;
}

/** Record a metered unit, deduplicated. Returns row id or 0 if duplicate. */
function se_wa_meter($brand_id, $category, $billable, $dedup_ref)
{
    $CI = &get_instance();
    $table = db_prefix() . 'se_wa_metering';

    $CI->db->where('dedup_ref', $dedup_ref);
    if ($CI->db->count_all_results($table) > 0) {
        return 0;
    }

    $CI->db->insert($table, [
        'brand_id'     => (int) $brand_id,
        'category'     => mb_substr((string) $category, 0, 24),
        'billable'     => $billable ? 1 : 0,
        'quantity'     => 1,
        'meter_date'   => date('Y-m-d'),
        'dedup_ref'    => mb_substr((string) $dedup_ref, 0, 191),
        'date_created' => date('Y-m-d H:i:s'),
    ]);

    return (int) $CI->db->insert_id();
}

/* --------------------------- reply window / send ------------------------- */

/** Is the 24h free-form reply window still open for a conversation row? */
function se_wa_window_open($conversation)
{
    if (!$conversation || empty($conversation->window_expires_at)) {
        return false;
    }
    return strtotime($conversation->window_expires_at) > time();
}

/** Can this brand actually send (has a configured, tokened number)? */
function se_wa_can_send($brand_id)
{
    $CI = &get_instance();
    $CI->db->where('brand_id', (int) $brand_id)->where('state', 'active')->where('token_option_ref IS NOT NULL', null, false);
    $n = $CI->db->count_all_results(db_prefix() . 'se_wa_numbers');
    if ($n === 0) {
        return false;
    }
    return true; // real send still gated on a valid token in the referenced option
}

/**
 * Consume due appointment reminders from the Phase 2 queue. When no brand can
 * send (the current state until Meta onboarding), reminders are left pending —
 * nothing is transmitted. This is the seam the reminder interface was built for.
 */
function se_wa_consume_due_reminders($limit = 100)
{
    // after_cron_run passes a bool ($manually) as the first arg; ignore non-positive limits.
    $limit = (int) $limit; if ($limit < 1) { $limit = 100; }
    $CI = &get_instance();
    $table = db_prefix() . 'se_reminders';
    if (!$CI->db->table_exists($table)) {
        return 0;
    }

    $CI->db->where('state', 'pending')->where('scheduled_at <=', date('Y-m-d H:i:s'))
           ->order_by('id', 'ASC')->limit((int) $limit);
    $due = $CI->db->get($table)->result_array();

    $held = 0;
    foreach ($due as $r) {
        if (!se_wa_can_send((int) $r['brand_id'])) {
            $held++;   // gated: leave pending, transmit nothing
            continue;
        }
        // A live sender lands here once Meta onboarding completes (externally gated).
    }

    return $held;
}
