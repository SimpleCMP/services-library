<?php

declare(strict_types=1);

/**
 * OCD import — translate Open Cookie Database CSV into per-service
 * JSON files under `data/services/_imported/`. Run with the path to
 * the cached CSV (see `data/_sources/ocd/<sha>.csv`):
 *
 *     php bin/import-ocd.php data/_sources/ocd/<sha>.csv
 *
 * Output:
 *   - One JSON file per unique `Platform` row group, written to
 *     `data/services/_imported/<slug>.json` (segregated for review).
 *   - For platforms whose slug collides with an existing
 *     `data/services/<slug>.json`, a *sidecar* file
 *     `data/services/_imported/<slug>.union.json` is written with
 *     only the deltas (new cookies + origins). Hand-curated files
 *     are never touched — the reviewer decides which deltas to
 *     apply during Step 3.
 *   - Stdout: a per-platform summary and a final totals line.
 *
 * No external dependencies; uses fgetcsv + json_encode only.
 * See `docs/ocd-import-plan.md` for the full design.
 *
 * ## Reproducibility under hand-curated aliasOrigins
 *
 * Hand-curated services may declare `matches.aliasOrigins`
 * extending the canonical `matches.origins` to cover other TLDs
 * the same vendor runs on (Meta on `.facebook.net`, Google on
 * `doubleclick.net`, etc.). This importer NEVER writes to
 * `data/services/<id>.json` — it routes new entries to
 * `data/services/_imported/<id>.json` and collisions to
 * `_imported/<id>.union.json` sidecars. The reviewer applies
 * sidecar deltas to the hand-curated file manually, choosing
 * which alias additions to keep. So `aliasOrigins` is preserved
 * by construction across re-imports. Don't loosen the sidecar
 * routing without re-thinking this guarantee.
 */

// --- input -----------------------------------------------------------

$argv0 = $argv[0] ?? 'import-ocd.php';
if (!isset($argv[1])) {
    fwrite(STDERR, "usage: {$argv0} <path-to-ocd.csv>\n");
    exit(2);
}
$csvPath = (string) $argv[1];
if (!is_readable($csvPath)) {
    fwrite(STDERR, "csv not readable: {$csvPath}\n");
    exit(2);
}
$repoRoot = dirname(__DIR__);
$servicesDir = $repoRoot . '/data/services';
$importedDir = $servicesDir . '/_imported';
@mkdir($importedDir, 0o755, true);

// --- purpose map ----------------------------------------------------
// Per D2 / Step 2c in docs/ocd-import-plan.md. Future OCD snapshots may
// introduce `Unclassified`; those rows get purposes: [] per D2.
$purposeMap = [
    'Functional' => 'functional',
    'Marketing' => 'marketing',
    'Analytics' => 'analytics',
    'Security' => 'security',
    'Necessary' => 'functional',
    'Personalization' => 'personalization',
];

// --- read CSV -------------------------------------------------------

$fh = fopen($csvPath, 'rb');
if (!$fh) {
    fwrite(STDERR, "could not open csv\n");
    exit(2);
}
$header = fgetcsv($fh);
if (!$header) {
    fwrite(STDERR, "empty csv\n");
    exit(2);
}

// Map header names to column indexes once. OCD's header has spaces
// and special characters; cache the indices so the per-row code stays
// readable.
$col = static function (string $name) use ($header): int {
    $i = array_search($name, $header, true);
    if ($i === false) {
        fwrite(STDERR, "csv missing column: {$name}\n");
        exit(2);
    }
    return $i;
};
$IDX = [
    'platform' => $col('Platform'),
    'category' => $col('Category'),
    'cookie' => $col('Cookie / Data Key name'),
    'domain' => $col('Domain'),
    'retention' => $col('Retention period'),
    'controller' => $col('Data Controller'),
    'privacy' => $col('User Privacy & GDPR Rights Portals'),
    'wildcard' => $col('Wildcard match'),
];

/**
 * Slugify a platform name into a kebab-case `service_id`. Handles
 * OCD's `"X / Y"` pattern (product slash vendor) by trimming after
 * the slash to keep just the product side.
 */
