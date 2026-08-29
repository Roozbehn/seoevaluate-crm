# Manual UI Checklist (owner — after a normal login)

Log in normally at `https://crm.roozbeh.com.tr/admin`. Verify each item. Use **authorized test brands**
(create synthetic ZZ brands/leads, remove afterward) — **never real patient data**.

> **What has already been verified, and what has not.** Phases 10–13 performed authenticated browser
> verification of every screen below against the deployed application, using an existing operator browser
> session — no session was forged, cloned or inserted, and no cookie was exported. Rendering, navigation,
> translation, Dark Theme and responsive behaviour at 390 px / 768 px / desktop are therefore **verified**,
> and Patient CRUD UI and Dark Theme are no longer classified as pending on those grounds.
>
> **What remains is the part that structurally cannot be self-verified: the restricted-capability pass.**
> Every check in *Authorization QA* below requires a **second staff account limited to one brand and
> holding fewer capabilities**. Creating staff accounts was out of scope, so these are owner actions.
> Route-level and model-level authorization is already proven by the HTTP tier (49 assertions) and the
> real-MariaDB tier (86 assertions) — but proving it through the **rendered UI as a restricted human user**
> is what this section is for. Do not treat the two as interchangeable.

## Authorization QA (required — not just visual)
- [ ] A staff member **without** the `se_patients` view capability is denied `/admin/se_core/se_patients`.
- [ ] A staff member without create/edit/delete capability cannot reach those actions (no hidden-button-only gating).
- [ ] A staff member scoped to **Brand A** cannot open, edit or archive a **Brand B** patient **by id in the URL**.
- [ ] The same cross-brand id denial for appointments and for the WhatsApp inbox.
- [ ] A POST without a CSRF token is rejected (403) on patient create/edit/archive.
- [ ] Sensitive patient reads appear in `tblse_record_access_log`.

## Reporting & health
- [ ] Reporting dashboard: `/admin/se_core/se_reports/index` — cards, funnel, by-source, WhatsApp, spend/outcome load.
- [ ] Integration-health: `/admin/se_core/se_reports/health` — cron, outbox, token states, blockers, freshness.

## Patients
- [ ] Patient list: `/admin/se_core/se_patients` — list, search, pagination, "Show archived" toggle.
- [ ] Create: `/admin/se_core/se_patients/create` — brand_id + lead_id/client_id + language/nationality/passport; save.
- [ ] View: `/admin/se_core/se_patients/view/<id>` — links, consent history, access history render.
- [ ] Edit: `/admin/se_core/se_patients/edit/<id>` — update fields; save.
- [ ] Archive: from the edit screen — patient becomes archived; consent + history remain.
- [ ] **Brand isolation:** as a staff member limited to Brand A, confirm a Brand-B patient id in the URL
      (`/admin/se_core/se_patients/view/<B_id>`) is **denied** (access denied), and the list shows only Brand A.

## WhatsApp
- [ ] Inbox: `/admin/se_whatsapp/inbox` — list + filters (all/assigned-to-me/unassigned).
- [ ] Conversation: `/admin/se_whatsapp/conversation/<id>` — thread, states, reply-window/template state.

## Appointments
- [ ] Calendar: `/admin/se_appointments/index` · List: `/admin/se_appointments/manage`.
- [ ] Create/edit; reschedule; cancel (with reason); mark no-show; confirm status history + reminder behaviour.

## Lead profile tabs
- [ ] Open a lead (`/admin/leads`) → WhatsApp conversation tab + appointments tab render.

## Dark Theme
- [ ] Enable Dark Theme (module active) — admin UI renders in dark mode.
- [ ] Disable Dark Theme — reverts cleanly (non-destructive).

## Responsive
- [ ] Desktop layout OK.  - [ ] Mobile-width (≤ 480px) layout OK for dashboard, patient list, inbox.

## Dark Theme visual / responsive QA
- [ ] Dark mode renders correctly on: dashboard, leads list, lead profile, patients list/view/edit,
      appointments calendar + manage, WhatsApp inbox + conversation, reporting dashboard, integration health.
- [ ] Responsive/mobile widths: no overflow, no unreadable contrast, no clipped controls.
- [ ] Toggling the theme off restores the default appearance with no residue.

## Screens added since this checklist was first written
- [ ] SEO Evaluate dashboard: `/admin/se_core/se_dashboard`
- [ ] Conversion outbox: `/admin/se_core/se_outbox` and a detail screen
- [ ] Credentials status: `/admin/se_core/se_credentials` — confirm **no secret value is displayed**
- [ ] Consent settings: `/admin/se_core/se_consent`
- [ ] Meta Lead Ads: `/admin/se_core/se_meta`
- [ ] Google Data Manager: `/admin/se_core/se_google`
- [ ] WhatsApp readiness: `/admin/se_whatsapp/readiness`

## After completing this checklist
Update the classification rows in `docs/FINAL-QA-MATRIX.md` and `docs/CRM-A-Z-FINAL-REPORT.md` §1/§6/§11.
Remove every synthetic ZZ brand, lead, patient and appointment created for these checks — then confirm the
module data tables are back to zero rows, as the automated residue scan does.
