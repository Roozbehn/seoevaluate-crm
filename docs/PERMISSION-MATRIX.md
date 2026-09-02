# Permission Matrix (per role × surface)

**Evidence basis — read this first.** The rows below are derived from the source
(capability guards and brand-scope helpers, file:line in `docs/ROUTE-UI-INVENTORY.md`
and the C1 route audit) and proven at two tiers: the **real-MariaDB** tier (86
assertions, executing the real model guards with synthetic staff/brand fixtures)
and the **HTTP** tier (146 assertions, route/method/capability over the deployed
app). The **administrator** column is additionally **browser-verified** (authenticated
admin session, genuine viewport captures). The one-brand / unmapped / all-brands
columns are **code+model+HTTP verified but NOT yet browser-verified** — a restricted
staff account was out of scope this phase, so the rendered-UI restricted pass in
`MANUAL-UI-CHECKLIST.md` remains an owner action. Do not read this matrix as
"restricted users were browser-tested".

Capabilities: `se_brands` (config), `se_reports` (reporting),
`se_tenancy.all_brands` / `se_tenancy.triage_unassigned` (cross-brand reach),
`se_consent.manage` (consent wording only — clinic mode), plus per-feature
`view/create/edit/delete` on `se_patients` / `se_appointments` / `se_whatsapp`.

**Clinic mode (see `CLINIC-MODE.md`)** changed three rows below and added the
two seeded roles. The sidebar is now flat (Dashboard, Leads, Patients,
Appointments, WhatsApp, Customers, Reports) plus an admin-only *Integrations*
group; the clinic dashboard admits any clinic role; Consent Settings admits
`se_consent.manage`. The fake-DB suite `test_clinic.php` (147 assertions)
covers the new gates, the seeded roles and provisioning; the restricted-user
browser pass remains an owner action.

## Roles
- **Administrator** — `is_admin()`; sees and reaches everything, all brands.
- **One-brand staff** — mapped to exactly one brand via `tblse_staff_brands`, holds
  ordinary feature capabilities, no `se_tenancy.*`.
- **Unmapped staff** — authenticated, holds some feature capability, but has **no**
  brand mapping and no `se_tenancy.*`.
- **All-brands / triage staff** — holds `se_tenancy.all_brands` (or
  `triage_unassigned` for brand-0).
- **Clinic Owner (seeded role)** — one-brand staff holding `customers.*`,
  `leads.view/delete`, `se_patients.*`, `se_appointments.*`, `se_whatsapp.*`,
  `se_reports.view`, `se_consent.manage`. No `se_brands.*`, no `se_tenancy.*`.
- **Sales (seeded role)** — one-brand staff holding `customers.view/create/edit`,
  `leads.view`, and `view/create/edit` on patients, appointments, WhatsApp.
  No delete, no reports, no configuration.

