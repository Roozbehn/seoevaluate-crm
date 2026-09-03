# Azin CRM Design System v1

**Scope:** every screen rendered by the SE modules (`se_core`, `se_journey`, `se_whatsapp`, `se_instagram`, `se_appointments`) inside Perfex 3.4.1's Bootstrap-3 admin shell, dark theme first (light theme tokens included).
**Delivery:** one stylesheet `modules/se_core/assets/se-ds.css` (tokens + components, ≈8 KB) loaded on every admin page by `se_clinic` via `app_admin_head`, replacing the inline `<style>` blocks in `se_chat_ui.php`, `se_reports_health.php` and the dead selectors in `pwa.css`. PHP helpers in `se_ui_helper.php` emit the classes below; views never write colours, sizes or `style=""`.
**Reference implementation:** `docs/design/mockups/azin-ds.css` (the exact stylesheet the mockups use).

---

## 1. Tokens

All tokens are CSS custom properties on `:root` (dark) and `[data-theme=light]` / `body.light` (Perfex's theme switch). Names are prefixed `--se-`.

### 1.1 Colour

| Token | Dark | Light | Use | Contrast (dark) |
|---|---|---|---|---|
| `--se-bg` | `#1e1e2e` | `#f4f4f7` | page background | — |
| `--se-surface` | `#31324a` | `#ffffff` | cards, panels, table rows | — |
| `--se-surface-2` | `#3a3c58` | `#eef0f5` | chips, inputs on cards, hover rows | — |
| `--se-border` | `#44465f` | `#dcdfe8` | decorative dividers | decorative |
| `--se-border-strong` | `#7c7e9c` | `#8b90a6` | input and secondary-button borders | 3.0:1 on surface (UI component) |
| `--se-text` | `#e4e4e7` | `#1f2937` | primary text | 9.8:1 |
| `--se-text-2` | `#b0b0bb` | `#4b5563` | secondary text, labels | 5.0:1 on surface-2 |
| `--se-text-3` | `#a8a8b3` | `#6b7280` | meta text (min 13 px) | 4.5:1 on surface-2 |
| `--se-primary` | `#4f5bf0` | `#4650e0` | primary buttons, active nav | white on it 5.1:1 |
| `--se-primary-hover` | `#4650e0` | `#3d46c9` | hover/active | 6.0:1 |
| `--se-primary-soft` | `rgba(79,91,240,.18)` | `rgba(70,80,224,.10)` | selected row, active nav bg | — |
| `--se-link` | `#9aa3ff` | `#3d46c9` | links | 5.4:1 |
| `--se-focus` | `#9aa3ff` | `#3d46c9` | focus ring | 7.1:1 on bg |

**Semantic status colours** (text/badge foreground on dark surface; each has a `-soft` background at 16–18 % alpha and, where a solid fill is needed, a `-solid`):

| Semantic | Token fg | Solid | Meaning in the CRM |
|---|---|---|---|
| positive | `#6ee7b7` (8.2:1) | `#34d399` with dark text `#052e1c` | done, sent, delivered, consent granted, aftercare on track |
| warning | `#fbbf24` (7.5:1) | — | waiting on the patient longer than expected, quote unanswered, window closing |
| danger | `#fca5a5` (6.6:1) | `#dc2626` with white (4.8:1) | urgent symptom, failed send, conflict, destructive action |
| info | `#93c5fd` (6.9:1) | — | scheduled, informational, automatic message |
| **action** | `#fdba74` (7.4:1) | — | **staff must act** (needs review, needs approval) — the one colour that means "you" |
| inactive | `#a1a1aa` (4.9:1) | — | paused, closed, archived |

Rule: *action* is reserved for things a staff member must do; *warning* for things the patient has not done. Never use a colour alone — every status badge carries a label and a leading dot.

Retired: `#71717a` (`.text-muted`, 2.6:1), the inline Tailwind palette in `se_reports_health.php`, `rgba(59,130,246…)`, `#ef4444`, `#10b981` in `se_chat_ui.php`.

### 1.2 Typography

Font stack unchanged (Metropolis → system). Sizes are the only allowed set:

| Token | Size / line | Weight | Use |
|---|---|---|---|
| `--se-fs-title` | 24 / 1.2 | 600 | page title (`h1`, one per page) |
| `--se-fs-h2` | 18 / 1.3 | 600 | card/section title |
| `--se-fs-body` | 15 / 1.45 | 400 | body, chat bubbles |
| `--se-fs-sm` | 14 / 1.4 | 400/600 | table cells, buttons, tabs, form labels |
| `--se-fs-meta` | 13 / 1.4 | 400 | timestamps, helper text, badge text |
| (labels) | 12 / 1.3 | 600 uppercase +.06em | field captions in the patient header only |

**Nothing below 12 px; nothing below 13 px carries information.** Numbers that align (times, ages, counts) use `font-variant-numeric: tabular-nums`.

### 1.3 Spacing

`--se-sp-1..6` = 4 / 8 / 12 / 16 / 24 / 32. Card padding 16 (24 on ≥1440). Grid gap 16. Page padding 24 desktop, 16 mobile. Vertical rhythm between cards 16.

### 1.4 Radius, elevation, motion

| Element | Radius |
|---|---|
| inputs, buttons, chips (rectangular) | 8 (`--se-r-md`) |
| cards, panels, modals, next-action box | 12 (`--se-r-lg`) |
| badges, pill chips, counts | 999 |
| chat bubbles | 14, 4 on the tail corner |

Elevation: flat by default (border, no shadow); modals and the mobile FAB get `0 6px 16px rgba(0,0,0,.4)`. Motion: 120 ms ease for hover/focus, 200 ms for drawers; all transitions wrapped in `@media (prefers-reduced-motion: no-preference)`.

### 1.5 Sizes

Controls 40 px tall on desktop, **44 px on ≤767 px** (`--se-ctrl`, `--se-ctrl-m`). Icon-only buttons are square at the same size. Sidebar 232 px, header 56 px.

---

## 2. Components

Each component: class, variants, states, a11y, and the Perfex mapping (what it replaces).

### 2.1 Buttons `.se-btn`

| Variant | Class | Look | Use |
|---|---|---|---|
| Primary | `.se-btn-primary` | solid primary, white text | the one main action on a screen/card/row |
| Secondary | `.se-btn-secondary` | transparent, strong border | alternatives next to a primary |
| Ghost | `.se-btn-ghost` | text only, link colour | tertiary, "show all", cancel |
| Destructive | `.se-btn-danger` | outlined danger; solid `#dc2626` only inside a confirm dialog | close as lost, delete |
| Icon | `.se-iconbtn` | square, no label | always `aria-label` |
| Sizes | default 40/44; `.se-btn-sm` 32 px (desktop tables only, never on touch) | | |

States: hover (`--se-primary-hover` / surface-2), focus (`:focus-visible` ring 2 px `--se-focus`, offset 2), disabled (opacity .5, `aria-disabled`), loading (label replaced by spinner + `aria-busy`). Keyboard: Enter/Space. **Rule:** one `.se-btn-primary` per visual group; a row in a list has at most one button.

Replaces: `btn btn-primary/default/info/success/xs/sm` used ad hoc; `label`-styled links used as buttons.

### 2.2 Status badge `.se-badge`

`.se-badge.se-badge-{positive|warning|danger|info|action|inactive}` — 24 px pill, 13 px 600 text, leading 7 px dot in `currentColor`. `.se-badge-plain` removes the dot for non-status tags (appointment type, channel). Text comes from `_l('se_state_' . $state)` via `se_ui_state_badge($state)` which also maps the 31 journey states to the 7 macro-stages (see UX-COPY glossary §3).

Replaces: `label label-success/danger/default/primary`, raw state strings.

### 2.3 Attention row `.se-attn > li`

Grid: `[priority bar 4px] [who + why + age] [primary action]`. `who` 15/600; `why` = badge + plain-language reason + age (`.se-age.hot` in action colour when over threshold). Priority bar: `p1` danger (urgent/failed), `p2` action (needs staff now), `p3` info (soon). On ≤767 px the button drops below and spans full width. ARIA: `<li>` contains a heading-less group; the button label includes the patient ("Elif K. — fotoğrafları incele" via `aria-label`).

### 2.4 Patient summary header `.se-ph`

`[avatar 48] [name + state badge / id-line / facts dl] [actions]`. Id-line: masked phone · language · source · assignee. Facts (`dl.se-facts`, auto-fit 150 px columns): Son temas, Randevu, Otomatik mesajlar, Rıza, Sohbet penceresi. Mobile shows the first three; the rest fold into "Ayrıntılar". Actions: primary *WhatsApp'tan yaz*, secondary *+ Randevu*, overflow `⋯` (Assign, Pause, Sync, Close).

### 2.5 Stage bar `.se-stages`

7 equal segments (Talep · Değerlendirme · İnceleme · Teklif · Ön görüşme · Kaş ekimi · Bakım). `done` positive-soft, `now` solid primary, future surface-2. Mobile: numbers only except the current segment (`data-n`). Replaces the 8-step label row of small `label` pills.

### 2.6 Next-action panel `.se-next`

Action-soft gradient, action border, `k` caption "SONRAKİ ADIM", `v` 18/600 sentence, `m` meta line, one primary button. Present on the patient page, the WhatsApp context column, and (compact) the mobile context strip `.se-strip`. Content produced by `se_journey_next_action($j)` — a single function returning `{label, reason, age, action, url, priority}`.

### 2.7 Alert `.se-alert-{warning|danger|info}`

Inline, 14 px, icon + text + optional right-aligned `.se-btn-sm`. Never full-width page banners for persistent conditions; the dashboard *Sistem* card owns integration warnings. `role="status"` (info) or `role="alert"` (danger).

### 2.8 Cards `.se-card`

Surface, border, r-12, p-16, `h2` 18/600 with optional `.se-count`. No nested cards. Table cards use `padding:0` and a footer row for pagination.

### 2.9 Tables `.se-table`

14 px, 12 px cell padding, header 13/600 in text-2, row hover. First cell is a two-line identity (`.se-name` + `.se-meta`). Status cell is a badge. Last cell is the single row action (`.se-btn-sm` on desktop, 44 px on touch). Columns marked `.hide-m` disappear ≤767 px — a table never scrolls horizontally on a phone; instead the row degrades to `identity · badge · next action · button`. Sortable headers are `<button>`s with `aria-sort`.

### 2.10 Chips `.se-chip`

36 px pill filters; `.on` = primary-soft with primary border. Group behaves as a radio group (`role="radiogroup"`) or multi-select (`aria-pressed`).

### 2.11 Inputs `.se-input`, `.se-field`

40/44 px, bg page colour on cards, `--se-border-strong` border, 12 px padding, label 14/600 above, `.se-help` 13 below, error: border danger + `.se-error` with `role="alert"` and `aria-describedby`. Native `date`/`time`/`datetime-local` on all devices. Selects styled identically.

### 2.12 Tabs `.se-tabs`

44 px, 2 px active underline in primary, count pill. Horizontal scroll on narrow screens with the active tab scrolled into view. `role="tablist"`, arrow-key navigation.

### 2.13 Timeline `.se-tl`

12 px dot per actor (patient positive, system info, staff primary, warning), 14/600 title in **plain Turkish**, 13 meta (`age · actor · channel · delivery`), optional quoted body in a page-colour box. Titles come from `se_journey_event_label($kind, $meta)` — the raw kind is never rendered.

### 2.14 Chat `.se-msg`

Bubbles 15 px, max 72 % (88 % mobile); outbound `#2f3a8a`; **automatic messages are dashed-bordered with an "otomatik" tag** so staff can tell bot from human at a glance; delivery meta 12 px; day separators. Composer: window badge row, full-width textarea (44 px min, auto-grow to 160), tool row `emoji · attach · mic · [pause toggle] · Gönder`; on phones the pause toggle becomes an icon button and the row never wraps (3×44 + 44 + 96 = 304 px ≤ 358 available).

### 2.15 Calendar

Desktop month/week/day grid with **type-coloured** events (`ev-consult` info, `ev-proc` action, `ev-check` positive, `ev-follow` `#c4b5fd`); a legend row; today highlighted. ≤767 px: **agenda list** with day chips, each entry `time · type bar · patient · type · place · staff · duration` plus the two contextual buttons; FAB for new. No month grid on phones.

### 2.16 Mobile shell

Bottom tab bar (64 px, 5 items: Bugün · Hastalar · Mesajlar · Randevu · Diğer) replaces the sidebar ≤767 px; counts as badges. Inside a conversation the tab bar hides and the composer is sticky. FAB only on list screens where "create" is the obvious action (Randevular).

### 2.17 Empty states

Card with 18/600 title, one sentence in text-2, one primary action. Copy pattern: *what this is · why it's empty · how to start* (see UX-COPY §5).

### 2.18 Modals / drawers

Desktop: Bootstrap modal 560 px, r-12, header 18/600, footer buttons right (primary last), focus trapped, Esc closes, `aria-labelledby`. Mobile: bottom sheet (full width, max 90 vh, drag handle) for the patient context, filters, and confirmations.

---

## 3. Layout grid

| Breakpoint | Shell | Content grid |
|---|---|---|
| ≤767 (phone) | header 56 + content + tab bar 64 | single column, 16 px gutters |
| 768–1023 (tablet) | header + content, sidebar off-canvas (hamburger) | single column, 24 px gutters; chat = list + thread |
| 1024–1439 | sidebar 232 + content | 2fr/1fr two-column; chat = 280 / 1fr / 300 |
| ≥1440 | sidebar 232 + content (max 1600) | 2fr/1fr; chat = 320 / 1fr / 340 |

---

## 4. Implementation notes for Bootstrap 3 / Perfex

- Load `se-ds.css` after Perfex's theme CSS; scope everything under `.se-*` so core screens are untouched. Where a core element must change (header icon overflow, `.text-muted` colour), add a minimal `body.se-clinic` override in the same file with a comment naming the Perfex selector it overrides.
- Keep Bootstrap's grid (`row/col-md-8/col-md-4`) for page structure; the DS provides `.se-grid-8-4` only for module views that are not already on the grid.
- Helper API (`modules/se_core/se_ui_helper.php`): `se_ui_btn($label,$url,$variant,$opts)`, `se_ui_badge_state($state)`, `se_ui_badge($kind,$text)`, `se_ui_attention_row($item)`, `se_ui_patient_header($ctx)`, `se_ui_stages($state)`, `se_ui_next_action($na)`, `se_ui_alert($kind,$text,$action)`, `se_ui_phone($e164,$mask=true)`, `se_ui_age($ts)`. Views call helpers; helpers own markup.
- Icons: keep Font Awesome (already loaded); every icon-only control gets `aria-label`, decorative icons get `aria-hidden`.
- Dark/light: tokens flip with Perfex's theme class; no component references a hex directly.
- RTL: all spacing uses logical properties (`margin-inline-start`, `padding-inline-end`, `inset-inline-*`, `border-inline-start`); `text-align:start`; `dir` set from the staff language (see main report §M).
