// TicketJunkies.com — event ticketing platform server
// Zero-dependency Node.js server: JSON API + static frontend + JSON file database.
// Run:  node server.mjs [port]      (default port 4321)

import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import os from 'node:os';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';
import { qrPng, qrSvg, qrMatrix } from './qr.mjs';
import { geocodePlace, forecast, validLatLng, publicHolidays } from './geo.mjs';
import { toPdf, toDocx, toXlsx, toCsv, ticketsPdf } from './exporters.mjs';
import { createNotifier, emailLayout, detailTable, escHtml as h, normalizePhone } from './notify.mjs';

const ROOT = path.dirname(fileURLToPath(import.meta.url));
const PUBLIC = path.join(ROOT, 'public');
const UPLOADS = path.join(PUBLIC, 'uploads');
const DATA_DIR = path.join(ROOT, 'data');
const DB_FILE = path.join(DATA_DIR, 'db.json');
const PORT = Number(process.argv[2] || process.env.PORT || 4321);
const MAX_BODY = 8 * 1024 * 1024;
const SESSION_DAYS = 30;

export const CATEGORIES = ['Music', 'Nightlife', 'Comedy', 'Food & Drink', 'Sports', 'Arts', 'Business', 'Community'];
const FEE_PERCENT = 0.035;   // service fee: 3.5% + $0.99 per paid ticket
const FEE_FIXED = 0.99;
const RAW_SENT = Symbol('raw response already sent');
const RECAPTCHA_TEST_SITE = '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI';   // Google's public test keys
const RECAPTCHA_TEST_SECRET = '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe';

fs.mkdirSync(DATA_DIR, { recursive: true });
fs.mkdirSync(UPLOADS, { recursive: true });

// ---------- helpers ----------
const newId = (p) => `${p}_${crypto.randomBytes(8).toString('hex')}`;
const round2 = (n) => Math.round(n * 100) / 100;
const ticketFee = (price) => {
  const s = (db && db.settings) || { feePercent: FEE_PERCENT, feeFixed: FEE_FIXED };
  return price > 0 ? round2(price * s.feePercent + s.feeFixed) : 0;
};

function hashPassword(pw, salt = crypto.randomBytes(16).toString('hex')) {
  return `${salt}:${crypto.scryptSync(pw, salt, 64).toString('hex')}`;
}
function verifyPassword(pw, stored) {
  const [salt, hash] = String(stored).split(':');
  if (!salt || !hash) return false;
  const test = crypto.scryptSync(pw, salt, 64);
  const ref = Buffer.from(hash, 'hex');
  return ref.length === test.length && crypto.timingSafeEqual(test, ref);
}

class HttpError extends Error {
  constructor(status, message) { super(message); this.status = status; }
}
const fail = (status, msg) => { throw new HttpError(status, msg); };

function str(v, { name, max = 200, required = false } = {}) {
  const s = typeof v === 'string' ? v.trim() : '';
  if (required && !s) fail(400, `${name} is required`);
  if (s.length > max) fail(400, `${name} must be ${max} characters or fewer`);
  return s;
}

// ---------- database ----------
let db;
function saveDb() {
  const tmp = DB_FILE + '.tmp';
  // Compact JSON: noticeably faster to write as the number of orders grows.
  fs.writeFileSync(tmp, JSON.stringify(db));
  fs.renameSync(tmp, DB_FILE);
}
function loadDb() {
  if (fs.existsSync(DB_FILE)) {
    db = JSON.parse(fs.readFileSync(DB_FILE, 'utf8'));
  } else {
    db = { users: [], events: [], orders: [], sessions: {} };
    seed();
  }
  migrate();
  applyBundledFlyers();
  saveDb();
}

// Ready-made flyers for the demo events (public/flyers). Added once per event, only when it has no picture yet,
// so a flyer the organizer uploads is never replaced.
const BUNDLED_FLYERS = {
  'Friday Night Afrobeats Takeover': 'friday-night-afrobeats-takeover',
  'Island Vibes Summer Fest': 'island-vibes-summer-fest',
  'Startup Pitch Night': 'startup-pitch-night',
  'Laugh Out Loud Comedy Night': 'laugh-out-loud-comedy-night',
  'Taste of the Islands Food Festival': 'taste-of-the-islands-food-festival',
  'Junkanoo Rush Experience': 'junkanoo-rush-experience',
};
function applyBundledFlyers() {
  db.settings.bundledFlyers ||= {};
  for (const e of db.events) {
    const slug = BUNDLED_FLYERS[String(e.title || '').trim()];
    if (!slug || e.imageUrl || db.settings.bundledFlyers[e.id]) continue;
    if (!fs.existsSync(path.join(PUBLIC, 'flyers', slug + '.jpg'))) continue;
    e.imageUrl = `/flyers/${slug}.jpg`;
    e.imageShareUrl = `/flyers/${slug}-share.jpg`;
    db.settings.bundledFlyers[e.id] = slug;
  }
}

// Upgrades older databases and guarantees an administrator account exists.
function migrate() {
  // "Promoter" was renamed to "Organizer": upgrade older saved data once.
  for (const u of db.users || []) { if (u.role === 'promoter') u.role = 'organizer'; if (u.name === 'Demo Promoter') u.name = 'Demo Organizer'; }
  for (const e of db.events || []) if (e.promoterId !== undefined) { e.organizerId ??= e.promoterId; delete e.promoterId; }
  const aa = db.settings?.notifications?.adminAlerts;
  if (aa && aa.promoterSignup !== undefined) { aa.organizerSignup ??= aa.promoterSignup; delete aa.promoterSignup; }
  // Demo account login and saved message history: replace the old wording too.
  const oldDemo = (db.users || []).find((u) => u.email === 'promoter@ticketjunkies.com');
  if (oldDemo && !(db.users || []).some((u) => u.email === 'organizer@ticketjunkies.com')) {
    oldDemo.email = 'organizer@ticketjunkies.com';
    oldDemo.passwordHash = hashPassword('organizer123');
  }
  const reword = (v) => typeof v !== 'string' ? v : v
    .replace(/promoter@ticketjunkies\.com/g, 'organizer@ticketjunkies.com')
    .replace(/\bPROMOTERS?\b/g, (w) => w.replace('PROMOTER', 'ORGANIZER'))
    .replace(/\bPromoter/g, 'Organizer')
    .replace(/\bpromoter/g, 'organizer');
  for (const n of db.notifications || []) for (const k of ['tag', 'to', 'subject', 'html', 'text', 'detail']) if (n[k] !== undefined) n[k] = reword(n[k]);
  for (const o of db.orders || []) for (const k of ['buyerEmail', 'issuedBy', 'compNote']) if (o[k] !== undefined) o[k] = reword(o[k]);
  for (const e of db.events || []) if (!e.organizerName) { const o = (db.users || []).find((u) => u.id === e.organizerId); if (o) e.organizerName = o.organization || o.name; }
  for (const e of db.events || []) if (!Array.isArray(e.organizers) && e.organizerName) e.organizers = [e.organizerName];
  if (!Array.isArray(db.organizers) || !Array.isArray(db.venues)) {
    db.organizers = Array.isArray(db.organizers) ? db.organizers : [];
    db.venues = Array.isArray(db.venues) ? db.venues : [];
    for (const u of db.users || []) if (u.role === 'organizer' && (u.organization || u.name) && !db.organizers.some((o) => norm(o.name) === norm(u.organization || u.name)))
      db.organizers.push({ id: newId('org'), name: u.organization || u.name, email: u.email, phone: u.phone || '', website: '', notes: '', userId: u.id, createdById: null, createdAt: u.createdAt || new Date().toISOString() });
    for (const e of db.events || []) syncDirectory(e, db.users.find((u) => u.id === e.organizerId));
  }
  for (const o of db.organizers || []) if (!o.userId) { const u = (db.users || []).find((x) => (x.role === 'organizer' || x.role === 'admin') && x.organization && norm(x.organization) === norm(o.name)); if (u) { o.userId = u.id; o.email ||= u.email; o.phone ||= u.phone || ''; } }
  for (const e of db.events || []) {
    if (!e.imageUrl || !/^\/uploads\/[\w-]+\.(png|jpe?g)$/.test(e.imageUrl)) continue;
    const opt = e.imageUrl.replace(/\.(png|jpe?g)$/, '-opt.jpg');
    if (fs.existsSync(path.join(PUBLIC, opt))) e.imageUrl = opt;
  }
  db.settings ||= { feePercent: FEE_PERCENT, feeFixed: FEE_FIXED };
  db.settings.notifications = {
    siteUrl: `http://localhost:${PORT}`, adminEmail: '', emailFrom: 'TicketJunkies <tickets@ticketjunkies.com>', replyTo: '',
    emailEnabled: false, resendApiKey: '', smsEnabled: false, twilioSid: '', twilioToken: '', twilioFrom: '',
    adminAlerts: { userSignup: true, organizerSignup: true, ticketSale: true, eventCreated: true },
    ...(db.settings.notifications || {}),
  };
  db.notifications ||= [];
  // Google reCAPTCHA v2 ("I'm not a robot"). Starts with Google's public TEST keys, which always pass
  // and show a red "for testing purposes only" notice. Replace them with real keys in Admin → Settings.
  db.settings.cardHashKey ||= crypto.randomBytes(32).toString('hex'); // secret for card fingerprints
  db.settings.captcha = {
    enabled: true, protectRegister: true, protectCheckout: true,
    siteKey: RECAPTCHA_TEST_SITE, secretKey: RECAPTCHA_TEST_SECRET,
    ...(db.settings.captcha || {}),
  };
  db.settings.maps = {
    googleMapsKey: '', country: 'Bahamas',
    weatherPlace: 'Nassau, Bahamas', weatherLat: 25.0582, weatherLng: -77.3431,
    ...(db.settings.maps || {}),
  };
  db.settings.security = { sessionHours: 720, adminSessionHours: 720, idleMinutes: 0, maxAttempts: 5, lockMinutes: 15, allowReset: true, resetMinutes: 60, minPassword: 8, ...(db.settings.security || {}) };
  db.resets ||= [];
  db.settings.preloader = { enabled: true, icon: 'logo', customUrl: '', size: 56, animation: 'pulse', speed: 'normal', background: 'page', ...(db.settings.preloader || {}) };
  for (const u of db.users) u.phone ??= '';
  for (const o of db.orders) {
    o.key ||= crypto.randomBytes(12).toString('hex'); o.buyerPhone ??= '';
    // orders placed before card reporting existed: best-effort details from the last 4 digits
    o.cardBrand ??= o.cardLast4 ? (o.cardLast4 === '4242' ? 'Visa' : 'Card') : '';
    o.cardHolder ??= o.cardLast4 ? o.buyerName : '';
    o.cardFingerprint ??= o.cardLast4 ? 'legacy_' + o.cardLast4 : '';
  }
  for (const u of db.users) if (u.suspended === undefined) u.suspended = false;
  for (const o of db.orders) o.status ||= 'paid';
  if (!db.users.some((u) => u.role === 'admin')) {
    db.users.push({
      id: newId('usr'), name: 'Site Administrator', email: 'admin@ticketjunkies.com',
      passwordHash: hashPassword('admin12345'), role: 'admin', organization: 'TicketJunkies.com',
      suspended: false, createdAt: new Date().toISOString(),
    });
    console.log('Created administrator account: admin@ticketjunkies.com / admin12345 (change this password!)');
  }
}

function seed() {
  const now = Date.now();
  const day = 86400000;
  const organizer = {
    id: newId('usr'), name: 'Demo Organizer', email: 'organizer@ticketjunkies.com',
    passwordHash: hashPassword('organizer123'), role: 'organizer',
    organization: 'Junkie Nights Entertainment', createdAt: new Date().toISOString(),
  };
  const fan = {
    id: newId('usr'), name: 'Demo Fan', email: 'fan@ticketjunkies.com',
    passwordHash: hashPassword('fan12345'), role: 'user', organization: '',
    createdAt: new Date().toISOString(),
  };
  db.users.push(organizer, fan);
  const at = (d, h) => { const x = new Date(now + d * day); x.setHours(h, 0, 0, 0); return x.toISOString(); };
  const tier = (name, price, quantity) => ({ id: newId('tier'), name, price, quantity, sold: 0 });
  const samples = [
    ['Island Vibes Summer Fest', 'Music', 'An all-day open-air festival with live bands, DJs, food vendors and island vibes until sunset. Bring your crew!', 9, 14, 23, 'Paradise Beach Grounds', '1 Ocean Drive', 'Nassau', [tier('General Admission', 45, 800, 312), tier('VIP Lounge', 120, 120, 64)]],
    ['Friday Night Afrobeats Takeover', 'Nightlife', 'The hottest Afrobeats, Amapiano and Dancehall all night long with special guest DJs.', 4, 22, 4, 'Club Velocity', '88 Bay Street', 'Nassau', [tier('Early Bird', 20, 150, 150), tier('Regular', 30, 300, 121), tier('VIP Table (6 ppl)', 400, 10, 3)]],
    ['Laugh Out Loud Comedy Night', 'Comedy', 'Five of the funniest comedians in the Caribbean on one stage. 18+ show.', 15, 20, 23, 'The Grand Theatre', '12 Shirley Street', 'Nassau', [tier('Standard Seat', 35, 250, 98), tier('Front Row', 65, 30, 12)]],
    ['Taste of the Islands Food Festival', 'Food & Drink', 'Sample dishes from 40+ local chefs, rum tastings, cooking demos and live music.', 21, 12, 20, 'Arawak Cay', 'West Bay Street', 'Nassau', [tier('Day Pass', 25, 1000, 220), tier('Tasting Pass + Drinks', 60, 200, 41)]],
    ['Startup Pitch Night', 'Business', 'Local founders pitch to investors. Networking, drinks and inspiration.', 12, 18, 21, 'Innovation Hub', '45 East Street', 'Nassau', [tier('Free RSVP', 0, 120, 37)]],
    ['Junkanoo Rush Experience', 'Community', 'Feel the rhythm of cowbells and goatskin drums. A family-friendly celebration of culture.', 35, 16, 22, 'Rawson Square', 'Bay Street', 'Nassau', [tier('Bleacher Seat', 15, 2000, 410), tier('Premium Stand', 50, 300, 88)]],
  ];
  for (const [title, category, description, d, h1, h2, venue, address, city, tiers] of samples) {
    db.events.push({
      id: newId('evt'), organizerId: organizer.id, title, category, description,
      startsAt: at(d, h1), endsAt: at(h2 < h1 ? d + 1 : d, h2),
      venue, address, city, imageUrl: '', status: 'published', tiers,
      createdAt: new Date().toISOString(),
    });
  }
}

// ---------- sessions ----------
function parseCookies(req) {
  const out = {};
  for (const part of String(req.headers.cookie || '').split(';')) {
    const i = part.indexOf('=');
    if (i > 0) out[part.slice(0, i).trim()] = decodeURIComponent(part.slice(i + 1).trim());
  }
  return out;
}
// Log-in & security settings (Admin → Settings → Log in & security)
const SEC = () => db.settings.security;
const sessionMs = (user) => (user.role === 'admin' ? SEC().adminSessionHours : SEC().sessionHours) * 3600000;
let sessSaveT = null;
const saveSessionsSoon = () => { if (!sessSaveT) sessSaveT = setTimeout(() => { sessSaveT = null; saveDb(); }, 10000); };
function currentUser(req) {
  const token = parseCookies(req).tj_session;
  const s = token && db.sessions[token];
  if (!s) return null;
  const now = Date.now();
  const user = db.users.find((u) => u.id === s.userId);
  const created = s.created || s.expires - SESSION_DAYS * 86400000;
  const idle = SEC().idleMinutes * 60000;
  // Ends the session when it is past its time limit, or nobody used it for longer than the idle limit.
  if (!user || s.expires < now || created + sessionMs(user) < now || (idle && s.lastSeen && now - s.lastSeen > idle)) { delete db.sessions[token]; saveSessionsSoon(); return null; }
  if (!s.lastSeen || now - s.lastSeen > 60000) { s.lastSeen = now; saveSessionsSoon(); }
  return !user.suspended ? user : null;
}
function startSession(res, user) {
  const token = crypto.randomBytes(32).toString('hex');
  const now = Date.now(), ms = sessionMs(user);
  db.sessions[token] = { userId: user.id, created: now, lastSeen: now, expires: now + ms };
  // Secure: on https the log-in cookie is never sent over a plain http connection.
  const secure = res.req && requestOrigin(res.req).startsWith('https:') ? '; Secure' : '';
  res.setHeader('Set-Cookie', `tj_session=${token}; HttpOnly; Path=/; SameSite=Lax; Max-Age=${Math.round(ms / 1000)}${secure}`);
}
function checkPassword(pw, label = 'Password') {
  const n = SEC().minPassword;
  if (pw.length < n) fail(400, `${label} must be at least ${n} characters`);
  if (pw.length > 200) fail(400, `${label} is too long`);
}
const durLabel = (min) => min % 1440 === 0 ? `${min / 1440} day${min === 1440 ? '' : 's'}` : min % 60 === 0 ? `${min / 60} hour${min === 60 ? '' : 's'}` : `${min} minute${min === 1 ? "" : "s"}`;
const sha256 = (t) => crypto.createHash('sha256').update(String(t)).digest('hex');
const loginFails = new Map(); // email -> { count, until }
const publicUser = (u) => u && ({ id: u.id, name: u.name, email: u.email, phone: u.phone || '', role: u.role, organization: u.organization, suspended: !!u.suspended });
const requireUser = (req) => currentUser(req) || fail(401, 'Please log in first');
function requireOrganizer(req) {
  const u = requireUser(req);
  if (u.role !== 'organizer' && u.role !== 'admin') fail(403, 'Only organizer accounts can do this');
  return u;
}
function requireAdmin(req) {
  const u = requireUser(req);
  if (u.role !== 'admin') fail(403, 'Administrator access required');
  return u;
}

// ---------- event shaping & validation ----------
// Every date/time in a recurring series (organizers and admins also see drafts and cancelled dates).
function seriesDates(ev, viewer) {
  const owner = !!viewer && (viewer.id === ev.organizerId || viewer.role === 'admin');
  return db.events
    .filter((e) => e.seriesId === ev.seriesId && (owner || e.status === 'published'))
    .sort((a, b) => new Date(a.startsAt) - new Date(b.startsAt))
    .map((e) => ({ id: e.id, startsAt: e.startsAt, endsAt: e.endsAt, status: e.status, sessionLabel: e.sessionLabel || '',
      soldOut: e.tiers.filter((t) => t.visibility !== 'private').every((t) => t.quantity - t.sold <= 0) }));
}
function publicEvent(ev, viewer) {
  const organizer = db.users.find((u) => u.id === ev.organizerId);
  const mine = boughtBy(viewer, ev.id);
  return {
    id: ev.id, title: ev.title, category: ev.category, description: ev.description,
    startsAt: ev.startsAt, endsAt: ev.endsAt, venue: ev.venue, address: ev.address, city: ev.city,
    imageUrl: ev.imageUrl, imageShareUrl: ev.imageShareUrl || '', status: ev.status,
    lat: Number.isFinite(ev.lat) ? ev.lat : null, lng: Number.isFinite(ev.lng) ? ev.lng : null, geoPrecision: ev.geoPrecision || null,
    organizer: ev.organizerName || (organizer ? (organizer.organization || organizer.name) : 'Unknown'),
    organizers: eventOrganizers(ev), extraVenues: ev.extraVenues || [],
    schedule: ev.schedule || 'single', seriesId: ev.seriesId || null, sessionLabel: ev.sessionLabel || '',
    series: ev.seriesId ? seriesDates(ev, viewer) : [],
    organizerName: ev.organizerName || (organizer ? (organizer.organization || organizer.name) : ''),
    isOwner: !!viewer && (viewer.id === ev.organizerId || viewer.role === 'admin'),
    // Private ticket types (comps, guest list, staff…) are only visible to the event's organizer and admins.
    tiers: ev.tiers.filter((t) => t.visibility !== 'private' || (!!viewer && (viewer.id === ev.organizerId || viewer.role === 'admin')))
      .map((t) => ({ id: t.id, name: t.name, description: t.description || '', visibility: t.visibility === 'private' ? 'private' : 'public', kind: tierKind(t), price: t.price, suggested: t.suggested ?? null, salesStart: t.salesStart || null, salesEnd: t.salesEnd || null, saleState: tierSaleState(t), onSale: tierOnSale(t), maxPerPerson: t.maxPerPerson || null, boughtByYou: mine[t.id] || 0, remaining: Math.max(0, t.quantity - t.sold), fee: ticketFee(t.price) })),
  };
}
const isPublicTier = (t) => t.visibility !== 'private';
const TICKET_KINDS = ['paid', 'free', 'donation'];
// Older ticket types have no kind: $0 = free, anything else = paid
function tierKind(t) { return TICKET_KINDS.includes(t.kind) ? t.kind : (t.price > 0 ? 'paid' : 'free'); }
// soon = sales not started · open = can be bought · closed = sales end has passed
function tierSaleState(t) {
  const now = Date.now();
  if (t.salesStart && new Date(t.salesStart).getTime() > now) return 'soon';
  if (t.salesEnd && new Date(t.salesEnd).getTime() <= now) return 'closed';
  return 'open';
}
const tierOnSale = (t) => tierSaleState(t) === 'open';
// tickets of each type this person already bought for the event (refunded and complimentary orders don't count)
function boughtBy(user, eventId) {
  const out = {};
  if (!user) return out;
  for (const o of db.orders) {
    if (o.userId !== user.id || o.eventId !== eventId || o.status === 'refunded' || o.comp) continue;
    for (const i of o.items) out[i.tierId] = (out[i.tierId] || 0) + i.qty;
  }
  return out;
}
// Lowest open public price, for "From $X" labels
function lowestPrice(tiers) {
  const avail = tiers.filter((t) => t.visibility !== 'private' && t.quantity - t.sold > 0);
  const open = avail.filter(tierOnSale);
  if (!open.length) {
    const next = avail.filter((t) => tierSaleState(t) === 'soon').map((t) => t.salesStart).sort()[0] || null;
    const closed = !next && avail.length > 0 && avail.every((t) => tierSaleState(t) === 'closed');
    return { priceFrom: null, priceKind: null, soldOut: !next && !closed, salesStart: next, salesClosed: closed };
  }
  const best = open.reduce((a, t) => (t.price < a.price || (t.price === a.price && tierKind(t) === 'paid') ? t : a), open[0]);
  return { priceFrom: best.price, priceKind: tierKind(best), soldOut: false };
}

