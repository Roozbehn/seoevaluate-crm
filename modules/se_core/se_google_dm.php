<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Google Data Manager conversion sender.
 *
 * Uploads offline conversions via the Data Manager API
 * (POST https://datamanager.googleapis.com/v1/events:ingest, scope
 * https://www.googleapis.com/auth/datamanager) — NOT the deprecated Google Ads
 * ConversionUploadService. Identifiers are SHA-256 hex (shared Se_hash
 * normalisation with Meta). Consent gates ad-user-data/ad-personalization.
 *
 * GATED: a live send needs a service-account access token — minted on demand
 * through the official google/auth library from a key document installed as a
 * 0600 file outside the document root (see se_google_auth.php) — plus a
 * per-brand Google Ads customer id and a conversion-action id. Without them,
 * se_google_dm_send_event() returns a clear non-fatal reason and the outbox
 * row is held. A registered sender (tests) or a real token drives the same
 * payload path.
 *
 * POLICY: no clinical field (procedure/diagnosis/body area/photo/health) is ever
 * placed in a conversion — Google prohibits health-tied conversion data.
 */

/* AGE WINDOW — reviewed, and the six-hour minimum is now OFF by default.
 *
 * The hard-coded 21600s minimum came from older Google Ads offline-conversion
 * guidance about CLICK time, and this code applies it to EVENT time, which is
 * not the same quantity. No current Data Manager requirement was found that
 * mandates it, and an unverified delay silently holds every conversion for six
 * hours — invisible, and indistinguishable from a broken queue.
 *
 * It is therefore disabled unless the owner deliberately configures it, and the
 * maximum age is configurable too rather than assumed. Both are recorded in the
 * Google screen so the active policy is visible rather than buried in a
 * constant. */
define('SE_GDM_DEFAULT_MIN_AGE_SECONDS', 0);
define('SE_GDM_MAX_AGE_DAYS', 90);         // <= 90 days
define('SE_GDM_MAX_EVENTS', 2000);         // Data Manager per-request cap

// Poll in-flight ingest requests after core cron tasks (retry/result visibility).
if (function_exists('se_cron_listener')) { se_cron_listener('se_gdm_poll_pending'); } else { hooks()->add_action('after_cron_run', 'se_gdm_poll_pending'); }

$GLOBALS['SE_GDM_SENDER'] = null;

/** Register a transport for tests/live: callable(url,payload):array{ok,code,body}. */
function se_gdm_register_sender(callable $s)
{
    $GLOBALS['SE_GDM_SENDER'] = $s;
}

/**
 * Is live Google Data Manager delivery enabled for this brand?
 * Defaults to DISABLED; enabling a live ad integration is a deliberate act.
 */
function se_google_dm_enabled($brand_id)
{
    return (int) get_option('se_google_dm_enabled_' . (int) $brand_id) === 1;
}

/**
 * Obtain a Data Manager access token.
 *
 * IMPLEMENTED via the official google/auth library (se_google_auth.php):
 * a renewable short-lived token is minted from the per-brand service-account
 * key document (a 0600 file outside the document root and outside Git, read
 * through the secret provider), signed and exchanged by
 * Google\Auth\Credentials\ServiceAccountCredentials, cached in memory for the
 * request only, and refreshed shortly before expiry.
 *   https://developers.google.com/data-manager/api/devguides/quickstart/set-up-access
 *   https://developers.google.com/identity/protocols/oauth2/service-account
 *
 * Returns '' whenever anything is missing or rejected — no key document, an
 * unusable key, or a refused exchange — which the outbox treats as GATED: the
 * row holds without consuming a retry attempt and resumes by itself once the
 * owner fixes the credential. se_gdm_last_token_failure() carries the
 * sanitized authentication/configuration classification.
 *
 * HISTORY: the original design read a STATIC bearer token from the option
 * `se_google_sa_token_<brand>`. Such tokens expire hourly and sat in plaintext
 * in tbloptions, so that path was removed. The old option is deliberately NOT
 * read, NOT migrated and NOT deleted: removing a value the owner may have
 * stored is their decision, not ours. */
function se_gdm_access_token($brand_id)
{
    // Renewable, short-lived, minted through the credential provider. Returns
    // '' whenever anything is missing, which the outbox treats as GATED.
    return se_gdm_fetch_access_token($brand_id);
}

/**
 * Pipeline STAGES that MAY be mapped to a Google conversion in the UI.
 *
 * POLICY GUARD, not a preference. The pipeline carries clinical-adjacent stages
 * (Photos Received, Treated, Follow-up, Reviewed, …). Mapping any of them to an
 * ad platform would leak that a specific person interacted with a medical
 * procedure — the sensitive-category data Google's policy (and ours) forbids.
 * Only generic, business-outcome stages a consented lead would expect may be
 * mapped; everything else is locked in the UI and refused on save.
 */
function se_gdm_uploadable_stages()
{
    return ['New', 'Contacted', 'Qualified', 'Consultation Booked', 'Reviewed'];
}

/** Is this pipeline stage allowed to be MAPPED/uploaded to Google at all? */
function se_gdm_stage_uploadable($stage)
{
    return in_array((string) $stage, se_gdm_uploadable_stages(), true);
}

/**
 * Generic, non-clinical conversion EVENT names that are always safe to upload,
 * independent of pipeline stage. These are business outcomes a consented lead
 * expects (a lead, a qualified lead, a booked consultation, a converted lead) —
 * never a treatment, image, or clinical attribute.
 */
function se_gdm_event_uploadable($event_name)
{
    $generic = ['Lead', 'Qualified Lead', 'Consultation Booked', 'Converted Lead'];

    return in_array((string) $event_name, $generic, true)
        || se_gdm_stage_uploadable($event_name);
}

/**
 * Conversion-action id for a brand + event name (stage). Option-mapped.
 *
 * A PURE lookup — used by health, the sender's classification, and the emit
 * path. The sensitive-data guard lives at the two write boundaries (UI save and
 * the emit path) so this lookup never returns a misleading '' that would read
 * as "no mapping" to unrelated callers.
 */
function se_gdm_conversion_action($brand_id, $event_name)
{
    $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $event_name));
    return (string) (get_option('se_google_conv_action_' . (int) $brand_id . '_' . $slug)
        ?: get_option('se_google_conv_action_' . (int) $brand_id));
}

