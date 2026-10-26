<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing\PayFirst;

use App\Repository\ConfigRepository;
use App\Service\Auth\RegistrationConfig;
use App\Service\Billing\BillingService;
use App\Service\Billing\PayFirst\PayFirstConfig;
use App\Service\Email\MailerConfig;
use PHPUnit\Framework\TestCase;

/**
 * Pay first is off unless an operator turns it on, and never runs where
 * Stripe billing, self-sign-up or mail is off — so self-hosters, OIDC-only
 * installs and partners without Stripe keep their own process, and nobody
 * buys an account they could not sign back into.
 */
final class PayFirstConfigTest extends TestCase
{
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousEnv = $_ENV[PayFirstConfig::ENV_VAR] ?? null;
        unset($_ENV[PayFirstConfig::ENV_VAR]);
    }

    protected function tearDown(): void
    {
        if (null === $this->previousEnv) {
            unset($_ENV[PayFirstConfig::ENV_VAR]);
        } else {
            $_ENV[PayFirstConfig::ENV_VAR] = $this->previousEnv;
        }
    }

    public function testOffByDefault(): void
    {
        self::assertFalse($this->config(stored: null)->isActive());
    }

    public function testOnWhenSwitchedOnWithBillingAndSignUp(): void
    {
        self::assertTrue($this->config(stored: 'true')->isActive());
    }

    public function testNeverWithoutStripeBillingOrSelfSignUp(): void
    {
        self::assertFalse($this->config(stored: 'true', billing: false)->isActive());
        self::assertFalse($this->config(stored: 'true', registration: false)->isActive());
    }

    public function testNeverWithoutAMailTransport(): void
    {
        self::assertFalse($this->config(stored: 'true', mail: false)->isActive());
        self::assertTrue($this->config(stored: 'true', mail: false)->canSettle(), 'A payment already taken still becomes an account.');
    }

    public function testTheEnvironmentPinsTheSwitch(): void
    {
        $_ENV[PayFirstConfig::ENV_VAR] = 'false';
        self::assertFalse($this->config(stored: 'true')->isActive());

        $_ENV[PayFirstConfig::ENV_VAR] = 'true';
        self::assertTrue($this->config(stored: null)->isActive());
    }

    private function config(?string $stored, bool $billing = true, bool $registration = true, bool $mail = true): PayFirstConfig
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $key): ?string => PayFirstConfig::CONFIG_GROUP === $group && PayFirstConfig::KEY === $key ? $stored : null
        );
        $billingService = $this->createStub(BillingService::class);
        $billingService->method('isEnabled')->willReturn($billing);
        $registrationConfig = $this->createStub(RegistrationConfig::class);
        $registrationConfig->method('isEnabled')->willReturn($registration);

        $mailerConfig = $this->createStub(MailerConfig::class);
        $mailerConfig->method('isConfigured')->willReturn($mail);

        return new PayFirstConfig($repository, $billingService, $registrationConfig, $mailerConfig);
    }
}
