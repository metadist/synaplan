<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Catalog;

use App\AI\Catalog\ReasoningLadder;
use App\AI\Catalog\ReasoningLevelRule;
use App\AI\Catalog\ReasoningMatch;
use PHPUnit\Framework\TestCase;

final class ReasoningLadderTest extends TestCase
{
    public function testTheFirstMatchingPrefixWinsAndUnknownModelsHaveNoLevels(): void
    {
        $ladder = ReasoningLadder::prefixes([
            'gpt-6-sol' => ['none', 'low', 'high'],
            'gpt-6' => ['low', 'high'],
        ]);

        self::assertSame(['none', 'low', 'high'], $ladder->levelsFor('GPT-6-SOL-2026'));
        self::assertSame(['low', 'high'], $ladder->levelsFor('gpt-6-astra'));
        self::assertNull($ladder->levelsFor('gpt-4o'));
    }

    public function testContainsAndAnyRules(): void
    {
        $ladder = (new ReasoningLadder([
            new ReasoningLevelRule(ReasoningMatch::Contains, ['kimi-k3'], ['low', 'high', 'max']),
        ]))->with(new ReasoningLevelRule(ReasoningMatch::Any, [], ['low', 'medium', 'high']));

        self::assertSame(['low', 'high', 'max'], $ladder->levelsFor('moonshotai/Kimi-K3-thinking'));
        self::assertSame(['low', 'medium', 'high'], $ladder->levelsFor('anything-else'));
        self::assertSame(['low', 'medium', 'high'], ReasoningLadder::always(['low', 'medium', 'high'])->levelsFor('x'));
    }
}
