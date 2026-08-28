<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Meta Lead Ads inbound webhook — SCAFFOLD.
 *
 * Status: the receiver logic is written and safe to expose, but it CANNOT go
 * live until Meta App Review grants Advanced Access to `leads_retrieval` and a
 * per-Page system-user token exists. Until then the endpoint verifies the
 * subscription and stores raw notifications, but does not fetch lead data
 * (that call would 400 without the permission).
 *
 * What is real here:
 *   - GET verification (hub.challenge echo)
 *   - X-Hub-Signature-256 validation against the app secret
 *   - idempotent storage of the raw notification, keyed on leadgen_id
 * What is gated (marked TODO, not silently missing):
 *   - GET /{leadgen_id}?fields=... to fetch field_data  [needs App Review]
 *   - mapping field_data -> a lead, per form                [needs form map]
 *
 * Endpoint (once routed): https://crm.roozbeh.com.tr/se_core/leadgen
 */

hooks()->add_action('app_init', 'se_leadgen_maybe_handle');

function se_leadgen_maybe_handle()
{
    $CI  = &get_instance();
    $uri = $CI->uri->uri_string();

    if (strpos($uri, 'se_core/leadgen') !== 0) {
        return;
    }

    // --- GET: subscription verification -----------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $mode      = $CI->input->get('hub_mode');
        $token     = $CI->input->get('hub_verify_token');
        $challenge = $CI->input->get('hub_challenge');
        $expected  = get_option('se_meta_webhook_verify_token');

        if ($mode === 'subscribe' && $expected && hash_equals((string) $expected, (string) $token)) {
            header('Content-Type: text/plain');
            echo $challenge;
        } else {
            header('HTTP/1.1 403 Forbidden');
        }
        exit;
    }

    // --- POST: leadgen notification ---------------------------------------
    $raw = file_get_contents('php://input');

    // Signature check over the RAW body, before any parsing.
    $app_secret = get_option('se_meta_app_secret');
    $sig        = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if ($app_secret) {
        $expected_sig = 'sha256=' . hash_hmac('sha256', $raw, $app_secret);
        if (!hash_equals($expected_sig, $sig)) {
            header('HTTP/1.1 403 Forbidden');
            exit;
        }
    }

    // Return 200 immediately; process minimally and idempotently.
    http_response_code(200);

    $payload = json_decode($raw, true);
    if (!is_array($payload) || empty($payload['entry'])) {
        exit;
    }

    foreach ($payload['entry'] as $entry) {
        foreach (($entry['changes'] ?? []) as $change) {
            if (($change['field'] ?? '') !== 'leadgen') {
                continue;
            }
            $v         = $change['value'] ?? [];
            $leadgen_id = (string) ($v['leadgen_id'] ?? '');
            if ($leadgen_id === '') {
                continue;
            }

            // Idempotent store keyed on leadgen_id.
            $CI->db->where('meta_lead_id', $leadgen_id);
            if ($CI->db->count_all_results(db_prefix() . 'leads') > 0) {
                continue;
            }

            // TODO(App Review): GET /{version}/{leadgen_id}?fields=field_data,...
            // with the Page system-user token, then map field_data -> a lead
            // using the form's field map, stamping brand_id and meta_lead_id.
            log_activity('SE leadgen received (fetch pending App Review) [' . $leadgen_id . ']');
        }
    }

    exit;
}
