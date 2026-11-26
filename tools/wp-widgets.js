
// ---------- Elementor / shortcode widgets ----------
// Each widget prints <div class="tj-root tj-widget" data-tj-widget="..." data-tj-opts='{...}'></div>; this mounts them.
const tjHomeQuery = () => searchValues(new URLSearchParams(location.search));
const tjEventsCache = {};
function tjEvents(params) {
  const p = searchParams(searchValues(params));
  const k = p.toString();
  return (tjEventsCache[k] ||= api('/api/events?' + p).then((r) => r.events));
}
const tjOpts = (el) => { try { return JSON.parse(el.getAttribute('data-tj-opts') || '{}'); } catch { return {}; } };
const tjHomeUrl = (params) => { const s = searchParams(searchValues(params)).toString(); return (TJ.pages.home || TJ.home) + (s ? '?' + s : ''); };
const tjGoSearch = (p) => { const s = p.toString(); location.href = (TJ.pages.home || TJ.home) + (s ? '?' + s : ''); };

const TJ_WIDGETS = {
  // Full routed screens (event page, checkout, dashboards, admin …)
  screen(el, o) {
    if (TJ_SCREEN) { el.innerHTML = '<div class="notice">Only one TicketJunkies screen can be used per page.</div>'; return; }
    const key = o.screen || 'home';
    el.classList.add('tj-screen');
    TJ_SCREEN = { el, key, routed: false };
    window.addEventListener('hashchange', route);
    route();
  },
  search(el, o) {
    const v = tjHomeQuery();
    el.innerHTML = searchFormHtml(v, o);
    wireSearchForm(el.querySelector('form'), v, tjGoSearch);
  },
  chips(el, o) {
    const sv = tjHomeQuery(); const { category } = sv;
    el.innerHTML = `<div class="chips">${['', ...META.categories].map((c) => `<a class="chip ${c === category ? 'active' : ''}" href="${esc(tjHomeUrl({ ...sv, category: c }))}">${c ? esc(c) : esc(o.allLabel || 'All')}</a>`).join('')}</div>`;
  },
  async map(el, o) {
    const query = o.category ? { q: '', category: o.category } : tjHomeQuery();
    const filtered = searchActive(searchValues(query));
    el.innerHTML = `<div class="area-host"></div><section class="map-section">
      ${o.showTitle === false ? '' : `<div class="section-head"><h2>${esc(filtered ? (o.titleFiltered || '🗺️ These events on the map') : (o.title || '🗺️ Live events on the map'))}</h2><span class="muted small tj-map-count">Tap a pin to see the event</span></div>`}
      <div class="events-map" style="${o.height ? `height:${Number(o.height)}px` : ''}"><div class="loading">Loading map…</div></div></section>`;
    const mapEl = el.querySelector('.events-map');
    try {
      const sv = searchValues(query);
      const [all, area] = await Promise.all([tjEvents(query), TJArea.current()]);
      const { events, searched } = await TJArea.filter(all, sv);
      TJArea.fillBar({ ...area, searched });
      const where = area.place && area.scope !== 'all' && !searched ? ' in ' + TJArea.label(area.scope, area.place) : '';
      const pinned = events.filter((e) => Number.isFinite(e.lat));
      const c = el.querySelector('.tj-map-count');
      if (c) c.textContent = pinned.length ? `${pinned.length} event${pinned.length === 1 ? '' : 's'}${where} · tap a pin to see the event` : `No mapped events${where} yet`;
      await TJGeo.eventsMap(mapEl, events, { googleKey: META.maps?.googleMapsKey, center: await TJArea.center() });
    } catch { mapEl.innerHTML = '<div class="empty" style="box-shadow:none">Map could not be loaded right now.</div>'; }
  },
  async grid(el, o) {
    const query = o.category ? { q: '', category: o.category } : tjHomeQuery();
    const filtered = !o.category && searchActive(searchValues(query));
    el.innerHTML = `<div class="area-host"></div>${o.showTitle === false ? '' : `<div class="section-head"><h2>${esc(filtered ? (o.titleFiltered || 'Search results') : (o.title || 'Upcoming events'))}</h2>${o.showCount === false ? '' : '<span class="muted small tj-count"></span>'}</div>`}
      <div class="grid"${o.columns ? ` style="grid-template-columns:repeat(${Number(o.columns)},minmax(0,1fr))"` : ''}><div class="loading">Loading events…</div></div>`;
    try {
      const sv = searchValues(query);
      const [all, area] = await Promise.all([tjEvents(query), TJArea.current()]);
      let { events, searched } = await TJArea.filter(all, sv);
      TJArea.fillBar({ ...area, searched });
      const where = area.place && area.scope !== 'all' && !searched ? ' in ' + TJArea.label(area.scope, area.place) : '';
      if (o.limit) events = events.slice(0, Number(o.limit));
      const c = el.querySelector('.tj-count'); if (c) c.textContent = `${events.length} event${events.length === 1 ? '' : 's'}${where}`;
      el.querySelector('.grid').innerHTML = events.length ? events.map(card).join('')
        : all.length && where ? TJArea.emptyHtml(area)
        : `<div class="empty" style="grid-column:1/-1"><div class="big">🔍</div><h3>${esc(o.emptyTitle || 'No events found')}</h3><p class="muted">${esc(o.emptyText || 'Try another search or category.')}</p></div>`;
    } catch (e) { el.querySelector('.grid').innerHTML = `<div class="error">${esc(e.message)}</div>`; }
  },
  weather(el, o) {
    el.innerHTML = infoBarHtml(o);
    // A fixed place set in the widget wins; otherwise the weather follows the visitor's location
    const fixed = o.place && o.lat && o.lng ? { name: o.place, lat: Number(o.lat), lng: Number(o.lng) } : null;
    if (o.showWeather !== false) (fixed ? Promise.resolve(fixed) : TJArea.weatherPlace()).then((pl) => { const w = el.querySelector('#homeWx'); if (pl && w) TJGeo.homeWeather(w, pl); });
    if (o.showClock !== false) startHomeClock();
  },
  // Slim clock & weather bar above the orange & blue "Get your ticket fix." box (search inside)
  hero(el, o) {
    const sv = tjHomeQuery();
    const bar = infoBarHtml(o);
    const cta = o.showCta === false ? '' : (me?.role === 'organizer'
      ? '<a class="btn btn-green" href="#/create">+ Create an event</a>'
      : `<span>${esc(o.ctaText || 'Hosting an event?')}</span><a class="link" href="#/register?role=organizer">${esc(o.ctaLink || 'Become an organizer →')}</a>`);
    el.innerHTML = `${bar}<section class="hero tj-hero no-side">
      <h1 class="display">${esc(o.title || 'Get your ticket fix.')}</h1>
      ${o.subtitle === '' ? '' : `<p>${esc(o.subtitle || 'Discover the hottest parties, concerts, comedy shows and festivals — or launch your own event and start selling tickets in minutes.')}</p>`}
      ${searchFormHtml(sv, o)}
      ${cta ? `<div class="hero-cta">${cta}</div>` : ''}
    </section>`;
    wireSearchForm(el.querySelector('form.search-adv'), sv, tjGoSearch);
    if (o.showWeather !== false) TJArea.weatherPlace().then((pl) => { const w = el.querySelector('#homeWx'); if (pl && w) TJGeo.homeWeather(w, pl); });
    if (o.showClock !== false) startHomeClock();
  },
  // Month calendar with event days highlighted
  calendar(el, o) {
    el.innerHTML = calendarHtml(o);
    mountCalendar(el.querySelector('.cal-section'));
  },
  cta(el, o) {
    if (me?.role === 'organizer' && o.hideForOrganizers !== false) { el.innerHTML = '<a class="btn btn-green" href="#/create">+ Create an event</a>'; return; }
    el.innerHTML = `<div class="hero-cta"><span>${esc(o.text || 'Hosting an event?')}</span><a class="link" href="#/register?role=organizer">${esc(o.link || 'Become an organizer →')}</a></div>`;
  },
};

