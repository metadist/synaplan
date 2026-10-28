<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

use App\AI\Credential\HiggsfieldCredentialResolver;
use App\AI\Credential\ProviderKeyStore;
use App\AI\Credential\UserProviderKeyResolver;
use App\AI\Health\FailureClassifier;
use App\AI\Health\ModelHealthConfig;
use App\AI\Health\ModelHealthRecorder;
use App\AI\Import\CapabilityProbe;
use App\AI\Messages\AnthropicJsonSchemaNormalizer;
use App\AI\Messages\MessagesContextInjector;
use App\AI\Messages\MessagesGateway;
use App\AI\Messages\MessagesModelResolver;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\GatewayToolLoop;
use App\AI\Messages\Tools\WebFetchPolicy;
use App\AI\Messages\Translator\AnthropicPassthroughTranslator;
use App\AI\Messages\Translator\GeminiMessagesTranslator;
use App\AI\Messages\Translator\OpenAiMessagesTranslator;
use App\AI\Messages\Vision\VisionPolicy;
use App\AI\OpenAI\OpenAiGatewayToolLoop;
use App\AI\Pipeline\AiCallPipeline;
use App\AI\Pipeline\Hook\CircuitBreakerHook;
use App\AI\Pipeline\Hook\ModelHealthHook;
use App\AI\Pipeline\Hook\RateLimitHook;
use App\AI\Pipeline\Hook\RequestContextHook;
use App\AI\Pipeline\Hook\UsageHook;
use App\AI\Pipeline\UsageHookConfig;
use App\AI\Pipeline\UsageHookMode;
use App\AI\Provider\OpenAIProvider;
use App\AI\Provider\PerplexityProvider;
use App\AI\Service\AiFacade;
use App\AI\Service\ProviderModelInventory;
use App\AI\Service\ProviderRegistry;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\Controller\OpenAICompatibleController;
use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\Prompt;
use App\Entity\Subscription;
use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Plug\PlugConfigService;
use App\Plug\Rerank\Adapter\LlmReranker;
use App\Plug\Rerank\RerankMetrics;
use App\Plug\Rerank\RerankRegistry;
use App\Plug\Rerank\RerankStage;
use App\Plug\WebSearch\Adapter\PerplexityAdapter;
use App\Plug\WebSearch\Client\PerplexitySearchClient;
use App\Plug\WebSearch\WebSearchQuery;
use App\Repository\ConfigRepository;
use App\Repository\ModelPriceHistoryRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TopupRepository;
use App\Repository\UserRepository;
use App\Repository\WidgetRepository;
use App\Service\Api\OpenAiEmbeddingsRequest;
use App\Service\Api\OpenAiEmbeddingsService;
use App\Service\Api\OpenAiGatewayModels;
use App\Service\Api\OpenAiImageGenerationRequest;
use App\Service\Api\OpenAiImageGenerationService;
use App\Service\Api\OpenAiToolCallingGate;
use App\Service\Billing\BillingService;
use App\Service\Billing\PremiumFeatureGate;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\Chat\ProviderTranscriptCapture;
use App\Service\Chat\Stream\StreamAgentPinResolver;
use App\Service\Chat\Stream\StreamCaller;
use App\Service\Chat\Stream\StreamCallerResolver;
use App\Service\Chat\Stream\StreamRequestResolver;
use App\Service\Chat\Stream\StreamTurnState;
use App\Service\Chat\Stream\StreamUsageRecorder;
use App\Service\Document\ChatDocumentOutput;
use App\Service\Email\EmailChatService;
use App\Service\Email\EmailWebhookIdempotencyService;
use App\Service\Email\InternalEmailService;
use App\Service\Email\RawMimeEmailParser;
use App\Service\Email\Turn\EmailAnswerStore;
use App\Service\Email\Turn\InboundEmailIntake;
use App\Service\Email\Turn\StoredInboundEmail;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\TextChunker;
use App\Service\File\ThumbnailService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\File\VectorizationService;
use App\Service\Infrastructure\CircuitBreaker;
use App\Service\Infrastructure\DatabaseConnectionRelease;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Media\GeneratedFileMetadataNormalizer;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaCancellationStore;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Memory\MemoryExtractionService;
use App\Service\Memory\PendingMemoryExtraction;
use App\Service\Message\Chat\ChatModelChoice;
use App\Service\Message\Chat\ChatReplyGenerator;
use App\Service\Message\Chat\ChatRequestOptions;
use App\Service\Message\Chat\ChatTurn;
use App\Service\Message\Handler\Media\MediaDownloader;
use App\Service\Message\MediaPromptExtractor;
use App\Service\Message\MessageSorter;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\ModelConfigService;
use App\Service\Notification\DiscordNotificationService;
use App\Service\Prompt\PromptService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\RateLimitService;
use App\Service\Usage\AiChatUsageMeter;
use App\Service\Usage\AiPipelineMeter;
use App\Service\Usage\CostCalculationService;
use App\Service\Usage\LegacyUsageWriter;
use App\Service\Usage\TranscriptionUsageRecorder;
use App\Service\Usage\TurnUsageLedger;
use App\Service\Usage\UsageFailureLogger;
use App\Service\Usage\UsageStatsService;
use App\Service\Usage\UsageTaximeterConfig;
use App\Service\Vision\VisionModelResolver;
use App\Service\Widget\Turn\StoredVisitorMessage;
use App\Service\Widget\Turn\WidgetAnswerStore;
use App\Service\Widget\Turn\WidgetTurnGate;
use App\Service\Widget\WidgetDomainGuard;
use App\Service\Widget\WidgetRealtimeBroadcaster;
use App\Service\Widget\WidgetService;
use App\Service\Widget\WidgetSessionResolver;
use App\Service\Widget\WidgetSessionService;
use App\Tests\Support\MediaGenerationHandlerFactory;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use GuzzleHttp\Psr7\Response;
use OpenAI\Responses\Audio\TranscriptionResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingResponse;
use OpenAI\Responses\Images\CreateResponse as ImageResponse;
use OpenAI\Testing\Enums\OverrideStrategy;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Isolated, offline execution harness for {@see \App\Tests\Characterization\AiPathCharacterizationTest}.
 *
 * Every case builds the real AiFacade, RateLimitService, CostCalculationService,
 * CircuitBreaker, ModelHealthRecorder, AiChatUsageMeter, TranscriptionUsageRecorder,
 * OpenAIProvider, PerplexityProvider and MessagesGateway translators on top of
 * {@see ProviderHttpRecorder} and {@see OpenAISdkRecorder}, and records the
 * six contract surfaces of AU9:
 *  - provider_requests (sdk + http, secrets masked)
 *  - buselog (every INSERT INTO BUSELOG row with action, provider, model, tokens, rounded cost, metadata)
 *  - rate_limit_checks (checkLimit + checkCostBudget calls)
 *  - circuit_breaker (service + outcome)
 *  - model_health (capability, provider, model, model_id, outcome)
 *  - result (normalized return value)
 */
final class AiPathHarness
{
    public const SECRET = 'test-provider-key-secret';
    private const USER_ID = 7;
    private const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';

    /**
     * Catalog models used across the 19 cases:
     * id => [service, providerId, name, tag, priceIn, inUnit, priceOut, outUnit, json].
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: float, 5: string, 6: float, 7: string, 8: array<string, mixed>}>
     */
    private const MODELS = [
        12 => ['OpenAI', 'gpt-4o', 'GPT-4o', 'chat', 2.50, 'per1M', 10.00, 'per1M', ['features' => ['reasoning', 'vision']]],
        14 => ['Anthropic', 'claude-sonnet-4-6', 'Claude Sonnet 4.6', 'chat', 3.00, 'per1M', 15.00, 'per1M', ['features' => ['reasoning', 'vision']]],
        15 => ['Google', 'gemini-2.5-flash', 'Gemini 2.5 Flash', 'chat', 0.30, 'per1M', 2.50, 'per1M', ['features' => ['vision']]],
        21 => ['OpenAI', 'text-embedding-3-small', 'Text Embedding 3 Small', 'vectorize', 0.02, 'per1M', 0.00, 'per1M', ['vector_dim' => 1024]],
        41 => ['OpenAI', 'dall-e-3', 'DALL-E 3', 'text2pic', 0.00, 'per1M', 0.04, 'perpic', ['pricing_mode' => 'per_image']],
        45 => ['OpenAI', 'tts-1', 'TTS 1', 'text2sound', 15.00, 'per1M', 0.00, 'per1M', ['pricing_mode' => 'per_character']],
        51 => ['OpenAI', 'whisper-1', 'Whisper 1', 'sound2text', 0.006, 'persec', 0.00, 'persec', ['pricing_mode' => 'per_second']],
    ];

