<?php
/**
 * Patient journey (se_journey): the WhatsApp → CRM path, end to end, on the
 * REAL webhook pipeline (store → claim → process → listener) with a fixture
 * transport and a fixture media fetcher. No network, no real patient data:
 * the "patient" is a synthetic number and the photos are generated in GD.
 *
 * Acceptance criteria covered here: 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12,
 * 14, 15, 16, 17, 19 (see docs/WHATSAPP_EYEBROW_PATIENT_JOURNEY.md).
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/journey_fixtures.php';

/* ======================================================================== */
se_group('Journey: exact pre-filled message creates ONE lead + journey and ONE welcome');

se_test_seed_journey();
se_test_act_as(10, [], true);

$out = se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid()));
se_eq(200, $out['status'], 'the signed webhook is accepted');

$db = se_test_db();
se_eq(1, count($db->rows('tblleads')), 'exactly one CRM lead was created');
se_eq(1, count($db->rows('tblse_journeys')), 'exactly one journey was created');
$j = se_test_journey_row();
se_eq('instagram_prefilled_link', $j->source, 'source is the Instagram pre-filled link');
se_eq('exact', $j->source_confidence, 'with exact confidence');
se_eq('welcome_sent', $j->state, 'state advanced to welcome_sent');
se_eq(1, (int) $db->rows('tblse_wa_conversations')[0]['lead_id'], 'the WhatsApp thread is linked to the lead');
se_eq('+' . SE_TEST_PATIENT, $db->rows('tblleads')[0]['phonenumber'], 'lead phone stored as E.164');
se_eq(null, $db->rows('tblleads')[0]['utm_campaign'], 'no campaign id is fabricated for a text-only match');

$welcome = array_values(array_filter($GLOBALS['se_wa_sent'], function ($m) { return $m['kind'] !== 'template'; }));
se_eq(1, count($welcome), 'exactly one welcome message went through the transport');
se_eq('interactive', $welcome[0]['kind'], 'the welcome is an interactive reply-button message');
se_eq(3, count($welcome[0]['payload']['buttons']), 'with the three options');
se_eq('Değerlendirme Başlat', $welcome[0]['payload']['buttons'][0]['title'], 'first button: start evaluation (20-char Meta limit)');
foreach ($welcome[0]['payload']['buttons'] as $b) { se_ok(mb_strlen($b['title']) <= 20, 'button "' . $b['title'] . '" respects the 20-char title limit'); }
se_ok(mb_strlen($welcome[0]['body']) <= 1024, 'interactive body respects the 1024-char limit');
se_ok(strpos($welcome[0]['body'], 'otomatik danışmanlık asistanıyım') !== false, 'the bot identifies itself as automated');
se_ok(strpos($welcome[0]['body'], 'Merhaba Test') !== false, 'greets by the WhatsApp profile first name');
se_ok(stripos($welcome[0]['body'], 'Dr.') === false && stripos($welcome[0]['body'], 'garanti') === false, 'no title claim, no guarantee wording');

$transitions = $db->rows('tblse_journey_transitions');
se_eq(2, count($transitions), 'two immutable transitions: creation and welcome');
se_eq('patient', $transitions[0]['actor_type'], 'creation edge is attributed to the patient');
se_eq('wamid.T1', $transitions[0]['correlation_id'], 'with the inbound wamid as correlation id');

/* ======================================================================== */
se_group('Journey: duplicate webhook delivery is fully idempotent');

$before = [count($db->rows('tblse_journeys')), count($db->rows('tblleads')), count($db->rows('tblse_wa_messages')),
           count($db->rows('tblse_journey_transitions')), count($GLOBALS['se_wa_sent'])];
$dup = se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, 'wamid.T1'));
se_eq('duplicate', $dup['reason'], 'the identical body is recognised as a duplicate');
se_eq($before, [count($db->rows('tblse_journeys')), count($db->rows('tblleads')), count($db->rows('tblse_wa_messages')),
                count($db->rows('tblse_journey_transitions')), count($GLOBALS['se_wa_sent'])],
    'no duplicate journey, lead, message, transition or send');

// Same wamid, different body bytes (Meta re-sends with a new timestamp): message dedup on wamid holds.
$dup2 = se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, 'wamid.T1', [], time() + 5));
se_eq('accepted', $dup2['reason'], 'a re-signed redelivery with a new timestamp is stored');
se_eq($before, [count($db->rows('tblse_journeys')), count($db->rows('tblleads')), count($db->rows('tblse_wa_messages')),
                count($db->rows('tblse_journey_transitions')), count($GLOBALS['se_wa_sent'])],
    'but the duplicate wamid never reaches the listener: still nothing duplicated');

/* ======================================================================== */
se_group('Journey: invalid signature is rejected and audited safely');

$badBody = se_test_wa_body('905000000009', SE_JOURNEY_PREFILLED_MESSAGE, 'wamid.BAD');
$bad = se_test_wa_deliver($badBody, 'wrong-secret');
se_eq(401, $bad['status'], 'a wrongly signed webhook is refused with 401');
se_eq('bad_signature', $bad['reason'], 'with the bad_signature reason');
se_eq(0, count(array_filter($db->rows('tblse_wa_webhook_events'), function ($e) { return strpos($e['payload'], 'wamid.BAD') !== false; })),
    'nothing from the forged body was stored');
se_eq(1, count($db->rows('tblse_journeys')), 'no journey was created from it');
se_ok(strpos(json_encode($GLOBALS['se_test']['activity']), '905000000009') === false, 'the forged sender never reaches the activity log');

/* ======================================================================== */
se_group('Journey: punctuation/case/whitespace variants match without a duplicate');

