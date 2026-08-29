# DNS, TLS & Cutover Runbook (PREPARED — NOT EXECUTED)

> **Optional future infrastructure migration — NOT required for the current cPanel deployment.**
> The CRM stays on cPanel + PHP 8.1.34 for the initial 2–3 users. Keep this only as a future reference.


**No DNS, certificate, Cloudflare, host, or routing change has been made.** This is a plan only. Execute
only with owner approval at the cutover gate.

## Current topology (observed)
- Public `crm.roozbeh.com.tr` is fronted by **Cloudflare**; the **origin IP is `57.129.84.98`** (Apache/LiteSpeed
  on the shared host). Staging is reached from the box via `curl --resolve crm.roozbeh.com.tr:443:57.129.84.98`.

## Pre-cutover
1. **Reduce DNS TTL** on `crm.roozbeh.com.tr` (and any webhook host) to 300s at least 24h before cutover.
2. Record current DNS values (A/AAAA/CNAME, proxied state) as the **rollback set**.
3. Provision the production origin; issue/verify its TLS certificate (Let's Encrypt or Cloudflare origin cert).

## TLS / cookies / proxy
- Full-strict TLS between Cloudflare and origin (valid origin cert). HTTPS redirect for all HTTP.
- Secure cookies in production: set `APP_COOKIE_SECURE=true`, `APP_COOKIE_HTTPONLY=true`; trust proxy headers
  (`X-Forwarded-Proto`) so the app sees HTTPS behind Cloudflare.
- **HSTS**: enable only AFTER verifying HTTPS end-to-end (start with a short max-age, then increase).

## Webhook DNS/TLS requirements
- Meta (WhatsApp `/se_whatsapp/webhook`, Lead Ads `/se_core/leadgen`) require a **publicly resolvable, valid-TLS**
  callback. Verify the origin cert is trusted publicly (not just via `--resolve`). Enabling the public POST route
  also needs the narrow `csrf_exclude_uris` entry (owner-gated) + Meta subscription (owner-gated).

## Cutover
1. Confirm production health (login 200, health page, cron 200) via `--resolve` to the new origin BEFORE DNS.
2. Point DNS to the new origin (or update Cloudflare origin). 3. Watch propagation; verify HTTPS + login.
4. Verify webhooks resolve to the new origin (GET verify echoes the challenge).

## Rollback
- Restore the recorded DNS values (rollback set). With low TTL, propagation is minutes.
- Re-gate integrations if any external calls were enabled.

## Post-cutover monitoring (first 24–48h)
- Login availability, cron freshness, error log, outbox pending/failed, webhook GET-verify reachability,
  certificate validity/expiry.
