<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\ConfigRepository;
use App\Service\GuestChatConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1517: the anonymous guest trial must be disable-able for OIDC-only
 * instances. Same two layers and the same precedence as
 * {@see RegistrationConfigTest}: an explicit environment value wins over the
 * BCONFIG row the setup wizard writes.
 */
final class GuestChatConfigTest extends TestCase
{
    private ?string $originalEnv = null;
    private bool $envWasSet = false;
    private ConfigRepository&MockObject $configRepository;

    protected function setUp(): void
    {
        $this->envWasSet = \array_key_exists('GUEST_CHAT_ENABLED', $_ENV);
        $this->originalEnv = $this->envWasSet ? (string) $_ENV['GUEST_CHAT_ENABLED'] : null;
        $this->configRepository = $this->createMock(ConfigRepository::class);
    }

    protected function tearDown(): void
    {
        if ($this->envWasSet) {
            $_ENV['GUEST_CHAT_ENABLED'] = $this->originalEnv;
        } else {
            unset($_ENV['GUEST_CHAT_ENABLED']);
        }
    }

    public function testEnabledByDefaultWhenNeitherEnvNorDatabaseIsSet(): void
    {
        unset($_ENV['GUEST_CHAT_ENABLED']);
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
        // Unrecognized value keeps the safe default (guest trial allowed).
        yield 'garbage' => ['nonsense', true];
    }

    #[DataProvider('envValueProvider')]
    public function testIsEnabledParsesEnv(string $value, bool $expected): void
    {
        $_ENV['GUEST_CHAT_ENABLED'] = $value;
        $this->givenStoredValue(null);

        self::assertSame($expected, $this->config()->isEnabled());
    }

    public function testEmptyEnvValueDefersToTheStoredValue(): void
    {
        $_ENV['GUEST_CHAT_ENABLED'] = '';
        $this->givenStoredValue('false');

        self::assertFalse($this->config()->isEnabled());
        self::assertFalse($this->config()->isLockedByEnv());
    }

    public function testStoredValueAppliesWhenEnvIsUnset(): void
    {
        unset($_ENV['GUEST_CHAT_ENABLED']);
        $this->givenStoredValue('false');

        self::assertFalse($this->config()->isEnabled());
    }

    public function testExplicitEnvValueWinsOverTheStoredValue(): void
    {
        $_ENV['GUEST_CHAT_ENABLED'] = 'false';
        $this->givenStoredValue('true');

        self::assertFalse($this->config()->isEnabled());
        self::assertTrue($this->config()->isLockedByEnv());
    }

    public function testStoreWritesTheInstallWideRow(): void
    {
        $this->configRepository->expects($this->once())
            ->method('setValue')
            ->with(0, GuestChatConfig::CONFIG_GROUP, GuestChatConfig::KEY_ENABLED, 'true');

        $this->config()->store(true);
    }

    private function config(): GuestChatConfig
    {
        return new GuestChatConfig($this->configRepository);
    }

    private function givenStoredValue(?string $value): void
    {
        $this->configRepository->expects($this->any())
            ->method('getValue')
            ->with(0, GuestChatConfig::CONFIG_GROUP, GuestChatConfig::KEY_ENABLED)
            ->willReturn($value);
    }
}
