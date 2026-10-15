<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\RagDocument;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 190)]
final readonly class KnowledgeEraser implements UserDataEraserInterface
{
    public function __construct(
        private VectorStorageFacade $vectorStorage,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $this->vectorStorage->deleteAllForUser($erasure->userId);
    }

    public static function covers(): array
    {
        return [RagDocument::class];
    }
}
