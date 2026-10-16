<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index;

use App\Entity\RevectorizeRun;
use App\Repository\SearchIndexRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moves every index row onto the search embedding model after an admin
 * switch. Rows keep their keyword match the whole time; each owner's rows
 * gain meaning matches again as soon as their batch is done.
 */
final readonly class SearchIndexReembedder
{
    public function __construct(
        private SearchIndexRepository $repository,
        private SearchIndexEmbedder $embedder,
        private SearchEmbeddingModel $embeddingModel,
        private EntityManagerInterface $em,
    ) {
    }

    public function execute(RevectorizeRun $run): void
    {
        $modelId = $this->embeddingModel->modelId();
        if (null === $modelId) {
            throw new \RuntimeException('The search index has no embedding model to move onto.');
        }

        foreach ($this->repository->pendingCountsByUser($modelId) as $userId => $pending) {
            $result = $this->embedder->embedPendingCounted($userId, $pending);
            $run->incrementChunksProcessed($result['embedded']);
            $run->incrementChunksFailed($result['failed']);
            $this->em->flush();
        }
    }
}
