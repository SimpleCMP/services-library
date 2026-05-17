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

#### 2a — Group by vendor

OCD has one row per cookie. We want one JSON per service. Group by
the OCD "Data Controller" (vendor) field, fall back to the
"Domain" field when the controller is empty.

A single Google Analytics service ends up with `_ga`, `_gid`,
`_gat`, `__utm*`, etc. all in its `matches.cookies` array.

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

OCD uses its own taxonomy: `Functional`, `Marketing`, `Statistics`,
`Preferences`, `Unclassified`. Map to SimpleCMP purposes:

| OCD              | SimpleCMP                   |
|------------------|------------------------------|
| `Functional`     | `functional`                 |
| `Marketing`      | `marketing` (+ `advertising` for known ad networks) |
| `Statistics`     | `analytics`                  |
| `Preferences`    | `personalization`            |
| `Unclassified`   | (none — leave `purposes: []`) |

The `Unclassified` rows are imported but left without purposes —
they appear in the registry but a curator has to fill in purposes
before they're useful in the FE consent flow.

#### 2d — Derive `origins` opportunistically

OCD's `Domain` field is a useful signal for the host that sets the
cookie (e.g. `.doubleclick.net`). Emit it as an origin matcher
when present:

- Strip leading `.` → `doubleclick.net`
- For `www.example.com` subdomains, emit both `www.example.com`
  literal *and* `*.example.com` wildcard, conservatively.

When the OCD row has no domain, leave `matches.origins` empty.

#### 2e — Skip already-curated services

Read the existing `data/services/*.json` files; any service-id
that already exists is skipped. The hand-curated entries are
authoritative — OCD never overwrites them.

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

## Open questions

These are explicit decisions that need answering before Step 2 starts:

1. **Do we collapse vendor variants?** Some vendors appear in OCD
   under multiple names ("Google", "Google LLC", "Google Inc.").
   Collapse to one canonical name per vendor or keep them separate?
   Recommendation: collapse, with a hardcoded canonicalisation map
   for the top 30 vendors.
2. **Do we include `Unclassified` rows at all?** They have no
   purpose information, so they don't help the FE classifier
   distinguish "known but unverified" from genuinely unknown.
   Recommendation: include them anyway because at least they map
   a cookie name → vendor + privacy policy URL, which is useful
   for the BE *Übernehmen* modal even without purposes.
3. **What do we do about OCD entries for services already in our
   hand-curated library that have *more* cookies than we listed?**
   E.g. our Google Analytics service lists `_ga`, but OCD has 12
   GA-family cookies. Recommendation: leave hand-curated services
   completely untouched; if curation needs the additional matchers,
   that's a separate hand-update.

Once the OCD purposes / canonical-vendor decisions are made, Step 2
can proceed in one focused sitting.