// A page with the separate search + weather blocks but no hero box gets the
// orange & blue "Get your ticket fix." box back automatically (search, weather and
// clock move inside it). Turn off with: add_filter('tj_auto_hero', '__return_false');
function tjAutoHero(root) {
  if (!window.TJ_CONFIG || TJ_CONFIG.autoHero === false || !root.querySelectorAll) return;
  if (document.querySelector('[data-tj-widget="hero"], .tj-hero-box')) return;
  const search = root.querySelector('[data-tj-widget="search"]:not([data-tj-mounted])');
  if (!search) return;
  const weather = [...root.querySelectorAll('[data-tj-widget="weather"]:not([data-tj-mounted])')];
  const so = tjOpts(search);
  search.setAttribute('data-tj-widget', 'hero');
  search.setAttribute('data-tj-opts', JSON.stringify({ placeholder: so.placeholder, button: so.button, showWeather: weather.length ? undefined : false, showClock: weather.length ? undefined : false }));
  for (const w of weather) {
    w.dataset.tjMounted = '1'; w.style.display = 'none'; w.innerHTML = '';
    const br = w.nextSibling; if (br && br.nodeName === 'BR') br.remove();
  }
}

// Pages that have the events map but no calendar get the "Calendar of events" right under the map.
// Turn off with: add_filter('tj_auto_calendar', '__return_false');
function tjAutoCalendar(root) {
  if (!window.TJ_CONFIG || TJ_CONFIG.autoCalendar === false || !root.querySelectorAll) return;
  if (document.querySelector('[data-tj-widget="calendar"], .cal-section')) return;
  const map = root.querySelector('[data-tj-widget="map"]:not([data-tj-mounted])');
  if (!map) return;
  const cal = document.createElement('div');
  cal.className = 'tj-root tj-widget';
  cal.setAttribute('data-tj-widget', 'calendar');
  cal.setAttribute('data-tj-opts', '{}');
  map.parentNode.insertBefore(cal, map.nextSibling);
}

