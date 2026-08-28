<?php

defined('BASEPATH') or exit('No direct script access allowed');

$CI = &get_instance();

$charset = $CI->db->char_set;

/* ------------------------------------------------------------------ */
/* Brand registry                                                      */
/* ------------------------------------------------------------------ */

if (!$CI->db->table_exists(db_prefix() . 'se_brands')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "se_brands` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name` varchar(191) NOT NULL,
        `slug` varchar(64) NOT NULL,
        `active` tinyint(1) NOT NULL DEFAULT '1',
        `meta_page_id` varchar(32) DEFAULT NULL,
        `meta_ad_account_id` varchar(32) DEFAULT NULL,
        `meta_dataset_id` varchar(32) DEFAULT NULL,
        `whatsapp_waba_id` varchar(32) DEFAULT NULL,
        `whatsapp_phone_number_id` varchar(32) DEFAULT NULL,
        `google_ads_customer_id` varchar(16) DEFAULT NULL,
        `ga4_property_id` varchar(32) DEFAULT NULL,
        `gsc_site_url` varchar(255) DEFAULT NULL,
        `date_created` datetime NOT NULL,
        `last_updated` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `slug` (`slug`)
    ) ENGINE=InnoDB DEFAULT CHARSET=" . $charset . ';');
}

/* ------------------------------------------------------------------ */
/* Staff to brand mapping                                              */
/* ------------------------------------------------------------------ */

if (!$CI->db->table_exists(db_prefix() . 'se_staff_brands')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "se_staff_brands` (
        `staff_id` int(11) NOT NULL,
        `brand_id` int(11) NOT NULL,
        PRIMARY KEY (`staff_id`,`brand_id`),
        KEY `brand_id` (`brand_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=" . $charset . ';');
}

/* ------------------------------------------------------------------ */
/* Conversion outbox - the spine of the ad-platform integrations       */
/* ------------------------------------------------------------------ */

if (!$CI->db->table_exists(db_prefix() . 'se_conversion_outbox')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "se_conversion_outbox` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `brand_id` int(11) NOT NULL DEFAULT '0',
        `lead_id` int(11) NOT NULL DEFAULT '0',
        `destination` varchar(32) NOT NULL,
        `event_name` varchar(64) NOT NULL,
        `event_time` datetime NOT NULL,
        `payload` longtext,
        `status` varchar(16) NOT NULL DEFAULT 'pending',
        `attempts` int(11) NOT NULL DEFAULT '0',
        `last_error` text,
        `sent_at` datetime DEFAULT NULL,
        `dedup_key` varchar(191) NOT NULL,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `dedup_key` (`dedup_key`),
        KEY `status_event_time` (`status`,`event_time`),
        KEY `lead_id` (`lead_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=" . $charset . ';');
}

/* ------------------------------------------------------------------ */
/* brand_id on the scoped core tables                                  */
/* ------------------------------------------------------------------ */

$brand_columns = [
    db_prefix() . 'leads'   => 'id',
    db_prefix() . 'clients' => 'userid',
    db_prefix() . 'events'  => 'eventid',
];

foreach ($brand_columns as $table => $pk) {
    if (!$CI->db->field_exists('brand_id', $table)) {
        $CI->db->query('ALTER TABLE `' . $table . "` ADD COLUMN `brand_id` int(11) NOT NULL DEFAULT '0'");
        $CI->db->query('ALTER TABLE `' . $table . '` ADD INDEX `brand_id` (`brand_id`)');
    }
}

/* ------------------------------------------------------------------ */
/* Attribution columns on leads                                        */
/*                                                                     */
/* Real columns, not Perfex custom fields: custom fields are stored     */
/* key-value, degrade past ~10 per entity and are painful to report on. */
/* ------------------------------------------------------------------ */

$leads = db_prefix() . 'leads';

$attribution = [
    // Google click identifiers. VARBINARY because GCLID is case sensitive and
    // the default utf8mb4_general_ci collation would silently break matching.
    'gclid'            => "varbinary(200) DEFAULT NULL",
    'gbraid'           => "varbinary(200) DEFAULT NULL",
    'wbraid'           => "varbinary(200) DEFAULT NULL",

    // Meta identifiers. meta_lead_id is the 15-17 digit leadgen_id and is
    // stored as a string - PHP mangles it as a float.
    'fbclid'           => "varchar(255) DEFAULT NULL",
    'fbc'              => "varchar(255) DEFAULT NULL",
    'fbp'              => "varchar(255) DEFAULT NULL",
    'meta_lead_id'     => "varchar(32) DEFAULT NULL",

    // Click-to-WhatsApp click id. Arrives only on the first inbound message,
    // so it is captured once and kept for the life of the record.
    'ctwa_clid'        => "varchar(255) DEFAULT NULL",

    'utm_source'       => "varchar(255) DEFAULT NULL",
    'utm_medium'       => "varchar(255) DEFAULT NULL",
    'utm_campaign'     => "varchar(255) DEFAULT NULL",
    'utm_term'         => "varchar(255) DEFAULT NULL",
    'utm_content'      => "varchar(255) DEFAULT NULL",

    'landing_url'      => "text",
    'referrer'         => "text",
    'first_touch_at'   => "datetime DEFAULT NULL",

    // Consent gates every outbound signal. Meta's CAPI has no consent field,
    // so enforcement is entirely ours.
    'consent_ads'      => "tinyint(1) NOT NULL DEFAULT '0'",
    'consent_marketing'=> "tinyint(1) NOT NULL DEFAULT '0'",
];

foreach ($attribution as $column => $definition) {
    if (!$CI->db->field_exists($column, $leads)) {
        $CI->db->query('ALTER TABLE `' . $leads . '` ADD COLUMN `' . $column . '` ' . $definition);
    }
}

if (!$CI->db->field_exists('meta_lead_id_idx', $leads)) {
    $indexes = $CI->db->query('SHOW INDEX FROM `' . $leads . "` WHERE Key_name = 'meta_lead_id'")->num_rows();

    if ($indexes === 0) {
        $CI->db->query('ALTER TABLE `' . $leads . '` ADD INDEX `meta_lead_id` (`meta_lead_id`)');
    }
}

/* ------------------------------------------------------------------ */
/* Web-to-lead forms belong to a brand                                 */
/*                                                                     */
/* The visitor never chooses the brand - the form does. One form per   */
/* clinic keeps attribution unambiguous.                               */
/* ------------------------------------------------------------------ */

if (!$CI->db->field_exists('brand_id', db_prefix() . 'web_to_lead')) {
    $CI->db->query('ALTER TABLE `' . db_prefix() . "web_to_lead` ADD COLUMN `brand_id` int(11) NOT NULL DEFAULT '0'");
}
