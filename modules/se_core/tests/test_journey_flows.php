<?php
/**
 * Patient journey (se_journey) — WhatsApp Flows: the intake form and the
 * consultation calendar inside WhatsApp, with this CRM as the data endpoint.
 *
 *   - Meta's transport: RSA-OAEP(SHA-256) key unwrap, AES-128-GCM both ways
 *     with the inverted IV, signature check — round-tripped here, and against
 *     the OpenSSL CLI when it is present
 *   - the generated Flow JSON respects Meta's component limits and mirrors
 *     the questionnaire (same keys, same options)
 *   - the endpoint walks the intake: consent → four screens → submit, storing
 *     through the SAME functions as the web form (sealed answers identical);
 *     a missing answer sends the patient back to its screen
 *   - the booking flow lists live days/slots and books through the model
 *   - the journey sends the flow instead of the link when it is published
 *     (interactive in-window, template FLOW button outside), and the
 *     completion webhook (nfm_reply) is a receipt, not a data path
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/journey_fixtures.php';

/* ---- test-side OAEP-SHA256 encryption (RFC 8017 §7.1.1), to play Meta ------ */
function se_test_oaep_encrypt($message, $publicPem)
{
    $key = openssl_pkey_get_public($publicPem);
    $k   = (int) (openssl_pkey_get_details($key)['bits'] / 8);
    $hLen = 32;
    $lHash = hash('sha256', '', true);
    $ps = str_repeat("\0", $k - strlen($message) - 2 * $hLen - 2);
    $db = $lHash . $ps . "\x01" . $message;
    $seed = random_bytes($hLen);
    $dbMask = se_journey_flow_mgf1($seed, $k - $hLen - 1);
    $maskedDB = $db ^ $dbMask;
    $seedMask = se_journey_flow_mgf1($maskedDB, $hLen);
    $maskedSeed = $seed ^ $seedMask;
    $em = "\0" . $maskedSeed . $maskedDB;
    $out = '';
    openssl_public_encrypt($em, $out, $key, OPENSSL_NO_PADDING);

    return $out;
}

