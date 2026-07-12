<?php

declare(strict_types=1);

namespace App\Seed;

use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the BSUBSCRIPTIONS plan catalogue.
 *
 * Two responsibilities:
 *
 * 1. INSERT-IF-NOT-EXISTS the three purchasable plan rows (PRO/TEAM/BUSINESS)
 *    with the reference display prices, so a fresh install has operator-editable
 *    plans (the public plans endpoint reads them). Existing rows are NEVER
 *    touched — pricing stays operator-owned after the first seed.
 * 2. Fill in BCOST_BUDGET_MONTHLY / BCOST_BUDGET_YEARLY for plans that exist
 *    but still have zero budgets. Guard logic preserves operator customisations:
 *    each budget column is only written when its current value is <= 0.
 */
final readonly class SubscriptionPlanSeeder
{
    /**
     * @var array<string, array{
     *     name: string,
     *     priceMonthly: string,
     *     priceYearly: string,
     *     description: string,
     *     costBudgetMonthly: string,
     *     costBudgetYearly: string
     * }>
     */
    private const PLAN_DEFAULTS = [
        'PRO' => [
            'name' => 'Pro',
            'priceMonthly' => '19.95',
            'priceYearly' => '199.50',
            'description' => 'Professional plan for individuals',
            'costBudgetMonthly' => '10.00',
            'costBudgetYearly' => '120.00',
        ],
        'TEAM' => [
            'name' => 'Team',
            'priceMonthly' => '49.95',
            'priceYearly' => '499.50',
            'description' => 'Team plan with collaboration features',
            'costBudgetMonthly' => '30.00',
            'costBudgetYearly' => '360.00',
        ],
        'BUSINESS' => [
            'name' => 'Business',
            'priceMonthly' => '99.95',
            'priceYearly' => '999.50',
            'description' => 'Business plan with unlimited usage',
            'costBudgetMonthly' => '60.00',
            'costBudgetYearly' => '720.00',
        ],
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function seed(): SeedResult
    {
        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach (self::PLAN_DEFAULTS as $level => $defaults) {
            $existing = $this->connection->fetchAssociative(
                'SELECT BID, BCOST_BUDGET_MONTHLY, BCOST_BUDGET_YEARLY FROM BSUBSCRIPTIONS WHERE BLEVEL = :level LIMIT 1',
                ['level' => $level]
            );

            if (!$existing) {
                $this->connection->executeStatement(
                    'INSERT INTO BSUBSCRIPTIONS
                        (BNAME, BLEVEL, BPRICE_MONTHLY, BPRICE_YEARLY, BCURRENCY, BDESCRIPTION, BACTIVE,
                         BCOST_BUDGET_MONTHLY, BCOST_BUDGET_YEARLY)
                     VALUES
                        (:name, :level, :price_monthly, :price_yearly, :currency, :description, 1,
                         :budget_monthly, :budget_yearly)',
                    [
                        'name' => $defaults['name'],
                        'level' => $level,
                        'price_monthly' => $defaults['priceMonthly'],
                        'price_yearly' => $defaults['priceYearly'],
                        'currency' => 'EUR',
                        'description' => $defaults['description'],
                        'budget_monthly' => $defaults['costBudgetMonthly'],
                        'budget_yearly' => $defaults['costBudgetYearly'],
                    ]
                );
                ++$inserted;
                continue;
            }

            $sets = [];
            $params = ['level' => $level];

            if ((float) ($existing['BCOST_BUDGET_MONTHLY'] ?? 0) <= 0) {
                $sets[] = 'BCOST_BUDGET_MONTHLY = :budget_monthly';
                $params['budget_monthly'] = $defaults['costBudgetMonthly'];
            }

            if ((float) ($existing['BCOST_BUDGET_YEARLY'] ?? 0) <= 0) {
                $sets[] = 'BCOST_BUDGET_YEARLY = :budget_yearly';
                $params['budget_yearly'] = $defaults['costBudgetYearly'];
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
            inserted: $inserted,
            updated: $updated,
            skipped: $skipped,
            preserved: 0,
        );
    }
}
