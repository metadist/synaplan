<?php

declare(strict_types=1);

namespace App\Seed;

use Doctrine\DBAL\Connection;

/**
 * Shared insert-if-missing helper for the BCONFIG table.
 *
 * The BCONFIG table currently lacks a UNIQUE(BOWNERID, BGROUP, BSETTING) constraint
 * (would require a data-cleanup migration to dedupe historical rows first). Until that
 * ships, we use a single atomic `INSERT … SELECT … WHERE NOT EXISTS` statement so
 * concurrent container starts cannot race and produce duplicate rows.
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
            $affected = $connection->executeStatement(
                'INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
                 SELECT ?, ?, ?, ? FROM DUAL
                 WHERE NOT EXISTS (
                     SELECT 1 FROM BCONFIG
                     WHERE BOWNERID = ? AND BGROUP = ? AND BSETTING = ?
                 )',
                [
                    $row['ownerId'], $row['group'], $row['setting'], $row['value'],
                    $row['ownerId'], $row['group'], $row['setting'],
                ]
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
