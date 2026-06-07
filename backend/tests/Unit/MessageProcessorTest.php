<?php

namespace App\Tests\Unit;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Repository\SearchResultRepository;
use App\Service\Message\InferenceRouter;
use App\Service\Message\MessageClassifier;
use App\Service\Message\MessagePreProcessor;
use App\Service\Message\MessageProcessor;
use App\Service\Message\SearchQueryGenerator;
use App\Service\ModelConfigService;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\TaskPlanner;
use App\Service\Multitask\TaskPlanStore;
use App\Service\PromptService;
use App\Service\Search\BraveSearchService;
use App\Service\UrlContentService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MessageProcessorTest extends TestCase
{
    private MessageRepository&MockObject $messageRepository;
    private SearchResultRepository&MockObject $searchResultRepository;
    private MessagePreProcessor&MockObject $preProcessor;
    private MessageClassifier&MockObject $classifier;
    private InferenceRouter&MockObject $router;
    private ModelConfigService&MockObject $modelConfigService;
    private PromptService&MockObject $promptService;
    private BraveSearchService&MockObject $braveSearchService;
    private SearchQueryGenerator&MockObject $searchQueryGenerator;
    private LoggerInterface&MockObject $logger;
    private MessageProcessor $processor;

    protected function setUp(): void
    {
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->searchResultRepository = $this->createMock(SearchResultRepository::class);
        $this->preProcessor = $this->createMock(MessagePreProcessor::class);
        $this->classifier = $this->createMock(MessageClassifier::class);
        $this->router = $this->createMock(InferenceRouter::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->promptService = $this->createMock(PromptService::class);
        $this->braveSearchService = $this->createMock(BraveSearchService::class);
        $this->searchQueryGenerator = $this->createMock(SearchQueryGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->processor = new MessageProcessor(
            $this->messageRepository,
            $this->searchResultRepository,
            $this->preProcessor,
            $this->classifier,
            $this->router,
            $this->modelConfigService,
            $this->promptService,
            $this->braveSearchService,
            $this->searchQueryGenerator,
            $this->createMock(UrlContentService::class),
            $this->logger,
            $this->createMock(MultitaskRoutingConfig::class),
            $this->createMock(TaskPlanner::class),
            $this->createMock(TaskPlanStore::class)
        );
    }

    public function testProcessCompletesPipeline(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);

        $this->preProcessor
            ->expects($this->once())
            ->method('process')
            ->with($message)
            ->willReturn($message);

        $this->messageRepository
            ->expects($this->once())
            ->method('findConversationHistory')
            ->willReturn([]);

        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->classifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn([
                'topic' => 'CHAT',
                'language' => 'en',
                'source' => 'ai_sorting',
            ]);

        $this->router
            ->expects($this->once())
            ->method('route')
            ->willReturn([
                'content' => 'Response',
                'metadata' => ['provider' => 'test', 'model' => 'test'],
            ]);

        $result = $this->processor->process($message);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('classification', $result);
    }

    public function testProcessCallsStatusCallback(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);

        $this->preProcessor->method('process')->willReturn($message);
        $this->messageRepository->method('findConversationHistory')->willReturn([]);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->classifier->method('classify')->willReturn([
            'topic' => 'CHAT',
            'language' => 'en',
            'source' => 'ai_sorting',
        ]);
        $this->router->method('route')->willReturn([
            'content' => 'Response',
            'metadata' => ['provider' => 'test', 'model' => 'test'],
        ]);

        $statuses = [];
        $callback = function ($status) use (&$statuses) {
            $statuses[] = $status['status'];
        };

        $this->processor->process($message, [], $callback);

        $this->assertContains('started', $statuses);
        $this->assertContains('preprocessing', $statuses);
        $this->assertContains('classifying', $statuses);
        $this->assertContains('classified', $statuses);
        $this->assertContains('generating', $statuses);
        $this->assertContains('complete', $statuses);
    }

    public function testProcessHandlesProviderException(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);

        $this->preProcessor->method('process')->willReturn($message);
        $this->messageRepository->method('findConversationHistory')->willReturn([]);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->classifier->method('classify')->willReturn([
            'topic' => 'CHAT',
            'language' => 'en',
            'source' => 'ai_sorting',
        ]);

        $exception = new \App\AI\Exception\ProviderException(
            'Model not found',
            'ollama',
            ['install_command' => 'ollama pull llama3']
        );

        $this->router->method('route')->willThrowException($exception);

        $result = $this->processor->process($message);

        $this->assertFalse($result['success']);
        $this->assertEquals('Model not found', $result['error']);
        $this->assertEquals('ollama', $result['provider']);
        $this->assertArrayHasKey('context', $result);
    }

    public function testProcessHandlesGenericException(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);
        $message->method('getId')->willReturn(1);

        $this->preProcessor->method('process')->willReturn($message);
        $this->messageRepository->method('findConversationHistory')->willReturn([]);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->classifier->method('classify')->willReturn([
            'topic' => 'CHAT',
            'language' => 'en',
            'source' => 'ai_sorting',
        ]);

        $this->router->method('route')->willThrowException(new \Exception('Generic error'));

        $result = $this->processor->process($message);

        $this->assertFalse($result['success']);
        $this->assertEquals('Generic error', $result['error']);
    }

    public function testProcessStreamCallsStreamCallback(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);

        $this->preProcessor->method('process')->willReturn($message);
        $this->messageRepository->method('findConversationHistory')->willReturn([]);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->classifier->method('classify')->willReturn([
            'topic' => 'CHAT',
            'language' => 'en',
            'source' => 'ai_sorting',
        ]);

        $streamCalled = false;
        $streamCallback = function ($chunk) use (&$streamCalled) {
            $streamCalled = true;
        };

        $this->router
            ->expects($this->once())
            ->method('routeStream')
            ->with(
                $message,
                $this->anything(),
                $this->anything(),
                $streamCallback,
                $this->anything(),
                $this->anything()
            )
            ->willReturn(['metadata' => ['provider' => 'test', 'model' => 'test']]);

        $result = $this->processor->processStream($message, $streamCallback);

        $this->assertTrue($result['success']);
    }

    public function testProcessStreamPassesOptions(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);

        $this->preProcessor->method('process')->willReturn($message);
        $this->messageRepository->method('findConversationHistory')->willReturn([]);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->classifier->method('classify')->willReturn([
            'topic' => 'CHAT',
            'language' => 'en',
            'source' => 'ai_sorting',
        ]);

        $options = ['reasoning' => true, 'temperature' => 0.5];

        $this->router
            ->expects($this->once())
            ->method('routeStream')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                // Phase 0 instrumentation injects `perf_timer` into options so
                // ChatHandler can mark first-token latency. Verify the original
                // caller-supplied options are preserved alongside it instead of
                // pinning to an exact-match array (which would lock out future
                // additive options like resolved_prompt_data).
                $this->callback(static function (array $passedOptions) use ($options): bool {
                    foreach ($options as $key => $value) {
                        if (!array_key_exists($key, $passedOptions) || $passedOptions[$key] !== $value) {
                            return false;
                        }
                    }

                    return $passedOptions['perf_timer'] instanceof \App\Service\PerfTimer;
                })
            )
            ->willReturn(['metadata' => ['provider' => 'test', 'model' => 'test']]);

        $this->processor->processStream($message, function () {}, null, $options);
    }

    /**
     * Locks down the project-wide "rather search than not" policy:
     * a chat-friendly topic with NO explicit `tool_internet` opinion
     * must trigger search. This is the new default after Variante B —
     * the user no longer has to enable a toggle to get search.
     */
    public function testProcessStreamSearchesByDefaultForChatTopic(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);
        $message->method('getId')->willReturn(98);
        $message->method('getText')->willReturn('Hallo, was sind die aktuellen News?');

        $this->preProcessor->method('process')->willReturn($message);
        $this->messageRepository->method('findConversationHistory')->willReturn([]);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->classifier->method('classify')->willReturn([
            'topic' => 'general',
            'language' => 'de',
            'source' => 'fast_path_heuristic',
        ]);

        // No tool_internet opinion → project default kicks in (search).
        $this->promptService
            ->method('getPromptWithMetadata')
            ->willReturn(['metadata' => []]);

        $this->braveSearchService->method('isEnabled')->willReturn(true);
        $this->searchQueryGenerator->method('generate')->willReturn('aktuelle News');

        $this->braveSearchService
            ->expects($this->once())
            ->method('search')
            ->willReturn(['results' => []]);

        $this->router
            ->method('routeStream')
            ->willReturn(['metadata' => ['provider' => 'test', 'model' => 'test']]);

        $this->processor->processStream($message, function (): void {});
    }

    /**
     * Locks down the NON_WEB_SEARCH-topic exclusion: a media-generation
     * topic without an explicit opt-in must NOT trigger search, because
     * the handler does not consume web context.
     */
    public function testProcessStreamSkipsSearchForMediaGenerationTopic(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);
        $message->method('getId')->willReturn(97);
        $message->method('getText')->willReturn('Erstelle ein Bild von einem schwarzen Kater');

        $this->preProcessor->method('process')->willReturn($message);
        $this->messageRepository->method('findConversationHistory')->willReturn([]);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->classifier->method('classify')->willReturn([
            'topic' => 'mediamaker',
            'language' => 'de',
            'source' => 'ai_sorting',
        ]);

        // No opt-in → NON_WEB_SEARCH gate suppresses search.
        $this->promptService
            ->method('getPromptWithMetadata')
            ->willReturn(['metadata' => []]);

        $this->braveSearchService->method('isEnabled')->willReturn(true);

        $this->braveSearchService
            ->expects($this->never())
            ->method('search');

        $this->router
            ->method('routeStream')
            ->willReturn(['metadata' => ['provider' => 'test', 'model' => 'test']]);

        $this->processor->processStream($message, function (): void {});
    }

    /**
     * Regression test for the silent "Internet Search" toggle bug.
     *
     * Setup: a German message that would otherwise NOT trigger the
     * classifier's web_search hint (no keyword from WEB_SEARCH_KEYWORDS).
     * The task prompt has `tool_internet=true` set in the user's UI.
     *
     * Before the fix `processStream()` had no positive trigger for the
     * prompt flag — only a negative gate that read the wrong key
     * (`tool_internet_search`). The user toggle was therefore ignored.
     *
     * After the fix the streaming pipeline must mirror the non-streaming
     * `process()` path and call `BraveSearchService::search()` exactly
     * once when the prompt opts in via `tool_internet`.
     */
    public function testProcessStreamTriggersWebSearchFromPromptToolInternetFlag(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);
        $message->method('getId')->willReturn(99);
        $message->method('getText')->willReturn('Erzähl mir etwas über Eigentumswohnungen in München');

        $this->preProcessor->method('process')->willReturn($message);
        $this->messageRepository->method('findConversationHistory')->willReturn([]);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        // Classifier picks a non-search-worthy topic and does NOT set
        // web_search — so the only way search can fire is the prompt flag.
        $this->classifier->method('classify')->willReturn([
            'topic' => 'company',
            'language' => 'de',
            'source' => 'ai_sorting',
            'web_search' => false,
        ]);

        // Prompt metadata has internet search opted-in by the user's UI.
        $this->promptService
            ->method('getPromptWithMetadata')
            ->willReturn([
                'metadata' => ['tool_internet' => true],
            ]);

        $this->braveSearchService->method('isEnabled')->willReturn(true);
        $this->searchQueryGenerator->method('generate')->willReturn('Eigentumswohnungen München Preise');

        $braveSearchCalled = false;
        $this->braveSearchService
            ->expects($this->once())
            ->method('search')
            ->willReturnCallback(function (string $query) use (&$braveSearchCalled): array {
                $braveSearchCalled = true;

                return ['results' => [], 'query' => $query];
            });

        $this->router
            ->method('routeStream')
            ->willReturn(['metadata' => ['provider' => 'test', 'model' => 'test']]);

        $this->processor->processStream($message, function (): void {});

        $this->assertTrue(
            $braveSearchCalled,
            'BraveSearchService::search() must be called when the resolved task prompt has tool_internet=true',
        );
    }

    /**
     * Negative gate: when the prompt explicitly disables internet search
     * the streaming pipeline must NOT call BraveSearchService — even if
     * the frontend passed `web_search=true`.
     */
    public function testProcessStreamRespectsPromptToolInternetFalseAsHardDisable(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);
        $message->method('getId')->willReturn(100);
        $message->method('getText')->willReturn('Was kostet ein iPhone heute?');

        $this->preProcessor->method('process')->willReturn($message);
        $this->messageRepository->method('findConversationHistory')->willReturn([]);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->classifier->method('classify')->willReturn([
            'topic' => 'translate',
            'language' => 'de',
            'source' => 'ai_sorting',
            'web_search' => true,
        ]);

        $this->promptService
            ->method('getPromptWithMetadata')
            ->willReturn([
                'metadata' => ['tool_internet' => false],
            ]);

        $this->braveSearchService->method('isEnabled')->willReturn(true);

        $this->braveSearchService
            ->expects($this->never())
            ->method('search');

        $this->router
            ->method('routeStream')
            ->willReturn(['metadata' => ['provider' => 'test', 'model' => 'test']]);

        $this->processor->processStream(
            $message,
            function (): void {},
            null,
            ['web_search' => true],
        );
    }

    public function testProcessLoadsConversationHistory(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(456);
        $message->method('getFile')->willReturn(0);

        $this->preProcessor->method('process')->willReturn($message);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->messageRepository
            ->expects($this->once())
            ->method('findConversationHistory')
            ->with(1, 456, 10)
            ->willReturn([]);

        $this->classifier->method('classify')->willReturn([
            'topic' => 'CHAT',
            'language' => 'en',
            'source' => 'ai_sorting',
        ]);

        $this->router->method('route')->willReturn([
            'content' => 'Response',
            'metadata' => ['provider' => 'test', 'model' => 'test'],
        ]);

        $this->processor->process($message);
    }
}
