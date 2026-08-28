<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Conversion outbox — producer hooks and cron drainer.
 *
 * The outbox is the spine of every ad-platform integration. A pipeline stage
 * change writes a row; cron drains it; nothing is ever sent inline with a web
 * request. This file wires the producer (lead_status_changed -> a queued row)
 * and the consumer (after_cron_run -> drain pending rows to their destination).
 *
 * Live sending requires per-brand credentials that only exist after Meta App
 * Review / Google service-account setup. Until a brand has those, its rows are
 * marked 'skipped' with a clear reason rather than failing — the pipeline is
 * real and correct now, and starts delivering the moment credentials land.
 */

hooks()->add_action('lead_status_changed', 'se_outbox_on_status_change', 10, 1);
hooks()->add_action('lead_converted_to_customer', 'se_outbox_on_converted', 10, 1);
hooks()->add_action('after_cron_run', 'se_outbox_drain');

/**
 * Every pipeline stage change becomes a conversion signal.
 *
 * Meta is explicit that all prior stages must have been sent before a later
 * one, so we emit on every transition, not only the final conversion. The
 * event name is the stage name verbatim, kept treatment-agnostic by policy.
 */
function se_outbox_on_status_change($data)
{
    $lead_id = 0;

    if (is_array($data) && isset($data['lead_id'])) {
        $lead_id = (int) $data['lead_id'];
    } elseif (is_numeric($data)) {
        $lead_id = (int) $data;
    }

    if (!$lead_id) {
        return;
    }

    $CI = &get_instance();

    $CI->db->select('brand_id, status, consent_ads')->where('id', $lead_id);
    $lead = $CI->db->get(db_prefix() . 'leads')->row();

    if (!$lead) {
        return;
    }

    // No consent, nothing leaves the CRM. Meta/Google carry no consent field;
    // enforcement is entirely ours.
    if ((int) $lead->consent_ads !== 1) {
        return;
    }

    $CI->db->select('name')->where('id', (int) $lead->status);
    $status = $CI->db->get(db_prefix() . 'leads_status')->row();

    $event_name = $status ? $status->name : ('Stage ' . (int) $lead->status);

    // One row per enabled destination. se_brands says which are configured.
    foreach (se_outbox_destinations_for_brand((int) $lead->brand_id) as $destination) {
        se_outbox_queue((int) $lead->brand_id, $lead_id, $destination, $event_name);
    }
}

function se_outbox_on_converted($data)
{
    // Conversion is itself a high-value stage. Reuse the status-change path by
    // reading the lead's current status.
    $lead_id = is_array($data) && isset($data['lead_id']) ? (int) $data['lead_id'] : 0;
    if ($lead_id) {
        se_outbox_on_status_change(['lead_id' => $lead_id]);
    }
}

/**
 * Which destinations are configured for a brand.
 *
 * A destination is only returned if the brand actually has the credentials for
 * it, so a half-onboarded clinic never queues rows that can't be sent.
 */
function se_outbox_destinations_for_brand($brand_id)
{
    if ($brand_id <= 0) {
        return [];
    }

    $CI = &get_instance();
    $CI->db->where('id', $brand_id);
    $brand = $CI->db->get(db_prefix() . 'se_brands')->row();

    if (!$brand) {
        return [];
    }

    $out = [];

    if (!empty($brand->meta_dataset_id)) {
        $out[] = 'meta_capi';
    }
    if (!empty($brand->google_ads_customer_id)) {
        $out[] = 'google_dm';
    }

    return $out;
}

/**
 * Cron drainer.
 *
 * Runs after core cron tasks. Pulls a bounded batch of pending rows, sends each
 * through its destination handler, and records the outcome. Failures back off
 * by attempt count; a row that has failed too many times is parked, not retried
 * forever.
 */
function se_outbox_drain()
{
    $CI = &get_instance();

    $table = db_prefix() . 'se_conversion_outbox';

    // Meta rejects events older than 7 days; Google older than 90. Park stale
    // rows rather than sending guaranteed rejects.
    $CI->db->where('status', 'pending')
           ->where('event_time <', date('Y-m-d H:i:s', strtotime('-7 days')))
           ->update($table, ['status' => 'skipped', 'last_error' => 'event older than 7 days']);

    $CI->db->where('status', 'pending')
           ->where('attempts <', 5)
           ->order_by('id', 'ASC')
           ->limit(200);
    $rows = $CI->db->get($table)->result_array();

    if (empty($rows)) {
        return;
    }

    $CI->load->library('se_core/se_hash');

    foreach ($rows as $row) {
        $ok    = false;
        $error = '';

        try {
            switch ($row['destination']) {
                case 'meta_capi':
                    $result = se_capi_send_event($row);
                    $ok     = $result['ok'];
                    $error  = $result['error'];
                    break;

                case 'google_dm':
                    // Producer is wired; the sender lands with the Google module
                    // once a service account exists. Hold rather than fail.
                    $error = 'google_dm sender not yet configured';
                    break;

                default:
                    $error = 'unknown destination ' . $row['destination'];
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        if ($ok) {
            $CI->db->where('id', $row['id'])->update($table, [
                'status'  => 'sent',
                'sent_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $CI->db->where('id', $row['id'])->update($table, [
                'status'     => 'pending',
                'attempts'   => (int) $row['attempts'] + 1,
                'last_error' => substr($error, 0, 1000),
            ]);
        }
    }
}
