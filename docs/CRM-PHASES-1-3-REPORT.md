# SEO Evaluate CRM — Phases 1–3 Completion Report

Staging: `https://crm.roozbeh.com.tr` · Perfex 3.4.1 / CI3 / PHP 8.1.34 · MariaDB 10.11.18 · branch `main`.
Synthetic data only. No real patient data used. This report covers the immediate corrections plus
Phases 1 (foundation), 2 (appointments) and 3 (WhatsApp inbox).

## 1. Executive verdict
Corrections done; Phases 1 and 2 are **functional and end-to-end tested**; Phase 3 is **functional with
signed fixtures**, with the live Meta connection **externally gated**. All work is committed on feature
branches, merged to `main`, pushed to `origin/main`, and the staging app loads with zero new PHP errors.
Nothing is misrepresented: scaffolds/gated items are labelled as such in §29.

## 2. Cron-key rotation
`APP_CRON_KEY rotated and verified.` Config file rewritten atomically (perms `600` preserved), key never
printed/logged/committed; wrapper cron returns **200**, unauthenticated cron returns **401**.

## 3. Documentation redaction
The real DB name was replaced across all tracked docs with `APP_DB_NAME` / "the configured CRM database".
`git grep` confirms the real name no longer appears in any tracked file. Live DB config unchanged.

## 4. Git state & hashes
`main` in sync with `origin/main`; working tree clean. Key commits (oldest→newest):
- `c632ac0` Rotate cron access key and redact environment details
- `5af074d` Phase 1: CRM foundation → merge `a4f250d`
- `707142d` Phase 2: complete appointments → merge `92f6a02`
- `e1d1f53` Phase 3: WhatsApp inbox → merge `d41cd96` (current HEAD)

## 5. Files added/modified
- **se_core:** `migrations.php`(new), `pipeline.php`(new), `se_patients.php`(new); rewritten
  `se_attribution.php`, `se_outbox.php`; wired `se_core.php`.
- **se_appointments:** `migrations.php`,`reminders.php`,`availability.php`,`gcal.php`(new); rewritten
  `models/Se_appointments_model.php`; wired `se_appointments.php`.
- **se_whatsapp (new module):** `se_whatsapp.php`,`install.php`,`helpers.php`,
  `controllers/Webhook.php`,`controllers/Se_whatsapp.php`,`models/Se_whatsapp_model.php`,
  `views/inbox.php`,`views/conversation.php`,`language/english/se_whatsapp_lang.php`.
- **docs:** `CRM-A-Z-PROGRESS.md`, `DB-CHARSET-MIGRATION-NOTE.md`, `WHATSAPP-APP-REVIEW-READINESS.md`,
  this report.

## 6. Schema changes (idempotent migrations; `IF NOT EXISTS`)
- `tblleads`: last-touch cols (`last_gclid/gbraid/wbraid/fbclid/utm_*`,`last_touch_at`),`consent_text_version`.
- `tblse_conversion_outbox`: `locked_at`,`locked_by`,index `claim`; added missing `brand_id` index.
- `tblweb_to_lead`: added missing `brand_id` index.
- New (se_core, brand-scoped, unicode_ci): `tblse_patients`,`tblse_consent_ledger`,`tblse_procedure_history`,`tblse_record_access_log`.
- `tblse_appointments`: `appointment_type`,`consultation_format`,`cancellation_reason`,`staff_timezone`,`reminder_queued`.
- New (se_appointments): `tblse_appointment_status_history`,`tblse_working_hours`,`tblse_reminders`.
- New (se_whatsapp): `tblse_wa_numbers`,`tblse_wa_conversations`,`tblse_wa_messages`,`tblse_wa_templates`,`tblse_wa_webhook_events`,`tblse_wa_metering`.
- Schema versions: `se_core_schema_version=4`, `se_appt_schema_version=2` (applied via real admin_init runtime path).
- `tblleads_status`: 13 pipeline stages seeded (Customer preserved).

## 7. Attribution behavior & evidence
First-touch is immutable in the primary columns; last-touch is a parallel `last_*` set that never
overwrites the original. Captures gclid/gbraid/wbraid/fbclid/utm_* (first+last), fbc/fbp (pixel cookies),
ctwa_clid (raw), landing_url/referrer/first_touch_at; consent_ads/marketing + consent_text_version mirrored
to the brand-scoped consent ledger; brand resolved from the form; Turkish text intact; 1000-char truncation;
missing consent → 0 + no ledger grant; no clinical field ever. **Evidence:** `phase1_attr_test.php` 30/0 vs
deployed bytes. **Gap:** live browser→web-to-lead HTTP submission is fixture-verified on the persist path;
a full live-form run is pending.

