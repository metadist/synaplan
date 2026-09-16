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

    public function testRegisteredRootUriAcceptsAnyPathOnThatOrigin(): void
    {
        $host = $this->prod->normalizeHost('https://files.example.org');
        $uris = $this->prod->normalizeRedirectUris($host, ['https://files.example.org']);

        self::assertSame(['https://files.example.org/'], $uris);
        self::assertTrue($this->prod->matchesRegisteredPrefix('https://files.example.org/apps/x/callback', $host, $uris));
        self::assertTrue($this->prod->matchesRegisteredPrefix('https://files.example.org/', $host, $uris));
        self::assertFalse($this->prod->matchesRegisteredPrefix('https://files.example.org:8443/apps/x/callback', $host, $uris));
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

    public function testWildcardHostCannotBeRegistered(): void
    {
        $this->expectException(PlatformLinkValidationException::class);
        $this->prod->normalizeHost('*');
    }

    public function testWildcardSubdomainHostCannotBeRegistered(): void
    {
        $this->expectException(PlatformLinkValidationException::class);
        $this->prod->normalizeHost('https://*.example.org');
    }

    /** The seeded Outlook built-in row (migration Version20260908120000). */
    private const OUTLOOK_BUILTIN = [
        'https://localhost',
        'https://127.0.0.1',
        'https://addin.synaplan.com',
        'https://*.synaplan.com',
    ];

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function acceptedOutlookRelays(): iterable
    {
        yield 'dev server with port' => ['https://localhost:3000/src/dialog/auth-relay.html'];
        yield 'loopback with port' => ['https://127.0.0.1:3000/src/dialog/auth-relay.html'];
        yield 'production add-in host' => ['https://addin.synaplan.com/src/dialog/auth-relay.html'];
        yield 'other synaplan subdomain' => ['https://addin-staging.synaplan.com/dialog/auth-relay.html'];
    }

    #[DataProvider('acceptedOutlookRelays')]
    public function testOutlookBuiltinAcceptsRelayHosts(string $uri): void
    {
        self::assertTrue($this->prod->matchesRegisteredPrefix($uri, '*', self::OUTLOOK_BUILTIN));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rejectedOutlookRelays(): iterable
    {
        yield 'foreign host' => ['https://evil.example/relay'];
        yield 'suffix spoof' => ['https://synaplan.com.evil.example/relay'];
        yield 'apex without subdomain' => ['https://synaplan.com/relay'];
        yield 'http downgrade on localhost in prod' => ['http://localhost:3000/relay'];
        yield 'http downgrade on synaplan host' => ['http://addin.synaplan.com/relay'];
        yield 'userinfo' => ['https://addin.synaplan.com@evil.example/relay'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'query on relay' => ['https://addin.synaplan.com/relay?next=https://evil.example'];
    }

    #[DataProvider('rejectedOutlookRelays')]
    public function testOutlookBuiltinRejectsForeignRelays(string $uri): void
    {
        self::assertFalse($this->prod->matchesRegisteredPrefix($uri, '*', self::OUTLOOK_BUILTIN));
    }

    public function testPortStaysStrictForNonLocalHosts(): void
    {
        self::assertFalse($this->prod->matchesRegisteredPrefix(
            'https://addin.synaplan.com:8443/relay',
            '*',
            self::OUTLOOK_BUILTIN,
        ));
    }
}
