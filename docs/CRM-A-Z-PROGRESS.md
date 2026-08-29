# SEO Evaluate CRM — A-to-Z Progress Tracker

> Persistent state so any future Claude Code session can resume without rediscovery.
> Update after every phase. Synthetic data only. Stop at external-action gates.
>
> **Environment decision (authoritative).** The **current cPanel hosting with PHP 8.1.34** is the
> **approved environment** for 2–3 internal users and the intended home for that limited internal use.
> The installation is in **pre-live internal use** today and becomes **limited internal production use**
> once the privacy/KVKK gate and the manual UI QA clear. Earlier "staging only" wording means *pre-live*,
> not *temporary*. **VPS migration, PHP 8.3/8.4 adoption and DNS migration are optional future work, not
> go-live requirements**, and have been removed from the owner checklist. **PHP 8.1.34 is the production
> runtime; there is no PHP 8.3 production target.**
>
> **Module version ≠ schema version.** `se_core` module **1.0.0** / schema **v7**; `se_appointments`
> module **1.0.0** / schema **v2**; `se_whatsapp` module **1.0.0**.
>
> **Reviewed final commit:** `c60d39d4fa7c5e16344c2f833bca5a8f7b77c592`.

**Environment:** Perfex 3.4.1 / CI3 / PHP 8.1.34 · docroot `/home/hyundaic/crm.roozbeh.com.tr`
· SSH alias `seoevaluate-crm` (user `hyundaic`) · repo `git@github.com:Roozbehn/seoevaluate-crm.git`
· branch `main` · DB `APP_DB_NAME` (default `utf8mb4 / utf8mb4_unicode_ci`).

**Real data to preserve:** brand `Azin` (id 1); real staff id 1 (admin). All `ZZSEED*` / `zzseed*@example.invalid` are synthetic.

**Off-docroot backups:** `~/_deploy_artifacts/backups/` (DB dump `db_predeploy_20260828_185352.sql` + module tgz + `se_core_predeploy_*` tree).

**Test utilities:** live OUTSIDE docroot in `~/_deploy_artifacts/tests/`; removed before each commit. Never place temp controllers in `application/controllers/`.

---

## Legend
Status: `pending` · `active` · `complete` · `externally gated`

---

## Phase 0 — Stabilization  — **COMPLETE**

| Task | Status | Evidence | Remaining dependency |
|------|--------|----------|----------------------|
| 0.1 Server/repo reconcile | complete | HEAD `e34dd7e`, branch main, PHP 8.1.34; 2 deployed diffs match handoff; off-docroot backups verified | — |
| 0.2 CAPI payload correction | complete | `test_capi_payload.php` 31/0 + `smoke_capi.php` 9/0 vs deployed bytes: action_source=system_generated (never business_messaging/offline, even with ctwa_clid), custom_data event_source=crm + lead_event_source, string lead IDs, 64-char SHA-256 email/phone, raw ctwa_clid, no clinical fields, no HTTP w/o token | — |
| 0.3 Brand-helper fix | complete | `se_staff_brand_ids()` raw query, int-cast, normalized int array, leaves CI builder untouched; regression via live feed/isolation (see 0.4) | — |
| 0.4 Appointment verification | complete | Real authenticated HTTP (forged synthetic sessions, CSRF, origin bypass): CSRF 403 w/o token & 303 accept; scheduled→held→no_show via REAL form + DB re-query; exactly 1 "Consultation Held" outbox row; duplicate save no dup; invalid window (end≤start) rejected; valid window accepted; brand isolation both ways (feed JSON); cross-brand edit + delete denied (row unchanged) — 17 checks | — |
| 0.5 DB charset | complete | Already `utf8mb4/utf8mb4_unicode_ci` (NO ALTER re-run). Round-trip PASS: İstanbul/görüşme/kaş/sağlık/ışıltı byte-exact; probe table inherits utf8mb4_unicode_ci. Note: `tblse_appointments`+`tblse_brands` are `utf8mb4_general_ci` (still utf8mb4, Turkish-safe) — see charset migration note | — |
| 0.6 Controlled cron cycle | complete | `crm-cron.sh` → `/cron/index/<key>` HTTP 200; synthetic pending row stayed pending, attempts 0→1, last_error="no Meta system-user token" (gating, not HTTP), sent_at NULL, no new PHP error → after_cron_run→se_outbox_drain fires, no external transmit | — |
| 0.7 Cleanup + commit | complete | All synthetic torn down (0 rows); git tree = 5 intended files + 2 docs; php -l clean; committed + pushed | commit hash: `cd4a29c0c79a361895642d38e9b41bd10cb4cce8` |

