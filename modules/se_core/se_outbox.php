<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Conversion outbox — producer hooks and concurrent-safe cron drainer.
 *
 * A pipeline stage change writes a row; cron drains it; nothing is sent inline
 * with a web request. The drainer is safe under overlapping cron workers: rows
 * are claimed atomically with a processing lease, so an event is delivered at
 * most once even if two crons run at the same time. A worker that dies mid-batch
 * leaves a stale lease that the next run recovers.
 *
 * Live sending needs per-brand credentials that only exist after Meta App Review
 * / Google service-account setup. Until then rows are held (pending) or parked
 * (skipped/failed) with a clear, non-sensitive reason — never silently lost.
 */

define('SE_OUTBOX_MAX_ATTEMPTS', 5);
define('SE_OUTBOX_LEASE_SECONDS', 900);   // 15 min: a lease older than this is stale
define('SE_OUTBOX_BATCH', 200);
define('SE_META_MAX_AGE_DAYS', 7);        // Meta rejects events older than 7 days
define('SE_GOOGLE_MIN_AGE_SECONDS', 21600); // Google needs >= 6h after the click
define('SE_GOOGLE_MAX_AGE_DAYS', 90);       // and <= 90 days

hooks()->add_action('lead_status_changed', 'se_outbox_on_status_change', 10, 1);
hooks()->add_action('lead_converted_to_customer', 'se_outbox_on_converted', 10, 1);
if (function_exists('se_cron_listener')) { se_cron_listener('se_outbox_drain'); } else { hooks()->add_action('after_cron_run', 'se_outbox_drain'); }

/**
 * Every eligible pipeline stage change becomes a conversion signal. Meta wants
 * all prior stages sent before a later one, so we emit on every transition. A
 * lead that is lost or junk, or lacks ad consent, produces nothing.
 */
function se_outbox_on_status_change($data)
{
    $lead_id = 0;
    if (is_array($data) && isset($data['lead_id'])) {
        $lead_id = (int) $data['lead_id'];
    } elseif (is_numeric($data)) {
        $lead_id = (int) $data;
    }
    if (!$lead_id) {
        return;
    }

    $CI = &get_instance();
    $CI->db->where('id', $lead_id);
    $lead = $CI->db->get(db_prefix() . 'leads')->row();

    if (!$lead) {
        return;
    }

    // Lost/junk leads never generate further conversions.
    if (function_exists('se_lead_blocks_conversion') && se_lead_blocks_conversion($lead)) {
        return;
    }

    // No ad consent, nothing leaves the CRM.
    if ((int) $lead->consent_ads !== 1) {
        return;
    }

    $CI->db->select('name')->where('id', (int) $lead->status);
    $status = $CI->db->get(db_prefix() . 'leads_status')->row();
    $event_name = $status ? $status->name : ('Stage ' . (int) $lead->status);

    foreach (se_outbox_destinations_for_brand((int) $lead->brand_id) as $destination) {
        se_outbox_queue((int) $lead->brand_id, $lead_id, $destination, $event_name);
    }
}

function se_outbox_on_converted($data)
{
    $lead_id = is_array($data) && isset($data['lead_id']) ? (int) $data['lead_id'] : 0;
    if ($lead_id) {
        se_outbox_on_status_change(['lead_id' => $lead_id]);
    }
}

/** Which destinations a brand actually has credentials for. */
function se_outbox_destinations_for_brand($brand_id)
{
    if ($brand_id <= 0) {
        return [];
    }

    $CI = &get_instance();
    $CI->db->where('id', $brand_id);
    $brand = $CI->db->get(db_prefix() . 'se_brands')->row();
    if (!$brand) {
        return [];
    }

    $out = [];

    // A destination is queued only when it is EXPLICITLY enabled and its
    // non-secret configuration is complete. Presence of an id alone used to be
    // enough, which queued events for brands nobody had turned on yet.
    if (!empty($brand->meta_dataset_id) && se_capi_enabled($brand_id)) {
        $out[] = 'meta_capi';
    }

    if (!empty($brand->google_ads_customer_id) && se_google_dm_enabled($brand_id)) {
        $out[] = 'google_dm';
    }

    return $out;
}

