<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/** A corpus row that continues a finished research run instead of starting one. */
final readonly class FollowUpCorpusRow
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $question,
    ) {
    }
}
