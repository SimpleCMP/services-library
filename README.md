# SimpleCMP Services Library

Curated library of well-known third-party trackers as JSON data, in the
[SimpleCMP Service-DB protocol](https://github.com/SimpleCMP/simplecmp/blob/main/docs/service-db-protocol.md)
shape. Designed to be consumed by the SimpleCMP CMS plugins (TYPO3,
WordPress, Contao) and the SimpleCMP JS library so they don't each have
to maintain their own copy of "what does Google Analytics look like."

This is **pure data + a thin PHP loader**, not a runtime — consumers
import the records into their own registry.

## What's in it

40 services covering:

- **Analytics** (6): Mixpanel, Hotjar, Plausible, Fathom, Amplitude, Heap
- **Ad networks** (8): LinkedIn Insight, TikTok Pixel, Pinterest Tag,
  X Pixel, Snapchat Pixel, Microsoft Bing UET, Outbrain, Taboola
- **Embeds** (5): Vimeo, Instagram, Spotify, SoundCloud, Twitch
- **Forms / captcha** (4): hCaptcha, Cloudflare Turnstile, Typeform, JotForm
- **Chat widgets** (6): Intercom, Drift, Crisp, Tawk.to, Zendesk Chat, HubSpot
- **Payments** (3): Stripe, PayPal, Klarna
- **Maps** (1): Mapbox
- **Monitoring** (3): Bugsnag, LogRocket, Rollbar
- **Fonts** (1): Adobe Fonts (Typekit)
- **Tag management / email / comments** (3): Google Tag Manager, Mailchimp, Disqus

Each lives in `data/services/<id>.json`.

## Usage (PHP consumers)

```bash
composer require simplecmp/services-library
```

```php
use SimpleCMP\ServicesLibrary\ServicesLibrary;

// Iterate every record
foreach (ServicesLibrary::services() as $service) {
    // ['id' => 'mixpanel', 'name' => 'Mixpanel', 'vendor' => …,
    //  'purposes' => […], 'matches' => ['cookies' => …, 'origins' => …]]
}

// Or resolve the data directory directly
$dir = ServicesLibrary::dataPath();
foreach (glob($dir . '/*.json') as $file) { … }
```

## Schema

Each JSON file conforms to the upstream Service-DB protocol:

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | string (kebab-case) | yes | Unique within the library. **Permanent** — see Stability. |
| `name` | string | yes | Display name shown to end users |
| `vendor` | string | recommended | Operator / legal entity |
| `vendorCountry` | ISO 3166-1 α-2 | recommended | Where the vendor is established |
| `purposes` | array of enum | yes | `analytics`, `marketing`, `advertising`, `functional`, `personalization`, `security` |
| `privacyPolicyUrl` | HTTPS URL | recommended | Vendor's privacy policy |
| `description` | string | recommended | Short factual EN description |
| `matches.cookies` | array of strings | one of cookies/origins | Exact names or `/regex/` patterns |
| `matches.origins` | array of strings | one of cookies/origins | Exact hosts or `*.suffix` wildcards |
| `retention` | object | optional | `{display, durationDays}` |
| `i18n` | object | optional | Per-language overrides for `title` and `description` |

Tests validate the schema on every PR.

## Stability

The `id` field is the **stable join key** consumers use to reference a
service across the registry, detection rows, and the library itself.
Once a service ships in a tagged release, its `id` is permanent —
renames or vendor consolidations only ever update display fields
(`name`, `vendor`), never the `id`. This means consumer plugins (the
TYPO3 ext, future WordPress / Contao plugins) can safely store the
`id` in their own tables and trust that future library releases will
keep classifying the same cookies under the same key.

If a typo or genuinely-wrong `id` ships, the fix is a service-id
rename helper in each consumer plugin, not a silent change in the
library. Mass-renames are off the table by design.

## Contributing

Pull requests adding new tracker definitions are welcome. Add a single
`data/services/<id>.json` file following the schema above. Use kebab-case
for the `id`. Cookie / origin patterns should be sourced from the
vendor's official documentation when possible.

## License

BSD-3-Clause, matching the broader SimpleCMP project. See [LICENSE](LICENSE).
