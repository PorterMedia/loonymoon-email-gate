# Fanloop Wallet Pass — go-live setup

A fan pass in **Apple Wallet** + **Google Wallet**: a lock-screen channel with
~90% open rates and **free** pushes (no per-message cost). Everything is already
built — this is the checklist to switch it from the dev/self-signed fallback to
live on real phones.

All values below are pasted in **WP Admin → Fanloop → Wallet Pass**. Nothing is
stored in a file; credentials live in the WordPress database.

Until you add the Apple cert, passes still build with a throwaway **dev
self-signed** signature so you can test the whole flow — they just won't install
on a real iPhone yet. The readiness panel at the top of the settings page shows
exactly what's live.

---

## 1. Apple Wallet (required for iPhone)

You need an **Apple Developer Program** membership ($99/yr).

1. **Pass Type ID** — [developer.apple.com](https://developer.apple.com) →
   Certificates, IDs & Profiles → Identifiers → **+** → *Pass Type IDs* →
   e.g. `pass.ca.portermedia.loony`. → **Settings → Wallet → Pass Type ID**.
2. **Team ID** — top-right of the developer portal (10 chars). → **Team ID**.
3. **Signing certificate** — on the Pass Type ID, *Create Certificate*, upload a
   CSR (Keychain Access → Certificate Assistant → Request a Certificate), then
   download the `.cer`. Convert to a PEM containing **both the certificate and
   its private key**:
   ```bash
   # .cer → .pem
   openssl x509 -inform der -in pass.cer -out pass-cert.pem
   # private key from Keychain: export the cert's key as pass.p12, then:
   openssl pkcs12 -in pass.p12 -nocerts -nodes -out pass-key.pem
   cat pass-cert.pem pass-key.pem > pass-signing.pem
   ```
   Paste `pass-signing.pem`'s contents → **Signing cert (PEM)** (or a server path
   to it). If the key has a passphrase, put it in **Cert password**.
4. **Apple WWDR intermediate** — download **Worldwide Developer Relations G4**
   from [apple.com/certificateauthority](https://www.apple.com/certificateauthority/),
   convert and paste:
   ```bash
   openssl x509 -inform der -in AppleWWDRCAG4.cer -out wwdr.pem
   ```
   → **WWDR cert (PEM)**.

When all four are set, the readiness panel flips **Apple signing → production**.

## 2. Push updates (APNs — required to notify)

Lets a drop / broadcast light up fans' lock screens. Uses an **APNs Auth Key**
(one key works for all your apps/passes).

1. developer.apple.com → Keys → **+** → enable **Apple Push Notifications
   service (APNs)** → download `AuthKey_XXXXXXXXXX.p8` (you can only download it
   once) and note the **Key ID**.
2. Paste the `.p8` contents → **APNs key (.p8)**; the 10-char id → **Key ID**.
   Leave **Sandbox** off for production.

Then **Push → ready**. Pushes cost nothing.

## 3. Google Wallet (optional — Android)

1. Google Cloud → enable the **Google Wallet API** → create a **service
   account** → create a JSON key → download it.
2. Get your **Issuer ID** from the [Google Wallet console](https://pay.google.com/business/console).
3. Paste the whole JSON → **Service account JSON**; the issuer id →
   **Issuer ID**. → **Google Wallet → ready**, and a *Save to Google Wallet*
   button appears next to Apple's.
4. Click **Register / update Google class** (Status panel) once. It validates the
   service account end-to-end and creates the shared Android pass class — if the
   SA isn't authorized on the issuer or the Wallet API isn't enabled, it tells
   you exactly what's wrong instead of a silently broken Save. Safe to re-run.

## 4. Branding

Set **Pass name**, **Logo text**, **colours** (background / text / accent), and
an optional **Strip image** (a wide hero, auto-cropped to 375×123). Leave the
strip blank for a clean coloured card.

---

## Using it

- **Add the button** anywhere with the shortcode **`[fanloop_wallet]`** (site,
  link-in-bio, a gated page). Logged-in members get a one-tap button; everyone
  else gets an email-capture that drops them into the CRM and hands back the pass.
- **Announce to Wallet** — in **Compose Broadcast**, tick *"Also push to Apple
  Wallet"*. The push targets the same segment as the email/SMS send: pick any
  number of tags with **match any / match all** and Wallet mirrors it (no tags =
  every pass-holder).
- **Auto on release** — when a **Release Drop** goes live it pushes pass-holders
  automatically (toggle: *Auto-push new releases to Wallet*, on by default).
- **Membership** — a fan's tier shows on the pass and updates automatically when
  they upgrade or cancel.
- **Fan control** — the back of the pass (tap ⓘ) carries a *Manage your pass*
  note: how to remove it (which auto-stops updates) and a one-tap link to manage
  all reminders. Removing the pass unregisters the device automatically.
- **At the show** — scan the pass QR; it opens an admin-only check-in card (fan,
  tier, member-since) and logs attendance.
- **Tour** — upcoming shows put the pass on the lock screen near the venue and on
  show day (no setup — pulled from your shows).

## How it works (for reference)

- Passes are signed server-side (PHP + OpenSSL) and delivered at
  `?lmeg_wallet=pkpass`; the "Add" capture is `?lmeg_wallet=add`.
- Apple registers devices at the auto web-service URL shown on the settings page
  (`/wp-json/lmeg-wallet/v1/…`); updates are an empty APNs push that makes the
  device re-fetch the pass.
- Tables: `wp_lmeg_wallet_passes`, `wp_lmeg_wallet_registrations`,
  `wp_lmeg_wallet_checkins`.
