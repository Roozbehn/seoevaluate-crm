<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Google Data Manager conversion sender — SCAFFOLD.
 *
 * Status: GATED on a Google Cloud service account + the clinic conversion
 * actions. Build against the Data Manager API (datamanager.googleapis.com/v1),
 * NOT the Google Ads API ConversionUploadService — since 15 Jun 2026 that path
 * rejects new developer tokens (CUSTOMER_NOT_ALLOWLISTED_FOR_THIS_FEATURE).
 *
 * The outbox drainer already routes 'google_dm' rows here in spirit; this stub
 * documents the exact call so the sender is a fill-in-the-blank once creds land.
 *
 * TODO(service account):
 *   POST https://datamanager.googleapis.com/v1/events:ingest
 *   {
 *     destinations:[{ loginAccount:{accountId:MCC,accountType:GOOGLE_ADS},
 *                     operatingAccount:{accountId:CLINIC,accountType:GOOGLE_ADS},
 *                     productDestinationId: CONVERSION_ACTION_ID }],
 *     encoding:"HEX",
 *     events:[{ transactionId, eventTimestamp(RFC3339),
 *               adIdentifiers:{gclid}, userData:{userIdentifiers:[{emailAddress:sha256},{phoneNumber:sha256}]},
 *               consent:{adUserData:"GRANTED",adPersonalization:"GRANTED"} }]
 *   }
 *   Auth: OAuth service account, scope auth/datamanager. No developer token.
 *   Window: >= 6h and <= 90d after the click. Use Se_hash for identifiers.
 *   POLICY: never attach a procedure to the conversion action, transactionId,
 *   or any field — Google prohibits health-tied conversion data.
 */

function se_google_dm_send_event($row)
{
    return ['ok' => false, 'error' => 'google_dm sender gated on service account (see se_google_dm.php)'];
}
