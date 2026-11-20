<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Service\Run\RunClaim;
use App\Service\Run\RunState;
use App\Service\Run\RunUnitHandler;
use App\Service\Run\RunUnitResult;
use Psr\Log\LoggerInterface;

/**
 * Chat run units on the run kernel: one unit is the tool calls of the last
 * model answer and one model call. After the checkpoint the `BRUNS` row
 * follows, and a finished run is posted into its chat.
 */
final readonly class ChatRunUnitHandler implements RunUnitHandler
{
    /** Four minutes; a live unit renews it between its calls. */
    public const LEASE_MS = 240_000;

    public function __construct(
        private ChatRunStep $step,
        private ChatRunRecords $records,
        private ChatRunDelivery $delivery,
        private LoggerInterface $logger,
    ) {
    }

    public function knows(string $unit): bool
    {
        return ChatRunStep::UNIT === $unit;
    }

    public function leaseMs(RunState $state, string $unit): int
    {
        return self::LEASE_MS;
    }

    public function run(RunState $state, string $unit, RunClaim $claim): RunUnitResult
    {
        return new RunUnitResult($this->step->run(self::chat($state), $claim));
    }

    public function cancel(RunState $state): RunUnitResult
    {
        $this->step->stop(self::chat($state));

        return RunUnitResult::done();
    }

    public function after(RunState $state, string $unit, RunUnitResult $result): void
    {
        $this->settle(self::chat($state));
    }

    /** Bring the row up to date and deliver a finished run. Never throws: the checkpoint is already written. */
    public function settle(ChatRunState $state): void
    {
        try {
            $this->records->sync($state);
            $this->delivery->deliver($state);
        } catch (\Throwable $e) {
            $this->logger->warning('A chat run was not settled after its unit', ['run_id' => $state->runId, 'exception' => $e]);
        }
    }

    public static function chat(RunState $state): ChatRunState
    {
        if (!$state instanceof ChatRunState) {
            throw new \LogicException('The chat run handler runs chat runs only.');
        }

        return $state;
    }
}
