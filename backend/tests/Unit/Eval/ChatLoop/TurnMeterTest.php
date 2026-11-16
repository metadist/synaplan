<?php

declare(strict_types=1);

namespace App\Tests\Unit\Eval\ChatLoop;

use App\Tests\Eval\ChatLoop\CorpusTurn;
use App\Tests\Eval\ChatLoop\GroupReport;
use App\Tests\Eval\ChatLoop\SpendGuard;
use App\Tests\Eval\ChatLoop\SseFrames;
use App\Tests\Eval\ChatLoop\TurnMeter;
use App\Tests\Eval\ChatLoop\TurnOutcome;
use PHPUnit\Framework\TestCase;

final class TurnMeterTest extends TestCase
{
    public function testAnAnsweredTurnHasFirstTokenTimeCostAndRecall(): void
    {
        $meter = new TurnMeter($this->turn([['78'], ['oak', 'worktop']]), 100.0);
        $meter->event(['status' => 'classifying'], 100.1);
        $meter->event(['status' => 'searching'], 100.2);
        $meter->event(['status' => 'searching'], 100.3);
        $meter->event(['status' => 'data', 'chunk' => ''], 100.4);
        $meter->event(['status' => 'data', 'chunk' => 'The cut-out is 78 cm, '], 100.5);
        $meter->event(['status' => 'data', 'chunk' => 'the WORKTOP is quartz.'], 100.9);
        $meter->event([
            'status' => 'complete',
            'usage' => ['cost' => '0.0120', 'totalTokens' => 900],
            'usageExtra' => [['cost' => '0.0030', 'totalTokens' => 100], ['cost' => null, 'totalTokens' => 40]],
        ], 101.0);

        $outcome = $meter->outcome(101.5);

        self::assertSame(TurnOutcome::STATE_ANSWERED, $outcome->state);
        self::assertEqualsWithDelta(0.5, $outcome->firstTokenSeconds, 0.0001);
        self::assertEqualsWithDelta(1.5, $outcome->totalSeconds, 0.0001);
        self::assertEqualsWithDelta(0.015, $outcome->cost, 0.00001);
        self::assertSame(1040, $outcome->tokens);
        self::assertTrue($outcome->priceKnown);
        self::assertSame(0.5, $outcome->recall);
        self::assertSame(['classifying', 'searching'], $outcome->stages);
    }

    public function testAStreamThatJustClosesHasNoTerminalResult(): void
    {
        $meter = new TurnMeter($this->turn(), 0.0);
        $meter->event(['status' => 'generating'], 0.1);
        $meter->event(['status' => 'data', 'chunk' => 'Half an ans'], 0.2);

        $outcome = $meter->outcome(0.3);

        self::assertFalse($outcome->hasTerminalResult());
        self::assertNull($outcome->recall);
        self::assertNotNull($outcome->reason);
    }

    public function testACompleteEventWithAnErrorReasonIsAFailedTurn(): void
    {
        $meter = new TurnMeter($this->turn(), 0.0);
        $meter->event(['status' => 'complete', 'errorReason' => 'provider_unavailable'], 0.2);

        $outcome = $meter->outcome(0.3);

        self::assertSame(TurnOutcome::STATE_FAILED, $outcome->state);
        self::assertSame('provider_unavailable', $outcome->reason);
        self::assertTrue($outcome->hasTerminalResult());
    }

    public function testAnErrorEventIsAFailedTurn(): void
    {
        $meter = new TurnMeter($this->turn(), 0.0);
        $meter->event(['status' => 'error', 'error' => 'COST_BUDGET_EXCEEDED'], 0.2);

        self::assertSame(TurnOutcome::STATE_FAILED, $meter->outcome(0.3)->state);
    }

