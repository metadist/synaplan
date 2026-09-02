<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Digest;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Entity\Message;
use App\Entity\MessageDigest;
use App\Entity\User;
use App\Repository\MessageDigestRepository;
use App\Repository\PromptRepository;
use App\Service\Digest\MessageDigestService;
use App\Service\Memory\MemoryEmbeddingModelResolver;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\VectorSearch\QdrantClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MessageDigestServiceTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfigService;
    private RateLimitService&MockObject $rateLimitService;
    private PromptRepository&MockObject $promptRepository;
    private MessageDigestRepository&MockObject $digestRepository;
    private QdrantClientInterface&MockObject $qdrantClient;
    private MemoryEmbeddingModelResolver&MockObject $embeddingResolver;
    private MessageDigestService $service;
    private User $user;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->promptRepository = $this->createMock(PromptRepository::class);
        $this->digestRepository = $this->createMock(MessageDigestRepository::class);
        $this->qdrantClient = $this->createMock(QdrantClientInterface::class);
        $this->embeddingResolver = $this->createMock(MemoryEmbeddingModelResolver::class);

        $this->modelConfigService->method('getMemoryModelConfig')
            ->willReturn(['model' => 'test-model', 'provider' => 'test', 'model_id' => 42]);
        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->embeddingResolver->method('resolve')->willReturn([
            'provider' => 'ollama',
            'model' => 'bge-m3',
            'model_id' => 13,
            'vector_dim' => 4,
        ]);

        $this->service = new MessageDigestService(
            $this->aiFacade,
            $this->modelConfigService,
            $this->rateLimitService,
            $this->promptRepository,
            $this->digestRepository,
            $this->qdrantClient,
            $this->embeddingResolver,
            new NullLogger(),
            $this->alwaysOnStructuredOutputConfig(),
        );

        $this->user = $this->makeUser(7);
    }

    private function alwaysOnStructuredOutputConfig(): StructuredOutputConfig
    {
        $config = $this->createMock(StructuredOutputConfig::class);
        $config->method('isEnabled')->willReturn(true);

        return $config;
    }

    public function testDigestBatchStoresValidProposalInDbAndQdrant(): void
    {
        $messages = [
            $this->makeMessage(101, 'Small talk, thanks!'),
            $this->makeMessage(102, 'Letter to the realtor about the office rent increase from 1450 to 1620.'),
        ];

        $this->digestRepository->method('findDigestedMessageIds')->willReturn([]);
        $this->digestRepository->method('findTitlesForChats')->willReturn([]);

        $this->aiFacade->method('chat')->willReturn([
            'content' => '[{"title": "office rent letter to realtor about the increase of payments", "message_id": 102}]',
            'provider' => 'test',
            'model' => 'test-model',
            'usage' => [],
        ]);
        $this->aiFacade->method('embed')->willReturn(['embedding' => [0.1, 0.2, 0.3, 0.4], 'usage' => []]);
        $this->qdrantClient->method('isAvailable')->willReturn(true);

        $storedDigest = null;
        $this->digestRepository->expects(self::once())
            ->method('upsert')
            ->willReturnCallback(static function (MessageDigest $digest) use (&$storedDigest): void {
                $storedDigest = $digest;
            });

        $qdrantPointId = null;
        $qdrantPayload = null;
        $this->qdrantClient->expects(self::once())
            ->method('upsertDigest')
            ->willReturnCallback(static function (string $pointId, array $vector, array $payload) use (&$qdrantPointId, &$qdrantPayload): void {
                $qdrantPointId = $pointId;
                $qdrantPayload = $payload;
            });

        $result = $this->service->digestBatch($this->user, $messages);

        self::assertSame(2, $result['scanned']);
        self::assertSame(1, $result['created']);

        self::assertInstanceOf(MessageDigest::class, $storedDigest);
        self::assertSame(7, $storedDigest->getUserId());
        self::assertSame(102, $storedDigest->getMessageId());
        self::assertSame('office rent letter to realtor about the increase of payments', $storedDigest->getTitle());
        self::assertSame('web', $storedDigest->getChannel());

        self::assertSame(sprintf('dig_7_%d', $storedDigest->getId()), $qdrantPointId);
        self::assertIsArray($qdrantPayload);
        self::assertSame(102, $qdrantPayload['message_id']);
        self::assertSame(7, $qdrantPayload['user_id']);
        self::assertTrue($qdrantPayload['active']);
    }

    public function testInventedMessageIdIsDropped(): void
    {
        $messages = [$this->makeMessage(101, 'Contract for the new office signed today.')];

        $this->digestRepository->method('findDigestedMessageIds')->willReturn([]);
        $this->digestRepository->method('findTitlesForChats')->willReturn([]);
        $this->aiFacade->method('chat')->willReturn([
            'content' => '[{"title": "a digest for a message that does not exist", "message_id": 99999}]',
            'usage' => [],
        ]);

        $this->digestRepository->expects(self::never())->method('upsert');

        $result = $this->service->digestBatch($this->user, $messages);

        self::assertSame(0, $result['created']);
        self::assertSame([], $result['proposals']);
    }

    public function testAlreadyDigestedMessagesNeverReachTheModel(): void
    {
        $messages = [$this->makeMessage(101, 'Important letter about the rent.')];

        $this->digestRepository->method('findDigestedMessageIds')->willReturn([101]);

        $this->aiFacade->expects(self::never())->method('chat');

        $result = $this->service->digestBatch($this->user, $messages);

        self::assertSame(1, $result['scanned']);
        self::assertSame(0, $result['created']);
    }

    public function testInvalidJsonYieldsNoDigestsWithoutThrowing(): void
    {
        $messages = [$this->makeMessage(101, 'Important letter about the rent.')];

        $this->digestRepository->method('findDigestedMessageIds')->willReturn([]);
        $this->digestRepository->method('findTitlesForChats')->willReturn([]);
        $this->aiFacade->method('chat')->willReturn(['content' => 'Sure! Here is my analysis without JSON.', 'usage' => []]);

        $this->digestRepository->expects(self::never())->method('upsert');

        $result = $this->service->digestBatch($this->user, $messages);

        self::assertSame(0, $result['created']);
    }

    public function testNullAndEmptyArrayResponsesAreValidEmptyResults(): void
    {
        $this->digestRepository->method('findDigestedMessageIds')->willReturn([]);
        $this->digestRepository->method('findTitlesForChats')->willReturn([]);
        $this->aiFacade->method('chat')->willReturn(['content' => 'null', 'usage' => []]);

        $result = $this->service->digestBatch($this->user, [$this->makeMessage(101, 'hi')]);

        self::assertSame(0, $result['created']);
        self::assertSame([], $result['proposals']);
    }

    public function testDryRunReturnsProposalsButStoresNothing(): void
    {
        $messages = [$this->makeMessage(102, 'Letter to the realtor about the office rent increase.')];

        $this->digestRepository->method('findDigestedMessageIds')->willReturn([]);
        $this->digestRepository->method('findTitlesForChats')->willReturn([]);
        $this->aiFacade->method('chat')->willReturn([
            'content' => '[{"title": "office rent letter to realtor", "message_id": 102}]',
            'usage' => [],
        ]);

        $this->digestRepository->expects(self::never())->method('upsert');
        $this->qdrantClient->expects(self::never())->method('upsertDigest');

        $result = $this->service->digestBatch($this->user, $messages, dryRun: true);

        self::assertSame(0, $result['created']);
        self::assertSame([['title' => 'office rent letter to realtor', 'message_id' => 102]], $result['proposals']);
    }

    public function testQdrantOutageKeepsTheDatabaseRow(): void
    {
        $messages = [$this->makeMessage(102, 'Letter to the realtor about the office rent increase.')];

        $this->digestRepository->method('findDigestedMessageIds')->willReturn([]);
        $this->digestRepository->method('findTitlesForChats')->willReturn([]);
        $this->aiFacade->method('chat')->willReturn([
            'content' => '[{"title": "office rent letter to realtor", "message_id": 102}]',
            'usage' => [],
        ]);
        $this->qdrantClient->method('isAvailable')->willReturn(false);

        $this->digestRepository->expects(self::once())->method('upsert');
        $this->qdrantClient->expects(self::never())->method('upsertDigest');

        $result = $this->service->digestBatch($this->user, $messages);

        self::assertSame(1, $result['created']);
    }

    public function testTooShortTitlesAreRejectedAndLongTitlesClipped(): void
    {
        $longTitle = str_repeat('a', 600);
        $messages = [
            $this->makeMessage(101, 'First important document.'),
            $this->makeMessage(102, 'Second important document.'),
        ];

        $this->digestRepository->method('findDigestedMessageIds')->willReturn([]);
        $this->digestRepository->method('findTitlesForChats')->willReturn([]);
        $this->qdrantClient->method('isAvailable')->willReturn(false);
        $this->aiFacade->method('chat')->willReturn([
            'content' => sprintf('[{"title": "ok", "message_id": 101}, {"title": "%s", "message_id": 102}]', $longTitle),
            'usage' => [],
        ]);

        $result = $this->service->digestBatch($this->user, $messages);

        self::assertCount(1, $result['proposals']);
        self::assertSame(102, $result['proposals'][0]['message_id']);
        self::assertSame(200, mb_strlen($result['proposals'][0]['title']));
    }

    public function testDigestBatchForwardsTheMessageDigestSchema(): void
    {
        $this->digestRepository->method('findDigestedMessageIds')->willReturn([]);
        $this->digestRepository->method('findTitlesForChats')->willReturn([]);

        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"digests": []}', 'usage' => []];
            }
        );

        $this->service->digestBatch($this->user, [$this->makeMessage(101, 'hi')]);

        self::assertInstanceOf(StructuredOutputSchema::class, $options['structured_output'] ?? null);
        self::assertSame('message_digest', $options['structured_output']->name);
    }

    public function testDigestBatchOmitsTheSchemaWhenTheKillSwitchIsOff(): void
    {
        $this->digestRepository->method('findDigestedMessageIds')->willReturn([]);
        $this->digestRepository->method('findTitlesForChats')->willReturn([]);

        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"digests": []}', 'usage' => []];
            }
        );

        $structuredOutputConfig = $this->createMock(StructuredOutputConfig::class);
        $structuredOutputConfig->method('isEnabled')->willReturn(false);

        $service = new MessageDigestService(
            $this->aiFacade,
            $this->modelConfigService,
            $this->rateLimitService,
            $this->promptRepository,
            $this->digestRepository,
            $this->qdrantClient,
            $this->embeddingResolver,
            new NullLogger(),
            $structuredOutputConfig,
        );

        $service->digestBatch($this->user, [$this->makeMessage(101, 'hi')]);

        self::assertArrayNotHasKey('structured_output', $options ?? []);
    }

    /**
     * The object-wrapped schema response (`{"digests": [...]}`) must parse
     * exactly like the legacy bare-array response — no parsing change was
     * needed because the extractor's regex already grabs the innermost
     * `[...]` regardless of what wraps it.
     */
    public function testWrappedSchemaResponseParsesTheSameAsABareArray(): void
    {
        $this->digestRepository->method('findDigestedMessageIds')->willReturn([]);
        $this->digestRepository->method('findTitlesForChats')->willReturn([]);
        $this->qdrantClient->method('isAvailable')->willReturn(false);
        $this->aiFacade->method('chat')->willReturn([
            'content' => '{"digests": [{"title": "office rent letter to realtor", "message_id": 102}]}',
            'usage' => [],
        ]);

        $result = $this->service->digestBatch($this->user, [$this->makeMessage(102, 'Letter to the realtor.')]);

        self::assertSame(1, $result['created']);
        self::assertSame([['title' => 'office rent letter to realtor', 'message_id' => 102]], $result['proposals']);
    }

    public function testUsageIsRecordedWithDigestSource(): void
    {
        $messages = [$this->makeMessage(101, 'hello')];

        $this->digestRepository->method('findDigestedMessageIds')->willReturn([]);
        $this->digestRepository->method('findTitlesForChats')->willReturn([]);
        $this->aiFacade->method('chat')->willReturn(['content' => '[]', 'usage' => []]);

        $this->rateLimitService->expects(self::once())
            ->method('recordUsage')
            ->with(
                $this->user,
                'MESSAGE_DIGEST',
                self::callback(static fn (array $metadata): bool => 'DIGEST' === ($metadata['source'] ?? null)),
            );

        $this->service->digestBatch($this->user, $messages);
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $idProperty = new \ReflectionProperty(User::class, 'id');
        $idProperty->setValue($user, $id);

        return $user;
    }

    private function makeMessage(int $id, string $text): Message
    {
        $message = new Message();
        $idProperty = new \ReflectionProperty(Message::class, 'id');
        $idProperty->setValue($message, $id);

        $message->setUserId(7);
        $message->setTrackingId(0);
        $message->setUnixTimestamp(1_700_000_000);
        $message->setDateTime('20231114000000');
        $message->setMessageType('WEB');
        $message->setDirection('IN');
        $message->setText($text);
        $message->setFile(0);
        $message->setFilePath('');
        $message->setFileType('');
        $message->setFileText('');
        $message->setChatId(3);

        return $message;
    }
}
