<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * WhatsApp Flows Data Endpoint (route: POST /se_journey/flow).
 *
 * Meta calls this for every screen of the intake and booking flows. Fixed
 * pipeline: method (405) → body size (413) → X-Hub-Signature-256 over the raw
 * bytes with the app secret (432) → RSA-OAEP/AES-GCM decrypt (421, which
 * makes Meta re-fetch the public key) → handle → encrypted plain-text reply
 * (200). Nothing decrypted is ever logged; a failure logs only its class.
 *
 * Every response carries `X-SE-Flow: journey` so a reply WITHOUT the marker
 * proves the request never reached this controller (a proxy page, say).
 */
class Flow extends App_Controller
{
    const MAX_BODY = 262144;   // 256 KiB — a flow screen is a few KiB

    public function index()
    {
        header('X-SE-Flow: journey');
        header('Cache-Control: no-store');
        $method = strtolower((string) $this->input->method());
        if ($method !== 'post') {
            set_status_header(405);
            header('Allow: POST');
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'reason' => 'method_not_allowed']);

            return;
        }
        $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($declared > self::MAX_BODY) {
            set_status_header(413);
            echo 'too large';

            return;
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (strlen($raw) > self::MAX_BODY) {
            set_status_header(413);
            echo 'too large';

            return;
        }
        $secret = function_exists('se_wa_app_secret') ? se_wa_app_secret() : '';
        $sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
        if (!se_journey_flow_signature_ok($raw, $sig, $secret)) {
            se_journey_audit(0, 0, 'flow_bad_signature', 'flow', null, $secret === '' ? 'no app secret' : 'mismatch');
            set_status_header(432);
            echo 'bad signature';

            return;
        }
        $body = json_decode($raw, true);
        $dec = is_array($body) ? se_journey_flow_decrypt($body, se_journey_flow_private_key()) : ['ok' => false, 'reason' => 'json'];
        if (!$dec['ok']) {
            se_journey_audit(0, 0, 'flow_undecryptable', 'flow', null, (string) $dec['reason']);
            set_status_header(421);
            echo 'cannot decrypt';

            return;
        }
        try {
            $response = se_journey_flow_handle($dec['payload'], (string) $this->input->ip_address(), (string) $this->input->user_agent());
        } catch (Throwable $e) {
            se_journey_audit(0, 0, 'flow_handler_error', 'flow', null, mb_substr(basename($e->getFile()) . ':' . $e->getLine(), 0, 191));
            $response = ['data' => ['error_message' => 'Geçici bir sorun oluştu. Lütfen tekrar deneyin.']];
        }
        $out = se_journey_flow_encrypt($response, $dec['aes_key'], $dec['iv']);
        if ($out === '') {
            set_status_header(500);
            echo 'encrypt failed';

            return;
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo $out;
    }
}
