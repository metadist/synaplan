<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\VerificationToken;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        // Cleanup test users
        $testEmails = ['newuser@test.com', 'logintest@test.com'];
        foreach ($testEmails as $email) {
            $user = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
            if ($user) {
                // Remove verification tokens
                $tokens = $this->em->getRepository(VerificationToken::class)
                    ->findBy(['userId' => $user->getId()]);
                foreach ($tokens as $token) {
                    $this->em->remove($token);
                }

                $this->em->remove($user);
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

        $this->assertArrayHasKey('token', $responseData);
        $this->assertArrayHasKey('user', $responseData);
        $this->assertNotEmpty($responseData['token']);
        $this->assertEquals('logintest@test.com', $responseData['user']['email']);
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
}
