# Azin Asgari CRM — Production Sign-off (final closure, 2026-09-04)

## 1. Final status

**READY-WITH-BLOCKED-POLICY-DECISIONS.** The CRM is deployed, verified and in daily use; every engineering gap that could be closed without an owner/legal decision is closed and verified. Fifteen traceability rows wait on owner/legal answers (DEC-001…DEC-005) or external parties (Meta Flow re-approval, an uptime service); nine rows are approved deferrals with a written milestone. Not "program complete": the policy rows are open by nature, and one automated runner (Playwright) is unexecuted by rule.

## 2. Code

| | SHA |
|---|---|
| Program start | `79783a6` |
| Previous (start of this closure) | `81df601` (Mac `main` == `origin/main` == host, clean, schema v21 — verified before any change) |
| Closure commits | `58c1e8d` engineering gaps · `cfcc8f4` Instagram in Mesajlar · `d69df6e` v22 + consent E2E + light theme · `884e7a3` DS anchor/chip colours · `68a5ced` schema oracle · `4fca98f` closure docs · **final** = the commit carrying this sign-off (docs + next-action consistency test + one harness tolerance) |
| Branch | `feat/master-optimization`, fast-forwarded into `main`, both pushed to `origin` |
| **Production SHA** | **`4fca98f`** on crm.roozbeh.com.tr (schema **v22**, host suite 4 505/0, copy gate OK, LiteSpeed restarted). The final commit after it changes documentation and tests only; it was pushed to `origin/main` but **not pulled to the host** — SSH to the host began refusing the deploy key at the very end of the closure (see §7 / §9). Runtime code on the host == final. |

## 3. Traceability (243 rows)

| Disposition | Rows |
|---|---|
| VERIFIED | 218 |
| NOT-APPLICABLE-WITH-EVIDENCE | 1 |
| DEFERRED-APPROVED | 9 |
| BLOCKED-LEGAL | 6 |
| BLOCKED-OWNER | 6 |
| BLOCKED-EXTERNAL | 3 |
| FAILED | 0 |
| IMPLEMENTED / IMPLEMENTING / PLANNED / BLOCKED-DECISION | 0 |

**ENGINEERING CLOSURE 230 / 243 = 94.7 %** (VERIFIED + N/A + the 11 policy-blocked rows whose engineering side is verified). **TOTAL POLICY CLOSURE 219 / 243 = 90.1 %** (rows needing nothing from anyone); 93.8 % if approved deferrals are counted as closed. Start of closure: 190 VERIFIED (78.2 %). Per-row reasoning: `CRM-FINAL-CLOSURE-INVENTORY-2026-09-04.md`.

## 4. Verification

