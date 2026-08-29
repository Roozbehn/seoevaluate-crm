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
 * HOW IT WORKS NOW
 * ----------------
 * The signing and token exchange are done by the OFFICIAL google/auth library
 * (Google\Auth\Credentials\ServiceAccountCredentials): it builds the RS256 JWT
 * assertion from the service-account key document and exchanges it at Google's
 * token endpoint. Nothing cryptographic is hand-rolled here.
 *
 * - The key document is read through the secret provider
 *   (se_secret_read('google_sa', $brand)): a 0600 file outside the document
 *   root and outside Git. Its content NEVER touches the database or a log.
 * - Only the minted access token and its expiry are cached, in memory, for the
 *   current request. Nothing token-shaped is ever persisted.
 * - The HTTP exchange goes through an injectable handler
 *   (se_gdm_register_http_handler) so tests never open a socket; live use
 *   passes null and the library builds its own Guzzle handler.
 * - The registration seam (se_gdm_register_token_provider) is kept: a
 *   registered callable overrides the library-backed default.
 * - Failures are CLASSIFIED, never quoted: a rejected exchange is an
 *   `authentication` failure, an invalid or unusable key document is a
 *   `configuration` failure. Both gate delivery (rows hold without consuming
 *   attempts); neither ever records key or token text.
 *
 * When google/auth is not installed and no provider is registered, every call
 * is GATED exactly as before: se_gdm_access_token() returns '', outbox rows
 * hold without consuming attempts, and nothing is sent.
 *
 * @see https://developers.google.com/data-manager/api/devguides/quickstart/set-up-access
 * @see https://developers.google.com/identity/protocols/oauth2/service-account
 */

/* Ingestion scope of the Data Manager API. VERIFIED against Google's live
 * discovery document (https://datamanager.googleapis.com/$discovery/rest?version=v1,
 * fetched 2026-08-29): method events.ingest requires exactly this scope. */
define('SE_GDM_SCOPE', 'https://www.googleapis.com/auth/datamanager');

/** Refresh this many seconds before the token actually expires. */
define('SE_GDM_TOKEN_SKEW', 300);

/** Assumed token lifetime when the exchange reports none. */
define('SE_GDM_TOKEN_TTL_FALLBACK', 3600);

/** The library class the default provider is built on. */
define('SE_GDM_SA_CLASS', 'Google\\Auth\\Credentials\\ServiceAccountCredentials');

$GLOBALS['SE_GDM_TOKEN_PROVIDER'] = null;
$GLOBALS['SE_GDM_HTTP_HANDLER']   = null;
$GLOBALS['SE_GDM_LAST_FAILURE']   = [];

/**
 * Register a signer override. callable(array $credential, string $scope): array
 * with keys access_token and expires_in. Overrides the library-backed default;
 * used by tests and available to custom deployments.
 */
function se_gdm_register_token_provider(callable $p)
{
    $GLOBALS['SE_GDM_TOKEN_PROVIDER'] = $p;
}

/**
 * Inject the HTTP handler handed to the library's fetchAuthToken().
 * callable(Psr\Http\Message\RequestInterface): Psr\Http\Message\ResponseInterface.
 * Tests MUST register one so no socket is ever opened; pass null to clear
 * (live use then lets the library build its own Guzzle handler).
 */
function se_gdm_register_http_handler(?callable $h)
{
    $GLOBALS['SE_GDM_HTTP_HANDLER'] = $h;
}

/**
 * Make the composer autoloader reachable outside the web app.
 *
 * In the web app App_Controller has already required APPPATH/vendor/autoload.php
 * so the class simply exists. In CLI/test contexts, honour APPPATH when it is
 * defined, else an SE_VENDOR_AUTOLOAD constant (defined by the test runner).
 */
function se_gdm_vendor_autoload()
{
    if (class_exists(SE_GDM_SA_CLASS)) {
        return true;
    }

    static $tried = false;

    if ($tried) {
        return false;
    }

    $tried = true;

    $path = '';
    if (defined('APPPATH')) {
        $path = APPPATH . 'vendor/autoload.php';
    } elseif (defined('SE_VENDOR_AUTOLOAD')) {
        $path = SE_VENDOR_AUTOLOAD;
    }

    if ($path !== '' && is_file($path)) {
        require_once $path;
    }

    return class_exists(SE_GDM_SA_CLASS);
}

