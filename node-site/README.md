# TicketJunkies.com

An Eventbrite-style ticketing platform: organizers create and sell events, attendees discover events and buy tickets.

## Run it

Needs Node.js 18+ only (no packages to install).

```bash
cd ticketjunkies
node server.mjs          # http://localhost:4321
node server.mjs 8080     # custom port
```

Data is stored in `data/db.json` (delete it to reset to the demo data). Uploaded flyers go to `public/uploads/`.

### Demo accounts
| Role | Email | Password |
|---|---|---|
| **Administrator (owner)** | djpayne242@gmail.com | (your private password) |
| Organizer (demo) | organizer@ticketjunkies.com | organizer123 |
| Attendee | fan@ticketjunkies.com | fan12345 |

The owner account controls the Admin Panel and can also post events ("My Events" / "+ Create Event"). If the database ever has no admin, the server creates a fallback admin@ticketjunkies.com / admin12345 at startup; change its password immediately if that happens. Change or remove the demo accounts before launch. They're created in `seed()` in `server.mjs`.


**Footer stays at the bottom (v1.2.11):** while a page loads (a reload or a click to another page), the page area fills the whole screen, so the footer never pops up in the middle and then jumps down. On short pages (like Log in) the footer sits on the bottom edge of the screen. It works for both versions; on WordPress a tiny script placed just before the footer sets this up before the footer is drawn.

