<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Public WhatsApp webhook receiver (route: /se_whatsapp/webhook).
 *
 * GET  -> subscription verification (hub.mode/hub.verify_token/hub.challenge).
 * POST -> the fixed pipeline implemented in se_wa_receive_outcome(): method
 * (405) -> body-size limit 413 (declared Content-Length AND actual bytes,
 * before decode and before HMAC) -> signature over the exact raw bytes (401)
 * -> JSON well-formedness (400) -> durable, deduplicated store (200 only when
 * stored or duplicate, 500 otherwise). Parsing/routing happens async in
 * se_wa_process_pending() (cron), so the ack is always fast.
 *
 * EVERY response carries the marker header `X-SE-Webhook: whatsapp` and
 * (except the bare verification challenge, which Meta requires as text/plain)
 * a machine-readable JSON body {"ok":bool,"reason":string}. A response
 * WITHOUT the marker proves the request never reached this controller.
 *
 * The live route is NOT registered with Meta yet (externally gated). Secrets
 * come from the FILE secret provider (se_secret_read) and are never echoed or
 * logged.
 */
class Webhook extends App_Controller
{
    public function index()
    {
        $method = strtolower((string) $this->input->method());

        if ($method === 'get') {
            $this->verify();

            return;
        }

        if ($method === 'post') {
            $this->receive();

            return;
        }

        set_status_header(405);
        header('Allow: GET, POST');
        $this->emit(false, 'method_not_allowed');

        return;
    }

    /** Marker header + machine-readable JSON body. Never a secret, never the payload. */
    private function emit($ok, $reason)
    {
        header('X-SE-Webhook: whatsapp');
        header('Content-Type: application/json');
        echo json_encode(['ok' => (bool) $ok, 'reason' => (string) $reason]);
    }

    private function verify()
    {
        $mode      = $this->input->get('hub_mode') ?: $this->input->get('hub.mode');
        $token     = $this->input->get('hub_verify_token') ?: $this->input->get('hub.verify_token');
        $challenge = $this->input->get('hub_challenge') ?: $this->input->get('hub.challenge');

        if (se_wa_verify_outcome($mode, $token)) {
            // Meta requires the EXACT challenge as the bare body; the marker
            // header still identifies the responder.
            set_status_header(200);
            header('X-SE-Webhook: whatsapp');
            header('Content-Type: text/plain');
            echo $challenge;

            return;
        }

        set_status_header(403);
        $this->emit(false, 'verify_failed');

        return;
    }

    private function receive()
    {
        // Content-Length is checked before the body is buffered at all, so an
        // oversized body is refused unread; the actual byte count is checked
        // again inside the outcome over the bounded read.
        $declared = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;

        if ($declared > SE_WA_MAX_BODY_BYTES) {
            set_status_header(413);
            $this->emit(false, 'payload_too_large');

            return;
        }

        $raw = file_get_contents('php://input', false, null, 0, SE_WA_MAX_BODY_BYTES + 1);
        $sig = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';

        $out = se_wa_receive_outcome($declared, $raw, $sig);

        set_status_header($out['status']);
        $this->emit($out['ok'], $out['reason']);

        return;
    }
}
