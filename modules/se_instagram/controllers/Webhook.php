<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Public Instagram Messaging webhook receiver (route: /se_instagram/webhook).
 *
 * GET  -> subscription verification (hub.mode/hub.verify_token/hub.challenge).
 * POST -> se_ig_receive_outcome(): 413 -> 401 (signature over raw bytes) ->
 *         400 -> durable deduplicated store (200 only when stored/duplicate).
 *
 * Every response carries `X-SE-Webhook: instagram`. Secrets come from the file
 * provider and are never echoed or logged.
 */
class Webhook extends App_Controller
{
    public function index()
    {
        $method = strtolower((string) $this->input->method());

        if ($method === 'get')  { $this->verify();  return; }
        if ($method === 'post') { $this->receive(); return; }

        set_status_header(405);
        header('Allow: GET, POST');
        $this->emit(false, 'method_not_allowed');
    }

    private function emit($ok, $reason)
    {
        header('X-SE-Webhook: instagram');
        header('Content-Type: application/json');
        echo json_encode(['ok' => (bool) $ok, 'reason' => (string) $reason]);
    }

    private function verify()
    {
        if (function_exists('se_webhook_record')) {
            se_webhook_record('ig', 'route_ok');
        }

        $mode      = $this->input->get('hub_mode') ?: $this->input->get('hub.mode');
        $token     = $this->input->get('hub_verify_token') ?: $this->input->get('hub.verify_token');
        $challenge = $this->input->get('hub_challenge') ?: $this->input->get('hub.challenge');

        if (se_ig_verify_outcome($mode, $token)) {
            if (function_exists('se_webhook_record')) {
                se_webhook_record('ig', 'challenge',
                    ['src' => (function_exists('se_webhook_is_selftest') && se_webhook_is_selftest()) ? 'self_test' : 'meta']);
            }
            set_status_header(200);
            header('X-SE-Webhook: instagram');
            header('Content-Type: text/plain');
            echo $challenge;

            return;
        }

        set_status_header(403);
        $this->emit(false, 'verify_failed');
    }

    private function receive()
    {
        $declared = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;

        if ($declared > SE_IG_MAX_BODY_BYTES) {
            set_status_header(413);
            $this->emit(false, 'payload_too_large');

            return;
        }

        $raw = file_get_contents('php://input', false, null, 0, SE_IG_MAX_BODY_BYTES + 1);
        $sig = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';

        $out = se_ig_receive_outcome($declared, $raw, $sig);

        if (function_exists('se_webhook_record') && !in_array((int) $out['status'], [401, 413], true)) {
            se_webhook_record('ig', 'signed_post',
                ['src' => (function_exists('se_webhook_is_selftest') && se_webhook_is_selftest()) ? 'self_test' : 'meta']);
        }

        set_status_header($out['status']);
        $this->emit($out['ok'], $out['reason']);
    }
}
