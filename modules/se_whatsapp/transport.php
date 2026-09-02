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
                'language' => ['code' => (string) ($m['template_language'] ?? '') !== ''
                    ? (string) $m['template_language'] : 'tr'],
                'components' => $components,
            ],
        ];
    } elseif ($m['kind'] === 'media' && !empty($m['media'])) {
        // Two steps: upload the bytes (multipart) to get a media id, then send
        // a message referencing it. The upload is repeated on every attempt —
        // Meta's ids are short-lived and a retry must not depend on one.
        $up = se_wa_upload_media((string) $m['phone_number_id'], $m['media'], $token);
        if (empty($up['ok'])) {
            return ['ok' => false, 'wamid' => '', 'code' => (int) $up['code'], 'error' => 'media upload: ' . $up['error']];
        }
        $kind = (string) $m['media']['kind'];
        $obj  = ['id' => (string) $up['id']];
        if ($kind !== 'audio' && (string) ($m['body'] ?? '') !== '') {
            $obj['caption'] = (string) $m['body'];
        }
        if ($kind === 'document' && !empty($m['media']['filename'])) {
            $obj['filename'] = (string) $m['media']['filename'];
        }
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => (string) $m['to'],
            'type'              => $kind,
            $kind               => $obj,
        ];
    } elseif ($m['kind'] === 'interactive') {
        // Reply buttons (Cloud API "interactive" type "button"). Shape validated
        // at queue time by se_wa_shape_interactive().
        $p = (array) ($m['payload'] ?? []);
        $interactive = [
            'type'   => 'button',
            'body'   => ['text' => (string) $m['body']],
            'action' => ['buttons' => array_map(function ($b) {
                return ['type' => 'reply', 'reply' => ['id' => (string) $b['id'], 'title' => (string) $b['title']]];
            }, array_values((array) ($p['buttons'] ?? [])))],
        ];
        if (!empty($p['footer'])) {
            $interactive['footer'] = ['text' => (string) $p['footer']];
        }
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => (string) $m['to'],
            'type'              => 'interactive',
            'interactive'       => $interactive,
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
 * Upload a stored file to the Cloud API media endpoint. Returns
 * {ok, id, code, error}. The token goes in the header; the file is streamed
 * from its private path and never copied into the docroot.
 */
function se_wa_upload_media($phone_number_id, array $media, $token)
{
    $version = get_option('se_meta_graph_version') ?: 'v23.0';
    $url = 'https://graph.facebook.com/' . $version . '/' . rawurlencode((string) $phone_number_id) . '/media';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS     => [
            'messaging_product' => 'whatsapp',
            'type'              => (string) $media['mime'],
            'file'              => new CURLFile((string) $media['abs_path'], (string) $media['mime'],
                                       (string) ($media['filename'] ?: basename((string) $media['abs_path']))),
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'id' => '', 'code' => 0, 'error' => 'network error: ' . mb_substr((string) $err, 0, 80)];
    }
    $body = json_decode((string) $raw, true) ?: [];
    if ($code >= 200 && $code < 300 && !empty($body['id'])) {
        return ['ok' => true, 'id' => (string) $body['id'], 'code' => $code, 'error' => ''];
    }
    return ['ok' => false, 'id' => '', 'code' => $code,
            'error' => mb_substr((string) ($body['error']['message'] ?? ('HTTP ' . $code)), 0, 160)];
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
