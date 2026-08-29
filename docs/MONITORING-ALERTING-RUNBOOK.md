# Monitoring & Alerting Runbook

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



All checks below are **read-only / non-destructive** and must **never print secrets** (tokens, cron key,
DB creds, message/patient content). Two ready surfaces already exist in the app:
- `se_integration_health($brand_id)` (PHP helper) and the authenticated JSON route
  `/admin/se_core/se_reports/health_data?brand=<id>` — aggregates outbox, Meta/Google health, WhatsApp
  number quality, cron age, data freshness, and blockers.
- `se_outbox_health()`, `se_meta_health()`, `se_google_health()` per-brand helpers.

## What to monitor (checks + thresholds + alert)

| Signal | Source | Threshold → alert |
|--------|--------|-------------------|
| Application availability | HTTP GET `/admin/authentication` | not 200 for 2 consecutive checks |
| Cron freshness | option `last_cron_run` (epoch) | age > 3600s (`se_report_cron_age`) |
| PHP/app errors | `~/<docroot>/error_log` + `application/logs/*.php` | any new bytes / new file since last check |
| DB connectivity | `mysqli` connect via app-config | connect fail |
| Disk usage | `df` on the home filesystem | > 90% (currently 95% — **flag now**) |
| Backup age & integrity | newest `~/_deploy_artifacts/backups/*.sql` mtime + CREATE TABLE count | age > 26h OR table count ≠ live |
| Outbox pending/retry/dead-letter | `tblse_conversion_outbox` status counts | pending > N for > 1h; failed > 0 |
| WhatsApp webhook/event health | `tblse_wa_webhook_events` state counts | failed > 0; pending backlog rising |
| Meta Lead Ads health | `tblse_meta_leadgen_events` state + `se_meta_health` | failed > 0; token error set |
| Meta CAPI health | `se_outbox_health` meta_capi + token status | pending rising while token present |
| Google Data Manager health | `se_google_health` + `tblse_gdm_requests` | request status stuck; no service account |
| External reporting-import freshness | options `se_report_last_import_*` | age > 26h when credentials configured |
| Number quality & messaging errors | `tblse_wa_numbers.quality_rating` + message failed states | quality != GREEN; failed deliveries |
| Appointment-reminder backlog | `tblse_reminders` state=pending & scheduled_at < now | backlog > N (consumer gated until WhatsApp live) |

## Wiring
- Poll the authenticated health JSON from an internal monitor (service account/session), or run a
  **read-only** cron-side check script that connects via app-config and emits status codes only.
- Disk 95% is an **immediate operational flag** — provision headroom before production.

## Read-only health-check pattern (dry-run by default; prints status, never secrets)
```bash
# pseudocode — a real script must default READONLY=1 and print only counts/ages, never values
CRON_AGE=$(( $(date +%s) - <last_cron_run epoch> ))     # alert if > 3600
DISK=$(df --output=pcent ~ | tail -1 | tr -dc 0-9)       # alert if > 90
BACKUP_AGE_H=<hours since newest backup>                 # alert if > 26
OUTBOX_FAILED=<SELECT COUNT(*) ... status='failed'>      # alert if > 0
# emit: OK / WARN / CRIT + the metric name; NEVER echo tokens, creds, or row contents
```

## Alert routing
- CRIT (app down, DB down, cron stale > 2h, backup > 48h): page immediately.
- WARN (disk > 90%, outbox failed > 0, import stale): daily digest.
