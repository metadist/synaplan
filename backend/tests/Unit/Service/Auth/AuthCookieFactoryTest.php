<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\AuthCookieFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Unit tests for {@see AuthCookieFactory}.
 *
 * The `Secure` matrix is the security-relevant part: a wrong `true` locks an
 * HTTP-only deployment out of its own session, a wrong `false` exposes a real
 * HTTPS deployment's auth cookies to a downgrade.
 */
final class AuthCookieFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function secureProvider(): iterable
    {
        yield 'prod over https' => ['prod', 'https://app.example.com', '', true];
        yield 'prod over http' => ['prod', 'http://umbrel.local:8300', '', false];
        yield 'dev over http' => ['dev', 'http://localhost:8000', '', false];
        yield 'scheme is matched case-insensitively' => ['prod', 'HTTPS://app.example.com', '', true];
        yield 'surrounding whitespace is ignored' => ['prod', '  https://app.example.com  ', '', true];

        yield 'unknown url fails secure in prod' => ['prod', '', '', true];
        yield 'unknown url stays open in dev' => ['dev', '', '', false];

        // A host without a scheme is not evidence of plain HTTP, so it must not
        // strip the flag off an HTTPS deployment that was configured sloppily.
        yield 'schemeless url fails secure in prod' => ['prod', 'app.example.com', '', true];
        yield 'schemeless url stays open in dev' => ['dev', 'localhost:8000', '', false];
        yield 'protocol-relative url fails secure in prod' => ['prod', '//app.example.com', '', true];
        yield 'uppercase http is still http' => ['prod', 'HTTP://umbrel.local:8300', '', false];

        yield 'override forces secure on http' => ['prod', 'http://app.example.com', 'true', true];
        yield 'override disables secure on https' => ['prod', 'https://app.example.com', 'false', false];
        yield 'override accepts 1' => ['dev', 'http://localhost:8000', '1', true];
        yield 'override accepts 0' => ['prod', 'https://app.example.com', '0', false];
        yield 'unparseable override falls back to the url' => ['prod', 'https://app.example.com', 'maybe', true];
    }

    #[DataProvider('secureProvider')]
    public function testSecureFlag(string $appEnv, string $appUrl, string $forceSecure, bool $expected): void
    {
        $factory = new AuthCookieFactory($appEnv, $appUrl, $forceSecure);

        $this->assertSame($expected, $factory->create('access_token', 'value', 1234567890)->isSecure());
    }

    public function testSameSiteFollowsTheEnvironmentNotTheScheme(): void
    {
        $prod = new AuthCookieFactory('prod', 'http://umbrel.local:8300');
        $dev = new AuthCookieFactory('dev', 'https://app.example.com');

        $this->assertSame(Cookie::SAMESITE_STRICT, $prod->create('access_token', 'value', 1)->getSameSite());
        $this->assertSame(Cookie::SAMESITE_LAX, $dev->create('access_token', 'value', 1)->getSameSite());
    }

    public function testCookieCarriesNameValueExpiryPathAndHttpOnly(): void
    {
        $factory = new AuthCookieFactory('prod', 'https://app.example.com');

        $cookie = $factory->create('refresh_token', 'secret-value', 1234567890);

        $this->assertSame('refresh_token', $cookie->getName());
        $this->assertSame('secret-value', $cookie->getValue());
        $this->assertSame(1234567890, $cookie->getExpiresTime());
        $this->assertSame('/', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
    }
}