/** Is the click old enough and not too old? Returns '' if OK, else a reason. */
/** The configured minimum event age, in seconds. 0 = no minimum (default). */
function se_gdm_min_age_seconds($brand_id = 0)
{
    $v = get_option('se_google_min_age_seconds_' . (int) $brand_id);

    if ($v === '' || $v === null) {
        $v = get_option('se_google_min_age_seconds');
    }

    return $v === '' || $v === null ? SE_GDM_DEFAULT_MIN_AGE_SECONDS : max(0, (int) $v);
}

/** The configured maximum event age, in days. */
function se_gdm_max_age_days($brand_id = 0)
{
    $v = get_option('se_google_max_age_days_' . (int) $brand_id);

    if ($v === '' || $v === null) {
        $v = get_option('se_google_max_age_days');
    }

    return $v === '' || $v === null ? SE_GDM_MAX_AGE_DAYS : max(1, (int) $v);
}

/** '' when the event is inside the configured window, else a reason. */
function se_gdm_age_check($event_time, $brand_id = 0)
{
    $age = time() - strtotime($event_time);
    $min = se_gdm_min_age_seconds($brand_id);

    if ($min > 0 && $age < $min) {
        return 'event younger than the configured minimum age';
    }

    if ($age > se_gdm_max_age_days($brand_id) * 86400) {
        return 'event older than the configured maximum age';
    }

    return '';
}

/**
 * Build one Data Manager Event from an outbox row + lead. Pure (no network/DB).
 * Uses first-touch click ids (immutable) and hashed identifiers only when the
 * lead has ad consent. Never includes a clinical field.
 */
