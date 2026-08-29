# Cron Execution Evidence

A crontab entry is not proof the scheduler runs the module work. This is a real
triggered run with before/after state, captured 2026-08-29.

## Method
Trigger `GET /cron/index/<APP_CRON_KEY>` against the origin (the key is read from
app-config inside the script and never printed), with every integration gated and
network-killed. Snapshot the options and queue sentinels before and after.

## Result
```
CRON HTTP CODE: 200 (expect 200)

signal                       before                 after                  verdict
last_cron_run                1788019382             1788019703             OK advanced
cron_has_run_from_cli        1                      1                      (no change)
se_meta_last_reconcile_at    2026-08-29 19:03:02    2026-08-29 19:08:23    OK advanced
outbox_attempts_sum          0                      0                      unchanged (good)
outbox_sent                  0                      0                      unchanged (good)
wa_out_attempts_sum          0                      0                      unchanged (good)
wa_out_sent                  0                      0                      unchanged (good)
wa_msgs_out                  0                      0                      unchanged (good)
gdm_requests                 0                      0                      unchanged (good)
leadgen_processed            0                      0                      unchanged (good)
meta_token_errors            0                      0                      unchanged (good)
google_auth_notes            1                      1                      unchanged (good)

last_cron_run advanced: YES
outbound sentinels unchanged (no external call): YES
```

## What this proves
- **Cron executes** — HTTP 200 and `last_cron_run` advanced to the run time.
- **The module hooks ran, not just Perfex core** — `se_meta_last_reconcile_at`
  advanced (written only by `se_leadgen_reconcile`, an `after_cron_run` consumer).
- **Zero external calls** — every outbound sentinel is unchanged: conversion-outbox
  and WhatsApp-outbound attempt sums and sent counts, outbound WhatsApp messages,
  Google Data Manager requests, processed/failed leadgen events, and
  `se_meta_token_last_error_*` (written on any non-2xx Graph response) all stayed
  put. The only options permitted to change — `last_cron_run`,
  `cron_has_run_from_cli`, `se_meta_last_reconcile_at` — are the only ones that did.
- **Queue consistency** — all queue tables remain at 0 rows; no spurious transitions.

## Honest limitation
The CLI network-kill fixture cannot observe this run: cron executes in the server's
web PHP process, where no test fixture exists. The no-outbound claim rests on (a)
static gate-reachability — every curl site sits behind a credential/registration
that does not exist — and (b) the runtime sentinels above, which would have moved
if any transport had fired. It does not rest on the kill fixture.
