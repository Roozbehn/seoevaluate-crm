# Clinic Mode — Azin Asgari – Kaş Ekimi, İstanbul

The CRM now serves one clinic with three accounts: an administrator, the
clinic owner (Azin) and a sales person. This document is the owner's runbook
for the change: what it does, how to deploy it, what to do by hand afterwards,
how to verify it, and how to undo it.

Code: `modules/se_core/se_clinic.php` (all clinic logic), `se_navigation.php`
(flat sidebar), `controllers/Se_dashboard.php` + `views/se_dashboard.php`
(role-aware dashboard), `controllers/Se_consent.php` (consent capability),
language files (EN + TR). Tests: `modules/se_core/tests/test_clinic.php`.

## 1. What changes

| Area | Before | After |
|------|--------|-------|
| Sidebar | Perfex's full agency menu + one grouped "SEO Evaluate CRM" section | Flat: **Dashboard, Leads, Patients, Appointments, WhatsApp, Customers, Reports**, then an **Integrations** group (Meta Lead Ads, Conversion Outbox, Google Data Manager, Integration Health, Integration Credentials, Consent Settings) that only configuration-capable staff see; a staff member whose only integration item is Consent Settings gets it as a plain item. Sales, Subscriptions, Expenses, Contracts, Projects, Tasks, Support, Estimate Request, Knowledge Base, Utilities and core Reports are removed for everyone. |
| Quick-create (+) | Invoice, estimate, proposal, … (and stock Perfex hides "Lead" from every non-admin) | Lead and Customer for clinic staff; plus Staff member for administrators |
| Setup menu | Every staff member | Administrators only |
| `/admin` | Perfex's invoice/ticket dashboard | Redirects to the clinic dashboard for anyone holding a clinic capability |
| Clinic dashboard | `se_reports.view` or `se_brands.view` | Any clinic role. Six clinic cards for everyone; Conversion Outbox cards for report-capable staff; Meta, Google, Credentials cards, the Integration Health button and the system-warnings banner for configuration-capable staff (administrators); the Consent card for `se_consent.manage`. Every card links only to a screen its holder can open. The brand badge row is gone while there is one brand. |
| Consent Settings | `se_brands.view` | `se_brands.view` **or** the new `se_consent.manage` |
| Brand name | "TurquAI CRM" | "Azin Asgari – Kaş Ekimi, İstanbul" (slug `azin-asgari`) |
| Company name | as set | "Azin Asgari – Kaş Ekimi, İstanbul" |
| UI language | English | **Turkish** default for new accounts; every account that existed at deploy time is pinned to English (change it in the profile if wanted) |
| Roles | none seeded | **Clinic Owner** and **Sales** (see §3) |
| Staff ↔ brand | manual tick on Setup → Brands | Automatic while there is exactly one active brand: existing active staff are mapped once at deploy, new staff on creation |
| Brand-0 records | visible only to admins / triage staff | Folded into the clinic once at deploy; new leads are stamped with the clinic whoever creates them — including web-to-lead submissions, whose `lead_created` payload is an array (`Forms.php`) that the previous stamping hook could not read |

Clinic mode is a property of the data, not a switch: everything in the
"single-brand" rows above is active only while exactly one active brand
exists. Add a second active brand on Setup → Brands and the CRM behaves as the
multi-brand tool it was — nothing has to be turned off.

## 2. Deploy

Same procedure as `PRODUCTION-READINESS-RUNBOOK.md` §7.2. On the server
(`/home/hyundaic/crm.roozbeh.com.tr`):

```sh
git fetch origin && git checkout main && git pull --ff-only origin main
git rev-parse HEAD            # must equal the reviewed release hash
touch ~/.lsphp_restart.txt    # LiteSpeed opcache
```

`application/config/app-config.php` and `database.php` are untracked and are
not touched.

Then log in once as the administrator. The first admin request after the
deploy runs, in order:

1. `se_core_migrate()` — no schema change in this release (still v11).
2. `se_clinic_provision()` — the one-shot data step: brand rename, company
   name, language, roles, staff mapping, brand-0 folding. It records
   `se_clinic_provision_version = 1` in `tbloptions` and one activity-log line
   ("Clinic provisioning v1 applied: …"). It runs once; a second request does
   nothing.

If the schema were ever behind, provisioning waits for it and retries on the
next request.

## 3. Create the two logins (by hand)

Provisioning creates the roles, not the accounts. Do this **after** the
deploy, so the new accounts inherit Turkish rather than being pinned to
English.

Setup → Staff → **New Staff Member**, twice:

| Field | Azin | Sales |
|-------|------|-------|
| Email | azin@azinasgari.com | sales@azinasgari.com |
| Role | **Clinic Owner** | **Sales** |
| Administrator | unticked | unticked |
| Staff member (`is_not_staff`) | staff (default) | staff (default) |
| Default language | leave empty (→ Turkish) | leave empty (→ Turkish) |
| Send welcome email | tick | tick |

Selecting the role ticks the permission boxes (Perfex copies the role's
permissions into the account at save time — editing the role later does not
change existing accounts unless "update staff permissions" is ticked on the
role). Both accounts are mapped to the clinic automatically on save; Setup →
Brands shows them ticked.

What each role holds:

