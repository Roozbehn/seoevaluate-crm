# crm-dispatch

Cloudflare Worker cron trigger (`* * * * *`) that calls the CRM's fast
messaging dispatcher `GET /se_core/dispatch/index/<APP_CRON_KEY>`.

Why: the cPanel host's cron-frequency-monitor rewrites any crontab line
tighter than 15 minutes, so a per-minute schedule cannot live on the host.

Deploy (from this directory, Node 22):

    npx wrangler secret put CRM_CRON_KEY   # paste the Perfex APP_CRON_KEY, never commit it
    npx wrangler deploy

Logs: `npx wrangler tail crm-dispatch`. The Worker has no useful HTTP surface.
