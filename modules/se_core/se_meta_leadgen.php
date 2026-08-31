<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Meta Lead Ads — inbound leadgen webhook + processing.
 *
 * Webhook-driven (never polling). The receiver verifies the subscription, checks
 * X-Hub-Signature-256 over the RAW body, decodes JSON big-integer-safe (so 16–17
 * digit page/form/leadgen ids are never mangled), and stores the notification
 * durably (deduplicated on leadgen_id) before a fast 200. A cron job then routes
 * each event to its brand via the page/form map, fetches the lead's field_data,
 * maps it to a CRM lead, captures consent, and queues the CAPI conversion.
 *
 * GATED: the Graph fetch of field_data needs Advanced Access to `leads_retrieval`
 * plus a per-Page system-user token (App Review pending). Without a token the
 * event is HELD (not lost, not failed). A registered fetcher (tests) or a live
 * token drives the same downstream mapping path.
 *
 * Public route (once enabled): https://crm.roozbeh.com.tr/se_core/leadgen
 * Do NOT create the second Meta app or persistent credentials without approval.
 */

/* Bounds and retry policy. Meta's notifications are tiny; anything far larger
 * is a bug or an attempt to fill the table, and an unbounded LONGTEXT insert
 * per webhook is a cheap way to exhaust the account's disk quota. */
define('SE_LEADGEN_MAX_BODY_BYTES', 65536);
define('SE_LEADGEN_MAX_ATTEMPTS', 5);
define('SE_LEADGEN_LEASE_SECONDS', 900);
define('SE_LEADGEN_BACKOFF_BASE', 300);
define('SE_LEADGEN_BACKOFF_CAP', 21600);
define('SE_LEADGEN_GATED_RECHECK', 3600);

hooks()->add_action('after_cron_run', 'se_leadgen_process_pending');
hooks()->add_action('after_cron_run', 'se_leadgen_reconcile');

/* ---------- receiver: see modules/se_core/controllers/Leadgen.php -------- */

/* ONE secret source: the FILE secret provider (se_secret_read).
 *
 * Enforcement used to read tbloptions (se_meta_app_secret,
 * se_meta_webhook_verify_token, se_meta_page_token_<brand>) while the
 * credentials UI reported the file provider, so the UI could say "installed"
 * while the webhook rejected everything — or the reverse. These accessors are
 * now the ONLY way this module reads a Meta secret. The legacy option values
 * are PRESERVED but never read again; activation is dropping a secret FILE
 * (providers meta_app / meta_verify / meta_page), no code change. Absent file
 * => '' => every check fails CLOSED. */

/** Meta app secret (signature verification + appsecret_proof). File provider only. */
function se_meta_app_secret()
{
    return se_secret_read('meta_app');
}

/** Meta webhook verify token. File provider only; '' fails verification closed. */
function se_meta_verify_token()
{
    return se_secret_read('meta_verify');
}

/** Per-brand Meta Page token, falling back to the shared file. Never an option. */
function se_meta_page_token($brand_id)
{
    $token = se_secret_read('meta_page', (int) $brand_id);

    return $token !== '' ? $token : se_secret_read('meta_page', 0);
}

/**
 * Per-brand Meta Conversions API token.
 *
 * Reads the FILE secret store first (meta_capi_<brand>, then the shared
 * meta_capi), so this matches se_capi_ready()/health exactly: "ready" now
 * truthfully implies the send path has a token. Legacy option storage
 * (se_meta_capi_token_<brand>, then se_meta_capi_token) is honoured last, only
 * so a pre-existing option-based install keeps working. The file store is the
 * documented, diagnosable location.
 */
function se_meta_capi_token($brand_id)
{
    $token = se_secret_read('meta_capi', (int) $brand_id);
    if ($token !== '') { return $token; }

    $token = se_secret_read('meta_capi', 0);
    if ($token !== '') { return $token; }

    // The Conversions API works with the SAME system-user token as Lead Ads
    // when the dataset is assigned to that system user (verified: events_received
    // = 1 to the correct dataset). So a brand with a Page/system-user token but
    // no dedicated CAPI token INHERITS it — exactly as WhatsApp inherits the
    // shared Meta App Secret. A dedicated meta_capi file still takes precedence.
    $page = se_secret_read('meta_page', (int) $brand_id);
    if ($page !== '') { return $page; }

    $page = se_secret_read('meta_page', 0);
    if ($page !== '') { return $page; }

    $opt = (string) get_option('se_meta_capi_token_' . (int) $brand_id);
    if ($opt !== '') { return $opt; }

    return (string) get_option('se_meta_capi_token');
}

/** Is a CAPI-capable token available (dedicated meta_capi, or inherited meta_page)? */
function se_capi_token_available($brand_id)
{
    return se_meta_capi_token((int) $brand_id) !== '';
}

/** True when CAPI has no dedicated token but inherits the Page/system-user one. */
function se_capi_token_inherited($brand_id)
{
    $own = se_secret_configured('meta_capi', (int) $brand_id) || se_secret_configured('meta_capi', 0);
    if ($own) { return false; }
    return se_secret_configured('meta_page', (int) $brand_id) || se_secret_configured('meta_page', 0);
}

function se_leadgen_verify_signature($raw_body, $header, $app_secret = null)
{
    $secret = $app_secret !== null ? $app_secret : se_meta_app_secret();
    if ($secret === '' || !is_string($header) || strpos($header, 'sha256=') !== 0) {
        return false;
    }
    return hash_equals('sha256=' . hash_hmac('sha256', $raw_body, $secret), $header);
}

/** GET verification decision: subscribe mode + non-empty configured token + constant-time match. */
function se_leadgen_verify_outcome($mode, $token)
{
    $expected = se_meta_verify_token();

    return $mode === 'subscribe' && $expected !== '' && hash_equals($expected, (string) $token);
}

