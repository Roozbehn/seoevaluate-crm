<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Public Meta Lead Ads webhook receiver (route: /se_core/leadgen).
 *
 * GET  -> subscription verification. POST -> verify X-Hub-Signature-256 over the
 * RAW body, store the notification durably (deduplicated on leadgen_id), 200 fast.
 * Parsing/routing/fetch happens async in se_leadgen_process_pending() (cron).
 * The live route is NOT registered with Meta yet (externally gated). No secret
 * is echoed or logged.
 */
class Leadgen extends App_Controller
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
        $mode      = $this->input->get('hub_mode');
        $token     = $this->input->get('hub_verify_token');
        $challenge = $this->input->get('hub_challenge');
        $expected  = (string) get_option('se_meta_webhook_verify_token');

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
        // Bounded read. Content-Length is checked first so an oversized body is
        // refused before it is buffered at all.
        $declared = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;

        if ($declared > SE_LEADGEN_MAX_BODY_BYTES) {
            header('HTTP/1.1 413 Payload Too Large');
            echo 'payload too large';
            return;
        }

        $raw = file_get_contents('php://input', false, null, 0, SE_LEADGEN_MAX_BODY_BYTES + 1);

        if ($raw !== false && strlen($raw) > SE_LEADGEN_MAX_BODY_BYTES) {
            header('HTTP/1.1 413 Payload Too Large');
            echo 'payload too large';
            return;
        }

        $raw = (string) $raw;
        $sig = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';

        if (!se_leadgen_verify_signature($raw, $sig)) {
            header('HTTP/1.1 401 Unauthorized');
            echo 'bad signature';
            return;
        }

        /* 200 means DURABLY ACCEPTED.
         *
         * The result of the store was previously discarded and 200 returned
         * unconditionally, so a failed insert told Meta the notification was
         * safely received and Meta never redelivered it — the lead was lost
         * silently. A duplicate is genuinely accepted (we already hold it);
         * anything else must return 500 so Meta retries. */
        $stored = se_leadgen_store_event($raw, true);

        if (empty($stored['stored']) && empty($stored['duplicate'])) {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'not stored';
            return;
        }

        update_option('se_meta_last_webhook_at', se_db_now());

        header('HTTP/1.1 200 OK');
        echo 'ok';
    }
}