| Area | Result |
|---|---|
| Automated suite | **4 515 / 0** on the container (PHP 8.4.21) for the final tree; **4 505 / 0** on the Mac (PHP 8.2.29) and the host (PHP 8.1.34) for `4fca98f` — the 10 extra assertions are the next-action consistency group added in the final commit. Program start 3 824 → 4 380 (handover) → 4 515. Output clean (0 warnings/deprecations); copy gate OK on all three; PHP lint clean; **schema oracle strict: 0 violations** across 44 SE tables |
| Responsive (live, authenticated Chrome, same-origin iframes at exact widths) | 10 pages × 390/768/1024/1440/1920 = 50 renders: horizontal overflow **0 px everywhere**; tab bar visible ≤ 767 and hidden ≥ 768 (DS rule); thread at 390 hides the tab bar, composer 86 % width, Send visible; 44 px controls on phones inside the shell (only Perfex header chrome and visually-hidden radio inputs are smaller); 0 unnamed controls, 0 images without alt inside the shell after labelling the two Perfex header buttons; FullCalendar at ≥ 768, agenda at 390; no `.alert-danger` on any page. Playwright runner unexecuted (credential rule) — README documents the equivalent run |
| Dark theme (real, after the CSS fixes) | 10 pages × 390/1440: **no text/background pair under 4.5:1** among heading, body, buttons (primary/secondary/ghost), badges, state chips, chips (on/off), inputs, alerts, links, tab bar, tabs, avatar, stage bar, timeline, bubbles |
| Light theme (real `perfex_dark_theme` light mode, toggled in the browser and restored) | Same 20 renders: **no pair under 4.5:1**. Fixed during closure: anchor buttons took the plugin's `body a{!important}` link colour (1.2:1 light / 1.7:1 dark), chip.on/avatar white-on-soft, tabs.active white, hard-coded dark tab bar, pastel calendar type colours, warning alert 4.4:1, `--se-text-3` 4.4:1 |
| Accessibility (WCAG 2.1 AA-oriented regression verification; no certification claimed) | contrast as above; names/alt 0 gaps in the shell; focus ring rule present; skip link; reduced-motion block; header controls named |
| RTL smoke (`dir=rtl` injected; no staff record changed) | 5 pages × 390/1200: overflow 0; page titles mirror right and actions left on desktop; phone numbers isolated LTR (`bdi`); chat bubbles use logical flex alignment (`.se-msg.out{justify-content:flex-end}`) so sides mirror by construction; composer 86 % at 390 |
| Security | `CRM-SECURITY-SIGNOFF-2026-09-04.md`: 10/10 PASS (one with an owner confirmation, one with a precaution) |
| Appointments / reminders | Isolated E2E in the harness: appointment → reminder (correct appointment id) → approved template → outbound queue → fake transport → outbound `sent` with wamid → **reminder `sent`** (new v22 back-link); cancelled appointment → skipped, no message; permanent transport failure → reminder `failed` with reason. Live calendar: feed 200 with 2 events, FullCalendar renders them at 1440 (4 legend types), agenda with items at 390, event click carries a URL, **0 console errors**; conflict message + next free slot in the harness |
| WhatsApp / mobile | Live 390 thread: textarea 86 % width, Send visible, tab bar hidden, no overflow, context strip/sheet present; desktop 1440: list / thread / context columns, contextual actions from the same engine. No message sent to any patient by the program |
| Patient workspace | Live at 5 widths (overflow 0, names 0, alt 0); note/reopen helpers tested; light/dark clean |
| Bugün / Hastalar | Live at 5 widths; Instagram threads now counted and listed; next-action consistency test: same key/sentence/URL/button on Bugün, Hastalar, Mesajlar context and the workspace, no duplicate rows |
| Observability / Health | Sistem card live: "Dispatcher ✓ 39 sn · Cron ✓ 5 dk · WhatsApp ✓ · Instagram ✓"; 7 conversions skipped **by reason (consent)** — visible, not green; failed WhatsApp sends listed with a Review link |
| Performance (live, 3 samples, no-store) | Bugün 219–313 ms round-trip / 4 ms build · Hastalar ~225 ms / 4 ms · Mesajlar ~210 ms / 4 ms · thread ~240 ms / 8 ms (134 KB) · workspace ~235 ms · calendar ~230 ms · Instagram ~237 ms · Health 218–771 ms. Targets Bugün < 600, inbox < 500 ✓. Harness N+1 guards: Hastalar 7 SELECTs for 25 rows, inbox 9 for 50 rows (no table > 2×); lists bounded (25 / 50 / 100) |
| Database / migration | v21 → **v22** (`se_reminders.outbound_id` + index, additive, `IF NOT EXISTS`): backup `~/backups/pre-wave10-20260904-014806.sql.gz` + files before apply; `--dry-run` 128 statements; `--apply` twice (second run no-op, version stays 22); `--verify` OK; `se_core_schema_error` empty; PHP 8.1 lint/suite green on the host |
| Logs | Host review at 01:48–02:30: `application/logs` empty (index.html only); home `error_log` holds only the closure's own earlier CLI attempts (`APPPATH` from a `php -r` include), nothing from the web app; no PHP error after any of the three deploys. **Later check blocked** by the SSH refusal (§7) — the live Sistem card (dispatcher 39 s, cron 5 min) stands in |

