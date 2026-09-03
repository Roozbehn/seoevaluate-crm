<?php

defined('BASEPATH') or exit('No direct script access allowed');

if (!defined('SE_WA_DEFAULT_REMINDER_TEMPLATE')) {
    define('SE_WA_DEFAULT_REMINDER_TEMPLATE', 'eyebrow_consultation_reminder_tr');
}

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
    // AUTHORITATIVE send-capability check — the composer, the queue drain,
    // Integration Health and diagnostics must all read THIS, so the UI can
    // never contradict what the pipeline actually does. Registration of the
    // live transport is lazy (module load order once made the eager attempt
    // silently skip), so try it here before judging 'no_transport'.
    if (function_exists('se_wa_maybe_register_live_transport')) {
        se_wa_maybe_register_live_transport();
    }

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

/**
 * Inbox-level capability notice.
 *
 * The inbox may contain more than one accessible brand, so brand 0 is not a
 * valid stand-in for "all brands" here.  Show a send-capability notice only
 * when every visible conversation belongs to one concrete brand; the
 * conversation composer still performs the authoritative per-brand check.
 */
function se_wa_inbox_blocked_reason(array $conversations)
{
    $brands = [];

    foreach ($conversations as $conversation) {
        $brand_id = (int) (is_array($conversation)
            ? ($conversation['brand_id'] ?? 0)
            : ($conversation->brand_id ?? 0));

        if ($brand_id > 0) {
            $brands[$brand_id] = true;
        }
    }

    if (count($brands) !== 1) {
        return '';
    }

    return se_wa_send_blocked_reason((int) array_key_first($brands));
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

    // Staff callers are scoped to their brands. SYSTEM callers (journey
    // automation, appointment reminders) run from the dispatcher / cron with
    // no staff session — the scope would resolve to "nothing" and the send
    // would silently fail with not_found; they have already resolved the
    // thread by (id, brand) and say so with 'system' => true.
    $CI->db->where('id', (int) $conversation_id);
    if (empty($message['system'])) {
        se_apply_scope_in('brand_id');
    }
    $conv = $CI->db->get(db_prefix() . 'se_wa_conversations')->row();

    if (!$conv) {
        return ['ok' => false, 'id' => 0, 'reason' => 'not_found'];
    }

    $policy = se_wa_compose_policy($conv);

    if (!$policy['allowed']) {
        return ['ok' => false, 'id' => 0, 'reason' => $policy['reason']];
    }

    $kind = $message['kind'] ?? 'text';
    $payload_json = null;

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
    } elseif ($kind === 'interactive') {
        /* Reply-button message. Same window rule as free-form text: it is a
         * session message, not a template. Limits are Meta's published ones
         * (body 1024, footer 60, 1–3 buttons, title 20, id 256) and are
         * enforced here so a bad definition fails at queue time, visibly. */
        if ($policy['mode'] !== 'freeform') {
            return ['ok' => false, 'id' => 0, 'reason' => 'window_closed'];
        }

        $shaped = se_wa_shape_interactive($message);
        if (!$shaped['ok']) {
            return ['ok' => false, 'id' => 0, 'reason' => $shaped['reason']];
        }

        $body         = $shaped['body'];
        $payload_json = json_encode($shaped['payload']);
        $signature    = hash('sha256', $body . '|' . $payload_json);
        $template     = null;
    } elseif ($kind === 'template') {
        $name = (string) ($message['template'] ?? '');

        // Only an APPROVED template for THIS brand may be queued.
        $approved = null;

        foreach (se_wa_approved_templates((int) $conv->brand_id) as $t) {
            if ($t['name'] === $name) { $approved = $t; break; }
        }

        if ($approved === null) {
            return ['ok' => false, 'id' => 0, 'reason' => 'template_not_approved'];
        }

        // Placeholder values must match the template EXACTLY, in order. Meta
        // rejects a mismatch at send time (#132000 "Number of parameters does
        // not match"), which used to surface only as a failed queue row an
        // hour later; refuse it here, at queue time, where the operator is.
        $expected = function_exists('se_wa_template_variables') ? se_wa_template_variables($approved) : [];
        // The journey registry submitted its own templates and knows their
        // placeholders and buttons before the mirror does (a status webhook
        // inserts the approval alone; the full pull follows on the cron).
        $hint = function_exists('se_journey_template_hint') ? se_journey_template_hint((int) $conv->brand_id, $name) : null;
        if (!$expected && $hint) {
            $expected = $hint['variables'];
        }
        // A FLOW button is sent with a per-message flow token; without one Meta
        // refuses the send (#132000). Only the journey issues tokens.
        if ($hint && $hint['flow_kind'] !== '' && empty($message['flow_button'])) {
            return ['ok' => false, 'id' => 0, 'reason' => 'flow_button_required'];
        }
        $given    = (array) ($message['variables'] ?? []);
        $isList   = array_keys($given) === range(0, count($given) - 1);
        $ordered  = [];
        foreach ($expected as $pos => $key) {
            // Accept values keyed by placeholder ('1', 'name') or as a plain
            // list. A plain list is POSITIONAL: with numeric placeholders
            // ('1','2') PHP turns the key '1' into index 1, so looking the key
            // up first handed {{1}} the SECOND value (and {{2}} the same one)
            // for every automated template with two or more variables.
            if ($isList) {
                $val = isset($given[$pos]) ? $given[$pos] : '';
            } else {
                $val = isset($given[$key]) ? $given[$key] : '';
            }
            $val = trim((string) $val);
            if ($val === '') {
                return ['ok' => false, 'id' => 0, 'reason' => 'template_variables', 'missing' => (string) $key];
            }
            $ordered[] = mb_substr($val, 0, 1024);
        }
        if (!$expected) {
            // Placeholder list unknown for this row (never fully synced): pass
            // through whatever the caller supplied, in order, and let Meta judge.
            $ordered = array_map(function ($v) { return mb_substr(trim((string) $v), 0, 1024); }, array_values($given));
        }
        $message['variables'] = $ordered;

        // Quick-reply button payloads (template BUTTONS component): what a tap
        // sends back as button.payload. Optional; without them Meta echoes the
        // button text. Positional, at most three, short opaque ids.
        if (!empty($message['quick_replies']) && is_array($message['quick_replies'])) {
            $qr = [];
            foreach (array_slice(array_values($message['quick_replies']), 0, 3) as $p) {
                $p = trim((string) $p);
                if ($p === '' || mb_strlen($p) > 128) {
                    return ['ok' => false, 'id' => 0, 'reason' => 'quick_reply_invalid'];
                }
                $qr[] = $p;
            }
            $payload_json = json_encode(['quick_replies' => $qr]);
        }
        // A template FLOW button: the per-message flow_token (and optional
        // flow_action_data) go with the send as a button component.
        if (!empty($message['flow_button']) && is_array($message['flow_button'])) {
            $fb = $message['flow_button'];
            $token = trim((string) ($fb['flow_token'] ?? ''));
            if ($token === '' || mb_strlen($token) > 256) {
                return ['ok' => false, 'id' => 0, 'reason' => 'flow_button_invalid'];
            }
            $pj = json_decode((string) $payload_json, true) ?: [];
            $pj['flow_button'] = ['index' => (int) ($fb['index'] ?? 0), 'flow_token' => $token,
                                  'flow_action_data' => is_array($fb['flow_action_data'] ?? null) ? $fb['flow_action_data'] : new stdClass()];
            $payload_json = json_encode($pj);
        }

        $body      = null;
        $template  = $name;
        $signature = hash('sha256', $name . '|' . json_encode($ordered) . ($payload_json !== null ? '|' . $payload_json : ''));
    } elseif ($kind === 'media') {
        // An attachment uploaded from the composer: free-form, so it obeys the
        // same 24-hour window as text. The row must be this brand's, stored,
        // and of a kind WhatsApp accepts.
        if ($policy['mode'] !== 'freeform') {
            return ['ok' => false, 'id' => 0, 'reason' => 'window_closed'];
        }
        $media_id = (int) ($message['media_id'] ?? 0);
        $media = function_exists('se_media_sendable') ? se_media_sendable($media_id, 'wa', (int) $conv->brand_id) : null;
        if ($media === null) {
            return ['ok' => false, 'id' => 0, 'reason' => 'media_invalid'];
        }
        $body      = trim((string) ($message['body'] ?? ''));
        $body      = $body !== '' ? mb_substr($body, 0, 1024) : null;   // caption cap (Meta: 1024)
        $template  = null;
        $signature = hash('sha256', 'media|' . $media_id . '|' . (string) $body);
    } else {
        return ['ok' => false, 'id' => 0, 'reason' => 'unsupported_kind'];
    }

    // Callers that need a distinct row for the same content (a second reminder
    // with identical wording) pass a discriminator; it becomes part of the key.
    if (isset($message['dedup_salt']) && (string) $message['dedup_salt'] !== '') {
        $signature .= '|' . (string) $message['dedup_salt'];
    }

    $key = se_wa_idempotency_key($conversation_id, $kind, $signature);

    // Idempotent: the same intent queued twice is one row.
    $CI->db->where('idempotency_key', $key);

    if ($CI->db->count_all_results(db_prefix() . 'se_wa_outbound') > 0) {
        return ['ok' => false, 'id' => 0, 'reason' => 'duplicate'];
    }

    // A deferred send (quiet hours) is expressed as a future next_attempt_at;
    // the claim query already honours it. Never earlier than now.
    $sendAt = se_db_now();
    if (isset($message['send_after']) && (int) $message['send_after'] > 0) {
        $sendAt = se_db_now(max(0, (int) $message['send_after'] - time()));
    }

    try {
        $CI->db->insert(db_prefix() . 'se_wa_outbound', [
            'conversation_id' => (int) $conv->id,
            'brand_id'        => (int) $conv->brand_id,
            'kind'            => $kind,
            'body'            => $body,
            'template_name'   => $template,
            'variables_json'  => $kind === 'template' ? json_encode(array_values((array) $message['variables'])) : null,
            'media_id'        => $kind === 'media' ? (int) $message['media_id'] : null,
            'payload_json'    => $payload_json,
            'origin'          => isset($message['origin']) ? mb_substr((string) $message['origin'], 0, 48) : ($staff_id > 0 ? 'staff' : 'system'),
            'idempotency_key' => $key,
            'status'          => 'pending',
            'attempts'        => 0,
            'fence'           => 0,
            'created_by'      => (int) $staff_id,
            'date_created'    => se_db_now(),
            'next_attempt_at' => $sendAt,
        ]);
    } catch (Exception $e) {
        // The unique index is the real guard; the pre-check only narrows it.
        return ['ok' => false, 'id' => 0, 'reason' => 'duplicate'];
    }

    $id = (int) $CI->db->insert_id();

    // A human reply pauses automation ONLY when the staff member asked for it
    // (composer checkbox / ⏸ toggle → pause_automation=1). Pausing silently on
    // every reply stopped reminders for exactly the patients a human had
    // spoken to, and the Resume button lived on another page (audit F8).
    if ((int) $staff_id > 0 && empty($message['origin']) && !empty($message['pause_automation'])
        && function_exists('se_journey_on_staff_send')) {
        se_journey_on_staff_send($conv, (int) $staff_id, $id);
    }

    return ['ok' => true, 'id' => $id, 'reason' => ''];
}

