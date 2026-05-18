# Changelog

All notable changes to `simplecmp/services-library` are recorded here.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

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

### Added

- **`bin/migrate-apex-origins.php`** — one-shot migration script
  for the apex-literal → wildcard rewrite above. Conservative:
  only touches services whose origin list consists ENTIRELY of
  2-label apex literals.

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