**Speed & security (v1.2.10):**
- **Security headers** on every page and API answer: no framing by other sites (clickjacking), no MIME sniffing, a strict referrer, and unused browser features (microphone, payments, USB) switched off. Camera (QR check-in) and location still work. On https, browsers are told to always use https (HSTS), and the log-in cookie is marked Secure.
- **Speed limits against bots:** each visitor may try 20 log-ins per 10 minutes, 10 sign-ups per hour, 5 password-reset emails per 15 minutes, 20 checkouts per 10 minutes and 60 uploads per hour. After that they see "Too many attempts. Please wait N minute(s)". Other visitors aren't affected. (On WordPress, the log-in, sign-up, checkout and upload limits apply; password resets go through WordPress's own reset page.)
- **Safer uploads:** pictures must really be PNG/JPG/WEBP/GIF (the file's contents are checked, not just its name). Requests other than uploads are limited to 2 MB, and slow-drip connections are dropped.
- **Faster pages:** event cards load a light 800 px copy of the built-in flyers (`public/flyers/*-card.jpg`, about 70% less image data on the home page). The full flyer is still used on the event page. The database file is saved compact, so saving is quicker as orders grow.
- **WordPress plugin extras** (each can be turned off with a filter such as `tj_harden_xmlrpc`): XML-RPC log-ins and pingbacks are off, `?author=1` and the public users list no longer reveal log-in names, the WordPress version tag is hidden, and the emoji and embed scripts no longer load on every page.

**Ticket types (Paid / Free / Donation):** every ticket type has a Ticket type box. Paid = fixed price above $0. Free = $0 (the price box hides; free-only orders skip the card form). Donation = the buyer chooses the amount per ticket, at least the organizer's Minimum donation (can be $0 for pay-what-you-want), with an optional Suggested amount that is pre-filled. Service fees are worked out on the amount actually paid. Once a ticket type has sales its type and price/minimum are locked. Revenue in dashboards and reports counts what donors actually gave. Older ticket types without a type count as Paid (price above $0) or Free ($0).

**Countdowns:** every event card shows a large "⏳ Goes live in" countdown with Days / Hours / Minutes / Seconds boxes counting down to the event's start time, the event page has a large "This event goes live in" Days / Hours / Minutes / Seconds countdown, and the calendar pop-up shows one too. When the event starts it switches to "Live now" ("🔴 This event is LIVE now!" on the event page), then "Ended".

**Demo event flyers:** ready-made 16:9 flyers (generated with GPT Image 2) for Friday Night Afrobeats Takeover, Island Vibes Summer Fest, Startup Pitch Night, Laugh Out Loud Comedy Night, Taste of the Islands Food Festival and Junkanoo Rush Experience.
- **Files:** `public/flyers/*.jpg` (1600 px), plus a `-share.jpg` copy under 300 KB for WhatsApp previews. The WordPress copies are in `assets/seed/flyers`.
- **Attaching:** each is added once to the event with that exact title, and only if the event has no picture. A flyer the organizer uploads is never replaced. Node records this in `settings.bundledFlyers`; WordPress uploads them to the Media Library and records it in the option `tj_bundled_flyers`.
- **Event pages:** now show the whole flyer (16:9, no cropping).

**Where people land after logging in/out:**
- Admins go to the Admin Panel, organizers to the Organizer Dashboard, and attendees to My Ticket Dashboard.
- A purchase in progress (checkout, cart, order or ticket link) still returns to where the person was.
- Logging out always goes to the home page.
- On WordPress, attendees and organizers using wp-login.php also go to their dashboard. WordPress staff still go to wp-admin, and wp-login log-outs go home.

**Shopping cart:**
- **Header:** a 🛒 button with a count. On WordPress it is added to the TicketJunkies header and opens the Checkout page at `#/cart`.
- **Event pages (one flow):** 1 · Choose tickets → 2 · Add to cart → 3 · Checkout. The buy box shows these steps and a single **Add to cart** button. After adding, a green box says "✅ N tickets added to your cart" with a big **Checkout** button (plus Keep shopping and View cart). Events already in the cart show **Update cart** and **Checkout**. Checkout opens `#/cart/checkout`, after sign-up or log-in if needed. The cart and checkout pages show the steps Tickets → Cart → Checkout → Your tickets.
- **Saving:** the cart is kept in the browser (localStorage `tj_basket`) for up to 30 days, works before logging in, and stays in step across tabs.
- **Cart page (`#/cart`):** each event with a picture, date and venue, a quantity menu per ticket type, Remove, Empty cart and a live total. It rechecks availability: quantities are trimmed to what is left, the per-person limit and sales windows apply, and ended or cancelled events must be removed.
- **Cart checkout (`#/cart/checkout`):** account required, one form, one payment. "Who's coming?" appears per event.
- **Server (`POST /api/cart/checkout`, WP `/tj/v1/cart/checkout`):** checks every event first, so **either the whole cart is bought or nothing is**, then creates one order per event linked by a shared `cartId`. Each order sends its usual emails (buyer, guests, organizer, admin). On WordPress all events in the cart are locked during the purchase.

**Ticket sale emails:**
- **Buyer:** a QR code for every ticket, plus all tickets attached as one PDF (and QR images), with a "Download tickets (PDF)" button.
- **Each guest:** at checkout, "Who's coming?" lets the buyer add a name and email for every ticket after the first. Each guest with an email gets their own email with only their ticket(s), a PDF attachment and a personal download link (`/api/tickets/<order>/pdf?code=…&tk=…`). The link is signed per ticket, so it never opens the rest of the order. Their name is printed on the PDF and shown at check-in.
- **Organizer:** order number and time, buyer name, email and phone, ticket types, revenue, and a table of every attendee (name, email, ticket type, code).
- **Admin:** everything above, plus organizer contact, platform fees, total collected, payment card (brand, last 4, name on card) and how many guest emails were sent.
- **Complimentary tickets:** still email only the recipient.
- **Attendee list and CSV:** show each ticket's attendee, with "Bought by" when someone else paid.

**Log in & security (Admin → Settings → 🔐 Log in & security):**
- **Log-in timer:** how long attendees/organizers stay logged in, and a separate (usually shorter) time for administrators, from 1 hour to 90 days.
- **Automatic log-out after no activity:** off, or 5 minutes to 4 hours. A "Still there?" warning with a **Stay logged in** button appears 1 minute before. Activity in any open tab counts. The server also ends idle sessions, and the log-in page explains why the person was logged out.
- **Wrong passwords:** lock the log-in after 3, 5 or 10 wrong tries for 5 minutes to 1 day (or off). The last 2 tries show a warning.
- **Passwords:** turn the "Forgot your password?" link on/off, choose how long reset links work (15 minutes to 1 day), and set the shortest password allowed (8–16 characters).
- **Buttons:** **Log everyone else out now**. On each user page: **Send password reset link** and **Log out of all devices**.
- **Stand-alone site:** reset emails link to `#/reset?token=…`. Links can be used once, there are at most 3 open links per person, and the forgot form gives the same answer whether or not the email has an account.
- **WordPress:** uses WordPress's own reset email and page, with these time limits applied. New time limits apply from the next log-in.

**Admin menu:** the Admin Panel tabs (Overview, Users, Organizers … Settings) sit on one slim line. On narrower screens the line scrolls sideways instead of wrapping onto a second row.

**Preloader (Admin → Settings → Preloader):** a small icon in the middle of the screen while a page opens or refreshes, and when moving between pages. It shows no "Loading" text. Pick one of 8 built-in icons (TicketJunkies, ticket, vinyl record, ring, music, star, lightning, heart) or upload your own PNG/JPG/WEBP/GIF (up to 2 MB). Choose its size (24–160 px), animation (spin, pulse, rumble, flash, bounce, flip or stand still), speed (slow/normal/fast) and background (cover the page, see-through dim, or icon only). It has an on/off switch, a live preview and a "Try it full screen" button. The stand-alone site puts it into the page itself, so it appears before anything else draws. In WordPress it is added at the top of every page and also shows when you click a link to another page. Saving clears the LiteSpeed page cache so the change shows straight away. It always hides after 8 seconds at most.

**Max per person (per ticket type):** an optional number from 1 to 100. It is the most tickets of that type one account can buy for the event, counted across all of that person's orders (refunded and complimentary orders don't count). Buyers see "👤 Limit 2 per person · you have 1", the quantity picker only goes up to what they have left, and once they hit the limit the ticket shows "Limit reached". The server enforces it too. Without a limit the usual 10 per order applies.

**Ticket sales window (per ticket type):** each ticket type has optional Sales start date, Sales start time, Sales end date and Sales end time boxes (e.g. Early Bird until Friday 11:59 PM, Door from Saturday 6 PM). A date without a time uses 12:00 AM for the start and 11:59 PM for the end; a time without a date is not allowed. Before the start buyers see the price and "🕒 On sale in 2d 03h 10m 05s" instead of a quantity picker; while selling they see "⌛ Sales end in …"; afterwards the ticket is greyed out as "Sales ended". On the event page each ticket's countdown is a full-width bar at the bottom of that ticket (below its name, price and quantity picker); tickets not on sale yet also show a "Coming soon" tag on the right. The page switches between these by itself when a countdown reaches zero. The server refuses purchases outside the window. Sales end must be after sales start and no later than the event's end. Event cards and the calendar show "On sale soon" when nothing is on sale yet and "Sales ended" when every ticket type has closed.

