# CRM Final Gap Closure — 2026-09-04

**Method (reverse audit).** Every row of `docs/strategy/CRM-MASTER-TRACEABILITY-2026-09-04.md` (243 rows from the five source documents) was re-read against the code on `main` (`b3994eb` + follow-ups), the harness (4 380 assertions) and the live pass (`CRM-UIUX-VISUAL-VERIFICATION-2026-09-04.md`). A row is VERIFIED only with an artefact (test group, live measurement, host check); IMPLEMENTED when the code exists but was not exercised end-to-end; PLANNED / BLOCKED-DECISION / NOT-APPLICABLE-WITH-EVIDENCE with the reason below. Nothing was dropped: every requirement keeps its row.

**Totals:** VERIFIED 190 · IMPLEMENTED 10 · IMPLEMENTING 3 · NOT-APPLICABLE-WITH-EVIDENCE 1 · BLOCKED-DECISION 11 · PLANNED 28 (of 243).

## 1. Gaps found by the reverse audit and closed in this pass

| # | Found by | Gap | Closure | Commit |
|---|---|---|---|---|
| G1 | live Bugün | Sistem card "0 WhatsApp message(s)" with 6 failed: Perfex `_l()` consumes placeholders | all 21 `sprintf(_l())` sites, `$L` closures, `se_tr()` → arguments through `_l`; copy-gate rule | `4850753` |
| G2 | live 390/1024 | Hastalar horizontal scroll (+68/+117 px) | `.se-sr` overflow fix (`th,td{position:relative}`) | `07b87d9` |
| G3 | live 390 | patient "Diğer" panel rendered while closed | `.se-more:not([open]) .se-more-panel{display:none}` | `07b87d9` |
| G4 | live 1440 | thread opened → whole page scrolled to the bottom, composer out of view | `.se-wa` fixed height, thread column is the scroll container | `07b87d9` |
| G5 | live form | JS hints "Ends: " / "Default duration for  is ." | literal `%s` passed through `_l` | `07b87d9` |
| G6 | live | edge served the old `se-ds.css?v=1.0.0` | assets versioned by DS version + mtime | `b3994eb` |
| G7 | probe | inactive badge 3.8:1 | `--se-inactive` #bdbdc6 → 5.3:1 | follow-up |
| G8 | probe 390 | chips 36 px, inputs/buttons 40 px, pills 22 px, name links 20 px | 44 px on ≤767 | `07b87d9` + follow-up |
| G9 | host | light theme selector unknown | plugin stamps `html[data-perfex-theme=light]` → added to the light token block | follow-up |
| G10 | host | `proxy_ips` empty behind Cloudflare (H.L5 / SEC-005) | Cloudflare ranges in `config.php` (`APP_PROXY_IPS` override) | follow-up |
| G11 | wave 2 leftover | task titles stored in English, Turkish `se_task_*` strings unused | `se_journey_open_tasks()` maps by kind via `se_tr` | Wave 7 |
| G12 | wave 4 review | `quote_expired` existed in the UI map but not in the state machine | state + transitions + lead sync + timers | Wave 7 |

## 2. Rows left IMPLEMENTED (code present, not exercised end-to-end on production — by the no-production-writes rule)

| Row(s) | Ticket | Why not VERIFIED | How to verify in 2 minutes |
|---|---|---|---|
| UX-P06 | M028 internal note | would write a real event on a real patient | add a note on a test journey; it appears first in Geçmiş as "Not" |
| UX-P08, AZCRM-WF-006 | M030 reopen | would change a real journey | reopen a closed test journey with a reason; state → İnceleme, reason in the transition |
| UX-A04, AZCRM-WF-004 (UI part) | M041 same-day shortcut | would create a real appointment | agenda → "Bugün işlem planla" → form arrives prefilled → save |
| K9, AZCRM-QA-003, UX-QA01 | M071 Playwright | needs the owner's one-time storage-state export (no credentials typed by the program) | `scripts/ui-regression/README.md` |
| J19, AZCRM-QA-005 | M068 docs/AppleDouble | docs refreshed under docs/; AppleDouble files not present in the repo tree; host module check (Auto_update/openai) is a cPanel action | `find . -name '._*'` on the host; disable the two modules in Setup → Modules |

## 3. IMPLEMENTING

| Row(s) | State | Remaining |
|---|---|---|
| H.L5, AZCRM-SEC-005 | `proxy_ips` set to Cloudflare ranges (deploys with the final push) | confirm one audit row shows a visitor IP, not 172.x/104.x |
| H.L6 | `cookie_secure` reads `APP_COOKIE_SECURE`, undefined on the host → false | **owner, host file (not in git):** add `define('APP_COOKIE_SECURE', true);` to `application/config/app-config.php`, then `touch ~/.lsphp_restart.txt`. Safe: the site is HTTPS-only behind Cloudflare. |

