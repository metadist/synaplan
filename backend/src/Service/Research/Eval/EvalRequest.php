<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/**
 * One invocation of app:research:eval.
 */
final readonly class EvalRequest
{
    public function __construct(
        public string $arm,
        public string $corpusPath,
        public bool $smokeOnly,
        public int $userId,
        public int $repeat,
        public EvalLiveTarget $live,
        public EvalFiles $files,
        public string $commandLine,
    ) {
    }
}
