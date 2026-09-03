<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * se_journey — secure intake: links, encryption, questionnaire, consent.
 *
 * LINKS. A patient link is a 256-bit random token, shown once in the
 * WhatsApp message and stored ONLY as its SHA-256. One journey, one purpose,
 * default 48h TTL, rotated on every re-send (the previous link keeps a short
 * grace period so an open form can still autosave). Nothing about the
 * patient is in the URL.
 *
 * ENCRYPTION. Health answers and check-in replies are sealed with
 * libsodium secretbox (XSalsa20-Poly1305) under a key that lives in the
 * file secret store (`journey_key`), never in the database or Git. With no
 * key present health data is REFUSED, not written in the clear — the same
 * rule se_patients applies to passport numbers.
 *
 * QUESTIONNAIRE. Versioned in code (v1). Answers are validated server-side
 * against the definition; review FLAGS are derived for staff attention and
 * are explicitly not an eligibility decision.
 *
 * CONSENT. The form's first step shows the counsel-approved texts from
 * Consent Settings (purpose health_data — required for evaluation;
 * photo_publication and marketing — optional, unticked). Every answer is
 * filed in the append-only ledger with channel, version, IP/UA hashes.
 */

define('SE_JOURNEY_TOKEN_BYTES', 32);
define('SE_JOURNEY_TOKEN_GRACE_SECONDS', 7200);
define('SE_JOURNEY_QUESTIONNAIRE_VERSION', 'v1');
define('SE_JOURNEY_MAX_ANSWERS_BYTES', 65536);

/* ===========================================================================
 * Encryption at rest
 * ======================================================================== */

/** The 32-byte key from the file secret store (base64 or hex), or '' when absent. */
function se_journey_key()
{
    if (!function_exists('se_secret_read')) {
        return '';
    }
    $raw = trim((string) se_secret_read('journey_key'));
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^[0-9a-f]{64}$/i', $raw)) {
        return hex2bin($raw);
    }
    $bin = base64_decode($raw, true);

    return ($bin !== false && strlen($bin) === 32) ? $bin : '';
}

function se_journey_key_version()
{
    $v = trim((string) get_option('se_journey_key_version'));

    return $v !== '' ? mb_substr($v, 0, 16) : 'k1';
}

function se_journey_crypto_available()
{
    return function_exists('sodium_crypto_secretbox') && se_journey_key() !== '';
}

