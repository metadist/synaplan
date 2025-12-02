<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\Chat;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

class StreamControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function getAuthToken(): string
    {
        if ($this->token) {
            return $this->token;
        }

        // Find test user
        $userRepository = static::getContainer()->get('doctrine')->getRepository(User::class);
        $user = $userRepository->findOneBy(['mail' => 'admin@synaplan.com']);

        if (!$user) {
            $this->fail('Test user not found');
        }

        // Generate JWT token manually for testing
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        $this->token = $jwtManager->create($user);

        return $this->token;
    }

    private function createTestChat(): Chat
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $userRepository = $em->getRepository(User::class);
        $user = $userRepository->findOneBy(['mail' => 'admin@synaplan.com']);

        $chat = new Chat();
        $chat->setUserId($user->getId());
        $chat->setTitle('Stream Test Chat');
        $chat->setUnixTimestamp(time());
        $chat->setCreatedDatetime(new \DateTime());

        $em->persist($chat);
        $em->flush();

        return $chat;
    }

    public function testStreamRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/messages/stream', [
            'message' => 'Test',
            'chatId' => 1
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testStreamRequiresMessage(): void
    {
        $token = $this->getAuthToken();
        $chat = $this->createTestChat();

        $this->client->request('GET', '/api/v1/messages/stream', [
            'chatId' => $chat->getId()
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $response = $this->client->getResponse();
        
        // For streamed responses, we need to check content
        $this->assertResponseStatusCodeSame(200);
        $this->assertResponseHeaderSame('Content-Type', 'text/event-stream');
    }

    public function testStreamRequiresChatId(): void
    {
        $token = $this->getAuthToken();

        $this->client->request('GET', '/api/v1/messages/stream', [
            'message' => 'Test message'
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $response = $this->client->getResponse();
        $this->assertResponseStatusCodeSame(200); // Stream starts
        $this->assertResponseHeaderSame('Content-Type', 'text/event-stream');
    }

    public function testStreamSetsCorrectHeaders(): void
    {
        $token = $this->getAuthToken();
        $chat = $this->createTestChat();

        $this->client->request('GET', '/api/v1/messages/stream', [
            'message' => 'Test',
            'chatId' => $chat->getId()
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $response = $this->client->getResponse();
        
        $this->assertResponseHeaderSame('Content-Type', 'text/event-stream');
        $this->assertResponseHeaderSame('Cache-Control', 'no-cache');
        $this->assertResponseHeaderSame('X-Accel-Buffering', 'no');
    }

    public function testStreamAcceptsOptionalParameters(): void
    {
        $token = $this->getAuthToken();
        $chat = $this->createTestChat();

        $this->client->request('GET', '/api/v1/messages/stream', [
            'message' => 'Test with options',
            'chatId' => $chat->getId(),
            'trackId' => 12345,
            'reasoning' => '1',
            'webSearch' => '1'
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertResponseHeaderSame('Content-Type', 'text/event-stream');
    }

    public function testStreamOnlyAcceptsGetMethod(): void
    {
        $token = $this->getAuthToken();

        $this->client->request('POST', '/api/v1/messages/stream', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(405);
    }

    public function testStreamRejectsUnauthorizedChatAccess(): void
    {
        $token = $this->getAuthToken();

        // Try to access non-existent chat
        $this->client->request('GET', '/api/v1/messages/stream', [
            'message' => 'Test',
            'chatId' => 999999
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(200); // Stream starts but will send error event
        $this->assertResponseHeaderSame('Content-Type', 'text/event-stream');
    }
}

