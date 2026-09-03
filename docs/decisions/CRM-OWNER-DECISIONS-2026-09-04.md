# Azin Asgari CRM — Owner Decisions (final closure, 2026-09-04)

These are the only items the engineering closure could not resolve on its own. Every one is a business or legal policy choice; the technical side is finished and verified (see `docs/verification/CRM-FINAL-CLOSURE-INVENTORY-2026-09-04.md`). Nothing below blocks daily use of the CRM. Recommended options are technical/product judgements only; where the law decides, the line says "Requires legal confirmation." and no recommendation is given.

The short answer sheet is `CRM-OWNER-DECISION-SUMMARY.md`.

---

## DEC-001 · Advertising-measurement consent (`ads`) from the intake's marketing checkbox

- **Subject:** Whether the "pazarlama iletişimi" tick on the WhatsApp/web intake may also count as consent for *advertising measurement* (Meta Conversions API, Google Ads offline conversions).
- **Current state:** The per-brand switch `se_consent_ads_from_intake_<brand>` is **OFF** (verified on the host: the option is unset). Every conversion of an intake lead is queued but **skipped with reason `consent_blocked`**; nothing is transmitted (harness E2E proof: `test_outbox.php` "CAPI consent E2E"). The Health page shows the skipped count by reason and names this decision; a staff re-queue is refused. Rows: T2/F2, K7, AZCRM-PJ-001 (CRM-M010).
- **Why a decision is required:** Whether a marketing-communication consent text covers measurement/profiling is a KVKK/GDPR interpretation of the *wording of the intake text*, not an engineering question.
- **Option A — keep OFF (status quo):** benefits: zero legal exposure, nothing changes; risks: ad platforms receive no offline conversions, campaign optimisation stays blind; technical consequence: none (rows stay `consent_blocked`, visible on Health).
- **Option B — switch ON per brand (Süreç ayarları → "Formdaki pazarlama rızası … reklam ölçümü"):** benefits: conversions flow to Meta/Google for leads who ticked marketing; risks: only valid if the lawyer confirms the intake text covers measurement — otherwise unlawful processing; technical consequence: one option flip, no deploy; `ads` is then granted from the tick and withdrawn when unticked (harness-proven).
- **Option C — ON only after the intake text is amended** (lawyer supplies a sentence naming measurement; the text version is bumped; only *new* consents count): benefits: clean basis; risks: existing leads never send; technical consequence: text edit in Süreç ayarları + version bump (supported), then Option B.
- **Recommended option:** Requires legal confirmation. (Technically C is the cleanest.)
- **Exact owner answer required:** A / B / C — and for B/C the lawyer's written confirmation.

## DEC-002 · Perfex Leads pipeline for administrators

- **Subject:** The stock Perfex Leads screen (14 English pipeline stages: New … Reviewed, Customer) is still reachable by administrators under Yönetim, and the Sales role holds `leads: view` (verified on the host: role 4 permissions), so a Sales user can open `/admin/leads` by URL even though the clinic navigation hides it.
- **Current state:** Clinic navigation v2 hides Leads/Customers for clinic roles; the journey syncs stage/consent/quote into the lead as read-only custom fields; Hastalar is the working list. Rows: T20/F20, AZCRM-UX-006, AZCRM-MOB-003, UX-L03 (CRM-M031).
- **Why a decision is required:** Which stages to keep (or whether to keep the Leads screen at all) changes what staff see and what reports mean; deleting stages is data-affecting.
- **Option A — leave as is (DEFERRED):** benefits: nothing to migrate; risks: two vocabularies exist for admins; the Sales role can reach an English screen by URL (no data risk: the role has view only); technical consequence: none.
- **Option B — trim to the journey's stages** (keep: New, WhatsApp Engaged, Qualified, Photos Received, Quote Sent, Consultation Booked, Consultation Held, Treated, Follow-up; retire Contacted, Deposit Paid, Travel Booked, Reviewed) and remove `leads: view` from the Sales role: benefits: one vocabulary; risks: leads in retired stages must be remapped first (7 leads today — trivial); technical consequence: a small follow-up (CRM-M031: stage remap + Turkish labels + role permission), ~1 h, after a backup.
- **Recommended option:** B.
- **Exact owner answer required:** A / B — and for B, confirm the stage list above (or edit it).

## DEC-003 · KVKK retention periods per data class

- **Subject:** How long each class of personal data is kept, and what happens after (deletion vs. anonymisation).
- **Current state:** Mechanisms exist and are verified (see the matrix in `docs/verification/CRM-KVKK-RETENTION-MATRIX-2026-09-04.md`): patient archive with a separate *deletion-request* stamp, sealed photo deletion from R2, inbox copy purge after sealing, access logging. **No automatic retention job runs**, because no period is defined; nothing is deleted on its own. Rows: H.L9, J18, AZCRM-PJ-006 (CRM-M057).
- **Why a decision is required:** Retention periods are legal facts (KVKK, health-data rules, tax/accounting law for quotes/invoices); inventing them would be wrong.
- **Option A — periods from the lawyer, then a scheduled job** (CRM-M057: per-class period → nightly job that anonymises/deletes past the period, with an audit line): benefits: compliance by construction; risks: none once periods are correct; technical consequence: one migration + cron step, tests-first, ~1 day.
- **Option B — manual only (status quo):** benefits: none beyond simplicity; risks: data kept indefinitely; technical consequence: none.
- **Recommended option:** Requires legal confirmation. (A once the periods exist.)
- **Exact owner answer required:** The filled "Retention period" column of the matrix (from the lawyer) and A / B.