function cleanImageUrl(v) {
  const s = str(v, { name: 'Image URL', max: 500 });
  if (!s) return '';
  if (!/^(\/uploads\/[\w.-]+|https?:\/\/[^\s"'()\\<>]+)$/.test(s)) fail(400, 'Image URL is not valid');
  return s;
}

// ---------- Organizers & venues directory ----------
function norm(x) { return String(x || '').trim().toLowerCase().replace(/\s+/g, ' '); }
function joinNames(list) { return list.length <= 2 ? list.join(' & ') : list.slice(0, -1).join(', ') + ' & ' + list[list.length - 1]; }
function cleanOrganizers(list) {
  const out = [];
  for (const raw of (Array.isArray(list) ? list : []).slice(0, 20)) {
    const n = String(raw ?? '').trim().replace(/\s+/g, ' ');
    if (!n) continue;
    if (n.length > 100) fail(400, 'Each organizer name must be 100 characters or less');
    if (!out.some((x) => norm(x) === norm(n))) out.push(n);
  }
  if (out.length > 10) fail(400, 'An event can have at most 10 organizers');
  return out;
}
function cleanExtraVenues(list) {
  if (!Array.isArray(list)) return [];
  if (list.length > 9) fail(400, 'An event can have at most 10 venues');
  return list.map((v, i) => {
    const name = str(v?.name, { name: `Venue #${i + 2} name`, max: 120, required: true });
    const address = str(v?.address, { name: `Venue #${i + 2} address`, max: 200 });
    const city = str(v?.city, { name: `Venue #${i + 2} city`, max: 80 });
    const lat = Number(v?.lat), lng = Number(v?.lng);
    const ok = v?.lat != null && v?.lng != null && validLatLng(lat, lng);
    return { name, address, city, lat: ok ? Math.round(lat * 1e6) / 1e6 : null, lng: ok ? Math.round(lng * 1e6) / 1e6 : null };
  });
}
function eventOrganizers(e) { return Array.isArray(e.organizers) && e.organizers.length ? e.organizers : (e.organizerName ? [e.organizerName] : []); }
function eventVenueNames(e) { return [e.venue, ...(e.extraVenues || []).map((v) => v.name)].filter(Boolean); }
// Adds organizers / venues used on an event to the saved lists, so they can be picked next time.
function syncDirectory(ev, user) {
  db.organizers ||= []; db.venues ||= [];
  const now = new Date().toISOString();
  for (const n of eventOrganizers(ev)) {
    if (!db.organizers.some((o) => norm(o.name) === norm(n))) db.organizers.push({ id: newId('org'), name: n, email: '', phone: '', website: '', notes: '', userId: (user?.role === 'organizer' || user?.role === 'admin') && norm(user.organization || user.name) === norm(n) ? user.id : null, createdById: user?.id || null, createdAt: now });
  }
  const all = [{ name: ev.venue, address: ev.address, city: ev.city, lat: ev.lat, lng: ev.lng, geoPrecision: ev.geoPrecision }, ...(ev.extraVenues || [])];
  for (const v of all) {
    if (!v.name) continue;
    const hit = db.venues.find((x) => norm(x.name) === norm(v.name) && (!v.city || !x.city || norm(x.city) === norm(v.city)));
    if (!hit) db.venues.push({ id: newId('ven'), name: v.name, address: v.address || '', city: v.city || '', lat: validLatLng(v.lat, v.lng) ? v.lat : null, lng: validLatLng(v.lat, v.lng) ? v.lng : null, geoPrecision: validLatLng(v.lat, v.lng) ? (v.geoPrecision || 'pin') : null, phone: '', notes: '', createdById: user?.id || null, createdAt: now });
    else if (!validLatLng(hit.lat, hit.lng) && validLatLng(v.lat, v.lng)) { hit.lat = v.lat; hit.lng = v.lng; hit.geoPrecision = v.geoPrecision || 'pin'; }
  }
}

function validateEvent(b, existing) {
  const title = str(b.title, { name: 'Title', max: 120, required: true });
  const category = CATEGORIES.includes(b.category) ? b.category : fail(400, 'Choose a valid category');
  const description = str(b.description, { name: 'Description', max: 5000, required: true });
  const start = new Date(b.startsAt);
  if (isNaN(start)) fail(400, 'Start date/time is required');
  let end = b.endsAt ? new Date(b.endsAt) : new Date(start.getTime() + 3 * 3600000);
  if (isNaN(end) || end < start) fail(400, 'End time must be after the start time');
  const organizers = cleanOrganizers(Array.isArray(b.organizers) && b.organizers.length ? b.organizers : [b.organizerName]);
  if (!organizers.length) fail(400, 'Organizer is required');
  const organizerName = joinNames(organizers);
  const extraVenues = cleanExtraVenues(b.extraVenues);
  const schedule = ['single', 'multi', 'series'].includes(b.schedule) ? b.schedule : 'single';
  const sessionLabel = str(b.sessionLabel, { name: 'Session name', max: 60 });
  const venue = str(b.venue, { name: 'Venue', max: 120, required: true });
  const address = str(b.address, { name: 'Address', max: 200 });
  const city = str(b.city, { name: 'City', max: 80, required: true });
  const imageUrl = cleanImageUrl(b.imageUrl);
  const imageShareUrl = imageUrl ? cleanImageUrl(b.imageShareUrl) : '';
  const allowed = existing ? ['published', 'draft', 'cancelled'] : ['published', 'draft'];
  const status = allowed.includes(b.status) ? b.status : 'draft';

  if (!Array.isArray(b.tiers) || b.tiers.length < 1) fail(400, 'Add at least one ticket type');
  if (b.tiers.length > 10) fail(400, 'Maximum 10 ticket types');
  const oldTiers = existing ? existing.tiers : [];
  const tiers = b.tiers.map((t, i) => {
    const name = str(t.name, { name: `Ticket type #${i + 1} name`, max: 60, required: true });
    // Paid = fixed price · Free = $0 · Donation = buyer chooses the amount (price is the minimum)
    const kind = TICKET_KINDS.includes(t.kind) ? t.kind : (Number(t.price) > 0 ? 'paid' : 'free');
    const price = kind === 'free' ? 0 : round2(Number(t.price || 0));
    if (!Number.isFinite(price) || price < 0 || price > 10000) fail(400, `${kind === 'donation' ? 'Minimum donation' : 'Price'} for "${name}" must be between 0 and 10,000`);
    if (kind === 'paid' && price <= 0) fail(400, `"${name}" is a paid ticket, so it needs a price above $0. Choose "Free" for free tickets.`);
    let suggested = null;
    if (kind === 'donation' && t.suggested !== undefined && t.suggested !== null && String(t.suggested).trim() !== '') {
      suggested = round2(Number(t.suggested));
      if (!Number.isFinite(suggested) || suggested < price || suggested > 10000) fail(400, `Suggested donation for "${name}" must be at least the minimum (${money(price)}) and at most 10,000`);
    }
    const quantity = Number(t.quantity);
    if (!Number.isInteger(quantity) || quantity < 1 || quantity > 100000) fail(400, `Quantity for "${name}" must be a whole number from 1 to 100,000`);
    const old = t.id && oldTiers.find((o) => o.id === t.id);
    if (old && quantity < old.sold) fail(400, `"${name}" already sold ${old.sold}; quantity can't be lower`);
    if (old && old.sold - (old.comped || 0) > 0 && kind !== tierKind(old)) fail(400, `"${name}" has sales; it can't be changed between paid, free and donation`);
    if (old && old.sold - (old.comped || 0) > 0 && price !== old.price) fail(400, `"${name}" has sales; its ${kind === 'donation' ? 'minimum donation' : 'price'} can't change`);
    const description = str(t.description, { name: `Description for "${name}"`, max: 300 });
    const visibility = t.visibility === 'private' ? 'private' : 'public';
    // optional: when this ticket type goes on sale (a countdown shows until then)
    let salesStart = null;
    if (t.salesStart !== undefined && t.salesStart !== null && String(t.salesStart).trim() !== '') {
      const d = new Date(t.salesStart);
      if (isNaN(d)) fail(400, `Sales start for "${name}" is not a valid date and time`);
      if (d >= end) fail(400, `Sales for "${name}" must start before the event ends`);
      salesStart = d.toISOString();
    }
    // optional: when this ticket type stops selling (a countdown shows while it is on sale)
    let salesEnd = null;
    if (t.salesEnd !== undefined && t.salesEnd !== null && String(t.salesEnd).trim() !== '') {
      const d = new Date(t.salesEnd);
      if (isNaN(d)) fail(400, `Sales end for "${name}" is not a valid date and time`);
      if (salesStart && d <= new Date(salesStart)) fail(400, `Sales end for "${name}" must be after its sales start`);
      if (d > end) fail(400, `Sales for "${name}" must end by the time the event ends`);
      salesEnd = d.toISOString();
    }
    // optional: most tickets of this type one person (account) can buy, across all their orders
    let maxPerPerson = null;
    if (t.maxPerPerson !== undefined && t.maxPerPerson !== null && String(t.maxPerPerson).trim() !== '') {
      maxPerPerson = Number(t.maxPerPerson);
      if (!Number.isInteger(maxPerPerson) || maxPerPerson < 1 || maxPerPerson > 100) fail(400, `Max per person for "${name}" must be a whole number from 1 to 100`);
    }
    return { id: old ? old.id : newId('tier'), name, description, visibility, kind, price, ...(kind === 'donation' ? { suggested } : {}), salesStart, salesEnd, maxPerPerson, quantity, sold: old ? old.sold : 0, comped: old ? (old.comped || 0) : 0 };
  });
  for (const o of oldTiers) {
    if (o.sold > 0 && !tiers.some((t) => t.id === o.id)) fail(400, `"${o.name}" has sales and can't be removed`);
  }
  const lat = b.lat === null || b.lat === '' || b.lat === undefined ? NaN : Number(b.lat);
  const lng = b.lng === null || b.lng === '' || b.lng === undefined ? NaN : Number(b.lng);
  const geo = validLatLng(lat, lng)
    ? { lat: Math.round(lat * 1e6) / 1e6, lng: Math.round(lng * 1e6) / 1e6, geoPrecision: b.geoPrecision === 'pin' ? 'pin' : (['venue', 'address', 'city'].includes(b.geoPrecision) ? b.geoPrecision : 'pin') }
    : { lat: null, lng: null, geoPrecision: null };
  return { title, organizerName, organizers, extraVenues, schedule, sessionLabel, category, description, startsAt: start.toISOString(), endsAt: end.toISOString(), venue, address, city, imageUrl, imageShareUrl, status, tiers, ...geo };
}

// Looks up map coordinates for an event that has none (never overrides an organizer's pin).
async function ensureGeo(ev) {
  if (validLatLng(ev.lat, ev.lng)) return;
  const hit = await geocodePlace({ venue: ev.venue, address: ev.address, city: ev.city, country: db.settings.maps.country });
  const live = db.events.find((e) => e.id === ev.id);
  if (hit && live && !validLatLng(live.lat, live.lng)) {
    Object.assign(live, { lat: Math.round(hit.lat * 1e6) / 1e6, lng: Math.round(hit.lng * 1e6) / 1e6, geoPrecision: hit.precision });
    saveDb();
  }
}
const withTimeout = (p, ms) => Promise.race([p, new Promise((r) => setTimeout(r, ms))]);

function ownedEvent(user, id) {
  const ev = db.events.find((e) => e.id === id);
  if (!ev || (ev.organizerId !== user.id && user.role !== 'admin')) fail(404, 'Event not found');
  return ev;
}

function eventStats(ev) {
  const orders = db.orders.filter((o) => o.eventId === ev.id && o.status !== 'refunded' && !o.test);
  const sold = ev.tiers.reduce((a, t) => a + t.sold, 0);
  const capacity = ev.tiers.reduce((a, t) => a + t.quantity, 0);
  const comped = ev.tiers.reduce((a, t) => a + (t.comped || 0), 0);
  // donation tickets count what buyers actually gave, not the minimum
  const donated = (t) => orders.filter((o) => !o.comp).reduce((a, o) => a + o.items.filter((i) => i.tierId === t.id).reduce((x, i) => x + i.price * i.qty, 0), 0);
  const revenue = round2(ev.tiers.reduce((a, t) => a + (tierKind(t) === 'donation' ? donated(t) : Math.max(0, t.sold - (t.comped || 0)) * t.price), 0));
  const checkedIn = orders.reduce((a, o) => a + o.tickets.filter((t) => t.checkedIn).length, 0);
  return { sold, capacity, comped, paidSold: sold - comped, revenue, orders: orders.filter((o) => !o.comp).length, compOrders: orders.filter((o) => o.comp).length, checkedIn, shareViews: ev.shareViews || 0 };
}

// First/last name for any order (older orders only stored a full name)
function splitName(o) {
  if (o.buyerFirstName || o.buyerLastName) return { buyerFirstName: o.buyerFirstName || '', buyerLastName: o.buyerLastName || '' };
  const p = String(o.buyerName || '').trim().split(/\s+/);
  return { buyerFirstName: p.length > 1 ? p.slice(0, -1).join(' ') : (p[0] || ''), buyerLastName: p.length > 1 ? p[p.length - 1] : '' };
}
function orderView(o) {
  const ev = db.events.find((e) => e.id === o.eventId);
  const { cardFingerprint, ...rest } = o;
  return {
    ...rest,
    event: ev ? { id: ev.id, title: ev.title, startsAt: ev.startsAt, venue: ev.venue, address: ev.address, city: ev.city, imageUrl: ev.imageUrl, category: ev.category, status: ev.status } : null,
  };
}

// ---------- notifications ----------
// Sandbox context: while set, messages are tagged as tests and staff copies go to the tester.
let sandboxCtx = null;
const notifier = createNotifier({
  getSettings: () => db.settings, getLog: () => db.notifications, save: () => saveDb(),
  getContext: () => (sandboxCtx ? { test: true, ref: sandboxCtx.orderId } : {}),
});
const N = () => db.settings.notifications;
const site = () => N().siteUrl.replace(/\/+$/, '');
const money = (n) => '$' + Number(n).toFixed(2);
const when = (iso) => new Date(iso).toLocaleString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
const adminRecipients = () => (N().adminEmail ? N().adminEmail.split(/[,;\s]+/).filter(Boolean) : activeAdmins().map((u) => u.email));
const organizerOf = (ev) => db.users.find((u) => u.id === ev.organizerId);
const ticketLink = (o) => `${site()}/#/t/${o.id}/${o.key}`;
const ticketKey = (o, code) => crypto.createHmac('sha256', o.key).update(code).digest('hex').slice(0, 20);
const ticketPdfLink = (o, t) => `${site()}/api/tickets/${o.id}/pdf?code=${encodeURIComponent(t.code)}&tk=${ticketKey(o, t.code)}`;
const orderPdfLink = (o) => `${site()}/api/tickets/${o.id}/pdf?k=${o.key}`;
const ticketPdfName = (ev, o, suffix = '') => `TicketJunkies-${String(ev.title || 'Event').replace(/[^\w]+/g, '-').replace(/^-|-$/g, '').slice(0, 40)}-${suffix || o.id.slice(-8).toUpperCase()}.pdf`;
const pdfAttachment = (o, ev, tickets, suffix = '') => ({ filename: ticketPdfName(ev, o, suffix), content: ticketsPdf({ ...o, tickets }, ev, qrMatrix, { site: site() }) });
// Table of every ticket on an order and who it belongs to (organizer + admin sale emails)
function attendeeTable(o) {
  const th = 'style="text-align:left;padding:8px 10px;border-bottom:2px solid #e5e7eb;font-size:11px;color:#6b7280;text-transform:uppercase"';
  const td = 'style="padding:8px 10px;border-bottom:1px solid #eef0f3;vertical-align:top"';
  const rows = o.tickets.map((t, i) => `<tr><td ${td}>${i + 1}</td><td ${td}><strong>${h(t.holderName || o.buyerName)}</strong><br><span style="color:#6b7280">${h(t.holderEmail || o.buyerEmail)}</span></td><td ${td}>${h(t.tierName)}<br><span style="color:#6b7280">${t.price ? money(t.price) : o.comp ? 'Comp' : 'Free'}</span></td><td style="padding:8px 10px;border-bottom:1px solid #eef0f3;vertical-align:top;font-family:Menlo,Consolas,monospace;font-weight:700;color:#376eb8">${h(t.code)}</td></tr>`).join('');
  return `<h3 style="font-size:15px;margin:20px 0 8px">👥 Attendees (${o.tickets.length})</h3><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px"><tr><th ${th}>#</th><th ${th}>Attendee</th><th ${th}>Ticket</th><th ${th}>Code</th></tr>${rows}</table>`;
}
const eventLink = (ev) => `${site()}/#/event/${ev.id}`;
const evRows = (ev) => [['Event', `<strong>${h(ev.title)}</strong>`], ['When', h(when(ev.startsAt))], ['Where', h([ev.venue, ev.address, ev.city].filter(Boolean).join(', '))]];

function mail(to, subject, { heading, preheader, body, cta, footerNote, tag, attachments }) {
  if (sandboxCtx) {
    // Never bother real organizers/admins with test messages: their copies go to the person running the test.
    if (!String(tag).startsWith('buyer.')) {
      const role = String(tag).startsWith('admin.') ? 'admin' : 'organizer';
      body = `<p style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;padding:10px 14px;border-radius:10px;font-size:13px">🧪 Sandbox test — this is the <strong>${role}</strong> copy. In a real sale it goes to ${h([].concat(to).join(', '))}.</p>` + body;
      to = sandboxCtx.testerEmail;
    }
    subject = '[TEST] ' + subject;
  }
  notifier.email({ to, subject, tag, attachments, html: emailLayout({ siteUrl: site(), heading, preheader, bodyHtml: body, cta, footerNote }), text: `${heading}\n\n${preheader || ''}\n\n${cta ? cta.label + ': ' + cta.url : ''}` });
}
function alertAdmin(kind, subject, opts) {
  if (!N().adminAlerts[kind]) return;
  mail(adminRecipients(), `[Admin] ${subject}`, { ...opts, tag: `admin.${kind}`, footerNote: 'You receive this because you are a TicketJunkies administrator.' });
}

const Notify = {
  passwordReset(u, link, valid) {
    mail(u.email, 'Reset your TicketJunkies password', {
      tag: 'user.passwordReset',
      heading: 'Reset your password',
      preheader: `Choose a new password. This link works for ${valid}.`,
      body: `<p>Hi ${h(u.name.split(' ')[0])},</p><p>We got a request to reset the password for your TicketJunkies account. Tap the button below to choose a new one. The link works for <strong>${h(valid)}</strong> and can be used only once.</p><p>If you didn't ask for this, you can ignore this email. Your password won't change.</p>`,
      cta: { label: 'Choose a new password', url: link },
    });
  },
  registered(u) {
    const organizer = u.role === 'organizer';
    alertAdmin(organizer ? 'organizerSignup' : 'userSignup', organizer ? `New organizer: ${u.organization || u.name}` : `New user: ${u.name}`, {
      heading: organizer ? 'New organizer registered' : 'New user registered',
      preheader: `${u.name} (${u.email}) just created an account.`,
      body: detailTable([['Name', h(u.name)], ['Email', h(u.email)], u.phone && ['Phone', h(u.phone)], ['Account type', organizer ? 'Organizer' : 'Attendee'], organizer && ['Organizer name', h(u.organization)], ['Joined', h(when(u.createdAt))]]),
      cta: { label: 'View in Admin Panel', url: `${site()}/#/admin/user/${u.id}` },
    });
    mail(u.email, organizer ? 'Welcome to TicketJunkies — start selling tickets' : 'Welcome to TicketJunkies!', {
      tag: 'user.welcome',
      heading: `Welcome, ${u.name.split(' ')[0]}!`,
      preheader: organizer ? 'Your organizer account is ready.' : 'Your account is ready.',
      body: organizer
        ? `<p>Your organizer account for <strong>${h(u.organization)}</strong> is ready. Create your first event, set your ticket types and start selling in minutes.</p><p>We'll email you every time you sell tickets, so you always know how your events are doing.</p>`
        : '<p>Your TicketJunkies account is ready. Discover parties, concerts, comedy shows and festivals, and get your tickets in seconds.</p>',
      cta: organizer ? { label: 'Create an event', url: `${site()}/#/create` } : { label: 'Browse events', url: site() },
    });
  },

  eventCreated(ev, actor) {
    const p = organizerOf(ev);
    const live = ev.status === 'published';
    alertAdmin('eventCreated', `New event: ${ev.title}`, {
      heading: 'New event created',
      preheader: `${(ev?.organizerName || p?.organization || p?.name)} created "${ev.title}".`,
      body: detailTable([...evRows(ev), ['Organizer', `${h((ev?.organizerName || p?.organization || p?.name))}<br><span style="color:#6b7280;font-weight:400">${h(p?.email)}</span>`], ['Status', live ? 'Published' : 'Draft'], ['Tickets', ev.tiers.map((t) => `${h(t.name)} — ${t.price ? money(t.price) : 'Free'} × ${t.quantity}`).join('<br>')]]),
      cta: { label: 'Review event', url: eventLink(ev) },
    });
    if (p) mail(p.email, live ? `Your event is live: ${ev.title}` : `Draft saved: ${ev.title}`, {
      tag: 'organizer.eventCreated',
      heading: live ? 'Your event is live! 🎉' : 'Your event draft is saved',
      preheader: live ? 'Tickets are now on sale.' : 'Publish it when you are ready to sell.',
      body: `<p>${live ? 'Tickets for your event are now on sale on TicketJunkies.com. Share the link with your fans!' : 'Your event is saved as a draft and is only visible to you. Publish it from your dashboard to start selling.'}</p>${detailTable(evRows(ev))}${actor && actor.id !== ev.organizerId ? '<p style="color:#6b7280">This event was created for you by a TicketJunkies administrator.</p>' : ''}`,
      cta: live ? { label: 'View event page', url: eventLink(ev) } : { label: 'Open dashboard', url: `${site()}/#/dashboard` },
    });
  },

  eventChanged(ev, before, actor) {
    const p = organizerOf(ev);
    if (!p) return;
    const byAdmin = actor && actor.id !== ev.organizerId;
    const statusMsg = { published: ['Your event is live', 'Tickets are now on sale.'], draft: ['Your event was unpublished', 'It is hidden from the public and tickets are not on sale.'], cancelled: ['Your event was cancelled', 'Ticket sales have stopped.'] };
    if (before.status !== ev.status) {
      const [heading, msg] = statusMsg[ev.status];
      mail(p.email, `${heading}: ${ev.title}`, { tag: 'organizer.eventStatus', heading, preheader: msg, body: `<p>${msg}${byAdmin ? ' This change was made by a TicketJunkies administrator.' : ''}</p>${detailTable(evRows(ev))}`, cta: { label: 'Open dashboard', url: `${site()}/#/dashboard` } });
      if (ev.status === 'cancelled') Notify.eventCancelledBuyers(ev);
    } else {
      mail(p.email, `Event updated: ${ev.title}`, { tag: 'organizer.eventUpdated', heading: 'Your event was updated', preheader: `Changes to "${ev.title}" were saved.`, body: `<p>Changes to your event were saved${byAdmin ? ' by a TicketJunkies administrator' : ''}.</p>${detailTable(evRows(ev))}`, cta: { label: 'View event page', url: eventLink(ev) } });
    }
  },

  eventDeleted(ev, actor) {
    const p = organizerOf(ev);
    if (p) mail(p.email, `Event deleted: ${ev.title}`, { tag: 'organizer.eventDeleted', heading: 'Your event was deleted', preheader: `"${ev.title}" has been removed.`, body: `<p>"${h(ev.title)}" was deleted${actor && actor.id !== ev.organizerId ? ' by a TicketJunkies administrator' : ''}. It had no ticket sales.</p>`, cta: { label: 'Open dashboard', url: `${site()}/#/dashboard` } });
  },

  eventCancelledBuyers(ev) {
    for (const o of db.orders.filter((x) => x.eventId === ev.id && x.status !== 'refunded' && !x.test)) {
      mail(o.buyerEmail, `Event cancelled: ${ev.title}`, { tag: 'buyer.eventCancelled', heading: 'Your event has been cancelled', preheader: `"${ev.title}" will not go ahead.`, body: `<p>Hi ${h(o.buyerName.split(' ')[0])}, we're sorry — the organizer has cancelled this event. Your order (${o.tickets.length} ticket${o.tickets.length > 1 ? 's' : ''}, ${money(o.total)}) will be refunded.</p>${detailTable(evRows(ev))}` });
      if (o.buyerPhone) notifier.sms({ to: o.buyerPhone, tag: 'buyer.eventCancelled', body: `TicketJunkies: "${ev.title}" on ${when(ev.startsAt)} has been CANCELLED. Your order of ${money(o.total)} will be refunded.` });
    }
  },

  // opts: { buyerOnly, email, sms } — buyerOnly is used when a customer asks to resend their tickets
  orderPlaced(o, ev, opts = {}) {
    const sendEmail = opts.email !== false, sendSms = opts.sms !== false;
    const p = organizerOf(ev);
    const qty = o.tickets.length;
    const itemsHtml = o.items.map((i) => `${i.qty} × ${h(i.tierName)} (${o.comp ? 'Complimentary' : i.price ? money(i.price) : 'Free'})`).join('<br>');

    // Buyer: email with a QR code for every ticket
    const qrBlock = (t) => `
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px;border:2px dashed #eb742a;border-radius:14px"><tr>
        <td style="padding:14px;width:170px" valign="middle"><img src="${site()}/qr/${t.code}.png" width="160" height="160" alt="QR code ${t.code}" style="display:block;width:160px;height:160px;border:0"></td>
        <td style="padding:14px 14px 14px 0" valign="middle">
          <div style="font-size:12px;color:#eb742a;font-weight:800;text-transform:uppercase">${h(t.tierName)}</div>
          <div style="font-size:17px;font-weight:800;margin:4px 0">${h(ev.title)}</div>
          <div style="font-size:13px;color:#6b7280">${h(when(ev.startsAt))}<br>${h(ev.venue)}, ${h(ev.city)}</div>
          <div style="font-family:Menlo,Consolas,monospace;font-size:18px;font-weight:800;color:#376eb8;margin-top:8px;letter-spacing:1px">${t.code}</div>
          ${t.holderName ? `<div style="font-size:13px;font-weight:700;margin-top:6px">👤 ${h(t.holderName)}</div>` : ''}
        </td></tr></table>`;
    const qrBlocks = o.tickets.map(qrBlock).join('');
    const pngs = (list) => list.map((t) => ({ filename: `${t.code}.png`, content: qrPng(t.code, 10) }));
    const buyerMail = String(o.buyerEmail || '').toLowerCase();
    // Tickets for other people with their own email address, grouped per person
    const guests = new Map();
    if (!o.comp) for (const t of o.tickets) if (t.holderEmail && t.holderEmail !== buyerMail) { const g = guests.get(t.holderEmail) || { name: t.holderName, email: t.holderEmail, tickets: [] }; g.tickets.push(t); guests.set(t.holderEmail, g); }
    if (o.comp) {
      const from = h((ev?.organizerName || p?.organization || p?.name) || 'The organizer');
      if (sendEmail) mail(o.buyerEmail, `Complimentary tickets: ${ev.title}`, {
        tag: 'buyer.comp',
        heading: "You're on the list! 🎟️",
        preheader: `${from} sent you ${qty} complimentary ticket${qty > 1 ? 's' : ''} for ${ev.title}.`,
        body: `<p>Hi ${h(o.buyerName.split(' ')[0])}, <strong>${from}</strong> has sent you ${qty} complimentary ticket${qty > 1 ? 's' : ''} — no payment needed. Show the QR code below at the door, on your phone or printed. Each ticket admits one person and can be scanned once.</p>
          ${o.compNote ? `<p style="background:#fdeee4;border-radius:10px;padding:12px 14px;margin:0 0 14px"><strong>Message:</strong> ${h(o.compNote)}</p>` : ''}
          ${qrBlocks}
          ${detailTable([...evRows(ev), ['Tickets', itemsHtml], ['Price', '<strong>Complimentary — $0.00</strong>'], ['Order #', h(o.id.slice(-8).toUpperCase())]])}
          <p style="font-size:13px;color:#6b7280">QR codes are also attached as images, and you can always open your tickets from the button below.</p>`,
        cta: { label: 'View my tickets', url: ticketLink(o) },
        attachments: o.tickets.map((t) => ({ filename: `${t.code}.png`, content: qrPng(t.code, 10) })),
      });
      if (sendSms && o.buyerPhone) notifier.sms({ to: o.buyerPhone, tag: 'buyer.comp', body: `TicketJunkies: ${(ev?.organizerName || p?.organization || p?.name) || 'The organizer'} sent you ${qty} complimentary ticket${qty > 1 ? 's' : ''} for ${ev.title}, ${when(ev.startsAt)} @ ${ev.venue}. Your QR tickets: ${ticketLink(o)}` });
      return; // comps are not sales: no sale emails to the organizer or admin
    }
    if (sendEmail) mail(o.buyerEmail, `Your tickets: ${ev.title}`, {
      tag: 'buyer.tickets',
      heading: "You're going! 🎟️",
      preheader: `${qty} ticket${qty > 1 ? 's' : ''} for ${ev.title} — show the QR code at the door.`,
      body: `<p>Hi ${h(o.buyerName.split(' ')[0])}, thanks for your order! Show the QR code below at the door — on your phone or printed. Each ticket admits one person and can be scanned once.</p>
        ${qrBlocks}
        <p style="margin:0 0 16px"><a href="${orderPdfLink(o)}" style="display:inline-block;background:#376eb8;color:#ffffff;font-weight:700;text-decoration:none;padding:10px 16px;border-radius:10px">⬇ Download tickets (PDF)</a></p>
        ${guests.size ? `<p style="background:#eef7e9;border-radius:10px;padding:12px 14px;margin:0 0 14px">📧 We also emailed each guest their own ticket: ${[...guests.values()].map((g) => `<strong>${h(g.name)}</strong> (${h(g.email)})`).join(', ')}.</p>` : ''}
        ${detailTable([...evRows(ev), ['Tickets', itemsHtml], ['Subtotal', money(o.subtotal)], ['Service fees', money(o.fees)], ['Total paid', `<strong>${money(o.total)}</strong>${o.cardLast4 ? ` <span style="color:#6b7280;font-weight:400">(card •••• ${h(o.cardLast4)})</span>` : ''}`], ['Order #', h(o.id.slice(-8).toUpperCase())]])}
        <p style="font-size:13px;color:#6b7280">Your tickets are attached as a PDF (easy to print, save or share) and as QR images. You can always open them from the button below.</p>`,
      cta: { label: 'View my tickets', url: ticketLink(o) },
      attachments: [pdfAttachment(o, ev, o.tickets), ...pngs(o.tickets)],
    });

    // Buyer: text message
    if (sendSms && o.buyerPhone) notifier.sms({ to: o.buyerPhone, tag: 'buyer.tickets', body: `TicketJunkies: You're going to ${ev.title}! ${qty} ticket${qty > 1 ? 's' : ''}, ${when(ev.startsAt)} @ ${ev.venue}. Order ${o.id.slice(-8).toUpperCase()}, total ${money(o.total)}. Your QR tickets: ${ticketLink(o)}` });
    if (opts.buyerOnly) return;

    // Each guest: their own ticket(s) + a PDF they can download or share
    const buyerFirst = h(o.buyerName.split(' ')[0]);
    for (const g of guests.values()) {
      const n = g.tickets.length;
      mail(g.email, `Your ticket${n > 1 ? 's' : ''}: ${ev.title}`, {
        tag: 'buyer.attendee',
        heading: `You're going, ${g.name.split(' ')[0]}! 🎟️`,
        preheader: `${o.buyerName} got you ${n > 1 ? n + ' tickets' : 'a ticket'} for ${ev.title}.`,
        body: `<p>Hi ${h(g.name.split(' ')[0])}, <strong>${h(o.buyerName)}</strong> got you ${n > 1 ? n + ' tickets' : 'a ticket'} for <strong>${h(ev.title)}</strong>. Show the QR code below at the door, on your phone or printed. Each ticket admits one person and can be scanned once.</p>
          ${g.tickets.map(qrBlock).join('')}
          <p style="margin:0 0 16px">${g.tickets.map((t) => `<a href="${ticketPdfLink(o, t)}" style="display:inline-block;background:#376eb8;color:#ffffff;font-weight:700;text-decoration:none;padding:10px 16px;border-radius:10px;margin:0 6px 6px 0">⬇ Download ticket ${h(t.code)} (PDF)</a>`).join('')}</p>
          ${detailTable([...evRows(ev), ['Ticket', g.tickets.map((t) => h(t.tierName)).join('<br>')], ['From', `${buyerFirst} (${h(o.buyerEmail)})`]])}
          <p style="font-size:13px;color:#6b7280">Your ticket is attached as a PDF you can save, print or share, plus a QR image.</p>`,
        cta: { label: `Download my ticket${n > 1 ? 's' : ''} (PDF)`, url: ticketPdfLink(o, g.tickets[0]) },
        attachments: [pdfAttachment(o, ev, g.tickets, n > 1 ? `${o.id.slice(-8).toUpperCase()}-${g.name.replace(/[^\w]+/g, '-').slice(0, 20)}` : g.tickets[0].code), ...pngs(g.tickets)],
      });
    }

    // Organizer: sale notification + sold-out alerts
    const buyerCell = `<strong>${h(o.buyerName)}</strong><br><span style="color:#6b7280;font-weight:400">${h(o.buyerEmail)}${o.buyerPhone ? '<br>' + h(o.buyerPhone) : ''}</span>`;
    if (p) {
      const stats = eventStats(ev);
      mail(p.email, `🎟️ ${qty} ticket${qty > 1 ? 's' : ''} sold — ${ev.title}`, {
        tag: 'organizer.sale',
        heading: `You sold ${qty} ticket${qty > 1 ? 's' : ''}!`,
        preheader: `${o.buyerName} bought ${qty} ticket${qty > 1 ? 's' : ''} for ${ev.title}.`,
        body: `${detailTable([['Event', `<strong>${h(ev.title)}</strong>`], ['When', h(when(ev.startsAt))], ['Order #', `${h(o.id.slice(-8).toUpperCase())} · ${h(when(o.createdAt))}`], ['Buyer', buyerCell], ['Tickets', itemsHtml], ['Your revenue (this order)', `<strong>${money(o.subtotal)}</strong>`], ['Total sold', `${stats.sold} / ${stats.capacity}`], ['Total revenue to date', money(stats.revenue)]])}${attendeeTable(o)}`,
        cta: { label: 'View attendees', url: `${site()}/#/attendees/${ev.id}` },
      });
      for (const it of o.items) {
        const tier = ev.tiers.find((t) => t.id === it.tierId);
        if (tier && tier.sold >= tier.quantity) mail(p.email, `Sold out: ${tier.name} — ${ev.title}`, { tag: 'organizer.soldOut', heading: `"${tier.name}" is sold out! 🔥`, preheader: `All ${tier.quantity} ${tier.name} tickets are gone.`, body: `<p>All <strong>${tier.quantity}</strong> "${h(tier.name)}" tickets for <strong>${h(ev.title)}</strong> have been sold. You can add more tickets or a new ticket type from your dashboard.</p>`, cta: { label: 'Edit event', url: `${site()}/#/edit/${ev.id}` } });
      }
    }

    // Admin: sale alert
    alertAdmin('ticketSale', `Sale: ${qty} × ${ev.title} (${money(o.total)})`, {
      heading: 'New ticket sale',
      preheader: `${o.buyerName} paid ${money(o.total)} for ${ev.title}.`,
      body: detailTable([
        ...evRows(ev),
        ['Organizer', `${h((ev?.organizerName || p?.organization || p?.name) || '')}${p ? `<br><span style="color:#6b7280;font-weight:400">${h(p.email)}${p.phone ? ' · ' + h(p.phone) : ''}</span>` : ''}`],
        ['Order #', `${h(o.id.slice(-8).toUpperCase())} · ${h(when(o.createdAt))}`],
        ['Buyer', buyerCell],
        ['Tickets', itemsHtml],
        ['Ticket sales', money(o.subtotal)],
        ['Platform fees (yours)', `<strong>${money(o.fees)}</strong>`],
        ['Total collected', `<strong>${money(o.total)}</strong>`],
        o.cardLast4 && ['Payment', `${h(o.cardBrand || 'Card')} •••• ${h(o.cardLast4)}${o.cardHolder ? ` · ${h(o.cardHolder)}` : ''}`],
        guests.size && ['Guest emails sent', String(guests.size)],
      ]) + attendeeTable(o),
      cta: { label: 'View orders', url: `${site()}/#/admin/orders` },
    });
  },

  orderRefunded(o) {
    const ev = db.events.find((e) => e.id === o.eventId);
    const title = ev ? ev.title : 'your event';
    mail(o.buyerEmail, `Refund issued: ${title}`, { tag: 'buyer.refund', heading: 'Your order was refunded', preheader: `${money(o.total)} is on its way back to you.`, body: `<p>Hi ${h(o.buyerName.split(' ')[0])}, your order for <strong>${h(title)}</strong> has been refunded (${money(o.total)}). The tickets on this order are no longer valid.</p>${detailTable([['Order #', h(o.id.slice(-8).toUpperCase())], ['Tickets', o.tickets.map((t) => t.code).join(', ')]])}` });
    if (o.buyerPhone) notifier.sms({ to: o.buyerPhone, tag: 'buyer.refund', body: `TicketJunkies: Your order ${o.id.slice(-8).toUpperCase()} for ${title} was refunded (${money(o.total)}). Those tickets are no longer valid.` });
    const p = ev && organizerOf(ev);
    if (p) mail(p.email, `Order refunded — ${title}`, { tag: 'organizer.refund', heading: 'An order was refunded', preheader: `${o.tickets.length} ticket(s) went back on sale.`, body: `<p>A TicketJunkies administrator refunded an order for <strong>${h(title)}</strong>. The ${o.tickets.length} ticket${o.tickets.length > 1 ? 's are' : ' is'} back on sale.</p>${detailTable([['Buyer', `${h(o.buyerName)} · ${h(o.buyerEmail)}`], ['Tickets', o.items.map((i) => `${i.qty} × ${h(i.tierName)}`).join('<br>')], ['Amount removed from your sales', money(o.subtotal)]])}`, cta: { label: 'Open dashboard', url: `${site()}/#/dashboard` } });
  },
};

// ---------- card helpers ----------
function luhnOk(num) {
  let sum = 0, alt = false;
  for (let i = num.length - 1; i >= 0; i--) {
    let d = num.charCodeAt(i) - 48;
    if (alt) { d *= 2; if (d > 9) d -= 9; }
    sum += d; alt = !alt;
  }
  return sum % 10 === 0;
}
function cardBrandOf(num) {
  if (/^4/.test(num)) return 'Visa';
  if (/^(5[1-5]|2(2[2-9][1-9]|2[3-9]\d|[3-6]\d\d|7[01]\d|720))/.test(num)) return 'Mastercard';
  if (/^3[47]/.test(num)) return 'Amex';
  if (/^(6011|65|64[4-9])/.test(num)) return 'Discover';
  if (/^35(2[89]|[3-8])/.test(num)) return 'JCB';
  if (/^3(0[0-5]|[68])/.test(num)) return 'Diners';
  return 'Card';
}
// Same card → same fingerprint, but the number can't be recovered from it.
function cardInfo(num, holder) {
  return {
    cardLast4: num.slice(-4), cardBrand: cardBrandOf(num), cardHolder: holder || '',
    cardFingerprint: 'fp_' + crypto.createHmac('sha256', db.settings.cardHashKey).update(num).digest('hex').slice(0, 20),
  };
}

// ---------- Google reCAPTCHA ----------
function captchaPublic() {
  const c = db.settings.captcha;
  const on = c.enabled && c.siteKey && c.secretKey;
  return { siteKey: on ? c.siteKey : '', register: !!(on && c.protectRegister), checkout: !!(on && c.protectCheckout), testKeys: c.siteKey === RECAPTCHA_TEST_SITE };
}
const clientIp = (req) => String(req.headers['x-forwarded-for'] || '').split(',')[0].trim() || req.socket.remoteAddress || '';
async function requireCaptcha(req, body, where) {
  const pub = captchaPublic();
  if (!pub[where]) return;
  const token = typeof body.captcha === 'string' ? body.captcha : '';
  if (!token || token.length > 4000) fail(400, 'Please tick "I\'m not a robot" to continue');
  let result;
  try {
    const res = await fetch('https://www.google.com/recaptcha/api/siteverify', {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ secret: db.settings.captcha.secretKey, response: token, remoteip: clientIp(req) }),
      signal: AbortSignal.timeout(8000),
    });
    result = await res.json();
  } catch (e) {
    // If Google can't be reached, don't block real customers — log it and continue.
    console.error('[captcha] verification service unreachable, allowing request:', e.message);
    return;
  }
  if (!result.success) {
    const codes = result['error-codes'] || [];
    if (codes.includes('invalid-input-secret')) console.error('[captcha] secret key rejected by Google — check Admin → Settings');
    fail(400, codes.includes('timeout-or-duplicate') ? 'The robot check expired. Please tick "I\'m not a robot" again.' : 'Robot check failed. Please try again.');
  }
}

// ---------- request plumbing ----------
function readBody(req, limit = MAX_BODY) {
  return new Promise((resolve, reject) => {
    let size = 0; const chunks = [];
    req.on('data', (c) => {
      size += c.length;
      if (size > limit) { reject(new HttpError(413, limit >= MAX_BODY ? 'Upload too large (max 8MB)' : 'Request too large')); req.destroy(); return; }
      chunks.push(c);
    });
    req.on('end', () => {
      if (!chunks.length) return resolve({});
      try { resolve(JSON.parse(Buffer.concat(chunks).toString('utf8'))); }
      catch { reject(new HttpError(400, 'Invalid JSON body')); }
    });
    req.on('error', reject);
  });
}
// Picks the best compression the browser supports (Brotli, then gzip).
function pickEncoding(req) {
  const ae = String(req?.headers?.['accept-encoding'] || '');
  return /\bbr\b/.test(ae) ? 'br' : /\bgzip\b/.test(ae) ? 'gzip' : null;
}
function compress(buf, enc) {
  return enc === 'br'
    ? zlib.brotliCompressSync(buf, { params: { [zlib.constants.BROTLI_PARAM_QUALITY]: 5 } })
    : zlib.gzipSync(buf, { level: 6 });
}
const SECURITY_HEADERS = { 'X-Content-Type-Options': 'nosniff', 'Referrer-Policy': 'strict-origin-when-cross-origin' };
// Sent with every response: stop other sites framing the pages (clickjacking), switch off browser features the
// site never uses, and keep plugins/<base> tricks out. Camera (QR check-in) and location (weather) stay allowed for this site.
const BASE_HEADERS = {
  ...SECURITY_HEADERS,
  'X-Frame-Options': 'SAMEORIGIN',
  'Permissions-Policy': 'camera=(self), geolocation=(self), microphone=(), payment=(), usb=()',
  'Cross-Origin-Opener-Policy': 'same-origin-allow-popups',
  'Content-Security-Policy': "object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'",
};
// Simple per-visitor speed limits for the forms bots like to hammer (log in, sign up, password reset, uploads, checkout).
const RATE_LIMITS = {
  'POST /api/auth/login': [20, 10 * 60000],
  'POST /api/auth/register': [10, 60 * 60000],
  'POST /api/auth/forgot': [5, 15 * 60000],
  'POST /api/auth/reset': [10, 15 * 60000],
  'POST /api/upload': [60, 60 * 60000],
  'POST /api/cart/checkout': [20, 10 * 60000],
};
const rateHits = new Map(); // "rule|ip" -> [timestamps]
function rateLimit(req, rule, [max, windowMs]) {
  const key = rule + '|' + clientIp(req);
  const now = Date.now();
  const hits = (rateHits.get(key) || []).filter((t) => now - t < windowMs);
  if (hits.length >= max) fail(429, `Too many attempts. Please wait ${Math.max(1, Math.ceil((windowMs - (now - hits[0])) / 60000))} minute(s) and try again.`);
  hits.push(now);
  rateHits.set(key, hits);
  if (rateHits.size > 20000) rateHits.clear();
}
// Real image check: the file's first bytes must match its type (a renamed script or web page is refused).
function imageBytesOk(buf, type) {
  if (type === 'png') return buf.length > 8 && buf[0] === 0x89 && buf.slice(1, 4).toString('latin1') === 'PNG';
  if (type === 'jpg' || type === 'jpeg') return buf.length > 3 && buf[0] === 0xff && buf[1] === 0xd8 && buf[2] === 0xff;
  if (type === 'gif') return buf.length > 6 && buf.slice(0, 4).toString('latin1') === 'GIF8';
  if (type === 'webp') return buf.length > 12 && buf.slice(0, 4).toString('latin1') === 'RIFF' && buf.slice(8, 12).toString('latin1') === 'WEBP';
  return false;
}
function send(res, status, obj) {
  let body = Buffer.from(JSON.stringify(obj));
  const headers = { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'Vary': 'Accept-Encoding', ...SECURITY_HEADERS };
  const enc = body.length > 1024 ? pickEncoding(res.req) : null;
  if (enc) { body = compress(body, enc); headers['Content-Encoding'] = enc; }
  headers['Content-Length'] = body.length;
  res.writeHead(status, headers);
  res.end(body);
}

// ---------- API routes ----------
const routes = [];
const route = (method, pattern, handler) => {
  const keys = [];
  const re = new RegExp('^' + pattern.replace(/:(\w+)/g, (_, k) => { keys.push(k); return '([^/]+)'; }) + '$');
  routes.push({ method, re, keys, handler });
};

route('GET', '/api/meta', () => {
  const m = db.settings.maps;
  return {
    categories: CATEGORIES, feePercent: db.settings.feePercent, feeFixed: db.settings.feeFixed,
    maps: { googleMapsKey: m.googleMapsKey, center: { lat: m.weatherLat, lng: m.weatherLng }, country: m.country || '' },
    captcha: captchaPublic(),
    preloader: db.settings.preloader,
    security: { idleMinutes: SEC().idleMinutes, allowReset: SEC().allowReset, minPassword: SEC().minPassword },
    weatherPlace: { name: m.weatherPlace, lat: m.weatherLat, lng: m.weatherLng },
  };
});

// Weather forecast for any location (cached 30 min per spot)
route('GET', '/api/weather', async ({ query }) => {
  const lat = Number(query.get('lat')), lng = Number(query.get('lng'));
  if (!validLatLng(lat, lng)) fail(400, 'Invalid location');
  try { return { forecast: await forecast(lat, lng) }; }
  catch (e) { fail(503, 'Weather is temporarily unavailable'); }
});

// Address lookup for the event form's map pin
route('GET', '/api/geocode', async ({ req, query }) => {
  requireOrganizer(req);
  const hit = await geocodePlace({ venue: query.get('venue') || '', address: query.get('address') || '', city: query.get('city') || '', country: db.settings.maps.country });
  if (!hit) fail(404, "Couldn't find that address — drag the pin to the venue instead");
  return { result: hit };
});

route('POST', '/api/auth/register', async ({ req, body, res }) => {
  await requireCaptcha(req, body, 'register');
  const name = str(body.name, { name: 'Name', max: 80, required: true });
  const email = str(body.email, { name: 'Email', max: 160, required: true }).toLowerCase();
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) fail(400, 'Enter a valid email address');
  const password = typeof body.password === 'string' ? body.password : '';
  checkPassword(password);
  const role = ['organizer', 'promoter'].includes(body.role) ? 'organizer' : 'user';
  const organization = str(body.organization, { name: 'Organizer name', max: 100, required: role === 'organizer' });
  if (db.users.some((u) => u.email === email)) fail(409, 'An account with this email already exists');
  const phone = normalizePhone(body.phone);
  if (phone === null) fail(400, 'Enter a valid mobile number, e.g. 242-555-1234 or +1 242 555 1234');
  if (!phone) fail(400, 'Mobile number is required');
  const user = { id: newId('usr'), name, email, phone, passwordHash: hashPassword(password), role, organization, suspended: false, createdAt: new Date().toISOString() };
  db.users.push(user);
  // Complimentary tickets sent to this email before the person had an account now appear in their dashboard.
  for (const o of db.orders) if (!o.userId && String(o.buyerEmail || '').toLowerCase() === email) o.userId = user.id;
  startSession(res, user);
  saveDb();
  Notify.registered(user);
  return { user: publicUser(user) };
});

