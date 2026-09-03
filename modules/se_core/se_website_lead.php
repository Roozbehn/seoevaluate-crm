<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Authenticated website-to-CRM lead ingest.
 *
 * The website already owns durable retrying through its transactional outbox.
 * This side therefore has one job: accept a small allowlisted payload
 * idempotently. The website lead UUID is unique on tblleads, so an ambiguous
 * timeout can be retried without creating a second person.
 *
 * The enquiry text is intentionally absent. It is health-adjacent free text
 * and the website's privacy contract says it is not stored in the lead
 * database. This boundary gives it nowhere to arrive.
 */

define('SE_WEBSITE_LEAD_MAX_BODY_BYTES', 16384);

/** Constant-time bearer comparison against the per-brand file provider. */
function se_website_lead_authorized($brand_id, $authorization)
{
    $expected = se_secret_read('website_lead', (int) $brand_id);
    if ($expected === '' || !is_string($authorization)) {
        return false;
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $m)) {
        return false;
    }

    return hash_equals($expected, trim($m[1]));
}

/**
 * Country → ISO 3166-1 alpha-2. The website sends a code, but a lead that
 * was first captured by an older form version carries a name ("Türkiye") and
 * is re-sent whenever the same person submits again; refusing the whole
 * enquiry over an optional, unstored field lost real leads (2026-09-02). A
 * code passes, a known name maps, anything else is dropped — never fatal.
 */
function se_website_lead_country_code($raw)
{
    $v = trim((string) $raw);
    if ($v === '') {
        return '';
    }
    if (preg_match('/^[A-Za-z]{2}$/', $v)) {
        return strtoupper($v);
    }
    $key = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $v), 'UTF-8');
    $names = [
        'türkiye' => 'TR', 'turkiye' => 'TR', 'turkey' => 'TR', 'tuerkei' => 'TR', 'türkei' => 'TR',
        'germany' => 'DE', 'deutschland' => 'DE', 'almanya' => 'DE',
        'united kingdom' => 'GB', 'uk' => 'GB', 'england' => 'GB', 'ingiltere' => 'GB', 'i̇ngiltere' => 'GB',
        'united states' => 'US', 'usa' => 'US', 'amerika' => 'US',
        'netherlands' => 'NL', 'hollanda' => 'NL', 'france' => 'FR', 'fransa' => 'FR',
        'iran' => 'IR', 'i̇ran' => 'IR', 'iraq' => 'IQ', 'irak' => 'IQ', 'azerbaijan' => 'AZ', 'azerbaycan' => 'AZ',
        'saudi arabia' => 'SA', 'suudi arabistan' => 'SA', 'united arab emirates' => 'AE', 'uae' => 'AE', 'bae' => 'AE',
        'qatar' => 'QA', 'katar' => 'QA', 'kuwait' => 'KW', 'kuveyt' => 'KW', 'egypt' => 'EG', 'mısır' => 'EG',
        'belgium' => 'BE', 'belçika' => 'BE', 'austria' => 'AT', 'avusturya' => 'AT', 'switzerland' => 'CH', 'i̇sviçre' => 'CH', 'isviçre' => 'CH',
        'sweden' => 'SE', 'i̇sveç' => 'SE', 'isveç' => 'SE', 'norway' => 'NO', 'norveç' => 'NO', 'denmark' => 'DK', 'danimarka' => 'DK',
        'italy' => 'IT', 'i̇talya' => 'IT', 'italya' => 'IT', 'spain' => 'ES', 'i̇spanya' => 'ES', 'ispanya' => 'ES',
        'russia' => 'RU', 'rusya' => 'RU', 'ukraine' => 'UA', 'ukrayna' => 'UA', 'kazakhstan' => 'KZ', 'kazakistan' => 'KZ',
        'canada' => 'CA', 'kanada' => 'CA', 'australia' => 'AU', 'avustralya' => 'AU', 'greece' => 'GR', 'yunanistan' => 'GR',
        'bulgaria' => 'BG', 'bulgaristan' => 'BG', 'romania' => 'RO', 'romanya' => 'RO', 'poland' => 'PL', 'polonya' => 'PL',
    ];

    return $names[$key] ?? '';
}