se_test_seed_journey();
$variants = [
    "MERHABA, KAŞ EKİMİ HAKKINDA FİYAT VE DEĞERLENDİRME BİLGİSİ ALMAK İSTİYORUM.",
    "merhaba kas ekimi hakkinda fiyat ve degerlendirme bilgisi almak istiyorum",
    "Merhaba,   kaş ekimi hakkında fiyat ve değerlendirme bilgisi almak istiyorum!!! 🙏",
    "Merhaba, kaş ekimi hakkında fiyat ve degerlendirme bilgisi almak istiyorum",
];
foreach ($variants as $i => $text) {
    $src = se_journey_detect_source($text, null);
    se_eq('instagram_prefilled_link', $src['source'], 'variant ' . ($i + 1) . ' is recognised as the pre-filled link');
}
$weak = se_journey_detect_source('kaş ekimi fiyatı ne kadar?', null);
se_eq('instagram_manual_handoff', $weak['source'], 'a manual paraphrase is a weak handoff signal, not a prefilled match');
$org = se_journey_detect_source('Merhaba, randevumu değiştirmek istiyorum', null);
se_eq('organic_whatsapp', $org['source'], 'an unrelated message is organic');

se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, $variants[2], se_test_wamid()));
se_eq(1, count($db->rows('tblse_journeys')), 'variant creates one journey');
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Bir sorum daha var', se_test_wamid()));
se_eq(1, count($db->rows('tblse_journeys')), 'a second message from the same number does NOT create a second journey');
se_eq(1, count($db->rows('tblleads')), 'nor a second lead');
se_eq(2, count(array_filter($db->rows('tblse_journey_events'), function ($e) { return $e['kind'] === 'wa_inbound'; })), 'the timeline holds both inbound messages');
se_ok(count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'question_after_welcome'; })) === 1,
    'a free-text question after the welcome opens a staff task');

/* ======================================================================== */
se_group('Journey: Click-to-WhatsApp referral metadata is preserved and preferred');

se_test_seed_journey();
$referral = ['source_url' => 'https://fb.me/xyz', 'source_id' => '120245890693210187', 'source_type' => 'ad',
             'headline' => 'Kaş ekimi', 'body' => 'Ön değerlendirme', 'media_type' => 'image', 'ctwa_clid' => 'CTWA_CLICK_ID_123'];
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid(), ['referral' => $referral]));
$j = se_test_journey_row();
se_eq('meta_click_to_whatsapp_ad', $j->source, 'referral wins over the text match');
se_eq('provider', $j->source_confidence, 'confidence: provider-supplied');
$attr = json_decode($j->attribution_json, true);
se_eq('CTWA_CLICK_ID_123', $attr['ctwa_clid'], 'ctwa_clid is stored on the journey');
se_eq('120245890693210187', $attr['source_id'], 'ad id stored verbatim');
se_eq('CTWA_CLICK_ID_123', $db->rows('tblleads')[0]['ctwa_clid'], 'ctwa_clid also lands on the lead (existing column)');
se_eq('ad:120245890693210187', $db->rows('tblleads')[0]['utm_content'], 'ad id recorded on the lead');
se_eq('CTWA_CLICK_ID_123', $db->rows('tblse_wa_conversations')[0]['ctwa_clid'], 'and on the conversation (first-touch capture)');

/* ======================================================================== */
se_group('Journey: an existing lead with the same phone is reused, not duplicated');

se_test_seed_journey(['leads' => [['id' => 500, 'brand_id' => 1, 'name' => 'Ayşe Örnek', 'phonenumber' => '0500 000 00 01', 'status' => 5, 'source' => 7]]]);
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid()));
se_eq(1, count($db->rows('tblleads')), 'no new lead: the formatted national number matched');
se_eq(500, (int) se_test_journey_row()->lead_id, 'the journey links to the existing lead');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], 'Merhaba Ayşe') !== false, 'the welcome greets by the lead name');

/* ======================================================================== */
se_group('Journey: organic enquiries wait for staff (no automatic welcome)');

se_test_seed_journey();
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Merhaba, randevumu değiştirmek istiyorum', se_test_wamid()));
$j = se_test_journey_row();
se_eq('organic_whatsapp', $j->source, 'organic source');
se_eq('new_whatsapp_enquiry', $j->state, 'no automatic welcome for organic messages by default');
se_eq(0, count($GLOBALS['se_wa_sent']), 'nothing was sent');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'organic_enquiry'; })), 'staff decide whether to start');

/* ======================================================================== */
se_group('Journey: disabled brand flag → the listener does nothing');

se_test_seed_journey(['options' => ['se_journey_enabled_1' => 0]]);
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid()));
se_eq(0, count($db->rows('tblse_journeys')), 'no journey when the feature flag is off');
se_eq(1, count($db->rows('tblse_wa_messages')), 'the inbox still records the message');

/* ======================================================================== */
se_group('Journey: sandbox mode records instead of sending, except for allow-listed test numbers');

se_test_seed_journey(['options' => ['se_journey_sandbox_1' => 1, 'se_journey_test_recipients_1' => '905000000002']]);
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid()));
se_eq(0, count($GLOBALS['se_wa_sent']), 'a non-allow-listed number receives nothing in sandbox');
se_eq('welcome_sent', se_test_journey_row()->state, 'but the journey advances as if sent (dry run)');
se_eq(1, count(array_filter($db->rows('tblse_journey_events'), function ($e) { return $e['kind'] === 'sandbox_send'; })), 'the sandbox send is recorded on the timeline');
se_test_wa_deliver(se_test_wa_body('905000000002', SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid()));
se_eq(1, count($GLOBALS['se_wa_sent']), 'the allow-listed test number gets the real send');

/* ======================================================================== */
se_group('Journey: opt-out keywords stop non-essential automation and are confirmed once');

