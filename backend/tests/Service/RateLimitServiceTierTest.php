<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TopupRepository;
use App\Service\BillingService;
use App\Service\Config\LayeredConfigResolver;
use App\Service\CostCalculationService;
use App\Service\RateLimitService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RateLimitServiceTierTest extends TestCase
{
    public function testGroupTierOverridesUserLevelForLimitsTable(): void
    {
        /** @var array<string, list<\App\Entity\Config>> $configMap */
        $configMap = [];
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('findBy')->willReturnCallback(
            static function (array $criteria) use (&$configMap): array {
                $group = isset($criteria['group']) && is_string($criteria['group'])
                    ? $criteria['group']
                    : '';

                return array_key_exists($group, $configMap) ? $configMap[$group] : [];
            }
        );
        $configMap['RATELIMITS_NEW'] = [$this->limitRow('MESSAGES_TOTAL', '5')];
        $configMap['RATELIMITS_BUSINESS'] = [$this->limitRow('MESSAGES_HOURLY', '100')];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $resolver = $this->createMock(LayeredConfigResolver::class);
        $resolver->method('resolve')->with(7, 'RATELIMITS', 'TIER')->willReturn('BUSINESS');

        $service = new RateLimitService(
            $configRepository,
            $em,
            $this->createMock(LoggerInterface::class),
            new BillingService('sk_test_valid_key', 'price_1RealProId'),
            $this->createMock(CostCalculationService::class),
            $this->createMock(SubscriptionRepository::class),
            $this->createMock(TopupRepository::class),
            $resolver,
        );

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getRateLimitLevel')->willReturn('NEW');

        $result = $service->checkLimit($user, 'MESSAGES');

        self::assertTrue($result['allowed']);
        self::assertSame(100, $result['limit']);
        self::assertNotSame('lifetime', $result['limit_type']);
    }

    private function limitRow(string $setting, string $value): \App\Entity\Config
    {
        $config = $this->createMock(\App\Entity\Config::class);
        $config->method('getSetting')->willReturn($setting);
        $config->method('getValue')->willReturn($value);

        return $config;
    }
}
