<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
 * CSRF exclusions for se_whatsapp — EXACT public webhook URI only.
 *
 * WhatsApp Cloud API signs every webhook POST with X-Hub-Signature-256 over
 * the raw body; the receiver (modules/se_whatsapp/controllers/Webhook.php)
 * verifies that HMAC before anything is stored. Perfex's cookie CSRF token is
 * meaningless to Meta's cookieless server-to-server caller and would 403 the
 * POST before the controller runs.
 *
 * The entry is an anchored regex matched against the FULL uri_string(), so
 * only the canonical `se_whatsapp/webhook` is excluded; the
 * `/admin/se_whatsapp/webhook` router alias stays CSRF-protected and nothing
 * else is widened.
 */
return [
    'se_whatsapp/webhook',
];
