<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * se_journey — WhatsApp Flows: the intake form and the consultation calendar
 * INSIDE WhatsApp, in place of the CRM links.
 *
 * Two flows, both driven by this CRM as their Data Endpoint (encrypted
 * data_exchange, Meta's "Flows Data API" v3.0):
 *
 *   intake   CONSENT → IDENTITY → CONCERN → HEALTH_A → HEALTH_B → SUCCESS
 *            every screen is validated and sealed by the same functions the
 *            web form uses (se_journey_record_form_consent, _intake_save,
 *            _intake_submit) — one questionnaire, two front doors.
 *   booking  DAY → TIME → SUCCESS, slots from se_journey_booking_slots(),
 *            booked by se_journey_booking_pick() (model lock + re-check).
 *
 * TRANSPORT SECURITY (Meta's contract, implemented here without extensions):
 *   request  = {encrypted_flow_data, encrypted_aes_key, initial_vector}
 *   AES key  = RSA-OAEP(SHA-256, MGF1-SHA-256) decrypt with the business
 *              private key (secret provider `flow_key`; PHP's openssl does
 *              OAEP with SHA-1 only, so the OAEP unpadding is done by hand
 *              over a raw RSA decrypt)
 *   payload  = AES-128-GCM, 16-byte tag appended, IV as given
 *   response = AES-128-GCM with the SAME key and the bit-inverted IV, tag
 *              appended, base64 — plain-text HTTP body
 *   signature: X-Hub-Signature-256 over the raw body with the app secret
 *   status   : 432 bad signature, 421 undecryptable (Meta re-fetches the
 *              public key), 200 otherwise
 *
 * IDENTITY: the flow_token we mint per message is "<kind>.<token>", the
 * token being a journey token of purpose `flow` (7 days). Nothing about the
 * patient travels inside the flow_token.
 *
 * WHEN A LINK IS STILL USED: flows are per-brand optional
 * (`se_journey_flows_<brand>`), need the private key + the published flow
 * ids; when anything is missing the journey silently keeps the CRM links.
 * Photos stay on WhatsApp itself (already supported); the quote answer is
 * already reply buttons.
 */

define('SE_JOURNEY_FLOW_JSON_VERSION', '6.3');
define('SE_JOURNEY_FLOW_DATA_API_VERSION', '3.0');
define('SE_JOURNEY_FLOW_MESSAGE_VERSION', '3');

/* ===========================================================================
 * Configuration
 * ======================================================================== */

function se_journey_flow_kinds()
{
    return [
        'intake'  => ['name' => 'eyebrow_intake_tr',  'categories' => ['SIGN_UP', 'LEAD_GENERATION'], 'entry' => 'CONSENT',
                      'cta' => 'Formu Doldur', 'template' => 'eyebrow_intake_flow_tr'],
        'booking' => ['name' => 'eyebrow_booking_tr', 'categories' => ['APPOINTMENT_BOOKING'], 'entry' => 'DAY',
                      'cta' => 'Tarih Seç', 'template' => 'eyebrow_booking_flow_tr'],
    ];
}

/** Per-brand switch: use flows instead of links where a published flow exists. */
function se_journey_flows_enabled($brand_id)
{
    return (int) get_option('se_journey_flows_' . (int) $brand_id) === 1;
}

function se_journey_flow_id($brand_id, $kind)
{
    return trim((string) get_option('se_journey_flow_id_' . $kind . '_' . (int) $brand_id));
}

function se_journey_flow_status($brand_id, $kind)
{
    return trim((string) get_option('se_journey_flow_status_' . $kind . '_' . (int) $brand_id));
}

/** The Meta app id the flows belong to (Meta signs endpoint requests with that app's secret). */
function se_journey_flow_app_id()
{
    return preg_replace('/\D/', '', (string) get_option('se_journey_flow_app_id'));
}

function se_journey_flow_endpoint_url()
{
    return se_journey_public_url('se_journey/flow');
}

/** Private key PEM from the secret store ('' when not installed). */
function se_journey_flow_private_key()
{
    return function_exists('se_secret_read') ? se_secret_read('flow_key') : '';
}

/** Public key PEM derived from the private key ('' when absent/invalid). */
function se_journey_flow_public_key()
{
    $pem = se_journey_flow_private_key();
    if ($pem === '') {
        return '';
    }
    $k = @openssl_pkey_get_private($pem);
    if (!$k) {
        return '';
    }
    $d = openssl_pkey_get_details($k);

    return is_array($d) && !empty($d['key']) ? (string) $d['key'] : '';
}

/** A fresh 2048-bit RSA key pair (PEM). Used by the owner's install step; never stored by this function. */
function se_journey_flow_keypair_generate()
{
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (!$res) {
        return ['ok' => false, 'private' => '', 'public' => ''];
    }
    $private = '';
    openssl_pkey_export($res, $private);
    $d = openssl_pkey_get_details($res);

    return ['ok' => $private !== '' && !empty($d['key']), 'private' => $private, 'public' => (string) ($d['key'] ?? '')];
}

/**
 * Is a flow usable for sending for this brand+kind? published id + key + switch.
 * @return array{ready:bool,reason:string,flow_id:string}
 */
function se_journey_flow_ready($brand_id, $kind)
{
    if (!isset(se_journey_flow_kinds()[$kind])) {
        return ['ready' => false, 'reason' => 'unknown_kind', 'flow_id' => ''];
    }
    if (!se_journey_flows_enabled($brand_id)) {
        return ['ready' => false, 'reason' => 'flows_off', 'flow_id' => ''];
    }
    if (se_journey_flow_private_key() === '') {
        return ['ready' => false, 'reason' => 'no_key', 'flow_id' => ''];
    }
    $id = se_journey_flow_id($brand_id, $kind);
    if ($id === '') {
        return ['ready' => false, 'reason' => 'not_created', 'flow_id' => ''];
    }
    if (strtoupper(se_journey_flow_status($brand_id, $kind)) !== 'PUBLISHED') {
        return ['ready' => false, 'reason' => 'not_published', 'flow_id' => $id];
    }

    return ['ready' => true, 'reason' => '', 'flow_id' => $id];
}

/* ===========================================================================
 * Crypto
 * ======================================================================== */

/** MGF1 with SHA-256 (RFC 8017 B.2.1). */
function se_journey_flow_mgf1($seed, $length)
{
    $out = '';
    for ($counter = 0; strlen($out) < $length; $counter++) {
        $out .= hash('sha256', $seed . pack('N', $counter), true);
    }

    return substr($out, 0, $length);
}

/**
 * RSAES-OAEP decryption with SHA-256 / MGF1-SHA-256 and an empty label
 * (RFC 8017 §7.1.2) over a raw RSA private operation. Returns '' on any
 * failure, without saying which (padding oracles are a thing).
 */
function se_journey_flow_rsa_oaep_decrypt($ciphertext, $privateKeyPem)
{
    $key = @openssl_pkey_get_private($privateKeyPem);
    if (!$key) {
        return '';
    }
    $details = openssl_pkey_get_details($key);
    $k = (int) (($details['bits'] ?? 0) / 8);
    if ($k <= 0 || strlen($ciphertext) !== $k) {
        return '';
    }
    $em = '';
    if (!@openssl_private_decrypt($ciphertext, $em, $key, OPENSSL_NO_PADDING) || strlen($em) !== $k) {
        return '';
    }
    $hLen = 32;
    if ($k < 2 * $hLen + 2) {
        return '';
    }
    $lHash      = hash('sha256', '', true);
    $y          = ord($em[0]);
    $maskedSeed = substr($em, 1, $hLen);
    $maskedDB   = substr($em, 1 + $hLen);
    $seed       = $maskedSeed ^ se_journey_flow_mgf1($maskedDB, $hLen);
    $db         = $maskedDB ^ se_journey_flow_mgf1($seed, $k - $hLen - 1);
    $lHash2     = substr($db, 0, $hLen);
    $rest       = substr($db, $hLen);
    // Constant-time-ish scan for 0x00* 0x01 M.
    $sep = -1; $bad = $y;
    for ($i = 0, $n = strlen($rest); $i < $n; $i++) {
        $c = ord($rest[$i]);
        if ($sep < 0 && $c === 1) { $sep = $i; }
        elseif ($sep < 0 && $c !== 0) { $bad |= 1; }
    }
    if ($bad !== 0 || $sep < 0 || !hash_equals($lHash, $lHash2)) {
        return '';
    }

    return substr($rest, $sep + 1);
}

/**
 * Decrypt a Flow endpoint request body.
 * @return array{ok:bool,reason:string,payload:array,aes_key:string,iv:string}
 */
function se_journey_flow_decrypt(array $body, $privateKeyPem)
{
    $fail = function ($reason) { return ['ok' => false, 'reason' => $reason, 'payload' => [], 'aes_key' => '', 'iv' => '']; };
    foreach (['encrypted_flow_data', 'encrypted_aes_key', 'initial_vector'] as $k) {
        if (empty($body[$k]) || !is_string($body[$k])) {
            return $fail('missing_' . $k);
        }
    }
    $encKey = base64_decode($body['encrypted_aes_key'], true);
    $data   = base64_decode($body['encrypted_flow_data'], true);
    $iv     = base64_decode($body['initial_vector'], true);
    if ($encKey === false || $data === false || $iv === false || strlen($data) <= 16) {
        return $fail('bad_base64');
    }
    $aesKey = se_journey_flow_rsa_oaep_decrypt($encKey, $privateKeyPem);
    if (strlen($aesKey) !== 16) {
        return $fail('aes_key');
    }
    $tag = substr($data, -16);
    $ct  = substr($data, 0, -16);
    $plain = openssl_decrypt($ct, 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        return $fail('aes_gcm');
    }
    $payload = json_decode($plain, true);
    if (!is_array($payload)) {
        return $fail('json');
    }

    return ['ok' => true, 'reason' => '', 'payload' => $payload, 'aes_key' => $aesKey, 'iv' => $iv];
}

/** Encrypt a response: AES-128-GCM with the request key and the bit-inverted IV; base64(ciphertext||tag). */
function se_journey_flow_encrypt(array $response, $aesKey, $iv)
{
    $flipped = '';
    for ($i = 0, $n = strlen($iv); $i < $n; $i++) {
        $flipped .= chr(ord($iv[$i]) ^ 0xFF);
    }
    $tag = '';
    $ct  = openssl_encrypt(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $flipped, $tag, '', 16);
    if ($ct === false) {
        return '';
    }

    return base64_encode($ct . $tag);
}

/** The counterpart used by tests and by Meta: encrypt a request the way Meta does (public key OAEP-SHA256 needs OpenSSL CLI; tests use it). */
function se_journey_flow_signature_ok($rawBody, $header, $secret)
{
    if ($secret === '' || !is_string($header) || strpos($header, 'sha256=') !== 0) {
        return false;
    }

    return hash_equals(hash_hmac('sha256', (string) $rawBody, $secret), substr($header, 7));
}

/* ===========================================================================
 * Flow tokens
 * ======================================================================== */

/** "<kind>.<journey flow token>" — minted per flow message (7 days). */
function se_journey_flow_token_issue($j, $kind, $issued_by = 0)
{
    $t = se_journey_issue_token($j, 'flow', (int) $issued_by, false);

    return $t['ok'] ? $kind . '.' . $t['token'] : '';
}

/** @return array{ok:bool,kind:string,journey:?object,reason:string} */
function se_journey_flow_token_resolve($flowToken, $ip = '', $ua = '')
{
    $parts = explode('.', (string) $flowToken, 2);
    if (count($parts) !== 2 || !isset(se_journey_flow_kinds()[$parts[0]])) {
        return ['ok' => false, 'kind' => '', 'journey' => null, 'reason' => 'bad_token'];
    }
    $v = se_journey_verify_token($parts[1], 'flow', $ip, $ua);
    if (!$v['ok']) {
        return ['ok' => false, 'kind' => $parts[0], 'journey' => null, 'reason' => (string) $v['reason']];
    }

    return ['ok' => true, 'kind' => $parts[0], 'journey' => $v['journey'], 'reason' => ''];
}

/* ===========================================================================
 * Flow JSON — generated from the questionnaire, so both doors ask the same
 * ======================================================================== */

/** Short labels where the questionnaire's wording exceeds Meta's limits (TextInput/Dropdown 20, groups 30). */
function se_journey_flow_short_labels()
{
    return [
        'contact_time' => 'Görüşme zamanı', 'contact_channel' => 'Görüşme kanalı',
        'desired' => 'Beklentileriniz',
        'previous_transplant' => 'Daha önce kaş ekimi?', 'previous_transplant_detail' => 'Tarih ve yer',
        'previous_procedures' => 'Önceki işlemler (kaş bölgesi)', 'previous_procedures_detail' => 'Tarihler / açıklama',
        'timing' => 'Zamanlama tercihi', 'travel_needed' => "İstanbul'a seyahat gerekir mi?",
        'skin' => 'Cilt ve kaş bölgesi durumu',
        'medications' => 'Reçeteli ilaçlar', 'otc_supplements' => 'Vitamin / takviyeler',
        'blood_thinners' => 'Kan sulandırıcı kullanımı', 'prior_operations' => 'Ameliyat geçmişi',
        'anesthesia_complications' => 'Anestezi sorunu yaşadınız mı?', 'infectious' => 'Bulaşıcı hastalık', 'additional' => 'Ek bilgi',
    ];
}

/** Option titles over 30 characters, reworded. */
function se_journey_flow_short_options()
{
    return [
        'Klinisyenle görüşmeyi tercih ederim' => 'Klinisyenle görüşmek isterim',
        'Dolgu / botoks (bölgeye yakın)' => 'Dolgu / botoks (yakın bölge)',
    ];
}

function se_journey_flow_trim($text, $max)
{
    $text = trim((string) $text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max - 1);
    $sp  = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp >= (int) ($max * 0.6)) {
        $cut = mb_substr($cut, 0, $sp);
    }

    return rtrim($cut, ' ,;:') . '…';
}

/** One questionnaire field → one Flow component. */
function se_journey_flow_component($key, array $f)
{
    $short = se_journey_flow_short_labels();
    $label = $short[$key] ?? (string) $f['label'];
    $required = !empty($f['required']);
    $opts = function (array $options) {
        $map = se_journey_flow_short_options();
        $out = [];
        foreach ($options as $id => $title) {
            $t = $map[$title] ?? $title;
            $out[] = ['id' => (string) $id, 'title' => se_journey_flow_trim($t, 30)];
        }

        return $out;
    };
    switch ((string) $f['type']) {
        case 'text':
            $c = ['type' => 'TextInput', 'name' => $key, 'label' => se_journey_flow_trim($label, 20), 'input-type' => 'text', 'required' => $required];
            if (!empty($f['max'])) { $c['max-chars'] = min(80, (int) $f['max']); }
            if (!empty($f['help'])) { $c['helper-text'] = se_journey_flow_trim($f['help'], 80); }

            return $c;
        case 'number':
            $c = ['type' => 'TextInput', 'name' => $key, 'label' => se_journey_flow_trim($label, 20), 'input-type' => 'number', 'required' => $required, 'max-chars' => 3];
            if (!empty($f['help'])) { $c['helper-text'] = se_journey_flow_trim($f['help'], 80); }

            return $c;
        case 'textarea':
            $c = ['type' => 'TextArea', 'name' => $key, 'label' => se_journey_flow_trim($label, 20), 'required' => $required];
            if (!empty($f['max'])) { $c['max-length'] = min(600, (int) $f['max']); }

            return $c;
        case 'select':
            return ['type' => 'Dropdown', 'name' => $key, 'label' => se_journey_flow_trim($label, 20), 'required' => $required, 'data-source' => $opts($f['options'])];
        case 'radio':
            return ['type' => 'RadioButtonsGroup', 'name' => $key, 'label' => se_journey_flow_trim($label, 30), 'required' => $required, 'data-source' => $opts($f['options'])];
        case 'multi':
            return ['type' => 'CheckboxGroup', 'name' => $key, 'label' => se_journey_flow_trim($label, 30), 'required' => $required, 'data-source' => $opts($f['options'])];
    }

    return null;   // readonly (WhatsApp number): the flow already knows the sender
}

/** Which questionnaire fields sit on which intake screen. */
function se_journey_flow_intake_screens($brand_id)
{
    $fields = se_journey_fields($brand_id);
    $sections = se_journey_questionnaire()['sections'];
    $bySection = [];
    foreach ($sections as $sk => $s) {
        foreach ($s['fields'] as $fk => $f) {
            if (isset($fields[$fk])) { $bySection[$sk][$fk] = $fields[$fk]; }
        }
    }
    $health = $bySection['health'] ?? [];
    $h1 = []; $h2 = [];
    foreach ($health as $fk => $f) {
        if (in_array($fk, ['pregnancy', 'chronic', 'chronic_detail', 'skin', 'skin_detail', 'allergies', 'allergies_detail'], true)) { $h1[$fk] = $f; } else { $h2[$fk] = $f; }
    }

    return [
        'IDENTITY' => ['title' => 'Kimlik ve iletişim',     'fields' => $bySection['identity'] ?? [], 'next' => 'CONCERN'],
        'CONCERN'  => ['title' => 'Kaş şikâyeti',           'fields' => $bySection['concern'] ?? [],  'next' => 'HEALTH_A'],
        'HEALTH_A' => ['title' => 'Sağlık taraması (1/2)',  'fields' => $h1, 'next' => 'HEALTH_B'],
        'HEALTH_B' => ['title' => 'Sağlık taraması (2/2)',  'fields' => $h2, 'next' => null],
    ];
}

/** The complete Flow JSON for a kind (array; json_encode for upload). */
function se_journey_flow_json($brand_id, $kind)
{
    return $kind === 'booking' ? se_journey_flow_json_booking($brand_id) : se_journey_flow_json_intake($brand_id);
}

function se_journey_flow_json_intake($brand_id)
{
    $screens = [];
    $routing = ['CONSENT' => ['IDENTITY']];

    // CONSENT: the notice + the three opt-ins (health required), texts from Consent Settings.
    $screens[] = [
        'id' => 'CONSENT', 'title' => 'Aydınlatma ve rıza',
        'data' => [
            'notice' => ['type' => 'string', '__example__' => 'Sağlık bilgileriniz özel nitelikli kişisel veridir…'],
            'health_label' => ['type' => 'string', '__example__' => 'Sağlık verilerimin işlenmesine açık rıza veriyorum.'],
            'photo_label'  => ['type' => 'string', '__example__' => 'Fotoğraflarımın tanıtımda kullanılmasına izin veriyorum.'],
            'marketing_label' => ['type' => 'string', '__example__' => 'Tanıtım iletileri almayı kabul ediyorum.'],
            'error_message' => ['type' => 'string', '__example__' => ''],
        ],
        'layout' => ['type' => 'SingleColumnLayout', 'children' => [
            ['type' => 'TextHeading', 'text' => 'Kaş ekimi ön değerlendirme'],
            ['type' => 'TextBody', 'text' => '${data.notice}'],
            ['type' => 'Form', 'name' => 'form', 'children' => [
                ['type' => 'OptIn', 'name' => 'consent_health_data', 'label' => '${data.health_label}', 'required' => true],
                ['type' => 'OptIn', 'name' => 'consent_photo_publication', 'label' => '${data.photo_label}', 'required' => false],
                ['type' => 'OptIn', 'name' => 'consent_marketing', 'label' => '${data.marketing_label}', 'required' => false],
                ['type' => 'Footer', 'label' => 'Devam et', 'on-click-action' => ['name' => 'data_exchange', 'payload' => [
                    'consent_health_data' => '${form.consent_health_data}',
                    'consent_photo_publication' => '${form.consent_photo_publication}',
                    'consent_marketing' => '${form.consent_marketing}',
                ]]],
            ]],
        ]],
    ];

    foreach (se_journey_flow_intake_screens($brand_id) as $id => $s) {
        $children = [['type' => 'TextSubheading', 'text' => $s['title']]];
        $payload = [];
        $formChildren = [];
        foreach ($s['fields'] as $fk => $f) {
            $c = se_journey_flow_component($fk, $f);
            if ($c === null) { continue; }
            $formChildren[] = $c;
            $payload[$fk] = '${form.' . $fk . '}';
        }
        $terminal = $s['next'] === null;
        $formChildren[] = ['type' => 'Footer', 'label' => $terminal ? 'Formu gönder' : 'Devam et', 'on-click-action' => ['name' => 'data_exchange', 'payload' => $payload]];
        $children[] = ['type' => 'Form', 'name' => 'form', 'children' => $formChildren];
        $screen = ['id' => $id, 'title' => $s['title'], 'data' => ['error_message' => ['type' => 'string', '__example__' => '']],
                   'layout' => ['type' => 'SingleColumnLayout', 'children' => $children]];
        if ($terminal) { $screen['terminal'] = true; $screen['success'] = true; }
        $screens[] = $screen;
        $routing[$id] = $s['next'] ? [$s['next']] : [];
    }

    return ['version' => SE_JOURNEY_FLOW_JSON_VERSION, 'data_api_version' => SE_JOURNEY_FLOW_DATA_API_VERSION, 'routing_model' => $routing, 'screens' => $screens];
}

function se_journey_flow_json_booking($brand_id)
{
    $days = [['id' => '2026-09-08', 'title' => '8 Eylül Salı']];
    $slots = [['id' => '2026-09-08 14:00:00', 'title' => '14:00']];

    return ['version' => SE_JOURNEY_FLOW_JSON_VERSION, 'data_api_version' => SE_JOURNEY_FLOW_DATA_API_VERSION,
        'routing_model' => ['DAY' => ['TIME'], 'TIME' => []],
        'screens' => [
            ['id' => 'DAY', 'title' => 'Görüşme günü',
             'data' => ['intro' => ['type' => 'string', '__example__' => 'Klinikte yüz yüze ön görüşme için gün seçin.'],
                        'days' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'title' => ['type' => 'string']]], '__example__' => $days],
                        'error_message' => ['type' => 'string', '__example__' => '']],
             'layout' => ['type' => 'SingleColumnLayout', 'children' => [
                ['type' => 'TextHeading', 'text' => 'Klinikte yüz yüze ön görüşme'],
                ['type' => 'TextBody', 'text' => '${data.intro}'],
                ['type' => 'Form', 'name' => 'form', 'children' => [
                    ['type' => 'RadioButtonsGroup', 'name' => 'day', 'label' => 'Gün', 'required' => true, 'data-source' => '${data.days}'],
                    ['type' => 'Footer', 'label' => 'Saatleri göster', 'on-click-action' => ['name' => 'data_exchange', 'payload' => ['day' => '${form.day}']]],
                ]],
             ]]],
            ['id' => 'TIME', 'title' => 'Görüşme saati', 'terminal' => true, 'success' => true,
             'data' => ['day_title' => ['type' => 'string', '__example__' => '8 Eylül Salı'],
                        'slots' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'title' => ['type' => 'string']]], '__example__' => $slots],
                        'error_message' => ['type' => 'string', '__example__' => '']],
             'layout' => ['type' => 'SingleColumnLayout', 'children' => [
                ['type' => 'TextSubheading', 'text' => '${data.day_title}'],
                ['type' => 'Form', 'name' => 'form', 'children' => [
                    ['type' => 'RadioButtonsGroup', 'name' => 'slot', 'label' => 'Saat', 'required' => true, 'data-source' => '${data.slots}'],
                    ['type' => 'Footer', 'label' => 'Randevuyu onayla', 'on-click-action' => ['name' => 'data_exchange', 'payload' => ['slot' => '${form.slot}']]],
                ]],
             ]]],
        ]];
}

