# Integration Status Matrix

Every row states exactly what exists. "Not built" means deliberately absent and
reported as absent in the UI — never a silent gap.

## Meta Lead Ads

| Item | State |
|------|-------|
| Signed webhook receiver (HMAC over raw body) | backend, HTTP-tested |
| Raw-body size bound (64 KB) + 413 | backend, HTTP-tested |
| 200 means durably accepted (500 on storage failure) | backend |
| page_id **+** form_id routing, ambiguity refused | backend, fake-DB |
| Field-map allowlist (contact columns only) | backend, fake-DB |
| Cross-brand `meta_lead_id` parked, never re-tenanted | backend, fake-DB |
| Atomic claim / worker id / lease / stale recovery / fence | backend |
| Retry, exponential backoff with jitter | backend |
| Rate limiting (429/613) keeps the attempt budget | backend |
| `held` events auto-resume once configured | backend |
| Configured default lead status/source (never 0) | backend + UI |
| Token in Authorization header, never the URL | backend |
| `appsecret_proof` on Graph calls | backend |
| Sanitized provider errors | backend |
| Queue counters, mapping view, requeue, setup checklist | UI, browser |
| Bounded reconciliation | **not built** — UI reports "Not implemented" |
| Live connection | **gated** — App Review + CSRF exclusion + token |

## Conversion Outbox

| Item | State |
|------|-------|
| Immutable event snapshot at queue time | backend, fake-DB |
| Snapshot lead must match the supplied brand | backend, fake-DB |
| Producer refuses missing/cross-brand leads | backend, fake-DB |
| Fail closed on payload_version 0 (no live-lead fallback) | backend, fake-DB |
| Authoritative consent recheck immediately before transport | backend, fake-DB |
| Consent-blocked rows can never be requeued | backend + UI |
| Stable event IDs across retries | backend, fake-DB |
| gated / retryable / permanent classification | backend, fake-DB |
| Gated failures consume no attempt | backend, fake-DB |
| Backoff with jitter, next_attempt_at | backend, real-DB |
| Fencing against stale-worker finalization | backend, **real-DB** |
| Disjoint claims across two connections | **real-DB** |
| At-least-once delivery, documented | docs |
| Monitor UI: filters, counters, safe detail, requeue | UI, browser |

## WhatsApp

| Item | State |
|------|-------|
| Signature verification over the exact raw body | backend, HTTP-tested |
| Body bound (128 KB) + 413 | backend, HTTP-tested |
| 200 means durably accepted | backend |
| Unique `event_hash` race handled | backend, **real-DB** |
| phone_number_id → brand routing | backend, fake-DB |
| Conversation brand-mismatch refused | backend, fake-DB |
| Claim / lease / fence / stale recovery / backoff | backend, **real-DB** |
| Status callbacks bound to the routed brand | backend, fake-DB |
| Raw payload retention purge (30 d, hash kept) | backend, fake-DB |
| Assignment restricted to same-brand staff, POST+CSRF | backend, fake-DB |
| **Outbound queue** (idempotency, claim/lease/fence/backoff) | backend, fake-DB |
| 24-hour window enforced at queue **and** send time | backend, fake-DB |
| Approved-template-only outside the window | backend, fake-DB |
| Reminder consumption, mark-before-queue | backend, fake-DB |
| Media allowlist (type + size), references only | backend, fake-DB |
| Inbox, conversation, composer, template selector, readiness | UI, browser |
| **Live sending** | **not built by design** — no transport is registered; every send is gated and held |
| Live connection | **gated** — App Review, number, CSRF exclusion |

## Google Data Manager

| Item | State |
|------|-------|
| Credential read from 0600 file outside the docroot | backend, fake-DB |
| Service-account document validation | backend, fake-DB |
| Short-lived token cache + refresh-before-expiry | backend, fake-DB |
| Renewable-token **abstraction** and signer seam | backend, fake-DB |
| JWT signing / OAuth exchange | **not built** — needs `google/auth`; no bespoke crypto |
| Ingest transport (fixtureable) | backend, fake-DB |
| `requestStatus` polling (fixtureable) | backend, fake-DB |
| submitted → confirmed / partial / failed lifecycle | backend, fake-DB |
| Partial failure reported honestly, never guessed | backend, fake-DB |
| Sanitized diagnostics | backend, fake-DB |
| Six-hour age rule | **removed** — was unverified; now configurable, default off |
| Landing tokens: version, audience, brand, iat, size bound | backend, fake-DB |
| Landing token cannot overwrite first-touch | backend, fake-DB |
| No clinical/patient field in any payload | backend, fake-DB |
| Status UI, mapping editor, owner checklist | UI, browser |
| Live delivery | **gated** — Cloud project, service account, `google/auth` |

## Secrets

| Item | State |
|------|-------|
| Filesystem provider, dir 700 / file 600, outside docroot | backend, fake-DB |
| Path from an untracked `app-config.php` constant | backend |
| No setter, no secret-editing UI | by design |
| Status screen: booleans, mode, expiry, last error only | UI, browser |
| No plaintext secret in `tbloptions` | verified — 0 SE credential options exist |
