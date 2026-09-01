<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
 * CSRF exclusions for se_core — EXACT authenticated public URIs only.
 *
 * Meta signs every webhook POST with X-Hub-Signature-256 over the raw body;
 * the receiver (modules/se_core/controllers/Leadgen.php) verifies that HMAC
 * before anything is stored, which is the cross-site protection a cookie CSRF
 * token cannot provide to a cookieless machine-to-machine caller. Perfex's
 * CSRF filter would otherwise 403 the POST before the controller ever runs.
 *
 * The website lead receiver uses a high-entropy per-brand bearer token and
 * never accepts browser session cookies as authorization. It therefore needs
 * the same narrow machine-to-machine exception while retaining its own
 * authorization check in the controller.
 *
 * Entries are anchored regexes matched against the FULL uri_string()
 * (preg_match('#^<entry>$#i')), so:
 *   - only the canonical public URI `se_core/leadgen` is excluded;
 *   - the `/admin/se_core/leadgen` router alias stays CSRF-protected
 *     (it is not the registered webhook path);
 *   - no wildcard, no prefix, no other route is widened.
 */
return [
    'se_core/leadgen',
    'se_core/website_lead',
];
