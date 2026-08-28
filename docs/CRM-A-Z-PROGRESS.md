# SEO Evaluate CRM — A-to-Z Progress Tracker

> Persistent state so any future Claude Code session can resume without rediscovery.
> Update after every phase. Staging only. Synthetic data only. Stop at external-action gates.

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
| P7 Operational readiness | — | pending | Author PRODUCTION-MIGRATION / BACKUP-RESTORE / INTEGRATION-SETUP runbooks; VPS/PHP 8.3 plan (prepare, do not execute) |
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
