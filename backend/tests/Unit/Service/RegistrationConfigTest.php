<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\ConfigRepository;
use App\Service\RegistrationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Issue #462: local registration must be disable-able for OIDC-only instances.
 *
 * Covers both layers and — the part that is easy to get wrong — their
 * precedence: an explicit environment value wins over the BCONFIG row the setup
 * wizard writes, and the built-in default is ON only when neither is set.
 */
final class RegistrationConfigTest extends TestCase
{
    private ?string $originalEnv = null;
    private bool $envWasSet = false;
    private ConfigRepository&MockObject $configRepository;

    protected function setUp(): void
    {
        $this->envWasSet = \array_key_exists('REGISTRATION_ENABLED', $_ENV);
        $this->originalEnv = $this->envWasSet ? (string) $_ENV['REGISTRATION_ENABLED'] : null;
        $this->configRepository = $this->createMock(ConfigRepository::class);
    }

    protected function tearDown(): void
    {
        if ($this->envWasSet) {
            $_ENV['REGISTRATION_ENABLED'] = $this->originalEnv;
        } else {
            unset($_ENV['REGISTRATION_ENABLED']);
        }
    }

    public function testEnabledByDefaultWhenNeitherEnvNorDatabaseIsSet(): void
    {
        unset($_ENV['REGISTRATION_ENABLED']);
        $this->givenStoredValue(null);

        self::assertTrue($this->config()->isEnabled());
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function envValueProvider(): iterable
    {
        yield 'false' => ['false', false];
        yield '0' => ['0', false];
        yield 'off' => ['off', false];
        yield 'no' => ['no', false];
        yield 'true' => ['true', true];
        yield '1' => ['1', true];
        yield 'on' => ['on', true];
        // Unrecognized value keeps the safe default (registration allowed).
        yield 'garbage' => ['nonsense', true];
    }

    #[DataProvider('envValueProvider')]
    public function testIsEnabledParsesEnv(string $value, bool $expected): void
    {
        $_ENV['REGISTRATION_ENABLED'] = $value;
        $this->givenStoredValue(null);

        self::assertSame($expected, $this->config()->isEnabled());
    }

    /**
     * An empty variable is how every deployment template ships the flag
     * (deploy/selfhost.env.example, deploy/compose.yaml), and it has to mean
     * "not set" — otherwise the stored value could never take effect.
     */
    public function testEmptyEnvValueDefersToTheStoredValue(): void
    {
        $_ENV['REGISTRATION_ENABLED'] = '';
        $this->givenStoredValue('false');

        self::assertFalse($this->config()->isEnabled());
        self::assertNull($this->config()->envOverride());
        self::assertFalse($this->config()->isLockedByEnv());
    }

    public function testStoredValueAppliesWhenEnvIsUnset(): void
    {
        unset($_ENV['REGISTRATION_ENABLED']);
        $this->givenStoredValue('false');

        self::assertFalse($this->config()->isEnabled());
    }

    public function testExplicitEnvValueWinsOverTheStoredValue(): void
    {
        $_ENV['REGISTRATION_ENABLED'] = 'true';
        $this->givenStoredValue('false');

        self::assertTrue($this->config()->isEnabled());
        self::assertTrue($this->config()->isLockedByEnv());
    }

    public function testStoreWritesTheInstallWideRow(): void
    {
        $this->configRepository->expects($this->once())
            ->method('setValue')
            ->with(0, RegistrationConfig::CONFIG_GROUP, RegistrationConfig::KEY_ENABLED, 'false');

        $this->config()->store(false);
    }

    private function config(): RegistrationConfig
    {
        return new RegistrationConfig($this->configRepository);
    }

    private function givenStoredValue(?string $value): void
    {
        $this->configRepository->expects($this->any())
            ->method('getValue')
            ->with(0, RegistrationConfig::CONFIG_GROUP, RegistrationConfig::KEY_ENABLED)
            ->willReturn($value);
    }
}
