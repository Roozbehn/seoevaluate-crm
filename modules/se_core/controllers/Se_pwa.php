<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * The installable-app surface: manifest, service worker, and the subscribe /
 * unsubscribe endpoints.
 *
 * SCOPE IS THE WHOLE REASON THIS CONTROLLER LOOKS ODD
 * A service worker may only control pages at or below its OWN url. Served from
 * /se_core/se_pwa/sw it would control nothing useful, so the worker is also
 * reachable at the site root via a rewrite (see docs) and declares
 * Service-Worker-Allowed. Getting this wrong produces a worker that registers
 * successfully and then never receives a push — which is indistinguishable
 * from a broken subscription until you go looking.
 *
 * The manifest and the worker are PUBLIC by necessity: the browser fetches
 * both before any session exists, and a service worker request carries no
 * cookies in some browsers. Neither leaks anything — the manifest is a name
 * and some icons, and the worker is code that does nothing until a push
 * arrives. Everything that touches DATA is behind a staff session.
 */
class Se_pwa extends App_Controller
{
    /** The web app manifest. Public: fetched before login. */
    public function manifest()
    {
        $name = defined('SE_CLINIC_NAME') ? SE_CLINIC_NAME : 'SEO Evaluate CRM';

        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');

        echo json_encode([
            'name'             => $name,
            'short_name'       => mb_substr($name, 0, 12),
            'start_url'        => site_url('admin'),
            'scope'            => site_url(),
            // 'standalone' is what removes the browser chrome AND, on iOS, what
            // makes web push available at all: iOS delivers push only to a PWA
            // the user has added to the home screen.
            'display'          => 'standalone',
            'orientation'      => 'portrait-primary',
            'background_color' => '#ffffff',
            'theme_color'      => '#1b1b1b',
            'icons'            => [
                ['src' => site_url('modules/se_core/assets/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => site_url('modules/se_core/assets/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => site_url('modules/se_core/assets/icon-maskable.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** The service worker. Public, and served with the widest allowed scope. */
    public function sw()
    {
        header('Content-Type: application/javascript; charset=utf-8');
        // Without this header the worker's scope is its own directory, and it
        // would never receive a push for an /admin page.
        header('Service-Worker-Allowed: /');
        header('Cache-Control: no-cache');

        /*
         * Rendered to a STRING and echoed, not loaded and exited.
         * CodeIgniter's view loader appends to an output buffer that CI
         * flushes at the end of the request — and `exit` never reaches that
         * flush. The result is a 200 with the right Content-Type and a body of
         * zero bytes: the browser registers the worker happily and it does
         * nothing at all, forever. Found by curling the deployed route.
         */
        echo $this->load->view('se_core/pwa/service_worker', [], true);
        exit;
    }

    /**
     * Registers this browser for push. Staff session required.
     *
     * The subscription belongs to whoever is logged in RIGHT NOW, taken from
     * the session and never from the request body: accepting a staff_id from
     * the client would let anyone redirect another person's notifications to
     * their own device.
     */
    public function subscribe()
    {
        $staff_id = function_exists('se_staff_session_id') ? (int) se_staff_session_id() : 0;
        if ($staff_id <= 0) {
            return $this->json(403, ['ok' => false]);
        }

        $body = $this->payload();
        if (!is_array($body)) {
            return $this->json(400, ['ok' => false]);
        }

        $ok = se_push_subscribe(
            $staff_id,
            isset($body['endpoint']) ? (string) $body['endpoint'] : '',
            isset($body['keys']['p256dh']) ? (string) $body['keys']['p256dh'] : '',
            isset($body['keys']['auth']) ? (string) $body['keys']['auth'] : '',
            $this->input->user_agent() ?: ''
        );

        return $this->json($ok ? 200 : 400, ['ok' => $ok]);
    }

    /**
     * The JSON document the browser sent: as a `payload` form field (with the
     * CSRF token beside it — CSRF verification reads $_POST) or, for older
     * clients, as a raw JSON body.
     */
    private function payload()
    {
        $raw = $this->input->post('payload', false);
        if (!is_string($raw) || $raw === '') {
            $raw = file_get_contents('php://input');
        }
        $body = json_decode((string) $raw, true);

        return is_array($body) ? $body : null;
    }

    /** Removes this browser's subscription. */
    public function unsubscribe()
    {
        $staff_id = function_exists('se_staff_session_id') ? (int) se_staff_session_id() : 0;
        if ($staff_id <= 0) {
            return $this->json(403, ['ok' => false]);
        }

        $body = $this->payload();
        $endpoint = is_array($body) && isset($body['endpoint']) ? (string) $body['endpoint'] : '';
        if ($endpoint !== '') {
            se_push_unsubscribe($endpoint, $staff_id);   // only this staff member's own row
        }

        return $this->json(200, ['ok' => true]);
    }

    /**
     * The VAPID public key the browser needs to subscribe.
     *
     * Not a secret — it is handed to every subscriber by design — but it is
     * still behind the staff session, because an unauthenticated endpoint that
     * answers "yes, push is configured here" is free reconnaissance.
     */
    public function key()
    {
        $staff_id = function_exists('se_staff_session_id') ? (int) se_staff_session_id() : 0;
        if ($staff_id <= 0) {
            return $this->json(403, ['ok' => false]);
        }

        return $this->json(200, ['ok' => true, 'key' => se_push_public_key()]);
    }

    private function json($status, array $payload)
    {
        $this->output
            ->set_status_header($status)
            ->set_content_type('application/json')
            ->set_output(json_encode($payload));
    }
}