se_test_seed_journey();
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid()));
foreach (['İPTAL', 'iptal', 'Dur', 'STOP', 'iptal lütfen', 'Dur artık'] as $i => $kw) {
    se_eq(true, se_journey_matches_keyword($kw, se_journey_optout_keywords()), "'{$kw}' is an opt-out");
}
se_eq(false, se_journey_matches_keyword('iptal etmek istemiyorum, devam edelim', se_journey_optout_keywords()), 'a longer sentence containing the word is NOT an opt-out');
$sentBefore = count($GLOBALS['se_wa_sent']);
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'İPTAL', se_test_wamid()));
$j = se_test_journey_row();
se_eq('opted_out', $j->state, 'state is opted_out');
se_eq('stopped', $j->automation_state, 'automation stopped');
se_eq($sentBefore + 1, count($GLOBALS['se_wa_sent']), 'one confirmation was sent');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], 'otomatik mesaj göndermeyeceğiz') !== false, 'the confirmation says no more automated messages');
$ledger = $db->rows('tblse_consent_ledger');
se_ok(count(array_filter($ledger, function ($r) { return $r['purpose'] === 'whatsapp' && $r['state'] === 'withdrawn'; })) === 1, 'whatsapp consent withdrawal is filed in the ledger');
se_ok(count(array_filter($ledger, function ($r) { return $r['purpose'] === 'marketing' && $r['state'] === 'withdrawn'; })) === 1, 'and marketing consent too');
se_eq(1, count($db->rows('tblse_journeys')), 'the record is retained, not deleted');

// Nothing automated may follow.
$r = se_journey_send_copy($j, 'intake_reminder_1', ['link' => 'x'], ['purpose' => 'reminder_1']);
se_eq(false, $r['ok'], 'an automated message to an opted-out contact is blocked');
se_eq('opted_out', $r['reason'], 'with the opted_out reason');
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Bir sorum var', se_test_wamid()));
se_eq($sentBefore + 1, count($GLOBALS['se_wa_sent']), 'a later question triggers no automated reply');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'optout_contact'; })), 'but a staff task is opened');

// Opt back in needs NEW evidence.
$bad = se_journey_reactivate(se_test_journey_row(), '', 10);
se_eq(false, $bad['ok'], 'reactivation without evidence is refused');
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Devam', se_test_wamid()));
$j = se_test_journey_row();
se_eq('welcome_sent', $j->state, 'an explicit "continue" message re-activates to the previous state');
se_eq('active', $j->automation_state, 'automation active again');
se_ok(count(array_filter($db->rows('tblse_consent_ledger'), function ($r) { return $r['purpose'] === 'whatsapp' && $r['state'] === 'granted'; })) === 1, 'the opt-back-in is filed with the wamid as evidence');

/* ======================================================================== */
se_group('Journey: human handoff pauses automation and alerts staff');

se_test_seed_journey();
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid()));
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'jr_handoff', 'title' => 'Danışmana Bağlan']]]));
$j = se_test_journey_row();
se_eq('paused_patient', $j->automation_state, 'automation paused by the patient');
$t = array_values(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'handoff'; }));
se_eq(1, count($t), 'an urgent handoff task exists');
se_eq('urgent', $t[0]['priority'], 'flagged urgent');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], 'ekip üyemize') !== false, 'a short acknowledgement was sent');
$inbound = array_values(array_filter($db->rows('tblse_wa_messages'), function ($m) { return $m['direction'] === 'in'; }));
se_eq('jr_handoff', end($inbound)['interactive_id'], 'the button id is stored on the message row');
foreach (['danışman', 'temsilci', 'insan', 'ara', 'Danışman lütfen'] as $kw) {
    se_eq(true, se_journey_matches_keyword($kw, se_journey_handoff_keywords()), "'{$kw}' is a handoff keyword");
}
// A staff resume is deliberate and audited.
se_journey_resume(se_test_journey_row(), 10);
se_eq('active', se_test_journey_row()->automation_state, 'staff resume re-activates');
se_eq(1, count(array_filter($db->rows('tblse_journey_audit'), function ($a) { return $a['action'] === 'automation_resume'; })), 'and is audited');

/* ======================================================================== */
se_group('Journey: a staff reply from the composer is a takeover');

$conv = (object) $db->rows('tblse_wa_conversations')[0];
$conv->window_expires_at = date('Y-m-d H:i:s', time() + 3600);
$db->tables['tblse_wa_conversations'][0]['window_expires_at'] = $conv->window_expires_at;
$r = se_wa_queue_message((int) $conv->id, ['kind' => 'text', 'body' => 'Merhaba, ben Ayşe, nasıl yardımcı olabilirim?'], 11);
se_eq(true, $r['ok'], 'the staff reply is queued');
se_eq('active', se_test_journey_row()->automation_state, 'a plain staff reply does NOT pause automation (pause is opt-in, CRM-M006)');
se_eq('staff', se_test_last_row('tblse_wa_outbound')['origin'], 'the outbound row is marked as a staff send');
$r = se_wa_queue_message((int) $conv->id, ['kind' => 'text', 'body' => 'Bir de şunu sorayım', 'pause_automation' => true], 11);
se_eq(true, $r['ok'], 'the explicit-pause reply is queued');
se_eq('paused_staff', se_test_journey_row()->automation_state, 'automation paused only when the staff member asked for it');
se_eq('staff', se_test_last_row('tblse_wa_outbound')['origin'], 'the outbound row is marked as a staff send');
$blocked = se_journey_send_copy(se_test_journey_row(), 'options_repeat', [], ['purpose' => 'options_repeat']);
se_eq('automation_paused_staff', $blocked['reason'], 'automated copy is blocked while staff own the thread');

/* ======================================================================== */
se_group('Journey: health-data consent text is a hard gate on intake');

se_test_seed_journey(['options' => ['se_consent_config_1' => '']]);
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid()));
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'jr_start', 'title' => 'Değerlendirmeye Başla']]]));
$j = se_test_journey_row();
se_eq('welcome_sent', $j->state, 'no progress past welcome without approved consent text');
se_eq('awaiting_approval', $j->automation_state, 'automation waits for approval');
se_eq(0, count($db->rows('tblse_journey_tokens')), 'no intake link was issued');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], 'hazırlanıyor') !== false, 'the patient is told the form is being prepared');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'consent_text_missing'; })), 'staff task names the missing configuration');
se_eq(false, se_journey_health_collection_allowed(1), 'health collection is not allowed');
// Emergency bypass: admin only, reason required, audited.
se_test_act_as(11, ['se_journey.view']);
se_eq(false, se_journey_set_consent_bypass(1, true, 'reason', 11), 'a non-admin cannot enable the bypass');
se_test_act_as(10, [], true);
se_eq(false, se_journey_set_consent_bypass(1, true, '', 10), 'an admin cannot enable it without a reason');
se_eq(true, se_journey_set_consent_bypass(1, true, 'counsel approved by e-mail 2026-09-02, text being typed in', 10), 'an admin can with a reason');
se_eq(true, se_journey_health_collection_allowed(1), 'collection is then allowed');
se_eq(1, count(array_filter($db->rows('tblse_journey_audit'), function ($a) { return $a['action'] === 'consent_bypass_on'; })), 'the bypass is audited');

