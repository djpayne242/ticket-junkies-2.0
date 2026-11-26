// Address lookup (OpenStreetMap Nominatim) and weather forecasts (Open-Meteo).
// Both are free and need no API key. Results are cached to stay fast and polite.

const UA = 'TicketJunkies.com/1.0 (event ticketing; admin contact via site)';
const geoMem = new Map();           // query -> { lat, lng, label } | null
let geoChain = Promise.resolve();   // Nominatim allows ~1 request/second: run lookups one at a time
let lastGeoAt = 0;

async function nominatim(q) {
  const key = q.toLowerCase().trim();
  if (geoMem.has(key)) return geoMem.get(key);
  const run = async () => {
    const wait = Math.max(0, lastGeoAt + 1100 - Date.now());
    if (wait) await new Promise((r) => setTimeout(r, wait));
    lastGeoAt = Date.now();
    const url = `https://nominatim.openstreetmap.org/search?format=json&limit=1&addressdetails=0&q=${encodeURIComponent(q)}`;
    const res = await fetch(url, { headers: { 'User-Agent': UA, 'Accept-Language': 'en' }, signal: AbortSignal.timeout(8000) });
    if (!res.ok) throw new Error(`Geocoder error ${res.status}`);
    const list = await res.json();
    const hit = list[0] ? { lat: Number(list[0].lat), lng: Number(list[0].lon), label: list[0].display_name } : null;
    geoMem.set(key, hit);
    return hit;
  };
  const p = geoChain.then(run, run);
  geoChain = p.catch(() => {});
  return p;
}

// Tries the most precise query first, then falls back to broader ones.
export async function geocodePlace({ venue = '', address = '', city = '', country = '' }) {
  const parts = (...xs) => xs.map((x) => String(x || '').trim()).filter(Boolean).join(', ');
  const tries = [
    ['venue', parts(venue, address, city, country)],
    ['address', parts(address, city, country)],
    ['venue', parts(venue, city, country)],
    ['city', parts(city, country)],
  ];
  const seen = new Set();
  for (const [precision, q] of tries) {
    if (!q || seen.has(q) || (precision !== 'city' && q === parts(city, country))) continue;
    seen.add(q);
    try {
      const hit = await nominatim(q);
      if (hit) return { ...hit, precision, query: q };
    } catch (e) {
      console.error('[geo]', e.message);
      return null;
    }
  }
  return null;
}

export const validLatLng = (lat, lng) =>
  Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180 && !(lat === 0 && lng === 0);

// ---------- weather ----------
const wxMem = new Map(); // "lat,lng" -> { at, data }
const WX_TTL = 30 * 60 * 1000;

export async function forecast(lat, lng) {
  const key = `${lat.toFixed(2)},${lng.toFixed(2)}`;
  const hit = wxMem.get(key);
  if (hit && Date.now() - hit.at < WX_TTL) return hit.data;
  const params = new URLSearchParams({
    latitude: lat.toFixed(4), longitude: lng.toFixed(4), timezone: 'auto', timeformat: 'unixtime', forecast_days: '16',
    temperature_unit: 'fahrenheit', wind_speed_unit: 'mph', precipitation_unit: 'inch',
    current: 'temperature_2m,apparent_temperature,relative_humidity_2m,weather_code,wind_speed_10m,is_day',
    hourly: 'temperature_2m,precipitation_probability,weather_code,wind_speed_10m,is_day',
    daily: 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset',
  });
  const res = await fetch(`https://api.open-meteo.com/v1/forecast?${params}`, { signal: AbortSignal.timeout(8000) });
  if (!res.ok) throw new Error(`Weather service error ${res.status}`);
  const j = await res.json();
  const data = {
    timezone: j.timezone, utcOffset: j.utc_offset_seconds,
    current: j.current && {
      time: j.current.time, temp: j.current.temperature_2m, feels: j.current.apparent_temperature,
      humidity: j.current.relative_humidity_2m, code: j.current.weather_code, wind: j.current.wind_speed_10m, isDay: !!j.current.is_day,
    },
    daily: (j.daily?.time || []).map((t, i) => ({
      time: t, code: j.daily.weather_code[i], max: j.daily.temperature_2m_max[i], min: j.daily.temperature_2m_min[i],
      rain: j.daily.precipitation_probability_max[i], sunrise: j.daily.sunrise[i], sunset: j.daily.sunset[i],
    })),
    hourly: (j.hourly?.time || []).map((t, i) => ({
      time: t, temp: j.hourly.temperature_2m[i], rain: j.hourly.precipitation_probability[i],
      code: j.hourly.weather_code[i], wind: j.hourly.wind_speed_10m[i], isDay: !!j.hourly.is_day[i],
    })),
  };
  wxMem.set(key, { at: Date.now(), data });
  if (wxMem.size > 500) wxMem.delete(wxMem.keys().next().value);
  return data;
}

