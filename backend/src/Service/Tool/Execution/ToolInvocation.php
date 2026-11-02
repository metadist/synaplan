<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

final readonly class ToolInvocation
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $toolName,
        public array $arguments,
        public ToolCaller $caller,
        public GateMode $gate,
        public bool $recordUsage = false,
    ) {
    }
}
