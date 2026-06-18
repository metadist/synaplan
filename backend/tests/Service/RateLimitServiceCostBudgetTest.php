<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TopupRepository;
use App\Service\BillingService;
use App\Service\CostCalculationService;
use App\Service\RateLimitService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the markup + top-up logic in {@see RateLimitService::checkCostBudget()}:
 *  - users are billed provider cost + markup (default 10%),
 *  - period top-ups raise the budget,
 *  - the markup can push a user over budget that raw cost alone would not.
 */
class RateLimitServiceCostBudgetTest extends TestCase
{
    private function makeUser(string $level = 'PRO'): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getRateLimitLevel')->willReturn($level);
        $user->method('getSubscriptionData')->willReturn([]); // calendar-month period

        return $user;
    }

    private function makeService(
        ?string $markupValue,
        float $tierBudget,
        float $rawCost,
        float $topups,
    ): RateLimitService {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getValue')->willReturnCallback(
            fn (int $owner, string $group, string $setting): ?string => RateLimitService::MARKUP_CONFIG_SETTING === $setting ? $markupValue : null
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn((string) $rawCost);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $subscription = (new Subscription())
            ->setName('Pro')
            ->setLevel('PRO')
            ->setPriceMonthly('19.95')
            ->setPriceYearly('199.00')
            ->setDescription('')
            ->setCostBudgetMonthly(number_format($tierBudget, 2, '.', ''));

        $subscriptionRepository = $this->createMock(SubscriptionRepository::class);
        $subscriptionRepository->method('findOneBy')->willReturn($tierBudget > 0 ? $subscription : null);

        $topupRepository = $this->createMock(TopupRepository::class);
        $topupRepository->method('sumForUserInPeriod')->willReturn($topups);

        return new RateLimitService(
            $config,
            $em,
            new NullLogger(),
            new BillingService('sk_test_valid_key', 'price_1RealProId'),
            $this->createMock(CostCalculationService::class),
            $subscriptionRepository,
            $topupRepository,
        );
    }

    public function testDefaultMarkupIsAppliedToChargedCost(): void
    {
        // raw 5.00 × 1.10 = 5.50 charged; budget 10 → allowed.
        $service = $this->makeService(null, 10.0, 5.0, 0.0);
        $result = $service->checkCostBudget($this->makeUser());

        $this->assertSame('5.50', $result['used_cost']);
        $this->assertSame('5.00', $result['raw_cost']);
        $this->assertSame(10.0, $result['markup_percent']);
        $this->assertSame('10.00', $result['budget']);
        $this->assertTrue($result['allowed']);
    }

    public function testMarkupCanPushUserOverBudget(): void
    {
        // raw 9.50 is under the 10.00 budget, but 9.50 × 1.10 = 10.45 is over.
        $service = $this->makeService(null, 10.0, 9.5, 0.0);
        $result = $service->checkCostBudget($this->makeUser());

        $this->assertSame('10.45', $result['used_cost']);
        $this->assertFalse($result['allowed']);
    }

    public function testTopupsRaiseTheBudget(): void
    {
        // base 10 + 100 top-up = 110 budget; raw 50 × 1.10 = 55 charged → allowed.
        $service = $this->makeService(null, 10.0, 50.0, 100.0);
        $result = $service->checkCostBudget($this->makeUser());

        $this->assertSame('110.00', $result['budget']);
        $this->assertSame('100.00', $result['topups']);
        $this->assertSame('55.00', $result['used_cost']);
        $this->assertSame('55.00', $result['remaining']);
        $this->assertTrue($result['allowed']);
    }

    public function testCustomMarkupPercentFromConfig(): void
    {
        // 20% markup → raw 5.00 × 1.20 = 6.00 charged.
        $service = $this->makeService('20', 10.0, 5.0, 0.0);
        $result = $service->checkCostBudget($this->makeUser());

        $this->assertSame(20.0, $result['markup_percent']);
        $this->assertSame('6.00', $result['used_cost']);
    }

    public function testNoTierBudgetAndNoTopupsIsUnlimited(): void
    {
        $service = $this->makeService(null, 0.0, 5.0, 0.0);
        $result = $service->checkCostBudget($this->makeUser('NEW'));

        $this->assertTrue($result['allowed']);
        $this->assertSame('0.00', $result['budget']);
        // Markup still reflected in the reported charged cost.
        $this->assertSame('5.50', $result['used_cost']);
    }

    public function testTopupOnlyBudgetForFreeTier(): void
    {
        // No tier budget, but a 100 top-up creates a 110-effective budget.
        $service = $this->makeService(null, 0.0, 200.0, 100.0);
        $result = $service->checkCostBudget($this->makeUser('NEW'));

        $this->assertSame('100.00', $result['budget']);
        // raw 200 × 1.10 = 220 charged > 110 → blocked.
        $this->assertFalse($result['allowed']);
    }
}