/** Seal a string. Returns '' on failure (never plaintext). Format: v1:base64(nonce||box). */
function se_journey_encrypt($plain)
{
    $key = se_journey_key();
    if ($key === '' || !function_exists('sodium_crypto_secretbox')) {
        return '';
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $box   = sodium_crypto_secretbox((string) $plain, $nonce, $key);
    sodium_memzero($key);

    return 'v1:' . base64_encode($nonce . $box);
}

/** Open a sealed string. Returns null on any failure. */
function se_journey_decrypt($sealed)
{
    $key = se_journey_key();
    if ($key === '' || !function_exists('sodium_crypto_secretbox_open') || strpos((string) $sealed, 'v1:') !== 0) {
        return null;
    }
    $bin = base64_decode(substr((string) $sealed, 3), true);
    if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $box   = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($box, $nonce, $key);
    sodium_memzero($key);

    return $plain === false ? null : $plain;
}

/* ===========================================================================
 * Health-data collection gate
 * ======================================================================== */

/**
 * May the intake form collect health answers/photos for this brand?
 *   - the encryption key must exist (else nothing can be stored safely), AND
 *   - counsel-approved health_data consent text must be configured, OR an
 *     administrator enabled the audited emergency bypass.
 */
function se_journey_health_collection_allowed($brand_id)
{
    if (!se_journey_crypto_available()) {
        return false;
    }
    if (function_exists('se_consent_text_configured') && se_consent_text_configured((int) $brand_id, 'health_data')) {
        return true;
    }

    return se_journey_consent_bypass_active($brand_id);
}

function se_journey_consent_bypass_active($brand_id)
{
    return (int) get_option('se_journey_consent_bypass_' . (int) $brand_id) === 1;
}

/** Admin-only, audited. Reason required. */
function se_journey_set_consent_bypass($brand_id, $on, $reason, $staff_id)
{
    if (!function_exists('is_admin') || !is_admin()) {
        return false;
    }
    $reason = trim((string) $reason);
    if ($on && $reason === '') {
        return false;
    }
    update_option('se_journey_consent_bypass_' . (int) $brand_id, $on ? 1 : 0);
    update_option('se_journey_consent_bypass_reason_' . (int) $brand_id, $on ? mb_substr($reason, 0, 191) : '');
    se_journey_audit((int) $brand_id, 0, $on ? 'consent_bypass_on' : 'consent_bypass_off', null, null, $reason);
    log_activity('SE journey consent bypass ' . ($on ? 'ENABLED' : 'disabled') . ' by staff ' . (int) $staff_id);

    return true;
}

/* ===========================================================================
 * Tokens
 * ======================================================================== */

function se_journey_token_purposes()
{
    return ['intake', 'upload', 'quote', 'checkin', 'info', 'book', 'calendar', 'flow'];
}

function se_journey_token_ttl_seconds($purpose)
{
    switch ($purpose) {
        case 'quote':   return 14 * 86400;
        case 'book':    return 14 * 86400;   // consultation slot picker after an accepted quote
        case 'calendar': return 45 * 86400;  // "add to calendar" file for a booked consultation
        case 'flow':    return 7 * 86400;    // a WhatsApp Flow session (flow_token) — intake or booking inside WhatsApp
        case 'info':    return 30 * 86400;
        case 'checkin': return 3 * 86400;
        default:        return se_journey_intake_ttl_hours() * 3600;
    }
}

function se_journey_hash_token($raw)
{
    return hash('sha256', (string) $raw);
}

/**
 * Issue a token. The plaintext is returned ONCE. Previous tokens of the same
 * purpose are rotated: they expire after a short grace period.
 *
 * @return array{ok:bool,token:string,id:int,expires_at:string,reason:string}
 */
function se_journey_issue_token($j, $purpose, $issued_by = 0, $rotate = true)
{
    if (!in_array($purpose, se_journey_token_purposes(), true)) {
        return ['ok' => false, 'token' => '', 'id' => 0, 'expires_at' => '', 'reason' => 'bad_purpose'];
    }
    $CI  = &get_instance();
    $t   = db_prefix() . 'se_journey_tokens';
    $now = time();

    if ($rotate) {
        $grace = date('Y-m-d H:i:s', $now + SE_JOURNEY_TOKEN_GRACE_SECONDS);
        $CI->db->where('journey_id', (int) $j->id)->where('purpose', $purpose);
        foreach ($CI->db->get($t)->result_array() as $old) {
            if (!empty($old['revoked_at'])) {
                continue;
            }
            if (strtotime((string) $old['expires_at']) > $now + SE_JOURNEY_TOKEN_GRACE_SECONDS) {
                $CI->db->where('id', (int) $old['id'])->update($t, ['expires_at' => $grace, 'revoke_reason' => 'rotated']);
            }
        }
    }

    $raw  = rtrim(strtr(base64_encode(random_bytes(SE_JOURNEY_TOKEN_BYTES)), '+/', '-_'), '=');
    $exp  = date('Y-m-d H:i:s', $now + se_journey_token_ttl_seconds($purpose));
    $CI->db->insert($t, [
        'journey_id' => (int) $j->id, 'brand_id' => (int) $j->brand_id, 'purpose' => $purpose,
        'token_hash' => se_journey_hash_token($raw), 'issued_by' => (int) $issued_by,
        'issued_at' => date('Y-m-d H:i:s', $now), 'expires_at' => $exp, 'use_count' => 0,
        'revoked_at' => null, 'revoke_reason' => null, 'first_used_at' => null, 'last_used_at' => null, 'rotated_from' => 0,
    ]);
    $id = (int) $CI->db->insert_id();
    se_journey_event($j, 'token_issued', $purpose, ['expires_at' => $exp], $issued_by ? 'staff' : 'system', $issued_by ?: null, 'token', (string) $id);

    return ['ok' => true, 'token' => $raw, 'id' => $id, 'expires_at' => $exp, 'reason' => ''];
}

function se_journey_revoke_tokens($j, $purpose, $reason = 'revoked')
{
    $CI = &get_instance();
    $CI->db->where('journey_id', (int) $j->id)->where('purpose', $purpose)
           ->update(db_prefix() . 'se_journey_tokens', ['revoked_at' => date('Y-m-d H:i:s'), 'revoke_reason' => mb_substr((string) $reason, 0, 64)]);

    return (int) $CI->db->affected_rows();
}

/**
 * Verify a presented token for a purpose. Constant-time on the hash; the
 * journey it resolves to is the ONLY journey the request may touch.
 *
 * @return array{ok:bool,reason:string,journey:?object,token:?array}
 */
function se_journey_verify_token($raw, $purpose, $ip = '', $ua = '')
{
    $raw = (string) $raw;
    if (!preg_match('/^[A-Za-z0-9_-]{40,48}$/', $raw)) {
        return ['ok' => false, 'reason' => 'malformed', 'journey' => null, 'token' => null];
    }
    if ($ip !== '' && se_journey_throttle_hit('tok:' . hash('sha256', $ip), 60, 600)) {
        return ['ok' => false, 'reason' => 'rate_limited', 'journey' => null, 'token' => null];
    }

    $CI   = &get_instance();
    $hash = se_journey_hash_token($raw);
    $CI->db->where('token_hash', $hash);
    $row = $CI->db->get(db_prefix() . 'se_journey_tokens')->row_array();
    if (!$row || !hash_equals((string) $row['token_hash'], $hash)) {
        return ['ok' => false, 'reason' => 'unknown', 'journey' => null, 'token' => null];
    }
    if ((string) $row['purpose'] !== (string) $purpose) {
        return ['ok' => false, 'reason' => 'wrong_purpose', 'journey' => null, 'token' => null];
    }
    if (!empty($row['revoked_at'])) {
        return ['ok' => false, 'reason' => 'revoked', 'journey' => null, 'token' => null];
    }
    if (strtotime((string) $row['expires_at']) <= time()) {
        return ['ok' => false, 'reason' => 'expired', 'journey' => null, 'token' => null];
    }
    $j = se_journey_get_raw((int) $row['journey_id']);
    if (!$j || (int) $j->brand_id !== (int) $row['brand_id']) {
        return ['ok' => false, 'reason' => 'journey_missing', 'journey' => null, 'token' => null];
    }
    if ((string) $j->state === 'opted_out') {
        return ['ok' => false, 'reason' => 'opted_out', 'journey' => $j, 'token' => null];
    }

    $now = date('Y-m-d H:i:s');
    $CI->db->where('id', (int) $row['id'])->update(db_prefix() . 'se_journey_tokens', [
        'first_used_at' => $row['first_used_at'] ?: $now, 'last_used_at' => $now, 'use_count' => (int) $row['use_count'] + 1,
        'last_ip_hash' => $ip !== '' ? hash('sha256', $ip) : null, 'last_ua_hash' => $ua !== '' ? hash('sha256', $ua) : null,
    ]);

    return ['ok' => true, 'reason' => '', 'journey' => $j, 'token' => $row];
}

/** Fixed-window throttle on a named bucket. True when OVER the limit. */
function se_journey_throttle_hit($bucket, $limit, $window_seconds)
{
    $CI  = &get_instance();
    $t   = db_prefix() . 'se_journey_throttle';
    $now = time();
    $CI->db->where('bucket', mb_substr((string) $bucket, 0, 96));
    $row = $CI->db->get($t)->row_array();
    if (!$row) {
        try {
            $CI->db->insert($t, ['bucket' => mb_substr((string) $bucket, 0, 96), 'window_start' => date('Y-m-d H:i:s', $now), 'hits' => 1]);
        } catch (Exception $e) {
            // lost a race with a concurrent insert: count it as one hit
        }

        return false;
    }
    if (strtotime((string) $row['window_start']) + (int) $window_seconds <= $now) {
        $CI->db->where('id', (int) $row['id'])->update($t, ['window_start' => date('Y-m-d H:i:s', $now), 'hits' => 1]);

        return false;
    }
    $hits = (int) $row['hits'] + 1;
    $CI->db->where('id', (int) $row['id'])->update($t, ['hits' => $hits]);

    return $hits > (int) $limit;
}

/* ===========================================================================
 * Questionnaire v1
 * ======================================================================== */

/**
 * Field types: text, textarea, number, select, radio, multi (checkbox group).
 * `other_detail` names the free-text field that opens for an "other" choice.
 * `flag` maps answers to review flags (attention only).
 */
function se_journey_questionnaire($version = SE_JOURNEY_QUESTIONNAIRE_VERSION)
{
    $unknown = ['unknown' => 'Bilmiyorum', 'discuss' => 'Klinisyenle görüşmeyi tercih ederim'];

    return [
        'version'  => 'v1',
        'sections' => [
            'identity' => [
                'title'  => 'Kimlik ve iletişim',
                'fields' => [
                    'full_name'  => ['type' => 'text', 'label' => 'Ad ve soyad', 'required' => true, 'max' => 120],
                    'age'        => ['type' => 'number', 'label' => 'Yaşınız', 'required' => true, 'min' => 1, 'max' => 110,
                                     'help' => 'Doğum tarihi yerine yalnızca yaşınız istenir.'],
                    'country'    => ['type' => 'text', 'label' => 'Ülke', 'required' => true, 'max' => 64],
                    'city'       => ['type' => 'text', 'label' => 'Şehir', 'required' => false, 'max' => 64],
                    'preferred_language' => ['type' => 'select', 'label' => 'Tercih ettiğiniz dil', 'required' => true,
                                             'options' => ['tr' => 'Türkçe', 'en' => 'English', 'fa' => 'فارسی', 'ar' => 'العربية']],
                    'whatsapp'   => ['type' => 'readonly', 'label' => 'WhatsApp numaranız', 'required' => false],
                    'contact_time' => ['type' => 'select', 'label' => 'Uygun görüşme zamanı', 'required' => false,
                                       'options' => ['morning' => 'Sabah', 'afternoon' => 'Öğleden sonra', 'evening' => 'Akşam', 'any' => 'Fark etmez']],
                    'contact_channel' => ['type' => 'select', 'label' => 'Tercih ettiğiniz görüşme kanalı', 'required' => false,
                                          'options' => ['whatsapp' => 'WhatsApp', 'phone' => 'Telefon', 'video' => 'Görüntülü görüşme', 'in_person' => 'Klinikte']],
                ],
            ],
            'concern' => [
                'title'  => 'Kaş şikâyeti ve beklentiler',
                'fields' => [
                    'main_concern' => ['type' => 'multi', 'label' => 'Ana şikâyetiniz', 'required' => true, 'other_detail' => 'main_concern_other',
                                       'options' => ['sparse' => 'Seyrek kaşlar', 'scarring' => 'Yara izi', 'asymmetry' => 'Asimetri',
                                                     'overplucking' => 'Aşırı alma', 'congenital' => 'Doğuştan', 'hair_loss' => 'Kıl dökülmesi', 'other' => 'Diğer']],
                    'main_concern_other' => ['type' => 'text', 'label' => 'Diğer (açıklayın)', 'required' => false, 'max' => 200],
                    'onset' => ['type' => 'select', 'label' => 'Ne zaman başladı?', 'required' => true,
                                'options' => ['lt1y' => '1 yıldan az', '1to3y' => '1–3 yıl', 'gt3y' => '3 yıldan fazla', 'birth' => 'Doğuştan', 'unknown' => 'Bilmiyorum']],
                    'progression' => ['type' => 'radio', 'label' => 'Durum sabit mi, ilerliyor mu?', 'required' => true,
                                      'options' => ['stable' => 'Sabit', 'progressive' => 'İlerliyor', 'unknown' => 'Bilmiyorum'],
                                      'flag' => ['progressive' => 'unstable_hair_loss']],
                    'areas' => ['type' => 'multi', 'label' => 'Etkilenen bölgeler', 'required' => true,
                                'options' => ['head' => 'Kaş başı', 'body' => 'Kaş gövdesi', 'arch' => 'Kaş kavisi', 'tail' => 'Kaş kuyruğu', 'full' => 'Tüm kaş']],
                    'desired' => ['type' => 'textarea', 'label' => 'İstediğiniz yoğunluk/şekil ve beklentileriniz', 'required' => false, 'max' => 1000],
                    'previous_transplant' => ['type' => 'radio', 'label' => 'Daha önce kaş ekimi yaptırdınız mı?', 'required' => true,
                                              'options' => ['yes' => 'Evet', 'no' => 'Hayır', 'unknown' => 'Emin değilim'], 'flag' => ['yes' => 'prior_transplant'],
                                              'other_detail' => 'previous_transplant_detail', 'detail_on' => ['yes']],
                    'previous_transplant_detail' => ['type' => 'text', 'label' => 'Yaklaşık tarih ve yer', 'required' => false, 'max' => 200],
                    'previous_procedures' => ['type' => 'multi', 'label' => 'Kaş bölgesine daha önce uygulanan işlemler', 'required' => true, 'other_detail' => 'previous_procedures_detail',
                                              'options' => ['none' => 'Hiçbiri', 'microblading' => 'Microblading', 'permanent_makeup' => 'Kalıcı makyaj / dövme',
                                                            'laser_removal' => 'Lazerle silme', 'surgery' => 'Cerrahi', 'filler_botox' => 'Dolgu / botoks (bölgeye yakın)', 'other' => 'Diğer'],
                                              'flag_any_except' => ['none' => 'prior_procedure_near_area']],
                    'previous_procedures_detail' => ['type' => 'text', 'label' => 'Yaklaşık tarihler / açıklama', 'required' => false, 'max' => 300],
                    'timing' => ['type' => 'select', 'label' => 'Tercih ettiğiniz zamanlama', 'required' => false,
                                 'options' => ['asap' => 'En kısa sürede', '1to3m' => '1–3 ay içinde', '3to6m' => '3–6 ay içinde', 'undecided' => 'Kararsızım']],
                    'travel_needed' => ['type' => 'radio', 'label' => "İstanbul'a seyahat etmeniz gerekecek mi?", 'required' => false,
                                        'options' => ['yes' => 'Evet', 'no' => 'Hayır', 'unknown' => 'Bilmiyorum']],
                ],
            ],
            'health' => [
                'title'  => 'Sağlık taraması',
                'sensitive' => true,
                'fields' => [
                    'pregnancy' => ['type' => 'radio', 'label' => 'Hamilelik veya emzirme durumu', 'required' => true,
                                    'options' => ['yes' => 'Evet', 'no' => 'Hayır', 'na' => 'Geçerli değil', 'discuss' => 'Klinisyenle görüşmeyi tercih ederim'],
                                    'flag' => ['yes' => 'pregnancy_reported']],
                    'chronic' => ['type' => 'multi', 'label' => 'Kronik hastalıklar', 'required' => true, 'other_detail' => 'chronic_detail',
                                  'options' => ['none' => 'Yok', 'diabetes' => 'Diyabet', 'hypertension' => 'Yüksek tansiyon', 'heart' => 'Kalp / dolaşım hastalığı',
                                                'thyroid' => 'Tiroid hastalığı', 'autoimmune' => 'Otoimmün hastalık', 'bleeding' => 'Kanama / pıhtılaşma bozukluğu',
                                                'immune_suppression' => 'Bağışıklık baskılanması', 'other' => 'Diğer', 'unknown' => 'Bilmiyorum'],
                                  'flag' => ['bleeding' => 'bleeding_disorder', 'immune_suppression' => 'immune_suppression']],
                    'chronic_detail' => ['type' => 'text', 'label' => 'Açıklama', 'required' => false, 'max' => 300],
                    'skin' => ['type' => 'multi', 'label' => 'Cilt / saçlı deri / kaş bölgesi durumları', 'required' => true, 'other_detail' => 'skin_detail',
                               'options' => ['none' => 'Yok', 'active_infection' => 'Aktif enfeksiyon', 'alopecia' => 'Alopesi tanısı',
                                             'uncontrolled_hair_loss' => 'Kontrolsüz kıl dökülmesi', 'eczema_psoriasis' => 'Egzama / sedef',
                                             'keloid' => 'Keloid / kötü yara izi eğilimi', 'scarring' => 'Bölgede yara izi', 'other' => 'Diğer'],
                               'flag' => ['active_infection' => 'active_skin_problem', 'eczema_psoriasis' => 'active_skin_problem',
                                          'uncontrolled_hair_loss' => 'unstable_hair_loss', 'keloid' => 'keloid_tendency', 'alopecia' => 'alopecia_reported']],
                    'skin_detail' => ['type' => 'text', 'label' => 'Açıklama', 'required' => false, 'max' => 300],
                    'allergies' => ['type' => 'multi', 'label' => 'Alerjiler', 'required' => true, 'other_detail' => 'allergies_detail',
                                    'options' => ['none' => 'Yok', 'medication' => 'İlaç', 'local_anesthetic' => 'Lokal anestezik', 'latex' => 'Lateks',
                                                  'adhesive' => 'Yapıştırıcı / bant', 'other' => 'Diğer', 'unknown' => 'Bilmiyorum'],
                                    'flag_any_except' => ['none' => 'allergy_reported', 'unknown' => 'allergy_reported']],
                    'allergies_detail' => ['type' => 'text', 'label' => 'Açıklama', 'required' => false, 'max' => 300],
                    'medications' => ['type' => 'textarea', 'label' => 'Kullandığınız reçeteli ilaçlar', 'required' => false, 'max' => 1000],
                    'otc_supplements' => ['type' => 'textarea', 'label' => 'Reçetesiz ilaçlar, vitamin ve takviyeler', 'required' => false, 'max' => 1000],
                    'blood_thinners' => ['type' => 'radio', 'label' => 'Kan sulandırıcı / antikoagülan / antiagregan ilaç kullanıyor musunuz?', 'required' => true,
                                         'options' => ['yes' => 'Evet', 'no' => 'Hayır', 'unknown' => 'Bilmiyorum'], 'flag' => ['yes' => 'anticoagulant_reported'],
                                         'other_detail' => 'blood_thinners_detail', 'detail_on' => ['yes']],
                    'blood_thinners_detail' => ['type' => 'text', 'label' => 'İlaç adı', 'required' => false, 'max' => 200],
                    'smoking' => ['type' => 'select', 'label' => 'Sigara / nikotin', 'required' => true,
                                  'options' => ['no' => 'Kullanmıyorum', 'occasionally' => 'Ara sıra', 'daily' => 'Her gün']],
                    'alcohol' => ['type' => 'select', 'label' => 'Alkol', 'required' => true,
                                  'options' => ['no' => 'Kullanmıyorum', 'occasionally' => 'Ara sıra', 'regularly' => 'Düzenli']],
                    'prior_operations' => ['type' => 'textarea', 'label' => 'Geçirdiğiniz ameliyatlar', 'required' => false, 'max' => 1000],
                    'anesthesia_complications' => ['type' => 'radio', 'label' => 'Lokal anestezi / sedasyon / anestezi ile ilgili sorun yaşadınız mı?', 'required' => true,
                                                   'options' => ['yes' => 'Evet', 'no' => 'Hayır', 'unknown' => 'Bilmiyorum'], 'flag' => ['yes' => 'anesthesia_complication'],
                                                   'other_detail' => 'anesthesia_detail', 'detail_on' => ['yes']],
                    'anesthesia_detail' => ['type' => 'text', 'label' => 'Açıklama', 'required' => false, 'max' => 300],
                    'infectious' => ['type' => 'textarea', 'label' => 'Bulaşıcı hastalık bildirimi', 'required' => false, 'max' => 500, 'conditional_option' => 'ask_infectious'],
                    'additional' => ['type' => 'textarea', 'label' => 'Klinisyenin bilmesi gereken başka bir şey', 'required' => false, 'max' => 1500],
                ],
            ],
        ],
    ];
}

/** Flatten field definitions (only those enabled for the brand). */
function se_journey_fields($brand_id, $version = SE_JOURNEY_QUESTIONNAIRE_VERSION)
{
    $out = [];
    foreach (se_journey_questionnaire($version)['sections'] as $sk => $section) {
        foreach ($section['fields'] as $fk => $f) {
            if (isset($f['conditional_option'])) {
                if ((int) get_option('se_journey_' . $f['conditional_option'] . '_' . (int) $brand_id) !== 1) {
                    continue;   // e.g. infectious-disease question only when counsel/medical director enabled it
                }
            }
            $f['section'] = $sk;
            $out[$fk] = $f;
        }
    }

    return $out;
}

/**
 * Validate a (partial) answer set. Unknown fields are dropped; values are
 * type-checked, bounded and option-allowlisted. With $final=true required
 * fields must be present.
 *
 * @return array{clean:array,errors:array<string,string>,missing:string[]}
 */
function se_journey_validate_answers($brand_id, array $input, $final = false)
{
    $fields = se_journey_fields($brand_id);
    $clean = []; $errors = []; $missing = [];

    foreach ($fields as $key => $f) {
        $raw = $input[$key] ?? null;
        $has = $raw !== null && $raw !== '' && $raw !== [];
        if ($f['type'] === 'readonly') {
            continue;
        }
        if (!$has) {
            if ($final && !empty($f['required'])) {
                $missing[] = $key;
            }
            continue;
        }
        switch ($f['type']) {
            case 'text':
            case 'textarea':
                if (!is_scalar($raw)) { $errors[$key] = 'invalid'; break; }
                $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $raw));
                if (mb_strlen($v) > (int) ($f['max'] ?? 500)) { $errors[$key] = 'too_long'; break; }
                $clean[$key] = $v;
                break;
            case 'number':
                if (!is_scalar($raw) || !preg_match('/^\d{1,3}$/', trim((string) $raw))) { $errors[$key] = 'invalid'; break; }
                $n = (int) $raw;
                if ($n < (int) ($f['min'] ?? 0) || $n > (int) ($f['max'] ?? 999)) { $errors[$key] = 'out_of_range'; break; }
                $clean[$key] = $n;
                break;
            case 'select':
            case 'radio':
                if (!is_scalar($raw) || !isset($f['options'][(string) $raw])) { $errors[$key] = 'invalid_option'; break; }
                $clean[$key] = (string) $raw;
                break;
            case 'multi':
                $vals = is_array($raw) ? $raw : [$raw];
                $ok = [];
                foreach ($vals as $v) {
                    if (!is_scalar($v) || !isset($f['options'][(string) $v])) { $errors[$key] = 'invalid_option'; continue 3; }
                    $ok[(string) $v] = true;
                }
                $clean[$key] = array_keys($ok);
                break;
        }
    }

    // A detail field is only meaningful with its parent choice.
    foreach ($fields as $key => $f) {
        if (empty($f['other_detail']) || !isset($clean[$f['other_detail']])) {
            continue;
        }
        $parent = $clean[$key] ?? null;
        $trigger = $f['detail_on'] ?? ['other'];
        $selected = is_array($parent) ? $parent : [$parent];
        if (!array_intersect($trigger, $selected)) {
            unset($clean[$f['other_detail']]);
        }
    }

    return ['clean' => $clean, 'errors' => $errors, 'missing' => $missing];
}

