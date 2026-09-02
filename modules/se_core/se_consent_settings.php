<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Consent TEXT configuration — the server-side source of truth.
 *
 * The consent-text version and the wording shown to a visitor are configuration,
 * never request data. A hidden form field carrying "consent_text_version" is
 * attacker-controlled: anyone can post any string, and the ledger would then
 * record a version that was never approved. So the browser supplies the
 * ANSWER; the server supplies the QUESTION, the wording and the version.
 *
 * FAIL CLOSED: with no approved text configured for a brand, the web-to-lead
 * form renders no grant-capable control at all. A checkbox with no approved
 * wording behind it cannot produce lawful consent, so it must not exist.
 */

/** Option key for one brand's consent configuration (0 = global default). */
function se_consent_config_key($brand_id = 0)
{
    return 'se_consent_config_' . (int) $brand_id;
}

/**
 * Purposes that can be configured independently.
 *
 * `health_data` and `photo_publication` carry the counsel-approved KVKK wording
 * the WhatsApp intake journey shows before collecting anything sensitive. With
 * no approved text for `health_data` the intake form refuses to open its
 * health sections (se_journey_health_collection_allowed()) — the same
 * fail-closed rule the web-to-lead form already applies to `ads`.
 */
function se_consent_configurable_purposes()
{
    return ['ads', 'marketing', 'health_data', 'photo_publication'];
}

/** Languages the consent text is maintained in. */
function se_consent_languages()
{
    return ['en' => 'English', 'tr' => 'Türkçe'];
}

/** Empty, safe default. Nothing is enabled and no text exists. */
function se_consent_empty_config()
{
    $purposes = [];

    foreach (se_consent_configurable_purposes() as $p) {
        $purposes[$p] = ['enabled' => 0, 'text' => ['en' => '', 'tr' => '']];
    }

    return [
        'version'      => '',
        'purposes'     => $purposes,
        'updated_at'   => null,
        'updated_by'   => 0,
    ];
}

/** Read a brand's configuration, falling back to the global default. */
function se_consent_config($brand_id = 0)
{
    $raw = get_option(se_consent_config_key($brand_id));
    $cfg = $raw ? json_decode($raw, true) : null;

    if (!is_array($cfg) && (int) $brand_id !== 0) {
        $raw = get_option(se_consent_config_key(0));
        $cfg = $raw ? json_decode($raw, true) : null;
    }

    if (!is_array($cfg)) {
        return se_consent_empty_config();
    }

    return array_merge(se_consent_empty_config(), $cfg);
}

/**
 * Is there approved, usable consent text for this brand and purpose?
 *
 * Requires an enabled purpose, a non-empty version identifier AND non-empty
 * text in every maintained language. A half-configured purpose is treated as
 * unconfigured, because showing a visitor an English-only notice in a Turkish
 * clinic is not informed consent.
 */
function se_consent_text_configured($brand_id = 0, $purpose = 'ads')
{
    $cfg = se_consent_config($brand_id);

    if (trim((string) $cfg['version']) === '') {
        return false;
    }

    $p = $cfg['purposes'][$purpose] ?? null;

    if (!$p || empty($p['enabled'])) {
        return false;
    }

    foreach (array_keys(se_consent_languages()) as $lang) {
        if (trim((string) ($p['text'][$lang] ?? '')) === '') {
            return false;
        }
    }

    return true;
}

/** The visitor-facing text for a brand/purpose/language. '' when unconfigured. */
function se_consent_text($brand_id, $purpose, $lang = 'en')
{
    if (!se_consent_text_configured($brand_id, $purpose)) {
        return '';
    }

    $cfg = se_consent_config($brand_id);

    return (string) ($cfg['purposes'][$purpose]['text'][$lang] ?? '');
}

/**
 * The authoritative consent-text version for a brand.
 * Resolved from configuration ONLY; a request can never influence it.
 */
function se_consent_configured_version($brand_id = 0)
{
    $cfg = se_consent_config($brand_id);
    $v   = trim((string) $cfg['version']);

    return $v !== '' ? mb_substr($v, 0, 32) : '';
}

/**
 * Validate and save configuration. Returns ['ok'=>bool,'errors'=>[]].
 *
 * There is deliberately no "pre-checked" option: a pre-ticked consent box is
 * not freely given consent, so the data model cannot express one.
 */
function se_consent_save_config($brand_id, array $input, $staff_id = 0)
{
    $errors = [];

    $version = trim((string) ($input['version'] ?? ''));

    if ($version === '') {
        $errors[] = 'version_required';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $version)) {
        $errors[] = 'version_invalid';
    }

    $cfg = se_consent_empty_config();
    $cfg['version'] = mb_substr($version, 0, 32);

    foreach (se_consent_configurable_purposes() as $purpose) {
        $enabled = !empty($input['purposes'][$purpose]['enabled']) ? 1 : 0;
        $texts   = [];

        foreach (array_keys(se_consent_languages()) as $lang) {
            $t = trim((string) ($input['purposes'][$purpose]['text'][$lang] ?? ''));
            // Plain text only: the notice is rendered as a label next to a
            // checkbox, and permitting markup there invites an injected link.
            $t = strip_tags($t);
            $texts[$lang] = mb_substr($t, 0, 2000);

            if ($enabled && $texts[$lang] === '') {
                $errors[] = 'text_required_' . $purpose . '_' . $lang;
            }
        }

        $cfg['purposes'][$purpose] = ['enabled' => $enabled, 'text' => $texts];
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    $cfg['updated_at'] = date('Y-m-d H:i:s');
    $cfg['updated_by'] = (int) $staff_id;

    update_option(se_consent_config_key($brand_id), json_encode($cfg));

    log_activity('SE consent config saved [brand ' . (int) $brand_id
        . ', version ' . $cfg['version'] . ', staff ' . (int) $staff_id . ']');

    return ['ok' => true, 'errors' => []];
}

/**
 * Should the 90-day attribution cookie be written for this brand?
 *
 * Tracking storage is gated on ads consent being configured AND enabled. The
 * previous behaviour wrote a 90-day first-party cookie on every page view
 * before anyone had agreed to anything.
 */
function se_consent_tracking_allowed($brand_id = 0)
{
    return se_consent_text_configured($brand_id, 'ads');
}
