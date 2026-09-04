# CRM Final Closure Inventory — 2026-09-04

Every row of the master traceability that was not VERIFIED at the start of the closure (190 VERIFIED / 10 IMPLEMENTED / 3 IMPLEMENTING / 1 N/A / 11 BLOCKED-DECISION / 28 PLANNED = 53 rows), grouped by master ticket where several source rows share one. Final tree: suite 4 515 / 0 (container), copy gate OK, schema oracle 0 violations. Baseline verified before any change: Mac `main` == `origin/main` == host == `81df601`, clean trees, schema v21, no schema error, cron/dispatcher fresh. Final dispositions use only: VERIFIED, DEFERRED-APPROVED, BLOCKED-OWNER, BLOCKED-LEGAL, BLOCKED-EXTERNAL, NOT-APPLICABLE-WITH-EVIDENCE, FAILED.

| ID | Source | Current status | Why not VERIFIED | Required action | Can close now? | Final disposition |
|---|---|---|---|---|---|---|
| T2/F2 · K7 · AZCRM-PJ-001 (CRM-M010) | CRM-AUDIT | BLOCKED-DECISION | CAPI `ads` consent from the intake marketing tick is a legal interpretation | Engineering: prove with the mapping OFF that nothing transmits, Health not green, reason visible, mapping configurable → `test_outbox.php` 'CAPI consent E2E' (9 assertions) + host option unset. Policy: DEC-001 | Engineering yes; policy no | BLOCKED-LEGAL (engineering VERIFIED) |
| T20/F20 · AZCRM-UX-006 · AZCRM-MOB-003 · UX-L03 (CRM-M031) | CRM-AUDIT / UIUX-BACKLOG | BLOCKED-DECISION | Which Perfex pipeline stages to keep is the owner's call | Verified today: nav v2 hides Leads/Customers for clinic roles; host check shows the Sales role holds `leads: view` (URL-reachable, view only, 7 leads) → DEC-002 with a recommended stage list | No (owner) | BLOCKED-OWNER (current behaviour VERIFIED, no operational impact found) |
| H.L9 · J18 · AZCRM-PJ-006 (CRM-M057) | CRM-AUDIT | BLOCKED-DECISION | Retention periods are legal facts | Engineering mechanism verified and documented in `CRM-KVKK-RETENTION-MATRIX-2026-09-04.md` (archive vs deletion request, sealed media delete, inbox purge, audit, no retention cron); periods → DEC-003 | No (legal) | BLOCKED-LEGAL (mechanism VERIFIED) |
| AZCRM-ARCH-006 (CRM-M059) | CRM-AUDIT | BLOCKED-DECISION | Retire/reconcile turquai-bridge | Evidence: not in the CRM repo (no history), not deployed (Cloudflare Workers list), not referenced (grep); it lives in the growth-os repo → DEC-004 (retire there) | No (other repo, owner) | BLOCKED-OWNER (N/A to the CRM with evidence) |
| J19 · AZCRM-QA-005 (CRM-M068) | CRM-AUDIT | IMPLEMENTED | Host module state and AppleDouble files were not checked | Host: `openai` inactive, `Auto_update` not installed, `find . -name '._*'` = 0; docs refreshed (this closure set); lang content-safety gate in CI | Yes | VERIFIED |
| H.DiD-7 (CRM-M068) | CRM-AUDIT | PLANNED | Same host check | Same evidence as above | Yes | VERIFIED |
| K9 (CRM-M071) | CRM-AUDIT | IMPLEMENTED | Playwright suite never ran authenticated | Responsive regression executed live with Claude-in-Chrome iframes at 390/768/1024/1440/1920 on 10 pages (50 measurements: no overflow, tab-bar rules, composer/send, 44 px targets, names, alt). Playwright itself cannot authenticate safely (the session cookie is now HttpOnly and no credentials are typed) | Yes (verification performed) | VERIFIED |
| AZCRM-QA-003 · UX-QA01 (CRM-M071) | CRM-AUDIT / UIUX-BACKLOG | IMPLEMENTED | The Playwright runner needs a storage state only the owner can export | Runner + README ready; equivalent coverage executed (K9). Milestone: owner exports `state.json` once → `node scripts/ui-regression/responsive.mjs` | No (credential rule) | DEFERRED-APPROVED |
| AZCRM-WF-004 · UX-A04 (CRM-M041) | CRM-AUDIT / UIUX-BACKLOG | IMPLEMENTED | Same-day shortcut prefill not exercised end-to-end | `se_appt_prefill_from()` extracted + 6 tests (copied fields, start = source end, 4 h, query overrides, foreign source → type only); live GET of the create form renders; the auto-held half is N/A-with-evidence (M047) | Yes | VERIFIED |
| AZCRM-WF-006 · UX-P08 (CRM-M030) | CRM-AUDIT / UIUX-BACKLOG | IMPLEMENTED | Reopen would change a real journey | `se_journey_reopen()` extracted + tests (reason required, not_suitable → İnceleme, closed without patient → enquiry, other states refused, transition carries reason/actor); controller uses it | Yes | VERIFIED |
| UX-P06 (CRM-M028) | UIUX-BACKLOG | IMPLEMENTED | Note would write a real event | `se_journey_add_note()` extracted + tests (empty refused, staff actor, 500-char cap, nothing outbound); live CSRF POST of an empty note → validation warning, nothing written | Yes | VERIFIED |
| H.L5 · AZCRM-SEC-005 (CRM-M062) | CRM-AUDIT | IMPLEMENTING | proxy_ips deployed but a visitor IP not yet observed | Host: `tblstaff.last_ip` for all three staff is a 92.44.x.x visitor address (not a Cloudflare edge) | Yes | VERIFIED |
| H.L6 (CRM-M062) | CRM-AUDIT | IMPLEMENTING | APP_COOKIE_SECURE undefined on the host | Set `APP_COOKIE_SECURE=true` + `APP_COOKIE_HTTPONLY=true` after a config backup; cookies observed `secure; HttpOnly; SameSite=Lax`; session persisted, CSRF works, a fresh staff login was recorded at 02:20 after the change | Yes | VERIFIED |
| T12/F12 · AZCRM-PJ-003 (CRM-M050) | CRM-AUDIT | PLANNED | phone_e164 identity column + backfill | Not needed for correctness: search normalises in SQL; dedup-on-write done by lookup (K6). 7 conditions hold (not P0/P1 → P2; no silent failure). Milestone: v23 schema window with backfill | No (approved deferral) | DEFERRED-APPROVED |
| K6 (CRM-M050) | CRM-AUDIT | PLANNED | Website lead did not reuse the WhatsApp lead by phone | Implemented in `se_website_lead_upsert()` + 8 tests (reuse by phone in any format, stamps website id/name/email, consent ledger, repeat = same lead, other phone = new) | Yes | VERIFIED |
| T14/F14 (CRM-M052) | CRM-AUDIT | PLANNED | Messaging CAPI never queued (no WABA on the conversation) | Runtime resolution: number row → brand WABA; tests (queue + `whatsapp_business_account_id`) | Yes | VERIFIED |
| J12 · AZCRM-ARCH-004 (CRM-M052 + M069) | CRM-AUDIT | PLANNED | waba_id phantom; fake DB had no schema | Phantom closed at runtime (above); the schema oracle now knows `se_wa_conversations` has no `waba_id` and would fail any write to it | Yes | VERIFIED (column superseded) |
| H.L8 · AZCRM-SEC-006 (CRM-M060) | CRM-AUDIT | PLANNED | Option-table secret fallbacks | Host confirmed no SE secrets in `tbloptions`; fallbacks removed from `se_capi.php`, `se_meta_leadgen.php`, `se_google_dm.php`; tests use the file store | Yes | VERIFIED |
| I.push (CRM-M054) | CRM-AUDIT | PLANNED | Custom-field sync ran one SELECT per field; push fan-out in request | Values preloaded once per lead (test: 1 SELECT per sync); inbound push already runs in the dispatcher (`se_wa_process_pending` → `handle_inbound`) | Yes | VERIFIED |
| AZCRM-PERF-003 (CRM-M054) | CRM-AUDIT | PLANNED | 'Push fan-out to dispatcher' | Residual: the website-lead POST pushes synchronously (≤ 3 staff subscriptions, 10 s cap, not silent). Milestone: M065 SeQueue | No (approved deferral) | DEFERRED-APPROVED |
| I.cron · AZCRM-PERF-004 (CRM-M055) | CRM-AUDIT | PLANNED | Media backfill/migration every tick | Migrations are version-gated (early return); `media_to_r2`/`journey_media` steps are one indexed, bounded SELECT when nothing is pending (host: 0 local rows) | Yes | VERIFIED (with evidence) |
| J9 · K5 (CRM-M055) | CRM-AUDIT | PLANNED | Media queue without lease | 15-min lease, atomic claim, dead-worker recovery + 4 tests | Yes | VERIFIED |
| J13 · AZCRM-ARCH-001/002/003 (CRM-M065/066/067) | CRM-AUDIT | PLANNED | SeQueue / registry / helper split are pure refactors | 7 conditions hold (P2/P3, no user-visible gain, no silent failure). Milestone: post-closure refactor window, tests-first | No (approved deferral) | DEFERRED-APPROVED |
| J15 · AZCRM-PJ-004 (CRM-M051) | CRM-AUDIT | PLANNED | Lead Ads path skipped `lead_created` and the dispatcher | Dispatcher `leadgen` step; new ad lead fires `lead_created` once + push; journey auto-start for ad leads behind `se_journey_auto_start_ads_<brand>` (default off); tests (consent, autostart, dispatch) | Yes | VERIFIED |
| K1 · AZCRM-QA-001 (CRM-M069) | CRM-AUDIT | PLANNED | Schema oracle in the fake DB (P1) | Implemented: 44 SE tables from the real statement sources; strict by default; zero violations; self-test | Yes | VERIFIED |
| AZCRM-PJ-005 (CRM-M056) | CRM-AUDIT | PLANNED | Long-term follow-up cadence undefined | DEC-005 (months) | No (owner) | BLOCKED-OWNER |
| AZCRM-WF-007 · UX-P07 (CRM-M029) | CRM-AUDIT / UIUX-BACKLOG | PLANNED | Meta Flow JSON must be updated and re-approved at Meta | External approval; current path is 4 clicks and works | No (external) | BLOCKED-EXTERNAL |
| AZCRM-OBS-005 (CRM-M074) | CRM-AUDIT | PLANNED | External uptime check needs an external account | Owner account at an uptime service; dispatcher heartbeat is already exposed on Health | No (external) | BLOCKED-EXTERNAL |
| UX-W09 (CRM-M038) | UIUX-BACKLOG | PLANNED | Instagram as a tab of Mesajlar | Implemented today (owner-reported regression): channel switch, unread counts, Bugün rows, page shell; tests + live | Yes | VERIFIED |
| J17 (CRM-M065) | CRM-AUDIT | NOT-APPLICABLE-WITH-EVIDENCE | Two clocks in one row | Mitigated by `se_db_clock_offset()`; unchanged | — | NOT-APPLICABLE-WITH-EVIDENCE |

