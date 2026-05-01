<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\AI\Service\AiFacade;
use App\Entity\Prompt;
use App\Repository\PromptRepository;
use App\Service\ModelConfigService;
use App\Service\VectorSearch\QdrantClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Synapse Indexer — manages topic embeddings in the synapse_topics Qdrant collection.
 *
 * Embeds each topic's description (plus optional keywords) and stores the
 * resulting vector together with rich payload metadata (model id/provider/name,
 * vector dim, source hash, indexed timestamp) so SynapseRouter can:
 *
 *   1. classify messages via vector similarity instead of an expensive LLM call;
 *   2. detect stale entries when the embedding model is swapped at runtime
 *      (different model_id) or its dimensions change (different vector_dim).
 *
 * The indexer is idempotent: when neither keywords/description nor the embedding
 * model changed since the last run, a content-addressable `source_hash` short-
 * circuits the embedding call. Use `force: true` to bypass the hash check.
 */
final readonly class SynapseIndexer
{
    private const DEFAULT_VECTOR_DIMENSION = 1024;

    /**
     * Synapse Routing reads its embedding-model binding from this
     * dedicated capability key — separate from VECTORIZE so admins can
     * pin Routing to the highest-quality model for short multilingual
     * prompt classification (Qwen3 by default) while every user keeps
     * their own VECTORIZE choice for files / memories.
     *
     * Stored in BCONFIG with ownerId=0; managed by AdminEmbedding
     * endpoints; SynapseRouter reads the same key so indexer and
     * search side stay in lockstep.
     */
    public const SYNAPSE_CAPABILITY = 'SYNAPSE_VECTORIZE';

    public function __construct(
        private QdrantClientInterface $qdrantClient,
        private AiFacade $aiFacade,
        private PromptRepository $promptRepository,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Index all enabled, non-tool topics for a given owner (0 = system).
     * When $userId is provided, also indexes that user's custom topics.
     *
     * @return array{indexed: int, skipped: int, errors: int}
     */
    public function indexAllTopics(?int $userId = null, bool $force = false): array
    {
        $prompts = $this->loadIndexablePrompts($userId);

        if (empty($prompts)) {
            $this->logger->warning('SynapseIndexer: No topics found to index');

            return ['indexed' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $indexed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($prompts as $prompt) {
            try {
                $result = $this->indexPrompt($prompt, $force);
                if ('indexed' === $result) {
                    ++$indexed;
                } elseif ('skipped' === $result) {
                    ++$skipped;
                }
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error('SynapseIndexer: Failed to index topic', [
                    'topic' => $prompt->getTopic(),
                    'owner_id' => $prompt->getOwnerId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('SynapseIndexer: Indexing complete', [
            'indexed' => $indexed,
            'skipped' => $skipped,
            'errors' => $errors,
            'total_topics' => count($prompts),
            'user_id' => $userId,
            'force' => $force,
        ]);

        return ['indexed' => $indexed, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Index or re-index a single topic by loading it from the database.
     *
     * @return string 'indexed', 'skipped' or 'missing'
     */
    public function indexTopic(string $topic, int $ownerId, bool $force = false): string
    {
        $prompt = $this->promptRepository->findByTopic($topic, $ownerId);

        if (!$prompt) {
            $this->logger->warning('SynapseIndexer: Topic not found in DB', [
                'topic' => $topic,
                'owner_id' => $ownerId,
            ]);

            return 'missing';
        }

        if (!$prompt->isEnabled()) {
            $this->logger->debug('SynapseIndexer: Topic is disabled, removing from index', [
                'topic' => $topic,
                'owner_id' => $ownerId,
            ]);
            $this->removeTopic($topic, $ownerId);

            return 'skipped';
        }

        if (str_starts_with($prompt->getTopic(), 'tools:')) {
            return 'skipped';
        }

        // Same guard as loadIndexablePrompts: a topic without any
        // description AND without keywords carries no signal for the
        // embedding model — skip it instead of upserting noise.
        if (
            '' === trim($prompt->getShortDescription())
            && '' === trim((string) $prompt->getKeywords())
        ) {
            return 'skipped';
        }

        return $this->indexPrompt($prompt, $force);
    }

    /**
     * Remove a single topic's embedding from the synapse collection.
     */
    public function removeTopic(string $topic, int $ownerId): void
    {
        $pointId = $this->buildPointId($topic, $ownerId);

        $this->qdrantClient->deleteSynapseTopic($pointId);

        $this->logger->info('SynapseIndexer: Topic removed', [
            'topic' => $topic,
            'owner_id' => $ownerId,
            'point_id' => $pointId,
        ]);
    }

    /**
     * Re-index all topics for a specific user (deletes old ones first).
     *
     * @return array{indexed: int, skipped: int, errors: int}
     */
    public function reindexForUser(int $userId, bool $force = false): array
    {
        $this->qdrantClient->deleteSynapseTopicsByOwner($userId);

        $userPrompts = $this->collectUserOwnedPrompts($userId);

        $indexed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($userPrompts as $prompt) {
            try {
                $result = $this->indexPrompt($prompt, $force);
                if ('indexed' === $result) {
                    ++$indexed;
                } elseif ('skipped' === $result) {
                    ++$skipped;
                }
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error('SynapseIndexer: Failed to re-index user topic', [
                    'topic' => $prompt->getTopic(),
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['indexed' => $indexed, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Lazy "self-healing" reindex used by the post-login auto-index
     * hook: walks every owned prompt and calls indexPrompt() with
     * force=false, so the per-topic source-hash check skips anything
     * that is already up-to-date with the current SYNAPSE_VECTORIZE
     * model. Cheap when nothing changed (zero embed calls), correct
     * when the user edited a prompt or the active model changed.
     *
     * Unlike reindexForUser() this does NOT pre-delete; that would
     * defeat the source-hash skip path and burn API tokens on every
     * single login.
     *
     * @return array{indexed: int, skipped: int, errors: int}
     */
    public function ensureUserTopicsFresh(int $userId): array
    {
        $userPrompts = $this->collectUserOwnedPrompts($userId);

        $indexed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($userPrompts as $prompt) {
            try {
                $result = $this->indexPrompt($prompt, false);
                if ('indexed' === $result) {
                    ++$indexed;
                } elseif ('skipped' === $result) {
                    ++$skipped;
                }
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->warning('SynapseIndexer: ensureUserTopicsFresh failed for topic', [
                    'topic' => $prompt->getTopic(),
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['indexed' => $indexed, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Get the embedding provider/model info used for indexing (for status display).
     *
     * @return array{provider: ?string, model: ?string, model_id: ?int, vector_dim: int}
     */
    public function getEmbeddingModelInfo(): array
    {
        $modelId = $this->resolveSynapseModelId();
        if (!$modelId) {
            return [
                'provider' => null,
                'model' => null,
                'model_id' => null,
                'vector_dim' => self::DEFAULT_VECTOR_DIMENSION,
            ];
        }

        return [
            'provider' => $this->modelConfigService->getProviderForModel($modelId),
            'model' => $this->modelConfigService->getModelName($modelId),
            'model_id' => $modelId,
            'vector_dim' => self::DEFAULT_VECTOR_DIMENSION,
        ];
    }

    /**
     * Resolve the BMODELS row id for the Synapse Routing embedding
     * model.
     *
     * Reads the global SYNAPSE_VECTORIZE binding (admin-managed) and
     * silently falls back to the VECTORIZE default when no Synapse-
     * specific binding has been seeded yet — keeps fresh deployments
     * working until the seeder runs.
     */
    private function resolveSynapseModelId(): ?int
    {
        $synapseId = $this->modelConfigService->getDefaultModel(self::SYNAPSE_CAPABILITY, null);
        if ($synapseId) {
            return $synapseId;
        }

        $this->logger->warning('SynapseIndexer: SYNAPSE_VECTORIZE binding missing, falling back to VECTORIZE default');

        return $this->modelConfigService->getDefaultModel('VECTORIZE', null);
    }

    /**
     * Build the canonical text that gets embedded for a topic.
     *
     * Combines topic name, short description and (optional) keywords into a
     * single multi-line string. Exposed publicly so the admin UI can render
     * a "this is what gets embedded" preview.
     */
    public function buildEmbeddingText(Prompt $prompt): string
    {
        $parts = [
            sprintf('Topic: %s', $prompt->getTopic()),
        ];

        $description = trim($prompt->getShortDescription());
        if ('' !== $description) {
            $parts[] = sprintf('Description: %s', $description);
        }

        $keywords = trim((string) $prompt->getKeywords());
        if ('' !== $keywords) {
            $parts[] = sprintf('Keywords: %s', $keywords);
        }

        return implode("\n", $parts);
    }

    /**
     * Compute the deterministic source hash that drives skip-when-unchanged.
     *
     * Includes the embedding text plus the active model id and target dim so
     * any change to the topic content OR the embedding stack invalidates the
     * cached vector. Returned as an URL-safe SHA-256 hex digest.
     */
    public function computeSourceHash(string $embeddingText, ?int $modelId, int $vectorDim): string
    {
        return hash('sha256', sprintf(
            'v2|model=%s|dim=%d|text=%s',
            (string) ($modelId ?? '0'),
            $vectorDim,
            $embeddingText,
        ));
    }

    // ──────────────────────────────────────────────
    //  Internal helpers
    // ──────────────────────────────────────────────

    /**
     * Restrict loadIndexablePrompts() to prompts the user actually
     * owns. Avoids re-indexing the global system prompts every time a
     * user logs in — those are the responsibility of the global index
     * and an admin-triggered reindex, not the per-user auto path.
     *
     * @return list<Prompt>
     */
    private function collectUserOwnedPrompts(int $userId): array
    {
        return array_values(array_filter(
            $this->loadIndexablePrompts($userId),
            fn (Prompt $p) => $p->getOwnerId() === $userId,
        ));
    }

    /**
     * @return list<Prompt>
     */
    private function loadIndexablePrompts(?int $userId): array
    {
        $prompts = $this->promptRepository->findAllForUser($userId ?? 0);

        $indexable = [];
        foreach ($prompts as $prompt) {
            if (!$prompt->isEnabled()) {
                continue;
            }
            if (str_starts_with($prompt->getTopic(), 'tools:')) {
                continue;
            }
            if ('' === trim($prompt->getShortDescription()) && '' === trim((string) $prompt->getKeywords())) {
                continue;
            }
            $indexable[] = $prompt;
        }

        return $indexable;
    }

    /**
     * @return string 'indexed' on a successful upsert, 'skipped' when the source
     *                hash matches and `force` is false
     */
    private function indexPrompt(Prompt $prompt, bool $force): string
    {
        $modelInfo = $this->getEmbeddingModelInfo();
        $modelId = $modelInfo['model_id'];
        $provider = $modelInfo['provider'];
        $modelName = $modelInfo['model'];
        $vectorDim = $modelInfo['vector_dim'];

        $embeddingText = $this->buildEmbeddingText($prompt);
        if ('' === trim($embeddingText)) {
            return 'skipped';
        }

        $sourceHash = $this->computeSourceHash($embeddingText, $modelId, $vectorDim);
        $pointId = $this->buildPointId($prompt->getTopic(), $prompt->getOwnerId());

        if (!$force) {
            $existing = $this->qdrantClient->getSynapseTopic($pointId);
            $existingHash = $existing['payload']['source_hash'] ?? null;
            if (null !== $existingHash && $existingHash === $sourceHash) {
                $this->logger->debug('SynapseIndexer: Topic unchanged, skipping', [
                    'topic' => $prompt->getTopic(),
                    'owner_id' => $prompt->getOwnerId(),
                    'source_hash' => $sourceHash,
                ]);

                return 'skipped';
            }
        }

        $embeddingOptions = $this->getEmbeddingOptions();
        $result = $this->aiFacade->embed($embeddingText, null, $embeddingOptions);
        /** @var float[] $vector */
        $vector = $result['embedding'];

        if (empty($vector)) {
            $this->logger->warning('SynapseIndexer: Empty embedding returned', [
                'topic' => $prompt->getTopic(),
            ]);

            throw new \RuntimeException(sprintf('Empty embedding returned for topic "%s"', $prompt->getTopic()));
        }

        $vector = $this->normalizeVector($vector, $vectorDim);

        $this->qdrantClient->upsertSynapseTopic($pointId, $vector, [
            'owner_id' => $prompt->getOwnerId(),
            'topic' => $prompt->getTopic(),
            'short_description' => $prompt->getShortDescription(),
            'keywords' => $prompt->getKeywords(),
            'embedding_model_id' => $modelId,
            'embedding_provider' => $provider,
            'embedding_model' => $modelName,
            'vector_dim' => $vectorDim,
            'source_hash' => $sourceHash,
            'indexed_at' => date(\DATE_ATOM),
        ]);

        $this->logger->debug('SynapseIndexer: Topic indexed', [
            'topic' => $prompt->getTopic(),
            'owner_id' => $prompt->getOwnerId(),
            'vector_dim' => count($vector),
            'model_id' => $modelId,
            'provider' => $provider,
            'model' => $modelName,
            'force' => $force,
        ]);

        return 'indexed';
    }

    private const QWEN3_INDEX_INSTRUCTION = 'Represent this topic description for retrieval';

    /**
     * Build the embedding options for the pinned Synapse model.
     *
     * Synapse Routing always uses the model identified by
     * SYNAPSE_MODEL_NAME / SYNAPSE_MODEL_SERVICE, regardless of the
     * VECTORIZE default. When the model is unavailable we fall back
     * to the VECTORIZE default so indexing still produces something
     * usable instead of crashing.
     *
     * @return array{provider?: string, model?: string, instruction?: string}
     */
    private function getEmbeddingOptions(): array
    {
        $modelId = $this->resolveSynapseModelId();
        if (!$modelId) {
            return [];
        }

        $options = [];
        $provider = $this->modelConfigService->getProviderForModel($modelId);
        $model = $this->modelConfigService->getModelName($modelId);

        if ($provider) {
            $options['provider'] = $provider;
        }
        if ($model) {
            $options['model'] = $model;

            if (str_contains(strtolower($model), 'qwen')) {
                $options['instruction'] = self::QWEN3_INDEX_INSTRUCTION;
            }
        }

        return $options;
    }

    /**
     * @param float[] $vector
     *
     * @return float[]
     */
    private function normalizeVector(array $vector, int $targetDim): array
    {
        $len = count($vector);
        if ($targetDim === $len) {
            return $vector;
        }

        if ($len > $targetDim) {
            return array_slice($vector, 0, $targetDim);
        }

        return array_pad($vector, $targetDim, 0.0);
    }

    private function buildPointId(string $topic, int $ownerId): string
    {
        return "synapse_{$ownerId}_{$topic}";
    }
}