/**
 * Validate and normalize the JSON contract. Unknown fields fail closed.
 * Returns [ok => bool, data? => array, reason? => string].
 */
function se_website_lead_validate($payload)
{
    if (!is_array($payload)) {
        return ['ok' => false, 'reason' => 'invalid_payload'];
    }

    $allowed = [
        'external_id', 'name', 'email', 'phone', 'country',
        'preferred_language', 'contact_consent', 'occurred_at', 'attribution',
    ];
    if (array_diff(array_keys($payload), $allowed)) {
        return ['ok' => false, 'reason' => 'unknown_field'];
    }

    $external = trim((string) ($payload['external_id'] ?? ''));
    $name     = trim((string) ($payload['name'] ?? ''));
    $email    = trim((string) ($payload['email'] ?? ''));
    $phone    = trim((string) ($payload['phone'] ?? ''));
    $country  = se_website_lead_country_code((string) ($payload['country'] ?? ''));
    $language = strtolower(trim((string) ($payload['preferred_language'] ?? '')));
    $occurred = trim((string) ($payload['occurred_at'] ?? ''));

    if (!preg_match('/^[a-f0-9-]{36}$/i', $external)
        || $name === '' || mb_strlen($name) > 191
        || ($email === '' && $phone === '')
        || ($email !== '' && (mb_strlen($email) > 191 || !filter_var($email, FILTER_VALIDATE_EMAIL)))
        || ($phone !== '' && (mb_strlen($phone) > 32 || !preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)))
        || ($language !== '' && !preg_match('/^[a-z]{2,3}(?:-[a-z]{2})?$/', $language))
        || !array_key_exists('contact_consent', $payload)
        || !is_bool($payload['contact_consent'])
        || ($occurred !== '' && strtotime($occurred) === false)) {
        return ['ok' => false, 'reason' => 'invalid_payload'];
    }

    $attr = $payload['attribution'] ?? [];
    if (!is_array($attr)) {
        return ['ok' => false, 'reason' => 'invalid_attribution'];
    }

    $attrAllowed = [
        'utm_source', 'utm_medium', 'utm_campaign', 'fbclid', 'gclid',
        'gbraid', 'wbraid', 'landing_path', 'attribution_token',
    ];
    if (array_diff(array_keys($attr), $attrAllowed)) {
        return ['ok' => false, 'reason' => 'unknown_attribution_field'];
    }

    $cleanAttr = [];
    foreach ($attrAllowed as $key) {
        if (!array_key_exists($key, $attr) || $attr[$key] === null || $attr[$key] === '') {
            continue;
        }
        if (!is_string($attr[$key]) || mb_strlen($attr[$key]) > ($key === 'landing_path' ? 1000 : 255)) {
            return ['ok' => false, 'reason' => 'invalid_attribution'];
        }
        $cleanAttr[$key] = trim($attr[$key]);
    }

    return ['ok' => true, 'data' => [
        'external_id'        => strtolower($external),
        'name'               => $name,
        'email'              => $email,
        'phone'              => $phone,
        'country'            => $country,
        'preferred_language' => $language,
        'contact_consent'    => $payload['contact_consent'],
        'occurred_at'        => $occurred,
        'attribution'        => $cleanAttr,
    ]];
}

function se_website_lead_default_status($brand_id)
{
    $configured = (int) get_option('se_website_default_status_' . (int) $brand_id);
    if ($configured > 0) {
        return $configured;
    }
    if (function_exists('se_leadgen_default_status')) {
        return se_leadgen_default_status((int) $brand_id);
    }

    $CI = &get_instance();
    $row = $CI->db->select('id')->order_by('statusorder', 'ASC')->limit(1)
        ->get(db_prefix() . 'leads_status')->row();
    return $row ? (int) $row->id : 0;
}

function se_website_lead_default_source($brand_id)
{
    $configured = (int) get_option('se_website_default_source_' . (int) $brand_id);
    if ($configured > 0) {
        return $configured;
    }
    if (function_exists('se_leadgen_default_source')) {
        return se_leadgen_default_source((int) $brand_id);
    }

    $CI = &get_instance();
    $row = $CI->db->select('id')->order_by('id', 'ASC')->limit(1)
        ->get(db_prefix() . 'leads_sources')->row();
    return $row ? (int) $row->id : 0;
}

