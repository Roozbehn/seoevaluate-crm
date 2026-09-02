<?php
/**
 * Patient journey (se_journey) — the logical template registry.
 *
 *   - every definition is shaped the way Meta accepts: sequential {{n}},
 *     samples = placeholders, no variable as the very last token, a sane
 *     variable-to-word ratio, ≤ 1024 characters, no forbidden copy
 *   - the registry refreshes a definition that changed while Meta had not
 *     accepted the old one (submit_failed / rejected / not_submitted) and
 *     never touches an approved or pending row
 *   - the welcome falls back to the start template outside the 24h window
 *   - Meta's error detail is kept (bounded) when a submission is refused
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/journey_fixtures.php';

/* ======================================================================== */
se_group('Journey templates: every definition is Meta-shaped');

$defs = se_journey_template_definitions();
se_eq(12, count($defs), 'twelve logical templates (11 + the out-of-window start)');
se_ok(isset($defs['eyebrow_journey_start_tr']), 'the start template exists for enquiries whose window closed');
se_eq(2, (int) ($defs['eyebrow_photos_retake_tr']['content_version'] ?? 1), 'the retake template is v2 (v1 was refused by Meta)');

foreach ($defs as $tplName => $d) {   // not $name: test files run inside the runner's scope
    $body = (string) $d['body'];
    preg_match_all('/\{\{(\d+)\}\}/', $body, $m);
    $vars = array_map('intval', $m[1]);
    se_eq(range(1, count($vars)), $vars, "$tplName: placeholders are sequential from 1");
    se_eq(count($vars), count($d['samples']), "$tplName: one sample per placeholder");
    foreach ($d['samples'] as $i => $sample) { se_ok(trim((string) $sample) !== '', "$tplName: sample " . ($i + 1) . " is not empty"); }
    se_ok(!preg_match('/\{\{\d+\}\}\s*$/u', $body), "$tplName: body does not end with a variable");
    se_ok(!preg_match('/^\s*\{\{\d+\}\}/u', $body), "$tplName: body does not start with a variable");
    se_ok(!preg_match('/\}\}\s*\{\{/u', $body), "$tplName: no two variables adjacent");
    $words = count(preg_split('/\s+/u', trim(preg_replace('/\{\{\d+\}\}/', '', $body))));
    // Meta accepted 11 words / 2 vars and 13 words / 3 vars (consultation templates, 2026-09-02) and
    // refused 13 words / 4 vars ending in a variable; the floor here keeps new copy clearly inside that.
    se_ok(count($vars) === 0 || $words / count($vars) >= 4, "$tplName: at least 4 words per variable ({$words} words / " . count($vars) . " vars)");
    se_ok(mb_strlen($body) <= 1024, "$tplName: ≤ 1024 characters");
    se_ok(preg_match('/^[a-z0-9_]+$/', $tplName) === 1 && mb_strlen($tplName) <= 512, "$tplName: Meta-safe name");
    se_eq('tr', $d['language'], "$tplName: Turkish");
    se_ok(!preg_match('/garantili|garanti eder|kalıcı|\bDr\.|Doktor/iu', $body), "$tplName: no guarantee/permanent/doctor wording (a 'not a guarantee' disclaimer is fine)");
}

/* ======================================================================== */
se_group('Journey templates: a changed definition refreshes a refused row, never an accepted one');

se_test_seed_journey();
se_test_act_as(10, [], true);
$db = se_test_db();
$n = se_journey_seed_templates(1);
se_eq(12, $n, 'first seeding registers all twelve');
se_eq(12, count($db->rows('tblse_journey_templates')), 'twelve rows');
se_eq(0, se_journey_seed_templates(1), 'a second run changes nothing');

