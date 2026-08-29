# Manual UI Checklist (owner — after a normal login)

Log in normally at `https://crm.roozbeh.com.tr/admin` (no session was forged during QA). Verify each item.
Use **authorized test brands** (create synthetic ZZ brands/leads, remove afterward) — never real patient data.

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
