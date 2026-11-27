<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\AuthCookieFactory;
use App\Service\Auth\LoginBrowserBinding;
use App\Service\Auth\OidcLoginState;
use App\Tests\Support\LoginBrowser;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class OidcLoginStateTest extends TestCase
{
    private const VERIFIER = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUV';

    private Request $browser;

    protected function setUp(): void
    {
        $this->browser = new Request();
    }

    public function testIssueReturnsAShortIdAndKeepsTheVerifierInTheCache(): void
    {
        $service = $this->service();

        $state = $service->issue($this->browser, self::VERIFIER, 'openid email profile', true, 'de');

        self::assertSame(32, strlen($state));
        self::assertSame(1, preg_match('/^'.OidcLoginState::ID_PATTERN.'$/', $state));
        self::assertStringNotContainsString(self::VERIFIER, $state);

        $attempt = $service->consume($this->browser, $state);
        self::assertSame([
            'pkce_verifier' => self::VERIFIER,
            'scopes' => 'openid email profile',
            'native' => true,
            'language' => 'de',
        ], $attempt);
        self::assertNull($service->consume($this->browser, $state));
    }

    public function testParallelAttemptsStayIndependent(): void
    {
        $service = $this->service();

        $first = $service->issue($this->browser, self::VERIFIER, 'openid', false, null);
        LoginBrowser::keepCookie($this->browser);
        $second = $service->issue($this->browser, str_repeat('b', 43), 'openid profile', false, null);

        self::assertNotSame($first, $second);
        $firstAttempt = $service->consume($this->browser, $first);
        $secondAttempt = $service->consume($this->browser, $second);
        self::assertIsArray($firstAttempt);
        self::assertIsArray($secondAttempt);
        self::assertSame(self::VERIFIER, $firstAttempt['pkce_verifier']);
        self::assertSame(str_repeat('b', 43), $secondAttempt['pkce_verifier']);
    }

    public function testConsumeRejectsAnythingThatIsNotAnIssuedId(): void
    {
        $service = $this->service();

        self::assertNull($service->consume($this->browser, ''));
        self::assertNull($service->consume($this->browser, 'not-a-state'));
        self::assertNull($service->consume($this->browser, str_repeat('ab', 200)));
        self::assertNull($service->consume($this->browser, bin2hex(random_bytes(16))));
    }

    public function testALanguageThatIsNotACodeIsDropped(): void
    {
        $service = $this->service();

        $state = $service->issue($this->browser, self::VERIFIER, 'openid', false, 'deutsch');
        LoginBrowser::keepCookie($this->browser);

        $attempt = $service->consume($this->browser, $state);
        self::assertIsArray($attempt);
        self::assertNull($attempt['language']);
    }

    public function testUnusableVerifierOrScopesAreRefusedBeforeTheCache(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::never())->method('getItem');
        $service = $this->service($cache);

        try {
            $service->issue($this->browser, 'short', 'openid', false, null);
            self::fail('A short verifier must be refused.');
        } catch (\InvalidArgumentException) {
        }

        try {
            $service->issue($this->browser, self::VERIFIER, '', false, null);
            self::fail('Empty scopes must be refused.');
        } catch (\InvalidArgumentException) {
        }
    }

    public function testTheAttemptExpiresWithThePreviousStateLifetime(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('set')->willReturnSelf();
        $item->expects(self::once())->method('expiresAfter')->with(OidcLoginState::TTL_SECONDS)->willReturnSelf();

        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);
        $cache->method('save')->willReturn(true);

        $state = $this->service($cache)->issue($this->browser, self::VERIFIER, 'openid', false, null);
        LoginBrowser::keepCookie($this->browser);

        self::assertSame(32, strlen($state));
    }

    public function testACacheThatRejectsTheWriteDoesNotHandOutAnId(): void
    {
        $item = $this->createStub(CacheItemInterface::class);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnSelf();

        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);
        $cache->method('save')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->service($cache)->issue($this->browser, self::VERIFIER, 'openid', false, null);
    }

    public function testACallbackThatArrivesWhileTheAttemptIsBeingRedeemedGetsNothing(): void
    {
        $cache = new ArrayAdapter();
        $locks = new LockFactory(new InMemoryStore());
        $service = $this->service($cache, $locks);
        $state = $service->issue($this->browser, self::VERIFIER, 'openid', false, null);
        LoginBrowser::keepCookie($this->browser);

        $held = $locks->createLock('oidc_login_'.hash('sha256', $state), 30.0);
        self::assertTrue($held->acquire(false));

        $rival = $this->service($cache, $locks);
        self::assertNull($rival->consume($this->browser, $state));

        $held->release();
        $attempt = $service->consume($this->browser, $state);
        self::assertIsArray($attempt);
        self::assertSame(self::VERIFIER, $attempt['pkce_verifier']);
        self::assertNull($rival->consume($this->browser, $state));
    }

    public function testACallbackThatCannotDeleteTheAttemptGetsNothing(): void
    {
        $stored = new ArrayAdapter();
        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturnCallback(
            static fn (string $key): CacheItemInterface => $stored->getItem($key),
        );
        $cache->method('save')->willReturnCallback(
            static fn (CacheItemInterface $item): bool => $stored->save($item),
        );
        $cache->method('deleteItem')->willReturn(false);

        $service = $this->service($cache);
        $state = $service->issue($this->browser, self::VERIFIER, 'openid', false, null);
        LoginBrowser::keepCookie($this->browser);

        self::assertNull($service->consume($this->browser, $state));
        self::assertTrue($stored->hasItem('oidc_login_'.hash('sha256', $state)));
    }

    public function testAnAttemptStartedInABrowserIsNotSpentByAnotherBrowser(): void
    {
        $service = $this->service();
        $state = $service->issue($this->browser, self::VERIFIER, 'openid', false, null);

        self::assertNull($service->consume(new Request(), $state));
    }

    public function testAnAttemptStartedByTheAppNeedsNoCookie(): void
    {
        $service = $this->service();
        $state = $service->issue($this->browser, self::VERIFIER, 'openid', true, null);

        self::assertNull($this->browser->attributes->get(LoginBrowser::COOKIE_ATTRIBUTE));
        self::assertIsArray($service->consume(new Request(), $state));
    }

    private function service(?CacheItemPoolInterface $cache = null, ?LockFactory $locks = null): OidcLoginState
    {
        return new OidcLoginState(
            $cache ?? new ArrayAdapter(),
            $locks ?? new LockFactory(new InMemoryStore()),
            new LoginBrowserBinding(new AuthCookieFactory('prod', 'https://app.example.com'), new NullLogger()),
        );
    }
}