**Phase 0 code delta (committed):**
- `modules/se_core/se_capi.php` — pure `se_capi_build_event()`, action_source always `system_generated` (deployed pre-session; verified).
- `modules/se_core/helpers/se_core_helper.php` — `se_staff_brand_ids()` raw query (no CI builder pollution) (deployed pre-session; verified).
- `modules/se_appointments/models/Se_appointments_model.php` — `invalid_window()` rejects end≤start / unparseable start in add()/update().
- `modules/se_appointments/controllers/Se_appointments.php` — warning alert when a save is rejected.
- `modules/se_appointments/language/english/se_appointments_lang.php` — `se_appt_invalid_window`.
- `docs/CRM-A-Z-PROGRESS.md`, `docs/DB-CHARSET-MIGRATION-NOTE.md`.

**Security item (flag):** `APP_CRON_KEY` was inadvertently surfaced in a session command output during recon. Recommend rotating it (edit `application/config/app-config.php`; the crontab reads it at run time, no crontab change needed). Not rotated autonomously — owner decision.

---

## Current codebase reality (reconciled from live server)

`se_core` already ships batch-2 surfaces (all tracked in `e34dd7e`):
- `se_attribution.php` (155 ln) — **wired** (required in se_core.php). Verify end-to-end in P1.
- `se_outbox.php` (182 ln) — **wired**. Producer `lead_status_changed`; drain `after_cron_run`; table `tblse_conversion_outbox` (has `dedup_key` UNIQUE, attempts, event_time).
- `se_capi.php` (159 ln) — **wired**. Meta CAPI sender, gated on dataset+token.
- `se_google_dm.php` (35 ln) — **wired scaffold**. Sender not implemented (drain holds `google_dm`).
- `se_meta_leadgen.php` (99 ln) — present, **NOT wired** (public webhook, gated on App Review).
- `se_whatsapp.php` (30 ln) — **inert scaffold, NOT wired**.

`se_brands` columns already present: meta_page_id, meta_ad_account_id, meta_dataset_id, whatsapp_waba_id, whatsapp_phone_number_id, google_ads_customer_id, ga4_property_id, gsc_site_url.

Modules registered+active: `se_core` 1.0.0, `se_appointments` 1.0.0. No `uninstall.php` in either.

---

## Phases 1–8 — Roadmap (pending)

| Phase | Branch | Status | Current reality / first action |
|-------|--------|--------|-------------------------------|
| P1 Foundation completeness | feature/crm-foundation | pending | Audit brand_id coverage + reusable scope checks; verify attribution capture is end-to-end vs schema-only (se_attribution wired — confirm first-party capture + web-to-lead + first-touch/dedup); configure shared pipeline stages; harden outbox (dedup/atomic claim/backoff/parking already partly present); patient+consent layer |
| P2 Appointments (extend) | feature/crm-foundation | pending | Add working hours, timezones, types, reschedule, cancel reason, reminders (queued via internal WA iface), status history/audit, Google Calendar sync behind config iface + fixtures |
| P3 WhatsApp inbox | feature/whatsapp-inbox | pending / scaffold | `se_whatsapp.php` is 30-ln inert scaffold → build data model, webhook (verify+signature+raw body+async+routing+dedup), inbox UI, media. Signed fixtures only. GATE: no live route/message |
| P4 Meta Lead Ads + CAPI | feature/meta-leads | pending / partial | `se_meta_leadgen.php` present, not wired → leadgen webhook (verify+sig+bigint-safe+dedup+routing+appsecret_proof), outbound CRM events completeness, per-brand health iface. GATE: 2nd Meta app, App Review |
| P5 Google conversions | feature/google-conversions | pending / scaffold | `se_google_dm.php` 35-ln scaffold → Data Manager API client (NOT deprecated), per-brand mapping, click ids, outbox integration; WA landing-token attribution; fixtures. GATE: cloud creds, live uploads |
| P6 Reporting + health | feature/reporting-hardening | pending | Brand-scoped dashboards + bounded async imports (GA4/GSC/Ads spend/funnel/no-show/WA); integration-health page |
| P7 Operational readiness | — | pending | Author PRODUCTION-MIGRATION / BACKUP-RESTORE / INTEGRATION-SETUP runbooks. *(VPS/PHP 8.3 planning has since been reclassified as optional future work, not a go-live requirement.)* |
| P8 Final QA + report | — | pending | Full matrix + `docs/CRM-A-Z-COMPLETION-REPORT.md` |

## External-action gates (STOP + ask)
2nd Meta app · persistent Meta/Google creds · App Review submit · first real WA message · first real
conversion upload · connect real clinic number · provision production VPS · DNS cutover · import real
patient data · start production traffic.

---

## Phase 1 audit (read-only, recorded pre-build)

- **Attribution** `se_attribution.php` (155 ln): WIRED end-to-end for web-to-lead — hooks
  `app_web_to_lead_form_head`/`web_to_lead_form_start`/`web_to_lead_form_submitted`; first-party
  cookie `se_attr` (90d); last-touch `gclid,gbraid,wbraid,fbclid,utm_*`; first-touch
  `landing_url,referrer,first_touch_at` (never overwritten). All 17 attribution columns exist on
  `tblleads`. Classification: **functional, needs a live browser→form→lead fixture to prove**.
  Gap: `fbc/fbp` derive from pixel (not in web-to-lead capture); `ctwa_clid` arrives via WhatsApp.
