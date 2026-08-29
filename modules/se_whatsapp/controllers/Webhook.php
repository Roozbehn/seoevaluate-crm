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
        // Bounded read: refuse an oversized body before buffering it.
        $declared = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;

        if ($declared > SE_WA_MAX_BODY_BYTES) {
            header('HTTP/1.1 413 Payload Too Large');
            echo 'payload too large';
            return;
        }

        $raw = file_get_contents('php://input', false, null, 0, SE_WA_MAX_BODY_BYTES + 1);

        if ($raw !== false && strlen($raw) > SE_WA_MAX_BODY_BYTES) {
            header('HTTP/1.1 413 Payload Too Large');
            echo 'payload too large';
            return;
        }

        $raw    = (string) $raw;
        $header = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';

        if (!se_wa_verify_signature($raw, $header)) {
            header('HTTP/1.1 401 Unauthorized');
            echo 'bad signature';
            return;
        }

        /* 200 means DURABLY ACCEPTED. A failed insert previously still returned
         * 200, so Meta considered the message delivered and never retried it:
         * the inbound message was lost with no trace. A duplicate IS accepted
         * (we already hold it); anything else must return 500 to be retried. */
        $stored = se_wa_store_event($raw, true);

        if (empty($stored['stored']) && empty($stored['duplicate'])) {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'not stored';
            return;
        }

        // Fast ack; heavy work is done by cron.
        header('HTTP/1.1 200 OK');
        echo 'ok';
    }
}
