<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * se_journey — eyebrow-transplant patient journey (WhatsApp → CRM).
 *
 * WHAT THIS FILE OWNS
 * -------------------
 *   - the idempotent schema (se_journey_schema_statements), registered with
 *     se_core's migration runner so it applies on admin_init like everything
 *     else;
 *   - the capability vocabulary (default-deny for health data and photos);
 *   - the persisted state machine with an immutable transition log;
 *   - journey identity: one journey per (brand, WhatsApp user), one CRM lead
 *     per person, source/attribution recorded once and never fabricated;
 *   - the inbound WhatsApp listener: keyword handling (opt-out, human
 *     handoff, urgent), button routing, media routing, and the automation
 *     pause/resume rules.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It never decides medical eligibility, never computes a price, and never
 * sends anything itself — sending goes through messaging.php, which applies the
 * central policy (consent, opt-out, window, template approval, quiet hours,
 * frequency caps, sandbox) before touching the se_whatsapp outbound queue.
 *
 * Health answers and photographs are NOT in this file's tables in the clear:
 * intake.php and media.php encrypt them at rest; this file stores only states,
 * flags, references and bounded non-sensitive summaries.
 */

define('SE_JOURNEY_MODULE_NAME', 'se_journey');
define('SE_JOURNEY_FEATURE', 'se_journey');

/** The exact pre-filled wa.me message the Instagram handoff link carries. */
define('SE_JOURNEY_PREFILLED_MESSAGE', 'Merhaba, kaş ekimi hakkında fiyat ve değerlendirme bilgisi almak istiyorum.');

/** Public WhatsApp number of the clinic, canonical E.164 digits (no +). */
define('SE_JOURNEY_CLINIC_WA', '905471207070');

define('SE_JOURNEY_MAX_NOTE', 500);
define('SE_JOURNEY_MAX_INITIAL', 1024);
define('SE_JOURNEY_LEAD_SCAN_LIMIT', 2000);

/* ===========================================================================
 * Schema — additive, guarded, MariaDB 10.11 / PHP 8.1.
 * ======================================================================== */

