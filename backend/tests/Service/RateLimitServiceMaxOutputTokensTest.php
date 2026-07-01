<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TopupRepository;
use App\Service\BillingService;
use App\Service\CostCalculationService;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Guards the runtime contract of {@see RateLimitService::getMaxOutputTokens()}
 * that {@see \App\Service\Message\Handler\ChatHandler} relies on to clamp the
 * provider max_tokens.
 *
 * By design only ANONYMOUS keeps a hard output cap; authenticated tiers
 * (NEW/PRO/TEAM/BUSINESS) must return null so the model's full max_tokens is
 * used. This complements RateLimitConfigSeederTest (which guards the seed data)
 * by locking the behaviour of the method itself — a refactor that re-clamps
 * authenticated tiers would silently truncate AI answers again.
 */
final class RateLimitServiceMaxOutputTokensTest extends TestCase
{
    private function makeUser(string $level): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRateLimitLevel')->willReturn($level);

        return $user;
    }

    /**
     * @param array<string, string|null> $configByGroup MAX_OUTPUT_TOKENS value per RATELIMITS_* group
     */
    private function makeService(bool $billingEnabled, array $configByGroup): RateLimitService
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getValue')->willReturnCallback(
            static function (int $owner, string $group, string $setting) use ($configByGroup): ?string {
                if ('MAX_OUTPUT_TOKENS' !== $setting) {
                    return null;
                }

                return $configByGroup[$group] ?? null;
            }
        );

        $billing = $billingEnabled
            ? new BillingService('sk_test_valid_key', 'price_1RealProId')
            : new BillingService('', '');

        return new RateLimitService(
            $config,
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            $billing,
            $this->createMock(CostCalculationService::class),
            $this->createMock(SubscriptionRepository::class),
            $this->createMock(TopupRepository::class),
        );
    }

    public function testAnonymousKeepsHardCap(): void
    {
        $service = $this->makeService(true, ['RATELIMITS_ANONYMOUS' => '2048']);

        $this->assertSame(2048, $service->getMaxOutputTokens($this->makeUser('ANONYMOUS')));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function authenticatedTierProvider(): array
    {
        return [['NEW'], ['PRO'], ['TEAM'], ['BUSINESS']];
    }

    #[DataProvider('authenticatedTierProvider')]
    public function testAuthenticatedTiersAreUncapped(string $level): void
    {
        // No MAX_OUTPUT_TOKENS row configured for authenticated tiers.
        $service = $this->makeService(true, ['RATELIMITS_ANONYMOUS' => '2048']);

        $this->assertNull(
            $service->getMaxOutputTokens($this->makeUser($level)),
            "Authenticated tier {$level} must be uncapped (full model max_tokens).",
        );
    }

    public function testAdminIsUncapped(): void
    {
        $service = $this->makeService(true, ['RATELIMITS_ADMIN' => '4096']);

        $this->assertNull($service->getMaxOutputTokens($this->makeUser('ADMIN')));
    }

    public function testBillingDisabledIsUncappedEvenForAnonymous(): void
    {
        $service = $this->makeService(false, ['RATELIMITS_ANONYMOUS' => '2048']);

        $this->assertNull($service->getMaxOutputTokens($this->makeUser('ANONYMOUS')));
    }

    public function testNonPositiveCapIsTreatedAsUncapped(): void
    {
        $service = $this->makeService(true, ['RATELIMITS_ANONYMOUS' => '0']);

        $this->assertNull($service->getMaxOutputTokens($this->makeUser('ANONYMOUS')));
    }
}
