# CRM Master Execution Backlog

One row per implementation unit. Columns: **Objective · Source IDs · Files · Deps · Steps · DB · Security · Responsive · A11y · Tests · Visual verification · Acceptance · Rollback · Status**. Status values: PLANNED · IMPLEMENTING · IMPLEMENTED · VERIFIED · BLOCKED-DECISION · NOT-APPLICABLE-WITH-EVIDENCE. Status here mirrors `traceability_src.py` (`STATUS` map) — that file is authoritative.

## WS0
- **CRM-M001 Baseline & strategy** — Objective: branch `feat/master-optimization` from `79783a6`, record test baseline, write traceability/conflicts/plan/backlog. Sources: program §2–§7. Files: docs/strategy/*. Deps: —. DB: none. Tests: full suite (3 824 pass / 0 fail at start). Acceptance: four docs exist; baseline recorded in CRM-WAVE-VERIFICATION. Rollback: n/a.

## WS1 — Security & silent failures
- **CRM-M002 Appointment reminder consumer** — Objective: reminders queued by the appointment module actually send. Sources: AZCRM-WA-003, T1/F1, K2, QW1. Files: `modules/se_whatsapp/outbound.php` (`se_wa_consume_due_reminders`), `modules/se_appointments/models/Se_appointments_model.php`, `modules/se_appointments/reminders.php`, `modules/se_core/tests/test_appointments.php`. Deps: —. Steps: load appointment by `appointment_id`; resolve template `eyebrow_consultation_reminder_tr` (existing, approved) with variables name/date/time; pass `template_ref` from enqueue; mark reminder `sent/failed` with reason. DB: none. Security: none. Tests: consumer test (appointment + approved template ⇒ one `se_wa_outbound` row; cancelled ⇒ none). Acceptance: F1 acceptance. Rollback: revert commit.
- **CRM-M003 Calendar assets** — Objective: calendar renders. Sources: AZCRM-AP-001, UX-A01, T4/F4, QW2, DS 2.15. Files: `modules/se_appointments/views/calendar.php`, `controllers/Se_appointments.php` (feed adds `type`, `className`). Steps: load Perfex's bundled FullCalendar css/js via `app_css/app_script` (as core Calendar does); type classes; visible alert when library missing. Visual: live month view. Acceptance: `.fc-view` present; events coloured by type. Rollback: revert.
- **CRM-M004 CSRF gateways anchor** — Sources: AZCRM-SEC-001, T5/F5, C1, K8, QW5. Files: `application/config/config.php`. Steps: replace substring test with anchored segment check on the path only (`preg_match('#^/?(index\.php/)?gateways/#', parse_url(REQUEST_URI, PHP_URL_PATH))`). Tests: `test_csrf.php` (function extracted to `se_core_helper`?) — the check is exercised by a pure function `se_csrf_gateways_exempt($uri)` added in se_core and used by config. Acceptance: `?x=gateways/` not exempt; `/gateways/…` exempt. Rollback: revert.
- **CRM-M005 PWA CSRF + unsubscribe ownership** — Sources: AZCRM-SEC-002, T6/F6, L4, DiD-2. Files: `modules/se_core/assets/pwa.js`, `controllers/Se_pwa.php`. Steps: send `csrfData` token as form field with the JSON payload in `payload`; unsubscribe deletes only rows with `staff_id = current`. Tests: controller helper test for ownership filter. Acceptance: F6 acceptance. Rollback: revert.
- **CRM-M006 Pause opt-in + Resume in thread** — Sources: AZCRM-WF-001, UX-W05, T8/F8, QW4. Files: `modules/se_whatsapp/outbound.php:423`, `modules/se_whatsapp/controllers/Se_whatsapp.php`, `modules/se_journey/ui.php`, `modules/se_core/se_chat_ui.php`. Steps: `se_journey_note_staff_reply` only pauses when `pause_automation=1` posted; composer checkbox/⏸ toggle (default off); thread panel shows Resume when paused (posts to existing `Se_journey::action resume`). Tests: reply without flag keeps `active`; with flag → `paused_staff`. Acceptance: F8. Rollback: revert.
- **CRM-M007 Reminder scan pagination** — Sources: AZCRM-PJ-002, T13/F13, K4, QW8. Files: `modules/se_journey/messaging.php:643`. Steps: `WHERE automation_state='active' AND state IN (plan states)` before LIMIT; iterate in pages of 200. Tests: 150 journeys, #150 gets its reminder. Rollback: revert.
- **CRM-M008 Health honesty** — Sources: AZCRM-OBS-001, T19/F19, K10, QW6, UX-D03. Files: `modules/se_core/se_reporting.php`, `views/se_reports_health.php`, `se_journey/health.php`. Steps: count outbox `skipped` by `error_code`; add dispatcher age (`se_dispatch` heartbeat option), WA outbound queue counts (pending/failed), `se_reminders` failed count; drop `dead`; card turns amber when skipped>0 or dispatcher age > 5 min. Tests: `test_health.php` (new) with seeded rows. Visual: live Health page. Acceptance: F2(b), F19. Rollback: revert.
- **CRM-M009 Consent banner brand fix** — Sources: T18/F18, UX-D04, QW7, AZCRM-UX-005 (part). Files: `modules/se_core/se_outbox_ui.php:250`. Steps: check brands visible to the staff member. Tests: brand 22 configured ⇒ no warning. Rollback: revert.
- **CRM-M010 CAPI consent mapping (decision-gated)** — Sources: AZCRM-PJ-001, T2/F2, K7. Files: `modules/se_journey/intake.php`, `modules/se_core/se_consent.php`, `se_reporting.php`, `views/settings.php`. Steps: brand option `se_consent_ads_from_intake_<brand>` default 0; when 1, `se_journey_record_consent` grants/withdraws `ads` alongside `marketing`; Health card explains the blocked state while 0. Tests: both branches. Status: BLOCKED-DECISION (engineering done; flip needs owner/lawyer). Rollback: option off.
- **CRM-M011 Deny docs/ services/ .env.example** — Sources: AZCRM-SEC-003, T16/F16, C2, K8. Files: `.htaccess`, deploy notes. Steps: `RedirectMatch 404` rules; document deploy exclude. Tests: HTTP tier (host). Acceptance: `curl -I /docs/` → 404 on host. Rollback: revert.
- **CRM-M012 Small security hardenings** — Sources: AZCRM-SEC-007/008, C3, L10, DiD-1,3,4,6. Files: `Se_journey.php` (assign brand check), `application/controllers/Cron.php` (hash_equals), `se_webhook_state.php` (self-test gated), `se_journey/media.php` (decode cap 4000), `Se_journey.php:552` (base URL host pin), `se_media.php`/`se_media_storage.php` (host allowlist), `se_push.php` (push host allowlist). Tests: assign rejects foreign staff; helper tests for allowlists. Rollback: revert.
- **CRM-M013 Duplicate-envelope guard order** — Sources: AZCRM-WA-005, T15/F15, K3. Files: `modules/se_whatsapp/helpers.php:468-486`. Steps: check wamid before touching the conversation row. Tests: second envelope leaves unread/window unchanged. Rollback: revert.
- **CRM-M014 Cron listener isolation** — Sources: AZCRM-OBS-004. Files: `modules/se_core/se_core.php` (after_cron_run wrapper). Steps: try/catch per listener, log class name. Tests: a throwing listener does not stop the next. Rollback: revert.

## WS2 — Shared UX foundation
- **CRM-M015 se-ds.css** — Sources: UX-F01, DS §1–§4, T3 (CSS part), T9, T17, MOB-002/004, A11Y-001/003, NAV03, X01, X05, UIUX-OPT §N/§K. Files: new `modules/se_core/assets/se-ds.css` (from `docs/design/mockups/azin-ds.css`), `modules/se_core/se_clinic.php` (load via `app_admin_head`, `body.se-clinic` class), delete inline CSS in `se_chat_ui.php` and `se_reports_health.php`, retire layout rules in `pwa.css`. Steps: copy tokens/components; add `body.se-clinic` overrides (`.text-muted` colour, header icons ≤990 px, focus-visible); light-theme tokens under `body.light`/`[data-theme=light]`. Responsive: all breakpoints. A11y: contrast/focus/44 px. Tests: file exists; grep no `<style>` in SE views (except intake public). Visual: probe on live pages. Rollback: option `se_clinic_ds=0` skips the load. 
- **CRM-M016 se_ui_* helpers** — Sources: UX-F02, DS 2.1/2.2/2.3/2.7/2.17, UIUX-OPT §Q, UX-COPY §3. Files: `modules/se_core/helpers/se_ui_helper.php`, lang files, `tests/test_ui_helper.php`. Steps: `se_ui_btn`, `se_ui_badge`, `se_ui_state_badge` (31-state map → label/stage/colour), `se_ui_stage_of`, `se_ui_stages`, `se_ui_attention_row`, `se_ui_alert`, `se_ui_empty`, `se_ui_age`, `se_ui_patient_header`, `se_ui_next_action`. Tests: every state maps; helpers escape. Rollback: revert.
- **CRM-M017 Next-action engine** — Sources: UX-F03, UX-QA03, UX-COPY §4, T7 (thresholds), UIUX-OPT §A.4. Files: new `modules/se_journey/next_action.php`, `tests/test_next_action.php`. Steps: `se_journey_next_action($j, $ctx)` → `{key,sentence,reason,age,priority,action_label,url,owner}` table-driven from UX-COPY §4; owner=staff|patient|none. Tests: every state × timing → documented sentence. Rollback: revert.
- **CRM-M018 Turkish copy + CI grep** — Sources: UX-F04, UX-QA02, AZCRM-UX-004, AZCRM-WA-006, UX-W08 (strings), T11, J20, App.1, UX-COPY §1,§6,§7,§8. Files: lang files (tr/en) of se_core/se_journey/se_whatsapp/se_appointments, `se_journey/helpers.php`, `review.php`, `aftercare.php`, `intake.php` (task titles → `_l`), `Se_journey.php:649-666` (event labels), `se_outbound_tracker.php`, new `scripts/check-copy.sh`. Steps: apply §8 rewrites; `se_journey_event_label()`; grep gate for forbidden titles and English task literals. Tests: grep gate in `run.php` pre-step. Rollback: revert.
- **CRM-M019 Phone formatter** — Sources: UX-F05, AZCRM-UX-008, UX-COPY §9. Files: `se_ui_helper.php`, tests. Steps: `se_ui_phone($raw,$mask)` E.164 → `+90 5xx xxx xx xx` / masked; `<bdi dir="ltr">`. Tests: unit. Rollback: revert.
- **CRM-M020 lang/dir attributes** — Sources: UX-X03, A11Y-002 (lang), UX-COPY §10, UIUX-OPT §M. Files: `se_clinic.php` head hook. Steps: set `<html lang dir>` from staff language via JS-free head hook (`app_admin_head` injects `document.documentElement.lang/dir` fallback + CSS `[dir=rtl]`). Rollback: revert.

## WS3 — Navigation & shell
- **CRM-M021 Navigation IA v2** — Sources: UX-NAV01, UX-L02, UIUX-OPT §B/§C, T20, UX-COPY §2. Files: `modules/se_core/se_navigation.php`, `se_clinic.php`. Steps: groups Operasyon/Yönetim; items Bugün/Hastalar/Mesajlar/Randevular; Yönetim: Raporlar (owner/admin), Entegrasyonlar (admin), Ayarlar (admin: Rıza metinleri, Süreç ayarları, Şablonlar, Flows, Perfex kurulumu/kayıtları); hide Leads/Customers/Patients/Patient journeys/Instagram top-level for clinic roles; option `se_clinic_nav_v2`. Visual: sidebar per role. Rollback: option off.
- **CRM-M022 Mobile tab bar** — Sources: UX-NAV02, DS 2.16, UIUX-OPT §C. Files: `se_clinic.php` (markup via `app_admin_footer`), `se-ds.css`. Steps: 5 items with counts; hidden in thread. Visual: 390. Rollback: css.

## WS4 — Bugün
- **CRM-M023 Bugün dashboard** — Sources: UX-D01/D02/D03, AZCRM-UX-001, AZCRM-UX-005, AZCRM-OBS-002, UIUX-OPT §D, DS 2.3/2.7, UX-COPY §4/§5. Files: `modules/se_core/controllers/Se_dashboard.php`, `views/se_dashboard.php`, `modules/se_journey/health.php` (queue builder `se_journey_attention_queue`), `se_outbox_ui.php` (counters removed). Steps: queue = next_action over active journeys (owner=staff) + urgent/failed/paused/unread; sort priority then age; cap 25; right column; option `se_clinic_dashboard_v2`. Tests: queue ordering fixture. Visual: 1440/768/390. Acceptance: §D. Rollback: option off.

## WS5 — Hastalar
- **CRM-M024 Unified Hastalar list** — Sources: UX-L01, AZCRM-UX-002, AZCRM-UX-007, T10, UIUX-OPT §E, DS 2.9/2.10. Files: new `modules/se_core/controllers/Se_hastalar.php`, `models/Se_hastalar_model.php`, `views/se_hastalar.php`, tests. Steps: query leads ⟕ journeys ⟕ latest conversation ⟕ next appointment; chips/selects/search (name or digits, normalised); 25/page; row action from next_action. DB: reads only. Responsive: `hide-m`. Tests: model filter/search. Visual: 1440/390. Rollback: menu points back to old lists.

## WS6 — Patient workspace
- **CRM-M025 Patient header, stage bar, next panel, alerts** — Sources: UX-P01/P02/P05, AZCRM-UX-003, UIUX-OPT §F, DS 2.4–2.7, UX-COPY §3.5. Files: `modules/se_journey/views/view.php`, `ui.php`, `Se_journey.php`. Tests: header renders masked phone for Sales. Visual: 1440/390. Rollback: revert view.
- **CRM-M026 Human timeline** — Sources: UX-P04, UX-COPY §6, DS 2.13, UIUX-OPT §F timeline rules. Files: `Se_journey.php:649-666`, `view.php`, `next_action.php` (`se_journey_event_label`). Tests: label map. Rollback: revert.
- **CRM-M027 Tabs (Genel/Sohbet/…/Dosyalar, KVKK tab)** — Sources: UX-P03, UX-L02 (KVKK envelope as tab), UIUX-OPT §F tabs, DS 2.12. Files: `view.php`, `Se_journey.php`, `se_chat_ui.php` (embed). Tests: reply from Sohbet tab posts to existing reply route. Rollback: revert.
- **CRM-M028 Internal notes** — UX-P06. Files: `Se_journey.php` action `note`, `view.php`. PLANNED.
- **CRM-M029 Photo one-step acceptance** — UX-P07, AZCRM-WF-007. Deps: Flow JSON at Meta. PLANNED.
- **CRM-M030 Reopen buttons** — UX-P08, AZCRM-WF-006. PLANNED.
- **CRM-M031 Perfex Leads cleanup + pipeline trim (admins)** — UX-L03, AZCRM-UX-006, AZCRM-MOB-003, T20. Deps: owner confirms stages. PLANNED.

## WS7 — Mesajlar
- **CRM-M032 Mobile thread & composer** — Sources: UX-W01, AZCRM-WA-001, AZCRM-MOB-001, T3/F3, QW3, DS 2.14/2.16, UIUX-OPT §G phone, §J. Files: `se_chat_ui.php`, `se-ds.css`, `se_whatsapp/views/conversation.php`. Steps: `.se-comp-row` nowrap, ⏸ icon toggle ≤767, sticky composer, thread fills viewport, tab bar hidden. Tests: Playwright 390 (textarea ≥60 %, composer visible, 44 px). Rollback: css.
- **CRM-M033 Context strip + sheet** — UX-W02, UIUX-OPT §G phone, DS 2.18. Files: `se_journey/ui.php`, `se_chat_ui.php`, `se-ds.css`. Visual: 390.
- **CRM-M034 Inbox query** — UX-W04, AZCRM-WA-002, AZCRM-PERF-002, I.unbounded. Files: `Se_whatsapp_model.php`. Steps: join lead name, last message preview, journey state, unread; LIMIT/cursor; search; thread LIMIT 100 + "load older". Tests: model. 
- **CRM-M035 Three-column inbox** — UX-W03, AZCRM-WA-002, UIUX-OPT §G desktop/tablet. Files: `views/inbox.php`+`conversation.php` merged view, `Se_whatsapp.php`. Visual: 1440/1024/768.
- **CRM-M036 Contextual actions by state** — UX-W06, AZCRM-WA-004. Files: `se_journey/ui.php` (uses next_action + state map). Tests: state → buttons.
- **CRM-M037 Auto-message styling + tracker TR collapsed** — UX-W07/W08, AZCRM-WA-006, UX-COPY §7. Files: `se_chat_ui.php`, `se_outbound_tracker.php`.
- **CRM-M038 Instagram as tab** — UX-W09. PLANNED.

## WS8 — Randevular
- **CRM-M039 Appointment form v2 + type + conflict copy** — UX-A03, AZCRM-AP-002, UIUX-OPT §H form, UX-COPY §3.4/§5 errors, DS 2.11. Files: `views/form.php`, `controllers/Se_appointments.php`, `models/Se_appointments_model.php`. Steps: type chips → duration; date+time+duration; patient search; exact conflict message + next free slot; notification checkboxes. Tests: conflict message; next slot. Visual: 1024/390.
- **CRM-M040 Mobile agenda** — UX-A02, DS 2.15. Files: `views/calendar.php`, `se-ds.css`. Visual: 390.
- **CRM-M041 Same-day "Bugün işlem planla"** — UX-A04, AZCRM-WF-004 (UI part). Files: `se_journey/views/view.php`, `consultation.php`, `Se_appointments.php` (prefill params). Tests: prefill payload.
- **CRM-M042 List with names + type** — UX-A05. Files: `views/manage.php`.
- **CRM-M043 Appointment cards on patient page** — UX-A06, AZCRM-AP-004. Files: `view.php`.
- **CRM-M044 Reschedule confirmation salt** — AZCRM-AP-003. Files: `consultation.php:107`. Tests: rescheduled appointment queues a new confirmation.

## WS9 — Quote
- **CRM-M048 Quote expiry + statuses** — UX-Q02, AZCRM-WF-005, conflict #10. Files: `review.php`, `helpers.php` (state `quote_expired`), timers. Tests: expiry transition.
- **CRM-M049 Quote tab layout + Sales read-only** — UX-Q01/Q03, UIUX-OPT §I. Files: `view.php`, capabilities in `helpers.php`. Tests: capability.

## WS10 — Timers
- **CRM-M045 Staff timers** — AZCRM-WF-002, T7/F7, OBS-002, UX-COPY §4 thresholds. Files: new `modules/se_journey/timers.php` + cron hook. Steps: dedup'd tasks + push at thresholds; consultation T-24 h reminder via existing approved template; option `se_journey_timers`. Tests: each threshold once.
- **CRM-M046 Aftercare auto-start** — AZCRM-WF-003. Deps: protocol approved flag. PLANNED.
- **CRM-M047 Consultation auto-held** — AZCRM-WF-004 (automation part). PLANNED.

## WS11 — Identity & data
- **CRM-M050 phone_e164** — AZCRM-PJ-003, T12, K6. Migration + ingest paths. PLANNED (migration needs backup window).
- **CRM-M052 waba_id on conversations** — AZCRM-ARCH-004, T14, J12. PLANNED.
- **CRM-M056 Long-term follow-up** — AZCRM-PJ-005. PLANNED.
- **CRM-M057 KVKK erasure** — AZCRM-PJ-006, L9, J18. BLOCKED on retention periods. PLANNED.

## WS12 — Integrations & observability
- **CRM-M051 Lead Ads → dispatcher + lead_created** — AZCRM-PJ-004, J15. PLANNED.
- **CRM-M061 crm-media Worker fail-closed** — AZCRM-SEC-004, L7. Files: `services/crm-media/src/index.js`.
- **CRM-M062 Host constants & real IP** — AZCRM-SEC-005, SEC-001 (host part), L5, L6. Host check + `proxy_ips`. PLANNED (host).
- **CRM-M073 Webhook exception message** — AZCRM-OBS-003. PLANNED.
- **CRM-M074 External uptime check** — AZCRM-OBS-005. PLANNED (external).

## WS13 — Performance
- **CRM-M053 Indexes** — AZCRM-PERF-001, I.idx. Migration v21 (additive). PLANNED until backup window.
- **CRM-M054 Push fan-out to dispatcher; batch custom fields** — AZCRM-PERF-003, I.push. PLANNED.
- **CRM-M055 Media steps conditional + lease** — AZCRM-PERF-004, ARCH-001 (lease), J9, K5. PLANNED.

## WS14 — A11y / RTL
- **CRM-M063 Names, labels, skip link** — UX-X02, AZCRM-A11Y-002, UIUX-OPT §K. Files: `se_clinic.php`, `se_chat_ui.php`, forms.
- **CRM-M064 RTL logical properties** — UX-X04, AZCRM-A11Y-004, UIUX-OPT §M. Files: `se-ds.css`, SE views.

## WS15 — Architecture
- **CRM-M058 wamid before finalize** — ARCH-005, J11. PLANNED. **CRM-M059 turquai-bridge** — ARCH-006. BLOCKED-DECISION. **CRM-M060 Secret fallbacks** — SEC-006, L8. PLANNED. **CRM-M065 SeQueue** — ARCH-001, J13, J17. PLANNED. **CRM-M066 Registry** — ARCH-002. PLANNED. **CRM-M067 Split helpers** — ARCH-003. PLANNED.

## WS16 — QA
- **CRM-M068 Docs refresh + AppleDouble + host module check** — QA-005, J19, DiD-7. PLANNED. **CRM-M069 Schema oracle** — QA-001, K1. PLANNED. **CRM-M070 Unit tests K.2–K.10** — QA-002 (delivered inside M002/M007/M008/M013/M010/M004). **CRM-M071 Playwright responsive** — QA-003, UX-QA01, K9. Files: `scripts/ui-regression/`. **CRM-M072 Sandbox E2E** — QA-004. PLANNED. **CRM-M075 Global search ⌘K** — UX-NAV04. PLANNED.
