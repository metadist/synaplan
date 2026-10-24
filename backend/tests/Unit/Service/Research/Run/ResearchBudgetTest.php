<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchDepth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResearchBudgetTest extends TestCase
{
    /**
     * @return iterable<string, array{ResearchDepth, int, int, int, int, float, int}>
     */
    public static function depths(): iterable
    {
        yield 'quick' => [ResearchDepth::Quick, 2, 4, 0, 60, 0.10, 6];
        yield 'standard' => [ResearchDepth::Standard, 10, 15, 3, 360, 1.0, 60];
        yield 'thorough' => [ResearchDepth::Thorough, 30, 40, 8, 1200, 5.0, 200];
    }

    #[DataProvider('depths')]
    public function testDefaultsMatchThePlan(
        ResearchDepth $depth,
        int $searches,
        int $sources,
        int $rounds,
        int $wall,
        float $cost,
        int $calls,
    ): void {
        $budget = ResearchBudget::for($depth, ResearchBudgetConfig::defaults($depth), new \DateTimeImmutable('2026-10-24T10:00:00+00:00'));
        $budget->at(new \DateTimeImmutable('2026-10-24T10:00:00+00:00'));
        $snapshot = $budget->toArray();

        self::assertSame([0, $searches], $snapshot['searches']);
        self::assertSame([0, $sources], $snapshot['sources_read']);
        self::assertSame([0, $rounds], $snapshot['rounds']);
        self::assertSame([0, $wall], $snapshot['seconds']);
        self::assertEqualsWithDelta($cost, $snapshot['cost']['estimate'], 0.000001);
        self::assertTrue($budget->canCall());
        self::assertSame($rounds > 0, $budget->canRound());
        self::assertSame($calls, $this->callCap($budget));
    }

    public function testTheHardStopIsCallsCostOrTheClock(): void
    {
        $start = new \DateTimeImmutable('2026-10-24T10:00:00+00:00');
        $budget = ResearchBudget::for(ResearchDepth::Quick, ResearchBudgetConfig::defaults(ResearchDepth::Quick), $start);
        $budget->at($start);

        $budget->spend('searches', 2);
        self::assertFalse($budget->canSearch());
        $budget->addCost(0.10);
        self::assertFalse($budget->canCall());
        self::assertEqualsWithDelta(0.10, $budget->toArray()['cost']['spent'], 0.000001);

        $fresh = ResearchBudget::for(ResearchDepth::Quick, ResearchBudgetConfig::defaults(ResearchDepth::Quick), $start);
        $fresh->at($start->modify('+60 seconds'));
        self::assertSame(0, $fresh->secondsLeft());
        self::assertFalse($fresh->canCall());
    }

    public function testARoundTripKeepsWhatWasSpent(): void
    {
        $start = new \DateTimeImmutable('2026-10-24T10:00:00+00:00');
        $budget = ResearchBudget::for(ResearchDepth::Standard, ResearchBudgetConfig::defaults(ResearchDepth::Standard), $start);
        $budget->spend('sources', 3);
        $budget->addCost(0.2);
        $budget->addCost(0.1);

        $restored = ResearchBudget::fromExport(ResearchDepth::Standard, $budget->export());
        $restored->at($start);

        self::assertSame(3, $restored->toArray()['sources_read'][0]);
        self::assertEqualsWithDelta(0.3, $restored->toArray()['cost']['spent'], 0.000001);
        self::assertTrue($restored->canCall());
    }

    private function callCap(ResearchBudget $budget): int
    {
        $guard = 0;
        while ($budget->canCall()) {
            $budget->spend('calls');
            ++$guard;
            if ($guard > 500) {
                self::fail('The call cap never closed.');
            }
        }

        return $budget->calls();
    }
}
