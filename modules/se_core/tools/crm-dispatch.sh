#!/bin/bash
# Fast messaging dispatcher — WhatsApp + Instagram queues ONLY (not the full
# Perfex cron). Install as a second cron line, every minute:
#   * * * * * /home/hyundaic/bin/crm-dispatch.sh >/dev/null 2>&1
# Same APP_CRON_KEY as crm-cron.sh; the route is GET-only and locked.
CFG=/home/hyundaic/crm.roozbeh.com.tr/application/config/app-config.php
ORIGIN=57.129.84.98
KEY=$(sed -n "s/.*APP_CRON_KEY', *'\([^']*\)'.*/\1/p" "$CFG")
[ -z "$KEY" ] && { echo "crm-dispatch: APP_CRON_KEY not found in $CFG" >&2; exit 1; }
curl -sS -m 55 -o /dev/null -w "%{http_code}\n" \
  --resolve "crm.roozbeh.com.tr:443:$ORIGIN" \
  "https://crm.roozbeh.com.tr/se_core/dispatch/$KEY"
