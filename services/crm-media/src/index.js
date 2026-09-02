/**
 * crm-media — R2 gateway for CRM conversation attachments.
 *
 *   PUT  /o/<key>              Authorization: Bearer <MEDIA_KEY>   body = bytes, Content-Type honoured
 *   HEAD /o/<key>              Authorization: Bearer <MEDIA_KEY>
 *   GET  /o/<key>              Authorization: Bearer <MEDIA_KEY>          (server-to-server)
 *   GET  /o/<key>?exp=&sig=    no auth: sig = hex(HMAC-SHA256(MEDIA_KEY, key + "|" + exp)), exp = unix seconds
 *   DELETE /o/<key>            Authorization: Bearer <MEDIA_KEY>   (erasure; idempotent, 204)
 *
 * Keys are confined to PREFIX ("crm/"). Nothing lists. Deletes need the bearer key.
 * The patient-journey module stores SEALED (libsodium) photos under crm/journey/…;
 * a signed GET of those returns ciphertext only.
 * Signed GETs are how staff browsers (via the CRM's authed redirect) and
 * Meta's Instagram fetcher receive files; the TTL is chosen by the CRM.
 */
export default {
  async fetch(req, env) {
    const url = new URL(req.url);
    if (!url.pathname.startsWith('/o/')) return json(404, { ok: false, reason: 'not_found' });
    const key = decodeURIComponent(url.pathname.slice(3));
    if (!key || key.includes('..') || !key.startsWith(env.PREFIX)) return json(404, { ok: false, reason: 'bad_key' });

    const bearer = (req.headers.get('Authorization') || '').replace(/^Bearer\s+/i, '');
    const authed = bearer && timingSafeEqual(bearer, env.MEDIA_KEY || '');

    if (req.method === 'PUT') {
      if (!authed) return json(401, { ok: false, reason: 'unauthorized' });
      const type = req.headers.get('Content-Type') || 'application/octet-stream';
      const obj = await env.MEDIA.put(key, req.body, { httpMetadata: { contentType: type } });
      return json(200, { ok: true, key, size: obj.size, etag: obj.etag });
    }

    if (req.method === 'DELETE') {
      if (!authed) return json(401, { ok: false, reason: 'unauthorized' });
      await env.MEDIA.delete(key);
      return new Response(null, { status: 204 });
    }

    if (req.method === 'HEAD' || req.method === 'GET') {
      if (!authed) {
        const exp = parseInt(url.searchParams.get('exp') || '0', 10);
        const sig = url.searchParams.get('sig') || '';
        if (!exp || exp < Math.floor(Date.now() / 1000)) return json(404, { ok: false, reason: 'expired' });
        const want = await hmacHex(env.MEDIA_KEY || '', key + '|' + exp);
        if (!timingSafeEqual(sig, want)) return json(404, { ok: false, reason: 'bad_sig' });
      }
      const range = req.headers.get('Range');
      const obj = await env.MEDIA.get(key, range ? { range: req.headers } : undefined);
      if (!obj) return json(404, { ok: false, reason: 'missing' });
      const h = new Headers();
      obj.writeHttpMetadata(h);
      h.set('etag', obj.httpEtag);
      h.set('Accept-Ranges', 'bytes');
      h.set('X-Content-Type-Options', 'nosniff');
      h.set('Content-Security-Policy', 'sandbox');
      h.set('Cache-Control', 'private, max-age=300');
      if (range && obj.range) {
        h.set('Content-Range', `bytes ${obj.range.offset}-${obj.range.offset + obj.range.length - 1}/${obj.size}`);
        return new Response(req.method === 'HEAD' ? null : obj.body, { status: 206, headers: h });
      }
      h.set('Content-Length', String(obj.size));
      return new Response(req.method === 'HEAD' ? null : obj.body, { status: 200, headers: h });
    }

    return json(405, { ok: false, reason: 'method' });
  },
};

function json(status, body) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

async function hmacHex(secret, msg) {
  const k = await crypto.subtle.importKey('raw', new TextEncoder().encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const s = await crypto.subtle.sign('HMAC', k, new TextEncoder().encode(msg));
  return [...new Uint8Array(s)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

function timingSafeEqual(a, b) {
  if (typeof a !== 'string' || typeof b !== 'string' || a.length !== b.length) return false;
  let r = 0;
  for (let i = 0; i < a.length; i++) r |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return r === 0;
}
