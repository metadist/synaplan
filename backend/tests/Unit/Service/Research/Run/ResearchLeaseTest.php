<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Service\Research\Run\Ledger\EvidenceCard;
use App\Service\Research\Run\Ledger\QuoteMatch;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\ResearchInputBudget;
use App\Service\Research\Run\ResearchKey;
use App\Service\Research\Run\ResearchLease;
use App\Service\Research\Run\ResearchReaper;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStatus;
use App\Service\Research\Run\ResearchUnitPlan;
use App\Service\Research\Run\ResearchUnitRunner;
use App\Service\Research\Run\ResearchUnitWorker;
use App\Service\Research\Run\Stage\CheckBatches;
use App\Service\Research\Run\Stage\CheckStage;
use App\Service\Research\Run\Stage\DraftCheck;
use App\Service\Research\Run\Stage\ResearchModelCaller;
use App\Service\Research\Run\Stage\ResearchModelReply;
use App\Service\Research\Run\Stage\StageCall;
use App\Service\Research\Run\Stage\StageContext;
use App\Service\Research\Run\Stage\StageResult;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class ResearchLeaseTest extends ResearchUnitCase
{
    public function testACallLongerThanTheLeaseIsNotPaidTwice(): void
    {
        self::assertSame(240_000, ResearchUnitWorker::LEASE_MS);
        $state = $this->openRun();
        $caller = new WaitingCaller($this->clock, ResearchUnitWorker::LEASE_MS + 60_000, true, [
            new ResearchModelReply(true, true, null, 'kept', 'test:writer', null),
        ], fn (): array => $this->reapNow());

        $this->worker(new ClosureRunner(static function (StageContext $ctx) use ($caller): StageResult {
            $result = (new StageCall($caller))->once($ctx, 'writer', 4, 'system', 'user', null);

            return $result->stop ?? new StageResult('done');
        }))->handle($state->ref->id, ResearchUnitPlan::WRITE, 1);

        $loaded = $this->store->load($state->ref->id);
        self::assertInstanceOf(ResearchRunState::class, $loaded);
        self::assertSame(1, $caller->calls);
        self::assertGreaterThan(ResearchUnitWorker::LEASE_MS, $caller->sleptMs);
        self::assertSame(0, $caller->reap['redispatched'] ?? null);
        self::assertSame(1, $loaded->budget->calls());
        self::assertSame(2, $loaded->expectedSeq);
        self::assertSame([], $this->bus->sent);
    }

    public function testTwoCheckCallsThatTogetherOutlastTheLeaseAreNotPaidTwice(): void
    {
        $state = $this->withTwoStatements();
        $caller = new WaitingCaller($this->clock, 150_000, false, [
            $this->verdict('c1'),
            $this->verdict('c2'),
        ], fn (): array => $this->reapNow());

        $this->worker(new ClosureRunner(static function (StageContext $ctx) use ($caller): StageResult {
            return (new CheckStage(new StageCall($caller), new DraftCheck(), new ResearchInputBudget(), new CheckBatches(), 20))->run($ctx);
        }))->handle($state->ref->id, ResearchUnitPlan::CHECK, 1);

        $loaded = $this->store->load($state->ref->id);
        self::assertInstanceOf(ResearchRunState::class, $loaded);
        self::assertSame(2, $caller->calls);
        self::assertSame(300_000, $caller->sleptMs);
        self::assertGreaterThan(ResearchUnitWorker::LEASE_MS, $caller->sleptMs);
        self::assertSame(0, $caller->reap['redispatched'] ?? null);
        self::assertSame(2, $loaded->budget->calls());
        self::assertSame([[
            'runId' => $state->ref->id,
            'unit' => ResearchUnitPlan::DELIVER,
            'seq' => 2,
            'delayMs' => ResearchUnitWorker::GAP_MS,
        ]], $this->bus->sent);
    }

    public function testARetryThatTogetherOutlastsTheLeaseIsNotPaidTwice(): void
    {
        $state = $this->openRun();
        $schema = new StructuredOutputSchema('check', ['type' => 'object']);
        $caller = new WaitingCaller($this->clock, 150_000, false, [
            new ResearchModelReply(false, true, null, 'not json', 'test:checker', null),
            new ResearchModelReply(true, true, ['kept' => true], '{}', 'test:checker', null),
        ], fn (): array => $this->reapNow());

        $this->worker(new ClosureRunner(static function (StageContext $ctx) use ($caller, $schema): StageResult {
            $result = (new StageCall($caller))->once($ctx, 'checker', 5, 'system', 'user', $schema);

            return $result->stop ?? new StageResult('done');
        }))->handle($state->ref->id, ResearchUnitPlan::CHECK, 1);

        $loaded = $this->store->load($state->ref->id);
        self::assertInstanceOf(ResearchRunState::class, $loaded);
        self::assertSame(2, $caller->calls);
        self::assertSame(300_000, $caller->sleptMs);
        self::assertSame(0, $caller->reap['redispatched'] ?? null);
        self::assertSame(2, $loaded->budget->calls());
        self::assertSame(2, $loaded->expectedSeq);
        self::assertSame([], $this->bus->sent);
    }

    public function testAFailedRenewalDoesNotCallTheModelAgain(): void
    {
        $state = $this->openRun();
        $schema = new StructuredOutputSchema('check', ['type' => 'object']);
        $caller = new WaitingCaller($this->clock, 0, false, [
            new ResearchModelReply(false, true, null, 'not json', 'test:checker', null),
            new ResearchModelReply(true, true, ['kept' => true], '{}', 'test:checker', null),
        ], null, function (int $call) use ($state): void {
            if (1 === $call) {
                $this->keys->set(ResearchKey::claim($state->ref->id, 1), str_repeat('b', 32), 600);
            }
        });

        $this->worker(new ClosureRunner(static function (StageContext $ctx) use ($caller, $schema): StageResult {
            $result = (new StageCall($caller))->once($ctx, 'checker', 5, 'system', 'user', $schema);

            return $result->stop ?? new StageResult('done');
        }))->handle($state->ref->id, ResearchUnitPlan::CHECK, 1);

        $loaded = $this->store->load($state->ref->id);
        self::assertInstanceOf(ResearchRunState::class, $loaded);
        self::assertSame(1, $caller->calls);
        self::assertSame(1, $loaded->expectedSeq);
        self::assertSame(0, $loaded->budget->calls());
        self::assertSame(ResearchStatus::Briefing, $loaded->status);
        self::assertNull($loaded->outcome);
        self::assertSame([], $this->bus->sent);
    }

    public function testAnUnrenewedClaimWithNoCallIsRedispatchedOnce(): void
    {
        $state = $this->openRun();
        $units = new ScriptedResearchUnits(new StageResult('deliver'));
        self::assertNotNull($this->store->claim($state->ref->id, 1, 1000));
        $this->clock->sleep(5);

        $counts = $this->reaper($units)->reap();

        $loaded = $this->store->load($state->ref->id);
        self::assertInstanceOf(ResearchRunState::class, $loaded);
        self::assertSame(1, $counts['redispatched']);
        self::assertSame(0, $counts['interrupted']);
        self::assertSame(0, $units->calls);
        self::assertSame(ResearchStatus::Briefing, $loaded->status);
        self::assertNull($loaded->outcome);
        self::assertSame([[
            'runId' => $state->ref->id,
            'unit' => ResearchUnitPlan::BRIEF_PLAN,
            'seq' => 1,
            'delayMs' => 0,
        ]], $this->bus->sent);
    }

    private function withTwoStatements(): ResearchRunState
    {
        $state = $this->openRun();
        $ledger = $state->ledger;
        $point = $ledger->addPoint('Unit price');
        foreach (['Alpha is one.', 'Beta is two.'] as $quote) {
            $source = $ledger->addSource(Source::web('https://maker.example/'.count($ledger->sources()), 'Page'));
            $ledger->addEvidence(new EvidenceCard(
                'x',
                $source,
                $point,
                $quote,
                ['§1'],
                [new QuoteMatch($quote, '§1', true)],
            ));
        }
        $ledger->setReport("Alpha is one. [e1]\nBeta is two. [e2]");
        $this->store->save($state);

        return $state;
    }

    private function verdict(string $id): ResearchModelReply
    {
        return new ResearchModelReply(true, true, ['verdicts' => [[
            'statement' => $id,
            'verdict' => 'supported',
            'unsupported_part' => null,
            'note' => '',
        ]]], '{}', 'test:checker', null);
    }

    /** @return array{redispatched: int, interrupted: int, continued: int, stopped: int} */
    private function reapNow(): array
    {
        self::assertSame([], $this->bus->sent);

        return $this->reaper(new ScriptedResearchUnits(new StageResult('deliver')))->reap();
    }

    private function worker(ResearchUnitRunner $units): ResearchUnitWorker
    {
        return new ResearchUnitWorker(
            $this->store,
            $units,
            new FixedResearchContext($this->events, $this->clock->now()),
            $this->bus,
            $this->events,
            $this->researchConfig(),
            new SilentResearchReport(),
        );
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
            new SilentResearchReport(),
        );
    }
}

