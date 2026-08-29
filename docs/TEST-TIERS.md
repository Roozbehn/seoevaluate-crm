# Test Tiers

Reported separately and never conflated. A passing fake-database assertion is
not evidence about MariaDB, and neither is evidence about a browser.

| Tier | Command | Assertions | What it can and cannot prove |
|------|---------|-----------:|------------------------------|
| **Fake DB / unit** | `php modules/se_core/tests/run.php` | **1310 / 0** | Module LOGIC against an in-memory query builder. Cannot prove anything about MariaDB, HTTP or rendering. Throws on any SQL it does not understand, so it can never pass by silently doing nothing. |
| **Real MariaDB** | `php modules/se_core/tests/run_db.php` | **86 / 0** | Executes the REAL model classes against the live schema inside a transaction that is always rolled back. Proves database behaviour: `IN ()` really is a syntax error, `GET_LOCK` really returns 0 under contention, a UNIQUE index really rejects the second inserter, two connections really claim disjoint rows, a fenced UPDATE really matches zero rows. |
| **HTTP** | `php modules/se_core/tests/run_http.php` | **146 / 0** | Real requests to the deployed controllers, unauthenticated. Meta and WhatsApp reported separately. Every webhook response carries an `X-SE-Webhook` marker header, so a markerless CSRF 403 is a FAIL — these assertions prove the controller actually executed, not that middleware answered. Covers verification GET (valid/invalid), signature (missing/invalid/tampered → 401), size bound → 413, malformed JSON → 400, valid → 200 + exactly one durable row, duplicate → one row, PUT/DELETE → 405, status-callback transition, cross-brand → no write, unknown mapping parked, storage failure → 500, plus route/method/CSRF and harness/log inaccessibility. See `HTTP-RESULT-MATRIX.md`. |
| **Authenticated browser** | manual, real admin session + CDP device emulation | 19 genuine-viewport captures | Rendering in the real Perfex layout, Dark Theme, navigation, translation, and genuine top-level responsive layout at 390 / 768 / 1728 px (not iframes, not a clamped window). Six required screens across three viewports. See `ROUTE-UI-EVIDENCE-MATRIX.md`. Admin role only — restricted roles remain owner-manual. |
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
- A **network-kill fixture** counts every outbound transport attempt during the
  CLI tiers; the run fails if the count is anything but zero (current: **0**).
  Honest limitation: this fixture is CLI-only and cannot observe the server's WEB
  process, so it does not police the deployed app or a cron run. There, "no
  outbound" rests on gate-reachability (every curl site sits behind a
  credential/registration that does not exist) plus runtime sentinels
  (attempt counters, sent counts, token-error options) that would move if any
  transport fired — see `CRON-EXECUTION-EVIDENCE.md`.

## What the real-MariaDB tier found that fake tests could not

1. **PHP/MariaDB clock skew of two hours.** Queue rows are written with SQL
   `NOW()` but were compared against PHP `date()`. Retries were three hours
   late and dead-worker leases took 2h15m to recover. Silent, and invisible to
   any in-memory harness.
2. **`GET_LOCK` result ignored** — proven by holding the lock on a second
   connection and observing the model wait the full timeout and refuse.
3. **`IN ()` is genuinely a MariaDB syntax error**, confirming the empty-scope
   bug produced 500s rather than empty results.

## Current totals (this phase)
| Tier | Result |
|------|-------:|
| Fake-DB / unit | **1310 / 0** |
| Real MariaDB | **86 / 0** (rollback clean, outbound 0) |
| HTTP (deployed controllers) | **146 / 0** |
| PHP 8.1 lint (module files) | 102 / 102 |

The real-MariaDB clock check is now an informational notice, not an assertion
about the host: it reports the measured PHP↔MariaDB skew but asserts only that
`se_db_now()` tracks the database clock, so a correctly synchronized host does
not fail the suite. The purge safety net now covers every module data table.
