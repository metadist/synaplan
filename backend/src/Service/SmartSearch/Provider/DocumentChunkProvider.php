<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Provider;

use App\Service\RAG\VectorSearchService;
use App\Service\SmartSearch\Index\SearchIndexer;
use App\Service\SmartSearch\SearchHit;
use App\Service\SmartSearch\SearchProviderInterface;
use App\Service\SmartSearch\SearchRequest;
use App\Service\SmartSearch\SnippetBuilder;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Meaning tier inside file contents: the RAG chunks of the user's own files
 * and of knowledge folders shared with them. One hit per file, with the best
 * passage as the snippet; resolve() re-checks access for every file.
 */
#[AsTaggedItem(priority: 70)]
final readonly class DocumentChunkProvider implements SearchProviderInterface
{
    private const KIND = 'file';
    private const MIN_SCORE = 0.4;
    /** Several chunks usually come from the same file. */
    private const CHUNK_OVERFETCH = 3;

    public function __construct(
        private VectorSearchService $vectorSearch,
        private SearchIndexer $indexer,
    ) {
    }

    public function name(): string
    {
        return 'documents';
    }

    public function search(SearchRequest $request): array
    {
        $userId = $request->userId();
        $source = $this->indexer->source(self::KIND);
        if (!$request->wants(self::KIND) || null === $source || !$source->isEnabledFor($userId)) {
            return [];
        }
        $vector = $request->vectors->forDocuments();
        if (null === $vector) {
            return [];
        }

        $chunks = $this->vectorSearch->semanticSearchByVector(
            $userId,
            $vector,
            limit: $request->limit * self::CHUNK_OVERFETCH,
            minScore: $request->vectors->documentsMinScore(self::MIN_SCORE),
        );

        $best = [];
        foreach ($chunks as $chunk) {
            $fileId = (string) ($chunk['file_id'] ?? '');
            if ('' === $fileId) {
                continue;
            }
            if (!isset($best[$fileId]) || $chunk['score'] > $best[$fileId]['score']) {
                $best[$fileId] = $chunk;
            }
        }
        if ([] === $best) {
            return [];
        }

        $resolved = $source->resolve($userId, array_map('strval', array_keys($best)));
        $hits = [];
        foreach ($best as $fileId => $chunk) {
            $fileId = (string) $fileId;
            $item = $resolved[$fileId] ?? null;
            if (null === $item) {
                continue;
            }
            $hits[] = new SearchHit(
                kind: self::KIND,
                refId: $fileId,
                title: '' !== $item->title ? $item->title : (string) ($chunk['file_name'] ?? $fileId),
                route: $item->route,
                matchedBy: SearchHit::MATCHED_SEMANTIC,
                subtitle: $item->subtitle,
                snippet: SnippetBuilder::build((string) ($chunk['chunk_text'] ?? ''), $request->fulltext->terms),
                score: (float) $chunk['score'],
                sharedBy: $item->sharedBy,
            );
        }

        usort($hits, static fn (SearchHit $a, SearchHit $b): int => $b->score <=> $a->score);

        return array_slice($hits, 0, $request->limit);
    }
}
