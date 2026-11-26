# TicketJunkies app for iPhone & Android (App Store / Google Play)

This folder holds the store version of TicketJunkies. It's a real native app that opens **https://ticketjunkies.com**
inside it. Everything you change on the website (events, ticket types, design, the plugin) shows up in the app straight away,
with no app update needed.

**One app for everyone.** Ticket buyers browse, buy and keep their tickets. Promoters see **📷 Scan** in the menu and use
the door scanner with the phone camera.

| What | Where |
| --- | --- |
| App name | TicketJunkies |
| App ID (bundle / package) | `com.ticketjunkies.app` (permanent once published; change it in `capacitor.config.json` first if you want another) |
| Website it opens | `https://ticketjunkies.com` (`server.url` in `capacitor.config.json`) |
| App icon | Your three-ticket artwork (`brand/app-icon-source.png`) on white → `assets/icon-only.png` (App Store) and the Android adaptive icon (`icon-foreground.png` + `icon-background.png`) |
| Logo in the app | The TICKETJUNKIES logo (`brand/logo-source.png`): app header, launch (splash) screen, loading and offline screens |
| Splash screens | `assets/splash.png` (light) and `assets/splash-dark.png` (dark): ticket icon above the logo |
| Store listing images | `store-listing/` (App Store icon 1024, Google Play icon 512, Google Play feature graphic 1024×500) |
| In-app look | Bottom tab bar (Events · Tickets · Scan/Cart · Account), compact header with the logo, no website footer |
| Camera permission text | "TicketJunkies uses the camera to scan ticket QR codes at the door." |

---

## Android (Google Play): built for you in the cloud

You don't need Android Studio. GitHub builds the app.

1. The `mobile-app` folder and the workflow files are in your GitHub repo (**ticket-junkies-2.0**).
2. On GitHub, open **Actions → "Mobile app – Android" → Run workflow**, type a version such as `1.0.0`, and press **Run**.
3. After about 10 minutes, open the finished run and download the file under **Artifacts**:
   - **ticketjunkies-android-test.apk**: copy it to an Android phone and open it to install and test. You may need to allow installs from unknown sources.
   - **ticketjunkies-play-store.aab**: this is the file you upload to Google Play.

### One-time setup for Google Play
1. Create a Google Play developer account at <https://play.google.com/console> ($25, one time).
2. Create your **upload key** once on your Mac (in Terminal), and keep the file and passwords safe:
   ```
   keytool -genkey -v -keystore ticketjunkies-upload.jks -alias upload -keyalg RSA -keysize 2048 -validity 10000
   base64 -i ticketjunkies-upload.jks | pbcopy
   ```
3. On GitHub, go to **Settings → Secrets and variables → Actions → New repository secret** and add:
   - `ANDROID_KEYSTORE_BASE64`: paste (what step 2 copied)
   - `ANDROID_KEYSTORE_PASSWORD`: the keystore password you chose
   - `ANDROID_KEY_ALIAS`: `upload`
   - `ANDROID_KEY_PASSWORD`: the key password you chose
4. Run the workflow again. The `.aab` is now signed. In Play Console: **Create app → Production (or Internal testing) → Create release → upload the .aab**.
5. Fill in the store listing (description, screenshots, privacy policy link, content rating), then send it for review.

Each new release just needs a higher version (1.0.1, 1.1.0 …). The build number goes up automatically.

---

## iPhone (App Store): built on your Mac with Xcode

Apple only allows App Store builds from a Mac with Xcode.

### One-time setup
1. Join the **Apple Developer Program** at <https://developer.apple.com/programs/> ($99 a year).
2. Install **Xcode** from the Mac App Store, then open it once so it finishes setting up.
3. Install **Node.js 22 or newer** from <https://nodejs.org>.
4. You don't need CocoaPods (Capacitor 8 uses Swift Package Manager).

### Build and upload
In Terminal:
```
cd path/to/ticket-junkies-2.0/mobile-app
npm run setup        # first time only: creates the iPhone + Android projects, icons and settings
npm run ios          # opens the project in Xcode
```
In Xcode:
1. Click **App** (left), go to **Signing & Capabilities**, and choose your **Team**. Xcode creates the certificates for you.
2. At the top, choose **Any iOS Device (arm64)**, then **Product → Archive**.
3. In the Organizer window that opens, click **Distribute App → App Store Connect → Upload**.
4. In <https://appstoreconnect.apple.com>, create the app (bundle ID `com.ticketjunkies.app`), add screenshots and a description, pick the uploaded build, and **Submit for Review**.

**Test on your own iPhone first:** plug it in, choose it at the top of Xcode, and press ▶︎.

To check that the iPhone project builds without your Mac, run **Actions → "Mobile app – iPhone build check"** on GitHub.

---

## Good to know for review
- **Payments:** event tickets are a real-world service, so Apple and Google allow normal card checkout. No in-app purchase is needed.
- **Apple sometimes rejects apps that are "just a website".** TicketJunkies has app-style features that help: a camera ticket
  scanner, tickets that open offline, a full-screen phone layout and a home-screen icon. In the review notes, mention the door scanner
  and give the reviewers a **demo organizer login** and a **demo attendee login** so they can try both.
- **Privacy policy:** both stores require a link to one on your website.
- **Ticket PDFs:** inside the app, buyers show their QR codes on screen (My Dashboard → Full-screen tickets). PDF downloads work best
  from the website or the ticket email.

## Updating the app
- Website changes (plugin updates, events, design) show up in the app right away.
- You only need a new store build to change the app's icon, name, splash screen or permissions, or to update Capacitor.