/* ===========================================================================
 * The endpoint (decrypted side)
 * ======================================================================== */

/**
 * Handle one decrypted request. Returns the response array to encrypt.
 * Never throws for a bad token: a stale flow shows a friendly message.
 */
function se_journey_flow_handle(array $req, $ip = '', $ua = '')
{
    $action = (string) ($req['action'] ?? '');
    if ($action === 'ping') {
        return ['data' => ['status' => 'active']];
    }
    if (isset($req['data']['error']) && $action !== 'data_exchange') {
        // Meta reports a client-side error; acknowledge, note it.
        se_journey_audit(0, 0, 'flow_client_error', 'flow', null, mb_substr(json_encode($req['data']), 0, 191));

        return ['data' => ['acknowledged' => true]];
    }
    $tok = se_journey_flow_token_resolve((string) ($req['flow_token'] ?? ''), $ip, $ua);
    if (!$tok['ok']) {
        return ['screen' => 'SUCCESS', 'data' => ['extension_message_response' => ['params' => ['flow_token' => (string) ($req['flow_token'] ?? ''), 'result' => 'expired']]]];
    }
    $j = $tok['journey'];
    if ($tok['kind'] === 'booking') {
        return se_journey_flow_booking_step($j, $action, (string) ($req['screen'] ?? ''), (array) ($req['data'] ?? []), (string) $req['flow_token']);
    }

    return se_journey_flow_intake_step($j, $action, (string) ($req['screen'] ?? ''), (array) ($req['data'] ?? []), (string) $req['flow_token'], $ip, $ua);
}

