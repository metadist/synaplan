<?php

declare(strict_types=1);

namespace App\Seed;

use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for subscription plans in BSUBSCRIPTIONS.
 *
 * Seeds:
 * - PRO
 * - TEAM
 * - BUSINESS
 *
 * Operator overrides (e.g. price changes, stripe IDs) are preserved
 * via INSERT IGNORE / ON DUPLICATE KEY UPDATE semantics.
 */
final readonly class SubscriptionPlanSeeder
{
    /**
     * @var list<array{level: string, name: string, priceMonthly: string, priceYearly: string, costBudgetMonthly: string, costBudgetYearly: string, description: string}>
     */
    private const DEFAULTS = [
        [
            'level' => 'PRO',
            'name' => 'Pro',
            'priceMonthly' => '19.99',
            'priceYearly' => '199.90',
            'costBudgetMonthly' => '10.00',
            'costBudgetYearly' => '120.00',
            'description' => 'For professionals and power users.',
        ],
        [
            'level' => 'TEAM',
            'name' => 'Team',
            'priceMonthly' => '49.99',
            'priceYearly' => '499.90',
            'costBudgetMonthly' => '30.00',
            'costBudgetYearly' => '360.00',
            'description' => 'For small teams.',
        ],
        [
            'level' => 'BUSINESS',
            'name' => 'Business',
            'priceMonthly' => '99.99',
            'priceYearly' => '999.90',
            'costBudgetMonthly' => '60.00',
            'costBudgetYearly' => '720.00',
            'description' => 'For organizations.',
        ],
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function seed(): SeedResult
    {
        $inserted = 0;
        $updated = 0;

        foreach (self::DEFAULTS as $plan) {
            $existing = $this->connection->fetchAssociative(
                'SELECT BID FROM BSUBSCRIPTIONS WHERE BLEVEL = :level',
                ['level' => $plan['level']]
            );

            if ($existing) {
                // Update cost budgets if they are 0.00 (legacy default)
                // We do not overwrite operator-modified budgets or prices.
                $affected = $this->connection->executeStatement(
                    'UPDATE BSUBSCRIPTIONS 
                        SET BCOST_BUDGET_MONTHLY = :budget_monthly,
                            BCOST_BUDGET_YEARLY = :budget_yearly
                      WHERE BLEVEL = :level 
                        AND BCOST_BUDGET_MONTHLY <= 0',
                    [
                        'level' => $plan['level'],
                        'budget_monthly' => $plan['costBudgetMonthly'],
                        'budget_yearly' => $plan['costBudgetYearly'],
                    ]
                );
                if ($affected > 0) {
                    ++$updated;
                }
            } else {
                $this->connection->executeStatement(
                    'INSERT INTO BSUBSCRIPTIONS (BNAME, BLEVEL, BPRICE_MONTHLY, BPRICE_YEARLY, BCOST_BUDGET_MONTHLY, BCOST_BUDGET_YEARLY, BDESCRIPTION, BACTIVE)
                     VALUES (:name, :level, :price_monthly, :price_yearly, :budget_monthly, :budget_yearly, :description, 1)',
                    [
                        'name' => $plan['name'],
                        'level' => $plan['level'],
                        'price_monthly' => $plan['priceMonthly'],
                        'price_yearly' => $plan['priceYearly'],
                        'budget_monthly' => $plan['costBudgetMonthly'],
                        'budget_yearly' => $plan['costBudgetYearly'],
                        'description' => $plan['description'],
                    ]
                );
                ++$inserted;
            }
        }

        return new SeedResult(
            label: 'subscriptions',
            inserted: $inserted,
            updated: $updated,
            skipped: 0,
            preserved: 0,
        );
    }
}
