<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GuestChatConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1517: the anonymous guest trial must be disable-able for OIDC-only
 * instances. Covers the GUEST_CHAT_ENABLED env parsing (default ON, explicit
 * falsey OFF), mirroring {@see RegistrationConfigTest}.
 */
final class GuestChatConfigTest extends TestCase
{
    private ?string $originalEnv = null;
    private bool $envWasSet = false;

    protected function setUp(): void
    {
        $this->envWasSet = \array_key_exists('GUEST_CHAT_ENABLED', $_ENV);
        $this->originalEnv = $this->envWasSet ? (string) $_ENV['GUEST_CHAT_ENABLED'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->envWasSet) {
            $_ENV['GUEST_CHAT_ENABLED'] = $this->originalEnv;
        } else {
            unset($_ENV['GUEST_CHAT_ENABLED']);
        }
    }

    public function testEnabledByDefaultWhenUnset(): void
    {
        unset($_ENV['GUEST_CHAT_ENABLED']);

        self::assertTrue((new GuestChatConfig())->isEnabled());
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
        yield 'empty string' => ['', true];
    }

    #[DataProvider('envValueProvider')]
    public function testIsEnabledParsesEnv(string $value, bool $expected): void
    {
        $_ENV['GUEST_CHAT_ENABLED'] = $value;

        self::assertSame($expected, (new GuestChatConfig())->isEnabled());
    }
}
