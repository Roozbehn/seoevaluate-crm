# Perfex Plugin Audit

Source (originals untouched): `/Users/roozbehnazari/Downloads/perfex plugins/`.
Inventory only — archives were **not extracted** and **no scripts/binaries were executed**. All six unique
plugins are commercial CodeCanyon/Envato items with proprietary licenses.

## 1. Inventory (checksums, contents, no extraction)

| Plugin | Archive | SHA-256 | Size | Top-level | Purpose |
|--------|---------|---------|------|-----------|---------|
| Flutex Mobile App | `52769004-flutex-perfex-admin-staff-mobile-app.zip` | `54bb4693…3206cf9` | 6.8 MB | `Flutex/` | Flutter staff mobile app (needs REST API) |
| Accounting & Bookkeeping | `accounting-bookkeeping-perfex.zip` | `1890e5bf…caad222` | 15.0 MB | `accounting/` (has install.php) | Double-entry accounting module |
| Dark Theme | `codecanyon-perfex-crm-dark-theme-v1.2.3.zip` | `79953203…6430afc` | 0.8 MB | nested `perfex_dark_theme.zip` + PDF | Cosmetic admin dark theme |
| Service Management | `codecanyon-service-management-module-for-perfex-crm.zip` (+ identical `(1)`) | `7aec19c1…4fc81975` | 27.1 MB | `App/`, `document/`, nested `upload.zip` | Field/service job management |
| PRChat | `prchat perfex v2.zip` | `15671bb6…e9a82704` | 1.6 MB | `prchat/` (has install.php) | Live website chat widget |
| WhatsBot | `whatsbot-whatsapp-marketing-bot-chat-module-for-perfex-crm.zip` | `087c95d5…d23f15fa9` | 18.4 MB | `main-files/` | WhatsApp marketing/bot module |

- **Traversal/absolute-path scan:** none found in any archive.
- **Duplicate archive:** `codecanyon-service-management-module-for-perfex-crm (1).zip` == the other (same SHA-256).
- Vendor/version/release-date/exact license: to be confirmed from each archive's docs on deeper review;
  all are CodeCanyon → **proprietary**, license ownership unverifiable from files alone.

## 2. Overlap with the completed custom architecture

| Plugin | Overlap | Note |
|--------|---------|------|
| WhatsBot | **High** — duplicates Phase 3 `se_whatsapp` inbox/messaging | "Marketing bot" wording suggests possibly unofficial/marketing-API flows; per policy, reject WhatsApp plugins built on unofficial WhatsApp Web/QR/browser automation. Would create a 2nd inbox/token store. |
| PRChat | Medium — live-chat inbox concept overlaps our inbox model | Website chat is a different channel but adds a competing conversation store. |
| Service Management | Low–Medium — job/service management vs our appointments | Different domain, but large; risks a parallel scheduling surface. |
| Accounting & Bookkeeping | None — genuinely new (finance) | Not built by us; heavy; bundles its own highcharts assets. |
| Dark Theme | None — cosmetic | Low value / ongoing theme-maintenance cost. |
| Flutex Mobile App | None — new surface (mobile) | Requires a REST API + ongoing app maintenance; large scope. |

## 5. Selection table (preliminary — pending license confirmation)

| Plugin | Function | Compatibility (3.4.1/CI3/PHP8.1→8.3) | Overlap | Adaptation | Maintenance risk | Decision (preliminary) |
|--------|----------|--------------------------------------|---------|-----------|------------------|------------------------|
| WhatsBot | WhatsApp messaging | Unverified | High (se_whatsapp) | Would need brand_id + de-dup vs our inbox | High | **Reject / Reference only** (duplicates completed module; WhatsApp-method policy) — *Awaiting owner confirmation* |
| PRChat | Live chat | Unverified | Medium | Brand scoping + ownership checks | Medium–High | **Reference only** — *Awaiting owner confirmation* |
| Service Management | Service jobs | Unverified | Low–Med | Brand scoping; avoid parallel scheduling | High | **Awaiting owner confirmation** (only if a real service-job need exists) |
| Accounting & Bookkeeping | Accounting | Unverified | None | Brand scoping; PHP 8.3 check | High | **Awaiting owner confirmation** (genuine new value; heavy) |
| Dark Theme | Theme | Likely OK | None | None | Low–Med | **Reference only** (cosmetic; not worth vendor lock-in) — *Awaiting owner confirmation* |
| Flutex Mobile App | Mobile app | Needs REST API | None | Large | High | **Awaiting owner confirmation** (new surface; large scope) |

## Gate — owner decision required before any installation

Every plugin is a **commercial CodeCanyon item**. Per the audit rules:
- License ownership / permitted repo-storage cannot be established from the files → each is marked
  **"Awaiting owner confirmation"** and must not be installed until confirmed.
- WhatsBot and PRChat **duplicate the completed `se_whatsapp` inbox**; installing either risks a second
  inbox/token store and (for WhatsBot) may rely on non-official WhatsApp methods → lean **reject/reference**.

**Recommendation:** install **none** now. Confirm which plugins you own valid licenses for and genuinely
want, then a per-plugin adapt-and-install workflow (feature branch, backup, brand scoping, lint, idempotent
activation, non-destructive deactivation, synthetic tests) can proceed for the approved, non-duplicative ones
(most likely candidates by net-new value: **Accounting & Bookkeeping**, possibly **Service Management**;
**Dark Theme** is cosmetic; **WhatsBot/PRChat** are rejected as duplicative).

## Not yet done (continues after owner confirmation)
Deep per-plugin code-quality/security review (§2), extraction into an off-repo temp dir, installer/schema
review, brand-boundary adaptation, staging install + synthetic tests (§6/§8), and the final
`docs/PERFEX-PLUGIN-IMPLEMENTATION-REPORT.md` (§9) — all gated on the licensing/ownership decision above.
