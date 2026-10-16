<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\Approval;
use App\Repository\ApprovalRepository;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The approvals the user owns, with their tool arguments and previews. An
 * approval the user decided on someone else's resource stays: its decider
 * column is that owner's audit record and holds only an id.
 */
#[AsTaggedItem(priority: 145)]
final readonly class ApprovalEraser implements UserDataEraserInterface
{
    public function __construct(
        private ApprovalRepository $approvals,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $this->approvals->deleteByOwner($erasure->userId);
    }

    public static function covers(): array
    {
        return [Approval::class];
    }
}
