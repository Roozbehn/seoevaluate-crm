# CRM Wave Verification Log

## Wave 0 — Baseline
- Start SHA: 79783a6ca4823c00e5da7d27a9b47eb4f06fea46 (main == origin/main, clean tree on the Mac)
- Branch: feat/master-optimization (container clone from a git bundle of the Mac repo; pushed back via bundle → Mac → origin)
- Baseline suite (container, PHP 8.4 CLI, fake DB): 36 suites, **3 824 pass / 0 fail / 0 skipped**
- Docs created: docs/strategy/{CRM-MASTER-TRACEABILITY,CRM-SOURCE-CONFLICTS,CRM-MASTER-OPTIMIZATION-PLAN}-2026-09-04.md, CRM-MASTER-EXECUTION-BACKLOG.md, traceability_src.py
- Traceability rows: 243 (CRM-AUDIT 134 · UIUX-OPT 19 · DESIGN-SYSTEM 25 · UX-COPY 15 · UIUX-BACKLOG 50)
- Current-state screenshots: docs/design/mockups/current-state/

## Wave 1 — P0/P1 silent failures + security
- Start SHA: 098f80a → End SHA: 5d78e2c (commits 670ec76, 5d78e2c)
- Files changed: modules/se_whatsapp/{outbound.php,helpers.php,se_whatsapp.php,controllers/Se_whatsapp.php}, modules/se_appointments/{types.php (new),se_appointments.php,views/calendar.php,controllers/Se_appointments.php,models/Se_appointments_model.php,language/*}, application/config/{config.php,se_csrf_exempt.php (new)}, application/controllers/Cron.php, modules/se_core/{assets/pwa.js,controllers/Se_pwa.php,se_push.php,se_outbox.php,se_reporting.php,se_outbox_ui.php,se_webhook_state.php,se_media.php,se_google_dm.php,se_meta_leadgen.php,helpers/se_core_helper.php,views/se_reports_health.php}, modules/se_journey/{intake.php,messaging.php,media.php,controllers/Se_journey.php,views/settings.php,language/*,se_journey.php}, modules/se_instagram/se_instagram.php, .htaccess, tests (new: test_csrf, test_health_honesty, test_cron_isolation; extended: wa_outbound, push, journey, journey_flows, journey_staff, whatsapp)
- Tests: fake-DB suite **3 888 pass / 0 fail / 0 skipped** (baseline 3 824; +64 new assertions). PHP lint clean on every changed file.
- Regression proofs: reminder-scan test fails on the pre-fix code (expected 1, got 0); reminder-consumer test uses reminder id ≠ appointment id so the old lookup cannot pass.
- Tickets: M002 M003 (+M040 agenda) M004 M005 M006 (behaviour; composer UI in Wave 2) M007 M008 M009 M010 (engineering; BLOCKED-DECISION for the flip) M011 M012 M013 M014
- Not verifiable from the container (host-only): `curl -I /docs/` 404, APP_CSRF_PROTECTION / APP_COOKIE_SECURE constants, proxy_ips → listed under CRM-M062 for the deploy step.
- Blockers: none new. Decision needed: CRM-M010 option flip (Settings → Süreç ayarları → "Formdaki pazarlama rızası … reklam ölçümü").

## Wave 2 — Shared UX foundation
- Start SHA: 5d78e2c → End SHA: e72814f
- Files: modules/se_core/assets/{se-ds.css (new), se-clinic.js (new), pwa.css}, modules/se_core/se_core.php, helpers/{se_ui_helper.php,se_core_helper.php}, se_chat_ui.php, se_outbound_tracker.php, modules/se_journey/{next_action.php (new), se_journey.php, helpers.php (origin column)}, modules/se_whatsapp/{outbound.php,views/conversation.php}, language files (tr/en) of se_core/se_journey/se_whatsapp/se_appointments, scripts/check-copy.sh (new), tests/{test_next_action.php (new), bootstrap.php}
- Tests: **4 050 pass / 0 fail** (+162 vs Wave 1: next-action table 156, tracker unchanged). Copy gate OK. PHP lint clean.
- Tickets: M015 M016 M017 M018 M019 M020 (Perfex core already emits lang/dir from the staff locale/direction — NOT-APPLICABLE-WITH-EVIDENCE for the head part; patient-block lang deferred) M032 (CSS + composer markup) M037 (auto tag + tracker TR) M063 (names/skip link)
- Visual verification: pending deploy (live check in the verification wave).
- Note: modules/se_journey/views/public/{quote,intake}.php contain patient-facing "klinisyen" wording — approved patient copy, excluded from the staff gate, listed as an owner decision.