// Simulate what production holds: the v1 retake refused by Meta, another template pending.
foreach ($db->tables['tblse_journey_templates'] as &$row) {
    if ($row['logical_name'] === 'eyebrow_photos_retake_tr') {
        $row['content_version'] = 1; $row['body'] = 'old v1 body {{1}} {{2}} {{3}} {{4}}'; $row['approval_status'] = 'submit_failed'; $row['rejection_reason'] = 'Invalid parameter';
    }
    if ($row['logical_name'] === 'eyebrow_intake_resume_tr') {
        $row['content_version'] = 0; $row['body'] = 'stale body {{1}} {{2}}'; $row['approval_status'] = 'pending'; $row['meta_template_id'] = '123';
    }
}
unset($row);
se_eq(1, se_journey_seed_templates(1), 'exactly one row refreshed');
$retake = null; $resume = null;
foreach ($db->rows('tblse_journey_templates') as $r) { if ($r['logical_name'] === 'eyebrow_photos_retake_tr') { $retake = $r; } if ($r['logical_name'] === 'eyebrow_intake_resume_tr') { $resume = $r; } }
se_eq(2, (int) $retake['content_version'], 'the refused retake row is now v2');
se_eq($defs['eyebrow_photos_retake_tr']['body'], $retake['body'], 'with the v2 body');
se_eq('not_submitted', $retake['approval_status'], 'ready to submit again');
se_eq(null, $retake['rejection_reason'], 'old refusal cleared');
se_eq(3, count(json_decode($retake['placeholders_json'], true)), 'three samples now');
se_eq('stale body {{1}} {{2}}', $resume['body'], 'a PENDING row is what Meta holds — untouched');
se_eq('pending', $resume['approval_status'], 'still pending');

/* ======================================================================== */
se_group('Journey templates: Meta refusal keeps the user-facing detail, bounded');

se_journey_register_template_submitter(function ($waba, $definition) {
    return ['ok' => false, 'error' => 'Invalid parameter [2388042] — Template body has too many variable parameters relative to the message length.'];
});
$r = se_journey_submit_template(1, 'eyebrow_photos_retake_tr', 10);
se_eq(false, $r['ok'], 'refused');
foreach ($db->rows('tblse_journey_templates') as $row) { if ($row['logical_name'] === 'eyebrow_photos_retake_tr') { $retake = $row; } }
se_eq('submit_failed', $retake['approval_status'], 'status submit_failed');
se_ok(strpos((string) $retake['rejection_reason'], '2388042') !== false && strpos((string) $retake['rejection_reason'], 'relative to the message length') !== false, 'the reason carries subcode and detail');

// The submitted definition is what Meta expects: name, language, category, one BODY with examples.
$captured = null;
se_journey_register_template_submitter(function ($waba, $definition) use (&$captured) { $captured = $definition; return ['ok' => true, 'id' => '999', 'status' => 'PENDING', 'category' => 'UTILITY']; });
$r = se_journey_submit_template(1, 'eyebrow_journey_start_tr', 10);
se_eq(true, $r['ok'], 'start template submitted');
se_eq('eyebrow_journey_start_tr', $captured['name'], 'meta name');
se_eq('tr', $captured['language'], 'language');
se_eq('UTILITY', $captured['category'], 'category');
se_eq('BODY', $captured['components'][0]['type'], 'one BODY component');
se_eq([['Ayşe']], $captured['components'][0]['example']['body_text'], 'example values = samples');

/* ======================================================================== */
se_group('Journey templates: the welcome uses the start template outside the window');

se_test_seed_journey();
se_test_act_as(10, [], true);
se_test_wa_deliver(se_test_wa_body('905000000004', 'kaş ekimi fiyat bilgisi alabilir miyim', se_test_wamid(), ['name' => 'Elif']));
$j = se_test_journey_row();
se_eq('new_whatsapp_enquiry', $j->state, 'an organic price question waits for staff (auto-start organic is off)');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'organic_enquiry'; })), 'staff task: start evaluation?');

// Two days later staff press Start: the window is closed.
foreach ($db->tables['tblse_wa_conversations'] as &$c) { $c['window_expires_at'] = date('Y-m-d H:i:s', time() - 86400); }
unset($c);
se_journey_seed_templates(1);
$before = count($GLOBALS['se_wa_sent']);
$r = se_journey_send_welcome(se_test_journey_row(), 'staff:10');
se_eq(false, $r['ok'], 'not approved yet → blocked, not silently dropped');
se_eq('template_not_submitted', $r['reason'], 'names the start template status');
se_eq('new_whatsapp_enquiry', se_test_journey_row()->state, 'state unchanged');

