<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Chat;
use App\Entity\GuestSession;
use App\Entity\Message;
use App\Service\Media\MediaCancellationStore;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

class GuestChatControllerTest extends WebTestCase
{
    private $client;
    private $em;

    /** @var list<int> Chat IDs created by a test, deleted in tearDown */
    private array $createdChatIds = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();
        $this->createdChatIds = [];
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            if ([] !== $this->createdChatIds) {
                $this->em->createQuery('DELETE FROM App\Entity\Message m WHERE m.chatId IN (:ids)')
                    ->setParameter('ids', $this->createdChatIds)
                    ->execute();
                $this->em->createQuery('DELETE FROM App\Entity\Chat c WHERE c.id IN (:ids)')
                    ->setParameter('ids', $this->createdChatIds)
                    ->execute();
            }

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

    /**
     * Attach a chat with one persisted IN message (the running turn) to the session.
     *
     * @return array{chatId: int, trackId: int}
     */
    private function attachChatWithIncomingMessage(string $sessionId): array
    {
        $session = $this->em->getRepository(GuestSession::class)->findOneBy(['sessionId' => $sessionId]);
        $this->assertNotNull($session);

        $now = new \DateTime();
        $chat = new Chat();
        $chat->setUserId(0);
        $chat->setTitle('Guest Chat • test');
        $chat->setSource('guest');
        $chat->setCreatedAt($now);
        $chat->setUpdatedAt($now);
        $this->em->persist($chat);
        $this->em->flush();

        $chatId = $chat->getId();
        $this->createdChatIds[] = $chatId;
        $session->setChatId($chatId);

        $trackId = random_int(1_000_000_000, 9_999_999_999);
        $message = new Message();
        $message->setUserId(0);
        $message->setChat($chat);
        $message->setTrackingId($trackId);
        $message->setDirection('IN');
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setText('Guest question');
        $this->em->persist($message);
        $this->em->flush();

        return ['chatId' => $chatId, 'trackId' => $trackId];
    }

    private function requestStopStream(array $body): void
    {
        $this->client->request(
            'POST',
            '/api/v1/guest/stop-stream',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body)
        );
    }

    public function testStopStreamReturns400ForInvalidSessionId(): void
    {
        $this->requestStopStream(['sessionId' => 'not-a-uuid', 'trackId' => 123]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testStopStreamReturns400ForMissingTrackId(): void
    {
        $created = $this->createGuestSession();

        $this->requestStopStream(['sessionId' => $created['sessionId']]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testStopStreamReturns404ForNonexistentSession(): void
    {
        $this->requestStopStream(['sessionId' => Uuid::v4()->toRfc4122(), 'trackId' => 123]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testStopStreamReturns404WhenTrackDoesNotBelongToSession(): void
    {
        $created = $this->createGuestSession();
        $turn = $this->attachChatWithIncomingMessage($created['sessionId']);

        // A valid session must not be able to cancel someone else's turn.
        $foreignTrackId = $turn['trackId'] + 1;
        $this->requestStopStream(['sessionId' => $created['sessionId'], 'trackId' => $foreignTrackId]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $cancellationStore = static::getContainer()->get(MediaCancellationStore::class);
        $this->assertFalse($cancellationStore->isCancelled((string) $foreignTrackId));
    }

    public function testStopStreamFlagsCancellationForOwnTurn(): void
    {
        $created = $this->createGuestSession();
        $turn = $this->attachChatWithIncomingMessage($created['sessionId']);

        $this->requestStopStream(['sessionId' => $created['sessionId'], 'trackId' => $turn['trackId']]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);

        $cancellationStore = static::getContainer()->get(MediaCancellationStore::class);
        $this->assertTrue($cancellationStore->isCancelled((string) $turn['trackId']));
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
