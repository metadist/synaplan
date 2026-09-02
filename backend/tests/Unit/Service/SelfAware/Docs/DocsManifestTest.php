<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SelfAware\Docs;

use App\Service\SelfAware\Docs\DocsManifest;
use PHPUnit\Framework\TestCase;

final class DocsManifestTest extends TestCase
{
    public function testParsesFixture(): void
    {
        $json = (string) file_get_contents(dirname(__DIR__, 4).'/Fixtures/selfaware/docs-manifest.json');
        $manifest = DocsManifest::fromJson($json);

        $this->assertSame(DocsManifest::SCHEMA_ID, $manifest->schema);
        $this->assertCount(3, $manifest->pages);
        $this->assertSame('intro', $manifest->pages[0]->slug);
        $this->assertSame('https://docs.synaplan.com/raw/intro.md', $manifest->pages[0]->rawUrl);
    }

    public function testRejectsHttpAndForeignHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocsManifest::fromJson(json_encode([
            'schema' => DocsManifest::SCHEMA_ID,
            'generated_at' => '2026-09-02T09:00:00Z',
            'site_url' => 'https://docs.synaplan.com',
            'version' => '2026.09',
            'pages' => [[
                'slug' => 'intro',
                'title' => 'Intro',
                'section' => 'Start',
                'description' => 'd',
                'url' => 'https://docs.synaplan.com/',
                'raw_url' => 'https://evil.example/raw/intro.md',
                'sha256' => str_repeat('a', 64),
                'bytes' => 1,
                'updated_at' => '2026-09-02T00:00:00Z',
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    public function testRejectsInvalidSlug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocsManifest::fromJson(json_encode([
            'schema' => DocsManifest::SCHEMA_ID,
            'site_url' => 'https://docs.synaplan.com',
            'pages' => [[
                'slug' => '../etc/passwd',
                'title' => 'x',
                'url' => 'https://docs.synaplan.com/x',
                'raw_url' => 'https://docs.synaplan.com/raw/x.md',
                'sha256' => str_repeat('b', 64),
            ]],
        ], JSON_THROW_ON_ERROR));
    }
}
