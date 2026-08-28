<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Conversion outbox — producer hooks and concurrent-safe cron drainer.
 *
 * A pipeline stage change writes a row; cron drains it; nothing is sent inline
 * with a web request. The drainer is safe under overlapping cron workers: rows
 * are claimed atomically with a processing lease, so an event is delivered at
 * most once even if two crons run at the same time. A worker that dies mid-batch
 * leaves a stale lease that the next run recovers.
 *
 * Live sending needs per-brand credentials that only exist after Meta App Review
 * / Google service-account setup. Until then rows are held (pending) or parked
 * (skipped/failed) with a clear, non-sensitive reason — never silently lost.
 */

define('SE_OUTBOX_MAX_ATTEMPTS', 5);
define('SE_OUTBOX_LEASE_SECONDS', 900);   // 15 min: a lease older than this is stale
define('SE_OUTBOX_BATCH', 200);
define('SE_META_MAX_AGE_DAYS', 7);        // Meta rejects events older than 7 days
define('SE_GOOGLE_MIN_AGE_SECONDS', 21600); // Google needs >= 6h after the click
define('SE_GOOGLE_MAX_AGE_DAYS', 90);       // and <= 90 days

hooks()->add_action('lead_status_changed', 'se_outbox_on_status_change', 10, 1);
hooks()->add_action('lead_converted_to_customer', 'se_outbox_on_converted', 10, 1);
hooks()->add_action('after_cron_run', 'se_outbox_drain');

/**
 * Every eligible pipeline stage change becomes a conversion signal. Meta wants
 * all prior stages sent before a later one, so we emit on every transition. A
 * lead that is lost or junk, or lacks ad consent, produces nothing.
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
    $CI->db->where('id', $lead_id);
    $lead = $CI->db->get(db_prefix() . 'leads')->row();

    if (!$lead) {
        return;
    }

    // Lost/junk leads never generate further conversions.
    if (function_exists('se_lead_blocks_conversion') && se_lead_blocks_conversion($lead)) {
        return;
    }

    // No ad consent, nothing leaves the CRM.
    if ((int) $lead->consent_ads !== 1) {
        return;
    }

    $CI->db->select('name')->where('id', (int) $lead->status);
    $status = $CI->db->get(db_prefix() . 'leads_status')->row();
    $event_name = $status ? $status->name : ('Stage ' . (int) $lead->status);

    foreach (se_outbox_destinations_for_brand((int) $lead->brand_id) as $destination) {
        se_outbox_queue((int) $lead->brand_id, $lead_id, $destination, $event_name);
    }
}

function se_outbox_on_converted($data)
{
    $lead_id = is_array($data) && isset($data['lead_id']) ? (int) $data['lead_id'] : 0;
    if ($lead_id) {
        se_outbox_on_status_change(['lead_id' => $lead_id]);
    }
}

/** Which destinations a brand actually has credentials for. */
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

/** A per-run worker identity. Not security-sensitive; just needs to be unique. */
function se_outbox_worker_id()
{
    return substr(md5(uniqid((string) getmypid(), true)), 0, 24);
}

/**
 * Return a stuck 'processing' row to 'pending' once its lease has expired, so a
 * worker that crashed mid-batch never strands its claim.
 */
function se_outbox_recover_stale()
{
    $CI = &get_instance();
    $cutoff = date('Y-m-d H:i:s', time() - SE_OUTBOX_LEASE_SECONDS);

    $CI->db->where('status', 'processing')
           ->where('locked_at <', $cutoff)
           ->update(db_prefix() . 'se_conversion_outbox', [
               'status'    => 'pending',
               'locked_at' => null,
               'locked_by' => null,
           ]);
}

/** Park pending rows already too old for Meta rather than sending sure rejects. */
function se_outbox_park_stale_pending()
{
    $CI = &get_instance();
    $CI->db->where('status', 'pending')
           ->where('destination', 'meta_capi')
           ->where('event_time <', date('Y-m-d H:i:s', strtotime('-' . SE_META_MAX_AGE_DAYS . ' days')))
           ->update(db_prefix() . 'se_conversion_outbox', [
               'status'     => 'skipped',
               'last_error' => 'event older than ' . SE_META_MAX_AGE_DAYS . ' days',
           ]);
}

