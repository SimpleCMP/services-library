# Plan — import the Open Cookie Database into `services-library`

Status: **proposed** (2026-05-17), not yet started.

## Why

The current library covers ~40 well-known third-party trackers
(Google Analytics, GTM, Hotjar, Mixpanel, …). On a freshly-installed
SimpleCMP site, that means:

- Most cookies the recorder sees on a typical CMS site land as
  **`unbekannt`** (unknown) in the BE detection table.
- Admins have to curate each one by hand, even when the cookie is a
  textbook well-known tracker the library *could* have classified.
- The "one-click *Übernehmen*" affordance (REQ-N5 / the three-state
  model) is wasted because almost nothing matches the library.

The [Open Cookie Database (OCD)](https://github.com/jkwakman/Open-Cookie-Database)
is a community-maintained CSV listing ~1500 cookies with vendor and
purpose information, kept up to date for years. CC0-licensed.
Importing it raises the **erkannt** (recognised) rate on a fresh
install from ~10% to a realistic ~70-80% of typical CMS cookie
exhaust.

Concretely: the visible payoff is that a fresh TYPO3 install hooked
to `serviceDbUrl` immediately classifies the majority of cookies it
sees through the recorder + bridge as known, and the BE detection
table fills with mostly *erkannt* rows (one-click action available)
rather than mostly *unbekannt* rows (full curation needed).

## What we're NOT doing

- **Not replacing the hand-curated library.** The 40-ish existing
  services represent careful classification work — purposes are
  vetted, origins are populated, regex patterns are tested. Those
  stay as-is and *take precedence* over OCD imports on
  service-id collisions.
- **Not importing OCD verbatim into the wire format.** OCD is
  cookie-centric (one row per cookie); SimpleCMP's library is
  service-centric (one JSON per service, with `matches.cookies` and
  `matches.origins` arrays). Translation is non-trivial — see Step 2.
- **Not auto-importing into production at install time.** The OCD
  data ships *bundled* with `simplecmp/services-library`. Whether
  it's used at runtime is the integrator's choice via the existing
  `import-known-trackers` CLI command.

## Steps

### Step 1 — Source the data

- Pull the latest `open-cookie-database.csv` from
  https://github.com/jkwakman/Open-Cookie-Database/blob/master/open-cookie-database.csv
- Pin to a specific commit SHA so re-runs are reproducible.
- Cache it inside the repo at `data/_sources/ocd/<sha>.csv` for
  diffability of future re-imports.
- License: CC0 (public domain). No attribution required, but we
  include a `data/_sources/ocd/README.md` noting the upstream
  source and SHA for traceability.

### Step 2 — Translate into the library's wire format

Build `bin/import-ocd.php` (a one-shot script, lives in this repo —
not the consumer plugins). For each OCD row:

#### 2a — Group by Platform; vendor flows through from Data Controller

OCD has one row per cookie. We want one JSON per service. The
*service identity* is the OCD `Platform` field (the product name —
"Google Analytics", "Google Tag Manager", "YouTube"), slugified to
form the `service_id`. The OCD `Data Controller` flows through as
the JSON's `vendor` display field unchanged.

This mirrors our hand-curated library: separate services for
`google-analytics`, `google-tag-manager`, `youtube` — all with
`vendor: "Google"`. Multiple Platforms under one Data Controller
become multiple services, not one mega-service.

When `Platform` is empty (rare in OCD), fall back to the `Domain`
field for the slug.

#### 2b — Collapse cookie families into regex

When a vendor has cookies that match an obvious family pattern
(e.g. `_ga_AB12CD34EF`, `_ga_99ZZ`, `_ga_HEAP123`), emit a single
regex matcher (`/^_ga_[A-Z0-9]+$/`) rather than 30 literal strings.
Concrete heuristic:

- If ≥ 3 cookies under a vendor share a stable prefix and the
  trailing varies in length/charset, emit a regex.
- Otherwise emit literal strings.

The heuristic is tunable but conservative — we'd rather over-list
literals than emit a too-broad regex that classifies arbitrary
unrelated cookies as known.

#### 2c — Map purposes

The pinned 2026-01-21 OCD snapshot uses six categories
(actual distribution in parentheses):

| OCD              | SimpleCMP                    | Rows |
|------------------|------------------------------|------|
| `Functional`     | `functional`                 | 977  |
| `Marketing`      | `marketing` (+ `advertising` for known ad networks) | 861  |
| `Analytics`      | `analytics`                  | 390  |
| `Security`       | `security`                   | 24   |
| `Necessary`      | `functional`                 | 9    |
| `Personalization`| `personalization`            | 3    |

Future OCD snapshots may introduce an `Unclassified` category for
cookies awaiting categorization. When that happens, those rows are
imported with `purposes: []` per D2 — the vendor and privacy-URL
data is still useful even without purposes, and the TYPO3 ext
refuses to save no-purposes services anyway.

When an OCD service ends up with multiple categories across its
cookies (e.g. some rows say `Functional`, others say `Marketing`),
the generated service's `purposes` is the union — deduplicated.

#### 2d — Derive `origins` opportunistically

OCD's `Domain` field is a useful signal for the host that sets the
cookie (e.g. `.doubleclick.net`). Emit it as an origin matcher
when present:

- Strip leading `.` → `doubleclick.net`
- For `www.example.com` subdomains, emit both `www.example.com`
  literal *and* `*.example.com` wildcard, conservatively.

When the OCD row has no domain, leave `matches.origins` empty.

#### 2e — Union into existing services; emit new services for the rest

Read the existing `data/services/*.json` files. For each OCD-derived
service (keyed by Platform-slug), the translator does one of two
things:

- If the slug collides with a hand-curated service's `id` (e.g.
  OCD's "Google Analytics" → `google-analytics`, which we already
  have): **union OCD's `matches.cookies` and `matches.origins`
  into the hand-curated service**, deduplicated against the
  existing matcher arrays. All other fields (`name`, `vendor`,
  `description`, `purposes`, `privacyPolicyUrl`, `retention`,
  `i18n`) are preserved unchanged — hand curation wins on display.
