<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AppleAuthController;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\AppleClientSecretGenerator;
use App\Service\Auth\AppleIdentityTokenVerifier;
use App\Service\Auth\LoginState;
use App\Service\Auth\OAuthLoginResponder;
use App\Service\Auth\OAuthSignupGate;
use App\Service\Auth\SignupDoor;
use App\Service\Auth\TokenService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which account an Apple sign-in lands on. Only Apple's subject and an
 * address Apple signed as verified may select an existing account.
 */
final class AppleAuthControllerAccountMatchTest extends TestCase
{
    private const SUB = '001234.abcdef.5678';

    private const PLACEHOLDER = self::SUB.'@privaterelay.appleid.local';

    private const VICTIM = 'owner@example.com';

    private UserRepository&MockObject $users;

    private OAuthSignupGate&MockObject $gate;

    protected function setUp(): void
    {
        $this->users = $this->createMock(UserRepository::class);
        $this->gate = $this->createMock(OAuthSignupGate::class);
    }

    public function testNativeSignInDoesNotLandOnAnAccountNamedOnlyInTheRequestBody(): void
    {
        $this->expectANewPlaceholderAccount();

        $response = $this->controller(['email' => null, 'emailVerified' => false])->native($this->nativeRequest(['email' => self::VICTIM]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::PLACEHOLDER, $this->body($response)['user']['email']);
    }

    public function testNativeSignInDoesNotLandOnAnAccountWhoseAddressAppleDidNotVerify(): void
    {
        $this->expectANewPlaceholderAccount();

        $response = $this->controller(['email' => self::VICTIM, 'emailVerified' => false])->native($this->nativeRequest([]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::PLACEHOLDER, $this->body($response)['user']['email']);
    }

    public function testWebCallbackDoesNotLandOnAnAccountNamedOnlyInTheUserField(): void
    {
        $this->expectANewPlaceholderAccount();

        $request = Request::create('/api/v1/auth/apple/callback', 'POST', [
            'code' => 'authorization-code',
            'state' => 'signed-state',
            'user' => json_encode(['name' => ['firstName' => 'Mallory'], 'email' => self::VICTIM], JSON_THROW_ON_ERROR),
        ]);

        $this->controller(['email' => null, 'emailVerified' => false])->callback($request);
    }

    public function testAnAddressAppleVerifiedLinksTheExistingAccount(): void
    {
        $owner = (new User())->setMail(self::VICTIM);
        $this->users->method('findByAppleSub')->willReturn(null);
        $this->users->expects(self::once())->method('findOneBy')->with(['mail' => self::VICTIM])->willReturn($owner);
        $this->gate->expects(self::once())->method('assertAppleSignup')->with(self::SUB, self::VICTIM, self::anything(), self::anything(), true);
        $this->gate->expects(self::never())->method('createAccount');
        // The gate marks the address verified and drops a password set before the proof.
        $this->gate->expects(self::once())->method('finishSignIn')->with($owner, false, self::anything(), true);

        $response = $this->controller(['email' => self::VICTIM, 'emailVerified' => true])->native($this->nativeRequest([]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::SUB, $owner->getUserDetails()['apple_sub']);
    }

    public function testAReturningAppleUserIsFoundBySubjectWithoutAnyAddress(): void
    {
        $known = (new User())->setMail('known@example.com');
        $this->users->expects(self::once())->method('findByAppleSub')->with(self::SUB)->willReturn($known);
        $this->users->expects(self::never())->method('findOneBy');
        $this->gate->expects(self::never())->method('createAccount');

        $response = $this->controller(['email' => null, 'emailVerified' => false])->native($this->nativeRequest(['email' => self::VICTIM]));

        self::assertSame('known@example.com', $this->body($response)['user']['email']);
        self::assertFalse($known->isEmailVerified());
    }

    /**
     * No lookup by address at all, and the new account carries the placeholder
     * built from Apple's subject, unverified.
     */
    private function expectANewPlaceholderAccount(): void
    {
        $this->users->method('findByAppleSub')->willReturn(null);
        $this->users->expects(self::never())->method('findOneBy');
        $this->gate->expects(self::once())->method('assertAppleSignup')->with(self::SUB, null);
        $this->gate->expects(self::once())->method('createAccount')
            ->with(SignupDoor::Apple, self::PLACEHOLDER, false)
            ->willReturn((new User())->setMail(self::PLACEHOLDER));
    }

    /**
     * @param array{email: ?string, emailVerified: bool} $claims
     */
    private function controller(array $claims): AppleAuthController
    {
        $verifier = $this->createStub(AppleIdentityTokenVerifier::class);
        $verifier->method('verify')->willReturn(['sub' => self::SUB, 'isPrivateEmail' => false] + $claims);

        $state = $this->createStub(LoginState::class);
        $state->method('redeem')->willReturn(['browser' => 'bound']);

        $responder = $this->createStub(OAuthLoginResponder::class);
        $responder->method('success')->willReturn(new Response());
        $responder->method('error')->willReturnCallback(static function (string $provider, string $message): Response {
            self::fail('The sign-in was refused: '.$message);
        });

        $secrets = $this->createStub(AppleClientSecretGenerator::class);
        $secrets->method('generate')->willReturn('client-secret');

        return new AppleAuthController(
            new MockHttpClient(new MockResponse(json_encode(['id_token' => 'identity-token'], JSON_THROW_ON_ERROR))),
            $this->users,
            $state,
            $responder,
            $this->gate,
            $secrets,
            $verifier,
            $this->createStub(TokenService::class),
            new NullLogger(),
            'client',
            'http://localhost',
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function nativeRequest(array $extra): Request
    {
        return Request::create('/api/v1/auth/apple/native', 'POST', content: json_encode(['identityToken' => 'identity-token'] + $extra, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
