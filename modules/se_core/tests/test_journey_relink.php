<?php
/**
 * Patient journey (se_journey) — a CRM lead deleted from the Leads screen
 * must not strand the patient.
 *
 * What happened in production: the website form created lead #A, the journey
 * was started from it, a staff member deleted #A in the CRM, the patient
 * submitted the form again and the pipeline re-created the lead as #B. The
 * journey still pointed at #A (gone) and "Start journey" on #B answered
 * "already started" — the patient was invisible on the Leads screen and the
 * journey wrote its stage to a lead that no longer existed.
 *
 *   - after_lead_deleted clears the link, logs it and opens a staff task
 *   - Start journey on the new lead RE-LINKS the existing journey (and its
 *     thread) instead of refusing — also when the old id was never cleared
 *   - the new lead is brought up to date at once (stage, custom fields)
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/journey_fixtures.php';

/* ======================================================================== */
se_group('Journey relink: a deleted CRM lead is detached, a staff task opens, the hook is registered');

se_test_seed_journey(['leads' => [
    ['id' => 700, 'brand_id' => 1, 'name' => 'Web Aday', 'phonenumber' => '+905000000031', 'email' => 'w@example.com', 'status' => 5, 'source' => 7, 'consent_marketing' => 1, 'consent_ads' => 0, 'lost' => 0, 'junk' => 0, 'lastcontact' => null, 'default_language' => '', 'country' => 0, 'city' => ''],
]]);
se_test_act_as(10, [], true);
$db = se_test_db();
update_option('se_journey_enabled_1', 1);
se_journey_seed_templates(1);
foreach ($db->tables['tblse_journey_templates'] as &$row) { if ($row['logical_name'] === 'eyebrow_journey_start_tr') { $row['approval_status'] = 'approved'; } }
unset($row);
$db->seed('tblse_wa_templates', [['id' => 1, 'brand_id' => 1, 'name' => 'eyebrow_journey_start_tr', 'language' => 'tr', 'category' => 'UTILITY', 'approval_state' => 'approved', 'variables' => '1']]);

se_ok(strpos((string) file_get_contents(__DIR__ . '/../../se_journey/se_journey.php'), "add_action('after_lead_deleted', 'se_journey_on_lead_deleted')") !== false, 'the module listens to after_lead_deleted (init file is not loaded by the harness)');

$r = se_journey_start_from_lead(700, 10);
se_wa_out_drain();
se_eq(true, $r['ok'], 'journey started from the website lead');
$j = se_test_journey_row();
se_eq(700, (int) $j->lead_id, 'linked to lead #700');
$conv = null; foreach ($db->rows('tblse_wa_conversations') as $c) { $conv = $c; }
se_eq(700, (int) $conv['lead_id'], 'the thread is linked too');

// The staff member deletes the lead in the CRM (Perfex removes the row, then fires the hook).
$db->tables['tblleads'] = array_values(array_filter($db->tables['tblleads'], function ($l) { return (int) $l['id'] !== 700; }));
se_eq(1, se_journey_on_lead_deleted(700), 'one journey detached');
$j = se_test_journey_row();
se_eq(0, (int) $j->lead_id, 'the journey no longer points at the deleted lead');
se_eq('welcome_sent', $j->state, 'the journey itself is untouched');
$conv = null; foreach ($db->rows('tblse_wa_conversations') as $c) { $conv = $c; }
se_eq(0, (int) $conv['lead_id'], 'the thread link is cleared as well');
$kinds = array_column($db->rows('tblse_journey_events'), 'kind');
se_ok(in_array('lead_deleted', $kinds, true), 'the timeline says the lead was deleted');
$tasks = array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'lead_deleted'; });
se_eq(1, count($tasks), 'a staff task asks for the patient\'s current lead to be linked');
se_eq(['ok' => false, 'reason' => 'no_lead', 'changed' => []], se_journey_sync_lead($j, 'test'), 'lead sync is a no-op while detached — nothing is written to a recycled id');
se_eq(0, se_journey_on_lead_deleted(700), 'idempotent');
se_eq(0, se_journey_on_lead_deleted(0), 'a bad id does nothing');

/* ======================================================================== */
se_group('Journey relink: the same number re-created as a new lead re-links the existing journey');

// The website pipeline re-creates the lead under a new id (same phone).
$db->tables['tblleads'][] = ['id' => 701, 'brand_id' => 1, 'name' => 'Web Aday', 'phonenumber' => '+905000000031', 'email' => 'w@example.com', 'status' => 5, 'source' => 7, 'consent_marketing' => 1, 'consent_ads' => 0, 'lost' => 0, 'junk' => 0, 'lastcontact' => null, 'default_language' => '', 'country' => 0, 'city' => ''];
$sentBefore = count($GLOBALS['se_wa_sent']);
$r = se_journey_start_from_lead(701, 10);
se_wa_out_drain();
se_eq('relinked', $r['reason'], 'Start journey on the new lead re-links instead of refusing');
se_eq(false, $r['created'], 'no second journey');
se_eq(1, count($db->rows('tblse_journeys')), 'still one journey for the number');
$j = se_test_journey_row();
se_eq(701, (int) $j->lead_id, 'the journey now points at the new lead');
$conv = null; foreach ($db->rows('tblse_wa_conversations') as $c) { $conv = $c; }
se_eq(701, (int) $conv['lead_id'], 'and so does the thread');
se_eq($sentBefore, count($GLOBALS['se_wa_sent']), 'nothing was re-sent to the patient');
$kinds = array_column($db->rows('tblse_journey_events'), 'kind');
se_ok(in_array('lead_linked', $kinds, true), 'the timeline records the link');
$stage = null;
foreach ($db->rows('tblcustomfields') as $f) { if ($f['slug'] === 'leads_journey_stage') { foreach ($db->rows('tblcustomfieldsvalues') as $v) { if ((int) $v['fieldid'] === (int) $f['id'] && (int) $v['relid'] === 701) { $stage = $v['value']; } } } }
se_ok($stage !== null && $stage !== '', 'the new lead received the journey stage at once');
se_eq('already_started', se_journey_start_from_lead(701, 10)['reason'], 'a second Start on the linked lead is the usual refusal');

