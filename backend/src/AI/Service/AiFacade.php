<?php

namespace App\AI\Service;

use App\AI\Credential\HiggsfieldCredentialResolver;
use App\AI\Exception\NoSpeakableTextException;
use App\AI\Exception\ProviderException;
use App\AI\Exception\StructuredOutputViolationException;
use App\AI\Health\ModelHealthRecorder;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\ProviderMetadataInterface;
use App\AI\Interface\SupportsAsyncVideo;
use App\AI\Interface\SupportsInlineReferenceImage;
use App\AI\Pipeline\AiCall;
use App\AI\Pipeline\AiCallContext;
use App\AI\Pipeline\AiCallOptions;
use App\AI\Pipeline\AiCallPayload;
use App\AI\Pipeline\AiCallPipeline;
use App\AI\Pipeline\AiCallSource;
use App\AI\Pipeline\AiCapability;
use App\AI\Pipeline\AiUsage;
use App\AI\Pipeline\Hook\CircuitBreakerHook;
use App\AI\Pipeline\Hook\ModelHealthHook;
use App\AI\Pipeline\Hook\RequestContextHook;
use App\AI\Provider\GoogleProvider;
use App\AI\Stream\StreamTextCapture;
use App\AI\StructuredOutput\StructuredOutputRecovery;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Service\Email\InternalEmailService;
use App\Service\Exception\StreamCancelledException;
use App\Service\File\FileHelper;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Infrastructure\CircuitBreaker;
use App\Service\Media\TtsTextSanitizer;
use App\Service\ModelConfigService;
use App\Service\Notification\DiscordNotificationService;
use App\Service\Stt\SpeechLanguageContext;
use App\Service\Stt\TranscriptionLanguageDecider;
use App\Service\Usage\AiChatUsageMeter;
use App\Service\Usage\RecordedUsage;
use App\Service\Usage\TranscriptionUsageRecorder;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\Attribute\Required;

class AiFacade
{
    private ?AiChatUsageMeter $usageMeter = null;
    private ?AiCallPipeline $pipeline = null;
    private ?AiEmbeddingExecutor $embeddings = null;
    private ?AiVisionExecutor $vision = null;

    public function __construct(
        private ProviderRegistry $registry,
        private ModelConfigService $modelConfig,
        private CircuitBreaker $circuitBreaker,
        private LoggerInterface $logger,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private DiscordNotificationService $discordNotification,
        private InternalEmailService $emailService,
        private CacheInterface $cache,
        private CacheItemPoolInterface $cachePool,
        private HiggsfieldCredentialResolver $higgsfieldCredentials,
        private TranscriptionUsageRecorder $transcriptionUsageRecorder,
        private ModelHealthRecorder $health,
        private string $uploadDir = '/var/www/backend/var/uploads',
        private string $embeddingFallbackProvider = '',
        private StructuredOutputRecovery $structuredOutputRecovery = new StructuredOutputRecovery(),
        private ?SpeechLanguageContext $speechLanguages = null,
    ) {
    }

    /**
     * Every chat is metered here. A setter keeps the constructor at its
     * current size; tests that build the facade by hand simply do not meter.
     */
    #[Required]
    public function setUsageMeter(AiChatUsageMeter $usageMeter): void
    {
        $this->usageMeter = $usageMeter;
    }

    #[Required]
    public function setPipeline(AiCallPipeline $pipeline): void
    {
        $this->pipeline = $pipeline;
        $this->embeddings = null;
        $this->vision = null;
    }

    public function pipeline(): AiCallPipeline
    {
        return $this->pipeline ??= new AiCallPipeline([
            new RequestContextHook(),
            new CircuitBreakerHook($this->circuitBreaker),
            new ModelHealthHook($this->health),
        ], $this->logger);
    }

    private function embeddingExecutor(): AiEmbeddingExecutor
    {
        return $this->embeddings ??= new AiEmbeddingExecutor(
            $this->registry,
            $this->modelConfig,
            $this->pipeline(),
            $this->cache,
            $this->cachePool,
            $this->discordNotification,
            $this->emailService,
            $this->logger,
            $this->embeddingFallbackProvider,
        );
    }

