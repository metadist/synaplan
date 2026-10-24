<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchKey;
use App\Service\Research\Run\ResearchRoles;
use App\Service\Research\Run\ResearchRunRef;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStageContextFactory;
use App\Service\Research\Run\ResearchStateStore;
use App\Service\Research\Run\ResearchUnitBus;
use App\Service\Research\Run\ResearchUnitRunner;
use App\Service\Research\Run\RoleUnavailable;
use App\Service\Research\Run\Stage\ResearchStop;
use App\Service\Research\Run\Stage\StageContext;
use App\Service\Research\Run\Stage\StageResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

abstract class ResearchUnitCase extends TestCase
{
    protected MemoryResearchRunKeys $keys;

    protected MockClock $clock;

    protected ResearchStateStore $store;

    protected ResearchEventLog $events;

    protected RecordingResearchBus $bus;

    protected function setUp(): void
    {
        $created = new \DateTimeImmutable('2026-10-24T09:00:00+00:00');
        $this->clock = new MockClock($created);
        $this->keys = new MemoryResearchRunKeys($this->clock);
        $this->store = new ResearchStateStore($this->keys, $this->clock);
        $this->events = new ResearchEventLog($this->keys);
        $this->bus = new RecordingResearchBus();
    }

    protected function openRun(): ResearchRunState
    {
        $created = $this->clock->now();
        $budget = ResearchBudget::for(ResearchDepth::Quick, ResearchBudgetConfig::defaults(ResearchDepth::Quick), $created);
        $budget->at($created);
        $state = ResearchRunState::open(
            ResearchRunRef::start(7, ResearchDepth::Quick, 'What does one unit cost?', null, $created),
            $budget,
        );
        $this->store->save($state);

        return $state;
    }

    protected function age(ResearchRunState $state, int $seconds): void
    {
        $when = $this->clock->now()->modify('-'.$seconds.' seconds');
        $this->keys->set(
            ResearchKey::run($state->ref->id),
            $state->encode($state->expectedSeq, $state->checkpointSeq, $when, $state->expiresAt),
            48 * 3600,
        );
    }
}

final class RecordingResearchBus implements ResearchUnitBus
{
    /** @var list<array{runId: string, unit: string, seq: int, delayMs: int}> */
    public array $sent = [];

    public function dispatch(string $runId, string $unit, int $seq, int $delayMs): void
    {
        $this->sent[] = ['runId' => $runId, 'unit' => $unit, 'seq' => $seq, 'delayMs' => $delayMs];
    }
}

final class ScriptedResearchUnits implements ResearchUnitRunner
{
    public int $calls = 0;

    /** @var list<string> */
    public array $units = [];

    /** @param \Closure(StageContext): void|null $onRun */
    public function __construct(
        private readonly StageResult $result,
        private readonly ?\Closure $onRun = null,
    ) {
    }

    public function run(StageContext $ctx, string $unit): StageResult
    {
        ++$this->calls;
        $this->units[] = $unit;
        if (null !== $this->onRun) {
            ($this->onRun)($ctx);
        }

        return $this->result;
    }
}

final class FixedResearchContext implements ResearchStageContextFactory
{
    public function __construct(
        private readonly ResearchEventLog $events,
        private readonly \DateTimeImmutable $today,
    ) {
    }

    public function open(ResearchRunState $state): StageContext
    {
        return new StageContext(
            $state,
            new ResearchRoles(1, 2, 3, 4, 5, false, []),
            $this->events,
            $this->today,
            new ResearchStop($state->stopRequested),
        );
    }
}

final class UnavailableResearchContext implements ResearchStageContextFactory
{
    public function open(ResearchRunState $state): StageContext
    {
        throw new RoleUnavailable('reader');
    }
}

final class RecordingMessageBus implements MessageBusInterface
{
    public ?object $message = null;

    public ?DelayStamp $stamp = null;

    /**
     * @param array<int, mixed> $stamps
     */
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->message = $message;
        $stamp = $stamps[0] ?? null;
        $this->stamp = $stamp instanceof DelayStamp ? $stamp : null;

        return new Envelope($message, $stamps);
    }
}
