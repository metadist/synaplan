<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\CostResult;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TopupRepository;
use App\Service\Billing\BillingService;
use App\Service\RateLimitService;
use App\Service\Usage\CostCalculationService;
use App\Service\Usage\UsageRecorded;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * {@see RateLimitService::recordUsage()} announces every written BUSELOG row
 * with {@see UsageRecorded}: raw cost, stored metadata only, and a listener
 * that throws never undoes the row or reaches the caller.
 */
final class RateLimitServiceUsageRecordedTest extends TestCase
{
    private function service(EventDispatcher $dispatcher, int &$inserts): RateLimitService
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(static function () use (&$inserts): int {
            ++$inserts;

            return 1;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $cost = $this->createStub(CostCalculationService::class);
        $cost->method('getPricingMode')->willReturn('per_token');
        $cost->method('calculateCost')->willReturn(new CostResult(
            totalCost: '0.004200',
            inputCost: '0.004000',
            outputCost: '0.000200',
            cacheSavings: '0.000000',
            priceSnapshot: [],
            billedInputTokens: 100,
        ));

        return new RateLimitService(
            $this->createStub(ConfigRepository::class),
            $em,
            new NullLogger(),
            new BillingService('sk_test_valid_key', 'price_1RealProId'),
            $cost,
            $this->createStub(SubscriptionRepository::class),
            $this->createStub(TopupRepository::class),
            null,
            $dispatcher,
        );
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(9);

        return $user;
    }

    public function testEventCarriesRawCostAndOnlyStoredMetadata(): void
    {
        $dispatcher = new EventDispatcher();
        $seen = [];
        $dispatcher->addListener(UsageRecorded::class, static function (UsageRecorded $event) use (&$seen): void {
            $seen[] = $event;
        });
        $inserts = 0;
        $user = $this->user();

        $this->service($dispatcher, $inserts)->recordUsage($user, 'MESSAGES', [
            'provider' => 'test',
            'model' => 'm',
            'model_id' => 5,
            'source' => 'OPENAI_API',
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10],
            'input_text' => 'secret prompt',
            'response_text' => 'secret answer',
        ]);

        self::assertSame(1, $inserts);
        self::assertCount(1, $seen);
        self::assertSame($user, $seen[0]->user);
        self::assertSame('MESSAGES', $seen[0]->action);
        self::assertEqualsWithDelta(0.0042, $seen[0]->cost, 1e-9);
        self::assertSame(['provider' => 'test', 'model' => 'm', 'source' => 'OPENAI_API'], $seen[0]->metadata);
    }

    public function testThrowingListenerIsSwallowed(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(UsageRecorded::class, static function (): void {
            throw new \RuntimeException('listener broke');
        });
        $inserts = 0;

        $recorded = $this->service($dispatcher, $inserts)->recordUsage($this->user(), 'MESSAGES', [
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10],
        ]);

        self::assertSame(1, $inserts);
        self::assertSame('0.004200', $recorded->rawCost);
    }
}
