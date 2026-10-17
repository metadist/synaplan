<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\AI\ToolCalling\ToolCallingCapability;
use App\AI\ToolCalling\ToolCallingTranslator;
use App\AI\ToolCalling\ToolCallParser;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\Feedback\FeedbackConfigService;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\DocumentImageCatalog;
use App\Service\File\DocumentImageReferenceResolver;
use App\Service\File\GeneratedImageVisionFlag;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Knowledge\KnowledgeContextFormatter;
use App\Service\Memory\MemoryExtractionDispatcher;
use App\Service\Memory\UserMemoryService;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Handler\ChatHandler;
use App\Service\Message\PerfPipelineFlag;
use App\Service\Message\Routing\RoutingToolset;
use App\Service\ModelConfigService;
use App\Service\Prompt\PromptService;
use App\Service\Prompt\TimeContextBuilder;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Tests\Characterization\Support\CleanupSnapshot;
use App\Tests\Support\ChatHandlerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * What ChatHandler hands to the model, on both pipelines.
 *
 * `handle()` and `handleStream()` assemble the prompt separately
 * (`buildMessages` versus `buildStreamingMessages`, and the quoted
 * reference lands in a different place relative to web results). Each
 * pipeline has its own snapshot. The clock is frozen (2026-06-17 19:05 UTC,
 * profile timezone Europe/Berlin) so the date block does not move.
 *
 * Record with UPDATE_CLEANUP_V2_SNAPSHOTS=1.
 */
#[AllowMockObjectsWithoutExpectations]
final class ChatPromptCharacterizationTest extends TestCase
{
    private const BUFFERED_SNAPSHOT = __DIR__.'/__snapshots__/chat_prompt.json';
    private const STREAMING_SNAPSHOT = __DIR__.'/__snapshots__/chat_prompt_stream.json';

    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfig;
    private UserMemoryService&MockObject $memoryService;
    private VectorSearchService&MockObject $vectorSearch;
    private ChatHandler $handler;

    /** @var list<array<string, mixed>> */
    private array $memoryHits = [];

    /** @var list<array<string, mixed>> */
    private array $negativeFeedback = [];

    /** @var list<array<string, mixed>> */
    private array $positiveFeedback = [];

    /** @var list<array<string, mixed>> */
    private array $ragHits = [];

    private string $provider = 'test';
    private string $modelName = 'test-model';

    /** @var array{messages: array<int, mixed>|string, options: array<string, mixed>}|null */
    private ?array $lastCapture = null;

    /** @var array{messages: array<int, mixed>|string, options: array<string, mixed>}|null */
    private ?array $lastStreamCapture = null;

    private int $chatCalls = 0;
    private int $streamCalls = 0;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfig = $this->createMock(ModelConfigService::class);
        $this->memoryService = $this->createMock(UserMemoryService::class);
        $this->vectorSearch = $this->createMock(VectorSearchService::class);

        $this->modelConfig->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->modelConfig->method('resolveUsableModelId')->willReturnCallback(
            static fn (?int $modelId, string $capability = 'CHAT', ?int $userId = null): ?int => $modelId,
        );
        $this->modelConfig->method('getDefaultModel')->willReturn(10);
        $this->modelConfig->method('getProviderForModel')->willReturnCallback(fn (int $modelId): string => $this->provider);
        $this->modelConfig->method('getModelName')->willReturnCallback(fn (int $modelId): string => $this->modelName);

        $this->memoryService->method('isAvailable')->willReturn(true);
        $this->memoryService->method('getMemoryEmbeddingModelId')->willReturn(10);
        $this->memoryService->method('embedUserQuery')->willReturn(['embedding' => [0.1, 0.2]]);
        $this->memoryService->method('searchMemoriesByVector')->willReturnCallback(
            function (int $userId, array $queryVector, ?string $category = null, int $limit = 5, float $minScore = 0.0, ?string $namespace = null, bool $includeHidden = false): array {
                return match ($category) {
                    'feedback_negative' => $this->negativeFeedback,
                    'feedback_positive' => $this->positiveFeedback,
                    default => null === $category ? $this->memoryHits : [],
                };
            },
        );

