<?php

declare(strict_types=1);

namespace App\Service\SmartSearch;

use App\Repository\SearchIndexRepository;
use App\Service\ModelConfigService;
use App\Service\SmartSearch\Index\SearchEmbeddingModel;
use App\Service\SmartSearch\Index\SettingsCatalog;
use App\Service\UserMemoryService;
use Psr\Log\LoggerInterface;

/**
 * The query embeddings of one search, computed on first use and shared by
 * every semantic provider. Stores that live in the same model space reuse
 * one vector, so a typical search pays for a single embed call.
 */
final class QueryVectors
{
    /**
     * A hit must beat the query's similarity to arbitrary text (its mean
     * similarity to the settings catalog) by this much. Relative, because
     * every embedding model has its own similarity range.
     */
    public const MARGIN_ABOVE_NOISE = 0.17;
    /** Absolute floor, and the cutoff when there is no catalog to compare with. */
    public const MIN_SCORE = 0.3;
    public const FALLBACK_MIN_SCORE = 0.45;

    /** @var array<string, array{modelId: int, vector: list<float>}|null> */
    private array $resolved = [];
    private ?float $indexMinScore = null;

    public function __construct(
        private readonly int $userId,
        private readonly string $query,
        private readonly SearchEmbeddingModel $searchModel,
        private readonly ModelConfigService $modelConfig,
        private readonly UserMemoryService $memories,
        private readonly SearchIndexRepository $index,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Similarity an index hit needs for this query (see MARGIN_ABOVE_NOISE).
     */
    public function indexMinScore(): float
    {
        if (null !== $this->indexMinScore) {
            return $this->indexMinScore;
        }
        $query = $this->forIndex();
        $noise = null === $query
            ? null
            : $this->index->averageSimilarity(SettingsCatalog::CATALOG_USER_ID, $query['vector'], $query['modelId']);

        return $this->indexMinScore = null === $noise
            ? self::FALLBACK_MIN_SCORE
            : max(self::MIN_SCORE, $noise + self::MARGIN_ABOVE_NOISE);
    }

    /**
     * Vector for the search index (user rows and the shared catalog).
     *
     * @return array{modelId: int, vector: list<float>}|null
     */
    public function forIndex(): ?array
    {
        $raw = $this->rawIndexVector();

        return null === $raw ? null : ['modelId' => $raw['modelId'], 'vector' => SearchEmbeddingModel::fit($raw['vector'])];
    }

    /**
     * Vector for the user's RAG documents (the member's VECTORIZE model).
     *
     * @return list<float>|null
     */
    public function forDocuments(): ?array
    {
        return $this->shared('documents', $this->documentsModelId(), fn (): ?array => $this->memories->embedUserQuery($this->userId, $this->query));
    }

    /**
     * Vector for memories and message digests (the memory-pinned model).
     *
     * @return list<float>|null
     */
    public function forMemories(): ?array
    {
        return $this->shared('memories', $this->memories->getMemoryEmbeddingModelId(), fn (): ?array => $this->memories->embedQueryForMemorySearch($this->userId, $this->query));
    }

    /** Adaptive cutoff when documents share the index space, else `$default`. */
    public function documentsMinScore(float $default): float
    {
        return $this->minScoreInSpaceOf($this->documentsModelId(), $default);
    }

    /** Adaptive cutoff when memories share the index space, else `$default`. */
    public function memoriesMinScore(float $default): float
    {
        return $this->minScoreInSpaceOf($this->memories->getMemoryEmbeddingModelId(), $default);
    }

    private function documentsModelId(): ?int
    {
        return $this->modelConfig->getDefaultModel('VECTORIZE', $this->userId);
    }

    private function minScoreInSpaceOf(?int $modelId, float $default): float
    {
        $raw = $this->rawIndexVector();

        return null !== $raw && null !== $modelId && $raw['modelId'] === $modelId ? $this->indexMinScore() : $default;
    }

    /** False once the index vector was asked for and could not be made. */
    public function indexAvailable(): bool
    {
        return !array_key_exists('index', $this->resolved) || null !== $this->resolved['index'];
    }

    /**
     * @param \Closure(): (array{embedding: array<int, float>}|null) $embed
     *
     * @return list<float>|null
     */
    private function shared(string $slot, ?int $modelId, \Closure $embed): ?array
    {
        $raw = $this->rawIndexVector();
        if (null !== $raw && null !== $modelId && $raw['modelId'] === $modelId) {
            return $raw['vector'];
        }

        $result = $this->resolve($slot, function () use ($embed, $modelId): ?array {
            $embedded = $embed();

            return null === $embedded ? null : ['modelId' => (int) $modelId, 'vector' => array_values($embedded['embedding'])];
        });

        return $result['vector'] ?? null;
    }

    /**
     * The search model's vector at its native width; every store fits it
     * to its own column width.
     *
     * @return array{modelId: int, vector: list<float>}|null
     */
    private function rawIndexVector(): ?array
    {
        return $this->resolve('index', function (): ?array {
            $result = $this->searchModel->embed([$this->query], $this->userId, fit: false);
            $vector = $result['vectors'][0] ?? null;

            return null === $result || null === $vector ? null : ['modelId' => $result['modelId'], 'vector' => $vector];
        });
    }

    /**
     * @param \Closure(): (array{modelId: int, vector: list<float>}|null) $factory
     *
     * @return array{modelId: int, vector: list<float>}|null
     */
    private function resolve(string $slot, \Closure $factory): ?array
    {
        if (!array_key_exists($slot, $this->resolved)) {
            try {
                $this->resolved[$slot] = $factory();
            } catch (\Throwable $e) {
                $this->logger->warning('Smart Search query embedding failed', [
                    'slot' => $slot,
                    'user_id' => $this->userId,
                    'error' => $e->getMessage(),
                ]);
                $this->resolved[$slot] = null;
            }
        }

        return $this->resolved[$slot];
    }
}