## 8. Pipeline stages & transition evidence
13-stage shared pipeline (New→…→Reviewed) seeded idempotently, order 10–130, reserved `Customer` at 1000.
Each eligible transition emits the stage name verbatim as one consent-gated outbox event per destination;
lost/junk leads emit nothing. **Evidence:** `phase1_outbox_test.php` 13/0.

## 9. Brand-separation evidence
Indexed `brand_id` on all 11 scoped entities; scope predicate isolates leads/appointments/patients/consent/
WhatsApp conversations (own=1, foreign=0, admin=both). Appointment cross-brand view/edit/delete denial is
HTTP-proven (Phase 0/2). **Evidence:** `phase1_isolation.php` 23/0 + WA isolation ok + Phase 2 HTTP 19/0.

## 10. Outbox concurrency evidence
Atomic claim (`UPDATE … status=processing … ORDER BY id LIMIT`) + processing lease + stale recovery +
bounded retry + permanent-fail + Meta 7-day / Google 6h–90d window validation + redacted errors +
`se_outbox_health()`. **Evidence:** 2 parallel workers claimed 40 rows disjointly (overlap 0, union 40) —
an event is claimed at most once.

## 11. Patient/consent implementation
`se_patients` (brand-scoped, preferred_language/nationality/passport/retention/deletion), append-only
`se_consent_ledger` (purpose whitelist ads/marketing/whatsapp; granted/withdrawn; version; source),
`se_procedure_history` (clinical, never leaves CRM), `se_record_access_log`. API: `se_consent_record/
current/granted`, `se_patient_upsert/get` (brand-scoped, access-logged). **UI:** API+schema complete;
full patient CRUD UI pending.

## 12. Appointment lifecycle
Statuses scheduled/confirmed/held/completed/no_show/cancelled; status history recorded on every change;
reschedule, cancel-with-reason, no-show; Booked/Held conversion signals with dedup on repeated saves;
start/end window validation. **Evidence:** `appt_phase2_http.php` 19/0 (real edit form + DB re-query).

## 13. Working-hours & timezone results
Overlap detection rejects double-booking (ignores self/cancelled/no_show); working-hours windows enforced
(outside rejected, inside accepted); Europe/Istanbul storage with tz display conversion (Istanbul→UTC −3,
no DST). **Evidence:** `phase2_unit.php` 13/0 + HTTP overlap/working-hours checks.

## 14. Reminder queue
`tblse_reminders` (unique dedup_key, state/attempts/scheduled_at/template_ref/language). Enqueued on create
(24h-before, configurable), cancelled on cancel/no_show, refreshed on reschedule. **No message sent** — it
is an internal interface the WhatsApp module consumes.

## 15. Google Calendar adapter status
Config-driven `se_gcal_sync(create/update/cancel)` with an idempotent **fixture adapter** (deterministic
event id stored in `google_event_id`); `se_gcal_register_adapter()` for the real client. **Externally gated**
on a Google service account. **Evidence:** fixture idempotency in `phase2_unit.php`.

## 16. WhatsApp tables & routes
6 brand-scoped tables (see §6). Routes: public `se_whatsapp/webhook` (GET verify + POST receiver), admin
`se_whatsapp/inbox` + `conversation/{id}` + `assign/{id}`. No token stored in tables (option-key reference).

## 17. Webhook fixture results
`phase3_unit.php` 13/0 — X-Hub-Signature-256 verify (valid/tampered/wrong-secret/malformed/empty), routing
extraction, reply-window, configurable rate. `phase3_integration.php` (cron-driven) 11/0 — tenant routing,
wamid dedup, ctwa capture, out-of-order status, metering, unknown-number parked. GET verification live-tested
(challenge echoed / 403 wrong token). Live POST route intentionally CSRF-disabled until go-live.

## 18. Inbox functionality
Brand-scoped conversation list (all/assigned-to-me/unassigned filters), conversation detail (thread,
delivery/read states, reply-window state), staff assignment, lead-profile conversation tab. All output
escaped. Admin route returns 200; brand isolation verified.

## 19. Message/media handling
Text stored + escaped; delivery/read/failed states with out-of-order protection. Media: metadata captured as
`media:<id>`; **controlled download deferred** (post-validation, outside executable paths, authorized serve)
— needs live media URLs + token, gated.