        $this->vectorSearch->method('semanticSearch')->willReturnCallback(function (mixed ...$args): array {
            return $this->ragHits;
        });
        $this->vectorSearch->method('semanticSearchByVector')->willReturnCallback(function (mixed ...$args): array {
            return $this->ragHits;
        });

        $this->aiFacade->method('chat')->willReturnCallback(
            function (array|string $messages, ?int $userId = null, array $options = []): array {
                ++$this->chatCalls;
                $this->lastCapture = ['messages' => $messages, 'options' => $options];

                return ['content' => 'ok', 'provider' => $this->provider, 'model' => $this->modelName];
            },
        );
        $this->aiFacade->method('chatStream')->willReturnCallback(
            function (array|string $messages, callable $streamCallback, ?int $userId = null, array $options = []): array {
                ++$this->streamCalls;
                $this->lastStreamCapture = ['messages' => $messages, 'options' => $options];
                $streamCallback('ok');

                return ['content' => 'ok', 'provider' => $this->provider, 'model' => $this->modelName];
            },
        );

        $catalog = $this->createMock(ConversationFileCatalog::class);
        $catalog->method('build')->willReturn([]);

        $user = $this->user();
        $users = $this->createMock(EntityRepository::class);
        $users->method('find')->willReturn($user);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($users);

        $config = $this->createMock(ConfigRepository::class);
        $config->method('getValue')->willReturn(null);

        $prompt = (new Prompt())->setTopic('general')->setPrompt('You are the Synaplan test assistant. Answer clearly.');
        $prompts = $this->createMock(PromptService::class);
        $prompts->method('getPromptWithMetadata')->willReturn(['prompt' => $prompt, 'metadata' => []]);

        $structured = $this->createMock(StructuredOutputConfig::class);
        $structured->method('isEnabled')->willReturn(true);

        $digest = $this->createMock(\App\Service\Digest\MessageDigestConfig::class);
        $digest->method('isEnabled')->willReturn(false);

