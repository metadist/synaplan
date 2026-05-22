<?php

declare(strict_types=1);

namespace App\Service\Memory;

use App\Repository\ConfigRepository;
use App\Service\ModelConfigService;
use App\Service\VectorSearch\QdrantClientInterface;
use Psr\Log\LoggerInterface;

/**
 * MemoryEmbeddingModelResolver — answers ONE question: "which embedding
 * model owns the user-memories Qdrant collection right now?".
 *
 * Background (PR #985 follow-up):
 *
 *   The VECTORIZE default model can be swapped via the admin UI. For
 *   documents/synapse that triggers a full re-index against the new
 *   model. For *memories* it does NOT, because dropping the collection
 *   would destroy user-curated long-term context (see #985). The
 *   memories collection therefore keeps its original vector dimension
 *   indefinitely.
 *
 *   That leaves an asymmetry: the active VECTORIZE model can produce
 *   1536-dim vectors while the existing memories collection is 3072.
 *   Without this resolver, both write paths (storeInQdrant) and read
 *   paths (searchRelevantMemories) would embed against VECTORIZE and
 *   then either get rejected by the dimension safety net or returned
 *   results filtered to zero by the stale-hit filter — making it
 *   impossible to save OR search memories after a switch.
 *
 * Resolution strategy (in priority order):
 *
 *   1. **Sticky pointer in BCONFIG** — `MEMORIES.EMBEDDING_MODEL_ID`
 *      (owner=0, group=MEMORIES, setting=EMBEDDING_MODEL_ID).
 *      Set by us the first time we embed a memory and updated by the
 *      memories re-index pipeline when (and only when) the operator
 *      explicitly migrates the collection.
 *   2. **Payload inference** — if the sticky pointer is missing but
 *      the collection already has points, copy the `embedding_model_id`
 *      from the most recent payload and persist it. Covers upgrade
 *      scenarios where the BCONFIG row was never written.
 *   3. **Active VECTORIZE fallback** — fresh installs with an empty
 *      memories collection. The resolver writes the BCONFIG pointer
 *      so subsequent calls take path 1.
 *
 * After resolution the produced model's `vector_dim` is verified
 * against the actual collection size from `getMemoriesCollectionInfo()`.
 * If they mismatch (e.g. operator dropped the collection manually) the
 * sticky pointer is cleared and the resolver re-runs — invalid sticky
 * state can never lock the system out permanently.
 */
final class MemoryEmbeddingModelResolver
{
    public const CONFIG_GROUP = 'MEMORIES';
    public const CONFIG_SETTING = 'EMBEDDING_MODEL_ID';

    /** @var array{provider: ?string, model: ?string, model_id: ?int, vector_dim: ?int}|null */
    private ?array $cached = null;

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly ModelConfigService $modelConfigService,
        private readonly QdrantClientInterface $qdrantClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve the model that the memories collection is currently
     * indexed under. Returns null fields when nothing has ever been
     * stored AND no VECTORIZE default is configured (a fresh install
     * with embeddings disabled) — callers must tolerate that.
     *
     * @return array{provider: ?string, model: ?string, model_id: ?int, vector_dim: ?int}
     */
    public function resolve(): array
    {
        if (null !== $this->cached) {
            return $this->cached;
        }

        $collectionDim = $this->getCollectionDimension();

        $modelId = $this->readStickyPointer();
        if (null !== $modelId) {
            $info = $this->buildInfo($modelId);
            if ($this->isCompatible($info, $collectionDim)) {
                return $this->cached = $info;
            }

            // Sticky pointer disagrees with the collection (manual
            // re-create, manual drop, dimension drift). Drop it so we
            // re-derive from payloads or the active VECTORIZE default.
            $this->logger->warning('MemoryEmbeddingModelResolver: stale sticky pointer, clearing', [
                'sticky_model_id' => $modelId,
                'sticky_dim' => $info['vector_dim'],
                'collection_dim' => $collectionDim,
            ]);
            $this->clearStickyPointer();
        }

        $payloadModelId = $this->inferFromPayloads();
        if (null !== $payloadModelId) {
            $info = $this->buildInfo($payloadModelId);
            if ($this->isCompatible($info, $collectionDim)) {
                $this->writeStickyPointer($payloadModelId);
                $this->logger->info('MemoryEmbeddingModelResolver: pinned memories model from payload', [
                    'model_id' => $payloadModelId,
                    'collection_dim' => $collectionDim,
                ]);

                return $this->cached = $info;
            }
        }

        // No sticky pointer and no inferrable history → fall back to the
        // currently active VECTORIZE model. This is the only branch that
        // can legitimately stamp a brand-new collection.
        $vectorizeId = $this->modelConfigService->getDefaultModel('VECTORIZE', null);
        if (null !== $vectorizeId) {
            $info = $this->buildInfo($vectorizeId);
            if (null === $collectionDim || $this->isCompatible($info, $collectionDim)) {
                $this->writeStickyPointer($vectorizeId);
                $this->logger->info('MemoryEmbeddingModelResolver: pinned memories model from VECTORIZE', [
                    'model_id' => $vectorizeId,
                    'collection_dim' => $collectionDim,
                ]);

                return $this->cached = $info;
            }
        }

        // Last resort: nothing matches. Surface the collection dim so
        // callers can still log a precise dimension-mismatch error.
        return $this->cached = [
            'provider' => null,
            'model' => null,
            'model_id' => null,
            'vector_dim' => $collectionDim,
        ];
    }