/** Data for the CONSENT screen (texts from Consent Settings; TextBody ≤ 4096). */
function se_journey_flow_consent_data($j)
{
    $texts = se_journey_consent_texts((int) $j->brand_id, 'tr');
    $notice = "Sağlık bilgileriniz özel nitelikli kişisel veridir ve yalnızca ön değerlendirme amacıyla, şifrelenerek işlenir. Bu ön değerlendirme tıbbi tanı veya kesin uygunluk kararı değildir.\n\n"
            . (string) $texts['health_data'];

    return [
        'notice' => se_journey_flow_trim($notice, 4000),
        'health_label' => se_journey_flow_trim($texts['health_data'] !== '' ? 'Sağlık verilerimin ön değerlendirme amacıyla işlenmesine açık rıza veriyorum.' : '', 120),
        'photo_label' => se_journey_flow_trim($texts['photo_publication'] !== '' ? $texts['photo_publication'] : 'Fotoğraflarımın tanıtımda kullanılmasına izin veriyorum (isteğe bağlı).', 120),
        'marketing_label' => se_journey_flow_trim($texts['marketing'] !== '' ? $texts['marketing'] : 'Tanıtım iletileri almayı kabul ediyorum (isteğe bağlı).', 120),
        'error_message' => '',
    ];
}

