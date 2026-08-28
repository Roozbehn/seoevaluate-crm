<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Public WhatsApp webhook receiver.
 *
 * GET  -> subscription verification (hub.mode/hub.verify_token/hub.challenge).
 * POST -> verify X-Hub-Signature-256 over the RAW body, store the event durably
 *         (deduplicated), and 200 immediately. Parsing/routing happens async in
 *         se_wa_process_pending() (cron), so the ack is always fast.
 *
 * The live route is NOT registered with Meta yet (externally gated). No secret
 * is ever echoed or logged.
 */
class Webhook extends App_Controller
{
    public function index()
    {
        if ($this->input->method() === 'get') {
            $this->verify();
            return;
        }
        $this->receive();
    }

    private function verify()
    {
        $mode      = $this->input->get('hub_mode') ?: $this->input->get('hub.mode');
        $token     = $this->input->get('hub_verify_token') ?: $this->input->get('hub.verify_token');
        $challenge = $this->input->get('hub_challenge') ?: $this->input->get('hub.challenge');

        $expected = (string) get_option('se_wa_verify_token');

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, (string) $token)) {
            header('Content-Type: text/plain');
            echo $challenge;
            return;
        }

        header('HTTP/1.1 403 Forbidden');
        echo 'verification failed';
    }

    private function receive()
    {
        $raw    = file_get_contents('php://input');
        $header = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';

        if (!se_wa_verify_signature($raw, $header)) {
            header('HTTP/1.1 401 Unauthorized');
            echo 'bad signature';
            return;
        }

        se_wa_store_event($raw, true);

        // Fast ack; heavy work is done by cron.
        header('HTTP/1.1 200 OK');
        echo 'ok';
    }
}
