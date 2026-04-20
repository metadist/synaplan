<?php

declare(strict_types=1);

namespace App\Seed;

use Doctrine\DBAL\Connection;

/**
 * Shared insert-if-missing helper for the BCONFIG table.
 *
 * The BCONFIG table currently lacks a UNIQUE(BOWNERID, BGROUP, BSETTING) constraint
 * (would require a data-cleanup migration to dedupe historical rows first). Until that
 * ships, seeders use SELECT-then-INSERT to stay idempotent without overwriting
 * operator-tuned values.
 */
final class BConfigSeeder
{
    /**
     * @param list<array{ownerId: int, group: string, setting: string, value: string}> $rows
     */
    public static function insertIfMissing(Connection $connection, string $label, array $rows): SeedResult
    {
        $inserted = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $existing = $connection->fetchOne(
                'SELECT BID FROM BCONFIG WHERE BOWNERID = ? AND BGROUP = ? AND BSETTING = ? LIMIT 1',
                [$row['ownerId'], $row['group'], $row['setting']]
            );

            if (false !== $existing) {
                ++$skipped;
                continue;
            }

            $connection->executeStatement(
                'INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES (?, ?, ?, ?)',
                [$row['ownerId'], $row['group'], $row['setting'], $row['value']]
            );
            ++$inserted;
        }

        return new SeedResult($label, inserted: $inserted, skipped: $skipped);
    }
}
