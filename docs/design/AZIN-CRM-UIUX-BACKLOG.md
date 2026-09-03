# Azin CRM — UI/UX Implementation Backlog

Priority scale: **UX-P0** cannot complete a critical workflow · **UX-P1** major usability/productivity issue · **UX-P2** significant improvement · **UX-P3** polish. Effort S/M/L. Benefit = estimated staff-time saved or errors prevented. Mobile = impact on phone use. Risk = regression risk of the change. Every ticket references the spec section in `docs/audits/CRM-UIUX-OPTIMIZATION-2026-09-04.md` and the mockup that shows the target. Backend-only work (reminders consumer, consent purpose, CSRF…) stays in the first audit's backlog (`AZCRM-*`); items here are UI/UX and the query/helper layer they need.

## Foundation (do first — everything else builds on it)

| ID | Title | Problem | Implementation | Files | Deps | Acceptance | Prio | Effort | Benefit | Mobile | Risk |
|---|---|---|---|---|---|---|---|---|---|---|---|
| UX-F01 | Ship `se-ds.css` tokens + components | Styles are inline per module; dead mobile selectors; `.text-muted` 2.6:1 | Create `modules/se_core/assets/se-ds.css` from `docs/design/mockups/azin-ds.css`; load via `app_admin_head` in `se_clinic.php`; delete inline `<style>` in `se_chat_ui.php`, `se_reports_health.php`; retire `pwa.css` layout rules | se_core/assets, se_clinic.php, se_chat_ui.php, se_reports_health.php | — | No `<style>` in module views; contrast probe ≥4.5:1 on all text ≥13 px; both themes render | UX-P1 | M | High (unblocks all) | High | Low |
| UX-F02 | UI helper API | Views hand-write badges/buttons | Add `se_ui_btn/badge_state/attention_row/patient_header/stages/next_action/alert/phone/age` to `se_ui_helper.php`; state→label→stage map from UX-COPY §3 | se_core/se_ui_helper.php, lang files | F01 | Every helper has a test rendering the expected class + `_l` key; grep finds no `label label-` in SE views | UX-P1 | M | High | — | Low |
| UX-F03 | `se_journey_next_action()` | No single "what next" | One function returning `{state_label, sentence, reason, age, priority, action_label, url}` per UX-COPY §4; used by dashboard, list, patient page, thread | se_journey/helpers.php (new next_action.php) | — | Table-driven test: each journey state + timing yields the documented sentence | UX-P1 | M | High | High | Low |
| UX-F04 | Turkish copy pass | English literals, raw codes, "hekim" | Route all task titles, timeline kinds, tracker strings, consent keys, care statuses through `_l`; apply UX-COPY §6–§8; add CI grep for `hekim|Dr\.|doktor` in lang + `se_journey_task\(\$j, '[a-z_]+', '[A-Z]` | se_journey/*.php, se_outbound_tracker.php, lang/turkish/*, scripts | — | Zero English strings on Bugün, Hastalar, patient page, thread in TR; CI gate green | UX-P1 | M | High | Med | Low |
| UX-F05 | Phone formatter | Three renderings | `se_ui_phone($e164, $mask)`; store E.164 (`phone_e164`, from audit PJ-003) | se_ui_helper.php | AZCRM-PJ-003 | One format everywhere; masked in lists/Sales | UX-P2 | S | Med | Med | Low |

## Navigation & IA

| ID | Title | Implementation | Files | Deps | Acceptance | Prio | Effort | Benefit | Mobile | Risk |
|---|---|---|---|---|---|---|---|---|---|---|
| UX-NAV01 | New sidebar: Bugün · Hastalar · Mesajlar · Randevular / Raporlar · Entegrasyonlar · Ayarlar | Rewrite `se_navigation.php` groups; hide Leads, Customers, Patient journeys, Patients, Instagram as top-level for clinic roles; Perfex Leads/Customers reachable under Ayarlar → Perfex kayıtları (admin) | se_navigation.php, se_clinic.php | F02 | Clinic role sees exactly 7 items; admin sees 7 + Ayarlar children; Leads still reachable by URL for admins | UX-P1 | S | High | High | Low |
| UX-NAV02 | Mobile bottom tab bar | 5-item fixed bar ≤767 px with counts (Bugün, Mesajlar) via `app_admin_head` markup + CSS; hides inside a thread | se_clinic.php, se-ds.css | F01 | Tab bar present on all SE pages at 390; absent in conversation view; targets ≥44 px | UX-P1 | S | High | High | Low |
| UX-NAV03 | Tablet header fix | At 700–990 px move Perfex top-bar icons behind the existing chevron menu | se-ds.css (`body.se-clinic` override) | F01 | No header control with `top>60` at 769/800/900/990 | UX-P2 | S | Med | High | Low |
| UX-NAV04 | Global search ⌘K | Search patients by name/phone, threads, appointments from the header | se_core new controller `se_search` + Perfex search hook | F05 | Typing 4 digits of a phone finds the patient in <1 s | UX-P2 | M | Med | Med | Low |

## Bugün (dashboard)

| ID | Title | Implementation | Files | Deps | Acceptance | Prio | Effort | Benefit | Mobile | Risk |
|---|---|---|---|---|---|---|---|---|---|---|
| UX-D01 | Replace 12-card dashboard with Bugün (mockup 01) | Controller builds: attention queue (F03 over active journeys + urgent + failed + paused + unread), today's appointments, unread threads, pipeline counts this week, system card; 8/4 layout; land clinic roles here | se_core/controllers/Se_dashboard.php, views/se_dashboard.php, se_journey/health.php | F01–F03 | First viewport shows the queue with one button per row; no all-time counters; loads <600 ms | UX-P1 | M | High | High | Low |
| UX-D02 | Queue ordering & thresholds | Sort by priority (danger > action > info) then age; thresholds from UX-COPY §4; cap 25 with "tümünü gör" | health.php | D01 | Seeded fixtures order as specified | UX-P1 | S | High | — | Low |
| UX-D03 | System card honesty | Show gönderici age, cron age, skipped-by-reason counts, failed sends; hide when all green | se_reporting.php, dashboard view | AZCRM-OBS-001 | Card shows the 7 consent-skipped rows today | UX-P2 | S | Med | — | Low |
| UX-D04 | Remove false consent banner | Check visible brand not brand 0 | se_outbox_ui.php:250 | — | Banner absent when brand 22 configured | UX-P2 | S | Low | — | Low |

## Hastalar (unified list)

| ID | Title | Implementation | Files | Deps | Acceptance | Prio | Effort | Benefit | Mobile | Risk |
|---|---|---|---|---|---|---|---|---|---|---|
| UX-L01 | Unified Hastalar list (mockup 04) | New view over `tblleads ⟕ se_journeys ⟕ se_wa_conversations ⟕ se_appointments`: Hasta (name+masked phone+lang), Aşama badge, Sonraki adım, Son temas (+unread), Randevu, Sorumlu, Kaynak, row action; chips Aktif / İşlem bekleyen / Bana atanan / Bakımda / Kapalı; selects Aşama, Kaynak; search name/phone; 25/page | se_core new controller `Se_hastalar` + model; se_journey/health.php | F02, F03, F05 | Search by 4 phone digits or name works; every row has ≤1 button; ≤767 px shows 3 columns + button, no horizontal scroll | UX-P1 | L | High | High | Med |
| UX-L02 | Retire `se_patients_list` and `se_journey/index` as menu items | Keep KVKK envelope as a tab on the patient page; journey counters move to Bugün | se_navigation.php, se_patients views | L01, D01 | Old lists reachable only by URL for admins | UX-P2 | S | Med | — | Low |
| UX-L03 | Perfex Leads cleanup for admins | Hide Company/Website/Value/Zip/Position/Proposals via `leads_table`/custom-field hooks; trim `pipeline.php` to mapped stages in Turkish; hide "Convert to customer" unless conversion is used | se_clinic.php, pipeline.php | — | Lead modal shows only clinic fields; status bar ≤9 TR stages | UX-P2 | M | Med | Low | Med |

## Patient workspace

| ID | Title | Implementation | Files | Deps | Acceptance | Prio | Effort | Benefit | Mobile | Risk |
|---|---|---|---|---|---|---|---|---|---|---|
| UX-P01 | Patient header (mockup 02) | `se_ui_patient_header()` with identity line, facts dl, actions; data from lead + journey + conversation + next appointment | se_journey/views/view.php, ui helper | F02, F03 | Phone/language/source/assignee/last contact/appointment/automation/consent/window visible without opening Lead | UX-P1 | M | High | High | Low |
| UX-P02 | 7-segment stage bar + Sonraki adım panel | Replace 8-pill stepper; add `.se-next` under the header; primary button = next action | view.php | F03 | Panel present in all non-terminal states; button routes to the right tab/action | UX-P1 | S | High | High | Low |
| UX-P03 | Tabs: Genel · Sohbet · Değerlendirme · Fotoğraflar · Teklif · Randevular · İşlem & Bakım · Geçmiş · Dosyalar | Genel = timeline (24 h) + evaluation summary + alerts + notes; Sohbet embeds the composer (same `se_ui_chat_composer`); Dosyalar = consent PDF, exports, photos zip | view.php, Se_journey.php, se_chat_ui.php | P01 | Reply sent from the Sohbet tab without leaving the page; tab counts update | UX-P1 | M | High | Med | Med |
| UX-P04 | Human timeline | `se_journey_event_label()` per UX-COPY §6; hide lead_sync rows by default; actor dots | Se_journey.php:649-666, view.php | F04 | No raw kind or `a → b` visible | UX-P1 | S | Med | Med | Low |
| UX-P05 | Alerts strip | Paused, consent missing, quote expired, appointment overdue, failed send as `.se-alert` rows in Genel | view.php | F02 | Each alert has one action | UX-P2 | S | Med | Med | Low |
| UX-P06 | Internal notes + call log | Free-text notes on Genel (stored in `se_journey_events` kind `note`) | Se_journey.php, helpers | — | Note appears in timeline as "Not (Roozbeh)" | UX-P2 | S | Med | Low | Low |
| UX-P07 | Photo review in one step | Accept set / classify inline with thumbnails; retake per photo | view.php Fotoğraflar tab, media.php | AZCRM-WF-007 | 3 photos accepted in ≤2 clicks | UX-P2 | M | High | Med | Med |
| UX-P08 | Reopen buttons | Uygun değil / Kapatıldı → "Yeniden aç" with reason | view.php, Se_journey.php | — | Transition recorded | UX-P3 | S | Low | Low | Low |

## Mesajlar (WhatsApp / Instagram)

| ID | Title | Implementation | Files | Deps | Acceptance | Prio | Effort | Benefit | Mobile | Risk |
|---|---|---|---|---|---|---|---|---|---|---|
| UX-W01 | Mobile composer + sticky bottom + no tab bar in thread (mockup 03 phone) | `.se-comp-row` nowrap with icon pause toggle; textarea full width; composer `position:sticky`; thread fills viewport | se_chat_ui.php, se-ds.css | F01 | At 390 px textarea ≥60 % width; composer visible without scrolling; 44 px targets | **UX-P0** | S | High | High | Low |
| UX-W02 | Mobile context strip + patient sheet | `.se-strip` (badge · reason · primary) under the header; ⓘ opens a bottom sheet with the context column | se_journey/ui.php, se_chat_ui.php | F03 | State + next action visible at top of thread at 390 px | UX-P1 | S | High | High | Low |
| UX-W03 | Three-column desktop inbox (mockup 03) | Conversation list (avatar/name/preview/time/unread/state chip/assignee), thread, context column with next action + contextual buttons + checklist + facts; single page with `?c=<id>` | se_whatsapp/views/inbox.php + conversation.php merged, Se_whatsapp.php, se_journey/ui.php | F02, F03, W04 | Switching threads keeps the list; context shows state-appropriate buttons | UX-P1 | L | High | Med | Med |
| UX-W04 | Inbox query: name, preview, unread, state, search, pagination | Model joins lead name + last message + journey state; `LIMIT` + cursor; search name/phone | Se_whatsapp_model.php | AZCRM-PERF-001 | 200 threads load <500 ms; search works | UX-P1 | M | High | Med | Low |
| UX-W05 | Pause opt-in + Resume in thread | Checkbox (default off) in composer; "Devam ettir" in context/strip when paused; toast with undo | se_whatsapp/outbound.php:423, se_journey/ui.php | — | Replying leaves automation active unless ticked | UX-P1 | S | High | High | Low |
| UX-W06 | Contextual actions by state | Map state → up to 3 buttons (UX-COPY §4) posting to existing `Se_journey::action` | se_journey/ui.php | F03 | Buttons change per state; each has a test | UX-P1 | M | High | Med | Low |
| UX-W07 | Automatic-message styling | Dashed bubble + "otomatik" tag for bot sends | se_chat_ui.php | F01 | Bot vs human distinguishable without reading | UX-P2 | S | Med | Med | Low |
| UX-W08 | Tracker in Turkish, collapsed by default | UX-COPY §7; show only when queue non-empty | se_outbound_tracker.php | F04 | No English; hidden when queue empty | UX-P2 | S | Med | Med | Low |
| UX-W09 | Instagram as a tab of Mesajlar | Same list/thread components with channel chip | se_instagram views | W03 | One inbox, channel filter | UX-P2 | M | Med | Med | Med |

## Randevular

| ID | Title | Implementation | Files | Deps | Acceptance | Prio | Effort | Benefit | Mobile | Risk |
|---|---|---|---|---|---|---|---|---|---|---|
| UX-A01 | Calendar renders (mockup 05) | Load FullCalendar assets; type-coloured events; legend; visible error if library missing | se_appointments/views/calendar.php, controller | AZCRM-AP-001 | Month view shows events with type colours | **UX-P0** | S | High | Low | Low |
| UX-A02 | Mobile agenda view | ≤767 px: day chips + agenda list with contextual buttons; FAB | calendar.php, se-ds.css | A01 | No month grid on phone; appointment reachable in 1 tap | UX-P1 | M | High | High | Low |
| UX-A03 | Appointment form v2 (mockup 07) | Type chips (Ön görüşme / Kaş ekimi / Kontrol / Takip) → default duration; patient search; date + time + duration instead of start/end; conflict message with next free slot; notification checkboxes | views/form.php, controller, model (`appointment_type`) | AZCRM-AP-002 | Conflict shows the exact clash and the next free slot; type saved | UX-P1 | M | High | Med | Med |
| UX-A04 | "Bugün işlem planla" shortcut | On consultation held (care tab, agenda, Bugün): pre-filled procedure form for the same day | se_journey/views/view.php, calendar agenda | A03 | 3 clicks from held to booked | UX-P1 | S | High | High | Low |
| UX-A05 | List view with type column and patient name | Replace "Related lead #id" with name + masked phone; type badge | views/manage.php | F05 | Name visible, type visible | UX-P2 | S | Med | Med | Low |
| UX-A06 | Appointment card on patient page | Randevular tab shows cards with status buttons (Yapıldı / Gelmedi / Yeniden planla) | view.php | — | Status changed from patient page | UX-P2 | S | Med | Med | Low |

## Teklif

| ID | Title | Implementation | Files | Deps | Acceptance | Prio | Effort | Benefit | Mobile | Risk |
|---|---|---|---|---|---|---|---|---|---|---|
| UX-Q01 | Quote tab layout (mockup 06) | Two-column: price/validity/scope/exclusions/recommendation/note; status checklist; versions; "Onayla ve gönder" combined for approvers; deposit fields collapsed under "Diğer" when brand policy = no deposit | view.php Teklif tab, review.php | F02 | Approver sends in one click; deposit hidden for brand 22 | UX-P1 | M | High | Med | Low |
| UX-Q02 | Quote status vocabulary + expiry | Badges per UX-COPY §3.3; `quote_expired` state; countdown "26 gün kaldı" | review.php, helpers | AZCRM-WF-005 | Expired quotes badge and appear in Bugün | UX-P2 | S | Med | Low | Low |
| UX-Q03 | Sales role sees the quote (read-only) | Capability `view_quote` separate from `edit_quote` | helpers capabilities, view.php:100 | — | Sales sees amounts and status, no internal margin | UX-P2 | S | Med | — | Low |

## Accessibility & RTL

| ID | Title | Implementation | Files | Deps | Acceptance | Prio | Effort | Benefit | Mobile | Risk |
|---|---|---|---|---|---|---|---|---|---|---|
| UX-X01 | Contrast + focus + sizes | Tokens from DS §1; `:focus-visible` ring; min 13 px meta; 44 px touch | se-ds.css | F01 | Probe: 0 text <4.5:1; every focusable shows a ring | UX-P1 | S | Med | High | Low |
| UX-X02 | Names and labels | `aria-label` on header icon buttons, composer buttons, row actions ("Elif K. — fotoğrafları incele"); label for assignee select; `role="alert"` on errors; skip link | se_clinic.php, se_chat_ui.php, forms | F02 | axe: 0 "button has no accessible name", 0 unlabeled fields | UX-P1 | S | Med | Med | Low |
| UX-X03 | `lang` and `dir` | From staff language; patient blocks carry patient lang | se_clinic.php head hook | — | `<html lang="tr" dir="ltr">` for TR staff, `fa`/`rtl` for Persian | UX-P2 | S | Low | — | Low |
| UX-X04 | Logical properties + RTL sweep | Replace `margin-left/right`, `pull-right`, `text-align:left` in SE CSS/views with logical equivalents; mirror chevrons; keep numbers/phones LTR (`dir="ltr"` + `unicode-bidi: isolate`) | se-ds.css, views | F01 | Screens at `dir=rtl` render mirrored with no overlap (Playwright shot) | UX-P2 | M | Med | Med | Med |
| UX-X05 | Reduced motion + no colour-only status | Wrap transitions; every badge has text + dot | se-ds.css | F01 | Badge text present for every status | UX-P3 | S | Low | Low | Low |

## QA for UX

| ID | Title | Implementation | Acceptance | Prio | Effort |
|---|---|---|---|---|---|
| UX-QA01 | Playwright visual regression at 390/768/1024/1440 for Bugün, Hastalar, patient page, thread, calendar, form (runs on the Mac against a sandbox brand) | screenshots + assertions: no horizontal overflow, composer width, header overlap, contrast probe, tab bar presence | Green before each UX release | UX-P1 | M |
| UX-QA02 | Copy lint | CI grep for forbidden titles and English literals in TR paths | Gate green | UX-P1 | S |
| UX-QA03 | Next-action table test | Every state × timing → documented sentence | 100 % of UX-COPY §4 rows covered | UX-P1 | S |

## Sequence summary (see main report §T)
F01 → F02 → F03 → F04 → W01 → A01 → NAV01/NAV02 → D01/D02 → P01/P02/P04 → W05/W02 → L01 → W03/W04/W06 → A02/A03/A04 → Q01 → P03/P07 → X01–X04 → remaining P2/P3.
