<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Filesystem secret provider for cPanel.
 *
 * WHY NOT tbloptions
 * ------------------
 * Every ad-platform secret was designed to live as a plaintext row in
 * tbloptions. Anything with database read access — a dump, a backup file, a
 * support engineer, a SQL-injection foothold — then reads every token in the
 * clear, and every backup is a fresh copy of them.
 *
 * DESIGN
 * ------
 * - Secrets live in a directory OUTSIDE the document root, mode 700, one file
 *   per (provider, brand), mode 600. cPanel gives us a home directory above
 *   public_html, which is the only durable private storage on this host.
 * - The directory PATH is configuration, not a secret; it comes from the
 *   untracked app-config.php constant SE_SECRET_DIR, falling back to
 *   ~/_secrets. The path is never itself sensitive, but is not rendered.
 * - Nothing here writes a secret. There is no setter and no UI: an owner
 *   installs the file over SSH/cPanel File Manager. Code that cannot write a
 *   secret cannot accidentally log one.
 * - read() is the ONLY accessor and its return value must never be echoed,
 *   logged, stored in an option, or placed in an exception message.
 * - status() is what the UI uses: booleans and timestamps only.
 */

define('SE_SECRET_DEFAULT_DIR', '/home/hyundaic/_secrets');

/** Providers this system knows about. Adding one here makes it appear in the UI. */
function se_secret_providers()
{
    return [
        'meta_capi'     => ['label' => 'Meta Conversions API token', 'per_brand' => true],
        'meta_page'     => ['label' => 'Meta Page access token', 'per_brand' => true],
        'meta_app'      => ['label' => 'Meta app secret', 'per_brand' => false],
        'meta_verify'   => ['label' => 'Meta webhook verify token', 'per_brand' => false],
        'wa_app'        => ['label' => 'WhatsApp app secret', 'per_brand' => false],
        'wa_verify'     => ['label' => 'WhatsApp webhook verify token', 'per_brand' => false],
        'wa_token'      => ['label' => 'WhatsApp Cloud API token', 'per_brand' => false],
        'ig_verify'     => ['label' => 'Instagram webhook verify token', 'per_brand' => false],
        'ig_token'      => ['label' => 'Instagram messaging token (optional; inherits meta_page)', 'per_brand' => false],
        'google_sa'     => ['label' => 'Google service-account key', 'per_brand' => true],
        'landing_token' => ['label' => 'Landing-token HMAC secret', 'per_brand' => false],
        'website_lead'  => ['label' => 'Website lead-ingest token', 'per_brand' => true],
        'r2_media_key'  => ['label' => 'R2 media gateway key (Cloudflare Worker crm-media)', 'per_brand' => false],
        // Patient-journey data key: seals health answers, check-in replies and
        // photographs at rest (libsodium secretbox). 32 random bytes, base64.
        'journey_key'   => ['label' => 'Patient-journey encryption key (32 bytes, base64)', 'per_brand' => false],
        // VAPID keypair for web push, as JSON {public, private}. The PRIVATE
        // half authorises pushing to every registered subscription, so it
        // belongs here and never in the options table. The public half is not
        // a secret but must stay STABLE — regenerating it silently kills every
        // existing subscription with nothing in any log.
        'webpush_vapid' => ['label' => 'Web push VAPID keypair (JSON {public, private})', 'per_brand' => false],
    ];
}

/** The configured secret directory. Configuration, not a secret. */
function se_secret_dir()
{
    return defined('SE_SECRET_DIR') ? SE_SECRET_DIR : SE_SECRET_DEFAULT_DIR;
}

/** Absolute path for one provider/brand. Never rendered to a user. */
function se_secret_path($provider, $brand_id = 0)
{
    $providers = se_secret_providers();

    if (!isset($providers[$provider])) {
        return null;
    }

    $name = $providers[$provider]['per_brand'] && (int) $brand_id > 0
        ? $provider . '_' . (int) $brand_id
        : $provider;

    // Defensive: the name is built from an allowlisted key plus an integer, so
    // no traversal is possible, but assert it anyway.
    if (!preg_match('/^[a-z0-9_]+$/', $name)) {
        return null;
    }

    return rtrim(se_secret_dir(), '/') . '/' . $name;
}

/**
 * Read a secret. The ONLY accessor.
 *
 * @return string '' when absent or unreadable — callers treat that as "gated"
 *                and must not distinguish the two in any user-visible output.
 */
function se_secret_read($provider, $brand_id = 0)
{
    $path = se_secret_path($provider, $brand_id);

    if ($path === null || !is_file($path) || !is_readable($path)) {
        return '';
    }

    $value = @file_get_contents($path);

    return $value === false ? '' : trim($value);
}

/** Is a secret installed and readable? Boolean only. */
function se_secret_configured($provider, $brand_id = 0)
{
    return se_secret_read($provider, $brand_id) !== '';
}

