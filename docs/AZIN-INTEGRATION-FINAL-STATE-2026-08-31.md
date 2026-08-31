# Azin Asgari Meta/WhatsApp CRM — final classified state

Date: 2026-08-31  
Production: `https://crm.roozbeh.com.tr`  
Production revision: `3d94d32`
Schema: `12`  
Automated tests: **1,566 passed, 0 failed**

## Executive result

The Lead Ads and WhatsApp integration is deployed and operating for the assets already connected to brand 22. A real Meta-signed Lead Ads webhook, reconciliation fetch, inbound WhatsApp message, CRM reply/statuses, and an iOS WhatsApp Business coexistence echo have all been processed in production.

This is **not** a claim that every requested Meta surface is fully complete. Events Manager has received both browser and server Lead events but is still parsing deduplication; only a test Instant Form exists; Marketing Messages API onboarding is incomplete; custom conversions are externally blocked; approved consent wording and the Terms URL still await owner/legal action; and CTWA attribution has not had a real ad-spend test.

## Deployed releases

| Revision | Classification | Result |
|---|---|---|
| `426da45` | Deployed and regression-tested | Removed the contradictory WhatsApp send gates; aligned live timestamp display; added inbound/status freshness; corrected inherited CAPI credential display; made Lead Ads access wording truthful; separated provider evidence from self-test evidence. |
| `2105171` | Deployed, migrated, live-tested | Added coexistence `smb_message_echoes` ingestion, global wamid deduplication, explicit message source (`customer`, `cloud_api`, `handset`), and UI source labels. Migrated schema v11 to v12. |
| `761e7b2` | Deployed and regression-tested | Removed the false all-brand WhatsApp warning caused by testing synthetic brand `0`; inbox capability now follows its actual visible brand. |
| `ebd14aa` | Deployed and regression-tested | Added an authenticated `evidence=redacted` view for safe WhatsApp and Health screenshots without exposing message bodies or full phone numbers. |
| `3d94d32` | Deployed and regression-tested | Made the Lead Ads checklist agree with live standard access: business-owned assets are operational; advanced access remains optional/pending. |

Rollback is additive and recoverable: revert `2105171` to remove echo ingestion while leaving the nullable `source` column in place. Do not delete the live echo row. Reverting `426da45` is not recommended because it restores known truthfulness and health-display defects.

## Production evidence

Evidence below is identifiers and state only; no tokens, message bodies, phone numbers, or patient data are included.

| Evidence | Production result |
|---|---|
| Meta webhook verification | Challenge verified `2026-08-31 09:19:34`; real provider-signed POST `2026-08-31 10:26:27`; self-test separately recorded `2026-08-31 10:28:05`. |
| Lead Ads | Test form `1597546275497103` is active; lead `900703` has a Meta lead id; last fetch/reconcile succeeded `2026-08-31 13:48:03` with one lead upserted. |
| WhatsApp webhook verification | Challenge verified `2026-08-31 11:37:38`; signed traffic received; last inbound `2026-08-31 13:34:38`; status heartbeat `2026-08-31 12:42:43`. |
| Customer inbound | Event `80`: signed, processed, one attempt. |
| iOS coexistence echo | Event `83`: real signed `smb_message_echoes`, reprocessed after the fix, stored exactly once as outbound `source=handset`; no error. |
| Echo delivery status | Event `84`: signed status event, processed. |
| Conversation | Conversation `950002` is open, has zero CRM-local unread messages, and its 24-hour window reflects the latest real inbound. |
| WhatsApp outbound queue | Two rows are sent; no pending or failed rows in the production snapshot. |
| Webhook queue | Ten processed; no pending or failed webhook events in the production snapshot. |
| Events Manager | `Lead` is active with integration `Multiple`: one browser event and one server event received. Deduplication currently says “Still Parsing Your Data.” |
| Business assets | Authenticated Business Settings lists the WABA, Page, CRM app, Instagram account, verified domain, web/CRM dataset, MM event-sharing dataset, and ad account. |
| Tests | Full production test suite: 1,566 passed, 0 failed after the final deploy. |

## Requirement classification

