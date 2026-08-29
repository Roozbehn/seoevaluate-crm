<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Purpose-specific Meta asset registry — the ONE authoritative definition of
 * each dataset, keyed by brand and purpose.
 *
 * WHY THIS EXISTS: the brand row (tblse_brands.meta_dataset_id) is the value
 * the CAPI send path transmits to, but a mutable DB column can silently drift
 * (it did: brand 22 was pointed at a WhatsApp MM dataset owned by another
 * business). A second mutable option would just be a second thing to drift.
 * These IDs are NON-SECRET and stable, so the single source of truth is this
 * versioned code table. The brand column is treated as an operational cache
 * that MUST equal the registry's web_capi id, or transmission is blocked.
 *
 * Purposes are kept strictly separate — the website Pixel/CAPI dataset is never
 * the same asset as the WhatsApp Marketing-Messages dataset.
 */

/** The authoritative dataset id per brand and purpose. Non-secret. */
function se_asset_registry()
{
    return [
        // Azin Asgari (brand 22).
        22 => [
            // "Azin Asgari | Web + CRM | Production", owner portfolio 1360984722912404.
            'web_capi' => '4515580372030489',
            // "WhatsApp Marketing Message Event Sharing" (Azin portfolio) — kept
            // dedicated to MM API, never used for web CAPI.
            'mm_api'   => '2081936999059007',
        ],
    ];
}

/** The authoritative dataset id for a purpose ('web_capi'|'mm_api'), or null. */
function se_asset_dataset($purpose, $brand_id)
{
    $reg = se_asset_registry();
    $brand_id = (int) $brand_id;
    return isset($reg[$brand_id][$purpose]) ? (string) $reg[$brand_id][$purpose] : null;
}

/**
 * Datasets that must NEVER be usable for web CAPI transmission. The superseded
 * value stays here as an audit record of a real misassignment, and the send
 * path refuses it outright regardless of any other configuration.
 */
function se_asset_superseded_web_capi()
{
    return [
        // WhatsApp MM dataset owned by business 1249274969453853 (SEO Evaluate) —
        // wrong owner AND wrong purpose; brand 22 was misassigned to it.
        '4266388243621345',
    ];
}

/** True when a dataset id is on the forbidden-for-web-CAPI list. */
function se_asset_is_forbidden_web_capi($dataset_id)
{
    return in_array(trim((string) $dataset_id), se_asset_superseded_web_capi(), true);
}
