<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Service\Chat\Loop\ChatRunModel;
use App\Service\Chat\Loop\ChatRunModelFailed;
use App\Service\Chat\Loop\ChatRunReply;
use App\Service\Chat\Loop\ChatRunState;
use App\Service\Chat\Loop\TranscriptCompactor;

/**
 * A scripted model whose compaction calls answer from their own script, so a
 * test does not have to know after which step the window fills. The last
 * notes answer repeats once the script is used up.
 */
final class CompactingChatRunModel implements ChatRunModel
{
    /** @var list<array{messages: list<array<string, mixed>>, maxTokens: ?int}> */
    public array $compactions = [];

    /** @var list<ChatRunReply|ChatRunModelFailed> */
    private array $notes;

    /** @param list<ChatRunReply|ChatRunModelFailed> $notes */
    public function __construct(public readonly ScriptedChatRunModel $work, array $notes)
    {
        $this->notes = $notes;
    }

    public function call(ChatRunState $state, array $messages, array $tools, ?string $toolChoice, ?callable $onText = null, ?int $maxTokens = null): ChatRunReply
    {
        if (TranscriptCompactor::INSTRUCTION !== ($messages[0]['content'] ?? null)) {
            return $this->work->call($state, $messages, $tools, $toolChoice, $onText, $maxTokens);
        }
        $this->compactions[] = ['messages' => $messages, 'maxTokens' => $maxTokens];
        $next = \count($this->notes) > 1 ? array_shift($this->notes) : ($this->notes[0] ?? null);
        if (null === $next) {
            throw new \LogicException('The run compacted without a scripted answer.');
        }
        if ($next instanceof ChatRunModelFailed) {
            throw $next;
        }

        return $next;
    }
}