function se_journey_flow_intake_step($j, $action, $screen, array $data, $flowToken, $ip = '', $ua = '')
{
    $screens = se_journey_flow_intake_screens((int) $j->brand_id);
    $order   = array_merge(['CONSENT'], array_keys($screens));
    $consent = se_journey_consent_state($j);
    $intake  = se_journey_intake_get($j);
    if ($intake && (string) $intake->status === 'submitted') {
        return ['screen' => 'SUCCESS', 'data' => ['extension_message_response' => ['params' => ['flow_token' => $flowToken, 'result' => 'already_submitted']]]];
    }

    if ($action === 'INIT' || $action === 'BACK') {
        // Resume where the patient left off: consent first, then the first screen with a missing required answer.
        if (!$consent['health_data']) {
            return ['screen' => 'CONSENT', 'data' => se_journey_flow_consent_data($j)];
        }
        $answers = $intake ? se_journey_intake_answers($intake) : [];
        foreach ($screens as $id => $s) {
            foreach ($s['fields'] as $fk => $f) {
                if (!empty($f['required']) && $f['type'] !== 'readonly' && !isset($answers[$fk])) {
                    return ['screen' => $id, 'data' => ['error_message' => '']];
                }
            }
        }

        $last = array_key_last($screens);   // everything answered: the final screen (ids: letters and underscores only — Meta refuses digits)

        return ['screen' => $last, 'data' => ['error_message' => '']];
    }

    if ($action !== 'data_exchange') {
        return ['data' => ['acknowledged' => true]];
    }

    if ($screen === 'CONSENT') {
        $input = [
            'consent_health_data' => !empty($data['consent_health_data']) ? 'yes' : 'no',
            'consent_photo_publication' => !empty($data['consent_photo_publication']) ? 'yes' : 'no',
            'consent_marketing' => !empty($data['consent_marketing']) ? 'yes' : 'no',
        ];
        $r = se_journey_record_form_consent($j, $input, $ip, $ua);
        if (!$r['ok']) {
            $d = se_journey_flow_consent_data($j);
            $d['error_message'] = 'Rıza kaydedilemedi. Lütfen tekrar deneyin.';

            return ['screen' => 'CONSENT', 'data' => $d];
        }
        if ($r['reason'] === 'declined') {
            return ['screen' => 'SUCCESS', 'data' => ['extension_message_response' => ['params' => ['flow_token' => $flowToken, 'result' => 'consent_declined']]]];
        }
        se_journey_event($j, 'flow_step', 'intake CONSENT', [], 'patient');

        return ['screen' => 'IDENTITY', 'data' => ['error_message' => '']];
    }

    if (!isset($screens[$screen])) {
        return ['screen' => 'CONSENT', 'data' => se_journey_flow_consent_data($j)];
    }
    // Only this screen's fields, as the questionnaire expects them (arrays for multi, strings otherwise).
    $input = [];
    foreach ($screens[$screen]['fields'] as $fk => $f) {
        if (!array_key_exists($fk, $data)) { continue; }
        $v = $data[$fk];
        if ($f['type'] === 'multi') {
            $input[$fk] = is_array($v) ? array_values(array_map('strval', $v)) : ($v === '' || $v === null ? [] : [(string) $v]);
        } elseif (is_array($v)) {
            $input[$fk] = (string) reset($v);
        } elseif ($v !== null && $v !== '') {
            $input[$fk] = is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v;
        }
    }
    $j = se_journey_get_raw((int) $j->id);
    $terminal = $screens[$screen]['next'] === null;
    $r = $terminal ? se_journey_intake_submit($j, $input, $ip, $ua) : se_journey_intake_save($j, $input, $ip, $ua);
    if (!$r['ok']) {
        $msg = 'Lütfen işaretli alanları kontrol edin.';
        if (!empty($r['errors'])) {
            $first = reset($r['errors']);
            $msg = is_string($first) ? $first : $msg;
        } elseif (!empty($r['missing'])) {
            $labels = se_journey_fields((int) $j->brand_id);
            $k = reset($r['missing']);
            $msg = 'Eksik alan: ' . (string) ($labels[$k]['label'] ?? $k);
            // A missing answer from an EARLIER screen: send the patient back there.
            foreach ($screens as $id => $s) {
                if (isset($s['fields'][$k])) {
                    return ['screen' => $id, 'data' => ['error_message' => se_journey_flow_trim($msg, 300)]];
                }
            }
        } elseif ($r['reason'] === 'consent_required') {
            return ['screen' => 'CONSENT', 'data' => se_journey_flow_consent_data($j)];
        }

        return ['screen' => $screen, 'data' => ['error_message' => se_journey_flow_trim($msg, 300)]];
    }
    se_journey_event($j, 'flow_step', 'intake ' . $screen, [], 'patient');
    if ($terminal) {
        return ['screen' => 'SUCCESS', 'data' => ['extension_message_response' => ['params' => ['flow_token' => $flowToken, 'result' => 'intake_submitted']]]];
    }

    return ['screen' => $screens[$screen]['next'], 'data' => ['error_message' => '']];
}