/* ======================================================================== */
se_group('Journey relink: an old link that was never cleared (deleted before the hook existed) re-links too');

foreach ($db->tables['tblse_journeys'] as &$jr) { $jr['lead_id'] = 699; }   // points at a lead that does not exist
unset($jr);
foreach ($db->tables['tblse_wa_conversations'] as &$c) { $c['lead_id'] = 699; }
unset($c);
$r = se_journey_start_from_lead(701, 10);
se_eq('relinked', $r['reason'], 'a dangling link is replaced');
se_eq(701, (int) se_test_journey_row()->lead_id, 'journey → new lead');
$conv = null; foreach ($db->rows('tblse_wa_conversations') as $c) { $conv = $c; }
se_eq(701, (int) $conv['lead_id'], 'thread → new lead');

// A journey whose lead DOES exist is never moved by a Start on another lead with the same number.
$db->tables['tblleads'][] = ['id' => 702, 'brand_id' => 1, 'name' => 'Web Aday', 'phonenumber' => '+905000000031', 'email' => 'w@example.com', 'status' => 5, 'source' => 7, 'consent_marketing' => 1, 'consent_ads' => 0, 'lost' => 0, 'junk' => 0, 'lastcontact' => null, 'default_language' => '', 'country' => 0, 'city' => ''];
se_eq('already_started', se_journey_start_from_lead(702, 10)['reason'], 'a duplicate lead for a linked, existing lead is refused as before');
se_eq(701, (int) se_test_journey_row()->lead_id, 'the link stays with the existing lead');
se_eq(false, se_journey_relink_lead(se_test_journey_row(), 9999, 10), 'relink to a lead that does not exist is refused');
se_eq(false, se_journey_relink_lead(se_test_journey_row(), 0, 10), 'relink to nothing is refused');

/* ======================================================================== */
se_group('Journey restart: a later journey on the SAME thread gets its own welcome (idempotency is per journey)');

// Production 2026-09-03: after the test purge the owner started a new journey on
// his old thread. The welcome was "already queued": the outbound idempotency
// key is (thread, kind, content) and the PREVIOUS journey's welcome row still
// existed — nothing was sent. The journey id is part of the key now.
se_test_seed_journey(['leads' => [
    ['id' => 710, 'brand_id' => 1, 'name' => 'Web Aday', 'phonenumber' => '+905000000041', 'email' => 'r@example.com', 'status' => 5, 'source' => 7, 'consent_marketing' => 1, 'consent_ads' => 0, 'lost' => 0, 'junk' => 0, 'lastcontact' => null, 'default_language' => '', 'country' => 0, 'city' => ''],
]]);
se_test_act_as(10, [], true);
$db = se_test_db();
update_option('se_journey_enabled_1', 1);
se_journey_seed_templates(1);
foreach ($db->tables['tblse_journey_templates'] as &$row) { if ($row['logical_name'] === 'eyebrow_journey_start_tr') { $row['approval_status'] = 'approved'; } }
unset($row);
$db->seed('tblse_wa_templates', [['id' => 1, 'brand_id' => 1, 'name' => 'eyebrow_journey_start_tr', 'language' => 'tr', 'category' => 'UTILITY', 'approval_state' => 'approved', 'variables' => '1']]);

// First journey: the patient wrote (window open) → welcome with buttons, in-window.
se_test_wa_deliver(se_test_wa_body('905000000041', 'kaş ekimi hakkında bilgi almak istiyorum', se_test_wamid(), ['name' => 'Rana']));
$first = se_test_journey_row();
$r = se_journey_send_welcome($first, 'staff:10');
se_wa_out_drain();
se_eq(['inwindow', ''], [$r['mode'], $r['reason']], 'first journey: welcome queued in-window');
$sentAfterFirst = count($GLOBALS['se_wa_sent']);

// The journey is purged (rows removed) while the thread and its outbound history stay — exactly the purge.
$db->tables['tblse_journeys'] = [];
$db->tables['tblse_journey_events'] = [];
$db->tables['tblse_journey_transitions'] = [];

// A new journey on the same thread, from the (new) lead.
$r = se_journey_start_from_lead(710, 10);
se_wa_out_drain();
se_eq(true, $r['ok'], 'second journey started on the same thread');
se_eq(true, $r['created'], 'a new journey row');
se_eq('inwindow', $r['mode'], 'window still open → in-window welcome');
se_eq($sentAfterFirst + 1, count($GLOBALS['se_wa_sent']), 'the welcome went out AGAIN — not "already queued" by the first journey\'s row');
$ev = array_filter($db->rows('tblse_journey_events'), function ($e) { return $e['kind'] === 'wa_outbound'; });
se_ok(!in_array(true, array_map(function ($e) { return strpos((string) $e['summary'], 'already queued') !== false; }, $ev), true), 'the timeline does not say "already queued"');
$second = se_test_journey_row();
// Within ONE journey the same message is still one row.
$sentBefore = count($GLOBALS['se_wa_sent']);
$r = se_journey_send_welcome($second, 'staff:10');
se_wa_out_drain();
se_eq('duplicate', $r['reason'], 'the same welcome twice in one journey is still deduplicated');
se_eq($sentBefore, count($GLOBALS['se_wa_sent']), 'nothing resent');
