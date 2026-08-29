<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Brand-scoped reporting + integration health.
 *
 * Two data sources, cleanly separated:
 *   - INTERNAL metrics are computed live from CRM tables (leads, appointments,
 *     outbox, WhatsApp) — cheap aggregate queries, always current.
 *   - EXTERNAL metrics (GA4, Search Console, Google Ads spend) are IMPORTED
 *     asynchronously by cron into tblse_ext_metrics and only READ at render time.
 *
 * No function here makes an external HTTP request during dashboard rendering.
 * Imports are bounded and gated on credentials (a registered importer drives
 * tests; a real client lands once credentials exist).
 */

hooks()->add_action('after_cron_run', 'se_report_import_all');

/* ----------------------------- internal metrics ------------------------- */

function se_report_range_where($CI, $col, $from, $to)
{
    if ($from) { $CI->db->where($col . ' >=', $from . ' 00:00:00'); }
    if ($to)   { $CI->db->where($col . ' <=', $to . ' 23:59:59'); }
}

/** Lead totals + lost/junk + converted, brand-scoped. */
function se_report_totals($brand_id, $from = null, $to = null)
{
    $CI = &get_instance();
    $t = db_prefix() . 'leads';

    $count = function ($extra) use ($CI, $t, $brand_id, $from, $to) {
        $CI->db->where('brand_id', (int) $brand_id);
        se_report_range_where($CI, 'dateadded', $from, $to);
        if ($extra) { $extra($CI); }
        return $CI->db->count_all_results($t);
    };

    $total     = $count(null);
    $lost      = $count(function ($db) { $db->db->where('lost', 1); });
    $junk      = $count(function ($db) { $db->db->where('junk', 1); });
    $converted = $count(function ($db) { $db->db->where('date_converted IS NOT NULL', null, false); });

    return [
        'leads'        => $total,
        'lost'         => $lost,
        'junk'         => $junk,
        'converted'    => $converted,
        'lost_rate'    => $total ? round($lost / $total, 4) : 0,
        'junk_rate'    => $total ? round($junk / $total, 4) : 0,
        'conv_rate'    => $total ? round($converted / $total, 4) : 0,
    ];
}

/** Leads by current pipeline stage (name => count), brand-scoped. */
function se_report_by_stage($brand_id, $from = null, $to = null)
{
    $CI = &get_instance();
    $l = db_prefix() . 'leads';
    $s = db_prefix() . 'leads_status';

    $CI->db->select($s . '.name as stage, COUNT(' . $l . '.id) as c')
           ->from($l)
           ->join($s, $s . '.id = ' . $l . '.status', 'left')
           ->where($l . '.brand_id', (int) $brand_id);
    se_report_range_where($CI, $l . '.dateadded', $from, $to);
    $CI->db->group_by($s . '.name');
    $rows = $CI->db->get()->result_array();

    $out = [];
    foreach ($rows as $r) { $out[$r['stage'] ?: 'Unstaged'] = (int) $r['c']; }
    return $out;
}

/** Conversion by acquisition source (utm_source), brand-scoped. */
function se_report_by_source($brand_id, $from = null, $to = null)
{
    $CI = &get_instance();
    $l = db_prefix() . 'leads';

    $expr = "COALESCE(NULLIF(utm_source,''),'direct')";
    $CI->db->select($expr . " as src_label, COUNT(*) as leads, "
                  . "SUM(CASE WHEN date_converted IS NOT NULL THEN 1 ELSE 0 END) as converted")
           ->from($l)
           ->where('brand_id', (int) $brand_id);
    se_report_range_where($CI, 'dateadded', $from, $to);
    $CI->db->group_by($expr, false);
    $rows = $CI->db->get()->result_array();

    $out = [];
    foreach ($rows as $r) {
        $leads = (int) $r['leads']; $conv = (int) $r['converted'];
        $out[$r['src_label']] = ['leads' => $leads, 'converted' => $conv, 'conv_rate' => $leads ? round($conv / $leads, 4) : 0];
    }
    return $out;
}

/** Appointment counts by status + no-show rate, brand-scoped. */
function se_report_appointments($brand_id, $from = null, $to = null)
{
    $CI = &get_instance();
    $a = db_prefix() . 'se_appointments';

    $CI->db->select('status, COUNT(*) as c')->from($a)->where('brand_id', (int) $brand_id);
    se_report_range_where($CI, 'start_at', $from, $to);
    $CI->db->group_by('status');
    $rows = $CI->db->get()->result_array();

    $by = [];
    foreach ($rows as $r) { $by[$r['status']] = (int) $r['c']; }
    $held = ($by['held'] ?? 0) + ($by['completed'] ?? 0);
    $noshow = $by['no_show'] ?? 0;
    $seen = $held + $noshow;

    return [
        'by_status'   => $by,
        'booked'      => array_sum($by),
        'held'        => $held,
        'no_show'     => $noshow,
        'no_show_rate' => $seen ? round($noshow / $seen, 4) : 0,
    ];
}

