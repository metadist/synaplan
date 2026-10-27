<?php

declare(strict_types=1);

namespace App\AI\Service;

use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Pipeline\AiCall;
use App\AI\Pipeline\AiCallContext;
use App\AI\Pipeline\AiCallOptions;
use App\AI\Pipeline\AiCallPayload;
use App\AI\Pipeline\AiCallPipeline;
use App\AI\Pipeline\AiCallSource;
use App\AI\Pipeline\AiCapability;
use App\Service\Email\InternalEmailService;
use App\Service\ModelConfigService;
use App\Service\Notification\DiscordNotificationService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Single and batch text embeddings behind {@see AiFacade}: two-layer cache
 * (in-process + shared Redis), pipeline execution per provider call, and
 * primary → fallback provider failover with throttled notifications.
 */
final class AiEmbeddingExecutor
{
    /**
     * 7 days. The vector for a given (text, provider, model, options) tuple is
     * deterministic, so once a successful primary-path embedding is cached we
     * can reuse it across requests AND across web nodes (cache.app is Redis).
     */
    private const EMBEDDING_SHARED_CACHE_TTL_SECONDS = 604800;

    private const EMBEDDING_SHARED_CACHE_KEY_PREFIX = 'embed.v1.';

    /** @var array<string, array{embedding: array<float>, usage: array{prompt_tokens: int, total_tokens: int}}> */
    private array $embedCache = [];

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ModelConfigService $modelConfig,
        private readonly AiCallPipeline $pipeline,
        private readonly CacheInterface $cache,
        private readonly CacheItemPoolInterface $cachePool,
        private readonly DiscordNotificationService $discordNotification,
        private readonly InternalEmailService $emailService,
        private readonly LoggerInterface $logger,
        private readonly string $embeddingFallbackProvider = '',
    ) {
    }

    /**
     * @param array<string, mixed> $options
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

        $inProcessKey = $this->embeddingInProcessCacheKey($text, $provider->getName(), $resolvedModel, $options);
        if (isset($this->embedCache[$inProcessKey])) {
            $this->logger->debug('AI embedding in-process cache hit', [
                'provider' => $provider->getName(),
                'model' => $resolvedModel,
                'text_length' => \strlen($text),
            ]);

            return $this->embedCache[$inProcessKey];
        }

        $sharedKey = $this->embeddingSharedCacheKey($text, $provider->getName(), $resolvedModel, $options);

        try {
            return $this->embedCache[$inProcessKey] = $this->cache->get(
                $sharedKey,
                function (ItemInterface $item) use ($text, $provider, $options, $userId, $resolvedModel): array {
                    $item->expiresAfter(self::EMBEDDING_SHARED_CACHE_TTL_SECONDS);
                    $this->logger->info('AI embedding request', [
                        'provider' => $provider->getName(),
                        'user_id' => $userId,
                        'model' => $resolvedModel,
                        'text_length' => \strlen($text),
                    ]);

                    $call = new AiCall(
                        capability: AiCapability::Embed,
                        provider: $provider->getName(),
                        model: (string) $resolvedModel,
                        context: new AiCallContext(
                            userId: $userId,
                            source: 0 === $userId ? AiCallSource::Probe : AiCallSource::Vectorize,
                            billable: null !== $userId && $userId > 0,
                        ),
                        payload: AiCallPayload::embed($text),
                        options: AiCallOptions::fromArray($options),
                    );

                    /* @var array{embedding: array<float>, usage: array{prompt_tokens: int, total_tokens: int}} */
                    return $this->pipeline->run(
                        $call,
                        static fn (AiCall $c): array => $provider->embed($c->payload->texts[0] ?? $text, $c->options->providerOptions),
                    )->value;
                }
            );
        } catch (\Throwable $primaryError) {
            $fresh = $this->tryEmbeddingFallback(
                static fn (EmbeddingProviderInterface $fb, array $fbOpts): array => $fb->embed($text, $fbOpts),
                $provider->getName(),
                $primaryError,
                $options,
            );
            $this->embedCache[$inProcessKey] = $fresh;

            return $fresh;
        }
    }

    /**
     * @param string[]             $texts
     * @param array<string, mixed> $options
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
        $resolvedModel = (string) ($options['model'] ?? $provider->getDefaultModels()['embedding'] ?? 'default');

        [$resolved, $missingByIndex, $sharedKeyByIndex, $inProcessKeyByIndex] = $this->resolveBatchCache(
            $texts,
            $provider->getName(),
            $resolvedModel,
            $options,
        );

        $this->logger->info('AI batch embedding request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'count' => \count($texts),
            'cache_hits' => \count($texts) - \count($missingByIndex),
            'cache_misses' => \count($missingByIndex),
        ]);

        if ([] === $missingByIndex) {
            return [
                'embeddings' => array_map(static fn (array $r): array => $r['embedding'], $resolved),
                'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
            ];
        }

        $missingTexts = array_values($missingByIndex);

        try {
            $call = new AiCall(
                capability: AiCapability::EmbedBatch,
                provider: $provider->getName(),
                model: $resolvedModel,
                context: new AiCallContext(
                    userId: $userId,
                    source: 0 === $userId ? AiCallSource::Probe : AiCallSource::Vectorize,
                    billable: null !== $userId && $userId > 0,
                ),
                payload: AiCallPayload::embedBatch($missingTexts),
                options: AiCallOptions::fromArray($options),
            );

            /** @var array{embeddings: array<array<float>>, usage: array{prompt_tokens: int, total_tokens: int}} $batch */
            $batch = $this->pipeline->run(
                $call,
                static fn (AiCall $c): array => $provider->embedBatch($c->payload->texts, $c->options->providerOptions),
            )->value;
        } catch (\Throwable $primaryError) {
            return $this->tryEmbeddingFallback(
                static fn (EmbeddingProviderInterface $fb, array $fbOpts): array => $fb->embedBatch($texts, $fbOpts),
                $provider->getName(),
                $primaryError,
                $options,
            );
        }

        return $this->storeBatchCache(
            $batch,
            $resolved,
            array_keys($missingByIndex),
            $sharedKeyByIndex,
            $inProcessKeyByIndex,
        );
    }

    /**
     * @param string[]             $texts
     * @param array<string, mixed> $options
     *
     * @return array{0: array<int, ?array{embedding: array<float>, usage: array{prompt_tokens: int, total_tokens: int}}>, 1: array<int, string>, 2: array<int, string>, 3: array<int, string>}
     */
    private function resolveBatchCache(array $texts, string $providerName, string $resolvedModel, array $options): array
    {
        $resolved = array_fill(0, \count($texts), null);
        $missingByIndex = [];
        $sharedKeyByIndex = [];
        $inProcessKeyByIndex = [];

        foreach ($texts as $i => $text) {
            $inProcessKey = $this->embeddingInProcessCacheKey($text, $providerName, $resolvedModel, $options);
            $inProcessKeyByIndex[$i] = $inProcessKey;

            if (isset($this->embedCache[$inProcessKey])) {
                $resolved[$i] = $this->embedCache[$inProcessKey];
                continue;
            }

            $sharedKey = $this->embeddingSharedCacheKey($text, $providerName, $resolvedModel, $options);
            $sharedKeyByIndex[$i] = $sharedKey;
            $missingByIndex[$i] = $text;
        }

        if ([] === $sharedKeyByIndex) {
            return [$resolved, $missingByIndex, $sharedKeyByIndex, $inProcessKeyByIndex];
        }

        try {
            $items = $this->cachePool->getItems(array_values(array_unique($sharedKeyByIndex)));
        } catch (\Psr\Cache\InvalidArgumentException $e) {
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

        return [$resolved, $missingByIndex, $sharedKeyByIndex, $inProcessKeyByIndex];
    }

    /**
     * @param array{embeddings: array<array<float>>, usage: array{prompt_tokens: int, total_tokens: int}}      $batch
     * @param array<int, ?array{embedding: array<float>, usage: array{prompt_tokens: int, total_tokens: int}}> $resolved
     * @param list<int>                                                                                        $missingIndexes
     * @param array<int, string>                                                                               $sharedKeyByIndex
     * @param array<int, string>                                                                               $inProcessKeyByIndex
     *
     * @return array{embeddings: array<array<float>>, usage: array{prompt_tokens: int, total_tokens: int}}
     */
    private function storeBatchCache(
        array $batch,
        array $resolved,
        array $missingIndexes,
        array $sharedKeyByIndex,
        array $inProcessKeyByIndex,
    ): array {
        $providerEmbeddings = $batch['embeddings'];
        $usage = $batch['usage'];

        foreach ($missingIndexes as $position => $originalIndex) {
            $vector = $providerEmbeddings[$position] ?? [];
            $entry = [
                'embedding' => $vector,
                'usage' => 0 === $position
                    ? ['prompt_tokens' => $usage['prompt_tokens'], 'total_tokens' => $usage['total_tokens']]
                    : ['prompt_tokens' => 0, 'total_tokens' => 0],
            ];

            $resolved[$originalIndex] = $entry;
            $this->embedCache[$inProcessKeyByIndex[$originalIndex]] = $entry;

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
     * @template T
     *
     * @param callable(EmbeddingProviderInterface, array<string, mixed>): T $operation
     * @param array<string, mixed>                                          $options
     *
     * @return T
     */
    private function tryEmbeddingFallback(callable $operation, string $primaryName, \Throwable $primaryError, array $options): mixed
    {
        $fallbackName = $this->embeddingFallbackProvider;

        if ('' === $fallbackName || $fallbackName === $primaryName) {
            throw $primaryError;
        }

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

    private function sendFallbackNotification(string $primaryProvider, string $fallbackProvider, string $error): void
    {
        $throttleKey = 'embedding_fallback_'.md5($primaryProvider.$fallbackProvider);

        try {
            $isNewNotification = false;
            $this->cache->get($throttleKey, static function (ItemInterface $item) use (&$isNewNotification): bool {
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
            return json_encode($this->embeddingOptionsForCacheKey($options), \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return '';
        }
    }
}