## DEC-004 · `turquai-bridge` (in the *growth-os* repository)

- **Subject:** A Cloudflare Worker source `services/turquai-bridge` that targets `crm.turquai.com`.
- **Current state (evidence):** It is **not in the CRM repository** (`git ls-files services` → crm-dispatch, crm-media only; no history of it), **not deployed** (Cloudflare account Workers: crm-media, crm-dispatch, azin-web, whatsapp-webhook — no turquai-bridge), and **not referenced by the CRM** (grep: only test brand names "TurquAI"). It lives in `~/Developer/azin-asgari-growth-os/services/turquai-bridge` with `TURQUAI_BASE_URL = https://crm.turquai.com`. Rows: AZCRM-ARCH-006 (CRM-M059). For the CRM program this is NOT-APPLICABLE-WITH-EVIDENCE; the retirement itself is a decision in the other repository.
- **Why a decision is required:** Deleting code from another repository is the owner's call; reconciling it to the new CRM would be new scope.
- **Option A — retire:** delete `services/turquai-bridge` in growth-os; benefits: no stale integration target; risks: none found (undeployed, unreferenced); technical consequence: one commit in growth-os.
- **Option B — keep as archived reference:** benefits: history preserved; risks: someone deploys it as-is against the wrong host; technical consequence: add a README "DO NOT DEPLOY" line.
- **Recommended option:** A.
- **Exact owner answer required:** A / B.
- **Related, for awareness (no decision needed now):** a Worker named `whatsapp-webhook` (last modified 2026-08-01) still exists in the Cloudflare account; the CRM's WhatsApp webhook is served by the CRM itself. If it is no longer routed, it can be deleted in the same clean-up.

## DEC-005 · Aftercare protocol approval and long-term follow-up cadence

- **Subject:** (a) Approving the aftercare protocol so the plan starts automatically after a completed procedure; (b) the cadence of long-term follow-ups (3/6/12 months?).
- **Current state:** The default protocol is **unapproved**; with the flag off the system creates a visible staff task "start the plan" and sends **no** patient message (harness-proven: `test_journey_timers.php`). With an approved protocol the plan starts within one cron tick and the first step is scheduled (+24 h) through the normal queue. Long-term follow-up state (CRM-M056 / AZCRM-PJ-005) is not built because the cadence is undefined. Rows: M046 gate, AZCRM-PJ-005.
- **Why a decision is required:** The protocol's steps are patient-facing clinical aftercare messages (approved copy); their timing and the follow-up cadence are the clinic's call.
- **Option A — approve the standard protocol now** (Ayarlar → Bakım protokolleri → approve): benefits: aftercare runs without anyone remembering; risks: the step texts must be the ones the clinic wants — review once; technical consequence: none (flag).
- **Option B — keep manual:** benefits: full control; risks: relies on staff memory (a task is created, so not silent); technical consequence: none.
- **Cadence (M056):** answer with months (e.g. 3 / 6 / 12) → a follow-up state + timer task, ~half a day, tests-first.
- **Recommended option:** A for the protocol (after a one-time review of the texts); cadence: owner's choice.
- **Exact owner answer required:** A / B, and the cadence in months (or "none").

## DEC-006 · Session cookie hardening applied on the host (confirmation only)

- **Subject:** `APP_COOKIE_SECURE` and `APP_COOKIE_HTTPONLY` were set to `true` in the host's `application/config/app-config.php` during the closure (backup `~/backups/app-config.php.pre-cookie-20260904-012718`).
- **Current state:** Verified: session persisted across the change, navigation and CSRF POSTs work, no redirect loop; the session and CSRF cookies now carry `secure; HttpOnly; SameSite=Lax` (observed on the host through a local PHP server, values never recorded). Perfex's JavaScript does not read the CSRF cookie (it uses the embedded `csrfData`), so HttpOnly is safe.
- **Why a decision is required:** None — this is a notification; the only thing the program could not do is a *fresh* login (no credentials are ever typed by the program). Please log out and in once; if anything is odd, delete the two lines and `touch ~/.lsphp_restart.txt`.
- **Exact owner answer required:** "logged in fine" (or a problem report).

## DEC-007 · Cron key rotation (precaution)

- **Subject:** While inspecting the host config for the cookie change, the tail of `app-config.php` — including the `APP_CRON_KEY` value — was displayed once in the working session's tool output. It was not written into any document or commit.
- **Why a decision is required:** Rotating it is a 2-minute owner action — a new value in `app-config.php` only (`~/bin/crm-cron.sh` and `crm-dispatch.sh` read the key from the config at run time; the Cloudflare `crm-dispatch` Worker's secret must be updated to the same value if it calls the dispatcher) — and only the owner should hold the new value.
- **Recommended option:** rotate.
- **Exact owner answer required:** "rotated" / "not needed".