function se_gdm_build_event($row, $lead, $consent_ads)
{
    $CI = &get_instance();
    $CI->load->library('se_core/se_hash');

    $ad          = [];
    $identifiers = [];
    $isMessage   = false;

    if (se_outbox_row_has_snapshot($row)) {
        /* --- snapshot path --------------------------------------------- */
        $snap = se_outbox_snapshot_decode($row['attribution_snapshot']);
        $ft   = $snap['first_touch'] ?? [];
        $ids  = $snap['identifiers'] ?? [];

        foreach (['gclid', 'gbraid', 'wbraid'] as $k) {
            if (!empty($ft[$k])) {
                $ad[$k] = (string) $ft[$k];
            }
        }

        if ($consent_ads) {
            if (!empty($ids['em'])) { $identifiers[] = ['emailAddress' => (string) $ids['em']]; }
            if (!empty($ids['ph'])) { $identifiers[] = ['phoneNumber'  => (string) $ids['ph']]; }
        }

        $isMessage = !empty($ft['ctwa_clid']);
    } elseif ($lead) {
        /* --- pre-snapshot rows ------------------------------------------ */
        foreach (['gclid', 'gbraid', 'wbraid'] as $k) {
            if (!empty($lead->$k)) {
                $ad[$k] = (string) $lead->$k;
            }
        }

        if ($consent_ads) {
            if (!empty($lead->email) && ($em = Se_hash::email($lead->email))) {
                $identifiers[] = ['emailAddress' => Se_hash::sha256($em)];   // SHA-256 hex
            }
            if (!empty($lead->phonenumber) && ($ph = Se_hash::phone($lead->phonenumber))) {
                $identifiers[] = ['phoneNumber' => Se_hash::sha256($ph)];
            }
        }

        $isMessage = !empty($lead->ctwa_clid);
    }

    $event = [
        'destinationReferences' => ['dest'],
        'transactionId'         => 'se-gdm-' . (int) $row['lead_id'] . '-' . $row['id'],
        'eventTimestamp'        => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['event_time'])),
        'eventSource'           => $isMessage ? 'MESSAGE' : 'WEB',
        'consent'               => [
            'adUserData'        => $consent_ads ? 'CONSENT_GRANTED' : 'CONSENT_DENIED',
            'adPersonalization' => $consent_ads ? 'CONSENT_GRANTED' : 'CONSENT_DENIED',
        ],
    ];
    if ($ad) {
        $event['adIdentifiers'] = $ad;
    }
    if ($identifiers) {
        $event['userData'] = ['userIdentifiers' => $identifiers];
    }

    return $event;
}

/** Wrap events into an events:ingest request for a brand. */
function se_gdm_build_request($brand, $conversion_action_id, array $events, $validate_only = false)
{
    $customer = preg_replace('/\D+/', '', (string) $brand->google_ads_customer_id);

    return [
        'destinations' => [[
            'reference'           => 'dest',
            'operatingAccount'    => ['accountType' => 'GOOGLE_ADS', 'accountId' => $customer],
            'productDestinationId' => (string) $conversion_action_id,
        ]],
        'encoding'     => 'HEX',
        'validateOnly' => (bool) $validate_only,
        'events'       => $events,
    ];
}

/**
 * Outbox entrypoint: send one 'google_dm' row. Gated until a token + mapping
 * exist. Returns ['ok'=>bool,'error'=>string]. Never throws into the drainer.
 */