/** Build a request body the way Meta does; returns [body, aes_key, iv]. */
function se_test_flow_request(array $payload, $publicPem)
{
    $aes = random_bytes(16);
    $iv  = random_bytes(16);
    $tag = '';
    $ct  = openssl_encrypt(json_encode($payload), 'aes-128-gcm', $aes, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

    return [[
        'encrypted_flow_data' => base64_encode($ct . $tag),
        'encrypted_aes_key'   => base64_encode(se_test_oaep_encrypt($aes, $publicPem)),
        'initial_vector'      => base64_encode($iv),
    ], $aes, $iv];
}

/** Decrypt an endpoint response the way Meta does. */
function se_test_flow_response($b64, $aes, $iv)
{
    $raw = base64_decode($b64);
    $flipped = '';
    for ($i = 0; $i < strlen($iv); $i++) { $flipped .= chr(ord($iv[$i]) ^ 0xFF); }

    return json_decode(openssl_decrypt(substr($raw, 0, -16), 'aes-128-gcm', $aes, OPENSSL_RAW_DATA, $flipped, substr($raw, -16)), true);
}

/* ======================================================================== */
se_group('Journey flows: the transport — OAEP-SHA256 unwrap, AES-GCM both ways, inverted IV, signature');

$kp = se_journey_flow_keypair_generate();
se_eq(true, $kp['ok'], 'a 2048-bit key pair');
se_ok(strpos($kp['private'], 'PRIVATE KEY') !== false && strpos($kp['public'], 'PUBLIC KEY') !== false, 'PEM private + public');

$secret = random_bytes(16);
$wrapped = se_test_oaep_encrypt($secret, $kp['public']);
se_eq(256, strlen($wrapped), 'RSA-2048 ciphertext is 256 bytes');
se_eq($secret, se_journey_flow_rsa_oaep_decrypt($wrapped, $kp['private']), 'OAEP-SHA256 unwrap recovers the AES key');
se_eq('', se_journey_flow_rsa_oaep_decrypt(str_repeat("\x01", 256), $kp['private']), 'garbage → empty, no exception');
se_eq('', se_journey_flow_rsa_oaep_decrypt($wrapped, se_journey_flow_keypair_generate()['private']), 'the wrong private key → empty');

// Against OpenSSL's own OAEP-SHA256 when the CLI is available (the reference implementation Meta matches).
$cli = trim((string) @shell_exec('command -v openssl 2>/dev/null'));
if ($cli !== '') {
    $tmp = sys_get_temp_dir() . '/se_flow_' . getmypid();
    file_put_contents($tmp . '.pub', $kp['public']);
    file_put_contents($tmp . '.in', $secret);
    @shell_exec($cli . ' pkeyutl -encrypt -pubin -inkey ' . escapeshellarg($tmp . '.pub') . ' -in ' . escapeshellarg($tmp . '.in') . ' -out ' . escapeshellarg($tmp . '.out')
        . ' -pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:sha256 -pkeyopt rsa_mgf1_md:sha256 2>/dev/null');
    $ref = @file_get_contents($tmp . '.out');
    foreach (['.pub', '.in', '.out'] as $ext) { @unlink($tmp . $ext); }
    se_ok($ref !== false && strlen($ref) === 256, 'OpenSSL CLI produced an OAEP-SHA256 ciphertext');
    se_eq($secret, se_journey_flow_rsa_oaep_decrypt((string) $ref, $kp['private']), 'and our unwrap agrees with OpenSSL\'s OAEP-SHA256/MGF1-SHA256');
}

[$body, $aes, $iv] = se_test_flow_request(['version' => '3.0', 'action' => 'ping'], $kp['public']);
$dec = se_journey_flow_decrypt($body, $kp['private']);
se_eq(true, $dec['ok'], 'request decrypted');
se_eq(['version' => '3.0', 'action' => 'ping'], $dec['payload'], 'payload intact');
se_eq($aes, $dec['aes_key'], 'AES key kept for the reply');
$enc = se_journey_flow_encrypt(['data' => ['status' => 'active']], $dec['aes_key'], $dec['iv']);
se_eq(['data' => ['status' => 'active']], se_test_flow_response($enc, $aes, $iv), 'reply readable with the inverted IV');
$tampered = $body; $tampered['encrypted_flow_data'] = base64_encode(substr(base64_decode($body['encrypted_flow_data']), 0, -1) . "\0");
se_eq('aes_gcm', se_journey_flow_decrypt($tampered, $kp['private'])['reason'], 'a tampered payload fails the GCM tag');
se_eq('missing_initial_vector', se_journey_flow_decrypt(['encrypted_flow_data' => 'x', 'encrypted_aes_key' => 'y'], $kp['private'])['reason'], 'missing field named');

se_eq(true, se_journey_flow_signature_ok('{"a":1}', 'sha256=' . hash_hmac('sha256', '{"a":1}', 's3cret'), 's3cret'), 'signature accepted');
se_eq(false, se_journey_flow_signature_ok('{"a":1}', 'sha256=' . hash_hmac('sha256', '{"a":2}', 's3cret'), 's3cret'), 'wrong body refused');
se_eq(false, se_journey_flow_signature_ok('{"a":1}', 'sha256=' . hash_hmac('sha256', '{"a":1}', 's3cret'), ''), 'no secret → refused (never open)');

/* ======================================================================== */
se_group('Journey flows: the generated Flow JSON respects Meta\'s limits and mirrors the questionnaire');

se_test_seed_journey();
$db = se_test_db();
foreach (['intake', 'booking'] as $kind) {
    $fj = se_journey_flow_json(1, $kind);
    se_eq(SE_JOURNEY_FLOW_JSON_VERSION, $fj['version'], "$kind: Flow JSON version");
    se_eq('3.0', $fj['data_api_version'], "$kind: data API 3.0 (endpoint flows)");
    $ids = array_column($fj['screens'], 'id');
    se_eq(array_keys($fj['routing_model']), $ids, "$kind: routing model covers every screen in order");
    foreach ($ids as $id) { se_ok(preg_match('/^[A-Za-z_]+$/', $id) === 1, "$kind: screen id '$id' is letters/underscores only (Meta refused HEALTH_1: PATTERN_MISMATCH)"); }
    se_ok(!in_array('SUCCESS', $ids, true), "$kind: SUCCESS is reserved");
    $terminals = array_filter($fj['screens'], function ($s) { return !empty($s['terminal']); });
    se_eq(1, count($terminals), "$kind: exactly one terminal screen");
    foreach ($fj['screens'] as $s) {
        $children = $s['layout']['children'];
        $count = 0; $footers = 0; $formFields = [];
        $walk = function ($nodes) use (&$walk, &$count, &$footers, &$formFields, $s, $kind) {
            foreach ($nodes as $c) {
                $count++;
                if ($c['type'] === 'Form') { $walk($c['children']); continue; }
                if ($c['type'] === 'Footer') { $footers++; se_ok(mb_strlen($c['label']) <= 35, "$kind/{$s['id']}: footer label ≤ 35"); se_eq('data_exchange', $c['on-click-action']['name'], "$kind/{$s['id']}: footer sends data_exchange"); }
                if (in_array($c['type'], ['TextInput', 'TextArea', 'Dropdown'], true)) { se_ok(mb_strlen($c['label']) <= 20, "$kind/{$s['id']}/{$c['name']}: label ≤ 20 ('{$c['label']}')"); }
                if (in_array($c['type'], ['RadioButtonsGroup', 'CheckboxGroup'], true)) { se_ok(mb_strlen($c['label']) <= 30, "$kind/{$s['id']}/{$c['name']}: label ≤ 30 ('{$c['label']}')"); }
                if (isset($c['data-source']) && is_array($c['data-source'])) {
                    $max = $c['type'] === 'Dropdown' ? 200 : 20;
                    se_ok(count($c['data-source']) >= 1 && count($c['data-source']) <= $max, "$kind/{$s['id']}/{$c['name']}: 1..$max options");
                    foreach ($c['data-source'] as $o) { se_ok(mb_strlen($o['title']) <= 30 && $o['id'] !== '', "$kind/{$s['id']}/{$c['name']}: option '{$o['title']}' ≤ 30"); }
                }
                if (isset($c['helper-text'])) { se_ok(mb_strlen($c['helper-text']) <= 80, "$kind/{$s['id']}/{$c['name']}: helper ≤ 80"); }
                if (isset($c['name']) && $c['type'] !== 'Form' && $c['type'] !== 'Footer') { $formFields[] = $c['name']; }
                if ($c['type'] === 'TextBody') { se_ok(mb_strlen($c['text']) <= 4096, "$kind/{$s['id']}: body ≤ 4096"); }
            }
        };
        $walk($children);
        se_ok($count <= 50, "$kind/{$s['id']}: ≤ 50 components ($count)");
        se_eq(1, $footers, "$kind/{$s['id']}: one footer");
        if (!empty($s['data'])) { foreach ($s['data'] as $k => $d) { se_ok(isset($d['type']) && array_key_exists('__example__', $d), "$kind/{$s['id']}: data '$k' has type + __example__"); } }
    }
}
$fj = se_journey_flow_json(1, 'intake');
se_eq(['CONSENT', 'IDENTITY', 'CONCERN', 'HEALTH_A', 'HEALTH_B'], array_column($fj['screens'], 'id'), 'intake screens');
// Every required questionnaire field is asked, with the same keys and option ids as the web form.
$asked = [];
foreach ($fj['screens'] as $s) { foreach ($s['layout']['children'] as $c) { if ($c['type'] === 'Form') { foreach ($c['children'] as $f) { if (isset($f['name'])) { $asked[$f['name']] = $f; } } } } }
foreach (se_journey_fields(1) as $key => $f) {
    if ($f['type'] === 'readonly') { continue; }
    se_ok(isset($asked[$key]), "questionnaire field '$key' is on a screen");
    if (isset($f['options']) && isset($asked[$key]['data-source'])) {
        se_eq(array_map('strval', array_keys($f['options'])), array_column($asked[$key]['data-source'], 'id'), "'$key': same option ids as the web form");
    }
}
se_ok(!isset($asked['whatsapp']), 'the read-only WhatsApp number field is not asked (the flow knows the sender)');

/* ======================================================================== */
se_group('Journey flows: the endpoint walks the intake and stores through the same functions as the web form');

se_test_seed_journey(['options' => ['se_journey_flows_1' => 1]]);
se_test_act_as(10, [], true);
$db = se_test_db();
se_test_install_secret('flow_key', $kp['private']);
$GLOBALS['se_test']['options']['se_journey_flow_id_intake_1'] = '10001';
$GLOBALS['se_test']['options']['se_journey_flow_status_intake_1'] = 'PUBLISHED';
$GLOBALS['se_test']['options']['se_journey_flow_id_booking_1'] = '10002';
$GLOBALS['se_test']['options']['se_journey_flow_status_booking_1'] = 'PUBLISHED';
se_eq(['ready' => true, 'reason' => '', 'flow_id' => '10001'], se_journey_flow_ready(1, 'intake'), 'intake flow ready');

// The enquiry arrives and the patient taps Start: the flow goes instead of the link.
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid(), ['name' => 'Ayşe']));
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'Başla', se_test_wamid()));
$j = se_test_journey_row();
se_eq('consent_pending', $j->state, 'state: consent_pending (the step happened)');
$last = end($GLOBALS['se_wa_sent']);
se_eq('interactive', $last['kind'], 'in-window: an interactive message');
se_eq('flow', $last['payload']['flow']['flow_action'] === 'data_exchange' ? 'flow' : 'x', 'a Flow CTA with data_exchange');
se_eq(['3', '10001', 'Formu Doldur', 'CONSENT'], [$last['payload']['flow']['flow_message_version'], $last['payload']['flow']['flow_id'], $last['payload']['flow']['flow_cta'], $last['payload']['flow']['flow_action_payload']['screen']], 'version, id, CTA (≤ 20), entry screen');
$flowToken = $last['payload']['flow']['flow_token'];
se_ok(strpos($flowToken, 'intake.') === 0, 'flow_token = kind.token');
se_ok(strpos($last['body'], 'WhatsApp içinde') !== false, 'the body explains the in-chat form');
se_ok(strpos((string) $last['body'], '/se_journey/intake/') === false, 'no CRM link in the flow message');
$ip = se_wa_interactive_payload($last['body'], $last['payload']);
se_eq(['flow', 'flow', '10001'], [$ip['type'], $ip['action']['name'], $ip['action']['parameters']['flow_id']], 'Cloud API interactive.type=flow with action.name=flow');

