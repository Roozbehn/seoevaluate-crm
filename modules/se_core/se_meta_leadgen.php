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

function se_leadgen_verify_signature($raw_body, $header, $app_secret = null)
{
    $secret = $app_secret !== null ? $app_secret : (string) get_option('se_meta_app_secret');
    if ($secret === '' || !is_string($header) || strpos($header, 'sha256=') !== 0) {
        return false;
    }
    return hash_equals('sha256=' . hash_hmac('sha256', $raw_body, $secret), $header);
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

    $token = (string) (get_option('se_meta_page_token_' . (int) $brand_id) ?: get_option('se_meta_page_token'));
    $secret = (string) get_option('se_meta_app_secret');
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

/** Reconciliation: records a heartbeat; a live fetch of missed leads is gated. */
function se_leadgen_reconcile($limit = 50)
{
    $limit = (int) $limit; if ($limit < 1) { $limit = 50; }
    update_option('se_meta_last_reconcile_at', date('Y-m-d H:i:s'));
    // Live reconciliation (re-fetch recent leads per form) activates with a token.
    return 0;
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

/** Per-brand Meta integration health snapshot (for the health interface). */
function se_meta_health($brand_id)
{
    $CI = &get_instance();
    $brand_id = (int) $brand_id;

    $CI->db->where('id', $brand_id);
    $brand = $CI->db->get(db_prefix() . 'se_brands')->row();

    $CI->db->where('brand_id', $brand_id);
    $forms = $CI->db->get(db_prefix() . 'se_meta_forms')->result_array();

    $token = (string) (get_option('se_meta_page_token_' . $brand_id) ?: get_option('se_meta_page_token'));

    $outbox = function_exists('se_outbox_health') ? se_outbox_health($brand_id) : [];

    return [
        'brand_id'          => $brand_id,
        'dataset_id'        => $brand ? ($brand->meta_dataset_id ?? null) : null,
        'forms'             => array_map(function ($f) {
            return ['page_id' => $f['page_id'], 'form_id' => $f['form_id'], 'name' => $f['form_name'], 'active' => (int) $f['active']];
        }, $forms),
        'token_configured'  => $token !== '',
        'token_last_error'  => get_option('se_meta_token_last_error_' . $brand_id) ?: null,
        'last_webhook_at'   => get_option('se_meta_last_webhook_at') ?: null,
        'last_reconcile_at' => get_option('se_meta_last_reconcile_at') ?: null,
        'outbox_pending'    => (int) ($outbox['pending'] ?? 0),
        'outbox_failed'     => (int) ($outbox['failed'] ?? 0),
        'outbox_sent'       => (int) ($outbox['sent'] ?? 0),
        'capi_enabled'      => se_capi_enabled($brand_id),
        'externally_gated'  => $token === '',   // no token => live fetch/send gated
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