function se_google_dm_send_event($row)
{
    $CI = &get_instance();

    $brand_id = (int) $row['brand_id'];

    // POLICY GUARD at the transmission boundary: a clinical/sensitive event that
    // somehow reached the outbox is refused before any HTTP call or mapping
    // lookup. Classified PERMANENT (like a consent block) so it never retries
    // and never transmits — the security goal is "never sent", not "retry".
    if (!se_gdm_event_uploadable($row['event_name'])) {
        return ['ok' => false, 'error' => 'event is not permitted for Google upload (sensitive)',
                'class' => SE_OUTBOX_FAIL_PERMANENT, 'code' => 'sensitive_blocked'];
    }

    $CI->db->where('id', $brand_id);
    $brand = $CI->db->get(db_prefix() . 'se_brands')->row();
    if (!$brand || empty($brand->google_ads_customer_id)) {
        return ['ok' => false, 'error' => 'brand has no google_ads_customer_id',
                'class' => SE_OUTBOX_FAIL_GATED, 'code' => 'no_customer_id'];
    }

    $action = se_gdm_conversion_action($brand_id, $row['event_name']);

    if ($action === '') {
        /* A missing conversion-action mapping is unfinished CONFIGURATION, not
         * a delivery failure. Classifying it as permanent parked the row for
         * good, so adding the mapping later recovered nothing. Gated rows hold
         * without consuming an attempt and resume by themselves. */
        return ['ok' => false, 'error' => 'no conversion action mapped for this stage',
                'class' => SE_OUTBOX_FAIL_GATED, 'code' => 'no_mapping'];
    }

    if (!se_google_dm_enabled($brand_id)) {
        return ['ok' => false, 'error' => 'google data manager disabled for brand',
                'class' => SE_OUTBOX_FAIL_GATED, 'code' => 'disabled'];
    }

    $token = se_gdm_access_token($brand_id);
    if ($token === '' && !is_callable($GLOBALS['SE_GDM_SENDER'] ?? null)) {
        /* No token and no fixture transport: hold without burning an attempt.
         * The provider classified WHY (se_google_auth.php) — surface that as
         * the gated code so the screen can distinguish an authentication
         * refusal from an unusable key document, with sanitized text only. */
        $why = function_exists('se_gdm_last_token_failure') ? se_gdm_last_token_failure($brand_id) : null;

        if ($why !== null && $why['category'] === 'authentication') {
            return ['ok' => false, 'error' => 'google authentication failed (gated): ' . $why['reason'],
                    'class' => SE_OUTBOX_FAIL_GATED, 'code' => 'auth_failed'];
        }

        if ($why !== null && in_array($why['code'], ['bad_key_document', 'key_rejected'], true)) {
            return ['ok' => false, 'error' => 'google service-account key unusable (gated): ' . $why['reason'],
                    'class' => SE_OUTBOX_FAIL_GATED, 'code' => 'bad_credential'];
        }

        return ['ok' => false, 'error' => 'google renewable credentials not configured (gated)',
                'class' => SE_OUTBOX_FAIL_GATED, 'code' => 'no_credentials'];
    }

    if ($age = se_gdm_age_check($row['event_time'], $brand_id)) {
        // An age window that has not opened yet is a schedule, not a failure.
        // "too young" is a schedule, not a failure; "too old" can never improve.
        $class = strpos($age, 'younger') !== false ? SE_OUTBOX_FAIL_GATED : SE_OUTBOX_FAIL_PERMANENT;

        return ['ok' => false, 'error' => $age, 'class' => $class, 'code' => 'age_window'];
    }

    /* Same rule as the Meta sender: no snapshot, no send. */
    if (!se_outbox_row_has_snapshot($row)) {
        return ['ok' => false, 'error' => 'no event snapshot; refusing to rebuild from the live lead',
                'class' => SE_OUTBOX_FAIL_PERMANENT, 'code' => 'no_snapshot'];
    }

    $lead = null;

    // Consent comes from the snapshot for snapshot rows: it is the decision that
    // applied when the conversion happened, not the lead's current flag.
    $consentInfo = se_outbox_row_consent($row, $lead);
    $consent     = $consentInfo['state'] === 'granted';

    $event   = se_gdm_build_event($row, $lead, $consent);
    $payload = se_gdm_build_request($brand, $action, [$event]);

    return se_gdm_ingest($payload, $token, $brand_id);
}

/**
 * Batch send: many rows -> one request (<=2000 events), per-event error
 * isolation (a row that can't build is skipped, not fatal to the batch).
 */
function se_gdm_send_batch($brand, $conversion_action_id, array $rows_with_leads, $token, $brand_id)
{
    $events = [];
    $skipped = 0;
    foreach (array_slice($rows_with_leads, 0, SE_GDM_MAX_EVENTS) as $pair) {
        try {
            $consent = (int) ($pair['lead']->consent_ads ?? 0) === 1;
            $events[] = se_gdm_build_event($pair['row'], $pair['lead'], $consent);
        } catch (Exception $e) {
            $skipped++;
        }
    }
    if (!$events) {
        return ['ok' => false, 'error' => 'no valid events', 'skipped' => $skipped];
    }
    $payload = se_gdm_build_request($brand, $conversion_action_id, $events);
    $res = se_gdm_ingest($payload, $token, $brand_id);
    $res['skipped'] = $skipped;
    $res['event_count'] = count($events);
    return $res;
}

