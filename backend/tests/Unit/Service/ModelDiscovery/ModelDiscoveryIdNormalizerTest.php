<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ModelDiscovery;

use App\Service\ModelDiscovery\ModelDiscoveryIdNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelDiscoveryIdNormalizerTest extends TestCase
{
    #[DataProvider('provideIds')]
    public function testNormalize(string $input, string $expected): void
    {
        $this->assertSame($expected, ModelDiscoveryIdNormalizer::normalize($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideIds(): iterable
    {
        yield 'lowercase' => ['GPT-5.4', 'gpt-5.4'];
        yield 'models prefix' => ['models/gemini-2.5-pro', 'gemini-2.5-pro'];
        yield 'models prefix mixed case' => ['Models/Gemini-2.5-Pro', 'gemini-2.5-pro'];
        yield 'trailing YYYYMMDD' => ['claude-sonnet-5-20250514', 'claude-sonnet-5'];
        yield 'trailing YYYY-MM-DD' => ['gpt-4o-2024-08-06', 'gpt-4o'];
        yield 'both prefix and date' => ['models/gemini-2.5-flash-20250520', 'gemini-2.5-flash'];
        yield 'whitespace' => ['  gpt-5.4  ', 'gpt-5.4'];
        yield 'no change needed' => ['mistral-large-latest', 'mistral-large-latest'];
        yield 'date in middle kept' => ['model-20250514-final', 'model-20250514-final'];
    }
}