/**
 * Review flags: staff attention items derived from answers. They are NOT an
 * eligibility calculation and nothing downstream auto-decides on them.
 */
function se_journey_review_flags($brand_id, array $answers, array $missing = [])
{
    $flags = [];
    foreach (se_journey_fields($brand_id) as $key => $f) {
        $v = $answers[$key] ?? null;
        if ($v === null || $v === '' || $v === []) {
            continue;
        }
        $vals = is_array($v) ? $v : [$v];
        if (!empty($f['flag'])) {
            foreach ($vals as $x) {
                if (isset($f['flag'][$x])) { $flags[$f['flag'][$x]] = true; }
            }
        }
        if (!empty($f['flag_any_except'])) {
            $except = array_keys($f['flag_any_except']);
            $flagName = reset($f['flag_any_except']);
            foreach ($vals as $x) {
                if (!in_array($x, $except, true)) { $flags[$flagName] = true; break; }
            }
        }
    }
    if (isset($answers['age']) && (int) $answers['age'] < 18) {
        $flags['age_under_18'] = true;
    }
    foreach ($missing as $m) {
        $flags['missing_answer:' . $m] = true;
    }

    return array_keys($flags);
}

/* ===========================================================================
 * Intake rows
 * ======================================================================== */

function se_journey_intake_get($j, $version = SE_JOURNEY_QUESTIONNAIRE_VERSION)
{
    $CI = &get_instance();
    $CI->db->where('journey_id', (int) $j->id)->where('questionnaire_version', $version);

    return $CI->db->get(db_prefix() . 'se_journey_intakes')->row();
}

