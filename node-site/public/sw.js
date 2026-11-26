/* TicketJunkies app worker: makes the site installable and keeps tickets available offline.
   - Pages: always fresh from the internet; the last copy is used only when there is no connection.
   - Your tickets, QR codes and account info: same (fresh first, saved copy offline).
   - Pictures, styles and scripts: shown instantly from the saved copy and refreshed in the background.
   - Payments, check-ins, admin and anything that changes data always go straight to the server. */
const VERSION = '1.3.0';
const STATIC = 'tj-static-' + VERSION, PAGES = 'tj-pages', DATA = 'tj-data';
const SCOPE = new URL(self.registration.scope);
const OFFLINE_HTML = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline – TicketJunkies</title>
<style>body{font-family:system-ui,-apple-system,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#f6f7fb;color:#1b1f2a;text-align:center;padding:20px}a{display:inline-block;margin-top:14px;background:#eb742a;color:#fff;padding:12px 22px;border-radius:12px;text-decoration:none;font-weight:700}@media(prefers-color-scheme:dark){body{background:#12161f;color:#e8ebf2}}</style></head>
<body><div><div style="font-size:3rem">📶</div><h1>You're offline</h1><p>This page hasn't been saved on this phone yet.<br>Tickets you've opened before still work.</p><a href="${SCOPE.pathname}">Try again</a></div></body></html>`;

self.addEventListener('install', (e) => {
  self.skipWaiting();
  e.waitUntil(caches.open(PAGES).then((c) => c.add(new Request(SCOPE.href, { credentials: 'same-origin' }))).catch(() => {}));
});
self.addEventListener('activate', (e) => {
  e.waitUntil((async () => {
    for (const k of await caches.keys()) if (k.startsWith('tj-static-') && k !== STATIC) await caches.delete(k);
    await self.clients.claim();
  })());
});
self.addEventListener('message', (e) => {
  if (e.data === 'tj-clear') e.waitUntil(Promise.all([caches.delete(PAGES), caches.delete(DATA)]));
});

// The data routes that are safe and useful to keep for offline use (standalone site: /api/..., WordPress: /wp-json/tj/v1/...)
const routeOf = (url) => url.searchParams.get('rest_route') || url.pathname;
const DATA_RE = /\/(?:api|tj\/v1)\/(?:orders(?:\/[\w-]+)?|auth\/me|meta|tickets\/[\w-]+)$/;
const QR_RE = /\/(?:tj\/v1\/)?qr\/TJ-[0-9A-F]{8}\.(?:svg|png)$/i;
const NEVER = /\/wp-admin|\/wp-login\.php|admin-ajax\.php|\/wp-cron\.php|preview=|elementor|customize_changeset|[?&]tj_sw=|\/sw\.js$/;

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  const same = url.origin === self.location.origin;
  if (same && (NEVER.test(url.pathname + url.search) || !url.pathname.startsWith(SCOPE.pathname))) return;
  if (req.headers.get('range')) return;
  const route = same ? routeOf(url) : '';
  if (same && (DATA_RE.test(route) || QR_RE.test(route))) return e.respondWith(freshFirst(req, DATA, true));
  if (same && /\/(?:api\/|wp-json\/)/.test(url.pathname + '/') ) return; // everything else from the server, never saved
  if (same && url.searchParams.has('rest_route')) return;
  if (req.mode === 'navigate') return e.respondWith(page(req));
  const dest = req.destination;
  const fonts = /^https:\/\/fonts\.(googleapis|gstatic)\.com$/.test(url.origin);
  if ((same || fonts) && ['style', 'script', 'image', 'font', 'manifest'].includes(dest)) return e.respondWith(savedFirst(req, e));
});

async function freshFirst(req, cacheName, json) {
  const cache = await caches.open(cacheName);
  try {
    const res = await fetch(req);
    if (res.ok && res.type === 'basic') cache.put(req, res.clone()).catch(() => {});
    return res;
  } catch (err) {
    const hit = await cache.match(req, { ignoreVary: true });
    if (hit) return hit;
    if (json) return new Response(JSON.stringify({ error: "You're offline — connect to the internet and try again" }), { status: 503, headers: { 'Content-Type': 'application/json' } });
    throw err;
  }
}
async function page(req) {
  const cache = await caches.open(PAGES);
  try {
    const res = await fetch(req);
    const type = res.headers.get('content-type') || '';
    if (res.ok && res.type === 'basic' && type.includes('text/html')) { cache.put(req, res.clone()).then(() => trim(PAGES, 40)).catch(() => {}); }
    return res;
  } catch {
    return (await cache.match(req, { ignoreSearch: false, ignoreVary: true }))
      || (await cache.match(req, { ignoreSearch: true, ignoreVary: true }))
      || (await cache.match(SCOPE.href, { ignoreVary: true }))
      || new Response(OFFLINE_HTML, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
  }
}
async function savedFirst(req, e) {
  const cache = await caches.open(STATIC);
  const hit = await cache.match(req);
  const net = fetch(req).then((res) => {
    if (res.ok || res.type === 'opaque') cache.put(req, res.clone()).then(() => trim(STATIC, 250)).catch(() => {});
    return res;
  }).catch(() => null);
  if (hit) { e.waitUntil(net); return hit; }
  return (await net) || Response.error();
}
async function trim(name, max) {
  const c = await caches.open(name);
  const keys = await c.keys();
  for (let i = 0; i < keys.length - max; i++) await c.delete(keys[i]);
}