| Feature | Clinic Owner | Sales |
|---------|--------------|-------|
| Customers | view, create, edit, delete | view, create, edit |
| Leads | view (all), delete | view (all) |
| Patients | view, create, edit, delete | view, create, edit |
| Appointments | view, create, edit, delete | view, create, edit |
| WhatsApp | view, create, edit, delete | view, create, edit |
| Reports | view | — |
| Consent settings | manage | — |
| Brand configuration, tenant access, integrations, Setup, system settings | — | — |

Neither role holds `se_brands.*` or `se_tenancy.*`, so neither can reach
credentials, platform configuration, or another brand's data.

## 4. Verify

As the administrator:

- `/admin` lands on the clinic dashboard titled "Azin Asgari – Kaş Ekimi,
  İstanbul"; all cards, the warnings banner and the Integration Health button
  are present.
- Sidebar reads Dashboard, Leads, Patients, Appointments, WhatsApp, Customers,
  Reports, Integrations (6 children), Setup. No Sales/Projects/Tasks/Utilities.
- Setup → Brands: one brand, "Azin Asgari – Kaş Ekimi, İstanbul", every active
  staff member ticked.
- Setup → Roles: "Clinic Owner" and "Sales" exist with the table in §3.
- Setup → Settings → General: company name is the clinic. Localisation:
  default language Turkish. Your own profile still says English.

As Azin (owner) — in Turkish:

- Lands on the clinic dashboard; sees the six clinic cards, the two
  Conversion Outbox cards and the Consent card — **no** Meta/Google/Credentials
  cards, **no** warnings banner, **no** Integration Health button, no brand
  badge row. The (+) quick-create menu offers Lead and Customer.
- Sidebar: Kontrol Paneli, Fırsatlar (Leads), Hastalar, Randevular,
  WhatsApp, Müşteriler, Raporlar, Onay ayarları (a plain item — a one-child
  Integrations group is not shown). No Setup (Kurulum) item.
- Can open Consent Settings; `/admin/se_core/se_credentials`,
  `/admin/se_core/se_meta`, `/admin/se_core/brands` → access denied.
- Can archive a patient and delete a lead.

As Sales — in Turkish:

- Same dashboard with only the six clinic cards.
- Sidebar: Kontrol Paneli, Fırsatlar, Hastalar, Randevular, WhatsApp,
  Müşteriler. No Reports, no Integrations, no Setup.
- Patient/appointment/WhatsApp create and edit work; archive/delete controls
  are absent; `/admin/se_core/se_reports/index` and `/admin/se_core/se_consent`
  → access denied.

Everyone:

- A lead created by the administrator is visible to Azin and Sales (it is
  stamped with the clinic brand — check `tblleads.brand_id`).
- `MANUAL-UI-CHECKLIST.md` → *Authorization QA* still applies for the
  rendered restricted-user pass.

## 5. Undo

Code: `git checkout <previous_release_hash>` + `touch ~/.lsphp_restart.txt`
(`ROLLBACK-PROCEDURE.md`). The old code ignores the new option and the new
capability rows; nothing breaks.

Data written by provisioning, and how to reverse each if wanted:

| Change | Reverse |
|--------|---------|
| `tblse_brands`: name/slug of the sole brand | Setup → Brands, edit |
| `tbloptions.companyname` | Setup → Settings → General |
| `tbloptions.active_language` = turkish; `tblstaff.default_language` = english for pre-existing accounts | Setup → Settings → Localisation; each profile |
| `tblroles`: Clinic Owner, Sales | Setup → Roles, delete |
| `tblse_staff_brands`: one row per active staff member | Setup → Brands, untick |
| `brand_id` 0 → clinic on leads, clients, patients, appointments, WhatsApp conversations | `UPDATE … SET brand_id = 0 WHERE …` — only if you really want them back in triage |
| `tbloptions.se_clinic_provision_version` | delete the option to make provisioning run again (it is idempotent) |

## 6. Optional follow-ups

- **Logo / favicon**: Setup → Settings → General → Company logo, dark logo,
  favicon. The Azin Asgari brand kit's AA-cipher SVG/PNG is the intended
  asset; the CRM ships no logo file in git.
- **Meta CAPI `lead_event_source`** still reads "SEO Evaluate CRM"
  (`se_capi.php`): it names the software, not the clinic, and Meta uses it
  only as a CRM identifier. Change it there if you prefer the clinic name.
- **Second admin?** Making Azin an administrator later is a Perfex staff edit
  (tick Administrator); admins bypass every gate here.

## 7. Evidence

| Tier | Result |
|------|--------|
| Fake-DB / unit (`php modules/se_core/tests/run.php`) | 1477 / 0 (was 1310; `clinic` suite adds 165, `webhook` F4 re-stated for the stricter Health nav gate). The harness no longer stubs a global `is_ajax_request()` — Perfex has none — so a bare call fails the suite instead of passing here and fatalling in production. |
| Sidebar composition (`php modules/se_core/tests/sidebar_sim.php`) | real `App_menu` + `menu_helper.php` + Menu Builder filters + clinic filters: the three role sidebars come out exactly as §4 lists them |
| PHP lint | every changed file clean |
| Real MariaDB / HTTP / browser | **not run in this change** — no Perfex base schema in the repo and no server access from the authoring session; §4 is the owner's pass |
