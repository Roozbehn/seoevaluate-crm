# Azin CRM redesign mockups

Self-contained HTML (CSS inlined) — open any `0N-*.html` in a browser and resize to see 390 / 768 / 1024 / 1440 behaviour.

- `01-today.html` — Bugün (operational dashboard)
- `02-patient-workspace.html` — Hasta çalışma alanı, Genel tab
- `03-whatsapp.html` — Mesajlar: three-column inbox; phone = thread with context strip
- `04-patients-list.html` — Hastalar unified list
- `05-calendar.html` — Randevular month view; phone = agenda
- `06-quote-tab.html` — Teklif tab
- `07-appointment-form.html` — Randevu formu with type chips, conflict message, same-day prefill

`azin-ds.css` is the design-system stylesheet the mockups use (reference for `modules/se_core/assets/se-ds.css`).
`png/` — renders at 1440 (desktop), 768 (tablet), 390 (phone). `current-state/` — screenshots of the live CRM for comparison.
`src/` — sources with the `{{SHELL}}` placeholder plus `build.mjs` (Playwright) to rebuild and re-render:
`npm i playwright && node build.mjs` (uses the preinstalled Chromium path; adjust `executablePath`).
All patient names are fictional.
