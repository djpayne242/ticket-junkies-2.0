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

## What's new in 2.0.7 — checkout page fixed (card form + Elementor)
- **Buyers can enter their card details again.** WooCommerce had no checkout page of its own: when it was installed it found the TicketJunkies page called "Checkout" and treated that as its payment page, or ended up with none at all. Buyers were sent back to the TicketJunkies screen or the home page and never saw the card form. The plugin now makes sure WooCommerce always has its own **Secure Checkout** page (address `/secure-checkout/`, built with Elementor) holding the WooCommerce checkout form, which works with every payment gateway. It reuses an existing WooCommerce checkout page if one exists, and repairs the setting automatically if it ever points at a TicketJunkies page again.
- **The checkout page can be edited with Elementor.** WooCommerce used to bounce its checkout page to the cart whenever the cart was empty, including inside the Elementor editor. Editors can now open it normally.
- **Security settings no longer block payment boxes or the Elementor editor.** The site's security header had switched off "payment" features, which card boxes from payment companies and Apple Pay / Google Pay need. That's allowed again. Framing is now limited to the site's own addresses (http and https), so Elementor's editor can show the page while other sites still can't put it in a frame.
- There are now two different pages: the TicketJunkies **Checkout** page (`/checkout/`) shows the order summary, and the WooCommerce **Secure Checkout** page is where the card is typed in.

## What's new in 2.0.6 — Sandbox removed
- **The Admin Panel's 🧪 Sandbox tab is gone**, together with everything that belonged to it: simulated test sales, test tickets, "[TEST]" emails and texts sent to the admin, the **Include sandbox tests** option and the test column in sales reports, **Test** badges on orders, attendees and messages, and the phone-link QR code.
- **Leftover test data is cleaned up automatically:** any old sandbox test orders and test messages are deleted once when you update. They never counted toward sales, payouts or ticket inventory, so your numbers don't change.
- The **Send test email** and **Send test text** buttons in **Admin → Settings** are kept. They only check that your email and SMS providers work.

## What's new in 2.0.5 — new ticket buying steps
Buying tickets now follows five clear steps, shown in a step bar at the top:

1. **Select tickets** — pick the ticket type(s) and how many on the event page.
2. **Attendee information** — one box per ticket with **First name, Last name, Email and Mobile number**. Every field is required. **Go to Cart** stays greyed out and shows what is missing (in red) until every box is filled in correctly. The first ticket is filled in from your account to save typing.
3. **Cart** — review everything, change quantities, edit any attendee's details, remove a single ticket (**✕ Remove**) or a whole event (**✕ Remove event**). Changes are saved as you type.
4. **Checkout** — the **🔒 Checkout** button only works when every attendee is complete.
5. **Payment** — the checkout page now only asks for payment. On WordPress the buyer goes straight to the store's secure WooCommerce checkout.

Also:
- Each ticket keeps its own attendee's name, email and mobile number, and each attendee gets their own QR ticket by email. The organizer's attendee list and CSV export now include the attendee's phone number.
- The server enforces the same rules, so tickets can't be bought without full attendee details. After WooCommerce has already taken payment, tickets are always issued.
- The cart is kept until payment succeeds, so a declined card can try again without re-entering anything.

## What's new in 2.0.4 — real payments through WooCommerce
- **Paid tickets are charged by your WooCommerce payment gateway.** The old card form was a demo: it only checked that a card number *looked* valid and never contacted a bank, so any card (even a locked one) "worked" and no money was taken. It is removed from the WordPress plugin.
- **Every ticket type is a WooCommerce product** (hidden from the shop, category *Event tickets*), kept in sync automatically: name, price, sold/cancelled state. Existing events are synced when you update the plugin.
- **Checkout:** buyers fill in their ticket details on the event checkout, then click *Continue to secure payment* and pay on your WooCommerce checkout page. The TicketJunkies service fee is added as a *Ticket service fee* line.
- **Tickets are issued only after the payment is approved** (order status Processing or Completed). Declined, failed, pending and on-hold payments get no tickets. Bank-transfer / cheque orders get their tickets when you mark them Processing.
- **Refunds:** refunding a ticket order in the TicketJunkies Admin Panel refunds the payment through WooCommerce (automatically if your gateway supports refunds). Refunding or cancelling the whole order in WooCommerce cancels its tickets.
- **Free tickets** still go straight through without the store checkout.
- Requires WooCommerce with at least one payment method switched on. Without it, paid tickets can't be bought (the site says online payments aren't available).

## What's new in 2.0.3
- **Reorder ticket types:** in Create / Edit event, drag a ticket by its ⠿ handle (or use the ↑ ↓ buttons) to set the order. Click **Save changes** and the event page shows the tickets in that order. Works with a mouse and on phones/tablets.

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
- Attendee dashboard, organizer dashboard with check-in, and an Admin Panel (users, organizers, venues, events, orders, reports, emails and texts, settings)
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
