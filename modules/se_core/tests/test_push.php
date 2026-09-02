<?php
/**
 * Web push: VAPID, payload encryption, subscription lifecycle.
 *
 * The encryption tests round-trip against an independently generated
 * subscription keypair rather than a fixture. That matters: every failure mode
 * in RFC 8291 is SILENT — a swapped key order or a wrong HKDF info string
 * produces a well-formed request that the push service accepts and the browser
 * quietly fails to decrypt. A golden-value fixture would not catch it; only
 * decrypting with the other half of the pair does.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function se_test_seed_push()
{
    $db = se_test_db();
    $db->tables = []; $db->autoinc = [];
    $db->seed('tblse_push_subscriptions', []);
    $GLOBALS['se_test']['options'] = [];
    se_push_register_http(null);
}

/** Stands in for a browser: a real P-256 subscription with its auth secret. */
function se_test_fake_browser()
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $d   = openssl_pkey_get_details($key);
    $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)
                  . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);

    return ['key' => $key, 'public_raw' => $pub,
            'p256dh' => se_push_b64url($pub), 'auth_raw' => ($a = random_bytes(16)),
            'auth' => se_push_b64url($a)];
}

/** Decrypts an aes128gcm body the way the browser's push handler would. */
function se_test_push_decrypt($body, $browser)
{
    $salt   = substr($body, 0, 16);
    $idlen  = ord($body[20]);
    $server = substr($body, 21, $idlen);
    $ct     = substr($body, 21 + $idlen);

    $shared = openssl_pkey_derive(se_push_peer_pem($server), $browser['key'], 32);
    $ikm    = se_push_hkdf($browser['auth_raw'], $shared,
                           "WebPush: info\x00" . $browser['public_raw'] . $server, 32);
    $cek    = se_push_hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
    $nonce  = se_push_hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

    $out = openssl_decrypt(substr($ct, 0, -16), 'aes-128-gcm', $cek,
                           OPENSSL_RAW_DATA, $nonce, substr($ct, -16));

    return $out === false ? null : rtrim($out, "\x02");
}

se_test_seed_push();

/* ======================================================================== */
se_group('VAPID: a token a push service will actually accept');

$vk = se_push_vapid_generate();
se_eq(87, strlen($vk['public']), 'public key is a base64url uncompressed P-256 point');
se_ok(strpos($vk['public'], '=') === false, 'base64url is unpadded');
se_ok(strpos($vk['public'], '+') === false && strpos($vk['public'], '/') === false,
      'base64url uses -_ , not +/');

$hdr = se_push_vapid_header('https://fcm.googleapis.com/fcm/send/abc123', $vk,
                            'mailto:crm@example.invalid', 1756800000);
se_ok(strpos($hdr, 'vapid t=') === 0, 'header is the vapid scheme');
preg_match('/t=([^,]+), k=(.+)$/', $hdr, $m);
list($h64, $p64, $s64) = explode('.', $m[1]);

$claims = json_decode(se_push_b64url_decode($p64), true);
// A JWT scoped to the full path is rejected by some services and accepted by
// others — the worst kind of bug to discover in production.
se_eq('https://fcm.googleapis.com', $claims['aud'], 'aud is the ORIGIN, never the full URL');
se_eq(1756800000 + 43200, $claims['exp'], 'exp is 12h — the RFC ceiling, not forever');
se_eq('mailto:crm@example.invalid', $claims['sub'], 'sub identifies us to the push service');
se_eq('ES256', json_decode(se_push_b64url_decode($h64), true)['alg'], 'alg is ES256');
se_eq($vk['public'], $m[2], 'k= carries the public key that signed it');

// openssl signs DER; JWS wants raw R||S. Skipping that conversion produces a
// signature every push service rejects with a bare 401 and no explanation.
$raw = se_push_b64url_decode($s64);
se_eq(64, strlen($raw), 'signature is the raw 64-byte R||S, not DER');

