<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Plugin;

use App\Service\Plugin\InvalidPluginManifestException;
use App\Service\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;

final class PluginManifestPlugsTest extends TestCase
{
    public function testParsesValidPlugDeclaration(): void
    {
        $manifest = PluginManifest::fromArray([
            'id' => 'serper_search',
            'namespace' => 'Plugin\\SerperSearch',
            'provides' => [
                'plugs' => [
                    ['port' => 'web_search', 'class' => 'Plugin\\SerperSearch\\Plug\\SerperSearchAdapter', 'key' => 'serper'],
                ],
            ],
        ]);

        self::assertCount(1, $manifest->plugs);
        self::assertSame('web_search', $manifest->plugs[0]['port']);
        self::assertSame('Plugin\\SerperSearch\\Plug\\SerperSearchAdapter', $manifest->plugs[0]['class']);
        self::assertSame('serper', $manifest->plugs[0]['key']);
    }

    public function testManifestWithoutProvidesIsValid(): void
    {
        $manifest = PluginManifest::fromArray(['name' => 'hello_world']);

        self::assertSame([], $manifest->plugs);
    }

    public function testRejectsUnknownPort(): void
    {
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('provides.plugs[0].port');
        PluginManifest::fromArray([
            'id' => 'x',
            'namespace' => 'Plugin\\X',
            'provides' => ['plugs' => [['port' => 'translation', 'class' => 'Plugin\\X\\A', 'key' => 'x']]],
        ]);
    }

    public function testRejectsClassOutsidePluginNamespace(): void
    {
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('provides.plugs[0].class');
        PluginManifest::fromArray([
            'id' => 'x',
            'namespace' => 'Plugin\\X',
            'provides' => ['plugs' => [['port' => 'web_search', 'class' => 'App\\Plug\\WebSearch\\Adapter\\TavilyAdapter', 'key' => 'x']]],
        ]);
    }

    public function testRejectsMalformedKey(): void
    {
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('provides.plugs[0].key');
        PluginManifest::fromArray([
            'id' => 'x',
            'namespace' => 'Plugin\\X',
            'provides' => ['plugs' => [['port' => 'rerank', 'class' => 'Plugin\\X\\R', 'key' => 'Bad Key']]],
        ]);
    }

    public function testDerivesNamespaceFromIdWhenAbsent(): void
    {
        $manifest = PluginManifest::fromArray([
            'id' => 'serper_search',
            'provides' => [
                'plugs' => [
                    ['port' => 'web_search', 'class' => 'Plugin\\Serper_search\\Adapter', 'key' => 'serper'],
                ],
            ],
        ]);

        self::assertCount(1, $manifest->plugs);
    }
}