function se_journey_schema_statements($p)
{
    $cs = 'utf8mb4';
    $s  = [];

    /* Existing se_whatsapp tables gain the columns the journey needs. */
    $s[] = "ALTER TABLE `{$p}se_wa_messages` ADD COLUMN IF NOT EXISTS `interactive_id` varchar(191) DEFAULT NULL";
    $s[] = "ALTER TABLE `{$p}se_wa_messages` ADD COLUMN IF NOT EXISTS `status_error` varchar(191) DEFAULT NULL";
    $s[] = "ALTER TABLE `{$p}se_wa_messages` ADD COLUMN IF NOT EXISTS `origin` varchar(48) DEFAULT NULL";   // staff | system | journey:<purpose> — the thread tags automatic messages
    $s[] = "ALTER TABLE `{$p}se_wa_outbound` ADD COLUMN IF NOT EXISTS `payload_json` text DEFAULT NULL";
    $s[] = "ALTER TABLE `{$p}se_wa_outbound` ADD COLUMN IF NOT EXISTS `origin` varchar(48) DEFAULT NULL";
    $s[] = "ALTER TABLE `{$p}se_wa_outbound` ADD INDEX IF NOT EXISTS `origin` (`origin`)";

    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_journeys` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `lead_id` int(11) NOT NULL DEFAULT 0,
        `client_id` int(11) NOT NULL DEFAULT 0,
        `patient_id` int(11) NOT NULL DEFAULT 0,
        `wa_conversation_id` bigint(20) NOT NULL DEFAULT 0,
        `wa_user_id` varchar(32) NOT NULL,
        `display_name` varchar(191) DEFAULT NULL,
        `language` varchar(8) NOT NULL DEFAULT 'tr',
        `state` varchar(40) NOT NULL DEFAULT 'new_whatsapp_enquiry',
        `previous_state` varchar(40) DEFAULT NULL,
        `state_changed_at` datetime DEFAULT NULL,
        `assigned_staff` int(11) NOT NULL DEFAULT 0,
        `next_action` varchar(191) DEFAULT NULL,
        `next_action_due_at` datetime DEFAULT NULL,
        `source` varchar(40) NOT NULL DEFAULT 'unknown',
        `source_detail` varchar(191) DEFAULT NULL,
        `source_confidence` varchar(16) DEFAULT NULL,
        `attribution_json` text DEFAULT NULL,
        `initial_message` text DEFAULT NULL,
        `first_touch_at` datetime DEFAULT NULL,
        `latest_touch_at` datetime DEFAULT NULL,
        `automation_state` varchar(24) NOT NULL DEFAULT 'active',
        `automation_reason` varchar(191) DEFAULT NULL,
        `automation_changed_by` int(11) NOT NULL DEFAULT 0,
        `automation_changed_at` datetime DEFAULT NULL,
        `opted_out_at` datetime DEFAULT NULL,
        `welcome_sent_at` datetime DEFAULT NULL,
        `intake_version` varchar(16) DEFAULT NULL,
        `intake_submitted_at` datetime DEFAULT NULL,
        `photos_required_json` varchar(191) DEFAULT NULL,
        `review_decision` varchar(32) DEFAULT NULL,
        `urgent` tinyint(1) NOT NULL DEFAULT 0,
        `urgent_at` datetime DEFAULT NULL,
        `reminder_count` int(11) NOT NULL DEFAULT 0,
        `last_reminder_at` datetime DEFAULT NULL,
        `last_outbound_at` datetime DEFAULT NULL,
        `last_send_block` varchar(191) DEFAULT NULL,
        `deposit_state` varchar(16) DEFAULT NULL,
        `payment_ref` varchar(64) DEFAULT NULL,
        `procedure_at` datetime DEFAULT NULL,
        `consultation_appointment_id` int(11) NOT NULL DEFAULT 0,
        `procedure_appointment_id` int(11) NOT NULL DEFAULT 0,
        `aftercare_plan_id` int(11) NOT NULL DEFAULT 0,
        `date_created` datetime NOT NULL,
        `last_updated` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `brand_user` (`brand_id`,`wa_user_id`),
        KEY `brand_state` (`brand_id`,`state`),
        KEY `lead_id` (`lead_id`),
        KEY `assigned_staff` (`assigned_staff`),
        KEY `next_action_due` (`next_action_due_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_journey_transitions` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `journey_id` int(11) NOT NULL,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `from_state` varchar(40) DEFAULT NULL,
        `to_state` varchar(40) NOT NULL,
        `trigger_key` varchar(64) NOT NULL,
        `actor_type` varchar(16) NOT NULL,
        `actor_id` varchar(64) DEFAULT NULL,
        `correlation_id` varchar(191) DEFAULT NULL,
        `note` varchar(500) DEFAULT NULL,
        `created_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `journey_id` (`journey_id`),
        KEY `brand_id` (`brand_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_journey_events` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `journey_id` int(11) NOT NULL,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `kind` varchar(48) NOT NULL,
        `actor_type` varchar(16) NOT NULL DEFAULT 'system',
        `actor_id` varchar(64) DEFAULT NULL,
        `ref_type` varchar(32) DEFAULT NULL,
        `ref_id` varchar(191) DEFAULT NULL,
        `summary` varchar(500) DEFAULT NULL,
        `meta_json` text DEFAULT NULL,
        `correlation_id` varchar(191) DEFAULT NULL,
        `created_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `journey_id` (`journey_id`),
        KEY `brand_kind` (`brand_id`,`kind`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_journey_tokens` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `journey_id` int(11) NOT NULL,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `purpose` varchar(24) NOT NULL,
        `token_hash` varchar(64) NOT NULL,
        `issued_by` int(11) NOT NULL DEFAULT 0,
        `issued_at` datetime NOT NULL,
        `expires_at` datetime NOT NULL,
        `revoked_at` datetime DEFAULT NULL,
        `revoke_reason` varchar(64) DEFAULT NULL,
        `first_used_at` datetime DEFAULT NULL,
        `last_used_at` datetime DEFAULT NULL,
        `use_count` int(11) NOT NULL DEFAULT 0,
        `rotated_from` bigint(20) NOT NULL DEFAULT 0,
        `last_ip_hash` varchar(64) DEFAULT NULL,
        `last_ua_hash` varchar(64) DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `token_hash` (`token_hash`),
        KEY `journey_purpose` (`journey_id`,`purpose`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_journey_intakes` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `journey_id` int(11) NOT NULL,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `questionnaire_version` varchar(16) NOT NULL,
        `status` varchar(16) NOT NULL DEFAULT 'started',
        `answers_enc` mediumtext DEFAULT NULL,
        `answers_hash` varchar(64) DEFAULT NULL,
        `key_version` varchar(16) DEFAULT NULL,
        `sections_done_json` varchar(255) DEFAULT NULL,
        `missing_json` text DEFAULT NULL,
        `flags_json` text DEFAULT NULL,
        `consent_snapshot_json` text DEFAULT NULL,
        `started_at` datetime DEFAULT NULL,
        `last_saved_at` datetime DEFAULT NULL,
        `submitted_at` datetime DEFAULT NULL,
        `submitted_ip_hash` varchar(64) DEFAULT NULL,
        `submitted_ua_hash` varchar(64) DEFAULT NULL,
        `date_created` datetime NOT NULL,
        `last_updated` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `journey_version` (`journey_id`,`questionnaire_version`),
        KEY `brand_id` (`brand_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_journey_media` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `journey_id` int(11) NOT NULL,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `intake_id` int(11) NOT NULL DEFAULT 0,
        `kind` varchar(16) NOT NULL DEFAULT 'unclassified',
        `phase` varchar(16) NOT NULL DEFAULT 'evaluation',
        `source` varchar(16) NOT NULL,
        `provider_media_id` varchar(191) DEFAULT NULL,
        `inbox_media_id` bigint(20) DEFAULT NULL,
        `wamid` varchar(128) DEFAULT NULL,
        `mime` varchar(64) DEFAULT NULL,
        `width` int(11) NOT NULL DEFAULT 0,
        `height` int(11) NOT NULL DEFAULT 0,
        `bytes` int(11) NOT NULL DEFAULT 0,
        `sha256` varchar(64) DEFAULT NULL,
        `storage_ref` varchar(191) DEFAULT NULL,
        `storage` varchar(8) NOT NULL DEFAULT 'local',
        `key_version` varchar(16) DEFAULT NULL,
        `metadata_stripped` tinyint(1) NOT NULL DEFAULT 0,
        `state` varchar(24) NOT NULL DEFAULT 'received',
        `last_error` varchar(191) DEFAULT NULL,
        `retake_reason` varchar(64) DEFAULT NULL,
        `evaluation_use_permitted` tinyint(1) NOT NULL DEFAULT 0,
        `publication_permitted` tinyint(1) NOT NULL DEFAULT 0,
        `reviewed_by` int(11) NOT NULL DEFAULT 0,
        `reviewed_at` datetime DEFAULT NULL,
        `uploaded_at` datetime NOT NULL,
        `deleted_at` datetime DEFAULT NULL,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `wamid` (`wamid`),
        KEY `journey_id` (`journey_id`),
        KEY `brand_id` (`brand_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_journey_reviews` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `journey_id` int(11) NOT NULL,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `reviewer_id` int(11) NOT NULL DEFAULT 0,
        `decision` varchar(32) DEFAULT NULL,
        `internal_notes` mediumtext DEFAULT NULL,
        `checklist_json` text DEFAULT NULL,
        `due_at` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL,
        `updated_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `journey_id` (`journey_id`),
        KEY `brand_id` (`brand_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_journey_quotes` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `journey_id` int(11) NOT NULL,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `version` int(11) NOT NULL DEFAULT 1,
        `status` varchar(24) NOT NULL DEFAULT 'draft',
        `currency` varchar(3) NOT NULL DEFAULT 'TRY',
        `amount_min` decimal(12,2) DEFAULT NULL,
        `amount_max` decimal(12,2) DEFAULT NULL,
        `show_amount` tinyint(1) NOT NULL DEFAULT 0,
        `valid_until` date DEFAULT NULL,
        `included_json` text DEFAULT NULL,
        `excluded_json` text DEFAULT NULL,
        `deposit_terms` varchar(500) DEFAULT NULL,
        `travel_notes` varchar(1000) DEFAULT NULL,
        `recommendation` varchar(32) DEFAULT NULL,
        `internal_notes` mediumtext DEFAULT NULL,
        `internal_margin` varchar(191) DEFAULT NULL,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `approved_by` int(11) NOT NULL DEFAULT 0,
        `approved_at` datetime DEFAULT NULL,
        `sent_at` datetime DEFAULT NULL,
        `sent_by` int(11) NOT NULL DEFAULT 0,
        `snapshot_json` mediumtext DEFAULT NULL,
        `snapshot_hash` varchar(64) DEFAULT NULL,
        `wa_outbound_id` bigint(20) NOT NULL DEFAULT 0,
        `patient_response` varchar(24) DEFAULT NULL,
        `patient_response_at` datetime DEFAULT NULL,
        `patient_response_via` varchar(16) DEFAULT NULL,
        `date_created` datetime NOT NULL,
        `last_updated` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `journey_id` (`journey_id`),
        KEY `brand_status` (`brand_id`,`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_journey_aftercare_plans` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `journey_id` int(11) NOT NULL,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `protocol_key` varchar(64) NOT NULL,
        `protocol_version` varchar(16) NOT NULL,
        `anchor_at` datetime NOT NULL,
        `state` varchar(16) NOT NULL DEFAULT 'active',
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `journey_id` (`journey_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_journey_aftercare_events` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `plan_id` int(11) NOT NULL,
        `journey_id` int(11) NOT NULL,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `step_key` varchar(32) NOT NULL,
        `label` varchar(64) DEFAULT NULL,
        `kind` varchar(24) NOT NULL DEFAULT 'checkin',
        `due_at` datetime NOT NULL,
        `template_ref` varchar(128) DEFAULT NULL,
        `state` varchar(16) NOT NULL DEFAULT 'scheduled',
        `reply_enc` text DEFAULT NULL,
        `reply_key_version` varchar(16) DEFAULT NULL,
        `escalated_at` datetime DEFAULT NULL,
        `sent_at` datetime DEFAULT NULL,
        `answered_at` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `plan_id` (`plan_id`),
        KEY `journey_id` (`journey_id`),
        KEY `due` (`state`,`due_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_journey_templates` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `logical_name` varchar(64) NOT NULL,
        `language` varchar(8) NOT NULL DEFAULT 'tr',
        `category_requested` varchar(24) NOT NULL DEFAULT 'UTILITY',
        `category_meta` varchar(24) DEFAULT NULL,
        `meta_template_id` varchar(64) DEFAULT NULL,
        `meta_name` varchar(128) DEFAULT NULL,
        `content_version` int(11) NOT NULL DEFAULT 1,
        `body` text DEFAULT NULL,
        `placeholders_json` text DEFAULT NULL,
        `buttons_json` text DEFAULT NULL,
        `approval_status` varchar(24) NOT NULL DEFAULT 'not_submitted',
        `rejection_reason` varchar(500) DEFAULT NULL,
        `fallback` varchar(24) NOT NULL DEFAULT 'staff_task',
        `submitted_at` datetime DEFAULT NULL,
        `last_sync_at` datetime DEFAULT NULL,
        `date_created` datetime NOT NULL,
        `last_updated` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `brand_logical_lang` (`brand_id`,`logical_name`,`language`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_journey_tasks` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `journey_id` int(11) NOT NULL,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `kind` varchar(32) NOT NULL,
        `title` varchar(191) NOT NULL,
        `priority` varchar(8) NOT NULL DEFAULT 'normal',
        `state` varchar(12) NOT NULL DEFAULT 'open',
        `assigned_staff` int(11) NOT NULL DEFAULT 0,
        `due_at` datetime DEFAULT NULL,
        `dedup_key` varchar(191) DEFAULT NULL,
        `created_at` datetime NOT NULL,
        `done_at` datetime DEFAULT NULL,
        `done_by` int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `dedup_key` (`dedup_key`),
        KEY `journey_id` (`journey_id`),
        KEY `brand_state` (`brand_id`,`state`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_journey_audit` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `brand_id` int(11) NOT NULL DEFAULT 0,
        `journey_id` int(11) NOT NULL DEFAULT 0,
        `staff_id` int(11) NOT NULL DEFAULT 0,
        `action` varchar(48) NOT NULL,
        `ref_type` varchar(32) DEFAULT NULL,
        `ref_id` varchar(64) DEFAULT NULL,
        `detail` varchar(255) DEFAULT NULL,
        `ip_hash` varchar(64) DEFAULT NULL,
        `created_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `journey_id` (`journey_id`),
        KEY `brand_action` (`brand_id`,`action`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    $s[] = "CREATE TABLE IF NOT EXISTS `{$p}se_journey_throttle` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `bucket` varchar(96) NOT NULL,
        `window_start` datetime NOT NULL,
        `hits` int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `bucket` (`bucket`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE=utf8mb4_unicode_ci";

    /* v18 (additive, idempotent): the patient's answer to a sent quote, and
     * quick-reply buttons on a template definition. CREATE TABLE above carries
     * them for fresh installs; these bring an existing v17 schema level. */
    $s[] = "ALTER TABLE `{$p}se_journey_quotes` ADD COLUMN IF NOT EXISTS `patient_response` varchar(24) DEFAULT NULL";
    $s[] = "ALTER TABLE `{$p}se_journey_quotes` ADD COLUMN IF NOT EXISTS `patient_response_at` datetime DEFAULT NULL";
    $s[] = "ALTER TABLE `{$p}se_journey_quotes` ADD COLUMN IF NOT EXISTS `patient_response_via` varchar(16) DEFAULT NULL";
    $s[] = "ALTER TABLE `{$p}se_journey_templates` ADD COLUMN IF NOT EXISTS `buttons_json` text DEFAULT NULL";

    return $s;
}

/* ===========================================================================
 * Capabilities — default deny. Only an admin or an explicit grant passes.
 * ======================================================================== */

function se_journey_capabilities()
{
    return [
        'view'                => 'se_journey_cap_view',
        'view_health'         => 'se_journey_cap_view_health',
        'view_photos'         => 'se_journey_cap_view_photos',
        'edit_review'         => 'se_journey_cap_edit_review',
        'approve_quote'       => 'se_journey_cap_approve_quote',
        'manage_consultation' => 'se_journey_cap_manage_consultation',
        'manage_aftercare'    => 'se_journey_cap_manage_aftercare',
        'export_health'       => 'se_journey_cap_export_health',
        'manage_templates'    => 'se_journey_cap_manage_templates',
        'manage_consent'      => 'se_journey_cap_manage_consent',
    ];
}

/** Capability check. Admins pass; everybody else needs the explicit grant. */
function se_journey_can($cap, $staff_id = '')
{
    if (function_exists('is_admin') && is_admin($staff_id)) {
        return true;
    }

    return function_exists('staff_can') && staff_can($cap, SE_JOURNEY_FEATURE, $staff_id);
}

/** Integration administration = the existing brand-configuration capability. */
function se_journey_is_integration_admin()
{
    return function_exists('se_staff_can_configure_brands') && se_staff_can_configure_brands();
}

/* ===========================================================================
 * Feature flags and configuration (never a secret; presence only).
 * ======================================================================== */

function se_journey_enabled($brand_id)
{
    return (int) get_option('se_journey_enabled_' . (int) $brand_id) === 1;
}

/** Sandbox: only allow-listed recipients receive real messages; others are recorded. */
function se_journey_sandbox($brand_id)
{
    $v = get_option('se_journey_sandbox_' . (int) $brand_id);

    return $v === '' ? true : (int) $v === 1;   // default ON until the owner turns it off
}

function se_journey_test_recipients($brand_id)
{
    $raw = (string) get_option('se_journey_test_recipients_' . (int) $brand_id);
    $out = [];
    // Numbers are separated by commas/semicolons; spaces inside a number are
    // allowed ("0 531 000 00 09"), so split on separators only.
    foreach (preg_split('/[,;]+/', $raw) as $n) {
        // Same normalisation as inbound wa_ids ("0 5xx" → "90 5xx", "+90…" → "90…")
        // so a number typed in national format still matches the allow-list.
        $n = se_journey_normalize_wa_id($n);
        if ($n !== '') { $out[] = $n; }
    }

    return array_values(array_unique($out));
}

/** Automation may start on organic (non-prefilled, non-ad) enquiries? Default no. */
function se_journey_auto_start_organic($brand_id)
{
    return (int) get_option('se_journey_auto_start_organic_' . (int) $brand_id) === 1;
}

/** Start the journey for every website lead the moment it arrives (default: staff press Start). */
function se_journey_auto_start_website($brand_id)
{
    return (int) get_option('se_journey_auto_start_website_' . (int) $brand_id) === 1;
}

function se_journey_config($key, $default = null)
{
    $v = get_option('se_journey_' . $key);

    return ($v === '' || $v === null) ? $default : $v;
}

/* ===========================================================================
 * State machine
 * ======================================================================== */

function se_journey_states()
{
    return [
        'new_whatsapp_enquiry', 'welcome_sent', 'privacy_notice_sent', 'consent_pending',
        'consent_declined', 'intake_link_sent', 'intake_started', 'intake_incomplete',
        'intake_submitted', 'photos_requested', 'photos_incomplete', 'photo_retake_requested',
        'ready_for_review', 'under_review', 'more_information_required',
        'consultation_recommended', 'quote_pending_staff_approval', 'quote_sent',
        'quote_accepted', 'quote_revision_requested', 'quote_expired',
        'consultation_booked', 'consultation_completed', 'procedure_booked', 'preop_pending',
        'procedure_completed', 'aftercare_active', 'followup_due', 'completed',
        'not_suitable', 'closed_lost', 'opted_out',
    ];
}

/** States reachable from ANY state (patient opt-out, staff close). */
function se_journey_global_targets()
{
    return ['opted_out', 'closed_lost'];
}

/** Explicit allowed transitions. Anything not listed is refused (unless forced by staff, audited). */
function se_journey_allowed_transitions()
{
    return [
        'new_whatsapp_enquiry'      => ['welcome_sent', 'not_suitable'],
        'welcome_sent'              => ['privacy_notice_sent', 'welcome_sent', 'not_suitable'],
        'privacy_notice_sent'       => ['consent_pending', 'intake_link_sent'],
        'consent_pending'           => ['consent_declined', 'intake_started', 'intake_link_sent', 'intake_incomplete'],
        'consent_declined'          => ['consent_pending', 'intake_link_sent', 'intake_started'],
        'intake_link_sent'          => ['consent_pending', 'intake_started', 'intake_incomplete'],
        'intake_started'            => ['intake_incomplete', 'intake_submitted', 'intake_link_sent', 'consent_declined'],
        'intake_incomplete'         => ['intake_started', 'intake_submitted', 'intake_link_sent'],
        // under_review from every photo state: staff may review (and quote) before the last photo arrives.
        'intake_submitted'          => ['photos_requested', 'ready_for_review', 'under_review'],
        'photos_requested'          => ['photos_incomplete', 'ready_for_review', 'photo_retake_requested', 'under_review'],
        'photos_incomplete'         => ['photos_requested', 'ready_for_review', 'under_review'],
        'photo_retake_requested'    => ['ready_for_review', 'photos_incomplete', 'photo_retake_requested', 'under_review'],
        'ready_for_review'          => ['under_review', 'photo_retake_requested', 'more_information_required'],
        'under_review'              => ['more_information_required', 'consultation_recommended', 'quote_pending_staff_approval',
                                        'not_suitable', 'photo_retake_requested', 'ready_for_review'],
        'more_information_required' => ['ready_for_review', 'under_review', 'intake_started', 'photo_retake_requested'],
        'consultation_recommended'  => ['consultation_booked', 'quote_pending_staff_approval'],
        'quote_pending_staff_approval' => ['quote_sent', 'under_review', 'consultation_recommended'],
        'quote_sent'                => ['consultation_booked', 'consultation_recommended', 'procedure_booked', 'quote_pending_staff_approval',
                                        'quote_accepted', 'quote_revision_requested', 'quote_expired'],
        // Past valid_until with no answer (CRM-M048): staff issue a new version, recommend a consultation or close.
        'quote_expired'             => ['quote_pending_staff_approval', 'under_review', 'consultation_recommended', 'quote_accepted', 'consultation_booked'],
        // The patient answered the quote (WhatsApp button/keyword or the quote page).
        'quote_accepted'            => ['consultation_booked', 'procedure_booked', 'consultation_recommended', 'quote_pending_staff_approval',
                                        'quote_revision_requested'],
        'quote_revision_requested'  => ['quote_pending_staff_approval', 'quote_sent', 'under_review', 'consultation_recommended', 'consultation_booked'],
        'consultation_booked'       => ['consultation_completed', 'consultation_recommended', 'consultation_booked'],
        'consultation_completed'    => ['procedure_booked', 'quote_pending_staff_approval', 'not_suitable', 'consultation_recommended'],
        'procedure_booked'          => ['preop_pending', 'procedure_booked', 'consultation_completed'],
        'preop_pending'             => ['procedure_completed', 'procedure_booked'],
        'procedure_completed'       => ['aftercare_active'],
        'aftercare_active'          => ['followup_due', 'completed'],
        'followup_due'              => ['aftercare_active', 'completed'],
        'completed'                 => ['aftercare_active'],
        'not_suitable'              => ['ready_for_review', 'under_review'],
        'closed_lost'               => ['new_whatsapp_enquiry', 'ready_for_review', 'consultation_recommended'],
        'opted_out'                 => [],   // only se_journey_reactivate() with new evidence
    ];
}

function se_journey_transition_allowed($from, $to)
{
    if (!in_array($to, se_journey_states(), true)) {
        return false;
    }
    if ($from === null || $from === '') {
        return $to === 'new_whatsapp_enquiry';
    }
    if ($from === 'opted_out') {
        return false;
    }
    if (in_array($to, se_journey_global_targets(), true)) {
        return true;
    }
    $map = se_journey_allowed_transitions();

    return isset($map[$from]) && in_array($to, $map[$from], true);
}

/**
 * Move a journey to a new state, appending an immutable transition row.
 *
 * @param object|int $journey  row or id
 * @return array{ok:bool,reason:string,from:?string,to:string}
 */
function se_journey_transition($journey, $to, $trigger, $actor_type = 'system', $actor_id = null, $correlation_id = null, $note = null, $force = false)
{
    $CI = &get_instance();
    $j  = is_object($journey) ? $journey : se_journey_get_raw((int) $journey);
    if (!$j) {
        return ['ok' => false, 'reason' => 'not_found', 'from' => null, 'to' => $to];
    }

    $from = (string) $j->state;
    if (!$force && !se_journey_transition_allowed($from, $to)) {
        return ['ok' => false, 'reason' => 'transition_not_allowed', 'from' => $from, 'to' => $to];
    }
    if ($force && !in_array($to, se_journey_states(), true)) {
        return ['ok' => false, 'reason' => 'unknown_state', 'from' => $from, 'to' => $to];
    }
    if (!in_array($actor_type, ['patient', 'staff', 'system', 'provider'], true)) {
        $actor_type = 'system';
    }

    $now = date('Y-m-d H:i:s');
    $upd = ['state' => $to, 'previous_state' => $from, 'state_changed_at' => $now, 'last_updated' => $now];
    if ($to === 'opted_out') {
        $upd['opted_out_at'] = $now;
        $upd['automation_state'] = 'stopped';
        $upd['automation_reason'] = 'opted_out';
        $upd['automation_changed_at'] = $now;
    }

    $CI->db->where('id', (int) $j->id)->where('brand_id', (int) $j->brand_id)->update(db_prefix() . 'se_journeys', $upd);

    $CI->db->insert(db_prefix() . 'se_journey_transitions', [
        'journey_id'     => (int) $j->id,
        'brand_id'       => (int) $j->brand_id,
        'from_state'     => $from !== '' ? $from : null,
        'to_state'       => $to,
        'trigger_key'    => mb_substr((string) $trigger, 0, 64),
        'actor_type'     => $actor_type,
        'actor_id'       => $actor_id !== null ? mb_substr((string) $actor_id, 0, 64) : null,
        'correlation_id' => $correlation_id !== null ? mb_substr((string) $correlation_id, 0, 191) : null,
        'note'           => $note !== null ? mb_substr((string) $note, 0, SE_JOURNEY_MAX_NOTE) : null,
        'created_at'     => $now,
    ]);

    foreach (['state' => $to, 'previous_state' => $from, 'state_changed_at' => $now] as $k => $v) {
        $j->$k = $v;
    }

    // The CRM lead follows the journey (non-health fields, pipeline stage, timeline line).
    if (function_exists('se_journey_sync_lead')) {
        try {
            se_journey_lead_log_transition($j, $to);
            se_journey_sync_lead($j, 'transition:' . $to);
        } catch (Throwable $e) {
            se_journey_audit((int) $j->brand_id, (int) $j->id, 'lead_sync_failed', null, null, mb_substr(basename($e->getFile()) . ':' . $e->getLine(), 0, 191));
        }
    }

    /* Notify the phone in someone's pocket. Only states a HUMAN must act on
     * are pushed (se_push_notify_journey filters); a journey moves through
     * many states by itself, and buzzing on each one teaches people to swipe
     * notifications away — after which the ones that matter are gone too. */
    if (function_exists('se_push_notify_journey')) {
        se_push_notify_journey((int) $j->brand_id, (int) $j->id, $to,
                               isset($j->assigned_staff) ? (int) $j->assigned_staff : 0);
    }

    return ['ok' => true, 'reason' => '', 'from' => $from, 'to' => $to];
}

/* ===========================================================================
 * Events, tasks, audit — bounded, never health content.
 * ======================================================================== */

function se_journey_event($journey, $kind, $summary = '', array $meta = [], $actor_type = 'system', $actor_id = null, $ref_type = null, $ref_id = null, $correlation_id = null)
{
    $CI = &get_instance();
    $j  = is_object($journey) ? $journey : se_journey_get_raw((int) $journey);
    if (!$j) {
        return 0;
    }
    $CI->db->insert(db_prefix() . 'se_journey_events', [
        'journey_id'     => (int) $j->id,
        'brand_id'       => (int) $j->brand_id,
        'kind'           => mb_substr((string) $kind, 0, 48),
        'actor_type'     => in_array($actor_type, ['patient', 'staff', 'system', 'provider'], true) ? $actor_type : 'system',
        'actor_id'       => $actor_id !== null ? mb_substr((string) $actor_id, 0, 64) : null,
        'ref_type'       => $ref_type !== null ? mb_substr((string) $ref_type, 0, 32) : null,
        'ref_id'         => $ref_id !== null ? mb_substr((string) $ref_id, 0, 191) : null,
        'summary'        => mb_substr((string) $summary, 0, SE_JOURNEY_MAX_NOTE),
        'meta_json'      => $meta ? json_encode($meta) : null,
        'correlation_id' => $correlation_id !== null ? mb_substr((string) $correlation_id, 0, 191) : null,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);

    return (int) $CI->db->insert_id();
}

/** Open a staff attention item, deduplicated on (journey, kind, dedup suffix). */
function se_journey_task($journey, $kind, $title, $priority = 'normal', $due_at = null, $dedup_suffix = '')
{
    $CI = &get_instance();
    $j  = is_object($journey) ? $journey : se_journey_get_raw((int) $journey);
    if (!$j) {
        return 0;
    }
    $dedup = 'j' . (int) $j->id . ':' . $kind . ($dedup_suffix !== '' ? ':' . $dedup_suffix : '');

    $CI->db->where('dedup_key', $dedup);
    if ($CI->db->count_all_results(db_prefix() . 'se_journey_tasks') > 0) {
        return 0;
    }
    try {
        $CI->db->insert(db_prefix() . 'se_journey_tasks', [
            'journey_id'     => (int) $j->id,
            'brand_id'       => (int) $j->brand_id,
            'kind'           => mb_substr((string) $kind, 0, 32),
            'title'          => mb_substr((string) $title, 0, 191),
            'priority'       => $priority === 'urgent' ? 'urgent' : 'normal',
            'state'          => 'open',
            'assigned_staff' => (int) $j->assigned_staff,
            'due_at'         => $due_at,
            'dedup_key'      => mb_substr($dedup, 0, 191),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $e) {
        return 0;
    }

    return (int) $CI->db->insert_id();
}

function se_journey_task_done($task_id, $staff_id)
{
    $CI = &get_instance();
    $predicate = function_exists('se_brand_predicate') ? se_brand_predicate() : '';
    $CI->db->where('id', (int) $task_id)->where('state', 'open');
    if ($predicate !== '') {
        $CI->db->where($predicate, null, false);
    }
    $CI->db->update(db_prefix() . 'se_journey_tasks', [
        'state' => 'done', 'done_at' => date('Y-m-d H:i:s'), 'done_by' => (int) $staff_id,
    ]);

    return (int) $CI->db->affected_rows() > 0;
}

/** Privileged access / export / approval audit. Detail is never health content. */
function se_journey_audit($brand_id, $journey_id, $action, $ref_type = null, $ref_id = null, $detail = null)
{
    $CI = &get_instance();
    $staff = function_exists('get_staff_user_id') ? (int) get_staff_user_id() : 0;
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

    $CI->db->insert(db_prefix() . 'se_journey_audit', [
        'brand_id'   => (int) $brand_id,
        'journey_id' => (int) $journey_id,
        'staff_id'   => $staff,
        'action'     => mb_substr((string) $action, 0, 48),
        'ref_type'   => $ref_type !== null ? mb_substr((string) $ref_type, 0, 32) : null,
        'ref_id'     => $ref_id !== null ? mb_substr((string) $ref_id, 0, 64) : null,
        'detail'     => $detail !== null ? mb_substr((string) $detail, 0, 255) : null,
        'ip_hash'    => $ip !== '' ? hash('sha256', $ip) : null,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    return (int) $CI->db->insert_id();
}

/* ===========================================================================
 * Identity, normalisation, attribution
 * ======================================================================== */

/** WhatsApp ids are E.164 digits without '+'. Anything else is reduced to digits. */
function se_journey_normalize_wa_id($raw)
{
    $d = preg_replace('/\D+/', '', (string) $raw);
    // Turkish national format 0 5xx -> 90 5xx.
    if (strlen($d) === 11 && $d[0] === '0') {
        $d = '9' . $d;   // 05471207070 -> 905471207070
    }

    return $d;
}

function se_journey_e164($wa_user_id)
{
    $d = se_journey_normalize_wa_id($wa_user_id);

    return $d === '' ? '' : '+' . $d;
}

/**
 * Text normalisation for keyword and pre-filled-message matching:
 * lower-case (UTF-8), Turkish letters folded, punctuation and emoji removed,
 * whitespace collapsed. Never used for anything but comparison.
 */
function se_journey_normalize_text($raw)
{
    $v = (string) $raw;
    $v = function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
    $v = strtr($v, ['ı' => 'i', 'i̇' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
    $v = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $v);
    $v = preg_replace('/\s+/u', ' ', $v);

    return trim((string) $v);
}

/** Similarity 0..1 between two normalised strings (Levenshtein on bytes, bounded). */
function se_journey_similarity($a, $b)
{
    $a = (string) $a; $b = (string) $b;
    if ($a === $b) {
        return 1.0;
    }
    $max = max(strlen($a), strlen($b));
    if ($max === 0) {
        return 1.0;
    }
    if ($max > 255) {
        // levenshtein() caps at 255 bytes; fall back to similar_text ratio.
        similar_text($a, $b, $pct);

        return $pct / 100;
    }

    return 1 - (levenshtein($a, $b) / $max);
}

/**
 * Source detection for a FIRST inbound message.
 *
 * Referral metadata from Meta wins (it is the only proof of an ad click);
 * the text match is a signal with an explicit confidence, never proof.
 *
 * @return array{source:string,detail:string,confidence:string,attribution:array}
 */
function se_journey_detect_source($body, $referral)
{
    $attribution = [];
    if (is_array($referral) && $referral) {
        foreach (['source_type', 'source_id', 'source_url', 'headline', 'body', 'media_type', 'ctwa_clid'] as $k) {
            if (isset($referral[$k]) && is_scalar($referral[$k])) {
                $attribution[$k] = mb_substr((string) $referral[$k], 0, $k === 'body' ? 500 : 255);
            }
        }
        if (isset($referral['welcome_message']['text'])) {
            $attribution['welcome_message'] = mb_substr((string) $referral['welcome_message']['text'], 0, 500);
        }
        $type = (string) ($referral['source_type'] ?? '');

        return [
            'source'      => 'meta_click_to_whatsapp_ad',
            'detail'      => $type !== '' ? 'referral:' . $type : 'referral',
            'confidence'  => 'provider',
            'attribution' => $attribution,
        ];
    }

    $norm = se_journey_normalize_text($body);
    $ref  = se_journey_normalize_text(SE_JOURNEY_PREFILLED_MESSAGE);
    if ($norm !== '') {
        if ($norm === $ref) {
            return ['source' => 'instagram_prefilled_link', 'detail' => 'prefilled:exact', 'confidence' => 'exact', 'attribution' => []];
        }
        $sim = se_journey_similarity($norm, $ref);
        if ($sim >= 0.85) {
            return ['source' => 'instagram_prefilled_link', 'detail' => 'prefilled:close:' . round($sim, 2), 'confidence' => 'close', 'attribution' => []];
        }
        // Weak signal: mentions the two nouns the handoff message is built on.
        if (strpos($norm, 'kas ekimi') !== false && (strpos($norm, 'fiyat') !== false || strpos($norm, 'ucret') !== false || strpos($norm, 'degerlendirme') !== false)) {
            return ['source' => 'instagram_manual_handoff', 'detail' => 'keywords', 'confidence' => 'weak', 'attribution' => []];
        }
    }

    return ['source' => 'organic_whatsapp', 'detail' => $norm === '' ? 'non_text' : 'other_text', 'confidence' => 'none', 'attribution' => []];
}

/* ===========================================================================
 * Keywords
 * ======================================================================== */

function se_journey_optout_keywords()
{
    $extra = se_journey_config('optout_keywords', '');

    return array_values(array_unique(array_merge(
        ['iptal', 'dur', 'stop', 'iptal et', 'mesaj istemiyorum', 'abonelikten cik', 'cikar'],
        array_filter(array_map('se_journey_normalize_text', preg_split('/[,;\n]+/', (string) $extra)))
    )));
}

function se_journey_handoff_keywords()
{
    $extra = se_journey_config('handoff_keywords', '');

    return array_values(array_unique(array_merge(
        ['danisman', 'temsilci', 'insan', 'ara', 'danismana baglan', 'yetkili', 'gorusmek istiyorum'],
        array_filter(array_map('se_journey_normalize_text', preg_split('/[,;\n]+/', (string) $extra)))
    )));
}

function se_journey_start_keywords()
{
    return ['degerlendirmeye basla', 'degerlendirme baslat', 'degerlendirme basla', 'basla', 'baslat', 'devam', 'evet', 'start'];
}

/**
 * Answers to a sent quote, typed instead of tapped (a template quick-reply
 * arrives with the button TEXT as its payload when Meta gets no payload; the
 * labels are therefore keywords too). Normalised spellings.
 */
function se_journey_quote_accept_keywords()
{
    return ['teklifi kabul et', 'teklifi kabul ediyorum', 'kabul', 'kabul ediyorum', 'kabul ediyoruz', 'onayliyorum', 'onay', 'onaylandi',
            'teklifi onayliyorum', 'randevu almak istiyorum', 'accept', 'i accept', 'approve'];
}

function se_journey_quote_revise_keywords()
{
    return ['fiyat revizyonu', 'fiyat revize', 'revizyon', 'revize', 'fiyat revizyonu istiyorum', 'fiyati revize edin', 'indirim', 'indirim var mi',
            'daha uygun fiyat', 'fiyat yuksek', 'pahali', 'price revision', 'revise price', 'discount'];
}

/** Urgent symptom keywords — a trigger for ESCALATION, never a diagnosis. */
function se_journey_urgent_keywords()
{
    $extra = se_journey_config('urgent_keywords', '');

    return array_values(array_unique(array_merge(
        ['siddetli agri', 'dayanilmaz agri', 'kanama', 'kanamasi durmuyor', 'nefes', 'nefes darligi',
         'gozum kapandi', 'gorusum', 'sisti', 'siskinlik', 'ates', 'atesim', 'iltihap', 'irin', 'alerji',
         'kurdesen', 'bayildim', 'acil', '112'],
        array_filter(array_map('se_journey_normalize_text', preg_split('/[,;\n]+/', (string) $extra)))
    )));
}

/** Exact (whole-message) match after normalisation, or the message IS the keyword plus filler. */
function se_journey_matches_keyword($body, array $keywords)
{
    $n = se_journey_normalize_text($body);
    if ($n === '') {
        return false;
    }
    foreach ($keywords as $k) {
        if ($n === $k) {
            return true;
        }
    }
    // Short messages ("iptal lütfen", "dur artık"): keyword + at most two filler words.
    if (str_word_count($n) <= 3) {
        foreach ($keywords as $k) {
            if (preg_match('/(^|\s)' . preg_quote($k, '/') . '(\s|$)/u', $n)) {
                return true;
            }
        }
    }

    return false;
}

function se_journey_contains_keyword($body, array $keywords)
{
    $n = se_journey_normalize_text($body);
    if ($n === '') {
        return false;
    }
    foreach ($keywords as $k) {
        if ($k !== '' && strpos(' ' . $n . ' ', ' ' . $k . ' ') !== false) {
            return true;
        }
    }

    return false;
}

/* ===========================================================================
 * Reads
 * ======================================================================== */

/** Unscoped read for internal (system) callers. Staff reads go through se_journey_get(). */
function se_journey_get_raw($id)
{
    $CI = &get_instance();
    $CI->db->where('id', (int) $id);

    return $CI->db->get(db_prefix() . 'se_journeys')->row();
}

/** Brand-scoped read for the acting staff member. Null when out of scope. */
function se_journey_get($id)
{
    $CI = &get_instance();
    $CI->db->where('id', (int) $id);
    if (function_exists('se_apply_scope_in')) {
        se_apply_scope_in('brand_id');
    }

    return $CI->db->get(db_prefix() . 'se_journeys')->row();
}

function se_journey_find_by_wa($brand_id, $wa_user_id)
{
    $CI = &get_instance();
    $CI->db->where('brand_id', (int) $brand_id)->where('wa_user_id', se_journey_normalize_wa_id($wa_user_id));

    return $CI->db->get(db_prefix() . 'se_journeys')->row();
}

function se_journey_find_by_lead($brand_id, $lead_id)
{
    $CI = &get_instance();
    $CI->db->where('brand_id', (int) $brand_id)->where('lead_id', (int) $lead_id);

    return $CI->db->get(db_prefix() . 'se_journeys')->row();
}

/** Does the CRM lead row still exist for this brand? (By id + brand, never staff scope.) */
function se_journey_lead_exists($brand_id, $lead_id)
{
    if ((int) $lead_id <= 0) {
        return false;
    }
    $CI = &get_instance();
    $CI->db->select('id')->where('id', (int) $lead_id)->where('brand_id', (int) $brand_id);

    return (bool) $CI->db->get(db_prefix() . 'leads')->row();
}

/**
 * Point a journey (and its WhatsApp thread) at another CRM lead of the same
 * brand. Used when the lead the journey was started from no longer exists —
 * deleted in the CRM while the website pipeline re-created it under a new id.
 * The health record, photos and timeline stay with the journey; only the
 * lead link moves, and the new lead is brought up to date at once.
 */
function se_journey_relink_lead($journey, $lead_id, $staff_id = 0)
{
    $CI = &get_instance();
    $j  = is_object($journey) ? $journey : se_journey_get_raw((int) $journey);
    if (!$j || (int) $lead_id <= 0 || !se_journey_lead_exists((int) $j->brand_id, (int) $lead_id)) {
        return false;
    }
    $old = (int) $j->lead_id;
    $now = date('Y-m-d H:i:s');
    $CI->db->where('id', (int) $j->id)->where('brand_id', (int) $j->brand_id)
           ->update(db_prefix() . 'se_journeys', ['lead_id' => (int) $lead_id, 'last_updated' => $now]);
    $j->lead_id = (int) $lead_id;
    if ((int) $j->wa_conversation_id > 0) {
        $CI->db->where('id', (int) $j->wa_conversation_id)->where('brand_id', (int) $j->brand_id)
               ->update(db_prefix() . 'se_wa_conversations', ['lead_id' => (int) $lead_id, 'last_updated' => $now]);
    }
    se_journey_event($j, 'lead_linked', 'journey linked to CRM lead #' . (int) $lead_id . ($old > 0 ? ' (was #' . $old . ')' : ''),
        ['lead_id' => (int) $lead_id, 'previous_lead_id' => $old], $staff_id > 0 ? 'staff' : 'system', $staff_id > 0 ? (int) $staff_id : null, 'lead', (string) $lead_id);
    se_journey_audit((int) $j->brand_id, (int) $j->id, 'lead_relink', 'lead', (string) $lead_id, $old > 0 ? 'was #' . $old : null);
    if (function_exists('se_journey_sync_lead')) {
        try {
            se_journey_sync_lead($j, 'relink');
        } catch (Throwable $e) {
            se_journey_audit((int) $j->brand_id, (int) $j->id, 'lead_sync_failed', 'lead', (string) $lead_id, mb_substr(basename($e->getFile()) . ':' . $e->getLine(), 0, 191));
        }
    }

    return true;
}

/**
 * Perfex `after_lead_deleted`: a journey never dies with its lead row. The
 * link is cleared (a later lead with the same number re-links through
 * se_journey_start_from_lead) and the timeline says what happened, so a
 * patient who submitted the website form again is not left invisible.
 */
function se_journey_on_lead_deleted($lead_id)
{
    $lead_id = (int) (is_array($lead_id) ? ($lead_id['lead_id'] ?? 0) : $lead_id);
    if ($lead_id <= 0) {
        return 0;
    }
    $CI = &get_instance();
    $CI->db->where('lead_id', $lead_id);
    $rows = $CI->db->get(db_prefix() . 'se_journeys')->result();
    $n = 0;
    foreach ($rows as $j) {
        $now = date('Y-m-d H:i:s');
        $CI->db->where('id', (int) $j->id)->where('brand_id', (int) $j->brand_id)
               ->update(db_prefix() . 'se_journeys', ['lead_id' => 0, 'last_updated' => $now]);
        if ((int) $j->wa_conversation_id > 0) {
            $CI->db->where('id', (int) $j->wa_conversation_id)->where('brand_id', (int) $j->brand_id)->where('lead_id', $lead_id)
                   ->update(db_prefix() . 'se_wa_conversations', ['lead_id' => 0, 'last_updated' => $now]);
        }
        $j->lead_id = 0;
        se_journey_event($j, 'lead_deleted', 'CRM lead #' . $lead_id . ' was deleted — the journey continues; start it again from the patient\'s new lead to re-link',
            ['previous_lead_id' => $lead_id], 'staff', function_exists('get_staff_user_id') ? ((int) get_staff_user_id() ?: null) : null, 'lead', (string) $lead_id);
        se_journey_task($j, 'lead_deleted', 'CRM lead #' . $lead_id . ' was deleted while the journey was active — link the patient\'s current lead (Start journey on the new lead)', 'normal', null, (string) $lead_id);
        se_journey_audit((int) $j->brand_id, (int) $j->id, 'lead_deleted', 'lead', (string) $lead_id);
        $n++;
    }

    return $n;
}

/** Brand-scoped list with optional state filter (dashboard, list screen). */
function se_journey_list(array $filters = [])
{
    $CI = &get_instance();
    if (function_exists('se_apply_scope_in')) {
        se_apply_scope_in('brand_id');
    }
    if (!empty($filters['state'])) {
        $CI->db->where('state', (string) $filters['state']);
    }
    if (!empty($filters['urgent'])) {
        $CI->db->where('urgent', 1);
    }
    if (!empty($filters['assigned'])) {
        $CI->db->where('assigned_staff', (int) $filters['assigned']);
    }
    $CI->db->order_by('last_updated', 'DESC');
    $CI->db->limit(max(1, min(200, (int) ($filters['limit'] ?? 100))));

    return $CI->db->get(db_prefix() . 'se_journeys')->result_array();
}

/* ===========================================================================
 * Lead identity — one CRM person per WhatsApp identity.
 * ======================================================================== */

function se_journey_default_lead_status($brand_id)
{
    $configured = (int) get_option('se_journey_lead_status_' . (int) $brand_id);
    if ($configured > 0) {
        return $configured;
    }
    if (function_exists('se_website_lead_default_status')) {
        return (int) se_website_lead_default_status($brand_id);
    }

    return 0;
}

function se_journey_default_lead_source($brand_id)
{
    $configured = (int) get_option('se_journey_lead_source_' . (int) $brand_id);
    if ($configured > 0) {
        return $configured;
    }
    if (function_exists('se_website_lead_default_source')) {
        return (int) se_website_lead_default_source($brand_id);
    }

    return 0;
}

/**
 * Find the existing lead for this WhatsApp identity inside the brand:
 * first a journey link, then a normalised phone-number scan of the brand's
 * leads (Perfex stores free-text phone numbers, so formatting cannot be
 * trusted in SQL). Returns the lead id or 0.
 */
function se_journey_find_lead_by_phone($brand_id, $wa_user_id)
{
    $CI = &get_instance();
    $want = se_journey_normalize_wa_id($wa_user_id);
    if ($want === '') {
        return 0;
    }

    $CI->db->select('id, phonenumber')->where('brand_id', (int) $brand_id)->where('phonenumber !=', '')
           ->order_by('id', 'DESC')->limit(SE_JOURNEY_LEAD_SCAN_LIMIT);
    foreach ($CI->db->get(db_prefix() . 'leads')->result_array() as $row) {
        if (se_journey_normalize_wa_id($row['phonenumber']) === $want) {
            return (int) $row['id'];
        }
    }

    return 0;
}

/**
 * Create the CRM lead for a new WhatsApp enquiry. Name falls back to a
 * masked label; the number is stored E.164. Attribution copied from the
 * referral object ONLY — nothing is invented.
 */
function se_journey_create_lead($brand_id, $wa_user_id, $profile_name, array $source)
{
    $CI = &get_instance();
    $status = se_journey_default_lead_status($brand_id);
    $src    = se_journey_default_lead_source($brand_id);
    if ($status <= 0 || $src <= 0) {
        return ['ok' => false, 'lead_id' => 0, 'reason' => 'pipeline_unconfigured'];
    }

    $digits = se_journey_normalize_wa_id($wa_user_id);
    $name   = trim((string) $profile_name);
    if ($name === '') {
        $name = 'WhatsApp ••••' . substr($digits, -4);
    }
    $attr = $source['attribution'] ?? [];

    $row = [
        'brand_id'       => (int) $brand_id,
        'name'           => mb_substr($name, 0, 191),
        'email'          => '',
        'phonenumber'    => '+' . $digits,
        'status'         => $status,
        'source'         => $src,
        'assigned'       => 0,
        'addedfrom'      => 0,
        'is_public'      => 0,
        'description'    => '',
        'address'        => '',
        'country'        => 0,
        'dateadded'      => date('Y-m-d H:i:s'),
        'consent_ads'    => 0,
        'consent_marketing' => 0,
        'ctwa_clid'      => isset($attr['ctwa_clid']) ? mb_substr((string) $attr['ctwa_clid'], 0, 255) : null,
        'utm_source'     => $source['source'] === 'meta_click_to_whatsapp_ad' ? 'meta' : ($source['source'] === 'instagram_prefilled_link' ? 'instagram' : null),
        'utm_medium'     => $source['source'] === 'meta_click_to_whatsapp_ad' ? 'ctwa' : ($source['source'] === 'instagram_prefilled_link' ? 'whatsapp_link' : null),
        'utm_campaign'   => null,   // NEVER fabricated; a campaign id only arrives via referral.source_id
        'utm_content'    => isset($attr['source_id']) ? mb_substr('ad:' . $attr['source_id'], 0, 255) : null,
        'first_touch_at' => date('Y-m-d H:i:s'),
    ];

    $CI->db->insert(db_prefix() . 'leads', $row);
    $leadId = (int) $CI->db->insert_id();
    if ($leadId <= 0) {
        return ['ok' => false, 'lead_id' => 0, 'reason' => 'insert_failed'];
    }
    hooks()->do_action('lead_created', $leadId);
    log_activity('WhatsApp journey lead created [ID: ' . $leadId . ']');

    return ['ok' => true, 'lead_id' => $leadId, 'reason' => ''];
}

/* ===========================================================================
 * Journey creation
 * ======================================================================== */

/**
 * Create the journey row for a first inbound message. Idempotent on
 * (brand, wa_user_id): a concurrent duplicate returns the existing row.
 */
function se_journey_create($brand_id, array $ctx, array $source, $lead_id)
{
    $CI  = &get_instance();
    $now = date('Y-m-d H:i:s');
    $wa  = se_journey_normalize_wa_id($ctx['from']);
    $body = (string) ($ctx['body'] ?? '');

    $row = [
        'brand_id'           => (int) $brand_id,
        'lead_id'            => (int) $lead_id,
        'wa_conversation_id' => (int) ($ctx['conversation_id'] ?? 0),
        'wa_user_id'         => $wa,
        'display_name'       => $ctx['profile_name'] !== '' ? mb_substr((string) $ctx['profile_name'], 0, 191) : null,
        'language'           => 'tr',
        'state'              => 'new_whatsapp_enquiry',
        'state_changed_at'   => $now,
        'source'             => $source['source'],
        'source_detail'      => mb_substr((string) $source['detail'], 0, 191),
        'source_confidence'  => mb_substr((string) $source['confidence'], 0, 16),
        'attribution_json'   => $source['attribution'] ? json_encode($source['attribution']) : null,
        'initial_message'    => $body !== '' ? mb_substr($body, 0, SE_JOURNEY_MAX_INITIAL) : null,
        'first_touch_at'     => (string) ($ctx['received_at'] ?? $now),
        'latest_touch_at'    => (string) ($ctx['received_at'] ?? $now),
        'automation_state'   => 'active',
        'automation_reason'  => null,
        'assigned_staff'     => 0,
        'client_id'          => 0,
        'patient_id'         => 0,
        'previous_state'     => null,
        'reminder_count'     => 0,
        'urgent'             => 0,
        'review_decision'    => null,
        'photos_required_json' => null,
        'consultation_appointment_id' => 0,
        'procedure_appointment_id'    => 0,
        'aftercare_plan_id'  => 0,
        'last_send_block'    => null,
        'next_action'        => null,
        'next_action_due_at' => null,
        'last_outbound_at'   => null,
        'last_reminder_at'   => null,
        'welcome_sent_at'    => null,
        'opted_out_at'       => null,
        'intake_version'     => null,
        'intake_submitted_at'=> null,
        'urgent_at'          => null,
        'deposit_state'      => null,
        'payment_ref'        => null,
        'procedure_at'       => null,
        'date_created'       => $now,
        'last_updated'       => $now,
    ];

    try {
        $CI->db->insert(db_prefix() . 'se_journeys', $row);
        $id = (int) $CI->db->insert_id();
    } catch (Exception $e) {
        $id = 0;
    }
    if ($id <= 0) {
        $existing = se_journey_find_by_wa($brand_id, $wa);

        return $existing ? ['journey' => $existing, 'created' => false] : ['journey' => null, 'created' => false];
    }

    $j = se_journey_get_raw($id);
    // The transition log starts with the creation edge (null -> new).
    $CI->db->insert(db_prefix() . 'se_journey_transitions', [
        'journey_id' => $id, 'brand_id' => (int) $brand_id, 'from_state' => null, 'to_state' => 'new_whatsapp_enquiry',
        'trigger_key' => 'inbound_' . $source['source'], 'actor_type' => 'patient', 'actor_id' => null,
        'correlation_id' => (string) ($ctx['wamid'] ?? ''), 'note' => null, 'created_at' => $now,
    ]);

    // Link the WhatsApp thread to the lead (column existed, was never filled).
    if ((int) $lead_id > 0 && (int) ($ctx['conversation_id'] ?? 0) > 0) {
        $CI->db->where('id', (int) $ctx['conversation_id'])->where('brand_id', (int) $brand_id)
               ->update(db_prefix() . 'se_wa_conversations', ['lead_id' => (int) $lead_id]);

        /* A thread that a click-to-WhatsApp ad opened is the conversion that
         * campaign exists to produce, and until now nothing reported it: the
         * only conversion signals the system had all ran through the website,
         * which a messages ad never touches. This is the first moment both
         * halves are in hand — the ad's click id (captured on the FIRST
         * inbound and never repeated) and the lead it belongs to.
         *
         * Queued, not sent: the outbox owns delivery, consent and retries,
         * and the destination stays gated off until the owner enables it. */
        if (function_exists('se_capi_messaging_queue_for_wa_conversation')) {
            se_capi_messaging_queue_for_wa_conversation((int) $ctx['conversation_id'], (int) $lead_id);
        }
    }

    return ['journey' => $j, 'created' => true];
}

/**
 * Staff start from the WhatsApp thread: a person who wrote before the module
 * existed (or before automation was enabled) has no journey row. Create it
 * from the conversation (same identity rules as the inbound path: reuse the
 * thread's lead, else the lead that owns the number, else a new lead) and
 * send the welcome — buttons inside the window, the approved start template
 * outside it. Never restarts a journey that already moved on.
 *
 * @return array{ok:bool,reason:string,mode:string,journey:object|null,created:bool}
 */
function se_journey_start_from_conversation($conv, $staff_id, array $opts = [])
{
    $brand = (int) ($conv->brand_id ?? 0);
    $from  = (string) ($conv->wa_user_id ?? '');
    if ($brand <= 0 || $from === '') {
        return ['ok' => false, 'reason' => 'invalid_conversation', 'mode' => '', 'journey' => null, 'created' => false];
    }
    if (!se_journey_enabled($brand)) {
        return ['ok' => false, 'reason' => 'disabled', 'mode' => '', 'journey' => null, 'created' => false];
    }

    $j = se_journey_find_by_wa($brand, $from);
    $created = false;
    if (!$j) {
        $source  = ['source' => (string) ($opts['source'] ?? 'organic_whatsapp'), 'detail' => (string) ($opts['detail'] ?? 'staff_start'),
                    'confidence' => isset($opts['source']) ? 'exact' : 'none', 'attribution' => []];
        $lead_id = (int) ($opts['lead_id'] ?? 0) > 0 ? (int) $opts['lead_id']
                 : ((int) ($conv->lead_id ?? 0) > 0 ? (int) $conv->lead_id : se_journey_find_lead_by_phone($brand, $from));
        if ($lead_id <= 0) {
            $lead = se_journey_create_lead($brand, $from, '', $source);
            $lead_id = (int) $lead['lead_id'];
        }
        $ctx = ['from' => $from, 'conversation_id' => (int) ($conv->id ?? 0), 'profile_name' => '', 'body' => '',
                'received_at' => (string) (!empty($conv->last_inbound_at) ? $conv->last_inbound_at : date('Y-m-d H:i:s')),
                'wamid' => 'staff:' . (int) $staff_id];
        $made = se_journey_create($brand, $ctx, $source, $lead_id);
        $j = $made['journey'];
        $created = (bool) $made['created'];
        if (!$j) {
            return ['ok' => false, 'reason' => 'create_failed', 'mode' => '', 'journey' => null, 'created' => false];
        }
        if ((int) $staff_id > 0) {
            se_journey_event($j, 'staff_started', 'journey created from the WhatsApp thread', [], 'staff', (int) $staff_id, 'wa_conversation', (string) ($conv->id ?? ''), 'staff:' . (int) $staff_id);
        } else {
            se_journey_event($j, 'auto_started', 'journey started automatically (' . (string) ($opts['detail'] ?? 'system') . ')', [], 'system', null, 'wa_conversation', (string) ($conv->id ?? ''), (string) ($opts['detail'] ?? 'system'));
        }
    } elseif ((int) ($opts['lead_id'] ?? 0) > 0 && (int) $opts['lead_id'] !== (int) $j->lead_id && !se_journey_lead_exists($brand, (int) $j->lead_id)) {
        // The journey's CRM lead is gone (deleted in the CRM, or never set) and
        // the same number arrived as a new lead — one patient, one journey:
        // re-link rather than leave the journey pointing at nothing.
        se_journey_relink_lead($j, (int) $opts['lead_id'], (int) $staff_id);
        $relinked = true;
    }
    if ((string) $j->state !== 'new_whatsapp_enquiry') {
        return ['ok' => false, 'reason' => !empty($relinked) ? 'relinked' : 'already_started', 'mode' => '', 'journey' => $j, 'created' => $created];
    }
    if (!function_exists('se_journey_send_welcome')) {
        return ['ok' => false, 'reason' => 'messaging_unavailable', 'mode' => '', 'journey' => $j, 'created' => $created];
    }
    $r = se_journey_send_welcome($j, 'staff:' . (int) $staff_id);

    return ['ok' => (bool) $r['ok'], 'reason' => (string) ($r['reason'] ?? ''), 'mode' => (string) ($r['mode'] ?? ''),
            'journey' => se_journey_get_raw((int) $j->id), 'created' => $created];
}

/**
 * Staff start from a LEAD that has a phone number but no WhatsApp thread yet —
 * the website applicant. Rules, in order: brand automation on; a usable
 * number; the lead's own contact consent (the website form's tick, recorded
 * as purpose=marketing) — never a cold template; not opted out. Then the
 * thread row is created for the brand's active number (window closed, so the
 * approved start template is what goes out) and the thread path takes over.
 * A lead that already has a thread simply uses it.
 *
 * @return array{ok:bool,reason:string,mode:string,journey:object|null,created:bool}
 */
function se_journey_start_from_lead($lead_id, $staff_id, array $opts = [])
{
    $CI = &get_instance();
    $fail = function ($reason) { return ['ok' => false, 'reason' => $reason, 'mode' => '', 'journey' => null, 'created' => false]; };

    $CI->db->where('id', (int) $lead_id);
    // A staff member sees their brands; the SYSTEM (auto-start from the
    // website endpoint, no session) goes by id and checks the brand below.
    if ((int) $staff_id > 0 && function_exists('se_apply_scope_in')) {
        se_apply_scope_in('brand_id');
    }
    $lead = $CI->db->get(db_prefix() . 'leads')->row();
    if (!$lead) {
        return $fail('lead_not_found');
    }
    $brand = (int) ($lead->brand_id ?? 0);
    if ($brand <= 0) {
        return $fail('lead_without_brand');
    }
    if (!se_journey_enabled($brand)) {
        return $fail('disabled');
    }
    $wa = se_journey_normalize_wa_id((string) ($lead->phonenumber ?? ''));
    if ($wa === '' || strlen($wa) < 10 || strlen($wa) > 15) {
        return $fail('no_usable_phone');
    }
    // Contact consent: the website form's tick is recorded as purpose=marketing
    // (se_website_lead.php); the lead column mirrors it for imports.
    $consented = (function_exists('se_consent_granted') && se_consent_granted($brand, 'lead', (int) $lead->id, 'marketing'))
              || (int) ($lead->consent_marketing ?? 0) === 1;
    if (!$consented) {
        return $fail('contact_consent_missing');
    }
    $existing = se_journey_find_by_wa($brand, $wa);
    if ($existing && (string) $existing->state === 'opted_out') {
        return ['ok' => false, 'reason' => 'opted_out', 'mode' => '', 'journey' => $existing, 'created' => false];
    }

    // The thread: reuse the brand's row for this number, else create one on
    // the brand's active WhatsApp number with the window CLOSED (nobody wrote).
    $CI->db->where('brand_id', $brand)->where('wa_user_id', $wa);
    $conv = $CI->db->get(db_prefix() . 'se_wa_conversations')->row();
    if (!$conv) {
        $CI->db->where('brand_id', $brand)->where('state', 'active')->order_by('id', 'ASC')->limit(1);
        $number = $CI->db->get(db_prefix() . 'se_wa_numbers')->row();
        if (!$number || (string) $number->phone_number_id === '') {
            return $fail('no_active_number');
        }
        $now = date('Y-m-d H:i:s');
        try {
            $CI->db->insert(db_prefix() . 'se_wa_conversations', [
                'brand_id' => $brand, 'phone_number_id' => (string) $number->phone_number_id, 'wa_user_id' => $wa,
                'lead_id' => (int) $lead->id, 'client_id' => 0, 'assigned_staff' => 0, 'unread_count' => 0,
                'last_inbound_at' => null, 'window_expires_at' => null, 'ctwa_clid' => null, 'referral_json' => null,
                'state' => 'open', 'date_created' => $now, 'last_updated' => $now,
            ]);
        } catch (Exception $e) {
            return $fail('conversation_create_failed');
        }
        $CI->db->where('brand_id', $brand)->where('wa_user_id', $wa);
        $conv = $CI->db->get(db_prefix() . 'se_wa_conversations')->row();
        if (!$conv) {
            return $fail('conversation_create_failed');
        }
    } elseif ((int) $conv->lead_id <= 0) {
        $CI->db->where('id', (int) $conv->id)->update(db_prefix() . 'se_wa_conversations', ['lead_id' => (int) $lead->id]);
        $conv->lead_id = (int) $lead->id;
    }

    return se_journey_start_from_conversation($conv, $staff_id, ['source' => 'website_form', 'detail' => (string) ($opts['detail'] ?? 'staff_start_from_lead'), 'lead_id' => (int) $lead->id]);
}

/**
 * Perfex `lead_created`: a WEBSITE lead (website_lead_id set — the form on
 * azinasgari.com through se_core/website_lead) starts its journey at once
 * when the brand switch is on: the approved start template goes out (the
 * person has not written on WhatsApp yet, so there is no window). Leads
 * created by staff, imports, or by the journey itself for an organic
 * WhatsApp enquiry are not touched — staff press Start for those. Runs
 * without a staff session; nothing here is staff-scoped.
 */
function se_journey_on_lead_created($arg)
{
    $lead_id = (int) (is_array($arg) ? ($arg['lead_id'] ?? 0) : $arg);
    if ($lead_id <= 0) {
        return ['ok' => false, 'reason' => 'no_lead'];
    }
    $CI = &get_instance();
    $CI->db->select('id, brand_id, website_lead_id')->where('id', $lead_id);
    $lead = $CI->db->get(db_prefix() . 'leads')->row();
    if (!$lead || trim((string) ($lead->website_lead_id ?? '')) === '') {
        return ['ok' => false, 'reason' => 'not_website_lead'];
    }
    $brand = (int) $lead->brand_id;
    if ($brand <= 0 || !se_journey_enabled($brand) || !se_journey_auto_start_website($brand)) {
        return ['ok' => false, 'reason' => 'auto_start_off'];
    }
    if (se_journey_find_by_lead($brand, $lead_id)) {
        return ['ok' => false, 'reason' => 'already_started'];
    }
    try {
        $r = se_journey_start_from_lead($lead_id, 0, ['detail' => 'auto_start_website']);
    } catch (Throwable $e) {
        se_journey_audit($brand, 0, 'auto_start_failed', 'lead', (string) $lead_id, mb_substr(basename($e->getFile()) . ':' . $e->getLine(), 0, 191));

        return ['ok' => false, 'reason' => 'exception'];
    }
    $jid = !empty($r['journey']) ? (int) $r['journey']->id : 0;
    se_journey_audit($brand, $jid, $r['ok'] ? 'auto_start' : 'auto_start_blocked', 'lead', (string) $lead_id, $r['ok'] ? (string) $r['mode'] : (string) $r['reason']);
    if (function_exists('se_journey_lead_activity')) {
        // The lead's own timeline says what happened, so staff on the lead page see it without opening the journey.
        se_journey_lead_activity($lead_id, $r['ok']
            ? ('Hasta yolculuğu otomatik başlatıldı (' . ($r['mode'] === 'sandbox' ? 'sandbox — gönderilmedi' : ($r['mode'] === 'template' ? 'başlangıç şablonu gönderildi' : 'karşılama gönderildi')) . ')')
            : ('Hasta yolculuğu otomatik başlatılamadı: ' . (string) $r['reason'] . ' — fırsat sayfasından "Start WhatsApp evaluation" ile başlatın'));
    }

    return $r;
}

/* ===========================================================================
 * Automation control
 * ======================================================================== */

function se_journey_set_automation($journey, $state, $reason, $actor_type = 'system', $actor_id = 0)
{
    $CI = &get_instance();
    $j  = is_object($journey) ? $journey : se_journey_get_raw((int) $journey);
    if (!$j || !in_array($state, ['active', 'paused_patient', 'paused_staff', 'awaiting_approval', 'error', 'stopped', 'blocked'], true)) {
        return false;
    }
    $now = date('Y-m-d H:i:s');
    $CI->db->where('id', (int) $j->id)->where('brand_id', (int) $j->brand_id)->update(db_prefix() . 'se_journeys', [
        'automation_state'      => $state,
        'automation_reason'     => mb_substr((string) $reason, 0, 191),
        'automation_changed_by' => (int) $actor_id,
        'automation_changed_at' => $now,
        'last_updated'          => $now,
    ]);
    $j->automation_state = $state;
    $j->automation_reason = $reason;
    se_journey_event($j, 'automation_' . $state, (string) $reason, [], $actor_type, $actor_id ?: null);

    return true;
}

/** Is automated messaging allowed on this journey right now? */
function se_journey_automation_active($journey)
{
    return isset($journey->automation_state) && $journey->automation_state === 'active'
        && (string) $journey->state !== 'opted_out';
}

/**
 * Staff sent something from the WhatsApp composer: automation pauses on that
 * thread until a staff member resumes it deliberately (audited).
 */
function se_journey_on_staff_send($conversation, $staff_id, $outbound_id = 0)
{
    if (!$conversation || empty($conversation->wa_user_id)) {
        return;
    }
    $j = se_journey_find_by_wa((int) $conversation->brand_id, $conversation->wa_user_id);
    if (!$j || $j->automation_state !== 'active') {
        return;
    }
    se_journey_set_automation($j, 'paused_staff', 'staff_reply', 'staff', (int) $staff_id);
    se_journey_audit((int) $j->brand_id, (int) $j->id, 'automation_pause', 'wa_outbound', (string) $outbound_id, 'staff takeover from composer');
}

/** Deliberate staff resume. */
function se_journey_resume($journey, $staff_id, $reason = 'staff_resume')
{
    $j = is_object($journey) ? $journey : se_journey_get_raw((int) $journey);
    if (!$j || $j->state === 'opted_out') {
        return false;
    }
    se_journey_set_automation($j, 'active', $reason, 'staff', (int) $staff_id);
    se_journey_audit((int) $j->brand_id, (int) $j->id, 'automation_resume', null, null, $reason);

    return true;
}

/**
 * Opt back in AFTER an opt-out requires NEW evidence: a fresh inbound
 * message from the patient asking to continue, recorded by its wamid, or a
 * staff-entered evidence note. Without it the call is refused.
 */
function se_journey_reactivate($journey, $evidence_ref, $staff_id = 0, $note = '')
{
    $j = is_object($journey) ? $journey : se_journey_get_raw((int) $journey);
    if (!$j || $j->state !== 'opted_out' || trim((string) $evidence_ref) === '') {
        return ['ok' => false, 'reason' => 'evidence_required'];
    }
    $target = $j->previous_state && $j->previous_state !== 'opted_out' ? $j->previous_state : 'new_whatsapp_enquiry';
    $r = se_journey_transition($j, $target, 'reactivated', $staff_id ? 'staff' : 'patient', $staff_id ?: null, (string) $evidence_ref, $note, true);
    if ($r['ok']) {
        $CI = &get_instance();
        $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', ['opted_out_at' => null]);
        if (function_exists('se_consent_grant') && (int) $j->lead_id > 0) {
            se_consent_grant((int) $j->brand_id, (int) $j->lead_id, 'whatsapp', 'whatsapp', 'opt_back_in', (string) $evidence_ref, (int) $staff_id);
        }
        se_journey_set_automation($j, 'active', 'reactivated:' . $evidence_ref, $staff_id ? 'staff' : 'patient', $staff_id);
    }

    return $r;
}

/* ===========================================================================
 * Inbound WhatsApp listener — the entry point of the whole journey.
 * ======================================================================== */

function se_journey_on_wa_inbound(array $ctx)
{
    $brand_id = (int) ($ctx['brand_id'] ?? 0);
    if ($brand_id <= 0 || !se_journey_enabled($brand_id)) {
        return ['handled' => false, 'reason' => 'disabled'];
    }
    $from = (string) ($ctx['from'] ?? '');
    if ($from === '') {
        return ['handled' => false, 'reason' => 'no_sender'];
    }
    $now  = date('Y-m-d H:i:s');
    $CI   = &get_instance();
    $body = (string) ($ctx['body'] ?? '');
    $corr = (string) ($ctx['wamid'] ?? '');

    $j = se_journey_find_by_wa($brand_id, $from);
    $created = false;

    if (!$j) {
        $source = se_journey_detect_source($body, $ctx['referral'] ?? null);

        // One person: reuse the lead a previous channel created for this number.
        $lead_id = se_journey_find_lead_by_phone($brand_id, $from);
        if ($lead_id <= 0) {
            $lead = se_journey_create_lead($brand_id, $from, (string) ($ctx['profile_name'] ?? ''), $source);
            $lead_id = (int) $lead['lead_id'];
        }
        $made = se_journey_create($brand_id, $ctx, $source, $lead_id);
        $j = $made['journey'];
        $created = $made['created'];
        if (!$j) {
            return ['handled' => false, 'reason' => 'create_failed'];
        }
    } else {
        $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', ['latest_touch_at' => $now, 'last_updated' => $now]);
        $j->latest_touch_at = $now;
    }

    se_journey_event($j, 'wa_inbound', $ctx['type'] === 'text' ? 'text' : (string) $ctx['type'], [], 'patient', null, 'wa_message', (string) ($ctx['message_id'] ?? ''), $corr);

    /* 0. A completed WhatsApp Flow: the endpoint already stored the answers; this is the receipt. */
    if (!empty($ctx['flow_reply']) && is_array($ctx['flow_reply']) && function_exists('se_journey_on_flow_reply')) {
        return se_journey_on_flow_reply($j, $ctx);
    }

    /* 1. Opt-out beats everything, on every state. */
    if ($body !== '' && se_journey_matches_keyword($body, se_journey_optout_keywords())) {
        return se_journey_handle_optout($j, $ctx);
    }

    /* 2. Opted-out patients: a message that is NOT an opt-out is new evidence
     *    only when it explicitly asks to continue; otherwise staff decides. */
    if ($j->state === 'opted_out') {
        if ($body !== '' && se_journey_matches_keyword($body, se_journey_start_keywords())) {
            se_journey_reactivate($j, $corr, 0, 'patient asked to continue');
            return ['handled' => true, 'reason' => 'reactivated', 'journey_id' => (int) $j->id];
        }
        se_journey_task($j, 'optout_contact', 'Opted-out contact messaged again — staff decision required', 'normal', null, $corr);

        return ['handled' => true, 'reason' => 'opted_out_contact', 'journey_id' => (int) $j->id];
    }

    /* 3. Human handoff (keyword or button). */
    $button = (string) ($ctx['interactive_id'] ?? '');
    if ($button === 'jr_handoff' || ($body !== '' && se_journey_matches_keyword($body, se_journey_handoff_keywords()))) {
        return se_journey_handle_handoff($j, $ctx);
    }

    /* 4. Urgent symptoms during aftercare/procedure phases: escalate, never diagnose. */
    if (in_array($j->state, ['procedure_completed', 'aftercare_active', 'followup_due', 'preop_pending', 'completed'], true)
        && $body !== '' && se_journey_contains_keyword($body, se_journey_urgent_keywords())) {
        return se_journey_handle_urgent($j, $ctx);
    }

    /* 5. Media: photographs are routed by phase, and only with health consent. */
    if (in_array((string) $ctx['type'], ['image'], true) && !empty($ctx['media_ref']) && function_exists('se_journey_on_wa_media')) {
        return se_journey_on_wa_media($j, $ctx);
    }

    /* 6. Aftercare check-in answers. */
    if (in_array($j->state, ['aftercare_active', 'followup_due'], true) && function_exists('se_journey_on_aftercare_reply')) {
        $r = se_journey_on_aftercare_reply($j, $ctx);
        if (!empty($r['handled'])) {
            return $r;
        }
    }

    /* 7. Automation paused / stopped: staff owns the thread; only record. */
    if (!se_journey_automation_active($j)) {
        if ($j->automation_state === 'paused_staff' || $j->automation_state === 'paused_patient') {
            se_journey_task($j, 'inbound_while_paused', 'Patient replied while automation is paused', 'normal', null, $corr);
        }

        return ['handled' => true, 'reason' => 'automation_' . $j->automation_state, 'journey_id' => (int) $j->id];
    }

    /* 8. Journey step routing. */
    return se_journey_route_step($j, $ctx, $created);
}

/** Opt-out: confirm once, stop non-essential automation, keep the record. */
function se_journey_handle_optout($j, array $ctx)
{
    $corr = (string) ($ctx['wamid'] ?? '');
    if ($j->state !== 'opted_out') {
        se_journey_transition($j, 'opted_out', 'keyword_optout', 'patient', null, $corr);
        if (function_exists('se_consent_withdraw') && (int) $j->lead_id > 0) {
            se_consent_withdraw((int) $j->brand_id, (int) $j->lead_id, 'whatsapp', 'whatsapp', 'optout_keyword', mb_substr((string) $ctx['body'], 0, 64));
            se_consent_withdraw((int) $j->brand_id, (int) $j->lead_id, 'marketing', 'whatsapp', 'optout_keyword', mb_substr((string) $ctx['body'], 0, 64));
        }
        if (function_exists('se_journey_send_copy')) {
            se_journey_send_copy($j, 'optout_confirm', [], ['purpose' => 'optout_confirm', 'correlation' => $corr]);
        }
        se_journey_task($j, 'opted_out', 'Patient opted out (İPTAL/DUR/STOP) — retain only what policy requires', 'normal', null, '');
    }

    return ['handled' => true, 'reason' => 'opted_out', 'journey_id' => (int) $j->id];
}

/** Human handoff: pause automation, urgent staff task, short acknowledgement. */
function se_journey_handle_handoff($j, array $ctx)
{
    $corr = (string) ($ctx['wamid'] ?? '');
    if ($j->automation_state === 'active') {
        se_journey_set_automation($j, 'paused_patient', 'handoff_requested', 'patient');
    }
    se_journey_task($j, 'handoff', 'Patient asked for a human — reply from the WhatsApp inbox', 'urgent', null, $corr);
    if (function_exists('se_journey_send_copy')) {
        se_journey_send_copy($j, 'handoff_ack', [], ['purpose' => 'handoff_ack', 'correlation' => $corr, 'bypass_pause' => true]);
    }

    return ['handled' => true, 'reason' => 'handoff', 'journey_id' => (int) $j->id];
}

/** Urgent aftercare concern: stop routine automation, alert, approved instruction only. */
function se_journey_handle_urgent($j, array $ctx)
{
    $CI   = &get_instance();
    $corr = (string) ($ctx['wamid'] ?? '');
    $now  = date('Y-m-d H:i:s');
    $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', ['urgent' => 1, 'urgent_at' => $now, 'last_updated' => $now]);
    $j->urgent = 1;
    if ($j->automation_state === 'active') {
        se_journey_set_automation($j, 'paused_patient', 'urgent_symptom_reported', 'patient');
    }
    se_journey_task($j, 'urgent', 'URGENT: patient reported a possibly serious symptom — contact now', 'urgent', null, $corr);
    if (function_exists('se_journey_notify_urgent')) {
        se_journey_notify_urgent($j, $corr);
    }
    if (function_exists('se_journey_send_copy')) {
        se_journey_send_copy($j, 'urgent_ack', [], ['purpose' => 'urgent_ack', 'correlation' => $corr, 'bypass_pause' => true]);
    }

    return ['handled' => true, 'reason' => 'urgent', 'journey_id' => (int) $j->id];
}

/**
 * Step routing for an active journey. Everything patient-facing is delegated
 * to messaging.php (policy) and intake.php (links); this only decides WHICH
 * step applies.
 */
function se_journey_route_step($j, array $ctx, $created)
{
    $corr   = (string) ($ctx['wamid'] ?? '');
    $body   = (string) ($ctx['body'] ?? '');
    $button = (string) ($ctx['interactive_id'] ?? '');
    $state  = (string) $j->state;

    if ($state === 'new_whatsapp_enquiry') {
        $qualifies = in_array($j->source, ['instagram_prefilled_link', 'meta_click_to_whatsapp_ad'], true)
            || se_journey_auto_start_organic((int) $j->brand_id);
        if (!$qualifies) {
            se_journey_task($j, 'organic_enquiry', 'New WhatsApp enquiry (not from the handoff link) — start evaluation?', 'normal', null, '');

            return ['handled' => true, 'reason' => 'organic_waiting_staff', 'journey_id' => (int) $j->id];
        }
        if (function_exists('se_journey_send_welcome')) {
            se_journey_send_welcome($j, $corr);
        }

        return ['handled' => true, 'reason' => 'welcome', 'journey_id' => (int) $j->id];
    }

    if ($state === 'welcome_sent') {
        if ($button === 'jr_start' || ($body !== '' && se_journey_matches_keyword($body, se_journey_start_keywords()))) {
            if (function_exists('se_journey_send_privacy_and_link')) {
                se_journey_send_privacy_and_link($j, $corr, 'patient');
            }

            return ['handled' => true, 'reason' => 'start', 'journey_id' => (int) $j->id];
        }
        // Anything else: a person typed a question. Staff should look; the
        // bot repeats its options once, not on every message.
        se_journey_task($j, 'question_after_welcome', 'Patient wrote something other than an option after the welcome', 'normal', null, '');
        if (function_exists('se_journey_send_copy') && (int) $j->reminder_count === 0) {
            se_journey_send_copy($j, 'options_repeat', [], ['purpose' => 'options_repeat', 'correlation' => $corr]);
        }

        return ['handled' => true, 'reason' => 'question', 'journey_id' => (int) $j->id];
    }

    if (in_array($state, ['privacy_notice_sent', 'consent_pending', 'intake_link_sent', 'intake_started', 'intake_incomplete', 'consent_declined'], true)) {
        // The form is the channel for health data. Chat replies here are
        // questions for staff, or a request for a fresh link.
        if ($body !== '' && se_journey_matches_keyword($body, ['link', 'baglanti', 'form', 'tekrar gonder'])) {
            if (function_exists('se_journey_send_privacy_and_link')) {
                se_journey_send_privacy_and_link($j, $corr, 'patient');
            }

            return ['handled' => true, 'reason' => 'link_resent', 'journey_id' => (int) $j->id];
        }
        se_journey_task($j, 'question_during_intake', 'Patient wrote during intake — may need help with the form', 'normal', null, $corr);

        return ['handled' => true, 'reason' => 'intake_question', 'journey_id' => (int) $j->id];
    }

    if (in_array($state, ['photos_requested', 'photos_incomplete', 'photo_retake_requested'], true)) {
        se_journey_task($j, 'question_during_photos', 'Patient wrote while photos are pending', 'normal', null, $corr);

        return ['handled' => true, 'reason' => 'photo_phase_text', 'journey_id' => (int) $j->id];
    }

    /* The quote is out: the patient answers with a reply button (in-window
     * interactive or template quick-reply — both arrive as interactive_id),
     * or types it. Accept → booking link; revision → staff; anything else →
     * staff, and the options are repeated once. */
    $quotePhase = in_array($state, ['quote_sent', 'quote_accepted', 'quote_revision_requested'], true);
    if (!$quotePhase && function_exists('se_journey_quote_sent_row')) {
        // A sent quote awaiting its answer is the quote phase whatever the state
        // says (a quote sent from ready_for_review before 2026-09-03 left the
        // state behind): repair the state, then read the answer.
        $open = se_journey_quote_sent_row($j);
        if ($open && (string) ($open->patient_response ?? '') === ''
            && ($button === 'jr_quote_accept' || $button === 'jr_quote_revise'
                || ($body !== '' && (se_journey_matches_keyword($body, se_journey_quote_accept_keywords()) || se_journey_matches_keyword($body, se_journey_quote_revise_keywords()))))) {
            $rep = se_journey_transition($j, 'quote_sent', 'quote_phase_repair', 'system', null, $corr, 'quote v' . (int) $open->version . ' was sent while the journey was at ' . $state, true);
            if (!empty($rep['ok'])) {
                $state = 'quote_sent';
                $quotePhase = true;
            }
        }
    }
    if ($quotePhase && function_exists('se_journey_quote_respond')) {
        if ($button === 'jr_quote_accept' || ($body !== '' && se_journey_matches_keyword($body, se_journey_quote_accept_keywords()))) {
            se_journey_quote_respond($j, 'accept', 'whatsapp', $corr);   // idempotent: repeats the booking link

            return ['handled' => true, 'reason' => 'quote_accepted', 'journey_id' => (int) $j->id];
        }
        if ($button === 'jr_quote_revise' || ($body !== '' && se_journey_matches_keyword($body, se_journey_quote_revise_keywords()))) {
            se_journey_quote_respond($j, 'revise', 'whatsapp', $corr);

            return ['handled' => true, 'reason' => 'quote_revision_requested', 'journey_id' => (int) $j->id];
        }
    }
    if ($state === 'quote_sent') {
        se_journey_task($j, 'question_after_quote', 'Patient replied to the quote with a question — see WhatsApp thread', 'normal', null, $corr);
        if (function_exists('se_journey_send_quote_options')) {
            se_journey_send_quote_options($j, $corr);   // deduplicated: once per quote
        }

        return ['handled' => true, 'reason' => 'quote_question', 'journey_id' => (int) $j->id];
    }

    if ($state === 'quote_accepted') {
        // "link / randevu": the booking link again; anything else is for staff.
        if ($body !== '' && se_journey_matches_keyword($body, ['link', 'baglanti', 'randevu', 'randevu linki', 'tekrar gonder', 'gorusme', 'tarih'])
            && function_exists('se_journey_send_booking_link')) {
            se_journey_send_booking_link($j, 0, $corr);

            return ['handled' => true, 'reason' => 'booking_link_resent', 'journey_id' => (int) $j->id];
        }
        se_journey_task($j, 'question_after_accept', 'Patient wrote after accepting the quote — see WhatsApp thread', 'normal', null, $corr);

        return ['handled' => true, 'reason' => 'accepted_phase_text', 'journey_id' => (int) $j->id];
    }

    // Review / quote / consultation / procedure phases: a human conversation.
    se_journey_task($j, 'patient_message', 'Patient replied — see WhatsApp thread', 'normal', null, $corr);

    return ['handled' => true, 'reason' => 'staff_phase', 'journey_id' => (int) $j->id];
}

/**
 * A message Meta accepted but later reported as FAILED (status webhook). Make
 * it visible on the journey instead of leaving the tracker on "sent".
 */
function se_journey_on_delivery_failed($brand_id, $wamid, $error_text)
{
    $CI = &get_instance();
    $CI->db->where('wamid', (string) $wamid)->where('brand_id', (int) $brand_id);
    $out = $CI->db->get(db_prefix() . 'se_wa_outbound')->row();
    if (!$out) {
        return;
    }
    $CI->db->where('id', (int) $out->conversation_id);
    $conv = $CI->db->get(db_prefix() . 'se_wa_conversations')->row();
    if (!$conv) {
        return;
    }
    $j = se_journey_find_by_wa((int) $brand_id, (string) $conv->wa_user_id);
    if (!$j) {
        return;
    }
    $what = strpos((string) ($out->origin ?? ''), 'journey:') === 0 ? substr((string) $out->origin, 8) : 'staff message';
    se_journey_event($j, 'wa_delivery_failed', $what . ': ' . ($error_text !== '' ? $error_text : 'failed'), [], 'provider', null, 'wa_outbound', (string) $out->id, (string) $wamid);
    se_journey_task($j, 'delivery_failed', 'WhatsApp message not delivered (' . ($error_text !== '' ? $error_text : 'failed') . ') — ' . $what, 'normal', null, (string) $out->id);
    $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', ['last_send_block' => mb_substr('delivery_failed:' . $error_text, 0, 191), 'last_updated' => date('Y-m-d H:i:s')]);
}

/* Register with the WhatsApp module. The global works whatever the module
 * load order (se_journey sorts BEFORE se_whatsapp on disk). */
if (!isset($GLOBALS['SE_WA_INBOUND_LISTENERS']) || !is_array($GLOBALS['SE_WA_INBOUND_LISTENERS'])) {
    $GLOBALS['SE_WA_INBOUND_LISTENERS'] = [];
}
$GLOBALS['SE_WA_INBOUND_LISTENERS']['se_journey'] = 'se_journey_on_wa_inbound';
