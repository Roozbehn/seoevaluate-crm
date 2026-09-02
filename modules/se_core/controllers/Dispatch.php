<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fast messaging dispatcher (route: /se_core/dispatch/index/<APP_CRON_KEY>).
 *
 * The full Perfex cron (invoices, reminders, IMAP, email queue, every SE hook)
 * runs every 15 minutes, so a WhatsApp/Instagram reply queued from the panel
 * waited up to 15 minutes. This route runs ONLY the messaging legs and is
 * meant to be called every minute by a second, dedicated cron line:
 *
 *   * * * * *  /home/<user>/bin/crm-dispatch.sh
 *
 * Per run: ingest pending WA + IG webhook events (inbound messages, delivery
 * and read receipts), then drain the WA + IG outbound queues. Each function is
 * bounded, leased and idempotent, and a DB lock stops two runs overlapping
 * (this route AND the 15-minute Perfex cron can call the same functions —
 * the queue rows are claimed with a fence, so that is safe by design).
 *
 * Same key as Perfex's own cron; GET-only; JSON summary; never a secret.
 */
class Dispatch extends App_Controller
{
    public function index($key = '')
    {
        header('Content-Type: application/json');
        header('X-SE-Dispatch: 1');

        if (!defined('APP_CRON_KEY') || !hash_equals((string) APP_CRON_KEY, (string) $key)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'reason' => 'bad_key']);
            return;
        }

        echo json_encode(se_dispatch_run());
    }
}
