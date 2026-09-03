# KVKK Retention Matrix — engineering state (2026-09-04)

**What this is.** For each class of personal data the CRM holds: where it lives, whether the software can delete or anonymise it today, and who decides the period. **No retention period is invented here** — every "Retention period" cell that is a legal fact is marked `LEGAL` and must be filled by the lawyer (DEC-003). Until then no automatic deletion runs; deletion is a staff action.

**Engineering mechanism verified (code + harness):** patient archive is a soft state (`se_patients.retention_state = archived`, `archived_at/by`) separate from a data-subject *deletion request* stamp (`se_patient_request_deletion`), both access-logged; sealed photos live in R2 and are deleted with `se_journey_media_delete_object`; the inbox copy of a photo is purged after sealing (`se_journey_purge_inbox_copy`); journey health answers are encrypted at rest (`se_journey_encrypt`, column `answers_enc`) and readable only with the `view_health` capability (default-deny, audited). There is **no** retention cron and **no** anonymisation routine for journey/message history yet (CRM-M057 — one migration + one dispatcher step once periods exist).

| Data class | Current storage | Deletion/anonymization supported? | Retention period | Decision owner |
|---|---|---|---|---|
| Patient identity (name, phone, e-mail, country, language) | `tblleads` (+ `se_patients` when a patient record exists) — MariaDB | Yes — Perfex lead delete; `se_patient_archive` (soft) and deletion-request stamp; no anonymiser yet | `LEGAL` | Lawyer → owner |
| Consent ledger (purpose, state, text version, source, time) | `tblse_consent_ledger` | **Keep by design** (proof of consent); no delete path on purpose | `LEGAL` (typically ≥ the claim period) | Lawyer |
| Intake questionnaire — health answers (sealed) | `tblse_journey_intakes` (`answers_enc`, encrypted), flags | Partial — row delete possible by SQL; **no UI/cron erasure** (CRM-M057) | `LEGAL` (health data) | Lawyer |
| Photos (frontal/left/right/donor) | Cloudflare R2 (sealed) + `tblse_journey_media` metadata; inbox copy purged after sealing | Yes — `se_journey_media_delete_object` per object; no bulk/expiry job | `LEGAL` (health data) | Lawyer |
| Review decision, quotes, quote snapshots | `tblse_journey_reviews`, `tblse_journey_quotes` | Partial — no erasure routine; quotes are commercial records | `LEGAL` (commercial/tax retention may apply) | Lawyer + accountant |
| Appointments and status history | `tblse_appointments`, `tblse_appointment_status_history` | Partial — delete by SQL; no routine | `LEGAL` | Lawyer |
| WhatsApp / Instagram message history | `tblse_wa_messages`, `tblse_ig_messages` (+ media metadata) | Partial — no erasure routine; per-conversation delete would need M057 | `LEGAL` | Lawyer |
| Outbound queue rows (templates, variables) | `tblse_wa_outbound`, `tblse_ig_outbound` | Yes — technical rows; can be pruned after `sent` (not scheduled) | Engineering proposal: 90 days after send — **needs confirmation** | Owner (technical) |
| Conversion outbox (hashed identifiers, consent snapshot) | `tblse_conversion_outbox` | Yes — technical rows; identifiers are hashed at queue time | Engineering proposal: 180 days — **needs confirmation** | Owner (technical) |
| Journey events / transitions / audit log | `tblse_journey_events`, `tblse_journey_transitions`, `tblse_journey_audit`, `tblse_record_access_log` | **Keep by design** (accountability); anonymise actor/patient refs only with M057 | `LEGAL` (audit duty) | Lawyer |
| Webhook event envelopes (redacted error text) | `tblse_wa_webhook_events`, `tblse_ig_webhook_events`, `tblse_meta_leadgen_events` | Yes — technical rows; prune after processing (not scheduled) | Engineering proposal: 30 days — **needs confirmation** | Owner (technical) |
| Push subscriptions (staff browsers) | `tblse_push_subscriptions` | Yes — deleted on 404/410 and on unsubscribe (verified) | n/a (staff device data) | Owner |
| Staff access to health data (who viewed what) | `tblse_journey_audit` (`view_intake`, photo URLs are staff-bound and expiring) | Keep | `LEGAL` | Lawyer |

**Next step when the periods exist (CRM-M057, ~1 day):** a migration adding `retention_until` per class, a dispatcher step that anonymises (identity → `[silindi]`, phone → hash) or deletes past the period, one audit line per action, and a Health counter "erased last night". Tests-first in the harness; production after a backup.