/* ======================================================================== */
se_group('Journey: start → privacy notice + secure link, then consent on the form');

se_test_seed_journey();
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid()));
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Değerlendirmeye Başla', se_test_wamid()));
$j = se_test_journey_row();
se_eq('consent_pending', $j->state, 'typed option text also works (text fallback)');
$tokens = $db->rows('tblse_journey_tokens');
se_eq(1, count($tokens), 'one intake token issued');
se_eq(64, strlen($tokens[0]['token_hash']), 'stored as a SHA-256 hash');
$linkMsg = end($GLOBALS['se_wa_sent'])['body'];
preg_match('#/se_journey/intake/([A-Za-z0-9_-]+)#', $linkMsg, $m);
$rawToken = $m[1] ?? '';
se_ok($rawToken !== '' && strlen($rawToken) >= 40, 'the message carries the raw token link');
se_ok(strpos($linkMsg, SE_TEST_PATIENT) === false, 'no phone number in the link message');
se_eq(hash('sha256', $rawToken), $tokens[0]['token_hash'], 'the hash matches the raw token');
se_ok(strpos($linkMsg, '48 saat') !== false, 'TTL is stated (48h default)');

$v = se_journey_verify_token($rawToken, 'intake', '203.0.113.7', 'UA');
se_eq(true, $v['ok'], 'the token verifies for its purpose');
se_eq((int) $j->id, (int) $v['journey']->id, 'and resolves to the right journey');
se_eq(false, se_journey_verify_token($rawToken, 'upload')['ok'], 'but not for another purpose');
se_eq('malformed', se_journey_verify_token('short', 'intake')['reason'], 'a malformed token is refused without a lookup');
se_eq('unknown', se_journey_verify_token(str_repeat('a', 43), 'intake')['reason'], 'an unknown token is refused');

// Form step 1: consent. Health decline blocks the form; marketing "no" does not.
$dec = se_journey_record_form_consent($v['journey'], ['consent_health_data' => 'no', 'consent_marketing' => 'no'], '203.0.113.7', 'UA');
se_eq('declined', $dec['reason'], 'declining health-data processing is recorded');
se_eq('consent_declined', se_test_journey_row()->state, 'state: consent_declined');
$save = se_journey_intake_save(se_test_journey_row(), ['full_name' => 'X'], '203.0.113.7', 'UA');
se_eq('consent_required', $save['reason'], 'health answers cannot be saved without health consent');
se_eq(0, count($db->rows('tblse_journey_intakes')), 'no intake row was created');

$grant = se_journey_record_form_consent(se_test_journey_row(), ['consent_health_data' => 'yes', 'consent_marketing' => 'no', 'consent_photo_publication' => 'no'], '203.0.113.7', 'UA');
se_eq('', $grant['reason'], 'granting health-data consent (marketing refused) is accepted');
se_eq('intake_started', se_test_journey_row()->state, 'state: intake_started');
$cs = se_journey_consent_state(se_test_journey_row());
se_eq([true, false, false], [$cs['health_data'], $cs['marketing'], $cs['photo_publication']], 'ledger: health yes, marketing no, publication no');
se_eq('kvkk-test-v1', $cs['version'], 'consent-text version is the configured one (server-side)');
$row = se_test_last_row('tblse_consent_ledger');
se_ok(strpos($row['source'], 'intake_form:') === 0 && strlen($row['source']) > 12, 'source carries channel + IP/UA hash fragments, never the raw IP');

/* ======================================================================== */
se_group('Journey: intake autosave, validation, submission, flags, encryption');

$j = se_test_journey_row();
$save = se_journey_intake_save($j, ['full_name' => 'Ayşe Örnek', 'age' => '34', 'country' => 'Türkiye', 'preferred_language' => 'tr', 'bogus_field' => 'x'], '203.0.113.7', 'UA');
se_eq(true, $save['ok'], 'autosave accepts a partial answer set');
se_eq(['identity'], $save['sections_done'], 'the identity section is complete');
$intake = $db->rows('tblse_journey_intakes')[0];
se_ok(strpos($intake['answers_enc'], 'v1:') === 0, 'answers are sealed');
se_ok(strpos($intake['answers_enc'], 'Ayşe') === false && strpos(base64_decode(substr($intake['answers_enc'], 3)), 'Ayşe') === false, 'plaintext is not present in the stored blob');
se_eq('k1', $intake['key_version'], 'key version recorded');
se_eq('Ayşe Örnek', se_journey_intake_answers((object) $intake)['full_name'], 'decrypts with the key');
se_eq(false, isset(se_journey_intake_answers((object) $intake)['bogus_field']), 'unknown fields are dropped');

$bad = se_journey_intake_save($j, ['age' => 'abc', 'progression' => 'maybe'], '', '');
se_eq('validation', $bad['reason'], 'invalid values are rejected');
se_eq(['age' => 'invalid', 'progression' => 'invalid_option'], $bad['errors'], 'with per-field errors');

$sub = se_journey_intake_submit($j, ['main_concern' => ['sparse']], '203.0.113.7', 'UA');
se_eq('validation', $sub['reason'], 'submission with required fields missing is refused');
se_ok(in_array('pregnancy', $sub['missing'], true) && in_array('blood_thinners', $sub['missing'], true), 'missing required health fields are named');