- **Pipeline**: `tblleads_status` has only `Customer` (id 1). The 13-stage agency pipeline is NOT
  configured. Concrete P1.3 build task (insert shared `tblleads_status` rows; keep Perfex-reserved
  `Customer`). **Do on feature/crm-foundation as a reproducible patch, not ad-hoc SQL.**
- **brand_id coverage**: present on tblleads, tblclients, tblevents, tblse_appointments,
  tblse_conversion_outbox, tblse_staff_brands, tblweb_to_lead. Future WA/message tables must add it.
- **Outbox hardening**: `tblse_conversion_outbox` has `dedup_key` UNIQUE + `attempts` + stale-parking
  (>7d -> skipped) + bounded retry (<5). Still to audit: atomic claim under overlapping cron.
- **WhatsApp** `se_whatsapp.php` (30 ln): SCAFFOLD ONLY (note fn). Externally gated (Tech Provider).
- **Google DM** `se_google_dm.php` (35 ln): SCAFFOLD; documents correct Data Manager v1 ingest
  contract (not deprecated Ads ConversionUpload). `se_google_dm_send_event()` returns gated error.
- **Meta leadgen** `se_meta_leadgen.php` (99 ln): present, NOT wired (public webhook, gated).

**Next-session first actions (feature/crm-foundation):** (1) live attribution fixture test;
(2) configure 13-stage pipeline as a reproducible module patch; (3) audit outbox atomic-claim under
concurrent cron; (4) scope patient/consent layer (consent_ads+consent_marketing columns already on
tblleads; ledger/versioned-consent-text not yet).

---

## Phase 1 — CRM foundation  — **COMPLETE (merged)**

Branch `feature/crm-foundation` -> merged to `main`. Schema at `se_core_schema_version=4`
(applied via the real admin_init runtime path; idempotent, IF NOT EXISTS DDL + name-guarded seed).

| Task | Status | Evidence |
|------|--------|----------|
| 1.1 Attribution e2e + consent | complete (unit-fixture) | `phase1_attr_test.php` 30/0 vs deployed: first-touch immutable in primary cols; parallel last_* cols never overwrite originals; fbc/fbp (pixel cookies) + ctwa_clid raw; consent_ads/marketing + consent_text_version; consent mirrored to brand-scoped ledger; Turkish intact; 1000-char truncation; missing-consent -> 0/no-grant; NO clinical field. **Live browser->web-to-lead HTTP submission: classified functional-with-fixture (persist path proven); live-form run pending.** |
| 1.2 Pipeline | complete | 13 stages seeded idempotently (order 10-130), `Customer` preserved at 1000; producer emits stage name verbatim; lost/junk leads emit nothing — `phase1_outbox_test.php` 13/0 |
| 1.3 Multi-brand coverage | complete | Indexed `brand_id` on all 11 scoped entities (added missing indexes on outbox + web_to_lead in v4); scope predicate isolates leads/appointments/patients/consent (own=1, foreign=0, admin=both) — `phase1_isolation.php` 23/0. Appointment cross-brand view/edit/delete denial already HTTP-proven in Phase 0. |
| 1.4 Outbox concurrency | complete | Atomic claim via `UPDATE ... status=processing ... ORDER BY id LIMIT` (processing lease + stale recovery + bounded retry + permanent-fail + Meta 7d / Google 6h-90d window validation + redacted errors + `se_outbox_health()`); 2-worker parallel claim proven disjoint (overlap 0, union 40) — event claimed at most once |
| 1.5 Patient + consent | complete (schema+API) | `se_patients`/`se_consent_ledger`/`se_procedure_history`/`se_record_access_log` (all brand_id-indexed, unicode_ci); append-only consent ledger API (`se_consent_record/current/granted`, purpose whitelist); patient upsert + brand-scoped get + access log; clinical data never in payloads. **Patient UI (tabs/forms) classified partial — API + schema done, full CRUD UI pending Phase 2/later.** |

New files: `migrations.php`, `pipeline.php`, `se_patients.php`. Rewritten: `se_attribution.php`, `se_outbox.php`. Wired: `se_core.php`.
Cron regression 200, login page 200, zero new PHP errors. Synthetic residue: 0.

---

## Phase 2 — Complete appointments  — **COMPLETE (merged)**

Branch `feature/appointments-complete` -> merged to `main`. Appt schema `se_appt_schema_version=2`.

