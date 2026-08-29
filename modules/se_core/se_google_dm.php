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
 * GATED: a live send needs a Google Cloud service-account access token (stored
 * in an option, never committed) plus a per-brand Google Ads customer id and a
 * conversion-action id. Without them, se_google_dm_send_event() returns a clear
 * non-fatal reason and the outbox row is held. A registered sender (tests) or a
 * real token drives the same payload path.
 *
 * POLICY: no clinical field (procedure/diagnosis/body area/photo/health) is ever
 * placed in a conversion — Google prohibits health-tied conversion data.
 */

define('SE_GDM_MIN_AGE_SECONDS', 21600);   // >= 6h after the click
define('SE_GDM_MAX_AGE_DAYS', 90);         // <= 90 days
define('SE_GDM_MAX_EVENTS', 2000);         // Data Manager per-request cap

// Poll in-flight ingest requests after core cron tasks (retry/result visibility).
hooks()->add_action('after_cron_run', 'se_gdm_poll_pending');

$GLOBALS['SE_GDM_SENDER'] = null;

/** Register a transport for tests/live: callable(url,payload):array{ok,code,body}. */
function se_gdm_register_sender(callable $s)
{
    $GLOBALS['SE_GDM_SENDER'] = $s;
}

/** Per-brand service-account access token (option; never committed). '' if gated. */
function se_gdm_access_token($brand_id)
{
    return (string) (get_option('se_google_sa_token_' . (int) $brand_id) ?: get_option('se_google_sa_token'));
}

/** Conversion-action id for a brand + event name (stage). Option-mapped. */
function se_gdm_conversion_action($brand_id, $event_name)
{
    $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $event_name));
    return (string) (get_option('se_google_conv_action_' . (int) $brand_id . '_' . $slug)
        ?: get_option('se_google_conv_action_' . (int) $brand_id));
}

/** Is the click old enough and not too old? Returns '' if OK, else a reason. */
function se_gdm_age_check($event_time)
{
    $age = time() - strtotime($event_time);
    if ($age < SE_GDM_MIN_AGE_SECONDS) {
        return 'click younger than 6h';
    }
    if ($age > SE_GDM_MAX_AGE_DAYS * 86400) {
        return 'click older than 90 days';
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

    $ad = [];
    foreach (['gclid', 'gbraid', 'wbraid'] as $k) {
        if (!empty($lead->$k)) {
            $ad[$k] = (string) $lead->$k;
        }
    }

    $identifiers = [];
    if ($consent_ads) {
        if (!empty($lead->email) && ($em = Se_hash::email($lead->email))) {
            $identifiers[] = ['emailAddress' => Se_hash::sha256($em)];      // SHA-256 hex
        }
        if (!empty($lead->phonenumber) && ($ph = Se_hash::phone($lead->phonenumber))) {
            $identifiers[] = ['phoneNumber' => Se_hash::sha256($ph)];
        }
    }

    $event = [
        'destinationReferences' => ['dest'],
        'transactionId'         => 'se-gdm-' . (int) $row['lead_id'] . '-' . $row['id'],
        'eventTimestamp'        => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['event_time'])),
        'eventSource'           => !empty($lead->ctwa_clid) ? 'MESSAGE' : 'WEB',
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
    $CI->db->where('id', $brand_id);
    $brand = $CI->db->get(db_prefix() . 'se_brands')->row();
    if (!$brand || empty($brand->google_ads_customer_id)) {
        return ['ok' => false, 'error' => 'brand has no google_ads_customer_id'];
    }

    $action = se_gdm_conversion_action($brand_id, $row['event_name']);
    if ($action === '') {
        return ['ok' => false, 'error' => 'no conversion action mapped'];
    }

    $token = se_gdm_access_token($brand_id);
    if ($token === '' && !is_callable($GLOBALS['SE_GDM_SENDER'] ?? null)) {
        return ['ok' => false, 'error' => 'google service account not configured (gated)'];
    }

    if ($age = se_gdm_age_check($row['event_time'])) {
        return ['ok' => false, 'error' => $age];
    }

    $CI->db->where('id', (int) $row['lead_id']);
    $lead = $CI->db->get(db_prefix() . 'leads')->row();
    if (!$lead) {
        return ['ok' => false, 'error' => 'lead no longer exists'];
    }
    $consent = (int) ($lead->consent_ads ?? 0) === 1;

    $event = se_gdm_build_event($row, $lead, $consent);
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
        $decoded = json_decode($r['body'] ?? '', true) ?: [];
        $request_id = $decoded['requestId'] ?? null;
        if ($request_id) {
            se_gdm_track_request((int) $brand_id, $request_id, count($payload['events']));
        }
        return ['ok' => true, 'error' => '', 'request_id' => $request_id];
    }

    return ['ok' => false, 'error' => 'HTTP ' . ($r['code'] ?? '?') . ': ' . substr((string) ($r['body'] ?? ''), 0, 300)];
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
        'created_at'   => date('Y-m-d H:i:s'),
    ]);
}