function se_journey_intake_open($j, $version = SE_JOURNEY_QUESTIONNAIRE_VERSION)
{
    $existing = se_journey_intake_get($j, $version);
    if ($existing) {
        return $existing;
    }
    $CI  = &get_instance();
    $now = date('Y-m-d H:i:s');
    $CI->db->insert(db_prefix() . 'se_journey_intakes', [
        'journey_id' => (int) $j->id, 'brand_id' => (int) $j->brand_id, 'questionnaire_version' => $version,
        'status' => 'started', 'started_at' => $now, 'date_created' => $now,
    ]);

    return se_journey_intake_get($j, $version);
}

/** Decrypt answers for an intake row. Callers MUST have checked view_health and audited. */
function se_journey_intake_answers($intake)
{
    if (!$intake || empty($intake->answers_enc)) {
        return [];
    }
    $plain = se_journey_decrypt($intake->answers_enc);
    if ($plain === null) {
        return [];
    }
    $a = json_decode($plain, true);

    return is_array($a) ? $a : [];
}

/**
 * Autosave: merge validated partial answers into the sealed blob.
 * Refuses when health collection is not allowed or the key is absent.
 */
function se_journey_intake_save($j, array $input, $ip = '', $ua = '')
{
    if (!se_journey_health_collection_allowed((int) $j->brand_id)) {
        return ['ok' => false, 'reason' => 'health_collection_blocked', 'errors' => []];
    }
    if (!se_journey_consent_state($j)['health_data']) {
        return ['ok' => false, 'reason' => 'consent_required', 'errors' => []];
    }
    $v = se_journey_validate_answers((int) $j->brand_id, $input, false);
    if ($v['errors']) {
        return ['ok' => false, 'reason' => 'validation', 'errors' => $v['errors']];
    }
    $intake  = se_journey_intake_open($j);
    $answers = array_merge(se_journey_intake_answers($intake), $v['clean']);
    $json    = json_encode($answers, JSON_UNESCAPED_UNICODE);
    if (strlen($json) > SE_JOURNEY_MAX_ANSWERS_BYTES) {
        return ['ok' => false, 'reason' => 'too_large', 'errors' => []];
    }
    $sealed = se_journey_encrypt($json);
    if ($sealed === '') {
        return ['ok' => false, 'reason' => 'encryption_unavailable', 'errors' => []];
    }
    $CI  = &get_instance();
    $now = date('Y-m-d H:i:s');
    $sections = [];
    foreach (se_journey_questionnaire()['sections'] as $sk => $s) {
        $done = true;
        foreach ($s['fields'] as $fk => $f) {
            if (!empty($f['required']) && $f['type'] !== 'readonly' && !isset($answers[$fk])) { $done = false; break; }
        }
        if ($done) { $sections[] = $sk; }
    }
    $CI->db->where('id', (int) $intake->id)->update(db_prefix() . 'se_journey_intakes', [
        'answers_enc' => $sealed, 'answers_hash' => hash('sha256', $json), 'key_version' => se_journey_key_version(),
        'sections_done_json' => json_encode($sections), 'status' => $intake->status === 'submitted' ? 'submitted' : 'started',
        'last_saved_at' => $now, 'last_updated' => $now,
    ]);
    if ((string) $j->state === 'consent_pending' || (string) $j->state === 'intake_link_sent' || (string) $j->state === 'intake_incomplete') {
        se_journey_transition($j, 'intake_started', 'form_autosave', 'patient');
    }
    se_journey_event($j, 'intake_saved', 'sections done: ' . implode(',', $sections), [], 'patient');

    return ['ok' => true, 'reason' => '', 'errors' => [], 'sections_done' => $sections];
}

