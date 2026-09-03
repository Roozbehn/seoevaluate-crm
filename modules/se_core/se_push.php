<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Web Push — the notification transport for the installable CRM.
 *
 * WHY THIS IS HAND-ROLLED
 * Perfex has no Composer autoloader in a module, so the usual web-push library
 * is not available. What is available on this host is openssl with EC support
 * and openssl_pkey_derive, which is everything RFC 8291 actually needs. The
 * alternative — vendoring a library tree into the repo — is a larger and less
 * auditable dependency than the ~120 lines below.
 *
 * WHAT A PUSH IS, precisely, because getting one part wrong fails silently:
 *   1. VAPID (RFC 8292): a short-lived ES256 JWT signed with OUR key, proving
 *      to the push service which application server is asking. Sent in the
 *      Authorization header alongside our public key.
 *   2. aes128gcm (RFC 8188 + RFC 8291): the payload is encrypted to the
 *      SUBSCRIPTION's public key, so the push service relays a blob it cannot
 *      read. Mozilla and Google never see the patient's name.
 *
 * That second property is the reason this is worth doing properly rather than
 * pushing an empty notification and making the client fetch. A push body that
 * says "Yeni WhatsApp mesajı" is already at the edge of what should leave the
 * building; it is encrypted end to end, and it carries no clinical content and
 * no message text — see se_push_notify_* callers.
 *
 * DELIVERY IS BEST-EFFORT AND MUST NEVER COST THE EVENT.
 * Every entry point swallows its own failures. A webhook that stores a
 * message and then fails to notify has still done the important half; a
 * webhook that throws on a dead push endpoint loses the message.
 */

/** Base64url without padding — every field in RFC 8292/8291 uses this form. */
function se_push_b64url($raw)
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function se_push_b64url_decode($str)
{
    $pad = strlen($str) % 4;
    if ($pad) { $str .= str_repeat('=', 4 - $pad); }

    return base64_decode(strtr($str, '-_', '+/'));
}

/**
 * The VAPID keypair, generated once and stored in the FILE secret store.
 *
 * The private key is a credential: it authorises anyone holding it to push to
 * every subscription we have registered. It lives with the other secrets
 * (mode 0600, outside the docroot), never in the options table, which renders
 * in the UI, and never in git.
 *
 * The PUBLIC key is not a secret and is handed to every browser that
 * subscribes — but it must be STABLE. Regenerating it silently invalidates
 * every existing subscription, and the failure mode is "notifications just
 * stopped" with nothing in any log. So this generates only when absent.
 */
function se_push_vapid_keys()
{
    if (!function_exists('se_secret_read')) {
        return null;
    }

    $stored = se_secret_read('webpush_vapid', 0);
    if ($stored !== '') {
        $decoded = json_decode($stored, true);
        if (is_array($decoded) && !empty($decoded['public']) && !empty($decoded['private'])) {
            return $decoded;
        }
    }

    return null;
}

/** Generates a VAPID keypair. Returns the array to be INSTALLED, never stores it. */
function se_push_vapid_generate()
{
    $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($res === false) {
        return null;
    }

    $details = openssl_pkey_get_details($res);
    if (empty($details['ec']['x']) || empty($details['ec']['y']) || empty($details['ec']['d'])) {
        return null;
    }

    // Uncompressed point: 0x04 || X || Y, both left-padded to 32 bytes.
    $public = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
                     . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

    return [
        'public'  => se_push_b64url($public),
        'private' => se_push_b64url(str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT)),
    ];
}

/** The public key the browser needs, or '' when push is not configured. */
function se_push_public_key()
{
    $keys = se_push_vapid_keys();

    return $keys ? (string) $keys['public'] : '';
}

/** Push is usable only with a keypair installed. Boolean, never the reason. */
function se_push_configured()
{
    return se_push_public_key() !== '';
}

