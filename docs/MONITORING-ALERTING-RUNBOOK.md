# Monitoring & Alerting Runbook

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