$r = ltrim(substr($raw, 0, 32), "\x00"); $ss = ltrim(substr($raw, 32), "\x00");
if (ord($r[0]) & 0x80) { $r = "\x00" . $r; }
if (ord($ss[0]) & 0x80) { $ss = "\x00" . $ss; }
$der = "\x30" . chr(4 + strlen($r) + strlen($ss)) . "\x02" . chr(strlen($r)) . $r
     . "\x02" . chr(strlen($ss)) . $ss;
se_eq(1, openssl_verify("{$h64}.{$p64}", $der,
      se_push_peer_pem(se_push_b64url_decode($vk['public'])), OPENSSL_ALGO_SHA256),
      'the signature verifies against our own public key');

se_eq(null, se_push_vapid_header('not-a-url', $vk, 'mailto:a@b.c'), 'a malformed endpoint yields no token');

/* ======================================================================== */
se_group('Payload encryption round-trips against a real subscription key');

$browser = se_test_fake_browser();
$plain   = json_encode(['t' => 'wa', 'title' => 'Yeni WhatsApp mesajı']);
$body    = se_push_encrypt($plain, $browser['p256dh'], $browser['auth']);

se_ok($body !== null, 'encryption produced a body');
se_eq(4096, unpack('N', substr($body, 16, 4))[1], 'record size header is 4096');
se_eq(65, ord($body[20]), 'key id is the 65-byte server public point');
se_eq($plain, se_test_push_decrypt($body, $browser),
      'the browser can decrypt it — the whole chain is right');

// The push service relays bytes it cannot read. This is why a title in
// Turkish is acceptable to send at all.
se_eq(false, strpos($body, 'WhatsApp') !== false, 'the plaintext does not appear in the body');

$b2 = se_push_encrypt($plain, $browser['p256dh'], $browser['auth']);
se_ok($body !== $b2, 'a fresh salt and ephemeral key every time — never a repeated ciphertext');

// A corrupt row must fail loudly here rather than deep inside openssl.
se_eq(null, se_push_encrypt($plain, se_push_b64url('short'), $browser['auth']),
      'a subscription key of the wrong length is refused');
se_eq(null, se_push_encrypt($plain, $browser['p256dh'], se_push_b64url('tiny')),
      'an auth secret of the wrong length is refused');

// Another subscription must NOT be able to read this one's payload.
$other = se_test_fake_browser();
se_eq(null, se_test_push_decrypt($body, $other), 'a different subscription cannot decrypt it');

/* ======================================================================== */
se_group('Subscriptions: one row per browser, pruned when the browser is gone');

se_test_seed_push();

se_ok(se_push_subscribe(10, 'https://fcm.googleapis.com/fcm/send/phone', $browser['p256dh'], $browser['auth'], 'Android'),
      'a phone subscribes');
se_ok(se_push_subscribe(10, 'https://fcm.googleapis.com/fcm/send/laptop', $browser['p256dh'], $browser['auth'], 'macOS'),
      'the same staff member also subscribes a laptop');
se_eq(2, count(se_test_db()->rows('tblse_push_subscriptions')),
      'both devices are kept — a clinic answers on whichever is to hand');

// Re-subscribing from the same browser must update, not accumulate. A browser
// re-registers on every service-worker update.
se_push_subscribe(10, 'https://fcm.googleapis.com/fcm/send/phone', $browser['p256dh'], $browser['auth'], 'Android 15');
se_eq(2, count(se_test_db()->rows('tblse_push_subscriptions')), 'a repeat subscribe does not duplicate');

// The endpoint is used as a curl target with our VAPID token attached, so an
// unvalidated one is a server-side request forgery.
se_eq(false, se_push_subscribe(10, 'http://fcm.googleapis.com/x', $browser['p256dh'], $browser['auth']),
      'a plaintext http endpoint is refused');
se_eq(false, se_push_subscribe(10, 'file:///etc/passwd', $browser['p256dh'], $browser['auth']),
      'a non-https scheme is refused');
