<?php

declare(strict_types=1);

namespace App\Seed;

use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for subscription cost budgets in BSUBSCRIPTIONS.
 *
 * Only fills in BCOST_BUDGET_MONTHLY and BCOST_BUDGET_YEARLY for plans
 * that already exist (operator-seeded) but have zero budgets. Does NOT
 * insert new plan rows — plan creation and pricing is operator responsibility.
 *
 * Guard logic preserves operator customisations: each budget column is
 * only written when its current value is <= 0.
 */
final readonly class SubscriptionPlanSeeder
{
    /**
     * @var array<string, array{costBudgetMonthly: string, costBudgetYearly: string}>
     */
    private const BUDGET_DEFAULTS = [
        'PRO' => [
            'costBudgetMonthly' => '10.00',
            'costBudgetYearly' => '120.00',
        ],
        'TEAM' => [
            'costBudgetMonthly' => '30.00',
            'costBudgetYearly' => '360.00',
        ],
        'BUSINESS' => [
            'costBudgetMonthly' => '60.00',
            'costBudgetYearly' => '720.00',
        ],
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function seed(): SeedResult
    {
        $updated = 0;
        $skipped = 0;

        foreach (self::BUDGET_DEFAULTS as $level => $budgets) {
            $existing = $this->connection->fetchAssociative(
                'SELECT BID, BCOST_BUDGET_MONTHLY, BCOST_BUDGET_YEARLY FROM BSUBSCRIPTIONS WHERE BLEVEL = :level LIMIT 1',
                ['level' => $level]
            );

            if (!$existing) {
                ++$skipped;
                continue;
            }

            $sets = [];
            $params = ['level' => $level];

            if ((float) ($existing['BCOST_BUDGET_MONTHLY'] ?? 0) <= 0) {
                $sets[] = 'BCOST_BUDGET_MONTHLY = :budget_monthly';
                $params['budget_monthly'] = $budgets['costBudgetMonthly'];
            }

            if ((float) ($existing['BCOST_BUDGET_YEARLY'] ?? 0) <= 0) {
                $sets[] = 'BCOST_BUDGET_YEARLY = :budget_yearly';
                $params['budget_yearly'] = $budgets['costBudgetYearly'];
            }

            if ([] === $sets) {
                ++$skipped;
                continue;
            }

            $this->connection->executeStatement(
                'UPDATE BSUBSCRIPTIONS SET '.implode(', ', $sets).' WHERE BLEVEL = :level',
                $params
            );
            ++$updated;
        }

        return new SeedResult(
            label: 'subscriptions',
            inserted: 0,
            updated: $updated,
            skipped: $skipped,
            preserved: 0,
        );
    }
}
