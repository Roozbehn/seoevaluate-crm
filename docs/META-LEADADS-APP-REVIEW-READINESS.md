# Meta Lead Ads — App Review Readiness (prepared, NOT submitted)

Lead Ads uses the **ads-focused** integration context, separate from the WhatsApp use case. **Do not
create the second Meta app or persistent credentials, or submit App Review, without approval at the gate.**

## Permissions required
- `leads_retrieval` (Advanced Access) — fetch a lead's `field_data` by `leadgen_id`.
- `pages_manage_metadata` — subscribe the Page to the `leadgen` webhook field.
- `pages_show_list` / Page access as needed to enumerate forms.
- App-secret proof is sent on every Graph call (`appsecret_proof = HMAC-SHA256(access_token, app_secret)`).

## Reviewer workflow (once assets exist)
1. Map each Page/form to a brand in `tblse_meta_forms` (page_id, form_id, field map).
2. Store the Page system-user token in option `se_meta_page_token_<brand_id>`; set app secret in
   `se_meta_app_secret` and the webhook verify token in `se_meta_webhook_verify_token`.
3. Set the callback URL to `https://crm.roozbeh.com.tr/se_core/leadgen`; subscribe the Page to `leadgen`.
4. Submit a test lead via Meta's Lead Ads Testing Tool → webhook stores it → cron fetches field_data →
   a CRM lead appears (brand-stamped, `meta_lead_id` preserved) → consent captured → CAPI "Lead" queued.

## Demonstration script
1. Test lead submitted → `tblse_meta_leadgen_events` row (deduplicated on leadgen_id).
2. Cron processes → lead created with mapped fields + `meta_lead_id`; consent ledger entry.
3. Meta health page shows page/form mapping, dataset, token status, last webhook, pending/failed outbox.
4. Outbound CAPI event (`system_generated`, `event_source=crm`, `lead_event_source=SEO Evaluate CRM`).

## Current implementation status (honest)
- Webhook receiver (`/se_core/leadgen`): GET verify **live-tested** (challenge / 403). POST signature +
  durable dedup store: **fixture + cron tested**; public POST route CSRF-disabled until go-live.
- Big-integer-safe decode, page/form routing, per-form field mapping, consent capture, `meta_lead_id`
  dedup, CAPI "Lead" queueing: **fixture-tested (21/0)**; cron gated-hold + dedup + unmapped **5/0**.
- Live `field_data` fetch (Graph GET with appsecret_proof), token-expiry diagnostics, reconciliation
  re-fetch: **built but externally gated** on `leads_retrieval` Advanced Access + a Page token.
- Outbound CAPI: complete + verified (Phase 0); per-brand enable/disable toggle (`se_capi_enabled`);
  dataset-health via `se_meta_health()`.

## Externally gated (owner action)
Second Meta app / ads integration, persistent Page token + app secret, subscribe production webhook
(+ `csrf_exclude_uris` deploy step for `se_core/leadgen`), App Review submission.
