<?php
/**
 * Shared fixtures for the se_journey suites (test_journey*.php): a seeded
 * clinic, a signed-webhook builder that runs the REAL receiver + drains, a
 * fixture transport, synthetic GD images. Never real data.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('SE_TEST_PATIENT', '905000000001');     // synthetic, not a real subscriber
define('SE_TEST_PN', 'PN1');

function se_test_seed_journey($opts = [])
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];

    $db->seed('tblse_brands', [['id' => 1, 'name' => 'Clinic', 'active' => 1]]);
    $db->seed('tblse_staff_brands', [['staff_id' => 10, 'brand_id' => 1], ['staff_id' => 11, 'brand_id' => 1], ['staff_id' => 20, 'brand_id' => 2]]);
    $db->seed('tblstaff', [
        ['staffid' => 10, 'firstname' => 'Owner', 'lastname' => 'A', 'admin' => 1, 'active' => 1],
        ['staffid' => 11, 'firstname' => 'Sales', 'lastname' => 'B', 'admin' => 0, 'active' => 1],
    ]);
    $db->seed('tblse_wa_numbers', [
        ['id' => 1, 'brand_id' => 1, 'phone_number_id' => SE_TEST_PN, 'waba_id' => 'W1', 'display_number' => '+90 547 120 70 70',
         'token_option_ref' => 'wa_token', 'state' => 'active', 'quality_rating' => 'GREEN', 'messaging_tier' => 'TIER_1K'],
    ]);
    $db->seed('tblse_wa_conversations', []);
    $db->seed('tblse_wa_messages', []);
    $db->seed('tblse_wa_outbound', []);
    $db->seed('tblse_wa_webhook_events', []);
    $db->seed('tblse_wa_metering', []);
    $db->seed('tblse_wa_templates', $opts['templates'] ?? []);
    $db->seed('tblleads', $opts['leads'] ?? []);
    $db->seed('tblleads_status', [['id' => 5, 'name' => 'New', 'statusorder' => 10]]);
    $db->seed('tblleads_sources', [['id' => 7, 'name' => 'WhatsApp']]);
    $db->seed('tblse_consent_ledger', []);
    $db->seed('tblse_patients', []);
    $db->seed('tblse_record_access_log', []);
    $db->seed('tblse_procedure_history', []);
    $db->seed('tblse_conversion_outbox', []);
    $db->seed('tblse_appointments', []);
    $db->seed('tblse_appointment_status_history', []);
    $db->seed('tblse_working_hours', []);
    $db->seed('tblse_reminders', []);
    // The lead record the journey keeps in sync (custom fields, timeline, countries for the ISO map).
    $db->seed('tblcustomfields', []);
    $db->seed('tblcustomfieldsvalues', []);
    $db->seed('tbllead_activity_log', []);
    $db->seed('tblcountries', [['country_id' => 228, 'iso2' => 'TR', 'short_name' => 'Turkey'], ['country_id' => 83, 'iso2' => 'DE', 'short_name' => 'Germany']]);
    foreach (['se_journeys', 'se_journey_transitions', 'se_journey_events', 'se_journey_tokens', 'se_journey_intakes',
              'se_journey_media', 'se_journey_reviews', 'se_journey_quotes', 'se_journey_aftercare_plans',
              'se_journey_aftercare_events', 'se_journey_templates', 'se_journey_tasks', 'se_journey_audit', 'se_journey_throttle'] as $t) {
        $db->seed('tbl' . $t, []);
    }

    $GLOBALS['se_test']['options'] = [
        'se_journey_enabled_1'  => 1,
        'se_journey_sandbox_1'  => 0,
        'se_consent_config_1'   => json_encode([
            'version'  => 'kvkk-test-v1',
            'purposes' => [
                'ads'         => ['enabled' => 0, 'text' => ['en' => '', 'tr' => '']],
                'marketing'   => ['enabled' => 1, 'text' => ['en' => 'Marketing (optional)', 'tr' => 'Pazarlama (isteğe bağlı)']],
                'health_data' => ['enabled' => 1, 'text' => ['en' => 'I consent to health-data processing for evaluation.', 'tr' => 'Sağlık verilerimin değerlendirme amacıyla işlenmesine açık rıza veriyorum.']],
                'photo_publication' => ['enabled' => 1, 'text' => ['en' => 'Publication (optional)', 'tr' => 'Yayın (isteğe bağlı)']],
            ],
        ]),
    ];
    $GLOBALS['se_test']['options'] = array_merge($GLOBALS['se_test']['options'], $opts['options'] ?? []);

    se_test_install_secret('wa_app', 'fixture-app-secret');
    se_test_install_secret('wa_token', 'fixture-token');
    se_test_install_secret('journey_key', base64_encode(str_repeat("\x42", 32)));

    $GLOBALS['SE_WA_TRANSPORT'] = null;
    $GLOBALS['se_wa_sent'] = [];
    se_wa_register_transport(function ($m) {
        $GLOBALS['se_wa_sent'][] = $m;
        return ['ok' => true, 'wamid' => 'wamid.OUT' . count($GLOBALS['se_wa_sent']), 'code' => 200, 'error' => ''];
    });
    $GLOBALS['SE_WA_MEDIA_FETCHER'] = null;
    $GLOBALS['SE_WA_INBOUND_LISTENERS'] = ['se_journey' => 'se_journey_on_wa_inbound'];
    se_clinic_reset_cache();
}

/** A signed Meta webhook body for one inbound message. */
function se_test_wa_body($from, $text, $wamid, $extra = [], $ts = null)
{
    $msg = ['from' => $from, 'id' => $wamid, 'timestamp' => (string) ($ts ?? time()), 'type' => 'text', 'text' => ['body' => $text]];
    if (isset($extra['referral'])) { $msg['referral'] = $extra['referral']; }
    if (isset($extra['interactive'])) { $msg['type'] = 'interactive'; unset($msg['text']); $msg['interactive'] = $extra['interactive']; }
    if (isset($extra['button'])) { $msg['type'] = 'button'; unset($msg['text']); $msg['button'] = $extra['button']; }   // template quick reply
    if (isset($extra['image'])) { $msg['type'] = 'image'; unset($msg['text']); $msg['image'] = $extra['image']; }
    $value = ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '905471207070', 'phone_number_id' => SE_TEST_PN],
              'contacts' => [['profile' => ['name' => $extra['name'] ?? 'Test Hasta'], 'wa_id' => $from]], 'messages' => [$msg]];

    return json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'W1', 'changes' => [['field' => 'messages', 'value' => $value]]]]]);
}

