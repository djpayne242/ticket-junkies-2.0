// Packs the live TicketJunkies data (events, orders, accounts, settings, flyer images) into one file
// for WordPress → TicketJunkies → "Bring over your existing TicketJunkies data".
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url));
const site = path.resolve(here, '../../ticketjunkies');
const out = process.argv[2] || path.resolve(here, '../ticketjunkies-import.json');
const db = JSON.parse(fs.readFileSync(path.join(site, 'data/db.json'), 'utf8'));
delete db.sessions; // logins are not carried over
const images = {};
const mime = { '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.gif': 'image/gif' };
for (const e of db.events) for (const k of ['imageUrl', 'imageShareUrl']) {
  const u = e[k];
  if (u && u.startsWith('/uploads/') && !images[u]) {
    const f = path.join(site, 'public', u);
    if (fs.existsSync(f)) images[u] = `data:${mime[path.extname(f).toLowerCase()] || 'application/octet-stream'};base64,` + fs.readFileSync(f).toString('base64');
  }
}
fs.writeFileSync(out, JSON.stringify({ version: 1, exportedAt: new Date().toISOString(), db, images }));
console.log(`wrote ${out}: ${db.users.length} users, ${db.events.length} events, ${db.orders.length} orders, ${Object.keys(images).length} images, ${(fs.statSync(out).size / 1024).toFixed(0)} KB`);
