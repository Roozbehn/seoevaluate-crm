# Owner decision summary (2026-09-04)

Details, options and consequences: `CRM-OWNER-DECISIONS-2026-09-04.md`. Nothing here blocks daily use.

| Decision | Recommended option | Owner needs to answer |
|---|---|---|
| DEC-001 CAPI `ads` consent from the intake marketing tick | Requires legal confirmation (technically C: amend the intake text, then switch on) | A keep off / B switch on / C amend text then on — plus the lawyer's written confirmation for B/C |
| DEC-002 Perfex Leads pipeline for admins + Sales role `leads: view` | B: trim to the journey's 9 stages, remove `leads: view` from Sales | A leave / B trim — and confirm the stage list |
| DEC-003 KVKK retention periods | Requires legal confirmation (then A: nightly retention job) | Filled "Retention period" column of the matrix; A job / B manual |
| DEC-004 turquai-bridge (growth-os repo) | A: retire (undeployed, unreferenced) | A retire / B keep with DO-NOT-DEPLOY note; optionally delete the old `whatsapp-webhook` Worker |
| DEC-005 Aftercare protocol approval + follow-up cadence | A: approve the standard protocol after reading its texts once; cadence: your choice | A / B, and the cadence in months (or "none") |
| DEC-006 Cookie hardening applied (Secure + HttpOnly) | — (done; confirm) | "logged in fine" after one logout/login |
| DEC-007 Cron key rotation | Rotate | "rotated" / "not needed" |
