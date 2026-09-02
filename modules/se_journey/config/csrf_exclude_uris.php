<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
 * CSRF exclusion for se_journey — the WhatsApp Flows Data Endpoint only.
 *
 * Meta posts every flow request with X-Hub-Signature-256 over the raw body
 * (verified in modules/se_journey/controllers/Flow.php before anything is
 * decrypted), and the body itself is RSA/AES encrypted to this CRM's key.
 * That is the cross-site protection a cookie CSRF token cannot give a
 * cookieless machine-to-machine caller. The patient pages under
 * se_journey/intake/... keep Perfex's CSRF token (they are browser forms).
 */
return [
    'se_journey/flow',
];
