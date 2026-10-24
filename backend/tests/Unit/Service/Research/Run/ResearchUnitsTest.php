<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Message\RunResearchUnit;
use App\Service\Research\Run\MessengerResearchUnitBus;
use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchKey;
use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchRunRef;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStatus;
use App\Service\Research\Run\ResearchUnitWorker;
use App\Service\Research\Run\Stage\ResearchStop;
use App\Service\Research\Run\Stage\StageContext;
use App\Service\Research\Run\Stage\StageResult;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class ResearchUnitsTest extends ResearchUnitCase
{
    public function testOneDeliveryRunsTheStepAndTheNextWaits(): void
    {
        $state = $this->openRun();
        $units = new ScriptedResearchUnits(new StageResult('gather'));
        $worker = $this->worker($units);

        $worker->handle($state->ref->id, 'brief_plan', 1);
        $worker->handle($state->ref->id, 'brief_plan', 1);

        self::assertSame(1, $units->calls);
        self::assertSame([[
            'runId' => $state->ref->id,
            'unit' => 'round',
            'seq' => 2,
            'delayMs' => ResearchUnitWorker::GAP_MS,
        ]], $this->bus->sent);
        self::assertSame(2, $this->store->load($state->ref->id)?->expectedSeq);
    }

    public function testAHeldClaimMakesNoSecondCall(): void
    {
        $state = $this->openRun();
        self::assertNotNull($this->store->claim($state->ref->id, 1, 1000));
        $units = new ScriptedResearchUnits(new StageResult('gather'));

        $this->worker($units)->handle($state->ref->id, 'brief_plan', 1);

        self::assertSame(0, $units->calls);
        self::assertSame([], $this->bus->sent);
    }

    public function testALostLeaseDoesNotQueueTheNextStepOrDropThePages(): void
    {
        $state = $this->openRun();
        $this->store->putSourceText($state->ref->id, 's1', 'The unit price is 12.40 EUR.');
        $keys = $this->keys;
        $units = new ScriptedResearchUnits(new StageResult('done'), function (StageContext $ctx) use ($keys): void {
            $ctx->state->ledger->setReport('Kept.');
            $keys->delete(ResearchKey::claim($ctx->state->ref->id, $ctx->state->expectedSeq));
        });

        $this->worker($units)->handle($state->ref->id, 'deliver', 1);

        $loaded = $this->store->load($state->ref->id);
        self::assertSame(1, $units->calls);
        self::assertSame([], $this->bus->sent);
        self::assertNull($loaded?->ledger->report());
        self::assertSame('The unit price is 12.40 EUR.', $this->store->sourceText($state->ref->id, 's1'));
    }

    public function testDeliverDropsThePagesOnlyAfterTheReportIsSaved(): void
    {
        $state = $this->openRun();
        $this->store->putSourceText($state->ref->id, 's1', 'The unit price is 12.40 EUR.');
        $units = new ScriptedResearchUnits(new StageResult('done'), function (StageContext $ctx): void {
            $ctx->state->ledger->setReport('The unit price is 12.40 EUR.');
        });

        $this->worker($units)->handle($state->ref->id, 'deliver', 1);

        $loaded = $this->store->load($state->ref->id);
        self::assertSame('The unit price is 12.40 EUR.', $loaded?->ledger->report());
        self::assertNull($this->store->sourceText($state->ref->id, 's1'));
        self::assertSame([], $this->bus->sent);
    }

    public function testADuplicateThatClaimsRightAfterTheCheckpointMakesNoSecondCall(): void
    {
        $state = $this->openRun();
        $units = new ScriptedResearchUnits(new StageResult('gather'));
        $first = $this->worker($units);
        $runId = $state->ref->id;
        $this->keys->beforeClaim = static function () use ($first, $runId): void {
            $first->handle($runId, 'brief_plan', 1);
        };

        $this->worker($units)->handle($runId, 'brief_plan', 1);

        self::assertSame(1, $units->calls);
        self::assertCount(1, $this->bus->sent);
        self::assertSame(2, $this->store->load($runId)?->expectedSeq);
        self::assertFalse($this->store->isClaimed($runId, 1));
    }

    public function testAStopDuringAStepSurvivesItsCheckpoint(): void
    {
        $state = $this->openRun();
        $store = $this->store;
        $units = new ScriptedResearchUnits(new StageResult('gather'), static function (StageContext $ctx) use ($store): void {
            $store->requestStop($ctx->state->ref->id);
        });
        $worker = $this->worker($units);

        $worker->handle($state->ref->id, 'brief_plan', 1);
        $worker->handle($state->ref->id, 'round', 2);

        $loaded = $this->store->load($state->ref->id);
        self::assertSame(1, $units->calls);
        self::assertTrue($this->store->stopRequested($state->ref->id));
        self::assertSame(ResearchStatus::Stopped, $loaded?->status);
        self::assertSame(ResearchOutcome::STOPPED_BY_USER, $loaded->outcome?->code);
    }

    public function testAStopBeforeTheStepMakesNoModelCall(): void
    {
        $state = $this->openRun();
        $this->store->requestStop($state->ref->id);
        $units = new ScriptedResearchUnits(new StageResult('gather'));

        $this->worker($units)->handle($state->ref->id, 'brief_plan', 1);

        $loaded = $this->store->load($state->ref->id);
        self::assertInstanceOf(ResearchRunState::class, $loaded);
        self::assertSame(0, $units->calls);
        self::assertSame([], $this->bus->sent);
        self::assertSame(ResearchStatus::Stopped, $loaded->status);
        self::assertInstanceOf(ResearchOutcome::class, $loaded->outcome);
        self::assertSame(ResearchOutcome::STOPPED_BY_USER, $loaded->outcome->code);
        self::assertStringContainsString('Stopped.', $loaded->outcome->text());
    }

    public function testTheStopCheckAsksAgainUntilItIsSet(): void
    {
        $state = $this->openRun();
        $store = $this->store;
        $stop = new ResearchStop(false, static fn (): bool => $store->stopRequested($state->ref->id));

        self::assertFalse($stop->requested());
        $this->store->requestStop($state->ref->id);
        self::assertTrue($stop->requested());

        $this->store->delete($state->ref->id);
        self::assertFalse($this->store->stopRequested($state->ref->id));
    }

    public function testAMissingModelFailsTheRunBeforeACall(): void
    {
        $state = $this->openRun();
        $units = new ScriptedResearchUnits(new StageResult('gather'));
        $worker = new ResearchUnitWorker(
            $this->store,
            $units,
            new UnavailableResearchContext(),
            $this->bus,
            $this->events,
            $this->researchConfig(),
            new SilentResearchReport(),
        );

        $worker->handle($state->ref->id, 'brief_plan', 1);

        $loaded = $this->store->load($state->ref->id);
        self::assertInstanceOf(ResearchRunState::class, $loaded);
        self::assertSame(0, $units->calls);
        self::assertSame([], $this->bus->sent);
        self::assertInstanceOf(ResearchOutcome::class, $loaded->outcome);
        self::assertSame(ResearchOutcome::FAILED_ROLE_UNAVAILABLE, $loaded->outcome->code);
        self::assertSame('reader', $loaded->outcome->params['role'] ?? null);
    }

    public function testAnUnknownStepIsIgnored(): void
    {
        $state = $this->openRun();
        $units = new ScriptedResearchUnits(new StageResult('gather'));

        $this->worker($units)->handle($state->ref->id, 'reflect', 1);

        self::assertSame(0, $units->calls);
        self::assertSame(1, $this->store->load($state->ref->id)?->expectedSeq);
    }

    public function testTheQueueStampWaitsBetweenSteps(): void
    {
        $messages = new RecordingMessageBus();
        (new MessengerResearchUnitBus($messages))->dispatch('rr_0123456789abcdef', 'round', 2, ResearchUnitWorker::GAP_MS);

        self::assertInstanceOf(RunResearchUnit::class, $messages->message);
        self::assertSame('round', $messages->message->unit());
        self::assertSame(2, $messages->message->seq());
        self::assertInstanceOf(DelayStamp::class, $messages->stamp);
        self::assertSame(ResearchUnitWorker::GAP_MS, $messages->stamp->getDelay());
    }

    public function testTheTestStackRunsAUnitImmediately(): void
    {
        $yaml = (string) file_get_contents(dirname(__DIR__, 5).'/config/packages/messenger.yaml');
        $route = strpos($yaml, "'App\\Message\\RunResearchUnit': async_ai_high");
        $sync = strpos($yaml, "'App\\Message\\RunResearchUnit': sync");
        $when = strpos($yaml, 'when@test:');

        self::assertNotFalse($route);
        self::assertNotFalse($sync);
        self::assertNotFalse($when);
        self::assertLessThan($when, $route);
        self::assertGreaterThan($when, $sync);
    }

    public function testARunStopsWhenResearchIsTurnedOff(): void
    {
        $state = $this->openRun();
        $units = new ScriptedResearchUnits(new StageResult('deliver'));
        $worker = new ResearchUnitWorker(
            $this->store,
            $units,
            new FixedResearchContext($this->events, $this->clock->now()),
            $this->bus,
            $this->events,
            $this->researchConfig(false),
            new SilentResearchReport(),
        );

        $worker->handle($state->ref->id, 'brief_plan', 1);

        $loaded = $this->store->load($state->ref->id);
        self::assertInstanceOf(ResearchRunState::class, $loaded);
        self::assertSame(['write'], $units->units);
        self::assertInstanceOf(ResearchOutcome::class, $loaded->outcome);
        self::assertSame(ResearchOutcome::STOPPED_BY_ADMIN, $loaded->outcome->code);
        self::assertSame([], $this->bus->sent);
    }

    public function testAFinishedWebRunPostsItsReport(): void
    {
        $created = $this->clock->now();
        $budget = ResearchBudget::for(ResearchDepth::Quick, ResearchBudgetConfig::defaults(ResearchDepth::Quick), $created);
        $budget->at($created);
        $state = ResearchRunState::open(
            ResearchRunRef::start(7, ResearchDepth::Quick, 'What does one unit cost?', 11, $created),
            $budget,
        );
        $this->store->save($state);
        $reports = new SilentResearchReport();
        $units = new ScriptedResearchUnits(new StageResult('done'), static function (StageContext $ctx): void {
            $ctx->state->ledger->setReport('The price is 12.');
            $ctx->state->finish(ResearchStatus::Complete, new ResearchOutcome(ResearchOutcome::COMPLETE));
        });

        $this->worker($units, $reports)->handle($state->ref->id, 'deliver', 1);

        self::assertSame(1, $reports->calls);
    }

    private function worker(ScriptedResearchUnits $units, ?SilentResearchReport $reports = null): ResearchUnitWorker
    {
        return new ResearchUnitWorker(
            $this->store,
            $units,
            new FixedResearchContext($this->events, $this->clock->now()),
            $this->bus,
            $this->events,
            $this->researchConfig(),
            $reports ?? new SilentResearchReport(),
        );
    }
}