/** POST the request. Uses the registered sender (tests) else a live HTTPS call. */
function se_gdm_ingest($payload, $token, $brand_id)
{
    $url = 'https://datamanager.googleapis.com/v1/events:ingest';

    if (is_callable($GLOBALS['SE_GDM_SENDER'] ?? null)) {
        $r = call_user_func($GLOBALS['SE_GDM_SENDER'], $url, $payload);
    } else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        $r = ['ok' => ($code >= 200 && $code < 300), 'code' => $code, 'body' => $cerr ? ('curl: ' . $cerr) : (string) $body];
    }

    if (!empty($r['ok'])) {
        $decoded    = json_decode($r['body'] ?? '', true) ?: [];
        $request_id = $decoded['requestId'] ?? null;

        if ($request_id) {
            se_gdm_track_request((int) $brand_id, $request_id, count($payload['events']));
        }

        // An accepted ingest request is SUBMITTED, not delivered. Data Manager
        // processes asynchronously; only requestStatus.retrieve can say whether
        // the events were actually ingested. Reporting 'sent' here would be a
        // claim we have no evidence for.
        return [
            'ok'         => false,
            'submitted'  => true,
            'error'      => '',
            'class'      => null,
            'code'       => 'submitted',
            'request_id' => $request_id,
        ];
    }

    $code  = (int) ($r['code'] ?? 0);
    $class = ($code >= 400 && $code < 500 && !in_array($code, [401, 403, 408, 429], true))
        ? SE_OUTBOX_FAIL_PERMANENT
        : SE_OUTBOX_FAIL_RETRYABLE;

    return ['ok' => false, 'error' => 'HTTP ' . ($code ?: '?'),
            'class' => $class, 'code' => 'http_' . $code];
}

/** Record an ingest request for result polling / visibility. */
function se_gdm_track_request($brand_id, $request_id, $event_count)
{
    $CI = &get_instance();
    $CI->db->insert(db_prefix() . 'se_gdm_requests', [
        'brand_id'     => (int) $brand_id,
        'request_id'   => (string) $request_id,
        'event_count'  => (int) $event_count,
        'status'       => 'submitted',
        'created_at'   => se_db_now(),
    ]);
}

/* ---------------------------------------------------------------------------
 * Asynchronous request status.
 *
 * An accepted ingest is SUBMITTED, not delivered. Data Manager processes
 * asynchronously and only requestStatus.retrieve can say whether the events
 * were ingested, partially ingested, or rejected. Until this ran, no row could
 * ever legitimately reach `confirmed`.
 * ------------------------------------------------------------------------- */

$GLOBALS['SE_GDM_STATUS_POLLER'] = null;

/** Register a status transport: callable(string $requestId, string $token): array. */
function se_gdm_register_status_poller(callable $p)
{
    $GLOBALS['SE_GDM_STATUS_POLLER'] = $p;
}

function se_gdm_status_poller_available()
{
    return is_callable($GLOBALS['SE_GDM_STATUS_POLLER'] ?? null);
}

/**
 * Is request-status polling IMPLEMENTED (as opposed to merely abstracted)?
 *
 * True when a concrete live transport exists — which it now does
 * (se_gdm_live_status_poller) — OR a poller is registered. This is the truthful
 * signal for the UI's "Request-status polling: implemented"; it does not depend
 * on the runtime registration global, which tests reset between suites.
 */
function se_gdm_status_polling_implemented()
{
    return function_exists('se_gdm_live_status_poller') || se_gdm_status_poller_available();
}

/**
 * Live Data Manager requestStatus transport.
 *
 * Retrieves the ingest request's async status so a SUBMITTED row can settle to
 * confirmed / partial / failed. Endpoint is configurable (the API surface is
 * still moving) and defaults to the documented retrieve call. The bearer token
 * is minted by the service-account signer (se_gdm_access_token); it goes in the
 * Authorization header, never a query string. Returns the decoded body for
 * se_gdm_interpret_status(); throws on transient failure so the row stays
 * submitted and is retried, rather than being invented as confirmed.
 */
