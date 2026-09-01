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
