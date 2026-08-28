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

    $hash = hash('sha256', $raw_body);

    $CI->db->where('event_hash', $hash);
    if ($CI->db->count_all_results($table) > 0) {
        return ['stored' => false, 'duplicate' => true];
    }

    $decoded = json_decode($raw_body, true) ?: [];
    $routing = se_wa_extract_routing($decoded);

    $CI->db->insert($table, [
        'event_hash'      => $hash,
        'phone_number_id' => $routing['phone_number_id'],
        'waba_id'         => $routing['waba_id'],
        'payload'         => $raw_body,
        'signature_valid' => $signature_valid ? 1 : 0,
        'state'           => 'pending',
        'received_at'     => date('Y-m-d H:i:s'),
    ]);

    return ['stored' => true, 'duplicate' => false];
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

/** Drain pending webhook events. Bounded; safe to call from cron. */
function se_wa_process_pending($limit = 100)
{
    // after_cron_run passes a bool ($manually) as the first arg; ignore non-positive limits.
    $limit = (int) $limit; if ($limit < 1) { $limit = 100; }
    $CI = &get_instance();
    $table = db_prefix() . 'se_wa_webhook_events';

    $CI->db->where('state', 'pending')->where('signature_valid', 1)
           ->order_by('id', 'ASC')->limit((int) $limit);
    $events = $CI->db->get($table)->result_array();

    foreach ($events as $ev) {
        $error = '';
        try {
            se_wa_process_event($ev);
        } catch (Exception $e) {
            $error = 'processing error';
        }
        $CI->db->where('id', $ev['id'])->update($table, [
            'state'        => $error ? 'failed' : 'processed',
            'attempts'     => (int) $ev['attempts'] + 1,
            'last_error'   => $error ?: null,
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    return count($events);
}

function se_wa_process_event($ev)
{
    $payload = json_decode($ev['payload'], true) ?: [];
    $routing = se_wa_extract_routing($payload);
    $brand_id = se_wa_route_to_brand($routing['phone_number_id']);
    if ($brand_id === null) {
        throw new Exception('unknown phone_number_id'); // parked as failed, not applied
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

    // Conversation (dedup by number+user).
    $convTable = db_prefix() . 'se_wa_conversations';
    $CI->db->where('phone_number_id', $phone_number_id)->where('wa_user_id', $from);
    $conv = $CI->db->get($convTable)->row();

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
            'ctwa_clid'         => $msg['referral']['ctwa_clid'] ?? null,
            'referral_json'     => isset($msg['referral']) ? json_encode($msg['referral']) : null,
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

    $type = $msg['type'] ?? 'text';
    $body = $type === 'text' ? ($msg['text']['body'] ?? '') : null;
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
    $CI->db->where('wamid', $wamid);
    $msg = $CI->db->get($msgTable)->row();
    if (!$msg) {
        return;
    }

    if ($state === 'failed') {
        $CI->db->where('id', $msg->id)->update($msgTable, ['delivery_state' => 'failed']);
        return;
    }

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
        $CI->db->where('id', $msg->id)->update($msgTable, $update);
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