// ping
se_eq(['data' => ['status' => 'active']], se_journey_flow_handle(['version' => '3.0', 'action' => 'ping']), 'ping → active');
// bad token → a graceful SUCCESS (the flow closes) with result=expired
$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'INIT', 'flow_token' => 'intake.nope']);
se_eq(['SUCCESS', 'expired'], [$r['screen'], $r['data']['extension_message_response']['params']['result']], 'unknown token → closes gracefully');

// INIT → CONSENT with the notice from Consent Settings.
$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'INIT', 'flow_token' => $flowToken]);
se_eq('CONSENT', $r['screen'], 'INIT → CONSENT');
se_ok(strpos($r['data']['notice'], 'Sağlık verilerimin değerlendirme amacıyla işlenmesine açık rıza veriyorum') !== false, 'the configured health-data text is shown');
se_ok(mb_strlen($r['data']['health_label']) <= 120 && mb_strlen($r['data']['marketing_label']) <= 120, 'opt-in labels ≤ 120');

// CONSENT declined → the flow closes; journey consent_declined.
$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'data_exchange', 'screen' => 'CONSENT', 'flow_token' => $flowToken, 'data' => ['consent_health_data' => false, 'consent_photo_publication' => false, 'consent_marketing' => false]]);
se_eq(['SUCCESS', 'consent_declined'], [$r['screen'], $r['data']['extension_message_response']['params']['result']], 'declined → closes');
se_eq('consent_declined', se_test_journey_row()->state, 'state: consent_declined');

