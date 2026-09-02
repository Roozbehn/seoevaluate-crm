<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Consent decision engine and append-only ledger.
 *
 * THE DEFECT THIS REPLACES
 * ------------------------
 * Meta Lead Ads consent used to be decided by:
 *
 *     $consent = in_array(strtolower($val), ['yes','true','1','evet','onay'], true)
 *                || $val !== '';
 *
 * The trailing `|| $val !== ''` made ANY non-empty answer grant consent, so
 * "no", "hayır", "false" and "0" all granted permission to ship the lead's
 * hashed identifiers to Meta and Google. That is a strict-allowlist problem,
 * not a parsing problem, so the answer is now decided by an allowlist and
 * nothing else.
 *
 * DECISION RULES (in order)
 *   1. An answer on the affirmative allowlist  -> GRANTED.
 *   2. An answer on the negative allowlist     -> WITHDRAWN (explicit refusal).
 *   3. Blank                                   -> UNKNOWN  -> not granted.
 *   4. Anything else, including free text      -> UNKNOWN  -> not granted.
 *
 * Only rule 1 grants. There is no fallback that turns an unrecognised answer
 * into consent.
 *
 * THE LEDGER IS AUTHORITATIVE
 * ---------------------------
 * `tblse_consent_ledger` is append-only and is the source of truth. Withdrawal
 * is a new row, never an update or a delete. `tblleads.consent_ads` is a
 * derived convenience flag for fast filtering and is always recomputed from
 * the ledger; nothing may treat it as authoritative.
 */

define('SE_CONSENT_GRANTED', 'granted');
define('SE_CONSENT_WITHDRAWN', 'withdrawn');
define('SE_CONSENT_UNKNOWN', 'unknown');

/** Default consent-text version when the owner has not set one. */
define('SE_CONSENT_DEFAULT_VERSION', 'v1');

/**
 * Affirmative answers. Configurable per deployment via the
 * `se_consent_affirmative_values` option (comma-separated), because the exact
 * wording lives in the ad form and is a legal artefact, not a code constant.
 */
function se_consent_affirmative_values()
{
    $configured = trim((string) get_option('se_consent_affirmative_values'));

    if ($configured !== '') {
        return se_consent_split_config($configured);
    }

    return [
        'yes', 'y', 'true', '1', 'on', 'checked', 'agree', 'agreed',
        'i agree', 'accept', 'accepted', 'consent', 'opt_in', 'opt-in', 'optin',
        // Turkish
        'evet', 'onay', 'onayliyorum', 'onaylıyorum', 'kabul', 'kabul ediyorum',
        'izin veriyorum', 'katiliyorum', 'katılıyorum',
    ];
}

/**
 * Explicit refusals. Kept separate from "unknown" so a deliberate NO is
 * recorded as a withdrawal in the ledger rather than as an absence of data.
 */
function se_consent_negative_values()
{
    $configured = trim((string) get_option('se_consent_negative_values'));

    if ($configured !== '') {
        return se_consent_split_config($configured);
    }

    return [
        'no', 'n', 'false', '0', 'off', 'unchecked', 'disagree', 'decline',
        'declined', 'reject', 'rejected', 'deny', 'denied', 'opt_out', 'opt-out', 'optout',
        // Turkish ("hayir" included because Turkish uppercase I lowercases to a
        // dotless i under some locales and to "i" under PHP's default folding)
        'hayir', 'hayır', 'red', 'reddediyorum', 'istemiyorum', 'kabul etmiyorum',
        'onaylamiyorum', 'onaylamıyorum', 'izin vermiyorum',
    ];
}

function se_consent_split_config($configured)
{
    $parts = array_map(function ($v) {
        return se_consent_normalize_answer($v);
    }, explode(',', $configured));

    return array_values(array_filter($parts, function ($v) { return $v !== ''; }));
}

/**
 * Normalize a raw answer for comparison: trim, lowercase (UTF-8 aware) and
 * collapse whitespace. Never used to DECIDE anything on its own — only to look
 * the answer up in the allowlists.
 */