## 20. Template/reply-window behavior
24h window from last inbound drives free-form vs template-required state (surfaced in UI); templates table
with approval/quality/variables. Template send is gated on a live token.

## 21. Message metering
Per-brand dedup metering: inbound (service) + status pricing (category/billable). Rates configurable via
`se_wa_rates_json` option — pricing is NOT hardcoded. Dedup proven.

## 22. Appointment-reminder integration
Phase 2 `se_reminders` queue is consumed by se_whatsapp's cron hook (`se_wa_consume_due_reminders`). With no
brand able to send (current state), due reminders are held pending — nothing transmitted. This is the exact
seam the reminder interface was built for.

## 23. App Review package status
Prepared, **not submitted** — `docs/WHATSAPP-APP-REVIEW-READINESS.md` (permissions, reviewer workflow,
demo/screencast script, privacy/deletion prerequisites, exact gated steps). Existing app
`SEO Evaluate CRM` (2296795344499663); no second app created.

## 24. PHP lint & log results
Every changed/added PHP file passes `php -l`. After full cron + app smoke, the application error log is
**0 bytes** and no per-request log files were created.

## 25. Synthetic-data cleanup counts
Final sweep = **0** for: ZZ brands, ZZ leads, zz@example.invalid staff, forged sessions, ZZ appointments,
outbox rows, reminders, WhatsApp conversations, WhatsApp webhook events. Real brand `Azin` preserved.
Off-docroot test scripts removed (kept read-only `q.php`/`dbdump.php` + backups).

## 26. External actions performed
None beyond rotating the cron key (owner-authorized correction). No Meta/Google calls, no messages, no
conversions, no external accounts/credentials created.

## 27. External actions NOT performed (gates respected)
No 2nd Meta app, no persistent Meta/Google credentials, no App Review submission, no real WhatsApp message,
no real conversion upload, no real number connected, no public webhook subscribed, no VPS/DNS/production.

## 28. Remaining blockers
Live Meta onboarding (token/number/webhook subscribe + `csrf_exclude_uris` deploy step); Google service
account + conversion actions; live web-to-lead form run; full patient CRUD UI; media download path.

## 29. Honest status classification
- **Functional & end-to-end tested:** brand scoping/isolation; conversion outbox (dedup/atomic-claim/parking/
  validation); appointment lifecycle + availability + timezones + reminders queue; pipeline; CAPI payload.
- **Functional with fixtures; external test pending:** attribution persist (live form pending); WhatsApp
  webhook POST + processing + metering; Google Calendar sync (fixture).
- **Externally gated:** WhatsApp outbound send + templates + media download; Meta Lead Ads live; Google Data
  Manager live; live web verification of the public webhook.
- **Partial:** patient CRUD UI; appointment UI new-field controls/advanced filters; WhatsApp media download.
- **Scaffold only / superseded:** old `se_core/se_whatsapp.php` (30-line note; superseded by the module).
- **Missing (future phases):** Phase 4 Meta Lead Ads wiring, Phase 5 Google Data Manager sender, Phase 6
  reporting dashboards, Phase 7 production runbooks.

## 30. Items requiring ChatGPT review
- First-touch-immutable vs last-touch column split — confirm this matches the intended attribution model.
- Outbox atomic-claim under MariaDB `UPDATE … ORDER BY … LIMIT` — validate the isolation assumption at
  higher concurrency than the 2-worker test.
- Reminder default (24h before) + WhatsApp 24h window + (from 1 Oct 2026) billable service replies — confirm
  business rules.
- WhatsApp webhook CSRF-exclusion as the go-live mechanism — confirm acceptable vs a dedicated public route.
- Cron throttle (300s) means webhook processing latency up to ~5 min — confirm acceptable or shorten.
- Patient/passport/nationality fields — confirm KVKK/GDPR justification before real data.

## 31. Exact recommended next action
1. Decide the WhatsApp go-live path (owner): provision Meta token + test number → I add the documented
   `csrf_exclude_uris` entry and subscribe the webhook (at the gate) to prove POST end-to-end.
2. Then proceed to **Phase 4 (Meta Lead Ads + CAPI)** on `feature/meta-leads`, and the queued
   **Perfex plugin audit** (its preconditions are now met).

**Confirmations:** No real patient data was used. No secrets, keys, tokens, backups, or temporary test
scripts were committed to git.