/**
 * Deliver through the real receiver + the dispatcher legs a real tick runs:
 * events → queue → inbox media fetch → journey media seal → queue again (the
 * acknowledgement the seal queues goes out on the next tick in production).
 */
function se_test_wa_deliver($body, $secret = 'fixture-app-secret')
{
    $sig = 'sha256=' . hash_hmac('sha256', $body, $secret);
    $out = se_wa_receive_outcome(strlen($body), $body, $sig);
    if ($out['ok']) {
        se_wa_process_pending();
        se_wa_out_drain();
        se_test_drain_media();
    }

    return $out;
}

/**
 * The inbox media fetch (se_core/se_media.php, dispatcher step `media`) and
 * the journey seal (step `journey_media`), then the outbound queue. Pending
 * inbox rows are made due first so a backoff never stalls a test.
 * Returns the number of photos the journey sealed.
 */
function se_test_drain_media()
{
    foreach (se_test_db()->rows('tblse_media') as $r) {
        if ($r['state'] === 'pending') {
            se_test_db()->where('id', $r['id'])->update('tblse_media', ['next_attempt_at' => date('Y-m-d H:i:s', time() - 1)]);
        }
    }
    se_media_fetch_pending();
    $sealed = se_journey_retry_parked_media();
    se_wa_out_drain();

    return $sealed;
}

