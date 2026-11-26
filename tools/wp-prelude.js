// ---------- WordPress adapter (TicketJunkies plugin) ----------
// TJ_CONFIG is printed by the plugin: REST base, nonce, page URLs, asset URL.
const TJ = Object.assign({ rest: '/wp-json/tj/v1/', nonce: '', home: '/', assets: '', pages: {}, editor: false }, window.TJ_CONFIG || {});
let tjBooted = false;
// Which WordPress page shows each part of the app. Keys of TJ.pages: home, event, checkout, tickets, organizer, admin, login, register, ticket
const TJ_ROUTE_PAGE = { '': 'home', event: 'event', checkout: 'checkout', cart: 'checkout', order: 'checkout', tickets: 'tickets', dashboard: 'organizer', create: 'organizer', edit: 'organizer', attendees: 'organizer', reports: 'organizer', admin: 'admin', login: 'login', register: 'register', t: 'ticket', scan: 'scanner', app: 'tickets' };
const tjSeg = (hash) => String(hash || '').replace(/^#?\/?/, '').split(/[/?]/)[0];
const tjPageOf = (hash) => {
  const key = Object.prototype.hasOwnProperty.call(TJ_ROUTE_PAGE, tjSeg(hash)) ? TJ_ROUTE_PAGE[tjSeg(hash)] : 'home';
  // The scanner opens on the Scanner page (editable in Elementor). Sites without that page scan inside the Organizer Dashboard.
  if (key === 'scanner') return (TJ_SCREEN && TJ_SCREEN.key === 'scanner') || TJ.pages.scanner ? 'scanner' : 'organizer';
  return key;
};
function tjApi(url) { return /^https?:/.test(url) ? url : TJ.rest + String(url).replace(/^\/?api\//, ''); }
// '#/event/evt_1' -> https://site/event/#/event/evt_1   ·   '#/?q=x' -> https://site/?q=x
function tjRouteUrl(hash) {
  hash = '#/' + String(hash || '').replace(/^#?\/?/, '');
  const key = tjPageOf(hash);
  const base = TJ.pages[key] || TJ.home;
  if (key === 'home') { const qs = hash.split('?')[1]; return base + (qs ? (base.includes('?') ? '&' : '?') + qs : ''); }
  return base + hash;
}
const tjLink = (l) => (/^https?:/.test(String(l)) ? l : tjRouteUrl(String(l || '').replace(/^\/?/, '')));
// The routed "screen" widget on this page (event page, checkout, dashboards …)
let TJ_SCREEN = null;
const TJ_SCREEN_DEFAULT = {
  home: () => '#/',
  event: () => { const id = new URLSearchParams(location.search).get('id'); return id ? '#/event/' + id : (TJ.sampleEvent ? '#/event/' + TJ.sampleEvent : '#/event/'); },
  checkout: () => { try { const c = JSON.parse(sessionStorage.getItem('tj_cart') || 'null'); if (c && c.id) return '#/checkout/' + c.id; } catch {} return TJ.sampleEvent ? '#/checkout/' + TJ.sampleEvent : '#/checkout/'; },
  tickets: () => '#/tickets', organizer: () => '#/dashboard', scanner: () => '#/scan', admin: () => '#/admin', login: () => '#/login', register: () => '#/register', ticket: () => '#/t',
};
function tjScreenAllows(hash) { return !!TJ_SCREEN && tjPageOf(hash) === TJ_SCREEN.key; }
function tjCurrentRoute() {
  if (TJ_SCREEN && location.hash && location.hash.length > 2 && tjScreenAllows(location.hash)) return location.hash;
  if (TJ_SCREEN && location.hash && location.hash.length > 2) return location.hash; // belongs to another page: route() forwards it
  return TJ_SCREEN ? TJ_SCREEN_DEFAULT[TJ_SCREEN.key]() : '#/' + location.search;
}
function tjGo(hash) {
  hash = '#/' + String(hash || '').replace(/^#?\/?/, '');
  if (tjScreenAllows(hash)) { if (location.hash === hash) route(); else location.hash = hash; return; }
  location.href = tjRouteUrl(hash);
}
// Links written as "#/..." are rewritten to the right page (so "open in new tab" works too)
function tjFixLinks(root) {
  (root.querySelectorAll ? root.querySelectorAll('a[href^="#/"]') : []).forEach((a) => {
    const h = a.getAttribute('href');
    if (!tjScreenAllows(h)) a.setAttribute('href', tjRouteUrl(h));
  });
}
new MutationObserver((muts) => { for (const m of muts) m.addedNodes.forEach((n) => { if (n.nodeType === 1) { if (n.matches && n.matches('a[href^="#/"]')) tjFixLinks(n.parentNode || n); else tjFixLinks(n); } }); })
  .observe(document.documentElement, { childList: true, subtree: true });
// Body-level container for toasts and pop-ups (keeps them inside the plugin's styles)
function tjFloat(id) {
  let f = document.getElementById('tj-float');
  if (!f) { f = document.createElement('div'); f.id = 'tj-float'; f.className = 'tj-root tj-float'; f.innerHTML = '<div id="toast" class="toast" hidden></div>'; document.body.appendChild(f); }
  return id ? document.getElementById(id) : f;
}
