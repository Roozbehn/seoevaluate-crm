<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Live Instagram Send API transport.
 *
 * Sends through the Page-linked Instagram account using a PAGE access token
 * derived (per request, in memory only) from the system-user token that also
 * carries instagram_manage_messages. Registered lazily from the authoritative
 * gate (module load order cannot be trusted for eager registration).
 */

/** Derive the Page token for this brand's account; cached for the request. */
function se_ig_page_token($brand_id, $page_id)
{
    static $cache = [];
    $k = (int) $brand_id . ':' . $page_id;
    if (isset($cache[$k])) { return $cache[$k]; }

    $su = se_ig_token((int) $brand_id);
    if ($su === '' || $page_id === '') { return $cache[$k] = ''; }

    $version = get_option('se_meta_graph_version') ?: 'v23.0';
    $ch = curl_init('https://graph.facebook.com/' . $version . '/' . rawurlencode($page_id) . '?fields=access_token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $su]]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $d = json_decode((string) $raw, true) ?: [];

    return $cache[$k] = (string) ($d['access_token'] ?? '');
}

function se_ig_live_transport(array $m)
{
    $CI = &get_instance();
    $CI->db->where('ig_account_id', (string) $m['ig_account_id']);
    $acc = $CI->db->get(db_prefix() . 'se_ig_accounts')->row();
    $pageId = $acc ? (string) $acc->page_id : '';

    $token = se_ig_page_token((int) $m['brand_id'], $pageId);
    if ($token === '') {
        return ['ok' => false, 'mid' => '', 'code' => 0, 'error' => 'no page token'];
    }

    $payload = [
        'recipient' => ['id' => (string) $m['to']],
        'message'   => ['text' => (string) $m['body']],
    ];

    $version = get_option('se_meta_graph_version') ?: 'v23.0';
    $ch = curl_init('https://graph.facebook.com/' . $version . '/' . rawurlencode($pageId) . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'mid' => '', 'code' => 0, 'error' => 'network error: ' . mb_substr((string) $err, 0, 120)];
    }

    $body = json_decode((string) $raw, true) ?: [];
    $mid  = (string) ($body['message_id'] ?? '');

    if ($code >= 200 && $code < 300 && $mid !== '') {
        return ['ok' => true, 'mid' => $mid, 'code' => $code, 'error' => ''];
    }

    $msg = (string) ($body['error']['message'] ?? 'send failed');
    $sub = isset($body['error']['error_subcode']) ? ' subcode=' . (int) $body['error']['error_subcode'] : '';

    return ['ok' => false, 'mid' => '', 'code' => $code, 'error' => mb_substr($msg, 0, 180) . $sub];
}

/**
 * Register the live transport when a usable token exists for THIS brand (a
 * brand-scoped meta_page_<brand> counts — checking only the shared brand-0
 * file left the gate reporting no_transport while every credential passed).
 */
function se_ig_maybe_register_live_transport($brand_id = 0)
{
    if (!se_ig_transport_available() && function_exists('se_secret_read') && se_ig_token((int) $brand_id) !== '') {
        se_ig_register_transport('se_ig_live_transport');
    }
}

se_ig_maybe_register_live_transport();