final class ClosureRunner implements ResearchUnitRunner
{
    /** @param \Closure(StageContext): StageResult $run */
    public function __construct(private readonly \Closure $run)
    {
    }

    public function run(StageContext $ctx, string $unit): StageResult
    {
        return ($this->run)($ctx);
    }
}

final class WaitingCaller implements ResearchModelCaller
{
    public int $calls = 0;

    public int $sleptMs = 0;

    /** @var array{redispatched: int, interrupted: int, continued: int, stopped: int}|null */
    public ?array $reap = null;

    /**
     * @param list<ResearchModelReply>                                                                  $script
     * @param \Closure(): array{redispatched: int, interrupted: int, continued: int, stopped: int}|null $onLast
     * @param \Closure(int): void|null                                                                  $afterEach
     */
    public function __construct(
        private readonly MockClock $clock,
        private readonly int $waitMs,
        private readonly bool $beat,
        private array $script,
        private readonly ?\Closure $onLast = null,
        private readonly ?\Closure $afterEach = null,
    ) {
    }

    public function answer(
        int $userId,
        int $modelId,
        string $system,
        string $user,
        ?StructuredOutputSchema $schema,
        ?ResearchLease $lease = null,
    ): ResearchModelReply {
        ++$this->calls;
        $left = $this->waitMs;
        while ($left > 0) {
            $slice = $this->beat ? min(30_000, $left) : $left;
            if ($this->beat && (!$lease instanceof ResearchLease || !$lease->renew())) {
                break;
            }
            $this->clock->sleep($slice / 1000);
            $this->sleptMs += $slice;
            $left -= $slice;
        }
        $reply = array_shift($this->script) ?? ResearchModelReply::failed(true);
        if ([] === $this->script && null !== $this->onLast) {
            $this->reap = ($this->onLast)();
        }
        if (null !== $this->afterEach) {
            ($this->afterEach)($this->calls);
        }

        return $reply;
    }
}
