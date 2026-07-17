=== Loonymoon Email Gate ===
Contributors: portermedia
Tags: email gate, content lock, opt-in, sms, mailgun, twilio
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 2.21.0
License: GPLv2 or later

Gate posts behind an email-or-phone opt-in, capture optional address fields, and broadcast to subscribers via Mailgun (email) or Twilio (SMS).

== Description ==
Single posts (and optionally the blog index) are replaced with an opt-in form. Visitors choose Email or Phone, submit, and unlock the site for 30 days via a cookie. Logged-in editors and admins always see the full content.

Captured data:
- contact_type (email|phone), email, phone (E.164), country (ISO alpha-2)
- optional address block: street, city, region (province/state), postal code, country

Broadcasts:
- Compose & queue email blasts via Mailgun or SMS blasts via Twilio.
- Optional country filter.
- wp_cron processes 25 recipients per minute and logs success/failure per recipient.
- "Send test" button for sanity-checking before broadcasting.

== Requirements ==
- A Mailgun account + verified sending domain for email broadcasts.
- A Twilio account + verified from-number for SMS broadcasts.
- Working wp_cron (the default on most hosts). For high-volume sends, hook a real OS-level cron to wp-cron.php.

== Migration notes (1.0.0 -> 2.0.0) ==
On first load, the plugin drops the old UNIQUE KEY `email` index, makes `email` nullable, and adds new columns plus the broadcast tables. Pre-2.0 rows are stamped `contact_type = 'email'` automatically.

== Uninstall ==
Drops the subscribers, broadcasts, and broadcast_log tables, removes settings, and clears the scheduled cron event.

== Changelog ==
= 2.21.0 =
* `[lmeg_signup]` gained a `tiers` param — combine free signup and paid tier selection in ONE form so email is only entered once.
* Values: `tiers=""` (default, current behavior — free only), `tiers="all"` (all active tiers below the free button), `tiers="1,2"` (specific tier IDs).
* New `divider` param controls the text between free button and tiers (default "or").
* Free submit button now sends `lmeg_after=free` explicitly; tier buttons send `lmeg_after=checkout:<id>:<interval>` — the handler routes accordingly.
* Members see a "Signed in as X" line and tier buttons only (no duplicate email input).

= 2.20.0 =
* Logo above the paywall card — new Logo URL + Logo max-width settings. Media-library picker in the settings page (falls back to plain URL paste).
* Paid-only posts (Any paid member / specific tier) now show tiers directly — no free email/unlock path, no "Get premium access" toggle. Non-members still enter their email compactly above the tier buttons.
* Sign-in is now in-place — clicking "Already subscribed? Sign in →" swaps the card contents to the sign-in form via JS. After submit, redirects back with ?lmeg_signin=sent and renders a "Check your inbox" confirmation in the same card. No more full-page nav.

= 2.19.0 =
* New "Page background" color setting — sets the whole gated-page background (behind the card). Overrides theme CSS with !important.
* Card + text color settings are now emitted with direct !important overrides instead of CSS variables alone, so they actually beat theme rules that would previously leak through via higher specificity.
* body.lmeg-page-bg class added when the gate template is active — lets the page-background color extend edge-to-edge.

= 2.18.0 =
* New shortcode `[lmeg_premium]` — embed tier-selection anywhere. Renders paid tier cards with an inline email input for non-members and Stripe Checkout in one hop.
* Params: heading, message, style (card|minimal), tiers (comma-separated tier IDs, default all active).
* Fix: `[lmeg_signup]` embed's Email/Phone pill toggle now uses explicit dark text so it stays visible on themes that set white body text.

= 2.17.0 =
* Fix: text was invisible when the theme sets `color: white` on the body (dark themes). Paywall / gate / embed cards now explicitly set their own dark text color instead of inheriting.
* Fix: unlock icon's SVG stroke was inheriting `currentColor` (white on dark themes → invisible on the light card). Now uses a fixed muted gray.
* Fix: email/phone tab pills had white text on white active-tab bg → invisible. Now use explicit dark text.
* New: Card background + Card text color settings so you can flip the paywall to a dark theme to match a dark site. Two new HTML5 color pickers in Settings → Colors.