    /** @var list<array<string, mixed>> */
    private array $buselog = [];

    /** @var list<array<string, mixed>> */
    private array $rateLimitChecks = [];

    /** @var list<array<string, mixed>> */
    private array $circuitBreaker = [];

    /** @var list<array<string, mixed>> */
    private array $modelHealth = [];

    /** @var array<int, array{capability: string, provider: string, model: string}> */
    private array $healthResolutionById = [];

    /** @var list<array<string, mixed>> */
    private array $mismatches = [];

    private ?AiPipelineMeter $pipelineMeter = null;

    public function __construct(
        private readonly TestCase $test,
        private readonly string $uploadDir,
        private readonly UsageHookMode $usageHookMode = UsageHookMode::Legacy,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function mismatches(): array
    {
        return $this->mismatches;
    }

    public function pipelineMeter(): ?AiPipelineMeter
    {
        return $this->pipelineMeter;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function runAll(): array
    {
        $cases = [
            'in_app_stream' => $this->inAppStream(...),
            'buffered_chat_email' => $this->bufferedChatEmail(...),
            'widget_stream' => $this->widgetStream(...),
            'openai_chat_buffered' => $this->openAiChatBuffered(...),
            'openai_chat_stream' => $this->openAiChatStream(...),
            'openai_embeddings' => $this->openAiEmbeddings(...),
            'openai_images_generations' => $this->openAiImagesGenerations(...),
            'messages_passthrough_anthropic' => $this->messagesPassthroughAnthropic(...),
            'messages_translated_openai' => $this->messagesTranslatedOpenAi(...),
            'messages_translated_gemini' => $this->messagesTranslatedGemini(...),
            'sorter' => $this->sorter(...),
            'memory_extraction' => $this->memoryExtraction(...),
            'vectorize' => $this->vectorize(...),
            'llm_rerank' => $this->llmRerank(...),
            'perplexity_web_answer' => $this->perplexityWebAnswer(...),
            'media_image' => $this->mediaImage(...),
            'transcription' => $this->transcription(...),
            'synthesis' => $this->synthesis(...),
            'model_probe' => $this->modelProbe(...),
        ];

        $this->mismatches = [];
        $out = [];
        foreach ($cases as $name => $runner) {
            $this->resetLogs();
            $out[$name] = $runner();
        }

        return $out;
    }

    private function resetLogs(): void
    {
        $this->buselog = [];
        $this->rateLimitChecks = [];
        $this->circuitBreaker = [];
        $this->modelHealth = [];
        $this->healthResolutionById = [];
        LegacyUsageWriter::bind(null);
    }

    /**
     * @return array<string, mixed>
     */
    private function inAppStream(): array
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::model('gpt-4o'),
            self::openAiStreamSse('Hello from Synaplan.'),
        ], [self::SECRET]);
        $http = new ProviderHttpRecorder([], [self::SECRET]);
        [$facade, $limits, $em, $user] = $this->buildSpine($sdk, $http);

        $callerResolver = $this->createStub(StreamCallerResolver::class);
        $callerResolver->method('resolve')->willReturn(new StreamCaller($user, 'en'));
        $agentResolver = $this->createStub(StreamAgentPinResolver::class);
        $agentResolver->method('resolve')->willReturn(null);
        $budgetGate = $this->createStub(PremiumFeatureGate::class);
        $budgetGate->method('isCostBudgetEnforced')->willReturn(true);

