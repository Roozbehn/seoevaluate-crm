<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Immutable event snapshot for the conversion outbox.
 *
 * THE DEFECT THIS FIXES
 * ---------------------
 * Both senders used to do `SELECT * FROM tblleads WHERE id = ?` at DRAIN time
 * and build the conversion from whatever the lead looked like then. A row can
 * sit pending for minutes, or for weeks while a destination is gated, so:
 *
 *   - a consent withdrawal after queueing still transmitted the event, and
 *     Google stamped CONSENT_GRANTED/DENIED from the CURRENT flag rather than
 *     the one that applied when the conversion happened;
 *   - consent_text_version was never captured at all;
 *   - an edited or erased email/phone silently changed the identifiers
 *     attached to a historical conversion.
 *
 * The outbox already had a `payload` column and se_outbox_queue() already
 * accepted a payload argument, but every caller passed `[]` and no sender ever
 * read it, so the mechanism was scaffolding that never ran.
 *
 * WHAT IS CAPTURED, AND WHY EACH FIELD
 * ------------------------------------
 *   identity      brand/lead/event/transition, so the row is self-describing.
 *   attribution   FIRST-TOUCH click ids only. Last-touch deliberately excluded:
 *                 a conversion belongs to the click that started the journey,
 *                 and reading the mutable last_* columns at send time is
 *                 exactly the bleed we are preventing.
 *   identifiers   already SHA-256 hashed. Raw email/phone are never stored in
 *                 the snapshot - the row would then be a second copy of
 *                 personal data with its own retention problem. ctwa_clid/fbc/
 *                 fbp are stored raw because the platforms require them raw and
 *                 they are click ids, not contact details.
 *   consent       the state, version, source, timestamp and LEDGER ROW ID that
 *                 applied at event_time, resolved from the append-only ledger.
 *
 * MIGRATION SAFETY
 * ----------------
 * payload_version 0 means "queued before this existed". Those rows keep the old
 * live-lead behaviour, so no backfill and no rewrite of existing rows is needed.
 */

define('SE_OUTBOX_PAYLOAD_VERSION', 1);

/**
 * Build the snapshot for one conversion at queue time.
 *
 * Pure apart from the two reads it needs (the lead row and the consent ledger),
 * and it never writes.
 *
 * @return array{attribution:array,consent:array}
 */
