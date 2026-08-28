# WhatsApp Cloud API — App Review Readiness (prepared, NOT submitted)

**App:** `SEO Evaluate CRM` · **App ID:** `2296795344499663` · ownership under Kimia Estetik is
intentional and approved. **Do not create a second WhatsApp app.** Do not submit App Review, connect
a real number, or send a real message without owner approval at the action point.

## Permissions actually required
- `whatsapp_business_messaging` — send/receive messages on behalf of the business.
- `whatsapp_business_management` — manage WABA, phone numbers, templates.
(Request only these. `business_management` only if managing assets programmatically.)

## Reviewer test workflow (once live assets exist)
1. Configure a test WABA + test phone number (Meta test assets) on the brand's `se_wa_numbers` row;
   store the system-user token in the referenced option key (never in git/UI).
2. Set the webhook callback URL to `https://crm.roozbeh.com.tr/se_whatsapp/webhook` and the verify
   token to the value in option `se_wa_verify_token`. Subscribe the app to the WABA `messages` field.
3. Reviewer sends a message to the test number → appears in the CRM inbox (brand-scoped) within one
   cron cycle → staff replies within the 24h window (free-form) or via an approved template outside it.

## Demonstration script (screencast shot list)
1. Inbound message arrives → shows in Inbox with unread badge + open reply window.
2. Open conversation → inbound/outbound thread, delivery/read states.
3. Reply inside window (free-form); show template-required state outside window.
4. Staff assignment; brand filter (admin sees all, staff sees only their brand).
5. Lead profile → WhatsApp conversation tab.

## Privacy / data-deletion prerequisites
- Public privacy policy URL covering WhatsApp message processing + retention.
- Data-deletion instructions/endpoint (map to the patient retention/deletion workflow in se_core).
- Message content is stored brand-scoped; no message body or token is written to application logs.

## Exact externally gated steps (owner action required)
- Create/store a persistent Meta system-user token (GATE).
- Connect a real clinic WABA / phone number (GATE).
- Enable the public webhook route — requires adding `se_whatsapp/webhook` to `csrf_exclude_uris`
  (deploy step) — and subscribing the production webhook (GATE).
- Send the first real test message (GATE).
- Submit App Review (GATE).

## Current implementation status (honest)
- Signed webhook receiver (GET verify + POST X-Hub-Signature-256 over raw body): **built + fixture-
  tested**; GET verification live-tested. Public POST route intentionally disabled (CSRF) until go-live.
- Tenant routing, wamid dedup, out-of-order status, ctwa capture, metering: **fixture + cron tested**.
- Inbox UI (list, conversation, filters, lead tab), brand scoping, permissions: **built**; brand
  isolation verified.
- Media: metadata captured; controlled download deferred (needs live media URLs + token) — gated.
- Outbound send + template send: **gated** on a valid token (reminder queue consumer holds until then).