## 5. Outstanding owner decisions

DEC-001 CAPI `ads` consent (legal) · DEC-002 Perfex Leads stages + Sales `leads: view` · DEC-003 KVKK retention periods (legal) · DEC-004 turquai-bridge in growth-os · DEC-005 aftercare protocol approval + follow-up cadence · DEC-006 confirm one fresh login after the cookie hardening · DEC-007 cron-key rotation. Answer sheet: `docs/decisions/CRM-OWNER-DECISION-SUMMARY.md`.

## 6. Approved deferrals (9 rows, all seven conditions checked in the inventory)

phone_e164 identity column (T12/F12, PJ-003) — search normalises in SQL, dedup-on-write done · SeQueue / registry / helper split (J13, ARCH-001/002/003) — pure refactors · website-lead synchronous push (PERF-003) — bounded, not silent · Playwright runner execution (QA-003, UX-QA01) — coverage executed via Chrome; owner exports a storage state to run it.

## 7. Known risks

1. **Host SSH access was refused ("Permission denied (publickey)") at the very end of the closure**, after the last successful deploy and verification of `4fca98f`. The key is offered and rejected; nothing in the closure touched `~/.ssh`. The site itself is healthy (live checks after the refusal). Until access is restored, the final docs-only commit is not on the host and the post-deploy log review could not be repeated. Owner action: check cPanel → SSH Access / authorized keys (and any CSF/cPHulk block for the Mac's IP).
2. One more failed WhatsApp send appeared during the day (7 failed / 75 sent vs 6 / 74 at the start) — normal staff activity, reason visible on the thread; not related to closure code (send classification unchanged).
3. `storage/backups/` on the host is untracked (consent-config backups from 2026-09-02); returns 404 over HTTP; should be git-ignored on the next pass.
4. Cookie hardening: a fresh staff login was recorded at 02:20 after the change (`tblstaff.last_login`), but the program itself never logged in — DEC-006.
5. The cron key value was displayed once in the working session — DEC-007 (rotate).
6. Perfex Leads screen remains URL-reachable (view only) for the Sales role — DEC-002.
7. The `whatsapp-webhook` Cloudflare Worker (2026-08-01) still exists in the account; the CRM does not use it — clean up with DEC-004.

## 8. Rollback

- **Code:** host `git checkout 81df601` (start of closure) or `79783a6` (program start); each closure commit is coherent and revertable on its own.
- **Data:** `~/backups/pre-wave10-20260904-013102.sql.gz` (before the Instagram deploy), `~/backups/pre-wave10-20260904-014806.sql.gz` + `-files.tgz` (before v22). v22 is additive: `ALTER TABLE tblse_reminders DROP COLUMN outbound_id` restores v21 exactly (data untouched); set `se_core_schema_version` back to 21 only if the column is dropped.
- **Config:** `~/backups/app-config.php.pre-cookie-20260904-012718` (or delete the two `APP_COOKIE_*` lines) + `touch ~/.lsphp_restart.txt`.
- **Feature flags (no deploy):** `se_clinic_ds=0`, `se_clinic_nav_v2=0`, `se_clinic_dashboard_v2=0`, `se_journey_timers=0`, `se_journey_auto_start_ads_<brand>` (stays 0), `se_consent_ads_from_intake_<brand>` (stays 0). The Instagram channel switch has no flag (it follows `se_instagram` permissions).

## 9. Final recommendation

Keep production on `4fca98f` (== final runtime). Restore SSH access, pull the final commit (docs/tests only), answer DEC-001…DEC-007 — DEC-002 and DEC-005 are quick wins, DEC-001/DEC-003 need the lawyer. Then the two small follow-ups (Leads trim ~1 h, retention job ~1 day) close the remaining engineering rows.
