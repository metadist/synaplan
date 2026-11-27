<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\AppSecretGuard;
use App\Security\UnusableAppSecretException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AppSecretGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function unusable(): iterable
    {
        yield 'empty' => [''];
        yield 'only whitespace' => ["  \n"];
        yield 'value in .env.example' => ['changeme_in_production'];
        yield 'fallback written by the entrypoint' => ['change_me_in_production_12345678901234567890'];
        yield 'upper case' => ['CHANGEME'];
        yield 'surrounding whitespace' => [' changeme_in_production '];
    }

    #[DataProvider('unusable')]
    public function testAnExampleOrEmptySecretIsRefused(string $secret): void
    {
        self::assertFalse(AppSecretGuard::isUsable($secret));

        $this->expectException(UnusableAppSecretException::class);
        $this->expectExceptionMessage('openssl rand -hex 32');
        AppSecretGuard::assertUsable($secret);
    }

    public function testARandomSecretIsAccepted(): void
    {
        $secret = bin2hex(random_bytes(32));

        self::assertTrue(AppSecretGuard::isUsable($secret));
        AppSecretGuard::assertUsable($secret);
    }

    public function testASecretThatOnlyContainsTheExampleTextIsAccepted(): void
    {
        self::assertTrue(AppSecretGuard::isUsable('9f2c-changeme-71aa'));
    }
}
