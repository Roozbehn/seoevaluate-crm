/**
 * crm-dispatch — runs the CRM's WhatsApp/Instagram dispatcher every minute.
 *
 * GET {DISPATCH_URL}{CRM_CRON_KEY}. The route only ingests pending WA/IG
 * webhook events and drains the two outbound queues (see
 * modules/se_core/se_dispatch.php); it is locked server-side, so an
 * overlapping call is a harmless no-op. Nothing is logged but the outcome.
 */
export default {
  async scheduled(event, env, ctx) {
    ctx.waitUntil(dispatch(env));
  },
  // No public surface: a plain HTTP hit reveals nothing and does nothing.
  async fetch() {
    return new Response('crm-dispatch: scheduled worker', { status: 404 });
  },
};

async function dispatch(env) {
  if (!env.CRM_CRON_KEY) {
    console.error('crm-dispatch: CRM_CRON_KEY secret is not set');
    return;
  }
  const url = env.DISPATCH_URL + encodeURIComponent(env.CRM_CRON_KEY);
  const started = Date.now();
  try {
    const res = await fetch(url, {
      method: 'GET',
      headers: { 'User-Agent': 'crm-dispatch-worker/1.0' },
      signal: AbortSignal.timeout(50_000),
    });
    let summary = '';
    try {
      const j = await res.json();
      summary = JSON.stringify({ ok: j.ok, locked: j.locked, ran: j.ran, errors: j.errors });
    } catch { summary = 'non-json body'; }
    console.log(`crm-dispatch: HTTP ${res.status} in ${Date.now() - started}ms ${summary}`);
  } catch (e) {
    console.error(`crm-dispatch: request failed after ${Date.now() - started}ms: ${e.name}`);
  }
}
