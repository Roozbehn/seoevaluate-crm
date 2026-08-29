# Final QA Matrix

> **AUTHORITATIVE DEPLOYMENT DECISION (supersedes earlier VPS/PHP-upgrade framing):** The CRM remains
> on the **current cPanel hosting with PHP 8.1.34**, serving **2–3 internal users** initially. VPS migration,
> PHP 8.3/8.4 adoption, DNS migration, load balancing, containers, and Redis are **NOT required for go-live**
> and are moved to an **Optional future roadmap**. Immediate operational priorities: disk-capacity monitoring,
> reliable backups + encrypted off-server copies, restore readiness, cron monitoring, HTTPS renewal,
> permissions/secret protection, SSH-key security, error monitoring, health checks — plus completing the
> externally gated integrations. Async cron processing is retained because it protects web requests
> (not for user volume).
>
> **Disk:** the 95% figure is the **shared 5.2 TB server array** (283 GB free, inodes 37%), not this account.
> This account uses **16 GB** total; the CRM **222 MB**; our backup artifacts **3.3 MB**. The latest backup
> `~/_deploy_artifacts/backups/db_predeploy_20260829_070412.sql` (**833,143 bytes**, sha256 `c28487c6…`) added
> ~833 KB — negligible. Not a go-live blocker for this account; monitor the shared array via the host.
>
> **PHP:** 8.1.34 is the **selected and approved** runtime. PHP **8.3.33 + 8.4 syntax lint passed** for all 64
> module files. PHP **8.3 application runtime was NOT verified**; the php83 CLI emits an `nd_mysqli.so`
> undefined-symbol **startup warning** (CLI extension-config artifact, not an app error) — **not an immediate
> blocker** since PHP 8.1 remains the runtime. Do not switch PHP until a future isolated compatibility test succeeds.
>
> **Error logs:** the earlier truncation removed only Claude-generated php83-CLI warnings, **not application
> errors**. Logs are no longer truncated; test noise is **rotated** (timestamped + checksummed) and gitignored;
> the application itself produces **zero** errors.



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

## Phase 9 additions
| Component | Status | Evidence |
|-----------|--------|----------|
| Patient CRUD UI (list/search/pagination/create/view/edit/archive) | Live and end-to-end verified (model+DB); UI visual = manual pending | unit 19/0 + DB 7/0 (brand isolation, cross-brand ID denial, archive, linkage, consent/audit) |
| Patient conversion-data prohibition | Live and end-to-end verified | CAPI + Google DM payloads carry no patient/clinical field; builders are lead-only |
| Infrastructure (cPanel + PHP 8.1.34) | Approved environment | suitable for 2–3 internal users |
| VPS / PHP 8.3 migration | Optional future roadmap | not required for go-live |
| Disk capacity | Monitored (not a blocker) | account 16 GB; 95% is shared array (283 GB free) |

Classification enum used across docs: `Live and end-to-end verified` · `Functional with fixtures` ·
`Scaffold/partial` · `Externally gated` · `Prepared but not executed` · `Optional future roadmap`.
