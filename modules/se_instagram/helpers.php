<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Instagram Messaging — processing core (no UI, no direct network here).
 *
 * Mirrors the WhatsApp module's contract: the webhook controller verifies the
 * X-Hub-Signature-256 over the RAW body, stores the event durably (dedup on a
 * body hash) and 200s fast. Parsing, routing and persistence happen later in
 * se_ig_process_pending() (cron), so a slow consumer never delays the ack.
 *
 * Instagram Messaging webhooks (object "instagram") deliver
 *   entry[].id                 = the IG professional account id (routing key)
 *   entry[].messaging[]        = events: {sender{id}, recipient{id}, timestamp(ms),
 *                                message{mid,text,is_echo,attachments[],is_deleted},
 *                                read{mid}, postback{mid,title,payload},
 *                                referral{source:"ADS", type, ad_id, ref, ads_context_data}}
 *
 * AD ATTRIBUTION: a conversation that starts from an ad carries `referral`
 * (ad_id + ads_context_data). It is captured on the FIRST inbound only — the
 * Instagram counterpart of WhatsApp's ctwa_clid — and never overwritten.
 *
 * No token or app secret is ever written to a table or a log.
 */

define('SE_IG_WINDOW_HOURS', 24);
define('SE_IG_MAX_BODY_BYTES', 131072);
define('SE_IG_MAX_TEXT_LEN', 4096);
define('SE_IG_MAX_ID_LEN', 191);
define('SE_IG_MAX_REFERRAL_BYTES', 8192);
define('SE_IG_EVENT_RETENTION_DAYS', 30);
define('SE_IG_LEASE_SECONDS', 900);
define('SE_IG_MAX_ATTEMPTS', 5);
define('SE_IG_BACKOFF_BASE', 300);
define('SE_IG_BACKOFF_CAP', 21600);

/* ------------------------------ schema ---------------------------------- */

