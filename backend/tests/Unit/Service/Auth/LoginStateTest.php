<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\AuthCookieFactory;
use App\Service\Auth\LoginBrowserBinding;
use App\Service\Auth\LoginState;
use App\Service\OAuth\OAuthStateService;
use App\Tests\Support\LoginBrowser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class LoginStateTest extends TestCase
{
    private LoginState $state;

    protected function setUp(): void
    {
        $this->state = new LoginState(
            new OAuthStateService(new NullLogger(), 'secret'),
            new LoginBrowserBinding(new AuthCookieFactory('prod', 'https://app.example.com'), new NullLogger()),
        );
    }

    public function testTheBrowserThatStartedRedeemsItsStateWithTheExtras(): void
    {
        $browser = new Request();
        $state = $this->state->issue($browser, 'google', ['language' => 'de']);
        LoginBrowser::keepCookie($browser);

        $payload = $this->state->redeem($browser, $state, 'google');

        self::assertIsArray($payload);
        self::assertSame('de', $payload['language']);
        self::assertFalse(LoginState::isNative($payload));
    }

    /** The answer of someone else's sign-in, opened by a person who never started one. */
    public function testAStateOpenedInAnotherBrowserSignsNobodyIn(): void
    {
        $state = $this->state->issue(new Request(), 'google');

        self::assertNull($this->state->redeem(new Request(), $state, 'google'));
    }

    /** Nor does it help that the other browser has a sign-in of its own under way. */
    public function testAStateOpenedInABrowserWithItsOwnSignInIsRefused(): void
    {
        $state = $this->state->issue(new Request(), 'google');

        $victim = new Request();
        $this->state->issue($victim, 'google');
        LoginBrowser::keepCookie($victim);

        self::assertNull($this->state->redeem($victim, $state, 'google'));
    }

    public function testAStateOfAnotherProviderIsRefused(): void
    {
        $browser = new Request();
        $state = $this->state->issue($browser, 'google');
        LoginBrowser::keepCookie($browser);

        self::assertNull($this->state->redeem($browser, $state, 'github'));
    }

    public function testASignInStartedByTheAppIsMarkedAndSetsNoCookie(): void
    {
        $app = new Request(['native' => '1']);
        $state = $this->state->issue($app, 'github');

        self::assertNull($app->attributes->get(LoginBrowser::COOKIE_ATTRIBUTE));
        $payload = $this->state->redeem(new Request(), $state, 'github');
        self::assertIsArray($payload);
        self::assertTrue(LoginState::isNative($payload));
    }

    /** `native` and `browser` are the service's own claims; a caller cannot set them. */
    public function testExtrasCannotMarkABrowserSignInAsTheApps(): void
    {
        $state = $this->state->issue(new Request(), 'google', ['native' => true, 'browser' => 'chosen']);

        self::assertNull($this->state->redeem(new Request(), $state, 'google'));
    }
}
