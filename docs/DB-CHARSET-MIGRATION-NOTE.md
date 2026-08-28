# Database Character-Set Migration Note

**Scope:** the CRM database only (`hyundaic_crmsee` on staging). Do not alter other databases.

## Current staging state (verified 2026-08-29)

- **Database default:** `utf8mb4 / utf8mb4_unicode_ci` (already migrated; the earlier
  `latin1` default was changed prior to this session — do **not** re-run the `ALTER DATABASE`).
- **Server default** (`@@character_set_server`): `latin1` — irrelevant once each table/column and the
  DB default are utf8mb4; noted only so a future operator is not alarmed.
- **Table collations:** 117 tables `utf8mb4_unicode_ci`, 4 tables `utf8mb4_general_ci`.
  The `general_ci` set includes `tblse_appointments` and `tblse_brands`. All are `utf8mb4`
  (full Unicode storage; Turkish-safe). The only difference is sort/comparison collation.
- **Round-trip proof:** `İstanbul`, `görüşme`, `kaş`, `sağlık`, `ışıltı` all stored and read back
  byte-for-byte through a temp probe table that inherited the DB default (`utf8mb4_unicode_ci`).

## Why two tables are `general_ci`

`utf8mb4_general_ci` is MySQL/MariaDB's *default collation for the `utf8mb4` charset*. A
`CREATE TABLE ... DEFAULT CHARSET=utf8mb4` **without** an explicit `COLLATE` clause therefore lands
on `general_ci`, regardless of the database's default collation. The `se_appointments` and
`se_brands` installers specify `DEFAULT CHARSET=<db char_set>` but not the collation, so those
tables were created `general_ci`.

## Recommended follow-ups (not applied in the stabilization commit — low risk, deferred to avoid
unnecessary table rebuilds in the first commit)

1. **Installer fix (code):** in module installers, create tables with an explicit
   `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` so new custom tables match the DB default.
2. **Align existing two tables (optional, staging → production):**
   ```sql
   ALTER TABLE `tblse_appointments` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ALTER TABLE `tblse_brands`       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
   Both are already utf8mb4, so this is a re-collation, not a re-encode; still take a fresh backup
   first and run in a maintenance window. Rationale: avoids "illegal mix of collations" errors if a
   future query compares these tables' string columns against `unicode_ci` columns.

## Production migration

On the production VPS, set the CRM database default to `utf8mb4 / utf8mb4_unicode_ci` at creation:
```sql
CREATE DATABASE `<db>` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
and ensure `character_set_server` / `collation_server` are set consistently in `my.cnf` so no table
falls back to `latin1` or `general_ci`. Round-trip the Turkish sample set above after cutover.
