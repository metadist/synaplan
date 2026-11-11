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
            ['gemini-4-argon-cyber-permissive'],
            ['gemini-4-carbon-preview'],
            ['gemini-4-carbonx'],
        ];
    }
}
