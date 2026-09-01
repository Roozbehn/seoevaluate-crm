<?php

defined('BASEPATH') or exit('No direct script access allowed');

/** Public, bearer-authenticated server-to-server website lead receiver. */
class Website_lead extends App_Controller
{
    public function index()
    {
        if (strtolower((string) $this->input->method()) !== 'post') {
            header('Allow: POST');
            return $this->emit(405, false, 'method_not_allowed');
        }

        $declared = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($declared > SE_WEBSITE_LEAD_MAX_BODY_BYTES) {
            return $this->emit(413, false, 'payload_too_large');
        }

        $brandId = function_exists('se_clinic_sole_brand_id')
            ? (int) se_clinic_sole_brand_id() : 0;
        if ($brandId <= 0) {
            return $this->emit(503, false, 'brand_unconfigured');
        }

        $auth = (string) $this->input->get_request_header('Authorization', true);
        if (!se_website_lead_authorized($brandId, $auth)) {
            return $this->emit(401, false, 'unauthorized');
        }

        if (stripos((string) $this->input->get_request_header('Content-Type', true), 'application/json') === false) {
            return $this->emit(415, false, 'unsupported_media_type');
        }

        $raw = file_get_contents('php://input', false, null, 0, SE_WEBSITE_LEAD_MAX_BODY_BYTES + 1);
        if ($raw === false || strlen($raw) > SE_WEBSITE_LEAD_MAX_BODY_BYTES) {
            return $this->emit(413, false, 'payload_too_large');
        }

        $payload = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->emit(400, false, 'malformed_json');
        }

        $validated = se_website_lead_validate($payload);
        if (!$validated['ok']) {
            return $this->emit(400, false, $validated['reason']);
        }

        $result = se_website_lead_upsert($brandId, $validated['data']);
        if (!$result['ok']) {
            return $this->emit(503, false, $result['reason']);
        }

        return $this->emit(200, true, $result['duplicate'] ? 'duplicate' : 'accepted', [
            'ref' => (string) $result['lead_id'],
        ]);
    }

    private function emit($status, $ok, $reason, array $extra = [])
    {
        set_status_header((int) $status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('X-SE-Endpoint: website-lead');
        echo json_encode(array_merge(['ok' => (bool) $ok, 'reason' => (string) $reason], $extra));
    }
}