route('POST', '/api/auth/login', ({ body, res }) => {
  const email = str(body.email, { name: 'Email', max: 160, required: true }).toLowerCase();
  const user = db.users.find((u) => u.email === email);
  const sec = SEC(), now = Date.now();
  let lf = loginFails.get(email);
  if (lf && lf.until && lf.until <= now) { loginFails.delete(email); lf = null; }
  const lockMsg = (until) => `Too many wrong tries. Please wait ${Math.max(1, Math.ceil((until - Date.now()) / 60000))} minute(s) and try again${sec.allowReset ? ', or reset your password' : ''}.`;
  if (sec.maxAttempts && lf && lf.until) fail(429, lockMsg(lf.until));
  if (!user || !verifyPassword(String(body.password || ''), user.passwordHash)) {
    if (sec.maxAttempts) {
      lf = lf || { count: 0, until: 0 };
      lf.count++;
      if (loginFails.size > 5000) loginFails.clear();
      loginFails.set(email, lf);
      if (lf.count >= sec.maxAttempts) { lf.until = now + sec.lockMinutes * 60000; fail(429, lockMsg(lf.until)); }
      const left = sec.maxAttempts - lf.count;
      if (left <= 2) fail(401, `Incorrect email or password (${left} ${left === 1 ? 'try' : 'tries'} left)`);
    }
    fail(401, 'Incorrect email or password');
  }
  loginFails.delete(email);
  if (user.suspended) fail(403, 'This account has been suspended. Please contact support.');
  user.lastLoginAt = new Date().toISOString();
  for (const o of db.orders) if (!o.userId && String(o.buyerEmail || '').toLowerCase() === email) o.userId = user.id;
  startSession(res, user);
  saveDb();
  return { user: publicUser(user) };
});

