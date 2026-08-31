# Authenticated evidence package — 2026-08-31

All screenshots were captured from authenticated production CRM or Meta UI. Credential values are never displayed. The WhatsApp evidence view masks the contact and replaces message bodies with explicit redaction placeholders.

| File | Evidence |
|---|---|
| `01-whatsapp-conversation-redacted.png` | Real inbound, two CRM/API outbounds, iOS WhatsApp Business app echo, statuses, zero unread, open service window, and clean queue. |
| `02-credentials-status.png` | Secret store posture and provider readiness without credential values. |
| `03-integration-health-redacted.png` | Cron, CAPI, Lead Ads and WhatsApp readiness; genuine provider evidence separated from self-tests; full WhatsApp number redacted. |
| `04-meta-lead-ads.png` | Standard access operational, live reconciliation, active test form mapping, and processed event queue. |
| `05-conversion-outbox.png` | Empty conversion queue with submitted/confirmed semantics visible. |
| `06-consent-settings.png` | No approved consent wording/version; form checkbox and attribution cookie correctly blocked. |
| `07-google-data-manager.png` | Optional integration disabled and blocked by the missing service-account credential; no false live-test claim. |
| `08-events-manager-dedup-status.png` | Meta's current dedup result: “Still Parsing Your Data.” The preceding authenticated inspection showed one browser and one server `Lead` received. |
| `09-marketing-template-list.png` | Active Turkish marketing template in WhatsApp Manager. |
| `09-marketing-messages-status.png` | `azin_reengagement_tr` active, Marketing Messages API generally available, and setup still offered/not completed. |
| `10-meta-business-assets.png` | Authenticated Business Settings registry for WABA, Page, app, Instagram, domain, datasets and ad account; sensitive payment details were closed before capture. |

Interpret screenshots together with `../../AZIN-INTEGRATION-FINAL-STATE-2026-08-31.md`; a screenshot is point-in-time evidence, not authorization for onboarding, messaging, legal publication, ads, or verification.
