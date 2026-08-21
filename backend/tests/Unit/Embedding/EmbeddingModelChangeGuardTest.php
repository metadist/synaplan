<?php

declare(strict_types=1);

namespace App\Tests\Unit\Embedding;

use App\Entity\RevectorizeRun;
use App\Entity\User;
use App\Repository\RevectorizeRunRepository;
use App\Service\BillingService;
use App\Service\Embedding\EmbeddingModelChangeGuard;
use App\Service\Embedding\Exception\CooldownActiveException;
use App\Service\Embedding\Exception\PremiumRequiredException;
use App\Service\PremiumFeatureGate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class EmbeddingModelChangeGuardTest extends TestCase
{
    private RevectorizeRunRepository&MockObject $runRepository;
    private EmbeddingModelChangeGuard $guard;

    protected function setUp(): void
    {
        $this->runRepository = $this->createMock(RevectorizeRunRepository::class);
        $this->guard = new EmbeddingModelChangeGuard($this->runRepository, $this->makeGate(billingEnabled: true));
    }

    public function testFreeUserIsBlocked(): void
    {
        $user = $this->makeUser('NEW');
        $this->runRepository->method('findLatestForScope')->willReturn(null);

        $this->expectException(PremiumRequiredException::class);
        $this->guard->assertCanChange($user);
    }

    public function testAnonymousUserIsBlocked(): void
    {
        $user = $this->makeUser('ANONYMOUS');
        $this->runRepository->method('findLatestForScope')->willReturn(null);

        $this->expectException(PremiumRequiredException::class);
        $this->guard->assertCanChange($user);
    }

    public function testProUserIsAllowed(): void
    {
        $user = $this->makeUser('PRO');
        $this->runRepository->method('findLatestForScope')->willReturn(null);

        $this->guard->assertCanChange($user);
        $this->addToAssertionCount(1); // explicit: no exception thrown
    }

    public function testTeamUserIsAllowed(): void
    {
        $user = $this->makeUser('TEAM');
        $this->runRepository->method('findLatestForScope')->willReturn(null);

        $this->guard->assertCanChange($user);
        $this->addToAssertionCount(1);
    }

    public function testAdminBypassesPremiumAndCooldown(): void
    {
        $user = $this->makeUser('ADMIN');
        $recentRun = $this->makeRun(time() - 60); // 60s ago — well inside cooldown
        $this->runRepository->method('findLatestForScope')->willReturn($recentRun);

        $this->guard->assertCanChange($user);
        $this->addToAssertionCount(1);
    }

    public function testCooldownBlocksRecentRunForPaidUser(): void
    {
        $user = $this->makeUser('PRO');
        $recentRun = $this->makeRun(time() - 60);
        $this->runRepository->method('findLatestForScope')->willReturn($recentRun);

        $this->expectException(CooldownActiveException::class);
        $this->guard->assertCanChange($user);
    }

    public function testCooldownExpiredAllowsRun(): void
    {
        $user = $this->makeUser('PRO');
        $oldRun = $this->makeRun(time() - 7200); // 2h ago — outside cooldown
        $this->runRepository->method('findLatestForScope')->willReturn($oldRun);

        $this->guard->assertCanChange($user);
        $this->addToAssertionCount(1);
    }

    public function testGetStatusReportsRequiresPremiumForFreeUser(): void
    {
        $user = $this->makeUser('NEW');
        $this->runRepository->method('findLatestForScope')->willReturn(null);

        $status = $this->guard->getStatus($user);

        self::assertFalse($status['canChange']);
        self::assertSame('requires_premium', $status['reason']);
        self::assertSame('NEW', $status['currentLevel']);
    }

    public function testGetStatusReportsCooldownForPaidUser(): void
    {
        $user = $this->makeUser('PRO');
        $this->runRepository->method('findLatestForScope')
            ->willReturn($this->makeRun(time() - 60));

        $status = $this->guard->getStatus($user);

        self::assertFalse($status['canChange']);
        self::assertSame('cooldown_active', $status['reason']);
        self::assertGreaterThan(0, $status['cooldownSecondsRemaining']);
    }

    public function testGetStatusReportsCanChangeForPaidUserOutsideCooldown(): void
    {
        $user = $this->makeUser('BUSINESS');
        $this->runRepository->method('findLatestForScope')->willReturn(null);

        $status = $this->guard->getStatus($user);

        self::assertTrue($status['canChange']);
        self::assertNull($status['reason']);
    }

    public function testFreeUserIsAllowedWhenBillingIsDisabled(): void
    {
        $guard = new EmbeddingModelChangeGuard($this->runRepository, $this->makeGate(billingEnabled: false));
        $this->runRepository->method('findLatestForScope')->willReturn(null);

        $guard->assertCanChange($this->makeUser('NEW'));
        $this->addToAssertionCount(1);
    }

    public function testGetStatusAllowsFreeUserWhenBillingIsDisabled(): void
    {
        $guard = new EmbeddingModelChangeGuard($this->runRepository, $this->makeGate(billingEnabled: false));
        $this->runRepository->method('findLatestForScope')->willReturn(null);

        $status = $guard->getStatus($this->makeUser('NEW'));

        self::assertTrue($status['canChange']);
        self::assertNull($status['reason']);
    }

    /**
     * BillingService and PremiumFeatureGate are final, so the open-source mode
     * is expressed the way production does it: through the Stripe config.
     */
    private function makeGate(bool $billingEnabled): PremiumFeatureGate
    {
        return new PremiumFeatureGate(
            $billingEnabled
                ? new BillingService('sk_live_test', 'price_1RealPro')
                : new BillingService('', ''),
        );
    }

    private function makeUser(string $level): User
    {
        $user = new User();
        $user->setUserLevel($level);

        return $user;
    }

    private function makeRun(int $createdAt): RevectorizeRun
    {
        $run = new RevectorizeRun();
        // The constructor sets created=now; for the cooldown logic we
        // need a deterministic value, so we drive Reflection.
        $reflection = new \ReflectionProperty($run, 'created');
        $reflection->setValue($run, $createdAt);

        return $run;
    }
}
