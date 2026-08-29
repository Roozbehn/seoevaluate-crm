# Rollback Procedure

Scope: the three `se_*` modules on the cPanel host. Nothing here changes the environment — no VPS, no PHP
version change, no DNS or TLS change.

## Before you start

Take a database dump first, even when rolling back. A rollback that loses the rows created since the
deploy is worse than the deploy.

```bash
cd /home/hyundaic/crm.roozbeh.com.tr
# dump goes OUTSIDE the document root
mysqldump --defaults-file=<credentials file> <db> > ~/_deploy_artifacts/backups/db_prerollback_$(date +%Y%m%d_%H%M%S).sql
```

## 1. Code rollback (the normal case)

The deployed tree is a Git working copy. Roll back by checking out the previous merge commit.

```bash
cd /home/hyundaic/crm.roozbeh.com.tr
git log --oneline -n 10          # identify the last known-good merge commit
git status                       # MUST be clean apart from intentionally untracked vendor files
git checkout <known-good-commit> -- modules/se_core modules/se_appointments modules/se_whatsapp
```

Then confirm:

```bash
find modules/se_core modules/se_appointments modules/se_whatsapp -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v 'No syntax errors' || echo "LINT CLEAN"
curl -s -o /dev/null -w '%{http_code}\n' --resolve crm.roozbeh.com.tr:443:57.129.84.98 https://crm.roozbeh.com.tr/admin/authentication
```

**Do not `git checkout` the whole tree.** `modules/perfex_dark_theme/` is a deployed vendor plugin that is
deliberately untracked; a full checkout or a `git clean` would delete it.

## 2. Schema rollback — deliberately not automated

Migrations are **additive and idempotent**: `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN` guarded by a version
counter (`se_core_schema_version`, `se_appt_schema_version` in `tbloptions`). They never drop or rewrite an
existing column.

That means **older code runs against a newer schema without modification** — the extra columns are simply
unused. So the correct schema rollback is: *do nothing.*

There is **no down-migration and no automatic table drop, by design.** An automated rollback that dropped
`se_consent_ledger`, `se_record_access_log` or `se_appointment_status_history` would destroy consent
evidence and audit history to fix a code problem. If a table genuinely must go, that is a deliberate,
manual, dump-first operation — never a scripted step.

To pin the schema counter back so a re-deploy re-runs a migration, set the option manually and re-run
`migrate_cli.php --apply`; it is safe to run repeatedly.

## 3. Disabling a feature without rolling back code

Usually faster and less risky than a code rollback, because every integration is gated:

| To stop | Do this | Effect |
|---------|---------|--------|
| All ad conversions | Clear the brand's token/dataset configuration | The outbox holds rows as **gated**; nothing is transmitted and nothing is lost |
| WhatsApp sending | Leave no transport registered (the current state) | Messages queue as **gated**; none is sent |
| Meta Lead Ads intake | Unsubscribe the webhook at Meta | Events stop arriving; already-stored events stay |
| Google Data Manager | Remove the service-account credential | Requests stay pending; nothing is submitted |
| A whole module | Deactivate it in Perfex → Modules | **Data is preserved** — no module has a deactivation hook or an `uninstall.php` |

Gating is fail-closed everywhere: a missing credential produces a held row with a stated reason, never a
silent drop and never an unauthenticated send attempt.

## 4. Rollback verification

1. `git status` — clean apart from the untracked vendor theme.
2. PHP 8.1 lint clean across all module files.
3. `/admin/authentication` returns 200.
4. Cron freshness: option `last_cron_run` advances within one interval.
5. No new `error_log*` file inside the document root.
6. Module data-table row counts unchanged from the pre-rollback dump.

## 5. What rollback does not cover

- **Anything already transmitted externally.** A conversion sent to Meta or Google cannot be recalled by
  rolling back this code. (As of this writing none has ever been sent.)
- **A restore.** Rolling back code is not restoring data. The restore drill in
  `BACKUP-RESTORE-RUNBOOK.md` is still outstanding, so this project has backup *integrity* evidence, not
  restore evidence.
