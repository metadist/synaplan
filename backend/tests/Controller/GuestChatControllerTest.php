<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\GuestSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class GuestChatControllerTest extends WebTestCase
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
        // Cleanup all test guest sessions
        if ($this->em->isOpen()) {
            $this->em->createQuery('DELETE FROM App\Entity\GuestSession gs')
                ->execute();
        }

        parent::tearDown();
    }

    public function testCreateSessionReturnsNewSession(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/guest/session',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('sessionId', $data);
        $this->assertArrayHasKey('remaining', $data);
        $this->assertArrayHasKey('maxMessages', $data);
        $this->assertArrayHasKey('limitReached', $data);
        $this->assertSame(5, $data['remaining']);
        $this->assertSame(5, $data['maxMessages']);
        $this->assertFalse($data['limitReached']);
    }

    public function testCreateSessionWithClientGeneratedUuid(): void
    {
        $sessionId = 'client-uuid-'.time();

        $this->client->request(
            'POST',
            '/api/v1/guest/session',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['sessionId' => $sessionId])
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($sessionId, $data['sessionId']);
    }

    public function testCreateSessionReturnsExistingSession(): void
    {
        $sessionId = 'existing-session-'.time();

        // First request creates the session
        $this->client->request(
            'POST',
            '/api/v1/guest/session',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['sessionId' => $sessionId])
        );
        $this->assertResponseIsSuccessful();

        // Increment message count directly for verification
        $session = $this->em->getRepository(GuestSession::class)->findOneBy(['sessionId' => $sessionId]);
        $session->setMessageCount(2);
        $this->em->flush();

        // Second request should return the existing session with updated count
        $this->client->request(
            'POST',
            '/api/v1/guest/session',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['sessionId' => $sessionId])
        );
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($sessionId, $data['sessionId']);
        $this->assertSame(3, $data['remaining']);
    }

    public function testGetSessionStatusReturnsSession(): void
    {
        $sessionId = 'status-test-'.time();

        // Create session first
        $this->client->request(
            'POST',
            '/api/v1/guest/session',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['sessionId' => $sessionId])
        );
        $this->assertResponseIsSuccessful();

        // Get status
        $this->client->request('GET', "/api/v1/guest/session/{$sessionId}");

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($sessionId, $data['sessionId']);
        $this->assertSame(5, $data['remaining']);
        $this->assertFalse($data['limitReached']);
    }

    public function testGetSessionStatusReturns404ForNonexistent(): void
    {
        $this->client->request('GET', '/api/v1/guest/session/nonexistent-uuid');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testGetSessionStatusReturns404ForExpired(): void
    {
        $sessionId = 'expired-test-'.time();

        // Create session
        $session = new GuestSession();
        $session->setSessionId($sessionId);
        $session->setExpires(time() - 3600); // Expired 1 hour ago
        $this->em->persist($session);
        $this->em->flush();

        $this->client->request('GET', "/api/v1/guest/session/{$sessionId}");

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCreateChatRequiresSessionId(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/guest/chat',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testCreateChatReturns404ForInvalidSession(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/guest/chat',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['sessionId' => 'nonexistent'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testGuestEndpointsDoNotRequireAuthentication(): void
    {
        // POST /api/v1/guest/session should be accessible without auth
        $this->client->request(
            'POST',
            '/api/v1/guest/session',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );

        $this->assertResponseIsSuccessful();
    }
}