route('POST', '/api/auth/logout', ({ req, res }) => {
  const token = parseCookies(req).tj_session;
  if (token) delete db.sessions[token];
  res.setHeader('Set-Cookie', 'tj_session=; HttpOnly; Path=/; SameSite=Lax; Max-Age=0');
  saveDb();
  return { ok: true };
});

route('GET', '/api/auth/me', ({ req }) => ({ user: publicUser(currentUser(req)) }));

// ---------- password reset ----------
function sendReset(u, byAdmin = false) {
  const now = Date.now();
  db.resets = db.resets.filter((r) => r.expires > now);
  if (!byAdmin && db.resets.filter((r) => r.userId === u.id).length >= 3) return false; // at most 3 open links per person
  const token = crypto.randomBytes(32).toString('hex');
  const minutes = SEC().resetMinutes;
  db.resets.push({ hash: sha256(token), userId: u.id, expires: now + minutes * 60000, createdAt: new Date().toISOString() });
  saveDb();
  Notify.passwordReset(u, `${site()}/#/reset?token=${token}`, durLabel(minutes));
  return true;
}
route('POST', '/api/auth/forgot', ({ body }) => {
  if (!SEC().allowReset) fail(403, 'Password resets are turned off. Please contact support.');
  const email = str(body.email, { name: 'Email', max: 160, required: true }).toLowerCase();
  const u = db.users.find((x) => x.email === email);
  if (u && !u.suspended) sendReset(u);
  // Same answer whether or not the account exists, so nobody can test which emails are registered.
  return { ok: true, valid: durLabel(SEC().resetMinutes) };
});
route('POST', '/api/auth/reset', ({ body, res }) => {
  const token = String(body.token || '');
  const now = Date.now();
  const r = /^[a-f0-9]{64}$/.test(token) && db.resets.find((x) => x.hash === sha256(token) && x.expires > now);
  const u = r && db.users.find((x) => x.id === r.userId);
  if (!u || u.suspended) fail(400, 'This reset link has expired or was already used. Please ask for a new one.');
  const password = String(body.password || '');
  checkPassword(password, 'New password');
  u.passwordHash = hashPassword(password);
  u.lastLoginAt = new Date().toISOString();
  db.resets = db.resets.filter((x) => x.userId !== u.id);
  killSessions(u.id);
  loginFails.delete(u.email);
  startSession(res, u);
  saveDb();
  return { user: publicUser(u) };
});

// ---------- homepage search filters (organizer, venue, date, year, time of day) ----------
const SITE_TZ = 'America/Nassau';
const siteDtf = new Intl.DateTimeFormat('en-CA', { timeZone: SITE_TZ, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', hourCycle: 'h23' });
function siteParts(iso) {
  const p = Object.fromEntries(siteDtf.formatToParts(new Date(iso)).map((x) => [x.type, x.value]));
  return { date: `${p.year}-${p.month}-${p.day}`, year: p.year, hour: Number(p.hour) % 24 };
}
const TIME_SLOTS = { morning: (h) => h >= 5 && h < 12, afternoon: (h) => h >= 12 && h < 17, evening: (h) => h >= 17 && h < 21, night: (h) => h >= 21 || h < 5 };
function searchFilters(query) {
  const date = /^\d{4}-\d{2}-\d{2}$/.test(query.get('date') || '') ? query.get('date') : '';
  const year = /^\d{4}$/.test(query.get('year') || '') ? query.get('year') : '';
  const time = TIME_SLOTS[query.get('time')] ? query.get('time') : '';
  return { organizer: norm(query.get('organizer')), venue: norm(query.get('venue')), date, year, time };
}
function matchesFilters(e, f) {
  if (f.organizer && !eventOrganizers(e).some((n) => norm(n) === f.organizer)) return false;
  if (f.venue && !eventVenueNames(e).some((n) => norm(n) === f.venue)) return false;
  if (f.date || f.year || f.time) {
    const s = siteParts(e.startsAt);
    if (f.year && s.year !== f.year) return false;
    if (f.time && !TIME_SLOTS[f.time](s.hour)) return false;
    if (f.date) { const end = siteParts(new Date(new Date(e.endsAt).getTime() - 1)).date; if (f.date < s.date || f.date > end) return false; }
  }
  return true;
}
const upcomingPublished = () => db.events.filter((e) => e.status === 'published' && new Date(e.endsAt).getTime() > Date.now());
// Choices for the search dropdowns, taken from events people can buy tickets for right now
route('GET', '/api/search-options', () => {
  const orgs = new Map(), venues = new Map(), years = new Set([String(new Date().getFullYear())]);
  for (const e of upcomingPublished()) {
    for (const n of eventOrganizers(e)) if (!orgs.has(norm(n))) orgs.set(norm(n), n);
    for (const n of eventVenueNames(e)) if (!venues.has(norm(n))) venues.set(norm(n), n);
    years.add(siteParts(e.startsAt).year);
  }
  const abc = (a, b) => a.localeCompare(b);
  return { organizers: [...orgs.values()].sort(abc), venues: [...venues.values()].sort(abc), years: [...years].sort() };
});

// Calendar of events: every published event on the days it takes place (Nassau time).
// An overnight party that ends before 6 AM only counts on the day it starts.
const siteTimeFmt = new Intl.DateTimeFormat('en-US', { timeZone: SITE_TZ, hour: 'numeric', minute: '2-digit' });
function calendarDays(e) {
  const start = siteParts(e.startsAt).date;
  let end = siteParts(new Date(new Date(e.endsAt).getTime() - 6 * 3600e3)).date;
  if (end < start) end = start;
  const out = []; const d = new Date(start + 'T00:00:00Z');
  for (let i = 0; i < 62; i++) { const k = d.toISOString().slice(0, 10); if (k > end) break; out.push(k); d.setUTCDate(d.getUTCDate() + 1); }
  return out;
}
function shortText(s, n = 170) { const t = String(s || '').replace(/\s+/g, ' ').trim(); return t.length > n ? t.slice(0, n).replace(/\s+\S*$/, '') + '…' : t; }
route('GET', '/api/calendar', async ({ req, query }) => {
  const viewer = currentUser(req);
  const today = siteParts(new Date()).date;
  const month = /^\d{4}-(0[1-9]|1[0-2])$/.test(query.get('month') || '') ? query.get('month') : today.slice(0, 7);
  const now = Date.now();
  const events = [];
  for (const e of db.events) {
    if (e.status !== 'published') continue;
    const all = calendarDays(e);
    const days = all.filter((k) => k.startsWith(month));
    if (!days.length) continue;
    const pe = publicEvent(e, viewer);
    const lp = lowestPrice(e.tiers);
    events.push({ id: e.id, title: e.title, startsAt: e.startsAt, endsAt: e.endsAt, venue: e.venue || '', city: e.city || '', address: e.address || '', lat: Number.isFinite(e.lat) ? e.lat : null, lng: Number.isFinite(e.lng) ? e.lng : null, category: e.category, sessionLabel: e.sessionLabel || '',
      time: siteTimeFmt.format(new Date(e.startsAt)), endTime: siteTimeFmt.format(new Date(e.endsAt)), startDay: all[0], days, ended: new Date(e.endsAt).getTime() < now,
      imageUrl: e.imageUrl || '', summary: shortText(e.description), organizer: pe.organizer,
      ...lp });
  }
  events.sort((a, b) => new Date(a.startsAt) - new Date(b.startsAt));
  const hol = await publicHolidays(db.settings.maps?.country || 'Bahamas', Number(month.slice(0, 4)));
  return { month, today, events, holidayCountry: hol.country, holidays: hol.holidays.filter((x) => x.date.startsWith(month)) };
});

route('GET', '/api/events', ({ req, query }) => {
  const viewer = currentUser(req);
  const q = (query.get('q') || '').toLowerCase().trim();
  const category = query.get('category') || '';
  const city = (query.get('city') || '').toLowerCase().trim();
  const filters = searchFilters(query);
  const list = upcomingPublished()
    .filter((e) => !category || e.category === category)
    .filter((e) => !city || e.city.toLowerCase().includes(city))
    .filter((e) => matchesFilters(e, filters))
    .filter((e) => {
      if (!q) return true;
      const p = db.users.find((u) => u.id === e.organizerId);
      return [e.title, e.description, e.venue, ...eventVenueNames(e), e.city, e.category, e.organizerName, p?.organization, p?.name].join(' ').toLowerCase().includes(q);
    })
    .sort((a, b) => new Date(a.startsAt) - new Date(b.startsAt));
  const seen = new Map();
  const out = [];
  for (const e of list) {
    if (e.seriesId) {
      if (seen.has(e.seriesId)) { seen.get(e.seriesId).moreDates++; continue; }
      const pe = { ...publicEvent(e, viewer), moreDates: 0 };
      seen.set(e.seriesId, pe); out.push(pe);
    } else out.push(publicEvent(e, viewer));
  }
  return { events: out };
});

route('GET', '/api/events/:id', ({ req, params }) => {
  const viewer = currentUser(req);
  const ev = db.events.find((e) => e.id === params.id);
  if (!ev || (ev.status === 'draft' && ev.organizerId !== viewer?.id && viewer?.role !== 'admin')) fail(404, 'Event not found');
  return { event: publicEvent(ev, viewer) };
});

route('POST', '/api/events', async ({ req, body }) => {
  const user = requireOrganizer(req);
  const base = { ...body, organizerName: String(body.organizerName || '').trim() || user.organization || user.name };
  if (base.schedule === 'series') {
    const sessions = Array.isArray(body.sessions) ? body.sessions : [];
    if (sessions.length < 2) fail(400, 'Add at least 2 dates/times for a recurring event (or choose One-day event)');
    if (sessions.length > 60) fail(400, 'A recurring event can have at most 60 dates/times');
    const now = new Date().toISOString();
    const seriesId = newId('ser');
    const made = sessions.map((sn, i) => {
      const label = `Date #${i + 1}`;
      if (!sn || isNaN(new Date(sn.startsAt))) fail(400, `${label}: start date/time is required`);
      if (!sn.endsAt || isNaN(new Date(sn.endsAt)) || new Date(sn.endsAt) <= new Date(sn.startsAt)) fail(400, `${label}: end time must be after the start time`);
      const data = validateEvent({ ...base, startsAt: sn.startsAt, endsAt: sn.endsAt, sessionLabel: sn.label || '' }, null);
      return { id: newId('evt'), organizerId: user.id, ...data, seriesId, createdAt: now };
    });
    const seen = new Set();
    for (const e of made) { if (seen.has(e.startsAt)) fail(400, 'Two dates/times start at exactly the same moment. Change or remove one.'); seen.add(e.startsAt); }
    made.sort((a, b) => new Date(a.startsAt) - new Date(b.startsAt));
    db.events.push(...made);
    syncDirectory(made[0], user);
    saveDb();
    await withTimeout(ensureGeo(made[0]), 10000);
    const first = db.events.find((e) => e.id === made[0].id) || made[0];
    if (validLatLng(first.lat, first.lng)) { for (const e of made) { if (e.id === first.id) continue; e.lat = first.lat; e.lng = first.lng; e.geoPrecision = first.geoPrecision; } saveDb(); }
    Notify.eventCreated(first, user);
    return { event: publicEvent(first, user), created: made.length };
  }
  if (base.schedule === 'series') base.schedule = 'single';
  const data = validateEvent(base, null);
  const ev = { id: newId('evt'), organizerId: user.id, ...data, createdAt: new Date().toISOString() };
  db.events.push(ev);
  syncDirectory(ev, user);
  saveDb();
  await withTimeout(ensureGeo(ev), 10000);
  Notify.eventCreated(ev, user);
  return { event: publicEvent(ev, user), created: 1 };
});

route('PUT', '/api/events/:id', async ({ req, body, params }) => {
  const user = requireOrganizer(req);
  const ev = ownedEvent(user, params.id);
  const merged = { ...publicEvent(ev, user), tiers: ev.tiers, ...body };
  if (body.organizerName !== undefined && body.organizers === undefined) merged.organizers = [body.organizerName];
  const moved = ['venue', 'address', 'city'].some((k) => body[k] !== undefined && String(body[k]).trim() !== ev[k]);
  if (moved && body.lat === undefined) { merged.lat = null; merged.lng = null; }
  const before = { status: ev.status };
  Object.assign(ev, validateEvent(merged, ev), { updatedAt: new Date().toISOString() });
  syncDirectory(ev, user);
  saveDb();
  await withTimeout(ensureGeo(ev), 10000);
  Notify.eventChanged(ev, before, user);
  return { event: publicEvent(ev, user) };
});

route('DELETE', '/api/events/:id', ({ req, params }) => {
  const user = requireOrganizer(req);
  const ev = ownedEvent(user, params.id);
  if (db.orders.some((o) => o.eventId === ev.id && !o.test)) {
    const before = { status: ev.status };
    ev.status = 'cancelled';
    saveDb();
    if (before.status !== 'cancelled') Notify.eventChanged(ev, before, user);
    return { ok: true, cancelled: true, message: 'Event has ticket sales, so it was cancelled instead of deleted.' };
  }
  db.events = db.events.filter((e) => e.id !== ev.id);
  if (db.guests) db.guests = db.guests.filter((g) => g.eventId !== ev.id);
  saveDb();
  Notify.eventDeleted(ev, user);
  return { ok: true, deleted: true };
});

// Optional name + email for each ticket ("Who's coming?"). Each person with an email gets their own ticket email + PDF.
function ticketHolders(list, count) {
  const att = Array.isArray(list) ? list : [];
  const out = [];
  for (let i = 0; i < count; i++) {
    const a = att[i] && typeof att[i] === 'object' ? att[i] : {};
    const name = str(a.name, { name: `Name for ticket ${i + 1}`, max: 80 });
    const email = str(a.email, { name: `Email for ticket ${i + 1}`, max: 160 }).toLowerCase();
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) fail(400, `Enter a valid email for ticket ${i + 1}`);
    if (email && !name) fail(400, `Enter a name for ticket ${i + 1}`);
    out.push(name ? { holderName: name, ...(email ? { holderEmail: email } : {}) } : {});
  }
  return out;
}

route('POST', '/api/events/:id/checkout', async ({ req, body, params }) => {
  const user = requireUser(req);
  // Verified first: everything after this point runs without pausing, so ticket counts can't be oversold.
  await requireCaptcha(req, body, 'checkout');
  const ev = db.events.find((e) => e.id === params.id);
  const order = placeOrder(user, ev, body);
  Notify.orderPlaced(order, ev);
  return { order: orderView(order) };
});

// Shopping cart: tickets for several events paid in one go. Every event is checked first,
// so either the whole cart is bought or nothing is (no awaits in between, so nothing can sell out halfway).
route('POST', '/api/cart/checkout', async ({ req, body }) => {
  const user = requireUser(req);
  const carts = (Array.isArray(body.carts) ? body.carts : []).filter((c) => c && typeof c === 'object');
  if (!carts.length) fail(400, 'Your cart is empty');
  if (carts.length > 20) fail(400, 'Your cart can hold tickets for up to 20 events at a time');
  const ids = carts.map((c) => String(c.eventId || ''));
  if (new Set(ids).size !== ids.length) fail(400, 'Each event can only be in your cart once');
  await requireCaptcha(req, body, 'checkout');
  const shared = { buyerFirstName: body.buyerFirstName, buyerLastName: body.buyerLastName, buyerName: body.buyerName, buyerEmail: body.buyerEmail, buyerPhone: body.buyerPhone, payment: body.payment };
  const jobs = carts.map((c, i) => {
    const ev = db.events.find((e) => e.id === ids[i]);
    if (!ev) fail(404, 'One of the events in your cart is no longer available. Please remove it and try again.');
    return { ev, b: { ...shared, items: c.items, attendees: c.attendees } };
  });
  for (const j of jobs) {
    try { placeOrder(user, j.ev, j.b, { dry: true }); }
    catch (e) { if (e instanceof HttpError && jobs.length > 1) e.message = `${j.ev.title}: ${e.message}`; throw e; }
  }
  const cartId = newId('cart');
  const orders = jobs.map((j) => placeOrder(user, j.ev, j.b, { cartId }));
  orders.forEach((o, i) => Notify.orderPlaced(o, jobs[i].ev));
  return { cartId, orders: orders.map(orderView) };
});

// Checks one event's tickets + buyer + payment and creates the order (dry: check only, change nothing).
function placeOrder(user, ev, body, { dry = false, cartId = '' } = {}) {
  if (!ev || ev.status !== 'published') fail(404, 'This event is not available for purchase');
  if (new Date(ev.endsAt).getTime() < Date.now()) fail(400, 'This event has already ended');
  const items = Array.isArray(body.items) ? body.items : [];
  const lines = [];
  for (const it of items) {
    const qty = Number(it.qty);
    if (!qty) continue;
    if (!Number.isInteger(qty) || qty < 0 || qty > 100) fail(400, 'You can buy up to 100 of each ticket type');
    const tier = ev.tiers.find((t) => t.id === it.tierId && isPublicTier(t)) || fail(400, 'Unknown ticket type');
    if (tier.quantity - tier.sold < qty) fail(409, `Only ${tier.quantity - tier.sold} "${tier.name}" tickets left`);
    if (tier.maxPerPerson) {
      const have = boughtBy(user, ev.id)[tier.id] || 0;
      if (have + qty > tier.maxPerPerson) fail(400, have ? `"${tier.name}" is limited to ${tier.maxPerPerson} per person and you already have ${have}` : `"${tier.name}" is limited to ${tier.maxPerPerson} per person`);
    } else if (qty > 10) fail(400, 'You can buy up to 10 of each ticket type');
    if (tierSaleState(tier) === 'soon') fail(400, `"${tier.name}" tickets are not on sale yet`);
    if (tierSaleState(tier) === 'closed') fail(400, `Sales for "${tier.name}" have ended`);
    // Donation tickets: the buyer picks the amount (per ticket), at least the organizer's minimum
    let unit = tierKind(tier) === 'free' ? 0 : tier.price;
    if (tierKind(tier) === 'donation') {
      unit = round2(Number(it.amount));
      if (!Number.isFinite(unit) || unit < tier.price) fail(400, `Enter a donation of at least ${money(tier.price)} for "${tier.name}"`);
      if (unit > 10000) fail(400, `Donations are limited to ${money(10000)} per ticket`);
    }
    lines.push({ tier, qty, unit });
  }
  if (!lines.length) fail(400, 'Select at least one ticket');

  // Attendee first + last name are both required (older clients that only send buyerName are split on the last space)
  const legacy = String(body.buyerName || '').trim().split(/\s+/);
  const buyerFirstName = str(body.buyerFirstName ?? (legacy.length > 1 ? legacy.slice(0, -1).join(' ') : legacy[0]), { name: 'Attendee first name', max: 40, required: true });
  const buyerLastName = str(body.buyerLastName ?? (legacy.length > 1 ? legacy[legacy.length - 1] : ''), { name: 'Attendee last name', max: 40, required: true });
  const buyerName = `${buyerFirstName} ${buyerLastName}`;
  const buyerEmail = str(body.buyerEmail, { name: 'Email', max: 160, required: true });
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(buyerEmail)) fail(400, 'Enter a valid email address');
  const buyerPhone = normalizePhone(body.buyerPhone);
  if (!buyerPhone) fail(400, 'Enter a valid mobile number so we can text your tickets');

  const holders = ticketHolders(body.attendees, lines.reduce((a, l) => a + l.qty, 0));

  const subtotal = round2(lines.reduce((a, l) => a + l.unit * l.qty, 0));
  const fees = round2(lines.reduce((a, l) => a + ticketFee(l.unit) * l.qty, 0));
  const total = round2(subtotal + fees);

  // DEMO PAYMENT: validates card format only. Replace with Stripe/PayPal before going live.
  // Card data: only brand, last 4, name on card and a one-way fingerprint are kept (PCI-DSS: never store full numbers or CVC).
  let card = { cardLast4: '', cardBrand: '', cardHolder: '', cardFingerprint: '' };
  if (total > 0) {
    const p = body.payment || {};
    const num = String(p.cardNumber || '').replace(/\D/g, '');
    if (num.length < 13 || num.length > 19 || !luhnOk(num)) fail(400, 'Enter a valid card number');
    const exp = String(p.expiry || '').trim();
    if (!/^(0[1-9]|1[0-2])\/\d{2}$/.test(exp)) fail(400, 'Expiry must be MM/YY');
    const [mm, yy] = exp.split('/').map(Number);
    const now = new Date();
    if (2000 + yy < now.getFullYear() || (2000 + yy === now.getFullYear() && mm < now.getMonth() + 1)) fail(400, 'This card has expired');
    if (!/^\d{3,4}$/.test(String(p.cvc || '').trim())) fail(400, 'Enter a valid CVC');
    const cardHolder = str(p.cardHolder, { name: 'Name on card', max: 80, required: true });
    card = cardInfo(num, cardHolder);
  }

  if (dry) return null;
  const tickets = [];
  for (const l of lines) {
    l.tier.sold += l.qty;
    for (let i = 0; i < l.qty; i++) {
      tickets.push({ code: 'TJ-' + crypto.randomBytes(4).toString('hex').toUpperCase(), tierId: l.tier.id, tierName: l.tier.name, price: l.unit, checkedIn: false, ...holders[tickets.length] });
    }
  }
  const order = {
    id: newId('ord'), userId: user.id, eventId: ev.id,
    items: lines.map((l) => ({ tierId: l.tier.id, tierName: l.tier.name, price: l.unit, qty: l.qty, ...(tierKind(l.tier) === 'donation' ? { donation: true } : {}) })),
    tickets, buyerName, buyerFirstName, buyerLastName, buyerEmail, buyerPhone, subtotal, fees, total, ...card, status: 'paid',
    key: crypto.randomBytes(12).toString('hex'), createdAt: new Date().toISOString(),
  };
  if (cartId) order.cartId = cartId;
  db.orders.push(order);
  if (!user.phone) user.phone = buyerPhone;
  saveDb();
  return order;
}

