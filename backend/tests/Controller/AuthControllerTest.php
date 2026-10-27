<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\EmailVerificationAttempt;
use App\Entity\Token;
use App\Entity\User;
use App\Entity\VerificationToken;
use App\Service\Chat\GuestSessionService;
use App\Service\Email\InternalEmailService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for AuthController.
 */
class AuthControllerTest extends WebTestCase
{
    private $client;
    private $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        // Cleanup test users
        $testEmails = [
            'newuser@test.com',
            'logintest@test.com',
            'logintest2@test.com',
            'logintest3@test.com',
            'unverified@test.com',
            'existing@test.com',
            'languser@test.com',
            'meuser@test.com',
            'resend-unconfigured@test.com',
            'resend-failed@test.com',
            'resend-unknown@test.com',
            'resend-sent@test.com',
        ];
        foreach ($testEmails as $email) {
            $user = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
            if ($user) {
                // Remove auth tokens (BTOKENS table)
                $authTokens = $this->em->getRepository(Token::class)
                    ->findBy(['userId' => $user->getId()]);
                foreach ($authTokens as $token) {
                    $this->em->remove($token);
                }

                // Remove verification tokens
                $verificationTokens = $this->em->getRepository(VerificationToken::class)
                    ->findBy(['userId' => $user->getId()]);
                foreach ($verificationTokens as $token) {
                    $this->em->remove($token);
                }

                $this->em->remove($user);
            }

            $attempt = $this->em->getRepository(EmailVerificationAttempt::class)->findOneBy(['email' => $email]);
            if ($attempt) {
                $this->em->remove($attempt);
            }
        }
        $this->em->flush();

        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testRegisterWithValidData(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'newuser@test.com',
                'password' => 'SecurePass123!',
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('success', $responseData);
        $this->assertTrue($responseData['success']);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertStringContainsString('verification email', $responseData['message']);

        // Verify user was created in database
        $user = $this->em->getRepository(User::class)
            ->findOneBy(['mail' => 'newuser@test.com']);

        $this->assertNotNull($user);
        $this->assertFalse($user->isEmailVerified());
        $this->assertEquals('WEB', $user->getType());
    }