/** WhatsApp volume + estimated billing, brand-scoped. */
function se_report_whatsapp($brand_id)
{
    $CI = &get_instance();
    $brand_id = (int) $brand_id;

    $msg = db_prefix() . 'se_wa_messages';
    $CI->db->where('brand_id', $brand_id)->where('direction', 'in');
    $in = $CI->db->count_all_results($msg);
    $CI->db->where('brand_id', $brand_id)->where('direction', 'out');
    $out = $CI->db->count_all_results($msg);

    $met = db_prefix() . 'se_wa_metering';
    $CI->db->select('category, SUM(quantity) as q, SUM(billable) as billable')
           ->from($met)->where('brand_id', $brand_id)->group_by('category');
    $rows = $CI->db->get()->result_array();

    $by_category = []; $est = 0.0;
    foreach ($rows as $r) {
        $q = (int) $r['q'];
        $by_category[$r['category']] = $q;
        if (function_exists('se_wa_rate')) { $est += $q * se_wa_rate($r['category']); }
    }

    return ['messages_in' => $in, 'messages_out' => $out, 'by_category' => $by_category, 'estimated_cost' => round($est, 4)];
}

/* --------------------------- external metrics (imported) ---------------- */

/** Store one imported external metric (upsert on brand+source+metric+date). */
function se_ext_metric_store($brand_id, $source, $metric, $value, $period_date)
{
    $CI = &get_instance();
    $t = db_prefix() . 'se_ext_metrics';
    $key = ['brand_id' => (int) $brand_id, 'source' => $source, 'metric' => $metric, 'period_date' => $period_date];

    $CI->db->where($key);
    $exists = $CI->db->count_all_results($t) > 0;
    if ($exists) {
        $CI->db->where($key)->update($t, ['value' => (float) $value, 'imported_at' => date('Y-m-d H:i:s')]);
    } else {
        $CI->db->insert($t, array_merge($key, ['value' => (float) $value, 'imported_at' => date('Y-m-d H:i:s')]));
    }
    return true;
}

/** Read stored external metrics for a brand/source (no external call). */
function se_ext_metric_sum($brand_id, $source, $metric, $from = null, $to = null)
{
    $CI = &get_instance();
    $CI->db->select('COALESCE(SUM(value),0) as v')->from(db_prefix() . 'se_ext_metrics')
           ->where('brand_id', (int) $brand_id)->where('source', $source)->where('metric', $metric);
    if ($from) { $CI->db->where('period_date >=', $from); }
    if ($to)   { $CI->db->where('period_date <=', $to); }
    $row = $CI->db->get()->row();
    return (float) ($row->v ?? 0);
}

/** Spend (imported) vs outcome (internal) — no external call at read time. */
function se_report_spend_vs_outcome($brand_id, $from = null, $to = null)
{
    $spend = se_ext_metric_sum($brand_id, 'google_ads', 'spend', $from, $to);
    $totals = se_report_totals($brand_id, $from, $to);
    $appts = se_report_appointments($brand_id, $from, $to);
    return [
        'spend'           => $spend,
        'leads'           => $totals['leads'],
        'treatments'      => $totals['converted'],
        'consultations_held' => $appts['held'],
        'cost_per_lead'   => $totals['leads'] ? round($spend / $totals['leads'], 2) : null,
        'cost_per_treatment' => $totals['converted'] ? round($spend / $totals['converted'], 2) : null,
        'gated'           => $spend == 0.0,   // no imported spend yet
    ];
}

/* --------------------------- async importers (gated) -------------------- */

$GLOBALS['SE_REPORT_IMPORTERS'] = [];

/** Register an importer for a source: callable(brand_id):array of metric rows. */
function se_report_register_importer($source, callable $fn)
{
    $GLOBALS['SE_REPORT_IMPORTERS'][$source] = $fn;
}

