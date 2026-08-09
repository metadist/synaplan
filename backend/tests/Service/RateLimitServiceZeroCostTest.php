<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\CostResult;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TopupRepository;
use App\Service\BillingService;
use App\Service\CostCalculationService;
use App\Service\RateLimitService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the `zero_cost` metering flag in {@see RateLimitService::recordUsage()}:
 * BYO-key gateway calls record their token counts for statistics, but the
 * stored BCOST must stay 0 so the Synaplan budget is never charged.
 */
class RateLimitServiceZeroCostTest extends TestCase
{
    private Connection&MockObject $connection;
    private CostCalculationService&MockObject $costCalculationService;
    private RateLimitService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->connection);

        $this->costCalculationService = $this->createMock(CostCalculationService::class);
        $this->costCalculationService->method('getPricingMode')->willReturn('per_token');

        $this->service = new RateLimitService(
            $this->createMock(ConfigRepository::class),
            $em,
            new NullLogger(),
            new BillingService('sk_test_valid_key', 'price_1RealProId'),
            $this->costCalculationService,
            $this->createMock(SubscriptionRepository::class),
            $this->createMock(TopupRepository::class),
        );
    }

    private function makeUser(): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        return $user;
    }

    public function testZeroCostFlagRecordsTokensButNoCost(): void
    {
        $this->costCalculationService->expects($this->never())->method('calculateCost');

        $captured = null;
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('INSERT INTO BUSELOG'),
                $this->callback(function (array $params) use (&$captured): bool {
                    $captured = $params;

                    return true;
                }),
            );

        $result = $this->service->recordUsage($this->makeUser(), 'API_CHAT', [
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'model_id' => 42,
            'source' => 'MESSAGES_API',
            'key_source' => 'user',
            'zero_cost' => true,
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
            ],
        ]);

        self::assertNotNull($captured);
        self::assertSame('0.000000', $captured['cost']);
        self::assertSame(150, $captured['tokens']);
        self::assertSame(100, $captured['prompt_tokens']);
        self::assertSame(50, $captured['completion_tokens']);
        self::assertSame(42, $captured['model_id']);

        // The key source stays in BMETADATA so statistics can show it.
        $metadata = json_decode((string) $captured['metadata'], true);
        self::assertIsArray($metadata);
        self::assertSame('user', $metadata['key_source']);

        self::assertSame('0.000000', $result->chargedCost);
        self::assertSame('0.000000', $result->rawCost);
        self::assertSame(150, $result->totalTokens);
    }

    public function testWithoutZeroCostFlagTheModelPriceIsCharged(): void
    {
        $this->costCalculationService->expects($this->once())
            ->method('calculateCost')
            ->willReturn(new CostResult(
                totalCost: '0.123456',
                inputCost: '0.100000',
                outputCost: '0.023456',
                cacheSavings: '0.000000',
                priceSnapshot: ['price_in' => '1.0', 'price_out' => '2.0'],
                billedInputTokens: 100,
            ));

        $captured = null;
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('INSERT INTO BUSELOG'),
                $this->callback(function (array $params) use (&$captured): bool {
                    $captured = $params;

                    return true;
                }),
            );

        $this->service->recordUsage($this->makeUser(), 'API_CHAT', [
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'model_id' => 42,
            'source' => 'MESSAGES_API',
            'key_source' => 'operator',
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
            ],
        ]);

        self::assertNotNull($captured);
        self::assertSame('0.123456', $captured['cost']);
    }
}
