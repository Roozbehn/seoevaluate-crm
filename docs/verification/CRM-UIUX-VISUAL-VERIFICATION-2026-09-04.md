# CRM UI/UX Visual Verification — 2026-09-04

**Where.** The live admin at https://crm.roozbeh.com.tr, authenticated Chrome session of the owner (Claude in Chrome), after deploy of `b3994eb` (+ CSS follow-ups). Breakpoints were rendered as same-origin iframes of exact width inside the logged-in page (Chrome's window resize is unreliable on this 2.5× display), and measured with the same assertions the Playwright suite (`scripts/ui-regression/responsive.mjs`) runs. Nothing was created, sent or changed on production during the pass; no screenshot containing patient data was kept.

**Pages.** Bugün (`se_core/se_dashboard`), Hastalar (`se_core/se_hastalar`), Mesajlar list (`se_whatsapp/inbox`), Mesajlar thread (`inbox?c=<id>`), Randevular calendar (`se_appointments`), appointment form (`se_appointments/create`), patient workspace (`se_journey/view/<id>`).

## 1. Responsive matrix (measured)

Legend: **ov** = document scrollWidth − viewport (≤ 0 = no sideways scroll) · **tab** = bottom tab bar visible · **thr** = `body.se-in-thread` · **comp** = composer textarea width / send button visible · **<44** = visible controls under 44×44 px inside the shell (phones only count) · **pg** = page scroll height beyond the viewport.

| Page | 390 | 768 | 1024 | 1440 | 1920 |
|---|---|---|---|---|---|
| Bugün | ov −4 · tab ✓ · <44: 0* | ov −4 · tab ✓ | ov −4 · tab hidden | ov −4 | ov −4 |
| Hastalar | ov −4 · tab ✓ · <44: 0* | ov −4 · tab ✓ | ov −4 | ov −4 | ov −4 |
| Mesajlar list | ov −4 · tab ✓ · <44: 0 | ov −4 · tab ✓ | ov −4 (list + thread) | ov −4 (3 columns) | ov −4 |
| Mesajlar thread | ov −4 · **tab hidden** · thr ✓ · comp **85 %** / send ✓ · page does not scroll (thread scrolls inside) | ov −4 · tab hidden · comp 92 % | ov −4 · comp 43 % (middle column) · ctx behind ⓘ | ov −4 · 3 columns · comp 38 % | ov −4 · comp 50 % |
| Randevular | ov −4 · agenda (no month grid) · tab ✓ | ov −4 · FullCalendar | ov −4 · `.fc-view` present | ov −4 | ov −4 |
| Appointment form | ov −4 · tab ✓ · <44: 0 · fields stacked | ov −4 · 4-up grid | ov −4 | ov −4 | ov −4 |
| Patient workspace | ov −4 · tab ✓ · <44: 1 (admin-only inline "Related lead" text link) | ov −4 | ov −4 · 8/4 grid | ov −4 | ov −4 |

\* after the `07b87d9`/pill follow-up: stage pills 44 px, chips/inputs/buttons 44 px, patient-name links 44 px. Before the fix Hastalar scrolled sideways by +68 px (390) / +117 px (1024) — cause: the visually-hidden "Action" header (`.se-sr`, absolute) escaping the scrolling table wrap; fixed by positioning table cells.

Desktop controls are 40 px by design (DS §1: 40 desktop / 44 touch); chips 36 px on desktop.

## 2. Mockup → live (UIUX-OPT §O)

| Mockup | Live | Match | Differences kept on purpose |
|---|---|---|---|
| 01-today | Bugün: attention queue (one button per row, priority bar, age), Hasta akışı pills, right column appointments / unread / Sistem, `+ Randevu` `+ Hasta` | ✓ | "Dışa aktar" not built; no all-time counters (by spec) |
| 02-patient-workspace | header (avatar, name, state badge, id-line, facts), 7-segment stage bar, next-step panel, alerts, tabs Genel/Sohbet/Değerlendirme/Fotoğraflar/İnceleme·Teklif/Randevu·İşlem·Bakım/KVKK, human timeline, evaluation checklist, tasks, notes | ✓ | "Dosyalar" tab folded into Fotoğraflar; "Geçmiş" is the Genel tab |
| 03-whatsapp | list \| thread \| context; chips; previews; state chip per row; unread pill; strip on phones; ⓘ sheet; contextual actions | ✓ | search is server-side (Enter), not live-filter |
| 04-patients-list | search, chips (Aktif / Bekleyen iş / Bana atanan / stages / Kapalı / Tümü), columns Hasta · Aşama · Sonraki adım · Son temas · Randevu · Sorumlu · Kaynak · action; 25/page | ✓ | Kaynak select not built (source shown as a column); "Dışa aktar" not built |
| 05-calendar | month/week/day chips, legend by type, FullCalendar v5; phone agenda with day chips and "Görüşme yapıldı / Bugün işlem planla" | ✓ | — |
| 06-quote-tab | İnceleme · Teklif tab: review form + quote form, statuses, versions, Sales read-only summary | ✓ | amount policy governs visibility (unchanged) |
| 07-appointment-form | type chips → duration, patient, date/time/duration/performer/format/location, notes, honest notification lines, conflict message on the time field with next free slot | ✓ | notification lines are statements, not checkboxes (the behaviour is automatic; a checkbox would have been a lie) |

