<?php

declare(strict_types=1);

namespace SimpleCMP\ServicesLibrary\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Locks the property that the OCD importer preserves hand-curated
 * `aliasOrigins` across re-imports.
 *
 * The importer routes:
 *   - new platforms → `data/services/_imported/<id>.json`
 *   - collisions    → `data/services/_imported/<id>.union.json`
 *                     (sidecar diff; the hand-curated file is
 *                     untouched)
 *
 * As long as `data/services/<id>.json` is never written by the
 * import script, anything the curator adds to it — including
 * `matches.aliasOrigins` — survives any number of OCD refresh
 * passes. This test asserts that the import script's source code
 * has no `file_put_contents` / `fwrite` calls that write to
 * `$servicesDir/*.json` directly (only to `$importedDir/*.json`).
 *
 * If the safety guarantee is ever loosened intentionally, this
 * test fails — forcing the change to also update the docstring
 * + revisit the audit / aliasOrigins curation story.
 */
final class OcdImportSafetyTest extends TestCase
{
    #[Test]
    public function importerNeverWritesToHandCuratedServicesDir(): void
    {
        $path = dirname(__DIR__) . '/bin/import-ocd.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        // Every file_put_contents in the importer must point at
        // `$importedDir` (the `_imported/` sidecar), never at
        // `$servicesDir` directly.
        preg_match_all('/file_put_contents\(\s*([^,]+)/', $source, $matches);
        self::assertNotEmpty($matches[1], 'Importer should write at least one file');
        foreach ($matches[1] as $target) {
            $target = trim($target);
            self::assertStringContainsString(
                '$importedDir',
                $target,
                sprintf(
                    'OCD importer file_put_contents() target should reference $importedDir, got: %s — '
                    . 'writing to $servicesDir directly would clobber hand-curated aliasOrigins. '
                    . 'See bin/import-ocd.php docstring "Reproducibility under hand-curated aliasOrigins".',
                    $target
                )
            );
        }
    }
}