/** Day/slot lists for the booking flow (RadioButtonsGroup: ≤ 20 options each). */
function se_journey_flow_booking_days($brand_id)
{
    $avail = se_journey_booking_slots((int) $brand_id);
    $days_tr = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
    $months = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $days = [];
    foreach ($avail['days'] as $date => $slots) {
        if (count($days) >= 20) { break; }
        $t = strtotime($date);
        $days[] = ['id' => $date, 'title' => se_journey_flow_trim((int) date('j', $t) . ' ' . $months[(int) date('n', $t)] . ' ' . $days_tr[(int) date('w', $t)], 30)];
    }

    return ['avail' => $avail, 'days' => $days];
}

function se_journey_flow_booking_step($j, $action, $screen, array $data, $flowToken)
{
    if (se_journey_consultation_upcoming($j)) {
        return ['screen' => 'SUCCESS', 'data' => ['extension_message_response' => ['params' => ['flow_token' => $flowToken, 'result' => 'already_booked']]]];
    }
    $b = se_journey_flow_booking_days((int) $j->brand_id);
    $intro = $b['days'] ? 'Klinikte yüz yüze ön görüşme için size uygun günü seçin.' : 'Önümüzdeki günlerde uygun saat bulunamadı; ekibimiz sizinle iletişime geçecektir.';

    if ($action === 'INIT' || $action === 'BACK' || $action !== 'data_exchange') {
        return ['screen' => 'DAY', 'data' => ['intro' => $intro, 'days' => $b['days'] ?: [['id' => 'none', 'title' => 'Uygun gün yok']], 'error_message' => '']];
    }
    if ($screen === 'DAY') {
        $day = (string) ($data['day'] ?? '');
        $slots = [];
        foreach ($b['avail']['days'][$day] ?? [] as $s) {
            if (count($slots) >= 20) { break; }
            $slots[] = ['id' => $s['start'], 'title' => date('H:i', strtotime($s['start']))];
        }
        if (!$slots) {
            return ['screen' => 'DAY', 'data' => ['intro' => $intro, 'days' => $b['days'] ?: [['id' => 'none', 'title' => 'Uygun gün yok']], 'error_message' => 'Bu gün için uygun saat kalmadı. Lütfen başka bir gün seçin.']];
        }
        $t = strtotime($day);
        $days_tr = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
        $months = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

        return ['screen' => 'TIME', 'data' => ['day_title' => (int) date('j', $t) . ' ' . $months[(int) date('n', $t)] . ' ' . $days_tr[(int) date('w', $t)], 'slots' => $slots, 'error_message' => '']];
    }
    if ($screen === 'TIME') {
        $slot = (string) ($data['slot'] ?? '');
        $r = se_journey_booking_pick(se_journey_get_raw((int) $j->id), $slot, 'flow');
        if ($r['ok']) {
            se_journey_event($j, 'flow_step', 'booking TIME → booked', [], 'patient');

            return ['screen' => 'SUCCESS', 'data' => ['extension_message_response' => ['params' => ['flow_token' => $flowToken, 'result' => 'booked', 'start' => $slot]]]];
        }
        $msg = $r['reason'] === 'slot_unavailable' ? 'Seçtiğiniz saat az önce doldu. Lütfen başka bir saat seçin.' : 'Randevu oluşturulamadı. Lütfen WhatsApp üzerinden bize yazın.';
        $day = substr($slot, 0, 10);
        $slots = [];
        foreach ($b['avail']['days'][$day] ?? [] as $s) { $slots[] = ['id' => $s['start'], 'title' => date('H:i', strtotime($s['start']))]; }
        if (!$slots) {
            return ['screen' => 'DAY', 'data' => ['intro' => $intro, 'days' => $b['days'] ?: [['id' => 'none', 'title' => 'Uygun gün yok']], 'error_message' => $msg]];
        }

        return ['screen' => 'TIME', 'data' => ['day_title' => $day, 'slots' => array_slice($slots, 0, 20), 'error_message' => $msg]];
    }

    return ['screen' => 'DAY', 'data' => ['intro' => $intro, 'days' => $b['days'] ?: [['id' => 'none', 'title' => 'Uygun gün yok']], 'error_message' => '']];
}