**Event cards:** each card shows the lowest available price ("From $25.00", "Free", "Donation · from $5.00" or "Pay what you want") next to an orange "Get tickets →" button; sold-out events show "Sold out" and "View event".

**Search filters:** the homepage search has a keyword box plus Category, Organizer, Venue and Date (a calendar picker; shows events happening on that day in Nassau time, including overnight events that started the evening before). The search panel runs straight across the whole orange-and-blue box on wide screens. The Organizer and Venue lists fill themselves from upcoming published events (`GET /api/search-options`). The dropdowns search right away; "Clear search" resets everything. Links like `/?organizer=Dj%20Payne%20Events` or `/?date=2026-12-30` work too, and the server still accepts `year` and `time` in links.

**Calendar of events:** under the map, a month calendar (`GET /api/calendar?month=YYYY-MM`, Nassau time) highlights event days in orange and public holidays in green. Today is outlined in blue. Tapping a highlighted day opens a pop-up with the holiday name and each event: flyer, start and end time, venue, organizer, category, a short description, the lowest ticket price (or Sold out / Ended) and a Get tickets button. ‹ › change month and "Today" jumps back. An overnight party that ends before 6 AM only shows on the night it starts. On phones the day boxes show dots instead of names.

**Public holidays:** they follow the country in Admin → Settings → Maps & weather. The Bahamas are calculated on the site from the Public Holidays Act rules (New Year's Day, Majority Rule Day, Good Friday, Easter Monday, Whit Monday, Randol Fawkes Labour Day, Independence Day, Emancipation Day, National Heroes' Day, Christmas Day, Boxing Day; a holiday on a Sunday is observed on the Monday), because online holiday databases list wrong Bahamian dates. Other countries are fetched from date.nager.at and cached for a week. Extra days the government announces at short notice are not included. In WordPress, pages that have the events map get the calendar automatically (turn off with `add_filter('tj_auto_calendar', '__return_false')`). It is also the Elementor widget "TJ Calendar of Events" and `[ticketjunkies widget="calendar"]`.

**Clock & weather bar:** the live clock and Nassau weather sit in one slim bar above the orange-and-blue box (not inside it). On computers it is a single line: time, short date, time zone, current weather and as many forecast days as fit. On phones it is two short lines (time and date, then current weather). In WordPress, `[ticketjunkies widget="weather"]` / the Elementor widget "TJ Clock & Weather Bar" shows the same bar, and pages with the separate search + weather blocks get the bar placed above the box automatically.

**Everyone**
- Browse upcoming events, search by keyword, filter by category
- Event pages with date, venue, map link, description and ticket types
- Sign up as an **Attendee** (buys tickets) or an **Organizer** (creates events)

**Attendees**
- Pick ticket quantities per ticket type, see fees and the total before paying
- Checkout (login/sign-up redirect keeps the cart), order confirmation
- **My Tickets**: upcoming and past orders with unique ticket codes (e.g. `TJ-1A2B3C4D`)

**Organizers**
- Dashboard: events, tickets sold, gross sales, check-ins
- Create and edit events: title, category, description, start/end, venue, address, city, flyer upload, multiple ticket types (price, quantity; free tickets supported)
- Publish, unpublish (draft), cancel or delete
- Attendee list with CSV export and door check-in by ticket code (blocks duplicate check-ins)
- Safeguards: can't lower quantity below tickets sold, change price after sales, or delete ticket types that have sales

**Administrator (Admin Panel)**
- Overview: total accounts, tickets sold, money collected, your platform fees, recent orders and sign-ups
- Users: search and filter attendees, organizers, admins and suspended accounts. Edit name, email, account type and organization, set a new password, suspend or reactivate, or delete. Add new users, including more admins.
- Each user's page shows their orders (with refunds) and the events they posted
- Events: see every event from every organizer, including drafts. Edit, publish, unpublish, cancel or delete, and view attendees.
- Orders: search by buyer, email, event, order ID or ticket code. Refund an order: its tickets are voided and the seats go back on sale.
- Settings: change the service fee (percentage and fixed amount)
- Safeguards: you can't suspend or demote yourself, and at least one active admin always remains. Suspended users are logged out at once and can't sign in.

**🛒 Checkout page & ticket downloads**
- **Flow:** pick tickets on the event page → **Continue to checkout** → (not logged in) **Create your account** page, with a Log in link → the **Checkout page** (`#/checkout/<eventId>`) → confirmation → **My Dashboard**.
- **Checkout page:**
  - a progress bar (Tickets → Account ✓ → Checkout → Your tickets)
  - the customer's details and payment
  - an order summary with event image, date, venue, ticket lines, fees, total, and a **Change tickets** link
  - Opening the checkout page without an account always redirects to sign-up, and the server also refuses checkout without login.
- **Attendee details:** checkout asks for the **Attendee first name** and **Attendee last name**, both required and enforced by the server. They're pre-filled from the account and saved separately on the order (`buyerFirstName` / `buyerLastName`, plus the combined `buyerName`). The attendee list CSV and the report exports (attendee list, CSV, PDF, Word, Excel) have separate **First name** and **Last name** columns. Older orders are split automatically.
- **Downloads and resends** (dashboard, order confirmation and public ticket page):
  - **⬇ Download tickets (PDF):** a printable PDF with one branded ticket per page, a large scannable QR code, the ticket code, attendee, order # and price.
  - **⬇ QR** under each ticket saves that QR code as an image.
  - **📧 Resend email** / **📱 Resend text** sends the tickets again to the customer only (no extra sale alerts), up to 3 times per 15 minutes.
  - **🔗 Full-screen tickets** opens the ticket page for showing at the door.
