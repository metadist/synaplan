<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderCatalog;
use App\AI\Provider\ScalewayProvider;
use PHPUnit\Framework\TestCase;

/**
 * The descriptor is what every table reads; this pins the values a typo
 * would silently change, and that discovery finds the class.
 */
final class ScalewayProviderTest extends TestCase
{
    public function testTheDescriptorNamesTheProvider(): void
    {
        $descriptor = ScalewayProvider::descriptor();

        self::assertSame('scaleway', $descriptor->name);
        self::assertSame('SCALEWAY_API_KEY', $descriptor->key?->envVar);
        self::assertContains('api.scaleway.ai', $descriptor->hosts());
        self::assertTrue($descriptor->hasCapability(ProviderCapability::Chat));
        self::assertSame('circle-flags:fr', $descriptor->branding->flag);
        self::assertSame('https://api.scaleway.ai/v1/chat/completions', $descriptor->chatCompletionsUrl());
    }

    public function testDiscoveryFindsTheClass(): void
    {
        self::assertTrue(ProviderCatalog::builtIn()->has('scaleway'));
    }
}

