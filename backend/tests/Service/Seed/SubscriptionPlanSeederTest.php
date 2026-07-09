<?php

declare(strict_types=1);

namespace App\Tests\Service\Seed;

use App\Seed\SubscriptionPlanSeeder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the BSUBSCRIPTIONS plan seeder.
 *
 * The seeder's contract:
 * - Inserts missing plan rows once (fresh install) with the reference display
 *   prices, so the public plans endpoint has operator-editable data.
 * - Never updates prices of existing rows (operator owns pricing after seed).
 * - Fills in zero-valued budget columns independently so an operator who
 *   customised only one of the two budget columns keeps their override.
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
        $existing = [
            'PRO' => ['BID' => 1, 'BCOST_BUDGET_MONTHLY' => '0.00', 'BCOST_BUDGET_YEARLY' => '200.00'],
            'TEAM' => ['BID' => 2, 'BCOST_BUDGET_MONTHLY' => '25.00', 'BCOST_BUDGET_YEARLY' => '300.00'],
            'BUSINESS' => ['BID' => 3, 'BCOST_BUDGET_MONTHLY' => '5.00', 'BCOST_BUDGET_YEARLY' => '60.00'],
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
        self::assertSame(2, $result->skipped, 'TEAM and BUSINESS (both non-zero) skipped');
        self::assertCount(1, $updates);

        // Only monthly budget is updated, yearly is preserved.
        self::assertStringContainsString('BCOST_BUDGET_MONTHLY', $updates[0]['sql']);
        self::assertStringNotContainsString('BCOST_BUDGET_YEARLY', $updates[0]['sql']);
        self::assertSame('10.00', $updates[0]['params']['budget_monthly']);
    }

    public function testInsertsMissingPlansWithReferencePrices(): void
    {
        // Empty database — no plans exist → all three get inserted once.
        /** @var list<array{sql: string, params: array<string, mixed>}> $statements */
        $statements = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            }
        );

        $seeder = new SubscriptionPlanSeeder($connection);
        $result = $seeder->seed();

        self::assertSame(3, $result->inserted);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->skipped);
        self::assertCount(3, $statements);

        foreach ($statements as $statement) {
            self::assertStringContainsString('INSERT INTO BSUBSCRIPTIONS', $statement['sql']);
            self::assertSame('EUR', $statement['params']['currency']);
        }
        self::assertSame('19.95', $statements[0]['params']['price_monthly']);
        self::assertSame('49.95', $statements[1]['params']['price_monthly']);
        self::assertSame('99.95', $statements[2]['params']['price_monthly']);
    }

    public function testNeverTouchesPricesOfExistingRows(): void
    {
        // Existing rows with customised budgets: nothing at all is written.
        $existing = [
            'PRO' => ['BID' => 1, 'BCOST_BUDGET_MONTHLY' => '11.00', 'BCOST_BUDGET_YEARLY' => '110.00'],
            'TEAM' => ['BID' => 2, 'BCOST_BUDGET_MONTHLY' => '22.00', 'BCOST_BUDGET_YEARLY' => '220.00'],
            'BUSINESS' => ['BID' => 3, 'BCOST_BUDGET_MONTHLY' => '33.00', 'BCOST_BUDGET_YEARLY' => '330.00'],
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use ($existing): array|false {
                return $existing[$params['level']] ?? false;
            }
        );
        $connection->expects(self::never())->method('executeStatement');

        $seeder = new SubscriptionPlanSeeder($connection);
        $result = $seeder->seed();

        self::assertSame(0, $result->inserted);
        self::assertSame(0, $result->updated);
        self::assertSame(3, $result->skipped);
    }
}
