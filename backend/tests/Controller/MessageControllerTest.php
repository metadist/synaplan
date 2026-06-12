<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Service\TokenService;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for MessageController
 * Tests all message-related endpoints.
 */
class MessageControllerTest extends WebTestCase
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

        // Use fixture user demo@synaplan.com (PRO level)
        $this->user = $this->em->getRepository(User::class)->findOneBy(['mail' => 'demo@synaplan.com']);

        if (!$this->user) {
            $this->markTestSkipped('Test user demo@synaplan.com not found. Run fixtures first.');
        }

        // Configure test user to use 'test' AI provider
        $modelConfigService = $this->client->getContainer()->get(\App\Service\ModelConfigService::class);
        $modelConfigService->setDefaultProvider($this->user->getId(), 'chat', 'test');

        // Generate access token using TokenService
        $this->token = $this->authenticateClient($this->client, $this->user);
    }

    protected function tearDown(): void
    {
        // Cleanup: Remove test data (but keep fixture user)
        if ($this->user) {
            // Get a fresh entity manager if the current one is closed
            if (!$this->em || !$this->em->isOpen()) {
                self::bootKernel();
                $this->em = self::getContainer()->get('doctrine')->getManager();
            }

            $userId = $this->user->getId();

            // Collect IDs first, then remove by re-fetching
            $messageIds = array_map(
                fn ($m) => $m->getId(),
                $this->em->getRepository(Message::class)->findBy(['userId' => $userId])
            );
            foreach ($messageIds as $id) {
                $message = $this->em->find(Message::class, $id);
                if ($message) {
                    $this->em->remove($message);
                }
            }

            // Remove UseLog entries (rate limit tracking)
            $useLogIds = array_map(
                fn ($u) => $u->getId(),
                $this->em->getRepository(\App\Entity\UseLog::class)->findBy(['userId' => $userId])
            );
            foreach ($useLogIds as $id) {
                $useLog = $this->em->find(\App\Entity\UseLog::class, $id);
                if ($useLog) {
                    $this->em->remove($useLog);
                }
            }

            // Remove all Config entries (model preferences) (including fixture configs)
            $configIds = array_map(
                fn ($c) => $c->getId(),
                $this->em->getRepository(\App\Entity\Config::class)->findBy(['ownerId' => $userId])
            );
            foreach ($configIds as $id) {
                $config = $this->em->find(\App\Entity\Config::class, $id);
                if ($config) {
                    $this->em->remove($config);
                }
            }

            $this->em->flush();

            // Do NOT remove fixture user - it will be reused across tests
        }

        // Ensure kernel is shutdown for next test
        static::ensureKernelShutdown();

        parent::tearDown();
    }

    public function testSendMessageWithoutAuth(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request(
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
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/api/v1/messages/history');

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

    public function testGetMessageWithoutAuth(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/api/v1/messages/123');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetMessageNotFound(): void
    {
        $this->client->request(
            'GET',
            '/api/v1/messages/999999999',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ]
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * Issue #1070: GET /messages/{id} must return the same row shape as the
     * chat history endpoint (shared MessageApiFormatter), including the
     * generated file and the AI model metadata — the frontend reconciles
     * the streamed state against this payload after SSE `complete`.
     */
    public function testGetMessageReturnsPersistedRowShape(): void
    {
        $message = new Message();
        $message->setUserId($this->user->getId());
        $message->setTrackingId(time());
        $message->setProviderIndex('test');
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setMessageType('WEB');
        $message->setTopic('general');
        $message->setLanguage('en');
        $message->setText('Voice reply answer');
        $message->setDirection('OUT');
        $message->setStatus('complete');
        // TTS audio persisted on the OUT message (voice reply / DAG turn)
        $message->setFile(1);
        $message->setFilePath('/api/v1/files/uploads/13/000/tts_reply.mp3');
        $message->setFileType('audio');

        $this->em->persist($message);
        $this->em->flush();

        // Metas require the message id (MessageMeta.messageId is non-null),
        // so they are attached after the initial flush — same order as the
        // StreamController persistence path.
        $message->setMeta('ai_audio_provider', 'piper');
        $message->setMeta('ai_audio_model', 'piper-multi');
        $message->setMeta('multitask', '1');
        $this->em->flush();

        $this->client->request(
            'GET',
            '/api/v1/messages/'.$message->getId(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ]
        );

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);

        $row = $response['message'];
        $this->assertSame($message->getId(), $row['id']);
        $this->assertSame('Voice reply answer', $row['text']);
        $this->assertSame('OUT', $row['direction']);
        $this->assertSame('general', $row['topic']);
        $this->assertTrue($row['multitask']);
        $this->assertSame(
            ['path' => '/api/v1/files/uploads/13/000/tts_reply.mp3', 'type' => 'audio'],
            $row['file']
        );
        $this->assertSame('piper', $row['aiModels']['audio']['provider']);
        $this->assertSame('piper-multi', $row['aiModels']['audio']['model']);
    }

    public function testGetMessageOfAnotherUserReturns404(): void
    {
        $otherUser = $this->em->getRepository(User::class)->findOneBy(['mail' => 'admin@synaplan.com']);
        if (!$otherUser) {
            $this->markTestSkipped('Fixture user admin@synaplan.com not found.');
        }

        $message = new Message();
        $message->setUserId($otherUser->getId());
        $message->setTrackingId(time());
        $message->setProviderIndex('test');
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setMessageType('WEB');
        $message->setFile(0);
        $message->setTopic('general');
        $message->setLanguage('en');
        $message->setText('Not yours');
        $message->setDirection('OUT');
        $message->setStatus('complete');

        $this->em->persist($message);
        $this->em->flush();
        $messageId = $message->getId();

        try {
            $this->client->request(
                'GET',
                '/api/v1/messages/'.$messageId,
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
                ]
            );

            $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        } finally {
            // tearDown only cleans up the demo user's messages.
            $leftover = $this->em->find(Message::class, $messageId);
            if ($leftover) {
                $this->em->remove($leftover);
                $this->em->flush();
            }
        }
    }

    public function testEnhanceWithoutAuth(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request(
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

    public function testEnhanceReturnsDocumentedPayloadForOutcome(): void
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

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            Response::HTTP_SERVICE_UNAVAILABLE,
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ]);

        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($payload);

        if (Response::HTTP_OK === $statusCode) {
            $this->assertTrue($payload['success'] ?? false);
            $this->assertArrayHasKey('enhanced', $payload);
            $this->assertIsString($payload['enhanced']);
            $this->assertArrayHasKey('original', $payload);

            return;
        }

        if (Response::HTTP_UNPROCESSABLE_ENTITY === $statusCode) {
            $this->assertSame('enhance_rejected', $payload['error'] ?? null);

            return;
        }

        if (Response::HTTP_SERVICE_UNAVAILABLE === $statusCode) {
            $this->assertArrayHasKey('error', $payload);

            return;
        }

        $this->assertArrayHasKey('error', $payload);
    }

    public function testAgainWithoutAuth(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request(
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
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request(
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
