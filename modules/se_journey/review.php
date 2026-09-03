<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * se_journey — staff review and the personalised quote.
 *
 * Nothing in this file infers suitability or a price from health answers.
 * A staff member records a DECISION; a staff member with approve_quote
 * APPROVES a quote; only an approved quote can be SENT, and what was sent is
 * frozen as an immutable snapshot (hash recorded) with the mandatory
 * "preliminary, subject to clinician assessment, no guarantee" wording.
 */

function se_journey_review_decisions()
{
    return ['more_information', 'consultation_required', 'provisionally_suitable', 'not_suitable', 'unable_to_assess'];
}

function se_journey_review_get($j)
{
    $CI = &get_instance();
    $CI->db->where('journey_id', (int) $j->id)->where('brand_id', (int) $j->brand_id)->order_by('id', 'DESC')->limit(1);

    return $CI->db->get(db_prefix() . 'se_journey_reviews')->row();
}

/** Opening the review workspace moves ready_for_review → under_review (audited elsewhere). */
function se_journey_review_open($j, $staff_id)
{
    if ((string) $j->state === 'ready_for_review') {
        se_journey_transition($j, 'under_review', 'review_opened', 'staff', $staff_id);
    }
    $r = se_journey_review_get($j);
    if (!$r) {
        $CI = &get_instance();
        $CI->db->insert(db_prefix() . 'se_journey_reviews', [
            'journey_id' => (int) $j->id, 'brand_id' => (int) $j->brand_id, 'reviewer_id' => (int) $staff_id,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $r = se_journey_review_get($j);
    }

    return $r;
}

/**
 * Save the review (notes/checklist/assignee/due) and, when a decision is
 * given, apply the matching transition. Internal notes are staff-only and
 * are never part of any patient-facing payload (the quote snapshot builder
 * reads an explicit allow-list of fields).
 */
function se_journey_review_save($j, array $data, $staff_id)
{
    $r = se_journey_review_open($j, $staff_id);
    $CI = &get_instance();
    $now = date('Y-m-d H:i:s');
    $upd = ['reviewer_id' => (int) $staff_id, 'updated_at' => $now];
    if (array_key_exists('internal_notes', $data)) {
        $upd['internal_notes'] = mb_substr((string) $data['internal_notes'], 0, 20000);
    }
    if (isset($data['checklist']) && is_array($data['checklist'])) {
        $upd['checklist_json'] = json_encode(array_values(array_map(function ($x) { return mb_substr((string) $x, 0, 64); }, $data['checklist'])));
    }
    if (!empty($data['due_at']) && strtotime((string) $data['due_at']) !== false) {
        $upd['due_at'] = date('Y-m-d H:i:s', strtotime((string) $data['due_at']));
    }
    $decision = isset($data['decision']) ? (string) $data['decision'] : '';
    if ($decision !== '' && !in_array($decision, se_journey_review_decisions(), true)) {
        return ['ok' => false, 'reason' => 'bad_decision'];
    }
    if ($decision !== '') {
        $upd['decision'] = $decision;
    }
    $CI->db->where('id', (int) $r->id)->where('brand_id', (int) $j->brand_id)->update(db_prefix() . 'se_journey_reviews', $upd);

    if (!empty($data['assigned_staff']) || !empty($data['next_action'])) {
        $ju = ['last_updated' => $now];
        if (!empty($data['assigned_staff'])) { $ju['assigned_staff'] = (int) $data['assigned_staff']; }
        if (!empty($data['next_action'])) { $ju['next_action'] = mb_substr((string) $data['next_action'], 0, 191); }
        if (!empty($data['next_action_due_at']) && strtotime((string) $data['next_action_due_at']) !== false) {
            $ju['next_action_due_at'] = date('Y-m-d H:i:s', strtotime((string) $data['next_action_due_at']));
        }
        $CI->db->where('id', (int) $j->id)->where('brand_id', (int) $j->brand_id)->update(db_prefix() . 'se_journeys', $ju);
    }
    se_journey_audit((int) $j->brand_id, (int) $j->id, 'review_save', 'review', (string) $r->id, $decision !== '' ? 'decision=' . $decision : null);

    if ($decision === '') {
        return ['ok' => true, 'reason' => ''];
    }

    $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', ['review_decision' => $decision]);
    $j->review_decision = $decision;
    $map = [
        'more_information'       => 'more_information_required',
        'unable_to_assess'       => 'more_information_required',
        'consultation_required'  => 'consultation_recommended',
        'provisionally_suitable' => 'quote_pending_staff_approval',
        'not_suitable'           => 'not_suitable',
    ];
    $target = $map[$decision];
    if ((string) $j->state !== $target) {
        if ((string) $j->state === 'ready_for_review') {
            se_journey_transition($j, 'under_review', 'review_decided', 'staff', $staff_id);
        }
        $t = se_journey_transition($j, $target, 'decision_' . $decision, 'staff', $staff_id, null, isset($data['decision_note']) ? mb_substr((string) $data['decision_note'], 0, 500) : null);
        if (!$t['ok']) {
            return ['ok' => false, 'reason' => $t['reason']];
        }
    }
    se_journey_event($j, 'review_decision', $decision, [], 'staff', $staff_id);

    if ($decision === 'more_information' || $decision === 'unable_to_assess') {
        se_journey_task($j, 'more_info', 'Reviewer needs more information — contact the patient', 'normal', null, $now);
        if (!empty($data['notify_patient']) && function_exists('se_journey_send_copy')) {
            se_journey_send_copy($j, 'more_info_request', [], ['purpose' => 'more_info_request', 'bypass_pause' => true,
                'template' => 'eyebrow_intake_resume_tr', 'template_vars' => [se_journey_template_name($j), se_journey_public_url('')]]);
        }
    } elseif ($decision === 'consultation_required') {
        se_journey_task($j, 'book_consultation', 'Consultation recommended — book a slot', 'normal', null, $now);
    } elseif ($decision === 'provisionally_suitable') {
        se_journey_task($j, 'quote_approval', 'Draft the quote and get it approved', 'normal', null, $now);
    } elseif ($decision === 'not_suitable') {
        se_journey_task($j, 'not_suitable_contact', 'Reviewer marked not suitable — a human must inform the patient sensitively', 'normal', null, $now);
    }

    return ['ok' => true, 'reason' => ''];
}

/* ===========================================================================
 * Quotes
 * ======================================================================== */

/** Clinic policy: may an amount be shown? hidden | range | exact. */
function se_journey_quote_amount_policy($brand_id)
{
    $p = (string) get_option('se_journey_quote_amount_policy_' . (int) $brand_id);

    return in_array($p, ['hidden', 'range', 'exact'], true) ? $p : 'range';
}

function se_journey_quote_latest($j)
{
    $CI = &get_instance();
    $CI->db->where('journey_id', (int) $j->id)->where('brand_id', (int) $j->brand_id)->order_by('id', 'DESC')->limit(1);

    return $CI->db->get(db_prefix() . 'se_journey_quotes')->row();
}

function se_journey_quote_get($quote_id)
{
    $CI = &get_instance();
    $CI->db->where('id', (int) $quote_id);
    if (function_exists('se_apply_scope_in')) {
        se_apply_scope_in('brand_id');
    }

    return $CI->db->get(db_prefix() . 'se_journey_quotes')->row();
}

/** Create or update the current DRAFT quote. Never touches an approved/sent one. */
function se_journey_quote_draft($j, array $data, $staff_id)
{
    $CI = &get_instance();
    $now = date('Y-m-d H:i:s');
    $policy = se_journey_quote_amount_policy((int) $j->brand_id);

    $clean = [
        'currency'       => preg_match('/^[A-Z]{3}$/', (string) ($data['currency'] ?? '')) ? (string) $data['currency'] : 'TRY',
        'amount_min'     => isset($data['amount_min']) && $data['amount_min'] !== '' ? round((float) $data['amount_min'], 2) : null,
        'amount_max'     => isset($data['amount_max']) && $data['amount_max'] !== '' ? round((float) $data['amount_max'], 2) : null,
        'show_amount'    => $policy !== 'hidden' && !empty($data['show_amount']) ? 1 : 0,
        'valid_until'    => !empty($data['valid_until']) && strtotime((string) $data['valid_until']) !== false ? date('Y-m-d', strtotime((string) $data['valid_until'])) : null,
        'included_json'  => json_encode(se_journey_lines($data['included'] ?? [])),
        'excluded_json'  => json_encode(se_journey_lines($data['excluded'] ?? [])),
        'deposit_terms'  => mb_substr(trim((string) ($data['deposit_terms'] ?? '')), 0, 500),
        'travel_notes'   => mb_substr(trim((string) ($data['travel_notes'] ?? '')), 0, 1000),
        'recommendation' => in_array((string) ($data['recommendation'] ?? ''), ['consultation', 'procedure_after_consultation'], true) ? (string) $data['recommendation'] : 'consultation',
        'internal_notes' => mb_substr((string) ($data['internal_notes'] ?? ''), 0, 20000),
        'internal_margin'=> mb_substr((string) ($data['internal_margin'] ?? ''), 0, 191),
        'last_updated'   => $now,
    ];
    if ($policy === 'exact' && $clean['amount_min'] !== null && $clean['amount_max'] === null) {
        $clean['amount_max'] = $clean['amount_min'];
    }
    if ($clean['amount_min'] !== null && $clean['amount_max'] !== null && $clean['amount_max'] < $clean['amount_min']) {
        return ['ok' => false, 'reason' => 'amount_range_invalid', 'id' => 0];
    }
    if ($clean['amount_min'] !== null && $clean['amount_min'] < 0) {
        return ['ok' => false, 'reason' => 'amount_invalid', 'id' => 0];
    }

    $latest = se_journey_quote_latest($j);
    if ($latest && in_array((string) $latest->status, ['draft', 'pending_approval'], true)) {
        $clean['status'] = 'draft';   // any edit invalidates a pending approval request
        $CI->db->where('id', (int) $latest->id)->where('brand_id', (int) $j->brand_id)->update(db_prefix() . 'se_journey_quotes', $clean);
        $id = (int) $latest->id;
    } else {
        $clean += ['journey_id' => (int) $j->id, 'brand_id' => (int) $j->brand_id, 'version' => $latest ? (int) $latest->version + 1 : 1,
                   'status' => 'draft', 'created_by' => (int) $staff_id, 'date_created' => $now];
        $CI->db->insert(db_prefix() . 'se_journey_quotes', $clean);
        $id = (int) $CI->db->insert_id();
    }
    se_journey_audit((int) $j->brand_id, (int) $j->id, 'quote_draft', 'quote', (string) $id, null);

    return ['ok' => true, 'reason' => '', 'id' => $id];
}

function se_journey_lines($raw)
{
    $out = [];
    if (is_string($raw)) {
        $raw = preg_split('/\r?\n/', $raw);
    }
    foreach ((array) $raw as $line) {
        $line = trim((string) $line);
        if ($line !== '') { $out[] = mb_substr($line, 0, 200); }
        if (count($out) >= 30) { break; }
    }

    return $out;
}

function se_journey_quote_request_approval($quote_id, $staff_id)
{
    $q = se_journey_quote_get($quote_id);
    if (!$q || (string) $q->status !== 'draft') {
        return ['ok' => false, 'reason' => 'not_draft'];
    }
    se_guarded_update(db_prefix() . 'se_journey_quotes', 'id', (int) $q->id, ['status' => 'pending_approval', 'last_updated' => date('Y-m-d H:i:s')]);
    se_journey_audit((int) $q->brand_id, (int) $q->journey_id, 'quote_request_approval', 'quote', (string) $q->id, null);
    $j = se_journey_get_raw((int) $q->journey_id);
    if ($j) {
        se_journey_task($j, 'quote_approval', 'Quote v' . (int) $q->version . ' awaits approval', 'normal', null, 'v' . (int) $q->version);
        if ($j->automation_state === 'active') {
            se_journey_set_automation($j, 'awaiting_approval', 'quote_pending_approval', 'staff', (int) $staff_id);
        }
    }

    return ['ok' => true, 'reason' => ''];
}

/** Approval: capability-gated HERE, not only in the controller. */
function se_journey_quote_approve($quote_id, $staff_id)
{
    if (!se_journey_can('approve_quote', $staff_id ?: '')) {
        return ['ok' => false, 'reason' => 'forbidden'];
    }
    $q = se_journey_quote_get($quote_id);
    if (!$q || !in_array((string) $q->status, ['draft', 'pending_approval'], true)) {
        return ['ok' => false, 'reason' => 'not_approvable'];
    }
    $now = date('Y-m-d H:i:s');
    se_guarded_update(db_prefix() . 'se_journey_quotes', 'id', (int) $q->id, ['status' => 'approved', 'approved_by' => (int) $staff_id, 'approved_at' => $now, 'last_updated' => $now]);
    se_journey_audit((int) $q->brand_id, (int) $q->journey_id, 'quote_approve', 'quote', (string) $q->id, 'v' . (int) $q->version);
    $j = se_journey_get_raw((int) $q->journey_id);
    if ($j) {
        se_journey_event($j, 'quote_approved', 'v' . (int) $q->version, [], 'staff', $staff_id, 'quote', (string) $q->id);
        if ($j->automation_state === 'awaiting_approval') {
            se_journey_set_automation($j, 'active', 'quote_approved', 'staff', (int) $staff_id);
        }
    }

    return ['ok' => true, 'reason' => ''];
}

/** Mandatory patient-facing disclaimer (never editable into a guarantee). */
function se_journey_quote_disclaimer($lang = 'tr')
{
    return "Bu belge, paylaştığınız bilgiler ve fotoğraflar üzerinden yapılan ön değerlendirmeye dayalı, kişiye özel bir ön tekliftir. Kesin tıbbi uygunluk, sonuç ya da kalıcılık garantisi içermez. Nihai değerlendirme klinisyen tarafından görüşme sırasında yapılır; teklif geçerlilik tarihine kadar geçerlidir.";
}

/**
 * Build the frozen patient-facing payload from an explicit allow-list of
 * fields. Internal notes and margins can NEVER appear here.
 */
function se_journey_quote_snapshot($q, $j)
{
    $policy = se_journey_quote_amount_policy((int) $q->brand_id);
    $amount = null;
    if ((int) $q->show_amount === 1 && $policy !== 'hidden' && $q->amount_min !== null) {
        $amount = $policy === 'exact' || $q->amount_max === null || (float) $q->amount_max === (float) $q->amount_min
            ? ['kind' => 'exact', 'value' => (float) $q->amount_min, 'currency' => (string) $q->currency]
            : ['kind' => 'range', 'min' => (float) $q->amount_min, 'max' => (float) $q->amount_max, 'currency' => (string) $q->currency];
    }

    return [
        'version'        => (int) $q->version,
        'prepared_for'   => se_journey_first_name($j),
        'recommendation' => (string) $q->recommendation,
        'amount'         => $amount,
        'valid_until'    => $q->valid_until ? (string) $q->valid_until : null,
        'included'       => json_decode((string) $q->included_json, true) ?: [],
        'excluded'       => json_decode((string) $q->excluded_json, true) ?: [],
        'deposit_terms'  => (string) $q->deposit_terms,
        'travel_notes'   => (string) $q->travel_notes,
        'disclaimer'     => se_journey_quote_disclaimer((string) $j->language),
        'approved_at'    => (string) ($q->approved_at ?? ''),
        'clinic'         => defined('SE_CLINIC_NAME') ? SE_CLINIC_NAME : 'Azin Asgari – Kaş Ekimi, İstanbul',
        'title'          => 'Kaş Ekimi Uzmanı',
    ];
}

/** Send an APPROVED quote: snapshot → token → message → quote_sent. */
function se_journey_quote_send($quote_id, $staff_id)
{
    $q = se_journey_quote_get($quote_id);
    if (!$q) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    if ((string) $q->status !== 'approved') {
        se_journey_audit((int) $q->brand_id, (int) $q->journey_id, 'quote_send_refused', 'quote', (string) $q->id, 'status=' . $q->status);

        return ['ok' => false, 'reason' => 'not_approved'];
    }
    $j = se_journey_get_raw((int) $q->journey_id);
    if (!$j) {
        return ['ok' => false, 'reason' => 'journey_missing'];
    }
    $snapshot = se_journey_quote_snapshot($q, $j);
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
    $token = se_journey_issue_token($j, 'quote', (int) $staff_id);
    if (!$token['ok']) {
        return ['ok' => false, 'reason' => 'token_failed'];
    }
    $link = se_journey_public_url('se_journey/intake/' . $token['token'] . '/quote');
    $spec = ['purpose' => 'evaluation_ready', 'bypass_pause' => true, 'dedup_salt' => 'q' . (int) $q->id,
             'template_vars' => [se_journey_template_name($j), $link]];
    // Inside the window: the message itself carries the three reply buttons
    // (accept / price revision / human). Outside it: the quick-reply template
    // when Meta has approved it, else the plain evaluation template (the quote
    // page then carries the same three actions).
    $withButtons = se_journey_template_ready((int) $j->brand_id, 'eyebrow_quote_ready_tr');
    $spec['template'] = $withButtons['ready'] ? 'eyebrow_quote_ready_tr' : 'eyebrow_evaluation_ready_tr';
    if ($withButtons['ready']) {
        $spec['template_quick_replies'] = array_column(se_journey_quote_buttons((int) $j->brand_id, (string) $j->language), 'id');
    }
    if (se_journey_interactive_enabled((int) $j->brand_id)) {
        $spec['kind'] = 'interactive';
        $spec['buttons'] = se_journey_quote_buttons((int) $j->brand_id, (string) $j->language);
    }
    $r = se_journey_send_copy($j, 'evaluation_ready', ['link' => $link], $spec);
    if (!$r['ok']) {
        se_journey_revoke_tokens($j, 'quote', 'send_blocked');

        return ['ok' => false, 'reason' => 'send_' . $r['reason']];
    }
    // The quote alone does not explain the procedure, what to do before it or
    // what recovery looks like — send those links right behind it (gated
    // separately; see se_journey_send_consultation_information).
    if (function_exists('se_journey_send_consultation_information')) {
        se_journey_send_consultation_information($j);
    }
    $now = date('Y-m-d H:i:s');
    se_guarded_update(db_prefix() . 'se_journey_quotes', 'id', (int) $q->id, [
        'status' => 'sent', 'sent_at' => $now, 'sent_by' => (int) $staff_id, 'snapshot_json' => $json,
        'snapshot_hash' => hash('sha256', $json), 'wa_outbound_id' => (int) $r['outbound_id'], 'last_updated' => $now,
    ]);
    se_journey_audit((int) $j->brand_id, (int) $j->id, 'quote_send', 'quote', (string) $q->id, 'hash=' . substr(hash('sha256', $json), 0, 16));
    se_journey_event($j, 'quote_sent', 'v' . (int) $q->version . ' (' . $r['mode'] . ')', ['hash' => hash('sha256', $json)], 'staff', $staff_id, 'quote', (string) $q->id);
    // Staff may draft, approve and send from the review tab without ever
    // pressing "open review": an approved, sent quote IS the review decision.
    // Live 2026-09-03 the journey stayed at ready_for_review after the send,
    // so the patient's button tap was not read as a quote answer.
    if (in_array((string) $j->state, ['intake_submitted', 'photos_requested', 'photos_incomplete', 'photo_retake_requested',
                                       'ready_for_review', 'more_information_required'], true)) {
        se_journey_transition($j, 'under_review', 'quote_prepared', 'staff', $staff_id);
    }
    if (in_array((string) $j->state, ['quote_pending_staff_approval', 'consultation_recommended', 'consultation_completed', 'under_review',
                                       'quote_revision_requested', 'quote_accepted'], true)) {
        if ((string) $j->state !== 'quote_pending_staff_approval') {
            se_journey_transition($j, 'quote_pending_staff_approval', 'quote_prepared', 'staff', $staff_id);
        }
        se_journey_transition($j, 'quote_sent', 'quote_sent', 'staff', $staff_id);
    }
    if (function_exists('se_outbox_queue') && function_exists('se_outbox_destinations_for_brand') && (int) $j->lead_id > 0) {
        // Pipeline milestone "Quote Sent" → existing conversion outbox (consent-gated inside).
        foreach (se_outbox_destinations_for_brand((int) $j->brand_id) as $dest) {
            se_outbox_queue((int) $j->brand_id, (int) $j->lead_id, $dest, 'Quote Sent');
        }
    }

    return ['ok' => true, 'reason' => '', 'link' => $link];
}

/** The public quote page: token → frozen snapshot (never the live row). */
function se_journey_quote_public($raw_token, $ip = '', $ua = '')
{
    $v = se_journey_verify_token($raw_token, 'quote', $ip, $ua);
    if (!$v['ok']) {
        return ['ok' => false, 'reason' => $v['reason'], 'snapshot' => null];
    }
    $CI = &get_instance();
    $CI->db->where('journey_id', (int) $v['journey']->id)->where('status', 'sent')->order_by('id', 'DESC')->limit(1);
    $q = $CI->db->get(db_prefix() . 'se_journey_quotes')->row();
    if (!$q || empty($q->snapshot_json)) {
        return ['ok' => false, 'reason' => 'no_quote', 'snapshot' => null];
    }
    se_journey_event($v['journey'], 'quote_viewed', 'v' . (int) $q->version, [], 'patient', null, 'quote', (string) $q->id);

    return ['ok' => true, 'reason' => '', 'snapshot' => json_decode((string) $q->snapshot_json, true), 'journey' => $v['journey'],
            'response' => (string) ($q->patient_response ?? ''), 'quote_id' => (int) $q->id,
            'booking' => function_exists('se_journey_consultation_upcoming') ? se_journey_consultation_upcoming($v['journey']) : null];
}

/* ===========================================================================
 * The patient's answer to the quote
 * ======================================================================== */

/** Reply buttons offered with a sent quote (≤20 chars each — Meta's cap). */
function se_journey_quote_buttons($brand_id, $lang = 'tr')
{
    return [
        ['id' => 'jr_quote_accept', 'title' => se_journey_copy($brand_id, 'btn_quote_accept', [], $lang)],
        ['id' => 'jr_quote_revise', 'title' => se_journey_copy($brand_id, 'btn_quote_revise', [], $lang)],
        ['id' => 'jr_handoff',      'title' => se_journey_copy($brand_id, 'btn_handoff', [], $lang)],
    ];
}

/** The quote the patient is answering: the latest SENT one. */
function se_journey_quote_sent_row($j)
{
    $CI = &get_instance();
    $CI->db->where('journey_id', (int) $j->id)->where('brand_id', (int) $j->brand_id)->where('status', 'sent')->order_by('id', 'DESC')->limit(1);

    return $CI->db->get(db_prefix() . 'se_journey_quotes')->row();
}

/**
 * Repeat the three options once per quote (a typed question after the quote).
 * In-window only — a closed window means the quote went as a template whose
 * buttons are still on the patient's screen.
 */
function se_journey_send_quote_options($j, $correlation = '')
{
    $q = se_journey_quote_sent_row($j);
    if (!$q || !se_journey_interactive_enabled((int) $j->brand_id)) {
        return ['ok' => false, 'mode' => 'skipped', 'reason' => $q ? 'interactive_disabled' : 'no_quote', 'outbound_id' => 0];
    }
    $conv = se_journey_conversation($j);
    if ($conv) {
        $CI = &get_instance();
        $CI->db->where('conversation_id', (int) $conv->id)->where('origin', 'journey:quote_options')->where('date_created >=', (string) $q->sent_at);
        if ($CI->db->count_all_results(db_prefix() . 'se_wa_outbound') > 0) {
            return ['ok' => true, 'mode' => 'skipped', 'reason' => 'already_sent', 'outbound_id' => 0];
        }
    }
    $policy = $conv && function_exists('se_wa_compose_policy') ? se_wa_compose_policy($conv) : ['mode' => 'none'];
    if (($policy['mode'] ?? '') !== 'freeform') {
        return ['ok' => false, 'mode' => 'skipped', 'reason' => 'window_closed', 'outbound_id' => 0];
    }

    return se_journey_send_copy($j, 'quote_options', [], ['purpose' => 'quote_options', 'kind' => 'interactive', 'bypass_pause' => true,
        'buttons' => se_journey_quote_buttons((int) $j->brand_id, (string) $j->language), 'correlation' => $correlation, 'dedup_salt' => 'q' . (int) $q->id]);
}

/**
 * Record the patient's decision on the sent quote and act on it:
 *   accept → quote_accepted, staff task, booking link (calendar page);
 *   revise → quote_revision_requested, staff task (new version), acknowledgement.
 * Idempotent: a repeated accept re-sends the booking link, nothing else.
 *
 * @param string $via whatsapp|page|staff
 * @return array{ok:bool,reason:string,book_link:string}
 */
function se_journey_quote_respond($j, $action, $via = 'whatsapp', $correlation = '')
{
    $q = se_journey_quote_sent_row($j);
    if (!$q) {
        return ['ok' => false, 'reason' => 'no_quote', 'book_link' => ''];
    }
    $via = in_array((string) $via, ['whatsapp', 'page', 'staff'], true) ? (string) $via : 'whatsapp';
    $now = date('Y-m-d H:i:s');
    $CI  = &get_instance();

    if ($action === 'accept') {
        $already = (string) ($q->patient_response ?? '') === 'accepted';
        if (!$already) {
            // Direct, brand-bound update: the patient (page) and the dispatcher
            // (WhatsApp tap) have no staff session for a guarded update to scope by.
            $CI->db->where('id', (int) $q->id)->where('brand_id', (int) $j->brand_id)->update(db_prefix() . 'se_journey_quotes', [
                'patient_response' => 'accepted', 'patient_response_at' => $now, 'patient_response_via' => $via, 'last_updated' => $now,
            ]);
            se_journey_audit((int) $j->brand_id, (int) $j->id, 'quote_accepted', 'quote', (string) $q->id, 'via=' . $via);
            se_journey_event($j, 'quote_accepted', 'v' . (int) $q->version . ' (' . $via . ')', [], 'patient', null, 'quote', (string) $q->id, $correlation);
            if (in_array((string) $j->state, ['quote_sent', 'quote_revision_requested'], true)) {
                se_journey_transition($j, 'quote_accepted', 'patient_accepted_quote', 'patient', null, $correlation);
            }
            se_journey_task($j, 'quote_accepted', 'Patient ACCEPTED the quote (v' . (int) $q->version . ') — a consultation slot is being chosen from the calendar', 'normal', null, 'q' . (int) $q->id);
            if (function_exists('se_outbox_queue') && function_exists('se_outbox_destinations_for_brand') && (int) $j->lead_id > 0) {
                foreach (se_outbox_destinations_for_brand((int) $j->brand_id) as $dest) {
                    se_outbox_queue((int) $j->brand_id, (int) $j->lead_id, $dest, 'Quote Accepted');
                }
            }
        }
        $link = ['ok' => false, 'link' => '', 'reason' => 'booking_unavailable'];
        if (function_exists('se_journey_send_booking_link')) {
            $link = se_journey_send_booking_link($j, 0, $correlation, $already ? 'booking_link_repeat' : 'quote_accepted_ack');
        }

        return ['ok' => true, 'reason' => $already ? 'already_accepted' : '', 'book_link' => (string) $link['link']];
    }

    if ($action === 'revise') {
        if ((string) ($q->patient_response ?? '') !== 'revision_requested') {
            $CI->db->where('id', (int) $q->id)->where('brand_id', (int) $j->brand_id)->update(db_prefix() . 'se_journey_quotes', [
                'patient_response' => 'revision_requested', 'patient_response_at' => $now, 'patient_response_via' => $via, 'last_updated' => $now,
            ]);
            se_journey_audit((int) $j->brand_id, (int) $j->id, 'quote_revision_requested', 'quote', (string) $q->id, 'via=' . $via);
            se_journey_event($j, 'quote_revision_requested', 'v' . (int) $q->version . ' (' . $via . ')', [], 'patient', null, 'quote', (string) $q->id, $correlation);
            if (in_array((string) $j->state, ['quote_sent', 'quote_accepted'], true)) {
                se_journey_transition($j, 'quote_revision_requested', 'patient_requested_revision', 'patient', null, $correlation);
            }
        }
        se_journey_task($j, 'quote_revision', 'Patient asked for a PRICE REVISION of quote v' . (int) $q->version . ' — prepare a new version or reply from the inbox', 'normal', null, 'q' . (int) $q->id);
        se_journey_send_copy($j, 'quote_revision_ack', [], ['purpose' => 'quote_revision_ack', 'bypass_pause' => true, 'correlation' => $correlation, 'dedup_salt' => 'q' . (int) $q->id]);

        return ['ok' => true, 'reason' => '', 'book_link' => ''];
    }

    return ['ok' => false, 'reason' => 'bad_action', 'book_link' => ''];
}