$full = ['main_concern' => ['sparse', 'other'], 'main_concern_other' => 'kaş kuyruğu yok', 'onset' => 'gt3y', 'progression' => 'progressive', 'areas' => ['tail'],
         'previous_transplant' => 'no', 'previous_procedures' => ['microblading'], 'previous_procedures_detail' => '2023',
         'pregnancy' => 'no', 'chronic' => ['none'], 'skin' => ['none'], 'allergies' => ['local_anesthetic'], 'allergies_detail' => 'lidokain',
         'blood_thinners' => 'yes', 'blood_thinners_detail' => 'aspirin', 'smoking' => 'no', 'alcohol' => 'occasionally', 'anesthesia_complications' => 'no'];
$sub = se_journey_intake_submit($j, $full, '203.0.113.7', 'UA');
se_wa_out_drain();
se_eq(true, $sub['ok'], 'a complete submission is accepted');
sort($sub['flags']);
se_eq(['allergy_reported', 'anticoagulant_reported', 'prior_procedure_near_area', 'unstable_hair_loss'], $sub['flags'], 'review flags are derived for attention');
$j = se_test_journey_row();
se_eq('photos_requested', $j->state, 'after submission the photos are requested');
se_eq(null, $j->review_decision, 'no decision was made automatically');
se_ok((int) $j->patient_id > 0, 'a patient record now exists');
se_eq('Ayşe Örnek', $db->rows('tblleads')[0]['name'], 'the lead name was updated from the form');
se_eq('submitted', $db->rows('tblse_journey_intakes')[0]['status'], 'intake status submitted');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], 'İki kaşın birlikte göründüğü') !== false, 'the photo request text is the approved one');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], 'tanıtım/paylaşım izni bundan ayrıdır') !== false, 'and states that publication permission is separate');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'review'; })), 'a review task exists');
$rows = $db->rows('tblse_journey_tokens');
se_ok(count($rows) === 2 && $rows[1]['purpose'] === 'upload', 'an upload token was issued for the secure photo link');

/* ======================================================================== */
se_group('Journey: token rotation, expiry and cross-patient isolation');

$j1 = se_test_journey_row();
$first = se_journey_issue_token($j1, 'intake', 0);
$second = se_journey_issue_token($j1, 'intake', 0);
se_ok(strtotime($db->rows('tblse_journey_tokens')[2]['expires_at']) <= time() + SE_JOURNEY_TOKEN_GRACE_SECONDS + 5, 'rotation shortens the previous token to the grace period');
se_eq(true, se_journey_verify_token($first['token'], 'intake')['ok'], 'the rotated token still works inside the grace period');
$db->tables['tblse_journey_tokens'][2]['expires_at'] = date('Y-m-d H:i:s', time() - 1);
se_eq('expired', se_journey_verify_token($first['token'], 'intake')['reason'], 'and is refused once expired');
se_journey_revoke_tokens($j1, 'intake', 'test');
se_eq('revoked', se_journey_verify_token($second['token'], 'intake')['reason'], 'a revoked token is refused');

// Second synthetic patient: their token can never open the first patient's form.
se_test_wa_deliver(se_test_wa_body('905000000003', SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid(), ['name' => 'Zeynep']));
$j2 = se_test_journey_row();
$t2 = se_journey_issue_token($j2, 'intake', 0);
$v2 = se_journey_verify_token($t2['token'], 'intake');
se_eq((int) $j2->id, (int) $v2['journey']->id, "patient 2's token resolves only to patient 2");
se_ok((int) $v2['journey']->id !== (int) $j1->id, 'never to patient 1');
se_journey_revoke_tokens($j2, 'intake');
$t3 = se_journey_issue_token($j1, 'intake', 0);
for ($i = 0; $i < 61; $i++) { se_journey_verify_token($t3['token'], 'intake', '198.51.100.9', 'UA'); }
se_eq('rate_limited', se_journey_verify_token($t3['token'], 'intake', '198.51.100.9', 'UA')['reason'], 'per-IP rate limit kicks in');

/* ======================================================================== */
se_group('Journey: photographs via WhatsApp — validated, sealed, counted; invalid media rejected');

se_test_seed_journey();
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid()));
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Değerlendirmeye Başla', se_test_wamid()));
$j = se_test_journey_row();

// A photo BEFORE consent is never sealed into the journey store. (The inbox
// media store still keeps the thread attachment — that is the inbox's own,
// pre-existing behaviour for every inbound file; see the journey doc §7.)
$GLOBALS['se_fetches'] = [];
se_test_media_fetcher(function ($id) {
    $GLOBALS['se_fetches'][] = $id;
    if ($id === 'MEDIA_EXE') { return ['ok' => true, 'bytes' => "MZ\x90\x00" . str_repeat('x', 1000), 'mime' => 'image/jpeg']; }
    if ($id === 'MEDIA_TINY') { return ['ok' => true, 'bytes' => se_test_jpeg(100, 100), 'mime' => 'image/jpeg']; }
    if ($id === 'MEDIA_POLY') { $b = se_test_jpeg(); return ['ok' => true, 'bytes' => $b . '<?php echo 1; ?>', 'mime' => 'image/jpeg']; }
    return ['ok' => true, 'bytes' => se_test_jpeg(), 'mime' => 'image/jpeg'];
});
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['image' => ['id' => 'MEDIA_EARLY', 'mime_type' => 'image/jpeg', 'sha256' => 'x']]));
se_eq(0, count($db->rows('tblse_journey_media')), 'nothing stored or parked in the journey store without health-data consent');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'media_no_consent'; })), 'staff task: guide the patient to the form');
se_eq(['MEDIA_EARLY'], $GLOBALS['se_fetches'], 'the inbox store fetched the thread attachment exactly once (its own pipeline, consent-independent)');

se_journey_record_form_consent($j, ['consent_health_data' => 'evet', 'consent_marketing' => 'hayır'], '', '');
$j = se_test_journey_row();
se_journey_intake_submit($j, $full + ['full_name' => 'Ayşe Örnek', 'age' => '34', 'country' => 'TR', 'preferred_language' => 'tr'], '', '');
$j = se_test_journey_row();
se_eq('photos_requested', $j->state, 'photos requested after intake');

