<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderCatalog;
use App\AI\Provider\LlmtrProvider;
use App\Model\ModelCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The descriptor is what every table reads; this pins the values a typo
 * would silently change, and that discovery finds the class.
 */
final class LlmtrProviderTest extends TestCase
{
    public function testTheDescriptorNamesTheProvider(): void
    {
        $descriptor = LlmtrProvider::descriptor();

        self::assertSame('llmtr', $descriptor->name);
        self::assertSame('LLMTR_API_KEY', $descriptor->key?->envVar);
        self::assertContains('llmtr.com', $descriptor->hosts());
        self::assertTrue($descriptor->hasCapability(ProviderCapability::Chat));
        self::assertTrue($descriptor->hasCapability(ProviderCapability::Vision));
        self::assertSame('circle-flags:tr', $descriptor->branding->flag);
        self::assertSame('https://llmtr.com/v1/chat/completions', $descriptor->chatCompletionsUrl());
        self::assertSame(14, $descriptor->defaults?->preferenceRank);
    }

    public function testCatalogRowsAreHostedInTurkey(): void
    {
        foreach ([
            'llmtr:llmtr/gemma-4:chat',
            'llmtr:llmtr/gemma-4:pic2text',
            'llmtr:llmtr/qwen3-8-27b:chat',
            'llmtr:llmtr/trendyol-asure-12b:chat',
            'llmtr:llmtr/muse-glimmer-30b-tr:chat',
        ] as $key) {
            $rows = ModelCatalog::find($key);
            self::assertCount(1, $rows, $key);
            self::assertSame('TR', $rows[0]['json']['meta']['jurisdiction'] ?? null, $key);
        }

        $asure = ModelCatalog::find('llmtr:llmtr/trendyol-asure-12b:chat');
        self::assertNotContains('tool_use', $asure[0]['json']['features'] ?? [], 'Trendyol Asure does not call tools');
    }

    public function testDiscoveryFindsTheClass(): void
    {
        self::assertTrue(ProviderCatalog::builtIn()->has('llmtr'));
    }
}