/**
 * Final submission: full validation, flags, patient record, consent
 * snapshot, staff task, state → intake_submitted, then the photo request.
 */
function se_journey_intake_submit($j, array $input, $ip = '', $ua = '')
{
    if (!se_journey_health_collection_allowed((int) $j->brand_id)) {
        return ['ok' => false, 'reason' => 'health_collection_blocked', 'errors' => [], 'missing' => []];
    }
    $consent = se_journey_consent_state($j);
    if (!$consent['health_data']) {
        return ['ok' => false, 'reason' => 'consent_required', 'errors' => [], 'missing' => []];
    }
    $intake  = se_journey_intake_open($j);
    $merged  = array_merge(se_journey_intake_answers($intake), $input);
    $v = se_journey_validate_answers((int) $j->brand_id, $merged, true);
    if ($v['errors'] || $v['missing']) {
        return ['ok' => false, 'reason' => 'validation', 'errors' => $v['errors'], 'missing' => $v['missing']];
    }
    $json   = json_encode($v['clean'], JSON_UNESCAPED_UNICODE);
    $sealed = se_journey_encrypt($json);
    if ($sealed === '') {
        return ['ok' => false, 'reason' => 'encryption_unavailable', 'errors' => [], 'missing' => []];
    }
    $flags = se_journey_review_flags((int) $j->brand_id, $v['clean'], []);
    $CI    = &get_instance();
    $now   = date('Y-m-d H:i:s');

    $CI->db->where('id', (int) $intake->id)->update(db_prefix() . 'se_journey_intakes', [
        'answers_enc' => $sealed, 'answers_hash' => hash('sha256', $json), 'key_version' => se_journey_key_version(),
        'status' => 'submitted', 'flags_json' => json_encode($flags), 'missing_json' => json_encode([]),
        'consent_snapshot_json' => json_encode($consent), 'submitted_at' => $now, 'last_saved_at' => $now, 'last_updated' => $now,
        'submitted_ip_hash' => $ip !== '' ? hash('sha256', $ip) : null, 'submitted_ua_hash' => $ua !== '' ? hash('sha256', $ua) : null,
    ]);

    // Non-sensitive identity fields flow to the lead/journey/patient record.
    se_journey_apply_identity($j, $v['clean']);

    $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', [
        'intake_version' => SE_JOURNEY_QUESTIONNAIRE_VERSION, 'intake_submitted_at' => $now, 'last_updated' => $now,
    ]);
    if (in_array((string) $j->state, ['consent_pending', 'intake_link_sent', 'intake_started', 'intake_incomplete'], true)) {
        se_journey_transition($j, 'intake_submitted', 'form_submitted', 'patient');
    }
    se_journey_event($j, 'intake_submitted', 'flags: ' . ($flags ? implode(', ', $flags) : 'none'), ['flags' => $flags, 'version' => SE_JOURNEY_QUESTIONNAIRE_VERSION], 'patient');
    se_journey_task($j, 'review', 'Intake submitted — review answers and photos', 'normal', date('Y-m-d H:i:s', time() + 2 * 86400), '');

    // Photos next (or straight to review when they already exist).
    if (function_exists('se_journey_send_photo_request')) {
        if (function_exists('se_journey_media_count') && se_journey_media_count($j) >= 3) {
            se_journey_transition($j, 'ready_for_review', 'photos_already_received', 'system');
        } else {
            se_journey_send_photo_request($j, 'intake:' . (int) $intake->id);
        }
    }

    return ['ok' => true, 'reason' => '', 'errors' => [], 'missing' => [], 'flags' => $flags];
}

