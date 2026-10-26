<?php

declare(strict_types=1);

namespace App\Service\RAG;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\RAG\VectorStorage\DTO\RagScope;
use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\VectorSearch\QdrantClientInterface;

/**
 * Keyword leg for document chunks. MariaDB has no full-text index, so that
 * store returns nothing here and the meaning list is left as it is.
 */
final readonly class DocumentKeywordSearch
{
    public function __construct(
        private QdrantClientInterface $client,
        private VectorStorageFacade $storage,
        private FileRepository $files,
    ) {
    }

    /**
     * @param list<RagScope> $scopes the same list the vector leg searches
     *
     * @return list<array<string, mixed>>
     */
    public function find(int $userId, string $text, ?string $groupKey, array $scopes, int $limit): array
    {
        if ('qdrant' !== $this->storage->getProviderName() || '' === trim($text) || $limit < 1) {
            return [];
        }

        $query = new SearchQuery(
            userId: $userId,
            vector: [],
            groupKey: $groupKey,
            limit: $limit,
            minScore: 0.0,
            scopes: $scopes,
        );
        $points = $this->client->searchDocumentKeywords($text, $userId, $groupKey, $limit, $query);
        $out = [];
        foreach ($points as $point) {
            $fileId = (int) ($point['payload']['file_id'] ?? 0);
            if ($fileId < 1) {
                continue;
            }
            $file = $this->files->find($fileId);
            $out[] = [
                'chunk_id' => $point['id'],
                'file_id' => $fileId,
                'chunk_text' => (string) ($point['payload']['text'] ?? ''),
                'file_name' => $file instanceof File ? $file->getFileName() : 'File',
                'score' => $point['score'],
            ];
        }

        return $out;
    }
}