/**
 * The Cloud API request body for a queued template message: body parameters
 * in order, then one button component per quick-reply payload (index-bound,
 * as Meta requires). Pure, so it is testable without the network.
 */
function se_wa_template_send_payload(array $m)
{
    $components = [];
    $vars = array_values((array) ($m['variables'] ?? []));
    if ($vars) {
        $components[] = ['type' => 'body', 'parameters' => array_map(function ($v) {
            return ['type' => 'text', 'text' => (string) $v];
        }, $vars)];
    }
    $qr = (array) (($m['payload']['quick_replies'] ?? null) ?: []);
    foreach (array_values($qr) as $i => $payloadId) {
        $components[] = ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => (string) $i,
                         'parameters' => [['type' => 'payload', 'payload' => (string) $payloadId]]];
    }
    $fb = (array) (($m['payload']['flow_button'] ?? null) ?: []);
    if (!empty($fb['flow_token'])) {
        $action = ['flow_token' => (string) $fb['flow_token']];
        if (!empty($fb['flow_action_data']) && is_array($fb['flow_action_data'])) {
            $action['flow_action_data'] = $fb['flow_action_data'];
        }
        $components[] = ['type' => 'button', 'sub_type' => 'flow', 'index' => (string) (int) ($fb['index'] ?? 0),
                         'parameters' => [['type' => 'action', 'action' => $action]]];
    }

    return [
        'messaging_product' => 'whatsapp',
        'to'                => (string) $m['to'],
        'type'              => 'template',
        'template'          => [
            'name'     => (string) $m['template'],
            'language' => ['code' => (string) ($m['template_language'] ?? '') !== ''
                ? (string) $m['template_language'] : 'tr'],
            'components' => $components,
        ],
    ];
}

