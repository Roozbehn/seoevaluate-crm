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
        $raw = file_get_contents('php://input');
        $sig = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';

        if (!se_leadgen_verify_signature($raw, $sig)) {
            header('HTTP/1.1 401 Unauthorized');
            echo 'bad signature';
            return;
        }

        se_leadgen_store_event($raw, true);
        update_option('se_meta_last_webhook_at', date('Y-m-d H:i:s'));

        header('HTTP/1.1 200 OK');
        echo 'ok';
    }
}