// ---------- public holidays for the site's country ----------
// The Bahamas are calculated here from the Public Holidays Act rules (the free online
// databases list wrong Bahamian dates). Other countries come from date.nager.at.
function easterSunday(y) {
  const a = y % 19, b = Math.floor(y / 100), c = y % 100, d = Math.floor(b / 4), e = b % 4, f = Math.floor((b + 8) / 25), g = Math.floor((b - f + 1) / 3);
  const h = (19 * a + b - d - g + 15) % 30, i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7, m = Math.floor((a + 11 * h + 22 * l) / 451);
  return new Date(Date.UTC(y, Math.floor((h + l - 7 * m + 114) / 31) - 1, ((h + l - 7 * m + 114) % 31) + 1));
}
const isoDay = (d) => d.toISOString().slice(0, 10);
const addDays = (d, n) => new Date(d.getTime() + n * 86400000);
const nthWeekday = (y, month, weekday, n) => { const first = new Date(Date.UTC(y, month, 1)); return addDays(first, ((weekday - first.getUTCDay() + 7) % 7) + (n - 1) * 7); };
export function bahamasHolidays(y) {
  const out = [];
  const add = (d, name, note = '') => out.push({ date: isoDay(d), name, note });
  // fixed-date holidays: when they fall on a Sunday the Monday after is the day off
  const fixed = (month, day, name) => {
    const d = new Date(Date.UTC(y, month, day));
    add(d, name);
    if (d.getUTCDay() === 0) add(addDays(d, 1), name, 'observed');
  };
  fixed(0, 1, "New Year's Day");
  fixed(0, 10, 'Majority Rule Day');
  const easter = easterSunday(y);
  add(addDays(easter, -2), 'Good Friday');
  add(addDays(easter, 1), 'Easter Monday');
  add(addDays(easter, 50), 'Whit Monday');
  add(nthWeekday(y, 5, 5, 1), 'Randol Fawkes Labour Day');
  fixed(6, 10, 'Independence Day');
  add(nthWeekday(y, 7, 1, 1), 'Emancipation Day');
  add(nthWeekday(y, 9, 1, 2), "National Heroes' Day");
  const xmas = new Date(Date.UTC(y, 11, 25));
  add(xmas, 'Christmas Day');
  add(new Date(Date.UTC(y, 11, 26)), 'Boxing Day');
  if (xmas.getUTCDay() === 6) add(new Date(Date.UTC(y, 11, 27)), 'Boxing Day', 'observed'); // Boxing Day on a Sunday
  if (xmas.getUTCDay() === 0) add(new Date(Date.UTC(y, 11, 27)), 'Christmas Day', 'observed');
  return out.sort((a, b) => a.date.localeCompare(b.date));
}
const holidayCache = new Map();
let nagerCountries = null;
async function countryCode(name) {
  const n = String(name || '').trim().toLowerCase();
  if (!n || n === 'bahamas' || n === 'the bahamas' || n === 'bs') return 'BS';
  if (/^[a-z]{2}$/.test(n)) return n.toUpperCase();
  if (!nagerCountries) {
    try { const r = await fetch('https://date.nager.at/api/v3/AvailableCountries', { signal: AbortSignal.timeout(8000) }); nagerCountries = r.ok ? await r.json() : []; } catch { nagerCountries = []; }
  }
  const hit = nagerCountries.find((c) => c.name.toLowerCase() === n || c.name.toLowerCase().replace(/^the /, '') === n.replace(/^the /, ''));
  return hit ? hit.countryCode : '';
}
export async function publicHolidays(country, year) {
  const code = await countryCode(country);
  if (!code) return { country: country || '', holidays: [] };
  if (code === 'BS') return { country: 'The Bahamas', holidays: bahamasHolidays(year) };
  const key = code + year;
  const c = holidayCache.get(key);
  if (c && c.until > Date.now()) return c.value;
  let value = { country: country || code, holidays: [] };
  try {
    const r = await fetch(`https://date.nager.at/api/v3/PublicHolidays/${year}/${code}`, { signal: AbortSignal.timeout(8000) });
    if (r.ok) {
      const seen = new Set();
      value.holidays = (await r.json()).filter((x) => x.global !== false && (x.types || ['Public']).includes('Public'))
        .filter((x) => { const k = x.date + x.name; if (seen.has(k)) return false; seen.add(k); return true; })
        .map((x) => ({ date: x.date, name: x.name, note: '' }));
    }
  } catch { /* offline: no holidays */ }
  holidayCache.set(key, { value, until: Date.now() + (value.holidays.length ? 7 * 86400000 : 3600000) });
  return value;
}
