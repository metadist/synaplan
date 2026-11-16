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

    /**
     * @param (\Closure(): float)|null           $clock    seconds, monotonic
     * @param (\Closure(float): void)|null       $sleep
     * @param (\Closure(TurnOutcome): void)|null $progress called after every turn
     */
    public function __construct(
        private readonly StackClient $stack,
        private readonly float $turnTimeoutSeconds,
        private readonly float $pollSeconds = 3.0,
        ?\Closure $clock = null,
        ?\Closure $sleep = null,
        private readonly ?\Closure $progress = null,
        private readonly bool $keepChats = false,
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
            $chatId = $this->stack->createChat('chat-loop eval '.$turn->id);
            try {
                $outcomes[] = $this->measure($chatId, $turn);
            } finally {
                $this->cleanUp($chatId);
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
        $chatId = $this->stack->createChat('chat-loop eval '.$title);
        $outcomes = [];
        try {
            foreach ($turns as $turn) {
                $outcome = $this->measure($chatId, $turn);
                $outcomes[] = $outcome;
                if (!$outcome->hasTerminalResult()) {
                    break;
                }
            }
        } finally {
            $this->cleanUp($chatId);
        }

        return $outcomes;
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

    private function measure(int $chatId, CorpusTurn $turn): TurnOutcome
    {
        $fileIds = array_map(fn (string $name): int => $this->stack->attach(ChatLoopCorpus::fixturePath($name)), $turn->files);

        $started = ($this->clock)();
        $meter = new TurnMeter($turn, $started);
        try {
            foreach ($this->stack->turn($chatId, $turn, $fileIds, $this->turnTimeoutSeconds) as $event) {
                $meter->event($event, ($this->clock)());
            }
            $this->followResearch($meter, $started);
        } catch (\Throwable $e) {
            // A dropped connection or a timeout: the turn has no terminal
            // result, and the reason says why.
            $meter->unreachable($e::class.': '.$e->getMessage());
        }

        $outcome = $meter->outcome(($this->clock)());
        if (null !== $this->progress) {
            ($this->progress)($outcome);
        }

        return $outcome;
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

    private function cleanUp(int $chatId): void
    {
        if ($this->keepChats) {
            return;
        }
        try {
            $this->stack->deleteChat($chatId);
        } catch (\Throwable) {
            // The measurement stands; a chat that could not be deleted is
            // named "chat-loop eval …" and can be removed by hand.
        }
    }
}