/** Is the official google/auth library loadable? */
function se_gdm_library_available()
{
    return se_gdm_vendor_autoload();
}

/** A signer exists: a registered override, or the library-backed default. */
function se_gdm_token_provider_available()
{
    return is_callable($GLOBALS['SE_GDM_TOKEN_PROVIDER'] ?? null) || se_gdm_library_available();
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
 * Does the key DOCUMENT hold a usable private key?
 *
 * Local check only (openssl parse); no network, and nothing derived from the
 * key ever leaves this function — the return value is a plain boolean.
 */
function se_gdm_key_document_valid($credential)
{
    if (!is_array($credential) || empty($credential['private_key'])) {
        return false;
    }

    if (($credential['type'] ?? 'service_account') !== 'service_account') {
        return false;
    }

    $key = @openssl_pkey_get_private((string) $credential['private_key']);

    return $key !== false;
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
    $keyOk  = $cred !== null && se_gdm_key_document_valid($cred);

    return [
        'file_present'       => $secret['configured'],
        'file_readable'      => $secret['readable'],
        'file_mode_ok'       => $secret['mode_ok'],
        'credential_valid'   => $cred !== null,
        'key_document_valid' => $keyOk,
        // The service-account IDENTITY is not a secret; it is the account's
        // address and the owner needs it to grant access in Google Ads.
        'client_email'      => $cred['client_email'] ?? null,
        'project_id'        => $cred['project_id'] ?? null,
        'signer_available'  => se_gdm_token_provider_available(),
        'library_available' => se_gdm_library_available(),
        'token_cached'      => $cache['cached'],
        'token_expires_at'  => $cache['expires_at'],
        'token_valid_now'   => $cache['valid'],
        'last_auth_at'      => $secret['last_auth_at'],
        'last_error'        => $secret['last_error'],
        'ready'             => $keyOk && se_gdm_token_provider_available(),
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

    $GLOBALS['SE_GDM_LAST_FAILURE'] = [];
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
 * The classified reason the LAST mint attempt for a brand failed, this request.
 *
 * @return array{category:string,code:string,reason:string}|null
 *         category is 'authentication' or 'configuration'; reason is a fixed,
 *         sanitized phrase — never provider output, never key or token text.
 */
function se_gdm_last_token_failure($brand_id)
{
    return $GLOBALS['SE_GDM_LAST_FAILURE'][(int) $brand_id] ?? null;
}

/** Record a classified mint failure (in-process) + the sanitized option note. */
function se_gdm_note_token_failure($brand_id, $category, $code, $reason)
{
    $GLOBALS['SE_GDM_LAST_FAILURE'][(int) $brand_id] = [
        'category' => $category,
        'code'     => $code,
        'reason'   => $reason,
    ];

    se_secret_note_auth('google_sa', $brand_id, false, $reason);
}

/**
 * Extract the short OAuth error code ('invalid_grant', ...) from a token
 * response array, strictly allowlisted so nothing sensitive can ride along.
 */
function se_gdm_oauth_error_code($result)
{
    $code = is_array($result) ? ($result['error'] ?? '') : '';

    return (is_string($code) && preg_match('/^[a-z_]{1,40}$/', $code)) ? $code : '';
}

/**
 * Mint a token through the official library. Wrapped by the classifier in
 * se_gdm_fetch_access_token(); everything it throws is caught there.
 */
function se_gdm_library_fetch(array $credential)
{
    $creds   = new Google\Auth\Credentials\ServiceAccountCredentials(SE_GDM_SCOPE, $credential);
    $handler = $GLOBALS['SE_GDM_HTTP_HANDLER'] ?? null;

    return $creds->fetchAuthToken(is_callable($handler) ? $handler : null);
}

/**
 * Is this exception a defect of the KEY DOCUMENT rather than of the exchange?
 *
 * The library raises InvalidArgumentException/LogicException while validating
 * the document and DomainException/UnexpectedValueException from the RS256
 * signer when the private key is unusable — all before any HTTP request.
 */
function se_gdm_is_key_document_error($e)
{
    return $e instanceof InvalidArgumentException
        || $e instanceof LogicException
        || $e instanceof DomainException
        || $e instanceof UnexpectedValueException;
}

/**
 * If a thrown exchange failure carries an HTTP response (Guzzle
 * BadResponseException does), recover the short OAuth error code from it.
 * Duck-typed so nothing here hard-depends on Guzzle. Never returns body text.
 */
function se_gdm_oauth_code_from_exception($e)
{
    if (!method_exists($e, 'getResponse')) {
        return '';
    }

    try {
        $response = $e->getResponse();

        if (!is_object($response) || !method_exists($response, 'getBody')) {
            return '';
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return se_gdm_oauth_error_code($decoded);
    } catch (Throwable $ignored) {
        return '';
    }
}

/**
 * A currently-valid access token, minting one if needed.
 *
 * Default path: Google\Auth\Credentials\ServiceAccountCredentials with the
 * Data Manager scope, through the injectable HTTP handler. A registered
 * provider (tests, custom) overrides it. Refreshes SE_GDM_TOKEN_SKEW seconds
 * before expiry.
 *
 * @return string '' when gated — no credential, no signer, or a classified
 *                authentication/configuration failure (see
 *                se_gdm_last_token_failure()).
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
        se_gdm_note_token_failure($brand_id, 'configuration', 'no_credential',
            'no usable service-account credential');

        return '';
    }

    $registered = $GLOBALS['SE_GDM_TOKEN_PROVIDER'] ?? null;

    if (!is_callable($registered) && !se_gdm_library_available()) {
        se_gdm_note_token_failure($brand_id, 'configuration', 'no_provider',
            'no token provider registered (google/auth not installed)');

        return '';
    }

    try {
        $result = is_callable($registered)
            ? call_user_func($registered, $credential, SE_GDM_SCOPE)
            : se_gdm_library_fetch($credential);
    } catch (Throwable $e) {
        // Never record the exception message: it can quote the request, the
        // assertion or the key. A fixed phrase plus the class name is enough.
        if (se_gdm_is_key_document_error($e)) {
            se_gdm_note_token_failure($brand_id, 'configuration', 'bad_key_document',
                'service-account key document invalid or unusable');

            return '';
        }

        $oauth = se_gdm_oauth_code_from_exception($e);

        if (in_array($oauth, ['invalid_grant', 'invalid_client'], true)) {
            se_gdm_note_token_failure($brand_id, 'configuration', 'key_rejected',
                'token endpoint rejected the key (' . $oauth . ') - key invalid or expired');

            return '';
        }

        se_gdm_note_token_failure($brand_id, 'authentication', 'token_exchange_failed',
            'token exchange failed (' . basename(str_replace('\\', '/', get_class($e)))
            . ($oauth !== '' ? ': ' . $oauth : '') . ')');

        return '';
    }

    $token = is_array($result) ? (string) ($result['access_token'] ?? '') : '';

    if ($token === '') {
        $oauth = se_gdm_oauth_error_code($result);

        if (in_array($oauth, ['invalid_grant', 'invalid_client'], true)) {
            se_gdm_note_token_failure($brand_id, 'configuration', 'key_rejected',
                'token endpoint rejected the key (' . $oauth . ') - key invalid or expired');
        } else {
            se_gdm_note_token_failure($brand_id, 'authentication', 'no_token',
                'token exchange returned no token' . ($oauth !== '' ? ' (' . $oauth . ')' : ''));
        }

        return '';
    }

    $cache[$key] = [
        'token'      => $token,
        'expires_at' => time() + (int) ($result['expires_in'] ?? SE_GDM_TOKEN_TTL_FALLBACK),
    ];

    unset($GLOBALS['SE_GDM_LAST_FAILURE'][$key]);

    se_secret_note_auth('google_sa', $brand_id, true);

    return $token;
}
