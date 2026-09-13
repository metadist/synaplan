<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

use App\Entity\SavedTask;

/**
 * A copy of a Saved Task plus the setup the new owner still has to do.
 *
 * @phpstan-type ChecklistRow array{code: string, itemKey: string, detail: string|null}
 */
final readonly class SavedTaskCopyResult
{
    /**
     * @param list<ChecklistRow> $checklist
     */
    public function __construct(
        public SavedTask $task,
        public array $checklist = [],
    ) {
    }
}
