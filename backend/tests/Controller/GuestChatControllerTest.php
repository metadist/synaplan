<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\GuestSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

class GuestChatControllerTest extends WebTestCase
{
    private $client;
    private $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            $this->em->createQuery('DELETE FROM App\Entity\GuestSession gs')
                ->execute();
        }

        parent::tearDown();
    }

    private function createGuestSession(array $body = []): array
    {
        $this->client->request(
            'POST',
            '/api/v1/guest/session',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body)
        );

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testCreateSessionReturnsNewSession(): void
    {
        $data = $this->createGuestSession();

        $this->assertResponseIsSuccessful();
        $this->assertArrayHasKey('sessionId', $data);
        $this->assertArrayHasKey('remaining', $data);
        $this->assertArrayHasKey('maxMessages', $data);
        $this->assertArrayHasKey('limitReached', $data);
        $this->assertSame(5, $data['remaining']);
        $this->assertSame(5, $data['maxMessages']);
        $this->assertFalse($data['limitReached']);
        $this->assertTrue(Uuid::isValid($data['sessionId']));
    }

    public function testCreateSessionIgnoresInvalidClientId(): void
    {
        $data = $this->createGuestSession(['sessionId' => 'not-a-uuid']);

        $this->assertResponseIsSuccessful();
        $this->assertTrue(Uuid::isValid($data['sessionId']));
        $this->assertNotSame('not-a-uuid', $data['sessionId']);
    }

    public function testCreateSessionReturnsExistingSession(): void
    {
        $first = $this->createGuestSession();
        $this->assertResponseIsSuccessful();
        $serverSessionId = $first['sessionId'];

        $session = $this->em->getRepository(GuestSession::class)->findOneBy(['sessionId' => $serverSessionId]);
        $session->setMessageCount(2);
        $this->em->flush();

        $second = $this->createGuestSession(['sessionId' => $serverSessionId]);
        $this->assertResponseIsSuccessful();

        $this->assertSame($serverSessionId, $second['sessionId']);
        $this->assertSame(3, $second['remaining']);
    }

    public function testGetSessionStatusReturnsSession(): void
    {
        $created = $this->createGuestSession();
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', "/api/v1/guest/session/{$created['sessionId']}");

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($created['sessionId'], $data['sessionId']);
        $this->assertSame(5, $data['remaining']);
        $this->assertFalse($data['limitReached']);
    }

    public function testGetSessionStatusReturns400ForInvalidUuid(): void
    {
        $this->client->request('GET', '/api/v1/guest/session/nonexistent-uuid');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testGetSessionStatusReturns404ForNonexistent(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $this->client->request('GET', "/api/v1/guest/session/{$uuid}");

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testGetSessionStatusReturns410ForExpired(): void
    {
        $sessionId = Uuid::v4()->toRfc4122();

        $session = new GuestSession();
        $session->setSessionId($sessionId);
        $session->setExpires(time() - 3600);
        $this->em->persist($session);
        $this->em->flush();

        $this->client->request('GET', "/api/v1/guest/session/{$sessionId}");

        $this->assertResponseStatusCodeSame(Response::HTTP_GONE);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Session expired', $data['error']);
        $this->assertSame('expired', $data['reason']);
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

    public function testCreateChatReturns400ForInvalidSessionId(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/guest/chat',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['sessionId' => 'nonexistent'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testCreateChatReturns404ForNonexistentSession(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $this->client->request(
            'POST',
            '/api/v1/guest/chat',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['sessionId' => $uuid])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testGuestEndpointsDoNotRequireAuthentication(): void
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
    }
}
