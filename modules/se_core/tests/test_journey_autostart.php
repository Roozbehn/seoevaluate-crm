<?php
/**
 * Patient journey (se_journey) — website leads start their journey on
 * arrival when the brand switch is on (owner decision 2026-09-03: "switch it
 * to automatically", production, sandbox off).
 *
 *   - Perfex `lead_created` → se_journey_on_lead_created (priority 30, after
 *     se_core stamped the brand); only a lead with website_lead_id qualifies
 *   - runs with NO staff session (the website endpoint is server-to-server):
 *     nothing staff-scoped, no is_admin() query
 *   - the approved start template goes to the number on the form; the lead's
 *     own timeline says what happened (also when blocked, with the reason)
 *   - switch off / staff-created lead / already started → nothing happens
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/journey_fixtures.php';

function se_test_autostart_seed()
{
    se_test_seed_journey();
    se_test_act_as(10, [], true);
    $db = se_test_db();
    update_option('se_journey_enabled_1', 1);
    se_journey_seed_templates(1);
    foreach ($db->tables['tblse_journey_templates'] as &$row) { if ($row['logical_name'] === 'eyebrow_journey_start_tr') { $row['approval_status'] = 'approved'; } }
    unset($row);
    $db->seed('tblse_wa_templates', [['id' => 1, 'brand_id' => 1, 'name' => 'eyebrow_journey_start_tr', 'language' => 'tr', 'category' => 'UTILITY', 'approval_state' => 'approved', 'variables' => '1']]);
    // What se_website_lead_upsert writes: the row + purpose=marketing consent from the form's checkbox.
    $db->tables['tblleads'][] = ['id' => 800, 'brand_id' => 1, 'name' => 'Web Aday', 'phonenumber' => '+905000000051', 'email' => 'w@example.com', 'status' => 5, 'source' => 7,
        'consent_marketing' => 1, 'consent_ads' => 0, 'lost' => 0, 'junk' => 0, 'lastcontact' => null, 'default_language' => '', 'country' => 0, 'city' => '', 'website_lead_id' => '11111111-2222-3333-4444-555555555555'];
    se_consent_grant(1, 800, 'marketing', 'website', 'consultation_contact_permission', 'yes', 0);
    // A lead a staff member typed in by hand: no website id.
    $db->tables['tblleads'][] = ['id' => 801, 'brand_id' => 1, 'name' => 'Elle Girilen', 'phonenumber' => '+905000000052', 'email' => 'm@example.com', 'status' => 5, 'source' => 7,
        'consent_marketing' => 1, 'consent_ads' => 0, 'lost' => 0, 'junk' => 0, 'lastcontact' => null, 'default_language' => '', 'country' => 0, 'city' => '', 'website_lead_id' => null];
    se_consent_grant(1, 801, 'marketing', 'website', 'consultation_contact_permission', 'yes', 0);
}

/* ======================================================================== */
se_group('Journey auto-start: off by default — a website lead waits for staff');

se_test_autostart_seed();
$db = se_test_db();
se_ok(strpos((string) file_get_contents(__DIR__ . '/../../se_journey/se_journey.php'), "add_action('lead_created', 'se_journey_on_lead_created', 30)") !== false, 'the module listens to lead_created after se_core (priority 30)');
se_eq(false, se_journey_auto_start_website(1), 'default: off');
$sentBefore = count($GLOBALS['se_wa_sent']);
$r = se_journey_on_lead_created(800);
se_eq('auto_start_off', $r['reason'], 'nothing happens while the switch is off');
se_eq(0, count($db->rows('tblse_journeys')), 'no journey');
se_eq($sentBefore, count($GLOBALS['se_wa_sent']), 'nothing sent');

/* ======================================================================== */
se_group('Journey auto-start: on — the start template goes out on arrival, without a staff session');

update_option('se_journey_auto_start_website_1', 1);
se_eq(true, se_journey_auto_start_website(1), 'switch on');
se_test_act_as(0, [], false);           // the website endpoint: no session at all
se_authz_reset_cache();
$GLOBALS['se_test']['is_admin_calls_without_session'] = 0;
$sentBefore = count($GLOBALS['se_wa_sent']);
$r = se_journey_on_lead_created(['lead_id' => 800, 'web_to_lead_form' => false]);   // the array shape Perfex forms use is accepted too
se_wa_out_drain();
se_eq(true, $r['ok'], 'journey started automatically');
se_eq(true, $r['created'], 'a new journey');
se_eq('template', $r['mode'], 'the person never wrote: the approved start template');
se_eq(0, (int) $GLOBALS['se_test']['is_admin_calls_without_session'], 'no is_admin() query without a session');
se_eq($sentBefore + 1, count($GLOBALS['se_wa_sent']), 'one send');
$last = end($GLOBALS['se_wa_sent']);
se_eq(['template', 'eyebrow_journey_start_tr'], [$last['kind'], $last['template']], 'the start template');
se_eq(['Web'], $last['variables'], 'with the first name from the form');
$j = se_test_journey_row();
se_eq([800, 'welcome_sent', 'website_form', 'auto_start_website'], [(int) $j->lead_id, $j->state, $j->source, $j->source_detail], 'journey linked to the lead, at welcome_sent, source website form / auto start');
$kinds = array_column($db->rows('tblse_journey_events'), 'kind');
se_ok(in_array('auto_started', $kinds, true) && !in_array('staff_started', $kinds, true), 'the timeline says it was started automatically, not by a staff member');
$conv = null; foreach ($db->rows('tblse_wa_conversations') as $c) { $conv = $c; }
se_eq(800, (int) $conv['lead_id'], 'the thread is linked to the lead');
$audit = array_filter($db->rows('tblse_journey_audit'), function ($a) { return $a['action'] === 'auto_start'; });
se_eq(1, count($audit), 'audited');
$log = array_filter($db->rows('tbllead_activity_log'), function ($l) { return (int) $l['leadid'] === 800 && strpos((string) $l['description'], 'otomatik başlatıldı') !== false; });
se_eq(1, count($log), 'the lead timeline says the journey was started automatically');