    private function visionExecutor(): AiVisionExecutor
    {
        return $this->vision ??= new AiVisionExecutor(
            $this->registry,
            $this->modelConfig,
            $this->pipeline(),
            $this->logger,
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function maybeInjectProviderCredentials(string $providerName, ?int $userId, array $options): array
    {
        if ('higgsfield' !== strtolower($providerName)) {
            return $options;
        }

        if (isset($options['credentials']) && \is_array($options['credentials'])) {
            return $options;
        }

        $resolved = $this->higgsfieldCredentials->resolve($userId);
        if (null === $resolved) {
            return $options;
        }

        $options['credentials'] = $resolved;

        return $options;
    }

    /**
     * Chat: Messages-Array or simple prompt.
     *
     * @param array|string $messages Messages array or simple string prompt
     * @param int|null     $userId   User ID for config lookup
     * @param array        $options  Additional options (provider, model, temperature, etc.)
     *
     * @return array Response with content, provider, model, usage
     */
    public function chat(array|string $messages, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;

        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'chat');
        }

        $provider = $this->registry->getChatProvider($providerName);

        if (\is_string($messages)) {
            $messages = [['role' => 'user', 'content' => $messages]];
        }

        $messages = Utf8MessageSanitizer::apply($messages);
        $billTo = AiChatUsageMeter::claim($options);
        $source = AiCallSource::fromChannel(\is_string($billTo['source'] ?? null) ? $billTo['source'] : null, AiCallSource::ChatWeb);

        $this->logger->info('AI chat request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'messages_count' => \count($messages),
        ]);

        try {
            $response = $this->runChatCall($provider, $messages, $options, $userId, $source, (bool) ($billTo[AiChatUsageMeter::CALLER_METERS] ?? false));
        } catch (StructuredOutputViolationException $e) {
            $response = $this->recoverStructuredOutput($e, $provider, $messages, $options, $userId, $source, (bool) ($billTo[AiChatUsageMeter::CALLER_METERS] ?? false));
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('AI chat failed', [
                'error' => $e->getMessage(),
            ]);
            throw new ProviderException('AI provider failed', 'unknown', null, 0, $e);
        }

        $out = [
            'content' => $response['content'] ?? '',
            'provider' => $provider->getName(),
            'model' => $options['model'] ?? $provider->getDefaultModels()['chat'] ?? 'unknown',
            'usage' => $response['usage'] ?? [],
            'response_id' => $response['response_id'] ?? null,
            'tool_calls' => $response['tool_calls'] ?? [],
        ];
        if (isset($response[StructuredOutputRecovery::RESPONSE_KEY]) && \is_string($response[StructuredOutputRecovery::RESPONSE_KEY])) {
            $out[StructuredOutputRecovery::RESPONSE_KEY] = $response[StructuredOutputRecovery::RESPONSE_KEY];
        }
        if (isset($response['tool_calls']) && \is_array($response['tool_calls'])) {
            $out['tool_calls'] = $response['tool_calls'];
        }
        if (isset($response['finish_reason']) && \is_string($response['finish_reason']) && '' !== $response['finish_reason']) {
            $out['finish_reason'] = $response['finish_reason'];
        }

