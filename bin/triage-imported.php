<?php

declare(strict_types=1);

/**
 * Triage `data/services/_imported/*.json` into review buckets so the
 * human review pass (Step 3 of docs/ocd-import-plan.md) can focus on
 * the highest-impact subset first.
 *
 *     php bin/triage-imported.php > data/services/_imported/TRIAGE.md
 *
 * Buckets, in order of expected review effort:
 *   1. Drop candidates — infrastructure / CMS framework cookies that
 *      aren't really third-party trackers.
 *   2. Drop candidates — generic cookie names that would over-match
 *      on real websites.
 *   3. Low coverage — ≤2 cookies and no origin. Hard to justify a
 *      whole service row for.
 *   4. Over-collapsed — >30 cookies or conflicting purposes (e.g.
 *      both `functional` and `marketing`), suggesting OCD lumped
 *      distinct services together.
 *   5. Peer-of-hand-curated — vendor matches an existing hand-
 *      curated service; review the union sidecar instead.
 *   6. Keep candidates — everything else; quick sanity-check pass.
 *
 * The same file may surface in multiple buckets if it triggers
 * multiple rules. Listing is for review prioritisation, not
 * automatic action; the human decides.
 */

$repoRoot = dirname(__DIR__);
$importedDir = $repoRoot . '/data/services/_imported';
$handCuratedDir = $repoRoot . '/data/services';

// Infrastructure / framework / CMS plugin identifiers that almost
// certainly aren't third-party trackers worth a service row.
// Names checked against both `id` and `vendor`.
$infraPatterns = [
    'nginx', 'haproxy', 'vbulletin', 'phpmyadmin', 'asp.net', 'aspnet',
    'nextjs', 'next-auth', 'nextauth.js', 'cleantalk', 'nopcommerce',
    'drupal', 'wordpress', 'woocommerce', 'magento', 'jetpack',
    'f5', 'cookieyes', 'consentmanager', 'cookieconsent', 'cookiebot',
    'github', 'xenforo', 'phpsession', 'php.net', 'azure', 'sap',
    'vercel', 'wix', 'shopify',
];

$shortCookieThreshold = 3; // cookies ≤ this length are "very generic"

// Read hand-curated vendors so we can flag OCD imports that overlap.
$handCuratedVendors = [];
foreach (glob($handCuratedDir . '/*.json') ?: [] as $f) {
    $d = json_decode((string) file_get_contents($f), true);
    if (is_array($d) && isset($d['vendor']) && is_string($d['vendor'])) {
        $handCuratedVendors[strtolower($d['vendor'])] = (string) ($d['id'] ?? '');
    }
}

/** @var array<string, list<array{id: string, summary: string}>> $buckets */
$buckets = [
    'infrastructure' => [],
    'generic-cookies' => [],
    'low-coverage' => [],
    'over-collapsed' => [],
    'peer-of-hand-curated' => [],
    'keep' => [],
];

$total = 0;
foreach (glob($importedDir . '/*.json') ?: [] as $f) {
    if (str_ends_with($f, '.union.json')) {
        continue;
    }
    $total++;
    $svc = json_decode((string) file_get_contents($f), true);
    if (!is_array($svc)) {
        continue;
    }
    $id = (string) ($svc['id'] ?? '');
    $vendor = (string) ($svc['vendor'] ?? '');
    $purposes = (array) ($svc['purposes'] ?? []);
    $cookies = (array) ($svc['matches']['cookies'] ?? []);
    $origins = (array) ($svc['matches']['origins'] ?? []);

    $summary = sprintf(
        '`%s` — %s • %dc %do • %s',
        $id,
        $vendor !== '' ? $vendor : '(no vendor)',
        count($cookies),
        count($origins),
        implode('+', $purposes) ?: '(no purposes)'
    );

    $matched = false;

    // (1) infrastructure / framework
    foreach ($infraPatterns as $p) {
        $needle = strtolower($p);
        if (str_contains(strtolower($id), $needle) || str_contains(strtolower($vendor), $needle)) {
            $buckets['infrastructure'][] = ['id' => $id, 'summary' => $summary . " (matched: `{$p}`)"];
            $matched = true;
            break;
        }
    }

    // (2) generic cookies — count short, non-regex cookies
    $shortLiterals = 0;
    foreach ($cookies as $c) {
        if (!is_string($c)) {
            continue;
        }
        $isRegex = strlen($c) >= 2 && $c[0] === '/' && $c[-1] === '/';
        if (!$isRegex && strlen($c) <= $shortCookieThreshold) {
            $shortLiterals++;
        }
    }
    if ($shortLiterals >= 3 && $shortLiterals / max(1, count($cookies)) > 0.5) {
        $buckets['generic-cookies'][] = ['id' => $id, 'summary' => $summary . " ({$shortLiterals} short-literal cookies)"];
        $matched = true;
    }

    // (3) low coverage
    if (count($cookies) <= 2 && count($origins) === 0) {
        $buckets['low-coverage'][] = ['id' => $id, 'summary' => $summary];
        $matched = true;
    }

    // (4) over-collapsed — too many cookies OR mixed purposes
    $hasMixedPurposes = in_array('marketing', $purposes, true)
        && in_array('functional', $purposes, true);
    if (count($cookies) > 30 || $hasMixedPurposes) {
        $reason = count($cookies) > 30 ? count($cookies) . ' cookies' : 'mixed marketing+functional';
        $buckets['over-collapsed'][] = ['id' => $id, 'summary' => $summary . " ({$reason})"];
        $matched = true;
    }

    // (5) peer-of-hand-curated — vendor overlap
    $vendorKey = strtolower($vendor);
    if ($vendor !== '' && isset($handCuratedVendors[$vendorKey])) {
        $buckets['peer-of-hand-curated'][] = [
            'id' => $id,
            'summary' => $summary . " (overlaps hand-curated vendor `{$vendor}` → `{$handCuratedVendors[$vendorKey]}`)",
        ];
        $matched = true;
    }

    // (6) keep — everything else
    if (!$matched) {
        $buckets['keep'][] = ['id' => $id, 'summary' => $summary];
    }
}

// Render Markdown report.
$bucketTitles = [
    'infrastructure' => 'Infrastructure / framework — drop candidates',
    'generic-cookies' => 'Generic short cookies — drop candidates (false-match risk)',
    'low-coverage' => 'Low coverage (≤2 cookies, no origin) — drop candidates',
    'over-collapsed' => 'Over-collapsed (>30 cookies or mixed purposes) — split or curate',
    'peer-of-hand-curated' => 'Vendor peer of hand-curated — review the union sidecar instead',
    'keep' => 'Keep candidates — quick sanity check pass',
];

echo "# OCD import — triage report\n\n";
echo "Generated by `bin/triage-imported.php` against the current `data/services/_imported/`.\n\n";
echo "Total `_imported/` services: **{$total}**. Files may surface in multiple buckets.\n\n";
echo "Bucket counts:\n\n";
foreach ($bucketTitles as $key => $title) {
    printf("- **%s** — %d\n", $title, count($buckets[$key]));
}
echo "\n";

foreach ($bucketTitles as $key => $title) {
    $entries = $buckets[$key];
    echo "## {$title} (" . count($entries) . ")\n\n";
    if ($entries === []) {
        echo "_(none)_\n\n";
        continue;
    }
    usort($entries, static fn ($a, $b) => $a['id'] <=> $b['id']);
    foreach ($entries as $e) {
        echo '- ' . $e['summary'] . "\n";
    }
    echo "\n";
}
