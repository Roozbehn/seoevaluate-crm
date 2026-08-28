<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * WhatsApp Cloud API — SCAFFOLD.
 *
 * Status: the single largest module, GATED on Meta Tech Provider verification +
 * Embedded Signup. Building the full inbox (4 tables, media handling, template
 * management, tenant-routing webhook receiver, UI) is a phase of its own and is
 * not attempted as untested code here. This file records the schema and the
 * webhook receiver contract so the work has a spine to grow from.
 *
 * Critical design facts already established (see the build plan §08):
 *   - one callback URL for ALL tenants; route on entry[].id (WABA id) -> brand
 *   - signature-verify X-Hub-Signature-256 over the raw body; respond <250ms
 *   - deduplicate on wamid (duplicates guaranteed), order by timestamp
 *   - download inbound media immediately (webhook media id expires 7d, URL 5min)
 *   - capture the referral object (ctwa_clid) on the FIRST inbound only
 *   - drive "window open?" from conversation.expiration_timestamp
 *   - from 1 Oct 2026, service replies inside the 24h window are billable
 *
 * TODO(Tech Provider): Embedded Signup onboarding; per-brand business
 * integration system-user token in options; register number with 2FA pin;
 * subscribe app to WABA; then build receiver + inbox UI.
 */

function se_whatsapp_scaffold_note()
{
    return 'WhatsApp module gated on Tech Provider verification';
}
