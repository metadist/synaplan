<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Plugin;

use App\Service\Plugin\InvalidPluginManifestException;
use App\Service\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;

final class PluginManifestAgentsTest extends TestCase
{
    public function testParsesAgentPackGlobs(): void
    {
        $manifest = PluginManifest::fromArray([
            'id' => 'hello_world',
            'name' => 'hello_world',
            'provides' => ['agents' => ['agents/*.json']],
        ]);

        self::assertSame(['agents/*.json'], $manifest->agentPacks);
    }

    public function testManifestWithoutAgentsIsValid(): void
    {
        $manifest = PluginManifest::fromArray(['name' => 'hello_world']);

        self::assertSame([], $manifest->agentPacks);
    }

    public function testRejectsPathTraversal(): void
    {
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('provides.agents[0]');
        PluginManifest::fromArray([
            'name' => 'x',
            'provides' => ['agents' => ['../secret.json']],
        ]);
    }

    public function testRejectsNonJson(): void
    {
        $this->expectException(InvalidPluginManifestException::class);
        PluginManifest::fromArray([
            'name' => 'x',
            'provides' => ['agents' => ['agents/*.txt']],
        ]);
    }
}
