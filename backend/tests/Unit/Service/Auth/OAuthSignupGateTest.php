<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\OAuthSignupGate;
use App\Service\Auth\SignupBlockedException;
use App\Service\Auth\SignupProtection;
use App\Service\ModelConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class OAuthSignupGateTest extends TestCase
{
    private SignupProtection&MockObject $protection;
    private UserRepository&MockObject $users;
    private ModelConfigService&MockObject $modelConfig;

    protected function setUp(): void
    {
        $this->protection = $this->createMock(SignupProtection::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->modelConfig = $this->createMock(ModelConfigService::class);
    }

    public function testStateCarriesTheHumanFlagOnlyForAVerifiedToken(): void
    {
        $this->protection->method('challengeRequired')->willReturn(true);
        $this->protection->method('verifyHuman')->willReturnCallback(fn (?string $token) => 'tok_ok' === $token);

        $gate = $this->gate();
        $this->assertSame([OAuthSignupGate::STATE_HUMAN => true], $gate->stateExtras(Request::create('/login', 'GET', ['challenge' => 'tok_ok']), 'google'));
        $this->assertSame([], $gate->stateExtras(Request::create('/login', 'GET', ['challenge' => 'tok_bad']), 'google'));
        $this->assertSame([], $gate->stateExtras(Request::create('/login'), 'google'));
    }

    public function testStateStaysPlainWhenNoChallengeProviderIsConfigured(): void
    {
        $this->protection->method('challengeRequired')->willReturn(false);
        $this->protection->expects($this->never())->method('verifyHuman');

        $this->assertSame([], $this->gate()->stateExtras(Request::create('/login', 'GET', ['challenge' => 'anything']), 'github'));
    }

    public function testExistingAccountsSignInWithoutAnyCheck(): void
    {
        $this->users->expects($this->once())->method('findOneBy')->with(['mail' => 'known@example.com'])->willReturn(new User());
        $this->protection->method('challengeRequired')->willReturn(true);
        $this->protection->expects($this->never())->method('assertSignupAllowed');

        $this->gate()->assertNewAccountAllowed('known@example.com', [], '203.0.113.1');
        $this->addToAssertionCount(1);
    }

    public function testNewAccountNeedsTheHumanFlagFromTheStateWhenAProviderIsConfigured(): void
    {
        $this->users->method('findOneBy')->willReturn(null);
        $this->protection->method('challengeRequired')->willReturn(true);
        $this->protection->expects($this->never())->method('assertSignupAllowed');

        try {
            $this->gate()->assertNewAccountAllowed('new@example.com', ['nonce' => 'x'], '203.0.113.1');
            $this->fail('A new OAuth account without the human flag must be refused');
        } catch (SignupBlockedException $e) {
            $this->assertSame(SignupBlockedException::CODE_CHALLENGE_REQUIRED, $e->getReasonCode());
            $this->assertSame('signup_challenge_required', $e->getErrorCodeParam());
        }
    }

    public function testNewAccountWithTheHumanFlagStillGoesThroughTheLimits(): void
    {
        $this->users->method('findOneBy')->willReturn(null);
        $this->protection->method('challengeRequired')->willReturn(true);
        $this->protection->expects($this->once())->method('assertSignupAllowed')->with('203.0.113.1');

        $this->gate()->assertNewAccountAllowed('new@example.com', [OAuthSignupGate::STATE_HUMAN => true], '203.0.113.1');
    }

    public function testNativeStartSkipsTheHumanCheckButKeepsTheLimits(): void
    {
        $this->users->method('findOneBy')->willReturn(null);
        $this->protection->method('challengeRequired')->willReturn(true);
        $this->protection->expects($this->once())->method('assertSignupAllowed')->with('203.0.113.1');

        $this->gate()->assertNewAccountAllowed('new@example.com', [], '203.0.113.1', native: true);
    }

    public function testCompleteNewUserInitialisesTheModelDefaults(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);
        $this->modelConfig->expects($this->once())->method('initializeNewUserDefaults')->with(42);

        $this->gate()->completeNewUser($user);
    }

    private function gate(): OAuthSignupGate
    {
        return new OAuthSignupGate($this->protection, $this->users, $this->modelConfig, new NullLogger());
    }
}
