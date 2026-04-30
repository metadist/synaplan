<?php

declare(strict_types=1);

namespace App\Service\Embedding;

use App\Service\ModelConfigService;

/**
 * EmbeddingMetadataService — single source of truth for the *currently
 * active* VECTORIZE model and stale-detection across all embedding
 * consumers (Synapse Routing, Document RAG, User Memories).
 *
 * Three concerns it answers:
 *
 *   1. "What model are we embedding queries with right now?" — used by
 *      indexers (write path) and searchers (read path) so query and
 *      stored vectors are always cosine-comparable.
 *   2. "Is this stored hit stale?" — a hit is stale iff its
 *      `embedding_model_id` payload differs from the active model id
 *      (or its `vector_dim` differs from the active vector dim). Stale
 *      hits must be filtered out of search responses because cross-
 *      model cosine scores are physically meaningless.
 *   3. "How many stale hits exist per scope?" — used by the admin UI
 *      to surface a "Re-vectorize required" banner after a model swap.
 *
 * Backwards compatible: payloads without any embedding metadata (legacy
 * vectors stored before the SafeModelChange feature) are treated as
 * fresh. They will be lazily re-indexed when their content changes or
 * the operator triggers a forced re-index.
 */
final class EmbeddingMetadataService
{
    public const DEFAULT_VECTOR_DIM = 1024;

    /** @var array{provider: ?string, model: ?string, model_id: ?int, vector_dim: int}|null */
    private ?array $cachedCurrentModel = null;

    public function __construct(
        private readonly ModelConfigService $modelConfigService,
    ) {
    }

    /**
     * @return array{provider: ?string, model: ?string, model_id: ?int, vector_dim: int}
     */
    public function getCurrentModel(): array
    {
        if (null !== $this->cachedCurrentModel) {
            return $this->cachedCurrentModel;
        }

        $modelId = $this->modelConfigService->getDefaultModel('VECTORIZE', null);
        if (!$modelId) {
            return $this->cachedCurrentModel = [
                'provider' => null,
                'model' => null,
                'model_id' => null,
                'vector_dim' => self::DEFAULT_VECTOR_DIM,
            ];
        }

        return $this->cachedCurrentModel = [
            'provider' => $this->modelConfigService->getProviderForModel($modelId),
            'model' => $this->modelConfigService->getModelName($modelId),
            'model_id' => $modelId,
            'vector_dim' => self::DEFAULT_VECTOR_DIM,
        ];
    }

    /**
     * Drop the in-process cache. Call after a programmatic model switch
     * inside the same request (e.g. admin endpoint) so subsequent reads
     * see the new active model.
     */
    public function invalidate(): void
    {
        $this->cachedCurrentModel = null;
    }

    public function getCurrentModelId(): ?int
    {
        return $this->getCurrentModel()['model_id'];
    }

    public function getCurrentVectorDim(): int
    {
        return $this->getCurrentModel()['vector_dim'];
    }

    /**
     * A hit is stale when its payload was indexed with a different
     * embedding model than the currently active one. Cross-model cosine
     * similarity is physically meaningless, so stale hits must be
     * filtered before returning search results.
     *
     * Treats legacy hits (no `embedding_model_id` AND no `vector_dim`
     * in payload) as fresh for backwards compatibility — they'll be
     * re-indexed lazily when content changes or the operator forces a
     * re-index.
     *
     * @param array<string, mixed> $payload
     */
    public function isStale(array $payload, ?int $currentModelId = null): bool
    {
        $modelId = $currentModelId ?? $this->getCurrentModelId();
        if (null === $modelId) {
            return false;
        }

        $indexedModelId = $payload['embedding_model_id'] ?? null;
        $indexedVectorDim = $payload['vector_dim'] ?? null;

        // Legacy: no metadata at all → assume fresh
        if (null === $indexedModelId && null === $indexedVectorDim) {
            return false;
        }

        if (null !== $indexedModelId && (int) $indexedModelId !== $modelId) {
            return true;
        }

        if (null !== $indexedVectorDim && (int) $indexedVectorDim !== $this->getCurrentVectorDim()) {
            return true;
        }

        return false;
    }

    /**
     * Partition hits into fresh + stale buckets.
     *
     * @template T of array<string, mixed>
     *
     * @param list<T> $hits
     *
     * @return array{fresh: list<T>, stale_count: int}
     */
    public function filterStaleHits(array $hits, string $payloadKey = 'payload', ?int $currentModelId = null): array
    {
        $modelId = $currentModelId ?? $this->getCurrentModelId();
        $fresh = [];
        $stale = 0;

        foreach ($hits as $hit) {
            /** @var array<string, mixed> $payload */
            $payload = $hit[$payloadKey] ?? [];
            if ($this->isStale($payload, $modelId)) {
                ++$stale;
                continue;
            }
            $fresh[] = $hit;
        }

        return ['fresh' => $fresh, 'stale_count' => $stale];
    }
}