/* ---------------------------------------------------------------------------
 * Delivery state machine.
 *
 * DELIVERY SEMANTICS: at-least-once, with destination-side idempotency.
 * A worker can die after the platform accepted an event but before we record
 * success, so the row is retried and the event is sent again. That is safe
 * because event_id / transactionId are derived from immutable primary keys and
 * never change across retries, so Meta and Google de-duplicate on their side.
 * It is NOT at-most-once and must not be documented as such.
 *
 * FAILURE CLASSES
 *   gated      no credential / integration disabled / age window not open yet.
 *              Does NOT consume an attempt - an external gate is not a delivery
 *              failure, and burning the retry budget while waiting for App
 *              Review is how a queue silently dies.
 *   retryable  transport or 5xx. Consumes an attempt, backs off exponentially.
 *   permanent  malformed, unknown destination, past the platform's age limit.
 *              Parked immediately; retrying cannot help.
 * ------------------------------------------------------------------------- */

define('SE_OUTBOX_FAIL_GATED', 'gated');
define('SE_OUTBOX_FAIL_RETRYABLE', 'retryable');
define('SE_OUTBOX_FAIL_PERMANENT', 'permanent');

define('SE_OUTBOX_BACKOFF_BASE', 300);      // 5 min
define('SE_OUTBOX_BACKOFF_CAP', 21600);     // 6 h
define('SE_OUTBOX_GATED_RECHECK', 3600);    // re-look at a gated row hourly

/** A per-run worker identity. Not security-sensitive; just needs to be unique. */
function se_outbox_worker_id()
{
    return substr(md5(uniqid((string) getmypid(), true)), 0, 24);
}

/**
 * Exponential backoff with full jitter.
 *
 * Jitter matters: without it every row queued by the same cron tick retries on
 * exactly the same second, which is a self-inflicted thundering herd against
 * the platform that just rate-limited us.
 */
function se_outbox_backoff_seconds($attempts)
{
    $exp = SE_OUTBOX_BACKOFF_BASE * (2 ** max(0, (int) $attempts - 1));
    $exp = min($exp, SE_OUTBOX_BACKOFF_CAP);

    return random_int((int) ($exp / 2), (int) $exp);
}

/**
 * Return a stuck 'processing' row to 'pending' once its lease has expired, so a
 * worker that crashed mid-batch never strands its claim.
 */
function se_outbox_recover_stale()
{
    $CI = &get_instance();
    $cutoff = se_db_now(-SE_OUTBOX_LEASE_SECONDS);

    $CI->db->where('status', 'processing')
           ->where('locked_at <', $cutoff)
           ->update(db_prefix() . 'se_conversion_outbox', [
               'status'    => 'pending',
               'locked_at' => null,
               'locked_by' => null,
           ]);
}

/** Park pending rows already too old for Meta rather than sending sure rejects. */
function se_outbox_park_stale_pending()
{
    $CI = &get_instance();
    $CI->db->where('status', 'pending')
           ->where('destination', 'meta_capi')
           ->where('event_time <', se_db_now(-SE_META_MAX_AGE_DAYS * 86400))
           ->update(db_prefix() . 'se_conversion_outbox', [
               'status'        => 'skipped',
               'failure_class' => SE_OUTBOX_FAIL_PERMANENT,
               'error_code'    => 'event_too_old',
               'last_error'    => 'event older than ' . SE_META_MAX_AGE_DAYS . ' days',
           ]);
}

/**
 * Atomically claim up to $limit pending rows that are DUE.
 *
 * Each claim also bumps `fence`. The fence is what stops a worker whose lease
 * expired mid-flight from later overwriting the result of the worker that took
 * over: every terminal write below is conditioned on the fence it claimed with,
 * so a stale worker's UPDATE matches zero rows.
 */
function se_outbox_claim_batch($worker, $limit = SE_OUTBOX_BATCH)
{
    $CI = &get_instance();
    $table = db_prefix() . 'se_conversion_outbox';
    $limit = max(1, (int) $limit);
    $now   = se_db_now();

    $CI->db->query(
        'UPDATE `' . $table . "` SET status='processing', locked_at=NOW()"
        . ', locked_by=' . $CI->db->escape($worker)
        . ', fence = fence + 1'
        . " WHERE status='pending' AND attempts < " . (int) SE_OUTBOX_MAX_ATTEMPTS
        . ' AND (next_attempt_at IS NULL OR next_attempt_at <= ' . $CI->db->escape($now) . ')'
        . ' ORDER BY id ASC LIMIT ' . $limit
    );

    $CI->db->where('status', 'processing')
           ->where('locked_by', $worker)
           ->order_by('id', 'ASC');

    return $CI->db->get($table)->result_array();
}

