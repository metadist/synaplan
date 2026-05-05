<?php

declare(strict_types=1);

namespace App\Seed;

use Doctrine\DBAL\Connection;

/**
 * Shared insert-if-missing helper for the BCONFIG table.
 *
 * Relies on the UNIQUE(BOWNERID, BGROUP, BSETTING) index added in
 * Version20260420000000. With that constraint in place, `INSERT IGNORE` is
 * atomic and race-safe under MariaDB InnoDB: concurrent container starts (or
 * parallel calls) cannot produce duplicate rows even without an outer lock.
 *
 * Operator overrides are NEVER touched — this seeder only fills in missing rows.
 */
final class BConfigSeeder
{
    /**
     * Static-only utility — instantiation is meaningless.
     */
    private function __construct()
    {
    }

    /**
     * @param list<array{ownerId: int, group: string, setting: string, value: string}> $rows
     */
    public static function insertIfMissing(Connection $connection, string $label, array $rows): SeedResult
    {
        $inserted = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $affected = $connection->executeStatement(
                'INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES (?, ?, ?, ?)',
                [$row['ownerId'], $row['group'], $row['setting'], $row['value']]
            );

            if ($affected > 0) {
                ++$inserted;
            } else {
                ++$skipped;
            }
        }

        return new SeedResult($label, inserted: $inserted, skipped: $skipped);
    }
}
