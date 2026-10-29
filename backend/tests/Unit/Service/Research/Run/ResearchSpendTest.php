<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\ResearchSpend;
use App\Service\Research\Run\Stage\ResearchCallUsage;
use PHPUnit\Framework\TestCase;

final class ResearchSpendTest extends TestCase
{
    public function testCallsOfTheSameRoleAndModelAddUpIntoOneUsageLine(): void
    {
        $spend = new ResearchSpend();
        $spend->add('reading', 'groq:llama', new ResearchCallUsage(600, 100, 700, '0.001500'));
        $spend->add('reader', 'groq:llama', new ResearchCallUsage(400, 100, 500, '0.001000'));
        $spend->add('lead', 'anthropic:claude', new ResearchCallUsage(3000, 200, 3200, '0.020000', false));

        self::assertSame(4400, $spend->totalTokens());
        self::assertSame('0.022500', $spend->cost());
        self::assertSame([
            ['promptTokens' => 1000, 'completionTokens' => 200, 'totalTokens' => 1200, 'cost' => '0.002500', 'modelKey' => 'groq:llama', 'kind' => 'READ', 'priceKnown' => true],
            ['promptTokens' => 3000, 'completionTokens' => 200, 'totalTokens' => 3200, 'cost' => '0.020000', 'modelKey' => 'anthropic:claude', 'kind' => 'LEAD', 'priceKnown' => false],
        ], $spend->toMessageUsage());
    }

    public function testTheSpendSurvivesTheRoundTripThroughTheStoredState(): void
    {
        $spend = new ResearchSpend();
        $spend->add('writing', 'openai:gpt', new ResearchCallUsage(2000, 800, 2800, '0.030000'));

        $restored = ResearchSpend::fromArray(json_decode((string) json_encode($spend->toArray()), true));

        self::assertSame($spend->toMessageUsage(), $restored->toMessageUsage());
        self::assertTrue(ResearchSpend::fromArray(null)->isEmpty());
        self::assertTrue(ResearchSpend::fromArray([['kind' => 'READ']])->isEmpty());
    }

    public function testTheChargedCostWinsOverTheCatalogPrice(): void
    {
        $charged = ResearchCallUsage::fromResponse([
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'facade_charged_cost' => '0.000400',
            'facade_price_known' => true,
        ], 0.0009);
        $raw = ResearchCallUsage::fromResponse(['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]], 0.0009);

        self::assertSame('0.000400', $charged->chargedCost);
        self::assertSame(15, $charged->totalTokens);
        self::assertSame('0.000900', $raw->chargedCost);
        self::assertTrue($raw->priceKnown);
    }
}