- Otherwise: emit a new service file under `data/services/_imported/`
  for the review pass.

The union is logged so the diff is auditable in the PR — e.g.
*"google-analytics: +`_gid` +`_gat` +`_ga_<ID>` +`__utm[abctvz]` …
(9 cookies added, display fields unchanged)"*. The reviewer reads
the log alongside the file diffs in Step 3.

This is the practical effect of D3 below.

### Step 3 — Manual review pass

The output goes into `data/services/_imported/*.json` (subdirectory
so they're visually segregated from hand-curated entries during
review).

Review checklist for each new service:

1. Is the vendor recognisably a tracker rather than a coincidental
   cookie source (e.g. some sites' own analytics)?
2. Are the regex patterns sensible — not too greedy?
3. Are the purposes credible?
4. Does the origin list, if present, look right?

Expected outcome of a 1500-row OCD: ~600-800 services after
deduplication, of which ~200 need a touch-up and ~50 should be
dropped entirely.

### Step 4 — Move reviewed services into `data/services/`

After the review pass, reviewed JSON files move from
`_imported/` into `services/`. Untouched-imported files stay in
`_imported/` and ship unloaded — the consumer code only walks
`data/services/`.

### Step 5 — Bump the library version

`composer.json` → `0.2.0`. The new minor version signals "library
significantly expanded; consider re-running
`simplecmp:import-known-trackers --force` if you want the new
entries in your registry."

### Step 6 — Sync into consumer plugins

The TYPO3 ext pulls `simplecmp/services-library: ^0.1` →
`^0.2`. Same for the WordPress and Contao plugins once they exist.

## Risks

| Risk | Mitigation |
|------|-----------|
| OCD has incorrect entries (e.g. attributes a vendor's cookie to the wrong company). | Manual review pass in Step 3. |
| Over-broad regex collapses cause false positives in the FE classifier. | Conservative heuristic in Step 2b; review pass; user can `--force`-overwrite a regex-only service with a literal-only one if it bites. |
| The library doubles in size; consumer plugin install footprint grows. | The JSON files compress well; impact on the published Composer package is ~200 KB uncompressed. Negligible. |
| OCD's taxonomy drifts (new purpose categories, renames). | We pin to a specific SHA, so re-imports are deliberate. The mapping table in Step 2c is editable as needed. |
| Vendor renames / acquisitions over time (e.g. "Adobe" vs "Marketo Inc."). | OCD is updated by its community; we re-import every 6-12 months and accept that some services will get renamed. SimpleCMP service IDs are stable slugs that don't change, so admin curation survives renames. |
| OCD's Apache-2.0 / CC0 license terms. | OCD ships under CC0 (per the repo's README). We attribute in `_sources/README.md` even though attribution isn't required. |

## Scope

- **Step 1 (source):** 30 minutes — script + pin SHA.
- **Step 2 (translate):** 3-4 hours — the heuristics are the
  interesting part. Output goes into `_imported/`.
- **Step 3 (review):** 2-3 hours, possibly more depending on the
  output volume. The single biggest time sink.
- **Step 4-6 (move, version, sync):** 30 minutes total.

**Total: ~6-8 hours.**

## Decisions

### D1 — Identity = `Platform`, vendor passes through unchanged (decided 2026-05-17)

`service_id` is derived from OCD's `Platform` column (the product
name — "Google Analytics", "YouTube"), slugified. The OCD
`Data Controller` flows through as the JSON's `vendor` display
field verbatim. No canonicalization map is needed at import time.

This came out of two observations:

1. **Our hand-curated library is already shaped by product, not
   vendor.** `google-analytics`, `google-tag-manager`, and
   `youtube` are three separate services, all with `vendor:
   "Google"`. Mapping OCD's `Platform` to `service_id` matches
   what we already do; mapping OCD's `Data Controller` to
   `vendor` carries the legal-entity string through unchanged.