/** Poll pending ingest requests via requestStatus.retrieve (gated on token). */
function se_gdm_poll_pending($limit = 50)
{
    $limit = (int) $limit; if ($limit < 1) { $limit = 50; }
    $CI = &get_instance();
    $CI->db->where('status', 'submitted')->order_by('id', 'ASC')->limit($limit);
    $rows = $CI->db->get(db_prefix() . 'se_gdm_requests')->result_array();
    // Live polling activates with a token; without one there is nothing in flight.
    return count($rows);
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
function se_landing_token_create(array $click, $ttl_days = 30, $secret = null)
{
    $secret = $secret !== null ? $secret : se_landing_secret();
    if ($secret === '') {
        return '';
    }
    $payload = [
        'g'   => $click['gclid'] ?? null,
        'gb'  => $click['gbraid'] ?? null,
        'wb'  => $click['wbraid'] ?? null,
        'exp' => time() + (int) $ttl_days * 86400,
    ];
    $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $sig  = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $secret, true)), '+/', '-_'), '=');
    return $body . '.' . $sig;
}

/** Verify + decode a landing token. Returns click ids or null (invalid/expired). */
function se_landing_token_verify($token, $secret = null)
{
    $secret = $secret !== null ? $secret : se_landing_secret();
    if ($secret === '' || !is_string($token) || strpos($token, '.') === false) {
        return null;
    }
    [$body, $sig] = explode('.', $token, 2);
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $secret, true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) {
        return null;
    }
    return ['gclid' => $payload['g'] ?? null, 'gbraid' => $payload['gb'] ?? null, 'wbraid' => $payload['wb'] ?? null];
}

/** Stamp a lead with the click ids recovered from a landing token (first-touch). */
function se_landing_apply_to_lead($lead_id, $token)
{
    $click = se_landing_token_verify($token);
    if (!$click) {
        return false;
    }
    $CI = &get_instance();
    $update = [];
    foreach (['gclid', 'gbraid', 'wbraid'] as $k) {
        if (!empty($click[$k])) {
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

    $CI->db->where('brand_id', $brand_id)->order_by('id', 'DESC')->limit(1);
    $lastReq = $CI->db->get(db_prefix() . 'se_gdm_requests')->row();

    return [
        'brand_id'            => $brand_id,
        'customer_id'         => $brand ? ($brand->google_ads_customer_id ?? null) : null,
        'ga4_property_id'     => $brand ? ($brand->ga4_property_id ?? null) : null,
        'gsc_site_url'        => $brand ? ($brand->gsc_site_url ?? null) : null,
        'sa_token_configured' => $token !== '',
        'conversion_action'   => se_gdm_conversion_action($brand_id, 'Lead') ?: null,
        'last_request_id'     => $lastReq ? $lastReq->request_id : null,
        'last_request_status' => $lastReq ? $lastReq->status : null,
        'outbox_pending'      => (int) ($outbox['pending'] ?? 0),
        'outbox_failed'       => (int) ($outbox['failed'] ?? 0),
        'externally_gated'    => $token === '',
    ];
}
