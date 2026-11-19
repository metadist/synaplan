<?php

declare(strict_types=1);

namespace App\Service\Tool\Loop;

final readonly class ToolLoopRun
{
    public function __construct(
        public ToolLoopStop $stop,
        public int $iterations,
    ) {
    }
}
