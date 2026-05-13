<?php

declare(strict_types=1);

namespace App\Tests\Service\Seed;

use App\Seed\SubscriptionPlanSeeder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the BSUBSCRIPTIONS plan seeder (issue #886 sub-task g).
 *
 * Locks down the operator-vs-catalog ownership contract: the seeder
 * inserts plans that don't exist, fills in zero-valued cost budgets on
 * legacy installs, and refuses to clobber an operator-customised price
 * or budget that has already been set above 0.
 */
final class SubscriptionPlanSeederTest extends TestCase
{
    public function testInsertsAllThreePlansOnFreshInstall(): void
    {
        /** @var array<string, array{BID: int}> $existing */
        $existing = [];
        /** @var list<array<string, mixed>> $inserts */
        $inserts = [];
        /** @var list<array<string, mixed>> $updates */
        $updates = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$existing): false {
                // No existing rows on a fresh install: every lookup returns false.
                unset($params, $sql, $existing);

                return false;
            }
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$inserts, &$updates, &$existing): int {
                if (str_contains($sql, 'INSERT INTO BSUBSCRIPTIONS')) {
                    $inserts[] = $params;
                    $existing[$params['level']] = ['BID' => count($inserts)];

                    return 1;
                }
                if (str_contains($sql, 'UPDATE BSUBSCRIPTIONS')) {
                    $updates[] = $params;

                    return 1;
                }

                return 0;
            }
        );

        $seeder = new SubscriptionPlanSeeder($connection);
        $result = $seeder->seed();

        self::assertSame(3, $result->inserted, 'PRO/TEAM/BUSINESS each get one row');
        self::assertSame(0, $result->updated);
        self::assertCount(3, $inserts);

        $byLevel = [];
        foreach ($inserts as $row) {
            $byLevel[$row['level']] = $row;
        }
        self::assertSame('10.00', $byLevel['PRO']['budget_monthly']);
        self::assertSame('30.00', $byLevel['TEAM']['budget_monthly']);
        self::assertSame('60.00', $byLevel['BUSINESS']['budget_monthly']);
    }

    public function testFillsZeroBudgetsOnLegacyRowsAndPreservesOperatorEdits(): void
    {
        // Pretend the table already has all three rows. PRO has a zero
        // budget (legacy default) and SHOULD be filled in. TEAM has been
        // edited to 25.00 by the operator and MUST be left alone. BUSINESS
        // was already at 60.00 from a previous seed run — also untouched.
        /** @var array<string, array{BID: int}> $existing */
        $existing = [
            'PRO' => ['BID' => 1],
            'TEAM' => ['BID' => 2],
            'BUSINESS' => ['BID' => 3],
        ];
        /** @var list<array<string, mixed>> $updates */
        $updates = [];

        // Track per-level current budget so the WHERE BCOST_BUDGET_MONTHLY <= 0
        // affected-rows count is realistic.
        $budgets = [
            'PRO' => 0.0,
            'TEAM' => 25.00,
            'BUSINESS' => 60.00,
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use ($existing): array|false {
                return $existing[$params['level']] ?? false;
            }
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$updates, &$budgets): int {
                if (str_contains($sql, 'UPDATE BSUBSCRIPTIONS')) {
                    $level = $params['level'];
                    if ($budgets[$level] > 0) {
                        return 0; // WHERE clause does not match -> no rows
                    }
                    $budgets[$level] = (float) $params['budget_monthly'];
                    $updates[] = $params;

                    return 1;
                }

                return 0;
            }
        );

        $seeder = new SubscriptionPlanSeeder($connection);
        $result = $seeder->seed();

        self::assertSame(0, $result->inserted);
        self::assertSame(1, $result->updated, 'Only the legacy zero-budget row gets updated');
        self::assertCount(1, $updates);
        self::assertSame('PRO', $updates[0]['level']);
        self::assertSame('10.00', $updates[0]['budget_monthly']);

        // Operator-modified TEAM budget is preserved.
        self::assertSame(25.00, $budgets['TEAM']);
    }
}