        return $this->withUsage($out, $messages, $options, $userId, $billTo);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    private function runChatCall(
        ChatProviderInterface $provider,
        array $messages,
        array $options,
        ?int $userId,
        AiCallSource $source,
        bool $callerMeters,
    ): array {
        $call = new AiCall(
            capability: AiCapability::Chat,
            provider: $provider->getName(),
            model: $this->reportedModel($provider, $options, 'chat'),
            context: new AiCallContext(userId: $userId, source: $source),
            payload: AiCallPayload::chat($messages),
            options: AiCallOptions::fromArray($options, stream: false, callerMeters: $callerMeters),
        );

        /* @var array<string, mixed> */
        return $this->pipeline()->run(
            $call,
            static fn (AiCall $c): array => $provider->chat($c->payload->messages, $c->options->providerOptions),
        )->value;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    private function recoverStructuredOutput(
        StructuredOutputViolationException $violation,
        ChatProviderInterface $provider,
        array $messages,
        array $options,
        ?int $userId,
        AiCallSource $source,
        bool $callerMeters,
    ): array {
        $schema = $options['structured_output'] ?? null;
        if (!$schema instanceof StructuredOutputSchema) {
            throw $violation;
        }

        $logContext = [
            'provider' => $provider->getName(),
            'model' => $options['model'] ?? null,
            'schema' => $schema->name,
            'user_id' => $userId,
            'validation_error' => $violation->getValidationError(),
        ];

        $salvaged = $this->structuredOutputRecovery->salvage($violation->getFailedGeneration(), $schema);
        if (null !== $salvaged) {
            $this->logger->warning('AI chat: salvaged a schema-rejected generation without a retry', $logContext);

            return $this->salvagedResponse($salvaged);
        }

        $this->logger->warning('AI chat: schema-rejected generation not salvageable, retrying once with a corrective turn', $logContext + [
            'failed_generation' => mb_substr((string) $violation->getFailedGeneration(), 0, 500),
        ]);

        $repairMessages = $this->structuredOutputRecovery->repairMessages($messages, $violation, $schema);

        try {
            $response = $this->runChatCall($provider, $repairMessages, $options, $userId, $source, $callerMeters);
        } catch (StructuredOutputViolationException $retryViolation) {
            $salvaged = $this->structuredOutputRecovery->salvage($retryViolation->getFailedGeneration(), $schema);
            if (null !== $salvaged) {
                $this->logger->warning('AI chat: salvaged the corrective retry\'s schema-rejected generation', $logContext);

                return $this->salvagedResponse($salvaged);
            }

            $this->logger->error('AI chat: structured output unrecoverable after salvage and one corrective retry', $logContext + [
                'retry_validation_error' => $retryViolation->getValidationError(),
                'retry_failed_generation' => mb_substr((string) $retryViolation->getFailedGeneration(), 0, 500),
            ]);

            throw $retryViolation;
        }

        $this->logger->warning('AI chat: corrective retry repaired the structured output', $logContext);
        $response[StructuredOutputRecovery::RESPONSE_KEY] = StructuredOutputRecovery::RECOVERY_REPAIRED;

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function salvagedResponse(string $content): array
    {
        return [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'cached_tokens' => 0,
                'cache_creation_tokens' => 0,
            ],
            StructuredOutputRecovery::RESPONSE_KEY => StructuredOutputRecovery::RECOVERY_SALVAGED,
        ];
    }

    /**
     * Chat with streaming support.
     *
     * @param array|string $messages       Messages array or simple string prompt
     * @param callable     $streamCallback Callback function for each chunk
     * @param int|null     $userId         User ID for config lookup
     * @param array        $options        Additional options (provider, model, temperature, etc.)
     *
     * @return array Metadata (provider, model, usage)
     */
    public function chatStream(array|string $messages, callable $streamCallback, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;

        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'chat');
        }

        $provider = $this->registry->getChatProvider($providerName);

        if (\is_string($messages)) {
            $messages = [['role' => 'user', 'content' => $messages]];
        }

        $messages = Utf8MessageSanitizer::apply($messages);
        $billTo = AiChatUsageMeter::claim($options);
        $source = AiCallSource::fromChannel(\is_string($billTo['source'] ?? null) ? $billTo['source'] : null, AiCallSource::ChatStream);
        $answer = StreamTextCapture::wrap($streamCallback);