| Task | Status | Evidence |
|------|--------|----------|
| 2.1 Appointment model | complete | +appointment_type, consultation_format (online/in_person), cancellation_reason, staff_timezone, reminder_queued; status history table; statuses scheduled/confirmed/held/completed/no_show/cancelled; Booked/Held signals with dedup — HTTP 19/0 |
| 2.2 Availability + timezone | complete | Overlap detection (rejects double-book, ignores self/cancelled/no_show); working-hours windows (outside rejected, inside accepted); Europe/Istanbul storage + tz display conversion (Istanbul->UTC -3, no DST) — unit 13/0 + HTTP |
| 2.3 Reminder framework | complete (queue) | `tblse_reminders` (dedup_key unique, state/attempts/scheduled_at/template_ref/language); `se_reminder_enqueue/cancel_for_appointment/schedule_for`; enqueued on create, cancelled on cancel/no_show, refreshed on reschedule. **No message sent — WhatsApp module (Phase 3) consumes the queue.** |
| 2.4 Google Calendar adapter | functional (fixture); externally gated | Config-driven `se_gcal_sync(create/update/cancel)`; fixture adapter records the op + returns deterministic idempotent event id (stored in google_event_id); `se_gcal_register_adapter()` for the real client. **Live sync gated on Google service account.** |
| 2.5 UI + permissions | partial | Calendar/list/detail/create-edit + lead tab functional (existing views render new data); capability gating view/create/edit/delete + brand scoping enforced (Phase 0/1 proven). **New-field form controls, brand/status/no-show filters and a dedicated patient tab: pending polish.** |

New files: `migrations.php`, `reminders.php`, `availability.php`, `gcal.php`. Rewritten: `models/Se_appointments_model.php`. Wired: `se_appointments.php`.
Cron 200, app 200, zero new PHP errors, synthetic residue 0.

---

## Phase 3 — WhatsApp inbox  — **FUNCTIONAL (fixtures); live Meta externally gated (merged)**

Branch `feature/whatsapp-inbox` -> merged to `main`. New dedicated module `modules/se_whatsapp/`
(6 brand-scoped tables). The old 30-line `se_core/se_whatsapp.php` scaffold is superseded dead code
(not wired) — left in place; may be removed later.

| Task | Status | Evidence |
|------|--------|----------|
| 3.1 Data model | complete | 6 idempotent brand-scoped tables: numbers (unique phone_number_id), conversations (unique number+user), messages (unique wamid), templates (unique brand+name+lang), webhook_events (unique event_hash), metering (unique dedup_ref). No token stored — number references an option key. |
| 3.2 Webhook | functional (fixtures); live route gated | GET verify live-tested (challenge echoed / 403 wrong token). POST: X-Hub-Signature-256 over RAW body verified (unit 6/0), durable dedup store, fast 200 ack, async processing via cron. Public POST route intentionally CSRF-disabled until go-live (documented deploy step + Meta registration = gate). |
| 3.3/3.4 Processing + inbox | functional | Cron-driven pipeline 11/0: tenant routing, wamid dedup (duplicate delivery -> one message), ctwa capture on first inbound, out-of-order status (read kept, older sent ignored), unknown-number parked failed. Inbox UI (list/conversation/filters/lead tab), brand-scoped + escaped; admin route 200; WA brand isolation proven. Media: metadata captured; controlled download deferred (needs live URLs+token) - gated. |
| 3.5 Reply window / templates | functional | 24h window from last inbound; window-open/closed + template-required states surfaced in UI; templates table. Reminder-queue consumer wired to cron, holds (transmits nothing) until a brand can send - the Phase 2 interface seam. |
| 3.6 Metering | complete | Per-brand dedup metering (inbound service + status pricing category/billable); rates configurable via option (not hardcoded). |
| 3.7 Permissions | complete | Separate `se_whatsapp` (view/create/edit/delete) + `se_whatsapp_config` (manage) capabilities; brand scoping via Phase-1-verified se_staff_brand_ids; tokens never shown to staff / never logged. |
| 3.8 Testing | done (fixtures) | Unit 13/0 (signature/routing/window/rate); cron integration 11/0; GET verify live; brand isolation ok. Live message send: externally gated. |
| 3.9 App Review pack | prepared (not submitted) | `docs/WHATSAPP-APP-REVIEW-READINESS.md` |

**Externally gated (owner action):** persistent Meta token, real WABA/number, public webhook subscribe
(+ csrf_exclude_uris deploy step), first real message, App Review submission.

---

## Perfex plugin audit — inventory done; **Dark Theme installed on the cPanel installation**

6 commercial CodeCanyon plugins inventoried (`docs/PERFEX-PLUGIN-AUDIT.md`). Owner approved **Dark Theme** only; installed v1.2.3 (clean review, non-destructive, idempotent, Phase 1-3 intact) — `docs/PERFEX-PLUGIN-IMPLEMENTATION-REPORT.md`. Vendor source deployed but gitignored (repo-storage decision pending per owner). WhatsBot/PRChat rejected (duplicate se_whatsapp). Accounting/Service-Management/Flutex: awaiting owner license confirmation.