## Matrix
| Surface | Administrator | One-brand staff | Unmapped staff | All-brands staff |
|---------|---------------|-----------------|----------------|------------------|
| Clinic sidebar items (Patients, Appointments, WhatsApp, Reports) | all four | items for held capabilities only | items for held capabilities only | Reports (+ feature items held) |
| *Integrations* group (Meta, Outbox, Google, Health, Credentials, Consent) | all six | **hidden** unless `se_brands.view`; with `se_consent.manage` alone, Consent Settings appears as a plain sidebar item | hidden | hidden (Health needs report **and** configure) |
| Core Perfex sidebar (Sales, Subscriptions, Expenses, Contracts, Projects, Tasks, Support, Estimate Request, Knowledge Base, Utilities, Reports) | **removed for everyone** (`se_clinic_filter_sidebar`) | removed | removed | removed |
| Setup menu | shown | **hidden** (`show_setup_menu` → admin only) | hidden | hidden |
| `/admin` (Perfex dashboard) | redirects to the clinic dashboard | redirects if any clinic capability is held | Perfex dashboard | redirects |
| Clinic dashboard (`se_core/se_dashboard`) | all cards + warnings + Health button | six clinic cards; Outbox cards with `se_reports.view`; Consent card with `se_consent.manage`; Meta/Google/Credentials cards and warnings only with `se_brands.view` | **no-brand panel** | six clinic cards + Outbox cards |
| Dashboard counts | all brands | own brand only (scoped) | **empty / no-brand** (never brand-0 leak) | all brands |
| Patients list | all brands | own brand only | **empty (fail-closed `1=0`)** | all/allowed brands |
| Patient view by foreign id | allowed | **access denied** (brand scope) | denied | allowed within reach |
| Patient create/edit/delete | allowed | needs the capability; own brand only | denied (no reachable brand) | allowed within reach |
| Appointments list/feed | all brands | own brand only (fail-closed) | empty | all/allowed |
| Appointment cross-brand id | allowed | **denied** | denied | allowed within reach |
| WhatsApp inbox / conversation | all brands | own brand only; foreign id denied | empty | all/allowed |
| WhatsApp assign | allowed | assignee must map to the conversation's brand | **cannot be assigned** (zero-brand no longer treated admin-like) | allowed |
| Meta Lead Ads screen (brand=0) | every brand's events | **only accessible brands' events** (fixed) | none | cross-brand events |
| Meta requeue (foreign event) | allowed | **refused** unless caller can access the event's brand (fixed) | refused | allowed within reach |
| Conversion Outbox | all; requeue needs configure | own brand; requeue needs configure | empty | all/allowed |
| SE Reports / report data | all brands | own brand; foreign brand → 401 | **empty (brand −1, zero aggregates)** — no brand-0 leak (fixed) | all/allowed |
| Integration Health page | allowed (report) | allowed only if report-capable | empty/denied | allowed |
| Integration Health **nav item** | shown | shown only to report-capable (fixed: no longer to configure-only) | hidden | shown |
| Credentials config | configure-capable, accessible brands | configure-capable, own brand | denied | configure-capable |
| Consent config | allowed | `se_brands.view` **or** `se_consent.manage`, own brand | denied | configure-capable |
| Public webhooks | n/a (unauthenticated; HMAC-gated) | — | — | — |

Rows marked **(fixed)** were authorization defects closed this phase (see the
Workstream-A commit); each has a fake-DB and/or real-DB test.

## The owner's remaining restricted-user browser pass
`MANUAL-UI-CHECKLIST.md` → *Authorization QA*: repeat the denied-by-id, empty-state,
menu-visibility and AJAX-authorization checks while logged in as a one-brand and an
unmapped staff account. Route- and model-level authorization is already proven by
the HTTP and real-MariaDB tiers; this pass proves it **through the rendered UI as a
restricted human user**, which cannot be self-verified without creating accounts.

## Patient journey (`se_journey`) — added 2026-09-02

Feature `se_journey`, default deny; admins pass. Brand scoping applies to every read.

| Capability | Clinic Owner | Sales | Admin | Gate in code |
|---|---|---|---|---|
| `view` (header, timeline, tasks; masked identity) | ✓ | ✓ | ✓ | `Se_journey::__construct`, list/lead tab |
| `view_health` (intake answers, check-in replies) | ✓ | – | ✓ | `view` tab=intake (audited `view_intake`) |
| `view_photos` (sealed photos via signed 10-min URL) | ✓ | – | ✓ | `media()` + `se_journey_media_signature_valid` (audited `view_photo`) |
| `edit_review` (decisions, quote drafts, automation pause/resume, close) | ✓ | – | ✓ | `action()` |
| `approve_quote` | ✓ | – | ✓ | `se_journey_quote_approve()` re-checks the capability |
| `manage_consultation` (book, status, pre-op, procedure) | ✓ | ✓ | ✓ | `action()` |
| `manage_aftercare` (protocol start, complete) | ✓ | – | ✓ | `action()` |
| `export_health` (JSON export, audited `export_intake`) | ✓ | – | ✓ | `export()` |
| `manage_templates` (Meta submission, sync, test send to allow-list) | – | – | ✓ (or brand-config staff) | `templates()` |
| `manage_consent` (clinical gates, protocols, copy) | ✓ | – | ✓ | `settings()` sections clinical/copy |
| consent-text emergency bypass | – | – | ✓ (reason required, audited) | `se_journey_set_consent_bypass()` |

Internal notes and margins never enter any patient payload (`se_journey_quote_snapshot` builds
from an explicit allow-list). Dashboards show counts only; no health flag or thumbnail leaves
the journey view. The role grant is applied once by `se_journey_grant_clinic_roles()` (option
`se_journey_roles_version`), additively, to the seeded roles only.