/**
 * The POST pipeline over an already-read raw body, in the REQUIRED order:
 *
 *   1. 413  body-size limit — declared Content-Length AND actual bytes,
 *           before any decode and before the HMAC is computed;
 *   2. 401  X-Hub-Signature-256 over the EXACT raw bytes (missing/invalid);
 *   3. 400  JSON well-formedness — a body that json_decode() cannot parse to
 *           an array/object is refused WITHOUT touching storage. Well-formed
 *           payloads are still stored raw and fully parsed async by cron
 *           (durability first); this gate only rejects the un-parseable;
 *   4. 200  only after the durable, deduplicated store (duplicate = held
 *           already = accepted); anything else is an honest 500 so Meta
 *           redelivers.
 *
 * @return array{status:int,ok:bool,reason:string}
 */
function se_leadgen_receive_outcome($declared_length, $raw, $signature_header)
{
    if ((int) $declared_length > SE_LEADGEN_MAX_BODY_BYTES
        || ($raw !== false && strlen((string) $raw) > SE_LEADGEN_MAX_BODY_BYTES)) {
        return ['status' => 413, 'ok' => false, 'reason' => 'payload_too_large'];
    }

    $raw = (string) $raw;

    if (!se_leadgen_verify_signature($raw, $signature_header)) {
        return ['status' => 401, 'ok' => false, 'reason' => 'bad_signature'];
    }

    $decoded = json_decode($raw);

    if (json_last_error() !== JSON_ERROR_NONE || (!is_array($decoded) && !is_object($decoded))) {
        return ['status' => 400, 'ok' => false, 'reason' => 'malformed_json'];
    }

    /* 200 means DURABLY ACCEPTED. A failed insert must return 500 so Meta
     * redelivers; a duplicate is genuinely accepted (we already hold it). */
    $stored = se_leadgen_store_event($raw, true);

    if (!empty($stored['stored']) || !empty($stored['duplicate'])) {
        update_option('se_meta_last_webhook_at', se_db_now());

        return [
            'status' => 200,
            'ok'     => true,
            'reason' => empty($stored['duplicate']) ? 'accepted' : 'duplicate',
        ];
    }

    return ['status' => 500, 'ok' => false, 'reason' => 'not_stored'];
}

/** Big-integer-safe decode: keeps 16–17 digit ids as strings. */
function se_leadgen_decode($raw)
{
    return json_decode($raw, true, 512, JSON_BIGINT_AS_STRING) ?: [];
}

