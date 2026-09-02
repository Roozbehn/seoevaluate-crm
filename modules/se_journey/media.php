<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * se_journey — photographs.
 *
 * Identifiable eyebrow/face photographs are special-category personal data.
 * The rules here are therefore stricter than the rest of the CRM:
 *
 *   - nothing is stored without health_data consent recorded in the ledger;
 *   - the bytes are validated by content (magic bytes + image decode +
 *     dimension bounds), not by the name the sender chose, and are
 *     re-encoded to drop EXIF/metadata and neutralise appended payloads;
 *   - files are sealed with the journey key BEFORE they leave PHP and live
 *     under unguessable names in Cloudflare R2 (bucket azin-media, key
 *     crm/journey/…, through the crm-media gateway — the CRM host holds no
 *     R2 credential) or, while the gateway is not configured, in a private
 *     directory OUTSIDE the document root; there is no URL to a photo, only
 *     a staff-only, capability-gated, signed, expiring view route that logs
 *     every view and streams the decrypted bytes;
 *   - evaluation use and publication use are separate permissions on every
 *     row, never inferred from each other.
 */

define('SE_JOURNEY_MEDIA_MAX_BYTES', 5 * 1024 * 1024);
define('SE_JOURNEY_MEDIA_MIN_DIM', 300);
define('SE_JOURNEY_MEDIA_MAX_DIM', 8000);
define('SE_JOURNEY_MEDIA_FALLBACK_DIR', '/home/hyundaic/_se_journey_media');
define('SE_JOURNEY_MEDIA_SIGN_TTL', 600);

function se_journey_media_kinds()
{
    return ['frontal', 'left', 'right', 'donor', 'other', 'followup', 'unclassified'];
}

function se_journey_required_photo_kinds($j = null)
{
    $req = ['frontal', 'left', 'right'];
    if ($j && !empty($j->photos_required_json)) {
        $extra = json_decode((string) $j->photos_required_json, true);
        if (is_array($extra) && in_array('donor', $extra, true)) {
            $req[] = 'donor';
        }
    }

    return $req;
}

/* ===========================================================================
 * Storage
 * ======================================================================== */

/**
 * Private directory for SEALED journey media. Constant > option > a sibling
 * of the inbox media store (se_core/se_media.php), i.e. outside the docroot.
 * Deliberately separate from the inbox store: inbox files are plain bytes
 * served to any staff member who can open the thread; journey files are
 * sealed and served only through the capability-gated signed route.
 */
function se_journey_media_dir()
{
    if (defined('SE_JOURNEY_MEDIA_DIR') && SE_JOURNEY_MEDIA_DIR !== '') {
        return rtrim(SE_JOURNEY_MEDIA_DIR, '/');
    }
    $opt = trim((string) get_option('se_journey_media_dir'));
    if ($opt !== '') {
        return rtrim($opt, '/');
    }
    if (function_exists('se_media_dir')) {
        return rtrim(dirname(rtrim(se_media_dir(), '/')), '/') . '/_se_journey_media';
    }

    return SE_JOURNEY_MEDIA_FALLBACK_DIR;
}

/**
 * Where NEW sealed objects go. Option se_journey_media_storage:
 *   auto  (default) — R2 whenever the inbox store's gateway is ready, else local
 *   r2              — R2, but never silently: falls back to local when the gateway is not ready
 *   local           — the private directory only
 * Every row records its own `storage`, so both can coexist while files migrate.
 */
function se_journey_media_storage_driver()
{
    $opt = (string) get_option('se_journey_media_storage');
    $ready = function_exists('se_media_r2_ready') && se_media_r2_ready();
    if ($opt === 'local') {
        return 'local';
    }

    return $ready ? 'r2' : 'local';
}

/** R2 object path (relative to the gateway prefix) for a journey storage_ref. */
function se_journey_media_object_rel($storage_ref)
{
    return 'journey/' . ltrim((string) $storage_ref, '/');
}

/** Presence/writability report — never a path secret, safe for the readiness screen. */
function se_journey_media_storage_status()
{
    $dir = se_journey_media_dir();
    $exists = is_dir($dir);
    $writable = $exists && is_writable($dir);
    $inDocroot = defined('FCPATH') && FCPATH !== '' && strpos(realpath($dir) ?: $dir, rtrim(realpath(FCPATH) ?: FCPATH, '/')) === 0;
    $driver = se_journey_media_storage_driver();

    return ['exists' => $exists, 'writable' => $writable, 'outside_docroot' => !$inDocroot, 'encrypted' => se_journey_crypto_available(),
            'driver' => $driver, 'r2_ready' => function_exists('se_media_r2_ready') && se_media_r2_ready(),
            'r2_requested' => (string) get_option('se_journey_media_storage') !== 'local'];
}

/**
 * Validate image bytes by content. Returns normalised (re-encoded) bytes.
 *
 * @return array{ok:bool,reason:string,bytes:string,mime:string,width:int,height:int,stripped:bool}
 */
