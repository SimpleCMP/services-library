# Changelog

All notable changes to `simplecmp/services-library` are recorded here.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 0.3.2 — 2026-05-28

### Changed

- `ServicesLibraryTest` now locks the `dataHash()` invariants
  consumers rely on: order-invariance (sort() normalises traversal
  order), content-sensitivity (a single-byte edit changes the
  hash), and filename-sensitivity (a rename changes the hash even
  if content is identical, because the basename is folded in).
- `bin/migrate-apex-origins.php` now warns to STDERR when a
  service file is missing the `id` field instead of silently
  falling back to the filename.
- README now documents `ServicesLibrary::dataHash()` in the
  PHP-consumers section.

No data changes — `dataHash()` output is identical to v0.3.1.

## 0.3.1 — 2026-05-28

### Added

- **`ServicesLibrary::dataHash(string $dir): string`** — sha256 over
  the sorted, normalised service JSON files. Stable across
  README/CI/docs commits so downstream consumers (e.g. the
  TYPO3 plugin's *Bibliotheks-Upstream* freshness panel) can detect
  *content* drift between a bundled snapshot and an upstream reference
  server without false positives from non-data commits.

## 0.3.0 — 2026-05-27

Layer-2 Provider-Informationen disclosure (REQ-19 in
[`SimpleCMP/simplecmp`](https://github.com/SimpleCMP/simplecmp)) —
each service can now carry full DSGVO Art. 13 recipient data inline.
Plus the placeholder polish from previous Unreleased rolls in here.

### Added

- **Four new optional `vendor*` fields** on each service entry:
  - `vendorAddress` — full postal address of the legal entity
    (e.g. `"Google Ireland Limited, Gordon House, Barrow Street,
    Dublin 4, Ireland"`)
  - `vendorOptOutUrl` — service-specific opt-out URL (HTTPS)
  - `vendorPartner` — free-text joint-controllers / partners +
    transfer-basis disclosure (DPF / SCCs / Art. 49)
  - `vendorDescription` — short description of the legal entity
    itself, distinct from the existing service `description`
  Tests in `ServicesLibraryTest.php` validate the shape when present
  (non-empty strings, HTTPS URL for `vendorOptOutUrl`).
- **32 services curated with full provider data** across 10 vendors:
  Google Ireland Limited (12), Microsoft Ireland Operations Limited
  (4), Adobe Systems Software Ireland Limited (5), Meta Platforms
  Ireland Limited (2 — facebook, instagram), TikTok Technology
  Limited (2), Twitter International Unlimited Company (1 — x;
  SCC-only + explicit "no equivalent protection" disclosure since X
  Corp. is not DPF-listed), Vimeo.com, Inc. (1; no EU establishment,
  DPF-listed), LinkedIn Ireland Unlimited Company (2), Pinterest
  Europe Limited (2), Stripe Payments Europe Limited (1).
- **`.claude/skills/curate-service-provider/SKILL.md`** — repo-scoped
  Claude Code skill documenting the curation procedure (when to
  invoke, what to skip, per-vendor research checklist, idempotent
  PHP one-liner pattern, four canonical example shapes covering
  DPF-listed multi-service, SCC-only, no-EU-establishment, and
  no-equivalent-protection cases).
- **Optional `placeholderTitle` / `placeholderDescription` fields**
  for the click-to-enable placeholder UI that SimpleCMP auto-inserts
  next to blocked embeds (`<simplecmp-contextual-notice>`). The
  consuming SimpleCMP engine reads these as
  `service.placeholderTitle` / `service.placeholderDescription` and
  falls back to the existing `name` / default i18n description when
  unset, so the new fields are purely additive — no existing
  consumer breaks if they don't pass the new fields through.
  Curated `placeholderDescription` copy added for 15 of the
  most-embedded services: YouTube, Vimeo, Google Maps, Instagram, X
  (Twitter), Spotify, SoundCloud, Twitch, Facebook, hCaptcha,
  Cloudflare Turnstile, Typeform, JotForm, Disqus, Mapbox.
- **`bin/migrate-apex-origins.php`** — one-shot migration script
  for the apex-literal → wildcard rewrite below. Conservative:
  only touches services whose origin list consists ENTIRELY of
  2-label apex literals.

### Fixed

- **Apex-domain origin literals migrated to wildcard form.** 140
  OCD-derived services shipped with origins like `example.com`
  (literal), which only match the apex exactly. Real trackers
  loading from `www.example.com` or `cdn.example.com` slipped
  past classification. Rewritten as `*.example.com` (matches apex
  + every subdomain per the existing `originMatches` semantics).
  Hand-curated services were already using wildcards correctly.
  The translator (`bin/import-ocd.php`) now emits the wildcard
  form automatically for 2-label domains; 3+ label literals stay
  as-is. Migration script `bin/migrate-apex-origins.php` is
  idempotent — re-running it is a no-op once the data is clean.
- **YouTube origin coverage** (`data/services/youtube.json`).
  OCD's apex `youtube.com` literal didn't match `www.youtube.com`
  iframe embeds. Added `*.youtube.com`, `*.googlevideo.com`,
  `*.ytimg.com` so real YouTube integrations classify.
- **OCD-import safety**: drop the `@` from `preg_match` so malformed
  regex sidecars surface as PHP warnings instead of silently
  failing. Functional fallback unchanged.

## 0.2.1 — 2026-05-17

### Fixed

- Three OCD-derived services (admatic, pulsepoint, semasio-net)
  shipped with `http://` privacy-policy URLs, failing the library's
  schema test (requires HTTPS). The translator now drops
  privacyPolicyUrl when it isn't HTTPS; the three affected JSONs
  have the field removed. Auto-upgrading the scheme is unsafe — a
  broken link is worse than a missing one.

## 0.2.0 — 2026-05-17

Major expansion. The library grows ~9× via a one-shot import from
[Open Cookie Database](https://github.com/jkwakman/Open-Cookie-Database),
pinned to upstream commit `f62992b` (2026-01-21). Plus a protocol
extension (ADR-0010 in `SimpleCMP/simplecmp`) for host-qualified
cookie matchers — short generic cookie names are classifiable
without false-matching.

### Added

- **328 OCD-derived service definitions** under `data/services/`,
  covering the long tail (ad-tech, embeds, ecommerce platforms,
  monitoring, chat widgets, CMS frameworks, …). The hand-curated 40
  stay untouched — they're authoritative on display fields; OCD
  only contributes matcher coverage. Library total: **368 services**.
- **`{name, requireOrigin}` cookie matcher form** (ADR-0010
  upstream). Short generic cookies (≤ 3 chars) from OCD ship in the
  object form when OCD provides a Domain — 145 such entries in this
  release. Backwards compatible: consumers ignoring the new shape
  treat them as non-matching.
- **`bin/import-ocd.php`** — one-shot translator from OCD's CSV to
  the protocol's per-service JSON shape. Handles: slug derivation
  from Platform, vendor flow-through from Data Controller, purpose
  mapping, OCD wildcard → regex, origin field cleanup
  (`(3rd party)` annotations, `X or Y` alternatives), regex-covered
  cookie dedup, host-qualified emission for short cookies.
- **`bin/triage-imported.php`** — produces a Markdown triage report
  grouping `_imported/` files into review buckets (infrastructure,
  generic-cookies, low-coverage, over-collapsed, peer-of-hand-curated,
  keep).
- **`data/_sources/ocd/`** — pinned snapshot of the upstream OCD CSV
  with attribution README. Future re-imports save fresh snapshots
  alongside; old snapshots stay for archaeology.
- **`docs/ocd-import-plan.md`** — the design discussion that drove
  the import (D1: Platform → service_id + service_id is permanent;
  D2: Unclassified rows imported with empty purposes, consumer
  plugins refuse to save no-purposes services; D3: union OCD
  cookies/origins into hand-curated services via sidecars,
  hand-curators apply manually).
- **Stability guarantee documented in README.** `id` field is the
  stable join key — once a service ships in a tagged release, its
  `id` is permanent. Renames only ever update display fields
  (`name`, `vendor`).

## 0.1.0

Initial release.

### Added

- 40 curated service definitions across analytics, ad networks,
  embeds, forms / captcha, chat widgets, payments, maps, monitoring,
  fonts, tag management, email, and comments.
- `SimpleCMP\ServicesLibrary\ServicesLibrary` helper class with
  `dataPath()` and `services()`.
- PHPUnit schema-validation tests for every bundled file (parses,
  required fields, kebab-case IDs, known purposes, ISO country codes,
  HTTPS privacy URLs, at-least-one matcher, unique IDs).