function se_consent_normalize_answer($raw)
{
    $v = (string) $raw;
    $v = trim($v);
    $v = function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
    $v = preg_replace('/\s+/u', ' ', $v);

    return (string) $v;
}

/**
 * ASCII fold, so "hayır"/"hayir" and "onaylıyorum"/"onayliyorum" compare equal
 * whatever the source encoding did to the Turkish dotless i.
 */
function se_consent_fold($normalized)
{
    return strtr($normalized, [
        'ı' => 'i', 'İ' => 'i', 'ş' => 's', 'ğ' => 'g',
        'ü' => 'u', 'ö' => 'o', 'ç' => 'c',
    ]);
}

/**
 * Decide one answer.
 *
 * @return string SE_CONSENT_GRANTED | SE_CONSENT_WITHDRAWN | SE_CONSENT_UNKNOWN
 */
function se_consent_decide($raw)
{
    $normalized = se_consent_normalize_answer($raw);

    if ($normalized === '') {
        return SE_CONSENT_UNKNOWN;   // blank is never consent
    }

    $folded = se_consent_fold($normalized);

    foreach (se_consent_affirmative_values() as $yes) {
        if ($normalized === $yes || $folded === se_consent_fold($yes)) {
            return SE_CONSENT_GRANTED;
        }
    }

    foreach (se_consent_negative_values() as $no) {
        if ($normalized === $no || $folded === se_consent_fold($no)) {
            return SE_CONSENT_WITHDRAWN;
        }
    }

    // Unrecognised free text. NOT consent.
    return SE_CONSENT_UNKNOWN;
}

/** Convenience: did this answer actually grant? */
function se_consent_is_granted($raw)
{
    return se_consent_decide($raw) === SE_CONSENT_GRANTED;
}

/**
 * The authoritative consent-text version.
 *
 * Server-controlled ONLY. A hidden client field is attacker-controlled and can
 * be set to any string, so it can never be the version we file in the ledger.
 * A client-supplied value may be recorded separately as a claim, never as the
 * authority.
 */
function se_consent_text_version($brand_id = 0)
{
    // The Consent Settings screen is the authority when it has been configured.
    if (function_exists('se_consent_configured_version')) {
        $configured = se_consent_configured_version($brand_id);

        if ($configured !== '') {
            return $configured;
        }
    }

    $perBrand = trim((string) get_option('se_consent_text_version_' . (int) $brand_id));

    if ($perBrand !== '') {
        return mb_substr($perBrand, 0, 32);
    }

    $global = trim((string) get_option('se_consent_text_version'));

    return $global !== '' ? mb_substr($global, 0, 32) : SE_CONSENT_DEFAULT_VERSION;
}

/* ---------------------------------------------------------------------------
 * Ledger — append-only, authoritative.
 * ------------------------------------------------------------------------- */

/**
 * Allowed consent purposes; anything else is rejected at the boundary.
 *
 *   ads               Meta/Google measurement of the lead's own conversion events.
 *   marketing         Promotional messages (optional, never required for care).
 *   whatsapp          Service messaging on WhatsApp (opt-out via İPTAL/DUR/STOP).
 *   health_data       KVKK special-category processing: health answers and
 *                     identifiable eyebrow/face photographs, for the
 *                     preliminary evaluation ONLY. Required before any health
 *                     question or photo is collected; separate from everything
 *                     else by design.
 *   photo_publication Use of photographs beyond evaluation (before/after,
 *                     social media). Optional, separate, never implied.
 */
function se_consent_purposes()
{
    return ['ads', 'marketing', 'whatsapp', 'health_data', 'photo_publication'];
}

/**
 * Append a consent event.
 *
 * Records WHICH question was answered and WHAT the raw answer was, so the
 * decision is auditable years later, plus the normalized form actually used
 * for the decision. The consent-text version is always resolved server-side.
 *
 * @return int new ledger row id, or 0 when rejected
 */