function se_outbox_build_snapshot($brand_id, $lead_id, $event_name, $event_time)
{
    $CI = &get_instance();

    /* The lead must EXIST and must belong to the supplied brand.
     *
     * The snapshot previously loaded the lead by id alone, so a caller that
     * passed a mismatched brand (a mis-routed webhook, a bug in a producer)
     * built a conversion attributed to one tenant out of another tenant's
     * lead, and nothing downstream could tell. Returning null makes the
     * producer refuse to queue rather than queue something wrong. */
    $CI->db->where('id', (int) $lead_id)->where('brand_id', (int) $brand_id);
    $lead = $CI->db->get(db_prefix() . 'leads')->row();

    if (!$lead) {
        return null;
    }

    $attribution = [
        'v'            => SE_OUTBOX_PAYLOAD_VERSION,
        'brand_id'     => (int) $brand_id,
        'lead_id'      => (int) $lead_id,
        'event_name'   => (string) $event_name,
        'event_time'   => (string) $event_time,
        'captured_at'  => date('Y-m-d H:i:s'),
        'first_touch'  => [],
        'identifiers'  => [],
        'destination'  => [],
    ];

    {
        // First-touch click ids and campaign context. Immutable by design.
        foreach (['gclid', 'gbraid', 'wbraid', 'fbclid', 'fbc', 'fbp', 'ctwa_clid',
                  'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                  'landing_url', 'referrer', 'first_touch_at'] as $field) {
            if (isset($lead->$field) && $lead->$field !== null && $lead->$field !== '') {
                $attribution['first_touch'][$field] = (string) $lead->$field;
            }
        }

        // Destination-side keys.
        if (!empty($lead->meta_lead_id)) {
            $attribution['destination']['meta_lead_id'] = (string) $lead->meta_lead_id;
        }

        // Hashed identifiers only. Never raw contact details.
        if (class_exists('Se_hash')) {
            if (!empty($lead->email) && ($em = Se_hash::email($lead->email))) {
                $attribution['identifiers']['em'] = Se_hash::sha256($em);
            }
            if (!empty($lead->phonenumber) && ($ph = Se_hash::phone($lead->phonenumber))) {
                $attribution['identifiers']['ph'] = Se_hash::sha256($ph);
            }
        }
    }

    // Consent exactly as it stood when the conversion occurred.
    $consent = function_exists('se_consent_state_at')
        ? se_consent_state_at((int) $brand_id, 'lead', (int) $lead_id, 'ads', $event_time)
        : ['state' => 'unknown', 'version' => null, 'source' => null, 'at' => null, 'ledger_id' => 0];

    $consent['v'] = SE_OUTBOX_PAYLOAD_VERSION;

    return ['attribution' => $attribution, 'consent' => $consent];
}

/** Decode a stored snapshot column. Returns [] when absent or malformed. */
function se_outbox_snapshot_decode($json)
{
    if (!is_string($json) || $json === '') {
        return [];
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

/** Does this row carry a usable snapshot? */
function se_outbox_row_has_snapshot($row)
{
    return (int) ($row['payload_version'] ?? 0) >= 1
        && se_outbox_snapshot_decode($row['attribution_snapshot'] ?? '') !== [];
}

/**
 * The consent decision recorded for a row.
 *
 * FAILS CLOSED on a pre-snapshot row. There is no live-lead fallback any more:
 * rebuilding a historical conversion from the lead's CURRENT flag is exactly
 * the defect the snapshot exists to remove, and keeping it "just for old rows"
 * kept the defect alive for every row queued before the migration. A
 * version-0 row is now unsendable and says so.
 *
 * @return array{state:string,version:?string,ledger_id:int,source:string}
 */
function se_outbox_row_consent($row, $lead = null)
{
    if (se_outbox_row_has_snapshot($row)) {
        $c = se_outbox_snapshot_decode($row['consent_snapshot'] ?? '');

        return [
            'state'     => $c['state'] ?? 'unknown',
            'version'   => $c['version'] ?? null,
            'ledger_id' => (int) ($c['ledger_id'] ?? 0),
            'source'    => 'snapshot',
        ];
    }

    return [
        'state'     => 'unknown',
        'version'   => null,
        'ledger_id' => 0,
        'source'    => 'no_snapshot',
    ];
}

/**
 * Final consent gate before transmission.
 *
 * Two questions, both of which must pass:
 *   1. Was consent granted WHEN the conversion happened?  (the snapshot)
 *   2. Has it been withdrawn SINCE?                       (the live ledger)
 *
 * (1) alone would keep sending after a withdrawal. (2) alone is the original
 * defect. The documented policy is that a withdrawal stops anything not yet
 * transmitted, so both are checked here, at the last possible moment.
 *
 * @return array{ok:bool,reason:string}
 */
function se_outbox_consent_allows_send($row, $lead = null)
{
    // A row with no snapshot cannot be sent at all: we cannot prove what the
    // consent, attribution or identifiers were when the event happened.
    if (!se_outbox_row_has_snapshot($row)) {
        return ['ok' => false, 'reason' => 'no event snapshot; cannot verify consent at event time'];
    }

    $snapshot = se_outbox_row_consent($row, $lead);

    if ($snapshot['state'] !== 'granted') {
        return ['ok' => false, 'reason' => 'no ad consent at event time'];
    }

    if (function_exists('se_consent_granted')) {
        $stillGranted = se_consent_granted((int) $row['brand_id'], 'lead', (int) $row['lead_id'], 'ads');

        /* Authoritative recheck immediately before transport. Unconditional:
         * the snapshot proves consent existed at event time, the ledger proves
         * it still does. A withdrawal between the two must stop the send. */
        if (!$stillGranted) {
            return ['ok' => false, 'reason' => 'consent withdrawn before transmission'];
        }
    }

    return ['ok' => true, 'reason' => ''];
}
