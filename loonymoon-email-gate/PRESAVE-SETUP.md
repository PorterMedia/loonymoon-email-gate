# Fanloop Pre-Saves — go-live setup

Let fans commit an upcoming release to their music library **before** it drops —
it's added automatically on release day. Three platforms, each independent:

| Platform | What happens | What it needs |
|---|---|---|
| **Apple Music** | Auto-added to the fan's library at release | A MusicKit key (.p8) + Key ID |
| **Deezer** | Auto-added to the fan's favourites at release | A Deezer app (App ID + secret) |
| **Amazon Music** | Release-day reminder email + one-tap link | Just the album's Amazon URL* |

\* Amazon has **no public auto-add API** for third parties, so an Amazon pre-save
is an honest reminder — we capture the fan and email them the link the day it's
out. (Spotify isn't offered: its API blocks pre-saves for real fanbases — use a
Release Drop for Spotify.)

Everything below is set in **WP Admin → Fanloop → Pre-Saves**. Until a platform's
credentials are in, that platform simply isn't shown on the widget.

---

## 1. Apple Music (MusicKit)

You already have the Apple Developer Program (from the Wallet pass), so there's
nothing more to join.

1. **Create a MusicKit key** — [developer.apple.com](https://developer.apple.com/account/resources/authkeys/list)
   → Keys → **+** → name it (e.g. `Fanloop MusicKit`) → tick **Media Services
   (MusicKit)** → Configure/Continue → **Register** → **Download** the
   `AuthKey_XXXXXXXXXX.p8` (once only — back it up) and note the **Key ID**.
   *(This is the same flow as the APNs key — it can be done for you in the
   browser.)*
2. Paste the `.p8` contents → **MusicKit key (.p8)**, the 10-char id → **Key ID**.
   Leave **Team ID** blank to reuse your Wallet Team ID (`9522FFNRKY`).
3. Apple Music flips to **ready**.
4. **Per release:** the **Apple album ID** is the number in the album's Apple
   Music URL — `music.apple.com/…/album/…/`**`1440913150`**.

## 2. Deezer

1. Create an app at [developers.deezer.com/myapps](https://developers.deezer.com/myapps)
   → note the **Application ID** and **Secret Key**.
2. Set the app's **Redirect URL after authentication** to exactly the URI shown
   on the Pre-Saves page: `https://<yoursite>/?lmeg_presave=deezer_cb`.
3. Paste **App ID** + **Secret** → Deezer flips to **ready**.
4. **Per release:** the **Deezer album ID** is the number in the album URL —
   `deezer.com/album/`**`302127`**.

## 3. Amazon Music

Nothing to configure — just paste the album's **`https://music.amazon.com/albums/…`**
URL on the campaign. Fans who pre-save get a release-day email with the link.

---

## Using it

1. **Create a campaign** — Pre-Saves → *New campaign*: title, artist, release
   date, the platform IDs above, and (optional) an artwork URL.
2. **Embed it** — put the shortcode on any page / link-in-bio:
   `[fanloop_presave campaign="12"]` (the id is shown next to each campaign).
   Fans see only the platforms you've configured; each pre-save also captures the
   fan into your CRM.
3. **Release day** — it runs **automatically** at the release date (WordPress
   cron), adding the album to every Apple/Deezer pre-saver's library and emailing
   Amazon pre-savers. You can also hit **Release now** on the campaign the moment
   the album is actually live on the stores.

## How it works (for reference)

- Apple: MusicKit JS authorizes the fan → we store a Music-User-Token → at release
  we `POST api.music.apple.com/v1/me/library` with your developer token (ES256,
  signed by the MusicKit key) + that token.
- Deezer: OAuth (`manage_library`) → we store the access token → at release we
  `POST api.deezer.com/user/me/albums`.
- Tables: `wp_lmeg_presave_campaigns`, `wp_lmeg_presaves`.
- The whole thing is white-label — fans only ever see the artist, never "Fanloop".