/* ===========================================================================
 * Sending a flow (in-window interactive; template with a FLOW button outside)
 * ======================================================================== */

/**
 * @param string $kind intake|booking
 * @return array{ok:bool,mode:string,reason:string,outbound_id:int}
 */
function se_journey_send_flow($j, $kind, $body, $correlation = '', array $opts = [])
{
    $ready = se_journey_flow_ready((int) $j->brand_id, $kind);
    if (!$ready['ready']) {
        return ['ok' => false, 'mode' => 'blocked', 'reason' => 'flow_' . $ready['reason'], 'outbound_id' => 0];
    }
    $def = se_journey_flow_kinds()[$kind];
    $flowToken = se_journey_flow_token_issue($j, $kind, (int) ($opts['issued_by'] ?? 0));
    if ($flowToken === '') {
        return ['ok' => false, 'mode' => 'blocked', 'reason' => 'flow_token', 'outbound_id' => 0];
    }
    $spec = [
        'purpose' => (string) ($opts['purpose'] ?? ($kind . '_flow')), 'kind' => 'interactive', 'body' => (string) $body,
        'interactive_type' => 'flow', 'correlation' => $correlation, 'bypass_pause' => !empty($opts['bypass_pause']),
        // data_exchange: the endpoint answers Meta's INIT with the first screen
        // (consent, or where the patient left off), so the message carries NO
        // flow_action_payload — Meta refuses one with data_exchange (#131009,
        // seen live 2026-09-03), exactly as the template button refuses
        // navigate_screen (2388203). The entry screen in the definition is
        // documentation of what INIT returns first, not a message field.
        'flow' => ['flow_message_version' => SE_JOURNEY_FLOW_MESSAGE_VERSION, 'flow_token' => $flowToken, 'flow_id' => $ready['flow_id'],
                   'flow_cta' => se_journey_flow_trim($def['cta'], 20), 'flow_action' => 'data_exchange'],
        'dedup_salt' => 'f' . substr(hash('sha256', $flowToken), 0, 12),
        // Outside the window: the template with the FLOW button (approved once the flow id exists).
        'template' => $def['template'], 'template_vars' => [se_journey_template_name($j)],
        'template_flow' => ['index' => 0, 'flow_token' => $flowToken],
    ];

    return se_journey_send($j, $spec);
}

