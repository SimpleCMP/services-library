# Open Cookie Database (OCD) — source snapshots

This directory caches versioned snapshots of
[Open Cookie Database](https://github.com/jkwakman/Open-Cookie-Database)
that have been used to generate library entries in
`data/services/`. Each file is named by the upstream commit SHA so
re-imports are reproducible and diffs between import generations are
visible in this repo's history.

## Current snapshot

| Field | Value |
|---|---|
| Upstream commit | [`f62992b`](https://github.com/jkwakman/Open-Cookie-Database/commit/f62992b678e770ee210633aa8279d0804514e493) |
| Date | 2026-01-21 |
| Rows | 2264 cookie definitions |
| File | `f62992b678e770ee210633aa8279d0804514e493.csv` |

## Schema

OCD ships one CSV with the following columns (header verbatim):

| Column | Notes |
|---|---|
| `ID` | UUID, OCD-internal, not used in import |
| `Platform` | Product / service name — the import's primary identity key |
| `Category` | OCD purpose: `Functional` / `Analytics` / `Marketing` / `Preferences` / `Unclassified` |
| `Cookie / Data Key name` | Cookie identifier; combined with `Wildcard match` to form a matcher |
| `Domain` | Host the cookie is set on; emitted as `matches.origins` when present |
| `Description` | Short free-text; not currently used (library has its own descriptions) |
| `Retention period` | OCD's free-text retention string; mapped to library `retention.display` when present |
| `Data Controller` | Legal entity — the umbrella vendor, canonicalized via `bin/ocd-canonical-map.php` |
| `User Privacy & GDPR Rights Portals` | Privacy-policy URL |
| `Wildcard match` | `0` = exact, `1` = treat the name as a prefix wildcard |

## License

OCD ships under the [Apache 2.0 license](https://github.com/jkwakman/Open-Cookie-Database/blob/master/LICENSE),
which permits use, modification, and redistribution. Attribution is
not required by Apache 2.0 for derivative data, but we include it
here for traceability and as a thank-you to the OCD maintainers.

## Updating the snapshot

1. Find the latest commit SHA for `open-cookie-database.csv` upstream.
2. Save the file at `data/_sources/ocd/<sha>.csv`.
3. Update this README's "Current snapshot" table.
4. Re-run the import script (`bin/import-ocd.php`) with the new SHA.
5. Review the diff. Old SHA files stay in the repo for archaeology.
