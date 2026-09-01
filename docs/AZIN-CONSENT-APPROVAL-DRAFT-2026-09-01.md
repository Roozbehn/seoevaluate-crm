# Azin Asgari consent wording — approval draft

Date: 2026-09-01  
Brand: 22  
Status: **DRAFT — disabled; owner/privacy-counsel approval required before activation**

## Why the current stored draft must not be enabled

The production record currently repeats the enquiry-contact sentence for both
`ads` and `marketing`. Those are different purposes. Contacting a person to
answer the request they submitted does not silently authorize Meta measurement
or later promotional messages.

The website already enforces this separation in code:

- the required consultation checkbox authorizes a reply about that enquiry;
- the cookie banner separately controls Meta Pixel/CAPI measurement;
- no advertising event is enqueued unless the server validates a current
  marketing-category cookie decision.

## Proposed version

`kvkk-2026-09-v1`

Do not activate this version until the legal identity/controller details and
wording have been reviewed.

## Ads measurement (`ads`)

**TR**

Meta Pixel ve Dönüşümler API'si aracılığıyla reklam performansını ölçmek
amacıyla form gönderimime ilişkin olay verilerinin Meta ile paylaşılmasına izin
veriyorum. Bu tercih isteğe bağlıdır ve talebime yanıt verilmesini etkilemez.
Ayrıntılar için Gizlilik Politikası'nı okuyun.

**EN**

I allow data about my form submission to be shared with Meta through the Meta
Pixel and Conversions API to measure advertising performance. This choice is
optional and does not affect whether my enquiry is answered. See the Privacy
Policy for details.

## Promotional communications (`marketing`)

**TR**

Azin Asgari'den hizmetler ve güncellemeler hakkında tanıtım amaçlı elektronik
iletiler almak istiyorum. Onayımı dilediğim zaman geri çekebilirim.

**EN**

I would like to receive promotional electronic communications from Azin Asgari
about services and updates. I can withdraw my consent at any time.

## Approval checklist

- Confirm the formal data-controller/legal-entity name and contact details.
- Confirm whether the marketing purpose is needed at all for the first launch.
- Confirm channels covered by marketing consent (email, SMS, WhatsApp) rather
  than relying on generic language.
- Confirm the Turkish wording against KVKK and electronic-commercial-message
  requirements; this document is operational copy, not legal advice.
- Keep both purposes disabled until approval is recorded.
