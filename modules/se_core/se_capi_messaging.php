<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Meta Conversions API — BUSINESS MESSAGING events.
 *
 * WHY THIS IS A SEPARATE FILE FROM se_capi.php
 * se_capi.php sends CRM stage events with action_source 'system_generated' to
 * the brand's WEB dataset, and its header explains at length why those events
 * are never 'business_messaging'. That reasoning is correct and unchanged.
 * This file is the other half it names: the events that DO happen inside a
 * live messaging thread, and they are a different animal in every respect
 * that matters —
 *
 *   different action_source   'business_messaging'
 *   different dataset         the MM dataset, never the web one (see below)
 *   different identifiers     ctwa_clid + WABA id, or IGSID + IG account id;
 *                             an email hash is not even accepted here
 *   different event names     a CLOSED set that does not include 'Contact'
 *
 * Folding them into one sender would mean four branches through a function
 * whose whole job is to be predictable about what leaves the building.
 *
 * WHAT PROBLEM THIS SOLVES
 * The live campaign is an Instagram MESSAGES campaign: the ad's whole purpose
 * is to open a DM thread, and the visitor never touches the website. Every
 * conversion signal the system had ran through the website — so Meta was
 * optimising that campaign against nothing at all, and Events Manager's first
 * recommendation ("connect to chat activity from business messaging apps")
 * is the diagnostic for exactly that hole.
 *
 * THE DATASET SEPARATION IS LOAD-BEARING
 * Brand 22 was once pointed at a WhatsApp MM dataset for web CAPI, which is
 * the mirror image of the mistake available here. se_asset_registry.php keeps
 * the two purposes apart; this sender resolves 'mm_api' and refuses outright
 * if the id it gets back is the web dataset, is on the forbidden list, or is
 * missing. Sending messaging events into the web dataset would corrupt the
 * optimisation of both.
 *
 * GATED OFF BY DEFAULT
 * Nothing here transmits until the owner sets the per-brand enable option AND
 * a token is installed. That is not timidity: turning this on changes what a
 * live campaign optimises toward, and spend decisions are the owner's.
 */

/**
 * The event names Meta accepts on a business messaging event.
 *
 * Verified against Meta's Conversions API for Business Messaging reference
 * (2026-09-02), not from memory. This is a CLOSED set: 'Contact' — the name
 * the website leg uses for a WhatsApp click — is NOT in it, and sending it
 * anyway is a 400 at best and a silently-ignored event at worst.
 */
function se_capi_messaging_event_names()
{
    return [
        'Purchase', 'LeadSubmitted', 'InitiateCheckout', 'AddToCart',
        'ViewContent', 'OrderCreated', 'OrderShipped', 'OrderDelivered',
        'OrderCanceled', 'OrderReturned', 'CartAbandoned', 'QualifiedLead',
        'RatingProvided', 'ReviewProvided',
    ];
}

/**
 * Our internal signal -> Meta's messaging event name.
 *
 * Deliberately a lookup that can MISS. The website repo learned this the hard
 * way: a sender that falls back to forwarding the internal name under its own
 * spelling breaks deduplication and teaches the optimiser a name nobody
 * reports on. An unmapped signal is a permanent failure that names itself,
 * not a guess.
 */
function se_capi_messaging_name_map()
{
    return [
        // The thread opened from a click-to-message ad and the patient wrote.
        // 'LeadSubmitted', not 'Contact': Meta does not accept 'Contact' on a
        // messaging event, and this IS the lead for a messages campaign.
        'conversation_started'  => 'LeadSubmitted',
        // Staff confirmed a real enquiry — the signal worth optimising toward.
        'qualified'             => 'QualifiedLead',
        'consultation_booked'   => 'InitiateCheckout',
        'treatment_confirmed'   => 'Purchase',
    ];
}

/** The Meta name for an internal signal, or null when we have none. */
function se_capi_messaging_event_name($signal)
{
    $map = se_capi_messaging_name_map();
    $name = isset($map[$signal]) ? $map[$signal] : null;

    // Belt and braces: a future edit that adds a mapping to a name Meta does
    // not accept is caught here rather than at the platform.
    if ($name !== null && !in_array($name, se_capi_messaging_event_names(), true)) {
        return null;
    }

    return $name;
}