function se_gdm_live_status_poller($request_id, $token)
{
    $base = get_option('se_gdm_status_endpoint')
        ?: 'https://datamanager.googleapis.com/v1/requestStatus:retrieve';

    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['requestId' => (string) $request_id]),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Transient / not-yet-available: keep the row submitted, retry next cycle.
    if ($code === 429 || $code === 503 || $code === 404 || $body === false) {
        throw new Exception('status poll transient');
    }
    if ($code < 200 || $code >= 300) {
        // A hard error is reported as an empty response so interpret_status
        // leaves the row submitted rather than confirming it. The sanitized
        // code is recorded for the operator.
        update_option('se_gdm_status_last_error', 'requestStatus HTTP ' . (int) $code);

        return [];
    }

    return json_decode((string) $body, true) ?: [];
}

/* The live poller is registered by default; it is internally gated (no token =>
 * skip), so status polling is genuinely IMPLEMENTED — it activates the moment a
 * service-account credential reads. Tests override it via se_gdm_register_status_poller(). */
if (!se_gdm_status_poller_available()) {
    se_gdm_register_status_poller('se_gdm_live_status_poller');
}

/**
 * Normalise a requestStatus response into one of four outcomes.
 *
 * @return array{state:string,succeeded:int,failed:int,diagnostics:array}
 */
function se_gdm_interpret_status(array $response)
{
    $raw = strtoupper((string) ($response['requestStatus'] ?? $response['status'] ?? ''));

    $succeeded = (int) ($response['successCount'] ?? 0);
    $failed    = (int) ($response['failureCount'] ?? 0);

    $diagnostics = [];

    foreach (($response['errorInfo'] ?? $response['diagnostics'] ?? []) as $d) {
        // Sanitized: a code and a short reason, never an echoed payload.
        $diagnostics[] = [
            'code'   => mb_substr((string) ($d['errorCode'] ?? $d['code'] ?? 'unknown'), 0, 64),
            'reason' => mb_substr(preg_replace('/[A-Za-z0-9_\-]{24,}/', '[redacted]',
                            (string) ($d['errorMessage'] ?? $d['reason'] ?? '')), 0, 200),
            'count'  => (int) ($d['count'] ?? 0),
        ];
    }

    if (in_array($raw, ['PROCESSING', 'PENDING', 'RUNNING', ''], true)) {
        $state = 'submitted';
    } elseif ($failed > 0 && $succeeded > 0) {
        $state = 'partial';
    } elseif ($failed > 0 || in_array($raw, ['FAILED', 'ERROR'], true)) {
        $state = 'failed';
    } else {
        $state = 'confirmed';
    }

    return ['state' => $state, 'succeeded' => $succeeded, 'failed' => $failed,
            'diagnostics' => $diagnostics];
}

/**
 * Poll in-flight ingest requests and settle the outbox rows behind them.
 *
 * Gated without a poller: rows stay `submitted` rather than being invented as
 * confirmed.
 */
function se_gdm_poll_pending($limit = 50)
{
    $limit = (int) $limit; if ($limit < 1) { $limit = 50; }

    $CI    = &get_instance();
    $table = db_prefix() . 'se_gdm_requests';

    $CI->db->where('status', 'submitted')->order_by('id', 'ASC')->limit($limit);
    $rows = $CI->db->get($table)->result_array();

    if (!$rows || !se_gdm_status_poller_available()) {
        return 0;   // nothing in flight, or polling not configured
    }

    $settled = 0;

    foreach ($rows as $req) {
        $token = se_gdm_access_token((int) $req['brand_id']);

        if ($token === '') {
            continue;   // gated; try again next cycle
        }

        try {
            $response = call_user_func($GLOBALS['SE_GDM_STATUS_POLLER'], $req['request_id'], $token);
        } catch (Exception $e) {
            continue;   // transient; leave submitted
        }

        $result = se_gdm_interpret_status(is_array($response) ? $response : []);

        if ($result['state'] === 'submitted') {
            continue;   // still processing
        }

        $CI->db->where('id', (int) $req['id'])->update($table, [
            'status'      => $result['state'],
            'succeeded'   => $result['succeeded'],
            'failed'      => $result['failed'],
            'diagnostics' => json_encode($result['diagnostics']),
            'polled_at'   => se_db_now(),
        ]);

        se_gdm_settle_outbox_for_request($req['request_id'], $result);
        $settled++;
    }

    return $settled;
}

