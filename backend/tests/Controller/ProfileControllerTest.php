<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for ProfileController
 */
class ProfileControllerTest extends WebTestCase
{
    private $client;
    private $em;
    private $user;
    private $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        
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
            'language' => 'en'
        ]);
        
        $this->em->persist($this->user);
        $this->em->flush();

        // Generate JWT token
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        $this->token = $jwtManager->create($this->user);
    }

    protected function tearDown(): void
    {
        if ($this->em && $this->user) {
            $this->em->remove($this->user);
            $this->em->flush();
        }
        
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testGetProfileWithoutAuth(): void
    {
        $this->client->request('GET', '/api/v1/profile');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetProfileWithAuth(): void
    {
        $this->client->request(
            'GET',
            '/api/v1/profile',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]
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
        $this->client->request(
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
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'firstName' => 'Jane',
                'lastName' => 'Smith',
                'phone' => '+4915112345678',
                'city' => 'Berlin'
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
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
                'CONTENT_TYPE' => 'application/json'
            ],
            'invalid json'
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testChangePasswordWithoutAuth(): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/profile/password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'currentPassword' => 'OldPass123!',
                'newPassword' => 'NewPass456!'
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
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'currentPassword' => 'OldPass123!',
                'newPassword' => 'NewSecurePass456!'
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
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'currentPassword' => 'WrongPassword',
                'newPassword' => 'NewPass456!'
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
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'currentPassword' => 'OldPass123!',
                'newPassword' => 'Short1'
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
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'currentPassword' => 'OldPass123!',
                'newPassword' => 'alllowercase'
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
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'currentPassword' => 'OldPass123!'
                // Missing newPassword
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }
}