function se_consent_record(
    $brand_id,
    $rel_type,
    $rel_id,
    $purpose,
    $state,
    $version = null,
    $source = null,
    $recorded_by = 0,
    $question_key = null,
    $answer_raw = null
) {
    if (!in_array($purpose, se_consent_purposes(), true)) {
        return 0;
    }

    if (!in_array($state, [SE_CONSENT_GRANTED, SE_CONSENT_WITHDRAWN], true)) {
        return 0;
    }

    $rel_id = (int) $rel_id;

    if ($rel_id <= 0) {
        return 0;
    }

    /* Server-controlled, unconditionally.
     *
     * The $version parameter is deliberately IGNORED for resolution. It used
     * to be honoured whenever it was non-empty, which meant any caller that
     * forwarded a request field — and se_attr_persist() did exactly that with
     * a hidden `se_consent_text_version` input — filed an attacker-chosen
     * string as the approved version. The version is configuration; it can
     * only come from configuration. */
    $resolvedVersion = se_consent_text_version($brand_id);

    if ($version !== null && $version !== '' && (string) $version !== $resolvedVersion) {
        // Worth knowing about: something tried to assert a different version.
        log_activity('SE consent version override ignored [' . $rel_type . ' ' . $rel_id . ']');
    }

    $CI = &get_instance();

    $CI->db->insert(db_prefix() . 'se_consent_ledger', [
        'brand_id'             => (int) $brand_id,
        'rel_type'             => in_array($rel_type, ['lead', 'client'], true) ? $rel_type : 'lead',
        'rel_id'               => $rel_id,
        'purpose'              => $purpose,
        'state'                => $state,
        'consent_text_version' => $resolvedVersion,
        'source'               => $source !== null ? mb_substr((string) $source, 0, 64) : null,
        'consent_at'           => date('Y-m-d H:i:s'),
        'recorded_by'          => (int) $recorded_by,
        'question_key'         => $question_key !== null ? mb_substr((string) $question_key, 0, 191) : null,
        'answer_raw'           => $answer_raw !== null ? mb_substr((string) $answer_raw, 0, 255) : null,
        'answer_normalized'    => $answer_raw !== null ? mb_substr(se_consent_normalize_answer($answer_raw), 0, 64) : null,
    ]);

    return (int) $CI->db->insert_id();
}

/** Latest ledger row for a purpose, or null. */
function se_consent_current_row($brand_id, $rel_type, $rel_id, $purpose)
{
    $CI = &get_instance();

    $CI->db->where('brand_id', (int) $brand_id)
           ->where('rel_type', $rel_type)
           ->where('rel_id', (int) $rel_id)
           ->where('purpose', $purpose)
           ->order_by('id', 'DESC')
           ->limit(1);

    return $CI->db->get(db_prefix() . 'se_consent_ledger')->row();
}

/** Latest consent state for a purpose, or null if never recorded. */
function se_consent_current($brand_id, $rel_type, $rel_id, $purpose)
{
    $row = se_consent_current_row($brand_id, $rel_type, $rel_id, $purpose);

    return $row ? $row->state : null;
}

/** True only if the latest state for the purpose is granted. */
function se_consent_granted($brand_id, $rel_type, $rel_id, $purpose)
{
    return se_consent_current($brand_id, $rel_type, $rel_id, $purpose) === SE_CONSENT_GRANTED;
}

/**
 * Consent state AS AT a point in time — the historical reproducibility the
 * outbox snapshot needs.
 *
 * The ledger is append-only, so "what did we believe when the conversion
 * happened" is answerable exactly: take the newest row at or before $at.
 *
 * @return array{state:string,version:?string,source:?string,at:?string,ledger_id:int}
 */
function se_consent_state_at($brand_id, $rel_type, $rel_id, $purpose, $at)
{
    $CI = &get_instance();

    $CI->db->where('brand_id', (int) $brand_id)
           ->where('rel_type', $rel_type)
           ->where('rel_id', (int) $rel_id)
           ->where('purpose', $purpose)
           ->where('consent_at <=', $at)
           ->order_by('id', 'DESC')
           ->limit(1);

    $row = $CI->db->get(db_prefix() . 'se_consent_ledger')->row();

    if (!$row) {
        return [
            'state'     => SE_CONSENT_UNKNOWN,
            'version'   => null,
            'source'    => null,
            'at'        => null,
            'ledger_id' => 0,
        ];
    }

    return [
        'state'     => $row->state,
        'version'   => $row->consent_text_version,
        'source'    => $row->source,
        'at'        => $row->consent_at,
        'ledger_id' => (int) $row->id,
    ];
}

