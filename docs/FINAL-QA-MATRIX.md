# Final QA Matrix

Re-verified on staging at `main` (Phase 8). Network-free unit suites and DB-level checks were re-run
without forging sessions; authenticated-UI items are listed as **manual QA pending** (owner). Per-phase
HTTP integration results (which used short-lived synthetic staff sessions, torn down) are cited as
in-phase evidence and were NOT re-run in Phase 8 to respect the no-session-forging rule.

## Re-run in Phase 8 (this session)
| Check | Result |
|-------|--------|
| PHP 8.1 lint (all modules) | PASS |
| PHP 8.3.33 + 8.4 static lint (64 files) | PASS |
| Migration idempotency (reset+re-apply; IF NOT EXISTS) | PASS — schema v7, 138 tables, no drift |
| Meta Lead Ads unit (`phase4_unit`) | 21/0 |
| Google Data Manager unit (`phase5_unit`) | 33/0 |
| Reporting importer unit (`phase6_unit`) | 7/0 |
| Brand isolation (scope predicate, DB) | PASS (own=1, foreign=0, admin=2) |
| Security scans (secrets/residue/CSRF/perms/plugins) | PASS |
| Cron + app smoke | cron 200, login 200 |
| Error-log regression | 0 app errors (baseline restored; app log empty) |

## Component classification (exactly one status each)
| Component | Status | Evidence |
|-----------|--------|----------|
| Brand isolation & authorization | Live and end-to-end verified | HTTP cross-brand deny (P0/P2) + DB scope re-verified (P8) |
| Attribution first/last-touch + fbc/fbp/ctwa | Functional with fixtures | unit 30/0; live web-to-lead form run pending |
| Consent ledger | Functional with fixtures | unit + isolation |
| Patient records (schema + API) | Functional with fixtures | tables + API + access log; brand-scoped |
| Patient CRUD UI | Scaffold/partial | API done; full UI pending |
| Pipeline (13 stages) | Live and end-to-end verified | seeded live; producer + lost/junk gating 13/0 |
| Conversion outbox (dedup/atomic-claim/lease/retry/windows) | Functional with fixtures | 2-worker disjoint; live send gated |
| Appointments (lifecycle/overlap/hours/tz/reschedule/cancel/no-show/history/reminder-dedup) | Live and end-to-end verified | real edit-form HTTP 19/0 (in-phase) + model unit 13/0 |
| Appointment reminder queue | Functional with fixtures | enqueue/cancel/reschedule proven; consumer gated on WhatsApp |
| Google Calendar sync | Externally gated (fixture-only) | fixture adapter idempotent; live needs service account |
| WhatsApp processing (signature/routing/wamid-dedup/status-order/unknown-parking/metering) | Functional with fixtures | unit 13/0 + cron 11/0 |
| WhatsApp inbox UI + brand scoping | Functional with fixtures | routes render; isolation verified; visual = manual pending |
| WhatsApp live Meta connection + public POST | Externally gated | token/number/webhook subscribe + csrf exception (owner) |
| Meta Lead Ads inbound (webhook/bigint/dedup/routing/mapping/consent) | Functional with fixtures | unit 21/0 + cron 5/0; GET verify live |
| Meta Lead Ads live ingestion | Externally gated | leads_retrieval Advanced Access + Page token |
| Meta CAPI payload + sender | Functional with fixtures | 31/0 payload + 9/0 gating |
| Meta CAPI live transmission | Externally gated | dataset + system-user token |
| Google Data Manager sender | Functional with fixtures | unit 33/0; cron gated-hold |
| Google Data Manager live delivery | Externally gated | Cloud service account + conversion actions |
| WhatsApp landing-token attribution | Functional with fixtures | create/verify/tamper/expiry/apply |
| Reporting internal metrics + dashboard + health routes | Live and end-to-end verified | JSON endpoints 21/0; render 200 |
| Reporting external imports (GA4/GSC/Ads) | Externally gated | credentials required; importer framework fixture 7/0 |
| Dark Theme (activation, non-destructive) | Live and end-to-end verified | activated via Perfex route; idempotent; visual = manual pending |
| Cron + outbox drain wiring | Live and end-to-end verified | after_cron_run drains; gated holds; no external call |
| DNS/TLS/VPS production cutover | Externally gated (prepared, not executed) | runbooks only |
| App Review submissions (Meta) | Externally gated (not submitted) | readiness packs prepared |

## Manual QA pending (owner; requires a real authenticated login — no session was forged)
- Visual dark-mode rendering across key admin screens.
- Reporting dashboard + integration-health page visual review (`/admin/se_core/se_reports/index` and `/health`).
- WhatsApp inbox + conversation views; appointment create/edit forms (new fields + filters).
- Lead profile: WhatsApp + appointment tabs render.
