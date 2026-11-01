<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderCatalog;
use App\AI\Provider\{{class}};
use PHPUnit\Framework\TestCase;

/**
 * The descriptor is what every table reads; this pins the values a typo
 * would silently change, and that discovery finds the class.
 */
final class {{class}}Test extends TestCase
{
    public function testTheDescriptorNamesTheProvider(): void
    {
        $descriptor = {{class}}::descriptor();

        self::assertSame('{{name}}', $descriptor->name);
        self::assertSame('{{envVar}}', $descriptor->key?->envVar);
        self::assertContains('{{host}}', $descriptor->hosts());
        self::assertTrue($descriptor->hasCapability(ProviderCapability::Chat));
        self::assertSame('{{baseUri}}/chat/completions', $descriptor->chatCompletionsUrl());
    }

    public function testDiscoveryFindsTheClass(): void
    {
        self::assertTrue(ProviderCatalog::builtIn()->has('{{name}}'));
    }
}