// CONSENT granted (+ marketing) → IDENTITY; consent ledger written by the same function as the web form.
$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'data_exchange', 'screen' => 'CONSENT', 'flow_token' => $flowToken, 'data' => ['consent_health_data' => true, 'consent_photo_publication' => false, 'consent_marketing' => true]]);
se_eq('IDENTITY', $r['screen'], 'granted → IDENTITY');
$cs = se_journey_consent_state(se_test_journey_row());
se_eq([true, true, false], [$cs['health_data'], $cs['marketing'], $cs['photo_publication']], 'consents recorded');
se_eq('intake_started', se_test_journey_row()->state, 'state: intake_started');

// INIT again (the patient reopens the flow) resumes at IDENTITY, not CONSENT.
$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'INIT', 'flow_token' => $flowToken]);
se_eq('IDENTITY', $r['screen'], 'reopening resumes after consent');

// IDENTITY → CONCERN (saved, sealed).
$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'data_exchange', 'screen' => 'IDENTITY', 'flow_token' => $flowToken,
    'data' => ['full_name' => 'Ayşe Örnek', 'age' => '34', 'country' => 'TR', 'city' => 'İzmir', 'preferred_language' => 'tr', 'contact_time' => 'afternoon', 'contact_channel' => 'whatsapp']]);
se_eq('CONCERN', $r['screen'], 'IDENTITY → CONCERN');
$intake = $db->rows('tblse_journey_intakes')[0];
se_ok(strpos((string) $intake['answers_enc'], 'Ayşe') === false && $intake['answers_enc'] !== '', 'answers sealed at rest');
se_eq('Ayşe Örnek', se_journey_intake_answers(se_journey_intake_get(se_test_journey_row()))['full_name'], 'and readable by the journey');

// CONCERN with a required field left out ('areas') → still saved (autosave), moves on.
$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'data_exchange', 'screen' => 'CONCERN', 'flow_token' => $flowToken,
    'data' => ['main_concern' => ['sparse'], 'onset' => 'gt3y', 'progression' => 'stable', 'previous_transplant' => 'no', 'previous_procedures' => ['none'], 'timing' => 'asap']]);
se_eq('HEALTH_A', $r['screen'], 'CONCERN → HEALTH_A');
// An invalid value → same screen with the validation message.
$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'data_exchange', 'screen' => 'HEALTH_A', 'flow_token' => $flowToken,
    'data' => ['pregnancy' => 'maybe', 'chronic' => ['none'], 'skin' => ['none'], 'allergies' => ['none']]]);
se_eq('HEALTH_A', $r['screen'], 'an invalid option keeps the screen');
se_ok($r['data']['error_message'] !== '', 'with an error message: ' . $r['data']['error_message']);
$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'data_exchange', 'screen' => 'HEALTH_A', 'flow_token' => $flowToken,
    'data' => ['pregnancy' => 'no', 'chronic' => ['none'], 'skin' => ['none'], 'allergies' => ['none']]]);
se_eq('HEALTH_B', $r['screen'], 'HEALTH_A → HEALTH_B');
// Final screen: the missing 'areas' from CONCERN sends the patient back there.
$h2 = ['blood_thinners' => 'yes', 'blood_thinners_detail' => 'aspirin', 'smoking' => 'no', 'alcohol' => 'no', 'anesthesia_complications' => 'no'];
$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'data_exchange', 'screen' => 'HEALTH_B', 'flow_token' => $flowToken, 'data' => $h2]);
se_eq('CONCERN', $r['screen'], 'submit with a missing earlier answer → back to that screen');
se_ok(strpos($r['data']['error_message'], 'Etkilenen bölgeler') !== false, 'naming the field');
$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'data_exchange', 'screen' => 'CONCERN', 'flow_token' => $flowToken, 'data' => ['areas' => ['tail']]]);
se_eq('HEALTH_A', $r['screen'], 'the missing answer saved');
$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'data_exchange', 'screen' => 'HEALTH_B', 'flow_token' => $flowToken, 'data' => $h2]);
se_wa_out_drain();
se_eq(['SUCCESS', 'intake_submitted'], [$r['screen'], $r['data']['extension_message_response']['params']['result']], 'HEALTH_B → SUCCESS (intake_submitted)');
se_eq($flowToken, $r['data']['extension_message_response']['params']['flow_token'], 'the flow_token travels back');
$j = se_test_journey_row();
se_eq('photos_requested', $j->state, 'state: photos_requested (the photo request went out as before)');
$intake = $db->rows('tblse_journey_intakes')[0];
se_eq('submitted', $intake['status'], 'intake submitted');
se_eq(['anticoagulant_reported'], json_decode($intake['flags_json'], true), 'the same review flags as the web form');
$answers = se_journey_intake_answers(se_journey_intake_get($j));
se_eq(['Ayşe Örnek', '34', ['tail'], 'aspirin'], [$answers['full_name'], (string) $answers['age'], $answers['areas'], $answers['blood_thinners_detail']], 'sealed answers hold every screen');
se_ok(count(array_filter($db->rows('tblse_journey_events'), function ($e) { return $e['kind'] === 'flow_step'; })) >= 5, 'each screen left an event');
$lead = null; foreach ($db->rows('tblleads') as $l) { if ((int) $l['id'] === (int) $j->lead_id) { $lead = $l; } }
se_eq(['Ayşe Örnek', 228, 'İzmir'], [$lead['name'], (int) $lead['country'], $lead['city']], 'the lead followed (name, country, city)');
// Reopening after submission closes at once.
$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'INIT', 'flow_token' => $flowToken]);
se_eq(['SUCCESS', 'already_submitted'], [$r['screen'], $r['data']['extension_message_response']['params']['result']], 'a second run closes');

