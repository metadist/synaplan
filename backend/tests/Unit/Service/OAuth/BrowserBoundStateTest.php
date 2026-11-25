<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\OAuth;

use App\EventSubscriber\OAuthBrowserCookieSubscriber;
use App\Service\Auth\AuthCookieFactory;
use App\Service\OAuth\BrowserBoundState;
use App\Service\OAuth\OAuthStateService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class BrowserBoundStateTest extends TestCase
{
    private Request $request;
    private BrowserBoundState $state;

    protected function setUp(): void
    {
        $this->request = new Request();
        $stack = new RequestStack();
        $stack->push($this->request);
        $this->state = new BrowserBoundState(
            new OAuthStateService(new NullLogger(), 'secret'),
            $stack,
            // Production posture: the auth cookies are Strict and Secure there.
            new AuthCookieFactory('prod', 'https://app.example.com'),
            new NullLogger(),
            new TokenStorage(),
        );
    }

    /**
     * Strict would not arrive on the provider's redirect; a script-readable or
     * site-wide cookie would hand the proof to more code than needs it.
     */
    public function testStartingAFlowAsksForALaxHttpOnlyCookieThatExpiresWithTheState(): void
    {
        $this->state->generateState('m365', ['owner_id' => 42]);

        $cookie = $this->cookie();
        self::assertSame('oauth_flow_m365', $cookie->getName());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame('/api/v1', $cookie->getPath());
        self::assertEqualsWithDelta(time() + 600, $cookie->getExpiresTime(), 5);
        self::assertSame(64, strlen((string) $cookie->getValue()));
    }

    public function testTheStateCarriesOnlyTheHashOfTheCookie(): void
    {
        $token = $this->state->generateState('m365', ['owner_id' => 42]);
        $value = (string) $this->cookie()->getValue();

        $payload = $this->state->peek($token, 'm365');
        self::assertSame(hash('sha256', $value), $payload['browser'] ?? null);
        self::assertStringNotContainsString($value, (string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/'), true));
    }

    public function testAnAcceptedCallbackClearsTheCookie(): void
    {
        $token = $this->state->generateState('dropbox', ['owner_id' => 42]);
        $this->request->cookies->set('oauth_flow_dropbox', (string) $this->cookie()->getValue());

        self::assertSame(42, $this->state->validateState($token, 'dropbox')['owner_id'] ?? null);

        $cleared = $this->cookie();
        self::assertSame('', (string) $cleared->getValue());
        self::assertLessThan(time(), $cleared->getExpiresTime());
    }

    /**
     * Each connector has its own cookie, so starting Dropbox in another tab
     * does not break a Microsoft sign-in that is under way.
     */
    public function testTheCookieOfOneProviderDoesNotUnlockAnother(): void
    {
        $token = $this->state->generateState('m365', ['owner_id' => 42]);
        $this->request->cookies->set('oauth_flow_dropbox', (string) $this->cookie()->getValue());

        self::assertNull($this->state->validateState($token, 'm365'));
    }

    public function testTheSubscriberPutsTheCookieOnTheResponse(): void
    {
        $this->state->generateState('mcp', ['owner_id' => 42]);
        $response = new Response();

        (new OAuthBrowserCookieSubscriber())->onResponse(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $this->request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame('oauth_flow_mcp', $cookies[0]->getName());
    }

    private function cookie(): Cookie
    {
        $cookie = $this->request->attributes->get(BrowserBoundState::COOKIE_ATTRIBUTE);
        self::assertInstanceOf(Cookie::class, $cookie);

        return $cookie;
    }
}