// Fired again for the same lead (a duplicate hook call): nothing more.
$sentBefore = count($GLOBALS['se_wa_sent']);
se_eq('already_started', se_journey_on_lead_created(800)['reason'], 'a second lead_created for the same lead does nothing');
se_eq($sentBefore, count($GLOBALS['se_wa_sent']), 'nothing resent');
se_eq(1, count($db->rows('tblse_journeys')), 'still one journey');

// The patient replies: the usual flow continues on the same journey.
se_test_wa_deliver(se_test_wa_body('905000000051', 'Değerlendirme Başlat', se_test_wamid()));
se_eq('consent_pending', se_test_journey_row()->state, "the patient's reply continues the journey (privacy notice + link)");

/* ======================================================================== */
se_group('Journey auto-start: only website leads; a blocked start is written on the lead timeline');

se_test_act_as(0, [], false);
se_authz_reset_cache();
$sentBefore = count($GLOBALS['se_wa_sent']);
se_eq('not_website_lead', se_journey_on_lead_created(801)['reason'], 'a lead typed in by staff is left for staff to start');
se_eq('no_lead', se_journey_on_lead_created(0)['reason'], 'a bad id does nothing');
se_eq('not_website_lead', se_journey_on_lead_created(9999)['reason'], 'an unknown id does nothing');
se_eq($sentBefore, count($GLOBALS['se_wa_sent']), 'nothing sent');

// A website lead whose form consent is missing (should not happen — the form requires it) is blocked, visibly.
$db->tables['tblleads'][] = ['id' => 802, 'brand_id' => 1, 'name' => 'İzinsiz', 'phonenumber' => '+905000000053', 'email' => 'n@example.com', 'status' => 5, 'source' => 7,
    'consent_marketing' => 0, 'consent_ads' => 0, 'lost' => 0, 'junk' => 0, 'lastcontact' => null, 'default_language' => '', 'country' => 0, 'city' => '', 'website_lead_id' => '99999999-2222-3333-4444-555555555555'];
$r = se_journey_on_lead_created(802);
se_eq('contact_consent_missing', $r['reason'], 'blocked: no contact consent');
$log = array_filter($db->rows('tbllead_activity_log'), function ($l) { return (int) $l['leadid'] === 802 && strpos((string) $l['description'], 'başlatılamadı') !== false && strpos((string) $l['description'], 'contact_consent_missing') !== false; });
se_eq(1, count($log), 'the lead timeline names the reason and points staff at the Start button');
$audit = array_filter($db->rows('tblse_journey_audit'), function ($a) { return $a['action'] === 'auto_start_blocked'; });
se_eq(1, count($audit), 'audited as blocked');
se_eq($sentBefore, count($GLOBALS['se_wa_sent']), 'nothing sent');

// Brand switched off entirely: nothing.
update_option('se_journey_enabled_1', 0);
$db->tables['tblleads'][] = ['id' => 803, 'brand_id' => 1, 'name' => 'Kapalı', 'phonenumber' => '+905000000054', 'email' => 'k@example.com', 'status' => 5, 'source' => 7,
    'consent_marketing' => 1, 'consent_ads' => 0, 'lost' => 0, 'junk' => 0, 'lastcontact' => null, 'default_language' => '', 'country' => 0, 'city' => '', 'website_lead_id' => '88888888-2222-3333-4444-555555555555'];
se_eq('auto_start_off', se_journey_on_lead_created(803)['reason'], 'journey disabled for the brand → off');
update_option('se_journey_enabled_1', 1);

/* ======================================================================== */
se_group('Journey auto-start: the settings form carries the switch');

se_test_act_as(10, [], true);
$view = (string) file_get_contents(__DIR__ . '/../../se_journey/views/settings.php');
se_ok(strpos($view, 'name="auto_website"') !== false, 'checkbox in the flags form');
$ctrl = (string) file_get_contents(__DIR__ . '/../../se_journey/controllers/Se_journey.php');
se_ok(strpos($ctrl, "update_option('se_journey_auto_start_website_' . \$brand") !== false, 'saved by save_settings (flags)');
foreach (['english', 'turkish'] as $lang) {
    $l = (string) file_get_contents(__DIR__ . '/../../se_journey/language/' . $lang . '/se_journey_lang.php');
    se_ok(strpos($l, "se_journey_flag_auto_website'") !== false && strpos($l, "se_journey_flag_auto_website_hint'") !== false, $lang . ' labels present');
}
