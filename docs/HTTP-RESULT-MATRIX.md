# HTTP Result Matrix (webhook functionality)

Real requests to the deployed controllers on the origin. Meta and WhatsApp are
reported **separately**. Every webhook response carries an `X-SE-Webhook` marker
header; a response WITHOUT it is Perfex's CSRF page and is treated as a **FAIL**,
so these rows prove the controller executed — not that some middleware answered.
`run_http.php` total: **146 / 0**. Zero outbound calls; guaranteed fixture +
secret-file cleanup; 138 non-session tables restored to the pre-run snapshot.

Secrets for the run were synthetic per-run random FILES for `meta_app`,
`meta_verify`, `wa_app`, `wa_verify` only — no Page/CAPI token, so every
live-send path stayed gated.

## Meta Lead Ads webhook (`/se_core/leadgen`)
| Case | HTTP | Marker | Reason | Row evidence |
|------|-----:|--------|--------|--------------|
| Verification GET, valid token | 200 | leadgen | — | body === challenge |
| Verification GET, invalid token | 403 | leadgen | verify_failed | challenge not echoed |
| POST, missing signature | 401 | leadgen | bad_signature | no row |
| POST, invalid signature | 401 | leadgen | bad_signature | no row |
| POST, byte modified after signing | 401 | leadgen | bad_signature | no row |
| POST, oversized (limit+1, valid sig) | 413 | leadgen | payload_too_large | no row |
| POST, malformed JSON (valid sig) | 400 | leadgen | malformed_json | no row |
| POST, valid signed | 200 | leadgen | accepted | 1 durable row |
| POST, duplicate delivery | 200 | leadgen | duplicate | still 1 row |
| PUT / DELETE | 405 | leadgen | method_not_allowed | Allow: GET, POST |
| POST, unknown page/form mapping | 200 | leadgen | accepted | stored; parked unmapped; 0 leads |
| Cross-brand routing conflict | — | — | brand_mismatch | lead brand unchanged; no 2nd lead |
| POST, storage failure (table renamed) | 500 | — | — | no row; table restored, identical count |

## WhatsApp webhook (`/se_whatsapp/webhook`)
| Case | HTTP | Marker | Reason | Row evidence |
|------|-----:|--------|--------|--------------|
| Verification GET, valid token | 200 | whatsapp | — | body === challenge |
| Verification GET, invalid token | 403 | whatsapp | verify_failed | challenge not echoed |
| POST, missing signature | 401 | whatsapp | bad_signature | no row |
| POST, invalid signature | 401 | whatsapp | bad_signature | no row |
| POST, byte modified after signing | 401 | whatsapp | bad_signature | no row |
| POST, oversized (limit+1, valid sig) | 413 | whatsapp | payload_too_large | no row |
| POST, malformed JSON (valid sig) | 400 | whatsapp | malformed_json | no row |
| POST, valid signed message | 200 | whatsapp | accepted | 1 durable row, async parse |
| POST, duplicate delivery | 200 | whatsapp | duplicate | still 1 row |
| PUT / DELETE | 405 | whatsapp | method_not_allowed | Allow: GET, POST |
| POST, valid status callback | 200 | whatsapp | accepted | sent→delivered, brand unchanged |
| POST, cross-brand status callback | 200 | whatsapp | accepted | stored; NO transition; no cross-brand write |
| POST, unknown phone_number_id | 200 | whatsapp | accepted | stored; parked; 0 operational rows |
| POST, storage failure (table renamed) | 500 | — | — | no row; table restored |

The codebase acks durably first and routes/parses in cron; the tier drives the
same `se_wa_process_event` / `se_leadgen_process_event` in-process for determinism.
A duplicate is a genuine acceptance (the row is already held). 200 means durably
stored — a failed insert returns 500 so the platform retries.

## Route / method / CSRF sub-suite
- Normal admin POST without a token → 403 (419 for XHR), **no marker** — CSRF still enforced everywhere except the two exact webhook URIs.
- Both `/admin/...` webhook aliases and both `/index` variants → still CSRF-blocked (no marker).
- Unauthenticated admin GET → 302 to `/admin/authentication`.
- GET on a mutation route → never 200. PUT/DELETE on the webhooks → 405.
- The test harness and every log/secret path (`/error_log`, `/application/logs/...`, `/_evidence/...`, `/_w3/...`, `/_secrets/...`) → 403/404, not web-reachable.

## Storage-failure mechanism
`RENAME TABLE ..._events TO ..._zzbak` immediately before one signed POST, restored
in `finally` + a shutdown handler + `--cleanup` recovery, with a guard that refuses
to open the window inside the live cron's firing seconds. Result: 500 (never 2xx),
no row, table restored with the identical pre-failure count. This is the HTTP tier
(a separate CLI process from the app); the transactional tiers still run zero DDL.
