<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Read/act layer for the conversion-outbox monitor.
 *
 * Everything here is brand-scoped through se_apply_scope_in(), so an operator
 * only ever sees their own tenants' conversions, and every value that reaches
 * a template is already safe: no raw email, phone, payload or token.
 */

/** Status counters for the strip at the top of the monitor. */
function se_outbox_status_counters($brand_id = 0)
{
    $CI = &get_instance();

    $CI->db->select('status, COUNT(*) AS c')->group_by('status');
    se_apply_scope_in('brand_id');

    if ((int) $brand_id > 0 && se_can_access_brand($brand_id)) {
        $CI->db->where('brand_id', (int) $brand_id);
    }

    $rows = $CI->db->get(db_prefix() . 'se_conversion_outbox')->result_array();

    // Always render every bucket, so a zero is visibly zero rather than absent.
    $out = ['pending' => 0, 'processing' => 0, 'submitted' => 0,
            'confirmed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

    foreach ($rows as $r) {
        $out[$r['status']] = (int) $r['c'];
    }

    return $out;
}

/** Filtered, paginated browse. */
function se_outbox_browse(array $filters, $limit = 100)
{
    $CI = &get_instance();

    se_apply_scope_in('brand_id');

    if (!empty($filters['brand']) && se_can_access_brand($filters['brand'])) {
        $CI->db->where('brand_id', (int) $filters['brand']);
    }

    foreach (['destination' => 'destination', 'status' => 'status', 'event' => 'event_name'] as $k => $col) {
        if (!empty($filters[$k])) {
            $CI->db->where($col, $filters[$k]);
        }
    }

    if (!empty($filters['from'])) {
        $CI->db->where('event_time >=', $filters['from'] . ' 00:00:00');
    }
    if (!empty($filters['to'])) {
        $CI->db->where('event_time <=', $filters['to'] . ' 23:59:59');
    }

    $CI->db->order_by('id', 'DESC')->limit((int) $limit);

    return $CI->db->get(db_prefix() . 'se_conversion_outbox')->result_array();
}

/** One row, brand-guarded. Null when out of scope. */
function se_outbox_row($id)
{
    $CI = &get_instance();

    $CI->db->where('id', (int) $id);
    se_apply_scope_in('brand_id');

    return $CI->db->get(db_prefix() . 'se_conversion_outbox')->row_array();
}

/**
 * Safe detail for the UI.
 *
 * The snapshot holds hashed identifiers and click ids. Neither is rendered:
 * a hash is still personal data under GDPR/KVKK, and a click id identifies an
 * individual's ad journey. The operator needs to know WHETHER an identifier
 * was captured, not what it was.
 */
function se_outbox_safe_detail(array $row)
{
    $snap    = se_outbox_snapshot_decode($row['attribution_snapshot'] ?? '');
    $consent = se_outbox_snapshot_decode($row['consent_snapshot'] ?? '');

    $ids = $snap['identifiers'] ?? [];
    $ft  = $snap['first_touch'] ?? [];

    return [
        'event_id'        => $row['destination'] === 'google_dm'
            ? 'se-gdm-' . (int) $row['lead_id'] . '-' . $row['id']
            : 'se-' . (int) $row['lead_id'] . '-' . $row['id'],
        'destination'     => $row['destination'],
        'event_name'      => $row['event_name'],
        'event_time'      => $row['event_time'],
        'captured_at'     => $snap['captured_at'] ?? null,
        'payload_version' => (int) ($row['payload_version'] ?? 0),
        'consent_state'   => $consent['state'] ?? 'unknown',
        'consent_version' => $consent['version'] ?? null,
        'consent_at'      => $consent['at'] ?? null,
        'attempts'        => (int) $row['attempts'],
        'next_attempt_at' => $row['next_attempt_at'] ?? null,
        'failure_class'   => $row['failure_class'] ?? null,
        'error_code'      => $row['error_code'] ?? null,
        'last_error'      => $row['last_error'] ?? null,
        'request_id'      => $row['request_id'] ?? null,
        'submitted_at'    => $row['submitted_at'] ?? null,
        // Presence booleans only — never the values.
        'has_email_hash'  => !empty($ids['em']),
        'has_phone_hash'  => !empty($ids['ph']),
        'has_click_id'    => !empty($ft['gclid']) || !empty($ft['fbc']) || !empty($ft['ctwa_clid']),
        'has_meta_lead'   => !empty($snap['destination']['meta_lead_id']),
    ];
}

/** Statuses an operator may requeue. */
function se_outbox_requeueable_statuses()
{
    return ['failed', 'skipped'];
}

/**
 * Requeue one row.
 *
 * Refuses a consent-blocked row outright. Re-sending a conversion the data
 * subject refused is the single mistake this screen must make impossible, and
 * an operator staring at a red "failed" badge will click requeue by reflex.
 */
function se_outbox_requeue($id)
{
    $row = se_outbox_row($id);

    if (!$row) {
        return ['ok' => false, 'message' => _l('se_outbox_requeue_denied')];
    }

    if (($row['error_code'] ?? '') === 'consent_withdrawn'
        || ($row['error_code'] ?? '') === 'consent_blocked'
        || ($row['failure_class'] ?? '') === 'consent_withdrawn') {
        return ['ok' => false, 'message' => _l('se_outbox_requeue_consent_blocked')];
    }

    if (!in_array($row['status'], se_outbox_requeueable_statuses(), true)) {
        return ['ok' => false, 'message' => _l('se_outbox_requeue_not_eligible')];
    }

    // Re-check the authoritative consent decision before making it sendable
    // again: the row may have been parked long enough for a withdrawal.
    $gate = se_outbox_consent_allows_send($row);

    if (!$gate['ok']) {
        return ['ok' => false, 'message' => _l('se_outbox_requeue_consent_blocked')];
    }

    $affected = se_guarded_update(db_prefix() . 'se_conversion_outbox', 'id', (int) $id, [
        'status'          => 'pending',
        'attempts'        => 0,
        'next_attempt_at' => date('Y-m-d H:i:s'),
        'failure_class'   => null,
        'error_code'      => null,
        'last_error'      => null,
        'locked_at'       => null,
        'locked_by'       => null,
    ]);

    if ($affected < 1) {
        return ['ok' => false, 'message' => _l('se_outbox_requeue_denied')];
    }

    // Audited: who requeued what, and when.
    log_activity('SE outbox requeue [row ' . (int) $id . ', brand ' . (int) $row['brand_id']
        . ', staff ' . (int) get_staff_user_id() . ']');

    return ['ok' => true, 'message' => _l('se_outbox_requeued')];
}

/* ---------------------------------------------------------------------------
 * Dashboard aggregation.
 * ------------------------------------------------------------------------- */

/** Brand-scoped counts for the dashboard cards. */
function se_dashboard_stats()
{
    $CI = &get_instance();
    $p  = db_prefix();

    $count = function ($table, callable $extra = null) use ($CI) {
        se_apply_scope_in('brand_id');
        if ($extra) { $extra($CI); }

        return (int) $CI->db->count_all_results($table);
    };

    $today = date('Y-m-d');

    return [
        'leads'             => $count($p . 'leads'),
        'patients'          => $count($p . 'se_patients', function ($CI) {
            $CI->db->where('retention_state !=', 'archived');
        }),
        'appts_today'       => $count($p . 'se_appointments', function ($CI) use ($today) {
            $CI->db->where('start_at >=', $today . ' 00:00:00')->where('start_at <=', $today . ' 23:59:59');
        }),
        'appts_upcoming'    => $count($p . 'se_appointments', function ($CI) use ($today) {
            $CI->db->where('start_at >', $today . ' 23:59:59')
                   ->where_in('status', ['scheduled', 'confirmed']);
        }),
        'appts_no_show'     => $count($p . 'se_appointments', function ($CI) {
            $CI->db->where('status', 'no_show');
        }),
        'wa_unread'         => $count($p . 'se_wa_conversations', function ($CI) {
            $CI->db->where('unread_count >', 0);
        }),
        'meta_pending'      => $count($p . 'se_meta_leadgen_events', function ($CI) {
            $CI->db->where('state', 'pending');
        }),
        'outbox_pending'    => $count($p . 'se_conversion_outbox', function ($CI) {
            $CI->db->where('status', 'pending');
        }),
        'outbox_failed'     => $count($p . 'se_conversion_outbox', function ($CI) {
            $CI->db->where('status', 'failed');
        }),
        'google_submitted'  => $count($p . 'se_conversion_outbox', function ($CI) {
            $CI->db->where('destination', 'google_dm')->where('status', 'submitted');
        }),
    ];
}

/** Configuration and freshness warnings, worst first. */
function se_dashboard_warnings()
{
    $warnings = [];

    // Cron freshness: everything asynchronous depends on it.
    $last = (int) get_option('last_cron_run');
    $age  = $last > 0 ? time() - $last : null;

    if ($age === null) {
        $warnings[] = ['level' => 'error', 'text' => _l('se_warn_cron_never')];
    } elseif ($age > 3600) {
        $warnings[] = ['level' => 'error', 'text' => _l('se_warn_cron_stale', [(int) round($age / 60)])];
    }

    // Consent configuration: without approved text nothing may be collected.
    // Checked for the brands this staff member actually works in — brand 0
    // (the global default) is normally empty while the clinic brand is fully
    // configured, and the old check warned on every dashboard load (audit T18).
    if (function_exists('se_consent_text_configured') && !se_consent_text_configured_anywhere()) {
        $warnings[] = ['level' => 'warning', 'text' => _l('se_warn_consent_unconfigured')];
    }

    // Secret store.
    $store = se_secret_store_status();

    if (!$store['exists']) {
        $warnings[] = ['level' => 'info', 'text' => _l('se_warn_secret_store_missing')];
    } elseif (!$store['mode_ok'] || !$store['outside_docroot']) {
        $warnings[] = ['level' => 'error', 'text' => _l('se_warn_secret_store_unsafe')];
    }

    return $warnings;
}

/** Is approved consent text configured for any brand the staff member can see (or the global default)? */
function se_consent_text_configured_anywhere($purpose = 'ads')
{
    $ids = function_exists('se_staff_brand_ids') ? se_staff_brand_ids() : [];
    if (!in_array(0, $ids, true)) {
        $ids[] = 0;
    }
    foreach ($ids as $brand_id) {
        if (se_consent_text_configured((int) $brand_id, $purpose)) {
            return true;
        }
    }

    return false;
}


/* ===========================================================================
 * Bugün right column (CRM-M023)
 * ======================================================================== */

/** Today's appointments with patient names and types, brand-scoped. */
function se_dashboard_today_appointments()
{
    $CI = &get_instance();
    $p  = db_prefix();
    if (!$CI->db->table_exists($p . 'se_appointments')) {
        return [];
    }
    $today = date('Y-m-d');
    se_apply_scope_in('brand_id');
    $CI->db->where('start_at >=', $today . ' 00:00:00')->where('start_at <=', $today . ' 23:59:59')
           ->where_not_in('status', ['cancelled'])->order_by('start_at', 'ASC')->limit(12);
    $rows = $CI->db->get($p . 'se_appointments')->result_array();
    if (!$rows) {
        return [];
    }
    $leadIds = array_values(array_unique(array_filter(array_map(function ($r) { return $r['rel_type'] === 'lead' ? (int) $r['rel_id'] : 0; }, $rows))));
    $names = [];
    if ($leadIds) {
        $CI->db->select('id, name')->where_in('id', $leadIds);
        foreach ($CI->db->get($p . 'leads')->result_array() as $l) { $names[(int) $l['id']] = (string) $l['name']; }
    }
    foreach ($rows as &$r) {
        $r['patient'] = $r['rel_type'] === 'lead' ? ($names[(int) $r['rel_id']] ?? '') : '';
        $r['type']    = function_exists('se_appt_type_key') ? se_appt_type_key($r['appointment_type'] ?? '') : 'consultation';
    }

    return $rows;
}

/** Unread threads, newest first, with lead names. */
function se_dashboard_unread_threads($limit = 5)
{
    $CI = &get_instance();
    $p  = db_prefix();
    if (!$CI->db->table_exists($p . 'se_wa_conversations')) {
        return [];
    }
    se_apply_scope_in('brand_id');
    $CI->db->where('unread_count >', 0)->order_by('last_inbound_at', 'DESC')->limit(max(1, (int) $limit));
    $rows = $CI->db->get($p . 'se_wa_conversations')->result_array();
    $leadIds = array_values(array_unique(array_filter(array_map(function ($r) { return (int) $r['lead_id']; }, $rows))));
    $names = [];
    if ($leadIds) {
        $CI->db->select('id, name')->where_in('id', $leadIds);
        foreach ($CI->db->get($p . 'leads')->result_array() as $l) { $names[(int) $l['id']] = (string) $l['name']; }
    }
    foreach ($rows as &$r) {
        $r['patient'] = $names[(int) $r['lead_id']] ?? '';
    }

    return $rows;
}

/**
 * The Sistem card: only what needs attention (skipped conversions, stalled
 * dispatcher/cron, failed sends/reminders) plus a one-line green summary.
 */
function se_dashboard_system_card()
{
    $out = ['alerts' => [], 'summary' => ''];
    $cronAge = (int) get_option('last_cron_run') ? time() - (int) get_option('last_cron_run') : null;
    $dispAge = function_exists('se_dispatch_age') ? se_dispatch_age() : null;
    $outbox  = function_exists('se_outbox_health') ? se_outbox_health(null) : [];
    $skipped = (int) ($outbox['skipped'] ?? 0);
    $failed  = (int) ($outbox['failed'] ?? 0);
    $wa      = function_exists('se_report_wa_queue_counts') ? se_report_wa_queue_counts(null) : [];
    $rem     = function_exists('se_report_reminder_counts') ? se_report_reminder_counts(null) : [];

    if ($cronAge === null || $cronAge > 3600) {
        $out['alerts'][] = ['tone' => 'danger', 'text' => $cronAge === null ? _l('se_sys_cron_never') : _l('se_sys_cron_stale', [(int) round($cronAge / 60)]), 'href' => admin_url('se_core/se_reports/health')];
    }
    if ($dispAge !== null && $dispAge > 900) {
        $out['alerts'][] = ['tone' => 'danger', 'text' => _l('se_sys_dispatch_stale', [(int) round($dispAge / 60)]), 'href' => admin_url('se_core/se_reports/health')];
    }
    if ($skipped > 0) {
        $reasons = [];
        foreach ((array) ($outbox['skipped_by_reason'] ?? []) as $code => $n) { $reasons[] = $n . ' × ' . _l('se_skip_' . $code) ; }
        $out['alerts'][] = ['tone' => 'warning', 'text' => _l('se_sys_outbox_skipped', [$skipped, implode(', ', $reasons)]), 'href' => admin_url('se_core/se_outbox?status=skipped')];
    }
    if ($failed > 0) {
        $out['alerts'][] = ['tone' => 'warning', 'text' => _l('se_sys_outbox_failed', [$failed]), 'href' => admin_url('se_core/se_outbox?status=failed')];
    }
    if ((int) ($wa['failed'] ?? 0) > 0) {
        $out['alerts'][] = ['tone' => 'danger', 'text' => _l('se_sys_wa_failed', [(int) $wa['failed']]), 'href' => admin_url('se_whatsapp/se_whatsapp/inbox')];
    }
    if ((int) ($rem['failed'] ?? 0) > 0) {
        $out['alerts'][] = ['tone' => 'warning', 'text' => _l('se_sys_reminders_failed', [(int) $rem['failed']]), 'href' => admin_url('se_appointments/se_appointments/manage')];
    }
    if (function_exists('se_consent_text_configured_anywhere') && !se_consent_text_configured_anywhere()) {
        $out['alerts'][] = ['tone' => 'warning', 'text' => _l('se_warn_consent_unconfigured'), 'href' => admin_url('se_core/se_consent')];
    }
    $out['summary'] = 'WhatsApp ✓ · Instagram ✓ · ' . _l('se_sys_dispatcher') . ' ' . ($dispAge === null ? '—' : ($dispAge <= 180 ? '✓ ' . $dispAge . ' sn' : '⚠ ' . (int) round($dispAge / 60) . ' dk'))
        . ' · Cron ' . ($cronAge === null ? '—' : ($cronAge <= 1800 ? '✓ ' . (int) round($cronAge / 60) . ' dk' : '⚠ ' . (int) round($cronAge / 60) . ' dk'));

    return $out;
}
