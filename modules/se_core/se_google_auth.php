<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Google credential provider — renewable, short-lived access tokens.
 *
 * WHAT THIS REPLACES
 * ------------------
 * A static bearer token pasted into a plaintext `tbloptions` row. Google
 * service-account access tokens expire in about an hour, so that design broke
 * hourly and required a human to paste a new one. It was never viable.
 *
 * WHAT IS AND IS NOT BUILT
 * ------------------------
 * BUILT: the whole abstraction — a credential reference on disk (0600, outside
 * the document root), a registration seam for a signer, a short-lived token
 * cache that holds ONLY the token and its expiry in memory for the request, and
 * refresh-before-expiry logic.
 *
 * NOT BUILT: the JWT signing and token exchange themselves. That needs
 * google/auth (or google/apiclient), which is not installed here, and
 * hand-rolling RS256 JWT assembly and OAuth exchange is exactly the kind of
 * security-critical cryptography that should never be written bespoke for one
 * project. With no signer registered every call is GATED: se_gdm_access_token()
 * returns '', outbox rows hold without consuming attempts, and nothing is sent.
 *
 * To finish it the owner installs google/auth and registers a signer:
 *
 *     se_gdm_register_token_provider(function (array $credential, $scope) {
 *         $creds = new Google\Auth\Credentials\ServiceAccountCredentials($scope, $credential);
 *         $token = $creds->fetchAuthToken();
 *         return ['access_token' => $token['access_token'],
 *                 'expires_in'   => $token['expires_in'] ?? 3600];
 *     });
 *
 * Nothing else has to change: the sender, the outbox and the UI already treat
 * "no token" as gated rather than failed.
 *
 * @see https://developers.google.com/data-manager/api/devguides/quickstart/set-up-access
 * @see https://developers.google.com/identity/protocols/oauth2/service-account
 */

define('SE_GDM_SCOPE', 'https://www.googleapis.com/auth/datamanager');

/** Refresh this many seconds before the token actually expires. */
define('SE_GDM_TOKEN_SKEW', 300);

$GLOBALS['SE_GDM_TOKEN_PROVIDER'] = null;

/**
 * Register the signer. callable(array $credential, string $scope): array
 * with keys access_token and expires_in.
 */
function se_gdm_register_token_provider(callable $p)
{
    $GLOBALS['SE_GDM_TOKEN_PROVIDER'] = $p;
}

function se_gdm_token_provider_available()
{
    return is_callable($GLOBALS['SE_GDM_TOKEN_PROVIDER'] ?? null);
}

/**
 * Load the service-account credential for a brand.
 *
 * Read through the secret provider: a 0600 file outside the document root and
 * outside Git. Returns null when absent or unparseable — never a partial or
 * guessed credential.
 */
function se_gdm_credential($brand_id)
{
    $raw = se_secret_read('google_sa', (int) $brand_id);

    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);

    // A service-account key is JSON with these fields. Anything else is not one.
    if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
        return null;
    }

    return $decoded;
}

/**
 * Readiness, for the UI. Booleans and metadata only — never the credential,
 * never the token, never a fragment of either.
 */
function se_gdm_credential_status($brand_id)
{
    $secret = se_secret_status('google_sa', (int) $brand_id);
    $cred   = se_gdm_credential($brand_id);
    $cache  = se_gdm_token_cache_meta($brand_id);

    return [
        'file_present'      => $secret['configured'],
        'file_mode_ok'      => $secret['mode_ok'],
        'credential_valid'  => $cred !== null,
        // The service-account IDENTITY is not a secret; it is the account's
        // address and the owner needs it to grant access in Google Ads.
        'client_email'      => $cred['client_email'] ?? null,
        'project_id'        => $cred['project_id'] ?? null,
        'signer_available'  => se_gdm_token_provider_available(),
        'token_cached'      => $cache['cached'],
        'token_expires_at'  => $cache['expires_at'],
        'token_valid_now'   => $cache['valid'],
        'last_auth_at'      => $secret['last_auth_at'],
        'last_error'        => $secret['last_error'],
        'ready'             => $cred !== null && se_gdm_token_provider_available(),
    ];
}

/**
 * Per-request token cache.
 *
 * Deliberately request-scoped and in memory. Persisting an access token to the
 * database would recreate the exact problem this replaces: a live credential
 * sitting in a table that every backup copies.
 */
function &se_gdm_token_cache()
{
    static $cache = [];

    return $cache;
}

/** Drop every cached access token. Used by tests and after a credential change. */
function se_gdm_token_cache_reset()
{
    $cache = &se_gdm_token_cache();
    $cache = [];
}

function se_gdm_token_cache_meta($brand_id)
{
    $cache = &se_gdm_token_cache();
    $key   = (int) $brand_id;

    if (!isset($cache[$key])) {
        return ['cached' => false, 'expires_at' => null, 'valid' => false];
    }

    return [
        'cached'     => true,
        'expires_at' => date('Y-m-d H:i:s', $cache[$key]['expires_at']),
        'valid'      => $cache[$key]['expires_at'] - SE_GDM_TOKEN_SKEW > time(),
    ];
}

/**
 * A currently-valid access token, minting one if needed.
 *
 * @return string '' when gated — no credential, or no signer registered.
 */
function se_gdm_fetch_access_token($brand_id)
{
    $cache = &se_gdm_token_cache();
    $key   = (int) $brand_id;

    if (isset($cache[$key]) && $cache[$key]['expires_at'] - SE_GDM_TOKEN_SKEW > time()) {
        return $cache[$key]['token'];
    }

    $credential = se_gdm_credential($brand_id);

    if ($credential === null) {
        se_secret_note_auth('google_sa', $brand_id, false, 'no usable service-account credential');

        return '';
    }

    if (!se_gdm_token_provider_available()) {
        se_secret_note_auth('google_sa', $brand_id, false, 'no token provider registered (google/auth not installed)');

        return '';
    }

    try {
        $result = call_user_func($GLOBALS['SE_GDM_TOKEN_PROVIDER'], $credential, SE_GDM_SCOPE);
    } catch (Exception $e) {
        // Never store the provider's message verbatim: it can quote the key.
        se_secret_note_auth('google_sa', $brand_id, false, 'token exchange failed');

        return '';
    }

    $token = (string) ($result['access_token'] ?? '');

    if ($token === '') {
        se_secret_note_auth('google_sa', $brand_id, false, 'token exchange returned no token');

        return '';
    }

    $cache[$key] = [
        'token'      => $token,
        'expires_at' => time() + (int) ($result['expires_in'] ?? 3600),
    ];

    se_secret_note_auth('google_sa', $brand_id, true);

    return $token;
}
