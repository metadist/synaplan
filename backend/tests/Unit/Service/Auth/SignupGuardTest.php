<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Repository\ConfigRepository;
use App\Service\Auth\SignupAnomalyAlert;
use App\Service\Auth\SignupBlockedException;
use App\Service\Auth\SignupGuard;
use App\Service\Auth\SignupPauseSwitch;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class SignupGuardTest extends TestCase
{
    private ConfigRepository&MockObject $config;
    private SignupAnomalyAlert&MockObject $alert;
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigRepository::class);
        $this->alert = $this->createMock(SignupAnomalyAlert::class);
        $this->previousEnv = $_ENV[SignupPauseSwitch::ENV_VAR] ?? null;
        unset($_ENV[SignupPauseSwitch::ENV_VAR]);
    }

    protected function tearDown(): void
    {
        if (null === $this->previousEnv) {
            unset($_ENV[SignupPauseSwitch::ENV_VAR]);
        } else {
            $_ENV[SignupPauseSwitch::ENV_VAR] = $this->previousEnv;
        }
    }

    public function testAllowsNormalTrafficAndThrottlesOneAddress(): void
    {
        $guard = $this->guard(perIp: 2, global: 100);

        $guard->assertAllowed('203.0.113.1');
        $guard->assertAllowed('203.0.113.1');
        $guard->assertAllowed('203.0.113.2');

        $this->alert->expects($this->never())->method('signupBurst');
        try {
            $guard->assertAllowed('203.0.113.1');
            $this->fail('Third attempt from the same address must be refused');
        } catch (SignupBlockedException $e) {
            $this->assertSame(SignupBlockedException::CODE_RATE_LIMITED, $e->getReasonCode());
            $this->assertSame(429, $e->getHttpStatus());
            $this->assertGreaterThan(0, $e->getRetryAfterSeconds());
        }
    }

    public function testInstallationWideLimitRefusesAndAlertsTheOperator(): void
    {
        $guard = $this->guard(perIp: 100, global: 2);
        $guard->assertAllowed('203.0.113.1');
        $guard->assertAllowed('203.0.113.2');

        $this->alert->expects($this->once())->method('signupBurst')->with(['last_ip' => '203.0.113.3']);
        $this->expectException(SignupBlockedException::class);
        $guard->assertAllowed('203.0.113.3');
    }

    public function testStoredPauseSwitchRefusesEveryoneBeforeAnyLimiterIsConsumed(): void
    {
        $this->config->expects($this->once())->method('getValue')->with(0, SignupPauseSwitch::CONFIG_GROUP, SignupPauseSwitch::KEY_PAUSED)->willReturn('true');
        $guard = $this->guard(perIp: 100, global: 100);

        try {
            $guard->assertAllowed('203.0.113.1');
            $this->fail('Paused sign-up must refuse');
        } catch (SignupBlockedException $e) {
            $this->assertSame(SignupBlockedException::CODE_PAUSED, $e->getReasonCode());
            $this->assertSame(503, $e->getHttpStatus());
            $this->assertSame(0, $e->getRetryAfterSeconds());
        }
    }

    public function testAnInvitedPersonPassesThePauseButStillCountsAgainstTheLimits(): void
    {
        $this->config->method('getValue')->willReturn('true');
        $guard = $this->guard(perIp: 1, global: 100);

        $guard->assertAllowed('203.0.113.1', true);

        try {
            $guard->assertAllowed('203.0.113.1', true);
            $this->fail('The per-IP limit applies to invited sign-ups too');
        } catch (SignupBlockedException $e) {
            $this->assertSame(SignupBlockedException::CODE_RATE_LIMITED, $e->getReasonCode());
        }
    }

    public function testEnvironmentPinsThePauseSwitchOverTheStoredRow(): void
    {
        $this->config->method('getValue')->willReturn('true');
        $_ENV[SignupPauseSwitch::ENV_VAR] = 'false';

        $switch = new SignupPauseSwitch($this->config);
        $this->assertFalse($switch->isPaused());
        $this->assertTrue($switch->isLockedByEnv());

        $this->guard(perIp: 100, global: 100)->assertAllowed('203.0.113.1');
        $this->addToAssertionCount(1);
    }

    public function testMissingClientIpSharesOneBucketInsteadOfBypassing(): void
    {
        $guard = $this->guard(perIp: 1, global: 100);
        $guard->assertAllowed(null);

        $this->expectException(SignupBlockedException::class);
        $guard->assertAllowed('');
    }

    private function guard(int $perIp, int $global): SignupGuard
    {
        $ipFactory = new RateLimiterFactory(['id' => 'signup_ip', 'policy' => 'sliding_window', 'limit' => $perIp, 'interval' => '1 hour'], new InMemoryStorage());
        $globalFactory = new RateLimiterFactory(['id' => 'signup_global', 'policy' => 'sliding_window', 'limit' => $global, 'interval' => '10 minutes'], new InMemoryStorage());

        return new SignupGuard($ipFactory, $globalFactory, new SignupPauseSwitch($this->config), $this->alert, new NullLogger());
    }
}
