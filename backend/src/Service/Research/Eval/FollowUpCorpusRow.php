<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/**
 * A corpus row that continues a finished research run instead of starting
 * one. The parent is the corpus question that runs first; the facts are
 * scored on the follow-up's report.
 */
final readonly class FollowUpCorpusRow
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $question,
        public string $parent,
        public CorpusFacts $facts,
    ) {
    }
}