2. **OCD's data is well-curated.** Inspection of the 322 unique
   Data Controllers in the 2026-01-21 snapshot turned up only a
   handful of casing variants (`Pubmatic` vs `PubMatic`,
   `GitHub` vs `Github`) and a few `.com`-suffix forms. None of
   these affect `service_id` (which comes from `Platform`); they
   only produce minor display inconsistencies in the `vendor`
   field. Worth tolerating — the BE detection list and FE consent
   UI both render fine.

If display inconsistencies turn out to matter post-launch
(admins complaining about seeing `Shopify.com` instead of
`Shopify`), the response is a focused per-row fix in
`data/services/_imported/*.json` during the review pass —
not a runtime canonical-map machinery.

**`service_id` is permanent once shipped** — this is the durable
rule, independent of the canonicalization question. Renames only
ever update display fields (`name`, `vendor`); `id` never changes.
Consumer plugins (TYPO3 ext, future WP / Contao plugins) can rely
on this — they store the `id` in their own tables and trust future
library releases to keep classifying the same cookies under the
same key.

### D2 — Include `Unclassified` OCD rows with empty `purposes` (decided 2026-05-17)

OCD's `Unclassified` rows are imported, but with `purposes: []`. The
vendor and privacy-policy-URL data is still useful — the BE
*Übernehmen* modal can show "this is HubSpot; please specify
purposes before adopting."

**Consumer-plugin follow-up (TYPO3 ext, then WP / Contao):**
purposes must be non-empty for any service to be saved. Two
enforcement points:

1. *TCA-level*: `required: true` on the `purposes` field. Blocks
   *Anpassen*, *Kuratieren*, and direct *Web → List* edits.
   DataHandler enforces server-side.
2. *Übernehmen path*: when the library entry has `purposes: []`,
   the silent-import button is replaced with a button routing to
   the curation form (*Anpassen* style) with purposes required.

Net effect: no admin can put a no-purposes service into the
registry. Library entries with empty purposes are *useless in
production until curated*, which is the intended outcome — better
than letting an under-classified service silently ship.

This retires the earlier "empty purposes is a valid needs-review
state" stance from the v0.2 TCA design discussion. The state
*needs-review* now lives in the detection table (the *erkannt*
badge), not in the service registry. A row in the service
registry without purposes is a bug.

Tracked as a follow-up task in the TYPO3 ext, not in this library
repo — the library can ship empty-purpose services; it's the
consumer plugins' job to refuse to save them.

### D3 — Union OCD cookies into existing hand-curated services (decided 2026-05-17)

When OCD covers a vendor we already have a hand-curated service for,
**OCD's cookies and origins are unioned into the existing service**.
Hand curation wins on every display field (`name`, `vendor`,
`description`, `purposes`, `privacyPolicyUrl`, `retention`, `i18n`)
— those stay exactly as the hand-curator wrote them. OCD only ever
contributes to the matcher arrays.

Concrete example: our `google-analytics` ships with
`matches.cookies: ["_ga"]` and a hand-vetted purpose list. OCD's
"Google" canonical vendor has 12 GA-family cookies. After import:

```json
{
  "id": "google-analytics",
  "name": "Google Analytics",        // hand-curated, unchanged
  "vendor": "Google",                // hand-curated, unchanged
  "purposes": ["analytics"],         // hand-curated, unchanged
  "matches": {
    "cookies": [                     // unioned with OCD additions
      "_ga", "_gid", "_gat", "/^_ga_/", "__utma", "__utmb", "__utmc",
      "__utmt", "__utmv", "__utmz"
    ],
    "origins": ["www.google-analytics.com"]
  }
}
```

**Why union, not separate service:** the alternative — emitting OCD's
"Google" as a separate service `google` alongside hand's
`google-analytics` — would mean two services in the registry both
classifying `_ga`. The FE classifier resolves to one, the other is
dead weight. Confusing for admins. Single canonical service per
vendor is cleaner.

**Why union, not full overwrite:** hand-curated display fields are
*intentional choices*. The hand-curator picked `analytics` instead of
`analytics + marketing`, wrote a specific German translation, vetted
the privacy-URL link target. OCD's data is fine for cookie names but
imprecise for everything else.

**Why library-side, not runtime:** the union happens at OCD-import
time in this repo, not at detection time in a consumer plugin. A
silent runtime "we saw `_gat`, let's add it to your `google-analytics`
service" is auto-curation, which is explicitly off the table for
compliance reasons — every addition to the registry must be a
deliberate admin choice (running `simplecmp:import-known-trackers
--force` against a new library version *is* a deliberate choice).

**Consumer-plugin propagation:** a site that's already running
`simplecmp:import-known-trackers` against a previous library version
sees no change until they update the library AND re-run the import
with `--force`. Default skip-if-exists preserves admin edits to the
TYPO3 `tx_simplecmptypo3_service` row, including any cookie matchers
the admin had hand-added. Admins who want the full union pass
`--force` (and accept that other admin edits to those rows are
clobbered).

The OCD-import plan questions are all resolved. Step 2 can proceed.
