<?php
/**
 * Patient journey (se_journey) — the CRM lead follows the journey.
 *
 *   - non-health facts land on the lead: country/city/language, a block of
 *     custom fields (stage, age, contact preference, consents, form date,
 *     photo count, review decision, quote, consultation), a timeline line per
 *     stage, and the pipeline status — forward only, never on a converted lead
 *   - health answers NEVER reach the lead record
 *   - it runs without a staff session (dispatcher, token pages) and can be
 *     switched off per brand
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/journey_fixtures.php';

function se_test_lead_cf($slug)
{
    $db = se_test_db();
    $fid = 0;
    foreach ($db->rows('tblcustomfields') as $f) { if ($f['slug'] === $slug) { $fid = (int) $f['id']; } }
    foreach ($db->rows('tblcustomfieldsvalues') as $v) { if ((int) $v['fieldid'] === $fid && $v['fieldto'] === 'leads') { return (string) $v['value']; } }

    return null;
}

/* ======================================================================== */
se_group('Journey → lead: identity, consents, photos and the stage land on the lead (no health data)');

se_test_journey_reviewed();
$db = se_test_db();
$j  = se_test_journey_row();
$lead = null; foreach ($db->rows('tblleads') as $l) { if ((int) $l['id'] === (int) $j->lead_id) { $lead = $l; } }
se_ok($lead !== null, 'the journey has a lead');
se_eq('Ayşe Örnek', $lead['name'], 'name from the form (existing behaviour)');
se_eq(228, (int) $lead['country'], 'country: the form\'s ISO code → Perfex country id');
se_eq('turkish', $lead['default_language'], 'language: tr → turkish');
se_ok(!empty($lead['lastcontact']), 'last contact set');

$fields = array_column($db->rows('tblcustomfields'), 'slug');
se_eq(count(se_journey_lead_field_definitions()), count($fields), 'the "Hasta yolculuğu" custom fields were created once');
se_eq(0, count(array_filter($db->rows('tblcustomfields'), function ($f) { return $f['fieldto'] !== 'leads' || (int) $f['active'] !== 1 || (int) $f['show_on_table'] !== 0; })), 'all for leads, active, not on the list table');
se_eq('se_journey_state_ready_for_review', se_test_lead_cf('leads_journey_stage'), 'stage (label key in the harness)');
se_eq('34', se_test_lead_cf('leads_journey_age'), 'age');
se_eq('Türkçe', se_test_lead_cf('leads_journey_language'), 'preferred language');
se_eq('Verildi (kvkk-test-v1)', se_test_lead_cf('leads_journey_consent_health'), 'health-data consent with the text version');
se_eq('Verilmedi', se_test_lead_cf('leads_journey_consent_marketing'), 'marketing consent');
se_eq('Verilmedi', se_test_lead_cf('leads_journey_consent_photo'), 'photo publication consent');
se_ok(preg_match('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/', (string) se_test_lead_cf('leads_journey_intake_at')) === 1, 'form date');
se_eq('3', se_test_lead_cf('leads_journey_photos'), 'three photos counted');
se_ok(se_test_lead_cf('leads_journey_synced_at') !== null, 'last sync stamp');

// Health answers never reach the lead: not in a field value, not in a field name, not on the lead row.
$blob = json_encode($db->rows('tblcustomfieldsvalues')) . json_encode($db->rows('tblcustomfields')) . json_encode($lead);
foreach (['aspirin', 'sparse', 'blood_thinners', 'chronic', 'allerg', 'pregnan', 'anesthesia', 'smoking'] as $needle) {
    se_ok(stripos($blob, $needle) === false, "no health answer on the lead record ('$needle')");
}

// One timeline line per stage change, attributed to the journey, no staff id.
$lines = array_filter($db->rows('tbllead_activity_log'), function ($a) use ($j) { return (int) $a['leadid'] === (int) $j->lead_id; });
se_ok(count($lines) >= 5, 'timeline lines for the stages so far (' . count($lines) . ')');
se_eq(0, count(array_filter($lines, function ($a) { return (int) $a['staffid'] !== 0 || $a['full_name'] !== 'Hasta yolculuğu'; })), 'all attributed to the journey');
se_ok(count(array_filter($lines, function ($a) { return $a['description'] === 'Hasta yolculuğu: se_journey_state_ready_for_review'; })) === 1, 'the latest stage line');

/* ======================================================================== */
se_group('Journey → lead: pipeline status moves forward only, never on a converted lead, and can be switched off');

$db->seed('tblleads_status', [
    ['id' => 5, 'name' => 'New', 'statusorder' => 10], ['id' => 6, 'name' => 'WhatsApp Engaged', 'statusorder' => 30],
    ['id' => 7, 'name' => 'Qualified', 'statusorder' => 40], ['id' => 8, 'name' => 'Photos Received', 'statusorder' => 50],
    ['id' => 9, 'name' => 'Quote Sent', 'statusorder' => 60], ['id' => 10, 'name' => 'Consultation Booked', 'statusorder' => 70],
    ['id' => 1, 'name' => 'Customer', 'statusorder' => 1000],
]);
$r = se_journey_sync_lead(se_test_journey_row(), 'test');
se_eq(true, $r['ok'], 'sync ok');
$leadRow = function () use ($db, $j) { foreach ($db->rows('tblleads') as $l) { if ((int) $l['id'] === (int) $j->lead_id) { return $l; } } return null; };
se_eq(8, (int) $leadRow()['status'], 'ready_for_review → "Photos Received"');
se_eq(5, (int) $leadRow()['last_lead_status'], 'previous status kept');
se_ok(!empty($leadRow()['last_status_change']), 'status change stamped');
$statusLines = array_filter($db->rows('tbllead_activity_log'), function ($a) { return $a['description'] === 'not_lead_activity_status_updated'; });
se_eq(1, count($statusLines), 'Perfex-style status line on the timeline');
se_eq(['Hasta yolculuğu', 'New', 'Photos Received'], unserialize(end($statusLines)['additional_data']), 'who / from / to');