        $requestResolver = new StreamRequestResolver(
            $callerResolver,
            $agentResolver,
            $em,
            $this->createStub(PromptService::class),
            $limits,
            $budgetGate,
            new NullLogger(),
        );
        $streamTurn = $requestResolver->resolve(
            Request::create(
                '/api/v1/messages/stream',
                'POST',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['message' => 'What is Synaplan?', 'chatId' => '5', 'trackId' => 42], \JSON_THROW_ON_ERROR),
            ),
            $user,
        );

        $generator = $this->chatReplyGenerator($facade, $em);
        $turn = $this->chatTurn('What is Synaplan?', ['source' => 'WEB']);
        $choice = new ChatModelChoice(12, 'openai', 'gpt-4o', self::USER_ID, false, false, false, $this->modelEntity(12));
        $aiOptions = $this->chatRequestOptions($limits)->forStreamedTurn($turn, $choice, ['model_id' => 12], null);
        $chunks = [];
        $reply = $generator->stream(
            $turn,
            $choice,
            [['role' => 'user', 'content' => 'What is Synaplan?']],
            $aiOptions,
            static function (mixed $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
            false,
            null,
        );

        $state = new StreamTurnState($streamTurn, new PerfTimer(), ['id' => 12, 'name' => 'gpt-4o', 'provider' => 'openai']);
        $state->incoming = $turn->message;
        $outgoing = $this->message(102, 'Hello from Synaplan.', 'OUT');

        $recorder = new StreamUsageRecorder(
            $limits,
            $this->createStub(UsageStatsService::class),
            $this->createStub(UsageTaximeterConfig::class),
            $em,
        );
        $streamUsage = $recorder->recordStreamedReply(
            $streamTurn,
            $state,
            $outgoing,
            $reply->metadata + ['model_id' => 12],
            ['topic' => 'general', 'language' => 'en'],
            (string) $reply->content,
            null,
        );

        return $this->pack($sdk, $http, [
            'chunks' => $chunks,
            'content' => $reply->content,
            'finish_reason' => $reply->finishReason,
            'chat_usage' => $streamUsage->chat,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function bufferedChatEmail(): array
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::model('gpt-4o'),
            OpenAIFixtures::response([OpenAIFixtures::message('We open at 9 on Saturday.')]),
        ], [self::SECRET]);
        $http = new ProviderHttpRecorder([], [self::SECRET]);
        [$facade, $limits, $em, $user] = $this->buildSpine($sdk, $http);

        $rawMime = $this->createStub(RawMimeEmailParser::class);
        $rawMime->method('looksLikeRawMime')->willReturn(false);
        $idempotency = $this->createStub(EmailWebhookIdempotencyService::class);
        $idempotency->method('findDuplicate')->willReturn([
            'existing' => null,
            'fingerprint' => 'fp-1',
            'normalized_message_id' => '<m1@example.com>',
        ]);
        $emailChats = $this->createStub(EmailChatService::class);
        $emailChats->method('parseEmailKeyword')->willReturn(null);
        $emailChats->method('findOrCreateUserFromEmail')->willReturn([
            'user' => $user,
            'is_anonymous' => false,
        ]);

        $intake = new InboundEmailIntake(
            $rawMime,
            $idempotency,
            $emailChats,
            $limits,
            $this->createStub(InternalEmailService::class),
            $this->createStub(DiscordNotificationService::class),
            new NullLogger(),
        );
        $email = $intake->accept(Request::create(
            '/api/v1/webhooks/email',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'from' => 'sender@example.com',
                'to' => 'smart@synaplan.net',
                'subject' => 'Opening hours',
                'body' => 'When do you open on Saturday?',
                'message_id' => '<m1@example.com>',
            ], \JSON_THROW_ON_ERROR),
        ));

        $generator = $this->chatReplyGenerator($facade, $em);
        $turn = $this->chatTurn('When do you open on Saturday?', ['channel' => 'EMAIL']);
        $choice = new ChatModelChoice(12, 'openai', 'gpt-4o', self::USER_ID, false, false, false, $this->modelEntity(12));
        $aiOptions = $this->chatRequestOptions($limits)->forBufferedTurn($turn, $choice, ['model_id' => 12], null);
        $reply = $generator->complete(
            $turn,
            $choice,
            [['role' => 'user', 'content' => 'When do you open on Saturday?']],
            $aiOptions,
            [],
            null,
        );

        $store = new EmailAnswerStore(
            $limits,
            new GeneratedFileMetadataNormalizer(),
            $this->createStub(ConversationSummaryRefreshDispatcher::class),
            $em,
        );
        $chat = $this->createStub(Chat::class);
        $chat->method('getId')->willReturn(5);
        $stored = new StoredInboundEmail($chat, $turn->message);
        $recorded = $store->recordUsage($email, $stored, (string) $reply->content, $reply->metadata + ['model_id' => 12], 0.25);

        return $this->pack($sdk, $http, [
            'content' => $reply->content,
            'metadata' => $reply->metadata,
            'recorded_tokens' => $recorded->totalTokens,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function widgetStream(): array
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::model('gpt-4o'),
            self::openAiStreamSse('Welcome to support.'),
        ], [self::SECRET]);
        $http = new ProviderHttpRecorder([], [self::SECRET]);
        [$facade, $limits, $em, $user] = $this->buildSpine($sdk, $http);

        $widget = new Widget();
        $widget->setOwnerId(self::USER_ID);
        $widget->setOwner($user);
        $widget->setWidgetId('wdg_char_1');
        $widget->setTaskPromptTopic('tools:widget-default');
        $widget->setName('Support');
        $widget->setStatus('active');
        $widget->setConfig(['aiModelId' => 12]);

        $session = new WidgetSession();
        $session->setWidgetId('wdg_char_1');
        $session->setSessionId('sess_1');

        $widgetRepo = $this->createStub(WidgetRepository::class);
        $widgetRepo->method('findOneByWidgetId')->willReturn($widget);
        $widgetService = new WidgetService($em, $widgetRepo, $this->createStub(PromptRepository::class), $limits, new NullLogger());
        $sessionResolver = $this->createStub(WidgetSessionResolver::class);
        $sessionResolver->method('resolve')->willReturn($session);
        $sessions = $this->createStub(WidgetSessionService::class);
        $sessions->method('getOrCreateSession')->willReturn($session);
        $sessions->method('checkSessionLimit')->willReturn(['allowed' => true, 'remaining' => 10, 'retry_after' => 0, 'reason' => null]);

        $turnGate = new WidgetTurnGate($widgetService, $this->createStub(WidgetDomainGuard::class), $sessionResolver, $sessions, $em);
        $widgetTurn = $turnGate->admit('wdg_char_1', Request::create(
            '/api/v1/widget/wdg_char_1/message',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['sessionId' => 'sess_1', 'text' => 'Need widget help', 'chatId' => 5], \JSON_THROW_ON_ERROR),
        ));

        $generator = $this->chatReplyGenerator($facade, $em);
        $turn = $this->chatTurn('Need widget help', ['channel' => 'WIDGET', 'is_widget_mode' => true]);
        $choice = new ChatModelChoice(12, 'openai', 'gpt-4o', self::USER_ID, false, false, false, $this->modelEntity(12));
        $aiOptions = $this->chatRequestOptions($limits)->forStreamedTurn($turn, $choice, ['model_id' => 12], null);
        $chunks = [];
        $reply = $generator->stream(
            $turn,
            $choice,
            [['role' => 'user', 'content' => 'Need widget help']],
            $aiOptions,
            static function (mixed $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
            false,
            null,
        );

        $chat = $this->createStub(Chat::class);
        $chat->method('getId')->willReturn(5);
        $stored = new StoredVisitorMessage($chat, $turn->message, []);

        $answerStore = new WidgetAnswerStore(
            $sessions,
            $this->createStub(WidgetRealtimeBroadcaster::class),
            new GeneratedFileMetadataNormalizer(),
            $this->createStub(GeneratedFileRegistrar::class),
            $this->createStub(ConversationSummaryRefreshDispatcher::class),
            $limits,
            $em,
        );
        $answerStore->store($widgetTurn, $stored, (string) $reply->content, [
            'classification' => ['topic' => 'tools:widget-default', 'language' => 'en'],
            'response' => ['metadata' => $reply->metadata + ['model_id' => 12]],
        ]);

        return $this->pack($sdk, $http, [
            'chunks' => $chunks,
            'content' => $reply->content,
            'preview' => $session->getLastMessagePreview(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function openAiChatBuffered(): array
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::model('gpt-4o'),
            OpenAIFixtures::response([OpenAIFixtures::message('Buffered gateway reply.')]),
        ], [self::SECRET]);
        $http = new ProviderHttpRecorder([], [self::SECRET]);
        [$facade, $limits, , $user, $modelRepo, $modelConfig] = $this->buildSpine($sdk, $http);

        $controller = $this->openAiCompatibleController($facade, $modelRepo, $modelConfig, $limits);
        $request = Request::create(
            '/v1/chat/completions',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'model' => 'gpt-4o',
                'messages' => [['role' => 'user', 'content' => 'Hello gateway']],
                'stream' => false,
            ], \JSON_THROW_ON_ERROR),
        );

        $response = $controller->chatCompletions($request, $user);
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $decoded['id'] = '<chatcmpl-id>';
        $decoded['created'] = 1700000000;

        return $this->pack($sdk, $http, [
            'status' => $response->getStatusCode(),
            'body' => $decoded,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function openAiChatStream(): array
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::model('gpt-4o'),
            self::openAiStreamSse('Streamed gateway reply.'),
        ], [self::SECRET]);
        $http = new ProviderHttpRecorder([], [self::SECRET]);
        [$facade, $limits, , $user, $modelRepo, $modelConfig] = $this->buildSpine($sdk, $http);

        $controller = $this->openAiCompatibleController($facade, $modelRepo, $modelConfig, $limits);
        $request = Request::create(
            '/v1/chat/completions',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'model' => 'gpt-4o',
                'messages' => [['role' => 'user', 'content' => 'Stream gateway']],
                'stream' => true,
                'stream_options' => ['include_usage' => true],
            ], \JSON_THROW_ON_ERROR),
        );

        $response = $controller->chatCompletions($request, $user);
        $this->test->assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        try {
            $response->sendContent();
        } finally {
            $raw = (string) ob_get_clean();
        }

        $events = [];
        foreach (preg_split("/\n\n+/", trim($raw)) ?: [] as $frame) {
            if ('data: [DONE]' === trim($frame)) {
                $events[] = '[DONE]';
                continue;
            }
            if (str_starts_with($frame, 'data: ')) {
                $chunk = json_decode(substr($frame, 6), true, 512, \JSON_THROW_ON_ERROR);
                $chunk['id'] = '<chatcmpl-id>';
                $chunk['created'] = 1700000000;
                $events[] = $chunk;
            }
        }

        return $this->pack($sdk, $http, [
            'status' => $response->getStatusCode(),
            'events' => $events,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function openAiEmbeddings(): array
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::json(EmbeddingResponse::fake([
                'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.25, -0.5, 0.75]]],
                'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
            ], null, OverrideStrategy::Replace)->toArray()),
        ], [self::SECRET]);
        $http = new ProviderHttpRecorder([], [self::SECRET]);
        [$facade, $limits, , $user, $modelRepo, $modelConfig] = $this->buildSpine($sdk, $http);

        $service = new OpenAiEmbeddingsService(
            $facade,
            new OpenAiGatewayModels($modelRepo, $modelConfig),
            $limits,
            new NullLogger(),
        );
        $result = $service->create(
            $user,
            OpenAiEmbeddingsRequest::fromBody(['model' => 'text-embedding-3-small', 'input' => 'Embed this sentence']),
            false,
        );

        return $this->pack($sdk, $http, $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function openAiImagesGenerations(): array
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::json(ImageResponse::fake([
                'data' => [['url' => 'https://example.test/generated.png', 'revised_prompt' => 'A red bicycle', 'b64_json' => null]],
            ], null, OverrideStrategy::Replace)->toArray()),
        ], [self::SECRET]);
        $http = new ProviderHttpRecorder([], [self::SECRET]);
        [$facade, $limits, , $user, $modelRepo, $modelConfig] = $this->buildSpine($sdk, $http);

        $service = new OpenAiImageGenerationService(
            $facade,
            new OpenAiGatewayModels($modelRepo, $modelConfig),
            $limits,
            $http->client(),
            new NullLogger(),
        );
        $result = $service->create(
            $user,
            OpenAiImageGenerationRequest::fromBody(['model' => 'dall-e-3', 'prompt' => 'A red bicycle', 'size' => '1024x1024']),
            false,
        );
        $result['created'] = 1700000000;

        return $this->pack($sdk, $http, $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function messagesPassthroughAnthropic(): array
    {
        $sdk = new OpenAISdkRecorder([], [self::SECRET]);
        $http = new ProviderHttpRecorder([
            new MockResponse((string) json_encode([
                'id' => 'msg_01',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'Hello from Anthropic.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 8],
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]),
        ], [self::SECRET]);

        return $this->runMessagesGatewayCase($sdk, $http, 'claude-sonnet-4-6');
    }

    /**
     * @return array<string, mixed>
     */
    private function messagesTranslatedOpenAi(): array
    {
        $sdk = new OpenAISdkRecorder([], [self::SECRET]);
        $http = new ProviderHttpRecorder([
            new MockResponse((string) json_encode([
                'id' => 'chatcmpl_01',
                'object' => 'chat.completion',
                'model' => 'gpt-4o',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Hello from OpenAI translator.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 6, 'total_tokens' => 15],
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]),
        ], [self::SECRET]);

        return $this->runMessagesGatewayCase($sdk, $http, 'gpt-4o');
    }

    /**
     * @return array<string, mixed>
     */
    private function messagesTranslatedGemini(): array
    {
        $sdk = new OpenAISdkRecorder([], [self::SECRET]);
        $http = new ProviderHttpRecorder([
            new MockResponse((string) json_encode([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'Hello from Gemini translator.']]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 11, 'candidatesTokenCount' => 7, 'totalTokenCount' => 18],
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]),
        ], [self::SECRET]);

        return $this->runMessagesGatewayCase($sdk, $http, 'gemini-2.5-flash');
    }

    /**
     * @return array<string, mixed>
     */
    private function sorter(): array
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::model('gpt-4o'),
            OpenAIFixtures::response([OpenAIFixtures::message('{"BTOPIC":"general","BLANG":"en","BWEBSEARCH":false,"BMULTI":false}')]),
        ], [self::SECRET]);
        $http = new ProviderHttpRecorder([], [self::SECRET]);
        [$facade, $limits, $em, , , $modelConfig] = $this->buildSpine($sdk, $http);

        $sortingPrompt = new Prompt();
        $sortingPrompt->setTopic('tools:sort');
        $sortingPrompt->setPrompt('Classify into [KEYLIST] with languages [LANGLIST]: [DYNAMICLIST]');

        $prompts = $this->createStub(PromptRepository::class);
        $prompts->method('findByTopic')->willReturn($sortingPrompt);
        $prompts->method('getAllTopics')->willReturn(['general', 'mediamaker']);
        $prompts->method('getTopicsWithDescriptions')->willReturn([
            ['topic' => 'general', 'description' => 'General conversation'],
            ['topic' => 'mediamaker', 'description' => 'Image, video or audio generation'],
        ]);

        $promptService = $this->createStub(PromptService::class);
        $promptService->method('getPromptWithMetadata')->willReturn(['prompt' => null, 'metadata' => ['aiModel' => 0]]);

        $structuredOutput = $this->createStub(StructuredOutputConfig::class);
        $structuredOutput->method('isEnabled')->willReturn(true);

        $sorter = new MessageSorter(
            $facade,
            $prompts,
            $modelConfig,
            $promptService,
            $limits,
            $em,
            new NullLogger(),
            $this->createStub(DiscordNotificationService::class),
            $structuredOutput,
        );

        $classified = $sorter->classify(['BTEXT' => 'Hello, how are you?', 'BLANG' => 'en'], [], self::USER_ID);

        return $this->pack($sdk, $http, [
            'topic' => $classified['topic'],
            'language' => $classified['language'],
            'sorting_provider' => $classified['sorting_provider'],
            'sorting_model_name' => $classified['sorting_model_name'],
            'sorting_usage' => $classified['sorting_usage'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function memoryExtraction(): array
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::model('gpt-4o'),
            OpenAIFixtures::response([OpenAIFixtures::message('{"memories":[{"action":"create","category":"preferences","key":"editor_theme","value":"dark mode"}]}')]),
        ], [self::SECRET]);
        $http = new ProviderHttpRecorder([], [self::SECRET]);
        [$facade, $limits, $em, , , $modelConfig] = $this->buildSpine($sdk, $http);

        $prompts = $this->createStub(PromptRepository::class);
        $prompts->method('findOneBy')->willReturn(null);

        $structuredOutput = $this->createStub(StructuredOutputConfig::class);
        $structuredOutput->method('isEnabled')->willReturn(true);

        $service = new MemoryExtractionService(
            $facade,
            $modelConfig,
            $limits,
            $prompts,
            $em,
            new NullLogger(),
            $structuredOutput,
        );

        $extracted = $service->analyzeAndExtract($this->message(101, 'I prefer dark mode in my editor.'), [], []);

        return $this->pack($sdk, $http, $extracted);
    }

    /**
     * @return array<string, mixed>
     */
    private function vectorize(): array
    {
        $vector = array_fill(0, 1024, 0.125);
        $sdk = new OpenAISdkRecorder([
            // First: embedBatch fails, triggering VectorizationService::embedWindowResilient()'s
            // per-chunk AiFacade::embed() fallback so both embedBatch() and embed() are exercised.
            OpenAIFixtures::error(500, 'Batch endpoint busy', 'server_error'),
            // Second: the per-chunk AiFacade::embed() call succeeds.
            OpenAIFixtures::json(EmbeddingResponse::fake([
                'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => $vector]],
                'usage' => ['prompt_tokens' => 8, 'total_tokens' => 8],
            ], null, OverrideStrategy::Replace)->toArray()),
        ], [self::SECRET]);
        $http = new ProviderHttpRecorder([], [self::SECRET]);
        [$facade, $limits, $em, , , $modelConfig] = $this->buildSpine($sdk, $http);

        $storage = $this->createStub(VectorStorageFacade::class);
        $storage->method('getProviderName')->willReturn('qdrant');

        $service = new VectorizationService(
            $facade,
            new TextChunker(),
            $modelConfig,
            $storage,
            $limits,
            $em,
            new NullLogger(),
        );

        $result = $service->vectorizeAndStore('Synaplan routes every AI call through one pipeline.', self::USER_ID, 101);

        return $this->pack($sdk, $http, $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function llmRerank(): array
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::model('gpt-4o'),
            OpenAIFixtures::response([OpenAIFixtures::message('["c2","c1"]')]),
        ], [self::SECRET]);
        $http = new ProviderHttpRecorder([], [self::SECRET]);
        [$facade, $limits, , , , $modelConfig] = $this->buildSpine($sdk, $http);

        $prompts = $this->createStub(PromptRepository::class);
        $prompts->method('findByTopic')->willReturn(null);

        $plugConfig = $this->createStub(PlugConfigService::class);
        $plugConfig->method('isRerankLlmFallback')->willReturn(true);
        $plugConfig->method('rerankCandidatesMultiplier')->willReturn(3);
        $plugConfig->method('rerankLatencyBudgetMs')->willReturn(5000);
        $plugConfig->method('rerankMaxCandidateChars')->willReturn(400);

        $reranker = new LlmReranker($facade, $modelConfig, $prompts, $plugConfig);
        $registry = $this->createStub(RerankRegistry::class);
        $registry->method('active')->willReturn($reranker);

        $stage = new RerankStage(
            $registry,
            $plugConfig,
            $this->createStub(RerankMetrics::class),
            $limits,
        );

        $reordered = $stage->apply('AI call pipeline', [
            ['chunk_id' => 'c1', 'chunk_text' => 'Unrelated note', 'score' => 0.9],
            ['chunk_id' => 'c2', 'chunk_text' => 'The AI call pipeline runs ordered hooks', 'score' => 0.8],
        ], 2, null);
        foreach ($reordered as &$hit) {
            if (isset($hit['rerank']['ms'])) {
                $hit['rerank']['ms'] = 1;
            }
        }
        unset($hit);

        return $this->pack($sdk, $http, $reordered);
    }

    /**
     * @return array<string, mixed>
     */
    private function perplexityWebAnswer(): array
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::json([
                'id' => 'cmpl-pplx-1',
                'object' => 'chat.completion',
                'created' => 1700000000,
                'model' => 'sonar',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Synaplan uses one AI call pipeline.', 'tool_calls' => []],
                    'logprobs' => null,
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 14, 'completion_tokens' => 9, 'total_tokens' => 23],
            ]),
        ], [self::SECRET], 'https://api.perplexity.ai');
        $http = new ProviderHttpRecorder([
            new MockResponse((string) json_encode([
                'results' => [[
                    'title' => 'Synaplan Architecture',
                    'url' => 'https://docs.synaplan.com/architecture',
                    'snippet' => 'One pipeline for all AI calls.',
                    'date' => '2026-10-21',
                ]],
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]),
        ], [self::SECRET]);

        $keyStore = $this->createStub(ProviderKeyStore::class);
        $keyStore->method('getKey')->willReturn(self::SECRET);

        $plugConfig = $this->createStub(PlugConfigService::class);
        $plugConfig->method('webSearchTimeoutMs')->willReturn(10000);

        $sonarBid = (string) \App\Model\ModelCatalog::findBidByKey('perplexity:sonar:chat');
        $configRepo = $this->createStub(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(static fn (int $owner, string $group, string $setting): ?string => 'DEFAULTMODEL' === $group && 'WEBANSWER' === $setting ? $sonarBid : null);

        [$facade] = $this->buildSpine($sdk, $http);
        $adapter = new PerplexityAdapter(
            new PerplexitySearchClient($http->client(), $keyStore, $plugConfig),
            $configRepo,
            new NullLogger(),
            $facade,
        );

        $resultSet = $adapter->search(new WebSearchQuery('Synaplan architecture', wantAnswer: true));

        return $this->pack($sdk, $http, [
            'query' => $resultSet->query,
            'count' => \count($resultSet->results),
            'first_url' => $resultSet->results[0]['url'] ?? null,
            'answer' => null !== $resultSet->answer ? [
                'text' => $resultSet->answer->text,
                'citations' => \count($resultSet->answer->citations),
            ] : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaImage(): array
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::json(ImageResponse::fake([
                'data' => [['url' => 'data:image/png;base64,'.self::PIXEL, 'revised_prompt' => 'A lighthouse at dusk', 'b64_json' => null]],
            ], null, OverrideStrategy::Replace)->toArray()),
        ], [self::SECRET]);
        $http = new ProviderHttpRecorder([], [self::SECRET]);
        [$facade, $limits, $em, , , $modelConfig] = $this->buildSpine($sdk, $http);

        $handler = $this->mediaHandler($facade, $modelConfig, $em, $limits, [
            'prompt' => 'A lighthouse at dusk',
            'media_type' => 'image',
        ]);
        $result = $handler->handle(
            $this->message(101, 'Draw a lighthouse at dusk'),
            [],
            ['topic' => 'mediamaker', 'language' => 'en', 'media_type' => 'image'],
            null,
            ['record_media_usage' => true],
        );

        return $this->pack($sdk, $http, [
            'content' => $result['content'],
            'provider' => $result['metadata']['provider'] ?? null,
            'model' => $result['metadata']['model'] ?? null,
            'media_type' => $result['metadata']['media_type'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transcription(): array
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::json(TranscriptionResponse::fake([
                'task' => 'transcribe',
                'language' => 'de',
                'duration' => 3.5,
                'text' => 'Hallo Synaplan',
                'segments' => [],
            ], null, OverrideStrategy::Replace)->toArray()),
        ], [self::SECRET]);
        $http = new ProviderHttpRecorder([], [self::SECRET]);
        [$facade] = $this->buildSpine($sdk, $http);

        $result = $facade->transcribe('voice.mp3', self::USER_ID, [
            'provider' => 'openai',
            'model' => 'whisper-1',
            'model_id' => 51,
            'language' => 'de',
        ]);

        return $this->pack($sdk, $http, [
            'text' => $result['text'],
            'language' => $result['language'],
            'duration' => $result['duration'],
            'provider' => $result['provider'],
            'model' => $result['model'],
            'model_id' => $result['model_id'],
            'transcription_usage' => $result['transcription_usage'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function synthesis(): array
    {
        $sdk = new OpenAISdkRecorder([
            new Response(200, ['Content-Type' => 'audio/mpeg'], 'ID3-synthesized-audio'),
        ], [self::SECRET]);
        $http = new ProviderHttpRecorder([], [self::SECRET]);
        [$facade, $limits, $em, , , $modelConfig] = $this->buildSpine($sdk, $http);

        $handler = $this->mediaHandler($facade, $modelConfig, $em, $limits, [
            'prompt' => 'Welcome aboard the harbour tour',
            'media_type' => 'audio',
        ]);
        $result = $handler->handle(
            $this->message(101, '/tts Welcome aboard the harbour tour'),
            [],
            ['topic' => 'tools:tts', 'language' => 'en'],
            null,
            ['record_media_usage' => true],
        );

        return $this->pack($sdk, $http, [
            'content' => $result['content'],
            'provider' => $result['metadata']['provider'] ?? null,
            'model' => $result['metadata']['model'] ?? null,
            'media_type' => $result['metadata']['media_type'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function modelProbe(): array
    {
        $sdk = new OpenAISdkRecorder([], [self::SECRET]);
        $http = new ProviderHttpRecorder([
            new MockResponse('{"id":"cmpl-probe"}', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]),
            new MockResponse('{"data":[{"embedding":[0.1]}]}', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]),
            new MockResponse((string) json_encode([
                'data' => [
                    ['id' => 'gpt-4o', 'created' => 1700000000],
                ],
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]),
            new MockResponse('{"error":{"message":"Model not found"}}', ['http_code' => 404, 'response_headers' => ['content-type' => 'application/json']]),
        ], [self::SECRET]);

        [$facade] = $this->buildSpine($sdk, $http);
        $capabilityProbe = new CapabilityProbe($http->client(), $facade->pipeline());
        $endpointProbe = $capabilityProbe->probe([
            'base_url' => 'https://endpoint.example.test/v1',
            'api_key' => self::SECRET,
            'headers' => [],
        ], 'custom-chat-1');

        $keyStore = $this->createStub(ProviderKeyStore::class);
        $keyStore->method('getKey')->willReturnCallback(static fn (string $provider): ?string => 'openai' === $provider ? self::SECRET : null);

        $inventory = new ProviderModelInventory($http->client(), $keyStore, new NullLogger(), $facade->pipeline());
        $availability = $inventory->fetch('openai');
        $singleProbe = $inventory->probe('openai', 'gpt-4o-retired-probe');

        return $this->pack($sdk, $http, [
            'capability_probe' => [
                'chat' => $endpointProbe->chat,
                'embeddings' => $endpointProbe->embeddings,
            ],
            'availability_status' => $availability->status,
            'served_count' => \count($availability->modelIds),
            'single_model_probe' => $singleProbe->name,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function runMessagesGatewayCase(OpenAISdkRecorder $sdk, ProviderHttpRecorder $http, string $requestedModel): array
    {
        [$facade, $limits, , $user, $modelRepo] = $this->buildSpine($sdk, $http);

        $config = $this->createStub(MessagesGatewayConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('allowOperatorKey')->willReturn(true);
        $config->method('upstreamUrl')->willReturn('https://api.anthropic.com');
        $config->method('modelAliases')->willReturn([]);
        $config->method('visionMode')->willReturn(MessagesGatewayConfig::VISION_PASSTHROUGH);
        $config->method('webFetchMode')->willReturn('off');
        $config->method('webFetchMaxPerTurn')->willReturn(0);
        $config->method('isContextInjectionEnabled')->willReturn(false);
        $config->method('isBudgetNoticeEnabled')->willReturn(false);

        $keyResolver = $this->createStub(UserProviderKeyResolver::class);
        $keyResolver->method('resolve')->willReturn(['key' => self::SECRET, 'source' => 'operator']);

        $gate = $this->createStub(PremiumFeatureGate::class);
        $gate->method('isUnlockedFor')->willReturn(true);

        $toolCatalog = $this->createStub(GatewayToolCatalog::class);
        $toolCatalog->method('build')->willReturn(['tools' => [], 'dispatch' => [], 'web_search' => 'off']);
        $toolCatalog->method('replacedServerTools')->willReturn([]);

        $visionPolicy = $this->createStub(VisionPolicy::class);
        $visionPolicy->method('apply')->willReturnCallback(static fn (array $body): array => [
            'body' => $body,
            'mutated' => false,
            'mode' => 'passthrough',
            'detail' => 'auto',
            'images_forwarded' => 0,
            'images_omitted' => 0,
        ]);

        $webFetchPolicy = $this->createStub(WebFetchPolicy::class);
        $webFetchPolicy->method('apply')->willReturnCallback(static fn (array $body): array => [
            'body' => $body,
            'mutated' => false,
            'handling' => 'off',
            'anthropic_beta' => null,
        ]);

        $passthrough = new AnthropicPassthroughTranslator($http->client(), new NullLogger(), new AnthropicJsonSchemaNormalizer());
        $openAiTranslator = new OpenAiMessagesTranslator($http->client());
        $geminiTranslator = new GeminiMessagesTranslator($http->client());

        $resolver = $this->createStub(MessagesModelResolver::class);
        $resolver->method('resolve')->willReturnCallback(static function (?string $modelString) use ($modelRepo): ?array {
            $model = null !== $modelString ? $modelRepo->findOneBy(['providerId' => $modelString]) : null;
            if (!$model instanceof Model) {
                return null;
            }

            return [
                'provider' => strtolower($model->getService()),
                'providerModelId' => $model->getProviderId(),
                'displayModel' => $model->getProviderId(),
                'model_id' => (int) $model->getId(),
                'requested' => (string) $modelString,
                'aliased_from' => null,
            ];
        });

        $gateway = new MessagesGateway(
            $config,
            $resolver,
            $modelRepo,
            $this->createStub(VisionModelResolver::class),
            $keyResolver,
            $limits,
            $gate,
            $passthrough,
            $toolCatalog,
            $this->createStub(GatewayToolLoop::class),
            $webFetchPolicy,
            $visionPolicy,
            $this->createStub(MessagesContextInjector::class),
            new ArrayAdapter(),
            $this->createStub(MessageBusInterface::class),
            new NullLogger(),
            [$passthrough, $openAiTranslator, $geminiTranslator],
        );
        $gateway->setPipeline($facade->pipeline());

        $request = Request::create(
            '/v1/messages',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'model' => $requestedModel,
                'max_tokens' => 256,
                'messages' => [['role' => 'user', 'content' => 'Hello Messages gateway']],
            ], \JSON_THROW_ON_ERROR),
        );

        $prepared = $gateway->prepare($request, $user);
        $this->test->assertTrue($prepared['ok']);
        $completed = $gateway->executeComplete($prepared, $user);

        return $this->pack($sdk, $http, [
            'status' => $completed['status'],
            'body' => $completed['body'] ?? null,
            'usage' => isset($completed['usage']) ? $completed['usage']->toRateLimitUsage() : null,
        ]);
    }

    /**
     * @return array{0: AiFacade, 1: RateLimitService, 2: EntityManagerInterface, 3: User, 4: ModelRepository, 5: ModelConfigService}
     */
    private function buildSpine(OpenAISdkRecorder $sdk, ProviderHttpRecorder $http): array
    {
        $user = $this->user();
        $modelRepo = $this->modelRepository();
        $userRepo = $this->userRepository($user);
        $em = $this->entityManager($user, $userRepo, $modelRepo);
        $limits = $this->rateLimitService($em, $modelRepo);
        $modelConfig = $this->modelConfigService();

        $openAi = new OpenAIProvider(
            new NullLogger(),
            $http->client(),
            self::SECRET,
            $this->uploadDir,
            false,
            sdkFactory: $sdk->factory(),
        );
        $perplexity = new PerplexityProvider(new NullLogger(), self::SECRET, null, $sdk->factory());

        $locator = new ServiceLocator([
            'openai' => static fn (): OpenAIProvider => $openAi,
            'perplexity' => static fn (): PerplexityProvider => $perplexity,
        ]);
        $registry = new ProviderRegistry(
            $locator,
            $locator,
            $locator,
            $locator,
            $locator,
            $locator,
            $locator,
            $locator,
            $modelRepo,
            new NullLogger(),
            'openai',
            'test',
        );

        $breaker = new CircuitBreaker($this->circuitBreakerCache(), new NullLogger());
        $healthConfig = $this->createStub(ModelHealthConfig::class);
        $healthConfig->method('windowSeconds')->willReturn(3600);
        $health = new ModelHealthRecorder(
            $this->modelHealthCache(),
            $healthConfig,
            new FailureClassifier(),
            $modelRepo,
            new UsageFailureLogger($em->getConnection(), new NullLogger()),
            new NullLogger(),
        );

        $transcriptionUsage = new TranscriptionUsageRecorder($limits, $userRepo, new NullLogger());
        $chatUsageMeter = new AiChatUsageMeter($limits, $userRepo, $modelRepo, new NullLogger());

        $priceHistory = $this->createStub(ModelPriceHistoryRepository::class);
        $priceHistory->method('findPriceAtTimestamp')->willReturn(null);
        $costs = new CostCalculationService($modelRepo, $priceHistory, new NullLogger());

        $hookConfigRepo = $this->createStub(ConfigRepository::class);
        $hookConfigRepo->method('getValue')->willReturn($this->usageHookMode->value);
        $hookConfig = new UsageHookConfig($hookConfigRepo);

        $mismatchLogger = new class(function (array $ctx): void {
            $this->mismatches[] = $ctx;
        }) extends AbstractLogger {
            /** @param \Closure(array<string, mixed>): void $record */
            public function __construct(private readonly \Closure $record)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ('ai.usage_mismatch' === ($context['event'] ?? null)) {
                    ($this->record)($context);
                }
            }
        };

        $this->pipelineMeter = new AiPipelineMeter(
            $limits,
            $costs,
            $userRepo,
            $modelRepo,
            $hookConfig,
            new TurnUsageLedger(),
            null,
            $mismatchLogger,
        );

        $pipeline = new AiCallPipeline([
            new RequestContextHook(),
            new RateLimitHook($this->pipelineMeter),
            new CircuitBreakerHook($breaker),
            new ModelHealthHook($health),
            new UsageHook($this->pipelineMeter),
        ]);

        $sharedCache = new ArrayAdapter();
        $facade = new AiFacade(
            $registry,
            $modelConfig,
            $breaker,
            new NullLogger(),
            new UserUploadPathBuilder(),
            $this->createStub(DiscordNotificationService::class),
            $this->createStub(InternalEmailService::class),
            $sharedCache,
            $sharedCache,
            $this->createStub(HiggsfieldCredentialResolver::class),
            $transcriptionUsage,
            $health,
            $this->uploadDir,
        );
        $facade->setUsageMeter($chatUsageMeter);
        $facade->setPipeline($pipeline);

        return [$facade, $limits, $em, $user, $modelRepo, $modelConfig];
    }

    private function rateLimitService(EntityManagerInterface $em, ModelRepository $modelRepo): RateLimitService
    {
        $configRepo = $this->createStub(ConfigRepository::class);
        $configRepo->method('getValue')->willReturn('10');
        $configRepo->method('findBy')->willReturn([]);

        $billing = $this->createStub(BillingService::class);
        $billing->method('isEnabled')->willReturn(true);

        $priceHistory = $this->createStub(ModelPriceHistoryRepository::class);
        $priceHistory->method('findPriceAtTimestamp')->willReturn(null);

        $costs = new CostCalculationService($modelRepo, $priceHistory, new NullLogger());

        $subscription = $this->createStub(Subscription::class);
        $subscription->method('getCostBudgetMonthly')->willReturn('50.00');

        $subscriptions = $this->createStub(SubscriptionRepository::class);
        $subscriptions->method('findOneBy')->willReturnCallback(function () use ($subscription): Subscription {
            $this->rateLimitChecks[] = [
                'action' => 'COST_BUDGET',
                'kind' => 'cost_budget',
                'user_id' => self::USER_ID,
            ];

            return $subscription;
        });

        $topups = $this->createStub(TopupRepository::class);
        $topups->method('sumForUserInPeriod')->willReturn(0.0);

        $logger = new class(function (array $entry): void {
            $this->rateLimitChecks[] = $entry;
            $this->pipelineMeter?->markAdmitted((int) $entry['user_id'], (string) $entry['action']);
        }) extends AbstractLogger {
            /** @param \Closure(array<string, mixed>): void $record */
            public function __construct(private readonly \Closure $record)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ('Rate limit check' === (string) $message) {
                    ($this->record)([
                        'action' => (string) ($context['action'] ?? ''),
                        'kind' => 'limit',
                        'user_id' => (int) ($context['user_id'] ?? 0),
                    ]);
                }
            }
        };

        return new RateLimitService(
            $configRepo,
            $em,
            $logger,
            $billing,
            $costs,
            $subscriptions,
            $topups,
        );
    }

    private function circuitBreakerCache(): CacheItemPoolInterface
    {
        return new class(function (array $entry): void {
            $this->circuitBreaker[] = $entry;
        }) implements CacheItemPoolInterface {
            private ArrayAdapter $inner;

            /** @param \Closure(array<string, mixed>): void $record */
            public function __construct(private readonly \Closure $record)
            {
                $this->inner = new ArrayAdapter();
            }

            public function getItem(string $key): CacheItemInterface
            {
                return $this->inner->getItem($key);
            }

            public function getItems(array $keys = []): iterable
            {
                return $this->inner->getItems($keys);
            }

            public function hasItem(string $key): bool
            {
                return $this->inner->hasItem($key);
            }

            public function clear(): bool
            {
                return $this->inner->clear();
            }

            public function deleteItem(string $key): bool
            {
                if (str_starts_with($key, 'circuit_breaker.failures.')) {
                    ($this->record)([
                        'outcome' => 'success',
                        'service' => substr($key, \strlen('circuit_breaker.failures.')),
                    ]);
                }

                return $this->inner->deleteItem($key);
            }

            public function deleteItems(array $keys): bool
            {
                return $this->inner->deleteItems($keys);
            }

            public function save(CacheItemInterface $item): bool
            {
                if (str_starts_with($item->getKey(), 'circuit_breaker.failures.')) {
                    ($this->record)([
                        'outcome' => 'failure',
                        'service' => substr($item->getKey(), \strlen('circuit_breaker.failures.')),
                    ]);
                }

                return $this->inner->save($item);
            }

            public function saveDeferred(CacheItemInterface $item): bool
            {
                return $this->inner->saveDeferred($item);
            }

            public function commit(): bool
            {
                return $this->inner->commit();
            }
        };
    }

    private function modelHealthCache(): CacheItemPoolInterface
    {
        return new class(function (int $modelId, bool $failed): void {
            $resolved = $this->healthResolutionById[$modelId] ?? ['capability' => 'unknown', 'provider' => 'unknown', 'model' => 'unknown'];
            $this->modelHealth[] = [
                'capability' => $resolved['capability'],
                'model' => $resolved['model'],
                'model_id' => $modelId,
                'outcome' => $failed ? 'failure' : 'success',
                'provider' => $resolved['provider'],
            ];
        }) implements CacheItemPoolInterface {
            private ArrayAdapter $inner;

            /**
             * @param \Closure(int, bool): void $record
             */
            public function __construct(private readonly \Closure $record)
            {
                $this->inner = new ArrayAdapter();
            }

            public function getItem(string $key): CacheItemInterface
            {
                return $this->inner->getItem($key);
            }

            public function getItems(array $keys = []): iterable
            {
                return $this->inner->getItems($keys);
            }

            public function hasItem(string $key): bool
            {
                return $this->inner->hasItem($key);
            }

            public function clear(): bool
            {
                return $this->inner->clear();
            }

            public function deleteItem(string $key): bool
            {
                return $this->inner->deleteItem($key);
            }

            public function deleteItems(array $keys): bool
            {
                return $this->inner->deleteItems($keys);
            }

            public function save(CacheItemInterface $item): bool
            {
                if (str_starts_with($item->getKey(), 'model_health.window.')) {
                    $modelId = (int) substr($item->getKey(), \strlen('model_health.window.'));
                    $value = \is_array($item->get()) ? $item->get() : [];
                    ($this->record)($modelId, null !== ($value['last_kind'] ?? null));
                }

                return $this->inner->save($item);
            }

            public function saveDeferred(CacheItemInterface $item): bool
            {
                return $this->inner->saveDeferred($item);
            }

            public function commit(): bool
            {
                return $this->inner->commit();
            }
        };
    }

    private function modelRepository(): ModelRepository
    {
        $repo = $this->createStub(ModelRepository::class);
        $repo->method('getProviderCapabilities')->willReturn([
            'openai' => ['chat', 'vectorize', 'pic2text', 'text2pic', 'text2vid', 'sound2text', 'text2sound', 'analyze'],
            'anthropic' => ['chat', 'pic2text'],
            'google' => ['chat', 'pic2text'],
            'perplexity' => ['chat'],
        ]);
        $repo->method('find')->willReturnCallback(fn (mixed $id): ?Model => $this->modelEntity((int) $id));
        $repo->method('findOneBy')->willReturnCallback(function (array $criteria): ?Model {
            foreach (array_keys(self::MODELS) as $id) {
                $model = $this->modelEntity($id);
                if (null === $model) {
                    continue;
                }
                if (isset($criteria['providerId']) && $model->getProviderId() === $criteria['providerId']) {
                    return $model;
                }
                if (isset($criteria['name']) && $model->getName() === $criteria['name']) {
                    return $model;
                }
            }

            return null;
        });
        $repo->method('findActiveByTagAndIdentity')->willReturnCallback(function (string $tag, string $identity): ?Model {
            foreach (array_keys(self::MODELS) as $id) {
                $model = $this->modelEntity($id);
                if (null !== $model && strtolower($tag) === strtolower($model->getTag())
                    && ($model->getProviderId() === $identity || $model->getName() === $identity)) {
                    return $model;
                }
            }

            return null;
        });
        $repo->method('findChatIdByProvider')->willReturnCallback(function (string $provider, string $modelId): ?int {
            foreach (self::MODELS as $id => [$service, $providerId, , $tag]) {
                if ('chat' === $tag && 0 === strcasecmp($service, $provider) && 0 === strcasecmp($providerId, $modelId)) {
                    return $id;
                }
            }

            return null;
        });
        $repo->method('findIdByServiceProviderIdAndTag')->willReturnCallback(function (string $provider, string $providerId, string $tag): ?int {
            $capabilityByTag = [
                'chat' => 'chat',
                'pic2text' => 'vision',
                'vectorize' => 'embedding',
                'text2pic' => 'image_generation',
                'text2vid' => 'video_generation',
                'sound2text' => 'speech_to_text',
                'text2sound' => 'text_to_speech',
            ];
            foreach (self::MODELS as $id => [$service, $candidateId, , $candidateTag]) {
                if ($candidateTag === $tag && 0 === strcasecmp($service, $provider) && 0 === strcasecmp($candidateId, $providerId)) {
                    $this->healthResolutionById[$id] = [
                        'capability' => $capabilityByTag[$tag],
                        'provider' => strtolower($provider),
                        'model' => $providerId,
                    ];

                    return $id;
                }
            }

            return null;
        });

        return $repo;
    }

    private function modelEntity(int $id): ?Model
    {
        if (!isset(self::MODELS[$id])) {
            return null;
        }
        [$service, $providerId, $name, $tag, $priceIn, $inUnit, $priceOut, $outUnit, $json] = self::MODELS[$id];
        $model = $this->createStub(Model::class);
        $model->method('getId')->willReturn($id);
        $model->method('getService')->willReturn($service);
        $model->method('getProviderId')->willReturn($providerId);
        $model->method('getName')->willReturn($name);
        $model->method('getTag')->willReturn($tag);
        $model->method('getActive')->willReturn(1);
        $model->method('getPriceIn')->willReturn($priceIn);
        $model->method('getInUnit')->willReturn($inUnit);
        $model->method('getPriceOut')->willReturn($priceOut);
        $model->method('getOutUnit')->willReturn($outUnit);
        $model->method('isPriceKnown')->willReturn(true);
        $model->method('getJson')->willReturn($json);
        $model->method('getFeatures')->willReturn($json['features'] ?? []);
        $model->method('hasFeature')->willReturnCallback(static fn (string $f): bool => \in_array($f, $json['features'] ?? [], true));
        $model->method('isRetired')->willReturn(false);

        return $model;
    }

    private function modelConfigService(): ModelConfigService
    {
        $config = $this->createStub(ModelConfigService::class);
        $config->method('getDefaultProvider')->willReturn('openai');
        $config->method('getEffectiveUserIdForMessage')->willReturn(self::USER_ID);
        $config->method('getDefaultModel')->willReturnCallback(static fn (string $capability): int => match (strtoupper($capability)) {
            'CHAT', 'SORT', 'SUMMARIZE', 'MEM' => 12,
            'VECTORIZE', 'EMBEDDING' => 21,
            'TEXT2PIC', 'PIC2PIC' => 41,
            'TEXT2SOUND' => 45,
            'SOUND2TEXT' => 51,
            default => 12,
        });
        $config->method('getConfiguredDefaultModel')->willReturnCallback(static fn (string $capability): ?int => match (strtoupper($capability)) {
            'CHAT', 'SORT' => 12,
            'VECTORIZE' => 21,
            'TEXT2PIC' => 41,
            'TEXT2SOUND' => 45,
            'SOUND2TEXT' => 51,
            default => null,
        });
        $config->method('getProviderForModel')->willReturnCallback(function (int $id): string {
            return isset(self::MODELS[$id]) ? strtolower(self::MODELS[$id][0]) : 'openai';
        });
        $config->method('getModelName')->willReturnCallback(function (int $id): string {
            return self::MODELS[$id][1] ?? 'gpt-4o';
        });
        $config->method('getSummaryModelConfig')->willReturn([
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'model_id' => 12,
        ]);
        $config->method('getMemoryModelConfig')->willReturn([
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'model_id' => 12,
        ]);
        $config->method('resolveSttDefault')->willReturn([
            'provider' => 'openai',
            'model' => 'whisper-1',
            'model_id' => 51,
        ]);

        return $config;
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(self::USER_ID);
        $user->method('getRateLimitLevel')->willReturn('PRO');
        $user->method('getUserLevel')->willReturn('PRO');
        $user->method('getSubscriptionData')->willReturn([]);
        $user->method('isAdmin')->willReturn(false);
        $user->method('isActive')->willReturn(true);
        $user->method('isMemoriesEnabled')->willReturn(true);

        return $user;
    }

    private function chatRequestOptions(RateLimitService $limits): ChatRequestOptions
    {
        $structuredOutput = $this->createStub(StructuredOutputConfig::class);
        $structuredOutput->method('isEnabled')->willReturn(true);

        return new ChatRequestOptions($structuredOutput, $limits);
    }

    private function userRepository(User $user): UserRepository
    {
        $repo = $this->createStub(UserRepository::class);
        $repo->method('find')->willReturnCallback(static fn (mixed $id): ?User => self::USER_ID === (int) $id ? $user : null);

        return $repo;
    }

    private function entityManager(User $user, UserRepository $userRepo, ModelRepository $modelRepo): EntityManagerInterface
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchOne')->willReturn('0');
        $conn->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []): int {
            if (str_contains($sql, 'INSERT INTO BUSELOG')) {
                $metadata = json_decode((string) ($params['metadata'] ?? '{}'), true);
                $metadata = \is_array($metadata) ? $metadata : [];
                unset($metadata['latency']);
                ksort($metadata);

                $this->buselog[] = [
                    'action' => (string) ($params['action'] ?? ''),
                    'cost' => number_format((float) ($params['cost'] ?? 0), 6, '.', ''),
                    'error' => (string) ($params['error'] ?? ''),
                    'metadata' => $metadata,
                    'model' => (string) ($params['model'] ?? ''),
                    'model_id' => isset($params['model_id']) ? (int) $params['model_id'] : null,
                    'provider' => (string) ($params['provider'] ?? ''),
                    'status' => (string) ($params['status'] ?? 'success'),
                    'tokens' => [
                        'cache_creation' => (int) ($params['cache_creation_tokens'] ?? 0),
                        'cached' => (int) ($params['cached_tokens'] ?? 0),
                        'completion' => (int) ($params['completion_tokens'] ?? 0),
                        'estimated' => (bool) ($params['estimated'] ?? false),
                        'prompt' => (int) ($params['prompt_tokens'] ?? 0),
                        'total' => (int) ($params['tokens'] ?? 0),
                    ],
                ];
            }

            return 1;
        });

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('getReference')->willReturnCallback(static fn (string $class): ?User => User::class === $class ? $user : null);
        $em->method('getRepository')->willReturnCallback(function (string $class) use ($userRepo, $modelRepo) {
            if (User::class === $class || 'App\Entity\User' === $class) {
                return $userRepo;
            }
            if (Model::class === $class || 'App\Entity\Model' === $class) {
                return $modelRepo;
            }

            return $this->createStub(EntityRepository::class);
        });

        return $em;
    }

    private function chatReplyGenerator(AiFacade $facade, EntityManagerInterface $em): ChatReplyGenerator
    {
        $docs = $this->createStub(ChatDocumentOutput::class);
        $docs->method('tryToolsEdit')->willReturn(null);

        $registry = $this->createStub(\Doctrine\Persistence\ManagerRegistry::class);
        $registry->method('getConnections')->willReturn([]);

        return new ChatReplyGenerator(
            $facade,
            $docs,
            new NullLogger(),
            new ProviderTranscriptCapture(),
            new DatabaseConnectionRelease($registry, new NullLogger()),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function chatTurn(string $text, array $options = []): ChatTurn
    {
        return new ChatTurn(
            $this->message(101, $text),
            [],
            ['topic' => 'general', 'language' => 'en'],
            $options,
            null,
            null,
            new PerfTimer(),
        );
    }

    private function message(int $id, string $text, string $direction = 'IN'): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getId')->willReturn($id);
        $message->method('getUserId')->willReturn(self::USER_ID);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn('en');
        $message->method('getTopic')->willReturn('general');
        $message->method('getDirection')->willReturn($direction);
        $message->method('getChatId')->willReturn(5);
        $message->method('getTrackingId')->willReturn(555);
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection());

        return $message;
    }

    private function openAiCompatibleController(
        AiFacade $facade,
        ModelRepository $modelRepo,
        ModelConfigService $modelConfig,
        RateLimitService $limits,
    ): OpenAICompatibleController {
        $gwConfig = $this->createStub(MessagesGatewayConfig::class);
        $gwConfig->method('isSessionSummaryEnabled')->willReturn(false);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $gate = $this->createStub(OpenAiToolCallingGate::class);
        $gate->method('allows')->willReturn(false);

        return new OpenAICompatibleController(
            $facade,
            $modelRepo,
            $modelConfig,
            $limits,
            $gwConfig,
            $bus,
            new NullLogger(),
            $gate,
            $this->createStub(OpenAiGatewayToolLoop::class),
        );
    }

    /**
     * @param array{prompt: string, media_type: string} $extractedPrompt
     */
    private function mediaHandler(
        AiFacade $facade,
        ModelConfigService $modelConfig,
        EntityManagerInterface $em,
        RateLimitService $limits,
        array $extractedPrompt,
    ): \App\Service\Message\Handler\MediaGenerationHandler {
        $extractor = $this->createStub(MediaPromptExtractor::class);
        $extractor->method('extract')->willReturn($extractedPrompt);

        $downloader = $this->createStub(MediaDownloader::class);
        $downloader->method('fetch')->willReturn((string) base64_decode(self::PIXEL, true));

        $jobConfig = $this->createStub(MediaJobConfig::class);
        $jobConfig->method('isAsyncJobsEnabled')->willReturn(false);

        $registrar = $this->createStub(GeneratedFileRegistrar::class);
        $registrar->method('register')->willReturn($this->createStub(File::class));

        $gate = $this->createStub(PremiumFeatureGate::class);
        $gate->method('isCostBudgetEnforced')->willReturn(true);

        $catalog = $this->createStub(ConversationFileCatalog::class);
        $catalog->method('build')->willReturn([]);
        $catalog->method('latestImage')->willReturn(null);

        return MediaGenerationHandlerFactory::build([
            'ai' => $facade,
            'models' => $modelConfig,
            'em' => $em,
            'extractor' => $extractor,
            'downloader' => $downloader,
            'thumbnails' => $this->createStub(ThumbnailService::class),
            'limits' => $limits,
            'memory' => $this->createStub(PendingMemoryExtraction::class),
            'cancellation' => $this->createStub(MediaCancellationStore::class),
            'jobConfig' => $jobConfig,
            'jobs' => $this->createStub(MediaJobService::class),
            'jobDispatcher' => $this->createStub(MediaJobDispatcher::class),
            'jobSync' => $this->createStub(MediaJobMessageSync::class),
            'registrar' => $registrar,
            'gate' => $gate,
            'catalog' => $catalog,
            'uploadDir' => $this->uploadDir,
            'publicBaseUrl' => 'http://localhost:8000',
            'folders' => null,
            'chat' => null,
        ]);
    }

    private static function openAiStreamSse(string $text): Response
    {
        $created = [
            'type' => 'response.created',
            'response' => OpenAIFixtures::responseArray([], ['id' => 'resp_s1', 'status' => 'in_progress', 'usage' => null]),
        ];
        $delta = [
            'type' => 'response.output_text.delta',
            'item_id' => 'msg_s1',
            'output_index' => 0,
            'content_index' => 0,
            'delta' => $text,
        ];
        $completed = [
            'type' => 'response.completed',
            'response' => OpenAIFixtures::responseArray([OpenAIFixtures::message($text)], ['id' => 'resp_s1', 'status' => 'completed']),
        ];

        return OpenAIFixtures::sse($created, $delta, $completed);
    }

    /**
     * @return array<string, mixed>
     */
    private function pack(OpenAISdkRecorder $sdk, ProviderHttpRecorder $http, mixed $result): array
    {
        return $this->normalize([
            'buselog' => $this->buselog,
            'circuit_breaker' => $this->circuitBreaker,
            'model_health' => $this->modelHealth,
            'provider_requests' => array_filter([
                'http' => $http->requests(),
                'sdk' => $sdk->requests(),
            ], static fn (array $list): bool => [] !== $list),
            'rate_limit_checks' => $this->rateLimitChecks,
            'result' => $result,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        array_walk_recursive($data, function (mixed &$value): void {
            if (\is_string($value)) {
                $value = str_replace(
                    [$this->uploadDir, '/'.date('Y').'/'.date('m').'/'],
                    ['<uploads>', '/<year>/<month>/'],
                    $value,
                );
                $value = (string) preg_replace(
                    ['/tts_[0-9a-f]{13}\./', '/^php[A-Za-z0-9]{6,}$/', '/_\d{10}_[0-9a-f]{12}\./', '/_\d{10}\./', '/^msg_[0-9a-f]{16}$/'],
                    ['tts_<unique>.', '<tmpfile>', '_<time>_<random>.', '_<time>.', 'msg_<random>'],
                    $value,
                );
            }
        });

        return $data;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T&\PHPUnit\Framework\MockObject\Stub
     */
    private function createStub(string $class): object
    {
        $ref = new \ReflectionMethod(TestCase::class, 'createStub');

        /* @var T&\PHPUnit\Framework\MockObject\Stub */
        return $ref->invoke($this->test, $class);
    }
}
