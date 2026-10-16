<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\RagDocument;
use App\Repository\RagDocumentRepository;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The user's knowledge base. Its rows go inside the transaction (their
 * foreign key forbids deleting the user first); the copy in the active
 * vector store is purged after the commit, so a rollback never leaves the
 * account without its vectors, and an unreachable store only leaves orphans.
 */
#[AsTaggedItem(priority: 190)]
final readonly class KnowledgeEraser implements UserDataEraserInterface
{
    public function __construct(
        private RagDocumentRepository $ragDocuments,
        private VectorStorageFacade $vectorStorage,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $userId = $erasure->userId;
        $this->ragDocuments->deleteByUser($userId);
        $erasure->afterCommit(function () use ($userId): void {
            $this->vectorStorage->deleteAllForUser($userId);
        });
    }

    public static function covers(): array
    {
        return [RagDocument::class];
    }
}