/** Channels Meta recognises on a business messaging event. */
function se_capi_messaging_channels()
{
    return ['whatsapp', 'instagram', 'messenger'];
}

/** Per-brand enable switch. Off unless the owner explicitly turns it on. */
function se_capi_messaging_enabled($brand_id)
{
    return (string) get_option('se_meta_mm_capi_enabled_' . (int) $brand_id) === '1';
}

/**
 * The MM dataset for a brand, or null with a reason.
 *
 * Returns an array so the caller can report WHY nothing was sent without
 * re-deriving it. The web dataset is rejected by identity, not by name: a
 * future registry edit that points both purposes at one id must fail here.
 */
function se_capi_messaging_dataset($brand_id)
{
    if (!function_exists('se_asset_dataset')) {
        return ['id' => null, 'code' => 'no_registry'];
    }

    $mm  = se_asset_dataset('mm_api', (int) $brand_id);
    $web = se_asset_dataset('web_capi', (int) $brand_id);

    if (empty($mm)) {
        return ['id' => null, 'code' => 'no_mm_dataset'];
    }

    if (!empty($web) && (string) $mm === (string) $web) {
        return ['id' => null, 'code' => 'dataset_collision'];
    }

    if (function_exists('se_asset_is_forbidden_web_capi')
        && se_asset_is_forbidden_web_capi($mm)) {
        /* The forbidden list exists because brand 22 was once pointed at a MM
         * dataset owned by a DIFFERENT business. Wrong owner is wrong for
         * messaging too — the list is about ownership, not only purpose. */
        return ['id' => null, 'code' => 'dataset_forbidden'];
    }

    return ['id' => (string) $mm, 'code' => 'ok'];
}

/**
 * The token for the MM dataset.
 *
 * Prefers a dedicated secret so the messaging permission can be revoked
 * without taking web CAPI down with it, and falls back to the web CAPI system
 * user, which in this deployment holds both dataset permissions. The fallback
 * is explicit rather than implicit so that "which credential authenticated"
 * is answerable after the fact.
 */
function se_capi_messaging_token($brand_id)
{
    if (!function_exists('se_secret_read')) {
        return '';
    }

    $dedicated = (string) se_secret_read('meta_mm_capi', (int) $brand_id);
    if ($dedicated !== '') {
        return $dedicated;
    }

    return function_exists('se_meta_capi_token')
        ? (string) se_meta_capi_token((int) $brand_id)
        : '';
}

/**
 * Builds the business messaging event.
 *
 * Pure: no DB, no network, no clock. Everything it needs is in $row (the
 * outbox row, whose `payload` carries the messaging context captured when the
 * conversation started) so it can be tested directly — which is the only way
 * the per-channel identifier rules below get exercised without a WABA.
 *
 * Returns ['event' => array] or ['error' => code]. Never throws, never
 * partially fills a payload: an event Meta will reject is worse than no event,
 * because it consumes the retry budget and reports nothing.
 *
 * IDENTIFIERS ARE NOT INTERCHANGEABLE ACROSS CHANNELS. Meta requires a
 * specific pair per channel, and a WhatsApp thread has no IGSID any more than
 * an Instagram thread has a ctwa_clid. Sending the wrong pair matches nobody.
 * All of them are transmitted UNHASHED — these are opaque platform handles,
 * not personal data, and hashing them (the instinct that is right for email
 * and phone) makes them unmatchable.
 */