function se_journey_media_validate($bytes, $claimed_name = '')
{
    $fail = function ($r) { return ['ok' => false, 'reason' => $r, 'bytes' => '', 'mime' => '', 'width' => 0, 'height' => 0, 'stripped' => false]; };

    $bytes = (string) $bytes;
    if ($bytes === '') {
        return $fail('empty');
    }
    if (strlen($bytes) > SE_JOURNEY_MEDIA_MAX_BYTES) {
        return $fail('too_large');
    }

    // Executables / archives / scripts never get past this line, whatever the
    // declared type: magic bytes first.
    $head = substr($bytes, 0, 8);
    foreach (["MZ", "\x7fELF", "PK\x03\x04", "%PDF", "#!", "\xca\xfe\xba\xbe"] as $bad) {
        if (strpos($head, $bad) === 0) {
            return $fail('not_an_image');
        }
    }

    $mime = '';
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $fi->buffer($bytes);
    } else {
        if (strpos($bytes, "\xff\xd8\xff") === 0) { $mime = 'image/jpeg'; }
        elseif (strpos($bytes, "\x89PNG\r\n\x1a\n") === 0) { $mime = 'image/png'; }
        elseif (strpos($bytes, 'RIFF') === 0 && substr($bytes, 8, 4) === 'WEBP') { $mime = 'image/webp'; }
    }
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return $fail('unsupported_type');
    }

    // Declared extension (form uploads) must agree with the real type.
    if ($claimed_name !== '') {
        $ext = strtolower((string) pathinfo($claimed_name, PATHINFO_EXTENSION));
        $okExt = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
        if ($ext !== '' && !in_array($ext, $okExt[$mime], true)) {
            return $fail('extension_mismatch');
        }
    }

    $info = @getimagesizefromstring($bytes);
    if (!$info || empty($info[0]) || empty($info[1])) {
        return $fail('undecodable');
    }
    [$w, $h] = [(int) $info[0], (int) $info[1]];
    if ($w < SE_JOURNEY_MEDIA_MIN_DIM || $h < SE_JOURNEY_MEDIA_MIN_DIM) {
        return $fail('too_small');
    }
    if ($w > SE_JOURNEY_MEDIA_MAX_DIM || $h > SE_JOURNEY_MEDIA_MAX_DIM) {
        return $fail('too_large_dimensions');
    }

    // Polyglot defence + metadata strip: re-encode through GD when available.
    $stripped = false;
    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return $fail('undecodable');
        }
        ob_start();
        imageinterlace($im, 0);
        imagejpeg($im, null, 90);
        $out = (string) ob_get_clean();
        imagedestroy($im);
        if ($out === '') {
            return $fail('reencode_failed');
        }
        $bytes = $out; $mime = 'image/jpeg'; $stripped = true;
    } else {
        // No GD: refuse anything that smells like embedded code rather than
        // storing an unverified container. Metadata is NOT stripped (flagged).
        if (stripos($bytes, '<?php') !== false || stripos($bytes, '<script') !== false || stripos($bytes, '<%') !== false) {
            return $fail('polyglot_suspected');
        }
    }

    return ['ok' => true, 'reason' => '', 'bytes' => $bytes, 'mime' => $mime, 'width' => $w, 'height' => $h, 'stripped' => $stripped];
}

/** Seal and write bytes into the private store. Returns the relative storage ref or ''. */
function se_journey_media_store_bytes($brand_id, $journey_id, $bytes)
{
    if (!se_journey_crypto_available()) {
        return ['ok' => false, 'ref' => '', 'storage' => '', 'fallback' => false, 'error' => 'crypto_unavailable'];
    }
    $rel  = (int) $brand_id . '/' . (int) $journey_id;
    $name = bin2hex(random_bytes(16)) . '.enc';
    $ref  = $rel . '/' . $name;
    $sealed = se_journey_encrypt($bytes);
    if ($sealed === '') {
        return ['ok' => false, 'ref' => '', 'storage' => '', 'fallback' => false, 'error' => 'seal_failed'];
    }

    $fallback = false;
    if (se_journey_media_storage_driver() === 'r2') {
        // Ciphertext only ever reaches the gateway; the content type is opaque on purpose.
        $err = se_media_r2_put(se_journey_media_object_rel($ref), $sealed, 'application/octet-stream');
        if ($err === '') {
            return ['ok' => true, 'ref' => $ref, 'storage' => 'r2', 'fallback' => false, 'error' => ''];
        }
        $fallback = true;   // the gateway is down: keep the photo locally, migrate later
    }

    $base = se_journey_media_dir();
    $dir  = $base . '/' . $rel;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return ['ok' => false, 'ref' => '', 'storage' => '', 'fallback' => $fallback, 'error' => 'local_dir_unavailable'];
    }
    @chmod($base, 0700);
    if (@file_put_contents($dir . '/' . $name, $sealed, LOCK_EX) === false) {
        return ['ok' => false, 'ref' => '', 'storage' => '', 'fallback' => $fallback, 'error' => 'local_write_failed'];
    }
    @chmod($dir . '/' . $name, 0600);

    return ['ok' => true, 'ref' => $ref, 'storage' => 'local', 'fallback' => $fallback, 'error' => ''];
}

