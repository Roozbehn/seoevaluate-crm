# Production Readiness Runbook (SEO Evaluate CRM)

Prepared, **not executed**. The current shared host (`crm.roozbeh.com.tr`, origin `57.129.84.98`
behind Cloudflare) is **staging only**. No production cutover, PHP switch, or host change has been
performed. All commands below use explicit paths and validated targets; none are destructive.

## 7.1 Environment & compatibility (verified on staging)

| Item | Staging (current) | Production target |
|------|-------------------|-------------------|
| App | Perfex CRM 3.4.1 / CodeIgniter 3 | same |
| PHP | 8.1.34 (NTS) | **8.3** (8.3.33 available as `/opt/alt/php83/usr/bin/php`) |
| DB | MariaDB 10.11.18 | MariaDB 10.11+ |
| Extensions (confirmed loaded) | bcmath, ctype, curl, fileinfo, gd, iconv, intl, json, libxml, mbstring, mysqli, openssl, PDO, pdo_mysql, SimpleXML, xml, xmlreader/writer, zip | same set required |
| Limits | memory 512M, upload 128M, post 100M, max_execution_time 0 (CLI) | ≥ these |
| Timezone | PHP `date.timezone=UTC`; app option `default_timezone=Europe/Istanbul` | keep app tz Europe/Istanbul; DB default `utf8mb4_unicode_ci` |
| Cron | `3-59/15 * * * * /home/hyundaic/bin/crm-cron.sh` (every 15 min; cron controller throttles to 300s) | 5-minute system cron recommended |
| Modules (active) | se_core 1.0.0, se_appointments 1.0.0, se_whatsapp 1.0.0, perfex_dark_theme 1.2.3 | same |
| Schema versions | se_core 7, se_appt 2 (option-gated, idempotent) | applied by admin_init on first authenticated request |

**PHP 8.3 compatibility:** static lint (`php -l`) of all 64 custom + dark-theme PHP files **passes on
PHP 8.3.33 and 8.4** — no parse-level incompatibility. **Runtime verification under PHP 8.3 is OUTSTANDING**
(requires switching the PHP handler in a staging clone; not done here). No Perfex core files were modified
by this work; any unavoidable core patch is isolated under a module `patches/` dir and documented.
Do not invent vendor PHP-8.3 support claims for Perfex 3.4.1 — treat runtime 8.3 as to-be-verified.

## 7.2 Deployment & rollback runbook

### Preflight (all must pass)
1. `cd /home/<prod_user>/<docroot> && git status` clean; on the intended release commit.
2. Confirm PHP/MariaDB versions and the extension set above.
3. Take backups (see BACKUP-RESTORE-RUNBOOK) — DB + `application/config/app-config.php` + `modules/`.
4. Confirm `application/config/app-config.php` has production DB creds, `APP_BASE_URL`, a fresh
   `APP_CRON_KEY`, `APP_CSRF_PROTECTION=true`, and Meta/Google secrets present only as options (never git).

### Maintenance window
- Optional for a code-only deploy (idempotent migrations). Recommended for the first cutover.

### Code deployment (explicit paths)
1. `git fetch origin && git checkout main && git pull --ff-only origin main`
2. Verify `git rev-parse HEAD` equals the reviewed release hash.

### Environment-specific configuration
- `app-config.php` is environment-specific and **untracked** — never overwritten from git. Set options
  (tokens, verify tokens, service-account token refs, rates, landing secret) via the admin UI / DB, not files.

### Database migration order
- Migrations are **idempotent** (`IF NOT EXISTS` + version-gated). They run automatically on the first
  authenticated `admin_init` after deploy. Order is enforced by module load: se_core (v7) → se_appointments (v2).
- To force-apply headlessly: authenticate once in the admin UI (any staff), or run the cron once after login.

### Module activation order
1. se_core → 2. se_appointments → 3. se_whatsapp → 4. perfex_dark_theme (cosmetic, last).
Activation is idempotent; re-activation is safe; no module has a destructive uninstall.

### Cron installation
- Install the 15-min (or 5-min) system cron calling the wrapper that reads `APP_CRON_KEY` from config at
  runtime (never in the crontab). Verify one run returns HTTP 200 and does not 401.

### Cache / restart
- CloudLinux/LiteSpeed: `touch ~/.lsphp_restart.txt` to clear opcache after a deploy.

### Health verification (post-deploy)
- Login page HTTP 200; one authenticated admin page 200; `/admin/se_core/se_reports/health` renders;
  cron run 200; application error log unchanged; outbox drains without external transmission when gated.

### Rollback triggers
- Login 5xx; migration error; fatal in app log; cron 500; outbox mass-fail; health page error.

### Code rollback
- `git checkout <previous_release_hash>` then `touch ~/.lsphp_restart.txt`. (No history rewrite.)

### Database rollback
- Restore the pre-deploy DB dump into the SAME database only if a schema change caused failure (see
  BACKUP-RESTORE-RUNBOOK). Migrations are additive (`IF NOT EXISTS`); a code rollback rarely needs a DB rollback.

### External-integration rollback
- Set the per-brand toggles off: `se_capi_enabled_<brand>=0`; clear `se_meta_page_token_*`,
  `se_google_sa_token_*`, `se_wa_app_secret`, `se_meta_app_secret` to re-gate all outbound. Nothing sends
  without those; rows hold as pending.

### Final acceptance criteria
- App 200, cron 200, migrations applied (schema v7/v2), health page green, error log clean, no synthetic data,
  no secrets in git, backups verified.