function se_capi_messaging_build_event($row)
{
    $ctx = [];
    if (!empty($row['payload'])) {
        $decoded = json_decode((string) $row['payload'], true);
        if (is_array($decoded)) { $ctx = $decoded; }
    }

    $channel = isset($ctx['messaging_channel']) ? (string) $ctx['messaging_channel'] : '';
    if (!in_array($channel, se_capi_messaging_channels(), true)) {
        return ['error' => 'unknown_channel'];
    }

    $name = se_capi_messaging_event_name(isset($ctx['signal']) ? (string) $ctx['signal'] : '');
    if ($name === null) {
        return ['error' => 'unmapped_signal'];
    }

    $user_data = [];

    if ($channel === 'whatsapp') {
        // ctwa_clid is the WHOLE point: it is the only thing that ties this
        // thread back to the ad that opened it. A WhatsApp messaging event
        // without one is unattributable, so it is required, not optional.
        if (empty($ctx['ctwa_clid']) || empty($ctx['whatsapp_business_account_id'])) {
            return ['error' => 'missing_whatsapp_identifiers'];
        }
        $user_data['whatsapp_business_account_id'] = (string) $ctx['whatsapp_business_account_id'];
        $user_data['ctwa_clid']                    = (string) $ctx['ctwa_clid'];
    } elseif ($channel === 'instagram') {
        if (empty($ctx['ig_sid']) || empty($ctx['instagram_business_account_id'])) {
            return ['error' => 'missing_instagram_identifiers'];
        }
        $user_data['instagram_business_account_id'] = (string) $ctx['instagram_business_account_id'];
        $user_data['ig_sid']                        = (string) $ctx['ig_sid'];
    } else {
        if (empty($ctx['page_id']) || empty($ctx['page_scoped_user_id'])) {
            return ['error' => 'missing_messenger_identifiers'];
        }
        $user_data['page_id']             = (string) $ctx['page_id'];
        $user_data['page_scoped_user_id'] = (string) $ctx['page_scoped_user_id'];
    }

    $event = [
        'event_name'        => $name,
        'event_time'        => strtotime($row['event_time']),
        'action_source'     => 'business_messaging',
        'messaging_channel' => $channel,
        'user_data'         => $user_data,
        /* Stable across retries because it is built from immutable primary
         * keys. The outbox is at-least-once by design, so this is what stops
         * a redelivery from counting as a second conversion. */
        'event_id'          => 'se-mm-' . (int) $row['lead_id'] . '-' . $row['id'],
    ];

    /* custom_data carries NOTHING clinical — no procedure, no body area, no
     * photo, no health attribute. The same rule as the web sender, restated
     * because this payload is assembled from a live patient conversation and
     * the temptation to enrich it is right there. */
    $event['custom_data'] = [
        'event_source'      => 'business_messaging',
        'lead_event_source' => defined('SE_CLINIC_NAME') ? SE_CLINIC_NAME : 'SEO Evaluate CRM',
    ];

    return ['event' => $event];
}

/**
 * Sends one business messaging event. Called by the outbox drainer for rows
 * whose destination is 'meta_mm_capi'.
 *
 * Failure classes follow the outbox contract exactly (se_outbox.php): a
 * missing credential or a disabled brand is GATED and must not consume an
 * attempt — burning the retry budget while waiting for the owner to install a
 * token is how a queue dies quietly. A malformed payload is PERMANENT: the
 * same row will assemble the same way forever.
 */