/** Idempotent DDL, shared by install.php and se_core/migrations.php. */
function se_ig_schema_statements($p)
{
    $cs = 'utf8mb4';
    $s  = [];

    /* 1) Accounts — one Instagram professional account per brand. */
    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_ig_accounts` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `ig_account_id` varchar(32) NOT NULL,
        `page_id` varchar(32) DEFAULT NULL,
        `username` varchar(64) DEFAULT NULL,
        `token_option_ref` varchar(64) DEFAULT NULL,
        `state` varchar(16) NOT NULL DEFAULT 'configured',
        `date_created` datetime NOT NULL,
        `last_updated` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ig_account_id` (`ig_account_id`),
        KEY `brand_id` (`brand_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    /* 2) Conversations — one per (account, Instagram-scoped user id). */
    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_ig_conversations` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `ig_account_id` varchar(32) NOT NULL,
        `igsid` varchar(64) NOT NULL,
        `lead_id` int(11) NOT NULL DEFAULT 0,
        `assigned_staff` int(11) NOT NULL DEFAULT 0,
        `unread_count` int(11) NOT NULL DEFAULT 0,
        `last_inbound_at` datetime DEFAULT NULL,
        `window_expires_at` datetime DEFAULT NULL,
        `referral_ad_id` varchar(64) DEFAULT NULL,
        `referral_source` varchar(32) DEFAULT NULL,
        `referral_json` text DEFAULT NULL,
        `state` varchar(16) NOT NULL DEFAULT 'open',
        `date_created` datetime NOT NULL,
        `last_updated` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `account_user` (`ig_account_id`,`igsid`),
        KEY `brand_id` (`brand_id`),
        KEY `assigned_staff` (`assigned_staff`),
        KEY `referral_ad_id` (`referral_ad_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    /* 3) Messages — deduplicated on mid. */
    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_ig_messages` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `conversation_id` bigint(20) NOT NULL DEFAULT 0,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `mid` varchar(191) NOT NULL,
        `direction` varchar(8) NOT NULL,
        `source` varchar(24) DEFAULT NULL,
        `type` varchar(24) NOT NULL DEFAULT 'text',
        `body` mediumtext DEFAULT NULL,
        `media_ref` varchar(191) DEFAULT NULL,
        `delivery_state` varchar(16) DEFAULT NULL,
        `sent_at` datetime DEFAULT NULL,
        `received_at` datetime DEFAULT NULL,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `mid` (`mid`),
        KEY `conversation_id` (`conversation_id`),
        KEY `brand_id` (`brand_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    /* 4) Webhook event queue — durable, deduplicated, claimed, async. */
    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_ig_webhook_events` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `event_hash` varchar(64) NOT NULL,
        `ig_account_id` varchar(32) DEFAULT NULL,
        `payload` longtext DEFAULT NULL,
        `signature_valid` tinyint(1) NOT NULL DEFAULT 0,
        `state` varchar(16) NOT NULL DEFAULT 'pending',
        `attempts` int(11) NOT NULL DEFAULT 0,
        `last_error` varchar(255) DEFAULT NULL,
        `next_attempt_at` datetime DEFAULT NULL,
        `locked_at` datetime DEFAULT NULL,
        `locked_by` varchar(64) DEFAULT NULL,
        `fence` bigint(20) NOT NULL DEFAULT 0,
        `received_at` datetime NOT NULL,
        `processed_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `event_hash` (`event_hash`),
        KEY `claim` (`state`,`next_attempt_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    /* 5) Outbound queue — idempotent, claimed, fenced. */
    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_ig_outbound` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `conversation_id` bigint(20) NOT NULL DEFAULT 0,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `kind` varchar(16) NOT NULL DEFAULT 'text',
        `body` mediumtext DEFAULT NULL,
        `idempotency_key` varchar(64) DEFAULT NULL,
        `status` varchar(16) NOT NULL DEFAULT 'pending',
        `attempts` int(11) NOT NULL DEFAULT 0,
        `failure_class` varchar(24) DEFAULT NULL,
        `last_error` varchar(255) DEFAULT NULL,
        `mid` varchar(191) DEFAULT NULL,
        `locked_at` datetime DEFAULT NULL,
        `locked_by` varchar(64) DEFAULT NULL,
        `next_attempt_at` datetime DEFAULT NULL,
        `fence` bigint(20) NOT NULL DEFAULT 0,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `sent_at` datetime DEFAULT NULL,
        `date_created` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idempotency_key` (`idempotency_key`),
        KEY `drain` (`status`,`next_attempt_at`),
        KEY `brand_id` (`brand_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    return $s;
}

/* --------------------------- secrets + signature ------------------------- */

/**
 * Signature secret. CANONICAL SOURCE IS THE META APP SECRET: Instagram
 * Messaging is served by the same Meta app (1375062474780237) as Lead Ads and
 * WhatsApp, so the App Secret that signs X-Hub-Signature-256 is identical. A
 * dedicated `ig_app` file is honoured if ever installed. File provider only.
 */
function se_ig_app_secret()
{
    $own = function_exists('se_secret_read') ? se_secret_read('ig_app') : '';

    return $own !== '' ? $own : (function_exists('se_secret_read') ? se_secret_read('meta_app') : '');
}

function se_ig_app_secret_inherited()
{
    return se_secret_read('ig_app') === '' && se_secret_read('meta_app') !== '';
}

/** Instagram webhook verify token (provider ig_verify). '' fails closed. */
function se_ig_verify_token()
{
    return function_exists('se_secret_read') ? se_secret_read('ig_verify') : '';
}

/**
 * Access token used for the Send API and Graph reads. Inherits the Page/
 * system-user token (meta_page) — the same system user owns the Page the
 * Instagram account is linked to; the token must carry instagram_basic +
 * instagram_manage_messages. A dedicated `ig_token` file takes precedence.
 */
function se_ig_token($brand_id = 0)
{
    if (!function_exists('se_secret_read')) { return ''; }

    $own = se_secret_read('ig_token');
    if ($own !== '') { return $own; }

    $page = se_secret_read('meta_page', (int) $brand_id);
    if ($page !== '') { return $page; }

    return se_secret_read('meta_page', 0);
}

function se_ig_token_inherited($brand_id = 0)
{
    return se_secret_read('ig_token') === '' && se_ig_token($brand_id) !== '';
}

/** Verify Meta's X-Hub-Signature-256 over the exact raw body. Constant-time. */
function se_ig_verify_signature($raw_body, $header, $app_secret = null)
{
    $secret = $app_secret !== null ? $app_secret : se_ig_app_secret();
    if ($secret === '' || !is_string($header) || strpos($header, 'sha256=') !== 0) {
        return false;
    }
    $expected = 'sha256=' . hash_hmac('sha256', $raw_body, $secret);

    return hash_equals($expected, $header);
}

/** GET verification decision: subscribe mode + configured token + constant-time match. */
function se_ig_verify_outcome($mode, $token)
{
    $expected = se_ig_verify_token();

    return $mode === 'subscribe' && $expected !== '' && hash_equals($expected, (string) $token);
}

/**
 * The POST pipeline: 413 (size) -> 401 (signature over RAW bytes) -> 400
 * (unparseable JSON) -> 200 only after the durable, deduplicated store.
 *
 * @return array{status:int,ok:bool,reason:string}
 */
function se_ig_receive_outcome($declared_length, $raw, $signature_header)
{
    if ((int) $declared_length > SE_IG_MAX_BODY_BYTES
        || ($raw !== false && strlen((string) $raw) > SE_IG_MAX_BODY_BYTES)) {
        return ['status' => 413, 'ok' => false, 'reason' => 'payload_too_large'];
    }

    $raw = (string) $raw;

    if (!se_ig_verify_signature($raw, $signature_header)) {
        return ['status' => 401, 'ok' => false, 'reason' => 'bad_signature'];
    }

    $decoded = json_decode($raw);

    if (json_last_error() !== JSON_ERROR_NONE || (!is_array($decoded) && !is_object($decoded))) {
        return ['status' => 400, 'ok' => false, 'reason' => 'malformed_json'];
    }

    $stored = se_ig_store_event($raw, true);

    if (!empty($stored['stored']) || !empty($stored['duplicate'])) {
        return ['status' => 200, 'ok' => true,
                'reason' => empty($stored['duplicate']) ? 'accepted' : 'duplicate'];
    }

    return ['status' => 500, 'ok' => false, 'reason' => 'not_stored'];
}

/** Routing id: the IG professional account (entry[0].id). */
function se_ig_extract_routing($payload)
{
    return ['ig_account_id' => isset($payload['entry'][0]['id']) ? (string) $payload['entry'][0]['id'] : null];
}

/** Store a webhook event durably, deduplicated on a hash of the raw body. */
function se_ig_store_event($raw_body, $signature_valid)
{
    $CI    = &get_instance();
    $table = db_prefix() . 'se_ig_webhook_events';

    if (strlen((string) $raw_body) > SE_IG_MAX_BODY_BYTES) {
        return ['stored' => false, 'duplicate' => false, 'oversize' => true];
    }

    $hash = hash('sha256', $raw_body);

    $CI->db->where('event_hash', $hash);
    if ($CI->db->count_all_results($table) > 0) {
        return ['stored' => false, 'duplicate' => true];
    }

    $routing = se_ig_extract_routing(json_decode($raw_body, true) ?: []);

    try {
        $CI->db->insert($table, [
            'event_hash'      => $hash,
            'ig_account_id'   => mb_substr((string) $routing['ig_account_id'], 0, 32),
            'payload'         => $raw_body,
            'signature_valid' => $signature_valid ? 1 : 0,
            'state'           => 'pending',
            'next_attempt_at' => se_db_now(),
            'received_at'     => se_db_now(),
        ]);
    } catch (Exception $e) {
        if (stripos($e->getMessage(), 'duplicate') !== false) {
            return ['stored' => false, 'duplicate' => true];
        }

        return ['stored' => false, 'duplicate' => false, 'error' => true];
    }

    return ['stored' => true, 'duplicate' => false];
}

function se_ig_backoff_seconds($attempts)
{
    $exp = min(SE_IG_BACKOFF_BASE * (2 ** max(0, (int) $attempts - 1)), SE_IG_BACKOFF_CAP);

    return random_int((int) ($exp / 2), (int) $exp);
}

function se_ig_purge_old_payloads()
{
    $CI = &get_instance();

    $CI->db->where('state', 'processed')
           ->where('received_at <', se_db_now(-SE_IG_EVENT_RETENTION_DAYS * 86400))
           ->where('payload IS NOT NULL', null, false)
           ->update(db_prefix() . 'se_ig_webhook_events', ['payload' => null]);

    return (int) $CI->db->affected_rows();
}

function se_ig_recover_stale()
{
    $CI = &get_instance();

    $CI->db->where('state', 'processing')
           ->where('locked_at <', se_db_now(-SE_IG_LEASE_SECONDS))
           ->update(db_prefix() . 'se_ig_webhook_events',
                    ['state' => 'pending', 'locked_at' => null, 'locked_by' => null]);

    return (int) $CI->db->affected_rows();
}

/** Atomically claim DUE webhook events (fenced against expired leases). */
function se_ig_claim_batch($worker, $limit = 100)
{
    $CI    = &get_instance();
    $table = db_prefix() . 'se_ig_webhook_events';
    $now   = se_db_now();

    $CI->db->query(
        'UPDATE `' . $table . "` SET state='processing', locked_at=NOW()"
        . ', locked_by=' . $CI->db->escape($worker)
        . ', fence = fence + 1'
        . " WHERE state='pending' AND signature_valid=1 AND attempts < " . (int) SE_IG_MAX_ATTEMPTS
        . ' AND (next_attempt_at IS NULL OR next_attempt_at <= ' . $CI->db->escape($now) . ')'
        . ' ORDER BY id ASC LIMIT ' . max(1, (int) $limit)
    );

    $CI->db->where('state', 'processing')->where('locked_by', $worker)->order_by('id', 'ASC');

    return $CI->db->get($table)->result_array();
}

/* ------------------------------- processing ------------------------------ */

/** Map an IG account id to its brand. Null if unknown. */
function se_ig_route_to_brand($ig_account_id)
{
    if (!$ig_account_id) {
        return null;
    }
    $CI = &get_instance();
    $CI->db->where('ig_account_id', (string) $ig_account_id);
    $row = $CI->db->get(db_prefix() . 'se_ig_accounts')->row();

    return $row ? (int) $row->brand_id : null;
}

class SeIgPermanentError extends Exception {}

/** Drain webhook events: bounded, claimed, retried with backoff. */
function se_ig_process_pending($limit = 100)
{
    $limit = (int) $limit; if ($limit < 1) { $limit = 100; }

    $CI    = &get_instance();
    $table = db_prefix() . 'se_ig_webhook_events';

    se_ig_recover_stale();

    $worker = substr(md5(uniqid((string) getmypid(), true)), 0, 24);
    $events = se_ig_claim_batch($worker, $limit);

    foreach ($events as $ev) {
        $error = ''; $permanent = false;

        try {
            se_ig_process_event($ev);
        } catch (SeIgPermanentError $e) {
            $error = 'routing failure'; $permanent = true;
        } catch (Exception $e) {
            $error = 'processing error';
        }

        $attempts = (int) $ev['attempts'] + 1;

        if ($error === '') {
            $update = ['state' => 'processed', 'attempts' => $attempts, 'last_error' => null,
                       'processed_at' => date('Y-m-d H:i:s'), 'locked_at' => null, 'locked_by' => null];
        } elseif ($permanent || $attempts >= SE_IG_MAX_ATTEMPTS) {
            $update = ['state' => 'failed', 'attempts' => $attempts, 'last_error' => $error,
                       'processed_at' => date('Y-m-d H:i:s'), 'locked_at' => null, 'locked_by' => null];
        } else {
            $update = ['state' => 'pending', 'attempts' => $attempts, 'last_error' => $error,
                       'next_attempt_at' => se_db_now(se_ig_backoff_seconds($attempts)),
                       'locked_at' => null, 'locked_by' => null];
        }

        $CI->db->where('id', $ev['id'])->where('locked_by', $worker)->where('fence', (int) $ev['fence'])
               ->update($table, $update);
    }

    se_ig_purge_old_payloads();

    return count($events);
}

/** Parse one messaging event into a normalised shape (pure; unit-tested). */
function se_ig_classify_event($m)
{
    $sender    = (string) ($m['sender']['id'] ?? '');
    $recipient = (string) ($m['recipient']['id'] ?? '');
    $tsMs      = isset($m['timestamp']) ? (int) $m['timestamp'] : 0;
    $ts        = $tsMs > 0 ? (int) floor($tsMs / 1000) : time();

    if (isset($m['read']['mid'])) {
        return ['kind' => 'read', 'sender' => $sender, 'recipient' => $recipient, 'ts' => $ts,
                'mid' => (string) $m['read']['mid']];
    }

    if (isset($m['postback'])) {
        return ['kind' => 'inbound', 'sender' => $sender, 'recipient' => $recipient, 'ts' => $ts,
                'mid'  => (string) ($m['postback']['mid'] ?? ''), 'type' => 'postback',
                'text' => (string) ($m['postback']['title'] ?? ''), 'media' => null,
                'referral' => $m['postback']['referral'] ?? ($m['referral'] ?? null)];
    }

    if (isset($m['message'])) {
        $msg  = $m['message'];
        $type = 'text';
        $media = null;
        if (!empty($msg['attachments'][0]['type'])) {
            $type  = mb_substr((string) $msg['attachments'][0]['type'], 0, 24);
            $media = isset($msg['attachments'][0]['payload']['url'])
                ? 'url:' . mb_substr((string) $msg['attachments'][0]['payload']['url'], 0, 180) : 'attachment';
        }

        return ['kind' => !empty($msg['is_echo']) ? 'echo' : 'inbound',
                'sender' => $sender, 'recipient' => $recipient, 'ts' => $ts,
                'mid' => (string) ($msg['mid'] ?? ''), 'type' => $type,
                'text' => (string) ($msg['text'] ?? ''), 'media' => $media,
                'deleted' => !empty($msg['is_deleted']),
                'referral' => $m['referral'] ?? ($msg['referral'] ?? null)];
    }

    if (isset($m['referral'])) {
        // A standalone referral event (ad tap that opened the thread).
        return ['kind' => 'referral', 'sender' => $sender, 'recipient' => $recipient, 'ts' => $ts,
                'referral' => $m['referral']];
    }

    return ['kind' => 'ignore', 'sender' => $sender, 'recipient' => $recipient, 'ts' => $ts];
}

function se_ig_process_event($ev)
{
    $payload  = json_decode($ev['payload'], true) ?: [];
    $routing  = se_ig_extract_routing($payload);
    $brand_id = se_ig_route_to_brand($routing['ig_account_id']);

    if ($brand_id === null) {
        throw new SeIgPermanentError('unknown ig_account_id');
    }

    $account = (string) $routing['ig_account_id'];

    foreach (($payload['entry'] ?? []) as $entry) {
        foreach (($entry['messaging'] ?? []) as $m) {
            $e = se_ig_classify_event($m);

            switch ($e['kind']) {
                case 'inbound':  se_ig_handle_inbound($brand_id, $account, $e); break;
                case 'echo':     se_ig_handle_echo($brand_id, $account, $e);    break;
                case 'read':     se_ig_handle_read($brand_id, $account, $e);    break;
                case 'referral': se_ig_handle_referral($brand_id, $account, $e); break;
                default: break;
            }
        }
    }
}

/** Find-or-create the conversation for (account, customer igsid). */
function se_ig_conversation_for($brand_id, $account, $igsid, $create, $ts = null)
{
    $CI    = &get_instance();
    $table = db_prefix() . 'se_ig_conversations';

    $CI->db->where('ig_account_id', $account)->where('igsid', $igsid);
    $conv = $CI->db->get($table)->row();

    if ($conv && (int) $conv->brand_id !== (int) $brand_id) {
        throw new SeIgPermanentError('conversation brand mismatch');
    }

    if ($conv || !$create) {
        return $conv;
    }

    $CI->db->insert($table, [
        'brand_id'      => (int) $brand_id,
        'ig_account_id' => $account,
        'igsid'         => $igsid,
        'unread_count'  => 0,
        'state'         => 'open',
        'date_created'  => $ts ?: date('Y-m-d H:i:s'),
    ]);
    $id = (int) $CI->db->insert_id();

    $CI->db->where('id', $id);

    return $CI->db->get($table)->row();
}

/** Attach ad-referral attribution ONCE (first touch); never overwritten. */
function se_ig_apply_referral($conv, $referral)
{
    if (!$conv || !is_array($referral) || !empty($conv->referral_json)) {
        return;
    }
    $CI = &get_instance();
    $CI->db->where('id', (int) $conv->id)->where('brand_id', (int) $conv->brand_id)
           ->where('(referral_json IS NULL OR referral_json = \'\')', null, false)
           ->update(db_prefix() . 'se_ig_conversations', [
               'referral_ad_id'  => isset($referral['ad_id']) ? mb_substr((string) $referral['ad_id'], 0, 64) : null,
               'referral_source' => isset($referral['source']) ? mb_substr((string) $referral['source'], 0, 32) : null,
               'referral_json'   => mb_substr((string) json_encode($referral), 0, SE_IG_MAX_REFERRAL_BYTES),
               'last_updated'    => date('Y-m-d H:i:s'),
           ]);
}

/** Inbound customer message (or icebreaker postback). Deduplicated on mid. */
function se_ig_handle_inbound($brand_id, $account, $e)
{
    $CI    = &get_instance();
    $igsid = mb_substr($e['sender'], 0, SE_IG_MAX_ID_LEN);
    $mid   = mb_substr($e['mid'], 0, SE_IG_MAX_ID_LEN);

    if ($igsid === '' || $mid === '' || !empty($e['deleted'])) {
        return;
    }

    // DEDUP FIRST: a redelivered mid must not touch counters, the window or
    // attribution — otherwise a Meta retry double-counts unread.
    $msgTable = db_prefix() . 'se_ig_messages';
    $CI->db->where('mid', $mid);
    if ($CI->db->count_all_results($msgTable) > 0) {
        return; // duplicate delivery
    }

    $ts     = date('Y-m-d H:i:s', (int) $e['ts']);
    $window = date('Y-m-d H:i:s', (int) $e['ts'] + SE_IG_WINDOW_HOURS * 3600);

    if (function_exists('update_option')) {
        update_option('se_ig_last_inbound_at', $ts);
        update_option('se_ig_last_inbound_at_' . (int) $brand_id, $ts);
    }

    $convTable = db_prefix() . 'se_ig_conversations';
    $conv = se_ig_conversation_for($brand_id, $account, $igsid, true, $ts);
    $isNew = (int) $conv->unread_count === 0 && empty($conv->last_inbound_at);

    $CI->db->where('id', (int) $conv->id)->where('brand_id', (int) $brand_id)->update($convTable, [
        'last_inbound_at'   => $ts,
        'window_expires_at' => $window,
        'unread_count'      => (int) $conv->unread_count + 1,
        'last_updated'      => date('Y-m-d H:i:s'),
    ]);

    se_ig_apply_referral($conv, $e['referral'] ?? null);

    if ($isNew && function_exists('se_webhook_record')) {
        se_webhook_record('ig', 'live_test');
    }

    $CI->db->insert($msgTable, [
        'conversation_id' => (int) $conv->id,
        'brand_id'        => (int) $brand_id,
        'mid'             => $mid,
        'direction'       => 'in',
        'source'          => 'customer',
        'type'            => $e['type'],
        'body'            => $e['type'] === 'text' || $e['type'] === 'postback'
            ? mb_substr((string) $e['text'], 0, SE_IG_MAX_TEXT_LEN) : null,
        'media_ref'       => $e['media'],
        'received_at'     => $ts,
        'date_created'    => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Business-sent message echoed back (sent from the Instagram app / Business
 * Suite, or by the CRM itself). Mirrored into the thread; a CRM-sent message
 * is already recorded under its mid, so the unique key makes this a no-op.
 */
function se_ig_handle_echo($brand_id, $account, $e)
{
    $CI    = &get_instance();
    $igsid = mb_substr($e['recipient'], 0, SE_IG_MAX_ID_LEN);
    $mid   = mb_substr($e['mid'], 0, SE_IG_MAX_ID_LEN);

    if ($igsid === '' || $mid === '') {
        return;
    }

    $msgTable = db_prefix() . 'se_ig_messages';
    $CI->db->where('mid', $mid);
    if ($CI->db->count_all_results($msgTable) > 0) {
        return;   // CRM-sent (already mirrored) or a replay
    }

    $conv = se_ig_conversation_for($brand_id, $account, $igsid, true);
    $ts   = date('Y-m-d H:i:s', (int) $e['ts']);

    $CI->db->insert($msgTable, [
        'conversation_id' => (int) $conv->id,
        'brand_id'        => (int) $brand_id,
        'mid'             => $mid,
        'direction'       => 'out',
        'source'          => 'handset',
        'type'            => $e['type'],
        'body'            => $e['type'] === 'text' ? mb_substr((string) $e['text'], 0, SE_IG_MAX_TEXT_LEN) : null,
        'media_ref'       => $e['media'],
        'delivery_state'  => 'sent',
        'sent_at'         => $ts,
        'date_created'    => date('Y-m-d H:i:s'),
    ]);
}

/** Customer read receipt: mark this brand's outbound rows in the thread as read. */
function se_ig_handle_read($brand_id, $account, $e)
{
    $CI    = &get_instance();
    $igsid = mb_substr($e['sender'], 0, SE_IG_MAX_ID_LEN);
    if ($igsid === '') {
        return;
    }

    if (function_exists('update_option')) {
        $nowTs = date('Y-m-d H:i:s');
        update_option('se_ig_last_status_at', $nowTs);
        update_option('se_ig_last_status_at_' . (int) $brand_id, $nowTs);
    }

    $conv = se_ig_conversation_for($brand_id, $account, $igsid, false);
    if (!$conv) {
        return;
    }

    $CI->db->where('conversation_id', (int) $conv->id)->where('brand_id', (int) $brand_id)
           ->where('direction', 'out')->where('delivery_state !=', 'read')
           ->update(db_prefix() . 'se_ig_messages', ['delivery_state' => 'read']);
}

/** Standalone ad-referral event (thread opened from an ad before any text). */
function se_ig_handle_referral($brand_id, $account, $e)
{
    $igsid = mb_substr($e['sender'], 0, SE_IG_MAX_ID_LEN);
    if ($igsid === '') {
        return;
    }
    $conv = se_ig_conversation_for($brand_id, $account, $igsid, true, date('Y-m-d H:i:s', (int) $e['ts']));
    se_ig_apply_referral($conv, $e['referral'] ?? null);
}

/* --------------------------- reply window / misc ------------------------- */

function se_ig_window_open($conversation)
{
    if (!$conversation || empty($conversation->window_expires_at)) {
        return false;
    }
    return strtotime($conversation->window_expires_at) > time();
}

function se_ig_redacted_contact($value)
{
    $tail = mb_substr((string) $value, -4);

    return $tail === '' ? '[redacted]' : '••••' . $tail;
}

/** Can this brand send (has an active account row)? */
function se_ig_can_send($brand_id)
{
    $CI = &get_instance();
    $CI->db->where('brand_id', (int) $brand_id)->where('state', 'active');

    return $CI->db->count_all_results(db_prefix() . 'se_ig_accounts') > 0;
}

function se_ig_accounts_for($brand_id = 0)
{
    $CI = &get_instance();
    se_apply_scope_in('brand_id');
    if ((int) $brand_id > 0 && se_can_access_brand($brand_id)) {
        $CI->db->where('brand_id', (int) $brand_id);
    }
    $CI->db->order_by('id', 'ASC');

    return $CI->db->get(db_prefix() . 'se_ig_accounts')->result_array();
}

function se_ig_last_event_at()
{
    $CI = &get_instance();
    $CI->db->select('received_at')->order_by('id', 'DESC')->limit(1);
    $row = $CI->db->get(db_prefix() . 'se_ig_webhook_events')->row();

    return $row ? $row->received_at : null;
}