/**
 * Safe status for one provider/brand.
 *
 * Returns booleans, a mode string and timestamps. Never the value, never a
 * prefix, never a length — a length narrows a brute force and tells an
 * observer which credential type is installed.
 */
function se_secret_status($provider, $brand_id = 0)
{
    $path = se_secret_path($provider, $brand_id);

    $exists     = $path !== null && is_file($path);
    $readable   = $exists && is_readable($path);
    $configured = $readable && se_secret_read($provider, $brand_id) !== '';

    $mode = null;
    $modeOk = null;

    if ($exists) {
        $perms  = @fileperms($path);
        $mode   = $perms === false ? null : substr(sprintf('%o', $perms), -3);
        $modeOk = $mode === '600';
    }

    // A shared secret may be INHERITED from a canonical one, so the UI can say
    // so instead of showing a working provider as "missing":
    //   - wa_app inherits meta_app (same Meta app);
    //   - meta_capi inherits meta_page (the dataset is assigned to the same
    //     system user, verified live: events_received=1 with that token).
    // Never label an effective inherited credential as missing.
    $inheritedFrom = null;
    if ($provider === 'wa_app' && !$configured && se_secret_read('meta_app') !== '') {
        $inheritedFrom = 'meta_app';
    }
    if ($provider === 'meta_capi' && !$configured) {
        if (se_secret_read('meta_page', (int) $brand_id) !== '') {
            $inheritedFrom = 'meta_page' . ((int) $brand_id > 0 ? '_' . (int) $brand_id : '');
        } elseif (se_secret_read('meta_page', 0) !== '') {
            $inheritedFrom = 'meta_page';
        }
    }

    return [
        'provider'    => $provider,
        'brand_id'    => (int) $brand_id,
        'configured'  => $configured || $inheritedFrom !== null,
        'own_file'    => $configured,           // a dedicated file really exists
        'inherited_from' => $inheritedFrom,     // null, or the canonical provider key
        'readable'    => $readable,
        'mode'        => $mode,
        'mode_ok'     => $modeOk,
        // The expected filename is CONFIGURATION, safe to show — it helps the
        // owner install the right file, and is never the value.
        'expected_file' => basename((string) $path),
        'installed_at' => $exists ? date('Y-m-d H:i:s', (int) @filemtime($path)) : null,
        'last_auth_at' => get_option('se_secret_last_auth_' . $provider . '_' . (int) $brand_id) ?: null,
        'last_error'   => get_option('se_secret_last_error_' . $provider . '_' . (int) $brand_id) ?: null,
    ];
}

/** Status for every provider across every brand the caller can reach. */
function se_secret_status_all()
{
    $out    = [];
    $brands = se_all_brands(false, true);

    foreach (se_secret_providers() as $key => $meta) {
        if ($meta['per_brand']) {
            foreach ($brands as $b) {
                $row = se_secret_status($key, (int) $b['id']);
                $row['label']      = $meta['label'];
                $row['brand_name'] = $b['name'];
                $out[] = $row;
            }
        } else {
            $row = se_secret_status($key, 0);
            $row['label']      = $meta['label'];
            $row['brand_name'] = null;
            $out[] = $row;
        }
    }

    return $out;
}

/** Status of the store itself: does the directory exist with safe permissions? */
function se_secret_store_status()
{
    $dir = se_secret_dir();

    $exists = is_dir($dir);
    $mode   = null;

    if ($exists) {
        $perms = @fileperms($dir);
        $mode  = $perms === false ? null : substr(sprintf('%o', $perms), -3);
    }

    // Inside the document root would defeat the entire point.
    $docroot = rtrim(FCPATH, '/');
    $inside  = strpos(realpath($dir) ?: $dir, $docroot) === 0;

    return [
        'exists'    => $exists,
        'mode'      => $mode,
        'mode_ok'   => $mode === '700',
        'outside_docroot' => !$inside,
        'configured_path' => defined('SE_SECRET_DIR'),
        // The absolute path is CONFIGURATION, not a secret, so the UI can derive
        // owner instructions from the REAL resolved path instead of a hard-coded
        // one that may be wrong for this host.
        'dir'       => $dir,
    ];
}

/**
 * Record the outcome of an authentication attempt.
 * Stores a timestamp or a SANITIZED reason — never the credential or a
 * provider error body, which routinely quotes the request back.
 */
function se_secret_note_auth($provider, $brand_id, $ok, $reason = '')
{
    $suffix = $provider . '_' . (int) $brand_id;

    if ($ok) {
        update_option('se_secret_last_auth_' . $suffix, date('Y-m-d H:i:s'));
        update_option('se_secret_last_error_' . $suffix, '');

        return;
    }

    $reason = preg_replace('/[A-Za-z0-9_\-\.]{24,}/', '[redacted]', (string) $reason);
    update_option('se_secret_last_error_' . $suffix, mb_substr(trim($reason), 0, 160));
}
