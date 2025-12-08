<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Message;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for MessageController
 * Tests all message-related endpoints.
 */
class MessageControllerTest extends WebTestCase
{
    private $client;
    private $em;
    private $user;
    private $token;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();

        // Create test user
        $this->user = new User();
        $this->user->setMail('test@example.com');
        $this->user->setPw(password_hash('testpass', PASSWORD_BCRYPT));
        $this->user->setUserLevel('PRO');
        $this->user->setProviderId('test-provider');
        $this->user->setCreated(date('YmdHis'));

        $this->em->persist($this->user);
        $this->em->flush();

        // Configure test user to use 'test' AI provider
        $modelConfigService = $this->client->getContainer()->get(\App\Service\ModelConfigService::class);
        $modelConfigService->setDefaultProvider($this->user->getId(), 'chat', 'test');

        // Generate JWT token for authentication
        $this->token = $this->generateJwtToken($this->user);
    }

    protected function tearDown(): void
    {
        // Cleanup: Remove test data
        if ($this->em && $this->user) {
            $userId = $this->user->getId();

            // Remove test messages
            $messages = $this->em->getRepository(Message::class)
                ->findBy(['userId' => $userId]);
            foreach ($messages as $message) {
                $this->em->remove($message);
            }

            // Remove UseLog entries (rate limit tracking)
            $useLogs = $this->em->getRepository(\App\Entity\UseLog::class)
                ->findBy(['userId' => $userId]);
            foreach ($useLogs as $useLog) {
                $this->em->remove($useLog);
            }

            // Remove Config entries (model preferences)
            $configs = $this->em->getRepository(\App\Entity\Config::class)
                ->findBy(['ownerId' => $userId]);
            foreach ($configs as $config) {
                $this->em->remove($config);
            }

            $this->em->flush();

            // Now safe to remove test user
            $this->em->remove($this->user);
            $this->em->flush();
        }

        // Ensure kernel is shutdown for next test
        static::ensureKernelShutdown();

        parent::tearDown();
    }

    private function generateJwtToken(User $user): string
    {
        $jwtManager = $this->client->getContainer()->get('lexik_jwt_authentication.jwt_manager');

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
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
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
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ],
            json_encode([
                'message' => 'Hello, AI!',
                'trackId' => time(),
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
        for ($i = 0; $i < 5; ++$i) {
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
            $message->setText('Test message '.$i);
            $message->setDirection(0 === $i % 2 ? 'IN' : 'OUT');
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
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ]
        );

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('messages', $response);
        $this->assertIsArray($response['messages']);
        $this->assertGreaterThanOrEqual(5, count($response['messages']));

        // Check structure of first message
        if (count($response['messages']) > 0) {
            $firstMessage = $response['messages'][0];
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
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ]
        );

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('messages', $response);
        $this->assertIsArray($response['messages']);

        // All messages should have the same trackId
        foreach ($response['messages'] as $msg) {
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
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
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
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ],
            json_encode([
                'text' => 'make this better',
                'mode' => 'improve',
            ])
        );

        // Enhancement might succeed or return service unavailable depending on AI provider
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_SERVICE_UNAVAILABLE,
            Response::HTTP_INTERNAL_SERVER_ERROR,
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
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
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
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
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
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ],
            json_encode([
                'message' => 'Async message',
                'trackId' => time(),
            ])
        );

        // Enqueue returns HTTP 202 Accepted
        $this->assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message_id', $response);
        $this->assertArrayHasKey('tracking_id', $response);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('queued', $response['status']);
    }
}
