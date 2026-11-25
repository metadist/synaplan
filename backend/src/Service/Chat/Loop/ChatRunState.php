<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Service\Run\RunState;

/**
 * The checkpoint of one chat run: who it belongs to, the transcript the
 * model sees, the tool calls of the last model answer that still have to
 * run, the plan the model keeps, and how the run ended.
 *
 * The system prompt is not stored whole: the project part is resolved again
 * at every unit, so a chat moved out of its project loses it at once.
 */
final class ChatRunState implements RunState
{
    public const MODE_FREE = 'free';
    public const MODE_FINISH = 'finish';
    public const MODE_FINISH_JSON = 'finish_json';

    /** One last call without tools that writes the result from what the run has (03 §1.3). */
    public const MODE_WRAP_UP = 'wrap_up';
    public const MODES = [self::MODE_FREE, self::MODE_FINISH, self::MODE_FINISH_JSON, self::MODE_WRAP_UP];

    public const PLAN_PENDING = 'pending';
    public const PLAN_ACTIVE = 'in_progress';
    public const PLAN_DONE = 'done';
    public const PLAN_STATES = [self::PLAN_PENDING, self::PLAN_ACTIVE, self::PLAN_DONE];

    public const DECISION_APPROVED = 'approved';
    public const DECISION_REJECTED = 'rejected';

    public const WAIT_APPROVAL = 'approval';

    public ChatRunStatus $status = ChatRunStatus::Running;
    public ?ChatRunEnd $end = null;
    public ?string $waitReason = null;
    public ?string $answer = null;

    /** What the person already saw of this run, the first answer made in the request included. */
    public string $said = '';

    /**
     * The note the check before delivery adds under the answer: null until the
     * check ran, empty when it found nothing to note.
     */
    public ?string $note = null;
    public string $mode = self::MODE_FREE;
    public int $steps = 0;
    public int $costMicro = 0;
    public int $nudges = 0;

    /** The dearest single model call of this run, in what the person is billed. */
    public int $dearestMicro = 0;

    /** The last model call: what it cost and how many characters it sent, for the cost estimate. */
    public int $lastCallMicro = 0;
    public int $lastCallChars = 0;

    /** The model's price, fixed at the start; null for a model without one. */
    public ?ChatRunRates $rates = null;

    /** Whether the model was told once that the budget of this answer is nearly used. */
    public bool $budgetNudged = false;

    /** Why the run wraps up; set once, when the wrap-up call is asked for. */
    public ?ChatRunEnd $wrapUp = null;

    /**
     * What circle detection counts (03 §1.4): calls by their normalised
     * input, the results seen so far, steps in a row with nothing new, tool
     * errors in a row, whether the model was told once already, and whether
     * the step under way found something.
     *
     * @var array{calls: array<string, array{tool: string, n: int}>, seen: array<string, true>, stale: int, errors: int, nudged: bool, found: bool, ran: bool}
     */
    public array $stall = ChatRunStallDetector::EMPTY;

    /** Model calls that failed in a row; a call that answers clears it. */
    public int $modelFailures = 0;

    /** The chat message the answer goes into, written before the run started. */
    public ?int $replyMessageId = null;

    /** @var list<array<string, mixed>> the transcript after the system prompt */
    public array $messages = [];

    /** @var list<array{id: string, name: string, args: array<string, mixed>, approval?: int, decision?: string}> */
    public array $pending = [];

    /** @var list<array{text: string, state: string}> */
    public array $plan = [];

    /** @var array<string, int> a repeated read, by call hash, answered from the transcript */
    public array $reads = [];

    /** @var list<string> connected tools the model opened, whose schemas every later call declares */
    public array $opened = [];

    /** @var list<int> files whose picture the vision model already looked at in this run */
    public array $analyzed = [];

    /**
     * How many messages at the start of the transcript are the conversation
     * the run started from (the chat history and the person's question). A
     * compaction keeps them word for word.
     */
    public int $head = 0;

