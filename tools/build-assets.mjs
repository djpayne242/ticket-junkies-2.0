// Builds the WordPress plugin's front-end assets from the standalone site (ticketjunkies/public).
// Usage: openart-node ticketjunkies-wp/tools/build-assets.mjs
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const SRC = path.resolve(here, '../../ticketjunkies/public');
const OUT = path.resolve(here, '../ticketjunkies/assets');
fs.mkdirSync(path.join(OUT, 'img', 'og'), { recursive: true });

// ---------- images ----------
for (const f of ['logo.png', 'favicon.png', 'tj-icon.png', 'apple-touch-icon.png', 'icon-192.png', 'icon-512.png', 'icon-maskable-512.png', 'scanner-192.png', 'scanner-512.png', 'scanner-touch.png']) fs.copyFileSync(path.join(SRC, f), path.join(OUT, 'img', f));
// app worker (served by the plugin at /?tj_sw=1) and the QR reader used by the door scanner on iPhone
fs.copyFileSync(path.join(SRC, 'sw.js'), path.join(OUT, 'sw.js'));
fs.mkdirSync(path.join(OUT, 'vendor'), { recursive: true });
fs.copyFileSync(path.join(SRC, 'vendor', 'jsQR.js'), path.join(OUT, 'vendor', 'jsQR.js'));
for (const f of fs.readdirSync(path.join(SRC, 'og'))) fs.copyFileSync(path.join(SRC, 'og', f), path.join(OUT, 'img', 'og', f));
fs.mkdirSync(path.join(OUT, 'img', 'preloader'), { recursive: true });
for (const f of fs.readdirSync(path.join(SRC, 'preloader'))) fs.copyFileSync(path.join(SRC, 'preloader', f), path.join(OUT, 'img', 'preloader', f));

// ---------- helpers ----------
function patch(src, pairs, file) {
  for (const [from, to, opts = {}] of pairs) {
    if (from instanceof RegExp) {
      const n = (src.match(from) || []).length;
      if (!n && !opts.optional) throw new Error(`${file}: pattern not found: ${from}`);
      src = src.replace(from, to);
    } else {
      if (!src.includes(from)) { if (opts.optional) continue; throw new Error(`${file}: text not found: ${from.slice(0, 90)}`); }
      src = opts.all ? src.split(from).join(to) : src.replace(from, to);
    }
  }
  return src;
}

// ---------- maps.js ----------
let maps = fs.readFileSync(path.join(SRC, 'maps.js'), 'utf8');
maps = patch(maps, [
  ['fetch(`/api/weather?', 'fetch(`${(window.TJ_CONFIG || {}).rest || \'/wp-json/tj/v1/\'}weather?'],
], 'maps.js');
fs.writeFileSync(path.join(OUT, 'tj-maps.js'), maps);