## 4. NOT-APPLICABLE-WITH-EVIDENCE

| Row | Evidence |
|---|---|
| J17 "two clocks in one row" | mitigated by `se_db_clock_offset()` (display = app clock, scheduling = DB clock, documented in `se_wa_record_outbound`); a full clock unification is the SeQueue refactor (M065, planned) |
| AZCRM-WF-004 automation part (M047 auto-held) | marking a consultation *held* automatically at its end time would record no-shows as held; replaced by the `held_unrecorded` timer task (2 h after end) + the one-click "Görüşme yapıldı" prompt (`?held=1`) |

## 5. BLOCKED-DECISION (owner / legal)

| Decision | Rows | What happens meanwhile | Where to flip |
|---|---|---|---|
| D1 CAPI `ads` consent from the intake's marketing checkbox | T2/F2, K7, AZCRM-PJ-001 (M010) | conversions keep being **skipped with reason `consent_blocked`**, visible on Bugün/Health; nothing is sent to Meta with a purpose the patient did not grant | Settings → Süreç ayarları → "Formdaki pazarlama rızası … reklam ölçümü" (per brand, default off) — after the lawyer confirms the intake text covers measurement |
| D2 Perfex Leads trim for admins (pipeline stages to keep) | T20/F20, AZCRM-UX-006, AZCRM-MOB-003, UX-L03 (M031) | Leads/Customers are hidden for clinic roles (nav v2) and remain reachable for admins under Yönetim; the 14 English stages are untouched | name the stages to keep; M031 is a small follow-up |
| D3 KVKK retention periods | H.L9, J18, AZCRM-PJ-006 (M057) | erasure exists for sealed media (R2) and patient records (archive); journey health data has no automatic retention | periods per data class from the lawyer → M057 migration + cron |
| D4 turquai-bridge | AZCRM-ARCH-006 (M059) | not deployed, not referenced by the CRM | retire (delete `services/turquai-bridge`) or reconcile its target |
| D5 Aftercare protocol approval | (M046 gate) | auto-start is live but idle until a protocol is marked approved; staff get the "start the plan" task instead | Settings → Bakım protokolleri → approve |

## 6. PLANNED (with the reason each stays open)

| Ticket | Rows | Reason |
|---|---|---|
| M050 phone_e164 (+K6, T12) | identity column + backfill | needs a backup window and a one-off backfill over leads; search already normalises in SQL (`REPLACE` chain), so the user-visible gap is closed; the dedup-on-write part remains |
| M052 waba_id (+T14, J12, ARCH-004) | column + webhook write | only matters when messaging CAPI is turned on (BLOCKED-DECISION D1 first) |
| M051 Lead Ads → dispatcher | J15, PJ-004 | credential-gated path (Meta Page token + App Review) — cannot be exercised |
| M054 push fan-out / batch custom fields | I.push, PERF-003 | no measured problem at 2–3 staff; do with M065 |
| M055 media lease + conditional backfill | I.cron, J9, K5, PERF-004 | cron steps are cheap today (`media_to_r2: 0 moved`); refactor with M065 |
| M056 long-term follow-up state | PJ-005 | needs the cadence decision (D5) |
| M060 secret fallbacks | H.L8, SEC-006 | must first confirm on the host which secrets still live only in `tbloptions` (`~/bin/se-secret-install.sh` exists; a blind removal could break sending) |
| M065/M066/M067 architecture | ARCH-001/002/003, J13 | pure refactors, tests-first; no user-visible gain in this program |
| M069 schema oracle in the fake DB | K1, QA-001 | harness improvement; the `_l()` bug above shows the value — recommended next |
| M074 external uptime check | OBS-005 | external service (owner account) |
| M029 photo acceptance in one step | WF-007, UX-P07 | Meta Flow JSON must be updated and re-approved at Meta |
| M038 Instagram as a Mesajlar tab | UX-W09 | Instagram keeps its own inbox; the shared chat UI already restyles it |
| M062 host constants | (see §3) | host-only |

## 7. Vocabulary and safety re-checks (program §22)

- `scripts/check-copy.sh` OK on the final tree: no hekim/doktor/Dr./cerrah/klinisyen in staff strings or staff views; no retired vocabulary. Patient-facing public views still contain approved patient copy with "klinisyen" (owner decision, out of the staff gate).
- No automated clinical decision anywhere: review decisions, suitability, "held", aftercare start (protocol approval) stay human or gated.
- No `ads` consent granted without the explicit per-brand option (default off); no consent recorded by hand on the KVKK tab (read-only by design).
- No patient message sent by the program; the fake transport only in the harness.
- No production row deleted or edited by hand; the only schema change is additive (v21 indexes) after a backup (`~/backups/pre-wave10-20260904-003617.*`).