    /** The model's context window in tokens, fixed at the start; null when the catalog has none. */
    public ?int $window = null;

    /** The notes the older, folded part of the transcript became (03 §1.5); null before the first compaction. */
    public ?ChatRunNotes $notes = null;

    public int $compactions = 0;

    private int $expected = 1;
    private int $checkpoint = 0;
    private ?int $expiresAt = null;

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function __construct(
        public readonly string $runId,
        public readonly int $userId,
        public readonly int $chatId,
        public readonly ?int $incomingMessageId,
        public readonly ?string $provider,
        public readonly ?string $model,
        public readonly string $locale,
        public readonly string $basePrompt,
        array $messages,
        private int $updated,
    ) {
        $this->messages = $messages;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function expectedSeq(): int
    {
        return $this->expected;
    }

    public function checkpointSeq(): int
    {
        return $this->checkpoint;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function updatedAt(): int
    {
        return $this->updated;
    }

    public function expiresAt(): ?int
    {
        return $this->expiresAt;
    }

    public function stored(int $expectedSeq, int $checkpointSeq, int $now, ?int $expiresAt): string
    {
        return ChatRunCodec::encode($this, $expectedSeq, $checkpointSeq, $now, $expiresAt);
    }

    public function checkpointed(int $expectedSeq, int $checkpointSeq, int $now, ?int $expiresAt): void
    {
        $this->restore($expectedSeq, $checkpointSeq, $now, $expiresAt);
    }

    /** Used by the codec only: put the stored counters back. */
    public function restore(int $expectedSeq, int $checkpointSeq, int $updated, ?int $expiresAt): void
    {
        $this->expected = $expectedSeq;
        $this->checkpoint = $checkpointSeq;
        $this->updated = $updated;
        $this->expiresAt = $expiresAt;
    }

    public function finish(ChatRunEnd $end, ?string $answer = null): void
    {
        $this->status = $end->status();
        $this->end = $end;
        $this->waitReason = null;
        $this->pending = [];
        if (null !== $answer && '' !== trim($answer)) {
            $this->answer = $answer;
        }
    }

    public function waitForApproval(): void
    {
        $this->status = ChatRunStatus::Waiting;
        $this->waitReason = self::WAIT_APPROVAL;
    }

    public function resume(): void
    {
        $this->status = ChatRunStatus::Running;
        $this->waitReason = null;
    }

    /** The approval the run waits for, or null. */
    public function awaitedApproval(): ?int
    {
        foreach ($this->pending as $call) {
            if (isset($call['approval']) && !isset($call['decision'])) {
                return $call['approval'];
            }
        }

        return null;
    }

    /** Record the person's decision on the approval the run waits for. False when it waits for another one. */
    public function decide(int $approvalId, string $decision): bool
    {
        foreach ($this->pending as $i => $call) {
            if (($call['approval'] ?? null) === $approvalId && !isset($call['decision'])) {
                $this->pending[$i]['decision'] = $decision;

                return true;
            }
        }

        return false;
    }

    /** @return list<string> the plan items that are not done */
    public function openItems(): array
    {
        $open = [];
        foreach ($this->plan as $item) {
            if (self::PLAN_DONE !== $item['state']) {
                $open[] = $item['text'];
            }
        }

        return $open;
    }

    /** The run has a work list and every item on it is done. */
    public function planDone(): bool
    {
        return [] !== $this->plan && [] === $this->openItems();
    }

    /** The last text the model wrote, used when a run ends without a clean answer. */
    public function lastAssistantText(): ?string
    {
        for ($i = \count($this->messages) - 1; $i >= 0; --$i) {
            $message = $this->messages[$i];
            if ('assistant' === ($message['role'] ?? null) && \is_string($message['content'] ?? null) && '' !== trim($message['content'])) {
                return $message['content'];
            }
        }

        return null;
    }
}