## 3. Accessibility probe (1440, Bugün)

- Contrast (computed from tokens on the dark surface #31324a): body text 5.8:1, meta text 5.3:1, links 5.4:1, white on primary 5.1:1, badges positive 6.0 / warning 5.3 / danger 5.2 / info 5.3 / action 5.5 / inactive **3.8 → 5.3** after `--se-inactive` bump. No text under 12 px inside the shell.
- Focus: `:focus-visible` outline 2 px `#9aa3ff` on every focusable inside `body.se-clinic` (verified computed `outline-color rgb(123,138,243)` on a page-head button).
- Names: 0 unnamed form controls on every page/width; 0 images without alt; header icon buttons labelled by `se-clinic.js`; skip link present.
- Motion: `prefers-reduced-motion` block present (not exercised live).
- RTL smoke (`dir=rtl` injected on Bugün at 1200): no overflow, title mirrored to the right, actions to the left, row buttons on the left, ages/phones isolated LTR. Not exercised with a real Persian staff locale (would change a real staff record).

## 4. Header overlap (audit T9)

At 800 / 900 / 990 px the page-head buttons sit at y=122 / 81 / 81 with the header cluster ending at y=56: **no overlap** (was overlapping at ~700–990 before the DS).

## 5. Performance (live, logged-in, no-store fetch from the browser)

| Page | HTML round-trip | server build (`data-ms`) | HTML size |
|---|---|---|---|
| Bugün | 240 ms | 4–6 ms | 82 KB |
| Hastalar | 273 ms | 4 ms | 85 KB |
| Mesajlar list | 249 ms | 4 ms | 82 KB |

Targets: Bugün < 600 ms ✓, inbox < 500 ms ✓ (server build is single-digit ms; the rest is TLS + Cloudflare + Perfex bootstrap).

## 6. Live defects found and fixed during the pass

1. **Placeholders rendered as 0 / empty** — "0 WhatsApp message(s) not delivered" with 6 in the table: Perfex's `_l()` sprintf's the line itself; every `sprintf(_l(…))` (21 sites), the next-action `$L` closures and `se_tr()` now pass arguments through `_l`; new copy-gate rule. (`4850753`)
2. Hastalar sideways scroll (`.se-sr` escaping the table wrap). (`07b87d9`)
3. Patient "Diğer" panel visible while closed. (`07b87d9`)
4. Mesajlar thread scrolled the whole page instead of the thread column. (`07b87d9`)
5. Appointment form JS hints lost `%s`. (`07b87d9`)
6. Stale stylesheet from the CDN edge (`?v=1.0.0`): assets now versioned by DS version + mtime. (`b3994eb`)
7. Phone touch sizes (chips 36 → 44, inputs/buttons 40 → 44, pills 22 → 44). (`07b87d9`, follow-up)

## 7. Not verified live (and why)

- Conflict message on a real double booking, same-day shortcut save, reopen, note, reply from the Sohbet tab — each would create or change a real appointment/journey/message on production. Covered by the harness (model reason/message, prefill, transitions) and by reading the rendered forms.
- Light theme: the `perfex_dark_theme` plugin stamps `html[data-perfex-theme="light"]` (read from its helper on the host); that selector is now in the light token block. A visual pass of the light theme itself is not done — the owner’s session is dark and switching it stores a preference in that browser.
- Playwright suite: ready (`scripts/ui-regression/responsive.mjs`); needs a one-time storage-state export by the owner (README) — it never types credentials.
