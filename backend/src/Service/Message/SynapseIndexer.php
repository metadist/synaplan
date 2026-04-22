<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\AI\Service\AiFacade;
use App\Repository\PromptRepository;
use App\Service\ModelConfigService;
use App\Service\VectorSearch\QdrantClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Synapse Indexer — manages topic embeddings in the synapse_topics Qdrant collection.
 *
 * Embeds each topic's shortDescription and stores the resulting vector so
 * SynapseRouter can classify messages via vector similarity instead of
 * an expensive LLM sorting call.
 */
final readonly class SynapseIndexer
{
    private const VECTOR_DIMENSION = 1024;

    public function __construct(
        private QdrantClientInterface $qdrantClient,
        private AiFacade $aiFacade,
        private PromptRepository $promptRepository,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Index all non-tool topics for a given owner (0 = system).
     * When $userId is provided, also indexes that user's custom topics.
     *
     * @return int Number of topics indexed
     */
    public function indexAllTopics(?int $userId = null): int
    {
        $topicsWithDesc = $this->promptRepository->getTopicsWithDescriptions(0, '', $userId, excludeTools: true);

        if (empty($topicsWithDesc)) {
            $this->logger->warning('SynapseIndexer: No topics found to index');

            return 0;
        }

        $indexed = 0;
        foreach ($topicsWithDesc as $item) {
            $topic = $item['topic'];
            $description = $item['description'] ?? '';
            $ownerId = $item['ownerId'] ?? 0;

            if ('' === $description) {
                $this->logger->debug('SynapseIndexer: Skipping topic without description', ['topic' => $topic]);
                continue;
            }

            try {
                if ($this->indexTopicWithData($topic, $ownerId, $description)) {
                    ++$indexed;
                }
            } catch (\Throwable $e) {
                $this->logger->error('SynapseIndexer: Failed to index topic', [
                    'topic' => $topic,
                    'owner_id' => $ownerId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('SynapseIndexer: Indexing complete', [
            'indexed' => $indexed,
            'total_topics' => count($topicsWithDesc),
            'user_id' => $userId,
        ]);

        return $indexed;
    }

    /**
     * Index or re-index a single topic by loading it from the database.
     */
    public function indexTopic(string $topic, int $ownerId): void
    {
        $prompt = $this->promptRepository->findByTopic($topic, $ownerId);

        if (!$prompt) {
            $this->logger->warning('SynapseIndexer: Topic not found in DB', [
                'topic' => $topic,
                'owner_id' => $ownerId,
            ]);

            return;
        }

        $description = $prompt->getShortDescription();
        if ('' === $description) {
            $this->logger->debug('SynapseIndexer: Topic has no description, skipping', ['topic' => $topic]);

            return;
        }

        $this->indexTopicWithData($topic, $ownerId, $description);
    }

    /**
     * Remove a topic's embedding from the synapse collection.
     */
    public function removeTopic(string $topic, int $ownerId): void
    {
        $pointId = $this->buildPointId($topic, $ownerId);

        try {
            $this->qdrantClient->upsertSynapseTopic($pointId, [], []);
        } catch (\Throwable) {
            // Point may not exist — that's fine
        }

        // Delete by filter is more reliable
        $this->qdrantClient->deleteSynapseTopicsByOwner($ownerId);

        $this->logger->info('SynapseIndexer: Topic removed', [
            'topic' => $topic,
            'owner_id' => $ownerId,
        ]);
    }

    /**
     * Re-index all topics for a specific user (deletes old ones first).
     */
    public function reindexForUser(int $userId): int
    {
        $this->qdrantClient->deleteSynapseTopicsByOwner($userId);

        $topicsWithDesc = $this->promptRepository->getTopicsWithDescriptions(0, '', $userId, excludeTools: true);

        $indexed = 0;
        foreach ($topicsWithDesc as $item) {
            $ownerId = $item['ownerId'] ?? 0;
            if ($ownerId !== $userId) {
                continue;
            }

            $description = $item['description'] ?? '';
            if ('' === $description) {
                continue;
            }

            try {
                if ($this->indexTopicWithData($item['topic'], $ownerId, $description)) {
                    ++$indexed;
                }
            } catch (\Throwable $e) {
                $this->logger->error('SynapseIndexer: Failed to re-index user topic', [
                    'topic' => $item['topic'],
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $indexed;
    }

    /**
     * Get the embedding provider/model info used for indexing (for status display).
     *
     * @return array{provider: ?string, model: ?string}
     */
    public function getEmbeddingModelInfo(): array
    {
        $modelId = $this->modelConfigService->getDefaultModel('VECTORIZE', null);
        if (!$modelId) {
            return ['provider' => null, 'model' => null];
        }

        return [
            'provider' => $this->modelConfigService->getProviderForModel($modelId),
            'model' => $this->modelConfigService->getModelName($modelId),
        ];
    }

    /**
     * @return bool True if the topic was successfully indexed
     */
    private function indexTopicWithData(string $topic, int $ownerId, string $description): bool
    {
        $embeddingOptions = $this->getEmbeddingOptions();
        $result = $this->aiFacade->embed($description, null, $embeddingOptions);
        /** @var float[] $vector */
        $vector = $result['embedding'];

        if (empty($vector)) {
            $this->logger->warning('SynapseIndexer: Empty embedding returned', ['topic' => $topic]);

            return false;
        }

        $vector = $this->normalizeVector($vector);

        $pointId = $this->buildPointId($topic, $ownerId);

        $this->qdrantClient->upsertSynapseTopic($pointId, $vector, [
            'owner_id' => $ownerId,
            'topic' => $topic,
            'short_description' => $description,
        ]);

        $this->logger->debug('SynapseIndexer: Topic indexed', [
            'topic' => $topic,
            'owner_id' => $ownerId,
            'vector_dim' => count($vector),
        ]);

        return true;
    }

    /**
     * Build the embedding options from the globally configured VECTORIZE model.
     *
     * @return array{provider?: string, model?: string}
     */
    private function getEmbeddingOptions(): array
    {
        $modelId = $this->modelConfigService->getDefaultModel('VECTORIZE', null);
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
        }

        return $options;
    }

    /**
     * @param float[] $vector
     *
     * @return float[]
     */
    private function normalizeVector(array $vector): array
    {
        $len = count($vector);
        if (self::VECTOR_DIMENSION === $len) {
            return $vector;
        }

        if ($len > self::VECTOR_DIMENSION) {
            return array_slice($vector, 0, self::VECTOR_DIMENSION);
        }

        return array_pad($vector, self::VECTOR_DIMENSION, 0.0);
    }

    private function buildPointId(string $topic, int $ownerId): string
    {
        return "synapse_{$ownerId}_{$topic}";
    }
}