// A staff member already moved the lead further along: the journey never pulls it back.
$db->where('id', (int) $j->lead_id)->update('tblleads', ['status' => 9]);
se_journey_sync_lead(se_test_journey_row(), 'test');
se_eq(9, (int) $leadRow()['status'], 'a later stage set by staff is left alone');
se_eq(1, count(array_filter($db->rows('tbllead_activity_log'), function ($a) { return $a['description'] === 'not_lead_activity_status_updated'; })), 'no second status line');

// A converted lead keeps its status whatever the journey does.
$db->where('id', (int) $j->lead_id)->update('tblleads', ['status' => 1, 'date_converted' => date('Y-m-d H:i:s')]);
se_journey_sync_lead(se_test_journey_row(), 'test');
se_eq(1, (int) $leadRow()['status'], 'converted lead untouched');
$db->where('id', (int) $j->lead_id)->update('tblleads', ['status' => 5, 'date_converted' => null]);

// Status sync off: fields still flow, the status stays.
$GLOBALS['se_test']['options']['se_journey_lead_sync_status_1'] = 0;
se_journey_sync_lead(se_test_journey_row(), 'test');
se_eq(5, (int) $leadRow()['status'], 'status sync off → status untouched');
se_eq('se_journey_state_ready_for_review', se_test_lead_cf('leads_journey_stage'), 'fields still written');
$GLOBALS['se_test']['options']['se_journey_lead_sync_status_1'] = 1;

// Whole sync off.
$GLOBALS['se_test']['options']['se_journey_lead_sync_1'] = 0;
se_eq(['ok' => false, 'reason' => 'disabled', 'changed' => []], se_journey_sync_lead(se_test_journey_row(), 'test'), 'switched off per brand');
$GLOBALS['se_test']['options']['se_journey_lead_sync_1'] = 1;

/* ======================================================================== */
se_group('Journey → lead: quote, acceptance and the consultation follow, also without a staff session');

se_test_act_as(10, [], true);
se_journey_review_open(se_test_journey_row(), 10);
se_journey_review_save(se_test_journey_row(), ['decision' => 'provisionally_suitable'], 10);
se_eq('se_journey_decision_provisionally_suitable', se_test_lead_cf('leads_journey_review'), 'review decision');
se_journey_quote_draft(se_test_journey_row(), ['currency' => 'EUR', 'amount_min' => '1500', 'amount_max' => '2200', 'show_amount' => 1, 'recommendation' => 'procedure_after_consultation'], 10);
$q = $db->rows('tblse_journey_quotes')[0];
se_journey_quote_approve((int) $q['id'], 10);
se_journey_quote_send((int) $q['id'], 10);
se_wa_out_drain();
se_eq(9, (int) $leadRow()['status'], 'quote_sent → "Quote Sent"');
se_ok(strpos((string) se_test_lead_cf('leads_journey_quote'), 'v1 · 1.500–2.200 EUR · gönderildi ') === 0, 'quote summary with the shown range: ' . se_test_lead_cf('leads_journey_quote'));

// The patient accepts from WhatsApp: the dispatcher has no staff session.
se_test_act_as(0, [], false);
se_authz_reset_cache();
$GLOBALS['se_test']['is_admin_calls_without_session'] = 0;
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'jr_quote_accept', 'title' => 'Teklifi Kabul Et']]]));
se_eq('quote_accepted', se_test_journey_row()->state, 'accepted');
se_ok(strpos((string) se_test_lead_cf('leads_journey_quote'), '· kabul edildi ') !== false, 'acceptance on the lead');
se_eq('se_journey_state_quote_accepted', se_test_lead_cf('leads_journey_stage'), 'stage updated by the dispatcher path');
se_eq(0, (int) $GLOBALS['se_test']['is_admin_calls_without_session'], 'no is_admin() query without a session');

// A hidden amount is never written to the lead.
$db->tables['tblse_journey_quotes'][0]['show_amount'] = 0;
se_journey_sync_lead(se_test_journey_row(), 'test');
se_ok(strpos((string) se_test_lead_cf('leads_journey_quote'), 'EUR') === false, 'hidden amount → no figure on the lead');

// Booking from the calendar page (no session) → consultation on the lead + "Consultation Booked".
$avail = se_journey_booking_slots(1);
$r = se_journey_booking_pick(se_test_journey_row(), $avail['slots'][0]['start'], 'page');
se_wa_out_drain();
se_eq(true, $r['ok'], 'booked');
se_eq(date('d.m.Y H:i', strtotime($avail['slots'][0]['start'])) . ' · klinikte · planlandı', se_test_lead_cf('leads_journey_consultation'), 'consultation summary');
se_eq(10, (int) $leadRow()['status'], 'consultation_booked → "Consultation Booked"');

// Staff confirm the slot: no state change, the lead still follows.
se_test_act_as(10, [], true);
se_authz_reset_cache();
$j = se_test_journey_row();
se_journey_appointment_update($j, (int) $j->consultation_appointment_id, ['status' => 'confirmed'], 10);
se_eq(date('d.m.Y H:i', strtotime($avail['slots'][0]['start'])) . ' · klinikte · onaylandı', se_test_lead_cf('leads_journey_consultation'), 'confirmation reflected');

// The manual button.
$r = se_journey_sync_lead(se_test_journey_row(), 'staff');
se_eq(true, $r['ok'], 'manual sync ok');
se_ok(strpos((string) se_test_lead_cf('leads_journey_synced_at'), '· staff') !== false, 'stamped with the trigger');
