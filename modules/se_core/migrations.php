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

define('SE_CORE_SCHEMA_VERSION', 15);

/**
 * Ordered, idempotent DDL that brings a fresh install.php schema up to
 * SE_CORE_SCHEMA_VERSION. Pure data (no side effects) so it is trivially
 * testable and portable.
 */
function se_core_migration_statements()
{
    $p = db_prefix();

    $stmts = [];

    /* --- v13: idempotent website-to-CRM lead delivery ------------------- *
     * The public website's durable outbox identifies a lead with its UUID.
     * Keeping that identifier on the CRM row makes at-least-once HTTP
     * delivery safe: a timeout followed by a retry returns the first row
     * instead of creating a duplicate person.
     */
    $stmts[] = "ALTER TABLE `{$p}leads` ADD COLUMN IF NOT EXISTS `website_lead_id` varchar(64) DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}leads` ADD UNIQUE INDEX IF NOT EXISTS `website_lead_id` (`website_lead_id`)";

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


    /* --- Phase 6: imported external report metrics (GA4/GSC/Ads) ---------- */
    $stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_ext_metrics` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `source` varchar(32) NOT NULL,
        `metric` varchar(64) NOT NULL,
        `value` double NOT NULL DEFAULT 0,
        `period_date` date NOT NULL,
        `imported_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `brand_source_metric_date` (`brand_id`,`source`,`metric`,`period_date`),
        KEY `brand_id` (`brand_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /* =====================================================================
     * Phase 10 (schema v8) — additive only. No existing migration above is
     * modified, no column is dropped or retyped, every statement is guarded.
     * ===================================================================== */

    /* --- v8.1: conversion-outbox event snapshot --------------------------- *
     * Senders used to rebuild a historical conversion from the lead's CURRENT
     * mutable row, so a consent withdrawal or an edited identifier silently
     * changed what was transmitted for a past event. The snapshot is captured
     * once at queue time and is what the senders read from now on.
     * `payload_version` = 0 means "queued before this migration": those rows
     * keep the old live-lead behaviour, so no backfill or rewrite is needed.
     */
    $stmts[] = "ALTER TABLE `{$p}se_conversion_outbox` ADD COLUMN IF NOT EXISTS `attribution_snapshot` MEDIUMTEXT DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_conversion_outbox` ADD COLUMN IF NOT EXISTS `consent_snapshot` TEXT DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_conversion_outbox` ADD COLUMN IF NOT EXISTS `payload_version` tinyint(4) NOT NULL DEFAULT 0";

    /* --- v8.2: outbox delivery state machine ------------------------------ *
     * Distinguishes gated / retryable / permanent / submitted / confirmed
     * outcomes, and gives the drainer a backoff clock plus a fencing token so
     * an expired worker cannot overwrite a newer worker's result.
     */
    $stmts[] = "ALTER TABLE `{$p}se_conversion_outbox` ADD COLUMN IF NOT EXISTS `next_attempt_at` datetime DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_conversion_outbox` ADD COLUMN IF NOT EXISTS `failure_class` varchar(24) DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_conversion_outbox` ADD COLUMN IF NOT EXISTS `error_code` varchar(64) DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_conversion_outbox` ADD COLUMN IF NOT EXISTS `fence` bigint(20) NOT NULL DEFAULT 0";
    $stmts[] = "ALTER TABLE `{$p}se_conversion_outbox` ADD COLUMN IF NOT EXISTS `request_id` varchar(191) DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_conversion_outbox` ADD COLUMN IF NOT EXISTS `submitted_at` datetime DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_conversion_outbox` ADD INDEX IF NOT EXISTS `drain` (`status`,`next_attempt_at`)";
    $stmts[] = "ALTER TABLE `{$p}se_conversion_outbox` ADD INDEX IF NOT EXISTS `request_id` (`request_id`)";

    /* --- v8.3: consent ledger provenance ---------------------------------- *
     * The ledger is the authoritative record, so it has to carry WHICH question
     * was answered and WHAT the raw answer was, not just a granted/withdrawn
     * flag. `answer_raw` is bounded and never used for logic - only the
     * normalized decision is.
     */
    $stmts[] = "ALTER TABLE `{$p}se_consent_ledger` ADD COLUMN IF NOT EXISTS `question_key` varchar(191) DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_consent_ledger` ADD COLUMN IF NOT EXISTS `answer_raw` varchar(255) DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_consent_ledger` ADD COLUMN IF NOT EXISTS `answer_normalized` varchar(64) DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_consent_ledger` ADD INDEX IF NOT EXISTS `lookup` (`brand_id`,`rel_type`,`rel_id`,`purpose`,`consent_at`)";

    /* --- v8.4: patient archive state, separate from deletion requests ------ *
     * Archiving is an operational filing action. A deletion request is a data
     * subject exercising a right. Overloading one column onto the other loses
     * the legal signal, so they are now distinct.
     */
    $stmts[] = "ALTER TABLE `{$p}se_patients` ADD COLUMN IF NOT EXISTS `archived_at` datetime DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_patients` ADD COLUMN IF NOT EXISTS `archived_by` int(11) NOT NULL DEFAULT 0";
    $stmts[] = "ALTER TABLE `{$p}se_patients` ADD INDEX IF NOT EXISTS `brand_state` (`brand_id`,`retention_state`)";

    /* --- v8.5: tenant-safe uniqueness ------------------------------------- *
     * One patient row per (brand, lead) and per (brand, client). Enforced in the
     * database so a race cannot create the duplicate the model checks for.
     * Partial uniqueness is not available in MariaDB, and lead_id/client_id use
     * 0 for "not linked", so these are added as plain indexes and the model
     * enforces the business rule; see se_patient_link_conflict().
     */
    $stmts[] = "ALTER TABLE `{$p}se_patients` ADD INDEX IF NOT EXISTS `brand_lead` (`brand_id`,`lead_id`)";
    $stmts[] = "ALTER TABLE `{$p}se_patients` ADD INDEX IF NOT EXISTS `brand_client` (`brand_id`,`client_id`)";

    /* --- v8.6: appointment calendar sync state ---------------------------- *
     * Separates "a fixture ran" from "Google holds this event id", so a test
     * adapter can never leave a real row believing it is synced.
     */
    $stmts[] = "ALTER TABLE `{$p}se_appointments` ADD COLUMN IF NOT EXISTS `gcal_sync_state` varchar(16) DEFAULT NULL";

    /* --- v8.7: WhatsApp webhook claim, lease, fencing and backoff ---------- *
     * The drainer previously SELECTed pending rows with no claim, so two
     * overlapping cron runs processed the same event twice.
     */
    $stmts[] = "ALTER TABLE `{$p}se_wa_webhook_events` ADD COLUMN IF NOT EXISTS `locked_at` datetime DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_wa_webhook_events` ADD COLUMN IF NOT EXISTS `locked_by` varchar(64) DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_wa_webhook_events` ADD COLUMN IF NOT EXISTS `next_attempt_at` datetime DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_wa_webhook_events` ADD COLUMN IF NOT EXISTS `fence` bigint(20) NOT NULL DEFAULT 0";
    $stmts[] = "ALTER TABLE `{$p}se_wa_webhook_events` ADD INDEX IF NOT EXISTS `claim` (`state`,`next_attempt_at`)";
    $stmts[] = "ALTER TABLE `{$p}se_wa_webhook_events` ADD UNIQUE INDEX IF NOT EXISTS `event_hash` (`event_hash`)";
    $stmts[] = "ALTER TABLE `{$p}se_wa_messages` ADD INDEX IF NOT EXISTS `brand_wamid` (`brand_id`,`wamid`)";

    /* --- v12: distinguish customer, Cloud API, and handset messages ------ *
     * Coexistence mirrors messages sent from the WhatsApp Business handset
     * through smb_message_echoes. Direction alone cannot tell an operator
     * whether an outbound row came from the CRM or the handset.
     */
    $stmts[] = "ALTER TABLE `{$p}se_wa_messages` ADD COLUMN IF NOT EXISTS `source` varchar(24) DEFAULT NULL";

    /* --- v13: Instagram Direct inbox (se_instagram) ----------------------- *
     * Same idempotent DDL the module's install.php runs, registered here so a
     * host that never toggles the module still gets the tables on admin_init.
     */
    if (!function_exists('se_ig_schema_statements') && is_file(__DIR__ . '/../se_instagram/helpers.php')) {
        require_once __DIR__ . '/../se_instagram/helpers.php';
    }
    if (function_exists('se_ig_schema_statements')) {
        foreach (se_ig_schema_statements($p) as $igSql) {
            $stmts[] = $igSql;
        }
    }

    /* --- v14: inbound media store (WhatsApp + Instagram attachments) ------ */
    if (!function_exists('se_media_schema_statements') && is_file(__DIR__ . '/se_media.php')) {
        require_once __DIR__ . '/se_media.php';
    }
    if (function_exists('se_media_schema_statements')) {
        foreach (se_media_schema_statements($p) as $mSql) {
            $stmts[] = $mSql;
        }
    }
    /* --- v15: outbound attachments (direction/outbound_id on se_media, media_id on queues) */
    if (function_exists('se_media_schema_statements_v15')) {
        foreach (se_media_schema_statements_v15($p) as $mSql) {
            $stmts[] = $mSql;
        }
    }

    /* --- v8.8: brand-scoping index coverage for tenant queries ------------- */
    $stmts[] = "ALTER TABLE `{$p}se_staff_brands` ADD INDEX IF NOT EXISTS `staff_id` (`staff_id`)";

    /* =====================================================================
     * Phase 13 (schema v9) — Meta Lead Ads queue durability. Additive only.
     * The leadgen drainer had no claim at all: two overlapping cron runs
     * processed the same notification twice, and a credential-gated event
     * parked as `held` was never selected again because the drainer only ever
     * looked for `pending`.
     * ===================================================================== */
    $stmts[] = "ALTER TABLE `{$p}se_meta_leadgen_events` ADD COLUMN IF NOT EXISTS `locked_at` datetime DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_meta_leadgen_events` ADD COLUMN IF NOT EXISTS `locked_by` varchar(64) DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_meta_leadgen_events` ADD COLUMN IF NOT EXISTS `next_attempt_at` datetime DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_meta_leadgen_events` ADD COLUMN IF NOT EXISTS `fence` bigint(20) NOT NULL DEFAULT 0";
    $stmts[] = "ALTER TABLE `{$p}se_meta_leadgen_events` ADD COLUMN IF NOT EXISTS `failure_class` varchar(24) DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_meta_leadgen_events` ADD COLUMN IF NOT EXISTS `brand_id` int(11) NOT NULL DEFAULT 0";
    $stmts[] = "ALTER TABLE `{$p}se_meta_leadgen_events` ADD INDEX IF NOT EXISTS `claim` (`state`,`next_attempt_at`)";
    $stmts[] = "ALTER TABLE `{$p}se_meta_leadgen_events` ADD INDEX IF NOT EXISTS `brand_id` (`brand_id`)";

    /* =====================================================================
     * Phase 13 (schema v10) — WhatsApp OUTBOUND queue. Additive only.
     * Sending stays disabled: the table exists so the composer can queue and
     * the drainer can hold, with no live transport anywhere in the code.
     * ===================================================================== */
    $stmts[] = "CREATE TABLE IF NOT EXISTS `{$p}se_wa_outbound` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `conversation_id` bigint(20) NOT NULL,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `kind` varchar(16) NOT NULL DEFAULT 'text',
        `body` mediumtext DEFAULT NULL,
        `template_name` varchar(128) DEFAULT NULL,
        `variables_json` text DEFAULT NULL,
        `idempotency_key` varchar(64) NOT NULL,
        `status` varchar(16) NOT NULL DEFAULT 'pending',
        `attempts` int(11) NOT NULL DEFAULT 0,
        `failure_class` varchar(24) DEFAULT NULL,
        `last_error` varchar(255) DEFAULT NULL,
        `wamid` varchar(128) DEFAULT NULL,
        `locked_at` datetime DEFAULT NULL,
        `locked_by` varchar(64) DEFAULT NULL,
        `next_attempt_at` datetime DEFAULT NULL,
        `fence` bigint(20) NOT NULL DEFAULT 0,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `sent_at` datetime DEFAULT NULL,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idempotency_key` (`idempotency_key`),
        KEY `brand_id` (`brand_id`),
        KEY `conversation_id` (`conversation_id`),
        KEY `claim` (`status`,`next_attempt_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /* --- v11: Google Data Manager asynchronous request outcomes ----------- *
     * Until these existed a submitted request had nowhere to record what
     * Google eventually said, so no conversion could legitimately reach
     * `confirmed`.
     */
    $stmts[] = "ALTER TABLE `{$p}se_gdm_requests` ADD COLUMN IF NOT EXISTS `succeeded` int(11) NOT NULL DEFAULT 0";
    $stmts[] = "ALTER TABLE `{$p}se_gdm_requests` ADD COLUMN IF NOT EXISTS `failed` int(11) NOT NULL DEFAULT 0";
    $stmts[] = "ALTER TABLE `{$p}se_gdm_requests` ADD COLUMN IF NOT EXISTS `diagnostics` text DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_gdm_requests` ADD COLUMN IF NOT EXISTS `polled_at` datetime DEFAULT NULL";
    $stmts[] = "ALTER TABLE `{$p}se_gdm_requests` ADD INDEX IF NOT EXISTS `status` (`status`)";

    return $stmts;
}

/**
 * Runs the migration statements through a caller-supplied executor.
 *
 * $exec is `function(string $sql): bool` and MUST return false on failure.
 * Execution stops at the first failure and the index is reported, so the
 * caller can record exactly how far the schema got.
 *
 * @return array{executed:int,total:int,failed_sql:?string,ok:bool}
 */
function se_core_run_migrations(callable $exec)
{
    $stmts    = se_core_migration_statements();
    $executed = 0;

    foreach ($stmts as $sql) {
        $result = $exec($sql);

        if ($result === false) {
            return [
                'executed'   => $executed,
                'total'      => count($stmts),
                'failed_sql' => $sql,
                'ok'         => false,
            ];
        }

        $executed++;
    }

    return ['executed' => $executed, 'total' => count($stmts), 'failed_sql' => null, 'ok' => true];
}

/**
 * Capability migration for the tenancy split (schema v8).
 *
 * `se_brands.view` used to mean three things at once, including "see every
 * brand". Splitting it must FAIL CLOSED: nobody is auto-granted the new
 * `se_tenancy.all_brands` capability, because doing so would faithfully
 * preserve the vulnerability. What we do preserve is the harmless half —
 * anyone who could open reports keeps being able to open reports, now scoped
 * to their own brands.
 *
 * Idempotent: re-running inserts nothing new.
 */
function se_core_migrate_capabilities()
{
    $CI = &get_instance();
    $p  = db_prefix();

    // 1. Per-staff permissions: se_brands.view -> also grant se_reports.view.
    $rows = $CI->db->query(
        'SELECT staff_id FROM `' . $p . "staff_permissions` WHERE feature = 'se_brands' AND capability = 'view'"
    )->result_array();

    foreach ($rows as $row) {
        $staffId = (int) $row['staff_id'];

        $exists = (int) $CI->db->query(
            'SELECT COUNT(*) AS c FROM `' . $p . "staff_permissions`"
            . " WHERE staff_id = {$staffId} AND feature = 'se_reports' AND capability = 'view'"
        )->row()->c;

        if ($exists === 0) {
            $CI->db->insert($p . 'staff_permissions', [
                'staff_id'   => $staffId,
                'feature'    => 'se_reports',
                'capability' => 'view',
            ]);
        }
    }

    // 2. Role permissions are a serialized feature => [capabilities] map.
    $roles = $CI->db->query('SELECT roleid, permissions FROM `' . $p . 'roles`')->result_array();

    foreach ($roles as $role) {
        if (empty($role['permissions'])) {
            continue;
        }

        $perms = @unserialize($role['permissions']);

        if (!is_array($perms) || !isset($perms['se_brands']) || !is_array($perms['se_brands'])) {
            continue;
        }

        if (!in_array('view', $perms['se_brands'], true)) {
            continue;
        }

        $existing = isset($perms['se_reports']) && is_array($perms['se_reports']) ? $perms['se_reports'] : [];

        if (in_array('view', $existing, true)) {
            continue; // already migrated
        }

        $existing[]          = 'view';
        $perms['se_reports'] = array_values(array_unique($existing));

        $CI->db->where('roleid', (int) $role['roleid'])
               ->update($p . 'roles', ['permissions' => serialize($perms)]);
    }
}

/**
 * Runtime entry: apply pending schema on admin_init.
 *
 * Three properties the previous version lacked:
 *
 *  1. EVERY statement result is checked. CodeIgniter's db->query() returns
 *     false on error when db_debug is off; the old loop ignored that and then
 *     stamped the new version anyway, so a half-applied schema was recorded as
 *     complete.
 *  2. The version option is written ONLY after every statement succeeded.
 *  3. Concurrent first-run admin requests are serialised with a MySQL advisory
 *     lock. Without it, two simultaneous logins after a deploy both ran the
 *     whole DDL list.
 *
 * DDL auto-commits in MariaDB, so a mid-list failure cannot be rolled back.
 * Rather than pretending otherwise, a failure is recorded in
 * `se_core_schema_error` (statement index + message) and the version option is
 * left at its previous value, so the next request retries from the top. Every
 * statement is individually idempotent, which makes that retry safe.
 */
function se_core_migrate()
{
    if ((int) get_option('se_core_schema_version') >= SE_CORE_SCHEMA_VERSION) {
        return true;
    }

    $CI = &get_instance();

    // Serialise concurrent first-run requests. GET_LOCK is connection-scoped
    // and released explicitly below (and automatically if the request dies).
    $lockName = 'se_core_migrate_' . md5(db_prefix() . SE_CORE_SCHEMA_VERSION);
    $lock     = $CI->db->query('SELECT GET_LOCK(' . $CI->db->escape($lockName) . ', 10) AS l')->row();

    if (!$lock || (int) $lock->l !== 1) {
        return false; // another request holds it; it will finish the work
    }

    try {
        // Re-read inside the lock: the holder may have just finished.
        if ((int) get_option('se_core_schema_version') >= SE_CORE_SCHEMA_VERSION) {
            return true;
        }

        $failure = null;

        $result = se_core_run_migrations(function ($sql) use ($CI, &$failure) {
            try {
                if ($CI->db->query($sql) === false) {
                    $failure = 'query returned false';

                    return false;
                }
            } catch (Exception $e) {
                $failure = 'exception during DDL';

                return false;
            }

            return true;
        });

        if (!$result['ok']) {
            // Statement index only — never the SQL text or a DB message, which
            // can carry schema/credential detail into an option row.
            update_option('se_core_schema_error',
                'v' . SE_CORE_SCHEMA_VERSION . ' failed at statement '
                . ($result['executed'] + 1) . '/' . $result['total'] . ': ' . $failure);

            return false;
        }

        // Idempotent DML, kept out of the DDL list.
        if (function_exists('se_pipeline_seed')) {
            se_pipeline_seed();
        }

        se_core_migrate_capabilities();

        update_option('se_core_schema_version', SE_CORE_SCHEMA_VERSION);
        update_option('se_core_schema_error', '');

        return true;
    } finally {
        $CI->db->query('SELECT RELEASE_LOCK(' . $CI->db->escape($lockName) . ')');
    }
}
