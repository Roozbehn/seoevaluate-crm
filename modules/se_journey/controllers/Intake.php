<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Public, token-authenticated patient pages (no CRM session):
 *
 *   GET  se_journey/intake/<token>            consent step, then the form
 *   POST se_journey/intake/<token>/consent
 *   POST se_journey/intake/<token>/save       autosave (JSON)
 *   POST se_journey/intake/<token>/submit
 *   GET  se_journey/intake/<token>/photos     secure photo upload
 *   POST se_journey/intake/<token>/photos
 *   GET  se_journey/intake/<token>/quote      frozen quote snapshot
 *   POST se_journey/intake/<token>/quote      the patient's answer (accept / revision / human)
 *   GET  se_journey/intake/<token>/book       face-to-face consultation slot picker (calendar)
 *   POST se_journey/intake/<token>/book       book the chosen slot
 *   GET  se_journey/intake/<token>/calendar   the booked consultation as an .ics file (calendar or book token)
 *
 * The token is the ONLY identity; nothing else in the URL. Every request
 * re-verifies it (purpose, expiry, revocation, opt-out), rate-limits by IP,
 * and the CI CSRF token protects every POST from cross-site submission.
 * Responses are never cached, never framed, never indexed.
 */
class Intake extends App_Controller
{
    public function _remap($token, $params = [])
    {
        $this->headers();
        $sub = strtolower((string) ($params[0] ?? ''));
        $method = strtolower((string) $this->input->method());
        $ip = (string) $this->input->ip_address();
        $ua = (string) $this->input->user_agent();

        if ($sub === 'quote') {
            if ($method === 'post') {
                if (se_journey_throttle_hit('post:' . hash('sha256', $ip), 120, 600)) {
                    return $this->fail('rate_limited');
                }

                return $this->quote_post($token, $ip, $ua);
            }

            return $this->quote($token, $ip, $ua);
        }
        if ($sub === 'calendar') {
            return $this->calendar($token, $ip, $ua);
        }
        if ($sub === 'book') {
            if ($method === 'post' && se_journey_throttle_hit('post:' . hash('sha256', $ip), 120, 600)) {
                return $this->fail('rate_limited');
            }

            return $this->book($token, $ip, $ua, $method === 'post');
        }
        if ($sub === 'photos') {
            return $method === 'post' ? $this->photos_post($token, $ip, $ua) : $this->photos_get($token, $ip, $ua);
        }

        $v = se_journey_verify_token($token, 'intake', $ip, $ua);
        if (!$v['ok']) {
            return $this->fail($v['reason']);
        }
        $j = $v['journey'];

        if ($method === 'post') {
            if (se_journey_throttle_hit('post:' . hash('sha256', $ip), 120, 600)) {
                return $this->fail('rate_limited');
            }
            switch ($sub) {
                case 'consent': return $this->consent($j, $ip, $ua, $token);
                case 'save':    return $this->save($j, $ip, $ua);
                case 'submit':  return $this->submit($j, $ip, $ua, $token);
                default:        return $this->fail('unknown');
            }
        }

        return $this->form($j, $token);
    }

    /* ---------------------------------------------------------------- */

    private function form($j, $token)
    {
        $brand = (int) $j->brand_id;
        $allowed = se_journey_health_collection_allowed($brand);
        $consent = se_journey_consent_state($j);
        $intake  = se_journey_intake_get($j);
        $data = [
            'token'    => $token,
            'j'        => $j,
            'allowed'  => $allowed,
            'consent'  => $consent,
            'texts'    => se_journey_consent_texts($brand, 'tr'),
            'version'  => function_exists('se_consent_text_version') ? se_consent_text_version($brand) : '',
            'draft'    => se_journey_consent_bypass_active($brand) && !(function_exists('se_consent_text_configured') && se_consent_text_configured($brand, 'health_data')),
            'questionnaire' => se_journey_questionnaire(),
            'fields'   => se_journey_fields($brand),
            'answers'  => $consent['health_data'] ? se_journey_intake_answers($intake) : [],
            'submitted'=> $intake && (string) $intake->status === 'submitted',
            'masked_phone' => '+' . substr((string) $j->wa_user_id, 0, 4) . ' ••• •• ' . substr((string) $j->wa_user_id, -2),
            'csrf_name'=> $this->security->get_csrf_token_name(),
            'csrf_hash'=> $this->security->get_csrf_hash(),
            'base'     => se_journey_public_url('se_journey/intake/' . $token),
            'photos_url' => se_journey_public_url('se_journey/intake/' . $token . '/photos'),
        ];
        $this->load->view('se_journey/public/intake', $data);
    }

