# crm-media

R2 gateway Worker for CRM conversation attachments (bucket `azin-media`, prefix `crm/`).
The CRM (cPanel host) has no R2 credentials; it talks to this Worker with a shared
HMAC key (`MEDIA_KEY` here = secret provider `r2_media_key` on the CRM host).

    npx wrangler secret put MEDIA_KEY
    npx wrangler deploy

API: `PUT/GET/HEAD/DELETE /o/crm/...` with `Authorization: Bearer`, or `GET /o/crm/...?exp=&sig=`.
`DELETE` (added with the patient journey) is what erasure and the optional purge-after-seal use;
a Worker deployed before it answers 405 and the CRM treats that as "unsupported" and keeps the object.
The journey module keeps its sealed photos under `crm/journey/<brand>/<journey>/<random>.enc`.
