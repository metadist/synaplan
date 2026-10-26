<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\RAG\DocumentKeywordSearch;
use App\Service\RAG\HybridKnowledgeSearch;
use App\Service\RAG\RagScopeResolver;
use App\Service\RAG\VectorSearchService;

/** File hits for one research query: meaning plus keywords, one row per file. */
final readonly class ResearchFileSearch
{
    public function __construct(
        private VectorSearchService $vectors,
        private DocumentKeywordSearch $keywords,
        private HybridKnowledgeSearch $hybrid,
        private RagScopeResolver $scopes,
    ) {
    }

    /**
     * @return list<array{file_id: int, title: string}>
     */
    public function find(int $userId, string $query, int $limit): array
    {
        $limit = max(1, $limit);
        $meaning = $this->vectors->semanticSearch($query, $userId, null, $limit, 0.3);
        $keyword = $this->keywords->find($userId, $query, null, $this->scopes->resolve($userId, null), $limit);
        $seen = [];
        $out = [];
        foreach ($this->hybrid->merge($meaning, $keyword, $limit, $query) as $hit) {
            $fileId = (int) ($hit['file_id'] ?? 0);
            if ($fileId < 1 || isset($seen[$fileId])) {
                continue;
            }
            $seen[$fileId] = true;
            $title = $hit['file_name'] ?? 'File';
            $out[] = ['file_id' => $fileId, 'title' => is_string($title) && '' !== $title ? $title : 'File'];
        }

        return $out;
    }
}
