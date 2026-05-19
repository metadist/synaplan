<?php

declare(strict_types=1);

namespace App\Tests\Service\Seed;

use App\Seed\SubscriptionPlanSeeder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the BSUBSCRIPTIONS budget seeder (issue #886 sub-task g).
 *
 * The seeder's contract:
 * - Never inserts new plan rows (operator owns plan creation and pricing).
 * - Fills in zero-valued budget columns independently so an operator who
 *   customised only one of the two budget columns keeps their override.
 * - Skips plans that don't exist in the database.
 */
final class SubscriptionPlanSeederTest extends TestCase
{
    public function testFillsZeroBudgetsOnLegacyRows(): void
    {
        $existing = [
            'PRO' => ['BID' => 1, 'BCOST_BUDGET_MONTHLY' => '0.00', 'BCOST_BUDGET_YEARLY' => '0.00'],
            'TEAM' => ['BID' => 2, 'BCOST_BUDGET_MONTHLY' => '0.00', 'BCOST_BUDGET_YEARLY' => '0.00'],
            'BUSINESS' => ['BID' => 3, 'BCOST_BUDGET_MONTHLY' => '0.00', 'BCOST_BUDGET_YEARLY' => '0.00'],
        ];
        /** @var list<array{sql: string, params: array<string, mixed>}> $updates */
        $updates = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use ($existing): array|false {
                return $existing[$params['level']] ?? false;
            }
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$updates): int {
                $updates[] = ['sql' => $sql, 'params' => $params];

                return 1;
            }
        );

        $seeder = new SubscriptionPlanSeeder($connection);
        $result = $seeder->seed();

        self::assertSame(0, $result->inserted);
        self::assertSame(3, $result->updated, 'All three legacy zero-budget plans get updated');
        self::assertCount(3, $updates);
    }

    public function testPreservesOperatorCustomisedBudgetsIndependently(): void
    {
        // PRO: monthly zero, yearly customised → only monthly gets filled.
        // TEAM: both customised → skipped entirely.
        // BUSINESS: doesn't exist → skipped.
        $existing = [
            'PRO' => ['BID' => 1, 'BCOST_BUDGET_MONTHLY' => '0.00', 'BCOST_BUDGET_YEARLY' => '200.00'],
            'TEAM' => ['BID' => 2, 'BCOST_BUDGET_MONTHLY' => '25.00', 'BCOST_BUDGET_YEARLY' => '300.00'],
        ];
        /** @var list<array{sql: string, params: array<string, mixed>}> $updates */
        $updates = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use ($existing): array|false {
                return $existing[$params['level']] ?? false;
            }
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$updates): int {
                $updates[] = ['sql' => $sql, 'params' => $params];

                return 1;
            }
        );

        $seeder = new SubscriptionPlanSeeder($connection);
        $result = $seeder->seed();

        self::assertSame(0, $result->inserted);
        self::assertSame(1, $result->updated, 'Only PRO (with zero monthly) gets updated');
        self::assertSame(2, $result->skipped, 'TEAM (both non-zero) and BUSINESS (missing) skipped');
        self::assertCount(1, $updates);

        // Only monthly budget is updated, yearly is preserved.
        self::assertStringContainsString('BCOST_BUDGET_MONTHLY', $updates[0]['sql']);
        self::assertStringNotContainsString('BCOST_BUDGET_YEARLY', $updates[0]['sql']);
        self::assertSame('10.00', $updates[0]['params']['budget_monthly']);
    }

    public function testSkipsMissingPlans(): void
    {
        // Empty database — no plans exist.
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);
        $connection->expects(self::never())->method('executeStatement');

        $seeder = new SubscriptionPlanSeeder($connection);
        $result = $seeder->seed();

        self::assertSame(0, $result->inserted);
        self::assertSame(0, $result->updated);
        self::assertSame(3, $result->skipped);
    }
}