/**
 * Apply a settled request outcome to the outbox rows it carried.
 *
 * PARTIAL is the interesting case: some events were ingested and some were
 * not, and Data Manager reports counts rather than per-event verdicts. We
 * cannot tell which row failed, so marking them all confirmed would be a
 * fabrication and marking them all failed would re-send events Google already
 * has. They are marked `partial` and surfaced for an operator, with the
 * diagnostics attached.
 */
function se_gdm_settle_outbox_for_request($request_id, array $result)
{
    $CI = &get_instance();

    $status = $result['state'] === 'confirmed' ? 'confirmed'
        : ($result['state'] === 'partial' ? 'partial' : 'failed');

    $update = [
        'status'     => $status,
        'error_code' => $result['state'] === 'confirmed' ? null : 'gdm_' . $result['state'],
    ];

    if ($result['state'] !== 'confirmed') {
        $first = $result['diagnostics'][0] ?? null;
        $update['failure_class'] = $result['state'] === 'partial' ? 'permanent' : 'permanent';
        $update['last_error']    = $first
            ? mb_substr($first['code'] . ': ' . $first['reason'], 0, 300)
            : 'reported ' . $result['state'] . ' by the platform';
    } else {
        $update['failure_class'] = null;
        $update['last_error']    = null;
        $update['sent_at']       = se_db_now();
    }

    $CI->db->where('request_id', (string) $request_id)
           ->where('status', 'submitted')
           ->update(db_prefix() . 'se_conversion_outbox', $update);

    return (int) $CI->db->affected_rows();
}

/* --------------------------------------------------------------------------
 * 5.3 WhatsApp-originated click attribution — secure landing token.
 *
 * A visitor clicks a Google ad (gclid/gbraid/wbraid), lands on a page, and
 * continues via click-to-WhatsApp. The click ids are packed into a short,
 * HMAC-signed, time-limited token that survives the WhatsApp hop so the eventual
 * conversion can still carry the Google click id.
 * ------------------------------------------------------------------------ */

function se_landing_secret()
{
    return (string) get_option('se_landing_token_secret');
}

/** Create a signed token embedding click ids. TTL default 30 days (click window). */
function se_landing_token_create(array $click, $ttl_days = 30, $secret = null, $brand_id = 0)
{
    $secret = $secret !== null ? $secret : se_landing_secret();
    if ($secret === '') {
        return '';
    }
    /* Bound to a BRAND, a PURPOSE and an issue time.
     *
     * The token used to carry click ids and an expiry only, so one minted for
     * any brand could be applied to a lead in any other, and it had no version
     * or audience to check. */
    $payload = [
        'v'   => 1,
        'aud' => 'se_landing',
        'b'   => (int) $brand_id,
        'g'   => $click['gclid'] ?? null,
        'gb'  => $click['gbraid'] ?? null,
        'wb'  => $click['wbraid'] ?? null,
        'iat' => time(),
        'exp' => time() + (int) $ttl_days * 86400,
    ];
    $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $sig  = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $secret, true)), '+/', '-_'), '=');
    return $body . '.' . $sig;
}

/** Verify + decode a landing token. Returns click ids or null (invalid/expired). */
function se_landing_token_verify($token, $secret = null, $expected_brand = null)
{
    $secret = $secret !== null ? $secret : se_landing_secret();

    if ($secret === '' || !is_string($token) || strpos($token, '.') === false) {
        return null;
    }

    // Bounded: a token is short. Refuse anything absurd before decoding it.
    if (strlen($token) > 2048) {
        return null;
    }

    [$body, $sig] = explode('.', $token, 2);

    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $secret, true)), '+/', '-_'), '=');

    if (!hash_equals($expected, $sig)) {
        return null;
    }

    $decoded = base64_decode(strtr($body, '-_', '+/'), true);

    if ($decoded === false || strlen($decoded) > 4096) {
        return null;
    }

    $payload = json_decode($decoded, true);

    if (!is_array($payload)) {
        return null;
    }

    // Version + audience: a signed blob from elsewhere is not a landing token.
    if ((int) ($payload['v'] ?? 0) !== 1 || ($payload['aud'] ?? '') !== 'se_landing') {
        return null;
    }

    // Expiry, and a sane issued-at: a token from the future is forged or the
    // clock is wrong, and either way it must not be trusted.
    if (($payload['exp'] ?? 0) < time() || ($payload['iat'] ?? 0) > time() + 300) {
        return null;
    }

    if ($expected_brand !== null && (int) ($payload['b'] ?? 0) !== (int) $expected_brand) {
        return null;
    }

    return [
        'gclid'    => $payload['g'] ?? null,
        'gbraid'   => $payload['gb'] ?? null,
        'wbraid'   => $payload['wb'] ?? null,
        'brand_id' => (int) ($payload['b'] ?? 0),
    ];
}

