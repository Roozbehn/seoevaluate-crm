<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * se_core schema migrations — idempotent and repeatable.
 *
 * Single source of truth for every schema change made after the original
 * install.php. Both paths below run the SAME statement list:
 *   - runtime: se_core_migrate() on admin_init, gated on a stored version so it
 *     costs one option read per request once applied;
 *   - portable: tools/run_migrations can call se_core_migration_statements()
 *     against any environment.
 *
 * Every statement is guarded (IF NOT EXISTS / INSERT ... WHERE NOT EXISTS), so
 * re-running is always safe. MariaDB 10.11 supports IF NOT EXISTS on ADD COLUMN
 * / ADD INDEX / CREATE TABLE, which keeps the guards declarative.
 */

define('SE_CORE_SCHEMA_VERSION', 6);

/**
 * Ordered, idempotent DDL that brings a fresh install.php schema up to
 * SE_CORE_SCHEMA_VERSION. Pure data (no side effects) so it is trivially
 * testable and portable.
 */
function se_core_migration_statements()
{
    $p = db_prefix();

    $stmts = [];

    /* --- Phase 1.1: last-touch attribution + consent-text version --------- *
     * The original click IDs / UTMs / landing / referrer / first_touch_at stay
     * immutable first-touch data in their existing columns. Last-touch is kept
     * in clearly-named parallel columns so reporting never mutates the original.
     */
    $lastTouch = [
        'last_gclid'        => 'varbinary(200) DEFAULT NULL',
        'last_gbraid'       => 'varbinary(200) DEFAULT NULL',
        'last_wbraid'       => 'varbinary(200) DEFAULT NULL',
        'last_fbclid'       => 'varchar(255) DEFAULT NULL',
        'last_utm_source'   => 'varchar(255) DEFAULT NULL',
        'last_utm_medium'   => 'varchar(255) DEFAULT NULL',
        'last_utm_campaign' => 'varchar(255) DEFAULT NULL',
        'last_utm_term'     => 'varchar(255) DEFAULT NULL',
        'last_utm_content'  => 'varchar(255) DEFAULT NULL',
        'last_touch_at'     => 'datetime DEFAULT NULL',
        'consent_text_version' => 'varchar(32) DEFAULT NULL',
    ];
    foreach ($lastTouch as $col => $def) {
        $stmts[] = "ALTER TABLE `{$p}leads` ADD COLUMN IF NOT EXISTS `{$col}` {$def}";
    }

    /* --- Phase 1.4: outbox processing lease (atomic claim + recovery) ------ */
    $stmts[] = "ALTER TABLE `{$p}se_conversion_outbox` ADD COLUMN IF NOT EXISTS `locked_at` datetime DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_conversion_outbox` ADD COLUMN IF NOT EXISTS `locked_by` varchar(64) DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_conversion_outbox` ADD INDEX IF NOT EXISTS `claim` (`status`,`locked_at`)";

    /* --- Phase 1.3: complete indexed brand_id coverage -------------------- */
    $stmts[] = "ALTER TABLE `{$p}se_conversion_outbox` ADD INDEX IF NOT EXISTS `brand_id` (`brand_id`)";
    $stmts[] = "ALTER TABLE `{$p}web_to_lead` ADD INDEX IF NOT EXISTS `brand_id` (`brand_id`)";

    /* --- Phase 1.5: patient + consent layer (brand-scoped) ----------------- *
     * Keeps Perfex's native customer/contact model; adds only the operational
     * layer. Clinical data lives here and NEVER enters a Meta/Google payload.
     */
    $charset = 'utf8mb4';

    $stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_patients` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `client_id` int(11) NOT NULL DEFAULT 0,
        `lead_id` int(11) NOT NULL DEFAULT 0,
        `preferred_language` varchar(8) DEFAULT NULL,
        `nationality` varchar(64) DEFAULT NULL,
        `passport_no` varchar(64) DEFAULT NULL,
        `retention_state` varchar(16) NOT NULL DEFAULT 'active',
        `deletion_requested_at` datetime DEFAULT NULL,
        `date_created` datetime NOT NULL,
        `last_updated` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `brand_id` (`brand_id`),
        KEY `client_id` (`client_id`),
        KEY `lead_id` (`lead_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_consent_ledger` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `rel_type` varchar(16) NOT NULL DEFAULT 'lead',
        `rel_id` int(11) NOT NULL DEFAULT 0,
        `purpose` varchar(32) NOT NULL,
        `state` varchar(16) NOT NULL DEFAULT 'granted',
        `consent_text_version` varchar(32) DEFAULT NULL,
        `source` varchar(64) DEFAULT NULL,
        `consent_at` datetime NOT NULL,
        `recorded_by` int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `brand_id` (`brand_id`),
        KEY `rel` (`rel_type`,`rel_id`),
        KEY `purpose` (`purpose`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_procedure_history` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `patient_id` int(11) NOT NULL DEFAULT 0,
        `procedure_name` varchar(191) NOT NULL,
        `procedure_date` date DEFAULT NULL,
        `notes` mediumtext,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `brand_id` (`brand_id`),
        KEY `patient_id` (`patient_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_record_access_log` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `patient_id` int(11) NOT NULL DEFAULT 0,
        `staff_id` int(11) NOT NULL DEFAULT 0,
        `action` varchar(32) NOT NULL,
        `accessed_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `brand_id` (`brand_id`),
        KEY `patient_id` (`patient_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE=utf8mb4_unicode_ci";


    /* --- Phase 4: Meta Lead Ads (leadgen webhook queue + form mapping) ----- */
    $stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_meta_leadgen_events` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `leadgen_id` varchar(32) NOT NULL,
        `page_id` varchar(32) DEFAULT NULL,
        `form_id` varchar(32) DEFAULT NULL,
        `payload` longtext DEFAULT NULL,
        `signature_valid` tinyint(1) NOT NULL DEFAULT 0,
        `state` varchar(16) NOT NULL DEFAULT 'pending',
        `attempts` int(11) NOT NULL DEFAULT 0,
        `last_error` varchar(255) DEFAULT NULL,
        `received_at` datetime NOT NULL,
        `processed_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `leadgen_id` (`leadgen_id`),
        KEY `state` (`state`),
        KEY `form_id` (`form_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_meta_forms` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `page_id` varchar(32) DEFAULT NULL,
        `form_id` varchar(32) NOT NULL,
        `form_name` varchar(191) DEFAULT NULL,
        `field_map_json` text DEFAULT NULL,
        `active` tinyint(1) NOT NULL DEFAULT 1,
        `last_leadgen_at` datetime DEFAULT NULL,
        `date_created` datetime NOT NULL,
        `last_updated` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `form_id` (`form_id`),
        KEY `brand_id` (`brand_id`),
        KEY `page_id` (`page_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";


    /* --- Phase 5: Google Data Manager ingest request tracking ------------- */
    $stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_gdm_requests` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `request_id` varchar(191) NOT NULL,
        `event_count` int(11) NOT NULL DEFAULT 0,
        `status` varchar(16) NOT NULL DEFAULT 'submitted',
        `last_error` varchar(255) DEFAULT NULL,
        `created_at` datetime NOT NULL,
        `polled_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `brand_id` (`brand_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return $stmts;
}

/**
 * Runs the migration statements through a caller-supplied executor.
 * $exec is `function(string $sql): void`. Returns the count executed.
 */
function se_core_run_migrations(callable $exec)
{
    $stmts = se_core_migration_statements();
    foreach ($stmts as $sql) {
        $exec($sql);
    }

    return count($stmts);
}

/**
 * Runtime entry: apply pending schema on admin_init, gated on a stored version
 * so the DDL runs at most once per deploy rather than every request.
 */
function se_core_migrate()
{
    if ((int) get_option('se_core_schema_version') >= SE_CORE_SCHEMA_VERSION) {
        return;
    }

    $CI = &get_instance();
    se_core_run_migrations(function ($sql) use ($CI) {
        $CI->db->query($sql);
    });

    // Pipeline + consent-purpose seeding is idempotent DML, kept out of the DDL list.
    if (function_exists('se_pipeline_seed')) {
        se_pipeline_seed();
    }

    update_option('se_core_schema_version', SE_CORE_SCHEMA_VERSION);
}
