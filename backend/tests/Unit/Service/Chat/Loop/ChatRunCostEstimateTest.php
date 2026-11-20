<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Entity\Model;
use App\Service\Chat\Loop\ChatRunAllowance;
use App\Service\Chat\Loop\ChatRunCostEstimate;
use App\Service\Chat\Loop\ChatRunCostGuard;
use App\Service\Chat\Loop\ChatRunRates;
use App\Service\Chat\Loop\ChatRunState;
use PHPUnit\Framework\TestCase;

/**
 * The cost estimate behind the wrap-up reserve (03 §1.3): from what the
 * run's calls cost, or from the model's price before the first call.
 */
final class ChatRunCostEstimateTest extends TestCase
{
    public function testTheWrapUpCostsTheTranscriptNowPlusAFullAnswer(): void
    {
        $state = $this->state(str_repeat('a', 1_000));
        $state->rates = new ChatRunRates(2.0, 30_000);
        // A long tool result arrived: the transcript is three times as large, and so is its part.
        $state->messages[] = ['role' => 'tool', 'tool_call_id' => 'c1', 'name' => 'web_search', 'content' => str_repeat('b', 2_000)];

        self::assertSame(6_000 + 30_000, ChatRunCostEstimate::wrapUp($state));
    }

    public function testTheWrapUpCostsAtLeastTheDearestCallSoFar(): void
    {
        $state = $this->state('short');
        $state->rates = new ChatRunRates(1.0, 1_000);
        $state->dearestMicro = 40_000;

        self::assertSame(40_000, ChatRunCostEstimate::wrapUp($state));
    }

    public function testWithoutAPriceTheLastCallIsScaledToTheTranscriptNow(): void
    {
        $state = $this->state(str_repeat('a', 1_000));
        $state->lastCallMicro = 10_000;
        $state->lastCallChars = 1_000;
        $state->messages[] = ['role' => 'tool', 'tool_call_id' => 'c1', 'name' => 'web_search', 'content' => str_repeat('b', 2_000)];

        self::assertSame(30_000, ChatRunCostEstimate::wrapUp($state));
    }

    public function testCallsThatCostNothingNeedNoReserve(): void
    {
        self::assertSame(0, ChatRunCostEstimate::wrapUp($this->state('anything')));
    }

    public function testTheRatesComeFromThePriceWithTheMarkup(): void
    {
        $model = (new Model())->setPriceIn(3.0)->setInUnit('per1M')->setPriceOut(15.0)->setOutUnit('per1M');
        $rates = ChatRunRates::of($model, 10.0);

        self::assertNotNull($rates);
        // 40,000 characters are 10,000 tokens in ($0.03) and 2,000 out ($0.03), plus 10 %.
        self::assertSame(66_000, $rates->call(40_000));
        self::assertEquals($rates, ChatRunRates::decode($rates->encode()));
        self::assertNull(ChatRunRates::of(null, 10.0));
        self::assertNull(ChatRunRates::of(new Model(), 10.0));
        self::assertNull(ChatRunRates::decode(['in' => 'x', 'answer' => 1]));
    }

    public function testTheRunMaySpendTheSmallerOfItsShareAndWhatIsLeft(): void
    {
        self::assertSame(200_000, ChatRunCostGuard::room(0, new ChatRunAllowance(true, 200_000, 5_000_000)));
        self::assertSame(950_000, ChatRunCostGuard::room(50_000, new ChatRunAllowance(true, 3_000_000, 1_000_000)));
        self::assertSame(0, ChatRunCostGuard::room(2_000_000, new ChatRunAllowance(true, 3_000_000, 1_000_000)));
        self::assertNull(ChatRunCostGuard::room(0, new ChatRunAllowance(true, null, null)));
    }

    private function state(string $question): ChatRunState
    {
        return new ChatRunState('r1', 7, 11, 5, 'test', 'test-model', 'de', '', [['role' => 'user', 'content' => $question]], 0);
    }
}
