# CRM Master Optimization Plan — 2026-09-04

Inputs: CRM-AUDIT-2026-09-03, CRM-UIUX-OPTIMIZATION-2026-09-04, AZIN-CRM-DESIGN-SYSTEM-v1, AZIN-CRM-UX-COPY-TR, AZIN-CRM-UIUX-BACKLOG (+ mockups). Completeness gate: `CRM-MASTER-TRACEABILITY-2026-09-04.md` (243 rows). Conflicts: `CRM-SOURCE-CONFLICTS-2026-09-04.md`. Tickets: `CRM-MASTER-EXECUTION-BACKLOG.md`.

## 0. Brainstorm outcome (product-management:brainstorm, run 2026-09-04)

Framing question: *what single primitive, if built first, makes the most tickets fall out as views?* Answer: **`se_journey_next_action()`** plus a **state→label→stage→colour map**. Together they feed Bugün rows, Hastalar rows, the patient header/next panel, the WhatsApp context column and strip, the staff timers (same thresholds), and every primary button. Building them once removes four separately-specified solutions.

1. **Major workstreams** — the 17 below; WS2 (foundation) and WS10 (timers) are the leverage points; WS15 (architecture) is deliberately last and mostly deferred.
2. **Foundation that unlocks most** — `se-ds.css` (fixes T3, T9, T17, MOB-002/004, A11Y-001/003, X01/X05, NAV03 in one file), `se_ui_*` helpers (every screen), next-action engine (7 screens + timers), Turkish state map (badges, timeline, counters).
3. **Bundles** — W01+WA-001+MOB-001 (composer); WA-003+F1+K2 (reminders); D01+UX-001+OBS-002 (Bugün); L01+UX-002+UX-007 (Hastalar); P01+P02+P05+UX-003 (patient header); W03+W04+WA-002+PERF-002 (inbox query + three columns); A03+AP-002 (type + conflict); Q01+Q02+Q03+WF-005 (quote).
4. **Apparent duplicates that are layers** — UX-D01 (view) vs AZCRM-UX-001 (query) vs OBS-002 (stuck rows); UX-W05 (UI) vs WF-001 (behaviour); UX-A01 (asset) vs AP-001 (feed type). Each keeps both IDs.
5. **Missing dependencies found** — Hastalar search needs `phone_e164` (M050) *or* a normalised-in-PHP fallback → shipped with a fallback (`se_journey_normalize_wa_id` over `phonenumber`) so M024 does not wait for a migration; appointment form v2 needs `appointment_type` already in schema (present); timers need the reminder-LIMIT fix; Health honesty needs `skipped` counting before the Sistem card; tab bar needs the thread to hide it (M032).
6. **Largest staff-time reduction** — Bugün queue + next action (morning triage 3 pages → 1), composer/pause opt-in (every reply), Hastalar search, same-day shortcut, contextual actions in thread.
7. **Largest risk reduction** — reminders consumer, CSRF anchor, honest Health, dispatcher age, duplicate-envelope order, reminder LIMIT.
8. **Deploy together** — Wave 1 + Wave 2 (so the composer fix arrives as DS CSS, not a patch); Wave 3 + Wave 4 (navigation rename only makes sense once Hastalar exists); Wave 5 alone; Wave 6 with the reminder fix already live.
9. **Minimal Perfex core** — zero core PHP edits beyond the existing Kanban patch; the CSRF fix touches `application/config/config.php` (already a tracked, patched file) with a 3-line anchored check; header overlap and `.text-muted` are CSS overrides scoped to `body.se-clinic`; navigation uses the existing sidebar filter hooks; Leads/Customers remain intact and reachable by URL/admin.
10. **Verification that proves both reports** — fake-DB suite (+ new tests per ticket), Playwright responsive suite against the live app on the Mac, live screenshots compared to mockups, sandbox-brand workflow walk (fake transport), reverse audit against the traceability rows with evidence links.

Riskiest assumption: that the Bugün query stays <600 ms with joins over leads/journeys/conversations/appointments — mitigated by indexes (M053) and a 25-row cap; measured in verification.

## 1. Workstreams

