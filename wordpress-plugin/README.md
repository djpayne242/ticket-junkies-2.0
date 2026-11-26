# TicketJunkies for WordPress + Elementor: install guide

This is the complete TicketJunkies.com site rebuilt as a **WordPress plugin**. It keeps everything the original site has:
- the same look (logo, colors, light/dark/auto switch, the homepage with weather, clock, map and event cards)
- the same features (events, ticket types, private and complimentary tickets, checkout, QR tickets, PDF downloads, emails and texts, My Dashboard, Organizer Dashboard, Admin Panel, reports with PDF/Word/Excel/CSV downloads, sandbox and settings)

Every page is an **Elementor page**, so you can drag things around, add sections, change colors and fonts, and add your own content.

## What you need
- A WordPress website (version 6.0 or newer) on your hosting, with PHP 7.4 or newer. Most hosts (Hostinger, SiteGround, Bluehost and others) already have this.
- The free **Elementor** plugin.
- Recommended: the free **Hello Elementor** theme. It's a clean, blank theme, so TicketJunkies looks exactly like the original.

## Install (about 10 minutes)
1. In WordPress, go to **Plugins → Add New**. Search for **Elementor**, then click **Install** and **Activate**.
2. Go to **Appearance → Themes → Add New**. Search for **Hello Elementor**, then click **Install** and **Activate**.
3. Go to **Plugins → Add New → Upload Plugin** and choose **ticketjunkies-wordpress-plugin.zip**. Click **Install Now**, then **Activate**.
4. A new **🎟️ TicketJunkies** menu appears on the left. Open **TicketJunkies → Start here**.
   - The plugin has already created these 9 pages: Home, Event, Checkout, My Dashboard, Organizer Dashboard, Admin Panel, Log In, Create Account and Your Tickets.
5. Make TicketJunkies your front page:
   - Go to **Settings → Reading** and choose **A static page**, then set **Homepage** to **Home**.
   - Go to **Settings → Permalinks**, choose **Post name** and click **Save**.
6. Go to **Settings → General → Timezone**, choose **Nassau** and click **Save**. This matters: without it, event times in emails, tickets and reports show in the wrong time zone (an 8 PM party would show as 1 AM).

## Bring over your existing events, accounts and orders
1. Open **TicketJunkies → Start here** and scroll to **Bring over your existing TicketJunkies data**.
2. Upload **ticketjunkies-import.json** and click **Import**.

This brings over your events and flyers, ticket types, orders and tickets (the QR codes stay the same, so tickets people already have still work), accounts, and settings.

**Good to know:**
- **Passwords can't be copied** from the old site. Each person clicks **Forgot your password?** on the Log In page once to set a new one.
- **Your own account** (djpayne242@gmail.com) comes in as an organizer, because the importer never creates WordPress administrators on its own. You'll keep running the site from the WordPress administrator account you made when you installed WordPress. That account automatically sees the TicketJunkies **Admin Panel**. If you'd rather use your Gmail account, open **Users**, edit it, and set the role to **Administrator**.
- **Keep the import file private.** It contains customer names, emails and your email/text settings. Delete it from your computer once the import is done.

## Editing with Elementor
- Open any TicketJunkies page and click **Edit with Elementor**.
- In the left panel there's a **🎟️ TicketJunkies** group with 15 building blocks:
  - **Homepage pieces:** Search bar, Weather & clock, Organizer call-to-action, Category chips, Events map, Event grid.
  - **Whole screens:** Event page, Checkout, My Dashboard, Organizer Dashboard, Admin Panel, Log In, Create Account, Your Tickets, Full homepage.
- Click any block to change its options. The **Style** tab lets you change colors, fonts, button and card corners, and shadows.
- Your brand colors (green #84d65f, blue #376eb8, orange #eb742a) and fonts are already set up as Elementor **Global Colors** and **Global Fonts**.
- You can add normal Elementor content (text, images, videos, testimonials) above or below any TicketJunkies block.
- **Header & footer:** the TicketJunkies header (logo, menu, light/dark switch) and footer appear automatically. You can turn them off in **TicketJunkies → Start here** if you build your own with Elementor Pro.
- **Starting over:** if a page gets deleted, **TicketJunkies → Start here → Re-create missing pages** brings it back. **Reset page designs** puts every TicketJunkies page back to its original design.

## Before taking payments for real
Checkout is still in **demo mode**, just like the original site: it checks the card details but doesn't charge anything. Full card numbers are never saved. Connecting Stripe is the next step before real sales.

## Email and text messages
The same messages go out as before: tickets with QR codes, sale alerts, refunds and more.
- **Emails** are sent through WordPress's own email system, so the Resend key box isn't needed. For reliable delivery, install the free **WP Mail SMTP** plugin and connect it to your email provider. Resend works too: choose "Other SMTP" with host `smtp.resend.com`, port 465, user `resend`, and your Resend key as the password.
- **Texts:** in the **Admin Panel → Settings**, paste in your Twilio details and tick **Send real text messages**.
- Then tick **Send real emails** and use the test buttons.