- **Download links:**
  - `/api/orders/<id>/tickets.pdf` works for the logged-in owner, the event's organizer or an admin.
  - `/api/tickets/<id>/pdf?k=<key>` is the private link included in ticket emails and texts.

**🤖 reCAPTCHA look:** the robot-check box has no visible box. Its background blends into the page in light mode (white) and dark mode (dark navy), so only the checkbox, text and logo show. Google draws the widget in a locked frame, so this is done with CSS color filters on the frame (see the end of `styles.css`). The small red 'for testing purposes only' line disappears once real reCAPTCHA keys are added in Admin → Settings.

**🔒 Account required to check out + attendee dashboard**
- **Account first:** customers must have a free attendee account before checking out, and the server refuses checkout without one.
  - Logged-out visitors who pick tickets see a "Create your free account to check out" step with **Create free account** and **Log in** buttons.
  - Their ticket selection is held while they sign up or log in.
  - Afterwards they go straight back to checkout, which scrolls into view with the same tickets selected.
- **My Ticket Dashboard** (`#/tickets`, "🎟️ My Dashboard" in the menu):
  - totals for upcoming events, tickets, total spent and events attended
  - upcoming and past orders with QR tickets
  - a full purchase history: date, order #, event, tickets, card used, total, status
- Attendees land on their dashboard after logging in or signing up.
- Complimentary tickets sent to an email with no account are linked to that person's account automatically when they sign up or log in.

**🎟️ Ticket descriptions, private tickets & complimentary tickets**
- **Description (optional):** every ticket type can have a short description, for example "Includes 1 free drink + fast-track entry", shown under the ticket on the event page.
- **Visibility:** each ticket type is either 🌐 **Public** (on sale) or 🔒 **Private**, set in Create/Edit event → Tickets.
  - Private ticket types are hidden from the public and **can't be bought at checkout**; the server blocks it too.
  - The organizer and admins still see them on the event page, marked PRIVATE.
- **Complimentary tickets:** on the event's **Attendees** page, use **🎁 Complimentary tickets**.
  - Pick any ticket type (public or private), choose how many (1–50), and enter the guest's name, email, an optional mobile number and an optional message.
  - The guest gets a "You're on the list!" email (and a text) with QR tickets that scan at the door like normal tickets.
  - Organizers can comp their own events; admins can comp any event.
- **Accounting:**
  - Comps count toward the ticket type's capacity but **not** as sales or revenue.
  - Comps never trigger "ticket sold" alerts.
  - They're labeled 🎁 Comp in attendees, orders and reports, and as "Complimentary" in PDF/Word/Excel/CSV exports.
  - Refunding (voiding) a comp returns the seats.

**🕒 Homepage clock**
- **Where:** a live clock sits just beneath the weather box on the homepage.
- **What it shows:** the time with seconds and AM/PM, then the day, date, month and year (for example "Sunday, November 22, 2026").
- **Time zone:** each visitor's own browser time zone, which the clock names, for example "America/Nassau (EST)".
- **Updates:** every second, and it works on phones.

