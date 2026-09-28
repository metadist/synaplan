<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\TokenService;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for ProfileController.
 */
class ProfileControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private $client;
    private $em;
    private $user;
    private $token;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();

        // Create test user (local auth, not OAuth)
        $this->user = new User();
        $this->user->setMail('profiletest@example.com');
        $this->user->setPw(password_hash('OldPass123!', PASSWORD_BCRYPT));
        $this->user->setUserLevel('PRO');
        $this->user->setProviderId('local'); // Local auth = can change password
        $this->user->setCreated(date('YmdHis'));
        $this->user->setUserDetails([
            'firstName' => 'John',
            'lastName' => 'Doe',
            'language' => 'en',
        ]);

        $this->em->persist($this->user);
        $this->em->flush();

        // Generate access token using TokenService
        $this->token = $this->authenticateClient($this->client, $this->user);
    }

    protected function tearDown(): void
    {
        if ($this->user) {
            // Get a fresh entity manager if the current one is closed
            if (!$this->em || !$this->em->isOpen()) {
                self::bootKernel();
                $this->em = self::getContainer()->get('doctrine')->getManager();
            }

            // Find the user again if it's detached
            $user = $this->em->find(User::class, $this->user->getId());
            if ($user) {
                $this->em->remove($user);
                $this->em->flush();
            }
        }

        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testGetProfileWithoutAuth(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/api/v1/profile');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetProfileWithAuth(): void
    {
        $this->client->request(
            'GET',
            '/api/v1/profile',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('success', $responseData);
        $this->assertTrue($responseData['success']);

        $this->assertArrayHasKey('profile', $responseData);
        $profile = $responseData['profile'];

        $this->assertEquals('profiletest@example.com', $profile['email']);
        $this->assertEquals('John', $profile['firstName']);
        $this->assertEquals('Doe', $profile['lastName']);
        $this->assertEquals('en', $profile['language']);
    }

    public function testUpdateProfileWithoutAuth(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request(
            'PUT',
            '/api/v1/profile',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['firstName' => 'Jane'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testUpdateProfileWithAuth(): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/profile',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'firstName' => 'Jane',
                'lastName' => 'Smith',
                'phone' => '+4915112345678',
                'city' => 'Berlin',
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('success', $responseData);
        $this->assertTrue($responseData['success']);

        // Verify changes were saved
        $this->em->refresh($this->user);
        $details = $this->user->getUserDetails();

        $this->assertEquals('Jane', $details['firstName']);
        $this->assertEquals('Smith', $details['lastName']);
        $this->assertEquals('+4915112345678', $details['phone']);
        $this->assertEquals('Berlin', $details['city']);
    }

    public function testUpdateProfileWithInvalidJson(): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/profile',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
                'CONTENT_TYPE' => 'application/json',
            ],
            'invalid json'
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testChangePasswordWithoutAuth(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request(
            'PUT',
            '/api/v1/profile/password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'currentPassword' => 'OldPass123!',
                'newPassword' => 'NewPass456!',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testChangePasswordWithCorrectCurrentPassword(): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/profile/password',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'currentPassword' => 'OldPass123!',
                'newPassword' => 'NewSecurePass456!',
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('success', $responseData);
        $this->assertTrue($responseData['success']);
    }

    public function testChangePasswordWithIncorrectCurrentPassword(): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/profile/password',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'currentPassword' => 'WrongPassword',
                'newPassword' => 'NewPass456!',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testChangePasswordWithTooShortPassword(): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/profile/password',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'currentPassword' => 'OldPass123!',
                'newPassword' => 'Short1',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testChangePasswordWithWeakPassword(): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/profile/password',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'currentPassword' => 'OldPass123!',
                'newPassword' => 'alllowercase',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testChangePasswordWithMissingFields(): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/profile/password',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'currentPassword' => 'OldPass123!',
                // Missing newPassword
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testAdminCanChangeOwnSignInEmail(): void
    {
        $this->user->setUserLevel('ADMIN');
        $this->user->setEmailVerified(true);
        $this->em->flush();

        $this->putProfile([
            'email' => 'Admin.New@Example.com',
            'currentPassword' => 'OldPass123!',
            'firstName' => 'Ada',
        ]);

        $this->assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('admin.new@example.com', $payload['email']);

        $this->em->refresh($this->user);
        $this->assertSame('admin.new@example.com', $this->user->getMail());
        $this->assertTrue($this->user->isEmailVerified());
        $this->assertSame('Ada', $this->user->getUserDetails()['firstName']);
        $this->assertSame('ADMIN', $this->user->getUserLevel());
    }

    public function testUnchangedEmailDoesNotRequirePassword(): void
    {
        $this->putProfile([
            'email' => 'ProfileTest@Example.com',
            'firstName' => 'Jane',
        ]);

        $this->assertResponseIsSuccessful();
        $this->em->refresh($this->user);
        $this->assertSame('profiletest@example.com', $this->user->getMail());
        $this->assertSame('Jane', $this->user->getUserDetails()['firstName']);
    }

    public function testEmailChangeWithWrongPasswordSavesNothing(): void
    {
        $this->putProfile([
            'email' => 'other@example.com',
            'currentPassword' => 'WrongPass123!',
            'firstName' => 'ShouldNotStick',
            'timezone' => 'Asia/Kolkata',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('email_password_incorrect', $payload['error']);

        $this->em->refresh($this->user);
        $this->assertSame('profiletest@example.com', $this->user->getMail());
        $this->assertSame('John', $this->user->getUserDetails()['firstName']);
        $this->assertArrayNotHasKey('timezone', $this->user->getUserDetails());
    }

    public function testEmailChangeRequiresCurrentPassword(): void
    {
        $this->putProfile([
            'email' => 'other@example.com',
            'firstName' => 'ShouldNotStick',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('email_password_required', $payload['error']);
        $this->em->refresh($this->user);
        $this->assertSame('John', $this->user->getUserDetails()['firstName']);
    }

    public function testEmailChangeRejectsAddressAlreadyInUse(): void
    {
        $other = new User();
        $other->setMail('Taken@Example.com');
        $other->setPw(password_hash('OtherPass123!', PASSWORD_BCRYPT));
        $other->setUserLevel('NEW');
        $other->setProviderId('local');
        $other->setCreated(date('YmdHis'));
        $other->setUserDetails([]);
        $this->em->persist($other);
        $this->em->flush();

        try {
            $this->putProfile([
                'email' => 'taken@example.com',
                'currentPassword' => 'OldPass123!',
                'firstName' => 'ShouldNotStick',
            ]);

            $this->assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
            $payload = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertSame('email_taken', $payload['error']);
            $this->em->refresh($this->user);
            $this->assertSame('profiletest@example.com', $this->user->getMail());
            $this->assertSame('John', $this->user->getUserDetails()['firstName']);
        } finally {
            $this->removeUser($other);
        }
    }

    public function testExternalAuthCannotChangeSignInEmail(): void
    {
        $external = new User();
        $external->setMail('google-user@example.com');
        $external->setPw(null);
        $external->setUserLevel('PRO');
        $external->setProviderId('google');
        $external->setCreated(date('YmdHis'));
        $external->setUserDetails(['firstName' => 'Grace']);
        $this->em->persist($external);
        $this->em->flush();
        $token = $this->authenticateClient($this->client, $external);

        try {
            $this->client->request(
                'PUT',
                '/api/v1/profile',
                [],
                [],
                [
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                    'CONTENT_TYPE' => 'application/json',
                ],
                json_encode([
                    'email' => 'grace-new@example.com',
                    'currentPassword' => 'OldPass123!',
                    'firstName' => 'ShouldNotStick',
                ])
            );

            $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $payload = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertSame('email_managed', $payload['error']);
            $this->em->refresh($external);
            $this->assertSame('google-user@example.com', $external->getMail());
            $this->assertSame('Grace', $external->getUserDetails()['firstName']);
        } finally {
            $this->removeUser($external);
        }
    }

    public function testInvalidTimezoneSavesNothing(): void
    {
        $this->putProfile([
            'timezone' => 'Not/AZone',
            'firstName' => 'ShouldNotStick',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('timezone_invalid', $payload['error']);
        $this->em->refresh($this->user);
        $this->assertSame('John', $this->user->getUserDetails()['firstName']);
    }

    public function testStoredEmailIsCanonicalizedWithoutAPassword(): void
    {
        $this->user->setMail('ProfileTest@Example.com');
        $this->em->flush();

        $this->putProfile([
            'email' => ' profiletest@example.com ',
            'firstName' => 'Jane',
        ]);

        $this->assertResponseIsSuccessful();
        $this->em->refresh($this->user);
        $this->assertSame('profiletest@example.com', $this->user->getMail());
        $this->assertSame('Jane', $this->user->getUserDetails()['firstName']);
    }

    public function testReservedProcessorEmailIsRejected(): void
    {
        $this->putProfile([
            'email' => 'Guest-Processor@synaplan.internal',
            'currentPassword' => 'OldPass123!',
            'firstName' => 'ShouldNotStick',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('email_reserved', $payload['error']);
        $this->em->refresh($this->user);
        $this->assertSame('profiletest@example.com', $this->user->getMail());
        $this->assertSame('John', $this->user->getUserDetails()['firstName']);
    }

    public function testOffsetAndAbbreviationTimezonesAreRejected(): void
    {
        foreach (['+05:45', 'CST'] as $timezone) {
            $this->putProfile([
                'timezone' => $timezone,
                'firstName' => 'ShouldNotStick',
            ]);

            $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
            $payload = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertSame('timezone_invalid', $payload['error']);
        }

        $this->em = $this->client->getContainer()->get('doctrine')->getManager();
        $user = $this->em->find(User::class, $this->user->getId());
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('John', $user->getUserDetails()['firstName']);
        $this->assertArrayNotHasKey('timezone', $user->getUserDetails());
    }

    public function testBackwardCompatibleTimezoneAliasIsStored(): void
    {
        $this->putProfile([
            'timezone' => 'Asia/Calcutta',
        ]);

        $this->assertResponseIsSuccessful();
        $this->em->refresh($this->user);
        $this->assertSame('Asia/Calcutta', $this->user->getUserDetails()['timezone']);
    }

    public function testFractionalHourTimezoneIsStored(): void
    {
        $this->putProfile([
            'timezone' => 'Asia/Kathmandu',
        ]);

        $this->assertResponseIsSuccessful();
        $this->em->refresh($this->user);
        $this->assertSame('Asia/Kathmandu', $this->user->getUserDetails()['timezone']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function putProfile(array $payload): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/profile',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode($payload)
        );
    }

    private function removeUser(User $user): void
    {
        if (!$this->em->isOpen()) {
            return;
        }

        $managed = $this->em->find(User::class, $user->getId());
        if ($managed instanceof User) {
            $this->em->remove($managed);
            $this->em->flush();
        }
    }
}