= 2.16.0 =
* Fix: existing members clicking a tier button no longer got "Please enter a valid email address" — the form doesn't render an email input for signed-in members, so the handler now short-circuits directly to Stripe Checkout.
* Fix: Stripe Checkout failures are now surfaced as an error page with the actual Stripe error message instead of silently redirecting back to the post. Diagnoses common causes (missing keys, missing price ID).

= 2.15.0 =
* Update check interval cut from 6 hours to 15 minutes.
* New `lmeg_quarter_hour` custom cron schedule + `lmeg_updater_tick` event that clears WP's `update_plugins` transient every 15 min, so new GitHub releases surface without waiting for WP's twice-daily default check.
* Cron event auto-unscheduled on deactivation.

= 2.14.0 =
* Colors section in Settings — customize the primary button color + text, accent (soft paywall tint, focus rings), and card border.
* Color values are output as scoped CSS custom properties (`--lmeg-primary`, `--lmeg-primary-text`, `--lmeg-accent`, `--lmeg-border`) via `wp_add_inline_style`, so themes and installs without saved colors get the same look as before.
* Uses HTML5 native color pickers — no jQuery UI dependency.

= 2.13.0 =
* GitHub-driven self-updater: WP admin now shows "Update available" whenever a new release is published on GitHub. One-click update, no manual zip uploads.
* Repo target configurable via LMEG_GITHUB_OWNER + LMEG_GITHUB_REPO constants (defaults: loonymoonchild/loonymoon-email-gate).
* Post-extraction folder-rename filter so GitHub source zips also work in a pinch.

= 2.12.0 =
* Embeddable `[lmeg_signup]` shortcode for dropping a signup form anywhere: posts, pages, widgets, footers.
* Three visual styles: `card` (default, boxed), `inline` (horizontal email + button), `minimal` (widget-friendly).
* Params: `heading`, `message`, `button`, `style`, `phone` (yes/no), `consent` (auto/none/custom), `redirect`, `success`.
* Signups flow through the same handler as the main gate — auto-tag, member cookie, welcome email, sequences all fire normally.
* Success confirmation renders in-place after submit (query-param + anchor scroll).

= 2.11.0 =
* New paywall layout: 🔓 icon + centered heading + email/phone form + "Get premium access" collapsible.
* Settings for the paywall heading, unlock button label, and premium button label (defaults: "Unlock <site name>" / "Unlock" / "Get premium access").
* Premium panel is collapsed by default for non-members (email-first UX), expanded for free members on hard-paid posts.
* v1 tier-first paywall preserved under `lmeg_paywall_html_v1()`; switch via `add_filter('lmeg_paywall_mode', fn() => 'v1')`.

= 2.10.0 =
* Tier-first paywall — non-members hitting paid/soft-paid posts see tier cards + email input + "Start free" all in one form. No more two-step gate.
* Tier CTAs are `<button>`s inside the same form as the email input, so one submit captures the email AND creates the Stripe Checkout session.
* Form handler routes on `lmeg_after=checkout:<tier>:<interval>` or `free`; soft-grants are applied automatically for non-members submitting on soft-paid posts.
* Redesigned tier cards with responsive grid, ghost-button "Start free", "or" divider.

= 2.9.0 =
* Soft paywall — new post access level "Paid, or continue free". Upgrade CTA shows tier cards + "Not right now, keep reading" link.
* Per-(subscriber, post) grants stored in lmeg_soft_grants so the ask reappears on the next soft-paid post.
* Settings: separate copy fields for hard vs soft paywall heading + message.

= 2.8.0 =
* Members admin page with MRR / ARR / paying / churn stats + by-tier breakdown + roster.
* Auto-tags for paid membership: channel:paid + tier:<slug>, refreshed on Stripe events and comp changes.
* Subscribers list: status tabs (All / Free / Paid / Unsubscribed), bulk grant/revoke tier for comps.
* Dashboard widget adds MRR + paying count row.

