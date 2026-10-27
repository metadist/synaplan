<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\CloudWatch;
use App\Repository\CloudWatchRepository;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Watched folders and their handled files go with the account. Items are
 * removed first; the cluster has no ON DELETE CASCADE.
 */
#[AsTaggedItem(priority: 136)]
final readonly class CloudWatchEraser implements UserDataEraserInterface
{
    public function __construct(
        private CloudWatchRepository $watches,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $this->watches->deleteByOwner($erasure->userId);
    }

    public static function covers(): array
    {
        return [CloudWatch::class];
    }
}
