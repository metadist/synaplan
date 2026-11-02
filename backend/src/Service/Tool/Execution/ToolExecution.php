<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Service\Document\Tool\DocumentToolResult;

/**
 * What an executor returns before the invoker clamps the text.
 */
final readonly class ToolExecution
{
    public function __construct(
        public string $text,
        public bool $isError,
        public string $source,
        public string $kind,
        public ?DocumentToolResult $document = null,
    ) {
    }
}