/** Cron drainer entrypoint. */
function se_outbox_drain()
{
    se_outbox_recover_stale();
    se_outbox_park_stale_pending();

    $CI = &get_instance();
    $CI->load->library('se_core/se_hash');

    $worker  = se_outbox_worker_id();
    $claimed = se_outbox_claim_batch($worker, SE_OUTBOX_BATCH);

    foreach ($claimed as $row) {
        se_outbox_process_row($row, $worker);
    }

    return count($claimed);
}

/**
 * Write a terminal/next state for a claimed row.
 *
 * FENCED: the UPDATE names the id, the processing state, the claiming worker
 * AND the fence value the worker saw. A worker whose lease expired and whose
 * row was re-claimed by someone else will therefore update nothing, instead of
 * stamping a stale result over a newer one.
 *
 * @return int rows written (0 means this worker was fenced out)
 */
function se_outbox_finalize($row, $worker, array $data)
{
    $CI = &get_instance();

    $CI->db->where('id', (int) $row['id'])
           ->where('status', 'processing')
           ->where('locked_by', $worker)
           ->where('fence', (int) $row['fence'])
           ->update(db_prefix() . 'se_conversion_outbox', $data);

    return (int) $CI->db->affected_rows();
}

/**
 * Normalise a provider error into a code + category + short message.
 *
 * Never stores the echoed payload or anything token-shaped: a provider's error
 * body routinely quotes the request back, which would put the access token into
 * a database column that every dump then copies.
 */
function se_outbox_sanitize_error($class, $code, $message)
{
    $message = (string) $message;
    $message = preg_replace('/[A-Za-z0-9_\-]{24,}/', '[redacted]', $message);
    $message = preg_replace('/\s+/', ' ', $message);

    return [
        'failure_class' => $class,
        'error_code'    => mb_substr((string) $code, 0, 64),
        'last_error'    => mb_substr(trim($message), 0, 300),
    ];
}