**📱 Layout & mobile**
- **Wide layout:** pages use most of the screen width, up to 1640px (1800px on very large monitors), with slim side margins that adjust to the screen size.
- **Phones:**
  - a compact header and ☰ menu
  - a stacked search box
  - swipeable category chips and admin tabs
  - full-width forms and buttons (text boxes don't trigger iPhone zoom)
  - single-column event cards
- **Tables on phones:** they become easy-to-read cards (each row is a card with labels), including orders, events, users, attendees and reports.

**📈 Sales reports** (Admin Panel → Reports · organizers: Dashboard → Sales reports)
- **Filters:** all events or one event, sales date range, paid / refunded / both, and (admin) include sandbox tests.
- **Summary:** tickets sold, orders, ticket sales, platform fees, total collected, average order, unique buyers, unique cards, refunds, check-ins.
- **Sales by day** chart.
- **By event** table (click an event to drill in). For a single event it breaks down by ticket type.
- **Tickets purchased per card** (admin only): card brand and last 4, name(s) on card, buyer emails, orders, tickets, total spent, events, and first and last purchase. Automatic flags: many tickets, a card used by several emails, different names, refunds.
- **Transactions & attendees:** sale date and time, order #, event, buyer name, email and phone, card (admin), ticket types and quantity, ticket codes, check-ins, amounts, status and refund date. A live search box filters the table.
- **Where to find it:** the **📈 Reports** link in the top menu (on phones and narrow windows, tap ☰ first). It is also the Admin Panel → Reports tab, and organizers reach it from Dashboard → Sales reports.
- **Download / export** (the "Download this report" panel): tick the sections you want (summary, sales by day, by event or ticket types, per card (admin), transactions, attendee list), then choose a format:
  - **📄 PDF:** branded, landscape, page numbers, ready to print or email.
  - **📝 Word (.docx):** an editable document with formatted tables.
  - **📊 Excel (.xlsx):** one tab per section, with real numbers, dollar formatting, dates, filters and frozen header rows.
  - **🧾 CSV:** plain spreadsheet data for any app.
  - **🖨 Print:** prints the on-screen page.
- Files are built by the server itself (`exporters.mjs`, no add-ons needed) and always use the current filters. Organizer exports never include card details.
- The small "⬇ CSV" buttons on each table still download that single table.
- **Card data & PCI-DSS:**
  - Full card numbers and CVC codes are **never stored**, as the card industry requires.
  - Each order keeps the card brand, last 4 digits, name on card, and a one-way **card fingerprint** (HMAC-SHA256 with a secret key). The same card always gives the same fingerprint, so purchases are grouped per card, but the number can't be recovered.
  - When Stripe is connected, Stripe's own card fingerprint can be used instead.
  - Checkout now asks for the **name on card** and checks card numbers (Luhn) and expiry dates.
- Organizers only see reports for their own events, without card details.

**🤖 Google reCAPTCHA (bot & spam protection)**
- The "I'm not a robot" checkbox (reCAPTCHA v2) is on the **registration page** (attendee and organizer sign-up) and the **ticket checkout**.
- The server verifies every answer with Google before creating an account or selling tickets, so bots can't bypass it by skipping the page.
- The checkbox matches the light or dark theme, resets after an error, and asks again if it expires.
- Admin-created accounts and Sandbox test sales skip it. If Google is briefly unreachable, the sale goes through (logged) rather than blocking real customers.
- **Comes with Google's public test keys**: it works immediately but always passes and shows a red "testing purposes only" note.
- **Before launch:**
  1. Go to google.com/recaptcha/admin and create a site: reCAPTCHA v2 → "I'm not a robot" Checkbox.
  2. Add the domains `ticketjunkies.com` and `www.ticketjunkies.com`.
  3. Paste the **site key** and **secret key** into Admin → Settings → Google reCAPTCHA, and save.
- Each protected area can be switched on or off in the same section.

**🌗 Light / Dark / Auto theme**
- A three-way switch in the top bar on every page: ☀️ Light (day), 🌙 Dark (night), and Auto, which follows the visitor's phone or computer setting and is the default.
- Each visitor's choice is remembered on their device. In Auto mode the site switches live when the device changes (for example a phone going dark at sunset).
- No white flash when loading in dark mode. Maps, weather, tickets, the Admin Panel and every page are themed.
- QR codes and the logo always stay on white so they scan and read clearly. The Google street map embed can't be recolored, so it's softened in dark mode.

**📣 Sharing** (anyone, no login needed)
- **Event page:** a **Share** button, plus one-tap WhatsApp, Facebook, X, text message and copy-link buttons under the title.
- **Share window:** WhatsApp, Facebook, X, Telegram, Text message, Email, Instagram (copies the link for stories, bio or DMs), Copy link, an event **QR code** to download for posters, and on phones the device's own **Share via…** menu (Messenger, Snapchat, AirDrop and more).
- **Share icon on every event card**, a **Share** button for each live event on the organizer dashboard, and a **"Tell your friends you're going!"** prompt after buying tickets.
- **Rich link previews:** shared links use the address `ticketjunkies.com/e/<event>`. WhatsApp, Facebook, X, iMessage, Telegram and others show the event flyer, title, date, venue and lowest price, then open the event page.
  - When a flyer is uploaded, a small copy (≤280 KB) is saved automatically, because WhatsApp ignores large preview images.
  - Events without a flyer get a branded preview card in their category's colors (`public/og/`).
- Visits from shared links are counted per event (hover the dashboard's Share button).

**🗺️ Maps & 🌤️ weather**
- **Front page map:** every live event as a colored pin by category. Click a pin for a preview card (flyer, date, venue, price) with **View event →**. It follows the search and category filters, and events at the same spot are fanned out so every pin can be clicked.
- **Event page map:** a Google Maps close-up of the venue's street, with a Map/Satellite toggle and **Get directions**, **Street View** and **Open in Google Maps** buttons.
- **Event page weather:** the forecast at the event's start time (temperature, conditions, rain chance, wind, high/low, sunset), an hour-by-hour strip for the event, and tips like "bring an umbrella". Events more than about 2 weeks out show the date the forecast becomes available, plus current conditions at the venue.
- **Front page weather:** a small widget in the banner with current conditions and a 5-day outlook (default Nassau; change it in Admin → Settings).
- **Event locations:**
  - Addresses are looked up automatically when an event is saved.
  - In the event form, organizers can click **Find address on map**, then **drag the pin** or click the map to mark the exact venue. A pin an organizer places is never overwritten.
  - Admins can click **Locate events missing a map pin** in Settings.
- **Providers:**
  - Weather comes from Open-Meteo: free, no key.
  - Address lookup uses OpenStreetMap Nominatim: free, no key, limited to 1 lookup per second.
  - Event-page maps are standard Google Maps embeds: no key needed.
  - The front-page map uses OpenStreetMap until you paste a **Google Maps API key** in Admin → Settings, then it switches to Google Maps. For a busy live site, a Google key is recommended. Create it in Google Cloud Console, enable "Maps JavaScript API", and restrict it to `ticketjunkies.com/*`. Google's free monthly credit covers about 10,000 map loads.

**Email & text notifications** (Admin Panel → Settings / Emails & Texts)
- **Administrator** gets emails for: user registration, organizer registration, ticket sales (with your platform fee), and new events. Each can be switched on or off. They go to all admin accounts, or to the address(es) you enter.
- **Organizers** get emails for: welcome, event created or published, event updated, unpublished, cancelled or deleted, every ticket sale (with running totals), ticket types selling out, and refunds.
- **Ticket buyers** get:
  - an email with a scannable **QR code for every ticket** (shown in the email and attached as PNG files)
  - a **text message** with order details and a private link to their QR tickets
  - refund and event-cancellation notices by email and text
- Mobile number is collected at sign-up (optional) and required at checkout. Numbers are formatted automatically (7 digits = Bahamas 242, 10 digits = +1).
- **Door check-in:** organizers can tap **📷 Scan QR** on the attendees page to scan tickets with the phone camera (Chrome/Edge/Android/Safari 17+), or type the code.
- **Preview mode:** until email and SMS are connected, every message is saved in **Emails & Texts** so you can read it exactly as it would be sent.
- QR codes are generated by the site itself (`qr.mjs`). They're served at `/qr/TJ-XXXXXXXX.png` and `.svg` and don't depend on any outside service.

**🧪 Sandbox test mode** (Admin Panel → Sandbox)
- Simulate a ticket sale on any event (drafts too). Choose a ticket type and quantity, and the email and phone the tickets go to.
- It runs the full flow: real QR tickets, the buyer's ticket email (QR codes in the email and attached), the buyer's text, and the organizer and admin sale emails.
- The results screen shows each ticket's QR code, a **Test door check-in** button (a second scan of the same ticket is correctly rejected), every message exactly as sent, and a QR code to open the ticket page on your phone (same Wi-Fi).
- With Resend or Twilio connected, the test email and text are actually delivered to the email and phone you entered. Otherwise they stay in preview.
- Test orders are marked **TEST** everywhere and never change ticket inventory, sales totals, payouts or stats. Real organizers and admins aren't emailed: their copies go to the admin running the test, marked "[TEST]".
- **Delete all test data** removes every test order, ticket and test message.

**Connect real sending:**
1. **Email via Resend** (resend.com, free tier 3,000 emails/month): add and verify the ticketjunkies.com domain (DNS records), create an API key, and paste it in Admin → Settings. Set the From address to e.g. `TicketJunkies <tickets@ticketjunkies.com>`, tick **Send real emails**, then click **Send test email**.
2. **Texts via Twilio** (twilio.com): buy an SMS-capable number, and copy the Account SID, Auth Token and number into Admin → Settings. Tick **Send real text messages**, then click **Send test text**. US numbers need A2P 10DLC registration, and Bahamas delivery must be enabled under Twilio's Geo Permissions.
3. Set **Website address** to `https://ticketjunkies.com` once the site is live, so links and QR images in emails work for recipients.

**Platform fee:** 3.5% + $0.99 per paid ticket, paid by the buyer. Change `FEE_PERCENT` and `FEE_FIXED` in `server.mjs`.

## Before going live on ticketjunkies.com
1. **Payments:** checkout is in DEMO mode (it validates the card format and charges nothing). Connect **Stripe Connect** so organizers get paid out. Replace the block marked `DEMO PAYMENT` in `server.mjs`.
2. **Email/SMS:** built in. Connect Resend and Twilio in Admin → Settings (see above).
3. **Hosting:** deploy to a Node host (Render, Railway, Fly.io, a VPS), point ticketjunkies.com DNS at it and enable HTTPS. Add `Secure` to the session cookie.
4. **Database:** move from the JSON file to Postgres/MySQL once traffic grows.
5. Extras: password reset, QR codes on tickets, admin panel, refunds, promo codes.

## "Promoter" is now "Organizer"
Everywhere on the site (menus, sign-up, dashboards, admin tabs, emails, texts, reports and exports) the word **Promoter** is now **Organizer**. Existing accounts and events were converted automatically when the server started. Old sign-up links that used the previous word still work. The demo account now signs in as organizer@ticketjunkies.com (password organizer123), and the saved email/text history uses the new wording too.

## WordPress + Elementor version
A WordPress plugin version of this whole site is in **ticketjunkies-wordpress-plugin.zip**. Its guide is **WORDPRESS-INSTALL.md**. It looks and works the same, and every page can be designed in Elementor.

## Wider Create Account form
The sign-up form now spans the page. On computers and tablets it's a wide card with fields in two columns: name and mobile, then email and password. The organization name goes full width when "Organizer" is chosen. On phones the form runs almost edge to edge.

## Required sign-up fields and the Organizer column
- **Create Account:** every field is now required: full name, mobile number, email and password. Organizers must also fill in **Organizer name** (their company or brand). Required fields show an orange *, and the server refuses sign-ups with missing fields.
- **Admin Panel → Users:** a new **Organizer** column shows the organizer name each person entered at sign-up; attendees show "—". The same name appears in Manage → "Organizer name", and you can edit it there. The search box also searches organizer names.

## Organizer on each event + full-width event form
- **Create / Edit Event** has a required **Organizer** field. It's filled in with the account's organizer name, and you can change it per event (e.g. a different brand or a co-host). This name shows on the event page as "Presented by …", in the Admin Panel's events list, sandbox and reports, and in emails and texts about that event. Event search also finds it. Existing events were filled in automatically from their owner's organizer name.
- **Layout:** the form is one full-width (edge-to-edge) column with centered headings, in this order: Event title, Organizer, Category, Event flyer, Date & time, Location (with map), Description, Status, then Ticket types and the Save / Cancel buttons.

## Event schedules: times a day and days
In **Create Event → Date & time** there are two dropdowns:
- **How many times a day?** Once a day, **2 times a day** (Morning & Evening) or **3 times a day** (Morning, Midday & Evening). Each show has its own start and end time, and you can rename it, for example "Matinee".
- **On which days?**
  - **One day:** pick the date.
  - **Several days, same times each day:** pick the days. Use "+ Add a day", or **Fill days** to repeat the first day every day, week, 2 weeks or month.
  - **Different days and times:** add a row for every show, each with its own date, start, end and name.
- A summary under the dropdowns shows exactly which shows will be created, e.g. "6 shows on 2 days".
- **One show** creates a normal event. **Several shows** each get their **own tickets, stock, guest list, check-in and sales**. The event page shows "N shows available" with a button for each day and time. The homepage lists the event once, with "+N more dates".
- If a show's end time is earlier than its start time, it ends the next morning.
- Editing changes only the show you're editing. The edit page links to the other shows.
- The old "Multi-day event" and "Multiple dates / times" cards were removed.

## Organizers & Venues (Admin Panel) + several per event
- **Admin Panel → 📣 Organizers:** the saved list of organizer names (brands, promoters, co-hosts). For each one you can see its events, upcoming events and tickets sold, and you can add contact details, a website or social link, private notes, and link it to an account. **Renaming an organizer here renames it on every event that uses it.** Deleting one only removes it from the list; events keep the name.
- **Admin Panel → 📍 Venues:** the saved list of venues, with address, city, map status (On map / City only / No pin), capacity, phone, notes and event counts. New venues get their map pin automatically from the address. **Editing a venue's name or address updates every event held there.**
- **Both lists fill themselves:** every organizer and venue used on an event is added automatically. Your existing events were added when the server started.
- **Create / Edit Event:**
  - **Organizers:** add one or more by picking a saved name or typing a new one. All of them show as "Presented by A & B" on the event page, tickets, emails and reports. At least one is required (up to 10).
  - **Location:** "Choose a saved venue" fills in the main venue, address, city and map pin. **More venues** adds extra places for events held at more than one venue (up to 10 in total). Pick a saved venue or type one in; a saved venue's address fills in by itself.
  - **Event page:** lists every venue with its own Directions link. Event cards show "+N venues".

## 🎟️ Green "My Tickets" menu button (v1.3.8)
- In the top menu, **🎟️ My Tickets** (organizers and admins) and **🎟️ My Dashboard** (attendees) now sit in a TicketJunkies-green box with dark text, alongside the blue Admin Panel and orange Create Event buttons. On phones it spans the menu width like those buttons.

## 📍 Events, map and weather near the visitor + secure checkout (v1.3.7)
- **Location is detected automatically** (no pop-up) from the visitor's internet connection. It's checked against the device's time zone, because Bahamian internet providers often appear to be in Miami: when the two disagree, the time zone wins (e.g. a phone set to Nassau time is placed in Nassau). Visitors who already allowed location, or who tap **🎯 Use my exact location**, are placed exactly using GPS. The result is saved on that device only (12 hours for the automatic guess, 7 days for GPS).
- **Home page weather** shows the visitor's own town instead of a fixed place. A Weather widget with its own fixed place on WordPress keeps that place.
- **The map, the events list and the calendar only show events in the visitor's area.** A bar above the map says *📍 Showing events in Nassau* with buttons for the **city**, the **island / state**, the **country** and **Everywhere**. By default the smallest area that has events is picked. When an area has none, visitors see "No upcoming events in … yet" with a **Show events everywhere** button. Typed searches (words, venue, organizer) still search everywhere.
- Matching uses each event's map pin (within about 40 km for a city, 160 km for an island/state) plus the city and address text. Events count as being in the site's own country (*Admin → Settings → Maps*, e.g. Bahamas).
- **Checkout: secure payment section**, centered at the bottom of the checkout and cart-checkout pages. A green *Secure payment* badge, then *Secure checkout* (encrypted SSL connection), *Card details protected* (we never store your full card number), *Fraud protection* (only when the robot check is on for checkout) and *Guaranteed tickets*, then the **Visa, Mastercard and American Express** logos with "We accept Visa, Mastercard and American Express".
- Services used by the visitor's browser: ipwho.is (with ipapi.co as backup) for the automatic guess, and OpenStreetMap Nominatim to name a GPS position.

## ✅ Green confirmation pop-ups (v1.3.6)
- The confirmation message that pops up at the bottom of the screen after a change ("Saved", "Dark mode", "Added to the guest list"…) is now TicketJunkies green (#84d65f) with dark text, in both light and dark mode. Error messages are still red.

## 📷 "Ticket Scanner" menu name (v1.3.5)
- The menu link **📷 Scan** is now **📷 Ticket Scanner**, and the bottom tab in the phone app says **Ticket Scanner** (kept on one line). On WordPress the Scanner page is now titled **Ticket Scanner**. Existing sites are renamed once on the next wp-admin visit, but only if the page is still called "Scanner", so a title you chose yourself is kept.

## 🎟️ Scanner heading with the Ticket Junkies icon (v1.3.4)
- The scanner's heading now reads **Ticket Junkies Scanner** (was "📷 TJ Scanner"), with the Ticket Junkies three-ticket icon on its left. The icon has a see-through background, so it suits light and dark mode. Files: `public/tj-icon.png` (Node) and `assets/img/tj-icon.png` (WordPress).

## 📷 Scanner page you can edit in Elementor (v1.3.3)
- **WordPress:** the Door Scanner now has its own page, **Scanner** (`/scanner/`), built with the new Elementor widget **TJ Door Scanner**. Open it with *Edit with Elementor* to add headings, pictures, banners or instructions above or below the scanner, or change its colors in the widget's Style tab. The widget can also be placed on any other page.
- Sites set up before v1.3.3 get the Scanner page automatically the first time an admin opens wp-admin after updating. If you delete it later it isn't forced back, and scanning simply happens inside the Organizer Dashboard page again. *TicketJunkies → Start here → Re-create missing pages* brings it back.
- The Door Scanner button on the dashboard, the **Scan** tab in the phone app and every scanner link open the Scanner page. The scanner app's home-screen shortcut starts there too.
- **Install buttons removed** from the scanner (both the event picker and the scanning screen). Phones can still add the site to the home screen from the browser menu, and the separate *Get the app* page and footer link are unchanged.

## 🚪 Door Scanner on the dashboard + guest list (v1.3.2)
- **Door Scanner button:** a big blue **📷 Door Scanner** button now sits at the very top of the Organizer Dashboard, so it's the first thing organizers see when they log in on their phone. It opens the scanner (`#/scan`), where they pick the event.
- **Who's in and who isn't:** under the camera, the scanner shows **Guest list & check-ins** with three tabs: **Not checked in**, **Checked in** (with the time) and **Everyone**, each with a count. The big counter at the top counts everyone expected, including extra guests.
- **Search:** type any part of a name, email or ticket code to find someone straight away (accents and capitals don't matter).
- **Guest list (typed in by hand):** **+ Add name** takes a name, plus optional email, number of extra guests (0–20, e.g. "+2") and a note (VIP, DJ, press…). Guest-list names show a blue *Guest list* badge, are checked in with one tap, can be un-checked (**Undo**) or removed (**✕**), and are deleted along with their event.
- Ticket holders can also be checked in by tapping **Check in** in the list (handy when a phone screen won't scan). The list refreshes every 30 seconds so several door staff stay in sync.
- Only the event's organizer (or an admin) can see or change its guest list; it is never shown publicly. Up to 2,000 names per event.
- New API: `GET/POST /api/organizer/events/:id/guests`, `DELETE …/guests/:gid`, `POST …/guests/:gid/checkin` (`{undo:true}` to reverse). WordPress: same routes under `/wp-json/tj/v1/`, stored in the option `tj_guests_<eventId>`.

## 📲 Mobile apps (v1.3.0, v1.3.1)
- **v1.3.1, store app look:** inside the App Store / Google Play app the site turns into a phone app: a bottom tab bar (**Events · Tickets · Scan** for organizers or **Cart** for attendees **· Account**), a compact header with the TicketJunkies logo, no website footer, and spacing for the phone's notch and home bar. New app icon (the three-ticket artwork) for the store app, the home-screen app and TJ Scanner.
- **Install as an app (iPhone, Android, computer):** visitors get a small *Get the app* bar on phones and a **📲 Get the app** page (footer link, `#/app`). On Android/Chrome it installs in one tap; on iPhone it shows the 3 Safari steps (Share → Add to Home Screen).
- **Tickets work offline:** the app keeps a saved copy of My Dashboard, the ticket QR codes and the full-screen ticket pages, so they open at the door with no signal. Saved data is wiped from the phone on Log Out. Payments, check-ins and the Admin Panel always go straight to the server.
- **TJ Scanner for promoters** (`#/scan`, **📷 Scan** in the menu, **📷 Door scanner** on the attendees page): pick the event, tap *Start scanning*, and each ticket shows a big green **Admit**, amber **Already checked in** or red **Do not admit** result with sound and vibration, a live checked-in count, a recent-scans list, manual code entry, front/back camera switch and screen kept awake. It works on **iPhone too** (a built-in QR reader is used when Safari has no scanner of its own). It installs as its own **TJ Scanner** home-screen app.
- **App Store / Google Play app:** the `mobile-app` folder (Capacitor 8) wraps ticketjunkies.com in a native app with camera access. Android builds in the cloud with GitHub Actions; the iPhone app is uploaded from Xcode. See `mobile-app/README.md`.
- WordPress: the plugin serves the app files from your site address (`/?tj_sw=1`, `/?tj_manifest=main`). To switch the app features off, add `add_filter('tj_pwa', '__return_false');`.

## Speed, phones, browsers and social media
- **Faster loading:** the homepage now downloads about **87 KB instead of 333 KB** on a first visit. Pages, scripts and styles are compressed (Brotli or gzip). Versioned files are cached by the browser for a year, and repeat visits re-check only the page itself. The scripts no longer block the page from drawing, and fonts load in the background.
- **Lighter images:** event pictures load only when they scroll into view. New flyers are resized in the browser before upload (max 1600 px). The two existing 1.26 MB flyers were replaced with 119 KB copies that look the same. The site icon dropped from 47 KB to 3 KB.
- **Phones:** iPhone notch and home-bar safe areas are respected, tap targets are at least 44 px, there's no zoom when typing on iOS, and you can add the site to the home screen (app icon + `manifest.webmanifest`). Animations are reduced for people who prefer less motion.
- **Browsers:** the code avoids features older browsers don't support. It adds `-webkit-` prefixes for Safari and a fallback layout for Safari 14 and older. It works in Chrome, Safari (Mac, iPhone, iPad), Firefox, Edge and Samsung Internet.
- **Social media and Google:**
  - The homepage has Open Graph and Twitter/X preview tags with full image addresses. Event share links (`/e/<id>`) also add Google "Event" data (date, venue, price, availability).
  - There's a `robots.txt` and an automatic `sitemap.xml` listing every upcoming event.
- **WordPress plugin:** scripts load deferred, fonts preconnect, there's an iPhone home-screen icon, and social preview tags are added unless an SEO plugin (Yoast, Rank Math, AIOSEO or SEOPress) already does it.