---

## Phase 4 — Meta Lead Ads + CAPI  — **FUNCTIONAL (fixtures); live fetch/send externally gated (merged)**

Branch `feature/meta-leads` -> merged to `main`. se_core schema v5 (leadgen tables). Existing `se_meta_leadgen.php`
scaffold rebuilt into a functional pipeline; receiver moved to `controllers/Leadgen.php` (public route `/se_core/leadgen`).

| Task | Status | Evidence |
|------|--------|----------|
| 4.1 Inbound Lead Ads | functional (fixtures); live fetch gated | GET verify live (challenge/403). POST X-Hub-Signature-256 verify + durable dedup store (unique leadgen_id). Big-integer-safe JSON decode (17-digit ids preserved). Page/form routing via `tblse_meta_forms`; per-form field map; consent capture; `meta_lead_id` dedup; appsecret_proof on Graph calls; reconciliation heartbeat. Live `field_data` fetch gated on `leads_retrieval` + Page token -> events HELD (no transmit). Webhook-driven (no polling). Unit 21/0, cron 5/0. |
| 4.2 Outbound CRM events | complete | system_generated / event_source=crm / lead_event_source (Phase 0 verified 31/0); Meta lead id preferred; hashed identifiers; stable vocabulary; per-brand `se_capi_enabled` toggle; event-time/expiry validation (Phase 1 outbox). |
| 4.3 Meta health interface | complete (helper) | `se_meta_health($brand)` returns page/form mapping, dataset, token status + last error, last webhook, last reconcile, outbox pending/failed/sent, feature toggle, externally-gated flag. |
| App Review pack | prepared (not submitted) | `docs/META-LEADADS-APP-REVIEW-READINESS.md` |

New: `controllers/Leadgen.php`. Rewritten: `se_meta_leadgen.php`. Wired in se_core.php; `se_capi.php` toggle; migrations v5
(`tblse_meta_leadgen_events`, `tblse_meta_forms`). Cron 200, app 200, residue 0.

**Externally gated (owner):** 2nd Meta app / ads integration, persistent Page token + app secret, subscribe production
webhook (+ csrf_exclude_uris for se_core/leadgen), App Review submission.

---

## Phase 5 — Google Data Manager conversions  — **FUNCTIONAL (fixtures); live send externally gated (merged)**

Branch `feature/google-conversions` -> merged to `main`. se_core schema v6 (`tblse_gdm_requests`). Rebuilt the
35-line `se_google_dm.php` scaffold into a functional Data Manager v1 sender (verified against current official docs).

| Task | Status | Evidence |
|------|--------|----------|
| 5.1 Data Manager integration | functional (fixtures); live send gated | events:ingest payload (encoding=HEX, destinations.operatingAccount GOOGLE_ADS + productDestinationId, per-event destinationReferences, transactionId, RFC3339-Z eventTimestamp, adIdentifiers gclid/gbraid/wbraid, userData SHA-256-hex email/phone via shared Se_hash, consent GRANTED/DENIED). Conversion-time validation (>=6h,<=90d), <=2000/request batching, per-event isolation, retry/poll (tblse_gdm_requests + requestStatus.retrieve hook), outbox integration. NOT the deprecated Ads ConversionUpload. Unit 33/0; cron gated-hold end-to-end (no external call). |
| 5.2 Ads management/reporting | checklist (Phase 6) | Conversion-action mapping (options); campaign/spend + account-health reporting scoped to Phase 6 dashboards. |
| 5.3 WhatsApp landing-token attribution | complete | `se_landing_token_create/verify/apply_to_lead` — HMAC-signed, time-limited token preserves gclid/gbraid/wbraid across the click-to-WhatsApp hop. Fixture-tested (create/verify/tamper/wrong-secret/expiry/apply). |
| Setup checklist | done | `docs/GOOGLE-DATA-MANAGER-SETUP-CHECKLIST.md` (MCC, Cloud project, service account, Data Manager perms, conversion actions, GA4, Search Console). |

Rewritten `se_google_dm.php` (already wired in se_core batch-2 loader); migrations v6. No clinical data in conversions.
**Externally gated (owner):** Cloud project + service account + credentials, Data Manager permissions, conversion actions, first live upload.

---

## Phase 6 — Reporting + integration health  — **COMPLETE (internal); external imports gated (merged)**

Branch `feature/reporting-hardening` -> merged to `main`. se_core schema v7 (`tblse_ext_metrics`).
Routes: `/admin/se_core/se_reports/index` (dashboard), `/health` (integration health), `/data` + `/health_data` (JSON).