// Complimentary tickets: organizer (own events) or admin issues free tickets from any ticket type, public or private.
route('POST', '/api/events/:id/comp', ({ req, body, params }) => {
  const user = requireOrganizer(req);
  const ev = ownedEvent(user, params.id);
  if (ev.status === 'cancelled') fail(400, 'This event is cancelled');
  const tier = ev.tiers.find((t) => t.id === body.tierId) || fail(400, 'Choose a ticket type');
  const qty = Number(body.qty);
  if (!Number.isInteger(qty) || qty < 1 || qty > 50) fail(400, 'Quantity must be 1–50');
  if (tier.quantity - tier.sold < qty) fail(409, `Only ${tier.quantity - tier.sold} "${tier.name}" tickets left — raise its quantity first`);
  const buyerName = str(body.name, { name: 'Guest name', max: 80, required: true });
  const buyerEmail = str(body.email, { name: 'Guest email', max: 160, required: true });
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(buyerEmail)) fail(400, 'Enter a valid email address');
  const buyerPhone = body.phone ? (normalizePhone(body.phone) || fail(400, 'Enter a valid mobile number, or leave it blank')) : '';
  const compNote = str(body.note, { name: 'Message', max: 300 });
  const guest = db.users.find((u) => u.email.toLowerCase() === buyerEmail.toLowerCase());
  tier.sold += qty;
  tier.comped = (tier.comped || 0) + qty;
  const tickets = Array.from({ length: qty }, () => ({ code: 'TJ-' + crypto.randomBytes(4).toString('hex').toUpperCase(), tierId: tier.id, tierName: tier.name, price: 0, checkedIn: false }));
  const order = {
    id: newId('ord'), userId: guest ? guest.id : null, eventId: ev.id, comp: true, compNote, issuedBy: user.organization || user.name, issuedById: user.id,
    items: [{ tierId: tier.id, tierName: tier.name, price: 0, qty }],
    tickets, buyerName, buyerEmail, buyerPhone, subtotal: 0, fees: 0, total: 0,
    cardLast4: '', cardBrand: '', cardHolder: '', cardFingerprint: '', status: 'paid',
    key: crypto.randomBytes(12).toString('hex'), createdAt: new Date().toISOString(),
  };
  db.orders.push(order);
  saveDb();
  Notify.orderPlaced(order, ev);
  return { order: { ...orderView(order), link: `/#/t/${order.id}/${order.key}` }, stats: eventStats(ev) };
});

route('GET', '/api/orders', ({ req }) => {
  const user = requireUser(req);
  const orders = db.orders.filter((o) => o.userId === user.id).sort((a, b) => b.createdAt.localeCompare(a.createdAt));
  return { orders: orders.map(orderView) };
});

route('GET', '/api/orders/:id', ({ req, params }) => {
  const user = requireUser(req);
  const o = db.orders.find((x) => x.id === params.id && x.userId === user.id) || fail(404, 'Order not found');
  return { order: orderView(o) };
});

route('GET', '/api/organizer/events', ({ req }) => {
  const user = requireOrganizer(req);
  const list = db.events.filter((e) => e.organizerId === user.id).sort((a, b) => new Date(a.startsAt) - new Date(b.startsAt));
  return { events: list.map((e) => ({ ...publicEvent(e, user), tiers: e.tiers, stats: eventStats(e) })) };
});

route('GET', '/api/organizer/events/:id', ({ req, params }) => {
  const user = requireOrganizer(req);
  const ev = ownedEvent(user, params.id);
  return { event: { ...publicEvent(ev, user), tiers: ev.tiers, stats: eventStats(ev) } };
});

route('GET', '/api/organizer/events/:id/attendees', ({ req, params }) => {
  const user = requireOrganizer(req);
  const ev = ownedEvent(user, params.id);
  const attendees = [];
  for (const o of db.orders.filter((x) => x.eventId === ev.id && x.status !== 'refunded')) {
    for (const t of o.tickets) {
      attendees.push({ code: t.code, tierName: t.tierName, price: t.price, checkedIn: t.checkedIn, checkedInAt: t.checkedInAt || null, holderName: t.holderName || '', holderEmail: t.holderEmail || '', buyerName: o.buyerName, ...splitName(o), buyerEmail: o.buyerEmail, buyerPhone: o.buyerPhone || '', orderId: o.id, purchasedAt: o.createdAt, test: !!o.test, comp: !!o.comp, compNote: o.compNote || '', issuedBy: o.issuedBy || '' });
    }
  }
  return { event: { ...publicEvent(ev, user), stats: eventStats(ev) }, attendees };
});

route('POST', '/api/organizer/checkin', ({ req, body }) => {
  const user = requireOrganizer(req);
  const code = str(body.code, { name: 'Ticket code', max: 20, required: true }).toUpperCase();
  const ev = ownedEvent(user, String(body.eventId || ''));
  for (const o of db.orders.filter((x) => x.eventId === ev.id)) {
    const t = o.tickets.find((x) => x.code === code);
    if (!t) continue;
    if (o.status === 'refunded') fail(409, `Ticket ${code} was refunded and is no longer valid`);
    if (t.checkedIn) fail(409, `Ticket ${code} was already checked in`);
    t.checkedIn = true;
    t.checkedInAt = new Date().toISOString();
    saveDb();
    return { ok: true, ticket: { code, tierName: t.tierName, buyerName: t.holderName || o.buyerName } };
  }
  fail(404, `No ticket ${code} for this event`);
});

// ---------- guest list (names the organizer types in, checked in by tapping at the door) ----------
function guestOut(g) {
  return { id: g.id, name: g.name, email: g.email || '', note: g.note || '', plus: g.plus || 0, addedAt: g.addedAt, addedBy: g.addedBy || '', checkedIn: !!g.checkedIn, checkedInAt: g.checkedInAt || null };
}
function guestInput(body) {
  const name = str(body.name, { name: 'Name', max: 80, required: true });
  const email = str(body.email, { name: 'Email', max: 160 }).toLowerCase();
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) fail(400, 'Enter a valid email address (or leave it empty)');
  const note = str(body.note, { name: 'Note', max: 120 });
  const plus = Math.round(Number(body.plus) || 0);
  if (plus < 0 || plus > 20) fail(400, 'Extra guests must be between 0 and 20');
  return { name, email, note, plus };
}
route('GET', '/api/organizer/events/:id/guests', ({ req, params }) => {
  const user = requireOrganizer(req);
  const ev = ownedEvent(user, params.id);
  return { guests: (db.guests ||= []).filter((g) => g.eventId === ev.id).map(guestOut) };
});
route('POST', '/api/organizer/events/:id/guests', ({ req, params, body }) => {
  const user = requireOrganizer(req);
  const ev = ownedEvent(user, params.id);
  const list = (db.guests ||= []);
  if (list.filter((g) => g.eventId === ev.id).length >= 2000) fail(400, 'The guest list is full (2,000 names)');
  const g = { id: newId('gst'), eventId: ev.id, ...guestInput(body), addedAt: new Date().toISOString(), addedBy: user.name, checkedIn: false, checkedInAt: null };
  list.push(g);
  saveDb();
  return { guest: guestOut(g) };
});
const ownedGuest = (user, params) => {
  const ev = ownedEvent(user, params.id);
  const g = (db.guests ||= []).find((x) => x.id === params.gid && x.eventId === ev.id);
  if (!g) fail(404, 'That name is not on the guest list');
  return g;
};
route('DELETE', '/api/organizer/events/:id/guests/:gid', ({ req, params }) => {
  const user = requireOrganizer(req);
  const g = ownedGuest(user, params);
  db.guests = db.guests.filter((x) => x !== g);
  saveDb();
  return { ok: true };
});
route('POST', '/api/organizer/events/:id/guests/:gid/checkin', ({ req, params, body }) => {
  const user = requireOrganizer(req);
  const g = ownedGuest(user, params);
  if (body.undo) { g.checkedIn = false; g.checkedInAt = null; }
  else {
    if (g.checkedIn) fail(409, `${g.name} was already checked in`);
    g.checkedIn = true; g.checkedInAt = new Date().toISOString();
  }
  saveDb();
  return { guest: guestOut(g) };
});

route('POST', '/api/upload', ({ req, body }) => {
  requireOrganizer(req);
  const m = /^data:image\/(png|jpe?g|webp|gif);base64,([A-Za-z0-9+/=]+)$/.exec(String(body.dataUrl || ''));
  if (!m) fail(400, 'Upload a PNG, JPG, WEBP or GIF image');
  const buf = Buffer.from(m[2], 'base64');
  if (buf.length > 5 * 1024 * 1024) fail(413, 'Image must be 5MB or smaller');
  if (!imageBytesOk(buf, m[1])) fail(400, "That file isn't a real image. Please upload a PNG, JPG, WEBP or GIF.");
  const name = `${crypto.randomBytes(10).toString('hex')}.${m[1] === 'jpeg' ? 'jpg' : m[1]}`;
  fs.writeFileSync(path.join(UPLOADS, name), buf);
  return { url: `/uploads/${name}` };
});

// ---------- administrator ----------
const ROLES = ['user', 'organizer', 'admin'];
const paidOrders = () => db.orders.filter((o) => o.status !== 'refunded' && !o.test);

function adminUser(u) {
  const orders = paidOrders().filter((o) => o.userId === u.id);
  return {
    ...publicUser(u), createdAt: u.createdAt, lastLoginAt: u.lastLoginAt || null,
    orders: orders.length,
    ticketsBought: orders.reduce((a, o) => a + o.tickets.length, 0),
    spent: round2(orders.reduce((a, o) => a + o.total, 0)),
    events: db.events.filter((e) => e.organizerId === u.id).length,
  };
}
function adminOrder(o) {
  const u = db.users.find((x) => x.id === o.userId);
  return { ...orderView(o), account: u ? { id: u.id, name: u.name, email: u.email } : null };
}
function adminEvent(e) {
  const p = db.users.find((u) => u.id === e.organizerId);
  return { ...publicEvent(e, null), organizerId: e.organizerId, organizerEmail: p?.email || '', stats: eventStats(e), createdAt: e.createdAt };
}
const killSessions = (userId, keepToken) => {
  for (const [t, s] of Object.entries(db.sessions)) if (s.userId === userId && t !== keepToken) delete db.sessions[t];
};
const activeAdmins = () => db.users.filter((u) => u.role === 'admin' && !u.suspended);

route('GET', '/api/admin/stats', ({ req }) => {
  requireAdmin(req);
  const paid = paidOrders();
  const now = Date.now();
  const sum = (k) => round2(paid.reduce((a, o) => a + o[k], 0));
  const byRole = (r) => db.users.filter((u) => u.role === r).length;
  return {
    users: db.users.length, attendees: byRole('user'), organizers: byRole('organizer'), admins: byRole('admin'),
    suspended: db.users.filter((u) => u.suspended).length,
    events: db.events.length,
    liveEvents: db.events.filter((e) => e.status === 'published' && new Date(e.endsAt).getTime() > now).length,
    orders: paid.length, refunds: db.orders.filter((o) => o.status === 'refunded' && !o.test).length, testOrders: db.orders.filter((o) => o.test).length,
    ticketsSold: paid.reduce((a, o) => a + o.tickets.length, 0),
    grossSales: sum('subtotal'), platformFees: sum('fees'), totalCollected: sum('total'),
    recentOrders: [...db.orders].sort((a, b) => b.createdAt.localeCompare(a.createdAt)).slice(0, 8).map(adminOrder),
    recentUsers: [...db.users].sort((a, b) => b.createdAt.localeCompare(a.createdAt)).slice(0, 6).map(adminUser),
  };
});

route('GET', '/api/admin/users', ({ req, query }) => {
  requireAdmin(req);
  const q = (query.get('q') || '').toLowerCase().trim();
  const role = query.get('role') || '';
  const list = db.users
    .filter((u) => !role || (role === 'suspended' ? u.suspended : u.role === role))
    .filter((u) => !q || [u.name, u.email, u.organization].join(' ').toLowerCase().includes(q))
    .sort((a, b) => b.createdAt.localeCompare(a.createdAt));
  return { users: list.map(adminUser) };
});

route('GET', '/api/admin/users/:id', ({ req, params }) => {
  requireAdmin(req);
  const u = db.users.find((x) => x.id === params.id) || fail(404, 'User not found');
  return {
    user: adminUser(u),
    orders: db.orders.filter((o) => o.userId === u.id).sort((a, b) => b.createdAt.localeCompare(a.createdAt)).map(adminOrder),
    events: db.events.filter((e) => e.organizerId === u.id).map(adminEvent),
  };
});

route('POST', '/api/admin/users', ({ req, body }) => {
  requireAdmin(req);
  const name = str(body.name, { name: 'Name', max: 80, required: true });
  const email = str(body.email, { name: 'Email', max: 160, required: true }).toLowerCase();
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) fail(400, 'Enter a valid email address');
  if (db.users.some((u) => u.email === email)) fail(409, 'An account with this email already exists');
  const password = String(body.password || '');
  checkPassword(password);
  const role = ROLES.includes(body.role) ? body.role : 'user';
  const organization = str(body.organization, { name: 'Organizer name', max: 100 });
  const phone = normalizePhone(body.phone);
  if (phone === null) fail(400, 'Enter a valid mobile number');
  const user = { id: newId('usr'), name, email, phone, passwordHash: hashPassword(password), role, organization, suspended: false, createdAt: new Date().toISOString() };
  db.users.push(user);
  saveDb();
  return { user: adminUser(user) };
});

route('PUT', '/api/admin/users/:id', ({ req, body, params }) => {
  const admin = requireAdmin(req);
  const u = db.users.find((x) => x.id === params.id) || fail(404, 'User not found');
  const self = u.id === admin.id;
  const next = {
    name: body.name !== undefined ? str(body.name, { name: 'Name', max: 80, required: true }) : u.name,
    email: body.email !== undefined ? str(body.email, { name: 'Email', max: 160, required: true }).toLowerCase() : u.email,
    role: body.role !== undefined ? (ROLES.includes(body.role) ? body.role : fail(400, 'Invalid role')) : u.role,
    organization: body.organization !== undefined ? str(body.organization, { name: 'Organizer name', max: 100 }) : u.organization,
    suspended: body.suspended !== undefined ? !!body.suspended : !!u.suspended,
    phone: body.phone !== undefined ? normalizePhone(body.phone) : u.phone || '',
  };
  if (next.phone === null) fail(400, 'Enter a valid mobile number');
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(next.email)) fail(400, 'Enter a valid email address');
  if (db.users.some((x) => x.email === next.email && x.id !== u.id)) fail(409, 'Another account already uses this email');
  if (self && next.role !== 'admin') fail(400, "You can't remove your own administrator role");
  if (self && next.suspended) fail(400, "You can't suspend your own account");
  if (u.role === 'admin' && (next.role !== 'admin' || next.suspended) && activeAdmins().length <= 1) fail(400, 'At least one active administrator is required');
  if (body.password) {
    checkPassword(String(body.password), 'New password');
    u.passwordHash = hashPassword(String(body.password));
    killSessions(u.id, parseCookies(req).tj_session);
  }
  Object.assign(u, next, { updatedAt: new Date().toISOString() });
  if (u.suspended) killSessions(u.id);
  saveDb();
  return { user: adminUser(u) };
});