/* ---------------------------------------------------------------------------
 * Grant / withdraw.
 * ------------------------------------------------------------------------- */

/**
 * Record a grant and refresh the derived flag.
 */
function se_consent_grant($brand_id, $rel_id, $purpose, $source, $question_key = null, $answer_raw = null, $recorded_by = 0)
{
    $id = se_consent_record($brand_id, 'lead', $rel_id, $purpose, SE_CONSENT_GRANTED,
        null, $source, $recorded_by, $question_key, $answer_raw);

    if ($id && $purpose === 'ads') {
        se_consent_sync_lead_flag((int) $rel_id);
    }

    return $id;
}

/**
 * Withdraw consent.
 *
 * Documented policy, applied here:
 *   1. Append a withdrawal row (the ledger never loses history).
 *   2. Recompute the derived `consent_ads` flag so no NEW outbox event is
 *      produced — the producer gates on it.
 *   3. HOLD every unsent, still-eligible outbox row for this lead. They are
 *      moved to `skipped` with an explicit reason rather than deleted, so the
 *      decision stays auditable. Rows already `sent`/`submitted`/`confirmed`
 *      are left alone: they were transmitted under a consent that was valid at
 *      the time, and rewriting history would be the lie, not the fix.
 *
 * @return array{ledger_id:int,held:int}
 */
function se_consent_withdraw($brand_id, $rel_id, $purpose, $source, $question_key = null, $answer_raw = null, $recorded_by = 0)
{
    $id = se_consent_record($brand_id, 'lead', $rel_id, $purpose, SE_CONSENT_WITHDRAWN,
        null, $source, $recorded_by, $question_key, $answer_raw);

    $held = 0;

    if ($id && $purpose === 'ads') {
        se_consent_sync_lead_flag((int) $rel_id);
        $held = se_consent_hold_unsent_outbox((int) $brand_id, (int) $rel_id);
    }

    return ['ledger_id' => (int) $id, 'held' => $held];
}

/**
 * Recompute `tblleads.consent_ads` from the ledger.
 *
 * The flag is derived, never authoritative. Recomputing rather than toggling
 * means a withdrawal followed by a re-grant lands on the right value without
 * any caller having to reason about ordering.
 */
function se_consent_sync_lead_flag($lead_id)
{
    $CI = &get_instance();

    $lead = $CI->db->query(
        'SELECT `brand_id` AS brand_id FROM `' . db_prefix() . 'leads` WHERE `id` = ' . (int) $lead_id . ' LIMIT 1'
    )->row();

    if (!$lead) {
        return false;
    }

    $granted = se_consent_granted((int) $lead->brand_id, 'lead', (int) $lead_id, 'ads') ? 1 : 0;

    $CI->db->where('id', (int) $lead_id)
           ->update(db_prefix() . 'leads', ['consent_ads' => $granted]);

    return true;
}

/**
 * Park every unsent outbox row for a lead after a withdrawal.
 *
 * Only rows that have not left the CRM are touched.
 *
 * @return int rows held
 */
function se_consent_hold_unsent_outbox($brand_id, $lead_id)
{
    $CI = &get_instance();

    $CI->db->where('brand_id', (int) $brand_id)
           ->where('lead_id', (int) $lead_id)
           ->where_in('status', ['pending', 'processing'])
           ->update(db_prefix() . 'se_conversion_outbox', [
               'status'        => 'skipped',
               'failure_class' => 'consent_withdrawn',
               'error_code'    => 'consent_withdrawn',
               'last_error'    => 'consent withdrawn before transmission',
               'locked_at'     => null,
               'locked_by'     => null,
           ]);

    return (int) $CI->db->affected_rows();
}
