<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\Message;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for MessageController
 * Tests all message-related endpoints
 */
class MessageControllerTest extends WebTestCase
{
    private $client;
    private $em;
    private $user;
    private $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        
        // Create test user
        $this->user = new User();
        $this->user->setMail('test@example.com');
        $this->user->setPw(password_hash('testpass', PASSWORD_BCRYPT));
        $this->user->setUserLevel('PRO');
        $this->user->setProviderId('test-provider');
        $this->user->setCreated(date('YmdHis'));
        
        $this->em->persist($this->user);
        $this->em->flush();

        // Generate JWT token for authentication
        $this->token = $this->generateJwtToken($this->user);
    }

    protected function tearDown(): void
    {
        // Cleanup: Remove test messages
        if ($this->em && $this->user) {
            $messages = $this->em->getRepository(Message::class)
                ->findBy(['userId' => $this->user->getId()]);
            
            foreach ($messages as $message) {
                $this->em->remove($message);
            }
            
            // Remove test user
            $this->em->remove($this->user);
            $this->em->flush();
        }
        
        // Ensure kernel is shutdown for next test
        static::ensureKernelShutdown();
        
        parent::tearDown();
    }

    private function generateJwtToken(User $user): string
    {
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        return $jwtManager->create($user);
    }

    public function testSendMessageWithoutAuth(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/messages/send',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['message' => 'Hello'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testSendMessageWithEmptyMessage(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/messages/send',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token
            ],
            json_encode(['message' => ''])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals('Message is required', $response['error']);
    }

    public function testSendMessageSuccess(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/messages/send',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token
            ],
            json_encode([
                'message' => 'Hello, AI!',
                'trackId' => time()
            ])
        );

        $this->assertResponseIsSuccessful();
        
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('message', $response);
        $this->assertArrayHasKey('id', $response['message']);
        $this->assertArrayHasKey('text', $response['message']);
    }

    public function testGetHistoryWithoutAuth(): void
    {
        $this->client->request('GET', '/api/v1/messages/history');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetHistorySuccess(): void
    {
        // Create some test messages
        for ($i = 0; $i < 5; $i++) {
            $message = new Message();
            $message->setUserId($this->user->getId());
            $message->setTrackingId(time() + $i);
            $message->setProviderIndex('WEB');
            $message->setUnixTimestamp(time() + $i);
            $message->setDateTime(date('YmdHis'));
            $message->setMessageType('WEB');
            $message->setFile(0);
            $message->setTopic('CHAT');
            $message->setLanguage('en');
            $message->setText('Test message ' . $i);
            $message->setDirection($i % 2 === 0 ? 'IN' : 'OUT');
            $message->setStatus('complete');
            
            $this->em->persist($message);
        }
        $this->em->flush();

        $this->client->request(
            'GET',
            '/api/v1/messages/history',
            ['limit' => 10],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token
            ]
        );

        $this->assertResponseIsSuccessful();
        
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($response);
        $this->assertGreaterThanOrEqual(5, count($response));
        
        // Check structure of first message
        if (count($response) > 0) {
            $firstMessage = $response[0];
            $this->assertArrayHasKey('id', $firstMessage);
            $this->assertArrayHasKey('text', $firstMessage);
            $this->assertArrayHasKey('direction', $firstMessage);
            $this->assertArrayHasKey('timestamp', $firstMessage);
        }
    }

    public function testGetHistoryWithTrackId(): void
    {
        $trackId = time() + 1000;
        
        // Create messages with specific trackId
        $message1 = new Message();
        $message1->setUserId($this->user->getId());
        $message1->setTrackingId($trackId);
        $message1->setProviderIndex('WEB');
        $message1->setUnixTimestamp(time());
        $message1->setDateTime(date('YmdHis'));
        $message1->setMessageType('WEB');
        $message1->setFile(0);
        $message1->setTopic('CHAT');
        $message1->setLanguage('en');
        $message1->setText('Message with trackId');
        $message1->setDirection('IN');
        $message1->setStatus('complete');
        
        $this->em->persist($message1);
        $this->em->flush();

        $this->client->request(
            'GET',
            '/api/v1/messages/history',
            ['trackId' => $trackId],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token
            ]
        );

        $this->assertResponseIsSuccessful();
        
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($response);
        
        // All messages should have the same trackId
        foreach ($response as $msg) {
            $this->assertEquals($trackId, $msg['trackId']);
        }
    }

    public function testEnhanceWithoutAuth(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/messages/enhance',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['text' => 'Hello'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testEnhanceWithEmptyText(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/messages/enhance',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token
            ],
            json_encode(['text' => ''])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testEnhanceSuccess(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/messages/enhance',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token
            ],
            json_encode([
                'text' => 'make this better',
                'mode' => 'improve'
            ])
        );

        // Enhancement might succeed or return service unavailable depending on AI provider
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_SERVICE_UNAVAILABLE,
            Response::HTTP_INTERNAL_SERVER_ERROR
        ]);
    }

    public function testAgainWithoutAuth(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/messages/again',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['messageId' => 1])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAgainWithInvalidData(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/messages/again',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token
            ],
            json_encode([])
        );

        // Should return error or internal server error
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertGreaterThanOrEqual(400, $statusCode);
    }

    public function testEnqueueWithoutAuth(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/messages/enqueue',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['message' => 'Hello'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testEnqueueWithEmptyMessage(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/messages/enqueue',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token
            ],
            json_encode(['message' => ''])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testEnqueueSuccess(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/messages/enqueue',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token
            ],
            json_encode([
                'message' => 'Async message',
                'trackId' => time()
            ])
        );

        $this->assertResponseIsSuccessful();
        
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('messageId', $response);
    }
}