route('DELETE', '/api/admin/users/:id', ({ req, params }) => {
  const admin = requireAdmin(req);
  const u = db.users.find((x) => x.id === params.id) || fail(404, 'User not found');
  if (u.id === admin.id) fail(400, "You can't delete your own account");
  if (u.role === 'admin' && activeAdmins().filter((x) => x.id !== u.id).length < 1) fail(400, 'At least one active administrator is required');
  const theirEvents = db.events.filter((e) => e.organizerId === u.id);
  if (db.orders.some((o) => o.userId === u.id) || theirEvents.some((e) => db.orders.some((o) => o.eventId === e.id))) {
    fail(409, 'This account has orders or event sales on record, so it cannot be deleted. Suspend it instead.');
  }
  db.events = db.events.filter((e) => e.organizerId !== u.id);
  db.users = db.users.filter((x) => x.id !== u.id);
  killSessions(u.id);
  saveDb();
  return { ok: true };
});

// Saved organizers & venues for the event form (organizers and admins)
route('GET', '/api/directory', ({ req }) => {
  requireOrganizer(req);
  const byName = (a, b) => a.name.localeCompare(b.name);
  return {
    organizers: (db.organizers || []).map((o) => ({ id: o.id, name: o.name })).sort(byName),
    venues: (db.venues || []).map((v) => ({ id: v.id, name: v.name, address: v.address, city: v.city, lat: v.lat, lng: v.lng, geoPrecision: v.geoPrecision })).sort(byName),
  };
});

const organizerView = (o) => {
  const evs = db.events.filter((e) => eventOrganizers(e).some((n) => norm(n) === norm(o.name)));
  const acct = o.userId ? db.users.find((u) => u.id === o.userId) : null;
  return { ...o, account: acct ? { id: acct.id, name: acct.name, email: acct.email } : null, events: evs.length,
    upcoming: evs.filter((e) => e.status === 'published' && new Date(e.endsAt) > new Date()).length,
    ticketsSold: evs.reduce((a, e) => a + e.tiers.reduce((x, t) => x + t.sold - (t.comped || 0), 0), 0) };
};
const venueView = (v) => {
  const evs = db.events.filter((e) => eventVenueNames(e).some((n) => norm(n) === norm(v.name)));
  return { ...v, events: evs.length, upcoming: evs.filter((e) => e.status === 'published' && new Date(e.endsAt) > new Date()).length };
};
function organizerInput(b) {
  const name = str(b.name, { name: 'Organizer name', max: 100, required: true });
  const email = str(b.email, { name: 'Email', max: 160 }).toLowerCase();
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) fail(400, 'Enter a valid email address');
  const phone = b.phone ? normalizePhone(b.phone) : '';
  if (phone === null) fail(400, 'Enter a valid phone number');
  const website = str(b.website, { name: 'Website', max: 200 });
  const notes = str(b.notes, { name: 'Notes', max: 1000 });
  const userId = b.userId ? String(b.userId) : null;
  if (userId && !db.users.some((u) => u.id === userId)) fail(400, 'Linked account not found');
  return { name, email, phone: phone || '', website, notes, userId };
}
function venueInput(b) {
  const name = str(b.name, { name: 'Venue name', max: 120, required: true });
  const address = str(b.address, { name: 'Address', max: 200 });
  const city = str(b.city, { name: 'City', max: 80, required: true });
  const phone = b.phone ? normalizePhone(b.phone) : '';
  if (phone === null) fail(400, 'Enter a valid phone number');
  const capacity = b.capacity === '' || b.capacity == null ? null : Number(b.capacity);
  if (capacity != null && (!Number.isInteger(capacity) || capacity < 1 || capacity > 1000000)) fail(400, 'Capacity must be a whole number');
  const notes = str(b.notes, { name: 'Notes', max: 1000 });
  return { name, address, city, phone: phone || '', capacity, notes };
}
// Renaming in the directory also renames it on every event that uses it.
function renameOnEvents(kind, oldName, next) {
  let n = 0;
  for (const e of db.events) {
    if (kind === 'organizer') {
      const list = eventOrganizers(e);
      if (!list.some((x) => norm(x) === norm(oldName))) continue;
      e.organizers = cleanOrganizers(list.map((x) => (norm(x) === norm(oldName) ? next.name : x)));
      e.organizerName = joinNames(e.organizers); n++;
    } else {
      let hit = false;
      if (norm(e.venue) === norm(oldName)) {
        e.venue = next.name; e.address = next.address; e.city = next.city;
        if (validLatLng(next.lat, next.lng)) { e.lat = next.lat; e.lng = next.lng; e.geoPrecision = next.geoPrecision || 'pin'; }
        hit = true;
      }
      for (const x of e.extraVenues || []) if (norm(x.name) === norm(oldName)) { Object.assign(x, { name: next.name, address: next.address, city: next.city, lat: next.lat ?? null, lng: next.lng ?? null }); hit = true; }
      if (hit) n++;
    }
  }
  return n;
}
async function locateVenue(v) {
  if (validLatLng(v.lat, v.lng)) return;
  try {
    const hit = await withTimeout(geocodePlace({ venue: v.name, address: v.address, city: v.city, country: db.settings.maps.country }), 8000);
    if (hit) { v.lat = hit.lat; v.lng = hit.lng; v.geoPrecision = hit.precision; }
  } catch {}
}

route('GET', '/api/admin/organizers', ({ req, query }) => {
  requireAdmin(req);
  const q = norm(query.get('q'));
  const list = (db.organizers || []).filter((o) => !q || norm([o.name, o.email, o.phone, o.website].join(' ')).includes(q)).map(organizerView).sort((a, b) => a.name.localeCompare(b.name));
  const accounts = db.users.filter((u) => u.role === 'organizer' || u.role === 'admin').map((u) => ({ id: u.id, name: u.name, email: u.email, organization: u.organization || '' }));
  return { organizers: list, accounts };
});
route('POST', '/api/admin/organizers', ({ req, body }) => {
  const admin = requireAdmin(req);
  const data = organizerInput(body);
  if ((db.organizers ||= []).some((o) => norm(o.name) === norm(data.name))) fail(409, 'An organizer with this name already exists');
  const o = { id: newId('org'), ...data, createdById: admin.id, createdAt: new Date().toISOString() };
  db.organizers.push(o); saveDb();
  return { organizer: organizerView(o) };
});
route('PUT', '/api/admin/organizers/:id', ({ req, body, params }) => {
  requireAdmin(req);
  const o = (db.organizers || []).find((x) => x.id === params.id) || fail(404, 'Organizer not found');
  const data = organizerInput({ ...o, ...body });
  if (db.organizers.some((x) => x.id !== o.id && norm(x.name) === norm(data.name))) fail(409, 'Another organizer already has this name');
  const old = o.name;
  Object.assign(o, data, { updatedAt: new Date().toISOString() });
  const changed = old !== o.name ? renameOnEvents('organizer', old, o) : 0;
  saveDb();
  return { organizer: organizerView(o), eventsUpdated: changed };
});
route('DELETE', '/api/admin/organizers/:id', ({ req, params }) => {
  requireAdmin(req);
  const i = (db.organizers || []).findIndex((x) => x.id === params.id);
  if (i < 0) fail(404, 'Organizer not found');
  db.organizers.splice(i, 1); saveDb();
  return { ok: true };
});

route('GET', '/api/admin/venues', ({ req, query }) => {
  requireAdmin(req);
  const q = norm(query.get('q'));
  return { venues: (db.venues || []).filter((v) => !q || norm([v.name, v.address, v.city].join(' ')).includes(q)).map(venueView).sort((a, b) => a.name.localeCompare(b.name)) };
});
route('POST', '/api/admin/venues', async ({ req, body }) => {
  const admin = requireAdmin(req);
  const data = venueInput(body);
  if ((db.venues ||= []).some((v) => norm(v.name) === norm(data.name) && norm(v.city) === norm(data.city))) fail(409, 'This venue is already saved');
  const v = { id: newId('ven'), ...data, lat: null, lng: null, geoPrecision: null, createdById: admin.id, createdAt: new Date().toISOString() };
  const lat = Number(body.lat), lng = Number(body.lng);
  if (body.lat != null && body.lng != null && validLatLng(lat, lng)) { v.lat = lat; v.lng = lng; v.geoPrecision = 'pin'; } else await locateVenue(v);
  db.venues.push(v); saveDb();
  return { venue: venueView(v) };
});
route('PUT', '/api/admin/venues/:id', async ({ req, body, params }) => {
  requireAdmin(req);
  const v = (db.venues || []).find((x) => x.id === params.id) || fail(404, 'Venue not found');
  const data = venueInput({ ...v, ...body });
  if (db.venues.some((x) => x.id !== v.id && norm(x.name) === norm(data.name) && norm(x.city) === norm(data.city))) fail(409, 'Another saved venue already has this name and city');
  const old = v.name;
  const moved = norm(data.address) !== norm(v.address) || norm(data.city) !== norm(v.city);
  Object.assign(v, data, { updatedAt: new Date().toISOString() });
  const lat = Number(body.lat), lng = Number(body.lng);
  if (body.lat != null && body.lng != null && validLatLng(lat, lng)) { v.lat = lat; v.lng = lng; v.geoPrecision = 'pin'; }
  else if (moved) { v.lat = null; v.lng = null; v.geoPrecision = null; await locateVenue(v); }
  const changed = renameOnEvents('venue', old, v);
  saveDb();
  return { venue: venueView(v), eventsUpdated: changed };
});
route('DELETE', '/api/admin/venues/:id', ({ req, params }) => {
  requireAdmin(req);
  const i = (db.venues || []).findIndex((x) => x.id === params.id);
  if (i < 0) fail(404, 'Venue not found');
  db.venues.splice(i, 1); saveDb();
  return { ok: true };
});

route('GET', '/api/admin/events', ({ req, query }) => {
  requireAdmin(req);
  const q = (query.get('q') || '').toLowerCase().trim();
  const status = query.get('status') || '';
  const list = db.events
    .filter((e) => !status || e.status === status)
    .filter((e) => {
      if (!q) return true;
      const p = db.users.find((u) => u.id === e.organizerId);
      return [e.title, ...eventVenueNames(e), e.city, e.category, e.organizerName, p?.name, p?.email, p?.organization].join(' ').toLowerCase().includes(q);
    })
    .sort((a, b) => new Date(b.startsAt) - new Date(a.startsAt));
  return { events: list.map(adminEvent) };
});

route('GET', '/api/admin/orders', ({ req, query }) => {
  requireAdmin(req);
  const q = (query.get('q') || '').toLowerCase().trim();
  const status = query.get('status') || '';
  const list = db.orders
    .filter((o) => !status || o.status === status)
    .filter((o) => {
      if (!q) return true;
      const ev = db.events.find((e) => e.id === o.eventId);
      return [o.id, o.buyerName, o.buyerEmail, ev?.title, ...o.tickets.map((t) => t.code)].join(' ').toLowerCase().includes(q);
    })
    .sort((a, b) => b.createdAt.localeCompare(a.createdAt));
  return { orders: list.map(adminOrder) };
});

route('POST', '/api/admin/orders/:id/refund', ({ req, params }) => {
  requireAdmin(req);
  const o = db.orders.find((x) => x.id === params.id) || fail(404, 'Order not found');
  if (o.status === 'refunded') fail(409, 'This order was already refunded');
  const ev = db.events.find((e) => e.id === o.eventId);
  if (ev && !o.test) for (const it of o.items) {
    const tier = ev.tiers.find((t) => t.id === it.tierId);
    if (tier) { tier.sold = Math.max(0, tier.sold - it.qty); if (o.comp) tier.comped = Math.max(0, (tier.comped || 0) - it.qty); }
  }
  // DEMO: when real payments are connected, issue the processor refund here.
  o.status = 'refunded';
  o.refundedAt = new Date().toISOString();
  saveDb();
  const admin = currentUser(req);
  withSandbox(o.test ? { orderId: o.id, testerEmail: admin.email } : null, () => Notify.orderRefunded(o));
  return { order: adminOrder(o) };
});

// ---------- preloader (small icon shown while pages open) ----------
const PRE_ICONS = ['logo', 'ticket', 'vinyl', 'ring', 'music', 'star', 'bolt', 'heart', 'custom'];
const PRE_ANIMS = ['spin', 'pulse', 'rumble', 'flash', 'bounce', 'flip', 'still'];
const PRE_SPEEDS = ['slow', 'normal', 'fast'];
const PRE_BGS = ['page', 'dim', 'clear'];
function preloaderHtml() {
  const p = db.settings.preloader;
  if (!p || !p.enabled) return '';
  const src = p.icon === 'custom' && p.customUrl ? p.customUrl : (p.icon === 'logo' || p.icon === 'custom') ? '/favicon.png' : `/preloader/${p.icon}.svg`;
  const size = Math.min(160, Math.max(24, Math.round(p.size) || 56));
  return `<div class="tj-root tj-pre-host"><div id="tjPre" class="tj-pre pre-${p.animation} pre-speed-${p.speed} pre-bg-${p.background}" style="--pre-size:${size}px" role="status" aria-label="Loading"><img src="${src.replace(/"/g, '')}" alt="" width="${size}" height="${size}" /></div></div><script>setTimeout(function(){var p=document.getElementById('tjPre');if(p)p.classList.add('done')},8000)</script>`;
}

const SECRET_KEYS = ['resendApiKey', 'twilioToken'];
function settingsView() {
  const n = { ...N() };
  for (const k of SECRET_KEYS) n[k] = n[k] ? '••••' + n[k].slice(-4) : '';
  return { feePercent: db.settings.feePercent, feeFixed: db.settings.feeFixed, notifications: n, maps: db.settings.maps, preloader: db.settings.preloader, security: SEC(), captcha: { ...db.settings.captcha, secretKey: db.settings.captcha.secretKey ? '••••' + db.settings.captcha.secretKey.slice(-4) : '', testKeys: db.settings.captcha.siteKey === RECAPTCHA_TEST_SITE }, defaultAdminRecipients: activeAdmins().map((u) => u.email) };
}

route('GET', '/api/admin/settings', ({ req }) => { requireAdmin(req); return { settings: settingsView() }; });

route('PUT', '/api/admin/security', ({ req, body }) => {
  requireAdmin(req);
  const s = { ...SEC() };
  const int = (k, lo, hi, msg) => { if (body[k] === undefined) return; const n = Number(body[k]); if (!Number.isInteger(n) || n < lo || n > hi) fail(400, msg); s[k] = n; };
  int('sessionHours', 1, 2160, 'Pick how long people stay logged in');
  int('adminSessionHours', 1, 2160, 'Pick how long administrators stay logged in');
  int('idleMinutes', 0, 1440, 'Pick an inactivity time');
  int('maxAttempts', 0, 20, 'Wrong tries must be between 0 (off) and 20');
  int('lockMinutes', 1, 1440, 'Lock time must be between 1 minute and 1 day');
  int('resetMinutes', 10, 1440, 'Reset links must work for between 10 minutes and 1 day');
  int('minPassword', 8, 64, 'Minimum password length must be between 8 and 64');
  if (body.allowReset !== undefined) s.allowReset = !!body.allowReset;
  db.settings.security = s;
  saveDb();
  return { settings: settingsView() };
});
route('POST', '/api/admin/logout-everyone', ({ req }) => {
  const admin = requireAdmin(req);
  const mine = parseCookies(req).tj_session;
  let n = 0;
  for (const [t, s] of Object.entries(db.sessions)) if (t !== mine) { delete db.sessions[t]; if (s.userId !== admin.id) n++; }
  saveDb();
  return { ok: true, count: n };
});
route('POST', '/api/admin/users/:id/send-reset', ({ req, params }) => {
  requireAdmin(req);
  const u = db.users.find((x) => x.id === params.id) || fail(404, 'User not found');
  if (u.suspended) fail(400, 'This account is suspended. Make it active first.');
  sendReset(u, true);
  return { ok: true, email: u.email, valid: durLabel(SEC().resetMinutes) };
});
route('POST', '/api/admin/users/:id/logout', ({ req, params }) => {
  const admin = requireAdmin(req);
  const u = db.users.find((x) => x.id === params.id) || fail(404, 'User not found');
  killSessions(u.id, u.id === admin.id ? parseCookies(req).tj_session : undefined);
  saveDb();
  return { ok: true };
});

route('PUT', '/api/admin/preloader', ({ req, body }) => {
  requireAdmin(req);
  const p = { ...db.settings.preloader };
  if (body.enabled !== undefined) p.enabled = !!body.enabled;
  if (body.icon !== undefined) { if (!PRE_ICONS.includes(body.icon)) fail(400, 'Pick one of the preloader icons'); p.icon = body.icon; }
  if (body.customUrl !== undefined) {
    const u = String(body.customUrl || '').trim();
    if (u && !/^\/uploads\/[\w-]+\.(png|jpe?g|webp|gif)$/.test(u)) fail(400, 'Upload your preloader icon with the Upload button');
    p.customUrl = u;
  }
  if (body.size !== undefined) { const n = Number(body.size); if (!Number.isInteger(n) || n < 24 || n > 160) fail(400, 'Icon size must be between 24 and 160 pixels'); p.size = n; }
  if (body.animation !== undefined) { if (!PRE_ANIMS.includes(body.animation)) fail(400, 'Pick one of the animations'); p.animation = body.animation; }
  if (body.speed !== undefined) { if (!PRE_SPEEDS.includes(body.speed)) fail(400, 'Pick a speed'); p.speed = body.speed; }
  if (body.background !== undefined) { if (!PRE_BGS.includes(body.background)) fail(400, 'Pick a background'); p.background = body.background; }
  if (p.icon === 'custom' && !p.customUrl) fail(400, 'Upload your own icon first, or pick one of the built-in icons');
  db.settings.preloader = p;
  saveDb();
  return { settings: settingsView() };
});

route('PUT', '/api/admin/captcha', ({ req, body }) => {
  requireAdmin(req);
  const c = db.settings.captcha;
  if (body.useTestKeys) { c.siteKey = RECAPTCHA_TEST_SITE; c.secretKey = RECAPTCHA_TEST_SECRET; }
  else {
    if (body.siteKey !== undefined) {
      const k = str(body.siteKey, { name: 'Site key', max: 100 });
      if (k && !/^[\w-]{30,60}$/.test(k)) fail(400, 'That does not look like a reCAPTCHA site key');
      c.siteKey = k;
    }
    if (typeof body.secretKey === 'string' && body.secretKey && !body.secretKey.startsWith('••••')) {
      if (!/^[\w-]{30,60}$/.test(body.secretKey.trim())) fail(400, 'That does not look like a reCAPTCHA secret key');
      c.secretKey = body.secretKey.trim();
    }
  }
  for (const k of ['enabled', 'protectRegister', 'protectCheckout']) if (body[k] !== undefined) c[k] = !!body[k];
  if (c.enabled && (!c.siteKey || !c.secretKey)) fail(400, 'Enter both the site key and the secret key, or use the test keys');
  saveDb();
  return { settings: settingsView() };
});

route('PUT', '/api/admin/maps', async ({ req, body }) => {
  requireAdmin(req);
  const m = db.settings.maps;
  if (body.googleMapsKey !== undefined) {
    const k = str(body.googleMapsKey, { name: 'Google Maps key', max: 100 });
    if (k && !/^[\w-]{20,}$/.test(k)) fail(400, 'That does not look like a Google Maps API key');
    m.googleMapsKey = k;
  }
  if (body.country !== undefined) m.country = str(body.country, { name: 'Country', max: 60 });
  if (body.weatherPlace !== undefined) {
    const place = str(body.weatherPlace, { name: 'Weather location', max: 100, required: true });
    if (place !== m.weatherPlace) {
      const hit = await geocodePlace({ city: place });
      if (!hit) fail(400, `Couldn't find "${place}" — try a city name like "Nassau, Bahamas"`);
      Object.assign(m, { weatherPlace: place, weatherLat: Math.round(hit.lat * 1e4) / 1e4, weatherLng: Math.round(hit.lng * 1e4) / 1e4 });
    }
  }
  saveDb();
  return { settings: settingsView() };
});

// Re-run address lookup for events that have no map location
route('POST', '/api/admin/maps/locate-all', async ({ req }) => {
  requireAdmin(req);
  const missing = db.events.filter((e) => !validLatLng(e.lat, e.lng));
  for (const e of missing) await ensureGeo(e);
  const located = missing.filter((e) => { const x = db.events.find((y) => y.id === e.id); return x && validLatLng(x.lat, x.lng); }).length;
  return { checked: missing.length, located };
});

route('PUT', '/api/admin/notifications', ({ req, body }) => {
  requireAdmin(req);
  const n = N();
  const s = (k, max = 300) => { if (body[k] !== undefined) n[k] = str(body[k], { name: k, max }); };
  s('siteUrl'); s('adminEmail', 500); s('emailFrom'); s('replyTo'); s('twilioSid', 64); s('twilioFrom', 32);
  if (n.siteUrl && !/^https?:\/\/[^\s]+$/.test(n.siteUrl)) fail(400, 'Website address must start with http:// or https://');
  n.siteUrl = (n.siteUrl || `http://localhost:${PORT}`).replace(/\/+$/, '');
  if (n.adminEmail && !n.adminEmail.split(/[,;\s]+/).filter(Boolean).every((e) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e))) fail(400, 'Admin notification email is not valid');
  if (n.twilioFrom) { const p = normalizePhone(n.twilioFrom); if (!p) fail(400, 'Twilio phone number is not valid'); n.twilioFrom = p; }
  for (const k of SECRET_KEYS) {
    const v = body[k];
    if (typeof v === 'string' && v && !v.startsWith('••••')) n[k] = v.trim();
    if (v === '') n[k] = '';
  }
  if (body.emailEnabled !== undefined) n.emailEnabled = !!body.emailEnabled;
  if (body.smsEnabled !== undefined) n.smsEnabled = !!body.smsEnabled;
  if (body.adminAlerts && typeof body.adminAlerts === 'object') for (const k of Object.keys(n.adminAlerts)) if (k in body.adminAlerts) n.adminAlerts[k] = !!body.adminAlerts[k];
  saveDb();
  return { settings: settingsView() };
});

