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

/** Aggregated integration-health snapshot for a brand (no external calls). */
function se_integration_health($brand_id)
{
    $brand_id = (int) $brand_id;
    $meta   = function_exists('se_meta_health') ? se_meta_health($brand_id) : [];
    $google = function_exists('se_google_health') ? se_google_health($brand_id) : [];
    $outbox = function_exists('se_outbox_health') ? se_outbox_health($brand_id) : [];

    $CI = &get_instance();
    $CI->db->where('brand_id', $brand_id);
    $wa_numbers = $CI->db->get(db_prefix() . 'se_wa_numbers')->result_array();
    $quality = array_map(function ($n) { return ['number' => $n['display_number'], 'quality' => $n['quality_rating'], 'tier' => $n['messaging_tier'], 'state' => $n['state']]; }, $wa_numbers);

    $freshness = [
        'ga4'            => get_option('se_report_last_import_ga4') ?: null,
        'search_console' => get_option('se_report_last_import_search_console') ?: null,
        'google_ads'     => get_option('se_report_last_import_google_ads') ?: null,
        'meta_webhook'   => get_option('se_meta_last_webhook_at') ?: null,
    ];

    $blockers = [];
    if (!empty($meta['externally_gated'])) { $blockers[] = 'meta_capi/leadgen: no token (App Review pending)'; }
    if (!empty($google['externally_gated'])) { $blockers[] = 'google_dm: no service account'; }
    if (empty($quality)) { $blockers[] = 'whatsapp: no number configured'; }

    $cronAge = se_report_cron_age();

    return [
        'brand_id'        => $brand_id,
        'meta'            => $meta,
        'google'          => $google,
        'outbox'          => ['pending' => (int) ($outbox['pending'] ?? 0), 'failed' => (int) ($outbox['failed'] ?? 0), 'sent' => (int) ($outbox['sent'] ?? 0)],
        'whatsapp_numbers' => $quality,
        'cron_age_seconds' => $cronAge,
        'cron_healthy'    => $cronAge !== null && $cronAge < 3600,
        'data_freshness'  => $freshness,
        'blockers'        => $blockers,
    ];
}
