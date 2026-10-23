<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/**
 * Files one eval invocation reads or writes.
 */
final readonly class EvalFiles
{
    public function __construct(
        public ?string $importPath,
        public ?string $frozenDir,
        public ?string $reportPath,
        public ?string $documentsDir,
        public ?string $answersPath = null,
    ) {
    }
}