route('POST', '/api/admin/notifications/test', async ({ req, body }) => {
  const admin = requireAdmin(req);
  if (body.channel === 'sms') {
    const to = normalizePhone(body.to || admin.phone);
    if (!to) fail(400, 'Enter a mobile number to send the test text to');
    const r = await notifier.deliverSms({ to, tag: 'test', body: 'TicketJunkies: This is a test text message. Your SMS notifications are working! 🎟️' });
    return { result: r };
  }
  const to = str(body.to || admin.email, { name: 'Email', max: 160 });
  const r = await notifier.deliverEmail({ to, tag: 'test', subject: 'TicketJunkies test email', html: emailLayout({ siteUrl: site(), heading: 'Your emails are working! ✅', bodyHtml: '<p>This is a test email from your TicketJunkies Admin Panel. Notifications to administrators, organizers and ticket buyers will look like this.</p>', cta: { label: 'Open Admin Panel', url: `${site()}/#/admin` } }), text: 'Your TicketJunkies emails are working!' });
  return { result: r };
});

route('GET', '/api/admin/notifications', ({ req, query }) => {
  requireAdmin(req);
  const channel = query.get('channel') || '';
  const q = (query.get('q') || '').toLowerCase();
  const list = db.notifications.filter((n) => (!channel || n.channel === channel) && (!q || [n.to, n.subject, n.tag].join(' ').toLowerCase().includes(q)));
  return { notifications: list.slice(0, 200).map(({ html, ...rest }) => rest) };
});

route('GET', '/api/admin/notifications/:id', ({ req, params }) => {
  requireAdmin(req);
  return { notification: db.notifications.find((n) => n.id === params.id) || fail(404, 'Message not found') };
});

// ---------- sandbox (simulated sales for testing) ----------
function withSandbox(ctx, fn) {
  const prev = sandboxCtx;
  sandboxCtx = ctx;
  try { return fn(); } finally { sandboxCtx = prev; }
}
function lanUrls() {
  const out = [];
  for (const list of Object.values(os.networkInterfaces())) for (const a of list || []) {
    if (a.family === 'IPv4' && !a.internal && !a.address.startsWith('169.254.')) out.push(`http://${a.address}:${PORT}`);
  }
  return out;
}
const providerStatus = () => {
  const n = N();
  return { email: !!(n.emailEnabled && n.resendApiKey), sms: !!(n.smsEnabled && n.twilioSid && n.twilioToken && n.twilioFrom), siteUrl: site() };
};

route('GET', '/api/admin/sandbox', ({ req }) => {
  requireAdmin(req);
  const events = db.events.filter((e) => e.status !== 'cancelled').sort((a, b) => new Date(a.startsAt) - new Date(b.startsAt)).map((e) => {
    const p = organizerOf(e);
    return { id: e.id, title: e.title, startsAt: e.startsAt, status: e.status, organizer: e.organizerName || p?.organization || p?.name || '', tiers: e.tiers.map((t) => ({ id: t.id, name: t.name, price: t.price })) };
  });
  const testOrders = db.orders.filter((o) => o.test).sort((a, b) => b.createdAt.localeCompare(a.createdAt)).map((o) => ({ ...orderView(o), link: `/#/t/${o.id}/${o.key}` }));
  return { events, testOrders, providers: providerStatus(), lanUrls: lanUrls() };
});

route('POST', '/api/admin/sandbox/sale', ({ req, body }) => {
  const admin = requireAdmin(req);
  const ev = db.events.find((e) => e.id === body.eventId) || fail(400, 'Choose an event');
  const tier = ev.tiers.find((t) => t.id === body.tierId) || fail(400, 'Choose a ticket type');
  const qty = Number(body.qty);
  if (!Number.isInteger(qty) || qty < 1 || qty > 10) fail(400, 'Quantity must be 1–10');
  const buyerName = str(body.buyerName, { name: 'Buyer name', max: 80, required: true });
  const buyerEmail = str(body.buyerEmail, { name: 'Buyer email', max: 160, required: true });
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(buyerEmail)) fail(400, 'Enter a valid email address');
  const buyerPhone = normalizePhone(body.buyerPhone);
  if (!buyerPhone) fail(400, 'Enter a valid mobile number for the test text');

  const subtotal = round2(tier.price * qty);
  const fees = round2(ticketFee(tier.price) * qty);
  const tickets = Array.from({ length: qty }, () => ({ code: 'TJ-' + crypto.randomBytes(4).toString('hex').toUpperCase(), tierId: tier.id, tierName: tier.name, price: tier.price, checkedIn: false }));
  const order = {
    id: newId('ord'), userId: admin.id, eventId: ev.id, test: true,
    items: [{ tierId: tier.id, tierName: tier.name, price: tier.price, qty }],
    tickets, buyerName, buyerEmail, buyerPhone, subtotal, fees, total: round2(subtotal + fees),
    ...(subtotal + fees > 0 ? cardInfo('4242424242424242', buyerName) : { cardLast4: '', cardBrand: '', cardHolder: '', cardFingerprint: '' }), status: 'paid', key: crypto.randomBytes(12).toString('hex'),
    createdAt: new Date().toISOString(),
  };
  // Test orders never change ticket inventory, sales totals or payouts.
  db.orders.push(order);
  saveDb();
  withSandbox({ orderId: order.id, testerEmail: admin.email }, () => Notify.orderPlaced(order, ev));
  return { order: { ...orderView(order), link: `/#/t/${order.id}/${order.key}` }, providers: providerStatus(), lanUrls: lanUrls() };
});

route('GET', '/api/admin/sandbox/orders/:id/messages', ({ req, params }) => {
  requireAdmin(req);
  return { messages: db.notifications.filter((n) => n.ref === params.id) };
});

route('DELETE', '/api/admin/sandbox', ({ req }) => {
  requireAdmin(req);
  const before = db.orders.length;
  db.orders = db.orders.filter((o) => !o.test);
  const removedMsgs = db.notifications.length;
  db.notifications = db.notifications.filter((n) => !n.test);
  saveDb();
  return { removedOrders: before - db.orders.length, removedMessages: removedMsgs - db.notifications.length };
});

// ---------- sales reports ----------
function buildReport({ events, query, withCards }) {
  const evIds = new Set(events.map((e) => e.id));
  const eventId = query.get('eventId') || '';
  const status = query.get('status') || 'paid';          // paid | refunded | all
  const includeTests = query.get('tests') === '1';
  const fromD = query.get('from') ? new Date(query.get('from') + 'T00:00:00') : null;
  const toD = query.get('to') ? new Date(query.get('to') + 'T23:59:59.999') : null;
  const orders = db.orders.filter((o) => evIds.has(o.eventId)
    && (!eventId || o.eventId === eventId)
    && (status === 'all' || (status === 'refunded' ? o.status === 'refunded' : o.status !== 'refunded'))
    && (includeTests || !o.test)
    && (!fromD || new Date(o.createdAt) >= fromD) && (!toD || new Date(o.createdAt) <= toD))
    .sort((a, b) => b.createdAt.localeCompare(a.createdAt));
  const evById = Object.fromEntries(db.events.map((e) => [e.id, e]));
  const sum = (list, f) => round2(list.reduce((a, x) => a + f(x), 0));
  const paid = orders.filter((o) => o.status !== 'refunded');
  const refunded = orders.filter((o) => o.status === 'refunded');
  const cardKey = (o) => o.cardFingerprint || (o.cardLast4 ? 'legacy_' + o.cardLast4 : '');

  const sales = paid.filter((o) => !o.comp);
  const comps = paid.filter((o) => o.comp);
  const summary = {
    orders: sales.length, tickets: sales.reduce((a, o) => a + o.tickets.length, 0),
    compOrders: comps.length, compTickets: comps.reduce((a, o) => a + o.tickets.length, 0),
    grossSales: sum(paid, (o) => o.subtotal), fees: sum(paid, (o) => o.fees), totalCollected: sum(paid, (o) => o.total),
    refundedOrders: refunded.length, refundedAmount: sum(refunded, (o) => o.total),
    buyers: new Set(sales.map((o) => o.buyerEmail.toLowerCase())).size,
    cards: new Set(paid.map(cardKey).filter(Boolean)).size,
    avgOrder: sales.length ? round2(sum(sales, (o) => o.total) / sales.length) : 0,
    checkedIn: paid.reduce((a, o) => a + o.tickets.filter((t) => t.checkedIn).length, 0),
  };

  // per event
  const byEvent = events.filter((e) => !eventId || e.id === eventId).map((e) => {
    const os = orders.filter((o) => o.eventId === e.id);
    const pAll = os.filter((o) => o.status !== 'refunded');
    const p = pAll.filter((o) => !o.comp);
    const pc = pAll.filter((o) => o.comp);
    const prom = db.users.find((u) => u.id === e.organizerId);
    return {
      id: e.id, title: e.title, startsAt: e.startsAt, venue: e.venue, status: e.status, organizer: e.organizerName || prom?.organization || prom?.name || '',
      capacity: e.tiers.reduce((a, t) => a + t.quantity, 0), soldAllTime: e.tiers.reduce((a, t) => a + t.sold, 0),
      orders: p.length, tickets: p.reduce((a, o) => a + o.tickets.length, 0),
      gross: sum(p, (o) => o.subtotal), fees: sum(p, (o) => o.fees), total: sum(p, (o) => o.total),
      comps: pc.reduce((a, o) => a + o.tickets.length, 0),
      refunds: os.length - pAll.length, checkedIn: pAll.reduce((a, o) => a + o.tickets.filter((t) => t.checkedIn).length, 0),
      tiers: e.tiers.map((t) => {
        const items = p.flatMap((o) => o.items.filter((i) => i.tierId === t.id));
        const q = items.reduce((a, i) => a + i.qty, 0);
        const c = pc.flatMap((o) => o.items.filter((i) => i.tierId === t.id)).reduce((a, i) => a + i.qty, 0);
        return { name: t.name + (t.visibility === 'private' ? ' (private)' : '') + (tierKind(t) === 'donation' ? ' (donation)' : ''), price: t.price, quantity: t.quantity, sold: q, comps: c, revenue: round2(items.reduce((a, i) => a + i.price * i.qty, 0)) };
      }),
    };
  }).filter((r) => eventId || r.orders || r.comps || r.refunds || r.soldAllTime || evById[r.id].status === 'published');

  // per day (sales date)
  const dayMap = new Map();
  for (const o of sales) {
    const d = new Date(o.createdAt); const k = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const r = dayMap.get(k) || { date: k, orders: 0, tickets: 0, total: 0 };
    r.orders++; r.tickets += o.tickets.length; r.total = round2(r.total + o.total);
    dayMap.set(k, r);
  }
  const byDay = [...dayMap.values()].sort((a, b) => a.date.localeCompare(b.date));

  // per card (admin only)
  let byCard = [];
  if (withCards) {
    const m = new Map();
    for (const o of orders) {
      const k = cardKey(o);
      if (!k) continue;
      const r = m.get(k) || { fingerprint: k, brand: o.cardBrand || 'Card', last4: o.cardLast4, holders: new Set(), emails: new Set(), events: new Set(), orders: 0, tickets: 0, total: 0, refunds: 0, first: o.createdAt, last: o.createdAt };
      if (o.status === 'refunded') r.refunds++;
      else { r.orders++; r.tickets += o.tickets.length; r.total = round2(r.total + o.total); }
      if (o.cardHolder) r.holders.add(o.cardHolder);
      r.emails.add(o.buyerEmail); r.events.add(evById[o.eventId]?.title || 'Deleted event');
      if (o.createdAt < r.first) r.first = o.createdAt;
      if (o.createdAt > r.last) r.last = o.createdAt;
      m.set(k, r);
    }
    byCard = [...m.values()].map((r) => {
      const flags = [];
      if (r.tickets >= 12) flags.push('Many tickets');
      if (r.emails.size > 1) flags.push('Used by several emails');
      if (r.holders.size > 1) flags.push('Different names');
      if (r.refunds) flags.push('Has refunds');
      return { ...r, holders: [...r.holders], emails: [...r.emails], events: [...r.events], flags };
    }).sort((a, b) => b.tickets - a.tickets || b.total - a.total);
  }

  // transactions
  const transactions = orders.map((o) => {
    const e = evById[o.eventId];
    const row = {
      id: o.id, ref: o.id.slice(-8).toUpperCase(), createdAt: o.createdAt, status: o.status, test: !!o.test, comp: !!o.comp, compNote: o.compNote || '', issuedBy: o.issuedBy || '',
      eventId: o.eventId, event: e?.title || 'Deleted event', eventDate: e?.startsAt || null,
      buyerName: o.buyerName, ...splitName(o), buyerEmail: o.buyerEmail, buyerPhone: o.buyerPhone || '',
      items: o.items.map((i) => ({ name: i.tierName, qty: i.qty, price: i.price })),
      qty: o.tickets.length, codes: o.tickets.map((t) => t.code), checkedIn: o.tickets.filter((t) => t.checkedIn).length,
      subtotal: o.subtotal, fees: o.fees, total: o.total, refundedAt: o.refundedAt || null,
    };
    if (withCards) Object.assign(row, { cardBrand: o.cardBrand || '', cardLast4: o.cardLast4 || '', cardHolder: o.cardHolder || '', cardFingerprint: cardKey(o) });
    return row;
  });

  return {
    generatedAt: new Date().toISOString(), filters: { eventId, status, from: query.get('from') || '', to: query.get('to') || '', tests: includeTests },
    events: events.map((e) => ({ id: e.id, title: e.title, startsAt: e.startsAt })).sort((a, b) => new Date(b.startsAt) - new Date(a.startsAt)),
    summary, byEvent, byDay, byCard, transactions, withCards,
  };
}

route('GET', '/api/admin/reports', ({ req, query }) => {
  requireAdmin(req);
  return { report: buildReport({ events: db.events, query, withCards: true }) };
});

// Organizers see reports for their own events (buyer details, no card data)
route('GET', '/api/organizer/reports', ({ req, query }) => {
  const user = requireOrganizer(req);
  const mine = db.events.filter((e) => e.organizerId === user.id);
  return { report: buildReport({ events: mine, query, withCards: false }) };
});

// ---------- report exports (PDF / Word / Excel / CSV) ----------
const EXPORT_SECTIONS = ['summary', 'days', 'events', 'cards', 'transactions', 'attendees'];
function reportModel(r, sections, who) {
  const want = (k) => sections.includes(k);
  const single = r.filters.eventId ? r.byEvent[0] : null;
  const day = (iso) => new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  const period = r.filters.from || r.filters.to ? `${r.filters.from ? day(r.filters.from + 'T12:00') : 'Start'} – ${r.filters.to ? day(r.filters.to + 'T12:00') : 'Today'}` : 'All time';
  const statusTxt = r.filters.status === 'all' ? 'Paid + refunded orders' : r.filters.status === 'refunded' ? 'Refunded orders' : 'Paid orders';
  const s = r.summary;
  const model = {
    title: `Sales Report — ${single ? single.title : 'All Events'}`,
    subtitle: `${period} · ${statusTxt}${r.filters.tests ? ' · incl. sandbox tests' : ''} · ${who} · generated ${new Date(r.generatedAt).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' })}`,
    meta: [], sections: [],
  };
  if (want('summary')) {
    model.meta = [
      ['Tickets sold', s.tickets], ['Orders', s.orders], ['Ticket sales', s.grossSales], [r.withCards ? 'Platform fees' : 'Service fees (buyers)', s.fees],
      ['Total collected', s.totalCollected], ['Avg order', s.avgOrder], ['Unique buyers', s.buyers], ...(r.withCards ? [['Unique cards', s.cards]] : []),
      ['Complimentary tickets', s.compTickets], ['Refunded orders', s.refundedOrders], ['Refunded amount', s.refundedAmount], ['Tickets checked in', s.checkedIn],
    ];
  }
  if (want('days') && r.byDay.length) {
    model.sections.push({ title: 'Sales by Day', sheet: 'By Day', columns: [{ label: 'Date', width: 16 }, { label: 'Orders', width: 9, type: 'int' }, { label: 'Tickets', width: 9, type: 'int' }, { label: 'Collected', width: 12, type: 'money' }],
      rows: r.byDay.map((d) => [day(d.date + 'T12:00'), d.orders, d.tickets, d.total]), totals: ['Total', s.orders, s.tickets, s.totalCollected] });
  }
  if (want('events')) {
    if (single) {
      model.sections.push({ title: `Ticket Types — ${single.title}`, sheet: 'Ticket Types', columns: [{ label: 'Ticket type', width: 24 }, { label: 'Price', width: 10, type: 'money' }, { label: 'Sold', width: 8, type: 'int' }, { label: 'Comps', width: 8, type: 'int' }, { label: 'Capacity', width: 9, type: 'int' }, { label: 'Revenue', width: 12, type: 'money' }],
        rows: single.tiers.map((t) => [t.name, t.price, t.sold, t.comps || 0, t.quantity, t.revenue]), totals: ['Total', '', single.tickets, single.comps || 0, single.capacity, single.gross] });
    } else {
      model.sections.push({ title: 'Sales by Event', sheet: 'By Event', columns: [
        { label: 'Event', width: 26 }, { label: 'Event date', width: 15, type: 'date' }, ...(r.withCards ? [{ label: 'Organizer', width: 18 }] : []), { label: 'Orders', width: 8, type: 'int' }, { label: 'Tickets', width: 8, type: 'int' },
        { label: 'Sold (all time)', width: 10, type: 'int' }, { label: 'Capacity', width: 9, type: 'int' }, { label: 'Ticket sales', width: 12, type: 'money' }, { label: 'Fees', width: 10, type: 'money' }, { label: 'Refunds', width: 8, type: 'int' }, { label: 'Checked in', width: 9, type: 'int' }],
        rows: r.byEvent.map((e) => [e.title, e.startsAt, ...(r.withCards ? [e.organizer] : []), e.orders, e.tickets, e.soldAllTime, e.capacity, e.gross, e.fees, e.refunds, e.checkedIn]),
        totals: ['Total', '', ...(r.withCards ? [''] : []), s.orders, s.tickets, '', '', s.grossSales, s.fees, s.refundedOrders, s.checkedIn] });
    }
  }
  if (want('cards') && r.withCards) {
    model.sections.push({ title: 'Tickets Purchased per Card', sheet: 'Per Card', columns: [
      { label: 'Card', width: 15 }, { label: 'Name(s) on card', width: 16 }, { label: 'Buyer email(s)', width: 22 }, { label: 'Orders', width: 7, type: 'int' }, { label: 'Refunds', width: 8, type: 'int' },
      { label: 'Tickets', width: 7, type: 'int' }, { label: 'Total spent', width: 11, type: 'money' }, { label: 'Events', width: 22 }, { label: 'First purchase', width: 14, type: 'date' }, { label: 'Last purchase', width: 14, type: 'date' }, { label: 'Flags', width: 16 }],
      rows: r.byCard.map((c) => [`${c.brand} •••• ${c.last4}`, c.holders.join(', '), c.emails.join(', '), c.orders, c.refunds, c.tickets, c.total, c.events.join(', '), c.first, c.last, c.flags.join(', ')]) });
  }
  if (want('transactions')) {
    model.sections.push({ title: 'Transactions', sheet: 'Transactions', columns: [
      { label: 'Sale date', width: 14, type: 'date' }, { label: 'Order #', width: 9 }, ...(single ? [] : [{ label: 'Event', width: 18 }]), { label: 'Buyer', width: 14 }, { label: 'Email', width: 20 }, { label: 'Phone', width: 12 },
      ...(r.withCards ? [{ label: 'Card', width: 13 }, { label: 'Name on card', width: 13 }] : []), { label: 'Qty', width: 5, type: 'int' }, { label: 'Ticket types', width: 15 }, { label: 'Ticket codes', width: 16 },
      { label: 'Subtotal', width: 9, type: 'money' }, { label: 'Fees', width: 8, type: 'money' }, { label: 'Total', width: 9, type: 'money' }, { label: 'Status', width: 9 }],
      rows: r.transactions.map((t) => [t.createdAt, t.ref + (t.test ? ' (test)' : ''), ...(single ? [] : [t.event]), t.buyerName, t.buyerEmail, t.buyerPhone,
        ...(r.withCards ? [t.cardLast4 ? `${t.cardBrand || 'Card'} •••• ${t.cardLast4}` : 'Free', t.cardHolder] : []), t.qty, t.items.map((i) => `${i.qty}× ${i.name}`).join(', '), t.codes.join(', '),
        t.subtotal, t.fees, t.total, (t.status === 'refunded' ? 'Refunded' : t.comp ? 'Complimentary' : 'Paid')]),
      totals: ['Total', '', ...(single ? [] : ['']), '', '', '', ...(r.withCards ? ['', ''] : []), r.transactions.reduce((a, t) => a + t.qty, 0), '', '',
        round2(r.transactions.reduce((a, t) => a + t.subtotal, 0)), round2(r.transactions.reduce((a, t) => a + t.fees, 0)), round2(r.transactions.reduce((a, t) => a + t.total, 0)), ''] });
  }
  if (want('attendees')) {
    const rows = r.transactions.flatMap((t) => { const types = t.items.flatMap((i) => Array(i.qty).fill(i)); return t.codes.map((c, k) => [c, ...(single ? [] : [t.event]), types[k]?.name || '', types[k]?.price ?? 0, t.buyerFirstName, t.buyerLastName, t.buyerEmail, t.buyerPhone, t.ref, t.createdAt, t.status === 'refunded' ? 'Refunded (void)' : t.comp ? 'Valid (comp)' : 'Valid']); });
    model.sections.push({ title: 'Attendee List (one row per ticket)', sheet: 'Attendees', columns: [
      { label: 'Ticket code', width: 12 }, ...(single ? [] : [{ label: 'Event', width: 20 }]), { label: 'Ticket type', width: 14 }, { label: 'Price', width: 8, type: 'money' }, { label: 'First name', width: 11 }, { label: 'Last name', width: 11 },
      { label: 'Email', width: 22 }, { label: 'Phone', width: 13 }, { label: 'Order #', width: 9 }, { label: 'Purchased', width: 14, type: 'date' }, { label: 'Ticket status', width: 11 }], rows });
  }
  return model;
}

