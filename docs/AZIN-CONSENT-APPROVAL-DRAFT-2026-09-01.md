# Azin Asgari consent wording — approved configuration

Date: 2026-09-01  
Brand: 22  
Status: **APPROVED by owner on 2026-09-01; activated for brand 22**
Approved legal/controller name: **Azin Asgari**

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

This version was activated after the owner confirmed the legal name and the
need for marketing consent across email, SMS, WhatsApp, telephone calls and
other electronic communication channels.

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

Azin Asgari'den hizmetler, kampanyalar ve güncellemeler hakkında e-posta, SMS,
WhatsApp, telefon araması ve diğer elektronik iletişim kanalları üzerinden
tanıtım ve pazarlama iletileri almayı kabul ediyorum. Onayımı dilediğim zaman
geri çekebilirim.

**EN**

I agree to receive promotional and marketing communications from Azin Asgari
about services, campaigns and updates by email, SMS, WhatsApp, phone calls and
other electronic communication channels. I can withdraw my consent at any
time.

## Approval record

- Legal/controller name confirmed by owner: Azin Asgari.
- Ads measurement consent approved.
- Marketing consent approved for email, SMS, WhatsApp, phone calls and other
  electronic communication channels.
- Both purposes enabled in production under version `kvkk-2026-09-v1`.
- This document records the operational approval; it is not legal advice.
