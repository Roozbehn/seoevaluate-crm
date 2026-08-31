<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Live WhatsApp Cloud API transport.
 *
 * The outbound pipeline (outbound.php) is transport-agnostic: it calls whatever
 * was registered with se_wa_register_transport(). Tests register a fixture;
 * THIS file registers the real Graph API sender — but only when the Cloud API
 * token (secret provider `wa_token`) is actually configured, so a token-less
 * install stays honestly gated ('no_token') instead of failing at send time.
 *
 * The token is read per send from the file provider and never logged; Graph
 * error bodies are sanitized by the caller (token-shaped strings redacted).
 */

/** Send one message via the Cloud API. Shape: see the outbound call site. */
function se_wa_live_transport(array $m)
{
    $token = se_wa_cloud_token();

    if ($token === '') {
        return ['ok' => false, 'wamid' => '', 'code' => 0, 'error' => 'no cloud api token'];
    }

    if ($m['kind'] === 'template') {
        $components = [];
        $vars = array_values((array) ($m['variables'] ?? []));
        if ($vars) {
            $components[] = ['type' => 'body', 'parameters' => array_map(function ($v) {
                return ['type' => 'text', 'text' => (string) $v];
            }, $vars)];
        }
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => (string) $m['to'],
            'type'              => 'template',
            'template'          => [
                'name'     => (string) $m['template'],
                'language' => ['code' => 'tr'],
                'components' => $components,
            ],
        ];
    } else {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => (string) $m['to'],
            'type'              => 'text',
            'text'              => ['body' => (string) $m['body']],
        ];
    }

    $version = get_option('se_meta_graph_version') ?: 'v23.0';
    $url = 'https://graph.facebook.com/' . $version . '/'
         . rawurlencode((string) $m['phone_number_id']) . '/messages';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            // Header, never the query string: URLs land in proxy/access logs.
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'wamid' => '', 'code' => 0,
                'error' => 'network error: ' . mb_substr((string) $err, 0, 120)];
    }

    $body  = json_decode((string) $raw, true) ?: [];
    $wamid = (string) ($body['messages'][0]['id'] ?? '');

    if ($code >= 200 && $code < 300 && $wamid !== '') {
        return ['ok' => true, 'wamid' => $wamid, 'code' => $code, 'error' => ''];
    }

    // Sanitized provider error: message + code only, never the raw body (it can
    // quote the request back) and never anything token-shaped.
    $msg = (string) ($body['error']['message'] ?? 'send failed');
    $sub = isset($body['error']['error_subcode']) ? ' subcode=' . (int) $body['error']['error_subcode'] : '';

    return ['ok' => false, 'wamid' => '', 'code' => $code,
            'error' => mb_substr($msg, 0, 180) . $sub];
}

/**
 * Register the live transport when a token exists and nothing else (a test
 * fixture, a future alternative) has registered one first.
 *
 * Called LAZILY from the drain, not only at load: at module-load time the
 * se_core secret provider may not be loaded yet (module load order is not
 * guaranteed), which made this registration silently skip and the drain gate
 * report no_transport despite a valid token.
 */
function se_wa_maybe_register_live_transport()
{
    if (!se_wa_transport_available()
        && function_exists('se_secret_read')
        && se_wa_cloud_token() !== '') {
        se_wa_register_transport('se_wa_live_transport');
    }
}

se_wa_maybe_register_live_transport();   // still try eagerly when order permits
