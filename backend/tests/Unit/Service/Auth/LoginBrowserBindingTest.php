<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\AuthCookieFactory;
use App\Service\Auth\LoginBrowserBinding;
use App\Tests\Support\LoginBrowser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final class LoginBrowserBindingTest extends TestCase
{
    /**
     * Strict would not arrive on the provider's redirect; a script-readable or
     * site-wide cookie would hand the proof to more code than needs it.
     */
    public function testStartingASignInAsksForALaxHttpOnlyCookieOnTheAuthPath(): void
    {
        $browser = new Request();

        $claim = $this->binding()->start($browser, 'google');

        $cookie = LoginBrowser::keepCookie($browser);
        self::assertSame('login_flow_google', $cookie->getName());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame('/api/v1/auth', $cookie->getPath());
        self::assertEqualsWithDelta(time() + 600, $cookie->getExpiresTime(), 5);
        self::assertSame(64, strlen((string) $cookie->getValue()));
        self::assertSame(hash('sha256', (string) $cookie->getValue()), $claim);
        self::assertNotSame($cookie->getValue(), $claim);
    }

    /** Apple answers with a cross-site POST, which a Lax cookie does not accompany. */
    public function testAppleGetsACookieThatArrivesOnACrossSitePost(): void
    {
        $browser = new Request();

        // A plain-http deployment: every other cookie is not Secure there.
        $this->binding('dev', 'http://localhost:8000')->start($browser, 'apple');

        $cookie = LoginBrowser::keepCookie($browser);
        self::assertSame(Cookie::SAMESITE_NONE, $cookie->getSameSite());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
    }

    public function testTheBrowserThatStartedIsConfirmed(): void
    {
        $browser = new Request();
        $claim = $this->binding()->start($browser, 'github');
        LoginBrowser::keepCookie($browser);

        self::assertTrue($this->binding()->confirm($browser, 'github', $claim));
    }

    public function testAnotherBrowserIsRefused(): void
    {
        $claim = $this->binding()->start(new Request(), 'github');

        $other = new Request();
        self::assertFalse($this->binding()->confirm($other, 'github', $claim));

        $this->binding()->start($other, 'github');
        LoginBrowser::keepCookie($other);
        self::assertFalse($this->binding()->confirm($other, 'github', $claim));
    }

    public function testACookieOfAnotherProviderDoesNotCount(): void
    {
        $browser = new Request();
        $claim = $this->binding()->start($browser, 'google');
        $cookie = LoginBrowser::keepCookie($browser);
        $browser->cookies->set('login_flow_github', (string) $cookie->getValue());
        $browser->cookies->remove('login_flow_google');

        self::assertFalse($this->binding()->confirm($browser, 'google', $claim));
    }

    public function testAStateWithoutAClaimIsRefusedEvenWithACookie(): void
    {
        $browser = new Request();
        $this->binding()->start($browser, 'google');
        LoginBrowser::keepCookie($browser);

        self::assertFalse($this->binding()->confirm($browser, 'google', null));
        self::assertFalse($this->binding()->confirm($browser, 'google', ''));
        self::assertFalse($this->binding()->confirm($browser, 'google', true));
    }

    /** Two tabs, or a second click: both sign-ins finish in the same browser. */
    public function testASecondStartInTheSameBrowserKeepsTheFirstSignInAlive(): void
    {
        $browser = new Request();
        $first = $this->binding()->start($browser, 'google');
        LoginBrowser::keepCookie($browser);
        $second = $this->binding()->start($browser, 'google');
        LoginBrowser::keepCookie($browser);

        self::assertTrue($this->binding()->confirm($browser, 'google', $first));
        self::assertTrue($this->binding()->confirm($browser, 'google', $second));
    }

    public function testACookieThatIsNotOursIsReplaced(): void
    {
        $browser = new Request();
        $browser->cookies->set('login_flow_google', 'planted');

        $this->binding()->start($browser, 'google');

        self::assertSame(64, strlen((string) LoginBrowser::keepCookie($browser)->getValue()));
    }

    private function binding(string $env = 'prod', string $appUrl = 'https://app.example.com'): LoginBrowserBinding
    {
        return new LoginBrowserBinding(new AuthCookieFactory($env, $appUrl), new NullLogger());
    }
}
