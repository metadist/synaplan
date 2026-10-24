<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\Ledger\EvidenceLedger;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\Loop\LoopAction;
use App\Service\Research\Run\Loop\LoopActions;
use App\Service\Research\Run\Loop\LoopRules;
use App\Service\Research\Run\Loop\LoopStop;
use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchOutcome;
use PHPUnit\Framework\TestCase;

final class ResearchLoopTest extends TestCase
{
    private LoopRules $rules;

    private LoopActions $actions;

    protected function setUp(): void
    {
        $this->rules = new LoopRules();
        $this->actions = new LoopActions();
    }

    public function testStopAndWriteNowComeBeforeThePlan(): void
    {
        $ledger = $this->ledger();
        $budget = $this->budget(ResearchDepth::Standard);

        $stop = $this->judge($ledger, $budget, stoppedByUser: true, writeNow: true, finish: 'covered');
        self::assertSame(LoopStop::HALT, $stop->next);
        self::assertSame('user', $stop->reason);
        self::assertSame(ResearchOutcome::STOPPED_BY_USER, $stop->outcome?->code);

        $write = $this->judge($ledger, $budget, writeNow: true);
        self::assertSame(LoopStop::WRITE, $write->next);
        self::assertSame('write_now', $write->reason);
        self::assertNull($write->outcome);
    }

    public function testFinishAndACoveredPlanGoToTheReport(): void
    {
        $ledger = $this->ledger();
        $budget = $this->budget(ResearchDepth::Standard);

        $finish = $this->judge($ledger, $budget, finish: 'covered', madeProgress: false);
        self::assertSame(LoopStop::WRITE, $finish->next);
        self::assertSame('finish', $finish->reason);

        $ledger->markPoint('p1', 'answered');
        $ledger->markPoint('p2', 'skipped');
        $answered = $this->judge($ledger, $budget);
        self::assertSame('answered', $answered->reason);
        self::assertSame(LoopStop::WRITE, $answered->next);
    }

    public function testEachBudgetEndsTheLoopForItsOwnReason(): void
    {
        $ledger = $this->ledger();

        $calls = $this->budget(ResearchDepth::Standard);
        $calls->spend('calls', 60);
        $this->assertStop($ledger, $calls, 'calls', ResearchOutcome::COMPLETE_PARTIAL);

        $wall = $this->budget(ResearchDepth::Standard);
        $wall->at(new \DateTimeImmutable('2026-10-24T10:06:00+00:00'));
        $this->assertStop($ledger, $wall, 'wall', ResearchOutcome::STOPPED_LIMIT);

        $rounds = $this->budget(ResearchDepth::Quick);
        $this->assertStop($ledger, $rounds, 'rounds', ResearchOutcome::STOPPED_LIMIT);

        $gather = $this->budget(ResearchDepth::Standard);
        $gather->spend('searches', 10);
        $gather->spend('sources', 15);
        $this->assertStop($ledger, $gather, 'gather', ResearchOutcome::STOPPED_LIMIT);

        $searchesOnly = $this->budget(ResearchDepth::Standard);
        $searchesOnly->spend('searches', 10);
        self::assertSame(LoopStop::CONTINUE, $this->judge($ledger, $searchesOnly)->next);
    }

    public function testTheRunCostCapIsALimitNotTheMonthlyBudget(): void
    {
        $ledger = $this->ledger();
        $cost = $this->budget(ResearchDepth::Standard);
        $cost->addCost(1.0);

        $this->assertStop($ledger, $cost, 'cost', ResearchOutcome::STOPPED_LIMIT);
    }

    public function testNoProgressGetsOneHintAndThenStops(): void
    {
        $ledger = $this->ledger();
        $budget = $this->budget(ResearchDepth::Standard);

        $hint = $this->judge($ledger, $budget, madeProgress: false);
        self::assertSame(LoopStop::CONTINUE, $hint->next);
        self::assertTrue($hint->hint);
        self::assertSame('no_progress', $hint->reason);

        $stop = $this->judge($ledger, $budget, madeProgress: false, hintGiven: true);
        self::assertSame(LoopStop::WRITE, $stop->next);
        self::assertSame('no_progress', $stop->reason);
        self::assertSame(ResearchOutcome::COMPLETE_PARTIAL, $stop->outcome?->code);
        self::assertSame(2, $stop->outcome?->params['open'] ?? null);
    }

