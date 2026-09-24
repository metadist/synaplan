<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ModelDiscovery;

use App\Service\ModelDiscovery\ModelDiscoveryIdNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelDiscoveryIdNormalizerTest extends TestCase
{
    #[DataProvider('provideNormalize')]
    public function testNormalize(string $input, string $expected): void
    {
        $this->assertSame($expected, ModelDiscoveryIdNormalizer::normalize($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNormalize(): iterable
    {
        yield 'lowercase' => ['GPT-5.4', 'gpt-5.4'];
        yield 'models prefix' => ['models/gemini-2.5-pro', 'gemini-2.5-pro'];
        yield 'models prefix mixed case' => ['Models/Gemini-2.5-Pro', 'gemini-2.5-pro'];
        yield 'date kept' => ['claude-sonnet-5-20250514', 'claude-sonnet-5-20250514'];
        yield 'hyphenated date kept' => ['gpt-4o-2024-08-06', 'gpt-4o-2024-08-06'];
        yield 'prefix kept with date' => ['models/gemini-2.5-flash-20250520', 'gemini-2.5-flash-20250520'];
        yield 'whitespace' => ['  gpt-5.4  ', 'gpt-5.4'];
        yield 'no change needed' => ['mistral-large-latest', 'mistral-large-latest'];
    }

    #[DataProvider('provideUndated')]
    public function testUndated(string $input, string $expected): void
    {
        $this->assertSame($expected, ModelDiscoveryIdNormalizer::undated($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUndated(): iterable
    {
        yield 'YYYYMMDD' => ['claude-sonnet-5-20250514', 'claude-sonnet-5'];
        yield 'YYYY-MM-DD' => ['gpt-4o-2024-08-06', 'gpt-4o'];
        yield 'no date' => ['gpt-4o', 'gpt-4o'];
        yield 'date in middle kept' => ['model-20250514-final', 'model-20250514-final'];
    }

    public function testIsKnownExactMatch(): void
    {
        $known = ['gpt-4o' => true];
        $this->assertTrue(ModelDiscoveryIdNormalizer::isKnown('GPT-4O', $known));
        $this->assertFalse(ModelDiscoveryIdNormalizer::isKnown('gpt-5', $known));
    }

    public function testIsKnownUndatedCatalogCoversDatedListing(): void
    {
        $known = ['gpt-4o' => true];
        $this->assertTrue(ModelDiscoveryIdNormalizer::isKnown('gpt-4o-2024-11-20', $known));
    }

    public function testIsKnownUndatedListingCoversDatePinnedCatalog(): void
    {
        $known = ['claude-haiku-4-5-20251001' => true];
        $this->assertTrue(ModelDiscoveryIdNormalizer::isKnown('claude-haiku-4-5', $known));
    }

    public function testIsKnownNewSnapshotOfDatePinnedCatalogIsPending(): void
    {
        $known = ['claude-haiku-4-5-20251001' => true];
        $this->assertFalse(ModelDiscoveryIdNormalizer::isKnown('claude-haiku-4-5-20260301', $known));
    }
}
