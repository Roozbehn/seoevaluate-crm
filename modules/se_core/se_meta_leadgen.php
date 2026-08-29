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

/** Map a page/form to its brand + field map. Null when unmapped. */
function se_leadgen_route($page_id, $form_id)
{
    $CI = &get_instance();
    $CI->db->where('form_id', (string) $form_id)->where('active', 1);
    $form = $CI->db->get(db_prefix() . 'se_meta_forms')->row();
    if (!$form) {
        return null;
    }
    return [
        'brand_id'  => (int) $form->brand_id,
        'field_map' => json_decode((string) $form->field_map_json, true) ?: se_leadgen_default_field_map(),
    ];
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
    $version = get_option('se_meta_graph_version') ?: 'v23.0';
    $url = 'https://graph.facebook.com/' . $version . '/' . rawurlencode($leadgen_id)
         . '?fields=field_data,created_time'
         . '&access_token=' . rawurlencode($token)
         . '&appsecret_proof=' . rawurlencode(se_leadgen_appsecret_proof($token, $secret));

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        update_option('se_meta_token_last_error_' . (int) $brand_id, 'graph HTTP ' . $code);
        return ['ok' => false, 'gated' => false, 'field_data' => []];
    }
    $decoded = json_decode((string) $body, true) ?: [];
    return ['ok' => true, 'gated' => false, 'field_data' => $decoded['field_data'] ?? []];
}

/** Convert Meta field_data [{name,values:[]}] into mapped lead columns. */
function se_leadgen_map_fields($field_data, $map)
{
    $out = [];
    $consent = false;
    foreach (($field_data ?: []) as $f) {
        $name = strtolower($f['name'] ?? '');
        $val  = isset($f['values'][0]) ? (string) $f['values'][0] : '';
        if (isset($map[$name])) {
            $out[$map[$name]] = mb_substr($val, 0, 191);
        }
        // A consent/opt-in question maps to ad consent.
        if (strpos($name, 'consent') !== false || strpos($name, 'opt_in') !== false || strpos($name, 'optin') !== false) {
            $consent = in_array(strtolower($val), ['yes', 'true', '1', 'evet', 'onay'], true) || $val !== '';
        }
    }
    return ['lead' => $out, 'consent_ads' => $consent];
}

/** Drain pending leadgen events. after_cron_run passes a bool; coerce the limit. */
function se_leadgen_process_pending($limit = 100)
{
    $limit = (int) $limit; if ($limit < 1) { $limit = 100; }
    $CI = &get_instance();
    $table = db_prefix() . 'se_meta_leadgen_events';

    $CI->db->where('state', 'pending')->where('signature_valid', 1)
           ->order_by('id', 'ASC')->limit($limit);
    $events = $CI->db->get($table)->result_array();

    foreach ($events as $ev) {
        $state = 'processed';
        $error = null;
        try {
            $result = se_leadgen_process_event($ev);
            if ($result === 'held') {
                $state = 'held';
            } elseif ($result === 'unmapped') {
                $state = 'failed';
                $error = 'no active form mapping';
            }
        } catch (Exception $e) {
            $state = 'failed';
            $error = 'processing error';
        }
        $CI->db->where('id', $ev['id'])->update($table, [
            'state'        => $state,
            'attempts'     => (int) $ev['attempts'] + 1,
            'last_error'   => $error,
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
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
    if ($lead_id && $mapped['consent_ads']) {
        se_leadgen_capture_consent($route['brand_id'], $lead_id);
    }
    // Queue the CAPI "Lead" conversion (respects per-brand toggle + consent gate downstream).
    if ($lead_id && function_exists('se_outbox_queue') && $mapped['consent_ads']) {
        foreach (se_outbox_destinations_for_brand($route['brand_id']) as $dest) {
            se_outbox_queue($route['brand_id'], $lead_id, $dest, 'Lead');
        }
    }
    return 'processed';
}

/** Upsert a lead, deduplicated on meta_lead_id. Stamps brand + meta_lead_id (string). */
function se_leadgen_upsert_lead($brand_id, $leadgen_id, $fields)
{
    $CI = &get_instance();
    $table = db_prefix() . 'leads';

    $CI->db->where('meta_lead_id', (string) $leadgen_id);
    $existing = $CI->db->get($table)->row();

    $data = array_merge($fields, [
        'brand_id'     => (int) $brand_id,
        'meta_lead_id' => (string) $leadgen_id,
    ]);

    if ($existing) {
        $CI->db->where('id', (int) $existing->id)->update($table, $data);
        return (int) $existing->id;
    }

    $data['name']    = $data['name'] ?? ('Meta Lead ' . $leadgen_id);
    $data['status']  = 0;
    $data['source']  = 0;
    $data['addedfrom'] = 0;
    $data['dateadded'] = date('Y-m-d H:i:s');
    $CI->db->insert($table, $data);
    return (int) $CI->db->insert_id();
}

function se_leadgen_capture_consent($brand_id, $lead_id)
{
    $CI = &get_instance();
    $CI->db->where('id', (int) $lead_id)->update(db_prefix() . 'leads', ['consent_ads' => 1]);
    if (function_exists('se_consent_record')) {
        se_consent_record((int) $brand_id, 'lead', (int) $lead_id, 'ads', 'granted', null, 'meta_lead_ads');
    }
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
function se_capi_enabled($brand_id)
{
    $v = get_option('se_capi_enabled_' . (int) $brand_id);
    return $v === '' || $v === false ? true : (int) $v === 1;
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