    public function testATurnHandedToResearchEndsWhenTheRunEnds(): void
    {
        $meter = new TurnMeter($this->turn([['Halzen']]), 0.0);
        $meter->event(['status' => 'complete', 'researchRunId' => 'rr_1', 'usage' => ['cost' => '0.01', 'totalTokens' => 10]], 1.0);

        self::assertSame('rr_1', $meter->openResearchRun());
        self::assertFalse($meter->outcome(1.0)->hasTerminalResult());
        self::assertFalse($meter->research(['status' => 'gathering']));

        self::assertTrue($meter->research([
            'status' => 'complete',
            'report' => ['markdown' => 'Francis Halzen received the prize.', 'version' => 1],
            'cost' => ['spent' => 0.42],
            'check' => ['statements' => 10, 'supported' => 9, 'removed' => 1],
        ]));
        $outcome = $meter->outcome(300.0);

        self::assertNull($meter->openResearchRun());
        self::assertSame(TurnOutcome::STATE_ANSWERED, $outcome->state);
        self::assertEqualsWithDelta(0.43, $outcome->cost, 0.00001);
        self::assertSame(1.0, $outcome->recall);
        self::assertSame([10, 9], [$outcome->statements, $outcome->supported]);
    }

    public function testAStoppedResearchRunIsAFailedTurnWithItsOutcomeCode(): void
    {
        $meter = new TurnMeter($this->turn(), 0.0);
        $meter->event(['status' => 'complete', 'researchRunId' => 'rr_1'], 1.0);
        $meter->research(['status' => 'failed', 'outcome' => ['code' => 'failed_no_sources', 'text' => 'No sources.']]);

        $outcome = $meter->outcome(2.0);

        self::assertSame(TurnOutcome::STATE_FAILED, $outcome->state);
        self::assertSame('failed_no_sources', $outcome->reason);
    }

    public function testAnUnpricedModelIsNotReportedAsFree(): void
    {
        $meter = new TurnMeter($this->turn(), 0.0);
        $meter->event(['status' => 'complete', 'usage' => ['cost' => '0', 'totalTokens' => 5, 'priceKnown' => false]], 0.1);

        $outcome = $meter->outcome(0.2);

        self::assertFalse($outcome->priceKnown);
        self::assertStringContainsString('(no price)', GroupReport::markdown([$outcome], 'run'));
    }

    public function testAFrameSplitAcrossTwoChunksIsReadOnce(): void
    {
        $frames = new SseFrames();

        self::assertSame([], $frames->feed("event: message\ndata: {\"status\":\"da"));
        self::assertSame([['status' => 'data', 'chunk' => 'Hi']], $frames->feed("ta\",\"chunk\":\"Hi\"}\n\n: keep-alive\ndata: not json\n"));
    }

    public function testTheReportSummarizesAGroup(): void
    {
        $outcomes = [
            $this->outcome('a', TurnOutcome::STATE_ANSWERED, 1.0, 0.10, 0.5),
            $this->outcome('b', TurnOutcome::STATE_ANSWERED, 3.0, 0.30, 1.0),
            $this->outcome('c', TurnOutcome::STATE_FAILED, 9.0, 0.05, null),
            $this->outcome('d', TurnOutcome::STATE_NONE, 9.0, 0.0, null),
        ];

        $row = GroupReport::summarize($outcomes)['simple'];

        self::assertSame([4, 2, 1, 1], [$row['turns'], $row['answered'], $row['failed'], $row['no_terminal']]);
        self::assertEqualsWithDelta(0.45, $row['cost_total'], 0.00001);
        self::assertEqualsWithDelta(0.20, (float) $row['cost_median'], 0.00001);
        self::assertEqualsWithDelta(2.0, (float) $row['total_median'], 0.00001);
        self::assertEqualsWithDelta(2.9, (float) $row['total_p95'], 0.00001);
        self::assertSame(0.75, $row['recall']);
        self::assertNull($row['backed_rate']);
        self::assertNull(GroupReport::percentile([], 50));
    }

    public function testAPaidModelNeedsTheLiveSwitch(): void
    {
        SpendGuard::check(['CHAT' => -1, 'SORT' => -1, 'TEXT2PIC' => 348], false);
        SpendGuard::check(['CHAT' => 379], true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--live');
        SpendGuard::check(['CHAT' => 379, 'SORT' => -1], false);
    }

    /**
     * @param list<list<string>> $expect
     */
    private function turn(array $expect = []): CorpusTurn
    {
        return new CorpusTurn('t-01', 'simple', 'en', 'Question', false, [], $expect);
    }

    private function outcome(string $id, string $state, float $seconds, float $cost, ?float $recall): TurnOutcome
    {
        return new TurnOutcome($id, 'simple', $state, null, TurnOutcome::STATE_ANSWERED === $state ? $seconds / 2 : null, $seconds, $cost, true, 0, $recall, '', [], null);
    }
}