function se_capi_messaging_send_event($row)
{
    $brand_id = (int) $row['brand_id'];

    if (!se_capi_messaging_enabled($brand_id)) {
        return ['ok' => false, 'error' => 'messaging CAPI is not enabled for this brand',
                'class' => SE_OUTBOX_FAIL_GATED, 'code' => 'disabled'];
    }

    $dataset = se_capi_messaging_dataset($brand_id);
    if ($dataset['id'] === null) {
        /* Every one of these is a configuration fact the owner can act on, so
         * the code says which. 'dataset_collision' in particular means the
         * registry now points web and messaging at one id — a data-corrupting
         * state that must stop the send, not degrade it. */
        return ['ok' => false, 'error' => 'no usable messaging dataset (' . $dataset['code'] . ')',
                'class' => SE_OUTBOX_FAIL_GATED, 'code' => $dataset['code']];
    }

    $token = se_capi_messaging_token($brand_id);
    if ($token === '') {
        return ['ok' => false, 'error' => 'no Meta token for the messaging dataset',
                'class' => SE_OUTBOX_FAIL_GATED, 'code' => 'no_token'];
    }

    /* Consent is checked by the drainer before this is reached, and again by
     * the producer before the row exists. Both remain true; this sender does
     * not re-derive it, because a third opinion assembled from different
     * inputs is how the three disagree. */

    $built = se_capi_messaging_build_event($row);
    if (isset($built['error'])) {
        return ['ok' => false, 'error' => 'unsendable messaging event: ' . $built['error'],
                'class' => SE_OUTBOX_FAIL_PERMANENT, 'code' => $built['error']];
    }

    $version = get_option('se_meta_graph_version') ?: 'v26.0';
    $url = 'https://graph.facebook.com/' . $version . '/'
         . rawurlencode($dataset['id']) . '/events';

    $result = se_capi_messaging_http($url, $token, ['data' => [$built['event']]]);

    if (!empty($result['transport_error'])) {
        return ['ok' => false, 'error' => 'transport error',
                'class' => SE_OUTBOX_FAIL_RETRYABLE, 'code' => 'curl'];
    }

    $code = (int) $result['status'];

    if ($code >= 200 && $code < 300) {
        // Evidence, never identifiers: which event, which dataset, when.
        update_option('se_meta_last_mm_capi_at', date('Y-m-d H:i:s'));
        update_option('se_meta_last_mm_capi_event', (string) $built['event']['event_name']);
        update_option('se_meta_last_mm_capi_channel', (string) $built['event']['messaging_channel']);
        update_option('se_meta_last_mm_capi_dataset', $dataset['id']);

        return ['ok' => true, 'error' => '', 'class' => null, 'code' => 'ok'];
    }

    update_option('se_meta_last_mm_capi_error', 'HTTP ' . $code . ' at ' . date('Y-m-d H:i:s'));

    $class = ($code >= 400 && $code < 500 && !in_array($code, [401, 403, 408, 429], true))
        ? SE_OUTBOX_FAIL_PERMANENT
        : SE_OUTBOX_FAIL_RETRYABLE;

    return ['ok' => false, 'error' => 'HTTP ' . $code, 'class' => $class, 'code' => 'http_' . $code];
}

/**
 * The one network call, behind a replaceable seam.
 *
 * Same pattern as se_media_register_http: the tests must be able to exercise
 * every status-code branch above without a WABA, a token, or a single packet
 * leaving the machine. A sender that can only be tested against production is
 * a sender nobody tests.
 */
function se_capi_messaging_register_http(?callable $fn = null)
{
    static $impl = null;
    if ($fn !== null) { $impl = $fn; }

    return $impl;
}

