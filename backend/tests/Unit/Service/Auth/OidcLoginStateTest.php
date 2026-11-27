<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\OidcLoginState;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class OidcLoginStateTest extends TestCase
{
    private const VERIFIER = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUV';

    public function testIssueReturnsAShortIdAndKeepsTheVerifierInTheCache(): void
    {
        $service = $this->service();

        $state = $service->issue(self::VERIFIER, 'openid email profile', true, 'de');

        self::assertSame(32, strlen($state));
        self::assertSame(1, preg_match('/^'.OidcLoginState::ID_PATTERN.'$/', $state));
        self::assertStringNotContainsString(self::VERIFIER, $state);

        $attempt = $service->consume($state);
        self::assertSame([
            'pkce_verifier' => self::VERIFIER,
            'scopes' => 'openid email profile',
            'native' => true,
            'language' => 'de',
        ], $attempt);
        self::assertNull($service->consume($state));
    }

    public function testParallelAttemptsStayIndependent(): void
    {
        $service = $this->service();

        $first = $service->issue(self::VERIFIER, 'openid', false, null);
        $second = $service->issue(str_repeat('b', 43), 'openid profile', false, null);

        self::assertNotSame($first, $second);
        $firstAttempt = $service->consume($first);
        $secondAttempt = $service->consume($second);
        self::assertIsArray($firstAttempt);
        self::assertIsArray($secondAttempt);
        self::assertSame(self::VERIFIER, $firstAttempt['pkce_verifier']);
        self::assertSame(str_repeat('b', 43), $secondAttempt['pkce_verifier']);
    }

    public function testConsumeRejectsAnythingThatIsNotAnIssuedId(): void
    {
        $service = $this->service();

        self::assertNull($service->consume(''));
        self::assertNull($service->consume('not-a-state'));
        self::assertNull($service->consume(str_repeat('ab', 200)));
        self::assertNull($service->consume(bin2hex(random_bytes(16))));
    }

    public function testALanguageThatIsNotACodeIsDropped(): void
    {
        $service = $this->service();

        $state = $service->issue(self::VERIFIER, 'openid', false, 'deutsch');

        $attempt = $service->consume($state);
        self::assertIsArray($attempt);
        self::assertNull($attempt['language']);
    }

    public function testUnusableVerifierOrScopesAreRefusedBeforeTheCache(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::never())->method('getItem');
        $service = $this->service($cache);

        try {
            $service->issue('short', 'openid', false, null);
            self::fail('A short verifier must be refused.');
        } catch (\InvalidArgumentException) {
        }

        try {
            $service->issue(self::VERIFIER, '', false, null);
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

        $state = $this->service($cache)->issue(self::VERIFIER, 'openid', false, null);

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
        $this->service($cache)->issue(self::VERIFIER, 'openid', false, null);
    }

    public function testACallbackThatArrivesWhileTheAttemptIsBeingRedeemedGetsNothing(): void
    {
        $cache = new ArrayAdapter();
        $locks = new LockFactory(new InMemoryStore());
        $service = $this->service($cache, $locks);
        $state = $service->issue(self::VERIFIER, 'openid', false, null);

        $held = $locks->createLock('oidc_login_'.hash('sha256', $state), 30.0);
        self::assertTrue($held->acquire(false));

        $rival = $this->service($cache, $locks);
        self::assertNull($rival->consume($state));

        $held->release();
        $attempt = $service->consume($state);
        self::assertIsArray($attempt);
        self::assertSame(self::VERIFIER, $attempt['pkce_verifier']);
        self::assertNull($rival->consume($state));
    }

    private function service(?CacheItemPoolInterface $cache = null, ?LockFactory $locks = null): OidcLoginState
    {
        return new OidcLoginState(
            $cache ?? new ArrayAdapter(),
            $locks ?? new LockFactory(new InMemoryStore()),
        );
    }
}
