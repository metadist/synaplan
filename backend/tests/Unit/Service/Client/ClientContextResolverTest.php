<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Client;

use App\Service\Client\ClientContextResolver;
use PHPUnit\Framework\TestCase;

class ClientContextResolverTest extends TestCase
{
    private ClientContextResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ClientContextResolver();
    }

    public function testParsesMajorMinorVersion(): void
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Synaplan Mobile V4.0';
        $context = $this->resolver->fromUserAgent($ua);

        self::assertTrue($context->isMobileApp);
        self::assertSame('4.0', $context->appVersion);
        self::assertSame(4, $context->appVersionMajor);
        self::assertSame(0, $context->appVersionMinor);
        self::assertNull($context->appVersionPatch);
        self::assertSame('mobile', $context->platform());
    }

    public function testParsesMajorMinorPatchVersion(): void
    {
        $context = $this->resolver->fromUserAgent('Android WebView Synaplan Mobile V4.0.1');

        self::assertTrue($context->isMobileApp);
        self::assertSame('4.0.1', $context->appVersion);
        self::assertSame(4, $context->appVersionMajor);
        self::assertSame(0, $context->appVersionMinor);
        self::assertSame(1, $context->appVersionPatch);
    }

    public function testHandlesLargerVersionNumbers(): void
    {
        $context = $this->resolver->fromUserAgent('Synaplan Mobile V12.34.56');

        self::assertSame('12.34.56', $context->appVersion);
        self::assertSame(12, $context->appVersionMajor);
        self::assertSame(34, $context->appVersionMinor);
        self::assertSame(56, $context->appVersionPatch);
    }

    public function testPlainBrowserUserAgentIsWeb(): void
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
        $context = $this->resolver->fromUserAgent($ua);

        self::assertFalse($context->isMobileApp);
        self::assertNull($context->appVersion);
        self::assertSame('web', $context->platform());
    }

    public function testNullUserAgentIsWeb(): void
    {
        $context = $this->resolver->fromUserAgent(null);

        self::assertFalse($context->isMobileApp);
        self::assertNull($context->appVersion);
    }

    public function testEmptyUserAgentIsWeb(): void
    {
        $context = $this->resolver->fromUserAgent('');

        self::assertFalse($context->isMobileApp);
    }

    /**
     * Spoof-ish / malformed variants must NOT be detected as the app: the contract is a
     * capital V immediately followed by digits, so these near-misses fall back to web.
     */
    public function testRejectsSpoofAndMalformedTokens(): void
    {
        $nonMatches = [
            'Synaplan Mobile',            // no version
            'Synaplan Mobile V',          // V without digits
            'Synaplan Mobile Vx.y',       // non-numeric
            'Synaplan Mobile v4.0',       // lowercase v
            'SynaplanMobile V4.0',        // missing space
            'Synaplan Desktop V4.0',      // wrong product word
        ];

        foreach ($nonMatches as $ua) {
            $context = $this->resolver->fromUserAgent($ua);
            self::assertFalse($context->isMobileApp, sprintf('UA "%s" must not be detected as the mobile app', $ua));
            self::assertNull($context->appVersion);
        }
    }
}
