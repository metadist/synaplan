<?php

namespace App\Tests\Unit;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\Prompt;
use App\Entity\User;
use App\Message\ExtractMemoriesCommand;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\FeedbackConfigService;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\MemoryExtractionDispatcher;
use App\Service\Message\Handler\ChatHandler;
use App\Service\ModelConfigService;
use App\Service\PerfPipelineFlag;
use App\Service\Prompt\TimeContextBuilder;
use App\Service\PromptService;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\UserMemoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ChatHandlerTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private PromptRepository&MockObject $promptRepository;
    private PromptService&MockObject $promptService;
    private ModelConfigService&MockObject $modelConfigService;
    private ModelRepository&MockObject $modelRepository;
    private LoggerInterface&MockObject $logger;
    private VectorSearchService&MockObject $vectorSearchService;
    private EntityManagerInterface&MockObject $em;
    private UserUploadPathBuilder $userUploadPathBuilder;
    private UserMemoryService&MockObject $userMemoryService;
    private FeedbackConfigService $feedbackConfigService;
    private RateLimitService&MockObject $rateLimitService;
    private MemoryExtractionDispatcher&MockObject $memoryExtractionDispatcher;
    private PerfPipelineFlag&MockObject $perfPipelineFlag;
    private ChatHandler $handler;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->promptRepository = $this->createMock(PromptRepository::class);
        $this->promptService = $this->createMock(PromptService::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->vectorSearchService = $this->createMock(VectorSearchService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->userUploadPathBuilder = new UserUploadPathBuilder();
        $this->userMemoryService = $this->createMock(UserMemoryService::class);
        $this->feedbackConfigService = new FeedbackConfigService($this->createStub(ConfigRepository::class));
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->memoryExtractionDispatcher = $this->createMock(MemoryExtractionDispatcher::class);
        $this->perfPipelineFlag = $this->createMock(PerfPipelineFlag::class);

        $this->handler = new ChatHandler(
            $this->aiFacade,
            $this->promptRepository,
            $this->promptService,
            $this->modelConfigService,
            $this->modelRepository,
            $this->logger,
            $this->vectorSearchService,
            $this->em,
            '/tmp/uploads',
            $this->userUploadPathBuilder,
            $this->userMemoryService,
            $this->feedbackConfigService,
            $this->rateLimitService,
            $this->memoryExtractionDispatcher,
            $this->perfPipelineFlag,
            $this->createMock(DocumentGeneratorService::class),
            new TimeContextBuilder(),
        );
    }

    public function testGetName(): void
    {
        $this->assertEquals('chat', $this->handler->getName());
    }

    public function testHumanizeFileMarkersReplacesGeneratedMarker(): void
    {
        $result = $this->handler->humanizeFileMarkersForModel('__FILE_GENERATED__:Zweiter_Weltkrieg.docx');

        $this->assertStringNotContainsString('__FILE_GENERATED__', $result);
        $this->assertStringNotContainsString('FILE_GENERATED', $result);
        $this->assertStringContainsString('Zweiter_Weltkrieg.docx', $result);
    }

    public function testHumanizeFileMarkersReplacesFailedMarker(): void
    {
        $result = $this->handler->humanizeFileMarkersForModel('__FILE_GENERATION_FAILED__');

        $this->assertStringNotContainsString('__FILE_GENERATION_FAILED__', $result);
        $this->assertStringContainsString('could not be generated', $result);
    }

    public function testHumanizeFileMarkersLeavesRegularContentUntouched(): void
    {
        $text = 'Here is a normal assistant reply with no markers.';

        $this->assertSame($text, $this->handler->humanizeFileMarkersForModel($text));
    }

    public function testHumanizeFileMarkersHandlesNull(): void
    {
        $this->assertSame('', $this->handler->humanizeFileMarkersForModel(null));
    }

    public function testHandleUsesUserSelectedModel(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Hello');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $classification = [
            'topic' => 'CHAT',
            'language' => 'en',
            'model_id' => 42, // User-selected model
        ];

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->expects(self::any())->method('getProviderForModel')->with(42)->willReturn('ollama');
        $this->modelConfigService->expects(self::any())->method('getModelName')->with(42)->willReturn('llama3');

        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->anything(),
                1,
                $this->callback(function ($options) {
                    return 'ollama' === $options['provider'] && 'llama3' === $options['model'];
                })
            )
            ->willReturn([
                'content' => 'Response text',
                'provider' => 'ollama',
                'model' => 'llama3',
            ]);

        $result = $this->handler->handle($message, [], $classification);

        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertEquals('Response text', $result['content']);
    }

    public function testHandleFallsBackToDefaultModel(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Hello');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');
        $message->expects(self::any())->method('getMeta')->with('channel')->willReturn('web'); // Non-WhatsApp channel

        $classification = [
            'topic' => 'CHAT',
            'language' => 'en',
        ];

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->expects(self::any())->method('getEffectiveUserIdForMessage')->with($message)->willReturn(1);
        $this->modelConfigService->expects(self::any())->method('getDefaultModel')->with('CHAT', 1)->willReturn(10);
        $this->modelConfigService->expects(self::any())->method('getProviderForModel')->with(10)->willReturn('openai');
        $this->modelConfigService->expects(self::any())->method('getModelName')->with(10)->willReturn('gpt-4');

        $this->aiFacade
            ->method('chat')
            ->willReturn([
                'content' => 'Response',
                'provider' => 'openai',
                'model' => 'gpt-4',
            ]);

        $result = $this->handler->handle($message, [], $classification);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
    }

    public function testHandleExtractsJsonResponse(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Test');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $classification = ['topic' => 'CHAT', 'language' => 'en'];

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $jsonResponse = json_encode([
            'BTEXT' => 'Extracted text content',
            'BFILE' => 1,
            'BFILETEXT' => '/path/to/file.jpg',
        ]);

        $this->aiFacade
            ->method('chat')
            ->willReturn([
                'content' => $jsonResponse,
                'provider' => 'test',
                'model' => 'test',
            ]);

        $result = $this->handler->handle($message, [], $classification);

        $this->assertEquals('Extracted text content', $result['content']);
        $this->assertArrayHasKey('file', $result['metadata']);
    }

    /**
     * Regression for issue #1067: web search results are system-injected
     * context and must ride in the SYSTEM role. Putting them into the user
     * turn makes the model attribute them to the user ("the user gave web
     * search results") and blurs the prompt-injection trust boundary.
     */
    public function testHandleInjectsWebSearchResultsIntoSystemPrompt(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('wie wird das Wetter in Münster heute');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20260612120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('de');
        $message->method('getFileText')->willReturn('');

        $classification = [
            'topic' => 'CHAT',
            'language' => 'de',
            'search_results' => [
                'query' => 'Wetter Münster heute',
                'results' => [
                    [
                        'title' => 'Wetter Münster',
                        'url' => 'https://example.com/wetter',
                        'description' => 'Sonnig, 20 Grad',
                    ],
                ],
            ],
        ];

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $capturedMessages = null;
        $this->aiFacade
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$capturedMessages): array {
                $capturedMessages = $messages;

                return ['content' => 'Sonnig.', 'provider' => 'test', 'model' => 'test'];
            });

        $this->handler->handle($message, [], $classification);

        $this->assertIsArray($capturedMessages);
        $this->assertSame('system', $capturedMessages[0]['role']);
        $this->assertStringContainsString('Web Search Results', $capturedMessages[0]['content']);
        $this->assertStringContainsString('https://example.com/wetter', $capturedMessages[0]['content']);
        $this->assertStringContainsString('NOT provided by the user', $capturedMessages[0]['content']);

        foreach ($capturedMessages as $msg) {
            if ('user' !== $msg['role']) {
                continue;
            }
            $content = is_string($msg['content']) ? $msg['content'] : (string) json_encode($msg['content']);
            $this->assertStringNotContainsString('Web Search Results', $content);
        }
    }

    public function testHandleIncludesThreadMessages(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Current message');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $threadMsg = $this->createMock(Message::class);
        $threadMsg->method('getDirection')->willReturn('IN');
        $threadMsg->method('getText')->willReturn('Previous message');
        $threadMsg->method('getDateTime')->willReturn('20250116115900');

        $thread = [$threadMsg];
        $classification = ['topic' => 'CHAT', 'language' => 'en'];

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    // Should have system, thread, and current message
                    return count($messages) >= 3;
                }),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([
                'content' => 'Response',
                'provider' => 'test',
                'model' => 'test',
            ]);

        $this->handler->handle($message, $thread, $classification);
    }

    public function testHandleCallsProgressCallback(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Test');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->aiFacade->method('chat')->willReturn([
            'content' => 'Response',
            'provider' => 'test',
            'model' => 'test',
        ]);

        $callbackCalled = 0;
        $callback = function ($status) use (&$callbackCalled) {
            ++$callbackCalled;
            $this->assertArrayHasKey('status', $status);
            $this->assertArrayHasKey('message', $status);
        };

        $this->handler->handle($message, [], ['topic' => 'CHAT', 'language' => 'en'], $callback);

        $this->assertGreaterThan(0, $callbackCalled);
    }

    public function testHandleUsesUserPrompt(): void
    {
        $this->markTestSkipped('Complex mock test with repository expectations - needs refactoring');

        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(5);
        $message->method('getText')->willReturn('Test');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('de');
        $message->method('getFileText')->willReturn('');

        $userPrompt = $this->createMock(Prompt::class);
        $userPrompt->method('getPrompt')->willReturn('Custom user prompt');

        $this->promptRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['ownerId' => 5, 'language' => 'de'])
            ->willReturn($userPrompt);

        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->aiFacade->method('chat')->willReturn([
            'content' => 'Response',
            'provider' => 'test',
            'model' => 'test',
        ]);

        $this->handler->handle($message, [], ['topic' => 'CHAT', 'language' => 'de']);
    }

    public function testHandleStreamCallsStreamCallback(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Stream test');
        $message->method('getFileText')->willReturn('');

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $chunks = [];
        $streamCallback = function ($chunk) use (&$chunks) {
            $chunks[] = $chunk;
        };

        $this->aiFacade
            ->expects($this->once())
            ->method('chatStream')
            ->with(
                $this->anything(),
                $this->callback(static fn ($cb) => is_callable($cb)),
                1,
                $this->anything()
            )
            ->willReturnCallback(static function ($messages, $cb, $userId, $options) {
                // Simulate one streamed chunk coming from the provider.
                $cb('hello');

                return ['provider' => 'test', 'model' => 'test'];
            });

        $this->handler->handleStream(
            $message,
            [],
            ['topic' => 'CHAT', 'language' => 'en'],
            $streamCallback
        );

        self::assertNotEmpty($chunks);
    }

    /**
     * Rolling conversation summary: when MessageProcessor condenses older turns
     * and passes them via options['conversation_summary'], ChatHandler must fold
     * that text into the SYSTEM prompt so long threads keep their context.
     */
    public function testHandleStreamInjectsConversationSummaryIntoSystemPrompt(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('And what about the second point?');
        $message->method('getFileText')->willReturn('');

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $captured = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chatStream')
            ->willReturnCallback(function ($messages, $cb, $userId, $options) use (&$captured) {
                $captured = $messages;
                $cb('ok');

                return ['provider' => 'test', 'model' => 'test'];
            });

        $this->handler->handleStream(
            $message,
            [],
            ['topic' => 'CHAT', 'language' => 'en'],
            static function (): void {},
            null,
            ['conversation_summary' => 'Earlier: the user argued for option A and asked to compare prices.'],
        );

        self::assertNotNull($captured);
        $systemPrompt = $captured[0]['content'] ?? '';
        self::assertSame('system', $captured[0]['role'] ?? '');
        self::assertStringContainsString('Summary of earlier conversation', $systemPrompt);
        self::assertStringContainsString('option A', $systemPrompt);
    }

    public function testHandleStreamWithoutConversationSummaryLeavesSystemPromptClean(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Hello');
        $message->method('getFileText')->willReturn('');

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $captured = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chatStream')
            ->willReturnCallback(function ($messages, $cb, $userId, $options) use (&$captured) {
                $captured = $messages;
                $cb('ok');

                return ['provider' => 'test', 'model' => 'test'];
            });

        $this->handler->handleStream(
            $message,
            [],
            ['topic' => 'CHAT', 'language' => 'en'],
            static function (): void {},
        );

        self::assertNotNull($captured);
        self::assertStringNotContainsString('Summary of earlier conversation', $captured[0]['content'] ?? '');
    }

    /**
     * Issue #615: the non-streaming `handle()` path serves email
     * (`smart+...@synaplan.net`) and the generic API webhook. Before the
     * fix, neither loaded user memories nor extracted new ones. This
     * test pins the regression: when Qdrant has relevant memories,
     * `handle()` must inject them into the system prompt that ships
     * with the AI request.
     */
    public function testHandleLoadsMemoriesIntoSystemPromptForEmailChannel(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getId')->willReturn(123);
        $message->method('getText')->willReturn('Where do I live?');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20260512100000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');
        $message->method('getDirection')->willReturn('IN');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('isMemoriesEnabled')->willReturn(true);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::any())->method('find')->with(7)->willReturn($user);
        $this->em->expects(self::any())->method('getRepository')->with(User::class)->willReturn($userRepository);

        $this->userMemoryService->method('isAvailable')->willReturn(true);
        // PR #985 follow-up: ChatHandler now embeds memory queries via
        // `embedQueryForMemorySearch` (sticky memory model) instead of
        // reusing the shared VECTORIZE embedding unconditionally. Mock
        // both so the test stays valid whether or not the memory
        // resolver decides to reuse the shared vector.
        $this->userMemoryService->method('embedUserQuery')->willReturn(['embedding' => [0.1, 0.2, 0.3]]);
        $this->userMemoryService->method('embedQueryForMemorySearch')->willReturn(['embedding' => [0.1, 0.2, 0.3]]);
        $this->userMemoryService->method('getMemoryEmbeddingModelId')->willReturn(10);
        $this->userMemoryService
            ->method('searchMemoriesByVector')
            ->willReturnCallback(function (int $userId, array $vec, ...$rest) {
                $category = $rest[0] ?? null;
                if (null === $category) {
                    return [
                        ['id' => 42, 'key' => 'city', 'value' => 'Hamburg', 'score' => 0.91],
                    ];
                }

                return [];
            });

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->modelConfigService->method('getDefaultModel')->willReturn(10);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('gpt-4.1');

        $capturedMessages = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages, int $userId, array $options) use (&$capturedMessages) {
                $capturedMessages = $messages;

                return [
                    'content' => 'You live in Hamburg.',
                    'provider' => 'openai',
                    'model' => 'gpt-4.1',
                ];
            });

        $this->perfPipelineFlag->method('isEnabled')->willReturn(true);
        // No dispatcher expectation here — this test only checks that the
        // memory loader injected the matched memories into the system
        // prompt; the dispatch is covered by other tests.

        $result = $this->handler->handle($message, [], ['topic' => 'CHAT', 'language' => 'en']);

        self::assertNotNull($capturedMessages, 'aiFacade->chat() should have been called');
        $systemMessage = $capturedMessages[0]['content'] ?? '';
        self::assertStringContainsString(
            'User Memories',
            $systemMessage,
            'system prompt must include the memories section so email replies use stored memories'
        );
        self::assertStringContainsString(
            'Hamburg',
            $systemMessage,
            'system prompt must contain the actual memory value'
        );
        self::assertSame([
            ['id' => 42, 'key' => 'city', 'value' => 'Hamburg', 'score' => 0.91],
        ], $result['metadata']['memories']);
    }

    /**
     * Issue #615: after generating a reply, the non-streaming path must
     * also dispatch `ExtractMemoriesCommand` so new facts (e.g. a user
     * sharing their new address by email) end up in long-term storage.
     */
    public function testHandleDispatchesMemoryExtractionAfterResponse(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getId')->willReturn(456);
        $message->method('getText')->willReturn('My new address is Bahnhofstrasse 1.');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20260512100000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('isMemoriesEnabled')->willReturn(true);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::any())->method('find')->with(7)->willReturn($user);
        $this->em->expects(self::any())->method('getRepository')->with(User::class)->willReturn($userRepository);

        $this->userMemoryService->method('isAvailable')->willReturn(false);

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->modelConfigService->method('getDefaultModel')->willReturn(10);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('gpt-4.1');

        $this->aiFacade->method('chat')->willReturn([
            'content' => 'Got it, I will remember your new address.',
            'provider' => 'openai',
            'model' => 'gpt-4.1',
        ]);

        $this->perfPipelineFlag->expects(self::any())->method('isEnabled')->with(7)->willReturn(true);

        $dispatched = null;
        $this->memoryExtractionDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (?ExtractMemoriesCommand $cmd) use (&$dispatched): void {
                $dispatched = $cmd;
            });

        $this->handler->handle($message, [], ['topic' => 'CHAT', 'language' => 'en']);

        self::assertInstanceOf(ExtractMemoriesCommand::class, $dispatched);
        self::assertSame(456, $dispatched->getMessageId());
        self::assertSame(7, $dispatched->getUserId());
        self::assertStringContainsString('remember your new address', $dispatched->getAiResponse());
    }

    /**
     * Widget requests opt out of memory loading + extraction (privacy:
     * widget visitors are anonymous embeds). Verifies the
     * `disable_memories` flag short-circuits before we ever talk to
     * Qdrant or the messenger bus.
     */
    public function testHandleSkipsMemoriesForWidgetSource(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getText')->willReturn('Hi widget');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20260512100000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $user = $this->createMock(User::class);
        $user->method('isMemoriesEnabled')->willReturn(true);
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('find')->willReturn($user);
        $this->em->method('getRepository')->willReturn($userRepository);

        // If the disable flag is honoured we never reach Qdrant; if a
        // future regression bypasses it, the mock's strict expectations
        // below will fail the test. Includes the memory-pinned embed
        // path added in #985-followup so a future split that only
        // disables the shared (RAG) branch can't accidentally re-enable
        // memory reads.
        $this->userMemoryService->expects($this->never())->method('embedUserQuery');
        $this->userMemoryService->expects($this->never())->method('embedQueryForMemorySearch');
        $this->userMemoryService->expects($this->never())->method('searchMemoriesByVector');
        $this->userMemoryService->expects($this->never())->method('searchRelevantMemories');

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->modelConfigService->method('getDefaultModel')->willReturn(10);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('gpt-4.1');

        $this->aiFacade->method('chat')->willReturn([
            'content' => 'Hi there',
            'provider' => 'openai',
            'model' => 'gpt-4.1',
        ]);

        // No extraction either — widget visitors must not leave a memory trail.
        // The proxy in ChatHandler still forwards to the dispatcher, but the
        // payload must be null so the dispatcher (whose contract is exercised
        // separately in MemoryExtractionDispatcherTest) short-circuits.
        $this->memoryExtractionDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->with($this->isNull());

        $this->handler->handle(
            $message,
            [],
            ['topic' => 'CHAT', 'language' => 'en', 'source' => 'widget'],
        );
    }

    /**
     * PR #925 Copilot follow-up: previously the dispatch helper received a
     * single combined "disabled" boolean and always logged "Skipping
     * memory extraction for widget request" — misleading when the actual
     * reason was the user toggling memories off in their settings. The
     * helper now takes two flags and logs the precise reason so
     * production operators can tell the cases apart.
     */
    public function testHandleLogsUserSettingReasonWhenMemoriesDisabledByUser(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getId')->willReturn(789);
        $message->method('getText')->willReturn('My new dog is called Bruno.');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20260513120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('isMemoriesEnabled')->willReturn(false); // ← user toggled memories off

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::any())->method('find')->with(7)->willReturn($user);
        $this->em->expects(self::any())->method('getRepository')->with(User::class)->willReturn($userRepository);

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->modelConfigService->method('getDefaultModel')->willReturn(10);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('gpt-4.1');

        $this->aiFacade->method('chat')->willReturn([
            'content' => 'Got it.',
            'provider' => 'openai',
            'model' => 'gpt-4.1',
        ]);

        // No real extraction because the user disabled memories — the
        // proxy in ChatHandler still forwards to the dispatcher, but the
        // payload must be null so the dispatcher (tested separately in
        // MemoryExtractionDispatcherTest) short-circuits before touching
        // the messenger bus.
        $this->memoryExtractionDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->with($this->isNull());

        // Capture every info-level log call and assert the exact reason
        // string surfaces — the request log is the regression we are
        // explicitly NOT supposed to emit here. The callback declares the
        // PSR-3 `(message, context)` signature so PHPUnit's pass-through
        // never trips an ArgumentCountError when ChatHandler ships a
        // context array alongside the message.
        $infoMessages = [];
        $this->logger
            ->method('info')
            ->willReturnCallback(function (string $message, array $context = []) use (&$infoMessages): void {
                $infoMessages[] = $message;
            });

        $this->handler->handle(
            $message,
            [],
            ['topic' => 'CHAT', 'language' => 'en'],
            null,
            ['channel' => 'email'], // non-widget channel
        );

        self::assertContains(
            'ChatHandler: Memory extraction disabled by user setting, skipping',
            $infoMessages,
            'dispatchMemoryExtractionAsync() must log the user-setting reason when the user has memories disabled',
        );
        self::assertNotContains(
            'ChatHandler: Memory extraction disabled by request, skipping',
            $infoMessages,
            'must not emit the request-level log when the cause is the user setting',
        );
    }

    public function testHandleStreamLoadsPromptMetadataForTaskPrompt(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('What do you know about Cursor Ultra?');
        $message->method('getFileText')->willReturn('');
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        // Mock PromptService to return prompt data with metadata
        $promptMock = $this->createMock(Prompt::class);
        $promptMock->method('getPrompt')->willReturn('You are an AI assistant with knowledge about Cristian Grosu.');

        $this->promptService
            ->expects($this->once())
            ->method('getPromptWithMetadata')
            ->with('cristian', 1, 'en')
            ->willReturn([
                'prompt' => $promptMock,
                'metadata' => [
                    'aiModel' => -1,
                    'tool_internet' => true,
                    'tool_files' => true,
                    'tool_url_screenshot' => false,
                ],
            ]);

        $this->modelConfigService->method('getDefaultModel')->willReturn(30);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('gpt-4.1');

        $model = $this->createMock(Model::class);
        $model->method('getFeatures')->willReturn([]);
        $model->method('getJson')->willReturn(['supportsStreaming' => true]);
        $this->modelRepository->method('find')->willReturn($model);

        $streamCallback = function ($chunk) {};

        $this->aiFacade
            ->expects($this->once())
            ->method('chatStream')
            ->willReturn(['provider' => 'openai', 'model' => 'gpt-4.1']);

        // Test passes if RAG context loading doesn't throw exception (graceful degradation)
        $this->handler->handleStream(
            $message,
            [],
            ['topic' => 'cristian', 'language' => 'en'],
            $streamCallback
        );
    }

    /**
     * Issue #881: when the caller passes `defer_memory_extraction = true`
     * the ChatHandler must NOT dispatch the ExtractMemoriesCommand
     * itself (would race the StreamController flush of the outgoing
     * assistant message). Instead it returns the prepared command in
     * `metadata.extraction_payload` so the caller can fire it after
     * persisting the OUT row.
     */
    public function testHandleDefersMemoryExtractionWhenOptionIsSet(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getId')->willReturn(456);
        $message->method('getText')->willReturn('My new address is Bahnhofstrasse 1.');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20260512100000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('isMemoriesEnabled')->willReturn(true);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::any())->method('find')->with(7)->willReturn($user);
        $this->em->expects(self::any())->method('getRepository')->with(User::class)->willReturn($userRepository);

        $this->userMemoryService->method('isAvailable')->willReturn(false);

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->modelConfigService->method('getDefaultModel')->willReturn(10);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('gpt-4.1');

        $this->aiFacade->method('chat')->willReturn([
            'content' => 'Got it.',
            'provider' => 'openai',
            'model' => 'gpt-4.1',
        ]);

        $this->perfPipelineFlag->expects(self::any())->method('isEnabled')->with(7)->willReturn(true);

        // The crucial expectation: the handler must NOT touch the bus
        // when the dispatch is deferred — that is the whole point of
        // the option. The StreamController fires it later, after the
        // outgoing message flush.
        $this->memoryExtractionDispatcher->expects($this->never())->method('dispatch');

        $result = $this->handler->handle(
            $message,
            [],
            ['topic' => 'CHAT', 'language' => 'en'],
            null,
            ['defer_memory_extraction' => true],
        );

        self::assertArrayHasKey('extraction_payload', $result['metadata']);
        $payload = $result['metadata']['extraction_payload'];
        self::assertInstanceOf(ExtractMemoriesCommand::class, $payload);
        self::assertSame(456, $payload->getMessageId());
        self::assertSame(7, $payload->getUserId());
        self::assertStringContainsString('Got it', $payload->getAiResponse());
    }

    /**
     * Issue #881: even when extraction is deferred, the same
     * skip-conditions (request opt-out, user setting opt-out,
     * kill-switch) must short-circuit BEFORE building the payload.
     * Returning a non-null payload for a widget visitor would leak the
     * dispatch back into the request lifecycle and rebuild the privacy
     * regression we already fixed in PR #925.
     */
    public function testHandleSkipsExtractionPayloadForWidgetEvenWhenDeferred(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getText')->willReturn('Hi widget');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20260512100000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $user = $this->createMock(User::class);
        $user->method('isMemoriesEnabled')->willReturn(true);
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('find')->willReturn($user);
        $this->em->method('getRepository')->willReturn($userRepository);

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->modelConfigService->method('getDefaultModel')->willReturn(10);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('gpt-4.1');

        $this->aiFacade->method('chat')->willReturn([
            'content' => 'Hi there',
            'provider' => 'openai',
            'model' => 'gpt-4.1',
        ]);

        $this->memoryExtractionDispatcher->expects($this->never())->method('dispatch');

        $result = $this->handler->handle(
            $message,
            [],
            ['topic' => 'CHAT', 'language' => 'en', 'source' => 'widget'],
            null,
            [
                'defer_memory_extraction' => true,
                // Mirrors what the StreamController/widget flow sets.
            ],
        );

        self::assertNull(
            $result['metadata']['extraction_payload'],
            'widget requests must not produce an extraction payload, even with defer_memory_extraction set',
        );
    }

    /**
     * The public {@see ChatHandler::dispatchPendingMemoryExtraction()} is
     * now a thin proxy onto {@see MemoryExtractionDispatcher::dispatch()}
     * (Copilot review of PR #939: keep the dispatch + log + swallow
     * contract in one service so this path and the
     * StreamController-deferred path cannot drift). The null-safety
     * contract is therefore the dispatcher's job — exercised in
     * {@see Service\MemoryExtractionDispatcherTest} —
     * and this test only pins that the wrapper forwards a null payload
     * unchanged so callers can blindly hand the result of
     * `buildPendingMemoryExtraction()` over without conditionals.
     */
    public function testDispatchPendingMemoryExtractionForwardsNullToDispatcher(): void
    {
        $this->memoryExtractionDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isNull());

        $this->handler->dispatchPendingMemoryExtraction(null);
    }

    /**
     * The dispatch helper must forward the prepared command to the
     * messenger bus exactly once — that is the whole reason for
     * splitting build + dispatch (issue #881 race fix).
     */
    public function testDispatchPendingMemoryExtractionForwardsCommandToBus(): void
    {
        $command = new ExtractMemoriesCommand(
            messageId: 123,
            userId: 7,
            aiResponse: 'hello',
            threadSnapshot: [],
            relevantMemories: [],
        );

        $this->memoryExtractionDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->identicalTo($command));

        $this->handler->dispatchPendingMemoryExtraction($command);
    }

    /**
     * GPT-5.5 Pro officemaker regression: models whose JSON says
     * `supportsStreaming: false` (and nothing else) are treated as "no
     * system-role support" by the legacy heuristic. The system prompt —
     * including the whole topic prompt — used to be silently DROPPED,
     * so officemaker never received its JSON file contract and produced
     * prose instead of a document. The prompt must now be folded into
     * the first user turn instead.
     */
    public function testHandleFoldsSystemPromptIntoFirstUserTurnWhenModelRejectsSystemRole(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Erstelle mir ein Word-Dokument');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20260703090000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('officemaker');
        $message->method('getLanguage')->willReturn('de');
        $message->method('getFileText')->willReturn('');

        $prompt = $this->createMock(Prompt::class);
        $prompt->method('getPrompt')->willReturn('OFFICEMAKER-PROMPT-MARKER: respond with {"BFILEPATH":...}');
        $this->promptService
            ->method('getPromptWithMetadata')
            ->willReturn(['prompt' => $prompt, 'metadata' => []]);

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->expects(self::any())->method('getProviderForModel')->with(206)->willReturn('openai');
        $this->modelConfigService->expects(self::any())->method('getModelName')->with(206)->willReturn('gpt-5.5-pro');

        // No supportsSystemMessages key → legacy "no streaming ⇒ no system role" heuristic fires.
        $model = $this->createMock(Model::class);
        $model->method('getJson')->willReturn(['supportsStreaming' => false]);
        $this->modelRepository->expects(self::any())->method('find')->with(206)->willReturn($model);

        $capturedMessages = null;
        $this->aiFacade
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$capturedMessages): array {
                $capturedMessages = $messages;

                return ['content' => '{"BFILEPATH":"doc.docx","BFILETEXT":"..."}', 'provider' => 'openai', 'model' => 'gpt-5.5-pro'];
            });

        $this->handler->handle($message, [], ['topic' => 'officemaker', 'language' => 'de', 'model_id' => 206]);

        self::assertIsArray($capturedMessages);
        foreach ($capturedMessages as $msg) {
            self::assertNotSame('system', $msg['role'], 'model without system-role support must not receive a system message');
        }

        $firstUser = null;
        foreach ($capturedMessages as $msg) {
            if ('user' === $msg['role']) {
                $firstUser = $msg;
                break;
            }
        }
        self::assertNotNull($firstUser, 'there must be a user turn');
        $content = is_string($firstUser['content']) ? $firstUser['content'] : (string) json_encode($firstUser['content']);
        self::assertStringContainsString(
            'OFFICEMAKER-PROMPT-MARKER',
            $content,
            'the system prompt must be folded into the first user turn instead of being dropped'
        );
        self::assertStringContainsString('Erstelle mir ein Word-Dokument', $content);
    }

    /**
     * Companion to the catalog fix: an explicit `supportsSystemMessages: true`
     * must override the legacy "no streaming ⇒ no system role" convention, so
     * GPT-5.5 Pro (Responses API, takes system prompts via `instructions`)
     * keeps its system message even though streaming is unsupported.
     */
    public function testHandleKeepsSystemRoleWhenExplicitFlagOverridesStreamingHeuristic(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Erstelle mir ein Word-Dokument');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20260703090000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('officemaker');
        $message->method('getLanguage')->willReturn('de');
        $message->method('getFileText')->willReturn('');

        $prompt = $this->createMock(Prompt::class);
        $prompt->method('getPrompt')->willReturn('OFFICEMAKER-PROMPT-MARKER: respond with {"BFILEPATH":...}');
        $this->promptService
            ->method('getPromptWithMetadata')
            ->willReturn(['prompt' => $prompt, 'metadata' => []]);

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->expects(self::any())->method('getProviderForModel')->with(206)->willReturn('openai');
        $this->modelConfigService->expects(self::any())->method('getModelName')->with(206)->willReturn('gpt-5.5-pro');

        $model = $this->createMock(Model::class);
        $model->method('getJson')->willReturn(['supportsStreaming' => false, 'supportsSystemMessages' => true]);
        $this->modelRepository->expects(self::any())->method('find')->with(206)->willReturn($model);

        $capturedMessages = null;
        $this->aiFacade
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$capturedMessages): array {
                $capturedMessages = $messages;

                return ['content' => 'ok', 'provider' => 'openai', 'model' => 'gpt-5.5-pro'];
            });

        $this->handler->handle($message, [], ['topic' => 'officemaker', 'language' => 'de', 'model_id' => 206]);

        self::assertIsArray($capturedMessages);
        self::assertSame('system', $capturedMessages[0]['role']);
        self::assertStringContainsString('OFFICEMAKER-PROMPT-MARKER', $capturedMessages[0]['content']);
    }

    /**
     * Streaming variant of the fold-into-user-turn fallback: handleStream()
     * had the identical silent-drop bug (its comment even claimed the prompt
     * "will be prepended to first user message instead" — it never was).
     */
    public function testHandleStreamFoldsSystemPromptIntoFirstUserTurnWhenModelRejectsSystemRole(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Erstelle mir ein Word-Dokument');
        $message->method('getFileText')->willReturn('');
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $prompt = $this->createMock(Prompt::class);
        $prompt->method('getPrompt')->willReturn('OFFICEMAKER-PROMPT-MARKER: respond with {"BFILEPATH":...}');
        $this->promptService
            ->method('getPromptWithMetadata')
            ->willReturn(['prompt' => $prompt, 'metadata' => []]);

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->expects(self::any())->method('getProviderForModel')->with(206)->willReturn('openai');
        $this->modelConfigService->expects(self::any())->method('getModelName')->with(206)->willReturn('gpt-5.5-pro');

        $model = $this->createMock(Model::class);
        $model->method('getFeatures')->willReturn([]);
        $model->method('getJson')->willReturn(['supportsStreaming' => false]);
        $this->modelRepository->expects(self::any())->method('find')->with(206)->willReturn($model);

        $capturedMessages = null;
        $this->aiFacade
            ->method('chatStream')
            ->willReturnCallback(function (array $messages, callable $cb) use (&$capturedMessages): array {
                $capturedMessages = $messages;
                $cb('chunk');

                return ['provider' => 'openai', 'model' => 'gpt-5.5-pro'];
            });

        $this->handler->handleStream(
            $message,
            [],
            ['topic' => 'officemaker', 'language' => 'de', 'model_id' => 206],
            static function ($chunk): void {},
        );

        self::assertIsArray($capturedMessages);
        foreach ($capturedMessages as $msg) {
            self::assertNotSame('system', $msg['role']);
        }
        $firstUser = null;
        foreach ($capturedMessages as $msg) {
            if ('user' === $msg['role']) {
                $firstUser = $msg;
                break;
            }
        }
        self::assertNotNull($firstUser);
        $content = is_string($firstUser['content']) ? $firstUser['content'] : (string) json_encode($firstUser['content']);
        self::assertStringContainsString('OFFICEMAKER-PROMPT-MARKER', $content);
    }

    public function testFormatQuotedReferenceReturnsEmptyWhenNoQuote(): void
    {
        $this->assertSame('', $this->invokeFormatQuotedReference([]));
        $this->assertSame('', $this->invokeFormatQuotedReference(['quoted_text' => '   ']));
    }

    public function testFormatQuotedReferenceWrapsExcerpt(): void
    {
        $result = $this->invokeFormatQuotedReference(['quoted_text' => 'FrankenPHP powers the runtime.']);

        $this->assertStringContainsString('## Quoted reference from the conversation', $result);
        $this->assertStringContainsString('FrankenPHP powers the runtime.', $result);
        $this->assertStringContainsString('primary reference point', $result);
    }

    public function testFormatQuotedReferenceTruncatesOversizedExcerpt(): void
    {
        $longText = str_repeat('a', 5000);
        $result = $this->invokeFormatQuotedReference(['quoted_text' => $longText]);

        // 4000 char cap + ellipsis, plus the surrounding template text.
        $this->assertStringContainsString('…', $result);
        $this->assertLessThan(5000, mb_strlen($result));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function invokeFormatQuotedReference(array $options): string
    {
        $method = new \ReflectionMethod(ChatHandler::class, 'formatQuotedReferenceForPrompt');
        $method->setAccessible(true);

        /** @var string $result */
        $result = $method->invoke($this->handler, $options);

        return $result;
    }
}
