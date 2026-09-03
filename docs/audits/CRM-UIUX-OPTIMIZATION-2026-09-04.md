# Azin Asgari CRM — UI/UX Optimization & Redesign Specification

**Date:** 2026-09-04 · **Input:** `docs/audits/CRM-AUDIT-2026-09-03.md` (findings treated as established) · **Live re-inspection:** crm.roozbeh.com.tr, logged in, 390 / 768 / 1024 / 1440 / 1536 (the linked Mac's display gives 1536 css px; 1920 was inspected in the first audit) · **Mode:** read-only, no production change.

Companion files: `docs/design/AZIN-CRM-DESIGN-SYSTEM-v1.md` · `docs/design/AZIN-CRM-UX-COPY-TR.md` · `docs/design/AZIN-CRM-UIUX-BACKLOG.md` · mockups `docs/design/mockups/` (HTML sources, `azin-ds.css`, rendered PNGs at 1440/768/390 under `png/`, current-state screenshots under `current-state/`).

Patient names in mockups are fictional; no real record is reproduced.

---

## A. UX Executive Summary

The CRM already has the right *engine*: a journey state machine with audited transitions, a server-decided WhatsApp compose policy, an approval-gated quote, and a strong test harness. What it lacks is a *cockpit*. Staff today juggle four nouns for one person (Lead, Patient, Journey, WhatsApp conversation), land on a page of all-time counters, read raw state codes and English task titles, and cannot reply from a phone.

The target is a **patient-centred operating system in five screens**: **Bugün** (what needs me now), **Hastalar** (one list, one row per person, one next step), the **Hasta çalışma alanı** (header + stage bar + Sonraki adım + tabs, with the WhatsApp composer inside it), **Mesajlar** (a real three-column inbox with a state-aware context column, collapsing to a one-column thread with a context strip on phones), and **Randevular** (a working, type-coloured calendar that becomes an agenda on phones). Everything is expressed through one small design system (`se-ds.css`, 18 components) and one Turkish vocabulary.

The backend stays Perfex + SE modules. The redesign is mostly views, one CSS file, a few helper functions (`se_journey_next_action`, `se_ui_*`), two new read models (unified Hastalar list, richer inbox query) and navigation configuration. Estimated effort: foundation 2 weeks, core screens 4–5 weeks, polish 2 weeks, one developer.

**The ten highest-impact changes** (detail in §S/§T):
1. Fix the phone composer and make the thread a full-height, sticky-composer screen (UX-P0).
2. Make the calendar render, with type colours and an agenda on phones (UX-P0).
3. Bugün: replace the 12 counters with an attention queue that carries patient · reason · age · one button.
4. `se_journey_next_action()` — one function that every screen uses to say what to do next.
5. The unified Hastalar list (name, stage, next step, last contact, appointment, assignee, source) with name/phone search.
6. Patient header + 7-segment stage bar + Sonraki adım panel on the journey page; WhatsApp composer inside the page.
7. Three-column Mesajlar with a state-aware context column and contextual actions; pause becomes opt-in and Resume lives in the thread.
8. One Turkish vocabulary: Hasta · Sohbet · Ön görüşme · Kaş ekimi · Rıza · Onay · Teklif · Bakım · Takip; human timeline labels; no English literals; no "hekim".
9. Navigation cut to Bugün · Hastalar · Mesajlar · Randevular (+ Yönetim for admins) with a bottom tab bar on phones.
10. Design-system tokens that fix contrast, focus, 44 px targets, and RTL-ready logical properties.

---

## B. Current vs Target Information Architecture

### B.1 Current (observed)

Sidebar: Dashboard · Leads · Patient journeys · Patients · Appointments · WhatsApp · Instagram · Customers · Reports · Integrations (6) · Setup. Four people-screens, three of them stock or thin; the operational list is *Patient journeys*, but the landing page is *Dashboard*; the KVKK envelope is called *Patients* and has no names; Instagram is a top-level item for a channel with one thread a day.

### B.2 Target

```
OPERASYON                              (all staff)
  Bugün          — attention queue, today's appointments, unread, pipeline, system
  Hastalar       — one list, one row per person (lead ⟕ journey ⟕ thread ⟕ appointment)
  Mesajlar       — WhatsApp · Instagram tabs, three-column inbox
  Randevular     — calendar / agenda / list / form
YÖNETİM                                (owner + admin)
  Raporlar       — existing reports (Turkish, clinic vocabulary)
  Entegrasyonlar — Meta Lead Ads · Dönüşüm kuyruğu · Google · Sistem sağlığı · Kimlik bilgileri   (admin only)
  Ayarlar        — Rıza metinleri · Süreç ayarları · Şablonlar · WhatsApp Flows · Perfex kurulumu (admin only)
```

| Item | Decision |
|---|---|
| Dashboard | **Renamed** *Bugün*, rebuilt (§D) |
| Leads | **Hidden** for clinic roles; admins reach Perfex Leads under Ayarlar → Perfex kayıtları. The unified Hastalar list replaces it operationally. |
| Patient journeys | **Merged** into Hastalar (list) and Bugün (counters/attention); the journey page becomes the patient page |
| Patients (KVKK envelope) | **Moved** to a tab (*Kimlik & Rıza*) on the patient page; list retired from the menu |
| Appointments | **Stays** as Randevular |
| WhatsApp / Instagram | **Merged** into Mesajlar with channel tabs |
| Customers | **Hidden** (Perfex conversion is not part of the clinic flow; if used later, it lives under Ayarlar → Perfex kayıtları) |
| Reports | **Stays**, Yönetim group, owner + admin |
| Integrations | **Stays**, admin only, plus *Sistem sağlığı* |
| Setup (Perfex) | **Moved** under Ayarlar → Perfex kurulumu, admin only |
| WhatsApp templates / Flows / Journey settings (currently buttons on the journey index) | **Moved** to Ayarlar |

Normal clinic staff never see: Perfex Setup, Leads/Customers, Integrations, Conversion Outbox, Credentials, Consent Settings, Journey settings, Templates, Flows. They see exactly four operational items.

## C. Navigation Redesign

**Desktop (≥1024):** left sidebar 232 px, two labelled groups (OPERASYON / YÖNETİM), 40 px items with icon + label + count pill (Bugün = queue size, Mesajlar = unread). Active item: primary-soft background + 3 px inset bar. Header: hamburger (collapses sidebar to icons at 1024–1279), brand wordmark, global search "Hasta, telefon veya sohbet ara… ⌘K", notification bell with dot, "+" quick-create (Hasta / Randevu), avatar.

**Tablet (768–1023):** sidebar off-canvas behind the hamburger; header keeps search; content single column. Perfex's top-bar icon cluster (timers, todo, share, theme) is hidden behind the existing chevron at ≤990 px — this removes the overlap.

**Phone (≤767):** header = hamburger · wordmark · bell · + · avatar (search inside the hamburger sheet). **Bottom tab bar** 64 px: Bugün (count) · Hastalar · Mesajlar (count) · Randevu · Diğer (Raporlar, Ayarlar, profile). Inside a conversation the tab bar hides so the composer is the bottom of the screen; a back arrow returns to the list.

**Breadcrumbs:** none. Every detail page has a *← Geri* that returns to the list it came from (`?from=`), and the patient page header carries the name so context is never lost.

**Renames in the shell:** "Dashboard" → Bugün; "Patient journeys" → (gone); "Patients" → Hastalar; "WhatsApp" → Mesajlar; "Appointments" → Randevular; "Reports" → Raporlar; "Integrations" → Entegrasyonlar; "Setup" → Ayarlar.

## D. Dashboard Redesign — *Bugün* (mockups `01-today-*`)

**Desktop layout (≥1024):** page head `Bugün · Perşembe, 4 Eylül · 2 randevu · 6 bekleyen iş` with *+ Randevu* (secondary) and *+ Hasta* (primary) at the right. Below, an 8/4 grid.

**Left (8 cols) — "İlgilenmeniz gerekenler N":** the attention queue, ordered by priority (danger → action → info) then age. Each row: 4 px priority bar · patient name (15/600) · badge (state in Turkish) · plain reason · age (action colour when past threshold) · **one** button. Sources, in this order:
1. Urgent flags and failed sends (danger) — *Yanıtla / Ara*
2. `ready_for_review`, `quote_pending_staff_approval`, `quote_revision_requested`, `consultation_recommended`, `consultation_completed` without outcome, `procedure_completed` without plan (action) — *Fotoğrafları incele / Teklifi onayla / Yeni sürüm / Randevu oluştur / Sonucu kaydet / Planı başlat*
3. `quote_sent` > 3 d, `followup_due`, `paused_staff` > 24 h, intake/photos past the final reminder (warning/info) — *Hatırlat / Ara / Devam ettir / Kapat*
4. Unanswered inbound threads > 30 min not covered above — *Yanıtla*
Cap 25 rows, "Tümünü gör →" to Hastalar filtered *İşlem bekleyen*. Empty state: "Bugün için bekleyen iş yok."

**Left, second card — "Hasta akışı · bu hafta":** seven pills with counts per macro-stage (Yeni talep 4 · Değerlendirme 3 · İnceleme 1 · Teklif 2 · Ön görüşme 2 · Kaş ekimi 1 · Bakım 3). Click filters Hastalar. No all-time totals anywhere on this page.

**Right (4 cols):** *Bugünkü randevular* (time · name · type badge · place; "Takvimi aç →"), *Okunmamış mesajlar* (name/number · channel · age, max 5), *Sistem* (only when something needs attention: e.g. "Meta dönüşüm gönderimi: 7 kayıt rıza nedeniyle atlandı [İncele]"; otherwise a one-line green summary "WhatsApp ✓ · Instagram ✓ · Gönderici ✓ 40 sn önce · Cron ✓ 6 dk önce").

**Phone (390):** single column: page head, queue (rows become two lines + full-width button), then appointments, unread, system. First viewport shows the first two queue items.

**Removed:** Leads / Patients / No-show / Conversions pending / Meta queue / Google submitted counters, the Integration Credentials and Consent Settings cards, the persistent consent banner (false positive; real integration issues go to the Sistem card).

**Data:** one query over active journeys + `se_journey_tasks` (open) + unread conversations + today's appointments; `se_journey_next_action()` supplies sentence/button; target < 600 ms.

## E. Patients Redesign — *Hastalar* (mockup `04-patients-list-desktop`)

One list, one row per person. Source: `tblleads` (brand-scoped) left-joined with `se_journeys` (state, automation, assignee, next_action), the newest `se_wa_conversations` (unread, last inbound), and the next `se_appointments`. People without a journey still appear (state *Yeni talep* or *Kayıt*).

**Columns (desktop):** Hasta (name 14/600 + masked phone · language 13 meta; "ad bilinmiyor" when only a number) · Aşama (badge, colour by semantic) · Sonraki adım (sentence from `next_action`) · Son temas (age + 💬 unread) · Randevu (type + date) · Sorumlu · Kaynak · row action (the next-action button, `.se-btn-sm`).
**Tablet (768–1023):** hide Kaynak and Sorumlu.
**Phone (390):** Hasta · Aşama · Sonraki adım · button, stacked in one cell; no horizontal scroll; 44 px button.

**Toolbar:** search (name or phone digits, debounced, server-side), chips *Aktif* (default) · *İşlem bekleyen N* · *Bana atanan* · *Bakımda* · *Kapalı*; selects *Aşama* (7 stages + terminal) and *Kaynak*. Saved views are not needed at 2–3 users — the chips are the saved views. Sort by last contact desc by default; sortable Aşama/Son temas/Randevu. 25 per page, "Önceki / Sonraki".

**Row click** opens the patient page; the button performs the next action directly (deep-links to the right tab, or posts for one-click actions like *Devam ettir*). Urgent rows carry a small *Acil* tag on the name.

**Brand:** hidden unless the staff member can see more than one brand; then a filter chip, never a numeric column.

## F. Journey Workspace Redesign — *Hasta çalışma alanı* (mockups `02-patient-workspace-*`, `06-quote-tab-*`)

**Header card:** avatar initials (48) · name (22/600) with state badge · identity line *phone · language · source · Sorumlu: X* · facts grid (Son temas, Randevu, Otomatik mesajlar, Rıza, Sohbet penceresi) · actions *💬 WhatsApp'tan yaz* (primary), *+ Randevu*, *⋯* (Sorumlu ata, Otomatik mesajları duraklat/devam ettir, Kaydı güncelle, Kaybedildi olarak kapat, Yeniden aç). Everything the lead modal offered that matters is here; the lead modal is no longer needed by clinic staff.

**Stage bar:** 7 segments — Talep · Değerlendirme · İnceleme · Teklif · Ön görüşme · Kaş ekimi · Bakım — done/now/future. The 31 states map to these (UX-COPY §3.2); the exact state is the badge next to the name, not a step.

**Sonraki adım panel:** directly under the stage bar, action-tinted, caption SONRAKİ ADIM, sentence (18/600), reason line with age and evidence, one primary button. It is the largest interactive object on the page. When the patient owns the next step (form, photos, quote answer), the panel says so ("Hasta bekleniyor — hatırlatma otomatik · 24 sa hatırlatma 3 sa sonra") with a ghost *Şimdi hatırlat*.

**Alerts:** rows under the panel only when present: Otomatik mesajlar duraklatıldı [Devam ettir] · Rıza eksik (sağlık) [Formu yeniden gönder] · Teklif süresi doldu [Yeni sürüm] · Randevu geçti, sonuç yok [Sonucu kaydet] · Mesaj iletilemedi [Ara].

**Tabs:** Genel · Sohbet (unread count) · Değerlendirme · Fotoğraflar (count) · Teklif · Randevular · İşlem & Bakım · Geçmiş · Dosyalar.
- *Genel:* timeline of the last 24 h (human labels), Değerlendirme özeti checklist (form v · photos n/3 · inceleme · teklif), Uyarılar, Notlar (internal, free text).
- *Sohbet:* the same thread + composer component as Mesajlar, embedded; replying here never leaves the page.
- *Değerlendirme:* answers grouped by section, flags highlighted, consent snapshot, "Formu yeniden gönder".
- *Fotoğraflar:* thumbnails with checklist (tam karşıdan / sol / sağ / donör), *Seti kabul et* (one click), per-photo *Yeniden çekim iste*.
- *Teklif:* §I.
- *Randevular:* cards with status buttons (Yapıldı · Gelmedi · Yeniden planla) and *Bugün işlem planla* after a held consultation.
- *İşlem & Bakım:* pre-op checklist, procedure record, aftercare plan with day markers, follow-ups.
- *Geçmiş:* the full timeline, filter by actor (Hasta / Otomatik / Personel), "Kayıt güncellemelerini göster" toggle for lead-sync rows.
- *Dosyalar:* consent PDF, exported answers, photo set download (audited), quote snapshots.

**Timeline rules:** `se_journey_event_label()` returns Turkish sentences (UX-COPY §6); actor dot colour; meta = age · actor · channel · delivery; quoted body only for messages. Never `quote_pending_staff_approval → quote_sent`; render "Teklif v1 onaylandı ve hastaya gönderildi".

**Phone:** header collapses to avatar + name + badge + identity line + 2 facts + "Ayrıntılar"; actions row = WhatsApp (flex) · + Randevu · ⋯; stage bar shows numbers except the current segment; Sonraki adım full width with full-width button; tabs scroll horizontally; Genel content stacks.

## G. WhatsApp Redesign — *Mesajlar* (mockups `03-whatsapp-*`)

**Desktop (≥1024) three columns:** 320 / fluid / 340 (280 / fluid / 300 at 1024–1439).

*Left — Sohbetler:* search; chips *Tümü · Okunmamış N · Bana atanan · Dikkat* (Dikkat = urgent, failed, handoff, unanswered > 30 min); channel tabs WhatsApp / Instagram above the list. Row: avatar initials (or "?" for unknown numbers, IG for Instagram) · name (or masked number) + state chip · last-message preview (📷 Fotoğraf, 🎤 Sesli, "Siz: …") · time · unread pill. Active row primary-soft. Sorted by last activity; unread first when the Okunmamış chip is on.

*Center — Sohbet:* head (avatar · name · phone · language · Sorumlu · *Hasta sayfası* · ⓘ); messages with day separators; **automatic messages dashed with an "otomatik" tag**; delivery meta; composer: window badge row ("Yanıt penceresi açık · 23 sa 40 dk kaldı · Şablon gönder"), full-width textarea (Enter sends), tool row *🙂 📎 🎤 · [ ] Otomatik mesajları duraklat · Gönder*. When the window is closed the textarea is replaced by the template picker with placeholder inputs (existing behaviour, restyled).

*Right — Hasta bağlamı:* name + age/source/started; state badge + "3. adım / 7 — İnceleme"; compact Sonraki adım; **contextual actions** (≤3 by state, UX-COPY §4, e.g. *Fotoğrafları incele · Yeniden çekim iste · Ön görüşme planla · Tüm işlemler ⋯*); Değerlendirme checklist (rıza · form · fotoğraf · inceleme · teklif · ön görüşme); Bilgiler (phone, language, Sorumlu select, Otomatik, Giden kuyruk one-liner). The outbound tracker table is collapsed into that one line and expands only when the queue is non-empty.

**Tablet (768–1023):** list + thread; context column becomes the ⓘ sheet.

**Phone (390) — full design, not a patch:**
- Header: ← back · avatar · name · "Sorumlu: Azin" · ⓘ.
- **Context strip** under the header: state badge · reason ("İnceleme bekliyor") · one primary button (*İncele*). Tapping ⓘ or the strip opens a **bottom sheet** (max 90 vh) with the entire context column. Reason for a sheet rather than a drawer or top summary: the strip already gives state + action in 36 px; the sheet keeps the thread full-height and is a pattern staff know from WhatsApp itself (contact info sheet); a side drawer would cover the thread while typing.
- Messages full width (bubbles max 88 %).
- Composer sticky at the bottom: window row, full-width textarea, tool row *🙂 📎 🎤 ⏸ · Gönder* (3×44 + 44 + 96 = 304 px in a 358 px row — never wraps). The bottom tab bar is hidden in a thread.
- Nothing is 1 500 px down: state and action are at the top; details are one tap away.

**Pause semantics:** replying no longer pauses automation by default; the checkbox (or ⏸ toggle) pauses explicitly and shows a toast with *Geri al*. When paused, the context column/strip shows *Duraklatıldı* with *Devam ettir*.

## H. Appointments Redesign (mockups `05-calendar-*`, `07-appointment-form-*`)

**List:** Hasta (name + masked phone) · Tür (badge: Ön görüşme / Kaş ekimi / Kontrol / Takip) · Başlangıç · Süre · Uygulayan · Yer · Durum · actions. Filters: date range, type, status, staff.

**Calendar (desktop):** month/week/day + list toggle chips, ‹ Bugün ›, *+ Randevu*. Events coloured by **type**, not status: Ön görüşme info-blue, Kaş ekimi action-orange, Kontrol positive-green, Takip lavender; cancelled events struck through; legend row. Click → appointment sheet (details + Yapıldı / Gelmedi / Yeniden planla / Hasta sayfası).

**Calendar (phone):** agenda: day chips (‹ Çar 3 · **Per 4** · Cum 5 ›), cards per day with entries `time · type bar · name · type · place · staff · duration`, contextual buttons on today's consultations (*Görüşme yapıldı*, *Bugün işlem planla*), FAB for new. No month grid.

**Form:** Randevu türü chips first (sets default duration: Ön görüşme 30 dk, Kaş ekimi 4 sa, Kontrol 20 dk, Takip 15 dk) · Hasta (search, pre-filled from context) · Tarih · Saat · Süre (instead of Ends) · Uygulayan (default Azin Asgari — Kaş Ekimi Uzmanı) · Yer · Not · Hastaya bildirim checkboxes (onay + takvim dosyası, 24 sa hatırlatma). Side panel: today's schedule for the staff member with the first free slot, and the patient's readiness checklist.
**Conflict:** inline under Saat, `role="alert"`: "Bu saatte Azin Asgari için başka bir randevu var (14:00–18:00 Kaş ekimi · Ayşe Y.). İlk uygun saat: 18:30." — never "Check date range and relation".
**Same-day flow:** after *Görüşme yapıldı* (patient page, agenda, or Bugün), a *Bugün işlem planla* button opens the form pre-filled: type Kaş ekimi, patient, staff, date today, time = next free slot, place = same; 3 clicks from held to booked.
**Patient-context card:** on the patient page Randevular tab and in the WhatsApp context column: next appointment as `Ön görüşme · 8 Eyl 13:30 · Klinik · Onaylandı` with the status buttons.

## I. Quote UX Optimization (mockup `06-quote-tab-*`)

Keep the workflow (draft → approval → send → patient response → versions). Presentation:
- Title row: *Teklif v2* · status badge (Taslak / Onay bekliyor / Onaylandı / Gönderildi / Kabul edildi / Revizyon istendi / Süresi doldu) · who prepared, when · previous version summary at right.
- Two-column body: **Fiyat** (22/600, range by default "45.000 – 50.000 TL", hint "Aralık · ön görüşmede netleşir") · **Geçerlilik** (date + "26 gün kaldı") · **Kapsam** list · **Kapsam dışı** list · **Öneri** (önce ön görüşme / ön görüşme sonrası aynı gün kaş ekimi) · **Hastaya not**.
- **Deposit:** hidden under a collapsed "Diğer" section when the brand policy is no-deposit (brand 22); the `deposit_state` select disappears from the header.
- Internal notes and margin under a `<details>` "İç notlar ve marj (hastaya gönderilmez)".
- Actions by role: preparer *Onay iste · Düzenle*; approver *Onayla ve hastaya gönder* (one click) · *Sadece onayla* · *Düzenle (v3 oluşturur)* · *Önizleme*; after send: *Hatırlat* (if unanswered ≥3 d) · *Yeni sürüm*.
- Right column: status checklist (taslak → gönderildi → yanıt), Sürümler list, Değerlendirme özeti (decision, photos, age).
- Sending explanation line under the buttons (what the patient receives and how they answer).
- Sales role: read-only view of amounts/status, no internal section.

## J. Mobile UX — 390 / 768 / 1024

| Screen | 390 (phone) | 768 (tablet portrait) | 1024 (tablet landscape / small laptop) |
|---|---|---|---|
| **Navigation** | bottom tab bar (5), hamburger sheet for Diğer + search | off-canvas sidebar, header search | fixed sidebar 232 (collapsible to icons) |
| **Bugün** | single column; queue rows 2-line + full-width button; appointments/unread/system below | single column, queue then two cards side by side | 8/4 grid |
| **Hastalar** | 3 visible columns (Hasta · Aşama · Sonraki adım) + 44 px button; filters as a sheet; no horizontal scroll | 6 columns (hide Kaynak, Sorumlu) | all columns |
| **Patient page** | header 2 facts + Ayrıntılar; stage numbers; Sonraki adım full width; tabs scroll; content stacked; sticky primary button at bottom when scrolled past the panel | header full; 8/4 becomes single column | full |
| **Mesajlar** | thread only: back · strip · messages · sticky composer; list is the previous screen; context = sheet | list 280 + thread; context = sheet | 280 / fluid / 300 |
| **Randevular** | agenda + FAB | month grid with abbreviated events, week view default | full |
| **Appointment form** | single column, native pickers, type chips wrap, conflict inline, sticky Kaydet | two columns for date/time/duration | 8/4 with side panel |
| **Modals** | bottom sheets (confirm, filters, context) | centered modal 560 px | centered modal |
| **Hidden/collapsed on phone** | Kaynak, Sorumlu, Son temas columns; header search; legend; tracker; facts 4–5 | Kaynak, Sorumlu | — |
| **Primary action location** | top of screen (strip / Sonraki adım) and bottom (sticky composer / Kaydet); never mid-page only | top | top |

Tap targets ≥44 px on ≤767 px; no table scrolls horizontally on a phone; no element wider than the viewport (Playwright assertion in UX-QA01).

## K. Accessibility Optimization — implementation rules

| Rule | Before (observed) | After |
|---|---|---|
| Text contrast ≥4.5:1 | `.text-muted` #71717a on #31324a = 2.58:1 (all field labels) | `--se-text-2` #b0b0bb (5.0:1) for labels, `--se-text-3` #a8a8b3 (4.5:1) for meta |
| Minimum size | 10–11 px timestamps, tracker cells | 13 px meta, 12 px only for uppercase captions |
| Focus visible | ~40 % of controls no ring | global `:focus-visible { outline: 2px solid var(--se-focus); outline-offset: 2px }` |
| Touch targets | 22–30 px buttons, ✓ 32×20 | 44 px on ≤767 px; `.se-btn-sm` desktop-only |
| Icon names | menu, search, timers, bell, theme unnamed | `aria-label="Menüyü aç"`, "Bildirimler, 3 yeni", … |
| Form labels | `select[name=staff_id]` unlabeled | `<label for>` or `aria-label="Sorumlu"` |
| Errors | generic text, no association | `.se-error` with `role="alert"`, input `aria-describedby`, `aria-invalid` |
| Status not colour-only | coloured labels only | every badge has text + dot; calendar events carry type text |
| Language | `lang="en"` on Turkish content | `lang` from staff language; patient blocks `lang` per patient |
| Button text | "Ayarla", "Kaydet" (ambiguous) | verb + object ("Fotoğrafları incele"); row buttons get `aria-label` with the patient name |
| Modal focus | Bootstrap default | focus trapped, returned on close, `aria-labelledby` |
| Skip link | none | "İçeriğe geç" first in DOM |
| Reduced motion | partial | all transitions under `prefers-reduced-motion: no-preference` |
| Headings | H4 page title, stray H3/H4 in hidden Perfex modals | one H1 per page, H2 cards, H3 inside context column |

Before/after example, field label: `<span class="text-muted">State</span>` → `<dt class="se-facts__k">Durum</dt>` rendered `#a8a8b3` 12 px uppercase on `#31324a` (4.5:1) with the value in `#e4e4e7` 14 px.

## L. Turkish UX Copy & Terminology

Canonical set (full glossary, status vocabulary, next-action sentences, timeline labels, error/empty/confirm patterns and 30 lang-key rewrites in `AZIN-CRM-UX-COPY-TR.md`):

**Hasta** (person, any stage; stage 1 = *Yeni talep*) · **Sohbet** (WhatsApp/IG thread) · **Ön görüşme** (consultation) · **Kaş ekimi** (procedure) · **Bakım** (aftercare) · **Takip** (follow-up message) · **Kontrol** (in-clinic check) · **Rıza** (KVKK consent) · **Onay** (staff approval) · **Teklif** (quote) · **Değerlendirme formu / İnceleme** (form / internal review) · **Sonraki adım** · **Otomatik mesajlar** · **Sorumlu** · **Yanıt penceresi** · **Gönderici**.

Retired: Aday, Potansiyel müşteri, Fırsat, Lead, Müşteri, Görüşme (for chat), Konsültasyon, Klinisyen, Hekim, Dr., Onay (for consent), Yolculuk (in UI), Convert, Estimate, Proposal.

Azin Asgari is always **Kaş Ekimi Uzmanı**; `se_journey_lang:174` ("hukuk ve hekim onaylı") is rewritten and a CI grep blocks *hekim|doktor|Dr\.|cerrah|klinisyen* in staff strings.

## M. RTL Strategy (fa / ar staff)

- `<html dir>` from the staff language (`fa`, `ar` → `rtl`); Perfex core already flips its grid with `dir`.
- All SE CSS uses logical properties: `margin-inline-start/end`, `padding-inline-*`, `inset-inline-*`, `border-inline-start`, `text-align: start/end`; no `left/right`, no `pull-right` in SE views (use `.se-end` = `margin-inline-start:auto`).
- **Sidebar** mirrors to the right; active-item bar moves to the inline-end edge; tab bar order mirrors automatically.
- **Chat bubbles:** outbound aligned to inline-end, tails on the inline-end corner; message text uses `unicode-bidi: plaintext` so a Turkish bubble in an Arabic UI still reads correctly.
- **Timeline:** dot column on the inline-start; time meta on inline-end.
- **Forms:** labels above inputs (no side labels), so nothing reflows; required asterisk after the label in both directions.
- **Icons:** mirror only directional icons (← → chevrons, "send" arrow) via `[dir=rtl] .se-mirror { transform: scaleX(-1) }`; never mirror clocks, logos, check marks.
- **Numbers / phones / times:** wrapped in `<bdi dir="ltr">` (`se_ui_phone`, `se_ui_age`) and `font-variant-numeric: tabular-nums`; dates formatted by locale but kept LTR inside RTL text.
- **Padding/margins:** tokens only; verified by a Playwright run with `dir=rtl` (UX-X04).
- Persian/Arabic staff strings are out of scope for this phase (the UI ships TR + EN); the layout must simply not break when they arrive.

## N. Design System

Summarised here; full spec with tokens, contrast table, 18 components, states, a11y and Bootstrap-3 notes in `AZIN-CRM-DESIGN-SYSTEM-v1.md`, and the exact CSS in `mockups/azin-ds.css`.

- **Tokens:** bg `#1e1e2e`, surface `#31324a`, surface-2 `#3a3c58`, text `#e4e4e7 / #b0b0bb / #a8a8b3`, primary `#4f5bf0` (white 5.1:1), link/focus `#9aa3ff`; semantic positive/warning/danger/info/**action**/inactive with `-soft` fills; spacing 4/8/12/16/24/32; radius 8 (controls) / 12 (cards) / pill; type 24/18/15/14/13; controls 40 px, 44 px on phones. Light-theme values included.
- **Components:** `.se-btn` (primary/secondary/ghost/danger/icon), `.se-badge` (6 semantics + plain), `.se-attn` row, `.se-ph` patient header, `.se-stages`, `.se-next`, `.se-alert`, `.se-card`, `.se-table`, `.se-chip`, `.se-input/.se-field`, `.se-tabs`, `.se-tl` timeline, `.se-msg`/`.se-composer`, calendar/agenda, mobile shell (tab bar, FAB, sheet), empty state, modal/sheet.
- **Delivery:** `modules/se_core/assets/se-ds.css` + `se_ui_*` helpers; inline styles removed; Perfex overrides scoped under `body.se-clinic`.

## O. Wireframes / Mockups

All in `docs/design/mockups/` — HTML sources (responsive, one file per screen, shell injected by `build.mjs`), `azin-ds.css`, and rendered PNGs in `png/` at 1440 (`-desktop`), 768 (`-tablet`) and 390 (`-phone`). Open the HTML in a browser and resize to see every breakpoint.

| # | Screen | Files |
|---|---|---|
| 1 | Bugün — operational dashboard | `01-today.html`, `png/01-today-{desktop,tablet,phone}.png` |
| 2 | Hasta çalışma alanı — Genel tab | `02-patient-workspace.html`, `png/02-patient-workspace-*.png` |
| 3 | Mesajlar — three-column inbox / phone thread | `03-whatsapp.html`, `png/03-whatsapp-*.png` |
| 4 | Hastalar — unified list | `04-patients-list.html`, `png/04-patients-list-*.png` |
| 5 | Randevular — month calendar / phone agenda | `05-calendar.html`, `png/05-calendar-*.png` |
| 6 | Teklif tab | `06-quote-tab.html`, `png/06-quote-tab-*.png` |
| 7 | Randevu formu with conflict + same-day prefill | `07-appointment-form.html`, `png/07-appointment-form-*.png` |
| — | Current state for comparison | `current-state/*.jpg|png` (dashboard 1440/768, appointment form 1024, blank calendar, collapsed composer 390) |

The mockups are implementation-oriented: class names, sizes, colours and copy are the ones the backlog asks the developer to ship; the build script's assertions (no horizontal overflow, no button under 32 px) pass at all three widths. Figma was not used: HTML with the real stylesheet is closer to the Bootstrap-3 implementation and can be diffed against the shipped CSS.

## P. Before / After Comparison

| Screen | Current problem | Optimization | Expected benefit |
|---|---|---|---|
| Dashboard | 12 all-time counters, false consent banner, no work list; the real queue is on another page | *Bugün*: attention queue with patient · reason · age · one button; today's appointments; unread; honest Sistem card | Morning check goes from 3 pages to 1; nothing depends on memory |
| Leads | Stock Perfex: Company/Value/Proposals, 14 English stages, "Convert to customer", 13-column table | Hidden for clinic roles; admins get a trimmed Turkish version; unified Hastalar replaces it | One people screen; no CRM jargon |
| Patients | No name/phone, `Brand 22`, `active`, search by nationality only | KVKK envelope becomes a tab; list retired | Patients findable by name/phone |
| Journey index | Counters + 100-row table, no search/filters; entry point for templates/settings | Merged into Bugün + Hastalar; settings move to Ayarlar | One list with next step per row |
| Journey detail | Good structure but raw codes, English tasks, no identity, no composer, 30 px buttons, 8-pill stepper | Patient header, 7-stage bar, Sonraki adım panel, alerts, human timeline, embedded Sohbet, 9 tabs, 44 px on mobile | Fewer page hops (3 → 1), clear next step |
| WhatsApp inbox | Raw digits, `#id`, `staff #3`, no preview/search/pagination | Three-column inbox with names, previews, state chips, filters, search | Find and triage a thread in seconds |
| WhatsApp thread | Fixed 560 px box, page scroll, English tracker, context 1 500 px down, silent pause, 78 px textarea on phone | Full-height thread, sticky composer, context column/strip + sheet, contextual actions, opt-in pause, Turkish tracker collapsed | Reply from a phone; know the patient's state before replying |
| Appointment list | `Related lead #900720`, no type | Name + type badge + duration + staff | Readable schedule |
| Appointment calendar | Blank (library never loaded) | Rendered month/week/day with type colours; agenda on phones | Calendar usable; same-day planning visible |
| Appointment form | Title/Start/End, no type, generic conflict text | Type chips → duration, patient prefilled, exact conflict + next free slot, notification checkboxes | Fewer errors, 3-click same-day procedure |
| Quote | Deposit prominent, statuses raw, Sales blind | Structured layout, status badges, one-click approve-and-send, deposit collapsed, Sales read-only | Faster approval; no confusion about deposit |

## Q. Component Inventory

| Existing | Problem | New component | Helper |
|---|---|---|---|
| `label label-success/danger/…` (Perfex) and `se_ui_badge` mix | inconsistent semantics, raw state text | `.se-badge.se-badge-{positive,warning,danger,info,action,inactive}` | `se_ui_badge_state($state)`, `se_ui_badge($kind,$text)` |
| Journey action row (8+ buttons by state) | scattered, all same weight | `.se-next` + `.se-btn-primary` (one), overflow `⋯` menu | `se_ui_next_action($na)` |
| Dashboard stat cards (`se_ui_stat`) | passive counters | `.se-attn` attention rows; `.se-pipe` stage pills | `se_ui_attention_row($item)`, `se_ui_pipeline($counts)` |
| Journey header facts (`State/Assignee/…` in `.text-muted`) | technical, low contrast | `.se-ph` patient summary header with `dl.se-facts` | `se_ui_patient_header($ctx)` |
| 8-step `label` stepper | too granular, tiny | `.se-stages` 7 segments | `se_ui_stages($state)` |
| Timeline (`kind · summary` raw) | codes | `.se-tl` with actor dots and Turkish labels | `se_journey_event_label()` |
| Chat thread + composer (`se_chat_ui.php` inline CSS) | inline styles, dead mobile CSS | `.se-msgs/.se-msg(.auto)/.se-composer/.se-comp-row` | existing `se_ui_chat_thread/composer`, restyled |
| Outbound tracker table | English, always visible | `.se-ctx` one-liner + expandable `.se-table` | `se_ui_outbound_summary()` |
| `hidden-xs` table columns / `.table-responsive` scroll | horizontal scroll on phones | `.se-table` with `.hide-m` and stacked identity cell | `se_ui_table_row()` pattern |
| Perfex `.btn-xs/.btn-sm` everywhere | 22–30 px targets | `.se-btn` 40/44, `.se-btn-sm` desktop tables only | `se_ui_btn()` |
| Perfex alert banners (`alert-warning` full width) | persistent, ignored | `.se-alert` rows inside cards; Sistem card | `se_ui_alert()` |
| Raw `#id`, `staff #3`, `905…` | technical identifiers | name via lead join, staff name, `se_ui_phone()` masked | `se_ui_phone()`, `se_ui_staff()` |
| Bootstrap modal for everything | unusable on phones | modal ≥768, bottom sheet ≤767 | `se_ui_sheet()` |
| Numbered pagination buttons | noisy | `.se-pager` Önceki/Sonraki + range | `se_ui_pager()` |
| FullCalendar default theme | not loaded; status colours | type-coloured events + legend; agenda on phones | `se_appt_event_class($type)` |

## R. Implementation Mapping

| UI improvement | Likely file/module |
|---|---|
| Tokens, components, mobile shell, header overlap fix | new `modules/se_core/assets/se-ds.css`; `modules/se_core/se_clinic.php` (`app_admin_head`); delete inline CSS in `se_core/se_chat_ui.php:25-63`, `se_core/views/se_reports_health.php:13-27`; retire layout rules in `se_core/assets/pwa.css` |
| UI helpers | `modules/se_core/helpers/se_ui_helper.php` |
| Next action | new `modules/se_journey/next_action.php` (reads `se_journeys`, tasks, quotes, appointments) |
| Turkish copy, timeline labels, tracker | `modules/se_journey/language/turkish/se_journey_lang.php`, `modules/se_core/language/turkish/se_core_lang.php`, `modules/se_whatsapp/language/turkish/*`, `modules/se_core/se_outbound_tracker.php:130-217`, `modules/se_journey/controllers/Se_journey.php:649-666`, task titles in `se_journey/helpers.php`, `review.php`, `aftercare.php`, `intake.php` |
| Navigation | `modules/se_core/se_navigation.php`, `modules/se_core/se_clinic.php` (hidden slugs, dashboard re-point, role gates) |
| Bugün | `modules/se_core/controllers/Se_dashboard.php`, `modules/se_core/views/se_dashboard.php`, `modules/se_journey/health.php` (queue query), `se_core/se_outbox_ui.php:187-264` (counters removed / brand-0 fix) |
| Hastalar list | new `modules/se_core/controllers/Se_hastalar.php` + `models/Se_hastalar_model.php` + `views/se_hastalar.php`; joins `tblleads`, `se_journeys`, `se_wa_conversations`, `se_appointments` |
| Patient page | `modules/se_journey/views/view.php`, `modules/se_journey/ui.php`, `modules/se_journey/controllers/Se_journey.php`; KVKK tab from `se_core/views/se_patients_view.php` |
| WhatsApp inbox/thread | `modules/se_whatsapp/views/inbox.php` + `conversation.php` (merge), `modules/se_whatsapp/controllers/Se_whatsapp.php`, `models/Se_whatsapp_model.php:15-39` (joins, LIMIT, search), `modules/se_core/se_chat_ui.php`, `modules/se_journey/ui.php:69-101` (context + contextual actions), `modules/se_whatsapp/outbound.php:423-424` (pause opt-in) |
| Instagram as a tab | `modules/se_instagram/views/*`, same components |
| Calendar | `modules/se_appointments/views/calendar.php` (asset load, event classes), `controllers/Se_appointments.php` (feed adds `type`) |
| Appointment form | `modules/se_appointments/views/form.php`, `controllers/Se_appointments.php:189` (conflict message), `models/Se_appointments_model.php` (type, duration, next free slot) |
| Same-day shortcut | `modules/se_journey/views/view.php` care tab, `consultation.php` (prefill payload) |
| Quote tab | `modules/se_journey/views/view.php:233-293`, `review.php` (statuses, expiry), capabilities in `helpers.php` |
| Accessibility attributes | `se_clinic.php` (header buttons), `se_chat_ui.php`, forms; `<html lang/dir>` in the head hook |
| RTL | `se-ds.css` logical properties; sweep of `margin-left`/`pull-right` in `se_chat_ui.php:49,191`, `view.php:282,285`, `se_ui_helper.php:239` |
| QA | Playwright suite on the Mac (`scripts/ui-regression/`), CI copy grep |

Perfex core is not modified; the header-overlap fix is CSS scoped to `body.se-clinic`.

## S. Prioritized UI/UX Backlog

Full tickets (problem, implementation, files, dependencies, acceptance, priority, effort, benefit, mobile impact, risk) in `AZIN-CRM-UIUX-BACKLOG.md`. Summary:

| Priority | Tickets |
|---|---|
| **UX-P0** | W01 mobile composer/thread · A01 calendar renders |
| **UX-P1** | F01 tokens · F02 helpers · F03 next action · F04 Turkish copy · NAV01 sidebar · NAV02 tab bar · D01/D02 Bugün · L01 Hastalar · P01–P04 patient header/stage/next/timeline · W02 strip+sheet · W03/W04 three-column inbox · W05 pause opt-in · W06 contextual actions · A02 agenda · A03 form v2 · A04 same-day · Q01 quote layout · X01/X02 a11y · QA01–03 |
| **UX-P2** | F05 phone · NAV03 tablet header · NAV04 search · D03/D04 · L02/L03 · P05–P07 · W07–W09 · A05/A06 · Q02/Q03 · X03/X04 |
| **UX-P3** | P08 reopen · X05 |

## T. Recommended Implementation Sequence

1. **Week 1 — Foundation:** F01 `se-ds.css` (tokens, components, mobile shell, tablet header fix) → F02 helpers → F03 `se_journey_next_action` → F04 Turkish copy + CI grep. Ship: nothing visible changes yet except contrast, focus, sizes, copy.
2. **Week 2 — Stop the bleeding:** W01 mobile thread/composer, W05 pause opt-in + Resume, A01 calendar, NAV01/NAV02 navigation + tab bar, D04 banner fix. Ship: phones usable, calendar usable, four-item menu.
3. **Weeks 3–4 — Cockpit:** D01/D02 Bugün, P01/P02/P04/P05 patient header + stage bar + Sonraki adım + human timeline + alerts, W02 context strip + sheet. Ship: staff land on a work list and every patient page says what to do.
4. **Weeks 5–6 — One person, one inbox:** L01 Hastalar (+ L02 retire old lists), W03/W04/W06 three-column Mesajlar with contextual actions, W07/W08. Ship: Leads/Patients/Journeys menus gone for clinic roles.
5. **Week 7 — Appointments & quote:** A02 agenda, A03 form v2, A04 same-day shortcut, A05/A06, Q01/Q02/Q03. 
6. **Week 8 — Polish & QA:** P03 embedded Sohbet + Dosyalar, P06/P07, X03/X04 RTL, W09 Instagram tab, L03 Perfex leads cleanup, QA01 visual regression, docs.

Backend items from the first audit that these depend on: PJ-003 `phone_e164` (before L01/W04), AP-001/AP-002 (with A01/A03), WF-005 quote expiry (with Q02), OBS-001 (with D03), WA-003 reminders (the form's checkbox must be true before it is shown).

---

## Final answers

**10 highest-impact UX changes:** §A list (1–10).

**Recommended final navigation:** Operasyon → Bugün · Hastalar · Mesajlar · Randevular; Yönetim → Raporlar (owner/admin) · Entegrasyonlar (admin) · Ayarlar (admin: Rıza metinleri, Süreç ayarları, Şablonlar, WhatsApp Flows, Perfex kurulumu/kayıtları). Phone: bottom tab bar Bugün · Hastalar · Mesajlar · Randevu · Diğer.

**Recommended final dashboard layout (Bugün):** page head with date, counts and *+ Randevu / + Hasta*; left 8 columns = "İlgilenmeniz gerekenler" attention queue (priority bar · patient · badge · reason · age · one button, ≤25 rows) over "Hasta akışı · bu hafta" stage pills; right 4 columns = Bugünkü randevular, Okunmamış mesajlar, Sistem (only when something needs attention). No all-time counters, no persistent banner. Phone: same blocks stacked, queue first.

**Recommended final patient workspace layout:** header card (avatar · name + state badge · phone/language/source/assignee · 5 facts · WhatsApp'tan yaz / + Randevu / ⋯) → 7-segment stage bar → Sonraki adım panel (sentence · reason · one button) → alert rows → tabs Genel · Sohbet · Değerlendirme · Fotoğraflar · Teklif · Randevular · İşlem & Bakım · Geçmiş · Dosyalar → Genel = 8/4 (timeline 24 h | evaluation summary, alerts, notes). Phone: compact header, numbered stages, full-width Sonraki adım, scrolling tabs.

**Recommended final WhatsApp layout:** desktop 320 / fluid / 340 — conversation list (search, chips, avatar/name/preview/time/unread/state) | thread (head, dashed "otomatik" bubbles, window row, full-width textarea, tool row with opt-in pause, Gönder) | context column (state + step, Sonraki adım, ≤3 contextual buttons, evaluation checklist, facts, assignee, queue one-liner). Phone: back · name · ⓘ; context strip (badge · reason · primary button); full-width messages; sticky composer `🙂 📎 🎤 ⏸ · Gönder`; context in a bottom sheet; tab bar hidden.

**Implementation order:** §T — foundation (F01–F04) → W01, W05, A01, NAV01/02 → Bugün + patient header/next action → Hastalar + three-column Mesajlar → appointments + quote → polish, RTL, visual regression.