    public function testFinishDropsActionsAndInvalidOnesAreDropped(): void
    {
        $ledger = $this->ledger();
        $source = $ledger->addSource(Source::web('https://maker.example/price', 'Price list'));
        $ledger->addQuery('p1', 'unit price', 'web', 4);
        $budget = $this->budget(ResearchDepth::Standard);
        $decision = [
            'finish' => 'covered',
            'actions' => [
                ['type' => 'search', 'point' => 'p1', 'query' => 'warranty'],
            ],
        ];

        self::assertSame('covered', $this->actions->finish($decision));
        self::assertSame([], $this->actions->keep($decision, $ledger, $budget, 0));

        $kept = $this->actions->keep([
            'finish' => null,
            'actions' => [
                ['type' => 'browse', 'query' => 'nope'],
                ['type' => 'search', 'point' => 'p9', 'query' => 'missing point'],
                ['type' => 'search', 'point' => 'p1', 'query' => '  Unit   price '],
                ['type' => 'search', 'point' => 'p1', 'query' => 'warranty terms'],
                ['type' => 'search', 'point' => 'p2', 'query' => 'Warranty terms'],
                ['type' => 'read', 'source' => 's99'],
                ['type' => 'read', 'source' => $source],
                ['type' => 'read', 'source' => $source],
                ['type' => 'reread', 'source' => $source, 'question' => 'Net or gross?'],
                ['type' => 'ask_user', 'question' => 'Which market?', 'options' => ['EU']],
                ['type' => 'ask_user', 'question' => 'Which market?', 'options' => ['EU', 'US']],
            ],
        ], $ledger, $budget, 0);

        self::assertSame(
            [
                [LoopAction::SEARCH, 'p1', 'warranty terms'],
                [LoopAction::READ, $source, null],
                [LoopAction::ASK_USER, 'Which market?', null],
            ],
            array_map(static fn (LoopAction $action): array => [$action->type, $action->point ?? $action->source ?? $action->question, $action->query], $kept),
        );
    }

    public function testActionsPastTheCapAreDropped(): void
    {
        $ledger = $this->ledger();
        $budget = $this->budget(ResearchDepth::Standard);
        $actions = [];
        for ($n = 1; $n <= 8; ++$n) {
            $actions[] = ['type' => 'search', 'point' => 'p1', 'query' => 'query '.$n, 'language' => 'en', 'scope' => 'web'];
        }

        $kept = $this->actions->keep(['actions' => $actions], $ledger, $budget, 0);

        self::assertCount(6, $kept);
        self::assertSame('en', $kept[0]->language);
    }

    public function testTheBudgetCountsActionsKeptInTheSameDecision(): void
    {
        $ledger = $this->ledger();
        $first = $ledger->addSource(Source::web('https://a.example/one', 'One'));
        $second = $ledger->addSource(Source::web('https://b.example/two', 'Two'));
        $budget = $this->budget(ResearchDepth::Standard);
        $budget->spend('searches', 9);
        $budget->spend('sources', 14);

        $kept = $this->actions->keep(['actions' => [
            ['type' => 'search', 'point' => 'p1', 'query' => 'first'],
            ['type' => 'search', 'point' => 'p1', 'query' => 'second'],
            ['type' => 'read', 'source' => $first],
            ['type' => 'read', 'source' => $second],
            ['type' => 'ask_user', 'question' => 'Which market?', 'options' => ['EU', 'US']],
        ]], $ledger, $budget, 1);

        self::assertSame(
            [[LoopAction::SEARCH, 'first'], [LoopAction::READ, $first]],
            array_map(static fn (LoopAction $action): array => [$action->type, $action->query ?? $action->source], $kept),
        );
    }

    public function testModelTextIsOneCleanLineAndABadLanguageDoesNotCostTheSearch(): void
    {
        $ledger = $this->ledger();
        $budget = $this->budget(ResearchDepth::Standard);

        $kept = $this->actions->keep(['actions' => [
            ['type' => 'search', 'point' => 'p1', 'query' => "price\nlist", 'language' => 'de-DE'],
            ['type' => 'search', 'point' => 'p2', 'query' => 'warranty', 'language' => 'DE'],
            ['type' => 'ask_user', 'question' => str_repeat('a', 400), 'options' => ["EU\u{0007}", 'US', 'US']],
        ]], $ledger, $budget, 0);

        self::assertCount(3, $kept);
        self::assertSame('price list', $kept[0]->query);
        self::assertNull($kept[0]->language);
        self::assertSame('de', $kept[1]->language);
        self::assertSame(300, mb_strlen((string) $kept[2]->question));
        self::assertSame(['EU', 'US'], $kept[2]->options);
    }

    private function judge(
        EvidenceLedger $ledger,
        ResearchBudget $budget,
        bool $stoppedByUser = false,
        bool $writeNow = false,
        ?string $finish = null,
        bool $madeProgress = true,
        bool $hintGiven = false,
    ): LoopStop {
        return $this->rules->judge(
            $ledger,
            $budget,
            stoppedByUser: $stoppedByUser,
            writeNow: $writeNow,
            finish: $finish,
            madeProgress: $madeProgress,
            hintGiven: $hintGiven,
        );
    }

    private function assertStop(EvidenceLedger $ledger, ResearchBudget $budget, string $reason, string $code): void
    {
        $stop = $this->judge($ledger, $budget);

        self::assertSame(LoopStop::WRITE, $stop->next);
        self::assertSame($reason, $stop->reason);
        self::assertSame($code, $stop->outcome?->code);
    }

    private function ledger(): EvidenceLedger
    {
        $ledger = new EvidenceLedger();
        $ledger->addPoint('Price');
        $ledger->addPoint('Warranty');

        return $ledger;
    }

    private function budget(ResearchDepth $depth): ResearchBudget
    {
        $start = new \DateTimeImmutable('2026-10-24T10:00:00+00:00');
        $budget = ResearchBudget::for($depth, ResearchBudgetConfig::defaults($depth), $start);
        $budget->at($start);

        return $budget;
    }
}
