<?php

declare(strict_types=1);

namespace App\Tests\Eval\ChatLoop;

/**
 * One turn of the chat-loop corpus. An assertion is a list of strings that
 * must all appear in the answer; it is scored without a model.
 */
final readonly class CorpusTurn
{
    /**
     * @param list<string>       $files  fixture names, uploaded before the turn
     * @param list<list<string>> $expect assertions; empty when only cost and time are measured
     */
    public function __construct(
        public string $id,
        public string $group,
        public string $language,
        public string $text,
        public bool $webSearch,
        public array $files,
        public array $expect,
    ) {
    }
}
