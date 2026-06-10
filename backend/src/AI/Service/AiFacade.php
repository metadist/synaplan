<?php

namespace App\AI\Service;

use App\AI\Exception\ProviderException;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Provider\GoogleProvider;
use App\Service\CircuitBreaker;
use App\Service\DiscordNotificationService;
use App\Service\File\FileHelper;
use App\Service\File\UserUploadPathBuilder;
use App\Service\InternalEmailService;
use App\Service\ModelConfigService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class AiFacade
{
    /**
     * 7 days. The vector for a given (text, provider, model, options) tuple is
     * deterministic, so once a successful primary-path embedding is cached we
     * can reuse it across requests AND across web nodes (cache.app is Redis).
     * Bump the EMBEDDING_SHARED_CACHE_KEY_PREFIX version when the on-disk
     * shape of the returned array changes, so old entries are skipped instead
     * of mis-deserialised.
     */
    private const EMBEDDING_SHARED_CACHE_TTL_SECONDS = 604800;

    private const EMBEDDING_SHARED_CACHE_KEY_PREFIX = 'embed.v1.';

    /** @var array<string, array{embedding: array<float>, usage: array{prompt_tokens: int, total_tokens: int}}> */
    private array $embedCache = [];

    public function __construct(
        private ProviderRegistry $registry,
        private ModelConfigService $modelConfig,
        private CircuitBreaker $circuitBreaker,
        private LoggerInterface $logger,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private DiscordNotificationService $discordNotification,
        private InternalEmailService $emailService,
        private CacheInterface $cache,
        // Same physical pool as `$cache` (cache.app → Redis in prod, array in
        // tests). The Contracts interface is great for the cache-aside helper
        // used by embed(), but embedBatch() needs the PSR-6 surface so it can
        // distinguish hits from misses WITHOUT triggering one provider call
        // per missing text. Both interfaces target the same Redis keys.
        private CacheItemPoolInterface $cachePool,
        private string $uploadDir = '/var/www/backend/var/uploads',
        private string $embeddingFallbackProvider = '',
    ) {
    }

    /**
     * Sanitize UTF-8 in messages to prevent encoding errors.
     */
    private function sanitizeMessages(array $messages): array
    {
        return array_map(function ($message) {
            if (is_array($message)) {
                foreach ($message as $key => $value) {
                    if (is_string($value)) {
                        // Remove invalid UTF-8 characters
                        $message[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                        // Remove null bytes and other problematic characters
                        $message[$key] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $message[$key]);
                    } elseif (is_array($value)) {
                        // Handle nested arrays (e.g., for vision messages with image_url)
                        $message[$key] = $this->sanitizeNestedArray($value);
                    }
                }
            }

            return $message;
        }, $messages);
    }

    /**
     * Recursively sanitize nested arrays.
     */
    private function sanitizeNestedArray(array $arr): array
    {
        foreach ($arr as $key => $value) {
            if (is_string($value)) {
                $arr[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                $arr[$key] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $arr[$key]);
            } elseif (is_array($value)) {
                $arr[$key] = $this->sanitizeNestedArray($value);
            }
        }

        return $arr;
    }

    /**
     * Chat: Messages-Array or simple prompt.
     *
     * @param array|string $messages Messages array or simple string prompt
     * @param int|null     $userId   User ID for config lookup
     * @param array        $options  Additional options (provider, model, temperature, etc.)
     *
     * @return array Response mit content, provider, model, usage
     */
    public function chat(array|string $messages, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;

        // Fall back to user configuration when no provider is explicitly given
        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'chat');
        }

        $provider = $this->registry->getChatProvider($providerName);

        // String zu Messages konvertieren
        if (is_string($messages)) {
            $messages = [['role' => 'user', 'content' => $messages]];
        }

        // Sanitize UTF-8 to prevent encoding errors
        $messages = $this->sanitizeMessages($messages);

        $this->logger->info('AI chat request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'messages_count' => count($messages),
        ]);

        // Execute with Circuit Breaker protection
        try {
            $response = $this->circuitBreaker->execute(
                callback: fn () => $provider->chat($messages, $options),
                serviceName: 'ai_provider_'.$provider->getName(),
                fallback: null // NO FALLBACK - let ProviderException bubble up
            );
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('AI chat failed', [
                'error' => $e->getMessage(),
            ]);
            throw new ProviderException('AI provider failed', 'unknown', null, 0, $e);
        }

        return [
            'content' => $response['content'] ?? '',
            'provider' => $provider->getName(),
            'model' => $options['model'] ?? $provider->getDefaultModels()['chat'] ?? 'unknown',
            'usage' => $response['usage'] ?? [],
            'response_id' => $response['response_id'] ?? null,
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

        // If no provider specified, use user configuration
        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'chat');
        }

        $provider = $this->registry->getChatProvider($providerName);

        // Convert string to messages format
        if (is_string($messages)) {
            $messages = [['role' => 'user', 'content' => $messages]];
        }

        // Sanitize UTF-8 to prevent encoding errors
        $messages = $this->sanitizeMessages($messages);

        $this->logger->info('🔵 AiFacade: Starting chat stream', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'messages_count' => count($messages),
            'model' => $options['model'] ?? 'default',
        ]);

        // Execute streaming with Circuit Breaker protection
        $streamResult = null;
        try {
            $this->circuitBreaker->execute(
                callback: function () use ($provider, $messages, $streamCallback, $options, &$streamResult) {
                    $this->logger->info('🟢 AiFacade: Calling provider chatStream');
                    $streamResult = $provider->chatStream($messages, $streamCallback, $options);
                    $this->logger->info('🔵 AiFacade: Provider chatStream completed');

                    return null;
                },
                serviceName: 'ai_provider_'.$provider->getName(),
                fallback: null // NO FALLBACK - let ProviderException bubble up
            );
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('🔴 AiFacade: Chat stream failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new ProviderException('AI provider failed for streaming', 'unknown', null, 0, $e);
        }

        return [
            'provider' => $provider->getName(),
            'model' => $options['model'] ?? $provider->getDefaultModels()['chat'] ?? 'unknown',
            'usage' => $streamResult['usage'] ?? [],
            'response_id' => $streamResult['response_id'] ?? null,
        ];
    }

    /**
     * Embedding: Text → Vector.
     *
     * @param string   $text    Text to embed
     * @param int|null $userId  User ID for config lookup
     * @param array    $options Additional options (provider, model, etc.)
     *
     * @return array{embedding: array<float>, usage: array{prompt_tokens: int, total_tokens: int}}
     */
    public function embed(string $text, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;
        $model = $options['model'] ?? null;

        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'embedding');
        }

        $provider = $this->registry->getEmbeddingProvider($providerName);
        $resolvedModel = $model ?? $provider->getDefaultModels()['embedding'] ?? 'default';

        // Two-layer cache:
        //   1. In-process (`$this->embedCache`) collapses duplicates inside the
        //      same request — useful for batch handlers that re-embed the same
        //      chunk multiple times.
        //   2. Shared (Symfony cache.app → Redis) collapses duplicates across
        //      requests AND across web nodes. The vector for a given
        //      (text, provider, model, options) tuple is deterministic, so
        //      this is a pure performance win — no provider call needed.
        $inProcessKey = $this->embeddingInProcessCacheKey($text, $provider->getName(), $resolvedModel, $options);
        if (isset($this->embedCache[$inProcessKey])) {
            $this->logger->debug('AI embedding in-process cache hit', [
                'provider' => $provider->getName(),
                'model' => $resolvedModel,
                'text_length' => strlen($text),
            ]);

            return $this->embedCache[$inProcessKey];
        }

        $sharedKey = $this->embeddingSharedCacheKey($text, $provider->getName(), $resolvedModel, $options);

        try {
            // The callback only runs on a cache MISS, so the
            // 'AI embedding request' log line below faithfully reflects when
            // we actually call out to a provider.
            return $this->embedCache[$inProcessKey] = $this->cache->get(
                $sharedKey,
                function (ItemInterface $item) use ($text, $provider, $options, $userId, $resolvedModel): array {
                    $item->expiresAfter(self::EMBEDDING_SHARED_CACHE_TTL_SECONDS);
                    $this->logger->info('AI embedding request', [
                        'provider' => $provider->getName(),
                        'user_id' => $userId,
                        'model' => $resolvedModel,
                        'text_length' => strlen($text),
                    ]);

                    return $provider->embed($text, $options);
                }
            );
        } catch (ProviderException $primaryError) {
            // Fallback path is intentionally NOT cached under the primary key:
            // a Cloudflare vector must never be returned later when the
            // primary (e.g. Ollama) is healthy again — different model spaces
            // are not interchangeable. We still fill the in-process cache so
            // the same request doesn't double-bill the fallback provider.
            $fresh = $this->tryEmbeddingFallback(
                fn ($fb, $fbOpts) => $fb->embed($text, $fbOpts),
                $provider->getName(),
                $primaryError,
                $options,
            );
            $this->embedCache[$inProcessKey] = $fresh;

            return $fresh;
        }
    }

    /**
     * Options that can change the embedding vector for the same text+model+provider.
     * Anything outside this allow-list (e.g. request-id, telemetry tags) MUST
     * NOT influence the cache key — otherwise the shared cache hit-rate
     * collapses to ~zero.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function embeddingOptionsForCacheKey(array $options): array
    {
        $slice = array_intersect_key($options, array_flip(['dimensions', 'encoding_format']));
        ksort($slice);

        return $slice;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function embeddingInProcessCacheKey(string $text, string $providerName, string $resolvedModel, array $options): string
    {
        return md5($text.'|'.$providerName.'|'.$resolvedModel.'|'.$this->embeddingOptionsJson($options));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function embeddingSharedCacheKey(string $text, string $providerName, string $resolvedModel, array $options): string
    {
        return self::EMBEDDING_SHARED_CACHE_KEY_PREFIX.md5(
            $text.'|'.$providerName.'|'.$resolvedModel.'|'.$this->embeddingOptionsJson($options)
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function embeddingOptionsJson(array $options): string
    {
        try {
            return json_encode($this->embeddingOptionsForCacheKey($options), JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Batch Embedding.
     *
     * Cache strategy mirrors {@see embed()} on a per-text basis: every input
     * text is looked up under the same `embed.v1.*` key shape, so single and
     * batch calls share one Redis namespace. Only the texts that miss the
     * cache are forwarded to the provider in a single batch call. The
     * resulting vectors are persisted under their respective keys so a later
     * `embed()` for the same text can return without a provider hop.
     *
     * Fallback path stays intentionally uncached — exactly like `embed()` —
     * to prevent vectors from one model space leaking into another model's
     * key once the primary recovers.
     *
     * @param string[]    $texts        Texts to embed
     * @param int|null    $userId       User ID for config lookup
     * @param string|null $providerName Explicit provider name
     * @param array       $options      Additional options (model, etc.) forwarded to the provider
     *
     * @return array{embeddings: array<array<float>>, usage: array{prompt_tokens: int, total_tokens: int}}
     */
    public function embedBatch(array $texts, ?int $userId = null, ?string $providerName = null, array $options = []): array
    {
        if ([] === $texts) {
            return [
                'embeddings' => [],
                'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
            ];
        }

        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'embedding');
        }

        $provider = $this->registry->getEmbeddingProvider($providerName);
        $resolvedModel = $options['model'] ?? $provider->getDefaultModels()['embedding'] ?? 'default';

        // Resolve every input slot from the in-process and shared caches
        // first. We deliberately keep the original index of each missing text
        // so the final vector list maps 1:1 to the caller-supplied order — a
        // hard requirement for VectorizationService and EmbeddingReindexService.
        $resolved = array_fill(0, count($texts), null);
        /** @var array<int, string> $missingByIndex */
        $missingByIndex = [];
        /** @var array<int, string> $sharedKeyByIndex */
        $sharedKeyByIndex = [];
        /** @var array<int, string> $inProcessKeyByIndex */
        $inProcessKeyByIndex = [];

        foreach ($texts as $i => $text) {
            $inProcessKey = $this->embeddingInProcessCacheKey($text, $provider->getName(), $resolvedModel, $options);
            $inProcessKeyByIndex[$i] = $inProcessKey;

            if (isset($this->embedCache[$inProcessKey])) {
                $resolved[$i] = $this->embedCache[$inProcessKey];
                continue;
            }

            $sharedKey = $this->embeddingSharedCacheKey($text, $provider->getName(), $resolvedModel, $options);
            $sharedKeyByIndex[$i] = $sharedKey;
            $missingByIndex[$i] = $text;
        }

        if ([] !== $sharedKeyByIndex) {
            try {
                $items = $this->cachePool->getItems(array_values(array_unique($sharedKeyByIndex)));
            } catch (\Psr\Cache\InvalidArgumentException $e) {
                // Cache lookup failure must never break embedding — fall back
                // to treating every shared-cache entry as a miss and let the
                // provider produce fresh vectors.
                $this->logger->warning('AI batch embedding shared-cache lookup failed', [
                    'error' => $e->getMessage(),
                ]);
                $items = [];
            }

            $itemsByKey = [];
            foreach ($items as $item) {
                $itemsByKey[$item->getKey()] = $item;
            }

            foreach ($sharedKeyByIndex as $i => $sharedKey) {
                $item = $itemsByKey[$sharedKey] ?? null;
                if (null !== $item && $item->isHit()) {
                    /** @var array{embedding: array<float>, usage: array{prompt_tokens: int, total_tokens: int}} $cached */
                    $cached = $item->get();
                    $resolved[$i] = $cached;
                    $this->embedCache[$inProcessKeyByIndex[$i]] = $cached;
                    unset($missingByIndex[$i]);
                }
            }
        }

        $this->logger->info('AI batch embedding request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'count' => count($texts),
            'cache_hits' => count($texts) - count($missingByIndex),
            'cache_misses' => count($missingByIndex),
        ]);

        // Whole batch served from cache → skip the provider entirely.
        if ([] === $missingByIndex) {
            return [
                'embeddings' => array_map(static fn (array $r): array => $r['embedding'], $resolved),
                'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
            ];
        }

        // Preserve insertion order so the returned embeddings line up with
        // the texts we sent to the provider (most providers preserve order
        // by index in their response array).
        $missingTexts = array_values($missingByIndex);
        $missingIndexes = array_keys($missingByIndex);

        try {
            $batch = $provider->embedBatch($missingTexts, $options);
        } catch (\Throwable $primaryError) {
            // The fallback covers the FULL original text list (not just the
            // misses). Mixing cached primary vectors with fallback vectors
            // would silently violate the model-space invariant the cache key
            // is designed to enforce.
            return $this->tryEmbeddingFallback(
                fn ($fb, $fbOpts) => $fb->embedBatch($texts, $fbOpts),
                $provider->getName(),
                $primaryError,
                $options,
            );
        }

        $providerEmbeddings = $batch['embeddings'];
        $usage = $batch['usage'];

        foreach ($missingIndexes as $position => $originalIndex) {
            $vector = $providerEmbeddings[$position] ?? [];
            $entry = [
                'embedding' => $vector,
                // Per-text usage cannot be reconstructed from a batch response,
                // so we only attach the aggregate to the first miss to keep
                // the cache shape compatible with embed()'s schema. Everything
                // else stores zeroes — the cache hit path doesn't account for
                // tokens anyway.
                'usage' => 0 === $position
                    ? ['prompt_tokens' => $usage['prompt_tokens'], 'total_tokens' => $usage['total_tokens']]
                    : ['prompt_tokens' => 0, 'total_tokens' => 0],
            ];

            $resolved[$originalIndex] = $entry;
            $this->embedCache[$inProcessKeyByIndex[$originalIndex]] = $entry;

            // Empty vectors typically signal a downstream issue (dimension
            // mismatch, transient provider hiccup, etc.). Skipping the cache
            // write keeps the next call fresh instead of pinning a bad value
            // for a week.
            if ([] === $vector) {
                continue;
            }

            try {
                $item = $this->cachePool->getItem($sharedKeyByIndex[$originalIndex]);
                $item->set($entry);
                $item->expiresAfter(self::EMBEDDING_SHARED_CACHE_TTL_SECONDS);
                $this->cachePool->save($item);
            } catch (\Psr\Cache\InvalidArgumentException $e) {
                $this->logger->warning('AI batch embedding shared-cache write failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'embeddings' => array_map(static fn (?array $r): array => null === $r ? [] : $r['embedding'], $resolved),
            'usage' => ['prompt_tokens' => $usage['prompt_tokens'], 'total_tokens' => $usage['total_tokens']],
        ];
    }

    /**
     * Try a fallback embedding provider when the primary fails.
     *
     * @template T
     *
     * @param callable(EmbeddingProviderInterface, array): T $operation
     *
     * @return T
     */
    private function tryEmbeddingFallback(callable $operation, string $primaryName, \Throwable $primaryError, array $options): mixed
    {
        $fallbackName = $this->embeddingFallbackProvider;

        if ('' === $fallbackName || $fallbackName === $primaryName) {
            throw $primaryError;
        }

        // Use `error` (not `warning`) so this shows up in the same log
        // bucket as production incidents — silent failovers were called
        // out as a risk in the embedding-stack review. The success
        // path further down logs `notice` so on-call can correlate
        // "primary failed" with "fallback succeeded" without grepping
        // two channels.
        $this->logger->error('Embedding primary provider failed; attempting fallback', [
            'primary' => $primaryName,
            'fallback' => $fallbackName,
            'error' => $primaryError->getMessage(),
            'event' => 'embedding.fallback.attempt',
        ]);

        try {
            $fallback = $this->registry->getEmbeddingProvider($fallbackName);
        } catch (\Throwable) {
            throw $primaryError;
        }

        $fallbackOptions = $options;
        $fallbackOptions['provider'] = $fallbackName;
        unset($fallbackOptions['model']);

        $result = $operation($fallback, $fallbackOptions);

        $this->logger->notice('Embedding fallback succeeded', [
            'primary' => $primaryName,
            'fallback' => $fallbackName,
            'event' => 'embedding.fallback.success',
        ]);

        $this->sendFallbackNotification($primaryName, $fallbackName, $primaryError->getMessage());

        return $result;
    }

    /**
     * Send a throttled warning notification when the embedding fallback activates.
     * Max one notification per provider pair per hour to prevent spam.
     */
    private function sendFallbackNotification(string $primaryProvider, string $fallbackProvider, string $error): void
    {
        $throttleKey = 'embedding_fallback_'.md5($primaryProvider.$fallbackProvider);

        try {
            $isNewNotification = false;
            $this->cache->get($throttleKey, function ($item) use (&$isNewNotification) {
                $item->expiresAfter(3600);
                $isNewNotification = true;

                return true;
            });

            if (!$isNewNotification) {
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Fallback throttle cache error (non-critical)', ['error' => $e->getMessage()]);
        }

        try {
            $this->discordNotification->notifyEmbeddingFallback($primaryProvider, $fallbackProvider, $error);
        } catch (\Throwable $e) {
            $this->logger->debug('Discord fallback notification failed (non-critical)', ['error' => $e->getMessage()]);
        }

        try {
            $this->emailService->sendEmbeddingFallbackWarning($primaryProvider, $fallbackProvider, $error);
        } catch (\Throwable $e) {
            $this->logger->debug('Email fallback notification failed (non-critical)', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Analyze Image with Vision AI.
     *
     * @param string   $imagePath Relative path to image from upload dir
     * @param string   $prompt    Analysis prompt
     * @param int|null $userId    User ID for config lookup
     * @param array    $options   Additional options (provider, model, etc.)
     *
     * @return array Response mit content, provider, model
     */
    public function analyzeImage(string $imagePath, string $prompt, ?int $userId = null, array $options = []): array
    {
        $providerWasExplicit = array_key_exists('provider', $options);
        $callerSuppliedModel = array_key_exists('model', $options);
        $resolvedVisionProvider = null;

        // The settings UI persists the user's vision pick to
        // BCONFIG.DEFAULTMODEL.PIC2TEXT. Honour that configured row before
        // falling through to the legacy default-vision-provider chain.
        if (!$providerWasExplicit && null !== $userId) {
            $visionDefault = $this->modelConfig->resolveVisionDefault($userId);
            $resolvedVisionProvider = $visionDefault['provider'];

            if (null !== $visionDefault['model_id']) {
                $options['provider'] = $visionDefault['provider'];
                $providerWasExplicit = true;
                if (null !== $visionDefault['model'] && !$callerSuppliedModel) {
                    $options['model'] = $visionDefault['model'];
                }
            }
        }

        $requestedProvider = $options['provider'] ?? $resolvedVisionProvider ?? $this->modelConfig->getDefaultProvider($userId, 'vision');
        // Don't default to 'test' - let real providers be tried first via fallback logic
        $normalizedRequested = $requestedProvider ? strtolower($requestedProvider) : null;

        // The model id stashed in $options is the API identifier of whichever
        // provider we just picked as primary. When we fall back to a *different*
        // provider, that string is meaningless (and often actively harmful: e.g.
        // OpenAI/Google read $options['model'] verbatim and will 400 on a Groq
        // model name). Remember whose model this is so we can strip it for any
        // non-matching candidate further down — mirrors the embedding fallback's
        // `unset($fallbackOptions['model'])` safeguard.
        $modelOwnerProvider = (isset($options['model']) && $normalizedRequested)
            ? $normalizedRequested
            : null;

        $candidates = [];

        // Prefer explicitly requested provider first (unless it is the dummy test provider)
        if ($providerWasExplicit && $requestedProvider && 'test' !== $normalizedRequested) {
            $candidates[] = [
                'name' => $requestedProvider,
                'requireCapability' => true,
            ];
        } elseif ($requestedProvider && 'test' !== $normalizedRequested) {
            $candidates[] = [
                'name' => $requestedProvider,
                'requireCapability' => true,
            ];
        }
        $this->logger->info('AI vision request - provider selection', [
            'requested_provider' => $requestedProvider,
            'user_id' => $userId,
            'image' => basename($imagePath),
            'options' => $options,
        ]);

        // Add all available real providers next (ALWAYS - even if a provider was requested)
        $fallbackRequireCapability = true;
        $fallbackProviders = $this->registry->getAvailableProviders('vision', includeTest: false, requireCapability: true);

        if (empty($fallbackProviders)) {
            $this->logger->info('AI vision fallback: no DB-enabled providers, probing available providers with API keys', [
                'requested_provider' => $requestedProvider,
                'user_id' => $userId,
            ]);

            $fallbackProviders = $this->registry->getAvailableProviders('vision', includeTest: false, requireCapability: false);
            $fallbackRequireCapability = false;
        }

        foreach ($fallbackProviders as $fallbackName) {
            if ($normalizedRequested && 0 === strcasecmp($fallbackName, $requestedProvider)) {
                continue;
            }

            $candidates[] = [
                'name' => $fallbackName,
                'requireCapability' => $fallbackRequireCapability,
            ];
        }

        // Only add TestProvider as last resort if NO real providers are available
        // TestProvider should NEVER be used in production for actual file analysis
        if (empty($candidates)) {
            $this->logger->warning('AI vision: No real providers available, falling back to TestProvider (development only)', [
                'user_id' => $userId,
            ]);
            $candidates[] = [
                'name' => 'test',
                'requireCapability' => false,
            ];
        }

        $attempted = [];
        $lastException = null;

        foreach ($candidates as $candidate) {
            $candidateName = $candidate['name'];
            $normalizedCandidate = strtolower($candidateName);

            if (in_array($normalizedCandidate, $attempted, true)) {
                continue;
            }
            $attempted[] = $normalizedCandidate;

            try {
                $provider = $this->registry->getVisionProvider($candidateName, $candidate['requireCapability']);
            } catch (ProviderException $e) {
                $this->logger->warning('AI vision provider not available', [
                    'provider' => $candidateName,
                    'require_capability' => $candidate['requireCapability'],
                    'error' => $e->getMessage(),
                ]);
                $lastException = $e;
                continue;
            }

            // Build per-candidate options: drop the provider-specific `model`
            // override whenever the candidate isn't the provider that string
            // belongs to, so each provider gets either its own model or nothing
            // (in which case it falls back to its internal default).
            $candidateOptions = $options;
            $candidateOptions['provider'] = $candidateName;
            if (null !== $modelOwnerProvider && $modelOwnerProvider !== $normalizedCandidate) {
                unset($candidateOptions['model']);
            }

            $this->logger->info('AI vision request via '.$provider->getName(), [
                'provider' => $provider->getName(),
                'user_id' => $userId,
                'image' => basename($imagePath),
                'model' => $candidateOptions['model'] ?? null,
            ]);

            try {
                $response = $this->circuitBreaker->execute(
                    callback: fn () => $provider->explainImage($imagePath, $prompt, $candidateOptions),
                    serviceName: 'ai_provider_vision_'.$provider->getName(),
                    fallback: null // NO FALLBACK
                );

                return [
                    'content' => $response,
                    'provider' => $provider->getName(),
                    'model' => $candidateOptions['model'] ?? 'unknown',
                ];
            } catch (ProviderException $e) {
                $this->logger->warning('AI vision provider failed: '.$provider->getName().' - '.$e->getMessage(), [
                    'provider' => $provider->getName(),
                    'error' => $e->getMessage(),
                ]);
                $lastException = $e;
                continue;
            } catch (\Throwable $e) {
                $this->logger->error('AI vision provider error: '.$provider->getName().' - '.$e->getMessage(), [
                    'provider' => $provider->getName(),
                    'error' => $e->getMessage(),
                ]);
                $lastException = new ProviderException(
                    'Vision provider error: '.$e->getMessage(),
                    $provider->getName(),
                    null,
                    0,
                    $e
                );
                continue;
            }
        }

        $this->logger->error('AI vision failed after exhausting providers', [
            'image' => basename($imagePath),
            'attempted' => $attempted,
        ]);

        if ($lastException) {
            throw $lastException;
        }

        throw new ProviderException('Vision AI failed', 'unknown');
    }

    /**
     * Generate Image with AI (DALL-E, etc.).
     *
     * @param string   $prompt  Image generation prompt
     * @param int|null $userId  User ID for config lookup
     * @param array    $options Additional options (provider, model, size, quality, style, etc.)
     *
     * @return array Generated images with metadata
     */
    public function generateImage(string $prompt, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;

        // Fall back to user configuration when no provider is explicitly given
        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'image_generation');
        }

        $provider = $this->registry->getImageGenerationProvider($providerName);

        $this->logger->info('AI image generation request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'prompt_length' => strlen($prompt),
        ]);

        try {
            $images = $this->circuitBreaker->execute(
                callback: fn () => $provider->generateImage($prompt, $options),
                serviceName: 'ai_provider_image_'.$provider->getName(),
                fallback: null // NO FALLBACK
            );
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
            'model' => $options['model'] ?? 'unknown',
            'image_count' => is_array($images) ? count($images) : 1,
        ];
    }

    /**
     * Generate Video with AI.
     *
     * @param string   $prompt  Video generation prompt
     * @param int|null $userId  User ID for config lookup
     * @param array    $options Additional options (provider, model, duration, resolution, etc.)
     *
     * @return array Generated videos with metadata
     */
    public function generateVideo(string $prompt, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;

        // Fall back to user configuration when no provider is explicitly given
        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'video_generation');
        }

        $provider = $this->registry->getVideoGenerationProvider($providerName);

        $this->logger->info('AI video generation request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'prompt_length' => strlen($prompt),
        ]);

        try {
            $videos = $this->circuitBreaker->execute(
                callback: fn () => $provider->generateVideo($prompt, $options),
                serviceName: 'ai_provider_video_'.$provider->getName(),
                fallback: null // NO FALLBACK
            );
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
        if (is_array($videos) && !empty($videos)) {
            $durationSeconds = $videos[0]['duration'] ?? $videos[0]['duration_seconds'] ?? null;
            $resolution = $videos[0]['resolution'] ?? null;
        }

        return [
            'videos' => $videos,
            'provider' => $provider->getName(),
            'model' => $options['model'] ?? 'unknown',
            'duration_seconds' => $durationSeconds,
            'resolution' => $resolution,
        ];
    }

    /**
     * Start an async video generation operation (non-blocking).
     *
     * @return array{operationName: string, provider: string, model: string, duration: int, resolution: string}
     */
    public function startVideoGeneration(string $prompt, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;

        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'video_generation');
        }

        $provider = $this->registry->getVideoGenerationProvider($providerName);

        if (!$provider instanceof GoogleProvider) {
            throw new ProviderException('Async video generation is only supported by Google Veo', $provider->getName());
        }

        $this->logger->info('Starting async video generation', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
        ]);

        $operationData = $provider->startVideoOperation($prompt, $options);

        return [
            ...$operationData,
            'provider' => $provider->getName(),
        ];
    }

    /**
     * Poll an async video operation once.
     *
     * @return array{done: bool, videoUri: ?string, error: ?string}
     */
    public function pollVideoOperation(string $operationName, ?string $providerName = null): array
    {
        $provider = $this->registry->getVideoGenerationProvider($providerName);

        if (!$provider instanceof GoogleProvider) {
            throw new ProviderException('Async video polling is only supported by Google Veo', $provider->getName());
        }

        return $provider->pollVideoOperationOnce($operationName);
    }

    /**
     * Download video content from a provider URI (as data URL).
     */
    public function downloadVideoContent(string $videoUri, ?string $providerName = null): string
    {
        $provider = $this->registry->getVideoGenerationProvider($providerName);

        if (!$provider instanceof GoogleProvider) {
            throw new ProviderException('Async video download is only supported by Google Veo', $provider->getName());
        }

        return $provider->downloadVideoContent($videoUri);
    }

    /**
     * Download raw video bytes from a provider URI (avoids base64 overhead).
     */
    public function downloadVideoRaw(string $videoUri, ?string $providerName = null): string
    {
        $provider = $this->registry->getVideoGenerationProvider($providerName);

        if (!$provider instanceof GoogleProvider) {
            throw new ProviderException('Async video download is only supported by Google Veo', $provider->getName());
        }

        return $provider->downloadVideoRaw($videoUri);
    }

    /**
     * Transcribe Audio (Whisper).
     *
     * @param string   $audioPath Relative path to audio file from upload dir
     * @param int|null $userId    User ID for config lookup
     * @param array    $options   Additional options (provider, model, language, etc.)
     *
     * @return array Transcription result with text, language, duration, segments
     */
    public function transcribe(string $audioPath, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;
        $callerSuppliedModel = array_key_exists('model', $options);
        $sttModelId = null;

        // The settings UI persists the user's transcription pick to
        // BCONFIG.DEFAULTMODEL.SOUND2TEXT. Honour that configured row before
        // falling through to the legacy ai/default_speech_to_text_provider
        // chain — that legacy key is never written by the UI, so prior to this
        // we silently picked whichever provider the smart fallback returned
        // (often OpenAI/whisper-1 instead of the user's Groq/whisper-large-v3).
        // See issue #696.
        if (!$providerName && null !== $userId && $userId > 0) {
            $sttDefault = $this->modelConfig->resolveSttDefault($userId);
            $providerName = $sttDefault['provider'];
            $sttModelId = $sttDefault['model_id'];

            // Only forward the SOUND2TEXT model name when it actually resolved
            // to a BMODELS row — otherwise the provider would receive a stale
            // string from a different provider's catalog and 400 (mirrors the
            // PIC2TEXT safeguard in analyzeImage()).
            if (null !== $sttDefault['model'] && !$callerSuppliedModel) {
                $options['model'] = $sttDefault['model'];
            }
        }

        $provider = $this->registry->getSpeechToTextProvider($providerName);

        $this->logger->info('AI transcription request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'audio' => basename($audioPath),
            'model' => $options['model'] ?? null,
            'model_id' => $sttModelId,
        ]);

        try {
            $result = $this->circuitBreaker->execute(
                callback: fn () => $provider->transcribe($audioPath, $options),
                serviceName: 'ai_provider_stt_'.$provider->getName(),
                fallback: null // NO FALLBACK
            );
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('AI transcription failed', [
                'error' => $e->getMessage(),
            ]);
            throw new ProviderException('Transcription failed', 'unknown', null, 0, $e);
        }

        return array_merge($result, [
            'provider' => $provider->getName(),
            'model' => $options['model'] ?? 'unknown',
        ]);
    }

    /**
     * Check if the user has configured an external STT provider.
     *
     * Returns true when the user's SOUND2TEXT default model points to an external
     * provider (OpenAI, Groq, etc.) — in that case, local Whisper.cpp should
     * be skipped and the external API used directly.
     */
    public function hasConfiguredSttProvider(?int $userId): bool
    {
        if (!$userId || $userId <= 0) {
            return false;
        }

        $modelId = $this->modelConfig->getDefaultModel('SOUND2TEXT', $userId);

        return null !== $modelId && $modelId > 0;
    }

    /**
     * Synthesize Speech (TTS).
     *
     * @param string   $text    Text to synthesize
     * @param int|null $userId  User ID for config lookup
     * @param array    $options Additional options (provider, model, voice, speed, format, etc.)
     *
     * @return array Result with relativePath (user-based path) and metadata
     */
    public function synthesize(string $text, ?int $userId = null, array $options = []): array
    {
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

        $this->logger->info('AI TTS request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'text_length' => strlen($text),
        ]);

        try {
            $filename = $this->circuitBreaker->execute(
                callback: fn () => $provider->synthesize($text, $options),
                serviceName: 'ai_provider_tts_'.$provider->getName(),
                fallback: null // NO FALLBACK
            );
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
            'model' => $options['model'] ?? 'unknown',
            'model_id' => $ttsModelId ?? null,
            'text_length' => mb_strlen($text),
        ];
    }

    /**
     * Stream TTS audio via the user's configured provider.
     *
     * Resolves the provider from the user's DEFAULTMODEL/TEXT2SOUND config
     * (same source the UI "Sprachsynthese" dropdown writes to) so streaming
     * and MP3 generation always use the same voice.
     *
     * @param string   $text    Text to synthesize
     * @param int|null $userId  User ID for config lookup
     * @param array    $options Additional options (provider, model, voice, speed, format, language, etc.)
     *
     * @return array{generator: \Generator, contentType: string, provider: string, supportsStreaming: bool}
     */
    public function synthesizeStream(string $text, ?int $userId = null, array $options = []): array
    {
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

        $this->logger->info('AI TTS stream request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'text_length' => strlen($text),
            'supports_streaming' => $provider->supportsStreaming(),
        ]);

        return [
            'generator' => $provider->synthesizeStream($text, $options),
            'contentType' => $provider->getStreamContentType($options),
            'provider' => $provider->getName(),
            'supportsStreaming' => $provider->supportsStreaming(),
        ];
    }

    /**
     * Move a file from uploadDir root to user-based path structure.
     *
     * @param string   $filename     The filename in uploadDir root (e.g., tts_xxx.mp3)
     * @param int|null $userId       User ID for path generation
     * @param string   $providerName Provider name for logging
     *
     * @return string Relative path from uploadDir (e.g., 13/000/00013/2025/01/tts_xxx.mp3)
     */
    private function moveToUserPath(string $filename, ?int $userId, string $providerName): string
    {
        $sourcePath = $this->uploadDir.'/'.$filename;

        // If no userId, keep file in root (fallback)
        if (!$userId) {
            $this->logger->warning('AiFacade: No userId for TTS file, keeping in root', [
                'filename' => $filename,
                'provider' => $providerName,
            ]);

            return $filename;
        }

        // Build user-based path
        $year = date('Y');
        $month = date('m');
        $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);
        $relativePath = $userBase.'/'.$year.'/'.$month.'/'.$filename;
        $targetPath = $this->uploadDir.'/'.$relativePath;

        // Create directory if not exists
        if (!FileHelper::ensureParentDirectory($targetPath)) {
            $this->logger->error('AiFacade: Failed to create user directory for TTS', [
                'dir' => dirname($targetPath),
                'filename' => $filename,
            ]);

            // Fall back to root path
            return $filename;
        }

        // Move file and set permissions/ownership
        if (!rename($sourcePath, $targetPath)) {
            $this->logger->error('AiFacade: Failed to move TTS file to user path', [
                'source' => $sourcePath,
                'target' => $targetPath,
            ]);

            // Fall back to root path
            return $filename;
        }

        // Set proper permissions and ownership on the moved file
        FileHelper::setFilePermissions($targetPath);

        $this->logger->info('AiFacade: Moved TTS file to user path', [
            'filename' => $filename,
            'relativePath' => $relativePath,
            'userId' => $userId,
        ]);

        return $relativePath;
    }
}