// The completion receipt (nfm_reply) is an event, never a data path.
$before = $db->rows('tblse_journey_intakes')[0]['answers_hash'];
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['interactive' => ['type' => 'nfm_reply', 'nfm_reply' => ['name' => 'flow', 'body' => 'Sent', 'response_json' => json_encode(['flow_token' => $flowToken, 'result' => 'intake_submitted', 'full_name' => 'Mallory'])]]]));
se_eq(1, count(array_filter($db->rows('tblse_journey_events'), function ($e) { return $e['kind'] === 'flow_completed'; })), 'flow_completed event');
se_eq($before, $db->rows('tblse_journey_intakes')[0]['answers_hash'], 'the receipt changed nothing in the sealed answers');
se_eq('photos_requested', se_test_journey_row()->state, 'state unchanged by the receipt');

/* ======================================================================== */
se_group('Journey flows: the booking flow lists live slots and books through the model');

se_test_journey_reviewed();
$db = se_test_db();
se_test_install_secret('flow_key', $kp['private']);
foreach (['se_journey_flows_1' => 1, 'se_journey_flow_id_booking_1' => '10002', 'se_journey_flow_status_booking_1' => 'PUBLISHED',
          'se_journey_booking_hours_1' => '10:00-12:00', 'se_journey_booking_days_1' => '1,2,3,4,5', 'se_journey_booking_location_1' => 'Klinik, İstanbul'] as $k => $v) {
    $GLOBALS['se_test']['options'][$k] = $v;
}
se_test_act_as(10, [], true);
se_journey_review_open(se_test_journey_row(), 10);
se_journey_review_save(se_test_journey_row(), ['decision' => 'provisionally_suitable'], 10);
se_journey_quote_draft(se_test_journey_row(), ['currency' => 'EUR', 'amount_min' => '1500', 'amount_max' => '2200', 'show_amount' => 1, 'recommendation' => 'procedure_after_consultation'], 10);
$q = $db->rows('tblse_journey_quotes')[0];
se_journey_quote_approve((int) $q['id'], 10);
se_journey_quote_send((int) $q['id'], 10);
se_wa_out_drain();
se_test_act_as(0, [], false);
se_authz_reset_cache();
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(), ['interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'jr_quote_accept', 'title' => 'Teklifi Kabul Et']]]));
se_eq('quote_accepted', se_test_journey_row()->state, 'accepted');
$last = end($GLOBALS['se_wa_sent']);
se_eq(['interactive', '10002', 'Tarih Seç', 'DAY'], [$last['kind'], $last['payload']['flow']['flow_id'], $last['payload']['flow']['flow_cta'], $last['payload']['flow']['flow_action_payload']['screen']], 'the booking FLOW went instead of the calendar link');
se_ok(strpos((string) $last['body'], '/book') === false, 'no link');
$bookToken = $last['payload']['flow']['flow_token'];

$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'INIT', 'flow_token' => $bookToken]);
se_eq('DAY', $r['screen'], 'INIT → DAY');
se_ok(count($r['data']['days']) >= 1 && count($r['data']['days']) <= 20, 'days listed (' . count($r['data']['days']) . ')');
foreach ($r['data']['days'] as $d) { se_ok(preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['id']) === 1 && mb_strlen($d['title']) <= 30, 'day option ' . $d['title']); }
$day = $r['data']['days'][0]['id'];
$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'data_exchange', 'screen' => 'DAY', 'flow_token' => $bookToken, 'data' => ['day' => $day]]);
se_eq('TIME', $r['screen'], 'DAY → TIME');
se_eq(['10:00', '10:30', '11:00', '11:30'], array_column($r['data']['slots'], 'title'), 'four half-hour slots between 10:00 and 12:00');
$slot = $r['data']['slots'][1]['id'];
$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'data_exchange', 'screen' => 'TIME', 'flow_token' => $bookToken, 'data' => ['slot' => $slot]]);
se_wa_out_drain();
se_eq(['SUCCESS', 'booked'], [$r['screen'], $r['data']['extension_message_response']['params']['result']], 'TIME → SUCCESS (booked)');
$j = se_test_journey_row();
se_eq('consultation_booked', $j->state, 'state: consultation_booked');
$a = null; foreach ($db->rows('tblse_appointments') as $row) { if ((int) $row['id'] === (int) $j->consultation_appointment_id) { $a = $row; } }
se_eq([$slot, 'in_person', 'Klinik, İstanbul'], [$a['start_at'], $a['consultation_format'], $a['location']], 'the appointment, face to face, at the clinic');
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], 'klinikte görüşmeniz oluşturuldu') !== false, 'confirmation (with the calendar link) sent');
se_ok(strpos((string) $a['notes'], 'flow') !== false, 'noted as booked from the flow');
// A second run closes at once.
$r = se_journey_flow_handle(['version' => '3.0', 'action' => 'INIT', 'flow_token' => $bookToken]);
se_eq(['SUCCESS', 'already_booked'], [$r['screen'], $r['data']['extension_message_response']['params']['result']], 'reopening after booking closes');

