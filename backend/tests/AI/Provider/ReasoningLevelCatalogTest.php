<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Provider\ReasoningLevelCatalog;
use PHPUnit\Framework\TestCase;

final class ReasoningLevelCatalogTest extends TestCase
{
    public function testGpt54ExposesSkipAndExtraHigh(): void
    {
        self::assertSame(
            ['none', 'low', 'medium', 'high', 'xhigh'],
            ReasoningLevelCatalog::levels('OpenAI', 'gpt-5.4', ['reasoning']),
        );
    }

    public function testGpt55ProHasNoSkipTier(): void
    {
        self::assertSame(
            ['medium', 'high', 'xhigh'],
            ReasoningLevelCatalog::levels('OpenAI', 'gpt-5.5-pro', ['reasoning']),
        );
    }

    public function testOpenAiWithoutReasoningFeatureHasNoLevels(): void
    {
        self::assertNull(ReasoningLevelCatalog::levels('OpenAI', 'gpt-5.4', ['vision']));
    }

    public function testGrok47And46(): void
    {
        self::assertSame(
            ['low', 'medium', 'high', 'xhigh'],
            ReasoningLevelCatalog::levels('xAI', 'grok-4.7', ['reasoning']),
        );
        self::assertNull(ReasoningLevelCatalog::levels('xAI', 'grok-4.6', ['reasoning']));
    }

    public function testGoogleAndAnthropicFamilies(): void
    {
        self::assertSame(
            ['low', 'medium', 'high'],
            ReasoningLevelCatalog::levels('Google', 'gemini-2.5-pro', ['reasoning']),
        );
        self::assertSame(
            ['low', 'medium', 'high'],
            ReasoningLevelCatalog::levels('Anthropic', 'claude-opus-5-5', ['reasoning']),
        );
        self::assertNull(ReasoningLevelCatalog::levels('Anthropic', 'claude-haiku-4-5', ['reasoning']));
    }

    public function testMetaHuggingFaceAndOllama(): void
    {
        self::assertSame(
            ['minimal', 'low', 'medium', 'high', 'xhigh', 'max'],
            ReasoningLevelCatalog::levels('Meta', 'muse-spark-1.3', ['reasoning']),
        );
        self::assertSame(
            ['low', 'high', 'max'],
            ReasoningLevelCatalog::levels('Hugging Face', 'moonshotai/kimi-k3', ['reasoning']),
        );
        self::assertNull(ReasoningLevelCatalog::levels('Ollama', 'deepseek-r1', ['reasoning']));
    }

    public function testClampDropsMaxOntoAFamilyThatTopsOutAtHigh(): void
    {
        $levels = ReasoningLevelCatalog::levels('Anthropic', 'claude-sonnet-4-6', ['reasoning']);
        self::assertNotNull($levels);
        self::assertSame('high', ReasoningLevelCatalog::clamp($levels, 'max'));
        self::assertSame('low', ReasoningLevelCatalog::clamp($levels, 'not-a-level'));
    }

    public function testDefaultReadsNestedCatalogValue(): void
    {
        $levels = ['none', 'low', 'medium', 'high', 'xhigh'];

        self::assertSame('medium', ReasoningLevelCatalog::defaultLevel($levels, [
            'meta' => ['reasoning_effort_default' => 'medium'],
        ]));
        self::assertSame('high', ReasoningLevelCatalog::defaultLevel($levels, [
            'reasoning_effort_default' => 'high',
        ]));
        self::assertSame('none', ReasoningLevelCatalog::defaultLevel($levels, [
            'reasoning_effort_default' => 'max-not-in-list',
        ]));
    }

    public function testApplyClampsAndLeavesAnUnchosenLevelAlone(): void
    {
        $chosen = ReasoningLevelCatalog::apply(
            ['reasoning' => false, 'reasoning_effort' => 'max'],
            'xAI',
            'grok-4.7',
            ['reasoning'],
        );
        self::assertSame('xhigh', $chosen['reasoning_effort']);
        self::assertTrue($chosen['reasoning']);

        $untouched = ReasoningLevelCatalog::apply(
            ['reasoning' => true],
            'OpenAI',
            'gpt-5.4',
            ['reasoning'],
        );
        self::assertTrue($untouched['reasoning']);
        self::assertArrayNotHasKey('reasoning_effort', $untouched);

        $fromCatalog = ReasoningLevelCatalog::apply(
            ['reasoning' => true],
            'xAI',
            'grok-4.7',
            ['reasoning'],
            ['reasoning_effort_default' => 'high'],
        );
        self::assertSame('high', $fromCatalog['reasoning_effort']);
        self::assertTrue($fromCatalog['reasoning']);

        $leftOff = ReasoningLevelCatalog::apply(
            ['reasoning' => false],
            'xAI',
            'grok-4.7',
            ['reasoning'],
            ['reasoning_effort_default' => 'high'],
        );
        self::assertFalse($leftOff['reasoning']);
        self::assertArrayNotHasKey('reasoning_effort', $leftOff);

        $fixedDepth = ReasoningLevelCatalog::apply(
            ['reasoning' => true, 'reasoning_effort' => 'high'],
            'xAI',
            'grok-4.6',
            ['reasoning'],
        );
        self::assertTrue($fixedDepth['reasoning']);
        self::assertArrayNotHasKey('reasoning_effort', $fixedDepth);
    }

    public function testNoneAndMinimalTurnReasoningOffButLowDoesNot(): void
    {
        $off = ReasoningLevelCatalog::apply(
            ['reasoning' => true, 'reasoning_effort' => 'none'],
            'OpenAI',
            'gpt-5.4',
            ['reasoning'],
        );
        self::assertSame('none', $off['reasoning_effort']);
        self::assertFalse($off['reasoning']);

        $low = ReasoningLevelCatalog::apply(
            ['reasoning' => false, 'reasoning_effort' => 'low'],
            'Anthropic',
            'claude-opus-4-6',
            ['reasoning'],
        );
        self::assertSame('low', $low['reasoning_effort']);
        self::assertTrue($low['reasoning']);
    }
}
