# Secret Provider — Structure Status

The filesystem secret provider is the single source of truth for every integration
secret, for BOTH the status UI and enforcement.

## Providers (config, not secrets)
`meta_capi` (per brand), `meta_page` (per brand), `meta_app`, `meta_verify` (new
this phase), `wa_app`, `wa_verify`, `google_sa` (per brand), `landing_token`.

## Structure
- Directory: `SE_SECRET_DIR` (an untracked app-config constant) else
  `/home/hyundaic/_secrets`. Mode **700**, outside the document root.
- Files: one per provider/brand, mode **600**, plain value.
- **No setter and no UI.** Nothing in the application writes a secret; the owner
  places files by hand.
- Not in Git; a repository restore does not restore secrets (back them up separately).

## Current state
- The directory does **not exist yet** — the dashboard shows a warning to that
  effect, and every integration stays gated until it is created and populated.
- `tbloptions` holds **no** integration secret. Enforcement was migrated off the
  legacy option reads this phase (webhook signature secrets, verify tokens, page
  tokens); the old option rows are preserved but never read.
- Health/credentials screens display only booleans, mode, expiry and sanitized
  errors — never a value, and there is no copy control.

## Activation (owner)
Create the directory (700), drop each secret file (600), and the matching
integration un-gates with **no code change**. The status UI and enforcement read
the same files, so "configured" on the screen means "used" by the receiver.