    private function consent($j, $ip, $ua, $token)
    {
        $r = se_journey_record_form_consent($j, $this->input->post(), $ip, $ua);
        if (!$r['ok']) {
            return $this->fail($r['reason']);
        }
        redirect(se_journey_public_url('se_journey/intake/' . $token) . ($r['reason'] === 'declined' ? '?declined=1' : '#adim-1'));
    }

    private function save($j, $ip, $ua)
    {
        $input = $this->input->post();
        unset($input[$this->security->get_csrf_token_name()]);
        $r = se_journey_intake_save($j, is_array($input) ? $input : [], $ip, $ua);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => (bool) $r['ok'], 'reason' => (string) $r['reason'], 'errors' => (object) ($r['errors'] ?? []), 'sections_done' => $r['sections_done'] ?? []]);
    }

    private function submit($j, $ip, $ua, $token)
    {
        $input = $this->input->post();
        unset($input[$this->security->get_csrf_token_name()]);
        $r = se_journey_intake_submit($j, is_array($input) ? $input : [], $ip, $ua);
        if (!$r['ok']) {
            $data = ['token' => $token, 'j' => $j, 'errors' => $r['errors'], 'missing' => $r['missing'], 'reason' => $r['reason'],
                     'fields' => se_journey_fields((int) $j->brand_id), 'base' => se_journey_public_url('se_journey/intake/' . $token)];
            $this->load->view('se_journey/public/intake_invalid', $data);
            return;
        }
        $upload = se_journey_issue_token($j, 'upload', 0);
        $this->load->view('se_journey/public/intake_done', [
            'j' => $j, 'photos_url' => $upload['ok'] ? se_journey_public_url('se_journey/intake/' . $upload['token'] . '/photos') : '',
        ]);
    }

    /* ---------------------------------------------------------------- */

    private function photos_token($token, $ip, $ua)
    {
        $v = se_journey_verify_token($token, 'upload', $ip, $ua);
        if (!$v['ok'] && $v['reason'] === 'wrong_purpose') {
            $v = se_journey_verify_token($token, 'intake', $ip, $ua);   // the form's own link may upload too
        }

        return $v;
    }

    private function photos_get($token, $ip, $ua)
    {
        $v = $this->photos_token($token, $ip, $ua);
        if (!$v['ok']) {
            return $this->fail($v['reason']);
        }
        $this->load->view('se_journey/public/photos', $this->photos_data($v['journey'], $token));
    }

    private function photos_post($token, $ip, $ua)
    {
        $v = $this->photos_token($token, $ip, $ua);
        if (!$v['ok']) {
            return $this->fail($v['reason']);
        }
        if (se_journey_throttle_hit('upload:' . hash('sha256', $ip), 40, 600)) {
            return $this->fail('rate_limited');
        }
        $j = $v['journey'];
        $results = [];
        foreach (['frontal', 'left', 'right', 'donor', 'other'] as $kind) {
            if (empty($_FILES['photo_' . $kind]) || (int) ($_FILES['photo_' . $kind]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $f = $_FILES['photo_' . $kind];
            if ((int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                $results[$kind] = 'upload_error';
                continue;
            }
            if ((int) $f['size'] > SE_JOURNEY_MEDIA_MAX_BYTES) {
                $results[$kind] = 'too_large';
                continue;
            }
            $bytes = (string) file_get_contents($f['tmp_name'], false, null, 0, SE_JOURNEY_MEDIA_MAX_BYTES + 1);
            $r = se_journey_media_ingest($j, $bytes, ['source' => 'form_upload', 'kind' => $kind, 'name' => (string) $f['name']]);
            $results[$kind] = $r['ok'] ? 'ok' : $r['reason'];
            if ($r['ok']) {
                se_journey_after_media_received(se_journey_get_raw((int) $j->id), 'form:' . $r['id']);
            }
        }
        $data = $this->photos_data(se_journey_get_raw((int) $j->id), $token);
        $data['results'] = $results;
        $this->load->view('se_journey/public/photos', $data);
    }

    private function photos_data($j, $token)
    {
        $consent = se_journey_consent_state($j);

        return [
            'token' => $token, 'j' => $j, 'consent_ok' => $consent['health_data'] && se_journey_health_collection_allowed((int) $j->brand_id),
            'checklist' => se_journey_media_checklist($j), 'count' => se_journey_media_count($j),
            'csrf_name' => $this->security->get_csrf_token_name(), 'csrf_hash' => $this->security->get_csrf_hash(),
            'action' => se_journey_public_url('se_journey/intake/' . $token . '/photos'),
            'followup' => in_array((string) $j->state, ['aftercare_active', 'followup_due', 'procedure_completed', 'completed'], true),
            'results' => [],
        ];
    }

    private function quote($token, $ip, $ua, $notice = '')
    {
        $r = se_journey_quote_public($token, $ip, $ua);
        if (!$r['ok']) {
            return $this->fail($r['reason']);
        }
        $this->load->view('se_journey/public/quote', [
            'snapshot' => $r['snapshot'], 'response' => $r['response'], 'booking' => $r['booking'], 'notice' => $notice,
            'ics_url' => $r['booking'] ? se_journey_public_url('se_journey/intake/' . $token . '/calendar') : '',
            'gcal_url' => $r['booking'] ? se_journey_calendar_google_url($r['journey'], $r['booking']) : '',
            'state' => (string) $r['journey']->state,
            'csrf_name' => $this->security->get_csrf_token_name(), 'csrf_hash' => $this->security->get_csrf_hash(),
            'action' => se_journey_public_url('se_journey/intake/' . $token . '/quote'),
        ]);
    }

    /** The three answers, from the page (works whatever the WhatsApp window state). */
    private function quote_post($token, $ip, $ua)
    {
        $v = se_journey_verify_token($token, 'quote', $ip, $ua);
        if (!$v['ok']) {
            return $this->fail($v['reason']);
        }
        $j = $v['journey'];
        $action = (string) $this->input->post('action');
        if ($action === 'accept' || $action === 'book') {
            $r = se_journey_quote_respond($j, 'accept', 'page');
            if ($r['ok'] && $r['book_link'] !== '') {
                redirect($r['book_link']);   // straight to the calendar
            }

            return $this->quote($token, $ip, $ua, $r['ok'] ? 'accepted' : 'failed');
        }
        if ($action === 'revise') {
            $r = se_journey_quote_respond($j, 'revise', 'page');

            return $this->quote($token, $ip, $ua, $r['ok'] ? 'revision' : 'failed');
        }
        if ($action === 'handoff') {
            se_journey_handle_handoff($j, ['wamid' => '', 'body' => 'quote page: handoff']);

            return $this->quote($token, $ip, $ua, 'handoff');
        }

        return $this->fail('unknown');
    }

    /** The booked consultation as an iCalendar file ("add to calendar"). */
    private function calendar($token, $ip, $ua)
    {
        $v = se_journey_verify_token($token, 'calendar', $ip, $ua);
        foreach (['book', 'quote'] as $alt) {   // the booking and quote pages link with their own tokens
            if (!$v['ok'] && $v['reason'] === 'wrong_purpose') {
                $v = se_journey_verify_token($token, $alt, $ip, $ua);
            }
        }
        if (!$v['ok']) {
            return $this->fail($v['reason']);
        }
        $j = $v['journey'];
        $a = se_journey_consultation_appointment($j);
        if (!$a) {
            return $this->fail('no_appointment');
        }
        $ics = se_journey_calendar_ics($j, $a);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="on-gorusme.ics"');
        header('Content-Length: ' . strlen($ics));
        echo $ics;
    }

    /** Calendar: free face-to-face slots; POST books one. */
    private function book($token, $ip, $ua, $post)
    {
        $v = se_journey_verify_token($token, 'book', $ip, $ua);
        if (!$v['ok']) {
            return $this->fail($v['reason']);
        }
        $j = $v['journey'];
        $result = null;
        if ($post) {
            $result = se_journey_booking_pick($j, (string) $this->input->post('slot'), 'page');
            $j = se_journey_get_raw((int) $j->id);
        }
        $avail = se_journey_booking_slots((int) $j->brand_id);
        $booking = se_journey_consultation_upcoming($j);
        $this->load->view('se_journey/public/book', [
            'token' => $token, 'j' => $j, 'avail' => $avail, 'result' => $result,
            'booking' => $booking,
            'ics_url' => $booking ? se_journey_public_url('se_journey/intake/' . $token . '/calendar') : '',
            'gcal_url' => $booking ? se_journey_calendar_google_url($j, $booking) : '',
            'csrf_name' => $this->security->get_csrf_token_name(), 'csrf_hash' => $this->security->get_csrf_hash(),
            'action' => se_journey_public_url('se_journey/intake/' . $token . '/book'),
        ]);
    }

    /* ---------------------------------------------------------------- */

    private function fail($reason)
    {
        $map = ['expired' => 410, 'revoked' => 410, 'rate_limited' => 429, 'opted_out' => 410];
        set_status_header($map[$reason] ?? 404);
        $this->load->view('se_journey/public/error', ['reason' => $reason]);
    }

    private function headers()
    {
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('Permissions-Policy: camera=(self), geolocation=()');
    }
}