/* ===========================================================================
 * Graph API management (create / upload JSON / publish / status / key)
 * ======================================================================== */

$GLOBALS['SE_JOURNEY_FLOW_GRAPH'] = $GLOBALS['SE_JOURNEY_FLOW_GRAPH'] ?? null;

/** Seam: callable(string $method, string $path, array $params, array $opts): array{ok,code,body,error}. */
function se_journey_flow_register_graph(callable $f)
{
    $GLOBALS['SE_JOURNEY_FLOW_GRAPH'] = $f;
}

function se_journey_flow_graph($method, $path, array $params = [], array $opts = [])
{
    if (is_callable($GLOBALS['SE_JOURNEY_FLOW_GRAPH'] ?? null)) {
        return call_user_func($GLOBALS['SE_JOURNEY_FLOW_GRAPH'], $method, $path, $params, $opts);
    }
    $token = function_exists('se_wa_cloud_token') ? se_wa_cloud_token() : '';
    if ($token === '') {
        return ['ok' => false, 'code' => 0, 'body' => [], 'error' => 'no cloud api token'];
    }
    $version = get_option('se_meta_graph_version') ?: 'v23.0';
    $url = 'https://graph.facebook.com/' . $version . '/' . ltrim($path, '/');
    $ch = curl_init();
    $headers = ['Authorization: Bearer ' . $token];
    if ($method === 'GET') {
        $url .= ($params ? '?' . http_build_query($params) : '');
    } elseif (!empty($opts['multipart'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);   // CURLFile inside
    } else {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    }
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => $headers]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'code' => 0, 'body' => [], 'error' => 'network: ' . mb_substr((string) $err, 0, 80)];
    }
    $body = json_decode((string) $raw, true) ?: [];
    $ok = $code >= 200 && $code < 300 && empty($body['error']);
    $e  = (array) ($body['error'] ?? []);
    $error = $ok ? '' : mb_substr((string) ($e['message'] ?? ('http ' . $code)) . (!empty($e['error_user_msg']) ? ' — ' . $e['error_user_msg'] : ''), 0, 300);

    return ['ok' => $ok, 'code' => $code, 'body' => $body, 'error' => $error];
}

/** Create the flow at Meta (draft) and remember its id. */
function se_journey_flow_create($brand_id, $kind, $staff_id = 0)
{
    $def  = se_journey_flow_kinds()[$kind] ?? null;
    $waba = function_exists('se_wa_waba_for_brand') ? (string) se_wa_waba_for_brand($brand_id) : '';
    if (!$def || $waba === '') {
        return ['ok' => false, 'reason' => $def ? 'no_waba' : 'unknown_kind'];
    }
    $params = ['name' => $def['name'], 'categories' => $def['categories'], 'endpoint_uri' => se_journey_flow_endpoint_url()];
    if (se_journey_flow_app_id() !== '') {
        $params['application_id'] = se_journey_flow_app_id();
    }
    $r = se_journey_flow_graph('POST', $waba . '/flows', $params);
    if (!$r['ok'] || empty($r['body']['id'])) {
        se_journey_audit($brand_id, 0, 'flow_create_failed', 'flow', $kind, $r['error'] ?: 'no id');

        return ['ok' => false, 'reason' => $r['error'] ?: 'no id'];
    }
    update_option('se_journey_flow_id_' . $kind . '_' . (int) $brand_id, (string) $r['body']['id']);
    update_option('se_journey_flow_status_' . $kind . '_' . (int) $brand_id, 'DRAFT');
    se_journey_audit($brand_id, 0, 'flow_created', 'flow', $kind, 'id=' . $r['body']['id']);

    return ['ok' => true, 'reason' => '', 'id' => (string) $r['body']['id']];
}