/* ======================================================================== */
se_group('Journey flows: outside the window the template with the FLOW button goes; without a flow the link stays');

se_test_seed_journey(['options' => ['se_journey_flows_1' => 1, 'se_journey_flow_id_intake_1' => '10001', 'se_journey_flow_status_intake_1' => 'PUBLISHED']]);
se_test_act_as(10, [], true);
$db = se_test_db();
se_test_install_secret('flow_key', $kp['private']);
se_journey_seed_templates(1);
// The registry template carries a FLOW button whose id is resolved at submission.
$captured = null;
se_journey_register_template_submitter(function ($waba, $definition) use (&$captured) { $captured = $definition; return ['ok' => true, 'id' => '777', 'status' => 'PENDING', 'category' => 'UTILITY']; });
$r = se_journey_submit_template(1, 'eyebrow_intake_flow_tr', 10);
se_eq(true, $r['ok'], 'flow template submitted');
se_eq(['BODY', 'BUTTONS'], array_column($captured['components'], 'type'), 'BODY + BUTTONS');
se_eq(['type' => 'FLOW', 'text' => 'Formu Doldur', 'flow_id' => '10001', 'flow_action' => 'data_exchange'], $captured['components'][1]['buttons'][0], 'a FLOW button bound to the created flow — no navigate_screen with data_exchange (Meta 2388203)');
$GLOBALS['se_test']['options']['se_journey_flow_id_booking_1'] = '';
se_eq(['ok' => false, 'reason' => 'flow_not_created'], se_journey_submit_template(1, 'eyebrow_booking_flow_tr', 10), 'no flow id yet → the template cannot be submitted');

foreach ($db->tables['tblse_journey_templates'] as &$row) { if ($row['logical_name'] === 'eyebrow_intake_flow_tr') { $row['approval_status'] = 'approved'; } }
unset($row);
$db->seed('tblse_wa_templates', [['id' => 1, 'brand_id' => 1, 'name' => 'eyebrow_intake_flow_tr', 'language' => 'tr', 'category' => 'UTILITY', 'approval_state' => 'approved', 'variables' => '1']]);
se_test_wa_deliver(se_test_wa_body('905000000004', 'kaş ekimi fiyat', se_test_wamid(), ['name' => 'Elif']));
foreach ($db->tables['tblse_wa_conversations'] as &$c) { $c['window_expires_at'] = date('Y-m-d H:i:s', time() - 86400); }
unset($c);
$j = se_test_journey_row();
se_journey_transition($j, 'welcome_sent', 'test', 'staff', 10, null, null, true);
$r = se_journey_send_privacy_and_link(se_test_journey_row(), 'staff:10', 'staff', 10);
se_wa_out_drain();
se_eq(true, $r['ok'], 'sent');
$last = end($GLOBALS['se_wa_sent']);
se_eq(['template', 'eyebrow_intake_flow_tr'], [$last['kind'], $last['template']], 'the FLOW-button template');
se_ok(strpos((string) $last['payload']['flow_button']['flow_token'], 'intake.') === 0, 'with a per-message flow_token');
$p = se_wa_template_send_payload($last);
$btn = end($p['template']['components']);
se_eq(['button', 'flow', '0', 'action'], [$btn['type'], $btn['sub_type'], $btn['index'], $btn['parameters'][0]['type']], 'Cloud API button component sub_type flow');
se_eq($last['payload']['flow_button']['flow_token'], $btn['parameters'][0]['action']['flow_token'], 'carrying the flow_token');

// Flows switched off → the classic link, unchanged.
$GLOBALS['se_test']['options']['se_journey_flows_1'] = 0;
foreach ($db->tables['tblse_wa_conversations'] as &$c) { $c['window_expires_at'] = date('Y-m-d H:i:s', time() + 3600); }
unset($c);
$r = se_journey_send_privacy_and_link(se_test_journey_row(), 'staff:10', 'staff', 10);
se_wa_out_drain();
se_ok(strpos(end($GLOBALS['se_wa_sent'])['body'], '/se_journey/intake/') !== false, 'flows off → the secure link');
se_eq(['ready' => false, 'reason' => 'flows_off', 'flow_id' => ''], se_journey_flow_ready(1, 'intake'), 'readiness names the reason');

/* ======================================================================== */
se_group('Journey flows: the composer cannot send a FLOW-button template as a plain template — it goes through the journey');

// Production, 2026-09-03: a staff member picked eyebrow_intake_flow_tr in the
// conversation composer. The mirror row had been inserted by the approval
// webhook alone (no body, no variables), so no placeholder was asked for, no
// flow token existed, and Meta refused the send: (#132000) "Number of
// parameters does not match the expected number of params".
se_test_seed_journey(['options' => ['se_journey_flows_1' => 1, 'se_journey_flow_id_intake_1' => '10001', 'se_journey_flow_status_intake_1' => 'PUBLISHED',
                                     'se_journey_flow_id_booking_1' => '10002', 'se_journey_flow_status_booking_1' => 'PUBLISHED']]);
se_test_act_as(10, [], true);
$db = se_test_db();
se_test_install_secret('flow_key', $kp['private']);
se_journey_seed_templates(1);
foreach ($db->tables['tblse_journey_templates'] as &$row) { if (in_array($row['logical_name'], ['eyebrow_intake_flow_tr', 'eyebrow_booking_flow_tr', 'eyebrow_journey_start_tr'], true)) { $row['approval_status'] = 'approved'; } }
unset($row);