/** The "interactive" object for a queued row: reply buttons, or a Flow CTA. Pure. */
function se_wa_interactive_payload($body, array $p)
{
    if (!empty($p['flow']) && is_array($p['flow'])) {
        $f = $p['flow'];
        $params = ['flow_message_version' => (string) ($f['flow_message_version'] ?? '3'), 'flow_token' => (string) $f['flow_token'],
                   'flow_id' => (string) $f['flow_id'], 'flow_cta' => (string) $f['flow_cta'], 'flow_action' => (string) ($f['flow_action'] ?? 'navigate')];
        // flow_action_payload (first screen + its data) exists only for
        // navigate; with data_exchange the endpoint answers INIT with the
        // first screen, and Meta refuses the field (#131009).
        if ($params['flow_action'] === 'navigate' && !empty($f['flow_action_payload']) && is_array($f['flow_action_payload'])) {
            $params['flow_action_payload'] = $f['flow_action_payload'];
        }
        $interactive = ['type' => 'flow', 'body' => ['text' => $body], 'action' => ['name' => 'flow', 'parameters' => $params]];
    } else {
        $interactive = [
            'type'   => 'button',
            'body'   => ['text' => $body],
            'action' => ['buttons' => array_map(function ($b) {
                return ['type' => 'reply', 'reply' => ['id' => (string) $b['id'], 'title' => (string) $b['title']]];
            }, array_values((array) ($p['buttons'] ?? [])))],
        ];
    }
    if (!empty($p['footer'])) {
        $interactive['footer'] = ['text' => (string) $p['footer']];
    }

    return $interactive;
}