/** Copy name/language from the form to the lead, journey and patient record (non-health). */
function se_journey_apply_identity($j, array $clean)
{
    $CI  = &get_instance();
    $now = date('Y-m-d H:i:s');
    $upd = ['last_updated' => $now];
    if (!empty($clean['preferred_language'])) {
        $upd['language'] = mb_substr((string) $clean['preferred_language'], 0, 8);
    }
    if (!empty($clean['full_name'])) {
        $upd['display_name'] = mb_substr((string) $clean['full_name'], 0, 191);
        if ((int) $j->lead_id > 0) {
            $CI->db->where('id', (int) $j->lead_id)->where('brand_id', (int) $j->brand_id)
                   ->update(db_prefix() . 'leads', ['name' => mb_substr((string) $clean['full_name'], 0, 191), 'lastcontact' => $now]);
        }
    }
    $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', $upd);
    if (function_exists('se_journey_lead_apply_identity')) {
        se_journey_lead_apply_identity($j, $clean);   // country / city / language / age / contact preference → lead
    }

    // Patient record (brand-scoped, one per lead) — created now that clinical data exists.
    if ((int) $j->patient_id <= 0 && (int) $j->lead_id > 0 && function_exists('se_patient_create')) {
        $CI->db->where('brand_id', (int) $j->brand_id)->where('lead_id', (int) $j->lead_id);
        $existing = $CI->db->get(db_prefix() . 'se_patients')->row();
        $pid = $existing ? (int) $existing->id : (int) se_patient_create([
            'brand_id' => (int) $j->brand_id, 'lead_id' => (int) $j->lead_id, 'client_id' => 0,
            'preferred_language' => $upd['language'] ?? (string) $j->language,
            'nationality' => isset($clean['country']) ? mb_substr((string) $clean['country'], 0, 64) : null, 'passport_no' => null,
        ]);
        if ($pid > 0) {
            $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', ['patient_id' => $pid]);
            $j->patient_id = $pid;
        }
    }
}