// Approved in the registry AND mirrored from Meta: the template goes out and the journey advances.
foreach ($db->tables['tblse_journey_templates'] as &$row) { if ($row['logical_name'] === 'eyebrow_journey_start_tr') { $row['approval_status'] = 'approved'; } }
unset($row);
$db->seed('tblse_wa_templates', [['id' => 1, 'brand_id' => 1, 'name' => 'eyebrow_journey_start_tr', 'language' => 'tr', 'category' => 'UTILITY', 'approval_state' => 'approved', 'variables' => '1']]);
se_journey_resume(se_test_journey_row(), 10);
$r = se_journey_send_welcome(se_test_journey_row(), 'staff:10');
se_wa_out_drain();
se_eq(true, $r['ok'], 'queued');
se_eq('template', $r['mode'], 'as a template');
$last = end($GLOBALS['se_wa_sent']);
se_eq('eyebrow_journey_start_tr', $last['template'], 'the start template');
se_eq(['Elif'], $last['variables'], 'first name as the only variable');
se_eq('welcome_sent', se_test_journey_row()->state, 'journey moved to welcome_sent');

// The patient replies with the typed keyword → window reopens → normal in-window flow continues.
foreach ($db->tables['tblse_wa_conversations'] as &$c) { $c['window_expires_at'] = date('Y-m-d H:i:s', time() + 86400); }
unset($c);
se_test_wa_deliver(se_test_wa_body('905000000004', 'Değerlendirme Başlat', se_test_wamid()));
se_eq('consent_pending', se_test_journey_row()->state, 'privacy notice + secure link went out in-window after the reply');


/* ======================================================================== */
se_group('Journey templates: Start from the WhatsApp thread (contact without a journey)');

se_test_seed_journey();
se_test_act_as(10, [], true);
$db = se_test_db();
se_journey_seed_templates(1);
foreach ($db->tables['tblse_journey_templates'] as &$row) { if ($row['logical_name'] === 'eyebrow_journey_start_tr') { $row['approval_status'] = 'approved'; } }
unset($row);
$db->seed('tblse_wa_templates', [['id' => 1, 'brand_id' => 1, 'name' => 'eyebrow_journey_start_tr', 'language' => 'tr', 'category' => 'UTILITY', 'approval_state' => 'approved', 'variables' => '1']]);

// A thread that predates the module: conversation row, no journey row.
$db->seed('tblse_wa_conversations', [['id' => 77, 'brand_id' => 1, 'phone_number_id' => SE_TEST_PN, 'wa_user_id' => '905000000005', 'lead_id' => 0, 'client_id' => 0,
    'assigned_staff' => 0, 'unread_count' => 0, 'last_inbound_at' => date('Y-m-d H:i:s', time() - 3 * 86400), 'window_expires_at' => date('Y-m-d H:i:s', time() - 2 * 86400),
    'ctwa_clid' => null, 'referral_json' => null, 'state' => 'open', 'date_created' => date('Y-m-d H:i:s', time() - 3 * 86400), 'last_updated' => null]]);
$conv = (object) $db->tables['tblse_wa_conversations'][0];
se_eq(null, se_journey_find_by_wa(1, '905000000005'), 'no journey for the thread yet');

// Automation off → nothing is created or sent (the reply would go unprocessed).
update_option('se_journey_enabled_1', 0);
$r = se_journey_start_from_conversation($conv, 10);
se_eq('disabled', $r['reason'], 'start refused while the brand automation is off');
se_eq(0, count($db->rows('tblse_journeys')), 'no journey row created');
update_option('se_journey_enabled_1', 1);

// Window closed → journey created, lead created, start template sent.
$sentBefore = count($GLOBALS['se_wa_sent']);
$r = se_journey_start_from_conversation($conv, 10);
se_wa_out_drain();
se_eq(true, $r['ok'], 'started');
se_eq(true, $r['created'], 'journey row created from the thread');
se_eq('template', $r['mode'], 'outside the window the start template went out');
$j = $r['journey'];
se_eq('welcome_sent', $j->state, 'journey is at welcome_sent');
se_eq('organic_whatsapp', $j->source, 'source recorded as organic');
se_eq('staff_start', $j->source_detail, 'with staff_start as the detail');
se_eq(77, (int) $j->wa_conversation_id, 'linked to the thread');
se_ok((int) $j->lead_id > 0, 'a lead exists for the number');
$last = end($GLOBALS['se_wa_sent']);
se_eq('eyebrow_journey_start_tr', $last['template'], 'the start template');
se_eq(1, count(array_filter($db->rows('tblse_journey_events'), function ($e) { return $e['kind'] === 'staff_started'; })), 'audit event: staff_started');