/**
 * Builds a PEM EC private key from the raw 32-byte scalar.
 *
 * openssl_sign needs a key resource; we store 32 raw bytes. Rather than pull
 * in an ASN.1 library, the DER for a P-256 private key is a fixed prefix
 * around the scalar and the public point — the only variable parts. This is
 * the standard SEC1 ECPrivateKey structure; the constants are the DER tags and
 * the P-256 OID, not magic.
 */
function se_push_ec_pem($private_raw, $public_raw)
{
    $der = "\x30\x77"                                   // SEQUENCE, 0x77 bytes
         . "\x02\x01\x01"                               // INTEGER version = 1
         . "\x04\x20" . $private_raw                    // OCTET STRING, 32-byte scalar
         . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"  // [0] OID prime256v1
         . "\xa1\x44\x03\x42\x00" . $public_raw;        // [1] BIT STRING, 65-byte point

    return "-----BEGIN EC PRIVATE KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END EC PRIVATE KEY-----\n";
}

/**
 * The VAPID Authorization header value for one push endpoint.
 *
 * `aud` is the endpoint's ORIGIN, not the full URL — a JWT scoped to the whole
 * path is rejected by some services and accepted by others, which is the worst
 * kind of bug to have in production. `exp` is deliberately short: the token
 * travels to a third party, and a long-lived one is a standing licence to push
 * to our subscribers.
 */
function se_push_vapid_header($endpoint, $keys, $subject, $now = null)
{
    $parts = parse_url($endpoint);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $aud = $parts['scheme'] . '://' . $parts['host'];

    $now = $now === null ? time() : (int) $now;

    $header  = se_push_b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = se_push_b64url(json_encode([
        'aud' => $aud,
        'exp' => $now + 43200,   // 12 h — the RFC's ceiling, not an arbitrary one
        'sub' => $subject,
    ]));

    $private = se_push_b64url_decode($keys['private']);
    $public  = se_push_b64url_decode($keys['public']);
    $pem     = se_push_ec_pem($private, $public);

    $der = '';
    if (!openssl_sign($header . '.' . $payload, $der, $pem, OPENSSL_ALGO_SHA256)) {
        return null;
    }

    // openssl produces a DER SEQUENCE of two INTEGERs; JWS wants the raw
    // 64-byte R||S concatenation. Skipping this conversion produces a
    // signature every push service rejects with a bare 401.
    $sig = se_push_der_to_raw($der);
    if ($sig === null) {
        return null;
    }

    return 'vapid t=' . $header . '.' . $payload . '.' . se_push_b64url($sig)
         . ', k=' . $keys['public'];
}

/** DER SEQUENCE(INTEGER r, INTEGER s) -> 64 raw bytes, each left-padded to 32. */
function se_push_der_to_raw($der)
{
    $offset = 0;
    if (strlen($der) < 8 || ord($der[$offset++]) !== 0x30) {
        return null;
    }

    $len = ord($der[$offset++]);
    if ($len & 0x80) {
        // Long-form length: skip the length-of-length bytes.
        $offset += ($len & 0x7f);
    }

    $out = '';
    for ($i = 0; $i < 2; $i++) {
        if ($offset >= strlen($der) || ord($der[$offset++]) !== 0x02) {
            return null;
        }
        $ilen = ord($der[$offset++]);
        $int  = substr($der, $offset, $ilen);
        $offset += $ilen;
        // DER prepends 0x00 when the high bit is set; the raw form has no
        // sign, so strip it and left-pad to a fixed 32 bytes.
        $int = ltrim($int, "\x00");
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
    }

    return strlen($out) === 64 ? $out : null;
}

/** HKDF (RFC 5869) with SHA-256, which is the only variant RFC 8291 uses. */
function se_push_hkdf($salt, $ikm, $info, $length)
{
    $prk = hash_hmac('sha256', $ikm, $salt, true);

    return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $length);
}

/**
 * Encrypts a payload to one subscription (RFC 8291, aes128gcm).
 *
 * The push service relays a blob it cannot read: the content encryption key is
 * derived from an ECDH between an ephemeral keypair minted here and the
 * subscriber's own public key, salted with their auth secret. Mozilla and
 * Google carry the bytes and learn nothing from them.
 *
 * Returns the RFC 8188 body: salt(16) || rs(4) || idlen(1) || key(65) || ciphertext.
 */
