<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: SE Instagram
Description: Instagram Direct inbox (Instagram Messaging API via the Page-linked account) for the Azin Asgari – Kaş Ekimi, İstanbul clinic CRM. Signed webhook receiver, tenant routing, conversations/messages with ad-referral attribution, outbound queue. Same Meta app as Lead Ads and WhatsApp.
Version: 1.0.0
Requires at least: 3.4.1
*/

define('SE_INSTAGRAM_MODULE_NAME', 'se_instagram');

register_language_files(SE_INSTAGRAM_MODULE_NAME, [SE_INSTAGRAM_MODULE_NAME]);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/outbound.php';
require_once __DIR__ . '/transport.php';   // registers the live Send API transport when a token exists

register_activation_hook(SE_INSTAGRAM_MODULE_NAME, 'se_instagram_activation');

hooks()->add_action('admin_init', 'se_instagram_permissions');

// Async: drain webhook events + outbound queue after core cron tasks.
if (function_exists('se_cron_listener')) { se_cron_listener('se_ig_process_pending'); } else { hooks()->add_action('after_cron_run', 'se_ig_process_pending'); }
if (function_exists('se_cron_listener')) { se_cron_listener('se_ig_out_drain'); } else { hooks()->add_action('after_cron_run', 'se_ig_out_drain'); }

function se_instagram_activation()
{
    require_once __DIR__ . '/install.php';
}

function se_instagram_permissions()
{
    $caps = [
        'view'   => _l('permission_view') . '(' . _l('permission_global') . ')',
        'create' => _l('permission_create'),
        'edit'   => _l('permission_edit'),
        'delete' => _l('permission_delete'),
    ];
    register_staff_capabilities('se_instagram', ['capabilities' => $caps], _l('se_instagram'));
}
