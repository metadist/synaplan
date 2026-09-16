<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\RateLimitService;
use App\Service\UsageStatsService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Issue #1875 / PR #1949: getUserStats() reports the resolved group tier as
 * user_level while subscription.level and cost_budget stay billing-based.
 */
final class UsageStatsServiceGetUserStatsTest extends TestCase
{
    public function testGroupTierIsReportedSeparatelyFromBillingSubscription(): void
    {
        $user = new User();
        $user->setUserLevel('NEW');
        $user->setPaymentDetails([]);
        $id = new \ReflectionProperty(User::class, 'id');
        $id->setValue($user, 7);

        $limits = $this->createStub(RateLimitService::class);
        $limits->method('resolveRateLimitLevel')->willReturn('BUSINESS');
        $limits->method('checkLimit')->willReturn([
            'used' => 1,
            'limit' => 50,
            'remaining' => 49,
            'allowed' => true,
            'reset_at' => null,
            'limit_type' => 'daily',
        ]);
        $limits->method('checkCostBudget')->willReturn([
            'used_cost' => '1.00',
            'budget' => '5.00',
            'remaining' => '4.00',
            'percent' => 20.0,
            'period_start' => 1,
            'period_end' => 2,
        ]);

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $connection->method('fetchAssociative')->willReturn(['total_cost' => 0]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $service = new UsageStatsService(
            $em,
            $this->createStub(ConfigRepository::class),
            $limits,
            new NullLogger(),
        );

        $stats = $service->getUserStats($user);

        self::assertSame('BUSINESS', $stats['user_level']);
        self::assertSame('NEW', $stats['subscription']['level']);
        self::assertSame('Free Plan', $stats['subscription']['plan_name']);
        self::assertSame('free', $stats['subscription']['status']);
        self::assertSame(1.0, $stats['cost_budget']['used']);
        self::assertSame(5.0, $stats['cost_budget']['budget']);
        self::assertSame(4.0, $stats['cost_budget']['remaining']);
        self::assertSame(20.0, $stats['cost_budget']['percent']);
    }
}
