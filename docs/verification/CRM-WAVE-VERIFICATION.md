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

## Wave 3 — Navigation v2 + Bugün
- Start SHA: e72814f → End SHA: dbd0950
- Files: modules/se_core/{se_navigation.php, se_clinic.php (tab bar, thread body class, hidden slugs), controllers/Se_dashboard.php (today()), views/se_today.php (new), se_outbox_ui.php (today appointments / unread / Sistem card), assets/se-ds.css (pipe links), language tr/en}, modules/se_journey/{health.php (attention queue, stage counts, terminal states), language tr/en}, tests/{test_today.php (new), test_clinic.php, fake_db.php (where_not_in), bootstrap.php}
- Tests: **4 105 pass / 0 fail** (+55 vs Wave 2: today 41, clinic v2 nav 14). Copy gate OK. PHP lint clean.
- Tickets: M021 (nav v2, flag `se_clinic_nav_v2`) M022 (tab bar; hidden in thread) M023 (Bugün, flag `se_clinic_dashboard_v2`) — plus AZCRM-UX-001 (query with cap), UX-005 (Sistem card shows only actionable items), OBS-002 (stuck rows: overdue review = p1 after 3 days, `held_unrecorded`, `welcome_stale`).
- Queue behaviour proven: brand scope fail-closed (unmapped staff → 0 rows), terminal states excluded, patient-owned steps only surface with an inbound unanswered >30 min, priority→age order, cap keeps `total`, every row has one button + accessible name, phone masked when no name.
- Perf: the queue is 6 batched queries (journeys, quotes, appointments, failed sends, leads, unread) — no per-row query; `build_ms` is printed for admins on the page so the <600 ms target is measured live in the verification wave.
- Deferred inside this wave: "+ Hasta" links to Perfex Leads until Hastalar (Wave 4) provides its own create path; the "Tümünü gör" link targets `se_core/se_hastalar?sort=attention` (Wave 4).

## Wave 4 — Hastalar + patient workspace (+ quote read-only)
- Start SHA: a4e44fd → End SHA: 5d983cc
- Files: modules/se_core/{se_hastalar.php (new), controllers/Se_hastalar.php (new), views/se_hastalar.php (new), se_core.php (loader), se_chat_ui.php (`back`), assets/se-ds.css, language tr/en}, modules/se_journey/{health.php (se_journey_batch_context, se_journey_attention_row_from — shared by Bugün and Hastalar), ui.php (se_journey_timeline_human), controllers/Se_journey.php (view() rewrite, note, reopen), views/view.php (rewrite), language tr/en}, modules/se_whatsapp/controllers/Se_whatsapp.php (reply_back), tests/{test_hastalar.php (new), test_workspace.php (new), fake_db.php, bootstrap.php}
- Tests: **4 178 pass / 0 fail** (+73 vs Wave 3). Copy gate OK. PHP lint clean.
- Tickets: M024 M025 M026 M027 M028 M030 M049 (Sales read-only) — M048 partially (expiry alert in the header; the `quote_expired` state transition arrives with the timers in Wave 7) — M029 stays PLANNED (Flow JSON at Meta).
- Proven: phone search in any formatting (spaces, dashes, parentheses, +90/0 prefixes); scope fail-closed; chips/sort/pagination; masked phones for staff without `view_health`; every row has one button; timeline shows no raw kinds, no "a → b" arrows, hides token/lead-sync/other-brand noise, truncates previews; automatic vs staff vs patient actors.
- Reply from the Sohbet tab: the composer posts to the existing `se_whatsapp/reply/<id>` route (policy unchanged) with a same-site `back` field; the controller only follows a `back` that starts with admin_url().
- Not testable in the harness (controllers are outside it): the view files were linted and read against the mockups; live check in the verification wave.

## Wave 5 — Mesajlar
- Start SHA: eb7f799 → End SHA: 3cca012
- Files: modules/se_whatsapp/{inbox.php (new), controllers/Se_whatsapp.php (render_inbox, conversation → same page, reply_back), views/inbox.php (rewrite), views/conversation.php (removed), se_whatsapp.php (loader), language tr/en}, modules/se_journey/{ui.php (contextual actions, context column), language tr/en}, modules/se_core/{assets/se-ds.css (sheet, ctx actions), se_clinic.php (se-in-thread for inbox?c=), tests/{test_inbox.php (new), fake_db.php, bootstrap.php}}
- Tests: **4 228 pass / 0 fail** (+50). Copy gate OK. PHP lint clean.
- Tickets: M032 (markup/CSS complete; Playwright 390 assertions in the verification wave) M033 M034 M035 M036 M037 (done in Wave 2) — M038 Instagram tab stays PLANNED.
- Proven: brand scope fail-closed; newest-inbound order; exact last-message preview (image after text); chips incl. legacy links; search by name / formatted phone / wa id; 50-row page + non-overlapping cursor page; thread newest-100 + older cursor; foreign-brand thread yields nothing; state → ≤4 buttons, next action first and primary, POST actions carry the return tab, view-only roles get no mutating buttons, paused automation offers Resume, closed offers only Reopen.

## Wave 6 — Randevular
- Start SHA: f45e2e3 → End SHA: 51eae96
- Files: modules/se_appointments/{availability.php (first conflict, next free slot), types.php (se_tr), models/Se_appointments_model.php (last_reason/last_message), controllers/Se_appointments.php (prefill, normalise_post, form_error, after_save, names), views/{form.php (rewrite), manage.php, view.php}, language tr/en}, modules/se_journey/consultation.php (se_journey_link_appointment, start-aware salt, reschedule confirmation), modules/se_core/assets/se-ds.css, tests/{test_appointments.php, test_journey_staff.php}
- Tests: **4 247 pass / 0 fail** (+19). Copy gate OK. PHP lint clean.
- Tickets: M039 M041 M042 M043 M044 (M040 agenda done in Wave 1; M003 calendar done in Wave 1).
- Proven: conflict row + patient name; other staff free; ignore-self on edit; next free slot skips busy and cancelled-not-blocking; quarter-hour rounding; day-full → ''; message contains who/when/what/next; model names the refusal and the message on a real overlap; reschedule sends exactly one new confirmation, same-slot save none, location edit none.
- Not in harness: the form's JS (duration/end hint) and the re-render path — checked by lint + live in the verification wave.
