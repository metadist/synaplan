<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

use App\Service\Research\Eval\AnswerFile;

/**
 * @phpstan-import-type SavedAnswer from AnswerFile
 */
final readonly class SpikeBatchResult
{
    /**
     * @param list<SavedAnswer> $answers
     */
    public function __construct(
        public string $report,
        public array $answers,
    ) {
    }
}