// The registry knows what the mirror does not yet.
$hint = se_journey_template_hint(1, 'eyebrow_intake_flow_tr');
se_eq(['1'], $hint['variables'], 'the registry names the {{1}} placeholder');
se_eq('intake', $hint['flow_kind'], 'and the FLOW button (intake)');
se_eq('booking', se_journey_template_hint(1, 'eyebrow_booking_flow_tr')['flow_kind'], 'booking flow template');
se_eq('', se_journey_template_hint(1, 'eyebrow_journey_start_tr')['flow_kind'], 'a plain template has no flow');
se_eq(null, se_journey_template_hint(1, 'no_such_template'), 'unknown template → null');
se_eq(null, se_journey_template_hint(2, 'eyebrow_intake_flow_tr'), 'another brand → null');

// The approval webhook inserts the mirror row WITH the registry's body and placeholders, and asks for a forced re-pull.
se_eq(true, se_wa_handle_template_status(1, ['event' => 'APPROVED', 'message_template_id' => '1362571199278702', 'message_template_name' => 'eyebrow_intake_flow_tr', 'message_template_language' => 'tr']), 'status webhook applied');
$mirror = null; foreach ($db->rows('tblse_wa_templates') as $t) { if ($t['name'] === 'eyebrow_intake_flow_tr') { $mirror = $t; } }
se_eq('approved', $mirror['approval_state'], 'mirror row approved');
se_eq('1', $mirror['variables'], 'the mirror row carries the placeholder list right away (no fifteen-minute gap)');
se_ok(strpos((string) $mirror['body'], '{{1}}') !== false, 'and the body');
se_eq('1', get_option('se_wa_templates_resync_1'), "Meta's copy is still requested: forced re-pull flagged");
// Forced re-pull runs on the next cron even though the last sync is fresh; the flag clears.
update_option('se_wa_templates_synced_at_1', se_db_now());
$pulled = 0;
se_wa_register_template_fetcher(function ($waba) use (&$pulled) { $pulled++; return ['ok' => true, 'templates' => [['name' => 'eyebrow_intake_flow_tr', 'language' => 'tr', 'status' => 'APPROVED', 'category' => 'UTILITY',
    'components' => [['type' => 'BODY', 'text' => 'Merhaba {{1}}, form.'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'FLOW', 'text' => 'Formu Doldur']]]]]]]; });
se_eq(1, se_wa_sync_templates_cron(), 'the cron pulled despite the throttle');
se_eq(1, $pulled, 'exactly once');
se_eq('0', get_option('se_wa_templates_resync_1'), 'flag cleared');
se_eq(0, se_wa_sync_templates_cron(), 'and the throttle applies again');
$mirror = null; foreach ($db->rows('tblse_wa_templates') as $t) { if ($t['name'] === 'eyebrow_intake_flow_tr') { $mirror = $t; } }
se_eq('Merhaba {{1}}, form.', $mirror['body'], "Meta's copy replaced the registry's");
se_eq('UTILITY', $mirror['category'], 'with its category');
$GLOBALS['SE_WA_TEMPLATE_FETCHER'] = null;
$db->seed('tblse_wa_templates', [
    ['id' => 1, 'brand_id' => 1, 'name' => 'eyebrow_intake_flow_tr', 'language' => 'tr', 'category' => 'UTILITY', 'approval_state' => 'approved', 'body' => null, 'variables' => null],
    ['id' => 2, 'brand_id' => 1, 'name' => 'eyebrow_booking_flow_tr', 'language' => 'tr', 'category' => 'UTILITY', 'approval_state' => 'approved', 'body' => null, 'variables' => null],
    ['id' => 3, 'brand_id' => 1, 'name' => 'eyebrow_journey_start_tr', 'language' => 'tr', 'category' => 'UTILITY', 'approval_state' => 'approved', 'variables' => '1'],
]);

// A thread whose window has closed, with a journey at consent_pending.
se_test_wa_deliver(se_test_wa_body('905000000005', 'kaş ekimi fiyat', se_test_wamid(), ['name' => 'Elif']));
foreach ($db->tables['tblse_wa_conversations'] as &$c) { $c['window_expires_at'] = date('Y-m-d H:i:s', time() - 86400); }
unset($c);
$j = se_test_journey_row();
se_journey_transition($j, 'welcome_sent', 'test', 'staff', 10, null, null, true);
se_journey_transition(se_test_journey_row(), 'privacy_notice_sent', 'test', 'staff', 10, null, null, true);
se_journey_transition(se_test_journey_row(), 'consent_pending', 'test', 'staff', 10, null, null, true);
$conv = null; foreach ($db->rows('tblse_wa_conversations') as $c) { $conv = (object) $c; }

