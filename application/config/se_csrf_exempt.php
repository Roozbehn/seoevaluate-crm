<?php
/**
 * CSRF exemption for payment-gateway callbacks — ANCHORED.
 *
 * Perfex's stock check was `strpos(REQUEST_URI, 'gateways/') !== false`, which
 * matched the query string too, so `POST /admin/anything?x=gateways/` skipped
 * CSRF verification for every admin action. Only a request whose PATH starts
 * with the gateways segment (optionally after index.php) is exempt.
 */
if (!function_exists('se_csrf_gateways_exempt')) {
    function se_csrf_gateways_exempt($request_uri)
    {
        $path = (string) parse_url((string) $request_uri, PHP_URL_PATH);

        return (bool) preg_match('#^/?(?:index\.php/)?gateways/#i', $path);
    }
}
