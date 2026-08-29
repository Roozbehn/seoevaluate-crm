# Google Data Manager — Setup Checklist (owner + operator)

Live conversion upload is **externally gated**. The CRM code (payload build, batching, validation,
retry/poll, landing-token attribution, health) is complete and fixture-tested; it starts delivering the
moment the items below exist. **Do not create cloud credentials or submit live conversions without owner
approval at the gate.**

## Owner / account decisions (gated)
1. **Google Ads MCC** access to the clinic account(s); record each brand's Google Ads **customer id**
   (digits) → `tblse_brands.google_ads_customer_id`.
2. **Google Cloud project** for the Data Manager API; enable the **Data Manager API**.
3. **Service account** in that project; grant it Data Manager access to the operating account(s).

> ### ⚠️ BLOCKER — the current authentication design must be replaced before going live
>
> Today `se_gdm_access_token()` reads a **static bearer token** from option
> `se_google_sa_token_<brand_id>` (fallback `se_google_sa_token`) and `se_gdm_ingest()` sends it directly
> as `Authorization: Bearer <token>`. There is **no service-account key, no signed JWT assertion, no OAuth
> token exchange and no refresh path**. Google service-account access tokens expire in about **one hour**,
> so this design breaks roughly hourly and requires a human to paste a new token. **Do not build live
> authentication around it.**
>
> **Required instead:** obtain **renewable short-lived access tokens** from **service-account credentials**
> (JSON key → signed JWT → token exchange) or from **Application Default Credentials**, cached until shortly
> before expiry. Store only the **secure credential reference / configuration needed for renewal** — a
> filesystem path to a `600` key file **outside the document root and outside Git**, or an ADC
> configuration — **never a bare access token**, and never a plaintext secret in `tbloptions`.
>
> - <https://developers.google.com/data-manager/api/devguides/quickstart/set-up-access>
> - <https://developers.google.com/identity/protocols/oauth2/service-account>
>
> **No credential was added during the documentation-correction phase, and no existing option was migrated
> or deleted. A separate remediation proposal is required before any live Google conversion is sent.**
4. **Data Manager permissions**: the service account must be linked to each operating account with
   permission to ingest events (scope `https://www.googleapis.com/auth/datamanager`).
5. **Conversion actions**: create one per stage you upload (e.g. "Lead", "Consultation Held"); record the
   conversion-action id → option `se_google_conv_action_<brand_id>_<event_slug>` (or a per-brand default
   `se_google_conv_action_<brand_id>`).
6. **GA4 property** id per brand → `tblse_brands.ga4_property_id` (for reporting, Phase 6).
7. **Search Console** property (site URL) per brand → `tblse_brands.gsc_site_url` (Phase 6).
8. **Landing-token secret**: set option `se_landing_token_secret` (random) to enable the WhatsApp-origin
   click-attribution token flow.

## What the CRM already does (fixture-tested, 33/0)
- Builds `events:ingest` payloads: `encoding=HEX`, `destinations[].operatingAccount{GOOGLE_ADS,accountId}`
  + `productDestinationId`, per-event `destinationReferences`, `transactionId`, RFC3339-Z `eventTimestamp`,
  `adIdentifiers{gclid,gbraid,wbraid}`, `userData.userIdentifiers[{emailAddress|phoneNumber: SHA-256 hex}]`
  (only with ad consent), `consent{adUserData,adPersonalization}`.
- Conversion-time validation (>=6h, <=90d); per-request cap 2000 events; per-event error isolation.
- Outbox integration (drains `google_dm` rows); request tracking (`tblse_gdm_requests`) + poll hook.
- WhatsApp-origin landing token: `se_landing_token_create/verify/apply_to_lead` (HMAC-signed, time-limited)
  preserves gclid/gbraid/wbraid across the click-to-WhatsApp hop.
- Per-brand health: `se_google_health($brand)`.

## Policy
No clinical field (procedure/diagnosis/body area/photo/health) is ever attached to a conversion,
transactionId, or any Data Manager field — Google prohibits health-tied conversion data.

## Externally gated steps (owner action)
Create the Cloud project + service account + credentials; link Data Manager permissions; create conversion
actions; submit the first live conversion. Until then, `google_dm` outbox rows are held (nothing sent).
