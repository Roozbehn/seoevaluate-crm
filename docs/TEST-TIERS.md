# Test Tiers

Reported separately and never conflated. A passing fake-database assertion is
not evidence about MariaDB, and neither is evidence about a browser.

| Tier | Command | Assertions | What it can and cannot prove |
|------|---------|-----------:|------------------------------|
| **Fake DB / unit** | `php modules/se_core/tests/run.php` | **1146 / 0** | Module LOGIC against an in-memory query builder. Cannot prove anything about MariaDB, HTTP or rendering. Throws on any SQL it does not understand, so it can never pass by silently doing nothing. |
| **Real MariaDB** | `php modules/se_core/tests/run_db.php` | **86 / 0** | Executes the REAL model classes against the live schema inside a transaction that is always rolled back. Proves database behaviour: `IN ()` really is a syntax error, `GET_LOCK` really returns 0 under contention, a UNIQUE index really rejects the second inserter, two connections really claim disjoint rows, a fenced UPDATE really matches zero rows. |
| **HTTP** | `php modules/se_core/tests/run_http.php` | **49 / 0** | Real requests to the deployed app, unauthenticated throughout. Proves webhook verification, signature enforcement, size bounds, route authorization, GET-on-mutation refusal, and that the harness and logs are not web-reachable. |
| **Authenticated browser** | manual, existing session | 28 routes | Rendering inside the real Perfex layout, Dark Theme, navigation, translation, responsive layout. |
| **Owner manual** | `MANUAL-UI-CHECKLIST.md` | pending | Anything needing a second staff account with restricted capabilities. |

## Safety properties of the real-MariaDB tier

- Every suite runs inside one transaction, always rolled back.
- Row counts are compared before and after; a mismatch fails the run.
- An unconditional purge of the reserved fixture id range (≥ 900000) runs after
  the rollback as a safety net.
- **DDL is forbidden in this tier.** `ALTER TABLE` implicitly COMMITs in
  MariaDB, which ends the transaction and makes everything before it permanent.
  That happened once during development and leaked fixtures into the live
  database; the rule and the purge both exist because of it. Migration
  idempotency is proven instead by the fake-DB suite and by
  `migrate_cli.php --apply` run twice.
- A **network-kill fixture** counts every outbound transport attempt. The run
  fails if the count is anything but zero. Current: **0**.

## What the real-MariaDB tier found that fake tests could not

1. **PHP/MariaDB clock skew of two hours.** Queue rows are written with SQL
   `NOW()` but were compared against PHP `date()`. Retries were three hours
   late and dead-worker leases took 2h15m to recover. Silent, and invisible to
   any in-memory harness.
2. **`GET_LOCK` result ignored** — proven by holding the lock on a second
   connection and observing the model wait the full timeout and refuse.
3. **`IN ()` is genuinely a MariaDB syntax error**, confirming the empty-scope
   bug produced 500s rather than empty results.
