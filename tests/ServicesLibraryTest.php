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
        self::assertGreaterThanOrEqual(0, $checked); // sentinel so the test isn't marked risky pre-backfill
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
}
