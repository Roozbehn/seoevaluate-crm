<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Shared agency lead pipeline.
 *
 * The stage names double as the conversion event vocabulary (se_outbox emits the
 * stage name verbatim), so they are generic and treatment-agnostic by policy —
 * never a clinical term. Seeding is idempotent: stages are matched by name, so
 * re-running never duplicates a stage and never disturbs Perfex's reserved
 * "Customer" status or any real lead already sitting on a stage.
 */

/** Ordered pipeline stages. Index drives statusorder; names are the event names. */
function se_pipeline_stages()
{
    return [
        'New',
        'Contacted',
        'WhatsApp Engaged',
        'Qualified',
        'Photos Received',
        'Quote Sent',
        'Consultation Booked',
        'Consultation Held',
        'Deposit Paid',
        'Travel Booked',
        'Treated',
        'Follow-up',
        'Reviewed',
    ];
}

/**
 * Idempotently ensure every pipeline stage exists in tblleads_status.
 * Matches by name; assigns a deterministic statusorder (10, 20, …) while leaving
 * the reserved "Customer" status (order 1000) untouched. Returns stages created.
 */
function se_pipeline_seed()
{
    $CI = &get_instance();
    $table = db_prefix() . 'leads_status';

    $created = 0;
    $order   = 10;

    foreach (se_pipeline_stages() as $name) {
        $CI->db->where('name', $name);
        $exists = $CI->db->count_all_results($table);

        if ($exists === 0) {
            $CI->db->insert($table, [
                'name'        => $name,
                'statusorder' => $order,
                'color'       => '#4c84ff',
                'isdefault'   => 0,
            ]);
            $created++;
        }

        $order += 10;
    }

    return $created;
}

/**
 * A lead marked lost or junk must not generate further conversion signals — a
 * disqualified lead is not a conversion. Reads Perfex's own lost/junk flags,
 * guarded so it is safe if a column is absent on an older schema.
 */
function se_lead_blocks_conversion($lead)
{
    if (!$lead) {
        return true;
    }

    if (isset($lead->lost) && (int) $lead->lost === 1) {
        return true;
    }
    if (isset($lead->junk) && (int) $lead->junk === 1) {
        return true;
    }

    return false;
}
