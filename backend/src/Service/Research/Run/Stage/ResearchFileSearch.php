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
     * @param list<string> $groupKeys empty searches the caller's own files and folders they can use
     *
     * @return list<array{file_id: int, title: string}>
     */
    public function find(int $userId, string $query, int $limit, array $groupKeys = []): array
    {
        $limit = max(1, $limit);
        $keys = $this->keys($groupKeys);
        if ([] === $keys) {
            return $this->once($userId, $query, $limit, null);
        }
        $seen = [];
        $out = [];
        foreach ($keys as $key) {
            foreach ($this->once($userId, $query, $limit, $key) as $row) {
                if (isset($seen[$row['file_id']])) {
                    continue;
                }
                $seen[$row['file_id']] = true;
                $out[] = $row;
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<string> $groupKeys
     *
     * @return list<string>
     */
    private function keys(array $groupKeys): array
    {
        $keys = [];
        foreach ($groupKeys as $key) {
            $key = trim($key);
            if ('' !== $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @return list<array{file_id: int, title: string}>
     */
    private function once(int $userId, string $query, int $limit, ?string $groupKey): array
    {
        $scopes = $this->scopesFor($userId, $groupKey);
        if ([] === $scopes) {
            return [];
        }
        $meaning = $this->vectors->semanticSearch($query, $userId, $groupKey, $limit, 0.3, $scopes);
        $keyword = $this->keywords->find($userId, $query, $groupKey, $scopes, $limit);
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

    /**
     * A shared folder the caller cannot use is skipped. Searching it through
     * the normal resolver would fall back to every file the caller owns.
     *
     * @return list<\App\Service\RAG\VectorStorage\DTO\RagScope>
     */
    private function scopesFor(int $userId, ?string $groupKey): array
    {
        $picker = RagScopeResolver::parseSharedPicker($groupKey);
        if (null !== $picker && !$this->scopes->canUse($userId, $picker[0], $picker[1])) {
            return [];
        }

        return $this->scopes->resolve($userId, $groupKey);
    }
}
