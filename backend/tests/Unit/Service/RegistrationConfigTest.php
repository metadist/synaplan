<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\RegistrationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #462: local registration must be disable-able for OIDC-only instances.
 * Covers the REGISTRATION_ENABLED env parsing (default ON, explicit falsey OFF).
 */
final class RegistrationConfigTest extends TestCase
{
    private ?string $originalEnv = null;
    private bool $envWasSet = false;

    protected function setUp(): void
    {
        $this->envWasSet = \array_key_exists('REGISTRATION_ENABLED', $_ENV);
        $this->originalEnv = $this->envWasSet ? (string) $_ENV['REGISTRATION_ENABLED'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->envWasSet) {
            $_ENV['REGISTRATION_ENABLED'] = $this->originalEnv;
        } else {
            unset($_ENV['REGISTRATION_ENABLED']);
        }
    }

    public function testEnabledByDefaultWhenUnset(): void
    {
        unset($_ENV['REGISTRATION_ENABLED']);

        self::assertTrue((new RegistrationConfig())->isEnabled());
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
        yield 'empty string' => ['', true];
    }

    #[DataProvider('envValueProvider')]
    public function testIsEnabledParsesEnv(string $value, bool $expected): void
    {
        $_ENV['REGISTRATION_ENABLED'] = $value;

        self::assertSame($expected, (new RegistrationConfig())->isEnabled());
    }
}