/* ===========================================================================
 * Consent from the form
 * ======================================================================== */

/** Current ledger state for the three journey purposes. */
function se_journey_consent_state($j)
{
    $out = ['health_data' => false, 'photo_publication' => false, 'marketing' => false, 'whatsapp' => true, 'version' => null];
    if ((int) $j->lead_id <= 0 || !function_exists('se_consent_granted')) {
        return $out;
    }
    foreach (['health_data', 'photo_publication', 'marketing'] as $p) {
        $out[$p] = se_consent_granted((int) $j->brand_id, 'lead', (int) $j->lead_id, $p);
    }
    $out['whatsapp'] = function_exists('se_consent_current')
        ? se_consent_current((int) $j->brand_id, 'lead', (int) $j->lead_id, 'whatsapp') !== SE_CONSENT_WITHDRAWN
        : true;
    $out['version'] = function_exists('se_consent_text_version') ? se_consent_text_version((int) $j->brand_id) : null;

    return $out;
}

/**
 * Record the consent step of the form.
 *   health_data       required to continue (decline → consent_declined)
 *   photo_publication optional, default no
 *   marketing         optional, default no — never affects evaluation
 */
function se_journey_record_form_consent($j, array $input, $ip = '', $ua = '')
{
    if ((int) $j->lead_id <= 0) {
        return ['ok' => false, 'reason' => 'no_lead'];
    }
    if (!se_journey_health_collection_allowed((int) $j->brand_id)) {
        return ['ok' => false, 'reason' => 'health_collection_blocked'];
    }
    $brand = (int) $j->brand_id; $lead = (int) $j->lead_id;
    $src = 'intake_form' . ($ip !== '' ? ':' . substr(hash('sha256', $ip), 0, 12) : '') . ($ua !== '' ? ':' . substr(hash('sha256', $ua), 0, 12) : '');
    $health = !empty($input['consent_health_data']) && se_consent_is_granted((string) $input['consent_health_data']);

    if ($health) {
        se_consent_grant($brand, $lead, 'health_data', $src, 'health_data_processing', 'yes');
    } else {
        se_consent_withdraw($brand, $lead, 'health_data', $src, 'health_data_processing', 'no');
    }
    foreach (['photo_publication' => 'consent_photo_publication', 'marketing' => 'consent_marketing'] as $purpose => $field) {
        $yes = !empty($input[$field]) && se_consent_is_granted((string) $input[$field]);
        if ($yes) {
            se_consent_grant($brand, $lead, $purpose, $src, $field, 'yes');
        } else {
            se_consent_withdraw($brand, $lead, $purpose, $src, $field, 'no');
        }
        // Advertising-measurement consent (`ads`, what the conversion outbox
        // requires) is recorded from the intake's marketing checkbox ONLY when
        // the brand has explicitly enabled that mapping — a legal decision the
        // owner/counsel take in Settings, never a default (CRM-M010).
        if ($purpose === 'marketing' && se_journey_ads_consent_from_intake($brand)) {
            if ($yes) {
                se_consent_grant($brand, $lead, 'ads', $src, $field, 'yes');
            } else {
                se_consent_withdraw($brand, $lead, 'ads', $src, $field, 'no');
            }
        }
    }
    se_journey_event($j, 'consent_recorded', 'health_data=' . ($health ? 'yes' : 'no'), ['version' => se_consent_text_version($brand)], 'patient');

    if (!$health) {
        if (in_array((string) $j->state, ['consent_pending', 'intake_link_sent', 'intake_started'], true)) {
            se_journey_transition($j, 'consent_declined', 'form_consent_declined', 'patient');
        }
        se_journey_task($j, 'consent_declined', 'Patient declined health-data processing — evaluation cannot continue by form', 'normal', null, '');
        if (function_exists('se_journey_send_copy')) {
            se_journey_send_copy($j, 'consent_declined_ack', [], ['purpose' => 'consent_declined_ack']);
        }

        if (function_exists('se_journey_sync_lead')) { se_journey_sync_lead($j, 'consent'); }

        return ['ok' => true, 'reason' => 'declined'];
    }
    if (in_array((string) $j->state, ['consent_pending', 'intake_link_sent', 'consent_declined'], true)) {
        se_journey_transition($j, 'intake_started', 'form_consent_granted', 'patient');
    }
    if (function_exists('se_journey_sync_lead')) { se_journey_sync_lead($j, 'consent'); }   // the optional consents change without a transition

    return ['ok' => true, 'reason' => ''];
}

