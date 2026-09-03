# CRM Productivity Results — 2026-09-04

Before = clicks/pages counted in the audit (CRM-AUDIT §F, code paths + live UI). After = counted on the deployed build (`b3994eb`+) by walking the same task on the live admin without submitting anything that changes production data; where a step was walked only in the harness it is marked (H). "Remembering" = a step that previously depended on a person noticing something without a prompt.

## 1. Task table (CRM-AUDIT §F targets vs delivered)

| Task | Before | Target (audit) | After (delivered) | Result | How |
|---|---|---|---|---|---|
| Morning triage: find what needs a hand | 3 pages (dashboard → journeys → inbox), read counters, open rows | 1 page | **1 page** — Bugün lists every staff-owned item with one button, sorted by priority/age; Hasta akışı pills; unread; Sistem | ✓ | `se_journey_attention_queue()` fed by the next-action engine |
| Triage a new organic enquiry (find thread, read, start) | 3 clicks / 2 pages | 0–1 | **1** (Bugün row → "Değerlendirmeyi başlat" in the thread context) — or 0 with auto-start organic on (switch exists, default off by decision) | ✓ | contextual actions |
| Answer a patient question during intake and keep reminders running | 5 clicks / 2 pages (reply, back, resume) | 1 | **1** — reply; pause is an explicit opt-in checkbox next to Send | ✓ (H for send) | CRM-M006 |
| Photos received → ready for review | ~10 clicks | 2 | **4** — Bugün row "Fotoğrafları incele" → Fotoğraflar tab → "Fotoğrafları kabul et" → "İncelemeye hazır" (classification still per photo when the Flow does not label them) | ⚠️ partial | CRM-M029 (Flow JSON at Meta) still PLANNED |
| Find a patient by name or phone | not possible (nationality only) | search | **1** — Hastalar search box, digits in any formatting | ✓ | CRM-M024 |
| Open a thread and know where the patient is | inbox → thread → scroll 1 500 px | in view | **0 extra** — state chip in the list row; context column / strip / sheet with next step and 2–4 actions | ✓ | CRM-M033/M036 |
| Review + quote (draft, approval, send) | ~8 clicks + typing, 2 roles | 6 | **6–7** — İnceleme · Teklif tab: save draft → request approval → approve → send (owner can approve directly) | ✓ | unchanged flow, one tab |
| Consultation held → procedure booked (same day) | ~7 clicks / 2 forms | 3 | **3** — agenda "Görüşme yapıldı" → confirm held → "Bugün işlem planla" opens the form prefilled (patient, staff, brand, place, start = consultation end, 4 h) → save | ✓ (H for save) | CRM-M041 |
| Booking with a busy slot | generic "invalid window", guess again | exact message | **exact** — "Bu saatte Azin A. için başka bir randevu var (14:00–14:30 Ön görüşme · Ayşe Y.). İlk uygun saat: 14:30." on the time field, form values kept | ✓ (H) | CRM-M039 |
| Procedure done → aftercare running | 3 clicks + remembering | 0 | **0 when a protocol is approved** (auto-start within one cron tick) — today the default protocol is unapproved, so it is a task on Bugün instead of a memory item | ✓ gated | CRM-M046 |
| Quote unanswered / expired / review overdue / consultation held but unrecorded / automation paused / welcome unanswered | remembering | prompt | **task + push once per threshold**, and the same row on Bugün | ✓ | CRM-M045/M048 |
| Reschedule a consultation | confirmation blocked by dedup (patient never told) | new confirmation | **new confirmation** with the new time | ✓ (H) | CRM-M044 |
| Reopen a closed / not-suitable patient | not possible in UI | button + reason | **1 form** on the patient page (reason required) | ✓ | CRM-M030 |
| Read a patient's history | raw kinds, "a → b", English | human Turkish | human sentences, actor (hasta / personel / otomatik), noise hidden | ✓ | CRM-M026 |
| See why sends are failing / conversions skipped | hidden | honest Health | Sistem card + Health: skipped by reason, dispatcher age, failed sends/reminders | ✓ | CRM-M008 |

## 2. Estimated staff-time effect

With ~20 active journeys the audit estimated 60–90 clicks/day removed. Delivered: every "remembering" step is now a Bugün row or a timer task (resume, quote follow-up, expiry, review SLA, held-unrecorded, aftercare start); the three most frequent daily actions (triage, reply, find patient) are one page / one click; same-day procedure is 3 clicks. The one target not fully met is photo acceptance (4 clicks instead of 2) pending the Meta Flow update (CRM-M029).

## 3. Measured performance (live)

Bugün 240 ms round-trip / 4–6 ms server build (target < 600 ms) · Hastalar 273 ms / 4 ms · Mesajlar 249 ms / 4 ms (target < 500 ms). All lists are bounded (Bugün 25 rows, Hastalar 25/page over a 500-row scan, Mesajlar 50 per cursor page, thread 100 per page).

## 4. What was NOT counted

No task was completed against production data (no appointment, note, reply, reopen or quote was submitted). Click counts for those are taken from the rendered forms (buttons present, fields prefilled) and the harness assertions for the behaviour behind them.
