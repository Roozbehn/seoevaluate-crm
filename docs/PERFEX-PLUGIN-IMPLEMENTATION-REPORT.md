# Perfex Plugin Implementation Report

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