// The visitor picked another area (or shared their exact location): redraw the weather, map, events and calendar
function tjAreaChanged() {
  document.querySelectorAll('[data-tj-widget]').forEach((el) => {
    const t = el.dataset.tjWidget;
    if (!el.dataset.tjMounted) return;
    if (t === 'map' || t === 'grid' || t === 'calendar') { try { const r = TJ_WIDGETS[t](el, tjOpts(el)); if (r && r.catch) r.catch(() => {}); } catch {} }
  });
  const w = document.getElementById('homeWx');
  if (w) TJArea.weatherPlace().then((pl) => { if (pl) TJGeo.homeWeather(w, pl); });
  if (TJ_SCREEN && TJ_SCREEN.key === 'home' && !parseHash().parts[0]) route();
}

function tjMountAll(root) {
  tjAutoHero(root);
  tjAutoCalendar(root);
  const els = [...(root.querySelectorAll ? root.querySelectorAll('[data-tj-widget]') : [])];
  // screens first so links on the same page are recognised
  els.sort((a, b) => (a.dataset.tjWidget === 'screen' ? -1 : 0) - (b.dataset.tjWidget === 'screen' ? -1 : 0));
  for (const el of els) {
    if (el.dataset.tjMounted) continue;
    el.dataset.tjMounted = '1';
    const fn = TJ_WIDGETS[el.dataset.tjWidget];
    if (!fn) continue;
    try { const r = fn(el, tjOpts(el)); if (r && r.catch) r.catch((e) => { el.innerHTML = `<div class="error">${esc(e.message)}</div>`; }); }
    catch (e) { el.innerHTML = `<div class="error">${esc(e.message)}</div>`; }
  }
  tjFixLinks(document);
}
// Elementor editor: re-mount widgets when they are (re)rendered in the preview
window.addEventListener('elementor/frontend/init', () => {
  if (!window.elementorFrontend || !elementorFrontend.hooks) return;
  elementorFrontend.hooks.addAction('frontend/element_ready/global', ($scope) => {
    const node = $scope && $scope[0];
    if (!node || !tjBooted) return;
    node.querySelectorAll('[data-tj-widget]').forEach((el) => {
      if (el.dataset.tjWidget === 'screen' && TJ_SCREEN && TJ_SCREEN.el !== el) { TJ_SCREEN = null; }
      delete el.dataset.tjMounted;
    });
    tjMountAll(node);
  });
});

// "Forgot your password?" link under the log-in form (uses WordPress's own reset email)
(function () {
  const add = () => {
    const f = document.getElementById('loginForm');
    if (!f || f.dataset.tjLost || f.querySelector('.forgot-link') || !(window.TJ_CONFIG && TJ_CONFIG.lostPassword)) return;
    f.dataset.tjLost = '1';
    const p = document.createElement('p');
    p.className = 'muted small';
    p.style.cssText = 'text-align:center;margin:12px 0 0';
    p.innerHTML = '<a href="' + TJ_CONFIG.lostPassword + '">Forgot your password?</a>';
    f.appendChild(p);
  };
  new MutationObserver(add).observe(document.documentElement, { childList: true, subtree: true });
  document.addEventListener('DOMContentLoaded', add);
})();
