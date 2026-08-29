# Backup & Restore Runbook

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



## Scope
Database, application code, uploaded files, environment configuration, and secrets. Staging backups live
**outside the public document root** in `~/_deploy_artifacts/backups/` (dir perms `700`). **No database dump
is ever stored under the document root.**

## What to back up
| Asset | Source | Method |
|-------|--------|--------|
| Database | `hyundaic` CRM DB (name read from `app-config.php`, never printed) | `mysqldump --single-transaction --quick --no-tablespaces --routines` (creds via `MYSQL_PWD` env, never on argv/stdout) |
| Application code | docroot `modules/`, `application/` | git (custom code) + `tar` for vendor/core |
| Uploaded files | `uploads/` (media, attachments) | `tar` (outside docroot) |
| Configuration | `application/config/app-config.php` | encrypted copy (contains secrets — restrict `600`) |
| Secrets | option table token refs + `APP_CRON_KEY` | part of DB + config backups; store encrypted |

## Requirements
- **Encryption** for any off-server copy (config + DB contain secrets). Use age/gpg; keep keys off the host.
- **Access**: backup dir `700`; config backup `600`; owner-only.
- **Retention**: keep ≥ 30 days; rotate daily; keep one weekly + one monthly.
- **Off-server copy**: **NOT yet automated** (`rclone` absent on staging). Production must push encrypted
  backups to an approved off-host destination (e.g. Cloudflare R2 / S3) — owner decision + credentials required.
- **Monitoring**: alert if newest backup age > 26h or size drops > 30% vs the prior (see MONITORING runbook).
- **RPO ≤ 24h** (daily DB dump; tighten to hourly binlog for production if needed). **RTO ≤ 2h**.

## Verify a backup (non-destructive) — DONE on staging
```
LATEST=$(ls -t ~/_deploy_artifacts/backups/*.sql | head -1)
grep -c 'CREATE TABLE' "$LATEST"     # == live BASE TABLE count (verified: 138 == 138)
tail -c 200 "$LATEST" | grep 'Dump completed'   # completion marker present (verified)
```
Evidence (2026-08-29): fresh dump 138 tables, completion marker present, header parses; matches live schema.
**No backup contents are ever printed.**

## Restore drill
- **Full restore drill requires an ISOLATED temporary database** (never the live staging DB). On this
  shared CloudLinux account a throwaway DB could not be created safely without owner/cPanel action, so the
  full load-and-verify drill is **PENDING an isolated environment**. Integrity was validated
  non-destructively (above).
- **Production**: create an isolated restore target, `mysql <restore_db> < dump.sql`, then run the app
  read-only against it to verify row counts and key screens. **Never** restore over the live database.

## Restore procedure (production, in a maintenance window)
1. Stop cron. 2. Snapshot the current (broken) DB first. 3. Create/verify the restore target DB.
4. `MYSQL_PWD=… mysql -h <host> -u <user> <db> < /path/db_backup.sql` (explicit path).
5. Restore `uploads/` from the tar. 6. Restore `app-config.php` (decrypt). 7. `touch ~/.lsphp_restart.txt`.
8. Verify login 200, health page, cron 200. 9. Re-enable cron.

## Rollback / recovery objectives
- Code: git checkout previous release + opcache clear. DB: restore pre-change dump into the same DB.
- External: re-gate outbound by clearing token options + `se_capi_enabled_<brand>=0`.
