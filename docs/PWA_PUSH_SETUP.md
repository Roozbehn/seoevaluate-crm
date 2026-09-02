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