// MEDIA_EXE is refused by the inbox store's own sniff (bytes ≠ image/jpeg) and
// so never reaches the journey; MEDIA_TINY passes the inbox (a real JPEG) and
// is refused by the journey's stricter dimension rule.
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['image' => ['id' => 'MEDIA_EXE', 'mime_type' => 'image/jpeg']]));
$ev = end($db->tables['tblse_journey_events']);
se_ok($ev['kind'] === 'media_fetch_failed' && strpos($ev['summary'], 'does not match') !== false, "an executable disguised as a JPEG is refused upstream (inbox sniff) and surfaced as a fetch failure");
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['image' => ['id' => 'MEDIA_TINY', 'mime_type' => 'image/jpeg']]));
$ev = end($db->tables['tblse_journey_events']);
se_ok($ev['kind'] === 'media_rejected' && strpos($ev['summary'], 'too_small') !== false, "a too-small image is rejected by the journey (too_small)");
se_eq(0, count(array_filter($db->rows('tblse_journey_media'), function ($m) { return $m['state'] !== 'fetch_failed'; })), 'no invalid file was sealed');
se_eq(1, count(array_filter($db->rows('tblse_journey_media'), function ($m) { return $m['state'] === 'fetch_failed'; })), 'the refused attachment is visible as a failed placeholder');

se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['image' => ['id' => 'MEDIA_POLY', 'mime_type' => 'image/jpeg']]));
$sealedRows = function () use ($db) { return array_values(array_filter($db->rows('tblse_journey_media'), function ($m) { return $m['state'] === 'received'; })); };
se_eq(1, count($sealedRows()), 'a JPEG with an appended script is accepted after re-encoding');
$m = $sealedRows()[0];
se_ok((int) $m['inbox_media_id'] > 0, 'the sealed row points back at the inbox media row it was taken from');
se_eq(1, (int) $m['metadata_stripped'], 'metadata stripped (re-encoded through GD)');
$stored = se_journey_media_read((object) $m);
se_ok($stored !== null && strpos($stored, '<?php') === false, 'the stored bytes no longer contain the payload');
se_ok(strpos((string) file_get_contents(se_journey_media_dir() . '/' . $m['storage_ref']), "\xff\xd8") !== 0, 'the file on disk is sealed, not a raw JPEG');
se_eq(1, (int) $m['evaluation_use_permitted'], 'evaluation use permitted (health consent)');
se_eq(0, (int) $m['publication_permitted'], 'publication NOT permitted (separate consent, never implied)');
se_eq('photos_requested', se_test_journey_row()->state, 'state unchanged after 1 photo');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], '1 fotoğraf alındı') !== false, 'partial acknowledgement counts 1');

se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['image' => ['id' => 'MEDIA_2', 'mime_type' => 'image/jpeg']]));
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['image' => ['id' => 'MEDIA_3', 'mime_type' => 'image/jpeg']]));
se_eq(3, count($sealedRows()), 'three photos attached to the record');
foreach ($sealedRows() as $mm) { se_eq((int) $j->id, (int) $mm['journey_id'], 'photo ' . $mm['id'] . ' belongs to the right journey'); }
se_eq('ready_for_review', se_test_journey_row()->state, 'three photos → ready_for_review');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], 'Fotoğraflarınız alındı') !== false, 'completion acknowledgement sent');

// Duplicate delivery of the same image (same wamid) is a no-op.
$lastWamid = 'wamid.T' . $GLOBALS['WAMID'];
$n = count($db->rows('tblse_journey_media'));
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', $lastWamid, ['image' => ['id' => 'MEDIA_3', 'mime_type' => 'image/jpeg']], time() + 9));
se_eq($n, count($db->rows('tblse_journey_media')), 'a redelivered image does not create a second media row');

// No token (and no test seam): the inbox store cannot fetch, the journey
// parks a placeholder pointing at the inbox row, opens the gated task, loses
// nothing — and seals the photo as soon as the inbox row lands.
$GLOBALS['SE_MEDIA_FETCHER'] = null;
se_test_remove_secret('wa_token');
$before = count($db->rows('tblse_media'));
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['image' => ['id' => 'MEDIA_LATE', 'mime_type' => 'image/jpeg']]));
$parked = se_test_last_row('tblse_journey_media');
se_eq('pending_fetch', $parked['state'], 'without a token the photo is parked for a later fetch');
$inboxLate = se_test_last_row('tblse_media');
se_eq($before + 1, count($db->rows('tblse_media')), 'the inbox store registered the attachment');
se_eq('pending', $inboxLate['state'], 'and is still retrying it (no wa_token)');
se_ok(strpos((string) $inboxLate['last_error'], 'wa_token') !== false, 'with the exact gate named');
se_eq((int) $inboxLate['id'], (int) $parked['inbox_media_id'], 'the placeholder points at that inbox row');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'media_fetch_gated'; })), 'staff task: install wa_token');
se_test_install_secret('wa_token', 'fixture-token');
se_test_media_fetcher(function ($id) { return ['ok' => true, 'bytes' => se_test_jpeg(), 'mime' => 'image/jpeg']; });
se_eq(1, se_test_drain_media(), 'the next dispatcher tick seals the parked photo once the inbox row lands');
se_eq(0, count(array_filter($db->rows('tblse_journey_media'), function ($m) { return $m['state'] === 'pending_fetch'; })), 'no parked rows remain');

/* ======================================================================== */
se_group('Journey: outside the service window only an APPROVED template is sent; unapproved = visible block');