/** Upload the generated Flow JSON as the flow's asset (validation errors come back verbatim). */
function se_journey_flow_upload_json($brand_id, $kind, $staff_id = 0)
{
    $id = se_journey_flow_id($brand_id, $kind);
    if ($id === '') {
        return ['ok' => false, 'reason' => 'not_created', 'validation_errors' => []];
    }
    $json = json_encode(se_journey_flow_json($brand_id, $kind), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $tmp  = tempnam(sys_get_temp_dir(), 'flow');
    file_put_contents($tmp, $json);
    $r = se_journey_flow_graph('POST', $id . '/assets', ['name' => 'flow.json', 'asset_type' => 'FLOW_JSON', 'file' => new CURLFile($tmp, 'application/json', 'flow.json')], ['multipart' => true]);
    @unlink($tmp);
    $errors = (array) ($r['body']['validation_errors'] ?? []);
    if (!$r['ok']) {
        se_journey_audit($brand_id, 0, 'flow_upload_failed', 'flow', $kind, $r['error']);

        return ['ok' => false, 'reason' => $r['error'], 'validation_errors' => $errors];
    }
    update_option('se_journey_flow_json_hash_' . $kind . '_' . (int) $brand_id, hash('sha256', $json));
    update_option('se_journey_flow_errors_' . $kind . '_' . (int) $brand_id, json_encode($errors));
    se_journey_audit($brand_id, 0, 'flow_json_uploaded', 'flow', $kind, count($errors) . ' validation error(s)');

    return ['ok' => true, 'reason' => '', 'validation_errors' => $errors];
}

function se_journey_flow_publish($brand_id, $kind, $staff_id = 0)
{
    $id = se_journey_flow_id($brand_id, $kind);
    if ($id === '') {
        return ['ok' => false, 'reason' => 'not_created'];
    }
    $r = se_journey_flow_graph('POST', $id . '/publish', []);
    if (!$r['ok']) {
        se_journey_audit($brand_id, 0, 'flow_publish_failed', 'flow', $kind, $r['error']);

        return ['ok' => false, 'reason' => $r['error']];
    }
    update_option('se_journey_flow_status_' . $kind . '_' . (int) $brand_id, 'PUBLISHED');
    se_journey_audit($brand_id, 0, 'flow_published', 'flow', $kind, 'id=' . $id);

    return ['ok' => true, 'reason' => ''];
}

/** Refresh status + validation errors from Meta. */
function se_journey_flow_sync($brand_id, $kind)
{
    $id = se_journey_flow_id($brand_id, $kind);
    if ($id === '') {
        return ['ok' => false, 'reason' => 'not_created', 'status' => '', 'validation_errors' => []];
    }
    $r = se_journey_flow_graph('GET', $id, ['fields' => 'id,name,status,validation_errors,json_version,data_api_version,endpoint_uri']);
    if (!$r['ok']) {
        return ['ok' => false, 'reason' => $r['error'], 'status' => se_journey_flow_status($brand_id, $kind), 'validation_errors' => []];
    }
    $status = strtoupper((string) ($r['body']['status'] ?? ''));
    $errors = (array) ($r['body']['validation_errors'] ?? []);
    if ($status !== '') {
        update_option('se_journey_flow_status_' . $kind . '_' . (int) $brand_id, $status);
    }
    update_option('se_journey_flow_errors_' . $kind . '_' . (int) $brand_id, json_encode($errors));

    return ['ok' => true, 'reason' => '', 'status' => $status, 'validation_errors' => $errors, 'endpoint_uri' => (string) ($r['body']['endpoint_uri'] ?? '')];
}

/** Register the business public key on the brand's phone number. */
function se_journey_flow_register_public_key($brand_id)
{
    $pub = se_journey_flow_public_key();
    if ($pub === '') {
        return ['ok' => false, 'reason' => 'no_key'];
    }
    $pn = se_journey_flow_phone_number_id($brand_id);
    if ($pn === '') {
        return ['ok' => false, 'reason' => 'no_number'];
    }
    $r = se_journey_flow_graph('POST', $pn . '/whatsapp_business_encryption', ['business_public_key' => $pub]);
    if (!$r['ok']) {
        se_journey_audit($brand_id, 0, 'flow_key_register_failed', 'flow', null, $r['error']);

        return ['ok' => false, 'reason' => $r['error']];
    }
    update_option('se_journey_flow_key_registered_' . (int) $brand_id, date('Y-m-d H:i:s'));
    se_journey_audit($brand_id, 0, 'flow_key_registered', 'flow', null, 'fingerprint=' . substr(hash('sha256', $pub), 0, 12));

    return ['ok' => true, 'reason' => ''];
}

/** Meta's view of the registered key: VALID | MISMATCH | '' (none). */
function se_journey_flow_public_key_status($brand_id)
{
    $pn = se_journey_flow_phone_number_id($brand_id);
    if ($pn === '') {
        return ['ok' => false, 'status' => '', 'matches' => false, 'reason' => 'no_number'];
    }
    $r = se_journey_flow_graph('GET', $pn . '/whatsapp_business_encryption', []);
    if (!$r['ok']) {
        return ['ok' => false, 'status' => '', 'matches' => false, 'reason' => $r['error']];
    }
    $row = (array) (($r['body']['data'][0] ?? null) ?: []);
    $remote = trim((string) ($row['business_public_key'] ?? ''));
    $local  = trim(se_journey_flow_public_key());

    return ['ok' => true, 'status' => (string) ($row['business_public_key_signature_status'] ?? ''), 'matches' => $remote !== '' && $remote === $local, 'reason' => ''];
}

function se_journey_flow_phone_number_id($brand_id)
{
    $CI = &get_instance();
    $CI->db->where('brand_id', (int) $brand_id)->where('state', 'active')->order_by('id', 'ASC')->limit(1);
    $row = $CI->db->get(db_prefix() . 'se_wa_numbers')->row();

    return $row ? (string) $row->phone_number_id : '';
}

/** Readiness summary for the admin page. */
function se_journey_flow_readiness($brand_id)
{
    $out = ['enabled' => se_journey_flows_enabled($brand_id), 'key_installed' => se_journey_flow_private_key() !== '',
            'key_registered_at' => (string) get_option('se_journey_flow_key_registered_' . (int) $brand_id),
            'app_id' => se_journey_flow_app_id(), 'endpoint' => se_journey_flow_endpoint_url(), 'flows' => []];
    foreach (se_journey_flow_kinds() as $kind => $def) {
        $out['flows'][$kind] = [
            'name' => $def['name'], 'id' => se_journey_flow_id($brand_id, $kind), 'status' => se_journey_flow_status($brand_id, $kind),
            'errors' => json_decode((string) get_option('se_journey_flow_errors_' . $kind . '_' . (int) $brand_id), true) ?: [],
            'json_hash' => (string) get_option('se_journey_flow_json_hash_' . $kind . '_' . (int) $brand_id),
            'current_hash' => hash('sha256', json_encode(se_journey_flow_json($brand_id, $kind), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)),
            'ready' => se_journey_flow_ready($brand_id, $kind),
        ];
    }

    return $out;
}

/* ===========================================================================
 * The completion webhook (nfm_reply) — the endpoint already stored everything
 * ======================================================================== */

function se_journey_on_flow_reply($j, array $ctx)
{
    $reply = (array) ($ctx['flow_reply'] ?? []);
    $tok = se_journey_flow_token_resolve((string) ($reply['flow_token'] ?? ''));
    $result = (string) ($reply['result'] ?? '');
    se_journey_event($j, 'flow_completed', ($tok['ok'] ? $tok['kind'] : 'unknown') . ($result !== '' ? ' (' . $result . ')' : ''), [], 'patient', null, 'wa_message', (string) ($ctx['message_id'] ?? ''), (string) ($ctx['wamid'] ?? ''));
    if ($tok['ok'] && $result === 'expired') {
        // The flow outlived its token: offer a fresh one.
        se_journey_task($j, 'flow_expired', 'Patient opened an expired ' . $tok['kind'] . ' flow — resend', 'normal', null, (string) ($ctx['wamid'] ?? ''));
    }

    return ['handled' => true, 'reason' => 'flow_reply', 'journey_id' => (int) $j->id];
}