        $this->logger->info('🔵 AiFacade: Starting chat stream', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'messages_count' => \count($messages),
            'model' => $options['model'] ?? 'default',
        ]);

        $call = new AiCall(
            capability: AiCapability::ChatStream,
            provider: $provider->getName(),
            model: $this->reportedModel($provider, $options, 'chat'),
            context: new AiCallContext(userId: $userId, source: $source),
            payload: AiCallPayload::chat($messages),
            options: AiCallOptions::fromArray($options, stream: true, callerMeters: (bool) ($billTo[AiChatUsageMeter::CALLER_METERS] ?? false)),
        );

        try {
            $streamResult = $this->pipeline()->stream(
                $call,
                function (AiCall $c, callable $onChunk) use ($provider): mixed {
                    $this->logger->info('🟢 AiFacade: Calling provider chatStream');
                    $res = $provider->chatStream($c->payload->messages, $onChunk, $c->options->providerOptions);
                    $this->logger->info('🔵 AiFacade: Provider chatStream completed');

                    return $res;
                },
                $answer,
            )->value;
        } catch (ProviderException|StreamCancelledException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('🔴 AiFacade: Chat stream failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new ProviderException('AI provider failed for streaming', 'unknown', null, 0, $e);
        }

        return $this->withUsage([
            'provider' => $provider->getName(),
            'model' => $options['model'] ?? $provider->getDefaultModels()['chat'] ?? 'unknown',
            'content' => $answer->text(),
            'usage' => \is_array($streamResult) ? ($streamResult['usage'] ?? []) : [],
            'response_id' => \is_array($streamResult) ? ($streamResult['response_id'] ?? null) : null,
            'tool_calls' => \is_array($streamResult) ? ($streamResult['tool_calls'] ?? []) : [],
            'finish_reason' => \is_array($streamResult) && \is_string($streamResult['finish_reason'] ?? null) ? $streamResult['finish_reason'] : null,
        ], $messages, $options, $userId, $billTo);
    }

    /**
     * @param array<string, mixed> $response
     * @param list<mixed>          $messages
     * @param array<string, mixed> $options
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function withUsage(array $response, array $messages, array $options, ?int $userId, array $context = []): array
    {
        $recorded = $this->usageMeter?->record($userId, $response, $messages, $options, $context);
        if ($recorded instanceof RecordedUsage) {
            $response['facade_metered'] = true;
            $response['facade_charged_cost'] = $recorded->chargedCost;
            $response['facade_price_known'] = $recorded->priceKnown;
        }

        return $response;
    }

    /**
     * @return array{embedding: array<float>, usage: array{prompt_tokens: int, total_tokens: int}}
     */
    public function embed(string $text, ?int $userId = null, array $options = []): array
    {
        return $this->embeddingExecutor()->embed($text, $userId, $options);
    }

    /**
     * @param string[] $texts
     *
     * @return array{embeddings: array<array<float>>, usage: array{prompt_tokens: int, total_tokens: int}}
     */
    public function embedBatch(array $texts, ?int $userId = null, ?string $providerName = null, array $options = []): array
    {
        return $this->embeddingExecutor()->embedBatch($texts, $userId, $providerName, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function analyzeImage(string $imagePath, string $prompt, ?int $userId = null, array $options = []): array
    {
        return $this->visionExecutor()->analyzeImage($imagePath, $prompt, $userId, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function generateImage(string $prompt, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;

        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'image_generation');
        }

        $provider = $this->registry->getImageGenerationProvider($providerName);
        $options = $this->maybeInjectProviderCredentials($provider->getName(), $userId, $options);
        $reportedModel = $this->reportedModel($provider, $options, 'image_generation');

        $this->logger->info('AI image generation request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'prompt_length' => \strlen($prompt),
        ]);

        $call = new AiCall(
            capability: AiCapability::Image,
            provider: $provider->getName(),
            model: $reportedModel,
            context: new AiCallContext(userId: $userId, source: AiCallSource::Media),
            payload: AiCallPayload::mediaPrompt($prompt),
            options: AiCallOptions::fromArray($options),
        );

        try {
            $images = $this->pipeline()->run(
                $call,
                static fn (AiCall $c): mixed => $provider->generateImage((string) $c->payload->prompt, $c->options->providerOptions),
                static fn (mixed $imgs): AiUsage => new AiUsage(imageCount: \is_array($imgs) ? \count($imgs) : 1),
            )->value;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('AI image generation failed', [
                'error' => $e->getMessage(),
            ]);
            throw new ProviderException('Image generation failed', 'unknown', null, 0, $e);
        }

        return [
            'images' => $images,
            'provider' => $provider->getName(),
            'model' => $reportedModel,
            'image_count' => \is_array($images) ? \count($images) : 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function generateVideo(string $prompt, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;

        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'video_generation');
        }

        $provider = $this->registry->getVideoGenerationProvider($providerName);
        $options = $this->maybeInjectProviderCredentials($provider->getName(), $userId, $options);
        $reportedModel = $this->reportedModel($provider, $options, 'video_generation');

        $this->logger->info('AI video generation request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'prompt_length' => \strlen($prompt),
        ]);

        $call = new AiCall(
            capability: AiCapability::VideoStart,
            provider: $provider->getName(),
            model: $reportedModel,
            context: new AiCallContext(userId: $userId, source: AiCallSource::Media),
            payload: AiCallPayload::mediaPrompt($prompt),
            options: AiCallOptions::fromArray($options),
        );

        try {
            $videos = $this->pipeline()->run(
                $call,
                static fn (AiCall $c): mixed => $provider->generateVideo((string) $c->payload->prompt, $c->options->providerOptions),
            )->value;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('AI video generation failed', [
                'error' => $e->getMessage(),
            ]);
            throw new ProviderException('Video generation failed', 'unknown', null, 0, $e);
        }

        $durationSeconds = null;
        $resolution = null;
        if (\is_array($videos) && !empty($videos)) {
            $durationSeconds = $videos[0]['duration'] ?? $videos[0]['duration_seconds'] ?? null;
            $resolution = $videos[0]['resolution'] ?? null;
        }

        return [
            'videos' => $videos,
            'provider' => $provider->getName(),
            'model' => $reportedModel,
            'duration_seconds' => $durationSeconds,
            'resolution' => $resolution,
        ];
    }

    public function supportsAsyncVideo(?string $providerName): bool
    {
        if (null === $providerName || '' === $providerName) {
            return false;
        }

        try {
            $provider = $this->registry->getVideoGenerationProvider($providerName);
        } catch (\Throwable) {
            return false;
        }

        return $provider instanceof SupportsAsyncVideo;
    }

    public function supportsInlineReferenceImage(?string $providerName): bool
    {
        if (null === $providerName || '' === $providerName) {
            return false;
        }

        try {
            $provider = $this->registry->getVideoGenerationProvider($providerName);
        } catch (\Throwable) {
            return false;
        }

        return $provider instanceof SupportsInlineReferenceImage;
    }

    /**
     * @return array{operationName: string, provider: string, model: string, duration: int, resolution: string}
     */
    public function startVideoGeneration(string $prompt, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;

        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'video_generation');
        }

        $provider = $this->registry->getVideoGenerationProvider($providerName);
        $options = $this->maybeInjectProviderCredentials($provider->getName(), $userId, $options);

        if (!$provider instanceof SupportsAsyncVideo) {
            throw new ProviderException('Async video generation is not supported by '.$provider->getName(), $provider->getName());
        }

        $this->logger->info('Starting async video generation', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
        ]);

        $call = new AiCall(
            capability: AiCapability::VideoStart,
            provider: $provider->getName(),
            model: $this->reportedModel($provider, $options, 'video_generation'),
            context: new AiCallContext(userId: $userId, source: AiCallSource::Media),
            payload: AiCallPayload::mediaPrompt($prompt),
            options: AiCallOptions::fromArray($options + ['skip_circuit_breaker' => true, 'skip_model_health' => true]),
        );

        /** @var array{operationName: string, model: string, duration: int, resolution: string} $operationData */
        $operationData = $this->pipeline()->run(
            $call,
            static fn (AiCall $c): array => $provider->startVideoOperation((string) $c->payload->prompt, array_diff_key($c->options->providerOptions, ['skip_circuit_breaker' => true, 'skip_model_health' => true])),
        )->value;

        return [
            ...$operationData,
            'provider' => $provider->getName(),
        ];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{done: bool, videoUri: ?string, error: ?string, status?: ?string, percent?: ?int}
     */
    public function pollVideoOperation(string $operationName, ?string $providerName = null, ?int $userId = null, array $options = []): array
    {
        $provider = $this->registry->getVideoGenerationProvider($providerName);

        if (!$provider instanceof SupportsAsyncVideo) {
            throw new ProviderException('Async video polling is not supported by '.$provider->getName(), $provider->getName());
        }

        if (null !== $userId) {
            $options = $this->maybeInjectProviderCredentials($provider->getName(), $userId, $options);
        }

        $call = new AiCall(
            capability: AiCapability::VideoPoll,
            provider: $provider->getName(),
            model: $this->reportedModel($provider, $options, 'video_generation'),
            context: new AiCallContext(userId: $userId, source: AiCallSource::Media, billable: false),
            payload: AiCallPayload::videoOperation($operationName),
            options: AiCallOptions::fromArray($options),
        );

        /* @var array{done: bool, videoUri: ?string, error: ?string, status?: ?string, percent?: ?int} */
        return $this->pipeline()->run(
            $call,
            static fn (AiCall $c): array => $provider->pollVideoOperationOnce((string) $c->payload->operationId, $c->options->providerOptions),
        )->value;
    }

    public function downloadVideoContent(string $videoUri, ?string $providerName = null): string
    {
        $provider = $this->registry->getVideoGenerationProvider($providerName);

        if (!$provider instanceof GoogleProvider) {
            throw new ProviderException('Async video download is only supported by Google Veo', $provider->getName());
        }

        return $provider->downloadVideoContent($videoUri);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function downloadVideoRaw(string $videoUri, ?string $providerName = null, ?int $userId = null, array $options = []): string
    {
        $provider = $this->registry->getVideoGenerationProvider($providerName);

        if (!$provider instanceof SupportsAsyncVideo) {
            throw new ProviderException('Async video download is not supported by '.$provider->getName(), $provider->getName());
        }

        if (null !== $userId) {
            $options = $this->maybeInjectProviderCredentials($provider->getName(), $userId, $options);
        }

        return $provider->downloadVideoRaw($videoUri, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function cancelVideoOperation(string $operationName, ?string $providerName = null, ?int $userId = null, array $options = []): void
    {
        $provider = $this->registry->getVideoGenerationProvider($providerName);

        if (!$provider instanceof SupportsAsyncVideo) {
            return;
        }

        if (null !== $userId) {
            $options = $this->maybeInjectProviderCredentials($provider->getName(), $userId, $options);
        }

        try {
            $provider->cancelVideoOperation($operationName, $options);
        } catch (\Throwable $e) {
            $this->logger->warning('AiFacade: cancelVideoOperation failed (ignored)', [
                'provider' => $provider->getName(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function reportedModel(ProviderMetadataInterface $provider, array $options, string $capability): string
    {
        $requested = $options['model'] ?? null;
        if (\is_string($requested) && '' !== trim($requested)) {
            return $requested;
        }

        $defaults = $provider->getDefaultModels();
        $default = $defaults[$capability] ?? null;
        if (\is_string($default) && '' !== trim($default)) {
            return $default;
        }

        return 'unknown';
    }

    /**
     * @return array<string, mixed>
     */
    public function transcribe(string $audioPath, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;
        $callerSuppliedModel = \array_key_exists('model', $options);
        $sttModelId = $this->positiveIntOrNull($options['model_id'] ?? null);
        unset($options['model_id']);

        if (!$providerName && null !== $userId && $userId > 0) {
            $sttDefault = $this->modelConfig->resolveSttDefault($userId);
            $providerName = $sttDefault['provider'];
            $sttModelId ??= $sttDefault['model_id'];

            if (null !== $sttDefault['model'] && !$callerSuppliedModel) {
                $options['model'] = $sttDefault['model'];
            }
        }

        $provider = $this->registry->getSpeechToTextProvider($providerName);

        $explicit = \is_string($options['language'] ?? null) ? $options['language'] : null;
        $languageOptions = $options;
        unset($options['language'], $options['ui_language'], $options['channel_language'], $options['expected_languages']);
        $speech = null !== $this->speechLanguages
            ? $this->speechLanguages->resolve($userId, $languageOptions, $explicit)
            : SpeechLanguageContext::fromSignals($languageOptions, $explicit, null, []);

        $this->logger->info('AI transcription request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'audio' => basename($audioPath),
            'model' => $options['model'] ?? null,
            'model_id' => $sttModelId,
            'language_hint' => $speech['hint'],
        ]);

        try {
            $result = (new TranscriptionLanguageDecider())->transcribe(
                function (?string $hint) use ($provider, $audioPath, $options, $userId): array {
                    $callOptions = $options;
                    if (null !== $hint && '' !== $hint) {
                        $callOptions['language'] = $hint;
                    }

                    $call = new AiCall(
                        capability: AiCapability::Transcribe,
                        provider: $provider->getName(),
                        model: $this->reportedModel($provider, $callOptions, 'speech_to_text'),
                        context: new AiCallContext(userId: $userId, source: AiCallSource::Media),
                        payload: AiCallPayload::audioFile($audioPath),
                        options: AiCallOptions::fromArray($callOptions),
                    );
                    $transcribed = $this->pipeline()->run(
                        $call,
                        static fn (AiCall $c): mixed => $provider->transcribe((string) $c->payload->filePath, $c->options->providerOptions),
                    )->value;

                    return \is_array($transcribed) ? $transcribed : [];
                },
                $speech['expected'],
                $speech['hint'],
            );
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('AI transcription failed', [
                'error' => $e->getMessage(),
            ]);
            throw new ProviderException('Transcription failed', 'unknown', null, 0, $e);
        }

        $enriched = array_merge($result, [
            'provider' => $provider->getName(),
            'model' => $this->reportedModel($provider, $options, 'speech_to_text'),
            'model_id' => $sttModelId,
        ]);

        $recordedTranscriptionUsage = $this->transcriptionUsageRecorder->record(
            userId: $userId,
            modelId: $sttModelId,
            provider: $provider->getName(),
            model: (string) $enriched['model'],
            durationSeconds: (float) ($result['duration'] ?? 0) + (float) ($result['first_pass_duration'] ?? 0),
            extraMetadata: ['language' => $result['language'] ?? 'unknown'],
        );
        if (null !== $recordedTranscriptionUsage) {
            $enriched['transcription_usage'] = $recordedTranscriptionUsage->toMessageUsage(
                $provider->getName(),
                (string) $enriched['model'],
                'TRANSCRIPTION',
            );
        }

        return $enriched;
    }

    public function hasConfiguredSttProvider(?int $userId): bool
    {
        if (!$userId || $userId <= 0) {
            return false;
        }

        $modelId = $this->modelConfig->getConfiguredDefaultModel('SOUND2TEXT', $userId);
        if (null === $modelId || $modelId <= 0 || !$this->modelConfig->isConfiguredModelUsable($modelId)) {
            return false;
        }

        $provider = strtolower((string) $this->modelConfig->getProviderForModel($modelId));

        return '' !== $provider && 'whisper' !== $provider;
    }

    /**
     * @return array<string, mixed>
     */
    public function synthesize(string $text, string $language, ?int $userId = null, array $options = []): array
    {
        $language = trim($language);
        if ('' === $language) {
            throw new \InvalidArgumentException('TTS language is required');
        }
        $options['language'] = $language;

        $providerName = $options['provider'] ?? null;
        $ttsModelId = null;

        if ($userId > 0) {
            $ttsModelId = $this->modelConfig->getDefaultModel('TEXT2SOUND', $userId);
            if ($ttsModelId) {
                if (!$providerName) {
                    $providerName = $this->modelConfig->getProviderForModel($ttsModelId);
                }
                if (!isset($options['model'])) {
                    $options['model'] = $this->modelConfig->getModelName($ttsModelId);
                }
            }
        }

        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'text_to_speech');
        }

        $provider = $this->registry->getTextToSpeechProvider($providerName);

        $text = TtsTextSanitizer::prepareForSynthesis($text);
        if ('' === $text) {
            throw new NoSpeakableTextException();
        }

        $reportedModel = $this->reportedModel($provider, $options, 'text_to_speech');

        $this->logger->info('AI TTS request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'language' => $language,
            'text_length' => \strlen($text),
        ]);

        $call = new AiCall(
            capability: AiCapability::Synthesize,
            provider: $provider->getName(),
            model: $reportedModel,
            context: new AiCallContext(userId: $userId, source: AiCallSource::Media),
            payload: AiCallPayload::speechText($text),
            options: AiCallOptions::fromArray($options),
        );

        try {
            $filename = (string) $this->pipeline()->run(
                $call,
                static fn (AiCall $c): string => $provider->synthesize((string) $c->payload->prompt, $c->options->providerOptions),
                static fn (): AiUsage => new AiUsage(characterCount: mb_strlen($text)),
            )->value;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('AI TTS failed', [
                'error' => $e->getMessage(),
            ]);
            throw new ProviderException('TTS failed', 'unknown', null, 0, $e);
        }

        $relativePath = $this->moveToUserPath($filename, $userId, $provider->getName());

        return [
            'filename' => basename($relativePath),
            'relativePath' => $relativePath,
            'provider' => $provider->getName(),
            'model' => $reportedModel,
            'model_id' => $ttsModelId ?? null,
            'text_length' => mb_strlen($text),
        ];
    }

    /**
     * @return array{generator: \Generator, contentType: string, provider: string, supportsStreaming: bool}
     */
    public function synthesizeStream(string $text, string $language, ?int $userId = null, array $options = []): array
    {
        $language = trim($language);
        if ('' === $language) {
            throw new \InvalidArgumentException('TTS language is required');
        }
        $options['language'] = $language;

        $providerName = $options['provider'] ?? null;

        if (!$providerName && $userId > 0) {
            $ttsModelId = $this->modelConfig->getDefaultModel('TEXT2SOUND', $userId);
            if ($ttsModelId) {
                $providerName = $this->modelConfig->getProviderForModel($ttsModelId);
                if (!isset($options['model'])) {
                    $options['model'] = $this->modelConfig->getModelName($ttsModelId);
                }
            }
        }

        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'text_to_speech');
        }

        $provider = $this->registry->getTextToSpeechProvider($providerName);

        $text = TtsTextSanitizer::prepareForSynthesis($text);
        if ('' === $text) {
            throw new NoSpeakableTextException();
        }

        $this->logger->info('AI TTS stream request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'language' => $language,
            'text_length' => \strlen($text),
            'supports_streaming' => $provider->supportsStreaming(),
        ]);

        $call = new AiCall(
            capability: AiCapability::SynthesizeStream,
            provider: $provider->getName(),
            model: $this->reportedModel($provider, $options, 'text_to_speech'),
            context: new AiCallContext(userId: $userId, source: AiCallSource::Media),
            payload: AiCallPayload::speechText($text),
            options: AiCallOptions::fromArray($options + ['skip_model_health' => true], stream: true),
        );
        $charCount = mb_strlen($text);
        $streamedGenerator = $this->pipeline()->streamGenerator(
            $call,
            static function (AiCall $c) use ($provider): \Generator {
                return $provider->synthesizeStream((string) $c->payload->prompt, array_diff_key($c->options->providerOptions, ['skip_model_health' => true]));
            },
            static fn (): AiUsage => new AiUsage(characterCount: $charCount),
        );

        return [
            'generator' => $streamedGenerator,
            'contentType' => $provider->getStreamContentType($options),
            'provider' => $provider->getName(),
            'supportsStreaming' => $provider->supportsStreaming(),
        ];
    }

    private function moveToUserPath(string $filename, ?int $userId, string $providerName): string
    {
        $sourcePath = $this->uploadDir.'/'.$filename;

        if (!$userId) {
            $this->logger->warning('AiFacade: No userId for TTS file, keeping in root', [
                'filename' => $filename,
                'provider' => $providerName,
            ]);

            return $filename;
        }

        $year = date('Y');
        $month = date('m');
        $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);
        $relativePath = $userBase.'/'.$year.'/'.$month.'/'.$filename;
        $targetPath = $this->uploadDir.'/'.$relativePath;

        if (!FileHelper::ensureParentDirectory($targetPath)) {
            $this->logger->error('AiFacade: Failed to create user directory for TTS', [
                'dir' => \dirname($targetPath),
                'filename' => $filename,
            ]);

            return $filename;
        }

        if (!rename($sourcePath, $targetPath)) {
            $this->logger->error('AiFacade: Failed to move TTS file to user path', [
                'source' => $sourcePath,
                'target' => $targetPath,
            ]);

            return $filename;
        }

        FileHelper::setFilePermissions($targetPath);

        $this->logger->info('AiFacade: Moved TTS file to user path', [
            'filename' => $filename,
            'relativePath' => $relativePath,
            'userId' => $userId,
        ]);

        return $relativePath;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (\is_int($value) && $value > 0) {
            return $value;
        }
        if (\is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
