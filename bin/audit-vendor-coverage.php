<?php

declare(strict_types=1);

/**
 * Audit vendor-coverage gaps in the bundled service library.
 *
 * Reads a corpus of known third-party hosts (default:
 * tests/audit-corpus.txt) and runs each through the same
 * matcher production code uses — `ServicesLibrary::services()`
 * with the canonical + aliasOrigins flattened together. Reports
 * hosts that classify as `null` (no matching service) so the
 * curator can extend an existing entry's `aliasOrigins` or add a
 * new service.
 *
 * Usage:
 *
 *     php bin/audit-vendor-coverage.php
 *     php bin/audit-vendor-coverage.php path/to/hosts.txt
 *
 * Corpus file format: one host per line; `#` starts a comment
 * (full-line or trailing); blank lines ignored. Hosts are
 * normalised to lowercase. The default corpus lives in
 * `tests/audit-corpus.txt` and is grown over time as gaps are
 * discovered in the wild.
 *
 * Output (stdout):
 *   - One line per host classification: `OK <host> -> <service>`
 *     or `MISS <host>` (when no service matches).
 *   - Trailing summary: `<matched>/<total> hosts matched, <gaps>
 *     unmatched`.
 *   - Exit code: 0 if all hosts match, 1 if any miss. CI can wire
 *     this in to fail PRs that don't extend coverage for hosts the
 *     curator already knows are tracker-y.
 *
 * The matcher logic mirrors `HostMatcher` (in the TYPO3 ext) and
 * `originMatches` (in the upstream JS recorder) so the audit
 * reflects what production would do. Wildcard `*.suffix` matches
 * the apex AND every subdomain (ADR-0013 wildcard semantics).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use SimpleCMP\ServicesLibrary\ServicesLibrary;

$repoRoot = dirname(__DIR__);
$corpusPath = (string) ($argv[1] ?? ($repoRoot . '/tests/audit-corpus.txt'));
if (!is_readable($corpusPath)) {
    fwrite(STDERR, "audit corpus not readable: {$corpusPath}\n");
    exit(2);
}

$hosts = [];
foreach (file($corpusPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    // Strip trailing comment.
    if (($hash = strpos($line, '#')) !== false) {
        $line = trim(substr($line, 0, $hash));
        if ($line === '') {
            continue;
        }
    }
    $hosts[] = strtolower($line);
}
$hosts = array_values(array_unique($hosts));

if ($hosts === []) {
    fwrite(STDERR, "corpus is empty after parsing\n");
    exit(2);
}

// Build the same flat (origins + aliasOrigins merged) index the
// production matchers see.
$exact = [];
$wildcards = []; // list<{suffix, apex, service}>
foreach (ServicesLibrary::services() as $service) {
    $id = (string) ($service['id'] ?? '');
    if ($id === '') {
        continue;
    }
    $origins = $service['matches']['origins'] ?? [];
    if (!is_array($origins)) {
        continue;
    }
    foreach ($origins as $origin) {
        if (!is_string($origin) || $origin === '') {
            continue;
        }
        if (str_starts_with($origin, '*.')) {
            $apex = substr($origin, 2);
            $wildcards[] = [
                'suffix' => substr($origin, 1), // ".facebook.com"
                'apex' => $apex,
                'service' => $id,
            ];
            continue;
        }
        // First-seen wins on exact-match conflicts.
        $exact[$origin] ??= $id;
    }
}

$match = static function (string $host) use ($exact, $wildcards): ?string {
    if (isset($exact[$host])) {
        return $exact[$host];
    }
    foreach ($wildcards as $w) {
        if ($host === $w['apex'] || str_ends_with($host, $w['suffix'])) {
            return $w['service'];
        }
    }
    return null;
};

$matched = 0;
$missed = [];
foreach ($hosts as $host) {
    $service = $match($host);
    if ($service !== null) {
        printf("OK    %-40s -> %s\n", $host, $service);
        $matched++;
        continue;
    }
    printf("MISS  %s\n", $host);
    $missed[] = $host;
}

echo "\n";
printf("%d/%d hosts matched, %d unmatched\n", $matched, count($hosts), count($missed));

if ($missed !== []) {
    echo "\nUnmatched hosts (grouped by likely registrable suffix):\n";
    $bySuffix = [];
    foreach ($missed as $h) {
        $parts = explode('.', $h);
        $suffix = implode('.', array_slice($parts, -2));
        $bySuffix[$suffix][] = $h;
    }
    ksort($bySuffix);
    foreach ($bySuffix as $suffix => $list) {
        printf("  %s\n", $suffix);
        foreach ($list as $h) {
            printf("    %s\n", $h);
        }
    }
    exit(1);
}

exit(0);
