<?php

declare(strict_types=1);

/**
 * One-shot migration: replace 2-label apex-domain origin literals
 * (`example.com`) with their wildcard form (`*.example.com`).
 *
 * The wildcard form matches the apex AND every subdomain — see
 * `originMatches` in `simplecmp/src/recorder/classifier.ts`. The
 * literal form only matches the apex exactly, which means a real
 * tracker loading from `www.example.com` or `cdn.example.com` slips
 * past classification.
 *
 * Affects 140 services from the OCD import (2026-05-17). Hand-
 * curated services already use `*.suffix` wildcards.
 *
 * Migration rule (conservative): only touch a service whose
 * origin list consists ENTIRELY of 2-label apex literals AND no
 * wildcards. Anything more complex (subdomain literals, mixed,
 * existing wildcards) is left alone — the curator was being
 * specific.
 *
 *     php bin/migrate-apex-origins.php
 *
 * Idempotent: re-running after the first pass is a no-op.
 */

$repoRoot = dirname(__DIR__);
$servicesDir = $repoRoot . '/data/services';

$touched = 0;
$inspected = 0;

foreach (glob($servicesDir . '/*.json') ?: [] as $file) {
    $inspected++;
    $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $origins = $data['matches']['origins'] ?? null;
    if (!is_array($origins) || $origins === []) {
        continue;
    }
    // Eligible only if every origin is a 2-label apex literal.
    $eligible = true;
    foreach ($origins as $o) {
        if (!is_string($o)) {
            $eligible = false;
            break;
        }
        if (str_starts_with($o, '*.') || str_starts_with($o, '/')) {
            $eligible = false;
            break;
        }
        if (substr_count($o, '.') !== 1) {
            $eligible = false;
            break;
        }
    }
    if (!$eligible) {
        continue;
    }
    $rewritten = array_map(static fn (string $o) => '*.' . $o, $origins);
    if ($rewritten === $origins) {
        continue;
    }
    $data['matches']['origins'] = $rewritten;
    file_put_contents(
        $file,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    printf("M  %-40s %s → %s\n", $data['id'] ?? basename($file), implode(',', $origins), implode(',', $rewritten));
    $touched++;
}

echo "\n";
printf("Inspected: %d\n", $inspected);
printf("Migrated:  %d\n", $touched);