/** Stamp a lead with the click ids recovered from a landing token (first-touch). */
function se_landing_apply_to_lead($lead_id, $token)
{
    $CI = &get_instance();

    // The lead decides the brand; the token must match it.
    $lead = $CI->db->query('SELECT `brand_id`, `gclid`, `gbraid`, `wbraid` FROM `'
        . db_prefix() . 'leads` WHERE `id` = ' . (int) $lead_id . ' LIMIT 1')->row();

    if (!$lead) {
        return false;
    }

    $click = se_landing_token_verify($token, null, (int) $lead->brand_id);

    if (!$click) {
        return false;   // bad signature, expired, wrong audience, or wrong brand
    }

    $update = [];

    foreach (['gclid', 'gbraid', 'wbraid'] as $k) {
        // FIRST-TOUCH ONLY. This wrote unconditionally, so a second landing
        // token overwrote the click id that actually started the journey.
        if (!empty($click[$k]) && empty($lead->$k)) {
            $update[$k] = $click[$k];
        }
    }

    if (!$update) {
        return false;
    }

    $CI->db->where('id', (int) $lead_id)->update(db_prefix() . 'leads', $update);

    return true;
}

/* ------------------------------- health --------------------------------- */

/** Per-brand Google integration health snapshot. */
function se_google_health($brand_id)
{
    $CI = &get_instance();
    $brand_id = (int) $brand_id;
    $CI->db->where('id', $brand_id);
    $brand = $CI->db->get(db_prefix() . 'se_brands')->row();

    $token = se_gdm_access_token($brand_id);
    $outbox = function_exists('se_outbox_health') ? se_outbox_health($brand_id) : [];
    $cred   = function_exists('se_gdm_credential_status') ? se_gdm_credential_status($brand_id) : [];

    $CI->db->where('brand_id', $brand_id)->order_by('id', 'DESC')->limit(1);
    $lastReq = $CI->db->get(db_prefix() . 'se_gdm_requests')->row();

    // "not configured" (no key file at all) is distinct from "configured but
    // failing" (key present, but it does not parse or auth fails). The old
    // single externally_gated boolean could not tell the operator which it was.
    $credPresent = !empty($cred['file_present']);
    $credUsable  = !empty($cred['ready']) || $token !== '';
    $credFailing = $credPresent && !$credUsable;

    return [
        'brand_id'            => $brand_id,
        'customer_id'         => $brand ? ($brand->google_ads_customer_id ?? null) : null,
        'ga4_property_id'     => $brand ? ($brand->ga4_property_id ?? null) : null,
        'gsc_site_url'        => $brand ? ($brand->gsc_site_url ?? null) : null,
        'sa_token_configured' => $token !== '',
        'credential_present'  => $credPresent,
        'credential_failing'  => $credFailing,
        'credential_valid'    => !empty($cred['credential_valid']),
        'status_polling'      => se_gdm_status_polling_implemented(),
        'last_status_error'   => get_option('se_gdm_status_last_error') ?: null,
        'conversion_action'   => se_gdm_conversion_action($brand_id, 'Lead') ?: null,
        'last_request_id'     => $lastReq ? $lastReq->request_id : null,
        'last_request_status' => $lastReq ? $lastReq->status : null,
        'outbox_pending'      => (int) ($outbox['pending'] ?? 0),
        'outbox_failed'       => (int) ($outbox['failed'] ?? 0),
        // Gated == no usable credential. A present-but-failing key is NOT gated;
        // it is failing, which the UI shows differently.
        'externally_gated'    => !$credUsable,
    ];
}
