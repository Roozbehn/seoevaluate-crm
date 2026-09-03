<?php
/**
 * CSRF exemption is anchored to the gateways path (CRM-M004 / AZCRM-SEC-001).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 3) . '/application/config/se_csrf_exempt.php';

se_group('CSRF gateways exemption is anchored');
se_eq(true,  se_csrf_gateways_exempt('/gateways/stripe/webhook'), 'a gateway callback path is exempt');
se_eq(true,  se_csrf_gateways_exempt('/index.php/gateways/paypal'), 'also behind index.php');
se_eq(false, se_csrf_gateways_exempt('/admin/se_journey/se_journey/action/6?x=gateways/'), 'gateways/ in the query string is NOT exempt');
se_eq(false, se_csrf_gateways_exempt('/admin/gateways/anything'), 'a nested admin path is NOT exempt');
se_eq(false, se_csrf_gateways_exempt('/admin/se_whatsapp/se_whatsapp/reply/12?gateways/'), 'the audit exploit URL is NOT exempt');
se_eq(false, se_csrf_gateways_exempt(''), 'empty URI is not exempt');