## Result (243 rows)

| Disposition | Rows |
|---|---|
| VERIFIED | 218 |
| NOT-APPLICABLE-WITH-EVIDENCE | 1 |
| DEFERRED-APPROVED | 9 |
| BLOCKED-LEGAL | 6 |
| BLOCKED-OWNER | 6 |
| BLOCKED-EXTERNAL | 3 |
| FAILED | 0 |
| IMPLEMENTED / IMPLEMENTING / PLANNED / BLOCKED-DECISION | **0** |

**ENGINEERING CLOSURE: 230 / 243 = 94.7 %** — VERIFIED + N/A (219) plus the 11 policy-blocked rows whose engineering side is verified (consent E2E ×3, KVKK mechanism ×3, Leads visibility ×4, turquai evidence ×1). Not counted: 9 approved deferrals, follow-up cadence (M056), Meta Flow (M029 ×2), external uptime (M074).

**TOTAL POLICY CLOSURE: 219 / 243 = 90.1 %** — rows that need nothing further from anyone (VERIFIED + N/A). Counting the 9 approved deferrals as closed: 228 / 243 = 93.8 %. The remaining 15 rows wait on DEC-001…DEC-005 (12) and two external parties (3).

## DEFERRED-APPROVED — the seven conditions, per row

| Row(s) | Not needed for production correctness | UX target intact | Not P0/P1 | No silent failure | No security/privacy risk | Future milestone | Documented |
|---|---|---|---|---|---|---|---|
| T12/F12, PJ-003 (phone_e164 column) | ✓ search normalises in SQL, dedup by lookup | ✓ | ✓ P2 | ✓ | ✓ | v23 schema window + backfill | this file |
| J13, ARCH-001/002/003 (SeQueue, registry, split) | ✓ refactors | ✓ | ✓ P2/P3 | ✓ | ✓ | post-closure refactor window, tests-first | this file |
| PERF-003 (website-lead sync push) | ✓ bounded (≤ 3 subs × 10 s) | ✓ | ✓ P3 | ✓ (push failure is logged; lead is saved first) | ✓ | M065 | this file |
| QA-003, UX-QA01 (Playwright run) | ✓ coverage executed via Chrome | ✓ | ✓ P2 | ✓ | ✓ (no credentials typed) | owner exports storage state once | README + this file |
