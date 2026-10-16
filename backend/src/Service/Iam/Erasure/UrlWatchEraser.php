<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\UrlWatch;
use App\Repository\UrlWatchRepository;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The pages the user watches, with the fetched text and the last diff.
 */
#[AsTaggedItem(priority: 135)]
final readonly class UrlWatchEraser implements UserDataEraserInterface
{
    public function __construct(
        private UrlWatchRepository $watches,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $this->watches->deleteByOwner($erasure->userId);
    }

    public static function covers(): array
    {
        return [UrlWatch::class];
    }
}
