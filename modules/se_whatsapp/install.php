<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * se_whatsapp install / idempotent schema.
 *
 * Six brand-scoped tables for a multi-tenant WhatsApp Cloud API inbox. All DDL
 * is guarded (IF NOT EXISTS) so activation is idempotent and deactivation never
 * drops a table — no operational data is lost on toggle. No token is ever stored
 * in these tables; a number row references an options key that holds the secret.
 */

$CI = &get_instance();
$p  = db_prefix();
$cs = 'utf8mb4';

$stmts = [];

/* 1) Numbers — one WhatsApp sender per brand. */
$stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_wa_numbers` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `brand_id` int(11) NOT NULL DEFAULT 0,
    `waba_id` varchar(32) DEFAULT NULL,
    `phone_number_id` varchar(32) NOT NULL,
    `display_number` varchar(32) DEFAULT NULL,
    `token_option_ref` varchar(64) DEFAULT NULL,
    `quality_rating` varchar(16) DEFAULT NULL,
    `messaging_tier` varchar(16) DEFAULT NULL,
    `state` varchar(16) NOT NULL DEFAULT 'test',
    `date_created` datetime NOT NULL,
    `last_updated` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `phone_number_id` (`phone_number_id`),
    KEY `brand_id` (`brand_id`),
    KEY `waba_id` (`waba_id`)
) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

/* 2) Conversations — one per (number, WhatsApp user). */
$stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_wa_conversations` (
    `id` bigint(20) NOT NULL AUTO_INCREMENT,
    `brand_id` int(11) NOT NULL DEFAULT 0,
    `phone_number_id` varchar(32) NOT NULL,
    `wa_user_id` varchar(32) NOT NULL,
    `lead_id` int(11) NOT NULL DEFAULT 0,
    `client_id` int(11) NOT NULL DEFAULT 0,
    `assigned_staff` int(11) NOT NULL DEFAULT 0,
    `unread_count` int(11) NOT NULL DEFAULT 0,
    `last_inbound_at` datetime DEFAULT NULL,
    `window_expires_at` datetime DEFAULT NULL,
    `ctwa_clid` varchar(255) DEFAULT NULL,
    `referral_json` text DEFAULT NULL,
    `state` varchar(16) NOT NULL DEFAULT 'open',
    `date_created` datetime NOT NULL,
    `last_updated` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `number_user` (`phone_number_id`,`wa_user_id`),
    KEY `brand_id` (`brand_id`),
    KEY `assigned_staff` (`assigned_staff`)
) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

/* 3) Messages — deduplicated on wamid. */
$stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_wa_messages` (
    `id` bigint(20) NOT NULL AUTO_INCREMENT,
    `conversation_id` bigint(20) NOT NULL DEFAULT 0,
    `brand_id` int(11) NOT NULL DEFAULT 0,
    `wamid` varchar(128) NOT NULL,
    `direction` varchar(8) NOT NULL,
    `type` varchar(24) NOT NULL DEFAULT 'text',
    `body` mediumtext DEFAULT NULL,
    `media_ref` varchar(191) DEFAULT NULL,
    `template_name` varchar(128) DEFAULT NULL,
    `delivery_state` varchar(16) DEFAULT NULL,
    `pricing_category` varchar(24) DEFAULT NULL,
    `billable` tinyint(1) NOT NULL DEFAULT 0,
    `sent_at` datetime DEFAULT NULL,
    `received_at` datetime DEFAULT NULL,
    `date_created` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `wamid` (`wamid`),
    KEY `conversation_id` (`conversation_id`),
    KEY `brand_id` (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

/* 4) Templates. */
$stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_wa_templates` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `brand_id` int(11) NOT NULL DEFAULT 0,
    `name` varchar(128) NOT NULL,
    `language` varchar(8) NOT NULL DEFAULT 'en',
    `category` varchar(24) DEFAULT NULL,
    `approval_state` varchar(16) DEFAULT NULL,
    `body` text DEFAULT NULL,
    `variables` varchar(255) DEFAULT NULL,
    `quality_state` varchar(16) DEFAULT NULL,
    `date_created` datetime NOT NULL,
    `last_updated` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `brand_name_lang` (`brand_id`,`name`,`language`),
    KEY `brand_id` (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

/* 5) Webhook event queue — durable, deduplicated, async-processed. */
$stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_wa_webhook_events` (
    `id` bigint(20) NOT NULL AUTO_INCREMENT,
    `event_hash` varchar(64) NOT NULL,
    `phone_number_id` varchar(32) DEFAULT NULL,
    `waba_id` varchar(32) DEFAULT NULL,
    `payload` longtext DEFAULT NULL,
    `signature_valid` tinyint(1) NOT NULL DEFAULT 0,
    `state` varchar(16) NOT NULL DEFAULT 'pending',
    `attempts` int(11) NOT NULL DEFAULT 0,
    `last_error` varchar(255) DEFAULT NULL,
    `received_at` datetime NOT NULL,
    `processed_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `event_hash` (`event_hash`),
    KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

/* 6) Metering — per-brand conversation/message billing counters. */
$stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_wa_metering` (
    `id` bigint(20) NOT NULL AUTO_INCREMENT,
    `brand_id` int(11) NOT NULL DEFAULT 0,
    `category` varchar(24) NOT NULL,
    `billable` tinyint(1) NOT NULL DEFAULT 1,
    `quantity` int(11) NOT NULL DEFAULT 1,
    `meter_date` date NOT NULL,
    `dedup_ref` varchar(191) NOT NULL,
    `date_created` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `dedup_ref` (`dedup_ref`),
    KEY `brand_id` (`brand_id`),
    KEY `meter_date` (`meter_date`)
) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

foreach ($stmts as $sql) {
    $CI->db->query($sql);
}
