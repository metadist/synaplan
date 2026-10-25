<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchQuestion;
use App\Service\Research\Run\ResearchQuestions;
use App\Service\Research\Run\ResearchReaper;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStatus;
use App\Service\Research\Run\ResearchUnitPlan;
use App\Service\Research\Run\Stage\StageContext;
use App\Service\Research\Run\Stage\StageResult;
use Psr\Log\NullLogger;

final class ResearchReaperTest extends ResearchUnitCase
{
    private SilentResearchReport $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = new SilentResearchReport();
    }

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
        self::assertSame([], $this->store->expiredClaims());
        self::assertSame(['failed'], $this->reports->statuses);
    }

    public function testAReportThatIsAlreadyWrittenIsNotPaidForAgain(): void
    {
        $state = $this->openRun();
        $state->ledger->setReport('Kept.');
        $this->store->save($state);
        $units = new ScriptedResearchUnits(new StageResult('deliver'));
        $reaper = $this->reaper($units);

        foreach ([1, 2] as $_) {
            self::assertNotNull($this->store->claim($state->ref->id, 1, 1000));
            $this->clock->sleep(5);
            $reaper->reap();
        }

        $loaded = $this->store->load($state->ref->id);
        self::assertSame(0, $units->calls);
        self::assertSame('Kept.', $loaded?->ledger->report());
        self::assertSame(ResearchOutcome::FAILED_INTERRUPTED, $loaded->outcome?->code);
    }

    public function testAFailingWriterStillClosesTheRunAndTheOtherRunsAreReaped(): void
    {
        $broken = $this->openRun();
        $waiting = $this->waiting(ResearchStatus::WaitingPlan);
        $this->age($waiting, ResearchReaper::PLAN_WAIT_SECONDS);
        $units = new ScriptedResearchUnits(new StageResult('deliver'), static function (StageContext $ctx): void {
            throw new \RuntimeException('The provider closed the connection.');
        });
        $reaper = $this->reaper($units);
        $this->store->noteLostClaim($broken->ref->id, 1);
        self::assertNotNull($this->store->claim($broken->ref->id, 1, 1000));
        $this->clock->sleep(5);

        $counts = $reaper->reap();

        self::assertSame(1, $units->calls);
        self::assertSame(ResearchOutcome::FAILED_INTERRUPTED, $this->store->load($broken->ref->id)?->outcome?->code);
        self::assertSame(ResearchOutcome::STOPPED_WAITING, $this->store->load($waiting->ref->id)?->outcome?->code);
        self::assertSame(1, $counts['interrupted']);
        self::assertSame(1, $counts['stopped']);
    }

    public function testAStalledRunIsQueuedAgainOnceThenClosed(): void
    {
        $state = $this->openRun();
        $units = new ScriptedResearchUnits(new StageResult('deliver'));
        $reaper = $this->reaper($units);

        $this->clock->sleep(ResearchReaper::STALL_SECONDS - 1);
        $reaper->reap();
        self::assertSame([], $this->bus->sent);

        $this->clock->sleep(1);
        $reaper->reap();
        self::assertCount(1, $this->bus->sent);
        self::assertSame(ResearchStatus::Briefing, $this->store->load($state->ref->id)?->status);

        $this->clock->sleep(ResearchReaper::STALL_SECONDS - 1);
        $reaper->reap();
        self::assertCount(1, $this->bus->sent);
        self::assertSame(ResearchStatus::Briefing, $this->store->load($state->ref->id)?->status);

        $this->clock->sleep(1);
        $reaper->reap();

        $loaded = $this->store->load($state->ref->id);
        self::assertCount(1, $this->bus->sent);
        self::assertSame(['write'], $units->units);
        self::assertInstanceOf(ResearchRunState::class, $loaded);
        self::assertSame(ResearchStatus::Failed, $loaded->status);
        self::assertSame(ResearchOutcome::FAILED_INTERRUPTED, $loaded->outcome?->code);
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
        self::assertSame(3, $loaded->expectedSeq);
        self::assertSame(ResearchUnitPlan::BRIEF_PLAN, $this->bus->sent[0]['unit'] ?? null);
        self::assertSame(3, $this->bus->sent[0]['seq'] ?? null);
        self::assertSame(0, $units->calls);
        $events = $this->events->replay($waiting->ref->id, 0);
        self::assertSame('question', $events[0]['event'] ?? null);
        self::assertTrue($events[0]['data']['skipped'] ?? false);
    }

    public function testAnAnswerThatHoldsTheClaimIsNotOverwritten(): void
    {
        $waiting = $this->waiting(ResearchStatus::WaitingAnswer);
        $this->age($waiting, 601);
        self::assertNotNull($this->store->claim($waiting->ref->id, 2, 60_000));

        $this->reaper(new ScriptedResearchUnits(new StageResult('gather')))->reap();

        self::assertSame(ResearchStatus::WaitingAnswer, $this->store->load($waiting->ref->id)?->status);
        self::assertSame([], $this->bus->sent);
        self::assertSame([], $this->events->replay($waiting->ref->id, 0));
    }

    public function testALeadQuestionSkippedAfterTenMinutesResumesTheRound(): void
    {
        $waiting = $this->waiting(ResearchStatus::WaitingAnswer);
        $question = ResearchQuestion::collect([
            ['text' => 'Which shop?', 'options' => ['Alpha', 'Beta']],
        ], 1)[0];
        $waiting->questions = new ResearchQuestions(
            [$question],
            ResearchUnitPlan::ROUND,
            ResearchStatus::Gathering->value,
            'a',
        );
        $waiting->conversation->append('', [[
            'id' => 'a',
            'type' => 'function',
            'function' => ['name' => 'ask_user', 'arguments' => '{}'],
        ]], [[
            'id' => 'a',
            'name' => 'ask_user',
            'content' => '{"pending":true}',
        ]]);
        $this->store->save($waiting);
        $this->age($waiting, 601);

        $this->reaper(new ScriptedResearchUnits(new StageResult('round')))->reap();

        $loaded = $this->store->load($waiting->ref->id);
        self::assertSame(ResearchStatus::Gathering, $loaded?->status);
        self::assertSame(ResearchUnitPlan::ROUND, $this->bus->sent[0]['unit'] ?? null);
        self::assertSame('Skipped', $loaded->replies[0]['answer'] ?? null);
        $content = $loaded->conversation->turns()[0]['results'][0]['content'] ?? '';
        self::assertSame('{"skipped":true}', $content);
    }

    public function testAStepWaitingForAWorkerSaysSoAfterTwentySeconds(): void
    {
        $state = $this->openRun();
        $this->age($state, ResearchReaper::QUEUE_AFTER_SECONDS);
        $reaper = $this->reaper(new ScriptedResearchUnits(new StageResult('gather')));

        $reaper->reap();
        $first = $this->events->replay($state->ref->id, 0);
        self::assertSame('queued', $first[0]['event'] ?? null);
        self::assertSame(ResearchReaper::QUEUE_AFTER_SECONDS, $first[0]['data']['since_seconds'] ?? null);

        $reaper->reap();
        self::assertCount(1, $this->events->replay($state->ref->id, 0));

        $this->clock->sleep(ResearchReaper::QUEUE_AFTER_SECONDS);
        $reaper->reap();
        self::assertCount(2, $this->events->replay($state->ref->id, 0));
    }

    public function testWriteNowThatRacedIntoAWaitGoesToTheReport(): void
    {
        $waiting = $this->waiting(ResearchStatus::WaitingPlan);
        $this->store->requestWriteNow($waiting->ref->id);

        $counts = $this->reaper(new ScriptedResearchUnits(new StageResult('deliver')))->reap();

        self::assertSame(1, $counts['continued']);
        self::assertSame(ResearchStatus::Writing, $this->store->load($waiting->ref->id)?->status);
        self::assertSame(ResearchUnitPlan::WRITE, $this->bus->sent[0]['unit'] ?? null);
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
        self::assertSame(['stopped'], $this->reports->statuses);
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
            new NullLogger(),
            $this->reports,
        );
    }
}
