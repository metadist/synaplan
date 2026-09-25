<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Provider\OpenAiReasoningEffort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenAiReasoningEffortTest extends TestCase
{
    #[DataProvider('lowestProvider')]
    public function testLowest(string $model, string $expected): void
    {
        $this->assertSame($expected, OpenAiReasoningEffort::lowest($model));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lowestProvider(): array
    {
        return [
            'gpt-6-sol' => ['gpt-6-sol', 'none'],
            'gpt-6-luna' => ['gpt-6-luna', 'none'],
            'gpt-6-astra' => ['gpt-6-astra', 'low'],
            'gpt-6' => ['gpt-6', 'low'],
            'gpt-5.6' => ['gpt-5.6', 'none'],
            'gpt-5.5-pro' => ['gpt-5.5-pro', 'medium'],
            'gpt-5.5' => ['gpt-5.5', 'none'],
            'gpt-5.4' => ['gpt-5.4', 'none'],
            'gpt-5' => ['gpt-5', 'minimal'],
            'gpt-5-mini' => ['gpt-5-mini', 'minimal'],
            'o3' => ['o3', 'low'],
            'o4-mini' => ['o4-mini', 'low'],
        ];
    }

    public function testTiersForGpt6Sol(): void
    {
        $this->assertSame(
            ['none', 'low', 'medium', 'high', 'xhigh', 'max'],
            OpenAiReasoningEffort::tiers('gpt-6-sol'),
        );
    }

    public function testGpt55ProDoesNotResolveToGpt55List(): void
    {
        $this->assertSame(
            ['medium', 'high', 'xhigh'],
            OpenAiReasoningEffort::tiers('gpt-5.5-pro'),
        );
        $this->assertSame(
            ['none', 'low', 'medium', 'high', 'xhigh'],
            OpenAiReasoningEffort::tiers('gpt-5.5'),
        );
        $this->assertNotSame(
            OpenAiReasoningEffort::tiers('gpt-5.5'),
            OpenAiReasoningEffort::tiers('gpt-5.5-pro'),
        );
    }

    #[DataProvider('clampProvider')]
    public function testClamp(string $model, string $requested, string $expected): void
    {
        $this->assertSame($expected, OpenAiReasoningEffort::clamp($model, $requested));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function clampProvider(): array
    {
        return [
            'supported tier is kept' => ['gpt-6-sol', 'high', 'high'],
            'max on a family capped at xhigh' => ['gpt-5.5', 'max', 'xhigh'],
            'xhigh on o-series caps at high' => ['o3', 'xhigh', 'high'],
            'minimal on a none-family rounds down to none' => ['gpt-6-sol', 'minimal', 'none'],
            'below the family floor takes the floor' => ['gpt-5.5-pro', 'low', 'medium'],
            'none on astra takes its floor' => ['gpt-6-astra', 'none', 'low'],
            'unknown name takes the floor' => ['gpt-6-luna', 'turbo', 'none'],
            'case-insensitive' => ['gpt-6-sol', 'HIGH', 'high'],
        ];
    }
}
