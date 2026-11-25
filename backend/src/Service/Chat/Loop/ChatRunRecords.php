<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\Run;
use App\Repository\RunRepository;
use Psr\Clock\ClockInterface;

/**
 * The durable `BRUNS` row of a chat run: written when the run opens and
 * after every unit, so a run is visible after Redis forgot it.
 */
final readonly class ChatRunRecords
{
    public const ORIGIN = 'web_chat';
    public const NOT_STARTED = 'not_started';

    public function __construct(
        private RunRepository $runs,
        private ClockInterface $clock,
    ) {
    }

    public function open(ChatRunState $state, int $startedBy, string $job, ?string $idempotencyKey): void
    {
        $now = $this->clock->now()->getTimestamp();
        $run = new Run($state->runId, Run::KIND_CHAT, $state->userId, self::ORIGIN, mb_substr($job, 0, Run::MAX_JOB_LENGTH), $now);
        $run->setStartedBy($startedBy);
        $run->setChatId($state->chatId);
        $run->setIdempotency($idempotencyKey);
        $run->progress(Run::STATUS_RUNNING, null, 0, 0, null, $now);
        $this->runs->save($run);
    }

    /** The run never got its checkpoint: close the row, so it does not look like work in progress. */
    public function abandon(string $runId): void
    {
        $run = $this->runs->findOneByUuid($runId);
        if (!$run instanceof Run || Run::KIND_CHAT !== $run->getKind() || $run->isTerminal()) {
            return;
        }
        $run->progress(Run::STATUS_FAILED, null, 0, 0, self::NOT_STARTED, $this->clock->now()->getTimestamp());
        $this->runs->save($run);
    }

    public function sync(ChatRunState $state): void
    {
        $run = $this->runs->findOneByUuid($state->runId);
        if (!$run instanceof Run || Run::KIND_CHAT !== $run->getKind()) {
            return;
        }
        $run->progress(
            $state->status->value,
            $state->waitReason,
            $state->steps,
            $state->costMicro,
            $state->end?->value,
            $this->clock->now()->getTimestamp(),
        );
        if (null !== $state->notes) {
            $run->setNotes($state->notes->json());
        }
        $this->runs->save($run);
    }

    /** The run when $userId started it (a row without a starter: its owner); null for everyone else. */
    public function forCaller(string $runId, int $userId): ?Run
    {
        $run = $this->runs->findOneByUuid($runId);
        if (!$run instanceof Run || Run::KIND_CHAT !== $run->getKind()) {
            return null;
        }
        $starter = $run->getStartedBy() ?? $run->getUserId();

        return $starter === $userId ? $run : null;
    }
}
