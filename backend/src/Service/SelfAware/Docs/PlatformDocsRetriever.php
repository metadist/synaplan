<?php

declare(strict_types=1);

namespace App\Service\SelfAware\Docs;

use App\Service\RAG\VectorSearchService;
use App\Service\SelfAware\SelfAwareConfig;

/**
 * Retrieves official documentation chunks for the `synaplan` topic.
 *
 * Queries owner 0 / SYSTEM:synaplan so the embedding model matches the
 * corpus (Decision 4). Unknown file ids are dropped — never guessed.
 */
final readonly class PlatformDocsRetriever
{
    public const DEFAULT_LIMIT = 5;
    public const DEFAULT_MIN_SCORE = 0.35;
    public const MAX_PAGES = 4;

    public function __construct(
        private SelfAwareConfig $config,
        private PlatformDocsSyncState $state,
        private VectorSearchService $vectorSearch,
    ) {
    }

    public function retrieve(
        string $query,
        int $userId,
        int $limit = self::DEFAULT_LIMIT,
        float $minScore = self::DEFAULT_MIN_SCORE,
    ): PlatformDocsHits {
        if (!$this->config->isDocsRagEnabled($userId > 0 ? $userId : null)) {
            return new PlatformDocsHits([]);
        }
        if (!$this->state->hasPages()) {
            return new PlatformDocsHits([]);
        }

        $results = $this->vectorSearch->semanticSearch(
            $query,
            PlatformDocsSyncService::OWNER_ID,
            PlatformDocsSyncService::GROUP_KEY,
            $limit,
            $minScore,
        );

        $bestBySlug = [];
        foreach ($results as $row) {
            $fileId = (int) ($row['file_id'] ?? 0);
            $page = $this->state->pageByFileId($fileId);
            if (null === $page) {
                continue;
            }
            $slug = $page['slug'];
            $score = (float) ($row['score'] ?? $row['distance'] ?? 0.0);
            $existing = $bestBySlug[$slug] ?? null;
            if (null !== $existing && $existing->score >= $score) {
                continue;
            }
            $bestBySlug[$slug] = new PlatformDocsHit(
                $slug,
                (string) $page['title'],
                (string) $page['url'],
                (string) $page['section'],
                trim((string) ($row['chunk_text'] ?? '')),
                $score,
            );
        }

        usort(
            $bestBySlug,
            static fn (PlatformDocsHit $a, PlatformDocsHit $b): int => $b->score <=> $a->score,
        );

        return new PlatformDocsHits(array_slice($bestBySlug, 0, self::MAX_PAGES));
    }
}
