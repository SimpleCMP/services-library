<?php

declare(strict_types=1);

namespace SimpleCMP\ServicesLibrary\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\ServicesLibrary\ServicesLibrary;

/**
 * Schema validation for every bundled service file. Run on every PR
 * via CI so contributions can't slip in malformed data.
 */
final class ServicesLibraryTest extends TestCase
{
    private const array ALLOWED_PURPOSES = [
        'analytics',
        'marketing',
        'advertising',
        'functional',
        'personalization',
        'security',
    ];

    #[Test]
    public function dataDirectoryExists(): void
    {
        self::assertDirectoryExists(ServicesLibrary::dataPath());
    }

    #[Test]
    public function dataHashIsAStableSha256(): void
    {
        $hash = ServicesLibrary::dataHash();
        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        // Stability: two consecutive reads of the same on-disk state must agree.
        self::assertSame($hash, ServicesLibrary::dataHash());
    }

    #[Test]
    public function dataHashIsInvariantToFileInsertionOrder(): void
    {
        $a = $this->makeTempDir('svc-lib-order-a');
        $b = $this->makeTempDir('svc-lib-order-b');
        try {
            file_put_contents($a . '/alpha.json', '{"id":"alpha"}');
            file_put_contents($a . '/beta.json', '{"id":"beta"}');
            file_put_contents($a . '/gamma.json', '{"id":"gamma"}');

            file_put_contents($b . '/gamma.json', '{"id":"gamma"}');
            file_put_contents($b . '/alpha.json', '{"id":"alpha"}');
            file_put_contents($b . '/beta.json', '{"id":"beta"}');

            self::assertSame(
                ServicesLibrary::dataHash($a),
                ServicesLibrary::dataHash($b),
                'dataHash must be invariant to file insertion order — sort() inside the implementation normalises traversal.',
            );
        } finally {
            $this->removeTempDir($a);
            $this->removeTempDir($b);
        }
    }

    #[Test]
    public function dataHashChangesOnSingleByteContentEdit(): void
    {
        $dir = $this->makeTempDir('svc-lib-content');
        try {
            file_put_contents($dir . '/x.json', '{"id":"x","name":"X"}');
            $before = ServicesLibrary::dataHash($dir);
            file_put_contents($dir . '/x.json', '{"id":"x","name":"Y"}');
            $after = ServicesLibrary::dataHash($dir);
            self::assertNotSame($before, $after, 'dataHash must change when a single byte of file content changes.');
        } finally {
            $this->removeTempDir($dir);
        }
    }

    #[Test]
    public function dataHashChangesOnFilenameRenameEvenWhenContentIdentical(): void
    {
        $dir = $this->makeTempDir('svc-lib-rename');
        try {
            $payload = '{"id":"placeholder"}';
            file_put_contents($dir . '/foo.json', $payload);
            $before = ServicesLibrary::dataHash($dir);
            rename($dir . '/foo.json', $dir . '/bar.json');
            $after = ServicesLibrary::dataHash($dir);
            self::assertNotSame(
                $before,
                $after,
                'dataHash must change on rename — basename is folded in so a moved service is visible to drift detection.',
            );
        } finally {
            $this->removeTempDir($dir);
        }
    }

    #[Test]
    public function libraryShipsAtLeastOneService(): void
    {
        $count = iterator_count(self::iterableToGenerator(ServicesLibrary::services()));
        self::assertGreaterThan(0, $count);
    }

