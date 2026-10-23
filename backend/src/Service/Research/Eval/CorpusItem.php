<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/**
 * One research-eval question. Gold match strings are scored without a model.
 */
final readonly class CorpusItem
{
    public function __construct(
        public string $id,
        public string $category,
        public string $language,
        public string $question,
        public string $depth,
        public bool $web,
        public bool $knowledge,
        public CorpusFacts $facts,
        public bool $smoke,
        public ?string $validUntil,
    ) {
    }
}