| Task | Status | Evidence |
|------|--------|----------|
| Brand-scoped dashboards | complete (internal) | `se_report_totals/by_stage/by_source/appointments/whatsapp/spend_vs_outcome` — leads/converted/lost/junk + rates, funnel by stage, conversion by source/campaign, appts booked/held/no-show + no-show rate, WhatsApp volume + estimated billing (configurable rates), spend-vs-outcome (cost/lead, cost/treatment). HTTP 21/0. |
| Scheduled external imports | framework done; gated | GA4 / Search Console / Google Ads spend imported ASYNC by cron into `tblse_ext_metrics` (upsert), read-only at render. Importer seam + `se_report_import_all` cron hook; gated (no client -> 0 imported) until credentials. Unit 7/0. **No external HTTP during dashboard render** (internal aggregates + stored metrics only). |
| Integration-health page | complete | `se_integration_health` aggregates meta/google/outbox health + WhatsApp number quality + cron age/health + data-freshness timestamps + external blockers. Renders 200; blockers correctly list gated meta/google. |

New: `se_reporting.php`, `controllers/Se_reports.php`, `views/se_reports_dashboard.php`, `views/se_reports_health.php`.
Wired in se_core (require + sidebar menu + lang); migrations v7. Dashboard/health render 200; residue 0.
**Gated (owner):** GA4 property, Search Console property, Google Ads API access for live spend/metric imports (see Google setup checklist).

---

## Phase 7 — Production readiness  — **PREPARED (docs); nothing executed (merged)**

Branch `feature/production-readiness` -> merged to `main`. Docs only; no code/schema change; no production
mutation, PHP switch, DNS/TLS/host change.

| Task | Status | Evidence |
|------|--------|----------|
| 7.1 Environment & PHP 8.3 compat | **static lint only; NOT a production target** | Env inventory captured. **PHP 8.3.33 + 8.4 static lint of all 64 module files: PASS** — a forward-compatibility data point only. **PHP 8.1.34 is the production runtime**; the 8.3 application runtime has never been verified and no handler switch was performed. Any future upgrade is optional work starting from an isolated compatibility test. |
| 7.2 Deployment & rollback | runbook | `docs/PRODUCTION-READINESS-RUNBOOK.md` (preflight, migration order, module activation order, cache/restart, health verify, code/DB/external rollback). |
| 7.3 Backup & restore | **dump integrity evidence only — restore NOT proven** | Proves exactly three things: dump **completion marker** present, **expected table count** (138 == 138 live base tables), **basic parse/integrity**. Proves **neither a successful restore nor complete application recovery**. Backups `700` off-docroot. Before real patient data: encrypted off-server copies (`rclone`/`age` absent, `gpg` present), DB + `uploads/` + config coverage, an **isolated restore test**, documented retention, backup-age monitoring. `docs/BACKUP-RESTORE-RUNBOOK.md`. |
| 7.4 DNS/TLS/cutover | **optional future work; plan retained, not required** | `docs/DNS-TLS-CUTOVER-ROLLBACK.md` (TTL, cert, HSTS-after-verify, webhook TLS, rollback set). Nothing changed; removed from the owner go-live checklist. |
| 7.5 Monitoring & alerting | runbook | `docs/MONITORING-ALERTING-RUNBOOK.md` (uses existing health helpers; read-only). **Corrected:** alert on the **account quota** (16,362 / 51,200 MB, ~32%; 295,019 / 500,000 inodes), *not* on the shared provider array's 95% — that is a monitored hosting-provider condition, not an account-capacity blocker. |

---

## Phase 8 — Final QA + completion reports  — **COMPLETE (merged)**

Branch `feature/final-qa` -> merged to `main`. No session forging in Phase 8 (per ground rules); regression
via network-free unit suites + DB-level checks + security scans; authenticated-UI items -> manual owner checklist.

**Session provenance:** earlier phases (0, 2–6) drove authenticated HTTP using **temporary synthetic
database sessions** (rows inserted into `tblsessions` for synthetic staff, then deleted — teardown verified
at 0). **Phases 8 and 9 fabricated no sessions at all.** Consequently no authenticated *browser* UI check
has ever been performed by a human, which is why the UI items remain pending.

Re-run: PHP 8.1/8.3/8.4 lint PASS; migration idempotency PASS (no drift); phase4/5/6 unit 21/33/7 = 61/0;
brand isolation PASS; security scans PASS (0 secrets in tracked files, 0 synthetic residue, webhook POST still
CSRF-gated, app-config 600, dark theme untracked, no unlicensed plugin); cron 200, app 200. **Error logs:**
the only content ever produced was PHP 8.3 **CLI lint** warnings, which were **rotated** (not truncated as
application evidence); the **application subsequently generated no new error log**. Reports: `docs/CRM-A-Z-FINAL-REPORT.md`, `docs/FINAL-QA-MATRIX.md`,
`docs/OWNER-GO-LIVE-CHECKLIST.md`. All external ad-platform delivery classified Externally gated; nothing submitted.

---

## Phase 9 — Gap closure (cPanel decision + Patient UI)  — **COMPLETE (merged)**

