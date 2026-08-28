<?php

defined('BASEPATH') or exit('No direct script access allowed');

$CI = &get_instance();

if (!$CI->db->table_exists(db_prefix() . 'se_appointments')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "se_appointments` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `brand_id` int(11) NOT NULL DEFAULT '0',
        `title` varchar(191) NOT NULL,
        `rel_type` varchar(20) NOT NULL DEFAULT 'lead',
        `rel_id` int(11) NOT NULL DEFAULT '0',
        `staff_id` int(11) NOT NULL DEFAULT '0',
        `procedure_interest` int(11) NOT NULL DEFAULT '0',
        `start_at` datetime NOT NULL,
        `end_at` datetime DEFAULT NULL,
        `status` varchar(20) NOT NULL DEFAULT 'scheduled',
        `location` varchar(191) DEFAULT NULL,
        `notes` mediumtext,
        `reminder_sent` tinyint(1) NOT NULL DEFAULT '0',
        `google_event_id` varchar(255) DEFAULT NULL,
        `date_created` datetime NOT NULL,
        `last_updated` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `brand_id` (`brand_id`),
        KEY `rel` (`rel_type`,`rel_id`),
        KEY `start_at` (`start_at`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');
}
