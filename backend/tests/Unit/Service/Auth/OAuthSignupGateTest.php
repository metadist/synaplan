<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\OAuthSignupGate;
use App\Service\Auth\SignupAdmission;
use App\Service\Auth\SignupBlockedException;
use App\Service\Auth\SignupProtection;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

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

    public function testExistingGoogleAccountSignsInWithoutTheHumanCheck(): void
    {
        $this->users->expects($this->once())->method('findOneBy')->with(['mail' => 'known@example.com'])->willReturn(new User());
        $this->protection->method('challengeRequired')->willReturn(true);
        $this->protection->expects($this->never())->method('assertSignupAllowed');

        $this->gate()->assertGoogleSignup('known@example.com', ['nonce' => 'n1'], '203.0.113.1');
        $this->addToAssertionCount(1);
    }

    public function testReturningAppleUserIsFoundBySubjectWhenTheEmailIsMissing(): void
    {
        $this->users->expects($this->once())->method('findByAppleSub')->with('apple-sub-1')->willReturn(new User());
        $this->users->expects($this->never())->method('findOneBy');
        $this->protection->method('challengeRequired')->willReturn(true);
        $this->protection->expects($this->never())->method('assertSignupAllowed');

        $this->gate()->assertAppleSignup('apple-sub-1', null, ['nonce' => 'n-apple'], '203.0.113.1');
        $this->addToAssertionCount(1);
    }

    public function testReturningGitHubUserIsFoundByIdWhenTheEmailIsHidden(): void
    {
        $this->users->method('findOneBy')->willReturn(null);
        $this->users->expects($this->once())->method('findByGithubId')->with(99)->willReturn(new User());
        $this->protection->method('challengeRequired')->willReturn(true);
        $this->protection->expects($this->never())->method('assertSignupAllowed');

        $this->gate()->assertGithubSignup(null, 99, ['nonce' => 'n-gh'], '203.0.113.1');
        $this->addToAssertionCount(1);
    }

    public function testNewAccountNeedsTheHumanFlagFromTheStateWhenAProviderIsConfigured(): void
    {
        $this->users->method('findOneBy')->willReturn(null);
        $this->protection->method('challengeRequired')->willReturn(true);
        $this->protection->expects($this->never())->method('assertSignupAllowed');

        try {
            $this->gate()->assertGoogleSignup('new@example.com', ['nonce' => 'x'], '203.0.113.1');
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

        $this->gate()->assertGoogleSignup('new@example.com', ['nonce' => 'n2', OAuthSignupGate::STATE_HUMAN => true], '203.0.113.1');
    }

    public function testTheSameNonceCannotCreateASecondAccount(): void
    {
        $this->users->method('findOneBy')->willReturn(null);
        $this->protection->method('challengeRequired')->willReturn(true);
        $this->protection->expects($this->once())->method('assertSignupAllowed');
        $gate = $this->gate();
        $state = ['nonce' => 'once', OAuthSignupGate::STATE_HUMAN => true];

        $gate->assertGoogleSignup('first@example.com', $state, '203.0.113.1');

        $this->expectException(SignupBlockedException::class);
        $gate->assertGoogleSignup('second@example.com', $state, '203.0.113.2');
    }

    public function testABrowserNativeFlagDoesNotSkipTheHumanCheck(): void
    {
        $this->users->method('findByAppleSub')->willReturn(null);
        $this->users->method('findOneBy')->willReturn(null);
        $this->protection->method('challengeRequired')->willReturn(true);
        $this->protection->expects($this->never())->method('assertSignupAllowed');

        $this->expectException(SignupBlockedException::class);
        $this->gate()->assertAppleSignup('new-sub', 'new@example.com', ['nonce' => 'n3', 'native' => true], '203.0.113.1');
    }

    public function testVerifiedAppleNativeSheetSkipsTheHumanCheckButKeepsTheLimits(): void
    {
        $this->users->method('findByAppleSub')->willReturn(null);
        $this->users->method('findOneBy')->willReturn(null);
        $this->protection->method('challengeRequired')->willReturn(true);
        $this->protection->expects($this->once())->method('assertSignupAllowed')->with('203.0.113.1');

        $this->gate()->assertAppleSignup('new-sub', 'new@example.com', [], '203.0.113.1', true);
    }

    public function testCompleteNewUserInitialisesTheModelDefaults(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);
        $this->modelConfig->expects($this->once())->method('initializeNewUserDefaults')->with(42);

        $this->gate()->completeNewUser($user);
    }

    public function testANewOAuthAccountIsAdmittedInsideOneTransaction(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $phase = 'before';
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($user);
        $em->expects($this->once())->method('flush')->willReturnCallback(function () use (&$phase): void {
            self::assertSame('inside', $phase);
        });
        $em->expects($this->once())->method('wrapInTransaction')->willReturnCallback(function (callable $fn) use (&$phase): void {
            $phase = 'inside';
            $fn();
        });
        $admission = $this->createMock(SignupAdmission::class);
        $admission->method('resolve')->willThrowException(SignupBlockedException::codeInvalid());

        $gate = new OAuthSignupGate(
            $this->protection,
            $this->users,
            $this->modelConfig,
            new RateLimiterFactory([
                'id' => 'oauth_signup_nonce',
                'policy' => 'sliding_window',
                'limit' => 1,
                'interval' => '10 minutes',
            ], new InMemoryStorage()),
            new NullLogger(),
            $admission,
            $em,
        );

        $this->expectException(SignupBlockedException::class);
        $gate->finishSignIn($user, true, ['code' => 'ABCD2345']);
    }

    private function gate(): OAuthSignupGate
    {
        $nonces = new RateLimiterFactory([
            'id' => 'oauth_signup_nonce',
            'policy' => 'sliding_window',
            'limit' => 1,
            'interval' => '10 minutes',
        ], new InMemoryStorage());

        $admission = $this->createMock(SignupAdmission::class);
        $admission->method('resolve')->willReturn(null);

        return new OAuthSignupGate($this->protection, $this->users, $this->modelConfig, $nonces, new NullLogger(), $admission, $this->createMock(EntityManagerInterface::class));
    }
}