/**
 * Insert or return the existing CRM lead.
 *
 * Contact consent is recorded under purpose=marketing. It is deliberately
 * NOT purpose=ads: cookie-banner advertising consent is a separate decision.
 */
function se_website_lead_upsert($brand_id, array $data)
{
    $CI    = &get_instance();
    $table = db_prefix() . 'leads';

    $CI->db->where('website_lead_id', $data['external_id']);
    $existing = $CI->db->get($table)->row();
    if ($existing) {
        return (int) $existing->brand_id === (int) $brand_id
            ? ['ok' => true, 'lead_id' => (int) $existing->id, 'duplicate' => true]
            : ['ok' => false, 'reason' => 'brand_mismatch'];
    }

    $status = se_website_lead_default_status($brand_id);
    $source = se_website_lead_default_source($brand_id);
    if ($status <= 0 || $source <= 0) {
        return ['ok' => false, 'reason' => 'pipeline_unconfigured'];
    }

    $a = $data['attribution'];
    $row = [
        'website_lead_id' => $data['external_id'],
        'brand_id'        => (int) $brand_id,
        'name'            => $data['name'],
        'email'           => $data['email'],
        'phonenumber'     => $data['phone'],
        'status'          => $status,
        'source'          => $source,
        'assigned'        => 0,
        'addedfrom'       => 0,
        'is_public'       => 0,
        'description'     => '',
        'address'         => '',
        'country'         => 0,
        'dateadded'       => date('Y-m-d H:i:s'),
        'consent_marketing' => $data['contact_consent'] ? 1 : 0,
        'utm_source'      => $a['utm_source'] ?? null,
        'utm_medium'      => $a['utm_medium'] ?? null,
        'utm_campaign'    => $a['utm_campaign'] ?? null,
        'fbclid'          => $a['fbclid'] ?? null,
        'gclid'           => $a['gclid'] ?? null,
        'gbraid'          => $a['gbraid'] ?? null,
        'wbraid'          => $a['wbraid'] ?? null,
        'landing_url'     => $a['landing_path'] ?? null,
        'first_touch_at'  => $data['occurred_at'] !== ''
            ? date('Y-m-d H:i:s', strtotime($data['occurred_at']))
            : date('Y-m-d H:i:s'),
    ];

    $CI->db->trans_begin();
    $inserted = $CI->db->insert($table, $row);
    $leadId   = $inserted ? (int) $CI->db->insert_id() : 0;

    // A concurrent retry can win the unique key between our read and insert.
    if ($leadId <= 0) {
        $CI->db->trans_rollback();
        $CI->db->where('website_lead_id', $data['external_id']);
        $raced = $CI->db->get($table)->row();
        return $raced && (int) $raced->brand_id === (int) $brand_id
            ? ['ok' => true, 'lead_id' => (int) $raced->id, 'duplicate' => true]
            : ['ok' => false, 'reason' => 'insert_failed'];
    }

    if ($data['contact_consent']) {
        se_consent_grant((int) $brand_id, $leadId, 'marketing', 'website',
            'consultation_contact_permission', 'yes');
    } else {
        se_consent_withdraw((int) $brand_id, $leadId, 'marketing', 'website',
            'consultation_contact_permission', 'no');
    }

    if ($CI->db->trans_status() === false) {
        $CI->db->trans_rollback();
        return ['ok' => false, 'reason' => 'insert_failed'];
    }

    $CI->db->trans_commit();
    hooks()->do_action('lead_created', $leadId);

    /* A new enquiry is the one notification nobody minds receiving. Brand
     * staff rather than an assignee: a lead has no owner yet, and that is
     * precisely why someone should look. */
    if (function_exists('se_push_notify_lead')) {
        se_push_notify_lead((int) $brand_id, (int) $leadId, 'website');
    }
    log_activity('Website lead ingested [ID: ' . $leadId . ']');

    return ['ok' => true, 'lead_id' => $leadId, 'duplicate' => false];
}