se_test_seed_journey();
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid()));
$j = se_test_journey_row();
se_eq('welcome_sent', $j->state, 'welcome went out while the window was open');
// Close the window.
$db->tables['tblse_wa_conversations'][0]['window_expires_at'] = date('Y-m-d H:i:s', time() - 60);
se_journey_seed_templates(1);
$sent = count($GLOBALS['se_wa_sent']);
$r = se_journey_send_privacy_and_link(se_test_journey_row(), 'c1', 'staff', 10);
se_eq(false, $r['ok'], 'with the window closed and the template not approved, the send is blocked');
se_eq('template_not_submitted', $r['reason'], 'the block names the template status');
se_eq($sent, count($GLOBALS['se_wa_sent']), 'nothing was sent');
$j = se_test_journey_row();
se_eq('error', $j->automation_state, 'automation control shows an error state');
se_eq('template_unapproved:eyebrow_intake_resume_tr', $j->automation_reason, 'with the exact template named');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'template_blocked'; })), 'staff task opened');
se_eq('welcome_sent', $j->state, 'the journey did not pretend the step happened');

// Registry says approved but the WABA mirror does not: still blocked.
foreach ($db->tables['tblse_journey_templates'] as &$tplRow) { if ($tplRow['logical_name'] === 'eyebrow_intake_resume_tr') { $tplRow['approval_status'] = 'approved'; } }
unset($tplRow);
se_journey_resume(se_test_journey_row(), 10);
$r = se_journey_send_privacy_and_link(se_test_journey_row(), 'c2', 'staff', 10);
se_eq('template_not_in_waba_mirror', $r['reason'], 'registry status alone is not enough — Meta must hold the approved template');

// Both approved: template send goes out with ordered variables, state advances.
$db->seed('tblse_wa_templates', [['id' => 1, 'brand_id' => 1, 'name' => 'eyebrow_intake_resume_tr', 'language' => 'tr', 'category' => 'UTILITY', 'approval_state' => 'approved', 'variables' => '1,2']]);
se_eq('error', se_test_journey_row()->automation_state, 'the second block again parked automation in error');
se_journey_resume(se_test_journey_row(), 10);
$r = se_journey_send_privacy_and_link(se_test_journey_row(), 'c3', 'staff', 10);
se_wa_out_drain();
se_eq(true, $r['ok'], 'with an approved template the message is queued');
se_eq('template', $r['mode'], 'as a template');
$last = end($GLOBALS['se_wa_sent']);
se_eq('eyebrow_intake_resume_tr', $last['template'], 'the Meta template name is used');
se_eq('tr', $last['template_language'], 'with the mirror language');
se_eq(2, count($last['variables']), 'two ordered placeholders (name, link)');
se_ok(strpos($last['variables'][1], '/se_journey/intake/') !== false, 'the link is the second placeholder');
se_eq('consent_pending', se_test_journey_row()->state, 'state advanced');

/* ======================================================================== */
se_group('Journey: delivery / read / failure callbacks update the thread');

$outRow = se_test_last_row('tblse_wa_outbound');
$wamid  = $outRow['wamid'] ?? se_test_last_row('tblse_wa_messages')['wamid'];
se_wa_handle_status(1, ['id' => $wamid, 'status' => 'delivered']);
se_eq('delivered', se_test_last_row('tblse_wa_messages')['delivery_state'], 'delivered callback applied to the mirrored outbound message');
se_wa_handle_status(1, ['id' => $wamid, 'status' => 'read']);
se_eq('read', se_test_last_row('tblse_wa_messages')['delivery_state'], 'read callback applied');
se_wa_handle_status(1, ['id' => $wamid, 'status' => 'failed', 'errors' => [['code' => 131047, 'title' => 'Re-engagement message']]]);
se_eq('failed', se_test_last_row('tblse_wa_messages')['delivery_state'], 'failure callback applied');
se_eq('131047 Re-engagement message', se_test_last_row('tblse_wa_messages')['status_error'], 'the Meta error code + title is kept on the message (never content)');
$failedOut = array_values(array_filter($db->rows('tblse_wa_outbound'), function ($o) use ($wamid) { return ($o['wamid'] ?? '') === $wamid; }));
se_eq('failed', $failedOut[0]['status'], 'the outbound tracker row flips from sent to failed');
se_eq('provider', $failedOut[0]['failure_class'], 'classified as a provider-side drop');
se_ok(count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'delivery_failed'; })) === 1, 'a staff task names the undelivered message');
se_ok(strpos((string) se_test_journey_row()->last_send_block, 'delivery_failed:131047') === 0, 'the journey header shows the delivery failure');

/* ======================================================================== */
se_group('Journey: reminders — one after 24h, one final after 72h, then a staff task and STOP');

se_test_seed_journey(['options' => ['se_journey_quiet_hours' => '00:00-00:00', 'se_journey_daily_cap' => 10]]); // the fixture clock is simulated; the cap counts real-time rows
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid()));
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Başla', se_test_wamid()));
$j = se_test_journey_row();
se_eq('consent_pending', $j->state, 'waiting on the patient');
$base = strtotime($j->state_changed_at);
$reminders = function () use ($db) {
    return array_values(array_filter($db->rows('tblse_wa_outbound'), function ($o) { return strpos((string) $o['origin'], 'journey:reminder') === 0; }));
};
$db->tables['tblse_wa_conversations'][0]['window_expires_at'] = date('Y-m-d H:i:s', time() + 30 * 86400); // keep the fixture window open

