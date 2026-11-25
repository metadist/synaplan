<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\OAuth;

use App\Entity\User;
use App\Service\OAuth\BrowserBoundState;
use App\Tests\Support\ConsentBrowser;
use PHPUnit\Framework\TestCase;

/**
 * The native app keeps no cookies, so its consent flow is bound to the
 * account: only a caller signed in as the owner can redeem the state.
 */
final class AppBoundStateTest extends TestCase
{
    private ConsentBrowser $app;

    protected function setUp(): void
    {
        $this->app = new ConsentBrowser();
        $this->app->startInApp();
    }

    public function testAFlowStartedInTheAppSetsNoCookieAndIsMarkedAppBound(): void
    {
        $token = $this->app->generateState('m365', ['owner_id' => 42]);

        self::assertTrue($this->app->state->isAppBound($token));
        $payload = $this->app->readState($token, 'm365');
        self::assertIsArray($payload);
        self::assertTrue($payload['app'] ?? null);
        self::assertArrayNotHasKey('browser', $payload);
        self::assertNull($this->app->attribute(BrowserBoundState::COOKIE_ATTRIBUTE));
    }

    public function testTheOwnerRedeemsItWithTheirOwnToken(): void
    {
        $token = $this->app->generateState('m365', ['owner_id' => 42]);
        $this->app->signIn($this->user(42));

        self::assertSame(42, $this->app->state->validateState($token, 'm365')['owner_id'] ?? null);
    }

    /** The system browser that carried the consent is not signed in. */
    public function testNobodySignedInCannotRedeemIt(): void
    {
        $token = $this->app->generateState('m365', ['owner_id' => 42]);

        self::assertNull($this->app->state->validateState($token, 'm365'));
    }

    /**
     * The attack this binding exists for: someone else's consent answer, handed
     * to a person who is signed in to their own account.
     */
    public function testAnotherAccountCannotRedeemIt(): void
    {
        $token = $this->app->generateState('m365', ['owner_id' => 42]);
        $this->app->signIn($this->user(7));

        self::assertNull($this->app->state->validateState($token, 'm365'));
    }

    public function testAStateWithoutAnOwnerIsNeverRedeemed(): void
    {
        $token = $this->app->generateState('m365');
        $this->app->signIn($this->user(42));

        self::assertNull($this->app->state->validateState($token, 'm365'));
    }

    public function testABrowserFlowIsNotAppBoundAndStillNeedsItsCookie(): void
    {
        $browser = new ConsentBrowser();
        $token = $browser->generateState('m365', ['owner_id' => 42]);
        $browser->signIn($this->user(42));
        $browser->switchBrowser();

        self::assertFalse($browser->state->isAppBound($token));
        self::assertNull($browser->state->validateState($token, 'm365'));
    }

    public function testGarbageIsNotAppBound(): void
    {
        self::assertFalse($this->app->state->isAppBound('not-a-state'));
    }

    private function user(int $id): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRoles')->willReturn(['ROLE_USER']);

        return $user;
    }
}
