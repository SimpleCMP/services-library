---
name: curate-service-provider
description: Use this skill when the user wants to add or update the provider-disclosure fields (vendorAddress, vendorOptOutUrl, vendorPartner, vendorDescription) on one or more service entries in this services-library repo, typically when curating a new vendor or filling in DSGVO Art. 13 L2-modal data for SimpleCMP consumers.
---

# Curate service provider data

Fills the `vendor*` disclosure fields on `data/services/*.json` entries
so SimpleCMP's L2 Provider-Informationen modal renders DSGVO-correct
recipient + address + privacy URL without operator authoring.

Background context lives in upstream
[`simplecmp/docs/research/2026-05-blocked-embed-placeholder-cmp-survey.md`](https://github.com/SimpleCMP/simplecmp/blob/main/docs/research/2026-05-blocked-embed-placeholder-cmp-survey.md)
and REQ-19 in `simplecmp/docs/requirements.md`. Don't re-derive the
design here.

## When to invoke

- Adding a new vendor's provider data across one or more service entries.
- Updating existing provider data (legal entity rename, address change,
  DPF status flip).
- Promoting a long-tail entry from `vendor`-string-only to a full
  curated provider block.

## When NOT to invoke

- For changes to non-`vendor*` fields (matchers, purposes, cookies) —
  use a normal Edit flow.
- For framework / hosting / CMS cookies (Ruby on Rails, Joomla,
  ColdFusion, ASP.NET, AWS infrastructure cookies) — these are
  first-party operator cookies, the legal recipient is the website
  operator, not the framework vendor. Leave their `vendor` strings
  alone and DO NOT add `vendor*` fields.
- For separate product lines (e.g. Magento under Adobe, AWS under
  Amazon) where the cookies are not the parent vendor's
  responsibility. Verify by reading the service description before
  consolidating.

## Procedure

### 1. Identify candidate services

Two paths:

- **User names a vendor** (e.g. "curate Stripe"): grep
  `data/services/*.json` for that vendor string. Note that
  variations may exist ("Adobe", "Adobe Inc.", "Adobe ColdFusion") —
  treat each as a candidate and decide consolidation case-by-case in
  step 3.
- **User wants a frequency audit**: run the audit one-liner

  ```bash
  php -r '
  $counts = [];
  foreach (glob("data/services/*.json") as $f) {
    $j = json_decode(file_get_contents($f), true);
    $v = $j["vendor"] ?? "(none)";
    $counts[$v] = ($counts[$v] ?? 0) + 1;
  }
  arsort($counts);
  foreach ($counts as $v => $c) printf("%4d  %s\n", $c, $v);
  '
  ```

  Vendors with `count > 1` are candidates for consolidation. The
  library is long-tail (~336 distinct vendors / 369 services); only
  the top ~10 vendors realistically benefit.

### 2. Research the legal entity (real work, not boilerplate)

Required facts per vendor — look these up from authoritative sources
(the vendor's own privacy policy, imprint, EU registration register):

- **EU establishment legal name** (e.g. "Google Ireland Limited",
  "Adobe Systems Software Ireland Limited", "Twitter International
  Unlimited Company"). Often differs from the brand name.
- **Full postal address** of that EU establishment. The Borlabs/RCB
  convention is to embed the legal entity name in the address string
  (e.g. `"Google Ireland Limited, Gordon House, Barrow Street, Dublin
  4, Ireland"`) so a single field renders cleanly in the L2 modal.
- **US/global parent company** legal name + jurisdiction.
- **DPF status**: check
  [dataprivacyframework.gov/list](https://www.dataprivacyframework.gov/list).
  Determines whether the partner clause cites DPF (Art. 45 adequacy)
  or SCCs (Art. 46) + supplementary measures.
- **Privacy policy URL** — should already be on the service entry
  but verify it points to the recipient's actual policy.
- **Service-specific opt-out URL** if one exists (ad personalization
  pages count; "uninstall the browser" doesn't).

For vendors without an EU establishment (e.g. Vimeo): set
`vendorCountry` to the actual jurisdiction (e.g. `US`) and write the
`vendorPartner` field as "No EU establishment; [legal name] acts as
controller directly" + the transfer basis.

### 3. Decide consolidation scope

For multi-service vendors, walk the candidate list and split into:

- **Consolidate** — services where the vendor really is the legal
  recipient (e.g. all 12 `vendor: "Google"` services).
- **Skip** — framework/hosting/separate-product entries (see "When
  NOT to invoke" above).

Document the split briefly in the commit message so future curators
understand the reasoning.

### 4. Apply via PHP one-liner

The pattern matches the existing curation commits in this repo
(see git log for examples). Template:

```bash
php -r '
$work = [
  [["<service-id-1>", "<service-id-2>"], [
    "vendor" => "<brand-name>",            // display name visitors see
    "vendorCountry" => "IE",                // ISO-3166-1 alpha-2
    "vendorAddress" => "<legal entity name>, <full postal>",
    "vendorOptOutUrl" => "https://...",     // OPTIONAL — omit if none
    "vendorPartner" => "<US/global parent details + transfer basis>",
    "vendorDescription" => "<one paragraph on the legal entity + transfer flow>",
  ]],
  // ... more vendor blocks ...
];

foreach ($work as $batch) {
  [$ids, $canonical] = $batch;
  foreach ($ids as $id) {
    $f = "data/services/$id.json";
    $j = json_decode(file_get_contents($f), true);
    $base = [];
    foreach ($j as $k => $v) {
      // Strip any existing vendor* fields so we rewrite in canonical order
      if ($k === "vendor" || str_starts_with($k, "vendor")) continue;
      $base[$k] = $v;
    }
    $out = [];
    foreach ($base as $k => $v) {
      $out[$k] = $v;
      if ($k === "name") {
        foreach (["vendor","vendorCountry","vendorAddress","vendorOptOutUrl","vendorPartner","vendorDescription"] as $field) {
          if (isset($canonical[$field])) $out[$field] = $canonical[$field];
        }
      }
    }
    $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($f, $json . "\n");
    echo "wrote $f\n";
  }
}
'
```

**Important conventions:**

- Field order after `name`: `vendor`, `vendorCountry`, `vendorAddress`,
  (optional `vendorOptOutUrl`), `vendorPartner`, `vendorDescription`.
- JSON encoding: `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES |
  JSON_UNESCAPED_UNICODE` (4-space indent, no escaped slashes, real
  Unicode chars).
- Trailing newline on every file (POSIX convention; some tooling
  expects it).
- The script strips ALL existing `vendor*` fields before re-emitting,
  so it's idempotent — running twice produces the same output.

### 5. Verify

```bash
php vendor/bin/phpunit
```

All schema tests must pass. The four sentinel tests
(`vendorAddressIsNonEmptyStringWhenPresent` etc.) now use
`assertGreaterThan(0, $checked)` — they fail if you accidentally drop
the last entry with a given field.

```bash
git diff --stat
```

Sanity check: only the files you intended changed. Common mistake is
forgetting an `id` and silently skipping a service — the diff stat
makes this visible.

### 6. Commit

Convention: one commit per curation pass (multiple vendors fine if
they're a coherent batch). Conventional Commits message shape:

```
feat(data): provider disclosure for <N> services across <V> vendors

<short rationale, ~2-3 lines>

Curated providers:

- <Legal entity name> — <N> services (<service-id>, ...)
- ...

Skipped (framework / separate product line / first-party):
- <service-id> — <one-line reason>

Tests: 19/19 passing, <old> → <new> assertions.
```

The "skipped" section is important when you didn't fully consolidate
— it documents the reasoning so future curators don't re-raise the
same question.

## Canonical examples (reference, not copy-paste boilerplate)

### Multi-service EU-established DPF-listed (Google pattern)

```json
{
  "vendor": "Google",
  "vendorCountry": "IE",
  "vendorAddress": "Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Ireland",
  "vendorOptOutUrl": "https://adssettings.google.com/",
  "vendorPartner": "Google LLC (USA) as parent company. Data transferred to the USA under the EU-US Data Privacy Framework adequacy decision (Art. 45 GDPR).",
  "vendorDescription": "Google Ireland Limited is the EU establishment of Google LLC. It is the EU controller for Google products and processes personal data of European users, forwarding to Google LLC in the USA where required."
}
```

### Multi-service EU-established NOT DPF-listed (TikTok pattern, SCC-only)

```json
{
  "vendor": "TikTok",
  "vendorCountry": "IE",
  "vendorAddress": "TikTok Technology Limited, 10 Earlsfort Terrace, Dublin 2, D02 T380, Ireland",
  "vendorOptOutUrl": "https://www.tiktok.com/safety/privacy-and-security",
  "vendorPartner": "ByteDance Ltd. (Cayman Islands) and TikTok Inc. (USA) as parent group. Not DPF-listed; international transfers under Standard Contractual Clauses (Art. 46 GDPR).",
  "vendorDescription": "TikTok Technology Limited is the EU establishment for TikTok services in Europe. It is the EU controller and processes personal data of European users, forwarding to TikTok Inc. in the USA and ByteDance group entities under Standard Contractual Clauses."
}
```

### NOT DPF-listed with explicit no-equivalent-protection warning (X pattern)

```json
{
  "vendor": "X",
  "vendorCountry": "IE",
  "vendorAddress": "Twitter International Unlimited Company, One Cumberland Place, Fenian Street, Dublin 2, D02 AX07, Ireland",
  "vendorOptOutUrl": "https://x.com/personalization",
  "vendorPartner": "X Corp. (USA) as parent company. Not DPF-listed; transfers to the USA under Standard Contractual Clauses (Art. 46 GDPR) with supplementary measures. The U.S. lacks an EU-equivalent data protection level; government access not excluded.",
  "vendorDescription": "Twitter International Unlimited Company is the EU establishment of X Corp. (formerly Twitter, Inc.). It is the EU controller for X and processes personal data of European users, forwarding to X Corp. in the USA under Standard Contractual Clauses."
}
```

### No EU establishment, DPF-listed parent (Vimeo pattern)

```json
{
  "vendor": "Vimeo",
  "vendorCountry": "US",
  "vendorAddress": "Vimeo.com, Inc., 555 West 18th Street, New York, NY 10011, USA",
  "vendorOptOutUrl": "https://vimeo.com/cookie_policy",
  "vendorPartner": "No EU establishment; Vimeo.com, Inc. acts as controller directly. DPF-listed: transfers under the EU-US Data Privacy Framework adequacy decision (Art. 45 GDPR).",
  "vendorDescription": "Vimeo.com, Inc. is a US-headquartered video hosting platform. It directly controls personal data of European users; transfers are covered by the EU-US Data Privacy Framework adequacy decision."
}
```

## Frequently raised questions

**Should the `vendor` field be the legal entity name?**

No. `vendor` is the brand display name visitors see (e.g. "Google",
"Instagram", "X"). The legal entity name goes inside `vendorAddress`.
This matches the Borlabs convention and keeps the L2 modal readable.

**Why not normalize Provider into a separate entity?**

Audit on 2026-05-27 found 336 distinct vendor strings across 369
services — 1:1 long-tail. Only ~10 vendors have multi-service
consolidation potential. Normalization is non-breaking to add later
(via a `providerId` reference) if a BE Provider catalog UI ever
warrants it. Pre-1.0 cost/benefit doesn't justify it now.

**What about IAB-Vendor-ID and TCF?**

Deferred to post-v1.0 per the upstream "Bewusst nicht in v1.0"
register. Don't add a TCF stub field yet.

**The DPF could be struck down by a Schrems-III ruling. What then?**

`vendorPartner` is the field to update — flip the wording from "EU-US
Data Privacy Framework adequacy decision (Art. 45 GDPR)" to
"Standard Contractual Clauses (Art. 46 GDPR) with supplementary
measures. The U.S. lacks an EU-equivalent data protection level;
government access not excluded." All entries citing DPF would need
this update. The bulk-edit is straightforward; this skill should be
re-invoked with the updated boilerplate.