// 1. The plain-template path (what the composer used to do) is refused at queue time, before Meta.
$before = count($db->rows('tblse_wa_outbound'));
$r = se_wa_queue_message((int) $conv->id, ['kind' => 'template', 'template' => 'eyebrow_intake_flow_tr', 'variables' => []], 10);
se_eq('flow_button_required', $r['reason'], 'a FLOW-button template without a flow token is refused at queue time');
$r = se_wa_queue_message((int) $conv->id, ['kind' => 'template', 'template' => 'eyebrow_intake_flow_tr', 'variables' => ['Elif']], 10);
se_eq('flow_button_required', $r['reason'], 'even with the name filled in');
se_eq($before, count($db->rows('tblse_wa_outbound')), 'nothing queued');
// A body-less mirror row still gets its placeholders from the registry for a plain template.
$r = se_wa_queue_message((int) $conv->id, ['kind' => 'template', 'template' => 'eyebrow_journey_start_tr', 'variables' => []], 10);
se_eq('template_variables', $r['reason'], 'a plain template with a placeholder still needs its value');

// 2. The composer routes the flow template through the journey: outside the window → the template WITH its flow token.
$r = se_journey_compose_template($conv, 'eyebrow_intake_flow_tr', 10);
se_wa_out_drain();
se_eq(true, $r['ok'], 'sent through the journey');
se_eq('template', $r['mode'], 'window closed → the approved template');
$last = end($GLOBALS['se_wa_sent']);
se_eq(['template', 'eyebrow_intake_flow_tr'], [$last['kind'], $last['template']], 'the FLOW-button template');
se_eq(['Elif'], $last['variables'], 'with the {{1}} name');
se_ok(strpos((string) $last['payload']['flow_button']['flow_token'], 'intake.') === 0, 'and a per-message flow token');
$p = se_wa_template_send_payload($last);
se_eq(['body', 'button'], array_column($p['template']['components'], 'type'), 'Cloud API: body parameters + the flow button component (what #132000 was missing)');
se_eq(1, count($p['template']['components'][0]['parameters']), 'one body parameter');
se_eq('consent_pending', se_test_journey_row()->state, 'journey state as after a staff "Resend link"');
$audit = array_filter($db->rows('tblse_journey_audit'), function ($a) { return $a['action'] === 'composer_flow_template'; });
se_eq(1, count($audit), 'audited as a composer send');

// Inside the window → the interactive Flow message, never the template.
foreach ($db->tables['tblse_wa_conversations'] as &$c) { $c['window_expires_at'] = date('Y-m-d H:i:s', time() + 3600); }
unset($c);
$r = se_journey_compose_template($conv, 'eyebrow_intake_flow_tr', 10);
se_wa_out_drain();
se_eq(['inwindow', true], [$r['mode'], $r['ok']], 'window open → in-window');
$last = end($GLOBALS['se_wa_sent']);
se_eq('interactive', $last['kind'], 'the Flow message');
se_eq(['10001', 'CONSENT'], [$last['payload']['flow']['flow_id'] ?? '', $last['payload']['flow']['flow_action_payload']['screen'] ?? ''], 'the intake Flow CTA, entry screen CONSENT');

// The booking template goes through the calendar step.
$r = se_journey_compose_template($conv, 'eyebrow_booking_flow_tr', 10);
se_wa_out_drain();
se_eq(true, $r['ok'], 'booking flow sent through the journey');
$last = end($GLOBALS['se_wa_sent']);
se_eq('10002', $last['payload']['flow']['flow_id'] ?? '', 'the booking flow');

// 3. Not a flow template → null: the composer proceeds as before.
se_eq(null, se_journey_compose_template($conv, 'eyebrow_journey_start_tr', 10), 'a plain template is not routed');
se_eq(null, se_journey_compose_template($conv, 'unknown_tpl', 10), 'an unknown template is not routed');

// 4. No journey on the thread → a clear reason, nothing sent; no permission → refused.
$db->tables['tblse_wa_conversations'][] = ['id' => 77, 'brand_id' => 1, 'phone_number_id' => SE_TEST_PN, 'wa_user_id' => '905000000099', 'lead_id' => 0, 'client_id' => 0,
    'assigned_staff' => 0, 'unread_count' => 0, 'last_inbound_at' => date('Y-m-d H:i:s'), 'window_expires_at' => date('Y-m-d H:i:s', time() + 3600), 'state' => 'open', 'date_created' => date('Y-m-d H:i:s'), 'last_updated' => date('Y-m-d H:i:s')];
$sentBefore = count($GLOBALS['se_wa_sent']);
$r = se_journey_compose_template((object) end($db->tables['tblse_wa_conversations']), 'eyebrow_intake_flow_tr', 10);
se_eq(['ok' => false, 'reason' => 'journey_required'], ['ok' => $r['ok'], 'reason' => $r['reason']], 'no journey → journey_required');
se_test_act_as(11, ['view'], false);
$r = se_journey_compose_template($conv, 'eyebrow_intake_flow_tr', 11);
se_eq('journey_permission', $r['reason'], 'a role without edit_review cannot operate the journey from the composer');
se_test_act_as(10, [], true);
se_eq($sentBefore, count($GLOBALS['se_wa_sent']), 'nothing sent in either case');

// 5. Sandbox: recorded on the timeline, not sent — and the composer is told so.
update_option('se_journey_sandbox_1', 1);
$sentBefore = count($GLOBALS['se_wa_sent']);
$r = se_journey_compose_template($conv, 'eyebrow_intake_flow_tr', 10);
se_wa_out_drain();
se_eq(['ok' => true, 'mode' => 'sandbox'], ['ok' => $r['ok'], 'mode' => $r['mode']], 'sandbox mode reported');
se_eq($sentBefore, count($GLOBALS['se_wa_sent']), 'nothing sent');
update_option('se_journey_sandbox_1', 0);