    #[Test]
    public function everyServiceFileParsesAsJsonObject(): void
    {
        $files = glob(ServicesLibrary::dataPath() . '/*.json') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            self::assertIsArray($decoded, sprintf('%s should parse as a JSON object', basename($file)));
        }
    }

    #[Test]
    public function everyServiceHasRequiredFields(): void
    {
        foreach (ServicesLibrary::services() as $service) {
            self::assertArrayHasKey('id', $service);
            self::assertArrayHasKey('name', $service);
            self::assertArrayHasKey('purposes', $service);
            self::assertArrayHasKey('matches', $service);
        }
    }

    #[Test]
    public function everyServiceIdIsKebabCase(): void
    {
        foreach (ServicesLibrary::services() as $service) {
            self::assertMatchesRegularExpression(
                '/^[a-z0-9]+(-[a-z0-9]+)*$/',
                (string) $service['id'],
                sprintf('Service id %s should be kebab-case', $service['id']),
            );
        }
    }

    #[Test]
    public function everyServicePurposeIsKnown(): void
    {
        foreach (ServicesLibrary::services() as $service) {
            self::assertIsArray($service['purposes']);
            foreach ($service['purposes'] as $purpose) {
                self::assertContains(
                    $purpose,
                    self::ALLOWED_PURPOSES,
                    sprintf('Unknown purpose "%s" in service %s', $purpose, $service['id']),
                );
            }
        }
    }

    #[Test]
    public function everyServiceVendorCountryIsTwoLetterCode(): void
    {
        foreach (ServicesLibrary::services() as $service) {
            if (!isset($service['vendorCountry'])) {
                continue;
            }
            self::assertMatchesRegularExpression(
                '/^[A-Z]{2}$/',
                (string) $service['vendorCountry'],
                sprintf('vendorCountry %s in %s should be a 2-letter ISO code', $service['vendorCountry'], $service['id']),
            );
        }
    }

    #[Test]
    public function everyServiceHasAtLeastOneMatcher(): void
    {
        foreach (ServicesLibrary::services() as $service) {
            $cookies = $service['matches']['cookies'] ?? [];
            $origins = $service['matches']['origins'] ?? [];
            self::assertNotEmpty(
                array_merge((array) $cookies, (array) $origins),
                sprintf('%s has neither cookies nor origins — it would never match', $service['id']),
            );
        }
    }

    #[Test]
    public function serviceIdsAreUnique(): void
    {
        $seen = [];
        foreach (ServicesLibrary::services() as $service) {
            $id = (string) $service['id'];
            self::assertNotContains($id, $seen, sprintf('Duplicate service id: %s', $id));
            $seen[] = $id;
        }
    }

    #[Test]
    public function privacyPolicyUrlsAreHttps(): void
    {
        foreach (ServicesLibrary::services() as $service) {
            if (!isset($service['privacyPolicyUrl'])) {
                continue;
            }
            self::assertStringStartsWith(
                'https://',
                (string) $service['privacyPolicyUrl'],
                sprintf('privacyPolicyUrl in %s should use HTTPS', $service['id']),
            );
        }
    }

    // Provider-disclosure fields (DSGVO Art. 13 L2 modal) — REQ-19.
    // All four are optional; tests only fire when the field is present.
    // Curators add them on the top ~25 high-value services; long-tail
    // entries stay with `vendor` + `vendorCountry` and the renderer
    // degrades gracefully.
    //
    // Sentinel: each test counts how many entries have the field. The
    // trailing assertGreaterThanOrEqual(0) keeps PHPUnit from flagging
    // tests as Risky when the library has zero entries with the field
    // yet. Once curation lands ≥1 entry per field, tighten to
    // assertGreaterThan(0) to lock the coverage (mirrors the existing
    // aliasOriginsFieldShapeIsValid pattern).

    #[Test]
    public function vendorAddressIsNonEmptyStringWhenPresent(): void
    {
        $checked = 0;
        foreach (ServicesLibrary::services() as $service) {
            if (!array_key_exists('vendorAddress', $service)) {
                continue;
            }
            $checked++;
            self::assertIsString(
                $service['vendorAddress'],
                sprintf('vendorAddress in %s should be a string', $service['id']),
            );
            self::assertNotSame(
                '',
                trim((string) $service['vendorAddress']),
                sprintf('vendorAddress in %s should not be empty', $service['id']),
            );
        }
        self::assertGreaterThan(0, $checked);
    }

    #[Test]
    public function vendorOptOutUrlIsHttpsWhenPresent(): void
    {
        $checked = 0;
        foreach (ServicesLibrary::services() as $service) {
            if (!isset($service['vendorOptOutUrl'])) {
                continue;
            }
            $checked++;
            self::assertStringStartsWith(
                'https://',
                (string) $service['vendorOptOutUrl'],
                sprintf('vendorOptOutUrl in %s should use HTTPS', $service['id']),
            );
        }
        self::assertGreaterThan(0, $checked);
    }

    #[Test]
    public function vendorPartnerIsNonEmptyStringWhenPresent(): void
    {
        $checked = 0;
        foreach (ServicesLibrary::services() as $service) {
            if (!array_key_exists('vendorPartner', $service)) {
                continue;
            }
            $checked++;
            self::assertIsString(
                $service['vendorPartner'],
                sprintf('vendorPartner in %s should be a string', $service['id']),
            );
            self::assertNotSame(
                '',
                trim((string) $service['vendorPartner']),
                sprintf('vendorPartner in %s should not be empty', $service['id']),
            );
        }
        self::assertGreaterThan(0, $checked);
    }

    #[Test]
    public function vendorDescriptionIsNonEmptyStringWhenPresent(): void
    {
        $checked = 0;
        foreach (ServicesLibrary::services() as $service) {
            if (!array_key_exists('vendorDescription', $service)) {
                continue;
            }
            $checked++;
            self::assertIsString(
                $service['vendorDescription'],
                sprintf('vendorDescription in %s should be a string', $service['id']),
            );
            self::assertNotSame(
                '',
                trim((string) $service['vendorDescription']),
                sprintf('vendorDescription in %s should not be empty', $service['id']),
            );
        }
        self::assertGreaterThan(0, $checked);
    }

    #[Test]
    public function aliasOriginsAreFlattenedIntoOriginsAtLoadTime(): void
    {
        // The on-disk separation is for curation/audit only — consumers
        // see one flat origins list. This test reads any service in the
        // library that has `aliasOrigins` and verifies the flattening
        // contract.
        $files = glob(ServicesLibrary::dataPath() . '/*.json') ?: [];
        $sampled = 0;
        foreach ($files as $file) {
            $raw = json_decode((string) file_get_contents($file), true);
            $aliases = $raw['matches']['aliasOrigins'] ?? null;
            if (!is_array($aliases) || $aliases === []) {
                continue;
            }
            $sampled++;
            // Look the same service up via the iterator.
            $loaded = null;
            foreach (ServicesLibrary::services() as $candidate) {
                if (($candidate['id'] ?? null) === ($raw['id'] ?? null)) {
                    $loaded = $candidate;
                    break;
                }
            }
            self::assertNotNull($loaded, sprintf('Iterator did not return %s', $raw['id']));
            self::assertArrayNotHasKey(
                'aliasOrigins',
                $loaded['matches'],
                sprintf('aliasOrigins should be flattened away in %s', $raw['id']),
            );
            $origins = (array) ($loaded['matches']['origins'] ?? []);
            foreach ($aliases as $alias) {
                self::assertContains(
                    $alias,
                    $origins,
                    sprintf('Alias %s should appear in flattened origins of %s', is_string($alias) ? $alias : '<non-string>', $raw['id']),
                );
            }
            $rawOrigins = (array) ($raw['matches']['origins'] ?? []);
            foreach ($rawOrigins as $canonical) {
                self::assertContains(
                    $canonical,
                    $origins,
                    sprintf('Canonical %s should survive flattening in %s', is_string($canonical) ? $canonical : '<non-string>', $raw['id']),
                );
            }
        }
        // The library has no aliasOrigins yet (Step 4 adds them).
        // This test still locks the contract for when it does.
        if ($sampled === 0) {
            self::assertTrue(true, 'No aliasOrigins fields in the library yet — contract reserved for Step 4 backfill');
        }
    }

    #[Test]
    public function aliasOriginsFieldShapeIsValid(): void
    {
        // Schema check: when present, aliasOrigins must be a list of
        // strings (same shape as origins). Catches malformed data before
        // it confuses the loader.
        $files = glob(ServicesLibrary::dataPath() . '/*.json') ?: [];
        $checked = 0;
        foreach ($files as $file) {
            $raw = json_decode((string) file_get_contents($file), true);
            $aliases = $raw['matches']['aliasOrigins'] ?? null;
            if ($aliases === null) {
                continue;
            }
            self::assertIsArray($aliases, sprintf('aliasOrigins in %s should be an array', basename($file)));
            foreach ($aliases as $entry) {
                self::assertIsString($entry, sprintf('aliasOrigins entry in %s should be a string', basename($file)));
                self::assertNotEmpty($entry, sprintf('aliasOrigins entry in %s should be non-empty', basename($file)));
            }
            $checked++;
        }
        // At least one service must carry aliasOrigins — without this
        // assertion the test would silently no-op if the field were
        // ever stripped en masse. The Meta + YouTube + TikTok + MS
        // backfill (commit 02b8df5) ensures we have ≥5 today.
        self::assertGreaterThan(
            0,
            $checked,
            'Expected at least one service to carry matches.aliasOrigins. '
            . 'If a recent change stripped every entry, this is a regression — '
            . 'see CHANGELOG entry for the Schema-A multi-TLD work.',
        );
    }

    #[Test]
    public function shortLiteralCookiesAreHostQualified(): void
    {
        // ADR-0010 compliance gate. Generic short cookie names like
        // `_ga`, `did`, `t`, `xbc` etc. WILL false-match on unrelated
        // sites that happen to use the same name. The host-qualified
        // matcher form `{name, requireOrigin}` guards against this by
        // requiring the recorder to have observed the vendor's host
        // in the session.
        //
        // Rule: any literal cookie name (string, not regex, not
        // object) of length ≤ SHORT_COOKIE_THRESHOLD MUST be in the
        // object form OR pass a documented exception.
        //
        // Exception list: first-party-context cookies whose host
        // varies per integrator deployment (e.g. `_ga` set on the
        // customer's own domain by GA's JS). Host qualification
        // doesn't structurally apply — the cookie is observed on
        // the same origin as the host page. These are accepted as
        // a known false-positive risk on unrelated sites using the
        // same cookie name. The risk is low because the names
        // (e.g. `_ga`, `gcl`) are distinctive enough that real-world
        // collisions are rare.
        $exceptions = [
            'adobe-analytics' => ['fid'],
            'adobe-audience-manager' => ['dst', '_dp', 'dpm'],
            'google-analytics' => ['_ga'],
            'google' => ['gcl', 'gac'],
            'magento' => ['stf'],
            'snowplow' => ['sp'],
        ];
        $threshold = 3;
        $violations = [];
        foreach (ServicesLibrary::services() as $service) {
            $id = (string) $service['id'];
            $cookies = $service['matches']['cookies'] ?? [];
            if (!is_array($cookies)) {
                continue;
            }
            foreach ($cookies as $c) {
                if (!is_string($c)) {
                    continue; // object-form is already host-qualified
                }
                if (str_starts_with($c, '/') && str_ends_with($c, '/')) {
                    continue; // slash-bounded regex
                }
                if (strlen($c) > $threshold) {
                    continue;
                }
                if (in_array($c, $exceptions[$id] ?? [], true)) {
                    continue;
                }
                $violations[] = sprintf('%s: %s', $id, $c);
            }
        }
        self::assertSame(
            [],
            $violations,
            "Short literal cookies (≤{$threshold} chars) must use the "
            . '{name, requireOrigin} object form (ADR-0010) OR be added '
            . 'to the documented first-party-context exception list in '
            . 'this test. Violations:' . "\n  " . implode("\n  ", $violations),
        );
    }

    #[Test]
    public function originMatchersAreWellFormedHosts(): void
    {
        // Every entry in matches.origins / matches.aliasOrigins must be a
        // host matcher: a slash-bounded regex (/.../) OR a well-formed host
        // (`*.`-wildcard allowed). Catches the corruption class fixed in the
        // 2026-05-30 audit — a path in an origin (cdnjs.../rollbar.js), a
        // stray token (`ut`), or a missing-TLD host (`id5-sync`) — none of
        // which can ever match a real host.
        $files = glob(ServicesLibrary::dataPath() . '/*.json') ?: [];
        $violations = [];
        foreach ($files as $file) {
            $raw = json_decode((string) file_get_contents($file), true);
            if (!is_array($raw)) {
                continue;
            }
            $origins = array_merge(
                (array) ($raw['matches']['origins'] ?? []),
                (array) ($raw['matches']['aliasOrigins'] ?? []),
            );
            foreach ($origins as $origin) {
                if (!is_string($origin)) {
                    $violations[] = sprintf('%s: <non-string origin>', basename($file));
                    continue;
                }
                if (self::isRegexMatcher($origin)) {
                    continue; // slash-bounded regex source
                }
                if (!self::isWellFormedHost($origin)) {
                    $violations[] = sprintf('%s: "%s"', basename($file), $origin);
                }
            }
        }
        self::assertSame(
            [],
            $violations,
            'matches.origins / aliasOrigins entries must be a slash-regex or a '
            . "well-formed host (optionally `*.`-prefixed, with a TLD, no path / "
            . "port / whitespace). Violations:\n  " . implode("\n  ", $violations),
        );
    }

    #[Test]
    public function cookieMatchersAreWellFormed(): void
    {
        // A string cookie that is slash-bounded must be a compilable regex;
        // an object cookie must carry a string `name` plus a `requireOrigin`
        // that is itself a well-formed host. Guards against a malformed
        // object cookie or a missing-TLD requireOrigin (e.g. the id5 `gpp`
        // `id5-sync` fixed in the 2026-05-30 audit) slipping back in.
        $files = glob(ServicesLibrary::dataPath() . '/*.json') ?: [];
        $violations = [];
        foreach ($files as $file) {
            $raw = json_decode((string) file_get_contents($file), true);
            $cookies = is_array($raw) ? ($raw['matches']['cookies'] ?? []) : [];
            if (!is_array($cookies)) {
                continue;
            }
            foreach ($cookies as $cookie) {
                if (is_string($cookie)) {
                    if (self::isRegexMatcher($cookie) && @preg_match($cookie, '') === false) {
                        $violations[] = sprintf('%s: uncompilable cookie regex %s', basename($file), $cookie);
                    }
                    continue;
                }
                if (!is_array($cookie)) {
                    $violations[] = sprintf('%s: cookie must be a string or {name, requireOrigin} object', basename($file));
                    continue;
                }
                if (!isset($cookie['name']) || !is_string($cookie['name'])) {
                    $violations[] = sprintf('%s: object cookie missing string `name`', basename($file));
                }
                $requireOrigin = $cookie['requireOrigin'] ?? null;
                if ($requireOrigin !== null && (!is_string($requireOrigin) || !self::isWellFormedHost($requireOrigin))) {
                    $violations[] = sprintf(
                        '%s: object cookie `%s` has malformed requireOrigin %s',
                        basename($file),
                        is_string($cookie['name'] ?? null) ? $cookie['name'] : '?',
                        is_string($requireOrigin) ? '"' . $requireOrigin . '"' : '<non-string>',
                    );
                }
            }
        }
        self::assertSame(
            [],
            $violations,
            'Cookie matchers must be a literal name, a compilable slash-regex, or a '
            . '{name, requireOrigin} object whose requireOrigin is a well-formed host. '
            . "Violations:\n  " . implode("\n  ", $violations),
        );
    }

    /**
     * Slash-bounded regex source per the Service-DB protocol, e.g. `/^_ga/`.
     */
    private static function isRegexMatcher(string $value): bool
    {
        return strlen($value) >= 2 && str_starts_with($value, '/') && str_ends_with($value, '/');
    }

    /**
     * A host matcher: optional `*.` wildcard prefix, then dot-separated
     * labels and a 2+ letter TLD. Rejects paths, ports, schemes, whitespace,
     * embedded credentials, and missing-TLD tokens.
     */
    private static function isWellFormedHost(string $host): bool
    {
        return preg_match('/^(\*\.)?([a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $host) === 1;
    }

    /**
     * @param iterable<int, mixed> $iterable
     * @return \Generator<int, mixed>
     */
    private static function iterableToGenerator(iterable $iterable): \Generator
    {
        foreach ($iterable as $k => $v) {
            yield $k => $v;
        }
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '-' . uniqid('', true);
        mkdir($dir);
        return $dir;
    }

    private function removeTempDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($dir);
    }
}