const EXPORT_TYPES = {
  pdf: { mime: 'application/pdf', ext: 'pdf', fn: toPdf },
  docx: { mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ext: 'docx', fn: toDocx },
  xlsx: { mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ext: 'xlsx', fn: toXlsx },
  csv: { mime: 'text/csv; charset=utf-8', ext: 'csv', fn: (m) => Buffer.from(toCsv(m), 'utf8') },
};
function sendExport(res, r, query, who) {
  const type = EXPORT_TYPES[query.get('format')] || fail(400, 'Choose PDF, Word, Excel or CSV');
  const picked = String(query.get('sections') || EXPORT_SECTIONS.join(',')).split(',').filter((x) => EXPORT_SECTIONS.includes(x));
  if (!picked.length) fail(400, 'Choose at least one section to export');
  const model = reportModel(r, picked, who);
  const buf = type.fn(model);
  const single = r.filters.eventId ? r.byEvent[0]?.title : 'all-events';
  const name = `TicketJunkies-report-${String(single || 'event').replace(/[^\w]+/g, '-').replace(/^-|-$/g, '').slice(0, 50)}-${new Date().toISOString().slice(0, 10)}.${type.ext}`;
  res.writeHead(200, { 'Content-Type': type.mime, 'Content-Length': buf.length, 'Content-Disposition': `attachment; filename="${name}"`, 'Cache-Control': 'no-store' });
  res.end(buf);
  return RAW_SENT;
}

route('GET', '/api/admin/reports/export', ({ req, res, query }) => {
  requireAdmin(req);
  return sendExport(res, buildReport({ events: db.events, query, withCards: true }), query, 'Administrator report');
});
route('GET', '/api/organizer/reports/export', ({ req, res, query }) => {
  const user = requireOrganizer(req);
  const mine = db.events.filter((e) => e.organizerId === user.id);
  return sendExport(res, buildReport({ events: mine, query, withCards: false }), query, user.organization || user.name);
});

// Public ticket page (link sent by text/email) — protected by the order's secret key
route('GET', '/api/tickets/:id', ({ params, query }) => {
  const o = db.orders.find((x) => x.id === params.id);
  const k = String(query.get('k') || '');
  if (!o || !k || k.length !== o.key.length || !crypto.timingSafeEqual(Buffer.from(k), Buffer.from(o.key))) fail(404, 'Tickets not found');
  const { userId, cardLast4, cardBrand, cardHolder, ...safe } = orderView(o);
  return { order: safe };
});

// ---------- ticket downloads & resend (attendee dashboard) ----------
function sendTicketPdf(res, o, suffix = '') {
  const ev = db.events.find((e) => e.id === o.eventId) || { title: 'Event' };
  const buf = ticketsPdf(o, ev, qrMatrix, { site: site() });
  const name = ticketPdfName(ev, o, suffix);
  res.writeHead(200, { 'Content-Type': 'application/pdf', 'Content-Length': buf.length, 'Content-Disposition': `attachment; filename="${name}"`, 'Cache-Control': 'no-store' });
  res.end(buf);
  return RAW_SENT;
}
const canSeeOrder = (user, o) => o.userId === user.id || user.role === 'admin' || (user.role === 'organizer' && db.events.some((e) => e.id === o.eventId && e.organizerId === user.id));
route('GET', '/api/orders/:id/tickets.pdf', ({ req, res, params }) => {
  const user = requireUser(req);
  const o = db.orders.find((x) => x.id === params.id);
  if (!o || !canSeeOrder(user, o)) fail(404, 'Order not found');
  return sendTicketPdf(res, o);
});
route('GET', '/api/tickets/:id/pdf', ({ res, params, query }) => {
  const o = db.orders.find((x) => x.id === params.id);
  // One person's ticket (link in an attendee's email): only that ticket, never the rest of the order
  const code = String(query.get('code') || ''), tk = String(query.get('tk') || '');
  if (code) {
    const t = o && o.tickets.find((x) => x.code === code);
    const want = t ? ticketKey(o, code) : '';
    if (!t || tk.length !== want.length || !crypto.timingSafeEqual(Buffer.from(tk), Buffer.from(want))) fail(404, 'Ticket not found');
    return sendTicketPdf(res, { ...o, tickets: [t] }, code);
  }
  const k = String(query.get('k') || '');
  if (!o || !k || k.length !== o.key.length || !crypto.timingSafeEqual(Buffer.from(k), Buffer.from(o.key))) fail(404, 'Tickets not found');
  return sendTicketPdf(res, o);
});
const resendLog = new Map(); // orderId -> [timestamps]
route('POST', '/api/orders/:id/resend', ({ req, body, params }) => {
  const user = requireUser(req);
  const o = db.orders.find((x) => x.id === params.id && x.userId === user.id) || fail(404, 'Order not found');
  if (o.status === 'refunded') fail(400, 'This order was refunded, so its tickets are no longer valid');
  const ev = db.events.find((e) => e.id === o.eventId) || fail(404, 'This event no longer exists');
  const channel = ['email', 'sms', 'both'].includes(body.channel) ? body.channel : 'both';
  if (channel !== 'email' && !o.buyerPhone) fail(400, 'No mobile number is saved on this order');
  const now = Date.now();
  const recent = (resendLog.get(o.id) || []).filter((t) => now - t < 15 * 60000);
  if (recent.length >= 3) fail(429, 'You can resend tickets up to 3 times every 15 minutes. Please try again shortly, or download the PDF.');
  recent.push(now); resendLog.set(o.id, recent);
  const run = () => Notify.orderPlaced(o, ev, { buyerOnly: true, email: channel !== 'sms', sms: channel !== 'email' });
  if (o.test) withSandbox({ orderId: o.id, testerEmail: user.email }, run); else run();
  return { ok: true, sentTo: { email: channel !== 'sms' ? o.buyerEmail : null, sms: channel !== 'email' ? o.buyerPhone : null } };
});

route('PUT', '/api/admin/settings', ({ req, body }) => {
  requireAdmin(req);
  const pct = Number(body.feePercent);
  const fixed = Number(body.feeFixed);
  if (!Number.isFinite(pct) || pct < 0 || pct > 30) fail(400, 'Fee percent must be between 0 and 30');
  if (!Number.isFinite(fixed) || fixed < 0 || fixed > 20) fail(400, 'Fixed fee must be between $0 and $20');
  db.settings.feePercent = round2(pct) / 100;
  db.settings.feeFixed = round2(fixed);
  saveDb();
  return { settings: settingsView() };
});

// ---------- static files ----------
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.gif': 'image/gif', '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.json': 'application/json; charset=utf-8', '.webmanifest': 'application/manifest+json; charset=utf-8', '.txt': 'text/plain; charset=utf-8', '.xml': 'application/xml; charset=utf-8', '.woff2': 'font/woff2' };
const COMPRESSIBLE = new Set(['.html', '.js', '.css', '.svg', '.json', '.webmanifest', '.txt', '.xml']);
const staticCache = new Map(); // file -> { mtimeMs, size, data, etag, br, gzip }
function staticEntry(file) {
  const st = fs.statSync(file);
  if (!st.isFile()) throw new Error('not a file');
  let e = staticCache.get(file);
  if (e && e.mtimeMs === st.mtimeMs && e.size === st.size) return e;
  const data = fs.readFileSync(file);
  e = { mtimeMs: st.mtimeMs, size: st.size, data, etag: '"' + crypto.createHash('sha1').update(data).digest('base64url').slice(0, 20) + '"' };
  if (staticCache.size > 400) staticCache.clear();
  staticCache.set(file, e);
  return e;
}
function serveStatic(req, res, pathname, search = '') {
  let rel;
  try { rel = decodeURIComponent(pathname); } catch { res.writeHead(400); return res.end('Bad request'); }
  if (rel === '/' || !path.extname(rel)) rel = '/index.html';
  const file = path.normalize(path.join(PUBLIC, rel));
  if (!file.startsWith(PUBLIC + path.sep)) { res.writeHead(403); return res.end('Forbidden'); }
  let e;
  try { e = staticEntry(file); } catch { res.writeHead(404, { 'Content-Type': 'text/plain' }); return res.end('Not found'); }
  const ext = path.extname(file).toLowerCase();
  // Files with ?v= in the address (and uploaded images, whose names never change) are cached for a year.
  // The page itself is always re-checked so visitors get the newest version straight away.
  const versioned = /[?&]v=/.test(search) || rel.startsWith('/uploads/');
  const cache = ext === '.html' || ext === '.webmanifest' || rel === '/sw.js' ? 'no-cache' : versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=86400, stale-while-revalidate=604800';
  const headers = { 'Content-Type': MIME[ext] || 'application/octet-stream', 'Cache-Control': cache, 'ETag': e.etag, ...SECURITY_HEADERS };
  if (COMPRESSIBLE.has(ext)) headers['Vary'] = 'Accept-Encoding';
  // The page includes the preloader settings, so its version tag changes when they change.
  const preHtml = ext === '.html' && e.data.includes('__PRELOADER__') ? preloaderHtml() : null;
  if (preHtml !== null) headers['ETag'] = e.etag.slice(0, -1) + '-' + crypto.createHash('sha1').update(preHtml).digest('base64url').slice(0, 8) + '"';
  if (req.headers['if-none-match'] === headers['ETag']) { res.writeHead(304, headers); return res.end(); }
  let body = e.data;
  if (ext === '.html' && e.data.includes('__ORIGIN__')) {
    // Social media previews need full web addresses, so fill in this site's address.
    const origin = requestOrigin(req);
    e.byOrigin ||= new Map();
    const key = origin + '\n' + (preHtml || '');
    let o = e.byOrigin.get(key);
    if (!o) { o = { data: Buffer.from(e.data.toString('utf8').split('__ORIGIN__').join(origin).split('__PRELOADER__').join(preHtml || '')) }; if (e.byOrigin.size > 20) e.byOrigin.clear(); e.byOrigin.set(key, o); }
    const enc2 = pickEncoding(req);
    if (enc2) { o[enc2] ||= compress(o.data, enc2); body = o[enc2]; headers['Content-Encoding'] = enc2; } else body = o.data;
    headers['Content-Length'] = body.length;
    res.writeHead(200, headers);
    return res.end(req.method === 'HEAD' ? undefined : body);
  }
  const enc = COMPRESSIBLE.has(ext) && e.data.length > 1024 ? pickEncoding(req) : null;
  if (enc) {
    if (!e[enc]) e[enc] = enc === 'br'
      ? zlib.brotliCompressSync(e.data, { params: { [zlib.constants.BROTLI_PARAM_QUALITY]: 11 } })
      : zlib.gzipSync(e.data, { level: 9 });
    body = e[enc]; headers['Content-Encoding'] = enc;
  }
  headers['Content-Length'] = body.length;
  res.writeHead(200, headers);
  res.end(req.method === 'HEAD' ? undefined : body);
}

// ---------- SEO: robots.txt + sitemap.xml ----------
function seoFile(req, res, name) {
  const origin = requestOrigin(req);
  let body, type;
  if (name === 'robots') {
    type = 'text/plain; charset=utf-8';
    body = `User-agent: *\nAllow: /\nDisallow: /api/\n\nSitemap: ${origin}/sitemap.xml\n`;
  } else {
    type = 'application/xml; charset=utf-8';
    const now = Date.now();
    const evs = db.events.filter((e) => e.status === 'published' && new Date(e.endsAt).getTime() > now);
    body = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n  <url><loc>${h(origin)}/</loc><changefreq>daily</changefreq><priority>1.0</priority></url>\n${evs.map((e) => `  <url><loc>${h(origin)}/e/${e.id}</loc><lastmod>${(e.updatedAt || e.createdAt || new Date().toISOString()).slice(0, 10)}</lastmod><changefreq>daily</changefreq><priority>0.8</priority></url>`).join('\n')}\n</urlset>\n`;
  }
  let buf = Buffer.from(body);
  const headers = { 'Content-Type': type, 'Cache-Control': 'public, max-age=3600', 'Vary': 'Accept-Encoding', ...SECURITY_HEADERS };
  const enc = buf.length > 1024 ? pickEncoding(req) : null;
  if (enc) { buf = compress(buf, enc); headers['Content-Encoding'] = enc; }
  res.writeHead(200, headers);
  res.end(buf);
}

// ---------- share pages (link previews for WhatsApp, Facebook, X, iMessage…) ----------
const OG_CAT = { 'Music': 'music', 'Nightlife': 'nightlife', 'Comedy': 'comedy', 'Food & Drink': 'food', 'Sports': 'sports', 'Arts': 'arts', 'Business': 'business', 'Community': 'community' };
function requestOrigin(req) {
  const proto = String(req.headers['x-forwarded-proto'] || '').split(',')[0].trim() || (req.socket.encrypted ? 'https' : 'http');
  const host = String(req.headers['x-forwarded-host'] || req.headers.host || `localhost:${PORT}`).split(',')[0].trim();
  return /^[\w.:-]+$/.test(host) ? `${proto === 'https' ? 'https' : 'http'}://${host}` : site();
}
// Google "Event" rich results (shows date, venue and price in search)
function eventJsonLd(ev, origin, img) {
  const open = ev.tiers.filter((t) => isPublicTier(t));
  const venues = [{ name: ev.venue, address: ev.address, city: ev.city }, ...(ev.extraVenues || [])];
  const place = (v) => ({ '@type': 'Place', name: v.name, address: { '@type': 'PostalAddress', streetAddress: v.address || undefined, addressLocality: v.city || undefined, addressCountry: db.settings.maps?.country || 'BS' } });
  return {
    '@context': 'https://schema.org', '@type': 'Event', name: ev.title, description: ev.description.slice(0, 500),
    startDate: ev.startsAt, endDate: ev.endsAt, image: [img],
    eventStatus: ev.status === 'cancelled' ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
    eventAttendanceMode: 'https://schema.org/OfflineEventAttendanceMode',
    location: venues.length > 1 ? venues.map(place) : place(venues[0]),
    organizer: (ev.organizers && ev.organizers.length ? ev.organizers : [ev.organizerName]).filter(Boolean).map((n) => ({ '@type': 'Organization', name: n })),
    offers: open.map((t) => ({ '@type': 'Offer', name: t.name, price: t.price.toFixed(2), priceCurrency: 'USD', url: `${origin}/e/${ev.id}`, availability: t.quantity > t.sold ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut', validFrom: ev.createdAt })),
  };
}
function sharePage(req, res, id) {
  const ev = db.events.find((e) => e.id === id && e.status !== 'draft');
  const origin = requestOrigin(req);
  const target = ev ? `/#/event/${ev.id}` : '/';
  const title = ev ? ev.title : 'TicketJunkies.com';
  const price = ev ? (() => { const open = ev.tiers.filter((t) => isPublicTier(t) && t.quantity > t.sold); if (!open.length) return 'Sold out'; const min = Math.min(...open.map((t) => t.price)); return min === 0 ? 'Free entry' : `Tickets from $${min.toFixed(2)}`; })() : '';
  const desc = ev
    ? `📅 ${when(ev.startsAt)} · 📍 ${[ev.venue, ev.city].filter(Boolean).join(', ')}${price ? ` · 🎟️ ${price}` : ''}${ev.status === 'cancelled' ? ' · CANCELLED' : ''}. ${ev.description.replace(/\s+/g, ' ').slice(0, 140)}`
    : 'Discover events and buy tickets on TicketJunkies.com';
  const flyer = ev && (ev.imageShareUrl || ev.imageUrl);
  const img = flyer ? (flyer.startsWith('/') ? origin + flyer : flyer) : `${origin}/og/${(ev && OG_CAT[ev.category]) || 'default'}.png`;
  const url = `${origin}/e/${ev ? ev.id : ''}`;
  const html = `<!doctype html><html lang="en"><head><meta charset="utf-8">
<title>${h(title)} | TicketJunkies.com</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="${h(desc)}">
<link rel="canonical" href="${h(url)}">
<meta property="og:type" content="website"><meta property="og:site_name" content="TicketJunkies.com">
<meta property="og:title" content="${h(title)}"><meta property="og:description" content="${h(desc)}">
<meta property="og:url" content="${h(url)}"><meta property="og:image" content="${h(img)}"><meta property="og:image:alt" content="${h(title)}">
${ev && ev.imageUrl ? '' : '<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">'}
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="${h(title)}"><meta name="twitter:description" content="${h(desc)}"><meta name="twitter:image" content="${h(img)}">
<meta property="og:locale" content="en_US">${ev ? `<meta property="event:start_time" content="${h(ev.startsAt)}"><meta property="event:end_time" content="${h(ev.endsAt)}">` : ''}
<link rel="icon" href="/favicon.png"><link rel="apple-touch-icon" href="/apple-touch-icon.png">
${ev ? `<script type="application/ld+json">${JSON.stringify(eventJsonLd(ev, origin, img)).replace(/</g, '\\u003c')}</script>` : ''}
<meta http-equiv="refresh" content="0;url=${h(target)}">
<script>location.replace(${JSON.stringify(target)});</script>
</head><body style="font-family:system-ui,sans-serif;text-align:center;padding:60px 20px"><img src="/logo.png" alt="TicketJunkies.com" style="max-width:320px"><p>Opening <a href="${h(target)}">${h(title)}</a>…</p></body></html>`;
  if (ev) ev.shareViews = (ev.shareViews || 0) + 1;
  let out = Buffer.from(html);
  const sh = { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'public, max-age=300', 'Vary': 'Accept-Encoding', ...SECURITY_HEADERS };
  const enc = out.length > 1024 ? pickEncoding(req) : null;
  if (enc) { out = compress(out, enc); sh['Content-Encoding'] = enc; }
  res.writeHead(ev ? 200 : 404, sh);
  res.end(out);
}

// ---------- server ----------
loadDb();
const server = http.createServer(async (req, res) => {
  for (const [k, v] of Object.entries(BASE_HEADERS)) res.setHeader(k, v);
  // On https, tell browsers to always use https for this site (6 months)
  if (requestOrigin(req).startsWith('https:')) res.setHeader('Strict-Transport-Security', 'max-age=15552000');
  let url;
  try { url = new URL(req.url, 'http://localhost'); } catch { res.writeHead(400); return res.end('Bad request'); }
  const qr = /^\/qr\/(TJ-[0-9A-F]{8})\.(png|svg)$/.exec(url.pathname);
  if (qr) {
    const png = qr[2] === 'png';
    res.writeHead(200, { 'Content-Type': png ? 'image/png' : 'image/svg+xml', 'Cache-Control': 'public, max-age=31536000, immutable' });
    return res.end(png ? qrPng(qr[1], 10) : qrSvg(qr[1]));
  }
  if (url.pathname === '/qr/link.svg') {
    // QR of a ticket-page link (used by the sandbox to open tickets on a phone)
    const u = url.searchParams.get('u') || '';
    if (!/^https?:\/\/[\w.:-]+\/#\/t\/ord_[0-9a-f]+\/[0-9a-f]+$/.test(u) || u.length > 180) { res.writeHead(400); return res.end('Bad link'); }
    res.writeHead(200, { 'Content-Type': 'image/svg+xml', 'Cache-Control': 'no-store' });
    return res.end(qrSvg(u));
  }
  const share = /^\/e\/(evt_[0-9a-f]+)\/?$/.exec(url.pathname);
  if (share) return sharePage(req, res, share[1]);
  if (url.pathname === '/qr/event.svg') {
    // QR code of an event's share link (for posters / flyers)
    const id = url.searchParams.get('id') || '';
    if (!/^evt_[0-9a-f]+$/.test(id) || !db.events.some((e) => e.id === id)) { res.writeHead(404); return res.end('Not found'); }
    res.writeHead(200, { 'Content-Type': 'image/svg+xml', 'Cache-Control': 'public, max-age=3600' });
    return res.end(qrSvg(`${requestOrigin(req)}/e/${id}`));
  }
  if (url.pathname === '/robots.txt') return seoFile(req, res, 'robots');
  if (url.pathname === '/sitemap.xml') return seoFile(req, res, 'sitemap');
  if (!url.pathname.startsWith('/api/')) return serveStatic(req, res, url.pathname, url.search);
  try {
    const r = routes.find((x) => x.method === req.method && x.re.test(url.pathname));
    if (!r) fail(404, 'Not found');
    const m = r.re.exec(url.pathname);
    let params;
    try { params = Object.fromEntries(r.keys.map((k, i) => [k, decodeURIComponent(m[i + 1])])); } catch { fail(400, 'Bad address'); }
    const rk = `${req.method} ${url.pathname}`;
    if (RATE_LIMITS[rk]) rateLimit(req, rk, RATE_LIMITS[rk]);
    else if (req.method === 'POST' && /^\/api\/events\/[^/]+\/checkout$/.test(url.pathname)) rateLimit(req, 'checkout', [20, 10 * 60000]);
    // Only image uploads may be large; everything else is small JSON.
    const body = ['POST', 'PUT', 'PATCH'].includes(req.method) ? await readBody(req, url.pathname === '/api/upload' ? MAX_BODY : 2 * 1024 * 1024) : {};
    const result = await r.handler({ req, res, body, params, query: url.searchParams });
    if (result !== RAW_SENT) send(res, 200, result);
  } catch (e) {
    if (!(e instanceof HttpError)) console.error(e);
    send(res, e.status || 500, { error: e instanceof HttpError ? e.message : 'Something went wrong' });
  }
});
// Slow-connection protection: drop clients that send headers or requests too slowly.
server.headersTimeout = 20000;
server.requestTimeout = 120000;
server.keepAliveTimeout = 5000;
server.listen(PORT, () => {
  console.log(`TicketJunkies running at http://localhost:${PORT}`);
  // Locate any events that don't have map coordinates yet (runs quietly in the background).
  (async () => { for (const e of db.events.filter((x) => !validLatLng(x.lat, x.lng))) await ensureGeo(e); })().catch((e) => console.error('[geo]', e.message));
});