$slugify = static function (string $name): string {
    if (str_contains($name, ' / ')) {
        $name = trim(explode(' / ', $name, 2)[0]);
    }
    $name = strtolower($name);
    $name = (string) preg_replace('/[^a-z0-9]+/', '-', $name);
    return trim($name, '-');
};

// Read rows. Group by slugified id so case variants ("HubSpot" /
// "Hubspot") merge into one service rather than silently
// overwriting each other when written.
/** @var array<string, list<array<string, string|bool>>> $byId */
$byId = [];
while (($row = fgetcsv($fh)) !== false) {
    $platform = trim((string) ($row[$IDX['platform']] ?? ''));
    if ($platform === '') {
        $platform = trim((string) ($row[$IDX['domain']] ?? ''));
    }
    if ($platform === '') {
        continue;
    }
    $id = $slugify($platform);
    if ($id === '') {
        continue;
    }
    $byId[$id][] = [
        'platform' => $platform,
        'category' => trim((string) ($row[$IDX['category']] ?? '')),
        'cookie' => trim((string) ($row[$IDX['cookie']] ?? '')),
        'domain' => trim((string) ($row[$IDX['domain']] ?? '')),
        'retention' => trim((string) ($row[$IDX['retention']] ?? '')),
        'controller' => trim((string) ($row[$IDX['controller']] ?? '')),
        'privacy' => trim((string) ($row[$IDX['privacy']] ?? '')),
        'wildcard' => trim((string) ($row[$IDX['wildcard']] ?? '0')) === '1',
    ];
}
fclose($fh);

// --- helpers --------------------------------------------------------

/**
 * Pick the most common non-empty value from a list, breaking ties
 * deterministically by first occurrence. Used to settle on a single
 * Data Controller / privacy URL when a platform's rows disagree.
 */
$mostCommonNonEmpty = static function (array $values): string {
    $counts = [];
    $firstSeen = [];
    foreach ($values as $i => $v) {
        $v = trim((string) $v);
        if ($v === '') {
            continue;
        }
        $counts[$v] = ($counts[$v] ?? 0) + 1;
        $firstSeen[$v] ??= $i;
    }
    if ($counts === []) {
        return '';
    }
    uksort($counts, static fn ($a, $b) => $counts[$b] <=> $counts[$a]
        ?: $firstSeen[$a] <=> $firstSeen[$b]);
    return (string) array_key_first($counts);
};

/**
 * Clean OCD's Domain field into a list of valid hostnames. Returns []
 * when nothing usable remains. Used both for `matches.origins` and for
 * the host-qualified cookie matcher's `requireOrigin`.
 */
$hostnameRe = '/^[a-z0-9*][a-z0-9.*-]*[a-z0-9]$/';
$cleanDomains = static function (string $raw) use ($hostnameRe): array {
    $raw = strtolower($raw);
    if ($raw === '') {
        return [];
    }
    $hosts = [];
    foreach (preg_split('/\s+or\s+/', $raw) ?: [$raw] as $part) {
        $part = (string) preg_replace('/\s*\([^)]*\)\s*$/', '', $part);
        $part = trim($part, " \t\n\r\0\x0B.");
        if ($part !== '' && preg_match($hostnameRe, $part) === 1) {
            $hosts[$part] = true;
        }
    }
    return array_keys($hosts);
};

// Cookie name length at or below which we require a host qualifier
// (ADR-0010). 1-3 chars covers the OCD generic-name long tail
// (Bing's MR/MC0, Stripe's m, GTM's td, …). Tunable.
const SHORT_COOKIE_THRESHOLD = 3;

// --- build services + write -----------------------------------------

$writtenNew = 0;
$writtenUnion = 0;
$skippedEmpty = 0;
$unclassifiedPlatforms = 0;

// Pre-index existing hand-curated services by id to detect unions.
$existingServices = [];
foreach (glob($servicesDir . '/*.json') ?: [] as $f) {
    $decoded = json_decode((string) file_get_contents($f), true);
    if (is_array($decoded) && isset($decoded['id'])) {
        $existingServices[(string) $decoded['id']] = ['file' => $f, 'data' => $decoded];
    }
}