/**
 * Validate and normalise a reply-button message definition.
 *
 * @return array{ok:bool,reason:string,body:string,payload:array}
 */
function se_wa_shape_interactive(array $message)
{
    $body = trim((string) ($message['body'] ?? ''));
    if ($body === '') {
        return ['ok' => false, 'reason' => 'empty_body', 'body' => '', 'payload' => []];
    }
    if (mb_strlen($body) > 1024) {
        return ['ok' => false, 'reason' => 'interactive_body_too_long', 'body' => '', 'payload' => []];
    }

    /* A WhatsApp Flow message (interactive type "flow"): one CTA that opens
     * the flow. Meta's limits: flow_cta ≤ 20 chars, flow_message_version 3. */
    if ((string) ($message['interactive_type'] ?? '') === 'flow') {
        $f = (array) ($message['flow'] ?? []);
        $cta = trim((string) ($f['flow_cta'] ?? ''));
        $flowId = trim((string) ($f['flow_id'] ?? ''));
        $token  = trim((string) ($f['flow_token'] ?? ''));
        if ($cta === '' || mb_strlen($cta) > 20 || $flowId === '' || $token === '' || mb_strlen($token) > 256) {
            return ['ok' => false, 'reason' => 'flow_invalid', 'body' => '', 'payload' => []];
        }
        $payload = ['flow' => [
            'flow_message_version' => (string) ($f['flow_message_version'] ?? '3'),
            'flow_token' => $token, 'flow_id' => $flowId, 'flow_cta' => $cta,
            'flow_action' => (string) ($f['flow_action'] ?? 'navigate') === 'data_exchange' ? 'data_exchange' : 'navigate',
        ]];
        // Only navigate carries a first-screen payload (see se_wa_interactive_payload).
        if ($payload['flow']['flow_action'] === 'navigate' && !empty($f['flow_action_payload']) && is_array($f['flow_action_payload'])) {
            $payload['flow']['flow_action_payload'] = $f['flow_action_payload'];
        }
        $footer = trim((string) ($message['footer'] ?? ''));
        if ($footer !== '') {
            if (mb_strlen($footer) > 60) {
                return ['ok' => false, 'reason' => 'interactive_footer_too_long', 'body' => '', 'payload' => []];
            }
            $payload['footer'] = $footer;
        }

        return ['ok' => true, 'reason' => '', 'body' => $body, 'payload' => $payload];
    }

    $buttons = [];
    foreach ((array) ($message['buttons'] ?? []) as $b) {
        $id    = trim((string) ($b['id'] ?? ''));
        $title = trim((string) ($b['title'] ?? ''));
        if ($id === '' || $title === '' || mb_strlen($id) > 256 || mb_strlen($title) > 20) {
            return ['ok' => false, 'reason' => 'interactive_button_invalid', 'body' => '', 'payload' => []];
        }
        $buttons[] = ['id' => $id, 'title' => $title];
    }
    if (count($buttons) < 1 || count($buttons) > 3) {
        return ['ok' => false, 'reason' => 'interactive_button_count', 'body' => '', 'payload' => []];
    }

    $payload = ['buttons' => $buttons];
    $footer  = trim((string) ($message['footer'] ?? ''));
    if ($footer !== '') {
        if (mb_strlen($footer) > 60) {
            return ['ok' => false, 'reason' => 'interactive_footer_too_long', 'body' => '', 'payload' => []];
        }
        $payload['footer'] = $footer;
    }

    return ['ok' => true, 'reason' => '', 'body' => $body, 'payload' => $payload];
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
    // Lazy transport init: module load order cannot be trusted for the eager
    // registration (see transport.php). No-op in tests (transport.php unloaded).
    if (function_exists('se_wa_maybe_register_live_transport')) {
        se_wa_maybe_register_live_transport();
    }

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
    if (in_array($row['kind'], ['text', 'interactive', 'media'], true) && !se_wa_window_open($conv)) {
        $skipped = ['status' => 'skipped', 'attempts' => (int) $row['attempts'],
                    'failure_class' => 'permanent', 'last_error' => 'service window closed before send'];
        // Let the journey layer fall back to an approved template / staff task.
        if (function_exists('se_journey_on_outbound_skipped')) {
            se_journey_on_outbound_skipped($row, $conv, 'window_closed');
        }
        return $skipped;
    }

    // Attachment: hand the transport the stored file (it uploads to the Cloud
    // API at send time — Meta media ids are single-use-ish and short-lived).
    $media = null;
    if ($row['kind'] === 'media') {
        $media = function_exists('se_media_sendable') ? se_media_sendable((int) ($row['media_id'] ?? 0), 'wa', (int) $conv->brand_id) : null;
        // A real local file for the multipart upload: the store path for local
        // rows, a temp download for R2 rows (removed after the attempt).
        $abs   = $media ? (function_exists('se_media_local_copy') ? se_media_local_copy($media) : se_media_abs_path($media)) : '';
        if ($media === null || $abs === '') {
            return ['status' => 'failed', 'attempts' => (int) $row['attempts'] + 1,
                    'failure_class' => 'permanent', 'last_error' => 'attachment missing'];
        }
        $media['abs_path'] = $abs;
        $media_tmp = ($media['storage'] ?? 'local') === 'r2' ? $abs : '';
    }

    // The template's language comes from the mirror row, never assumed: Meta
    // rejects a send whose language code differs from the approved template.
    $template_language = '';
    if ($row['kind'] === 'template') {
        foreach (se_wa_approved_templates((int) $conv->brand_id) as $t) {
            if ($t['name'] === $row['template_name']) { $template_language = (string) $t['language']; break; }
        }
    }

    try {
        $result = call_user_func($GLOBALS['SE_WA_TRANSPORT'], [
            'phone_number_id' => $conv->phone_number_id,
            'to'              => $conv->wa_user_id,
            'kind'            => $row['kind'],
            'body'            => $row['body'],
            'template'        => $row['template_name'],
            'template_language' => $template_language,
            'variables'       => json_decode((string) $row['variables_json'], true) ?: [],
            'media'           => $media,   // kind, mime, filename, abs_path — or null
            'payload'         => json_decode((string) ($row['payload_json'] ?? ''), true) ?: [],
            'idempotency_key' => $row['idempotency_key'],
        ]);
    } catch (Exception $e) {
        if (!empty($media_tmp)) { @unlink($media_tmp); }
        $attempts = (int) $row['attempts'] + 1;

        return ['status' => $attempts >= SE_WA_OUT_MAX_ATTEMPTS ? 'failed' : 'pending',
                'attempts' => $attempts, 'failure_class' => 'retryable',
                'last_error' => 'transport error',
                'next_attempt_at' => se_db_now(se_wa_out_backoff_seconds($attempts))];
    }

    if (!empty($media_tmp)) { @unlink($media_tmp); }

    if (!empty($result['ok'])) {
        // Provider AUTHENTICATION evidence: the Cloud API token really sent.
        if (function_exists('se_secret_note_auth')) {
            se_secret_note_auth('wa_token', 0, true);
        }

        // Record the sent message in the conversation thread.
        se_wa_record_outbound($row, $conv, (string) ($result['wamid'] ?? ''));

        return ['status' => 'sent', 'attempts' => (int) $row['attempts'] + 1,
                'wamid' => (string) ($result['wamid'] ?? ''), 'sent_at' => date('Y-m-d H:i:s'),
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

    $media = $row['kind'] === 'media' && function_exists('se_media_get') ? se_media_get((int) ($row['media_id'] ?? 0)) : null;

    $CI->db->insert(db_prefix() . 'se_wa_messages', [
        'conversation_id' => (int) $conv->id,
        'brand_id'        => (int) $conv->brand_id,
        'wamid'           => $wamid,
        'direction'       => 'out',
        'source'          => 'cloud_api',
        'type'            => $row['kind'] === 'template' ? 'template' : ($media ? $media['kind'] : ($row['kind'] === 'interactive' ? 'interactive' : 'text')),
        'body'            => $row['body'],
        'media_ref'       => $media ? 'out:' . (int) $media['id'] : null,
        'template_name'   => $row['template_name'],
        'delivery_state'  => 'sent',
        // DISPLAY timestamps use the PHP application clock (business timezone,
        // Europe/Istanbul), matching how inbound messages are stamped from
        // Meta's Unix timestamps. se_db_now() (the DB clock, a different
        // timezone on this host) is reserved for queue/lease scheduling —
        // mixing the two rendered an outbound reply an hour behind its
        // inbound counterpart in the same thread.
        'sent_at'         => date('Y-m-d H:i:s'),
        'date_created'    => date('Y-m-d H:i:s'),
    ]);

    if ($media && function_exists('se_media_attach_message')) {
        se_media_attach_message((int) $media['id'], (int) $CI->db->insert_id(), (int) ($row['id'] ?? 0));
    }
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
/**
 * The template message a due reminder becomes. The appointment module enqueues
 * reminders without a template_ref; the approved consultation reminder
 * (registered by the journey template registry) is the default, and its two
 * placeholders are the patient's first name and the appointment time.
 */
function se_wa_reminder_message(array $rem, $appt)
{
    $template = trim((string) ($rem['template_ref'] ?? ''));
    if ($template === '') {
        $template = SE_WA_DEFAULT_REMINDER_TEMPLATE;
    }

    $CI   = &get_instance();
    $name = '';
    if (!empty($appt->rel_id)) {
        $CI->db->select('name')->where('id', (int) $appt->rel_id);
        $lead = $CI->db->get(db_prefix() . 'leads')->row();
        if ($lead && trim((string) $lead->name) !== '') {
            $parts = preg_split('/\s+/', trim((string) $lead->name));
            $name  = (string) $parts[0];
        }
    }
    if ($name === '') {
        $name = 'Merhaba';   // the template body already greets; never an empty placeholder
    }
    $when = !empty($appt->start_at) ? date('d.m.Y H:i', strtotime((string) $appt->start_at)) : '';

    return [
        'kind'              => 'template',
        'template'          => $template,
        'template_language' => 'tr',
        'variables'         => [$name, $when],
        'system'            => true,   // cron: no staff session, thread resolved by brand above
        'dedup_salt'        => 'reminder:' . (int) ($rem['id'] ?? 0),
    ];
}

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
        // The reminder row points at the appointment through appointment_id;
        // loading by the reminder's own id returned an unrelated (or no)
        // appointment, so every reminder ended as "no conversation" / failed.
        $CI->db->where('id', (int) ($rem['appointment_id'] ?? 0));
        $appt = $CI->db->get(db_prefix() . 'se_appointments')->row();

        if (!$appt || in_array((string) ($appt->status ?? ''), ['cancelled', 'no_show'], true)) {
            $CI->db->where('id', (int) $rem['id'])->update($table, [
                'state' => 'skipped', 'last_error' => $appt ? 'appointment cancelled' : 'appointment missing',
            ]);
            continue;
        }

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

        $res = se_wa_queue_message((int) $conv->id, se_wa_reminder_message($rem, $appt));

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
