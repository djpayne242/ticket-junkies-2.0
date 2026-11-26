# 🎟️ TicketJunkies.com — 2.0

An Eventbrite-style ticketing website for **DJ Payne / Dj Payne Events** in Nassau, Bahamas. People discover events and buy tickets; organizers create events and sell tickets; you run everything from the Admin Panel.

This repository has **two versions of the same website**:

| Folder | What it is | Use it for |
|---|---|---|
| [`wordpress-plugin`](wordpress-plugin) | The **WordPress + Elementor** version (a plugin) | Running TicketJunkies on your WordPress site |
| [`dist/ticketjunkies-wordpress-plugin.zip`](dist/ticketjunkies-wordpress-plugin.zip) | The same plugin, ready to upload | **Plugins → Add New → Upload Plugin** in WordPress |
| [`html-snippets`](html-snippets) | Copy-and-paste HTML and shortcodes for every piece of the site | Elementor *HTML* widgets or WordPress *Custom HTML* blocks |
| [`node-site`](node-site) | The original stand-alone website (Node.js) | Hosting without WordPress (Render, Railway, a VPS…) |
| [`mobile-app`](mobile-app) | The **App Store / Google Play** app (Capacitor) | Publishing TicketJunkies as a phone app (see its README) |
| [`tools`](tools) | Build scripts that turn the Node site's pages into the plugin | Developers only |

## What's new in 2.0
TicketJunkies 2.0 is the fresh home for the project. It starts from everything that was built in the original **ticket-junkies** repo (up to v1.3.8) and carries on from here.

- **New update channel:** the WordPress plugin now checks **this** repository (`djpayne242/ticket-junkies-2.0`) for updates. Install the 2.0.0 zip once; after that, updates arrive here automatically.
- **Includes v1.3.7 and v1.3.8** (not yet on the live site, which runs 1.3.6): weather, map, events and calendar follow the visitor's location; secure payment section with Visa / Mastercard / Amex logos; My Tickets / My Dashboard menu link in a green box.
- Everything else (ticket scanner, shopping cart, organizer and admin dashboards, mobile app) is carried over unchanged.

## What's new in 2.0.2
- **Secure checkout badge:** a green shield-and-lock "Your purchase is 100% secure" badge at the bottom of checkout, with small, centered Visa, Mastercard and American Express logos under "We accept these cards".
- **Footer:** the "Get the app" link is removed.
- **Admin → Users → (organizer) → Events posted:** an *Assign to organizer…* picker moves an event (all dates of a recurring event) to another organizer. Both organizers get an email.
- **Ticket types that are on sale** (organizers and admins):
  - **Edit** anything, including the price. People who already bought keep the price they paid; revenue is counted from what buyers actually paid.
  - **🚫 Cancel** stops sales; buyers can't see or buy it. **↺ Resume sales** puts it back on sale.
  - **🗑 Delete** removes it. If tickets were already sold, those tickets stay valid and stay in reports; refunds can be made from Orders.

## What's new in 2.0.1
- **My Events** in the menu (organizers and admins) now sits in a TicketJunkies-green box.
- **My Tickets / My Dashboard** in the menu now sits in a white box.

## Put it on WordPress (the short version)
1. Install the free **Elementor** plugin and the **Hello Elementor** theme.
2. Download [`dist/ticketjunkies-wordpress-plugin.zip`](dist/ticketjunkies-wordpress-plugin.zip). In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip, and click **Activate**.
3. Open **🎟️ TicketJunkies → Start here**. All pages (Home, Event, Checkout, dashboards, Admin Panel, Log in, Create account) are created for you as Elementor pages.
4. Set **Settings → Reading → Homepage** to *Home*, **Settings → Permalinks** to *Post name*, and **Settings → General → Timezone** to *Nassau*.

Step-by-step guide: [`WORDPRESS-INSTALL.md`](WORDPRESS-INSTALL.md)

## Connected to WordPress (automatic updates)
The plugin checks this repository's **Releases** for new versions. When a newer release is published, your WordPress dashboard shows **"Update available"** for TicketJunkies on the **Plugins** screen, and you update in one click, or turn on **Enable auto-updates** to have it update by itself.

- Check right away with **Plugins → TicketJunkies → Check GitHub for updates**.
- **Publishing a new version (developers):**
  1. Change `Version:` and `TJ_VERSION` in `wordpress-plugin/ticketjunkies.php`.
  2. Rebuild `dist/ticketjunkies-wordpress-plugin.zip`, then commit and push.
  3. Run `gh release create v2.0.1 dist/ticketjunkies-wordpress-plugin.zip`. The release must include a file named exactly `ticketjunkies-wordpress-plugin.zip`.

## Features
- Events with flyers, several organizers, several venues, maps and weather
- Shows 1, 2 or 3 times a day (Morning, Midday, Evening), on one day, several days, or any mix of days and times. Each show has its own tickets
- Ticket types (public or private), free tickets, complimentary tickets, fees of 3.5% + $0.99
- Account required at checkout. QR-code tickets by email and text, PDF ticket downloads, resend tickets
- Attendee dashboard, organizer dashboard with check-in, and an Admin Panel (users, organizers, venues, events, orders, reports, emails and texts, sandbox, settings)
- Reports with PDF, Word, Excel and CSV downloads
- Light, dark and automatic themes; works on phones, tablets and computers
- **Mobile apps:** installable app with offline tickets, the **TJ Scanner** door check-in app (works on iPhone and Android), and App Store / Google Play builds in [`mobile-app`](mobile-app)
- **Fast:** compressed files, browser caching, lazy-loaded images, automatic flyer resizing, scripts that don't block the page
- **Social media ready:** link previews for Facebook, WhatsApp, Instagram, X, LinkedIn and iMessage, share buttons on every event, and Google "Event" rich results
- **Works in all modern browsers:** Chrome, Safari (Mac, iPhone, iPad), Firefox, Edge and Samsung Internet, with fallbacks for older Safari

## Privacy
Customer accounts, orders and uploaded images are **not** stored in this repository (see `.gitignore`). Full card numbers are never stored anywhere.

## Run the stand-alone version
```
cd node-site
node server.mjs 4321
```
Then open http://localhost:4321. It needs Node.js 20 or newer, with no extra packages.