| # | Area | Classification | What is true now / remaining gate |
|---:|---|---|---|
| 1 | WhatsApp inbox/conversation | **Deployed; live-tested** | Real inbound, CRM outbound, delivery updates, unread clearing, and source display are implemented. Authenticated final screenshot remains. |
| 2 | Timestamps | **Deployed; partial architecture** | The visible live-path mismatch was corrected and provider echo time is preserved. A full canonical-UTC storage plus raw-provider-timestamp redesign was not completed and must not be claimed. |
| 3 | Health freshness | **Deployed; live-tested** | Inbound and status heartbeats are written and populated. Authenticated UI screenshot remains. |
| 4 | iOS coexistence echo | **Deployed; live-tested** | The iOS reply produced signed event `83`; it is now ingested once as `handset` without increasing unread or inventing an inbound service window. |
| 5 | Credentials display | **Deployed; authenticated at provider** | CAPI correctly reports inheritance from the brand's Meta credential instead of “missing.” Tokens were provider-debugged as the intended system-user principal; secrets are not stored in this report. |
| 6 | CAPI delivery and dedup | **Both sources received; dedup still parsing** | Dataset `4515580372030489` shows one browser and one server `Lead`. The pair used the same stable event id `se_dedup_b1f7cb9e3354`, but Meta currently displays “Still Parsing Your Data,” so the final merge is not yet claimed. The CRM “last accepted” fields are empty because the controlled acceptance predates that instrumentation; no extra conversion was sent merely to populate them. |
| 7 | Lead Ads access | **Operational — standard access** | Business-owned asset fetch and signed webhook work. Advanced access is pending. Only the test form exists; this is not production-form readiness. |
| 8 | Evidence integrity | **Deployed; verified** | Provider-signed and self-test timestamps are stored separately; replay testing no longer overwrites genuine provider evidence. |
| 9 | Provider authentication evidence | **Deployed; API-verified** | Current credential state and provider checks are represented without exposing secret values. Authenticated CRM screenshot remains. |
| 10 | System-user tokens/scopes | **Production-ready for current flows** | Current scopes cover pages, Lead Ads, and WhatsApp messaging/management. Tokens are non-expiring system-user tokens. `ads_management` is intentionally absent. |
| 11 | Instant Forms | **Test-only; owner action required** | Test form `1597546275497103` is mapped and live-tested. No production Instant Form has been approved or published. |
| 12 | CTWA attribution | **Implemented; fixture-tested; not live-tested** | Referral/`ctwa_clid` capture and downstream snapshot handling exist. No real click-to-WhatsApp ad-spend event was run, so live attribution is not claimed. |
| 13 | Conversation-to-lead policy | **Linking supported; automation incomplete** | Conversations can carry a brand-scoped `lead_id` and appear on lead profiles, but automatic lead creation/linking for an unknown WhatsApp contact is not enabled. The live test conversation is not asserted to have a related lead. |
| 14 | Marketing Messages API (formerly MM Lite) | **Eligible; onboarding incomplete** | WABA is active, review approved, API eligibility is `ELIGIBLE`, and `azin_reengagement_tr` is active/approved (`MARKETING`, Turkish). WhatsApp Manager offers “Set up Marketing Messages API,” while the Business asset “WhatsApp Marketing Message Event Sharing” says “No data connected.” Enrollment and any controlled send require explicit owner authorization. |
| 15 | Custom conversions | **Externally blocked** | The ad account is unavailable through the installed Ads capability and the current token lacks `ads_management`. Creation requires Ads capability rollout or an owner-generated token with the required scope. No conversion was created. |
| 16 | Meta asset assignments | **Authenticated UI-verified** | Business Settings lists the WABA, Page, CRM app, Instagram account, verified domain, web/CRM dataset, MM event-sharing dataset, and ad account. App-level WhatsApp fields and Page `leadgen` are subscribed, and real deliveries prove routing. Messenger and Instagram Direct remain outside this CRM's implemented channel scope. |
| 17 | Legal and consent | **Owner/counsel action required** | Privacy and data-deletion pages are live. The four-locale Terms draft was not deployed and the app Terms URL was not changed without approval. CRM also shows no approved consent version/text; web forms therefore render no consent checkbox and the attribution cookie stays blocked, as designed. |
| 18 | Google Data Manager | **Optional; implemented but not live** | The optional sender path and truthful missing-credential state exist. `google_sa_22` is not configured; no credential should be requested unless the owner chooses this channel. |
| 19 | Final screenshots | **Complete** | Eleven authenticated screenshots were captured (the ten requested surfaces plus a separate Marketing Messages template list). WhatsApp message bodies/full contact details are redacted; credential values never appear. |

## Current Meta asset registry

| Asset | Identifier | Current evidence |
|---|---:|---|
| App | `1375062474780237` | Page `leadgen` and app-level WhatsApp webhook subscriptions observed. |
| Page | `1329232800262893` | Correct page and active test form observed through Graph. |
| Instagram | `17841438546792982` | Registry/prior UI evidence; current assignment screenshot pending. |
| Ad account | `677114702120392` | Registry/prior UI evidence; Ads management externally gated. |
| Web CAPI dataset | `4515580372030489` | Controlled event accepted; distinct from MM dataset. |
| WABA | `1398503638806590` | Active; review approved; MM Lite eligible. |
| Phone number id | `1290456080816587` | Connected; quality reported green. Actual phone number is intentionally omitted. |
| MM dataset | `2081936999059007` | Registry/prior UI evidence; must never be substituted for the web CAPI dataset. |

## Required owner actions

1. Have qualified privacy counsel approve consent wording/version and the four-locale Terms draft. Reply `approve terms` only when deployment and replacement of the current incorrect app Terms URL are authorized; consent settings require their own approved text/version.
2. Decide whether to approve and publish a production Instant Form. The current form is explicitly a test form.
3. If Marketing Messages API is wanted, explicitly authorize onboarding first, and separately authorize any controlled send.
4. If custom conversions are required now, provide an owner-controlled `ads_management` path or wait for the Ads capability rollout.
5. Treat CTWA as technically implemented but not live-proven until a separately approved real-ad test is performed.
6. Recheck Events Manager after Meta finishes parsing the controlled browser/server pair; until then, do not label deduplication as confirmed.
7. Business verification is not complete. Do not initiate verification without the business owner's explicit direction and documents.

## Authenticated screenshot package

1. WhatsApp conversation showing customer, cloud/API, and handset sources.
2. Credentials page showing inherited CAPI credential without exposing values.
3. Health page with Lead Ads, WhatsApp, and CAPI evidence rows.
4. Meta Lead Ads configuration and standard-access wording.
5. Conversion outbox state.
6. Consent settings.
7. Optional Google Data Manager state.
8. Events Manager deduplication status for the controlled browser/server pair.
9. Marketing Messages Lite onboarding/status.
10. Meta Business Settings asset assignments.

Files are indexed in `docs/evidence/2026-08-31/README.md`.

The correct final label is: **core Lead Ads + WhatsApp integration deployed, live-tested, and captured; final dedup maturity plus legally/externally/owner-gated extensions remain outstanding**.