function se_push_encrypt($payload, $p256dh_b64, $auth_b64)
{
    $client_public = se_push_b64url_decode($p256dh_b64);
    $auth          = se_push_b64url_decode($auth_b64);

    // A subscription key is 65 bytes (0x04 || X || Y) and the auth secret is
    // 16. Anything else is a corrupt row, and feeding it to openssl produces
    // an unhelpful failure much further down.
    if (strlen($client_public) !== 65 || strlen($auth) !== 16) {
        return null;
    }

    $ephemeral = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($ephemeral === false) {
        return null;
    }
    $ed = openssl_pkey_get_details($ephemeral);
    $server_public = "\x04" . str_pad($ed['ec']['x'], 32, "\0", STR_PAD_LEFT)
                            . str_pad($ed['ec']['y'], 32, "\0", STR_PAD_LEFT);

    $peer = se_push_peer_pem($client_public);
    if ($peer === null) {
        return null;
    }

    $shared = openssl_pkey_derive($peer, $ephemeral, 32);
    if ($shared === false || $shared === null) {
        return null;
    }

    // The order of the two keys in this info string is fixed by the RFC and is
    // NOT symmetric: client first, then server. Swapping them yields a key the
    // browser cannot derive, and the only symptom is a notification that never
    // appears.
    $prk_info = "WebPush: info\x00" . $client_public . $server_public;
    $ikm      = se_push_hkdf($auth, $shared, $prk_info, 32);

    $salt = random_bytes(16);
    $cek  = se_push_hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = se_push_hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

    // aes128gcm requires a padding delimiter; 0x02 marks the last record.
    $tag = '';
    $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek,
                                  OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        return null;
    }

    return $salt
         . pack('N', 4096)                 // record size
         . chr(strlen($server_public))     // key id length
         . $server_public
         . $ciphertext . $tag;
}

/** Wraps a raw 65-byte P-256 point as a PEM public key openssl will accept. */
function se_push_peer_pem($point)
{
    $der = "\x30\x59\x30\x13"
         . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"          // OID ecPublicKey
         . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"      // OID prime256v1
         . "\x03\x42\x00" . $point;

    $pem = "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END PUBLIC KEY-----\n";

    $key = openssl_pkey_get_public($pem);

    return $key === false ? null : $key;
}

/** HTTP seam, so every branch below is testable without a push service. */
function se_push_register_http(?callable $fn = null)
{
    static $impl = null;
    if ($fn !== null) { $impl = $fn; }

    return $impl;
}

function se_push_http($endpoint, $headers, $body)
{
    $impl = se_push_register_http();
    if ($impl !== null) {
        return call_user_func($impl, $endpoint, $headers, $body);
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    return ['status' => $status, 'transport_error' => $err !== ''];
}

/**
 * Stores or refreshes one browser's subscription.
 *
 * Keyed on the endpoint, not on the staff member: the same person on a phone
 * and a laptop is two subscriptions and both should ring. Re-subscribing from
 * the same browser updates the existing row rather than accumulating
 * duplicates, and resets the failure count — the browser has just told us it
 * is alive.
 */
function se_push_subscribe($staff_id, $endpoint, $p256dh, $auth, $user_agent = '')
{
    $CI = &get_instance();

    $staff_id = (int) $staff_id;
    if ($staff_id <= 0 || $endpoint === '' || $p256dh === '' || $auth === '') {
        return false;
    }

    // Only ever a real https push endpoint. This value is used as a curl
    // target, so an unvalidated one is a server-side request forgery with our
    // VAPID token attached.
    $parts = parse_url($endpoint);
    if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https' || empty($parts['host'])) {
        return false;
    }
    // ...and only a known push service (browser vendors' endpoints), never an
    // arbitrary HTTPS host a staff browser hands us.
    if (function_exists('se_host_allowed') && !se_host_allowed($endpoint, se_push_endpoint_hosts())) {
        return false;
    }

    $hash  = hash('sha256', $endpoint);
    $table = db_prefix() . 'se_push_subscriptions';

    $CI->db->where('endpoint_hash', $hash);
    $existing = $CI->db->get($table)->row();

    $row = [
        'staff_id'   => $staff_id,
        'p256dh'     => mb_substr($p256dh, 0, 200),
        'auth'       => mb_substr($auth, 0, 64),
        'user_agent' => mb_substr((string) $user_agent, 0, 255),
        'failures'   => 0,
    ];

    if ($existing) {
        $CI->db->where('id', (int) $existing->id)->update($table, $row);

        return true;
    }

    $row['endpoint_hash'] = $hash;
    $row['endpoint']      = $endpoint;
    $row['date_created']  = date('Y-m-d H:i:s');
    $CI->db->insert($table, $row);

    return true;
}