/**
 * Register an inbox-store fetcher keyed by the WhatsApp media id (the seam
 * upstream tests use: se_media_register_fetcher receives the tblse_media row).
 * $byId(string $mediaId) returns ['ok','bytes','mime'] (+ optional 'error').
 */
function se_test_media_fetcher(callable $byId)
{
    se_media_register_fetcher(function ($row) use ($byId) {
        $r = $byId((string) $row['provider_ref']);

        return ['ok' => !empty($r['ok']), 'bytes' => (string) ($r['bytes'] ?? ''), 'mime' => (string) ($r['mime'] ?? ''),
                'error' => (string) ($r['error'] ?? '')];
    });
}

/** Last row of a fake table (a copy — end() needs a variable). */
function se_test_last_row($table)
{
    $rows = se_test_db()->rows($table);

    return $rows ? end($rows) : null;
}

function se_test_journey_row()
{
    $rows = se_test_db()->rows('tblse_journeys');

    return $rows ? (object) end($rows) : null;
}

function se_test_sent_bodies()
{
    return array_map(function ($m) { return (string) ($m['body'] ?? ('[template ' . $m['template'] . ']')); }, $GLOBALS['se_wa_sent']);
}

/** A synthetic JPEG of the requested size (no real person). */
function se_test_jpeg($w = 640, $h = 480)
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 200, 180, 160));
    imagefilledellipse($im, (int) ($w / 2), (int) ($h / 2), (int) ($w / 3), (int) ($h / 6), imagecolorallocate($im, 90, 60, 40));
    ob_start(); imagejpeg($im, null, 85); $bytes = ob_get_clean(); imagedestroy($im);

    return $bytes;
}

$WAMID = 0;
function se_test_wamid() { $GLOBALS['WAMID']++; return 'wamid.T' . $GLOBALS['WAMID']; }


/* The real appointments model needs two Perfex helpers the harness lacks. */
if (!function_exists('to_sql_date')) {
    function to_sql_date($d, $t = false) { return date($t ? 'Y-m-d H:i:s' : 'Y-m-d', strtotime((string) $d)); }
}
if (!class_exists('Se_appointments_model')) {
    require_once dirname(dirname(__DIR__)) . '/se_appointments/models/Se_appointments_model.php';
}

/** Fast path to a reviewed journey with a submitted intake and three photos. */
function se_test_journey_reviewed()
{
    se_test_seed_journey(['options' => ['se_journey_daily_cap' => 20]]);
    se_test_act_as(10, [], true);
    se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid(), ['name' => 'Ayşe']));
    se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Başla', se_test_wamid()));
    $j = se_test_journey_row();
    se_journey_record_form_consent($j, ['consent_health_data' => 'yes', 'consent_marketing' => 'no', 'consent_photo_publication' => 'no'], '', '');
    $j = se_test_journey_row();
    $answers = ['full_name' => 'Ayşe Örnek', 'age' => '34', 'country' => 'TR', 'preferred_language' => 'tr',
                'main_concern' => ['sparse'], 'onset' => 'gt3y', 'progression' => 'stable', 'areas' => ['tail'],
                'previous_transplant' => 'no', 'previous_procedures' => ['none'], 'pregnancy' => 'no', 'chronic' => ['none'],
                'skin' => ['none'], 'allergies' => ['none'], 'blood_thinners' => 'yes', 'blood_thinners_detail' => 'aspirin',
                'smoking' => 'no', 'alcohol' => 'no', 'anesthesia_complications' => 'no'];
    se_journey_intake_submit($j, $answers, '', '');
    $j = se_test_journey_row();
    se_test_media_fetcher(function ($id) { return ['ok' => true, 'bytes' => se_test_jpeg(), 'mime' => 'image/jpeg']; });
    foreach (['P1', 'P2', 'P3'] as $id) {
        se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['image' => ['id' => $id, 'mime_type' => 'image/jpeg']]));
    }
    se_wa_out_drain();
    se_test_db()->tables['tblse_wa_conversations'][0]['window_expires_at'] = date('Y-m-d H:i:s', time() + 30 * 86400);

    return se_test_journey_row();
}