// ---------- app.js ----------
let app = fs.readFileSync(path.join(SRC, 'app.js'), 'utf8');
const prelude = fs.readFileSync(path.join(here, 'wp-prelude.js'), 'utf8');
const appendix = fs.readFileSync(path.join(here, 'wp-widgets.js'), 'utf8');
app = patch(app, [
  ["const preAsset = (f) => '/' + f;", "const preAsset = (f) => TJ.assets + 'img/' + f;"],
  // app (install + offline): WordPress serves the worker and manifests from the site address
  ["const APP_URLS = { sw: '/sw.js', swScope: '/', manifest: '/manifest.webmanifest', scannerManifest: '/scanner.webmanifest', jsqr: '/vendor/jsQR.js?v=1.4.0', appleIcon: '/apple-touch-icon.png', scannerIcon: '/scanner-touch.png', icon: '/icon-192.png', scannerIconBig: '/scanner-192.png' };",
   "const TJ_HOME_PATH = (() => { try { return new URL(TJ.home, location.href).pathname; } catch { return '/'; } })();\nconst APP_URLS = { sw: TJ_HOME_PATH + '?tj_sw=1', swScope: TJ_HOME_PATH, manifest: TJ_HOME_PATH + '?tj_manifest=main', scannerManifest: TJ_HOME_PATH + '?tj_manifest=scanner', jsqr: TJ.assets + 'vendor/jsQR.js?v=1.4.0', appleIcon: TJ.assets + 'img/apple-touch-icon.png', scannerIcon: TJ.assets + 'img/scanner-touch.png', icon: TJ.assets + 'img/icon-192.png', scannerIconBig: TJ.assets + 'img/scanner-192.png' };"],
  ["const $app = document.getElementById('app');\nconst $nav = document.getElementById('nav');",
   "let $app = document.createElement('div');\nlet $nav = document.getElementById('nav') || document.createElement('nav');"],
  // REST base + WordPress nonce
  [`async function api(url, { method = 'GET', body } = {}) {
  const res = await fetch(url, {
    method, credentials: 'same-origin',
    headers: body ? { 'Content-Type': 'application/json' } : {},
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) { const e = new Error(data.error || 'Request failed'); e.status = res.status; throw e; }
  return data;
}`, `async function api(url, { method = 'GET', body } = {}) {
  const headers = { 'X-WP-Nonce': TJ.nonce };
  if (body) headers['Content-Type'] = 'application/json';
  const res = await fetch(tjApi(url), { method, credentials: 'same-origin', headers, body: body ? JSON.stringify(body) : undefined });
  const data = await res.json().catch(() => ({}));
  if (data && typeof data.nonce === 'string' && data.nonce) TJ.nonce = data.nonce;
  if (!res.ok) { const e = new Error(data.error || data.message || 'Request failed'); e.status = res.status; throw e; }
  return data;
}`],
  ["const t = document.getElementById('toast');", "const t = tjFloat('toast');"],
  ['const go = (hash) => { location.hash = hash; };', 'const go = (hash) => tjGo(hash);'],
  ['const shareUrl = (ev) => `${location.origin}/e/${ev.id}`;', 'const shareUrl = (ev) => `${TJ.home}e/${ev.id}`;'],
  ["  const h = location.hash || '#/';\n  const link = (href, label)", "  if (!document.getElementById('nav')) return;\n  const h = tjCurrentRoute();\n  const link = (href, label)"],
  ["document.getElementById('menuBtn').onclick = () => $nav.classList.toggle('open');",
   "document.addEventListener('click', (e) => { if (e.target.closest('#menuBtn')) $nav.classList.toggle('open'); });"],
  ["const raw = (location.hash || '#/').slice(1);", 'const raw = tjCurrentRoute().slice(1);'],
  [/encodeURIComponent\(location\.hash\)/g, 'encodeURIComponent(tjCurrentRoute())'],
  // router: only runs inside a TicketJunkies "screen" widget, and sends routes that belong elsewhere to the right page
  ['async function route() {\n', 'async function route() {\n  if (!TJ_SCREEN) return;\n  $app = TJ_SCREEN.el;\n  if (!tjScreenAllows(tjCurrentRoute())) { location.href = tjRouteUrl(tjCurrentRoute()); return; }\n'],
  ['  $nav.classList.remove(\'open\');\n  renderNav();\n  window.scrollTo(0, 0);', "  $nav.classList.remove('open');\n  renderNav();\n  if (TJ_SCREEN.routed) TJ_SCREEN.el.scrollIntoView({ block: 'start' }); TJ_SCREEN.routed = true;"],
  // URLs that pointed at the standalone server
  [/(src|href)="\/qr\//g, '$1="${TJ.rest}qr/'],
  ['href="/api/orders/${esc(o.id)}/tickets.pdf"', 'href="${TJ.rest}orders/${esc(o.id)}/tickets.pdf?_wpnonce=${TJ.nonce}"'],
  ['href="/api/orders/${esc(order.id)}/tickets.pdf"', 'href="${TJ.rest}orders/${esc(order.id)}/tickets.pdf?_wpnonce=${TJ.nonce}"'],
  ['href="/api/tickets/${esc(order.id)}/pdf?k=', 'href="${TJ.rest}tickets/${esc(order.id)}/pdf?k='],
  ["const res = await fetch(`/api/${admin ? 'admin' : 'organizer'}/reports/export?${p}`, { credentials: 'same-origin' });",
   "const res = await fetch(`${TJ.rest}${admin ? 'admin' : 'organizer'}/reports/export?${p}`, { credentials: 'same-origin', headers: { 'X-WP-Nonce': TJ.nonce } });"],
  ['const localLink = location.origin + order.link;', 'const localLink = tjLink(order.link);'],
  [/esc\((o|order)\.link\)/g, 'esc(tjLink($1.link))'],
  // modals/toasts live in a body-level TicketJunkies container so they keep the plugin's styles
  [/document\.body\.appendChild\(wrap\)/g, "tjFloat().appendChild(wrap)"],
  [".observe(document.getElementById('app'), { childList: true, subtree: true });", '.observe(document.body, { childList: true, subtree: true });'],
  // boot
  [`(async function boot() {
  document.getElementById('year').textContent = new Date().getFullYear();
  try { META = await api('/api/meta'); } catch {}
  try { me = (await api('/api/auth/me')).user; } catch {}
  route();
  registerAppWorker();
  setTimeout(maybeAppBanner, 4000);
})();`, `(async function boot() {
  const y = document.getElementById('year'); if (y) y.textContent = new Date().getFullYear();
  try { META = await api('/api/meta'); } catch {}
  try { me = (await api('/api/auth/me')).user; } catch {}
  renderNav();
  tjMountAll(document);
  tjBooted = true;
  registerAppWorker();
  setTimeout(maybeAppBanner, 4000);
})();`],
], 'app.js');
// in-page "home" links of the stand-alone router become real homepage links
app = app.replace("'use strict';\n", "'use strict';\n" + prelude + '\n');
app += '\n' + appendix;
fs.writeFileSync(path.join(OUT, 'tj-app.js'), app);

// ---------- styles.css: scope every rule under .tj-root ----------
const css = fs.readFileSync(path.join(SRC, 'styles.css'), 'utf8');
function scopeSelector(sel) {
  sel = sel.trim();
  if (!sel) return sel;
  if (/^(from|to|\d+%)$/.test(sel)) return sel; // keyframe steps
  const m = /^((?:html|:root)(?:\[[^\]]*\]|:[\w-]+(?:\([^)]*\))?)*)(\s+|$)(.*)$/.exec(sel);
  if (m) {
    const rest = m[3].trim();
    if (!rest) return m[1];
    if (/^body\b/.test(rest)) return `${m[1]} .tj-root${rest.slice(4)}`;
    return `${m[1]} .tj-root ${rest}`;
  }
  if (/^body\b/.test(sel)) return '.tj-root' + sel.slice(4);
  if (/^main\.wrap\b/.test(sel)) return '.tj-root.tj-screen' + sel.slice(9);
  return '.tj-root ' + sel;
}
function splitTop(s, ch) { const out = []; let d = 0, cur = ''; for (const c of s) { if (c === '(' || c === '[') d++; if (c === ')' || c === ']') d--; if (c === ch && !d) { out.push(cur); cur = ''; } else cur += c; } out.push(cur); return out; }
function scopeBlock(text) {
  let i = 0, out = '';
  while (i < text.length) {
    // comments
    if (text.startsWith('/*', i)) { const j = text.indexOf('*/', i + 2); out += text.slice(i, j + 2); i = j + 2; continue; }
    if (/\s/.test(text[i])) { out += text[i++]; continue; }
    const brace = text.indexOf('{', i);
    const semi = text.indexOf(';', i);
    if (brace < 0) { out += text.slice(i); break; }
    if (semi >= 0 && semi < brace && text[i] === '@') { out += text.slice(i, semi + 1); i = semi + 1; continue; } // @import etc
    const head = text.slice(i, brace).trim();
    // find matching close brace
    let d = 0, j = brace;
    for (; j < text.length; j++) { if (text[j] === '{') d++; else if (text[j] === '}') { d--; if (!d) break; } }
    const body = text.slice(brace + 1, j);
    if (/^@(media|supports|container|layer)/.test(head)) out += `${head} {${scopeBlock(body)}}`;
    else if (/^@/.test(head)) out += `${head} {${body}}`;
    else out += splitTop(head, ',').map(scopeSelector).join(', ') + ` {${body}}`;
    i = j + 1;
  }
  return out;
}
const extra = fs.readFileSync(path.join(here, 'wp-extra.css'), 'utf8');
fs.writeFileSync(path.join(OUT, 'tj.css'), '/* TicketJunkies – generated from the stand-alone site styles; every rule is scoped to .tj-root */\n' + scopeBlock(css) + '\n' + extra);
console.log('assets built:', fs.readdirSync(OUT).join(', '));
