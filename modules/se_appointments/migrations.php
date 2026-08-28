<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * se_appointments schema migrations — idempotent and repeatable.
 * Same pattern as se_core/migrations.php: one guarded statement list, run at
 * most once per deploy (version-gated) on admin_init, portable to any env.
 */

define('SE_APPT_SCHEMA_VERSION', 2);

function se_appt_migration_statements()
{
    $p = db_prefix();
    $cs = 'utf8mb4';
    $stmts = [];

    /* --- New appointment attributes -------------------------------------- */
    $cols = [
        'appointment_type'    => "varchar(64) DEFAULT NULL",
        'consultation_format' => "varchar(16) NOT NULL DEFAULT 'in_person'", // online | in_person
        'cancellation_reason' => "varchar(255) DEFAULT NULL",
        'staff_timezone'      => "varchar(64) DEFAULT NULL",
        'reminder_queued'     => "tinyint(1) NOT NULL DEFAULT 0",
    ];
    foreach ($cols as $c => $def) {
        $stmts[] = "ALTER TABLE `{$p}se_appointments` ADD COLUMN IF NOT EXISTS `{$c}` {$def}";
    }

    /* --- Status history --------------------------------------------------- */
    $stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_appointment_status_history` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `appointment_id` int(11) NOT NULL,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `old_status` varchar(20) DEFAULT NULL,
        `new_status` varchar(20) NOT NULL,
        `changed_by` int(11) NOT NULL DEFAULT 0,
        `changed_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `appointment_id` (`appointment_id`),
        KEY `brand_id` (`brand_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    /* --- Staff working hours (availability) ------------------------------- */
    $stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_working_hours` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `staff_id` int(11) NOT NULL DEFAULT 0,
        `weekday` tinyint(1) NOT NULL,           /* 0=Sun .. 6=Sat */
        `start_time` time NOT NULL,
        `end_time` time NOT NULL,
        PRIMARY KEY (`id`),
        KEY `brand_id` (`brand_id`),
        KEY `staff_day` (`staff_id`,`weekday`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    /* --- Reminder queue (internal interface; WhatsApp consumes in Phase 3) - */
    $stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_reminders` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `appointment_id` int(11) NOT NULL DEFAULT 0,
        `type` varchar(32) NOT NULL DEFAULT 'appointment',
        `channel` varchar(16) NOT NULL DEFAULT 'whatsapp',
        `language` varchar(8) DEFAULT NULL,
        `template_ref` varchar(128) DEFAULT NULL,
        `scheduled_at` datetime NOT NULL,
        `state` varchar(16) NOT NULL DEFAULT 'pending',   /* pending|sent|failed|cancelled */
        `attempts` int(11) NOT NULL DEFAULT 0,
        `last_error` varchar(255) DEFAULT NULL,
        `sent_at` datetime DEFAULT NULL,
        `failed_at` datetime DEFAULT NULL,
        `dedup_key` varchar(191) NOT NULL,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `dedup_key` (`dedup_key`),
        KEY `brand_id` (`brand_id`),
        KEY `state_sched` (`state`,`scheduled_at`),
        KEY `appointment_id` (`appointment_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    return $stmts;
}

function se_appt_run_migrations(callable $exec)
{
    foreach (se_appt_migration_statements() as $sql) {
        $exec($sql);
    }
    return count(se_appt_migration_statements());
}

function se_appt_migrate()
{
    if ((int) get_option('se_appt_schema_version') >= SE_APPT_SCHEMA_VERSION) {
        return;
    }
    $CI = &get_instance();
    se_appt_run_migrations(function ($sql) use ($CI) { $CI->db->query($sql); });
    update_option('se_appt_schema_version', SE_APPT_SCHEMA_VERSION);
}
