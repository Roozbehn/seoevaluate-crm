<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
 * CSRF exclusion for se_instagram — the EXACT public webhook URI only.
 * Meta signs every POST with X-Hub-Signature-256 over the raw body and the
 * receiver verifies it before anything is stored; Perfex's cookie CSRF token
 * is meaningless to Meta's server-to-server caller.
 */
return [
    'se_instagram/webhook',
];
