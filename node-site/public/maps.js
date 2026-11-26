// TicketJunkies — maps (Google Maps / OpenStreetMap) and weather widgets
'use strict';

const TJGeo = (() => {
  const escH = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const CAT = {
    'Music': ['#376eb8', '🎵'], 'Nightlife': ['#6d28d9', '🪩'], 'Comedy': ['#eb742a', '🎤'], 'Food & Drink': ['#c0392b', '🍹'],
    'Sports': ['#1d7a46', '🏆'], 'Arts': ['#9b2c6e', '🎨'], 'Business': ['#1b2a41', '💼'], 'Community': ['#3e7f22', '🥁'],
  };
  const catOf = (c) => CAT[c] || ['#eb742a', '🎟️'];

  // ---------- script loaders ----------
  const once = {};
  function loadLeaflet() {
    return (once.leaflet ||= new Promise((resolve, reject) => {
      const css = document.createElement('link');
      css.rel = 'stylesheet'; css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
      document.head.appendChild(css);
      const s = document.createElement('script');
      s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
      s.onload = () => resolve(window.L); s.onerror = () => reject(new Error('Map library failed to load'));
      document.head.appendChild(s);
    }));
  }
  function loadGoogle(key) {
    return (once.google ||= new Promise((resolve, reject) => {
      window.__tjGmapsReady = () => resolve(window.google.maps);
      window.gm_authFailure = () => { console.warn('Google Maps key rejected — falling back to OpenStreetMap'); };
      const s = document.createElement('script');
      s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&callback=__tjGmapsReady&loading=async`;
      s.async = true; s.onerror = () => reject(new Error('Google Maps failed to load'));
      document.head.appendChild(s);
    }));
  }

  // ---------- shared bits ----------
  const fmtWhen = (iso) => new Date(iso).toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  function priceLabel(ev) {
    const open = ev.tiers.filter((t) => t.remaining > 0);
    if (!open.length) return 'Sold out';
    const min = Math.min(...open.map((t) => t.price));
    return min === 0 ? 'Free' : 'From $' + min.toFixed(2);
  }
  const popupHtml = (ev) => `
    <a class="map-pop" href="#/event/${escH(ev.id)}">
      ${ev.imageUrl ? `<span class="map-pop-img" style="background-image:url(&quot;${escH(ev.imageUrl)}&quot;)"></span>` : `<span class="map-pop-img" style="background:${catOf(ev.category)[0]}"><b>${catOf(ev.category)[1]}</b></span>`}
      <span class="map-pop-body">
        <span class="map-pop-date">${escH(fmtWhen(ev.startsAt))}</span>
        <strong>${escH(ev.title)}</strong>
        <span class="map-pop-venue">📍 ${escH(ev.venue)}</span>
        <span class="map-pop-foot"><span>${escH(priceLabel(ev))}</span><span class="map-pop-cta">View event →</span></span>
      </span>
    </a>`;

  // Events at the exact same spot are fanned out slightly so every pin is clickable.
  function spread(events) {
    const groups = new Map();
    for (const e of events) {
      const k = `${e.lat.toFixed(5)},${e.lng.toFixed(5)}`;
      if (!groups.has(k)) groups.set(k, []);
      groups.get(k).push(e);
    }
    const out = [];
    for (const list of groups.values()) {
      list.forEach((e, i) => {
        if (list.length === 1) return out.push({ ev: e, lat: e.lat, lng: e.lng });
        const ang = (2 * Math.PI * i) / list.length, r = 0.00035 * (1 + Math.floor(i / 8));
        out.push({ ev: e, lat: e.lat + r * Math.sin(ang), lng: e.lng + r * Math.cos(ang) / Math.cos((e.lat * Math.PI) / 180) });
      });
    }
    return out;
  }

  // ---------- front-page map of all live events ----------
  async function eventsMap(el, events, { googleKey, center }) {
    const pts = spread(events.filter((e) => Number.isFinite(e.lat) && Number.isFinite(e.lng)));
    el.innerHTML = '';
    if (googleKey) {
      try { return await googleEventsMap(el, pts, center, googleKey); }
      catch (e) { console.warn(e); el.innerHTML = ''; }
    }
    const L = await loadLeaflet();
    const map = L.map(el, { scrollWheelZoom: false, zoomControl: true }).setView([center.lat, center.lng], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
    const markers = pts.map(({ ev, lat, lng }) => {
      const [color, icon] = catOf(ev.category);
      const m = L.marker([lat, lng], {
        title: ev.title, riseOnHover: true,
        icon: L.divIcon({ className: 'tj-pin-wrap', html: `<div class="tj-pin" style="--c:${color}"><span>${icon}</span></div>`, iconSize: [40, 48], iconAnchor: [20, 46], popupAnchor: [0, -42] }),
      }).addTo(map).bindPopup(popupHtml(ev), { minWidth: 260, maxWidth: 280, className: 'tj-popup' });
      return m;
    });
    if (markers.length) map.fitBounds(L.featureGroup(markers).getBounds().pad(0.25), { maxZoom: 15 });
    el.addEventListener('click', () => map.scrollWheelZoom.enable(), { once: true });
    setTimeout(() => map.invalidateSize(), 50);
    return map;
  }

  async function googleEventsMap(el, pts, center, key) {
    const gm = await loadGoogle(key);
    const map = new gm.Map(el, { center, zoom: 12, mapTypeControl: false, streetViewControl: false, fullscreenControl: true, gestureHandling: 'cooperative' });
    const info = new gm.InfoWindow();
    const bounds = new gm.LatLngBounds();
    for (const { ev, lat, lng } of pts) {
      const [color, icon] = catOf(ev.category);
      const marker = new gm.Marker({
        map, position: { lat, lng }, title: ev.title,
        icon: { path: gm.SymbolPath.CIRCLE, scale: 17, fillColor: color, fillOpacity: 1, strokeColor: '#ffffff', strokeWeight: 3 },
        label: { text: icon, fontSize: '16px' },
      });
      marker.addListener('click', () => { info.setContent(popupHtml(ev)); info.open({ map, anchor: marker }); });
      bounds.extend({ lat, lng });
    }
    if (pts.length > 1) map.fitBounds(bounds, 60);
    else if (pts.length === 1) { map.setCenter(bounds.getCenter()); map.setZoom(15); }
    return map;
  }

  // ---------- single event: Google close-up of the venue ----------
  function venueMap(el, ev) {
    const hasPin = Number.isFinite(ev.lat) && Number.isFinite(ev.lng);
    const placeQ = [ev.venue, ev.address, ev.city].filter(Boolean).join(', ');
    const q = hasPin ? `${ev.lat},${ev.lng}` : placeQ;
    const src = (type) => `https://maps.google.com/maps?q=${encodeURIComponent(q)}&z=${type === 'k' ? 18 : 17}&t=${type}&output=embed&hl=en`;
    const dest = hasPin ? `${ev.lat},${ev.lng}` : placeQ;
    const directions = `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(dest)}`;
    const open = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(hasPin ? `${ev.lat},${ev.lng}` : placeQ)}`;
    const street = hasPin ? `https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${ev.lat},${ev.lng}` : '';
    el.innerHTML = `
      <div class="venue-map-head">
        <div><strong>${escH(ev.venue)}</strong><div class="muted small">${escH([ev.address, ev.city].filter(Boolean).join(', '))}</div></div>
        <div class="seg"><button class="active" data-t="m">Map</button><button data-t="k">Satellite</button></div>
      </div>
      <div class="venue-map-frame"><iframe title="Map of ${escH(ev.venue)}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen src="${src('m')}"></iframe></div>
      ${hasPin && ev.geoPrecision === 'city' ? '<div class="muted small" style="margin-top:6px">📍 Approximate location (city area). Check the address above.</div>' : ''}
      <div class="venue-map-actions">
        <a class="btn btn-primary btn-sm" href="${directions}" target="_blank" rel="noopener">🧭 Get directions</a>
        ${street ? `<a class="btn btn-ghost btn-sm" href="${street}" target="_blank" rel="noopener">👀 Street View</a>` : ''}
        <a class="btn btn-ghost btn-sm" href="${open}" target="_blank" rel="noopener">Open in Google Maps ↗</a>
      </div>`;
    const frame = el.querySelector('iframe');
    el.querySelectorAll('.seg button').forEach((b) => (b.onclick = () => {
      el.querySelectorAll('.seg button').forEach((x) => x.classList.toggle('active', x === b));
      frame.src = src(b.dataset.t);
    }));
  }

  // ---------- event form: drag-the-pin location picker ----------
  async function pinPicker(el, { lat, lng, center, onChange }) {
    const L = await loadLeaflet();
    const start = Number.isFinite(lat) && Number.isFinite(lng) ? [lat, lng] : [center.lat, center.lng];
    const map = L.map(el, { scrollWheelZoom: false }).setView(start, Number.isFinite(lat) ? 17 : 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
    const icon = L.divIcon({ className: 'tj-pin-wrap', html: '<div class="tj-pin" style="--c:#eb742a"><span>📍</span></div>', iconSize: [40, 48], iconAnchor: [20, 46] });
    let marker = null;
    const place = (ll, source, zoom) => {
      if (!marker) {
        marker = L.marker(ll, { draggable: true, icon }).addTo(map);
        marker.on('dragend', () => { const p = marker.getLatLng(); onChange(p.lat, p.lng, 'pin'); });
      } else marker.setLatLng(ll);
      if (zoom) map.setView(ll, zoom);
      onChange(ll[0] ?? ll.lat, ll[1] ?? ll.lng, source);
    };
    if (Number.isFinite(lat) && Number.isFinite(lng)) { marker = L.marker(start, { draggable: true, icon }).addTo(map); marker.on('dragend', () => { const p = marker.getLatLng(); onChange(p.lat, p.lng, 'pin'); }); }
    map.on('click', (e) => place([e.latlng.lat, e.latlng.lng], 'pin'));
    el.addEventListener('click', () => map.scrollWheelZoom.enable(), { once: true });
    setTimeout(() => map.invalidateSize(), 60);
    return { setPoint: (la, ln, source) => place([la, ln], source, source === 'city' ? 13 : 17) };
  }

  // ---------- weather ----------
  const WX = (code, isDay = true) => {
    if (code === 0) return [isDay ? '☀️' : '🌙', 'Clear'];
    if (code === 1) return [isDay ? '🌤️' : '🌙', 'Mostly clear'];
    if (code === 2) return ['⛅', 'Partly cloudy'];
    if (code === 3) return ['☁️', 'Cloudy'];
    if (code === 45 || code === 48) return ['🌫️', 'Fog'];
    if (code >= 51 && code <= 57) return ['🌦️', 'Drizzle'];
    if (code >= 61 && code <= 67) return ['🌧️', code >= 65 ? 'Heavy rain' : 'Rain'];
    if (code >= 71 && code <= 77) return ['❄️', 'Snow'];
    if (code >= 80 && code <= 82) return ['🌦️', 'Rain showers'];
    if (code === 85 || code === 86) return ['🌨️', 'Snow showers'];
    if (code >= 95) return ['⛈️', 'Thunderstorms'];
    return ['🌡️', '—'];
  };
  const deg = (n) => `${Math.round(n)}°`;
  const inTz = (unix, tz, opts) => new Date(unix * 1000).toLocaleString(undefined, { timeZone: tz, ...opts });
  const getForecast = (lat, lng) => fetch(`/api/weather?lat=${lat}&lng=${lng}`).then((r) => r.ok ? r.json() : Promise.reject(new Error('Weather unavailable'))).then((d) => d.forecast);

  async function homeWeather(el, place) {
    try {
      const f = await getForecast(place.lat, place.lng);
      const [icon, label] = WX(f.current.code, f.current.isDay);
      const days = f.daily.slice(0, 5).map((d, i) => {
        const [di] = WX(d.code);
        return `<div class="wx-mini-day" title="${escH(WX(d.code)[1])}, ${d.rain ?? 0}% rain"><span>${i === 0 ? 'Today' : escH(inTz(d.time + 43200, f.timezone, { weekday: 'short' }))}</span><b>${di}</b><span>${deg(d.max)}<small> ${deg(d.min)}</small></span></div>`;
      }).join('');
      el.innerHTML = `
        <div class="wx-mini-now"><span class="wx-mini-icon">${icon}</span><div><div class="wx-mini-temp">${deg(f.current.temp)}F</div><div class="wx-mini-place">${escH(place.name.split(',')[0])} · ${escH(label)}</div></div></div>
        <div class="wx-mini-days">${days}</div>`;
      el.classList.remove('hidden');
    } catch { el.classList.add('hidden'); }
  }

  async function eventWeather(el, ev) {
    if (!Number.isFinite(ev.lat) || !Number.isFinite(ev.lng)) { el.classList.add('hidden'); return; }
    el.innerHTML = '<div class="muted small">Loading forecast…</div>';
    let f;
    try { f = await getForecast(ev.lat, ev.lng); } catch { el.innerHTML = '<div class="muted small">Weather forecast is temporarily unavailable.</div>'; return; }
    const tz = f.timezone;
    const start = Math.floor(new Date(ev.startsAt).getTime() / 1000);
    const end = Math.floor(new Date(ev.endsAt).getTime() / 1000);
    const now = Date.now() / 1000;
    const lastHour = f.hourly.length ? f.hourly[f.hourly.length - 1].time : 0;
    const [cIcon, cLabel] = WX(f.current.code, f.current.isDay);
    const nowLine = `<div class="wx-now-line">Right now at the venue: <strong>${cIcon} ${deg(f.current.temp)}F</strong> · ${escH(cLabel)}</div>`;

    if (end < now) { el.innerHTML = `<h3>🌤️ Weather</h3><div class="muted">This event has ended.</div>`; return; }
    if (start > lastHour) {
      const avail = new Date((start - 15 * 86400) * 1000);
      el.innerHTML = `<h3>🌤️ Weather forecast</h3>
        <div class="wx-later"><span class="wx-big-icon">📅</span><div><strong>Forecast coming soon</strong><div class="muted small">Detailed forecasts are available about 2 weeks ahead. Check back from <strong>${escH(avail.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }))}</strong>.</div></div></div>
        ${nowLine}`;
      return;
    }
    const nearest = f.hourly.reduce((best, h) => Math.abs(h.time - start) < Math.abs(best.time - start) ? h : best, f.hourly[0]);
    const day = f.daily.find((d) => start >= d.time && start < d.time + 86400) || f.daily[0];
    const [icon, label] = WX(nearest.code, nearest.isDay);
    const span = f.hourly.filter((h) => h.time >= start - 1800 && h.time <= Math.min(end, start + 10 * 3600));
    const step = span.length > 6 ? 2 : 1;
    const hours = span.filter((_, i) => i % step === 0).slice(0, 6).map((h) => {
      const [hi] = WX(h.code, h.isDay);
      return `<div class="wx-hour"><span>${escH(inTz(h.time, tz, { hour: 'numeric' }))}</span><b>${hi}</b><span>${deg(h.temp)}</span><small>${h.rain ?? 0}%</small></div>`;
    }).join('');
    const rainMax = Math.max(nearest.rain ?? 0, ...span.map((h) => h.rain ?? 0));
    const tips = [];
    if (rainMax >= 50) tips.push('☔ Good chance of rain. Bring an umbrella or rain jacket.');
    else if (rainMax >= 25) tips.push('🌂 Some chance of showers. Keep an umbrella handy.');
    if (nearest.temp >= 88) tips.push('🥵 Hot one! Stay hydrated and wear sunscreen.');
    if (nearest.wind >= 20) tips.push('💨 Breezy conditions expected.');
    const sunset = day?.sunset ? inTz(day.sunset, tz, { hour: 'numeric', minute: '2-digit' }) : '';
    el.innerHTML = `
      <h3>🌤️ Weather forecast</h3>
      <div class="wx-main">
        <span class="wx-big-icon">${icon}</span>
        <div class="wx-main-info">
          <div class="muted small">At start time · ${escH(inTz(start, tz, { weekday: 'long', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }))}</div>
          <div class="wx-main-temp">${deg(nearest.temp)}F <span>${escH(label)}</span></div>
          <div class="wx-stats"><span>🌧️ ${nearest.rain ?? 0}% rain</span><span>💨 ${Math.round(nearest.wind)} mph</span>${day ? `<span>🌡️ High ${deg(day.max)} / Low ${deg(day.min)}</span>` : ''}${sunset ? `<span>🌅 Sunset ${escH(sunset)}</span>` : ''}</div>
        </div>
      </div>
      ${hours ? `<div class="wx-hours">${hours}</div>` : ''}
      ${tips.length ? `<div class="wx-tips">${tips.map((t) => `<div>${escH(t)}</div>`).join('')}</div>` : ''}
      ${start - now > 86400 ? nowLine : ''}
      <div class="muted small" style="margin-top:8px">Forecast by Open-Meteo, updated every 30 min. Forecasts can change. Check again closer to the date.</div>`;
  }

  return { eventsMap, venueMap, pinPicker, homeWeather, eventWeather };
})();