= 2.7.0 =
* Brevo (formerly Sendinblue) added as an email provider alongside Mailgun.
* New "Email provider" dropdown in Settings picks which one sends. All sending code paths (broadcast, welcome, magic-link, sequences, test sends) go through a single `lmeg_email_send()` wrapper.
* "Save & test Brevo" button verifies credentials via GET /v3/account.

= 2.6.0 =
* Paid membership tiers (Substack model): create multiple tiers with monthly/annual prices, Stripe-backed.
* Per-post access meta box: Public / Free members / Any paid member / Specific tier.
* Passwordless magic-link sign-in for returning members.
* Signed member cookie (HMAC) — identifies specific subscribers, not just "someone unlocked".
* Stripe Checkout for new subs, Stripe Customer Portal for cancel/update card.
* Stripe webhook receiver with signature verification and idempotency.
* Gate expands from 2 states to 3: needs_signup / needs_upgrade / ok.
* Cron sweep reverts cancelled members to free after grace period.

= 2.5.0 =
* Open + click tracking — HMAC-signed pixel + link rewriter, per-broadcast + per-recipient stats.
* Saved segments — persist tag filter + match mode by name, load into Compose with one click.
* Email templates — reusable subject + body pairs with merge tags (`{name}`, `{email}`, `{city}`, `{country}`, `{site_name}`, etc.).
* Welcome email — auto-send on new email signups, opt-in via Settings.
* Drip sequences — multi-step time-based email/SMS series triggered by tag attachment; wp_cron processes due steps.
* Dashboard widget — 30-day signup sparkline + last broadcast summary + opens/clicks.
* Merge tags applied per-recipient during send.

= 2.4.0 =
* Native tag-based audience system — no third-party dependency.
* Auto-tags applied on every signup: channel:email / channel:phone, country:XX, has-address.
* New "Tags" admin page — create, rename, recolor, delete custom tags. Auto-tags update automatically as subscriber data changes.
* Subscribers list shows tag chips per row, supports bulk-select + bulk-tag (Add tag / Remove tag) and per-tag filtering via `?tag=<slug>`.
* Compose: country dropdown removed. New tag picker with Any/All match modes and live audience count via AJAX.
* `queue_broadcast()` now accepts a `tag_filter` instead of `country_filter` (legacy column kept for backward compat, new `tag_filter` column added).
* Auto-tag backfill on plugins_loaded for pre-2.4 subscribers.

= 2.3.0 =
* One-click unsubscribe link auto-appended to every broadcast email (HMAC-signed, no login required, CASL/CAN-SPAM compliant).
* Unsubscribed subscribers excluded from broadcasts. Re-submitting the form re-activates them.
* RSS feed and REST API now respect the gate — `the_content_feed`, `the_excerpt_rss`, and `rest_prepare_post` filters return a teaser + permalink instead of full content.
* "Save & test Mailgun" and "Save & test Twilio" buttons in Settings — verifies credentials against each provider's API.
* Scheduled broadcasts — Compose has a "Send at" datetime picker; cron picks broadcasts up at the scheduled moment.
* Subscribers list shows active/unsubscribed status; broadcast history shows "scheduled" status separately.
* CSV export includes `unsubscribed_at`.

= 2.1.0 =
* Broadcasts now auto-route per subscriber: emails go to email subscribers, texts to SMS subscribers, in one queued send.
* Compose screen has separate Email body and SMS body fields, plus a "Send test" button for each channel.
* New `channel` column on broadcast_log so each recipient remembers its own delivery channel.
* Schema migration is idempotent — pre-2.1 broadcasts keep working unchanged.

= 2.0.0 =
* Email or phone toggle on the form.
* Country dropdown with flags + dial codes.
* Optional blog index gating (in addition to single posts).
* Optional address fields block with custom message.
* Mailgun (email) and Twilio (SMS) broadcast UI driven by wp_cron.
* Per-recipient broadcast log.

= 1.0.0 =
* Initial release: single-post gate, email-only, local storage, CSV export.