    public function testRegisterStoresLanguageBeforeTheVerificationEmail(): void
    {
        $email = $this->createMock(InternalEmailService::class);
        $email->expects($this->once())
            ->method('sendVerificationEmail')
            ->with(
                'languser@test.com',
                $this->callback(static fn (mixed $token): bool => is_string($token) && '' !== $token),
                'de',
            );
        static::getContainer()->set(InternalEmailService::class, $email);

        $this->client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'languser@test.com',
                'password' => 'SecurePass123!',
                'language' => 'de',
            ])
        );

        $this->assertResponseIsSuccessful();

        $user = $this->em->getRepository(User::class)->findOneBy(['mail' => 'languser@test.com']);
        $this->assertNotNull($user);
        $this->assertSame('de', $user->getUserDetails()['language'] ?? null);
        $this->assertSame('de', $user->getPreferredLanguage());
    }

    public function testAuthMeReturnsNullLanguageWhenUnset(): void
    {
        $user = new User();
        $user->setMail('meuser@test.com');
        $user->setPw(password_hash('TestPass123!', PASSWORD_BCRYPT));
        $user->setUserLevel('PRO');
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $user->setUserDetails([]);

        $this->em->persist($user);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'meuser@test.com',
                'password' => 'TestPass123!',
            ])
        );

        $this->assertResponseIsSuccessful();
        $login = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('language', $login['user']);
        $this->assertNull($login['user']['language']);

        $this->client->request('GET', '/api/v1/auth/me');
        $this->assertResponseIsSuccessful();
        $me = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('language', $me['user']);
        $this->assertNull($me['user']['language']);
    }

    public function testRegisterWithExistingEmail(): void
    {
        // Create existing user
        $existingUser = new User();
        $existingUser->setMail('existing@test.com');
        $existingUser->setPw(password_hash('password', PASSWORD_BCRYPT));
        $existingUser->setUserLevel('NEW');
        $existingUser->setProviderId('local');
        $existingUser->setCreated(date('YmdHis'));

        $this->em->persist($existingUser);
        $this->em->flush();

        // Try to register with same email
        $this->client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'existing@test.com',
                'password' => 'NewPass123!',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($responseData['success']);
        $this->assertStringContainsString('verification email', $responseData['message']);

        // Cleanup
        $this->em->remove($existingUser);
        $this->em->flush();
    }

    public function testRegisterDoesNotCreateTheReservedGuestProcessorEmail(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => GuestSessionService::PROCESSING_USER_EMAIL,
                'password' => 'SecurePass123!',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($responseData['success']);

        $user = $this->em->getRepository(User::class)
            ->findOneBy(['mail' => GuestSessionService::PROCESSING_USER_EMAIL]);
        $this->assertNull($user);
    }

    public function testRegisterWithInvalidEmail(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'invalid-email',
                'password' => 'SecurePass123!',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testLoginWithValidCredentials(): void
    {
        // Create test user
        $user = new User();
        $user->setMail('logintest@test.com');
        $user->setPw(password_hash('TestPass123!', PASSWORD_BCRYPT));
        $user->setUserLevel('PRO');
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);

        $this->em->persist($user);
        $this->em->flush();

        // Login
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'logintest@test.com',
                'password' => 'TestPass123!',
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('success', $responseData);
        $this->assertTrue($responseData['success']);
        $this->assertArrayHasKey('user', $responseData);
        $this->assertEquals('logintest@test.com', $responseData['user']['email']);

        // Verify auth cookies are set
        $response = $this->client->getResponse();
        $cookies = $response->headers->getCookies();
        $cookieNames = array_map(fn ($cookie) => $cookie->getName(), $cookies);
        $this->assertContains('access_token', $cookieNames);
        $this->assertContains('refresh_token', $cookieNames);

        $refreshCookie = $this->cookieNamed('refresh_token');
        $this->assertNotNull($refreshCookie);
        $ttl = $refreshCookie->getExpiresTime() - time();
        $this->assertGreaterThan(29 * 86400, $ttl);
        $this->assertLessThanOrEqual(30 * 86400 + 5, $ttl);
    }

    /**
     * Regression: the frontend replaces its in-memory user object wholesale
     * with whatever `user` payload the most recent auth response carried, so
     * `firstName` must survive both login AND the subsequent access-token
     * refresh — otherwise a name shown right after login silently disappears
     * once the token refreshes (observed on the native app, which refreshes
     * eagerly on launch).
     */
    public function testLoginAndRefreshResponsesIncludeFirstName(): void
    {
        $user = new User();
        $user->setMail('logintest3@test.com');
        $user->setPw(password_hash('TestPass123!', PASSWORD_BCRYPT));
        $user->setUserLevel('PRO');
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $user->setUserDetails(['firstName' => 'Cristian']);

        $this->em->persist($user);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'logintest3@test.com',
                'password' => 'TestPass123!',
            ])
        );

        $this->assertResponseIsSuccessful();
        $loginData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Cristian', $loginData['user']['firstName']);

        $this->client->request('POST', '/api/v1/auth/refresh');

        $this->assertResponseIsSuccessful();
        $refreshData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Cristian', $refreshData['user']['firstName']);

        $refreshCookie = $this->cookieNamed('refresh_token');
        $this->assertNotNull($refreshCookie, 'refresh must rewrite the sliding refresh cookie');
        $ttl = $refreshCookie->getExpiresTime() - time();
        $this->assertGreaterThan(29 * 86400, $ttl);
        $this->assertLessThanOrEqual(30 * 86400 + 5, $ttl);

        // Cleanup relies on tearDown()'s email lookup: the kernel reboots
        // between requests above, so the original `$user` reference is
        // detached and cannot be removed directly here.
    }

    public function testLoginWithInvalidPassword(): void
    {
        // Create test user
        $user = new User();
        $user->setMail('logintest2@test.com');
        $user->setPw(password_hash('CorrectPass123!', PASSWORD_BCRYPT));
        $user->setUserLevel('PRO');
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);

        $this->em->persist($user);
        $this->em->flush();

        // Try login with wrong password
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'logintest2@test.com',
                'password' => 'WrongPassword!',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // Cleanup
        $this->em->remove($user);
        $this->em->flush();
    }

    public function testLoginWithNonExistentUser(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'nonexistent@test.com',
                'password' => 'AnyPassword123!',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testLoginWithUnverifiedEmail(): void
    {
        // Create unverified user
        $user = new User();
        $user->setMail('unverified@test.com');
        $user->setPw(password_hash('TestPass123!', PASSWORD_BCRYPT));
        $user->setUserLevel('NEW');
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(false);

        $this->em->persist($user);
        $this->em->flush();

        // Try to login
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'unverified@test.com',
                'password' => 'TestPass123!',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // Cleanup
        $this->em->remove($user);
        $this->em->flush();
    }

    public function testLoginRequiresEmailAndPassword(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'test@test.com',
                // Missing password
            ])
        );

        // Missing required fields returns 400
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testRegisterWithWeakPassword(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'weakpass@test.com',
                'password' => 'weak',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testResendWithoutMailDoesNotReportSuccessOrSpendTheAllowance(): void
    {
        $this->createUnverifiedUser('resend-unconfigured@test.com');

        for ($i = 0; $i < 6; ++$i) {
            $this->client->request(
                'POST',
                '/api/v1/auth/resend-verification',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['email' => 'resend-unconfigured@test.com'])
            );

            $this->assertResponseIsSuccessful();
            $body = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertFalse($body['success']);
            $this->assertFalse($body['mailDelivered']);
            $this->assertStringContainsString('No verification email was sent', $body['message']);
        }

        $this->client->request(
            'POST',
            '/api/v1/auth/resend-verification',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'resend-unknown@test.com'])
        );
        $this->assertResponseIsSuccessful();
        $unknown = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($unknown['mailDelivered']);

        $user = $this->em->getRepository(User::class)->findOneBy(['mail' => 'resend-unconfigured@test.com']);
        $this->assertSame([], $this->em->getRepository(VerificationToken::class)->findBy(['userId' => $user->getId()]));
        $this->assertNull($this->em->getRepository(EmailVerificationAttempt::class)->findOneBy(['email' => 'resend-unconfigured@test.com']));
        $this->assertNull($this->em->getRepository(EmailVerificationAttempt::class)->findOneBy(['email' => 'resend-unknown@test.com']));
    }

    public function testResendWithMailReportsDeliveryAndThenWaits(): void
    {
        $previous = $_ENV['MAILER_DSN'] ?? null;
        $previousMax = $_ENV['EMAIL_VERIFICATION_MAX_ATTEMPTS'] ?? null;
        $previousCooldown = $_ENV['EMAIL_VERIFICATION_COOLDOWN_MINUTES'] ?? null;
        $_ENV['MAILER_DSN'] = 'smtp://127.0.0.1:1025';
        $_ENV['EMAIL_VERIFICATION_MAX_ATTEMPTS'] = '5';
        $_ENV['EMAIL_VERIFICATION_COOLDOWN_MINUTES'] = '2';
        $this->client->disableReboot();
        $mail = $this->createMock(InternalEmailService::class);
        $mail->expects($this->once())->method('sendVerificationEmail')->willReturn(true);
        static::getContainer()->set(InternalEmailService::class, $mail);

        try {
            $this->createUnverifiedUser('resend-sent@test.com');

            $this->client->request(
                'POST',
                '/api/v1/auth/resend-verification',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['email' => 'resend-sent@test.com'])
            );

            $this->assertResponseIsSuccessful();
            $sent = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertTrue($sent['success']);
            $this->assertTrue($sent['mailDelivered']);
            $this->assertSame(4, $sent['remainingAttempts']);
            $this->assertSame(2, $sent['cooldownMinutes']);

            $this->client->request(
                'POST',
                '/api/v1/auth/resend-verification',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['email' => 'resend-sent@test.com'])
            );

            $this->assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
            $limited = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertSame(4, $limited['remainingAttempts']);
            $this->assertGreaterThan(0, $limited['waitSeconds']);

            $attempt = $this->em->getRepository(EmailVerificationAttempt::class)->findOneBy(['email' => 'resend-sent@test.com']);
            $this->assertNotNull($attempt);
            $this->assertSame(1, $attempt->getAttempts());
        } finally {
            $this->restoreEnv('MAILER_DSN', $previous);
            $this->restoreEnv('EMAIL_VERIFICATION_MAX_ATTEMPTS', $previousMax);
            $this->restoreEnv('EMAIL_VERIFICATION_COOLDOWN_MINUTES', $previousCooldown);
        }
    }

    public function testResendFailureDoesNotSpendTheAllowance(): void
    {
        $previous = $_ENV['MAILER_DSN'] ?? null;
        $_ENV['MAILER_DSN'] = 'smtp://127.0.0.1:1025';
        $this->client->disableReboot();
        $mail = $this->createStub(InternalEmailService::class);
        $mail->method('sendVerificationEmail')->willThrowException(new \RuntimeException('smtp down'));
        static::getContainer()->set(InternalEmailService::class, $mail);

        try {
            $this->createUnverifiedUser('resend-failed@test.com');

            for ($i = 0; $i < 2; ++$i) {
                $this->client->request(
                    'POST',
                    '/api/v1/auth/resend-verification',
                    [],
                    [],
                    ['CONTENT_TYPE' => 'application/json'],
                    json_encode(['email' => 'resend-failed@test.com'])
                );

                $this->assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $this->assertNull($this->em->getRepository(EmailVerificationAttempt::class)->findOneBy(['email' => 'resend-failed@test.com']));
            $user = $this->em->getRepository(User::class)->findOneBy(['mail' => 'resend-failed@test.com']);
            $this->assertSame([], $this->em->getRepository(VerificationToken::class)->findBy(['userId' => $user->getId()]));
        } finally {
            $this->restoreEnv('MAILER_DSN', $previous);
        }
    }

    private function restoreEnv(string $name, ?string $value): void
    {
        if (null === $value) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $value;
        }
    }

    private function createUnverifiedUser(string $email): User
    {
        $user = new User();
        $user->setMail($email);
        $user->setPw(password_hash('SecurePass123!', PASSWORD_BCRYPT));
        $user->setUserLevel('NEW');
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(false);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function cookieNamed(string $name): ?Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }
}