/** Sealed bytes of a row from wherever it lives ('' when missing). */
function se_journey_media_sealed_bytes($storage, $storage_ref)
{
    if ($storage === 'r2') {
        return function_exists('se_media_r2_get') ? (string) se_media_r2_get(se_journey_media_object_rel($storage_ref)) : '';
    }
    $path = se_journey_media_dir() . '/' . $storage_ref;

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/** Open a stored object (decrypted). Null on failure. */
function se_journey_media_read($media)
{
    if (!$media || empty($media->storage_ref) || !empty($media->deleted_at)) {
        return null;
    }
    $sealed = se_journey_media_sealed_bytes((string) ($media->storage ?? 'local'), (string) $media->storage_ref);
    if ($sealed === '') {
        return null;
    }

    return se_journey_decrypt($sealed);
}

/**
 * Remove the stored object. '' on success, 'unsupported' when the deployed
 * gateway has no DELETE route yet (the row keeps its object), else an error.
 */
function se_journey_media_delete_object($storage, $storage_ref)
{
    if ($storage_ref === '') {
        return '';
    }
    if ($storage === 'r2') {
        return function_exists('se_media_r2_delete') ? (string) se_media_r2_delete(se_journey_media_object_rel($storage_ref)) : 'unsupported';
    }
    $path = se_journey_media_dir() . '/' . $storage_ref;
    if (is_file($path) && !@unlink($path)) {
        return 'unlink failed';
    }

    return '';
}

/**
 * Cron: move sealed objects written locally (gateway down, or before R2 was
 * configured) up to R2 — upload, read back, compare, then unlink. Runs only
 * while the driver is r2. Returns ['moved','failed'].
 */
function se_journey_media_migrate_to_r2($limit = 10)
{
    $out = ['moved' => 0, 'failed' => 0];
    if (se_journey_media_storage_driver() !== 'r2') {
        return $out;
    }
    $CI = &get_instance();
    $table = db_prefix() . 'se_journey_media';
    $CI->db->where('storage', 'local')->where('deleted_at IS NULL')->where('storage_ref !=', '')->order_by('id', 'ASC')->limit(max(1, (int) $limit));
    foreach ($CI->db->get($table)->result_array() as $m) {
        $sealed = se_journey_media_sealed_bytes('local', (string) $m['storage_ref']);
        if ($sealed === '') {
            $out['failed']++;
            continue;
        }
        $rel = se_journey_media_object_rel((string) $m['storage_ref']);
        if (se_media_r2_put($rel, $sealed, 'application/octet-stream') !== '') {
            $out['failed']++;
            continue;
        }
        $back = se_media_r2_get($rel);
        if ($back === '' || !hash_equals(hash('sha256', $sealed), hash('sha256', $back))) {
            $out['failed']++;
            continue;
        }
        $CI->db->where('id', (int) $m['id'])->update($table, ['storage' => 'r2']);
        @unlink(se_journey_media_dir() . '/' . $m['storage_ref']);
        $out['moved']++;
    }

    return $out;
}

/* ===========================================================================
 * Ingest (form upload or WhatsApp)
 * ======================================================================== */

/**
 * Validate, store and record one photo. Consent is checked HERE, whatever the
 * caller did, because this is the last line before the disk.
 *
 * @return array{ok:bool,reason:string,id:int}
 */
function se_journey_media_ingest($j, $bytes, array $meta)
{
    $consent = se_journey_consent_state($j);
    if (!$consent['health_data']) {
        return ['ok' => false, 'reason' => 'consent_required', 'id' => 0];
    }
    if (!se_journey_health_collection_allowed((int) $j->brand_id)) {
        return ['ok' => false, 'reason' => 'health_collection_blocked', 'id' => 0];
    }
    $v = se_journey_media_validate($bytes, (string) ($meta['name'] ?? ''));
    if (!$v['ok']) {
        se_journey_event($j, 'media_rejected', (string) ($meta['source'] ?? 'unknown') . ': ' . $v['reason'], [], 'patient', null, null, (string) ($meta['wamid'] ?? ''));

        return ['ok' => false, 'reason' => $v['reason'], 'id' => 0];
    }
    $stored = se_journey_media_store_bytes((int) $j->brand_id, (int) $j->id, $v['bytes']);
    if (!$stored['ok']) {
        se_journey_event($j, 'media_store_failed', $stored['error'], [], 'system', null, null, null, (string) ($meta['wamid'] ?? ''));

        return ['ok' => false, 'reason' => 'storage_unavailable', 'id' => 0];
    }
    $ref = $stored['ref'];
    if ($stored['fallback']) {
        // Gateway unreachable at write time: the photo is safe locally and the
        // cron moves it to R2 — visible to staff, never silent.
        se_journey_event($j, 'media_store_fallback', 'R2 gateway unreachable — sealed locally, migration pending', [], 'system', null, null, null, (string) ($meta['wamid'] ?? ''));
    }
    $kind = in_array((string) ($meta['kind'] ?? ''), se_journey_media_kinds(), true) ? (string) $meta['kind'] : 'unclassified';
    $phase = in_array((string) $j->state, ['procedure_completed', 'aftercare_active', 'followup_due', 'completed'], true) ? 'followup' : 'evaluation';
    if ($kind === 'followup') { $phase = 'followup'; }

    $CI  = &get_instance();
    $now = date('Y-m-d H:i:s');
    try {
        $CI->db->insert(db_prefix() . 'se_journey_media', [
            'journey_id' => (int) $j->id, 'brand_id' => (int) $j->brand_id, 'intake_id' => (int) ($meta['intake_id'] ?? 0),
            'kind' => $kind, 'phase' => $phase, 'source' => (string) ($meta['source'] ?? 'form_upload') === 'whatsapp' ? 'whatsapp' : 'form_upload',
            'provider_media_id' => isset($meta['provider_media_id']) ? mb_substr((string) $meta['provider_media_id'], 0, 191) : null,
            'inbox_media_id' => !empty($meta['inbox_media_id']) ? (int) $meta['inbox_media_id'] : null,
            'wamid' => isset($meta['wamid']) && $meta['wamid'] !== '' ? mb_substr((string) $meta['wamid'], 0, 128) : null,
            'mime' => $v['mime'], 'width' => $v['width'], 'height' => $v['height'], 'bytes' => strlen($v['bytes']),
            'sha256' => hash('sha256', $v['bytes']), 'storage_ref' => $ref, 'storage' => $stored['storage'], 'key_version' => se_journey_key_version(),
            'metadata_stripped' => $v['stripped'] ? 1 : 0, 'state' => 'received',
            'evaluation_use_permitted' => 1, 'publication_permitted' => $consent['photo_publication'] ? 1 : 0,
            'uploaded_at' => $now, 'date_created' => $now,
        ]);
    } catch (Exception $e) {
        // Duplicate wamid: the same WhatsApp image delivered twice.
        se_journey_media_delete_object($stored['storage'], $ref);

        return ['ok' => false, 'reason' => 'duplicate', 'id' => 0];
    }
    $id = (int) $CI->db->insert_id();
    se_journey_event($j, 'media_received', $kind . ' via ' . ((string) ($meta['source'] ?? '') === 'whatsapp' ? 'whatsapp' : 'form'),
        ['media_id' => $id, 'phase' => $phase], 'patient', null, 'media', (string) $id, (string) ($meta['wamid'] ?? ''));

    return ['ok' => true, 'reason' => '', 'id' => $id];
}

/** Count of usable evaluation photos (received/accepted, not deleted). */
function se_journey_media_count($j, $phase = 'evaluation')
{
    $n = 0;
    foreach (se_journey_media_list($j) as $m) {
        if ($m['phase'] === $phase && in_array($m['state'], ['received', 'accepted'], true) && empty($m['deleted_at'])) {
            $n++;
        }
    }

    return $n;
}

function se_journey_media_list($j)
{
    $CI = &get_instance();
    $CI->db->where('journey_id', (int) $j->id)->where('brand_id', (int) $j->brand_id)->order_by('id', 'ASC');

    return $CI->db->get(db_prefix() . 'se_journey_media')->result_array();
}

/** Required-photo checklist. */
function se_journey_media_checklist($j)
{
    $have = [];
    foreach (se_journey_media_list($j) as $m) {
        if ($m['phase'] === 'evaluation' && in_array($m['state'], ['received', 'accepted'], true) && empty($m['deleted_at'])) {
            $have[$m['kind']] = true;
        }
    }
    $out = [];
    foreach (se_journey_required_photo_kinds($j) as $k) {
        $out[$k] = !empty($have[$k]);
    }
    $out['_unclassified'] = !empty($have['unclassified']);
    $out['_complete'] = !in_array(false, array_intersect_key($out, array_flip(se_journey_required_photo_kinds($j))), true);

    return $out;
}

/**
 * Inbound WhatsApp image. Consent-gated fetch through the controlled
 * downloader; a gated fetcher (no token) parks a placeholder row for a
 * later retry so nothing is lost and nothing is fetched unsafely.
 */
function se_journey_on_wa_media($j, array $ctx)
{
    $corr = (string) ($ctx['wamid'] ?? '');
    $consent = se_journey_consent_state($j);
    $collecting = in_array((string) $j->state, ['intake_submitted', 'photos_requested', 'photos_incomplete', 'photo_retake_requested',
        'ready_for_review', 'under_review', 'more_information_required', 'aftercare_active', 'followup_due', 'procedure_completed'], true);

    if (!$consent['health_data'] || !se_journey_health_collection_allowed((int) $j->brand_id)) {
        se_journey_event($j, 'media_declined', 'no health-data consent — not fetched', [], 'system', null, null, null, $corr);
        se_journey_task($j, 'media_no_consent', 'Patient sent a photo before consent — not stored; guide them to the form', 'normal', null, $corr);
        if (se_journey_automation_active($j) && function_exists('se_journey_send_copy')) {
            $t = in_array((string) $j->state, ['welcome_sent', 'new_whatsapp_enquiry'], true) ? null : se_journey_issue_token($j, 'intake', 0);
            $link = $t && $t['ok'] ? se_journey_public_url('se_journey/intake/' . $t['token']) : '';
            se_journey_send_copy($j, 'photos_no_consent', ['link' => $link], ['purpose' => 'photos_no_consent', 'correlation' => $corr, 'dedup_salt' => date('Ymd')]);
        }

        return ['handled' => true, 'reason' => 'media_no_consent', 'journey_id' => (int) $j->id];
    }
    if (!$collecting) {
        se_journey_task($j, 'unexpected_media', 'Patient sent a photo outside a photo step — review manually', 'normal', null, $corr);
        // Still stored (consent exists) so staff can see it, classified as other.
    }

    // The bytes are NOT fetched here. The inbox media store (se_core/se_media.php)
    // registered this attachment when the message was stored and the
    // dispatcher pulls it with the Cloud API token in its own step — never
    // inside webhook/event processing. The journey parks a placeholder that
    // points at that inbox row; the `journey_media` dispatcher step (and the
    // 15-minute cron) seals the bytes into the journey store once they land.
    $mediaId = substr((string) $ctx['media_ref'], strlen('media:'));
    $inbox = function_exists('se_media_for_messages') ? se_media_for_messages('wa', [(int) ($ctx['message_id'] ?? 0)]) : [];
    $inboxRow = $inbox[(int) ($ctx['message_id'] ?? 0)] ?? null;

    $parkedId = se_journey_park_media($j, $mediaId, $corr, $inboxRow ? (int) $inboxRow['id'] : 0);
    if ($parkedId === 0) {
        return ['handled' => true, 'reason' => 'media_duplicate', 'journey_id' => (int) $j->id];
    }
    if ($inboxRow === null) {
        // The inbox store is absent or refused the attachment (e.g. a kind it
        // does not keep): nothing will ever land — tell staff now.
        se_journey_event($j, 'media_fetch_failed', 'not registered by the inbox media store', [], 'system', null, null, null, $corr);
        se_journey_task($j, 'media_fetch_failed', 'Photo could not be registered for download — ask the patient to use the secure upload link', 'normal', null, $corr);
        $CI = &get_instance();
        $CI->db->where('id', $parkedId)->update(db_prefix() . 'se_journey_media', ['state' => 'fetch_failed', 'last_error' => 'not registered by inbox store']);

        return ['handled' => true, 'reason' => 'media_fetch_failed', 'journey_id' => (int) $j->id];
    }
    if (!se_journey_media_fetch_possible()) {
        se_journey_task($j, 'media_fetch_gated', 'Photo received but the Cloud API token is missing — cannot download (install wa_token)', 'normal', null, 'gated');
    }

    // Already fetched (a redelivered event processed after the store landed)?
    $parked = se_journey_media_row($parkedId);
    if ($parked && (string) ($inboxRow['state'] ?? '') === 'stored') {
        return se_journey_ingest_parked($j, $parked, $inboxRow);
    }

    return ['handled' => true, 'reason' => 'media_parked', 'journey_id' => (int) $j->id];
}

/** Can the inbox store actually pull WhatsApp bytes right now (token or test seam)? */
function se_journey_media_fetch_possible()
{
    if (!function_exists('se_media_fetch_pending')) {
        return false;
    }
    if (is_callable($GLOBALS['SE_MEDIA_FETCHER'] ?? null)) {
        return true;
    }

    return function_exists('se_wa_cloud_token') && se_wa_cloud_token() !== '';
}

function se_journey_media_row($id)
{
    $CI = &get_instance();
    $CI->db->where('id', (int) $id);

    return $CI->db->get(db_prefix() . 'se_journey_media')->row_array() ?: null;
}

/**
 * Placeholder row for a photo whose bytes have not been sealed yet. Returns
 * the row id, or 0 when this wamid was already parked/ingested (unique key).
 */
function se_journey_park_media($j, $provider_media_id, $wamid, $inbox_media_id = 0)
{
    $CI = &get_instance();
    $now = date('Y-m-d H:i:s');
    try {
        $CI->db->insert(db_prefix() . 'se_journey_media', [
            'journey_id' => (int) $j->id, 'brand_id' => (int) $j->brand_id, 'kind' => 'unclassified', 'phase' => 'evaluation',
            'source' => 'whatsapp', 'provider_media_id' => mb_substr((string) $provider_media_id, 0, 191),
            'inbox_media_id' => (int) $inbox_media_id > 0 ? (int) $inbox_media_id : null,
            'wamid' => $wamid !== '' ? mb_substr((string) $wamid, 0, 128) : null, 'state' => 'pending_fetch',
            'uploaded_at' => $now, 'date_created' => $now,
        ]);
    } catch (Exception $e) {
        return 0;
    }

    return (int) $CI->db->insert_id();
}

/**
 * Seal one parked photo from its (already fetched) inbox row. The inbox copy
 * is read through se_media_local_copy so R2-backed rows work too; a temp copy
 * is removed afterwards. The placeholder is deleted first so the unique wamid
 * is free for the real row.
 */
function se_journey_ingest_parked($j, array $parked, array $inboxRow)
{
    $CI = &get_instance();
    $corr = (string) ($parked['wamid'] ?? '');
    $abs = function_exists('se_media_local_copy') ? se_media_local_copy($inboxRow) : (function_exists('se_media_abs_path') ? se_media_abs_path($inboxRow) : '');
    $bytes = $abs !== '' && is_file($abs) ? (string) file_get_contents($abs) : '';
    if (($inboxRow['storage'] ?? 'local') === 'r2' && $abs !== '') {
        @unlink($abs);
    }
    if ($bytes === '') {
        $CI->db->where('id', (int) $parked['id'])->update(db_prefix() . 'se_journey_media', ['state' => 'fetch_failed', 'last_error' => 'inbox file unreadable']);
        se_journey_task($j, 'media_fetch_failed', 'Photo download failed (inbox file unreadable) — ask the patient to use the secure upload link', 'normal', null, $corr);

        return ['handled' => true, 'reason' => 'media_fetch_failed', 'journey_id' => (int) $j->id];
    }

    $CI->db->where('id', (int) $parked['id'])->delete(db_prefix() . 'se_journey_media');
    $r = se_journey_media_ingest($j, $bytes, ['source' => 'whatsapp', 'provider_media_id' => (string) $parked['provider_media_id'],
        'wamid' => $corr, 'kind' => 'unclassified', 'inbox_media_id' => (int) ($inboxRow['id'] ?? 0)]);
    if (!$r['ok']) {
        if ($r['reason'] !== 'duplicate' && se_journey_automation_active($j)) {
            se_journey_task($j, 'media_rejected', 'A WhatsApp photo was rejected (' . $r['reason'] . ')', 'normal', null, $corr);
        }

        return ['handled' => true, 'reason' => 'media_' . $r['reason'], 'journey_id' => (int) $j->id];
    }

    // Optional (per brand): once the sealed copy exists, drop the plain
    // thread copy so the photo is only reachable through view_photos.
    if ((int) get_option('se_journey_purge_inbox_copy_' . (int) $j->brand_id) === 1) {
        se_journey_purge_inbox_copy($j, $inboxRow, $corr);
    }

    return se_journey_after_media_received($j, $corr);
}

/**
 * Remove the inbox store's plain copy of a photo that is now sealed in the
 * journey store. Local: unlink; R2: gateway DELETE (kept, with a task, when
 * the deployed Worker predates the route). The tblse_media row stays as a
 * placeholder so the thread still shows that a photo was received.
 */
function se_journey_purge_inbox_copy($j, array $inboxRow, $corr = '')
{
    if (($inboxRow['state'] ?? '') !== 'stored' || empty($inboxRow['path'])) {
        return false;
    }
    $CI = &get_instance();
    $table = db_prefix() . 'se_media';
    if (($inboxRow['storage'] ?? 'local') === 'r2') {
        $err = function_exists('se_media_r2_delete') ? se_media_r2_delete((string) $inboxRow['path']) : 'unsupported';
        if ($err !== '') {
            se_journey_task($j, 'inbox_purge_pending', 'Inbox copy of a sealed photo could not be purged from R2 (' . $err . ') — redeploy crm-media with the DELETE route', 'low', null, 'r2');

            return false;
        }
    } else {
        $abs = function_exists('se_media_abs_path') ? se_media_abs_path($inboxRow) : '';
        if ($abs !== '' && is_file($abs) && !@unlink($abs)) {
            return false;
        }
    }
    $CI->db->where('id', (int) $inboxRow['id'])->update($table, ['state' => 'purged', 'path' => null, 'last_error' => 'sealed into the patient journey store']);
    se_journey_event($j, 'inbox_copy_purged', 'plain thread copy removed after sealing', ['inbox_media_id' => (int) $inboxRow['id']], 'system', null, null, null, $corr);

    return true;
}

/**
 * Dispatcher step (`journey_media`, right after the inbox `media` step) and
 * cron safety net: seal parked photos whose inbox row has landed, surface the
 * ones the inbox store gave up on. Returns the number sealed.
 */
function se_journey_retry_parked_media($limit = 20)
{
    if (!function_exists('se_media_get')) {
        return 0;
    }
    $CI = &get_instance();
    $CI->db->where('state', 'pending_fetch')->order_by('id', 'ASC')->limit(max(1, (int) $limit));
    $rows = $CI->db->get(db_prefix() . 'se_journey_media')->result_array();
    $done = 0;
    foreach ($rows as $m) {
        $j = se_journey_get_raw((int) $m['journey_id']);
        if (!$j) { continue; }
        $inbox = (int) ($m['inbox_media_id'] ?? 0) > 0 ? se_media_get((int) $m['inbox_media_id']) : null;
        if ($inbox === null) {
            $CI->db->where('id', (int) $m['id'])->update(db_prefix() . 'se_journey_media', ['state' => 'fetch_failed', 'last_error' => 'inbox row missing']);
            se_journey_task($j, 'media_fetch_failed', 'Photo download failed (inbox row missing) — ask the patient to use the secure upload link', 'normal', null, (string) $m['wamid']);
            continue;
        }
        $state = (string) ($inbox['state'] ?? '');
        if ($state === 'stored') {
            $r = se_journey_ingest_parked($j, $m, $inbox);
            if (!in_array($r['reason'], ['media_fetch_failed', 'media_invalid', 'media_rejected'], true)) { $done++; }
        } elseif ($state === 'failed') {
            $why = mb_substr((string) ($inbox['last_error'] ?? 'download failed'), 0, 120);
            $CI->db->where('id', (int) $m['id'])->update(db_prefix() . 'se_journey_media', ['state' => 'fetch_failed', 'last_error' => mb_substr($why, 0, 191)]);
            se_journey_event($j, 'media_fetch_failed', $why, [], 'provider', null, null, null, (string) $m['wamid']);
            se_journey_task($j, 'media_fetch_failed', 'Photo download failed (' . $why . ') — ask the patient to use the secure upload link', 'normal', null, (string) $m['wamid']);
        }
        // pending / fetching: the inbox store is still retrying — wait.
    }

    return $done;
}

/** Common follow-through after a photo lands: counts, acknowledgements, state. */
function se_journey_after_media_received($j, $corr = '')
{
    $phase = in_array((string) $j->state, ['procedure_completed', 'aftercare_active', 'followup_due', 'completed'], true) ? 'followup' : 'evaluation';
    if ($phase === 'followup') {
        se_journey_task($j, 'followup_photo', 'Follow-up photo received — review', 'normal', null, $corr);
        if (function_exists('se_journey_on_aftercare_photo')) {
            se_journey_on_aftercare_photo($j, $corr);
        }

        return ['handled' => true, 'reason' => 'followup_photo', 'journey_id' => (int) $j->id];
    }

    $n = se_journey_media_count($j);
    $inPhotoStep = in_array((string) $j->state, ['intake_submitted', 'photos_requested', 'photos_incomplete', 'photo_retake_requested'], true);

    if ($n >= 3 && $inPhotoStep) {
        if ((string) $j->state === 'intake_submitted') {
            se_journey_transition($j, 'photos_requested', 'photos_arrived_early', 'system', null, $corr);
        }
        se_journey_transition($j, 'ready_for_review', 'photos_received', 'patient', null, $corr);
        se_journey_task($j, 'review', 'Photos received — classify, accept and review', 'normal', date('Y-m-d H:i:s', time() + 2 * 86400), '');
        if (function_exists('se_journey_send_copy')) {
            se_journey_send_copy($j, 'photos_received_ack', [], ['purpose' => 'photos_received_ack', 'correlation' => $corr]);
        }

        return ['handled' => true, 'reason' => 'photos_complete', 'journey_id' => (int) $j->id];
    }
    if ($inPhotoStep && function_exists('se_journey_send_copy')) {
        se_journey_send_copy($j, 'photos_partial_ack', ['n' => (string) $n], ['purpose' => 'photos_partial_ack', 'correlation' => $corr, 'dedup_salt' => 'n' . $n]);
    } elseif (!$inPhotoStep) {
        se_journey_task($j, 'additional_photo', 'Additional photo received', 'normal', null, $corr);
    }
    if (function_exists('se_journey_sync_lead')) { se_journey_sync_lead($j, 'photo'); }   // count only; no transition yet

    return ['handled' => true, 'reason' => 'photo_stored', 'journey_id' => (int) $j->id];
}

/* ===========================================================================
 * Staff actions
 * ======================================================================== */

function se_journey_media_get($media_id)
{
    $CI = &get_instance();
    $CI->db->where('id', (int) $media_id);
    if (function_exists('se_apply_scope_in')) {
        se_apply_scope_in('brand_id');
    }

    return $CI->db->get(db_prefix() . 'se_journey_media')->row();
}

function se_journey_media_classify($media_id, $kind, $staff_id)
{
    if (!in_array($kind, ['frontal', 'left', 'right', 'donor', 'other', 'followup'], true)) {
        return false;
    }
    $m = se_journey_media_get($media_id);
    if (!$m) {
        return false;
    }
    $affected = se_guarded_update(db_prefix() . 'se_journey_media', 'id', (int) $media_id, ['kind' => $kind, 'reviewed_by' => (int) $staff_id, 'reviewed_at' => date('Y-m-d H:i:s')]);
    if ($affected > 0) {
        se_journey_audit((int) $m->brand_id, (int) $m->journey_id, 'photo_classify', 'media', (string) $media_id, $kind);
    }

    return $affected > 0;
}

/** Accept the current evaluation photos; ready_for_review when the checklist is complete. */
function se_journey_media_accept($j, $staff_id)
{
    $CI = &get_instance();
    $CI->db->where('journey_id', (int) $j->id)->where('brand_id', (int) $j->brand_id)->where('phase', 'evaluation')->where('state', 'received')
           ->update(db_prefix() . 'se_journey_media', ['state' => 'accepted', 'reviewed_by' => (int) $staff_id, 'reviewed_at' => date('Y-m-d H:i:s')]);
    se_journey_audit((int) $j->brand_id, (int) $j->id, 'photos_accept', null, null, null);
    $check = se_journey_media_checklist($j);
    if ($check['_complete'] && in_array((string) $j->state, ['photos_requested', 'photos_incomplete', 'photo_retake_requested'], true)) {
        se_journey_transition($j, 'ready_for_review', 'photos_accepted', 'staff', $staff_id);
    }

    return $check;
}

function se_journey_retake_reasons()
{
    return ['blurry', 'dark', 'makeup', 'filter', 'angle', 'crop', 'other'];
}

/** Request a retake of one kind with a coded reason → concise tailored instruction. */
function se_journey_media_request_retake($j, $kind, $reason, $staff_id)
{
    if (!in_array($kind, ['frontal', 'left', 'right', 'donor'], true) || !in_array($reason, se_journey_retake_reasons(), true)) {
        return ['ok' => false, 'reason' => 'invalid'];
    }
    $CI = &get_instance();
    $CI->db->where('journey_id', (int) $j->id)->where('brand_id', (int) $j->brand_id)->where('kind', $kind)->where('phase', 'evaluation')
           ->update(db_prefix() . 'se_journey_media', ['state' => 'retake_requested', 'retake_reason' => $reason, 'reviewed_by' => (int) $staff_id, 'reviewed_at' => date('Y-m-d H:i:s')]);
    se_journey_audit((int) $j->brand_id, (int) $j->id, 'photo_retake', 'kind', $kind, $reason);

    $which  = se_journey_copy((int) $j->brand_id, 'photo_kind_' . $kind, [], (string) $j->language);
    $why    = se_journey_copy((int) $j->brand_id, 'retake_' . $reason, [], (string) $j->language);
    $token  = se_journey_issue_token($j, 'upload', (int) $staff_id);
    $link   = $token['ok'] ? se_journey_public_url('se_journey/intake/' . $token['token'] . '/photos') : '';
    $r = se_journey_send_copy($j, 'photo_retake', ['which' => $which, 'reason' => $why], ['purpose' => 'photo_retake', 'bypass_pause' => true,
        'template' => 'eyebrow_photos_retake_tr', 'template_vars' => [se_journey_template_name($j), trim($which . ' — ' . rtrim($why, '.'), ' —'), $link], 'dedup_salt' => $kind . ':' . $reason . ':' . date('YmdH')]);

    if (in_array((string) $j->state, ['photos_requested', 'photos_incomplete', 'ready_for_review', 'under_review', 'more_information_required', 'photo_retake_requested'], true)) {
        se_journey_transition($j, 'photo_retake_requested', 'retake_' . $kind, 'staff', $staff_id, null, $reason);
    }

    return ['ok' => (bool) $r['ok'], 'reason' => $r['reason']];
}

function se_journey_media_request_donor($j, $staff_id)
{
    $CI = &get_instance();
    $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', ['photos_required_json' => json_encode(['donor'])]);
    $j->photos_required_json = json_encode(['donor']);
    se_journey_audit((int) $j->brand_id, (int) $j->id, 'photo_donor_request', null, null, null);
    // Outside the window the photo-request template carries the upload link;
    // Meta refuses an empty parameter, so a real token link is issued.
    $token = se_journey_issue_token($j, 'upload', (int) $staff_id);
    $link  = $token['ok'] ? se_journey_public_url('se_journey/intake/' . $token['token'] . '/photos') : se_journey_public_url('');
    $r = se_journey_send_copy($j, 'donor_request', [], ['purpose' => 'donor_request', 'bypass_pause' => true,
        'template' => 'eyebrow_photos_request_tr', 'template_vars' => [se_journey_template_name($j), $link]]);
    if (in_array((string) $j->state, ['ready_for_review', 'under_review', 'photos_requested'], true)) {
        se_journey_transition($j, 'photo_retake_requested', 'donor_requested', 'staff', $staff_id);
    }

    return $r;
}

function se_journey_media_ready_for_review($j, $staff_id)
{
    se_journey_audit((int) $j->brand_id, (int) $j->id, 'photos_ready', null, null, null);
    if (in_array((string) $j->state, ['photos_requested', 'photos_incomplete', 'photo_retake_requested', 'intake_submitted'], true)) {
        if ((string) $j->state === 'intake_submitted') {
            return se_journey_transition($j, 'ready_for_review', 'staff_ready', 'staff', $staff_id);
        }

        return se_journey_transition($j, 'ready_for_review', 'staff_ready', 'staff', $staff_id);
    }

    return ['ok' => false, 'reason' => 'transition_not_allowed'];
}

/* ===========================================================================
 * Signed, expiring, staff-only view URLs
 * ======================================================================== */

function se_journey_media_sign_key()
{
    $k = se_journey_key();
    if ($k === '') {
        return '';
    }
    $derived = hash_hmac('sha256', 'se_journey_media_view', $k, true);
    sodium_memzero($k);

    return $derived;
}

/** Signature binds media id, the viewing staff member and the expiry. */
function se_journey_media_signature($media_id, $staff_id, $expires)
{
    $key = se_journey_media_sign_key();
    if ($key === '') {
        return '';
    }

    return substr(hash_hmac('sha256', (int) $media_id . '|' . (int) $staff_id . '|' . (int) $expires, $key), 0, 40);
}

function se_journey_media_view_url($media_id, $staff_id, $ttl = SE_JOURNEY_MEDIA_SIGN_TTL)
{
    $exp = time() + max(60, (int) $ttl);
    $sig = se_journey_media_signature($media_id, $staff_id, $exp);
    if ($sig === '') {
        return '';
    }

    return admin_url('se_journey/se_journey/media/' . (int) $media_id . '?e=' . $exp . '&s=' . $sig);
}

function se_journey_media_signature_valid($media_id, $staff_id, $expires, $sig)
{
    if ((int) $expires < time()) {
        return false;
    }
    $expected = se_journey_media_signature($media_id, $staff_id, $expires);

    return $expected !== '' && is_string($sig) && hash_equals($expected, $sig);
}