Branch `feature/patient-ui` -> merged to `main` as **`c60d39d4fa7c5e16344c2f833bca5a8f7b77c592`**.
Infrastructure reconciled: **cPanel + PHP 8.1.34 is the approved environment and production runtime for 2–3
internal users; VPS migration, PHP 8.3/8.4 adoption and DNS migration are optional future work, not go-live
requirements.** Disk: account **16,362 / 51,200 MB (~32%)** and **295,019 / 500,000 inodes (~59%)**; the
shared provider array `/dev/md3` at **95%** is a monitored hosting-provider condition, not an account
blocker. PHP 8.3/8.4 **static syntax lint** PASS (forward-compatibility data point only); 8.3 runtime never
verified (nd_mysqli CLI warning is a CLI extension-config artifact). Error logs: PHP 8.3 CLI lint warnings
rotated (never truncated as app evidence) + gitignored; the application generated no new error log.

**Patient CRUD UI** (`/admin/se_core/se_patients`): reuses `tblse_patients`/`se_consent_ledger`/
`se_record_access_log` (no duplicate model, **no migration** — `retention_state` supports archive). Brand-scoped
list+search+pagination, create/view/edit, **archive/soft-delete** (keeps consent+history), cross-brand ID denial,
Perfex capabilities (view/create/edit/delete), CSRF, lead/client/appointment links, consent + audit history,
server-side validation, Turkish/Unicode, minimal personal data. **Conversion-data prohibition proven** (patient/
clinical data cannot enter CAPI/Google payloads — builders are lead-only). Tests: unit **19/0** + DB **7/0** = **26/0**.
**Classification: *Functional with fixtures — automated model/DB tests passed; authenticated UI and
permission QA pending*.** Authenticated visual CRUD **and authorization QA** (capability denial, cross-brand
id denial) remain on the owner manual checklist (`docs/MANUAL-UI-CHECKLIST.md`). It is **not** end-to-end
verified. Dark Theme is likewise ***Installed and functionally activated — visual/responsive QA pending***.

---

## Phase 10 — Documentation correction + independent-review packaging — **COMPLETE**

Documentation-only phase at `c60d39d4fa7c5e16344c2f833bca5a8f7b77c592`. **No live-integration code was
modified and no external integration was activated.**

| Task | Status | Outcome |
|------|--------|---------|
| 10.1 Resolve documentation contradictions | complete | Unified environment banner across 6 runbooks/reports; cPanel + PHP 8.1.34 recorded as the approved environment **and production runtime**; VPS / PHP 8.3 / DNS removed from the immediate owner checklist and moved to *Optional future work*; module versions separated from schema versions; shared-array 95% separated from account quota; installation terminology unified on *pre-live internal use → limited internal production use*. |
| 10.2 Webhook latency | complete (documented, **cron unchanged**) | Cron is `3-59/15` (every 15 min); the Perfex 300 s throttle is a floor between executions and never blocks a run. **Effective worst case ≈ 15 min, not 5.** Recorded as a blocker before live WhatsApp/Lead Ads, with two cPanel-compatible options (5-min cron + matching throttle; **preferred:** separate 1-min lightweight webhook processor). Receiver already verifies → enqueues → 200 with no inline Meta/Google work. |
| 10.3 Credential audit (no values read or printed) | complete | **All** ad-platform secrets are designed as **plaintext `tbloptions`** rows; **zero encryption** anywhere in the custom modules. **No credential option is currently populated** (only 7 `se_*` rows exist: 2 schema versions, 1 reconcile timestamp, 4 duplicate `se_wa_rates_json`). Config files verified **mode 600 owner-only**. Health endpoints and logs expose **booleans only** — no secret leaks. **Live integration BLOCKED**; nothing migrated or deleted. |
| 10.4 Google Data Manager authentication | complete (finding) | `se_gdm_access_token()` returns a **static bearer token** from `se_google_sa_token_<brand>` and sends it as `Authorization: Bearer`. No service-account JWT, no ADC, no refresh — Google tokens expire ~hourly. **Must be rebuilt on service-account credentials or ADC**; store only the renewal reference. **BLOCKED.** |
| 10.5 Attribution snapshot verification | complete (defect) | First-touch immutable ✅; last-touch parallel columns ✅; `event_id`/`transactionId` stable across retries ✅; `event_time`/`event_name` snapshotted ✅; last-touch bleed not currently occurring ✅. **DEFECT:** both senders re-read `tblleads` at drain time, so consent state, `consent_text_version` and identifiers are rebuilt from the lead's **current** row. The outbox `payload` snapshot column is always written `[]` and never read (0 of 0 rows populated). Additive idempotent schema-v8 snapshot migration **proposed, not applied**. |
| 10.6 Rotated-log exposure | complete (**fixed — authorized relocation**) | `error_log.claude-php83lint-20260829_093518` was **inside the document root**, mode `644`, and served **HTTP 200** with its full 2,200-byte body. **Moved** (not deleted) to `~/_evidence/logs/`, mode `600`, dir `700`; sha256 identical before/after; old URL now **404**. A second `error_log` generated during this audit was relocated the same way. Document root now holds no `error_log*`. Owner action outstanding: set PHP's `error_log` path outside the docroot. |
| 10.7 Backup claims corrected | complete | Reclassified as **dump integrity evidence only** — completion marker, expected table count (138 == 138), basic parse. Explicitly **not** proof of restore or application recovery. Five prerequisites recorded before real patient data. **No new dump and no isolated database created.** |
| 10.8 Privacy/KVKK launch gate | complete (gate opened) | 10-item owner/legal gate added (controller, clarification notice, lawful grounds, consent wording/versions, minimize nationality/passport, retention & deletion, staff roles/need-to-know, correction/export/deletion procedures, breach response, international transfer to Meta/Google/backup storage). **No legal compliance is claimed; requires qualified Turkish privacy counsel.** |
| 10.9 Source-review package | complete | `CRM-CODE-REVIEW-PACKAGE.zip` built from **tracked Git files at the reviewed commit** (`git archive`), containing `modules/se_core`, `modules/se_appointments`, `modules/se_whatsapp`, `.gitignore` and `CODE-REVIEW-MANIFEST.md`. Excludes `app-config.php`, all credentials/env files, unmodified Perfex core, `modules/perfex_dark_theme/`, uploads, databases/dumps, backups, logs, caches, sessions, test data, third-party commercial archives and Git metadata. Secret-pattern scan run over the final archive. |

