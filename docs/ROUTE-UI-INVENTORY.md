# Route / UI / Permission Inventory

Generated from source at schema **v11**. Every route below was requested in a
real authenticated browser session and returned HTTP 200 unless marked
otherwise; every unauthenticated request returned 307 to login (HTTP tier).

## Status vocabulary

| Label | Meaning |
|-------|---------|
| **backend** | Server-side logic exists |
| **UI** | A usable screen exists, inside the Perfex layout |
| **nav** | Reachable from the SEO Evaluate CRM navigation group |
| **browser** | Rendered and inspected in an authenticated browser |
| **fake-DB** | Covered by the network-free unit suite |
| **real-DB** | Covered against MariaDB with real model execution |
| **HTTP** | Covered by real HTTP requests |
| **gated** | Externally blocked; needs an owner action outside the CRM |
| **not built** | Deliberately absent, and reported as absent in the UI |

## Screens

| Screen | URL | Capability | backend | UI | nav | browser | mobile |
|--------|-----|-----------|:-------:|:--:|:---:|:-------:|:------:|
| SE Dashboard | `/admin/se_core/se_dashboard` | `se_reports.view` or `se_brands.view` | yes | yes | yes | yes | 390/768 |
| Patients list | `/admin/se_core/se_patients` | `se_patients.view` | yes | yes | yes | yes | 390 |
| Patient create | `/admin/se_core/se_patients/create` | `se_patients.create` | yes | yes | — | yes | — |
| Patient view | `/admin/se_core/se_patients/view/{id}` | `se_patients.view` | yes | yes | — | yes | — |
| Patient edit | `/admin/se_core/se_patients/edit/{id}` | `se_patients.edit` | yes | yes | — | yes | — |
| Patient archive | `/admin/se_core/se_patients/archive/{id}` | `se_patients.delete` | yes | POST | — | yes | — |
| Deletion request | `/admin/se_core/se_patients/request_deletion/{id}` | `se_patients.delete` | yes | POST | — | yes | — |
| Appointments list | `/admin/se_appointments/se_appointments/manage` | `se_appointments.view` | yes | yes | yes | yes | 390 |
| Appointment calendar | `/admin/se_appointments/se_appointments/index` | `se_appointments.view` | yes | yes | — | yes | — |
| Appointment create | `/admin/se_appointments/se_appointments/create` | `se_appointments.create` | yes | yes | — | yes | 390 |
| Appointment edit | `/admin/se_appointments/se_appointments/edit/{id}` | `se_appointments.edit` | yes | yes | — | yes | — |
| Appointment view | `/admin/se_appointments/se_appointments/view/{id}` | `se_appointments.view` | yes | yes | — | yes | — |
| Appointment status | `/admin/se_appointments/se_appointments/status/{id}` | `se_appointments.edit` | yes | POST | — | yes | — |
| WhatsApp inbox | `/admin/se_whatsapp/se_whatsapp/inbox` | `se_whatsapp.view` | yes | yes | yes | yes | 390 |
| WhatsApp conversation | `/admin/se_whatsapp/se_whatsapp/conversation/{id}` | `se_whatsapp.view` | yes | yes | — | yes | — |
| WhatsApp reply (queue) | `/admin/se_whatsapp/se_whatsapp/reply/{id}` | `se_whatsapp.create` | yes | POST | — | yes | — |
| WhatsApp assign | `/admin/se_whatsapp/se_whatsapp/assign/{id}` | `se_whatsapp.edit` | yes | POST | — | yes | — |
| WhatsApp readiness | `/admin/se_whatsapp/se_whatsapp/readiness` | `se_brands.view` | yes | yes | — | yes | — |
| Meta Lead Ads | `/admin/se_core/se_meta` | `se_brands.view` | yes | yes | yes | yes | — |
| Conversion Outbox | `/admin/se_core/se_outbox` | `se_reports.view` or `se_brands.view` | yes | yes | yes | yes | 390 |
| Outbox detail | `/admin/se_core/se_outbox/detail/{id}` | as above | yes | yes | — | yes | — |
| Google Data Manager | `/admin/se_core/se_google` | `se_brands.view` | yes | yes | yes | yes | — |
| Integration Credentials | `/admin/se_core/se_credentials` | `se_brands.view` | yes | yes | yes | yes | — |
| Consent Settings | `/admin/se_core/se_consent` | `se_brands.view` | yes | yes | yes | yes | — |
| SE Reports | `/admin/se_core/se_reports/index` | `se_reports.view` | yes | yes | yes | yes | — |
| Integration Health | `/admin/se_core/se_reports/health` | `se_reports.view` | yes | yes | yes | yes | 390/768 |
| Brands (Setup) | `/admin/se_core/brands` | `se_brands.view` | yes | yes | Setup | yes | — |

## Public endpoints

| Endpoint | Method | Current state |
|----------|--------|---------------|
| `/se_whatsapp/webhook` | GET | Verification; refuses wrong/missing/empty token (403) |
| `/se_whatsapp/webhook` | POST | **CSRF-gated (403)** — cannot receive until the owner adds the narrow `csrf_exclude_uris` entry |
| `/se_core/leadgen` | GET | Verification; refuses wrong token (403) |
| `/se_core/leadgen` | POST | **CSRF-gated (403)** — same |

## Permission matrix

| Feature | Capability | Grants |
|---------|-----------|--------|
| `se_brands` | `view` / `create` / `edit` / `delete` | Brand **configuration** only. No cross-brand data access. |
| `se_reports` | `view` | Reporting screens, scoped to the staff member's own brands. |
| `se_tenancy` | `all_brands` | **Cross-brand data reach.** Grant deliberately. |
| `se_tenancy` | `triage_unassigned` | Access to brand-0 (unassigned) records. |
| `se_patients` | `view` / `create` / `edit` / `delete` | Patient records within reachable brands. |
| `se_appointments` | `view` / `create` / `edit` / `delete` | Appointments within reachable brands. |
| `se_whatsapp` | `view` / `create` / `edit` / `delete` | Conversations within reachable brands. |

No ordinary `view`/`create`/`edit`/`delete` on any feature implies cross-brand
access. Only `se_tenancy.all_brands`, or being a Perfex admin, does.

An unmapped ordinary staff member reaches **nothing**: the scope predicate is
`1=0`, and every SE screen shows an explanatory "no brand assigned" panel
rather than an empty table or a 500.
