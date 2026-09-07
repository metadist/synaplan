<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Service\PlatformLink\Exception\PlatformLinkValidationException;
use App\Service\PlatformLink\RedirectUriPolicy;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedirectUriPolicyTest extends TestCase
{
    private RedirectUriPolicy $prod;
    private RedirectUriPolicy $dev;

    protected function setUp(): void
    {
        $guard = new SsrfGuard();
        $this->prod = new RedirectUriPolicy($guard, 'prod');
        $this->dev = new RedirectUriPolicy($guard, 'dev');
    }

    public function testAcceptsExactPrefixOnRegisteredHost(): void
    {
        $host = $this->prod->normalizeHost('https://files.example.org');
        $uris = $this->prod->normalizeRedirectUris($host, [
            'https://files.example.org/apps/synaplan_integration/link/callback',
        ]);

        self::assertTrue($this->prod->matchesRegisteredPrefix(
            'https://files.example.org/apps/synaplan_integration/link/callback',
            $host,
            $uris,
        ));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rejectedRedirects(): iterable
    {
        yield 'other host' => ['https://evil.example/apps/synaplan_integration/link/callback'];
        yield 'suffix host' => ['https://files.example.org.evil.example/apps/synaplan_integration/link/callback'];
        yield 'userinfo' => ['https://files.example.org@evil.example/apps/synaplan_integration/link/callback'];
        yield 'http downgrade' => ['http://files.example.org/apps/synaplan_integration/link/callback'];
        yield 'protocol relative' => ['//evil.example/apps/synaplan_integration/link/callback'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'backslash' => ['https://files.example.org\\@evil.example/cb'];
        yield 'encoded slash host' => ['https://files.example.org%2Fevil.example/cb'];
        yield 'parent path' => ['https://files.example.org/apps/x/../../evil'];
        yield 'port mismatch' => ['https://files.example.org:8443/apps/synaplan_integration/link/callback'];
        yield 'trailing dot' => ['https://files.example.org./apps/synaplan_integration/link/callback'];
        yield 'mixed case host spoof via extra label' => ['https://Files.Example.Org.evil.example/cb'];
    }

    #[DataProvider('rejectedRedirects')]
    public function testRejectsOpenRedirectCorpus(string $uri): void
    {
        $host = 'files.example.org';
        $registered = ['https://files.example.org/apps/synaplan_integration/link/callback'];

        self::assertFalse($this->prod->matchesRegisteredPrefix($uri, $host, $registered));
    }

    public function testRejectsIdnLookAlikeHostOnRegister(): void
    {
        $this->expectException(PlatformLinkValidationException::class);
        $this->prod->normalizeHost('xn--files-example-org');
    }

    public function testLocalhostHttpAllowedOnlyInDev(): void
    {
        $host = $this->dev->normalizeHost('http://localhost:8081');
        self::assertSame('localhost:8081', $host);
        $uris = $this->dev->normalizeRedirectUris($host, [
            'http://localhost:8081/apps/synaplan_integration/link/callback',
        ]);
        self::assertTrue($this->dev->matchesRegisteredPrefix(
            'http://localhost:8081/apps/synaplan_integration/link/callback',
            $host,
            $uris,
        ));

        $this->expectException(PlatformLinkValidationException::class);
        $this->prod->normalizeHost('http://localhost:8081');
    }

    public function testDockerNextcloudHostAllowedInDev(): void
    {
        $host = $this->dev->normalizeHost('http://nextcloud');
        self::assertSame('nextcloud', $host);
    }
}
