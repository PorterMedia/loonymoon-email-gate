# Loonymoon Email Gate

WordPress plugin for [loonymoonchild.com](https://loonymoonchild.com/) that gates single-post content behind an email opt-in, captures subscribers, runs broadcasts + drip sequences, and (optionally) monetizes with paid membership tiers.

## Install (first time)

Download the latest `loonymoon-email-gate.zip` from the [Releases](../../releases) page, then in WP admin:

1. Plugins → Add New → Upload Plugin
2. Pick the zip → Install → Activate

## Update (after first install)

Once installed, updates are automatic. WP will show "Update available" on the Plugins page whenever a new release is published on GitHub. Click **Update Now** and WP downloads + installs the new zip from the Releases page. No re-uploading.

## Features (high level)

- **Content gate** — single posts (and optionally the blog index) require email opt-in
- **Multi-channel** — email or SMS at signup (country dropdown, E.164 phone)
- **Address fields** — optional street/city/region/postal collection
- **Broadcasts** — Mailgun or Brevo (email) + Twilio (SMS), auto-route per subscriber, tag filters, schedule, open/click tracking
- **Tags + Segments** — auto-tagged by channel, country, tier, address status; saved segments for reuse
- **Templates** — reusable email/SMS bodies with `{name}`, `{email}`, `{city}`, `{site_name}` merge tags
- **Welcome + Sequences** — auto-send welcome, multi-step drip campaigns triggered by tag
- **Paid membership** — Stripe Checkout, tiered pricing, magic-link sign-in, soft or hard paywall, member portal
- **Embeddable form** — `[lmeg_signup]` shortcode drops a signup form anywhere on the site
- **Compliance** — CASL/CAN-SPAM unsubscribe footer + one-click endpoint, RSS + REST API sealed for gated posts

## Configuration

After activation, look under **Email Gate** in the WP admin sidebar:

- **Settings** → email provider (Mailgun/Brevo), Stripe keys, member behavior, feed teaser
- **Tags** → create manual tags; auto-tags (`channel:email`, `country:CA`, `tier:basic`) appear as members join
- **Tiers (Paid)** → configure paid subscription products backed by Stripe prices
- **Templates / Segments / Sequences** → optional automation

## Self-updater configuration

The updater points at `LMEG_GITHUB_OWNER/LMEG_GITHUB_REPO`. Defaults are set in the main plugin file. Override in `wp-config.php` if your fork lives elsewhere:

```php
define('LMEG_GITHUB_OWNER', 'yourname');
define('LMEG_GITHUB_REPO', 'loonymoon-email-gate');
```

## License

GPL-2.0+