/** Extract routing ids from the first leadgen change. */
function se_leadgen_extract($payload)
{
    foreach (($payload['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $change) {
            if (($change['field'] ?? '') !== 'leadgen') {
                continue;
            }
            $v = $change['value'] ?? [];
            return [
                'leadgen_id' => (string) ($v['leadgen_id'] ?? ''),
                'page_id'    => (string) ($v['page_id'] ?? ''),
                'form_id'    => (string) ($v['form_id'] ?? ''),
                'created_time' => isset($v['created_time']) ? (int) $v['created_time'] : null,
            ];
        }
    }
    return ['leadgen_id' => '', 'page_id' => '', 'form_id' => '', 'created_time' => null];
}

/** Durable, deduplicated store keyed on leadgen_id. */
function se_leadgen_store_event($raw_body, $signature_valid)
{
    $CI = &get_instance();
    $table = db_prefix() . 'se_meta_leadgen_events';
    $ids = se_leadgen_extract(se_leadgen_decode($raw_body));

    if ($ids['leadgen_id'] === '') {
        return ['stored' => false, 'duplicate' => false];
    }

    $CI->db->where('leadgen_id', $ids['leadgen_id']);
    if ($CI->db->count_all_results($table) > 0) {
        return ['stored' => false, 'duplicate' => true];
    }

    $CI->db->insert($table, [
        'leadgen_id'      => $ids['leadgen_id'],
        'page_id'         => $ids['page_id'],
        'form_id'         => $ids['form_id'],
        'payload'         => $raw_body,
        'signature_valid' => $signature_valid ? 1 : 0,
        'state'           => 'pending',
        'received_at'     => date('Y-m-d H:i:s'),
    ]);
    return ['stored' => true, 'duplicate' => false];
}

/* ------------------------------ processing ------------------------------ */

$GLOBALS['SE_LEADGEN_FETCHER'] = null;

/** Register a field_data fetcher: callable(leadgen_id,brand_id):?array. Tests/live. */
function se_leadgen_register_fetcher(callable $f)
{
    $GLOBALS['SE_LEADGEN_FETCHER'] = $f;
}

/** appsecret_proof = HMAC-SHA256(access_token, app_secret). Required on Graph calls. */
function se_leadgen_appsecret_proof($token, $app_secret)
{
    return hash_hmac('sha256', (string) $token, (string) $app_secret);
}

/**
 * Map a page+form pair to its brand and field map. Null when unmapped.
 *
 * BOTH ids are required. Matching on form_id alone trusted one attacker-chosen
 * value from the webhook body to select a tenant, and Meta form ids are not
 * globally unique across pages. The mapping must be active and must be unique
 * for the pair; an ambiguous mapping is a configuration error and is refused
 * rather than resolved arbitrarily.
 *
 * @return array{brand_id:int,field_map:array,form_row_id:int}|null
 */
function se_leadgen_route($page_id, $form_id)
{
    if ((string) $page_id === '' || (string) $form_id === '') {
        return null;
    }

    $CI = &get_instance();

    $CI->db->where('page_id', (string) $page_id)
           ->where('form_id', (string) $form_id)
           ->where('active', 1);

    $forms = $CI->db->get(db_prefix() . 'se_meta_forms')->result();

    if (count($forms) !== 1) {
        return null;   // unmapped, or ambiguous -> park, never guess
    }

    $form = $forms[0];

    return [
        'brand_id'    => (int) $form->brand_id,
        'field_map'   => se_leadgen_sanitize_field_map(json_decode((string) $form->field_map_json, true)),
        'form_row_id' => (int) $form->id,
    ];
}

/**
 * CRM lead columns a Meta form is allowed to write.
 *
 * `field_map_json` is operator-supplied configuration, and the old code fed it
 * straight into an UPDATE, so a mapping could target any column on tblleads —
 * brand_id, consent_ads, the immutable first-touch attribution columns. Only
 * these plain contact columns may ever be written from an ad form.
 */
function se_leadgen_allowed_lead_columns()
{
    return ['name', 'email', 'phonenumber', 'title', 'company', 'city',
            'country', 'zip', 'address', 'state', 'description', 'website'];
}

/** Drop any mapping that targets a column outside the allowlist. */
function se_leadgen_sanitize_field_map($map)
{
    if (!is_array($map) || !$map) {
        return se_leadgen_default_field_map();
    }

    $allowed = se_leadgen_allowed_lead_columns();
    $clean   = [];

    foreach ($map as $metaField => $leadColumn) {
        if (is_string($leadColumn) && in_array($leadColumn, $allowed, true)) {
            $clean[strtolower((string) $metaField)] = $leadColumn;
        }
    }

    return $clean ?: se_leadgen_default_field_map();
}

/** Default Meta field_data name -> CRM lead column. Overridable per form. */
function se_leadgen_default_field_map()
{
    return [
        'full_name'    => 'name',
        'email'        => 'email',
        'phone_number' => 'phonenumber',
    ];
}

/**
 * Fetch a lead's field_data. Uses a registered fetcher (tests) if present,
 * else a live Graph call with appsecret_proof when a Page token exists, else
 * returns a gated marker so the event is HELD, not failed.
 */
function se_leadgen_fetch($leadgen_id, $brand_id)
{
    if (is_callable($GLOBALS['SE_LEADGEN_FETCHER'] ?? null)) {
        $data = call_user_func($GLOBALS['SE_LEADGEN_FETCHER'], $leadgen_id, $brand_id);
        return ['ok' => is_array($data), 'gated' => false, 'field_data' => $data ?: []];
    }

    // File secret provider only (see the accessors above the receiver).
    $token  = se_meta_page_token($brand_id);
    $secret = se_meta_app_secret();
    if ($token === '') {
        return ['ok' => false, 'gated' => true, 'field_data' => []]; // App Review pending
    }

    // Live path (only reached once a token exists — externally gated until then).
    // The token goes in the Authorization header, NOT the query string: a URL
    // reaches proxies, access logs, Referer headers and error text, and a Page
    // token in any of those is a disclosure.
    $version = get_option('se_meta_graph_version') ?: 'v23.0';
    $url = 'https://graph.facebook.com/' . $version . '/' . rawurlencode($leadgen_id)
         . '?fields=field_data,created_time'
         . '&appsecret_proof=' . rawurlencode(se_leadgen_appsecret_proof($token, $secret));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 429 and Meta's throttling code are transient: back off rather than burn
    // an attempt and eventually park the notification as failed.
    if ($code === 429 || $code === 613) {
        throw new SeLeadgenRateLimited('rate limited');
    }

    if ($code < 200 || $code >= 300) {
        update_option('se_meta_token_last_error_' . (int) $brand_id, 'graph HTTP ' . $code);
        return ['ok' => false, 'gated' => false, 'field_data' => []];
    }
    $decoded = json_decode((string) $body, true) ?: [];
    return ['ok' => true, 'gated' => false, 'field_data' => $decoded['field_data'] ?? []];
}

/**
 * Convert Meta field_data [{name,values:[]}] into mapped lead columns and a
 * consent DECISION.
 *
 * The previous rule ended in `|| $val !== ''`, so every non-empty answer —
 * including "no" and "hayır" — granted consent. The decision now comes solely
 * from se_consent_decide()'s affirmative allowlist: a negative answer is
 * recorded as an explicit withdrawal, and blank or unrecognised text is
 * `unknown`, which is not consent.
 *
 * The question identifier and the raw answer are carried out so the ledger can
 * record exactly what was asked and what was answered.
 *
 * @return array{lead:array,consent_state:string,consent_question:?string,consent_answer:?string}
 */
function se_leadgen_map_fields($field_data, $map)
{
    $map = se_leadgen_sanitize_field_map($map);

    $out             = [];
    $consentState    = SE_CONSENT_UNKNOWN;
    $consentQuestion = null;
    $consentAnswer   = null;

    foreach (($field_data ?: []) as $f) {
        $name = strtolower((string) ($f['name'] ?? ''));
        $val  = isset($f['values'][0]) ? (string) $f['values'][0] : '';

        if (isset($map[$name])) {
            $out[$map[$name]] = mb_substr($val, 0, 191);
        }

        if (!se_leadgen_is_consent_question($name)) {
            continue;
        }

        $decision = se_consent_decide($val);

        // First explicit answer wins; a later blank must not erase it, and a
        // later "unknown" must not upgrade a recorded refusal.
        if ($consentState === SE_CONSENT_UNKNOWN || $decision === SE_CONSENT_WITHDRAWN) {
            $consentState    = $decision;
            $consentQuestion = $name;
            $consentAnswer   = $val;
        }
    }

    return [
        'lead'             => $out,
        'consent_state'    => $consentState,
        'consent_question' => $consentQuestion,
        'consent_answer'   => $consentAnswer,
        // Back-compat for any caller still reading a boolean. Only a real
        // grant is true.
        'consent_ads'      => $consentState === SE_CONSENT_GRANTED,
    ];
}

/** Is this Meta field a consent/opt-in question? */
function se_leadgen_is_consent_question($name)
{
    foreach (['consent', 'opt_in', 'opt-in', 'optin', 'permission', 'izin', 'onay', 'kvkk', 'gdpr'] as $needle) {
        if (strpos($name, $needle) !== false) {
            return true;
        }
    }

    return false;
}

/** Exponential backoff with full jitter. */
function se_leadgen_backoff_seconds($attempts)
{
    $exp = SE_LEADGEN_BACKOFF_BASE * (2 ** max(0, (int) $attempts - 1));
    $exp = min($exp, SE_LEADGEN_BACKOFF_CAP);

    return random_int((int) ($exp / 2), (int) $exp);
}

/** Return events whose processing lease expired to the queue. */
function se_leadgen_recover_stale()
{
    $CI = &get_instance();

    $CI->db->where('state', 'processing')
           ->where('locked_at <', se_db_now(-SE_LEADGEN_LEASE_SECONDS))
           ->update(db_prefix() . 'se_meta_leadgen_events', [
               'state' => 'pending', 'locked_at' => null, 'locked_by' => null,
           ]);

    return (int) $CI->db->affected_rows();
}

/**
 * Atomically claim DUE events.
 *
 * `held` is included deliberately. A credential-gated notification was parked
 * as `held` and the drainer then only ever selected `pending`, so it was
 * stranded forever: configuring the token afterwards recovered nothing. A
 * gated event now reschedules itself and resumes automatically once the gate
 * opens, with no operator action.
 */
function se_leadgen_claim_batch($worker, $limit = 100)
{
    $CI    = &get_instance();
    $table = db_prefix() . 'se_meta_leadgen_events';
    $limit = max(1, (int) $limit);
    $now   = se_db_now();

    $CI->db->query(
        'UPDATE `' . $table . "` SET state='processing', locked_at=NOW()"
        . ', locked_by=' . $CI->db->escape($worker)
        . ', fence = fence + 1'
        . " WHERE state IN ('pending','held') AND signature_valid=1"
        . ' AND attempts < ' . (int) SE_LEADGEN_MAX_ATTEMPTS
        . ' AND (next_attempt_at IS NULL OR next_attempt_at <= ' . $CI->db->escape($now) . ')'
        . ' ORDER BY id ASC LIMIT ' . $limit
    );

    $CI->db->where('state', 'processing')->where('locked_by', $worker)->order_by('id', 'ASC');

    return $CI->db->get($table)->result_array();
}

/** Terminal, non-retryable outcome. */
function se_leadgen_permanent($ev, $reason)
{
    return [
        'state'           => 'failed',
        'attempts'        => (int) $ev['attempts'] + 1,
        'failure_class'   => 'permanent',
        'last_error'      => mb_substr($reason, 0, 255),
        'next_attempt_at' => null,
    ];
}

/** Provider throttling — transient, and must not consume the retry budget. */
class SeLeadgenRateLimited extends Exception {}

/** Queue health counters for the operator screen. */
function se_leadgen_health_counters()
{
    $CI = &get_instance();

    $CI->db->select('state, COUNT(*) AS c')->group_by('state');
    $rows = $CI->db->get(db_prefix() . 'se_meta_leadgen_events')->result_array();

    $out = ['pending' => 0, 'processing' => 0, 'held' => 0, 'processed' => 0, 'failed' => 0];

    foreach ($rows as $r) { $out[$r['state']] = (int) $r['c']; }

    return $out;
}

/**
 * Drain leadgen events: claimed, leased, fenced, retried with backoff.
 * after_cron_run passes a bool; coerce the limit.
 */
function se_leadgen_process_pending($limit = 100)
{
    $limit = (int) $limit; if ($limit < 1) { $limit = 100; }

    $CI    = &get_instance();
    $table = db_prefix() . 'se_meta_leadgen_events';

    se_leadgen_recover_stale();

    $worker = substr(md5(uniqid((string) getmypid(), true)), 0, 24);
    $events = se_leadgen_claim_batch($worker, $limit);

    foreach ($events as $ev) {
        $update = null;

        try {
            $result = se_leadgen_process_event($ev);

            if ($result === 'held') {
                // Gated on a credential: hold WITHOUT consuming an attempt and
                // reschedule, so it resumes by itself once configured.
                $update = [
                    'state'           => 'held',
                    'attempts'        => (int) $ev['attempts'],
                    'failure_class'   => 'gated',
                    'last_error'      => 'credential gated; will resume automatically',
                    'next_attempt_at' => se_db_now(SE_LEADGEN_GATED_RECHECK),
                ];
            } elseif ($result === 'unmapped') {
                $update = se_leadgen_permanent($ev, 'no active page+form mapping');
            } elseif ($result === 'brand_mismatch') {
                $update = se_leadgen_permanent($ev, 'brand mismatch on existing meta_lead_id');
            } else {
                $update = [
                    'state' => 'processed', 'attempts' => (int) $ev['attempts'] + 1,
                    'failure_class' => null, 'last_error' => null, 'next_attempt_at' => null,
                ];
            }
        } catch (SeLeadgenRateLimited $e) {
            // Throttling is transient and self-healing: back off, and keep the
            // attempt budget intact so a throttled hour cannot exhaust it.
            $update = [
                'state'           => 'pending',
                'attempts'        => (int) $ev['attempts'],
                'failure_class'   => 'retryable',
                'last_error'      => 'rate limited by provider',
                'next_attempt_at' => se_db_now(SE_LEADGEN_BACKOFF_CAP),
            ];
        } catch (Exception $e) {
            $attempts = (int) $ev['attempts'] + 1;
            $update = [
                'state'           => $attempts >= SE_LEADGEN_MAX_ATTEMPTS ? 'failed' : 'pending',
                'attempts'        => $attempts,
                'failure_class'   => 'retryable',
                'last_error'      => 'processing error',
                'next_attempt_at' => se_db_now(se_leadgen_backoff_seconds($attempts)),
            ];
        }

        $update['processed_at'] = se_db_now();
        $update['locked_at']    = null;
        $update['locked_by']    = null;

        // Fenced: a worker whose lease expired cannot overwrite a newer result.
        $CI->db->where('id', $ev['id'])
               ->where('locked_by', $worker)
               ->where('fence', (int) $ev['fence'])
               ->update($table, $update);
    }

    return count($events);
}

/** Process one event: route -> fetch -> map -> upsert lead -> consent -> CAPI. */
function se_leadgen_process_event($ev)
{
    $route = se_leadgen_route($ev['page_id'], $ev['form_id']);
    if (!$route) {
        return 'unmapped';
    }

    $fetch = se_leadgen_fetch($ev['leadgen_id'], $route['brand_id']);
    if ($fetch['gated']) {
        return 'held';   // token/App Review pending — keep for later, transmit nothing
    }
    if (!$fetch['ok']) {
        throw new Exception('fetch failed');
    }

    $mapped = se_leadgen_map_fields($fetch['field_data'], $route['field_map']);

    $lead_id = se_leadgen_upsert_lead($route['brand_id'], $ev['leadgen_id'], $mapped['lead']);

    if ($lead_id === 'brand_mismatch') {
        return 'brand_mismatch';   // parked + alerted; never silently re-tenanted
    }

    if (!$lead_id) {
        return 'processed';
    }

    // A real webhook event became a real CRM lead: this is the Lead Ads
    // live_test_passed evidence (the intended workflow ran end to end).
    if (function_exists('se_webhook_record')) {
        se_webhook_record('meta', 'live_test');
    }

    // Record the consent DECISION, whichever way it went. An explicit refusal
    // is a withdrawal row in the ledger, not an absence of data — that is what
    // makes "we asked and they said no" provable later.
    if ($mapped['consent_state'] === SE_CONSENT_GRANTED) {
        se_consent_grant((int) $route['brand_id'], (int) $lead_id, 'ads', 'meta_lead_ads',
            $mapped['consent_question'], $mapped['consent_answer']);
    } elseif ($mapped['consent_state'] === SE_CONSENT_WITHDRAWN) {
        se_consent_withdraw((int) $route['brand_id'], (int) $lead_id, 'ads', 'meta_lead_ads',
            $mapped['consent_question'], $mapped['consent_answer']);
    }
    // SE_CONSENT_UNKNOWN (blank, missing question, unrecognised text): nothing
    // is recorded as granted and nothing is queued.

    // Queue the CAPI "Lead" conversion ONLY on an affirmative decision.
    if (function_exists('se_outbox_queue') && $mapped['consent_state'] === SE_CONSENT_GRANTED) {
        foreach (se_outbox_destinations_for_brand($route['brand_id']) as $dest) {
            se_outbox_queue($route['brand_id'], $lead_id, $dest, 'Lead');
        }
    }

    return 'processed';
}

/**
 * Upsert a lead, deduplicated on meta_lead_id.
 *
 * BRAND MOVES ARE REFUSED. Dedup on meta_lead_id is global, and the old code
 * then wrote brand_id from the incoming route — so a webhook naming an id that
 * already existed under Brand A silently moved that lead, and all of its
 * history, into Brand B. A mismatch is now parked and alerted instead.
 *
 * @return int|string lead id, or the string 'brand_mismatch'
 */
function se_leadgen_upsert_lead($brand_id, $leadgen_id, $fields)
{
    $CI = &get_instance();
    $table = db_prefix() . 'leads';

    // Only allowlisted contact columns can ever be written from an ad form.
    $fields = array_intersect_key($fields, array_flip(se_leadgen_allowed_lead_columns()));

    $CI->db->where('meta_lead_id', (string) $leadgen_id);
    $existing = $CI->db->get($table)->row();

    if ($existing && (int) $existing->brand_id !== (int) $brand_id && (int) $existing->brand_id !== 0) {
        update_option('se_meta_token_last_error_' . (int) $brand_id,
            'leadgen brand mismatch on meta_lead_id (parked)');
        log_activity('SE leadgen brand mismatch parked [lead ' . (int) $existing->id . ']');

        return 'brand_mismatch';
    }

    $data = array_merge($fields, [
        'brand_id'     => (int) $brand_id,
        'meta_lead_id' => (string) $leadgen_id,
    ]);

    if ($existing) {
        $CI->db->where('id', (int) $existing->id)->update($table, $data);
        return (int) $existing->id;
    }

    /* A configured, VALID status and source.
     *
     * These were hard-coded to 0, which is not a real lead status or source in
     * Perfex: every Lead Ads lead landed outside the pipeline, invisible to
     * every report and every kanban column. */
    $data['name']   = $data['name'] ?? ('Meta Lead ' . $leadgen_id);
    $data['status'] = se_leadgen_default_status((int) $brand_id);
    $data['source'] = se_leadgen_default_source((int) $brand_id);
    $data['addedfrom'] = 0;
    $data['dateadded'] = date('Y-m-d H:i:s');
    $CI->db->insert($table, $data);
    return (int) $CI->db->insert_id();
}

/**
 * Kept as a thin alias so nothing that still calls it bypasses the ledger.
 * New code should call se_consent_grant() directly.
 *
 * @deprecated use se_consent_grant()
 */
function se_leadgen_capture_consent($brand_id, $lead_id, $question = null, $answer = null)
{
    return se_consent_grant((int) $brand_id, (int) $lead_id, 'ads', 'meta_lead_ads', $question, $answer);
}

/* Reconciliation lookback and page size. A missed webhook (Meta outage, our
 * downtime) means a lead exists on the form but never reached us; reconciliation
 * re-fetches recent leads per mapped form and upserts them idempotently. The
 * window is bounded so a first run can never pull unlimited history. */
define('SE_LEADGEN_RECONCILE_LOOKBACK_SECONDS', 259200); // 72h default safety window
define('SE_LEADGEN_RECONCILE_PAGE_SIZE', 50);
define('SE_LEADGEN_RECONCILE_MAX_PAGES', 20);

$GLOBALS['SE_LEADGEN_LIST_FETCHER'] = null;

/**
 * Register a form-leads lister for reconciliation:
 *   callable(string $form_id, int $brand_id, int $since_ts, ?string $cursor): array
 * returning ['leads' => [{id, created_time, field_data}], 'next' => ?string].
 * Tests inject a deterministic lister; the live Graph path is used otherwise.
 */
function se_leadgen_register_list_fetcher(callable $f)
{
    $GLOBALS['SE_LEADGEN_LIST_FETCHER'] = $f;
}

/**
 * List recent leads for one form since a timestamp, one page at a time.
 *
 * Live path: GET /{form_id}/leads with a Page/system-user token (Authorization
 * header, never the query string) and appsecret_proof. Gated returns a marker
 * so the caller records an ATTEMPT, not a false success.
 *
 * @return array{ok:bool,gated:bool,leads:array,next:?string}
 */
function se_leadgen_list_form_leads($form_id, $brand_id, $since_ts, $cursor = null)
{
    if (is_callable($GLOBALS['SE_LEADGEN_LIST_FETCHER'] ?? null)) {
        $r = call_user_func($GLOBALS['SE_LEADGEN_LIST_FETCHER'], (string) $form_id, (int) $brand_id, (int) $since_ts, $cursor);

        return ['ok' => is_array($r), 'gated' => false,
                'leads' => $r['leads'] ?? [], 'next' => $r['next'] ?? null];
    }

    $token  = se_meta_page_token($brand_id);
    $secret = se_meta_app_secret();
    if ($token === '') {
        return ['ok' => false, 'gated' => true, 'leads' => [], 'next' => null]; // App Review / token pending
    }

    $version = get_option('se_meta_graph_version') ?: 'v23.0';
    $url = 'https://graph.facebook.com/' . $version . '/' . rawurlencode((string) $form_id) . '/leads'
         . '?fields=id,created_time,field_data'
         . '&limit=' . (int) SE_LEADGEN_RECONCILE_PAGE_SIZE
         . '&filtering=' . rawurlencode(json_encode([[
             'field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => (int) $since_ts,
         ]]))
         . '&appsecret_proof=' . rawurlencode(se_leadgen_appsecret_proof($token, $secret));

    if ($cursor) {
        $url .= '&after=' . rawurlencode((string) $cursor);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 429 || $code === 613) {
        throw new SeLeadgenRateLimited('rate limited');
    }
    if ($code < 200 || $code >= 300) {
        update_option('se_meta_token_last_error_' . (int) $brand_id, 'graph list HTTP ' . $code);

        return ['ok' => false, 'gated' => false, 'leads' => [], 'next' => null];
    }

    $decoded = json_decode((string) $body, true) ?: [];

    return [
        'ok'    => true,
        'gated' => false,
        'leads' => $decoded['data'] ?? [],
        'next'  => $decoded['paging']['cursors']['after'] ?? null,
    ];
}

/**
 * Reconciliation: re-fetch recent leads for every mapped form and upsert them
 * idempotently, so a lead created while a webhook was missed still lands.
 *
 * Idempotent by design: each lead is upserted through se_leadgen_upsert_lead(),
 * which dedups on meta_lead_id — a lead the webhook already delivered is a
 * no-op UPDATE, never a duplicate. Per-form checkpoints (se_meta_reconcile_cursor_*)
 * advance only on a fully processed page; pagination is bounded; transient
 * failures back off; a revoked/expired token surfaces as a sanitized error and
 * does NOT advance the success timestamp.
 *
 * TWO DISTINCT TIMESTAMPS, never conflated:
 *   - se_meta_last_reconcile_at  : an ATTEMPT ran (heartbeat, always set).
 *   - se_meta_last_fetch_ok_at   : an AUTHENTICATED Graph fetch actually
 *                                  succeeded (what "Last successful fetch"
 *                                  must mean). Gated/failed runs never touch it.
 */
function se_leadgen_reconcile($limit = null)
{
    update_option('se_meta_last_reconcile_at', date('Y-m-d H:i:s'));

    $CI = &get_instance();
    $CI->db->where('active', 1);
    $forms = $CI->db->get(db_prefix() . 'se_meta_forms')->result_array();

    if (!$forms) {
        // A skipped attempt is NOT a successful reconciliation: record the exact
        // reason and outcome so the UI never shows a misleading green state.
        se_leadgen_record_reconcile_outcome('Skipped', 'no active Page/form mapping');
        return 0;   // nothing mapped yet — a truthful zero, not a fake success
    }

    $lookback = (int) (get_option('se_meta_reconcile_lookback_seconds') ?: SE_LEADGEN_RECONCILE_LOOKBACK_SECONDS);
    $upserted = 0;
    $any_ok   = false;
    $gated    = false;

    foreach ($forms as $form) {
        $form_id  = (string) $form['form_id'];
        $brand_id = (int) $form['brand_id'];

        $ckKey = 'se_meta_reconcile_cursor_' . preg_replace('/[^0-9]/', '', $form_id);
        $since = (int) (get_option($ckKey) ?: (time() - $lookback));
        $cursor  = null;
        $newest  = $since;

        for ($page = 0; $page < SE_LEADGEN_RECONCILE_MAX_PAGES; $page++) {
            $res = se_leadgen_list_form_leads($form_id, $brand_id, $since, $cursor);

            if ($res['gated']) { $gated = true; break; }
            if (!$res['ok'])   { break; }   // sanitized error already recorded; do not advance

            $any_ok = true;

            foreach ($res['leads'] as $lead) {
                $leadgen_id = (string) ($lead['id'] ?? '');
                if ($leadgen_id === '') { continue; }

                $created = (int) ($lead['created_time'] ?? 0);
                if ($created > $newest) { $newest = $created; }

                $route = se_leadgen_route($form['page_id'], $form_id);
                if (!$route) { continue; }

                $mapped  = se_leadgen_map_fields($lead['field_data'] ?? [], $route['field_map']);
                $lead_id = se_leadgen_upsert_lead($route['brand_id'], $leadgen_id, $mapped['lead']);

                if (is_int($lead_id) && $lead_id > 0) {
                    $upserted++;

                    if ($mapped['consent_state'] === SE_CONSENT_GRANTED) {
                        se_consent_grant((int) $route['brand_id'], (int) $lead_id, 'ads', 'meta_lead_ads',
                            $mapped['consent_question'], $mapped['consent_answer']);

                        if (function_exists('se_outbox_queue')) {
                            foreach (se_outbox_destinations_for_brand($route['brand_id']) as $dest) {
                                se_outbox_queue($route['brand_id'], $lead_id, $dest, 'Lead');
                            }
                        }
                    }
                }
            }

            $cursor = $res['next'];
            if (!$cursor) { break; }
        }

        // Advance the checkpoint only when we actually fetched for this form.
        if ($any_ok && !$gated) {
            update_option($ckKey, (string) $newest);
        }
    }

    // "Last successful fetch" means exactly that: an authenticated fetch worked.
    if ($any_ok) {
        update_option('se_meta_last_fetch_ok_at', date('Y-m-d H:i:s'));
        update_option('se_meta_token_last_error_0', '');
        se_leadgen_record_reconcile_outcome('Reconciled', 'upserted ' . (int) $upserted . ' lead(s)');
    } elseif ($gated) {
        // Mapped, but no Page/system-user token: the attempt was skipped, not
        // reconciled. Held, not lost — and reported truthfully as Skipped.
        se_leadgen_record_reconcile_outcome('Skipped', 'Meta Page access token missing (App Review pending)');
    } else {
        se_leadgen_record_reconcile_outcome('Skipped', 'no leads returned or provider error');
    }

    return $upserted;
}

/** Record the last reconcile attempt's result + reason + timestamp (never a secret). */
function se_leadgen_record_reconcile_outcome($result, $reason)
{
    update_option('se_meta_last_reconcile_result', (string) $result);
    update_option('se_meta_last_reconcile_reason', (string) $reason);
    update_option('se_meta_last_reconcile_at', date('Y-m-d H:i:s'));
}

/* ------------------------------- controls + health ---------------------- */

/** Per-brand CAPI on/off (default on). */
/**
 * Is live Meta CAPI transmission enabled for this brand?
 *
 * DEFAULTS TO DISABLED. It used to default to enabled when the option was
 * unset, so configuring a dataset id was enough to start transmitting: the
 * safe state was the one you had to remember to ask for. Turning a live ad
 * integration on must be a deliberate act.
 */
function se_capi_enabled($brand_id)
{
    return (int) get_option('se_capi_enabled_' . (int) $brand_id) === 1;
}

/**
 * Is the Meta CAPI leg READY for a brand?
 *
 * CAPI depends ONLY on a Conversions API token (provider meta_capi) and a
 * dataset id. It does NOT depend on the Lead Ads Page token or on Lead Ads
 * App Review — the two were historically conflated into one blocker, so a
 * fully-working CAPI setup looked broken because Lead Ads advanced access was
 * still pending. They are independent legs of the same app and are reported
 * independently everywhere.
 */
function se_capi_ready($brand_id)
{
    $CI = &get_instance();
    $CI->db->where('id', (int) $brand_id);
    $brand = $CI->db->get(db_prefix() . 'se_brands')->row();
    $dataset = $brand ? ($brand->meta_dataset_id ?? null) : null;

    // A configured-but-conflicting dataset id is NOT ready: transmitting to the
    // wrong dataset is worse than not transmitting. The guard below turns a
    // silent mis-route into an explicit blocker.
    if (se_capi_dataset_conflict((int) $brand_id) !== null) { return false; }

    return se_capi_token_available((int) $brand_id) && !empty($dataset);
}

/**
 * Dataset-drift guard.
 *
 * When an authoritative dataset id is recorded for the brand
 * (option se_meta_dataset_authoritative_<brand>) and the brand's stored
 * meta_dataset_id does NOT match it, this returns the authoritative id so the
 * caller can block transmission and show an explicit error. Returns null when
 * no authoritative value is recorded or when the two agree.
 *
 * This exists because the CAPI dataset (website Pixel/Dataset) and the
 * WhatsApp Marketing-Messages dataset are different Meta assets that are easy
 * to cross-wire; a wrong id silently sends web conversions to the wrong place.
 */
function se_capi_dataset_conflict($brand_id)
{
    // Single source of truth = the versioned asset registry, NOT a mutable
    // option. (The old se_meta_dataset_authoritative_<brand> option is no longer
    // read here; it survives only as a historical audit note.)
    $auth = function_exists('se_asset_dataset')
        ? (string) se_asset_dataset('web_capi', (int) $brand_id) : '';

    $CI = &get_instance();
    $CI->db->where('id', (int) $brand_id);
    $brand = $CI->db->get(db_prefix() . 'se_brands')->row();
    $dataset = $brand ? trim((string) ($brand->meta_dataset_id ?? '')) : '';

    // A forbidden (superseded/misassigned) id ALWAYS conflicts, independent of
    // the registry — it must never be transmittable for web CAPI.
    if (function_exists('se_asset_is_forbidden_web_capi') && se_asset_is_forbidden_web_capi($dataset)) {
        return $auth !== '' ? $auth : 'a valid web dataset';
    }

    return se_capi_dataset_conflict_decide($dataset, $auth);
}

/**
 * Pure conflict decision (no DB/registry), extracted for testing.
 *   - returns null when there is nothing to enforce ($authoritative empty)
 *     or the dataset is unset (a separate "no dataset" blocker) or they agree;
 *   - returns the authoritative id when the two disagree.
 */
function se_capi_dataset_conflict_decide($dataset, $authoritative)
{
    $dataset = trim((string) $dataset);
    $authoritative = trim((string) $authoritative);
    if ($authoritative === '' || $dataset === '') { return null; }
    return $dataset === $authoritative ? null : $authoritative;
}

/** Per-brand Meta integration health snapshot (for the health interface). */
function se_meta_health($brand_id)
{
    $CI = &get_instance();
    $brand_id = (int) $brand_id;

    $CI->db->where('id', $brand_id);
    $brand = $CI->db->get(db_prefix() . 'se_brands')->row();

    $CI->db->where('brand_id', $brand_id);
    $forms = $CI->db->get(db_prefix() . 'se_meta_forms')->result_array();

    $token     = se_meta_page_token($brand_id);   // file provider — same source as enforcement
    $appSecret = se_meta_app_secret();
    $verify    = se_meta_verify_token();
    $dataset   = $brand ? ($brand->meta_dataset_id ?? null) : null;
    // CAPI token: a dedicated meta_capi, OR the inherited Page/system-user token.
    $capiToken = se_capi_token_available($brand_id);
    $activeForms = count(array_filter($forms, function ($f) { return (int) $f['active'] === 1; }));

    $outbox = function_exists('se_outbox_health') ? se_outbox_health($brand_id) : [];

    // Lead Ads is ready to RECEIVE once the app secret + verify token are
    // installed (webhook verification passes) and a page+form mapping exists;
    // live lead RETRIEVAL additionally needs the Page token (App Review).
    $webhookReady = $appSecret !== '' && $verify !== '';
    $leadgenTestReady = $webhookReady && $activeForms > 0;

    return [
        'brand_id'          => $brand_id,
        'dataset_id'        => $dataset,
        'forms'             => array_map(function ($f) {
            return ['page_id' => $f['page_id'], 'form_id' => $f['form_id'], 'name' => $f['form_name'], 'active' => (int) $f['active']];
        }, $forms),
        'active_form_count' => $activeForms,

        // --- CAPI leg (independent of Lead Ads) ---
        'capi_token'        => $capiToken,
        'capi_token_inherited' => se_capi_token_inherited($brand_id),
        'capi_ready'        => se_capi_ready($brand_id),
        'capi_enabled'      => se_capi_enabled($brand_id),
        'capi_gated'        => !$capiToken,
        'last_capi_at'      => get_option('se_meta_last_capi_at') ?: null,
        // Dataset-drift guard: the authoritative dataset for this brand comes
        // from the versioned asset registry (single source of truth), and, if
        // they disagree, the id the brand is wrongly pointed at.
        'dataset_authoritative' => function_exists('se_asset_dataset') ? se_asset_dataset('web_capi', $brand_id) : null,
        'dataset_mm_api'    => function_exists('se_asset_dataset') ? se_asset_dataset('mm_api', $brand_id) : null,
        'dataset_conflict'  => se_capi_dataset_conflict($brand_id),

        // --- Lead Ads leg (independent of CAPI) ---
        'page_token'        => $token !== '',
        'app_secret'        => $appSecret !== '',
        'verify_token'      => $verify !== '',
        'webhook_ready'     => $webhookReady,
        'leadgen_test_ready' => $leadgenTestReady,
        // Live lead retrieval is gated when EITHER the Page token is absent OR
        // the token lacks the App-Review permission (leads_retrieval). A present
        // token does NOT imply retrieval is granted.
        'leadgen_gated'     => $token === '' || (int) get_option('se_meta_leadgen_review_gated') === 1,
        'leadgen_review_gated' => (int) get_option('se_meta_leadgen_review_gated') === 1,
        'leadgen_review_item'  => get_option('se_meta_leadgen_review_item') ?: null,
        'token_configured'  => $token !== '',       // back-compat alias
        // Back-compat: legacy callers/tests read externally_gated. It now means
        // exactly the LEAD ADS leg (live lead retrieval), never CAPI — the two
        // are reported independently everywhere else.
        'externally_gated'  => $token === '',
        'token_last_error'  => get_option('se_meta_token_last_error_' . $brand_id) ?: null,
        'last_webhook_at'   => get_option('se_meta_last_webhook_at') ?: null,
        'last_reconcile_at' => get_option('se_meta_last_reconcile_at') ?: null,
        'last_fetch_ok_at'  => get_option('se_meta_last_fetch_ok_at') ?: null,

        'outbox_pending'    => (int) ($outbox['pending'] ?? 0),
        'outbox_failed'     => (int) ($outbox['failed'] ?? 0),
        'outbox_sent'       => (int) ($outbox['sent'] ?? 0),

        // Evidence-based six-state webhook model (verify_token_installed …
        // live_test_passed). A verify-token file existing is NOT "verified".
        'webhook_state'     => function_exists('se_webhook_state') ? se_webhook_state('meta') : null,
    ];
}

/**
 * Configured default lead status for a brand, falling back to the first real
 * pipeline status. Never 0.
 */
function se_leadgen_default_status($brand_id)
{
    $configured = (int) get_option('se_meta_default_status_' . (int) $brand_id);

    if ($configured > 0) { return $configured; }

    $CI = &get_instance();
    $CI->db->select('id')->order_by('statusorder', 'ASC')->limit(1);
    $row = $CI->db->get(db_prefix() . 'leads_status')->row();

    return $row ? (int) $row->id : 0;
}

/** Configured default lead source for a brand, falling back to the first real one. */
function se_leadgen_default_source($brand_id)
{
    $configured = (int) get_option('se_meta_default_source_' . (int) $brand_id);

    if ($configured > 0) { return $configured; }

    $CI = &get_instance();
    $CI->db->select('id')->order_by('id', 'ASC')->limit(1);
    $row = $CI->db->get(db_prefix() . 'leads_sources')->row();

    return $row ? (int) $row->id : 0;
}

