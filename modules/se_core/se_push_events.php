<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * What the CRM pushes, to whom, and — mostly — what it refuses to say.
 *
 * WHAT A NOTIFICATION MAY CONTAIN
 * A push lands on a lock screen. In a clinic that lock screen is on a phone
 * lying on a desk in a room with patients in it, and the person who reads it
 * is often not the person it was sent to. So a notification says only what
 * KIND of thing happened and where to go. Never the message text, never the
 * patient's name or number, never anything clinical, never a quote amount.
 * "Yeni WhatsApp mesajı" and a link is the whole payload.
 *
 * The bodies are encrypted end to end (RFC 8291) and the push service cannot
 * read them, but that is not why this rule exists — the rule is about the
 * screen, not the wire.
 *
 * WHO GETS IT
 * The assigned staff member when a thread has one, otherwise everyone with
 * access to that brand. A one-practitioner clinic mostly means "both people",
 * and that is correct: an unanswered patient is the failure mode that matters,
 * not a redundant buzz.
 *
 * FAILURE IS ALWAYS SWALLOWED
 * Every function here is called from a webhook or a state change that has
 * already done the important work. Losing a message to save a notification is
 * the wrong trade, so nothing below may throw.
 */

/** Staff who can see a brand. The fallback audience for anything unassigned. */
function se_push_brand_staff($brand_id)
{
    $CI = &get_instance();

    $CI->db->select('staff_id')->where('brand_id', (int) $brand_id);
    $rows = $CI->db->get(db_prefix() . 'se_staff_brands')->result();

    $out = [];
    foreach ($rows as $r) {
        $out[] = (int) $r->staff_id;
    }

    return $out;
}

/**
 * Recipients for a conversation: the assigned staff member, else the brand.
 *
 * `assigned_staff` of 0 means nobody has picked the thread up — which is
 * exactly when a notification is most useful, so it widens rather than
 * narrows.
 */
function se_push_conversation_recipients($brand_id, $assigned_staff)
{
    $assigned = (int) $assigned_staff;

    return $assigned > 0 ? [$assigned] : se_push_brand_staff($brand_id);
}

/** A guard so every entry point below reads the same. */
function se_push_safe_notify($staff_ids, array $payload)
{
    if (!function_exists('se_push_notify') || !se_push_configured()) {
        return 0;
    }

    try {
        return se_push_notify($staff_ids, $payload);
    } catch (Exception $e) {
        // A notification is never worth an exception on a webhook path.
        return 0;
    }
}

/**
 * An inbound WhatsApp or Instagram message.
 *
 * `$channel` is 'wa' or 'ig'. The tag is per-conversation on purpose: a
 * patient sending ten lines in a row should replace one notification, not
 * stack ten on a phone.
 */
function se_push_notify_inbound($channel, $brand_id, $conversation_id, $assigned_staff)
{
    $isWa = $channel === 'wa';

    return se_push_safe_notify(
        se_push_conversation_recipients($brand_id, $assigned_staff),
        [
            't'     => $channel,
            'title' => $isWa ? 'Yeni WhatsApp mesajı' : 'Yeni Instagram mesajı',
            // No sender, no number, no text. Deliberate — see the header.
            'body'  => 'Yanıt bekleyen bir mesaj var.',
            'tag'   => $channel . '-' . (int) $conversation_id,
            'url'   => admin_url(($isWa ? 'se_whatsapp/conversation/' : 'se_instagram/conversation/') . (int) $conversation_id),
        ]
    );
}

/** A new lead, from the website form or from Meta Lead Ads. */
function se_push_notify_lead($brand_id, $lead_id, $source = '')
{
    return se_push_safe_notify(se_push_brand_staff($brand_id), [
        't'     => 'lead',
        'title' => 'Yeni başvuru',
        // The source is a fixed vocabulary of our own words, never free text
        // and never anything the submitter typed.
        'body'  => $source === 'leadgen' ? 'Meta reklam formundan geldi.' : 'Web sitesi formundan geldi.',
        'tag'   => 'lead-' . (int) $lead_id,
        'url'   => admin_url('leads/index/' . (int) $lead_id),
    ]);
}

/**
 * A patient journey changed state.
 *
 * Only states a HUMAN must act on are pushed. A journey moves through many
 * states on its own, and notifying on each one trains people to swipe
 * notifications away — at which point the ones that matter are gone too.
 */
function se_push_notify_journey($brand_id, $journey_id, $to_state, $assigned_staff = 0)
{
    $titles = [
        'quote_accepted'            => 'Teklif kabul edildi',
        'quote_revision_requested'  => 'Fiyat revizyonu istendi',
        'consultation_booked'       => 'Konsültasyon randevusu alındı',
        'handoff_requested'         => 'Danışman talep edildi',
        'quote_pending_staff_approval' => 'Teklif onayı bekliyor',
    ];

    if (!isset($titles[$to_state])) {
        return 0;
    }

    return se_push_safe_notify(
        se_push_conversation_recipients($brand_id, $assigned_staff),
        [
            't'     => 'journey',
            'title' => $titles[$to_state],
            'body'  => 'Hasta yolculuğunda işlem bekliyor.',
            // Per journey, not per state: the latest state replaces the last.
            'tag'   => 'journey-' . (int) $journey_id,
            'url'   => admin_url('se_journey/journey/' . (int) $journey_id),
        ]
    );
}

/**
 * A WhatsApp call is ringing.
 *
 * The CRM cannot answer it — staff pick up in the WhatsApp Business app — so
 * this notification's only job is to make sure someone knows the phone is
 * ringing when it is not in their hand.
 */
function se_push_notify_call($brand_id, $conversation_id, $assigned_staff = 0)
{
    return se_push_safe_notify(
        se_push_conversation_recipients($brand_id, $assigned_staff),
        [
            't'     => 'call',
            'title' => 'WhatsApp araması',
            'body'  => 'Bir hasta arıyor.',
            'tag'   => 'call-' . (int) $conversation_id,
            'url'   => admin_url('se_whatsapp/conversation/' . (int) $conversation_id),
        ]
    );
}

/**
 * A WhatsApp call ended without being answered.
 *
 * The expensive failure in a clinic is not a dropped call, it is a missed one
 * nobody rings back. This is the only notification here that is about
 * something that already went wrong.
 */
function se_push_notify_missed_call($brand_id, $conversation_id, $assigned_staff = 0)
{
    return se_push_safe_notify(
        se_push_conversation_recipients($brand_id, $assigned_staff),
        [
            't'     => 'call_missed',
            'title' => 'Cevapsız WhatsApp araması',
            'body'  => 'Geri aranması gerekiyor.',
            // A distinct tag from the ringing one: the missed-call notice must
            // NOT be replaced by, or replace, the "ringing" one — they say
            // different things and the second is the one that needs acting on.
            'tag'   => 'call-missed-' . (int) $conversation_id,
            'url'   => admin_url('se_whatsapp/conversation/' . (int) $conversation_id),
        ]
    );
}
