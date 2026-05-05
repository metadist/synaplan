<?php

declare(strict_types=1);

namespace App\Service\VectorSearch;

/**
 * Outcome of a single call to {@see LegacyPointIdMigrator::migrateCollection()}.
 */
final readonly class LegacyPointIdMigrationReport
{
    public function __construct(
        public int $scanned,
        public int $legacy,
        public int $migrated,
        public int $errors,
    ) {
    }
}
