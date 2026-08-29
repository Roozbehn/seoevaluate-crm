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
plus per-feature `view/create/edit/delete` on `se_patients` / `se_appointments` /
`se_whatsapp`.

## Roles
- **Administrator** — `is_admin()`; sees and reaches everything, all brands.
- **One-brand staff** — mapped to exactly one brand via `tblse_staff_brands`, holds
  ordinary feature capabilities, no `se_tenancy.*`.
- **Unmapped staff** — authenticated, holds some feature capability, but has **no**
  brand mapping and no `se_tenancy.*`.
- **All-brands / triage staff** — holds `se_tenancy.all_brands` (or
  `triage_unassigned` for brand-0).

## Matrix
| Surface | Administrator | One-brand staff | Unmapped staff | All-brands staff |
|---------|---------------|-----------------|----------------|------------------|
| SEO Evaluate nav group | all 11 items | items for held capabilities only | items for held capabilities only | all reporting/config items held |
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
| Credentials / Consent config | configure-capable, accessible brands | configure-capable, own brand | denied | configure-capable |
| Public webhooks | n/a (unauthenticated; HMAC-gated) | — | — | — |

Rows marked **(fixed)** were authorization defects closed this phase (see the
Workstream-A commit); each has a fake-DB and/or real-DB test.

## The owner's remaining restricted-user browser pass
`MANUAL-UI-CHECKLIST.md` → *Authorization QA*: repeat the denied-by-id, empty-state,
menu-visibility and AJAX-authorization checks while logged in as a one-brand and an
unmapped staff account. Route- and model-level authorization is already proven by
the HTTP and real-MariaDB tiers; this pass proves it **through the rendered UI as a
restricted human user**, which cannot be self-verified without creating accounts.
