<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Service\Document\Tool\DocumentToolResult;

/**
 * What an executor returns before the invoker clamps the text.
 */
final readonly class ToolExecution
{
    /**
     * @param array<string, mixed> $details caller-specific fields (usage labels, raw MCP content). Never arguments.
     */
    public function __construct(
        public string $text,
        public bool $isError,
        public string $source,
        public string $kind,
        public ?DocumentToolResult $document = null,
        public array $details = [],
    ) {
    }
}