**Not done by design in Phase 10:** cron unchanged; no option migrated or deleted; no credential added; no
live-integration code modified; no external integration activated; no new dump or isolated DB; no claim of
independent code approval.

---

## Phase 13 — Integration, test-tier and UI closure — **COMPLETE**

Branch `feature/final-integration-test-closure`. Schema **v11**.

| Task | Outcome |
|------|---------|
| 13.1 Appointment backend re-verification | Closed the partial-update bypass (`{rel_id}` alone skipped the entire link check; `{end_at}` alone skipped the window check). `GET_LOCK`'s result is now honoured — it was ignored, so a timed-out lock looked identical to a held one and the double-booking guard silently vanished. Required fields on create. Unmapped staff are no longer treated as unrestricted. |
| 13.2 Real MariaDB test tier | New tier executing the REAL model classes against the live schema in a rolled-back transaction, with a network-kill fixture. 86 assertions. Found the clock skew below. |
| 13.3 PHP/MariaDB clock skew | **Real production bug.** Rows written with SQL `NOW()` were compared against PHP `date()`; PHP runs UTC, MariaDB on system time — a measured two-hour offset. Retries fired three hours late; dead-worker leases took 2h15m to recover. `se_db_now()` now supplies the database clock everywhere. |
| 13.4 Meta Lead Ads backend | Atomic claim, worker identity, lease, stale recovery, fence, backoff with jitter, rate-limit handling. `held` events auto-resume instead of being stranded forever. Token moved to the Authorization header. Lead status/source configured instead of 0. |
| 13.5 Webhook receivers | Bounded reads with 413; 200 now means durably accepted — a failed insert previously returned 200 and Meta never redelivered, losing the lead silently. |
| 13.6 Conversion outbox | Snapshot bound to the supplied brand; producer refuses missing/cross-brand leads; fail closed on payload_version 0 (the live-lead fallback is gone); unconditional consent recheck before transport; missing Google mapping is gated configuration. |
| 13.7 WhatsApp outbound | New queue with idempotency, claim/lease/fence/backoff, 24-hour window enforced at queue AND send time, approved-template-only outside it, reminder consumption that marks before queueing. Gate is checked before the window — conflating them permanently discarded gated messages. |
| 13.8 WhatsApp UI | Real inbox list, threaded conversation, composer whose control the server chooses, template selector, disabled composer with an explicit reason, readiness screen. |
| 13.9 Google | Renewable credential-provider abstraction with a signer seam (JWT/OAuth deliberately not hand-rolled), token cache, async `requestStatus` lifecycle with confirmed/partial/failed, sanitized diagnostics. Six-hour age rule removed as unverified. Landing tokens brand/audience/version bound and first-touch preserving. |
| 13.10 HTTP test tier | 49 assertions against the deployed app: verification, signatures, size bounds, route authorization, GET-on-mutation, harness and log inaccessibility. |
| 13.11 Mobile verification | Real media-query rendering at 390 and 768 across 12 page/width combinations; 0 horizontal overflow. Screenshots captured. |

**Tiers:** fake DB 1146/0 · real MariaDB 86/0 · HTTP 49/0.

**Not done, and reported as not done:** Meta reconciliation; Google JWT signing
(needs `google/auth`); WhatsApp live transport. All three are surfaced in the UI
as "Not implemented" rather than implied to work.