/** Configured consent texts for the form (tr/en), '' when unconfigured; draft marker under bypass. */
function se_journey_consent_texts($brand_id, $lang = 'tr')
{
    $out = [];
    foreach (['health_data', 'photo_publication', 'marketing'] as $p) {
        $t = function_exists('se_consent_text') ? se_consent_text((int) $brand_id, $p, $lang) : '';
        if ($t === '' && se_journey_consent_bypass_active($brand_id)) {
            $t = '[TASLAK — hukuk onayı bekliyor] ' . ($p === 'health_data'
                ? 'Sağlık bilgilerimin ve kaş/yüz fotoğraflarımın yalnızca ön değerlendirme amacıyla işlenmesine açık rıza veriyorum.'
                : ($p === 'photo_publication'
                    ? 'Fotoğraflarımın tanıtım amacıyla kullanılmasına izin veriyorum (isteğe bağlı).'
                    : 'Tanıtım iletileri almayı kabul ediyorum (isteğe bağlı).'));
        }
        $out[$p] = $t;
    }

    return $out;
}

/**
 * Brand switch: does the intake's marketing consent also count as advertising
 * measurement consent (purpose `ads`)? Default OFF. While off, every
 * conversion of a WhatsApp-intake lead is skipped `consent_blocked` and the
 * Health page says so; flipping it is an owner/legal decision.
 */
function se_journey_ads_consent_from_intake($brand_id)
{
    return (int) get_option('se_consent_ads_from_intake_' . (int) $brand_id) === 1;
}

