# Owner Go-Live Checklist (exact actions, in order)

> **AUTHORITATIVE DEPLOYMENT DECISION (supersedes earlier VPS/PHP-upgrade framing):** The CRM remains
> on the **current cPanel hosting with PHP 8.1.34**, serving **2–3 internal users** initially. VPS migration,
> PHP 8.3/8.4 adoption, DNS migration, load balancing, containers, and Redis are **NOT required for go-live**
> and are moved to an **Optional future roadmap**. Immediate operational priorities: disk-capacity monitoring,
> reliable backups + encrypted off-server copies, restore readiness, cron monitoring, HTTPS renewal,
> permissions/secret protection, SSH-key security, error monitoring, health checks — plus completing the
> externally gated integrations. Async cron processing is retained because it protects web requests
> (not for user volume).
>
> **Disk:** the 95% figure is the **shared 5.2 TB server array** (283 GB free, inodes 37%), not this account.
> This account uses **16 GB** total; the CRM **222 MB**; our backup artifacts **3.3 MB**. The latest backup
> `~/_deploy_artifacts/backups/db_predeploy_20260829_070412.sql` (**833,143 bytes**, sha256 `c28487c6…`) added
> ~833 KB — negligible. Not a go-live blocker for this account; monitor the shared array via the host.
>
> **PHP:** 8.1.34 is the **selected and approved** runtime. PHP **8.3.33 + 8.4 syntax lint passed** for all 64
> module files. PHP **8.3 application runtime was NOT verified**; the php83 CLI emits an `nd_mysqli.so`
> undefined-symbol **startup warning** (CLI extension-config artifact, not an app error) — **not an immediate
> blocker** since PHP 8.1 remains the runtime. Do not switch PHP until a future isolated compatibility test succeeds.
>
> **Error logs:** the earlier truncation removed only Claude-generated php83-CLI warnings, **not application
> errors**. Logs are no longer truncated; test noise is **rotated** (timestamped + checksummed) and gitignored;
> the application itself produces **zero** errors.



Everything below is **owner-gated** — credentials, external submissions, or production authority. The CRM
code for each is built and fixture-tested; each item flips from gated to live once you complete the step.
Do these in order; each is independent enough to do when ready.

## A. Immediate (safe, no external submission)
1. **Rotate/confirm secrets on production config** (never in git): `APP_CRON_KEY`, `APP_CSRF_PROTECTION=true`,
   DB creds, `se_landing_token_secret` (random) to enable landing-token attribution.
2. **Disk headroom**: staging home filesystem is at **95%** — provision space before production.
3. **Off-server encrypted backups**: install `rclone` (absent) + configure an approved destination (R2/S3);
   schedule encrypted daily DB+config+uploads backup, 30-day retention.

## B. WhatsApp (Meta) — each is a gate
4. Provide a **Meta system-user token** + **test WABA/phone-number**; store token in option
   `se_wa_*` (never git); set `se_wa_verify_token`, `se_wa_app_secret`.
5. Approve the **narrow `csrf_exclude_uris` entry** for `se_whatsapp/webhook` (deploy step) to enable the public POST.
6. **Subscribe the production webhook** to the WABA `messages` field.  7. **Send the first real test message** (gate).
8. **Submit WhatsApp App Review** using `docs/WHATSAPP-APP-REVIEW-READINESS.md` (gate; existing app 2296795344499663; no 2nd app).

## C. Meta Lead Ads + CAPI — each is a gate
9. Decide the **ads integration/app context** (may need a separate use case; **do not create a 2nd app without deciding**).
10. Map Pages/forms to brands in `tblse_meta_forms`; store the **Page token**; set `se_meta_app_secret`,
    `se_meta_webhook_verify_token`; approve `csrf_exclude_uris` for `se_core/leadgen`; subscribe the leadgen webhook.
11. Provide the **Meta dataset id** + **CAPI system-user token** per brand to enable live CAPI (`se_capi_enabled_<brand>` on).
12. **Submit Lead Ads App Review** using `docs/META-LEADADS-APP-REVIEW-READINESS.md` (gate).

## D. Google — each is a gate
13. Grant **MCC access**; record each brand's Google Ads customer id.  14. Create a **Cloud project** + enable Data Manager API.
15. Create a **service account** + link **Data Manager permissions**; store its token in `se_google_sa_token_<brand>` (no keys in git).
16. Create **conversion actions**; map to `se_google_conv_action_<brand>_<event>`.  17. First **live conversion** (gate).
18. Configure **GA4 property** + **Search Console** + **Google Ads reporting** creds to enable report imports (gated until then).

## E. Google Calendar (optional)
19. Provide a service account + per-brand/staff calendar mapping to replace the fixture adapter (gate).

## F. Production migration (prepared; owner-executed)
20. Provision the **VPS** (PHP 8.3 — static-lint-clean; runtime verification then required), MariaDB 10.11+.
21. Follow `docs/PRODUCTION-READINESS-RUNBOOK.md` (deploy, migrate, activate, cron, health).
22. Perform a **full restore drill** into an isolated DB (`docs/BACKUP-RESTORE-RUNBOOK.md`).
23. **DNS/TLS cutover** per `docs/DNS-TLS-CUTOVER-ROLLBACK.md` (reduce TTL first; HSTS after verify).
24. Do **not** place real patient data on the shared staging host at any point.

## Legal/owner decisions (independent)
- Turkey vs EU data hosting; KVKK/GDPR transfer mechanism; consent wording + versions; data-retention periods.
- CodeCanyon license confirmation for Accounting / Service Management / Flutex (currently *awaiting confirmation*;
  WhatsBot/PRChat rejected as duplicative). Decide whether to commit the Dark Theme vendor source (currently gitignored).