/** Send one claimed row, then record the outcome and release the lease. */
function se_outbox_process_row($row, $worker = null)
{
    $worker = $worker ?: ($row['locked_by'] ?? '');

    $ok        = false;
    $class     = SE_OUTBOX_FAIL_RETRYABLE;
    $code      = 'unknown';
    $error     = '';
    $submitted = false;
    $requestId = null;

    // Consent gate, evaluated from the snapshot plus any later withdrawal.
    $consent = se_outbox_consent_allows_send($row);

    if (!$consent['ok']) {
        se_outbox_finalize($row, $worker, array_merge(
            se_outbox_sanitize_error(SE_OUTBOX_FAIL_PERMANENT, 'consent_blocked', $consent['reason']),
            ['status' => 'skipped', 'locked_at' => null, 'locked_by' => null]
        ));

        return 'skipped';
    }

    try {
        switch ($row['destination']) {
            case 'meta_capi':
                $result    = se_capi_send_event($row);
                $ok        = (bool) $result['ok'];
                $class     = $result['class'] ?? SE_OUTBOX_FAIL_RETRYABLE;
                $code      = $result['code'] ?? 'meta_error';
                $error     = (string) $result['error'];
                break;

            case 'meta_mm_capi':
                /* Business messaging events (action_source
                 * 'business_messaging') go to the MM dataset, never the web
                 * one. Separate sender, separate dataset, separate token —
                 * see se_capi_messaging.php for why folding them together
                 * would be a mistake. */
                $result    = function_exists('se_capi_messaging_send_event')
                    ? se_capi_messaging_send_event($row)
                    : ['ok' => false, 'error' => 'messaging CAPI sender not loaded',
                       'class' => SE_OUTBOX_FAIL_GATED, 'code' => 'not_configured'];
                $ok        = (bool) $result['ok'];
                $class     = $result['class'] ?? SE_OUTBOX_FAIL_RETRYABLE;
                $code      = $result['code'] ?? 'meta_mm_error';
                $error     = (string) $result['error'];
                break;

            case 'google_dm':
                $result    = function_exists('se_google_dm_send_event')
                    ? se_google_dm_send_event($row)
                    : ['ok' => false, 'error' => 'google_dm sender not configured',
                       'class' => SE_OUTBOX_FAIL_GATED, 'code' => 'not_configured'];
                $ok        = (bool) $result['ok'];
                $class     = $result['class'] ?? SE_OUTBOX_FAIL_RETRYABLE;
                $code      = $result['code'] ?? 'google_error';
                $error     = (string) $result['error'];
                $submitted = !empty($result['submitted']);
                $requestId = $result['request_id'] ?? null;
                break;

            default:
                $class = SE_OUTBOX_FAIL_PERMANENT;
                $code  = 'unknown_destination';
                $error = 'unknown destination';
        }
    } catch (Exception $e) {
        $class = SE_OUTBOX_FAIL_RETRYABLE;
        $code  = 'exception';
        $error = 'exception during send';   // never leak internals into the row
    }

    /* --- accepted-for-processing (Google): submitted, not yet confirmed --- */
    if ($submitted) {
        se_outbox_finalize($row, $worker, [
            'status'        => 'submitted',
            'submitted_at'  => se_db_now(),
            'request_id'    => $requestId,
            'failure_class' => null,
            'error_code'    => null,
            'last_error'    => null,
            'locked_at'     => null,
            'locked_by'     => null,
        ]);

        return 'submitted';
    }

    /* --- success ------------------------------------------------------- */
    if ($ok) {
        se_outbox_finalize($row, $worker, [
            'status'        => 'sent',
            'sent_at'       => se_db_now(),
            'failure_class' => null,
            'error_code'    => null,
            'last_error'    => null,
            'locked_at'     => null,
            'locked_by'     => null,
        ]);

        return 'sent';
    }

    /* --- gated: hold WITHOUT consuming an attempt ----------------------- */
    if ($class === SE_OUTBOX_FAIL_GATED) {
        se_outbox_finalize($row, $worker, array_merge(
            se_outbox_sanitize_error($class, $code, $error),
            [
                'status'          => 'pending',
                'attempts'        => (int) $row['attempts'],   // unchanged, deliberately
                'next_attempt_at' => se_db_now(SE_OUTBOX_GATED_RECHECK),
                'locked_at'       => null,
                'locked_by'       => null,
            ]
        ));

        return 'gated';
    }

    /* --- permanent ------------------------------------------------------ */
    if ($class === SE_OUTBOX_FAIL_PERMANENT) {
        se_outbox_finalize($row, $worker, array_merge(
            se_outbox_sanitize_error($class, $code, $error),
            ['status' => 'failed', 'attempts' => (int) $row['attempts'] + 1,
             'locked_at' => null, 'locked_by' => null]
        ));

        return 'failed';
    }

    /* --- retryable: exponential backoff with jitter --------------------- */
    $attempts = (int) $row['attempts'] + 1;
    $status   = $attempts >= SE_OUTBOX_MAX_ATTEMPTS ? 'failed' : 'pending';

    se_outbox_finalize($row, $worker, array_merge(
        se_outbox_sanitize_error($class, $code, $error),
        [
            'status'          => $status,
            'attempts'        => $attempts,
            'next_attempt_at' => se_db_now(se_outbox_backoff_seconds($attempts)),
            'locked_at'       => null,
            'locked_by'       => null,
        ]
    ));

    return $status;
}

/**
 * Integration-health counters for the outbox, optionally per brand.
 * Returns [status => count]. Used by the health interface, not by sending.
 */
function se_outbox_health($brand_id = null)
{
    $CI = &get_instance();
    if ($brand_id !== null) {
        $CI->db->where('brand_id', (int) $brand_id);
    }
    $CI->db->select('status, COUNT(*) as c')->group_by('status');
    $rows = $CI->db->get(db_prefix() . 'se_conversion_outbox')->result_array();

    $out = [];
    foreach ($rows as $r) {
        $out[$r['status']] = (int) $r['c'];
    }

    // Skipped rows are permanent non-deliveries (consent, no snapshot...) that
    // the pending/failed counters never showed — the Health page said
    // "healthy, 0 failed" while 7 of 7 conversions were skipped (audit T2).
    if ($brand_id !== null) {
        $CI->db->where('brand_id', (int) $brand_id);
    }
    $CI->db->select('error_code, COUNT(*) as c')->where('status', 'skipped')->group_by('error_code');
    $out['skipped_by_reason'] = [];
    foreach ($CI->db->get(db_prefix() . 'se_conversion_outbox')->result_array() as $r) {
        $out['skipped_by_reason'][(string) ($r['error_code'] ?: 'unknown')] = (int) $r['c'];
    }
    $out['skipped'] = (int) array_sum($out['skipped_by_reason']);

    return $out;
}
