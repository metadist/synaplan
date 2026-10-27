<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\NotionActivity;
use App\Entity\NotionWatch;
use App\Repository\NotionActivityRepository;
use App\Repository\NotionWatchRepository;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The Notion pages the user watches and the list of pages Synaplan changed.
 * The pages themselves live in Notion and are not touched.
 */
#[AsTaggedItem(priority: 134)]
final readonly class NotionEraser implements UserDataEraserInterface
{
    public function __construct(
        private NotionWatchRepository $watches,
        private NotionActivityRepository $activity,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $this->watches->deleteByUser($erasure->userId);
        $this->activity->deleteByUser($erasure->userId);
    }

    public static function covers(): array
    {
        return [NotionWatch::class, NotionActivity::class];
    }
}
