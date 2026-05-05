<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\RateLimitService;
use App\Service\UsageStatsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the private `deriveSubscriptionStatus` branching table.
 *
 * We test via reflection because the method is pure-logic (no DB access, no
 * side effects) and mocking all of `UsageStatsService`'s public-path
 * collaborators (EntityManager, ConfigRepository, RateLimitService) just to
 * exercise a few switch statements would be noise.
 */
final class UsageStatsServiceDeriveSubscriptionStatusTest extends TestCase
{
    private UsageStatsService $service;

    protected function setUp(): void
    {
        $this->service = new UsageStatsService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ConfigRepository::class),
            $this->createMock(RateLimitService::class),
            new NullLogger(),
        );
    }

    /**
     * @param array{
     *     userLevel: string,
     *     hasActiveSub: bool,
     *     hasVerifiedPhone?: bool,
     *     paymentDetails?: array<string, mixed>,
     * } $userFixture
     * @param array<string, mixed> $subscriptionData
     */
    #[DataProvider('statusCases')]
    public function testDeriveSubscriptionStatus(
        string $expected,
        array $userFixture,
        array $subscriptionData,
    ): void {
        $user = $this->buildUser($userFixture);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('deriveSubscriptionStatus');
        $method->setAccessible(true);

        $this->assertSame(
            $expected,
            $method->invoke($this->service, $user, $subscriptionData),
        );
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, array<string, mixed>}>
     */
    public static function statusCases(): iterable
    {
        // ANONYMOUS always wins, regardless of subscription data.
        yield 'anonymous' => [
            'anonymous',
            ['userLevel' => 'ANONYMOUS', 'hasActiveSub' => false],
            [],
        ];

        yield 'anonymous even with stray stripe data' => [
            'anonymous',
            ['userLevel' => 'ANONYMOUS', 'hasActiveSub' => false],
            ['status' => 'active', 'subscription_end' => PHP_INT_MAX],
        ];

        // Active subscription (live source of truth) short-circuits everything below.
        yield 'active paid subscription' => [
            'active',
            ['userLevel' => 'PRO', 'hasActiveSub' => true],
            ['status' => 'active', 'subscription_end' => PHP_INT_MAX],
        ];

        // Stripe status mapping — full enumeration of documented values.
        yield 'stripe trialing → active' => [
            'active',
            ['userLevel' => 'PRO', 'hasActiveSub' => false],
            ['status' => 'trialing', 'subscription_end' => PHP_INT_MAX],
        ];

        yield 'stripe active (but local hasActiveSubscription says no) → active' => [
            'active',
            ['userLevel' => 'PRO', 'hasActiveSub' => false],
            ['status' => 'active', 'subscription_end' => time() - 100],
        ];

        yield 'stripe past_due → past_due' => [
            'past_due',
            ['userLevel' => 'PRO', 'hasActiveSub' => false],
            ['status' => 'past_due'],
        ];

        yield 'stripe unpaid → past_due' => [
            'past_due',
            ['userLevel' => 'PRO', 'hasActiveSub' => false],
            ['status' => 'unpaid'],
        ];

        yield 'stripe incomplete → past_due' => [
            'past_due',
            ['userLevel' => 'PRO', 'hasActiveSub' => false],
            ['status' => 'incomplete'],
        ];

        yield 'stripe incomplete_expired → past_due' => [
            'past_due',
            ['userLevel' => 'PRO', 'hasActiveSub' => false],
            ['status' => 'incomplete_expired'],
        ];

        yield 'stripe canceled (US spelling) → cancelled' => [
            'cancelled',
            ['userLevel' => 'PRO', 'hasActiveSub' => false],
            ['status' => 'canceled'],
        ];

        yield 'stripe cancelled (UK spelling) → cancelled' => [
            'cancelled',
            ['userLevel' => 'PRO', 'hasActiveSub' => false],
            ['status' => 'cancelled'],
        ];

        yield 'stripe paused → inactive' => [
            'inactive',
            ['userLevel' => 'PRO', 'hasActiveSub' => false],
            ['status' => 'paused'],
        ];

        yield 'stripe status mixed case (PAST_DUE)' => [
            'past_due',
            ['userLevel' => 'PRO', 'hasActiveSub' => false],
            ['status' => 'PAST_DUE'],
        ];

        // subscription_end as integer (native JSON) in the past.
        yield 'expired subscription_end (int) → past_due' => [
            'past_due',
            ['userLevel' => 'PRO', 'hasActiveSub' => false],
            ['subscription_end' => time() - 86400],
        ];

        // subscription_end as string (Stripe webhook JSON representation).
        yield 'expired subscription_end (string) → past_due' => [
            'past_due',
            ['userLevel' => 'PRO', 'hasActiveSub' => false],
            ['subscription_end' => (string) (time() - 86400)],
        ];

        // subscription_end in the future — falls through to level-based fallback.
        yield 'future subscription_end with NEW level → free' => [
            'free',
            ['userLevel' => 'NEW', 'hasActiveSub' => false],
            ['subscription_end' => PHP_INT_MAX],
        ];

        // subscription_end is 0 or non-numeric — treated as absent.
        yield 'subscription_end = 0 with NEW level → free' => [
            'free',
            ['userLevel' => 'NEW', 'hasActiveSub' => false],
            ['subscription_end' => 0],
        ];

        yield 'subscription_end non-numeric string with NEW level → free' => [
            'free',
            ['userLevel' => 'NEW', 'hasActiveSub' => false],
            ['subscription_end' => 'not-a-timestamp'],
        ];

        // Level-based fallbacks when no Stripe data is relevant.
        yield 'NEW level, empty subscription data → free' => [
            'free',
            ['userLevel' => 'NEW', 'hasActiveSub' => false],
            [],
        ];

        // Admin-override path: BUSERLEVEL set to PRO without a Stripe record.
        // Status must reflect the granted capability, NOT a contradictory "inactive"
        // next to the "Pro Plan" label (regression guard for #839 review feedback).
        yield 'admin-override PRO without Stripe → active' => [
            'active',
            ['userLevel' => 'PRO', 'hasActiveSub' => false],
            [],
        ];

        yield 'admin-override TEAM without Stripe → active' => [
            'active',
            ['userLevel' => 'TEAM', 'hasActiveSub' => false],
            [],
        ];

        yield 'admin-override BUSINESS without Stripe → active' => [
            'active',
            ['userLevel' => 'BUSINESS', 'hasActiveSub' => false],
            [],
        ];

        // Unknown Stripe status falls through to level-based fallback.
        yield 'unknown stripe status + NEW level → free' => [
            'free',
            ['userLevel' => 'NEW', 'hasActiveSub' => false],
            ['status' => 'some_future_stripe_status'],
        ];

        yield 'unknown stripe status + PRO (admin) → active' => [
            'active',
            ['userLevel' => 'PRO', 'hasActiveSub' => false],
            ['status' => 'some_future_stripe_status'],
        ];
    }

    /**
     * Builds a minimal User stub with just the fields `deriveSubscriptionStatus`
     * and its callees (`getRateLimitLevel`, `hasActiveSubscription`) need.
     *
     * @param array{
     *     userLevel: string,
     *     hasActiveSub: bool,
     *     paymentDetails?: array<string, mixed>,
     * } $fixture
     */
    private function buildUser(array $fixture): User
    {
        $user = new User();
        $user->setUserLevel($fixture['userLevel']);

        // hasActiveSubscription() returns true iff paymentDetails.subscription has
        // status='active' AND subscription_end > time(). Synthesise that directly.
        if ($fixture['hasActiveSub']) {
            $user->setPaymentDetails([
                'subscription' => [
                    'status' => 'active',
                    'subscription_end' => PHP_INT_MAX,
                ],
            ]);
        } else {
            $user->setPaymentDetails($fixture['paymentDetails'] ?? []);
        }

        return $user;
    }
}