/** Run a source importer for a brand. Gated (no importer/creds) -> 0 imported. */
function se_report_import($source, $brand_id)
{
    $fn = $GLOBALS['SE_REPORT_IMPORTERS'][$source] ?? null;
    if (!is_callable($fn)) {
        return 0;   // externally gated: GA4/GSC/Ads client lands with credentials
    }
    $rows = call_user_func($fn, (int) $brand_id) ?: [];
    $n = 0;
    foreach ($rows as $r) {
        se_ext_metric_store($brand_id, $source, $r['metric'], $r['value'], $r['period_date']);
        $n++;
    }
    if ($n) { update_option('se_report_last_import_' . $source, date('Y-m-d H:i:s')); }
    return $n;
}

/** Cron: bounded async import across sources + brands. after_cron_run bool-safe. */
function se_report_import_all($manual = false)
{
    $CI = &get_instance();
    $brands = $CI->db->select('id')->get(db_prefix() . 'se_brands')->result_array();
    $total = 0;
    foreach (['ga4', 'search_console', 'google_ads'] as $source) {
        foreach ($brands as $b) {
            $total += se_report_import($source, (int) $b['id']);
        }
    }
    return $total;
}

/* --------------------------- integration health ------------------------- */

/** Age of the last cron run in seconds (null if never). */
function se_report_cron_age()
{
    $last = (int) get_option('last_cron_run');
    return $last ? (time() - $last) : null;
}

/* Cron cadence, made explicit so the UI can explain what "healthy" means rather
 * than showing a bare green dot against an unstated threshold. The system cron
 * fires every 15 minutes (900s); we warn once two intervals could have been
 * missed and fail after ~1h. */
define('SE_CRON_EXPECTED_INTERVAL_SECONDS', 900);
define('SE_CRON_WARN_SECONDS', 1800);
define('SE_CRON_FAIL_SECONDS', 3600);

/** Admin URL for a remediation target, or null outside an HTTP context. */
function se_health_link($path)
{
    return function_exists('admin_url') ? admin_url($path) : null;
}

/**
 * Aggregated integration-health snapshot for a brand (no external calls).
 *
 * PER-PROVIDER and TRUTHFUL. Every leg reports independently: a pending Lead
 * Ads App Review never marks CAPI unhealthy, and a deliberately-disabled
 * optional Google property never marks the provider unhealthy. Each blocker
 * carries a precise reason, its scope, a next action, a remediation link and a
 * last-checked time.
 */
