<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * Makes the one model call of a unit. Usage is recorded per call.
 */
interface ChatRunModel
{
    /**
     * @param list<array<string, mixed>>    $messages the whole prompt, system message first
     * @param list<array<string, mixed>>    $tools    function declarations
     * @param (callable(string): void)|null $onText   receives the answer's text while it is written, when the model streams
     * @param int|null                      $maxTokens the most the answer may write; null leaves the provider's default
     *
     * @throws ChatRunModelFailed when the provider refused or failed the call
     */
    public function call(ChatRunState $state, array $messages, array $tools, ?string $toolChoice, ?callable $onText = null, ?int $maxTokens = null): ChatRunReply;
}