/**
 * Atomically claim up to $limit pending rows for this worker. The UPDATE takes
 * InnoDB row locks, so a concurrent worker's identical UPDATE only sees rows
 * still 'pending' after this one commits — claims are disjoint. Returns the
 * claimed rows.
 */
function se_outbox_claim_batch($worker, $limit = SE_OUTBOX_BATCH)
{
    $CI = &get_instance();
    $table = db_prefix() . 'se_conversion_outbox';
    $limit = max(1, (int) $limit);

    $CI->db->query(
        'UPDATE `' . $table . "` SET status='processing', locked_at=" . 'NOW()' . ', locked_by=' . $CI->db->escape($worker)
        . " WHERE status='pending' AND attempts < " . (int) SE_OUTBOX_MAX_ATTEMPTS
        . ' ORDER BY id ASC LIMIT ' . $limit
    );

    $CI->db->where('status', 'processing')
           ->where('locked_by', $worker)
           ->order_by('id', 'ASC');

    return $CI->db->get($table)->result_array();
}

/** Cron drainer entrypoint. */
function se_outbox_drain()
{
    se_outbox_recover_stale();
    se_outbox_park_stale_pending();

    $CI = &get_instance();
    $CI->load->library('se_core/se_hash');

    $worker  = se_outbox_worker_id();
    $claimed = se_outbox_claim_batch($worker, SE_OUTBOX_BATCH);

    foreach ($claimed as $row) {
        se_outbox_process_row($row);
    }
}

/** Send one claimed row, then record the outcome and release the lease. */
function se_outbox_process_row($row)
{
    $CI = &get_instance();
    $table = db_prefix() . 'se_conversion_outbox';

    $ok = false;
    $error = '';
    $permanent = false;

    try {
        switch ($row['destination']) {
            case 'meta_capi':
                $result = se_capi_send_event($row);
                $ok     = (bool) $result['ok'];
                $error  = (string) $result['error'];
                break;

            case 'google_dm':
                $age = time() - strtotime($row['event_time']);
                if ($age < SE_GOOGLE_MIN_AGE_SECONDS) {
                    $error = 'google click younger than 6h; holding';
                } elseif ($age > SE_GOOGLE_MAX_AGE_DAYS * 86400) {
                    $permanent = true;
                    $error = 'google click older than 90 days';
                } elseif (function_exists('se_google_dm_send_event')) {
                    $result = se_google_dm_send_event($row);
                    $ok     = (bool) $result['ok'];
                    $error  = (string) $result['error'];
                } else {
                    $error = 'google_dm sender not configured';
                }
                break;

            default:
                $permanent = true;
                $error = 'unknown destination ' . $row['destination'];
        }
    } catch (Exception $e) {
        $error = 'exception during send';   // never leak internals into the row
    }

    if ($ok) {
        $CI->db->where('id', $row['id'])->update($table, [
            'status'    => 'sent',
            'sent_at'   => date('Y-m-d H:i:s'),
            'locked_at' => null,
            'locked_by' => null,
        ]);

        return;
    }

    $attempts = (int) $row['attempts'] + 1;
    $status = ($permanent || $attempts >= SE_OUTBOX_MAX_ATTEMPTS) ? 'failed' : 'pending';

    $CI->db->where('id', $row['id'])->update($table, [
        'status'     => $status,
        'attempts'   => $attempts,
        'last_error' => mb_substr($error, 0, 500),   // redacted, no payload/token
        'locked_at'  => null,
        'locked_by'  => null,
    ]);
}

/**
 * Integration-health counters for the outbox, optionally per brand.
 * Returns [status => count]. Used by the health interface, not by sending.
 */
function se_outbox_health($brand_id = null)
{
    $CI = &get_instance();
    if ($brand_id !== null) {
        $CI->db->where('brand_id', (int) $brand_id);
    }
    $CI->db->select('status, COUNT(*) as c')->group_by('status');
    $rows = $CI->db->get(db_prefix() . 'se_conversion_outbox')->result_array();

    $out = [];
    foreach ($rows as $r) {
        $out[$r['status']] = (int) $r['c'];
    }

    return $out;
}