function se_integration_health($brand_id)
{
    $brand_id = (int) $brand_id;
    $now      = date('Y-m-d H:i:s');

    $meta   = function_exists('se_meta_health') ? se_meta_health($brand_id) : [];
    $google = function_exists('se_google_health') ? se_google_health($brand_id) : [];
    $outbox = function_exists('se_outbox_health') ? se_outbox_health($brand_id) : [];

    $CI = &get_instance();
    $CI->db->where('brand_id', $brand_id);
    $wa_numbers = $CI->db->get(db_prefix() . 'se_wa_numbers')->result_array();
    $quality = array_map(function ($n) {
        return [
            'number'  => $n['display_number'],
            'phone_number_id' => $n['phone_number_id'],
            'waba_id' => $n['waba_id'],
            'quality' => $n['quality_rating'],
            'tier'    => $n['messaging_tier'],
            'state'   => $n['state'],
        ];
    }, $wa_numbers);

    // WhatsApp is CONFIGURED once its identifiers exist (a seeded/discovered
    // row), AUTHENTICATED once the app secret is installed, and WEBHOOK-VERIFIED
    // once the verify token is installed. "no number configured" must mean the
    // identifiers really are absent, not merely that no message has arrived yet.
    $waIdentifiers = !empty($quality);
    $waAppSecret   = function_exists('se_wa_app_secret') ? se_wa_app_secret() !== '' : false;
    $waVerify      = function_exists('se_wa_verify_token') ? se_wa_verify_token() !== '' : false;
    $wa = [
        'identifiers_configured' => $waIdentifiers,
        'app_secret'             => $waAppSecret,
        'app_secret_inherited'   => function_exists('se_wa_app_secret_inherited') ? se_wa_app_secret_inherited() : false,
        'webhook_verified'       => $waVerify,
        'last_inbound_at'        => get_option('se_wa_last_inbound_at_' . $brand_id) ?: (get_option('se_wa_last_inbound_at') ?: null),
        'last_status_at'         => get_option('se_wa_last_status_at_' . $brand_id) ?: (get_option('se_wa_last_status_at') ?: null),
        'numbers'                => $quality,
    ];

    $freshness = [
        'ga4'            => get_option('se_report_last_import_ga4') ?: null,
        'search_console' => get_option('se_report_last_import_search_console') ?: null,
        'google_ads'     => get_option('se_report_last_import_google_ads') ?: null,
        'meta_webhook'   => get_option('se_meta_last_webhook_at') ?: null,
    ];

    // --- Precise, per-provider blockers (never one combined line) -----------
    $blockers = [];
    $blk = function ($key, $reason, $impact, $action, $link) use (&$blockers, $now) {
        $blockers[] = ['key' => $key, 'reason' => $reason, 'impact' => $impact,
                       'action' => $action, 'link' => $link, 'checked_at' => $now];
    };

    if (!empty($meta['dataset_conflict'])) {
        $blk('meta_capi_dataset_conflict',
             'Configured dataset id conflicts with the authoritative dataset for this brand',
             'CAPI transmission is BLOCKED — server events would go to the wrong dataset (misattributed conversions)',
             'Reconcile the brand dataset id to ' . $meta['dataset_conflict'] . ' on the Meta integration screen',
             se_health_link('se_core/se_meta'));
    }
    if (!empty($meta['capi_gated'])) {
        $blk('meta_capi', 'No Conversions API token installed for this brand',
             'Server-side conversions are not transmitted; browser Pixel is unaffected',
             'Install the meta_capi credential for this brand and set the dataset id',
             se_health_link('se_core/se_credentials'));
    }
    if (empty($meta['webhook_ready'])) {
        $blk('meta_leadgen_webhook', 'Meta app secret and/or webhook verify token not installed',
             'Lead Ads webhook cannot verify or validate signatures',
             'Install meta_app and meta_verify credentials',
             se_health_link('se_core/se_meta'));
    }
    if (!empty($meta['webhook_ready']) && !empty($meta['leadgen_gated'])) {
        $blk('meta_leadgen_retrieval', 'Lead Ads page token pending (App Review / token)',
             'Webhook receives events but lead field data cannot be fetched yet — events are held, not lost',
             'Complete Lead Ads advanced access and install the page/system-user token',
             se_health_link('se_core/se_meta'));
    }
    if (empty($meta['active_form_count'])) {
        $blk('meta_leadgen_mapping', 'No active Page/form mapping for this brand',
             'Incoming leadgen events cannot be routed to a brand',
             'Map at least one Page + Instant Form to this brand',
             se_health_link('se_core/se_meta'));
    }
    if (empty($wa['identifiers_configured'])) {
        $blk('whatsapp_identifiers', 'No WhatsApp number configured for this brand',
             'WhatsApp inbound/outbound is unavailable',
             'Configure the WABA and phone-number identifiers for this brand',
             se_health_link('se_whatsapp/se_whatsapp/inbox'));
    } elseif (!$wa['app_secret'] || !$wa['webhook_verified']) {
        $blk('whatsapp_auth', 'WhatsApp app secret and/or verify token not installed',
             'WhatsApp webhook cannot verify or validate signatures',
             'Install meta_app (shared) and wa_verify credentials',
             se_health_link('se_core/se_credentials'));
    }
    if (!empty($google['externally_gated'])) {
        $blk('google_dm', 'No Google service-account credential installed',
             'Offline conversion upload to Google is unavailable (optional; does not affect Meta)',
             'Install the google_sa credential and enable the integration for the brand',
             se_health_link('se_core/se_google'));
    }

    $cronAge = se_report_cron_age();
    $cronState = $cronAge === null ? 'unknown'
        : ($cronAge < SE_CRON_WARN_SECONDS ? 'healthy'
        : ($cronAge < SE_CRON_FAIL_SECONDS ? 'warning' : 'failed'));

    return [
        'brand_id'        => $brand_id,
        'checked_at'      => $now,
        'meta'            => $meta,
        'google'          => $google,
        'whatsapp'        => $wa,
        'outbox'          => [
            'pending' => (int) ($outbox['pending'] ?? 0),
            'failed'  => (int) ($outbox['failed'] ?? 0),
            'sent'    => (int) ($outbox['sent'] ?? 0),
            'dead'    => (int) ($outbox['dead'] ?? 0),
        ],
        'whatsapp_numbers' => $quality,   // back-compat
        'cron_age_seconds' => $cronAge,
        'cron_state'      => $cronState,
        'cron_healthy'    => $cronState === 'healthy',
        'cron_expected_interval_seconds' => SE_CRON_EXPECTED_INTERVAL_SECONDS,
        'cron_warn_seconds' => SE_CRON_WARN_SECONDS,
        'cron_fail_seconds' => SE_CRON_FAIL_SECONDS,
        'data_freshness'  => $freshness,
        'blockers'        => $blockers,
    ];
}
