# Route / UI Evidence Matrix (v3)

Per claimed UI screen: exact route, controller method, capability, brand-scope rule, menu visibility, HTTP result, and genuine-viewport screenshots at desktop (1728px), tablet (768px) and mobile (390px). Files under `crm-ui-screenshots-v3/{desktop,tablet,mobile}/`. Captured in an authenticated **admin** session in real Chrome with DevTools CDP device emulation (`Emulation.setDeviceMetricsOverride`) — genuine top-level viewports, not iframes and not a clamped window. A Cloudflare *managed* challenge auto-clears on navigation. Synthetic fixture #950001 (patient + WhatsApp conversation, no real data) backed the two detail views and was removed afterwards.

| Feature | Route | Controller::method | Capability | Brand-scope | Menu | HTTP | Desktop | Tablet | Mobile | Dark | Console/AJAX | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| SEO Evaluate dashboard | `/admin/se_core/se_dashboard` | Se_dashboard::index | report OR configure | has-brand; se_apply_scope_in | yes | 200 | `01-dashboard-desktop.jpg` | `01-dashboard-768.jpg` | `01-dashboard-390.jpg` | yes | clean / — | live counters + warnings |
| Appointments — create | `/admin/se_appointments/se_appointments/create` | Se_appointments::create | se_appointments.create | accessible brands only | yes | 200 | `02-appointment-form-desktop.jpg` | `02-appointment-form-768.jpg` | `02-appointment-form-390.jpg` | yes | clean / — | relation picker; availability panel; TZ |
| Patient detail view | `/admin/se_core/se_patients/view/{id}` | Se_patients::view | se_patients.view | brand-scoped; read logged | via Patients | 200 (#950001) | `03-patient-view-desktop.jpg` | `03-patient-view-768.jpg` | `03-patient-view-390.jpg` | yes | clean / — | no name field; access history logged |
| WhatsApp conversation | `/admin/se_whatsapp/se_whatsapp/conversation/{id}` | Se_whatsapp::conversation | se_whatsapp.view | scoped; foreign id denied | via inbox | 200 (#950001) | `04-whatsapp-conversation-desktop.jpg` | `04-whatsapp-conversation-768.jpg` | `04-whatsapp-conversation-390.jpg` | yes | clean / — | server-chosen disabled composer; window badge |
| Conversion Outbox | `/admin/se_core/se_outbox` | Se_outbox::index | report OR configure | has-brand; se_apply_scope_in | yes | 200 | `05-conversion-outbox-desktop.jpg` | `05-conversion-outbox-768.jpg` | `05-conversion-outbox-390.jpg` | yes | clean / — | status chips; filters; empty state |
| Integration Health | `/admin/se_core/se_reports/health` | Se_reports::health | report | brand() resolver; foreign→401 | yes | 200 | `06-integration-health-desktop.jpg` | `06-integration-health-768.jpg` | `06-integration-health-390.jpg` | yes | clean / — | cron healthy; gated blockers |
| Patients list (supplemental) | `/admin/se_core/se_patients` | Se_patients::index | se_patients.view | fail-closed 1=0 | yes | 200 | — | — | `07-patients-list-390.jpg` | yes | clean / — | — |
| WhatsApp inbox (supplemental) | `/admin/se_whatsapp/se_whatsapp/inbox` | Se_whatsapp::inbox | se_whatsapp.view | has-brand; fail-closed | yes | 200 | — | — | `08-whatsapp-inbox-390.jpg` | yes | clean / — | gated banner |
| SE Reports (supplemental) | `/admin/se_core/se_reports/index` | Se_reports::index | report | brand() resolver; AJAX /data?brand=22→200 | yes | 200; AJAX 200 | — | `07-reports-768.jpg` | — | yes | clean / AJAX 200 | funnel cards |

## Required-set completeness
All **six** required screens have all **three** genuine viewports:
dashboard, appointment form, patient detail view, WhatsApp conversation, conversion outbox, integration health — 18 core captures, plus 3 supplemental (patients list 390, WhatsApp inbox 390, reports 768) = **21 genuine viewport screenshots**.

## Verification method (asserted per capture via page JS)
- **Mobile 390×844** — DevTools Device Toolbar / CDP device metrics: `innerWidth===390`, `matchMedia('(max-width:480px)')===true`, DPR 2.
- **Tablet 768×1024** — `innerWidth===768`, `matchMedia('(max-width:768px)')===true`.
- **Desktop 1728×996** — maximized window, `innerWidth===1728`, `matchMedia('(min-width:1280px)')===true`.
- No horizontal page overflow (`scrollWidth<=innerWidth`) on any capture; responsive tables scroll inside their own `.table-responsive` container.
- All captures Dark Theme (deployed default). Console clean on every page checked; the only AJAX endpoint exercised, `/se_reports/data?brand=22`, returned 200.

## Not covered here (owner action)
Restricted-role *rendered-UI* pass (one-brand / unmapped / triage staff) — see `PERMISSION-MATRIX.md`; authorization is already proven at the HTTP (146) and real-MariaDB (86) tiers.
