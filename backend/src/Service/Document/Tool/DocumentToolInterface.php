<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

interface DocumentToolInterface
{
    public function name(): string;

    /**
     * OpenAI Chat Completions function declaration.
     *
     * @return array{type: 'function', function: array{name: string, description: string, parameters: array<string, mixed>}}
     */
    public function declaration(): array;

    /**
     * @return list<string>
     */
    public function appliesTo(): array;

    /**
     * Never throws — errors are returned as {@see DocumentToolResult}.
     *
     * @param array<string, mixed> $input
     */
    public function execute(DocumentSession $session, array $input): DocumentToolResult;
}
