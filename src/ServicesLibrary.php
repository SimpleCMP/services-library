<?php

declare(strict_types=1);

namespace SimpleCMP\ServicesLibrary;

/**
 * Helper for consumers of the SimpleCMP services library — exposes the
 * data directory path and an iterator over loaded service records.
 *
 * The data files at `data/services/*.json` follow the upstream
 * SimpleCMP Service-DB protocol shape. See
 * https://github.com/SimpleCMP/simplecmp/blob/main/docs/service-db-protocol.md
 * for the field reference.
 *
 * Consumers (TYPO3, WordPress, Contao plugins) typically iterate
 * `services()` and upsert each record into their own registry, or
 * resolve `dataPath()` to read the files directly.
 */
final class ServicesLibrary
{
    /**
     * Absolute filesystem path to the directory holding the JSON
     * service definitions, without trailing slash.
     */
    public static function dataPath(): string
    {
        return dirname(__DIR__) . '/data/services';
    }

    /**
     * Iterate every bundled service as a decoded array. Files are
     * yielded in filename order for deterministic test output.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public static function services(): iterable
    {
        $files = glob(self::dataPath() . '/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                yield $decoded;
            }
        }
    }
}
