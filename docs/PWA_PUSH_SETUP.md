# Installable CRM + web push — setup

The CRM installs to a phone home screen and pushes notifications for inbound
WhatsApp/Instagram messages, new leads, and patient-journey states that need a
person. This is what the owner has to do once; everything else is in the code.

## 1. Generate and install the VAPID keypair

Push is inert until this exists — `se_push_configured()` is false and every
notification call is a silent no-op. That is deliberate: a half-configured push
path that throws on a webhook would cost messages.

On the host:

```bash
php -r '
define("BASEPATH","/");
require "modules/se_core/se_push.php";
echo json_encode(se_push_vapid_generate()), PHP_EOL;'
```

Install the JSON it prints with the safe installer, which reads from stdin and
never puts a value in argv or a log:

```bash
/home/hyundaic/bin/se-secret-install.sh webpush_vapid
```

**The public half must never change.** Regenerating the keypair silently
invalidates every subscription that already exists, and the only symptom is
that notifications stop, with nothing in any log.

## 2. Serve the service worker from the site root

A service worker only controls pages at or below its own URL. Served from
`/se_core/se_pwa/sw` it would control nothing useful. Add to `.htaccess` at the
docroot, above the existing CI rewrite:

```apache
RewriteRule ^sw\.js$ index.php/se_core/se_pwa/sw [L]
```

The controller already sends `Service-Worker-Allowed: /`, which is what lets a
worker served from a subpath claim root scope. Without one of these two, the
worker registers successfully and then never receives a push — which looks
exactly like a broken subscription.

## 3. Replace the placeholder icons

`modules/se_core/assets/icon-192.png`, `icon-512.png` and `icon-maskable.png`
are plain dark placeholders. The maskable one needs its mark inside the middle
80% or Android will crop it.

## 4. Turn it on, per person

Each staff member opens the CRM and taps the bell in the top bar. The prompt
only ever appears on that click — a permission request fired on page load is
auto-denied by current browsers and burns the one chance to ask.

**On iPhone the CRM must be added to the home screen first.** iOS delivers web
push only to an installed PWA; in Safari's normal browsing view the bell will
appear and nothing will ever arrive. Share → Add to Home Screen, open it from
there, then tap the bell.

## What a notification says

Only what kind of thing happened and where to go — never the message text, the
patient's name or number, a quote amount, or anything clinical. Bodies are
encrypted end to end (RFC 8291) and the push service cannot read them, but the
rule is about the lock screen, not the wire: that screen is often on a phone
lying on a desk in a room with patients in it.

Journey states are filtered to the ones a person must act on (quote accepted,
revision requested, consultation booked, handoff requested, quote awaiting
approval). A journey moves through many states by itself, and buzzing on each
one teaches people to swipe notifications away — after which the ones that
matter are gone too.

## Where things are

| Piece | File |
|---|---|
| VAPID + RFC 8291 encryption + send | `modules/se_core/se_push.php` |
| What gets notified, and to whom | `modules/se_core/se_push_events.php` |
| Manifest, worker, subscribe routes | `modules/se_core/controllers/Se_pwa.php` |
| Service worker | `modules/se_core/views/pwa/service_worker.php` |
| Registration + the bell | `modules/se_core/assets/pwa.js` |
| Mobile layout | `modules/se_core/assets/pwa.css` |
| Subscriptions table (schema v19) | `tblse_push_subscriptions` |

Tests: `modules/se_core/tests/test_push.php`. The encryption tests round-trip
against an independently generated subscription keypair rather than a fixture,
because every failure mode in RFC 8291 is silent — a swapped key order produces
a well-formed request the push service accepts and the browser cannot decrypt.

---

# WhatsApp call logging

The CRM records WhatsApp calls. It does not carry them.

## Why not answer in the browser

Meta's Calling API hands the business an SDP offer and expects it to answer and
then carry DTLS-SRTP media itself. That needs a WebRTC or SIP stack; the
cPanel/LiteSpeed host cannot run one. Accepting a call we would then drop is
worse for a patient than a phone ringing in the WhatsApp Business app, which is
where staff answer today.

What the CRM adds is the record — who called, when, how long, whether anyone
picked up — and a notification when a call is missed. That is the half that
produces the clinic value: the expensive failure is a missed call nobody rings
back, and today that fact exists only inside someone's phone. It reaches no
screen and no report.

Answering inside the CRM stays possible later without touching
`modules/se_whatsapp/calls.php`: it is a media plane behind the same two
webhooks. That needs either a Cloudflare Realtime SFU spike (its interop with
Meta's SDP offer is plausible but undocumented, so it must be proven before it
is promised) or a CPaaS with a monthly bill. Cloudflare covers the signalling
and TURN cleanly; it has no SIP at all.

## Enabling it

1. Turn on calling for the business number in WhatsApp Manager.
2. Subscribe the app to the `calls` webhook field. It arrives on the same
   subscription as messages, so no new endpoint is needed — `se_wa_process_event`
   already routes it.

Nothing else. There is no token to install and nothing to switch on in the CRM:
with the field unsubscribed, no call webhook arrives and the table stays empty.

## The lifecycle

One call is two webhooks — `connect` then `terminate` — and Meta redelivers
both. `call_id` is the unique key: connect inserts, terminate updates, and a
redelivered connect neither duplicates the row nor rings the phone twice. A
terminate that arrives with no preceding connect still records the call, because
Meta can drop the first webhook.

"Answered" is not a field Meta sends. It is derived: a `COMPLETED` status **with
a non-zero duration**. `COMPLETED` with zero duration is a missed call, and a
test plants the version that gets this wrong.