se_eq(false, se_push_subscribe(0, 'https://fcm.googleapis.com/fcm/send/x', $browser['p256dh'], $browser['auth']),
      'a subscription with no staff member is refused');

/* ======================================================================== */
se_group('Sending: gated on a keypair, and dead endpoints are pruned');

se_test_seed_push();
se_push_subscribe(10, 'https://fcm.googleapis.com/fcm/send/phone', $browser['p256dh'], $browser['auth']);

// No VAPID keypair installed: nothing is attempted at all.
$tried = 0;
se_push_register_http(function ($e, $h, $b) use (&$tried) { $tried++; return ['status' => 201, 'transport_error' => false]; });
se_eq(0, se_push_notify([10], ['t' => 'wa']), 'with no keypair installed, nothing is sent');
se_eq(0, $tried, 'and no request is attempted');

se_test_install_secret('webpush_vapid', json_encode(se_push_vapid_generate()));

$seen = [];
se_push_register_http(function ($e, $h, $b) use (&$seen) {
    $seen[] = ['endpoint' => $e, 'headers' => $h, 'body' => $b];
    return ['status' => 201, 'transport_error' => false];
});
se_eq(1, se_push_notify([10], ['t' => 'wa', 'title' => 'Yeni mesaj']), 'one device accepted');
se_eq(1, count($seen), 'exactly one request');
se_ok(in_array('Content-Encoding: aes128gcm', $seen[0]['headers'], true), 'declares aes128gcm');
$auth_hdrs = array_values(array_filter($seen[0]['headers'], function ($x) { return strpos($x, 'Authorization:') === 0; }));
se_ok(strpos($auth_hdrs[0], 'vapid t=') !== false, 'carries a VAPID token');
se_eq(false, strpos($seen[0]['endpoint'], 'vapid') !== false, 'the token is not in the URL');

// Staff who did not subscribe get nothing, and one staff member's devices are
// never reachable by another's id.
se_eq(0, se_push_notify([11], ['t' => 'wa']), 'a staff member with no device gets nothing');
se_eq(0, se_push_notify([], ['t' => 'wa']), 'an empty recipient list sends nothing');

// 410 Gone is the service saying this browser is finished. Keeping the row
// means retrying a dead endpoint on every notification, forever.
se_push_register_http(function ($e, $h, $b) { return ['status' => 410, 'transport_error' => false]; });
se_eq(0, se_push_notify([10], ['t' => 'wa']), 'a gone endpoint reports nothing sent');
se_eq(0, count(se_test_db()->rows('tblse_push_subscriptions')), 'and the dead row is deleted');

// A transient failure must NOT delete the row — the phone is in a tunnel.
se_test_seed_push();
se_test_install_secret('webpush_vapid', json_encode(se_push_vapid_generate()));
se_push_subscribe(10, 'https://fcm.googleapis.com/fcm/send/phone', $browser['p256dh'], $browser['auth']);
se_push_register_http(function ($e, $h, $b) { return ['status' => 503, 'transport_error' => false]; });
se_push_notify([10], ['t' => 'wa']);
$rows = se_test_db()->rows('tblse_push_subscriptions');
se_eq(1, count($rows), 'a 503 keeps the subscription');
se_eq(1, (int) $rows[0]['failures'], 'and counts the failure');

se_push_register_http(null);

/* ======================================================================== */
se_group('What a notification is allowed to say');

se_test_seed_push();
se_test_install_secret('webpush_vapid', json_encode(se_push_vapid_generate()));
se_test_db()->seed('tblse_staff_brands', [
    ['staff_id' => 10, 'brand_id' => 22],
    ['staff_id' => 11, 'brand_id' => 22],
    ['staff_id' => 12, 'brand_id' => 99],
]);
se_push_subscribe(10, 'https://fcm.googleapis.com/fcm/send/a', $browser['p256dh'], $browser['auth']);
se_push_subscribe(11, 'https://fcm.googleapis.com/fcm/send/b', $browser['p256dh'], $browser['auth']);
se_push_subscribe(12, 'https://fcm.googleapis.com/fcm/send/c', $browser['p256dh'], $browser['auth']);

