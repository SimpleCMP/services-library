# Changelog

All notable changes to `simplecmp/services-library` are recorded here.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

_Nothing yet._

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