/** Removes one subscription. Called when a browser unsubscribes, and on 404/410. */
function se_push_unsubscribe($endpoint, $staff_id = 0)
{
    $CI = &get_instance();
    $CI->db->where('endpoint_hash', hash('sha256', $endpoint));
    // A staff member may only remove their own device; the endpoint alone is
    // not proof of ownership (it is visible to any script on the page).
    if ((int) $staff_id > 0) {
        $CI->db->where('staff_id', (int) $staff_id);
    }
    $CI->db->delete(db_prefix() . 'se_push_subscriptions');

    return true;
}

/**
 * Pushes a notification to every device belonging to a set of staff.
 *
 * Returns the number of push services that accepted. Never throws: the caller
 * is a webhook or a status change that has already done the important work,
 * and losing a message to save a notification is the wrong trade.
 *
 * A payload NEVER carries message text, a patient name, a phone number or
 * anything clinical — only what kind of thing happened and where to go. It is
 * encrypted end to end, but it also lands on a lock screen in a waiting room.
 */
function se_push_notify(array $staff_ids, array $payload)
{
    $keys = se_push_vapid_keys();
    if (!$keys) {
        return 0;
    }

    $staff_ids = array_values(array_unique(array_filter(array_map('intval', $staff_ids))));
    if (empty($staff_ids)) {
        return 0;
    }

    $CI    = &get_instance();
    $table = db_prefix() . 'se_push_subscriptions';
    $CI->db->where_in('staff_id', $staff_ids);
    $subs = $CI->db->get($table)->result();

    if (empty($subs)) {
        return 0;
    }

    $subject = 'mailto:' . (get_option('smtp_email') ?: 'crm@localhost');
    $body    = json_encode($payload);
    $sent    = 0;

    foreach ($subs as $sub) {
        $encrypted = se_push_encrypt($body, $sub->p256dh, $sub->auth);
        if ($encrypted === null) {
            continue;
        }

        $auth_header = se_push_vapid_header($sub->endpoint, $keys, $subject);
        if ($auth_header === null) {
            continue;
        }

        $res = se_push_http($sub->endpoint, [
            'Authorization: ' . $auth_header,
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: 86400',
            'Urgency: normal',
        ], $encrypted);

        $status = (int) $res['status'];

        if ($status >= 200 && $status < 300) {
            $sent++;
            $CI->db->where('id', (int) $sub->id)
                   ->update($table, ['failures' => 0, 'last_success_at' => date('Y-m-d H:i:s')]);
            continue;
        }

        /* 404/410 is the push service telling us this browser is gone for
         * good — the user cleared site data, or uninstalled the PWA. Deleting
         * immediately is correct and is the only thing that keeps this table
         * from growing into a list of dead endpoints we retry forever. */
        if ($status === 404 || $status === 410) {
            se_push_unsubscribe($sub->endpoint);
            continue;
        }

        $CI->db->where('id', (int) $sub->id)
               ->update($table, ['failures' => (int) $sub->failures + 1]);
    }

    return $sent;
}