    public function getModelId(): ?int
    {
        return $this->resolve()['model_id'];
    }

    public function getModelName(): ?string
    {
        return $this->resolve()['model'];
    }

    public function getProvider(): ?string
    {
        return $this->resolve()['provider'];
    }

    public function getVectorDim(): ?int
    {
        return $this->resolve()['vector_dim'];
    }

    /**
     * Persist a new sticky pointer after a successful memories-collection
     * recreate. Called by `EmbeddingReindexService::reindexMemories()`
     * once every memory has been re-embedded under the new model — only
     * then is it safe to migrate the pointer.
     */
    public function rememberModel(int $modelId): void
    {
        $this->writeStickyPointer($modelId);
        $this->invalidate();
    }

    /**
     * Drop the in-process cache. Call after a programmatic config
     * change inside the same request so subsequent resolves see the
     * new sticky pointer.
     */
    public function invalidate(): void
    {
        $this->cached = null;
    }

    /**
     * @return array{provider: ?string, model: ?string, model_id: ?int, vector_dim: ?int}
     */
    private function buildInfo(int $modelId): array
    {
        return [
            'provider' => $this->modelConfigService->getProviderForModel($modelId),
            'model' => $this->modelConfigService->getModelName($modelId),
            'model_id' => $modelId,
            'vector_dim' => $this->modelConfigService->getVectorDimForModel($modelId),
        ];
    }

    /**
     * @param array{provider: ?string, model: ?string, model_id: ?int, vector_dim: ?int} $info
     */
    private function isCompatible(array $info, ?int $collectionDim): bool
    {
        if (null === $collectionDim) {
            return true;
        }
        if (null === $info['vector_dim']) {
            // Catalog row has no declared dimension — we cannot prove
            // compatibility. Treating it as compatible here would let
            // an upsert run anyway and trigger Qdrant's "wrong vector
            // size" error at the safer storage layer. Reject instead.
            return false;
        }

        return $info['vector_dim'] === $collectionDim;
    }

    private function readStickyPointer(): ?int
    {
        $raw = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::CONFIG_SETTING);
        if (null === $raw || '' === trim($raw)) {
            return null;
        }
        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }

    private function writeStickyPointer(int $modelId): void
    {
        $this->configRepository->setValue(0, self::CONFIG_GROUP, self::CONFIG_SETTING, (string) $modelId);
    }

    private function clearStickyPointer(): void
    {
        $existing = $this->configRepository->findByOwnerGroupAndSetting(0, self::CONFIG_GROUP, self::CONFIG_SETTING);
        if (null === $existing) {
            return;
        }
        // Store an empty marker rather than physically deleting — keeps
        // the row pinned at owner=0 for later re-use and matches how
        // every other BCONFIG row signals "unset".
        $this->configRepository->setValue(0, self::CONFIG_GROUP, self::CONFIG_SETTING, '');
    }

    private function getCollectionDimension(): ?int
    {
        try {
            $info = $this->qdrantClient->getMemoriesCollectionInfo();
        } catch (\Throwable $e) {
            $this->logger->warning('MemoryEmbeddingModelResolver: failed to read collection info', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $info['vector_dim'] ?? null;
    }

    private function inferFromPayloads(): ?int
    {
        try {
            $points = $this->qdrantClient->scrollAllMemoriesForReindex(50);
        } catch (\Throwable $e) {
            $this->logger->warning('MemoryEmbeddingModelResolver: scroll failed during inference', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        foreach ($points as $point) {
            $payload = $point['payload'] ?? [];
            $modelId = $payload['embedding_model_id'] ?? null;
            if (is_int($modelId) && $modelId > 0) {
                return $modelId;
            }
            if (is_string($modelId) && '' !== $modelId && ctype_digit($modelId)) {
                return (int) $modelId;
            }
        }

        return null;
    }
}
