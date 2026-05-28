# SimpleCMP Services Library

Curated library of well-known third-party trackers as JSON data, in the
[SimpleCMP Service-DB protocol](https://github.com/SimpleCMP/simplecmp/blob/main/docs/service-db-protocol.md)
shape. Designed to be consumed by the SimpleCMP CMS plugins (TYPO3,
WordPress, Contao) and the SimpleCMP JS library so they don't each have
to maintain their own copy of "what does Google Analytics look like."

This is **pure data + a thin PHP loader**, not a runtime — consumers
import the records into their own registry.

## What's in it

**368 services** covering analytics, ad networks, embeds, forms /
captcha, chat widgets, payments, maps, monitoring, fonts, tag
management, and a long tail of region-specific ad-tech. Each lives in
`data/services/<id>.json` — `ls data/services` is the source of truth
for the current set; a hand-maintained category list would rot too
fast at this size.

Where the data comes from:

- **Hand-curated cores** — Stripe, Google Analytics, Hotjar, Intercom,
  Mixpanel, YouTube, Vimeo, LinkedIn Insight, hCaptcha, Cloudflare
  Turnstile, etc. — written from each vendor's own docs.
- **OCD-derived bulk** — a large chunk was translated from the
  [Open Cookie Database](https://github.com/jkwakman/Open-Cookie-Database)
  via `bin/import-ocd.php`, then re-shaped to the protocol schema
  (slash-bounded regex matchers where OCD used wildcards, etc.).
- **Provider-disclosure curation (v0.3.0)** — 32 services across 10
  legal entities (Google Ireland Limited, Microsoft Ireland Operations
  Limited, Adobe Systems Software Ireland Limited, Meta Platforms
  Ireland Limited, TikTok Technology Limited, Twitter International
  Unlimited Company, Vimeo Inc., LinkedIn Ireland Unlimited Company,
  Pinterest Europe Limited, Stripe Payments Europe Limited) carry the
  full Art. 13 disclosure fields (postal address, opt-out URL,
  partner / joint-controllers, provider description, transfer basis)
  used by SimpleCMP's L2 Provider-Informationen modal. The remaining
  ~340 entries ship with just `vendor` + `vendorCountry` and degrade
  gracefully in the modal. Curators can promote any long-tail entry
  via the [`curate-service-provider`](./.claude/skills/curate-service-provider/SKILL.md)
  Claude Code skill.

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

// Content-only sha256 over the service JSON files (stable across
// README/CI/docs commits) — use this for drift detection between
// a bundled snapshot and a remote reference server. Optional
// `$customDataDir` argument for tools that consume a fresh clone
// rather than the composer-installed copy.
$hash = ServicesLibrary::dataHash();
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
| `description` | string | recommended | Short factual EN description of **the service** |
| `vendorAddress` | string | optional | Full postal address of the legal entity (e.g. `"Gordon House, Barrow Street, Dublin 4, Ireland"`). Surfaced in the SimpleCMP L2 Provider-Informationen modal next to the blocked embed. |
| `vendorOptOutUrl` | HTTPS URL | optional | Service-specific opt-out endpoint (e.g. Google Ads Settings, Adobe opt-out). Distinct from the privacy policy URL. |
| `vendorPartner` | string | optional | Free-text description of joint controllers, parent companies, or data-sharing partners (Fashion ID / Art. 26 DSGVO). |
| `vendorDescription` | string | optional | Short description of **the legal entity / company itself** (distinct from the service `description`). E.g. `"OpenStreetMap ist eine kostenlose und öffentliche geografische Datenbank."` |
| `matches.cookies` | array | one of cookies/origins | Each entry is either a string (exact name or `/regex/`) or a `{ name, requireOrigin }` object (host-qualified — only fires when the runtime has also observed the qualifying origin). |
| `matches.origins` | array of strings | one of cookies/origins | Exact hosts (`maps.googleapis.com`) or wildcard form `*.suffix` (matches both the apex `suffix` and every subdomain `*.suffix`). |
| `retention` | object | optional | `{display, durationDays}` |
| `i18n` | object | optional | Per-language overrides for `title` and `description` |
| `placeholderTitle` | string | optional | Short title for the click-to-enable placeholder UI (when SimpleCMP auto-inserts a `<simplecmp-contextual-notice>` next to a blocked embed). Falls back to `name`/`title` when unset. |
| `placeholderDescription` | string | optional | One-sentence description for the click-to-enable placeholder UI — what the visitor would load by enabling. Falls back to the engine's default `contextualConsent.description` template when unset. |

Tests validate the schema on every PR.

### Provider-disclosure fields (DSGVO Art. 13 L2 modal)

The four `vendor*` fields (`vendorCountry`, `vendorAddress`,
`vendorOptOutUrl`, `vendorPartner`, `vendorDescription`) plus the
existing `vendor` and `privacyPolicyUrl` fields together populate the
**Provider-Informationen modal** that SimpleCMP renders one click
beneath the blocked-embed placeholder. This is the L2 disclosure
surface in the layered consent model (banner first-view → service
expansion / placeholder Mehr-Infos modal → linked Datenschutzerklärung).

All fields are optional. The renderer reads each independently and
hides or marks missing fields as "not specified." Long-tail entries
with only `vendor` + `vendorCountry` show a minimal but legally-
defensible disclosure surface.

Curation guidance: prioritise filling these fields on the top
multi-service vendors (Google, Microsoft, Adobe) and single-service
big-N (Meta, Stripe, Vimeo, X, TikTok, LinkedIn). For obscure 1:1
vendors, the existing `vendor` string is sufficient as a baseline.

### Host-qualified cookie matchers

55 services use the object form `{ name, requireOrigin }` on at least
one cookie matcher. Use it whenever the bare cookie name is too
generic to be safely attributable to one tracker. Concrete examples in
the library:

- Stripe's session cookie `m` — set by Stripe but also by countless
  unrelated sites. Without a host qualifier, every site that happens
  to use a cookie called `m` would false-classify as Stripe.
- GTM's `td`, Bing's `MR` / `MC0` / `CC`, generic GA fallbacks.

The qualifier is satisfied when the recorder observes a network
request to the listed origin in the same session. The classifier
re-validates retroactively when a qualifying origin arrives *after*
the cookie was first seen (`enrichDetection` path). See SimpleCMP
[ADR-0010](https://github.com/SimpleCMP/simplecmp/blob/main/docs/adr/0010-host-qualified-cookie-matchers.md)
for the full design.

Backwards-compatible — string entries continue to work and consumers
that ignore the object form fall back to a name-only match.

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
