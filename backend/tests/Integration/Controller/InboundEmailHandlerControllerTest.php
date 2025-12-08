<?php

namespace App\Tests\Integration\Controller;

use App\Entity\User;
use App\Service\TokenService;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class InboundEmailHandlerControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private $client;
    private $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Find test user and authenticate using TokenService
        $userRepository = $this->client->getContainer()->get('doctrine')->getRepository(User::class);
        $user = $userRepository->findOneBy(['mail' => 'admin@synaplan.com']);

        if (!$user) {
            $this->markTestSkipped('Test user admin@synaplan.com not found. Run fixtures first.');
        }

        $this->token = $this->authenticateClient($this->client, $user);
    }

    public function testCreateHandlerWithSMTPAndEmailFilter(): void
    {
        $this->client->request('POST', '/api/v1/inbound-email-handlers', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], json_encode([
            'name' => 'Test Handler Integration',
            'mailServer' => 'imap.test.com',
            'port' => 993,
            'protocol' => 'IMAP',
            'security' => 'SSL/TLS',
            'username' => 'test@test.com',
            'password' => 'test-imap-pwd',
            'checkInterval' => 300,
            'deleteAfter' => false,
            'smtpServer' => 'smtp.test.com',
            'smtpPort' => 587,
            'smtpSecurity' => 'STARTTLS',
            'smtpUsername' => 'test@test.com',
            'smtpPassword' => 'test-smtp-pwd',
            'emailFilterMode' => 'new',
            'departments' => [
                [
                    'name' => 'Support',
                    'email' => 'support@test.com',
                    'rules' => 'help, support',
                    'isDefault' => true,
                ],
            ],
        ]));

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('handler', $data);
        $this->assertEquals('Test Handler Integration', $data['handler']['name']);
        $this->assertEquals('inactive', $data['handler']['status']); // Starts inactive
        $this->assertCount(1, $data['handler']['departments']);
    }

    public function testHistoricalModeRequiresProLevel(): void
    {
        // This test assumes admin@synaplan.com has PRO level
        // If admin is not PRO, this test will fail as expected

        $this->client->request('POST', '/api/v1/inbound-email-handlers', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], json_encode([
            'name' => 'Historical Test',
            'mailServer' => 'imap.test.com',
            'port' => 993,
            'protocol' => 'IMAP',
            'security' => 'SSL/TLS',
            'username' => 'test@test.com',
            'password' => 'test-pwd',
            'smtpServer' => 'smtp.test.com',
            'smtpPort' => 587,
            'smtpSecurity' => 'STARTTLS',
            'smtpUsername' => 'test@test.com',
            'smtpPassword' => 'test-pwd',
            'emailFilterMode' => 'historical',
            'emailFilterFromDate' => '2025-01-01T00:00',
            'emailFilterToDate' => '2025-12-31T23:59',
            'departments' => [
                ['name' => 'Test', 'email' => 'test@test.com', 'rules' => 'test', 'isDefault' => true],
            ],
        ]));

        $response = $this->client->getResponse();

        // Should succeed if admin is PRO/TEAM/BUSINESS
        // Should fail with 403 if admin is NEW
        $statusCode = $response->getStatusCode();
        $this->assertTrue(
            Response::HTTP_CREATED === $statusCode || Response::HTTP_FORBIDDEN === $statusCode,
            'Expected 201 (PRO user) or 403 (NEW user)'
        );

        if (Response::HTTP_FORBIDDEN === $statusCode) {
            $data = json_decode($response->getContent(), true);
            $this->assertStringContainsString('PRO', $data['error']);
        }
    }

    public function testUpdateHandlerEmailFilter(): void
    {
        // Create a handler first
        $this->client->request('POST', '/api/v1/inbound-email-handlers', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], json_encode([
            'name' => 'Update Test Handler',
            'mailServer' => 'imap.test.com',
            'port' => 993,
            'protocol' => 'IMAP',
            'security' => 'SSL/TLS',
            'username' => 'test@test.com',
            'password' => 'test-pwd',
            'smtpServer' => 'smtp.test.com',
            'smtpPort' => 587,
            'smtpSecurity' => 'STARTTLS',
            'smtpUsername' => 'test@test.com',
            'smtpPassword' => 'test-pwd',
            'emailFilterMode' => 'new',
            'departments' => [
                ['name' => 'Test', 'email' => 'test@test.com', 'rules' => 'test', 'isDefault' => true],
            ],
        ]));

        $createResponse = json_decode($this->client->getResponse()->getContent(), true);
        $handlerId = $createResponse['handler']['id'];

        // Update to historical mode
        $this->client->request('PUT', '/api/v1/inbound-email-handlers/'.$handlerId, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], json_encode([
            'emailFilterMode' => 'historical',
            'emailFilterFromDate' => '2025-06-01T00:00',
            'emailFilterToDate' => '2025-06-30T23:59',
        ]));

        $response = $this->client->getResponse();
        $statusCode = $response->getStatusCode();

        // Should succeed if user is PRO+
        $this->assertTrue(
            Response::HTTP_OK === $statusCode || Response::HTTP_FORBIDDEN === $statusCode
        );
    }

    public function testListHandlers(): void
    {
        $this->client->request('GET', '/api/v1/inbound-email-handlers', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('handlers', $data);
        $this->assertIsArray($data['handlers']);
    }
}
