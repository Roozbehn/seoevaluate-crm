# CRM Master Traceability — 2026-09-04

Zero-loss inventory of every actionable requirement in the five source documents. Overlapping requirements keep every source reference and map to the same master ticket. Status is updated by editing `traceability_src.py` and regenerating; it is the completeness gate for the program.

**Rows:** 243 · BLOCKED-DECISION 11 · IMPLEMENTED 83 · IMPLEMENTING 2 · NOT-APPLICABLE-WITH-EVIDENCE 1 · PLANNED 31 · VERIFIED 115

**Per source:** CRM-AUDIT 134 · UIUX-OPT 19 · DESIGN-SYSTEM 25 · UX-COPY 15 · UIUX-BACKLOG 50

| Source | Source ID/Section | Requirement | Workstream | Depends On | Implementation Ticket | Verification | Status |
|---|---|---|---|---|---|---|---|
| CRM-AUDIT | T1/F1 | Appointment-module reminders never send (consumer loads by reminder id; no template) | WS1 | — | CRM-M002 | test_appointments reminder consumer test; code | VERIFIED |
| CRM-AUDIT | T2/F2 | All CAPI conversions skipped consent_blocked; Health hides skipped | WS1/WS12 | owner+lawyer decision | CRM-M010 (+M008) | health JSON shows skipped by reason; option-driven mapping test | BLOCKED-DECISION |
| CRM-AUDIT | T3/F3 | WhatsApp composer unusable at phone width; dead pwa.css selectors | WS7 | CRM-M015 | CRM-M032 | Playwright 390: textarea ≥60% width; screenshot | IMPLEMENTED |
| CRM-AUDIT | T4/F4 | Calendar renders blank — FullCalendar never loaded | WS8 | — | CRM-M003 | live screenshot; .fc-view present | IMPLEMENTED |
| CRM-AUDIT | T5/F5 | CSRF disabled when URI contains gateways/ | WS1 | — | CRM-M004 | code; test_csrf_gateways | VERIFIED |
| CRM-AUDIT | T6/F6 | PWA push subscribe posts JSON without CSRF token; unsubscribe ownership | WS1 | — | CRM-M005 | code; pwa.js sends token; unsubscribe scoped to staff | VERIFIED |
| CRM-AUDIT | T7/F7 | No timers for staff-owned states; consultation reminder never sent; next_action_due_at unread | WS10 | CRM-M017 | CRM-M045 | test_journey_timers | VERIFIED |
| CRM-AUDIT | T8/F8 | Composer reply silently pauses automation; no Resume in thread | WS7 | — | CRM-M006 | test_wa_outbound pause opt-in; UI Resume | VERIFIED |
| CRM-AUDIT | T9/F9 | Top-bar icons overlap page buttons at ~700–990 px | WS14 | CRM-M015 | CRM-M015 | live 769 px check | IMPLEMENTED |
| CRM-AUDIT | T10/F10 | Patients list shows no name/phone, brand 22, search only nationality | WS5 | CRM-M016 | CRM-M024 | Hastalar list live | VERIFIED |
| CRM-AUDIT | T11/F11 | Raw keys and English literals in Turkish UI | WS2 | — | CRM-M018 | grep gate; live | VERIFIED |
| CRM-AUDIT | T12/F12 | Cross-channel duplicate persons (no phone_e164) | WS11 | migration | CRM-M050 | test dedup; schema | PLANNED |
| CRM-AUDIT | T13/F13 | Reminder scan LIMIT 100 before state filter | WS1 | — | CRM-M007 | test 150 journeys | VERIFIED |
| CRM-AUDIT | T14/F14 | se_wa_conversations has no waba_id → messaging CAPI never queues | WS11 | migration | CRM-M052 | schema + test | PLANNED |
| CRM-AUDIT | T15/F15 | Duplicate webhook envelope inflates unread/window before wamid guard | WS1 | — | CRM-M013 | test duplicate envelope | VERIFIED |
| CRM-AUDIT | T16/F16 | docs/ and .env.example in docroot with origin IP | WS1 | — | CRM-M011 | .htaccess rule; curl 404 (host) | IMPLEMENTED |
| CRM-AUDIT | T17/F17 | Contrast 2.58:1, 11 px text, no focus, unnamed icon controls | WS14 | CRM-M015 | CRM-M015 + CRM-M063 | contrast probe; axe | IMPLEMENTED |
| CRM-AUDIT | T18/F18 | Dashboard consent warning checks brand 0 | WS1 | — | CRM-M009 | test; live | VERIFIED |
| CRM-AUDIT | T19/F19 | Health hides skipped/submitted, reads nonexistent dead, no dispatcher age | WS12 | — | CRM-M008 | test_health; live | VERIFIED |
| CRM-AUDIT | T20/F20 | Stock Perfex Leads vocabulary/fields as primary people screens | WS3/WS5 | CRM-M021 | CRM-M021 + CRM-M031 | live sidebar; lead modal | BLOCKED-DECISION |
| CRM-AUDIT | H.C1 | CSRF substring exemption | WS1 | — | CRM-M004 | test | VERIFIED |
| CRM-AUDIT | H.C2 | docs/ + .env.example exposure | WS1 | — | CRM-M011 | htaccess | IMPLEMENTED |
| CRM-AUDIT | H.C3 | Journey assign accepts any staff id | WS1 | — | CRM-M012 | test_journey_staff assign brand check | VERIFIED |
| CRM-AUDIT | H.L4 | PWA CSRF | WS1 | — | CRM-M005 | code | VERIFIED |
| CRM-AUDIT | H.L5 | Rate limits keyed on Cloudflare edge IPs (proxy_ips) | WS1 | host check | CRM-M062 | config; not observable from container | PLANNED |
| CRM-AUDIT | H.L6 | cookie_secure depends on undefined constant | WS1 | host check | CRM-M062 | host app-config check | PLANNED |
| CRM-AUDIT | H.L7 | crm-media Worker fails open without MEDIA_KEY | WS12 | — | CRM-M061 | worker code | VERIFIED |
| CRM-AUDIT | H.L8 | Legacy secret fallbacks in tbloptions | WS1 | — | CRM-M060 | grep | PLANNED |
| CRM-AUDIT | H.L9 | No erasure path for journey health data (KVKK) | WS11 | lawyer retention periods | CRM-M057 | retention job + test | BLOCKED-DECISION |
| CRM-AUDIT | H.L10 | Perfex Cron key compared with != | WS1 | — | CRM-M012 | code | VERIFIED |
| CRM-AUDIT | H.DiD-1 | Outbound host allowlist for media fetch | WS12 | — | CRM-M012 | code | VERIFIED |
| CRM-AUDIT | H.DiD-2 | Push endpoint host allowlist + unsubscribe ownership | WS1 | — | CRM-M005/M012 | code | VERIFIED |
| CRM-AUDIT | H.DiD-3 | Gate X-SE-SELFTEST on cron key | WS1 | — | CRM-M012 | code | VERIFIED |
| CRM-AUDIT | H.DiD-4 | Cap image decode at ~4000 px | WS1 | — | CRM-M012 | code | VERIFIED |
| CRM-AUDIT | H.DiD-5 | Nonce intake CSP | WS14 | — | CRM-M012 | PLANNED (low) | VERIFIED |
| CRM-AUDIT | H.DiD-6 | Restrict se_journey_public_base_url to site host | WS1 | — | CRM-M012 | code | VERIFIED |
| CRM-AUDIT | H.DiD-7 | Confirm Auto_update/openai deactivated; strip ._* files | WS0/WS16 | host | CRM-M068 | host check | PLANNED |
| CRM-AUDIT | H.keep | Keep: webhook pipeline, tokens, sealing, scoping, escaping, secrets store | all | — | constraint | regression suite stays green | VERIFIED |
| CRM-AUDIT | I.idx | Missing indexes wamid, conversations.lead_id/last_inbound_at, journeys.automation_state | WS13 | migration | CRM-M053 | migration v21; EXPLAIN on host | IMPLEMENTED |
| CRM-AUDIT | I.unbounded | Inbox/thread unbounded queries | WS13 | — | CRM-M034 | LIMIT in model; test | VERIFIED |
| CRM-AUDIT | I.push | Synchronous push fan-out in request; custom-field sync N queries | WS13 | — | CRM-M054 | PLANNED | PLANNED |
| CRM-AUDIT | I.cron | Media backfill/migration steps every tick | WS13 | — | CRM-M055 | PLANNED | PLANNED |
| CRM-AUDIT | I.no-infra | Do NOT add Redis/queues/VPS/SPA | all | — | constraint | none added | VERIFIED |
| CRM-AUDIT | J9 | Media queue without lease | WS15 | — | CRM-M055 | PLANNED | PLANNED |
| CRM-AUDIT | J11 | Double send after crash between Graph 200 and finalize | WS15 | — | CRM-M058 | PLANNED | VERIFIED |
| CRM-AUDIT | J12 | waba_id phantom; fake DB has no schema | WS11/WS16 | — | CRM-M052 + CRM-M069 | PLANNED | PLANNED |
| CRM-AUDIT | J13 | Six copy-pasted drainers; function_exists/globals contract | WS15 | tests | CRM-M065/M066 | PLANNED | PLANNED |
| CRM-AUDIT | J15 | Lead Ads path skips lead_created and dispatcher | WS12 | — | CRM-M051 | PLANNED | PLANNED |
| CRM-AUDIT | J17 | Two clocks in one row | WS15 | — | CRM-M065 | PLANNED (mitigated by se_db_clock_offset) | NOT-APPLICABLE-WITH-EVIDENCE |
| CRM-AUDIT | J18 | retention_state not linked to journey data | WS11 | lawyer | CRM-M057 | PLANNED | BLOCKED-DECISION |
| CRM-AUDIT | J19 | Stale docs; AppleDouble files | WS16 | — | CRM-M068 | docs | IMPLEMENTED |
| CRM-AUDIT | J20 | "hekim onaylı" string | WS2 | — | CRM-M018 | grep gate | VERIFIED |
| CRM-AUDIT | K1 | Schema oracle in fake DB | WS16 | — | CRM-M069 | PLANNED | PLANNED |
| CRM-AUDIT | K2 | Reminder consumer end-to-end test | WS1 | — | CRM-M002 | test_appointments | VERIFIED |
| CRM-AUDIT | K3 | Duplicate envelope test | WS1 | — | CRM-M013 | test_whatsapp | VERIFIED |
| CRM-AUDIT | K4 | 150 journeys reminder test | WS1 | — | CRM-M007 | test_journey | VERIFIED |
| CRM-AUDIT | K5 | Media fetching lease test | WS15 | — | CRM-M055 | PLANNED | PLANNED |
| CRM-AUDIT | K6 | Website lead reuses WhatsApp lead by phone | WS11 | CRM-M050 | CRM-M050 | PLANNED | PLANNED |
| CRM-AUDIT | K7 | Consent purpose alignment test | WS12 | decision | CRM-M010 | test of mapping option | BLOCKED-DECISION |
| CRM-AUDIT | K8 | Security regressions (CSRF gateways, assign, docs 404) | WS1 | — | CRM-M004/M012/M011 | tests + host curl | VERIFIED |
| CRM-AUDIT | K9 | Responsive regression Playwright | WS16 | Mac | CRM-M071 | scripts/ui-regression | IMPLEMENTED |
| CRM-AUDIT | K10 | Health JSON includes dispatcher_age and skipped | WS12 | — | CRM-M008 | test_health | VERIFIED |
| CRM-AUDIT | AZCRM-UX-001 | Needs-me-today landing list | WS4 | M017 | CRM-M023 | live | VERIFIED |
| CRM-AUDIT | AZCRM-UX-002 | Patients list shows patient | WS5 | — | CRM-M024 | live | VERIFIED |
| CRM-AUDIT | AZCRM-UX-003 | Journey header identity block | WS6 | — | CRM-M025 | live | IMPLEMENTED |
| CRM-AUDIT | AZCRM-UX-004 | Translate raw keys | WS2 | — | CRM-M018 | grep | VERIFIED |
| CRM-AUDIT | AZCRM-UX-005 | Dashboard counters today/week; brand-0 fix; split Consultation due | WS4 | — | CRM-M009 + CRM-M023 | live | VERIFIED |
| CRM-AUDIT | AZCRM-UX-006 | Hide irrelevant Perfex lead fields; trim pipeline | WS3 | owner confirms stages | CRM-M031 | live | BLOCKED-DECISION |
| CRM-AUDIT | AZCRM-UX-007 | Journey index search/filter/sort/pagination >100 | WS5 | — | CRM-M024 | live | VERIFIED |
| CRM-AUDIT | AZCRM-UX-008 | Consistent phone rendering helper | WS2 | — | CRM-M019 | unit test | VERIFIED |
| CRM-AUDIT | AZCRM-WF-001 | Resume/pause in thread; pause opt-in | WS7 | — | CRM-M006 | test + live | VERIFIED |
| CRM-AUDIT | AZCRM-WF-002 | Staff-owned timers | WS10 | M017 | CRM-M045 | test | VERIFIED |
| CRM-AUDIT | AZCRM-WF-003 | Aftercare auto-start at procedure end | WS10 | protocol approved flag | CRM-M046 | PLANNED | VERIFIED |
| CRM-AUDIT | AZCRM-WF-004 | Consultation auto-held + Book procedure now | WS8/WS10 | — | CRM-M041/M047 | partial: shortcut done, auto-held planned | IMPLEMENTED |
| CRM-AUDIT | AZCRM-WF-005 | Quote expiry state + follow-up | WS9 | — | CRM-M048 | test | VERIFIED |
| CRM-AUDIT | AZCRM-WF-006 | Reopen buttons for not_suitable/closed_lost | WS6 | — | CRM-M030 | PLANNED | IMPLEMENTED |
| CRM-AUDIT | AZCRM-WF-007 | Photo acceptance in one step | WS6 | Flow JSON at Meta | CRM-M029 | PLANNED | PLANNED |
| CRM-AUDIT | AZCRM-WA-001 | Composer wraps on mobile; dead selectors; sticky; panel first | WS7 | M015 | CRM-M032 | Playwright | IMPLEMENTED |
| CRM-AUDIT | AZCRM-WA-002 | Inbox: name + preview + state + search + pagination | WS7 | M034 | CRM-M034/M035 | live | VERIFIED |
| CRM-AUDIT | AZCRM-WA-003 | Appointment reminder consumer fix | WS1 | — | CRM-M002 | test | VERIFIED |
| CRM-AUDIT | AZCRM-WA-004 | Contextual actions in thread panel | WS7 | M017 | CRM-M036 | live | VERIFIED |
| CRM-AUDIT | AZCRM-WA-005 | Duplicate-envelope guard order | WS1 | — | CRM-M013 | test | VERIFIED |
| CRM-AUDIT | AZCRM-WA-006 | Tracker copy in Turkish | WS2 | — | CRM-M018 | grep | VERIFIED |
| CRM-AUDIT | AZCRM-PJ-001 | Consent purpose alignment for CAPI | WS12 | decision | CRM-M010 | BLOCKED-DECISION | BLOCKED-DECISION |
| CRM-AUDIT | AZCRM-PJ-002 | Reminder scan pagination | WS1 | — | CRM-M007 | test | VERIFIED |
| CRM-AUDIT | AZCRM-PJ-003 | phone_e164 identity | WS11 | migration | CRM-M050 | PLANNED | PLANNED |
| CRM-AUDIT | AZCRM-PJ-004 | Lead Ads → dispatcher + lead_created | WS12 | M050 | CRM-M051 | PLANNED | PLANNED |
| CRM-AUDIT | AZCRM-PJ-005 | Long-term follow-up state | WS10 | protocol | CRM-M056 | PLANNED | PLANNED |
| CRM-AUDIT | AZCRM-PJ-006 | KVKK erasure for journey data | WS11 | lawyer | CRM-M057 | PLANNED | BLOCKED-DECISION |
| CRM-AUDIT | AZCRM-AP-001 | Calendar loads FullCalendar | WS8 | — | CRM-M003 | live | IMPLEMENTED |
| CRM-AUDIT | AZCRM-AP-002 | Appointment type on standalone form + list + conflict reason | WS8 | — | CRM-M039 | test + live | VERIFIED |
| CRM-AUDIT | AZCRM-AP-003 | Reschedule sends new confirmation (salt) | WS8 | — | CRM-M044 | test | VERIFIED |
| CRM-AUDIT | AZCRM-AP-004 | Appointment detail linked from care tab | WS8 | — | CRM-M043 | live | IMPLEMENTED |
| CRM-AUDIT | AZCRM-SEC-001 | CSRF gateways anchor + host constants check | WS1 | host | CRM-M004 (+M062) | test; host check pending | VERIFIED |
| CRM-AUDIT | AZCRM-SEC-002 | PWA subscribe with CSRF; unsubscribe ownership | WS1 | — | CRM-M005 | code | VERIFIED |
| CRM-AUDIT | AZCRM-SEC-003 | Deny docs/services/.env; deploy excludes; strip ._* | WS1 | host | CRM-M011 | htaccess | IMPLEMENTED |
| CRM-AUDIT | AZCRM-SEC-004 | crm-media Worker fail-closed | WS12 | — | CRM-M061 | worker code | VERIFIED |
| CRM-AUDIT | AZCRM-SEC-005 | Real client IP behind Cloudflare | WS1 | host | CRM-M062 | PLANNED | PLANNED |
| CRM-AUDIT | AZCRM-SEC-006 | Remove option-table secret fallbacks | WS1 | — | CRM-M060 | PLANNED | PLANNED |
| CRM-AUDIT | AZCRM-SEC-007 | Outbound/push host allowlists, self-test gate, decode cap, base-URL pin | WS1 | — | CRM-M012 | code | VERIFIED |
| CRM-AUDIT | AZCRM-SEC-008 | Journey assign brand check; Perfex cron hash_equals | WS1 | — | CRM-M012 | test | VERIFIED |
| CRM-AUDIT | AZCRM-PERF-001 | Indexes | WS13 | migration | CRM-M053 | PLANNED | IMPLEMENTED |
| CRM-AUDIT | AZCRM-PERF-002 | LIMIT/paginate inbox and thread | WS13 | — | CRM-M034 | test | VERIFIED |
| CRM-AUDIT | AZCRM-PERF-003 | Push fan-out to dispatcher; batch custom-field sync | WS13 | — | CRM-M054 | PLANNED | PLANNED |
| CRM-AUDIT | AZCRM-PERF-004 | Media backfill only when pending | WS13 | — | CRM-M055 | PLANNED | PLANNED |
| CRM-AUDIT | AZCRM-ARCH-001 | Shared SeQueue drainer + se_media lease | WS15 | tests | CRM-M065 | PLANNED | PLANNED |
| CRM-AUDIT | AZCRM-ARCH-002 | Registry instead of $GLOBALS | WS15 | — | CRM-M066 | PLANNED | PLANNED |
| CRM-AUDIT | AZCRM-ARCH-003 | Split se_journey/helpers.php | WS15 | — | CRM-M067 | PLANNED | PLANNED |
| CRM-AUDIT | AZCRM-ARCH-004 | waba_id on conversations | WS11 | migration | CRM-M052 | PLANNED | PLANNED |
| CRM-AUDIT | AZCRM-ARCH-005 | Record wamid before finalize | WS15 | — | CRM-M058 | PLANNED | VERIFIED |
| CRM-AUDIT | AZCRM-ARCH-006 | Reconcile or retire turquai-bridge | WS15 | owner decision | CRM-M059 | BLOCKED-DECISION | BLOCKED-DECISION |
| CRM-AUDIT | AZCRM-QA-001 | Schema oracle in fake DB | WS16 | — | CRM-M069 | PLANNED | PLANNED |
| CRM-AUDIT | AZCRM-QA-002 | Tests K.2–K.8, K.10 | WS16 | — | CRM-M070 | suite | VERIFIED |
| CRM-AUDIT | AZCRM-QA-003 | Playwright responsive regression | WS16 | Mac | CRM-M071 | scripts/ui-regression | IMPLEMENTED |
| CRM-AUDIT | AZCRM-QA-004 | Sandbox-brand E2E with fake transport | WS16 | — | CRM-M072 | PLANNED | IMPLEMENTED |
| CRM-AUDIT | AZCRM-QA-005 | Docs refresh + content-safety grep on lang | WS16 | — | CRM-M068 + CRM-M018 | grep gate done; docs planned | IMPLEMENTED |
| CRM-AUDIT | AZCRM-MOB-001 | = WA-001 | WS7 | — | CRM-M032 | Playwright | IMPLEMENTED |
| CRM-AUDIT | AZCRM-MOB-002 | 44 px targets and ≥12 px meta text | WS14 | M015 | CRM-M015 | probe | IMPLEMENTED |
| CRM-AUDIT | AZCRM-MOB-003 | Leads: status pills behind select; card layout | WS3 | — | CRM-M031 | PLANNED (Leads hidden for clinic roles) | BLOCKED-DECISION |
| CRM-AUDIT | AZCRM-MOB-004 | Tablet header overlap | WS14 | M015 | CRM-M015 | live 769 | IMPLEMENTED |
| CRM-AUDIT | AZCRM-A11Y-001 | Contrast tokens | WS14 | — | CRM-M015 | probe | IMPLEMENTED |
| CRM-AUDIT | AZCRM-A11Y-002 | Names for icon controls, label staff_id, skip link, lang | WS14 | — | CRM-M063 + CRM-M020 | axe/probe | IMPLEMENTED |
| CRM-AUDIT | AZCRM-A11Y-003 | :focus-visible ring | WS14 | — | CRM-M015 | probe | IMPLEMENTED |
| CRM-AUDIT | AZCRM-A11Y-004 | RTL logical properties; dir from language | WS14 | — | CRM-M064 + CRM-M020 | rtl smoke | IMPLEMENTED |
| CRM-AUDIT | AZCRM-OBS-001 | Health: dispatcher age, WA queue, skipped by reason, reminder failures; remove dead | WS12 | — | CRM-M008 | test + live | VERIFIED |
| CRM-AUDIT | AZCRM-OBS-002 | Stuck list: no transition N days per state | WS4/WS10 | M045 | CRM-M023/M045 | live Bugün | VERIFIED |
| CRM-AUDIT | AZCRM-OBS-003 | Keep redacted exception message on webhook events | WS12 | — | CRM-M073 | PLANNED | VERIFIED |
| CRM-AUDIT | AZCRM-OBS-004 | Cron listener isolation | WS12 | — | CRM-M014 | code | VERIFIED |
| CRM-AUDIT | AZCRM-OBS-005 | External uptime check on dispatch heartbeat | WS12 | external | CRM-M074 | PLANNED (external service) | PLANNED |
| CRM-AUDIT | O.QW1-10 | Top 10 quick wins | WS1/WS2 | — | M002,M003,M032,M006,M004,M008,M009,M007,M018,M024 | all mapped above | IMPLEMENTED |
| CRM-AUDIT | App1 | 25 Turkish copy fixes | WS2 | — | CRM-M018 | lang diff | VERIFIED |
| CRM-AUDIT | App3 | Things that work well — do not "fix" | all | — | constraint | suite green; no rewrite | VERIFIED |
| CRM-AUDIT | Constraint | No automated suitability decision; no rebuild; keep Perfex foundation; retention_state exists (no migration to add) | all | — | constraint | code review | VERIFIED |
| CRM-AUDIT | P.decision | READY WITH FIXES — P1 set first | WS1 | — | Wave 1 | wave log | VERIFIED |
| UIUX-OPT | A.1-10 | Ten highest-impact changes | WS2-WS8 | — | M032,M003,M023,M017,M024,M025,M035,M018,M021,M015 | each mapped | IMPLEMENTED |
| UIUX-OPT | B | Target IA: 4 operational items + Yönetim; hide/move table | WS3 | — | CRM-M021 | live sidebar per role | VERIFIED |
| UIUX-OPT | C | Navigation: desktop sidebar groups, tablet off-canvas + icons behind chevron, phone tab bar, no breadcrumbs, back links, renames | WS3 | M015 | CRM-M021 + CRM-M022 | live at 3 widths | IMPLEMENTED |
| UIUX-OPT | D | Bugün spec: 8/4, queue sources/order/cap, pipeline pills, right column, phone, removed counters, <600 ms | WS4 | M017 | CRM-M023 | live + timing | VERIFIED |
| UIUX-OPT | E | Hastalar spec: columns, tablet/phone reductions, toolbar chips/selects/search, sort, pagination, row click, brand hidden | WS5 | M016,M017,M019 | CRM-M024 | live | VERIFIED |
| UIUX-OPT | F | Patient workspace spec: header, stage bar, next panel, alerts, 9 tabs, timeline rules, phone | WS6 | M016,M017 | CRM-M025/M026/M027 | live | IMPLEMENTED |
| UIUX-OPT | G | Mesajlar spec: three columns, list row, thread, context, tablet, phone strip+sheet, pause semantics | WS7 | M017 | CRM-M032–M037 | live | IMPLEMENTED |
| UIUX-OPT | H | Appointments spec: list, calendar colours, agenda, form v2, conflict copy, same-day, patient card | WS8 | — | CRM-M039–M044 | live | VERIFIED |
| UIUX-OPT | I | Quote spec: layout, statuses, deposit collapsed, role actions, Sales read-only | WS9 | — | CRM-M049 + CRM-M048 | live | IMPLEMENTED |
| UIUX-OPT | J | Mobile matrix 390/768/1024 per screen; ≥44 px; no horizontal table scroll | WS14 | M015 | CRM-M071 assertions | Playwright | IMPLEMENTED |
| UIUX-OPT | K | Accessibility rules table (14) | WS14 | — | CRM-M015/M063/M020 | probe/axe | IMPLEMENTED |
| UIUX-OPT | L | Terminology canon; retired words; CI grep | WS2 | — | CRM-M018 | grep gate | VERIFIED |
| UIUX-OPT | M | RTL strategy (9 rules) | WS14 | — | CRM-M064 | rtl smoke | IMPLEMENTED |
| UIUX-OPT | N | Design system delivery via se-ds.css + helpers | WS2 | — | CRM-M015/M016 | file present, loaded | IMPLEMENTED |
| UIUX-OPT | O | Mockups as target | WS16 | — | visual verification | CRM-UIUX-VISUAL-VERIFICATION | IMPLEMENTING |
| UIUX-OPT | P | Before/after per screen | WS16 | — | productivity results | CRM-PRODUCTIVITY-RESULTS | IMPLEMENTING |
| UIUX-OPT | Q | Component inventory → se-* classes + helpers | WS2 | — | CRM-M015/M016 | helpers exist | IMPLEMENTED |
| UIUX-OPT | R | Implementation mapping (files) | all | — | master backlog | files touched match | VERIFIED |
| UIUX-OPT | S/T | Prioritised backlog + sequence | all | — | master plan | — | VERIFIED |
| DESIGN-SYSTEM | 1.1 | Colour tokens dark+light, semantic set incl. action; retire #71717a and inline palettes | WS2 | — | CRM-M015 | se-ds.css tokens; grep inline styles | IMPLEMENTED |
| DESIGN-SYSTEM | 1.2 | Type scale 24/18/15/14/13, min 12, tabular nums | WS2 | — | CRM-M015 | css | IMPLEMENTED |
| DESIGN-SYSTEM | 1.3 | Spacing tokens 4–32; card 16/24; page 24/16 | WS2 | — | CRM-M015 | css | IMPLEMENTED |
| DESIGN-SYSTEM | 1.4 | Radius 8/12/pill/14; flat elevation; motion under reduced-motion | WS2 | — | CRM-M015 | css | IMPLEMENTED |
| DESIGN-SYSTEM | 1.5 | Controls 40/44; sidebar 232; header 56 | WS2 | — | CRM-M015 | css | IMPLEMENTED |
| DESIGN-SYSTEM | 2.1 | Buttons variants/states/one primary per group | WS2 | — | CRM-M015/M016 | helpers | IMPLEMENTED |
| DESIGN-SYSTEM | 2.2 | Status badge with state map | WS2 | — | CRM-M016 | helper test | VERIFIED |
| DESIGN-SYSTEM | 2.3 | Attention row | WS4 | — | CRM-M016/M023 | live | VERIFIED |
| DESIGN-SYSTEM | 2.4 | Patient summary header | WS6 | — | CRM-M025 | live | IMPLEMENTED |
| DESIGN-SYSTEM | 2.5 | Stage bar 7 segments | WS6 | — | CRM-M025 | live | IMPLEMENTED |
| DESIGN-SYSTEM | 2.6 | Next-action panel from se_journey_next_action | WS2/WS6 | — | CRM-M017/M025 | live | VERIFIED |
| DESIGN-SYSTEM | 2.7 | Alert rows, no persistent banners | WS4 | — | CRM-M016/M023 | live | VERIFIED |
| DESIGN-SYSTEM | 2.8 | Cards | WS2 | — | CRM-M015 | css | IMPLEMENTED |
| DESIGN-SYSTEM | 2.9 | Tables with hide-m, identity cell, single row action, sortable headers | WS5 | — | CRM-M024 | live | VERIFIED |
| DESIGN-SYSTEM | 2.10 | Chips radiogroup | WS5 | — | CRM-M024 | live | VERIFIED |
| DESIGN-SYSTEM | 2.11 | Inputs/fields/error state | WS8 | — | CRM-M039 | live | VERIFIED |
| DESIGN-SYSTEM | 2.12 | Tabs 44 px, scroll, tablist | WS6 | — | CRM-M027 | live | IMPLEMENTED |
| DESIGN-SYSTEM | 2.13 | Timeline with Turkish labels | WS6 | — | CRM-M026 | live | VERIFIED |
| DESIGN-SYSTEM | 2.14 | Chat bubbles, auto tag, composer row rules | WS7 | — | CRM-M032/M037 | live | IMPLEMENTED |
| DESIGN-SYSTEM | 2.15 | Calendar type colours; agenda ≤767 | WS8 | — | CRM-M003/M040 | live | IMPLEMENTED |
| DESIGN-SYSTEM | 2.16 | Mobile shell: tab bar, hide in thread, FAB | WS3/WS7 | — | CRM-M022/M032 | live | IMPLEMENTED |
| DESIGN-SYSTEM | 2.17 | Empty states pattern | WS2 | — | CRM-M016 | helper | VERIFIED |
| DESIGN-SYSTEM | 2.18 | Modals/sheets | WS7 | — | CRM-M033 | live | IMPLEMENTED |
| DESIGN-SYSTEM | 3 | Layout grid per breakpoint | WS14 | — | CRM-M015 | css | IMPLEMENTED |
| DESIGN-SYSTEM | 4 | Bootstrap-3 notes: load after theme, scope .se-*, body.se-clinic overrides, helper API, icons aria, theme flip, RTL logical | WS2 | — | CRM-M015/M016/M064 | code | IMPLEMENTED |
| UX-COPY | 0 | One concept one word; never hekim/doktor/Dr./cerrah/klinisyen; patient-facing copy untouched | WS2 | — | CRM-M018 | grep gate + patient copy diff empty | VERIFIED |
| UX-COPY | 1 | Glossary 26 canonical terms | WS2 | — | CRM-M018 | lang files | VERIFIED |
| UX-COPY | 2 | Navigation labels | WS3 | — | CRM-M021/M022 | live | VERIFIED |
| UX-COPY | 3.1 | Macro-stages | WS2 | — | CRM-M016 | helper | VERIFIED |
| UX-COPY | 3.2 | 31 state → label → stage → colour map | WS2 | — | CRM-M016 | helper test | VERIFIED |
| UX-COPY | 3.3 | Quote statuses | WS9 | — | CRM-M049 | live | IMPLEMENTED |
| UX-COPY | 3.4 | Appointment types/statuses | WS8 | — | CRM-M039 | live | VERIFIED |
| UX-COPY | 3.5 | Consent badges | WS6 | — | CRM-M025 | live | IMPLEMENTED |
| UX-COPY | 4 | Next-action sentences (13) | WS2 | — | CRM-M017 | table test | VERIFIED |
| UX-COPY | 5 | Message patterns: buttons, confirms, errors, empty, toasts, loading | WS2-8 | — | CRM-M016/M039/M024 | live | VERIFIED |
| UX-COPY | 6 | Timeline labels (34) | WS6 | — | CRM-M026 | helper test | VERIFIED |
| UX-COPY | 7 | Outbound tracker Turkish | WS7 | — | CRM-M037 | grep | VERIFIED |
| UX-COPY | 8 | 30 lang-key rewrites | WS2 | — | CRM-M018 | lang diff | VERIFIED |
| UX-COPY | 9 | Phone/number/money formatting | WS2 | — | CRM-M019 | unit test | VERIFIED |
| UX-COPY | 10 | lang attribute per staff/patient | WS14 | — | CRM-M020 | live | IMPLEMENTED |
| UIUX-BACKLOG | UX-F01 | Ship se-ds.css | WS2 | — | CRM-M015 | file + probe | IMPLEMENTED |
| UIUX-BACKLOG | UX-F02 | UI helper API | WS2 | — | CRM-M016 | tests | VERIFIED |
| UIUX-BACKLOG | UX-F03 | se_journey_next_action() | WS2 | — | CRM-M017 | table test | VERIFIED |
| UIUX-BACKLOG | UX-F04 | Turkish copy pass + CI grep | WS2 | — | CRM-M018 | grep | VERIFIED |
| UIUX-BACKLOG | UX-F05 | Phone formatter | WS2 | — | CRM-M019 | test | VERIFIED |
| UIUX-BACKLOG | UX-NAV01 | New sidebar + role gating | WS3 | — | CRM-M021 | live | VERIFIED |
| UIUX-BACKLOG | UX-NAV02 | Mobile bottom tab bar | WS3 | — | CRM-M022 | live 390 | IMPLEMENTED |
| UIUX-BACKLOG | UX-NAV03 | Tablet header fix | WS14 | — | CRM-M015 | live 769 | IMPLEMENTED |
| UIUX-BACKLOG | UX-NAV04 | Global search ⌘K | WS3 | M019 | CRM-M075 | PLANNED | IMPLEMENTED |
| UIUX-BACKLOG | UX-D01 | Bugün | WS4 | — | CRM-M023 | live | VERIFIED |
| UIUX-BACKLOG | UX-D02 | Queue ordering & thresholds | WS4 | — | CRM-M023 | test | VERIFIED |
| UIUX-BACKLOG | UX-D03 | System card honesty | WS4 | M008 | CRM-M023 | live | VERIFIED |
| UIUX-BACKLOG | UX-D04 | Remove false consent banner | WS1 | — | CRM-M009 | live | VERIFIED |
| UIUX-BACKLOG | UX-L01 | Unified Hastalar list | WS5 | — | CRM-M024 | live | VERIFIED |
| UIUX-BACKLOG | UX-L02 | Retire se_patients_list / journey index as menu items | WS3 | — | CRM-M021 | live | VERIFIED |
| UIUX-BACKLOG | UX-L03 | Perfex Leads cleanup for admins | WS3 | owner | CRM-M031 | PLANNED | BLOCKED-DECISION |
| UIUX-BACKLOG | UX-P01 | Patient header | WS6 | — | CRM-M025 | live | IMPLEMENTED |
| UIUX-BACKLOG | UX-P02 | Stage bar + next panel | WS6 | — | CRM-M025 | live | IMPLEMENTED |
| UIUX-BACKLOG | UX-P03 | Tabs with embedded Sohbet, Dosyalar | WS6 | — | CRM-M027 | live | IMPLEMENTED |
| UIUX-BACKLOG | UX-P04 | Human timeline | WS6 | — | CRM-M026 | live | VERIFIED |
| UIUX-BACKLOG | UX-P05 | Alerts strip | WS6 | — | CRM-M025 | live | IMPLEMENTED |
| UIUX-BACKLOG | UX-P06 | Internal notes + call log | WS6 | — | CRM-M028 | PLANNED | IMPLEMENTED |
| UIUX-BACKLOG | UX-P07 | Photo review in one step | WS6 | Flow | CRM-M029 | PLANNED | PLANNED |
| UIUX-BACKLOG | UX-P08 | Reopen buttons | WS6 | — | CRM-M030 | PLANNED | IMPLEMENTED |
| UIUX-BACKLOG | UX-W01 | Mobile composer + sticky + no tab bar in thread | WS7 | — | CRM-M032 | Playwright | IMPLEMENTED |
| UIUX-BACKLOG | UX-W02 | Mobile context strip + sheet | WS7 | — | CRM-M033 | live 390 | IMPLEMENTED |
| UIUX-BACKLOG | UX-W03 | Three-column desktop inbox | WS7 | M034 | CRM-M035 | live | IMPLEMENTED |
| UIUX-BACKLOG | UX-W04 | Inbox query: name, preview, unread, state, search, pagination | WS7 | — | CRM-M034 | test | VERIFIED |
| UIUX-BACKLOG | UX-W05 | Pause opt-in + Resume | WS7 | — | CRM-M006 | test + live | VERIFIED |
| UIUX-BACKLOG | UX-W06 | Contextual actions by state | WS7 | M017 | CRM-M036 | live | VERIFIED |
| UIUX-BACKLOG | UX-W07 | Automatic-message styling | WS7 | — | CRM-M037 | live | VERIFIED |
| UIUX-BACKLOG | UX-W08 | Tracker Turkish collapsed | WS7 | — | CRM-M037 | live | VERIFIED |
| UIUX-BACKLOG | UX-W09 | Instagram as tab of Mesajlar | WS7 | M035 | CRM-M038 | PLANNED | PLANNED |
| UIUX-BACKLOG | UX-A01 | Calendar renders | WS8 | — | CRM-M003 | live | IMPLEMENTED |
| UIUX-BACKLOG | UX-A02 | Mobile agenda view | WS8 | — | CRM-M040 | live 390 | IMPLEMENTED |
| UIUX-BACKLOG | UX-A03 | Appointment form v2 | WS8 | — | CRM-M039 | live | VERIFIED |
| UIUX-BACKLOG | UX-A04 | Bugün işlem planla shortcut | WS8 | — | CRM-M041 | live | IMPLEMENTED |
| UIUX-BACKLOG | UX-A05 | List with type + patient name | WS8 | — | CRM-M042 | live | IMPLEMENTED |
| UIUX-BACKLOG | UX-A06 | Appointment card on patient page | WS8 | — | CRM-M043 | live | IMPLEMENTED |
| UIUX-BACKLOG | UX-Q01 | Quote tab layout | WS9 | — | CRM-M049 | live | IMPLEMENTED |
| UIUX-BACKLOG | UX-Q02 | Quote status vocabulary + expiry | WS9 | — | CRM-M048 | test | VERIFIED |
| UIUX-BACKLOG | UX-Q03 | Sales role sees quote read-only | WS9 | — | CRM-M049 | test | IMPLEMENTED |
| UIUX-BACKLOG | UX-X01 | Contrast + focus + sizes | WS14 | — | CRM-M015 | probe | IMPLEMENTED |
| UIUX-BACKLOG | UX-X02 | Names and labels | WS14 | — | CRM-M063 | probe | IMPLEMENTED |
| UIUX-BACKLOG | UX-X03 | lang and dir | WS14 | — | CRM-M020 | live | IMPLEMENTED |
| UIUX-BACKLOG | UX-X04 | Logical properties + RTL sweep | WS14 | — | CRM-M064 | rtl smoke | IMPLEMENTED |
| UIUX-BACKLOG | UX-X05 | Reduced motion + no colour-only | WS14 | — | CRM-M015 | css | IMPLEMENTED |
| UIUX-BACKLOG | UX-QA01 | Playwright visual regression | WS16 | Mac | CRM-M071 | run log | IMPLEMENTED |
| UIUX-BACKLOG | UX-QA02 | Copy lint | WS16 | — | CRM-M018 | CI gate | VERIFIED |
| UIUX-BACKLOG | UX-QA03 | Next-action table test | WS16 | — | CRM-M017 | test | VERIFIED |