function se_capi_messaging_http($url, $token, array $payload)
{
    $impl = se_capi_messaging_register_http();
    if ($impl !== null) {
        return call_user_func($impl, $url, $token, $payload);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        // Bearer header, never the query string — a URL reaches proxy logs,
        // error text and access logs; a token in one of those is an incident.
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json',
                                   'Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    return ['status' => $status, 'body' => $body, 'transport_error' => $cerr !== ''];
}

/**
 * Queues a messaging conversion for a conversation that came from an ad.
 *
 * Called from the WhatsApp and Instagram modules at the moment the ad
 * referral is recorded — the first inbound message on a thread that a
 * click-to-message ad opened. That is the earliest point at which every
 * identifier exists AND the last point at which they are guaranteed present:
 * ctwa_clid arrives on the first inbound only, and is never repeated.
 *
 * $ctx must carry `messaging_channel`, `signal`, and the channel's own
 * identifier pair. Nothing is derived here from the live conversation row —
 * the caller owns the record, and re-reading it later is the drift the outbox
 * snapshot exists to prevent.
 *
 * Returns true only when a row was created. A false is normal and common:
 * no lead yet, no ad referral, brand not enabled.
 */
function se_capi_messaging_queue($brand_id, $lead_id, array $ctx, $event_time = null)
{
    $brand_id = (int) $brand_id;
    $lead_id  = (int) $lead_id;

    if ($brand_id <= 0 || $lead_id <= 0) {
        return false;
    }

    if (!se_capi_messaging_enabled($brand_id)) {
        /* Refuse at the PRODUCER when the brand is switched off, rather than
         * queueing rows that will sit gated forever. The web CAPI destination
         * learned this: presence of an id was once enough to queue, which
         * filled the outbox for brands nobody had turned on. */
        return false;
    }

    $channel = isset($ctx['messaging_channel']) ? (string) $ctx['messaging_channel'] : '';
    if (!in_array($channel, se_capi_messaging_channels(), true)) {
        return false;
    }

    if (se_capi_messaging_event_name(isset($ctx['signal']) ? (string) $ctx['signal'] : '') === null) {
        return false;
    }

    // The event name stored on the row is Meta's, so the outbox UI and the
    // dedup key both read as what was actually sent.
    $event_name = se_capi_messaging_event_name((string) $ctx['signal']);

    /* The channel is part of the identity of this conversion: the same
     * patient can answer a WhatsApp ad and an Instagram ad on the same day,
     * and those are two conversions, not one. */
    return se_outbox_queue($brand_id, $lead_id, 'meta_mm_capi', $event_name, $ctx, $event_time, $channel);
}

/**
 * Queues the messaging conversion for a WhatsApp conversation that an ad
 * opened, reading the identifiers from the conversation row.
 *
 * Called at the moment the thread is linked to a lead. Reads the row by id +
 * brand rather than through a staff-scoped getter: this runs on the webhook
 * path, where there is no staff session, and a scoped read there poisons the
 * shared query builder (the blank-500 incident).
 *
 * A conversation with no ctwa_clid returns false and that is the ordinary
 * case — organic enquiries vastly outnumber ad ones, and reporting them as ad
 * conversions would be a straightforward lie to the optimiser.
 */
function se_capi_messaging_queue_for_wa_conversation($conversation_id, $lead_id)
{
    $CI = &get_instance();

    $CI->db->where('id', (int) $conversation_id);
    $conv = $CI->db->get(db_prefix() . 'se_wa_conversations')->row();

    if (!$conv || empty($conv->ctwa_clid)) {
        return false;
    }

    /* Meta requires the WABA id alongside the click id. The conversation table
     * carries no waba_id column (audit T14 / CRM-M052): resolve it from the
     * number the thread runs on (se_wa_numbers.phone_number_id → waba_id),
     * then the brand's first number. Only a thread whose number is unknown is
     * refused — a half-identified event matches nobody and burns a retry. */
    $waba = !empty($conv->waba_id) ? (string) $conv->waba_id : '';
    if ($waba === '' && !empty($conv->phone_number_id)) {
        $CI->db->select('waba_id')->where('phone_number_id', (string) $conv->phone_number_id);
        $n = $CI->db->get(db_prefix() . 'se_wa_numbers')->row_array();
        $waba = (string) ($n['waba_id'] ?? '');
    }
    if ($waba === '' && function_exists('se_wa_waba_for_brand')) {
        $waba = (string) se_wa_waba_for_brand((int) $conv->brand_id);
    }
    if ($waba === '') {
        return false;
    }

    return se_capi_messaging_queue((int) $conv->brand_id, (int) $lead_id, [
        'messaging_channel'            => 'whatsapp',
        'signal'                       => 'conversation_started',
        'ctwa_clid'                    => (string) $conv->ctwa_clid,
        'whatsapp_business_account_id' => $waba,
    ]);
}

/**
 * The Instagram counterpart. IG has no click id — the thread itself is the
 * identifier — so the ad origin is proven by the stored referral instead.
 */
function se_capi_messaging_queue_for_ig_conversation($conversation_id, $lead_id)
{
    $CI = &get_instance();

    $CI->db->where('id', (int) $conversation_id);
    $conv = $CI->db->get(db_prefix() . 'se_ig_conversations')->row();

    // No referral means the person found the profile on their own. Reporting
    // that as an ad conversion is the mistake this check exists to prevent.
    if (!$conv || empty($conv->referral_json) || empty($conv->igsid) || empty($conv->ig_account_id)) {
        return false;
    }

    return se_capi_messaging_queue((int) $conv->brand_id, (int) $lead_id, [
        'messaging_channel'             => 'instagram',
        'signal'                        => 'conversation_started',
        'ig_sid'                        => (string) $conv->igsid,
        'instagram_business_account_id' => (string) $conv->ig_account_id,
    ]);
}