// Pressing Start again does not resend.
$sentBefore = count($GLOBALS['se_wa_sent']);
$r = se_journey_start_from_conversation($conv, 10);
se_eq('already_started', $r['reason'], 'a second Start is refused');
se_eq($sentBefore, count($GLOBALS['se_wa_sent']), 'nothing resent');

// Window open → the interactive welcome instead of the template.
$db->seed('tblse_wa_conversations', [['id' => 78, 'brand_id' => 1, 'phone_number_id' => SE_TEST_PN, 'wa_user_id' => '905000000006', 'lead_id' => 0, 'client_id' => 0,
    'assigned_staff' => 0, 'unread_count' => 0, 'last_inbound_at' => date('Y-m-d H:i:s', time() - 600), 'window_expires_at' => date('Y-m-d H:i:s', time() + 80000),
    'ctwa_clid' => null, 'referral_json' => null, 'state' => 'open', 'date_created' => date('Y-m-d H:i:s', time() - 600), 'last_updated' => null]]);
$conv2 = null; foreach ($db->rows('tblse_wa_conversations') as $c) { if ((int) $c['id'] === 78) { $conv2 = (object) $c; } }
$r = se_journey_start_from_conversation($conv2, 10);
se_wa_out_drain();
se_eq(true, $r['ok'], 'started in-window');
se_eq('inwindow', $r['mode'], 'as a normal in-window message');
$last = end($GLOBALS['se_wa_sent']);
se_eq('interactive', $last['kind'], 'the welcome with reply buttons');

/* ======================================================================== */
se_group('Journey templates: the thread composer offers approved templates while the window is open');

if (!function_exists('form_open')) { function form_open($a, $x = []) { return '<form action="' . $a . '">'; } }
if (!function_exists('form_open_multipart')) { function form_open_multipart($a, $x = []) { return '<form action="' . $a . '" enctype="multipart/form-data">'; } }
if (!function_exists('form_close')) { function form_close() { return '</form>'; } }
if (!function_exists('se_ui_empty')) { function se_ui_empty($t) { echo '<p>' . $t . '</p>'; } }
$tpls = [['name' => 'eyebrow_journey_start_tr', 'language' => 'tr', 'category' => 'UTILITY', 'body' => 'Merhaba {{1}}', 'variables' => '1']];
ob_start(); se_ui_chat_composer(['mode' => 'freeform', 'action' => '/reply/1', 'templates' => $tpls]); $html = ob_get_clean();
se_ok(strpos($html, 'name="kind" value="text"') !== false, 'free-form reply form present');
se_ok(strpos($html, 'se_chat_send_template_toggle') !== false, 'a toggle offers templates');
se_ok(strpos($html, 'name="kind" value="template"') !== false, 'the template form is rendered too');
se_ok(strpos($html, 'eyebrow_journey_start_tr') !== false, 'with the approved template listed');
se_ok(strpos($html, 'id="se-tpl-panel" style="display:none') !== false, 'collapsed by default');
ob_start(); se_ui_chat_composer(['mode' => 'freeform', 'action' => '/reply/1']); $html = ob_get_clean();
se_ok(strpos($html, 'name="kind" value="template"') === false, 'no template form when the brand has none (Instagram, or nothing approved)');
ob_start(); se_ui_chat_composer(['mode' => 'template', 'action' => '/reply/1', 'templates' => $tpls]); $html = ob_get_clean();
se_ok(strpos($html, 'name="kind" value="template"') !== false && strpos($html, 'name="kind" value="text"') === false, 'outside the window only the template form');

/* ======================================================================== */
se_group('Journey templates: Start from a LEAD (website applicant who never wrote on WhatsApp)');

se_test_seed_journey(['leads' => [
    ['id' => 501, 'brand_id' => 1, 'name' => 'Web Aday', 'phonenumber' => '+905000000007', 'email' => 'a@example.com', 'status' => 5, 'source' => 7, 'consent_marketing' => 0, 'consent_ads' => 0],
    ['id' => 502, 'brand_id' => 1, 'name' => 'İzinli Aday', 'phonenumber' => '0 530 000 00 08', 'email' => 'b@example.com', 'status' => 5, 'source' => 7, 'consent_marketing' => 0, 'consent_ads' => 0],
    ['id' => 503, 'brand_id' => 1, 'name' => 'Telefonsuz', 'phonenumber' => '', 'email' => 'c@example.com', 'status' => 5, 'source' => 7, 'consent_marketing' => 1, 'consent_ads' => 0],
]]);
se_test_act_as(10, [], true);
$db = se_test_db();
update_option('se_journey_enabled_1', 1);
se_journey_seed_templates(1);
foreach ($db->tables['tblse_journey_templates'] as &$row) { if ($row['logical_name'] === 'eyebrow_journey_start_tr') { $row['approval_status'] = 'approved'; } }
unset($row);
$db->seed('tblse_wa_templates', [['id' => 1, 'brand_id' => 1, 'name' => 'eyebrow_journey_start_tr', 'language' => 'tr', 'category' => 'UTILITY', 'approval_state' => 'approved', 'variables' => '1']]);

