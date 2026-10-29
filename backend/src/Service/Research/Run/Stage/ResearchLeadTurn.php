<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\ToolCalling\ToolCall;

/** One lead reply. Tool calls are already decoded. An empty list is a real answer. */
final readonly class ResearchLeadTurn
{
    /**
     * @param list<ToolCall> $calls
     */
    public function __construct(
        public bool $ok,
        public bool $called,
        public string $text,
        public array $calls,
        public string $modelKey,
        public ?float $cost,
        public ?ResearchCallUsage $usage = null,
    ) {
    }

    public static function failed(bool $called = false): self
    {
        return new self(false, $called, '', [], '', null);
    }
}
