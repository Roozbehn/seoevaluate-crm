<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Evidence-based webhook verification state for a provider ('meta' | 'wa').
 *
 * A verify-token FILE existing is NOT "webhook verified". These six states are
 * each backed by a concrete fact — a readable secret, a route self-check, or a
 * stored timestamp from an event that actually happened:
 *
 *   1. verify_token_installed : the verify-token secret is readable.
 *   2. verification_ready     : the route is reachable (a self-check reached the
 *                               controller) AND the verify token is readable.
 *   3. challenge_verified     : a request with the CORRECT verify token actually
 *                               returned the challenge. Timestamp + source
 *                               ('self_test' loopback, or 'meta' real callback).
 *   4. app_secret_installed   : the Meta App Secret is securely readable
 *                               (WhatsApp inherits meta_app).
 *   5. signed_post_received   : a real POST with a VALID X-Hub-Signature-256 was
 *                               accepted. Timestamp stored.
 *   6. live_test_passed       : the event was processed into the intended CRM
 *                               workflow (a lead, or a contact+conversation).
 *
 * Nothing here marks production "verified" on the strength of a wrong-token 403
 * or a file merely existing. Only an actual correct-token challenge sets (3),
 * and its source is recorded so a self-test is never presented as Meta's own
 * callback.
 */

/** Resolve the provider's secret booleans (never a value). */
function se_webhook_provider_secrets($provider)
{
    if ($provider === 'wa') {
        return [
            'verify'               => function_exists('se_wa_verify_token') ? se_wa_verify_token() !== '' : false,
            'app_secret'           => function_exists('se_wa_app_secret') ? se_wa_app_secret() !== '' : false,
            'app_secret_inherited' => function_exists('se_wa_app_secret_inherited') ? se_wa_app_secret_inherited() : false,
        ];
    }

    return [
        'verify'               => function_exists('se_meta_verify_token') ? se_meta_verify_token() !== '' : false,
        'app_secret'           => function_exists('se_meta_app_secret') ? se_meta_app_secret() !== '' : false,
        'app_secret_inherited' => false,
    ];
}

/** The full six-state snapshot for a provider. Pure reads; no external calls. */
function se_webhook_state($provider)
{
    $provider = $provider === 'wa' ? 'wa' : 'meta';
    $s = se_webhook_provider_secrets($provider);
    $p = 'se_' . $provider . '_';

    $routeOk      = get_option($p . 'route_ok_at') ?: null;
    // PROVIDER evidence (Meta's own callbacks) and SELF-TEST evidence are
    // stored separately and NEVER mixed: a later self-test must not overwrite
    // or masquerade as provider traffic, and only provider evidence turns the
    // production states green.
    $challenge    = get_option($p . 'challenge_verified_at') ?: null;
    $challengeSt  = get_option($p . 'challenge_selftest_at') ?: null;
    $signed       = get_option($p . 'signed_post_at') ?: null;
    $signedSt     = get_option($p . 'signed_post_selftest_at') ?: null;
    $live         = get_option($p . 'live_test_at') ?: null;

    return [
        'provider'               => $provider,
        'verify_token_installed' => $s['verify'],
        'verification_ready'     => $s['verify'] && $routeOk !== null,
        'route_ok_at'            => $routeOk,
        // Green ONLY on Meta's real callback; self-test tracked separately.
        'challenge_verified'     => $challenge !== null,
        'challenge_verified_at'  => $challenge,
        'challenge_src'          => $challenge !== null ? 'meta' : null,
        'challenge_selftest_at'  => $challengeSt,
        'app_secret_installed'   => $s['app_secret'],
        'app_secret_inherited'   => $s['app_secret_inherited'],
        // Green ONLY on a provider-signed POST; self-test tracked separately.
        'signed_post_received'   => $signed !== null,
        'signed_post_at'         => $signed,
        'signed_post_src'        => $signed !== null ? 'meta' : null,
        'signed_post_selftest_at' => $signedSt,
        'live_test_passed'       => $live !== null,
        'live_test_at'           => $live,
    ];
}

/**
 * Record a concrete verification event. Only ever writes a timestamp (and, for
 * a challenge, its source) — never a secret. Safe to call from either module.
 *
 *   $event: 'route_ok' | 'challenge' | 'signed_post' | 'live_test'
 *   $args:  ['src' => 'self_test'|'meta'] for a challenge.
 */
function se_webhook_record($provider, $event, $args = [])
{
    if (!function_exists('update_option')) { return; }

    $provider = $provider === 'wa' ? 'wa' : 'meta';
    $p   = 'se_' . $provider . '_';
    $now = function_exists('se_db_now') ? se_db_now() : date('Y-m-d H:i:s');

    $selfTest = ($args['src'] ?? 'meta') === 'self_test';

    switch ($event) {
        case 'route_ok':
            update_option($p . 'route_ok_at', $now);
            break;
        case 'challenge':
            // Self-test evidence lives in its OWN option; it never touches the
            // provider timestamp, so a replay cannot downgrade real evidence.
            update_option($p . ($selfTest ? 'challenge_selftest_at' : 'challenge_verified_at'), $now);
            break;
        case 'signed_post':
            update_option($p . ($selfTest ? 'signed_post_selftest_at' : 'signed_post_at'), $now);
            break;
        case 'live_test':
            update_option($p . 'live_test_at', $now);
            break;
    }
}

/** True when the current request is the on-host verification self-test. */
function se_webhook_is_selftest()
{
    return isset($_SERVER['HTTP_X_SE_SELFTEST']) && (string) $_SERVER['HTTP_X_SE_SELFTEST'] !== '';
}
