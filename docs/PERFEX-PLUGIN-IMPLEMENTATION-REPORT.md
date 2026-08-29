# Perfex Plugin Implementation Report


> **Terminology note.** Where this document says "staging", it means **pre-live internal use on the
> current cPanel installation** — the approved environment (PHP 8.1.34) that becomes the home for
> limited internal production use once the privacy/KVKK gate and manual UI QA clear. There is no
> planned host change; VPS / PHP 8.3 / DNS migration are optional future work.

Follows `docs/PERFEX-PLUGIN-AUDIT.md`. Owner selected **Dark Theme only**; all other plugins are
**Awaiting owner confirmation** (commercial CodeCanyon licenses) and were **not installed**. WhatsBot and
PRChat were additionally rejected as duplicative of the completed `se_whatsapp` module.

## Installed: Perfex Dark Theme v1.2.3
- **Vendor:** iDev (idevalex.com) · **Module:** `perfex_dark_theme` · **Version:** 1.2.3
- **Outer archive SHA-256:** `79953203c1b6c54354dd362693381f894b7debce88de9f36030104e9c6430afc`
- **Inner package SHA-256:** `8c05e294c31ec10199a7727077987aaefcf8f627474d226fbbd157e823555ef6`
- **Original archive:** preserved untouched in `~/Downloads/perfex plugins/` (owner's machine).
- **DB backup before install:** `~/_deploy_artifacts/backups/db_predeploy_20260829_055417.sql` (off-docroot).

### Security/code review (pre-activation)
- No `eval`/`base64_decode`/`gzinflate`/`shell_exec`/`system`/remote `curl`/`file_get_contents(http…)`/
  file writes / core-file modification found.
- `install.php` only calls `add_option` (3 cosmetic options). Migrations 110–123 are idempotent
  `add_option` guards. No `DROP`/`DELETE`/`TRUNCATE`. **No `uninstall.php`** → deactivation is
  non-destructive by design.

### Tests
- PHP lint: all files clean.
- Activation via the normal Perfex route (`admin/modules/activate/perfex_dark_theme`): **307** (success);
  `tblmodules.perfex_dark_theme.active=1`; 3 options created.
- **Regression:** `se_core`, `se_appointments`, `se_whatsapp` remain **active**; app login **200**;
  application error log **0 bytes**.
- Activation is idempotent (guarded `add_option`); deactivation removes no data (no uninstall routine).
- Visual dark-mode rendering: cosmetic, to be eyeballed by the owner in the admin UI.

### Git / licensing decision (interim)
Per owner's "decide per plugin later", the vendor source is **deployed to staging but NOT committed** —
`modules/perfex_dark_theme/` is added to `.gitignore`. To reproduce on another host, re-deploy from the
archive above (checksums recorded). If the CodeCanyon license permits private-repo storage for your own
use, we can later commit it and drop the ignore entry.

## Decisions recap
| Plugin | Decision | Reason |
|--------|----------|--------|
| Dark Theme | **Installed (staging)** | Clean, non-destructive, owner-approved |
| Accounting & Bookkeeping | Awaiting owner confirmation | New value; license/ownership unconfirmed |
| Service Management | Awaiting owner confirmation | Possible parallel scheduling; license unconfirmed |
| Flutex Mobile App | Awaiting owner confirmation | New surface; needs REST API; heavy |
| WhatsBot | **Reject / reference** | Duplicates se_whatsapp; possible non-official WhatsApp methods |
| PRChat | **Reference only** | Duplicates inbox concept; license unconfirmed |

## Items requiring ChatGPT / owner review
- Confirm CodeCanyon license ownership + whether repo-storage is permitted, per plugin.
- Confirm no real need for Service Management / Flutex before taking on their maintenance cost.
- Decide whether to commit the dark-theme vendor source or keep it deploy-only (gitignored).

## Dark Theme — deployment continuity (verified)
- **Installed module path:** `modules/perfex_dark_theme/` (active, version **1.2.3**).
- **Source archive (owner's machine):** `~/Downloads/perfex plugins/codecanyon-perfex-crm-dark-theme-v1.2.3.zip`
  — outer SHA-256 `79953203c1b6c54354dd362693381f894b7debce88de9f36030104e9c6430afc`;
  inner `perfex_dark_theme.zip` SHA-256 `8c05e294c31ec10199a7727077987aaefcf8f627474d226fbbd157e823555ef6`.
- **Git tracking:** **0 vendor files tracked** (gitignored via `modules/perfex_dark_theme/`). Not committed.
- **Copy to a replacement cPanel install:** extract the inner `perfex_dark_theme.zip`; upload the
  `perfex_dark_theme/` folder into `modules/` (cPanel File Manager or scp). No git checkout brings it.
- **Activation:** Admin → Setup → Modules → Activate `Perfex Dark Theme` (installer only adds 3 options).
- **Version verification:** `tblmodules.perfex_dark_theme.installed_version = 1.2.3`, `active = 1`.
- **Deactivation:** Admin → Modules → Deactivate. **Non-destructive** (no `uninstall.php`; no data removed).
- **Rollback:** deactivate + delete `modules/perfex_dark_theme/`. No data loss.
- **Other CodeCanyon plugins installed: none** (WhatsBot/PRChat rejected; Accounting/Service/Flutex awaiting license).
