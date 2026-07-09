<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Controller\MessageController;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\SearchResultRepository;
use App\Service\File\DataUrlFixer;
use App\Service\File\FileProcessor;
use App\Service\File\FileStorageService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\File\VectorizationService;
use App\Service\Message\AgainHandler;
use App\Service\Message\MessageApiFormatter;
use App\Service\MessageEnqueueService;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unit coverage for {@see MessageController::getExtractedMemories()}.
 *
 * Issue #881: the memory poll endpoint was returning `pending` forever in
 * production even after the worker had successfully extracted memories.
 * Two of the three root causes live in this controller:
 *
 *   1. Missing `Cache-Control` headers — without explicit `no-store`,
 *      Symfony defaults to `private, must-revalidate` and intermediate
 *      caches (browser HTTP cache, Cloudflare quirks under heavy load)
 *      could serve the very first `pending` body for every subsequent
 *      poll on the same `messageId`.
 *
 *   2. Stale Doctrine identity map — under FrankenPHP/worker mode the
 *      EntityManager survives across the SSE stream and the polling
 *      requests in the same PHP process, so a Message entity loaded
 *      during the SSE call kept its old (empty) BMESSAGEMETA collection
 *      after the worker inserted the `extracted_memories` row from a
 *      separate process.
 *
 * Both fixes are pinned by the assertions below.
 */
final class MessageControllerExtractedMemoriesTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MessageRepository&MockObject $messageRepository;
    private MessageController $controller;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->messageRepository = $this->createMock(MessageRepository::class);

        $this->em
            ->expects(self::any())
            ->method('getRepository')
            ->with(Message::class)
            ->willReturn($this->messageRepository);

        $this->controller = new MessageController(
            $this->em,
            $this->createMock(AiFacade::class),
            $this->createMock(AgainHandler::class),
            $this->createMock(PromptService::class),
            $this->createMock(ModelConfigService::class),
            $this->createMock(MessageEnqueueService::class),
            $this->createMock(RateLimitService::class),
            $this->createMock(FileStorageService::class),
            $this->createMock(FileProcessor::class),
            $this->createMock(VectorizationService::class),
            $this->messageRepository,
            // MessageApiFormatter is `final readonly` and cannot be mocked;
            // a real instance with mocked collaborators is fine since the
            // memory-poll endpoint under test never calls format().
            new MessageApiFormatter(
                $this->messageRepository,
                $this->createMock(SearchResultRepository::class),
                new DataUrlFixer(
                    $this->em,
                    new UserUploadPathBuilder(),
                    '/tmp',
                    new NullLogger(),
                ),
            ),
            new NullLogger(),
        );

        // AbstractController::json() requires a container even for the
        // serializer-less fallback path.
        $this->controller->setContainer(new Container());
    }

    public function testReturnsUnauthorizedWithNoStoreHeadersWhenUserIsNull(): void
    {
        $response = $this->controller->getExtractedMemories(123, null);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $this->assertNoStoreHeaders($response);
    }

    public function testReturnsNotFoundWithNoStoreHeadersWhenMessageMissing(): void
    {
        $user = $this->makeUser(7);
        $this->messageRepository->expects(self::any())->method('find')->with(123)->willReturn(null);

        $response = $this->controller->getExtractedMemories(123, $user);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertNoStoreHeaders($response);
    }

    public function testReturnsNotFoundWhenMessageBelongsToDifferentUser(): void
    {
        $user = $this->makeUser(7);
        $message = $this->makeMessage(99, 999); // owned by user 999, not 7

        $this->messageRepository->expects(self::any())->method('find')->with(123)->willReturn($message);

        $response = $this->controller->getExtractedMemories(123, $user);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertNoStoreHeaders($response);
    }

    public function testReturnsPendingWithNoStoreHeadersWhenMetaIsMissing(): void
    {
        $user = $this->makeUser(7);
        $message = $this->makeMessage(99, 7); // belongs to user 7

        $this->messageRepository->expects(self::any())->method('find')->with(123)->willReturn($message);

        // Critical: refresh() must run BEFORE getMeta() so the poll sees
        // freshly-flushed worker writes (issue #881 root cause #3).
        $this->em
            ->expects($this->once())
            ->method('refresh')
            ->with($message);

        $response = $this->controller->getExtractedMemories(123, $user);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertNoStoreHeaders($response);

        $body = $this->decodeJson($response);
        self::assertSame('pending', $body['status']);
        self::assertNull($body['completed_at']);
        self::assertSame([], $body['saved']);
        self::assertSame([], $body['delete_suggestions']);
    }

    public function testReturnsCompletePayloadWithNoStoreHeaders(): void
    {
        $user = $this->makeUser(7);
        $message = $this->makeMessage(99, 7);
        $message->setMeta('extracted_memories', json_encode([
            'status' => 'complete',
            'completed_at' => 1_700_000_000,
            'saved' => [
                ['id' => 1, 'category' => 'personal', 'key' => 'name', 'value' => 'Max'],
            ],
            'delete_suggestions' => [],
        ]));

        $this->messageRepository->expects(self::any())->method('find')->with(123)->willReturn($message);
        $this->em->expects($this->once())->method('refresh')->with($message);

        $response = $this->controller->getExtractedMemories(123, $user);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertNoStoreHeaders($response);

        $body = $this->decodeJson($response);
        self::assertSame('complete', $body['status']);
        self::assertSame(1_700_000_000, $body['completed_at']);
        self::assertCount(1, $body['saved']);
        self::assertSame('Max', $body['saved'][0]['value']);
    }

    public function testCorruptPayloadReturnsEmptyStatusAndStillOptsOutOfCaching(): void
    {
        $user = $this->makeUser(7);
        $message = $this->makeMessage(99, 7);
        $message->setMeta('extracted_memories', '{this is not valid json');

        $this->messageRepository->expects(self::any())->method('find')->with(123)->willReturn($message);
        $this->em->expects($this->once())->method('refresh')->with($message);

        $response = $this->controller->getExtractedMemories(123, $user);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertNoStoreHeaders($response);

        $body = $this->decodeJson($response);
        self::assertSame('empty', $body['status']);
        self::assertNull($body['completed_at']);
    }

    /**
     * If the entity manager throws while refreshing (entity removed
     * mid-poll, transient DB hiccup), the controller must keep serving
     * the request rather than 500-ing the user.
     */
    public function testRefreshFailureDoesNotBreakTheResponse(): void
    {
        $user = $this->makeUser(7);
        $message = $this->makeMessage(99, 7);

        $this->messageRepository->expects(self::any())->method('find')->with(123)->willReturn($message);
        $this->em
            ->expects($this->once())
            ->method('refresh')
            ->with($message)
            ->willThrowException(new \RuntimeException('detached entity'));

        $response = $this->controller->getExtractedMemories(123, $user);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertNoStoreHeaders($response);

        $body = $this->decodeJson($response);
        self::assertSame('pending', $body['status']);
    }

    private function assertNoStoreHeaders(Response $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-cache', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('must-revalidate', $cacheControl);
        self::assertSame('no-cache', $response->headers->get('Pragma'));
        self::assertSame('0', $response->headers->get('Expires'));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(Response $response): array
    {
        $body = $response->getContent();
        self::assertNotFalse($body);

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    private function makeUser(int $id): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    /**
     * Build a real Message entity (not a mock) so the existing
     * {@see Message::setMeta()} / {@see Message::getMeta()} logic runs
     * against a real BMESSAGEMETA collection. The id is set via
     * reflection because the column is database-generated.
     */
    private function makeMessage(int $id, int $userId): Message
    {
        $message = new Message();
        $message->setUserId($userId);

        $reflection = new \ReflectionProperty(Message::class, 'id');
        $reflection->setValue($message, $id);

        return $message;
    }
}
