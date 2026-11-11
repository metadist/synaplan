<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\Google;

use App\AI\Provider\Google\Gemini4Models;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Gemini4ModelsTest extends TestCase
{
    #[DataProvider('publicIds')]
    public function testPublicGemini4IdsUseThinkingLevel(string $model): void
    {
        self::assertTrue(Gemini4Models::usesThinkingLevel($model));
    }

    /**
     * @return list<array{string}>
     */
    public static function publicIds(): array
    {
        return [
            ['gemini-4-argon'],
            ['gemini-4-carbon'],
            ['models/gemini-4-carbon'],
            ['google:gemini-4-carbon:chat'],
            ['google:gemini-4-argon:pic2text'],
            ['GEMINI-4-CARBON'],
        ];
    }

    #[DataProvider('otherIds')]
    public function testOtherIdsKeepTheOlderRequestShape(string $model): void
    {
        self::assertFalse(Gemini4Models::usesThinkingLevel($model));
    }

    /**
     * @return list<array{string}>
     */
    public static function otherIds(): array
    {
        return [
            ['gemini-3.8-flash'],
            ['gemini-4-carbon-cyber'],
            ['gemini-4-carbon-cyber-permissive'],
            ['gemini-4-argon-cyber-permissive'],
            ['gemini-4-carbon-preview'],
            ['gemini-4-carbonx'],
            ['custom-gemini-4-carbon'],
        ];
    }

    public function testThinkingLevelClampsSemanticEffortsAndRejectsMinimal(): void
    {
        self::assertNull(Gemini4Models::thinkingLevel(null));
        self::assertNull(Gemini4Models::thinkingLevel('minimal'));
        self::assertSame('low', Gemini4Models::thinkingLevel('off'));
        self::assertSame('low', Gemini4Models::thinkingLevel('none'));
        self::assertSame('low', Gemini4Models::thinkingLevel('low'));
        self::assertSame('medium', Gemini4Models::thinkingLevel('medium'));
        self::assertSame('medium', Gemini4Models::thinkingLevel('dynamic'));
        self::assertSame('high', Gemini4Models::thinkingLevel('high'));
        self::assertSame('high', Gemini4Models::thinkingLevel('xhigh'));
        self::assertSame('high', Gemini4Models::thinkingLevel('MAX'));
    }

    public function testThinkingLevelFromMessagesRequestMapsDisabledEffortAndBudget(): void
    {
        self::assertSame('low', Gemini4Models::thinkingLevelFromMessagesRequest([
            'thinking' => ['type' => 'disabled'],
            'output_config' => ['effort' => 'high'],
        ]));
        self::assertSame('high', Gemini4Models::thinkingLevelFromMessagesRequest([
            'output_config' => ['effort' => 'xhigh'],
        ]));
        self::assertSame('low', Gemini4Models::thinkingLevelFromMessagesRequest([
            'output_config' => ['effort' => 'minimal'],
        ]));
        self::assertSame('low', Gemini4Models::thinkingLevelFromMessagesRequest([
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 1024],
        ]));
        self::assertSame('medium', Gemini4Models::thinkingLevelFromMessagesRequest([
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 4096],
        ]));
        self::assertSame('high', Gemini4Models::thinkingLevelFromMessagesRequest([
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 16000],
        ]));
        self::assertNull(Gemini4Models::thinkingLevelFromMessagesRequest([
            'thinking' => ['type' => 'adaptive'],
        ]));
    }
}
