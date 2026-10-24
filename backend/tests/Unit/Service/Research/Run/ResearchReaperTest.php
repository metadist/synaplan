<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchReaper;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStatus;
use App\Service\Research\Run\ResearchUnitPlan;
use App\Service\Research\Run\Stage\StageResult;

final class ResearchReaperTest extends ResearchUnitCase
{
    public function testALostLeaseIsRedispatchedOnceThenInterrupted(): void
    {
        $state = $this->openRun();
        $units = new ScriptedResearchUnits(new StageResult('deliver'));
        $reaper = $this->reaper($units);

        self::assertNotNull($this->store->claim($state->ref->id, 1, 1000));
        $this->clock->sleep(5);
        $reaper->reap();

        self::assertSame(ResearchStatus::Briefing, $this->store->load($state->ref->id)?->status);
        self::assertSame([[
            'runId' => $state->ref->id,
            'unit' => ResearchUnitPlan::BRIEF_PLAN,
            'seq' => 1,
            'delayMs' => 0,
        ]], $this->bus->sent);
        self::assertSame(0, $units->calls);

        self::assertNotNull($this->store->claim($state->ref->id, 1, 1000));
        $this->clock->sleep(5);
        $reaper->reap();

        $loaded = $this->store->load($state->ref->id);
        self::assertInstanceOf(ResearchRunState::class, $loaded);
        self::assertSame(['write'], $units->units);
        self::assertSame(ResearchStatus::Failed, $loaded->status);
        self::assertInstanceOf(ResearchOutcome::class, $loaded->outcome);
        self::assertSame(ResearchOutcome::FAILED_INTERRUPTED, $loaded->outcome->code);
        self::assertStringContainsString('interrupted', $loaded->outcome->text());
        self::assertCount(1, $this->bus->sent);
    }

    public function testAnOpenQuestionContinuesAfterTenMinutes(): void
    {
        $waiting = $this->waiting(ResearchStatus::WaitingAnswer);
        $units = new ScriptedResearchUnits(new StageResult('gather'));
        $reaper = $this->reaper($units);

        $this->age($waiting, 540);
        $reaper->reap();
        self::assertSame(ResearchStatus::WaitingAnswer, $this->store->load($waiting->ref->id)?->status);
        self::assertSame([], $this->bus->sent);

        $this->age($waiting, 601);
        $reaper->reap();

        $loaded = $this->store->load($waiting->ref->id);
        self::assertSame(ResearchStatus::Planning, $loaded?->status);
        self::assertSame(ResearchUnitPlan::BRIEF_PLAN, $this->bus->sent[0]['unit'] ?? null);
        self::assertSame(2, $this->bus->sent[0]['seq'] ?? null);
        self::assertSame(0, $units->calls);
        $events = $this->events->replay($waiting->ref->id, 0);
        self::assertSame('question', $events[0]['event'] ?? null);
        self::assertTrue($events[0]['data']['skipped'] ?? false);
    }

    public function testAnUnconfirmedPlanStopsAfterADay(): void
    {
        $waiting = $this->waiting(ResearchStatus::WaitingPlan);
        $units = new ScriptedResearchUnits(new StageResult('done'));
        $this->age($waiting, ResearchReaper::PLAN_WAIT_SECONDS);

        $this->reaper($units)->reap();

        $loaded = $this->store->load($waiting->ref->id);
        self::assertInstanceOf(ResearchRunState::class, $loaded);
        self::assertSame(ResearchStatus::Stopped, $loaded->status);
        self::assertInstanceOf(ResearchOutcome::class, $loaded->outcome);
        self::assertSame(ResearchOutcome::STOPPED_WAITING, $loaded->outcome->code);
        self::assertStringContainsString('not confirmed', $loaded->outcome->text());
        self::assertSame([], $this->bus->sent);
        self::assertSame(0, $units->calls);
    }

    private function waiting(ResearchStatus $status): ResearchRunState
    {
        $opened = $this->openRun();

        $waiting = new ResearchRunState($opened->ref, $status, $opened->ledger, $opened->budget, 2, 1);
        $this->store->save($waiting);

        return $waiting;
    }

    private function reaper(ScriptedResearchUnits $units): ResearchReaper
    {
        return new ResearchReaper(
            $this->store,
            $units,
            new FixedResearchContext($this->events, $this->clock->now()),
            $this->bus,
            $this->events,
            $this->clock,
        );
    }
}
