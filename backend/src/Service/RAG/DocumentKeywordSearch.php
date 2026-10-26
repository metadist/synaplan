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
 *
 * Rows have the shape of {@see VectorSearchService::semanticSearchByVector()}
 * rows, so a keyword-only hit still says whose file it is.
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
     * @param list<RagScope> $scopes the same list the vector leg searches; empty searches nothing
     *
     * @return list<array<string, mixed>>
     */
    public function find(int $userId, string $text, ?string $groupKey, array $scopes, int $limit): array
    {
        if ([] === $scopes || 'qdrant' !== $this->storage->getProviderName() || '' === trim($text) || $limit < 1) {
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
        $files = $this->filesById($points);
        $out = [];
        foreach ($points as $point) {
            $payload = $point['payload'];
            $fileId = (int) ($payload['file_id'] ?? 0);
            if ($fileId < 1) {
                continue;
            }
            $file = $files[$fileId] ?? null;
            $ownerId = (int) ($payload['user_id'] ?? $userId);
            $out[] = [
                'chunk_id' => $point['id'],
                'file_id' => $fileId,
                'message_id' => $fileId,
                'chunk_text' => (string) ($payload['text'] ?? ''),
                'start_line' => (int) ($payload['start_line'] ?? 0),
                'end_line' => (int) ($payload['end_line'] ?? 0),
                'group_key' => (string) ($payload['group_key'] ?? ''),
                'score' => $point['score'],
                'file_name' => $file instanceof File ? $file->getFileName() : 'File',
                'mime_type' => $file?->getFileMime(),
                'owner_id' => $ownerId,
                'shared' => $ownerId !== $userId,
            ];
        }

        return $out;
    }

    /**
     * @param array<int, array{id: string, score: float, payload: array<string, mixed>}> $points
     *
     * @return array<int, File>
     */
    private function filesById(array $points): array
    {
        $ids = [];
        foreach ($points as $point) {
            $id = (int) ($point['payload']['file_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ([] === $ids) {
            return [];
        }

        $byId = [];
        foreach ($this->files->findBy(['id' => array_values($ids)]) as $file) {
            $byId[(int) $file->getId()] = $file;
        }

        return $byId;
    }
}
