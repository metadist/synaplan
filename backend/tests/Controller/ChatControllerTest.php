<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Chat;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for ChatController
 * Tests all chat management endpoints.
 */
class ChatControllerTest extends WebTestCase
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
        $this->user->setMail('chattest@example.com');
        $this->user->setPw(password_hash('testpass', PASSWORD_BCRYPT));
        $this->user->setUserLevel('PRO');
        $this->user->setProviderId('test-provider');
        $this->user->setCreated(date('YmdHis'));

        $this->em->persist($this->user);
        $this->em->flush();

        // Generate JWT token
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        $this->token = $jwtManager->create($this->user);
    }

    protected function tearDown(): void
    {
        if ($this->em && $this->user) {
            // Cleanup: Remove test chats
            $chats = $this->em->getRepository(Chat::class)
                ->findBy(['userId' => $this->user->getId()]);

            foreach ($chats as $chat) {
                $this->em->remove($chat);
            }

            // Remove test user
            $this->em->remove($this->user);
            $this->em->flush();
        }

        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testListChatsWithoutAuth(): void
    {
        $this->client->request('GET', '/api/v1/chats');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testListChatsEmpty(): void
    {
        $this->client->request(
            'GET',
            '/api/v1/chats',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ]
        );

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('chats', $response);
        $this->assertEmpty($response['chats']);
    }

    public function testCreateChatWithoutAuth(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/chats',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => 'New Chat'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCreateChatWithEmptyTitle(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/chats',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ],
            json_encode(['title' => ''])
        );

        // Should either create with default title or return bad request
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_CREATED,
            Response::HTTP_BAD_REQUEST,
        ]);
    }

    public function testCreateChatSuccess(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/chats',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ],
            json_encode(['title' => 'My Test Chat'])
        );

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('chat', $response);
        $this->assertArrayHasKey('id', $response['chat']);
        $this->assertArrayHasKey('title', $response['chat']);
        $this->assertEquals('My Test Chat', $response['chat']['title']);

        // Verify chat was created in database
        $chat = $this->em->getRepository(Chat::class)->find($response['chat']['id']);
        $this->assertNotNull($chat);
        $this->assertEquals($this->user->getId(), $chat->getUserId());
    }

    public function testListChatsWithData(): void
    {
        // Create test chats
        for ($i = 1; $i <= 3; ++$i) {
            $chat = new Chat();
            $chat->setUserId($this->user->getId());
            $chat->setTitle('Test Chat '.$i);
            $chat->setCreatedAt(new \DateTime());
            $chat->setUpdatedAt(new \DateTime());

            $this->em->persist($chat);
        }
        $this->em->flush();

        $this->client->request(
            'GET',
            '/api/v1/chats',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ]
        );

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('chats', $response);
        $this->assertCount(3, $response['chats']);

        // Check structure of first chat
        $firstChat = $response['chats'][0];
        $this->assertArrayHasKey('id', $firstChat);
        $this->assertArrayHasKey('title', $firstChat);
        $this->assertArrayHasKey('createdAt', $firstChat);
        $this->assertArrayHasKey('updatedAt', $firstChat);
    }

    public function testGetChatByIdWithoutAuth(): void
    {
        $this->client->request('GET', '/api/v1/chats/1');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetChatByIdNotFound(): void
    {
        $this->client->request(
            'GET',
            '/api/v1/chats/99999',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ]
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testGetChatByIdSuccess(): void
    {
        // Create test chat
        $chat = new Chat();
        $chat->setUserId($this->user->getId());
        $chat->setTitle('Single Test Chat');
        $chat->setCreatedAt(new \DateTime());
        $chat->setUpdatedAt(new \DateTime());

        $this->em->persist($chat);
        $this->em->flush();

        $this->client->request(
            'GET',
            '/api/v1/chats/'.$chat->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ]
        );

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('chat', $response);
        $this->assertEquals($chat->getId(), $response['chat']['id']);
        $this->assertEquals('Single Test Chat', $response['chat']['title']);
    }

    public function testGetChatByIdUnauthorized(): void
    {
        // Create chat for different user
        $otherUser = new User();
        $otherUser->setMail('other@example.com');
        $otherUser->setPw(password_hash('test', PASSWORD_BCRYPT));
        $otherUser->setUserLevel('NEW');
        $otherUser->setProviderId('other');
        $otherUser->setCreated(date('YmdHis'));

        $this->em->persist($otherUser);
        $this->em->flush();

        $chat = new Chat();
        $chat->setUserId($otherUser->getId());
        $chat->setTitle('Other User Chat');
        $chat->setCreatedAt(new \DateTime());
        $chat->setUpdatedAt(new \DateTime());

        $this->em->persist($chat);
        $this->em->flush();

        // Try to access with current user's token
        $this->client->request(
            'GET',
            '/api/v1/chats/'.$chat->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ]
        );

        // Should return 403 Forbidden or 404 Not Found
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_FORBIDDEN,
            Response::HTTP_NOT_FOUND,
        ]);

        // Cleanup
        $this->em->remove($chat);
        $this->em->remove($otherUser);
        $this->em->flush();
    }

    public function testUpdateChatWithoutAuth(): void
    {
        $this->client->request(
            'PATCH',
            '/api/v1/chats/1',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => 'Updated'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testUpdateChatNotFound(): void
    {
        $this->client->request(
            'PATCH',
            '/api/v1/chats/99999',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ],
            json_encode(['title' => 'Updated'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testUpdateChatSuccess(): void
    {
        // Create test chat
        $chat = new Chat();
        $chat->setUserId($this->user->getId());
        $chat->setTitle('Original Title');
        $chat->setCreatedAt(new \DateTime());
        $chat->setUpdatedAt(new \DateTime());

        $this->em->persist($chat);
        $this->em->flush();

        $this->client->request(
            'PATCH',
            '/api/v1/chats/'.$chat->getId(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ],
            json_encode(['title' => 'Updated Title'])
        );

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('chat', $response);
        $this->assertEquals('Updated Title', $response['chat']['title']);

        // Verify update in database
        $this->em->refresh($chat);
        $this->assertEquals('Updated Title', $chat->getTitle());
    }

    public function testDeleteChatWithoutAuth(): void
    {
        $this->client->request('DELETE', '/api/v1/chats/1');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testDeleteChatNotFound(): void
    {
        $this->client->request(
            'DELETE',
            '/api/v1/chats/99999',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ]
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeleteChatSuccess(): void
    {
        // Create test chat
        $chat = new Chat();
        $chat->setUserId($this->user->getId());
        $chat->setTitle('Chat to Delete');
        $chat->setCreatedAt(new \DateTime());
        $chat->setUpdatedAt(new \DateTime());

        $this->em->persist($chat);
        $this->em->flush();

        $chatId = $chat->getId();

        $this->client->request(
            'DELETE',
            '/api/v1/chats/'.$chatId,
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ]
        );

        $this->assertResponseIsSuccessful();

        // Verify deletion in database
        $deletedChat = $this->em->getRepository(Chat::class)->find($chatId);
        $this->assertNull($deletedChat);
    }
}