$captured = [];
$browser_ref = $browser;
se_push_register_http(function ($e, $h, $b) use (&$captured, $browser_ref) {
    $captured[] = ['endpoint' => $e, 'plain' => se_test_push_decrypt($b, $browser_ref)];
    return ['status' => 201, 'transport_error' => false];
});

/* An UNASSIGNED thread widens to the whole brand — an unanswered patient is
 * the failure that matters, not a redundant buzz. */
se_eq(2, se_push_notify_inbound('wa', 22, 501, 0), 'an unassigned thread notifies all brand staff');
se_eq(false, in_array('https://fcm.googleapis.com/fcm/send/c', array_column($captured, 'endpoint'), true),
      'another brand\'s staff are never notified');

$captured = [];
se_eq(1, se_push_notify_inbound('wa', 22, 501, 11), 'an assigned thread notifies only the assignee');
se_eq('https://fcm.googleapis.com/fcm/send/b', $captured[0]['endpoint'], 'and it is the right person');

/* THE RULE THIS SUITE EXISTS FOR. A push lands on a lock screen, on a phone
 * lying on a desk in a room with patients in it. It says what KIND of thing
 * happened and where to go — never who, never what they wrote. */
$captured = [];
se_push_notify_inbound('wa', 22, 501, 10);
$payload = json_decode($captured[0]['plain'], true);
se_eq('Yeni WhatsApp mesajı', $payload['title'], 'the title names the channel only');
foreach (['Ayşe', '+9055', 'kaş', 'greft', 'ekimi', 'fiyat'] as $banned) {
    se_eq(false, stripos($captured[0]['plain'], $banned) !== false,
          "no '{$banned}' can appear in a notification");
}
se_eq('wa-501', $payload['tag'],
      'the tag is per-conversation, so ten messages replace one another');

$captured = [];
se_push_notify_inbound('ig', 22, 777, 0);
se_eq('Yeni Instagram mesajı', json_decode($captured[0]['plain'], true)['title'], 'instagram has its own title');
se_eq('ig-777', json_decode($captured[0]['plain'], true)['tag'], 'and its own tag namespace');

$captured = [];
se_eq(2, se_push_notify_lead(22, 900, 'website'), 'a new lead notifies the brand, not an assignee');
se_eq('Yeni başvuru', json_decode($captured[0]['plain'], true)['title'], 'lead title');

/* A journey moves through many states on its own. Buzzing on each one trains
 * people to swipe notifications away, and then the ones that matter are gone. */
$captured = [];
se_eq(0, se_push_notify_journey(22, 1, 'welcome_sent', 0), 'an automated state is NOT pushed');
se_eq(0, se_push_notify_journey(22, 1, 'new_whatsapp_enquiry', 0), 'nor is the opening state');
se_eq(0, count($captured), 'and nothing was sent');

se_eq(2, se_push_notify_journey(22, 1, 'quote_accepted', 0), 'a quote acceptance IS pushed');
se_eq('Teklif kabul edildi', json_decode($captured[0]['plain'], true)['title'], 'with a title a human can act on');
se_eq('journey-1', json_decode($captured[0]['plain'], true)['tag'],
      'tagged per journey, so the latest state replaces the last');

$captured = [];
se_eq(1, se_push_notify_journey(22, 1, 'handoff_requested', 11), 'a handoff reaches the assignee');

/* Push not configured at all must be a silent no-op, never an error on a
 * webhook path. */
se_test_remove_secret('webpush_vapid');
$captured = [];
se_eq(0, se_push_notify_inbound('wa', 22, 501, 0), 'with push unconfigured nothing is sent');
se_eq(0, count($captured), 'and nothing is attempted');
se_eq(false, se_push_configured(), 'and the CRM reports push as unconfigured');

se_push_register_http(null);