se_eq(0, se_journey_run_reminders($base + 2 * 3600), 'no reminder after 2h');
se_eq(0, se_journey_run_reminders($base + 23 * 3600 + 60), 'no reminder at 23h');
se_eq(1, se_journey_run_reminders($base + 25 * 3600), 'first reminder at 24h+');
se_eq(1, (int) se_test_journey_row()->reminder_count, 'reminder_count = 1');
se_eq(0, se_journey_run_reminders($base + 25 * 3600 + 60), 'running the next tick queues nothing new');
se_eq(1, count($reminders()), 'exactly one reminder row exists (idempotent key)');
se_ok(strpos($reminders()[0]['body'], 'henüz tamamlanmadı') !== false, 'first reminder copy');
se_ok(strpos($reminders()[0]['body'], 'İPTAL') !== false, 'reminder offers the opt-out');
se_eq(0, se_journey_run_reminders($base + 30 * 3600), 'no second reminder before a further 72h');
se_eq(0, se_journey_run_reminders($base + 25 * 3600 + 71 * 3600), 'still none at 71h after the first');
se_eq(1, se_journey_run_reminders($base + 25 * 3600 + 73 * 3600), 'final reminder after a further 72h');
se_eq(2, (int) se_test_journey_row()->reminder_count, 'reminder_count = 2');
se_eq(2, count($reminders()), 'two reminder rows in total');
se_ok(strpos($reminders()[1]['body'], 'son hatırlatmamız') !== false, 'final reminder copy');
se_eq('intake_incomplete', se_test_journey_row()->state, 'state marked intake_incomplete');
se_eq(1, count(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'reminders_exhausted'; })), 'staff task created');
se_eq(0, se_journey_run_reminders($base + 400 * 3600), 'and no further reminders EVER (loop prevention)');
se_eq(0, se_journey_run_reminders($base + 4000 * 3600), 'still none');
se_eq(2, count($reminders()), 'still exactly two rows');

/* ======================================================================== */
se_group('Journey: the reminder scan is not capped by unrelated journeys (CRM-M007 / T13)');

// 120 journeys in staff-owned states with LOWER ids, then one patient-owned
// journey. The old scan took the first 100 rows by id regardless of state and
// never reached the patient.
$rows = $db->rows('tblse_journeys');
$victim = $rows[0];
$filler = [];
for ($i = 1; $i <= 120; $i++) {
    $f = $victim; $f['id'] = 5000 + $i; $f['wa_user_id'] = '9055500' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
    $f['state'] = 'ready_for_review'; $f['reminder_count'] = 0; $f['automation_state'] = 'active';
    $filler[] = $f;
}
$victim['id'] = 5999; $victim['wa_user_id'] = '905559999999'; $victim['state'] = 'consent_pending'; $victim['reminder_count'] = 0;
$victim['automation_state'] = 'active'; $victim['state_changed_at'] = date('Y-m-d H:i:s', $base); $victim['last_reminder_at'] = null; $victim['latest_touch_at'] = null;
$db->seed('tblse_journeys', array_merge($filler, [$victim]));
$db->seed('tblse_wa_outbound', []);
$db->tables['tblse_wa_conversations'][0]['wa_user_id'] = '905559999999';
se_eq(1, se_journey_run_reminders($base + 25 * 3600), 'the 121st journey (the only one waiting on the patient) gets its reminder');

/* ======================================================================== */
se_group('Journey: quiet hours defer scheduled messages; daily cap blocks; replies are exempt');

se_test_seed_journey();
$GLOBALS['se_test']['options']['se_journey_quiet_hours'] = '21:00-09:00';
$night = strtotime(date('Y-m-d') . ' 23:30:00');
$rel = se_journey_quiet_hours_release($night);
se_eq(date('Y-m-d H:i', strtotime('tomorrow 09:00', $night)), date('Y-m-d H:i', $rel), 'a 23:30 scheduled send is released at 09:00 the next day');
$day = strtotime(date('Y-m-d') . ' 14:00:00');
se_eq($day, se_journey_quiet_hours_release($day), 'a 14:00 send is immediate');
$GLOBALS['se_test']['options']['se_journey_daily_cap'] = 2;
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid()));
$j = se_test_journey_row();
$db->tables['tblse_wa_conversations'][0]['window_expires_at'] = date('Y-m-d H:i:s', time() + 7 * 86400);
$a = se_journey_send_copy($j, 'intake_reminder_1', ['link' => 'L'], ['purpose' => 'r1', 'schedulable' => true, 'dedup_salt' => '1']);
$b = se_journey_send_copy($j, 'intake_reminder_1', ['link' => 'L'], ['purpose' => 'r2', 'schedulable' => true, 'dedup_salt' => '2']);
se_eq([true, false], [$a['ok'], $b['ok']], 'with the welcome already counted, the second scheduled message exceeds a cap of 2');
se_eq('frequency_cap', $b['reason'], 'named as the frequency cap');
$c = se_journey_send_copy($j, 'options_repeat', [], ['purpose' => 'options_repeat']);
se_eq(true, $c['ok'], 'a direct reply to the patient is exempt from the cap');

/* ======================================================================== */
se_group('Journey: urgent aftercare answer pauses automation and alerts — no diagnosis');

se_test_seed_journey();
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid()));
$j = se_test_journey_row();
// Fast-forward to aftercare via forced transitions (state plumbing is tested elsewhere).
se_journey_transition($j, 'aftercare_active', 'test_fastforward', 'staff', 10, null, null, true);
$db->tables['tblse_wa_conversations'][0]['window_expires_at'] = date('Y-m-d H:i:s', time() + 3600);
$sent = count($GLOBALS['se_wa_sent']);
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Kanama durmuyor ve çok şiddetli ağrı var', se_test_wamid()));
$j = se_test_journey_row();
se_eq(1, (int) $j->urgent, 'the journey is flagged urgent');
se_eq('paused_patient', $j->automation_state, 'routine automation paused');
$task = array_values(array_filter($db->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'urgent'; }));
se_eq('urgent', $task[0]['priority'], 'an urgent staff task exists');
$ack = end($GLOBALS['se_wa_sent'])['body'];
se_ok(strpos($ack, '112') !== false, 'the reply points to emergency services');
foreach (['enfeksiyon', 'antibiyotik', 'normal', 'teşhis', 'muhtemelen'] as $word) {
    se_ok(mb_stripos($ack, $word) === false, "the reply does not diagnose ('{$word}' absent)");
}
se_eq(1, count(array_filter($db->rows('tblse_journey_events'), function ($e) { return $e['kind'] === 'urgent_alerted'; })), 'staff were alerted');

/* Leave the shared fixture stores as this suite found them. */
se_test_remove_secret('wa_token');
se_test_remove_secret('wa_app');
se_test_remove_secret('journey_key');
$GLOBALS['SE_WA_TRANSPORT'] = null;
$GLOBALS['SE_WA_MEDIA_FETCHER'] = null;
