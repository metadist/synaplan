<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage;

use App\Repository\VisitorUploadRepository;
use App\Service\RAG\VectorStorage\DTO\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Keeps widget visitors' uploads out of knowledge search.
 *
 * A visitor's file belongs to one widget session. Its text reaches the model
 * through that session's own turns and is never knowledge for anyone else,
 * so a chunk of such a file is dropped from every search result, whichever
 * store returned it and whenever it was indexed.
 */
final readonly class VisitorUploadFilter
{
    public function __construct(
        private VisitorUploadRepository $visitorUploads,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param SearchResult[] $results
     *
     * @return SearchResult[]
     */
    public function withoutVisitorUploads(array $results): array
    {
        $fileIds = [];
        foreach ($results as $result) {
            if ($result->fileId > 0) {
                $fileIds[$result->fileId] = true;
            }
        }

        $blocked = $this->visitorUploads->idsAmong(array_keys($fileIds));
        if ([] === $blocked) {
            return $results;
        }

        $this->logger->warning('Knowledge search dropped chunks of widget visitor uploads', [
            'file_ids' => $blocked,
        ]);

        return array_values(array_filter(
            $results,
            static fn (SearchResult $result): bool => !\in_array($result->fileId, $blocked, true),
        ));
    }
}