ksort($byId);
foreach ($byId as $id => $rows) {
    // Display name: most common Platform variant under this slug
    // (so collisions like HubSpot/Hubspot pick the better-cased
    // form for `name`). Trim the OCD "Product / Vendor" suffix.
    $rawPlatform = $mostCommonNonEmpty(array_column($rows, 'platform'));
    $displayName = trim(explode(' / ', $rawPlatform, 2)[0]);

    // vendor: most common Data Controller across the platform's rows.
    $vendor = $mostCommonNonEmpty(array_column($rows, 'controller'));
    // privacyPolicyUrl: must be HTTPS per the library's schema test.
    // OCD occasionally lists http:// URLs (3 in the 2026-01-21
    // snapshot); drop those rather than auto-upgrading the scheme
    // (the vendor may not serve HTTPS, and a broken link is worse
    // than a missing one).
    $privacy = $mostCommonNonEmpty(array_column($rows, 'privacy'));
    if ($privacy !== '' && !str_starts_with($privacy, 'https://')) {
        $privacy = '';
    }

    // purposes: union of mapped categories. Unmapped categories
    // (e.g. future `Unclassified`) drop out — service ships with
    // purposes: []; D2's consumer-plugin guardrails take over.
    $purposes = [];
    $hasUnmappedCategory = false;
    foreach ($rows as $r) {
        $cat = $r['category'];
        if ($cat === '') {
            continue;
        }
        if (isset($purposeMap[$cat])) {
            $purposes[$purposeMap[$cat]] = true;
        } else {
            $hasUnmappedCategory = true;
        }
    }
    if ($hasUnmappedCategory && $purposes === []) {
        $unclassifiedPlatforms++;
    }
    $purposes = array_keys($purposes);
    sort($purposes);

    // cookies: literal for wildcard=0, regex `/^name/` for wildcard=1.
    // Short literal cookies (≤ SHORT_COOKIE_THRESHOLD chars) emit the
    // ADR-0010 host-qualified object form `{name, requireOrigin}`
    // when the row carries a clean Domain — otherwise plain literals
    // would false-match generic names on unrelated sites. Cookies are
    // deduplicated by `id|name|origin` so two rows that produce the
    // same triple collapse to one entry; the cookie list preserves
    // first-seen order.
    /** @var array<string, string|array{name: string, requireOrigin: string}> $cookies */
    $cookies = [];
    foreach ($rows as $r) {
        if ($r['cookie'] === '') {
            continue;
        }
        $rowHosts = $cleanDomains($r['domain']);
        if ($r['wildcard']) {
            $entry = '/^' . preg_quote($r['cookie'], '/') . '/';
            $key = "rx|{$entry}";
            $cookies[$key] ??= $entry;
            continue;
        }
        $isShort = strlen($r['cookie']) <= SHORT_COOKIE_THRESHOLD;
        if ($isShort && $rowHosts !== []) {
            // One entry per (name, host) pair — same cookie set on
            // multiple subdomains gets a host-qualified entry each.
            foreach ($rowHosts as $host) {
                $key = "hq|{$r['cookie']}|{$host}";
                $cookies[$key] ??= ['name' => $r['cookie'], 'requireOrigin' => $host];
            }
            continue;
        }
        // Plain literal — long enough to be distinctive, OR short
        // without a host to qualify against (rare; ships as a known
        // false-match risk).
        $key = "lit|{$r['cookie']}";
        $cookies[$key] ??= $r['cookie'];
    }
    $cookies = array_values($cookies);

    // origins: deduplicated, cleaned hostname list across all rows for
    // this service. Uses the same domain cleanup as the host-qualified
    // cookies (free-text qualifiers like `(3rd party)`, ` or `
    // alternatives, hostname validation).
    //
    // 2-label apex domains get the wildcard form `*.example.com`
    // automatically — `originMatches` treats it as "apex + all
    // subdomains", so a real tracker loading from `www.example.com`
    // or `cdn.example.com` still classifies. Specific subdomain
    // literals (3+ labels) stay literal: the curator was being
    // specific. See bin/migrate-apex-origins.php for the one-shot
    // pass over data shipped before this rule.
    $origins = [];
    foreach ($rows as $r) {
        foreach ($cleanDomains($r['domain']) as $host) {
            if (substr_count($host, '.') === 1) {
                $origins['*.' . $host] = true;
            } else {
                $origins[$host] = true;
            }
        }
    }
    $origins = array_keys($origins);

    $matches = [];
    if ($cookies !== []) {
        $matches['cookies'] = $cookies;
    }
    if ($origins !== []) {
        $matches['origins'] = $origins;
    }
    if ($matches === []) {
        $skippedEmpty++;
        continue;
    }

    if (isset($existingServices[$id])) {
        // D3: collision with a hand-curated service. Emit a sidecar
        // `<id>.union.json` listing only the deltas — DO NOT touch
        // the hand-curated file. The reviewer reads the sidecar in
        // Step 3, decides which entries make sense, copies them in.
        $existing = $existingServices[$id]['data'];
        $oldCookies = (array) ($existing['matches']['cookies'] ?? []);
        $oldOrigins = (array) ($existing['matches']['origins'] ?? []);

        // Skip cookies already string-matched OR covered by an
        // existing regex matcher (`/foo/`). The FE classifier checks
        // every matcher, so a literal `AMP_TEST` adds nothing when
        // `/^AMP_/` is already in the list — just visual noise.
        $existingRegexes = [];
        foreach ($oldCookies as $m) {
            if (is_string($m) && strlen($m) >= 2 && $m[0] === '/' && $m[-1] === '/') {
                $existingRegexes[] = '/' . substr($m, 1, -1) . '/';
            }
        }
        $newCookies = [];
        foreach ($cookies as $c) {
            if (in_array($c, $oldCookies, true)) {
                continue;
            }
            // Object-form (host-qualified) entries are never covered
            // by an existing string regex — they impose an extra
            // condition (the host observation). Pass through.
            if (is_array($c)) {
                $newCookies[] = $c;
                continue;
            }
            $coveredByRegex = false;
            // Compare two literals only to regex; literals don't
            // cover each other (the dedup above handled that).
            $isLiteral = !(strlen($c) >= 2 && $c[0] === '/' && $c[-1] === '/');
            if ($isLiteral) {
                foreach ($existingRegexes as $rx) {
                    if (@preg_match($rx, $c) === 1) {
                        $coveredByRegex = true;
                        break;
                    }
                }
            }
            if (!$coveredByRegex) {
                $newCookies[] = $c;
            }
        }
        $newOrigins = array_values(array_diff($origins, $oldOrigins));

        if ($newCookies === [] && $newOrigins === []) {
            printf("=  %-40s no new matchers from OCD\n", $id);
            continue;
        }

        $sidecar = ['id' => $id, 'addToMatches' => []];
        if ($newCookies !== []) {
            $sidecar['addToMatches']['cookies'] = $newCookies;
        }
        if ($newOrigins !== []) {
            $sidecar['addToMatches']['origins'] = $newOrigins;
        }
        file_put_contents(
            $importedDir . '/' . $id . '.union.json',
            json_encode($sidecar, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
        printf(
            "U  %-40s +%dc +%do (sidecar in _imported/, hand-curated file untouched)\n",
            $id,
            count($newCookies),
            count($newOrigins)
        );
        $writtenUnion++;
        continue;
    }

    // New service — write to _imported/ for review.
    $svc = ['id' => $id, 'name' => $displayName];
    if ($vendor !== '') {
        $svc['vendor'] = $vendor;
    }
    $svc['purposes'] = $purposes;
    if ($privacy !== '') {
        $svc['privacyPolicyUrl'] = $privacy;
    }
    $svc['matches'] = $matches;
    file_put_contents(
        $importedDir . '/' . $id . '.json',
        json_encode($svc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
    printf("N  %-40s %dc %do %s\n", $id, count($cookies), count($origins), $vendor !== '' ? "(vendor: {$vendor})" : '');
    $writtenNew++;
}

echo "\n";
printf("Total services grouped:    %d (case variants merged)\n", count($byId));
printf("New services written:      %d (in data/services/_imported/)\n", $writtenNew);
printf("Hand-curated unions:       %d (sidecars in _imported/<id>.union.json; hand files untouched)\n", $writtenUnion);
printf("Skipped (empty matchers):  %d\n", $skippedEmpty);
printf("Platforms with only unmapped categories: %d (purposes:[] — review)\n", $unclassifiedPlatforms);
