<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

se_group('Website lead bearer authentication');

se_test_remove_secret('website_lead_22');
se_eq(false, se_website_lead_authorized(22, 'Bearer anything'), 'an absent server token fails closed');

se_test_install_secret('website_lead_22', 'fixture-website-token');
se_eq(true, se_website_lead_authorized(22, 'Bearer fixture-website-token'), 'the exact bearer is accepted');
se_eq(false, se_website_lead_authorized(22, 'Bearer wrong'), 'a wrong bearer is refused');
se_eq(false, se_website_lead_authorized(22, 'fixture-website-token'), 'the Bearer scheme is required');
se_eq(false, se_website_lead_authorized(23, 'Bearer fixture-website-token'), 'a token cannot cross brands');

se_group('Website lead route boundary');

$csrfUris = require dirname(__DIR__) . '/config/csrf_exclude_uris.php';
se_ok(in_array('se_core/website_lead', $csrfUris, true), 'the canonical machine route can reach bearer authentication');
se_eq(false, in_array('admin/se_core/website_lead', $csrfUris, true), 'the admin router alias stays CSRF-protected');
se_eq(false, count(array_filter($csrfUris, function ($uri) {
    return strpos($uri, '*') !== false || strpos($uri, '.+') !== false;
})) > 0, 'no wildcard widens the CSRF exception');
$controllerSource = file_get_contents(dirname(__DIR__) . '/controllers/Website_lead.php');
se_ok(strpos($controllerSource, 'se_clinic_sole_brand_id()') !== false,
    'the controller resolves the real single-clinic helper');

se_group('Website lead payload boundary');

$valid = [
    'external_id' => '123e4567-e89b-12d3-a456-426614174000',
    'name' => 'Fixture Person',
    'email' => 'fixture@example.com',
    'phone' => '+905551112233',
    'country' => 'tr',
    'preferred_language' => 'TR',
    'contact_consent' => true,
    'occurred_at' => '2026-09-01T08:30:00Z',
    'attribution' => [
        'utm_source' => 'meta', 'utm_campaign' => 'fixture',
        'fbclid' => 'fixture-click', 'landing_path' => '/tr/consultation',
    ],
];

$r = se_website_lead_validate($valid);
se_eq(true, $r['ok'], 'a complete allowlisted payload is accepted');
se_eq('TR', $r['data']['country'], 'country is normalized');
$named = $valid; $named['country'] = 'Türkiye';
$rn = se_website_lead_validate($named);
se_eq(true, $rn['ok'], "a country NAME (older form version, re-sent on a repeat submission) no longer refuses the enquiry");
se_eq('TR', $rn['data']['country'], 'a known name maps to its code');
$odd = $valid; $odd['country'] = 'Atlantis';
$ro = se_website_lead_validate($odd);
se_eq(true, $ro['ok'], 'an unknown country value is dropped, never fatal (the field is optional and not stored)');
se_eq('', $ro['data']['country'], 'dropped');
se_eq('tr', $r['data']['preferred_language'], 'language is normalized');
se_eq(true, $r['data']['contact_consent'], 'contact permission remains explicit');

$bad = $valid; $bad['brand_id'] = 22;
se_eq('unknown_field', se_website_lead_validate($bad)['reason'], 'the caller cannot choose a brand');

$bad = $valid; $bad['concern'] = 'health-adjacent text';
se_eq('unknown_field', se_website_lead_validate($bad)['reason'], 'free-text concern has no ingest field');

$bad = $valid; $bad['contact_consent'] = 'yes';
se_eq('invalid_payload', se_website_lead_validate($bad)['reason'], 'consent is a boolean, never truthy text');

$bad = $valid; $bad['phone'] = '0555 111 22 33';
se_eq('invalid_payload', se_website_lead_validate($bad)['reason'], 'phone must already be normalized E.164');

$bad = $valid; $bad['attribution']['unexpected'] = 'x';
se_eq('unknown_attribution_field', se_website_lead_validate($bad)['reason'], 'attribution is allowlisted too');

$emailOnly = $valid; $emailOnly['phone'] = '';
se_eq(true, se_website_lead_validate($emailOnly)['ok'], 'an email-only lead remains deliverable');

$phoneOnly = $valid; $phoneOnly['email'] = '';
se_eq(true, se_website_lead_validate($phoneOnly)['ok'], 'a phone-only lead remains deliverable');

$none = $valid; $none['email'] = ''; $none['phone'] = '';
se_eq('invalid_payload', se_website_lead_validate($none)['reason'], 'at least one reply channel is required');

/* ======================================================================== */
se_group('One person per phone across channels (audit K6 / T12 / CRM-M050): the web form reuses the WhatsApp lead');
$db = se_test_db();
$db->tables = []; $db->autoinc = [];
$db->seed('tblse_brands', [['id' => 1, 'name' => 'A', 'active' => 1]]);
$db->seed('tblleads_status', [['id' => 5, 'statusorder' => 1]]);
$db->seed('tblleads_sources', [['id' => 7]]);
$db->seed('tblse_consent_ledger', []);
$db->seed('tblleads', [['id' => 900, 'brand_id' => 1, 'name' => 'WhatsApp ••••2233', 'phonenumber' => '+905551112233', 'email' => '', 'website_lead_id' => null, 'consent_marketing' => 0]]);
$GLOBALS['se_test']['options'] = [];
$payload = se_website_lead_validate($valid)['data'];          // phone +905551112233, name "Fixture Person"
$payload['phone'] = '0555 111 22 33';                          // national format, spaces: same person
$r = se_website_lead_upsert(1, $payload);
se_eq(true, $r['ok'], 'accepted');
se_eq(900, (int) $r['lead_id'], 'the existing WhatsApp lead is reused');
se_eq('phone', $r['merged_by'] ?? '', 'merged by phone');
se_eq(1, count($db->rows('tblleads')), 'no second person was created');
$row = $db->rows('tblleads')[0];
se_eq('Fixture Person', $row['name'], 'the masked WhatsApp placeholder name is replaced by the real name');
se_eq($valid['external_id'], $row['website_lead_id'], 'the website id is stamped on the existing lead');
se_eq('fixture@example.com', $row['email'], 'email filled in');
se_eq(1, count(array_filter($db->rows('tblse_consent_ledger'), function ($c) { return (int) $c['rel_id'] === 900 && $c['purpose'] === 'marketing'; })), 'marketing consent recorded on that lead');
$r2 = se_website_lead_upsert(1, $payload);
se_eq(900, (int) $r2['lead_id'], 'a repeat submission (same external id) still returns the same lead');
$other = $payload; $other['external_id'] = '223e4567-e89b-12d3-a456-426614174999'; $other['phone'] = '+905559998877';
$r3 = se_website_lead_upsert(1, $other);
se_eq(true, $r3['ok'] && (int) $r3['lead_id'] !== 900, 'a different phone creates a new person');
se_eq(2, count($db->rows('tblleads')), 'now two people');
