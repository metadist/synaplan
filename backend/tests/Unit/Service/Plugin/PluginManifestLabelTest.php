<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Plugin;

use App\Service\Plugin\PluginManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PluginManifestLabelTest extends TestCase
{
    public function testUsesTheManifestDisplayName(): void
    {
        $manifest = PluginManifest::fromArray(['name' => 'synascriber', 'displayName' => ' Meeting notes ']);

        self::assertSame('Meeting notes', $manifest->label());
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('manifestsWithoutDisplayName')]
    public function testFallsBackToThePluginNameInWords(array $data, string $expected): void
    {
        self::assertSame($expected, PluginManifest::fromArray($data)->label());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function manifestsWithoutDisplayName(): iterable
    {
        yield 'underscore' => [['name' => 'hello_world'], 'Hello world'];
        yield 'dash' => [['name' => 'serper-search'], 'Serper search'];
        yield 'blank' => [['name' => 'synaform', 'displayName' => '   '], 'Synaform'];
        yield 'not a string' => [['name' => 'synamail', 'displayName' => ['en' => 'Mail']], 'Synamail'];
    }

    public function testDirectConstructionWithoutDisplayNameStillHasALabel(): void
    {
        $manifest = new PluginManifest('marketeer', '1.0.0', 'Marketing plugin');

        self::assertSame('Marketeer', $manifest->label());
    }
}