| WS | Objective | Source requirements (IDs) | Master tickets | Deps | Prod risk | Rollback | Completion gate |
|---|---|---|---|---|---|---|---|
| WS0 Baseline | branch, start SHA, test baseline, traceability, plan, backlog | program §2, §5, §6 | M001 | — | none | n/a | docs exist; 3 824/0 baseline recorded |
| WS1 Security & silent failures | stop the six silent failures and the CSRF hole | T1,T5,T6,T8,T13,T15,T16,T18,C1–C3,L4,L10,DiD-1..6,K2–K4,K8,SEC-001/002/003/007/008,WA-003/005,WF-001,PJ-002,D04 | M002–M007, M009, M011–M014 | — | low (behavioural fixes with tests) | git revert per commit | suite green + new tests + host checks listed |
| WS2 Shared UX foundation | one stylesheet, helpers, next-action, Turkish | F01–F05, DS all, UX-COPY all, T11,T17,J20,App1,A11Y-001/003,MOB-002/004,NAV03,X01,X05,QA02/03 | M015–M020 | WS0 | low-med (global CSS) | disable `se-ds.css` load flag | probe: contrast/focus/sizes; grep gate; next-action table test |
| WS3 Navigation & shell | 4-item IA + Yönetim; tab bar | B,C,NAV01/02,L02,T20,UX-COPY §2 | M021, M022 | WS2 | med (staff see new menu) | option `se_clinic_nav_v2=0` | live sidebar per role at 3 widths |
| WS4 Bugün | attention dashboard | D,D01–D03,UX-001,UX-005,OBS-002 | M023 | WS2, M008 | low | option `se_clinic_dashboard_v2=0` | live; <600 ms; queue rows have one button |
| WS5 Hastalar & identity | unified list | E,L01,UX-002/007,T10 | M024 (+M050 later) | WS2 | low | old lists still routable | search by name/phone; ≤767 no h-scroll |
| WS6 Patient workspace | header, stages, next, alerts, tabs, timeline | F,P01–P08,UX-003,DS 2.4–2.6,2.12,2.13,UX-COPY §3.5,§6 | M025–M030 | WS2 | med (busiest page) | view file revert | live; timeline no raw kinds |
| WS7 Mesajlar | desktop 3-col, phone thread-first, pause, contextual actions | G,W01–W09,WA-001/002/004/006,MOB-001,DS 2.14,2.16,2.18,UX-COPY §7 | M032–M038 | WS2, M017 | med (live channel; no send changes) | revert views; composer policy untouched | Playwright 390 assertions; live |
| WS8 Randevular | calendar, type, agenda, form v2, same-day | H,A01–A06,AP-001..004,WF-004 (UI part),DS 2.11,2.15 | M003, M039–M044 | M002 | low-med (form) | revert | calendar renders; conflict copy; 3-click same-day |
| WS9 Quote & clinical productivity | quote tab, statuses, expiry, sales read-only | I,Q01–Q03,WF-005,UX-COPY §3.3 | M048, M049 | WS2 | low | revert | live; test |
| WS10 Timers & automation safety | staff timers, auto-held, aftercare auto-start | T7,WF-002/003/004,OBS-002,UX-COPY §4 thresholds | M045–M047 | M017, M007 | med (creates tasks/pushes; one patient template) | option `se_journey_timers=0` | test per threshold; no duplicate tasks |
| WS11 Identity & data model | phone_e164, waba_id, retention | T12,T14,PJ-003/005/006,ARCH-004,L9,J18,K6 | M050,M052,M056,M057 | migrations; lawyer | med (migration) | reversible migration | schema + tests |
| WS12 Integrations & observability | Health honesty, CAPI mapping, Lead Ads, worker, cron isolation | T2,T19,OBS-001/003/004/005,PJ-001/004,SEC-004,J15,K7,K10 | M008,M010,M014,M051,M061,M073,M074 | — | low | revert | test_health; live Health |
| WS13 Performance | indexes, pagination, push fan-out | I.*,PERF-001..004 | M034,M053,M054,M055 | migrations | low | drop index | EXPLAIN; timings |
| WS14 A11y / responsive / RTL | tokens, names, lang/dir, logical props | K,J,M,X01–X05,A11Y-001..004,MOB-002/004,NAV03 | M015,M020,M063,M064 | WS2 | low | css | probe + rtl smoke |
| WS15 Architecture / debt | queue drainer, registry, split helpers, wamid-first, turquai | ARCH-001/002/003/005/006,J9,J11,J13,J17 | M058,M059,M065–M067 | tests first | med | — | deferred with justification |
| WS16 QA / verification | tests, Playwright, sandbox E2E, docs | K1,K9,QA-001..005,UX-QA01..03 | M068–M072 | — | none | — | verification docs |

## 2. Waves (dependency-ordered)

Wave 0 (WS0) → Wave 1 (WS1 + M008/M010/M014 from WS12) → Wave 2 (WS2, incl. composer M032 CSS) → Wave 3 (WS3 + WS4) → Wave 4 (WS5 + WS6 + WS9) → Wave 5 (WS7) → Wave 6 (WS8) → Wave 7 (WS10) → Wave 8 (WS11 + WS12 rest + WS13) → Wave 9 (WS14 completion) → Wave 10 (WS15) → verification (WS16) → reverse audit → gap closure → final.

Each wave ends with: suite run (counts recorded), PHP lint, grep gates, route check, traceability update, entry in `docs/verification/CRM-WAVE-VERIFICATION.md`.

## 3. Release strategy

Feature flags (options, default on after verification): `se_clinic_nav_v2`, `se_clinic_dashboard_v2`, `se_journey_timers`, `se_consent_ads_from_intake_<brand>` (default **off** — decision). Deploy from the Mac per the existing recipe (push main → host `git pull --ff-only` → `touch ~/.lsphp_restart.txt` → `migrate_cli.php --verify/--apply` → `php modules/se_core/tests/run.php`). Migrations are additive (indexes, columns with defaults) and idempotent; a backup snapshot precedes any schema change.

## 4. Test strategy

Fake-DB suite per ticket (new suites: `test_csrf.php`, `test_next_action.php`, `test_ui_helper.php`, `test_journey_timers.php`, `test_hastalar.php`, extensions to appointments/whatsapp/journey/health); PHP lint on every changed file; grep gates (`hekim|doktor|Dr\.|cerrah|klinisyen`, English task literals); Playwright responsive suite on the Mac (`scripts/ui-regression/`); live visual verification vs mockups; sandbox-brand workflow walk with the fake WA transport.

## 5. Decisions required from the owner (blocking only their own tickets)

1. CAPI: may the intake's marketing consent be recorded as purpose `ads`? (M010 option flip.)
2. Pipeline stages to keep in Perfex Leads for admins (M031).
3. turquai-bridge: reconcile or retire (M059).
4. Retention periods per KVKK for journey data (M057).
5. Aftercare protocol approval flag (M046) and long-term follow-up cadence (M056).