        $this->handler = ChatHandlerFactory::create(
            $this->aiFacade,
            $this->createMock(PromptRepository::class),
            $prompts,
            $this->modelConfig,
            $this->modelRepository(),
            new NullLogger(),
            $this->vectorSearch,
            $em,
            '/tmp/uploads',
            new UserUploadPathBuilder(),
            $this->memoryService,
            new FeedbackConfigService($config),
            $this->createMock(RateLimitService::class),
            $this->createMock(MemoryExtractionDispatcher::class),
            $this->createMock(PerfPipelineFlag::class),
            $this->createMock(DocumentGeneratorService::class),
            $this->createMock(DocumentImageReferenceResolver::class),
            $this->createMock(DocumentImageCatalog::class),
            new TimeContextBuilder(new MockClock(new \DateTimeImmutable('2026-06-17 19:05:00', new \DateTimeZone('UTC')))),
            new KnowledgeContextFormatter(),
            $this->createMock(\App\Service\Vision\VisionModelResolver::class),
            $this->createMock(\App\Service\Digest\DigestSearchService::class),
            $digest,
            $catalog,
            $this->createMock(GeneratedImageVisionFlag::class),
            $structured,
            new ToolCallingTranslator(new ToolCallingCapability()),
            new ToolCallParser(),
            new RoutingToolset(new SystemCapabilityRegistry()),
        );
    }

    public function testBufferedPromptAssemblyMatchesTheSnapshot(): void
    {
        $actual = [];
        foreach ($this->cases() as $id => $case) {
            $actual[$id] = $this->assembleBuffered($case);
        }

        self::assertContains('handoff_mediamaker', $actual['routing_handoff']['tool_names']);
        CleanupSnapshot::assertSame(self::BUFFERED_SNAPSHOT, $actual, 'buffered chat prompt');
    }

    public function testStreamingPromptAssemblyMatchesTheSnapshot(): void
    {
        $actual = [];
        foreach ($this->cases() as $id => $case) {
            $actual[$id] = $this->assembleStreaming($case);
        }

        self::assertContains('handoff_mediamaker', $actual['routing_handoff']['tool_names']);
        CleanupSnapshot::assertSame(self::STREAMING_SNAPSHOT, $actual, 'streaming chat prompt');
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return array<string, mixed>
     */
    private function assembleBuffered(array $case): array
    {
        $this->applyCase($case);
        $this->lastCapture = null;
        $this->chatCalls = 0;
        $this->streamCalls = 0;

        $this->handler->handle($case['message'], $case['thread'], $case['classification'], null, $case['options']);

        self::assertSame(1, $this->chatCalls, 'The buffered pipeline should hand exactly one message array to the model.');
        self::assertSame(0, $this->streamCalls, 'The buffered pipeline must not open a stream.');
        self::assertIsArray($this->lastCapture);

        return $this->normalize($this->lastCapture);
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return array<string, mixed>
     */
    private function assembleStreaming(array $case): array
    {
        $this->applyCase($case);
        $this->lastStreamCapture = null;
        $this->streamCalls = 0;
        $this->chatCalls = 0;

        $this->handler->handleStream(
            $case['message'],
            $case['thread'],
            $case['classification'],
            static function (string|array $chunk, array $metadata = []): void {},
            null,
            $case['options'],
        );

        self::assertSame(1, $this->streamCalls, 'The streaming pipeline should hand exactly one message array to the model.');
        self::assertSame(0, $this->chatCalls, 'The streaming pipeline must not use the buffered call.');
        self::assertIsArray($this->lastStreamCapture);

        return $this->normalize($this->lastStreamCapture);
    }

    /**
     * @param array<string, mixed> $case
     */
    private function applyCase(array $case): void
    {
        $this->memoryHits = $case['memories'];
        $this->negativeFeedback = $case['negative'];
        $this->positiveFeedback = $case['positive'];
        $this->ragHits = $case['rag'];
        $this->provider = $case['provider'];
        $this->modelName = $case['model'];
    }

    /**
     * @param array{messages: array<int, mixed>|string, options: array<string, mixed>} $captured
     *
     * @return array<string, mixed>
     */
    private function normalize(array $captured): array
    {
        $options = $captured['options'];
        $names = [];
        foreach ($options['tools'] ?? [] as $tool) {
            if (is_array($tool)) {
                $names[] = (string) ($tool['function']['name'] ?? '');
            }
        }
        sort($names);

        return [
            'messages' => $captured['messages'],
            'provider' => $options['provider'] ?? null,
            'model' => $options['model'] ?? null,
            'temperature' => $options['temperature'] ?? null,
            'max_tokens' => $options['max_tokens'] ?? null,
            'tool_choice' => $options['tool_choice'] ?? null,
            'tool_names' => $names,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function cases(): array
    {
        return [
            'plain' => $this->spec('Hello, how are you today?'),
            'with_history' => $this->spec('And the day after?', thread: [
                $this->message('What day is it?', 'IN', 90),
                $this->message('Wednesday.', 'OUT', 91),
            ]),
            'with_attachment' => $this->spec('Summarize the attached note.', fileText: "Budget line: paper.\nBudget line: ink."),
            'with_knowledge' => $this->spec('What did we decide about the office move?', classification: [
                'rag_group_key' => 'notes',
            ], memories: [[
                'id' => 42, 'key' => 'city', 'value' => 'The office is in Münster.', 'score' => 0.91,
            ]], negative: [[
                'id' => 7, 'key' => 'avoid', 'value' => 'Do not claim the office is in Berlin.', 'score' => 0.8,
            ]], positive: [[
                'id' => 8, 'key' => 'confirm', 'value' => 'The move is scheduled for July.', 'score' => 0.88,
            ]], rag: [[
                'file_name' => 'move.md', 'chunk_text' => 'The office moves in July.', 'score' => 0.77,
                'chunk_id' => 'c1', 'file_id' => 3, 'group_key' => 'notes', 'start_line' => 1, 'end_line' => 2,
            ]]),
            'with_web_and_quote' => $this->spec('Is that still true?', classification: [
                'url_content' => "Page: https://example.com/php\nPHP 8.4 was released.",
            ], options: [
                'search_results' => [
                    'query' => 'php latest release',
                    'pages_read' => 1,
                    'results' => [[
                        'title' => 'PHP releases',
                        'url' => 'https://www.php.net/releases/8.4',
                        'description' => 'PHP 8.4 is current.',
                        'age' => '2024',
                        'page_content' => 'PHP 8.4 is the current stable release.',
                    ]],
                ],
                'quoted_text' => 'We should wait for the next PHP release.',
                'quoted_message_id' => 90,
                'client_country' => 'DE',
            ]),
            'routing_handoff' => $this->spec('Draw a cat', classification: [
                'defer_routing_to_chat' => true,
            ], provider: 'anthropic', model: 'claude-sonnet-5'),
        ];
    }

    /**
     * @param list<Message>              $thread
     * @param array<string, mixed>       $classification
     * @param array<string, mixed>       $options
     * @param list<array<string, mixed>> $memories
     * @param list<array<string, mixed>> $negative
     * @param list<array<string, mixed>> $positive
     * @param list<array<string, mixed>> $rag
     *
     * @return array<string, mixed>
     */
    private function spec(
        string $text,
        array $thread = [],
        array $classification = [],
        array $options = [],
        string $fileText = '',
        array $memories = [],
        array $negative = [],
        array $positive = [],
        array $rag = [],
        string $provider = 'test',
        string $model = 'test-model',
    ): array {
        $message = $this->message($text, 'IN', 100);
        if ('' !== $fileText) {
            $message->setFile(1)->setFileText($fileText);
        }

        return [
            'message' => $message,
            'thread' => $thread,
            'classification' => ['topic' => 'general', 'language' => 'en', 'intent' => 'chat'] + $classification,
            'options' => ['client_country' => 'DE'] + $options,
            'memories' => $memories,
            'negative' => $negative,
            'positive' => $positive,
            'rag' => $rag,
            'provider' => $provider,
            'model' => $model,
        ];
    }

    private function message(string $text, string $direction, int $id): Message
    {
        $message = (new Message())
            ->setUserId(7)
            ->setTrackingId(1)
            ->setUnixTimestamp(1_750_179_900)
            ->setDateTime('20260617190500')
            ->setText($text)
            ->setDirection($direction)
            ->setTopic('CHAT')
            ->setLanguage('en');

        $idProperty = new \ReflectionProperty(Message::class, 'id');
        $idProperty->setValue($message, $id);

        return $message;
    }

    private function user(): User
    {
        $user = (new User())
            ->setMail('prompt@example.com')
            ->setUserDetails(['timezone' => 'Europe/Berlin', 'memories_enabled' => true]);

        $idProperty = new \ReflectionProperty(User::class, 'id');
        $idProperty->setValue($user, 7);

        return $user;
    }

    private function modelRepository(): ModelRepository&MockObject
    {
        $model = (new Model())
            ->setService('test')
            ->setProviderId('test-model')
            ->setJson(['supportsSystemMessages' => true]);
        $idProperty = new \ReflectionProperty(Model::class, 'id');
        $idProperty->setValue($model, 10);

        $repository = $this->createMock(ModelRepository::class);
        $repository->method('find')->willReturn($model);

        return $repository;
    }
}
