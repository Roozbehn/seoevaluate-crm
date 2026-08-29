# Manual UI Checklist (owner — after a normal login)

Log in normally at `https://crm.roozbeh.com.tr/admin`. Verify each item. Use **authorized test brands**
(create synthetic ZZ brands/leads, remove afterward) — **never real patient data**.

> **Why this checklist gates two classifications.** Earlier phases drove authenticated HTTP using
> *temporary synthetic database sessions* (rows inserted into `tblsessions`, then deleted); Phases 8 and 9
> fabricated no sessions at all. **No human has ever performed an authenticated browser check.** Until this
> checklist is complete:
> - **Patient CRUD UI** stays at *Functional with fixtures — automated model/DB tests passed; authenticated
>   UI and permission QA pending*.
> - **Dark Theme** stays at *Installed and functionally activated — visual/responsive QA pending*.
>
> Neither may be described as end-to-end verified before then.

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

## After completing this checklist
Update the classification rows in `docs/FINAL-QA-MATRIX.md` and `docs/CRM-A-Z-FINAL-REPORT.md` §1/§6/§11.
Remove every synthetic ZZ brand, lead, patient and appointment created for these checks.