// No contact consent on the form → refused, nothing created, nothing sent.
$sentBefore = count($GLOBALS['se_wa_sent']);
$r = se_journey_start_from_lead(501, 10);
se_eq('contact_consent_missing', $r['reason'], 'a lead without contact consent never receives a template');
se_eq(0, count($db->rows('tblse_wa_conversations')), 'no thread created');
se_eq(0, count($db->rows('tblse_journeys')), 'no journey created');
se_eq($sentBefore, count($GLOBALS['se_wa_sent']), 'nothing sent');

// No phone → refused.
$r = se_journey_start_from_lead(503, 10);
se_eq('no_usable_phone', $r['reason'], 'a lead without a phone cannot be started');

// Website form's contact consent recorded in the ledger (what se_website_lead does) → thread + journey + start template.
se_consent_grant(1, 502, 'marketing', 'website', 'contact_consent', 'true', 0);
$r = se_journey_start_from_lead(502, 10);
se_wa_out_drain();
se_eq(true, $r['ok'], 'started from the lead');
se_eq(true, $r['created'], 'journey created');
se_eq('template', $r['mode'], 'the start template went out (no window: the person never wrote)');
$conv = null; foreach ($db->rows('tblse_wa_conversations') as $c) { $conv = $c; }
se_eq('905300000008', $conv['wa_user_id'], 'thread created on the normalised number (0 530… → 90530…)');
se_eq(SE_TEST_PN, $conv['phone_number_id'], "on the brand's active WhatsApp number");
se_eq(502, (int) $conv['lead_id'], 'linked to the lead');
se_eq(null, $conv['window_expires_at'], 'window closed — nobody wrote yet');
$j = $r['journey'];
se_eq('welcome_sent', $j->state, 'journey at welcome_sent');
se_eq('website_form', $j->source, 'source is the website form');
se_eq('staff_start_from_lead', $j->source_detail, 'started by staff from the lead');
se_eq(502, (int) $j->lead_id, 'the existing lead is reused — no duplicate person');
$last = end($GLOBALS['se_wa_sent']);
se_eq('eyebrow_journey_start_tr', $last['template'], 'the start template');
se_eq(['İzinli'], $last['variables'], "the lead's first name is the placeholder");

// Second press: nothing resent; the reply then continues the normal flow.
$sentBefore = count($GLOBALS['se_wa_sent']);
se_eq('already_started', se_journey_start_from_lead(502, 10)['reason'], 'a second Start is refused');
se_eq($sentBefore, count($GLOBALS['se_wa_sent']), 'nothing resent');
se_test_wa_deliver(se_test_wa_body('905300000008', 'Değerlendirme Başlat', se_test_wamid()));
se_eq('consent_pending', se_test_journey_row()->state, "the lead's reply lands on the same thread and the privacy notice + link go out");
se_eq(1, count($db->rows('tblse_wa_conversations')), 'still one thread (Meta wa_id matched the normalised number)');

// Opted-out person: refused even with consent on file.
foreach ($db->tables['tblse_journeys'] as &$jr) { if ((int) $jr['lead_id'] === 502) { $jr['state'] = 'opted_out'; } }
unset($jr);
se_eq('opted_out', se_journey_start_from_lead(502, 10)['reason'], 'an opted-out person is never re-contacted from the lead page');

/* Leave the shared fixture stores as this suite found them. */
$GLOBALS['SE_JOURNEY_TEMPLATE_SUBMITTER'] = null;
se_test_remove_secret('wa_token');
se_test_remove_secret('wa_app');
se_test_remove_secret('journey_key');
$GLOBALS['SE_WA_TRANSPORT'] = null;
$GLOBALS['SE_MEDIA_FETCHER'] = null;
