<?php

declare(strict_types=1);

namespace App\Tests\Eval\ChatLoop;

/**
 * Sends corpus turns to a stack the way the web client does and measures
 * each one. A research run that waits for the person gets "you decide", so
 * a run measures the work and not the waiting.
 */
final class CorpusRunner
{
    /** @var \Closure(): float */
    private readonly \Closure $clock;
    /** @var \Closure(float): void */
    private readonly \Closure $sleep;
    /** @var list<string> */
    private array $leftovers = [];
    private ?string $spendStop = null;

    /**
     * @param (\Closure(): float)|null           $clock    seconds, monotonic
     * @param (\Closure(float): void)|null       $sleep
     * @param (\Closure(TurnOutcome): void)|null $progress called after every turn
     * @param bool                               $live     false stops the run at the first turn the account was charged for
     */
    public function __construct(
        private readonly StackClient $stack,
        private readonly float $turnTimeoutSeconds,
        private readonly float $pollSeconds = 3.0,
        ?\Closure $clock = null,
        ?\Closure $sleep = null,
        private readonly ?\Closure $progress = null,
        private readonly bool $keepChats = false,
        private readonly bool $live = true,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1e6));
        };
    }

    /**
     * Every turn in a chat of its own.
     *
     * @param list<CorpusTurn> $turns
     *
     * @return list<TurnOutcome>
     */
    public function run(array $turns): array
    {
        $outcomes = [];
        foreach ($turns as $turn) {
            $outcome = $this->inChat('chat-loop eval '.$turn->id, $turn, fn (int $chatId): TurnOutcome => $this->measure($chatId, $turn));
            $outcomes[] = $outcome;
            if ($this->chargedWithoutLive($outcome)) {
                break;
            }
        }

        return $outcomes;
    }

    /**
     * All turns after each other in one chat: a scripted conversation.
     *
     * @param list<CorpusTurn> $turns
     *
     * @return list<TurnOutcome>
     */
    public function runAsOneChat(string $title, array $turns): array
    {
        if ([] === $turns) {
            return [];
        }
        $outcomes = [];
        $last = $this->inChat('chat-loop eval '.$title, $turns[0], function (int $chatId) use ($turns, &$outcomes): TurnOutcome {
            $outcome = $this->measure($chatId, $turns[0]);
            $outcomes[] = $outcome;
            foreach (\array_slice($turns, 1) as $turn) {
                if (!$outcome->hasTerminalResult() || $this->chargedWithoutLive($outcome)) {
                    break;
                }
                $outcome = $this->measure($chatId, $turn);
                $outcomes[] = $outcome;
            }
            $this->chargedWithoutLive($outcome);

            return $outcome;
        });

        // The chat could not be opened: its one outcome says why.
        return [] === $outcomes ? [$last] : $outcomes;
    }

    /**
     * What the run could not remove from the account: chats, files, research
     * runs. Empty after a clean run.
     *
     * @return list<string>
     */
    public function leftovers(): array
    {
        return $this->leftovers;
    }

    /**
     * The turn that was charged although the run was not live, or null.
     */
    public function spendStop(): ?string
    {
        return $this->spendStop;
    }

    /**
     * @param list<TurnOutcome> $outcomes
     */
    public static function requireTerminalResults(array $outcomes): void
    {
        $open = [];
        foreach ($outcomes as $outcome) {
            if (!$outcome->hasTerminalResult()) {
                $open[] = $outcome->id.' ('.$outcome->reason.')';
            }
        }
        if ([] !== $open) {
            throw new \RuntimeException('No terminal result for: '.implode('; ', $open).'. The numbers of this run do not count.');
        }
    }

    /**
     * Opens a chat, runs the work in it and deletes it. A chat that cannot
     * be opened gives the turn no terminal result instead of ending the run
     * and losing the turns before it.
     *
     * @param \Closure(int): TurnOutcome $work
     */
    private function inChat(string $title, CorpusTurn $first, \Closure $work): TurnOutcome
    {
        try {
            $chatId = $this->stack->createChat($title);
        } catch (\Throwable $e) {
            $started = ($this->clock)();
            $meter = new TurnMeter($first, $started);
            $meter->unreachable($e->getMessage());

            return $this->report($meter->outcome($started));
        }
        try {
            return $work($chatId);
        } finally {
            if (!$this->keepChats) {
                $this->remove('chat '.$chatId, fn () => $this->stack->deleteChat($chatId));
            }
        }
    }

    private function measure(int $chatId, CorpusTurn $turn): TurnOutcome
    {
        $started = ($this->clock)();
        $meter = new TurnMeter($turn, $started);
        $fileIds = [];
        try {
            foreach ($turn->files as $name) {
                $fileIds[] = $this->stack->attach(ChatLoopCorpus::fixturePath($name));
            }
            // Uploading is not part of the turn the person waits for.
            $started = ($this->clock)();
            $meter = new TurnMeter($turn, $started);
            foreach ($this->stack->turn($chatId, $turn, $fileIds, $this->turnTimeoutSeconds) as $event) {
                $meter->event($event, ($this->clock)());
            }
            $this->followResearch($meter, $started);
        } catch (\Throwable $e) {
            // A dropped connection or a timeout: the turn has no terminal
            // result, and the reason says why.
            $meter->unreachable($e::class.': '.$e->getMessage());
        }

        $runId = $meter->openResearchRun();
        if (null !== $runId) {
            // Nobody reads this run any more; it must not keep spending.
            $this->remove('research run '.$runId.' (still running)', fn () => $this->stack->cancelResearch($runId));
        }
        if (!$this->keepChats) {
            foreach ($fileIds as $fileId) {
                $this->remove('file '.$fileId, fn () => $this->stack->deleteFile($fileId));
            }
        }

        return $this->report($meter->outcome(($this->clock)()));
    }

    private function report(TurnOutcome $outcome): TurnOutcome
    {
        if (null !== $this->progress) {
            ($this->progress)($outcome);
        }

        return $outcome;
    }

    private function chargedWithoutLive(TurnOutcome $outcome): bool
    {
        if ($this->live || $outcome->cost <= 0.0) {
            return false;
        }
        $this->spendStop = $outcome->id;

        return true;
    }

    /**
     * @param \Closure(): void $delete
     */
    private function remove(string $what, \Closure $delete): void
    {
        try {
            $delete();
        } catch (\Throwable $e) {
            $this->leftovers[] = $what.': '.$e->getMessage();
        }
    }

    private function followResearch(TurnMeter $meter, float $started): void
    {
        while (null !== ($runId = $meter->openResearchRun())) {
            if (($this->clock)() - $started > $this->turnTimeoutSeconds) {
                $meter->unreachable('The research run '.$runId.' was still working after '.$this->turnTimeoutSeconds.' s.');

                return;
            }
            $run = $this->stack->research($runId);
            if ($meter->research($run)) {
                return;
            }
            $this->decideForThePerson($runId, $run);
            ($this->sleep)($this->pollSeconds);
        }
    }

    /**
     * @param array<mixed> $run
     */
    private function decideForThePerson(string $runId, array $run): void
    {
        $status = $run['status'] ?? null;
        if ('waiting_plan' === $status) {
            $this->stack->researchInput($runId, ['type' => 'approve_plan']);

            return;
        }
        if ('waiting_answer' !== $status) {
            return;
        }
        foreach (\is_array($run['questions'] ?? null) ? $run['questions'] : [] as $question) {
            $open = \is_array($question) && null === ($question['answer'] ?? null) && true !== ($question['skipped'] ?? false);
            if ($open && \is_string($question['id'] ?? null)) {
                $this->stack->researchInput($runId, ['type' => 'skip_question', 'question' => $question['id']]);
            }
        }
    }
}
